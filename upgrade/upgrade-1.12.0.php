<?php

function aibridge_1_12_0_column($column)
{
    $columns = Db::getInstance()->executeS(
        'SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'aibridge_approval_request` LIKE \'' . pSQL($column) . '\''
    );

    return is_array($columns) && !empty($columns) ? $columns[0] : null;
}

function upgrade_module_1_12_0($module)
{
    $db = Db::getInstance();

    if (!aibridge_1_12_0_column('operation_type') && !$db->execute(
        'ALTER TABLE `' . _DB_PREFIX_ . 'aibridge_approval_request` '
        . 'ADD `operation_type` VARCHAR(32) NOT NULL DEFAULT \'update\' AFTER `status`'
    )) {
        return false;
    }

    if (!aibridge_1_12_0_column('created_product_id') && !$db->execute(
        'ALTER TABLE `' . _DB_PREFIX_ . 'aibridge_approval_request` '
        . 'ADD `created_product_id` INT UNSIGNED NULL AFTER `product_id`'
    )) {
        return false;
    }

    $productIdColumn = aibridge_1_12_0_column('product_id');
    if ($productIdColumn && strtoupper((string) $productIdColumn['Null']) !== 'YES' && !$db->execute(
        'ALTER TABLE `' . _DB_PREFIX_ . 'aibridge_approval_request` '
        . 'MODIFY `product_id` INT UNSIGNED NULL'
    )) {
        return false;
    }

    return $db->execute(
        'UPDATE `' . _DB_PREFIX_ . 'aibridge_approval_request` '
        . "SET `operation_type` = 'update' "
        . "WHERE `operation_type` IS NULL OR `operation_type` = ''"
    );
}