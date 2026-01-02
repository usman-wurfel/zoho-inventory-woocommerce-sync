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
        zoho_delete_woo_order_from_zoho_invoice($object_id);
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
            zoho_delete_woo_order_from_zoho_invoice($invoice_id);
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
    
    // Set flag to prevent circular sync - skip WooCommerce → Zoho sync for next 10 seconds
    set_transient('zoho_webhook_updating_order_' . $order_id, true, 10);
    
    if (isset($zoho_invoice_data['status'])) {
        $zoho_status = strtolower($zoho_invoice_data['status']);
        $woo_status = 'pending';
        
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

