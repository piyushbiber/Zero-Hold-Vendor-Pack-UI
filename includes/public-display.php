<?php
if (!defined('ABSPATH')) exit;

/**
 * Single Product Display Enhancements
 * Shows "Box contains: X pcs" under the price.
 */
add_action('woocommerce_single_product_summary', function () {
    global $product;
    if (!$product) return;

    $product_id = $product->get_id();

    // Get the calculated pieces per box
    $pcs = get_post_meta($product_id, 'zh_pieces_per_box', true);

    if (!$pcs) return;

    // Expected GIF path: Zero Hold Vendor Pack UI/assets/box.gif
    $gif_url = plugin_dir_url(dirname(__FILE__)) . 'assets/box.gif';

    echo '<div class="zh-box-pcs" style="margin-top:8px; display:flex; align-items:center; gap:6px; font-weight:600;">';
    echo '<img src="' . esc_url($gif_url) . '" alt="Box" style="width:20px; height:20px;" />';
    echo '<span>Box contains: ' . esc_html($pcs) . ' pcs</span>';
    echo '</div>';
}, 25);
