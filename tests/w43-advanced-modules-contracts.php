<?php
/**
 * 4.3 Advanced Modules & Shared WenPai Bridge Updater runtime + contract tests:
 *  - Updater: WenPai Bridge /api/v1/update-check + /api/v1/plugins/{slug}, 404 legacy fallback, host allowlist
 *  - Cross-border: idempotent process_refund() + refunds capability + wc_back_link
 *  - Fund Auth: no SIM-AUTH simulation, real AlipayTradePayRequest capture, signed async notify
 *  - Profitsharing: P1-6 product+default double-split fix, >100% hard cap, discount scaling, bind/unbind relation API
 *  - Subscriptions: P1-5 AlipayTradePayRequest, response state machine (paid/pending/needs_query/mismatch/fail), hook dedupe
 *  - Feature gate: all 4 Phase-2 modules remain unavailable pending live sandbox acceptance
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WEI_ALIPAY_PLUGIN_PATH', dirname( __DIR__ ) . '/' );
define( 'WEI_ALIPAY_PLUGIN_URL', 'https://example.test/wp-content/plugins/wei-alipay/' );
define( 'WEI_ALIPAY_PLUGIN_FILE', dirname( __DIR__ ) . '/wei-alipay.php' );
define( 'WEI_ALIPAY_VERSION', '4.3.0' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$root     = dirname( __DIR__ );
$failures = 0;

$expect = static function( $cond, $label ) use ( &$failures ) {
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		++$failures;
		return;
	}
	echo "PASS: {$label}\n";
};

// Minimal WordPress / WooCommerce shims for offline runtime testing
$GLOBALS['__mock_options']    = array();
$GLOBALS['__mock_transients'] = array();
$GLOBALS['__mock_http_post']  = null;
$GLOBALS['__mock_http_get']   = null;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return (string) $url; }
function esc_url_raw( $url ) { return (string) $url; }
function wp_kses_post( $str ) { return (string) $str; }
function plugin_basename( $file ) { return 'wei-alipay/wei-alipay.php'; }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {}
function add_action( $tag, $cb, $prio = 10, $args = 1 ) {}
function apply_filters( $tag, $val ) { return $val; }
function is_multisite() { return false; }
function get_current_blog_id() { return 1; }
function get_bloginfo( $show = '' ) { return 'version' === $show ? '6.8' : 'TestShop'; }
function home_url( $path = '' ) { return 'https://shop.example.test' . $path; }
function current_time( $type = 'mysql' ) { return 'timestamp' === $type ? 1732260000 : '2026-11-22 16:00:00'; }
function wp_json_encode( $data, $options = 0 ) { return json_encode( $data, $options ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function sanitize_text_field( $str ) { return trim( (string) $str ); }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['__mock_options'] ) ? $GLOBALS['__mock_options'][ $name ] : $default; }
function get_transient( $key ) { return array_key_exists( $key, $GLOBALS['__mock_transients'] ) ? $GLOBALS['__mock_transients'][ $key ] : false; }
function set_transient( $key, $val, $ttl = 0 ) { $GLOBALS['__mock_transients'][ $key ] = $val; return true; }
function delete_transient( $key ) { unset( $GLOBALS['__mock_transients'][ $key ] ); return true; }
function wp_remote_post( $url, $args = array() ) { return call_user_func( $GLOBALS['__mock_http_post'], $url, $args ); }
function wp_remote_get( $url, $args = array() ) { return call_user_func( $GLOBALS['__mock_http_get'], $url, $args ); }
function wp_remote_retrieve_response_code( $resp ) { return is_array( $resp ) ? (int) ( $resp['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $resp ) { return is_array( $resp ) ? (string) ( $resp['body'] ?? '' ) : ''; }
function wc_get_logger() { return null; }

class Mock_WC_Product {
	private $id;
	private $cats;
	public function __construct( $id, $cats = array() ) { $this->id = $id; $this->cats = $cats; }
	public function get_id() { return $this->id; }
	public function get_category_ids() { return $this->cats; }
}
class Mock_WC_Order_Item {
	private $subtotal;
	private $tax;
	private $product;
	public function __construct( $subtotal, $tax, $product ) { $this->subtotal = $subtotal; $this->tax = $tax; $this->product = $product; }
	public function get_subtotal() { return $this->subtotal; }
	public function get_subtotal_tax() { return $this->tax; }
	public function get_product() { return $this->product; }
}
class WC_Order {
	public $id = 101;
	public $total = 100.0;
	public $shipping = 0.0;
	public $shipping_tax = 0.0;
	public $items = array();
	public $meta = array();
	public function get_id() { return $this->id; }
	public function get_order_number() { return (string) $this->id; }
	public function get_total() { return $this->total; }
	public function get_shipping_total() { return $this->shipping; }
	public function get_shipping_tax() { return $this->shipping_tax; }
	public function get_items( $type = 'line_item' ) { return $this->items; }
	public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function save() {}
}
class WC_Order_Refund extends WC_Order {}

// =========================================================================
// 1. Updater: WenPai Bridge /api/v1 + 404 fallback + host allowlist
// =========================================================================
require_once $root . '/includes/class-wenpai-updater.php';

$updater = new Wei_Alipay_WenPai_Updater( 'wei-alipay/wei-alipay.php', '4.2.0' );

// 1a. /api/v1/update-check happy path with downloads.feicode.com package
$posted_urls = array();
$GLOBALS['__mock_http_post'] = static function( $url, $args ) use ( &$posted_urls ) {
	$posted_urls[] = $url;
	return array(
		'code' => 200,
		'body' => json_encode( array(
			'plugins' => array(
				'wei-alipay/wei-alipay.php' => array(
					'version' => '4.3.0',
					'package' => 'https://downloads.feicode.com/wei-alipay/wei-alipay-4.3.0.zip',
					'url'          => 'https://www.weixiaoduo.com/product/wei-alipay.html',
					'requires'     => '6.2',
					'tested'       => '6.8',
					'requires_php' => '7.4',
				),
			),
		) ),
	);
};
$GLOBALS['__mock_http_get']   = static function( $url ) { return array( 'code' => 500, 'body' => '' ); };
$GLOBALS['__mock_transients'] = array();

$res = $updater->check_update( false, array(), 'wei-alipay/wei-alipay.php', array( 'zh_CN' ) );
$expect( array( 'https://updates.weixiaoduo.com/api/v1/update-check' ) === $posted_urls, 'Updater POSTs to https://updates.weixiaoduo.com/api/v1/update-check' );
$expect( is_array( $res ) && '4.3.0' === ( $res['version'] ?? '' ) && 'https://downloads.feicode.com/wei-alipay/wei-alipay-4.3.0.zip' === ( $res['package'] ?? '' ), 'Updater parses WenPai Bridge /api/v1/update-check response and accepts downloads.feicode.com ZIP' );

// 1b. Untrusted download_url is rejected
$GLOBALS['__mock_transients'] = array();
$GLOBALS['__mock_http_post']  = static function( $url, $args ) {
	return array(
		'code' => 200,
		'body' => json_encode( array(
			'plugins' => array(
				'wei-alipay/wei-alipay.php' => array(
					'version' => '4.3.0',
					'package' => 'https://evil.example.com/wei-alipay-4.3.0.zip',
				),
			),
		) ),
	);
};
$res2 = $updater->check_update( false, array(), 'wei-alipay/wei-alipay.php', array( 'zh_CN' ) );
$expect( false === $res2, 'Updater rejects package URL outside shared WenPai Bridge + Weixiaoduo host allowlist' );

// 1c. Transparent 404 fallback to legacy updates.weixiaoduo.com/{slug}.json during DNS cutover
$GLOBALS['__mock_transients'] = array();
$got_urls                     = array();
$GLOBALS['__mock_http_post']  = static function( $url, $args ) {
	return array( 'code' => 404, 'body' => 'Not Found' );
};
$GLOBALS['__mock_http_get']   = static function( $url, $args ) use ( &$got_urls ) {
	$got_urls[] = $url;
	return array(
		'code' => 200,
		'body' => json_encode( array(
			'version'      => '4.3.0',
			'download_url' => 'https://updates.weixiaoduo.com/packages/wei-alipay-4.3.0.zip',
			'homepage'     => 'https://www.weixiaoduo.com/product/wei-alipay.html',
		) ),
	);
};
$res3 = $updater->check_update( false, array(), 'wei-alipay/wei-alipay.php', array( 'zh_CN' ) );
$expect( array( 'https://updates.weixiaoduo.com/wei-alipay.json' ) === $got_urls, 'Updater falls back to updates.weixiaoduo.com/wei-alipay.json when /api/v1 returns 404' );
$expect( is_array( $res3 ) && '4.3.0' === ( $res3['version'] ?? '' ), 'Updater transition fallback resolves valid 4.3.0 update' );

// 1d. plugins_api hits GET /api/v1/plugins/wei-alipay
$GLOBALS['__mock_transients'] = array();
$api_urls                     = array();
$GLOBALS['__mock_http_get']   = static function( $url, $args ) use ( &$api_urls ) {
	$api_urls[] = $url;
	return array(
		'code' => 200,
		'body' => json_encode( array(
			'name'         => '文派支付宝',
			'slug'         => 'wei-alipay',
			'version'      => '4.3.0',
			'download_url' => 'https://downloads.feicode.com/wei-alipay/wei-alipay-4.3.0.zip',
			'description'  => 'WordPress & WooCommerce 支付宝网关',
		) ),
	);
};
$info = $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'wei-alipay' ) );
$expect( array( 'https://updates.weixiaoduo.com/api/v1/plugins/wei-alipay' ) === $api_urls, 'Updater plugin_info queries /api/v1/plugins/wei-alipay' );
$expect( is_object( $info ) && '4.3.0' === $info->version, 'Updater plugin_info returns parsed WenPai Bridge plugin metadata' );

// =========================================================================
// 2. Profitsharing runtime math (P1-6) + bind/unbind relation API
// =========================================================================
require_once $root . '/inc/modules/profitsharing/inc/class-wei-alipay-ps-service.php';
require_once $root . '/lib/alipay/aop/request/AlipayTradeRoyaltyRelationBindRequest.php';
require_once $root . '/lib/alipay/aop/request/AlipayTradeRoyaltyRelationUnbindRequest.php';

$GLOBALS['__mock_options']['woo_alipay_ps_settings'] = array(
	'base'       => 'order_total_minus_shipping',
	'round'      => 2,
	'min_amount' => '0.01',
);
$GLOBALS['__mock_options']['woo_alipay_ps_receivers'] = array(
	'r1' => array( 'enabled' => 1, 'label' => 'Vendor A', 'identity' => 'vendor-a@example.com', 'identity_type' => 'ALIPAY_LOGON_ID' ),
	'r2' => array( 'enabled' => 1, 'label' => 'Platform', 'identity' => '2088100000000001', 'identity_type' => 'ALIPAY_USER_ID' ),
);

// 2a. Product rule + default rule with NO category rules: no double-splitting!
$GLOBALS['__mock_options']['woo_alipay_ps_rules'] = array(
	'product'  => array( '10' => array( array( 'receiver_id' => 'r1', 'percent' => 50 ) ) ),
	'category' => array(),
	'default'  => array( array( 'receiver_id' => 'r2', 'percent' => 30 ) ),
);
$order_a = new WC_Order();
$order_a->total = 100.00;
$order_a->items = array(
	new Mock_WC_Order_Item( 60.00, 0.00, new Mock_WC_Product( 10 ) ),
	new Mock_WC_Order_Item( 40.00, 0.00, new Mock_WC_Product( 20 ) ),
);
$jobs_a = Wei_Alipay_PS_Service::compute_jobs_for_order( $order_a );
$map_a  = array();
foreach ( $jobs_a as $j ) { $map_a[ $j['receiver_id'] ] = $j['amount']; }
$expect( abs( ( $map_a['r1'] ?? 0 ) - 30.00 ) < 0.001 && abs( ( $map_a['r2'] ?? 0 ) - 12.00 ) < 0.001, 'Profitsharing partitions product rule (30.00) and default rule on remainder (12.00) without double-splitting (P1-6)' );

// 2b. Over-100% rules are hard-capped at 100% of base_amount
$GLOBALS['__mock_options']['woo_alipay_ps_rules'] = array(
	'product'  => array(
		'10' => array(
			array( 'receiver_id' => 'r1', 'percent' => 80 ),
			array( 'receiver_id' => 'r2', 'percent' => 60 ),
		),
	),
	'category' => array(),
	'default'  => array(),
);
$order_b = new WC_Order();
$order_b->total = 100.00;
$order_b->items = array( new Mock_WC_Order_Item( 100.00, 0.00, new Mock_WC_Product( 10 ) ) );
$jobs_b = Wei_Alipay_PS_Service::compute_jobs_for_order( $order_b );
$sum_b  = array_sum( array_column( $jobs_b, 'amount' ) );
$expect( $sum_b <= 100.0001 && $sum_b >= 99.99, 'Profitsharing hard-caps >100% rules so total split <= base_amount (' . $sum_b . ')' );

// 2c. Discounted order scales product rules proportionally to base_amount
$GLOBALS['__mock_options']['woo_alipay_ps_rules'] = array(
	'product'  => array( '10' => array( array( 'receiver_id' => 'r1', 'percent' => 100 ) ) ),
	'category' => array(),
	'default'  => array(),
);
$order_c = new WC_Order();
$order_c->total = 80.00;
$order_c->items = array( new Mock_WC_Order_Item( 100.00, 0.00, new Mock_WC_Product( 10 ) ) );
$jobs_c = Wei_Alipay_PS_Service::compute_jobs_for_order( $order_c );
$sum_c  = array_sum( array_column( $jobs_c, 'amount' ) );
$expect( abs( $sum_c - 80.00 ) < 0.001, 'Profitsharing scales product rule to discounted base_amount (80.00, not 100.00)' );

// 2d. bind_relation() & unbind_relation() runtime test against mock AOP
$mock_ps_aop = new class {
	public $last_class = '';
	public $last_biz   = array();
	public function execute( $req ) {
		$this->last_class = get_class( $req );
		$this->last_biz   = json_decode( $req->getBizContent(), true );
		$resp             = new stdClass();
		if ( 'AlipayTradeRoyaltyRelationUnbindRequest' === $this->last_class ) {
			$resp->alipay_trade_royalty_relation_unbind_response = (object) array( 'code' => '10000', 'result_code' => 'SUCCESS' );
		} else {
			$resp->alipay_trade_royalty_relation_bind_response = (object) array( 'code' => '10000', 'result_code' => 'SUCCESS' );
		}
		return $resp;
	}
};
$bind_res = Wei_Alipay_PS_Service::bind_relation( array( 'identity' => '2088100000000001', 'identity_type' => 'ALIPAY_USER_ID', 'label' => 'Platform' ), 'PSB-TEST-1', $mock_ps_aop );
$expect( ! empty( $bind_res['success'] ) && 'AlipayTradeRoyaltyRelationBindRequest' === $mock_ps_aop->last_class && 'userId' === ( $mock_ps_aop->last_biz['receiver_list'][0]['type'] ?? '' ), 'Profitsharing bind_relation() executes AlipayTradeRoyaltyRelationBindRequest with mapped receiver type' );
$unbind_res = Wei_Alipay_PS_Service::unbind_relation( array( 'identity' => 'vendor-a@example.com', 'identity_type' => 'ALIPAY_LOGON_ID' ), 'PSU-TEST-1', $mock_ps_aop );
$expect( ! empty( $unbind_res['success'] ) && 'AlipayTradeRoyaltyRelationUnbindRequest' === $mock_ps_aop->last_class && 'loginName' === ( $mock_ps_aop->last_biz['receiver_list'][0]['type'] ?? '' ), 'Profitsharing unbind_relation() executes AlipayTradeRoyaltyRelationUnbindRequest' );

// =========================================================================
// 3. Subscriptions runtime (P1-5) + single renewal hook check
// =========================================================================
require_once $root . '/inc/class-alipay-sdk-helper.php';
require_once $root . '/inc/modules/subscriptions/inc/class-wc-alipay-subscriptions-charge.php';

$eval_paid = WC_Alipay_Subscriptions_Charge::evaluate_pay_response( (object) array( 'code' => '10000', 'out_trade_no' => 'WOOAS101-1', 'trade_no' => '202611220001', 'trade_status' => 'TRADE_SUCCESS' ), 'WOOAS101-1' );
$expect( is_array( $eval_paid ) && true === $eval_paid['success'] && 'paid' === $eval_paid['status'], 'Subscriptions evaluate_pay_response marks TRADE_SUCCESS as paid' );

$eval_gmt = WC_Alipay_Subscriptions_Charge::evaluate_pay_response( (object) array( 'code' => '10000', 'out_trade_no' => 'WOOAS101-1', 'trade_no' => '202611220001', 'gmt_payment' => '2026-11-22 16:00:00' ), 'WOOAS101-1' );
$expect( is_array( $eval_gmt ) && true === $eval_gmt['success'] && 'paid' === $eval_gmt['status'], 'Subscriptions evaluate_pay_response accepts gmt_payment settlement proof' );

$eval_wait = WC_Alipay_Subscriptions_Charge::evaluate_pay_response( (object) array( 'code' => '10000', 'out_trade_no' => 'WOOAS101-1', 'trade_no' => '202611220001', 'trade_status' => 'WAIT_BUYER_PAY' ), 'WOOAS101-1' );
$expect( is_array( $eval_wait ) && false === $eval_wait['success'] && true === $eval_wait['pending'], 'Subscriptions evaluate_pay_response treats WAIT_BUYER_PAY as pending (never falsely paid)' );

$eval_10003 = WC_Alipay_Subscriptions_Charge::evaluate_pay_response( (object) array( 'code' => '10003', 'out_trade_no' => 'WOOAS101-1', 'trade_no' => '202611220001' ), 'WOOAS101-1' );
$expect( is_array( $eval_10003 ) && false === $eval_10003['success'] && true === $eval_10003['pending'], 'Subscriptions evaluate_pay_response treats code=10003 as pending' );

$eval_bare = WC_Alipay_Subscriptions_Charge::evaluate_pay_response( (object) array( 'code' => '10000', 'out_trade_no' => 'WOOAS101-1', 'trade_no' => '202611220001' ), 'WOOAS101-1' );
$expect( is_array( $eval_bare ) && ! empty( $eval_bare['needs_query'] ) && false === $eval_bare['success'], 'Subscriptions evaluate_pay_response refuses bare code=10000 without settlement proof (requires query)' );

$eval_mismatch = WC_Alipay_Subscriptions_Charge::evaluate_pay_response( (object) array( 'code' => '10000', 'out_trade_no' => 'OTHER-ORDER', 'trade_no' => '202611220001', 'trade_status' => 'TRADE_SUCCESS' ), 'WOOAS101-1' );
$expect( is_wp_error( $eval_mismatch ) && 'charge_context_mismatch' === $eval_mismatch->get_error_code(), 'Subscriptions evaluate_pay_response rejects mismatched out_trade_no' );

// Runtime charge_with_agreement() verifies AlipayTradePayRequest is used (NOT AlipayTradeCreateRequest)
$sub_order = new WC_Order();
$sub_order->id = 205;
$sub_order->meta['_alipay_out_trade_no_withhold'] = 'WOOAS205-FIXED';
$mock_sub_aop = new class {
	public $last_class = '';
	public $last_biz   = array();
	public function execute( $req ) {
		$this->last_class = get_class( $req );
		$this->last_biz   = json_decode( $req->getBizContent(), true );
		$resp             = new stdClass();
		$resp->alipay_trade_pay_response = (object) array(
			'code'         => '10000',
			'out_trade_no' => 'WOOAS205-FIXED',
			'trade_no'     => '202611229999',
			'trade_status' => 'TRADE_SUCCESS',
		);
		return $resp;
	}
};
$charge_res = WC_Alipay_Subscriptions_Charge::charge_with_agreement( $sub_order, 49.00, 'AGREEMENT-123', 'GENERAL_WITHHOLDING', $mock_sub_aop );
$expect( 'AlipayTradePayRequest' === $mock_sub_aop->last_class && is_array( $charge_res ) && true === $charge_res['success'], 'Subscriptions charge_with_agreement() sends AlipayTradePayRequest and returns paid on TRADE_SUCCESS (P1-5)' );
$expect( 'AGREEMENT-123' === ( $mock_sub_aop->last_biz['agreement_params']['agreement_no'] ?? '' ), 'Subscriptions charge_with_agreement() binds agreement_params.agreement_no' );

// =========================================================================
// 4. Static contracts: Fund Auth, Cross-Border, Hook Dedupe, Feature Gates
// =========================================================================
$fund_auth = file_get_contents( $root . '/inc/modules/fund-auth/inc/class-wc-alipay-fund-auth.php' );
$expect( false === strpos( $fund_auth, 'SIM-AUTH-' ), 'Fund Auth removes fake SIM-AUTH-* local sandbox simulation' );
$expect( false !== strpos( $fund_auth, 'AlipayTradePayRequest' ) && false === strpos( $fund_auth, 'AlipayFundAuthOrderVoucherCreateRequest' ), 'Fund Auth capture_funds() uses AlipayTradePayRequest instead of VoucherCreate' );
$expect( false !== strpos( $fund_auth, 'woocommerce_api_wc_alipay_fund_auth' ) && false !== strpos( $fund_auth, 'Alipay_SDK_Helper::verify_notify' ), 'Fund Auth registers signed woocommerce_api_wc_alipay_fund_auth callback' );
$expect( false !== strpos( $fund_auth, 'wc_back_link' ), 'Fund Auth admin_options includes wc_back_link()' );

$acbop = file_get_contents( $root . '/inc/modules/cross-border/includes/class-wc-acbop.php' );
$expect( false !== strpos( $acbop, "'refunds'" ) && false !== strpos( $acbop, 'function process_refund(' ) && false !== strpos( $acbop, '_alipay_processed_refund_requests' ), 'Cross-Border implements idempotent process_refund() and declares refunds support' );
$expect( false !== strpos( $acbop, 'wc_back_link' ), 'Cross-Border admin_options includes wc_back_link()' );

$core_gw = file_get_contents( $root . '/inc/class-wc-alipay.php' );
$expect( false !== strpos( $core_gw, '$dedicated_subs_active' ) && false !== strpos( $core_gw, '! $dedicated_subs_active' ), 'Core gateway deduplicates woocommerce_scheduled_subscription_payment_alipay when subscriptions module is active' );

$charge_src = file_get_contents( $root . '/inc/modules/subscriptions/inc/class-wc-alipay-subscriptions-charge.php' );
$expect( false === strpos( $charge_src, 'AlipayTradeCreateRequest' ) && false === strpos( $charge_src, '->charset' ), 'Subscriptions charge removes AlipayTradeCreateRequest and deprecated $aop->charset' );

// Feature gate safety: all 4 Phase-2 modules remain unavailable until live sandbox acceptance
require_once $root . '/inc/class-wei-alipay-features.php';
$reg = Wei_Alipay_Features::registry();
foreach ( array( 'cross_border', 'fund_auth', 'profitsharing', 'subscriptions' ) as $slug ) {
	$expect( isset( $reg[ $slug ] ) && 'unavailable' === ( $reg[ $slug ]['gate'] ?? '' ), "Phase-2 module {$slug} remains gated as unavailable until live sandbox acceptance" );
}

exit( $failures ? 1 : 0 );
