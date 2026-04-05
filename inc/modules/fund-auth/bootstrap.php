<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WOO_ALIPAY_FUNDAUTH_PLUGIN_FILE' ) ) {
    define( 'WOO_ALIPAY_FUNDAUTH_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WOO_ALIPAY_FUNDAUTH_PLUGIN_PATH' ) ) {
    define( 'WOO_ALIPAY_FUNDAUTH_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WOO_ALIPAY_FUNDAUTH_PLUGIN_URL' ) ) {
    define( 'WOO_ALIPAY_FUNDAUTH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Ensure WooCommerce and core Woo Alipay are active before loading classes
add_action( 'plugins_loaded', function () {
    if ( class_exists( 'WC_Payment_Gateway' ) && class_exists( 'Woo_Alipay' ) ) {
        $gateway_file = WOO_ALIPAY_FUNDAUTH_PLUGIN_PATH . 'inc/class-wc-alipay-fund-auth.php';
        if ( file_exists( $gateway_file ) ) {
            require_once $gateway_file;
        }
    }
}, 15 );

// Register gateway into WooCommerce
add_filter( 'woocommerce_payment_gateways', function( $methods ) {
    if ( class_exists( 'WC_Alipay_Fund_Auth' ) ) {
        $methods[] = 'WC_Alipay_Fund_Auth';
    }
    return $methods;
}, 11, 1 );

// Blocks registration
add_action(
    'woocommerce_blocks_payment_method_type_registration',
    function( $registry ) {
        if ( ! class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
            return;
        }

        if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
            return;
        }

        $file = WOO_ALIPAY_FUNDAUTH_PLUGIN_PATH . 'inc/class-wc-alipay-fund-auth-blocks-support.php';
        if ( file_exists( $file ) ) {
            require_once $file;
        }

        if ( class_exists( 'WC_Alipay_Fund_Auth_Blocks_Support' ) ) {
            $registry->register( new WC_Alipay_Fund_Auth_Blocks_Support() );
        }
    },
    10,
    1
);

// Handle admin capture
add_action( 'admin_post_woo_alipay_fund_auth_capture', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-fund-auth' ) );
    }
    check_admin_referer( 'woo_alipay_fund_auth_capture' );
    $order_id = absint( $_POST['order_id'] ?? 0 );
    $amount   = isset( $_POST['amount'] ) ? (float) $_POST['amount'] : 0;
    $order    = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_safe_redirect( wp_get_referer() );
        exit;
    }
    $res = WC_Alipay_Fund_Auth::capture_order( $order_id, $amount );
    if ( is_wp_error( $res ) ) {
        $order->add_order_note( __( 'Capture failed: ', 'woo-alipay-fund-auth' ) . $res->get_error_message() );
    }
    wp_safe_redirect( wp_get_referer() );
    exit;
} );

// Handle admin unfreeze
add_action( 'admin_post_woo_alipay_fund_auth_unfreeze', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-fund-auth' ) );
    }
    check_admin_referer( 'woo_alipay_fund_auth_unfreeze' );
    $order_id = absint( $_POST['order_id'] ?? 0 );
    $order    = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_safe_redirect( wp_get_referer() );
        exit;
    }
    $res = WC_Alipay_Fund_Auth::unfreeze_order( $order_id );
    if ( is_wp_error( $res ) ) {
        $order->add_order_note( __( 'Unfreeze failed: ', 'woo-alipay-fund-auth' ) . $res->get_error_message() );
    }
    wp_safe_redirect( wp_get_referer() );
    exit;
} );

// Auto capture on order complete (if enabled)
add_action( 'woocommerce_order_status_completed', function( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->is_paid() ) { return; }
    if ( 'alipay_fund_auth' !== $order->get_payment_method() ) { return; }
    $settings = get_option( 'woocommerce_alipay_fund_auth_settings', array() );
    if ( empty( $settings['capture_on_complete'] ) || 'yes' !== $settings['capture_on_complete'] ) { return; }
    // Determine auto capture amount per settings
    $auth_amt       = (float) $order->get_meta( '_alipay_auth_amount' );
    $captured_total = (float) $order->get_meta( '_alipay_captured_total', true );
    $remaining      = max( 0.0, $auth_amt - $captured_total );
    if ( $remaining <= 0 ) { return; }
    $mode = $settings['auto_capture_amount_mode'] ?? 'authorized';
    if ( 'order_total' === $mode ) {
        $amount = (float) $order->get_total();
        // do not exceed remaining
        $amount = min( $amount, $remaining );
    } elseif ( 'remaining' === $mode ) {
        $amount = $remaining;
    } else {
        // authorized
        $amount = ( $captured_total > 0 ) ? $remaining : $auth_amt;
    }
    if ( $amount <= 0 ) { return; }
    $res = WC_Alipay_Fund_Auth::capture_order( $order_id, $amount );
    if ( is_wp_error( $res ) ) {
        $order->add_order_note( __( 'Auto-capture failed: ', 'woo-alipay-fund-auth' ) . $res->get_error_message() );
    }
}, 10, 1 );

// Auto unfreeze on cancel (if enabled)
add_action( 'woocommerce_order_status_cancelled', function( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) { return; }
    if ( 'alipay_fund_auth' !== $order->get_payment_method() ) { return; }
    $settings = get_option( 'woocommerce_alipay_fund_auth_settings', array() );
    if ( empty( $settings['unfreeze_on_cancel'] ) || 'yes' !== $settings['unfreeze_on_cancel'] ) { return; }
    $res = WC_Alipay_Fund_Auth::unfreeze_order( $order_id );
    if ( is_wp_error( $res ) ) {
        $order->add_order_note( __( 'Auto-unfreeze failed: ', 'woo-alipay-fund-auth' ) . $res->get_error_message() );
    }
}, 10, 1 );

// Store API: persist auth code for Blocks if provided
add_action( 'woocommerce_store_api_checkout_update_order_from_request', function( $order, $request ) {
    try {
        if ( ! ( $order instanceof WC_Order ) ) { return; }
        $payment_data = array();
        if ( ! empty( $request['payment_data'] ) && is_array( $request['payment_data'] ) ) {
            foreach ( $request['payment_data'] as $pair ) {
                if ( isset( $pair['key'], $pair['value'] ) ) {
                    $payment_data[ sanitize_key( $pair['key'] ) ] = wc_clean( $pair['value'] );
                }
            }
        }
        if ( isset( $payment_data['alipay_auth_code'] ) && $payment_data['alipay_auth_code'] ) {
            $order->update_meta_data( '_alipay_auth_code', $payment_data['alipay_auth_code'] );
            $order->save();
        }
    } catch ( Exception $e ) {
        // Silence.
    }
}, 10, 2 );
