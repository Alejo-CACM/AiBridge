<?php

class AiBridgeUpload extends ObjectModel
{
    public $id_aibridge_upload;
    public $token_hash;
    public $file_path;
    public $mime;
    public $extension;
    public $file_size;
    public $width;
    public $height;
    public $status;
    public $created_at;
    public $expires_at;
    public $consumed_at;

    public static $definition = array(
        'table' => 'aibridge_upload',
        'primary' => 'id_aibridge_upload',
        'fields' => array(
            'token_hash' => array('type' => self::TYPE_STRING, 'required' => true),
            'file_path' => array('type' => self::TYPE_HTML, 'required' => true),
            'mime' => array('type' => self::TYPE_STRING, 'required' => true),
            'extension' => array('type' => self::TYPE_STRING, 'required' => true),
            'file_size' => array('type' => self::TYPE_INT, 'required' => true),
            'width' => array('type' => self::TYPE_INT, 'required' => true),
            'height' => array('type' => self::TYPE_INT, 'required' => true),
            'status' => array('type' => self::TYPE_STRING, 'required' => true),
            'created_at' => array('type' => self::TYPE_DATE, 'required' => true),
            'expires_at' => array('type' => self::TYPE_DATE, 'required' => true),
            'consumed_at' => array('type' => self::TYPE_DATE, 'allow_null' => true),
        ),
    );

    public static function findStagedById($id)
    {
        $upload = new self((int) $id);
        if (!Validate::isLoadedObject($upload)) return null;
        if (strtotime((string) $upload->expires_at) <= time()) {
            self::expireUpload($upload);
            return null;
        }
        return $upload->status === 'staged' && self::hasValidStagedPath((string) $upload->file_path)
            ? $upload : null;
    }

    public static function consume(self $upload)
    {
        if ($upload->status !== 'staged') return false;
        $upload->status = 'consumed';
        $upload->consumed_at = date('Y-m-d H:i:s');
        return $upload->update();
    }

    public static function ensureStaged($id)
    {
        $upload = new self((int) $id);
        if (!Validate::isLoadedObject($upload)) return false;
        return $upload->status === 'staged' || self::restoreStaged((int) $id);
    }
    public static function restoreStaged($id)
    {
        $upload = new self((int) $id);
        if (!Validate::isLoadedObject($upload) || $upload->status !== 'consumed') return false;
        $upload->status = 'staged';
        $upload->consumed_at = null;
        return $upload->update();
    }
    public static function findStagedByToken($token)
    {
        if (!is_string($token) || $token === '') {
            return null;
        }
        $tokenHash = hash('sha256', $token);
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_aibridge_upload` FROM `' . _DB_PREFIX_
            . 'aibridge_upload` WHERE `token_hash` = \'' . pSQL($tokenHash) . '\''
        );
        if ($id <= 0) {
            return null;
        }

        $upload = new self($id);
        if (!Validate::isLoadedObject($upload)) {
            return null;
        }
        if (strtotime((string) $upload->expires_at) <= time()) {
            self::expireUpload($upload);
            return null;
        }
        if ($upload->status !== 'staged' || !self::hasValidStagedPath((string) $upload->file_path)) {
            return null;
        }
        return $upload;
    }

    private static function expireUpload(self $upload)
    {
        $path = (string) $upload->file_path;
        if (self::hasValidStagedPath($path) && is_file($path)) {
            @unlink($path);
        }
        $upload->status = 'expired';
        $upload->update();
    }

    private static function hasValidStagedPath($path)
    {
        $root = realpath(_PS_CACHE_DIR_ . 'aibridge_uploads');
        $realPath = realpath($path);
        return $root !== false && $realPath !== false
            && strpos($realPath, $root . DIRECTORY_SEPARATOR) === 0
            && is_file($realPath);
    }
    public static function expireStagedUploads($directory)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_aibridge_upload`, `file_path` FROM `' . _DB_PREFIX_
            . 'aibridge_upload` WHERE `status` = \'staged\' AND `expires_at` < NOW()'
        );
        if (!is_array($rows)) {
            return;
        }

        $root = realpath($directory);
        foreach ($rows as $row) {
            $upload = new self((int) $row['id_aibridge_upload']);
            if (!Validate::isLoadedObject($upload)) {
                continue;
            }
            $path = (string) $upload->file_path;
            $realPath = realpath($path);
            if ($root !== false && $realPath !== false
                && strpos($realPath, $root . DIRECTORY_SEPARATOR) === 0
                && is_file($realPath)) {
                @unlink($realPath);
            }
            $upload->status = 'expired';
            $upload->update();
        }
    }
}