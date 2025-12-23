<?php
/**
 * Plugin Name: Zoho Inventory WooCommerce Sync
 * Description: Zoho Inventory → WooCommerce Inventory Sync
 * Version: 1.2.1
 * Author: Skyler Rubber
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/zoho-api.php';

/* ======================================================
   FIX ADMIN URLS - ENSURE WP-ADMIN IS INCLUDED
====================================================== */
add_filter('admin_url', 'zoho_fix_admin_url', 999, 3);
function zoho_fix_admin_url($url, $path, $blog_id) {
    // Get site URL
    $site_url = rtrim(get_site_url($blog_id), '/');
    
    // If URL contains site URL but doesn't contain wp-admin, fix it
    if (strpos($url, $site_url) !== false && strpos($url, '/wp-admin/') === false) {
        // If URL starts with site_url/admin.php or site_url/admin-, add wp-admin/
        $url = preg_replace(
            '#^' . preg_quote($site_url, '#') . '/(admin\.php|admin-[^/]+)#',
            $site_url . '/wp-admin/$1',
            $url
        );
    }
    
    return $url;
}

/* ======================================================
   ADMIN MENU
====================================================== */
add_action('admin_menu', 'zoho_register_admin_menu');
function zoho_register_admin_menu() {

    if (!current_user_can('manage_options')) return;

    add_menu_page(
        'Zoho Sync',
        'Zoho Sync',
        'manage_options',
        'zoho-sync',
        'zoho_dashboard_page',
        'dashicons-update',
        56
    );

    add_submenu_page(
        'zoho-sync',
        'Dashboard',
        'Dashboard',
        'manage_options',
        'zoho-sync',
        'zoho_dashboard_page'
    );

    add_submenu_page(
        'zoho-sync',
        'Field Mapping',
        'Field Mapping',
        'manage_options',
        'zoho-field-mapping',
        'zoho_field_mapping_page'
    );

    add_submenu_page(
        'zoho-sync',
        'Inventory Sync',
        'Inventory Sync',
        'manage_options',
        'zoho-inventory-sync',
        'zoho_inventory_sync_page'
    );

    add_submenu_page(
        'zoho-sync',
        'Invoice Sync',
        'Invoice Sync',
        'manage_options',
        'zoho-invoice-sync',
        'zoho_invoice_sync_page'
    );

    add_submenu_page(
        'zoho-sync',
        'Contact Sync',
        'Contact Sync',
        'manage_options',
        'zoho-contact-sync',
        'zoho_contact_sync_page'
    );

    add_submenu_page(
        'zoho-sync',
        'Sync Logs',
        'Logs',
        'manage_options',
        'zoho-logs',
        'zoho_logs_page'
    );
}

/* ======================================================
   ENQUEUE SCRIPTS FOR PROGRESS BAR
====================================================== */
add_action('admin_enqueue_scripts', 'zoho_enqueue_sync_scripts');
function zoho_enqueue_sync_scripts($hook) {
    // Only load on our sync pages
    $allowed_hooks = array(
        'toplevel_page_zoho-sync',
        'zoho-sync_page_zoho-inventory-sync',
        'zoho-sync_page_zoho-field-mapping',
        'zoho-sync_page_zoho-invoice-sync',
        'zoho-sync_page_zoho-contact-sync'
    );
    if (!in_array($hook, $allowed_hooks)) {
        return;
    }
    
    // Add custom CSS for better styling
    wp_add_inline_style('wp-admin', '
        /* Zoho Sync Dashboard Styles */
        .zoho-dashboard-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .zoho-stat-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 25px;
            border-radius: 4px;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .zoho-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,.1);
        }
        .zoho-stat-number {
            font-size: 48px;
            font-weight: 600;
            color: #2271b1;
            margin: 15px 0;
        }
        .zoho-stat-label {
            margin: 0 0 15px 0;
            font-size: 14px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .zoho-status-badge {
            padding: 5px 15px;
            border-radius: 3px;
            font-weight: 600;
            font-size: 13px;
            display: inline-block;
        }
        .zoho-status-connected {
            background: #46b450;
            color: #fff;
        }
        .zoho-status-disconnected {
            background: #dc3232;
            color: #fff;
        }
        
        /* Enhanced Tab Styles */
        .nav-tab-wrapper {
            border-bottom: 2px solid #2271b1;
            margin-bottom: 20px;
        }
        .nav-tab {
            border: 1px solid #ccd0d4;
            border-bottom: none;
            background: #f6f7f7;
            color: #50575e;
            padding: 8px 15px;
            margin-left: 0.5em;
            margin-bottom: -1px;
            transition: all 0.2s;
        }
        .nav-tab:hover {
            background: #fff;
            color: #2271b1;
        }
        .nav-tab-active {
            background: #fff;
            color: #2271b1;
            border-bottom-color: #fff;
            position: relative;
            z-index: 1;
        }
        .nav-tab-active:focus {
            box-shadow: none;
            outline: none;
        }
    ');
    
    // Load sync script on inventory sync page only (invoice/contact use inline scripts)
    if ($hook == 'zoho-sync_page_zoho-inventory-sync') {
        wp_enqueue_script('zoho-sync-script', plugin_dir_url(__FILE__) . 'js/zoho-sync.js', array('jquery'), '1.0.0', true);
        wp_localize_script('zoho-sync-script', 'zohoSync', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('zoho_sync_nonce')
        ));
    }
}

/* ======================================================
   ZOHO OAUTH CALLBACK HANDLER
====================================================== */
add_action('admin_init', function () {

    if (!current_user_can('manage_options')) return;

    // OAuth callback handler - code se token exchange karo
    if (isset($_GET['code']) && isset($_GET['page']) && $_GET['page'] == 'zoho-sync') {
        
        $code = sanitize_text_field($_GET['code']);
        $client_id = get_option('zoho_client_id');
        $client_secret = get_option('zoho_client_secret');
        $redirect_uri = get_option('zoho_redirect_uri');
        
        if (empty($code) || empty($client_id) || empty($client_secret)) {
            wp_redirect(admin_url('admin.php?page=zoho-sync&error=missing_credentials'));
            exit;
        }
        
        // Token exchange API call
        $response = wp_remote_post('https://accounts.zoho.com/oauth/v2/token', [
            'body' => [
                'grant_type' => 'authorization_code',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri' => $redirect_uri,
                'code' => $code
            ],
            'timeout' => 20
        ]);
        
        if (is_wp_error($response)) {
            wp_redirect(admin_url('admin.php?page=zoho-sync&error=token_exchange_failed'));
            exit;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['access_token'])) {
            // Access token aur refresh token save karo
            update_option('zoho_access_token', $data['access_token']);
            if (isset($data['refresh_token'])) {
                update_option('zoho_refresh_token', $data['refresh_token']);
            }
            if (isset($data['expires_in'])) {
                update_option('zoho_token_expires_at', time() + $data['expires_in']);
            }
            wp_redirect(admin_url('admin.php?page=zoho-sync&connected=1'));
        } else {
            $error_msg = isset($data['error']) ? $data['error'] : 'Unknown error';
            wp_redirect(admin_url('admin.php?page=zoho-sync&error=' . urlencode($error_msg)));
        }
        exit;
    }

    // Delete log handler
    if (isset($_GET['zoho_delete_log']) && isset($_GET['_wpnonce'])) {

        if (!wp_verify_nonce($_GET['_wpnonce'], 'zoho_delete_log')) {
            wp_die('Security check failed');
        }

        $log_id = sanitize_text_field($_GET['zoho_delete_log']);
        $logs = get_option('zoho_inventory_logs', []);

        // Filter out the log to delete
        $filtered_logs = array_filter($logs, function($log) use ($log_id) {
            return $log['id'] !== $log_id;
        });

        // Re-index array aur save karo
        $filtered_logs = array_values($filtered_logs);
        
        // IMPORTANT: Update option BEFORE redirect
        update_option('zoho_inventory_logs', $filtered_logs);
        
        // Force refresh option cache
        wp_cache_delete('zoho_inventory_logs', 'options');

        wp_redirect(admin_url('admin.php?page=zoho-inventory-sync&deleted=1'));
        exit;
    }
});

/* ======================================================
   DASHBOARD PAGE
====================================================== */
function zoho_dashboard_page() {

    if (isset($_POST['save_zoho_credentials'])) {
        // Pehle existing connection clear karo (agar hai)
        delete_option('zoho_access_token');
        delete_option('zoho_refresh_token');
        delete_option('zoho_token_expires_at');
        
        // Credentials update karo
        update_option('zoho_client_id', sanitize_text_field($_POST['client_id']));
        update_option('zoho_client_secret', sanitize_text_field($_POST['client_secret']));
        update_option('zoho_redirect_uri', esc_url_raw($_POST['redirect_uri']));
        
        // Automatically connect karo - Zoho auth URL par redirect karo
        wp_redirect(zoho_auth_url());
        exit;
    }

    // Show connection success message
    if (isset($_GET['connected']) && $_GET['connected'] == '1') {
        echo '<div class="notice notice-success is-dismissible"><p>Successfully connected to Zoho! New token saved.</p></div>';
    }

    // Show error messages
    if (isset($_GET['error'])) {
        $error = sanitize_text_field($_GET['error']);
        echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> '.esc_html($error).'</p></div>';
    }

    $connected = get_option('zoho_access_token');
    $refresh_token = get_option('zoho_refresh_token');
    $token_expires_at = get_option('zoho_token_expires_at');
    $default_redirect_uri = admin_url('admin.php?page=zoho-sync');
    
    // Calculate token expiry time
    $expires_in = '';
    if ($token_expires_at && $connected) {
        $time_left = $token_expires_at - time();
        if ($time_left > 0) {
            $minutes = round($time_left / 60);
            $expires_in = $minutes . ' minutes';
        }
    }

    // Get sync statistics
    $inventory_logs = get_option('zoho_inventory_logs', []);
    $total_items_synced = 0;
    if (!empty($inventory_logs)) {
        $latest_log = end($inventory_logs);
        $total_items_synced = isset($latest_log['synced']) ? $latest_log['synced'] : 0;
    }
    
    // Count total products in WooCommerce
    $total_products = wp_count_posts('product');
    $published_products = $total_products->publish ?? 0;

    // Count total invoices (orders) - placeholder for now
    $total_invoices = 0; // Will be updated when invoice sync is implemented
    
    ?>
    <div class="wrap">
        <h1 style="margin-bottom: 20px;">Zoho Inventory Dashboard</h1>
        
        <!-- Connection Status Card -->
        <div style="background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin-bottom: 20px; border-radius: 4px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h2 style="margin: 0; font-size: 18px;">Zoho Inventory Connection Status</h2>
                <?php if ($connected): ?>
                    <span class="zoho-status-badge zoho-status-connected">Connected</span>
                <?php else: ?>
                    <span class="zoho-status-badge zoho-status-disconnected">Not Connected</span>
                <?php endif; ?>
            </div>
            
            <?php if ($connected): ?>
                <div style="margin-bottom: 15px;">
                    <?php if ($expires_in): ?>
                        <p style="margin: 5px 0; color: #666;">
                            <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                            Access token will expire in <?php echo esc_html($expires_in); ?>.
                        </p>
                    <?php endif; ?>
                </div>
                
                <div style="margin-top: 15px;">
                    <a href="<?php echo esc_url(zoho_auth_url()); ?>" class="button button-secondary" style="margin-right: 10px;">
                        Reauthorize
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=zoho-sync&disconnect=1&_wpnonce=' . wp_create_nonce('zoho_disconnect'))); ?>" class="button button-link-delete" onclick="return confirm('Are you sure you want to disconnect?');">
                        Disconnect
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Sync Statistics Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
            <!-- Items Synced Card -->
            <div class="zoho-stat-card">
                <h3 class="zoho-stat-label">Items Synced</h3>
                <div class="zoho-stat-number"><?php echo number_format($published_products); ?></div>
            </div>
            
            <!-- Invoices Synced Card -->
            <div class="zoho-stat-card">
                <h3 class="zoho-stat-label">Invoices Synced</h3>
                <div class="zoho-stat-number"><?php echo number_format($total_invoices); ?></div>
            </div>
            
            <!-- Customers Synced Card -->
            <div class="zoho-stat-card">
                <h3 class="zoho-stat-label">Customers Synced</h3>
                <div class="zoho-stat-number">0</div>
            </div>
        </div>
        
        <!-- Connection Settings - Only show if not connected -->
        <?php if (!$connected): ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; border-radius: 4px;">
            <h2 style="margin-top: 0; font-size: 18px; margin-bottom: 20px;">Connection Settings</h2>
            
    <form method="post">
    <table class="form-table">
                    <tr>
                        <th scope="row"><label for="client_id">Client ID</label></th>
                        <td>
                            <input name="client_id" type="text" id="client_id" value="<?php echo esc_attr(get_option('zoho_client_id')); ?>" class="regular-text" required>
                            <p class="description">Your Zoho API Client ID</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="client_secret">Client Secret</label></th>
                        <td>
                            <input name="client_secret" type="password" id="client_secret" value="<?php echo esc_attr(get_option('zoho_client_secret')); ?>" class="regular-text" required>
                            <p class="description">Your Zoho API Client Secret</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="redirect_uri">Redirect URI</label></th>
                        <td>
                            <input name="redirect_uri" type="url" id="redirect_uri" value="<?php echo esc_attr(get_option('zoho_redirect_uri', $default_redirect_uri)); ?>" class="regular-text" required>
                            <p class="description">Configure this redirect URI in your Zoho App settings.</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Update & Connect', 'primary', 'save_zoho_credentials'); ?>
            </form>
            <p class="description" style="margin-top: 15px;">After updating credentials, you will be automatically redirected to connect with Zoho.</p>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

// Handle disconnect
add_action('admin_init', function() {
    if (isset($_GET['disconnect']) && isset($_GET['_wpnonce']) && current_user_can('manage_options')) {
        if (wp_verify_nonce($_GET['_wpnonce'], 'zoho_disconnect')) {
            delete_option('zoho_access_token');
            delete_option('zoho_refresh_token');
            delete_option('zoho_token_expires_at');
            wp_redirect(admin_url('admin.php?page=zoho-sync&disconnected=1'));
            exit;
        }
    }
    
    if (isset($_GET['disconnected'])) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>Disconnected from Zoho successfully.</p></div>';
        });
    }
});

/* ======================================================
   AUTH URL
====================================================== */
function zoho_auth_url() {
    return 'https://accounts.zoho.com/oauth/v2/auth?scope=ZohoInventory.FullAccess.all'
        .'&client_id='.get_option('zoho_client_id')
        .'&response_type=code'
        .'&access_type=offline'
        .'&redirect_uri='.urlencode(get_option('zoho_redirect_uri'));
}

/* ======================================================
   FIELD MAPPING PAGE
====================================================== */
add_action('admin_init', function() {
    if (!current_user_can('manage_options')) return;
    
    // Save field mapping settings
    if (isset($_POST['save_zoho_field_mapping']) && check_admin_referer('zoho_field_mapping_nonce')) {
        
        // Save toggle settings
        $toggle_settings = array(
            'sync_name' => isset($_POST['sync_name']) ? 'yes' : 'no',
            'sync_sku' => isset($_POST['sync_sku']) ? 'yes' : 'no',
            'sync_price' => isset($_POST['sync_price']) ? 'yes' : 'no',
            'sync_image' => isset($_POST['sync_image']) ? 'yes' : 'no',
            'sync_description' => isset($_POST['sync_description']) ? 'yes' : 'no',
            'sync_cost_price' => isset($_POST['sync_cost_price']) ? 'yes' : 'no',
            'sync_stock' => isset($_POST['sync_stock']) ? 'yes' : 'no',
            'sync_reorder_point' => isset($_POST['sync_reorder_point']) ? 'yes' : 'no',
            'sync_weight' => isset($_POST['sync_weight']) ? 'yes' : 'no',
            'sync_dimensions' => isset($_POST['sync_dimensions']) ? 'yes' : 'no',
            'sync_tax' => isset($_POST['sync_tax']) ? 'yes' : 'no',
            'sync_upc' => isset($_POST['sync_upc']) ? 'yes' : 'no',
            'sync_ean' => isset($_POST['sync_ean']) ? 'yes' : 'no',
            'sync_isbn' => isset($_POST['sync_isbn']) ? 'yes' : 'no',
            'sync_mpn' => isset($_POST['sync_mpn']) ? 'yes' : 'no',
            'sync_brand' => isset($_POST['sync_brand']) ? 'yes' : 'no',
            'sync_manufacturer' => isset($_POST['sync_manufacturer']) ? 'yes' : 'no',
        );
        
        foreach ($toggle_settings as $key => $value) {
            update_option('zoho_' . $key, $value);
        }
        
        // Save field mappings
        $field_mappings = array(
            'zoho_name_field' => sanitize_text_field($_POST['zoho_name_field'] ?? 'name'),
            'zoho_sku_field' => sanitize_text_field($_POST['zoho_sku_field'] ?? 'sku'),
            'zoho_price_field' => sanitize_text_field($_POST['zoho_price_field'] ?? 'rate'),
            'zoho_image_field' => sanitize_text_field($_POST['zoho_image_field'] ?? 'image'),
            'zoho_description_field' => sanitize_text_field($_POST['zoho_description_field'] ?? 'description'),
            'zoho_cost_price_field' => sanitize_text_field($_POST['zoho_cost_price_field'] ?? 'purchase_rate'),
            'zoho_stock_field' => sanitize_text_field($_POST['zoho_stock_field'] ?? 'stock_on_hand'),
            'zoho_reorder_point_field' => sanitize_text_field($_POST['zoho_reorder_point_field'] ?? 'reorder_level'),
            'zoho_weight_field' => sanitize_text_field($_POST['zoho_weight_field'] ?? 'weight'),
            'zoho_length_field' => sanitize_text_field($_POST['zoho_length_field'] ?? 'length'),
            'zoho_width_field' => sanitize_text_field($_POST['zoho_width_field'] ?? 'width'),
            'zoho_height_field' => sanitize_text_field($_POST['zoho_height_field'] ?? 'height'),
            'zoho_tax_field' => sanitize_text_field($_POST['zoho_tax_field'] ?? 'tax_id'),
            'zoho_upc_field' => sanitize_text_field($_POST['zoho_upc_field'] ?? 'upc'),
            'zoho_ean_field' => sanitize_text_field($_POST['zoho_ean_field'] ?? 'ean'),
            'zoho_isbn_field' => sanitize_text_field($_POST['zoho_isbn_field'] ?? 'isbn'),
            'zoho_mpn_field' => sanitize_text_field($_POST['zoho_mpn_field'] ?? 'part_number'),
            'zoho_brand_field' => sanitize_text_field($_POST['zoho_brand_field'] ?? 'brand'),
            'zoho_manufacturer_field' => sanitize_text_field($_POST['zoho_manufacturer_field'] ?? 'manufacturer'),
        );
        
        foreach ($field_mappings as $key => $value) {
            update_option($key, $value);
        }
        
        wp_redirect(admin_url('admin.php?page=zoho-field-mapping&tab=items&saved=1'));
        exit;
    }
    
    // Save invoice mapping settings
    if (isset($_POST['save_zoho_invoice_mapping']) && check_admin_referer('zoho_field_mapping_nonce')) {
        // Save invoice field mappings
        update_option('zoho_invoice_customer_id_type', sanitize_text_field($_POST['invoice_customer_id_type'] ?? 'standard_field'));
        $customer_id_value = ($_POST['invoice_customer_id_type'] == 'custom_value') ? 
            sanitize_text_field($_POST['invoice_customer_id_custom_value'] ?? '') : 
            sanitize_text_field($_POST['invoice_customer_id_value'] ?? 'shop_order_billing_email');
        update_option('zoho_invoice_customer_id_value', $customer_id_value);
        
        update_option('zoho_invoice_reference_number_type', sanitize_text_field($_POST['invoice_reference_number_type'] ?? 'custom_value'));
        $ref_number_value = ($_POST['invoice_reference_number_type'] == 'custom_value') ? 
            sanitize_text_field($_POST['invoice_reference_number_custom_value'] ?? 'woo-order-{shop_order_order_id}') : 
            sanitize_text_field($_POST['invoice_reference_number_value'] ?? '');
        update_option('zoho_invoice_reference_number_value', $ref_number_value);
        
        update_option('zoho_invoice_date_type', sanitize_text_field($_POST['invoice_date_type'] ?? 'standard_field'));
        $date_value = ($_POST['invoice_date_type'] == 'custom_value') ? 
            sanitize_text_field($_POST['invoice_date_custom_value'] ?? '') : 
            sanitize_text_field($_POST['invoice_date_value'] ?? 'shop_order_order_create_date');
        update_option('zoho_invoice_date_value', $date_value);
        
        update_option('zoho_invoice_invoice_number_type', sanitize_text_field($_POST['invoice_invoice_number_type'] ?? 'custom_value'));
        $invoice_number_value = ($_POST['invoice_invoice_number_type'] == 'custom_value') ? 
            sanitize_text_field($_POST['invoice_invoice_number_custom_value'] ?? 'woo{shop_order_order_id}') : 
            sanitize_text_field($_POST['invoice_invoice_number_value'] ?? '');
        update_option('zoho_invoice_invoice_number_value', $invoice_number_value);
        
        update_option('zoho_invoice_reason_type', sanitize_text_field($_POST['invoice_reason_type'] ?? 'custom_value'));
        $reason_value = ($_POST['invoice_reason_type'] == 'custom_value') ? 
            sanitize_text_field($_POST['invoice_reason_custom_value'] ?? 'Invoice create or update') : 
            sanitize_text_field($_POST['invoice_reason_value'] ?? '');
        update_option('zoho_invoice_reason_value', $reason_value);
        
        wp_redirect(admin_url('admin.php?page=zoho-field-mapping&tab=invoices&saved=1'));
        exit;
    }
    
    // Save customer mapping settings
    if (isset($_POST['save_zoho_customer_mapping']) && check_admin_referer('zoho_field_mapping_nonce')) {
        $customer_fields = array(
            'contact_name', 'company_name', 'contact_type', 'customer_sub_type',
            'first_name', 'last_name', 'email', 'bill_address', 'billing_street2',
            'billing_city', 'billing_state', 'billing_zip', 'billing_country', 'billing_phone',
            'ship_address', 'shipping_street2', 'shipping_city', 'shipping_state',
            'shipping_zip', 'shipping_country'
        );
        
        foreach ($customer_fields as $field) {
            $type = sanitize_text_field($_POST['customer_' . $field . '_type'] ?? 'standard_field');
            update_option('zoho_customer_' . $field . '_type', $type);
            
            $value = ($type == 'custom_value') ? 
                sanitize_text_field($_POST['customer_' . $field . '_custom_value'] ?? '') : 
                sanitize_text_field($_POST['customer_' . $field . '_value'] ?? '');
            update_option('zoho_customer_' . $field . '_value', $value);
        }
        
        wp_redirect(admin_url('admin.php?page=zoho-field-mapping&tab=customers&saved=1'));
        exit;
    }
});

function zoho_field_mapping_page() {
    // Show success message
    if (isset($_GET['saved'])) {
        echo '<div class="notice notice-success"><p>Field mapping settings saved successfully!</p></div>';
    }
    
    // Get current tab
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'items';
    
    echo '<h1>Field Mapping & Sync Settings</h1>';
    
    // Tabs
    echo '<nav class="nav-tab-wrapper">';
    echo '<a href="'.admin_url('admin.php?page=zoho-field-mapping&tab=items').'" class="nav-tab '.($current_tab == 'items' ? 'nav-tab-active' : '').'">Items</a>';
    echo '<a href="'.admin_url('admin.php?page=zoho-field-mapping&tab=invoices').'" class="nav-tab '.($current_tab == 'invoices' ? 'nav-tab-active' : '').'">Invoices</a>';
    echo '<a href="'.admin_url('admin.php?page=zoho-field-mapping&tab=customers').'" class="nav-tab '.($current_tab == 'customers' ? 'nav-tab-active' : '').'">Customers</a>';
    echo '</nav>';
    
    echo '<div class="tab-content" style="margin-top: 20px;">';
    
    // Show appropriate tab content
    if ($current_tab == 'items') {
        zoho_field_mapping_items_tab();
    } elseif ($current_tab == 'invoices') {
        zoho_field_mapping_invoices_tab();
    } elseif ($current_tab == 'customers') {
        zoho_field_mapping_customers_tab();
    }
    
    echo '</div>';
}

/* ======================================================
   ITEMS MAPPING TAB
====================================================== */
function zoho_field_mapping_items_tab() {
    echo '<p>Configure which attributes to sync and map Zoho fields to WooCommerce fields for products.</p>';
    
    // Get saved settings with defaults
    $sync_name = get_option('zoho_sync_name', 'yes');
    $sync_sku = get_option('zoho_sync_sku', 'yes');
    $sync_price = get_option('zoho_sync_price', 'yes');
    $sync_image = get_option('zoho_sync_image', 'no');
    $sync_description = get_option('zoho_sync_description', 'yes');
    $sync_cost_price = get_option('zoho_sync_cost_price', 'no');
    $sync_stock = get_option('zoho_sync_stock', 'yes');
    $sync_reorder_point = get_option('zoho_sync_reorder_point', 'no');
    $sync_weight = get_option('zoho_sync_weight', 'no');
    $sync_dimensions = get_option('zoho_sync_dimensions', 'no');
    $sync_tax = get_option('zoho_sync_tax', 'no');
    $sync_upc = get_option('zoho_sync_upc', 'no');
    $sync_ean = get_option('zoho_sync_ean', 'no');
    $sync_isbn = get_option('zoho_sync_isbn', 'no');
    $sync_mpn = get_option('zoho_sync_mpn', 'no');
    $sync_brand = get_option('zoho_sync_brand', 'no');
    $sync_manufacturer = get_option('zoho_sync_manufacturer', 'no');
    
    // Zoho field options
    $zoho_fields = array(
        'name' => 'Name',
        'sku' => 'SKU',
        'rate' => 'Selling Price (rate)',
        'purchase_rate' => 'Purchase Rate (Cost Price)',
        'description' => 'Description',
        'image' => 'Image',
        'stock_on_hand' => 'Stock on Hand',
        'reorder_level' => 'Reorder Level',
        'weight' => 'Weight',
        'length' => 'Length',
        'width' => 'Width',
        'height' => 'Height',
        'tax_id' => 'Tax ID',
        'is_taxable' => 'Is Taxable',
        'upc' => 'UPC',
        'ean' => 'EAN',
        'isbn' => 'ISBN',
        'part_number' => 'Part Number (MPN)',
        'brand' => 'Brand',
        'manufacturer' => 'Manufacturer',
        'unit' => 'Unit',
        'hsn_or_sac' => 'HSN/SAC',
    );
    
    // WooCommerce field options
    $wc_fields = array(
        'product_name' => 'Product Name',
        'sku' => 'SKU',
        'regular_price' => 'Regular Price',
        'sale_price' => 'Sale Price',
        'description' => 'Description',
        'short_description' => 'Short Description',
        'image' => 'Product Image',
        'stock_quantity' => 'Stock Quantity',
        'low_stock_threshold' => 'Low Stock Threshold',
        'weight' => 'Weight',
        'length' => 'Length',
        'width' => 'Width',
        'height' => 'Height',
        'tax_status' => 'Tax Status',
        'tax_class' => 'Tax Class',
        'gtin' => 'GTIN',
        'barcode' => 'Barcode',
        'attribute_brand' => 'Attribute: Brand',
        'attribute_manufacturer' => 'Attribute: Manufacturer',
        'cost_of_goods' => 'Cost of Goods (COG)',
    );
    
    echo '<form method="post">';
    wp_nonce_field('zoho_field_mapping_nonce');
    
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>
        <th style="width: 200px;">Attribute</th>
        <th style="width: 100px;">Enable Sync</th>
        <th style="width: 250px;">Zoho Field</th>
        <th style="width: 250px;">WooCommerce Field</th>
    </tr></thead><tbody>';
    
    // Name mapping
    echo '<tr>
        <td><strong>Product Name</strong></td>
        <td><input type="checkbox" name="sync_name" value="1" '.checked($sync_name, 'yes', false).'></td>
        <td><select name="zoho_name_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_name_field', 'name'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>Product Name (fixed)</td>
    </tr>';
    
    // SKU mapping
    echo '<tr>
        <td><strong>SKU</strong></td>
        <td><input type="checkbox" name="sync_sku" value="1" '.checked($sync_sku, 'yes', false).'></td>
        <td><select name="zoho_sku_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_sku_field', 'sku'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>SKU (fixed)</td>
    </tr>';
    
    // Price mapping
    echo '<tr>
        <td><strong>Selling Price</strong></td>
        <td><input type="checkbox" name="sync_price" value="1" '.checked($sync_price, 'yes', false).'></td>
        <td><select name="zoho_price_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_price_field', 'rate'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>Regular Price (fixed)</td>
    </tr>';
    
    // Image mapping
    echo '<tr>
        <td><strong>Product Image</strong></td>
        <td><input type="checkbox" name="sync_image" value="1" '.checked($sync_image, 'yes', false).'></td>
        <td><select name="zoho_image_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_image_field', 'image'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>Product Image (fixed)</td>
    </tr>';
    
    // Description mapping
    echo '<tr>
        <td><strong>Description</strong></td>
        <td><input type="checkbox" name="sync_description" value="1" '.checked($sync_description, 'yes', false).'></td>
        <td><select name="zoho_description_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_description_field', 'description'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>Description (fixed)</td>
    </tr>';
    
    // Cost Price mapping
    echo '<tr>
        <td><strong>Cost Price</strong></td>
        <td><input type="checkbox" name="sync_cost_price" value="1" '.checked($sync_cost_price, 'yes', false).'></td>
        <td><select name="zoho_cost_price_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_cost_price_field', 'purchase_rate'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>Cost of Goods (COG) (fixed)</td>
    </tr>';
    
    // Stock mapping
    echo '<tr>
        <td><strong>Stock Quantity</strong></td>
        <td><input type="checkbox" name="sync_stock" value="1" '.checked($sync_stock, 'yes', false).'></td>
        <td><select name="zoho_stock_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_stock_field', 'stock_on_hand'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>Stock Quantity (fixed)</td>
    </tr>';
    
    // Reorder Point mapping
    echo '<tr>
        <td><strong>Reorder Point</strong></td>
        <td><input type="checkbox" name="sync_reorder_point" value="1" '.checked($sync_reorder_point, 'yes', false).'></td>
        <td><select name="zoho_reorder_point_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_reorder_point_field', 'reorder_level'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>Low Stock Threshold (fixed)</td>
    </tr>';
    
    // Weight mapping
    echo '<tr>
        <td><strong>Weight</strong></td>
        <td><input type="checkbox" name="sync_weight" value="1" '.checked($sync_weight, 'yes', false).'></td>
        <td><select name="zoho_weight_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_weight_field', 'weight'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>Weight (fixed)</td>
    </tr>';
    
    // Dimensions mapping
    echo '<tr>
        <td><strong>Dimensions (LxWxH)</strong></td>
        <td><input type="checkbox" name="sync_dimensions" value="1" '.checked($sync_dimensions, 'yes', false).'></td>
        <td>
            Length: <select name="zoho_length_field" style="width: 100px;">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_length_field', 'length'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select><br>
            Width: <select name="zoho_width_field" style="width: 100px;">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_width_field', 'width'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select><br>
            Height: <select name="zoho_height_field" style="width: 100px;">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_height_field', 'height'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select>
        </td>
        <td>Dimensions (fixed)</td>
    </tr>';
    
    // Tax mapping
    echo '<tr>
        <td><strong>Tax Status</strong></td>
        <td><input type="checkbox" name="sync_tax" value="1" '.checked($sync_tax, 'yes', false).'></td>
        <td><select name="zoho_tax_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_tax_field', 'tax_id'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>Tax Status (fixed)</td>
    </tr>';
    
    // UPC mapping
    echo '<tr>
        <td><strong>UPC</strong></td>
        <td><input type="checkbox" name="sync_upc" value="1" '.checked($sync_upc, 'yes', false).'></td>
        <td><select name="zoho_upc_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_upc_field', 'upc'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>GTIN/Barcode (fixed)</td>
    </tr>';
    
    // EAN mapping
    echo '<tr>
        <td><strong>EAN</strong></td>
        <td><input type="checkbox" name="sync_ean" value="1" '.checked($sync_ean, 'yes', false).'></td>
        <td><select name="zoho_ean_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_ean_field', 'ean'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>GTIN/Barcode (fixed)</td>
    </tr>';
    
    // ISBN mapping
    echo '<tr>
        <td><strong>ISBN</strong></td>
        <td><input type="checkbox" name="sync_isbn" value="1" '.checked($sync_isbn, 'yes', false).'></td>
        <td><select name="zoho_isbn_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_isbn_field', 'isbn'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>GTIN/Barcode (fixed)</td>
    </tr>';
    
    // MPN mapping
    echo '<tr>
        <td><strong>MPN (Part Number)</strong></td>
        <td><input type="checkbox" name="sync_mpn" value="1" '.checked($sync_mpn, 'yes', false).'></td>
        <td><select name="zoho_mpn_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_mpn_field', 'part_number'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>GTIN/Barcode (fixed)</td>
    </tr>';
    
    // Brand mapping
    echo '<tr>
        <td><strong>Brand</strong></td>
        <td><input type="checkbox" name="sync_brand" value="1" '.checked($sync_brand, 'yes', false).'></td>
        <td><select name="zoho_brand_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_brand_field', 'brand'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>Attribute: Brand (fixed)</td>
    </tr>';
    
    // Manufacturer mapping
    echo '<tr>
        <td><strong>Manufacturer</strong></td>
        <td><input type="checkbox" name="sync_manufacturer" value="1" '.checked($sync_manufacturer, 'yes', false).'></td>
        <td><select name="zoho_manufacturer_field">';
    foreach ($zoho_fields as $field_key => $field_label) {
        $selected = selected(get_option('zoho_zoho_manufacturer_field', 'manufacturer'), $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select></td>
        <td>Attribute: Manufacturer (fixed)</td>
    </tr>';
    
    echo '</tbody></table>';
    
    submit_button('Save Field Mapping Settings', 'primary', 'save_zoho_field_mapping');
    echo '</form>';
}

/* ======================================================
   INVOICES MAPPING TAB
====================================================== */
function zoho_field_mapping_invoices_tab() {
    echo '<p>Configure field mappings for syncing Zoho Invoices with WooCommerce Orders.</p>';
    
    // Get saved invoice mappings
    $invoice_customer_id_type = get_option('zoho_invoice_customer_id_type', 'standard_field');
    $invoice_customer_id_value = get_option('zoho_invoice_customer_id_value', 'shop_order_billing_email');
    $invoice_reference_number_type = get_option('zoho_invoice_reference_number_type', 'custom_value');
    $invoice_reference_number_value = get_option('zoho_invoice_reference_number_value', 'woo-order-{shop_order_order_id}');
    $invoice_date_type = get_option('zoho_invoice_date_type', 'standard_field');
    $invoice_date_value = get_option('zoho_invoice_date_value', 'shop_order_order_create_date');
    $invoice_invoice_number_type = get_option('zoho_invoice_invoice_number_type', 'custom_value');
    $invoice_invoice_number_value = get_option('zoho_invoice_invoice_number_value', 'woo{shop_order_order_id}');
    $invoice_reason_type = get_option('zoho_invoice_reason_type', 'custom_value');
    $invoice_reason_value = get_option('zoho_invoice_reason_value', 'Invoice create or update');
    
    // WooCommerce Order fields
    $wc_order_fields = array(
        'shop_order_billing_email' => 'Billing Email',
        'shop_order_billing_first_name' => 'Billing First Name',
        'shop_order_billing_last_name' => 'Billing Last Name',
        'shop_order_billing_company' => 'Billing Company',
        'shop_order_order_number' => 'Order Number',
        'shop_order_order_id' => 'Order ID',
        'shop_order_order_create_date' => 'Order Create Date',
        'shop_order_order_status' => 'Order Status',
    );
    
    echo '<form method="post">';
    wp_nonce_field('zoho_field_mapping_nonce');
    
    // Customer ID Field
    echo '<div style="background: #fff; border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px;">';
    echo '<h3 style="margin-top: 0;">Customer_id</h3>';
    echo '<p style="margin: 5px 0; color: #666;">API Name : <code>customer_id</code> Type : <code>lookup</code></p>';
    echo '<table class="form-table" style="margin-top: 15px;">';
    echo '<tr>';
    echo '<th scope="row"><label>Field Type</label></th>';
    echo '<td>';
    echo '<select name="invoice_customer_id_type" class="zoho-field-type" data-field="invoice_customer_id" style="width: 200px;">';
    echo '<option value="standard_field" '.selected($invoice_customer_id_type, 'standard_field', false).'>Standard Fields</option>';
    echo '<option value="custom_value" '.selected($invoice_customer_id_type, 'custom_value', false).'>Custom Value</option>';
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '<tr class="invoice_customer_id_field_row" '.($invoice_customer_id_type == 'custom_value' ? 'style="display:none;"' : '').'>';
    echo '<th scope="row"><label>Field Value</label></th>';
    echo '<td>';
    echo '<select name="invoice_customer_id_value" style="width: 300px;">';
    foreach ($wc_order_fields as $field_key => $field_label) {
        $selected = selected($invoice_customer_id_value, $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '<tr class="invoice_customer_id_custom_row" '.($invoice_customer_id_type == 'standard_field' ? 'style="display:none;"' : '').'>';
    echo '<th scope="row"><label>Custom Value</label></th>';
    echo '<td><input type="text" name="invoice_customer_id_custom_value" value="'.esc_attr($invoice_customer_id_type == 'custom_value' ? $invoice_customer_id_value : '').'" class="regular-text"></td>';
    echo '</tr>';
    echo '</table>';
    echo '</div>';
    
    // Reference Number Field
    echo '<div style="background: #fff; border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px;">';
    echo '<h3 style="margin-top: 0;">Reference_number</h3>';
    echo '<p style="margin: 5px 0; color: #666;">API Name : <code>reference_number</code> Type : <code>string</code></p>';
    echo '<table class="form-table" style="margin-top: 15px;">';
    echo '<tr>';
    echo '<th scope="row"><label>Field Type</label></th>';
    echo '<td>';
    echo '<select name="invoice_reference_number_type" class="zoho-field-type" data-field="invoice_reference_number" style="width: 200px;">';
    echo '<option value="standard_field" '.selected($invoice_reference_number_type, 'standard_field', false).'>Standard Fields</option>';
    echo '<option value="custom_value" '.selected($invoice_reference_number_type, 'custom_value', false).'>Custom Value</option>';
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '<tr class="invoice_reference_number_field_row" '.($invoice_reference_number_type == 'custom_value' ? 'style="display:none;"' : '').'>';
    echo '<th scope="row"><label>Field Value</label></th>';
    echo '<td>';
    echo '<select name="invoice_reference_number_value" style="width: 300px;">';
    foreach ($wc_order_fields as $field_key => $field_label) {
        $selected = selected($invoice_reference_number_value, $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '<tr class="invoice_reference_number_custom_row" '.($invoice_reference_number_type == 'standard_field' ? 'style="display:none;"' : '').'>';
    echo '<th scope="row"><label>Custom Value</label></th>';
    echo '<td><input type="text" name="invoice_reference_number_custom_value" value="'.esc_attr($invoice_reference_number_type == 'custom_value' ? $invoice_reference_number_value : '').'" class="regular-text" placeholder="woo-order-{shop_order_order_id}"></td>';
    echo '</tr>';
    echo '</table>';
    echo '</div>';
    
    // Date Field
    echo '<div style="background: #fff; border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px;">';
    echo '<h3 style="margin-top: 0;">Date</h3>';
    echo '<p style="margin: 5px 0; color: #666;">API Name : <code>date</code> Type : <code>string</code></p>';
    echo '<table class="form-table" style="margin-top: 15px;">';
    echo '<tr>';
    echo '<th scope="row"><label>Field Type</label></th>';
    echo '<td>';
    echo '<select name="invoice_date_type" class="zoho-field-type" data-field="invoice_date" style="width: 200px;">';
    echo '<option value="standard_field" '.selected($invoice_date_type, 'standard_field', false).'>Standard Fields</option>';
    echo '<option value="custom_value" '.selected($invoice_date_type, 'custom_value', false).'>Custom Value</option>';
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '<tr class="invoice_date_field_row" '.($invoice_date_type == 'custom_value' ? 'style="display:none;"' : '').'>';
    echo '<th scope="row"><label>Field Value</label></th>';
    echo '<td>';
    echo '<select name="invoice_date_value" style="width: 300px;">';
    foreach ($wc_order_fields as $field_key => $field_label) {
        $selected = selected($invoice_date_value, $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '<tr class="invoice_date_custom_row" '.($invoice_date_type == 'standard_field' ? 'style="display:none;"' : '').'>';
    echo '<th scope="row"><label>Custom Value</label></th>';
    echo '<td><input type="text" name="invoice_date_custom_value" value="'.esc_attr($invoice_date_type == 'custom_value' ? $invoice_date_value : '').'" class="regular-text"></td>';
    echo '</tr>';
    echo '</table>';
    echo '</div>';
    
    // Invoice Number Field
    echo '<div style="background: #fff; border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px;">';
    echo '<h3 style="margin-top: 0;">Invoice_number</h3>';
    echo '<p style="margin: 5px 0; color: #666;">API Name : <code>invoice_number</code> Type : <code>string</code></p>';
    echo '<table class="form-table" style="margin-top: 15px;">';
    echo '<tr>';
    echo '<th scope="row"><label>Field Type</label></th>';
    echo '<td>';
    echo '<select name="invoice_invoice_number_type" class="zoho-field-type" data-field="invoice_invoice_number" style="width: 200px;">';
    echo '<option value="standard_field" '.selected($invoice_invoice_number_type, 'standard_field', false).'>Standard Fields</option>';
    echo '<option value="custom_value" '.selected($invoice_invoice_number_type, 'custom_value', false).'>Custom Value</option>';
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '<tr class="invoice_invoice_number_field_row" '.($invoice_invoice_number_type == 'custom_value' ? 'style="display:none;"' : '').'>';
    echo '<th scope="row"><label>Field Value</label></th>';
    echo '<td>';
    echo '<select name="invoice_invoice_number_value" style="width: 300px;">';
    foreach ($wc_order_fields as $field_key => $field_label) {
        $selected = selected($invoice_invoice_number_value, $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '<tr class="invoice_invoice_number_custom_row" '.($invoice_invoice_number_type == 'standard_field' ? 'style="display:none;"' : '').'>';
    echo '<th scope="row"><label>Custom Value</label></th>';
    echo '<td><input type="text" name="invoice_invoice_number_custom_value" value="'.esc_attr($invoice_invoice_number_type == 'custom_value' ? $invoice_invoice_number_value : '').'" class="regular-text" placeholder="woo{shop_order_order_id}"></td>';
    echo '</tr>';
    echo '</table>';
    echo '</div>';
    
    // Reason Field
    echo '<div style="background: #fff; border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px;">';
    echo '<h3 style="margin-top: 0;">Reason</h3>';
    echo '<p style="margin: 5px 0; color: #666;">API Name : <code>reason</code> Type : <code>string</code></p>';
    echo '<table class="form-table" style="margin-top: 15px;">';
    echo '<tr>';
    echo '<th scope="row"><label>Field Type</label></th>';
    echo '<td>';
    echo '<select name="invoice_reason_type" class="zoho-field-type" data-field="invoice_reason" style="width: 200px;">';
    echo '<option value="standard_field" '.selected($invoice_reason_type, 'standard_field', false).'>Standard Fields</option>';
    echo '<option value="custom_value" '.selected($invoice_reason_type, 'custom_value', false).'>Custom Value</option>';
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '<tr class="invoice_reason_field_row" '.($invoice_reason_type == 'custom_value' ? 'style="display:none;"' : '').'>';
    echo '<th scope="row"><label>Field Value</label></th>';
    echo '<td>';
    echo '<select name="invoice_reason_value" style="width: 300px;">';
    foreach ($wc_order_fields as $field_key => $field_label) {
        $selected = selected($invoice_reason_value, $field_key, false);
        echo '<option value="'.esc_attr($field_key).'"'.$selected.'>'.esc_html($field_label).'</option>';
    }
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '<tr class="invoice_reason_custom_row" '.($invoice_reason_type == 'standard_field' ? 'style="display:none;"' : '').'>';
    echo '<th scope="row"><label>Custom Value</label></th>';
    echo '<td><input type="text" name="invoice_reason_custom_value" value="'.esc_attr($invoice_reason_type == 'custom_value' ? $invoice_reason_value : '').'" class="regular-text" placeholder="Invoice create or update"></td>';
    echo '</tr>';
    echo '</table>';
    echo '</div>';
    
    submit_button('Save Invoice Mapping Settings', 'primary', 'save_zoho_invoice_mapping');
    echo '</form>';
    
    // JavaScript for toggling field type
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('.zoho-field-type').on('change', function() {
            var field = $(this).data('field');
            var type = $(this).val();
            if (type == 'custom_value') {
                $('.' + field + '_field_row').hide();
                $('.' + field + '_custom_row').show();
            } else {
                $('.' + field + '_field_row').show();
                $('.' + field + '_custom_row').hide();
            }
        });
    });
    </script>
    <?php
}

/* ======================================================
   CUSTOMERS MAPPING TAB
====================================================== */
function zoho_field_mapping_customers_tab() {
    echo '<p>Configure field mappings for syncing Zoho Contacts with WooCommerce Customers.</p>';
    
    // WooCommerce Customer fields
    $wc_customer_fields = array(
        'billing_email' => 'Billing Email',
        'billing_first_name' => 'Billing First Name',
        'billing_last_name' => 'Billing Last Name',
        'billing_company' => 'Billing Company',
        'billing_address_1' => 'Billing Address 1',
        'billing_address_2' => 'Billing Address 2',
        'billing_city' => 'Billing City',
        'billing_state' => 'Billing State',
        'billing_postcode' => 'Billing Postcode',
        'billing_country' => 'Billing Country',
        'billing_phone' => 'Billing Phone',
        'shipping_first_name' => 'Shipping First Name',
        'shipping_last_name' => 'Shipping Last Name',
        'shipping_company' => 'Shipping Company',
        'shipping_address_1' => 'Shipping Address 1',
        'shipping_address_2' => 'Shipping Address 2',
        'shipping_city' => 'Shipping City',
        'shipping_state' => 'Shipping State',
        'shipping_postcode' => 'Shipping Postcode',
        'shipping_country' => 'Shipping Country',
    );
    
    // Define all customer fields
    $customer_fields = array(
        'contact_name' => array('label' => 'Contact_name', 'api' => 'contact_name', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'billing_email'),
        'company_name' => array('label' => 'Company_name', 'api' => 'company_name', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'billing_company'),
        'contact_type' => array('label' => 'Contact_type', 'api' => 'contact_type', 'type' => 'string', 'default_type' => 'custom_value', 'default_value' => 'customer'),
        'customer_sub_type' => array('label' => 'Customer_sub_type', 'api' => 'customer_sub_type', 'type' => 'string', 'default_type' => 'custom_value', 'default_value' => 'individual'),
        'first_name' => array('label' => 'First_name', 'api' => 'first_name', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'billing_first_name'),
        'last_name' => array('label' => 'Last_name', 'api' => 'last_name', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'billing_last_name'),
        'email' => array('label' => 'Email', 'api' => 'email', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'billing_email'),
        'bill_address' => array('label' => 'Bill_address', 'api' => 'bill_address', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'billing_address_1'),
        'billing_street2' => array('label' => 'Billing_street2', 'api' => 'billing_street2', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'billing_address_2'),
        'billing_city' => array('label' => 'Billing_city', 'api' => 'billing_city', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'billing_city'),
        'billing_state' => array('label' => 'Billing_state', 'api' => 'billing_state', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'billing_state'),
        'billing_zip' => array('label' => 'Billing_zip', 'api' => 'billing_zip', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'billing_postcode'),
        'billing_country' => array('label' => 'Billing_country', 'api' => 'billing_country', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'billing_country'),
        'billing_phone' => array('label' => 'Billing_phone', 'api' => 'billing_phone', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'billing_phone'),
        'ship_address' => array('label' => 'Ship_address', 'api' => 'ship_address', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'shipping_address_1'),
        'shipping_street2' => array('label' => 'Shipping_street2', 'api' => 'shipping_street2', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'shipping_address_2'),
        'shipping_city' => array('label' => 'Shipping_city', 'api' => 'shipping_city', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'shipping_city'),
        'shipping_state' => array('label' => 'Shipping_state', 'api' => 'shipping_state', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'shipping_state'),
        'shipping_zip' => array('label' => 'Shipping_zip', 'api' => 'shipping_zip', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'shipping_postcode'),
        'shipping_country' => array('label' => 'Shipping_country', 'api' => 'shipping_country', 'type' => 'string', 'default_type' => 'standard_field', 'default_value' => 'shipping_country'),
    );
    
    echo '<form method="post">';
    wp_nonce_field('zoho_field_mapping_nonce');
    
    foreach ($customer_fields as $field_key => $field_info) {
        $saved_type = get_option('zoho_customer_' . $field_key . '_type', $field_info['default_type']);
        $saved_value = get_option('zoho_customer_' . $field_key . '_value', $field_info['default_value']);
        
        echo '<div style="background: #fff; border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px;">';
        echo '<h3 style="margin-top: 0;">'.$field_info['label'].'</h3>';
        echo '<p style="margin: 5px 0; color: #666;">API Name : <code>'.$field_info['api'].'</code> Type : <code>'.$field_info['type'].'</code></p>';
        echo '<table class="form-table" style="margin-top: 15px;">';
        echo '<tr>';
        echo '<th scope="row"><label>Field Type</label></th>';
        echo '<td>';
        echo '<select name="customer_'.$field_key.'_type" class="zoho-field-type" data-field="customer_'.$field_key.'" style="width: 200px;">';
        echo '<option value="standard_field" '.selected($saved_type, 'standard_field', false).'>Standard Fields</option>';
        echo '<option value="custom_value" '.selected($saved_type, 'custom_value', false).'>Custom Value</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        echo '<tr class="customer_'.$field_key.'_field_row" '.($saved_type == 'custom_value' ? 'style="display:none;"' : '').'>';
        echo '<th scope="row"><label>Field Value</label></th>';
        echo '<td>';
        echo '<select name="customer_'.$field_key.'_value" style="width: 300px;">';
        foreach ($wc_customer_fields as $wc_key => $wc_label) {
            $sel = ($saved_type == 'standard_field' && $saved_value == $wc_key) ? ' selected' : '';
            echo '<option value="'.esc_attr($wc_key).'"'.$sel.'>'.esc_html($wc_label).'</option>';
        }
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        echo '<tr class="customer_'.$field_key.'_custom_row" '.($saved_type == 'standard_field' ? 'style="display:none;"' : '').'>';
        echo '<th scope="row"><label>Custom Value</label></th>';
        echo '<td><input type="text" name="customer_'.$field_key.'_custom_value" value="'.esc_attr($saved_type == 'custom_value' ? $saved_value : $field_info['default_value']).'" class="regular-text"></td>';
        echo '</tr>';
        echo '</table>';
        echo '</div>';
    }
    
    submit_button('Save Customer Mapping Settings', 'primary', 'save_zoho_customer_mapping');
    echo '</form>';
    
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('.zoho-field-type').on('change', function() {
            var field = $(this).data('field');
            var type = $(this).val();
            if (type == 'custom_value') {
                $('.' + field + '_field_row').hide();
                $('.' + field + '_custom_row').show();
            } else {
                $('.' + field + '_field_row').show();
                $('.' + field + '_custom_row').hide();
            }
        });
    });
    </script>
    <?php
}

/* ======================================================
   INVENTORY SYNC PAGE
====================================================== */
function zoho_inventory_sync_page() {

    if (isset($_GET['deleted'])) {
        echo '<div class="notice notice-success"><p>Log deleted successfully.</p></div>';
    }
    
    // ONE-TIME CLEANUP: Purane logs jo ID nahi hai unhe delete karo
    $logs = get_option('zoho_inventory_logs', []);
    $cleaned = false;
    
    foreach ($logs as $key => $log) {
        if (!isset($log['id']) || empty($log['id'])) {
            unset($logs[$key]);
            $cleaned = true;
        }
    }
    
    if ($cleaned) {
        update_option('zoho_inventory_logs', array_values($logs));
        echo '<div class="notice notice-success"><p>Old logs cleaned! Refreshing...</p></div>';
        echo '<script>setTimeout(function(){ window.location.href = "'.admin_url('admin.php?page=zoho-inventory-sync&cleaned=1').'"; }, 1500);</script>';
        return;
    }
    
    if (isset($_GET['cleaned'])) {
        echo '<div class="notice notice-success"><p>Cleanup complete! Old logs removed.</p></div>';
    }

    /* ---------- VIEW LOG ---------- */
    if (isset($_GET['view_log'])) {

        $logs = get_option('zoho_inventory_logs', []);
        foreach ($logs as $log) {
            if ($log['id'] === $_GET['view_log']) {

                echo '<h2>Sync Details</h2>';
                echo '<p><strong>Date:</strong> '.$log['time'].'</p>';
                echo '<p><strong>Total Products:</strong> '.$log['total'].'</p>';
                echo '<p><strong>Synced:</strong> '.$log['synced'].'</p>';
                if (isset($log['created'])) {
                    echo '<p><strong>Created (New):</strong> '.$log['created'].'</p>';
                }
                if (isset($log['updated'])) {
                    echo '<p><strong>Updated (Existing):</strong> '.$log['updated'].'</p>';
                }
                echo '<p><strong>Pending:</strong> '.$log['pending'].'</p>';

                echo '<h3>Synced SKUs</h3>';
                zoho_render_sku_table($log['synced_skus']);

                echo '<h3>Pending / Skipped</h3>';
                zoho_render_pending_table($log['pending_skus']);

                echo '<a href="'.admin_url('admin.php?page=zoho-inventory-sync').'" class="button">Back</a>';
                return;
            }
        }
    }

    echo '<h1>Inventory Sync</h1>';

    echo '<p>Configure product field mappings in <a href="'.admin_url('admin.php?page=zoho-field-mapping&tab=items').'">Field Mapping → Items</a> before syncing.</p>';

    // Show success message from previous sync (if exists)
    if (isset($_GET['sync_completed'])) {
        echo '<div id="zoho-sync-success" class="notice notice-success">
            <p><strong>Sync Completed!</strong></p>
            <p>Total: '.intval($_GET['total']).' | Synced: '.intval($_GET['synced']).' | Created: '.intval($_GET['created']).' | Updated: '.intval($_GET['updated']).' | Pending: '.intval($_GET['pending']).'</p>
        </div>';
    } else {
        echo '<div id="zoho-sync-success" style="display:none;"></div>';
    }
    
    // Sync direction
    echo '<div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 4px;">';
    echo '<h3 style="margin-top: 0;">Sync Direction</h3>';
    echo '<p><label><input type="radio" name="inventory_sync_direction" value="zoho_to_woo" checked> <strong>Zoho → WooCommerce:</strong> Create/Update WooCommerce products from Zoho items</label></p>';
    echo '<p><label><input type="radio" name="inventory_sync_direction" value="woo_to_zoho"> <strong>WooCommerce → Zoho:</strong> Create/Update Zoho items from WooCommerce products</label></p>';
    echo '</div>';

    // Progress bar container
    echo '<div id="zoho-sync-progress" style="display:none; margin: 20px 0;">
        <div style="background: #f0f0f0; border-radius: 4px; padding: 3px; margin-bottom: 10px;">
            <div id="zoho-progress-bar" style="background: #2271b1; height: 24px; border-radius: 3px; width: 0%; transition: width 0.3s ease; text-align: center; line-height: 24px; color: white; font-weight: bold;">
                <span id="zoho-progress-percentage">0%</span>
            </div>
        </div>
        <p id="zoho-progress-text" style="margin: 5px 0;">Starting sync...</p>
        </div>';
    
    echo '<div id="zoho-sync-result" style="display:none; margin: 20px 0; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;"></div>';

    echo '<button id="zoho-start-sync" class="button button-primary" type="button">Sync Products Now</button>';
    
    echo '<p style="margin-top: 15px;"><a href="'.admin_url('admin.php?page=zoho-logs&tab=items').'">View Item Sync Logs →</a></p>';
}

/* ======================================================
   HELPER FUNCTIONS FOR PRODUCT SYNC
====================================================== */

/**
 * Set product attribute
 */
function zoho_set_product_attribute($product, $attribute_name, $attribute_value) {
    if (empty($attribute_value)) return;
    
    $attribute_key = 'pa_' . sanitize_title($attribute_name);
    
    // Get existing attributes
    $attributes = $product->get_attributes();
    
    // Create or update attribute
    if (!isset($attributes[$attribute_key])) {
        $attribute = new WC_Product_Attribute();
        $attribute->set_name($attribute_key);
        $attribute->set_options(array($attribute_value));
        $attribute->set_visible(true);
        $attribute->set_variation(false);
        $attributes[$attribute_key] = $attribute;
    } else {
        $attributes[$attribute_key]->set_options(array($attribute_value));
    }
    
    $product->set_attributes($attributes);
}

/**
 * Set product image from URL
 */
function zoho_set_product_image($product, $image_url) {
    if (empty($image_url)) return;
    
    // Check if image already exists
    $attachment_id = attachment_url_to_postid($image_url);
    
    if (!$attachment_id) {
        // Download and attach image
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $attachment_id = media_sideload_image($image_url, $product->get_id(), '', 'id');
        
        if (is_wp_error($attachment_id)) {
            return; // Failed to download image
        }
    }
    
    // Set as featured image
    if ($attachment_id) {
        set_post_thumbnail($product->get_id(), $attachment_id);
    }
}

/* ======================================================
   AJAX HANDLER FOR PRODUCT SYNC
====================================================== */
add_action('wp_ajax_zoho_sync_products_ajax', 'zoho_sync_products_ajax_handler');
function zoho_sync_products_ajax_handler() {
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
    $direction = isset($_POST['direction']) ? sanitize_text_field($_POST['direction']) : 'zoho_to_woo';
    
    // Handle WooCommerce to Zoho sync
    if ($direction === 'woo_to_zoho') {
        zoho_sync_woo_to_zoho($offset, $total, $synced, $created, $updated);
        return;
    }
    
    // Zoho to WooCommerce sync (default) - First call - fetch ALL items from API with pagination
    if ($offset == 0 && $total == 0) {
        $all_items = array();
        $page = 1;
        $per_page = 200; // Zoho default limit
        
        // Fetch all pages from Zoho API
        do {
            $response = zoho_api_get('items?page=' . $page . '&per_page=' . $per_page);
            
            if (is_wp_error($response)) {
                wp_send_json_error('API call failed: ' . $response->get_error_message());
                return;
            }
            
            if (isset($response['error'])) {
                wp_send_json_error($response['error']);
                return;
            }
            
            if (isset($response['code']) && $response['code'] != 0) {
                $error_msg = $response['message'] ?? 'Unknown error';
                wp_send_json_error('API Error (Code: ' . $response['code'] . '): ' . $error_msg);
                return;
            }
            
            $items = $response['items'] ?? array();
            $all_items = array_merge($all_items, $items);
            
            $has_more_pages = isset($response['page_context']['has_more_page']) ? $response['page_context']['has_more_page'] : (count($items) >= $per_page);
            $page++;
            
        } while ($has_more_pages && $page <= 50); // Max 50 pages = 10,000 items safety limit
        
        if (empty($all_items)) {
            wp_send_json_error('No items found in Zoho');
            return;
        }
        
        $total = count($all_items);
        error_log('Zoho Sync: Found ' . $total . ' items from Zoho API');
        
        // Store items in transient for batch processing
        set_transient('zoho_sync_items', $all_items, 600); // 10 minutes for large syncs
        set_transient('zoho_sync_stats', array('synced' => 0, 'created' => 0, 'updated' => 0, 'pending' => 0, 'synced_skus' => array(), 'pending_skus' => array()), 600);
    } else {
        // Get items from transient
        $items = get_transient('zoho_sync_items');
        if (!$items) {
            wp_send_json_error('Sync session expired. Please try again.');
            return;
        }
        $total = count($items);
        $stats = get_transient('zoho_sync_stats');
        $synced_skus = $stats['synced_skus'] ?? array();
        $pending_skus = $stats['pending_skus'] ?? array();
    }
    
    // Process batch (50 products per batch)
    $batch_size = 50;
    $batch_items = array_slice($items, $offset, $batch_size);
    $processed = 0;
    $batch_created = 0;
    $batch_updated = 0;
    $batch_synced_skus = array();
    $batch_pending_skus = array();
    
    // Get field mappings and toggle settings
    $zoho_name_field = get_option('zoho_zoho_name_field', 'name');
    $zoho_sku_field = get_option('zoho_zoho_sku_field', 'sku');
    $zoho_price_field = get_option('zoho_zoho_price_field', 'rate');
    $zoho_image_field = get_option('zoho_zoho_image_field', 'image');
    $zoho_description_field = get_option('zoho_zoho_description_field', 'description');
    $zoho_cost_price_field = get_option('zoho_zoho_cost_price_field', 'purchase_rate');
    $zoho_stock_field = get_option('zoho_zoho_stock_field', 'stock_on_hand');
    $zoho_reorder_point_field = get_option('zoho_zoho_reorder_point_field', 'reorder_level');
    $zoho_weight_field = get_option('zoho_zoho_weight_field', 'weight');
    $zoho_length_field = get_option('zoho_zoho_length_field', 'length');
    $zoho_width_field = get_option('zoho_zoho_width_field', 'width');
    $zoho_height_field = get_option('zoho_zoho_height_field', 'height');
    $zoho_tax_field = get_option('zoho_zoho_tax_field', 'tax_id');
    $zoho_upc_field = get_option('zoho_zoho_upc_field', 'upc');
    $zoho_ean_field = get_option('zoho_zoho_ean_field', 'ean');
    $zoho_isbn_field = get_option('zoho_zoho_isbn_field', 'isbn');
    $zoho_mpn_field = get_option('zoho_zoho_mpn_field', 'part_number');
    $zoho_brand_field = get_option('zoho_zoho_brand_field', 'brand');
    $zoho_manufacturer_field = get_option('zoho_zoho_manufacturer_field', 'manufacturer');
    
    foreach ($batch_items as $item) {
        // Get SKU from mapped field
        $sku_value = isset($item[$zoho_sku_field]) ? $item[$zoho_sku_field] : (isset($item['sku']) ? $item['sku'] : '');
        
        if (empty($sku_value)) {
            $batch_pending_skus[] = ['sku'=>'(empty)','reason'=>'Missing SKU'];
                continue;
            }

        $product_id = wc_get_product_id_by_sku($sku_value);
        $is_new_product = false;
        
            if (!$product_id) {
            // Create new product
            $product = new WC_Product_Simple();
            $product->set_sku($sku_value);
            
            // Get name from mapped field
            $name_value = isset($item[$zoho_name_field]) ? $item[$zoho_name_field] : (isset($item['name']) ? $item['name'] : $sku_value);
            $product->set_name($name_value);
            $product->set_slug(sanitize_title($name_value));
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            
            $is_new_product = true;
            $batch_created++;
        } else {
            $product = wc_get_product($product_id);
            $batch_updated++;
        }
        
        // Sync Name (if enabled)
        if (get_option('zoho_sync_name', 'yes') == 'yes') {
            $name_value = isset($item[$zoho_name_field]) ? $item[$zoho_name_field] : '';
            if (!empty($name_value)) {
                $product->set_name($name_value);
            }
        }
        
        // Sync Price (if enabled)
        if (get_option('zoho_sync_price', 'yes') == 'yes') {
            $price_value = isset($item[$zoho_price_field]) ? $item[$zoho_price_field] : '';
            if (!empty($price_value)) {
                $product->set_regular_price($price_value);
            }
        }
        
        // Sync Stock (if enabled)
        if (get_option('zoho_sync_stock', 'yes') == 'yes') {
            $stock_value = isset($item[$zoho_stock_field]) ? $item[$zoho_stock_field] : '';
            if ($stock_value !== '') {
                $product->set_manage_stock(true);
                $product->set_stock_quantity(intval($stock_value));
                $product->set_stock_status($stock_value > 0 ? 'instock' : 'outofstock');
            }
        }
        
        // Sync Description (if enabled)
        if (get_option('zoho_sync_description', 'yes') == 'yes') {
            $desc_value = isset($item[$zoho_description_field]) ? $item[$zoho_description_field] : '';
            if (!empty($desc_value)) {
                $product->set_description($desc_value);
            }
        }
        
        // Sync Cost Price (if enabled)
        if (get_option('zoho_sync_cost_price', 'no') == 'yes') {
            $cost_value = isset($item[$zoho_cost_price_field]) ? $item[$zoho_cost_price_field] : '';
            if (!empty($cost_value) && function_exists('wc_get_product')) {
                // Cost of Goods plugin support
                update_post_meta($product->get_id(), '_wc_cog_cost', $cost_value);
            }
        }
        
        // Sync Reorder Point (if enabled)
        if (get_option('zoho_sync_reorder_point', 'no') == 'yes') {
            $reorder_value = isset($item[$zoho_reorder_point_field]) ? $item[$zoho_reorder_point_field] : '';
            if ($reorder_value !== '') {
                $product->set_low_stock_amount(intval($reorder_value));
            }
        }
        
        // Sync Weight (if enabled)
        if (get_option('zoho_sync_weight', 'no') == 'yes') {
            $weight_value = isset($item[$zoho_weight_field]) ? $item[$zoho_weight_field] : '';
            if ($weight_value !== '') {
                $product->set_weight($weight_value);
            }
        }
        
        // Sync Dimensions (if enabled)
        if (get_option('zoho_sync_dimensions', 'no') == 'yes') {
            $length_value = isset($item[$zoho_length_field]) ? $item[$zoho_length_field] : '';
            $width_value = isset($item[$zoho_width_field]) ? $item[$zoho_width_field] : '';
            $height_value = isset($item[$zoho_height_field]) ? $item[$zoho_height_field] : '';
            
            if ($length_value !== '') $product->set_length($length_value);
            if ($width_value !== '') $product->set_width($width_value);
            if ($height_value !== '') $product->set_height($height_value);
        }
        
        // Sync Tax (if enabled)
        if (get_option('zoho_sync_tax', 'no') == 'yes') {
            $tax_value = isset($item[$zoho_tax_field]) ? $item[$zoho_tax_field] : '';
            if (!empty($tax_value)) {
                // If tax_id exists, set taxable
                $product->set_tax_status('taxable');
                // You can also set tax class if needed
            } elseif (isset($item['is_taxable']) && !$item['is_taxable']) {
                $product->set_tax_status('none');
            }
        }
        
        // Sync UPC/EAN/ISBN/MPN (if enabled)
        if (get_option('zoho_sync_upc', 'no') == 'yes') {
            $upc_value = isset($item[$zoho_upc_field]) ? $item[$zoho_upc_field] : '';
            if (!empty($upc_value)) {
                update_post_meta($product->get_id(), '_wpm_gtin_code', $upc_value);
            }
        }
        
        if (get_option('zoho_sync_ean', 'no') == 'yes') {
            $ean_value = isset($item[$zoho_ean_field]) ? $item[$zoho_ean_field] : '';
            if (!empty($ean_value)) {
                update_post_meta($product->get_id(), '_wpm_gtin_code', $ean_value);
            }
        }
        
        if (get_option('zoho_sync_isbn', 'no') == 'yes') {
            $isbn_value = isset($item[$zoho_isbn_field]) ? $item[$zoho_isbn_field] : '';
            if (!empty($isbn_value)) {
                update_post_meta($product->get_id(), '_wpm_gtin_code', $isbn_value);
            }
        }
        
        if (get_option('zoho_sync_mpn', 'no') == 'yes') {
            $mpn_value = isset($item[$zoho_mpn_field]) ? $item[$zoho_mpn_field] : '';
            if (!empty($mpn_value)) {
                update_post_meta($product->get_id(), '_wpm_gtin_code', $mpn_value);
            }
        }
        
        // Sync Brand (if enabled) - as product attribute
        if (get_option('zoho_sync_brand', 'no') == 'yes') {
            $brand_value = isset($item[$zoho_brand_field]) ? $item[$zoho_brand_field] : '';
            if (!empty($brand_value)) {
                zoho_set_product_attribute($product, 'brand', $brand_value);
            }
        }
        
        // Sync Manufacturer (if enabled) - as product attribute
        if (get_option('zoho_sync_manufacturer', 'no') == 'yes') {
            $manufacturer_value = isset($item[$zoho_manufacturer_field]) ? $item[$zoho_manufacturer_field] : '';
            if (!empty($manufacturer_value)) {
                zoho_set_product_attribute($product, 'manufacturer', $manufacturer_value);
            }
        }
        
        // Sync Image (if enabled)
        if (get_option('zoho_sync_image', 'no') == 'yes') {
            $image_value = isset($item[$zoho_image_field]) ? $item[$zoho_image_field] : '';
            if (!empty($image_value)) {
                zoho_set_product_image($product, $image_value);
            }
            }

            $product->save();
        $saved_product_id = $product->get_id();
        
        // Permalink Manager integration
        if ($saved_product_id && $is_new_product && class_exists('Permalink_Manager_URI_Functions_Post')) {
            global $permalink_manager_uris;
            if (isset($permalink_manager_uris[$saved_product_id])) {
                unset($permalink_manager_uris[$saved_product_id]);
            }
            Permalink_Manager_URI_Functions_Post::save_uri($saved_product_id, '', true, 0);
        }
        
        // Add detailed log
        $zoho_item_id = isset($item['item_id']) ? $item['item_id'] : '';
        zoho_add_detailed_log(
            'item',
            'product',
            $saved_product_id,
            'items-inventory',
            $zoho_item_id,
            json_encode($item),
            json_encode(array('code' => 0, 'message' => ($is_new_product ? 'Created' : 'Updated'), 'product_id' => $saved_product_id))
        );
        
        $processed++;
        $batch_synced_skus[] = $sku_value;
    }
    
    // Update stats
    $new_offset = $offset + $batch_size;
    $new_synced = $synced + $processed;
    $new_created = $created + $batch_created;
    $new_updated = $updated + $batch_updated;
    
    $stats = get_transient('zoho_sync_stats');
    $stats['synced'] = $new_synced;
    $stats['created'] = $new_created;
    $stats['updated'] = $new_updated;
    $stats['synced_skus'] = array_merge($stats['synced_skus'] ?? array(), $batch_synced_skus);
    $stats['pending_skus'] = array_merge($stats['pending_skus'] ?? array(), $batch_pending_skus);
    set_transient('zoho_sync_stats', $stats, 300);
    
    $has_more = $new_offset < $total;
    
    // If last batch, save log and cleanup
    if (!$has_more) {
        // Flush rewrite rules
        if ($new_created > 0) {
            global $wp_rewrite;
            $wp_rewrite->flush_rules(false);
        }
        
        // Save log
        zoho_add_sync_log($total, $new_synced, $stats['synced_skus'], $stats['pending_skus'], $new_created, $new_updated);
        
        // Cleanup transients
        delete_transient('zoho_sync_items');
        delete_transient('zoho_sync_stats');
    }
    
    wp_send_json_success(array(
        'offset' => $new_offset,
        'total' => $total,
        'synced' => $new_synced,
        'created' => $new_created,
        'updated' => $new_updated,
        'pending' => count($stats['pending_skus']),
        'has_more' => $has_more
    ));
}

/* ======================================================
   WOOCOMMERCE TO ZOHO SYNC FUNCTION
====================================================== */
function zoho_sync_woo_to_zoho($offset, $total, $synced, $created, $updated) {
    // First call - fetch WooCommerce products
    if ($offset == 0 && $total == 0) {
        // Clear old transients first to ensure fresh data
        delete_transient('zoho_woo_sync_products');
        delete_transient('zoho_woo_sync_stats');
        
        // Use direct SQL query to get ALL products (published, draft, private, pending)
        global $wpdb;
        $product_ids = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'product' 
            AND post_status IN ('publish', 'draft', 'private', 'pending')
            ORDER BY ID ASC
        ");
        
        // Log the count for debugging
        error_log('Zoho Sync: Found ' . count($product_ids) . ' products to sync (WooCommerce to Zoho)');
        
        $total = count($product_ids);
        
        if ($total == 0) {
            wp_send_json_error('No products found in WooCommerce');
        return;
    }

        // Store product IDs in transient for batch processing (10 minutes for large syncs)
        set_transient('zoho_woo_sync_products', $product_ids, 600);
        set_transient('zoho_woo_sync_stats', array('synced' => 0, 'created' => 0, 'updated' => 0, 'pending' => 0, 'synced_skus' => array(), 'pending_skus' => array()), 600);
    } else {
        // Get product IDs from transient
        $product_ids = get_transient('zoho_woo_sync_products');
        if (!$product_ids) {
            wp_send_json_error('Sync session expired. Please try again.');
            return;
        }
        $total = count($product_ids);
        $stats = get_transient('zoho_woo_sync_stats');
        $synced_skus = $stats['synced_skus'] ?? array();
        $pending_skus = $stats['pending_skus'] ?? array();
    }
    
    // Process batch (10 products per batch for API calls)
    $batch_size = 10;
    $batch_ids = array_slice($product_ids, $offset, $batch_size);
    $processed = 0;
    $batch_created = 0;
    $batch_updated = 0;
    $batch_synced_skus = array();
    $batch_pending_skus = array();
    
    // Get organization ID
    $org_id = get_option('zoho_organization_id', '');
    $access_token = get_option('zoho_access_token', '');
    
    if (empty($org_id) || empty($access_token)) {
        wp_send_json_error('Zoho connection not configured');
        return;
    }
    
    foreach ($batch_ids as $product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            $batch_pending_skus[] = array('sku' => $product_id, 'reason' => 'Product not found');
            continue;
        }
        
        $sku = $product->get_sku();
        if (empty($sku)) {
            $batch_pending_skus[] = array('sku' => $product_id, 'reason' => 'No SKU');
            continue;
        }
        
        // Prepare item data for Zoho
        $item_data = array(
            'name' => $product->get_name(),
            'sku' => $sku,
            'rate' => floatval($product->get_regular_price()),
            'description' => $product->get_description(),
            'item_type' => 'inventory',
        );
        
        // Add stock if managing stock
        if ($product->get_manage_stock()) {
            $item_data['initial_stock'] = $product->get_stock_quantity() ?: 0;
            $item_data['initial_stock_rate'] = floatval($product->get_regular_price());
        }
        
        // Check if item exists in Zoho by SKU
        $zoho_item_id = zoho_get_item_by_sku($sku);
        
        $request_data = json_encode(array('data' => array($item_data)));
        
        if ($zoho_item_id) {
            // Update existing item
            $response = zoho_api_put('items/' . $zoho_item_id, $item_data);
            $action = 'updated';
            $batch_updated++;
        } else {
            // Create new item
            $response = zoho_api_post('items', $item_data);
            $action = 'created';
            $batch_created++;
        }
        
        // Log the sync
        $zoho_object_id = '';
        if (isset($response['item']['item_id'])) {
            $zoho_object_id = $response['item']['item_id'];
        }
        
        zoho_add_detailed_log(
            'item',
            'product',
            $product_id,
            'items-inventory',
            $zoho_object_id,
            $request_data,
            json_encode($response)
        );
        
        if (is_wp_error($response) || (isset($response['code']) && $response['code'] != 0)) {
            $error_msg = is_wp_error($response) ? $response->get_error_message() : ($response['message'] ?? 'Unknown error');
            $batch_pending_skus[] = array('sku' => $sku, 'reason' => $error_msg);
            // Revert count
            if ($action == 'updated') $batch_updated--;
            else $batch_created--;
        } else {
            $batch_synced_skus[] = $sku;
        }
        
        $processed++;
    }
    
    // Calculate new totals
    $stats = get_transient('zoho_woo_sync_stats') ?: array('synced' => 0, 'created' => 0, 'updated' => 0, 'synced_skus' => array(), 'pending_skus' => array());
    $new_offset = $offset + $processed;
    $new_synced = $synced + count($batch_synced_skus);
    $new_created = $created + $batch_created;
    $new_updated = $updated + $batch_updated;
    
    // Update transient stats
    $stats['synced_skus'] = array_merge($stats['synced_skus'] ?? array(), $batch_synced_skus);
    $stats['pending_skus'] = array_merge($stats['pending_skus'] ?? array(), $batch_pending_skus);
    set_transient('zoho_woo_sync_stats', $stats, 300);
    
    $has_more = $new_offset < $total;
    
    // If last batch, save log and cleanup
    if (!$has_more) {
        zoho_add_sync_log($total, $new_synced, $stats['synced_skus'], $stats['pending_skus'], $new_created, $new_updated);
        delete_transient('zoho_woo_sync_products');
        delete_transient('zoho_woo_sync_stats');
    }
    
    wp_send_json_success(array(
        'offset' => $new_offset,
        'total' => $total,
        'synced' => $new_synced,
        'created' => $new_created,
        'updated' => $new_updated,
        'pending' => count($stats['pending_skus']),
        'has_more' => $has_more
    ));
}

/* ======================================================
   HELPER: Get Zoho Item by SKU
====================================================== */
function zoho_get_item_by_sku($sku) {
    $response = zoho_api_get('items?sku=' . urlencode($sku));
    
    if (!is_wp_error($response) && isset($response['items']) && !empty($response['items'])) {
        return $response['items'][0]['item_id'];
    }
    
    return null;
}

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
    // First call - fetch WooCommerce orders
    if ($offset == 0 && $total == 0) {
        // Clear old transients
        delete_transient('zoho_invoice_sync_orders');
        delete_transient('zoho_invoice_sync_stats');
        
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
        
        error_log('Zoho Sync: Found ' . $total . ' orders to sync (WooCommerce to Zoho)');
        
        if ($total == 0) {
            wp_send_json_error('No orders found in WooCommerce');
            return;
        }
        
        set_transient('zoho_invoice_sync_orders', $order_ids, 600);
        set_transient('zoho_invoice_sync_stats', array('synced' => 0, 'created' => 0, 'updated' => 0, 'failed' => array()), 600);
    } else {
        $order_ids = get_transient('zoho_invoice_sync_orders');
        if (!$order_ids) {
            wp_send_json_error('Sync session expired. Please try again.');
            return;
        }
        $total = count($order_ids);
    }
    
    // Process batch (5 orders per batch for API calls)
    $batch_size = 5;
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
        
        // Get or create customer in Zoho
        $customer_email = $order->get_billing_email();
        $zoho_customer_id = zoho_get_or_create_contact($order);
        
        if (!$zoho_customer_id) {
            $batch_failed[] = array('id' => $order_id, 'reason' => 'Failed to get/create customer');
            continue;
        }
        
        // Build invoice data
        $invoice_date = $order->get_date_created()->format('Y-m-d');
        $reference_number = str_replace('{shop_order_order_id}', $order_id, $reference_number_value);
        $invoice_number = str_replace('{shop_order_order_id}', $order_id, $invoice_number_value);
        
        // Build line items
        $line_items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $sku = $product ? $product->get_sku() : '';
            
            // Try to find item in Zoho
            $zoho_item_id = $sku ? zoho_get_item_by_sku($sku) : null;
            
            $line_item = array(
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'rate' => $item->get_total() / max(1, $item->get_quantity()),
            );
            
            if ($zoho_item_id) {
                $line_item['item_id'] = $zoho_item_id;
            }
            
            $line_items[] = $line_item;
        }
        
        // Add shipping as line item if exists
        if ($order->get_shipping_total() > 0) {
            $line_items[] = array(
                'name' => 'Shipping: ' . $order->get_shipping_method(),
                'quantity' => 1,
                'rate' => $order->get_shipping_total(),
            );
        }
        
        $invoice_data = array(
            'customer_id' => $zoho_customer_id,
            'date' => $invoice_date,
            'reference_number' => $reference_number,
            'line_items' => $line_items,
        );
        
        // Check if invoice already exists for this order
        $existing_invoice_id = get_post_meta($order_id, '_zoho_invoice_id', true);
        
        $request_data = json_encode($invoice_data);
        
        if ($existing_invoice_id) {
            // Update existing invoice
            $response = zoho_api_put('invoices/' . $existing_invoice_id, $invoice_data);
            $action = 'updated';
        } else {
            // Create new invoice
            $response = zoho_api_post('invoices', $invoice_data);
            $action = 'created';
        }
        
        // Log the sync
        $zoho_invoice_id = '';
        if (isset($response['invoice']['invoice_id'])) {
            $zoho_invoice_id = $response['invoice']['invoice_id'];
            update_post_meta($order_id, '_zoho_invoice_id', $zoho_invoice_id);
        }
        
        zoho_add_detailed_log(
            'invoice',
            'order',
            $order_id,
            'invoice',
            $zoho_invoice_id,
            $request_data,
            json_encode($response)
        );
        
        if (is_wp_error($response) || (isset($response['code']) && $response['code'] != 0)) {
            $error_msg = is_wp_error($response) ? $response->get_error_message() : ($response['message'] ?? 'Unknown error');
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
        error_log('Zoho Sync: Found ' . $total . ' invoices from Zoho API');
        
        if ($total == 0) {
            wp_send_json_error('No invoices found in Zoho');
            return;
        }
        
        set_transient('zoho_invoice_sync_invoices', $all_invoices, 600);
        set_transient('zoho_invoice_sync_stats', array('synced' => 0, 'created' => 0, 'updated' => 0, 'failed' => array()), 600);
    } else {
        $all_invoices = get_transient('zoho_invoice_sync_invoices');
        if (!$all_invoices) {
            wp_send_json_error('Sync session expired. Please try again.');
            return;
        }
        $total = count($all_invoices);
    }
    
    // Process batch
    $batch_size = 5;
    $batch_invoices = array_slice($all_invoices, $offset, $batch_size);
    $batch_created = 0;
    $batch_updated = 0;
    $batch_failed = array();
    
    foreach ($batch_invoices as $invoice) {
        $zoho_invoice_id = $invoice['invoice_id'] ?? '';
        $reference_number = $invoice['reference_number'] ?? '';
        
        // Check if order already exists
        $existing_order_id = zoho_get_order_by_zoho_invoice($zoho_invoice_id);
        
        if ($existing_order_id) {
            // Update existing order
            $order = wc_get_order($existing_order_id);
            if ($order) {
                // Update order total if needed
                $order->add_order_note('Updated from Zoho Invoice: ' . $zoho_invoice_id);
                $order->save();
                $batch_updated++;
                
                zoho_add_detailed_log('invoice', 'order', $existing_order_id, 'invoice', $zoho_invoice_id, json_encode($invoice), json_encode(array('message' => 'Updated')));
            }
        } else {
            // Create new order
            $order = wc_create_order();
            
            // Set customer
            $customer_id = $invoice['customer_id'] ?? '';
            if ($customer_id) {
                // Try to find WooCommerce customer by Zoho contact
                $wc_customer = zoho_get_wc_customer_by_zoho_contact($customer_id);
                if ($wc_customer) {
                    $order->set_customer_id($wc_customer);
                }
            }
            
            // Add line items
            foreach ($invoice['line_items'] ?? array() as $line_item) {
                $item_name = $line_item['name'] ?? 'Item';
                $quantity = $line_item['quantity'] ?? 1;
                $rate = $line_item['rate'] ?? 0;
                
                // Try to find product by SKU
                $product_id = 0;
                if (!empty($line_item['sku'])) {
                    $product_id = wc_get_product_id_by_sku($line_item['sku']);
                }
                
                if ($product_id) {
                    $order->add_product(wc_get_product($product_id), $quantity);
                } else {
                    $item = new WC_Order_Item_Product();
                    $item->set_name($item_name);
                    $item->set_quantity($quantity);
                    $item->set_subtotal($rate * $quantity);
                    $item->set_total($rate * $quantity);
                    $order->add_item($item);
                }
            }
            
            $order->calculate_totals();
            $order->set_status('processing');
            $order->add_order_note('Created from Zoho Invoice: ' . $zoho_invoice_id);
            $order->save();
            
            update_post_meta($order->get_id(), '_zoho_invoice_id', $zoho_invoice_id);
            
            $batch_created++;
            
            zoho_add_detailed_log('invoice', 'order', $order->get_id(), 'invoice', $zoho_invoice_id, json_encode($invoice), json_encode(array('message' => 'Created', 'order_id' => $order->get_id())));
        }
    }
    
    // Update stats
    $stats = get_transient('zoho_invoice_sync_stats') ?: array('synced' => 0, 'created' => 0, 'updated' => 0, 'failed' => array());
    $new_offset = $offset + count($batch_invoices);
    $new_synced = $synced + $batch_created + $batch_updated;
    $new_created = $created + $batch_created;
    $new_updated = $updated + $batch_updated;
    $stats['failed'] = array_merge($stats['failed'] ?? array(), $batch_failed);
    set_transient('zoho_invoice_sync_stats', $stats, 600);
    
    $has_more = $new_offset < $total;
    
    if (!$has_more) {
        delete_transient('zoho_invoice_sync_invoices');
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
   WOO CUSTOMERS TO ZOHO CONTACTS
====================================================== */
function zoho_sync_customers_to_contacts($offset, $total, $synced, $created, $updated, $from_date, $to_date) {
    // First call - fetch WooCommerce customers
    if ($offset == 0 && $total == 0) {
        // Clear old transients first
        delete_transient('zoho_contact_sync_customers');
        delete_transient('zoho_contact_sync_stats');
        
        $args = array(
            'role' => 'customer',
            'number' => -1,
            'fields' => 'ID'
        );
        
        if (!empty($from_date)) {
            $args['date_query'] = array(
                array('after' => $from_date, 'inclusive' => true)
            );
        }
        if (!empty($to_date)) {
            if (!isset($args['date_query'])) $args['date_query'] = array();
            $args['date_query'][] = array('before' => $to_date, 'inclusive' => true);
        }
        
        $users = get_users($args);
        $total = count($users);
        
        error_log('Zoho Sync: Found ' . $total . ' customers to sync (WooCommerce to Zoho)');
        
        if ($total == 0) {
            wp_send_json_error('No customers found in WooCommerce');
            return;
        }
        
        set_transient('zoho_contact_sync_customers', $users, 600);
        set_transient('zoho_contact_sync_stats', array('synced' => 0, 'created' => 0, 'updated' => 0, 'failed' => array()), 600);
    } else {
        $users = get_transient('zoho_contact_sync_customers');
        if (!$users) {
            wp_send_json_error('Sync session expired. Please try again.');
            return;
        }
        $total = count($users);
    }
    
    // Process batch
    $batch_size = 10;
    $batch_ids = array_slice($users, $offset, $batch_size);
    $batch_created = 0;
    $batch_updated = 0;
    $batch_failed = array();
    
    foreach ($batch_ids as $user_id) {
        $customer = new WC_Customer($user_id);
        
        // Build contact data from field mappings
        $contact_data = zoho_build_contact_data_from_customer($customer);
        
        // Log the data being sent
        error_log('Zoho Contact Sync - User ID: ' . $user_id . ' - Data: ' . json_encode($contact_data));
        
        if (empty($contact_data['contact_name'])) {
            $batch_failed[] = array('id' => $user_id, 'reason' => 'No contact name (email: ' . $customer->get_email() . ')');
            continue;
        }
        
        // Check if contact exists
        $existing_contact_id = get_user_meta($user_id, '_zoho_contact_id', true);
        
        $request_data = json_encode($contact_data);
        
        if ($existing_contact_id) {
            $response = zoho_api_put('contacts/' . $existing_contact_id, $contact_data);
            $action = 'updated';
        } else {
            $response = zoho_api_post('contacts', $contact_data);
            $action = 'created';
        }
        
        // Log the response
        error_log('Zoho Contact Sync - Response: ' . json_encode($response));
        
        $zoho_contact_id = '';
        if (isset($response['contact']['contact_id'])) {
            $zoho_contact_id = $response['contact']['contact_id'];
            update_user_meta($user_id, '_zoho_contact_id', $zoho_contact_id);
        }
        
        zoho_add_detailed_log('contact', 'customer', $user_id, 'contact', $zoho_contact_id, $request_data, json_encode($response));
        
        // Check for errors - handle both WP_Error and API error responses
        $has_error = false;
        $error_msg = '';
        
        if (is_wp_error($response)) {
            $has_error = true;
            $error_msg = $response->get_error_message();
        } elseif (isset($response['error'])) {
            $has_error = true;
            $error_msg = $response['error'];
        } elseif (isset($response['code']) && $response['code'] != 0) {
            $has_error = true;
            $error_msg = 'API Code ' . $response['code'] . ': ' . ($response['message'] ?? 'Unknown error');
        }
        
        if ($has_error) {
            $batch_failed[] = array('id' => $user_id, 'reason' => $error_msg);
            error_log('Zoho Contact Sync FAILED - User ID: ' . $user_id . ' - Error: ' . $error_msg);
        } else {
            if ($action == 'created') $batch_created++;
            else $batch_updated++;
        }
    }
    
    // Update stats
    $stats = get_transient('zoho_contact_sync_stats') ?: array('synced' => 0, 'created' => 0, 'updated' => 0, 'failed' => array());
    $new_offset = $offset + count($batch_ids);
    $new_synced = $synced + $batch_created + $batch_updated;
    $new_created = $created + $batch_created;
    $new_updated = $updated + $batch_updated;
    $stats['failed'] = array_merge($stats['failed'] ?? array(), $batch_failed);
    set_transient('zoho_contact_sync_stats', $stats, 600);
    
    $has_more = $new_offset < $total;
    
    if (!$has_more) {
        delete_transient('zoho_contact_sync_customers');
        delete_transient('zoho_contact_sync_stats');
    }
    
    // Get failure reasons for display
    $failure_reasons = array();
    foreach ($stats['failed'] as $fail) {
        $failure_reasons[] = 'ID ' . $fail['id'] . ': ' . $fail['reason'];
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
        error_log('Zoho Sync: Found ' . $total . ' contacts from Zoho API');
        
        if ($total == 0) {
            wp_send_json_error('No contacts found in Zoho');
            return;
        }
        
        set_transient('zoho_contact_sync_contacts', $all_contacts, 600);
        set_transient('zoho_contact_sync_stats', array('synced' => 0, 'created' => 0, 'updated' => 0, 'failed' => array()), 600);
    } else {
        $contacts = get_transient('zoho_contact_sync_contacts');
        if (!$contacts) {
            wp_send_json_error('Sync session expired. Please try again.');
            return;
        }
        $total = count($contacts);
    }
    
    // Process batch
    $batch_size = 10;
    $batch_contacts = array_slice($contacts, $offset, $batch_size);
    $batch_created = 0;
    $batch_updated = 0;
    $batch_failed = array();
    
    foreach ($batch_contacts as $contact) {
        $zoho_contact_id = $contact['contact_id'] ?? '';
        $email = $contact['email'] ?? '';
        
        if (empty($email)) {
            $batch_failed[] = array('id' => $zoho_contact_id, 'reason' => 'No email');
            continue;
        }
        
        // Check if user exists
        $existing_user = get_user_by('email', $email);
        
        if ($existing_user) {
            // Update existing user
            update_user_meta($existing_user->ID, 'billing_first_name', $contact['first_name'] ?? '');
            update_user_meta($existing_user->ID, 'billing_last_name', $contact['last_name'] ?? '');
            update_user_meta($existing_user->ID, 'billing_company', $contact['company_name'] ?? '');
            update_user_meta($existing_user->ID, 'billing_phone', $contact['phone'] ?? '');
            update_user_meta($existing_user->ID, '_zoho_contact_id', $zoho_contact_id);
            
            $batch_updated++;
            zoho_add_detailed_log('contact', 'customer', $existing_user->ID, 'contact', $zoho_contact_id, json_encode($contact), json_encode(array('message' => 'Updated')));
        } else {
            // Create new user
            $username = sanitize_user($email);
            $password = wp_generate_password();
            
            $user_id = wc_create_new_customer($email, $username, $password);
            
            if (is_wp_error($user_id)) {
                $batch_failed[] = array('id' => $zoho_contact_id, 'reason' => $user_id->get_error_message());
                continue;
            }
            
            update_user_meta($user_id, 'billing_first_name', $contact['first_name'] ?? '');
            update_user_meta($user_id, 'billing_last_name', $contact['last_name'] ?? '');
            update_user_meta($user_id, 'billing_company', $contact['company_name'] ?? '');
            update_user_meta($user_id, 'billing_phone', $contact['phone'] ?? '');
            update_user_meta($user_id, '_zoho_contact_id', $zoho_contact_id);
            
            $batch_created++;
            zoho_add_detailed_log('contact', 'customer', $user_id, 'contact', $zoho_contact_id, json_encode($contact), json_encode(array('message' => 'Created', 'user_id' => $user_id)));
        }
    }
    
    // Update stats
    $stats = get_transient('zoho_contact_sync_stats') ?: array('synced' => 0, 'created' => 0, 'updated' => 0, 'failed' => array());
    $new_offset = $offset + count($batch_contacts);
    $new_synced = $synced + $batch_created + $batch_updated;
    $new_created = $created + $batch_created;
    $new_updated = $updated + $batch_updated;
    $stats['failed'] = array_merge($stats['failed'] ?? array(), $batch_failed);
    set_transient('zoho_contact_sync_stats', $stats, 600);
    
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
    // Default mappings for Zoho contact fields to WooCommerce customer fields
    $default_mappings = array(
        'contact_name' => 'full_name',
        'company_name' => 'billing_company',
        'contact_type' => 'customer',
        'customer_sub_type' => 'individual',
        'first_name' => 'billing_first_name',
        'last_name' => 'billing_last_name',
        'email' => 'billing_email',
        'billing_address' => array(
            'address' => 'billing_address_1',
            'street2' => 'billing_address_2',
            'city' => 'billing_city',
            'state' => 'billing_state',
            'zip' => 'billing_postcode',
            'country' => 'billing_country',
            'phone' => 'billing_phone',
        ),
        'shipping_address' => array(
            'address' => 'shipping_address_1',
            'street2' => 'shipping_address_2',
            'city' => 'shipping_city',
            'state' => 'shipping_state',
            'zip' => 'shipping_postcode',
            'country' => 'shipping_country',
        ),
    );
    
    // Build contact data with defaults (no need for complex option checks)
    $contact_data = array(
        'contact_name' => trim($customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name()),
        'contact_type' => 'customer',
        'customer_sub_type' => 'individual',
        'first_name' => $customer->get_billing_first_name(),
        'last_name' => $customer->get_billing_last_name(),
        'email' => $customer->get_billing_email(),
        'company_name' => $customer->get_billing_company(),
        'billing_address' => array(
            'address' => $customer->get_billing_address_1(),
            'street2' => $customer->get_billing_address_2(),
            'city' => $customer->get_billing_city(),
            'state' => $customer->get_billing_state(),
            'zip' => $customer->get_billing_postcode(),
            'country' => $customer->get_billing_country(),
            'phone' => $customer->get_billing_phone(),
        ),
        'shipping_address' => array(
            'address' => $customer->get_shipping_address_1(),
            'street2' => $customer->get_shipping_address_2(),
            'city' => $customer->get_shipping_city(),
            'state' => $customer->get_shipping_state(),
            'zip' => $customer->get_shipping_postcode(),
            'country' => $customer->get_shipping_country(),
        ),
    );
    
    // Remove empty values
    if (empty($contact_data['contact_name'])) {
        // Fallback to email if no name
        $contact_data['contact_name'] = $customer->get_billing_email();
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
    
    // Check if contact exists by email
    $response = zoho_api_get('contacts?email=' . urlencode($email));
    
    if (!is_wp_error($response) && isset($response['contacts']) && !empty($response['contacts'])) {
        return $response['contacts'][0]['contact_id'];
    }
    
    // Create new contact
    $contact_data = array(
        'contact_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
        'contact_type' => 'customer',
        'customer_sub_type' => 'individual',
        'first_name' => $order->get_billing_first_name(),
        'last_name' => $order->get_billing_last_name(),
        'email' => $email,
        'company_name' => $order->get_billing_company(),
        'billing_address' => array(
            'address' => $order->get_billing_address_1(),
            'street2' => $order->get_billing_address_2(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'zip' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),
            'phone' => $order->get_billing_phone(),
        ),
        'shipping_address' => array(
            'address' => $order->get_shipping_address_1(),
            'street2' => $order->get_shipping_address_2(),
            'city' => $order->get_shipping_city(),
            'state' => $order->get_shipping_state(),
            'zip' => $order->get_shipping_postcode(),
            'country' => $order->get_shipping_country(),
        ),
    );
    
    $response = zoho_api_post('contacts', $contact_data);
    
    if (!is_wp_error($response) && isset($response['contact']['contact_id'])) {
        return $response['contact']['contact_id'];
    }
    
    return null;
}

/* ======================================================
   HELPER: Get Order by Zoho Invoice ID
====================================================== */
function zoho_get_order_by_zoho_invoice($zoho_invoice_id) {
    global $wpdb;
    
    $order_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zoho_invoice_id' AND meta_value = %s LIMIT 1",
        $zoho_invoice_id
    ));
    
    return $order_id ? intval($order_id) : null;
}

/* ======================================================
   HELPER: Get WC Customer by Zoho Contact
====================================================== */
function zoho_get_wc_customer_by_zoho_contact($zoho_contact_id) {
    global $wpdb;
    
    $user_id = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_zoho_contact_id' AND meta_value = %s LIMIT 1",
        $zoho_contact_id
    ));
    
    return $user_id ? intval($user_id) : null;
}

/* ======================================================
   HELPERS
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
    echo '<p>Configure contact field mappings in <a href="'.admin_url('admin.php?page=zoho-field-mapping&tab=customers').'">Field Mapping → Customers</a> before syncing.</p>';
    
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
                            
                            $('#zoho-contact-sync-result').html(resultHtml).show();
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

/* ======================================================
   LOGS PAGE - Detailed Sync Logs
====================================================== */
function zoho_logs_page() {
    echo '<h1>Sync Logs</h1>';
    echo '<p>View detailed sync logs for all sync operations.</p>';
    
    // Get current tab
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'items';
    
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
    
    // View single log detail
    if (isset($_GET['view_log'])) {
        $logs = get_option('zoho_detailed_logs', []);
        $log_id = sanitize_text_field($_GET['view_log']);
        
        foreach ($logs as $log) {
            if (isset($log['id']) && $log['id'] === $log_id) {
                echo '<div style="margin-bottom: 20px;">';
                echo '<a href="'.admin_url('admin.php?page=zoho-logs&tab='.$tab_name).'" class="button">&larr; Back to Logs</a>';
                echo '</div>';
                
                echo '<div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 4px;">';
                echo '<h2 style="margin-top: 0;">Log Details</h2>';
                echo '<table class="form-table">';
                echo '<tr><th>Time</th><td>'.esc_html($log['time']).'</td></tr>';
                echo '<tr><th>Woo Object</th><td>'.esc_html($log['woo_object'] ?? $woo_object_default).'</td></tr>';
                echo '<tr><th>Woo Object Id</th><td>'.esc_html($log['woo_object_id'] ?? '-').'</td></tr>';
                echo '<tr><th>Zoho Inventory Object</th><td>'.esc_html($log['zoho_object'] ?? $zoho_object_default).'</td></tr>';
                echo '<tr><th>Zoho Inventory Object Id</th><td>'.esc_html($log['zoho_object_id'] ?? '-').'</td></tr>';
                echo '</table>';
                echo '</div>';
                
                // Request
                echo '<div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 4px;">';
                echo '<h3 style="margin-top: 0;">Request</h3>';
                echo '<pre style="background: #f5f5f5; padding: 15px; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; max-height: 400px;">'.esc_html(json_encode(json_decode($log['request'] ?? '{}'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>';
                echo '</div>';
                
                // Response
                echo '<div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 4px;">';
                echo '<h3 style="margin-top: 0;">Response</h3>';
                echo '<pre style="background: #f5f5f5; padding: 15px; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; max-height: 400px;">'.esc_html(json_encode(json_decode($log['response'] ?? '{}'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>';
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
    
    $total_logs = count($filtered_logs);
    $total_pages = ceil($total_logs / $per_page);
    
    if ($total_logs == 0) {
        echo '<p>No '.str_replace('_', ' ', $type).' sync logs yet.</p>';
        return;
    }
    
    // Get logs for current page
    $offset = ($current_page - 1) * $per_page;
    $page_logs = array_slice($filtered_logs, $offset, $per_page);
    
    // Show count
    echo '<p style="margin-bottom: 15px;">Showing '.($offset + 1).' - '.min($offset + $per_page, $total_logs).' of '.$total_logs.' logs</p>';
    
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>
        <th style="width: 50px;">Expand</th>
        <th>Woo Object</th>
        <th>Woo Object Id</th>
        <th>Zoho Inventory Object</th>
        <th>Zoho Inventory Object Id</th>
        <th>Time</th>
    </tr></thead><tbody>';
    
    foreach ($page_logs as $log) {
        $zoho_link = $log['zoho_object_id'] ?? '-';
        if ($type == 'item' && !empty($log['zoho_object_id'])) {
            $zoho_link = '<a href="https://inventory.zoho.com/app#/inventory/items/'.esc_attr($log['zoho_object_id']).'" target="_blank">'.esc_html($log['zoho_object_id']).'</a>';
        } else {
            $zoho_link = esc_html($log['zoho_object_id'] ?? '-');
        }
        
        echo '<tr>';
        echo '<td><a href="'.admin_url('admin.php?page=zoho-logs&tab='.$tab_name.'&view_log='.esc_attr($log['id'])).'" class="button button-small">-</a></td>';
        echo '<td>'.esc_html($log['woo_object'] ?? $woo_object_default).'</td>';
        echo '<td>'.esc_html($log['woo_object_id'] ?? '-').'</td>';
        echo '<td>'.esc_html($log['zoho_object'] ?? $zoho_object_default).'</td>';
        echo '<td>'.$zoho_link.'</td>';
        echo '<td>'.esc_html($log['time'] ?? '-').'</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    
    // Pagination
    if ($total_pages > 1) {
        echo '<div class="tablenav bottom"><div class="tablenav-pages">';
        echo '<span class="displaying-num">'.$total_logs.' items</span>';
        echo '<span class="pagination-links">';
        
        // First page
        if ($current_page > 1) {
            echo '<a class="first-page button" href="'.admin_url('admin.php?page=zoho-logs&tab='.$tab_name.'&paged=1').'">«</a> ';
            echo '<a class="prev-page button" href="'.admin_url('admin.php?page=zoho-logs&tab='.$tab_name.'&paged='.($current_page - 1)).'">‹</a> ';
        } else {
            echo '<span class="tablenav-pages-navspan button disabled">«</span> ';
            echo '<span class="tablenav-pages-navspan button disabled">‹</span> ';
        }
        
        echo '<span class="paging-input">';
        echo '<span class="tablenav-paging-text"> '.$current_page.' of <span class="total-pages">'.$total_pages.'</span></span>';
        echo '</span>';
        
        // Next/Last page
        if ($current_page < $total_pages) {
            echo ' <a class="next-page button" href="'.admin_url('admin.php?page=zoho-logs&tab='.$tab_name.'&paged='.($current_page + 1)).'">›</a>';
            echo ' <a class="last-page button" href="'.admin_url('admin.php?page=zoho-logs&tab='.$tab_name.'&paged='.$total_pages).'">»</a>';
        } else {
            echo ' <span class="tablenav-pages-navspan button disabled">›</span>';
            echo ' <span class="tablenav-pages-navspan button disabled">»</span>';
        }
        
        echo '</span></div></div>';
    }
}

/* ======================================================
   HELPER: Add Detailed Log Entry
====================================================== */
function zoho_add_detailed_log($type, $woo_object, $woo_object_id, $zoho_object, $zoho_object_id, $request, $response) {
    $logs = get_option('zoho_detailed_logs', []);
    
    $logs[] = array(
        'id' => uniqid('log_'),
        'type' => $type, // 'item', 'invoice', 'contact'
        'time' => current_time('d-m-Y h:i A'),
        'woo_object' => $woo_object,
        'woo_object_id' => $woo_object_id,
        'zoho_object' => $zoho_object,
        'zoho_object_id' => $zoho_object_id,
        'request' => is_string($request) ? $request : json_encode($request),
        'response' => is_string($response) ? $response : json_encode($response),
    );
    
    // Keep only last 100 logs
    update_option('zoho_detailed_logs', array_slice($logs, -100));
}