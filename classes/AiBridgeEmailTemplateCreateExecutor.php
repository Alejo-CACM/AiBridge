<?php

require_once dirname(__FILE__) . '/AiBridgeEmailTemplateCreatePreview.php';
require_once dirname(__FILE__) . '/AiBridgeEmailTemplate.php';
require_once dirname(__FILE__) . '/AiBridgeExecutionLog.php';

class AiBridgeEmailTemplateCreateExecutor
{
    public function execute(AiBridgeApprovalRequest $request, $employeeId)
    {
        if ($request->status !== AiBridgeApprovalRequest::STATUS_APPROVED
            || strtotime($request->expires_at) < time()
            || ($request->created_product_id !== null && $request->created_product_id !== '')) {
            return $this->recordFailure($request, $employeeId, 'Request is not executable.', null);
        }

        $request->status = AiBridgeApprovalRequest::STATUS_EXECUTING;
        $request->execution_status = 'executing';
        $request->executed_by_employee_id = (int) $employeeId;
        $request->execution_error = null;
        $request->executed_at = null;
        if (!$request->update()) {
            return $this->recordFailure($request, $employeeId, 'Execution state could not be saved.', null);
        }

        $createdTemplateId = null;
        try {
            $payload = json_decode($request->payload_json, true);
            if (!is_array($payload)) {
                throw new Exception('Invalid approved payload.');
            }

            // The stored canonical payload has shop_id/language_id merged in (see
            // AiBridgeEmailTemplateCreatePreview::build()), but build() takes those
            // as separate parameters, not payload keys — strip them before
            // re-validating (same lesson as the images/address idempotency bugs).
            $rawPayload = $payload;
            unset($rawPayload['shop_id'], $rawPayload['language_id']);

            $preview = (new AiBridgeEmailTemplateCreatePreview())->build(
                $rawPayload,
                (int) $request->shop_id,
                (int) $request->language_id
            );
            if (!$preview['valid']) {
                throw new Exception($preview['conflict'] ? 'Template name already exists.' : 'Invalid approved payload.');
            }

            if (!hash_equals((string) $request->payload_hash, (string) $preview['payload_hash'])) {
                throw new Exception('Payload hash mismatch.');
            }

            $canonical = $preview['canonical_payload'];

            $template = new AiBridgeEmailTemplate();
            $template->name = (string) $canonical['name'];
            $template->subject = (string) $canonical['subject'];
            $template->html_body = (string) $canonical['html_body'];
            $template->created_at = date('Y-m-d H:i:s');
            $template->updated_at = date('Y-m-d H:i:s');

            if (!$template->add()) {
                throw new Exception('Template add returned false.');
            }
            $createdTemplateId = (int) $template->id;

            if (AiBridgeEmailTemplate::findByName($canonical['name']) === null) {
                throw new Exception('Template verification failed.');
            }

            $request->created_product_id = $createdTemplateId;
            $request->status = AiBridgeApprovalRequest::STATUS_EXECUTED;
            $request->execution_status = 'executed';
            $request->executed_by_employee_id = (int) $employeeId;
            $request->executed_at = date('Y-m-d H:i:s');
            $request->execution_error = null;
            if (!$request->update()) {
                throw new Exception('Execution audit update failed.');
            }

            if (!AiBridgeExecutionLog::record(
                $request->id,
                null,
                'apply-create-email-template',
                array('email_template_create'),
                'success',
                null,
                $employeeId
            )) {
                throw new Exception('Execution audit log failed.');
            }

            return true;
        } catch (\Throwable $exception) {
            if ($createdTemplateId !== null && !$this->rollback($createdTemplateId)) {
                return $this->recordFailure($request, $employeeId, 'Template creation rollback requires manual review.', $createdTemplateId, $this->debugDetail($exception));
            }

            return $this->recordFailure($request, $employeeId, $this->safeError($exception), $createdTemplateId, $this->debugDetail($exception));
        }
    }

    private function rollback($templateId)
    {
        $template = new AiBridgeEmailTemplate((int) $templateId);
        if (!Validate::isLoadedObject($template)) {
            return true;
        }

        return $template->delete();
    }

    private function recordFailure(AiBridgeApprovalRequest $request, $employeeId, $error, $templateId, $debugDetail = null)
    {
        $request->status = AiBridgeApprovalRequest::STATUS_FAILED;
        $request->execution_status = 'failed';
        $request->created_product_id = null;
        $request->executed_by_employee_id = (int) $employeeId;
        $request->executed_at = date('Y-m-d H:i:s');
        $request->execution_error = $error;
        $request->update();

        AiBridgeExecutionLog::record(
            $request->id,
            null,
            'apply-create-email-template',
            array('email_template_create'),
            'failed',
            $debugDetail !== null ? $error . ' | debug: ' . $debugDetail : $error,
            $employeeId
        );

        return false;
    }

    private function debugDetail(\Throwable $exception)
    {
        return get_class($exception) . ': ' . $exception->getMessage()
            . ' @ ' . basename($exception->getFile()) . ':' . $exception->getLine();
    }

    private function safeError(\Throwable $exception)
    {
        $allowed = array(
            'Request is not executable.',
            'Execution state could not be saved.',
            'Invalid approved payload.',
            'Template name already exists.',
            'Payload hash mismatch.',
            'Template add returned false.',
            'Template verification failed.',
            'Execution audit update failed.',
            'Execution audit log failed.',
        );

        $message = $exception->getMessage();

        return in_array($message, $allowed, true) ? $message : 'Template add returned false.';
    }
}
