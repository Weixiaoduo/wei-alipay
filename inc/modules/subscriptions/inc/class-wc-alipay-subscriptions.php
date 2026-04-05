<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Alipay_Subscriptions {

    public function __construct() {
        // Hook for automatic renewals (will only run when gateway supports automatic payments)
        add_action( 'woocommerce_scheduled_subscription_payment_alipay', array( $this, 'scheduled_subscription_payment' ), 10, 2 );

        // Allow extensions to customize notify_url when needed (kept as pass-through for now)
        add_filter( 'woo_alipay_gateway_notify_url', array( $this, 'maybe_route_notify_url' ), 10, 2 );
    }

    /**
     * Optionally route notify_url for subscription-specific callbacks.
     */
    public function maybe_route_notify_url( $notify_url, $order_id ) {
        // Placeholder: keep original notify URL. We can route to a dedicated endpoint when adding agreement callbacks.
        return $notify_url;
    }

    /**
     * Handle scheduled subscription payment for gateway ID "alipay".
     *
     * @param float    $amount_to_charge
     * @param WC_Order $renewal_order
     */
    public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {
        if ( ! $renewal_order || ! is_a( $renewal_order, 'WC_Order' ) ) {
            return;
        }

        $logger = wc_get_logger();
        $context = array( 'source' => 'alipay-subscriptions' );

        // Phase 1: manual renewal mode by default
        $auto_debit_enabled = ( 'yes' === get_option( 'woo_alipay_subscriptions_enable_auto_debit', 'no' ) );

        if ( ! $auto_debit_enabled ) {
            $renewal_order->add_order_note( __( '未启用自动代扣或未完成签约：将使用手动续费。', 'woo-alipay-subscriptions' ) );
            $renewal_order->update_status( 'failed', __( '自动代扣未启用或未签约，标记为失败以触发手动续费。', 'woo-alipay-subscriptions' ) );
            $logger->log( 'info', 'Auto debit disabled, renewal order set to failed: #' . $renewal_order->get_id(), $context );
            return;
        }

        // Look up an Alipay agreement token for this customer
        $user_id = $renewal_order->get_user_id();
        $token   = $this->get_user_agreement_token( $user_id );

        if ( ! $token ) {
            $renewal_order->add_order_note( __( '未找到支付宝代扣协议，无法自动续费。', 'woo-alipay-subscriptions' ) );
            $renewal_order->update_status( 'failed', __( '未找到支付宝代扣协议，标记为失败以触发手动续费。', 'woo-alipay-subscriptions' ) );
            $logger->log( 'warning', 'No Alipay agreement token for user #' . $user_id . ', order #' . $renewal_order->get_id(), $context );
            return;
        }

        // Execute charge using agreement
        $agreement_no = method_exists( $token, 'get_agreement_no' ) ? $token->get_agreement_no() : $token->get_token();
        $product_code = get_option( 'woo_alipay_subscriptions_product_code', 'GENERAL_WITHHOLDING' );

        $result = WC_Alipay_Subscriptions_Charge::charge_with_agreement( $renewal_order, $amount_to_charge, $agreement_no, $product_code );

        if ( is_wp_error( $result ) ) {
            $renewal_order->add_order_note( sprintf( __( '自动代扣失败：%s', 'woo-alipay-subscriptions' ), $result->get_error_message() ) );
            $logger->log( 'error', 'Auto debit failed for order #' . $renewal_order->get_id() . ' - ' . $result->get_error_message(), $context );
            // Schedule retry according to plan
            $this->maybe_schedule_retry( $renewal_order );
            $renewal_order->update_status( 'failed' );
            return;
        }

        // Payment created successfully; mark as paid
        $renewal_order->payment_complete( $result['trade_no'] );
        $renewal_order->add_order_note( sprintf( __( '自动代扣成功 - 交易号: %s', 'woo-alipay-subscriptions' ), $result['trade_no'] ) );
    }

    /**
     * Schedule retry attempts based on settings (0,1,3,7 days).
     */
    private function maybe_schedule_retry( WC_Order $order ) {
        $plan_raw = get_option( 'woo_alipay_subscriptions_retry_plan', '0,1,3,7' );
        $plan = array_values( array_filter( array_map( 'absint', array_map( 'trim', explode( ',', $plan_raw ) ) ), function( $v ){ return $v >= 0; } ) );
        if ( empty( $plan ) ) { $plan = array( 1, 3, 7 ); }

        $attempt = absint( $order->get_meta( '_woo_alipay_retry_attempt', true ) );
        if ( $attempt >= count( $plan ) ) {
            return; // no more retries
        }
        $delay_days = $plan[ $attempt ];
        $timestamp = time() + ( $delay_days * DAY_IN_SECONDS );

        $order->update_meta_data( '_woo_alipay_retry_attempt', $attempt + 1 );
        $order->save();

        wp_schedule_single_event( $timestamp, 'woo_alipay_subscriptions_retry_charge', array( $order->get_id() ) );
    }

    /**
     * Get a customer Alipay agreement token if available.
     *
     * @param int $user_id
     * @return WC_Payment_Token_Alipay_Agreement|null
     */
    private function get_user_agreement_token( $user_id ) {
        if ( ! $user_id ) {
            return null;
        }
        $tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, 'alipay_agreement' );
        if ( empty( $tokens ) ) {
            return null;
        }
        // Return the first available token
        $token = is_array( $tokens ) ? array_shift( $tokens ) : $tokens;
        return ( $token instanceof WC_Payment_Token ) ? $token : null;
    }

    /**
     * Handle our retry event.
     */
    public function handle_retry_event( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->is_paid() ) {
            return;
        }
        $user_id = $order->get_user_id();
        $token = $this->get_user_agreement_token( $user_id );
        if ( ! $token ) {
            return;
        }
        $amount = (float) $order->get_total();
        $agreement_no = method_exists( $token, 'get_agreement_no' ) ? $token->get_agreement_no() : $token->get_token();
        $product_code = get_option( 'woo_alipay_subscriptions_product_code', 'GENERAL_WITHHOLDING' );
        $result = WC_Alipay_Subscriptions_Charge::charge_with_agreement( $order, $amount, $agreement_no, $product_code );
        if ( is_wp_error( $result ) ) {
            $order->add_order_note( sprintf( __( '自动代扣重试失败：%s', 'woo-alipay-subscriptions' ), $result->get_error_message() ) );
            $this->maybe_schedule_retry( $order );
            return;
        }
        $order->payment_complete( $result['trade_no'] );
        $order->add_order_note( sprintf( __( '自动代扣成功（重试） - 交易号: %s', 'woo-alipay-subscriptions' ), $result['trade_no'] ) );
    }
}

// Initialize the class
$__woo_alipay_subs = new WC_Alipay_Subscriptions();
// Bind retry action
add_action( 'woo_alipay_subscriptions_retry_charge', array( $__woo_alipay_subs, 'handle_retry_event' ), 10, 1 );
