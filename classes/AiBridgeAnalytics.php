<?php

/**
 * Read-only aggregate queries for the Analytics dashboard. Raw SQL on
 * purpose — these are aggregate reports over ps_orders/ps_order_detail,
 * loading full Order/Customer ObjectModel instances per row would be far
 * slower for no benefit here.
 */
class AiBridgeAnalytics
{
    public function kpis($from, $to)
    {
        $row = Db::getInstance()->getRow(
            'SELECT COUNT(*) AS orders_count,
                COALESCE(SUM(total_paid_real / NULLIF(conversion_rate, 0)), 0) AS revenue,
                COALESCE(AVG(total_paid_real / NULLIF(conversion_rate, 0)), 0) AS avg_order
            FROM `' . _DB_PREFIX_ . 'orders`
            WHERE valid = 1 AND date_add BETWEEN "' . pSQL($from) . ' 00:00:00" AND "' . pSQL($to) . ' 23:59:59"'
        );

        $newCustomers = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer`
            WHERE date_add BETWEEN "' . pSQL($from) . ' 00:00:00" AND "' . pSQL($to) . ' 23:59:59"'
        );

        return array(
            'orders_count' => (int) ($row['orders_count'] ?? 0),
            'revenue' => (float) ($row['revenue'] ?? 0),
            'avg_order' => (float) ($row['avg_order'] ?? 0),
            'new_customers' => $newCustomers,
        );
    }

    public function salesByDay($from, $to)
    {
        return (array) Db::getInstance()->executeS(
            'SELECT DATE(date_add) AS d, COUNT(*) AS orders_count,
                COALESCE(SUM(total_paid_real / NULLIF(conversion_rate, 0)), 0) AS revenue
            FROM `' . _DB_PREFIX_ . 'orders`
            WHERE valid = 1 AND date_add BETWEEN "' . pSQL($from) . ' 00:00:00" AND "' . pSQL($to) . ' 23:59:59"
            GROUP BY DATE(date_add)
            ORDER BY d ASC'
        );
    }

    public function topProducts($from, $to, $limit = 10)
    {
        return (array) Db::getInstance()->executeS(
            'SELECT od.product_id, od.product_name,
                SUM(od.product_quantity) AS qty_sold,
                SUM(od.total_price_tax_incl) AS revenue
            FROM `' . _DB_PREFIX_ . 'order_detail` od
            INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.id_order = od.id_order
            WHERE o.valid = 1 AND o.date_add BETWEEN "' . pSQL($from) . ' 00:00:00" AND "' . pSQL($to) . ' 23:59:59"
            GROUP BY od.product_id
            ORDER BY qty_sold DESC
            LIMIT ' . (int) $limit
        );
    }

    public function topCustomers($from, $to, $limit = 10)
    {
        return (array) Db::getInstance()->executeS(
            'SELECT o.id_customer, c.firstname, c.lastname, c.email,
                COUNT(*) AS orders_count,
                COALESCE(SUM(o.total_paid_real / NULLIF(o.conversion_rate, 0)), 0) AS spent
            FROM `' . _DB_PREFIX_ . 'orders` o
            INNER JOIN `' . _DB_PREFIX_ . 'customer` c ON c.id_customer = o.id_customer
            WHERE o.valid = 1 AND o.date_add BETWEEN "' . pSQL($from) . ' 00:00:00" AND "' . pSQL($to) . ' 23:59:59"
            GROUP BY o.id_customer
            ORDER BY spent DESC
            LIMIT ' . (int) $limit
        );
    }

    /**
     * Customers who have at least one valid order, but nothing in the last
     * $days days — a win-back list, not "never purchased" customers.
     */
    public function inactiveCustomers($days = 90, $limit = 50)
    {
        return (array) Db::getInstance()->executeS(
            'SELECT c.id_customer, c.firstname, c.lastname, c.email,
                MAX(o.date_add) AS last_order_at,
                COUNT(o.id_order) AS orders_total,
                DATEDIFF(NOW(), MAX(o.date_add)) AS days_inactive
            FROM `' . _DB_PREFIX_ . 'customer` c
            INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.id_customer = c.id_customer AND o.valid = 1
            WHERE c.deleted = 0
            GROUP BY c.id_customer
            HAVING MAX(o.date_add) < DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)
            ORDER BY last_order_at DESC
            LIMIT ' . (int) $limit
        );
    }

    /**
     * Wephone's own "wephonebackofficecolumns" module tracks real invoicing
     * status per order (an employee manually assigns an invoice number and
     * marks it paid) in `wephone_order_billing` — that reflects actual
     * accounting state far better than PrestaShop's own valid/total_paid_real.
     * Returns null when that table doesn't exist (any other install of this
     * module), so callers can skip the section entirely.
     */
    public function wephoneBillingStatus($from, $to)
    {
        if (!$this->hasWephoneBillingTable()) {
            return null;
        }

        $row = Db::getInstance()->getRow(
            'SELECT
                COALESCE(SUM(CASE WHEN wob.invoice_number IS NOT NULL AND wob.invoice_number != \'\' AND wob.payment_status = \'paid\'
                    THEN COALESCE(wob.invoice_total, o.total_paid_tax_incl) ELSE 0 END), 0) AS paid_amount,
                SUM(CASE WHEN wob.invoice_number IS NOT NULL AND wob.invoice_number != \'\' AND wob.payment_status = \'paid\' THEN 1 ELSE 0 END) AS paid_count,
                COALESCE(SUM(CASE WHEN wob.invoice_number IS NOT NULL AND wob.invoice_number != \'\' AND wob.payment_status != \'paid\'
                    THEN COALESCE(wob.invoice_total, o.total_paid_tax_incl) ELSE 0 END), 0) AS pending_amount,
                SUM(CASE WHEN wob.invoice_number IS NOT NULL AND wob.invoice_number != \'\' AND wob.payment_status != \'paid\' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN wob.invoice_number IS NULL OR wob.invoice_number = \'\' THEN 1 ELSE 0 END) AS not_invoiced_count
            FROM `' . _DB_PREFIX_ . 'orders` o
            LEFT JOIN `' . _DB_PREFIX_ . 'wephone_order_billing` wob ON wob.id_order = o.id_order
            WHERE o.valid = 1 AND o.date_add BETWEEN "' . pSQL($from) . ' 00:00:00" AND "' . pSQL($to) . ' 23:59:59"'
        );

        if (!is_array($row)) {
            return null;
        }

        return array(
            'paid_amount' => (float) $row['paid_amount'],
            'paid_count' => (int) $row['paid_count'],
            'pending_amount' => (float) $row['pending_amount'],
            'pending_count' => (int) $row['pending_count'],
            'not_invoiced_count' => (int) $row['not_invoiced_count'],
        );
    }

    private function hasWephoneBillingTable()
    {
        static $exists = null;
        if ($exists === null) {
            // executeS(), not getValue() - PrestaShop's Db::getValue()/getRow()
            // append "LIMIT 1" unconditionally, and "SHOW TABLES ... LIMIT 1"
            // is invalid syntax on some MariaDB versions.
            $exists = (bool) Db::getInstance()->executeS(
                "SHOW TABLES LIKE '" . _DB_PREFIX_ . "wephone_order_billing'"
            );
        }

        return $exists;
    }

    public function lowStockProducts($threshold = 5, $limit = 20)
    {
        $languageId = (int) Context::getContext()->language->id;

        return (array) Db::getInstance()->executeS(
            'SELECT p.id_product, pl.name, sa.quantity
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.id_product = p.id_product AND pl.id_lang = ' . $languageId . '
                ' . Shop::addSqlRestrictionOnLang('pl') . '
            INNER JOIN `' . _DB_PREFIX_ . 'stock_available` sa
                ON sa.id_product = p.id_product AND sa.id_product_attribute = 0
            WHERE p.active = 1 AND sa.quantity <= ' . (int) $threshold . '
            ORDER BY sa.quantity ASC
            LIMIT ' . (int) $limit
        );
    }
}
