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
        // Db::getValue()/getRow() append "LIMIT 1" unconditionally, and
        // "SHOW COLUMNS ... LIMIT 1" is invalid syntax on some MariaDB
        // versions ("near 'LIMIT 1'"). executeS() does not append it.
        $existing = $db->executeS('SHOW COLUMNS FROM `' . $table . '` LIKE "' . pSQL($name) . '"');
        if (empty($existing)) {
            if (!$db->execute('ALTER TABLE `' . $table . '` ADD COLUMN `' . pSQL($name) . '` ' . $definition)) {
                return false;
            }
        }
    }

    return true;
}