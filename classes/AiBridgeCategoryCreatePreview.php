<?php

class AiBridgeCategoryCreatePreview
{
    private const ALLOWED_FIELDS = array(
        'shop_id',
        'language_id',
        'id_parent',
        'name',
        'link_rewrite',
        'description',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'active',
    );

    private const OPTIONAL_TEXT_FIELDS = array(
        'description' => array('required' => false, 'validate' => 'isCleanHtml'),
        'meta_title' => array('required' => false, 'validate' => 'isGenericName'),
        'meta_description' => array('required' => false, 'validate' => 'isGenericName'),
        'meta_keywords' => array('required' => false, 'validate' => 'isGenericName'),
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

        $parentId = $this->positiveInteger($payload['id_parent'] ?? null);
        if ($parentId === null || !$this->isActiveCategory($parentId)) {
            $errors[] = 'Invalid parent category.';
        } else {
            $canonical['id_parent'] = $parentId;
        }

        $canonical['name'] = $this->normalizeTranslations(
            $payload['name'] ?? null,
            $languageId,
            $shopId,
            'name',
            true,
            'isCatalogName',
            128,
            $errors
        );
        $canonical['link_rewrite'] = $this->normalizeTranslations(
            $payload['link_rewrite'] ?? null,
            $languageId,
            $shopId,
            'link_rewrite',
            true,
            'isLinkRewrite',
            128,
            $errors
        );

        foreach (self::OPTIONAL_TEXT_FIELDS as $field => $rules) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $canonical[$field] = $this->normalizeTranslations(
                $payload[$field],
                $languageId,
                $shopId,
                $field,
                false,
                $rules['validate'],
                $field === 'description' ? null : ($field === 'meta_description' ? 512 : 255),
                $errors
            );
        }

        if (!array_key_exists('active', $payload) || $payload['active'] !== false) {
            $errors[] = 'New categories must be inactive.';
        } else {
            $canonical['active'] = false;
        }

        if (empty($errors) && $this->hasDuplicateLinkRewrite(
            $canonical['link_rewrite'][$languageId],
            $languageId,
            $shopId
        )) {
            $errors[] = 'Link rewrite already exists.';
            $conflict = true;
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
            'field' => 'category_create',
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
            'category_date_upd_snapshot' => null,
            'changes' => array($change),
        );
    }

    private function normalizeTranslations($values, $effectiveLanguageId, $shopId, $field, $required, $validator, $size, array &$errors)
    {
        if ($values === null && !$required) {
            return array();
        }

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
            if (($required && trim($value) === '')
                || ($size !== null && Tools::strlen($value) > $size)
                || ($validator !== null && method_exists('Validate', $validator) && !call_user_func(array('Validate', $validator), $value))) {
                $errors[] = 'Invalid ' . $field . '.';
                continue;
            }

            $normalized[$languageId] = $value;
        }

        if ($required && !isset($normalized[$effectiveLanguageId])) {
            $errors[] = 'Missing ' . $field . ' for selected language.';
        }

        ksort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function hasDuplicateLinkRewrite($linkRewrite, $languageId, $shopId)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_category` FROM `' . _DB_PREFIX_ . 'category_lang`
            WHERE `link_rewrite` = \'' . pSQL($linkRewrite) . '\' AND `id_lang` = ' . (int) $languageId
            . ' AND `id_shop` = ' . (int) $shopId
        );

        return is_array($rows) && count($rows) > 0;
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

    private function isActiveCategory($categoryId)
    {
        $category = new Category((int) $categoryId);

        return Validate::isLoadedObject($category) && (bool) $category->active;
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
