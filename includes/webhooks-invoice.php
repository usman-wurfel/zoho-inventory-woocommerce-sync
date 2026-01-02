<?php
/**
 * Zoho Inventory Webhooks Handler - INVOICES ONLY
 * Receives webhooks from Zoho when invoices are created/updated/deleted
 */

if (!defined('ABSPATH')) exit;

/* ======================================================
   REGISTER WEBHOOK ENDPOINTS - INVOICES
====================================================== */
add_action('rest_api_init', 'zoho_register_invoice_webhook_endpoints');
function zoho_register_invoice_webhook_endpoints() {
    // Webhook endpoint for create/update: /wp-json/zoho-inventory/v1/webhook-invoices
    register_rest_route('zoho-inventory/v1', '/webhook-invoices', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'zoho_handle_invoice_webhook_request',
        'permission_callback' => 'zoho_validate_invoice_webhook',
    ));
    
    // Separate webhook endpoint for delete: /wp-json/zoho-inventory/v1/webhook-invoices-delete
    register_rest_route('zoho-inventory/v1', '/webhook-invoices-delete', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'zoho_handle_invoice_delete_webhook',
        'permission_callback' => 'zoho_validate_invoice_webhook',
    ));
}

/* ======================================================
   VALIDATE INVOICE WEBHOOK REQUEST
====================================================== */
function zoho_validate_invoice_webhook($request) {
    $received_secret = $request->get_header('X-Webhook-Secret');
    
    if (!empty($received_secret)) {
        $expected_secret = get_option('zoho_webhook_secret_invoices', '');
        if (!empty($expected_secret) && $received_secret !== $expected_secret) {
            return new WP_Error('invalid_secret', 'Invalid webhook secret', array('status' => 401));
        }
    }
    
    return true;
}

/* ======================================================
   HANDLE INVOICE WEBHOOK REQUEST (Create/Update)
====================================================== */
function zoho_handle_invoice_webhook_request($request) {
    $body = $request->get_json_params();
    $headers = $request->get_headers();
    
    update_option('zoho_last_webhook_invoices', current_time('mysql'));
    update_option('zoho_last_webhook_invoices_data', $body);
    
    zoho_add_detailed_log(
        'webhook',
        'webhook_invoices',
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
    
    if (get_option('zoho_sync_zoho_to_woo_invoices', 'no') !== 'yes') {
        return new WP_REST_Response(array('message' => 'Invoice sync disabled'), 200);
    }
    
    $action = 'update';
    $object_id = '';
    $object_data = array();
    
    if (isset($body['invoice'])) {
        $object_data = $body['invoice'];
        $object_id = isset($object_data['invoice_id']) ? $object_data['invoice_id'] : '';
        if (isset($body['event_type'])) {
            $event_type = strtolower($body['event_type']);
            if (strpos($event_type, 'created') !== false || strpos($event_type, 'create') !== false) {
                $action = 'create';
            } else {
                $action = 'update';
            }
        }
    } elseif (isset($body['invoice_id'])) {
        $object_id = $body['invoice_id'];
        $object_data = isset($body['data']) ? $body['data'] : $body;
    }
    
    if (!empty($object_id) && ($action === 'create' || $action === 'update')) {
        zoho_handle_invoice_webhook($action, $object_id, $object_data);
    }
    
    return new WP_REST_Response(array('message' => 'Invoice webhook processed', 'action' => $action, 'invoice_id' => $object_id), 200);
}

/* ======================================================
   HANDLE INVOICE DELETE WEBHOOK
====================================================== */
function zoho_handle_invoice_delete_webhook($request) {
    $body = $request->get_json_params();
    $headers = $request->get_headers();
    
    update_option('zoho_last_webhook_invoices', current_time('mysql'));
    update_option('zoho_last_webhook_invoices_data', $body);
    
    zoho_add_detailed_log(
        'webhook',
        'webhook_invoices_delete',
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
    
    if (get_option('zoho_sync_zoho_to_woo_invoices', 'no') !== 'yes') {
        return new WP_REST_Response(array('message' => 'Invoice sync disabled'), 200);
    }
    
    $object_id = '';
    if (isset($body['invoice'])) {
        $object_id = isset($body['invoice']['invoice_id']) ? $body['invoice']['invoice_id'] : '';
    } elseif (isset($body['invoice_id'])) {
        $object_id = $body['invoice_id'];
    }
    
    if (!empty($object_id)) {
        // Force delete when called from webhook (ignore auto_delete setting)
        zoho_delete_woo_order_from_zoho_invoice($object_id, true);
    } else {
        zoho_add_detailed_log(
            'webhook',
            'webhook_invoices_delete',
            0,
            'webhook',
            '',
            json_encode(array('body' => $body)),
            json_encode(array('status' => 'error', 'message' => 'Invoice ID not found in webhook payload'))
        );
    }
    
    return new WP_REST_Response(array('message' => 'Invoice delete webhook processed', 'invoice_id' => $object_id), 200);
}

/* ======================================================
   HANDLE INVOICE WEBHOOKS
====================================================== */
function zoho_handle_invoice_webhook($action, $invoice_id, $invoice_data) {
    if (get_option('zoho_sync_zoho_to_woo_invoices', 'no') !== 'yes') {
        return;
    }
    
    switch ($action) {
        case 'create':
        case 'update':
            zoho_sync_zoho_invoice_to_woo($invoice_id, $invoice_data);
            break;
        case 'delete':
            // Force delete when called from webhook
            zoho_delete_woo_order_from_zoho_invoice($invoice_id, true);
            break;
    }
}

/* ======================================================
   SYNC FUNCTIONS - ZOHO TO WOOCOMMERCE
====================================================== */

/**
 * Sync Zoho invoice to WooCommerce order
 */
function zoho_sync_zoho_invoice_to_woo($zoho_invoice_id, $zoho_invoice_data = null) {
    if (empty($zoho_invoice_data)) {
        $response = zoho_api_get('invoices/' . $zoho_invoice_id);
        if (is_wp_error($response) || !isset($response['invoice'])) {
            return false;
        }
        $zoho_invoice_data = $response['invoice'];
    }
    
    // Use helper function for HPOS-compatible lookup
    if (function_exists('zoho_get_order_by_zoho_invoice')) {
        $order_id = zoho_get_order_by_zoho_invoice($zoho_invoice_id);
    } else {
        // Fallback if function doesn't exist
        global $wpdb;
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zoho_invoice_id' AND meta_value = %s LIMIT 1",
            $zoho_invoice_id
        ));
    }
    
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
    $zoho_invoice_id = $zoho_invoice_data['invoice_id'] ?? '';
    if (empty($zoho_invoice_id)) {
        return false;
    }
    
    // Check if order already exists (use helper function for HPOS compatibility)
    $existing_order_id = 0;
    if (function_exists('zoho_get_order_by_zoho_invoice')) {
        $existing_order_id = zoho_get_order_by_zoho_invoice($zoho_invoice_id);
    } else {
        global $wpdb;
        $existing_order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zoho_invoice_id' AND meta_value = %s LIMIT 1",
            $zoho_invoice_id
        ));
    }
    
    if ($existing_order_id) {
        // Order already exists, update it instead
        return zoho_update_woo_order_from_zoho_invoice($existing_order_id, $zoho_invoice_data);
    }
    
    // Create new order
    $order = wc_create_order();
    if (!$order) {
        return false;
    }
    
    // Set flag to prevent circular sync
    set_transient('zoho_webhook_updating_order_' . $order->get_id(), true, 10);
    
    // Set customer if exists
    $customer_id = $zoho_invoice_data['customer_id'] ?? '';
    $wc_customer_id = 0;
    
    if ($customer_id) {
        // Try to find WooCommerce customer by Zoho contact ID (use helper function if available)
        if (function_exists('zoho_get_wc_customer_by_zoho_contact')) {
            $wc_customer_id = zoho_get_wc_customer_by_zoho_contact($customer_id);
        } else {
            global $wpdb;
            $wc_customer_id = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_zoho_contact_id' AND meta_value = %s LIMIT 1",
                $customer_id
            ));
        }
        
        if ($wc_customer_id) {
            $order->set_customer_id($wc_customer_id);
            $customer = new WC_Customer($wc_customer_id);
            
            // Set billing from customer
            $order->set_billing_first_name($customer->get_billing_first_name());
            $order->set_billing_last_name($customer->get_billing_last_name());
            $order->set_billing_company($customer->get_billing_company());
            $order->set_billing_address_1($customer->get_billing_address_1());
            $order->set_billing_address_2($customer->get_billing_address_2());
            $order->set_billing_city($customer->get_billing_city());
            $order->set_billing_state($customer->get_billing_state());
            $order->set_billing_postcode($customer->get_billing_postcode());
            $order->set_billing_country($customer->get_billing_country());
            $order->set_billing_email($customer->get_billing_email());
            $order->set_billing_phone($customer->get_billing_phone());
            
            // Set shipping from customer
            $order->set_shipping_first_name($customer->get_shipping_first_name());
            $order->set_shipping_last_name($customer->get_shipping_last_name());
            $order->set_shipping_company($customer->get_shipping_company());
            $order->set_shipping_address_1($customer->get_shipping_address_1());
            $order->set_shipping_address_2($customer->get_shipping_address_2());
            $order->set_shipping_city($customer->get_shipping_city());
            $order->set_shipping_state($customer->get_shipping_state());
            $order->set_shipping_postcode($customer->get_shipping_postcode());
            $order->set_shipping_country($customer->get_shipping_country());
        }
    }
    
    // If no customer or customer data incomplete, use invoice billing/shipping address
    if (!$wc_customer_id) {
        // Extract customer name from invoice
        $customer_name = $zoho_invoice_data['customer_name'] ?? '';
        $name_parts = explode(' ', $customer_name, 2);
        $first_name = $name_parts[0] ?? '';
        $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
        
        // Set billing from invoice
        $billing_address = $zoho_invoice_data['billing_address'] ?? array();
        $order->set_billing_first_name($first_name);
        $order->set_billing_last_name($last_name);
        $order->set_billing_company($billing_address['company_name'] ?? '');
        $order->set_billing_address_1($billing_address['street'] ?? $billing_address['address'] ?? '');
        $order->set_billing_address_2($billing_address['street2'] ?? '');
        $order->set_billing_city($billing_address['city'] ?? '');
        $order->set_billing_state($billing_address['state'] ?? '');
        $order->set_billing_postcode($billing_address['zip'] ?? '');
        $order->set_billing_country($billing_address['country'] ?? '');
        $order->set_billing_email($zoho_invoice_data['email'] ?? '');
        $order->set_billing_phone($billing_address['phone'] ?? '');
        
        // Set shipping from invoice (fallback to billing if not available)
        $shipping_address = $zoho_invoice_data['shipping_address'] ?? $billing_address;
        $order->set_shipping_first_name($first_name);
        $order->set_shipping_last_name($last_name);
        $order->set_shipping_company($shipping_address['company_name'] ?? '');
        $order->set_shipping_address_1($shipping_address['street'] ?? $shipping_address['address'] ?? '');
        $order->set_shipping_address_2($shipping_address['street2'] ?? '');
        $order->set_shipping_city($shipping_address['city'] ?? '');
        $order->set_shipping_state($shipping_address['state'] ?? '');
        $order->set_shipping_postcode($shipping_address['zip'] ?? '');
        $order->set_shipping_country($shipping_address['country'] ?? '');
    }
    
    // Add line items
    $line_items = $zoho_invoice_data['line_items'] ?? array();
    if (empty($line_items)) {
        $order->delete(true);
        zoho_add_detailed_log(
            'invoice',
            'order',
            0,
            'invoice',
            $zoho_invoice_id,
            json_encode(array('action' => 'webhook_create', 'zoho_data' => $zoho_invoice_data)),
            json_encode(array('status' => 'failed', 'message' => 'No line items found in invoice'))
        );
        return false;
    }
    
    foreach ($line_items as $line_item) {
        $item_name = $line_item['name'] ?? 'Item';
        $quantity = floatval($line_item['quantity'] ?? 1);
        $rate = floatval($line_item['rate'] ?? 0);
        $item_total = floatval($line_item['item_total'] ?? ($rate * $quantity));
        
        // Try to find product by SKU
        $product_id = 0;
        if (!empty($line_item['sku'])) {
            $product_id = wc_get_product_id_by_sku($line_item['sku']);
        }
        
        // Try to find by Zoho item_id
        if (!$product_id && !empty($line_item['item_id'])) {
            $product_id = zoho_get_product_by_zoho_item($line_item['item_id']);
        }
        
        if ($product_id) {
            $product = wc_get_product($product_id);
            $order->add_product($product, $quantity, array(
                'subtotal' => $item_total,
                'total' => $item_total
            ));
        } else {
            // Add as custom line item
            $item = new WC_Order_Item_Product();
            $item->set_name($item_name);
            $item->set_quantity($quantity);
            $item->set_subtotal($item_total);
            $item->set_total($item_total);
            $order->add_item($item);
        }
    }
    
    // Add taxes if any
    foreach ($zoho_invoice_data['taxes'] ?? array() as $tax) {
        $tax_item = new WC_Order_Item_Tax();
        $tax_item->set_name($tax['tax_name'] ?? 'Tax');
        $tax_item->set_rate_code($tax['tax_name'] ?? '');
        $tax_item->set_tax_total($tax['tax_amount'] ?? 0);
        $order->add_item($tax_item);
    }
    
    // Set order totals
    $order->set_discount_total($zoho_invoice_data['discount_total'] ?? 0);
    $order->set_shipping_total($zoho_invoice_data['shipping_charge'] ?? 0);
    $order->set_cart_tax($zoho_invoice_data['tax_total'] ?? 0);
    $order->set_total($zoho_invoice_data['total'] ?? 0);
    $order->calculate_totals(false);
    
    // Set order status based on invoice status
    $zoho_status = strtolower($zoho_invoice_data['status'] ?? '');
    $woo_status = 'processing';
    
    switch ($zoho_status) {
        case 'paid':
            $woo_status = 'completed';
            break;
        case 'partially_paid':
        case 'sent':
            $woo_status = 'processing';
            break;
        case 'draft':
            $woo_status = 'pending';
            break;
        case 'void':
        case 'cancelled':
            $woo_status = 'cancelled';
            break;
    }
    
    $order->set_status($woo_status);
    
    // Add order notes
    $order->add_order_note('Created from Zoho Invoice webhook: ' . $zoho_invoice_id);
    if (!empty($zoho_invoice_data['reference_number'])) {
        $order->add_order_note('Zoho Reference Number: ' . $zoho_invoice_data['reference_number']);
    }
    
    // Save order
    $order->save();
    
    // Save Zoho invoice ID and metadata
    $order->update_meta_data('_zoho_invoice_id', $zoho_invoice_id);
    $org_id = get_option('zoho_organization_id', '');
    if (!empty($org_id)) {
        $order->update_meta_data('_zoho_org_id', $org_id);
    }
    if (!empty($zoho_invoice_data['reference_number'])) {
        $order->update_meta_data('_zoho_reference_number', $zoho_invoice_data['reference_number']);
    }
    $order->save();
    
    zoho_add_detailed_log(
        'invoice',
        'order',
        $order->get_id(),
        'invoice',
        $zoho_invoice_id,
        json_encode(array('action' => 'webhook_create', 'zoho_data' => $zoho_invoice_data)),
        json_encode(array('status' => 'created', 'order_id' => $order->get_id()))
    );
    
    return $order->get_id();
}

/**
 * Update WooCommerce order from Zoho invoice
 */
function zoho_update_woo_order_from_zoho_invoice($order_id, $zoho_invoice_data) {
    $order = wc_get_order($order_id);
    if (!$order) {
        return false;
    }
    
    // Set flag to prevent circular sync - skip WooCommerce → Zoho sync for next 10 seconds
    set_transient('zoho_webhook_updating_order_' . $order_id, true, 10);
    
    // Remove old line items
    foreach ($order->get_items() as $item_id => $item) {
        wc_delete_order_item($item_id);
    }
    
    // Update billing/shipping address if changed
    $billing_address = $zoho_invoice_data['billing_address'] ?? array();
    if (!empty($billing_address)) {
        $customer_name = $zoho_invoice_data['customer_name'] ?? '';
        $name_parts = explode(' ', $customer_name, 2);
        $first_name = $name_parts[0] ?? '';
        $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
        
        $order->set_billing_first_name($first_name);
        $order->set_billing_last_name($last_name);
        $order->set_billing_address_1($billing_address['street'] ?? $billing_address['address'] ?? '');
        $order->set_billing_address_2($billing_address['street2'] ?? '');
        $order->set_billing_city($billing_address['city'] ?? '');
        $order->set_billing_state($billing_address['state'] ?? '');
        $order->set_billing_postcode($billing_address['zip'] ?? '');
        $order->set_billing_country($billing_address['country'] ?? '');
        $order->set_billing_phone($billing_address['phone'] ?? '');
        
        $shipping_address = $zoho_invoice_data['shipping_address'] ?? $billing_address;
        $order->set_shipping_first_name($first_name);
        $order->set_shipping_last_name($last_name);
        $order->set_shipping_address_1($shipping_address['street'] ?? $shipping_address['address'] ?? '');
        $order->set_shipping_address_2($shipping_address['street2'] ?? '');
        $order->set_shipping_city($shipping_address['city'] ?? '');
        $order->set_shipping_state($shipping_address['state'] ?? '');
        $order->set_shipping_postcode($shipping_address['zip'] ?? '');
        $order->set_shipping_country($shipping_address['country'] ?? '');
    }
    
    // Add updated line items
    $line_items = $zoho_invoice_data['line_items'] ?? array();
    foreach ($line_items as $line_item) {
        $item_name = $line_item['name'] ?? 'Item';
        $quantity = floatval($line_item['quantity'] ?? 1);
        $rate = floatval($line_item['rate'] ?? 0);
        $item_total = floatval($line_item['item_total'] ?? ($rate * $quantity));
        
        // Try to find product by SKU
        $product_id = 0;
        if (!empty($line_item['sku'])) {
            $product_id = wc_get_product_id_by_sku($line_item['sku']);
        }
        
        // Try to find by Zoho item_id
        if (!$product_id && !empty($line_item['item_id'])) {
            $product_id = zoho_get_product_by_zoho_item($line_item['item_id']);
        }
        
        if ($product_id) {
            $product = wc_get_product($product_id);
            $order->add_product($product, $quantity, array(
                'subtotal' => $item_total,
                'total' => $item_total
            ));
        } else {
            // Add as custom line item
            $item = new WC_Order_Item_Product();
            $item->set_name($item_name);
            $item->set_quantity($quantity);
            $item->set_subtotal($item_total);
            $item->set_total($item_total);
            $order->add_item($item);
        }
    }
    
    // Update totals
    $order->set_discount_total($zoho_invoice_data['discount_total'] ?? 0);
    $order->set_shipping_total($zoho_invoice_data['shipping_charge'] ?? 0);
    $order->set_cart_tax($zoho_invoice_data['tax_total'] ?? 0);
    $order->set_total($zoho_invoice_data['total'] ?? 0);
    $order->calculate_totals(false);
    
    // Update order status
    if (isset($zoho_invoice_data['status'])) {
        $zoho_status = strtolower($zoho_invoice_data['status']);
        $woo_status = 'processing';
        
        switch ($zoho_status) {
            case 'paid':
                $woo_status = 'completed';
                break;
            case 'partially_paid':
            case 'sent':
                $woo_status = 'processing';
                break;
            case 'draft':
                $woo_status = 'pending';
                break;
            case 'void':
            case 'cancelled':
                $woo_status = 'cancelled';
                break;
        }
        
        $order->update_status($woo_status);
    }
    
    // Add order note
    $order->add_order_note('Updated from Zoho Invoice webhook: ' . ($zoho_invoice_data['invoice_id'] ?? ''));
    
    // Save order
    $order->save();
    
    zoho_add_detailed_log(
        'invoice',
        'order',
        $order_id,
        'invoice',
        $zoho_invoice_data['invoice_id'] ?? '',
        json_encode(array('action' => 'webhook_update', 'zoho_data' => $zoho_invoice_data)),
        json_encode(array('status' => 'updated', 'order_id' => $order_id))
    );
    
    return $order_id;
}

/**
 * Delete WooCommerce order when Zoho invoice is deleted
 * @param string $zoho_invoice_id Zoho invoice ID
 * @param bool $force_delete If true, delete even if auto_delete setting is off (for webhooks)
 */
function zoho_delete_woo_order_from_zoho_invoice($zoho_invoice_id, $force_delete = false) {
    // Check auto_delete setting only if not forced (for webhooks, we force delete)
    if (!$force_delete && get_option('zoho_auto_delete_woo_orders', 'no') !== 'yes') {
        return;
    }
    
    // Use helper function for HPOS-compatible lookup
    $order_id = 0;
    if (function_exists('zoho_get_order_by_zoho_invoice')) {
        $order_id = zoho_get_order_by_zoho_invoice($zoho_invoice_id);
    } else {
        global $wpdb;
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zoho_invoice_id' AND meta_value = %s LIMIT 1",
            $zoho_invoice_id
        ));
    }
    
    if (!$order_id) {
        zoho_add_detailed_log(
            'invoice',
            'order',
            0,
            'invoice',
            $zoho_invoice_id,
            json_encode(array('action' => 'webhook_delete')),
            json_encode(array('status' => 'not_found', 'message' => 'Order not found in WooCommerce'))
        );
        return;
    }
    
    // Delete the order permanently
    $deleted = wp_delete_post($order_id, true);
    
    if ($deleted) {
        zoho_add_detailed_log(
            'invoice',
            'order',
            $order_id,
            'invoice',
            $zoho_invoice_id,
            json_encode(array('action' => 'webhook_delete')),
            json_encode(array('status' => 'deleted', 'order_id' => $order_id))
        );
    } else {
        zoho_add_detailed_log(
            'invoice',
            'order',
            $order_id,
            'invoice',
            $zoho_invoice_id,
            json_encode(array('action' => 'webhook_delete')),
            json_encode(array('status' => 'failed', 'message' => 'Failed to delete order', 'order_id' => $order_id))
        );
    }
}

