<?php
define('WOO_ALIPAY_PLUGIN_PATH', '/tmp/woo-alipay/');
define('WOO_ALIPAY_PLUGIN_URL', 'https://example.test/woo-alipay/');
define('WOO_ALIPAY_PLUGIN_FILE', '/tmp/woo-alipay/woo-alipay.php');
define('WOO_ALIPAY_VERSION', '3.2.0');
class AopClient {
    public $gatewayUrl;
    public $appId;
    public $rsaPrivateKey;
    public $alipayrsaPublicKey;
    public $charset;
    public $signType;
    public function execute($request) {}
    public function pageExecute($request, $httpmethod = "POST") {}
    public function rsaCheckV1($params, $key, $signType = "RSA") {}
}
class AlipayTradeService {
    public function __construct($config) {}
    public function aopclientRequestExecute($request) {}
    public function pagePay($builder, $returnUrl, $notifyUrl) {}
    public function wapPay($builder, $returnUrl, $notifyUrl) {}
    public function Refund($builder) {}
    public function check($params) {}
}
class AlipayTradeQueryRequest { public function setBizContent($value) {} }
class AlipayTradeCloseRequest { public function setBizContent($value) {} }
class AlipayTradeRefundRequest { public function setBizContent($value) {} }
class AlipayTradePrecreateRequest { public function setBizContent($value) {} public function setNotifyUrl($value) {} }
class AlipayTradePagePayRequest { public function setBizContent($value) {} public function setReturnUrl($value) {} public function setNotifyUrl($value) {} }
class AlipayTradePayRequest { public function setBizContent($value) {} public function setNotifyUrl($value) {} }
class AlipayTradeCreateRequest { public function setBizContent($value) {} public function setNotifyUrl($value) {} }
class AlipayTradeWapPayContentBuilder {
    public function setBody($value) {} public function setSubject($value) {} public function setTotalAmount($value) {} public function setOutTradeNo($value) {} public function setTimeExpress($value) {}
}
class AlipayTradePagePayContentBuilder extends AlipayTradeWapPayContentBuilder {}
class AlipayTradeRefundContentBuilder {
    public function setOutTradeNo($value) {} public function setTradeNo($value) {} public function setRefundAmount($value) {} public function setOutRequestNo($value) {} public function setRefundReason($value) {}
}
