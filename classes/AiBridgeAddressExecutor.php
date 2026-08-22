<?php

require_once dirname(__FILE__) . '/AiBridgeExecutionLog.php';

class AiBridgeAddressExecutor
{
    private const TEXT_FIELDS = array('alias', 'firstname', 'lastname', 'address1', 'address2', 'city', 'postcode', 'phone', 'phone_mobile', 'company', 'other', 'dni', 'vat_number');
    private const ALLOWED_FIELDS = array('alias', 'firstname', 'lastname', 'address1', 'address2', 'city', 'postcode', 'phone', 'phone_mobile', 'company', 'other', 'dni', 'vat_number', 'id_country', 'id_state');

    public function execute(AiBridgeApprovalRequest $request, $employeeId)
    {
        if ($request->status !== AiBridgeApprovalRequest::STATUS_APPROVED
            || strtotime($request->expires_at) < time()) {
            return $this->recordFailure($request, $employeeId, 'Request is not executable.', null);
        }

        $request->status = AiBridgeApprovalRequest::STATUS_EXECUTING;
        $request->execution_status = 'executing';
        $request->executed_by_employee_id = (int) $employeeId;
        $request->execution_error = null;
        $request->executed_at = null;
        if (!$request->update()) {
            return $this->recordFailure($request, $employeeId, 'Execution state could not be saved.', $request->product_id);
        }

        $address = null;
        $snapshot = null;
        try {
            $payload = json_decode($request->payload_json, true);
            if (!is_array($payload) || !$payload || count($payload) > count(self::ALLOWED_FIELDS)) {
                throw new Exception('Invalid approved payload.');
            }

            $address = new Address((int) $request->product_id);
            if (!Validate::isLoadedObject($address)
                || (bool) $address->deleted
                || $address->date_upd !== $request->product_date_upd_snapshot) {
                throw new Exception('Address changed since approval.');
            }

            $fields = $this->validatePayload($payload, $address);
            $currentHash = hash('sha256', json_encode($this->canonicalizeCurrent($fields)));
            if (!hash_equals((string) $request->payload_hash, $currentHash)) {
                throw new Exception('Payload hash mismatch.');
            }

            if (array_key_exists('id_state', $fields) && (int) $fields['id_state'] > 0) {
                $targetCountryId = array_key_exists('id_country', $fields) ? (int) $fields['id_country'] : (int) $address->id_country;
                $state = new State((int) $fields['id_state']);
                if (!Validate::isLoadedObject($state) || (int) $state->id_country !== $targetCountryId) {
                    throw new Exception('Invalid state.');
                }
            }

            $snapshot = $this->capture($address);

            foreach ($fields as $field => $value) {
                $address->{$field} = $value;
            }

            if (!$address->update()) {
                throw new Exception('Address update failed.');
            }

            if (!$this->verify((int) $address->id, $fields)) {
                throw new Exception('Address verification failed.');
            }

            $request->status = AiBridgeApprovalRequest::STATUS_EXECUTED;
            $request->execution_status = 'executed';
            $request->executed_at = date('Y-m-d H:i:s');
            $request->execution_error = null;
            if (!$request->update()) {
                throw new Exception('Execution audit update failed.');
            }

            if (!AiBridgeExecutionLog::record(
                $request->id,
                null,
                'apply-update-address',
                array_keys($fields),
                'success',
                null,
                $employeeId
            )) {
                throw new Exception('Execution audit log failed.');
            }

            return true;
        } catch (\Throwable $exception) {
            $error = $this->getSafeError($exception);

            if ($snapshot !== null && $address !== null && !$this->restore($address, $snapshot)) {
                $error = 'Address rollback requires manual review.';
            }

            return $this->recordFailure($request, $employeeId, $error, $request->product_id, $this->debugDetail($exception));
        }
    }

    private function validatePayload(array $payload, Address $address)
    {
        $fields = array();

        foreach ($payload as $field => $value) {
            if ($field === 'id_country') {
                if ((is_int($value) && $value > 0) || (is_string($value) && ctype_digit($value) && (int) $value > 0)) {
                    $countryId = (int) $value;
                    $country = new Country($countryId);
                    if (Validate::isLoadedObject($country) && (bool) $country->active) {
                        $fields['id_country'] = $countryId;
                        continue;
                    }
                }
                throw new Exception('Invalid approved payload.');
            }

            if ($field === 'id_state') {
                if ((is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value))) {
                    $fields['id_state'] = (int) $value;
                    continue;
                }
                throw new Exception('Invalid approved payload.');
            }

            if (in_array($field, self::TEXT_FIELDS, true)) {
                if (is_string($value)) {
                    $fields[$field] = str_replace(array("\r\n", "\r"), ' ', $value);
                    continue;
                }
                throw new Exception('Invalid approved payload.');
            }

            throw new Exception('Invalid approved payload.');
        }

        ksort($fields);

        return $fields;
    }

    private function canonicalizeCurrent(array $fields)
    {
        ksort($fields);

        return $fields;
    }

    private function capture(Address $address)
    {
        $snapshot = array('id_country' => (int) $address->id_country, 'id_state' => (int) $address->id_state);
        foreach (self::TEXT_FIELDS as $field) {
            $snapshot[$field] = is_string($address->{$field}) ? $address->{$field} : '';
        }

        return $snapshot;
    }

    private function restore(Address $address, array $snapshot)
    {
        foreach ($snapshot as $field => $value) {
            $address->{$field} = $value;
        }

        return $address->update();
    }

    private function verify($addressId, array $fields)
    {
        $address = new Address((int) $addressId);
        if (!Validate::isLoadedObject($address)) {
            return false;
        }

        foreach ($fields as $field => $value) {
            $actual = in_array($field, self::TEXT_FIELDS, true) ? (string) $address->{$field} : (int) $address->{$field};
            $expected = in_array($field, self::TEXT_FIELDS, true) ? (string) $value : (int) $value;
            if ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function recordFailure(AiBridgeApprovalRequest $request, $employeeId, $error, $addressId, $debugDetail = null)
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
            'apply-update-address',
            array(),
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

    private function getSafeError(\Throwable $exception)
    {
        $allowed = array(
            'Invalid approved payload.',
            'Payload hash mismatch.',
            'Address changed since approval.',
            'Address update failed.',
            'Address verification failed.',
            'Invalid state.',
            'Address rollback requires manual review.',
            'Execution audit update failed.',
            'Execution audit log failed.',
            'Execution state could not be saved.',
            'Request is not executable.',
        );

        return in_array($exception->getMessage(), $allowed, true) ? $exception->getMessage() : 'Address update failed.';
    }
}
