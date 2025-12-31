<?php
/**
 * Inventory Sync Page and Functions
 */

if (!defined('ABSPATH')) exit;

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
            // Save organization_id with item
            $org_id = get_option('zoho_organization_id', '');
            if (!empty($org_id)) {
                update_post_meta($product_id, '_zoho_org_id', $org_id);
            }
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
