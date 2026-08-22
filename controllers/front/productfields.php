<?php

class AibridgeProductfieldsModuleFrontController extends ModuleFrontController
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

            $this->sendJson(200, array(
                'success' => true,
                'data' => array(
                    'module' => 'aibridge',
                    'prestashop_version' => _PS_VERSION_,
                    'groups' => array(
                        'identification' => array(
                            $this->field(
                                'reference',
                                'string',
                                false,
                                'Internal product reference.'
                            ),
                            $this->field(
                                'ean13',
                                'string',
                                false,
                                'EAN-13 barcode; validate its format before saving.'
                            ),
                        ),
                        'texts' => array(
                            $this->field(
                                'name',
                                'string',
                                true,
                                'Product name for each enabled language.'
                            ),
                            $this->field(
                                'description',
                                'html',
                                true,
                                'Long product description; HTML requires sanitization.'
                            ),
                            $this->field(
                                'description_short',
                                'html',
                                true,
                                'Short product description; HTML requires sanitization.'
                            ),
                        ),
                        'seo' => array(
                            $this->field(
                                'meta_title',
                                'string',
                                true,
                                'SEO title for each enabled language.'
                            ),
                            $this->field(
                                'meta_description',
                                'string',
                                true,
                                'SEO description for each enabled language.'
                            ),
                            $this->field(
                                'link_rewrite',
                                'string',
                                true,
                                'URL slug for each enabled language; must be validated.'
                            ),
                        ),
                        'pricing' => array(
                            $this->field(
                                'price',
                                'decimal',
                                false,
                                'Tax-excluded retail price.'
                            ),
                            $this->field(
                                'wholesale_price',
                                'decimal',
                                false,
                                'Tax-excluded cost price.'
                            ),
                            $this->field(
                                'id_tax_rules_group',
                                'integer',
                                false,
                                'Identifier of the tax rules group.'
                            ),
                        ),
                        'stock' => array(
                            $this->field(
                                'quantity',
                                'integer',
                                false,
                                'Stock requires multistore and combination-aware handling.'
                            ),
                            $this->field(
                                'minimal_quantity',
                                'integer',
                                false,
                                'Minimum quantity allowed per order.'
                            ),
                        ),
                        'classification' => array(
                            $this->field(
                                'categories',
                                'integer_array',
                                false,
                                'Assigned category identifiers.'
                            ),
                            $this->field(
                                'id_category_default',
                                'integer',
                                false,
                                'Default category identifier; it must be assigned.'
                            ),
                            $this->field(
                                'id_manufacturer',
                                'integer',
                                false,
                                'Manufacturer identifier; use zero when unset.'
                            ),
                        ),
                        'features' => array(
                            $this->field(
                                'features',
                                'feature_value_array',
                                false,
                                'Feature assignments are not translated; custom feature values may contain values per language.'
                            ),
                        ),
                        'combinations' => array(
                            $this->field(
                                'combinations',
                                'combination_array',
                                false,
                                'Combination data requires dedicated validation and stock rules.'
                            ),
                        ),
                        'status' => array(
                            $this->field(
                                'active',
                                'boolean',
                                false,
                                'Whether the product is enabled in the current shop context.'
                            ),
                            $this->field(
                                'condition',
                                'enum',
                                false,
                                'Product condition, such as new, used or refurbished.'
                            ),
                        ),
                        'physical' => array(
                            $this->field(
                                'weight',
                                'decimal',
                                false,
                                'Weight in the shop configured unit.'
                            ),
                            $this->field(
                                'width',
                                'decimal',
                                false,
                                'Package width in the shop configured unit.'
                            ),
                            $this->field(
                                'height',
                                'decimal',
                                false,
                                'Package height in the shop configured unit.'
                            ),
                            $this->field(
                                'depth',
                                'decimal',
                                false,
                                'Package depth in the shop configured unit.'
                            ),
                        ),
                        'images' => array(
                            $this->field(
                                'images',
                                'image_array',
                                false,
                                'Image management requires dedicated upload and ordering rules.'
                            ),
                        ),
                    ),
                ),
            ));
        } catch (\Throwable $exception) {
            $this->sendJson(500, array(
                'success' => false,
                'error' => array(
                    'code' => 'product_fields_internal_error',
                    'message' => 'Product field metadata could not be loaded.',
                ),
            ));
        }
    }

    private function field($name, $type, $translatable, $notes)
    {
        return array(
            'name' => $name,
            'type' => $type,
            'translatable' => $translatable,
            'writable_later' => true,
            'preview_required' => true,
            'notes' => $notes,
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
