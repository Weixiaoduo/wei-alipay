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
    public static function validate_notify_context($params, $order, $expected_app_id, $expected_amount, $expected_out_trade_no = '', $allowed_methods = array())
    {
        if (!($order instanceof WC_Order)) {
            return new WP_Error('invalid_order', __('Invalid order.', 'woo-alipay'));
        }

        $params = wp_unslash($params);
        $app_id = isset($params['app_id']) ? (string) $params['app_id'] : '';
        if ('' === $app_id || '' === (string) $expected_app_id || !hash_equals((string) $expected_app_id, $app_id)) {
            return new WP_Error('app_id_mismatch', __('Alipay application ID does not match.', 'woo-alipay'));
        }

        $out_trade_no = isset($params['out_trade_no']) ? (string) $params['out_trade_no'] : '';
        if ('' === $out_trade_no || ('' !== (string) $expected_out_trade_no && !hash_equals((string) $expected_out_trade_no, $out_trade_no))) {
            return new WP_Error('out_trade_no_mismatch', __('Merchant order number does not match.', 'woo-alipay'));
        }

        if (!isset($params['total_amount']) || !is_numeric($params['total_amount'])) {
            return new WP_Error('invalid_amount', __('Alipay notification amount is invalid.', 'woo-alipay'));
        }

        if (!hash_equals(self::format_amount($expected_amount), self::format_amount($params['total_amount']))) {
            return new WP_Error('amount_mismatch', __('Alipay notification amount does not match the order.', 'woo-alipay'));
        }

        if (!empty($allowed_methods) && !in_array($order->get_payment_method(), $allowed_methods, true)) {
            return new WP_Error('payment_method_mismatch', __('Order payment method does not match.', 'woo-alipay'));
        }

        $stored_transaction_id = (string) $order->get_transaction_id();
        $notified_trade_no = isset($params['trade_no']) ? (string) $params['trade_no'] : '';
        if ('' !== $stored_transaction_id && '' !== $notified_trade_no && !hash_equals($stored_transaction_id, $notified_trade_no)) {
            return new WP_Error('trade_no_mismatch', __('Alipay transaction ID does not match.', 'woo-alipay'));
        }

        return true;
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
        return number_format(floatval($amount), 2, '.', '');
    }
}
