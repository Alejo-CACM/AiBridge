<?php

require_once dirname(__FILE__) . '/AiBridgeProductCreatePreview.php';
require_once dirname(__FILE__) . '/AiBridgeExecutionLog.php';
require_once dirname(__FILE__) . '/AiBridgeTagHandler.php';
require_once dirname(__FILE__) . '/AiBridgeImageHandler.php';
require_once dirname(__FILE__) . '/AiBridgeFeatureHandler.php';
require_once dirname(__FILE__) . '/AiBridgeStockHandler.php';
require_once dirname(__FILE__) . '/AiBridgeCombinationCreateHandler.php';

class AiBridgeProductCreateExecutor
{
    public function execute(AiBridgeApprovalRequest $request, $employeeId)
    {
        if ($request->status !== AiBridgeApprovalRequest::STATUS_APPROVED
            || strtotime($request->expires_at) < time()
            || ($request->product_id !== null && $request->product_id !== '')
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

        $createdProductId = null;
        $contextState = null;
        try {
            $payload = json_decode($request->payload_json, true);
            if (!is_array($payload)) {
                throw new Exception('Invalid approved payload.');
            }

            $preview = (new AiBridgeProductCreatePreview())->build($payload);
            if (!$preview['valid']) {
                throw new Exception($preview['conflict'] ? 'Product duplicate detected.' : 'Invalid approved payload.');
            }

            if (!hash_equals((string) $request->payload_hash, (string) $preview['payload_hash'])) {
                throw new Exception('Payload hash mismatch.');
            }

            $canonical = $preview['canonical_payload'];
            $contextState = $this->switchContext((int) $canonical['shop_id'], (int) $canonical['language_id']);
            $product = $this->buildProduct($canonical);

            $normalValidation = $product->validateFields(false, true);
            if ($normalValidation !== true) {
                throw new Exception('Product validation failed: ' . $this->validationField($normalValidation) . '.');
            }

            $languageValidation = $product->validateFieldsLang(false, true);
            if ($languageValidation !== true) {
                throw new Exception('Product language validation failed: ' . $this->validationField($languageValidation) . '.');
            }

            $addContext = $this->captureAddContext($product, $normalValidation, $languageValidation, (int) $canonical['language_id']);
            try {
                $added = $product->add();
            } catch (\PrestaShopException $exception) {
                throw new Exception('Product add hook failed.');
            }

            if (!$added) {
                $createdProductId = (int) $product->id > 0 ? (int) $product->id : null;
                $error = $this->productAddDiagnostic($product, $addContext);

                return $this->handleFailure($request, $employeeId, $error, $createdProductId);
            }
            $createdProductId = (int) $product->id;

            if ($createdProductId <= 0 || !$product->addToCategories($canonical['categories'])) {
                throw new Exception('Product category association failed.');
            }

            if (isset($canonical['tags']) && $canonical['tags']
                && !(new AiBridgeTagHandler())->apply($product, $canonical['tags'])) {
                throw new Exception('Product tag association failed.');
            }

            if (isset($canonical['features'])) {
                $featureHandler = new AiBridgeFeatureHandler();
                if (!$featureHandler->apply($product, $canonical['features'])
                    || !$featureHandler->verify($product, $canonical['features'])) {
                    throw new Exception('Product feature association failed.');
                }
            }

            if (isset($canonical['combinations'])) {
                $combinationHandler = new AiBridgeCombinationCreateHandler();
                $combinationSnapshot = $combinationHandler->capture(
                    $product,
                    $canonical['combinations'],
                    (int) $canonical['language_id'],
                    (int) $canonical['shop_id']
                );
                if (!$combinationHandler->apply($product, $canonical['combinations'], (int) $canonical['language_id'], $combinationSnapshot)
                    || !$combinationHandler->verify($product, $combinationSnapshot)) {
                    throw new Exception('Product combination creation failed.');
                }
            }

            if (isset($canonical['stock'])
                && !(new AiBridgeStockHandler())->apply($product, $canonical['stock'], (int) $canonical['shop_id'])) {
                throw new Exception('Product stock update failed.');
            }

            if (isset($canonical['images'])) {
                $imageHandler = new AiBridgeImageHandler();
                $canonicalImages = $imageHandler->normalizeCanonical($canonical['images'], $product, (int) $canonical['shop_id']);
                if ($canonicalImages === null) {
                    throw new Exception('Product image validation failed.');
                }

                $imageSnapshot = $imageHandler->capture($product, $canonicalImages, (int) $canonical['shop_id']);
                if (!$imageHandler->apply($product, $canonicalImages, (int) $canonical['shop_id'], $imageSnapshot)
                    || !$imageHandler->verify($product, $canonicalImages, (int) $canonical['shop_id'], $imageSnapshot)) {
                    throw new Exception('Product image association failed.');
                }

                if (!$imageHandler->consumeAddedUpload($imageSnapshot)) {
                    throw new Exception('Product image upload consumption failed.');
                }
            }

            if (!$this->verify($createdProductId, $canonical)) {
                throw new Exception('Product verification failed.');
            }

            $request->created_product_id = $createdProductId;
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
                $createdProductId,
                'apply-create',
                array('product_create'),
                'success',
                null,
                $employeeId
            )) {
                throw new Exception('Execution audit log failed.');
            }

            return true;
        } catch (\PrestaShopException $exception) {
            return $this->handleFailure($request, $employeeId, 'Product validation failed: ' . $this->validationField($exception->getMessage()) . '.', $createdProductId, $this->debugDetail($exception));
        } catch (\Throwable $exception) {
            return $this->handleFailure($request, $employeeId, $this->safeError($exception), $createdProductId, $this->debugDetail($exception));
        } finally {
            if ($contextState !== null) {
                $this->restoreContext($contextState);
            }
        }
    }

    private function buildProduct(array $payload)
    {
        $product = new Product(null, false, (int) $payload['language_id'], (int) $payload['shop_id']);
        $product->id_shop_default = (int) $payload['shop_id'];
        $product->id_shop_list = array((int) $payload['shop_id']);
        $product->product_type = \PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::TYPE_STANDARD;
        $product->visibility = 'both';
        $product->condition = 'new';
        $product->minimal_quantity = 1;
        $product->available_for_order = true;
        $product->show_price = true;
        $product->redirect_type = \PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\RedirectType::TYPE_DEFAULT;
        $product->id_type_redirected = 0;
        $product->id_tax_rules_group = (int) $payload['id_tax_rules_group'];
        $product->id_category_default = (int) $payload['id_category_default'];
        $product->price = (float) $payload['price'];
        $product->reference = (string) $payload['reference'];
        $product->active = false;
        $product->id_manufacturer = isset($payload['id_manufacturer']) ? (int) $payload['id_manufacturer'] : 0;
        $product->name = $payload['name'];
        $product->link_rewrite = $payload['link_rewrite'];
        $product->description = $payload['description'] ?? array();
        $product->description_short = $payload['description_short'] ?? array();
        $product->meta_title = $payload['meta_title'] ?? array();
        $product->meta_description = $payload['meta_description'] ?? array();
        $product->meta_keywords = $payload['meta_keywords'] ?? array();

        return $product;
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

    private function captureAddContext(Product $product, $normalValidation, $languageValidation, $languageId)
    {
        $context = Context::getContext();

        return array(
            'normal_validation' => $normalValidation === true,
            'language_validation' => $languageValidation === true,
            'context_shop_exists' => isset($context->shop),
            'context_shop_id' => isset($context->shop) ? (int) $context->shop->id : 0,
            'context_language_id' => isset($context->language) ? (int) $context->language->id : 0,
            'product_shop_id' => (int) $product->id_shop_default,
            'product_language_id' => (int) $languageId,
            'name_language_count' => is_array($product->name) ? count($product->name) : 0,
            'link_rewrite_language_count' => is_array($product->link_rewrite) ? count($product->link_rewrite) : 0,
            'shop_list_count' => is_array($product->id_shop_list) ? count($product->id_shop_list) : 0,
        );
    }

    private function productAddDiagnostic(Product $product, array $context)
    {
        $db = Db::getInstance();
        $number = (int) $db->getNumberError();
        $message = (string) $db->getMsgError();
        $stage = $this->detectAddFailureStage($product, $message, $context);

        if ($number <= 0 || trim($message) === '') {
            return 'Product add returned false without database error: ' . $stage . '. '
                . $this->formatAddContext($context, $stage);
        }

        return 'Product add database error [' . $number . ']: ' . $stage . '. '
            . $this->sanitizeDatabaseDescription($number, $message);
    }

    private function detectAddFailureStage(Product $product, $message, array $context)
    {
        if ((int) $product->id <= 0) {
            return 'product_base_insert';
        }

        $message = strtolower((string) $message);
        if (strpos($message, 'product_lang') !== false) {
            return 'product_lang_insert';
        }
        if (strpos($message, 'product_shop') !== false) {
            return 'product_shop_association';
        }

        $shopAssociated = false;
        foreach (Product::getShopsByProduct((int) $product->id) as $shop) {
            if ((int) $shop['id_shop'] === (int) $context['context_shop_id']) {
                $shopAssociated = true;
                break;
            }
        }
        if (!$shopAssociated) {
            return 'product_shop_association';
        }

        $reloaded = new Product(
            (int) $product->id,
            false,
            null,
            (int) $context['context_shop_id']
        );
        $languageId = (int) $context['context_language_id'];
        if (!Validate::isLoadedObject($reloaded)
            || !is_array($reloaded->name)
            || !is_array($reloaded->link_rewrite)
            || !array_key_exists($languageId, $reloaded->name)
            || !array_key_exists($languageId, $reloaded->link_rewrite)) {
            return 'product_lang_insert';
        }

        return 'product_post_add';
    }

    private function formatAddContext(array $context, $stage)
    {
        return sprintf(
            '[stage=%s, normal_validation=%d, language_validation=%d, context_shop=%d, shop_id=%d, language_id=%d, product_shop_id=%d, product_language_id=%d, name_languages=%d, link_rewrite_languages=%d, shop_list_count=%d]',
            $stage,
            $context['normal_validation'] ? 1 : 0,
            $context['language_validation'] ? 1 : 0,
            $context['context_shop_exists'] ? 1 : 0,
            $context['context_shop_id'],
            $context['context_language_id'],
            $context['product_shop_id'],
            $context['product_language_id'],
            $context['name_language_count'],
            $context['link_rewrite_language_count'],
            $context['shop_list_count']
        );
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
        if ((int) $number === 1146 || strpos($message, 'doesn\'t exist') !== false) {
            return 'Database table is unavailable.';
        }
        if ((int) $number === 1054 || strpos($message, 'unknown column') !== false) {
            return 'Database column is invalid.';
        }

        return 'Database operation failed.';
    }
    private function verify($productId, array $payload)
    {
        $product = new Product((int) $productId, false, null, (int) $payload['shop_id']);
        if (!Validate::isLoadedObject($product)
            || (bool) $product->active !== false
            || (float) $product->price !== (float) $payload['price']
            || (int) $product->id_tax_rules_group !== (int) $payload['id_tax_rules_group']
            || (string) $product->reference !== (string) $payload['reference']
            || (int) $product->id_category_default !== (int) $payload['id_category_default']
            || (int) $product->id_manufacturer !== (int) ($payload['id_manufacturer'] ?? 0)
            || (bool) $product->hasAttributes() !== isset($payload['combinations'])) {
            return false;
        }

        $languageId = (int) $payload['language_id'];
        if (!isset($product->name[$languageId], $product->link_rewrite[$languageId])
            || $product->name[$languageId] !== $payload['name'][$languageId]
            || $product->link_rewrite[$languageId] !== $payload['link_rewrite'][$languageId]) {
            return false;
        }

        foreach (array('description', 'description_short', 'meta_title', 'meta_description', 'meta_keywords') as $textField) {
            foreach ((array) ($payload[$textField] ?? array()) as $langId => $value) {
                if (!isset($product->{$textField}[$langId]) || $product->{$textField}[$langId] !== $value) {
                    return false;
                }
            }
        }

        $categories = array_map('intval', Product::getProductCategories((int) $productId));
        sort($categories, SORT_NUMERIC);
        if ($categories !== $payload['categories']) {
            return false;
        }

        $shopIds = array();
        foreach (Product::getShopsByProduct((int) $productId) as $shop) {
            $shopIds[] = (int) $shop['id_shop'];
        }
        sort($shopIds, SORT_NUMERIC);
        if ($shopIds !== array((int) $payload['shop_id'])) {
            return false;
        }

        $hasImages = !empty(Image::getImages($languageId, (int) $productId, null, (int) $payload['shop_id']));
        if (isset($payload['images']) ? !$hasImages : $hasImages) {
            return false;
        }

        $expectedQuantity = isset($payload['stock']['simple_quantity']) ? (int) $payload['stock']['simple_quantity'] : 0;

        return (int) StockAvailable::getQuantityAvailableByProduct(
            (int) $productId,
            0,
            (int) $payload['shop_id']
        ) === $expectedQuantity;
    }

    private function handleFailure(AiBridgeApprovalRequest $request, $employeeId, $error, $createdProductId, $debugDetail = null)
    {
        if ($createdProductId !== null && !$this->rollback($createdProductId, (int) $request->language_id, (int) $request->shop_id)) {
            $error = 'Product creation rollback requires manual review.';
        }

        return $this->recordFailure($request, $employeeId, $error, $createdProductId, $debugDetail);
    }

    private function debugDetail(\Throwable $exception)
    {
        return get_class($exception) . ': ' . $exception->getMessage()
            . ' @ ' . basename($exception->getFile()) . ':' . $exception->getLine();
    }

    private function rollback($productId, $languageId, $shopId)
    {
        $product = new Product((int) $productId, false, $languageId, $shopId);
        if (!Validate::isLoadedObject($product) || !$product->delete()) {
            return false;
        }

        $reloaded = new Product((int) $productId, false, $languageId, $shopId);
        if (Validate::isLoadedObject($reloaded)) {
            return false;
        }

        return empty(Product::getProductAttributesIds((int) $productId))
            && empty(Image::getImages((int) $languageId, (int) $productId, null, (int) $shopId));
    }

    private function recordFailure(AiBridgeApprovalRequest $request, $employeeId, $error, $productId, $debugDetail = null)
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
            $productId,
            'apply-create',
            array('product_create'),
            'failed',
            $debugDetail !== null ? $error . ' | debug: ' . $debugDetail : $error,
            $employeeId
        );

        return false;
    }

    private function validationField($message)
    {
        if (is_string($message) && preg_match('/Product->([A-Za-z_]+)/', $message, $matches)) {
            return $matches[1];
        }

        return 'field';
    }

    private function safeError(\Throwable $exception)
    {
        $message = $exception->getMessage();
        if (preg_match('/\AProduct (?:language )?validation failed: [A-Za-z_]+\.\z/', $message)
            || preg_match('/\AProduct add database error \[\d+\]: product_(?:base_insert|lang_insert|shop_association|post_add)\. (?:Duplicate key constraint|Required value is missing|Relationship constraint failed|Database table is unavailable|Database column is invalid|Database operation failed)\.\z/', $message)
            || preg_match('/\AProduct add returned false without database error: product_(?:base_insert|lang_insert|shop_association|post_add)\. \[stage=product_(?:base_insert|lang_insert|shop_association|post_add), normal_validation=[01], language_validation=[01], context_shop=[01], shop_id=\d+, language_id=\d+, product_shop_id=\d+, product_language_id=\d+, name_languages=\d+, link_rewrite_languages=\d+, shop_list_count=\d+\]\z/', $message)) {
            return $message;
        }

        $allowed = array(
            'Request is not executable.',
            'Execution state could not be saved.',
            'Invalid approved payload.',
            'Product duplicate detected.',
            'Payload hash mismatch.',
            'Product add hook failed.',
            'Product category association failed.',
            'Product tag association failed.',
            'Product feature association failed.',
            'Product combination creation failed.',
            'Combination create conflict.',
            'Product stock update failed.',
            'Product image validation failed.',
            'Product image association failed.',
            'Product image upload consumption failed.',
            'Product verification failed.',
            'Execution audit update failed.',
            'Execution audit log failed.',
            'Product creation rollback requires manual review.',
        );

        return in_array($message, $allowed, true)
            ? $message
            : 'Product add returned false.';
    }
}