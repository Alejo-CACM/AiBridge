<?php

class AiBridgeAiProvider extends ObjectModel
{
    public const TYPE_OPENAI = 'openai';
    public const TYPE_ANTHROPIC = 'anthropic';
    public const TYPE_OPENAI_COMPATIBLE = 'openai_compatible';

    public $id_aibridge_ai_provider;
    public $name;
    public $provider_type;
    public $api_key;
    public $base_url;
    public $model;
    public $active;
    public $is_default;
    public $created_at;
    public $updated_at;

    public static $definition = array(
        'table' => 'aibridge_ai_provider',
        'primary' => 'id_aibridge_ai_provider',
        'fields' => array(
            'name' => array('type' => self::TYPE_STRING, 'required' => true, 'size' => 64),
            'provider_type' => array('type' => self::TYPE_STRING, 'required' => true, 'size' => 32),
            'api_key' => array('type' => self::TYPE_STRING, 'size' => 255),
            'base_url' => array('type' => self::TYPE_STRING, 'size' => 255, 'allow_null' => true),
            'model' => array('type' => self::TYPE_STRING, 'required' => true, 'size' => 128),
            'active' => array('type' => self::TYPE_BOOL),
            'is_default' => array('type' => self::TYPE_BOOL),
            'created_at' => array('type' => self::TYPE_DATE, 'required' => true),
            'updated_at' => array('type' => self::TYPE_DATE, 'required' => true),
        ),
    );

    public static function types()
    {
        return array(
            self::TYPE_OPENAI => 'OpenAI (ChatGPT)',
            self::TYPE_ANTHROPIC => 'Anthropic (Claude)',
            self::TYPE_OPENAI_COMPATIBLE => 'Compatible con OpenAI (Gemini, DeepSeek, Groq, OpenRouter, Ollama, etc.)',
        );
    }

    public static function getDefault()
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_aibridge_ai_provider` FROM `' . _DB_PREFIX_ . 'aibridge_ai_provider`'
            . ' WHERE `active` = 1 AND `is_default` = 1'
        );

        if (!$id) {
            $id = (int) Db::getInstance()->getValue(
                'SELECT `id_aibridge_ai_provider` FROM `' . _DB_PREFIX_ . 'aibridge_ai_provider`'
                . ' WHERE `active` = 1 ORDER BY `id_aibridge_ai_provider` ASC'
            );
        }

        if (!$id) {
            return null;
        }

        $provider = new self($id);

        return Validate::isLoadedObject($provider) ? $provider : null;
    }

    public function clearOtherDefaults()
    {
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'aibridge_ai_provider` SET `is_default` = 0'
            . ' WHERE `id_aibridge_ai_provider` != ' . (int) $this->id
        );
    }

    /**
     * Last 4 characters only, so the admin list/form never round-trips a
     * usable key back to the browser once saved.
     */
    public function maskedApiKey()
    {
        $key = (string) $this->api_key;
        $length = strlen($key);

        return $length > 4 ? str_repeat('*', $length - 4) . substr($key, -4) : str_repeat('*', $length);
    }
}
