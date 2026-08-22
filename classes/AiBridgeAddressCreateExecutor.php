<?php

require_once dirname(__FILE__) . '/AiBridgeAddressCreatePreview.php';
require_once dirname(__FILE__) . '/AiBridgeExecutionLog.php';

class AiBridgeAddressCreateExecutor
{
    private const TEXT_FIELDS = array('alias', 'firstname', 'lastname', 'address1', 'address2', 'city', 'postcode', 'phone', 'phone_mobile', 'company', 'other', 'dni', 'vat_number');

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

        $createdAddressId = null;
        try {
            $payload = json_decode($request->payload_json, true);
            if (!is_array($payload)) {
                throw new Exception('Invalid approved payload.');
            }

            // The stored canonical payload has shop_id/language_id merged in (see
            // AiBridgeAddressCreatePreview::build()), but build() takes those as
            // separate parameters, not payload keys — strip them before re-validating,
            // same lesson as the images idempotency bug in AiBridgeProductCreatePreview.
            $rawPayload = $payload;
            unset($rawPayload['shop_id'], $rawPayload['language_id']);

            $preview = (new AiBridgeAddressCreatePreview())->build(
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
            $address = $this->buildAddress($canonical);

            $validation = $address->validateFields(false, true);
            if ($validation !== true) {
                throw new Exception('Address validation failed: ' . (is_string($validation) ? $validation : 'unknown') . '.');
            }

            if (!$address->add()) {
                throw new Exception('Address add returned false.');
            }
            $createdAddressId = (int) $address->id;

            if (!$this->verify($createdAddressId, $canonical)) {
                throw new Exception('Address verification failed.');
            }

            $request->created_product_id = $createdAddressId;
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
                'apply-create-address',
                array('address_create'),
                'success',
                null,
                $employeeId
            )) {
                throw new Exception('Execution audit log failed.');
            }

            return true;
        } catch (\PrestaShopException $exception) {
            return $this->handleFailure($request, $employeeId, 'Address validation failed: ' . $this->validationField($exception->getMessage()) . '.', $createdAddressId, $this->debugDetail($exception));
        } catch (\Throwable $exception) {
            return $this->handleFailure($request, $employeeId, $this->safeError($exception), $createdAddressId, $this->debugDetail($exception));
        }
    }

    private function buildAddress(array $payload)
    {
        $address = new Address();
        $address->id_customer = (int) $payload['id_customer'];
        $address->id_country = (int) $payload['id_country'];
        $address->id_state = (int) ($payload['id_state'] ?? 0);
        $address->alias = (string) $payload['alias'];
        $address->firstname = (string) $payload['firstname'];
        $address->lastname = (string) $payload['lastname'];
        $address->address1 = (string) $payload['address1'];
        $address->address2 = (string) ($payload['address2'] ?? '');
        $address->postcode = (string) $payload['postcode'];
        $address->city = (string) $payload['city'];
        $address->phone = (string) ($payload['phone'] ?? '');
        $address->phone_mobile = (string) ($payload['phone_mobile'] ?? '');
        $address->company = (string) ($payload['company'] ?? '');
        $address->other = (string) ($payload['other'] ?? '');
        $address->dni = (string) ($payload['dni'] ?? '');
        $address->vat_number = (string) ($payload['vat_number'] ?? '');

        return $address;
    }

    private function verify($addressId, array $payload)
    {
        $address = new Address((int) $addressId);
        if (!Validate::isLoadedObject($address)
            || (int) $address->id_customer !== (int) $payload['id_customer']
            || (int) $address->id_country !== (int) $payload['id_country']
            || (int) $address->id_state !== (int) ($payload['id_state'] ?? 0)) {
            return false;
        }

        foreach (self::TEXT_FIELDS as $field) {
            $expected = (string) ($payload[$field] ?? '');
            if ((string) $address->{$field} !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function handleFailure(AiBridgeApprovalRequest $request, $employeeId, $error, $createdAddressId, $debugDetail = null)
    {
        if ($createdAddressId !== null && !$this->rollback($createdAddressId)) {
            $error = 'Address creation rollback requires manual review.';
        }

        return $this->recordFailure($request, $employeeId, $error, $createdAddressId, $debugDetail);
    }

    private function rollback($addressId)
    {
        $address = new Address((int) $addressId);
        if (!Validate::isLoadedObject($address) || !$address->delete()) {
            return false;
        }

        $reloaded = new Address((int) $addressId);

        return !Validate::isLoadedObject($reloaded) || (bool) $reloaded->deleted;
    }

    private function recordFailure(AiBridgeApprovalRequest $request, $employeeId, $error, $addressId, $debugDetail = null)
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
            'apply-create-address',
            array('address_create'),
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

    private function validationField($message)
    {
        if (is_string($message) && preg_match('/Address->([A-Za-z_]+)/', $message, $matches)) {
            return $matches[1];
        }

        return 'field';
    }

    private function safeError(\Throwable $exception)
    {
        $allowed = array(
            'Request is not executable.',
            'Execution state could not be saved.',
            'Invalid approved payload.',
            'Payload hash mismatch.',
            'Address add returned false.',
            'Address verification failed.',
            'Execution audit update failed.',
            'Execution audit log failed.',
            'Address creation rollback requires manual review.',
        );

        $message = $exception->getMessage();
        if (strpos($message, 'Address validation failed:') === 0) {
            return $message;
        }

        return in_array($message, $allowed, true) ? $message : 'Address add returned false.';
    }
}
