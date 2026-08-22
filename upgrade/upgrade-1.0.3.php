<?php
function upgrade_module_1_0_3($module)
{
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'aibridge_approval_request';
    $columns = array(
        'executed_by_employee_id' => 'INT UNSIGNED NULL',
        'executed_at' => 'DATETIME NULL',
        'execution_status' => 'VARCHAR(16) NULL',
        'execution_error' => 'TEXT NULL',
    );

    foreach ($columns as $name => $definition) {
        if (!$db->getValue('SHOW COLUMNS FROM `' . $table . '` LIKE "' . pSQL($name) . '"')) {
            if (!$db->execute('ALTER TABLE `' . $table . '` ADD COLUMN `' . pSQL($name) . '` ' . $definition)) {
                return false;
            }
        }
    }

    return true;
}