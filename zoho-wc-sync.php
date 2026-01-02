<?php
/**
 * Plugin Name: Zoho Inventory WooCommerce Sync
 * Description: Zoho Inventory → WooCommerce Inventory Sync
 * Version: 1.2.3
 * Author: Skyler Rubber
 */

if (!defined('ABSPATH')) exit;

// Load Zoho API
require_once plugin_dir_path(__FILE__) . 'includes/zoho-api.php';

// Load organized admin files
require_once plugin_dir_path(__FILE__) . 'includes/admin/menu.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/dashboard.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/fields.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/inventory.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/contact.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/invoice.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/logs.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/auto-sync.php';
require_once plugin_dir_path(__FILE__) . 'includes/webhooks.php';
require_once plugin_dir_path(__FILE__) . 'includes/webhooks-contact.php';
require_once plugin_dir_path(__FILE__) . 'includes/webhooks-invoice.php';



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
