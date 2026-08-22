<?php

require_once dirname(__FILE__) . '/AiBridgeAiClientFactory.php';
require_once dirname(__FILE__) . '/AiBridgeToolRegistry.php';
require_once dirname(__FILE__) . '/AiBridgeToolExecutor.php';
require_once dirname(__FILE__) . '/../AiBridgeAiProvider.php';

/**
 * Runs one request/response turn of the Back Office chat: calls the
 * configured AI provider, executes any tool calls it makes (each becomes a
 * pending approval request, never a direct write), feeds the results back,
 * and repeats until the model answers with plain text or the iteration cap
 * is hit. Only the final text and one summary line per tool call are
 * persisted to the conversation log — the tool_call/tool_result exchange
 * itself lives only for the duration of this call.
 */
class AiBridgeChatOrchestrator
{
    private const MAX_TOOL_ITERATIONS = 5;

    public function respond(array $priorMessages, $employeeId)
    {
        $provider = AiBridgeAiProvider::getDefault();

        if ($provider === null) {
            return array($this->assistantMessage(
                'No hay ningún proveedor de IA activo configurado. Configurá uno en AI Bridge -> Proveedores de IA.'
            ));
        }

        try {
            $client = AiBridgeAiClientFactory::build($provider);
        } catch (\Throwable $exception) {
            return array($this->assistantMessage('Error de configuración del proveedor de IA: ' . $exception->getMessage()));
        }

        $tools = AiBridgeToolRegistry::all();
        $executor = new AiBridgeToolExecutor();
        $systemPrompt = $this->buildSystemPrompt();

        $conversation = array();
        foreach ($priorMessages as $message) {
            if (isset($message['role'], $message['content']) && in_array($message['role'], array('user', 'assistant'), true)) {
                $conversation[] = array('role' => $message['role'], 'content' => (string) $message['content']);
            }
        }

        $newMessages = array();

        for ($iteration = 0; $iteration < self::MAX_TOOL_ITERATIONS; ++$iteration) {
            try {
                $result = $client->chat($conversation, $tools, $systemPrompt);
            } catch (\Throwable $exception) {
                $newMessages[] = $this->assistantMessage('Error consultando al proveedor de IA: ' . $exception->getMessage());

                return $newMessages;
            }

            if (empty($result['tool_calls'])) {
                $content = trim((string) $result['content']);
                if ($content !== '') {
                    $newMessages[] = $this->assistantMessage($content);
                }

                return $newMessages;
            }

            $conversation[] = array(
                'role' => 'assistant',
                'content' => $result['content'],
                'tool_calls' => $result['tool_calls'],
            );

            foreach ($result['tool_calls'] as $call) {
                $toolResult = $executor->execute($call['name'], $call['arguments'], $employeeId);

                $newMessages[] = $this->assistantMessage($this->summarizeToolCall($call['name'], $toolResult));

                $conversation[] = array(
                    'role' => 'tool_result',
                    'tool_call_id' => $call['id'],
                    'content' => json_encode($toolResult),
                );
            }
        }

        $newMessages[] = $this->assistantMessage(
            'Se alcanzó el límite de pasos por turno sin llegar a una respuesta final. Pedime continuar si hace falta.'
        );

        return $newMessages;
    }

    private function summarizeToolCall($name, array $result)
    {
        if (empty($result['ok'])) {
            return '⚠️ ' . $name . ' falló: ' . (isset($result['error']) ? $result['error'] : 'error desconocido');
        }

        if (($result['status'] ?? '') === 'pending_approval') {
            return '🔧 ' . $name . ' — propuesta creada, pendiente de aprobación (#' . $result['approval_id']
                . '). Un administrador distinto debe aprobarla en AI Bridge -> Approvals.';
        }

        if (($result['status'] ?? '') === 'no_changes') {
            return 'ℹ️ ' . $name . ': no hay cambios que aplicar, ya coincide con lo solicitado.';
        }

        return '🔧 ' . $name . ' ejecutado.';
    }

    private function assistantMessage($content)
    {
        return array('role' => 'assistant', 'content' => $content, 'at' => date('c'));
    }

    private function buildSystemPrompt()
    {
        $persona = 'Sos el asistente de IA integrado en el Back Office de una tienda PrestaShop, a través del módulo AI Bridge. '
            . 'Hablás en español salvo que te pidan otro idioma. Sé breve y directo. '
            . 'Para consultar o cambiar el catálogo (productos, categorías, marcas) usá las herramientas disponibles — nunca inventes que hiciste un cambio si no llamaste a una herramienta. '
            . 'Toda acción de escritura queda como propuesta pendiente de aprobación por otro administrador: dejalo claro en tu respuesta. '
            . 'A continuación tenés la guía completa de operaciones del módulo, con el formato exacto de cada payload:'
            . "\n\n---\n\n";

        $guidePath = dirname(__FILE__) . '/../../AGENTS.md';
        $guide = is_file($guidePath) ? (string) @file_get_contents($guidePath) : '';

        // Defensive cap: keeps a single request well within any provider's
        // context window even if AGENTS.md grows a lot further.
        if (strlen($guide) > 60000) {
            $guide = substr($guide, 0, 60000) . "\n\n[... guía truncada ...]";
        }

        return $persona . $guide;
    }
}
