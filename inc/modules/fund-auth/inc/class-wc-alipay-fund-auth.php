<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Alipay_Fund_Auth extends WC_Payment_Gateway {

    protected static $log_enabled = false;
    protected static $log = null;
    protected $required_core_fields = array( 'appid', 'private_key', 'public_key' );

    const GATEWAY_ID = 'alipay_fund_auth';

    public function __construct() {
        $this->id                 = self::GATEWAY_ID;
        $this->method_title       = __( 'Alipay Fund Authorization', 'wei-alipay' );
        $this->method_description = __( 'Freeze customer funds first, then capture or unfreeze later.', 'wei-alipay' );
        $this->title              = __( 'Alipay Fund Authorization', 'wei-alipay' );
        $this->description        = __( 'Your payment will be authorized (frozen) now and captured upon fulfillment.', 'wei-alipay' );
        $this->icon               = WEI_ALIPAY_PLUGIN_URL . 'assets/images/alipay-icon.svg';
        $this->has_fields         = false;
        $this->supports           = array( 'products' );

        $this->init_form_fields();
        $this->init_settings();

        self::$log_enabled = ( 'yes' === $this->get_option( 'debug', 'no' ) );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_api_wc_alipay_fund_auth', array( $this, 'handle_fund_auth_notify' ) );

        if ( is_admin() ) {
            add_action( 'add_meta_boxes', array( $this, 'add_authorization_metabox' ) );
        }

        // Optional: automatic capture/unfreeze hooks to be added in M2.
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'wei-alipay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Alipay Fund Authorization', 'wei-alipay' ),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __( 'Title', 'wei-alipay' ),
                'type'        => 'text',
                'default'     => __( 'Alipay Fund Authorization', 'wei-alipay' ),
            ),
            'description' => array(
                'title'       => __( 'Description', 'wei-alipay' ),
                'type'        => 'textarea',
                'default'     => __( 'Your payment will be authorized (frozen) now and captured upon fulfillment.', 'wei-alipay' ),
            ),
            'capture_on_complete' => array(
                'title'       => __( 'Auto-capture on order complete', 'wei-alipay' ),
                'type'        => 'checkbox',
                'label'       => __( 'When order is marked completed, automatically capture authorized funds', 'wei-alipay' ),
                'default'     => 'no',
            ),
            'unfreeze_on_cancel' => array(
                'title'       => __( 'Auto-unfreeze on cancel', 'wei-alipay' ),
                'type'        => 'checkbox',
                'label'       => __( 'When order is cancelled, automatically unfreeze funds', 'wei-alipay' ),
                'default'     => 'yes',
            ),
            'allow_partial_capture' => array(
                'title'       => __( 'Allow partial capture', 'wei-alipay' ),
                'type'        => 'checkbox',
                'label'       => __( 'Allow capturing less than the authorized amount', 'wei-alipay' ),
                'default'     => 'no',
            ),
            'auto_capture_amount_mode' => array(
                'title'       => __( 'Auto-capture amount mode', 'wei-alipay' ),
                'type'        => 'select',
                'description' => __( 'When auto-capture is enabled, choose how much to capture.', 'wei-alipay' ),
                'default'     => 'authorized',
                'options'     => array(
                    'authorized' => __( 'Authorized amount', 'wei-alipay' ),
                    'order_total'=> __( 'Order total', 'wei-alipay' ),
                    'remaining'  => __( 'Remaining authorized amount', 'wei-alipay' ),
                ),
            ),
            'debug' => array(
                'title'       => __( 'Debug log', 'wei-alipay' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable logging', 'wei-alipay' ),
                'default'     => 'no',
            ),
        );
    }

    public function is_available() {
        if ( 'yes' !== $this->get_option( 'enabled', 'no' ) ) {
            return false;
        }
        return $this->has_core_credentials();
    }

    public function requirement_checks() {
        if ( 'yes' !== $this->get_option( 'enabled', 'no' ) ) {
            return;
        }
        $missing = $this->get_missing_core_fields();
        if ( ! empty( $missing ) ) {
            /* translators: %s: comma-separated list of missing configuration field names */
            echo '<div class="notice notice-error inline wei-alipay-settings-notice" role="alert"><p>' . esc_html( sprintf( __( 'Alipay fund auth requires core credentials. Missing fields: %s', 'wei-alipay' ), implode( ', ', $missing ) ) ) . '</p></div>';
        }
    }

    public function admin_options() {
        echo '<h3>' . esc_html( $this->method_title );
        if ( function_exists( 'wc_back_link' ) ) {
            wc_back_link( __( 'Return to payments', 'wei-alipay' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) );
        }
        echo '</h3>';
        echo '<p>' . esc_html( $this->method_description ) . '</p>';
        echo '<section class="wei-alipay-settings-notices" aria-label="' . esc_attr__( 'Gateway notifications', 'wei-alipay' ) . '">';
        $this->requirement_checks();
        echo '</section>';
        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }

    private function get_core_settings() {
        return wp_parse_args( get_option( 'woocommerce_alipay_settings', array() ), array(
            'appid'                => '',
            'private_key'          => '',
            'public_key'           => '',
            'seller_id'            => '',
            'sandbox'              => 'no',
            'sign_credential_mode' => 'public_key',
            'app_public_cert'      => '',
            'alipay_public_cert'   => '',
            'alipay_root_cert'     => '',
        ) );
    }

    private function get_missing_core_fields() {
        $core = $this->get_core_settings();
        $missing = array();
        foreach ( $this->required_core_fields as $field ) {
            if ( empty( $core[ $field ] ) ) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    private function has_core_credentials() {
        return empty( $this->get_missing_core_fields() );
    }

    private static function log_msg( $message, $level = 'info', $context = array() ) {
        if ( self::$log_enabled ) {
            if ( null === self::$log ) {
                self::$log = wc_get_logger();
            }
            if ( is_array( $message ) || is_object( $message ) ) {
                $message = wc_print_r( $message, true );
            }
            self::$log->log( $level, $message, array( 'source' => self::GATEWAY_ID ) + (array) $context );
        }
    }

    private function format_amount( $amount ) {
        return number_format( (float) $amount, 2, '.', '' );
    }

    private function create_aop_from_core() {
        require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';

        $missing_fields = $this->get_missing_core_fields();
        if ( ! empty( $missing_fields ) ) {
            self::log_msg( 'Missing core settings for fund auth AOP client', 'error', array( 'missing' => implode( '|', $missing_fields ) ) );
            return array( null, array() );
        }

        $core   = $this->get_core_settings();
        $config = Alipay_SDK_Helper::get_alipay_config( $core );
        $aop = Alipay_SDK_Helper::create_alipay_service( $config );
        return array( $aop, $config );
    }

    private function generate_out_nos( $order ) {
        $prefix = is_multisite() ? ( get_current_blog_id() . '-' ) : '';
        $base   = $prefix . $order->get_id() . '-' . time();
        return array(
            'out_order_no'   => 'FAO-' . $base,
            'out_request_no' => 'FAR-' . $base,
        );
    }

    private function freeze_funds( WC_Order $order, $amount ) {
        try {
            list( $aop, $config ) = $this->create_aop_from_core();
            if ( ! $aop ) {
                return new WP_Error( 'sdk', __( 'Failed to init Alipay service.', 'wei-alipay' ) );
            }

            require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayFundAuthOrderFreezeRequest.php';

            $nos = $this->generate_out_nos( $order );
            $biz = array(
                'out_order_no'   => $nos['out_order_no'],
                'out_request_no' => $nos['out_request_no'],
                'order_title'    => sprintf( 'Preauth for order #%d', $order->get_id() ),
                'amount'         => $this->format_amount( $amount ),
                'product_code'   => 'PRE_AUTH_ONLINE',
            );

            $req = new AlipayFundAuthOrderFreezeRequest();
            $req->setBizContent( wp_json_encode( $biz, JSON_UNESCAPED_UNICODE ) );
            $req->setNotifyUrl( apply_filters( 'woo_alipay_gateway_notify_url', WC()->api_request_url( 'WC_Alipay_Fund_Auth' ), $order->get_id() ) );

            self::log_msg( array( 'freeze_biz' => $biz ) );
            $resp = $aop->execute( $req );
            $node = 'alipay_fund_auth_order_freeze_response';
            $res  = $resp->$node ?? null;

            if ( ! $res || ! isset( $res->code ) ) {
                return new WP_Error( 'alipay', __( 'Empty response from Alipay.', 'wei-alipay' ) );
            }
            if ( '10000' !== (string) $res->code ) {
                self::log_msg( array( 'freeze_error' => $resp ), 'error' );
                $msg = ( isset( $res->sub_msg ) && $res->sub_msg ) ? $res->sub_msg : ( $res->msg ?? __( 'Freeze failed', 'wei-alipay' ) );
                return new WP_Error( 'alipay', $msg );
            }

            $auth_no   = isset( $res->auth_no ) ? (string) $res->auth_no : '';
            $operation = isset( $res->operation_id ) ? (string) $res->operation_id : '';

            $order->update_meta_data( '_alipay_auth_no', $auth_no );
            $order->update_meta_data( '_alipay_operation_id', $operation );
            $order->update_meta_data( '_alipay_auth_amount', $this->format_amount( $amount ) );
            $order->update_meta_data( '_alipay_out_order_no', $nos['out_order_no'] );
            $order->update_meta_data( '_alipay_out_request_no', $nos['out_request_no'] );
            $order->save();

            return array( 'auth_no' => $auth_no, 'operation_id' => $operation );
        } catch ( Exception $e ) {
            return new WP_Error( 'exception', $e->getMessage() );
        }
    }

    private function capture_funds( WC_Order $order, $amount ) {
        try {
            list( $aop, $config ) = $this->create_aop_from_core();
            if ( ! $aop ) {
                return new WP_Error( 'sdk', __( 'Failed to init Alipay service.', 'wei-alipay' ) );
            }
            /** @phpstan-ignore requireOnce.fileNotFound */
            require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradePayRequest.php';
            $auth_no = (string) $order->get_meta( '_alipay_auth_no' );
            if ( ! $auth_no ) {
                return new WP_Error( 'param', __( 'Missing auth_no.', 'wei-alipay' ) );
            }
            $out_trade_no     = 'FAC-' . ( is_multisite() ? get_current_blog_id() . '-' : '' ) . $order->get_id() . '-' . time();
            $remaining        = $this->get_remaining_authorized( $order );
            $is_final_capture = ( (float) $amount + 0.0001 >= $remaining );
            $biz = array(
                'out_trade_no'      => $out_trade_no,
                'auth_no'           => $auth_no,
                'product_code'      => 'PRE_AUTH_ONLINE',
                'subject'           => sprintf( 'Capture for order #%d', $order->get_id() ),
                'total_amount'      => $this->format_amount( $amount ),
                'auth_confirm_mode' => $is_final_capture ? 'COMPLETE' : 'NOT_COMPLETE',
            );
            $req = new AlipayTradePayRequest();
            $req->setBizContent( wp_json_encode( $biz, JSON_UNESCAPED_UNICODE ) );
            $req->setNotifyUrl( apply_filters( 'woo_alipay_gateway_notify_url', WC()->api_request_url( 'WC_Alipay_Fund_Auth' ), $order->get_id() ) );
            self::log_msg( array( 'capture_biz' => $biz ) );
            $resp = $aop->execute( $req );
            $node = 'alipay_trade_pay_response';
            $res  = $resp->$node ?? null;
            if ( ! $res || ! isset( $res->code ) ) {
                return new WP_Error( 'alipay', __( 'Empty response from Alipay.', 'wei-alipay' ) );
            }
            if ( '10000' !== (string) $res->code ) {
                self::log_msg( array( 'capture_error' => $resp ), 'error' );
                $msg = ( $res->sub_msg ?? $res->msg ?? __( 'Capture failed', 'wei-alipay' ) );
                return new WP_Error( 'alipay', (string) $msg );
            }
            $resp_out_trade_no = isset( $res->out_trade_no ) ? (string) $res->out_trade_no : '';
            if ( '' === $resp_out_trade_no || ! hash_equals( $out_trade_no, $resp_out_trade_no ) ) {
                self::log_msg( 'Fund auth capture out_trade_no mismatch', 'error', array( 'order_id' => $order->get_id(), 'out_trade_no' => $out_trade_no ) );
                return new WP_Error( 'capture_context_mismatch', __( 'Alipay capture response does not match the merchant order number.', 'wei-alipay' ) );
            }
            $trade_no = isset( $res->trade_no ) ? (string) $res->trade_no : '';
            $order->update_meta_data( '_alipay_capture_out_trade_no', $out_trade_no );
            if ( '' !== $trade_no ) {
                $order->update_meta_data( '_alipay_capture_trade_no', $trade_no );
            }
            $order->save();
            return array( 'payment_id' => $trade_no, 'trade_no' => $trade_no, 'out_trade_no' => $out_trade_no );
        } catch ( Exception $e ) {
            return new WP_Error( 'exception', $e->getMessage() );
        }
    }

    private function unfreeze_funds( WC_Order $order, $amount = null ) {
        try {
            list( $aop, $config ) = $this->create_aop_from_core();
            if ( ! $aop ) {
                return new WP_Error( 'sdk', __( 'Failed to init Alipay service.', 'wei-alipay' ) );
            }
            require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayFundAuthOrderUnfreezeRequest.php';
            $auth_no      = (string) $order->get_meta( '_alipay_auth_no' );
            $auth_amount  = (float) ( null === $amount ? $this->get_remaining_authorized( $order ) : $amount );
            if ( ! $auth_no ) {
                return new WP_Error( 'param', __( 'Missing auth_no.', 'wei-alipay' ) );
            }
            $out_request_no = 'FAU-' . ( is_multisite() ? get_current_blog_id() . '-' : '' ) . $order->get_id() . '-' . time();
            $biz = array(
                'auth_no'        => $auth_no,
                'out_request_no' => $out_request_no,
                'amount'         => $this->format_amount( $auth_amount ),
                'remark'         => 'Wei Alipay unfreeze',
            );
            $req = new AlipayFundAuthOrderUnfreezeRequest();
            $req->setBizContent( wp_json_encode( $biz, JSON_UNESCAPED_UNICODE ) );
            self::log_msg( array( 'unfreeze_biz' => $biz ) );
            $resp = $aop->execute( $req );
            $node = 'alipay_fund_auth_order_unfreeze_response';
            $res  = $resp->$node ?? null;
            if ( ! $res || ! isset( $res->code ) ) {
                return new WP_Error( 'alipay', __( 'Empty response from Alipay.', 'wei-alipay' ) );
            }
            if ( '10000' !== (string) $res->code ) {
                self::log_msg( array( 'unfreeze_error' => $resp ), 'error' );
                $msg = ( $res->sub_msg ?? $res->msg ?? __( 'Unfreeze failed', 'wei-alipay' ) );
                return new WP_Error( 'alipay', $msg );
            }
            return array( 'unfrozen' => true, 'amount' => $this->format_amount( $auth_amount ) );
        } catch ( Exception $e ) {
            return new WP_Error( 'exception', $e->getMessage() );
        }
    }

    private function get_captured_total( WC_Order $order ) {
        return (float) $order->get_meta( '_alipay_captured_total', true );
    }

    private function add_captured_amount( WC_Order $order, $amount ) {
        $sum = $this->get_captured_total( $order ) + (float) $amount;
        $order->update_meta_data( '_alipay_captured_total', $this->format_amount( $sum ) );
        $order->save();
    }

    private function get_remaining_authorized( WC_Order $order ) {
        $auth = (float) $order->get_meta( '_alipay_auth_amount' );
        $cap  = $this->get_captured_total( $order );
        $rem  = max( 0.0, $auth - $cap );
        return (float) $this->format_amount( $rem );
    }

    private function get_auto_capture_amount( WC_Order $order ) {
        $mode = $this->get_option( 'auto_capture_amount_mode', 'authorized' );
        if ( 'order_total' === $mode ) {
            return (float) $this->format_amount( $order->get_total() );
        } elseif ( 'remaining' === $mode ) {
            return $this->get_remaining_authorized( $order );
        }
        // default authorized amount
        return (float) $order->get_meta( '_alipay_auth_amount' );
    }

    public static function capture_order( $order_id, $amount = null ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) { return new WP_Error( 'order', 'Order not found' ); }
        $inst  = new self();
        if ( null === $amount ) {
            $amount = (float) $inst->get_remaining_authorized( $order );
        }
        // Validate remaining
        $remaining = (float) $inst->get_remaining_authorized( $order );
        if ( $amount <= 0 || $amount > $remaining + 0.0001 ) {
            return new WP_Error( 'amount', __( 'Invalid capture amount (exceeds remaining authorized).', 'wei-alipay' ) );
        }
        $res = $inst->capture_funds( $order, $amount );
        if ( is_wp_error( $res ) ) { return $res; }
        $payment_id = $res['payment_id'] ?? '';
        // Update captured total
        $inst->add_captured_amount( $order, $amount );
        /* translators: %1$s: payment ID. %2$.2f: captured amount. */
        $order->add_order_note( sprintf( __( 'Capture success: payment_id %1$s amount %2$.2f', 'wei-alipay' ), $payment_id ?: '-', $amount ) );
        // Complete order only if fully paid
        $captured_total = (float) $order->get_meta( '_alipay_captured_total', true );
        if ( $captured_total + 0.0001 >= (float) $order->get_total() ) {
            $order->payment_complete( $payment_id );
        } else {
            // keep on-hold for partial capture
            if ( ! $order->has_status( array( 'on-hold' ) ) ) {
                $order->update_status( 'on-hold', __( 'Partially captured.', 'wei-alipay' ) );
            }
        }
        return true;
    }

    public static function unfreeze_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) { return new WP_Error( 'order', 'Order not found' ); }
        $inst  = new self();
        $amount = $inst->get_remaining_authorized( $order );
        if ( $amount <= 0 ) {
            return new WP_Error( 'amount', __( 'Nothing to unfreeze.', 'wei-alipay' ) );
        }
        $res = $inst->unfreeze_funds( $order, $amount );
        if ( is_wp_error( $res ) ) { return $res; }
        /* translators: %.2f: unfrozen amount */
        $order->add_order_note( sprintf( __( 'Unfreeze success: amount %.2f', 'wei-alipay' ), $amount ) );
        return true;
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return array( 'result' => 'failure', 'messages' => __( 'Order not found.', 'wei-alipay' ) );
        }

        $missing_fields = $this->get_missing_core_fields();
        if ( ! empty( $missing_fields ) ) {
            /* translators: %s: comma-separated list of missing configuration field names */
            $msg = sprintf( __( 'Alipay core configuration is incomplete. Missing fields: %s', 'wei-alipay' ), implode( ', ', $missing_fields ) );
            wc_add_notice( $msg, 'error' );
            self::log_msg( 'Payment blocked: missing core settings', 'error', array( 'order_id' => $order_id, 'missing' => implode( '|', $missing_fields ) ) );
            return array( 'result' => 'failure', 'messages' => $msg );
        }

        $amount = (float) $order->get_total();

        // Execute real Alipay fund freeze in both sandbox (openapi.alipaydev.com) and production.
        $freeze = $this->freeze_funds( $order, $amount );
        if ( is_wp_error( $freeze ) ) {
            wc_add_notice( $freeze->get_error_message(), 'error' );
            self::log_msg( 'Freeze failed', 'error', array( 'order_id' => $order_id, 'error' => $freeze->get_error_message() ) );
            return array( 'result' => 'failure', 'messages' => $freeze->get_error_message() );
        }
        /* translators: %1$s: authorization number. %2$.2f: frozen amount. */
        $order->add_order_note( sprintf( __( 'Fund authorized: AUTH %1$s Frozen %2$.2f', 'wei-alipay' ), $freeze['auth_no'] ?? '-', $amount ) );
        // Reset captured_total on new authorization
        $order->update_meta_data( '_alipay_captured_total', $this->format_amount( 0 ) );
        $order->update_status( 'on-hold', __( 'Funds authorized.', 'wei-alipay' ) );
        $order->save();

        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        );
    }

    /**
     * Handle signed asynchronous notifications from Alipay for fund freeze, unfreeze, and auth-to-pay capture.
     */
    public function handle_fund_auth_notify() {
        $params = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- signed Alipay server callback; verified before fields are used.
        /** @phpstan-ignore requireOnce.fileNotFound */
        require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';

        $core       = $this->get_core_settings();
        $verify_key = Alipay_SDK_Helper::resolve_verify_key( $core );
        if ( '' === $verify_key || ! Alipay_SDK_Helper::verify_notify( $params, $verify_key ) ) {
            self::log_msg( 'Fund auth notify signature verification failed', 'error' );
            status_header( 400 );
            echo 'fail';
            exit;
        }

        $expected_app_id = trim( (string) ( $core['appid'] ?? '' ) );
        $notified_app_id = isset( $params['app_id'] ) ? sanitize_text_field( (string) $params['app_id'] ) : '';
        if ( '' === $expected_app_id || '' === $notified_app_id || ! hash_equals( $expected_app_id, $notified_app_id ) ) {
            self::log_msg( 'Fund auth notify app_id mismatch', 'error' );
            status_header( 400 );
            echo 'fail';
            exit;
        }

        $expected_seller_id = trim( (string) ( $core['seller_id'] ?? '' ) );
        $notified_seller_id = isset( $params['payee_user_id'] ) ? sanitize_text_field( (string) $params['payee_user_id'] ) : ( isset( $params['seller_id'] ) ? sanitize_text_field( (string) $params['seller_id'] ) : '' );
        if ( '' !== $expected_seller_id && '' !== $notified_seller_id && ! hash_equals( $expected_seller_id, $notified_seller_id ) ) {
            self::log_msg( 'Fund auth notify seller_id mismatch', 'error' );
            status_header( 400 );
            echo 'fail';
            exit;
        }

        $out_order_no = isset( $params['out_order_no'] ) ? sanitize_text_field( (string) $params['out_order_no'] ) : '';
        $out_trade_no = isset( $params['out_trade_no'] ) ? sanitize_text_field( (string) $params['out_trade_no'] ) : '';
        $lookup_key   = '' !== $out_order_no ? '_alipay_out_order_no' : '_alipay_capture_out_trade_no';
        $lookup_val   = '' !== $out_order_no ? $out_order_no : $out_trade_no;
        if ( '' === $lookup_val ) {
            self::log_msg( 'Fund auth notify missing out_order_no/out_trade_no', 'error' );
            status_header( 400 );
            echo 'fail';
            exit;
        }

        $matches = wc_get_orders( array(
            'meta_key'   => $lookup_key,
            'meta_value' => $lookup_val,
            'limit'      => 2,
            'return'     => 'objects',
        ) );
        if ( ! is_array( $matches ) || 1 !== count( $matches ) || ! ( $matches[0] instanceof WC_Order ) ) {
            self::log_msg( 'Fund auth notify order lookup failed or ambiguous', 'error', array( 'lookup_key' => $lookup_key, 'lookup_val' => $lookup_val ) );
            status_header( 404 );
            echo 'fail';
            exit;
        }

        $order = $matches[0];
        if ( self::GATEWAY_ID !== $order->get_payment_method() ) {
            self::log_msg( 'Fund auth notify payment method mismatch', 'error', array( 'order_id' => $order->get_id() ) );
            status_header( 400 );
            echo 'fail';
            exit;
        }

        $lock = Alipay_SDK_Helper::acquire_lock( 'fund_auth_notify', $lookup_val, 30 );
        if ( false === $lock ) {
            status_header( 409 );
            echo 'fail';
            exit;
        }

        try {
            $order          = wc_get_order( $order->get_id() );
            $operation_type = isset( $params['operation_type'] ) ? strtoupper( sanitize_text_field( (string) $params['operation_type'] ) ) : '';
            $status         = isset( $params['status'] ) ? strtoupper( sanitize_text_field( (string) $params['status'] ) ) : '';
            $trade_status   = isset( $params['trade_status'] ) ? strtoupper( sanitize_text_field( (string) $params['trade_status'] ) ) : '';
            $auth_no        = isset( $params['auth_no'] ) ? sanitize_text_field( (string) $params['auth_no'] ) : '';
            $operation_id   = isset( $params['operation_id'] ) ? sanitize_text_field( (string) $params['operation_id'] ) : '';
            $trade_no       = isset( $params['trade_no'] ) ? sanitize_text_field( (string) $params['trade_no'] ) : '';

            if ( $order ) {
                if ( 'FREEZE' === $operation_type && 'SUCCESS' === $status ) {
                    if ( '' !== $auth_no ) {
                        $order->update_meta_data( '_alipay_auth_no', $auth_no );
                    }
                    if ( '' !== $operation_id ) {
                        $order->update_meta_data( '_alipay_operation_id', $operation_id );
                    }
                    $order->update_meta_data( '_alipay_auth_status', 'FROZEN' );
                    if ( $order->has_status( array( 'pending' ) ) ) {
                        $order->update_status( 'on-hold', __( 'Alipay fund authorization confirmed by async notification.', 'wei-alipay' ) );
                    }
                    $order->save();
                } elseif ( 'UNFREEZE' === $operation_type && 'SUCCESS' === $status ) {
                    $order->update_meta_data( '_alipay_auth_status', 'UNFROZEN' );
                    $order->add_order_note( __( 'Alipay fund unfreeze confirmed by async notification.', 'wei-alipay' ) );
                    $order->save();
                } elseif ( in_array( $trade_status, array( 'TRADE_SUCCESS', 'TRADE_FINISHED' ), true ) && '' !== $trade_no ) {
                    $total_amount = isset( $params['total_amount'] ) ? (float) $params['total_amount'] : 0.0;
                    if ( ! $order->is_paid() && $total_amount + 0.0001 >= (float) $order->get_total() ) {
                        $order->update_meta_data( '_alipay_auth_status', 'CAPTURED' );
                        $order->save();
                        $order->payment_complete( $trade_no );
                        /* translators: %s: Alipay transaction number */
                        $order->add_order_note( sprintf( __( 'Alipay fund capture confirmed by async notification - trade_no: %s', 'wei-alipay' ), $trade_no ) );
                    }
                }
            }
        } finally {
            Alipay_SDK_Helper::release_lock( 'fund_auth_notify', $lookup_val, $lock );
        }

        status_header( 200 );
        echo 'success';
        exit;
    }

    public function add_authorization_metabox() {
        add_meta_box(
            'wc-alipay-fund-auth-box',
            __( 'Alipay Authorization', 'wei-alipay' ),
            array( $this, 'render_authorization_metabox' ),
            'shop_order',
            'side',
            'high'
        );
    }

    public function render_authorization_metabox( $post ) {
        $order = wc_get_order( $post->ID );
        if ( ! $order ) { return; }
        $auth_no   = $order->get_meta( '_alipay_auth_no' );
        $auth_amt  = $order->get_meta( '_alipay_auth_amount' );
        echo '<p><strong>' . esc_html__( 'Auth No:', 'wei-alipay' ) . '</strong> ' . esc_html( $auth_no ?: '-' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Authorized Amount:', 'wei-alipay' ) . '</strong> ' . esc_html( $auth_amt ?: '-' ) . '</p>';

        if ( $auth_no && $auth_amt ) {
            // Capture form
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:8px;">';
            wp_nonce_field( 'woo_alipay_fund_auth_capture' );
            echo '<input type="hidden" name="action" value="woo_alipay_fund_auth_capture" />';
            echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '" />';
            echo '<p><label>' . esc_html__( 'Capture Amount', 'wei-alipay' ) . ': <input type="number" step="0.01" min="0" name="amount" value="' . esc_attr( $auth_amt ) . '" style="width:90px;" /></label></p>';
            submit_button( esc_html__( 'Capture', 'wei-alipay' ), 'primary', 'submit', false );
            echo '</form>';

            // Unfreeze form
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'woo_alipay_fund_auth_unfreeze' );
            echo '<input type="hidden" name="action" value="woo_alipay_fund_auth_unfreeze" />';
            echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '" />';
            submit_button( esc_html__( 'Unfreeze', 'wei-alipay' ), 'secondary', 'submit', false );
            echo '</form>';
        } else {
            echo '<p>' . esc_html__( 'Capture and Unfreeze actions will be available after authorization.', 'wei-alipay' ) . '</p>';
        }
    }
}
