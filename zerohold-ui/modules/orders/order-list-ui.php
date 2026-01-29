<?php
/**
 * ZeroHold Order List UI Customizations
 * Adds product images to the Dokan Vendor Order Listing table.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Inject Product Images into Order List
 * Fetches the parent product image for variations and outputs hidden data for JS processing.
 */
add_action( 'dokan_order_listing_row_before_action_field', 'zh_inject_order_product_image_data' );
function zh_inject_order_product_image_data( $order ) {
    if ( ! $order ) return;
    
    $items = $order->get_items();
    $first_item = reset( $items );
    if ( ! $first_item ) return;

    $product = $first_item->get_product();
    
    // Explicit Parent Resolution for ZeroHold model (variations have no images)
    if ( $product && $product->is_type('variation') ) {
        $parent_product = wc_get_product( $product->get_parent_id() );
        if ( $parent_product ) {
            $product = $parent_product;
        }
    }

    $img_kses = apply_filters(
        'dokan_product_image_attributes', [
            'img' => [
                'alt'         => [],
                'class'       => [],
                'height'      => [],
                'src'         => [],
                'width'       => [],
                'srcset'      => [],
                'data-srcset' => [],
                'data-src'    => [],
            ],
        ]
    );

    ob_start();
    // Wrap in a hidden <td> so the browser doesn't kick the div out of the <tr>
    ?>
    <td class="zh-order-image-marker" style="display:none !important;">
        <div class="zh-order-image-hidden">
            <?php if ( $product ) : ?>
                <a href="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>">
                    <?php echo wp_kses( $product->get_image( 'shop_thumbnail', [ 'class' => 'zh-order-thumb', 'title' => '' ] ), $img_kses ); ?>
                </a>
            <?php else : ?>
                <?php echo wp_kses_post( wc_placeholder_img( 'shop_thumbnail', [ 'class' => 'zh-order-thumb' ] ) ); ?>
            <?php endif; ?>
        </div>
    </td>
    <?php
    echo ob_get_clean();
}

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
