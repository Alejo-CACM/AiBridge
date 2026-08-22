<?php

require_once dirname(__FILE__) . '/../../classes/AiBridgeCategoryPreview.php';
require_once dirname(__FILE__) . '/../../classes/AiBridgeApprovalRequest.php';
require_once dirname(__FILE__) . '/../../classes/AiBridgeApprovalExecutor.php';

class AibridgeCategorypreviewupdateModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->sendJson(405, array(
                'success' => false,
                'error' => array('code' => 'method_not_allowed', 'message' => 'Only POST requests are allowed.'),
            ));
        }

        try {
            $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            $this->sendJson(400, array(
                'success' => false,
                'error' => array('code' => 'invalid_json', 'message' => 'Invalid JSON payload.'),
            ));
        }

        if (!is_array($payload)) {
            $this->sendJson(400, array(
                'success' => false,
                'error' => array('code' => 'invalid_json', 'message' => 'Invalid JSON payload.'),
            ));
        }

        try {
            if (!is_object($this->module) || !method_exists($this->module, 'isValidApiToken')) {
                throw new RuntimeException('Invalid module context.');
            }

            if (!$this->module->isValidApiToken()) {
                $this->sendJson(401, array(
                    'success' => false,
                    'error' => array('code' => 'unauthorized', 'message' => 'Invalid or missing API token.'),
                ));
            }

            $categoryId = (int) Tools::getValue('id', 0);
            $languageId = (int) $this->context->language->id;
            $shopId = (int) $this->context->shop->id;
            $preview = (new AiBridgeCategoryPreview())->build($categoryId, $payload, $languageId, $shopId);

            if ($preview === null) {
                $this->sendJson(404, array(
                    'success' => false,
                    'error' => array('code' => 'category_not_found', 'message' => 'Category not found.'),
                ));
            }

            $approvalUuid = null;
            $status = null;
            $applied = null;
            if ($preview['valid'] && !empty($preview['changes'])) {
                $employeeId = $this->module->getAuthenticatedEmployeeId();
                $request = AiBridgeApprovalRequest::createPendingCategoryUpdate(
                    $preview,
                    $preview['canonical_payload'],
                    $employeeId
                );
                if (!$request->add()) {
                    throw new RuntimeException('Approval request creation failed.');
                }
                $approvalUuid = $request->uuid;
                $status = AiBridgeApprovalRequest::STATUS_PENDING;

                if ($this->module->isDirectApplyTestMode()) {
                    if (!$request->approveForDirectApiExecution()) {
                        throw new RuntimeException('Direct execution preparation failed.');
                    }

                    $applied = (new AiBridgeApprovalExecutor())->execute($request, $employeeId);
                    $status = (string) $request->status;
                }
            }

            $this->sendJson(200, array(
                'success' => true,
                'data' => array(
                    'id' => $preview['id'],
                    'valid' => $preview['valid'],
                    'payload_hash' => $preview['payload_hash'],
                    'changes' => $preview['changes'],
                    'approval_uuid' => $approvalUuid,
                    'status' => $status,
                    'applied' => $applied,
                ),
            ));
        } catch (\Throwable $exception) {
            $this->sendJson(500, array(
                'success' => false,
                'error' => array('code' => 'category_preview_internal_error', 'message' => 'Category preview could not be generated.'),
            ));
        }
    }

    private function sendJson($statusCode, array $payload)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}
