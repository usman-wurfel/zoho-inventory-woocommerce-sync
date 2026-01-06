<?php
/**
 * Invoice Sync Page and Functions
 */

if (!defined('ABSPATH')) exit;

/* ======================================================
   AJAX HANDLER FOR INVOICE SYNC
====================================================== */
add_action('wp_ajax_zoho_sync_invoices_ajax', 'zoho_sync_invoices_ajax_handler');
function zoho_sync_invoices_ajax_handler() {
    check_ajax_referer('zoho_sync_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
        return;
    }
    
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $total = isset($_POST['total']) ? intval($_POST['total']) : 0;
    $synced = isset($_POST['synced']) ? intval($_POST['synced']) : 0;
    $created = isset($_POST['created']) ? intval($_POST['created']) : 0;
    $updated = isset($_POST['updated']) ? intval($_POST['updated']) : 0;
    $direction = isset($_POST['direction']) ? sanitize_text_field($_POST['direction']) : 'woo_to_zoho';
    $from_date = isset($_POST['from_date']) ? sanitize_text_field($_POST['from_date']) : '';
    $to_date = isset($_POST['to_date']) ? sanitize_text_field($_POST['to_date']) : '';
    
    if ($direction === 'woo_to_zoho') {
        zoho_sync_orders_to_invoices($offset, $total, $synced, $created, $updated, $from_date, $to_date);
    } else {
        zoho_sync_invoices_to_orders($offset, $total, $synced, $created, $updated, $from_date, $to_date);
    }
}

/* ======================================================
   WOO ORDERS TO ZOHO INVOICES
====================================================== */


function zoho_sync_orders_to_invoices($offset, $total, $synced, $created, $updated, $from_date, $to_date) {
    
    // Add rate limiting helper
    $last_api_call = get_transient('zoho_last_api_call_time');
    if ($last_api_call) {
        $time_since_last_call = microtime(true) - $last_api_call;
        if ($time_since_last_call < 0.6) {
            usleep((0.6 - $time_since_last_call) * 1000000);
        }
    }
    set_transient('zoho_last_api_call_time', microtime(true), 60);
    
    // First call - initialize sync
    if ($offset == 0 && $total == 0) {
        delete_transient('zoho_invoice_sync_orders');
        delete_transient('zoho_invoice_sync_stats');
        delete_transient('zoho_invoice_sync_contacts_map');
        delete_transient('zoho_invoice_sync_items_map');
        
        // Fetch orders
        $args = array(
            'status' => array('completed', 'processing', 'pending', 'on-hold'),
            'limit' => -1,
            'return' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC'
        );
        
        if (!empty($from_date)) {
            $args['date_created'] = '>=' . $from_date . ' 00:00:00';
        }
        if (!empty($to_date)) {
            if (!isset($args['date_created'])) {
                $args['date_created'] = '';
            } else {
                $args['date_created'] .= '...';
            }
            $args['date_created'] .= '<=' . $to_date . ' 23:59:59';
        }
        
        $order_ids = wc_get_orders($args);
        $total = count($order_ids);
        
        if ($total == 0) {
            wp_send_json_error('No orders found in WooCommerce');
            return;
        }
        
        // OPTIMIZED: Fetch contacts with LIMIT (max 5 pages = 1000 contacts)
        $contacts_map = array();
        $max_pages = 5;
        $page = 1;
        $per_page = 200;
        
        do {
            $contacts_response = zoho_api_get('contacts?page=' . $page . '&per_page=' . $per_page);
            
            usleep(600000); // 600ms delay
            
            if (!is_wp_error($contacts_response) && isset($contacts_response['contacts'])) {
                foreach ($contacts_response['contacts'] as $contact) {
                    if (!empty($contact['email'])) {
                        $email_key = strtolower(trim($contact['email']));
                        $contacts_map[$email_key] = $contact['contact_id'];
                    }
                }
            }
            
            $has_more = !is_wp_error($contacts_response) 
                && isset($contacts_response['page_context']['has_more_page']) 
                && $contacts_response['page_context']['has_more_page'];
            
            $page++;
        } while ($has_more && $page <= $max_pages);
        
        // OPTIMIZED: Fetch items with LIMIT (max 5 pages = 1000 items)
        $items_map = array();
        $items_sku_map = array();
        $items_name_map = array();
        $max_pages = 5;
        $page = 1;
        
        do {
            $items_response = zoho_api_get('items?page=' . $page . '&per_page=' . $per_page);
            
            usleep(600000); // 600ms delay
            
            if (!is_wp_error($items_response) && isset($items_response['items'])) {
                foreach ($items_response['items'] as $item) {
                    if (!empty($item['item_id'])) {
                        $item_id = $item['item_id'];
                        $items_map[$item_id] = $item;
                        
                        if (!empty($item['sku'])) {
                            $items_sku_map[strtolower(trim($item['sku']))] = $item_id;
                        }
                        if (!empty($item['name'])) {
                            $items_name_map[strtolower(trim($item['name']))] = $item_id;
                        }
                    }
                }
            }
            
            $has_more = !is_wp_error($items_response) 
                && isset($items_response['page_context']['has_more_page']) 
                && $items_response['page_context']['has_more_page'];
            
            $page++;
        } while ($has_more && $page <= $max_pages);
        
        // Store in transients
        set_transient('zoho_invoice_sync_orders', $order_ids, 600);
        set_transient('zoho_invoice_sync_stats', array(
            'synced' => 0, 
            'created' => 0, 
            'updated' => 0, 
            'failed' => array()
        ), 600);
        set_transient('zoho_invoice_sync_contacts_map', $contacts_map, 600);
        set_transient('zoho_invoice_sync_items_map', $items_map, 600);
        set_transient('zoho_invoice_sync_items_sku_map', $items_sku_map, 600);
        set_transient('zoho_invoice_sync_items_name_map', $items_name_map, 600);
        
    } else {
        $order_ids = get_transient('zoho_invoice_sync_orders');
        if (!$order_ids) {
            wp_send_json_error('Sync session expired. Please try again.');
            return;
        }
        $total = count($order_ids);
        
        $contacts_map = get_transient('zoho_invoice_sync_contacts_map') ?: array();
        $items_map = get_transient('zoho_invoice_sync_items_map') ?: array();
        $items_sku_map = get_transient('zoho_invoice_sync_items_sku_map') ?: array();
        $items_name_map = get_transient('zoho_invoice_sync_items_name_map') ?: array();
    }
    
    // Process 5 orders per batch
    $batch_size = 5;
    $batch_ids = array_slice($order_ids, $offset, $batch_size);
    $batch_created = 0;
    $batch_updated = 0;
    $batch_failed = array();
    
    foreach ($batch_ids as $order_id) {
        usleep(600000); // 600ms delay
        
        $order = wc_get_order($order_id);
        if (!$order) {
            $batch_failed[] = array('id' => $order_id, 'reason' => 'Order not found');
            
            // LOG FAILED ORDER
            zoho_add_detailed_log(
                'invoice',
                'order',
                $order_id,
                'invoice',
                '',
                json_encode(array('order_id' => $order_id)),
                json_encode(array('error' => 'Order not found', 'code' => 'ORDER_NOT_FOUND'))
            );
            continue;
        }
        
        // Check if already paid in Zoho
        $existing_invoice_id = $order->get_meta('_zoho_invoice_id', true);
        $order_status = $order->get_status();
        
        if ($order_status === 'completed' && !empty($existing_invoice_id)) {
            $invoice_check = zoho_api_get('invoices/' . $existing_invoice_id);
            usleep(600000);
            
            if (!is_wp_error($invoice_check) && isset($invoice_check['invoice'])) {
                $invoice_status = strtolower($invoice_check['invoice']['status'] ?? '');
                $invoice_balance = floatval($invoice_check['invoice']['balance'] ?? 0);
                
                if ($invoice_status === 'paid' || $invoice_balance <= 0) {
                    continue; // Skip paid invoices
                }
            } elseif (isset($invoice_check['code']) && $invoice_check['code'] == 1002) {
                $order->delete_meta_data('_zoho_invoice_id');
                $order->save();
                $existing_invoice_id = '';
            }
        }
        
        // Get/Create customer - ONLY EMAIL VERIFICATION (no name matching)
        $customer_email_raw = $order->get_billing_email();
        $customer_email = strtolower(trim($customer_email_raw));
        $zoho_customer_id = null;
        
        // Step 1: Check in bulk contacts map
        if (!empty($customer_email) && isset($contacts_map[$customer_email])) {
            $zoho_customer_id = $contacts_map[$customer_email];
        }
        
        // Step 2: Search by email in Zoho (with exact match verification)
        if (!$zoho_customer_id && !empty($customer_email)) {
            $contact_search = zoho_api_get('contacts?email=' . urlencode($customer_email_raw));
            usleep(600000);
            
            if (!is_wp_error($contact_search) && isset($contact_search['contacts']) && !empty($contact_search['contacts'])) {
                // ONLY verify exact email match (ignore name)
                foreach ($contact_search['contacts'] as $contact) {
                    $found_email = isset($contact['email']) ? strtolower(trim($contact['email'])) : '';
                    if ($found_email === $customer_email) {
                        $zoho_customer_id = $contact['contact_id'];
                        $contacts_map[$customer_email] = $zoho_customer_id;
                        set_transient('zoho_invoice_sync_contacts_map', $contacts_map, 600);
                        break;
                    }
                }
            }
        }
        
        // Step 3: Try to create new contact if not found by email
        if (!$zoho_customer_id) {
            // Create contact
            $contact_data = array(
                'contact_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?: $customer_email_raw,
                'contact_type' => 'customer',
                'customer_sub_type' => 'individual',
                'email' => $customer_email_raw,
            );
            
            if (!empty($order->get_billing_first_name())) {
                $contact_data['first_name'] = $order->get_billing_first_name();
            }
            if (!empty($order->get_billing_last_name())) {
                $contact_data['last_name'] = $order->get_billing_last_name();
            }
            
            $contact_create = zoho_api_post('contacts', $contact_data);
            usleep(600000);
            
            if (!is_wp_error($contact_create) && isset($contact_create['contact']['contact_id'])) {
                $zoho_customer_id = $contact_create['contact']['contact_id'];
                $contacts_map[$customer_email] = $zoho_customer_id;
                set_transient('zoho_invoice_sync_contacts_map', $contacts_map, 600);
            } elseif (isset($contact_create['code']) && $contact_create['code'] == 3062) {
                // Code 3062: Contact already exists - Search by EMAIL ONLY
                $retry_search = zoho_api_get('contacts?email=' . urlencode($customer_email_raw));
                usleep(600000);
                
                if (!is_wp_error($retry_search) && isset($retry_search['contacts']) && !empty($retry_search['contacts'])) {
                    foreach ($retry_search['contacts'] as $contact) {
                        $found_email = isset($contact['email']) ? strtolower(trim($contact['email'])) : '';
                        if ($found_email === $customer_email) {
                            $zoho_customer_id = $contact['contact_id'];
                            $contacts_map[$customer_email] = $zoho_customer_id;
                            set_transient('zoho_invoice_sync_contacts_map', $contacts_map, 600);
                            break;
                        }
                    }
                }
                
                // If still not found, try without encoding
                if (!$zoho_customer_id) {
                    $retry_search2 = zoho_api_get('contacts?email=' . $customer_email_raw);
                    usleep(600000);
                    
                    if (!is_wp_error($retry_search2) && isset($retry_search2['contacts']) && !empty($retry_search2['contacts'])) {
                        foreach ($retry_search2['contacts'] as $contact) {
                            $found_email = isset($contact['email']) ? strtolower(trim($contact['email'])) : '';
                            if ($found_email === $customer_email) {
                                $zoho_customer_id = $contact['contact_id'];
                                $contacts_map[$customer_email] = $zoho_customer_id;
                                set_transient('zoho_invoice_sync_contacts_map', $contacts_map, 600);
                                break;
                            }
                        }
                    }
                }
            } else {
                // Other errors - try one more email search
                $final_retry = zoho_api_get('contacts?email=' . urlencode($customer_email_raw));
                usleep(600000);
                
                if (!is_wp_error($final_retry) && isset($final_retry['contacts']) && !empty($final_retry['contacts'])) {
                    foreach ($final_retry['contacts'] as $contact) {
                        $found_email = isset($contact['email']) ? strtolower(trim($contact['email'])) : '';
                        if ($found_email === $customer_email) {
                            $zoho_customer_id = $contact['contact_id'];
                            $contacts_map[$customer_email] = $zoho_customer_id;
                            set_transient('zoho_invoice_sync_contacts_map', $contacts_map, 600);
                            break;
                        }
                    }
                }
            }
        }
        
        // Final fallback attempts - ONLY EMAIL-BASED SEARCHES
        if (!$zoho_customer_id) {
            // LAST ATTEMPT 1: Try direct search by email (without urlencode)
            $final_search = zoho_api_get('contacts?email=' . $customer_email_raw);
            usleep(600000);
            
            if (!is_wp_error($final_search) && isset($final_search['contacts']) && !empty($final_search['contacts'])) {
                // Verify EXACT email match only
                foreach ($final_search['contacts'] as $contact) {
                    $found_email = isset($contact['email']) ? strtolower(trim($contact['email'])) : '';
                    if ($found_email === $customer_email) {
                        $zoho_customer_id = $contact['contact_id'];
                        $contacts_map[$customer_email] = $zoho_customer_id;
                        set_transient('zoho_invoice_sync_contacts_map', $contacts_map, 600);
                        break;
                    }
                }
            }
            
            // LAST ATTEMPT 2: Search with lowercase email
            if (!$zoho_customer_id && $customer_email !== $customer_email_raw) {
                $lowercase_search = zoho_api_get('contacts?email=' . urlencode($customer_email));
                usleep(600000);
                
                if (!is_wp_error($lowercase_search) && isset($lowercase_search['contacts']) && !empty($lowercase_search['contacts'])) {
                    foreach ($lowercase_search['contacts'] as $contact) {
                        $found_email = isset($contact['email']) ? strtolower(trim($contact['email'])) : '';
                        if ($found_email === $customer_email) {
                            $zoho_customer_id = $contact['contact_id'];
                            $contacts_map[$customer_email] = $zoho_customer_id;
                            set_transient('zoho_invoice_sync_contacts_map', $contacts_map, 600);
                            break;
                        }
                    }
                }
            }
        }
        
        if (!$zoho_customer_id) {
            $error_reason = 'Failed to create/find customer: ' . $customer_email_raw;
            $batch_failed[] = array('id' => $order_id, 'reason' => $error_reason);
            
            // LOG FAILED ORDER - Customer creation failed
            zoho_add_detailed_log(
                'invoice',
                'order',
                $order_id,
                'invoice',
                '',
                json_encode(array(
                    'order_id' => $order_id,
                    'customer_email' => $customer_email_raw,
                    'contact_data' => $contact_data ?? array()
                )),
                json_encode(array(
                    'error' => $error_reason,
                    'code' => 'CUSTOMER_CREATE_FAILED',
                    'api_response' => $contact_create ?? 'No response'
                ))
            );
            continue;
        }
        
        // Build line items
        $line_items = array();
        $skipped_items = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                $skipped_items[] = 'Product not found (Item ID: ' . $item->get_id() . ')';
                continue;
            }
            
            $product_id = $product->get_id();
            $sku = $product->get_sku();
            $product_name = $product->get_name();
            
            // Try to find Zoho item
            $zoho_item_id = get_post_meta($product_id, '_zoho_item_id', true);
            
            if ($zoho_item_id && isset($items_map[$zoho_item_id])) {
                $zoho_item = $items_map[$zoho_item_id];
            } else {
                $zoho_item_id = null;
                
                if (!empty($sku) && isset($items_sku_map[strtolower(trim($sku))])) {
                    $zoho_item_id = $items_sku_map[strtolower(trim($sku))];
                } elseif (!empty($product_name) && isset($items_name_map[strtolower(trim($product_name))])) {
                    $zoho_item_id = $items_name_map[strtolower(trim($product_name))];
                }
                
                if ($zoho_item_id && isset($items_map[$zoho_item_id])) {
                    update_post_meta($product_id, '_zoho_item_id', $zoho_item_id);
                    $zoho_item = $items_map[$zoho_item_id];
                } else {
                    $skipped_items[] = $product_name . ' (SKU: ' . ($sku ?: 'N/A') . ', Product ID: ' . $product_id . ')';
                    continue;
                }
            }
            
            $quantity = absint($item->get_quantity());
            $item_total = floatval($item->get_total());
            $rate = $quantity > 0 ? floatval($item_total / $quantity) : 0.01;
            
            $line_items[] = array(
                'item_id' => $zoho_item['item_id'],
                'quantity' => $quantity,
                'rate' => round(max($rate, 0.01), 2),
            );
        }
        
        if (empty($line_items)) {
            $error_reason = 'No valid line items (products not found in Zoho)';
            $batch_failed[] = array(
                'id' => $order_id, 
                'reason' => $error_reason . ' | Skipped: ' . implode(', ', $skipped_items)
            );
            
            // LOG FAILED ORDER - No line items
            zoho_add_detailed_log(
                'invoice',
                'order',
                $order_id,
                'invoice',
                '',
                json_encode(array(
                    'order_id' => $order_id,
                    'customer_id' => $zoho_customer_id,
                    'order_items' => array_map(function($item) {
                        $product = $item->get_product();
                        return array(
                            'name' => $product ? $product->get_name() : 'N/A',
                            'sku' => $product ? $product->get_sku() : 'N/A',
                            'product_id' => $product ? $product->get_id() : 'N/A'
                        );
                    }, $order->get_items())
                )),
                json_encode(array(
                    'error' => $error_reason,
                    'code' => 'NO_LINE_ITEMS',
                    'skipped_items' => $skipped_items,
                    'total_order_items' => count($order->get_items())
                ))
            );
            continue;
        }
        
        // Build invoice data
        $invoice_data = array(
            'customer_id' => (string)$zoho_customer_id,
            'date' => $order->get_date_created()->format('Y-m-d'),
            'reference_number' => 'woo-order-' . $order_id,
            'line_items' => $line_items,
        );
        
        $request_data = json_encode($invoice_data);
        
        if ($existing_invoice_id) {
            $invoice_data['reason'] = 'Invoice paid through WooCommerce';
            $response = zoho_api_put('invoices/' . $existing_invoice_id, $invoice_data);
            usleep(600000);
            
            if (isset($response['code']) && $response['code'] == 1002) {
                $order->delete_meta_data('_zoho_invoice_id');
                $order->save();
                unset($invoice_data['reason']);
                $response = zoho_api_post('invoices', $invoice_data);
                usleep(600000);
                $action = 'created';
            } else {
                $action = 'updated';
            }
        } else {
            $response = zoho_api_post('invoices', $invoice_data);
            usleep(600000);
            $action = 'created';
        }
        
        // Prepare response log
        $response_log = $response;
        if (is_wp_error($response)) {
            $response_log = array(
                'error' => $response->get_error_message(),
                'code' => 'WP_ERROR'
            );
        } elseif (!is_array($response)) {
            $response_log = array('raw_response' => $response);
        }
        
        // Process response
        if (!is_wp_error($response) && isset($response['invoice']['invoice_id'])) {
            $zoho_invoice_id = $response['invoice']['invoice_id'];
            $order->update_meta_data('_zoho_invoice_id', $zoho_invoice_id);
            
            $org_id = get_option('zoho_organization_id', '');
            if (!empty($org_id)) {
                $order->update_meta_data('_zoho_org_id', $org_id);
            }
            $order->save();
            
            // Mark as sent and paid if completed
            if ($order_status === 'completed') {
                zoho_api_post('invoices/' . $zoho_invoice_id . '/status/sent', array());
                usleep(600000);
                
                $invoice_check = zoho_api_get('invoices/' . $zoho_invoice_id);
                usleep(600000);
                
                if (!is_wp_error($invoice_check) && isset($invoice_check['invoice'])) {
                    $invoice_balance = floatval($invoice_check['invoice']['balance'] ?? 0);
                    
                    if ($invoice_balance > 0) {
                        $payment_data = array(
                            'customer_id' => (string)$zoho_customer_id,
                            'payment_mode' => 'other',
                            'amount' => $invoice_balance,
                            'date' => date('Y-m-d'),
                            'invoices' => array(
                                array(
                                    'invoice_id' => $zoho_invoice_id,
                                    'amount_applied' => $invoice_balance
                                )
                            )
                        );
                        
                        zoho_api_post('customerpayments', $payment_data);
                        usleep(600000);
                    }
                }
            }
            
            if ($action == 'created') $batch_created++;
            else $batch_updated++;
            
            // LOG SUCCESS
            zoho_add_detailed_log(
                'invoice',
                'order',
                $order_id,
                'invoice',
                $zoho_invoice_id,
                $request_data,
                json_encode($response_log)
            );
            
        } else {
            // FAILED - Extract error details
            $error_msg = is_wp_error($response) 
                ? $response->get_error_message() 
                : ($response['message'] ?? 'Unknown error');
            $error_code = isset($response['code']) ? $response['code'] : '';
            
            // Add more context for common errors
            if ($error_code == 1002) {
                $item_ids = array_column($line_items, 'item_id');
                $error_msg .= ' | Customer ID: ' . $zoho_customer_id . ' | Item IDs: ' . implode(', ', $item_ids);
            }
            
            $batch_failed[] = array(
                'id' => $order_id, 
                'reason' => 'Code ' . $error_code . ': ' . $error_msg
            );
            
            // LOG FAILED ORDER - API Error
            zoho_add_detailed_log(
                'invoice',
                'order',
                $order_id,
                'invoice',
                $existing_invoice_id ?: '',
                $request_data,
                json_encode(array(
                    'error' => $error_msg,
                    'code' => $error_code ?: 'API_ERROR',
                    'full_response' => $response_log,
                    'invoice_data_sent' => $invoice_data
                ))
            );
        }
    }
    
    // Update stats
    $stats = get_transient('zoho_invoice_sync_stats') ?: array(
        'synced' => 0, 
        'created' => 0, 
        'updated' => 0, 
        'failed' => array()
    );
    
    $new_offset = $offset + count($batch_ids);
    $new_synced = $synced + $batch_created + $batch_updated;
    $new_created = $created + $batch_created;
    $new_updated = $updated + $batch_updated;
    $stats['failed'] = array_merge($stats['failed'] ?? array(), $batch_failed);
    
    set_transient('zoho_invoice_sync_stats', $stats, 600);
    
    $has_more = $new_offset < $total;
    
    if (!$has_more) {
        delete_transient('zoho_invoice_sync_orders');
        delete_transient('zoho_invoice_sync_stats');
        delete_transient('zoho_last_api_call_time');
    }
    
    wp_send_json_success(array(
        'offset' => $new_offset,
        'total' => $total,
        'synced' => $new_synced,
        'created' => $new_created,
        'updated' => $new_updated,
        'failed' => count($stats['failed']),
        'failed_details' => $stats['failed'], // Return failed order details
        'has_more' => $has_more
    ));
}

/* ======================================================
   ZOHO INVOICES TO WOO ORDERS
   FIXED: Proper items sync + billing/shipping info
====================================================== */
function zoho_sync_invoices_to_orders($offset, $total, $synced, $created, $updated, $from_date, $to_date) {
    // First call - fetch Zoho invoices with pagination
    if ($offset == 0 && $total == 0) {
        // Clear old transients
        delete_transient('zoho_invoice_sync_invoices');
        delete_transient('zoho_invoice_sync_stats');
        
        $all_invoices = array();
        $page = 1;
        $per_page = 200;
        
        // Build base params with date filters
        $base_params = array();
        if (!empty($from_date)) $base_params[] = 'date_start=' . $from_date;
        if (!empty($to_date)) $base_params[] = 'date_end=' . $to_date;
        
        // Fetch all pages from Zoho API
        do {
            $params = $base_params;
            $params[] = 'page=' . $page;
            $params[] = 'per_page=' . $per_page;
            $endpoint = 'invoices?' . implode('&', $params);
            
            $response = zoho_api_get($endpoint);
            
            if (is_wp_error($response)) {
                wp_send_json_error('API call failed: ' . $response->get_error_message());
                return;
            }
            
            if (isset($response['code']) && $response['code'] != 0) {
                wp_send_json_error('API Error: ' . ($response['message'] ?? 'Unknown error'));
                return;
            }
            
            $invoices = $response['invoices'] ?? array();
            
            // Apply client-side date filtering if API parameters didn't work or for extra validation
            // Note: Zoho API date_start/date_end should filter, but we also filter here as fallback
            if (!empty($from_date) || !empty($to_date)) {
                foreach ($invoices as $invoice) {
                    $invoice_data = $invoice;
                    if (isset($invoice['invoice'])) {
                        $invoice_data = $invoice['invoice'];
                    }
                    
                    $invoice_created_time = $invoice_data['created_time'] ?? $invoice_data['date'] ?? '';
                    
                    // Skip if no date field
                    if (empty($invoice_created_time)) {
                        continue;
                    }
                    
                    // Parse Zoho date format (e.g., "2026-01-02T12:22:05+0500" or "2026-01-02")
                    $invoice_date = strtotime($invoice_created_time);
                    if ($invoice_date === false) {
                        continue; // Skip if date parsing fails
                    }
                    
                    // Filter by from_date
                    if (!empty($from_date)) {
                        $from_timestamp = strtotime($from_date . ' 00:00:00');
                        if ($invoice_date < $from_timestamp) {
                            continue; // Skip - invoice created before from_date
                        }
                    }
                    
                    // Filter by to_date
                    if (!empty($to_date)) {
                        $to_timestamp = strtotime($to_date . ' 23:59:59');
                        if ($invoice_date > $to_timestamp) {
                            continue; // Skip - invoice created after to_date
                        }
                    }
                    
                    // Invoice passed date filter, add it
                    $all_invoices[] = $invoice;
                }
            } else {
                // No date filter, add all invoices
                $all_invoices = array_merge($all_invoices, $invoices);
            }
            
            $has_more_pages = isset($response['page_context']['has_more_page']) ? $response['page_context']['has_more_page'] : (count($invoices) >= $per_page);
            $page++;
            
        } while ($has_more_pages && $page <= 50);
        
        $total = count($all_invoices);
        
        if ($total == 0) {
            wp_send_json_error('No invoices found in Zoho' . (!empty($from_date) || !empty($to_date) ? ' (after date filtering)' : ''));
            return;
        }
        
        set_transient('zoho_invoice_sync_invoices', $all_invoices, 600);
        set_transient('zoho_invoice_sync_stats', array(
            'synced' => 0, 
            'created' => 0, 
            'updated' => 0, 
            'failed' => array(),
            'skipped' => array()
        ), 600);
    } else {
        $all_invoices = get_transient('zoho_invoice_sync_invoices');
        if (!$all_invoices) {
            wp_send_json_error('Sync session expired. Please try again.');
            return;
        }
        $total = count($all_invoices);
        
        $stats = get_transient('zoho_invoice_sync_stats') ?: array('synced' => 0, 'created' => 0, 'updated' => 0, 'failed' => array());
    }
    
    // Process batch (10 invoices per batch - balanced for performance)
    $batch_size = 10;
    $batch_invoices = array_slice($all_invoices, $offset, $batch_size);
    $batch_created = 0;
    $batch_updated = 0;
    $batch_failed = array();
    $batch_skipped = array();
    
    foreach ($batch_invoices as $invoice) {
        $zoho_invoice_id = $invoice['invoice_id'] ?? '';
        $reference_number = $invoice['reference_number'] ?? '';
        
        // Skip if no invoice ID
        if (empty($zoho_invoice_id)) {
            $batch_failed[] = array(
                'id' => 'Unknown',
                'reason' => 'Missing invoice ID',
                'invoice_number' => $invoice['invoice_number'] ?? 'N/A'
            );
            continue;
        }
        
        // Get full invoice details (including line items and customer info)
        $invoice_details = zoho_api_get('invoices/' . $zoho_invoice_id);
        
        if (is_wp_error($invoice_details)) {
            $batch_failed[] = array(
                'id' => $zoho_invoice_id,
                'reason' => 'API Error: ' . $invoice_details->get_error_message(),
                'invoice_number' => $invoice['invoice_number'] ?? 'N/A'
            );
            continue;
        }
        
        if (!isset($invoice_details['invoice'])) {
            $batch_failed[] = array(
                'id' => $zoho_invoice_id,
                'reason' => 'Invalid response - no invoice data',
                'invoice_number' => $invoice['invoice_number'] ?? 'N/A'
            );
            continue;
        }
        
        $full_invoice = $invoice_details['invoice'];
        
        // Check invoice status - skip drafts if needed
        $invoice_status = strtolower($full_invoice['status'] ?? '');
        
        // Check if order already exists
        $existing_order_id = zoho_get_order_by_zoho_invoice($zoho_invoice_id);
        
        if ($existing_order_id) {
            // Update existing order
            $order = wc_get_order($existing_order_id);
            if ($order) {
                // Remove old line items
                foreach ($order->get_items() as $item_id => $item) {
                    wc_delete_order_item($item_id);
                }
                
                // Add updated line items
                foreach ($full_invoice['line_items'] ?? array() as $line_item) {
                    $item_name = $line_item['name'] ?? 'Item';
                    $quantity = floatval($line_item['quantity'] ?? 1);
                    $rate = floatval($line_item['rate'] ?? 0);
                    $item_total = floatval($line_item['item_total'] ?? ($rate * $quantity));
                    
                    // Try to find product by SKU or item_id
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
                $order->set_total($full_invoice['total'] ?? 0);
                $order->calculate_totals(false); // false = don't recalculate taxes
                
                $order->add_order_note('Updated from Zoho Invoice: ' . $zoho_invoice_id);
                $order->save();
                $batch_updated++;
                
                zoho_add_detailed_log('invoice', 'order', $existing_order_id, 'invoice', $zoho_invoice_id, json_encode($full_invoice), json_encode(array('message' => 'Updated')));
            }
        } else {
            // ===== CREATE NEW ORDER =====
            $order = wc_create_order();
            
            // ===== SET CUSTOMER + BILLING/SHIPPING INFO =====
            $customer_id = $full_invoice['customer_id'] ?? '';
            $wc_customer_id = 0;
            
            if ($customer_id) {
                // Try to find WooCommerce customer by Zoho contact ID
                $wc_customer_id = zoho_get_wc_customer_by_zoho_contact($customer_id);
                
                if ($wc_customer_id) {
                    $order->set_customer_id($wc_customer_id);
                    
                    // Get customer data from WooCommerce
                    $customer = new WC_Customer($wc_customer_id);
                    
                    // Set billing address from customer
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
                    
                    // Set shipping address from customer
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
            
            // If customer not found or no customer data, use invoice billing/shipping address
            if (!$wc_customer_id) {
                // Set billing from invoice
                $billing_address = $full_invoice['billing_address'] ?? array();
                $order->set_billing_first_name($billing_address['first_name'] ?? '');
                $order->set_billing_last_name($billing_address['last_name'] ?? '');
                $order->set_billing_company($billing_address['company_name'] ?? '');
                $order->set_billing_address_1($billing_address['street'] ?? '');
                $order->set_billing_address_2($billing_address['street2'] ?? '');
                $order->set_billing_city($billing_address['city'] ?? '');
                $order->set_billing_state($billing_address['state'] ?? '');
                $order->set_billing_postcode($billing_address['zip'] ?? '');
                $order->set_billing_country($billing_address['country'] ?? '');
                $order->set_billing_email($full_invoice['email'] ?? '');
                $order->set_billing_phone($billing_address['phone'] ?? '');
                
                // Set shipping from invoice
                $shipping_address = $full_invoice['shipping_address'] ?? array();
                $order->set_shipping_first_name($shipping_address['first_name'] ?? $billing_address['first_name'] ?? '');
                $order->set_shipping_last_name($shipping_address['last_name'] ?? $billing_address['last_name'] ?? '');
                $order->set_shipping_company($shipping_address['company_name'] ?? $billing_address['company_name'] ?? '');
                $order->set_shipping_address_1($shipping_address['street'] ?? $billing_address['street'] ?? '');
                $order->set_shipping_address_2($shipping_address['street2'] ?? $billing_address['street2'] ?? '');
                $order->set_shipping_city($shipping_address['city'] ?? $billing_address['city'] ?? '');
                $order->set_shipping_state($shipping_address['state'] ?? $billing_address['state'] ?? '');
                $order->set_shipping_postcode($shipping_address['zip'] ?? $billing_address['zip'] ?? '');
                $order->set_shipping_country($shipping_address['country'] ?? $billing_address['country'] ?? '');
            }
            
            // ===== ADD LINE ITEMS =====
            $line_items = $full_invoice['line_items'] ?? array();
            
            if (empty($line_items)) {
                $batch_failed[] = array(
                    'id' => $zoho_invoice_id,
                    'reason' => 'No line items found in invoice',
                    'invoice_number' => $full_invoice['invoice_number'] ?? 'N/A'
                );
                continue;
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
            
            // ===== ADD TAXES (if any) =====
            foreach ($full_invoice['taxes'] ?? array() as $tax) {
                $tax_item = new WC_Order_Item_Tax();
                $tax_item->set_name($tax['tax_name'] ?? 'Tax');
                $tax_item->set_rate_code($tax['tax_name'] ?? '');
                $tax_item->set_tax_total($tax['tax_amount'] ?? 0);
                $order->add_item($tax_item);
            }
            
            // ===== SET ORDER TOTALS =====
            $order->set_discount_total($full_invoice['discount_total'] ?? 0);
            $order->set_shipping_total($full_invoice['shipping_charge'] ?? 0);
            $order->set_cart_tax($full_invoice['tax_total'] ?? 0);
            $order->set_total($full_invoice['total'] ?? 0);
            
            // Calculate totals (this will ensure everything is properly calculated)
            $order->calculate_totals(false); // false = don't recalculate taxes
            
            // ===== SET ORDER STATUS =====
            $zoho_status = strtolower($full_invoice['status'] ?? '');
            $woo_status = 'processing'; // Default
            
            // Map Zoho status to WooCommerce status
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
            
            // ===== ADD ORDER NOTES =====
            $order->add_order_note('Created from Zoho Invoice: ' . $zoho_invoice_id);
            if (!empty($reference_number)) {
                $order->add_order_note('Zoho Reference Number: ' . $reference_number);
            }
            
            // Save order
            $order->save();
            
            // Save Zoho invoice ID
            $order->update_meta_data('_zoho_invoice_id', $zoho_invoice_id);
            // Save organization_id with invoice
            $org_id = get_option('zoho_organization_id', '');
            if (!empty($org_id)) {
                $order->update_meta_data('_zoho_org_id', $org_id);
            }
            $order->save();
            if (!empty($reference_number)) {
                $order->update_meta_data('_zoho_reference_number', $reference_number);
                $order->save();
            }
            
            $batch_created++;
            
            zoho_add_detailed_log('invoice', 'order', $order->get_id(), 'invoice', $zoho_invoice_id, json_encode($full_invoice), json_encode(array('message' => 'Created', 'order_id' => $order->get_id())));
        }
    }
    
    // Update stats
    $stats = get_transient('zoho_invoice_sync_stats') ?: array(
        'synced' => 0, 
        'created' => 0, 
        'updated' => 0, 
        'failed' => array(),
        'skipped' => array()
    );
    $new_offset = $offset + count($batch_invoices);
    $new_synced = $synced + $batch_created + $batch_updated;
    $new_created = $created + $batch_created;
    $new_updated = $updated + $batch_updated;
    $stats['failed'] = array_merge($stats['failed'] ?? array(), $batch_failed);
    $stats['skipped'] = array_merge($stats['skipped'] ?? array(), $batch_skipped);
    set_transient('zoho_invoice_sync_stats', $stats, 600);
    
    $has_more = $new_offset < $total;
    
    if (!$has_more) {
        delete_transient('zoho_invoice_sync_invoices');
        delete_transient('zoho_invoice_sync_stats');
    }
    
    // Prepare failure reasons with invoice numbers
    $failure_reasons = array();
    foreach ($stats['failed'] as $fail) {
        $invoice_num = isset($fail['invoice_number']) ? ' [Invoice: ' . $fail['invoice_number'] . ']' : '';
        $failure_reasons[] = $fail['id'] . ': ' . $fail['reason'] . $invoice_num;
    }
    
    // Prepare skipped reasons
    $skipped_reasons = array();
    foreach ($stats['skipped'] ?? array() as $skip) {
        $invoice_num = isset($skip['invoice_number']) ? ' [Invoice: ' . $skip['invoice_number'] . ']' : '';
        $skipped_reasons[] = $skip['id'] . ': ' . $skip['reason'] . $invoice_num;
    }
    
    wp_send_json_success(array(
        'offset' => $new_offset,
        'total' => $total,
        'synced' => $new_synced,
        'created' => $new_created,
        'updated' => $new_updated,
        'failed' => count($stats['failed']),
        'failed_reasons' => $failure_reasons,
        'skipped' => count($stats['skipped'] ?? array()),
        'skipped_reasons' => $skipped_reasons,
        'has_more' => $has_more
    ));
}

/* ======================================================
   INVOICE SYNC PAGE
====================================================== */
function zoho_invoice_sync_page() {
    echo '<h1>Invoice Sync</h1>';
    
    // Check connection
    $access_token = get_option('zoho_access_token', '');
    if (empty($access_token)) {
        echo '<div class="notice notice-error"><p>Please connect to Zoho first from the <a href="'.admin_url('admin.php?page=zoho-sync').'">Dashboard</a>.</p></div>';
        return;
    }
    
    echo '<p>Sync invoices between Zoho Inventory and WooCommerce Orders.</p>';
    
    // Date filters
    echo '<div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 4px;">';
    echo '<h3 style="margin-top: 0;">Date Range Filter</h3>';
    echo '<p>Leave empty to sync all invoices/orders.</p>';
    echo '<table class="form-table">';
    echo '<tr>';
    echo '<th><label for="invoice_from_date">From Date</label></th>';
    echo '<td><input type="date" id="invoice_from_date" name="invoice_from_date" class="regular-text"></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th><label for="invoice_to_date">To Date</label></th>';
    echo '<td><input type="date" id="invoice_to_date" name="invoice_to_date" class="regular-text"></td>';
    echo '</tr>';
    echo '</table>';
    echo '</div>';
    
    // Sync direction
    echo '<div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 4px;">';
    echo '<h3 style="margin-top: 0;">Sync Direction</h3>';
    echo '<p><label><input type="radio" name="invoice_sync_direction" value="woo_to_zoho" checked> <strong>WooCommerce → Zoho:</strong> Create/Update Zoho invoices from WooCommerce orders</label></p>';
    echo '<p><label><input type="radio" name="invoice_sync_direction" value="zoho_to_woo"> <strong>Zoho → WooCommerce:</strong> Create/Update WooCommerce orders from Zoho invoices</label></p>';
    echo '</div>';
    
    // Progress bar
    echo '<div id="zoho-invoice-sync-progress" style="display:none; margin: 20px 0;">
        <div style="background: #f0f0f0; border-radius: 4px; padding: 3px; margin-bottom: 10px;">
            <div id="invoice-progress-bar" style="background: #2271b1; height: 24px; border-radius: 3px; width: 0%; transition: width 0.3s ease; text-align: center; line-height: 24px; color: white; font-weight: bold;">
                <span id="invoice-progress-percentage">0%</span>
            </div>
        </div>
        <p id="invoice-progress-text" style="margin: 5px 0;">Starting sync...</p>
    </div>';
    
    echo '<div id="zoho-invoice-sync-result" style="display:none; margin: 20px 0; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;"></div>';
    
    echo '<button id="zoho-start-invoice-sync" class="button button-primary" type="button">Sync Invoices Now</button>';
    
    echo '<p style="margin-top: 15px;"><a href="'.admin_url('admin.php?page=zoho-logs&tab=invoices').'">View Invoice Sync Logs →</a></p>';
    
    ?>
    <script>
    jQuery(document).ready(function($) {
        var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
        var ajaxNonce = '<?php echo wp_create_nonce('zoho_sync_nonce'); ?>';
        
        $('#zoho-start-invoice-sync').on('click', function() {
            var $btn = $(this);
            var fromDate = $('#invoice_from_date').val();
            var toDate = $('#invoice_to_date').val();
            var direction = $('input[name="invoice_sync_direction"]:checked').val();
            
            $btn.prop('disabled', true).text('Syncing...');
            $('#zoho-invoice-sync-progress').show();
            $('#zoho-invoice-sync-result').hide();
            
            syncInvoices(0, 0, 0, 0, 0, direction, fromDate, toDate);
        });
        
        function syncInvoices(offset, total, synced, created, updated, direction, fromDate, toDate) {
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zoho_sync_invoices_ajax',
                    nonce: ajaxNonce,
                    offset: offset,
                    total: total,
                    synced: synced,
                    created: created,
                    updated: updated,
                    direction: direction,
                    from_date: fromDate,
                    to_date: toDate
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var percentage = data.total > 0 ? Math.round((data.synced / data.total) * 100) : 0;
                        
                        $('#invoice-progress-bar').css('width', percentage + '%');
                        $('#invoice-progress-percentage').text(percentage + '%');
                        $('#invoice-progress-text').text('Syncing: ' + data.synced + ' / ' + data.total + ' | Created: ' + data.created + ' | Updated: ' + data.updated);
                        
                        if (data.has_more) {
                            setTimeout(function() {
                                syncInvoices(data.offset, data.total, data.synced, data.created, data.updated, direction, fromDate, toDate);
                            }, 100);
                        } else {
                            $('#invoice-progress-bar').css('width', '100%');
                            $('#invoice-progress-percentage').text('100%');
                            $('#invoice-progress-text').text('Sync completed!');
                            $('#zoho-invoice-sync-result').html('<strong>Sync Completed!</strong> Total: ' + data.total + ' | Synced: ' + data.synced + ' | Created: ' + data.created + ' | Updated: ' + data.updated + ' | Failed: ' + data.failed).show();
                            $('#zoho-start-invoice-sync').prop('disabled', false).text('Sync Invoices Now');
                        }
                    } else {
                        $('#invoice-progress-text').text('Error: ' + (response.data || 'Unknown error'));
                        $('#zoho-start-invoice-sync').prop('disabled', false).text('Sync Invoices Now');
                    }
                },
                error: function(xhr, status, error) {
                    $('#invoice-progress-text').text('Error: ' + error);
                    $('#zoho-start-invoice-sync').prop('disabled', false).text('Sync Invoices Now');
                }
            });
        }
    });
    </script>
    <?php
}
