<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Intercepts Alipay receipt for subscription first orders when sign_and_pay is enabled,
 * and redirects to agreement signing start endpoint carrying order context.
 */
class WC_Alipay_Subscriptions_Checkout_Interceptor {

    public function __construct() {
        // Run before the core gateway outputs its receipt form
        add_action( 'woocommerce_receipt_alipay', array( $this, 'maybe_override_receipt' ), 1, 1 );
    }

    public function maybe_override_receipt( $order_id ) {
        try {
            if ( ! function_exists( 'wcs_order_contains_subscription' ) ) {
                return; // Subscriptions plugin not present or too old
            }
            $enable_auto = get_option( 'woo_alipay_subscriptions_enable_auto_debit', 'no' );
            $sign_mode   = get_option( 'woo_alipay_subscriptions_sign_mode', 'sign_only' );
            if ( 'yes' !== $enable_auto || 'sign_and_pay' !== $sign_mode ) {
                return; // Not enabled
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return;
            }
            if ( $order->is_paid() ) {
                return; // Already paid
            }
            if ( ! wcs_order_contains_subscription( $order, array( 'parent', 'renewal', 'switch' ) ) ) {
                return; // Not a subscription first order
            }

            // Redirect to sign start with order context
            $url = add_query_arg( array( 'order_id' => $order->get_id() ), home_url( '/?wc-api=wc_alipay_subscriptions_sign_start' ) );
            wp_safe_redirect( $url );
            exit;
        } catch ( Exception $e ) {
            // Fallback to default receipt if anything goes wrong
            return;
        }
    }
}

new WC_Alipay_Subscriptions_Checkout_Interceptor();
