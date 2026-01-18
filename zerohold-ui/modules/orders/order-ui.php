<?php
/**
 * ZeroHold Order UI Customizations
 * Handles layout and UI improvements for the Dokan Order Details page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hide unnecessary sections from Dokan Order Details page
 */
add_action( 'wp_head', 'zh_customize_order_details_css' );
function zh_customize_order_details_css() {
    if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() ) {
        return;
    }

    // Only target the order details page
    if ( ! isset( $_GET['order_id'] ) ) {
        return;
    }

    ?>
    <style>
        /* Hide Billing and Shipping Address Panels */
        .dokan-order-billing-address,
        .dokan-order-shipping-address {
            display: none !important;
        }

        /* Hide Downloadable Product Permission Panel */
        /* Since it doesn't have a unique class, we target the panel containing that specific text */
        .dokan-order-details-wrap .dokan-panel:has(strong:contains("Downloadable Product Permission")),
        .dokan-order-details-wrap .dokan-panel:has(.dokan-panel-heading strong:contains("Downloadable Product Permission")) {
            display: none !important;
        }

        /* Hide Order Notes Panel */
        #dokan-order-notes,
        .dokan-order-details-wrap .dokan-panel:has(.dokan-panel-heading strong:contains("Order Notes")) {
            display: none !important;
        }
    </style>
    <?php
}

/**
 * JS Fallback and advanced UI cleaning
 */
add_action( 'wp_footer', 'zh_customize_order_details_js' );
function zh_customize_order_details_js() {
    if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() ) {
        return;
    }

    if ( ! isset( $_GET['order_id'] ) ) {
        return;
    }

    ?>
    <script>
    jQuery(function($) {
        // Robust way to find and hide panels by their heading text
        $('.dokan-panel-heading strong').each(function() {
            var text = $(this).text().trim();
            if (text === 'Downloadable Product Permission' || text === 'Order Notes') {
                $(this).closest('.dokan-panel').parent().hide();
            }
        });

        // Specific IDs just in case
        $('#dokan-order-notes').closest('.dokan-panel').parent().hide();
    });
    </script>
    <?php
}
