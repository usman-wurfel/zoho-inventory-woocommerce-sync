<?php
/**
 * Logs Page and Log Helper Functions
 */

if (!defined('ABSPATH')) exit;

/* ======================================================
   HELPER: Render SKU Table
====================================================== */
function zoho_render_sku_table($skus) {
    if (empty($skus)) { echo '<p>None</p>'; return; }
    echo '<table class="widefat"><tbody>';
    foreach ($skus as $sku) {
        echo '<tr><td>'.esc_html($sku).'</td></tr>';
    }
    echo '</tbody></table>';
}

function zoho_render_pending_table($items) {
    if (empty($items)) { echo '<p>None</p>'; return; }
    echo '<table class="widefat"><thead>
        <tr><th>SKU</th><th>Reason</th></tr>
    </thead><tbody>';
    foreach ($items as $row) {
        echo '<tr>
            <td>'.esc_html($row['sku']).'</td>
            <td>'.esc_html($row['reason']).'</td>
        </tr>';
    }
    echo '</tbody></table>';
}

/* ======================================================
   SAVE LOG
====================================================== */
function zoho_add_sync_log($total,$synced,$synced_skus,$pending_skus,$created=0,$updated=0) {

    $logs = get_option('zoho_inventory_logs', []);

    $logs[] = [
        'id' => uniqid('sync_'),
        'time' => current_time('mysql'),
        'total' => $total,
        'synced' => $synced,
        'created' => $created,
        'updated' => $updated,
        'pending' => $total - $synced,
        'synced_skus' => $synced_skus,
        'pending_skus' => $pending_skus
    ];

    update_option('zoho_inventory_logs', array_slice($logs, -20));
}

/* ======================================================
   HELPER: Add Detailed Log Entry
====================================================== */
function zoho_add_detailed_log($type, $woo_object, $woo_object_id, $zoho_object, $zoho_object_id, $request, $response) {
    $logs = get_option('zoho_detailed_logs', []);
    
    $new_log = array(
        'id' => uniqid('log_'),
        'type' => $type, // 'item', 'invoice', 'contact'
        'time' => current_time('d-m-Y h:i A'),
        'timestamp' => time(), // Unix timestamp for filtering
        'woo_object' => $woo_object,
        'woo_object_id' => $woo_object_id,
        'zoho_object' => $zoho_object,
        'zoho_object_id' => $zoho_object_id,
        'request' => is_string($request) ? $request : json_encode($request),
        'response' => is_string($response) ? $response : json_encode($response),
    );
    
    $logs[] = $new_log;
    
    // Remove logs older than 30 days
    $thirty_days_ago = time() - (30 * 24 * 60 * 60);
    $logs = array_filter($logs, function($log) use ($thirty_days_ago) {
        // Keep log if it has no timestamp (old logs) or timestamp is within 30 days
        return !isset($log['timestamp']) || $log['timestamp'] >= $thirty_days_ago;
    });
    
    // Re-index array after filtering
    $logs = array_values($logs);
    
    // Keep only last 1000 logs max
    $logs = array_slice($logs, -1000);
    
    // Save logs (use add_option if doesn't exist, update_option if exists)
    if (get_option('zoho_detailed_logs') === false) {
        $result = add_option('zoho_detailed_logs', $logs);
    } else {
        $result = update_option('zoho_detailed_logs', $logs);
    }
    
    // If save fails, try to save to a smaller array (prevent option size limit issues)
    if ($result === false && count($logs) > 100) {
        $logs = array_slice($logs, -100); // Keep only last 100
        update_option('zoho_detailed_logs', $logs);
    }
}

/* ======================================================
   HELPER: Delete Logs by WooCommerce Object ID
====================================================== */
function zoho_delete_logs_by_woo_id($type, $woo_object_id) {
    $logs = get_option('zoho_detailed_logs', []);
    
    // Remove all logs matching type and woo_object_id
    $logs = array_values(array_filter($logs, function($log) use ($type, $woo_object_id) {
        return !(($log['type'] ?? '') === $type && ($log['woo_object_id'] ?? '') == $woo_object_id);
    }));
    
    update_option('zoho_detailed_logs', $logs);
}

/* ======================================================
   HELPER: Check if Log is Successful
====================================================== */
function zoho_is_log_successful($log) {
    $response = json_decode($log['response'] ?? '{}', true);
    $message = isset($response['message']) ? strtolower($response['message']) : '';
    
    // Check for success indicators FIRST
    if (isset($response['code']) && $response['code'] == 0) return true;
    if (isset($response['item']) || isset($response['contact']) || isset($response['invoice'])) return true;
    if (strpos($message, 'success') !== false || 
        strpos($message, 'created') !== false || 
        strpos($message, 'updated') !== false) return true;
    
    // "Already exists" (Code 1001) is a FAILURE - needs retry to update
    if (isset($response['code']) && $response['code'] == 1001) return false;
    if (strpos($message, 'already exists') !== false) return false;
    
    // Check for error indicators
    if (isset($response['error'])) return false;
    if (isset($response['code']) && $response['code'] != 0) return false;
    if (strpos($message, 'error') !== false) return false;
    if (strpos($message, 'failed') !== false) return false;
    
    // Default to successful if no error found
    return true;
}

/* ======================================================
   HELPER: Extract Error Message from Log
====================================================== */
function zoho_get_error_message($log) {
    $response = json_decode($log['response'] ?? '{}', true);
    
    if (isset($response['error'])) return $response['error'];
    if (isset($response['message'])) return $response['message'];
    if (isset($response['code']) && $response['code'] != 0) return 'Error Code: ' . $response['code'];
    
    return 'Unknown error';
}

/* ======================================================
   AJAX HANDLER: Clear Logs
====================================================== */
add_action('wp_ajax_zoho_clear_logs', 'zoho_clear_logs_handler');
function zoho_clear_logs_handler() {
    check_ajax_referer('zoho_logs_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
        return;
    }
    
    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'all';
    
    if ($type === 'all') {
        // Clear all logs
        update_option('zoho_detailed_logs', []);
    } else {
        // Clear only specific type logs
        $logs = get_option('zoho_detailed_logs', []);
        $logs = array_values(array_filter($logs, function($log) use ($type) {
            return ($log['type'] ?? '') !== $type;
        }));
        update_option('zoho_detailed_logs', $logs);
    }
    
    wp_send_json_success('Logs cleared successfully');
}

/* ======================================================
   AJAX HANDLER: Retry Failed Sync
====================================================== */
add_action('wp_ajax_zoho_retry_sync', 'zoho_retry_sync_handler');
function zoho_retry_sync_handler() {
    check_ajax_referer('zoho_logs_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
        return;
    }
    
    $log_id = isset($_POST['log_id']) ? sanitize_text_field($_POST['log_id']) : '';
    
    if (empty($log_id)) {
        wp_send_json_error('No log ID provided');
        return;
    }
    
    // Find the log
    $logs = get_option('zoho_detailed_logs', []);
    $target_log = null;
    
    foreach ($logs as $log) {
        if (isset($log['id']) && $log['id'] === $log_id) {
            $target_log = $log;
            break;
        }
    }
    
    if (!$target_log) {
        wp_send_json_error('Log not found');
        return;
    }
    
    $type = $target_log['type'] ?? '';
    $woo_id = $target_log['woo_object_id'] ?? '';
    $request_data = json_decode($target_log['request'] ?? '{}', true);
    $zoho_object_id = $target_log['zoho_object_id'] ?? '';
    
    $result = array('success' => false, 'message' => 'Unknown error');
    
    if ($type === 'item') {
        $product = null;
        $is_zoho_to_woo_retry = false;
        
        // If woo_id is empty, this is a Zoho to WooCommerce sync retry
        if (empty($woo_id) && !empty($request_data)) {
            $is_zoho_to_woo_retry = true;
            // Extract SKU from Zoho item data
            $zoho_sku = isset($request_data['sku']) ? $request_data['sku'] : '';
            
            if (empty($zoho_sku)) {
                wp_send_json_error('No SKU found in Zoho item data. Cannot create/find product.');
                return;
            }
            
            // Try to find existing product by SKU
            $woo_id = wc_get_product_id_by_sku($zoho_sku);
            
            if ($woo_id) {
                $product = wc_get_product($woo_id);
                $is_new_product = false;
            } else {
                // Product doesn't exist - create it from Zoho item data
                try {
                    $product = new WC_Product_Simple();
                    $product->set_sku($zoho_sku);
                    
                    $name_value = isset($request_data['name']) ? $request_data['name'] : $zoho_sku;
                    $product->set_name($name_value);
                    $product->set_slug(sanitize_title($name_value));
                    $product->set_status('publish');
                    $product->set_catalog_visibility('visible');
                    
                    // Set price if available
                    if (isset($request_data['rate']) && $request_data['rate'] > 0) {
                        $product->set_regular_price(floatval($request_data['rate']));
                    }
                    
                    // Set description if available
                    if (isset($request_data['description']) && !empty($request_data['description'])) {
                        $product->set_description($request_data['description']);
                    }
                    
                    // Set stock if available
                    if (isset($request_data['initial_stock']) && $request_data['initial_stock'] !== '') {
                        $stock_value = intval($request_data['initial_stock']);
                        $product->set_manage_stock(true);
                        $product->set_stock_quantity(max(0, $stock_value));
                        $product->set_stock_status($stock_value > 0 ? 'instock' : 'outofstock');
                    }

                    $product->save();
                    $woo_id = $product->get_id();
                    
                    if (!$woo_id) {
                        throw new Exception('Failed to create product - no ID returned');
                    }
                    
                    $is_new_product = true;
                } catch (Exception $e) {
                    wp_send_json_error('Failed to create product from Zoho item: ' . $e->getMessage());
                    return;
                }
            }
            
            // For Zoho to WooCommerce retry, just create/update product and return
            // Don't sync back to Zoho
            if ($product && $woo_id) {
                // Delete old failed log
                zoho_delete_logs_by_woo_id('item', $woo_id);
                
                // Log success
                $action = isset($is_new_product) && $is_new_product ? 'Created' : 'Updated';
                zoho_add_detailed_log(
                    'item',
                    'product',
                    $woo_id,
                    'items-inventory',
                    $zoho_object_id,
                    json_encode($request_data),
                    json_encode(array('code' => 0, 'message' => $action . ' from Zoho item', 'product_id' => $woo_id))
                );
                
                wp_send_json_success(array('success' => true, 'message' => 'Product ' . strtolower($action) . ' successfully from Zoho item!'));
                return;
            }
        } else {
            // Normal WooCommerce to Zoho retry - product should exist
            if (empty($woo_id)) {
                wp_send_json_error('No WooCommerce ID found in log');
                return;
            }
            
            $product = wc_get_product($woo_id);
            if (!$product) {
                wp_send_json_error('Product not found: ' . $woo_id);
                return;
            }
        }
        
        $sku = $product->get_sku();
        // Determine product_type: service (virtual/downloadable/no stock) or goods (has stock)
        // Zoho valid values for product_type: goods, service
        // Zoho valid values for item_type: inventory, sales, purchases, sales_and_purchases
        $is_service = $product->is_virtual() || $product->is_downloadable() || !$product->get_manage_stock();
        $product_type = $is_service ? 'service' : 'goods';
        
        // Use original product name
        $item_name = $product->get_name();
        
        $item_data = array(
            'name' => $item_name,
            'sku' => $sku,
            'rate' => floatval($product->get_regular_price()) ?: 0,
            'description' => wp_strip_all_tags($product->get_description()),
            'product_type' => $product_type,
        );
        
        // Set item_type based on product_type
        // For goods (with stock), use 'inventory'. For services, use 'sales'
        $item_data['item_type'] = ($product_type === 'goods') ? 'inventory' : 'sales';
        
        // Add stock and inventory settings for goods items (null/empty/"-" = 0)
        if ($product_type === 'goods') {
            $stock = $product->get_stock_quantity();
            $stock_value = (is_numeric($stock) && $stock !== '') ? intval($stock) : 0;
            $item_data['initial_stock'] = max(0, $stock_value); // Never negative
            $item_data['initial_stock_rate'] = floatval($product->get_regular_price()) ?: 0;
            
            // Add Inventory Account ID (Asset Inventory account) - must be long integer
            $inventory_account_id = get_option('zoho_inventory_account_id', '');
            if (!empty($inventory_account_id)) {
                $item_data['inventory_account_id'] = intval($inventory_account_id);
            }
            
            // Add Inventory Valuation Method (FIFO, LIFO, Weighted Average, etc.)
            $inventory_valuation_method = get_option('zoho_inventory_valuation_method', '');
            if (!empty($inventory_valuation_method)) {
                $item_data['inventory_valuation_method'] = $inventory_valuation_method;
            }
        }
        
        // Check if item exists in Zoho by SKU first (SKU is unique identifier)
        // Use function from inventory.php if available
        if (function_exists('zoho_get_item_by_sku')) {
            $zoho_item_id = zoho_get_item_by_sku($sku);
        } else {
            $response = zoho_api_get('items?sku=' . urlencode($sku));
            $zoho_item_id = null;
            if (!is_wp_error($response) && isset($response['items']) && !empty($response['items'])) {
                $zoho_item_id = $response['items'][0]['item_id'];
            }
        }
        
        // Only update if found by SKU (don't search by name - name can be duplicate, SKU cannot)
        // If SKU doesn't match, we'll create new item
        
        if ($zoho_item_id) {
            // Update existing item (found by SKU) - remove fields that can't be changed/updated
            $update_data = $item_data;
            unset($update_data['item_type']); // Can't change item_type on update
            unset($update_data['product_type']); // Can't change product_type on update
            unset($update_data['initial_stock']); // Can only set on create, not update
            unset($update_data['initial_stock_rate']); // Can only set on create, not update
            unset($update_data['inventory_account_id']); // Can only set on create, not update
            unset($update_data['inventory_valuation_method']); // Can only set on create, not update
            $response = zoho_api_put('items/' . $zoho_item_id, $update_data);
            $action = 'updated';
        } else {
            // Create new item with original name
            $response = zoho_api_post('items', $item_data);
            $action = 'created';
            
            // If "Invalid item_type" or "product_type" error (Code 2), retry without these fields
            if (isset($response['code']) && $response['code'] == 2 && 
                (strpos(strtolower($response['message'] ?? ''), 'item_type') !== false || 
                 strpos(strtolower($response['message'] ?? ''), 'product_type') !== false)) {
                $item_data_fallback = $item_data;
                unset($item_data_fallback['item_type']);
                unset($item_data_fallback['product_type']);
                $response = zoho_api_post('items', $item_data_fallback);
                $action = 'created';
            }
        }
        
        // Delete old failed log for this woo_object_id before adding new one
        zoho_delete_logs_by_woo_id('item', $woo_id);
        
        // Log the retry
        $zoho_object_id = isset($response['item']['item_id']) ? $response['item']['item_id'] : ($zoho_item_id ?: '');
        zoho_add_detailed_log('item', 'product', $woo_id, 'items-inventory', $zoho_object_id, json_encode($item_data), json_encode($response));
        
        if (isset($response['code']) && $response['code'] == 0) {
            $result = array('success' => true, 'message' => 'Item ' . $action . ' successfully!');
        } elseif (isset($response['item'])) {
            $result = array('success' => true, 'message' => 'Item ' . $action . ' successfully!');
        } else {
            $error_msg = isset($response['message']) ? $response['message'] : (isset($response['error']) ? $response['error'] : 'Unknown error');
            $result = array('success' => false, 'message' => $error_msg);
        }
        
    } elseif ($type === 'contact') {
        // Retry contact sync
        // Use function from contact.php or helpers.php if available
        if (function_exists('zoho_build_contact_data_from_customer')) {
            $customer = new WC_Customer($woo_id);
            if (!$customer->get_id()) {
                wp_send_json_error('Customer not found: ' . $woo_id);
                return;
            }

            $contact_data = zoho_build_contact_data_from_customer($customer);
            
            // Check if contact exists
            $existing_contact_id = get_user_meta($woo_id, '_zoho_contact_id', true);
            
            if ($existing_contact_id) {
                $response = zoho_api_put('contacts/' . $existing_contact_id, $contact_data);
                $action = 'updated';
            } else {
                $response = zoho_api_post('contacts', $contact_data);
                $action = 'created';
                if (isset($response['contact']['contact_id'])) {
                    update_user_meta($woo_id, '_zoho_contact_id', $response['contact']['contact_id']);
                }
            }
            
            // Delete old logs for this woo_object_id before adding new one
            zoho_delete_logs_by_woo_id('contact', $woo_id);
            
            $zoho_contact_id = '';
            if (isset($response['contact']['contact_id'])) {
                $zoho_contact_id = $response['contact']['contact_id'];
                update_user_meta($woo_id, '_zoho_contact_id', $zoho_contact_id);
            } elseif (!empty($existing_contact_id)) {
                // If update was successful, use the existing ID
                $zoho_contact_id = $existing_contact_id;
                update_user_meta($woo_id, '_zoho_contact_id', $zoho_contact_id);
            }
            zoho_add_detailed_log('contact', 'customer', $woo_id, 'contact', $zoho_contact_id, json_encode($contact_data), json_encode($response));
            
            if (isset($response['code']) && $response['code'] == 0) {
                $result = array('success' => true, 'message' => 'Contact ' . $action . ' successfully!');
            } elseif (isset($response['contact'])) {
                $result = array('success' => true, 'message' => 'Contact ' . $action . ' successfully!');
            } else {
                $error_msg = isset($response['message']) ? $response['message'] : (isset($response['error']) ? $response['error'] : 'Unknown error');
                $result = array('success' => false, 'message' => $error_msg);
            }
        } else {
            wp_send_json_error('Contact sync function not available');
            return;
        }
        
    } elseif ($type === 'invoice') {
        // For invoices, just return a message - too complex to retry individually
        $result = array('success' => false, 'message' => 'Invoice retry not supported. Please use Invoice Sync page to resync.');
    } else {
        $result = array('success' => false, 'message' => 'Unknown sync type: ' . $type);
    }
    
    if ($result['success']) {
        wp_send_json_success($result['message']);
    } else {
        wp_send_json_error($result['message']);
    }
}

/* ======================================================
   AJAX HANDLER: Retry All Failed Syncs
====================================================== */
add_action('wp_ajax_zoho_retry_all_failed', 'zoho_retry_all_failed_handler');
function zoho_retry_all_failed_handler() {
    check_ajax_referer('zoho_logs_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
        return;
    }
    
    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
    
    if (empty($type) || !in_array($type, array('item', 'contact'))) {
        wp_send_json_error('Invalid sync type. Only item and contact retry is supported.');
        return;
    }
    
    // Get all failed logs of this type
    $all_logs = get_option('zoho_detailed_logs', []);
    $failed_logs = array_filter($all_logs, function($log) use ($type) {
        return ($log['type'] ?? '') === $type && !zoho_is_log_successful($log);
    });
    
    if (empty($failed_logs)) {
        wp_send_json_error('No failed logs found to retry.');
        return;
    }
    
    $total = count($failed_logs);
    $success_count = 0;
    $fail_count = 0;
    
    foreach ($failed_logs as $log) {
        $log_id = $log['id'] ?? '';
        $woo_id = $log['woo_object_id'] ?? '';
        
        if (empty($woo_id)) {
            $fail_count++;
            continue;
        }
        
        $retry_success = false;
        
        if ($type === 'item') {
            // Retry item sync
            $product = wc_get_product($woo_id);
            if (!$product) {
                $fail_count++;
                continue;
            }
            
            $sku = $product->get_sku();
            if (empty($sku)) {
                $fail_count++;
                continue;
            }
            
            // Determine product_type: service (virtual/downloadable/no stock) or goods (has stock)
            // Zoho valid values for product_type: goods, service
            // Zoho valid values for item_type: inventory, sales, purchases, sales_and_purchases
            $is_service = $product->is_virtual() || $product->is_downloadable() || !$product->get_manage_stock();
            $product_type = $is_service ? 'service' : 'goods';
            
            // Use original product name
            $item_name = $product->get_name();
            
            $item_data = array(
                'name' => $item_name,
                'sku' => $sku,
                'rate' => floatval($product->get_regular_price()) ?: 0,
                'description' => wp_strip_all_tags($product->get_description()),
                'product_type' => $product_type,
            );
            
            // Set item_type based on product_type
            // For goods (with stock), use 'inventory'. For services, use 'sales'
            $item_data['item_type'] = ($product_type === 'goods') ? 'inventory' : 'sales';
            
            // Add stock and inventory settings for goods items (null/empty/"-" = 0)
            if ($product_type === 'goods') {
                $stock = $product->get_stock_quantity();
                $stock_value = (is_numeric($stock) && $stock !== '') ? intval($stock) : 0;
                $item_data['initial_stock'] = max(0, $stock_value); // Never negative
                $item_data['initial_stock_rate'] = floatval($product->get_regular_price()) ?: 0;
                
                // Add Inventory Account ID (Asset Inventory account) - must be long integer
                $inventory_account_id = get_option('zoho_inventory_account_id', '');
                if (!empty($inventory_account_id)) {
                    $item_data['inventory_account_id'] = intval($inventory_account_id);
                }
                
                // Add Inventory Valuation Method (FIFO, LIFO, Weighted Average, etc.)
                $inventory_valuation_method = get_option('zoho_inventory_valuation_method', '');
                if (!empty($inventory_valuation_method)) {
                    $item_data['inventory_valuation_method'] = $inventory_valuation_method;
                }
            }
            
            // Check if item exists in Zoho by SKU first (SKU is unique identifier)
            if (function_exists('zoho_get_item_by_sku')) {
                $zoho_item_id = zoho_get_item_by_sku($sku);
            } else {
                $response = zoho_api_get('items?sku=' . urlencode($sku));
                $zoho_item_id = null;
                if (!is_wp_error($response) && isset($response['items']) && !empty($response['items'])) {
                    $zoho_item_id = $response['items'][0]['item_id'];
                }
            }
            
            // Only update if found by SKU (don't search by name - name can be duplicate, SKU cannot)
            // If SKU doesn't match, we'll create new item
            
            if ($zoho_item_id) {
                // Update existing item (found by SKU) - remove fields that can't be changed/updated
                $update_data = $item_data;
                unset($update_data['item_type']); // Can't change item_type on update
                unset($update_data['product_type']); // Can't change product_type on update
                unset($update_data['initial_stock']); // Can only set on create, not update
                unset($update_data['initial_stock_rate']); // Can only set on create, not update
                unset($update_data['inventory_account_id']); // Can only set on create, not update
                unset($update_data['inventory_valuation_method']); // Can only set on create, not update
                $response = zoho_api_put('items/' . $zoho_item_id, $update_data);
            } else {
                // Create new item with original name
                $response = zoho_api_post('items', $item_data);
                
                // If "Invalid item_type" or "product_type" error (Code 2), retry without these fields
                if (isset($response['code']) && $response['code'] == 2 && 
                    (strpos(strtolower($response['message'] ?? ''), 'item_type') !== false || 
                     strpos(strtolower($response['message'] ?? ''), 'product_type') !== false)) {
                    $item_data_fallback = $item_data;
                    unset($item_data_fallback['item_type']);
                    unset($item_data_fallback['product_type']);
                    $response = zoho_api_post('items', $item_data_fallback);
                }
            }
            
            // Delete old logs for this woo_object_id before adding new one
            zoho_delete_logs_by_woo_id('item', $woo_id);
            
            $zoho_object_id = isset($response['item']['item_id']) ? $response['item']['item_id'] : ($zoho_item_id ?: '');
            zoho_add_detailed_log('item', 'product', $woo_id, 'items-inventory', $zoho_object_id, json_encode($item_data), json_encode($response));
            
            if ((isset($response['code']) && $response['code'] == 0) || isset($response['item'])) {
                $retry_success = true;
            }
            
        } elseif ($type === 'contact') {
            // Retry contact sync
            if (function_exists('zoho_build_contact_data_from_customer')) {
                $customer = new WC_Customer($woo_id);
                if (!$customer->get_id()) {
                    $fail_count++;
                    continue;
                }
                
                $contact_data = zoho_build_contact_data_from_customer($customer);
                $existing_contact_id = get_user_meta($woo_id, '_zoho_contact_id', true);
                
                if ($existing_contact_id) {
                    $response = zoho_api_put('contacts/' . $existing_contact_id, $contact_data);
                    
                    // If update fails with Code 1002, contact doesn't exist - clear ID and search/create
                    if (isset($response['code']) && $response['code'] == 1002) {
                        // Clear invalid contact_id
                        delete_user_meta($woo_id, '_zoho_contact_id');
                        $existing_contact_id = '';
                        
                        // Try to find contact by email
                        if (!empty($contact_data['email'])) {
                            $search_email = strtolower(trim($contact_data['email']));
                            $search_response = zoho_api_get('contacts?email=' . urlencode($search_email));
                            if (!is_wp_error($search_response) && isset($search_response['contacts']) && !empty($search_response['contacts'])) {
                                foreach ($search_response['contacts'] as $found_contact) {
                                    $found_email = isset($found_contact['email']) ? strtolower(trim($found_contact['email'])) : '';
                                    if ($found_email === $search_email) {
                                        $existing_contact_id = $found_contact['contact_id'];
                                        // Update with correct ID
                                        $response = zoho_api_put('contacts/' . $existing_contact_id, $contact_data);
                                        break;
                                    }
                                }
                            }
                        }
                        
                        // DO NOT search by name - email is unique identifier
                        // If email search didn't find it, create new contact
                        // Same name != same person
                        if (empty($existing_contact_id)) {
                            $response = zoho_api_post('contacts', $contact_data);
                        }
                    }
                } else {
                    // No existing contact ID, try to create
                    $response = zoho_api_post('contacts', $contact_data);
                    
                    // Handle Code 3062 (contact already exists) or Code 1002 (may indicate contact exists)
                    if (isset($response['code']) && ($response['code'] == 3062 || $response['code'] == 1002)) {
                        $contact_found = false;
                        
                        // Try to find existing contact by email first (most reliable)
                        if (!empty($contact_data['email'])) {
                            $search_email = strtolower(trim($contact_data['email']));
                            $search_response = zoho_api_get('contacts?email=' . urlencode($search_email));
                            if (!is_wp_error($search_response) && isset($search_response['contacts']) && !empty($search_response['contacts'])) {
                                // Check if email matches (case-insensitive)
                                foreach ($search_response['contacts'] as $found_contact) {
                                    $found_email = isset($found_contact['email']) ? strtolower(trim($found_contact['email'])) : '';
                                    if ($found_email === $search_email) {
                                        $existing_contact_id = $found_contact['contact_id'];
                                        // Update with correct ID
                                        $response = zoho_api_put('contacts/' . $existing_contact_id, $contact_data);
                                        $contact_found = true;
                                        break;
                                    }
                                }
                                // If no exact email match but contacts found, use first one
                                if (!$contact_found && !empty($search_response['contacts'])) {
                                    $existing_contact_id = $search_response['contacts'][0]['contact_id'];
                                    $response = zoho_api_put('contacts/' . $existing_contact_id, $contact_data);
                                    $contact_found = true;
                                }
                            }
                        }
                        
                        // DO NOT search by name - email is the unique identifier in Zoho
                        // If email search didn't find it, create new contact
                        // Two people can have same name but different emails
                        if (!$contact_found) {
                            $response = zoho_api_post('contacts', $contact_data);
                            if (isset($response['contact']['contact_id'])) {
                                $existing_contact_id = $response['contact']['contact_id'];
                                $contact_found = true;
                                update_user_meta($woo_id, '_zoho_contact_id', $existing_contact_id);
                            }
                        }
                    }
                    
                }
                
                // Delete old logs for this woo_object_id before adding new one
                zoho_delete_logs_by_woo_id('contact', $woo_id);
                
                $zoho_contact_id = '';
                if (isset($response['contact']['contact_id'])) {
                    $zoho_contact_id = $response['contact']['contact_id'];
                    update_user_meta($woo_id, '_zoho_contact_id', $zoho_contact_id);
                } elseif (!empty($existing_contact_id)) {
                    // If update was successful, use the existing ID
                    $zoho_contact_id = $existing_contact_id;
                    update_user_meta($woo_id, '_zoho_contact_id', $zoho_contact_id);
                }
                zoho_add_detailed_log('contact', 'customer', $woo_id, 'contact', $zoho_contact_id, json_encode($contact_data), json_encode($response));
                
                if ((isset($response['code']) && $response['code'] == 0) || isset($response['contact'])) {
                    $retry_success = true;
                }
            } else {
                $fail_count++;
                continue;
            }
        }
        
        if ($retry_success) {
            $success_count++;
        } else {
            $fail_count++;
        }
    }
    
    // Old logs are already deleted by zoho_delete_logs_by_woo_id() during retry
    
    wp_send_json_success(array(
        'total' => $total,
        'success' => $success_count,
        'failed' => $fail_count
    ));
}

/* ======================================================
   LOGS PAGE - Detailed Sync Logs
====================================================== */
function zoho_logs_page() {
    // Get current tab
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'items';
    $nonce = wp_create_nonce('zoho_logs_nonce');
    
    echo '<h1>Sync Logs</h1>';
    echo '<p style="font-size: 14px; color: #666;">View detailed sync logs for all sync operations. <strong>Showing logs from the past 30 days.</strong></p>';
    
    // Clear All Logs button
    echo '<div style="margin-bottom: 15px;">';
    echo '<button type="button" class="button" id="clear-current-logs" style="color: #d63638; border-color: #d63638;">Clear '.ucfirst($current_tab).' Logs</button> ';
    echo '<button type="button" class="button" id="clear-all-logs" style="color: #d63638; border-color: #d63638;">Clear All Logs</button>';
    echo '</div>';
    
    // Tabs
    echo '<nav class="nav-tab-wrapper">';
    echo '<a href="'.admin_url('admin.php?page=zoho-logs&tab=items').'" class="nav-tab '.($current_tab == 'items' ? 'nav-tab-active' : '').'">Items</a>';
    echo '<a href="'.admin_url('admin.php?page=zoho-logs&tab=invoices').'" class="nav-tab '.($current_tab == 'invoices' ? 'nav-tab-active' : '').'">Invoices</a>';
    echo '<a href="'.admin_url('admin.php?page=zoho-logs&tab=contacts').'" class="nav-tab '.($current_tab == 'contacts' ? 'nav-tab-active' : '').'">Contacts</a>';
    echo '</nav>';
    
    echo '<div class="tab-content" style="margin-top: 20px;">';
    
    if ($current_tab == 'items') {
        zoho_logs_items_tab();
    } elseif ($current_tab == 'invoices') {
        zoho_logs_invoices_tab();
    } elseif ($current_tab == 'contacts') {
        zoho_logs_contacts_tab();
    }
    
    echo '</div>';
    
    // JavaScript for Clear and Retry
    ?>
    <script>
    jQuery(document).ready(function($) {
        var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
        var nonce = '<?php echo $nonce; ?>';
        var currentTab = '<?php echo $current_tab; ?>';
        var typeMap = {'items': 'item', 'invoices': 'invoice', 'contacts': 'contact'};
        
        // Clear current tab logs
        $('#clear-current-logs').on('click', function() {
            if (!confirm('Are you sure you want to clear all ' + currentTab + ' logs?')) return;
            
            var $btn = $(this);
            $btn.prop('disabled', true).text('Clearing...');
            
            $.post(ajaxUrl, {
                action: 'zoho_clear_logs',
                nonce: nonce,
                type: typeMap[currentTab]
            }, function(response) {
                if (response.success) {
                    alert('Logs cleared successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                    $btn.prop('disabled', false).text('Clear ' + currentTab.charAt(0).toUpperCase() + currentTab.slice(1) + ' Logs');
                }
            });
        });
        
        // Clear all logs
        $('#clear-all-logs').on('click', function() {
            if (!confirm('Are you sure you want to clear ALL logs (Items, Invoices, Contacts)?')) return;
            
            var $btn = $(this);
            $btn.prop('disabled', true).text('Clearing...');
            
            $.post(ajaxUrl, {
                action: 'zoho_clear_logs',
                nonce: nonce,
                type: 'all'
            }, function(response) {
                if (response.success) {
                    alert('All logs cleared successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                    $btn.prop('disabled', false).text('Clear All Logs');
                }
            });
        });
        
        // Retry single failed sync
        $(document).on('click', '.retry-sync-btn', function() {
            var $btn = $(this);
            var logId = $btn.data('log-id');
            
            $btn.prop('disabled', true).text('Retrying...');
            
            $.post(ajaxUrl, {
                action: 'zoho_retry_sync',
                nonce: nonce,
                log_id: logId
            }, function(response) {
                if (response.success) {
                    alert('Success: ' + response.data);
                    location.reload();
                } else {
                    alert('Failed: ' + response.data);
                    $btn.prop('disabled', false).text('Retry');
                }
            });
        });
        
        // Retry All failed syncs
        $(document).on('click', '.retry-all-btn', function() {
            var $btn = $(this);
            var syncType = $btn.data('type');
            var $progress = $('#retry-all-progress');
            
            if (!confirm('Are you sure you want to retry all failed ' + syncType + ' syncs?')) return;
            
            $btn.prop('disabled', true).text('Retrying All...');
            $progress.show().text('Starting...');
            
            $.post(ajaxUrl, {
                action: 'zoho_retry_all_failed',
                nonce: nonce,
                type: syncType
            }, function(response) {
                if (response.success) {
                    var data = response.data;
                    alert('Retry All Complete!\n\nTotal: ' + data.total + '\nSuccess: ' + data.success + '\nStill Failed: ' + data.failed);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                    $btn.prop('disabled', false).text('↻ Retry All Failed');
                    $progress.hide();
                }
            }).fail(function() {
                alert('Request failed. Please try again.');
                $btn.prop('disabled', false).text('↻ Retry All Failed');
                $progress.hide();
            });
        });
    });
    </script>
    <?php
}

/* ======================================================
   LOGS - ITEMS TAB
====================================================== */
function zoho_logs_items_tab() {
    zoho_render_logs_tab('item', 'items', 'product', 'items-inventory');
}

/* ======================================================
   LOGS - INVOICES TAB
====================================================== */
function zoho_logs_invoices_tab() {
    zoho_render_logs_tab('invoice', 'invoices', 'order', 'invoice');
}

/* ======================================================
   LOGS - CONTACTS TAB
====================================================== */
function zoho_logs_contacts_tab() {
    zoho_render_logs_tab('contact', 'contacts', 'customer', 'contact');
}

/* ======================================================
   LOGS - RENDER TAB WITH PAGINATION
====================================================== */
function zoho_render_logs_tab($type, $tab_name, $woo_object_default, $zoho_object_default) {
    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
    
    // View single log detail
    if (isset($_GET['view_log'])) {
        $logs = get_option('zoho_detailed_logs', []);
        $log_id = sanitize_text_field($_GET['view_log']);
        
        foreach ($logs as $log) {
            if (isset($log['id']) && $log['id'] === $log_id) {
                $is_success = zoho_is_log_successful($log);
                $back_url = admin_url('admin.php?page=zoho-logs&tab='.$tab_name.'&status='.$status_filter);
                
                echo '<div style="margin-bottom: 20px;">';
                echo '<a href="'.$back_url.'" class="button">&larr; Back to Logs</a>';
                echo '</div>';
                
                // Status badge
                $status_badge = $is_success 
                    ? '<span style="background: #00a32a; color: #fff; padding: 3px 10px; border-radius: 3px; font-size: 12px;">✓ Successful</span>'
                    : '<span style="background: #d63638; color: #fff; padding: 3px 10px; border-radius: 3px; font-size: 12px;">✕ Failed</span>';
                
                echo '<div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 4px;">';
                echo '<h2 style="margin-top: 0;">Log Details '.$status_badge.'</h2>';
                echo '<table class="form-table">';
                echo '<tr><th>Time</th><td>'.esc_html($log['time']).'</td></tr>';
                echo '<tr><th>Status</th><td>'.($is_success ? '<span style="color: #00a32a;">Successful</span>' : '<span style="color: #d63638;">Failed</span>').'</td></tr>';
                echo '<tr><th>Woo Object</th><td>'.esc_html($log['woo_object'] ?? $woo_object_default).'</td></tr>';
                echo '<tr><th>Woo Object Id</th><td>'.esc_html($log['woo_object_id'] ?? '-').'</td></tr>';
                echo '<tr><th>Zoho Inventory Object</th><td>'.esc_html($log['zoho_object'] ?? $zoho_object_default).'</td></tr>';
                echo '<tr><th>Zoho Inventory Object Id</th><td>'.esc_html($log['zoho_object_id'] ?? '-').'</td></tr>';
                
                // Show error message prominently for failed logs
                if (!$is_success) {
                    echo '<tr><th style="color: #d63638;">Error Message</th><td style="color: #d63638; font-weight: bold;">'.esc_html(zoho_get_error_message($log)).'</td></tr>';
                }
                
                echo '</table>';
                echo '</div>';
                
                // Request
                echo '<div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 4px;">';
                echo '<h3 style="margin-top: 0;">Request</h3>';
                echo '<pre style="background: #f5f5f5; padding: 15px; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; max-height: 400px;">'.esc_html(json_encode(json_decode($log['request'] ?? '{}'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>';
                echo '</div>';
                
                // Response
                $response_bg = $is_success ? '#f5f5f5' : '#ffe6e6';
                echo '<div style="background: #fff; border: 1px solid '.($is_success ? '#ddd' : '#d63638').'; padding: 20px; margin-bottom: 20px; border-radius: 4px;">';
                echo '<h3 style="margin-top: 0; '.($is_success ? '' : 'color: #d63638;').'">Response</h3>';
                echo '<pre style="background: '.$response_bg.'; padding: 15px; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; max-height: 400px;">'.esc_html(json_encode(json_decode($log['response'] ?? '{}'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>';
                echo '</div>';
                
                return;
            }
        }
        echo '<p>Log not found.</p>';
        return;
    }
    
    // Get all logs and filter by type
    $all_logs = get_option('zoho_detailed_logs', []);
    $filtered_logs = array_values(array_filter($all_logs, function($log) use ($type) {
        return ($log['type'] ?? '') === $type;
    }));
    
    // Reverse for newest first
    $filtered_logs = array_reverse($filtered_logs);
    
    // Separate successful and failed logs
    $successful_logs = array_values(array_filter($filtered_logs, function($log) {
        return zoho_is_log_successful($log);
    }));
    $failed_logs = array_values(array_filter($filtered_logs, function($log) {
        return !zoho_is_log_successful($log);
    }));
    
    // Sub-tabs for Successful/Failed
    echo '<div style="margin-bottom: 20px; border-bottom: 1px solid #ccc; padding-bottom: 10px;">';
    echo '<a href="'.admin_url('admin.php?page=zoho-logs&tab='.$tab_name.'&status=all').'" class="button '.($status_filter == 'all' ? 'button-primary' : '').'" style="margin-right: 5px;">All ('.count($filtered_logs).')</a>';
    echo '<a href="'.admin_url('admin.php?page=zoho-logs&tab='.$tab_name.'&status=successful').'" class="button '.($status_filter == 'successful' ? 'button-primary' : '').'" style="margin-right: 5px; '.($status_filter != 'successful' ? 'color: #00a32a;' : '').'">✓ Successful ('.count($successful_logs).')</a>';
    echo '<a href="'.admin_url('admin.php?page=zoho-logs&tab='.$tab_name.'&status=failed').'" class="button '.($status_filter == 'failed' ? 'button-primary' : '').'" style="margin-right: 5px; '.($status_filter != 'failed' ? 'color: #d63638;' : '').'">✕ Failed ('.count($failed_logs).')</a>';
    
    // Retry All button for failed logs (only for items and contacts)
    if ($status_filter == 'failed' && count($failed_logs) > 0 && ($type == 'item' || $type == 'contact')) {
        echo '<button type="button" class="button retry-all-btn" data-type="'.$type.'" style="margin-left: 20px; background: #00a32a; color: #fff; border-color: #00a32a;">↻ Retry All Failed ('.count($failed_logs).')</button>';
        echo '<span id="retry-all-progress" style="margin-left: 10px; display: none;"></span>';
    }
    echo '</div>';
    
    // Filter logs based on status
    if ($status_filter == 'successful') {
        $display_logs = $successful_logs;
    } elseif ($status_filter == 'failed') {
        $display_logs = $failed_logs;
    } else {
        $display_logs = $filtered_logs;
    }
    
    $total_logs = count($display_logs);
    $total_pages = ceil($total_logs / $per_page);
    
    if ($total_logs == 0) {
        if ($status_filter == 'failed') {
            echo '<p style="color: #00a32a;">✓ No failed logs! All syncs were successful.</p>';
        } elseif ($status_filter == 'successful') {
            echo '<p>No successful '.str_replace('_', ' ', $type).' sync logs yet.</p>';
        } else {
            echo '<p>No '.str_replace('_', ' ', $type).' sync logs yet.</p>';
        }
        return;
    }
    
    // Get logs for current page
    $offset = ($current_page - 1) * $per_page;
    $page_logs = array_slice($display_logs, $offset, $per_page);
    
    // Show count
    echo '<p style="margin-bottom: 15px;">Showing '.($offset + 1).' - '.min($offset + $per_page, $total_logs).' of '.$total_logs.' logs</p>';
    
    // Different table header for failed logs (includes Error column and Retry button)
    if ($status_filter == 'failed') {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>
            <th style="width: 100px;">Actions</th>
            <th>Woo Object Id</th>
            <th>Zoho Object Id</th>
            <th style="width: 30%;">Error Message</th>
            <th>Time</th>
        </tr></thead><tbody>';
        
        foreach ($page_logs as $log) {
            $error_msg = zoho_get_error_message($log);
            $log_type = $log['type'] ?? '';
            $can_retry = ($log_type === 'item' || $log_type === 'contact'); // Invoice retry not supported
            
            echo '<tr style="background: #fff5f5;">';
            echo '<td>';
            echo '<a href="'.admin_url('admin.php?page=zoho-logs&tab='.$tab_name.'&status='.$status_filter.'&view_log='.esc_attr($log['id'])).'" class="button button-small">View</a> ';
            if ($can_retry) {
                echo '<button class="button button-small retry-sync-btn" data-log-id="'.esc_attr($log['id']).'" style="color: #00a32a;">Retry</button>';
            }
            echo '</td>';
            echo '<td>'.esc_html($log['woo_object_id'] ?? '-').'</td>';
            echo '<td>'.esc_html($log['zoho_object_id'] ?? '-').'</td>';
            echo '<td style="color: #d63638;">'.esc_html($error_msg).'</td>';
            echo '<td>'.esc_html($log['time'] ?? '-').'</td>';
            echo '</tr>';
        }
    } else {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>
            <th style="width: 100px;">Actions</th>
            <th style="width: 60px;">Status</th>
            <th>Woo Object</th>
            <th>Woo Object Id</th>
            <th>Zoho Object</th>
            <th>Zoho Object Id</th>
            <th>Time</th>
        </tr></thead><tbody>';
        
        foreach ($page_logs as $log) {
            $is_success = zoho_is_log_successful($log);
            $zoho_link = $log['zoho_object_id'] ?? '-';
            if ($type == 'item' && !empty($log['zoho_object_id'])) {
                $zoho_link = '<a href="https://inventory.zoho.com/app#/inventory/items/'.esc_attr($log['zoho_object_id']).'" target="_blank">'.esc_html($log['zoho_object_id']).'</a>';
            } else {
                $zoho_link = esc_html($log['zoho_object_id'] ?? '-');
            }
            
            $row_style = $is_success ? '' : 'background: #fff5f5;';
            $status_icon = $is_success 
                ? '<span style="color: #00a32a; font-weight: bold;">✓</span>' 
                : '<span style="color: #d63638; font-weight: bold;">✕</span>';
            
            $log_type = $log['type'] ?? '';
            $can_retry = !$is_success && ($log_type === 'item' || $log_type === 'contact');
            
            echo '<tr style="'.$row_style.'">';
            echo '<td>';
            echo '<a href="'.admin_url('admin.php?page=zoho-logs&tab='.$tab_name.'&status='.$status_filter.'&view_log='.esc_attr($log['id'])).'" class="button button-small">View</a>';
            if ($can_retry) {
                echo ' <button class="button button-small retry-sync-btn" data-log-id="'.esc_attr($log['id']).'" style="color: #00a32a;">Retry</button>';
            }
            echo '</td>';
            echo '<td style="text-align: center;">'.$status_icon.'</td>';
            echo '<td>'.esc_html($log['woo_object'] ?? $woo_object_default).'</td>';
            echo '<td>'.esc_html($log['woo_object_id'] ?? '-').'</td>';
            echo '<td>'.esc_html($log['zoho_object'] ?? $zoho_object_default).'</td>';
            echo '<td>'.$zoho_link.'</td>';
            echo '<td>'.esc_html($log['time'] ?? '-').'</td>';
            echo '</tr>';
        }
    }
    
    echo '</tbody></table>';
    
    // Pagination
    if ($total_pages > 1) {
        echo '<div class="tablenav bottom"><div class="tablenav-pages">';
        echo '<span class="displaying-num">'.$total_logs.' items</span>';
        echo '<span class="pagination-links">';
        
        // First page
        if ($current_page > 1) {
            echo '<a class="first-page button" href="'.admin_url('admin.php?page=zoho-logs&tab='.$tab_name.'&status='.$status_filter.'&paged=1').'">«</a> ';
            echo '<a class="prev-page button" href="'.admin_url('admin.php?page=zoho-logs&tab='.$tab_name.'&status='.$status_filter.'&paged='.($current_page - 1)).'">‹</a> ';
        } else {
            echo '<span class="tablenav-pages-navspan button disabled">«</span> ';
            echo '<span class="tablenav-pages-navspan button disabled">‹</span> ';
        }
        
        echo '<span class="paging-input">';
        echo '<span class="tablenav-paging-text"> '.$current_page.' of <span class="total-pages">'.$total_pages.'</span></span>';
        echo '</span>';
        
        // Next/Last page
        if ($current_page < $total_pages) {
            echo ' <a class="next-page button" href="'.admin_url('admin.php?page=zoho-logs&tab='.$tab_name.'&status='.$status_filter.'&paged='.($current_page + 1)).'">›</a>';
            echo ' <a class="last-page button" href="'.admin_url('admin.php?page=zoho-logs&tab='.$tab_name.'&status='.$status_filter.'&paged='.$total_pages).'">»</a>';
        } else {
            echo ' <span class="tablenav-pages-navspan button disabled">›</span>';
            echo ' <span class="tablenav-pages-navspan button disabled">»</span>';
        }
        
        echo '</span></div></div>';
    }
}

