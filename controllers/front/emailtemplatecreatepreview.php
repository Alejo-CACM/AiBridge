<?php

require_once dirname(__FILE__) . '/../../classes/AiBridgeEmailTemplateCreatePreview.php';
require_once dirname(__FILE__) . '/../../classes/AiBridgeApprovalRequest.php';
require_once dirname(__FILE__) . '/../../classes/AiBridgeApprovalExecutor.php';

class AibridgeEmailtemplatecreatepreviewModuleFrontController extends ModuleFrontController
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

            $preview = (new AiBridgeEmailTemplateCreatePreview())->build(
                $payload,
                (int) $this->context->shop->id,
                (int) $this->context->language->id
            );
            if (!$preview['valid']) {
                $this->sendJson($preview['conflict'] ? 409 : 400, array(
                    'success' => false,
                    'error' => array(
                        'code' => $preview['conflict'] ? 'duplicate_template' : 'invalid_payload',
                        'message' => $preview['conflict'] ? 'A template with this name already exists.' : 'Invalid email template payload.',
                    ),
                    'errors' => $preview['errors'],
                ));
            }

            $employeeId = $this->module->getAuthenticatedEmployeeId();
            $request = AiBridgeApprovalRequest::createPendingEmailTemplateCreate(
                $preview,
                $preview['canonical_payload'],
                $employeeId
            );
            if (!$request->add()) {
                throw new RuntimeException('Approval request creation failed.');
            }

            $status = AiBridgeApprovalRequest::STATUS_PENDING;
            $applied = null;
            if ($this->module->isDirectApplyTestMode()) {
                if (!$request->approveForDirectApiExecution()) {
                    throw new RuntimeException('Direct execution preparation failed.');
                }

                $applied = (new AiBridgeApprovalExecutor())->execute($request, $employeeId);
                $status = (string) $request->status;
            }

            $this->sendJson(200, array(
                'success' => true,
                'data' => array(
                    'valid' => true,
                    'payload_hash' => $preview['payload_hash'],
                    'changes' => $preview['changes'],
                    'approval_uuid' => $request->uuid,
                    'status' => $status,
                    'applied' => $applied,
                ),
            ));
        } catch (\Throwable $exception) {
            $this->sendJson(500, array(
                'success' => false,
                'error' => array('code' => 'email_template_create_preview_internal_error', 'message' => 'Email template creation preview could not be generated.'),
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
