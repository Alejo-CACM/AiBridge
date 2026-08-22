<?php

require_once dirname(__FILE__) . '/AiBridgeAiClientInterface.php';

/**
 * Talks to any OpenAI-compatible /chat/completions endpoint: OpenAI itself,
 * and (via a custom base_url) Gemini's OpenAI-compat endpoint, DeepSeek,
 * Groq, OpenRouter, Mistral, local Ollama/LM Studio, etc. The wire format
 * for chat + function calling is the same across all of them.
 */
class AiBridgeOpenAiClient implements AiBridgeAiClientInterface
{
    private $apiKey;
    private $model;
    private $baseUrl;

    public function __construct($apiKey, $model, $baseUrl = 'https://api.openai.com/v1')
    {
        $this->apiKey = (string) $apiKey;
        $this->model = (string) $model;
        $this->baseUrl = rtrim((string) $baseUrl, '/');
    }

    public function chat(array $messages, array $tools, $systemPrompt)
    {
        $wireMessages = array();
        if ($systemPrompt !== null && $systemPrompt !== '') {
            $wireMessages[] = array('role' => 'system', 'content' => (string) $systemPrompt);
        }

        foreach ($messages as $message) {
            $wireMessages[] = $this->toWireMessage($message);
        }

        $body = array(
            'model' => $this->model,
            'messages' => $wireMessages,
        );

        if ($tools) {
            $body['tools'] = array_map(array($this, 'toWireTool'), $tools);
            $body['tool_choice'] = 'auto';
        }

        $response = $this->post('/chat/completions', $body);
        $message = $response['choices'][0]['message'] ?? null;

        if (!is_array($message)) {
            throw new \RuntimeException('Unexpected response from AI provider.');
        }

        $toolCalls = array();
        foreach ((array) ($message['tool_calls'] ?? array()) as $rawCall) {
            $arguments = json_decode((string) ($rawCall['function']['arguments'] ?? '{}'), true);
            $toolCalls[] = array(
                'id' => (string) ($rawCall['id'] ?? ''),
                'name' => (string) ($rawCall['function']['name'] ?? ''),
                'arguments' => is_array($arguments) ? $arguments : array(),
            );
        }

        return array(
            'content' => isset($message['content']) ? (string) $message['content'] : null,
            'tool_calls' => $toolCalls,
        );
    }

    private function toWireMessage(array $message)
    {
        if ($message['role'] === 'tool_result') {
            return array(
                'role' => 'tool',
                'tool_call_id' => (string) $message['tool_call_id'],
                'content' => (string) $message['content'],
            );
        }

        if ($message['role'] === 'assistant' && !empty($message['tool_calls'])) {
            return array(
                'role' => 'assistant',
                'content' => $message['content'] !== null ? (string) $message['content'] : null,
                'tool_calls' => array_map(function ($call) {
                    return array(
                        'id' => (string) $call['id'],
                        'type' => 'function',
                        'function' => array(
                            'name' => (string) $call['name'],
                            'arguments' => json_encode($call['arguments']),
                        ),
                    );
                }, $message['tool_calls']),
            );
        }

        return array('role' => (string) $message['role'], 'content' => (string) $message['content']);
    }

    private function toWireTool(array $tool)
    {
        return array(
            'type' => 'function',
            'function' => array(
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['parameters'],
            ),
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
                'Authorization: Bearer ' . $this->apiKey,
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
