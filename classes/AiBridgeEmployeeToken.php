<?php

class AiBridgeEmployeeToken extends ObjectModel
{
    public $id_employee;
    public $token_hash;
    public $created_at;
    public $last_used_at;

    public static $definition = array(
        'table' => 'aibridge_employee_token',
        'primary' => 'id_aibridge_employee_token',
        'fields' => array(
            'id_employee' => array('type' => self::TYPE_INT, 'required' => true),
            'token_hash' => array('type' => self::TYPE_STRING, 'required' => true),
            'created_at' => array('type' => self::TYPE_DATE),
            'last_used_at' => array('type' => self::TYPE_DATE, 'allow_null' => true),
        ),
    );

    /**
     * Generates (or replaces) the token for one employee and returns the raw
     * token. Only the sha256 hash is ever stored — the raw value is shown to
     * the admin once, at generation time, same as the upload token flow.
     */
    public static function generateForEmployee($employeeId)
    {
        self::ensureTable();

        $employeeId = (int) $employeeId;
        if ($employeeId <= 0) {
            return null;
        }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (\Throwable $exception) {
            return null;
        }

        $row = self::findByEmployeeId($employeeId);
        $entity = $row instanceof self ? $row : new self();
        $entity->id_employee = $employeeId;
        $entity->token_hash = hash('sha256', $token);
        $entity->created_at = date('Y-m-d H:i:s');
        $entity->last_used_at = null;

        $saved = $entity->id ? $entity->update() : $entity->add();

        return $saved ? $token : null;
    }

    public static function revokeForEmployee($employeeId)
    {
        self::ensureTable();

        $row = self::findByEmployeeId((int) $employeeId);

        return $row === null || $row->delete();
    }

    public static function hasToken($employeeId)
    {
        self::ensureTable();

        return self::findByEmployeeId((int) $employeeId) !== null;
    }

    /**
     * Resolves a raw bearer token to an employee id, or null if it doesn't
     * match any active per-employee token. Touches last_used_at on match.
     */
    public static function findEmployeeIdByToken($token)
    {
        self::ensureTable();

        if (!is_string($token) || $token === '') {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_aibridge_employee_token` FROM `' . _DB_PREFIX_
            . 'aibridge_employee_token` WHERE `token_hash` = \'' . pSQL($tokenHash) . '\''
        );

        if ($id <= 0) {
            return null;
        }

        $entity = new self($id);
        if (!Validate::isLoadedObject($entity)) {
            return null;
        }

        $entity->last_used_at = date('Y-m-d H:i:s');
        $entity->update();

        return (int) $entity->id_employee;
    }

    private static function findByEmployeeId($employeeId)
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_aibridge_employee_token` FROM `' . _DB_PREFIX_
            . 'aibridge_employee_token` WHERE `id_employee` = ' . (int) $employeeId
        );

        if ($id <= 0) {
            return null;
        }

        $entity = new self($id);

        return Validate::isLoadedObject($entity) ? $entity : null;
    }

    /**
     * Defensive safety net: this module is deployed to saruia.es by copying
     * files over FTP, not through PrestaShop's module upgrade flow, so a
     * version bump alone does not guarantee upgrade-1.15.0.php ran. Creating
     * the table here too (idempotent) means the feature works regardless.
     */
    private static function ensureTable()
    {
        Db::getInstance()->execute(
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
    }
}
