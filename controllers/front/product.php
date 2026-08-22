<?php

require_once dirname(__FILE__) . '/../../classes/AiBridgeDiscountHandler.php';

class AibridgeProductModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        try {
            if (!is_object($this->module)
                || !method_exists($this->module, 'isValidApiToken')) {
                throw new \RuntimeException('Invalid module context.');
            }

            if (!$this->module->isValidApiToken()) {
                $this->sendJson(401, array(
                    'success' => false,
                    'error' => array(
                        'code' => 'unauthorized',
                        'message' => 'Invalid or missing API token.',
                    ),
                ));
            }

            $productId = (int) Tools::getValue('id', 0);
            $languageId = (int) $this->context->language->id;
            $shopId = (int) $this->context->shop->id;
            $product = new Product($productId, true, $languageId, $shopId);

            if (!Validate::isLoadedObject($product)) {
                $this->sendJson(404, array(
                    'success' => false,
                    'error' => array(
                        'code' => 'product_not_found',
                        'message' => 'Product not found.',
                    ),
                ));
            }

            $this->sendJson(200, array(
                'success' => true,
                'data' => array(
                    'language_id' => $languageId,
                    'shop_id' => $shopId,
                    'product' => array(
                        'id' => (int) $product->id,
                        'reference' => (string) $product->reference,
                        'name' => (string) $product->name,
                        'description_short' => (string) $product->description_short,
                        'description' => (string) $product->description,
                        'price_tax_excl' => (float) $product->price,
                        'discount' => $this->getDiscount($product),
                        'id_manufacturer' => (int) $product->id_manufacturer,
                        'categories' => $this->getCategoryIds((int) $product->id),
                        'id_category_default' => (int) $product->id_category_default,
                        'features' => $this->getFeatures(
                            $languageId,
                            (int) $product->id
                        ),
                        'meta_title' => (string) $product->meta_title,
                        'meta_description' => (string) $product->meta_description,
                        'link_rewrite' => (string) $product->link_rewrite,
                        'active' => (int) $product->active,
                        'quantity' => (int) StockAvailable::getQuantityAvailableByProduct(
                            (int) $product->id,
                            null,
                            $shopId
                        ),
                        'combinations' => $this->getCombinations(
                            $product,
                            $languageId,
                            $shopId
                        ),
                        'images' => $this->getImages($product, $languageId),
                    ),
                ),
            ));
        } catch (\Throwable $exception) {
            $this->sendJson(500, array(
                'success' => false,
                'error' => array(
                    'code' => 'product_internal_error',
                    'message' => 'Product could not be loaded.',
                ),
            ));
        }
    }

    private function getDiscount(Product $product)
    {
        $discount = (new AiBridgeDiscountHandler())->read($product);
        if ($discount === null) {
            return null;
        }

        $price = (float) $product->price;
        $discountedPrice = $discount['reduction_type'] === 'percentage'
            ? round($price * (1 - $discount['reduction']), 6)
            : max(0, round($price - $discount['reduction'], 6));

        return array(
            'reduction_type' => $discount['reduction_type'],
            'reduction' => $discount['reduction'],
            'from_quantity' => $discount['from_quantity'],
            'from' => $discount['from'] === '0000-00-00 00:00:00' ? null : $discount['from'],
            'to' => $discount['to'] === '0000-00-00 00:00:00' ? null : $discount['to'],
            'price_tax_excl_after_discount' => $discountedPrice,
        );
    }

    private function getCategoryIds($productId)
    {
        $categories = Product::getProductCategories($productId);

        if (!is_array($categories)) {
            return array();
        }

        return array_values(array_map('intval', $categories));
    }

    private function getFeatures($languageId, $productId)
    {
        $features = Product::getFrontFeaturesStatic($languageId, $productId);

        if (!is_array($features)) {
            return array();
        }

        $formattedFeatures = array();

        foreach ($features as $feature) {
            $formattedFeatures[] = array(
                'id_feature' => (int) $feature['id_feature'],
                'value' => (string) $feature['value'],
            );
        }

        return $formattedFeatures;
    }

    private function getCombinations(Product $product, $languageId, $shopId)
    {
        $rows = $product->getAttributeCombinations($languageId);

        if (!is_array($rows)) {
            return array();
        }

        $combinations = array();

        foreach ($rows as $row) {
            $combinationId = (int) $row['id_product_attribute'];

            if (!isset($combinations[$combinationId])) {
                $combinations[$combinationId] = array(
                    'id_product_attribute' => $combinationId,
                    'reference' => (string) ($row['reference'] ?? ''),
                    'ean13' => (string) ($row['ean13'] ?? ''),
                    'upc' => (string) ($row['upc'] ?? ''),
                    'price_impact' => (float) ($row['price'] ?? 0),
                    'weight_impact' => (float) ($row['weight'] ?? 0),
                    'default_on' => (bool) ($row['default_on'] ?? false),
                    'quantity' => (int) StockAvailable::getQuantityAvailableByProduct(
                        (int) $product->id,
                        $combinationId,
                        $shopId
                    ),
                    'attributes' => array(),
                );
            }

            $combinations[$combinationId]['attributes'][] = array(
                'id_attribute_group' => (int) $row['id_attribute_group'],
                'group_name' => (string) $row['group_name'],
                'id_attribute' => (int) $row['id_attribute'],
                'attribute_name' => (string) $row['attribute_name'],
            );
        }

        return array_values($combinations);
    }

    private function getImages(Product $product, $languageId)
    {
        $images = $product->getImages($languageId);

        if (!is_array($images)) {
            return array();
        }

        $formattedImages = array();

        foreach ($images as $image) {
            $formattedImages[] = array(
                'id_image' => (int) $image['id_image'],
                'position' => (int) ($image['position'] ?? 0),
                'cover' => (bool) ($image['cover'] ?? false),
                'legend' => (string) ($image['legend'] ?? ''),
            );
        }

        return $formattedImages;
    }

    private function sendJson($statusCode, array $payload)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($payload);
        exit;
    }
}
