<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Alipay_Subscriptions_Charge {

    private static function log( $level, $message, $context = array() ) {
        $logger = wc_get_logger();
        if ( $logger ) {
            $logger->log( $level, $message, array_merge( array( 'source' => 'alipay-subscriptions' ), $context ) );
        }
    }

    private static function get_main_gateway_config( WC_Order $order ) {
        /** @phpstan-ignore requireOnce.fileNotFound */
        require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';

        $core = wp_parse_args( get_option( 'woocommerce_alipay_settings', array() ), array(
            'appid'                => '',
            'private_key'          => '',
            'public_key'           => '',
            'sandbox'              => 'no',
            'sign_credential_mode' => 'public_key',
            'app_public_cert'      => '',
            'alipay_public_cert'   => '',
            'alipay_root_cert'     => '',
        ) );

        $missing = array();
        if ( empty( $core['appid'] ) ) {
            $missing[] = 'appid';
        }
        if ( empty( $core['private_key'] ) ) {
            $missing[] = 'private_key';
        }
        if ( 'cert' === (string) $core['sign_credential_mode'] ) {
            if ( empty( $core['app_public_cert'] ) || empty( $core['alipay_public_cert'] ) || empty( $core['alipay_root_cert'] ) ) {
                $missing[] = 'certificates';
            }
        } elseif ( empty( $core['public_key'] ) ) {
            $missing[] = 'public_key';
        }

        if ( ! empty( $missing ) ) {
            self::log( 'error', 'Subscription charge blocked: missing core settings', array( 'order_id' => $order->get_id(), 'missing' => implode( '|', $missing ) ) );
            /* translators: %s: comma-separated list of missing configuration field names */
            return new WP_Error( 'config_missing', sprintf( __( 'Alipay core configuration is incomplete. Missing fields: %s', 'wei-alipay' ), implode( ', ', $missing ) ) );
        }

        return Alipay_SDK_Helper::get_alipay_config( $core );
    }

    /**
     * Evaluate an alipay.trade.pay response object against expected merchant order context.
     *
     * @param mixed  $result                Decoded alipay_trade_pay_response object.
     * @param string $expected_out_trade_no Expected out_trade_no.
     * @return array<string,mixed>|WP_Error
     */
    public static function evaluate_pay_response( $result, $expected_out_trade_no ) {
        if ( ! is_object( $result ) || ! isset( $result->code ) ) {
            return new WP_Error( 'alipay_pay_empty', __( '支付宝代扣返回为空。', 'wei-alipay' ) );
        }

        $code                  = (string) $result->code;
        $trade_no              = isset( $result->trade_no ) ? (string) $result->trade_no : '';
        $response_out_trade_no = isset( $result->out_trade_no ) ? (string) $result->out_trade_no : '';
        $trade_status          = isset( $result->trade_status ) ? strtoupper( trim( (string) $result->trade_status ) ) : '';

        if ( '10003' === $code ) {
            if ( '' !== $response_out_trade_no && ! hash_equals( (string) $expected_out_trade_no, $response_out_trade_no ) ) {
                return new WP_Error( 'charge_context_mismatch', __( '支付宝代扣响应与订单请求不匹配。', 'wei-alipay' ) );
            }
            return array(
                'success'  => false,
                'pending'  => true,
                'status'   => 'pending',
                'trade_no' => $trade_no,
                'code'     => '10003',
            );
        }

        if ( '10000' === $code ) {
            if ( '' === $trade_no || '' === $response_out_trade_no || ! hash_equals( (string) $expected_out_trade_no, $response_out_trade_no ) ) {
                return new WP_Error( 'charge_context_mismatch', __( '支付宝代扣响应与订单请求不匹配。', 'wei-alipay' ) );
            }

            if ( '' !== $trade_status ) {
                if ( in_array( $trade_status, array( 'TRADE_SUCCESS', 'TRADE_FINISHED' ), true ) ) {
                    return array(
                        'success'  => true,
                        'pending'  => false,
                        'status'   => 'paid',
                        'trade_no' => $trade_no,
                    );
                }
                if ( 'WAIT_BUYER_PAY' === $trade_status ) {
                    return array(
                        'success'  => false,
                        'pending'  => true,
                        'status'   => 'pending',
                        'trade_no' => $trade_no,
                        'code'     => '10000',
                    );
                }
                return new WP_Error( 'alipay_pay_not_paid', sprintf( __( '支付宝代扣未完成，交易状态：%s', 'wei-alipay' ), $trade_status ) );
            }

            // alipay.trade.pay immediate success typically provides gmt_payment / fund_bill_list / receipt_amount.
            if ( ! empty( $result->gmt_payment ) || ! empty( $result->fund_bill_list ) || ! empty( $result->receipt_amount ) ) {
                return array(
                    'success'  => true,
                    'pending'  => false,
                    'status'   => 'paid',
                    'trade_no' => $trade_no,
                );
            }

            // Bare code=10000 without settlement proof: caller must verify via query_order().
            return array(
                'success'     => false,
                'pending'     => false,
                'needs_query' => true,
                'status'      => 'needs_query',
                'trade_no'    => $trade_no,
            );
        }

        $msg = isset( $result->sub_msg ) && $result->sub_msg
            ? (string) $result->sub_msg
            : ( isset( $result->msg ) && $result->msg ? (string) $result->msg : 'Unknown error' );
        return new WP_Error( 'alipay_pay_failed', $msg );
    }

    /**
     * Perform an auto-debit charge via agreement_no using AlipayTradePayRequest (alipay.trade.pay).
     *
     * Returns:
     * - array( 'success' => true, 'pending' => false, 'status' => 'paid', 'trade_no' => '...' )
     * - array( 'success' => false, 'pending' => true, 'status' => 'pending', 'trade_no' => '...' )
     * - WP_Error on failure or context mismatch.
     *
     * @param WC_Order    $order        Renewal or initial subscription order.
     * @param float       $amount       Amount to charge.
     * @param string      $agreement_no Signed Alipay agreement_no.
     * @param string      $product_code Alipay withholding product code.
     * @param object|null $aop_override Optional pre-built AOP client (for offline tests).
     * @return array<string,mixed>|WP_Error
     */
    public static function charge_with_agreement( WC_Order $order, $amount, $agreement_no, $product_code, $aop_override = null ) {
        try {
            $config = array();
            if ( ! $aop_override ) {
                $config = self::get_main_gateway_config( $order );
                if ( is_wp_error( $config ) ) {
                    return $config;
                }
            }

            // Ensure a stable out_trade_no for retries
            $out_trade_no = (string) $order->get_meta( '_alipay_out_trade_no_withhold' );
            if ( '' === $out_trade_no ) {
                $blog_prefix  = is_multisite() ? get_current_blog_id() . '-' : '';
                $out_trade_no = 'WOOAS' . $blog_prefix . $order->get_id() . '-' . current_time( 'timestamp' );
                $order->update_meta_data( '_alipay_out_trade_no_withhold', $out_trade_no );
                $order->save();
            }

            /** @phpstan-ignore requireOnce.fileNotFound */
            require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
            /** @phpstan-ignore requireOnce.fileNotFound */
            require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradePayRequest.php';

            $aop = $aop_override ? $aop_override : Alipay_SDK_Helper::create_alipay_service( $config );
            if ( ! $aop ) {
                return new WP_Error( 'sdk_error', __( '创建支付宝服务失败。', 'wei-alipay' ) );
            }

            /* translators: %s: order ID */
            $subject = get_bloginfo( 'name' ) . ' - ' . sprintf( __( '订阅续费订单 #%s', 'wei-alipay' ), $order->get_id() );
            $biz     = array(
                'out_trade_no'     => $out_trade_no,
                'total_amount'     => Alipay_SDK_Helper::format_amount( $amount ),
                'subject'          => mb_substr( $subject, 0, 256 ),
                'product_code'     => $product_code,
                'agreement_params' => array(
                    'agreement_no' => $agreement_no,
                ),
            );

            $request = new AlipayTradePayRequest();
            $request->setBizContent( wp_json_encode( $biz ) );
            if ( function_exists( 'WC' ) && WC() && method_exists( WC(), 'api_request_url' ) ) {
                $request->setNotifyUrl( apply_filters( 'woo_alipay_gateway_notify_url', WC()->api_request_url( 'WC_Alipay' ), $order->get_id() ) );
            }

            self::log( 'info', 'Subscription charge request (alipay.trade.pay)', array(
                'order_id'     => $order->get_id(),
                'out_trade_no' => $out_trade_no,
                'amount'       => Alipay_SDK_Helper::format_amount( $amount ),
            ) );

            $response  = $aop->execute( $request );
            $node      = 'alipay_trade_pay_response';
            $result    = isset( $response->$node ) ? $response->$node : null;
            $evaluated = self::evaluate_pay_response( $result, $out_trade_no );

            if ( is_wp_error( $evaluated ) ) {
                self::log( 'error', 'Subscription charge failed', array(
                    'order_id'     => $order->get_id(),
                    'out_trade_no' => $out_trade_no,
                    'error'        => $evaluated->get_error_message(),
                ) );
                return $evaluated;
            }

            if ( ! empty( $evaluated['needs_query'] ) && ! empty( $config ) ) {
                $queried = Alipay_SDK_Helper::query_order( $out_trade_no, $evaluated['trade_no'], $config );
                if ( is_wp_error( $queried ) ) {
                    return new WP_Error( 'alipay_pay_unconfirmed', $queried->get_error_message() );
                }
                $q_status = strtoupper( (string) ( $queried['trade_status'] ?? '' ) );
                if ( in_array( $q_status, array( 'TRADE_SUCCESS', 'TRADE_FINISHED' ), true ) ) {
                    $evaluated = array(
                        'success'  => true,
                        'pending'  => false,
                        'status'   => 'paid',
                        'trade_no' => (string) ( $queried['trade_no'] ?: $evaluated['trade_no'] ),
                    );
                } elseif ( 'WAIT_BUYER_PAY' === $q_status ) {
                    $evaluated = array(
                        'success'  => false,
                        'pending'  => true,
                        'status'   => 'pending',
                        'trade_no' => (string) ( $queried['trade_no'] ?: $evaluated['trade_no'] ),
                    );
                } else {
                    return new WP_Error( 'alipay_pay_not_paid', sprintf( __( '支付宝代扣查询未确认已支付，状态：%s', 'wei-alipay' ), $q_status ?: 'UNKNOWN' ) );
                }
            } elseif ( ! empty( $evaluated['needs_query'] ) ) {
                // No config available to query (e.g. unit test without query); treat as pending rather than falsely paid.
                $evaluated = array(
                    'success'  => false,
                    'pending'  => true,
                    'status'   => 'pending',
                    'trade_no' => (string) $evaluated['trade_no'],
                );
            }

            if ( ! empty( $evaluated['pending'] ) ) {
                self::log( 'info', 'Subscription charge pending confirmation', array(
                    'order_id'     => $order->get_id(),
                    'out_trade_no' => $out_trade_no,
                    'trade_no'     => $evaluated['trade_no'],
                ) );
                return $evaluated;
            }

            self::log( 'info', 'Subscription charge success', array(
                'order_id'     => $order->get_id(),
                'out_trade_no' => $out_trade_no,
                'trade_no'     => $evaluated['trade_no'],
            ) );
            return $evaluated;
        } catch ( Exception $e ) {
            self::log( 'error', 'Subscription charge exception', array( 'order_id' => $order->get_id(), 'error' => $e->getMessage() ) );
            return new WP_Error( 'exception', $e->getMessage() );
        }
    }
}
