<?php

require_once dirname(__FILE__) . '/../../classes/AiBridgeProductPreview.php';
require_once dirname(__FILE__) . '/../../classes/AiBridgeApprovalRequest.php';
require_once dirname(__FILE__) . '/../../classes/AiBridgeApprovalExecutor.php';

class AibridgeBatchpreviewModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->out(400, array('success' => false, 'error' => array(
                'code' => 'invalid_request',
                'message' => 'Invalid batch request.',
            )));
        }

        if (!$this->module->isValidApiToken()) {
            $this->out(401, array('success' => false, 'error' => array(
                'code' => 'unauthorized',
                'message' => 'Invalid or missing API token.',
            )));
        }

        try {
            $body = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            $this->out(400, array('success' => false, 'error' => array(
                'code' => 'invalid_json',
                'message' => 'Invalid JSON payload.',
            )));
        }

        if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])
            || count($body['items']) > 50) {
            $this->out(400, array('success' => false, 'error' => array(
                'code' => 'invalid_request',
                'message' => 'Invalid batch request.',
            )));
        }

        $employeeId = $this->module->getAuthenticatedEmployeeId();
        $results = array();
        foreach ($body['items'] as $item) {
            $id = is_array($item) ? (int) ($item['product_id'] ?? 0) : 0;
            $preview = (is_array($item) && isset($item['changes']) && is_array($item['changes']))
                ? (new AiBridgeProductPreview())->build(
                    $id,
                    $item['changes'],
                    (int) ($item['language_id'] ?? $this->context->language->id),
                    (int) ($item['shop_id'] ?? $this->context->shop->id)
                )
                : null;

            if (!$preview || !$preview['valid']) {
                $results[] = array(
                    'product_id' => $id,
                    'valid' => false,
                    'errors' => $preview ? $preview['changes'] : array('Invalid item.'),
                );
                continue;
            }

            if (empty($preview['changes'])) {
                $results[] = array(
                    'product_id' => $id,
                    'valid' => true,
                    'status' => 'no_changes',
                    'diff' => array(),
                    'errors' => array(),
                );
                continue;
            }

            $request = AiBridgeApprovalRequest::createPending($preview, $preview['canonical_payload'], $employeeId);
            if (!$request->add()) {
                $results[] = array(
                    'product_id' => $id,
                    'valid' => false,
                    'errors' => array('Request could not be created.'),
                );
                continue;
            }

            $status = AiBridgeApprovalRequest::STATUS_PENDING;
            $applied = null;
            if ($this->module->isDirectApplyTestMode()) {
                if (!$request->approveForDirectApiExecution()) {
                    $results[] = array(
                        'product_id' => $id,
                        'valid' => false,
                        'errors' => array('Direct execution preparation failed.'),
                    );
                    continue;
                }

                $applied = (new AiBridgeApprovalExecutor())->execute($request, $employeeId);
                $status = (string) $request->status;
            }

            $results[] = array(
                'product_id' => $id,
                'valid' => true,
                'approval_uuid' => $request->uuid,
                'status' => $status,
                'applied' => $applied,
                'diff' => $preview['changes'],
                'errors' => array(),
            );
        }

        $this->out(200, array('success' => true, 'data' => array('items' => $results)));
    }

    private function out($statusCode, array $payload)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}
