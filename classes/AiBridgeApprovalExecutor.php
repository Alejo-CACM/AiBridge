<?php


require_once dirname(__FILE__) . '/AiBridgeExecutionLog.php';
require_once dirname(__FILE__) . '/AiBridgeProductCreateExecutor.php';
require_once dirname(__FILE__) . '/AiBridgeCategoryCreateExecutor.php';
require_once dirname(__FILE__) . '/AiBridgeCategoryExecutor.php';
require_once dirname(__FILE__) . '/AiBridgeManufacturerCreateExecutor.php';
require_once dirname(__FILE__) . '/AiBridgeAddressCreateExecutor.php';
require_once dirname(__FILE__) . '/AiBridgeAddressExecutor.php';
require_once dirname(__FILE__) . '/AiBridgeEmailTemplateCreateExecutor.php';
require_once dirname(__FILE__) . '/AiBridgeEmailSendExecutor.php';
require_once dirname(__FILE__) . '/AiBridgeClassificationHandler.php';
require_once dirname(__FILE__) . '/AiBridgeFeatureHandler.php';
require_once dirname(__FILE__) . '/AiBridgeTagHandler.php';
require_once dirname(__FILE__) . '/AiBridgeDiscountHandler.php';
require_once dirname(__FILE__) . '/AiBridgeStockHandler.php';
require_once dirname(__FILE__) . '/AiBridgeCombinationHandler.php';
require_once dirname(__FILE__) . '/AiBridgeCombinationCreateHandler.php';
require_once dirname(__FILE__) . '/AiBridgeImageHandler.php';
require_once dirname(__FILE__) . '/AiBridgeCombinationImageHandler.php';

class AiBridgeApprovalExecutor
{
    private $classificationHandler;
    private $featureHandler;
    private $tagHandler;
    private $discountHandler;
    private $stockHandler;
    private $combinationHandler;
    private $combinationCreateHandler;
    private $imageHandler;
    private $combinationImageHandler;

    private const BOOLEAN_FIELDS = array(
        'active',
        'available_for_order',
        'show_price',
    );

    private const TEXT_FIELDS = array(
        'name',
        'description',
        'description_short',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'link_rewrite',
    );

    private const NON_NEGATIVE_NUMBER_FIELDS = array(
        'wholesale_price',
        'weight',
        'width',
        'height',
        'depth',
    );

    private const ALLOWED_FIELDS = array(
        'price',
        'active',
        'reference',
        'minimal_quantity',
        'ean13',
        'isbn',
        'upc',
        'wholesale_price',
        'id_tax_rules_group',
        'available_for_order',
        'show_price',
        'condition',
        'out_of_stock',
        'weight',
        'width',
        'height',
        'depth',
        'name',
        'description',
        'description_short',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'link_rewrite',
        'id_manufacturer',
        'categories',
        'id_category_default',
        'features',
        'tags',
        'discount',
        'stock',
        'combinations',
        'images',
        'combination_images',
    );

    public function execute(AiBridgeApprovalRequest $request, $employeeId)
    {
        if ($request->operation_type === AiBridgeApprovalRequest::OPERATION_CREATE) {
            return (new AiBridgeProductCreateExecutor())->execute($request, $employeeId);
        }

        if ($request->operation_type === AiBridgeApprovalRequest::OPERATION_CREATE_CATEGORY) {
            return (new AiBridgeCategoryCreateExecutor())->execute($request, $employeeId);
        }

        if ($request->operation_type === AiBridgeApprovalRequest::OPERATION_UPDATE_CATEGORY) {
            return (new AiBridgeCategoryExecutor())->execute($request, $employeeId);
        }

        if ($request->operation_type === AiBridgeApprovalRequest::OPERATION_CREATE_MANUFACTURER) {
            return (new AiBridgeManufacturerCreateExecutor())->execute($request, $employeeId);
        }

        if ($request->operation_type === AiBridgeApprovalRequest::OPERATION_CREATE_ADDRESS) {
            return (new AiBridgeAddressCreateExecutor())->execute($request, $employeeId);
        }

        if ($request->operation_type === AiBridgeApprovalRequest::OPERATION_UPDATE_ADDRESS) {
            return (new AiBridgeAddressExecutor())->execute($request, $employeeId);
        }

        if ($request->operation_type === AiBridgeApprovalRequest::OPERATION_CREATE_EMAIL_TEMPLATE) {
            return (new AiBridgeEmailTemplateCreateExecutor())->execute($request, $employeeId);
        }

        if ($request->operation_type === AiBridgeApprovalRequest::OPERATION_SEND_EMAIL) {
            return (new AiBridgeEmailSendExecutor())->execute($request, $employeeId);
        }

        if ($request->operation_type !== AiBridgeApprovalRequest::OPERATION_UPDATE) {
            return $this->recordFailure($request, $employeeId, 'Request is not executable.');
        }

        if ($request->status !== AiBridgeApprovalRequest::STATUS_APPROVED
            || strtotime($request->expires_at) < time()) {
            return $this->recordFailure($request, $employeeId, 'Request is not executable.');
        }

        $request->status = AiBridgeApprovalRequest::STATUS_EXECUTING;
        $request->execution_status = 'executing';
        $request->executed_by_employee_id = (int) $employeeId;
        $request->execution_error = null;
        $request->executed_at = null;
        if (!$request->update()) {
            return $this->recordFailure(
                $request,
                $employeeId,
                'Execution state could not be saved.'
            );
        }

        $classificationSnapshot = null;
        $classificationTouched = false;
        $featuresTouched = false;
        $featureSnapshot = null;
        $featuresChanged = false;
        $tags = null;
        $tagSnapshot = null;
        $discount = null;
        $discountSnapshot = null;
        $discountTouched = false;
        $discountCaptured = false;
        $product = null;
        try {
            $payload = json_decode($request->payload_json, true);

            if (!is_array($payload)) {
                throw new Exception('Invalid approved payload.');
            }

            $product = new Product(
                (int) $request->product_id,
                true,
                null,
                (int) $request->shop_id
            );

            if (!Validate::isLoadedObject($product)
                || $product->date_upd !== $request->product_date_upd_snapshot) {
                throw new Exception('Product changed since approval.');
            }

            $canonicalPayload = $this->canonicalizePayload($payload, $product);
            $currentHash = hash('sha256', json_encode($canonicalPayload));

            if (!hash_equals((string) $request->payload_hash, $currentHash)
                && !$this->hasLegacyPriceOnlyHash($request, $payload)) {
                throw new Exception('Payload hash mismatch.');
            }

            $fields = $this->validatePayload(
                $payload,
                $canonicalPayload,
                (int) $request->language_id, $product
            );
            $outOfStock = array_key_exists('out_of_stock', $fields)
                ? $fields['out_of_stock']
                : null;
            unset($fields['out_of_stock']);
            $stock = array_key_exists('stock', $fields) ? $fields['stock'] : null;
            unset($fields['stock']);
            $combinations = array_key_exists('combinations', $fields) ? $fields['combinations'] : null;
            unset($fields['combinations']);
            $combinationCreate = $combinations !== null && isset($combinations['create']);
            $combinationCreateSnapshot = null;
            $images = array_key_exists('images', $fields) ? $fields['images'] : null;
            unset($fields['images']);
            $combinationImages = array_key_exists('combination_images', $fields) ? $fields['combination_images'] : null;
            unset($fields['combination_images']);
            $combinationImageSnapshot = null;
            $imageSnapshot = null;
            $combinationSnapshot = null;

            $stockSnapshot = null;

            $categories = array_key_exists('categories', $fields)
                ? $fields['categories']
                : null;
            unset($fields['categories']);
            $features = array_key_exists('features', $fields)
                ? $fields['features']
                : null;
            unset($fields['features']);
            $featuresTouched = $features !== null;
            $featureSnapshot = null;
            $tags = array_key_exists('tags', $fields) ? $fields['tags'] : null;
            unset($fields['tags']);
            $tagSnapshot = null;
            $discount = array_key_exists('discount', $fields) ? $fields['discount'] : null;
            unset($fields['discount']);
            $discountTouched = $discount !== null;
            $classificationTouched = $this->getClassificationHandler()->isTouched(
                $fields,
                $categories
            );

            $product = new Product(
                (int) $request->product_id,
                true,
                null,
                (int) $request->shop_id
            );

            if (!Validate::isLoadedObject($product)
                || $product->date_upd !== $request->product_date_upd_snapshot) {
                throw new Exception('Product changed since approval.');
            }

            if ($stock !== null) {
                $stockSnapshot = $this->getStockHandler()->capture($product, $stock, (int) $request->shop_id);
            }

            if ($combinationCreate) {
                $combinationCreateSnapshot = $this->getCombinationCreateHandler()->capture($product, $combinations, (int) $request->language_id, (int) $request->shop_id);
            } elseif ($combinations !== null) {
                $combinationSnapshot = $this->getCombinationHandler()->capture($product, $combinations, (int) $request->language_id);
            }
            if ($combinationImages !== null) $combinationImageSnapshot = $this->getCombinationImageHandler()->capture($product, $combinationImages, (int) $request->language_id);
            if ($images !== null) $imageSnapshot = $this->getImageHandler()->capture($product, $images, (int) $request->shop_id);

            if ($featuresTouched) {
                $featureSnapshot = $this->getFeatureHandler()->read($product);
                $featuresChanged = $featureSnapshot !== $features;
            }

            if ($tags !== null) {
                $tagSnapshot = $this->getTagHandler()->capture($product, $tags);
            }

            if ($discountTouched) {
                $discountSnapshot = $this->getDiscountHandler()->capture($product);
                $discountCaptured = true;
            }

            if ($classificationTouched) {
                $classificationSnapshot = $this->getClassificationHandler()->capture($product);
                $this->getClassificationHandler()->assertValidTarget(
                    $fields,
                    $categories,
                    $classificationSnapshot
                );
            }

            $this->getClassificationHandler()->applyProductFields($product, $fields);

            foreach ($fields as $field => $value) {
                if ($this->getClassificationHandler()->isClassificationField($field)) {
                    continue;
                }
                if (in_array($field, self::TEXT_FIELDS, true)) {
                    $product->{$field}[(int) $request->language_id] = $value;
                    continue;
                }

                $product->{$field} = $value;
            }

            if ($fields && !$product->update()) {
                throw new Exception('Product update failed.');
            }

            if (!$this->getClassificationHandler()->applyCategories($product, $categories)) {
                throw new Exception('Category update failed.');
            }
            if ($featuresChanged && (!$this->getFeatureHandler()->apply($product, $features)
                || !$this->getFeatureHandler()->verify($product, $features))) {
                throw new Exception('Feature update failed.');
            }

            if ($tags !== null && (!$this->getTagHandler()->apply($product, $tags)
                || !$this->getTagHandler()->verify($product, $tags))) {
                throw new Exception('Tag update failed.');
            }

            if ($discountTouched && (!$this->getDiscountHandler()->apply($product, $discount)
                || !$this->getDiscountHandler()->verify($product, $discount))) {
                throw new Exception('Discount update failed.');
            }


            if ($classificationTouched && !$this->getClassificationHandler()->verify(
                $request,
                $fields,
                $categories
            )) {
                throw new Exception('Classification verification failed.');
            }

            if ($stock !== null && !$this->getStockHandler()->apply($product, $stock, (int) $request->shop_id)) {
                throw new Exception('Stock update failed.');
            }

            if ($combinationCreate && (!$this->getCombinationCreateHandler()->apply($product, $combinations, (int) $request->language_id, $combinationCreateSnapshot)
                || !$this->getCombinationCreateHandler()->verify($product, $combinationCreateSnapshot))) {
                throw new Exception('Combination create failed.');
            } elseif ($combinations !== null && (!$this->getCombinationHandler()->apply($product, $combinations, (int) $request->language_id)
                || !$this->getCombinationHandler()->verify($product, $combinations, (int) $request->language_id))) {
                throw new Exception('Combination update failed.');
            }

            if ($combinationImages !== null && (!$this->getCombinationImageHandler()->apply($product, $combinationImages, (int) $request->language_id)
                || !$this->getCombinationImageHandler()->verify($product, $combinationImages, (int) $request->language_id))) {
                throw new Exception('Combination image update failed.');
            }

            if ($images !== null && (!$this->getImageHandler()->apply($product, $images, (int) $request->shop_id, $imageSnapshot)
                || !$this->getImageHandler()->verify($product, $images, (int) $request->shop_id, $imageSnapshot))) {
                throw new Exception('Image update failed.');
            }

            if ($outOfStock !== null) {
                $shopId = $this->resolveShopId($product);

                StockAvailable::setProductOutOfStock(
                    (int) $product->id,
                    $outOfStock,
                    $shopId
                );

                if ((int) StockAvailable::outOfStock(
                    (int) $product->id,
                    $shopId
                ) !== $outOfStock) {
                    throw new Exception('Out-of-stock update failed.');
                }
            }

            if ($images !== null && isset($images['add'])
                && !$this->getImageHandler()->consumeAddedUpload($imageSnapshot)) {
                throw new Exception('Image upload consumption failed.');
            }

            $changedFields = array_keys($fields);

            if ($classificationSnapshot !== null) {
                if (array_key_exists('id_manufacturer', $fields)
                    && (int) $fields['id_manufacturer'] === (int) $classificationSnapshot['id_manufacturer']) {
                    $changedFields = array_values(array_diff($changedFields, array('id_manufacturer')));
                }

                if (array_key_exists('id_category_default', $fields)
                    && (int) $fields['id_category_default'] === (int) $classificationSnapshot['id_category_default']) {
                    $changedFields = array_values(array_diff($changedFields, array('id_category_default')));
                }

                if ($categories !== null && $categories !== $classificationSnapshot['categories']) {
                    $changedFields[] = 'categories';
                }
            } elseif ($categories !== null) {
                $changedFields[] = 'categories';
            }

            if ($outOfStock !== null) {
                $changedFields[] = 'out_of_stock';
            }

            if ($featuresChanged) {
                $changedFields[] = 'features';
            }

            if ($stock !== null && $stockSnapshot !== $stock) {
                $changedFields[] = 'stock';
            }

            if ($combinations !== null) $changedFields[] = 'combinations';
            if ($images !== null) $changedFields[] = 'images';
            if ($combinationImages !== null) $changedFields[] = 'combination_images';
            if ($tags !== null) $changedFields[] = 'tags';
            if ($discountTouched) $changedFields[] = 'discount';

            sort($changedFields, SORT_STRING);

            $request->status = AiBridgeApprovalRequest::STATUS_EXECUTED;
            $request->execution_status = 'executed';
            $request->executed_at = date('Y-m-d H:i:s');
            $request->execution_error = null;
            if (!$request->update()) {
                throw new Exception('Execution audit update failed.');
            }

            if (!AiBridgeExecutionLog::record(
                $request->id,
                $request->product_id,
                'apply-update',
                $changedFields,
                'success',
                null,
                $employeeId
            )) {
                throw new Exception('Execution audit log failed.');
            }

            return true;
        } catch (\Throwable $exception) {
            $error = $this->getSafeError($exception);

            if ($images !== null && $imageSnapshot !== null
                && !$this->getImageHandler()->restore($product, $imageSnapshot, (int) $request->shop_id)) {
                $error = 'Image rollback requires manual review.';
            }

            if ($combinationImages !== null && $combinationImageSnapshot !== null
                && !$this->getCombinationImageHandler()->restore($product, $combinationImageSnapshot, (int) $request->language_id)) {
                $error = 'Combination image rollback requires manual review.';
            }

            if ($combinationCreate && $combinationCreateSnapshot !== null
                && !$this->getCombinationCreateHandler()->rollback($product, $combinationCreateSnapshot)) {
                $error = 'Combination rollback requires manual review.';
            } elseif ($combinations !== null && $combinationSnapshot !== null
                && !$this->getCombinationHandler()->restore($product, $combinationSnapshot, (int) $request->language_id)) {
                $error = 'Combination rollback requires manual review.';
            }

            if ($stock !== null && $stockSnapshot !== null
                && !$this->getStockHandler()->apply($product, $stockSnapshot, (int) $request->shop_id)) {
                $error = 'Stock rollback requires manual review.';
            }

            if ($featuresChanged && $featureSnapshot !== null
                && !$this->getFeatureHandler()->restore($product, $featureSnapshot)) {
                $error = 'Feature rollback requires manual review.';
            }

            if ($tags !== null && $tagSnapshot !== null
                && !$this->getTagHandler()->restore($product, $tagSnapshot)) {
                $error = 'Tag rollback requires manual review.';
            }

            if ($discountCaptured && $product !== null
                && !$this->getDiscountHandler()->restore($product, $discountSnapshot)) {
                $error = 'Discount rollback requires manual review.';
            }

            if ($classificationTouched && $classificationSnapshot !== null
                && !$this->getClassificationHandler()->restore($request, $classificationSnapshot)) {
                $error = 'Classification rollback requires manual review.';
            }

            return $this->recordFailure($request, $employeeId, $error);
        }
    }

    private function validatePayload(
        array $payload,
        array $canonicalPayload,
        $languageId,
        Product $product
    )
    {
        if (!$payload || count($payload) > count(self::ALLOWED_FIELDS)) {
            throw new Exception('Invalid approved payload.');
        }

        $fields = array();

        foreach ($payload as $field => $value) {
            if ($field === 'images') {
                $normalized = $this->getImageHandler()->normalizeCanonical($value, $product, $this->resolveShopId($product));
                if ($normalized !== null) {
                    $fields['images'] = $normalized;
                    continue;
                }
            }

            if ($field === 'combination_images') {
                $normalized = $this->getCombinationImageHandler()->normalize($value, $product, $languageId);
                if ($normalized !== null) { $fields['combination_images'] = $normalized; continue; }
            }

            if ($field === 'combinations') {
                $handler = is_array($value) && isset($value['create']) ? $this->getCombinationCreateHandler() : $this->getCombinationHandler();
                $normalized = $handler->normalize($value, $product, $languageId);
                if ($normalized !== null) { $fields['combinations'] = $normalized; continue; }
            }

            if ($field === 'stock') {
                $normalized = $this->getStockHandler()->normalize($value, $product);
                if ($normalized !== null) {
                    $fields['stock'] = $normalized;
                    continue;
                }
            }

            if ($field === 'features') {
                $normalized = $this->getFeatureHandler()->normalize($value);

                if ($normalized !== null) {
                    $fields['features'] = $normalized;
                    continue;
                }
            }

            if ($field === 'tags') {
                $normalized = $this->getTagHandler()->normalize($value);

                if ($normalized !== null) {
                    $fields['tags'] = $normalized;
                    continue;
                }
            }

            if ($field === 'discount') {
                $normalized = $this->getDiscountHandler()->normalize($value);

                if ($normalized !== null) {
                    $fields['discount'] = $normalized;
                    continue;
                }
            }

            if ($this->getClassificationHandler()->isClassificationField($field)) {
                $normalized = $this->getClassificationHandler()->validateField($field, $value);

                if ($normalized !== null) {
                    $fields[$field] = $normalized;
                    continue;
                }
            }

            if (in_array($field, self::TEXT_FIELDS, true)) {
                $text = $this->normalizeText($value);

                if ($text !== null && $this->isValidText($field, $text, $languageId)) {
                    $fields[$field] = $text;
                    continue;
                }
            }
            if ($field === 'price' && is_numeric($value)) {
                $fields['price'] = (float) $canonicalPayload['price'];
                continue;
            }

            if (in_array($field, self::BOOLEAN_FIELDS, true)) {
                $normalized = $this->normalizeBoolean($value);

                if ($normalized !== null) {
                    $fields[$field] = $normalized;
                    continue;
                }
            }

            if ($field === 'reference' && is_string($value)
                && Tools::strlen($value) <= 64
                && Validate::isReference($value)) {
                $fields['reference'] = $value;
                continue;
            }

            if ($field === 'minimal_quantity') {
                $normalized = $this->normalizeMinimalQuantity($value);

                if ($normalized !== null) {
                    $fields['minimal_quantity'] = $normalized;
                    continue;
                }
            }

            if (in_array($field, array('ean13', 'isbn', 'upc'), true)
                && is_string($value) && $this->isValidIdentifier($field, $value)) {
                $fields[$field] = $value;
                continue;
            }

            if (in_array($field, self::NON_NEGATIVE_NUMBER_FIELDS, true)
                && is_numeric($value) && (float) $value >= 0) {
                $fields[$field] = (float) $canonicalPayload[$field];
                continue;
            }

            if ($field === 'id_tax_rules_group') {
                $normalized = $this->normalizeNonNegativeInteger($value);

                if ($normalized !== null
                    && ($normalized === 0 || $this->taxRulesGroupExists($normalized))) {
                    $fields['id_tax_rules_group'] = $normalized;
                    continue;
                }
            }

            if ($field === 'condition' && is_string($value)
                && in_array($value, array('new', 'used', 'refurbished'), true)) {
                $fields['condition'] = $value;
                continue;
            }

            if ($field === 'out_of_stock') {
                $normalized = $this->normalizeOutOfStock($value);

                if ($normalized !== null) {
                    $fields['out_of_stock'] = $normalized;
                    continue;
                }
            }

            throw new Exception('Invalid approved payload.');
        }

        ksort($fields);

        return $fields;
    }

    private function canonicalizePayload(array $payload, Product $product)
    {
        foreach ($payload as $field => $value) {
            if ($field === 'images') {
                $normalized = $this->getImageHandler()->normalizeCanonical($value, $product, $this->resolveShopId($product));
                if ($normalized !== null) {
                    $payload['images'] = $normalized;
                }
                continue;
            }

            if ($field === 'combination_images') {
                $normalized = $this->getCombinationImageHandler()->normalize($value, $product, Context::getContext()->language->id);
                if ($normalized !== null) {
                    $payload['combination_images'] = $normalized;
                }
                continue;
            }

            if ($field === 'stock') {
                $normalized = $this->getStockHandler()->normalize($value, $product);
                if ($normalized !== null) {
                    $payload['stock'] = $normalized;
                }
                continue;
            }

            if ($field === 'features') {
                $normalized = $this->getFeatureHandler()->normalize($value);

                if ($normalized !== null) {
                    $payload['features'] = $normalized;
                }

                continue;
            }

            if ($field === 'tags') {
                $normalized = $this->getTagHandler()->normalize($value);

                if ($normalized !== null) {
                    $payload['tags'] = $normalized;
                }

                continue;
            }

            if ($field === 'discount') {
                $normalized = $this->getDiscountHandler()->normalize($value);

                if ($normalized !== null) {
                    $payload['discount'] = $normalized;
                }

                continue;
            }

            if ($this->getClassificationHandler()->isClassificationField($field)) {
                $normalized = $this->getClassificationHandler()->canonicalizeField($field, $value);

                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }

                continue;
            }

            if (in_array($field, self::TEXT_FIELDS, true)) {
                $normalized = $this->normalizeText($value);

                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }

                continue;
            }
            if ($field === 'price' && is_numeric($value)) {
                $payload[$field] = (float) $value;
                continue;
            }

            if (in_array($field, self::BOOLEAN_FIELDS, true)) {
                $normalized = $this->normalizeBoolean($value);

                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }

                continue;
            }

            if ($field === 'minimal_quantity') {
                $normalized = $this->normalizeMinimalQuantity($value);

                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }

                continue;
            }

            if (in_array($field, self::NON_NEGATIVE_NUMBER_FIELDS, true)
                && is_numeric($value) && (float) $value >= 0) {
                $payload[$field] = (float) $value;
                continue;
            }

            if ($field === 'id_tax_rules_group') {
                $normalized = $this->normalizeNonNegativeInteger($value);

                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }

                continue;
            }

            if ($field === 'out_of_stock') {
                $normalized = $this->normalizeOutOfStock($value);

                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                }
            }
        }

        ksort($payload);

        return $payload;
    }

    private function getCombinationImageHandler()
    {
        if ($this->combinationImageHandler === null) $this->combinationImageHandler = new AiBridgeCombinationImageHandler();
        return $this->combinationImageHandler;
    }

    private function getCombinationCreateHandler()
    {
        if ($this->combinationCreateHandler === null) $this->combinationCreateHandler = new AiBridgeCombinationCreateHandler();
        return $this->combinationCreateHandler;
    }

    private function getCombinationHandler()
    {
        if ($this->combinationHandler === null) $this->combinationHandler = new AiBridgeCombinationHandler();
        return $this->combinationHandler;
    }

    private function getImageHandler()
    {
        if ($this->imageHandler === null) {
            $this->imageHandler = new AiBridgeImageHandler();
        }

        return $this->imageHandler;
    }

    private function getStockHandler()
    {
        if ($this->stockHandler === null) {
            $this->stockHandler = new AiBridgeStockHandler();
        }

        return $this->stockHandler;
    }

    private function getFeatureHandler()
    {
        if ($this->featureHandler === null) {
            $this->featureHandler = new AiBridgeFeatureHandler();
        }

        return $this->featureHandler;
    }

    private function getTagHandler()
    {
        if ($this->tagHandler === null) {
            $this->tagHandler = new AiBridgeTagHandler();
        }

        return $this->tagHandler;
    }

    private function getDiscountHandler()
    {
        if ($this->discountHandler === null) {
            $this->discountHandler = new AiBridgeDiscountHandler();
        }

        return $this->discountHandler;
    }

    private function getClassificationHandler()
    {
        if ($this->classificationHandler === null) {
            $this->classificationHandler = new AiBridgeClassificationHandler();
        }

        return $this->classificationHandler;
    }

    private function normalizeText($value)
    {
        if (!is_string($value)) {
            return null;
        }

        return str_replace(array("\r\n", "\r"), "\n", $value);
    }

    private function isValidText($field, $value, $languageId)
    {
        if (!$this->isActiveLanguage($languageId)) {
            return false;
        }

        $size = $this->getProductFieldSize($field);
        $validator = $this->getProductFieldValidator($field);

        if (($size !== null && Tools::strlen($value) > $size)
            || (($field === 'name' || $field === 'link_rewrite') && trim($value) === '')
            || $validator === null
            || !method_exists('Validate', $validator)
            || !call_user_func(array('Validate', $validator), $value)) {
            return false;
        }

        if (in_array($field, array('description', 'description_short'), true)
            && !$this->runValidateMethod('isCleanHtml', $value)) {
            return false;
        }

        return true;
    }

    private function getProductFieldValidator($field)
    {
        if (!isset(Product::$definition['fields'][$field]['validate'])) {
            return null;
        }

        $validator = Product::$definition['fields'][$field]['validate'];

        return is_string($validator) && $validator !== '' ? $validator : null;
    }

    private function runValidateMethod($validator, $value)
    {
        return is_string($validator)
            && method_exists('Validate', $validator)
            && (bool) call_user_func(array('Validate', $validator), $value);
    }
    private function isActiveLanguage($languageId)
    {
        $language = new Language((int) $languageId);

        return Validate::isLoadedObject($language) && (bool) $language->active;
    }

    private function getProductFieldSize($field)
    {
        if (!isset(Product::$definition['fields'][$field]['size'])) {
            return null;
        }

        return (int) Product::$definition['fields'][$field]['size'];
    }
    private function hasLegacyPriceOnlyHash(
        AiBridgeApprovalRequest $request,
        array $payload
    ) {
        if (count($payload) !== 1 || !array_key_exists('price', $payload)) {
            return false;
        }

        ksort($payload);

        return hash_equals(
            (string) $request->payload_hash,
            hash('sha256', json_encode($payload))
        );
    }

    private function isValidIdentifier($field, $value)
    {
        if ($value === '') {
            return true;
        }

        if ($field === 'ean13') {
            return Validate::isEan13($value);
        }

        if ($field === 'isbn') {
            return Validate::isIsbn($value);
        }

        return Validate::isUpc($value);
    }

    private function taxRulesGroupExists($id)
    {
        $taxRulesGroup = new TaxRulesGroup((int) $id);

        return Validate::isLoadedObject($taxRulesGroup);
    }

    private function normalizeBoolean($value)
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value;
        }

        if (is_string($value) && ($value === '0' || $value === '1')) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeMinimalQuantity($value)
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeNonNegativeInteger($value)
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeOutOfStock($value)
    {
        if (is_bool($value)) {
            return null;
        }

        if (is_int($value) && in_array($value, array(0, 1, 2), true)) {
            return $value;
        }

        if (is_string($value) && in_array($value, array('0', '1', '2'), true)) {
            return (int) $value;
        }

        return null;
    }

    private function resolveShopId(Product $product)
    {
        $shopId = (int) Context::getContext()->shop->id;

        if ($shopId <= 0) {
            $shopId = (int) $product->id_shop_default;
        }

        return $shopId;
    }
    private function recordFailure(AiBridgeApprovalRequest $request, $employeeId, $error)
    {
        $request->status = AiBridgeApprovalRequest::STATUS_FAILED;
        $request->execution_status = 'failed';
        $request->executed_by_employee_id = (int) $employeeId;
        $request->executed_at = date('Y-m-d H:i:s');
        $request->execution_error = $error;
        $request->update();

        $failedFields = $this->getRequestedFields($request->payload_json);
        $failedFields = array_values(array_diff($failedFields, array('images')));

        AiBridgeExecutionLog::record(
            $request->id,
            $request->product_id,
            'apply-update',
            $failedFields,
            'failed',
            $error,
            $employeeId
        );

        return false;
    }

    private function getRequestedFields($payloadJson)
    {
        $payload = json_decode((string) $payloadJson, true);

        if (!is_array($payload)) {
            return array();
        }

        $fields = array();

        foreach (array_keys($payload) as $field) {
            if (in_array($field, self::ALLOWED_FIELDS, true)) {
                $fields[] = $field;
            }
        }

        sort($fields, SORT_STRING);

        return $fields;
    }

    private function getSafeError(\Throwable $exception)
    {
        $allowed = array(
            'Invalid approved payload.',
            'Payload hash mismatch.',
            'Product changed since approval.',
            'Product update failed.',
            'Out-of-stock update failed.',
            'Stock update failed.',
            'Combination update failed.',
            'Combination already exists.',
            'Combination create failed.',
            'Combination create conflict.',
            'Combination rollback requires manual review.',
            'Image update failed.',
            'Image rollback requires manual review.',
            'Image upload consumption failed.',
            'Stock rollback requires manual review.',
            'Category update failed.',
            'Feature update failed.',
            'Feature rollback requires manual review.',
            'Tag update failed.',
            'Tag rollback requires manual review.',
            'Discount update failed.',
            'Discount rollback requires manual review.',
            'Classification verification failed.',
            'Default category must belong to categories.',
            'Classification rollback requires manual review.',
            'Execution audit update failed.',
            'Execution audit log failed.',
            'Execution state could not be saved.',
            'Request is not executable.',
        );

        return in_array($exception->getMessage(), $allowed, true)
            ? $exception->getMessage()
            : 'Execution failed.';
    }
}
