<?php

/**
 * One overwritable message history per employee (not an ObjectModel: the
 * primary key is id_employee itself, not an auto-increment id).
 */
class AiBridgeConversation
{
    public const MAX_MESSAGES_JSON_BYTES = 524288;

    public static function get($employeeId)
    {
        self::ensureTable();

        $employeeId = (int) $employeeId;
        $row = Db::getInstance()->getRow(
            'SELECT `messages_json`, `updated_at` FROM `' . _DB_PREFIX_
            . 'aibridge_conversation` WHERE `id_employee` = ' . $employeeId
        );

        if (!is_array($row)) {
            return null;
        }

        return array(
            'messages_json' => (string) $row['messages_json'],
            'updated_at' => (string) $row['updated_at'],
        );
    }

    public static function save($employeeId, $messagesJson)
    {
        self::ensureTable();

        $employeeId = (int) $employeeId;
        if ($employeeId < 0 || !is_string($messagesJson)
            || strlen($messagesJson) > self::MAX_MESSAGES_JSON_BYTES) {
            return false;
        }

        $db = Db::getInstance();
        $exists = (bool) $db->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'aibridge_conversation` WHERE `id_employee` = ' . $employeeId
        );

        if ($exists) {
            return $db->update(
                'aibridge_conversation',
                array(
                    'messages_json' => pSQL($messagesJson, true),
                    'updated_at' => date('Y-m-d H:i:s'),
                ),
                '`id_employee` = ' . $employeeId
            );
        }

        return $db->insert('aibridge_conversation', array(
            'id_employee' => $employeeId,
            'messages_json' => pSQL($messagesJson, true),
            'updated_at' => date('Y-m-d H:i:s'),
        ));
    }

    public static function delete($employeeId)
    {
        self::ensureTable();

        return Db::getInstance()->delete(
            'aibridge_conversation',
            '`id_employee` = ' . (int) $employeeId
        );
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
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'aibridge_conversation` (
                `id_employee` INT UNSIGNED NOT NULL,
                `messages_json` LONGTEXT NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_employee`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }
}
