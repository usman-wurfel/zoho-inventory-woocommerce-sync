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
    // First call - fetch WooCommerce orders and bulk data from Zoho
    if ($offset == 0 && $total == 0) {
        // Clear old transients
        delete_transient('zoho_invoice_sync_orders');
        delete_transient('zoho_invoice_sync_stats');
        delete_transient('zoho_invoice_sync_contacts_map');
        delete_transient('zoho_invoice_sync_items_map');
        
        $args = array(
            'status' => array('completed', 'processing', 'pending', 'on-hold'),
            'limit' => -1,
            'return' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC'
        );
        
        if (!empty($from_date)) {
            $args['date_created'] = '>=' . $from_date;
        }
        if (!empty($to_date)) {
            if (!empty($from_date)) {
                $args['date_created'] = $from_date . '...' . $to_date;
            } else {
                $args['date_created'] = '<=' . $to_date;
            }
        }
        
        $order_ids = wc_get_orders($args);
        $total = count($order_ids);
        
        if ($total == 0) {
            wp_send_json_error('No orders found in WooCommerce');
            return;
        }
        
        // Fetch all contacts from Zoho in bulk (create email->contact_id map)
        $contacts_map = array(); // email => contact_id
        $page = 1;
        $per_page = 200;
        do {
            $contacts_response = zoho_api_get('contacts?page=' . $page . '&per_page=' . $per_page);
            if (!is_wp_error($contacts_response) && isset($contacts_response['contacts']) && !empty($contacts_response['contacts'])) {
                foreach ($contacts_response['contacts'] as $contact) {
                    if (!empty($contact['email'])) {
                        $email_key = strtolower(trim($contact['email']));
                        $contacts_map[$email_key] = $contact['contact_id'];
                    }
                }
            }
            $page++;
        } while (!is_wp_error($contacts_response) && isset($contacts_response['page_context']) && 
                 isset($contacts_response['page_context']['has_more_page']) && 
                 $contacts_response['page_context']['has_more_page']);
        
        // Fetch all items from Zoho in bulk (create item_id map and SKU/name maps)
        $items_map = array(); // item_id => item_data
        $items_sku_map = array(); // sku => item_id
        $items_name_map = array(); // name => item_id
        $page = 1;
        $per_page = 200;
        do {
            $items_response = zoho_api_get('items?page=' . $page . '&per_page=' . $per_page);
            if (!is_wp_error($items_response) && isset($items_response['items']) && !empty($items_response['items'])) {
                foreach ($items_response['items'] as $item) {
                    if (!empty($item['item_id'])) {
                        $item_id = $item['item_id'];
                        $items_map[$item_id] = $item;
                        
                        // Map by SKU
                        if (!empty($item['sku'])) {
                            $items_sku_map[strtolower(trim($item['sku']))] = $item_id;
                        }
                        
                        // Map by name
                        if (!empty($item['name'])) {
                            $items_name_map[strtolower(trim($item['name']))] = $item_id;
                        }
                    }
                }
            }
            $page++;
        } while (!is_wp_error($items_response) && isset($items_response['page_context']) && 
                 isset($items_response['page_context']['has_more_page']) && 
                 $items_response['page_context']['has_more_page']);
        
        // Store in transients for batch processing
        set_transient('zoho_invoice_sync_orders', $order_ids, 600);
        set_transient('zoho_invoice_sync_stats', array('synced' => 0, 'created' => 0, 'updated' => 0, 'failed' => array()), 600);
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
        
        // Get cached maps
        $contacts_map = get_transient('zoho_invoice_sync_contacts_map') ?: array();
        $items_map = get_transient('zoho_invoice_sync_items_map') ?: array();
        $items_sku_map = get_transient('zoho_invoice_sync_items_sku_map') ?: array();
        $items_name_map = get_transient('zoho_invoice_sync_items_name_map') ?: array();
    }
    
    // Process batch (10 orders per batch - balanced for API rate limits)
    $batch_size = 10;
    $batch_ids = array_slice($order_ids, $offset, $batch_size);
    $batch_created = 0;
    $batch_updated = 0;
    $batch_failed = array();
    
    // Get field mappings
    $customer_id_type = get_option('zoho_invoice_customer_id_type', 'standard_field');
    $customer_id_value = get_option('zoho_invoice_customer_id_value', 'shop_order_billing_email');
    $reference_number_type = get_option('zoho_invoice_reference_number_type', 'custom_value');
    $reference_number_value = get_option('zoho_invoice_reference_number_value', 'woo-order-{shop_order_order_id}');
    $date_type = get_option('zoho_invoice_date_type', 'standard_field');
    $date_value = get_option('zoho_invoice_date_value', 'shop_order_order_create_date');
    $invoice_number_type = get_option('zoho_invoice_invoice_number_type', 'custom_value');
    $invoice_number_value = get_option('zoho_invoice_invoice_number_value', 'woo{shop_order_order_id}');
    
    foreach ($batch_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            $batch_failed[] = array('id' => $order_id, 'reason' => 'Order not found');
            continue;
        }
        
        // Check if order is completed and has invoice - verify if invoice is already paid in Zoho
        $existing_invoice_id_check = $order->get_meta('_zoho_invoice_id', true);
        $order_status_check = $order->get_status();
        if ($order_status_check === 'completed' && !empty($existing_invoice_id_check)) {
            // Check invoice status in Zoho - if already paid, skip it
            $invoice_status_check = zoho_api_get('invoices/' . $existing_invoice_id_check);
            if (!is_wp_error($invoice_status_check) && isset($invoice_status_check['invoice'])) {
                $invoice_status = strtolower($invoice_status_check['invoice']['status'] ?? '');
                $invoice_balance = isset($invoice_status_check['invoice']['balance']) ? floatval($invoice_status_check['invoice']['balance']) : 0;
                
                // If invoice is paid (status is 'paid' or balance is 0), skip it
                if ($invoice_status === 'paid' || $invoice_balance <= 0) {
                    continue; // Skip already paid invoices
                }
                // Otherwise, continue processing to update payment
            } else {
                // If invoice doesn't exist in Zoho (Code 1002), clear the ID and continue to create new
                if (isset($invoice_status_check['code']) && $invoice_status_check['code'] == 1002) {
                    $order->delete_meta_data('_zoho_invoice_id');
                    $order->save();
                    // Continue to create new invoice
                } else {
                    // Other error - skip this order
                    continue;
                }
            }
        }
        
        // Get customer email and find in bulk contacts map
        $customer_email_raw = $order->get_billing_email();
        $customer_email = strtolower(trim($customer_email_raw));
        $zoho_customer_id = null;
        
        // Check in bulk contacts map first
        if (!empty($customer_email) && isset($contacts_map[$customer_email])) {
            $zoho_customer_id = $contacts_map[$customer_email];
        } else {
            // Not found in map, need to create (or search as fallback)
            $contact_search_response = zoho_api_get('contacts?email=' . urlencode($customer_email_raw));
            
            if (!is_wp_error($contact_search_response) && isset($contact_search_response['contacts']) && !empty($contact_search_response['contacts'])) {
                $zoho_customer_id = $contact_search_response['contacts'][0]['contact_id'];
                // Add to map for future use
                $contacts_map[$customer_email] = $zoho_customer_id;
                set_transient('zoho_invoice_sync_contacts_map', $contacts_map, 600);
            } else {
                // Contact not found, try to create
                $contact_data = array(
                    'contact_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?: ($order->get_billing_company() ?: $customer_email_raw),
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
                if (!empty($order->get_billing_company())) {
                    $contact_data['company_name'] = $order->get_billing_company();
                }
                
                $contact_create_response = zoho_api_post('contacts', $contact_data);
                
                if (!is_wp_error($contact_create_response) && isset($contact_create_response['contact']['contact_id'])) {
                    $zoho_customer_id = $contact_create_response['contact']['contact_id'];
                    // Add to map for future use
                    $contacts_map[$customer_email] = $zoho_customer_id;
                    set_transient('zoho_invoice_sync_contacts_map', $contacts_map, 600);
                } else {
                    // Check if contact already exists (Code 3062) - might have been added after bulk fetch
                    if (isset($contact_create_response['code']) && $contact_create_response['code'] == 3062) {
                        // Try one more search as fallback
                        $email_search_response = zoho_api_get('contacts?email=' . urlencode($customer_email_raw));
                        if (!is_wp_error($email_search_response) && isset($email_search_response['contacts']) && !empty($email_search_response['contacts'])) {
                            $zoho_customer_id = $email_search_response['contacts'][0]['contact_id'];
                            $contacts_map[$customer_email] = $zoho_customer_id;
                            set_transient('zoho_invoice_sync_contacts_map', $contacts_map, 600);
                        }
                    }
                    
                    // If still no contact ID found, log error
                    if (!$zoho_customer_id) {
                        // Check if contact already exists (Code 3062)
                        // Search by email only (email is unique identifier)
                        if (isset($contact_create_response['code']) && $contact_create_response['code'] == 3062) {
                            // Contact already exists - search by EMAIL only (not name)
                            if (!empty($customer_email)) {
                                $search_email = strtolower(trim($customer_email));
                                $email_search_response = zoho_api_get('contacts?email=' . urlencode($search_email));
                                
                                if (!is_wp_error($email_search_response) && isset($email_search_response['contacts']) && !empty($email_search_response['contacts'])) {
                                    // Verify exact email match
                                    foreach ($email_search_response['contacts'] as $contact) {
                                        $found_email = isset($contact['email']) ? strtolower(trim($contact['email'])) : '';
                                        if ($found_email === $search_email) {
                                            $zoho_customer_id = $contact['contact_id'];
                                            break;
                                        }
                                    }
                                    
                                    // If no exact match, use first result as fallback
                                    if (!$zoho_customer_id && isset($email_search_response['contacts'][0]['contact_id'])) {
                                        $zoho_customer_id = $email_search_response['contacts'][0]['contact_id'];
                                    }
                                }
                            }
                        }
                        
                        $contact_error = is_wp_error($contact_create_response) 
                            ? $contact_create_response->get_error_message() 
                            : (isset($contact_create_response['message']) ? 'Code ' . ($contact_create_response['code'] ?? '') . ': ' . $contact_create_response['message'] : 'Unknown error');
                        
                        $error_reason = 'Failed to create/find customer: ' . $contact_error;
                        $batch_failed[] = array('id' => $order_id, 'reason' => $error_reason);
                        
                        // Log the failure with detailed error
                        zoho_add_detailed_log(
                            'invoice',
                            'order',
                            $order_id,
                            'invoice',
                            '',
                            json_encode(array('order_id' => $order_id, 'customer_email' => $customer_email_raw, 'contact_data' => $contact_data)),
                            json_encode(array('error' => $error_reason, 'code' => 'CUSTOMER_CREATE_FAILED', 'zoho_response' => $contact_create_response))
                        );
                        continue;
                    }
                }
            }
        }
        
        if (!$zoho_customer_id) {
            $error_reason = 'Failed to get/create customer';
            $batch_failed[] = array('id' => $order_id, 'reason' => $error_reason);
            
            // Log the failure
            zoho_add_detailed_log(
                'invoice',
                'order',
                $order_id,
                'invoice',
                '',
                json_encode(array('order_id' => $order_id, 'customer_email' => $customer_email_raw)),
                json_encode(array('error' => $error_reason, 'code' => 'CUSTOMER_CREATE_FAILED'))
            );
            continue;
        }
        
        // Build invoice data
        $invoice_date = $order->get_date_created()->format('Y-m-d');
        $reference_number = str_replace('{shop_order_order_id}', $order_id, $reference_number_value);
        $invoice_number = str_replace('{shop_order_order_id}', $order_id, $invoice_number_value);
        
        // Build line items using bulk items map
        $line_items = array();
        $skipped_items = array(); // Track skipped items for logging
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                $skipped_items[] = 'Product not found for item ID: ' . $item->get_id();
                continue;
            }
            
            $product_id = $product->get_id();
            $sku = $product->get_sku();
            $product_name = $product->get_name();
            
            // First, try to get zoho_item_id from product meta (fastest - already linked)
            $zoho_item_id = get_post_meta($product_id, '_zoho_item_id', true);
            
            // Verify item exists in bulk map
            if ($zoho_item_id && isset($items_map[$zoho_item_id])) {
                // Item found in map, use it
                $zoho_item = $items_map[$zoho_item_id];
            } else {
                // Not in meta or not in map, try to find in bulk maps
                $zoho_item_id = null;
                
                // Try SKU map
                if (!empty($sku) && isset($items_sku_map[strtolower(trim($sku))])) {
                    $zoho_item_id = $items_sku_map[strtolower(trim($sku))];
                }
                
                // Try name map if SKU not found
                if (!$zoho_item_id && !empty($product_name) && isset($items_name_map[strtolower(trim($product_name))])) {
                    $zoho_item_id = $items_name_map[strtolower(trim($product_name))];
                }
                
                // If found, save to meta and get item data
                if ($zoho_item_id && isset($items_map[$zoho_item_id])) {
                    update_post_meta($product_id, '_zoho_item_id', $zoho_item_id);
                    $zoho_item = $items_map[$zoho_item_id];
                } else {
                    // Not found in bulk maps - track as skipped
                    if (!$sku) {
                        $skipped_items[] = $product_name . ' (ID: ' . $product_id . ') - No SKU and not found by name in Zoho';
                    } else {
                        $skipped_items[] = $product_name . ' (SKU: ' . $sku . ') - Not found in Zoho by SKU or name';
                    }
                    continue;
                }
            }
            $quantity = absint($item->get_quantity()); // Ensure integer
            $item_total = floatval($item->get_total()); // Ensure float
            
            // Calculate rate from order item total
            $rate = $quantity > 0 ? floatval($item_total / $quantity) : 0.0;
            
            // Use item's rate from Zoho if available, otherwise use calculated rate
            // But ensure minimum rate of 0.01
            if (isset($zoho_item['rate']) && floatval($zoho_item['rate']) > 0) {
                // Use Zoho's item rate as base, but allow order's rate if different (customer may have paid different price)
                $zoho_rate = floatval($zoho_item['rate']);
                // Only override if our rate is significantly different (>50% difference)
                if (abs($rate - $zoho_rate) / max($zoho_rate, 0.01) > 0.5 && $rate > 0) {
                    // Use order's actual rate (customer paid different price)
                    $final_rate = $rate;
                } else {
                    // Use Zoho's rate (more reliable)
                    $final_rate = $zoho_rate;
                }
            } else {
                $final_rate = $rate;
            }
            
            // Ensure minimum rate
            if ($final_rate <= 0) {
                $final_rate = 0.01;
            }
            
            $line_item = array(
                'item_id' => $zoho_item['item_id'], // Use verified item_id from API response
                'quantity' => $quantity,
                'rate' => round($final_rate, 2), // Round to 2 decimal places
            );
            
            // Ensure name is never added
            unset($line_item['name']);
            
            $line_items[] = $line_item;
        }
        
        // Skip shipping line item (requires item_id or existing item name in Zoho)
        // Shipping can be added manually in Zoho if needed
        
        // Check if we have any valid line items
        if (empty($line_items)) {
            $skipped_details = !empty($skipped_items) ? implode(' | ', $skipped_items) : 'No items in order';
            $error_reason = 'No valid line items (products not found in Zoho). Skipped: ' . $skipped_details;
            $batch_failed[] = array('id' => $order_id, 'reason' => $error_reason);
            
            // Log the failure with detailed skipped items
            $invoice_data = array(
                'customer_id' => $zoho_customer_id,
                'date' => $invoice_date,
                'reference_number' => $reference_number,
                'line_items' => array(), // Empty
            );
            zoho_add_detailed_log(
                'invoice',
                'order',
                $order_id,
                'invoice',
                '',
                json_encode($invoice_data),
                json_encode(array(
                    'error' => $error_reason,
                    'code' => 'NO_LINE_ITEMS',
                    'skipped_items' => $skipped_items
                ))
            );
            continue;
        }
        
        $invoice_data = array(
            'customer_id' => (string)$zoho_customer_id, // Ensure string format
            'date' => $invoice_date,
            'reference_number' => $reference_number,
            'line_items' => $line_items,
        );
        
        // Check if invoice already exists for this order
        $existing_invoice_id = $order->get_meta('_zoho_invoice_id', true);
        
        // If updating an existing invoice, add reason (required when invoice is already sent)
        if ($existing_invoice_id) {
            $invoice_data['reason'] = 'Invoice is paid through WooCommerce';
        }
        
        $request_data = json_encode($invoice_data);
        
        if ($existing_invoice_id) {
            // Update existing invoice
            $response = zoho_api_put('invoices/' . $existing_invoice_id, $invoice_data);
            
            // If Code 1002 (invoice doesn't exist), clear stored ID and create new invoice
            if (isset($response['code']) && $response['code'] == 1002) {
                // Clear invalid invoice ID
                $order->delete_meta_data('_zoho_invoice_id');
                $order->save();
                $existing_invoice_id = '';
                // Remove reason field for new invoice creation
                unset($invoice_data['reason']);
                // Create new invoice
                $response = zoho_api_post('invoices', $invoice_data);
                $action = 'created';
            } else {
                $action = 'updated';
            }
        } else {
            // Create new invoice
            $response = zoho_api_post('invoices', $invoice_data);
            $action = 'created';
        }
        
        // Log the sync (always log, even on errors)
        $zoho_invoice_id = '';
        if (isset($response['invoice']['invoice_id'])) {
            $zoho_invoice_id = $response['invoice']['invoice_id'];
            $order->update_meta_data('_zoho_invoice_id', $zoho_invoice_id);
            $order->save();
            
            // If order status is "completed", mark invoice as "sent" and record payment in Zoho
            $order_status = $order->get_status();
            if ($order_status === 'completed') {
                // First, mark invoice as sent (changes status from draft to sent)
                $status_response = zoho_api_post('invoices/' . $zoho_invoice_id . '/status/sent', array());
                
                // Then record payment to mark invoice as paid
                $order_total = floatval($order->get_total());
                if ($order_total > 0) {
                    // Get invoice to check current balance
                    $invoice_response = zoho_api_get('invoices/' . $zoho_invoice_id);
                    if (!is_wp_error($invoice_response) && isset($invoice_response['invoice'])) {
                        $invoice_data = $invoice_response['invoice'];
                        $invoice_balance = isset($invoice_data['balance']) ? floatval($invoice_data['balance']) : $order_total;
                        
                        // Record payment for the invoice
                        $payment_data = array(
                            'customer_id' => (string)$zoho_customer_id,
                            'payment_mode' => 'other', // Default payment mode, can be customized
                            'amount' => $invoice_balance,
                            'date' => date('Y-m-d'), // Payment date (today)
                            'invoices' => array(
                                array(
                                    'invoice_id' => $zoho_invoice_id,
                                    'amount_applied' => $invoice_balance
                                )
                            )
                        );
                        
                        // Record payment
                        $payment_response = zoho_api_post('customerpayments', $payment_data);
                        // Note: We don't fail the sync if payment recording fails, as invoice was created/updated successfully
                    }
                }
            }
        }
        
        // Ensure response is properly formatted for logging
        $response_log = $response;
        if (is_wp_error($response)) {
            $response_log = array('error' => $response->get_error_message(), 'code' => 'WP_ERROR');
        } elseif (!is_array($response)) {
            $response_log = array('raw_response' => $response);
        }
        
        zoho_add_detailed_log(
            'invoice',
            'order',
            $order_id,
            'invoice',
            $zoho_invoice_id,
            $request_data,
            json_encode($response_log)
        );
        
        if (is_wp_error($response) || (isset($response['code']) && $response['code'] != 0)) {
            $error_msg = is_wp_error($response) ? $response->get_error_message() : ($response['message'] ?? 'Unknown error');
            $error_code = isset($response['code']) ? $response['code'] : '';
            
            // Add detailed info for Code 1002 (Resource does not exist)
            if ($error_code == 1002) {
                $item_ids = array();
                $item_rates = array();
                foreach ($line_items as $li) {
                    if (isset($li['item_id'])) {
                        $item_ids[] = $li['item_id'];
                        $item_rates[] = isset($li['rate']) ? $li['rate'] : 'no rate';
                    }
                }
                $error_msg .= ' | Customer ID: ' . $zoho_customer_id . ' | Item IDs: ' . implode(', ', $item_ids) . ' | Rates: ' . implode(', ', $item_rates);
            }
            
            $batch_failed[] = array('id' => $order_id, 'reason' => $error_msg);
        } else {
            if ($action == 'created') $batch_created++;
            else $batch_updated++;
        }
    }
    
    // Update stats
    $stats = get_transient('zoho_invoice_sync_stats') ?: array('synced' => 0, 'created' => 0, 'updated' => 0, 'failed' => array());
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
    }
    
    wp_send_json_success(array(
        'offset' => $new_offset,
        'total' => $total,
        'synced' => $new_synced,
        'created' => $new_created,
        'updated' => $new_updated,
        'failed' => count($stats['failed']),
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
            $all_invoices = array_merge($all_invoices, $invoices);
            
            $has_more_pages = isset($response['page_context']['has_more_page']) ? $response['page_context']['has_more_page'] : (count($invoices) >= $per_page);
            $page++;
            
        } while ($has_more_pages && $page <= 50);
        
        $total = count($all_invoices);
        
        if ($total == 0) {
            wp_send_json_error('No invoices found in Zoho');
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
    echo '<p>Configure invoice field mappings in <a href="'.admin_url('admin.php?page=zoho-field-mapping&tab=invoices').'">Field Mapping → Invoices</a> before syncing.</p>';
    
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
