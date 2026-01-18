<?php
if (!defined('ABSPATH')) exit;

/*
 * ZeroHold – Product Save Lifecycle Control
 * Phase 1 (Option B)
 */

if (!defined('ZH_PRODUCT_CONTROL_ENABLED')) {
    define('ZH_PRODUCT_CONTROL_ENABLED', true);
}

function zh_get_or_create_size_term($label) {

    $taxonomy = 'pa_size';

    // Try by name
    $term = get_term_by('name', $label, $taxonomy);
    if ($term) return $term;

    // Create slug from label
    $slug = sanitize_title($label);

    $created = wp_insert_term($label, $taxonomy, [
        'slug' => $slug,
    ]);

    if (is_wp_error($created)) return null;

    return get_term($created['term_id'], $taxonomy);
}

/* ======================================================
 * 1️⃣ STOP DOKAN DESTRUCTIVE SAVE LOGIC
 * ====================================================== */

add_action('init', function () {

    if (!ZH_PRODUCT_CONTROL_ENABLED) return;

    // Dokan mutates product meta & variations here — must stop
    if (function_exists('dokan_process_product_meta')) {
        remove_action(
            'woocommerce_process_product_meta',
            'dokan_process_product_meta',
            10
        );
    }

});

/* ======================================================
 * 2️⃣ EARLY PRODUCT SAVE INTERCEPTION (CORE)
 * ====================================================== */

add_filter(
    'wp_insert_post_data',
    'zh_intercept_product_save',
    10,
    2
);

function zh_intercept_product_save($data, $postarr) {

    if (!ZH_PRODUCT_CONTROL_ENABLED) return $data;

    if ($data['post_type'] !== 'product') return $data;

    if (!is_admin()) return $data;

    // ZeroHold pack type must exist
    if (empty($_POST['zh_pack_type'])) return $data;

    $pack_type = sanitize_text_field($_POST['zh_pack_type']);

    /*
     * LOCKED ZeroHold rule:
     * one   → variable
     * mixed → simple
     */
    if ($pack_type === 'one') {
        $_POST['product-type'] = 'variable';
    } else {
        $_POST['product-type'] = 'simple';
    }

    return $data;
}

/* ======================================================
 * 3️⃣ ATTRIBUTE PREP (ANTI-RESET SHIELD)
 * ====================================================== */

add_action(
    'save_post_product',
    'zh_prepare_attributes',
    5
);

function zh_prepare_attributes($product_id) {

    if (!ZH_PRODUCT_CONTROL_ENABLED) return;

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($product_id)) return;

    $pack_type = get_post_meta($product_id, 'zh_pack_type', true);
    if (!$pack_type) return;

    $product = wc_get_product($product_id);
    if (!$product) return;

    $attributes = $product->get_attributes();

    foreach ($attributes as $key => $attribute) {

        if ($key === 'pa_size' && $pack_type === 'one') {
            $attribute->set_visible(true);
            $attribute->set_variation(true);
        } else {
            $attribute->set_variation(false);
        }

        $attributes[$key] = $attribute;
    }

    $product->set_attributes($attributes);
    $product->save();
}

/* ======================================================
 * PHASE 2 — ZEROHOLD VARIATION ENGINE
 * ====================================================== */

// [DISABLED - AUTHORITATIVE FLOW]
// add_action(
//     'woocommerce_after_product_object_save',
//     'zh_sync_variations_final',
//     20,
//     2
// );
//
// function zh_sync_variations_final($product, $data_store) {
//     if (!ZH_PRODUCT_CONTROL_ENABLED) return;
//     if (!$product || !$product->get_id()) return;
//     zh_sync_variations($product->get_id());
// }

function zh_sync_variations($product_id) {

    if (!ZH_PRODUCT_CONTROL_ENABLED) return;

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($product_id)) return;

    // Only for single-size box products
    $pack_type = get_post_meta($product_id, 'zh_pack_type', true);
    if ($pack_type !== 'one') return;

    $product = wc_get_product($product_id);
    if (!$product || !$product->is_type('variable')) return;

    // Authoritative size list
    $sizes = get_post_meta($product_id, 'zh_selected_sizes', true);
    if (!is_array($sizes) || empty($sizes)) return;

    /*
     * Build lookup of existing variations
     * key = size slug (lowercase, sanitized)
     */
    $existing_variations = [];

    foreach ($product->get_children() as $variation_id) {
        $variation = wc_get_product($variation_id);
        if (!$variation) continue;

        $size = $variation->get_attribute('pa_size');
        if ($size) {
            $key = sanitize_title($size);
            $existing_variations[$key] = $variation_id;
        }
    }

    /*
     * Create missing variations ONLY
     * Never delete anything
     */
    foreach ($sizes as $size_label) {

        $size_key = sanitize_title($size_label);

        // Variation already exists → do nothing
        if (isset($existing_variations[$size_key])) {
            continue;
        }

        // Create new variation
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($product_id);
        $variation->set_attributes([
            'pa_size' => $size_label,
        ]);

        // Do NOT set price or stock here (Phase 3)
        $variation->set_status('publish');

        $variation_id = $variation->save();

        // Safety: mark variation as ZeroHold-controlled
        if ($variation_id) {
            update_post_meta($variation_id, 'zh_variation_locked', 1);
        }
    }
}

/* ======================================================
 * PHASE 0 — NORMALIZE ZEROHOLD META (CORE FIX)
 * ====================================================== */

/**
 * 🔧 STEP 1 — SIZE LABEL → SLUG HELPER
 */
function zh_size_label_to_slug($label) {
    $label = strtolower(trim($label));
    $label = str_replace(['/', ' '], '-', $label);
    return sanitize_title($label);
}

function zh_normalize_zerohold_size_meta($product_id) {

    // Only ZeroHold single-size box
    if (get_post_meta($product_id, 'zh_pack_type', true) !== 'one') {
        return;
    }

    /* ==========================
     * Normalize zh_selected_sizes
     * ========================== */
    $sizes = get_post_meta($product_id, 'zh_selected_sizes', true);
    if (is_array($sizes)) {
        $normalized = [];
        foreach ($sizes as $label) {
            $normalized[] = zh_size_label_to_slug($label);
        }
        update_post_meta($product_id, 'zh_selected_sizes', array_values(array_unique($normalized)));
    }

    /* ==========================
     * Normalize zh_box_content
     * ========================== */
    $box_content = get_post_meta($product_id, 'zh_box_content', true);
    if (is_array($box_content)) {

        $new_content = [];

        foreach ($box_content as $color => $sizes) {
            foreach ($sizes as $label => $row) {
                $slug = zh_size_label_to_slug($label);
                $new_content[$color][$slug] = $row;
            }
        }

        update_post_meta($product_id, 'zh_box_content', $new_content);
    }

    /* ==========================
     * Normalize zh_box_inventory
     * ========================== */
    $inventory = get_post_meta($product_id, 'zh_box_inventory', true);
    if (is_array($inventory)) {

        $new_inventory = [];

        foreach ($inventory as $color => $sizes) {
            foreach ($sizes as $label => $qty) {
                $slug = zh_size_label_to_slug($label);
                $new_inventory[$color][$slug] = (int) $qty;
            }
        }

        update_post_meta($product_id, 'zh_box_inventory', $new_inventory);
    }
}

/**
 * 🔗 STEP 3 — RUN NORMALIZATION ON SAVE
 */
add_action('save_post_product', function ($product_id) {

    if (!defined('ZH_PRODUCT_CONTROL_ENABLED') || !ZH_PRODUCT_CONTROL_ENABLED) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    zh_normalize_zerohold_size_meta($product_id);

}, 25); // AFTER zh_save_pack_and_taxonomies (priority 20)

function zh_rebuild_variations_slug_first($product_id) {

    $product = wc_get_product($product_id);
    if (!$product || !$product->is_type('variable')) return;

    if (get_post_meta($product_id, 'zh_pack_type', true) !== 'one') return;

    $selected_sizes = get_post_meta($product_id, 'zh_selected_sizes', true);
    if (!is_array($selected_sizes)) return;

    foreach ($selected_sizes as $size_label) {

        // 1️⃣ Ensure term
        $term = zh_get_or_create_size_term($size_label);
        if (!$term) continue;

        $size_slug = $term->slug;
        $fixed = false;

        // 2️⃣ Fix existing variations
        foreach ($product->get_children() as $child_id) {
            $variation = wc_get_product($child_id);
            if (!$variation) continue;

            $current = $variation->get_attribute('pa_size');

            // If label matches, FIX it
            if ($current === $size_label) {
                update_post_meta(
                    $child_id,
                    'attribute_pa_size',
                    $size_slug
                );
                $variation->set_attributes(['pa_size' => $size_slug]);
                $variation->save();
                $fixed = true;
            }

            // Already correct
            if ($current === $size_slug) {
                $fixed = true;
            }
        }

        // 3️⃣ Create if still missing
        if (!$fixed) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            $variation->set_attributes(['pa_size' => $size_slug]);
            $variation->set_status('publish');
            $variation->save();
        }
    }

    // 🔒 Force Woo to accept changes
    WC_Product_Variable::sync($product_id);
    wc_delete_product_transients($product_id);
}

// [DISABLED - AUTHORITATIVE FLOW]
// add_action('save_post_product', function ($product_id) {
//     zh_rebuild_variations_slug_first($product_id);
// }, 20);

/* ======================================================
 * ZEROHOLD — BOX PRICE RESOLVER (AUTHORITATIVE)
 * ====================================================== */

/**
 * Resolve box regular price
 *
 * @param int    $product_id Parent product ID
 * @param string $size_key   Size label like "XS/36"
 *
 * @return float
 */
function zh_resolve_box_price($product_id, $size_key) {

    if (!$product_id || !$size_key) return 0;

    $pack_type = get_post_meta($product_id, 'zh_pack_type', true);
    if ($pack_type !== 'one') return 0;

    $box_content = get_post_meta($product_id, 'zh_box_content', true);
    if (!is_array($box_content)) {
        error_log("ZH PRICE DEBUG: No box content for product $product_id");
        return 0;
    }

    // DEBUG: Trace what we are looking for
    // error_log("ZH PRICE LOOKUP: Product $product_id | Searching for Size Slug: '$size_key'");

    foreach ($box_content as $color => $sizes) {
        
        // DEBUG LEVEL lookup
        if (isset($sizes[$size_key])) {
             error_log("ZH PRICE DEBUG: Found exact match for '$size_key'");
        } else {
             // Maybe keys are Labels? (e.g. "Small" vs "small")
             error_log("ZH PRICE DEBUG: Size '$size_key' not found in color '$color'. Available keys: " . implode(',', array_keys($sizes)));
        }

        if (
            isset($sizes[$size_key]['pcs']) &&
            isset($sizes[$size_key]['base_price'])
        ) {
            $pcs        = (int) $sizes[$size_key]['pcs'];
            $base_price = (float) $sizes[$size_key]['base_price'];
            $final      = round($pcs * $base_price, 2);
            
            error_log("ZH PRICE FOUND: $final (PCS: $pcs * Base: $base_price)");
            return $final;
        }
    }

    return 0;
}

/**
 * Map size label (e.g. "XL") to slug (e.g. "xl")
 */
function zh_get_size_slug_from_label($label) {
    $term = get_term_by('name', $label, 'pa_size');
    return $term ? $term->slug : null;
}


/* ======================================================
 * SHUTDOWN ENFORCER (PRICE SAFETY)
 * ====================================================== */
add_action('shutdown', function () {

    if (!is_admin()) return;
    if (!defined('ZH_PRODUCT_CONTROL_ENABLED') || !ZH_PRODUCT_CONTROL_ENABLED) return;
    if (!isset($_POST['post_ID'])) return;

    $product_id = (int) $_POST['post_ID'];
    if (!$product_id) return;

    zh_normalize_zerohold_size_meta($product_id);

});

/* ======================================================
 * PHASE 3 — ZEROHOLD PRICE & STOCK INJECTION
 * ====================================================== */

// MOVED TO SCRIPT END TO ENSURE ORDER
// add_action(
//     'woocommerce_after_product_object_save',
//     'zh_inject_price_and_stock',
//     30,
//     2
// );

// [DISABLED - AUTHORITATIVE FLOW]
// add_action('save_post_product', function($product_id) { ... }, 50);

// [DISABLED LEGACY INJECTION]
// function zh_inject_price_and_stock($product, $data_store) { ... }

/**
 * 🔒 AUTHORITATIVE VARIATION CREATION (FINAL TRUTH)
 *
 * Designed to own the variation creation + pricing in ONE atomic operation.
 * Bypasses all conflict-prone lifecycle interferences.
 */
// [DISABLED LEGACY INJECTION]
function zh_inject_price_and_stock($product, $data_store) {
    return;
}

/**
 * 2️⃣ FINAL PRICE HOOK (THIS WILL WORK)
 * Hook into WooCommerce's native variation save process
 */
// [DISABLED - MOVED TO SHUTDOWN HOOK]
// add_action(
//     'woocommerce_save_product_variation',
//     'zh_inject_variation_price_final',
//     99,
//     2
// );

function zh_inject_variation_price_final($variation_id, $i) {

    $parent_id = wp_get_post_parent_id($variation_id);
    if (!$parent_id) return;

    if (get_post_meta($parent_id, 'zh_pack_type', true) !== 'one') return;

    $size = get_post_meta($variation_id, 'attribute_pa_size', true);
    if (!$size) return;

    // SIMPLIFIED: Direct lookup from zh_box_prices
    $box_prices = get_post_meta($parent_id, 'zh_box_prices', true);
    $box_inventory = get_post_meta($parent_id, 'zh_box_inventory', true);

    // Get price from zh_box_prices (already slug-based)
    if (is_array($box_prices) && isset($box_prices[$size])) {
        $price = (float) $box_prices[$size];
        
        update_post_meta($variation_id, '_regular_price', $price);
        update_post_meta($variation_id, '_price', $price);
    }
    
    // Stock management
    update_post_meta($variation_id, '_manage_stock', 'yes');
    
    if (is_array($box_inventory)) {
        foreach ($box_inventory as $color => $sizes) {
            if (isset($sizes[$size])) {
                $stock = (int) $sizes[$size];
                update_post_meta($variation_id, '_stock', $stock);
                update_post_meta(
                    $variation_id,
                    '_stock_status',
                    $stock > 0 ? 'instock' : 'outofstock'
                );
                break;
            }
        }
    }
}

// [DISABLED LEGACY INJECTION]
// function zh_inject_price_and_stock($product, $data_store) { ... }

/* ======================================================
 * TEMPORARY MIGRATION SCRIPT (REMOVE LATER)
 * ====================================================== */
add_action('admin_init', function () {

    if (!current_user_can('manage_woocommerce')) return;

    // Trigger only when explicitly requested
    if (!isset($_GET['zh_migrate_sizes'])) return;

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;

    global $wpdb;

    // 1️⃣ Find broken variations
    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT pm.post_id, pm.meta_value
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = 'attribute_pa_size'
        AND pm.meta_value LIKE %s
        LIMIT %d
    ", '%/%', $limit));

    if (empty($rows)) {
        wp_die('ZeroHold: No broken variations found.');
    }

    foreach ($rows as $row) {

        $variation_id = (int) $row->post_id;
        $label        = $row->meta_value;

        // 2️⃣ Ensure term exists
        $term = get_term_by('name', $label, 'pa_size');

        if (!$term) {
            $created = wp_insert_term($label, 'pa_size', [
                'slug' => sanitize_title($label),
            ]);

            if (is_wp_error($created)) continue;

            $term = get_term($created['term_id'], 'pa_size');
        }

        if (!$term) continue;

        // 3️⃣ Update variation attribute to SLUG
        update_post_meta(
            $variation_id,
            'attribute_pa_size',
            $term->slug
        );
    }

    // 4️⃣ Sync parent products
    $parents = array_unique(array_map(function ($row) {
        return wp_get_post_parent_id($row->post_id);
    }, $rows));

    foreach ($parents as $parent_id) {
        if ($parent_id) {
            WC_Product_Variable::sync($parent_id);
            wc_delete_product_transients($parent_id);
        }
    }

    wp_die('ZeroHold: Size migration completed for ' . count($rows) . ' variations.');
});

/* ======================================================
 * 4️⃣ AUTHORITATIVE VARIATION CREATION
 * 
 * Called by Save Handler to CREATE variations if missing.
 * Sets initial shell (price/stock updated by shutdown hook).
 * ====================================================== */
function zh_create_or_update_variation_authoritative($parent_id, $size_slug, $price, $stock_qty) {
    
    // 1. Safety Checks (Robust)
    // Don't trust $parent->is_type() solely due to object caching cache during save
    if (!has_term('variable', 'product_type', $parent_id)) {
        // Enforce if missing
        wp_set_object_terms($parent_id, 'variable', 'product_type');
        wc_delete_product_transients($parent_id);
    }

    // 2. Find existing variation by size attribute (Direct DB Query to bypass cache)
    $args = [
        'post_type'   => 'product_variation',
        'post_parent' => $parent_id,
        'numberposts' => -1,
        'fields'      => 'ids',
    ];
    $children_ids = get_posts($args);

    $variation_id = 0;
    foreach ($children_ids as $child_id) {
        $child_size = get_post_meta($child_id, 'attribute_pa_size', true);
        if ($child_size === $size_slug) {
            $variation_id = $child_id;
            break;
        }
    }

    // 3. Create if missing
    if (!$variation_id) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($parent_id);
        $variation->set_attributes(['pa_size' => $size_slug]);
        $variation->set_status('publish');
        
        // 🔥 ZeroHold Fix: Assign Variation SKU on creation
        $sku_suffix = strtoupper(str_replace(['/', ' '], '-', $size_slug));
        $variation->set_sku("ZH-{$parent_id}-{$sku_suffix}");
        
        $variation_id = $variation->save();
        
        // Force attribute meta write immediately
        update_post_meta($variation_id, 'attribute_pa_size', $size_slug);
    }
    
    if (!$variation_id) return;

    // 4. Update Properties (Initial Set)
    // The shutdown hook is the ultimate authority, but setting here ensures
    // the variation is valid immediately upon creation.
    
    $obj = wc_get_product($variation_id);
    if (!$obj) return;
    
    $obj->set_regular_price($price);
    $obj->set_price($price);
    
    // 🔥 ZeroHold Fix: Enforce SKU pattern for existing/updated variations
    $sku_suffix = strtoupper(str_replace(['/', ' '], '-', $size_slug));
    $correct_sku = "ZH-{$parent_id}-{$sku_suffix}";
    if ($obj->get_sku() !== $correct_sku) {
        $obj->set_sku($correct_sku);
    }
    $obj->set_manage_stock(true);
    $obj->set_stock_quantity($stock_qty);
    $obj->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
    $obj->set_virtual(false);
    $obj->set_downloadable(false);
    
    // ✅ CONTRACT: Write Source of Truth BEFORE save trigger
    update_post_meta($variation_id, '_zh_box_price', $price); 
    update_post_meta($variation_id, '_zh_box_stock', $stock_qty); // ✅ STOCK CONTRACT
    
    $obj->save(); // Triggers hooks: 'zh_apply_variation_box_price' AND 'zh_apply_variation_box_stock'
    
    // Redundant but safe manual backup (optional)
    update_post_meta($variation_id, '_price', $price);
    update_post_meta($variation_id, '_regular_price', $price);
    update_post_meta($variation_id, '_stock', $stock_qty);
}

/* ======================================================
 * 5️⃣ FINAL NATIVE PRICE SYNC HOOK (SAFE)
 * 
 * Never fights WooCommerce lifecycle.
 * Writes price ONLY at variation-save hook.
 * Uses variation-owned meta as source.
 * ====================================================== */
add_action(
    'woocommerce_save_product_variation',
    'zh_apply_variation_box_price',
    99,
    2
);

function zh_apply_variation_box_price($variation_id, $index) {

    // Safety
    if (!defined('ZH_PRODUCT_CONTROL_ENABLED') || !ZH_PRODUCT_CONTROL_ENABLED) {
        return;
    }

    // ✅ FIX: Only run Forward Sync if saving from ZERO HOLD UI (prevents overwriting Admin edits)
    if (!isset($_POST['zh_pack_nonce'])) {
        return;
    }

    // ✅ LOOP GUARD
    if (!defined('ZH_FORWARD_PRICE_SYNC')) {
        define('ZH_FORWARD_PRICE_SYNC', true);
    }

    // Get ZeroHold box price (written by your UI/Save Logic)
    $box_price = get_post_meta($variation_id, '_zh_box_price', true);

    if ($box_price === '' || $box_price === null) {
        return;
    }

    $box_price = (float) $box_price;

    if ($box_price <= 0) {
        return;
    }

    // ✅ WooCommerce-native price write
    update_post_meta($variation_id, '_regular_price', $box_price);
    update_post_meta($variation_id, '_price', $box_price);

    // Stock safety (ensure managed)
    if (get_post_meta($variation_id, '_manage_stock', true) !== 'yes') {
        update_post_meta($variation_id, '_manage_stock', 'yes');
    }
}

/**
 * ==========================================
 * ZEROHOLD — FINAL VARIATION STOCK SYNC
 * ==========================================
 * WooCommerce-native
 * Dokan-safe
 * POS-safe
 */

add_action(
    'woocommerce_save_product_variation',
    'zh_apply_variation_box_stock',
    100,
    2
);

function zh_apply_variation_box_stock($variation_id, $index) {

    // Safety
    if (!defined('ZH_PRODUCT_CONTROL_ENABLED') || !ZH_PRODUCT_CONTROL_ENABLED) {
        return;
    }

    // ✅ FIX: Only run Forward Sync if saving from ZERO HOLD UI
    if (!isset($_POST['zh_pack_nonce'])) {
        return;
    }

    // Read ZeroHold box stock
    $box_stock = get_post_meta($variation_id, '_zh_box_stock', true);

    // If not set, do nothing
    if ($box_stock === '' || $box_stock === null) {
        return;
    }

    $box_stock = (int) $box_stock;
    if ($box_stock < 0) {
        $box_stock = 0;
    }

    // ✅ WooCommerce-native stock write
    update_post_meta($variation_id, '_manage_stock', 'yes');
    update_post_meta($variation_id, '_stock', $box_stock);
    update_post_meta(
        $variation_id,
        '_stock_status',
        $box_stock > 0 ? 'instock' : 'outofstock'
    );
}

/**
 * ==========================================
 * ZEROHOLD — SIMPLE PRODUCT PRICE SYNC
 * ==========================================
 * Handles "Mixed-Size" and "Free-Size" Box Prices.
 * Reads from zh_box_prices['default']['box_price'].
 * 
 * Target: _regular_price AND _price
 */
add_action(
    'woocommerce_after_product_object_save', 
    'zh_inject_simple_price_from_box_price', 
    20, 
    2
);

function zh_inject_simple_price_from_box_price($product, $data_store) {

    // Safety
    if (!defined('ZH_PRODUCT_CONTROL_ENABLED') || !ZH_PRODUCT_CONTROL_ENABLED) return;
    if (!$product instanceof WC_Product) return;

    // Filter: Only Simple Products
    if (!$product->is_type('simple')) return;

    // Filter: Only ZeroHold Mixed/Free packs
    // (Optional: we can rely on zh_box_prices existence, but checking pack type is safer)
    $pack_type = get_post_meta($product->get_id(), 'zh_pack_type', true);
    if ($pack_type === 'one') return; 

    // ✅ FIX: Only run Forward Sync if saving from ZERO HOLD UI (prevents overwriting Admin edits)
    if (!isset($_POST['zh_pack_nonce'])) {
        return;
    }

    // Read Contract Price
    $raw_prices = get_post_meta($product->get_id(), 'zh_box_prices', true);
    if (empty($raw_prices)) return;

    $prices = is_string($raw_prices) ? json_decode($raw_prices, true) : $raw_prices;

    // Check strict schema: ['default']['box_price']
    if (
        !is_array($prices) || 
        !isset($prices['default']) || 
        !is_array($prices['default']) || 
        empty($prices['default']['box_price'])
    ) {
        return;
    }

    $price = (float) $prices['default']['box_price'];

    if ($price <= 0) return;

    // ✅ LOOP GUARD: Flag that we are performing a Forward Sync
    if (!defined('ZH_FORWARD_PRICE_SYNC')) {
        define('ZH_FORWARD_PRICE_SYNC', true);
    }

    // ✅ Native Write (Prevent Loop)
    remove_action('woocommerce_after_product_object_save', 'zh_inject_simple_price_from_box_price', 20);

    $product->set_regular_price($price);
    $product->set_price($price);
    $product->save();

    add_action('woocommerce_after_product_object_save', 'zh_inject_simple_price_from_box_price', 20, 2);
}

/**
 * ==========================================
 * ZEROHOLD — REVERSE SYNC (Admin Edit)
 * ==========================================
 * When Admin edits Woo Price -> Update ZH
 */
add_action(
    'woocommerce_after_product_object_save',
    'zh_reverse_sync_simple_price_from_woo',
    30,
    2
);

function zh_reverse_sync_simple_price_from_woo($product, $data_store) {

    // -------- Guards --------

    if (defined('ZH_FORWARD_PRICE_SYNC')) {
        return; // skip ZH → Woo injections
    }

    if (defined('ZH_REVERSE_PRICE_SYNC')) {
        return; // prevent recursion
    }

    if (!$product instanceof WC_Product) {
        return;
    }

    // ONLY simple products
    if (!$product->is_type('simple')) {
        return;
    }

    // ONLY admins (Safety against vendor edits messing up ZH authoritative data)
    if (!is_admin() || !current_user_can('manage_woocommerce')) {
        return;
    }

    $product_id = $product->get_id();

    // -------- Read Woo price --------
    $woo_price = $product->get_regular_price();
    
    // Allow '0' but not empty string, validation primarily for non-numeric
    if ($woo_price === '' || !is_numeric($woo_price)) {
        return;
    }

    $woo_price = (float) $woo_price;

    if ($woo_price <= 0) {
        return;
    }

    // -------- Read existing ZH price (for comparison) --------

    $zh_box_prices_raw = get_post_meta($product_id, 'zh_box_prices', true);
    $zh_box_prices = [];

    if (!empty($zh_box_prices_raw)) {
        $decoded = is_string($zh_box_prices_raw) ? json_decode($zh_box_prices_raw, true) : $zh_box_prices_raw;
        if (is_array($decoded)) {
            $zh_box_prices = $decoded;
        }
    }

    $current_zh_price = isset($zh_box_prices['default']['box_price']) ? (float) $zh_box_prices['default']['box_price'] : null;

    // If same price, do nothing
    if ($current_zh_price === $woo_price) {
        return;
    }

    // -------- Write back to ZH --------

    if (!defined('ZH_REVERSE_PRICE_SYNC')) {
        define('ZH_REVERSE_PRICE_SYNC', true);
    }

    // Ensure structure
    if (!isset($zh_box_prices['default']) || !is_array($zh_box_prices['default'])) {
        $zh_box_prices['default'] = [];
    }
    
    $zh_box_prices['default']['box_price'] = $woo_price;

    update_post_meta(
        $product_id,
        'zh_box_prices',
        wp_json_encode($zh_box_prices)
    );
}

/**
 * ==========================================
 * ZEROHOLD — REVERSE STOCK SYNC (Admin)
 * ==========================================
 * When Admin edits Woo Stock -> Update ZH Inventory
 * Strategy: Update the FIRST color key to match the new total.
 */
add_action(
    'woocommerce_after_product_object_save',
    'zh_reverse_sync_simple_stock_from_woo',
    35,
    2
);

function zh_reverse_sync_simple_stock_from_woo($product, $data_store) {

    // Guards
    if (!$product instanceof WC_Product) return;
    if (!$product->is_type('simple')) return;
    if (!is_admin() || !current_user_can('manage_woocommerce')) return;

    // Skip if Saving from ZH UI (Forward Sync takes precedence)
    if (isset($_POST['zh_pack_nonce'])) return;

    // Check if Stock Management is ON
    if (!$product->get_manage_stock()) return;

    $woo_stock = (int) $product->get_stock_quantity();

    // Read ZH Inventory
    $zh_inv_raw = get_post_meta($product->get_id(), 'zh_box_inventory', true);
    $zh_inv = [];

    if (!empty($zh_inv_raw)) {
        $zh_inv = is_string($zh_inv_raw) ? json_decode($zh_inv_raw, true) : $zh_inv_raw;
    }

    if (!is_array($zh_inv)) {
        $zh_inv = [];
    }

    // Calculate current ZH Total
    $current_zh_total = 0;
    foreach ($zh_inv as $qty) {
        $current_zh_total += (int) $qty;
    }

    // No change?
    if ($current_zh_total === $woo_stock) return;

    // Logic: Distribute difference to FIRST key
    $diff = $woo_stock - $current_zh_total;
    
    // Get first key
    $keys = array_keys($zh_inv);
    $first_key = reset($keys);

    if ($first_key) {
        $zh_inv[$first_key] = (int) $zh_inv[$first_key] + $diff;
        if ($zh_inv[$first_key] < 0) $zh_inv[$first_key] = 0;
    } else {
        // No keys exists, create default
        $zh_inv['default'] = $woo_stock;
    }

    // Save back to ZH
    update_post_meta($product->get_id(), 'zh_box_inventory', $zh_inv);
}

/**
 * ==========================================
 * STEP-V2-A — Variant PRICE Reverse Sync (UNIFIED)
 * ==========================================
 * Woo Admin → ZeroHold · Variable Product · Price Only
 * Mirrors the Simple Product method for maximum reliability.
 */
add_action(
    'woocommerce_after_product_object_save',
    'zh_reverse_sync_variant_price_from_woo',
    40,
    2
);

function zh_reverse_sync_variant_price_from_woo($product, $data_store) {

    // 1. Guards
    // Track processed IDs to allow multiple variations but prevent infinite loops
    static $processed_variations = [];
    $variation_id = $product->get_id();
    
    if (isset($processed_variations[$variation_id])) {
        return;
    }

    if (defined('ZH_FORWARD_PRICE_SYNC')) {
        return;
    }

    if (!$product instanceof WC_Product_Variation) {
        return;
    }

    // Admin only
    if (!is_admin() || !current_user_can('manage_woocommerce')) {
        return;
    }

    $parent_id = $product->get_parent_id();
    if (!$parent_id) {
        return;
    }

    // 2. Pack Type Guard (One-Size Box / Variable only)
    $pack_type = get_post_meta($parent_id, 'zh_pack_type', true);
    if ($pack_type !== 'one') {
        return;
    }

    // 3. Read Woo price (Authoritative after save)
    $price = $product->get_regular_price();
    if ($price === '' || !is_numeric($price)) {
        return;
    }
    $price = (float) $price;
    if ($price <= 0) {
        return;
    }

    // 4. Detect Size Slug (Matches keys in DB screenshot: "xs-36", "s-38")
    $size_slug = get_post_meta($variation_id, 'attribute_pa_size', true);
    if (empty($size_slug)) {
        $size_slug = $product->get_attribute('pa_size');
    }
    if (empty($size_slug)) {
        return;
    }
    
    // Standardize to slug (e.g. "S/38" -> "s-38")
    $size_key = sanitize_title($size_slug);

    // 5. Read existing ZH prices
    $raw = get_post_meta($parent_id, 'zh_box_prices', true);
    $zh_box_prices = [];
    if (!empty($raw)) {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (is_array($decoded)) {
            $zh_box_prices = $decoded;
        }
    }

    // 6. Equality Guard (Compare as floats)
    $current_price = isset($zh_box_prices[$size_key]) ? (float) $zh_box_prices[$size_key] : null;
    if ($current_price !== null && abs($current_price - $price) < 0.001) {
        return;
    }

    // 7. Write Back to ZH
    $processed_variations[$variation_id] = true;

    // Update Parent Array (FLAT Schema: slug => "price.00")
    $zh_box_prices[$size_key] = (string) number_format($price, 2, '.', '');
    update_post_meta($parent_id, 'zh_box_prices', wp_json_encode($zh_box_prices));

    // Update Variation Contract Meta
    update_post_meta($variation_id, '_zh_box_price', $price);
}

/**
 * ==========================================
 * STEP-V2-B — Stable Variant STOCK Reverse Sync
 * ==========================================
 * Woo Admin → ZeroHold · Variable Product · Stock Only
 * Confirmed Real Model: Triggered on stock set.
 */
add_action(
    'woocommerce_product_set_stock',
    'zh_reverse_sync_variant_stock_to_zh',
    20,
    1
);

function zh_reverse_sync_variant_stock_to_zh( $product ) {

    // 1. Guards
    static $processed_stock = [];
    $variation_id = $product->get_id();

    if ( isset($processed_stock[$variation_id]) ) {
        return;
    }

    if ( defined('ZH_FORWARD_STOCK_SYNC') ) {
        return;
    }

    // Only variations
    if ( ! $product instanceof WC_Product_Variation ) {
        return;
    }

    // Admin only safety
    if ( ! is_admin() || ! current_user_can('manage_woocommerce') ) {
        return;
    }

    $parent_id = $product->get_parent_id();
    if ( ! $parent_id ) {
        return;
    }

    // 2. Identify Size Label (Matches keys in zh_box_inventory)
    $size = $product->get_attribute('pa_size');
    if ( empty($size) ) {
        return;
    }

    // 3. Read Updated Woo Stock (Boxes)
    $stock = $product->get_stock_quantity();
    if ( ! is_numeric($stock) ) {
        return;
    }

    // 4. Read ZH Inventory (JSON)
    $raw = get_post_meta( $parent_id, 'zh_box_inventory', true );
    $inv = [];
    if ( ! empty($raw) ) {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if ( is_array($decoded) ) {
            $inv = $decoded;
        }
    }

    // 5. Find Color (First-level key)
    $colors = array_keys($inv);
    $color  = $colors[0] ?? 'default';
    if ( ! isset($inv[$color]) ) {
        $inv[$color] = [];
    }

    // 6. Equality Guard
    if ( isset($inv[$color][$size]) && (int)$inv[$color][$size] === (int)$stock ) {
        return;
    }

    // 7. Write Back to ZH
    $processed_stock[$variation_id] = true;

    $inv[$color][$size] = (int) $stock;
    update_post_meta($parent_id, 'zh_box_inventory', $inv);

    // Update Variation Contract Meta
    update_post_meta($variation_id, '_zh_box_stock', (int)$stock);
}
