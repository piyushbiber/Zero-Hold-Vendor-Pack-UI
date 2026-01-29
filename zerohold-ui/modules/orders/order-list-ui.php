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
 * Enqueue CSS/JS to handle table transformation
 */
add_action( 'wp_footer', 'zh_order_list_ui_script' );
function zh_order_list_ui_script() {
    if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() ) {
        return;
    }

    // Only target the order listing page (exclude single order details)
    // Checking for 'orders' query var or param
    $is_orders_page = isset( $_GET['orders'] ) || get_query_var( 'orders' );
    
    // Fallback: Check URL if query vars are not yet populated in a clean way
    if ( ! $is_orders_page && strpos( $_SERVER['REQUEST_URI'], '/orders' ) !== false ) {
        $is_orders_page = true;
    }

    if ( ! $is_orders_page || isset( $_GET['order_id'] ) ) {
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

        /* REPLACED BY CONSOLIDATED SHIELD IN ORDER-ACTIONS.PHP */
    </style>
    <script>
    jQuery(function($) {
        function alignOrderImages() {
            const $table = $('.dokan-table.dokan-table-striped');
            if (!$table.length) return;

            // STRATEGIC GUARD: Only run if hidden image markers are present
            if (!$('.zh-order-image-hidden').length) {
                return;
            }

            // 1. Ensure Table Header has "IMAGE" column
            if (!$table.find('thead .zh-order-img-col').length) {
                const $headerRow = $table.find('thead tr');
                const $imgHeader = $('<th class="zh-order-img-col">IMAGE</th>');
                
                // Find Checkbox column to insert AFTER
                const $cbHeader = $headerRow.find('th#cb, th.check-column').eq(0);
                if ($cbHeader.length) {
                    $imgHeader.insertAfter($cbHeader);
                } else {
                    // Fallback to inserting BEFORE "Order"
                    const $orderHeader = $headerRow.find('th').filter(function() {
                        return $(this).text().trim().toLowerCase() === 'order';
                    });
                    if ($orderHeader.length) {
                        $imgHeader.insertBefore($orderHeader);
                    }
                }

                // SECURITY GUARD: Ensure Dual Headers (VIEW + ACTION) are visible
                let $actionHeader = $headerRow.find('th').filter(function() {
                    let t = $(this).text().trim().toLowerCase();
                    return t === 'action' || t === 'process order';
                });
                $actionHeader.css({ 'display': 'table-cell', 'visibility': 'visible' });

                let $viewHeader = $headerRow.find('th.zh-view-col');
                $viewHeader.css({ 'display': 'table-cell', 'visibility': 'visible' });
            }

            // 2. Process each row
            $table.find('tbody tr').each(function() {
                const $row = $(this);
                if ($row.find('.zh-order-img-col').length) return;

                // Find Checkbox cell to insert AFTER
                const $cbCell = $row.find('th.check-column, td.dokan-order-select').eq(0);
                const $orderCell = $row.find('.dokan-order-id');

                const $hiddenData = $row.find('.zh-order-image-hidden');
                let imgHtml = '';
                
                if ($hiddenData.length) {
                    imgHtml = $hiddenData.html();
                } else {
                    imgHtml = '<img src="<?php echo esc_url(wc_placeholder_img_src()); ?>" class="zh-order-thumb">';
                }

                // Create Image Cell
                const $imgCell = $(`<td class="zh-order-img-col" style="width:55px; text-align:center;">${imgHtml}</td>`);
                
                if ($cbCell.length) {
                    $imgCell.insertAfter($cbCell);
                } else if ($orderCell.length) {
                    $imgCell.insertBefore($orderCell);
                }
            });
        }

        // Run on load
        alignOrderImages();
        
        // Run after AJAX shifts
        $(document).ajaxComplete(function() {
            setTimeout(alignOrderImages, 100);
        });
    });
    </script>
    <?php
}
