<?php
/**
 * Auto Sync - Automatic sync when WooCommerce products/orders/contacts are updated
 */

if (!defined('ABSPATH')) exit;

/* ======================================================
   HELPER: Sync Single Product to Zoho
====================================================== */
function zoho_sync_single_product($product_id) {
    // Check if Zoho is connected
    if (!get_option('zoho_access_token')) {
        return false;
    }
    
    // Check if auto-sync is enabled
    if (get_option('zoho_auto_sync_products', 'yes') !== 'yes') {
        return false;
    }
    
    // Get product
    $product = wc_get_product($product_id);
    if (!$product || $product->get_type() === 'variation') {
        return false;
    }
    
    // Skip if product is trashed
    if ($product->get_status() === 'trash') {
        return false;
    }
    
    // Check if product belongs to current organization
    $current_org_id = get_option('zoho_organization_id', '');
    $product_org_id = get_post_meta($product_id, '_zoho_org_id', true);
    
    // Only sync if product has no org_id (new product) or matches current org
    if (!empty($current_org_id) && !empty($product_org_id) && $product_org_id !== $current_org_id) {
        return false; // Product belongs to different organization
    }
    
    // Check if product has SKU (required for Zoho)
    $sku = $product->get_sku();
    if (empty($sku)) {
        return false;
    }
    
    // Get existing Zoho item ID
    $zoho_item_id = get_post_meta($product_id, '_zoho_item_id', true);
    
    // Build item data - respect field mapping toggle settings (same as manual sync)
    $is_service = $product->is_virtual() || $product->is_downloadable() || !$product->get_manage_stock();
    $product_type = $is_service ? 'service' : 'goods';
    
    $item_data = array();
    
    // Only add fields if their sync toggle is enabled (same logic as manual sync)
    if (get_option('zoho_sync_name', 'yes') == 'yes') {
        $item_data['name'] = $product->get_name();
    }
    
    // SKU is always required, so always include it
    $item_data['sku'] = $sku;
    
    if (get_option('zoho_sync_price', 'yes') == 'yes') {
        $item_data['rate'] = floatval($product->get_regular_price()) ?: 0;
    }
    
    if (get_option('zoho_sync_description', 'yes') == 'yes') {
        $item_data['description'] = wp_strip_all_tags($product->get_description());
    }
    
    // product_type and item_type are always needed for proper item creation
    $item_data['product_type'] = $product_type;
    $item_data['item_type'] = ($product_type === 'goods') ? 'inventory' : 'sales';
    
    if ($product_type === 'goods') {
        // Stock sync - only if enabled
        if (get_option('zoho_sync_stock', 'yes') == 'yes') {
            $stock = $product->get_stock_quantity();
            $stock_value = (is_numeric($stock) && $stock !== '') ? intval($stock) : 0;
            $item_data['initial_stock'] = max(0, $stock_value);
            
            // initial_stock_rate should match rate if price sync is enabled
            if (get_option('zoho_sync_price', 'yes') == 'yes') {
                $item_data['initial_stock_rate'] = floatval($product->get_regular_price()) ?: 0;
            }
        }
        
        // Inventory account and valuation method are always included if available
        $inventory_account_id = get_option('zoho_inventory_account_id', '');
        if (!empty($inventory_account_id)) {
            $item_data['inventory_account_id'] = intval($inventory_account_id);
        }
        
        $inventory_valuation_method = get_option('zoho_inventory_valuation_method', '');
        if (!empty($inventory_valuation_method)) {
            $item_data['inventory_valuation_method'] = $inventory_valuation_method;
        }
    }
    
    $response = null;
    $action = '';
    
    if ($zoho_item_id) {
        // Try to update existing item
        $update_data = $item_data;
        unset($update_data['item_type']);
        unset($update_data['product_type']);
        unset($update_data['initial_stock']);
        unset($update_data['initial_stock_rate']);
        unset($update_data['inventory_account_id']);
        unset($update_data['inventory_valuation_method']);
        
        $response = zoho_api_put('items/' . $zoho_item_id, $update_data);
        
        // If item doesn't exist in Zoho, clear ID and create new
        if (isset($response['code']) && $response['code'] == 1002) {
            delete_post_meta($product_id, '_zoho_item_id');
            $zoho_item_id = '';
        } else {
            $action = 'updated';
        }
    }
    
    // If no existing ID or update failed, search by SKU
    if (empty($zoho_item_id) && empty($action)) {
        $search_response = zoho_api_get('items?sku=' . urlencode($sku));
        if (!is_wp_error($search_response) && isset($search_response['items']) && !empty($search_response['items'])) {
            $zoho_item_id = $search_response['items'][0]['item_id'];
            // Update found item
            $update_data = $item_data;
            unset($update_data['item_type']);
            unset($update_data['product_type']);
            unset($update_data['initial_stock']);
            unset($update_data['initial_stock_rate']);
            unset($update_data['inventory_account_id']);
            unset($update_data['inventory_valuation_method']);
            $response = zoho_api_put('items/' . $zoho_item_id, $update_data);
            $action = 'updated';
        }
    }
    
    // Create new item if still no ID
    if (empty($zoho_item_id) && empty($action)) {
        // For new items, ensure name is included (required by Zoho API)
        if (!isset($item_data['name']) || empty($item_data['name'])) {
            $item_data['name'] = $product->get_name();
        }
        
        $response = zoho_api_post('items', $item_data);
        $action = 'created';
        
        // Fallback if error with item_type/product_type
        if (isset($response['code']) && $response['code'] == 2) {
            $item_data_fallback = $item_data;
            unset($item_data_fallback['item_type']);
            unset($item_data_fallback['product_type']);
            $response = zoho_api_post('items', $item_data_fallback);
        }
    }
    
    // Save Zoho Item ID if successful
    if (isset($response['item']['item_id'])) {
        $zoho_item_id = $response['item']['item_id'];
        update_post_meta($product_id, '_zoho_item_id', $zoho_item_id);
        $org_id = get_option('zoho_organization_id', '');
        if (!empty($org_id)) {
            update_post_meta($product_id, '_zoho_org_id', $org_id);
        }
    } elseif (!empty($zoho_item_id)) {
        // Use existing ID for updates
        $zoho_item_id = $zoho_item_id;
    }
    
    // Only create inventory adjustment if stock was manually changed (not on every update)
    // Check if this update was triggered specifically by a stock change
    $stock_was_changed = get_transient('_zoho_stock_changed_' . $product_id);
    
    if ($action === 'updated' && !empty($zoho_item_id) && $stock_was_changed && isset($item_data['initial_stock']) && get_option('zoho_sync_stock', 'yes') == 'yes') {
        // Get current stock from Zoho
        $current_item = zoho_api_get('items/' . $zoho_item_id);
        if (!is_wp_error($current_item) && isset($current_item['item']['stock_on_hand'])) {
            $zoho_current_stock = intval($current_item['item']['stock_on_hand']);
            $woo_stock = intval($item_data['initial_stock']);
            
            // Only create adjustment if stock is different
            if ($zoho_current_stock != $woo_stock && $woo_stock >= 0) {
                $stock_difference = $woo_stock - $zoho_current_stock;
                
                // Create inventory adjustment
                $adjustment_data = array(
                    'date' => current_time('Y-m-d'),
                    'adjustment_type' => 'quantity',
                    'reason' => 'Stock updated from WooCommerce',
                    'line_items' => array(
                        array(
                            'item_id' => $zoho_item_id,
                            'quantity_adjusted' => $stock_difference,
                        ),
                    ),
                );
                
                $adjustment_response = zoho_api_post('inventoryadjustments', $adjustment_data);
                
                // Log the adjustment - ensure product_id is integer
                if (!is_wp_error($adjustment_response) && (!isset($adjustment_response['code']) || $adjustment_response['code'] == 0)) {
                    $log_product_id = is_numeric($product_id) ? intval($product_id) : (is_a($product_id, 'WC_Product') ? $product_id->get_id() : 0);
                    zoho_add_detailed_log(
                        'item',
                        'product',
                        $log_product_id,
                        'inventoryadjustments',
                        isset($adjustment_response['inventoryadjustment']['adjustment_id']) ? $adjustment_response['inventoryadjustment']['adjustment_id'] : '',
                        json_encode($adjustment_data),
                        json_encode($adjustment_response)
                    );
                }
            }
        }
        
        // Clear the transient after processing
        delete_transient('_zoho_stock_changed_' . $product_id);
    }
    
    // Log the sync - ensure product_id is integer, not object or array
    $log_product_id = is_numeric($product_id) ? intval($product_id) : (is_a($product_id, 'WC_Product') ? $product_id->get_id() : 0);
    
    zoho_add_detailed_log(
        'item',
        'product',
        $log_product_id,
        'items-inventory',
        $zoho_item_id ?: '',
        json_encode($item_data),
        json_encode($response)
    );
    
    return !isset($response['error']) && (!isset($response['code']) || $response['code'] == 0);
}

/* ======================================================
   HELPER: Sync Single Order to Zoho Invoice
====================================================== */
function zoho_sync_single_order($order_id) {
    // Check if Zoho is connected
    if (!get_option('zoho_access_token')) {
        return false;
    }
    
    // Check if auto-sync is enabled
    if (get_option('zoho_auto_sync_invoices', 'yes') !== 'yes') {
        return false;
    }
    
    // Get order
    $order = wc_get_order($order_id);
    if (!$order) {
        return false;
    }
    
    // Check if order belongs to current organization
    $current_org_id = get_option('zoho_organization_id', '');
    $order_org_id = $order->get_meta('_zoho_org_id', true);
    
    // Only sync if order has no org_id (new order) or matches current org
    if (!empty($current_org_id) && !empty($order_org_id) && $order_org_id !== $current_org_id) {
        return false; // Order belongs to different organization
    }
    
    // Get customer
    $customer_id = $order->get_customer_id();
    if (!$customer_id) {
        return false; // Guest orders not supported for auto-sync
    }
    
    // Get Zoho contact ID for customer
    $zoho_customer_id = get_user_meta($customer_id, '_zoho_contact_id', true);
    if (empty($zoho_customer_id)) {
        // Try to sync customer first
        zoho_sync_single_contact($customer_id);
        $zoho_customer_id = get_user_meta($customer_id, '_zoho_contact_id', true);
        if (empty($zoho_customer_id)) {
            return false; // Customer not found in Zoho
        }
    }
    
    // Build invoice data (simplified version) - use same format as manual sync
    $invoice_date = $order->get_date_created()->date('Y-m-d');
    
    // Get reference number format from settings (same as manual sync)
    $reference_number_type = get_option('zoho_invoice_reference_number_type', 'custom_value');
    $reference_number_value = get_option('zoho_invoice_reference_number_value', 'woo-order-{shop_order_order_id}');
    
    // Format reference number same as manual sync
    if ($reference_number_type === 'custom_value' && !empty($reference_number_value)) {
        $reference_number = str_replace('{shop_order_order_id}', $order_id, $reference_number_value);
    } else {
        $reference_number = $order->get_order_number();
    }
    
    $line_items = array();
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $zoho_item_id = get_post_meta($product_id, '_zoho_item_id', true);
        
        if (empty($zoho_item_id)) {
            continue; // Skip items not in Zoho
        }
        
        $quantity = $item->get_quantity();
        $rate = $item->get_subtotal() / $quantity;
        
        $line_items[] = array(
            'item_id' => $zoho_item_id,
            'quantity' => $quantity,
            'rate' => round($rate, 2),
        );
    }
    
    if (empty($line_items)) {
        return false; // No valid line items
    }
    
    $invoice_data = array(
        'customer_id' => (string)$zoho_customer_id,
        'date' => $invoice_date,
        'reference_number' => $reference_number,
        'line_items' => $line_items,
    );
    
    $existing_invoice_id = $order->get_meta('_zoho_invoice_id', true);
    
    if ($existing_invoice_id) {
        $invoice_data['reason'] = 'Invoice updated from WooCommerce';
        $response = zoho_api_put('invoices/' . $existing_invoice_id, $invoice_data);
        
        if (isset($response['code']) && $response['code'] == 1002) {
            // Invoice doesn't exist, clear ID and create new
            $order->delete_meta_data('_zoho_invoice_id');
            $order->save();
            unset($invoice_data['reason']);
            $response = zoho_api_post('invoices', $invoice_data);
            $action = 'created';
        } else {
            $action = 'updated';
        }
    } else {
        $response = zoho_api_post('invoices', $invoice_data);
        $action = 'created';
    }
    
    // Save invoice ID if successful
    if (isset($response['invoice']['invoice_id'])) {
        $zoho_invoice_id = $response['invoice']['invoice_id'];
        $order->update_meta_data('_zoho_invoice_id', $zoho_invoice_id);
        $org_id = get_option('zoho_organization_id', '');
        if (!empty($org_id)) {
            $order->update_meta_data('_zoho_org_id', $org_id);
        }
        $order->save();
    }
    
    // Log the sync
    zoho_add_detailed_log(
        'invoice',
        'order',
        $order_id,
        'invoice',
        isset($response['invoice']['invoice_id']) ? $response['invoice']['invoice_id'] : '',
        json_encode($invoice_data),
        json_encode($response)
    );
    
    return !isset($response['error']) && (!isset($response['code']) || $response['code'] == 0);
}

/* ======================================================
   HELPER: Sync Single Contact to Zoho
====================================================== */
function zoho_sync_single_contact($user_id) {
    // Check if Zoho is connected
    if (!get_option('zoho_access_token')) {
        return false;
    }
    
    // Check if auto-sync is enabled
    if (get_option('zoho_auto_sync_contacts', 'yes') !== 'yes') {
        return false;
    }
    
    // Check if user has customer role
    $user = new WP_User($user_id);
    if (!in_array('customer', $user->roles)) {
        return false;
    }
    
    // Check if contact belongs to current organization
    $current_org_id = get_option('zoho_organization_id', '');
    $contact_org_id = get_user_meta($user_id, '_zoho_org_id', true);
    
    // Only sync if contact has no org_id (new contact) or matches current org
    if (!empty($current_org_id) && !empty($contact_org_id) && $contact_org_id !== $current_org_id) {
        return false; // Contact belongs to different organization
    }
    
    // Get customer data
    $customer = new WC_Customer($user_id);
    $customer->read_meta_data();
    
    // Build contact data (using function from contact.php)
    if (function_exists('zoho_build_contact_data_from_customer')) {
        $contact_data = zoho_build_contact_data_from_customer($customer);
    } else {
        return false; // Function not available
    }
    
    if (empty($contact_data['contact_name'])) {
        return false; // No contact name
    }
    
    $existing_contact_id = get_user_meta($user_id, '_zoho_contact_id', true);
    $response = null;
    $action = '';
    
    if ($existing_contact_id) {
        // Try to update
        $response = zoho_api_put('contacts/' . $existing_contact_id, $contact_data);
        
        if (isset($response['code']) && $response['code'] == 1002) {
            // Contact doesn't exist, clear ID
            delete_user_meta($user_id, '_zoho_contact_id');
            $existing_contact_id = '';
        } else {
            $action = 'updated';
        }
    }
    
    // Search by email if no existing ID
    if (empty($existing_contact_id) && empty($action) && !empty($contact_data['email'])) {
        $search_response = zoho_api_get('contacts?email=' . urlencode($contact_data['email']));
        if (!is_wp_error($search_response) && isset($search_response['contacts']) && !empty($search_response['contacts'])) {
            $existing_contact_id = $search_response['contacts'][0]['contact_id'];
            $response = zoho_api_put('contacts/' . $existing_contact_id, $contact_data);
            $action = 'updated';
        }
    }
    
    // Create new contact if still no ID
    if (empty($existing_contact_id) && empty($action)) {
        $response = zoho_api_post('contacts', $contact_data);
        $action = 'created';
    }
    
    // Save contact ID if successful
    if (isset($response['contact']['contact_id'])) {
        $zoho_contact_id = $response['contact']['contact_id'];
        update_user_meta($user_id, '_zoho_contact_id', $zoho_contact_id);
        $org_id = get_option('zoho_organization_id', '');
        if (!empty($org_id)) {
            update_user_meta($user_id, '_zoho_org_id', $org_id);
        }
    } elseif (!empty($existing_contact_id)) {
        $zoho_contact_id = $existing_contact_id;
    } else {
        $zoho_contact_id = '';
    }
    
    // Log the sync
    zoho_add_detailed_log(
        'contact',
        'customer',
        $user_id,
        'contact',
        $zoho_contact_id,
        json_encode($contact_data),
        json_encode($response)
    );
    
    return !isset($response['error']) && (!isset($response['code']) || $response['code'] == 0);
}

/* ======================================================
   HELPER: Delete Item from Zoho
====================================================== */
function zoho_delete_item($zoho_item_id) {
    if (empty($zoho_item_id)) {
        return false;
    }
    
    // Check if auto-delete is enabled
    if (get_option('zoho_auto_delete_items', 'yes') !== 'yes') {
        return false;
    }
    
    $response = zoho_api_put('items/' . $zoho_item_id . '/active', array('is_active' => false));
    
    // Log the deletion attempt
    zoho_add_detailed_log(
        'item',
        'product',
        0,
        'items-inventory',
        $zoho_item_id,
        json_encode(array('action' => 'delete', 'item_id' => $zoho_item_id)),
        json_encode($response)
    );
    
    return !isset($response['error']) && (!isset($response['code']) || $response['code'] == 0);
}

/* ======================================================
   HELPER: Delete Invoice from Zoho
====================================================== */
function zoho_delete_invoice($zoho_invoice_id) {
    if (empty($zoho_invoice_id)) {
        return false;
    }
    
    // Check if auto-delete is enabled
    if (get_option('zoho_auto_delete_invoices', 'yes') !== 'yes') {
        return false;
    }
    
    // Note: Zoho doesn't allow deleting invoices, only voiding/cancelling
    // We'll mark it as cancelled
    $response = zoho_api_post('invoices/' . $zoho_invoice_id . '/status/cancelled', array());
    
    zoho_add_detailed_log(
        'invoice',
        'order',
        0,
        'invoice',
        $zoho_invoice_id,
        json_encode(array('action' => 'delete', 'invoice_id' => $zoho_invoice_id)),
        json_encode($response)
    );
    
    return !isset($response['error']) && (!isset($response['code']) || $response['code'] == 0);
}

/* ======================================================
   HELPER: Delete Contact from Zoho
====================================================== */
function zoho_delete_contact($zoho_contact_id) {
    if (empty($zoho_contact_id)) {
        return false;
    }
    
    // Check if auto-delete is enabled
    if (get_option('zoho_auto_delete_contacts', 'yes') !== 'yes') {
        return false;
    }
    
    // Delete contact
    $response = zoho_api_put('contacts/' . $zoho_contact_id . '/active', array('is_active' => false));
    
    zoho_add_detailed_log(
        'contact',
        'customer',
        0,
        'contact',
        $zoho_contact_id,
        json_encode(array('action' => 'delete', 'contact_id' => $zoho_contact_id)),
        json_encode($response)
    );
    
    return !isset($response['error']) && (!isset($response['code']) || $response['code'] == 0);
}

/* ======================================================
   WOOCOMMERCE HOOKS - Products
====================================================== */
// Track stock changes specifically - only create adjustment when stock is manually changed
add_action('woocommerce_product_set_stock', 'zoho_track_stock_change', 10, 1);
function zoho_track_stock_change($product) {
    if (is_a($product, 'WC_Product')) {
        $product_id = $product->get_id();
        // Set transient to mark that stock was manually changed for this product
        set_transient('_zoho_stock_changed_' . $product_id, true, 30); // Expires in 30 seconds
    }
}

// Product updated/saved - use shutdown hook to run after save completes
add_action('woocommerce_update_product', 'zoho_schedule_product_sync', 10, 1);
add_action('woocommerce_new_product', 'zoho_schedule_product_sync', 10, 1);
add_action('woocommerce_product_quick_edit_save', 'zoho_schedule_product_sync', 10, 1);
function zoho_schedule_product_sync($product_id) {
    // Skip if this is a variation or not a simple product
    $product = wc_get_product($product_id);
    if (!$product || $product->get_type() === 'variation') {
        return;
    }
    
    // Store product ID to sync on shutdown
    static $products_to_sync = array();
    if (!in_array($product_id, $products_to_sync)) {
        $products_to_sync[] = $product_id;
        // Run on shutdown to ensure product is fully saved
        add_action('shutdown', function() use ($product_id) {
            zoho_sync_single_product($product_id);
        }, 20);
    }
}

// Product deleted
add_action('before_delete_post', 'zoho_auto_delete_product', 10, 1);
function zoho_auto_delete_product($post_id) {
    $post_type = get_post_type($post_id);
    if ($post_type !== 'product') {
        return;
    }
    
    $product = wc_get_product($post_id);
    if (!$product || $product->get_type() === 'variation') {
        return;
    }
    
    $zoho_item_id = get_post_meta($post_id, '_zoho_item_id', true);
    if (!empty($zoho_item_id)) {
        zoho_delete_item($zoho_item_id);
    }
}

/* ======================================================
   WOOCOMMERCE HOOKS - Orders
====================================================== */
// Order created/updated
add_action('woocommerce_new_order', 'zoho_schedule_order_sync', 10, 1);
add_action('woocommerce_update_order', 'zoho_schedule_order_sync', 10, 1);
add_action('woocommerce_checkout_order_processed', 'zoho_schedule_order_sync', 10, 1);
function zoho_schedule_order_sync($order_id) {
    // Store order ID to sync on shutdown
    static $orders_to_sync = array();
    if (!in_array($order_id, $orders_to_sync)) {
        $orders_to_sync[] = $order_id;
        // Run on shutdown to ensure order is fully saved
        add_action('shutdown', function() use ($order_id) {
            zoho_sync_single_order($order_id);
        }, 20);
    }
}

// Order deleted
add_action('before_delete_post', 'zoho_auto_delete_order', 10, 1);
function zoho_auto_delete_order($post_id) {
    $post_type = get_post_type($post_id);
    if ($post_type !== 'shop_order') {
        return;
    }
    
    $order = wc_get_order($post_id);
    if (!$order) {
        return;
    }
    
    $zoho_invoice_id = $order->get_meta('_zoho_invoice_id', true);
    if (!empty($zoho_invoice_id)) {
        zoho_delete_invoice($zoho_invoice_id);
    }
}

/* ======================================================
   WOOCOMMERCE HOOKS - Contacts/Customers
====================================================== */
// Customer updated/created
add_action('profile_update', 'zoho_schedule_contact_sync', 10, 2);
add_action('user_register', 'zoho_schedule_contact_sync', 10, 1);
add_action('woocommerce_created_customer', 'zoho_schedule_contact_sync', 10, 1);
add_action('woocommerce_update_customer', 'zoho_schedule_contact_sync', 10, 1);
function zoho_schedule_contact_sync($user_id, $old_user_data = null) {
    // Check if user has customer role
    $user = new WP_User($user_id);
    if (!in_array('customer', $user->roles)) {
        return;
    }
    
    // Store user ID to sync on shutdown
    static $users_to_sync = array();
    if (!in_array($user_id, $users_to_sync)) {
        $users_to_sync[] = $user_id;
        // Run on shutdown to ensure user is fully saved
        add_action('shutdown', function() use ($user_id) {
            zoho_sync_single_contact($user_id);
        }, 20);
    }
}

// Customer deleted
add_action('delete_user', 'zoho_auto_delete_contact', 10, 1);
function zoho_auto_delete_contact($user_id) {
    $zoho_contact_id = get_user_meta($user_id, '_zoho_contact_id', true);
    if (!empty($zoho_contact_id)) {
        zoho_delete_contact($zoho_contact_id);
    }
}

