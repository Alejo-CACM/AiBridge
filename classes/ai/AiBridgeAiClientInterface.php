<?php

interface AiBridgeAiClientInterface
{
    /**
     * $messages: array of ['role' => 'user'|'assistant', 'content' => string]
     *   plus optional ['role' => 'tool', 'tool_call_id' => string, 'name' => string, 'content' => string]
     *   for a message that reports back a prior tool call's result.
     * $tools: array of ['name' => string, 'description' => string, 'parameters' => array (JSON schema)]
     *
     * Returns ['content' => ?string, 'tool_calls' => array of ['id' => string, 'name' => string, 'arguments' => array]]
     */
    public function chat(array $messages, array $tools, $systemPrompt);
}
