<?php

$approvalTableCreated = Db::getInstance()->execute(
    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'aibridge_approval_request` (
        `id_aibridge_approval_request` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `uuid` CHAR(64) NOT NULL, `status` VARCHAR(16) NOT NULL,
        `operation_type` VARCHAR(32) NOT NULL DEFAULT \'update\',
        `product_id` INT UNSIGNED NULL, `created_product_id` INT UNSIGNED NULL,
        `shop_id` INT UNSIGNED NOT NULL,
        `language_id` INT UNSIGNED NOT NULL, `payload_json` LONGTEXT NOT NULL,
        `diff_json` LONGTEXT NOT NULL, `payload_hash` CHAR(64) NOT NULL,
        `product_date_upd_snapshot` DATETIME NULL,
        `created_by_employee_id` INT UNSIGNED NULL, `created_at` DATETIME NOT NULL,
        `expires_at` DATETIME NOT NULL, `approved_by_employee_id` INT UNSIGNED NULL,
        `approved_at` DATETIME NULL, `rejected_by_employee_id` INT UNSIGNED NULL,
        `rejected_at` DATETIME NULL, `consumed_at` DATETIME NULL,
        `executed_by_employee_id` INT UNSIGNED NULL, `executed_at` DATETIME NULL,
        `execution_status` VARCHAR(16) NULL, `execution_error` TEXT NULL,
        PRIMARY KEY (`id_aibridge_approval_request`), UNIQUE KEY `uuid` (`uuid`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
);

$logTableCreated = Db::getInstance()->execute(
    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'aibridge_log` (
        `id_aibridge_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `approval_request_id` INT UNSIGNED NULL, `product_id` INT UNSIGNED NULL,
        `operation` VARCHAR(32) NOT NULL, `changed_fields_json` LONGTEXT NOT NULL,
        `result` VARCHAR(16) NOT NULL, `error_message` TEXT NULL,
        `executed_by_employee_id` INT UNSIGNED NULL, `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id_aibridge_log`), KEY `request` (`approval_request_id`),
        KEY `product` (`product_id`), KEY `created` (`created_at`), KEY `result` (`result`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
);

$uploadTableCreated = Db::getInstance()->execute(
    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'aibridge_upload` (
        `id_aibridge_upload` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `token_hash` CHAR(64) NOT NULL,
        `file_path` TEXT NOT NULL,
        `mime` VARCHAR(64) NOT NULL,
        `extension` VARCHAR(8) NOT NULL,
        `file_size` INT UNSIGNED NOT NULL,
        `width` INT UNSIGNED NOT NULL,
        `height` INT UNSIGNED NOT NULL,
        `status` VARCHAR(16) NOT NULL,
        `created_at` DATETIME NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `consumed_at` DATETIME NULL,
        PRIMARY KEY (`id_aibridge_upload`),
        UNIQUE KEY `token_hash` (`token_hash`),
        KEY `status_expires` (`status`, `expires_at`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
);

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

$emailTemplateTableCreated = Db::getInstance()->execute(
    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'aibridge_email_template` (
        `id_aibridge_email_template` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(64) NOT NULL,
        `subject` VARCHAR(255) NOT NULL,
        `html_body` LONGTEXT NOT NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id_aibridge_email_template`),
        UNIQUE KEY `name` (`name`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
);

$aiProviderTableCreated = Db::getInstance()->execute(
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

return $approvalTableCreated && $logTableCreated && $uploadTableCreated
    && $employeeTokenTableCreated && $conversationTableCreated && $emailTemplateTableCreated
    && $aiProviderTableCreated;