<?php

class AibridgeAttributesModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        try {
            if (!is_object($this->module) || !method_exists($this->module, 'isValidApiToken')) {
                throw new \RuntimeException('Invalid module context.');
            }

            if (!$this->module->isValidApiToken()) {
                $this->sendJson(401, array(
                    'success' => false,
                    'error' => array('code' => 'unauthorized', 'message' => 'Invalid or missing API token.'),
                ));
            }

            $languageId = (int) $this->context->language->id;
            $groups = AttributeGroup::getAttributesGroups($languageId);

            if (!is_array($groups)) {
                throw new \RuntimeException('Invalid attribute groups result.');
            }

            $formatted = array();
            foreach ($groups as $group) {
                $groupId = (int) $group['id_attribute_group'];
                $attributes = AttributeGroup::getAttributes($languageId, $groupId);
                $formattedAttributes = array();
                if (is_array($attributes)) {
                    foreach ($attributes as $attribute) {
                        $formattedAttributes[] = array(
                            'id_attribute' => (int) $attribute['id_attribute'],
                            'name' => (string) $attribute['name'],
                        );
                    }
                }

                $formatted[] = array(
                    'id_attribute_group' => $groupId,
                    'name' => (string) $group['name'],
                    'public_name' => (string) ($group['public_name'] ?? $group['name']),
                    'is_color_group' => (bool) ($group['is_color_group'] ?? false),
                    'attributes' => $formattedAttributes,
                );
            }

            $this->sendJson(200, array(
                'success' => true,
                'data' => array(
                    'language_id' => $languageId,
                    'count' => count($formatted),
                    'attribute_groups' => $formatted,
                ),
            ));
        } catch (\Throwable $exception) {
            $this->sendJson(500, array(
                'success' => false,
                'error' => array('code' => 'attributes_internal_error', 'message' => 'Attributes could not be loaded.'),
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
