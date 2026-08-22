<?php

class AibridgeCategoriesModuleFrontController extends ModuleFrontController
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
            $categories = Category::getCategories(
                $languageId,
                true,
                false
            );

            if (!is_array($categories)) {
                throw new \RuntimeException('Invalid categories result.');
            }

            $formattedCategories = array();

            foreach ($categories as $category) {
                $formattedCategories[] = array(
                    'id_category' => (int) $category['id_category'],
                    'name' => (string) $category['name'],
                    'id_parent' => (int) $category['id_parent'],
                    'level_depth' => (int) $category['level_depth'],
                    'active' => (bool) $category['active'],
                    'is_root_category' => (bool) $category['is_root_category'],
                );
            }

            usort($formattedCategories, function (array $left, array $right) {
                $levelComparison = $left['level_depth']
                    <=> $right['level_depth'];

                if ($levelComparison !== 0) {
                    return $levelComparison;
                }

                return $left['id_category'] <=> $right['id_category'];
            });

            $this->sendJson(200, array(
                'success' => true,
                'data' => array(
                    'language_id' => $languageId,
                    'shop_id' => $shopId,
                    'count' => count($formattedCategories),
                    'categories' => $formattedCategories,
                ),
            ));
        } catch (\Throwable $exception) {
            $this->sendJson(500, array(
                'success' => false,
                'error' => array(
                    'code' => 'categories_internal_error',
                    'message' => 'Categories could not be loaded.',
                ),
            ));
        }
    }

    private function sendJson($statusCode, array $payload)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($payload);
        exit;
    }
}
