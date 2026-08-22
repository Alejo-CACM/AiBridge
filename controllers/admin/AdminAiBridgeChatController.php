<?php

require_once dirname(__FILE__) . '/../../classes/AiBridgeConversation.php';
require_once dirname(__FILE__) . '/../../classes/ai/AiBridgeChatOrchestrator.php';

class AdminAiBridgeChatController extends ModuleAdminController
{
    private const MAX_MESSAGES_KEPT = 200;
    private const MAX_MESSAGE_LENGTH = 4000;

    public function __construct()
    {
        $this->bootstrap = true;
        $this->ajax = true;

        parent::__construct();
    }

    public function ajaxProcessFetchMessages()
    {
        $employeeId = $this->authenticatedEmployeeId();
        $messages = $this->loadMessages($employeeId);

        die(json_encode(array(
            'success' => true,
            'messages' => $messages,
            'awaiting_reply' => $this->isAwaitingReply($messages),
        )));
    }

    public function ajaxProcessSendMessage()
    {
        $employeeId = $this->authenticatedEmployeeId();
        $content = trim((string) Tools::getValue('content'));

        if ($employeeId <= 0 || $content === '' || Tools::strlen($content) > self::MAX_MESSAGE_LENGTH) {
            die(json_encode(array('success' => false, 'error' => 'invalid_message')));
        }

        $messages = $this->loadMessages($employeeId);
        $messages[] = array(
            'role' => 'user',
            'content' => $content,
            'at' => date('c'),
        );

        $replies = (new AiBridgeChatOrchestrator())->respond($messages, $employeeId);
        foreach ($replies as $reply) {
            $messages[] = $reply;
        }

        if (count($messages) > self::MAX_MESSAGES_KEPT) {
            $messages = array_slice($messages, -self::MAX_MESSAGES_KEPT);
        }

        $saved = AiBridgeConversation::save($employeeId, json_encode($messages));

        die(json_encode(array(
            'success' => (bool) $saved,
            'messages' => $messages,
            'awaiting_reply' => false,
        )));
    }

    public function ajaxProcessResetConversation()
    {
        $employeeId = $this->authenticatedEmployeeId();

        if ($employeeId <= 0) {
            die(json_encode(array('success' => false, 'error' => 'invalid_employee')));
        }

        die(json_encode(array('success' => (bool) AiBridgeConversation::delete($employeeId))));
    }

    private function authenticatedEmployeeId()
    {
        return isset($this->context->employee) ? (int) $this->context->employee->id : 0;
    }

    private function loadMessages($employeeId)
    {
        $conversation = AiBridgeConversation::get($employeeId);
        $messages = $conversation !== null ? json_decode((string) $conversation['messages_json'], true) : array();

        return is_array($messages) ? $messages : array();
    }

    /**
     * The widget shows a "waiting for the agent" state whenever the most
     * recent message is from the employee and no assistant reply follows it
     * yet — mirrors how the external agent decides a conversation needs a
     * response when polling via the token-based /conversation endpoint.
     */
    private function isAwaitingReply(array $messages)
    {
        if (!$messages) {
            return false;
        }

        $last = $messages[count($messages) - 1];

        return is_array($last) && isset($last['role']) && $last['role'] === 'user';
    }
}
