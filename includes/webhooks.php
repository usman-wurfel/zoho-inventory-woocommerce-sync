<?php
/**
 * Zoho Inventory Webhooks Handler
 * Receives webhooks from Zoho when items, contacts, or invoices are created/updated/deleted
 */

if (!defined('ABSPATH')) exit;

/* ======================================================
   REGISTER WEBHOOK ENDPOINT
====================================================== */
add_action('rest_api_init', 'zoho_register_webhook_endpoints');
function zoho_register_webhook_endpoints() {
    // Webhook endpoint: /wp-json/zoho-inventory/v1/webhook
    register_rest_route('zoho-inventory/v1', '/webhook', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'zoho_handle_webhook',
        'permission_callback' => 'zoho_validate_webhook',
    ));
}

/* ======================================================
   VALIDATE WEBHOOK REQUEST
====================================================== */
function zoho_validate_webhook($request) {
    // Option 1: Secret key validation (if Zoho sends a secret)
    $webhook_secret = get_option('zoho_webhook_secret', '');
    if (!empty($webhook_secret)) {
        $received_secret = $request->get_header('X-Webhook-Secret');
        if ($received_secret !== $webhook_secret) {
            return new WP_Error('invalid_secret', 'Invalid webhook secret', array('status' => 401));
        }
    }
    
    // Option 2: IP whitelist (Zoho API IPs)
    // Option 3: Basic auth or token validation
    // For now, allow if secret is empty (for testing)
    
    return true;
}

/* ======================================================
   HANDLE WEBHOOK REQUEST
====================================================== */
function zoho_handle_webhook($request) {
    $body = $request->get_json_params();
    $headers = $request->get_headers();
    
    // Store last webhook received time (for testing/verification)
    update_option('zoho_last_webhook_received', current_time('mysql'));
    update_option('zoho_last_webhook_data', $body);
    
    // Log webhook received
    zoho_add_detailed_log(
        'webhook',
        'webhook',
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
    
    // Check if webhooks are enabled
    if (get_option('zoho_webhooks_enabled', 'no') !== 'yes') {
        return new WP_REST_Response(array('message' => 'Webhooks disabled'), 200);
    }
    
    // Determine webhook type from payload
    $event_type = isset($body['event_type']) ? $body['event_type'] : '';
    $object_type = isset($body['object_type']) ? $body['object_type'] : ''; // items, contacts, invoices
    $action = isset($body['action']) ? $body['action'] : ''; // create, update, delete
    $object_id = isset($body['object_id']) ? $body['object_id'] : '';
    $object_data = isset($body['data']) ? $body['data'] : array();
    
    // Handle based on object type and action
    switch ($object_type) {
        case 'items':
            zoho_handle_item_webhook($action, $object_id, $object_data);
            break;
            
        case 'contacts':
            zoho_handle_contact_webhook($action, $object_id, $object_data);
            break;
            
        case 'invoices':
            zoho_handle_invoice_webhook($action, $object_id, $object_data);
            break;
            
        default:
            // Unknown object type - log it
            zoho_add_detailed_log(
                'webhook',
                'webhook',
                0,
                'webhook',
                '',
                json_encode(array('unknown_type' => $object_type, 'body' => $body)),
                json_encode(array('status' => 'unknown_type'))
            );
    }
    
    return new WP_REST_Response(array('message' => 'Webhook processed'), 200);
}

/* ======================================================
   HANDLE ITEM WEBHOOKS
====================================================== */
function zoho_handle_item_webhook($action, $item_id, $item_data) {
    // Check if sync from Zoho to WooCommerce is enabled
    if (get_option('zoho_sync_zoho_to_woo_items', 'no') !== 'yes') {
        return;
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
   HANDLE CONTACT WEBHOOKS
====================================================== */
function zoho_handle_contact_webhook($action, $contact_id, $contact_data) {
    // Check if sync from Zoho to WooCommerce is enabled
    if (get_option('zoho_sync_zoho_to_woo_contacts', 'no') !== 'yes') {
        return;
    }
    
    switch ($action) {
        case 'create':
        case 'update':
            // Sync contact to WooCommerce customer
            zoho_sync_zoho_contact_to_woo($contact_id, $contact_data);
            break;
            
        case 'delete':
            // Delete WooCommerce customer (or keep - your choice)
            zoho_delete_woo_customer_from_zoho_contact($contact_id);
            break;
    }
}

/* ======================================================
   HANDLE INVOICE WEBHOOKS
====================================================== */
function zoho_handle_invoice_webhook($action, $invoice_id, $invoice_data) {
    // Check if sync from Zoho to WooCommerce is enabled
    if (get_option('zoho_sync_zoho_to_woo_invoices', 'no') !== 'yes') {
        return;
    }
    
    switch ($action) {
        case 'create':
        case 'update':
            // Sync invoice to WooCommerce order
            zoho_sync_zoho_invoice_to_woo($invoice_id, $invoice_data);
            break;
            
        case 'delete':
            // Delete WooCommerce order
            zoho_delete_woo_order_from_zoho_invoice($invoice_id);
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
    
    // Map Zoho fields to WooCommerce fields
    $zoho_name_field = get_option('zoho_zoho_name_field', 'name');
    $zoho_price_field = get_option('zoho_zoho_price_field', 'rate');
    $zoho_stock_field = get_option('zoho_zoho_stock_field', 'stock_on_hand');
    $zoho_description_field = get_option('zoho_zoho_description_field', 'description');
    
    if (isset($zoho_item_data[$zoho_name_field])) {
        $product->set_name($zoho_item_data[$zoho_name_field]);
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
    
    $product_id = $product->save();
    
    if ($product_id) {
        zoho_add_detailed_log(
            'item',
            'product',
            $product_id,
            'item',
            $zoho_item_data['item_id'],
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
    if (get_option('zoho_auto_delete_woo_products', 'no') !== 'yes') {
        return; // Don't delete if not enabled
    }
    
    global $wpdb;
    $product_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zoho_item_id' AND meta_value = %s LIMIT 1",
        $zoho_item_id
    ));
    
    if ($product_id) {
        wp_delete_post($product_id, true);
        
        zoho_add_detailed_log(
            'item',
            'product',
            $product_id,
            'item',
            $zoho_item_id,
            json_encode(array('action' => 'webhook_delete')),
            json_encode(array('status' => 'deleted', 'product_id' => $product_id))
        );
    }
}

/**
 * Sync Zoho contact to WooCommerce customer
 */
function zoho_sync_zoho_contact_to_woo($zoho_contact_id, $zoho_contact_data = null) {
    // Fetch contact from Zoho if data not provided
    if (empty($zoho_contact_data)) {
        $response = zoho_api_get('contacts/' . $zoho_contact_id);
        if (is_wp_error($response) || !isset($response['contact'])) {
            return false;
        }
        $zoho_contact_data = $response['contact'];
    }
    
    // Find existing user by Zoho contact ID
    global $wpdb;
    $user_id = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_zoho_contact_id' AND meta_value = %s LIMIT 1",
        $zoho_contact_id
    ));
    
    // Also search by email
    if (empty($user_id) && !empty($zoho_contact_data['email'])) {
        $user = get_user_by('email', $zoho_contact_data['email']);
        if ($user) {
            $user_id = $user->ID;
        }
    }
    
    // Create or update customer
    if ($user_id) {
        zoho_update_woo_customer_from_zoho_contact($user_id, $zoho_contact_data);
    } else {
        zoho_create_woo_customer_from_zoho_contact($zoho_contact_data);
    }
}

/**
 * Create WooCommerce customer from Zoho contact
 */
function zoho_create_woo_customer_from_zoho_contact($zoho_contact_data) {
    $email = isset($zoho_contact_data['email']) ? $zoho_contact_data['email'] : '';
    if (empty($email)) {
        return false;
    }
    
    // Generate username from email
    $username = sanitize_user($email);
    if (username_exists($username)) {
        $username = $username . '_' . time();
    }
    
    // Create user
    $user_id = wp_create_user($username, wp_generate_password(), $email);
    
    if (is_wp_error($user_id)) {
        return false;
    }
    
    // Set customer role
    $user = new WP_User($user_id);
    $user->set_role('customer');
    
    // Update user data
    if (isset($zoho_contact_data['first_name'])) {
        update_user_meta($user_id, 'first_name', $zoho_contact_data['first_name']);
    }
    if (isset($zoho_contact_data['last_name'])) {
        update_user_meta($user_id, 'last_name', $zoho_contact_data['last_name']);
    }
    if (isset($zoho_contact_data['phone'])) {
        update_user_meta($user_id, 'billing_phone', $zoho_contact_data['phone']);
    }
    if (isset($zoho_contact_data['billing_address'])) {
        $billing = $zoho_contact_data['billing_address'];
        if (isset($billing['address'])) {
            update_user_meta($user_id, 'billing_address_1', $billing['address']);
        }
        if (isset($billing['city'])) {
            update_user_meta($user_id, 'billing_city', $billing['city']);
        }
        if (isset($billing['state'])) {
            update_user_meta($user_id, 'billing_state', $billing['state']);
        }
        if (isset($billing['zip'])) {
            update_user_meta($user_id, 'billing_postcode', $billing['zip']);
        }
        if (isset($billing['country'])) {
            update_user_meta($user_id, 'billing_country', $billing['country']);
        }
    }
    
    // Save Zoho contact ID
    update_user_meta($user_id, '_zoho_contact_id', $zoho_contact_data['contact_id']);
    $org_id = get_option('zoho_organization_id', '');
    if (!empty($org_id)) {
        update_user_meta($user_id, '_zoho_org_id', $org_id);
    }
    
    zoho_add_detailed_log(
        'contact',
        'customer',
        $user_id,
        'contact',
        $zoho_contact_data['contact_id'],
        json_encode(array('action' => 'webhook_create', 'zoho_data' => $zoho_contact_data)),
        json_encode(array('status' => 'created', 'user_id' => $user_id))
    );
    
    return $user_id;
}

/**
 * Update WooCommerce customer from Zoho contact
 */
function zoho_update_woo_customer_from_zoho_contact($user_id, $zoho_contact_data) {
    // Update user data
    if (isset($zoho_contact_data['first_name'])) {
        update_user_meta($user_id, 'first_name', $zoho_contact_data['first_name']);
    }
    if (isset($zoho_contact_data['last_name'])) {
        update_user_meta($user_id, 'last_name', $zoho_contact_data['last_name']);
    }
    if (isset($zoho_contact_data['email'])) {
        wp_update_user(array('ID' => $user_id, 'user_email' => $zoho_contact_data['email']));
    }
    if (isset($zoho_contact_data['phone'])) {
        update_user_meta($user_id, 'billing_phone', $zoho_contact_data['phone']);
    }
    if (isset($zoho_contact_data['billing_address'])) {
        $billing = $zoho_contact_data['billing_address'];
        if (isset($billing['address'])) {
            update_user_meta($user_id, 'billing_address_1', $billing['address']);
        }
        if (isset($billing['city'])) {
            update_user_meta($user_id, 'billing_city', $billing['city']);
        }
        if (isset($billing['state'])) {
            update_user_meta($user_id, 'billing_state', $billing['state']);
        }
        if (isset($billing['zip'])) {
            update_user_meta($user_id, 'billing_postcode', $billing['zip']);
        }
        if (isset($billing['country'])) {
            update_user_meta($user_id, 'billing_country', $billing['country']);
        }
    }
    
    zoho_add_detailed_log(
        'contact',
        'customer',
        $user_id,
        'contact',
        $zoho_contact_data['contact_id'],
        json_encode(array('action' => 'webhook_update', 'zoho_data' => $zoho_contact_data)),
        json_encode(array('status' => 'updated', 'user_id' => $user_id))
    );
    
    return $user_id;
}

/**
 * Delete WooCommerce customer when Zoho contact is deleted
 */
function zoho_delete_woo_customer_from_zoho_contact($zoho_contact_id) {
    if (get_option('zoho_auto_delete_woo_customers', 'no') !== 'yes') {
        return;
    }
    
    global $wpdb;
    $user_id = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_zoho_contact_id' AND meta_value = %s LIMIT 1",
        $zoho_contact_id
    ));
    
    if ($user_id) {
        wp_delete_user($user_id);
        
        zoho_add_detailed_log(
            'contact',
            'customer',
            $user_id,
            'contact',
            $zoho_contact_id,
            json_encode(array('action' => 'webhook_delete')),
            json_encode(array('status' => 'deleted', 'user_id' => $user_id))
        );
    }
}

/**
 * Sync Zoho invoice to WooCommerce order
 */
function zoho_sync_zoho_invoice_to_woo($zoho_invoice_id, $zoho_invoice_data = null) {
    // Fetch invoice from Zoho if data not provided
    if (empty($zoho_invoice_data)) {
        $response = zoho_api_get('invoices/' . $zoho_invoice_id);
        if (is_wp_error($response) || !isset($response['invoice'])) {
            return false;
        }
        $zoho_invoice_data = $response['invoice'];
    }
    
    // Find existing order by Zoho invoice ID
    global $wpdb;
    $order_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zoho_invoice_id' AND meta_value = %s LIMIT 1",
        $zoho_invoice_id
    ));
    
    if ($order_id) {
        zoho_update_woo_order_from_zoho_invoice($order_id, $zoho_invoice_data);
    } else {
        zoho_create_woo_order_from_zoho_invoice($zoho_invoice_data);
    }
}

/**
 * Create WooCommerce order from Zoho invoice
 */
function zoho_create_woo_order_from_zoho_invoice($zoho_invoice_data) {
    // This is complex - invoice to order conversion
    // For now, just log it - can be implemented based on requirements
    zoho_add_detailed_log(
        'invoice',
        'order',
        0,
        'invoice',
        $zoho_invoice_data['invoice_id'],
        json_encode(array('action' => 'webhook_create', 'zoho_data' => $zoho_invoice_data)),
        json_encode(array('status' => 'not_implemented', 'message' => 'Invoice to order creation not yet implemented'))
    );
}

/**
 * Update WooCommerce order from Zoho invoice
 */
function zoho_update_woo_order_from_zoho_invoice($order_id, $zoho_invoice_data) {
    $order = wc_get_order($order_id);
    if (!$order) {
        return false;
    }
    
    // Update order status based on invoice status
    if (isset($zoho_invoice_data['status'])) {
        $zoho_status = strtolower($zoho_invoice_data['status']);
        $woo_status = 'pending';
        
        // Map Zoho status to WooCommerce status
        if ($zoho_status === 'paid' || $zoho_status === 'sent') {
            $woo_status = 'processing';
        } elseif ($zoho_status === 'void') {
            $woo_status = 'cancelled';
        }
        
        $order->update_status($woo_status);
    }
    
    zoho_add_detailed_log(
        'invoice',
        'order',
        $order_id,
        'invoice',
        $zoho_invoice_data['invoice_id'],
        json_encode(array('action' => 'webhook_update', 'zoho_data' => $zoho_invoice_data)),
        json_encode(array('status' => 'updated', 'order_id' => $order_id))
    );
    
    return $order_id;
}

/**
 * Delete WooCommerce order when Zoho invoice is deleted
 */
function zoho_delete_woo_order_from_zoho_invoice($zoho_invoice_id) {
    if (get_option('zoho_auto_delete_woo_orders', 'no') !== 'yes') {
        return;
    }
    
    global $wpdb;
    $order_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zoho_invoice_id' AND meta_value = %s LIMIT 1",
        $zoho_invoice_id
    ));
    
    if ($order_id) {
        wp_delete_post($order_id, true);
        
        zoho_add_detailed_log(
            'invoice',
            'order',
            $order_id,
            'invoice',
            $zoho_invoice_id,
            json_encode(array('action' => 'webhook_delete')),
            json_encode(array('status' => 'deleted', 'order_id' => $order_id))
        );
    }
}

