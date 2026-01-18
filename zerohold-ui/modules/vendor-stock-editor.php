<?php
if (!defined('ABSPATH')) exit;

/**
 * Inject "Edit Stock" button in Dokan product list (Stock Column)
 * Using template-verified hook for guaranteed visibility.
 */
add_action('dokan_product_list_table_after_column_content_stock', function($product) {
    if ( ! is_a( $product, 'WC_Product' ) ) return;

    // Only for published or inactive products (Variable or Simple)
    if (!in_array($product->get_status(), ['publish', 'private']) || !in_array($product->get_type(), ['variable', 'simple'])) {
        return;
    }

    // Render the button - using a smaller, dashboard-friendly style (Grey/Black)
    printf(
        '<div style="margin-top: 7px;"><a href="javascript:void(0);" class="zh-edit-stock-btn" data-product-id="%d" style="background: #f3f3f3; color: #333; border: 1px solid #ddd; padding: 2px 6px; font-size: 10px; border-radius: 3px; text-decoration: none; font-weight: 600; text-transform: uppercase;">Edit Stock</a></div>',
        $product->get_id()
    );
}, 10, 1);


/**
 * Enqueue JS/CSS only on Dokan Seller Dashboard
 */
add_action('dokan_enqueue_scripts', function() {
    
    // Safety Guard: Only load on Dokan Seller Dashboard
    if ( ! function_exists('dokan_is_seller_dashboard') || ! dokan_is_seller_dashboard() ) {
        return;
    }

    wp_enqueue_style(
        'zh-stock-editor-style',
        plugin_dir_url(__FILE__) . '../assets/vendor-stock-editor.css',
        [],
        time()
    );

    wp_enqueue_script(
        'zh-stock-editor-script',
        plugin_dir_url(__FILE__) . '../assets/vendor-stock-editor.js',
        ['jquery'],
        time(),
        true
    );

    wp_localize_script('zh-stock-editor-script', 'ZHVStock', [
        'ajax' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('zh_vendor_stock_nonce'),
    ]);
});
