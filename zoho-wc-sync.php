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
        'zoho_connection_page',
        'dashicons-update',
        56
    );

    add_submenu_page(
        'zoho-sync',
        'Connection',
        'Connection',
        'manage_options',
        'zoho-sync',
        'zoho_connection_page'
    );

    add_submenu_page(
        'zoho-sync',
        'Inventory Sync',
        'Inventory Sync',
        'manage_options',
        'zoho-inventory-sync',
        'zoho_inventory_sync_page'
    );
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
   CONNECTION PAGE
====================================================== */
function zoho_connection_page() {

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
        echo '<div class="notice notice-success"><p>Successfully connected to Zoho! New token saved.</p></div>';
    }

    // Show error messages
    if (isset($_GET['error'])) {
        $error = sanitize_text_field($_GET['error']);
        echo '<div class="notice notice-error"><p><strong>Error:</strong> '.esc_html($error).'</p></div>';
    }

    $connected = get_option('zoho_access_token');
    $default_redirect_uri = admin_url('admin.php?page=zoho-sync');

    echo '<h1>Zoho Connection</h1>';

    // Connection status dikhao
    if ($connected) {
        echo '<div class="notice notice-info"><p><strong>Status:</strong> Connected to Zoho</p></div>';
        if (get_option('zoho_refresh_token')) {
            echo '<p><strong>Refresh Token:</strong> Saved</p>';
        }
    } else {
        echo '<div class="notice notice-warning"><p><strong>Status:</strong> Not Connected</p></div>';
    }

    // Credentials form hamesha dikhao
    echo '<form method="post">
    <table class="form-table">
        <tr><th>Client ID</th><td><input name="client_id" value="'.esc_attr(get_option('zoho_client_id')).'" class="regular-text" required></td></tr>
        <tr><th>Client Secret</th><td><input name="client_secret" type="password" value="'.esc_attr(get_option('zoho_client_secret')).'" class="regular-text" required></td></tr>
        <tr><th>Redirect URI</th><td><input name="redirect_uri" value="'.esc_attr(get_option('zoho_redirect_uri', $default_redirect_uri)).'" class="regular-text" required>
            <p class="description">Configure this redirect URI in your Zoho App settings.</p></td></tr>
    </table>';
    submit_button('Update & Connect','primary','save_zoho_credentials');
    echo '</form>';
    echo '<p class="description">After updating credentials, you will be automatically redirected to connect with Zoho.</p>';
}

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

    /* ---------- START SYNC ---------- */
    if (isset($_POST['start_inventory_sync'])) {

        // API se data fetch karo
        $response = zoho_api_get('items');
        
        // Debugging - response check karo
        if (is_wp_error($response)) {
            echo '<div class="notice notice-error">
                <p><strong>Error:</strong> API call failed - '.$response->get_error_message().'</p>
            </div>';
            return;
        }
        
        // Check for custom error messages
        if (isset($response['error'])) {
            echo '<div class="notice notice-error">
                <p><strong>Error:</strong> '.esc_html($response['error']).'</p>
            </div>';
            return;
        }
        
        // Response structure check karo
        if (empty($response)) {
            echo '<div class="notice notice-error">
                <p><strong>Error:</strong> Empty response from Zoho API. Please check connection and try again.</p>
                <p>Make sure you are connected to Zoho and access token is valid.</p>
            </div>';
            return;
        }
        
        // Check for API errors (Zoho API error codes)
        if (isset($response['code']) && $response['code'] != 0) {
            $error_msg = $response['message'] ?? 'Unknown error';
            $error_code = $response['code'];
            
            // If authorization error (code 57), ask to re-connect
            if ($error_code == 57) {
                echo '<div class="notice notice-error">
                    <p><strong>Authorization Error (Code: '.$error_code.'):</strong> '.esc_html($error_msg).'</p>
                    <p>Token has expired. Please <a href="'.admin_url('admin.php?page=zoho-sync').'">reconnect with Zoho</a>.</p>
                    <p>Or if refresh token is saved, refresh the page and try again (auto-refresh was attempted).</p>
                </div>';
            } else {
                echo '<div class="notice notice-error">
                    <p><strong>API Error (Code: '.$error_code.'):</strong> '.esc_html($error_msg).'</p>
                </div>';
            }
            return;
        }
        
        // Items array extract karo
        $items = [];
        if (isset($response['items']) && is_array($response['items'])) {
            $items = $response['items'];
        } else {
            echo '<div class="notice notice-warning">
                <p><strong>Warning:</strong> No items found in API response.</p>
                <p>Response structure: <pre>'.esc_html(print_r(array_keys($response), true)).'</pre></p>
                <p>Full response (first 500 chars): <pre>'.esc_html(substr(print_r($response, true), 0, 500)).'</pre></p>
            </div>';
            // Return early if no items found
            return;
        }

        $total = count($items);
        $synced = 0;
        $created = 0;
        $updated = 0;
        $synced_skus = [];
        $pending_skus = [];

        foreach ($items as $item) {

            if (empty($item['sku'])) {
                $pending_skus[] = ['sku'=>'(empty)','reason'=>'Missing SKU'];
                continue;
            }

            $product_id = wc_get_product_id_by_sku($item['sku']);
            
            if (!$product_id) {
                // Create new product if not found
                $product = new WC_Product_Simple();
                $product->set_sku($item['sku']);
                
                // Set product name from Zoho item name or use SKU if name not available
                $product_name = !empty($item['name']) ? $item['name'] : $item['sku'];
                $product->set_name($product_name);
                
                // Set slug explicitly from product name
                $product_slug = sanitize_title($product_name);
                $product->set_slug($product_slug);
                
                // Set status to publish
                $product->set_status('publish');
                
                // Set catalog visibility to visible
                $product->set_catalog_visibility('visible');
                
                $created++;
            } else {
                // Update existing product
                $product = wc_get_product($product_id);
                $updated++;
            }

            // Set price
            if (isset($item['rate'])) {
                $product->set_regular_price($item['rate']);
            }

            // Set stock quantity
            if (isset($item['stock_on_hand'])) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($item['stock_on_hand']);
                $product->set_stock_status('instock');
            }

            // Set description if available from Zoho
            if (!empty($item['description'])) {
                $product->set_description($item['description']);
            }

            // Save product
            $product->save();

            $synced++;
            $synced_skus[] = $item['sku'];
        }
        
        // Flush rewrite rules after creating new products to ensure permalinks work
        if ($created > 0) {
            // Force flush rewrite rules
            global $wp_rewrite;
            $wp_rewrite->flush_rules(true);
        }

        zoho_add_sync_log($total,$synced,$synced_skus,$pending_skus,$created,$updated);

        echo '<div class="notice notice-success">
            <p><strong>Sync Completed!</strong></p>
            <p>Total: '.$total.' | Synced: '.$synced.' | Created: '.$created.' | Updated: '.$updated.' | Pending: '.count($pending_skus).'</p>
        </div>';
        
        if ($created > 0) {
            echo '<div class="notice notice-info">
                <p><strong>Note:</strong> If product pages show 404, please go to <a href="'.admin_url('options-permalink.php').'">Settings → Permalinks</a> and click "Save Changes" to refresh permalinks.</p>
            </div>';
        }
    }

    echo '<form method="post">
        <button class="button button-primary" name="start_inventory_sync">Sync Products Now</button>
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