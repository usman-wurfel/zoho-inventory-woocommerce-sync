<?php
/**
 * Zoho Inventory Webhooks Handler - ITEMS ONLY
 * Receives webhooks from Zoho when items are created/updated/deleted
 */

if (!defined('ABSPATH')) exit;

/* ======================================================
   REGISTER WEBHOOK ENDPOINTS - ITEMS
====================================================== */
add_action('rest_api_init', 'zoho_register_item_webhook_endpoints');
function zoho_register_item_webhook_endpoints() {
    // Webhook endpoint for create/update: /wp-json/zoho-inventory/v1/webhook-items
    register_rest_route('zoho-inventory/v1', '/webhook-items', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'zoho_handle_item_webhook_request',
        'permission_callback' => 'zoho_validate_item_webhook',
    ));
    
    // Separate webhook endpoint for delete: /wp-json/zoho-inventory/v1/webhook-items-delete
    register_rest_route('zoho-inventory/v1', '/webhook-items-delete', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'zoho_handle_item_delete_webhook',
        'permission_callback' => 'zoho_validate_item_webhook',
    ));
}

/* ======================================================
   VALIDATE ITEM WEBHOOK REQUEST
====================================================== */
function zoho_validate_item_webhook($request) {
    // Get received secret from header
    $received_secret = $request->get_header('X-Webhook-Secret');
    
    // Validate against items secret
    if (!empty($received_secret)) {
        $expected_secret = get_option('zoho_webhook_secret_items', '');
        if (!empty($expected_secret) && $received_secret !== $expected_secret) {
            return new WP_Error('invalid_secret', 'Invalid webhook secret', array('status' => 401));
        }
    }
    
    // If no secret is required or validation passed, allow the request
    return true;
}

/* ======================================================
   HANDLE ITEM WEBHOOK REQUEST (Create/Update)
====================================================== */
function zoho_handle_item_webhook_request($request) {
    $body = $request->get_json_params();
    $headers = $request->get_headers();
    
    // Store last webhook received time (for testing/verification)
    update_option('zoho_last_webhook_items', current_time('mysql'));
    update_option('zoho_last_webhook_items_data', $body);
    
    // Log webhook received
    zoho_add_detailed_log(
        'webhook',
        'webhook_items',
        0,
        'webhook',
        '',
        json_encode(array(
            'received_data' => $body,
            'headers' => $headers,
            'timestamp' => current_time('mysql')
        )),
        json_encode(array('status' => 'processing'))
    );
    
    // Check if sync is enabled
    if (get_option('zoho_sync_zoho_to_woo_items', 'no') !== 'yes') {
        return new WP_REST_Response(array('message' => 'Item sync disabled'), 200);
    }
    
    // Parse item webhook - Zoho sends item object
    $action = 'update';
    $object_id = '';
    $object_data = array();
    
    if (isset($body['item'])) {
        $object_data = $body['item'];
        $object_id = isset($object_data['item_id']) ? $object_data['item_id'] : '';
        if (isset($body['event_type'])) {
            $event_type = strtolower($body['event_type']);
            if (strpos($event_type, 'created') !== false || strpos($event_type, 'create') !== false) {
                $action = 'create';
            } else {
                $action = 'update';
            }
        }
    } elseif (isset($body['item_id'])) {
        $object_id = $body['item_id'];
        $object_data = isset($body['data']) ? $body['data'] : $body;
    }
    
    if (!empty($object_id) && ($action === 'create' || $action === 'update')) {
        zoho_handle_item_webhook($action, $object_id, $object_data);
    }
    
    return new WP_REST_Response(array('message' => 'Item webhook processed', 'action' => $action, 'item_id' => $object_id), 200);
}

/* ======================================================
   HANDLE ITEM DELETE WEBHOOK
====================================================== */
function zoho_handle_item_delete_webhook($request) {
    $body = $request->get_json_params();
    $headers = $request->get_headers();
    
    update_option('zoho_last_webhook_items', current_time('mysql'));
    update_option('zoho_last_webhook_items_data', $body);
    
    zoho_add_detailed_log(
        'webhook',
        'webhook_items_delete',
        0,
        'webhook',
        '',
        json_encode(array(
            'received_data' => $body,
            'headers' => $headers,
            'timestamp' => current_time('mysql')
        )),
        json_encode(array('status' => 'processing_delete'))
    );
    
    if (get_option('zoho_sync_zoho_to_woo_items', 'no') !== 'yes') {
        return new WP_REST_Response(array('message' => 'Item sync disabled'), 200);
    }
    
    $object_id = '';
    if (isset($body['item'])) {
        $object_id = isset($body['item']['item_id']) ? $body['item']['item_id'] : '';
    } elseif (isset($body['item_id'])) {
        $object_id = $body['item_id'];
    }
    
    if (!empty($object_id)) {
        zoho_delete_woo_product_from_zoho_item($object_id);
    }
    
    return new WP_REST_Response(array('message' => 'Item delete webhook processed', 'item_id' => $object_id), 200);
}

/* ======================================================
   HANDLE ITEM WEBHOOKS
====================================================== */
function zoho_handle_item_webhook($action, $item_id, $item_data) {
    // Check if sync from Zoho to WooCommerce is enabled
    if (get_option('zoho_sync_zoho_to_woo_items', 'no') !== 'yes') {
        return;
    }
    
    // Normalize action - handle variations
    $action = strtolower($action);
    if (empty($action) || in_array($action, array('update', 'updated', 'modified', 'edit'))) {
        $action = 'update';
    } elseif (in_array($action, array('create', 'created', 'new'))) {
        $action = 'create';
    } elseif (in_array($action, array('delete', 'deleted', 'remove', 'removed'))) {
        $action = 'delete';
    }
    
    switch ($action) {
        case 'create':
        case 'update':
            // Sync item to WooCommerce product
            zoho_sync_zoho_item_to_woo($item_id, $item_data);
            break;
            
        case 'delete':
            // Delete or deactivate WooCommerce product
            zoho_delete_woo_product_from_zoho_item($item_id);
            break;
    }
}


/* ======================================================
   SYNC FUNCTIONS - ZOHO TO WOOCOMMERCE
====================================================== */

/**
 * Sync Zoho item to WooCommerce product
 */
function zoho_sync_zoho_item_to_woo($zoho_item_id, $zoho_item_data = null) {
    // Fetch item from Zoho if data not provided
    if (empty($zoho_item_data)) {
        $response = zoho_api_get('items/' . $zoho_item_id);
        if (is_wp_error($response) || !isset($response['item'])) {
            return false;
        }
        $zoho_item_data = $response['item'];
    }
    
    // Find existing product by Zoho item ID
    global $wpdb;
    $product_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zoho_item_id' AND meta_value = %s LIMIT 1",
        $zoho_item_id
    ));
    
    // Get field mappings
    $zoho_sku_field = get_option('zoho_zoho_sku_field', 'sku');
    $sku = isset($zoho_item_data[$zoho_sku_field]) ? $zoho_item_data[$zoho_sku_field] : '';
    
    // Also search by SKU if product not found by Zoho ID
    if (empty($product_id) && !empty($sku)) {
        $product_id = wc_get_product_id_by_sku($sku);
    }
    
    // Create or update product
    if ($product_id) {
        // Update existing product
        $product = wc_get_product($product_id);
        zoho_update_woo_product_from_zoho_item($product, $zoho_item_data);
    } else {
        // Create new product
        zoho_create_woo_product_from_zoho_item($zoho_item_data);
    }
}

/**
 * Create WooCommerce product from Zoho item
 */
function zoho_create_woo_product_from_zoho_item($zoho_item_data) {
    $product = new WC_Product_Simple();
    
    // Map Zoho fields to WooCommerce fields using field mapping settings
    $zoho_name_field = get_option('zoho_zoho_name_field', 'name');
    $zoho_sku_field = get_option('zoho_zoho_sku_field', 'sku');
    $zoho_price_field = get_option('zoho_zoho_price_field', 'rate');
    $zoho_stock_field = get_option('zoho_zoho_stock_field', 'stock_on_hand');
    $zoho_description_field = get_option('zoho_zoho_description_field', 'description');
    
    if (isset($zoho_item_data[$zoho_name_field])) {
        $product->set_name($zoho_item_data[$zoho_name_field]);
    }
    
    if (isset($zoho_item_data[$zoho_sku_field])) {
        $product->set_sku($zoho_item_data[$zoho_sku_field]);
    }
    
    if (isset($zoho_item_data[$zoho_price_field])) {
        $product->set_regular_price($zoho_item_data[$zoho_price_field]);
    }
    
    if (isset($zoho_item_data[$zoho_stock_field])) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity(intval($zoho_item_data[$zoho_stock_field]));
    }
    
    if (isset($zoho_item_data[$zoho_description_field])) {
        $product->set_description($zoho_item_data[$zoho_description_field]);
    }
    
    $product->set_status('publish');
    $product_id = $product->save();
    
    // Save Zoho item ID
    if ($product_id) {
        update_post_meta($product_id, '_zoho_item_id', $zoho_item_data['item_id']);
        $org_id = get_option('zoho_organization_id', '');
        if (!empty($org_id)) {
            update_post_meta($product_id, '_zoho_org_id', $org_id);
        }
        
        // Set flag to prevent circular sync - skip WooCommerce → Zoho sync for next 10 seconds
        set_transient('zoho_webhook_updating_product_' . $product_id, true, 10);
        
        zoho_add_detailed_log(
            'item',
            'product',
            $product_id,
            'item',
            $zoho_item_data['item_id'],
            json_encode(array('action' => 'webhook_create', 'zoho_data' => $zoho_item_data)),
            json_encode(array('status' => 'created', 'product_id' => $product_id))
        );
    }
    
    return $product_id;
}

/**
 * Update WooCommerce product from Zoho item
 */
function zoho_update_woo_product_from_zoho_item($product, $zoho_item_data) {
    if (!$product) {
        return false;
    }
    
    $product_id = $product->get_id();
    // Set flag to prevent circular sync - skip WooCommerce → Zoho sync for next 10 seconds
    set_transient('zoho_webhook_updating_product_' . $product_id, true, 10);
    
    // Map Zoho fields to WooCommerce fields
    $zoho_name_field = get_option('zoho_zoho_name_field', 'name');
    $zoho_price_field = get_option('zoho_zoho_price_field', 'rate');
    $zoho_stock_field = get_option('zoho_zoho_stock_field', 'stock_on_hand');
    $zoho_description_field = get_option('zoho_zoho_description_field', 'description');
    
    // Update name (check both 'name' and 'rate' field - Zoho might use different field names)
    if (isset($zoho_item_data[$zoho_name_field])) {
        $product->set_name($zoho_item_data[$zoho_name_field]);
    } elseif (isset($zoho_item_data['name'])) {
        // Fallback to 'name' if field mapping doesn't match
        $product->set_name($zoho_item_data['name']);
    }
    
    // Update price
    if (isset($zoho_item_data[$zoho_price_field])) {
        $product->set_regular_price($zoho_item_data[$zoho_price_field]);
    } elseif (isset($zoho_item_data['rate'])) {
        $product->set_regular_price($zoho_item_data['rate']);
    } elseif (isset($zoho_item_data['sales_rate'])) {
        $product->set_regular_price($zoho_item_data['sales_rate']);
    }
    
    // Update stock
    if (isset($zoho_item_data[$zoho_stock_field])) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity(intval($zoho_item_data[$zoho_stock_field]));
    } elseif (isset($zoho_item_data['stock_on_hand'])) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity(intval($zoho_item_data['stock_on_hand']));
    } elseif (isset($zoho_item_data['available_stock'])) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity(intval($zoho_item_data['available_stock']));
    }
    
    // Update description
    if (isset($zoho_item_data[$zoho_description_field])) {
        $product->set_description($zoho_item_data[$zoho_description_field]);
    } elseif (isset($zoho_item_data['description'])) {
        $product->set_description($zoho_item_data['description']);
    }
    
    $product_id = $product->save();
    
    if ($product_id) {
        zoho_add_detailed_log(
            'item',
            'product',
            $product_id,
            'item',
            isset($zoho_item_data['item_id']) ? $zoho_item_data['item_id'] : '',
            json_encode(array('action' => 'webhook_update', 'zoho_data' => $zoho_item_data)),
            json_encode(array('status' => 'updated', 'product_id' => $product_id))
        );
    }
    
    return $product_id;
}

/**
 * Delete or deactivate WooCommerce product when Zoho item is deleted
 */
function zoho_delete_woo_product_from_zoho_item($zoho_item_id) {
    global $wpdb;
    $product_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zoho_item_id' AND meta_value = %s LIMIT 1",
        $zoho_item_id
    ));
    
    if (!$product_id) {
        zoho_add_detailed_log(
            'item',
            'product',
            0,
            'item',
            $zoho_item_id,
            json_encode(array('action' => 'webhook_delete')),
            json_encode(array('status' => 'not_found', 'message' => 'Product not found in WooCommerce'))
        );
        return; // Product not found
    }
    
    // Move product to trash
    $product = wc_get_product($product_id);
    if ($product) {
        $product->delete(false); // false = move to trash, true = permanently delete
        
        zoho_add_detailed_log(
            'item',
            'product',
            $product_id,
            'item',
            $zoho_item_id,
            json_encode(array('action' => 'webhook_delete')),
            json_encode(array('status' => 'trashed', 'product_id' => $product_id))
        );
    }
}

