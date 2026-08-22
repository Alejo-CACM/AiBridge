<?php

require_once dirname(__FILE__) . '/AiBridgeTagHandler.php';
require_once dirname(__FILE__) . '/AiBridgeClassificationHandler.php';
require_once dirname(__FILE__) . '/AiBridgeImageHandler.php';
require_once dirname(__FILE__) . '/AiBridgeFeatureHandler.php';
require_once dirname(__FILE__) . '/AiBridgeStockHandler.php';
require_once dirname(__FILE__) . '/AiBridgeCombinationCreateHandler.php';

class AiBridgeProductCreatePreview
{
    private const ALLOWED_FIELDS = array(
        'shop_id',
        'language_id',
        'name',
        'link_rewrite',
        'price',
        'id_tax_rules_group',
        'reference',
        'id_category_default',
        'categories',
        'active',
        'tags',
        'id_manufacturer',
        'description',
        'description_short',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'images',
        'features',
        'stock',
        'combinations',
    );

    private const OPTIONAL_TEXT_FIELDS = array(
        'description',
        'description_short',
        'meta_title',
        'meta_description',
        'meta_keywords',
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

        $canonical['name'] = $this->normalizeTranslations(
            $payload['name'] ?? null,
            $languageId,
            $shopId,
            'name',
            true,
            $errors
        );
        $canonical['link_rewrite'] = $this->normalizeTranslations(
            $payload['link_rewrite'] ?? null,
            $languageId,
            $shopId,
            'link_rewrite',
            true,
            $errors
        );

        $price = $this->nonNegativeNumber($payload['price'] ?? null);
        if ($price === null) {
            $errors[] = 'Invalid price.';
        } else {
            $canonical['price'] = $price;
        }

        $taxRulesGroupId = $this->nonNegativeInteger($payload['id_tax_rules_group'] ?? null);
        if ($taxRulesGroupId === null || !$this->taxRulesGroupExists($taxRulesGroupId)) {
            $errors[] = 'Invalid tax rules group.';
        } else {
            $canonical['id_tax_rules_group'] = $taxRulesGroupId;
        }

        $reference = $this->normalizeReference($payload['reference'] ?? null);
        if ($reference === null) {
            $errors[] = 'Invalid reference.';
        } else {
            $canonical['reference'] = $reference;
        }

        $categories = $this->normalizeCategories($payload['categories'] ?? null, $languageId, $shopId);
        if ($categories === null) {
            $errors[] = 'Invalid categories.';
        } else {
            $canonical['categories'] = $categories;
        }

        $defaultCategoryId = $this->positiveInteger($payload['id_category_default'] ?? null);
        if ($defaultCategoryId === null || $categories === null || !in_array($defaultCategoryId, $categories, true)) {
            $errors[] = 'Invalid default category.';
        } else {
            $canonical['id_category_default'] = $defaultCategoryId;
        }

        if (!array_key_exists('active', $payload) || $payload['active'] !== false) {
            $errors[] = 'New products must be inactive.';
        } else {
            $canonical['active'] = false;
        }

        if (array_key_exists('tags', $payload)) {
            $normalizedTags = (new AiBridgeTagHandler())->normalize($payload['tags']);
            if ($normalizedTags === null) {
                $errors[] = 'Invalid tags.';
            } else {
                $canonical['tags'] = $normalizedTags;
            }
        }

        foreach (self::OPTIONAL_TEXT_FIELDS as $textField) {
            $canonical[$textField] = $this->normalizeTranslations(
                $payload[$textField] ?? null,
                $languageId,
                $shopId,
                $textField,
                false,
                $errors
            );
        }

        $manufacturerId = array_key_exists('id_manufacturer', $payload)
            ? (new AiBridgeClassificationHandler())->validateField('id_manufacturer', $payload['id_manufacturer'])
            : 0;
        if ($manufacturerId === null) {
            $errors[] = 'Invalid manufacturer.';
        } else {
            $canonical['id_manufacturer'] = $manufacturerId;
        }

        if (array_key_exists('images', $payload)) {
            $stubProduct = new Product();
            $imageHandler = new AiBridgeImageHandler();
            $normalizedImages = $imageHandler->normalize($payload['images'], $stubProduct, $shopId);
            if ($normalizedImages === null) {
                // Re-validating an already-canonicalized payload (executor path): normalize()
                // expects a raw upload_token, the stored canonical form has id_aibridge_upload instead.
                $normalizedImages = $imageHandler->normalizeCanonical($payload['images'], $stubProduct, $shopId);
            }
            if ($normalizedImages === null || !isset($normalizedImages['add']) || isset($normalizedImages['update'])) {
                $errors[] = 'Invalid images.';
            } else {
                $canonical['images'] = $normalizedImages;
            }
        }

        if (array_key_exists('features', $payload)) {
            $normalizedFeatures = (new AiBridgeFeatureHandler())->normalize($payload['features']);
            if ($normalizedFeatures === null) {
                $errors[] = 'Invalid features.';
            } else {
                $canonical['features'] = $normalizedFeatures;
            }
        }

        if (array_key_exists('stock', $payload)) {
            $normalizedStock = (new AiBridgeStockHandler())->normalize($payload['stock'], new Product());
            if ($normalizedStock === null || !isset($normalizedStock['simple_quantity'])) {
                $errors[] = 'Invalid stock.';
            } else {
                $canonical['stock'] = $normalizedStock;
            }
        }

        if (array_key_exists('combinations', $payload)) {
            $normalizedCombinations = (new AiBridgeCombinationCreateHandler())->normalize(
                $payload['combinations'],
                new Product(),
                $languageId
            );
            if ($normalizedCombinations === null) {
                $errors[] = 'Invalid combinations.';
            } else {
                $canonical['combinations'] = $normalizedCombinations;
            }
        }

        if (empty($errors) && $reference !== '' && (new Product())->existsRefInDatabase($reference)) {
            $errors[] = 'Reference already exists.';
            $conflict = true;
        }

        if (empty($errors) && $this->hasDuplicateEffectiveLinkRewrite(
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
            'field' => 'product_create',
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
            'product_date_upd_snapshot' => null,
            'changes' => array($change),
        );
    }

    private function normalizeTranslations($values, $effectiveLanguageId, $shopId, $field, $required, array &$errors)
    {
        if (!$required && $values === null) {
            return array();
        }

        if (!is_array($values)) {
            $errors[] = 'Invalid ' . $field . '.';

            return array();
        }

        $normalized = array();
        $size = Product::$definition['fields'][$field]['size'] ?? null;
        $validator = Product::$definition['fields'][$field]['validate'] ?? null;
        foreach ($values as $languageId => $value) {
            $languageId = $this->positiveInteger($languageId);
            if ($languageId === null || !$this->isActiveShopLanguage($languageId, $shopId) || !is_string($value)) {
                $errors[] = 'Invalid ' . $field . '.';
                continue;
            }

            $value = str_replace(array("\r\n", "\r"), "\n", $value);
            if (($required && trim($value) === '')
                || ($size !== null && Tools::strlen($value) > (int) $size)
                || (is_string($validator) && $validator !== '' && method_exists('Validate', $validator)
                    && trim($value) !== '' && !call_user_func(array('Validate', $validator), $value))
                || (in_array($field, array('description', 'description_short'), true)
                    && method_exists('Validate', 'isCleanHtml') && !Validate::isCleanHtml($value))) {
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

    private function normalizeReference($value)
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            return null;
        }

        $size = Product::$definition['fields']['reference']['size'] ?? 64;

        return Tools::strlen($value) <= (int) $size && Validate::isReference($value)
            ? $value
            : null;
    }

    private function normalizeCategories($categories, $languageId, $shopId)
    {
        if (!is_array($categories) || empty($categories)) {
            return null;
        }

        $normalized = array();
        foreach ($categories as $categoryId) {
            $categoryId = $this->positiveInteger($categoryId);
            if ($categoryId === null || isset($normalized[$categoryId])) {
                return null;
            }

            $category = new Category($categoryId, $languageId, $shopId);
            if (!Validate::isLoadedObject($category) || !(bool) $category->active) {
                return null;
            }

            $normalized[$categoryId] = $categoryId;
        }

        $normalized = array_values($normalized);
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function hasDuplicateEffectiveLinkRewrite($linkRewrite, $languageId, $shopId)
    {
        $context = Context::getContext();
        $previousShopId = isset($context->shop) ? (int) $context->shop->id : 0;

        try {
            Shop::setContext(Shop::CONTEXT_SHOP, (int) $shopId);
            $products = Product::getProducts(
                (int) $languageId,
                0,
                0,
                'id_product',
                'ASC',
                false,
                false,
                Context::getContext()
            );

            if (!is_array($products)) {
                return true;
            }

            foreach ($products as $product) {
                if (isset($product['link_rewrite']) && (string) $product['link_rewrite'] === $linkRewrite) {
                    return true;
                }
            }

            return false;
        } finally {
            if ($previousShopId > 0) {
                Shop::setContext(Shop::CONTEXT_SHOP, $previousShopId);
            }
        }
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

    private function taxRulesGroupExists($taxRulesGroupId)
    {
        if ((int) $taxRulesGroupId <= 0) {
            return false;
        }

        $taxRulesGroup = new TaxRulesGroup((int) $taxRulesGroupId);

        return Validate::isLoadedObject($taxRulesGroup);
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

    private function nonNegativeNumber($value)
    {
        if (is_bool($value) || !is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return (float) $value;
    }

    private function canonicalize(array $payload)
    {
        ksort($payload, SORT_STRING);

        return $payload;
    }
}