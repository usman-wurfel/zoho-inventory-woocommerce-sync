<?php
/**
 * Dashboard Page and Helper Functions
 */

if (!defined('ABSPATH')) exit;

/* ======================================================
   HELPER FUNCTIONS FOR DASHBOARD
====================================================== */
function zoho_get_synced_invoices_count() {
    global $wpdb;
    
    // Try WooCommerce function first (HPOS compatible)
    if (function_exists('wc_get_orders')) {
        $orders = wc_get_orders(array(
            'limit' => 1,
            'return' => 'ids',
            'meta_key' => '_zoho_invoice_id',
            'meta_compare' => 'EXISTS'
        ));
        
        // If returns results, use WC function (for smaller datasets)
        if ($orders !== false) {
            $all_orders = wc_get_orders(array(
                'limit' => -1,
                'return' => 'ids',
                'status' => 'any',
                'meta_query' => array(
                    array(
                        'key' => '_zoho_invoice_id',
                        'compare' => 'EXISTS'
                    ),
                    array(
                        'key' => '_zoho_invoice_id',
                        'value' => '',
                        'compare' => '!='
                    )
                )
            ));
            return count($all_orders);
        }
    }
    
    // Fallback to SQL (faster for large datasets)
    $hpos_enabled = false;
    if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil')) {
        $hpos_enabled = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }
    
    if ($hpos_enabled) {
        return intval($wpdb->get_var("
            SELECT COUNT(DISTINCT om.order_id) 
            FROM {$wpdb->prefix}wc_orders_meta om
            INNER JOIN {$wpdb->prefix}wc_orders o ON om.order_id = o.id
            WHERE om.meta_key = '_zoho_invoice_id' 
            AND om.meta_value != '' 
            AND o.type = 'shop_order'
            AND o.status NOT IN ('trash', 'auto-draft')
        "));
    } else {
        return intval($wpdb->get_var("
            SELECT COUNT(DISTINCT pm.post_id) 
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_zoho_invoice_id' 
            AND pm.meta_value != '' 
            AND p.post_type = 'shop_order'
            AND p.post_status NOT IN ('trash', 'auto-draft')
        "));
    }
}

function zoho_get_total_orders_count() {
    global $wpdb;
    
    // Try WooCommerce function first (HPOS compatible)
    if (function_exists('wc_get_orders')) {
        $all_orders = wc_get_orders(array(
            'limit' => -1,
            'return' => 'ids',
            'status' => 'any'
        ));
        return is_array($all_orders) ? count($all_orders) : 0;
    }
    
    // Fallback to SQL
    $hpos_enabled = false;
    if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil')) {
        $hpos_enabled = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }
    
    if ($hpos_enabled) {
        return intval($wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}wc_orders 
            WHERE type = 'shop_order'
            AND status NOT IN ('trash', 'auto-draft')
        "));
    } else {
        return intval($wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_type = 'shop_order' 
            AND post_status NOT IN ('trash', 'auto-draft')
        "));
    }
}

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
    
    // Get invoices synced count using helper function
    $invoices_synced = zoho_get_synced_invoices_count();
    
    // Get total orders count using helper function
    $total_orders = zoho_get_total_orders_count();
    
    // Also get WooCommerce counts for context
    $total_products = wp_count_posts('product');
    $published_products = $total_products->publish ?? 0;
    
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
                    <p style="margin: 5px 0; color: #666; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-clock" style="vertical-align: middle;"></span>
                        <?php if ($expires_in): ?>
                            <span id="zoho-token-expiry-text">Access token will expire in <?php echo esc_html($expires_in); ?>.</span>
                        <?php else: ?>
                            <span id="zoho-token-expiry-text">Access token is active.</span>
                        <?php endif; ?>
                    </p>
                </div>
                
                <div style="margin-top: 15px;">
                    <a href="<?php echo esc_url(zoho_auth_url()); ?>" class="button button-secondary" style="margin-right: 10px;">
                        Reauthorize
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=zoho-sync&disconnect=1&_wpnonce=' . wp_create_nonce('zoho_disconnect'))); ?>" class="button button-link-delete" onclick="return confirm('Are you sure you want to disconnect?');">
                        Disconnect
                    </a>
                </div>
                
                <div style="margin-top: 15px;">
                    <button type="button" id="zoho-refresh-token-btn" class="button button-secondary" style="padding: 6px 12px;">
                        <span class="dashicons dashicons-update" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px; margin-right: 5px;"></span>
                        <span id="zoho-refresh-btn-text">
                            <?php if ($expires_in): ?>
                                Access token will expire in <?php echo esc_html($expires_in); ?>.
                            <?php else: ?>
                                Refresh Token
                            <?php endif; ?>
                        </span>
                    </button>
                    <span id="zoho-refresh-status" style="display: none; margin-left: 10px; color: #00a32a; font-weight: 500;"></span>
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
                <div class="zoho-stat-number"><?php echo number_format($invoices_synced ?: 0); ?></div>
                <div style="font-size: 12px; color: #666; margin-top: 5px;"><?php echo number_format($invoices_synced ?: 0); ?> of <?php echo number_format($total_orders ?: 0); ?> orders linked</div>
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
    
    <script>
    jQuery(document).ready(function($) {
        var $refreshBtn = $('#zoho-refresh-token-btn');
        var $expiryText = $('#zoho-token-expiry-text');
        var $refreshBtnText = $('#zoho-refresh-btn-text');
        var $refreshStatus = $('#zoho-refresh-status');
        
        $refreshBtn.on('click', function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var $icon = $btn.find('.dashicons');
            
            // Disable button and show loading
            $btn.prop('disabled', true);
            $icon.addClass('spin');
            $refreshStatus.hide();
            
            // Make AJAX call
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'zoho_refresh_token',
                    nonce: '<?php echo wp_create_nonce('zoho_refresh_token_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        // Update expiry text in both places
                        var newTime = '60 minutes';
                        var newText = 'Access token will expire in ' + newTime + '.';
                        $expiryText.text(newText);
                        $refreshBtnText.text(newText);
                        $refreshStatus.text('Token refreshed!').css('color', '#00a32a').fadeIn(300);
                        
                        // Hide status after 3 seconds
                        setTimeout(function() {
                            $refreshStatus.fadeOut(300);
                        }, 3000);
                    } else {
                        $refreshStatus.text('Error: ' + (response.data || 'Failed to refresh token')).css('color', '#d63638').fadeIn(300);
                    }
                },
                error: function() {
                    $refreshStatus.text('Error: Request failed').css('color', '#d63638').fadeIn(300);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $icon.removeClass('spin');
                }
            });
        });
    });
    
    // Add CSS for spin animation
    if (!$('style#zoho-refresh-spin').length) {
        $('head').append('<style id="zoho-refresh-spin">.dashicons.spin { animation: zoho-spin 1s linear infinite; } @keyframes zoho-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }</style>');
    }
    </script>
    <?php
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
   AJAX HANDLER: Refresh Token
====================================================== */
add_action('wp_ajax_zoho_refresh_token', 'zoho_refresh_token_ajax_handler');
function zoho_refresh_token_ajax_handler() {
    check_ajax_referer('zoho_refresh_token_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
        return;
    }
    
    // Check if refresh token exists
    $refresh_token = get_option('zoho_refresh_token');
    if (empty($refresh_token)) {
        wp_send_json_error('No refresh token found. Please reconnect to Zoho.');
        return;
    }
    
    // Refresh the token
    $result = zoho_refresh_access_token();
    
    if ($result) {
        // Set expiry time to 1 hour (3600 seconds) from now
        $new_expiry_time = time() + 3600;
        update_option('zoho_token_expires_at', $new_expiry_time);
        
        // Return fixed 1 hour expiry
        wp_send_json_success(array(
            'message' => 'Token refreshed successfully',
            'expires_in' => '60 minutes'
        ));
    } else {
        wp_send_json_error('Failed to refresh token. Please check your credentials or reconnect to Zoho.');
    }
}

