<?php

function upgrade_module_1_14_0($module)
{
    // No schema changes: discounts reuse PrestaShop's core specific_price
    // table. AiBridgeDiscountHandler manages a single unscoped row per
    // product (id_shop=0, id_currency=0, id_country=0, id_group=0,
    // id_customer=0, id_cart=0, id_specific_price_rule=0).
    return true;
}
