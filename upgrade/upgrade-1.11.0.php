<?php

function upgrade_module_1_11_0($module)
{
    return Db::getInstance()->execute(
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
}