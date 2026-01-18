<?php
if (!defined('ABSPATH')) exit;

/**
 * ZERO HOLD → Woo Stock Sync (FINAL, SAFE)
 * Rule: 1 Box = 1 Stock Unit
 * Scope: Simple products only
 * 
 * This hook runs AFTER all meta, Dokan, and Woo normalizations are complete.
 */

add_action(
    'woocommerce_after_product_object_save',
    'zh_sync_stock_after_woo_save',
    20,
    2
);

function zh_sync_stock_after_woo_save($product, $data_store) {

    if (!$product instanceof WC_Product) {
        return;
    }

    /**
     * 🧠 FIX: Only sync stock when Zero Hold inventory is being updated.
     * This allows Admin UI stock changes to be respected.
     */
    if (empty($_POST['zh_box_inventory'])) {
        return;
    }

    $product_id = $product->get_id();

    // 🔒 HARD GUARD (As requested)
    $product = wc_get_product($product_id);
    if (!$product) {
        return;
    }

    /**
     * 🔒 HARD GUARD
     * Simple stock logic MUST run ONLY for true simple products
     */
    if (!$product->is_type('simple')) {
        return;
    }

    // Read authoritative Zero Hold inventory JSON
    $raw_inventory = get_post_meta($product_id, 'zh_box_inventory', true);

    if ($raw_inventory === '' || $raw_inventory === null) {
        return;
    }

    /**
     * ARCHITECTURAL ADJUSTMENT:
     * Zero Hold saves inventory as JSON in 'zh_box_inventory' (e.g. {"Red": "5"}).
     * We sum all values to determine the total stock units (boxes).
     */
    $boxes = 0;
    if ( is_string( $raw_inventory ) && strpos( $raw_inventory, '{' ) !== false ) {
        $decoded = json_decode( $raw_inventory, true );
        if ( is_array( $decoded ) ) {
            foreach ( $decoded as $val ) {
                $boxes += absint( $val );
            }
        }
    } else {
        $boxes = absint($raw_inventory);
    }

    // Enable stock management
    $product->set_manage_stock(true);

    // 1 box = 1 stock unit
    $product->set_stock_quantity($boxes);

    // Set status
    $product->set_stock_status(
        $boxes > 0 ? 'instock' : 'outofstock'
    );

    // Prevent infinite loop during $product->save()
    remove_action(
        'woocommerce_after_product_object_save',
        'zh_sync_stock_after_woo_save',
        20
    );

    // Save final WooCommerce state
    $product->save();

    // Restore hook for subsequent operations
    add_action(
        'woocommerce_after_product_object_save',
        'zh_sync_stock_after_woo_save',
        20,
        2
    );
}
