<?php

function upgrade_module_1_15_0($module)
{
    $employeeTokenTableCreated = Db::getInstance()->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'aibridge_employee_token` (
            `id_aibridge_employee_token` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_employee` INT UNSIGNED NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `created_at` DATETIME NOT NULL,
            `last_used_at` DATETIME NULL,
            PRIMARY KEY (`id_aibridge_employee_token`),
            UNIQUE KEY `id_employee` (`id_employee`),
            UNIQUE KEY `token_hash` (`token_hash`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
    );

    $conversationTableCreated = Db::getInstance()->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'aibridge_conversation` (
            `id_employee` INT UNSIGNED NOT NULL,
            `messages_json` LONGTEXT NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_employee`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
    );

    return $employeeTokenTableCreated && $conversationTableCreated;
}
