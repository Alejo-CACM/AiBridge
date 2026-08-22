<?php

require_once dirname(__FILE__) . '/AiBridgeEmailSendPreview.php';
require_once dirname(__FILE__) . '/AiBridgeEmailTemplate.php';
require_once dirname(__FILE__) . '/AiBridgeExecutionLog.php';

class AiBridgeEmailSendExecutor
{
    public function execute(AiBridgeApprovalRequest $request, $employeeId)
    {
        if ($request->status !== AiBridgeApprovalRequest::STATUS_APPROVED
            || strtotime($request->expires_at) < time()) {
            return $this->recordFailure($request, $employeeId, 'Request is not executable.');
        }

        $request->status = AiBridgeApprovalRequest::STATUS_EXECUTING;
        $request->execution_status = 'executing';
        $request->executed_by_employee_id = (int) $employeeId;
        $request->execution_error = null;
        $request->executed_at = null;
        if (!$request->update()) {
            return $this->recordFailure($request, $employeeId, 'Execution state could not be saved.');
        }

        $templateDir = null;
        try {
            $payload = json_decode($request->payload_json, true);
            if (!is_array($payload)) {
                throw new Exception('Invalid approved payload.');
            }

            // Same idempotency lesson as images/address/email-template: strip the
            // shop_id/language_id merged into the stored canonical payload before
            // re-validating, since build() takes those as separate parameters.
            $rawPayload = $payload;
            unset($rawPayload['shop_id'], $rawPayload['language_id']);

            $preview = (new AiBridgeEmailSendPreview())->build(
                $rawPayload,
                (int) $request->shop_id,
                (int) $request->language_id
            );
            if (!$preview['valid']) {
                throw new Exception('Invalid approved payload.');
            }

            if (!hash_equals((string) $request->payload_hash, (string) $preview['payload_hash'])) {
                throw new Exception('Payload hash mismatch.');
            }

            $canonical = $preview['canonical_payload'];

            $template = AiBridgeEmailTemplate::findByName($canonical['template']);
            if ($template === null) {
                throw new Exception('Template not found.');
            }

            $variables = is_array($canonical['variables']) ? $canonical['variables'] : array();
            $subject = AiBridgeEmailTemplate::render($template->subject, $variables);
            $html = AiBridgeEmailTemplate::render($template->html_body, $variables);
            $text = trim(strip_tags(str_replace(array('<br>', '<br/>', '<br />'), "\n", $html)));

            $language = new Language((int) $canonical['language_id']);
            $isoCode = Validate::isLoadedObject($language) ? $language->iso_code : 'en';

            // Mail::send()'s $templatePath argument is mostly cosmetic: internally it
            // recomputes the real path via getTemplateBasePath(), which only ever
            // looks in the active theme's mails/ dir or, when the path we passed in
            // matches ".../modules/<name>/mails/", in that module's own mails/ dir.
            // A location like _PS_CACHE_DIR_ is silently ignored — verified by
            // reading classes/Mail.php on saruia.es after Mail::Send() kept
            // returning false with the cache-dir approach. Writing under this
            // module's own mails/ dir is what actually gets picked up.
            $templateSlug = 'send_' . (int) $request->id;
            $templateDir = _PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'aibridge' . DIRECTORY_SEPARATOR . 'mails' . DIRECTORY_SEPARATOR;
            $langDir = $templateDir . $isoCode . DIRECTORY_SEPARATOR;
            if (!is_dir($langDir) && !mkdir($langDir, 0700, true) && !is_dir($langDir)) {
                throw new Exception('Mail template directory could not be created.');
            }

            file_put_contents($langDir . $templateSlug . '.html', $html);
            file_put_contents($langDir . $templateSlug . '.txt', $text);

            $sent = Mail::Send(
                (int) $canonical['language_id'],
                $templateSlug,
                $subject,
                array(),
                (string) $canonical['to'],
                $canonical['to_name'] !== '' ? (string) $canonical['to_name'] : null,
                null,
                null,
                null,
                null,
                $templateDir,
                false,
                (int) $canonical['shop_id']
            );

            if (!$sent) {
                throw new Exception('Email send returned false.');
            }

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
                'apply-send-email',
                array('email_send'),
                'success',
                null,
                $employeeId
            )) {
                throw new Exception('Execution audit log failed.');
            }

            return true;
        } catch (\Throwable $exception) {
            return $this->recordFailure($request, $employeeId, $this->safeError($exception), $this->debugDetail($exception));
        } finally {
            $this->cleanupTemplateFiles($templateDir, $request->id);
        }
    }

    private function cleanupTemplateFiles($templateDir, $requestId)
    {
        if ($templateDir === null) {
            return;
        }

        foreach (glob($templateDir . '*' . DIRECTORY_SEPARATOR . 'send_' . (int) $requestId . '.*') ?: array() as $file) {
            @unlink($file);
        }
    }

    private function recordFailure(AiBridgeApprovalRequest $request, $employeeId, $error, $debugDetail = null)
    {
        $request->status = AiBridgeApprovalRequest::STATUS_FAILED;
        $request->execution_status = 'failed';
        $request->executed_by_employee_id = (int) $employeeId;
        $request->executed_at = date('Y-m-d H:i:s');
        $request->execution_error = $error;
        $request->update();

        AiBridgeExecutionLog::record(
            $request->id,
            null,
            'apply-send-email',
            array('email_send'),
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
            'Payload hash mismatch.',
            'Template not found.',
            'Mail template directory could not be created.',
            'Email send returned false.',
            'Execution audit update failed.',
            'Execution audit log failed.',
        );

        $message = $exception->getMessage();

        return in_array($message, $allowed, true) ? $message : 'Email send returned false.';
    }
}
