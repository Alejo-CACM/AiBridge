<?php

require_once dirname(__FILE__) . '/AiBridgeCategoryCreatePreview.php';
require_once dirname(__FILE__) . '/AiBridgeExecutionLog.php';

class AiBridgeCategoryCreateExecutor
{
    private const TEXT_FIELDS = array('name', 'link_rewrite', 'description', 'meta_title', 'meta_description', 'meta_keywords');

    public function execute(AiBridgeApprovalRequest $request, $employeeId)
    {
        if ($request->status !== AiBridgeApprovalRequest::STATUS_APPROVED
            || strtotime($request->expires_at) < time()
            || ($request->created_product_id !== null && $request->created_product_id !== '')) {
            return $this->recordFailure($request, $employeeId, 'Request is not executable.', null);
        }

        $request->status = AiBridgeApprovalRequest::STATUS_EXECUTING;
        $request->execution_status = 'executing';
        $request->executed_by_employee_id = (int) $employeeId;
        $request->execution_error = null;
        $request->executed_at = null;
        if (!$request->update()) {
            return $this->recordFailure($request, $employeeId, 'Execution state could not be saved.', null);
        }

        $createdCategoryId = null;
        $contextState = null;
        try {
            $payload = json_decode($request->payload_json, true);
            if (!is_array($payload)) {
                throw new Exception('Invalid approved payload.');
            }

            $preview = (new AiBridgeCategoryCreatePreview())->build($payload);
            if (!$preview['valid']) {
                throw new Exception($preview['conflict'] ? 'Category duplicate detected.' : 'Invalid approved payload.');
            }

            if (!hash_equals((string) $request->payload_hash, (string) $preview['payload_hash'])) {
                throw new Exception('Payload hash mismatch.');
            }

            $canonical = $preview['canonical_payload'];
            $contextState = $this->switchContext((int) $canonical['shop_id'], (int) $canonical['language_id']);
            $category = $this->buildCategory($canonical);

            $normalValidation = $category->validateFields(false, true);
            if ($normalValidation !== true) {
                throw new Exception('Category validation failed: ' . $this->validationField($normalValidation) . '.');
            }

            $languageValidation = $category->validateFieldsLang(false, true);
            if ($languageValidation !== true) {
                throw new Exception('Category language validation failed: ' . $this->validationField($languageValidation) . '.');
            }

            try {
                $added = $category->add();
            } catch (\PrestaShopException $exception) {
                throw new Exception('Category add hook failed.');
            }

            if (!$added) {
                $createdCategoryId = (int) $category->id > 0 ? (int) $category->id : null;
                $error = $this->categoryAddDiagnostic($category);

                return $this->handleFailure($request, $employeeId, $error, $createdCategoryId);
            }
            $createdCategoryId = (int) $category->id;

            if (!$this->verify($createdCategoryId, $canonical)) {
                throw new Exception('Category verification failed.');
            }

            $request->created_product_id = $createdCategoryId;
            $request->status = AiBridgeApprovalRequest::STATUS_EXECUTED;
            $request->execution_status = 'executed';
            $request->executed_by_employee_id = (int) $employeeId;
            $request->executed_at = date('Y-m-d H:i:s');
            $request->execution_error = null;
            if (!$request->update()) {
                throw new Exception('Execution audit update failed.');
            }

            if (!AiBridgeExecutionLog::record(
                $request->id,
                $createdCategoryId,
                'apply-create-category',
                array('category_create'),
                'success',
                null,
                $employeeId
            )) {
                throw new Exception('Execution audit log failed.');
            }

            return true;
        } catch (\PrestaShopException $exception) {
            return $this->handleFailure($request, $employeeId, 'Category validation failed: ' . $this->validationField($exception->getMessage()) . '.', $createdCategoryId, $this->debugDetail($exception));
        } catch (\Throwable $exception) {
            return $this->handleFailure($request, $employeeId, $this->safeError($exception), $createdCategoryId, $this->debugDetail($exception));
        } finally {
            if ($contextState !== null) {
                $this->restoreContext($contextState);
            }
        }
    }

    private function buildCategory(array $payload)
    {
        $category = new Category(null, (int) $payload['language_id'], (int) $payload['shop_id']);
        $category->id_parent = (int) $payload['id_parent'];
        $category->active = false;
        $category->name = $payload['name'];
        $category->link_rewrite = $payload['link_rewrite'];
        $category->description = isset($payload['description']) ? $payload['description'] : array();
        $category->meta_title = isset($payload['meta_title']) ? $payload['meta_title'] : array();
        $category->meta_description = isset($payload['meta_description']) ? $payload['meta_description'] : array();
        $category->meta_keywords = isset($payload['meta_keywords']) ? $payload['meta_keywords'] : array();

        return $category;
    }

    private function switchContext($shopId, $languageId)
    {
        $context = Context::getContext();
        $state = array(
            'shop' => $context->shop,
            'language' => $context->language,
            'shop_id' => isset($context->shop) ? (int) $context->shop->id : 0,
        );

        Shop::setContext(Shop::CONTEXT_SHOP, (int) $shopId);
        $context->shop = new Shop((int) $shopId);
        $context->language = new Language((int) $languageId);

        return $state;
    }

    private function restoreContext(array $state)
    {
        $context = Context::getContext();
        $context->shop = $state['shop'];
        $context->language = $state['language'];

        if ((int) $state['shop_id'] > 0) {
            Shop::setContext(Shop::CONTEXT_SHOP, (int) $state['shop_id']);
        }
    }

    private function categoryAddDiagnostic(Category $category)
    {
        $db = Db::getInstance();
        $number = (int) $db->getNumberError();
        $message = (string) $db->getMsgError();

        if ($number <= 0 || trim($message) === '') {
            return 'Category add returned false.';
        }

        return 'Category add database error [' . $number . ']: ' . $this->sanitizeDatabaseDescription($number, $message);
    }

    private function sanitizeDatabaseDescription($number, $message)
    {
        $message = strtolower((string) $message);
        if ((int) $number === 1062 || strpos($message, 'duplicate') !== false) {
            return 'Duplicate key constraint.';
        }
        if ((int) $number === 1048 || strpos($message, 'cannot be null') !== false) {
            return 'Required value is missing.';
        }
        if ((int) $number === 1452 || strpos($message, 'foreign key') !== false) {
            return 'Relationship constraint failed.';
        }

        return 'Database operation failed.';
    }

    private function verify($categoryId, array $payload)
    {
        $category = new Category((int) $categoryId, null, (int) $payload['shop_id']);
        if (!Validate::isLoadedObject($category)
            || (bool) $category->active !== false
            || (int) $category->id_parent !== (int) $payload['id_parent']) {
            return false;
        }

        $languageId = (int) $payload['language_id'];

        return isset($category->name[$languageId], $category->link_rewrite[$languageId])
            && $category->name[$languageId] === $payload['name'][$languageId]
            && $category->link_rewrite[$languageId] === $payload['link_rewrite'][$languageId];
    }

    private function handleFailure(AiBridgeApprovalRequest $request, $employeeId, $error, $createdCategoryId, $debugDetail = null)
    {
        if ($createdCategoryId !== null && !$this->rollback($createdCategoryId)) {
            $error = 'Category creation rollback requires manual review.';
        }

        return $this->recordFailure($request, $employeeId, $error, $createdCategoryId, $debugDetail);
    }

    private function rollback($categoryId)
    {
        $category = new Category((int) $categoryId);
        if (!Validate::isLoadedObject($category) || !$category->delete()) {
            return false;
        }

        $reloaded = new Category((int) $categoryId);

        return !Validate::isLoadedObject($reloaded);
    }

    private function recordFailure(AiBridgeApprovalRequest $request, $employeeId, $error, $categoryId, $debugDetail = null)
    {
        $request->status = AiBridgeApprovalRequest::STATUS_FAILED;
        $request->execution_status = 'failed';
        $request->created_product_id = null;
        $request->executed_by_employee_id = (int) $employeeId;
        $request->executed_at = date('Y-m-d H:i:s');
        $request->execution_error = $error;
        $request->update();

        AiBridgeExecutionLog::record(
            $request->id,
            $categoryId,
            'apply-create-category',
            array('category_create'),
            'failed',
            $debugDetail !== null ? $error . ' | debug: ' . $debugDetail : $error,
            $employeeId
        );

        return false;
    }

    private function validationField($message)
    {
        if (is_string($message) && preg_match('/Category->([A-Za-z_]+)/', $message, $matches)) {
            return $matches[1];
        }

        return 'field';
    }

    private function debugDetail(\Throwable $exception)
    {
        return get_class($exception) . ': ' . $exception->getMessage()
            . ' @ ' . basename($exception->getFile()) . ':' . $exception->getLine();
    }

    private function safeError(\Throwable $exception)
    {
        $allowed = array(
            'Request is not executable.',
            'Execution state could not be saved.',
            'Invalid approved payload.',
            'Category duplicate detected.',
            'Payload hash mismatch.',
            'Category add hook failed.',
            'Category verification failed.',
            'Execution audit update failed.',
            'Execution audit log failed.',
            'Category creation rollback requires manual review.',
        );

        $message = $exception->getMessage();
        if (preg_match('/\ACategory (?:language )?validation failed: [A-Za-z_]+\.\z/', $message)
            || preg_match('/\ACategory add database error \[\d+\]: (?:Duplicate key constraint|Required value is missing|Relationship constraint failed|Database operation failed)\.\z/', $message)) {
            return $message;
        }

        return in_array($message, $allowed, true) ? $message : 'Category add returned false.';
    }
}
