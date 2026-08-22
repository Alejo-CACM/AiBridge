<?php

function upgrade_module_1_13_0($module)
{
    // No schema changes: product.create, category create/update, manufacturer
    // create, tags, attributes listing, and multi-combination create all
    // reuse existing core PrestaShop tables/columns and the
    // product_id/created_product_id columns already added in 1.12.0.
    return true;
}
