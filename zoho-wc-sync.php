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
}

/* ======================================================
   ENQUEUE SCRIPTS FOR PROGRESS BAR
====================================================== */
add_action('admin_enqueue_scripts', 'zoho_enqueue_sync_scripts');
function zoho_enqueue_sync_scripts($hook) {
    // Only load on our sync pages
    if ($hook != 'toplevel_page_zoho-sync' && $hook != 'zoho-sync_page_zoho-inventory-sync' && $hook != 'zoho-sync_page_zoho-field-mapping' && $hook != 'zoho-sync_page_zoho-invoice-sync') {
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
    
    // Only load sync script on inventory sync page
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
    
    // Show success message from previous sync (if exists)
    if (isset($_GET['sync_completed'])) {
        echo '<div id="zoho-sync-success" class="notice notice-success">
            <p><strong>Sync Completed!</strong></p>
            <p>Total: '.intval($_GET['total']).' | Synced: '.intval($_GET['synced']).' | Created: '.intval($_GET['created']).' | Updated: '.intval($_GET['updated']).' | Pending: '.intval($_GET['pending']).'</p>
        </div>';
    } else {
        echo '<div id="zoho-sync-success" style="display:none;"></div>';
    }

    // Progress bar container
    echo '<div id="zoho-sync-progress" style="display:none; margin: 20px 0;">
        <div style="background: #f0f0f0; border-radius: 4px; padding: 3px; margin-bottom: 10px;">
            <div id="zoho-progress-bar" style="background: #2271b1; height: 24px; border-radius: 3px; width: 0%; transition: width 0.3s ease; text-align: center; line-height: 24px; color: white; font-weight: bold;">
                <span id="zoho-progress-percentage">0%</span>
            </div>
        </div>
        <p id="zoho-progress-text" style="margin: 5px 0;">Starting sync...</p>
    </div>';

    echo '<form method="post">
        <button id="zoho-start-sync" class="button button-primary" type="button">Sync Products Now</button>
    </form>';

    /* ---------- LOG TABLE ---------- */
    $logs = get_option('zoho_inventory_logs', []);

    echo '<h2>Sync History</h2>';

    if (!$logs) {
        echo '<p>No sync history.</p>';
        return;
    }

    echo '<table class="widefat striped">
    <thead>
        <tr><th>Date</th><th>Total</th><th>Synced</th><th>Pending</th><th>Action</th></tr>
    </thead><tbody>';

    foreach (array_reverse($logs) as $log) {

        $view_url = admin_url('admin.php?page=zoho-inventory-sync&view_log='.$log['id']);
        $delete_url = wp_nonce_url(
            admin_url('admin.php?page=zoho-inventory-sync&zoho_delete_log='.$log['id']),
            'zoho_delete_log'
        );

        echo '<tr>
            <td>'.$log['time'].'</td>
            <td>'.$log['total'].'</td>
            <td>'.$log['synced'].'</td>
            <td>'.$log['pending'].'</td>
            <td>
                <a class="button button-small" href="'.esc_url($view_url).'">View</a>
                <a class="button button-small button-link-delete"
                   onclick="return confirm(\'Are you sure?\');"
                   href="'.esc_url($delete_url).'">Delete</a>
            </td>
        </tr>';
    }

    echo '</tbody></table>';
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
    
    // First call - fetch items from API
    if ($offset == 0 && $total == 0) {
        $response = zoho_api_get('items');
        
        if (is_wp_error($response)) {
            wp_send_json_error('API call failed: ' . $response->get_error_message());
            return;
        }
        
        if (isset($response['error'])) {
            wp_send_json_error($response['error']);
            return;
        }
        
        if (empty($response)) {
            wp_send_json_error('Empty response from Zoho API');
            return;
        }
        
        if (isset($response['code']) && $response['code'] != 0) {
            $error_msg = $response['message'] ?? 'Unknown error';
            wp_send_json_error('API Error (Code: ' . $response['code'] . '): ' . $error_msg);
            return;
        }
        
        if (!isset($response['items']) || !is_array($response['items'])) {
            wp_send_json_error('No items found in API response');
            return;
        }
        
        $items = $response['items'];
        $total = count($items);
        
        // Store items in transient for batch processing
        set_transient('zoho_sync_items', $items, 300); // 5 minutes
        set_transient('zoho_sync_stats', array('synced' => 0, 'created' => 0, 'updated' => 0, 'pending' => 0, 'synced_skus' => array(), 'pending_skus' => array()), 300);
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
    
    // Check if invoice sync is enabled
    $invoice_sync_enabled = get_option('zoho_sync_invoice', 'no');
    
    if ($invoice_sync_enabled != 'yes') {
        echo '<div class="notice notice-warning"><p><strong>Invoice Sync is disabled.</strong> Please enable it from <a href="'.admin_url('admin.php?page=zoho-field-mapping').'">Field Mapping</a> settings.</p></div>';
    }
    
    echo '<p>Sync invoices between Zoho Inventory and WooCommerce Orders.</p>';
    echo '<p><strong>Note:</strong> Invoice sync functionality will be implemented in the next phase.</p>';
    
    // Placeholder for invoice sync UI
    echo '<div style="margin: 20px 0; padding: 20px; background: #f9f9f9; border: 1px solid #ddd;">
        <h3>Sync Direction</h3>
        <p>Choose sync direction:</p>
        <ul>
            <li><strong>Zoho → WooCommerce:</strong> Create/Update WooCommerce orders from Zoho invoices</li>
            <li><strong>WooCommerce → Zoho:</strong> Create/Update Zoho invoices from WooCommerce orders</li>
            <li><strong>Two-Way Sync:</strong> Sync in both directions (recommended)</li>
        </ul>
    </div>';
    
    echo '<form method="post">
        <button class="button button-primary" type="button" disabled>Sync Invoices Now (Coming Soon)</button>
    </form>';
    
    // Invoice sync logs placeholder
    echo '<h2>Invoice Sync History</h2>';
    echo '<p>No invoice sync history yet.</p>';
}