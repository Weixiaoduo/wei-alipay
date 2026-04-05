<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Skeleton for Alipay Agreement Signing & Callbacks (Subscriptions)
 *
 * This class only wires endpoints and basic helpers. Actual API requests
 * will be added once the merchant is approved for withhold/auto-debit.
 */
class WC_Alipay_Subscriptions_Signing {

    public function __construct() {
        // Agreement notify endpoint (server-to-server)
        add_action( 'woocommerce_api_wc_alipay_subscriptions_agreement', array( $this, 'handle_agreement_notify' ) );
        // Agreement return endpoint (user browser return)
        add_action( 'woocommerce_api_wc_alipay_subscriptions_agreement_return', array( $this, 'handle_agreement_return' ) );
        // Agreement start endpoint (user launch signing)
        add_action( 'woocommerce_api_wc_alipay_subscriptions_sign_start', array( $this, 'handle_sign_start' ) );

        // Add a helper action link in My Account (optional placeholder)
        add_action( 'woocommerce_account_dashboard', array( $this, 'maybe_render_sign_hint' ) );
        // Unsign endpoint (user initiated)
        add_action( 'woocommerce_api_wc_alipay_subscriptions_agreement_unsign', array( $this, 'handle_unsign' ) );
    }

    /**
     * Generate agreement notify URL for Alipay settings display.
     */
    public static function agreement_notify_url() {
        return WC()->api_request_url( 'WC_Alipay_Subscriptions_Agreement' );
    }

    /**
     * Generate agreement return URL. Here we use My Account page by default.
     */
    public static function agreement_return_url() {
        $my_account = wc_get_page_permalink( 'myaccount' );
        return $my_account ? $my_account : home_url( '/' );
    }

    /**
     * Handle Alipay server-to-server notify for agreement events.
     */
    public function handle_agreement_notify() {
        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            status_header( 405 );
            echo 'fail';
            exit;
        }

        // Verify signature
        $main_gateway = new WC_Alipay(false);
        $alipay_public_key = $main_gateway->get_option('public_key');
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
        $aop = new AopClient();
        $aop->alipayrsaPublicKey = $alipay_public_key;
        $verified = $aop->rsaCheckV1( $_POST, null, 'RSA2' );
        if ( ! $verified ) {
            status_header( 400 );
            echo 'fail';
            exit;
        }

        // Parse payload
        $agreement_no = isset($_POST['agreement_no']) ? wc_clean( wp_unslash( $_POST['agreement_no'] ) ) : '';
        $alipay_user_id = isset($_POST['alipay_user_id']) ? wc_clean( wp_unslash( $_POST['alipay_user_id'] ) ) : '';
        $status = isset($_POST['status']) ? wc_clean( wp_unslash( $_POST['status'] ) ) : '';
        $external = isset($_POST['external_agreement_no']) ? wc_clean( wp_unslash( $_POST['external_agreement_no'] ) ) : '';

        if ( empty( $agreement_no ) || empty( $alipay_user_id ) ) {
            status_header( 400 );
            echo 'fail';
            exit;
        }

        // Extract ids from external agreement no: pattern WOOALISN-{user}-{ts}[-{order}]
        $user_id = 0; $order_id = 0;
        if ( $external && preg_match( '/^WOOALISN-(\d+)-(\d+)(?:-(\d+))?/i', $external, $m ) ) {
            $user_id = absint( $m[1] );
            // m[2] is timestamp, optional m[3] is order_id
            if ( isset( $m[3] ) ) { $order_id = absint( $m[3] ); }
        }

        if ( $user_id > 0 && ( 'NORMAL' === strtoupper( $status ) || 'SUCCESS' === strtoupper( $status ) ) ) {
            // Create or update payment token
            $existing = WC_Payment_Tokens::get_customer_tokens( $user_id, 'alipay_agreement' );
            $token_obj = null;
            if ( ! empty( $existing ) ) {
                foreach ( $existing as $tok ) {
                    if ( $tok instanceof WC_Payment_Token && ( $tok->get_token() === $agreement_no || ( method_exists( $tok, 'get_agreement_no' ) && $tok->get_agreement_no() === $agreement_no ) ) ) {
                        $token_obj = $tok; break;
                    }
                }
            }
            if ( ! $token_obj ) {
                $token_obj = new WC_Payment_Token_Alipay_Agreement();
                $token_obj->set_user_id( $user_id );
                $token_obj->set_gateway_id( 'alipay' );
                $token_obj->set_token( $agreement_no );
                $token_obj->set_default( true );
            }
            if ( method_exists( $token_obj, 'set_agreement_no' ) ) {
                $token_obj->set_agreement_no( $agreement_no );
            }
            if ( method_exists( $token_obj, 'set_alipay_user_id' ) ) {
                $token_obj->set_alipay_user_id( $alipay_user_id );
            }
            $product_code = get_option( 'woo_alipay_subscriptions_product_code', 'GENERAL_WITHHOLDING' );
            if ( method_exists( $token_obj, 'set_product_code' ) ) {
                $token_obj->set_product_code( $product_code );
            }
            $token_obj->save();

            // If this signing was initiated from a specific order and it's unpaid, do immediate charge
            if ( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( $order && ! $order->is_paid() ) {
                    $amount = (float) $order->get_total();
                    $product_code = get_option( 'woo_alipay_subscriptions_product_code', 'GENERAL_WITHHOLDING' );
                    $charge = WC_Alipay_Subscriptions_Charge::charge_with_agreement( $order, $amount, $agreement_no, $product_code );
                    if ( ! is_wp_error( $charge ) ) {
                        $order->payment_complete( $charge['trade_no'] );
                        $order->add_order_note( sprintf( __( '首单签约并扣款成功 - 交易号: %s', 'woo-alipay-subscriptions' ), $charge['trade_no'] ) );
                    } else {
                        $order->add_order_note( sprintf( __( '首单签约完成，但扣款失败：%s', 'woo-alipay-subscriptions' ), $charge->get_error_message() ) );
                    }
                }
            }
        }

        status_header( 200 );
        echo 'success';
        exit;
    }

    /**
     * Handle user browser return after agreement signing.
     */
    public function handle_agreement_return() {
        wc_add_notice( __( '支付宝代扣签约回跳：签约结果稍后将通过服务器通知同步。', 'woo-alipay-subscriptions' ), 'notice' );
        wp_safe_redirect( self::agreement_return_url() );
        exit;
    }

    /**
     * Start signing flow for current logged-in user.
     */
    public function handle_sign_start() {
        if ( ! is_user_logged_in() ) {
            wc_add_notice( __( '请先登录后再发起签约。', 'woo-alipay-subscriptions' ), 'error' );
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }
        $user_id = get_current_user_id();
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

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

        $product_code = get_option( 'woo_alipay_subscriptions_product_code', 'GENERAL_WITHHOLDING' );
        $external_no = 'WOOALISN-' . $user_id . '-' . current_time('timestamp');
        $return_url = WC()->api_request_url( 'WC_Alipay_Subscriptions_Agreement_Return' );
        $notify_url = WC()->api_request_url( 'WC_Alipay_Subscriptions_Agreement' );

        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayUserAgreementPageSignRequest.php';

        $aop = new AopClient();
        $aop->gatewayUrl = $config['gatewayUrl'];
        $aop->appId = $config['app_id'];
        $aop->rsaPrivateKey = $config['merchant_private_key'];
        $aop->alipayrsaPublicKey = $config['alipay_public_key'];
        $aop->charset = $config['charset'];
        $aop->signType = $config['sign_type'];

        $request = new AlipayUserAgreementPageSignRequest();
        $biz = array(
            'personal_product_code' => $product_code,
            'product_code'          => $product_code,
'external_agreement_no' => $external_no . ( $order_id ? '-' . $order_id : '' ),
            'sign_scene'            => 'INDUSTRY|GENERAL',
        );
        $request->setBizContent( wp_json_encode( $biz ) );
        $request->setReturnUrl( $return_url );
        $request->setNotifyUrl( $notify_url );

        // Use GET to obtain a redirect URL
        $result = $aop->pageExecute( $request, 'GET' );
        if ( is_string( $result ) && 0 === strpos( $result, 'http' ) ) {
            wp_redirect( $result );
        } else {
            echo $result; // If it's HTML form, output directly
        }
        exit;
    }

    /**
     * Optional hint on My Account to guide signing (placeholder only).
     */
    public function maybe_render_sign_hint() {
        $enable_auto = get_option( 'woo_alipay_subscriptions_enable_auto_debit', 'no' );
        if ( 'yes' !== $enable_auto ) {
            return;
        }
        $unsign_url = home_url( '/?wc-api=wc_alipay_subscriptions_agreement_unsign' );
        echo '<div class="woocommerce-info" style="margin-bottom:15px">'
           . esc_html__( '您已开启自动代扣功能。若尚未完成支付宝签约，请在下次结账时完成授权。', 'woo-alipay-subscriptions' )
           . ' <a href="' . esc_url( $unsign_url ) . '" class="button" style="margin-left:8px">' . esc_html__( '解绑代扣协议', 'woo-alipay-subscriptions' ) . '</a>'
           . '</div>';
    }

    /**
     * Handle user-initiated unsign request (simple flow).
     */
    public function handle_unsign() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }
        $user_id = get_current_user_id();
        $tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, 'alipay_agreement' );
        if ( empty( $tokens ) ) {
            wc_add_notice( __( '未找到代扣协议。', 'woo-alipay-subscriptions' ), 'error' );
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }
        $token = is_array( $tokens ) ? array_shift( $tokens ) : $tokens;
        $agreement_no = method_exists( $token, 'get_agreement_no' ) ? $token->get_agreement_no() : $token->get_token();

        // Call unsign API
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayUserAgreementUnsignRequest.php';
        $main_gateway = new WC_Alipay(false);
        $aop = new AopClient();
        $aop->gatewayUrl = ( 'yes' === $main_gateway->get_option('sandbox') ) ? 'https://openapi.alipaydev.com/gateway.do' : 'https://openapi.alipay.com/gateway.do';
        $aop->appId = $main_gateway->get_option('appid');
        $aop->rsaPrivateKey = $main_gateway->get_option('private_key');
        $aop->alipayrsaPublicKey = $main_gateway->get_option('public_key');
        $aop->charset = 'UTF-8';
        $aop->signType = 'RSA2';

        $request = new AlipayUserAgreementUnsignRequest();
        $biz = array(
            'agreement_no' => $agreement_no,
        );
        $request->setBizContent( wp_json_encode( $biz ) );
        $response = $aop->execute( $request );
        $node = 'alipay_user_agreement_unsign_response';
        $res = isset( $response->$node ) ? $response->$node : null;

        if ( is_object( $res ) && isset( $res->code ) && '10000' === (string) $res->code ) {
            // Delete local token
            WC_Payment_Tokens::delete( $token->get_id() );
            wc_add_notice( __( '协议已解绑。', 'woo-alipay-subscriptions' ), 'success' );
        } else {
            wc_add_notice( __( '协议解绑失败，请稍后再试。', 'woo-alipay-subscriptions' ), 'error' );
        }
        wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
        exit;
    }
}

new WC_Alipay_Subscriptions_Signing();
