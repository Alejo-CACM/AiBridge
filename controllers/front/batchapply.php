<?php

require_once dirname(__FILE__) . '/../../classes/AiBridgeApprovalRequest.php';
require_once dirname(__FILE__) . '/../../classes/AiBridgeApprovalExecutor.php';

class AibridgeBatchapplyModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                $this->sendJson(400, $this->error('invalid_request', 'Invalid batch request.'));
            }

            if (!is_object($this->module) || !$this->module->isValidApiToken()) {
                $this->sendJson(401, $this->error('unauthorized', 'Invalid or missing API token.'));
            }

            try {
                $body = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $exception) {
                $this->sendJson(400, $this->error('invalid_json', 'Invalid JSON payload.'));
            }

            if (!is_array($body) || !isset($body['approval_uuids']) || !is_array($body['approval_uuids'])
                || count($body['approval_uuids']) > 50 || count($body['approval_uuids']) !== count(array_unique($body['approval_uuids']))) {
                $this->sendJson(400, $this->error('invalid_request', 'Invalid batch request.'));
            }

            $items = array();
            foreach ($body['approval_uuids'] as $uuid) {
                $items[] = $this->applyOne($uuid);
            }

            $this->sendJson(200, array('success' => true, 'data' => array('items' => $items)));
        } catch (\Throwable $exception) {
            $this->sendJson(500, $this->error('batch_apply_internal_error', 'Batch apply failed internally.'));
        }
    }

    private function applyOne($uuid)
    {
        try {
            $requestId = AiBridgeApprovalRequest::findIdByUuid($uuid);
            if ($requestId <= 0) {
                return array('approval_uuid' => $uuid, 'product_id' => 0, 'result' => 'failed', 'status' => 'error', 'error' => 'Approval request not found.');
            }

            $request = new AiBridgeApprovalRequest($requestId);
            if (!Validate::isLoadedObject($request) || (int) $request->product_id <= 0) {
                return array('approval_uuid' => $uuid, 'product_id' => 0, 'result' => 'failed', 'status' => 'error', 'error' => 'Approval request not found.');
            }

            if ($request->status !== AiBridgeApprovalRequest::STATUS_APPROVED) {
                return array('approval_uuid' => $uuid, 'product_id' => (int) $request->product_id, 'result' => 'failed', 'status' => $request->status, 'error' => 'Request is not executable.');
            }

            $success = (new AiBridgeApprovalExecutor())->execute($request, $this->module->getAuthenticatedEmployeeId());
            return array('approval_uuid' => $uuid, 'product_id' => (int) $request->product_id, 'result' => $success ? 'success' : 'failed', 'status' => $request->status, 'error' => $success ? null : 'Execution failed.');
        } catch (\Throwable $exception) {
            return array('approval_uuid' => $uuid, 'product_id' => 0, 'result' => 'failed', 'status' => 'error', 'error' => 'Execution failed.');
        }
    }

    private function error($code, $message)
    {
        return array('success' => false, 'error' => array('code' => $code, 'message' => $message));
    }

    private function sendJson($statusCode, array $payload)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}