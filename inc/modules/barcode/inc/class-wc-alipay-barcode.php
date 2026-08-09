<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Alipay_Barcode_Pay extends WC_Payment_Gateway {

    const GATEWAY_ID = 'alipay_barcode';

    /**
     * @var string
     */
    public $allow_frontend_input = 'yes';

    /**
     * @var string
     */
    public $pos_enabled = 'yes';

    /**
     * @var string
     */
    public $debug = 'no';

    public function __construct( $init_hooks = true ) {
        $this->id                 = self::GATEWAY_ID;
        $this->method_title       = __( 'Alipay Barcode Pay', 'woo-alipay-barcode' );
        $this->method_description = __( '使用支付宝条码/二维码完成当面收款（商户扫码枪或顾客出示付款码）。此网关复用 Woo Alipay 核心插件中的凭据。', 'woo-alipay-barcode' );
        $this->has_fields         = true; // 前台显示 auth_code 输入
        $this->icon               = apply_filters( 'woo_alipay_barcode_icon', WOO_ALIPAY_PLUGIN_URL . 'assets/images/alipay-barcode-icon.svg' );

        // 配置字段与设置加载
        $this->init_form_fields();
        $this->init_settings();

        $this->enabled               = $this->get_option( 'enabled', 'no' );
        $this->title                 = $this->get_option( 'title', __( '支付宝条码支付', 'woo-alipay-barcode' ) );
        $this->description           = $this->get_option( 'description', __( '请向收银员出示支付宝付款码，或在下方输入条码数字。', 'woo-alipay-barcode' ) );
        $this->allow_frontend_input  = $this->get_option( 'allow_frontend_input', 'yes' );
        $this->pos_enabled           = $this->get_option( 'pos_enabled', 'yes' );
        $this->debug                 = $this->get_option( 'debug', 'no' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        if ( 'yes' === $this->pos_enabled && is_admin() ) {
            add_action( 'add_meta_boxes', array( $this, 'add_pos_metabox' ) );
            add_action( 'wp_ajax_woo_alipay_barcode_charge', array( $this, 'ajax_pos_charge' ) );
        }

        // 异步通知（可选，后续完善签名校验）
        add_action( 'woocommerce_api_wc_alipay_barcode_notify', array( $this, 'handle_notify' ) );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'basic' => array(
                'title'       => __( '基本设置', 'woo-alipay-barcode' ),
                'type'        => 'title',
                'description' => __( '此网关使用 Woo Alipay 核心插件的凭据（应用ID/密钥/公钥等），无需在此重复配置。', 'woo-alipay-barcode' ),
            ),
            'enabled' => array(
                'title'   => __( '启用/禁用', 'woo-alipay-barcode' ),
                'type'    => 'checkbox',
                'label'   => __( '启用条码支付', 'woo-alipay-barcode' ),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __( '结账页面标题', 'woo-alipay-barcode' ),
                'type'        => 'text',
                'default'     => __( '支付宝条码支付', 'woo-alipay-barcode' ),
                'desc_tip'    => true,
                'description' => __( '在结账页面显示的支付方式名称', 'woo-alipay-barcode' ),
            ),
            'description' => array(
                'title'       => __( '结账页面说明', 'woo-alipay-barcode' ),
                'type'        => 'textarea',
                'default'     => __( '请向收银员出示支付宝付款码，或在下方输入条码数字。', 'woo-alipay-barcode' ),
            ),
            'allow_frontend_input' => array(
                'title'       => __( '前台输入付款码', 'woo-alipay-barcode' ),
                'type'        => 'checkbox',
                'label'       => __( '允许买家在结账页输入付款码', 'woo-alipay-barcode' ),
                'default'     => 'yes',
                'description' => __( '适用于自提/到店核销场景；线上邮寄通常不需要买家输入。', 'woo-alipay-barcode' ),
            ),
            'pos_enabled' => array(
                'title'       => __( '后台 POS', 'woo-alipay-barcode' ),
                'type'        => 'checkbox',
                'label'       => __( '在订单编辑页显示“条码收款”模块', 'woo-alipay-barcode' ),
                'default'     => 'yes',
            ),
            'debug' => array(
                'title'       => __( '调试日志', 'woo-alipay-barcode' ),
                'type'        => 'checkbox',
                'label'       => __( '启用日志记录', 'woo-alipay-barcode' ),
                'default'     => 'no',
            ),
        );
    }

    // 复用核心网关的凭据
    protected function get_core_settings() {
        $core = get_option( 'woocommerce_alipay_settings', array() );
        return wp_parse_args( $core, array(
            'appid'       => '',
            'private_key' => '',
            'public_key'  => '',
            'sandbox'     => 'no',
            'exchange_rate' => '',
        ) );
    }

    public function is_available() {
        if ( 'yes' !== $this->enabled ) {
            return false;
        }
        $core = $this->get_core_settings();
        $currency_supported = in_array( strtoupper( get_woocommerce_currency() ), array( 'CNY', 'RMB' ), true ) || floatval( $core['exchange_rate'] ) > 0;
        return $currency_supported && ! empty( $core['appid'] ) && ! empty( $core['private_key'] ) && ! empty( $core['public_key'] );
    }

    public function payment_fields() {
        echo wpautop( wp_kses_post( $this->description ) );
        if ( 'yes' === $this->allow_frontend_input ) {
            echo '<p><label for="alipay_auth_code">' . esc_html__( '付款码', 'woo-alipay-barcode' ) . '</label><input type="text" id="alipay_auth_code" name="alipay_auth_code" style="width: 260px;" placeholder="2853 1234 5678 9012 345" /></p>';
        }
    }

    public function validate_fields() {
        if ( 'yes' === $this->allow_frontend_input ) {
            $auth_code = isset( $_POST['alipay_auth_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['alipay_auth_code'] ) ) ) : '';
            if ( empty( $auth_code ) ) {
                wc_add_notice( __( '请输入支付宝付款码。', 'woo-alipay-barcode' ), 'error' );
                return false;
            }
        }
        return true;
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return array( 'result' => 'failure', 'messages' => __( '订单不存在。', 'woo-alipay-barcode' ) );
        }

        // Read auth code from classic checkout field.
        $auth_code = isset( $_POST['alipay_auth_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['alipay_auth_code'] ) ) ) : '';

        // Fallback for Checkout Block: retrieve from order meta (saved during Store API request).
        if ( empty( $auth_code ) ) {
            $saved = (string) $order->get_meta( '_alipay_auth_code', true );
            if ( $saved ) {
                $auth_code = $saved;
            }
        }

        if ( empty( $auth_code ) ) {
            // Respect setting: if front-end input is required, enforce presence; otherwise still require as it is essential.
            return array( 'result' => 'failure', 'messages' => __( '请输入支付宝付款码。', 'woo-alipay-barcode' ) );
        }

        $result = $this->attempt_barcode_charge( $order, $auth_code );
        if ( is_wp_error( $result ) ) {
            wc_add_notice( $result->get_error_message(), 'error' );
            return array( 'result' => 'failure', 'messages' => $result->get_error_message() );
        }

        // 成功或等待中，统一跳转收款完成页，由异步通知/人工刷新完成状态
        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        );
    }

    protected function attempt_barcode_charge( WC_Order $order, $auth_code ) {
        try {
            require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradePayRequest.php';
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradeQueryRequest.php';

            $core = $this->get_core_settings();
            $config = Alipay_SDK_Helper::get_alipay_config( array(
                'appid'       => $core['appid'],
                'private_key' => $core['private_key'],
                'public_key'  => $core['public_key'],
                'sandbox'     => $core['sandbox'],
            ) );

            $aop = Alipay_SDK_Helper::create_alipay_service( $config );
            if ( ! $aop ) {
                return new WP_Error( 'sdk_error', __( '创建支付宝服务失败', 'woo-alipay-barcode' ) );
            }

            $out_trade_no = (string) $order->get_meta( '_alipay_out_trade_no' );
            if ( ! $out_trade_no ) {
                $out_trade_no = Alipay_SDK_Helper::generate_out_trade_no( $order->get_id(), 'WooB' );
                $order->update_meta_data( '_alipay_out_trade_no', $out_trade_no );
                $order->save();
            }

            $total = $this->format_amount_for_cny( $order );
            if ( is_wp_error( $total ) ) {
                return $total;
            }
            $subject = $this->get_order_title_excerpt( $order );

            $biz = array(
                'out_trade_no' => $out_trade_no,
                'scene'        => 'bar_code',
                'auth_code'    => $auth_code,
                'subject'      => $subject,
                'total_amount' => $total,
            );

            $request = new AlipayTradePayRequest();
            $request->setBizContent( wp_json_encode( $biz ) );
            $request->setNotifyUrl( WC()->api_request_url( 'WC_Alipay_Barcode_Notify' ) );

            $response = $aop->execute( $request );
            $node     = 'alipay_trade_pay_response';
            $res      = isset( $response->$node ) ? $response->$node : null;

            if ( ! $res || ! isset( $res->code ) ) {
                return new WP_Error( 'alipay_error', __( '支付宝响应异常。', 'woo-alipay-barcode' ) );
            }

            if ( '10000' === (string) $res->code ) {
                // 即时支付成功
                $trade_no = isset( $res->trade_no ) ? (string) $res->trade_no : '';
                $response_out_trade_no = isset( $res->out_trade_no ) ? (string) $res->out_trade_no : '';
                $response_amount = isset( $res->total_amount ) ? (string) $res->total_amount : '';
                if ( !$trade_no || !hash_equals( $out_trade_no, $response_out_trade_no ) || !Alipay_SDK_Helper::amounts_equal( $total, $response_amount ) ) {
                    return new WP_Error( 'alipay_context_mismatch', __( '支付宝即时响应与订单不匹配。', 'woo-alipay-barcode' ) );
                }
                $order->payment_complete( $trade_no );
                $order->add_order_note( __( '条码支付成功（即时返回）。', 'woo-alipay-barcode' ) );
                WC()->cart && WC()->cart->empty_cart();
                return true;
            }

            if ( '10003' === (string) $res->code ) {
                // 用户支付中，等待回调/查询
                $order->update_status( 'on-hold', __( '用户正在支付中，等待确认。', 'woo-alipay-barcode' ) );
                $order->add_order_note( __( '条码支付返回 USER_PAYING，等待异步通知或后续查询。', 'woo-alipay-barcode' ) );
                return true;
            }

            $msg = isset( $res->sub_msg ) ? (string) $res->sub_msg : ( isset( $res->msg ) ? (string) $res->msg : __( '支付失败', 'woo-alipay-barcode' ) );
            return new WP_Error( 'alipay_pay_failed', $msg );
        } catch ( Exception $e ) {
            return new WP_Error( 'exception', $e->getMessage() );
        }
    }

    protected function format_amount_for_cny( WC_Order $order ) {
        $currency = $order->get_currency();
        $core     = $this->get_core_settings();
        $rate     = isset( $core['exchange_rate'] ) ? floatval( $core['exchange_rate'] ) : 0.0;
        if ( in_array( strtoupper( $currency ), array( 'CNY', 'RMB' ), true ) ) {
            return number_format( (float) $order->get_total(), 2, '.', '' );
        }
        if ( $rate > 0 ) {
            return number_format( (float) $order->get_total() * $rate, 2, '.', '' );
        }
        return new WP_Error(
            'unsupported_currency',
            __( '当前订单币种需要先配置人民币汇率，已阻止发送错误金额。', 'woo-alipay-barcode' )
        );
    }

    protected function get_order_title_excerpt( WC_Order $order ) {
        $title = '#' . $order->get_id() . ' ';
        foreach ( $order->get_items() as $item ) {
            $title .= $item->get_name();
            break;
        }
        $title = str_replace( '%', '', $title );
        if ( function_exists( 'mb_strlen' ) && mb_strlen( $title ) > 128 ) {
            $title = mb_substr( $title, 0, 125 ) . '...';
        } elseif ( strlen( $title ) > 256 ) {
            $title = substr( $title, 0, 253 ) . '...';
        }
        return $title;
    }

    // 后台 POS 元框
    public function add_pos_metabox() {
        add_meta_box(
            'wc-alipay-barcode-pos',
            __( '支付宝条码收款', 'woo-alipay-barcode' ),
            array( $this, 'render_pos_metabox' ),
            'shop_order',
            'side',
            'high'
        );
    }

    public function render_pos_metabox( $post ) {
        $order = wc_get_order( $post->ID );
        if ( ! $order ) { return; }
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        wp_nonce_field( 'woo_alipay_barcode_pos', 'woo_alipay_barcode_nonce' );
        echo '<p><input type="text" id="alipay_auth_code_admin" placeholder="2853 1234 5678 9012 345" style="width:100%;" /></p>';
        echo '<p><a href="#" class="button button-primary" id="alipay-barcode-charge" data-order-id="' . esc_attr( $order->get_id() ) . '">' . esc_html__( '条码收款', 'woo-alipay-barcode' ) . '</a></p>';
        echo '<div id="alipay-barcode-charge-result"></div>';
        // 简易内联脚本（仅订单页生效，不干扰支付设置 React）
        echo '<script>jQuery(function($){$("#alipay-barcode-charge").on("click",function(e){e.preventDefault();var $btn=$(this);var code=$("#alipay_auth_code_admin").val()||"";if(!code){alert("请输入付款码");return;} $btn.prop("disabled",true);$("#alipay-barcode-charge-result").text("处理中...");$.post(ajaxurl,{action:"woo_alipay_barcode_charge",order_id:$btn.data("order-id"),auth_code:code,nonce:$("#woo_alipay_barcode_nonce").val()},function(resp){$btn.prop("disabled",false);if(resp&&resp.success){$("#alipay-barcode-charge-result").html("<span style=\"color:green\">"+(resp.data&&resp.data.message?resp.data.message:"成功")+"</span>");}else{$("#alipay-barcode-charge-result").html("<span style=\"color:red\">"+(resp&&resp.data?resp.data:"失败")+"</span>");}});});});</script>';
    }

    public function ajax_pos_charge() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( '权限不足', 'woo-alipay-barcode' ) );
        }
        check_admin_referer( 'woo_alipay_barcode_pos', 'nonce' );
        $order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $auth_code = isset( $_POST['auth_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['auth_code'] ) ) ) : '';
        $order     = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( __( '订单不存在', 'woo-alipay-barcode' ) );
        }
        if ( empty( $auth_code ) ) {
            wp_send_json_error( __( '请输入付款码', 'woo-alipay-barcode' ) );
        }
        $res = $this->attempt_barcode_charge( $order, $auth_code );
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( $res->get_error_message() );
        }
        wp_send_json_success( array( 'message' => __( '已发起收款，请关注订单状态。', 'woo-alipay-barcode' ) ) );
    }

    // 异步通知处理（基础占位，后续可增强签名校验与订单校验逻辑）
    public function handle_notify() {
        $params = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $core   = $this->get_core_settings();
        if ( empty( $params ) ) { exit; }

        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        $verified = Alipay_SDK_Helper::verify_notify( $params, $core['public_key'] );
        if ( ! $verified ) { echo 'fail'; exit; }

        $out_trade_no = isset( $params['out_trade_no'] ) ? sanitize_text_field( $params['out_trade_no'] ) : '';
        $trade_no     = isset( $params['trade_no'] ) ? sanitize_text_field( $params['trade_no'] ) : '';
        $status       = isset( $params['trade_status'] ) ? sanitize_text_field( $params['trade_status'] ) : '';

        $order = Alipay_SDK_Helper::find_order_by_out_trade_no( $out_trade_no, array( self::GATEWAY_ID ) );
        if ( is_wp_error( $order ) ) { echo 'fail'; exit; }

        $expected_amount = $this->format_amount_for_cny( $order );
        if ( is_wp_error( $expected_amount ) ) { echo 'fail'; exit; }
        $context_check = Alipay_SDK_Helper::validate_notify_context(
            $params,
            $order,
            $core['appid'],
            $expected_amount,
            (string) $order->get_meta( '_alipay_out_trade_no' ),
            array( self::GATEWAY_ID ),
            isset( $core['seller_id'] ) ? $core['seller_id'] : ''
        );
        if ( is_wp_error( $context_check ) ) {
            Alipay_SDK_Helper::log( '条码支付通知上下文校验失败: ' . $context_check->get_error_code(), 'error' );
            echo 'fail';
            exit;
        }

        $transition = Alipay_SDK_Helper::validate_notify_transition( $order, $status );
        if ( is_wp_error( $transition ) ) { echo 'fail'; exit; }
        $lock = Alipay_SDK_Helper::acquire_lock( 'notify', $out_trade_no, 30 );
        if ( false === $lock ) { echo 'fail'; exit; }
        $order = wc_get_order( $order->get_id() );
        $expected_amount = $this->format_amount_for_cny( $order );
        $context_check = is_wp_error( $expected_amount ) ? $expected_amount : Alipay_SDK_Helper::validate_notify_context(
            $params,
            $order,
            $core['appid'],
            $expected_amount,
            (string) $order->get_meta( '_alipay_out_trade_no' ),
            array( self::GATEWAY_ID ),
            isset( $core['seller_id'] ) ? $core['seller_id'] : ''
        );
        $transition = is_wp_error( $context_check ) ? $context_check : Alipay_SDK_Helper::validate_notify_transition( $order, $status );
        if ( is_wp_error( $transition ) ) {
            Alipay_SDK_Helper::release_lock( 'notify', $out_trade_no, $lock );
            echo 'fail';
            exit;
        }

        if ( 'process_payment' === $transition ) {
            if ( ! $order->is_paid() ) {
                $order->payment_complete( $trade_no );
                $order->add_order_note( __( '支付宝条码支付成功（异步通知）。', 'woo-alipay-barcode' ) );
            }
        } elseif ( 'process_close' === $transition ) {
            $order->update_status( 'cancelled', __( '支付宝关闭交易。', 'woo-alipay-barcode' ) );
        }
        Alipay_SDK_Helper::release_lock( 'notify', $out_trade_no, $lock );
        echo 'success';
        exit;
    }

    // WooCommerce Payments 列表徽章兼容
    public function is_account_connected() {
        $core = $this->get_core_settings();
        return (bool) ( $core['appid'] && $core['private_key'] && $core['public_key'] );
    }
    public function needs_setup() { return ! $this->is_account_connected(); }
    public function is_test_mode() {
        $core = $this->get_core_settings();
        return 'yes' === $core['sandbox'];
    }
}
