<?php

require_once dirname(__FILE__) . '/AiBridgeFeatureHandler.php';
require_once dirname(__FILE__) . '/AiBridgeTagHandler.php';
require_once dirname(__FILE__) . '/AiBridgeDiscountHandler.php';
require_once dirname(__FILE__) . '/AiBridgeStockHandler.php';
require_once dirname(__FILE__) . '/AiBridgeCombinationHandler.php';
require_once dirname(__FILE__) . '/AiBridgeCombinationCreateHandler.php';
require_once dirname(__FILE__) . '/AiBridgeImageHandler.php';
require_once dirname(__FILE__) . '/AiBridgeCombinationImageHandler.php';


class AiBridgeProductPreview
{
    private $featureHandler = null;
    private $tagHandler = null;
    private $discountHandler = null;
    private $stockHandler = null;
    private $combinationHandler = null;
    private $combinationCreateHandler = null;
    private $imageHandler = null;
    private $combinationImageHandler = null;
    private const BOOLEAN_FIELDS = array(
        'active',
        'available_for_order',
        'show_price',
    );

    private const TEXT_FIELDS = array(
        'name',
        'description',
        'description_short',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'link_rewrite',
    );

    private const NON_NEGATIVE_NUMBER_FIELDS = array(
        'wholesale_price',
        'weight',
        'width',
        'height',
        'depth',
    );

    public function build($productId, array $payload, $languageId, $shopId)
    {
        $product = new Product($productId, true, $languageId, $shopId);

        if (!Validate::isLoadedObject($product)) {
            return null;
        }

        $canonicalPayload = $this->canonicalizePayload($payload, $product);
        $changes = $this->buildChanges($product, $canonicalPayload, $languageId);
        $changes = $this->applyClassificationValidation($product, $canonicalPayload, $changes);
        $valid = true;

        foreach ($changes as $change) {
            if (!$change['validation']['ok']) {
                $valid = false;
                break;
            }
        }

        return array(
            'id' => (int) $product->id,
            'shop_id' => (int) $shopId,
            'language_id' => (int) $languageId,
            'valid' => $valid,
            'canonical_payload' => $canonicalPayload,
            'payload_hash' => hash('sha256', json_encode($canonicalPayload)),
            'product_date_upd_snapshot' => (string) $product->date_upd,
            'changes' => $changes,
        );
    }

    private function buildChanges(Product $product, array $payload, $languageId)
    {
        $changes = array();

        foreach ($payload as $field => $proposed) {
            if (in_array($field, self::TEXT_FIELDS, true)) {
                $changes[] = $this->buildTextChange($product, $field, $proposed, $languageId);
                continue;
            }


            if ($field === 'images') {
                $change = $this->getImageHandler()->buildCanonicalChange($product, $proposed, $this->resolveShopId($product));
                if ($change['changed'] || !$change['validation']['ok']) {
                    $changes[] = $change;
                }
                continue;
            }

            if ($field === 'combination_images') {
                $change = $this->getCombinationImageHandler()->buildChange($product, $proposed, $languageId);
                if ($change['changed'] || !$change['validation']['ok']) $changes[] = $change;
                continue;
            }

            if ($field === 'combinations') {
                $handler = is_array($proposed) && isset($proposed['create'])
                    ? $this->getCombinationCreateHandler()
                    : $this->getCombinationHandler();
                $change = $handler->buildChange($product, $proposed, $languageId);
                if ($change['changed'] || !$change['validation']['ok']) $changes[] = $change;
                continue;
            }



            if ($field === 'stock') {
                $change = $this->getStockHandler()->buildChange($product, $proposed, $this->resolveShopId($product));
                if ($change['changed'] || !$change['validation']['ok']) {
                    $changes[] = $change;
                }
                continue;
            }

            if ($field === 'features') {
                $change = $this->getFeatureHandler()->buildChange($product, $proposed);

                if ($change['changed'] || !$change['validation']['ok']) {
                    $changes[] = $change;
                }
                continue;
            }

            if ($field === 'tags') {
                $change = $this->getTagHandler()->buildChange($product, $proposed);

                if ($change['changed'] || !$change['validation']['ok']) {
                    $changes[] = $change;
                }
                continue;
            }

            if ($field === 'discount') {
                $change = $this->getDiscountHandler()->buildChange($product, $proposed);

                if ($change['changed'] || !$change['validation']['ok']) {
                    $changes[] = $change;
                }
                continue;
            }

            if ($field === 'id_manufacturer') {
                $changes[] = $this->buildManufacturerChange($product, $proposed);
                continue;
            }

            if ($field === 'categories') {
                $changes[] = $this->buildCategoriesChange($product, $proposed);
                continue;
            }

            if ($field === 'id_category_default') {
                $changes[] = $this->buildDefaultCategoryChange($product, $proposed);
                continue;
            }
            if ($field === 'price') {
                $changes[] = $this->buildPriceChange($product, $proposed);
                continue;
            }

            if (in_array($field, self::BOOLEAN_FIELDS, true)) {
                $changes[] = $this->buildBooleanChange($product, $field, $proposed);
                continue;
            }

            if ($field === 'reference') {
                $changes[] = $this->buildReferenceChange($product, $proposed);
                continue;
            }

            if ($field === 'minimal_quantity') {
                $changes[] = $this->buildMinimalQuantityChange($product, $proposed);
                continue;
            }

            if (in_array($field, array('ean13', 'isbn', 'upc'), true)) {
                $changes[] = $this->buildIdentifierChange($product, $field, $proposed);
                continue;
            }

            if (in_array($field, self::NON_NEGATIVE_NUMBER_FIELDS, true)) {
                $changes[] = $this->buildNonNegativeNumberChange(
                    $product,
                    $field,
                    $proposed
                );
                continue;
            }

            if ($field === 'id_tax_rules_group') {
                $changes[] = $this->buildTaxRulesGroupChange($product, $proposed);
                continue;
            }

            if ($field === 'condition') {
                $changes[] = $this->buildConditionChange($product, $proposed);
                continue;
            }

            if ($field === 'out_of_stock') {
                $changes[] = $this->buildOutOfStockChange($product, $proposed);
                continue;
            }

            $changes[] = $this->buildChange(
                (string) $field,
                null,
                $proposed,
                array('Field is not allowed.')
            );
        }

        return array_values(array_filter(
            $changes,
            function (array $change) {
                return $change['changed'] || !$change['validation']['ok'];
            }
        ));
    }
    private function buildManufacturerChange(Product $product, $proposed)
    {
        $manufacturerId = $this->normalizeNonNegativeInteger($proposed);
        $valid = $manufacturerId !== null
            && ($manufacturerId === 0 || $this->manufacturerExists($manufacturerId));

        return $this->buildChange(
            'id_manufacturer',
            (int) $product->id_manufacturer,
            $manufacturerId === null ? $proposed : $manufacturerId,
            $valid ? array() : array('Manufacturer does not exist.')
        );
    }

    private function buildCategoriesChange(Product $product, $proposed)
    {
        $categories = $this->normalizeCategories($proposed);
        $current = $this->getProductCategories((int) $product->id);

        return $this->buildChange(
            'categories',
            $current,
            $categories === null ? $proposed : $categories,
            $categories === null ? array('Categories must be a non-empty array of existing IDs.') : array()
        );
    }

    private function buildDefaultCategoryChange(Product $product, $proposed)
    {
        $categoryId = $this->normalizePositiveInteger($proposed);
        $valid = $categoryId !== null && Category::existsInDatabase($categoryId);

        return $this->buildChange(
            'id_category_default',
            (int) $product->id_category_default,
            $categoryId === null ? $proposed : $categoryId,
            $valid ? array() : array('Default category does not exist.')
        );
    }

    private function applyClassificationValidation(Product $product, array $payload, array $changes)
    {
        $categories = array_key_exists('categories', $payload)
            ? $this->normalizeCategories($payload['categories'])
            : $this->getProductCategories((int) $product->id);
        $defaultCategory = array_key_exists('id_category_default', $payload)
            ? $this->normalizePositiveInteger($payload['id_category_default'])
            : (int) $product->id_category_default;

        if ($categories !== null && $defaultCategory !== null
            && in_array($defaultCategory, $categories, true)) {
            return $changes;
        }

        foreach ($changes as &$change) {
            if ($change['field'] === 'id_category_default') {
                $change['validation']['ok'] = false;
                $change['validation']['errors'][] = 'Default category must belong to categories.';

                return $changes;
            }
        }
        unset($change);

        $changes[] = $this->buildChange(
            'id_category_default',
            (int) $product->id_category_default,
            $defaultCategory,
            array('Default category must belong to categories.')
        );

        return $changes;
    }

    private function normalizeCategories($value)
    {
        if (!is_array($value) || !$value) {
            return null;
        }

        $categories = array();

        foreach ($value as $categoryId) {
            $categoryId = $this->normalizePositiveInteger($categoryId);

            if ($categoryId === null || !Category::existsInDatabase($categoryId)) {
                return null;
            }

            $categories[] = $categoryId;
        }

        $categories = array_values(array_unique($categories));
        sort($categories, SORT_NUMERIC);

        return $categories ?: null;
    }

    private function getProductCategories($productId)
    {
        $categories = Product::getProductCategories($productId);

        if (!is_array($categories)) {
            return array();
        }

        $categories = array_values(array_unique(array_map('intval', $categories)));
        sort($categories, SORT_NUMERIC);

        return $categories;
    }

    private function manufacturerExists($manufacturerId)
    {
        $manufacturer = new Manufacturer((int) $manufacturerId);

        return Validate::isLoadedObject($manufacturer);
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
    private function buildTextChange(Product $product, $field, $proposed, $languageId)
    {
        $value = $this->normalizeText($proposed);
        $errors = $this->getTextValidationErrors($field, $value, $languageId);

        return $this->buildChange(
            $field,
            (string) $product->{$field},
            $value === null ? $proposed : $value,
            $errors
        );
    }
    private function buildPriceChange(Product $product, $proposed)
    {
        if (!is_numeric($proposed)) {
            return $this->buildChange(
                'price',
                (float) $product->price,
                $proposed,
                array('Value must be numeric.')
            );
        }

        return $this->buildChange(
            'price',
            (float) $product->price,
            (float) $proposed,
            array()
        );
    }

    private function buildBooleanChange(Product $product, $field, $proposed)
    {
        $value = $this->normalizeBoolean($proposed);

        if ($value === null) {
            return $this->buildChange(
                $field,
                (int) $product->{$field},
                $proposed,
                array('Value must be a boolean or 0 or 1.')
            );
        }

        return $this->buildChange(
            $field,
            (int) $product->{$field},
            $value,
            array()
        );
    }

    private function buildReferenceChange(Product $product, $proposed)
    {
        if (!is_string($proposed)) {
            return $this->buildChange(
                'reference',
                (string) $product->reference,
                $proposed,
                array('Value must be a string.')
            );
        }

        $errors = array();

        if (Tools::strlen($proposed) > 64) {
            $errors[] = 'Reference must not exceed 64 characters.';
        }

        if (!Validate::isReference($proposed)) {
            $errors[] = 'Invalid reference format.';
        }

        return $this->buildChange(
            'reference',
            (string) $product->reference,
            $proposed,
            $errors
        );
    }

    private function buildMinimalQuantityChange(Product $product, $proposed)
    {
        $value = $this->normalizeMinimalQuantity($proposed);

        if ($value === null) {
            return $this->buildChange(
                'minimal_quantity',
                (int) $product->minimal_quantity,
                $proposed,
                array('Value must be an integer greater than or equal to 1.')
            );
        }

        return $this->buildChange(
            'minimal_quantity',
            (int) $product->minimal_quantity,
            $value,
            array()
        );
    }

    private function buildIdentifierChange(Product $product, $field, $proposed)
    {
        $valid = is_string($proposed) && $this->isValidIdentifier($field, $proposed);

        return $this->buildChange(
            $field,
            (string) $product->{$field},
            $proposed,
            $valid ? array() : array('Invalid identifier format.')
        );
    }

    private function buildNonNegativeNumberChange(Product $product, $field, $proposed)
    {
        if (!is_numeric($proposed) || (float) $proposed < 0) {
            return $this->buildChange(
                $field,
                (float) $product->{$field},
                $proposed,
                array('Value must be numeric and greater than or equal to 0.')
            );
        }

        return $this->buildChange(
            $field,
            (float) $product->{$field},
            (float) $proposed,
            array()
        );
    }

    private function buildTaxRulesGroupChange(Product $product, $proposed)
    {
        $value = $this->normalizeNonNegativeInteger($proposed);
        $valid = $value !== null && ($value === 0 || $this->taxRulesGroupExists($value));

        return $this->buildChange(
            'id_tax_rules_group',
            (int) $product->id_tax_rules_group,
            $value === null ? $proposed : $value,
            $valid ? array() : array('Tax rules group does not exist.')
        );
    }

    private function buildConditionChange(Product $product, $proposed)
    {
        $valid = is_string($proposed)
            && in_array($proposed, array('new', 'used', 'refurbished'), true);

        return $this->buildChange(
            'condition',
            (string) $product->condition,
            $proposed,
            $valid ? array() : array('Invalid product condition.')
        );
    }

    private function buildOutOfStockChange(Product $product, $proposed)
    {
        $value = $this->normalizeOutOfStock($proposed);
        $shopId = $this->resolveShopId($product);
        $current = (int) StockAvailable::outOfStock(
            (int) $product->id,
            $shopId
        );

        return $this->buildChange(
            'out_of_stock',
            $current,
            $value === null ? $proposed : $value,
            $value === null ? array('Value must be 0, 1 or 2.') : array()
        );
    }

    private function canonicalizePayload(array $payload, Product $product)
    {
        foreach ($payload as $field => $value) {

            if ($field === 'stock') {
                $normalized = $this->getStockHandler()->normalize($value, $product);
                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }
                continue;
            }

            if ($field === 'images') {
                $normalized = $this->getImageHandler()->normalize($value, $product, $this->resolveShopId($product));
                $payload[$field] = $normalized === null
                    ? array('_invalid_canonical' => true, '_reason' => $this->getImageHandler()->diagnose($value, $product, $this->resolveShopId($product)))
                    : $normalized;
                continue;
            }

            if ($field === 'combinations') {
                $handler = is_array($value) && isset($value['create'])
                    ? $this->getCombinationCreateHandler()
                    : $this->getCombinationHandler();
                $normalized = $handler->normalize($value, $product, Context::getContext()->language->id);
                $payload[$field] = $normalized === null
                    ? array('_invalid_canonical' => true)
                    : $normalized;
                continue;
            }

            if ($field === 'combination_images') {
                $normalized = $this->getCombinationImageHandler()->normalize($value, $product, Context::getContext()->language->id);
                $payload[$field] = $normalized === null
                    ? array('_invalid_canonical' => true)
                    : $normalized;
                continue;
            }

            if ($field === 'features') {
                $normalized = $this->getFeatureHandler()->normalize($value);

                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }
                continue;
            }

            if ($field === 'tags') {
                $normalized = $this->getTagHandler()->normalize($value);

                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }
                continue;
            }

            if ($field === 'discount') {
                $normalized = $this->getDiscountHandler()->normalize($value);

                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }
                continue;
            }

            if ($field === 'id_manufacturer') {
                $normalized = $this->normalizeNonNegativeInteger($value);
                if ($normalized !== null) { $payload[$field] = $normalized; }
                continue;
            }

            if ($field === 'categories') {
                $normalized = $this->normalizeCategories($value);
                if ($normalized !== null) { $payload[$field] = $normalized; }
                continue;
            }

            if ($field === 'id_category_default') {
                $normalized = $this->normalizePositiveInteger($value);
                if ($normalized !== null) { $payload[$field] = $normalized; }
                continue;
            }
            if (in_array($field, self::TEXT_FIELDS, true)) {
                $normalized = $this->normalizeText($value);

                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }

                continue;
            }
            if ($field === 'price' && is_numeric($value)) {
                $payload[$field] = (float) $value;
                continue;
            }

            if (in_array($field, self::BOOLEAN_FIELDS, true)) {
                $normalized = $this->normalizeBoolean($value);

                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }

                continue;
            }

            if ($field === 'minimal_quantity') {
                $normalized = $this->normalizeMinimalQuantity($value);

                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }

                continue;
            }

            if (in_array($field, self::NON_NEGATIVE_NUMBER_FIELDS, true)
                && is_numeric($value) && (float) $value >= 0) {
                $payload[$field] = (float) $value;
                continue;
            }

            if ($field === 'id_tax_rules_group') {
                $normalized = $this->normalizeNonNegativeInteger($value);

                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }

                continue;
            }

            if ($field === 'out_of_stock') {
                $normalized = $this->normalizeOutOfStock($value);

                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }
            }
        }

        ksort($payload);

        return $payload;
    }

    private function normalizeText($value)
    {
        if (!is_string($value)) {
            return null;
        }

        return str_replace(array("\r\n", "\r"), "\n", $value);
    }

    private function getTextValidationErrors($field, $value, $languageId)
    {
        if ($value === null) {
            return array('Value must be a string.');
        }

        if (!$this->isActiveLanguage($languageId)) {
            return array('Language does not exist or is inactive.');
        }

        $errors = array();
        $size = $this->getProductFieldSize($field);
        $validator = $this->getProductFieldValidator($field);

        if ($size !== null && Tools::strlen($value) > $size) {
            $errors[] = 'Value exceeds the maximum allowed length.';
        }

        if (($field === 'name' || $field === 'link_rewrite') && trim($value) === '') {
            $errors[] = 'Value cannot be empty.';
        }

        if ($validator === null || !method_exists('Validate', $validator)) {
            $errors[] = 'Configured product validator is unavailable.';
        } elseif (!call_user_func(array('Validate', $validator), $value)) {
            $errors[] = 'Invalid field value.';
        }

        if (in_array($field, array('description', 'description_short'), true)
            && !$this->runValidateMethod('isCleanHtml', $value)) {
            $errors[] = 'Invalid HTML content.';
        }

        return array_values(array_unique($errors));
    }

    private function getProductFieldValidator($field)
    {
        if (!isset(Product::$definition['fields'][$field]['validate'])) {
            return null;
        }

        $validator = Product::$definition['fields'][$field]['validate'];

        return is_string($validator) && $validator !== '' ? $validator : null;
    }

    private function runValidateMethod($validator, $value)
    {
        return is_string($validator)
            && method_exists('Validate', $validator)
            && (bool) call_user_func(array('Validate', $validator), $value);
    }
    private function isActiveLanguage($languageId)
    {
        $language = new Language((int) $languageId);

        return Validate::isLoadedObject($language) && (bool) $language->active;
    }

    private function getProductFieldSize($field)
    {
        if (!isset(Product::$definition['fields'][$field]['size'])) {
            return null;
        }

        return (int) Product::$definition['fields'][$field]['size'];
    }
    private function isValidIdentifier($field, $value)
    {
        if ($value === '') {
            return true;
        }

        if ($field === 'ean13') {
            return Validate::isEan13($value);
        }

        if ($field === 'isbn') {
            return Validate::isIsbn($value);
        }

        return Validate::isUpc($value);
    }

    private function taxRulesGroupExists($id)
    {
        $taxRulesGroup = new TaxRulesGroup((int) $id);

        return Validate::isLoadedObject($taxRulesGroup);
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

    private function normalizeMinimalQuantity($value)
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeNonNegativeInteger($value)
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeOutOfStock($value)
    {
        if (is_bool($value)) {
            return null;
        }

        if (is_int($value) && in_array($value, array(0, 1, 2), true)) {
            return $value;
        }

        if (is_string($value) && in_array($value, array('0', '1', '2'), true)) {
            return (int) $value;
        }

        return null;
    }

    private function resolveShopId(Product $product)
    {
        $shopId = (int) Context::getContext()->shop->id;

        if ($shopId <= 0) {
            $shopId = (int) $product->id_shop_default;
        }

        return $shopId;
    }
    private function getCombinationCreateHandler()
    {
        if ($this->combinationCreateHandler === null) $this->combinationCreateHandler = new AiBridgeCombinationCreateHandler();
        return $this->combinationCreateHandler;
    }

    private function getCombinationHandler()
    {
        if ($this->combinationHandler === null) $this->combinationHandler = new AiBridgeCombinationHandler();
        return $this->combinationHandler;
    }

    private function getCombinationImageHandler()
    {
        if ($this->combinationImageHandler === null) $this->combinationImageHandler = new AiBridgeCombinationImageHandler();
        return $this->combinationImageHandler;
    }

    private function getImageHandler()
    {
        if ($this->imageHandler === null) {
            $this->imageHandler = new AiBridgeImageHandler();
        }

        return $this->imageHandler;
    }

    private function getStockHandler()
    {
        if ($this->stockHandler === null) {
            $this->stockHandler = new AiBridgeStockHandler();
        }

        return $this->stockHandler;
    }

    private function getFeatureHandler()
    {
        if ($this->featureHandler === null) {
            $this->featureHandler = new AiBridgeFeatureHandler();
        }

        return $this->featureHandler;
    }
    private function getTagHandler()
    {
        if ($this->tagHandler === null) {
            $this->tagHandler = new AiBridgeTagHandler();
        }

        return $this->tagHandler;
    }
    private function getDiscountHandler()
    {
        if ($this->discountHandler === null) {
            $this->discountHandler = new AiBridgeDiscountHandler();
        }

        return $this->discountHandler;
    }
    private function buildChange($field, $current, $proposed, array $errors)
    {
        return array(
            'field' => $field,
            'current' => $current,
            'proposed' => $proposed,
            'changed' => $current !== $proposed,
            'validation' => array(
                'ok' => count($errors) === 0,
                'errors' => $errors,
            ),
        );
    }
}
