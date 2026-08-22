<?php

class AiBridgeAddressPreview
{
    private const TEXT_FIELDS = array('alias', 'firstname', 'lastname', 'address1', 'address2', 'city', 'postcode', 'phone', 'phone_mobile', 'company', 'other', 'dni', 'vat_number');
    private const ALLOWED_FIELDS = array('alias', 'firstname', 'lastname', 'address1', 'address2', 'city', 'postcode', 'phone', 'phone_mobile', 'company', 'other', 'dni', 'vat_number', 'id_country', 'id_state');

    public function build($addressId, array $payload)
    {
        $address = new Address((int) $addressId);

        if (!Validate::isLoadedObject($address) || (bool) $address->deleted) {
            return null;
        }

        $changes = $this->buildChanges($address, $payload);
        $valid = true;

        foreach ($changes as $change) {
            if (!$change['validation']['ok']) {
                $valid = false;
                break;
            }
        }

        $canonicalPayload = array();
        foreach ($changes as $change) {
            if ($change['validation']['ok']) {
                $canonicalPayload[$change['field']] = $change['proposed'];
            }
        }
        ksort($canonicalPayload);

        return array(
            'id' => (int) $address->id,
            'valid' => $valid,
            'canonical_payload' => $canonicalPayload,
            'payload_hash' => hash('sha256', json_encode($canonicalPayload)),
            'address_date_upd_snapshot' => (string) $address->date_upd,
            'changes' => $changes,
        );
    }

    private function buildChanges(Address $address, array $payload)
    {
        $changes = array();

        foreach ($payload as $field => $proposed) {
            if (!in_array($field, self::ALLOWED_FIELDS, true)) {
                $changes[] = $this->buildChange((string) $field, null, $proposed, array('Field is not allowed.'));
                continue;
            }

            if ($field === 'id_country') {
                $changes[] = $this->buildCountryChange($address, $proposed);
                continue;
            }

            if ($field === 'id_state') {
                $changes[] = $this->buildStateChange($address, $proposed);
                continue;
            }

            $changes[] = $this->buildTextChange($address, $field, $proposed);
        }

        return array_values(array_filter($changes, function (array $change) {
            return $change['changed'] || !$change['validation']['ok'];
        }));
    }

    private function buildCountryChange(Address $address, $proposed)
    {
        $countryId = $this->positiveInteger($proposed);
        $country = $countryId !== null ? new Country($countryId) : null;
        $valid = $countryId !== null && Validate::isLoadedObject($country) && (bool) $country->active;

        return $this->buildChange(
            'id_country',
            (int) $address->id_country,
            $countryId === null ? $proposed : $countryId,
            $valid ? array() : array('Invalid country.')
        );
    }

    private function buildStateChange(Address $address, $proposed)
    {
        $stateId = $this->nonNegativeInteger($proposed);
        $errors = array();

        if ($stateId === null) {
            $errors[] = 'Invalid state.';
        } elseif ($stateId > 0) {
            $state = new State($stateId);
            $targetCountryId = (int) $address->id_country;
            if (!Validate::isLoadedObject($state) || (int) $state->id_country !== $targetCountryId) {
                $errors[] = 'Invalid state.';
            }
        }

        return $this->buildChange(
            'id_state',
            (int) $address->id_state,
            $stateId === null ? $proposed : $stateId,
            $errors
        );
    }

    private function buildTextChange(Address $address, $field, $proposed)
    {
        $errors = array();
        $value = is_string($proposed) ? trim(str_replace(array("\r\n", "\r"), ' ', $proposed)) : null;

        if ($value === null) {
            $errors[] = 'Value must be a string.';
        } else {
            $required = in_array($field, array('alias', 'firstname', 'lastname', 'address1', 'city', 'postcode'), true);
            if ($required && $value === '') {
                $errors[] = 'Value cannot be empty.';
            } elseif ($value !== '') {
                $definition = Address::$definition['fields'][$field] ?? null;
                $size = is_array($definition) && isset($definition['size']) ? (int) $definition['size'] : null;
                $validator = is_array($definition) && isset($definition['validate']) ? $definition['validate'] : null;

                if ($size !== null && Tools::strlen($value) > $size) {
                    $errors[] = 'Value exceeds the maximum allowed length.';
                }
                if (is_string($validator) && $validator !== '' && method_exists('Validate', $validator)
                    && !call_user_func(array('Validate', $validator), $value)) {
                    $errors[] = 'Invalid field value.';
                }
            }
        }

        $current = isset($address->{$field}) && is_string($address->{$field}) ? $address->{$field} : '';

        return $this->buildChange($field, $current, $value === null ? $proposed : $value, $errors);
    }

    private function positiveInteger($value)
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function nonNegativeInteger($value)
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function buildChange($field, $current, $proposed, array $errors)
    {
        return array(
            'field' => $field,
            'current' => $current,
            'proposed' => $proposed,
            'changed' => $current !== $proposed,
            'validation' => array('ok' => count($errors) === 0, 'errors' => $errors),
        );
    }
}
