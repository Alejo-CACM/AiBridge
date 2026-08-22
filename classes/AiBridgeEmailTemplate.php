<?php

class AiBridgeEmailTemplate extends ObjectModel
{
    public $name;
    public $subject;
    public $html_body;
    public $created_at;
    public $updated_at;

    public static $definition = array(
        'table' => 'aibridge_email_template',
        'primary' => 'id_aibridge_email_template',
        'fields' => array(
            'name' => array('type' => self::TYPE_STRING, 'required' => true),
            'subject' => array('type' => self::TYPE_STRING, 'required' => true),
            'html_body' => array('type' => self::TYPE_HTML, 'required' => true),
            'created_at' => array('type' => self::TYPE_DATE, 'required' => true),
            'updated_at' => array('type' => self::TYPE_DATE, 'required' => true),
        ),
    );

    public static function findByName($name)
    {
        self::ensureTable();

        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_aibridge_email_template` FROM `' . _DB_PREFIX_
            . 'aibridge_email_template` WHERE `name` = \'' . pSQL((string) $name) . '\''
        );

        if ($id <= 0) {
            return null;
        }

        $template = new self($id);

        return Validate::isLoadedObject($template) ? $template : null;
    }

    public static function listAll()
    {
        self::ensureTable();

        $rows = Db::getInstance()->executeS(
            'SELECT `id_aibridge_email_template`, `name`, `subject`, `created_at`, `updated_at`
            FROM `' . _DB_PREFIX_ . 'aibridge_email_template`
            ORDER BY `name` ASC'
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * Replaces {{key}} placeholders with the given values. Unknown
     * placeholders are left as-is (visible in the rendered output) rather
     * than silently becoming empty strings, so a typo in a variable name is
     * obvious in the preview instead of quietly disappearing.
     */
    public static function render($text, array $variables)
    {
        $text = (string) $text;

        foreach ($variables as $key => $value) {
            $text = str_replace('{{' . $key . '}}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * Defensive safety net: this module is deployed to saruia.es by copying
     * files over FTP, not through PrestaShop's module upgrade flow, so a
     * version bump alone does not guarantee upgrade-1.18.0.php ran.
     */
    private static function ensureTable()
    {
        Db::getInstance()->execute(
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
}
