<?php

require_once dirname(__FILE__) . '/AiBridgeManufacturerCreatePreview.php';
require_once dirname(__FILE__) . '/AiBridgeExecutionLog.php';

class AiBridgeManufacturerCreateExecutor
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

        $createdManufacturerId = null;
        try {
            $payload = json_decode($request->payload_json, true);
            if (!is_array($payload)) {
                throw new Exception('Invalid approved payload.');
            }

            $preview = (new AiBridgeManufacturerCreatePreview())->build($payload);
            if (!$preview['valid']) {
                throw new Exception($preview['conflict'] ? 'Manufacturer duplicate detected.' : 'Invalid approved payload.');
            }

            if (!hash_equals((string) $request->payload_hash, (string) $preview['payload_hash'])) {
                throw new Exception('Payload hash mismatch.');
            }

            $canonical = $preview['canonical_payload'];
            $manufacturer = new Manufacturer(null, (int) $canonical['language_id']);
            $manufacturer->name = (string) $canonical['name'];
            $manufacturer->active = false;
            $manufacturer->description = isset($canonical['description']) ? $canonical['description'] : array();
            $manufacturer->short_description = isset($canonical['short_description']) ? $canonical['short_description'] : array();
            $manufacturer->meta_title = isset($canonical['meta_title']) ? $canonical['meta_title'] : array();
            $manufacturer->meta_description = isset($canonical['meta_description']) ? $canonical['meta_description'] : array();
            $manufacturer->meta_keywords = isset($canonical['meta_keywords']) ? $canonical['meta_keywords'] : array();

            $normalValidation = $manufacturer->validateFields(false, true);
            if ($normalValidation !== true) {
                throw new Exception('Manufacturer validation failed.');
            }

            $languageValidation = $manufacturer->validateFieldsLang(false, true);
            if ($languageValidation !== true) {
                throw new Exception('Manufacturer language validation failed.');
            }

            try {
                $added = $manufacturer->add();
            } catch (\PrestaShopException $exception) {
                throw new Exception('Manufacturer add hook failed.');
            }

            if (!$added) {
                $createdManufacturerId = (int) $manufacturer->id > 0 ? (int) $manufacturer->id : null;

                return $this->handleFailure($request, $employeeId, $this->addDiagnostic(), $createdManufacturerId);
            }
            $createdManufacturerId = (int) $manufacturer->id;

            if (!$this->verify($createdManufacturerId, $canonical)) {
                throw new Exception('Manufacturer verification failed.');
            }

            $request->created_product_id = $createdManufacturerId;
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
                $createdManufacturerId,
                'apply-create-manufacturer',
                array('manufacturer_create'),
                'success',
                null,
                $employeeId
            )) {
                throw new Exception('Execution audit log failed.');
            }

            return true;
        } catch (\Throwable $exception) {
            return $this->handleFailure($request, $employeeId, $this->safeError($exception), $createdManufacturerId, $this->debugDetail($exception));
        }
    }

    private function addDiagnostic()
    {
        $db = Db::getInstance();
        $number = (int) $db->getNumberError();
        $message = (string) $db->getMsgError();

        if ($number <= 0 || trim($message) === '') {
            return 'Manufacturer add returned false.';
        }

        $lower = strtolower($message);
        if ((int) $number === 1062 || strpos($lower, 'duplicate') !== false) {
            return 'Manufacturer add database error: duplicate key constraint.';
        }

        return 'Manufacturer add database error: database operation failed.';
    }

    private function verify($manufacturerId, array $payload)
    {
        $manufacturer = new Manufacturer((int) $manufacturerId, (int) $payload['language_id']);

        return Validate::isLoadedObject($manufacturer)
            && (bool) $manufacturer->active === false
            && (string) $manufacturer->name === (string) $payload['name'];
    }

    private function handleFailure(AiBridgeApprovalRequest $request, $employeeId, $error, $createdManufacturerId, $debugDetail = null)
    {
        if ($createdManufacturerId !== null && !$this->rollback($createdManufacturerId)) {
            $error = 'Manufacturer creation rollback requires manual review.';
        }

        return $this->recordFailure($request, $employeeId, $error, $createdManufacturerId, $debugDetail);
    }

    private function rollback($manufacturerId)
    {
        $manufacturer = new Manufacturer((int) $manufacturerId);
        if (!Validate::isLoadedObject($manufacturer) || !$manufacturer->delete()) {
            return false;
        }

        $reloaded = new Manufacturer((int) $manufacturerId);

        return !Validate::isLoadedObject($reloaded);
    }

    private function recordFailure(AiBridgeApprovalRequest $request, $employeeId, $error, $manufacturerId, $debugDetail = null)
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
            $manufacturerId,
            'apply-create-manufacturer',
            array('manufacturer_create'),
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
            'Manufacturer duplicate detected.',
            'Payload hash mismatch.',
            'Manufacturer validation failed.',
            'Manufacturer language validation failed.',
            'Manufacturer add hook failed.',
            'Manufacturer verification failed.',
            'Execution audit update failed.',
            'Execution audit log failed.',
            'Manufacturer creation rollback requires manual review.',
        );

        $message = $exception->getMessage();
        if (strpos($message, 'Manufacturer add database error:') === 0) {
            return $message;
        }

        return in_array($message, $allowed, true) ? $message : 'Manufacturer add returned false.';
    }
}
