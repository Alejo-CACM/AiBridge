<?php

require_once dirname(__FILE__) . '/../../classes/AiBridgeConversation.php';

class AibridgeConversationModuleFrontController extends ModuleFrontController
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

            $employeeId = (int) $this->module->getAuthenticatedEmployeeId();
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

            if ($method === 'GET') {
                $this->handleGet($employeeId);
            }

            if ($method === 'POST') {
                $this->handlePost($employeeId);
            }

            $this->sendJson(405, array(
                'success' => false,
                'error' => array('code' => 'method_not_allowed', 'message' => 'Only GET and POST requests are allowed.'),
            ));
        } catch (\Throwable $exception) {
            $this->sendJson(500, array(
                'success' => false,
                'error' => array('code' => 'conversation_internal_error', 'message' => 'Conversation memory could not be processed.'),
            ));
        }
    }

    private function handleGet($employeeId)
    {
        $conversation = AiBridgeConversation::get($employeeId);

        $this->sendJson(200, array(
            'success' => true,
            'data' => array(
                'id_employee' => $employeeId,
                'messages' => $conversation !== null ? json_decode($conversation['messages_json'], true) : null,
                'updated_at' => $conversation !== null ? $conversation['updated_at'] : null,
            ),
        ));
    }

    private function handlePost($employeeId)
    {
        try {
            $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            $this->sendJson(400, array(
                'success' => false,
                'error' => array('code' => 'invalid_json', 'message' => 'Invalid JSON payload.'),
            ));
        }

        if (!is_array($payload) || !array_key_exists('messages', $payload) || !is_array($payload['messages'])) {
            $this->sendJson(400, array(
                'success' => false,
                'error' => array('code' => 'invalid_payload', 'message' => 'A "messages" array is required.'),
            ));
        }

        $messagesJson = json_encode($payload['messages']);
        if ($messagesJson === false || strlen($messagesJson) > AiBridgeConversation::MAX_MESSAGES_JSON_BYTES) {
            $this->sendJson(413, array(
                'success' => false,
                'error' => array('code' => 'conversation_too_large', 'message' => 'Conversation history is too large to store.'),
            ));
        }

        if (!AiBridgeConversation::save($employeeId, $messagesJson)) {
            $this->sendJson(500, array(
                'success' => false,
                'error' => array('code' => 'conversation_save_failed', 'message' => 'Conversation memory could not be saved.'),
            ));
        }

        $this->sendJson(200, array(
            'success' => true,
            'data' => array('id_employee' => $employeeId, 'saved' => true),
        ));
    }

    private function sendJson($statusCode, array $payload)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}
