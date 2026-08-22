<?php

class AibridgeCustomersModuleFrontController extends ModuleFrontController
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

            $customerId = (int) Tools::getValue('id', 0);

            if ($customerId > 0) {
                $customer = $this->getCustomerDetail($customerId);
                if ($customer === null) {
                    $this->sendJson(404, array(
                        'success' => false,
                        'error' => array('code' => 'customer_not_found', 'message' => 'Customer not found.'),
                    ));
                }

                $this->sendJson(200, array('success' => true, 'data' => array('customer' => $customer)));
            }

            $search = trim((string) Tools::getValue('search', ''));
            $page = max(1, (int) Tools::getValue('page', 1));
            $limit = min(100, max(1, (int) Tools::getValue('limit', 50)));
            $offset = ($page - 1) * $limit;

            if ($search === '') {
                $this->sendJson(400, array(
                    'success' => false,
                    'error' => array('code' => 'missing_search', 'message' => 'A "search" query parameter is required (name, email or phone).'),
                ));
            }

            $customers = $this->searchCustomers($search, $offset, $limit);

            $this->sendJson(200, array(
                'success' => true,
                'data' => array(
                    'page' => $page,
                    'limit' => $limit,
                    'search' => $search,
                    'count' => count($customers),
                    'customers' => $customers,
                ),
            ));
        } catch (\Throwable $exception) {
            $this->sendJson(500, array(
                'success' => false,
                'error' => array('code' => 'customers_internal_error', 'message' => 'Customers could not be loaded.'),
            ));
        }
    }

    private function searchCustomers($search, $offset, $limit)
    {
        $like = pSQL($search, true);

        $rows = Db::getInstance()->executeS(
            'SELECT `id_customer`, `firstname`, `lastname`, `email`, `active`, `date_add`
            FROM `' . _DB_PREFIX_ . 'customer`
            WHERE `deleted` = 0 AND (
                `email` LIKE \'%' . $like . '%\'
                OR `firstname` LIKE \'%' . $like . '%\'
                OR `lastname` LIKE \'%' . $like . '%\'
                OR CONCAT(`firstname`, \' \', `lastname`) LIKE \'%' . $like . '%\'
            )
            ORDER BY `id_customer` DESC
            LIMIT ' . (int) $offset . ', ' . (int) $limit
        );

        if (!is_array($rows)) {
            $rows = array();
        }

        if (empty($rows)) {
            $phoneMatches = Db::getInstance()->executeS(
                'SELECT DISTINCT c.`id_customer`, c.`firstname`, c.`lastname`, c.`email`, c.`active`, c.`date_add`
                FROM `' . _DB_PREFIX_ . 'customer` c
                INNER JOIN `' . _DB_PREFIX_ . 'address` a ON a.`id_customer` = c.`id_customer` AND a.`deleted` = 0
                WHERE c.`deleted` = 0 AND (a.`phone` LIKE \'%' . $like . '%\' OR a.`phone_mobile` LIKE \'%' . $like . '%\')
                ORDER BY c.`id_customer` DESC
                LIMIT ' . (int) $offset . ', ' . (int) $limit
            );

            if (is_array($phoneMatches)) {
                $rows = $phoneMatches;
            }
        }

        $customers = array();
        foreach ($rows as $row) {
            $customers[] = array(
                'id' => (int) $row['id_customer'],
                'firstname' => (string) $row['firstname'],
                'lastname' => (string) $row['lastname'],
                'email' => (string) $row['email'],
                'active' => (int) $row['active'],
                'date_add' => (string) $row['date_add'],
            );
        }

        return $customers;
    }

    private function getCustomerDetail($customerId)
    {
        $row = Db::getInstance()->getRow(
            'SELECT `id_customer`, `firstname`, `lastname`, `email`, `active`, `date_add`, `note`
            FROM `' . _DB_PREFIX_ . 'customer`
            WHERE `id_customer` = ' . (int) $customerId . ' AND `deleted` = 0'
        );

        if (!is_array($row)) {
            return null;
        }

        $addressRows = Db::getInstance()->executeS(
            'SELECT `id_address`, `alias`, `firstname`, `lastname`, `company`, `address1`, `address2`,
                `postcode`, `city`, `id_country`, `id_state`, `phone`, `phone_mobile`
            FROM `' . _DB_PREFIX_ . 'address`
            WHERE `id_customer` = ' . (int) $customerId . ' AND `deleted` = 0'
        );

        $addresses = array();
        if (is_array($addressRows)) {
            foreach ($addressRows as $addressRow) {
                $addresses[] = array(
                    'id' => (int) $addressRow['id_address'],
                    'alias' => (string) $addressRow['alias'],
                    'firstname' => (string) $addressRow['firstname'],
                    'lastname' => (string) $addressRow['lastname'],
                    'company' => (string) $addressRow['company'],
                    'address1' => (string) $addressRow['address1'],
                    'address2' => (string) $addressRow['address2'],
                    'postcode' => (string) $addressRow['postcode'],
                    'city' => (string) $addressRow['city'],
                    'id_country' => (int) $addressRow['id_country'],
                    'id_state' => (int) $addressRow['id_state'],
                    'phone' => (string) $addressRow['phone'],
                    'phone_mobile' => (string) $addressRow['phone_mobile'],
                );
            }
        }

        $orderCount = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_customer` = ' . (int) $customerId
        );

        return array(
            'id' => (int) $row['id_customer'],
            'firstname' => (string) $row['firstname'],
            'lastname' => (string) $row['lastname'],
            'email' => (string) $row['email'],
            'active' => (int) $row['active'],
            'note' => (string) $row['note'],
            'date_add' => (string) $row['date_add'],
            'order_count' => $orderCount,
            'addresses' => $addresses,
        );
    }

    private function sendJson($statusCode, array $payload)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}
