<?php
/**
 * Plugin Name: Zoho Inventory WooCommerce Sync
 * Description: Zoho Inventory → WooCommerce Inventory Sync
 * Version: 1.2.3
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
        'Contact Sync',
        'Contact Sync',
        'manage_options',
        'zoho-contact-sync',
        'zoho_contact_sync_page'
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
        // Use timestamp for cache busting
        wp_enqueue_script('zoho-sync-script', plugin_dir_url(__FILE__) . 'js/zoho-sync.js', array('jquery'), '2.0.' . time(), true);
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

    // Get sync statistics from product/customer meta (unique Zoho IDs)
    global $wpdb;
    
    // Count unique Zoho item IDs linked (distinct _zoho_item_id values)
    $items_linked = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT meta_value) 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = %s 
        AND meta_value != '' 
        AND meta_value IS NOT NULL
    ", '_zoho_item_id'));
    
    // Count WooCommerce products that have _zoho_item_id (products linked)
    $products_linked = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT post_id) 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = %s 
        AND meta_value != '' 
        AND meta_value IS NOT NULL
    ", '_zoho_item_id'));
    
    // Count unique Zoho contact IDs linked (distinct _zoho_contact_id values)
    $contacts_linked = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT meta_value) 
        FROM {$wpdb->usermeta} 
        WHERE meta_key = %s 
        AND meta_value != '' 
        AND meta_value IS NOT NULL
    ", '_zoho_contact_id'));
    
    // Count WooCommerce customers that have _zoho_contact_id (customers linked)
    // Only count users with customer role
    $customers_linked = $wpdb->get_var("
        SELECT COUNT(DISTINCT um.user_id) 
        FROM {$wpdb->usermeta} um
        INNER JOIN {$wpdb->users} u ON um.user_id = u.ID
        INNER JOIN {$wpdb->usermeta} role_meta ON um.user_id = role_meta.user_id 
            AND role_meta.meta_key = '{$wpdb->prefix}capabilities'
            AND role_meta.meta_value LIKE '%customer%'
        WHERE um.meta_key = '_zoho_contact_id' 
        AND um.meta_value != '' 
        AND um.meta_value IS NOT NULL
    ");
    
    // Count total WooCommerce customers (with customer role only)
    $total_customers = $wpdb->get_var("
        SELECT COUNT(DISTINCT u.ID) 
        FROM {$wpdb->users} u
        INNER JOIN {$wpdb->usermeta} role_meta ON u.ID = role_meta.user_id 
            AND role_meta.meta_key = '{$wpdb->prefix}capabilities'
            AND role_meta.meta_value LIKE '%customer%'
    ");
    
    // Get invoices count from logs (no meta tracking for invoices yet)
    $detailed_logs = get_option('zoho_detailed_logs', []);
    $invoices_synced = 0;
    foreach ($detailed_logs as $log) {
        if (zoho_is_log_successful($log) && ($log['type'] ?? '') === 'invoice') {
            $invoices_synced++;
        }
    }
    
    // Also get WooCommerce counts for context
    $total_products = wp_count_posts('product');
    $published_products = $total_products->publish ?? 0;
    $total_orders = wp_count_posts('shop_order');
    
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
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px;">
            <!-- Items Synced Card -->
            <div class="zoho-stat-card">
                <h3 class="zoho-stat-label">Zoho Items Linked</h3>
                <div class="zoho-stat-number"><?php echo number_format($items_linked ?: 0); ?></div>
                <div style="font-size: 12px; color: #666; margin-top: 5px;"><?php echo number_format($products_linked ?: 0); ?> of <?php echo number_format($published_products); ?> products linked</div>
            </div>
            
            <!-- Invoices Synced Card -->
            <div class="zoho-stat-card">
                <h3 class="zoho-stat-label">Invoices Synced</h3>
                <div class="zoho-stat-number"><?php echo number_format($invoices_synced); ?></div>
                <div style="font-size: 12px; color: #666; margin-top: 5px;">sync logs (30 days)</div>
            </div>
            
            <!-- Contacts Synced Card -->
            <div class="zoho-stat-card">
                <h3 class="zoho-stat-label">Zoho Contacts Linked</h3>
                <div class="zoho-stat-number"><?php echo number_format($contacts_linked ?: 0); ?></div>
                <div style="font-size: 12px; color: #666; margin-top: 5px;"><?php echo number_format($customers_linked ?: 0); ?> of <?php echo number_format($total_customers); ?> customers linked</div>
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
    
    echo '<h1>Field Mapping & Sync Settings</h1>';
    echo '<p style="color: #666;">Configure how Zoho Inventory fields map to WooCommerce fields for product sync.</p>';
    
    echo '<div class="tab-content" style="margin-top: 20px;">';
    
    // Only show Items field mapping - Invoices and Contacts use standard defaults
    zoho_field_mapping_items_tab();
    
    // Info about Invoices and Contacts
    echo '<div style="background: #f0f6fc; border: 1px solid #c3c4c7; padding: 15px; margin-top: 30px; border-radius: 4px;">';
    echo '<h3 style="margin-top: 0;">📋 Invoice & Contact Sync</h3>';
    echo '<p>Invoice and Contact sync uses <strong>standard field mappings</strong> automatically:</p>';
    echo '<ul style="margin-left: 20px;">';
    echo '<li><strong>Invoices:</strong> Order ID, Customer, Date, Line Items, Totals</li>';
    echo '<li><strong>Contacts:</strong> Name, Email, Phone, Billing Address, Shipping Address</li>';
    echo '</ul>';
    echo '<p style="margin-bottom: 0; color: #666;">No custom configuration needed. Use the <strong>Invoice Sync</strong> and <strong>Contact Sync</strong> pages to sync data.</p>';
    echo '</div>';
    
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
            $error_reason = 'Missing SKU';
            $batch_pending_skus[] = ['sku'=>'(empty)','reason'=>$error_reason];
            
            // Log the failure
            $zoho_item_id = isset($item['item_id']) ? $item['item_id'] : '';
            $item_name = isset($item[$zoho_name_field]) ? $item[$zoho_name_field] : (isset($item['name']) ? $item['name'] : 'Unknown');
            zoho_add_detailed_log(
                'item',
                'product',
                0,
                'items-inventory',
                $zoho_item_id,
                json_encode($item),
                json_encode(array('error' => $error_reason, 'code' => 'MISSING_SKU', 'item_name' => $item_name))
            );
                continue;
            }

        $product_id = wc_get_product_id_by_sku($sku_value);
        $is_new_product = false;
        $product = null;
        
        try {
            if (!$product_id) {
                // Create new product
                $product = new WC_Product_Simple();
                if (!$product) {
                    throw new Exception('Failed to create WC_Product_Simple object');
                }
                
                $product->set_sku($sku_value);
                
                // Get name from mapped field
                $name_value = isset($item[$zoho_name_field]) ? $item[$zoho_name_field] : (isset($item['name']) ? $item['name'] : $sku_value);
                if (empty($name_value)) {
                    $name_value = $sku_value; // Fallback to SKU if name is empty
                }
                $product->set_name($name_value);
                $product->set_slug(sanitize_title($name_value));
                $product->set_status('publish');
                $product->set_catalog_visibility('visible');
                
                $is_new_product = true;
                $batch_created++;
            } else {
                $product = wc_get_product($product_id);
                if (!$product) {
                    throw new Exception('Failed to load product with ID: ' . $product_id);
                }
                $batch_updated++;
            }
        } catch (Exception $e) {
            // Log the failure
            $error_reason = 'Product object creation failed: ' . $e->getMessage();
            $batch_pending_skus[] = ['sku' => $sku_value, 'reason' => $error_reason];
            
            $zoho_item_id = isset($item['item_id']) ? $item['item_id'] : '';
            $item_name = isset($item[$zoho_name_field]) ? $item[$zoho_name_field] : (isset($item['name']) ? $item['name'] : 'Unknown');
            zoho_add_detailed_log(
                'item',
                'product',
                0,
                'items-inventory',
                $zoho_item_id,
                json_encode($item),
                json_encode(array('error' => $error_reason, 'code' => 'PRODUCT_CREATE_FAILED', 'item_name' => $item_name, 'sku' => $sku_value, 'exception' => $e->getMessage()))
            );
            
            // Adjust counters
            if ($is_new_product) {
                $batch_created = max(0, $batch_created - 1);
            } else {
                $batch_updated = max(0, $batch_updated - 1);
            }
            
            continue; // Skip to next item
        }
        
        // If product is null, skip
        if (!$product) {
                continue;
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

        // Try to save product with error handling
        try {
            $product->save();
            $saved_product_id = $product->get_id();
            
            // Check if save was successful
            if (!$saved_product_id || $saved_product_id == 0) {
                throw new Exception('Product save failed - no product ID returned');
            }
            
            // Permalink Manager integration
            if ($saved_product_id && $is_new_product && class_exists('Permalink_Manager_URI_Functions_Post')) {
                global $permalink_manager_uris;
                if (isset($permalink_manager_uris[$saved_product_id])) {
                    unset($permalink_manager_uris[$saved_product_id]);
                }
                Permalink_Manager_URI_Functions_Post::save_uri($saved_product_id, '', true, 0);
            }
            
            // Add detailed log for success
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
            
        } catch (Exception $e) {
            // Log the failure
            $error_reason = 'Product save failed: ' . $e->getMessage();
            $batch_pending_skus[] = ['sku' => $sku_value, 'reason' => $error_reason];
            
            $zoho_item_id = isset($item['item_id']) ? $item['item_id'] : '';
            $item_name = isset($item[$zoho_name_field]) ? $item[$zoho_name_field] : (isset($item['name']) ? $item['name'] : 'Unknown');
            zoho_add_detailed_log(
                'item',
                'product',
                0,
                'items-inventory',
                $zoho_item_id,
                json_encode($item),
                json_encode(array('error' => $error_reason, 'code' => 'PRODUCT_SAVE_FAILED', 'item_name' => $item_name, 'sku' => $sku_value, 'exception' => $e->getMessage()))
            );
            
            // Adjust counters if this was counted as created/updated
            if ($is_new_product) {
                $batch_created = max(0, $batch_created - 1);
            } else {
                $batch_updated = max(0, $batch_updated - 1);
            }
        }
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
    // First call - fetch WooCommerce products AND all Zoho items
    if (intval($offset) === 0 && intval($total) === 0) {
        // Clear old transients first to ensure fresh data
        delete_transient('zoho_woo_sync_products');
        delete_transient('zoho_woo_sync_stats');
        delete_transient('zoho_woo_sync_zoho_items_map');
        
        // Use WP_Query to get ALL products (same query as WordPress admin edit.php?post_type=product)
        $args = array(
            'post_type' => 'product',
            'post_status' => array('publish', 'draft', 'private', 'pending'),
            'posts_per_page' => -1, // Get all products
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids' // Only get IDs for better performance
        );
        
        $query = new WP_Query($args);
        $product_ids = $query->posts;
        
        $total = count($product_ids);
        
        if ($total == 0) {
            wp_send_json_error('No products found in WooCommerce');
        return;
    }

        // OPTIMIZATION: Fetch ALL Zoho items once and create SKU lookup map
        // This eliminates per-product SKU lookup API calls (reduces from 2 calls/product to 1 call/product)
        $zoho_items_map = array(); // SKU => item_id
        $page = 1;
        $per_page = 200; // Zoho default limit
        
        do {
            $response = zoho_api_get('items?page=' . $page . '&per_page=' . $per_page);
            
            if (is_wp_error($response)) {
                wp_send_json_error('Failed to fetch Zoho items: ' . $response->get_error_message());
                return;
            }
            
            if (isset($response['error']) || (isset($response['code']) && $response['code'] != 0)) {
                $error_msg = $response['message'] ?? ($response['error'] ?? 'Unknown error');
                wp_send_json_error('Zoho API Error: ' . $error_msg);
                return;
            }
            
            $items = $response['items'] ?? array();
            foreach ($items as $item) {
                $item_sku = $item['sku'] ?? '';
                if (!empty($item_sku)) {
                    $zoho_items_map[strtolower($item_sku)] = $item['item_id']; // Store as lowercase for case-insensitive lookup
                }
            }
            
            $has_more_pages = isset($response['page_context']['has_more_page']) ? $response['page_context']['has_more_page'] : (count($items) >= $per_page);
            $page++;
            
        } while ($has_more_pages && $page <= 50); // Max 50 pages = 10,000 items safety limit
        
        // Store product IDs and Zoho items map in transient for batch processing (20 minutes for large syncs)
        // Ensure map is always an array (even if empty)
        if (!is_array($zoho_items_map)) {
            $zoho_items_map = array();
        }
        set_transient('zoho_woo_sync_products', $product_ids, 1200); // 20 minutes
        set_transient('zoho_woo_sync_zoho_items_map', $zoho_items_map, 1200); // 20 minutes
        set_transient('zoho_woo_sync_stats', array('synced' => 0, 'created' => 0, 'updated' => 0, 'pending' => 0, 'synced_skus' => array(), 'pending_skus' => array()), 1200); // 20 minutes
    } else {
        // Get product IDs and Zoho items map from transient
        $product_ids = get_transient('zoho_woo_sync_products');
        $zoho_items_map = get_transient('zoho_woo_sync_zoho_items_map');
        // Check if transients exist (false means not set, empty array is valid)
        if ($product_ids === false) {
            wp_send_json_error('Sync session expired: Product IDs not found. Please start sync again.');
            return;
        }
        if ($zoho_items_map === false) {
            wp_send_json_error('Sync session expired: Zoho items map not found. Please start sync again.');
            return;
        }
        // Initialize empty map if not set (shouldn't happen, but safety check)
        if (!is_array($zoho_items_map)) {
            $zoho_items_map = array();
        }
        if (!is_array($product_ids)) {
            wp_send_json_error('Sync session expired: Invalid product IDs. Please start sync again.');
            return;
        }
        $total = count($product_ids);
        $stats = get_transient('zoho_woo_sync_stats');
        $synced_skus = $stats['synced_skus'] ?? array();
        $pending_skus = $stats['pending_skus'] ?? array();
    }
    
    // Process batch (5 products per batch to avoid rate limits)
    // Zoho rate limit: 7,500 calls/hour (~125/min = ~2/sec). With 5 products/batch = 5 calls/batch + 3 sec delay = safe
    $batch_size = 5;
    $batch_ids = array_slice($product_ids, $offset, $batch_size);
    $processed = 0;
    $batch_created = 0;
    $batch_updated = 0;
    $batch_synced_skus = array();
    $batch_pending_skus = array();
    
    // Check if connected (API wrapper functions handle organization_id automatically)
    $access_token = get_option('zoho_access_token', '');
    if (empty($access_token)) {
        wp_send_json_error('Zoho not connected. Please connect to Zoho first.');
        return;
    }
    
    foreach ($batch_ids as $product_id) {
            $product = wc_get_product($product_id);
        if (!$product) {
            $batch_pending_skus[] = array('sku' => $product_id, 'reason' => 'Product not found');
            // Log the failure
            zoho_add_detailed_log('item', 'product', $product_id, 'items-inventory', '', '{}', json_encode(array('error' => 'Product not found in WooCommerce')));
            $processed++;
            continue;
        }
        
        $sku = $product->get_sku();
        if (empty($sku)) {
            $batch_pending_skus[] = array('sku' => 'ID:' . $product_id, 'reason' => 'No SKU - ' . $product->get_name());
            // Log the failure
            zoho_add_detailed_log('item', 'product', $product_id, 'items-inventory', '', json_encode(array('name' => $product->get_name())), json_encode(array('error' => 'Product has no SKU')));
            $processed++;
            continue;
        }
        
        // Prepare item data for Zoho
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
            // Handle null, empty, "-", non-numeric, or negative as 0
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
        
        // OPTIMIZED: Check if item exists in Zoho by SKU using pre-fetched map (no API call needed)
        $zoho_item_id = isset($zoho_items_map[strtolower($sku)]) ? $zoho_items_map[strtolower($sku)] : null;
        
        // Only update if found by SKU (don't search by name - name can be duplicate, SKU cannot)
        // If SKU doesn't match, we'll create new item
        
        $request_data = json_encode($item_data);
        
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
        
        // Log the sync and save Zoho Item ID to product
        $zoho_object_id = '';
        if (isset($response['item']['item_id'])) {
            $zoho_object_id = $response['item']['item_id'];
        } elseif (!empty($zoho_item_id)) {
            // For updates, use the ID we already found
            $zoho_object_id = $zoho_item_id;
        }
        
        // Save Zoho Item ID to product meta for tracking (if successful)
        if (!empty($zoho_object_id) && !isset($response['error']) && (!isset($response['code']) || $response['code'] == 0)) {
            update_post_meta($product_id, '_zoho_item_id', $zoho_object_id);
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
        
        // Check for errors
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
            $error_msg = 'Code ' . $response['code'] . ': ' . ($response['message'] ?? 'Unknown error');
        }
        
        if ($has_error) {
            $batch_pending_skus[] = array('sku' => $sku, 'reason' => $error_msg);
        } else {
            $batch_synced_skus[] = $sku;
            if ($action == 'updated') $batch_updated++;
            else $batch_created++;
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
        delete_transient('zoho_woo_sync_zoho_items_map');
    }
    
    // Get failure reasons for display
    $failure_reasons = array();
    foreach ($stats['pending_skus'] as $fail) {
        $failure_reasons[] = $fail['sku'] . ': ' . $fail['reason'];
    }
    
    wp_send_json_success(array(
        'offset' => $new_offset,
        'total' => $total,
        'synced' => $new_synced,
        'created' => $new_created,
        'updated' => $new_updated,
        'pending' => count($stats['pending_skus']),
        'failed_reasons' => $failure_reasons,
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

function zoho_get_item_by_name($name) {
    $response = zoho_api_get('items?search_text=' . urlencode($name));
    
    if (!is_wp_error($response) && isset($response['items']) && !empty($response['items'])) {
        // Find exact match by name
        foreach ($response['items'] as $item) {
            if (isset($item['name']) && $item['name'] === $name) {
                return $item['item_id'];
            }
        }
        // If no exact match, return first result
        if (isset($response['items'][0]['item_id'])) {
            return $response['items'][0]['item_id'];
        }
    }
    
    return null;
}

function zoho_verify_item_exists($item_id) {
    $response = zoho_api_get('items/' . urlencode($item_id));
    
    if (!is_wp_error($response) && isset($response['item']['item_id'])) {
        return true;
    }
    
    return false;
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
        
        $all_order_ids = wc_get_orders($args);
        
        // Filter out completed orders that already have invoices and are paid
        $order_ids = array();
        foreach ($all_order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) continue;
            
            $existing_invoice_id = get_post_meta($order_id, '_zoho_invoice_id', true);
            $order_status = $order->get_status();
            
            // Skip completed orders that already have invoices (already processed)
            if ($order_status === 'completed' && !empty($existing_invoice_id)) {
                continue;
            }
            
            $order_ids[] = $order_id;
        }
        
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
        
        // Check if already completed and has invoice - skip it
        $existing_invoice_id_check = get_post_meta($order_id, '_zoho_invoice_id', true);
        $order_status_check = $order->get_status();
        if ($order_status_check === 'completed' && !empty($existing_invoice_id_check)) {
            continue; // Skip already processed completed orders
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
        $existing_invoice_id = get_post_meta($order_id, '_zoho_invoice_id', true);
        
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
                delete_post_meta($order_id, '_zoho_invoice_id');
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
            update_post_meta($order_id, '_zoho_invoice_id', $zoho_invoice_id);
            
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
        
        // Skip draft invoices (optional - remove this if you want to sync drafts)
        // if ($invoice_status === 'draft') {
        //     $batch_skipped[] = array(
        //         'id' => $zoho_invoice_id,
        //         'reason' => 'Draft invoice - skipped',
        //         'invoice_number' => $full_invoice['invoice_number'] ?? 'N/A'
        //     );
        //     continue;
        // }
        
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
            update_post_meta($order->get_id(), '_zoho_invoice_id', $zoho_invoice_id);
            if (!empty($reference_number)) {
                update_post_meta($order->get_id(), '_zoho_reference_number', $reference_number);
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
                        break;
                    }
                }
                
                // Fallback if email field missing in response
                if (empty($action) && !empty($search_response['contacts'][0]['contact_id'])) {
                    $existing_contact_id = $search_response['contacts'][0]['contact_id'];
                    $response = zoho_api_put('contacts/' . $existing_contact_id, $contact_data);
                    $action = 'updated';
                    update_user_meta($customer_data['user_id'], '_zoho_contact_id', $existing_contact_id);
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
                                break;
                            }
                        }
                        
                        if (empty($action) && !empty($search_response['contacts'][0]['contact_id'])) {
                            $existing_contact_id = $search_response['contacts'][0]['contact_id'];
                            $response = zoho_api_put('contacts/' . $existing_contact_id, $contact_data);
                            $action = 'updated';
                            update_user_meta($customer_data['user_id'], '_zoho_contact_id', $existing_contact_id);
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
    $first_name = $order->get_billing_first_name();
    $last_name = $order->get_billing_last_name();
    $contact_name = trim($first_name . ' ' . $last_name);
    
    // Fallback to email if no name
    if (empty($contact_name)) {
        $contact_name = $order->get_billing_email();
    }

    $billing_email = $order->get_billing_email();
    $billing_phone = $order->get_billing_phone();
    
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

        // Add billing address if available
        // Note: Zoho uses 'address' field, not 'street'
        if (!empty($order->get_billing_address_1()) || !empty($order->get_billing_city())) {
            $contact_data['billing_address'] = array();
            
            if (!empty($order->get_billing_address_1())) {
                $contact_data['billing_address']['address'] = $order->get_billing_address_1();
            }
        if (!empty($order->get_billing_address_2())) {
            $contact_data['billing_address']['street2'] = $order->get_billing_address_2();
        }
        if (!empty($order->get_billing_city())) {
            $contact_data['billing_address']['city'] = $order->get_billing_city();
        }
        if (!empty($order->get_billing_state())) {
            $contact_data['billing_address']['state'] = $order->get_billing_state();
        }
        if (!empty($order->get_billing_postcode())) {
            $contact_data['billing_address']['zip'] = $order->get_billing_postcode();
        }
        if (!empty($order->get_billing_country())) {
            $contact_data['billing_address']['country'] = $order->get_billing_country();
        }
    }

    // Add shipping address if available
    // Note: Zoho uses 'address' field, not 'street'
    if (!empty($order->get_shipping_address_1()) || !empty($order->get_shipping_city())) {
        $contact_data['shipping_address'] = array();
        
        if (!empty($order->get_shipping_address_1())) {
            $contact_data['shipping_address']['address'] = $order->get_shipping_address_1();
        }
    if (!empty($order->get_shipping_address_2())) {
        $contact_data['shipping_address']['street2'] = $order->get_shipping_address_2();
    }
    if (!empty($order->get_shipping_city())) {
        $contact_data['shipping_address']['city'] = $order->get_shipping_city();
    }
    if (!empty($order->get_shipping_state())) {
        $contact_data['shipping_address']['state'] = $order->get_shipping_state();
    }
    if (!empty($order->get_shipping_postcode())) {
        $contact_data['shipping_address']['zip'] = $order->get_shipping_postcode();
    }
    if (!empty($order->get_shipping_country())) {
        $contact_data['shipping_address']['country'] = $order->get_shipping_country();
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
        $zoho_item_id = zoho_get_item_by_sku($sku);
        
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
            $zoho_item_id = zoho_get_item_by_sku($sku);
            
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
====================================================== */
function zoho_get_order_by_zoho_invoice($zoho_invoice_id) {
    global $wpdb;
    
    // Search in post meta for _zoho_invoice_id
    $order_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zoho_invoice_id' AND meta_value = %s LIMIT 1",
        $zoho_invoice_id
    ));
    
    return $order_id ? intval($order_id) : 0;
}

/* ======================================================
   HELPER: Get WC Customer by Zoho Contact ID
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
   HELPER: Get Product by Zoho Item ID
====================================================== */
function zoho_get_product_by_zoho_item($zoho_item_id) {
    global $wpdb;
    
    // Search in post meta for _zoho_item_id
    $product_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zoho_item_id' AND meta_value = %s LIMIT 1",
        $zoho_item_id
    ));
    
    return $product_id ? intval($product_id) : 0;
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
// Helper function to check if log is successful or failed
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

// Helper function to extract error message from log
function zoho_get_error_message($log) {
    $response = json_decode($log['response'] ?? '{}', true);
    
    if (isset($response['error'])) return $response['error'];
    if (isset($response['message'])) return $response['message'];
    if (isset($response['code']) && $response['code'] != 0) return 'Error Code: ' . $response['code'];
    
    return 'Unknown error';
}

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