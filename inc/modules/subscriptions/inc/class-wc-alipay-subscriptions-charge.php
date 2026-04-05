<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Alipay_Subscriptions_Charge {

    /**
     * Perform an auto-debit charge via agreement_no using AlipayTradeCreateRequest.
     * Returns array( 'success' => true, 'trade_no' => '...') or WP_Error.
     */
    public static function charge_with_agreement( WC_Order $order, $amount, $agreement_no, $product_code ) {
        try {
            // Load main gateway config
            $main_gateway = new WC_Alipay(false);
            $config = array(
                'app_id' => $main_gateway->get_option('appid'),
                'merchant_private_key' => $main_gateway->get_option('private_key'),
                'alipay_public_key' => $main_gateway->get_option('public_key'),
                'charset' => 'UTF-8',
                'sign_type' => 'RSA2',
                'gatewayUrl' => ( 'yes' === $main_gateway->get_option('sandbox') ) ? 'https://openapi.alipaydev.com/gateway.do' : 'https://openapi.alipay.com/gateway.do',
            );

            // Ensure a stable out_trade_no for retries
            $out_trade_no = $order->get_meta( '_alipay_out_trade_no_withhold' );
            if ( empty( $out_trade_no ) ) {
                $blog_prefix = is_multisite() ? get_current_blog_id() . '-' : '';
                $out_trade_no = 'WOOAS' . $blog_prefix . $order->get_id() . '-' . current_time('timestamp');
                $order->update_meta_data( '_alipay_out_trade_no_withhold', $out_trade_no );
                $order->save();
            }

            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradeCreateRequest.php';

            $aop = new AopClient();
            $aop->gatewayUrl = $config['gatewayUrl'];
            $aop->appId = $config['app_id'];
            $aop->rsaPrivateKey = $config['merchant_private_key'];
            $aop->alipayrsaPublicKey = $config['alipay_public_key'];
            $aop->charset = $config['charset'];
            $aop->signType = $config['sign_type'];

            $subject = get_bloginfo( 'name' ) . ' - ' . sprintf( __( '订阅续费订单 #%s', 'woo-alipay-subscriptions' ), $order->get_id() );
            $biz = array(
                'out_trade_no'   => $out_trade_no,
                'total_amount'   => number_format( (float) $amount, 2, '.', '' ),
                'subject'        => mb_substr( $subject, 0, 256 ),
                'product_code'   => $product_code,
                'agreement_params' => array(
                    'agreement_no' => $agreement_no,
                ),
            );

            $request = new AlipayTradeCreateRequest();
            $request->setBizContent( wp_json_encode( $biz ) );

            $response = $aop->execute( $request );
            $node = 'alipay_trade_create_response';
            $result = isset( $response->$node ) ? $response->$node : null;

            if ( is_object( $result ) && isset( $result->code ) && '10000' === (string) $result->code ) {
                $trade_no = isset( $result->trade_no ) ? $result->trade_no : '';
                if ( $trade_no ) {
                    return array( 'success' => true, 'trade_no' => $trade_no );
                }
                return new WP_Error( 'no_trade_no', __( '支付宝返回成功但缺少交易号。', 'woo-alipay-subscriptions' ) );
            }

            $msg = isset( $result->sub_msg ) ? $result->sub_msg : ( isset( $result->msg ) ? $result->msg : 'Unknown error' );
            return new WP_Error( 'alipay_create_failed', $msg );
        } catch ( Exception $e ) {
            return new WP_Error( 'exception', $e->getMessage() );
        }
    }
}
