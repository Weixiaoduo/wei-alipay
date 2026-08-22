<?php
/**
 * Offline reconciliation parser contract.
 */

define( 'ABSPATH', __DIR__ );

function __( $text ) { return $text; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wc_get_price_decimals() { return 2; }
function wc_format_decimal( $value, $decimals = 2 ) { return number_format( (float) $value, $decimals, '.', '' ); }
function get_current_blog_id() { return 11; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

class Alipay_SDK_Helper {
    public static function amounts_equal( $left, $right ) {
        return (int) round( (float) $left * 100 ) === (int) round( (float) $right * 100 );
    }
}

class Wei_Alipay_Test_Order {
    private $id;
    private $total;
    private $merchant_number;
    public function __construct( $id, $total, $merchant_number ) {
        $this->id = $id;
        $this->total = $total;
        $this->merchant_number = $merchant_number;
    }
    public function get_id() { return $this->id; }
    public function get_total() { return $this->total; }
    public function get_order_number() { return (string) $this->id; }
    public function get_status() { return 'processing'; }
    public function get_meta( $key ) { return '_alipay_out_trade_no' === $key ? $this->merchant_number : ''; }
}

$orders = array(
    101 => new Wei_Alipay_Test_Order( 101, '10.00', 'WooA11-101-1001' ),
    102 => new Wei_Alipay_Test_Order( 102, '12.00', 'WooA11-102-1002' ),
);
function wc_get_order( $order_id ) {
    global $orders;
    return $orders[ $order_id ] ?? false;
}

require dirname( __DIR__ ) . '/inc/modules/reconcile/inc/class-wei-alipay-reconcile-runner.php';

$csv = implode( "\n", array(
    '支付宝交易号,商户订单号,订单金额（元）,交易状态,完成时间',
    '20260822000000000001,WooA11-101-1001,10.00,交易成功,2026-08-22 18:50:00',
    '20260822000000000002,WooA11-102-1002,15.00,交易成功,2026-08-22 18:51:00',
    '20260822000000000003,WooA11-999-1003,5.00,交易成功,2026-08-22 18:52:00',
    '20260822000000000004,WooA12-201-1004,6.00,交易成功,2026-08-22 18:53:00',
) );
$file = tempnam( sys_get_temp_dir(), 'wei-alipay-reconcile-' );
file_put_contents( $file, $csv );
$method = new ReflectionMethod( 'Wei_Alipay_Reconcile_Runner', 'parse_and_reconcile' );
$method->setAccessible( true );
$result = $method->invoke( null, $file );
unlink( $file );

$assertions = 0;
$assert = static function ( $condition, $message ) use ( &$assertions ) {
    ++$assertions;
    if ( ! $condition ) {
        fwrite( STDERR, "[FAIL] {$message}\n" );
        exit( 1 );
    }
};

$assert( ! is_wp_error( $result ), 'synthetic statement parses' );
$assert( 4 === $result['total_rows'], 'all statement rows are counted' );
$assert( 1 === $result['matched'], 'one row matches' );
$assert( 1 === $result['mismatched'], 'one amount mismatch is reported' );
$assert( 2 === $result['unmatched'], 'two unmatched rows are reported' );
$assert( 3 === $result['detail_counts']['anomalies'], 'all anomalies are counted' );
$assert( 1 === $result['detail_counts']['matched'], 'matched details are counted' );
$assert( '2026-08-22 18:50:00' === $result['details']['matched'][0]['completed_at'], 'statement completion time is retained' );
$assert( '交易成功' === $result['details']['matched'][0]['trade_status'], 'statement status is retained' );
$assert( '2026**********0001' === $result['details']['matched'][0]['trade_no_masked'], 'provider trade number is masked' );
$reasons = array_column( $result['details']['anomalies'], 'reason' );
$assert( in_array( 'amount_mismatch', $reasons, true ), 'amount mismatch reason is explicit' );
$assert( in_array( 'order_not_found', $reasons, true ), 'missing WooCommerce order reason is explicit' );
$assert( in_array( 'different_site', $reasons, true ), 'multisite mismatch reason is explicit' );
$assert( 102 === $result['details']['anomalies'][0]['order_id'], 'amount mismatch links to its WooCommerce order' );

printf( "[PASS] offline reconciliation parser: %d assertions, 4 rows\n", $assertions );
