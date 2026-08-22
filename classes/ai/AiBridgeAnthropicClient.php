<?php

require_once dirname(__FILE__) . '/AiBridgeAiClientInterface.php';

class AiBridgeAnthropicClient implements AiBridgeAiClientInterface
{
    private const API_VERSION = '2023-06-01';
    private const MAX_TOKENS = 4096;

    private $apiKey;
    private $model;
    private $baseUrl;

    public function __construct($apiKey, $model, $baseUrl = 'https://api.anthropic.com/v1')
    {
        $this->apiKey = (string) $apiKey;
        $this->model = (string) $model;
        $this->baseUrl = rtrim((string) $baseUrl, '/');
    }

    public function chat(array $messages, array $tools, $systemPrompt)
    {
        $wireMessages = array();
        foreach ($messages as $message) {
            $wireMessages[] = $this->toWireMessage($message);
        }

        $body = array(
            'model' => $this->model,
            'max_tokens' => self::MAX_TOKENS,
            'messages' => $wireMessages,
        );

        if ($systemPrompt !== null && $systemPrompt !== '') {
            $body['system'] = (string) $systemPrompt;
        }

        if ($tools) {
            $body['tools'] = array_map(array($this, 'toWireTool'), $tools);
        }

        $response = $this->post('/messages', $body);

        $content = null;
        $toolCalls = array();
        foreach ((array) ($response['content'] ?? array()) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content = ($content ?? '') . (string) $block['text'];
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = array(
                    'id' => (string) $block['id'],
                    'name' => (string) $block['name'],
                    'arguments' => is_array($block['input'] ?? null) ? $block['input'] : array(),
                );
            }
        }

        return array('content' => $content, 'tool_calls' => $toolCalls);
    }

    private function toWireMessage(array $message)
    {
        if ($message['role'] === 'tool_result') {
            return array(
                'role' => 'user',
                'content' => array(array(
                    'type' => 'tool_result',
                    'tool_use_id' => (string) $message['tool_call_id'],
                    'content' => (string) $message['content'],
                )),
            );
        }

        if ($message['role'] === 'assistant' && !empty($message['tool_calls'])) {
            $blocks = array();
            if ($message['content'] !== null && $message['content'] !== '') {
                $blocks[] = array('type' => 'text', 'text' => (string) $message['content']);
            }
            foreach ($message['tool_calls'] as $call) {
                $blocks[] = array(
                    'type' => 'tool_use',
                    'id' => (string) $call['id'],
                    'name' => (string) $call['name'],
                    'input' => $call['arguments'],
                );
            }

            return array('role' => 'assistant', 'content' => $blocks);
        }

        return array('role' => (string) $message['role'], 'content' => (string) $message['content']);
    }

    private function toWireTool(array $tool)
    {
        return array(
            'name' => $tool['name'],
            'description' => $tool['description'],
            'input_schema' => $tool['parameters'],
        );
    }

    private function post($path, array $body)
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Missing API key for AI provider.');
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('Could not reach AI provider: ' . $curlError);
        }

        $decoded = json_decode((string) $raw, true);

        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) && isset($decoded['error']['message'])
                ? $decoded['error']['message']
                : ('HTTP ' . $status);
            throw new \RuntimeException('AI provider error: ' . $message);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON response from AI provider.');
        }

        return $decoded;
    }
}
