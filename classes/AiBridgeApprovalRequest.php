<?php

class AiBridgeApprovalRequest extends ObjectModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_EXECUTING = 'executing';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_FAILED = 'failed';

    public const OPERATION_UPDATE = 'update';
    public const OPERATION_CREATE = 'create';
    public const OPERATION_CREATE_CATEGORY = 'create_category';
    public const OPERATION_UPDATE_CATEGORY = 'update_category';
    public const OPERATION_CREATE_MANUFACTURER = 'create_manufacturer';
    public const OPERATION_CREATE_ADDRESS = 'create_address';
    public const OPERATION_UPDATE_ADDRESS = 'update_address';
    public const OPERATION_CREATE_EMAIL_TEMPLATE = 'create_email_template';
    public const OPERATION_SEND_EMAIL = 'send_email';

    public $id_aibridge_approval_request;
    public $uuid;
    public $status;
    public $operation_type;
    public $product_id;
    public $created_product_id;
    public $shop_id;
    public $language_id;
    public $payload_json;
    public $diff_json;
    public $payload_hash;
    public $product_date_upd_snapshot;
    public $created_by_employee_id;
    public $created_at;
    public $expires_at;
    public $approved_by_employee_id;
    public $approved_at;
    public $rejected_by_employee_id;
    public $rejected_at;
    public $consumed_at;
    public $executed_by_employee_id;
    public $executed_at;
    public $execution_status;
    public $execution_error;

    public static $definition = array(
        'table' => 'aibridge_approval_request',
        'primary' => 'id_aibridge_approval_request',
        'fields' => array(
            'uuid' => array('type' => self::TYPE_STRING, 'required' => true),
            'status' => array('type' => self::TYPE_STRING, 'required' => true),
            'operation_type' => array('type' => self::TYPE_STRING, 'required' => true),
            'product_id' => array('type' => self::TYPE_INT, 'allow_null' => true),
            'created_product_id' => array('type' => self::TYPE_INT, 'allow_null' => true),
            'shop_id' => array('type' => self::TYPE_INT, 'required' => true),
            'language_id' => array('type' => self::TYPE_INT, 'required' => true),
            'payload_json' => array('type' => self::TYPE_HTML),
            'diff_json' => array('type' => self::TYPE_HTML),
            'payload_hash' => array('type' => self::TYPE_STRING),
            'product_date_upd_snapshot' => array('type' => self::TYPE_DATE, 'allow_null' => true),
            'created_by_employee_id' => array('type' => self::TYPE_INT),
            'created_at' => array('type' => self::TYPE_DATE, 'required' => true),
            'expires_at' => array('type' => self::TYPE_DATE, 'required' => true),
            'approved_by_employee_id' => array('type' => self::TYPE_INT, 'allow_null' => true),
            'approved_at' => array('type' => self::TYPE_DATE, 'allow_null' => true),
            'rejected_by_employee_id' => array('type' => self::TYPE_INT, 'allow_null' => true),
            'rejected_at' => array('type' => self::TYPE_DATE, 'allow_null' => true),
            'consumed_at' => array('type' => self::TYPE_DATE, 'allow_null' => true),
            'executed_by_employee_id' => array('type' => self::TYPE_INT, 'allow_null' => true),
            'executed_at' => array('type' => self::TYPE_DATE, 'allow_null' => true),
            'execution_status' => array('type' => self::TYPE_STRING, 'allow_null' => true),
            'execution_error' => array('type' => self::TYPE_HTML, 'allow_null' => true),
        ),
    );

    public static function createPending(array $preview, array $payload, $employeeId)
    {
        if (empty($preview['id']) || (int) $preview['id'] <= 0) {
            throw new InvalidArgumentException('Update approval requires a product.');
        }

        $request = self::createBasePending($preview, $payload, $employeeId);
        $request->operation_type = self::OPERATION_UPDATE;
        $request->product_id = (int) $preview['id'];
        $request->created_product_id = null;
        $request->product_date_upd_snapshot = (string) $preview['product_date_upd_snapshot'];

        return $request;
    }

    public static function createPendingProductCreate(array $preview, array $payload, $employeeId = 0)
    {
        if (empty($preview['shop_id']) || empty($preview['language_id'])) {
            throw new InvalidArgumentException('Create approval requires shop and language context.');
        }

        $request = self::createBasePending($preview, $payload, $employeeId);
        $request->operation_type = self::OPERATION_CREATE;
        $request->product_id = null;
        $request->created_product_id = null;
        $request->product_date_upd_snapshot = null;

        return $request;
    }

    public static function createPendingCategoryCreate(array $preview, array $payload, $employeeId = 0)
    {
        if (empty($preview['shop_id']) || empty($preview['language_id'])) {
            throw new InvalidArgumentException('Create approval requires shop and language context.');
        }

        $request = self::createBasePending($preview, $payload, $employeeId);
        $request->operation_type = self::OPERATION_CREATE_CATEGORY;
        $request->product_id = null;
        $request->created_product_id = null;
        $request->product_date_upd_snapshot = null;

        return $request;
    }

    /**
     * $preview['id'] here is the target category id; reuses the same
     * product_id/product_date_upd_snapshot columns as product updates.
     */
    public static function createPendingCategoryUpdate(array $preview, array $payload, $employeeId = 0)
    {
        if (empty($preview['id']) || (int) $preview['id'] <= 0) {
            throw new InvalidArgumentException('Update approval requires a category.');
        }

        $request = self::createBasePending($preview, $payload, $employeeId);
        $request->operation_type = self::OPERATION_UPDATE_CATEGORY;
        $request->product_id = (int) $preview['id'];
        $request->created_product_id = null;
        $request->product_date_upd_snapshot = (string) $preview['category_date_upd_snapshot'];

        return $request;
    }

    public static function createPendingManufacturerCreate(array $preview, array $payload, $employeeId = 0)
    {
        if (empty($preview['shop_id']) || empty($preview['language_id'])) {
            throw new InvalidArgumentException('Create approval requires shop and language context.');
        }

        $request = self::createBasePending($preview, $payload, $employeeId);
        $request->operation_type = self::OPERATION_CREATE_MANUFACTURER;
        $request->product_id = null;
        $request->created_product_id = null;
        $request->product_date_upd_snapshot = null;

        return $request;
    }

    public static function createPendingAddressCreate(array $preview, array $payload, $employeeId = 0)
    {
        if (empty($preview['shop_id']) || empty($preview['language_id'])) {
            throw new InvalidArgumentException('Create approval requires shop and language context.');
        }

        $request = self::createBasePending($preview, $payload, $employeeId);
        $request->operation_type = self::OPERATION_CREATE_ADDRESS;
        $request->product_id = null;
        $request->created_product_id = null;
        $request->product_date_upd_snapshot = null;

        return $request;
    }

    /**
     * $preview['id'] here is the target address id; reuses the same
     * product_id/product_date_upd_snapshot columns as product/category updates.
     */
    public static function createPendingAddressUpdate(array $preview, array $payload, $employeeId = 0)
    {
        if (empty($preview['id']) || (int) $preview['id'] <= 0) {
            throw new InvalidArgumentException('Update approval requires an address.');
        }

        $request = self::createBasePending($preview, $payload, $employeeId);
        $request->operation_type = self::OPERATION_UPDATE_ADDRESS;
        $request->product_id = (int) $preview['id'];
        $request->created_product_id = null;
        $request->product_date_upd_snapshot = (string) $preview['address_date_upd_snapshot'];

        return $request;
    }

    public static function createPendingEmailTemplateCreate(array $preview, array $payload, $employeeId = 0)
    {
        if (empty($preview['shop_id']) || empty($preview['language_id'])) {
            throw new InvalidArgumentException('Create approval requires shop and language context.');
        }

        $request = self::createBasePending($preview, $payload, $employeeId);
        $request->operation_type = self::OPERATION_CREATE_EMAIL_TEMPLATE;
        $request->product_id = null;
        $request->created_product_id = null;
        $request->product_date_upd_snapshot = null;

        return $request;
    }

    public static function createPendingEmailSend(array $preview, array $payload, $employeeId = 0)
    {
        if (empty($preview['shop_id']) || empty($preview['language_id'])) {
            throw new InvalidArgumentException('Send approval requires shop and language context.');
        }

        $request = self::createBasePending($preview, $payload, $employeeId);
        $request->operation_type = self::OPERATION_SEND_EMAIL;
        $request->product_id = null;
        $request->created_product_id = null;
        $request->product_date_upd_snapshot = null;

        return $request;
    }

    private static function createBasePending(array $preview, array $payload, $employeeId)
    {
        $request = new self();
        $request->uuid = bin2hex(random_bytes(32));
        $request->status = self::STATUS_PENDING;
        $request->shop_id = (int) $preview['shop_id'];
        $request->language_id = (int) $preview['language_id'];
        $request->payload_json = json_encode($payload);
        $request->diff_json = json_encode($preview['changes']);
        $request->payload_hash = (string) $preview['payload_hash'];
        $request->created_by_employee_id = (int) $employeeId;
        $request->created_at = date('Y-m-d H:i:s');
        $request->expires_at = date('Y-m-d H:i:s', time() + 1800);

        return $request;
    }

    public function approveForDirectApiExecution()
    {
        if ($this->status !== self::STATUS_PENDING
            || strtotime($this->expires_at) < time()) {
            return false;
        }

        $this->status = self::STATUS_APPROVED;
        $this->approved_by_employee_id = null;
        $this->approved_at = null;

        return $this->update();
    }

    public static function findIdByUuid($uuid)
    {
        if (!is_string($uuid) || preg_match('/\A[0-9a-f]{64}\z/', $uuid) !== 1) {
            return 0;
        }

        return (int) Db::getInstance()->getValue(
            "SELECT `id_aibridge_approval_request` FROM `" . _DB_PREFIX_
            . "aibridge_approval_request` WHERE `uuid` = '" . pSQL($uuid) . "'"
        );
    }

    private function transition($from, $to)
    {
        if ($this->status !== $from) {
            return false;
        }

        $this->status = $to;

        return $this->update();
    }

    public function expire()
    {
        return strtotime($this->expires_at) < time()
            ? $this->transition(self::STATUS_PENDING, self::STATUS_EXPIRED)
            : false;
    }

    public function approve($employeeId)
    {
        if ((int) $employeeId <= 0) {
            return false;
        }

        if (strtotime($this->expires_at) < time()) {
            return $this->expire();
        }

        if ((int) $this->created_by_employee_id > 0
            && (int) $employeeId === (int) $this->created_by_employee_id) {
            return false;
        }

        $this->approved_by_employee_id = (int) $employeeId;
        $this->approved_at = date('Y-m-d H:i:s');

        return $this->transition(self::STATUS_PENDING, self::STATUS_APPROVED);
    }

    public function reject($employeeId)
    {
        if ((int) $employeeId <= 0
            || ((int) $this->created_by_employee_id > 0
                && (int) $employeeId === (int) $this->created_by_employee_id)) {
            return false;
        }

        $this->rejected_by_employee_id = (int) $employeeId;
        $this->rejected_at = date('Y-m-d H:i:s');

        return $this->transition(self::STATUS_PENDING, self::STATUS_REJECTED);
    }

    public function consume()
    {
        if (strtotime($this->expires_at) < time()) {
            return false;
        }

        $this->consumed_at = date('Y-m-d H:i:s');

        return $this->transition(self::STATUS_APPROVED, self::STATUS_CONSUMED);
    }
}