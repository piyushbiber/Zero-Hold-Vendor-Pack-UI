<?php
/**
 * ZeroHold Order List UI Customizations
 * Adds product images to the Dokan Vendor Order Listing table.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ZeroHold Order List UI Customizations
 * All UI injection logic moved to order-actions.php to ensure a single source of truth 
 * and prevent table crippling/structural shifts.
 */

/**
 * Enqueue CSS to handle table styling (Alignment logic moved to order-actions.php)
 */
add_action( 'wp_footer', function() {
    if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() ) {
        return;
    }
    ?>
    <style>
        .zh-order-img-col {
            width: 60px;
            text-align: center;
        }
        .zh-order-thumb {
            height: 40px;
            width: auto;
            border-radius: 4px;
            border: 1px solid #eee;
            object-fit: cover;
        }
        @media (max-width: 768px) {
            .zh-order-img-col {
                display: none;
            }
        }
    </style>
    <?php
});
