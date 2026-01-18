<?php
if (!defined('ABSPATH')) exit;

/**
 * Step 3: Product AJAX Handlers
 * Handles: inactive, bulk inactive, reactivate
 */

add_action('wp_ajax_zh_stock_inactive', 'zh_handle_stock_inactive');
add_action('wp_ajax_zh_stock_bulk_inactive', 'zh_handle_stock_bulk_inactive');
add_action('wp_ajax_zh_stock_reactivate', 'zh_handle_stock_reactivate');

function zh_handle_stock_inactive() {
    check_ajax_referer('zh_vendor_stock_nonce', 'nonce');
    
    $pid = absint($_POST['pid']);
    if (!$pid || get_post_field('post_author', $pid) != get_current_user_id()) {
        wp_send_json_error(['msg' => 'Unauthorized']);
    }

    wp_update_post([
        'ID'          => $pid,
        'post_status' => 'private'
    ]);

    wp_send_json_success(['msg' => 'Product set to inactive']);
}

function zh_handle_stock_bulk_inactive() {
    check_ajax_referer('zh_vendor_stock_nonce', 'nonce');
    
    $pids = array_map('absint', $_POST['pids'] ?? []);
    if (empty($pids)) {
        wp_send_json_error(['msg' => 'No products selected']);
    }

    $user_id = get_current_user_id();
    $count = 0;

    foreach ($pids as $pid) {
        if (get_post_field('post_author', $pid) == $user_id) {
            wp_update_post([
                'ID'          => $pid,
                'post_status' => 'private'
            ]);
            $count++;
        }
    }

    wp_send_json_success(['msg' => "$count products set to inactive"]);
}

function zh_handle_stock_reactivate() {
    check_ajax_referer('zh_vendor_stock_nonce', 'nonce');
    
    $pid = absint($_POST['pid']);
    if (!$pid || get_post_field('post_author', $pid) != get_current_user_id()) {
        wp_send_json_error(['msg' => 'Unauthorized']);
    }

    wp_update_post([
        'ID'          => $pid,
        'post_status' => 'publish'
    ]);

    wp_send_json_success(['msg' => 'Product reactivated']);
}
