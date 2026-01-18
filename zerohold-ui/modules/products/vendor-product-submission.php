<?php
if (!defined('ABSPATH')) exit;

/**
 * ZeroHold Vendor Product Submission Guard
 * Purpose: 
 * 1. Force every submission to be a unique product (No Merging).
 * 2. Auto-assign SKU based on WordPress Product ID (ZH-{ID}).
 * 3. Break post_parent relationships that Dokan uses for "Resubmissions".
 */

/**
 * Hook into product creation to assign unique SKU and break parent linking.
 * This fires for both Dokan Lite and Pro creation flows.
 */
add_action('wp_insert_post', 'zh_guard_new_vendor_product', 10, 3);
function zh_guard_new_vendor_product($post_id, $post, $update) {
    if ($post->post_type !== 'product') return;

    // Only handle vendor-created products in the dashboard
    if (!is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
        if (!function_exists('dokan_get_current_user_id') || !dokan_get_current_user_id()) return;
        
        // Ensure it belongs to the current vendor
        if ($post->post_author != dokan_get_current_user_id()) return;

        // 1. Force Break post_parent (Disables Dokan's Revision/Merge logic)
        if ($post->post_parent != 0) {
            remove_action('wp_insert_post', 'zh_guard_new_vendor_product', 10);
            wp_update_post([
                'ID' => $post_id,
                'post_parent' => 0
            ]);
            add_action('wp_insert_post', 'zh_guard_new_vendor_product', 10, 3);
        }

        // 2. Auto-Assign Unique SKU
        $product = wc_get_product($post_id);
        if ($product) {
            // Check Pack Type from POST or Meta (more reliable than is_type during save)
            // If Pack Type is 'one', it WILL be a variable product.
            $pack_type = isset($_POST['zh_pack_type']) ? sanitize_text_field($_POST['zh_pack_type']) : get_post_meta($post_id, 'zh_pack_type', true);
            $is_one_size_box = ($pack_type === 'one');
            
            $current_sku = $product->get_sku();
            
            if ($is_one_size_box) {
                // Rule: Parent SKU NO NEED TO SHOW for Variable products
                // We clear it to avoid confusion in the dashboard
                if (!empty($current_sku)) {
                    remove_action('wp_insert_post', 'zh_guard_new_vendor_product', 10);
                    $product->set_sku('');
                    $product->save();
                    add_action('wp_insert_post', 'zh_guard_new_vendor_product', 10, 3);
                }
            } else {
                // For Simple Products: Keep ZH-{ID}
                $new_sku = 'ZH-' . $post_id;
                if (empty($current_sku) || $current_sku !== $new_sku) {
                    remove_action('wp_insert_post', 'zh_guard_new_vendor_product', 10);
                    $product->set_sku($new_sku);
                    $product->save();
                    add_action('wp_insert_post', 'zh_guard_new_vendor_product', 10, 3);
                }
            }
        }
    }
}

/**
 * Aggressive Guard for Dokan Pro's "Resubmitted" flag.
 */
add_filter('dokan_is_product_author', function($is_author, $product_id) {
    return $is_author;
}, 10, 2);

/**
 * Ensure the SKU is also applied to variations if they exist (for One-Size Box)
 * Variations will follow a pattern: ZH-{ParentID}-{SizeCode} (e.g. ZH-30567-XS)
 */
add_action('woocommerce_save_product_variation', 'zh_guard_variation_sku', 99, 2);
add_action('save_post_product_variation', 'zh_guard_variation_sku', 99, 1);

function zh_guard_variation_sku($variation_id, $i = null) {
    // Only run for variations
    if (get_post_type($variation_id) !== 'product_variation') return;

    $variation = wc_get_product($variation_id);
    if ($variation) {
        $parent_id = $variation->get_parent_id();
        if (!$parent_id) return;
        
        // Use the size attribute to create a human-readable SKU suffix
        $size_val = $variation->get_attribute('pa_size');
        
        if (empty($size_val)) {
            $attributes = $variation->get_attributes();
            $size_val = isset($attributes['pa_size']) ? $attributes['pa_size'] : '';
        }

        // Clean up the suffix: uppercase and swap separators
        $suffix = (!empty($size_val) && is_string($size_val)) 
            ? strtoupper(str_replace([' ', '/'], '-', $size_val)) 
            : $variation_id;
        
        $new_sku = 'ZH-' . $parent_id . '-' . $suffix;
        
        if ($variation->get_sku() !== $new_sku) {
            // Remove actions to prevent infinite loop
            remove_action('woocommerce_save_product_variation', 'zh_guard_variation_sku', 99);
            remove_action('save_post_product_variation', 'zh_guard_variation_sku', 99);
            
            $variation->set_sku($new_sku);
            $variation->save();
            
            add_action('woocommerce_save_product_variation', 'zh_guard_variation_sku', 99, 2);
            add_action('save_post_product_variation', 'zh_guard_variation_sku', 99, 1);
        }
    }
}

/**
 * LiteSpeed Cache Auto-Purge Fix
 * Clears the cache when products are saved or deleted to ensure the dashboard list is updated.
 */
add_action('wp_insert_post', 'zh_lscache_purge_on_save', 999, 1);
add_action('wp_trash_post', 'zh_lscache_purge_on_save', 999, 1);
add_action('before_delete_post', 'zh_lscache_purge_on_save', 999, 1);

// Dokan specific hooks to be double-sure
add_action('dokan_new_product_added', 'zh_lscache_purge_on_save', 999, 1);
add_action('dokan_product_updated', 'zh_lscache_purge_on_save', 999, 1);

function zh_lscache_purge_on_save($post_id) {
    // Check if it's a product or variation
    $post_type = get_post_type($post_id);
    if (!in_array($post_type, ['product', 'product_variation'])) {
        return;
    }

    // Try multiple ways to trigger LiteSpeed purge
    $purged = false;
    
    if (function_exists('litespeed_purge_all')) {
        litespeed_purge_all();
        $purged = true;
    }
    
    // Also trigger via action hook which is often more reliable in LSCache
    if (has_action('litespeed_purge_all')) {
        do_action('litespeed_purge_all');
        $purged = true;
    }
    
    if ($purged) {
        error_log('[ZeroHold] 🔄 LiteSpeed Cache Purge Triggered for Product ID: ' . $post_id);
    }
}
