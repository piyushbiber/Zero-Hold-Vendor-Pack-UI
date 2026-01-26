<?php
/**
 * ZeroHold Order Actions
 * Migrated from snippets for Accept/Reject functionality.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 1️⃣ Physical (vendor) products → after payment → order must go to On Hold
 */
add_action( 'woocommerce_payment_complete', 'zh_hold_physical_vendor_orders', 20 );
function zh_hold_physical_vendor_orders( $order_id ) {
    if ( ! $order_id ) return;

    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    // Safety: only act if order is paid
    if ( ! $order->is_paid() ) return;

    // Handle Dokan sub-orders
    $sub_orders = get_children( array( 'post_parent' => $order_id, 'post_type' => 'shop_order' ) );
    $orders_to_check = ! empty( $sub_orders ) ? $sub_orders : array( $order );

    foreach ( $orders_to_check as $order_obj ) {
        $check_order = ( $order_obj instanceof WC_Order ) ? $order_obj : wc_get_order( $order_obj->ID );
        if ( ! $check_order ) continue;

        $has_physical_vendor_product = false;

        foreach ( $check_order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;

            if ( $product->needs_shipping() ) {
                $vendor_id = get_post_field( 'post_author', $product->get_id() );
                if ( $vendor_id && $vendor_id != 1 ) {
                    $has_physical_vendor_product = true;
                    break;
                }
            }
        }

        if ( $has_physical_vendor_product && $check_order->get_status() !== 'on-hold' ) {
            $check_order->update_status(
                'on-hold',
                __( 'Paid order placed on hold awaiting vendor acceptance.', 'zerohold' )
            );
        }
    }
}

/**
 * 2️⃣ Allow Dokan vendors to see on-hold orders
 */
add_filter( 'dokan_get_vendor_orders_args', 'zh_show_on_hold_orders_to_vendor' );
function zh_show_on_hold_orders_to_vendor( $args ) {
    if ( isset( $args['post_status'] ) && is_array( $args['post_status'] ) ) {
        if ( ! in_array( 'wc-on-hold', $args['post_status'], true ) ) {
            $args['post_status'][] = 'wc-on-hold';
        }
    }
    return $args;
}

/**
 * 3️⃣ ACCEPT + REJECT UI (REPLACES OLD ACCEPT BUTTON)
 */
add_action( 'dokan_order_detail_after_order_items', 'zh_vendor_accept_reject_ui' );
function zh_vendor_accept_reject_ui() {
    if ( ! dokan_is_seller_dashboard() ) return;
    if ( ! isset( $_GET['order_id'] ) ) return;

    $order_id = absint( $_GET['order_id'] );
    $order    = wc_get_order( $order_id );
    if ( ! $order ) return;

    $status   = $order->get_status();
    $accepted = $order->get_meta('_zh_vendor_accepted');
    $rejected = $order->get_meta('_zh_vendor_rejected');

    // SHOW ACCEPT + REJECT (ON HOLD ONLY)
    if ( $status === 'on-hold' && $accepted !== 'yes' && $rejected !== 'yes' ) {
        // Verify this order belongs to the current vendor
        $vendor_id = dokan_get_current_user_id();
        $order_seller_id = dokan_get_seller_id_by_order( $order_id );
        
        if ( (int) $vendor_id !== (int) $order_seller_id ) {
            return;
        }

        ?>
        <div class="zh-action-wrap" style="margin-top:15px; display:flex; gap:10px;">
            <?php wp_nonce_field( 'zh_order_action_nonce', 'zh_order_nonce' ); ?>
            <button type="button"
                class="dokan-btn dokan-btn-success zh-accept-order-btn"
                data-order-id="<?php echo esc_attr($order_id); ?>">
                ✔ Accept Order
            </button>
            <button type="button"
                class="dokan-btn dokan-btn-danger zh-reject-order-btn"
                data-order-id="<?php echo esc_attr($order_id); ?>">
                ✖ Reject Order
            </button>
        </div>

        <div class="zh-reject-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:999999; display:flex; align-items:center; justify-content:center;">
            <div style="background:#fff;padding:20px;border-radius:6px;max-width:420px;width:90%;">
                <h4>⚠️ Reject Order Confirmation</h4>
                <p>
                    Rejecting this order will <b>refund the full payment</b> to the customer.
                    This action <b>cannot be undone</b>.
                </p>
                <textarea class="zh-reject-reason"
                    placeholder="Reason for rejection (optional)"
                    style="width:100%;margin-top:10px; min-height:80px;"></textarea>
                <div style="margin-top:15px;text-align:right; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" class="dokan-btn zh-cancel-reject">Cancel</button>
                    <button type="button" class="dokan-btn dokan-btn-danger zh-confirm-reject"
                        data-order-id="<?php echo esc_attr($order_id); ?>">
                        Confirm Reject
                    </button>
                </div>
            </div>
        </div>
        <script>
            // Ensure visual hide as inline style display:none might be overridden if not careful
            jQuery('.zh-reject-modal').hide();
        </script>
        <?php
        return;
    }

    // ACCEPTED MESSAGE
    if ( $accepted === 'yes' ) {
        ?>
        <div style="margin-top:15px; padding: 10px; background: #e8f5e9; border-radius: 4px;">
            <div style="color:#2e7d32;font-weight:600;">✔ Order accepted</div>
            <div style="color:#f9a825;">⏳ Waiting for shipping label</div>
        </div>
        <?php
        return;
    }

    // REJECTED MESSAGE
    if ( $rejected === 'yes' ) {
        ?>
        <div style="margin-top:15px; padding: 10px; background: #ffebee; border-radius: 4px;">
            <div style="color:#c62828;font-weight:600;">✖ Order rejected</div>
            <div style="color:#2e7d32;">💰 Payment refunded to customer</div>
        </div>
        <?php
    }
}

/**
 * 4️⃣ ACCEPT AJAX HANDLER
 */
add_action( 'wp_ajax_zh_vendor_accept_order', 'zh_vendor_accept_order_ajax' );
add_action( 'wp_ajax_zh_order_accept', 'zh_vendor_accept_order_ajax' ); // Alias for spec compatibility
function zh_vendor_accept_order_ajax() {
    // Check both potential field names for security
    $nonce = $_POST['security'] ?? ($_POST['_wpnonce'] ?? '');
    if ( ! wp_verify_nonce( $nonce, 'zh_order_action_nonce' ) ) {
        wp_send_json_error( 'Invalid security token' );
    }

    if ( empty($_POST['order_id']) ) {
        wp_send_json_error('Invalid request');
    }

    $order_id = absint($_POST['order_id']);
    $order    = wc_get_order($order_id);

    if ( ! $order || $order->get_status() !== 'on-hold' ) {
        wp_send_json_error('Order not eligible');
    }

    // Permission Check
    $vendor_id = dokan_get_current_user_id();
    $order_seller_id = dokan_get_seller_id_by_order( $order_id );
    if ( (int) $vendor_id !== (int) $order_seller_id ) {
        wp_send_json_error('Unauthorized access');
    }

    $order->update_meta_data('_zh_vendor_accepted', 'yes');
    $order->update_status('processing', 'Vendor accepted the order.');
    $order->save();

    wp_send_json_success();
}

/**
 * 5️⃣ REJECT AJAX HANDLER (Razorpay + Wallet support)
 */
add_action( 'wp_ajax_zh_vendor_reject_order', 'zh_vendor_reject_order_ajax' );
function zh_vendor_reject_order_ajax() {
    check_ajax_referer( 'zh_order_action_nonce', 'security' );

    if ( empty( $_POST['order_id'] ) ) {
        wp_send_json_error( 'Invalid request' );
    }

    $order_id = absint( $_POST['order_id'] );
    $reason   = sanitize_textarea_field( $_POST['reason'] ?? '' );
    $order    = wc_get_order( $order_id );

    if ( ! $order ) {
        wp_send_json_error( 'Order not found' );
    }

    // Permission Check
    $vendor_id = dokan_get_current_user_id();
    $order_seller_id = dokan_get_seller_id_by_order( $order_id );
    if ( (int) $vendor_id !== (int) $order_seller_id ) {
        wp_send_json_error('Unauthorized access');
    }

    // 🔐 Safety checks
    if ( $order->get_meta( '_zh_vendor_accepted' ) === 'yes' ) {
        wp_send_json_error( 'Order already accepted' );
    }

    if ( $order->get_meta( '_zh_vendor_rejected' ) === 'yes' ) {
        wp_send_json_error( 'Order already rejected' );
    }

    if ( $order->get_status() !== 'on-hold' ) {
        wp_send_json_error( 'Order cannot be rejected' );
    }

    $payment_method = $order->get_payment_method();

    // CASE 1: TERAWALLET PAYMENT
    if ( $payment_method === 'wallet' ) {
        if ( ! function_exists( 'woo_wallet' ) ) {
            wp_send_json_error( 'TeraWallet not active' );
        }

        $customer_id = $order->get_user_id();
        $amount      = (float) $order->get_total();

        woo_wallet()->wallet->credit(
            $customer_id,
            $amount,
            sprintf( __( 'Refund for Order #%s (Vendor rejected)', 'zerohold' ), $order_id )
        );

        $order->update_meta_data( '_zh_vendor_rejected', 'yes' );
        $order->update_meta_data( '_zh_vendor_reject_reason', $reason );
        $order->set_status( 'refunded', __( 'Vendor rejected order. Amount refunded to customer wallet.', 'zerohold' ) );
        $order->save();

        wp_send_json_success( 'Order rejected and wallet refunded' );
    }

    // CASE 2: RAZORPAY PAYMENT
    if ( $payment_method === 'razorpay' ) {
        $payment_id = $order->get_meta( '_razorpay_payment_id' );
        if ( ! $payment_id ) {
            wp_send_json_error( 'Razorpay payment ID not found' );
        }

        $settings = get_option( 'woocommerce_razorpay_settings' );
        $key_id   = $settings['key_id'] ?? '';
        $secret   = $settings['key_secret'] ?? '';

        if ( ! $key_id || ! $secret ) {
            wp_send_json_error( 'Razorpay credentials missing' );
        }

        $auth = base64_encode( $key_id . ':' . $secret );
        $response = wp_remote_post(
            "https://api.razorpay.com/v1/payments/$payment_id/refund",
            [
                'headers' => [ 'Authorization' => 'Basic ' . $auth ],
                'timeout' => 20,
            ]
        );

        if ( is_wp_error( $response ) ) {
            $order->add_order_note( 'Vendor attempted rejection. Razorpay refund remote call failed.' );
            wp_send_json_error( 'Refund request failed. Please contact admin.' );
        }

        $body = wp_remote_retrieve_body( $response );
        $result = json_decode( $body );

        if ( wp_remote_retrieve_response_code( $response ) !== 200 || isset( $result->error ) ) {
            $err_msg = isset( $result->error->description ) ? $result->error->description : 'Razorpay API error';
            $order->add_order_note( 'Vendor attempted rejection. Razorpay refund failed: ' . $err_msg );
            wp_send_json_error( 'Razorpay refund failed: ' . $err_msg );
        }

        $order->update_meta_data( '_zh_vendor_rejected', 'yes' );
        $order->update_meta_data( '_zh_vendor_reject_reason', $reason );
        $order->set_status( 'refunded', __( 'Vendor rejected order. Payment refunded via Razorpay.', 'zerohold' ) );
        $order->save();

        wp_send_json_success( 'Order rejected and refunded' );
    }

    // CASE 3: NEW WALLET SYSTEM (wps_wcb_wallet_payment_gateway)
    if ( $payment_method === 'wps_wcb_wallet_payment_gateway' ) {
        // Attempt Native WooCommerce Refund first (Best Practice)
        // This triggers the gateway's process_refund() method if implemented
        $refund = wc_create_refund( array(
            'amount'         => $order->get_total(),
            'reason'         => 'Order rejected by vendor: ' . $reason,
            'order_id'       => $order_id,
            'refund_payment' => true // Trigger gateway API
        ) );

        if ( is_wp_error( $refund ) ) {
            $order->add_order_note( 'Vendor reject failed: ' . $refund->get_error_message() );
            // If native refund fails, it might be that the gateway doesn't support it or there's an error.
            // We can add a specialized manual fallback here if we knew the class, 
            // but for now we report the error to avoid data loss.
            wp_send_json_error( 'Refund failed: ' . $refund->get_error_message() );
        }

        $order->update_meta_data( '_zh_vendor_rejected', 'yes' );
        $order->update_meta_data( '_zh_vendor_reject_reason', $reason );
        
        // Ensure status update if not handled by refund
        if ( $order->get_status() !== 'refunded' ) {
            $order->set_status( 'refunded', __( 'Vendor rejected order. Payment refunded to Wallet.', 'zerohold' ) );
        }
        $order->save();

        wp_send_json_success( 'Order rejected and wallet refunded' );
    }

    wp_send_json_error( 'Unsupported payment method (' . $payment_method . ')' );
}

/**
 * 6️⃣ LIST ACTION COLUMNS (Separate VIEW and Process Order)
 */

// A. Inject VIEW Column Header
add_action( 'dokan_order_listing_header_before_action_column', function() {
    echo '<th class="zh-view-col" style="width: 80px; text-align: center;">' . esc_html__( 'VIEW', 'zerohold' ) . '</th>';
});

// B. Inject VIEW Column Data (The Eye Icon)
add_action( 'dokan_order_listing_row_before_action_field', function( $order ) {
    $view_url = wp_nonce_url( add_query_arg( [ 'order_id' => $order->get_id() ], dokan_get_navigation_url( 'orders' ) ), 'dokan_view_order' );
    ?>
    <td class="zh-view-col" style="text-align: center;" data-title="<?php esc_attr_e( 'View', 'zerohold' ); ?>">
        <a class="dokan-btn dokan-btn-default dokan-btn-sm tips" 
           href="<?php echo esc_url( $view_url ); ?>" 
           data-toggle="tooltip" 
           data-placement="top" 
           title="<?php esc_attr_e( 'View', 'dokan-lite' ); ?>">
           <i class="far fa-eye"></i>
        </a>
    </td>
    <?php
});

// C. Update ACTION Column (Add Accept/Reject, Remove View)
add_filter( 'woocommerce_admin_order_actions', 'zh_add_list_action_buttons', 9999, 2 );
function zh_add_list_action_buttons( $actions, $order ) {
    if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() ) {
        return $actions;
    }

    // 🛑 CRITICAL: Clear all native Dokan actions to prevent "Ghost UI"
    $actions = [];

    // 🏷️ SHIPPING BUTTONS NOW HANDLED BY ZSS PLUGIN
    // ZSS injects Generate/Download Label buttons via dokan_order_row_actions filter

    // 📦 GUARDS FOR ACCEPT/REJECT (Status: On-Hold)
    $status = $order->get_status();
    $accepted = $order->get_meta('_zh_vendor_accepted');
    $rejected = $order->get_meta('_zh_vendor_rejected');

    // Display "Order rejected" if status is refunded or rejected meta is yes
    if ( $status === 'refunded' || $rejected === 'yes' ) {
        $actions['rejected_msg'] = [
            'url'    => '#',
            'name'   => __( 'Order rejected', 'dokan-lite' ),
            'action' => 'rejected-msg',
            'icon'   => '<span class="zh-rejected-msg">Order rejected</span>',
        ];
        return $actions;
    }

    if ( $status === 'on-hold' && $accepted !== 'yes' && $rejected !== 'yes' ) {
        $actions['accept'] = [
            'url'    => '#',
            'name'   => __( 'Accept Order', 'dokan-lite' ),
            'action' => 'accept',
            'icon'   => '<span class="zh-accept zh-action-btn">ACCEPT</span>',
        ];

        $actions['reject'] = [
            'url'    => '#',
            'name'   => __( 'Reject Order', 'dokan-lite' ),
            'action' => 'reject',
            'icon'   => '<span class="zh-reject zh-action-btn">REJECT</span>',
        ];
    }

    return $actions;
}

/**
 * 7️⃣ JAVASCRIPT (ACCEPT + REJECT UX & UI RENAMING)
 */
add_action( 'wp_footer', function () {
    if ( ! function_exists('dokan_is_seller_dashboard') || ! dokan_is_seller_dashboard() ) return;
    
    // Inject Nonce and Modal Container globally for dashboard
    ?>
    <div id="zh-global-action-assets">
        <?php wp_nonce_field( 'zh_order_action_nonce', 'zh_order_nonce' ); ?>
        
        <div class="zh-reject-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:999999; align-items:center; justify-content:center;">
            <div style="background:#fff;padding:20px;border-radius:6px;max-width:420px;width:90%;">
                <h4 style="margin-top:0;">⚠️ Reject Order Confirmation</h4>
                <p>
                    Rejecting this order will <b>refund the full payment</b> to the customer.
                    This action <b>cannot be undone</b>.
                </p>
                <textarea class="zh-reject-reason"
                    placeholder="Reason for rejection (optional)"
                    style="width:100%;margin-top:10px; min-height:80px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="margin-top:15px;text-align:right; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" class="dokan-btn zh-cancel-reject">Cancel</button>
                    <button type="button" class="dokan-btn dokan-btn-danger zh-confirm-reject" data-order-id="">
                        Confirm Reject
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    jQuery(function($){
        // Rename Action Column
        function zhRenameActionHeader() {
            $('.dokan-table-striped thead th').each(function(){
                if ($(this).text().trim() === 'Action') {
                    $(this).text('Process order');
                }
            });
        }
        zhRenameActionHeader();
        $(document).ajaxComplete(zhRenameActionHeader);

        // Helper: Get Order ID from button or row
        function getOrderId($el) {
            let id = $el.data('order-id');
            if (!id) {
                // Try to find it from the row (Dokan list)
                let $row = $el.closest('tr');
                // Check if it's the single order page or list
                if ($row.length) {
                    // Try dokan-order-id cell text, or common data attrs
                    let orderText = $row.find('.dokan-order-id a').text().trim() || $row.find('.dokan-order-id').text().trim();
                    id = orderText.replace('#', '').replace('Order ', '');
                }
            }
            return id;
        }

        // ACCEPT
        $(document).on('click', '.zh-accept-order-btn, .dokan-order-action a.accept, .zh-accept', function(e){
            e.preventDefault();
            let btn = $(this);
            let id = getOrderId(btn);
            let nonce = $('#zh_order_nonce').val();
            let $container = btn.closest('.dokan-order-action, .zh-action-wrap');
            
            if (!id || btn.hasClass('disabled')) return;
            
            // STEP 1: Aggressive Cleanup (Ghost-Busting)
            // 1. Hide the container children (Optimistic)
            $container.children().hide(); 
            // 2. Suppress any native icons that might be inside hidden links
            $container.find('i, span:not(.zh-accepted-msg)').css('display', 'none !important');

            // Show temporary message
            let $msg = $('<span class="zh-accepted-msg">✔ Order Accepted</span>');
            $container.append($msg);

            // Execute AJAX
            $.post('<?php echo admin_url('admin-ajax.php'); ?>',{
                action: 'zh_order_accept', // Using the spec action name
                order_id: id,
                _wpnonce: nonce // Using the spec field name
            }, function(res) {
                if (res.success) {
                    // Update Status Badge optimistically (Consistency fix)
                    let $row = $container.closest('tr');
                    let $statusBadge = $row.find('.dokan-order-status span');
                    if ($statusBadge.length) {
                        $statusBadge.removeClass('dokan-label-on-hold')
                                    .addClass('dokan-label-processing')
                                    .text('Processing');
                    }

                    // Reload page to show shipping buttons from ZSS plugin
                    setTimeout(function(){
                        location.reload();
                    }, 1000);
                } else {
                    alert(res.data || 'Error accepting order');
                    $msg.remove();
                    $rowButtons.show();
                }
            });
        });

        // REJECT
        $(document).on('click', '.zh-reject-order-btn, .dokan-order-action a.reject, .zh-reject', function(e){
            e.preventDefault();
            let btn = $(this);
            let id = getOrderId(btn);
            if (!id) return;

            $('.zh-confirm-reject').data('order-id', id);
            $('.zh-reject-modal').fadeIn().css('display', 'flex');
        });

        // SHIPPING LABEL JAVASCRIPT NOW HANDLED BY ZSS PLUGIN

        // CANCEL REJECT
        $(document).on('click', '.zh-cancel-reject', function(){
            $('.zh-reject-modal').fadeOut();
        });

        // CONFIRM REJECT
        $(document).on('click', '.zh-confirm-reject', function(){
            let btn = $(this);
            let id = btn.data('order-id');
            let reason = $('.zh-reject-reason').val();
            let nonce = $('#zh_order_nonce').val();

            if (btn.prop('disabled')) return;
            btn.prop('disabled', true).text('⌛ Processing...');

            $.post('<?php echo admin_url('admin-ajax.php'); ?>',{
                action: 'zh_vendor_reject_order',
                order_id: id,
                reason: reason,
                security: nonce
            }, function(res) {
                if (res.success) {
                    $('.zh-reject-modal').fadeOut();
                    
                    // Find the buttons cell in the list
                    let $row = $('.dokan-table-striped').find('tr').filter(function(){
                        return getOrderId($(this).find('.dokan-order-id')) == id;
                    });
                    
                    let $actionCell = $row.find('.dokan-order-action, .zh-action-wrap');
                    if ($actionCell.length) {
                        $actionCell.empty().append('<span class="zh-rejected-msg">Order rejected</span>');
                        
                        // Also update status badge to Refunded
                        let $statusBadge = $row.find('.dokan-order-status span');
                        if ($statusBadge.length) {
                            $statusBadge.removeClass('dokan-label-on-hold')
                                        .addClass('dokan-label-refunded')
                                        .text('Refunded');
                        }
                    }

                    setTimeout(function(){
                        location.reload();
                    }, 1500);
                } else {
                    alert(res.data || 'Error rejecting order');
                    btn.prop('disabled', false).text('Confirm Reject');
                }
            });
        });
    });
    </script>
    <?php
});
