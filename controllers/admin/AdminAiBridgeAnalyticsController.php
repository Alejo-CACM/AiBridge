<?php

require_once dirname(__FILE__) . '/../../classes/AiBridgeAnalytics.php';

class AdminAiBridgeAnalyticsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();
    }

    /**
     * This is a plain dashboard, not an ObjectModel list — $this->table is
     * intentionally left unset. renderList() is the hook ModuleAdminController
     * calls by default (no add/edit/view id in the request), so overriding
     * it and never calling parent::renderList() is the safe way to serve
     * fully custom content without fighting HelperList's table assumptions.
     */
    public function renderList()
    {
        list($from, $to, $preset) = $this->resolveRange();
        $analytics = new AiBridgeAnalytics();

        $kpis = $analytics->kpis($from, $to);
        $salesByDay = $analytics->salesByDay($from, $to);
        $topProducts = $analytics->topProducts($from, $to);
        $topCustomers = $analytics->topCustomers($from, $to);
        $inactiveCustomers = $analytics->inactiveCustomers(90);
        $lowStock = $analytics->lowStockProducts(5);

        return $this->renderRangeForm($from, $to, $preset)
            . $this->renderKpis($kpis)
            . $this->renderSalesByDay($salesByDay)
            . '<div class="row">'
            . '<div class="col-lg-6">' . $this->renderTopProducts($topProducts) . '</div>'
            . '<div class="col-lg-6">' . $this->renderTopCustomers($topCustomers) . '</div>'
            . '</div>'
            . '<div class="row">'
            . '<div class="col-lg-6">' . $this->renderInactiveCustomers($inactiveCustomers) . '</div>'
            . '<div class="col-lg-6">' . $this->renderLowStock($lowStock) . '</div>'
            . '</div>';
    }

    private function resolveRange()
    {
        $preset = (string) Tools::getValue('preset', '30d');
        $customFrom = (string) Tools::getValue('from', '');
        $customTo = (string) Tools::getValue('to', '');

        $today = new DateTime();
        $to = clone $today;
        $from = clone $today;

        switch ($preset) {
            case 'today':
                break;
            case '7d':
                $from->modify('-6 days');
                break;
            case 'this_month':
                $from->modify('first day of this month');
                break;
            case 'last_month':
                $from->modify('first day of last month');
                $to->modify('last day of last month');
                break;
            case 'custom':
                if ($customFrom !== '' && $customTo !== '') {
                    try {
                        $from = new DateTime($customFrom);
                        $to = new DateTime($customTo);
                    } catch (\Exception $exception) {
                        $preset = '30d';
                        $from = clone $today;
                        $from->modify('-29 days');
                    }
                }
                break;
            case '30d':
            default:
                $preset = '30d';
                $from->modify('-29 days');
                break;
        }

        if ($from > $to) {
            list($from, $to) = array($to, $from);
        }

        return array($from->format('Y-m-d'), $to->format('Y-m-d'), $preset);
    }

    private function renderRangeForm($from, $to, $preset)
    {
        $presets = array(
            'today' => 'Hoy',
            '7d' => 'Últimos 7 días',
            '30d' => 'Últimos 30 días',
            'this_month' => 'Este mes',
            'last_month' => 'Mes pasado',
        );

        $baseUrl = self::$currentIndex . '&token=' . $this->token;

        $html = '<div class="panel"><div class="panel-heading">'
            . '<i class="icon-bar-chart"></i> ' . $this->l('Analítica') . '</div><div style="padding:15px;">';

        foreach ($presets as $key => $label) {
            $active = $preset === $key ? ' btn-primary' : ' btn-default';
            $html .= '<a class="btn' . $active . '" style="margin-right:5px;margin-bottom:10px;" href="'
                . $baseUrl . '&preset=' . $key . '">' . $label . '</a>';
        }

        $html .= '<form method="get" style="display:inline-block;margin-left:15px;">'
            . '<input type="hidden" name="controller" value="AdminAiBridgeAnalytics" />'
            . '<input type="hidden" name="token" value="' . Tools::safeOutput($this->token) . '" />'
            . '<input type="hidden" name="preset" value="custom" />'
            . '<input type="date" name="from" value="' . Tools::safeOutput($from) . '" class="form-control" style="display:inline-block;width:auto;" />'
            . ' — <input type="date" name="to" value="' . Tools::safeOutput($to) . '" class="form-control" style="display:inline-block;width:auto;" />'
            . ' <button type="submit" class="btn btn-default">' . $this->l('Aplicar') . '</button>'
            . '</form>'
            . '<p class="help-block">' . sprintf($this->l('Período mostrado: %s a %s'), $from, $to) . '</p>'
            . '</div></div>';

        return $html;
    }

    private function renderKpis(array $kpis)
    {
        $cards = array(
            array('label' => 'Pedidos', 'value' => (string) $kpis['orders_count']),
            array('label' => 'Ventas', 'value' => $this->formatMoney($kpis['revenue'])),
            array('label' => 'Ticket promedio', 'value' => $this->formatMoney($kpis['avg_order'])),
            array('label' => 'Clientes nuevos', 'value' => (string) $kpis['new_customers']),
        );

        $html = '<div class="row">';
        foreach ($cards as $card) {
            $html .= '<div class="col-lg-3 col-md-6"><div class="panel" style="text-align:center;padding:20px;">'
                . '<div style="font-size:26px;font-weight:bold;">' . Tools::safeOutput($card['value']) . '</div>'
                . '<div class="text-muted">' . Tools::safeOutput($card['label']) . '</div>'
                . '</div></div>';
        }
        $html .= '</div>';

        return $html;
    }

    private function renderSalesByDay(array $rows)
    {
        $html = '<div class="panel"><div class="panel-heading"><i class="icon-calendar"></i> '
            . $this->l('Ventas por día') . '</div>';

        if (!$rows) {
            return $html . '<p class="help-block" style="padding:15px;">' . $this->l('Sin ventas en este período.') . '</p></div>';
        }

        $maxRevenue = max(array_map(function ($row) { return (float) $row['revenue']; }, $rows));
        $maxRevenue = $maxRevenue > 0 ? $maxRevenue : 1;

        $html .= '<table class="table"><thead><tr>'
            . '<th>' . $this->l('Fecha') . '</th>'
            . '<th>' . $this->l('Pedidos') . '</th>'
            . '<th>' . $this->l('Ventas') . '</th>'
            . '<th style="width:40%;"></th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $revenue = (float) $row['revenue'];
            $barWidth = max(2, round(($revenue / $maxRevenue) * 100));
            $html .= '<tr><td>' . Tools::safeOutput($row['d']) . '</td>'
                . '<td>' . (int) $row['orders_count'] . '</td>'
                . '<td>' . $this->formatMoney($revenue) . '</td>'
                . '<td><div style="background:#5cb85c;height:14px;width:' . $barWidth . '%;border-radius:2px;"></div></td>'
                . '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    private function renderTopProducts(array $rows)
    {
        $html = '<div class="panel"><div class="panel-heading"><i class="icon-cubes"></i> '
            . $this->l('Productos más vendidos') . '</div>';

        if (!$rows) {
            return $html . '<p class="help-block" style="padding:15px;">' . $this->l('Sin datos en este período.') . '</p></div>';
        }

        $html .= '<table class="table"><thead><tr>'
            . '<th>' . $this->l('Producto') . '</th>'
            . '<th>' . $this->l('Cantidad') . '</th>'
            . '<th>' . $this->l('Ingresos') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $editUrl = $this->context->link->getAdminLink('AdminProducts') . '&id_product=' . (int) $row['product_id'] . '&updateproduct';
            $html .= '<tr><td><a href="' . Tools::safeOutput($editUrl) . '">' . Tools::safeOutput((string) $row['product_name']) . '</a></td>'
                . '<td>' . (int) $row['qty_sold'] . '</td>'
                . '<td>' . $this->formatMoney((float) $row['revenue']) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    private function renderTopCustomers(array $rows)
    {
        $html = '<div class="panel"><div class="panel-heading"><i class="icon-user"></i> '
            . $this->l('Clientes top') . '</div>';

        if (!$rows) {
            return $html . '<p class="help-block" style="padding:15px;">' . $this->l('Sin datos en este período.') . '</p></div>';
        }

        $html .= '<table class="table"><thead><tr>'
            . '<th>' . $this->l('Cliente') . '</th>'
            . '<th>' . $this->l('Pedidos') . '</th>'
            . '<th>' . $this->l('Gastado') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $editUrl = $this->context->link->getAdminLink('AdminCustomers') . '&id_customer=' . (int) $row['id_customer'] . '&viewcustomer';
            $label = trim($row['firstname'] . ' ' . $row['lastname']) . ' <span class="text-muted">(' . $row['email'] . ')</span>';
            $html .= '<tr><td><a href="' . Tools::safeOutput($editUrl) . '">' . $label . '</a></td>'
                . '<td>' . (int) $row['orders_count'] . '</td>'
                . '<td>' . $this->formatMoney((float) $row['spent']) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    private function renderInactiveCustomers(array $rows)
    {
        $html = '<div class="panel"><div class="panel-heading"><i class="icon-user-times"></i> '
            . $this->l('Clientes sin comprar hace más de 90 días') . '</div>';

        if (!$rows) {
            return $html . '<p class="help-block" style="padding:15px;">' . $this->l('No hay clientes inactivos.') . '</p></div>';
        }

        $html .= '<table class="table"><thead><tr>'
            . '<th>' . $this->l('Cliente') . '</th>'
            . '<th>' . $this->l('Último pedido') . '</th>'
            . '<th>' . $this->l('Días inactivo') . '</th>'
            . '<th>' . $this->l('Pedidos totales') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $editUrl = $this->context->link->getAdminLink('AdminCustomers') . '&id_customer=' . (int) $row['id_customer'] . '&viewcustomer';
            $label = trim($row['firstname'] . ' ' . $row['lastname']) . ' <span class="text-muted">(' . $row['email'] . ')</span>';
            $html .= '<tr><td><a href="' . Tools::safeOutput($editUrl) . '">' . $label . '</a></td>'
                . '<td>' . Tools::safeOutput(substr((string) $row['last_order_at'], 0, 10)) . '</td>'
                . '<td>' . (int) $row['days_inactive'] . '</td>'
                . '<td>' . (int) $row['orders_total'] . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    private function renderLowStock(array $rows)
    {
        $html = '<div class="panel"><div class="panel-heading"><i class="icon-warning-sign"></i> '
            . $this->l('Stock bajo (≤5 unidades)') . '</div>';

        if (!$rows) {
            return $html . '<p class="help-block" style="padding:15px;">' . $this->l('Ningún producto activo con stock bajo.') . '</p></div>';
        }

        $html .= '<table class="table"><thead><tr>'
            . '<th>' . $this->l('Producto') . '</th>'
            . '<th>' . $this->l('Stock') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $editUrl = $this->context->link->getAdminLink('AdminProducts') . '&id_product=' . (int) $row['id_product'] . '&updateproduct';
            $quantity = (int) $row['quantity'];
            $badge = $quantity <= 0 ? 'label-danger' : 'label-warning';
            $html .= '<tr><td><a href="' . Tools::safeOutput($editUrl) . '">' . Tools::safeOutput((string) $row['name']) . '</a></td>'
                . '<td><span class="label ' . $badge . '">' . $quantity . '</span></td>'
                . '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    private function formatMoney($value)
    {
        return Tools::displayPrice((float) $value, (int) $this->context->currency->id);
    }
}
