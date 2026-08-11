<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Alipay SDK 辅助类
 * 封装常用的 SDK 调用方法，供各个支付网关使用
 */
class Alipay_SDK_Helper
{
    /**
     * 获取支付宝配置
     * 
     * @param array $settings 网关设置
     * @return array
     */
    public static function get_alipay_config($settings)
    {
        $is_sandbox = isset($settings['sandbox']) && 'yes' === $settings['sandbox'];
        
        return array(
            'app_id' => $settings['appid'] ?? '',
            'merchant_private_key' => $settings['private_key'] ?? '',
            'alipay_public_key' => $settings['public_key'] ?? '',
            'charset' => 'UTF-8',
            'sign_type' => 'RSA2',
            'gatewayUrl' => $is_sandbox ? 
                'https://openapi.alipaydev.com/gateway.do' : 
                'https://openapi.alipay.com/gateway.do',
        );
    }

    /**
     * 创建支付宝服务对象
     * 
     * @param array $config 支付宝配置
     * @return AlipayTradeService|null
     */
    public static function create_alipay_service($config)
    {
        try {
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
            
            $aop = new AopClient();
            $aop->gatewayUrl = $config['gatewayUrl'];
            $aop->appId = $config['app_id'];
            $aop->rsaPrivateKey = $config['merchant_private_key'];
            $aop->alipayrsaPublicKey = $config['alipay_public_key'];
            $aop->charset = $config['charset'];
            $aop->signType = $config['sign_type'];
            
            return $aop;
        } catch (Exception $e) {
            self::log('创建支付宝服务失败: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * 验证支付宝通知签名
     * 
     * @param array $params 通知参数
     * @param string $alipay_public_key 支付宝公钥
     * @return bool
     */
    public static function verify_notify($params, $alipay_public_key)
    {
        if (!is_array($params) || !isset($params['sign_type']) || !is_scalar($params['sign_type']) || 'RSA2' !== strtoupper(trim((string) $params['sign_type']))) {
            return false;
        }

        try {
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
            
            $aop = new AopClient();
            $aop->alipayrsaPublicKey = $alipay_public_key;
            
            return $aop->rsaCheckV1($params, null, 'RSA2');
        } catch (Exception $e) {
            self::log('验证签名失败: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * 查询订单状态
     * 
     * @param string $out_trade_no 商户订单号
     * @param string $trade_no 支付宝交易号
     * @param array $config 支付宝配置
     * @return array|WP_Error
     */
    public static function query_order($out_trade_no, $trade_no, $config)
    {
        try {
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradeQueryRequest.php';
            
            $aop = self::create_alipay_service($config);
            if (!$aop) {
                return new WP_Error('sdk_error', '创建支付宝服务失败');
            }
            
            $request = new AlipayTradeQueryRequest();
            $biz_content = array();
            
            if ($out_trade_no) {
                $biz_content['out_trade_no'] = $out_trade_no;
            }
            if ($trade_no) {
                $biz_content['trade_no'] = $trade_no;
            }
            
            $request->setBizContent(json_encode($biz_content));
            $response = $aop->execute($request);
            
            $response_node = 'alipay_trade_query_response';
            $result = $response->$response_node;
            
            if (isset($result->code) && $result->code === '10000') {
                return array(
                    'success' => true,
                    'trade_no' => $result->trade_no ?? '',
                    'out_trade_no' => $result->out_trade_no ?? '',
                    'trade_status' => $result->trade_status ?? '',
                    'total_amount' => $result->total_amount ?? 0,
                );
            } else {
                return new WP_Error(
                    'query_failed',
                    $result->sub_msg ?? $result->msg ?? '查询订单失败'
                );
            }
        } catch (Exception $e) {
            self::log('查询订单异常: ' . $e->getMessage(), 'error');
            return new WP_Error('exception', $e->getMessage());
        }
    }

    /**
     * 关闭订单
     * 
     * @param string $out_trade_no 商户订单号
     * @param array $config 支付宝配置
     * @return array|WP_Error
     */
    public static function close_order($out_trade_no, $config)
    {
        try {
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradeCloseRequest.php';
            
            $aop = self::create_alipay_service($config);
            if (!$aop) {
                return new WP_Error('sdk_error', '创建支付宝服务失败');
            }
            
            $request = new AlipayTradeCloseRequest();
            $biz_content = array('out_trade_no' => $out_trade_no);
            $request->setBizContent(json_encode($biz_content));
            
            $response = $aop->execute($request);
            $response_node = 'alipay_trade_close_response';
            $result = $response->$response_node;
            
            if (isset($result->code) && $result->code === '10000') {
                return array('success' => true);
            } else {
                return new WP_Error(
                    'close_failed',
                    $result->sub_msg ?? $result->msg ?? '关闭订单失败'
                );
            }
        } catch (Exception $e) {
            self::log('关闭订单异常: ' . $e->getMessage(), 'error');
            return new WP_Error('exception', $e->getMessage());
        }
    }

    /**
     * 货币转换
     * 
     * @param float $amount 金额
     * @param string $from_currency 原货币
     * @param float $exchange_rate 汇率
     * @return float
     */
    public static function convert_currency($amount, $from_currency, $exchange_rate)
    {
        $supported_currencies = array('RMB', 'CNY');
        
        if (in_array(strtoupper($from_currency), $supported_currencies, true)) {
            return round(floatval($amount), 2);
        }
        
        return round(floatval($amount) * floatval($exchange_rate), 2);
    }

    /**
     * 生成商户订单号
     * 
     * @param int $order_id WooCommerce 订单 ID
     * @param string $prefix 前缀
     * @return string
     */
    public static function generate_out_trade_no($order_id, $prefix = '')
    {
        $blog_prefix = is_multisite() ? get_current_blog_id() . '-' : '';
        $timestamp = current_time('timestamp');
        
        if (empty($prefix)) {
            $prefix = 'WooA';
        }
        
        return $prefix . $blog_prefix . $order_id . '-' . $timestamp;
    }

    /**
     * Find exactly one order by the stored merchant order number across both
     * WooCommerce legacy posts storage and HPOS custom order tables.
     *
     * The final in-memory comparison is intentional: older data stores can
     * silently ignore an unsupported meta_query argument.
     *
     * @param string $out_trade_no Merchant order number.
     * @param array  $allowed_methods Optional payment method allow-list.
     * @return WC_Order|WP_Error
     */
    public static function find_order_by_out_trade_no($out_trade_no, $allowed_methods = array())
    {
        $out_trade_no = is_scalar($out_trade_no) ? trim((string) $out_trade_no) : '';
        if ('' === $out_trade_no || strlen($out_trade_no) > 128) {
            return new WP_Error('invalid_out_trade_no', __('Merchant order number is invalid.', 'woo-alipay'));
        }

        $args = array(
            'limit'  => 3,
            'return' => 'objects',
        );
        $order_util = '\Automattic\WooCommerce\Utilities\OrderUtil';
        $hpos_enabled = class_exists($order_util)
            && is_callable(array($order_util, 'custom_orders_table_usage_is_enabled'))
            && $order_util::custom_orders_table_usage_is_enabled();
        if ($hpos_enabled) {
            $args['meta_query'] = array(
                array(
                    'key'     => '_alipay_out_trade_no',
                    'value'   => $out_trade_no,
                    'compare' => '=',
                ),
            );
        } else {
            $args['meta_key']     = '_alipay_out_trade_no';
            $args['meta_value']   = $out_trade_no;
            $args['meta_compare'] = '=';
        }

        $matches = array();
        foreach ((array) wc_get_orders($args) as $candidate) {
            if (!($candidate instanceof WC_Order)) {
                continue;
            }
            $stored = (string) $candidate->get_meta('_alipay_out_trade_no');
            if (!hash_equals($out_trade_no, $stored)) {
                continue;
            }
            if (!empty($allowed_methods) && !in_array($candidate->get_payment_method(), $allowed_methods, true)) {
                continue;
            }
            $matches[] = $candidate;
        }

        if (1 === count($matches)) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            return new WP_Error('ambiguous_out_trade_no', __('Merchant order number matches multiple orders.', 'woo-alipay'));
        }
        return new WP_Error('order_not_found', __('Order not found.', 'woo-alipay'));
    }

    /**
     * Sanitize an SDK-generated payment form without allowing SDK scripts.
     *
     * Callers may add their own fixed auto-submit behavior after rendering.
     *
     * @param string $html SDK response HTML.
     * @return string
     */
    public static function sanitize_payment_form($html)
    {
        $allowed = array(
            'form' => array('action' => true, 'method' => true, 'id' => true, 'name' => true, 'class' => true, 'target' => true),
            'input' => array('type' => true, 'name' => true, 'value' => true, 'id' => true, 'class' => true),
            'button' => array('type' => true, 'name' => true, 'value' => true, 'id' => true, 'class' => true),
            'div' => array('id' => true, 'class' => true),
            'span' => array('id' => true, 'class' => true),
            'p' => array('id' => true, 'class' => true),
        );
        return wp_kses((string) $html, $allowed, array('http', 'https'));
    }

    /**
     * Return a bounded notification payload suitable for WooCommerce logs.
     * Signature material and customer data are deliberately excluded.
     *
     * @param array $params Notification parameters.
     * @return array
     */
    public static function redact_notify_for_log($params)
    {
        $safe = array();
        $allowed = array('notify_id', 'notify_type', 'out_trade_no', 'trade_no', 'trade_status', 'total_amount', 'app_id', 'sign_type');

        foreach ($allowed as $field) {
            if (isset($params[$field]) && is_scalar($params[$field])) {
                $safe[$field] = sanitize_text_field(wp_unslash((string) $params[$field]));
            }
        }

        return $safe;
    }

    /**
     * Validate signed notification fields against the local order context.
     * This must be called only after verify_notify() succeeds.
     *
     * @param array    $params                Notification parameters.
     * @param WC_Order $order                 WooCommerce order.
     * @param string   $expected_app_id       Configured application ID.
     * @param float    $expected_amount       Expected amount in CNY.
     * @param string   $expected_out_trade_no Stored merchant order number.
     * @param array    $allowed_methods       Allowed payment method IDs.
     * @return true|WP_Error
     */
    public static function validate_notify_context($params, $order, $expected_app_id, $expected_amount, $expected_out_trade_no = '', $allowed_methods = array(), $expected_seller_id = '', $max_age_seconds = 259200, $now = null)
    {
        if (!($order instanceof WC_Order)) {
            return new WP_Error('invalid_order', __('Invalid order.', 'woo-alipay'));
        }

        $params = wp_unslash($params);
        foreach (array('app_id', 'seller_id', 'out_trade_no', 'trade_no', 'trade_status', 'total_amount', 'notify_id', 'notify_time', 'sign_type', 'charset') as $field) {
            if (isset($params[$field]) && (!is_scalar($params[$field]) || 1 !== preg_match('//u', (string) $params[$field]))) {
                return new WP_Error('invalid_encoding', __('Alipay notification contains invalid text encoding.', 'woo-alipay'));
            }
        }

        $sign_type = isset($params['sign_type']) ? strtoupper(trim((string) $params['sign_type'])) : '';
        if ('RSA2' !== $sign_type) {
            return new WP_Error('unsupported_sign_type', __('Alipay notification must use RSA2.', 'woo-alipay'));
        }

        $notify_time = isset($params['notify_time']) ? trim((string) $params['notify_time']) : '';
        $notify_timestamp = self::parse_notify_time($notify_time);
        $now = null === $now ? time() : (int) $now;
        if (false === $notify_timestamp) {
            return new WP_Error('invalid_notify_time', __('Alipay notification time is invalid.', 'woo-alipay'));
        }
        if ($notify_timestamp > $now + 300) {
            return new WP_Error('future_notify', __('Alipay notification time is in the future.', 'woo-alipay'));
        }
        if ($max_age_seconds > 0 && $notify_timestamp < $now - (int) $max_age_seconds) {
            return new WP_Error('expired_notify', __('Alipay notification is too old.', 'woo-alipay'));
        }

        $app_id = isset($params['app_id']) ? (string) $params['app_id'] : '';
        if ('' === $app_id || '' === (string) $expected_app_id || !hash_equals((string) $expected_app_id, $app_id)) {
            return new WP_Error('app_id_mismatch', __('Alipay application ID does not match.', 'woo-alipay'));
        }

        $seller_id = isset($params['seller_id']) ? trim((string) $params['seller_id']) : '';
        if ('' === $seller_id || ('' !== (string) $expected_seller_id && !hash_equals((string) $expected_seller_id, $seller_id))) {
            return new WP_Error('seller_id_mismatch', __('Alipay seller ID does not match.', 'woo-alipay'));
        }

        $out_trade_no = isset($params['out_trade_no']) ? (string) $params['out_trade_no'] : '';
        if ('' === $out_trade_no || '' === (string) $expected_out_trade_no || !hash_equals((string) $expected_out_trade_no, $out_trade_no)) {
            return new WP_Error('out_trade_no_mismatch', __('Merchant order number does not match.', 'woo-alipay'));
        }

        if (!isset($params['total_amount']) || !is_numeric($params['total_amount'])) {
            return new WP_Error('invalid_amount', __('Alipay notification amount is invalid.', 'woo-alipay'));
        }

        if (!self::amounts_equal($expected_amount, $params['total_amount'])) {
            return new WP_Error('amount_mismatch', __('Alipay notification amount does not match the order.', 'woo-alipay'));
        }

        if (!empty($allowed_methods) && !in_array($order->get_payment_method(), $allowed_methods, true)) {
            return new WP_Error('payment_method_mismatch', __('Order payment method does not match.', 'woo-alipay'));
        }

        $stored_transaction_id = (string) $order->get_transaction_id();
        $notified_trade_no = isset($params['trade_no']) ? (string) $params['trade_no'] : '';
        $trade_status = isset($params['trade_status']) ? (string) $params['trade_status'] : '';
        if (in_array($trade_status, array('TRADE_SUCCESS', 'TRADE_FINISHED', 'WAIT_SELLER_SEND_GOODS'), true) && '' === $notified_trade_no) {
            return new WP_Error('missing_trade_no', __('Alipay transaction ID is missing.', 'woo-alipay'));
        }
        if ('' !== $stored_transaction_id && !hash_equals($stored_transaction_id, $notified_trade_no)) {
            return new WP_Error('trade_no_mismatch', __('Alipay transaction ID does not match.', 'woo-alipay'));
        }

        return true;
    }

    /** Validate a signed notification against the current WooCommerce state. */
    public static function validate_notify_transition($order, $trade_status)
    {
        if (!($order instanceof WC_Order)) {
            return new WP_Error('invalid_order', __('Invalid order.', 'woo-alipay'));
        }
        $order_status = (string) $order->get_status();
        if ($order->is_paid()) {
            return 'acknowledge';
        }
        if (in_array($order_status, array('cancelled', 'refunded', 'trash'), true)) {
            if ('TRADE_CLOSED' === $trade_status || 'WAIT_BUYER_PAY' === $trade_status) {
                return 'acknowledge';
            }
            return new WP_Error('terminal_order_state', __('The WooCommerce order is already terminal.', 'woo-alipay'));
        }
        if (in_array($trade_status, array('TRADE_SUCCESS', 'TRADE_FINISHED', 'WAIT_SELLER_SEND_GOODS'), true)) {
            return 'process_payment';
        }
        if ('TRADE_CLOSED' === $trade_status) {
            return 'process_close';
        }
        if ('WAIT_BUYER_PAY' === $trade_status) {
            return 'acknowledge';
        }
        return new WP_Error('unsupported_trade_status', __('Unsupported Alipay trade status.', 'woo-alipay'));
    }

    /** Acquire a short database-backed lock for notification/refund idempotency. */
    public static function acquire_lock($scope, $identifier, $ttl = 30)
    {
        $option = '_woo_alipay_lock_' . md5((string) $scope . ':' . (string) $identifier);
        $token = wp_generate_uuid4();
        $value = wp_json_encode(array('token' => $token, 'expires' => time() + max(1, (int) $ttl)));
        if (add_option($option, $value, '', false)) {
            return $token;
        }
        $existing = json_decode((string) get_option($option, ''), true);
        if (is_array($existing) && isset($existing['expires']) && (int) $existing['expires'] < time()) {
            delete_option($option);
            if (add_option($option, $value, '', false)) {
                return $token;
            }
        }
        return false;
    }

    /** Release a lock only when its ownership token still matches. */
    public static function release_lock($scope, $identifier, $token)
    {
        $option = '_woo_alipay_lock_' . md5((string) $scope . ':' . (string) $identifier);
        $existing = json_decode((string) get_option($option, ''), true);
        if (is_array($existing) && isset($existing['token']) && hash_equals((string) $existing['token'], (string) $token)) {
            delete_option($option);
        }
    }

    /** Parse Alipay's Asia/Shanghai notification timestamp strictly. */
    private static function parse_notify_time($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
            return false;
        }
        $timezone = new DateTimeZone('Asia/Shanghai');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d H:i:s') !== $value) {
            return false;
        }
        return $date->getTimestamp();
    }

    /** Normalize a decimal amount without float coercion. */
    public static function normalize_amount($amount)
    {
        if (!is_scalar($amount)) {
            return false;
        }
        $amount = trim((string) $amount);
        if (!preg_match('/^(0|[1-9]\d{0,12})(?:\.(\d{1,2}))?$/D', $amount, $matches)) {
            return false;
        }
        $fraction = isset($matches[2]) ? str_pad($matches[2], 2, '0') : '00';
        return $matches[1] . '.' . $fraction;
    }

    /** Convert a strict decimal amount to integer minor units. */
    public static function amount_to_minor_units($amount)
    {
        $normalized = self::normalize_amount($amount);
        if (false === $normalized) {
            return false;
        }
        list($whole, $fraction) = explode('.', $normalized, 2);
        return ((int) $whole * 100) + (int) $fraction;
    }

    /** Compare normalized decimal amounts exactly. */
    public static function amounts_equal($expected, $actual)
    {
        $expected = self::normalize_amount($expected);
        $actual = self::normalize_amount($actual);
        return false !== $expected && false !== $actual && hash_equals($expected, $actual);
    }

    /**
     * 记录日志
     * 
     * @param string $message 日志信息
     * @param string $level 日志级别
     */
    public static function log($message, $level = 'info')
    {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log($level, $message, array('source' => 'alipay'));
        }
    }

    /**
     * 获取设备类型
     * 
     * @return string 'mobile' 或 'pc'
     */
    public static function get_device_type()
    {
        if (wp_is_mobile()) {
            return 'mobile';
        }
        return 'pc';
    }

    /**
     * 格式化金额
     * 
     * @param float $amount 金额
     * @return string
     */
    public static function format_amount($amount)
    {
        $normalized = self::normalize_amount($amount);
        return false === $normalized ? number_format((float) $amount, 2, '.', '') : $normalized;
    }
}
