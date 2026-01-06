<?php
/**
 * Contact Sync Page and Functions
 */

if (!defined('ABSPATH')) exit;

/* ======================================================
   AJAX HANDLER FOR CONTACT SYNC
====================================================== */
add_action('wp_ajax_zoho_sync_contacts_ajax', 'zoho_sync_contacts_ajax_handler');
function zoho_sync_contacts_ajax_handler() {
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
        zoho_sync_customers_to_contacts($offset, $total, $synced, $created, $updated, $from_date, $to_date);
    } else {
        zoho_sync_contacts_to_customers($offset, $total, $synced, $created, $updated, $from_date, $to_date);
    }
}

/* ====================================================== 
   WOO CUSTOMERS TO ZOHO CONTACTS (Guest + Registered)
   FIXED: Prevents duplicate wc_customer_lookup entries
   ====================================================== */
   function zoho_sync_customers_to_contacts($offset, $total, $synced, $created, $updated, $from_date, $to_date) {
    // First call - fetch WooCommerce customers (registered + guest)
    if ($offset == 0 && $total == 0) {
        // Clear old transients first
        delete_transient('zoho_contact_sync_customers');
        delete_transient('zoho_contact_sync_stats');

        $all_customers = array();
        
        // === PART 1: Fetch Registered Customers (WordPress Users) ===
        $wp_query_args = array(
            'role' => 'customer',
            'number' => -1,
            'fields' => 'ID',
            'orderby' => 'ID',
            'order' => 'ASC',
        );

        // Apply date filters for registered users
        if (!empty($from_date) && $from_date !== '') {
            $wp_query_args['date_query'] = array(
                array('after' => $from_date . ' 00:00:00', 'inclusive' => true)
            );
        }
        if (!empty($to_date) && $to_date !== '') {
            if (!isset($wp_query_args['date_query'])) {
                $wp_query_args['date_query'] = array();
            }
            $wp_query_args['date_query'][] = array('before' => $to_date . ' 23:59:59', 'inclusive' => true);
        }

        $wp_user_query = new WP_User_Query($wp_query_args);
        $registered_users = $wp_user_query->get_results();

        // Add registered customers with type marker and fix missing usernames
        foreach ($registered_users as $user_id) {
            $user = get_user_by('ID', $user_id);
            if (!$user) {
                continue;
            }
            
            $customer = new WC_Customer($user_id);
            $email = strtolower(trim($customer->get_email()));
            
            if (empty($email)) {
                // Try to get email from user object
                $email = strtolower(trim($user->user_email));
            }
            
            if (empty($email)) {
                continue; // Skip if no email
            }
            
            // Fix username if missing or invalid
            if (empty($user->user_login) || strlen($user->user_login) < 3) {
                // Extract username from email (part before @)
                $email_parts = explode('@', $email);
                $new_username = sanitize_user($email_parts[0]);
                
                // Ensure unique username
                $original_username = $new_username;
                $counter = 1;
                while (username_exists($new_username)) {
                    $new_username = $original_username . $counter;
                    $counter++;
                }
                
                // Update username directly in database
                global $wpdb;
                $wpdb->update(
                    $wpdb->users,
                    array('user_login' => $new_username),
                    array('ID' => $user_id),
                    array('%s'),
                    array('%d')
                );
                
                // Clear user cache
                clean_user_cache($user_id);
                
                // Update user object
                $user->user_login = $new_username;
            }
            
            $all_customers[$email] = array(
                'type' => 'registered',
                'user_id' => $user_id,
                'email' => $email
            );
        }

        // === PART 2: Fetch Guest Customers from Orders ===
        $order_args = array(
            'limit' => -1,
            'return' => 'ids',
            'orderby' => 'date',
            'order' => 'ASC',
        );

        // Apply date filters for orders
        if (!empty($from_date) && $from_date !== '') {
            $order_args['date_created'] = '>=' . $from_date . ' 00:00:00';
        }
        if (!empty($to_date) && $to_date !== '') {
            if (!isset($order_args['date_created'])) {
                $order_args['date_created'] = '';
            } else {
                $order_args['date_created'] .= '...';
            }
            $order_args['date_created'] .= '<=' . $to_date . ' 23:59:59';
        }

        $orders = wc_get_orders($order_args);

        // STEP 1: Collect unique guest emails first (avoid duplicate processing)
        $guest_emails = array();
        
        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            $email = strtolower(trim($order->get_billing_email()));
            
            // Skip if already added as registered user or no email
            if (empty($email) || isset($all_customers[$email])) {
                continue;
            }
            
            // Store unique email with order data (latest order will be used)
            if (!isset($guest_emails[$email])) {
                $guest_emails[$email] = array(
                    'email' => $email,
                    'order_id' => $order_id,
                    'first_name' => $order->get_billing_first_name(),
                    'last_name' => $order->get_billing_last_name(),
                    'company' => $order->get_billing_company(),
                    'phone' => $order->get_billing_phone(),
                    'address_1' => $order->get_billing_address_1(),
                    'address_2' => $order->get_billing_address_2(),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'postcode' => $order->get_billing_postcode(),
                    'country' => $order->get_billing_country(),
                    'shipping_first_name' => $order->get_shipping_first_name(),
                    'shipping_last_name' => $order->get_shipping_last_name(),
                    'shipping_company' => $order->get_shipping_company(),
                    'shipping_address_1' => $order->get_shipping_address_1(),
                    'shipping_address_2' => $order->get_shipping_address_2(),
                    'shipping_city' => $order->get_shipping_city(),
                    'shipping_state' => $order->get_shipping_state(),
                    'shipping_postcode' => $order->get_shipping_postcode(),
                    'shipping_country' => $order->get_shipping_country(),
                );
            }
        }

        // STEP 2: Process unique guest emails
        global $wpdb;
        
        foreach ($guest_emails as $email => $order_data) {
            $existing_user = get_user_by('email', $email);
            $new_user_id = null;
            
            if ($existing_user) {
                // ===== EXISTING USER - Just Link Everything =====
                $new_user_id = $existing_user->ID;
                
                // Update ALL customer_lookup entries for this email
                $wpdb->update(
                    $wpdb->prefix . 'wc_customer_lookup',
                    array(
                        'user_id' => $new_user_id,
                        'username' => $existing_user->user_login
                    ),
                    array('email' => $email),
                    array('%d', '%s'),
                    array('%s')
                );
                
                // Link ALL orders for this email to the existing user
                $all_orders_for_email = wc_get_orders(array(
                    'billing_email' => $email,
                    'limit' => -1,
                    'return' => 'ids',
                ));
                
                foreach ($all_orders_for_email as $order_id) {
                    $order = wc_get_order($order_id);
                    if ($order && $order->get_customer_id() == 0) {
                        $order->set_customer_id($new_user_id);
                        $order->save();
                    }
                }
                
            } else {
                // ===== NEW USER - Create with Proper Handling =====
                
                // Check if customer_lookup entry already exists for this email
                $existing_lookup = $wpdb->get_row($wpdb->prepare(
                    "SELECT customer_id FROM {$wpdb->prefix}wc_customer_lookup WHERE email = %s LIMIT 1",
                    $email
                ));
                
                $email_parts = explode('@', $email);
                $username = sanitize_user($email_parts[0]);
                
                // Ensure unique username
                $original_username = $username;
                $counter = 1;
                while (username_exists($username)) {
                    $username = $original_username . $counter;
                    $counter++;
                }
                
                // Generate password
                $password = wp_generate_password();
                
                // Create WordPress user DIRECTLY (bypass WooCommerce function to control customer_lookup)
                $new_user_id = wp_create_user($username, $password, $email);
                
                if (!is_wp_error($new_user_id)) {
                    // Set user role to customer
                    $user = new WP_User($new_user_id);
                    $user->set_role('customer');
                    
                    // If customer_lookup entry exists, UPDATE it instead of creating new
                    if ($existing_lookup) {
                        // Update existing customer_lookup record
                        $wpdb->update(
                            $wpdb->prefix . 'wc_customer_lookup',
                            array(
                                'user_id' => $new_user_id,
                                'username' => $username
                            ),
                            array('customer_id' => $existing_lookup->customer_id),
                            array('%d', '%s'),
                            array('%d')
                        );
                    } else {
                        // Create new customer_lookup entry using WooCommerce DataStore
                        $data_store = WC_Data_Store::load('customer');
                        
                        // Get customer data
                        $customer = new WC_Customer($new_user_id);
                        
                        // Set billing data
                        $customer->set_billing_first_name($order_data['first_name']);
                        $customer->set_billing_last_name($order_data['last_name']);
                        $customer->set_billing_email($email);
                        $customer->set_email($email);
                        
                        // Save customer (this will create customer_lookup entry)
                        $customer->save();
                    }
                    
                    // Set billing information from order data
                    if (!empty($order_data['first_name'])) {
                        update_user_meta($new_user_id, 'billing_first_name', $order_data['first_name']);
                        update_user_meta($new_user_id, 'first_name', $order_data['first_name']);
                    }
                    if (!empty($order_data['last_name'])) {
                        update_user_meta($new_user_id, 'billing_last_name', $order_data['last_name']);
                        update_user_meta($new_user_id, 'last_name', $order_data['last_name']);
                    }
                    if (!empty($order_data['company'])) {
                        update_user_meta($new_user_id, 'billing_company', $order_data['company']);
                    }
                    if (!empty($order_data['phone'])) {
                        update_user_meta($new_user_id, 'billing_phone', $order_data['phone']);
                    }
                    if (!empty($order_data['address_1'])) {
                        update_user_meta($new_user_id, 'billing_address_1', $order_data['address_1']);
                    }
                    if (!empty($order_data['address_2'])) {
                        update_user_meta($new_user_id, 'billing_address_2', $order_data['address_2']);
                    }
                    if (!empty($order_data['city'])) {
                        update_user_meta($new_user_id, 'billing_city', $order_data['city']);
                    }
                    if (!empty($order_data['state'])) {
                        update_user_meta($new_user_id, 'billing_state', $order_data['state']);
                    }
                    if (!empty($order_data['postcode'])) {
                        update_user_meta($new_user_id, 'billing_postcode', $order_data['postcode']);
                    }
                    if (!empty($order_data['country'])) {
                        update_user_meta($new_user_id, 'billing_country', $order_data['country']);
                    }
                    if (!empty($order_data['shipping_first_name'])) {
                        update_user_meta($new_user_id, 'shipping_first_name', $order_data['shipping_first_name']);
                    }
                    if (!empty($order_data['shipping_last_name'])) {
                        update_user_meta($new_user_id, 'shipping_last_name', $order_data['shipping_last_name']);
                    }
                    if (!empty($order_data['shipping_company'])) {
                        update_user_meta($new_user_id, 'shipping_company', $order_data['shipping_company']);
                    }
                    if (!empty($order_data['shipping_address_1'])) {
                        update_user_meta($new_user_id, 'shipping_address_1', $order_data['shipping_address_1']);
                    }
                    if (!empty($order_data['shipping_address_2'])) {
                        update_user_meta($new_user_id, 'shipping_address_2', $order_data['shipping_address_2']);
                    }
                    if (!empty($order_data['shipping_city'])) {
                        update_user_meta($new_user_id, 'shipping_city', $order_data['shipping_city']);
                    }
                    if (!empty($order_data['shipping_state'])) {
                        update_user_meta($new_user_id, 'shipping_state', $order_data['shipping_state']);
                    }
                    if (!empty($order_data['shipping_postcode'])) {
                        update_user_meta($new_user_id, 'shipping_postcode', $order_data['shipping_postcode']);
                    }
                    if (!empty($order_data['shipping_country'])) {
                        update_user_meta($new_user_id, 'shipping_country', $order_data['shipping_country']);
                    }
                    
                    // Set billing_email in user meta
                    update_user_meta($new_user_id, 'billing_email', $email);
                    
                    // Link ALL orders for this email to the new user
                    $all_orders_for_email = wc_get_orders(array(
                        'billing_email' => $email,
                        'limit' => -1,
                        'return' => 'ids',
                    ));
                    
                    foreach ($all_orders_for_email as $order_id) {
                        $order = wc_get_order($order_id);
                        if ($order) {
                            $order->set_customer_id($new_user_id);
                            $order->save();
                        }
                    }
                    
                    // CRITICAL: Delete any duplicate customer_lookup entries for this user
                    // Keep only ONE entry per email
                    $all_lookup_entries = $wpdb->get_results($wpdb->prepare(
                        "SELECT customer_id FROM {$wpdb->prefix}wc_customer_lookup WHERE email = %s ORDER BY customer_id ASC",
                        $email
                    ));
                    
                    if (count($all_lookup_entries) > 1) {
                        // Keep first entry, delete rest
                        $keep_id = $all_lookup_entries[0]->customer_id;
                        $wpdb->query($wpdb->prepare(
                            "DELETE FROM {$wpdb->prefix}wc_customer_lookup WHERE email = %s AND customer_id != %d",
                            $email,
                            $keep_id
                        ));
                    }
                } else {
                    // User creation failed, skip this guest
                    continue;
                }
            }
            
            // Add to all_customers list
            if (!is_wp_error($new_user_id) && $new_user_id) {
                $all_customers[$email] = array(
                    'type' => 'registered',
                    'user_id' => $new_user_id,
                    'email' => $email
                );
            }
        }

        // Convert to indexed array
        $customers_to_sync = array_values($all_customers);
        $total = count($customers_to_sync);

        if ($total == 0) {
            wp_send_json_error('No customers found in WooCommerce');
            return;
        }

        set_transient('zoho_contact_sync_customers', $customers_to_sync, 600);
        set_transient('zoho_contact_sync_stats', array('synced' => 0, 'created' => 0, 'updated' => 0, 'failed' => array()), 600);

    } else {
        $customers_to_sync = get_transient('zoho_contact_sync_customers');
        if (!$customers_to_sync) {
            wp_send_json_error('Sync session expired. Please try again.');
            return;
        }
        $total = count($customers_to_sync);
    }

    // Process batch (20 customers per batch)
    $batch_size = 20;
    $batch = array_slice($customers_to_sync, $offset, $batch_size);
    $batch_created = 0;
    $batch_updated = 0;
    $batch_failed = array();

    foreach ($batch as $customer_data) {
        $contact_data = array();
        
        // Build contact data (all are now registered users)
        $customer = new WC_Customer($customer_data['user_id']);
        $customer->read_meta_data();
        $contact_data = zoho_build_contact_data_from_customer($customer);
        $reference_id = 'User:' . $customer_data['user_id'];
        $saved_zoho_id = get_user_meta($customer_data['user_id'], '_zoho_contact_id', true);

        // Validate contact name
        if (empty($contact_data['contact_name'])) {
            $batch_failed[] = array(
                'id' => $reference_id, 
                'reason' => 'No contact name',
                'email' => $customer_data['email']
            );
            continue;
        }

        $existing_contact_id = $saved_zoho_id;
        $action = '';
        $response = null;

        // ===== STEP 1: Try UPDATE if we have saved ID =====
        if (!empty($existing_contact_id)) {
            $response = zoho_api_put('contacts/' . $existing_contact_id, $contact_data);
            
            // If Code 1002 (contact doesn't exist), clear saved ID and search
            if (isset($response['code']) && $response['code'] == 1002) {
                delete_user_meta($customer_data['user_id'], '_zoho_contact_id');
                $existing_contact_id = '';
                $response = null;
            } else {
                $action = 'updated';
            }
        }

        // ===== STEP 2: Search by EMAIL if no valid ID =====
        if (empty($action) && !empty($contact_data['email'])) {
            $search_email = strtolower(trim($contact_data['email']));
            $search_response = zoho_api_get('contacts?email=' . urlencode($search_email));
            
            if (!is_wp_error($search_response) && isset($search_response['contacts']) && !empty($search_response['contacts'])) {
                // Verify exact email match (case-insensitive)
                foreach ($search_response['contacts'] as $found_contact) {
                    $found_email = isset($found_contact['email']) ? strtolower(trim($found_contact['email'])) : '';
                    if ($found_email === $search_email) {
                        $existing_contact_id = $found_contact['contact_id'];
                        $response = zoho_api_put('contacts/' . $existing_contact_id, $contact_data);
                        $action = 'updated';
                        update_user_meta($customer_data['user_id'], '_zoho_contact_id', $existing_contact_id);
                    // Save organization_id with contact
                    $org_id = get_option('zoho_organization_id', '');
                    if (!empty($org_id)) {
                        update_user_meta($customer_data['user_id'], '_zoho_org_id', $org_id);
                    }
                        // Save organization_id with contact
                        $org_id = get_option('zoho_organization_id', '');
                        if (!empty($org_id)) {
                            update_user_meta($customer_data['user_id'], '_zoho_org_id', $org_id);
                        }
                        break;
                    }
                }
                
                // Fallback if email field missing in response
                if (empty($action) && !empty($search_response['contacts'][0]['contact_id'])) {
                    $existing_contact_id = $search_response['contacts'][0]['contact_id'];
                    $response = zoho_api_put('contacts/' . $existing_contact_id, $contact_data);
                    $action = 'updated';
                    update_user_meta($customer_data['user_id'], '_zoho_contact_id', $existing_contact_id);
                    // Save organization_id with contact
                    $org_id = get_option('zoho_organization_id', '');
                    if (!empty($org_id)) {
                        update_user_meta($customer_data['user_id'], '_zoho_org_id', $org_id);
                    }
                }
            }
        }

        // ===== STEP 3: CREATE if still not found =====
        if (empty($action)) {
            $response = zoho_api_post('contacts', $contact_data);
            
            // Handle "Contact already exists" error
            if (isset($response['code']) && ($response['code'] == 3062 || $response['code'] == 1002)) {
                if (!empty($contact_data['email'])) {
                    $search_email = strtolower(trim($contact_data['email']));
                    $search_response = zoho_api_get('contacts?email=' . urlencode($search_email));
                    
                    if (!is_wp_error($search_response) && isset($search_response['contacts']) && !empty($search_response['contacts'])) {
                        foreach ($search_response['contacts'] as $found_contact) {
                            $found_email = isset($found_contact['email']) ? strtolower(trim($found_contact['email'])) : '';
                            if ($found_email === $search_email) {
                                $existing_contact_id = $found_contact['contact_id'];
                                $response = zoho_api_put('contacts/' . $existing_contact_id, $contact_data);
                                $action = 'updated';
                                update_user_meta($customer_data['user_id'], '_zoho_contact_id', $existing_contact_id);
                    // Save organization_id with contact
                    $org_id = get_option('zoho_organization_id', '');
                    if (!empty($org_id)) {
                        update_user_meta($customer_data['user_id'], '_zoho_org_id', $org_id);
                    }
                        // Save organization_id with contact
                        $org_id = get_option('zoho_organization_id', '');
                        if (!empty($org_id)) {
                            update_user_meta($customer_data['user_id'], '_zoho_org_id', $org_id);
                        }
                                break;
                            }
                        }
                        
                        if (empty($action) && !empty($search_response['contacts'][0]['contact_id'])) {
                            $existing_contact_id = $search_response['contacts'][0]['contact_id'];
                            $response = zoho_api_put('contacts/' . $existing_contact_id, $contact_data);
                            $action = 'updated';
                            update_user_meta($customer_data['user_id'], '_zoho_contact_id', $existing_contact_id);
                    // Save organization_id with contact
                    $org_id = get_option('zoho_organization_id', '');
                    if (!empty($org_id)) {
                        update_user_meta($customer_data['user_id'], '_zoho_org_id', $org_id);
                    }
                        // Save organization_id with contact
                        $org_id = get_option('zoho_organization_id', '');
                        if (!empty($org_id)) {
                            update_user_meta($customer_data['user_id'], '_zoho_org_id', $org_id);
                        }
                        }
                    }
                }
                
                if (empty($action)) {
                    $response = zoho_api_post('contacts', $contact_data);
                    if (isset($response['contact']['contact_id'])) {
                        $action = 'created';
                    }
                }
            } else {
                $action = 'created';
            }
        }

        // ===== STEP 4: Save Zoho Contact ID =====
        $zoho_contact_id = '';
        
        if (isset($response['contact']['contact_id'])) {
            $zoho_contact_id = $response['contact']['contact_id'];
        } elseif (!empty($existing_contact_id)) {
            $zoho_contact_id = $existing_contact_id;
        }
        
        if (!empty($zoho_contact_id)) {
            update_user_meta($customer_data['user_id'], '_zoho_contact_id', $zoho_contact_id);
            // Save organization_id with contact
            $org_id = get_option('zoho_organization_id', '');
            if (!empty($org_id)) {
                update_user_meta($customer_data['user_id'], '_zoho_org_id', $org_id);
            }
        }

        // Log the attempt
        zoho_add_detailed_log(
            'contact', 
            $customer_data['type'], 
            $reference_id, 
            'contact', 
            $zoho_contact_id, 
            json_encode($contact_data), 
            json_encode($response)
        );

        // ===== STEP 5: Check for final errors =====
        $has_error = false;
        $error_msg = '';
        
        if (is_wp_error($response)) {
            $has_error = true;
            $error_msg = $response->get_error_message();
            } elseif (isset($response['error'])) {
            $has_error = true;
            $error_msg = $response['error'];
        } elseif (isset($response['code']) && $response['code'] != 0 && !isset($response['contact'])) {
            $has_error = true;
            $error_msg = 'Code ' . $response['code'] . ': ' . ($response['message'] ?? 'Unknown');
        }

        if ($has_error) {
            $batch_failed[] = array(
                'id' => $reference_id, 
                'reason' => $error_msg,
                'email' => $contact_data['email'] ?? 'N/A'
            );
        } else {
            if ($action == 'created') {
                $batch_created++;
            } else {
                $batch_updated++;
            }
        }
    }

    // Update stats
    $stats = get_transient('zoho_contact_sync_stats') ?: array('synced' => 0, 'created' => 0, 'updated' => 0, 'failed' => array());
    $new_offset = $offset + count($batch);
    $new_synced = $synced + $batch_created + $batch_updated;
    $new_created = $created + $batch_created;
    $new_updated = $updated + $batch_updated;
    $stats['failed'] = array_merge($stats['failed'] ?? array(), $batch_failed);
    set_transient('zoho_contact_sync_stats', $stats, 600);
    
    // Cleanup orphaned customer lookup records after each batch
    global $wpdb;
    $wpdb->query("DELETE c FROM {$wpdb->prefix}wc_customer_lookup c LEFT JOIN {$wpdb->prefix}wc_order_stats o ON c.customer_id = o.customer_id WHERE (c.user_id = 0 OR c.user_id IS NULL) AND o.order_id IS NULL");
    
    $has_more = $new_offset < $total;
    
    if (!$has_more) {
        delete_transient('zoho_contact_sync_customers');
        delete_transient('zoho_contact_sync_stats');
    }

    // Prepare failure reasons for display
    $failure_reasons = array();
    foreach ($stats['failed'] as $fail) {
        $failure_reasons[] = $fail['id'] . ': ' . $fail['reason'] . ' (' . $fail['email'] . ')';
    }

    wp_send_json_success(array(
        'offset' => $new_offset,
        'total' => $total,
        'synced' => $new_synced,
        'created' => $new_created,
        'updated' => $new_updated,
        'failed' => count($stats['failed']),
        'failed_reasons' => $failure_reasons,
        'has_more' => $has_more
    ));
}

/* ======================================================
   Helper Function: Build Contact Data from Order (Guest)
====================================================== */
function zoho_build_contact_data_from_order($order) {
    // Get billing name fields - FIRST try HPOS table directly, then order methods
    global $wpdb;
    $order_id = $order->get_id();
    $addresses_table = $wpdb->prefix . 'wc_order_addresses';
    $first_name = '';
    $last_name = '';
    
    // PRIORITY 1: Get from HPOS addresses table (most reliable)
    $address_data = null;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$addresses_table}'") === $addresses_table) {
        $address_data = $wpdb->get_row($wpdb->prepare(
            "SELECT first_name, last_name, address_1, address_2, city, state, postcode, country, email, phone FROM {$addresses_table} WHERE order_id = %d AND address_type = 'billing' LIMIT 1",
            $order_id
        ), ARRAY_A);
        
        if ($address_data && is_array($address_data)) {
            $first_name = trim($address_data['first_name'] ?? '');
            $last_name = trim($address_data['last_name'] ?? '');
        }
    }
    
    // PRIORITY 2: If empty, try order methods
    if (empty($first_name)) {
        $first_name = trim($order->get_billing_first_name());
    }
    if (empty($last_name)) {
        $last_name = trim($order->get_billing_last_name());
    }
    
    // Build contact name
    $contact_name = trim($first_name . ' ' . $last_name);
    
    // Fallback to email ONLY if absolutely no name
    if (empty($contact_name) || $contact_name === ' ') {
        $contact_name = $order->get_billing_email();
    }

    // Get email and phone - try HPOS table first if available
    $billing_email = '';
    $billing_phone = '';
    if (isset($address_data)) {
        $billing_email = trim($address_data['email'] ?? '');
        $billing_phone = trim($address_data['phone'] ?? '');
    }
    if (empty($billing_email)) {
        $billing_email = $order->get_billing_email();
    }
    if (empty($billing_phone)) {
        $billing_phone = $order->get_billing_phone();
    }
    
    $contact_data = array(
        'contact_name' => $contact_name,
        'contact_type' => 'customer',
        'customer_sub_type' => 'individual',
    );
    
    // Always include email if available
    if (!empty($billing_email)) {
        $contact_data['email'] = $billing_email;
    }
    
    // Include phone if available
    if (!empty($billing_phone)) {
        $contact_data['phone'] = $billing_phone;
    }
    
    // Add email and name to contact_persons array (required for Zoho panel display)
    if (!empty($billing_email) || !empty($first_name) || !empty($last_name)) {
        $contact_data['contact_persons'] = array();
        $contact_person = array();
        
        if (!empty($billing_email)) {
            $contact_person['email'] = $billing_email;
        }
        if (!empty($first_name)) {
            $contact_person['first_name'] = $first_name;
        }
        if (!empty($last_name)) {
            $contact_person['last_name'] = $last_name;
        }
        
        // Mark as primary contact person
        $contact_person['is_primary_contact'] = true;
        
        // Add phone if available
        if (!empty($billing_phone)) {
            $contact_person['phone'] = $billing_phone;
        }
        
        $contact_data['contact_persons'][] = $contact_person;
    }

    // Add first/last name if available
    if (!empty($first_name)) {
        $contact_data['first_name'] = $first_name;
    }
    if (!empty($last_name)) {
        $contact_data['last_name'] = $last_name;
    }

    // Add company if available
    if (!empty($order->get_billing_company())) {
        $contact_data['company_name'] = $order->get_billing_company();
    }

    // Add billing address if available (use HPOS table data if available)
    // Note: Zoho uses 'address' field, not 'street'
    $billing_address_1 = '';
    $billing_address_2 = '';
    $billing_city = '';
    $billing_state = '';
    $billing_postcode = '';
    $billing_country = '';
    
    // Use data from HPOS table if already fetched
    if (isset($address_data)) {
        $billing_address_1 = trim($address_data['address_1'] ?? '');
        $billing_address_2 = trim($address_data['address_2'] ?? '');
        $billing_city = trim($address_data['city'] ?? '');
        $billing_state = trim($address_data['state'] ?? '');
        $billing_postcode = trim($address_data['postcode'] ?? '');
        $billing_country = trim($address_data['country'] ?? '');
    }
    
    // If empty, try order methods
    if (empty($billing_address_1)) {
        $billing_address_1 = trim($order->get_billing_address_1());
    }
    if (empty($billing_address_2)) {
        $billing_address_2 = trim($order->get_billing_address_2());
    }
    if (empty($billing_city)) {
        $billing_city = trim($order->get_billing_city());
    }
    if (empty($billing_state)) {
        $billing_state = trim($order->get_billing_state());
    }
    if (empty($billing_postcode)) {
        $billing_postcode = trim($order->get_billing_postcode());
    }
    if (empty($billing_country)) {
        $billing_country = trim($order->get_billing_country());
    }
    
    // Add billing address if any field is available
    if (!empty($billing_address_1) || !empty($billing_address_2) || !empty($billing_city) || !empty($billing_state) || !empty($billing_postcode) || !empty($billing_country)) {
        $contact_data['billing_address'] = array();
        
        if (!empty($billing_address_1)) {
            $contact_data['billing_address']['address'] = $billing_address_1;
        }
        if (!empty($billing_address_2)) {
            $contact_data['billing_address']['street2'] = $billing_address_2;
        }
        if (!empty($billing_city)) {
            $contact_data['billing_address']['city'] = $billing_city;
        }
        if (!empty($billing_state)) {
            $contact_data['billing_address']['state'] = $billing_state;
        }
        if (!empty($billing_postcode)) {
            $contact_data['billing_address']['zip'] = $billing_postcode;
        }
        if (!empty($billing_country)) {
            $contact_data['billing_address']['country'] = $billing_country;
        }
    }

    // Add shipping address if available (HPOS compatible)
    // Note: Zoho uses 'address' field, not 'street'
    $shipping_address_1 = '';
    $shipping_address_2 = '';
    $shipping_city = '';
    $shipping_state = '';
    $shipping_postcode = '';
    $shipping_country = '';
    
    // PRIORITY 1: Get from HPOS addresses table
    if ($wpdb->get_var("SHOW TABLES LIKE '{$addresses_table}'") === $addresses_table) {
        $shipping_address_data = $wpdb->get_row($wpdb->prepare(
            "SELECT address_1, address_2, city, state, postcode, country FROM {$addresses_table} WHERE order_id = %d AND address_type = 'shipping' LIMIT 1",
            $order_id
        ), ARRAY_A);
        
        if ($shipping_address_data) {
            $shipping_address_1 = trim($shipping_address_data['address_1'] ?? '');
            $shipping_address_2 = trim($shipping_address_data['address_2'] ?? '');
            $shipping_city = trim($shipping_address_data['city'] ?? '');
            $shipping_state = trim($shipping_address_data['state'] ?? '');
            $shipping_postcode = trim($shipping_address_data['postcode'] ?? '');
            $shipping_country = trim($shipping_address_data['country'] ?? '');
        }
    }
    
    // PRIORITY 2: If empty, try order methods
    if (empty($shipping_address_1)) {
        $shipping_address_1 = trim($order->get_shipping_address_1());
    }
    if (empty($shipping_address_2)) {
        $shipping_address_2 = trim($order->get_shipping_address_2());
    }
    if (empty($shipping_city)) {
        $shipping_city = trim($order->get_shipping_city());
    }
    if (empty($shipping_state)) {
        $shipping_state = trim($order->get_shipping_state());
    }
    if (empty($shipping_postcode)) {
        $shipping_postcode = trim($order->get_shipping_postcode());
    }
    if (empty($shipping_country)) {
        $shipping_country = trim($order->get_shipping_country());
    }
    
    // Add shipping address if any field is available
    if (!empty($shipping_address_1) || !empty($shipping_address_2) || !empty($shipping_city) || !empty($shipping_state) || !empty($shipping_postcode) || !empty($shipping_country)) {
        $contact_data['shipping_address'] = array();
        
        if (!empty($shipping_address_1)) {
            $contact_data['shipping_address']['address'] = $shipping_address_1;
        }
        if (!empty($shipping_address_2)) {
            $contact_data['shipping_address']['street2'] = $shipping_address_2;
        }
        if (!empty($shipping_city)) {
            $contact_data['shipping_address']['city'] = $shipping_city;
        }
        if (!empty($shipping_state)) {
            $contact_data['shipping_address']['state'] = $shipping_state;
        }
        if (!empty($shipping_postcode)) {
            $contact_data['shipping_address']['zip'] = $shipping_postcode;
        }
        if (!empty($shipping_country)) {
            $contact_data['shipping_address']['country'] = $shipping_country;
        }
    }

    return $contact_data;
}

/* ======================================================
   ZOHO CONTACTS TO WOO CUSTOMERS
====================================================== */
function zoho_sync_contacts_to_customers($offset, $total, $synced, $created, $updated, $from_date, $to_date) {
    // First call - fetch Zoho contacts with pagination
    if ($offset == 0 && $total == 0) {
        // Clear old transients
        delete_transient('zoho_contact_sync_contacts');
        delete_transient('zoho_contact_sync_stats');
        
        $all_contacts = array();
        $page = 1;
        $per_page = 200;
        
        // Fetch all pages from Zoho API
        do {
            $response = zoho_api_get('contacts?page=' . $page . '&per_page=' . $per_page);
            
            if (is_wp_error($response)) {
                wp_send_json_error('API call failed: ' . $response->get_error_message());
                return;
            }
            
            if (isset($response['code']) && $response['code'] != 0) {
                wp_send_json_error('API Error: ' . ($response['message'] ?? 'Unknown error'));
                return;
            }
            
            $contacts = $response['contacts'] ?? array();
            $all_contacts = array_merge($all_contacts, $contacts);
            
            $has_more_pages = isset($response['page_context']['has_more_page']) ? $response['page_context']['has_more_page'] : (count($contacts) >= $per_page);
            $page++;
            
        } while ($has_more_pages && $page <= 50);
        
        $total = count($all_contacts);
        
        if ($total == 0) {
            wp_send_json_error('No contacts found in Zoho');
            return;
        }
        
        set_transient('zoho_contact_sync_contacts', $all_contacts, 600);
        set_transient('zoho_contact_sync_stats', array('synced' => 0, 'created' => 0, 'updated' => 0, 'failed' => array()), 600);
        $contacts = $all_contacts;
    } else {
        $contacts = get_transient('zoho_contact_sync_contacts');
        if (!$contacts) {
            wp_send_json_error('Sync session expired. Please try again.');
            return;
        }
        $total = count($contacts);
    }
    
    // Process batch (20 contacts per batch - balanced for performance)
    $batch_size = 20;
    $batch_contacts = array_slice($contacts, $offset, $batch_size);
    $batch_created = 0;
    $batch_updated = 0;
    $batch_failed = array();
    
    foreach ($batch_contacts as $contact) {
        $zoho_contact_id = $contact['contact_id'] ?? '';
        $contact_name = $contact['contact_name'] ?? '';
        
        // Get email - check root level first, then contact_persons array
        $email = $contact['email'] ?? '';
        if (empty($email) && isset($contact['contact_persons']) && is_array($contact['contact_persons'])) {
            foreach ($contact['contact_persons'] as $person) {
                if (isset($person['is_primary_contact']) && $person['is_primary_contact']) {
                    $email = $person['email'] ?? '';
                    break;
                }
            }
            // If no primary contact found, use first contact with email
            if (empty($email)) {
                foreach ($contact['contact_persons'] as $person) {
                    $email = $person['email'] ?? '';
                    if (!empty($email)) break;
                }
            }
        }
        
        // Normalize and validate email
        $email = !empty($email) ? trim(strtolower($email)) : '';
        if (!empty($email) && !is_email($email)) {
            $email = '';
        }
        
        // Get first/last name - parse from contact_name if not available
        $first_name = $contact['first_name'] ?? '';
        $last_name = $contact['last_name'] ?? '';
        
        if (empty($first_name) && empty($last_name) && !empty($contact_name)) {
            // Parse contact_name into first/last
            $name_parts = explode(' ', trim($contact_name), 2);
            $first_name = $name_parts[0] ?? '';
            $last_name = $name_parts[1] ?? '';
        }
        
        // If still no email, try to find from existing WooCommerce user by matching name
        if (empty($email) && !empty($contact_name)) {
            // Try to find existing user by name and get their email
            $users = get_users(array(
                'number' => 10,
                'search' => $contact_name,
                'search_columns' => array('display_name')
            ));
            
            // Also search by first/last name
            if (empty($users) && !empty($first_name) && !empty($last_name)) {
                $users = get_users(array(
                    'meta_query' => array(
                        'relation' => 'AND',
                        array('key' => 'first_name', 'value' => $first_name, 'compare' => '='),
                        array('key' => 'last_name', 'value' => $last_name, 'compare' => '=')
                    ),
                    'number' => 5
                ));
            }
            
            // Get email from first matching user
            if (!empty($users) && isset($users[0])) {
                $user_email = $users[0]->user_email;
                $billing_email = get_user_meta($users[0]->ID, 'billing_email', true);
                $email = !empty($billing_email) ? $billing_email : $user_email;
            }
        }
        
        // LAST RESORT: Only generate dummy email if absolutely no email found
        if (empty($email) || !is_email($email)) {
            // Generate dummy email from contact name or ID
            $slug = sanitize_title($contact_name ?: 'contact-' . $zoho_contact_id);
            $email = $slug . '@zoho-import.local';
        }
        
        // Fallback for names
        if (empty($first_name)) $first_name = 'Customer';
        if (empty($last_name)) $last_name = $zoho_contact_id;
        
        // Get other fields with defaults
        $company = $contact['company_name'] ?? '';
        $phone = $contact['phone'] ?? ($contact['mobile'] ?? '');
        
        // Get billing address from Zoho
        $billing_address = $contact['billing_address'] ?? array();
        $billing_street = $billing_address['address'] ?? ($billing_address['street'] ?? '');
        $billing_street2 = $billing_address['street2'] ?? '';
        $billing_city = $billing_address['city'] ?? '';
        $billing_state = $billing_address['state'] ?? '';
        $billing_zip = $billing_address['zip'] ?? ($billing_address['zipcode'] ?? '');
        $billing_country = $billing_address['country'] ?? '';
        $billing_phone = $billing_address['phone'] ?? $phone;
        
        // Get shipping address from Zoho
        $shipping_address = $contact['shipping_address'] ?? array();
        $shipping_street = $shipping_address['address'] ?? ($shipping_address['street'] ?? $billing_street);
        $shipping_street2 = $shipping_address['street2'] ?? $billing_street2;
        $shipping_city = $shipping_address['city'] ?? $billing_city;
        $shipping_state = $shipping_address['state'] ?? $billing_state;
        $shipping_zip = $shipping_address['zip'] ?? ($shipping_address['zipcode'] ?? $billing_zip);
        $shipping_country = $shipping_address['country'] ?? $billing_country;
        
        $existing_user = null;
        $user_id = null;
        
        // 1. Check by Zoho contact ID
        global $wpdb;
        $existing_user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_zoho_contact_id' AND meta_value = %s LIMIT 1",
            $zoho_contact_id
        ));
        if ($existing_user_id) {
            $existing_user = get_user_by('ID', $existing_user_id);
        }
        
        // 2. If not found by Zoho ID, match by EMAIL (email is unique)
        if (!$existing_user && !empty($email) && is_email($email)) {
            $search_email = trim(strtolower($email));
            
            $user_id_by_billing_email = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'billing_email' AND LOWER(TRIM(meta_value)) = %s LIMIT 1",
                $search_email
            ));
            
            $user_id_by_user_email = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE LOWER(TRIM(user_email)) = %s LIMIT 1",
                $search_email
            ));
            
            $matched_user_id = $user_id_by_billing_email ?: $user_id_by_user_email;
            if ($matched_user_id) {
                $existing_user = get_user_by('ID', $matched_user_id);
            }
        }
        
        if ($existing_user) {
            $user_id = $existing_user->ID;
            $action = 'Updated';
            $batch_updated++;
            
            // For existing customers, preserve WooCommerce name (don't overwrite with Zoho name)
            $existing_first_name = get_user_meta($user_id, 'first_name', true);
            $existing_last_name = get_user_meta($user_id, 'last_name', true);
            $existing_billing_first = get_user_meta($user_id, 'billing_first_name', true);
            $existing_billing_last = get_user_meta($user_id, 'billing_last_name', true);
            
            // Only use Zoho name if WooCommerce name is empty
            if (empty($existing_first_name) && empty($existing_last_name)) {
                update_user_meta($user_id, 'first_name', $first_name);
                update_user_meta($user_id, 'last_name', $last_name);
            } else {
                $first_name = $existing_first_name ?: $first_name;
                $last_name = $existing_last_name ?: $last_name;
            }
            
            // Only update billing name if it's empty
            if (empty($existing_billing_first) && empty($existing_billing_last)) {
                update_user_meta($user_id, 'billing_first_name', $first_name);
                update_user_meta($user_id, 'billing_last_name', $last_name);
            }
            
            // Fix username if missing or invalid
            $existing_user_obj = get_user_by('ID', $user_id);
            if ($existing_user_obj && (empty($existing_user_obj->user_login) || strlen($existing_user_obj->user_login) < 3)) {
                // Generate username from email or name
                $new_username = '';
                if (!empty($email)) {
                    $email_parts = explode('@', $email);
                    $new_username = sanitize_user($email_parts[0]);
                } elseif (!empty($contact_name)) {
                    $new_username = sanitize_user($contact_name);
                } else {
                    $new_username = 'user_' . $user_id;
                }
                
                // Ensure unique username
                $original_username = $new_username;
                $counter = 1;
                while (username_exists($new_username)) {
                    $new_username = $original_username . $counter;
                    $counter++;
                }
                
                // Update username directly in database
                global $wpdb;
                $wpdb->update(
                    $wpdb->users,
                    array('user_login' => $new_username),
                    array('ID' => $user_id),
                    array('%s'),
                    array('%d')
                );
                
                // Clear user cache
                clean_user_cache($user_id);
            }
        } else {
            // Create new user
            $create_email = $email;
            if (empty($create_email) || !is_email($create_email)) {
                $create_email = sanitize_title($contact_name ?: 'contact-' . $zoho_contact_id) . '-' . substr(md5($zoho_contact_id), 0, 6) . '@zoho-import.local';
            }
            
            $username = sanitize_user($create_email);
            $password = wp_generate_password();
            $user_id = wc_create_new_customer($create_email, $username, $password);
            
            if (is_wp_error($user_id)) {
                $batch_failed[] = array('id' => $zoho_contact_id, 'reason' => $user_id->get_error_message());
                continue;
            }
            
            $action = 'Created';
            $batch_created++;
            
            // For new customers, set name from Zoho
            update_user_meta($user_id, 'first_name', $first_name);
            update_user_meta($user_id, 'last_name', $last_name);
            update_user_meta($user_id, 'billing_first_name', $first_name);
            update_user_meta($user_id, 'billing_last_name', $last_name);
        }
        update_user_meta($user_id, 'billing_company', $company);
        update_user_meta($user_id, 'billing_email', $email);
        update_user_meta($user_id, 'billing_phone', $billing_phone);
        update_user_meta($user_id, 'billing_address_1', $billing_street);
        update_user_meta($user_id, 'billing_address_2', $billing_street2);
        update_user_meta($user_id, 'billing_city', $billing_city);
        update_user_meta($user_id, 'billing_state', $billing_state);
        update_user_meta($user_id, 'billing_postcode', $billing_zip);
        update_user_meta($user_id, 'billing_country', $billing_country);
        
        // Shipping details
        if ($action == 'Created') {
            // For new customers, set shipping name from Zoho
            update_user_meta($user_id, 'shipping_first_name', $first_name);
            update_user_meta($user_id, 'shipping_last_name', $last_name);
        } else {
            // For existing customers, preserve WooCommerce shipping name
            $existing_shipping_first = get_user_meta($user_id, 'shipping_first_name', true);
            $existing_shipping_last = get_user_meta($user_id, 'shipping_last_name', true);
            
            // Only update if empty
            if (empty($existing_shipping_first) && empty($existing_shipping_last)) {
                update_user_meta($user_id, 'shipping_first_name', $first_name);
                update_user_meta($user_id, 'shipping_last_name', $last_name);
            }
        }
        update_user_meta($user_id, 'shipping_company', $company);
        update_user_meta($user_id, 'shipping_address_1', $shipping_street);
        update_user_meta($user_id, 'shipping_address_2', $shipping_street2);
        update_user_meta($user_id, 'shipping_city', $shipping_city);
        update_user_meta($user_id, 'shipping_state', $shipping_state);
        update_user_meta($user_id, 'shipping_postcode', $shipping_zip);
        update_user_meta($user_id, 'shipping_country', $shipping_country);
        
        // Link to Zoho
        update_user_meta($user_id, '_zoho_contact_id', $zoho_contact_id);
        // Save organization_id with contact
        $org_id = get_option('zoho_organization_id', '');
        if (!empty($org_id)) {
            update_user_meta($user_id, '_zoho_org_id', $org_id);
        }
        
        // Update display_name for WooCommerce to show customer name properly
        // Use billing name first (WooCommerce standard), then fallback to first_name/last_name
        $final_first_name = get_user_meta($user_id, 'billing_first_name', true);
        $final_last_name = get_user_meta($user_id, 'billing_last_name', true);
        
        if (empty($final_first_name)) {
            $final_first_name = get_user_meta($user_id, 'first_name', true);
        }
        if (empty($final_last_name)) {
            $final_last_name = get_user_meta($user_id, 'last_name', true);
        }
        
        $display_name = trim($final_first_name . ' ' . $final_last_name);
        
        // Fallback to company name or email if name is empty
        if (empty($display_name)) {
            $company = get_user_meta($user_id, 'billing_company', true);
            if (!empty($company)) {
                $display_name = $company;
            } else {
                $user = get_user_by('ID', $user_id);
                if ($user) {
                    $display_name = $user->user_email;
                }
            }
        }
        
        if (!empty($display_name)) {
            wp_update_user(array(
                'ID' => $user_id,
                'display_name' => $display_name
            ));
        }
        
        zoho_add_detailed_log('contact', 'customer', $user_id, 'contact', $zoho_contact_id, json_encode($contact), json_encode(array('message' => $action, 'user_id' => $user_id)));
    }
    
    // Update stats
    $stats = get_transient('zoho_contact_sync_stats') ?: array('synced' => 0, 'created' => 0, 'updated' => 0, 'failed' => array());
    $new_offset = $offset + count($batch_contacts);
    $new_synced = $synced + $batch_created + $batch_updated;
    $new_created = $created + $batch_created;
    $new_updated = $updated + $batch_updated;
    $stats['failed'] = array_merge($stats['failed'] ?? array(), $batch_failed);
    set_transient('zoho_contact_sync_stats', $stats, 600);
    
    // Cleanup orphaned customer lookup records after each batch (same as WooCommerce to Zoho)
    global $wpdb;
    $wpdb->query("DELETE c FROM {$wpdb->prefix}wc_customer_lookup c LEFT JOIN {$wpdb->prefix}wc_order_stats o ON c.customer_id = o.customer_id WHERE (c.user_id = 0 OR c.user_id IS NULL) AND o.order_id IS NULL");
    
    $has_more = $new_offset < $total;
    
    if (!$has_more) {
        delete_transient('zoho_contact_sync_contacts');
        delete_transient('zoho_contact_sync_stats');
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
   HELPER: Build Contact Data from WC Customer
====================================================== */
function zoho_build_contact_data_from_customer($customer) {
    // Get user data - try multiple sources
    $user_id = $customer->get_id();
    $user = get_user_by('ID', $user_id);
    
    $customer->read_meta_data();
    
    // Get first name - try billing, then user meta, then display name
    $first_name = $customer->get_billing_first_name();
    if (empty($first_name)) {
        $first_name = get_user_meta($user_id, 'billing_first_name', true);
        if (empty($first_name)) {
            $first_name = get_user_meta($user_id, 'first_name', true);
        }
    }
    
    // Get last name
    $last_name = $customer->get_billing_last_name();
    if (empty($last_name)) {
        $last_name = get_user_meta($user_id, 'billing_last_name', true);
        if (empty($last_name)) {
            $last_name = get_user_meta($user_id, 'last_name', true);
        }
    }
    
    // Get email - try multiple sources to ensure we get it
    $email = $customer->get_billing_email();
    
    // If empty, try user meta
    if (empty($email)) {
        $email = get_user_meta($user_id, 'billing_email', true);
    }
    
    // If still empty, try user object email
    if (empty($email) && $user) {
        $email = $user->user_email;
    }
    
    // Try to get email from order if still empty
    if (empty($email)) {
        // Get customer's orders to find email
        $customer_orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        if (!empty($customer_orders)) {
            $latest_order = $customer_orders[0];
            $order_email = $latest_order->get_billing_email();
            if (!empty($order_email)) {
                $email = $order_email;
                // Save it for future use
                update_user_meta($user_id, 'billing_email', $email);
                $customer->set_billing_email($email);
                $customer->save();
            }
        }
    }
    
    // Get company name
    $company_name = $customer->get_billing_company();
    if (empty($company_name)) {
        $company_name = get_user_meta($user_id, 'billing_company', true);
    }
    
    // Build contact_name (required field)
    // Priority: First Last Name > Company Name > Display Name > Username > Email
    $contact_name = trim($first_name . ' ' . $last_name);
    if (empty($contact_name) || $contact_name == ' ') {
        if (!empty($company_name)) {
            $contact_name = $company_name;
        } elseif ($user && !empty($user->display_name)) {
            $contact_name = $user->display_name;
        } elseif ($user && !empty($user->user_login)) {
            $contact_name = $user->user_login;  // Use username if name not available
        } else {
            $contact_name = $email ?: 'Customer ' . $user_id;
        }
    }
    
    // Build contact data - ensure email is always included
    $contact_data = array(
        'contact_name' => $contact_name,
        'contact_type' => 'customer',
        'customer_sub_type' => 'individual',
    );
    
    // Always include email if available
    $email = trim($email);
    if (!empty($email) && is_email($email)) {
        $contact_data['email'] = $email;
    } else {
        // If email is still empty or invalid, try one more time from user object
        if ($user && !empty($user->user_email) && is_email($user->user_email)) {
            $email = trim($user->user_email);
            $contact_data['email'] = $email;
        }
    }
    
    // Add name fields only if not empty
    if (!empty($first_name)) {
        $contact_data['first_name'] = $first_name;
    }
    if (!empty($last_name)) {
        $contact_data['last_name'] = $last_name;
    }
    
    // Add email and name to contact_persons array (required for Zoho panel display)
    $final_email = isset($contact_data['email']) ? $contact_data['email'] : $email;
    if (!empty($final_email) || !empty($first_name) || !empty($last_name)) {
        $contact_data['contact_persons'] = array();
        $contact_person = array();
        
        if (!empty($final_email) && is_email($final_email)) {
            $contact_person['email'] = $final_email;
        }
        if (!empty($first_name)) {
            $contact_person['first_name'] = $first_name;
        }
        if (!empty($last_name)) {
            $contact_person['last_name'] = $last_name;
        }
        
        // Mark as primary contact person
        $contact_person['is_primary_contact'] = true;
        
        // Add phone if available
        $billing_phone = $customer->get_billing_phone();
        if (empty($billing_phone)) {
            $billing_phone = get_user_meta($user_id, 'billing_phone', true);
        }
        if (!empty($billing_phone)) {
            $contact_person['phone'] = $billing_phone;
        }
        
        $contact_data['contact_persons'][] = $contact_person;
    }
    $company_name_meta = $customer->get_billing_company();
    if (empty($company_name_meta)) {
        $company_name_meta = get_user_meta($user_id, 'billing_company', true);
    }
    if (!empty($company_name_meta)) {
        $contact_data['company_name'] = $company_name_meta;
    }
    
    // Build billing address - include all available fields
    // Try WC_Customer methods first, then fallback to user meta
    $billing_address = array();
    $billing_address_1 = $customer->get_billing_address_1();
    if (empty($billing_address_1)) {
        $billing_address_1 = get_user_meta($user_id, 'billing_address_1', true);
    }
    
    $billing_address_2 = $customer->get_billing_address_2();
    if (empty($billing_address_2)) {
        $billing_address_2 = get_user_meta($user_id, 'billing_address_2', true);
    }
    
    $billing_city = $customer->get_billing_city();
    if (empty($billing_city)) {
        $billing_city = get_user_meta($user_id, 'billing_city', true);
    }
    
    $billing_state = $customer->get_billing_state();
    if (empty($billing_state)) {
        $billing_state = get_user_meta($user_id, 'billing_state', true);
    }
    
    $billing_postcode = $customer->get_billing_postcode();
    if (empty($billing_postcode)) {
        $billing_postcode = get_user_meta($user_id, 'billing_postcode', true);
    }
    
    $billing_country = $customer->get_billing_country();
    if (empty($billing_country)) {
        $billing_country = get_user_meta($user_id, 'billing_country', true);
    }
    
    $billing_phone = $customer->get_billing_phone();
    if (empty($billing_phone)) {
        $billing_phone = get_user_meta($user_id, 'billing_phone', true);
    }
    
    if (!empty($billing_address_1)) {
        $billing_address['address'] = $billing_address_1;
    }
    if (!empty($billing_address_2)) {
        $billing_address['street2'] = $billing_address_2;
    }
    if (!empty($billing_city)) {
        $billing_address['city'] = $billing_city;
    }
    if (!empty($billing_state)) {
        $billing_address['state'] = $billing_state;
    }
    if (!empty($billing_postcode)) {
        $billing_address['zip'] = $billing_postcode;
    }
    if (!empty($billing_country)) {
        $billing_address['country'] = $billing_country;
    }
    if (!empty($billing_phone)) {
        $billing_address['phone'] = $billing_phone;
    }
    
    // Add billing_address if it has at least one field
    // Note: Zoho uses 'address' field, not 'street'
    if (!empty($billing_address)) {
        $contact_data['billing_address'] = $billing_address;
    }
    
    // Also add phone at root level if not in billing_address or if billing_address is empty
    if (!empty($billing_phone) && empty($contact_data['phone'])) {
        $contact_data['phone'] = $billing_phone;
    }
    
    // Build shipping address - include all available fields
    // Try WC_Customer methods first, then fallback to user meta
    $shipping_address = array();
    $shipping_address_1 = $customer->get_shipping_address_1();
    if (empty($shipping_address_1)) {
        $shipping_address_1 = get_user_meta($user_id, 'shipping_address_1', true);
    }
    
    $shipping_address_2 = $customer->get_shipping_address_2();
    if (empty($shipping_address_2)) {
        $shipping_address_2 = get_user_meta($user_id, 'shipping_address_2', true);
    }
    
    $shipping_city = $customer->get_shipping_city();
    if (empty($shipping_city)) {
        $shipping_city = get_user_meta($user_id, 'shipping_city', true);
    }
    
    $shipping_state = $customer->get_shipping_state();
    if (empty($shipping_state)) {
        $shipping_state = get_user_meta($user_id, 'shipping_state', true);
    }
    
    $shipping_postcode = $customer->get_shipping_postcode();
    if (empty($shipping_postcode)) {
        $shipping_postcode = get_user_meta($user_id, 'shipping_postcode', true);
    }
    
    $shipping_country = $customer->get_shipping_country();
    if (empty($shipping_country)) {
        $shipping_country = get_user_meta($user_id, 'shipping_country', true);
    }
    
    if (!empty($shipping_address_1)) {
        $shipping_address['address'] = $shipping_address_1;
    }
    if (!empty($shipping_address_2)) {
        $shipping_address['street2'] = $shipping_address_2;
    }
    if (!empty($shipping_city)) {
        $shipping_address['city'] = $shipping_city;
    }
    if (!empty($shipping_state)) {
        $shipping_address['state'] = $shipping_state;
    }
    if (!empty($shipping_postcode)) {
        $shipping_address['zip'] = $shipping_postcode;
    }
    if (!empty($shipping_country)) {
        $shipping_address['country'] = $shipping_country;
    }
    
    // Add shipping_address if it has at least one field
    if (!empty($shipping_address)) {
        $contact_data['shipping_address'] = $shipping_address;
    }
    
    return $contact_data;
}

/* ======================================================
   HELPER: Build Contact Data from WC Customer (OLD - keeping for reference)
====================================================== */
function zoho_build_contact_data_from_customer_with_mapping($customer) {
    $fields = array(
        'contact_name', 'company_name', 'contact_type', 'customer_sub_type',
        'first_name', 'last_name', 'email', 'bill_address', 'billing_street2',
        'billing_city', 'billing_state', 'billing_zip', 'billing_country', 'billing_phone',
        'ship_address', 'shipping_street2', 'shipping_city', 'shipping_state',
        'shipping_zip', 'shipping_country'
    );
    
    $contact_data = array();
    
    foreach ($fields as $field) {
        $type = get_option('zoho_customer_' . $field . '_type', 'standard_field');
        $value = get_option('zoho_customer_' . $field . '_value', '');
        
        if ($type == 'custom_value') {
            $contact_data[$field] = $value;
        } else {
            // Map WooCommerce field to value
            switch ($value) {
                case 'billing_email': $contact_data[$field] = $customer->get_billing_email(); break;
                case 'billing_first_name': $contact_data[$field] = $customer->get_billing_first_name(); break;
                case 'billing_last_name': $contact_data[$field] = $customer->get_billing_last_name(); break;
                case 'billing_company': $contact_data[$field] = $customer->get_billing_company(); break;
                case 'billing_address_1': $contact_data[$field] = $customer->get_billing_address_1(); break;
                case 'billing_address_2': $contact_data[$field] = $customer->get_billing_address_2(); break;
                case 'billing_city': $contact_data[$field] = $customer->get_billing_city(); break;
                case 'billing_state': $contact_data[$field] = $customer->get_billing_state(); break;
                case 'billing_postcode': $contact_data[$field] = $customer->get_billing_postcode(); break;
                case 'billing_country': $contact_data[$field] = $customer->get_billing_country(); break;
                case 'billing_phone': $contact_data[$field] = $customer->get_billing_phone(); break;
                case 'shipping_first_name': $contact_data[$field] = $customer->get_shipping_first_name(); break;
                case 'shipping_last_name': $contact_data[$field] = $customer->get_shipping_last_name(); break;
                case 'shipping_company': $contact_data[$field] = $customer->get_shipping_company(); break;
                case 'shipping_address_1': $contact_data[$field] = $customer->get_shipping_address_1(); break;
                case 'shipping_address_2': $contact_data[$field] = $customer->get_shipping_address_2(); break;
                case 'shipping_city': $contact_data[$field] = $customer->get_shipping_city(); break;
                case 'shipping_state': $contact_data[$field] = $customer->get_shipping_state(); break;
                case 'shipping_postcode': $contact_data[$field] = $customer->get_shipping_postcode(); break;
                case 'shipping_country': $contact_data[$field] = $customer->get_shipping_country(); break;
            }
        }
    }
    
    return $contact_data;
}

/* ======================================================
   HELPER: Get or Create Contact in Zoho
====================================================== */
function zoho_get_or_create_contact($order) {
    $email = $order->get_billing_email();
    
    if (empty($email)) {
        return null;
    }
    
    // Check if contact exists by email
    $response = zoho_api_get('contacts?email=' . urlencode($email));
    
    if (!is_wp_error($response) && isset($response['contacts']) && !empty($response['contacts'])) {
        return $response['contacts'][0]['contact_id'];
    }
    
    // Build contact name (fallback to email if name is empty)
    $first_name = $order->get_billing_first_name();
    $last_name = $order->get_billing_last_name();
    $contact_name = trim($first_name . ' ' . $last_name);
    
    if (empty($contact_name)) {
        $contact_name = $order->get_billing_company() ?: $email;
    }
    
    // Create new contact
    $contact_data = array(
        'contact_name' => $contact_name,
        'contact_type' => 'customer',
        'customer_sub_type' => 'individual',
        'email' => $email,
    );
    
    // Add name fields if available
    if (!empty($first_name)) {
        $contact_data['first_name'] = $first_name;
    }
    if (!empty($last_name)) {
        $contact_data['last_name'] = $last_name;
    }
    
    // Add email and name to contact_persons array (required for Zoho panel display)
    if (!empty($email) || !empty($first_name) || !empty($last_name)) {
        $contact_data['contact_persons'] = array();
        $contact_person = array();
        
        if (!empty($email)) {
            $contact_person['email'] = $email;
        }
        if (!empty($first_name)) {
            $contact_person['first_name'] = $first_name;
        }
        if (!empty($last_name)) {
            $contact_person['last_name'] = $last_name;
        }
        
        // Mark as primary contact person
        $contact_person['is_primary_contact'] = true;
        
        // Add phone if available
        if (!empty($order->get_billing_phone())) {
            $contact_person['phone'] = $order->get_billing_phone();
        }
        
        $contact_data['contact_persons'][] = $contact_person;
    }
    
    // Add company if available
    if (!empty($order->get_billing_company())) {
        $contact_data['company_name'] = $order->get_billing_company();
    }
    
    // Add billing address if available
    $billing_address = array();
    if (!empty($order->get_billing_address_1())) {
        $billing_address['address'] = $order->get_billing_address_1();
    }
    if (!empty($order->get_billing_address_2())) {
        $billing_address['street2'] = $order->get_billing_address_2();
    }
    if (!empty($order->get_billing_city())) {
        $billing_address['city'] = $order->get_billing_city();
    }
    if (!empty($order->get_billing_state())) {
        $billing_address['state'] = $order->get_billing_state();
    }
    if (!empty($order->get_billing_postcode())) {
        $billing_address['zip'] = $order->get_billing_postcode();
    }
    if (!empty($order->get_billing_country())) {
        $billing_address['country'] = $order->get_billing_country();
    }
    if (!empty($order->get_billing_phone())) {
        $billing_address['phone'] = $order->get_billing_phone();
    }
    if (!empty($billing_address)) {
        $contact_data['billing_address'] = $billing_address;
    }
    
    // Add shipping address if available
    $shipping_address = array();
    if (!empty($order->get_shipping_address_1())) {
        $shipping_address['address'] = $order->get_shipping_address_1();
    }
    if (!empty($order->get_shipping_address_2())) {
        $shipping_address['street2'] = $order->get_shipping_address_2();
    }
    if (!empty($order->get_shipping_city())) {
        $shipping_address['city'] = $order->get_shipping_city();
    }
    if (!empty($order->get_shipping_state())) {
        $shipping_address['state'] = $order->get_shipping_state();
    }
    if (!empty($order->get_shipping_postcode())) {
        $shipping_address['zip'] = $order->get_shipping_postcode();
    }
    if (!empty($order->get_shipping_country())) {
        $shipping_address['country'] = $order->get_shipping_country();
    }
    if (!empty($shipping_address)) {
        $contact_data['shipping_address'] = $shipping_address;
    }
    
    $response = zoho_api_post('contacts', $contact_data);
    
    // Handle Code 3062 (contact already exists) or Code 1002 (may indicate contact exists)
    if (isset($response['code']) && ($response['code'] == 3062 || $response['code'] == 1002)) {
        // Try to find existing contact by email first (most reliable)
        if (!empty($email)) {
            $search_email = strtolower(trim($email));
            $search_response = zoho_api_get('contacts?email=' . urlencode($search_email));
            if (!is_wp_error($search_response) && isset($search_response['contacts']) && !empty($search_response['contacts'])) {
                // Check if email matches (case-insensitive)
                foreach ($search_response['contacts'] as $found_contact) {
                    $found_email = isset($found_contact['email']) ? strtolower(trim($found_contact['email'])) : '';
                    if ($found_email === $search_email) {
                        return $found_contact['contact_id'];
                    }
                }
                // If no exact email match but contacts found, use first one
                return $search_response['contacts'][0]['contact_id'];
            }
        }
        
        // DO NOT search by name - email is the unique identifier
        // If email search failed, contact doesn't exist - return null
        // Two people can have same name but different emails
        return null;
    }
    
    if (!is_wp_error($response) && isset($response['contact']['contact_id'])) {
        return $response['contact']['contact_id'];
    }
    
    return null;
}

/* ======================================================
   HELPER: Get Order by Zoho Invoice ID
   (Used by invoice.php)
====================================================== */
function zoho_get_order_by_zoho_invoice($zoho_invoice_id) {
    global $wpdb;
    
    // HPOS compatible: Search in wc_orders_meta first
    $order_id = $wpdb->get_var($wpdb->prepare(
        "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_zoho_invoice_id' AND meta_value = %s LIMIT 1",
        $zoho_invoice_id
    ));
    
    // Fallback to post meta (legacy storage)
    if (!$order_id) {
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zoho_invoice_id' AND meta_value = %s LIMIT 1",
            $zoho_invoice_id
        ));
    }
    
    return $order_id ? intval($order_id) : 0;
}

/* ======================================================
   HELPER: Get WC Customer by Zoho Contact ID
   (Used by invoice.php)
====================================================== */
function zoho_get_wc_customer_by_zoho_contact($zoho_contact_id) {
    global $wpdb;
    
    // Search in user meta for _zoho_contact_id
    $user_id = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_zoho_contact_id' AND meta_value = %s LIMIT 1",
        $zoho_contact_id
    ));
    
    return $user_id ? intval($user_id) : 0;
}

/* ======================================================
   CONTACT SYNC PAGE
====================================================== */
function zoho_contact_sync_page() {
    echo '<h1>Contact Sync</h1>';
    
    // Check connection
    $access_token = get_option('zoho_access_token', '');
    if (empty($access_token)) {
        echo '<div class="notice notice-error"><p>Please connect to Zoho first from the <a href="'.admin_url('admin.php?page=zoho-sync').'">Dashboard</a>.</p></div>';
        return;
    }
    
    echo '<p>Sync contacts/customers between Zoho Inventory and WooCommerce.</p>';
    
    // Date filters
    echo '<div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 4px;">';
    echo '<h3 style="margin-top: 0;">Date Range Filter</h3>';
    echo '<p>Leave empty to sync all contacts/customers.</p>';
    echo '<table class="form-table">';
    echo '<tr>';
    echo '<th><label for="contact_from_date">From Date</label></th>';
    echo '<td><input type="date" id="contact_from_date" name="contact_from_date" class="regular-text"></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th><label for="contact_to_date">To Date</label></th>';
    echo '<td><input type="date" id="contact_to_date" name="contact_to_date" class="regular-text"></td>';
    echo '</tr>';
    echo '</table>';
    echo '</div>';
    
    // Sync direction
    echo '<div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 4px;">';
    echo '<h3 style="margin-top: 0;">Sync Direction</h3>';
    echo '<p><label><input type="radio" name="contact_sync_direction" value="woo_to_zoho" checked> <strong>WooCommerce → Zoho:</strong> Create/Update Zoho contacts from WooCommerce customers</label></p>';
    echo '<p><label><input type="radio" name="contact_sync_direction" value="zoho_to_woo"> <strong>Zoho → WooCommerce:</strong> Create/Update WooCommerce customers from Zoho contacts</label></p>';
    echo '</div>';
    
    // Progress bar
    echo '<div id="zoho-contact-sync-progress" style="display:none; margin: 20px 0;">
        <div style="background: #f0f0f0; border-radius: 4px; padding: 3px; margin-bottom: 10px;">
            <div id="contact-progress-bar" style="background: #2271b1; height: 24px; border-radius: 3px; width: 0%; transition: width 0.3s ease; text-align: center; line-height: 24px; color: white; font-weight: bold;">
                <span id="contact-progress-percentage">0%</span>
            </div>
        </div>
        <p id="contact-progress-text" style="margin: 5px 0;">Starting sync...</p>
    </div>';
    
    echo '<div id="zoho-contact-sync-result" style="display:none; margin: 20px 0; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;"></div>';
    
    echo '<button id="zoho-start-contact-sync" class="button button-primary" type="button">Sync Contacts Now</button>';
    
    echo '<p style="margin-top: 15px;"><a href="'.admin_url('admin.php?page=zoho-logs&tab=contacts').'">View Contact Sync Logs →</a></p>';
    
    ?>
    <script>
    jQuery(document).ready(function($) {
        var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
        var ajaxNonce = '<?php echo wp_create_nonce('zoho_sync_nonce'); ?>';
        
        $('#zoho-start-contact-sync').on('click', function() {
            var $btn = $(this);
            var fromDate = $('#contact_from_date').val();
            var toDate = $('#contact_to_date').val();
            var direction = $('input[name="contact_sync_direction"]:checked').val();
            
            $btn.prop('disabled', true).text('Syncing...');
            $('#zoho-contact-sync-progress').show();
            $('#zoho-contact-sync-result').hide();
            
            syncContacts(0, 0, 0, 0, 0, direction, fromDate, toDate);
        });
        
        function syncContacts(offset, total, synced, created, updated, direction, fromDate, toDate) {
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zoho_sync_contacts_ajax',
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
                        
                        $('#contact-progress-bar').css('width', percentage + '%');
                        $('#contact-progress-percentage').text(percentage + '%');
                        $('#contact-progress-text').text('Syncing: ' + data.synced + ' / ' + data.total + ' | Created: ' + data.created + ' | Updated: ' + data.updated);
                        
                        if (data.has_more) {
                            setTimeout(function() {
                                syncContacts(data.offset, data.total, data.synced, data.created, data.updated, direction, fromDate, toDate);
                            }, 100);
                        } else {
                            $('#contact-progress-bar').css('width', '100%');
                            $('#contact-progress-percentage').text('100%');
                            $('#contact-progress-text').text('Sync completed!');
                            
                            var resultHtml = '<strong>Sync Completed!</strong> Total: ' + data.total + ' | Synced: ' + data.synced + ' | Created: ' + data.created + ' | Updated: ' + data.updated + ' | Failed: ' + data.failed;
                            
                            // Show failure reasons if any
                            if (data.failed > 0 && data.failed_reasons && data.failed_reasons.length > 0) {
                                resultHtml += '<br><br><strong>Failure Details:</strong><ul style="margin-top:5px;">';
                                for (var i = 0; i < data.failed_reasons.length; i++) {
                                    resultHtml += '<li>' + data.failed_reasons[i] + '</li>';
                                }
                                resultHtml += '</ul>';
                            }
                            
                            $('#zoho-contact-sync-result').html(resultHtml).css({
                                'background': '#d4edda',
                                'border-color': '#c3e6cb',
                                'color': '#155724'
                            }).show();
                            $('#zoho-start-contact-sync').prop('disabled', false).text('Sync Contacts Now');
                        }
                    } else {
                        $('#contact-progress-text').text('Error: ' + (response.data || 'Unknown error'));
                        $('#zoho-contact-sync-result').html('<strong style="color:red;">Error:</strong> ' + (response.data || 'Unknown error')).css({'background': '#f8d7da', 'border-color': '#f5c6cb'}).show();
                        $('#zoho-start-contact-sync').prop('disabled', false).text('Sync Contacts Now');
                    }
                },
                error: function(xhr, status, error) {
                    $('#contact-progress-text').text('Error: ' + error);
                    $('#zoho-contact-sync-result').html('<strong style="color:red;">Error:</strong> ' + error).css({'background': '#f8d7da', 'border-color': '#f5c6cb'}).show();
                    $('#zoho-start-contact-sync').prop('disabled', false).text('Sync Contacts Now');
                }
            });
        }
    });
    </script>
    <?php
}