<?php
class WP_Error {
    private $code;
    public function __construct($code, $message = '') { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class WC_Order {
    private $method;
    private $transaction;
    private $status;
    public function __construct($method = 'alipay', $transaction = '', $status = 'pending') {
        $this->method = $method;
        $this->transaction = $transaction;
        $this->status = $status;
    }
    public function get_payment_method() { return $this->method; }
    public function get_transaction_id() { return $this->transaction; }
    public function get_status() { return $this->status; }
    public function is_paid() { return in_array($this->status, array('processing', 'completed'), true); }
}
function __($message, $domain = '') { return $message; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wp_generate_uuid4() { static $i = 0; return 'test-token-' . ++$i; }
function wp_json_encode($value) { return json_encode($value); }
$GLOBALS['test_options'] = array();
function add_option($key, $value) {
    if (array_key_exists($key, $GLOBALS['test_options'])) { return false; }
    $GLOBALS['test_options'][$key] = $value;
    return true;
}
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['test_options']) ? $GLOBALS['test_options'][$key] : $default; }
function delete_option($key) { unset($GLOBALS['test_options'][$key]); return true; }
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

$now = (new DateTimeImmutable('2026-08-09 18:00:00', new DateTimeZone('Asia/Shanghai')))->getTimestamp();
$order = new WC_Order('alipay', 'trade-1');
$valid = array(
    'app_id' => 'app-1',
    'seller_id' => '2088000000000001',
    'out_trade_no' => 'WooA42-1',
    'trade_no' => 'trade-1',
    'total_amount' => '10.00',
    'trade_status' => 'TRADE_SUCCESS',
    'notify_id' => 'notify-1',
    'notify_time' => '2026-08-09 17:59:00',
    'sign_type' => 'RSA2',
    'charset' => 'utf-8',
    'sign' => 'secret-signature',
    'buyer_logon_id' => 'buyer@example.test',
);
$args = array($valid, $order, 'app-1', '10.00', 'WooA42-1', array('alipay'), '2088000000000001', 259200, $now);
expect_true(true === Alipay_SDK_Helper::validate_notify_context(...$args), 'valid notification context');
foreach (array(
    'app_id' => array('app-2', 'app_id_mismatch'),
    'seller_id' => array('2088000000000002', 'seller_id_mismatch'),
    'out_trade_no' => array('WooA43-1', 'out_trade_no_mismatch'),
    'total_amount' => array('10.01', 'amount_mismatch'),
    'trade_no' => array('trade-2', 'trade_no_mismatch'),
    'sign_type' => array('RSA', 'unsupported_sign_type'),
) as $field => $case) {
    $changed = $valid;
    $changed[$field] = $case[0];
    expect_error(Alipay_SDK_Helper::validate_notify_context($changed, $order, 'app-1', '10.00', 'WooA42-1', array('alipay'), '2088000000000001', 259200, $now), $case[1], "reject $field mismatch");
}
expect_error(Alipay_SDK_Helper::validate_notify_context($valid, new WC_Order('cod', 'trade-1'), 'app-1', '10.00', 'WooA42-1', array('alipay'), '2088000000000001', 259200, $now), 'payment_method_mismatch', 'reject payment method mismatch');

$cross_order = $valid;
$cross_order['out_trade_no'] = 'WooA99-1';
expect_error(Alipay_SDK_Helper::validate_notify_context($cross_order, $order, 'app-1', '10.00', 'WooA42-1', array('alipay'), '2088000000000001', 259200, $now), 'out_trade_no_mismatch', 'reject cross-order signed replay');
expect_error(Alipay_SDK_Helper::validate_notify_context($valid, $order, 'app-1', '10.00', '', array('alipay'), '2088000000000001', 259200, $now), 'out_trade_no_mismatch', 'reject order without stored merchant number');

foreach (array(
    array('1e1', 'amount_mismatch'),
    array('10.000', 'amount_mismatch'),
    array('010.00', 'amount_mismatch'),
    array('-10.00', 'amount_mismatch'),
    array("１０.００", 'invalid_amount'),
    array('10,00', 'invalid_amount'),
    array('INF', 'invalid_amount'),
    array('NaN', 'invalid_amount'),
) as $bad_case) {
    list($bad_amount, $amount_error) = $bad_case;
    $changed = $valid;
    $changed['total_amount'] = $bad_amount;
    expect_error(Alipay_SDK_Helper::validate_notify_context($changed, $order, 'app-1', '10.00', 'WooA42-1', array('alipay'), '2088000000000001', 259200, $now), $amount_error, "reject malformed amount $bad_amount");
}
expect_true('10.00' === Alipay_SDK_Helper::normalize_amount('10'), 'normalize integer decimal exactly');
expect_true('10.10' === Alipay_SDK_Helper::normalize_amount('10.1'), 'normalize one decimal exactly');
expect_true(1000 === Alipay_SDK_Helper::amount_to_minor_units('10.00'), 'convert decimal to integer minor units');
expect_true(1 === Alipay_SDK_Helper::amount_to_minor_units('0.01'), 'preserve one minor unit exactly');
expect_true(false === Alipay_SDK_Helper::amount_to_minor_units('1e1'), 'reject exponent before minor-unit conversion');

$expired = $valid;
$expired['notify_time'] = '2026-08-01 00:00:00';
expect_error(Alipay_SDK_Helper::validate_notify_context($expired, $order, 'app-1', '10.00', 'WooA42-1', array('alipay'), '2088000000000001', 259200, $now), 'expired_notify', 'reject expired notification');
$future = $valid;
$future['notify_time'] = '2026-08-09 18:06:00';
expect_error(Alipay_SDK_Helper::validate_notify_context($future, $order, 'app-1', '10.00', 'WooA42-1', array('alipay'), '2088000000000001', 259200, $now), 'future_notify', 'reject far-future notification');
$bad_time = $valid;
$bad_time['notify_time'] = '2026-02-30 10:00:00';
expect_error(Alipay_SDK_Helper::validate_notify_context($bad_time, $order, 'app-1', '10.00', 'WooA42-1', array('alipay'), '2088000000000001', 259200, $now), 'invalid_notify_time', 'reject impossible notification date');
$bad_encoding = $valid;
$bad_encoding['trade_no'] = "bad\xFFtext";
expect_error(Alipay_SDK_Helper::validate_notify_context($bad_encoding, $order, 'app-1', '10.00', 'WooA42-1', array('alipay'), '2088000000000001', 259200, $now), 'invalid_encoding', 'reject invalid UTF-8 field');
$array_field = $valid;
$array_field['trade_no'] = array('trade-1');
expect_error(Alipay_SDK_Helper::validate_notify_context($array_field, $order, 'app-1', '10.00', 'WooA42-1', array('alipay'), '2088000000000001', 259200, $now), 'invalid_encoding', 'reject array field encoding bypass');
$missing_trade = $valid;
unset($missing_trade['trade_no']);
expect_error(Alipay_SDK_Helper::validate_notify_context($missing_trade, new WC_Order('alipay'), 'app-1', '10.00', 'WooA42-1', array('alipay'), '2088000000000001', 259200, $now), 'missing_trade_no', 'require trade number for successful payment');

expect_true('process_payment' === Alipay_SDK_Helper::validate_notify_transition(new WC_Order('alipay', '', 'pending'), 'TRADE_SUCCESS'), 'pending to paid transition');
expect_true('process_payment' === Alipay_SDK_Helper::validate_notify_transition(new WC_Order('alipay_wap', '', 'pending'), 'WAIT_SELLER_SEND_GOODS'), 'legacy paid status transitions once');
expect_true('process_close' === Alipay_SDK_Helper::validate_notify_transition(new WC_Order('alipay', '', 'on-hold'), 'TRADE_CLOSED'), 'on-hold to closed transition');
expect_true('acknowledge' === Alipay_SDK_Helper::validate_notify_transition(new WC_Order('alipay', 'trade-1', 'processing'), 'TRADE_CLOSED'), 'late close cannot regress paid order');
expect_true('acknowledge' === Alipay_SDK_Helper::validate_notify_transition(new WC_Order('alipay', '', 'cancelled'), 'TRADE_CLOSED'), 'duplicate close acknowledged');
expect_error(Alipay_SDK_Helper::validate_notify_transition(new WC_Order('alipay', '', 'cancelled'), 'TRADE_SUCCESS'), 'terminal_order_state', 'late success cannot revive cancelled order');

$lock1 = Alipay_SDK_Helper::acquire_lock('notify', 'WooA42-1');
$lock2 = Alipay_SDK_Helper::acquire_lock('notify', 'WooA42-1');
expect_true(is_string($lock1) && false === $lock2, 'concurrent replay lock admits one owner');
Alipay_SDK_Helper::release_lock('notify', 'WooA42-1', 'wrong-owner');
expect_true(false === Alipay_SDK_Helper::acquire_lock('notify', 'WooA42-1'), 'wrong owner cannot release replay lock');
Alipay_SDK_Helper::release_lock('notify', 'WooA42-1', $lock1);
expect_true(false !== Alipay_SDK_Helper::acquire_lock('notify', 'WooA42-1'), 'correct owner releases replay lock');

$redacted = Alipay_SDK_Helper::redact_notify_for_log($valid);
expect_true(!isset($redacted['sign']) && !isset($redacted['buyer_logon_id']), 'redact signature and buyer identity');
expect_true('WooA42-1' === $redacted['out_trade_no'], 'retain bounded diagnostic fields');
exit($failures ? 1 : 0);
