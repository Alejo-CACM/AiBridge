<?php

function upgrade_module_1_0_4($module)
{
    return Db::getInstance()->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'aibridge_log` (
            `id_aibridge_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `approval_request_id` INT UNSIGNED NULL,
            `product_id` INT UNSIGNED NULL,
            `operation` VARCHAR(32) NOT NULL,
            `changed_fields_json` LONGTEXT NOT NULL,
            `result` VARCHAR(16) NOT NULL,
            `error_message` TEXT NULL,
            `executed_by_employee_id` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_aibridge_log`),
            KEY `aibridge_log_request` (`approval_request_id`),
            KEY `aibridge_log_product` (`product_id`),
            KEY `aibridge_log_created` (`created_at`),
            KEY `aibridge_log_result` (`result`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
    );
}