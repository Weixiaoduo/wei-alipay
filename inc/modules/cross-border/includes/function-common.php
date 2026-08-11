<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once(dirname(__FILE__) . '/../lib/alipay_submit.class.php');
require_once(dirname(__FILE__) . '/../lib/alipay_notify.class.php');

class CommonFunction
{
    public function __construct()
    {
    }

    public function buildAlipayString($order, $partnerId, $securityKey, $accountType, $paymentInst, $notifyURL, $returnURL, $orderPrefix, $isForceSSL, $isSandbox)
    {
        // Get alipay args
        $alipayParams = $this->getAliPayParams($accountType, $paymentInst, $order, $partnerId, $notifyURL, $returnURL, $orderPrefix);
        $alipayConfig = $this->getAliPayConfig($accountType, $paymentInst, $partnerId, $securityKey, $notifyURL, $returnURL, $isForceSSL);

        $alipaySubmit = new AlipaySubmit($alipayConfig, $isSandbox);

        // Build query string
        $queryString   = $alipaySubmit->buildRequestParaToString($alipayParams);
        $alipayString  = $alipaySubmit->alipay_gateway_new . $queryString;

        return $alipayString;
    }

    protected function getAliPayParams($accountType, $paymentInst, $order, $partnerId, $notifyURL, $returnURL, $orderPrefix)
    {
        $service = 'create_forex_trade';
        $productCode = null;
        if ($accountType == 'new_cross_border_pc') {
            $productCode = 'NEW_OVERSEAS_SELLER';
        } elseif ($accountType == 'hk_wallet_pc2mobile') {
            $productCode = 'NEW_WAP_OVERSEAS_SELLER';
            $service = 'create_forex_trade_wap';
        } elseif ($accountType == 'new_cross_border_wap') {
            $productCode = 'NEW_WAP_OVERSEAS_SELLER';
            $service = 'create_forex_trade_wap';
        } elseif ($accountType == 'new_cross_border_auto') {
            if (wp_is_mobile()) {
                $productCode = 'NEW_WAP_OVERSEAS_SELLER';
                $service = 'create_forex_trade_wap';
            } else {
                $productCode = 'NEW_OVERSEAS_SELLER';
            }
        }

        $params = [
            'service' => $service,
            'partner' => $partnerId,
            'notify_url' => urldecode($notifyURL),
            'return_url' => urldecode($returnURL),
            'out_trade_no' => $orderPrefix . ltrim($order->get_order_number(), '#'),
            'subject' => $this->getSubject($order),
            'total_fee' => $this->getAliPayTotal($order),
            'currency' => $order->get_order_currency(),
            '_input_charset' => strtolower('utf-8'),
            'product_code' => $productCode,
        ];

        if ($paymentInst) {
            $params['qr_pay_mode'] = 4;
            $params['qrcode_width'] = 200;
            $params['payment_inst'] = $paymentInst;
        }

        return $params;
    }

    protected function getAliPayTotal($order)
    {
        $total = $order->get_total();
        $currency = $order->get_order_currency();
        $supportedCurrencies = $this->getAliPaySupportedCurrencies();

        return round($total, $supportedCurrencies[$currency]);
    }

    public function getAliPayConfig($accountType, $paymentInst, $partnerId, $securityKey, $notifyURL, $returnURL, $isForceSSL)
    {
        $config = [
            'partner' => trim($partnerId),
            'key' => trim($securityKey),
            'notify_url' => $notifyURL,
            'return_url' => $returnURL,
            'sign_type' => strtoupper('MD5'),
            'input_charset' => strtolower('utf-8'),
            'cacert' => dirname(__FILE__) . '/../lib' . DIRECTORY_SEPARATOR . 'cacert.pem',
            'transport' => $this->getHttpMode($isForceSSL),
            'service' => 'create_forex_trade',
        ];

        if ($paymentInst) {
            $config['payment_inst'] = $paymentInst;
        }

        return $config;
    }

    protected function getHttpMode($isForceSSL)
    {
        if (is_ssl() || $isForceSSL == 'yes') {
            return 'https';
        }
        return 'http';
    }

    protected function getSubject($order)
    {
        $length = 256;
        $subject = '';

        foreach ($order->get_items() as $item) {
            if (empty($subject)) {
                $subject = $item['name'];
            } else {
                $subject .= ' | ' . $item['name'];
            }
        }

        if (empty($subject)) {
            $subject = '#' . $order->id;
        } else {
            if (strlen($subject) > $length) {
                $subject = mb_strimwidth($subject, 0, ($length-3), '...');
            }
            $subject = $this->cleanString($subject);
        }

        return $subject;
    }

    protected function cleanString($str)
    {
        if (!empty($str)) {
            $clean = str_replace(['%'], '', $str);
            $clean = sanitize_text_field($clean);
            $clean = html_entity_decode($clean, ENT_NOQUOTES);
        }

        return $clean;
    }

    public function getAliPayResponseParams($accountType)
    {
        $params = [
            'sign_type',
            'sign',
            'trade_status',
            'trade_no',
            'out_trade_no',
            'currency',
            'total_fee',
        ];

        if ($accountType == 'hk_wallet_pc2mobile') {
            $params[] = 'payment_inst';
        }

        return $params;
    }

    public function getAliPayNotifyParams($accountType)
    {
        $params = [
            'sign_type',
            'sign',
            'notify_type',
            'notify_id',
            'notify_time',
            'trade_status',
            'trade_no',
            'out_trade_no',
            'currency',
            'total_fee',
        ];

        if ($accountType == 'hk_wallet_pc2mobile') {
            $params[] = 'payment_inst';
        }

        return $params;
    }

    public function showNotifyRespond($isSuccess)
    {
        header('HTTP/1.1 200 OK');
        if ($isSuccess) {
            echo "success";
        } else {
            echo "fail";
        }
        exit;
    }

    public function getAliPaySupportedCurrencies()
    {
        return [
            'AUD' => 2,
            'CAD' => 2,
            'CHF' => 2,
            'DKK' => 2,
            'EUR' => 2,
            'GBP' => 2,
            'HKD' => 2,
            'JPY' => 0,
            'KRW' => 0,
            'NOK' => 2,
            'NZD' => 2,
            'SEK' => 2,
            'SGD' => 2,
            'THB' => 2,
            'USD' => 2,
        ];
    }
}
