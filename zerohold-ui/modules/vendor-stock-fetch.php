<?php
if (!defined('ABSPATH')) exit;

/**
 * Fetch Stock & Summary for Vendor Stock Editor v1.0
 */
add_action('wp_ajax_zh_vendor_fetch_stock', function() {

    check_ajax_referer('zh_vendor_stock_nonce', 'nonce');

    $pid = absint($_POST['pid']);
    $product = wc_get_product($pid);

    if (!$product || !in_array($product->get_type(), ['variable', 'simple'])) {
        wp_send_json_error(['msg' => 'Invalid product type']);
    }

    // Helper to get term name
    $get_zh_term = function($pid, $tax) {
        $terms = wp_get_object_terms($pid, $tax, ['fields' => 'names']);
        return (!is_wp_error($terms) && !empty($terms)) ? $terms[0] : 'N/A';
    };

    // Map Pack Type to labels
    $pack_type_raw = get_post_meta($pid, 'zh_pack_type', true);
    $pack_type_map = [
        'one'      => 'One-Size Box',
        'mixed'    => 'Mixed-Size Box',
        'freesize' => 'Free-Size Box'
    ];
    $pack_type_label = isset($pack_type_map[$pack_type_raw]) ? $pack_type_map[$pack_type_raw] : 'N/A';

    // Fetch Summary Metadata
    $summary = [
        'wear_type' => get_post_meta($pid, 'zh_wear_type', true),
        'fabric'    => $get_zh_term($pid, 'zh_fabric'),
        'pattern'   => $get_zh_term($pid, 'zh_pattern'),
        'gender'    => get_post_meta($pid, 'zh_wear_for', true),
        'color'     => $get_zh_term($pid, 'zh_color'),
        'pack_type' => $pack_type_label,
    ];

    // Fetch Variation Data (Woo is Single Source of Truth)
    $vars = [];
    if ($product->is_type('variable')) {
        foreach ($product->get_children() as $vid) {
            $v = wc_get_product($vid);
            if (!$v) continue;

            $vars[] = [
                'vid'   => $vid,
                'label' => $v->get_attribute('pa_size'), // e.g. XS/36
                'stock' => (int) $v->get_stock_quantity(),
            ];
        }
    } else {
        // Simple Product: Return a single row
        $vars[] = [
            'vid'   => $pid,
            'label' => 'Total Stock',
            'stock' => (int) $product->get_stock_quantity(),
        ];
    }

    wp_send_json_success([
        'pid'     => $pid,
        'title'   => $product->get_name(),
        'summary' => $summary,
        'vars'    => $vars
    ]);
});
