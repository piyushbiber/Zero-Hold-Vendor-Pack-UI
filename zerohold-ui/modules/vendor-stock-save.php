<?php
if (!defined('ABSPATH')) exit;

/**
 * Save Stock Updates for Vendor v1.0
 */
add_action('wp_ajax_zh_vendor_save_stock', function() {

    check_ajax_referer('zh_vendor_stock_nonce', 'nonce');

    $pid     = absint($_POST['pid']);
    $updates = $_POST['updates'] ?? [];
    $user_id = get_current_user_id();

    if (!$pid || empty($updates) || !$user_id) {
        wp_send_json_error(['msg' => 'Bad request']);
    }

    /** 
     * RATE LIMIT: 2 updates / product / vendor / day
     * Reset = Midnight UTC (date() uses server time, gmdate() for UTC)
     */
    $today = gmdate('Ymd');
    $limit_key = "zh_stock_limit_{$user_id}_{$pid}_{$today}";
    $current_count = (int) get_user_meta($user_id, $limit_key, true);

    if ($current_count >= 2) {
        wp_send_json_error(['msg' => 'Limit reached, try tomorrow']);
    }

    /** 
     * Save Stock to WooCommerce (Single Source of Truth)
     */
    $product = wc_get_product($pid);
    if (!$product) {
        wp_send_json_error(['msg' => 'Product not found']);
    }

    $total_stock = 0;
    foreach ($updates as $vid => $qty) {
        $vid = absint($vid);
        $qty = max(0, intval($qty));
        
        if ($vid === $pid && $product->is_type('simple')) {
            // Simple Product: Update parent directly
            $product->set_manage_stock('yes');
            $product->set_stock_quantity($qty);
            $total_stock = $qty;
        } else {
            // Variation: Update meta directly for speed
            update_post_meta($vid, '_manage_stock', 'yes');
            update_post_meta($vid, '_stock', $qty);
            
            // Update stock status for variation
            $status = ($qty > 0) ? 'instock' : 'outofstock';
            update_post_meta($vid, '_stock_status', $status);
            
            $total_stock += $qty;
        }
    }

    /** Update Parent Project Stock Status */
    $parent_status = ($total_stock > 0) ? 'instock' : 'outofstock';
    $product->set_stock_status($parent_status);
    $product->save();

    /** Increment Limit Count */
    update_user_meta($user_id, $limit_key, $current_count + 1);

    wp_send_json_success([
        'msg'        => 'Stock updated',
        'limit_left' => 2 - ($current_count + 1)
    ]);
});
