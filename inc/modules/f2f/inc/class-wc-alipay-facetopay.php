<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 支付宝当面付支付网关
 * 
 * 支持扫码支付功能，商家展示二维码供用户扫描支付
 * 适用于收银台、实体店等线下场景
 */
class WC_Alipay_FaceToPay extends WC_Payment_Gateway
{
    const GATEWAY_ID = 'alipay_facetopay';
    
    protected static $log_enabled = false;
    protected static $log = false;
    protected static $refund_request_id = '';
    
    protected $current_currency;
    protected $exchange_rate;
    protected $order_prefix;
    protected $notify_url;
    protected $charset;

    public function __construct()
    {
        $this->id = self::GATEWAY_ID;
        $this->method_title = __('支付宝当面付', 'wei-alipay');
        $this->method_description = __('支持当面付扫码支付，商家展示二维码供用户扫描。适用于收银台、实体店等场景。需要在支付宝商户后台开通当面付功能。', 'wei-alipay');
        $this->icon = WEI_ALIPAY_PLUGIN_URL . 'assets/images/alipay-f2f-icon.svg';
        $this->has_fields = false;
        $this->charset = strtolower(get_bloginfo('charset'));
        $this->current_currency = get_option('woocommerce_currency');

        if (!in_array($this->charset, array('gbk', 'utf-8'), true)) {
            $this->charset = 'utf-8';
        }
        
        $this->init_form_fields();
        $this->init_settings();

        
        $this->title = $this->get_option('title', __('支付宝扫码支付', 'wei-alipay'));
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->exchange_rate = $this->get_option('exchange_rate');
        $this->order_prefix = $this->get_option('order_prefix', 'F2F');
        $this->notify_url = WC()->api_request_url('WC_Alipay_FaceToPay');
        
        self::$log_enabled = ('yes' === $this->get_option('debug', 'no'));
        
        $this->supports = array(
            'products',
            'refunds',
        );
        
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_create_refund', array($this, 'remember_refund_request_id'), 10, 2);
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_api_wc_alipay_facetopay', array($this, 'check_alipay_response'));
        add_action('wp_ajax_alipay_facetopay_query', array($this, 'ajax_query_payment_status'));
        add_action('wp_ajax_nopriv_alipay_facetopay_query', array($this, 'ajax_query_payment_status'));
        add_action('wp_ajax_alipay_facetopay_refresh_qrcode', array($this, 'ajax_refresh_qrcode'));
        add_action('wp_ajax_nopriv_alipay_facetopay_refresh_qrcode', array($this, 'ajax_refresh_qrcode'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
    }

    public function admin_options()
    {
        echo '<h3>' . esc_html__('支付宝当面付', 'wei-alipay') . '</h3>';
        echo '<p>' . esc_html__('用于线下扫码场景的当面付支付方式。', 'wei-alipay') . '</p>';


        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }

    // Provider state methods for WooCommerce Payments list badges
    public function is_account_connected() {
        $core = get_option( 'woocommerce_alipay_settings', array() );
        return (bool) ( ! empty( $core['appid'] ) && ! empty( $core['private_key'] ) && ! empty( $core['public_key'] ) );
    }

    public function needs_setup() {
        return ! $this->is_account_connected();
    }

    public function is_test_mode() {
        $core = get_option( 'woocommerce_alipay_settings', array() );
        return ! empty( $core['sandbox'] ) && 'yes' === $core['sandbox'];
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('启用/禁用', 'wei-alipay'),
                'type' => 'checkbox',
                'label' => __('启用支付宝当面付', 'wei-alipay'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('标题', 'wei-alipay'),
                'type' => 'text',
                'description' => __('用户在结账时看到的支付方式名称', 'wei-alipay'),
                'default' => __('支付宝扫码支付', 'wei-alipay'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('描述', 'wei-alipay'),
                'type' => 'textarea',
                'description' => __('支付方式描述', 'wei-alipay'),
                'default' => __('使用支付宝扫描二维码完成支付', 'wei-alipay'),
                'desc_tip' => true,
            ),
            
            'qrcode_settings' => array(
                'title' => __('二维码设置', 'wei-alipay'),
                'type' => 'title',
            ),
            'qrcode_size' => array(
                'title' => __('二维码尺寸', 'wei-alipay'),
                'type' => 'number',
                'description' => __('二维码显示尺寸（像素）', 'wei-alipay'),
                'default' => '300',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => '100',
                    'max' => '500',
                    'step' => '10',
                ),
            ),
            'qrcode_timeout' => array(
                'title' => __('二维码有效期', 'wei-alipay'),
                'type' => 'select',
                'description' => __('二维码的有效时间', 'wei-alipay'),
                'default' => '120',
                'options' => array(
                    '60' => __('1分钟', 'wei-alipay'),
                    '120' => __('2分钟', 'wei-alipay'),
                    '180' => __('3分钟', 'wei-alipay'),
                    '300' => __('5分钟', 'wei-alipay'),
                    '600' => __('10分钟', 'wei-alipay'),
                ),
                'desc_tip' => true,
            ),
            
            'polling_settings' => array(
                'title' => __('轮询设置', 'wei-alipay'),
                'type' => 'title',
            ),
            'polling_interval' => array(
                'title' => __('轮询间隔', 'wei-alipay'),
                'type' => 'number',
                'description' => __('查询支付状态的间隔时间（秒）', 'wei-alipay'),
                'default' => '2',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => '1',
                    'max' => '10',
                    'step' => '1',
                ),
            ),
            
            'order_prefix' => array(
                'title' => __('订单号前缀', 'wei-alipay'),
                'type' => 'text',
                'description' => __('当面付订单号的前缀', 'wei-alipay'),
                'default' => 'F2F',
                'desc_tip' => true,
            ),
            
            'debug' => array(
                'title' => __('调试日志', 'wei-alipay'),
                'type' => 'checkbox',
                'label' => __('启用日志记录', 'wei-alipay'),
                'default' => 'no',
                'description' => sprintf(
                    /* translators: %s: log file path */
                    __('记录当面付相关日志到 %s', 'wei-alipay'),
                    '<code>' . WC_Log_Handler_File::get_log_file_path($this->id) . '</code>'
                ),
            ),
        );
        
        if (!in_array($this->current_currency, array('CNY', 'RMB'), true)) {
            $this->form_fields['exchange_rate'] = array(
                'title' => __('汇率', 'wei-alipay'),
                'type' => 'number',
                'description' => sprintf(
                    /* translators: %s: store currency code */
                    __('设置 %s 与人民币的汇率', 'wei-alipay'),
                    $this->current_currency
                ),
                'default' => '7.0',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'step' => '0.01',
                    'min' => '0.01',
                ),
            );
        }
    }

    public function payment_scripts()
    {
        if (!is_checkout() && !is_order_received_page()) {
            return;
        }
        
        $style_url  = apply_filters( 'woo_alipay_facetopay_style_url', WEI_ALIPAY_F2F_PLUGIN_URL . 'css/alipay-facetopay.css' );
        $script_url = apply_filters( 'woo_alipay_facetopay_script_url', WEI_ALIPAY_F2F_PLUGIN_URL . 'js/alipay-facetopay.js' );

        wp_enqueue_style(
            'alipay-facetopay',
            $style_url,
            array(),
            WEI_ALIPAY_VERSION
        );
        
        wp_enqueue_script(
            'qrcodejs',
            WEI_ALIPAY_F2F_PLUGIN_URL . 'js/qrcodejs/qrcode.min.js',
            array(),
            '1.0.0',
            true
        );
        
        wp_enqueue_script(
            'alipay-facetopay',
            $script_url,
            array('jquery', 'qrcodejs'),
            WEI_ALIPAY_VERSION,
            true
        );
        
        wp_localize_script('alipay-facetopay', 'alipayFaceToPayParams', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'polling_interval' => intval($this->get_option('polling_interval', 2)) * 1000,
            'timeout' => intval($this->get_option('qrcode_timeout', 120)),
        ));
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        
        $order->update_status('pending', __('等待扫码支付', 'wei-alipay'));
        WC()->cart->empty_cart();
        
        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        );
    }

    public function receipt_page($order_id)
    {
        $order = wc_get_order($order_id);
        
        if (!$order || $order->is_paid()) {
            return;
        }
        
        require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        
        $main_gateway = new WC_Alipay(false);
        $config = Alipay_SDK_Helper::get_alipay_config(array(
            'appid' => $main_gateway->get_option('appid'),
            'private_key' => $main_gateway->get_option('private_key'),
            'public_key' => $main_gateway->get_option('public_key'),
            'sandbox' => $main_gateway->get_option('sandbox'),
        ));
        
        $aop = Alipay_SDK_Helper::create_alipay_service($config);
        if (!$aop) {
            wc_add_notice(__('支付初始化失败', 'wei-alipay'), 'error');
            return;
        }
        
        $total = $this->convert_to_rmb($order->get_total());
        $out_trade_no = (string) $order->get_meta('_alipay_out_trade_no');
        if (!$out_trade_no) {
            $out_trade_no = (string) $order->get_meta('_alipay_out_trade_no');
            if (!$out_trade_no) {
                $out_trade_no = Alipay_SDK_Helper::generate_out_trade_no($order_id, $this->order_prefix);
                $order->update_meta_data('_alipay_out_trade_no', $out_trade_no);
                $order->save();
            }
        }
        
        try {
            require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradePrecreateRequest.php';
            
            $request = new AlipayTradePrecreateRequest();
            
            $biz_content = array(
                'out_trade_no' => $out_trade_no,
                'total_amount' => Alipay_SDK_Helper::format_amount($total),
                'subject' => $this->get_order_title($order),
                'body' => $this->get_order_description($order),
                'timeout_express' => $this->get_option('qrcode_timeout', 120) . 's',
            );
            
            $request->setBizContent(json_encode($biz_content));
            $request->setNotifyUrl($this->notify_url);
            
            $response = $aop->execute($request);
            $response_node = 'alipay_trade_precreate_response';
            $result = $response->$response_node;
            
            if (isset($result->code) && $result->code === '10000') {
                $qr_code = $result->qr_code;
                $order->update_meta_data('_alipay_qrcode', $qr_code);
                $order->save();
                
                self::log('当面付二维码生成成功: ' . $out_trade_no);
                
                $this->display_qrcode_page($order, $qr_code);
            } else {
                self::log('当面付二维码生成失败: ' . ($result->sub_msg ?? $result->msg), 'error');
                wc_add_notice(__('生成支付二维码失败', 'wei-alipay'), 'error');
            }
            
        } catch (Exception $e) {
            self::log('当面付异常: ' . $e->getMessage(), 'error');
            wc_add_notice(__('支付请求失败', 'wei-alipay'), 'error');
        }
    }

    protected function display_qrcode_page($order, $qr_code)
    {
        $qrcode_size = intval($this->get_option('qrcode_size', 300));
        $timeout = intval($this->get_option('qrcode_timeout', 120));
        
        $template_path = apply_filters( 'woo_alipay_facetopay_qrcode_template', WEI_ALIPAY_PLUGIN_PATH . 'inc/templates/payment-qrcode.php', $order, $qr_code, $qrcode_size, $timeout );
        include $template_path;
    }

    protected function is_authorized_order_request($order)
    {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo order-key authorization for guest checkout.
        $order_key = isset($_POST['order_key']) && is_scalar($_POST['order_key']) ? sanitize_text_field(wp_unslash((string) $_POST['order_key'])) : '';
        return $order_key && hash_equals($order->get_order_key(), $order_key);
    }

    public function ajax_refresh_qrcode()
    {
        check_ajax_referer('alipay_facetopay_query', 'nonce');
        $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
        if (!$order_id) {
            wp_send_json_error(array('message' => 'Invalid order ID'));
        }
        $order = wc_get_order($order_id);
        if (!$order || 'alipay_facetopay' !== $order->get_payment_method() || !$this->is_authorized_order_request($order) || $order->is_paid()) {
            wp_send_json_error(array('message' => 'Invalid order'), 403);
        }
        require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        $main_gateway = new WC_Alipay(false);
        $config = Alipay_SDK_Helper::get_alipay_config(array(
            'appid' => $main_gateway->get_option('appid'),
            'private_key' => $main_gateway->get_option('private_key'),
            'public_key' => $main_gateway->get_option('public_key'),
            'sandbox' => $main_gateway->get_option('sandbox'),
        ));
        try {
            require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradePrecreateRequest.php';
            $aop = Alipay_SDK_Helper::create_alipay_service($config);
            if (!$aop) {
                wp_send_json_error(array('message' => 'SDK init failed'));
            }
            $out_trade_no = (string) $order->get_meta('_alipay_out_trade_no');
            if (!$out_trade_no) {
                $out_trade_no = Alipay_SDK_Helper::generate_out_trade_no($order_id, $this->order_prefix);
                $order->update_meta_data('_alipay_out_trade_no', $out_trade_no);
                $order->save();
            }
            $request = new AlipayTradePrecreateRequest();
            $biz_content = array(
                'out_trade_no' => $out_trade_no,
                'total_amount' => Alipay_SDK_Helper::format_amount($this->convert_to_rmb($order->get_total())),
                'subject' => $this->get_order_title($order),
                'body' => $this->get_order_description($order),
                'timeout_express' => $this->get_option('qrcode_timeout', 120) . 's',
            );
            $request->setBizContent(json_encode($biz_content));
            $request->setNotifyUrl($this->notify_url);
            $response = $aop->execute($request);
            $response_node = 'alipay_trade_precreate_response';
            $result = $response->$response_node;
            if (isset($result->code) && $result->code === '10000') {
                $qr_code = $result->qr_code;
                $order->update_meta_data('_alipay_qrcode', $qr_code);
                $order->save();
                wp_send_json_success(array(
                    'qr_code' => $qr_code,
                    'timeout' => intval($this->get_option('qrcode_timeout', 120))
                ));
            }
            wp_send_json_error(array('message' => 'Precreate failed'));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_query_payment_status()
    {
        check_ajax_referer('alipay_facetopay_query', 'nonce');
        
        $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
        
        if (!$order_id) {
            wp_send_json_error(array('message' => 'Invalid order ID'));
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order || 'alipay_facetopay' !== $order->get_payment_method() || !$this->is_authorized_order_request($order)) {
            wp_send_json_error(array('message' => 'Order not found'), 403);
        }

        if ($order->is_paid()) {
            wp_send_json_success(array(
                'status' => 'paid',
                'redirect_url' => $order->get_checkout_order_received_url(),
            ));
        }
        
        require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        
        $main_gateway = new WC_Alipay(false);
        $config = Alipay_SDK_Helper::get_alipay_config(array(
            'appid' => $main_gateway->get_option('appid'),
            'private_key' => $main_gateway->get_option('private_key'),
            'public_key' => $main_gateway->get_option('public_key'),
            'sandbox' => $main_gateway->get_option('sandbox'),
        ));
        
        $out_trade_no = $order->get_meta('_alipay_out_trade_no');
        $result = Alipay_SDK_Helper::query_order($out_trade_no, '', $config);
        
        if (!is_wp_error($result) && $result['success']) {
            if (in_array($result['trade_status'], array('TRADE_SUCCESS', 'TRADE_FINISHED'), true)) {
                $query_matches = !empty($result['trade_no'])
                    && hash_equals((string) $out_trade_no, (string) $result['out_trade_no'])
                    && Alipay_SDK_Helper::amounts_equal(
                        Alipay_SDK_Helper::format_amount($this->convert_to_rmb($order->get_total())),
                        $result['total_amount']
                    );
                if (!$query_matches) {
                    self::log('当面付查询响应与订单上下文不一致', 'error');
                    wp_send_json_error(array('message' => 'Payment query context mismatch'), 409);
                }
                if (!$order->is_paid()) {
                    $order->payment_complete($result['trade_no']);
                    $order->add_order_note(
                        /* translators: %s: Alipay transaction number */
                        sprintf(__('当面付支付完成 - 交易号: %s', 'wei-alipay'), $result['trade_no'])
                    );
                }
                
                wp_send_json_success(array(
                    'status' => 'paid',
                    'redirect_url' => $order->get_checkout_order_received_url(),
                ));
            }
        }
        
        wp_send_json_success(array('status' => 'pending'));
    }

    public function check_alipay_response()
    {
        $params = wp_unslash($_POST); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Alipay webhooks are authenticated by RSA2 signatures.
        if ('POST' !== strtoupper(isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD'])) : '')) {
            echo 'fail';
            exit;
        }
        require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        
        $main_gateway = new WC_Alipay(false);
        $alipay_public_key = $main_gateway->get_option('public_key');
        
        if (!Alipay_SDK_Helper::verify_notify($params, $alipay_public_key)) {
            self::log('当面付通知签名验证失败', 'error');
            echo 'fail';
            exit;
        }
        
        $out_trade_no = $params['out_trade_no'] ?? '';
        $trade_no = $params['trade_no'] ?? '';
        $trade_status = $params['trade_status'] ?? '';
        
        self::log('当面付支付通知: ' . wp_json_encode(Alipay_SDK_Helper::redact_notify_for_log($params)));
        
        $order = Alipay_SDK_Helper::find_order_by_out_trade_no($out_trade_no, array(self::GATEWAY_ID));
        if (is_wp_error($order)) {
            self::log('订单匹配失败: ' . $order->get_error_code(), 'error');
            echo 'fail';
            exit;
        }
        $core = get_option('woocommerce_alipay_settings', array());
        $context_check = Alipay_SDK_Helper::validate_notify_context(
            $params,
            $order,
            isset($core['appid']) ? $core['appid'] : '',
            Alipay_SDK_Helper::format_amount($this->convert_to_rmb($order->get_total())),
            (string) $order->get_meta('_alipay_out_trade_no'),
            array('alipay_facetopay'),
            isset($core['seller_id']) ? $core['seller_id'] : ''
        );
        if (is_wp_error($context_check)) {
            self::log('当面付通知上下文校验失败: ' . $context_check->get_error_code(), 'error');
            echo 'fail';
            exit;
        }

        $transition = Alipay_SDK_Helper::validate_notify_transition($order, $trade_status);
        if (is_wp_error($transition)) {
            self::log('当面付通知状态拒绝: ' . $transition->get_error_code(), 'error');
            echo 'fail';
            exit;
        }
        $lock = Alipay_SDK_Helper::acquire_lock('notify', $out_trade_no, 30);
        if (false === $lock) {
            echo 'fail';
            exit;
        }
        $order = wc_get_order($order->get_id());
        $context_check = Alipay_SDK_Helper::validate_notify_context(
            $params,
            $order,
            isset($core['appid']) ? $core['appid'] : '',
            Alipay_SDK_Helper::format_amount($this->convert_to_rmb($order->get_total())),
            (string) $order->get_meta('_alipay_out_trade_no'),
            array('alipay_facetopay'),
            isset($core['seller_id']) ? $core['seller_id'] : ''
        );
        $transition = is_wp_error($context_check) ? $context_check : Alipay_SDK_Helper::validate_notify_transition($order, $trade_status);
        if (is_wp_error($transition)) {
            Alipay_SDK_Helper::release_lock('notify', $out_trade_no, $lock);
            echo 'fail';
            exit;
        }

        if ('process_payment' === $transition) {
            if (!$order->is_paid()) {
                $order->payment_complete($trade_no);
                $order->add_order_note(
                    /* translators: %s: Alipay transaction number */
                    sprintf(__('当面付支付完成 - 交易号: %s', 'wei-alipay'), $trade_no)
                );
            }
        } elseif ('process_close' === $transition) {
            $order->update_status('cancelled', __('支付宝已关闭当面付交易。', 'wei-alipay'));
        }
        Alipay_SDK_Helper::release_lock('notify', $out_trade_no, $lock);
        echo 'success';
        exit;
    }

    public function remember_refund_request_id( $refund, $args )
    {
        if ( ! $refund instanceof WC_Order_Refund ) {
            return;
        }
        $order = wc_get_order( $refund->get_parent_id() );
        if ( ! $order || self::GATEWAY_ID !== $order->get_payment_method() ) {
            return;
        }
        $blog = is_multisite() ? get_current_blog_id() . '-' : '';
        self::$refund_request_id = 'F2FR-' . $blog . $refund->get_id();
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order || self::GATEWAY_ID !== $order->get_payment_method()) {
            return new WP_Error('invalid_order', __('退款失败：订单或支付方式不匹配。', 'wei-alipay'));
        }

        require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        $refund_amount = null === $amount ? $order->get_total() : $amount;
        $amount_minor = Alipay_SDK_Helper::amount_to_minor_units($refund_amount);
        $total_minor = Alipay_SDK_Helper::amount_to_minor_units($order->get_total());
        if (false === $amount_minor || false === $total_minor || $amount_minor <= 0 || $amount_minor > $total_minor) {
            return new WP_Error('invalid_refund_amount', __('退款金额必须大于零且不能超过订单总额。', 'wei-alipay'));
        }
        $refund_amount = Alipay_SDK_Helper::format_amount($refund_amount);
        $refund_reason = $reason ? sanitize_text_field($reason) : __('订单退款', 'wei-alipay');
        $out_trade_no = (string) $order->get_meta('_alipay_out_trade_no');
        $trade_no = (string) $order->get_transaction_id();
        if ('' === $out_trade_no || '' === $trade_no) {
            return new WP_Error('missing_transaction_context', __('退款失败：缺少原始支付宝订单号或交易号。', 'wei-alipay'));
        }

        if ('' === self::$refund_request_id) {
            $blog = is_multisite() ? get_current_blog_id() . '-' : '';
            self::$refund_request_id = 'F2FR-' . $blog . $order_id . '-' . current_time('timestamp');
        }
        $request_id = self::$refund_request_id;
        $processed = array_values(array_filter((array) $order->get_meta('_alipay_processed_refund_requests', true), 'is_string'));
        if (in_array($request_id, $processed, true)) {
            self::$refund_request_id = '';
            return true;
        }
        $lock = Alipay_SDK_Helper::acquire_lock('refund', $request_id, 60);
        if (false === $lock) {
            self::$refund_request_id = '';
            return new WP_Error('refund_in_progress', __('相同退款请求正在处理中。', 'wei-alipay'));
        }

        try {
            require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
            require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradeRefundRequest.php';
            $main_gateway = new WC_Alipay(false);
            $config = Alipay_SDK_Helper::get_alipay_config(array(
                'appid' => $main_gateway->get_option('appid'),
                'private_key' => $main_gateway->get_option('private_key'),
                'public_key' => $main_gateway->get_option('public_key'),
                'sandbox' => $main_gateway->get_option('sandbox'),
            ));
            $aop = Alipay_SDK_Helper::create_alipay_service($config);
            if (!$aop) {
                return new WP_Error('sdk_error', __('创建支付宝服务失败', 'wei-alipay'));
            }
            $request = new AlipayTradeRefundRequest();
            $request->setBizContent(wp_json_encode(array(
                'out_trade_no' => $out_trade_no,
                'trade_no' => $trade_no,
                'refund_amount' => $refund_amount,
                'refund_reason' => $refund_reason,
                'out_request_no' => $request_id,
            )));
            $response = $aop->execute($request);
            $node = 'alipay_trade_refund_response';
            $result = isset($response->$node) ? $response->$node : null;
            if (!is_object($result) || !isset($result->code) || '10000' !== (string) $result->code) {
                $message = is_object($result) ? ($result->sub_msg ?? $result->msg ?? __('退款失败', 'wei-alipay')) : __('退款失败：支付宝返回为空。', 'wei-alipay');
                return new WP_Error('refund_failed', (string) $message);
            }
            if (!isset($result->out_trade_no, $result->trade_no, $result->refund_fee)
                || !hash_equals($out_trade_no, (string) $result->out_trade_no)
                || !hash_equals($trade_no, (string) $result->trade_no)
                || !Alipay_SDK_Helper::amounts_equal($refund_amount, $result->refund_fee)) {
                return new WP_Error('refund_context_mismatch', __('支付宝退款响应与原退款请求不匹配。', 'wei-alipay'));
            }
            $processed[] = $request_id;
            $order->update_meta_data('_alipay_processed_refund_requests', array_values(array_unique($processed)));
            $order->save();
            /* translators: %s: refunded amount */
            $order->add_order_note(sprintf(__('支付宝当面付退款成功，金额：¥%s', 'wei-alipay'), $refund_amount));
            return true;
        } catch (Exception $e) {
            return new WP_Error('refund_exception', $e->getMessage());
        } finally {
            Alipay_SDK_Helper::release_lock('refund', $request_id, $lock);
            self::$refund_request_id = '';
        }
    }

    /**
     * 检查支付方式是否可用
     */
    public function is_available()
    {
        $is_available = ('yes' === $this->enabled) ? true : false;

        if (!$is_available) {
            return false;
        }

        // 检查主支付宝网关是否配置
        $main_gateway = new WC_Alipay(false);
        if (!$main_gateway->get_option('appid') || !$main_gateway->get_option('private_key')) {
            return false;
        }

        return $is_available;
    }

    protected function convert_to_rmb($amount)
    {
        require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        return Alipay_SDK_Helper::convert_currency(
            $amount,
            $this->current_currency,
            $this->exchange_rate
        );
    }

    protected function get_order_title($order)
    {
        /* translators: %s: order ID */
        $title = get_bloginfo('name') . ' - ' . sprintf(__('订单 #%s', 'wei-alipay'), $order->get_id());
        return mb_substr($title, 0, 256);
    }

    protected function get_order_description($order)
    {
        $items = array();
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name();
        }
        return mb_substr(implode(', ', $items), 0, 400);
    }

    protected static function log($message, $level = 'info')
    {
        if (self::$log_enabled) {
            if (empty(self::$log)) {
                self::$log = wc_get_logger();
            }
            self::$log->log($level, $message, array('source' => self::GATEWAY_ID));
        }
    }
}
