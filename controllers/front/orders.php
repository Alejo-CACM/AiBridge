<?php

class AibridgeOrdersModuleFrontController extends ModuleFrontController
{
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

            $languageId = (int) $this->context->language->id;
            $orderId = (int) Tools::getValue('id', 0);

            if ($orderId > 0) {
                $order = $this->getOrderDetail($orderId, $languageId);
                if ($order === null) {
                    $this->sendJson(404, array(
                        'success' => false,
                        'error' => array('code' => 'order_not_found', 'message' => 'Order not found.'),
                    ));
                }

                $this->sendJson(200, array('success' => true, 'data' => array('order' => $order)));
            }

            $statusId = (int) Tools::getValue('status_id', 0);
            $customerId = (int) Tools::getValue('customer_id', 0);
            $page = max(1, (int) Tools::getValue('page', 1));
            $limit = min(100, max(1, (int) Tools::getValue('limit', 50)));
            $offset = ($page - 1) * $limit;

            $orders = $this->getOrderList($statusId, $customerId, $languageId, $offset, $limit);

            $this->sendJson(200, array(
                'success' => true,
                'data' => array(
                    'page' => $page,
                    'limit' => $limit,
                    'status_id' => $statusId > 0 ? $statusId : null,
                    'customer_id' => $customerId > 0 ? $customerId : null,
                    'count' => count($orders),
                    'orders' => $orders,
                ),
            ));
        } catch (\Throwable $exception) {
            $this->sendJson(500, array(
                'success' => false,
                'error' => array('code' => 'orders_internal_error', 'message' => 'Orders could not be loaded.'),
            ));
        }
    }

    private function getOrderList($statusId, $customerId, $languageId, $offset, $limit)
    {
        $where = array();
        if ($statusId > 0) {
            $where[] = 'o.`current_state` = ' . (int) $statusId;
        }
        if ($customerId > 0) {
            $where[] = 'o.`id_customer` = ' . (int) $customerId;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT o.`id_order`, o.`reference`, o.`id_customer`, o.`current_state`,
                o.`total_paid_tax_incl`, o.`total_paid_tax_excl`, o.`date_add`,
                osl.`name` AS status_name,
                CONCAT(c.`firstname`, \' \', c.`lastname`) AS customer_name, c.`email` AS customer_email
            FROM `' . _DB_PREFIX_ . 'orders` o
            INNER JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`
            LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl
                ON osl.`id_order_state` = o.`current_state` AND osl.`id_lang` = ' . (int) $languageId . '
            ' . (!empty($where) ? 'WHERE ' . implode(' AND ', $where) : '') . '
            ORDER BY o.`id_order` DESC
            LIMIT ' . (int) $offset . ', ' . (int) $limit
        );

        if (!is_array($rows)) {
            return array();
        }

        $orders = array();
        foreach ($rows as $row) {
            $orders[] = array(
                'id' => (int) $row['id_order'],
                'reference' => (string) $row['reference'],
                'customer' => array(
                    'id' => (int) $row['id_customer'],
                    'name' => trim((string) $row['customer_name']),
                    'email' => (string) $row['customer_email'],
                ),
                'status' => array(
                    'id' => (int) $row['current_state'],
                    'name' => $row['status_name'] !== null ? (string) $row['status_name'] : null,
                ),
                'total_paid_tax_incl' => (float) $row['total_paid_tax_incl'],
                'total_paid_tax_excl' => (float) $row['total_paid_tax_excl'],
                'date_add' => (string) $row['date_add'],
            );
        }

        return $orders;
    }

    private function getOrderDetail($orderId, $languageId)
    {
        $row = Db::getInstance()->getRow(
            'SELECT o.`id_order`, o.`reference`, o.`id_customer`, o.`current_state`,
                o.`id_address_delivery`, o.`id_address_invoice`, o.`id_carrier`,
                o.`payment`, o.`total_paid_tax_incl`, o.`total_paid_tax_excl`,
                o.`total_shipping_tax_incl`, o.`total_discounts_tax_incl`, o.`date_add`,
                osl.`name` AS status_name,
                CONCAT(c.`firstname`, \' \', c.`lastname`) AS customer_name, c.`email` AS customer_email,
                cur.`iso_code` AS currency_code
            FROM `' . _DB_PREFIX_ . 'orders` o
            INNER JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`
            LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl
                ON osl.`id_order_state` = o.`current_state` AND osl.`id_lang` = ' . (int) $languageId . '
            LEFT JOIN `' . _DB_PREFIX_ . 'currency` cur ON cur.`id_currency` = o.`id_currency`
            WHERE o.`id_order` = ' . (int) $orderId
        );

        if (!is_array($row)) {
            return null;
        }

        return array(
            'id' => (int) $row['id_order'],
            'reference' => (string) $row['reference'],
            'customer' => array(
                'id' => (int) $row['id_customer'],
                'name' => trim((string) $row['customer_name']),
                'email' => (string) $row['customer_email'],
            ),
            'status' => array(
                'id' => (int) $row['current_state'],
                'name' => $row['status_name'] !== null ? (string) $row['status_name'] : null,
            ),
            'payment' => (string) $row['payment'],
            'currency' => $row['currency_code'] !== null ? (string) $row['currency_code'] : null,
            'total_paid_tax_incl' => (float) $row['total_paid_tax_incl'],
            'total_paid_tax_excl' => (float) $row['total_paid_tax_excl'],
            'total_shipping_tax_incl' => (float) $row['total_shipping_tax_incl'],
            'total_discounts_tax_incl' => (float) $row['total_discounts_tax_incl'],
            'date_add' => (string) $row['date_add'],
            'address_delivery' => $this->getAddress((int) $row['id_address_delivery']),
            'address_invoice' => $this->getAddress((int) $row['id_address_invoice']),
            'products' => $this->getOrderProducts($orderId),
            'history' => $this->getOrderHistory($orderId, $languageId),
        );
    }

    private function getAddress($addressId)
    {
        if ($addressId <= 0) {
            return null;
        }

        $row = Db::getInstance()->getRow(
            'SELECT `id_address`, `alias`, `firstname`, `lastname`, `company`, `address1`, `address2`,
                `postcode`, `city`, `id_country`, `id_state`, `phone`, `phone_mobile`
            FROM `' . _DB_PREFIX_ . 'address`
            WHERE `id_address` = ' . (int) $addressId
        );

        if (!is_array($row)) {
            return null;
        }

        return array(
            'id' => (int) $row['id_address'],
            'alias' => (string) $row['alias'],
            'firstname' => (string) $row['firstname'],
            'lastname' => (string) $row['lastname'],
            'company' => (string) $row['company'],
            'address1' => (string) $row['address1'],
            'address2' => (string) $row['address2'],
            'postcode' => (string) $row['postcode'],
            'city' => (string) $row['city'],
            'id_country' => (int) $row['id_country'],
            'id_state' => (int) $row['id_state'],
            'phone' => (string) $row['phone'],
            'phone_mobile' => (string) $row['phone_mobile'],
        );
    }

    private function getOrderProducts($orderId)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_order_detail`, `product_id`, `product_name`, `product_reference`,
                `product_quantity`, `unit_price_tax_incl`, `total_price_tax_incl`
            FROM `' . _DB_PREFIX_ . 'order_detail`
            WHERE `id_order` = ' . (int) $orderId
        );

        if (!is_array($rows)) {
            return array();
        }

        $products = array();
        foreach ($rows as $row) {
            $products[] = array(
                'id_product' => (int) $row['product_id'],
                'name' => (string) $row['product_name'],
                'reference' => (string) $row['product_reference'],
                'quantity' => (int) $row['product_quantity'],
                'unit_price_tax_incl' => (float) $row['unit_price_tax_incl'],
                'total_price_tax_incl' => (float) $row['total_price_tax_incl'],
            );
        }

        return $products;
    }

    private function getOrderHistory($orderId, $languageId)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT oh.`id_order_state`, oh.`date_add`, osl.`name`
            FROM `' . _DB_PREFIX_ . 'order_history` oh
            LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl
                ON osl.`id_order_state` = oh.`id_order_state` AND osl.`id_lang` = ' . (int) $languageId . '
            WHERE oh.`id_order` = ' . (int) $orderId . '
            ORDER BY oh.`date_add` ASC'
        );

        if (!is_array($rows)) {
            return array();
        }

        $history = array();
        foreach ($rows as $row) {
            $history[] = array(
                'id_order_state' => (int) $row['id_order_state'],
                'name' => $row['name'] !== null ? (string) $row['name'] : null,
                'date_add' => (string) $row['date_add'],
            );
        }

        return $history;
    }

    private function sendJson($statusCode, array $payload)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}
