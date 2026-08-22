<?php

class AibridgeBrandsModuleFrontController extends ModuleFrontController
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
            $brands = Manufacturer::getManufacturers(
                false,
                $languageId,
                true
            );

            if (!is_array($brands)) {
                throw new \RuntimeException('Invalid brands result.');
            }

            $formattedBrands = array();

            foreach ($brands as $brand) {
                $formattedBrands[] = array(
                    'id_manufacturer' => (int) $brand['id_manufacturer'],
                    'name' => (string) $brand['name'],
                    'active' => (bool) $brand['active'],
                    'date_add' => (string) $brand['date_add'],
                    'date_upd' => (string) $brand['date_upd'],
                );
            }

            usort($formattedBrands, function (array $left, array $right) {
                $nameComparison = strcasecmp($left['name'], $right['name']);

                if ($nameComparison !== 0) {
                    return $nameComparison;
                }

                return $left['id_manufacturer']
                    <=> $right['id_manufacturer'];
            });

            $this->sendJson(200, array(
                'success' => true,
                'data' => array(
                    'language_id' => $languageId,
                    'shop_id' => $shopId,
                    'count' => count($formattedBrands),
                    'brands' => $formattedBrands,
                ),
            ));
        } catch (\Throwable $exception) {
            $this->sendJson(500, array(
                'success' => false,
                'error' => array(
                    'code' => 'brands_internal_error',
                    'message' => 'Brands could not be loaded.',
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
