<?php
if (!defined('ABSPATH')) exit;

/**
 * Hard Guard: Remove restricted row actions for vendors.
 * Targets: Edit, Delete, Quick Edit, Duplicate.
 * Compatible with Dokan Lite & Dokan Pro.
 */

// Primary Hook for Dokan Lite
add_filter('dokan_product_row_actions', 'zh_remove_restricted_product_actions', 99, 2);

// Secondary Hook often used in Dokan Pro / newer versions
add_filter('dokan_product_list_row_actions', 'zh_remove_restricted_product_actions', 99, 2);

function zh_remove_restricted_product_actions($actions, $post) {
    // Only target products
    if ($post->post_type !== 'product') {
        return $actions;
    }

    /**
     * Rule: Only lock actions for products that have been officially published
     * Pending products must remain editable.
     */
    if (!in_array($post->post_status, ['publish', 'private'])) {
        return $actions;
    }

    // 1. Remove Edit
    if (isset($actions['edit'])) {
        unset($actions['edit']);
    }
    
    // ... rest of the removals ...
    // 2. Remove Delete / Trash / Permanent Delete
    $delete_keys = ['delete', 'trash', 'permanent_delete'];
    foreach ($delete_keys as $key) {
        if (isset($actions[$key])) {
            unset($actions[$key]);
        }
    }

    // 3. Remove Quick Edit
    if (isset($actions['quick-edit']) || isset($actions['quick_edit'])) {
        unset($actions['quick-edit']);
        unset($actions['quick_edit']);
    }

    // 4. Remove Duplicate
    if (isset($actions['duplicate'])) {
        unset($actions['duplicate']);
    }

    return $actions;
}

/**
 * Remove Bulk Actions from Product List
 */
add_filter('dokan_bulk_product_statuses', '__return_empty_array', 99);

/**
 * Aggressively Hide Bulk Action UI via CSS
 */
add_action('wp_enqueue_scripts', function() {
    if (!function_exists('dokan_is_seller_dashboard') || !dokan_is_seller_dashboard()) return;

    wp_add_inline_style('dokan-style', '
        /* Zero Hold: Hide Bulk Action UI */
        #dokan-bulk-action-selector,
        #dokan-bulk-action-submit {
            display: none !important;
        }

        /* Zero Hold: Hide Selection Column to maintain table alignment */
        #cb,                           /* Header ID */
        .column-cb,                    /* Header Class */
        .dokan-product-select,         /* Row Class (Lite) */
        .check-column,                 /* Common Class */
        #cb-select-all {               /* Specific Input ID */
            display: none !important;
            width: 0 !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        /* Ensure columns align correctly after shift */
        table.dokan-table.product-listing-table thead th:first-child,
        table.dokan-table.product-listing-table tbody td:first-child,
        table.dokan-table.product-listing-table tbody th:first-child {
            border-left: none !important;
        }

        /* Zero Hold: Visual de-emphasis for locked links */
        .zh-locked-row .column-thumb, 
        .zh-locked-row .column-primary strong {
            cursor: default !important;
        }

        /* Zero Hold: Hide Permalink / Slug editor in Edit Product page */
        #edit-slug-box {
            display: none !important;
        }

        /* Zero Hold: Organic Layout with 2-Line Name Clamping */
        .dokan-product-listing-area .dokan-table {
            table-layout: auto !important; /* Back to organic Dokan fluidity */
        }

        /* Tighten gap between Image and Name */
        #dokan-product-list-table .column-thumb {
            width: 55px !important;
            padding-right: 0 !important;
        }

        #dokan-product-list-table .column-primary {
            padding-left: 5px !important;
            max-width: 260px !important; /* Force wrapping at ~6-7 words */
            white-space: normal !important;
        }

        /* The actual product title link */
        #dokan-product-list-table .column-primary strong a {
            display: -webkit-box !important;
            -webkit-line-clamp: 2 !important; /* Restore 2 lines max */
            -webkit-box-orient: vertical !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            line-height: 1.3 !important;
            white-space: normal !important;
            word-break: break-all;
            word-wrap: break-word;
            font-size: 14px !important;
            font-weight: 600 !important;
            color: #1a1a1a !important;
        }

        /* Ensure IDs and See All SKU links stack correctly without adding row height */
        .zh-product-id-tag, .zh-see-sku-btn {
            display: inline-block !important;
            margin-top: 2px !important;
            vertical-align: middle;
        }
    ');
}, 30);

/**
 * JS Guard: Disable Image and Name links ONLY for Online/Inactive products
 * Also enforces the compact layout styles against Dokan JS overrides.
 */
add_action('wp_footer', function() {
    if (!function_exists('dokan_is_seller_dashboard') || !dokan_is_seller_dashboard()) return;
    ?>
    <script>
    jQuery(function($) {
        const enforceZHLayout = () => {
            $('.product-listing-table tbody tr').each(function() {
                const $row = $(this);
                
                // 1. Lockdown logic (already present)
                const isOnline   = $row.find('.zh-action-inactive').length > 0;
                const isInactive = $row.find('.zh-action-reactivate').length > 0;
                
                if (isOnline || isInactive) {
                    $row.addClass('zh-locked-row');
                    $row.find('.column-thumb a, .column-primary strong a').each(function() {
                        const href = $(this).attr('href');
                        if (href && href.includes('action=edit')) {
                            $(this).contents().unwrap();
                        }
                    });
                }

                // 2. Anti-Flicker: Force style re-application if Dokan JS modified them
                $row.find('.column-primary strong a').css({
                    'display': '-webkit-box',
                    '-webkit-line-clamp': '2',
                    '-webkit-box-orient': 'vertical',
                    'overflow': 'hidden',
                    'white-space': 'normal'
                });
            });
        };

        // Run on load and during AJAX loads
        enforceZHLayout();
        $(document).on('ajaxComplete', function() {
            setTimeout(enforceZHLayout, 50);
            setTimeout(enforceZHLayout, 300); // Second pass to catch late JS shifts
        });
    });
    </script>
    <?php
}, 100);

/**
 * Safety Block: Prevent direct access to Edit page if vendor tries to bypass UI
 */
add_action('current_screen', function() {
    $screen = get_current_screen();
    
    // Block Dokan Edit Product screen for published/private products
    if ($screen && $screen->id === 'dokan_edit_product') {
        $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
        if ($product_id && !current_user_can('manage_options')) {
            $post = get_post($product_id);
            // LOCK publish and private, but ALLOW pending
            if ($post && in_array($post->post_status, ['publish', 'private'])) {
                 wp_die(__('Editing is locked for this product status. Only stock updates are allowed.', 'zerohold'));
            }
        }
    }
});
