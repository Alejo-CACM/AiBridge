<?php

class AiBridgeExecutionLog extends ObjectModel
{
    public $id_aibridge_log;
    public $approval_request_id;
    public $product_id;
    public $operation;
    public $changed_fields_json;
    public $result;
    public $error_message;
    public $executed_by_employee_id;
    public $created_at;

    public static $definition = array(
        'table' => 'aibridge_log',
        'primary' => 'id_aibridge_log',
        'fields' => array(
            'approval_request_id' => array('type' => self::TYPE_INT, 'allow_null' => true),
            'product_id' => array('type' => self::TYPE_INT, 'allow_null' => true),
            'operation' => array('type' => self::TYPE_STRING),
            'changed_fields_json' => array('type' => self::TYPE_HTML),
            'result' => array('type' => self::TYPE_STRING),
            'error_message' => array('type' => self::TYPE_HTML, 'allow_null' => true),
            'executed_by_employee_id' => array('type' => self::TYPE_INT),
            'created_at' => array('type' => self::TYPE_DATE),
        ),
    );

    public static function record($requestId, $productId, $operation, array $fields, $result, $error, $employeeId)
    {
        $log = new self();
        $log->approval_request_id = (int) $requestId > 0 ? (int) $requestId : null;
        $log->product_id = $productId !== null && (int) $productId > 0 ? (int) $productId : null;
        $log->operation = (string) $operation;
        $log->changed_fields_json = json_encode($fields);
        $log->result = (string) $result;
        $log->error_message = $error === null ? null : (string) $error;
        $log->executed_by_employee_id = (int) $employeeId;
        $log->created_at = date('Y-m-d H:i:s');

        return $log->add();
    }
}