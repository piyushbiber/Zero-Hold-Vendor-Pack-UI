<?php
if (!defined('ABSPATH')) exit;

/**
 * Robust Save Handler for Zero Hold Pack UI
 * Handles Post Meta, Taxonomy assignments, and Automated Pricing.
 */

add_action('save_post_product', 'zh_enforce_product_type_on_save', 5);
// [DISABLE LEGACY] add_action('save_post_product', 'zh_normalize_product_attributes_on_save', 10, 2);
// [DISABLE LEGACY] add_action('save_post_product', 'zh_auto_create_variations_structure', 25, 2);
add_action('save_post_product', 'zh_save_pack_and_taxonomies', 20, 2);

// STEP A — PRICE META FORENSIC LOGGER
add_action('updated_post_meta', function ($meta_id, $post_id, $meta_key, $meta_value) {
    if (strpos($meta_key, '_price') !== false) {
        error_log("ZH DEBUG: PRICE UPDATED | post={$post_id} | {$meta_key}={$meta_value}");
    }
}, 10, 4);

add_action('deleted_post_meta', function ($meta_id, $post_id, $meta_key, $meta_value) {
    if (strpos($meta_key, '_price') !== false) {
        error_log("ZH DEBUG: PRICE DELETED | post={$post_id} | {$meta_key}");
    }
}, 10, 4);

// STEP B — FORCE VARIATION VALIDITY (NO STOCK QTY)
add_action('woocommerce_after_product_object_save', function ($product) {

    if (!$product || !$product->is_type('variable')) return;

    foreach ($product->get_children() as $variation_id) {
        update_post_meta($variation_id, '_manage_stock', 'yes');
        update_post_meta($variation_id, '_stock_status', 'instock');
        update_post_meta($variation_id, '_virtual', 'no');
        update_post_meta($variation_id, '_downloadable', 'no');
    }

}, 5);

// STEP C — DIRECT PRICE WRITE (TEST ONLY)
// [DISABLE LEGACY]
/*
add_action('dokan_process_product_meta', function ($product_id) {

    $product = wc_get_product($product_id);
    if (!$product || !$product->is_type('variable')) return;

    $box_content = get_post_meta($product_id, 'zh_box_content', true);
    if (is_string($box_content)) {
        $box_content = json_decode($box_content, true);
    }
    if (!is_array($box_content)) return;

    foreach ($product->get_children() as $variation_id) {

        $variation = wc_get_product($variation_id);
        if (!$variation) continue;

        $size = $variation->get_attribute('pa_size');
        if (empty($box_content[$size]['base_price'])) continue;

        $price = $box_content[$size]['base_price'];

        update_post_meta($variation_id, '_regular_price', $price);
        update_post_meta($variation_id, '_price', $price);
    }

}, 999);
*/

/**
 * STEP 2 — Normalize product attributes
 * Ensures Size is the ONLY variation axis for variable products.
 */
function zh_normalize_product_attributes_on_save($product_id, $post) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($product_id)) return;
    if (get_post_type($product_id) !== 'product') return;

    $product = wc_get_product($product_id);
    if (!$product || !$product->is_type('variable')) {
        return;
    }

    $attributes = get_post_meta($product_id, '_product_attributes', true);
    if (!is_array($attributes)) return;

    $changed = false;
    foreach ($attributes as $key => &$attr) {
        if (empty($attr['is_taxonomy'])) continue;

        if ($attr['name'] === 'pa_size') {
            if ($attr['is_variation'] != 1) {
                $attr['is_variation'] = 1;
                $attr['is_visible']   = 1;
                $changed = true;
            }
        } else {
            if ($attr['is_variation'] != 0) {
                $attr['is_variation'] = 0;
                $changed = true;
            }
        }
    }

    if ($changed) {
        update_post_meta($product_id, '_product_attributes', $attributes);
        // Clear transients to reflect change
        wc_delete_product_transients($product_id);
    }
}

/**
 * STEP 3 — Auto-create size-based variations
 * Creates the structure only (no price, no stock).
 */
function zh_auto_create_variations_structure($product_id, $post) {

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($product_id)) return;

    $product = wc_get_product($product_id);
    if (!$product || !$product->is_type('variable')) return;

    // Read ZH selected sizes
    $sizes_raw = get_post_meta($product_id, 'zh_selected_sizes', true);
    if (empty($sizes_raw)) return;

    // Normalize sizes array
    $sizes = is_string($sizes_raw) ? json_decode($sizes_raw, true) : $sizes_raw;
    if (!is_array($sizes)) return;

    // Get existing variations by size
    $existing = [];
    foreach ($product->get_children() as $child_id) {
        $v = wc_get_product($child_id);
        if (!$v) continue;

        $size = $v->get_attribute('pa_size');
        if ($size) {
            $existing[$size] = $child_id;
        }
    }

    // Create missing variations
    foreach ($sizes as $size) {
        if (isset($existing[$size])) {
            continue; // already exists
        }

        $variation_id = wp_insert_post([
            'post_title'  => $product->get_name() . ' - ' . $size,
            'post_name'   => 'product-' . $product_id . '-size-' . sanitize_title($size),
            'post_status' => 'publish',
            'post_parent' => $product_id,
            'post_type'   => 'product_variation',
            'menu_order'  => 0
        ]);

        if (is_wp_error($variation_id)) {
            continue;
        }

        // Assign size attribute
        update_post_meta($variation_id, 'attribute_pa_size', $size);
    }
    
    // Refresh product to ensure children list is updated for the next hook
    wc_delete_product_transients($product_id);
}


/**
 * HARD LOCK Woo product_type based on Zero Hold pack logic
 * This must run BEFORE any stock or variation logic.
 */
function zh_enforce_product_type_on_save($product_id) {

    // Safety
    if (wp_is_post_autosave($product_id) || wp_is_post_revision($product_id)) {
        return;
    }

    // Only products
    if (get_post_type($product_id) !== 'product') {
        return;
    }

    // Read Zero Hold decision input
    // Prioritize fresh POST data, fallback to saved meta
    $pack_type = isset($_POST['zh_pack_type']) ? sanitize_text_field($_POST['zh_pack_type']) : get_post_meta($product_id, 'zh_pack_type', true);

    if (!$pack_type) {
        return; // Do nothing if Zero Hold pack type is not defined
    }

    // Decide Woo product_type
    if ($pack_type === 'one') {
        // 🔒 FORCE VARIABLE
        wp_set_object_terms($product_id, 'variable', 'product_type');
    } else {
        // 🔒 FORCE SIMPLE (mixed / free size)
        wp_set_object_terms($product_id, 'simple', 'product_type');
    }
    
    // Clear product transients to ensure change reflects
    wc_delete_product_transients($product_id);
}

function zh_calculate_box_price_from_content( $product_id ) {
    $box_content_raw = get_post_meta( $product_id, 'zh_box_content', true );
    if ( empty($box_content_raw) ) {
        return 0;
    }

    $box_content = json_decode($box_content_raw, true);
    if ( !is_array($box_content) ) {
        return 0;
    }

    // Get pack type to determine calculation method
    $pack_type = get_post_meta( $product_id, 'zh_pack_type', true );

    $box_price = 0;

    // CASE 1: One-Size Box - Calculate from ONLY the first row
    if ( $pack_type === 'one' ) {
        // Get the first row only
        $first_row = reset($box_content);
        if ( $first_row !== false ) {
            $pcs  = isset($first_row['pcs']) ? (int) $first_row['pcs'] : 0;
            $base = isset($first_row['base_price']) ? (float) $first_row['base_price'] : 0;
            $box_price = $pcs * $base;
        }
    }
    // CASE 2: Mixed-Size Box - Sum ALL rows
    else if ( $pack_type === 'mixed' ) {
        foreach ( $box_content as $size => $data ) {
            $pcs  = isset($data['pcs']) ? (int) $data['pcs'] : 0;
            $base = isset($data['base_price']) ? (float) $data['base_price'] : 0;
            $box_price += ($pcs * $base);
        }
    }

    return round($box_price, 2);
}

/**
 * Sync WooCommerce price with calculated box price
 * Following Zero Hold Price Rules:
 * 1. Regular Price = Box Calculation (conditional on Pack Type)
 *    - One-Size Box: First row only (PCS × Base Price)
 *    - Mixed-Size Box: Sum of all rows
 * 2. Sale Price = Allowed (set by vendor)
 */
function zh_sync_wc_box_price( $product_id ) {
    $box_price = zh_calculate_box_price_from_content( $product_id );
    if ( $box_price <= 0 ) {
        return;
    }

    // Force Regular Price
    update_post_meta( $product_id, '_regular_price', $box_price );

    // Determine current active price (_price)
    $sale_price = get_post_meta( $product_id, '_sale_price', true );
    $current_price = $box_price;

    if ( $sale_price !== '' && floatval($sale_price) < $box_price ) {
        $current_price = $sale_price;
    }

    update_post_meta( $product_id, '_price', $current_price );
}

function zh_save_pack_and_taxonomies($post_id, $post) {

    // 1. Safety Checks
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($post->post_type) || $post->post_type !== 'product') return;
    if (!current_user_can('edit_post', $post_id)) return;

    // 2. Nonce Validation
    if (!isset($_POST['zh_pack_nonce']) || !wp_verify_nonce($_POST['zh_pack_nonce'], 'zh_pack_save')) {
        return;
    }

    // 🔒 HARD ISOLATION GUARD
    $product = wc_get_product($post_id);
    if (!$product) {
        return;
    }

    // 3. Save Core Meta Fields
    
    // Pack Type
    if (isset($_POST['zh_pack_type'])) {
        $allowed = ['one', 'mixed', 'free'];
        $pack_type = sanitize_text_field($_POST['zh_pack_type']);
        if (in_array($pack_type, $allowed, true)) {
            update_post_meta($post_id, 'zh_pack_type', $pack_type);
        }
    }

    // Wear Type
    if (isset($_POST['zh_wear_type'])) {
        update_post_meta($post_id, 'zh_wear_type', sanitize_text_field($_POST['zh_wear_type']));
    }

    // Selected Sizes (JSON)
    if (isset($_POST['zh_selected_sizes'])) {
        update_post_meta($post_id, 'zh_selected_sizes', wp_unslash($_POST['zh_selected_sizes']));
    }

    // Box Content, Inventory, Dimensions (JSON fields)
    $json_fields = [
        'zh_box_content',
        'zh_box_inventory',
        'zh_box_dimensions',
        'zh_box_prices',  // NEW: Box prices for One-Size Box variations
    ];

    foreach ($json_fields as $field) {
        if (isset($_POST[$field])) {
            update_post_meta(
                $post_id,
                $field,
                wp_unslash($_POST[$field]) // Already JSON strings from UI
            );
        }
    }

    // 4. PRICE & ISOLATION GUARDS
    if ($product->is_type('simple')) {
        // ✅ ONLY simple-product price sync
        // [DISABLE LEGACY] zh_sync_wc_box_price($post_id);
    } 
    
    if ($product->is_type('variable')) {
        // ✅ ONLY variable-product price sync (Parent price for display)
        // [DISABLE LEGACY] zh_sync_wc_box_price($post_id);
    }

    // Box Weight
    if (isset($_POST['zh_box_weight'])) {
        update_post_meta($post_id, 'zh_box_weight', floatval($_POST['zh_box_weight']));
    }

    // 4. Build Box Composition (Deadstock Canonical) & Store Pieces Info
    $pack_type_saved   = get_post_meta($post_id, 'zh_pack_type', true);
    $wear_type_saved   = get_post_meta($post_id, 'zh_wear_type', true);
    $box_content_saved = get_post_meta($post_id, 'zh_box_content', true);

    if ($pack_type_saved && $wear_type_saved && $box_content_saved) {
        $box_content = json_decode($box_content_saved, true);
        if (is_array($box_content)) {
            $composition = [
                'box_type'     => $pack_type_saved,
                'wear_type'    => $wear_type_saved,
                'sizes'        => [],
                'total_pieces' => 0,
            ];

            // Correctly handle nested Color-Keyed JSON structure
            foreach ($box_content as $color => $sizes) {
                if (!is_array($sizes)) continue;
                foreach ($sizes as $size => $row) {
                    if (!empty($row['pcs'])) {
                        $pcs = (int) $row['pcs'];
                        $composition['sizes'][$size] = $pcs;
                        $composition['total_pieces'] += $pcs;
                    }
                }
            }

            if (!empty($composition['sizes'])) {
                update_post_meta($post_id, 'zh_box_composition', wp_json_encode($composition));
            }

            // Save Pieces Per Box for frontend display
            // Mixed-Size/Free-Size: Sum of all pieces. One-Size: Pieces in a single box (take first).
            $display_pcs = $composition['total_pieces'];
            if ($pack_type_saved === 'one' && !empty($composition['sizes'])) {
                $display_pcs = reset($composition['sizes']);
            }
            update_post_meta($post_id, 'zh_pieces_per_box', $display_pcs);
        }
    }

    // 5. Save Product Taxonomies - VENDOR ATTRIBUTES (Internal System Only)
    // 
    // IMPORTANT: These zh_* taxonomies are saved for VENDOR USE ONLY.
    // They are NOT synced to WooCommerce buyer attributes.
    // 
    // VENDOR-ONLY TAXONOMIES (not exposed to buyers):
    // - zh_neck: Vendor operational detail (not a buyer filter)
    // - zh_pattern: Vendor operational detail (not a buyer filter)
    // - zh_generic_name: Internal vendor classification
    // - zh_gst: Tax data for Zero Hold operations
    // - zh_hsn: Compliance code for Zero Hold operations
    // 
    // Note: zh_color, zh_fabric, zh_fit_shape ARE synced to WooCommerce
    // in the zh_map_vendor_ui_to_woo_attributes() function below.
    
    $taxonomy_map = [
        'zh_color'         => 'zh_color',
        'zh_fabric'        => 'zh_fabric',
        'zh_fit_shape'     => 'zh_fit_shape',
        'zh_neck'          => 'zh_neck',              // VENDOR-ONLY
        'zh_pattern'       => 'zh_pattern',           // VENDOR-ONLY
        'zh_generic_name'  => 'zh_generic_name',     // VENDOR-ONLY
        'zh_gst'           => 'zh_gst',               // VENDOR-ONLY
        'zh_hsn'           => 'zh_hsn',               // VENDOR-ONLY
    ];

    foreach ($taxonomy_map as $field => $taxonomy) {
        if (empty($_POST[$field])) {
            continue;
        }

        $term_ids = array_map('intval', (array) $_POST[$field]);

        wp_set_object_terms(
            $post_id,
            $term_ids,
            $taxonomy,
            false // Replace existing terms
        );
    }

    /**
     * 4.x Store Wear For (Gender) — System Canonical (DB)
     * Used for Deadstock, Box Labels, Ops
     */
    if (isset($_POST['zh_wear_for']) && $_POST['zh_wear_for'] !== '') {
        $wear_for = sanitize_text_field($_POST['zh_wear_for']);

        // Alignment: Resolve Name if ID was sent by UI dropdown
        if ( is_numeric($wear_for) ) {
            $term = get_term(intval($wear_for), 'pa_wear-for');
            if ($term && !is_wp_error($term)) {
                $wear_for = $term->name;
            }
        }

        $allowed = ['Infant', 'Kids', 'Men', 'Women', 'Unisex']; // Note: 'Infant' is displayed as 'Kids' in UI
        if (in_array($wear_for, $allowed, true)) {
            update_post_meta($post_id, 'zh_wear_for', $wear_for);
        }
    }

    // 5.x Normalize Buyer Attributes (Architect Pattern)
    zh_map_vendor_ui_to_woo_attributes($post_id, $_POST);

    // 🔒 6. AUTHORITATIVE VARIATION SYNC (FINAL TRUTH)
    // Only for One-Size Box (Variable Product)
    if ($pack_type_saved === 'one' && !empty($composition['sizes']) && function_exists('zh_create_or_update_variation_authoritative')) {
        
        // Retrieve fresh box prices from POST
        $box_prices_raw = isset($_POST['zh_box_prices']) ? wp_unslash($_POST['zh_box_prices']) : '{}';
        $box_prices = json_decode($box_prices_raw, true);
        
        $base_price = isset($_POST['zh_base_price']) ? floatval($_POST['zh_base_price']) : 0;
        
        foreach ($composition['sizes'] as $size_label => $pcs) {
             
             // Convert Label (XS/36) to Slug (xs-36)
             $size_slug = sanitize_title($size_label);
             
             // Calculate Box Price: Priority = Direct Input > Computed
             $box_price = 0;
             if (isset($box_prices[$size_slug])) {
                 $box_price = (float) $box_prices[$size_slug];
             } else {
                 // Fallback calculation
                 $box_price = round($pcs * $base_price, 2);
             }
             
             // Get Stock from zh_box_inventory
             // Get Stock from zh_box_inventory
             $stock_qty = 0;
             // Prioritize FRESH POST data + JSON Decode
             $inv_raw = isset($_POST['zh_box_inventory']) ? wp_unslash($_POST['zh_box_inventory']) : get_post_meta($post_id, 'zh_box_inventory', true);
             
             $inventory_data = is_string($inv_raw) ? json_decode($inv_raw, true) : $inv_raw;

             if (is_array($inventory_data)) {
                 foreach ($inventory_data as $color => $inv_sizes) {
                     // JS saves inventory keyed by LABEL (e.g. "S/36")
                     if (isset($inv_sizes[$size_label])) {
                         $stock_qty = (int) $inv_sizes[$size_label];
                         break; 
                     }
                 }
             }

             // 🔥 FIRE AUTHORITATIVE CREATE/UPDATE
             zh_create_or_update_variation_authoritative(
                 $post_id,
                 $size_slug,
                 $box_price,
                 $stock_qty
             );
        }

        // Final Sync to calculate Min/Max prices for parent
        WC_Product_Variable::sync($post_id);
        wc_delete_product_transients($post_id);
    }

    // 7. Box Dimension & Weight Sync (Mirror Zero Hold → WooCommerce)
    zh_sync_box_dimensions_to_woo($post_id);

    // 8. Schema Versioning
    update_post_meta($post_id, 'zh_box_schema_version', 1);
}

/**
 * Senior Architect Pattern: Map Vendor UI to WooCommerce Attributes
 * 
 * DESIGN PHILOSOPHY:
 * ─────────────────────────────────────────────────────────────────────
 * This function creates a clean separation between:
 * 
 * 1. VENDOR ATTRIBUTES (zh_* taxonomies) - Internal Zero Hold system
 *    - zh_color, zh_fabric, zh_fit_shape, zh_neck, zh_pattern
 *    - zh_generic_name, zh_gst, zh_hsn
 *    - Purpose: Vendor operations, packing, compliance
 *    - NOT exposed to buyers
 * 
 * 2. BUYER ATTRIBUTES (pa_* taxonomies) - WooCommerce marketplace
 *    - pa_color ← zh_color (filter for buyers)
 *    - pa_fabric ← zh_fabric (filter for buyers)
 *    - pa_fit-shape ← zh_fit_shape (filter for buyers)
 *    - pa_wear-for (Gender: Kids/Men/Women/Unisex)
 *    - pa_wear-type (Type: Topwear/Bottomwear/Set/Free Size)
 *    - pa_size (dynamically created from vendor pack setup)
 * 
 * INTENTIONALLY NOT SYNCED TO WOOCOMMERCE:
 * - Neck, Pattern, Generic Name, GST, HSN are vendor-only
 *   (not relevant to buyer shopping experience)
 * ─────────────────────────────────────────────────────────────────────
 */
function zh_map_vendor_ui_to_woo_attributes($product_id, $data) {
    if (get_post_type($product_id) !== 'product') {
        return;
    }

    // 1. Get Fresh Pack Type (Prioritize POST data during save)
    $pack_type = isset($data['zh_pack_type']) ? sanitize_text_field($data['zh_pack_type']) : get_post_meta($product_id, 'zh_pack_type', true);
    
    // Normalize values just in case
    if ($pack_type === 'mixed_size_box') $pack_type = 'mixed';
    if ($pack_type === 'one_size_box')   $pack_type = 'one';
    if ($pack_type === 'free_size_box')  $pack_type = 'freesize';

    // 2. Discover all registered WooCommerce attributes
    $wc_attributes = wc_get_attribute_taxonomies();
    $final_product_attributes = [];
    $position = 0;

    foreach ($wc_attributes as $attr_obj) {
        $attr_name = $attr_obj->attribute_name; // e.g. 'color', 'size'
        $pa_slug   = 'pa_' . $attr_name;
        $terms     = [];

        /**
         * A. SOURCE DATA MAPPING
         * We pull terms from Zero Hold internal data based on the attribute slug.
         */

        if ($attr_name === 'color') {
            $terms = wp_get_object_terms($product_id, 'zh_color', ['fields' => 'names']);
        } 
        elseif ($attr_name === 'size') {
            // Priority 1: Free Size Type
            if ($pack_type === 'freesize' || $pack_type === 'free') {
                $terms = ['Free Size'];
            } else {
                // Priority 2: Selected Sizes from POST (Fresh) or Meta (Stored)
                $selected_raw = isset($data['zh_selected_sizes']) ? wp_unslash($data['zh_selected_sizes']) : get_post_meta($product_id, 'zh_selected_sizes', true);
                if ($selected_raw) {
                    $decoded = json_decode($selected_raw, true);
                    if (is_array($decoded)) {
                        $terms = $decoded;
                    }
                }
            }
        }
        elseif ($attr_name === 'pack-type' || $attr_name === 'pack_type') {
            $pt_map = ['one' => 'One-Size Box', 'mixed' => 'Mixed-Size Box', 'freesize' => 'Free-Size Box'];
            if (isset($pt_map[$pack_type])) {
                $terms = [$pt_map[$pack_type]];
            }
        }
        elseif ($attr_name === 'wear-for') {
            $wf = isset($data['zh_wear_for']) ? sanitize_text_field($data['zh_wear_for']) : get_post_meta($product_id, 'zh_wear_for', true);
            if ($wf) {
                // Resolve ID to Name if needed (Dokan sometimes sends ID)
                if (is_numeric($wf)) {
                    $term = get_term(intval($wf), 'pa_wear-for');
                    if ($term && !is_wp_error($term)) $wf = $term->name;
                }
                $terms = ($wf === 'Unisex') ? ['Men', 'Women', 'Unisex'] : [$wf];
            }
        }
        elseif ($attr_name === 'wear-type') {
            $wt = isset($data['zh_wear_type']) ? sanitize_text_field($data['zh_wear_type']) : get_post_meta($product_id, 'zh_wear_type', true);
            $wt_map = [
                'topwear'    => 'Topwear (T-shirts / Kurtas)',
                'bottomwear' => 'Bottomwear (Jeans / Pants)',
                'set'        => 'Set / Combo (Kurta Set)',
                'freesize'   => 'Free Size (Saree / Dupatta)',
            ];
            if ($wt && isset($wt_map[$wt])) {
                $terms = [$wt_map[$wt]];
            }
        }
        else {
            /**
             * DYNAMIC DISCOVERY FALLBACK
             * Maps pa_neck -> zh_neck, pa_fit-shape -> zh_fit_shape
             */
            $zh_key = 'zh_' . str_replace('-', '_', $attr_name);
            $terms = wp_get_object_terms($product_id, $zh_key, ['fields' => 'names']);
        }

        if (is_wp_error($terms)) $terms = [];

        /**
         * B. SYNC TERMS AND CONSTRUCT METADATA
         */
        if (!empty($terms)) {
            // 1. Force term assignment (ensures frontend filters work)
            wp_set_object_terms($product_id, $terms, $pa_slug, false);

            // 2. Identify if this attribute should used for variations
            // Rule: ONLY 'size' and ONLY for 'One-Size Box'.
            $is_variation = ($attr_name === 'size' && $pack_type === 'one') ? 1 : 0;

            // 3. Build the WooCommerce attribute entry
            $final_product_attributes[$pa_slug] = [
                'name'         => $pa_slug,
                'value'        => '',
                'position'     => $position++,
                'is_visible'   => 1,
                'is_taxonomy'  => 1,
                'is_variation' => $is_variation,
            ];
        } else {
            // Clear existing terms if no value currently selected
            wp_set_object_terms($product_id, [], $pa_slug, false);
        }
    }

    // 3. Sync Dynamic Product Type (Variable vs Simple)
    update_post_meta($product_id, '_product_attributes', $final_product_attributes);
}


add_filter('woocommerce_admin_process_product_object', function ($product) {
    // [DISABLE LEGACY] Replaced by zh-product-lifecycle.php
    /*
    if (!$product) return $product;
    
    $post_id = $product->get_id();
    if (!$post_id) return $product;

    // 🔒 HARD LOCK: variable parent must NEVER manage stock
    if ($product->is_type('variable')) {
        $product->set_manage_stock(false);
        $product->set_stock_quantity(null);
    }

    // Re-read the latest calculated regular price
    $box_price = zh_calculate_box_price_from_content($post_id);
    
    if ($box_price > 0) {
        $product->set_regular_price($box_price);
        
        // Re-evaluate active price to respect potential sales
        $sale_price = $product->get_sale_price();
        if ($sale_price !== '' && floatval($sale_price) < $box_price) {
             $product->set_price($sale_price);
        } else {
             $product->set_price($box_price);
        }
    }
    */

    return $product;
}, 999);

/**
 * Validate discount price (must be < box price)
 */
add_action('woocommerce_process_product_meta', function ($post_id) {
    $regular = floatval(get_post_meta($post_id, '_regular_price', true));
    $sale    = get_post_meta($post_id, '_sale_price', true);

    if ( $sale !== '' ) {
        $sale_val = floatval($sale);
        if ($sale_val >= $regular) {
            // Invalid discount (greater than or equal to box price) → remove it
            delete_post_meta($post_id, '_sale_price');
            delete_post_meta($post_id, '_sale_price_dates_from');
            delete_post_meta($post_id, '_sale_price_dates_to');
            
            // Re-sync final price to regular
            update_post_meta($post_id, '_price', $regular);
        }
    }
});

/**
 * Show help message under price fields in Admin and Vendor dashboard
 */
function zh_price_calculated_help_message() {
    echo '<p class="description" style="margin-top:6px; color: #666; font-size: 11px;">
        Price is <strong>auto-calculated</strong> during box creation based on PCS × Base Price.
        Manual price entry is disabled.
    </p>';
}

add_action('woocommerce_product_options_pricing', 'zh_price_calculated_help_message');
add_action('dokan_product_edit_after_pricing', 'zh_price_calculated_help_message');

add_filter('update_post_metadata', function ($check, $post_id, $meta_key, $meta_value, $prev_value) {
    /**
     * 🔒 RULE: Variable parent must NEVER manage stock.
     * This protects against Dokan, Admin UI, or REST forcing parent stock.
     */
    if (in_array($meta_key, ['_manage_stock', '_stock'], true)) {
        $product = wc_get_product($post_id);
        if ($product && $product->is_type('variable')) {
            // 🚫 BLOCK turning stock management ON for variable parent
            if ($meta_key === '_manage_stock' && $meta_value === 'yes') {
                return false; 
            }
            // 🚫 BLOCK setting a quantity for variable parent
            if ($meta_key === '_stock' && $meta_value !== '' && $meta_value !== null) {
                return false;
            }
            // ✅ ALLOW 'no' or empty values (this allows us to correct the parent state)
        }
        return $check; 
    }

    // 📦 Existing hard block for Dokan dimension overrides
    if ( function_exists('dokan_is_seller_dashboard') && dokan_is_seller_dashboard() ) {
        if ( empty($GLOBALS['zh_syncing_dimensions']) ) {
            $blocked = ['_weight', '_length', '_width', '_height'];
            if ( in_array($meta_key, $blocked, true) ) {
                return false; 
            }
        }
    }
    return $check;
}, 10, 5);

/**
 * 📦 8.x Sync Zero Hold box dimensions → WooCommerce shipping meta
 */
/**
 * 📦 8.x Sync Zero Hold box dimensions → WooCommerce shipping meta
 * 
 * DESIGN: After blocking Dokan, we explicitly write Woo meta ourselves.
 * This function handles the mapping from ZH JSON to standard Woo meta.
 */
function zh_sync_box_dimensions_to_woo($product_id) {
    $raw = get_post_meta($product_id, 'zh_box_dimensions', true);
    if (empty($raw)) return;

    $data = json_decode($raw, true);
    if (!is_array($data)) return;

    // 🚀 Set bypass flag to "punch through" the Dokan block filter
    $GLOBALS['zh_syncing_dimensions'] = true;

    update_post_meta($product_id, '_weight', isset($data['weight']) ? $data['weight'] : '');
    update_post_meta($product_id, '_length', isset($data['length']) ? $data['length'] : '');
    update_post_meta($product_id, '_width',  isset($data['width'])  ? $data['width']  : '');
    update_post_meta($product_id, '_height', isset($data['height']) ? $data['height'] : '');

    // Reset bypass flag
    unset($GLOBALS['zh_syncing_dimensions']);
}
