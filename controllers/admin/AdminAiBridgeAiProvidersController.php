<?php

require_once dirname(__FILE__) . '/../../classes/AiBridgeAiProvider.php';

class AdminAiBridgeAiProvidersController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'aibridge_ai_provider';
        $this->className = 'AiBridgeAiProvider';
        $this->identifier = 'id_aibridge_ai_provider';

        parent::__construct();
    }

    public function displayProviderType($value)
    {
        $types = AiBridgeAiProvider::types();

        return isset($types[$value]) ? $types[$value] : $value;
    }

    public function displayApiKey($value, $row)
    {
        $provider = new AiBridgeAiProvider((int) $row['id_aibridge_ai_provider']);

        return Validate::isLoadedObject($provider) ? $provider->maskedApiKey() : '';
    }

    public function renderList()
    {
        $this->fields_list = array(
            'name' => array('title' => 'Nombre'),
            'provider_type' => array('title' => 'Tipo', 'callback' => 'displayProviderType'),
            'model' => array('title' => 'Modelo'),
            'api_key' => array('title' => 'API key', 'callback' => 'displayApiKey', 'orderby' => false, 'search' => false),
            'active' => array('title' => 'Activo', 'type' => 'bool', 'active' => 'active', 'class' => 'fixed-width-sm'),
            'is_default' => array('title' => 'Por defecto', 'type' => 'bool', 'class' => 'fixed-width-sm'),
        );

        return parent::renderList();
    }

    public function renderForm()
    {
        $typeOptions = array();
        foreach (AiBridgeAiProvider::types() as $value => $label) {
            $typeOptions[] = array('id' => $value, 'name' => $label);
        }

        $this->fields_form = array(
            'legend' => array('title' => 'Proveedor de IA'),
            'input' => array(
                array('type' => 'text', 'label' => 'Nombre', 'name' => 'name', 'required' => true, 'desc' => 'Nombre para identificarlo, ej. "ChatGPT producción".'),
                array(
                    'type' => 'select',
                    'label' => 'Tipo',
                    'name' => 'provider_type',
                    'required' => true,
                    'options' => array('query' => $typeOptions, 'id' => 'id', 'name' => 'name'),
                ),
                array('type' => 'text', 'label' => 'Modelo', 'name' => 'model', 'required' => true, 'desc' => 'Ej. gpt-4o, claude-sonnet-4-5, gemini-2.5-flash, etc.'),
                array(
                    'type' => 'text',
                    'label' => 'Base URL',
                    'name' => 'base_url',
                    'desc' => 'Solo para "Compatible con OpenAI": URL base de la API, ej. https://api.groq.com/openai/v1',
                ),
                array(
                    'type' => 'password',
                    'label' => 'API key',
                    'name' => 'api_key',
                    'desc' => $this->object && $this->object->id
                        ? 'Dejar en blanco para no cambiarla.'
                        : 'Se guarda en la base de datos de esta tienda.',
                ),
                array('type' => 'switch', 'label' => 'Activo', 'name' => 'active', 'values' => array(
                    array('id' => 'active_on', 'value' => 1, 'label' => 'Sí'),
                    array('id' => 'active_off', 'value' => 0, 'label' => 'No'),
                )),
                array('type' => 'switch', 'label' => 'Usar por defecto en el chat', 'name' => 'is_default', 'values' => array(
                    array('id' => 'default_on', 'value' => 1, 'label' => 'Sí'),
                    array('id' => 'default_off', 'value' => 0, 'label' => 'No'),
                )),
            ),
            'submit' => array('title' => 'Guardar'),
        );

        return parent::renderForm();
    }

    public function processSave()
    {
        if (!$this->access(Tools::getValue('id_' . $this->table) ? 'edit' : 'add')) {
            throw new PrestaShopException('Access denied.');
        }

        $id = (int) Tools::getValue('id_' . $this->table);
        $provider = $id ? new AiBridgeAiProvider($id) : new AiBridgeAiProvider();

        $provider->name = (string) Tools::getValue('name');
        $provider->provider_type = (string) Tools::getValue('provider_type');
        $provider->base_url = (string) Tools::getValue('base_url');
        $provider->model = (string) Tools::getValue('model');
        $provider->active = (bool) Tools::getValue('active');
        $provider->is_default = (bool) Tools::getValue('is_default');

        $submittedKey = trim((string) Tools::getValue('api_key'));
        if ($submittedKey !== '') {
            $provider->api_key = $submittedKey;
        } elseif (!$id) {
            $provider->api_key = '';
        }

        $provider->updated_at = date('Y-m-d H:i:s');
        if (!$id) {
            $provider->created_at = date('Y-m-d H:i:s');
        }

        if (!in_array($provider->provider_type, array_keys(AiBridgeAiProvider::types()), true)) {
            $this->errors[] = 'Tipo de proveedor inválido.';
            return false;
        }

        $saved = $id ? $provider->update() : $provider->add();

        if (!$saved) {
            $this->errors[] = 'No se pudo guardar el proveedor de IA.';
            return false;
        }

        if ($provider->is_default) {
            $provider->clearOtherDefaults();
        }

        Tools::redirectAdmin(self::$currentIndex . '&token=' . $this->token);
    }
}
