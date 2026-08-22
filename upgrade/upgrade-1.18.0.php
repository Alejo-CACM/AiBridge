<?php

function upgrade_module_1_18_0($module)
{
    return Db::getInstance()->execute(
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
}
