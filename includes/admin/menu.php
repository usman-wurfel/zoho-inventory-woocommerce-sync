<?php
/**
 * Admin Menu and Scripts
 */

if (!defined('ABSPATH')) exit;

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
        wp_enqueue_script('zoho-sync-script', plugin_dir_url(dirname(dirname(__FILE__))) . 'js/zoho-sync.js', array('jquery'), '2.0.' . time(), true);
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
    
    // Handle disconnect
    if (isset($_GET['disconnect']) && isset($_GET['_wpnonce'])) {
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

