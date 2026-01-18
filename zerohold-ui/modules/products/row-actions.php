<?php
if (!defined('ABSPATH')) exit;

/**
 * Step 2: RELOCATED Row Actions (Status Column)
 * Positioned under the Status label to match "Edit Stock" style.
 */
add_action('dokan_product_list_table_after_column_content_status', function($product) {
    if (!$product) return;
    
    $status = $product->get_status();
    $pid = $product->get_id();

    // Style matches "Edit Stock" button
    $btn_style = 'display: inline-block; margin-top: 5px; background: #f3f3f3; color: #333; border: 1px solid #ddd; padding: 2px 6px; font-size: 10px; border-radius: 3px; text-decoration: none; font-weight: 600; text-transform: uppercase; line-height: 1;';

    if ($status === 'publish') {
        printf(
            '<div><a href="javascript:void(0);" class="zh-action-inactive" data-id="%d" style="%s">%s</a></div>',
            $pid,
            $btn_style,
            __('Make Inactive', 'zerohold')
        );
    } elseif ($status === 'private') {
        printf(
            '<div><a href="javascript:void(0);" class="zh-action-reactivate" data-id="%d" style="%s">%s</a></div>',
            $pid,
            $btn_style,
            __('Reactivate', 'zerohold')
        );
    }
}, 10, 1);

/**
 * Enqueue JS for AJAX triggers in Dashboard
 */
add_action('wp_enqueue_scripts', function() {
    if (!function_exists('dokan_is_seller_dashboard') || !dokan_is_seller_dashboard()) return;

    ob_start();
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Handle Make Inactive
        $(document).on('click', '.zh-action-inactive', function(e) {
            e.preventDefault();
            const pid = $(this).data('id');
            if (!confirm('Are you sure you want to make this product inactive? It will be hidden from the marketplace.')) return;

            $.post(dokan.ajaxurl, {
                action: 'zh_stock_inactive',
                pid: pid,
                nonce: '<?php echo wp_create_nonce("zh_vendor_stock_nonce"); ?>'
            }, function(resp) {
                if (resp.success) {
                    window.location.reload();
                } else {
                    alert(resp.data.msg);
                }
            });
        });

        // Handle Reactivate
        $(document).on('click', '.zh-action-reactivate', function(e) {
            e.preventDefault();
            const pid = $(this).data('id');
            
            $.post(dokan.ajaxurl, {
                action: 'zh_stock_reactivate',
                pid: pid,
                nonce: '<?php echo wp_create_nonce("zh_vendor_stock_nonce"); ?>'
            }, function(resp) {
                if (resp.success) {
                    window.location.reload();
                } else {
                    alert(resp.data.msg);
                }
            });
        });
    });
    </script>
    <?php
    $js = ob_get_clean();
    wp_add_inline_script('dokan-script', str_replace(['<script>', '</script>'], '', $js));
});
