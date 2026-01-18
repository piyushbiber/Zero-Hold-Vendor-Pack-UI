<?php
if (!defined('ABSPATH')) exit;

/**
 * Display Product ID (#ID) next to product names in the vendor dashboard.
 */

/**
 * 1. Product List Table (next to name)
 */
add_action('dokan_product_list_table_after_column_content_name', 'zh_display_id_in_product_list', 5, 1);
function zh_display_id_in_product_list($product) {
    if (!$product) return;
    printf(
        '<span class="zh-product-id-tag" style="color: #999; font-size: 11px; font-weight: 400; margin-left: 5px; vertical-align: middle;">Product ID: %d</span>',
        $product->get_id()
    );
}

/**
 * 2. Edit Product Page (Header/Title area)
 */
add_action('dokan_before_product_edit_status_label', 'zh_display_id_in_edit_header', 10, 1);
function zh_display_id_in_edit_header($product) {
    if (!$product) return;
    printf(
        '<span class="zh-product-id-header" style="color: #999; font-size: 14px; font-weight: 400; margin-left: 8px; margin-right: 5px;">Product ID: %d</span>',
        $product->get_id()
    );
}
