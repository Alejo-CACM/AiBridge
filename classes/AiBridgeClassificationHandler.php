<?php

class AiBridgeClassificationHandler
{
    public function isClassificationField($field)
    {
        return in_array($field, array(
            'id_manufacturer',
            'categories',
            'id_category_default',
        ), true);
    }

    public function validateField($field, $value)
    {
        if ($field === 'id_manufacturer') {
            $manufacturerId = $this->normalizeNonNegativeInteger($value);

            return $manufacturerId !== null
                && ($manufacturerId === 0 || $this->manufacturerExists($manufacturerId))
                ? $manufacturerId
                : null;
        }

        if ($field === 'categories') {
            return $this->normalizeCategories($value);
        }

        if ($field === 'id_category_default') {
            $categoryId = $this->normalizePositiveInteger($value);

            return $categoryId !== null && Category::existsInDatabase($categoryId)
                ? $categoryId
                : null;
        }

        return null;
    }

    public function canonicalizeField($field, $value)
    {
        return $this->validateField($field, $value);
    }

    public function isTouched(array $fields, $categories)
    {
        return $categories !== null
            || array_key_exists('id_manufacturer', $fields)
            || array_key_exists('id_category_default', $fields);
    }

    public function capture(Product $product)
    {
        return array(
            'id_manufacturer' => (int) $product->id_manufacturer,
            'id_category_default' => (int) $product->id_category_default,
            'categories' => $this->getProductCategories((int) $product->id),
        );
    }

    public function assertValidTarget(array $fields, $categories, array $snapshot)
    {
        $targetCategories = $categories === null
            ? $snapshot['categories']
            : $categories;
        $targetDefaultCategory = array_key_exists('id_category_default', $fields)
            ? (int) $fields['id_category_default']
            : $snapshot['id_category_default'];

        if (!in_array($targetDefaultCategory, $targetCategories, true)) {
            throw new Exception('Default category must belong to categories.');
        }
    }

    public function applyProductFields(Product $product, array $fields)
    {
        if (array_key_exists('id_manufacturer', $fields)) {
            $product->id_manufacturer = (int) $fields['id_manufacturer'];
        }

        if (array_key_exists('id_category_default', $fields)) {
            $product->id_category_default = (int) $fields['id_category_default'];
        }
    }

    public function applyCategories(Product $product, $categories)
    {
        return $categories === null || $product->updateCategories($categories);
    }

    public function verify(AiBridgeApprovalRequest $request, array $fields, $categories)
    {
        $product = new Product(
            (int) $request->product_id,
            false,
            null,
            (int) $request->shop_id
        );

        if (!Validate::isLoadedObject($product)) {
            return false;
        }

        if (array_key_exists('id_manufacturer', $fields)
            && (int) $product->id_manufacturer !== (int) $fields['id_manufacturer']) {
            return false;
        }

        if (array_key_exists('id_category_default', $fields)
            && (int) $product->id_category_default !== (int) $fields['id_category_default']) {
            return false;
        }

        return $categories === null
            || $this->getProductCategories((int) $product->id) === $categories;
    }

    public function restore(AiBridgeApprovalRequest $request, array $snapshot)
    {
        $product = new Product(
            (int) $request->product_id,
            false,
            null,
            (int) $request->shop_id
        );

        if (!Validate::isLoadedObject($product)) {
            return false;
        }

        $product->id_manufacturer = (int) $snapshot['id_manufacturer'];
        $product->id_category_default = (int) $snapshot['id_category_default'];

        if (!$product->update() || !$product->updateCategories($snapshot['categories'])) {
            return false;
        }

        return $this->verify(
            $request,
            array(
                'id_manufacturer' => $snapshot['id_manufacturer'],
                'id_category_default' => $snapshot['id_category_default'],
            ),
            $snapshot['categories']
        );
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

    private function manufacturerExists($manufacturerId)
    {
        $manufacturer = new Manufacturer((int) $manufacturerId);

        return Validate::isLoadedObject($manufacturer);
    }
}