<?php

require_once dirname(__FILE__) . '/../../classes/AiBridgeEmailTemplate.php';

class AibridgeEmailtemplatesModuleFrontController extends ModuleFrontController
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

            $name = trim((string) Tools::getValue('name', ''));

            if ($name !== '') {
                $template = AiBridgeEmailTemplate::findByName($name);
                if ($template === null) {
                    $this->sendJson(404, array(
                        'success' => false,
                        'error' => array('code' => 'template_not_found', 'message' => 'Email template not found.'),
                    ));
                }

                $this->sendJson(200, array(
                    'success' => true,
                    'data' => array('template' => array(
                        'id' => (int) $template->id,
                        'name' => (string) $template->name,
                        'subject' => (string) $template->subject,
                        'html_body' => (string) $template->html_body,
                        'created_at' => (string) $template->created_at,
                        'updated_at' => (string) $template->updated_at,
                    )),
                ));
            }

            $rows = AiBridgeEmailTemplate::listAll();
            $templates = array();
            foreach ($rows as $row) {
                $templates[] = array(
                    'id' => (int) $row['id_aibridge_email_template'],
                    'name' => (string) $row['name'],
                    'subject' => (string) $row['subject'],
                    'created_at' => (string) $row['created_at'],
                    'updated_at' => (string) $row['updated_at'],
                );
            }

            $this->sendJson(200, array(
                'success' => true,
                'data' => array('count' => count($templates), 'templates' => $templates),
            ));
        } catch (\Throwable $exception) {
            $this->sendJson(500, array(
                'success' => false,
                'error' => array('code' => 'email_templates_internal_error', 'message' => 'Email templates could not be loaded.'),
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
