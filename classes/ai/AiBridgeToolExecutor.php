<?php

require_once dirname(__FILE__) . '/../AiBridgeApprovalRequest.php';
require_once dirname(__FILE__) . '/../AiBridgeProductPreview.php';
require_once dirname(__FILE__) . '/../AiBridgeProductCreatePreview.php';
require_once dirname(__FILE__) . '/../AiBridgeCategoryPreview.php';
require_once dirname(__FILE__) . '/../AiBridgeCategoryCreatePreview.php';
require_once dirname(__FILE__) . '/../AiBridgeManufacturerCreatePreview.php';

/**
 * Executes a tool call the chat AI requested. Write actions never touch the
 * store directly: they build a Preview the same way the HTTP API endpoints
 * do, then file a pending AiBridgeApprovalRequest. A human (a *different*
 * employee than the one chatting — AiBridgeApprovalRequest enforces that)
 * still has to approve it in AI Bridge -> Approvals before anything executes.
 */
class AiBridgeToolExecutor
{
    public function execute($name, array $arguments, $employeeId)
    {
        try {
            switch ($name) {
                case 'product_search':
                    return $this->productSearch($arguments);
                case 'category_list':
                    return $this->categoryList($arguments);
                case 'brands_list':
                    return $this->brandsList();
                case 'product_create':
                    return $this->createPending('product_create', $arguments, $employeeId);
                case 'product_update':
                    return $this->productUpdate($arguments, $employeeId);
                case 'category_create':
                    return $this->createPending('category_create', $arguments, $employeeId);
                case 'category_update':
                    return $this->categoryUpdate($arguments, $employeeId);
                case 'manufacturer_create':
                    return $this->createPending('manufacturer_create', $arguments, $employeeId);
                default:
                    return array('ok' => false, 'error' => 'Unknown tool: ' . $name);
            }
        } catch (\Throwable $exception) {
            return array('ok' => false, 'error' => $exception->getMessage());
        }
    }

    private function productSearch(array $arguments)
    {
        $limit = isset($arguments['limit']) ? min(100, max(1, (int) $arguments['limit'])) : 20;
        $results = array();

        if (!empty($arguments['reference'])) {
            $idProduct = (int) Product::getIdByReference((string) $arguments['reference']);
            if ($idProduct) {
                $results[] = $this->productSummary($idProduct);
            }
        } elseif (!empty($arguments['query'])) {
            $found = Product::searchByName((int) Context::getContext()->language->id, (string) $arguments['query']);
            foreach (array_slice((array) $found, 0, $limit) as $row) {
                $results[] = $this->productSummary((int) $row['id_product']);
            }
        } elseif (!empty($arguments['category_id'])) {
            $category = new Category((int) $arguments['category_id']);
            if (Validate::isLoadedObject($category)) {
                $products = $category->getProducts((int) Context::getContext()->language->id, 1, $limit);
                foreach ((array) $products as $row) {
                    $results[] = $this->productSummary((int) $row['id_product']);
                }
            }
        }

        return array('ok' => true, 'results' => $results);
    }

    private function productSummary($idProduct)
    {
        $product = new Product($idProduct, false, Context::getContext()->language->id);

        return array(
            'id' => $idProduct,
            'name' => $product->name,
            'reference' => $product->reference,
            'price' => (float) $product->price,
            'active' => (bool) $product->active,
        );
    }

    private function categoryList(array $arguments)
    {
        $parentId = isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : null;
        $languageId = (int) Context::getContext()->language->id;

        if ($parentId) {
            $category = new Category($parentId);
            $rows = Validate::isLoadedObject($category) ? $category->getSubCategories($languageId) : array();
        } else {
            $root = Category::getRootCategory();
            $category = new Category((int) $root->id_category);
            $rows = $category->getSubCategories($languageId);
        }

        $results = array();
        foreach ((array) $rows as $row) {
            if (isset($row['id_category'], $row['name'])) {
                $results[] = array('id' => (int) $row['id_category'], 'name' => $row['name']);
            }
        }

        return array('ok' => true, 'results' => $results);
    }

    private function brandsList()
    {
        $manufacturers = Manufacturer::getManufacturers();
        $results = array();
        foreach ((array) $manufacturers as $row) {
            $results[] = array('id' => (int) $row['id_manufacturer'], 'name' => $row['name']);
        }

        return array('ok' => true, 'results' => $results);
    }

    private function createPending($kind, array $payload, $employeeId)
    {
        switch ($kind) {
            case 'product_create':
                $preview = (new AiBridgeProductCreatePreview())->build($payload);
                $invalidMessage = 'The proposed product is not valid (duplicate reference/link rewrite, or missing required fields).';
                break;
            case 'category_create':
                $preview = (new AiBridgeCategoryCreatePreview())->build($payload);
                $invalidMessage = 'The proposed category is not valid.';
                break;
            case 'manufacturer_create':
                $preview = (new AiBridgeManufacturerCreatePreview())->build($payload);
                $invalidMessage = 'The proposed manufacturer is not valid.';
                break;
            default:
                return array('ok' => false, 'error' => 'Unknown creation kind: ' . $kind);
        }

        if (!$preview || !$preview['valid']) {
            return array('ok' => false, 'error' => $invalidMessage);
        }

        $request = $this->buildCreateRequest($kind, $preview, $employeeId);

        if (!$request->add()) {
            return array('ok' => false, 'error' => 'Could not save the approval request.');
        }

        return array(
            'ok' => true,
            'status' => 'pending_approval',
            'approval_id' => (int) $request->id,
            'approval_uuid' => $request->uuid,
            'changes' => $preview['changes'],
            'note' => 'A different employee must approve this in AI Bridge -> Approvals before it takes effect.',
        );
    }

    private function buildCreateRequest($kind, array $preview, $employeeId)
    {
        switch ($kind) {
            case 'product_create':
                return AiBridgeApprovalRequest::createPendingProductCreate($preview, $preview['canonical_payload'], $employeeId);
            case 'category_create':
                return AiBridgeApprovalRequest::createPendingCategoryCreate($preview, $preview['canonical_payload'], $employeeId);
            case 'manufacturer_create':
                return AiBridgeApprovalRequest::createPendingManufacturerCreate($preview, $preview['canonical_payload'], $employeeId);
        }

        throw new \RuntimeException('Unknown creation kind: ' . $kind);
    }

    private function productUpdate(array $arguments, $employeeId)
    {
        $id = (int) ($arguments['id'] ?? 0);
        $payload = is_array($arguments['payload'] ?? null) ? $arguments['payload'] : array();

        if ($id <= 0) {
            return array('ok' => false, 'error' => 'A product id is required.');
        }

        $context = Context::getContext();
        $preview = (new AiBridgeProductPreview())->build($id, $payload, (int) $context->language->id, (int) $context->shop->id);

        if ($preview === null) {
            return array('ok' => false, 'error' => 'Product not found.');
        }

        if (!$preview['valid']) {
            return array('ok' => false, 'error' => 'The proposed changes are not valid.');
        }

        if (empty($preview['changes'])) {
            return array('ok' => true, 'status' => 'no_changes', 'note' => 'The product already matches the requested values.');
        }

        $request = AiBridgeApprovalRequest::createPending($preview, $preview['canonical_payload'], $employeeId);

        if (!$request->add()) {
            return array('ok' => false, 'error' => 'Could not save the approval request.');
        }

        return array(
            'ok' => true,
            'status' => 'pending_approval',
            'approval_id' => (int) $request->id,
            'approval_uuid' => $request->uuid,
            'changes' => $preview['changes'],
            'note' => 'A different employee must approve this in AI Bridge -> Approvals before it takes effect.',
        );
    }

    private function categoryUpdate(array $arguments, $employeeId)
    {
        $id = (int) ($arguments['id'] ?? 0);
        $payload = is_array($arguments['payload'] ?? null) ? $arguments['payload'] : array();

        if ($id <= 0) {
            return array('ok' => false, 'error' => 'A category id is required.');
        }

        $context = Context::getContext();
        $preview = (new AiBridgeCategoryPreview())->build($id, $payload, (int) $context->language->id, (int) $context->shop->id);

        if ($preview === null) {
            return array('ok' => false, 'error' => 'Category not found.');
        }

        if (!$preview['valid']) {
            return array('ok' => false, 'error' => 'The proposed changes are not valid.');
        }

        if (empty($preview['changes'])) {
            return array('ok' => true, 'status' => 'no_changes', 'note' => 'The category already matches the requested values.');
        }

        $request = AiBridgeApprovalRequest::createPendingCategoryUpdate($preview, $preview['canonical_payload'], $employeeId);

        if (!$request->add()) {
            return array('ok' => false, 'error' => 'Could not save the approval request.');
        }

        return array(
            'ok' => true,
            'status' => 'pending_approval',
            'approval_id' => (int) $request->id,
            'approval_uuid' => $request->uuid,
            'changes' => $preview['changes'],
            'note' => 'A different employee must approve this in AI Bridge -> Approvals before it takes effect.',
        );
    }
}
