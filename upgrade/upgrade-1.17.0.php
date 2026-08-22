<?php

function upgrade_module_1_17_0($module)
{
    // No schema changes: orders/customers are read-only over PrestaShop's
    // own tables, and address create/update reuse the existing
    // aibridge_approval_request columns (product_id/created_product_id),
    // same tech-debt pattern already used for categories/manufacturers.
    return true;
}
