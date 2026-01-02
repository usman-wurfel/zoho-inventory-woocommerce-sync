<?php
/**
 * Zoho Inventory Webhooks Handler - CONTACTS ONLY
 * Receives webhooks from Zoho when contacts are created/updated/deleted
 */

if (!defined('ABSPATH')) exit;

/* ======================================================
   REGISTER WEBHOOK ENDPOINTS - CONTACTS
====================================================== */
add_action('rest_api_init', 'zoho_register_contact_webhook_endpoints');
function zoho_register_contact_webhook_endpoints() {
    // Webhook endpoint for create/update: /wp-json/zoho-inventory/v1/webhook-contacts
    register_rest_route('zoho-inventory/v1', '/webhook-contacts', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'zoho_handle_contact_webhook_request',
        'permission_callback' => 'zoho_validate_contact_webhook',
    ));
    
    // Separate webhook endpoint for delete: /wp-json/zoho-inventory/v1/webhook-contacts-delete
    register_rest_route('zoho-inventory/v1', '/webhook-contacts-delete', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'zoho_handle_contact_delete_webhook',
        'permission_callback' => 'zoho_validate_contact_webhook',
    ));
}

/* ======================================================
   VALIDATE CONTACT WEBHOOK REQUEST
====================================================== */
function zoho_validate_contact_webhook($request) {
    $received_secret = $request->get_header('X-Webhook-Secret');
    
    if (!empty($received_secret)) {
        $expected_secret = get_option('zoho_webhook_secret_contacts', '');
        if (!empty($expected_secret) && $received_secret !== $expected_secret) {
            return new WP_Error('invalid_secret', 'Invalid webhook secret', array('status' => 401));
        }
    }
    
    return true;
}

/* ======================================================
   HANDLE CONTACT WEBHOOK REQUEST (Create/Update)
====================================================== */
function zoho_handle_contact_webhook_request($request) {
    $body = $request->get_json_params();
    $headers = $request->get_headers();
    
    update_option('zoho_last_webhook_contacts', current_time('mysql'));
    update_option('zoho_last_webhook_contacts_data', $body);
    
    zoho_add_detailed_log(
        'webhook',
        'webhook_contacts',
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
    
    if (get_option('zoho_sync_zoho_to_woo_contacts', 'no') !== 'yes') {
        return new WP_REST_Response(array('message' => 'Contact sync disabled'), 200);
    }
    
    $action = 'update';
    $object_id = '';
    $object_data = array();
    
    if (isset($body['contact'])) {
        $object_data = $body['contact'];
        $object_id = isset($object_data['contact_id']) ? $object_data['contact_id'] : '';
        if (isset($body['event_type'])) {
            $event_type = strtolower($body['event_type']);
            if (strpos($event_type, 'created') !== false || strpos($event_type, 'create') !== false) {
                $action = 'create';
            } else {
                $action = 'update';
            }
        }
    } elseif (isset($body['contact_id'])) {
        $object_id = $body['contact_id'];
        $object_data = isset($body['data']) ? $body['data'] : $body;
    }
    
    if (!empty($object_id) && ($action === 'create' || $action === 'update')) {
        zoho_handle_contact_webhook($action, $object_id, $object_data);
    }
    
    return new WP_REST_Response(array('message' => 'Contact webhook processed', 'action' => $action, 'contact_id' => $object_id), 200);
}

/* ======================================================
   HANDLE CONTACT DELETE WEBHOOK
====================================================== */
function zoho_handle_contact_delete_webhook($request) {
    $body = $request->get_json_params();
    $headers = $request->get_headers();
    
    update_option('zoho_last_webhook_contacts', current_time('mysql'));
    update_option('zoho_last_webhook_contacts_data', $body);
    
    zoho_add_detailed_log(
        'webhook',
        'webhook_contacts_delete',
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
    
    if (get_option('zoho_sync_zoho_to_woo_contacts', 'no') !== 'yes') {
        return new WP_REST_Response(array('message' => 'Contact sync disabled'), 200);
    }
    
    $object_id = '';
    if (isset($body['contact'])) {
        $object_id = isset($body['contact']['contact_id']) ? $body['contact']['contact_id'] : '';
    } elseif (isset($body['contact_id'])) {
        $object_id = $body['contact_id'];
    }
    
    if (!empty($object_id)) {
        zoho_delete_woo_customer_from_zoho_contact($object_id);
    }
    
    return new WP_REST_Response(array('message' => 'Contact delete webhook processed', 'contact_id' => $object_id), 200);
}

/* ======================================================
   HANDLE CONTACT WEBHOOKS
====================================================== */
function zoho_handle_contact_webhook($action, $contact_id, $contact_data) {
    if (get_option('zoho_sync_zoho_to_woo_contacts', 'no') !== 'yes') {
        return;
    }
    
    switch ($action) {
        case 'create':
        case 'update':
            zoho_sync_zoho_contact_to_woo($contact_id, $contact_data);
            break;
        case 'delete':
            zoho_delete_woo_customer_from_zoho_contact($contact_id);
            break;
    }
}

/* ======================================================
   SYNC FUNCTIONS - ZOHO TO WOOCOMMERCE
====================================================== */

/**
 * Sync Zoho contact to WooCommerce customer
 */
function zoho_sync_zoho_contact_to_woo($zoho_contact_id, $zoho_contact_data = null) {
    if (empty($zoho_contact_data)) {
        $response = zoho_api_get('contacts/' . $zoho_contact_id);
        if (is_wp_error($response) || !isset($response['contact'])) {
            return false;
        }
        $zoho_contact_data = $response['contact'];
    }
    
    global $wpdb;
    $user_id = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_zoho_contact_id' AND meta_value = %s LIMIT 1",
        $zoho_contact_id
    ));
    
    if (empty($user_id) && !empty($zoho_contact_data['email'])) {
        $user = get_user_by('email', $zoho_contact_data['email']);
        if ($user) {
            $user_id = $user->ID;
        }
    }
    
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
    // Extract email - check root level first, then contact_persons
    $email = '';
    if (!empty($zoho_contact_data['email'])) {
        $email = trim($zoho_contact_data['email']);
    } elseif (isset($zoho_contact_data['contact_persons']) && is_array($zoho_contact_data['contact_persons'])) {
        foreach ($zoho_contact_data['contact_persons'] as $person) {
            if (!empty($person['email'])) {
                $email = trim($person['email']);
                break;
            }
        }
    }
    
    if (empty($email)) {
        return false;
    }
    
    $username = sanitize_user($email);
    if (username_exists($username)) {
        $username = $username . '_' . time();
    }
    
    $user_id = wp_create_user($username, wp_generate_password(), $email);
    
    if (is_wp_error($user_id)) {
        return false;
    }
    
    $user = new WP_User($user_id);
    $user->set_role('customer');
    
    // Extract first_name - check root level first, then contact_persons
    $first_name = '';
    if (!empty($zoho_contact_data['first_name'])) {
        $first_name = trim($zoho_contact_data['first_name']);
    } elseif (isset($zoho_contact_data['contact_persons']) && is_array($zoho_contact_data['contact_persons'])) {
        foreach ($zoho_contact_data['contact_persons'] as $person) {
            if (!empty($person['first_name'])) {
                $first_name = trim($person['first_name']);
                break;
            }
        }
    }
    if (!empty($first_name)) {
        update_user_meta($user_id, 'first_name', $first_name);
    }
    
    // Extract last_name - check root level first, then contact_persons
    $last_name = '';
    if (!empty($zoho_contact_data['last_name'])) {
        $last_name = trim($zoho_contact_data['last_name']);
    } elseif (isset($zoho_contact_data['contact_persons']) && is_array($zoho_contact_data['contact_persons'])) {
        foreach ($zoho_contact_data['contact_persons'] as $person) {
            if (!empty($person['last_name'])) {
                $last_name = trim($person['last_name']);
                break;
            }
        }
    }
    if (!empty($last_name)) {
        update_user_meta($user_id, 'last_name', $last_name);
    }
    
    // Extract phone - check root level, then contact_persons, then billing_address
    $phone = '';
    if (!empty($zoho_contact_data['phone'])) {
        $phone = trim($zoho_contact_data['phone']);
    } elseif (!empty($zoho_contact_data['mobile'])) {
        $phone = trim($zoho_contact_data['mobile']);
    } elseif (isset($zoho_contact_data['contact_persons']) && is_array($zoho_contact_data['contact_persons'])) {
        foreach ($zoho_contact_data['contact_persons'] as $person) {
            if (!empty($person['phone'])) {
                $phone = trim($person['phone']);
                break;
            } elseif (!empty($person['mobile'])) {
                $phone = trim($person['mobile']);
                break;
            }
        }
    } elseif (isset($zoho_contact_data['billing_address']['phone']) && !empty($zoho_contact_data['billing_address']['phone'])) {
        $phone = trim($zoho_contact_data['billing_address']['phone']);
    }
    if (!empty($phone)) {
        update_user_meta($user_id, 'billing_phone', $phone);
    }
    
    // Extract billing address
    if (isset($zoho_contact_data['billing_address']) && is_array($zoho_contact_data['billing_address'])) {
        $billing = $zoho_contact_data['billing_address'];
        if (!empty($billing['address'])) {
            update_user_meta($user_id, 'billing_address_1', trim($billing['address']));
        }
        if (!empty($billing['city'])) {
            update_user_meta($user_id, 'billing_city', trim($billing['city']));
        }
        if (!empty($billing['state'])) {
            update_user_meta($user_id, 'billing_state', trim($billing['state']));
        }
        if (!empty($billing['zip'])) {
            update_user_meta($user_id, 'billing_postcode', trim($billing['zip']));
        }
        if (!empty($billing['country'])) {
            update_user_meta($user_id, 'billing_country', trim($billing['country']));
        }
    }
    
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
    // Extract and update first_name - check root level first, then contact_persons
    $first_name = '';
    if (!empty($zoho_contact_data['first_name'])) {
        $first_name = trim($zoho_contact_data['first_name']);
    } elseif (isset($zoho_contact_data['contact_persons']) && is_array($zoho_contact_data['contact_persons'])) {
        foreach ($zoho_contact_data['contact_persons'] as $person) {
            if (!empty($person['first_name'])) {
                $first_name = trim($person['first_name']);
                break;
            }
        }
    }
    if (!empty($first_name)) {
        update_user_meta($user_id, 'first_name', $first_name);
    }
    
    // Extract and update last_name - check root level first, then contact_persons
    $last_name = '';
    if (!empty($zoho_contact_data['last_name'])) {
        $last_name = trim($zoho_contact_data['last_name']);
    } elseif (isset($zoho_contact_data['contact_persons']) && is_array($zoho_contact_data['contact_persons'])) {
        foreach ($zoho_contact_data['contact_persons'] as $person) {
            if (!empty($person['last_name'])) {
                $last_name = trim($person['last_name']);
                break;
            }
        }
    }
    if (!empty($last_name)) {
        update_user_meta($user_id, 'last_name', $last_name);
    }
    
    // Extract and update email - check root level first, then contact_persons
    $email = '';
    if (!empty($zoho_contact_data['email'])) {
        $email = trim($zoho_contact_data['email']);
    } elseif (isset($zoho_contact_data['contact_persons']) && is_array($zoho_contact_data['contact_persons'])) {
        foreach ($zoho_contact_data['contact_persons'] as $person) {
            if (!empty($person['email'])) {
                $email = trim($person['email']);
                break;
            }
        }
    }
    if (!empty($email)) {
        wp_update_user(array('ID' => $user_id, 'user_email' => $email));
    }
    
    // Extract and update phone - check root level, then contact_persons, then billing_address
    $phone = '';
    if (!empty($zoho_contact_data['phone'])) {
        $phone = trim($zoho_contact_data['phone']);
    } elseif (!empty($zoho_contact_data['mobile'])) {
        $phone = trim($zoho_contact_data['mobile']);
    } elseif (isset($zoho_contact_data['contact_persons']) && is_array($zoho_contact_data['contact_persons'])) {
        foreach ($zoho_contact_data['contact_persons'] as $person) {
            if (!empty($person['phone'])) {
                $phone = trim($person['phone']);
                break;
            } elseif (!empty($person['mobile'])) {
                $phone = trim($person['mobile']);
                break;
            }
        }
    } elseif (isset($zoho_contact_data['billing_address']['phone']) && !empty($zoho_contact_data['billing_address']['phone'])) {
        $phone = trim($zoho_contact_data['billing_address']['phone']);
    }
    if (!empty($phone)) {
        update_user_meta($user_id, 'billing_phone', $phone);
    }
    
    // Extract and update billing address
    if (isset($zoho_contact_data['billing_address']) && is_array($zoho_contact_data['billing_address'])) {
        $billing = $zoho_contact_data['billing_address'];
        if (!empty($billing['address'])) {
            update_user_meta($user_id, 'billing_address_1', trim($billing['address']));
        }
        if (!empty($billing['city'])) {
            update_user_meta($user_id, 'billing_city', trim($billing['city']));
        }
        if (!empty($billing['state'])) {
            update_user_meta($user_id, 'billing_state', trim($billing['state']));
        }
        if (!empty($billing['zip'])) {
            update_user_meta($user_id, 'billing_postcode', trim($billing['zip']));
        }
        if (!empty($billing['country'])) {
            update_user_meta($user_id, 'billing_country', trim($billing['country']));
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

