<?php
/**
 * Field Mapping Page and Functions
 */

if (!defined('ABSPATH')) exit;

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

