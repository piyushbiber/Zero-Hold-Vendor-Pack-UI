<?php
/**
 * Plugin Name: Zero Hold - Vendor Pack UI
 * Description: Adds a custom pack setup UI to Dokan vendor product edit page.
 * Version: 1.0
 * Author: Zero Hold
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load Saves Handler
require_once dirname(__FILE__) . '/includes/save-product-hooks.php';
require_once dirname(__FILE__) . '/includes/stock-sync.php';
require_once dirname(__FILE__) . '/includes/public-display.php';
/**
 * RE-INTEGRATED: Vendor Stock Editor for Dokan
 */
require_once plugin_dir_path(__FILE__) . 'zerohold-ui/modules/vendor-stock-editor.php';
require_once plugin_dir_path(__FILE__) . 'zerohold-ui/modules/vendor-stock-fetch.php';
require_once plugin_dir_path(__FILE__) . 'zerohold-ui/modules/vendor-stock-save.php';

// ZeroHold lifecycle control
require_once dirname(__FILE__) . '/includes/lifecycle/zh-product-lifecycle.php';

// Admin Settings
require_once dirname(__FILE__) . '/includes/admin-settings.php';

// ZeroHold Workflow & AJAX Actions
require_once plugin_dir_path(__FILE__) . 'zerohold-ui/modules/products/status-workflow.php';
require_once plugin_dir_path(__FILE__) . 'zerohold-ui/modules/products/ajax.php';
require_once plugin_dir_path(__FILE__) . 'zerohold-ui/modules/products/row-actions.php';
require_once plugin_dir_path(__FILE__) . 'zerohold-ui/modules/products/bulk-actions.php';
require_once plugin_dir_path(__FILE__) . 'zerohold-ui/modules/products/action-guards.php';
require_once plugin_dir_path(__FILE__) . 'zerohold-ui/modules/products/vendor-product-submission.php';
require_once plugin_dir_path(__FILE__) . 'zerohold-ui/modules/products/id-display.php';
require_once plugin_dir_path(__FILE__) . 'zerohold-ui/modules/products/sku-viewer.php';

// ZeroHold Order Management
require_once plugin_dir_path(__FILE__) . 'zerohold-ui/modules/orders/order-actions.php';
require_once plugin_dir_path(__FILE__) . 'zerohold-ui/modules/orders/order-ui.php';
require_once plugin_dir_path(__FILE__) . 'zerohold-ui/modules/orders/order-list-ui.php';

/**
 * Cleanup: Hide Dokan Pro Shipping & Dimensions UI for Vendors
 * Vendors should only use the Zero Hold Pack Setup for logistics.
 */
add_action('wp_enqueue_scripts', function () {
    if ( ! function_exists('dokan_is_seller_dashboard') || ! dokan_is_seller_dashboard() ) {
        return;
    }

    wp_add_inline_style(
        'dokan-style',
        '
        /* Zero Hold: Aggressive Hide for Dokan Sections */
        .dokan-product-shipping, 
        .dokan-shipping-tax, 
        .dokan-product-shipping-tax,
        .dokan-linked-product, 
        .dokan-product-linked-products,
        .dokan-product-attributes, 
        .dokan-product-min-max, 
        .dokan-min-max,
        .dokan-edit-row.dokan-shipping-tax,
        .dokan-edit-row.dokan-product-attributes,
        .dokan-section-heading[data-target=".dokan-product-shipping-tax"],
        .dokan-section-heading[data-target=".dokan-product-attributes"] {
            display: none !important;
        }
        '
    );
}, 25);

add_action('wp_footer', function () {
    if ( ! function_exists('dokan_is_seller_dashboard') || ! dokan_is_seller_dashboard() ) {
        return;
    }
    ?>
    <script>
        (function() {
            const hideDokanSections = () => {
                const selectors = [
                    '.dokan-product-shipping', '.dokan-shipping-tax', '.dokan-product-shipping-tax',
                    '.dokan-linked-product', '.dokan-product-linked-products',
                    '.dokan-product-attributes', '.dokan-product-min-max', '.dokan-min-max',
                    '.dokan-edit-row'
                ];
                
                selectors.forEach(selector => {
                    document.querySelectorAll(selector).forEach(el => {
                        // Check text content as a fallback for some sections
                        const text = el.textContent.toLowerCase();
                        if (text.includes('shipping and tax') || 
                            text.includes('linked products') || 
                            text.includes('attribute') || 
                            text.includes('min/max options')) {
                            el.style.display = 'none';
                            // el.remove(); // Removing might break Dokan's JS listeners, so we hide first
                        }
                        
                        // Explicitly hide based on class if it's one of the targeted ones
                        if (selector !== '.dokan-edit-row') {
                            el.style.display = 'none';
                        }
                    });
                });
            };

            // Run once
            hideDokanSections();

            // Run on Every DOM change (MutationObserver) because Dokan loads via AJAX
            const observer = new MutationObserver(hideDokanSections);
            observer.observe(document.body, { childList: true, subtree: true });
            
            // Final fallback interval
            setInterval(hideDokanSections, 1000);
        })();
    </script>
    <?php
}, 100);


function zh_render_taxonomy_dropdown( $taxonomy, $label, $field_id, $tooltip = '', $multiple = false ) {
    global $post;
    $post_id = $post ? $post->ID : 0;

    // Get current terms assigned to the product
    $current_terms = [];
    if ( $post_id ) {
        $current_terms = wp_get_object_terms( $post_id, $taxonomy, ['fields' => 'ids'] );
        if ( is_wp_error($current_terms) ) $current_terms = [];
    }

    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
    ]);

    $name_attr = $multiple ? $field_id . '[]' : $field_id;
    $multiple_attr = $multiple ? 'multiple="multiple"' : '';

    echo '<div class="zh-field">';
    echo '<label for="'.esc_attr($field_id).'">'.esc_html($label).' <span style="color:red">*</span>';
    if ( $tooltip ) {
        echo ' <span class="zh-tooltip" title="'.esc_attr($tooltip).'">ⓘ</span>';
    }
    echo '</label>';
    echo '<select id="'.esc_attr($field_id).'" name="'.esc_attr($name_attr).'" class="zh-select" '.$multiple_attr.' required>';
    if ( ! $multiple ) {
        echo '<option value="">Select</option>';
    }

        $shown_names = [];
        foreach ( $terms as $term ) {
            $selected = in_array($term->term_id, $current_terms) ? 'selected' : '';
            
            // UI Rename: 'Infant' -> 'Kids' for better clarity
            $display_name = ( $taxonomy === 'pa_wear-for' && $term->name === 'Infant' ) ? 'Kids' : $term->name;
            
            // Prevent duplicate 'Kids' display
            if ( in_array($display_name, $shown_names) && !$selected ) {
                continue;
            }
            
            echo '<option value="'.esc_attr($term->term_id).'" '.$selected.'>'.esc_html($display_name).'</option>';
            $shown_names[] = $display_name;
        }

    echo '</select>';
    echo '</div>';
}

function zh_render_taxonomy_checkbox_dropdown( $taxonomy, $label, $field_id, $tooltip = '' ) {
    global $post;
    $post_id = $post ? $post->ID : 0;

    $current_terms = [];
    if ( $post_id ) {
        $current_terms = wp_get_object_terms( $post_id, $taxonomy, ['fields' => 'ids'] );
        if ( is_wp_error($current_terms) ) $current_terms = [];
    }

    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
    ]);

    echo '<div class="zh-field">';
    echo '<label>'.esc_html($label).' <span style="color:red">*</span>';
    if ( $tooltip ) {
        echo ' <span class="zh-tooltip" title="'.esc_attr($tooltip).'">ⓘ</span>';
    }
    echo '</label>';
    
    echo '<div class="zh-dropdown" id="dropdown_'.esc_attr($field_id).'">';
    echo '<button type="button" class="zh-dropdown-trigger">';
    $count = count($current_terms);
    $trigger_text = $count > 0 ? $count . ' COLORS SELECTED' : 'SELECT ' . strtoupper($label);
    echo '<span class="zh-trigger-text">'.esc_html($trigger_text).'</span>';
    echo '<span class="zh-caret">▼</span>';
    echo '</button>';
    echo '<div class="zh-dropdown-panel">';
    echo '<div class="zh-dropdown-list">';
    
    if ( ! is_wp_error($terms) ) {
        foreach ( $terms as $term ) {
            $checked = in_array($term->term_id, $current_terms) ? 'checked' : '';
            // We use the SAME ID for the input to maintain JS syncing if needed, 
            // but for Attributes we usually need the ID on the container or a hidden select.
            // For saving, we just need the name attribute.
            echo '<label><input type="checkbox" name="'.esc_attr($field_id).'[]" value="'.esc_attr($term->term_id).'" '.$checked.'> '.esc_html($term->name).'</label>';
        }
    }
    
    echo '</div>'; 
    echo '<div class="zh-dropdown-footer">';
    echo '<button type="button" class="zh-clear">CLEAR</button>';
    echo '<button type="button" class="zh-apply">APPLY</button>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

/**
 * Inject Attribute Dropdowns
 * Priority 9 to ensure visibility above Pack Setup
 */
add_action('dokan_product_edit_after_main', 'zh_vendor_master_dropdowns', 9);
function zh_vendor_master_dropdowns() {
    ?>
    <div class="zh-master-attributes">
        <h3>Product Attributes</h3>
        <div class="zh-attributes-grid">
            <?php
            zh_render_taxonomy_dropdown(
                'zh_color',
                'Color',
                'zh_color',
                "Select the primary color for this product. You will define sizes, prices, and box packing for this color in the Zero Hold – Pack Setup section below."
            );
            
            zh_render_taxonomy_dropdown('zh_fabric', 'Fabric', 'zh_fabric');
            zh_render_taxonomy_dropdown('zh_fit_shape', 'Fit / Shape', 'zh_fit_shape');
            zh_render_taxonomy_dropdown('zh_generic_name', 'Generic Name', 'zh_generic_name', "Generic Name is the commonly used product type by which this item is known in the market. \nExample: T-Shirt, Kurta, Jeans, Track Pant, Saree, Kurta Set.\nDo NOT enter brand name, design name, or usage.");
            zh_render_taxonomy_dropdown('zh_neck', 'Neck', 'zh_neck');
            zh_render_taxonomy_dropdown('zh_pattern', 'Pattern', 'zh_pattern');
            zh_render_taxonomy_dropdown('zh_gst', 'GST', 'zh_gst');
            zh_render_taxonomy_dropdown('zh_hsn', 'HSN Code', 'zh_hsn');

            /**
             * Wear For (Gender) — Vendor UI
             * Global attribute: pa_wear-for
             * Terms: Kids (Infant), Kids, Men, Women, Unisex
             */
            zh_render_taxonomy_dropdown(
                'pa_wear-for',
                'Wear For (Gender)',
                'zh_wear_for',
                'Select who this product is designed for. Unisex will be visible under Men & Women.'
            );
            ?>
        </div>
    </div>
    <?php
}

/**
 * Render the Pack Setup UI (Refactored Single Container)
 */
add_action( 'dokan_product_edit_after_main', 'zh_render_pack_ui', 10, 1 );
function zh_render_pack_ui() {
    ?>
    <div class="zh-box-card">
      <?php wp_nonce_field('zh_pack_save', 'zh_pack_nonce'); ?>
      
      <!-- HIDDEN SYNC FIELDS -->
      <input type="hidden" name="zh_pack_type" id="zh_meta_pack_type">
      <input type="hidden" name="zh_wear_type" id="zh_meta_wear_type">
      <input type="hidden" name="zh_selected_sizes" id="zh_meta_selected_sizes">
      <input type="hidden" name="zh_box_content" id="zh_meta_box_content">
      <input type="hidden" name="zh_box_inventory" id="zh_meta_box_inventory">
      <input type="hidden" name="zh_box_prices" id="zh_meta_box_prices">
      <input type="hidden" name="zh_box_weight" id="zh_meta_box_weight">
      <input type="hidden" name="zh_box_dimensions" id="zh_meta_box_dimensions">

      <h3 class="zh-section-title">Zero Hold – Pack Setup</h3>
      <p class="zh-help">Define how garments are packed inside one sealed box</p>

      <div class="zh-row">
        
        <div class="zh-field">
          <label>Wear Type <span style="color:red">*</span> <span class="zh-tooltip" title="Wear Type decides which size system applies to this product.
Topwear: Sizes like XS / S / M / L / XL
Bottomwear: Sizes like S/28, M/30, L/32
Set / Combo: Standard clothing sizes
Free Size: Single universal size like Saree or Dupatta">ⓘ</span></label>
          <select id="zh_wear_type" class="zh-select" required>
            <option value="">Select</option>
            <option value="topwear">Topwear (T-Shirts / Kurtas)</option>
            <option value="bottomwear">Bottomwear (Jeans / Pants)</option>
            <option value="set">Set / Combo (Kurta Set)</option>
            <option value="freesize">Free Size (Saree / Dupatta)</option>
          </select>
        </div>

        <div class="zh-field">
          <label>Pack Type <span style="color:red">*</span> <span class="zh-tooltip" title="Pack Type defines how garments are packed inside one sealed box.
One-Size Box: One box contains garments of only one size.
Mixed-Size Box: One box contains multiple sizes together.
Select carefully — inventory and returns depend on this choice.">ⓘ</span></label>
          <select id="zh_pack_type" class="zh-select" required>
            <option value="" selected disabled>Select</option>
            <option value="one">One-Size Box</option>
            <option value="mixed">Mixed-Size Box</option>
            <option value="freesize">Free-Size Box</option>
          </select>
        </div>

        <!-- Color is automatically synced from Product Attributes -->

        <div class="zh-field">
          <label>Select available sizes <span style="color:red">*</span></label>
          <!-- Custom Multi-Check Dropdown Container -->
          <div class="zh-dropdown" id="zh_size_dropdown">
            <button type="button" class="zh-dropdown-trigger">
              <span class="zh-trigger-text">Select Sizes</span>
              <span class="zh-caret">▼</span>
            </button>
            <div class="zh-dropdown-panel">
              <div id="zh_size_list" class="zh-dropdown-list">
                <p style="padding:10px; font-size:12px; color:#999;">Select Wear Type first</p>
              </div>
              <div class="zh-dropdown-footer">
                <button type="button" class="zh-clear">Clear</button>
                <button type="button" class="zh-apply">Apply</button>
              </div>
            </div>
          </div>
        </div>

      </div>

      <!-- TABLE AREA -->
      <div id="zh-content-wrapper" style="margin-top:24px;">
        <h4 class="zh-section-title">Pack Content</h4>
        <table class="zh-pack-table">
          <thead>
            <tr>
              <th>Size</th>
              <th>Pcs / Box <span class="zh-tooltip" title="How many pieces of this specific size are inside one sealed box?">ⓘ</span></th>
              <th>Base Price / Pcs <span class="zh-tooltip" title="The wholesale price for one piece of this size.">ⓘ</span></th>
              <th id="zh-col-box-price">Box Price (Selling) <span class="zh-tooltip" title="Final selling price for one sealed box of this size.">ⓘ</span></th>
              <th>Retail Price (SRP) <span class="zh-tooltip" title="Suggested Retail Price for one piece. Used for buyer reference.">ⓘ</span></th>
            </tr>
          </thead>
          <tbody id="zh_pack_rows">
            <!-- Rows injected by JS -->
          </tbody>
        </table>
        

      </div>

        <div class="zh-field" id="zh-global-box-price-wrapper" style="max-width:200px; margin-top:16px;">
            <label>Box Price (Regular Price) <span class="zh-tooltip" title="This is the price of one sealed box.\n\nThe amount is automatically calculated based on the box configuration.\n\nThis price is shown on the marketplace and includes GST.">ⓘ</span></label>
            <input type="text" id="zh_display_box_price" class="zh-locked-price zh-input" readonly value="₹0">
        </div>

      </div>

      <!-- INVENTORY -->
      <div id="zh-inventory-wrapper" style="display:none; margin-top: 32px;">
        <h4 class="zh-section-title">Sealed Box Inventory <span class="zh-tooltip" title="Sealed Box Inventory means how many ready-to-dispatch sealed boxes are available.
Enter only sealed, unopened box quantities.">ⓘ</span></h4>
        <div id="zh-inventory-grid">
           <!-- Inputs injected by JS -->
        </div>
      </div>

    </div> <!-- End Card 1 -->

    <!-- CARD 2: ONE BOX SIZE DETAILS -->
    <div class="zh-box-card">

      <h3 class="zh-section-title">One Box Size Details <span class="zh-tooltip" title="One Box Size Details describe the physical dimensions of one sealed box.
Weight: Actual weight of one box.
Length, Breadth, Height: Box dimensions in centimeters.
Used for shipping, pickup, and courier calculation.">ⓘ</span></h3>
      <p class="zh-help">
          Provide the details of the final package that includes all the ordered items packed together in one box.
      </p>

      <div class="zh-dimension-row">
        <div class="zh-field">
          <label>Box Weight <span style="color:red">*</span></label>
          <input type="number" id="zh_field_box_weight" min="0" step="0.01" placeholder="kg" class="zh-input" required>
        </div>

        <div class="zh-field">
          <label>Length <span style="color:red">*</span></label>
          <input type="number" id="zh_field_box_length" min="0" placeholder="cm" class="zh-input" required>
        </div>

        <div class="zh-field">
          <label>Width <span style="color:red">*</span></label>
          <input type="number" id="zh_field_box_width" min="0" placeholder="cm" class="zh-input" required>
        </div>

        <div class="zh-field">
          <label>Height <span style="color:red">*</span></label>
          <input type="number" id="zh_field_box_height" min="0" placeholder="cm" class="zh-input" required>
        </div>
      </div>

      <div class="zh-info-banner">
          Applicable weight is calculated using box weight and dimensions.
      </div>

      <!-- GUIDELINES -->
      <div class="zh-guidelines">
        <div class="zh-guidelines-header">
          <strong>Pack like a Pro – Guidelines for Packaging and Measuring</strong>
          <button type="button" id="zh_toggle_guidelines">See Guidelines</button>
        </div>

        <div class="zh-guidelines-content" id="zh_guidelines_content" style="display:none; margin-top: 12px;">
            <img 
                src="<?php echo site_url('/wp-content/uploads/zerohold/guidelines/pack-like-a-pro.png'); ?>"
                alt="Pack like a Pro – Fit, Fill, Secure, Measure"
                style="max-width:100%; height:auto;"
            >
        </div>
      </div>

    </div>



    <!-- PREVIOUSLY zh-pack-box close was here, now part of split structure -->
    <?php
}

/**
 * Footer JS
 */
add_action( 'wp_footer', 'zh_pack_ui_js' );

function zh_pack_ui_js() {
    if ( function_exists('dokan_is_seller_dashboard') && ! dokan_is_seller_dashboard() ) return;
?>
<script>
document.addEventListener('DOMContentLoaded', function () {

    // --- DATA: Dual Size Mapping ---
    
    // Safety: Reset Pack Type
    const packType = document.getElementById('zh_pack_type');
    const wooProductType = document.getElementById('product_type') || document.querySelector('select[name="product_type"]');

    function zhSyncWooProductType() {
        if (!packType || !wooProductType) return;
        
        const packVal = packType.value;
        if (packVal === 'one') {
            wooProductType.value = 'variable';
        } else if (packVal === 'mixed' || packVal === 'freesize') {
            wooProductType.value = 'simple';
        }
        
        // Trigger Dokan/Woo change events
        const event = new Event('change', { bubbles: true });
        wooProductType.dispatchEvent(event);
        jQuery(wooProductType).trigger('change'); 
    }

    if (packType) {
        packType.value = '';
        packType.addEventListener('change', function() {
            document.getElementById('zh_meta_pack_type').value = this.value;
            zhResetPackDependentUI();
            zhTriggerAutoTable(); 
            zhSyncWooProductType(); // Sync Product Type
        });
    }

    // Lock Product Type Field
    if (wooProductType) {
        wooProductType.style.pointerEvents = 'none';
        wooProductType.style.background = '#f3f4f6';
        wooProductType.style.opacity = '0.7';
        // Disable interaction but allow value submission
        wooProductType.addEventListener('mousedown', (e) => e.preventDefault());
        wooProductType.addEventListener('keydown', (e) => e.preventDefault());
    }

    const SIZE_MAP = {
        topwear: [
            { code: 'XS', num: 36 },
            { code: 'S',  num: 38 },
            { code: 'M',  num: 40 },
            { code: 'L',  num: 42 },
            { code: 'XL', num: 44 },
            { code: 'XXL',num: 46 },
            { code: 'XXXL',num: 48 }
        ],
        bottomwear: [
            { code: 'XS', num: 26 },
            { code: 'S',  num: 28 },
            { code: 'M',  num: 30 },
            { code: 'L',  num: 32 },
            { code: 'XL', num: 34 },
            { code: 'XXL',num: 36 },
            { code: 'XXXL',num: 38 }
        ],
        kidswear: [
            { code: '0-3M' },
            { code: '6-9M' },
            { code: '12-18M' },
            { code: '18-24M' },
            { code: '2-3Y' },
            { code: '3-4Y' },
            { code: '4-5Y' },
            { code: '5-6Y' },
            { code: '7-8Y' },
            { code: '9-10Y' },
            { code: '11-12Y' },
            { code: '13-14Y' }
        ]
    };
    // Set/Combo uses same as Topwear
    SIZE_MAP['set'] = SIZE_MAP['topwear'];

    // --- Pack Setup Logic (Enhanced for Single-Color Selection) ---
    const wearTypeSel = document.getElementById('zh_wear_type');
    const sizeListContainer = document.getElementById('zh_size_list');
    const contentWrapper = document.getElementById('zh-content-wrapper');
    const inventoryGrid = document.getElementById('zh-inventory-grid');
    const inventoryWrapper = document.getElementById('zh-inventory-wrapper');

    // Helper: Reset UI state on dependent change
    function zhResetPackDependentUI() {
        // Clear meta
        document.getElementById('zh_meta_selected_sizes').value = '';
        document.getElementById('zh_meta_box_content').value = '';
        document.getElementById('zh_meta_box_inventory').value = '';

        // Clear tables
        const existingTables = contentWrapper.querySelectorAll('.zh-color-group');
        existingTables.forEach(t => t.remove());

        // Clear inventory
        if (inventoryGrid) inventoryGrid.innerHTML = '';
        if (inventoryWrapper) inventoryWrapper.style.display = 'none';
        
        // Uncheck all checkboxes
        document.querySelectorAll('#zh_size_list input[type="checkbox"]').forEach(cb => {
            cb.checked = false;
        });

        zhSyncTotalData();
    }

    // Helper: Sync All Content (JSON)
    function zhSyncTotalData() {
        const groups = contentWrapper.querySelectorAll('.zh-color-group');
        
        // Lock Wear Type if any table exists
        if (wearTypeSel) {
            if (groups.length > 0) {
                wearTypeSel.disabled = true;
                wearTypeSel.style.background = '#f9fafb';
                wearTypeSel.style.cursor = 'not-allowed';
            } else {
                wearTypeSel.disabled = false;
                wearTypeSel.style.background = '#fff';
                wearTypeSel.style.cursor = 'default';
            }
        }

        let boxContent = {};
        let inventoryData = {};
        let boxPrices = {}; // NEW: Store box prices with slugs
        const isOneSize = document.getElementById('zh_pack_type')?.value === 'one';
        let simplePriceTotal = 0; // Track total for Mixed/Free size

        groups.forEach(group => {
            const colorName = group.getAttribute('data-color');
            const rows = group.querySelectorAll('.zh-pack-table tbody tr');
            
            boxContent[colorName] = {};
            rows.forEach(row => {
                const size = row.cells[0].innerText; // MOVE TO TOP
                const inputs = row.querySelectorAll('input');
                let pcs, base, boxVal, srp;
                
                if (isOneSize) {
                    // One-Size: 4 inputs (Pcs, Base, Box, SRP)
                    if (inputs.length < 4) return;
                    pcs = inputs[0].value;
                    base = inputs[1].value;
                    boxVal = inputs[2].value;
                    srp = inputs[3].value;
                    
                    // Extract box prices for One-Size Box (with slug conversion)
                    if (boxVal) {
                        const sizeSlug = size.toLowerCase().replace(/[\/\s]/g, '-');
                        boxPrices[sizeSlug] = boxVal;
                    }
                } else {
                    // Mixed/Free-Size: 3 inputs (Pcs, Base, SRP)
                    if (inputs.length < 3) return;
                    pcs = inputs[0].value;
                    base = inputs[1].value;
                    boxVal = ''; // No box price per row
                    srp = inputs[2].value;

                    // Calculate total for simple product Global Price
                    if (pcs && base) {
                        simplePriceTotal += (parseFloat(pcs) * parseFloat(base));
                    }
                }
                
                boxContent[colorName][size] = {
                    pcs: pcs,
                    base_price: base,
                    box_price: boxVal,
                    srp: srp
                };
            });

            // Sync Inventory for this color
            if (isOneSize) {
                inventoryData[colorName] = {};
                const invCards = inventoryGrid.querySelectorAll(`.zh-inventory-card[data-color="${colorName}"]`);
                invCards.forEach(card => {
                    const size = card.getAttribute('data-size');
                    const val = card.querySelector('input').value;
                    inventoryData[colorName][size] = val;
                });
            } else {
                const invInput = document.querySelector(`.zh-inventory-card[data-color="${colorName}"] input`);
                if (invInput) {
                    inventoryData[colorName] = invInput.value;
                }
            }
        });

        // Store Global Price for Simple Products
        if (!isOneSize && simplePriceTotal > 0) {
            boxPrices['default'] = {
                box_price: simplePriceTotal.toFixed(2)
            };
        }

        document.getElementById('zh_meta_box_content').value = JSON.stringify(boxContent);
        document.getElementById('zh_meta_box_inventory').value = JSON.stringify(inventoryData);
        document.getElementById('zh_meta_box_prices').value = JSON.stringify(boxPrices);
        zhUpdateLiveBoxPrice();
    }

    // Helper: Sync Dimensions
    function zhSyncDimensions() {
        const weight = document.getElementById('zh_field_box_weight').value;
        const L = document.getElementById('zh_field_box_length').value;
        const W = document.getElementById('zh_field_box_width').value;
        const H = document.getElementById('zh_field_box_height').value;

        document.getElementById('zh_meta_box_weight').value = weight;
        document.getElementById('zh_meta_box_dimensions').value = JSON.stringify({
            weight: weight,
            length: L,
            width: W,
            height: H
        });
    }

    // Helper: Build Color-Specific Table
    function addOrUpdateColorTable(color, selectedSizes) {
        // ALWAYS REMOVE ALL EXISTING GROUPS - Only one color allowed now
        const existing = contentWrapper.querySelectorAll('.zh-color-group');
        existing.forEach(e => e.remove());

        const existingInv = inventoryGrid.querySelectorAll('.zh-inventory-group');
        existingInv.forEach(e => e.remove());

        const packTypeVal = document.getElementById('zh_pack_type')?.value;

        const isOneSize = packTypeVal === 'one';
        const isMixedSize = packTypeVal === 'mixed';
        const isFreeSizeBox = packTypeVal === 'freesize';

        // Toggle Header Column Display
        const headerCol = document.getElementById('zh-col-box-price');
        if (headerCol) headerCol.style.display = isOneSize ? 'table-cell' : 'none';

        // Toggle Global Box Price Field Display
        const globalField = document.getElementById('zh-global-box-price-wrapper');
        if (globalField) globalField.style.display = isOneSize ? 'none' : 'block';

        // Generate table rows with appropriate placeholders
        const pcsPlaceholder = (isOneSize || isFreeSizeBox) ? 'Total Pcs in one box' : '';
        const rowsHTML = selectedSizes.map(size => {
            // Only show Box Price input for One-Size Box
            const boxPriceTd = isOneSize 
                ? `<td><input type="number" min="0" class="zh-box-price-input" data-size="${size}" style="width:100%" placeholder="Box price"></td>`
                : '';

            return `
            <tr>
                <td>${size}</td>
                <td><input type="number" min="0" class="pcs-input" style="width:100%" placeholder="${pcsPlaceholder}" required></td>
                <td><input type="number" min="0" class="base-price-input" style="width:100%" placeholder="Single pcs price" required></td>
                ${boxPriceTd}
                <td><input type="number" min="0" style="width:100%" placeholder="Suggested retail price" required></td>
            </tr>
            `;
        }).join('');

        // Add summary row for mixed-size box and free-size box (4 columns)
        const summaryRowHTML = (isMixedSize || isFreeSizeBox) ? `
            <tr style="background-color:#f3f4f6; font-weight:600; border-top:2px solid #d1d5db;">
                <td style="font-weight:600;">TOTAL</td>
                <td style="text-align:center; color:#374151;" id="zh-total-pcs-${color}">0</td>
                <td style="text-align:center; color:#374151;" id="zh-total-base-${color}">0</td>
                <td></td>
            </tr>
        ` : '';

        const tableHTML = `
            <div class="zh-color-group" data-color="${color}" style="margin-top:20px; border-top:1px dashed #ddd; padding-top:15px;">
                <h5 style="margin:0 0 10px; font-weight:600; color:#374151;">Color: ${color}</h5>
                <table class="zh-pack-table">
                    <thead>
                        ${document.querySelector('.zh-pack-table thead').innerHTML}
                    </thead>
                    <tbody>
                        ${rowsHTML}
                        ${summaryRowHTML}
                    </tbody>
                </table>
            </div>
        `;
        
        // Append before the total price display
        const priceDisplay = document.querySelector('#zh-content-wrapper .zh-field');
        if (priceDisplay) {
            priceDisplay.insertAdjacentHTML('beforebegin', tableHTML);
        } else {
            contentWrapper.insertAdjacentHTML('beforeend', tableHTML);
        }

        // Add Inventory Section
        let invCardsHTML = '';
        if (isOneSize) {
            // Cards per size
            selectedSizes.forEach(size => {
                invCardsHTML += `
                    <div class="zh-inventory-card" data-color="${color}" data-size="${size}">
                        <label>${color} - ${size} Boxes</label>
                        <input type="number" min="0" class="zh-input" placeholder="Total Boxes" required>
                    </div>
                `;
            });
        } else {
            // Single card for color
            invCardsHTML = `
                <div class="zh-inventory-card" data-color="${color}">
                    <label>${color} Boxes</label>
                    <input type="number" min="0" class="zh-input" placeholder="Total Boxes" required>
                </div>
            `;
        }

        const groupHTML = `
            <div class="zh-inventory-group" data-color="${color}" style="width:100%; margin-bottom: 20px;">
                <h5 style="margin: 0 0 10px; font-size: 13px; color: #374151; font-weight: 600; text-transform: uppercase; border-bottom: 1px solid #f3f4f6; padding-bottom: 5px;">Inventory: ${color}</h5>
                <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                    ${invCardsHTML}
                </div>
            </div>
        `;
        inventoryGrid.insertAdjacentHTML('beforeend', groupHTML);

        inventoryWrapper.style.display = 'block';
        
        // AUTO-SUGGEST: Box Price from PCS × Base Price (for One-Size Box only)
        if (isOneSize) {
            const colorGroup = contentWrapper.querySelector(`[data-color="${color}"]`);
            if (colorGroup) {
                const rows = colorGroup.querySelectorAll('.zh-pack-table tbody tr');
                rows.forEach(row => {
                    const pcsInput = row.querySelector('.pcs-input');
                    const baseInput = row.querySelector('.base-price-input');
                    const boxPriceInput = row.querySelector('.zh-box-price-input');
                    
                    if (pcsInput && baseInput && boxPriceInput) {
                        const autoSuggest = () => {
                            const pcs = Number(pcsInput.value) || 0;
                            const base = Number(baseInput.value) || 0;
                            
                            // Calculate and update box price
                            if (pcs > 0 && base > 0) {
                                const calculated = pcs * base;
                                boxPriceInput.value = calculated.toFixed(2);
                            } else if (pcs === 0 || base === 0) {
                                boxPriceInput.value = '';
                            }
                        };
                        
                        pcsInput.addEventListener('input', autoSuggest);
                        baseInput.addEventListener('input', autoSuggest);
                        
                        // Trigger initial calculation if values exist
                        autoSuggest();
                    }
                });
            }
        }
        
        zhSyncTotalData();
    }

    // Helper: Update Live Box Price
    function zhUpdateLiveBoxPrice() {
        let total = 0;

        const packTypeVal = document.getElementById('zh_pack_type')?.value;
        const colorGroups = contentWrapper.querySelectorAll('.zh-color-group');

        if (packTypeVal === 'one') {
            // One-Size Box: use ONLY the FIRST COLOR's FIRST ROW
            if (colorGroups.length > 0) {
                const firstColorGroup = colorGroups[0];
                const firstRow = firstColorGroup.querySelector('.zh-pack-table tbody tr');
                if (firstRow) {
                    const inputs = firstRow.querySelectorAll('input');
                    const pcs = parseFloat(inputs[0].value) || 0;
                    const base = parseFloat(inputs[1].value) || 0;
                    total = pcs * base;
                }
            }
        } else if (packTypeVal === 'mixed' || packTypeVal === 'freesize') {
            // Mixed-Size Box and Free-Size Box: sum ALL DATA ROWS of FIRST COLOR ONLY
            if (colorGroups.length > 0) {
                const firstColorGroup = colorGroups[0];
                const allTbodyRows = firstColorGroup.querySelectorAll('.zh-pack-table tbody tr');
                // Sum all rows except the LAST row (which is summary row)
                for (let i = 0; i < allTbodyRows.length - 1; i++) {
                    const inputs = allTbodyRows[i].querySelectorAll('input');
                    const pcs = parseFloat(inputs[0].value) || 0;
                    const base = parseFloat(inputs[1].value) || 0;
                    total += (pcs * base);
                }
            }
        }

        const display = document.getElementById('zh_display_box_price');
        if (display) display.value = '₹' + total.toLocaleString('en-IN');

        const dokanPrice = document.getElementById('_regular_price') || document.querySelector('input[name="_regular_price"]');
        if (dokanPrice) {
            dokanPrice.value = total;
            dokanPrice.readOnly = true;
            dokanPrice.classList.add('zh-locked-price');
        }
    }

    // 1. Sync Logic for Color (Automatic)
    const mainColorSelect = document.getElementById('zh_color');
    
    function zhTriggerAutoTable() {
        if (!mainColorSelect) return;
        
        const selectedOption = mainColorSelect.options[mainColorSelect.selectedIndex];
        const colorName = selectedOption ? selectedOption.text : '';
        const colorId = selectedOption ? selectedOption.value : '';

        if (!colorId || colorId === '') {
            zhResetPackDependentUI();
            return;
        }

        const packType = document.getElementById('zh_pack_type')?.value;
        const wear = wearTypeSel?.value;

        if (!packType || !wear) return;

        let finalSizes = [];
        if (wear === 'freesize') {
            finalSizes = ['Free Size'];
        } else {
            finalSizes = [...sizeListContainer.querySelectorAll('input:checked')].map(i => i.value);
        }

        if (finalSizes.length > 0) {
            // Sync to hidden meta field for backend
            const metaSizes = document.getElementById('zh_meta_selected_sizes');
            if (metaSizes) {
                metaSizes.value = JSON.stringify(finalSizes);
            }
            addOrUpdateColorTable(colorName, finalSizes);
        } else {
            // Clear hidden field if no sizes
            const metaSizes = document.getElementById('zh_meta_selected_sizes');
            if (metaSizes) metaSizes.value = '';
            
            // Keep the table name updated even if no sizes selected yet
            const existingHeader = contentWrapper.querySelector('.zh-color-group h5');
            if (existingHeader) existingHeader.innerText = `Color: ${colorName}`;
        }
    }

    // Watch for Attribute Color changes
    if (mainColorSelect) {
        mainColorSelect.addEventListener('change', zhTriggerAutoTable);
    }

    // 2. Wear Type & Size List
    const wearForSel = document.getElementById('zh_wear_for');

    function zhUpdateSizeList() {
        if (!wearTypeSel || !sizeListContainer) return;

        const wearType = wearTypeSel.value;
        const wearForText = wearForSel ? wearForSel.options[wearForSel.selectedIndex].text : '';
        
        sizeListContainer.innerHTML = '';

        if (!wearType) {
            zhResetPackDependentUI();
            return;
        }

        if (wearType === 'freesize') {
            document.getElementById('zh_size_dropdown').style.display = 'none';
            zhTriggerAutoTable();
            return;
        } else {
            document.getElementById('zh_size_dropdown').style.display = 'block';
        }

        // Logic: If Gender is "Kids", override with Kidswear map
        let mapKey = wearType;
        if (wearForText === 'Kids') {
            mapKey = 'kidswear';
        }

        const availableSizes = SIZE_MAP[mapKey] || SIZE_MAP['topwear'];
        availableSizes.forEach(item => {
            const label = item.num ? `${item.code}/${item.num}` : item.code;
            const html = `<label><input type="checkbox" value="${label}"> ${label}</label>`;
            sizeListContainer.insertAdjacentHTML('beforeend', html);
        });
    }

    if (wearTypeSel) {
        wearTypeSel.addEventListener('change', function() {
            document.getElementById('zh_meta_wear_type').value = this.value;
            zhEnforceFreeSizePackLock();
            zhUpdateSizeList();
        });
    }

    if (wearForSel) {
        wearForSel.addEventListener('change', zhUpdateSizeList);
    }

    // Initial Trigger
    setTimeout(zhTriggerAutoTable, 500);

    // Enforce Free Size pack lock on initial load
    setTimeout(function(){ zhEnforceFreeSizePackLock(); }, 600);
    // Helper: enforce Free Size pack-type lock
    function zhEnforceFreeSizePackLock() {
        const packTypeSel = document.getElementById('zh_pack_type');
        const metaPack = document.getElementById('zh_meta_pack_type');
        if (!wearTypeSel || !packTypeSel || !metaPack) return;

        if (wearTypeSel.value === 'freesize') {
            // Show only Free-Size Box, set and lock
            Array.from(packTypeSel.options).forEach(opt => {
                if (opt.value === 'freesize' || opt.value === '') {
                    opt.style.display = '';
                } else {
                    opt.style.display = 'none';
                }
            });
            packTypeSel.value = 'freesize';
            metaPack.value = 'freesize';
            packTypeSel.disabled = true;
        } else {
            // Hide Free-Size Box, show others, enable dropdown
            Array.from(packTypeSel.options).forEach(opt => {
                if (opt.value === 'freesize') {
                    opt.style.display = 'none';
                } else {
                    opt.style.display = '';
                }
            });
            // If user had previously selected Free-Size Box, reset selection
            if (packTypeSel.value === 'freesize') {
                packTypeSel.value = '';
                metaPack.value = '';
            }
            packTypeSel.disabled = false;
        }
    }

    // 3. Dropdown Delegation (Sizes)
    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('.zh-dropdown-trigger');
        if (trigger) {
            const dropdown = trigger.closest('.zh-dropdown');
            dropdown.classList.toggle('open');
            return;
        }

        const clearBtn = e.target.closest('.zh-clear');
        if (clearBtn) {
            const list = clearBtn.closest('.zh-dropdown-panel').querySelector('.zh-dropdown-list');
            list.querySelectorAll('input:not([disabled])').forEach(cb => cb.checked = false);
            return;
        }

        const applyBtn = e.target.closest('.zh-apply');
        if (applyBtn) {
            const panel = applyBtn.closest('.zh-dropdown-panel');
            const dropdown = applyBtn.closest('.zh-dropdown');
            const list = panel.querySelector('.zh-dropdown-list');
            const triggerText = dropdown.querySelector('.zh-trigger-text');
            const checkedBoxes = [...list.querySelectorAll('input:checked')];
            
            if (list.id === 'zh_size_list') {
                triggerText.innerText = checkedBoxes.length > 0 ? checkedBoxes.length + ' SIZES SELECTED' : 'Select Sizes';
                zhTriggerAutoTable();
            }

            dropdown.classList.remove('open');
            return;
        }

        // Close on outside click
        if (!e.target.closest('.zh-dropdown')) {
            document.querySelectorAll('.zh-dropdown.open').forEach(d => d.classList.remove('open'));
        }
    });

    // 4. Data Sync Delegation
    document.addEventListener('input', function(e) {
        if (e.target.closest('.zh-pack-table') || e.target.closest('#zh-inventory-grid')) {
            zhSyncTotalData();
            zhUpdateMixedSizeSummaryRows();
        }
        if (e.target.closest('.zh-dimension-row')) {
            zhSyncDimensions();
        }
    });

    // Helper: Update Mixed-Size Box Summary Rows
    function zhUpdateMixedSizeSummaryRows() {
        const packTypeVal = document.getElementById('zh_pack_type')?.value;
        if (packTypeVal !== 'mixed' && packTypeVal !== 'freesize') return;

        const colorGroups = contentWrapper.querySelectorAll('.zh-color-group');
        if (colorGroups.length === 0) return;

        colorGroups.forEach(group => {
            const colorName = group.getAttribute('data-color');
            const allInputs = group.querySelectorAll('.zh-pack-table tbody tr:not(:last-child) input[type="number"]');
            
            if (allInputs.length === 0) return;

            let totalPcs = 0;
            let totalBasePrice = 0;

            for (let i = 0; i < allInputs.length; i += 3) {
                const pcsInput = allInputs[i];
                const basePriceInput = allInputs[i + 1];
                
                if (pcsInput && basePriceInput) {
                    const pcs = parseFloat(pcsInput.value) || 0;
                    const basePrice = parseFloat(basePriceInput.value) || 0;
                    totalPcs += pcs;
                    totalBasePrice += (pcs * basePrice);
                }
            }

            const totalPcsCell = document.getElementById(`zh-total-pcs-${colorName}`);
            const totalPriceCell = document.getElementById(`zh-total-base-${colorName}`);
            
            if (totalPcsCell) totalPcsCell.innerText = totalPcs;
            if (totalPriceCell) totalPriceCell.innerText = totalBasePrice.toFixed(2);
        });
    }

    // Guidelines
    const guidelinesBtn = document.getElementById('zh_toggle_guidelines');
    if (guidelinesBtn) {
        guidelinesBtn.addEventListener('click', function () {
            const box = document.getElementById('zh_guidelines_content');
            box.style.display = box.style.display === 'none' ? 'block' : 'none';
        });
    }

    // Initial Trigger for Price
    zhUpdateLiveBoxPrice();

});
</script>
<?php
}

/**
 * Head CSS
 */
add_action( 'wp_head', function () {
    if ( function_exists('dokan_is_seller_dashboard') && ! dokan_is_seller_dashboard() ) return;
    ?>
    <style>
        /* Hide unwanted UI */
        .dokan-product-short-description,
        #post_excerpt,
        .dokan-short-description,
        .dokan-product-inventory,
        .dokan-inventory-options,
        .inventory_tab,
        .dokan-product-other-options,
        .dokan-other-options,
        .dokan-product-status,
        .dokan-post-status,
        .dokan-product-visibility,
        .dokan-purchase-note,
        .dokan-form-group.dokan-product-status,
        .dokan-form-group.dokan-purchase-note {
            display: none !important;
        }

        .zh-locked-price {
            background-color: #f3f4f6 !important;
            cursor: not-allowed !important;
            border-color: #d1d5db !important;
            font-weight: 600 !important;
        }

        /* Master Attributes Grid */
        .zh-master-attributes {
            background:#fff;
            border:1px solid #e5e7eb;
            padding:16px;
            margin-top:16px;
        }
        .zh-master-attributes h3 { margin:0 0 12px; font-size:15px; font-weight: 600; }
        .zh-attributes-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 600px) { .zh-attributes-grid { grid-template-columns: 1fr; } }

        /* General Styles */
        .zh-section-title {
            margin:0 0 12px;
            font-size:15px; /* Matches Master Attributes */
            font-weight: 600;
        }
        .zh-field { margin-bottom:12px; }
        .zh-field label { display:block; font-size:12px; color:#555; margin-bottom:4px; }
        .zh-select, .zh-input { width:100%; padding:10px; border:1px solid #d1d5db; background:#f9fafb; border-radius:4px; box-sizing: border-box; }

        /* Card Container (Clean Separation) */
        .zh-box-card {
          background:#fff;
          border:1px solid #e5e7eb;
          padding:16px;
          margin-top:16px;
        }

        .zh-row {
          display: grid;
          grid-template-columns: 1fr 1fr 1fr 1fr;
          gap: 16px;
        }
        @media (max-width: 1100px) {
            .zh-row { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 600px) {
            .zh-row { grid-template-columns: 1fr; }
        }

        /* Improved Table Styling */
        .zh-pack-table {
          width:100%;
          border-collapse:collapse;
          margin-top:12px;
          font-size: 13px;
        }
        .zh-pack-table th {
            background: #f9fafb;
            color: #374151;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            padding: 10px;
            text-align: left;
            border:1px solid #e5e7eb;
        }
        .zh-pack-table td {
          border:1px solid #e5e7eb;
          padding:10px;
        }
        .zh-pack-table input {
            width: 100%;
            padding: 6px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
        }

        /* Box Dimensions & Guidelines */
        /* .zh-box-dimensions removed as wrapper, now in .zh-box-card */

        /* Box Dimensions & Guidelines */


        .zh-dimension-row {
          display:flex;
          gap:12px;
        }

        .zh-info-banner {
          background:#ecfdf5;
          padding:10px;
          margin-top:12px;
          font-size:13px;
          color:#065f46;
          border-radius: 4px;
        }


        .zh-guidelines {
          margin-top:16px;
        }

        .zh-guidelines-header {
          display:flex;
          justify-content:space-between;
          align-items:center;
        }
        
        #zh_toggle_guidelines {
            background: none;
            border: 1px solid #d1d5db;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }

        .zh-guidelines-content {
          display:none;
          margin-top:12px;
          grid-template-columns:repeat(4,1fr);
          gap:12px;
        }

        .zh-guidelines-content img {
          width:100%;
          border-radius:6px;
        }
        

        /* Dropdown container */
        .zh-dropdown {
        position: relative;
        width: 100%;
        }

        /* Trigger */
        .zh-dropdown-trigger {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        background: #fff;
        text-align: left;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-radius: 4px;
        }

        .zh-trigger-text {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            text-transform: uppercase;
        }

        /* Panel */
        .zh-dropdown-panel {
        position: absolute;
        top: 100%;
        left: 0;
        width: 100%;
        min-width: 240px; /* Ensure buttons and text fit */
        background: #fff;
        border: 1px solid #d1d5db;
        box-shadow: 0 4px 12px rgba(0,0,0,.08);
        z-index: 99;
        display: none;
        border-radius: 0 0 4px 4px;
        }
        
        /* Ensure panel doesn't get cut off on the right edge of the screen */
        .zh-field:nth-child(4) .zh-dropdown-panel {
            right: 0;
            left: auto;
        }
        @media (max-width: 1100px) {
            .zh-field:nth-child(even) .zh-dropdown-panel {
                right: 0;
                left: auto;
            }
        }

        /* Scrollable list */
        .zh-dropdown-list {
        max-height: 180px;
        overflow-y: auto;
        padding: 10px;
        }

        .zh-dropdown-list label {
        display: block;
        margin-bottom: 6px;
        font-size: 13px;
        }

        /* Footer */
        .zh-dropdown-footer {
        display: flex;
        justify-content: space-between;
        padding: 10px;
        border-top: 1px solid #eee;
        background: #fafafa;
        }

        .zh-clear {
        background: none;
        border: none;
        color: #7c3aed;
        cursor: pointer;
        font-weight: 600;
        font-size: 13px;
        text-transform: uppercase;
        }

        .zh-apply {
        background: #7c3aed;
        color: #fff;
        border: none;
        padding: 8px 20px;
        cursor: pointer;
        border-radius: 4px;
        font-weight: 600;
        font-size: 13px;
        text-transform: uppercase;
        }

        /* Show dropdown */
        .zh-dropdown.open .zh-dropdown-panel {
        display: block;
        }

        /* SECTION SPACING FIX */
        .zh-section-title {
        margin-top: 28px;
        margin-bottom: 12px;
        font-weight: 600;
        }

        .zh-section-content {
        margin-bottom: 24px;
        }

        /* Inventory Grid */
        #zh-inventory-grid {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        }

        .zh-inventory-card {
        width: 220px;
        border: 1px solid #ddd;
        padding: 12px;
        border-radius: 6px;
        background: #f9fafb;
        }

        .zh-inventory-card label {
        font-weight: 600;
        display: block;
        margin-bottom: 6px;
        font-size: 13px;
        }

        .zh-color-group {
            margin-bottom: 30px;
        }
        
        .zh-color-group h5 {
            font-size: 14px;
            color: #111827;
            border-left: 4px solid #6f4ef6;
            padding-left: 10px;
        }

        .zh-disabled {
            pointer-events: none !important;
            cursor: not-allowed !important;
        }
        .zh-disabled .zh-dropdown-trigger {
            background: #f9fafb !important;
            color: #9ca3af !important;
        }
    </style>
    <?php
});
