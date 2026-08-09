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
    
    protected $current_currency;
    protected $exchange_rate;
    protected $order_prefix;
    protected $notify_url;
    protected $charset;

    public function __construct()
    {
        $this->id = self::GATEWAY_ID;
        $this->method_title = __('支付宝当面付', 'woo-alipay');
        $this->method_description = __('支持当面付扫码支付，商家展示二维码供用户扫描。适用于收银台、实体店等场景。需要在支付宝商户后台开通当面付功能。', 'woo-alipay');
        $this->icon = WOO_ALIPAY_PLUGIN_URL . 'assets/images/alipay-f2f-icon.svg';
        $this->has_fields = false;
        $this->charset = strtolower(get_bloginfo('charset'));
        $this->current_currency = get_option('woocommerce_currency');

        if (!in_array($this->charset, array('gbk', 'utf-8'), true)) {
            $this->charset = 'utf-8';
        }
        
        $this->init_form_fields();
        $this->init_settings();

        
        $this->title = $this->get_option('title', __('支付宝扫码支付', 'woo-alipay'));
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
        echo '<h3>' . esc_html__('支付宝当面付', 'woo-alipay') . '</h3>';
        echo '<p>' . esc_html__('用于线下扫码场景的当面付支付方式。', 'woo-alipay') . '</p>';


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
                'title' => __('启用/禁用', 'woo-alipay'),
                'type' => 'checkbox',
                'label' => __('启用支付宝当面付', 'woo-alipay'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('标题', 'woo-alipay'),
                'type' => 'text',
                'description' => __('用户在结账时看到的支付方式名称', 'woo-alipay'),
                'default' => __('支付宝扫码支付', 'woo-alipay'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('描述', 'woo-alipay'),
                'type' => 'textarea',
                'description' => __('支付方式描述', 'woo-alipay'),
                'default' => __('使用支付宝扫描二维码完成支付', 'woo-alipay'),
                'desc_tip' => true,
            ),
            
            'qrcode_settings' => array(
                'title' => __('二维码设置', 'woo-alipay'),
                'type' => 'title',
            ),
            'qrcode_size' => array(
                'title' => __('二维码尺寸', 'woo-alipay'),
                'type' => 'number',
                'description' => __('二维码显示尺寸（像素）', 'woo-alipay'),
                'default' => '300',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => '100',
                    'max' => '500',
                    'step' => '10',
                ),
            ),
            'qrcode_timeout' => array(
                'title' => __('二维码有效期', 'woo-alipay'),
                'type' => 'select',
                'description' => __('二维码的有效时间', 'woo-alipay'),
                'default' => '120',
                'options' => array(
                    '60' => __('1分钟', 'woo-alipay'),
                    '120' => __('2分钟', 'woo-alipay'),
                    '180' => __('3分钟', 'woo-alipay'),
                    '300' => __('5分钟', 'woo-alipay'),
                    '600' => __('10分钟', 'woo-alipay'),
                ),
                'desc_tip' => true,
            ),
            
            'polling_settings' => array(
                'title' => __('轮询设置', 'woo-alipay'),
                'type' => 'title',
            ),
            'polling_interval' => array(
                'title' => __('轮询间隔', 'woo-alipay'),
                'type' => 'number',
                'description' => __('查询支付状态的间隔时间（秒）', 'woo-alipay'),
                'default' => '2',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => '1',
                    'max' => '10',
                    'step' => '1',
                ),
            ),
            
            'order_prefix' => array(
                'title' => __('订单号前缀', 'woo-alipay'),
                'type' => 'text',
                'description' => __('当面付订单号的前缀', 'woo-alipay'),
                'default' => 'F2F',
                'desc_tip' => true,
            ),
            
            'debug' => array(
                'title' => __('调试日志', 'woo-alipay'),
                'type' => 'checkbox',
                'label' => __('启用日志记录', 'woo-alipay'),
                'default' => 'no',
                'description' => sprintf(
                    __('记录当面付相关日志到 %s', 'woo-alipay'),
                    '<code>' . WC_Log_Handler_File::get_log_file_path($this->id) . '</code>'
                ),
            ),
        );
        
        if (!in_array($this->current_currency, array('CNY', 'RMB'), true)) {
            $this->form_fields['exchange_rate'] = array(
                'title' => __('汇率', 'woo-alipay'),
                'type' => 'number',
                'description' => sprintf(
                    __('设置 %s 与人民币的汇率', 'woo-alipay'),
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
        
        $style_url  = apply_filters( 'woo_alipay_facetopay_style_url', WOO_ALIPAY_F2F_PLUGIN_URL . 'css/alipay-facetopay.css' );
        $script_url = apply_filters( 'woo_alipay_facetopay_script_url', WOO_ALIPAY_F2F_PLUGIN_URL . 'js/alipay-facetopay.js' );

        wp_enqueue_style(
            'alipay-facetopay',
            $style_url,
            array(),
            WOO_ALIPAY_VERSION
        );
        
        wp_enqueue_script(
            'qrcodejs',
            'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js',
            array(),
            '1.0.0',
            true
        );
        
        wp_enqueue_script(
            'alipay-facetopay',
            $script_url,
            array('jquery', 'qrcodejs'),
            WOO_ALIPAY_VERSION,
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
        
        $order->update_status('pending', __('等待扫码支付', 'woo-alipay'));
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
        
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        
        $main_gateway = new WC_Alipay(false);
        $config = Alipay_SDK_Helper::get_alipay_config(array(
            'appid' => $main_gateway->get_option('appid'),
            'private_key' => $main_gateway->get_option('private_key'),
            'public_key' => $main_gateway->get_option('public_key'),
            'sandbox' => $main_gateway->get_option('sandbox'),
        ));
        
        $aop = Alipay_SDK_Helper::create_alipay_service($config);
        if (!$aop) {
            wc_add_notice(__('支付初始化失败', 'woo-alipay'), 'error');
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
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradePrecreateRequest.php';
            
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
                wc_add_notice(__('生成支付二维码失败', 'woo-alipay'), 'error');
            }
            
        } catch (Exception $e) {
            self::log('当面付异常: ' . $e->getMessage(), 'error');
            wc_add_notice(__('支付请求失败', 'woo-alipay'), 'error');
        }
    }

    protected function display_qrcode_page($order, $qr_code)
    {
        $qrcode_size = intval($this->get_option('qrcode_size', 300));
        $timeout = intval($this->get_option('qrcode_timeout', 120));
        
        $template_path = apply_filters( 'woo_alipay_facetopay_qrcode_template', WOO_ALIPAY_PLUGIN_PATH . 'inc/templates/payment-qrcode.php', $order, $qr_code, $qrcode_size, $timeout );
        include $template_path;
    }

    protected function is_authorized_order_request($order)
    {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }
        $order_key = isset($_POST['order_key']) && is_scalar($_POST['order_key']) ? sanitize_text_field(wp_unslash((string) $_POST['order_key'])) : '';
        return $order_key && hash_equals($order->get_order_key(), $order_key);
    }

    public function ajax_refresh_qrcode()
    {
        check_ajax_referer('alipay_facetopay_query', 'nonce');
        $order_id = intval($_POST['order_id'] ?? 0);
        if (!$order_id) {
            wp_send_json_error(array('message' => 'Invalid order ID'));
        }
        $order = wc_get_order($order_id);
        if (!$order || 'alipay_facetopay' !== $order->get_payment_method() || !$this->is_authorized_order_request($order) || $order->is_paid()) {
            wp_send_json_error(array('message' => 'Invalid order'), 403);
        }
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        $main_gateway = new WC_Alipay(false);
        $config = Alipay_SDK_Helper::get_alipay_config(array(
            'appid' => $main_gateway->get_option('appid'),
            'private_key' => $main_gateway->get_option('private_key'),
            'public_key' => $main_gateway->get_option('public_key'),
            'sandbox' => $main_gateway->get_option('sandbox'),
        ));
        try {
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradePrecreateRequest.php';
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
        
        $order_id = intval($_POST['order_id'] ?? 0);
        
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
        
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        
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
                        sprintf(__('当面付支付完成 - 交易号: %s', 'woo-alipay'), $result['trade_no'])
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
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        
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
                    sprintf(__('当面付支付完成 - 交易号: %s', 'woo-alipay'), $trade_no)
                );
            }
        } elseif ('process_close' === $transition) {
            $order->update_status('cancelled', __('支付宝已关闭当面付交易。', 'woo-alipay'));
        }
        Alipay_SDK_Helper::release_lock('notify', $out_trade_no, $lock);
        echo 'success';
        exit;
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('error', __('订单不存在', 'woo-alipay'));
        }
        
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradeRefundRequest.php';
        
        $main_gateway = new WC_Alipay(false);
        $config = Alipay_SDK_Helper::get_alipay_config(array(
            'appid' => $main_gateway->get_option('appid'),
            'private_key' => $main_gateway->get_option('private_key'),
            'public_key' => $main_gateway->get_option('public_key'),
            'sandbox' => $main_gateway->get_option('sandbox'),
        ));
        
        $aop = Alipay_SDK_Helper::create_alipay_service($config);
        if (!$aop) {
            return new WP_Error('sdk_error', __('创建支付宝服务失败', 'woo-alipay'));
        }
        
        $out_trade_no = $order->get_meta('_alipay_out_trade_no');
        if (!$out_trade_no) {
            return new WP_Error('missing_out_trade_no', __('退款失败：缺少原始支付宝商户订单号。', 'woo-alipay'));
        }
        $refund_amount = $amount ? floatval($amount) : floatval($order->get_total());
        $refund_reason = $reason ? $reason : __('订单退款', 'woo-alipay');
        
        try {
            $request = new AlipayTradeRefundRequest();
            $biz_content = array(
                'out_trade_no' => $out_trade_no,
                'refund_amount' => Alipay_SDK_Helper::format_amount($refund_amount),
                'refund_reason' => $refund_reason,
            );
            $request->setBizContent(json_encode($biz_content));
            $response = $aop->execute($request);
            $node = 'alipay_trade_refund_response';
            $result = $response->$node;
            if (isset($result->code) && $result->code === '10000') {
                $order->add_order_note(sprintf(__('支付宝退款成功，金额：¥%s', 'woo-alipay'), number_format($refund_amount, 2)));
                return true;
            }
            return new WP_Error('refund_failed', $result->sub_msg ?? $result->msg ?? __('退款失败', 'woo-alipay'));
        } catch (Exception $e) {
            return new WP_Error('exception', $e->getMessage());
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
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        return Alipay_SDK_Helper::convert_currency(
            $amount,
            $this->current_currency,
            $this->exchange_rate
        );
    }

    protected function get_order_title($order)
    {
        $title = get_bloginfo('name') . ' - ' . sprintf(__('订单 #%s', 'woo-alipay'), $order->get_id());
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
