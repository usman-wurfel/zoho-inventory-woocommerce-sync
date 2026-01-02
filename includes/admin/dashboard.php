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
    $current_org_id = get_option('zoho_organization_id', '');
    
    // Try WooCommerce function first (HPOS compatible)
    if (function_exists('wc_get_orders')) {
        $meta_query = array(
            array(
                'key' => '_zoho_invoice_id',
                'compare' => 'EXISTS'
            ),
            array(
                'key' => '_zoho_invoice_id',
                'value' => '',
                'compare' => '!='
            )
        );
        
        // Add organization filter if set
        if (!empty($current_org_id)) {
            $meta_query[] = array(
                'key' => '_zoho_org_id',
                'value' => $current_org_id,
                'compare' => '='
            );
        }
        
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
                'meta_query' => $meta_query
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
        if (!empty($current_org_id)) {
            return intval($wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT om1.order_id) 
                FROM {$wpdb->prefix}wc_orders_meta om1
                INNER JOIN {$wpdb->prefix}wc_orders o ON om1.order_id = o.id
                INNER JOIN {$wpdb->prefix}wc_orders_meta om2 ON om1.order_id = om2.order_id
                WHERE om1.meta_key = '_zoho_invoice_id' 
                AND om1.meta_value != '' 
                AND om2.meta_key = '_zoho_org_id'
                AND om2.meta_value = %s
                AND o.type = 'shop_order'
                AND o.status NOT IN ('trash', 'auto-draft')
            ", $current_org_id)));
        } else {
            return intval($wpdb->get_var("
                SELECT COUNT(DISTINCT om.order_id) 
                FROM {$wpdb->prefix}wc_orders_meta om
                INNER JOIN {$wpdb->prefix}wc_orders o ON om.order_id = o.id
                WHERE om.meta_key = '_zoho_invoice_id' 
                AND om.meta_value != '' 
                AND o.type = 'shop_order'
                AND o.status NOT IN ('trash', 'auto-draft')
            "));
        }
    } else {
        if (!empty($current_org_id)) {
            return intval($wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT pm1.post_id) 
                FROM {$wpdb->postmeta} pm1
                INNER JOIN {$wpdb->posts} p ON pm1.post_id = p.ID
                INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
                WHERE pm1.meta_key = '_zoho_invoice_id' 
                AND pm1.meta_value != '' 
                AND pm2.meta_key = '_zoho_org_id'
                AND pm2.meta_value = %s
                AND p.post_type = 'shop_order'
                AND p.post_status NOT IN ('trash', 'auto-draft')
            ", $current_org_id)));
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
    
    // Show organization selection success message
    if (isset($_GET['org_selected']) && $_GET['org_selected'] == '1') {
        echo '<div class="notice notice-success is-dismissible"><p>Organization selected successfully!</p></div>';
    }

    // Force refresh option cache to get latest values
    wp_cache_delete('zoho_access_token', 'options');
    
    $connected = get_option('zoho_access_token');
    $organization_id = get_option('zoho_organization_id');
    $default_redirect_uri = admin_url('admin.php?page=zoho-sync');
    
    // Handle organization selection/change
    if (isset($_POST['zoho_select_organization']) && $connected) {
        check_admin_referer('zoho_select_organization');
        $selected_org_id = sanitize_text_field($_POST['organization_id']);
        if (!empty($selected_org_id)) {
            update_option('zoho_organization_id', $selected_org_id);
            $organization_id = $selected_org_id;
            // Message will be shown via GET parameter
            wp_redirect(admin_url('admin.php?page=zoho-sync&org_selected=1'));
            exit;
        }
    }
    
    // Check if user wants to change organization
    $show_change_org_form = isset($_GET['change_org']) && $_GET['change_org'] == '1';
    
    // Fetch organizations if connected (always fetch to show Change Organization button if multiple orgs exist)
    $organizations = array();
    $need_org_selection = false;
    if ($connected) {
        // zoho-api.php is already loaded by main plugin file
        if (function_exists('zoho_fetch_organizations')) {
            $org_data = zoho_fetch_organizations();
            if (isset($org_data['organizations']) && is_array($org_data['organizations'])) {
                $organizations = $org_data['organizations'];
                // If only one organization and not already selected, auto-select it
                if (count($organizations) == 1 && empty($organization_id)) {
                    update_option('zoho_organization_id', $organizations[0]['organization_id']);
                    $organization_id = $organizations[0]['organization_id'];
                } else if (count($organizations) > 1) {
                    // Show selection form if no org selected, or if user wants to change
                    if (empty($organization_id) || $show_change_org_form) {
                        $need_org_selection = true;
                    }
                }
            } else {
                // Error fetching organizations - show message
                $org_error = isset($org_data['message']) ? $org_data['message'] : 'Failed to fetch organizations';
                if (isset($org_data['code']) && $org_data['code'] == 6024) {
                    echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Multiple organizations found. Please select an organization below.</p></div>';
                    // Try to fetch again without organization_id requirement for the organizations endpoint
                    $token = get_option('zoho_access_token');
                    $response = wp_remote_get('https://www.zohoapis.com/inventory/v1/organizations', [
                        'headers' => [
                            'Authorization' => 'Zoho-oauthtoken ' . $token,
                        ],
                        'timeout' => 30
                    ]);
                    if (!is_wp_error($response)) {
                        $body = wp_remote_retrieve_body($response);
                        $org_data = json_decode($body, true);
                        if (isset($org_data['organizations']) && is_array($org_data['organizations'])) {
                            $organizations = $org_data['organizations'];
                            $need_org_selection = true;
                        }
                    }
                }
            }
        }
    }

    // Get sync statistics from product/customer meta (unique Zoho IDs)
    // Filter by current organization_id
    global $wpdb;
    $current_org_id = get_option('zoho_organization_id', '');
    
    // Build organization filter SQL
    $org_filter_sql = '';
    $org_filter_params = array();
    if (!empty($current_org_id)) {
        // For postmeta: Join with _zoho_org_id meta
        $org_filter_sql_posts = " AND EXISTS (
            SELECT 1 FROM {$wpdb->postmeta} pm2 
            WHERE pm2.post_id = {$wpdb->postmeta}.post_id 
            AND pm2.meta_key = '_zoho_org_id' 
            AND pm2.meta_value = %s
        )";
        // For usermeta: Join with _zoho_org_id meta
        $org_filter_sql_users = " AND EXISTS (
            SELECT 1 FROM {$wpdb->usermeta} um2 
            WHERE um2.user_id = um.user_id 
            AND um2.meta_key = '_zoho_org_id' 
            AND um2.meta_value = %s
        )";
        $org_filter_params[] = $current_org_id;
    }
    
    // Count unique Zoho item IDs linked (distinct _zoho_item_id values) for current org
    if (!empty($current_org_id)) {
        $items_linked = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT pm1.meta_value) 
            FROM {$wpdb->postmeta} pm1
            INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
            WHERE pm1.meta_key = %s 
            AND pm1.meta_value != '' 
            AND pm1.meta_value IS NOT NULL
            AND pm2.meta_key = '_zoho_org_id'
            AND pm2.meta_value = %s
        ", '_zoho_item_id', $current_org_id));
    } else {
        $items_linked = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT meta_value) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = %s 
            AND meta_value != '' 
            AND meta_value IS NOT NULL
        ", '_zoho_item_id'));
    }
    
    // Count WooCommerce products that have _zoho_item_id (products linked) for current org
    if (!empty($current_org_id)) {
        $products_linked = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT pm1.post_id) 
            FROM {$wpdb->postmeta} pm1
            INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
            WHERE pm1.meta_key = %s 
            AND pm1.meta_value != '' 
            AND pm1.meta_value IS NOT NULL
            AND pm2.meta_key = '_zoho_org_id'
            AND pm2.meta_value = %s
        ", '_zoho_item_id', $current_org_id));
    } else {
        $products_linked = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT post_id) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = %s 
            AND meta_value != '' 
            AND meta_value IS NOT NULL
        ", '_zoho_item_id'));
    }
    
    // Count unique Zoho contact IDs linked (distinct _zoho_contact_id values) for current org
    if (!empty($current_org_id)) {
        $contacts_linked = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT um1.meta_value) 
            FROM {$wpdb->usermeta} um1
            INNER JOIN {$wpdb->usermeta} um2 ON um1.user_id = um2.user_id
            WHERE um1.meta_key = %s 
            AND um1.meta_value != '' 
            AND um1.meta_value IS NOT NULL
            AND um2.meta_key = '_zoho_org_id'
            AND um2.meta_value = %s
        ", '_zoho_contact_id', $current_org_id));
    } else {
        $contacts_linked = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT meta_value) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = %s 
            AND meta_value != '' 
            AND meta_value IS NOT NULL
        ", '_zoho_contact_id'));
    }
    
    // Count WooCommerce customers that have _zoho_contact_id (customers linked) for current org
    // Only count users with customer role
    if (!empty($current_org_id)) {
        $customers_linked = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT um1.user_id) 
            FROM {$wpdb->usermeta} um1
            INNER JOIN {$wpdb->users} u ON um1.user_id = u.ID
            INNER JOIN {$wpdb->usermeta} role_meta ON um1.user_id = role_meta.user_id 
                AND role_meta.meta_key = '{$wpdb->prefix}capabilities'
                AND role_meta.meta_value LIKE '%%customer%%'
            INNER JOIN {$wpdb->usermeta} um2 ON um1.user_id = um2.user_id
            WHERE um1.meta_key = '_zoho_contact_id' 
            AND um1.meta_value != '' 
            AND um1.meta_value IS NOT NULL
            AND um2.meta_key = '_zoho_org_id'
            AND um2.meta_value = %s
        ", $current_org_id));
    } else {
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
    }
    
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
                <?php if ($need_org_selection && !empty($organizations)): ?>
                    <!-- Organization Selection/Change Form -->
                    <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; margin-bottom: 15px; border-radius: 4px;">
                        <h3 style="margin-top: 0; color: #856404;"><?php echo $organization_id ? 'Change Organization' : 'Select Organization'; ?></h3>
                        <p style="color: #856404; margin-bottom: 15px;">Your Zoho account has access to multiple organizations. Please select which organization to use:</p>
                        <?php if ($organization_id): ?>
                            <p style="color: #856404; margin-bottom: 15px;"><strong>Current Organization ID:</strong> <?php echo esc_html($organization_id); ?></p>
                            <p style="color: #dc3545; margin-bottom: 15px;"><strong>⚠️ Warning:</strong> Changing organization will use the new organization's data for all sync operations. Make sure you want to switch before proceeding.</p>
                        <?php endif; ?>
                        <form method="post" action="">
                            <?php wp_nonce_field('zoho_select_organization'); ?>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="organization_id">Organization</label></th>
                                    <td>
                                        <select name="organization_id" id="organization_id" class="regular-text" required>
                                            <option value="">-- Select Organization --</option>
                                            <?php foreach ($organizations as $org): ?>
                                                <option value="<?php echo esc_attr($org['organization_id']); ?>" <?php selected($organization_id, $org['organization_id']); ?>>
                                                    <?php echo esc_html($org['name']); ?> (<?php echo esc_html($org['organization_id']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            </table>
                            <p class="submit">
                                <input type="submit" name="zoho_select_organization" class="button button-primary" value="<?php echo $organization_id ? 'Change Organization' : 'Select Organization'; ?>">
                                <?php if ($organization_id): ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=zoho-sync')); ?>" class="button button-secondary">Cancel</a>
                                <?php endif; ?>
                            </p>
                        </form>
                    </div>
                <?php elseif ($organization_id): ?>
                    <div style="margin-bottom: 15px;">
                        <p style="margin: 5px 0; color: #666;">
                            <strong>Organization ID:</strong> <?php echo esc_html($organization_id); ?>
                        </p>
                    </div>
                <?php endif; ?>
                
                <div style="margin-top: 15px;">
                    <?php
                    // Handle reauthorize request - revoke tokens first
                    if (isset($_GET['reauthorize']) && $_GET['reauthorize'] == '1') {
                        check_admin_referer('zoho_reauthorize');
                        // Revoke existing tokens
                        if (function_exists('zoho_revoke_access_token')) {
                            zoho_revoke_access_token();
                        }
                        // Delete local tokens
                        delete_option('zoho_access_token');
                        delete_option('zoho_refresh_token');
                        delete_option('zoho_token_expires_at');
                        // Redirect to auth URL
                        wp_redirect(zoho_auth_url());
                        exit;
                    }
                    ?>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=zoho-sync&reauthorize=1'), 'zoho_reauthorize')); ?>" class="button button-secondary" style="margin-right: 10px;">
                        Reauthorize
                    </a>
                    <?php if ($organization_id && !empty($organizations) && count($organizations) > 1): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=zoho-sync&change_org=1')); ?>" class="button button-secondary" style="margin-right: 10px;">
                            Change Organization
                        </a>
                    <?php endif; ?>
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
        
        <!-- Webhooks Settings -->
        <?php
        // Handle webhook settings save
        if (isset($_POST['save_webhook_settings']) && $connected) {
            check_admin_referer('zoho_webhook_settings');
            update_option('zoho_webhooks_enabled', isset($_POST['webhooks_enabled']) ? 'yes' : 'no');
            update_option('zoho_sync_zoho_to_woo_items', isset($_POST['sync_items']) ? 'yes' : 'no');
            update_option('zoho_sync_zoho_to_woo_contacts', isset($_POST['sync_contacts']) ? 'yes' : 'no');
            update_option('zoho_sync_zoho_to_woo_invoices', isset($_POST['sync_invoices']) ? 'yes' : 'no');
            update_option('zoho_webhook_secret', sanitize_text_field($_POST['webhook_secret']));
            echo '<div class="notice notice-success"><p>Webhook settings saved!</p></div>';
        }
        
        $webhooks_enabled = get_option('zoho_webhooks_enabled', 'no');
        $webhook_secret = get_option('zoho_webhook_secret', '');
        $sync_items = get_option('zoho_sync_zoho_to_woo_items', 'no');
        $sync_contacts = get_option('zoho_sync_zoho_to_woo_contacts', 'no');
        $sync_invoices = get_option('zoho_sync_zoho_to_woo_invoices', 'no');
        $webhook_url = rest_url('zoho-inventory/v1/webhook');
        $webhook_delete_url = rest_url('zoho-inventory/v1/webhook-delete');
        ?>
        
        <?php if ($connected): ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin-bottom: 20px; border-radius: 4px;">
            <h2 style="margin-top: 0; font-size: 18px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; cursor: pointer;" onclick="toggleWebhookSettings()">
                <span>Webhooks Settings</span>
                <span id="webhook-toggle-icon" style="font-size: 14px; transition: transform 0.3s;">▼</span>
            </h2>
            
            <div id="webhook-settings-content" style="display: none;">
            
            <!-- Tab Navigation -->
            <div style="border-bottom: 2px solid #ddd; margin-bottom: 20px;">
                <button type="button" class="webhook-tab-btn" onclick="switchWebhookTab('inventory')" id="webhook-tab-inventory" style="padding: 10px 20px; background: #2271b1; color: #fff; border: none; border-bottom: 3px solid #2271b1; cursor: pointer; font-weight: bold;">Inventory Webhooks</button>
                <button type="button" class="webhook-tab-btn" onclick="switchWebhookTab('contact')" id="webhook-tab-contact" style="padding: 10px 20px; background: #f0f0f0; color: #333; border: none; border-bottom: 3px solid transparent; cursor: pointer;">Contact Webhooks</button>
                <button type="button" class="webhook-tab-btn" onclick="switchWebhookTab('invoice')" id="webhook-tab-invoice" style="padding: 10px 20px; background: #f0f0f0; color: #333; border: none; border-bottom: 3px solid transparent; cursor: pointer;">Invoice Webhooks</button>
            </div>
            
            <!-- Inventory Webhooks Tab -->
            <div id="webhook-tab-content-inventory" class="webhook-tab-content">
                <div style="background: #f0f0f0; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                    <p style="margin: 5px 0 15px 0; font-weight: bold; font-size: 16px;">Webhook URLs for Items/Products:</p>
                    
                    <div style="margin-bottom: 15px;">
                        <p style="margin: 5px 0 8px 0; font-weight: bold; color: #2271b1;">Create/Update Webhook:</p>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" id="webhook-url-input" value="<?php echo esc_attr($webhook_url); ?>" readonly style="flex: 1; font-family: monospace; background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                            <button type="button" onclick="copyWebhookUrl('webhook-url-input')" class="button">Copy</button>
                        </div>
                        <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">
                            Use this URL for "Created" and "Updated" events in Zoho.
                        </p>
                    </div>
                    
                    <div>
                        <p style="margin: 5px 0 8px 0; font-weight: bold; color: #dc3232;">Delete Webhook:</p>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" id="webhook-delete-url-input" value="<?php echo esc_attr($webhook_delete_url); ?>" readonly style="flex: 1; font-family: monospace; background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                            <button type="button" onclick="copyWebhookUrl('webhook-delete-url-input')" class="button">Copy</button>
                        </div>
                        <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">
                            Use this URL for "Deleted" events in Zoho.
                        </p>
                    </div>
                </div>
                
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #2271b1; margin-bottom: 20px;">
                    <h3 style="margin-top: 0; color: #2271b1;">📋 How to Configure Item Webhooks in Zoho Inventory:</h3>
                    <ol style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
                        <li>Log in to your Zoho Inventory account</li>
                        <li>Go to <strong>Settings</strong> → <strong>Automation</strong> → <strong>Actions</strong> → <strong>Webhooks</strong></li>
                        <li>Click <strong>"New Webhook"</strong> or use this direct link:
                            <br><a href="https://inventory.zoho.com/app/<?php echo esc_attr(get_option('zoho_organization_id', '')); ?>#/settings/automation/actions/webhooks/new" target="_blank" style="color: #2271b1; text-decoration: underline;">
                                Open Webhooks Page
                            </a>
                        </li>
                        <li>Configure <strong>two separate webhooks</strong> for Items:
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li><strong>Webhook 1 - Create/Update:</strong>
                                    <ul style="margin-top: 5px; padding-left: 20px;">
                                        <li><strong>Webhook Name:</strong> WordPress Sync - Items Create/Update</li>
                                        <li><strong>URL:</strong> <code><?php echo esc_html($webhook_url); ?></code></li>
                                        <li><strong>Method:</strong> POST</li>
                                        <li><strong>Content-Type:</strong> application/json</li>
                                        <li><strong>Module:</strong> Items</li>
                                        <li><strong>Events:</strong> Select "Created" and "Updated"</li>
                                    </ul>
                                </li>
                                <li><strong>Webhook 2 - Delete:</strong>
                                    <ul style="margin-top: 5px; padding-left: 20px;">
                                        <li><strong>Webhook Name:</strong> WordPress Sync - Items Delete</li>
                                        <li><strong>URL:</strong> <code><?php echo esc_html($webhook_delete_url); ?></code></li>
                                        <li><strong>Method:</strong> POST</li>
                                        <li><strong>Content-Type:</strong> application/json</li>
                                        <li><strong>Module:</strong> Items</li>
                                        <li><strong>Events:</strong> Select "Deleted"</li>
                                    </ul>
                                </li>
                            </ul>
                        </li>
                        <?php if (!empty($webhook_secret)): ?>
                        <li>Add custom header for security:
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li><strong>Header Name:</strong> X-Webhook-Secret</li>
                                <li><strong>Header Value:</strong> <code><?php echo esc_html($webhook_secret); ?></code></li>
                            </ul>
                        </li>
                        <?php endif; ?>
                        <li>Click <strong>"Save"</strong> to register the webhook</li>
                    </ol>
                </div>
            </div>
            
            <!-- Contact Webhooks Tab -->
            <div id="webhook-tab-content-contact" class="webhook-tab-content" style="display: none;">
                <div style="background: #f0f0f0; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                    <p style="margin: 5px 0 15px 0; font-weight: bold; font-size: 16px;">Webhook URLs for Contacts/Customers:</p>
                    
                    <div style="margin-bottom: 15px;">
                        <p style="margin: 5px 0 8px 0; font-weight: bold; color: #2271b1;">Create/Update Webhook:</p>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" id="webhook-contact-url-input" value="<?php echo esc_attr($webhook_url); ?>" readonly style="flex: 1; font-family: monospace; background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                            <button type="button" onclick="copyWebhookUrl('webhook-contact-url-input')" class="button">Copy</button>
                        </div>
                        <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">
                            Use this URL for "Created" and "Updated" events in Zoho.
                        </p>
                    </div>
                    
                    <div>
                        <p style="margin: 5px 0 8px 0; font-weight: bold; color: #dc3232;">Delete Webhook:</p>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" id="webhook-contact-delete-url-input" value="<?php echo esc_attr($webhook_delete_url); ?>" readonly style="flex: 1; font-family: monospace; background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                            <button type="button" onclick="copyWebhookUrl('webhook-contact-delete-url-input')" class="button">Copy</button>
                        </div>
                        <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">
                            Use this URL for "Deleted" events in Zoho.
                        </p>
                    </div>
                </div>
                
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #2271b1; margin-bottom: 20px;">
                    <h3 style="margin-top: 0; color: #2271b1;">📋 How to Configure Contact Webhooks in Zoho Inventory:</h3>
                    <ol style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
                        <li>Log in to your Zoho Inventory account</li>
                        <li>Go to <strong>Settings</strong> → <strong>Automation</strong> → <strong>Actions</strong> → <strong>Webhooks</strong></li>
                        <li>Click <strong>"New Webhook"</strong></li>
                        <li>Configure <strong>two separate webhooks</strong> for Contacts:
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li><strong>Webhook 1 - Create/Update:</strong>
                                    <ul style="margin-top: 5px; padding-left: 20px;">
                                        <li><strong>Webhook Name:</strong> WordPress Sync - Contacts Create/Update</li>
                                        <li><strong>URL:</strong> <code><?php echo esc_html($webhook_url); ?></code></li>
                                        <li><strong>Method:</strong> POST</li>
                                        <li><strong>Content-Type:</strong> application/json</li>
                                        <li><strong>Module:</strong> Contacts</li>
                                        <li><strong>Events:</strong> Select "Created" and "Updated"</li>
                                    </ul>
                                </li>
                                <li><strong>Webhook 2 - Delete:</strong>
                                    <ul style="margin-top: 5px; padding-left: 20px;">
                                        <li><strong>Webhook Name:</strong> WordPress Sync - Contacts Delete</li>
                                        <li><strong>URL:</strong> <code><?php echo esc_html($webhook_delete_url); ?></code></li>
                                        <li><strong>Method:</strong> POST</li>
                                        <li><strong>Content-Type:</strong> application/json</li>
                                        <li><strong>Module:</strong> Contacts</li>
                                        <li><strong>Events:</strong> Select "Deleted"</li>
                                    </ul>
                                </li>
                            </ul>
                        </li>
                        <?php if (!empty($webhook_secret)): ?>
                        <li>Add custom header for security:
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li><strong>Header Name:</strong> X-Webhook-Secret</li>
                                <li><strong>Header Value:</strong> <code><?php echo esc_html($webhook_secret); ?></code></li>
                            </ul>
                        </li>
                        <?php endif; ?>
                        <li>Click <strong>"Save"</strong> to register the webhook</li>
                    </ol>
                </div>
            </div>
            
            <!-- Invoice Webhooks Tab -->
            <div id="webhook-tab-content-invoice" class="webhook-tab-content" style="display: none;">
                <div style="background: #f0f0f0; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                    <p style="margin: 5px 0 15px 0; font-weight: bold; font-size: 16px;">Webhook URLs for Invoices/Orders:</p>
                    
                    <div style="margin-bottom: 15px;">
                        <p style="margin: 5px 0 8px 0; font-weight: bold; color: #2271b1;">Create/Update Webhook:</p>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" id="webhook-invoice-url-input" value="<?php echo esc_attr($webhook_url); ?>" readonly style="flex: 1; font-family: monospace; background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                            <button type="button" onclick="copyWebhookUrl('webhook-invoice-url-input')" class="button">Copy</button>
                        </div>
                        <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">
                            Use this URL for "Created" and "Updated" events in Zoho.
                        </p>
                    </div>
                    
                    <div>
                        <p style="margin: 5px 0 8px 0; font-weight: bold; color: #dc3232;">Delete Webhook:</p>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" id="webhook-invoice-delete-url-input" value="<?php echo esc_attr($webhook_delete_url); ?>" readonly style="flex: 1; font-family: monospace; background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                            <button type="button" onclick="copyWebhookUrl('webhook-invoice-delete-url-input')" class="button">Copy</button>
                        </div>
                        <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">
                            Use this URL for "Deleted" events in Zoho.
                        </p>
                    </div>
                </div>
                
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #2271b1; margin-bottom: 20px;">
                    <h3 style="margin-top: 0; color: #2271b1;">📋 How to Configure Invoice Webhooks in Zoho Inventory:</h3>
                    <ol style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
                        <li>Log in to your Zoho Inventory account</li>
                        <li>Go to <strong>Settings</strong> → <strong>Automation</strong> → <strong>Actions</strong> → <strong>Webhooks</strong></li>
                        <li>Click <strong>"New Webhook"</strong></li>
                        <li>Configure <strong>two separate webhooks</strong> for Invoices:
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li><strong>Webhook 1 - Create/Update:</strong>
                                    <ul style="margin-top: 5px; padding-left: 20px;">
                                        <li><strong>Webhook Name:</strong> WordPress Sync - Invoices Create/Update</li>
                                        <li><strong>URL:</strong> <code><?php echo esc_html($webhook_url); ?></code></li>
                                        <li><strong>Method:</strong> POST</li>
                                        <li><strong>Content-Type:</strong> application/json</li>
                                        <li><strong>Module:</strong> Invoices</li>
                                        <li><strong>Events:</strong> Select "Created" and "Updated"</li>
                                    </ul>
                                </li>
                                <li><strong>Webhook 2 - Delete:</strong>
                                    <ul style="margin-top: 5px; padding-left: 20px;">
                                        <li><strong>Webhook Name:</strong> WordPress Sync - Invoices Delete</li>
                                        <li><strong>URL:</strong> <code><?php echo esc_html($webhook_delete_url); ?></code></li>
                                        <li><strong>Method:</strong> POST</li>
                                        <li><strong>Content-Type:</strong> application/json</li>
                                        <li><strong>Module:</strong> Invoices</li>
                                        <li><strong>Events:</strong> Select "Deleted"</li>
                                    </ul>
                                </li>
                            </ul>
                        </li>
                        <?php if (!empty($webhook_secret)): ?>
                        <li>Add custom header for security:
                            <ul style="margin-top: 5px; padding-left: 20px;">
                                <li><strong>Header Name:</strong> X-Webhook-Secret</li>
                                <li><strong>Header Value:</strong> <code><?php echo esc_html($webhook_secret); ?></code></li>
                            </ul>
                        </li>
                        <?php endif; ?>
                        <li>Click <strong>"Save"</strong> to register the webhook</li>
                    </ol>
                </div>
            </div>
            
            <script>
            function copyWebhookUrl(inputId) {
                var input = document.getElementById(inputId);
                input.select();
                input.setSelectionRange(0, 99999); // For mobile devices
                document.execCommand('copy');
                alert('Webhook URL copied to clipboard!');
            }
            
            function toggleWebhookSettings() {
                var content = document.getElementById('webhook-settings-content');
                var icon = document.getElementById('webhook-toggle-icon');
                if (content.style.display === 'none') {
                    content.style.display = 'block';
                    icon.textContent = '▲';
                    icon.style.transform = 'rotate(0deg)';
                } else {
                    content.style.display = 'none';
                    icon.textContent = '▼';
                    icon.style.transform = 'rotate(0deg)';
                }
            }
            
            function switchWebhookTab(tab) {
                // Hide all tab contents
                document.querySelectorAll('.webhook-tab-content').forEach(function(content) {
                    content.style.display = 'none';
                });
                
                // Reset all tab buttons
                document.querySelectorAll('.webhook-tab-btn').forEach(function(btn) {
                    btn.style.background = '#f0f0f0';
                    btn.style.color = '#333';
                    btn.style.borderBottom = '3px solid transparent';
                    btn.style.fontWeight = 'normal';
                });
                
                // Show selected tab content
                document.getElementById('webhook-tab-content-' + tab).style.display = 'block';
                
                // Highlight selected tab button
                var selectedBtn = document.getElementById('webhook-tab-' + tab);
                selectedBtn.style.background = '#2271b1';
                selectedBtn.style.color = '#fff';
                selectedBtn.style.borderBottom = '3px solid #2271b1';
                selectedBtn.style.fontWeight = 'bold';
            }
            </script>
            
            <form method="post">
                <?php wp_nonce_field('zoho_webhook_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Webhooks</th>
                        <td>
                            <label>
                                <input type="checkbox" name="webhooks_enabled" value="1" <?php checked($webhooks_enabled, 'yes'); ?>>
                                Enable webhook processing (receive webhooks from Zoho)
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Webhook Secret</th>
                        <td>
                            <input type="text" name="webhook_secret" value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text" placeholder="Optional: Secret key for webhook validation">
                            <p class="description">Optional: Set a secret key to validate webhook requests. Zoho should send this in X-Webhook-Secret header.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Sync Options</th>
                        <td>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="checkbox" name="sync_items" value="1" <?php checked($sync_items, 'yes'); ?>>
                                <strong>Items → Products:</strong> Sync Zoho items to WooCommerce products
                            </label>
                            
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="checkbox" name="sync_contacts" value="1" <?php checked($sync_contacts, 'yes'); ?>>
                                <strong>Contacts → Customers:</strong> Sync Zoho contacts to WooCommerce customers
                            </label>
                            
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="checkbox" name="sync_invoices" value="1" <?php checked($sync_invoices, 'yes'); ?>>
                                <strong>Invoices → Orders:</strong> Sync Zoho invoices to WooCommerce orders
                            </label>
                        </td>
                    </tr>
                    
                </table>
                
                <?php submit_button('Save Webhook Settings', 'primary', 'save_webhook_settings'); ?>
            </form>
            
            <!-- Webhook Status/Test Section -->
            <?php
            $last_webhook_received = get_option('zoho_last_webhook_received', '');
            $last_webhook_data = get_option('zoho_last_webhook_data', array());
            ?>
            <div style="margin-top: 20px; padding: 15px; background: <?php echo $last_webhook_received ? '#d4edda' : '#fff3cd'; ?>; border: 1px solid <?php echo $last_webhook_received ? '#28a745' : '#ffc107'; ?>; border-radius: 4px;">
                <h4 style="margin-top: 0;">Webhook Status:</h4>
                <?php if ($last_webhook_received): ?>
                    <p style="margin: 5px 0; color: #155724;">
                        <strong>✅ Webhook is working!</strong><br>
                        Last webhook received: <strong><?php echo esc_html($last_webhook_received); ?></strong>
                    </p>
                    <?php if (!empty($last_webhook_data)): ?>
                        <details style="margin-top: 10px;">
                            <summary style="cursor: pointer; color: #155724; font-weight: bold;">View Last Webhook Data</summary>
                            <pre style="background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 4px; margin-top: 10px; overflow-x: auto; font-size: 12px;"><?php echo esc_html(json_encode($last_webhook_data, JSON_PRETTY_PRINT)); ?></pre>
                        </details>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="margin: 0; color: #856404;">
                        <strong>⚠️ No webhooks received yet.</strong><br>
                        After configuring webhooks in Zoho Inventory, create/update an item, contact, or invoice to test. 
                        If no webhooks are received, webhooks may not be supported or configured incorrectly.
                    </p>
                <?php endif; ?>
            </div>
            
        </div>
        <?php endif; ?>
        
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

/* ======================================================
   AUTH URL
====================================================== */
function zoho_auth_url() {
    return 'https://accounts.zoho.com/oauth/v2/auth?scope=ZohoInventory.FullAccess.all'
        .'&client_id='.get_option('zoho_client_id')
        .'&response_type=code'
        .'&access_type=offline'
        .'&prompt=consent'
        .'&redirect_uri='.urlencode(get_option('zoho_redirect_uri'));
}

