<?php

class AiBridgeCombinationImageHandler
{
    public function normalize($value, Product $product, $languageId)
    {
        if (!is_array($value) || count($value) !== 1 || !isset($value['update'])
            || !is_array($value['update']) || count($value['update']) !== 1
            || !isset($value['update'][0]) || !is_array($value['update'][0])) {
            return null;
        }

        $entry = $value['update'][0];
        if (count($entry) !== 2 || !array_key_exists('id_product_attribute', $entry)
            || !array_key_exists('id_images', $entry)) {
            return null;
        }

        $combinationId = $this->positiveInteger($entry['id_product_attribute']);
        if ($combinationId === null || !is_array($entry['id_images'])) {
            return null;
        }

        $shopId = $this->resolveShopId($product);
        $combination = $this->loadCombination($combinationId, $shopId);
        if (!Validate::isLoadedObject($combination)
            || (int) $combination->id_product !== (int) $product->id) {
            return null;
        }

        $imageIds = array();
        foreach ($entry['id_images'] as $imageId) {
            $imageId = $this->positiveInteger($imageId);
            $image = $imageId === null ? null : $this->loadImage($imageId, $shopId);
            if ($imageId === null || !Validate::isLoadedObject($image)
                || (int) $image->id_product !== (int) $product->id) {
                return null;
            }
            $imageIds[$imageId] = true;
        }

        $imageIds = array_keys($imageIds);
        sort($imageIds, SORT_NUMERIC);

        return array('update' => array(array(
            'id_product_attribute' => $combinationId,
            'id_images' => $imageIds,
        )));
    }

    public function buildChange(Product $product, $value, $languageId)
    {
        $normalized = $this->normalize($value, $product, $languageId);
        if ($normalized === null) {
            return $this->change(array(), is_array($value) ? $value : array(), false, array('Invalid combination image update.'));
        }

        $entry = $normalized['update'][0];
        $current = array('update' => array(array(
            'id_product_attribute' => (int) $entry['id_product_attribute'],
            'id_images' => $this->readImages((int) $entry['id_product_attribute']),
        )));
        $proposed = $normalized;

        return $this->change($current, $proposed, $current !== $proposed, array());
    }

    public function capture(Product $product, array $updates, $languageId)
    {
        $normalized = $this->normalize($updates, $product, $languageId);
        if ($normalized === null) {
            throw new Exception('Combination image update failed.');
        }

        $entry = $normalized['update'][0];
        return array(
            'id_product_attribute' => (int) $entry['id_product_attribute'],
            'id_images' => $this->readImages((int) $entry['id_product_attribute']),
        );
    }

    public function apply(Product $product, array $updates, $languageId)
    {
        $normalized = $this->normalize($updates, $product, $languageId);
        if ($normalized === null) {
            return false;
        }

        $entry = $normalized['update'][0];
        $combination = $this->loadCombination((int) $entry['id_product_attribute'], $this->resolveShopId($product));
        return Validate::isLoadedObject($combination)
            && (int) $combination->id_product === (int) $product->id
            && $combination->setImages($entry['id_images']);
    }

    public function verify(Product $product, array $updates, $languageId)
    {
        $normalized = $this->normalize($updates, $product, $languageId);
        if ($normalized === null) {
            return false;
        }

        $entry = $normalized['update'][0];
        return $this->readImages((int) $entry['id_product_attribute']) === $entry['id_images'];
    }

    public function restore(Product $product, array $snapshot, $languageId)
    {
        if (!isset($snapshot['id_product_attribute'], $snapshot['id_images'])
            || !is_array($snapshot['id_images'])) {
            return false;
        }

        $combination = $this->loadCombination((int) $snapshot['id_product_attribute'], $this->resolveShopId($product));
        if (!Validate::isLoadedObject($combination)
            || (int) $combination->id_product !== (int) $product->id
            || !$combination->setImages($snapshot['id_images'])) {
            return false;
        }

        return $this->readImages((int) $snapshot['id_product_attribute']) === $snapshot['id_images'];
    }

    private function loadCombination($id, $shopId)
    {
        $combination = new Combination((int) $id, null, (int) $shopId);
        return Validate::isLoadedObject($combination) ? $combination : new Combination((int) $id);
    }

    private function loadImage($id, $shopId)
    {
        $image = new Image((int) $id, null, (int) $shopId);
        return Validate::isLoadedObject($image) ? $image : new Image((int) $id);
    }

    private function resolveShopId(Product $product)
    {
        $shopId = (int) Context::getContext()->shop->id;
        return $shopId > 0 ? $shopId : (int) $product->id_shop_default;
    }

    private function readImages($combinationId)
    {
        $combination = new Combination((int) $combinationId);
        if (!Validate::isLoadedObject($combination)) {
            return array();
        }

        $rows = $combination->getWsImages();
        $ids = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (isset($row['id'])) {
                    $ids[] = (int) $row['id'];
                }
            }
        }
        sort($ids, SORT_NUMERIC);
        return array_values(array_unique($ids));
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

    private function change($current, $proposed, $changed, array $errors)
    {
        return array(
            'field' => 'combination_images',
            'current' => $current,
            'proposed' => $proposed,
            'changed' => (bool) $changed,
            'validation' => array('ok' => !$errors, 'errors' => $errors),
        );
    }
}