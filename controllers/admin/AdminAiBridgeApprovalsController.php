<?php

require_once dirname(__FILE__) . '/../../classes/AiBridgeProductPreview.php';
require_once dirname(__FILE__) . '/../../classes/AiBridgeApprovalRequest.php';
require_once dirname(__FILE__) . '/../../classes/AiBridgeApprovalExecutor.php';

class AdminAiBridgeApprovalsController extends ModuleAdminController
{
    public function displayActor($value)
    {
        return (int) $value === 0 ? 'API/Codex' : (int) $value;
    }

    public function displayOperationType($value)
    {
        if ($value === AiBridgeApprovalRequest::OPERATION_CREATE) {
            return 'Producto pendiente de crear';
        }

        return 'Actualización de producto';
    }

    public function displayCreatedProduct($value)
    {
        return (int) $value > 0 ? (int) $value : '-';
    }

    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'aibridge_approval_request';
        $this->className = 'AiBridgeApprovalRequest';

        parent::__construct();
        $this->addRowAction('view');
        $this->addRowAction('approve');
        $this->addRowAction('reject');
        $this->addRowAction('execute');
    }

    public function initToolbar()
    {
        parent::initToolbar();

        if ($this->access('add')) {
            $this->toolbar_btn['new'] = array(
                'href' => self::$currentIndex . '&add' . $this->table
                    . '&token=' . $this->token,
                'desc' => 'Create pending request',
            );
        }
    }
    public function displayApproveLink($token = null, $id = null)
    {
        return '<a href="' . $this->context->link->getAdminLink('AdminAiBridgeApprovals')
            . '&approve_aibridge_approval_request=' . (int) $id . '&id_aibridge_approval_request=' . (int) $id
            . '&token=' . $this->token . '">Approve</a>';
    }

    public function displayExecuteLink($token = null, $id = null)
    {
        $request = new AiBridgeApprovalRequest((int) $id);

        if (!Validate::isLoadedObject($request)
            || $request->status !== AiBridgeApprovalRequest::STATUS_APPROVED) {
            return '';
        }

        return '<a href="' . $this->context->link->getAdminLink('AdminAiBridgeApprovals')
            . '&execute_aibridge_approval_request=' . (int) $id
            . '&id_aibridge_approval_request=' . (int) $id
            . '&token=' . $this->token . '">Execute</a>';
    }
    public function displayRejectLink($token = null, $id = null)
    {
        return '<a href="' . $this->context->link->getAdminLink('AdminAiBridgeApprovals')
            . '&reject_aibridge_approval_request=' . (int) $id . '&id_aibridge_approval_request=' . (int) $id
            . '&token=' . $this->token . '">Reject</a>';
    }
    public function renderList()
    {
        $this->fields_list = array(
            'uuid' => array('title' => 'UUID'),
            'status' => array('title' => 'Status'),
            'operation_type' => array('title' => 'Operation', 'callback' => 'displayOperationType'),
            'product_id' => array('title' => 'Product ID'),
            'created_product_id' => array('title' => 'Created product ID', 'callback' => 'displayCreatedProduct'),
            'shop_id' => array('title' => 'Shop ID'),
            'language_id' => array('title' => 'Language ID'),
            'created_by_employee_id' => array('title' => 'Creator', 'callback' => 'displayActor'),
            'expires_at' => array('title' => 'Expires at', 'callback' => 'formatDateForDisplay'),
        );
        return parent::renderList();
    }

    public function renderView()
    {
        $id = (int) Tools::getValue('id_aibridge_approval_request');
        $request = new AiBridgeApprovalRequest($id);

        if (!Validate::isLoadedObject($request)) {
            return '';
        }

        $productTargetDisplay = $request->operation_type === AiBridgeApprovalRequest::OPERATION_CREATE
            ? ((int) $request->created_product_id > 0
                ? 'Producto creado: ' . (int) $request->created_product_id
                : 'Producto pendiente de crear')
            : 'Producto existente: ' . (int) $request->product_id;

        $this->context->smarty->assign(array(
            'approval_request' => $request,
            'product_target_display' => $productTargetDisplay,
            'created_at_display' => $this->formatDateForDisplay($request->created_at),
            'expires_at_display' => $this->formatDateForDisplay($request->expires_at),
        ));

        return $this->context->smarty->fetch(
            'module:aibridge/views/templates/admin/approval_view.tpl'
        );
    }
    public function formatDateForDisplay($value)
    {
        try {
            $date = new DateTime($value);
            $date->setTimezone(new DateTimeZone((string) Configuration::get('PS_TIMEZONE')));

            return $date->format('Y-m-d H:i:s');
        } catch (Exception $exception) {
            return '';
        }
    }
    public function renderForm()
    {
        $this->fields_form = array(
            'legend' => array('title' => 'Create approval request'),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => 'Product ID',
                    'name' => 'product_id',
                    'required' => true,
                ),
                array(
                    'type' => 'textarea',
                    'label' => 'Payload JSON',
                    'name' => 'payload',
                    'required' => true,
                    'cols' => 80,
                    'rows' => 12,
                ),
            ),
            'submit' => array(
                'title' => 'Create pending request',
                'name' => 'submitCreateAiBridgeApprovalRequest',
            ),
        );

        return parent::renderForm();
    }

    private function getTransitionRejectionReason($request, $employeeId, $approval)
    {
        if (!Validate::isLoadedObject($request)) {
            return 'Approval request was not found.';
        }

        if ((int) $request->created_by_employee_id === (int) $employeeId) {
            return 'Same employee cannot approve or reject this request.';
        }

        if ($request->status !== AiBridgeApprovalRequest::STATUS_PENDING) {
            return 'Invalid state: ' . $request->status . '.';
        }

        if ($approval && strtotime($request->expires_at) < time()) {
            return 'Approval request has expired.';
        }

        return null;
    }
    public function postProcess()
    {
        if (!$this->access('edit')) {
            throw new PrestaShopException('Access denied.');
        }

        if (Tools::isSubmit('submitCreateAiBridgeApprovalRequest')) {
            return $this->processAdd();
        }

        if (Tools::getValue('approve_aibridge_approval_request')) {
            return $this->processApprove();
        }

        if (Tools::getValue('execute_aibridge_approval_request')) { return $this->processExecute(); }

        if (Tools::getValue('reject_aibridge_approval_request')) {
            return $this->processReject();
        }

        return parent::postProcess();
    }

    public function processAdd()
    {
        if (Tools::getValue('token') !== Tools::getAdminTokenLite('AdminAiBridgeApprovals')) {
            throw new PrestaShopException('Invalid token.');
        }

        $payload = json_decode((string) Tools::getValue('payload'), true);

        if (!is_array($payload)) {
            $this->errors[] = 'Payload JSON is invalid.';
            return false;
        }

        $preview = (new AiBridgeProductPreview())->build(
            (int) Tools::getValue('product_id'),
            $payload,
            (int) $this->context->language->id,
            (int) $this->context->shop->id
        );

        if (!$preview || !$preview['valid']) {
            $this->errors[] = 'The proposed changes are not valid.';
            return false;
        }

        if (empty($preview['changes'])) {
            $this->errors[] = 'The proposed changes do not contain real changes.';
            return false;
        }

        $request = AiBridgeApprovalRequest::createPending(
            $preview,
            $preview['canonical_payload'],
            (int) $this->context->employee->id
        );

        if (!$request->add()) {
            $this->errors[] = 'The approval request could not be created.';
            return false;
        }

        Tools::redirectAdmin(self::$currentIndex . '&token=' . $this->token);
    }

    public function processApprove()
    {
        return $this->processTransition(true);
    }

    public function processExecute()
    {
        if (Tools::getValue('token') !== Tools::getAdminTokenLite('AdminAiBridgeApprovals')) { throw new PrestaShopException('Invalid token.'); }
        $request = new AiBridgeApprovalRequest((int) Tools::getValue('id_aibridge_approval_request'));
        if (!Validate::isLoadedObject($request) || !(new AiBridgeApprovalExecutor())->execute($request, (int) $this->context->employee->id)) { $this->errors[] = 'Execution failed.'; }
        return false;
    }
    public function processReject()
    {
        return $this->processTransition(false);
    }

    public function processTransition($approval)
    {
        if (Tools::getValue('token') !== Tools::getAdminTokenLite('AdminAiBridgeApprovals')) {
            throw new PrestaShopException('Invalid token.');
        }

        $requestId = (int) Tools::getValue('id_aibridge_approval_request');

        if (!$requestId) {
            $this->errors[] = 'Approval request was not found.';
            return false;
        }

        $request = new AiBridgeApprovalRequest($requestId);
        $employeeId = (int) $this->context->employee->id;
        $result = $approval ? $request->approve($employeeId) : $request->reject($employeeId);

        if (!Validate::isLoadedObject($request) || !$result) {
            $this->errors[] = $approval
                ? 'Approval transition rejected.'
                : 'Rejection transition rejected.';
        }

        return false;
    }
}