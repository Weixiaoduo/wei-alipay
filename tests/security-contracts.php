<?php
class WP_Error {
    private $code;
    public function __construct($code, $message = '') { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class WC_Order {
    private $method;
    private $transaction;
    public function __construct($method = 'alipay', $transaction = '') { $this->method = $method; $this->transaction = $transaction; }
    public function get_payment_method() { return $this->method; }
    public function get_transaction_id() { return $this->transaction; }
}
function __($message, $domain = '') { return $message; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/inc/class-alipay-sdk-helper.php';

$failures = 0;
function expect_true($condition, $label) {
    global $failures;
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); ++$failures; }
    else { echo "PASS: $label\n"; }
}
function expect_error($value, $code, $label) {
    expect_true($value instanceof WP_Error && $code === $value->get_error_code(), $label);
}

$order = new WC_Order('alipay', 'trade-1');
$valid = array(
    'app_id' => 'app-1',
    'out_trade_no' => 'WooA42-1',
    'trade_no' => 'trade-1',
    'total_amount' => '10.00',
    'trade_status' => 'TRADE_SUCCESS',
    'sign' => 'secret-signature',
    'buyer_logon_id' => 'buyer@example.test',
);
expect_true(true === Alipay_SDK_Helper::validate_notify_context($valid, $order, 'app-1', 10, 'WooA42-1', array('alipay')), 'valid notification context');
foreach (array(
    'app_id' => array('app-2', 'app_id_mismatch'),
    'out_trade_no' => array('WooA43-1', 'out_trade_no_mismatch'),
    'total_amount' => array('10.01', 'amount_mismatch'),
    'trade_no' => array('trade-2', 'trade_no_mismatch'),
) as $field => $case) {
    $changed = $valid;
    $changed[$field] = $case[0];
    expect_error(Alipay_SDK_Helper::validate_notify_context($changed, $order, 'app-1', 10, 'WooA42-1', array('alipay')), $case[1], "reject $field mismatch");
}
expect_error(Alipay_SDK_Helper::validate_notify_context($valid, new WC_Order('cod'), 'app-1', 10, 'WooA42-1', array('alipay')), 'payment_method_mismatch', 'reject payment method mismatch');
$redacted = Alipay_SDK_Helper::redact_notify_for_log($valid);
expect_true(!isset($redacted['sign']) && !isset($redacted['buyer_logon_id']), 'redact signature and buyer identity');
expect_true('WooA42-1' === $redacted['out_trade_no'], 'retain bounded diagnostic fields');
exit($failures ? 1 : 0);
