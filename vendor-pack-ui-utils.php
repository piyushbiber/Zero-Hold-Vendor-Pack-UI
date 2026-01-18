<?php
if (!defined('ABSPATH')) exit;

/**
 * Utility functions for Zero Hold Vendor Pack UI
 * Place shared helpers here for use across the plugin.
 */

/**
 * Calculate box price from content (PCS × Base Price)
 * Supports 'one' and 'mixed' pack types.
 */
function zh_calculate_box_price_from_content($product_id) {
    $box_content_raw = get_post_meta($product_id, 'zh_box_content', true);
    if (empty($box_content_raw)) {
        return 0;
    }

    $box_content = json_decode($box_content_raw, true);
    if (!is_array($box_content)) {
        return 0;
    }

    $pack_type = get_post_meta($product_id, 'zh_pack_type', true);
    $box_price = 0;

    if ($pack_type === 'one') {
        $first_row = reset($box_content);
        if ($first_row !== false) {
            $pcs  = isset($first_row['pcs']) ? (int)$first_row['pcs'] : 0;
            $base = isset($first_row['base_price']) ? (float)$first_row['base_price'] : 0;
            $box_price = $pcs * $base;
        }
    } else if ($pack_type === 'mixed') {
        foreach ($box_content as $size => $data) {
            $pcs  = isset($data['pcs']) ? (int)$data['pcs'] : 0;
            $base = isset($data['base_price']) ? (float)$data['base_price'] : 0;
            $box_price += ($pcs * $base);
        }
    }

    return round($box_price, 2);
}

/**
 * Sync WooCommerce price with calculated box price.
 * Regular Price = Box Calculation, Sale Price = allowed if < regular.
 */
function zh_sync_wc_box_price($product_id) {
    $box_price = zh_calculate_box_price_from_content($product_id);
    if ($box_price <= 0) {
        return;
    }

    update_post_meta($product_id, '_regular_price', $box_price);

    $sale_price = get_post_meta($product_id, '_sale_price', true);
    $current_price = $box_price;

    if ($sale_price !== '' && floatval($sale_price) < $box_price) {
        $current_price = $sale_price;
    }

    update_post_meta($product_id, '_price', $current_price);
}