<?php

class AiBridgeAddressCreatePreview
{
    private const REQUIRED_FIELDS = array('id_customer', 'alias', 'firstname', 'lastname', 'address1', 'city', 'id_country', 'postcode');
    private const OPTIONAL_FIELDS = array('address2', 'id_state', 'phone', 'phone_mobile', 'company', 'other', 'dni', 'vat_number');
    private const TEXT_FIELDS = array('alias', 'firstname', 'lastname', 'address1', 'address2', 'city', 'postcode', 'phone', 'phone_mobile', 'company', 'other', 'dni', 'vat_number');

    public function build(array $payload, $shopId, $languageId)
    {
        $errors = array();
        $allowedFields = array_merge(self::REQUIRED_FIELDS, self::OPTIONAL_FIELDS);

        $unknownFields = array_diff(array_keys($payload), $allowedFields);
        if (!empty($unknownFields)) {
            $errors[] = 'Unsupported field.';
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                $errors[] = 'Missing ' . $field . '.';
            }
        }

        $canonical = array();

        $customerId = $this->positiveInteger($payload['id_customer'] ?? null);
        if ($customerId === null || !$this->customerExists($customerId)) {
            $errors[] = 'Invalid customer.';
        } else {
            $canonical['id_customer'] = $customerId;
        }

        $countryId = $this->positiveInteger($payload['id_country'] ?? null);
        $country = $countryId !== null ? new Country($countryId) : null;
        if ($countryId === null || !Validate::isLoadedObject($country) || !(bool) $country->active) {
            $errors[] = 'Invalid country.';
            $country = null;
        } else {
            $canonical['id_country'] = $countryId;
        }

        if ($country !== null && (bool) $country->contains_states) {
            $stateId = $this->positiveInteger($payload['id_state'] ?? null);
            $state = $stateId !== null ? new State($stateId) : null;
            if ($stateId === null || !Validate::isLoadedObject($state) || (int) $state->id_country !== $countryId) {
                $errors[] = 'Invalid state.';
            } else {
                $canonical['id_state'] = $stateId;
            }
        } elseif (array_key_exists('id_state', $payload)) {
            $stateId = $this->nonNegativeInteger($payload['id_state']);
            $canonical['id_state'] = $stateId !== null ? $stateId : 0;
        } else {
            $canonical['id_state'] = 0;
        }

        foreach (self::TEXT_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $value = $this->normalizeTextField($field, $payload[$field], $errors);
            if ($value !== null) {
                $canonical[$field] = $value;
            }
        }

        if (empty($canonical['phone']) && empty($canonical['phone_mobile'])) {
            $errors[] = 'At least one of phone or phone_mobile is required.';
        }

        if ($country !== null && (bool) $country->need_identification_number && empty($canonical['dni'])) {
            $errors[] = 'Missing dni (required for this country).';
        }

        if (!empty($errors)) {
            return array(
                'valid' => false,
                'errors' => array_values(array_unique($errors)),
            );
        }

        $canonical['shop_id'] = (int) $shopId;
        $canonical['language_id'] = (int) $languageId;
        $canonical = $this->canonicalize($canonical);

        $change = array(
            'field' => 'address_create',
            'current' => null,
            'proposed' => $canonical,
            'changed' => true,
            'validation' => array('ok' => true, 'errors' => array()),
        );

        return array(
            'valid' => true,
            'shop_id' => (int) $shopId,
            'language_id' => (int) $languageId,
            'canonical_payload' => $canonical,
            'payload_hash' => hash('sha256', json_encode($canonical)),
            'changes' => array($change),
        );
    }

    private function normalizeTextField($field, $value, array &$errors)
    {
        if (!is_string($value)) {
            $errors[] = 'Invalid ' . $field . '.';

            return null;
        }

        $value = trim(str_replace(array("\r\n", "\r"), ' ', $value));
        $required = in_array($field, self::REQUIRED_FIELDS, true);

        if ($required && $value === '') {
            $errors[] = 'Missing ' . $field . '.';

            return null;
        }

        if ($value === '' && !$required) {
            return '';
        }

        $definition = Address::$definition['fields'][$field] ?? null;
        $size = is_array($definition) && isset($definition['size']) ? (int) $definition['size'] : null;
        $validator = is_array($definition) && isset($definition['validate']) ? $definition['validate'] : null;

        if ($size !== null && Tools::strlen($value) > $size) {
            $errors[] = 'Value exceeds the maximum allowed length for ' . $field . '.';

            return null;
        }

        if (is_string($validator) && $validator !== '' && method_exists('Validate', $validator)
            && !call_user_func(array('Validate', $validator), $value)) {
            $errors[] = 'Invalid ' . $field . '.';

            return null;
        }

        return $value;
    }

    private function customerExists($customerId)
    {
        $customer = new Customer((int) $customerId);

        return Validate::isLoadedObject($customer) && !(bool) $customer->deleted;
    }

    private function positiveInteger($value)
    {
        if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function nonNegativeInteger($value)
    {
        if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            return null;
        }

        return (int) $value;
    }

    private function canonicalize(array $payload)
    {
        ksort($payload, SORT_STRING);

        return $payload;
    }
}
