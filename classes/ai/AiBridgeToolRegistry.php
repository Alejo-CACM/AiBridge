<?php

/**
 * Tool surface exposed to the in-Back-Office chat AI. Every write tool goes
 * through the exact same Preview -> AiBridgeApprovalRequest::createPendingX
 * classes the HTTP API uses (see AiBridgeToolExecutor), so a chat-originated
 * change is a pending approval like any other and still needs a second,
 * different employee to approve it in AI Bridge -> Approvals.
 *
 * Payload fields for the *_create/*_update tools intentionally use a loose
 * JSON schema (object, no fixed properties) because the exact field names
 * are already fully documented in AGENTS.md, which is injected wholesale
 * into the system prompt (see AiBridgeChatOrchestrator) — duplicating that
 * schema here would just be a second copy to keep in sync.
 */
class AiBridgeToolRegistry
{
    public static function all()
    {
        $payloadSchema = array(
            'type' => 'object',
            'description' => 'Fields exactly as documented in the operations guide for this action.',
        );

        return array(
            array(
                'name' => 'product_search',
                'description' => 'Search existing products by name, exact reference, or category, to check for duplicates before creating or to find the id to update.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'query' => array('type' => 'string', 'description' => 'Free-text name search.'),
                        'reference' => array('type' => 'string', 'description' => 'Exact product reference (SKU) lookup.'),
                        'category_id' => array('type' => 'integer'),
                        'limit' => array('type' => 'integer', 'description' => 'Max results, default 20, max 100.'),
                    ),
                ),
            ),
            array(
                'name' => 'category_list',
                'description' => 'List categories, optionally filtered by parent id.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'parent_id' => array('type' => 'integer'),
                    ),
                ),
            ),
            array(
                'name' => 'brands_list',
                'description' => 'List existing manufacturers/brands.',
                'parameters' => array('type' => 'object', 'properties' => new stdClass()),
            ),
            array(
                'name' => 'product_create',
                'description' => 'Propose creating a new product. Creates a pending approval request — does not go live until a different admin approves it.',
                'parameters' => $payloadSchema,
            ),
            array(
                'name' => 'product_update',
                'description' => 'Propose changes to an existing product (price, name, SEO fields, stock, active, discount, etc). Creates a pending approval request.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id' => array('type' => 'integer', 'description' => 'Product id to update.'),
                        'payload' => $payloadSchema,
                    ),
                    'required' => array('id', 'payload'),
                ),
            ),
            array(
                'name' => 'category_create',
                'description' => 'Propose creating a new category. Creates a pending approval request.',
                'parameters' => $payloadSchema,
            ),
            array(
                'name' => 'category_update',
                'description' => 'Propose changes to an existing category. Creates a pending approval request.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id' => array('type' => 'integer', 'description' => 'Category id to update.'),
                        'payload' => $payloadSchema,
                    ),
                    'required' => array('id', 'payload'),
                ),
            ),
            array(
                'name' => 'manufacturer_create',
                'description' => 'Propose creating a new manufacturer/brand. Creates a pending approval request.',
                'parameters' => $payloadSchema,
            ),
        );
    }
}
