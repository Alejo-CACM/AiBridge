<?php

class AiBridgeCategoryPreview
{
    private const TEXT_FIELDS = array(
        'name' => array('required' => true, 'validate' => 'isCatalogName', 'size' => 128),
        'link_rewrite' => array('required' => true, 'validate' => 'isLinkRewrite', 'size' => 128),
        'description' => array('required' => false, 'validate' => 'isCleanHtml', 'size' => null),
        'meta_title' => array('required' => false, 'validate' => 'isGenericName', 'size' => 255),
        'meta_description' => array('required' => false, 'validate' => 'isGenericName', 'size' => 512),
        'meta_keywords' => array('required' => false, 'validate' => 'isGenericName', 'size' => 255),
    );

    public function build($categoryId, array $payload, $languageId, $shopId)
    {
        $category = new Category((int) $categoryId, $languageId, $shopId);

        if (!Validate::isLoadedObject($category)) {
            return null;
        }

        $canonicalPayload = $this->canonicalizePayload($payload, $languageId, $shopId);
        $changes = $this->buildChanges($category, $canonicalPayload, $languageId, $shopId);
        $valid = true;

        foreach ($changes as $change) {
            if (!$change['validation']['ok']) {
                $valid = false;
                break;
            }
        }

        return array(
            'id' => (int) $category->id,
            'shop_id' => (int) $shopId,
            'language_id' => (int) $languageId,
            'valid' => $valid,
            'canonical_payload' => $canonicalPayload,
            'payload_hash' => hash('sha256', json_encode($canonicalPayload)),
            'category_date_upd_snapshot' => (string) $category->date_upd,
            'changes' => $changes,
        );
    }

    private function buildChanges(Category $category, array $payload, $languageId, $shopId)
    {
        $changes = array();

        foreach ($payload as $field => $proposed) {
            if ($field === 'id_parent') {
                $changes[] = $this->buildParentChange($category, $proposed);
                continue;
            }

            if (array_key_exists($field, self::TEXT_FIELDS)) {
                $changes[] = $this->buildTextChange($category, $field, $proposed, $languageId, $shopId);
                continue;
            }

            if ($field === 'active') {
                $changes[] = $this->buildActiveChange($category, $proposed);
                continue;
            }

            $changes[] = $this->buildChange((string) $field, null, $proposed, array('Field is not allowed.'));
        }

        return array_values(array_filter($changes, function (array $change) {
            return $change['changed'] || !$change['validation']['ok'];
        }));
    }

    private function buildParentChange(Category $category, $proposed)
    {
        $parentId = $this->normalizePositiveInteger($proposed);
        $valid = $parentId !== null
            && $this->categoryExists($parentId)
            && Category::checkBeforeMove((int) $category->id, $parentId);

        return $this->buildChange(
            'id_parent',
            (int) $category->id_parent,
            $parentId === null ? $proposed : $parentId,
            $valid ? array() : array('Invalid or circular parent category.')
        );
    }

    private function buildTextChange(Category $category, $field, $proposed, $languageId, $shopId)
    {
        $rules = self::TEXT_FIELDS[$field];
        $value = is_string($proposed) ? str_replace(array("\r\n", "\r"), "\n", $proposed) : null;
        $errors = array();

        if ($value === null) {
            $errors[] = 'Value must be a string.';
        } else {
            if ($rules['required'] && trim($value) === '') {
                $errors[] = 'Value cannot be empty.';
            }
            if ($rules['size'] !== null && Tools::strlen($value) > $rules['size']) {
                $errors[] = 'Value exceeds the maximum allowed length.';
            }
            if (!call_user_func(array('Validate', $rules['validate']), $value)) {
                $errors[] = 'Invalid field value.';
            }
        }

        $current = isset($category->{$field}) && is_string($category->{$field}) ? $category->{$field} : '';

        return $this->buildChange($field, $current, $value === null ? $proposed : $value, $errors);
    }

    private function buildActiveChange(Category $category, $proposed)
    {
        $value = $this->normalizeBoolean($proposed);

        return $this->buildChange(
            'active',
            (int) $category->active,
            $value === null ? $proposed : $value,
            $value === null ? array('Value must be a boolean or 0 or 1.') : array()
        );
    }

    private function canonicalizePayload(array $payload, $languageId, $shopId)
    {
        foreach ($payload as $field => $value) {
            if ($field === 'id_parent') {
                $normalized = $this->normalizePositiveInteger($value);
                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }
                continue;
            }

            if (array_key_exists($field, self::TEXT_FIELDS) && is_string($value)) {
                $payload[$field] = str_replace(array("\r\n", "\r"), "\n", $value);
                continue;
            }

            if ($field === 'active') {
                $normalized = $this->normalizeBoolean($value);
                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }
            }
        }

        ksort($payload);

        return $payload;
    }

    private function categoryExists($categoryId)
    {
        return Category::existsInDatabase((int) $categoryId);
    }

    private function normalizePositiveInteger($value)
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeBoolean($value)
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value;
        }
        if (is_string($value) && ($value === '0' || $value === '1')) {
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
