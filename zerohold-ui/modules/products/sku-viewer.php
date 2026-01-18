<?php
if (!defined('ABSPATH')) exit;

/**
 * SKU Viewer Module
 * Purpose: Allows vendors to see all variation SKUs for One-Size Box products in a popup.
 */

/**
 * 1. Inject "See All SKU" link in SKU Column
 */
add_action('dokan_product_list_table_after_column_content_sku', function($product) {
    if ( ! is_a( $product, 'WC_Product' ) ) return;

    // Only for Variable products (One-Size Box)
    if ($product->get_type() !== 'variable') {
        return;
    }

    // Render the link
    printf(
        '<div style="margin-top: 5px;"><a href="javascript:void(0);" class="zh-see-sku-btn" data-product-id="%d" style="color: #d63384; font-size: 11px; font-weight: 600; text-decoration: underline;">See All SKU</a></div>',
        $product->get_id()
    );
}, 10, 1);

/**
 * 2. AJAX Fetch: Get Variation SKUs
 */
add_action('wp_ajax_zh_get_variation_skus', function() {
    check_ajax_referer('zh_vendor_stock_nonce', 'nonce'); // Reuse existing nonce

    $pid = absint($_POST['pid']);
    $product = wc_get_product($pid);

    if (!$product || $product->get_type() !== 'variable') {
        wp_send_json_error(['msg' => 'Invalid product type']);
    }

    // Reuse existing summary logic for consistency
    $get_zh_term = function($pid, $tax) {
        $terms = wp_get_object_terms($pid, $tax, ['fields' => 'names']);
        return (!is_wp_error($terms) && !empty($terms)) ? $terms[0] : 'N/A';
    };

    $summary = [
        'wear_type' => get_post_meta($pid, 'zh_wear_type', true),
        'fabric'    => $get_zh_term($pid, 'zh_fabric'),
        'pattern'   => $get_zh_term($pid, 'zh_pattern'),
        'gender'    => get_post_meta($pid, 'zh_wear_for', true),
        'color'     => $get_zh_term($pid, 'zh_color'),
        'pack_type' => 'One-Size Box',
    ];

    $skus = [];
    foreach ($product->get_children() as $vid) {
        $v = wc_get_product($vid);
        if (!$v) continue;

        $skus[] = [
            'label' => $v->get_attribute('pa_size'),
            'sku'   => $v->get_sku() ?: 'N/A'
        ];
    }

    wp_send_json_success([
        'title'   => $product->get_name(),
        'summary' => $summary,
        'skus'    => $skus
    ]);
});

/**
 * 3. Enqueue Scripts
 */
add_action('dokan_enqueue_scripts', function() {
    if ( ! function_exists('dokan_is_seller_dashboard') || ! dokan_is_seller_dashboard() ) {
        return;
    }

    wp_enqueue_script(
        'zh-sku-viewer-js',
        plugin_dir_url(__FILE__) . '../../assets/sku-viewer.js',
        ['jquery'],
        time(),
        true
    );

    wp_localize_script('zh-sku-viewer-js', 'ZHSkuViewer', [
        'ajax' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('zh_vendor_stock_nonce'),
    ]);
});
