<?php

require_once dirname(__FILE__) . '/AiBridgeExecutionLog.php';

class AiBridgeCategoryExecutor
{
    private const ALLOWED_FIELDS = array('id_parent', 'name', 'link_rewrite', 'description', 'meta_title', 'meta_description', 'meta_keywords', 'active');
    private const TEXT_FIELDS = array('name', 'link_rewrite', 'description', 'meta_title', 'meta_description', 'meta_keywords');

    public function execute(AiBridgeApprovalRequest $request, $employeeId)
    {
        if ($request->status !== AiBridgeApprovalRequest::STATUS_APPROVED
            || strtotime($request->expires_at) < time()) {
            return $this->recordFailure($request, $employeeId, 'Request is not executable.', null);
        }

        $request->status = AiBridgeApprovalRequest::STATUS_EXECUTING;
        $request->execution_status = 'executing';
        $request->executed_by_employee_id = (int) $employeeId;
        $request->execution_error = null;
        $request->executed_at = null;
        if (!$request->update()) {
            return $this->recordFailure($request, $employeeId, 'Execution state could not be saved.', $request->product_id);
        }

        $category = null;
        $snapshot = null;
        try {
            $payload = json_decode($request->payload_json, true);
            if (!is_array($payload) || !$payload || count($payload) > count(self::ALLOWED_FIELDS)) {
                throw new Exception('Invalid approved payload.');
            }

            $category = new Category((int) $request->product_id, (int) $request->language_id, (int) $request->shop_id);
            if (!Validate::isLoadedObject($category)
                || $category->date_upd !== $request->product_date_upd_snapshot) {
                throw new Exception('Category changed since approval.');
            }

            $fields = $this->validatePayload($payload, $category);
            $currentHash = hash('sha256', json_encode($this->canonicalizeCurrent($fields, $category)));
            if (!hash_equals((string) $request->payload_hash, $currentHash)) {
                throw new Exception('Payload hash mismatch.');
            }

            if (array_key_exists('id_parent', $fields)
                && !Category::checkBeforeMove((int) $category->id, (int) $fields['id_parent'])) {
                throw new Exception('Invalid or circular parent category.');
            }

            $snapshot = $this->capture($category);

            foreach ($fields as $field => $value) {
                if (in_array($field, self::TEXT_FIELDS, true)) {
                    $category->{$field} = $value;
                    continue;
                }
                $category->{$field} = $value;
            }

            if (!$category->update()) {
                throw new Exception('Category update failed.');
            }

            if (!$this->verify((int) $category->id, $fields, (int) $request->language_id, (int) $request->shop_id)) {
                throw new Exception('Category verification failed.');
            }

            $request->status = AiBridgeApprovalRequest::STATUS_EXECUTED;
            $request->execution_status = 'executed';
            $request->executed_at = date('Y-m-d H:i:s');
            $request->execution_error = null;
            if (!$request->update()) {
                throw new Exception('Execution audit update failed.');
            }

            if (!AiBridgeExecutionLog::record(
                $request->id,
                (int) $category->id,
                'apply-update-category',
                array_keys($fields),
                'success',
                null,
                $employeeId
            )) {
                throw new Exception('Execution audit log failed.');
            }

            return true;
        } catch (\Throwable $exception) {
            $error = $this->getSafeError($exception);

            if ($snapshot !== null && $category !== null && !$this->restore($category, $snapshot)) {
                $error = 'Category rollback requires manual review.';
            }

            return $this->recordFailure($request, $employeeId, $error, $request->product_id, $this->debugDetail($exception));
        }
    }

    private function validatePayload(array $payload, Category $category)
    {
        $fields = array();

        foreach ($payload as $field => $value) {
            if ($field === 'id_parent') {
                if ((is_int($value) && $value > 0) || (is_string($value) && ctype_digit($value) && (int) $value > 0)) {
                    $parentId = (int) $value;
                    if (Category::existsInDatabase($parentId)) {
                        $fields['id_parent'] = $parentId;
                        continue;
                    }
                }
                throw new Exception('Invalid approved payload.');
            }

            if (in_array($field, self::TEXT_FIELDS, true)) {
                if (is_string($value)) {
                    $fields[$field] = str_replace(array("\r\n", "\r"), "\n", $value);
                    continue;
                }
                throw new Exception('Invalid approved payload.');
            }

            if ($field === 'active') {
                if (is_bool($value)) {
                    $fields['active'] = $value ? 1 : 0;
                    continue;
                }
                if (is_int($value) && ($value === 0 || $value === 1)) {
                    $fields['active'] = $value;
                    continue;
                }
                if (is_string($value) && ($value === '0' || $value === '1')) {
                    $fields['active'] = (int) $value;
                    continue;
                }
                throw new Exception('Invalid approved payload.');
            }

            throw new Exception('Invalid approved payload.');
        }

        ksort($fields);

        return $fields;
    }

    private function canonicalizeCurrent(array $fields, Category $category)
    {
        ksort($fields);

        return $fields;
    }

    private function capture(Category $category)
    {
        $snapshot = array('id_parent' => (int) $category->id_parent, 'active' => (int) $category->active);
        foreach (self::TEXT_FIELDS as $field) {
            $snapshot[$field] = is_string($category->{$field}) ? $category->{$field} : '';
        }

        return $snapshot;
    }

    private function restore(Category $category, array $snapshot)
    {
        foreach ($snapshot as $field => $value) {
            $category->{$field} = $value;
        }

        return $category->update();
    }

    private function verify($categoryId, array $fields, $languageId, $shopId)
    {
        $category = new Category((int) $categoryId, $languageId, $shopId);
        if (!Validate::isLoadedObject($category)) {
            return false;
        }

        foreach ($fields as $field => $value) {
            $actual = in_array($field, self::TEXT_FIELDS, true) ? (string) $category->{$field} : (int) $category->{$field};
            $expected = in_array($field, self::TEXT_FIELDS, true) ? (string) $value : (int) $value;
            if ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function recordFailure(AiBridgeApprovalRequest $request, $employeeId, $error, $categoryId, $debugDetail = null)
    {
        $request->status = AiBridgeApprovalRequest::STATUS_FAILED;
        $request->execution_status = 'failed';
        $request->executed_by_employee_id = (int) $employeeId;
        $request->executed_at = date('Y-m-d H:i:s');
        $request->execution_error = $error;
        $request->update();

        AiBridgeExecutionLog::record(
            $request->id,
            $categoryId,
            'apply-update-category',
            array(),
            'failed',
            $debugDetail !== null ? $error . ' | debug: ' . $debugDetail : $error,
            $employeeId
        );

        return false;
    }

    private function debugDetail(\Throwable $exception)
    {
        return get_class($exception) . ': ' . $exception->getMessage()
            . ' @ ' . basename($exception->getFile()) . ':' . $exception->getLine();
    }

    private function getSafeError(\Throwable $exception)
    {
        $allowed = array(
            'Invalid approved payload.',
            'Payload hash mismatch.',
            'Category changed since approval.',
            'Category update failed.',
            'Category verification failed.',
            'Invalid or circular parent category.',
            'Category rollback requires manual review.',
            'Execution audit update failed.',
            'Execution audit log failed.',
            'Execution state could not be saved.',
            'Request is not executable.',
        );

        return in_array($exception->getMessage(), $allowed, true) ? $exception->getMessage() : 'Category update failed.';
    }
}
