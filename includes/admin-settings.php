<?php
/**
 * ZeroHold Vendor Pack UI - Admin Settings
 * 
 * Provides an options page for configuring vendor rejection penalties.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Register Settings Menu
add_action( 'admin_menu', 'zh_vendor_pack_settings_menu' );
function zh_vendor_pack_settings_menu() {
    add_menu_page(
        __( 'ZeroHold', 'zerohold' ),
        __( 'ZeroHold', 'zerohold' ),
        'manage_options',
        'zerohold-settings',
        'zh_vendor_pack_settings_page',
        'dashicons-shield',
        58
    );

    add_submenu_page(
        'zerohold-settings',
        __( 'Rejection Policy', 'zerohold' ),
        __( 'Rejection Policy', 'zerohold' ),
        'manage_options',
        'zerohold-rejection-policy',
        'zh_vendor_pack_rejection_policy_page'
    );
    
    // Remove the duplicate first item if needed, or keep it as dashboard
}

// Register Settings
add_action( 'admin_init', 'zh_vendor_pack_register_settings' );
function zh_vendor_pack_register_settings() {
    // Fixed Penalty Amount
    register_setting( 'zh_rejection_policy_group', 'zh_rejection_penalty_fixed', array(
        'type' => 'number',
        'sanitize_callback' => 'floatval',
        'default' => 0
    ) );

    // Percentage Penalty
    register_setting( 'zh_rejection_policy_group', 'zh_rejection_penalty_percent', array(
        'type' => 'number',
        'sanitize_callback' => 'floatval',
        'default' => 25
    ) );
}

// Main Settings Page Callback
function zh_vendor_pack_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php _e( 'ZeroHold Vendor Settings', 'zerohold' ); ?></h1>
        <p><?php _e( 'Configure general settings for the ZeroHold platform.', 'zerohold' ); ?></p>
        <hr>
        <h3><?php _e( 'Quick Links', 'zerohold' ); ?></h3>
        <ul>
            <li><a href="<?php echo admin_url( 'admin.php?page=zerohold-rejection-policy' ); ?>">Set Rejection Penalties</a></li>
        </ul>
    </div>
    <?php
}

// Rejection Policy Page Callback
function zh_vendor_pack_rejection_policy_page() {
    ?>
    <div class="wrap">
        <h1><?php _e( 'Vendor Rejection Policy', 'zerohold' ); ?></h1>
        <p><?php _e( 'Configure the penalties applied to vendors when they reject a paid order.', 'zerohold' ); ?></p>
        
        <form method="post" action="options.php">
            <?php settings_fields( 'zh_rejection_policy_group' ); ?>
            <?php do_settings_sections( 'zh_rejection_policy_group' ); ?>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php _e( 'Fixed Penalty Amount (₹)', 'zerohold' ); ?></th>
                    <td>
                        <input type="number" step="0.01" min="0" name="zh_rejection_penalty_fixed" value="<?php echo esc_attr( get_option( 'zh_rejection_penalty_fixed', 0 ) ); ?>" />
                        <p class="description"><?php _e( 'A flat fee charged for every rejection (e.g. ₹50). Set to 0 to disable.', 'zerohold' ); ?></p>
                    </td>
                </tr>
                
                <tr valign="top">
                    <th scope="row"><?php _e( 'Penalty Percentage (%)', 'zerohold' ); ?></th>
                    <td>
                        <input type="number" step="0.01" min="0" max="100" name="zh_rejection_penalty_percent" value="<?php echo esc_attr( get_option( 'zh_rejection_penalty_percent', 25 ) ); ?>" />
                        <p class="description"><?php _e( 'Percentage of the ORDER TOTAL to charge as a penalty (e.g. 25%).', 'zerohold' ); ?></p>
                    </td>
                </tr>
            </table>
            
            <div style="margin-top: 20px; padding: 15px; background: #fff; border-left: 4px solid #00a0d2; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <h4><?php _e( 'Calculation Logic', 'zerohold' ); ?></h4>
                <p>
                    <strong>Total Penalty = Fixed Amount + ( Order Total × Percentage )</strong><br>
                    <em>Example: If Fixed = ₹50 and Percentage = 10% on a ₹100 order:</em><br>
                    Penalty = ₹50 + (₹100 × 0.10) = <strong>₹60</strong>
                </p>
            </div>
            
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
