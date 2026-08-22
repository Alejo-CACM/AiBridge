<?php

class AibridgeProductsModuleFrontController extends ModuleFrontController
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

            $languageId = (int) $this->context->language->id;
            $shopId = (int) $this->context->shop->id;
            $reference = trim((string) Tools::getValue('reference', ''));

            if ($reference !== '') {
                $this->sendJson(200, array(
                    'success' => true,
                    'data' => $this->lookupByReference($reference, $languageId, $shopId),
                ));
            }

            $search = trim((string) Tools::getValue('search', ''));
            $categoryId = (int) Tools::getValue('category_id', 0);
            $page = max(1, (int) Tools::getValue('page', 1));
            $limit = min(100, max(1, (int) Tools::getValue('limit', 50)));
            $offset = ($page - 1) * $limit;

            if ($search !== '') {
                $products = Product::searchByName($languageId, $search, $this->context, $limit);
            } else {
                $products = Product::getProducts(
                    $languageId,
                    $offset,
                    $limit,
                    'id_product',
                    'ASC',
                    $categoryId > 0 ? $categoryId : false,
                    true
                );
            }

            if (!is_array($products)) {
                throw new \RuntimeException('Invalid products result.');
            }

            $formattedProducts = array();

            foreach ($products as $product) {
                $formattedProducts[] = $this->formatListedProduct($product, $shopId);
            }

            $this->sendJson(200, array(
                'success' => true,
                'data' => array(
                    'page' => $search !== '' ? null : $page,
                    'limit' => $limit,
                    'search' => $search !== '' ? $search : null,
                    'category_id' => $categoryId > 0 ? $categoryId : null,
                    'count' => count($formattedProducts),
                    'products' => $formattedProducts,
                ),
            ));
        } catch (\Throwable $exception) {
            $this->sendJson(500, array(
                'success' => false,
                'error' => array(
                    'code' => 'products_internal_error',
                    'message' => 'Products could not be loaded.',
                ),
            ));
        }
    }

    /**
     * Fast O(1) lookup for duplicate checks before creating a product,
     * instead of paginating through the whole catalog.
     */
    private function lookupByReference($reference, $languageId, $shopId)
    {
        $productId = (int) Product::getIdByReference($reference);

        if ($productId <= 0) {
            return array('reference' => $reference, 'exists' => false, 'product' => null);
        }

        $product = new Product($productId, false, $languageId, $shopId);
        if (!Validate::isLoadedObject($product)) {
            return array('reference' => $reference, 'exists' => false, 'product' => null);
        }

        return array(
            'reference' => $reference,
            'exists' => true,
            'product' => $this->formatListedProduct(array(
                'id_product' => $productId,
                'reference' => $product->reference,
                'name' => $product->name,
                'description_short' => $product->description_short,
                'price' => $product->price,
                'id_manufacturer' => $product->id_manufacturer,
                'id_category_default' => $product->id_category_default,
                'active' => $product->active,
            ), $shopId),
        );
    }

    private function formatListedProduct(array $product, $shopId)
    {
        $productId = (int) $product['id_product'];
        $categories = Product::getProductCategories($productId);

        if (!is_array($categories)) {
            $categories = array();
        }

        return array(
            'id' => $productId,
            'reference' => (string) ($product['reference'] ?? ''),
            'name' => (string) ($product['name'] ?? ''),
            'description_short' => (string) ($product['description_short'] ?? ''),
            'price_tax_excl' => (float) ($product['price'] ?? 0),
            'id_manufacturer' => (int) ($product['id_manufacturer'] ?? 0),
            'categories' => array_values(array_map('intval', $categories)),
            'id_category_default' => (int) ($product['id_category_default'] ?? 0),
            'active' => (int) ($product['active'] ?? 0),
            'quantity' => (int) StockAvailable::getQuantityAvailableByProduct(
                $productId,
                null,
                $shopId
            ),
        );
    }

    private function sendJson($statusCode, array $payload)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($payload);
        exit;
    }
}
