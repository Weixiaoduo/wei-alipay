<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Alipay_Fund_Auth extends WC_Payment_Gateway {

    protected static $log_enabled = false;
    protected static $log = null;
    protected $required_core_fields = array( 'appid', 'private_key', 'public_key' );

    const GATEWAY_ID = 'alipay_fund_auth';

    public function __construct() {
        $this->id                 = self::GATEWAY_ID;
        $this->method_title       = __( 'Alipay Fund Authorization', 'woo-alipay' );
        $this->method_description = __( 'Freeze customer funds first, then capture or unfreeze later.', 'woo-alipay' );
        $this->title              = __( 'Alipay Fund Authorization', 'woo-alipay' );
        $this->description        = __( 'Your payment will be authorized (frozen) now and captured upon fulfillment.', 'woo-alipay' );
        $this->icon               = WOO_ALIPAY_PLUGIN_URL . 'assets/images/alipay-icon.svg';
        $this->has_fields         = false;
        $this->supports           = array( 'products' );

        $this->init_form_fields();
        $this->init_settings();

        self::$log_enabled = ( 'yes' === $this->get_option( 'debug', 'no' ) );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'admin_notices', array( $this, 'requirement_checks' ) );

        if ( is_admin() ) {
            add_action( 'add_meta_boxes', array( $this, 'add_authorization_metabox' ) );
        }

        // Optional: automatic capture/unfreeze hooks to be added in M2.
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'woo-alipay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Alipay Fund Authorization', 'woo-alipay' ),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __( 'Title', 'woo-alipay' ),
                'type'        => 'text',
                'default'     => __( 'Alipay Fund Authorization', 'woo-alipay' ),
            ),
            'description' => array(
                'title'       => __( 'Description', 'woo-alipay' ),
                'type'        => 'textarea',
                'default'     => __( 'Your payment will be authorized (frozen) now and captured upon fulfillment.', 'woo-alipay' ),
            ),
            'capture_on_complete' => array(
                'title'       => __( 'Auto-capture on order complete', 'woo-alipay' ),
                'type'        => 'checkbox',
                'label'       => __( 'When order is marked completed, automatically capture authorized funds', 'woo-alipay' ),
                'default'     => 'no',
            ),
            'unfreeze_on_cancel' => array(
                'title'       => __( 'Auto-unfreeze on cancel', 'woo-alipay' ),
                'type'        => 'checkbox',
                'label'       => __( 'When order is cancelled, automatically unfreeze funds', 'woo-alipay' ),
                'default'     => 'yes',
            ),
            'allow_partial_capture' => array(
                'title'       => __( 'Allow partial capture', 'woo-alipay' ),
                'type'        => 'checkbox',
                'label'       => __( 'Allow capturing less than the authorized amount', 'woo-alipay' ),
                'default'     => 'no',
            ),
            'auto_capture_amount_mode' => array(
                'title'       => __( 'Auto-capture amount mode', 'woo-alipay' ),
                'type'        => 'select',
                'description' => __( 'When auto-capture is enabled, choose how much to capture.', 'woo-alipay' ),
                'default'     => 'authorized',
                'options'     => array(
                    'authorized' => __( 'Authorized amount', 'woo-alipay' ),
                    'order_total'=> __( 'Order total', 'woo-alipay' ),
                    'remaining'  => __( 'Remaining authorized amount', 'woo-alipay' ),
                ),
            ),
            'debug' => array(
                'title'       => __( 'Debug log', 'woo-alipay' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable logging', 'woo-alipay' ),
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
            echo '<div class="error"><p>' . esc_html( sprintf( __( 'Alipay fund auth requires core credentials. Missing fields: %s', 'woo-alipay' ), implode( ', ', $missing ) ) ) . '</p></div>';
        }
    }

    private function get_core_settings() {
        return wp_parse_args( get_option( 'woocommerce_alipay_settings', array() ), array(
            'appid'       => '',
            'private_key' => '',
            'public_key'  => '',
            'sandbox'     => 'no',
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
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';

        $missing_fields = $this->get_missing_core_fields();
        if ( ! empty( $missing_fields ) ) {
            self::log_msg( 'Missing core settings for fund auth AOP client', 'error', array( 'missing' => implode( '|', $missing_fields ) ) );
            return array( null, array() );
        }

        $core   = $this->get_core_settings();
        $config = Alipay_SDK_Helper::get_alipay_config( array(
            'appid'       => $core['appid'],
            'private_key' => $core['private_key'],
            'public_key'  => $core['public_key'],
            'sandbox'     => $core['sandbox'],
        ) );
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
                return new WP_Error( 'sdk', __( 'Failed to init Alipay service.', 'woo-alipay' ) );
            }

            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayFundAuthOrderFreezeRequest.php';

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
                return new WP_Error( 'alipay', __( 'Empty response from Alipay.', 'woo-alipay' ) );
            }
            if ( '10000' !== (string) $res->code ) {
                self::log_msg( array( 'freeze_error' => $resp ), 'error' );
                $msg = ( isset( $res->sub_msg ) && $res->sub_msg ) ? $res->sub_msg : ( $res->msg ?? __( 'Freeze failed', 'woo-alipay' ) );
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
                return new WP_Error( 'sdk', __( 'Failed to init Alipay service.', 'woo-alipay' ) );
            }
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayFundAuthOrderVoucherCreateRequest.php';
            $auth_no = (string) $order->get_meta( '_alipay_auth_no' );
            if ( ! $auth_no ) {
                return new WP_Error( 'param', __( 'Missing auth_no.', 'woo-alipay' ) );
            }
            $out_request_no = 'FAC-' . ( is_multisite() ? get_current_blog_id() . '-' : '' ) . $order->get_id() . '-' . time();
            $biz = array(
                'auth_no'        => $auth_no,
                'out_request_no' => $out_request_no,
                'amount'         => $this->format_amount( $amount ),
                'order_title'    => sprintf( 'Capture for order #%d', $order->get_id() ),
            );
            $req = new AlipayFundAuthOrderVoucherCreateRequest();
            $req->setBizContent( wp_json_encode( $biz, JSON_UNESCAPED_UNICODE ) );
            self::log_msg( array( 'capture_biz' => $biz ) );
            $resp = $aop->execute( $req );
            $node = 'alipay_fund_auth_order_voucher_create_response';
            $res  = $resp->$node ?? null;
            if ( ! $res || ! isset( $res->code ) ) {
                return new WP_Error( 'alipay', __( 'Empty response from Alipay.', 'woo-alipay' ) );
            }
            if ( '10000' !== (string) $res->code ) {
                self::log_msg( array( 'capture_error' => $resp ), 'error' );
                $msg = ( $res->sub_msg ?? $res->msg ?? __( 'Capture failed', 'woo-alipay' ) );
                return new WP_Error( 'alipay', $msg );
            }
            $payment_id = isset( $res->payment_id ) ? (string) $res->payment_id : '';
            return array( 'payment_id' => $payment_id );
        } catch ( Exception $e ) {
            return new WP_Error( 'exception', $e->getMessage() );
        }
    }

    private function unfreeze_funds( WC_Order $order, $amount = null ) {
        try {
            list( $aop, $config ) = $this->create_aop_from_core();
            if ( ! $aop ) {
                return new WP_Error( 'sdk', __( 'Failed to init Alipay service.', 'woo-alipay' ) );
            }
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayFundAuthOrderUnfreezeRequest.php';
            $auth_no      = (string) $order->get_meta( '_alipay_auth_no' );
            $auth_amount  = (float) ( null === $amount ? $this->get_remaining_authorized( $order ) : $amount );
            if ( ! $auth_no ) {
                return new WP_Error( 'param', __( 'Missing auth_no.', 'woo-alipay' ) );
            }
            $out_request_no = 'FAU-' . ( is_multisite() ? get_current_blog_id() . '-' : '' ) . $order->get_id() . '-' . time();
            $biz = array(
                'auth_no'        => $auth_no,
                'out_request_no' => $out_request_no,
                'amount'         => $this->format_amount( $auth_amount ),
                'remark'         => 'Woo Alipay unfreeze',
            );
            $req = new AlipayFundAuthOrderUnfreezeRequest();
            $req->setBizContent( wp_json_encode( $biz, JSON_UNESCAPED_UNICODE ) );
            self::log_msg( array( 'unfreeze_biz' => $biz ) );
            $resp = $aop->execute( $req );
            $node = 'alipay_fund_auth_order_unfreeze_response';
            $res  = $resp->$node ?? null;
            if ( ! $res || ! isset( $res->code ) ) {
                return new WP_Error( 'alipay', __( 'Empty response from Alipay.', 'woo-alipay' ) );
            }
            if ( '10000' !== (string) $res->code ) {
                self::log_msg( array( 'unfreeze_error' => $resp ), 'error' );
                $msg = ( $res->sub_msg ?? $res->msg ?? __( 'Unfreeze failed', 'woo-alipay' ) );
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
            return new WP_Error( 'amount', __( 'Invalid capture amount (exceeds remaining authorized).', 'woo-alipay' ) );
        }
        $res = $inst->capture_funds( $order, $amount );
        if ( is_wp_error( $res ) ) { return $res; }
        $payment_id = $res['payment_id'] ?? '';
        // Update captured total
        $inst->add_captured_amount( $order, $amount );
        $order->add_order_note( sprintf( __( 'Capture success: payment_id %1$s amount %2$.2f', 'woo-alipay' ), $payment_id ?: '-', $amount ) );
        // Complete order only if fully paid
        $captured_total = (float) $order->get_meta( '_alipay_captured_total', true );
        if ( $captured_total + 0.0001 >= (float) $order->get_total() ) {
            $order->payment_complete( $payment_id );
        } else {
            // keep on-hold for partial capture
            if ( ! $order->has_status( array( 'on-hold' ) ) ) {
                $order->update_status( 'on-hold', __( 'Partially captured.', 'woo-alipay' ) );
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
            return new WP_Error( 'amount', __( 'Nothing to unfreeze.', 'woo-alipay' ) );
        }
        $res = $inst->unfreeze_funds( $order, $amount );
        if ( is_wp_error( $res ) ) { return $res; }
        $order->add_order_note( sprintf( __( 'Unfreeze success: amount %.2f', 'woo-alipay' ), $amount ) );
        return true;
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return array( 'result' => 'failure', 'messages' => __( 'Order not found.', 'woo-alipay' ) );
        }

        $missing_fields = $this->get_missing_core_fields();
        if ( ! empty( $missing_fields ) ) {
            $msg = sprintf( __( 'Alipay core configuration is incomplete. Missing fields: %s', 'woo-alipay' ), implode( ', ', $missing_fields ) );
            wc_add_notice( $msg, 'error' );
            self::log_msg( 'Payment blocked: missing core settings', 'error', array( 'order_id' => $order_id, 'missing' => implode( '|', $missing_fields ) ) );
            return array( 'result' => 'failure', 'messages' => $msg );
        }

        // M1: Sandbox simulate; Production: warn not configured (to be implemented with real freeze call in next step)
        $core = get_option( 'woocommerce_alipay_settings', array() );
        $sandbox = isset( $core['sandbox'] ) && 'yes' === $core['sandbox'];
        $amount  = (float) $order->get_total();

        if ( $sandbox ) {
            $auth_no = 'SIM-AUTH-' . time();
            $order->update_meta_data( '_alipay_auth_no', $auth_no );
            $order->update_meta_data( '_alipay_auth_amount', number_format( $amount, 2, '.', '' ) );
            $order->add_order_note( sprintf( __( 'Simulated fund authorization: AUTH %1$s Frozen %2$.2f', 'woo-alipay' ), $auth_no, $amount ) );
            $order->update_status( 'on-hold', __( 'Funds authorized (simulated).', 'woo-alipay' ) );
            $order->save();
            self::log_msg( 'Sandbox authorization success', 'info', array( 'order_id' => $order_id, 'auth_no' => $auth_no, 'amount' => number_format( $amount, 2, '.', '' ) ) );

            return array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_order_received_url(),
            );
        }

        // Production: try real freeze
        $freeze = $this->freeze_funds( $order, $amount );
        if ( is_wp_error( $freeze ) ) {
            wc_add_notice( $freeze->get_error_message(), 'error' );
            self::log_msg( 'Freeze failed', 'error', array( 'order_id' => $order_id, 'error' => $freeze->get_error_message() ) );
            return array( 'result' => 'failure', 'messages' => $freeze->get_error_message() );
        }
        $order->add_order_note( sprintf( __( 'Fund authorized: AUTH %1$s Frozen %2$.2f', 'woo-alipay' ), $freeze['auth_no'] ?? '-', $amount ) );
        // Reset captured_total on new authorization
        $order->update_meta_data( '_alipay_captured_total', $this->format_amount( 0 ) );
        $order->update_status( 'on-hold', __( 'Funds authorized.', 'woo-alipay' ) );
        $order->save();

        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        );
    }

    public function add_authorization_metabox() {
        add_meta_box(
            'wc-alipay-fund-auth-box',
            __( 'Alipay Authorization', 'woo-alipay' ),
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
        echo '<p><strong>' . esc_html__( 'Auth No:', 'woo-alipay' ) . '</strong> ' . esc_html( $auth_no ?: '-' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Authorized Amount:', 'woo-alipay' ) . '</strong> ' . esc_html( $auth_amt ?: '-' ) . '</p>';

        if ( $auth_no && $auth_amt ) {
            // Capture form
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:8px;">';
            wp_nonce_field( 'woo_alipay_fund_auth_capture' );
            echo '<input type="hidden" name="action" value="woo_alipay_fund_auth_capture" />';
            echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '" />';
            echo '<p><label>' . esc_html__( 'Capture Amount', 'woo-alipay' ) . ': <input type="number" step="0.01" min="0" name="amount" value="' . esc_attr( $auth_amt ) . '" style="width:90px;" /></label></p>';
            submit_button( esc_html__( 'Capture', 'woo-alipay' ), 'primary', 'submit', false );
            echo '</form>';

            // Unfreeze form
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'woo_alipay_fund_auth_unfreeze' );
            echo '<input type="hidden" name="action" value="woo_alipay_fund_auth_unfreeze" />';
            echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '" />';
            submit_button( esc_html__( 'Unfreeze', 'woo-alipay' ), 'secondary', 'submit', false );
            echo '</form>';
        } else {
            echo '<p>' . esc_html__( 'Capture and Unfreeze actions will be available after authorization.', 'woo-alipay' ) . '</p>';
        }
    }
}
