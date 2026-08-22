<?php

function upgrade_module_1_19_0($module)
{
    return Db::getInstance()->execute(
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
    ) && $module->installAiProvidersTab();
}
