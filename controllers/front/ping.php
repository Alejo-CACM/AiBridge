<?php

class AibridgePingModuleFrontController extends ModuleFrontController
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
                    'status' => 'ok',
                    'module' => 'aibridge',
                ),
            ));
        } catch (\Throwable $exception) {
            $this->sendJson(500, array(
                'success' => false,
                'error' => array(
                    'code' => 'ping_internal_error',
                    'message' => 'Ping failed internally.',
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
