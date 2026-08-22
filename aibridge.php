<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/AiBridgeSelfUpdater.php';
require_once __DIR__ . '/classes/AiBridgeEmployeeToken.php';

class AiBridge extends Module
{
    public const API_TOKEN_CONFIGURATION_KEY = 'AIBRIDGE_API_TOKEN';
    public const DIRECT_APPLY_TEST_MODE_CONFIGURATION_KEY = 'AIBRIDGE_DIRECT_APPLY_TEST_MODE';
    public const UPDATE_MANIFEST_URL_CONFIGURATION_KEY = 'AIBRIDGE_UPDATE_MANIFEST_URL';
    private const DEFAULT_UPDATE_MANIFEST_URL = 'https://raw.githubusercontent.com/Alejo-CACM/AiBridge/main/dist/manifest.json';

    private $authenticatedEmployeeId = 0;

    public function __construct()
    {
        $this->name = 'aibridge';
        $this->tab = 'administration';
        $this->version = '1.19.3';
        $this->author = 'Proyecto profesional';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('AI Bridge');
        $this->description = $this->l('Base module for AI-assisted catalog management.');
        $this->ps_versions_compliancy = array('min' => '8.2.0', 'max' => _PS_VERSION_);
    }

    public function getContent()
    {
        $this->ensureChatWidgetInstalled();

        $message = '';
        $adminToken = Tools::getAdminTokenLite('AdminModules');

        if (Tools::isSubmit('submitAiBridgeRegenerateToken')) {
            $submittedToken = (string) Tools::getValue('token');

            if (!hash_equals($adminToken, $submittedToken)) {
                $message = '<div class="alert alert-danger">'
                    . $this->l('Invalid security token.')
                    . '</div>';
            } else {
                try {
                    $token = bin2hex(random_bytes(32));
                    $saved = Configuration::updateValue(
                        self::API_TOKEN_CONFIGURATION_KEY,
                        $token
                    );
                } catch (\Throwable $exception) {
                    $saved = false;
                }

                if ($saved) {
                    $message = '<div class="alert alert-success">'
                        . $this->l('API token regenerated successfully.')
                        . '</div>';
                } else {
                    $message = '<div class="alert alert-danger">'
                        . $this->l('The API token could not be regenerated.')
                        . '</div>';
                }
            }
        }

        if (Tools::isSubmit('submitAiBridgeDirectApplyTestMode')) {
            $submittedToken = (string) Tools::getValue('token');

            if (!hash_equals($adminToken, $submittedToken)) {
                $message .= '<div class="alert alert-danger">'
                    . $this->l('Invalid security token.')
                    . '</div>';
            } else {
                Configuration::updateValue(
                    self::DIRECT_APPLY_TEST_MODE_CONFIGURATION_KEY,
                    Tools::getValue('aibridge_direct_apply_test_mode') ? 1 : 0
                );
                $message .= '<div class="alert alert-success">'
                    . $this->l('Direct API execution setting updated.')
                    . '</div>';
            }
        }

        if (Tools::isSubmit('submitAiBridgeUpdateManifestUrl')) {
            $submittedToken = (string) Tools::getValue('token');

            if (!hash_equals($adminToken, $submittedToken)) {
                $message .= '<div class="alert alert-danger">'
                    . $this->l('Invalid security token.')
                    . '</div>';
            } else {
                $url = trim((string) Tools::getValue('aibridge_update_manifest_url'));
                if ($url !== '' && strpos($url, 'https://') !== 0) {
                    $message .= '<div class="alert alert-danger">'
                        . $this->l('The update manifest URL must start with https://.')
                        . '</div>';
                } else {
                    Configuration::updateValue(
                        self::UPDATE_MANIFEST_URL_CONFIGURATION_KEY,
                        $url !== '' ? $url : self::DEFAULT_UPDATE_MANIFEST_URL
                    );
                    $message .= '<div class="alert alert-success">'
                        . $this->l('Update source saved.')
                        . '</div>';
                }
            }
        }

        $generatedEmployeeToken = null;
        if (Tools::isSubmit('submitAiBridgeGenerateEmployeeToken')) {
            $submittedToken = (string) Tools::getValue('token');
            $employeeId = (int) Tools::getValue('id_employee');

            if (!hash_equals($adminToken, $submittedToken)) {
                $message .= '<div class="alert alert-danger">'
                    . $this->l('Invalid security token.')
                    . '</div>';
            } elseif ($employeeId <= 0 || !Validate::isLoadedObject(new Employee($employeeId))) {
                $message .= '<div class="alert alert-danger">'
                    . $this->l('Invalid employee.')
                    . '</div>';
            } else {
                $generatedEmployeeToken = AiBridgeEmployeeToken::generateForEmployee($employeeId);
                $message .= $generatedEmployeeToken !== null
                    ? '<div class="alert alert-success">'
                        . $this->l('Employee token generated. Copy it now, it will not be shown again:')
                        . ' <code>' . Tools::safeOutput($generatedEmployeeToken) . '</code></div>'
                    : '<div class="alert alert-danger">'
                        . $this->l('The employee token could not be generated.')
                        . '</div>';
            }
        }

        if (Tools::isSubmit('submitAiBridgeRevokeEmployeeToken')) {
            $submittedToken = (string) Tools::getValue('token');
            $employeeId = (int) Tools::getValue('id_employee');

            if (!hash_equals($adminToken, $submittedToken)) {
                $message .= '<div class="alert alert-danger">'
                    . $this->l('Invalid security token.')
                    . '</div>';
            } else {
                $message .= AiBridgeEmployeeToken::revokeForEmployee($employeeId)
                    ? '<div class="alert alert-success">' . $this->l('Employee token revoked.') . '</div>'
                    : '<div class="alert alert-danger">' . $this->l('The employee token could not be revoked.') . '</div>';
            }
        }

        $updateLog = array();
        if (Tools::isSubmit('submitAiBridgeSelfUpdate')) {
            $submittedToken = (string) Tools::getValue('token');

            if (!hash_equals($adminToken, $submittedToken)) {
                $message .= '<div class="alert alert-danger">'
                    . $this->l('Invalid security token.')
                    . '</div>';
            } else {
                $manifest = (new AiBridgeSelfUpdater())->fetchManifest($this->getUpdateManifestUrl());

                if ($manifest === null) {
                    $message .= '<div class="alert alert-danger">'
                        . $this->l('Could not read the update manifest.')
                        . '</div>';
                } elseif (!(new AiBridgeSelfUpdater())->hasUpdate($this->version, $manifest)) {
                    $message .= '<div class="alert alert-info">'
                        . $this->l('The module is already up to date.')
                        . '</div>';
                } else {
                    $updater = new AiBridgeSelfUpdater();
                    $success = $updater->performUpdate($manifest, $updateLog);
                    $message .= '<div class="alert ' . ($success ? 'alert-success' : 'alert-danger') . '">'
                        . ($success ? $this->l('Update completed.') : $this->l('Update failed and was rolled back if possible.'))
                        . '</div>';
                }
            }
        }

        if (!$this->hasValidApiToken()) {
            return $message . '<div class="alert alert-warning">'
                . $this->l('El token API no existe o no es válido.')
                . '</div>';
        }

        $token = (string) Configuration::get(self::API_TOKEN_CONFIGURATION_KEY);
        $escapedToken = Tools::safeOutput($token);
        $baseUrl = rtrim(Tools::getShopDomainSsl(true, true), '/');
        $exampleUrl = $baseUrl . '/index.php?fc=module&module=aibridge&controller=ping';

        return $message . '<div class="panel">'
            . '<h3>' . $this->l('Conexión de IA Codex') . '</h3>'
            . '<p>' . $this->l('Utiliza este valor en la cabecera X-AI-Bridge-Token.') . '</p>'
            . '<div class="form-group"><label for="aibridge-api-token">'
            . $this->l('Token API') . '</label>'
            . '<input id="aibridge-api-token" type="text" class="form-control" value="'
            . $escapedToken . '" readonly="readonly" autocomplete="off" spellcheck="false" />'
            . '</div><p class="help-block">'
            . $this->l('Mantén este token en secreto.') . '</p>'
            . '<div class="form-group"><label for="aibridge-api-url">'
            . $this->l('URL base a entregar al agente de IA') . '</label>'
            . '<input id="aibridge-api-url" type="text" class="form-control" value="'
            . Tools::safeOutput($baseUrl) . '" readonly="readonly" />'
            . '</div><p class="help-block">'
            . $this->l('Ejemplo de llamada completa (ping):') . ' <code>'
            . Tools::safeOutput($exampleUrl) . '</code></p>'
            . '<form method="post"><input type="hidden" name="token" value="'
            . Tools::safeOutput($adminToken) . '" />'
            . '<button type="submit" name="submitAiBridgeRegenerateToken" class="btn btn-warning">'
            . $this->l('Regenerar token API') . '</button></form>'
            . '<hr /><form method="post">'
            . '<input type="hidden" name="token" value="' . Tools::safeOutput($adminToken) . '" />'
            . '<div class="checkbox"><label>'
            . '<input type="checkbox" name="aibridge_direct_apply_test_mode" value="1"'
            . ($this->isDirectApplyTestMode() ? ' checked="checked"' : '')
            . ' /> ' . $this->l('Test mode: apply valid API changes immediately without human approval.')
            . '</label></div>'
            . '<button type="submit" name="submitAiBridgeDirectApplyTestMode" class="btn btn-danger">'
            . $this->l('Save test execution mode') . '</button></form></div>'
            . $this->renderEmployeeTokensPanel($adminToken)
            . $this->renderUpdatePanel($adminToken, $updateLog);
    }

    private function renderEmployeeTokensPanel($adminToken)
    {
        $employees = Db::getInstance()->executeS(
            'SELECT `id_employee`, `firstname`, `lastname`, `email`, `active`
            FROM `' . _DB_PREFIX_ . 'employee`
            ORDER BY `lastname` ASC, `firstname` ASC'
        );

        $html = '<div class="panel"><h3>' . $this->l('Tokens por empleado') . '</h3>'
            . '<p class="help-block">' . $this->l('Cada empleado puede tener su propio token API (cabecera X-AI-Bridge-Token), separado del token global de arriba. Útil para que varios agentes de IA actúen identificados como personas distintas.') . '</p>';

        if (!is_array($employees) || !$employees) {
            $html .= '<p class="help-block">' . $this->l('No hay empleados registrados en esta tienda.') . '</p></div>';

            return $html;
        }

        $html .= '<table class="table"><thead><tr>'
            . '<th>' . $this->l('Empleado') . '</th>'
            . '<th>' . $this->l('Estado del token') . '</th>'
            . '<th>' . $this->l('Acciones') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($employees as $employee) {
            $employeeId = (int) $employee['id_employee'];
            $label = Tools::safeOutput(trim($employee['firstname'] . ' ' . $employee['lastname']))
                . ' <span class="text-muted">(' . Tools::safeOutput($employee['email']) . ')</span>';
            $hasToken = AiBridgeEmployeeToken::hasToken($employeeId);

            $html .= '<tr><td>' . $label . '</td>'
                . '<td>' . ($hasToken
                    ? '<span class="label label-success">' . $this->l('Configurado') . '</span>'
                    : '<span class="label label-default">' . $this->l('Sin token') . '</span>')
                . '</td><td>'
                . '<form method="post" style="display:inline-block;margin-right:5px;" onsubmit="return confirm(\''
                . $this->l('Esto invalida el token anterior de este empleado. ¿Continuar?') . '\');">'
                . '<input type="hidden" name="token" value="' . Tools::safeOutput($adminToken) . '" />'
                . '<input type="hidden" name="id_employee" value="' . $employeeId . '" />'
                . '<button type="submit" name="submitAiBridgeGenerateEmployeeToken" class="btn btn-xs btn-warning">'
                . ($hasToken ? $this->l('Regenerar') : $this->l('Generar')) . '</button></form>';

            if ($hasToken) {
                $html .= '<form method="post" style="display:inline-block;" onsubmit="return confirm(\''
                    . $this->l('¿Revocar el token de este empleado?') . '\');">'
                    . '<input type="hidden" name="token" value="' . Tools::safeOutput($adminToken) . '" />'
                    . '<input type="hidden" name="id_employee" value="' . $employeeId . '" />'
                    . '<button type="submit" name="submitAiBridgeRevokeEmployeeToken" class="btn btn-xs btn-default">'
                    . $this->l('Revocar') . '</button></form>';
            }

            $html .= '</td></tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    private function getUpdateManifestUrl()
    {
        $url = (string) Configuration::get(self::UPDATE_MANIFEST_URL_CONFIGURATION_KEY);

        return $url !== '' ? $url : self::DEFAULT_UPDATE_MANIFEST_URL;
    }

    private function renderUpdatePanel($adminToken, array $updateLog)
    {
        $manifestUrl = $this->getUpdateManifestUrl();
        $updater = new AiBridgeSelfUpdater();
        $manifest = $updater->fetchManifest($manifestUrl);
        $hasUpdate = $manifest !== null && $updater->hasUpdate($this->version, $manifest);

        $html = '<div class="panel"><h3>' . $this->l('Actualizaciones del módulo') . '</h3>'
            . '<p>' . $this->l('Versión instalada:') . ' <strong>' . Tools::safeOutput($this->version) . '</strong></p>';

        if ($manifest === null) {
            $html .= '<div class="alert alert-warning">'
                . $this->l('No se pudo consultar la fuente de actualizaciones ahora mismo.')
                . '</div>';
        } elseif ($hasUpdate) {
            $html .= '<div class="alert alert-info">'
                . sprintf($this->l('Hay una versión nueva disponible: %s'), Tools::safeOutput((string) $manifest['version']))
                . (isset($manifest['changelog']) && is_string($manifest['changelog'])
                    ? '<br />' . Tools::safeOutput($manifest['changelog'])
                    : '')
                . '</div>'
                . '<form method="post">'
                . '<input type="hidden" name="token" value="' . Tools::safeOutput($adminToken) . '" />'
                . '<button type="submit" name="submitAiBridgeSelfUpdate" class="btn btn-success" '
                . 'onclick="return confirm(\'' . $this->l('Se creará un respaldo antes de actualizar. ¿Continuar?') . '\');">'
                . $this->l('Actualizar ahora') . '</button></form>';
        } else {
            $html .= '<p class="help-block">' . $this->l('El módulo ya está en la última versión disponible.') . '</p>';
        }

        if (!empty($updateLog)) {
            $html .= '<hr /><p><strong>' . $this->l('Detalle de la última actualización:') . '</strong></p><ul>';
            foreach ($updateLog as $line) {
                $html .= '<li>' . Tools::safeOutput((string) $line) . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '<hr /><form method="post">'
            . '<input type="hidden" name="token" value="' . Tools::safeOutput($adminToken) . '" />'
            . '<div class="form-group"><label for="aibridge-update-manifest-url">'
            . $this->l('Fuente de actualizaciones (URL del manifiesto)') . '</label>'
            . '<input id="aibridge-update-manifest-url" type="text" class="form-control" name="aibridge_update_manifest_url" value="'
            . Tools::safeOutput($manifestUrl) . '" /></div>'
            . '<button type="submit" name="submitAiBridgeUpdateManifestUrl" class="btn btn-default">'
            . $this->l('Guardar fuente') . '</button></form></div>';

        return $html;
    }

    public function isDirectApplyTestMode()
    {
        return (bool) Configuration::get(self::DIRECT_APPLY_TEST_MODE_CONFIGURATION_KEY);
    }
    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        if (!(include __DIR__ . '/sql/install.php')) {
            parent::uninstall();
            return false;
        }

        if (!$this->installApprovalsTab()) {
            parent::uninstall();
            return false;
        }

        $this->installChatTab();
        $this->installAiProvidersTab();
        $this->registerHook('displayBackOfficeHeader');

        if ($this->hasValidApiToken()) {
            return true;
        }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (\Throwable $exception) {
            Configuration::deleteByName(self::API_TOKEN_CONFIGURATION_KEY);
            $this->uninstallApprovalsTab();
            parent::uninstall();

            return false;
        }

        if (!Configuration::updateValue(
            self::API_TOKEN_CONFIGURATION_KEY,
            $token
        )) {
            Configuration::deleteByName(self::API_TOKEN_CONFIGURATION_KEY);
            $this->uninstallApprovalsTab();
            parent::uninstall();

            return false;
        }

        return true;
    }

    public function uninstall()
    {
        $configurationDeleted = true;

        if (Configuration::hasKey(self::API_TOKEN_CONFIGURATION_KEY)) {
            $configurationDeleted = Configuration::deleteByName(
                self::API_TOKEN_CONFIGURATION_KEY
            );
        }

$uploadsDeleted = (bool) include __DIR__ . '/sql/uninstall.php';

        $this->uninstallApprovalsTab();
        $this->uninstallChatTab();
        $this->uninstallAiProvidersTab();

        $moduleUninstalled = parent::uninstall();

        return $configurationDeleted && $uploadsDeleted && $moduleUninstalled;
    }

    private function hasValidApiToken()
    {
        $token = Configuration::get(self::API_TOKEN_CONFIGURATION_KEY);

        return is_string($token)
            && preg_match('/\A[0-9a-f]{64}\z/', $token) === 1;
    }

    public function isValidApiToken()
    {
        $providedToken = isset($_SERVER['HTTP_X_AI_BRIDGE_TOKEN'])
            ? trim((string) $_SERVER['HTTP_X_AI_BRIDGE_TOKEN'])
            : '';

        if ($providedToken === '' && function_exists('getallheaders')) {
            $headers = getallheaders();

            if (is_array($headers)) {
                foreach ($headers as $headerName => $headerValue) {
                    if (strcasecmp($headerName, 'X-AI-Bridge-Token') === 0) {
                        $providedToken = trim((string) $headerValue);
                        break;
                    }
                }
            }
        }

        if ($providedToken === '') {
            return false;
        }

        $storedToken = (string) Configuration::get(self::API_TOKEN_CONFIGURATION_KEY);

        if ($storedToken !== '' && hash_equals($storedToken, $providedToken)) {
            $this->authenticatedEmployeeId = 0;

            return true;
        }

        $employeeId = AiBridgeEmployeeToken::findEmployeeIdByToken($providedToken);

        if ($employeeId !== null) {
            $this->authenticatedEmployeeId = $employeeId;

            return true;
        }

        return false;
    }

    /**
     * 0 means the request used the legacy shared token (no specific
     * employee identity), kept for backward compatibility with existing
     * integrations. Any positive id means a per-employee token matched.
     */
    public function getAuthenticatedEmployeeId()
    {
        return (int) $this->authenticatedEmployeeId;
    }
    private function installApprovalsTab()
    {
        if ((int) Tab::getIdFromClassName('AdminAiBridgeApprovals')) {
            return true;
        }

        $parentId = (int) Tab::getIdFromClassName('AdminParentModulesSf');

        if (!$parentId) {
            PrestaShopLogger::addLog(
                'AI Bridge approvals tab parent was not found.',
                3
            );

            return false;
        }

        $tab = new Tab();
        $tab->class_name = 'AdminAiBridgeApprovals';
        $tab->module = $this->name;
        $tab->id_parent = $parentId;

        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'AI Bridge Approvals';
        }

        if (!$tab->add()) {
            PrestaShopLogger::addLog(
                'AI Bridge approvals tab could not be created.',
                3
            );

            return false;
        }

        return true;
    }

    private function uninstallApprovalsTab()
    {
        $id = (int) Tab::getIdFromClassName('AdminAiBridgeApprovals'); if (!$id) { return true; } return (new Tab($id))->delete();
    }

    private function installChatTab()
    {
        if ((int) Tab::getIdFromClassName('AdminAiBridgeChat')) {
            return true;
        }

        $parentId = (int) Tab::getIdFromClassName('AdminParentModulesSf');

        if (!$parentId) {
            PrestaShopLogger::addLog('AI Bridge chat tab parent was not found.', 3);

            return false;
        }

        $tab = new Tab();
        $tab->class_name = 'AdminAiBridgeChat';
        $tab->module = $this->name;
        $tab->id_parent = $parentId;

        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'AI Bridge Chat';
        }

        if (!$tab->add()) {
            PrestaShopLogger::addLog('AI Bridge chat tab could not be created.', 3);

            return false;
        }

        return true;
    }

    private function uninstallChatTab()
    {
        $id = (int) Tab::getIdFromClassName('AdminAiBridgeChat'); if (!$id) { return true; } return (new Tab($id))->delete();
    }

    public function installAiProvidersTab()
    {
        // Nested under "AI Bridge Chat" (not a top-level sibling under
        // Modules) because provider configuration only exists to serve the
        // chat — it has no other reason to be there.
        $parentId = (int) Tab::getIdFromClassName('AdminAiBridgeChat');

        if (!$parentId) {
            $parentId = (int) Tab::getIdFromClassName('AdminParentModulesSf');
        }

        if (!$parentId) {
            PrestaShopLogger::addLog('AI Bridge AI providers tab parent was not found.', 3);

            return false;
        }

        $existingId = (int) Tab::getIdFromClassName('AdminAiBridgeAiProviders');

        if ($existingId) {
            // A direct UPDATE, not Tab::save() — Tab's own save/update path
            // recalculates sibling positions and on at least one production
            // MariaDB version produced a malformed query ("... near 'LIMIT
            // 1' at line 1") that aborted the whole self-update. Re-parenting
            // is cosmetic; it must never be able to fail an upgrade.
            try {
                Db::getInstance()->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'tab` SET `id_parent` = ' . (int) $parentId
                    . ' WHERE `id_tab` = ' . (int) $existingId
                );
            } catch (\Throwable $exception) {
                PrestaShopLogger::addLog('AI Bridge AI providers tab re-parent failed: ' . $exception->getMessage(), 2);
            }

            return true;
        }

        $tab = new Tab();
        $tab->class_name = 'AdminAiBridgeAiProviders';
        $tab->module = $this->name;
        $tab->id_parent = $parentId;

        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'Proveedores de IA';
        }

        if (!$tab->add()) {
            PrestaShopLogger::addLog('AI Bridge AI providers tab could not be created.', 3);

            return false;
        }

        return true;
    }

    private function uninstallAiProvidersTab()
    {
        $id = (int) Tab::getIdFromClassName('AdminAiBridgeAiProviders'); if (!$id) { return true; } return (new Tab($id))->delete();
    }

    /**
     * Self-healing bootstrap: this module is deployed to saruia.es by
     * copying files over FTP, not through PrestaShop's module upgrade flow,
     * so a version bump alone does not register the new tab/hook needed for
     * the chat widget. Running this whenever the config page loads means it
     * gets wired up the first time an admin opens it, no manual DB step or
     * package release needed.
     */
    private function ensureChatWidgetInstalled()
    {
        try {
            $this->installChatTab();
            $this->installAiProvidersTab();
            $this->registerHook('displayBackOfficeHeader');
            Db::getInstance()->execute(
                'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'aibridge_ai_provider` (
                    `id_aibridge_ai_provider` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` VARCHAR(64) NOT NULL,
                    `provider_type` VARCHAR(32) NOT NULL,
                    `api_key` VARCHAR(255) NOT NULL DEFAULT \'\',
                    `base_url` VARCHAR(255) NULL,
                    `model` VARCHAR(128) NOT NULL,
                    `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                    `is_default` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL,
                    `updated_at` DATETIME NOT NULL,
                    PRIMARY KEY (`id_aibridge_ai_provider`)
                ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
            );
        } catch (\Throwable $exception) {
            PrestaShopLogger::addLog('AI Bridge chat widget bootstrap failed: ' . $exception->getMessage(), 3);
        }
    }

    public function hookDisplayBackOfficeHeader()
    {
        if (!isset($this->context->employee) || !(int) $this->context->employee->id) {
            return '';
        }

        $ajaxUrl = $this->context->link->getAdminLink('AdminAiBridgeChat');
        $version = Tools::safeOutput($this->version);

        return '<link rel="stylesheet" href="' . Tools::safeOutput($this->_path . 'views/css/aibridge-chat-widget.css') . '?v=' . $version . '">'
            . '<script>window.AiBridgeChatConfig = ' . json_encode(array('ajaxUrl' => $ajaxUrl)) . ';</script>'
            . '<script src="' . Tools::safeOutput($this->_path . 'views/js/aibridge-chat-widget.js') . '?v=' . $version . '"></script>';
    }
}
