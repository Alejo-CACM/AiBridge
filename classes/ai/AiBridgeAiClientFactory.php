<?php

require_once dirname(__FILE__) . '/AiBridgeOpenAiClient.php';
require_once dirname(__FILE__) . '/AiBridgeAnthropicClient.php';

class AiBridgeAiClientFactory
{
    public static function build(AiBridgeAiProvider $provider)
    {
        switch ($provider->provider_type) {
            case AiBridgeAiProvider::TYPE_OPENAI:
                return new AiBridgeOpenAiClient($provider->api_key, $provider->model);

            case AiBridgeAiProvider::TYPE_OPENAI_COMPATIBLE:
                $baseUrl = trim((string) $provider->base_url);
                if ($baseUrl === '') {
                    throw new \RuntimeException('This provider needs a base URL.');
                }

                return new AiBridgeOpenAiClient($provider->api_key, $provider->model, $baseUrl);

            case AiBridgeAiProvider::TYPE_ANTHROPIC:
                return new AiBridgeAnthropicClient($provider->api_key, $provider->model);

            default:
                throw new \RuntimeException('Unknown AI provider type: ' . $provider->provider_type);
        }
    }
}
