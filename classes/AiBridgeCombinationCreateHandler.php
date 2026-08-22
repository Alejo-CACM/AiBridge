<?php

class AiBridgeCombinationCreateHandler
{
    private const OPTIONAL_FIELDS = array('reference', 'ean13', 'isbn', 'upc', 'price_impact', 'wholesale_price', 'weight_impact', 'minimal_quantity', 'available_date');
    private const MAX_CREATE_PER_CALL = 30;

    public function normalize($value, Product $product, $languageId)
    {
        if (!is_array($value) || count($value) !== 1 || !isset($value['create']) || !is_array($value['create'])
            || !$value['create'] || count($value['create']) > self::MAX_CREATE_PER_CALL) {
            return null;
        }
        if (!$this->hasStableDefault($product)) return null;

        $entries = array();
        $seenAttributeSets = array();
        foreach ($value['create'] as $rawEntry) {
            if (!is_array($rawEntry) || !array_key_exists('id_attributes', $rawEntry)) return null;
            foreach (array_keys($rawEntry) as $field) {
                if ($field !== 'id_attributes' && !in_array($field, self::OPTIONAL_FIELDS, true)) return null;
            }

            $attributes = $this->normalizeAttributes($rawEntry['id_attributes'], $languageId);
            if ($attributes === null) return null;
            $ids = array_column($attributes, 'id_attribute');
            $key = implode(',', $ids);
            if (isset($seenAttributeSets[$key])) return null;
            $seenAttributeSets[$key] = true;
            if ($product->productAttributeExists($ids, false, Context::getContext(), false, true)) return null;

            $normalized = array('id_attributes' => $ids, 'reference' => '', 'ean13' => '', 'isbn' => '', 'upc' => '', 'price_impact' => 0.0, 'wholesale_price' => 0.0, 'weight_impact' => 0.0, 'minimal_quantity' => 1, 'available_date' => null);
            foreach (self::OPTIONAL_FIELDS as $field) {
                if (!array_key_exists($field, $rawEntry)) continue;
                $input = $rawEntry[$field];
                if (in_array($field, array('price_impact', 'wholesale_price', 'weight_impact'), true)) {
                    if (!is_numeric($input)) return null;
                    $normalized[$field] = (float) $input;
                    continue;
                }
                if ($field === 'minimal_quantity') {
                    if (is_bool($input) || !(is_int($input) || (is_string($input) && ctype_digit($input))) || (int) $input < 1) return null;
                    $normalized[$field] = (int) $input;
                    continue;
                }
                if ($field === 'available_date') {
                    if ($input !== null && ($input !== '' && (!is_string($input) || !Validate::isDateFormat($input)))) return null;
                    $normalized[$field] = $input === '' ? null : $input;
                    continue;
                }
                if (!is_string($input)) return null;
                if (($field === 'reference' && (Tools::strlen($input) > 64 || !Validate::isReference($input))) || ($field === 'ean13' && !($input === '' || Validate::isEan13($input))) || ($field === 'isbn' && !($input === '' || Validate::isIsbn($input))) || ($field === 'upc' && !($input === '' || Validate::isUpc($input)))) return null;
                $normalized[$field] = $input;
            }
            $entries[] = $normalized;
        }

        if (empty($entries)) return null;
        $referenceCounts = array_count_values(array_filter(array_column($entries, 'reference'), function ($reference) { return $reference !== ''; }));
        foreach ($referenceCounts as $count) {
            if ($count > 1) return null;
        }

        return array('create' => $entries);
    }

    public function buildChange(Product $product, $value, $languageId)
    {
        $normalized = $this->normalize($value, $product, $languageId);
        if ($normalized === null) return $this->change(array('create' => array()), is_array($value) ? $value : array(), false, array('Invalid combination create payload.'));
        $proposed = array();
        foreach ($normalized['create'] as $entry) {
            $item = $entry;
            $item['attributes'] = $this->describeAttributes($entry['id_attributes'], $languageId);
            unset($item['id_attributes']);
            $proposed[] = $item;
        }
        return $this->change(array('create' => array()), array('create' => $proposed), true, array());
    }

    public function capture(Product $product, array $operations, $languageId, $shopId)
    {
        $normalized = $this->normalize($operations, $product, $languageId);
        if ($normalized === null) throw new Exception('Combination create conflict.');
        return array('operation' => $normalized, 'created_ids' => array(), 'existing' => $this->combinationState($product), 'shop_id' => (int) $shopId);
    }

    public function apply(Product $product, array $operations, $languageId, array &$snapshot)
    {
        $normalized = $this->normalize($operations, $product, $languageId);
        if ($normalized === null || !isset($snapshot['operation']['create'])) return false;
        $isFirstBatch = empty($snapshot['existing']);
        $snapshot['created_ids'] = array();

        foreach ($normalized['create'] as $index => $entry) {
            $combination = new Combination();
            $combination->id_product = (int) $product->id;
            $combination->id_shop_list = array((int) $snapshot['shop_id']);
            $combination->reference = $entry['reference'];
            $combination->ean13 = $entry['ean13'];
            $combination->isbn = $entry['isbn'];
            $combination->upc = $entry['upc'];
            $combination->price = $entry['price_impact'];
            $combination->wholesale_price = $entry['wholesale_price'];
            $combination->weight = $entry['weight_impact'];
            $combination->minimal_quantity = $entry['minimal_quantity'];
            $combination->available_date = $entry['available_date'] === null ? '0000-00-00' : $entry['available_date'];
            $combination->default_on = false;
            if (!$combination->add()) return false;
            $snapshot['created_ids'][] = (int) $combination->id;
            if (!$combination->setAttributes($entry['id_attributes'])) return false;

            if ($isFirstBatch && $index === 0 && !$product->setDefaultAttribute($combination->id)) return false;
        }

        return true;
    }

    public function verify(Product $product, array $snapshot)
    {
        if (empty($snapshot['created_ids']) || empty($snapshot['operation']['create'])) return false;
        $entries = $snapshot['operation']['create'];
        if (count($entries) !== count($snapshot['created_ids'])) return false;
        $isFirstBatch = empty($snapshot['existing']);

        foreach ($snapshot['created_ids'] as $index => $createdId) {
            $entry = $entries[$index];
            $expectDefault = $isFirstBatch && $index === 0;
            $combination = new Combination((int) $createdId);
            if (!Validate::isLoadedObject($combination) || (int) $combination->id_product !== (int) $product->id
                || (bool) $combination->default_on !== $expectDefault) return false;
            if ($this->attributeIds($combination) !== $entry['id_attributes'] || $this->imageIds($combination) !== array()) return false;
            if ((string) $combination->reference !== $entry['reference'] || (string) $combination->ean13 !== $entry['ean13'] || (string) $combination->isbn !== $entry['isbn'] || (string) $combination->upc !== $entry['upc'] || (float) $combination->price !== (float) $entry['price_impact'] || (float) $combination->wholesale_price !== (float) $entry['wholesale_price'] || (float) $combination->weight !== (float) $entry['weight_impact'] || (int) $combination->minimal_quantity !== (int) $entry['minimal_quantity'] || (string) $combination->available_date !== ($entry['available_date'] === null ? '0000-00-00' : $entry['available_date'])) return false;
            if ((int) StockAvailable::getQuantityAvailableByProduct((int) $product->id, (int) $createdId, (int) $snapshot['shop_id']) !== 0) return false;
        }

        return $this->combinationState($product) === $this->expectedState($snapshot['existing'], $snapshot['created_ids'], $isFirstBatch);
    }

    public function rollback(Product $product, array $snapshot)
    {
        if (empty($snapshot['created_ids'])) return true;
        foreach ($snapshot['created_ids'] as $createdId) {
            $combination = new Combination((int) $createdId);
            if (Validate::isLoadedObject($combination) && (int) $combination->id_product === (int) $product->id && !$combination->delete()) return false;
            $check = new Combination((int) $createdId);
            if (Validate::isLoadedObject($check)) return false;
        }

        return $this->combinationState($product) === $snapshot['existing'];
    }

    private function combinationState(Product $product)
    {
        $state = array();
        foreach ((array) Product::getProductAttributesIds((int) $product->id) as $row) {
            $id = isset($row['id_product_attribute']) ? (int) $row['id_product_attribute'] : 0;
            if ($id <= 0) continue;
            $combination = new Combination($id);
            if (Validate::isLoadedObject($combination)) $state[$id] = !empty($combination->default_on);
        }
        ksort($state, SORT_NUMERIC);
        return $state;
    }

    private function expectedState(array $existing, array $createdIds, $firstBatch)
    {
        $expected = $existing;
        foreach ($createdIds as $index => $createdId) {
            $expected[(int) $createdId] = $firstBatch && $index === 0;
        }
        ksort($expected, SORT_NUMERIC);
        return $expected;
    }

    /**
     * True when the product either has no combinations yet (the first entry
     * of the batch will become the default) or already has exactly one
     * stable default combination.
     */
    private function hasStableDefault(Product $product)
    {
        $state = $this->combinationState($product);

        return empty($state) || count(array_filter($state)) === 1;
    }

    private function attributeIds(Combination $combination)
    {
        $rows = $combination->getWsProductOptionValues();
        $ids = array();
        if (is_array($rows)) foreach ($rows as $row) if (isset($row['id'])) $ids[] = (int) $row['id'];
        sort($ids, SORT_NUMERIC);
        return array_values(array_unique($ids));
    }

    private function imageIds(Combination $combination)
    {
        $rows = $combination->getWsImages();
        $ids = array();
        if (is_array($rows)) foreach ($rows as $row) if (isset($row['id'])) $ids[] = (int) $row['id'];
        sort($ids, SORT_NUMERIC);
        return array_values(array_unique($ids));
    }

    private function normalizeAttributes($value, $languageId)
    {
        if (!is_array($value) || !$value) return null;
        $map = $this->attributeMap($languageId); $selected = array(); $groups = array();
        foreach ($value as $id) {
            $id = $this->positiveInteger($id);
            if ($id === null || !isset($map[$id]) || isset($selected[$id]) || isset($groups[$map[$id]['id_attribute_group']])) return null;
            $selected[$id] = $map[$id]; $groups[$map[$id]['id_attribute_group']] = true;
        }
        usort($selected, function ($left, $right) { return $left['id_attribute_group'] === $right['id_attribute_group'] ? $left['id_attribute'] <=> $right['id_attribute'] : $left['id_attribute_group'] <=> $right['id_attribute_group']; });
        return array_values($selected);
    }

    private function describeAttributes(array $ids, $languageId)
    {
        $map = $this->attributeMap($languageId); $attributes = array();
        foreach ($ids as $id) { if (!isset($map[$id])) return array(); $attributes[] = $map[$id]; }
        usort($attributes, function ($left, $right) { return $left['id_attribute_group'] === $right['id_attribute_group'] ? $left['id_attribute'] <=> $right['id_attribute'] : $left['id_attribute_group'] <=> $right['id_attribute_group']; });
        return $attributes;
    }

    private function attributeMap($languageId)
    {
        $map = array(); $groups = AttributeGroup::getAttributesGroups((int) $languageId);
        if (!is_array($groups)) return $map;
        foreach ($groups as $group) {
            if (!isset($group['id_attribute_group'])) continue;
            $groupId = (int) $group['id_attribute_group']; $attributes = AttributeGroup::getAttributes((int) $languageId, $groupId);
            if (!is_array($attributes)) continue;
            foreach ($attributes as $attribute) if (isset($attribute['id_attribute'])) $map[(int) $attribute['id_attribute']] = array('id_attribute_group' => $groupId, 'group_name' => isset($group['name']) ? (string) $group['name'] : '', 'id_attribute' => (int) $attribute['id_attribute'], 'attribute_name' => isset($attribute['name']) ? (string) $attribute['name'] : '');
        }
        return $map;
    }

    private function positiveInteger($value)
    {
        if (is_int($value) && $value > 0) return $value;
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) return (int) $value;
        return null;
    }

    private function change($current, $proposed, $changed, array $errors)
    {
        return array('field' => 'combinations', 'current' => $current, 'proposed' => $proposed, 'changed' => (bool) $changed, 'validation' => array('ok' => !$errors, 'errors' => $errors));
    }
}
