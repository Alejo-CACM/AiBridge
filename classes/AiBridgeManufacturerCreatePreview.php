<?php

class AiBridgeManufacturerCreatePreview
{
    private const ALLOWED_FIELDS = array(
        'shop_id',
        'language_id',
        'name',
        'description',
        'short_description',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'active',
    );

    private const OPTIONAL_TEXT_FIELDS = array(
        'description' => 'isCleanHtml',
        'short_description' => 'isCleanHtml',
        'meta_title' => 'isGenericName',
        'meta_description' => 'isGenericName',
        'meta_keywords' => 'isGenericName',
    );

    public function build(array $payload)
    {
        $errors = array();
        $conflict = false;

        $unknownFields = array_diff(array_keys($payload), self::ALLOWED_FIELDS);
        if (!empty($unknownFields)) {
            $errors[] = 'Unsupported field.';
        }

        $shopId = $this->positiveInteger($payload['shop_id'] ?? null);
        $languageId = $this->positiveInteger($payload['language_id'] ?? null);
        if ($shopId === null || !$this->isActiveShop($shopId)) {
            $errors[] = 'Invalid shop.';
        }
        if ($languageId === null || !$this->isActiveShopLanguage($languageId, $shopId)) {
            $errors[] = 'Invalid language.';
        }

        $canonical = array();
        if (empty($errors)) {
            $canonical['shop_id'] = $shopId;
            $canonical['language_id'] = $languageId;
        }

        $name = $payload['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || Tools::strlen($name) > 64 || !Validate::isCatalogName($name)) {
            $errors[] = 'Invalid name.';
        } else {
            $canonical['name'] = $name;
        }

        if (!empty($name) && $this->hasDuplicateName($name)) {
            $errors[] = 'Manufacturer name already exists.';
            $conflict = true;
        }

        foreach (self::OPTIONAL_TEXT_FIELDS as $field => $validator) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $canonical[$field] = $this->normalizeTranslations($payload[$field], $languageId, $shopId, $field, $validator, $field === 'meta_description' ? 512 : ($field === 'meta_title' || $field === 'meta_keywords' ? 255 : null), $errors);
        }

        if (!array_key_exists('active', $payload) || $payload['active'] !== false) {
            $errors[] = 'New manufacturers must be inactive.';
        } else {
            $canonical['active'] = false;
        }

        if (!empty($errors)) {
            return array(
                'valid' => false,
                'conflict' => $conflict,
                'errors' => array_values(array_unique($errors)),
            );
        }

        $canonical = $this->canonicalize($canonical);
        $change = array(
            'field' => 'manufacturer_create',
            'current' => null,
            'proposed' => $canonical,
            'changed' => true,
            'validation' => array('ok' => true, 'errors' => array()),
        );

        return array(
            'valid' => true,
            'conflict' => false,
            'shop_id' => $shopId,
            'language_id' => $languageId,
            'canonical_payload' => $canonical,
            'payload_hash' => hash('sha256', json_encode($canonical)),
            'changes' => array($change),
        );
    }

    private function normalizeTranslations($values, $effectiveLanguageId, $shopId, $field, $validator, $size, array &$errors)
    {
        if (!is_array($values)) {
            $errors[] = 'Invalid ' . $field . '.';

            return array();
        }

        $normalized = array();
        foreach ($values as $languageId => $value) {
            $languageId = $this->positiveInteger($languageId);
            if ($languageId === null || !$this->isActiveShopLanguage($languageId, $shopId) || !is_string($value)) {
                $errors[] = 'Invalid ' . $field . '.';
                continue;
            }

            $value = str_replace(array("\r\n", "\r"), "\n", $value);
            if (($size !== null && Tools::strlen($value) > $size)
                || !call_user_func(array('Validate', $validator), $value)) {
                $errors[] = 'Invalid ' . $field . '.';
                continue;
            }

            $normalized[$languageId] = $value;
        }

        ksort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function hasDuplicateName($name)
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_manufacturer` FROM `' . _DB_PREFIX_ . 'manufacturer` WHERE `name` = \'' . pSQL($name) . '\''
        );

        return $id > 0;
    }

    private function isActiveShop($shopId)
    {
        $shop = new Shop((int) $shopId);

        return Validate::isLoadedObject($shop) && (bool) $shop->active;
    }

    private function isActiveShopLanguage($languageId, $shopId)
    {
        foreach (Language::getLanguages(true, (int) $shopId) as $language) {
            if ((int) $language['id_lang'] === (int) $languageId) {
                return true;
            }
        }

        return false;
    }

    private function positiveInteger($value)
    {
        if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
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
