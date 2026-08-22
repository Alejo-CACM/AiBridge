<?php

class AibridgeDiagnosticsModuleFrontController extends ModuleFrontController
{
    private const MAX_LIMIT = 100;

    public function initContent()
    {
        try {
            if (!is_object($this->module) || !method_exists($this->module, 'isValidApiToken')) {
                throw new \RuntimeException('Invalid module context.');
            }

            if (!$this->module->isValidApiToken()) {
                $this->sendJson(401, array(
                    'success' => false,
                    'error' => array('code' => 'unauthorized', 'message' => 'Invalid or missing API token.'),
                ));
            }

            $limit = min(self::MAX_LIMIT, max(1, (int) Tools::getValue('limit', 20)));

            $this->sendJson(200, array(
                'success' => true,
                'data' => array(
                    'module_version' => defined('_AIBRIDGE_VERSION_') ? _AIBRIDGE_VERSION_ : (is_object($this->module) ? $this->module->version : null),
                    'authenticated_employee_id' => method_exists($this->module, 'getAuthenticatedEmployeeId')
                        ? (int) $this->module->getAuthenticatedEmployeeId()
                        : null,
                    'debug_mode' => (bool) _PS_MODE_DEV_,
                    'direct_apply_test_mode' => is_object($this->module) && method_exists($this->module, 'isDirectApplyTestMode')
                        ? (bool) $this->module->isDirectApplyTestMode()
                        : null,
                    'last_db_error' => array(
                        'number' => (int) Db::getInstance()->getNumberError(),
                        'message' => (string) Db::getInstance()->getMsgError(),
                    ),
                    'recent_execution_logs' => $this->getRecentExecutionLogs($limit),
                    'recent_approval_requests' => $this->getRecentApprovalRequests($limit),
                ),
            ));
        } catch (\Throwable $exception) {
            $this->sendJson(500, array(
                'success' => false,
                'error' => array('code' => 'diagnostics_internal_error', 'message' => 'Diagnostics could not be generated.'),
            ));
        }
    }

    private function getRecentExecutionLogs($limit)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_aibridge_log`, `approval_request_id`, `product_id`, `operation`,
                `changed_fields_json`, `result`, `error_message`, `executed_by_employee_id`, `created_at`
            FROM `' . _DB_PREFIX_ . 'aibridge_log`
            ORDER BY `id_aibridge_log` DESC
            LIMIT ' . (int) $limit
        );

        if (!is_array($rows)) {
            return array();
        }

        $logs = array();
        foreach ($rows as $row) {
            $logs[] = array(
                'id' => (int) $row['id_aibridge_log'],
                'approval_request_id' => $row['approval_request_id'] !== null ? (int) $row['approval_request_id'] : null,
                'product_id' => $row['product_id'] !== null ? (int) $row['product_id'] : null,
                'operation' => (string) $row['operation'],
                'changed_fields' => json_decode((string) $row['changed_fields_json'], true),
                'result' => (string) $row['result'],
                'error_message' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
                'executed_by_employee_id' => (int) $row['executed_by_employee_id'],
                'created_at' => (string) $row['created_at'],
            );
        }

        return $logs;
    }

    private function getRecentApprovalRequests($limit)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_aibridge_approval_request`, `uuid`, `status`, `operation_type`,
                `product_id`, `created_product_id`, `shop_id`, `language_id`,
                `created_at`, `expires_at`, `executed_at`, `execution_status`, `execution_error`
            FROM `' . _DB_PREFIX_ . 'aibridge_approval_request`
            ORDER BY `id_aibridge_approval_request` DESC
            LIMIT ' . (int) $limit
        );

        if (!is_array($rows)) {
            return array();
        }

        $requests = array();
        foreach ($rows as $row) {
            $requests[] = array(
                'id' => (int) $row['id_aibridge_approval_request'],
                'uuid' => (string) $row['uuid'],
                'status' => (string) $row['status'],
                'operation_type' => (string) $row['operation_type'],
                'product_id' => $row['product_id'] !== null ? (int) $row['product_id'] : null,
                'created_product_id' => $row['created_product_id'] !== null ? (int) $row['created_product_id'] : null,
                'shop_id' => (int) $row['shop_id'],
                'language_id' => (int) $row['language_id'],
                'created_at' => (string) $row['created_at'],
                'expires_at' => (string) $row['expires_at'],
                'executed_at' => $row['executed_at'] !== null ? (string) $row['executed_at'] : null,
                'execution_status' => $row['execution_status'] !== null ? (string) $row['execution_status'] : null,
                'execution_error' => $row['execution_error'] !== null ? (string) $row['execution_error'] : null,
            );
        }

        return $requests;
    }

    private function sendJson($statusCode, array $payload)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}
