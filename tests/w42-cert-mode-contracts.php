<?php
/**
 * Offline X.509 RSA2048 certificate mode tests for Wei Alipay 4.2 M1.
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
if ( ! defined( 'WEI_ALIPAY_PLUGIN_PATH' ) ) {
	define( 'WEI_ALIPAY_PLUGIN_PATH', $root . '/' );
}

function w42c_assert( bool $cond, string $msg ): void {
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL: {$msg}\n" );
		exit( 1 );
	}
	echo "PASS: {$msg}\n";
}

function w42c_make_cert( string $cn, int $days = 90 ): array {
	$key = openssl_pkey_new( array(
		'private_key_bits' => 2048,
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
	) );
	if ( false === $key ) {
		throw new RuntimeException( 'openssl_pkey_new failed' );
	}
	openssl_pkey_export( $key, $priv_pem );
	$csr = openssl_csr_new( array( 'commonName' => $cn, 'organizationName' => 'WeiAlipayTest' ), $key, array( 'digest_alg' => 'sha256' ) );
	$x509 = openssl_csr_sign( $csr, null, $key, $days, array( 'digest_alg' => 'sha256' ), random_int( 100000, 999999999 ) );
	openssl_x509_export( $x509, $cert_pem );
	$priv_body = trim( str_replace( array( '-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----', '-----BEGIN RSA PRIVATE KEY-----', '-----END RSA PRIVATE KEY-----', "\r", "\n" ), '', $priv_pem ) );
	return array(
		'priv_pem'  => $priv_pem,
		'priv_body' => $priv_body,
		'cert_pem'  => $cert_pem,
	);
}

require_once $root . '/inc/class-alipay-sdk-helper.php';
require_once $root . '/lib/alipay/aop/request/AlipayTradePagePayRequest.php';

$root_ca = w42c_make_cert( 'WeiAlipayTestRootCA', 365 );
$app_c   = w42c_make_cert( 'WeiAlipayTestApp', 120 );
$ali_c   = w42c_make_cert( 'WeiAlipayTestAlipay', 90 );

// 1. Default public_key mode still instantiates AopClient.
$pk_cfg = Alipay_SDK_Helper::get_alipay_config( array(
	'appid'       => '2026000000000001',
	'private_key' => $app_c['priv_body'],
	'public_key'  => Alipay_SDK_Helper::extract_cert_public_key( $ali_c['cert_pem'] ),
	'sandbox'     => 'yes',
) );
$aop_pk = Alipay_SDK_Helper::create_alipay_service( $pk_cfg );
w42c_assert( $aop_pk instanceof AopClient && ! ( $aop_pk instanceof AopCertClient ), 'default public_key mode instantiates AopClient' );

// 2. Certificate mode instantiates AopCertClient with valid SNs & signs form with app_cert_sn + alipay_root_cert_sn.
$cert_settings = array(
	'appid'                => '2026000000000001',
	'private_key'          => $app_c['priv_body'],
	'sign_credential_mode' => 'cert',
	'app_public_cert'      => $app_c['cert_pem'],
	'alipay_public_cert'   => $ali_c['cert_pem'],
	'alipay_root_cert'     => $root_ca['cert_pem'],
	'sandbox'              => 'yes',
);
w42c_assert( Alipay_SDK_Helper::has_valid_credentials( $cert_settings ), 'has_valid_credentials() accepts complete cert mode settings without public_key' );

$cert_cfg = Alipay_SDK_Helper::get_alipay_config( $cert_settings );
w42c_assert( 'cert' === $cert_cfg['sign_credential_mode'], 'get_alipay_config() resolves cert mode' );
w42c_assert( '' !== (string) $cert_cfg['alipay_public_key'], 'get_alipay_config() extracts alipay_public_key from PEM certificate' );

$aop_cert = Alipay_SDK_Helper::create_alipay_service( $cert_cfg );
w42c_assert( $aop_cert instanceof AopCertClient, 'cert mode instantiates AopCertClient' );
w42c_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', (string) $aop_cert->appCertSN ), 'appCertSN extracted as 32-char MD5 hex' );
w42c_assert( '' !== (string) $aop_cert->alipayRootCertSN, 'alipayRootCertSN extracted from root PEM certificate' );

$req = new AlipayTradePagePayRequest();
$req->setNotifyUrl( 'https://shop.example.test/wc-api/WC_Alipay/' );
$req->setReturnUrl( 'https://shop.example.test/checkout/order-received/1/' );
$req->setBizContent( json_encode( array(
	'out_trade_no' => 'WooA1-101-1700000000',
	'total_amount' => '19.90',
	'subject'      => 'Cert Mode Order',
	'product_code' => 'FAST_INSTANT_TRADE_PAY',
) ) );
$html = (string) $aop_cert->pageExecute( $req );
w42c_assert( false !== strpos( $html, "name='app_cert_sn'" ) || false !== strpos( $html, 'name="app_cert_sn"' ), 'cert mode signed checkout form includes app_cert_sn' );
w42c_assert( false !== strpos( $html, "name='alipay_root_cert_sn'" ) || false !== strpos( $html, 'name="alipay_root_cert_sn"' ), 'cert mode signed checkout form includes alipay_root_cert_sn' );
w42c_assert( false !== strpos( $html, "name='sign'" ) || false !== strpos( $html, 'name="sign"' ), 'cert mode signed checkout form includes RSA2 sign' );

// 3. verify_notify() accepts PEM certificate directly & rejects tampered payload.
$notify = array(
	'app_id'       => '2026000000000001',
	'out_trade_no' => 'WooA1-101-1700000000',
	'trade_no'     => '2026112222001400000001',
	'trade_status' => 'TRADE_SUCCESS',
	'total_amount' => '19.90',
	'sign_type'    => 'RSA2',
);
ksort( $notify );
$sign_pairs = array();
foreach ( $notify as $k => $v ) {
	if ( 'sign' !== $k && 'sign_type' !== $k && '' !== (string) $v ) {
		$sign_pairs[] = $k . '=' . $v;
	}
}
$sign_str = implode( '&', $sign_pairs );
openssl_sign( $sign_str, $raw_sig, $ali_c['priv_pem'], OPENSSL_ALGO_SHA256 );
$notify['sign'] = base64_encode( $raw_sig );

w42c_assert( true === Alipay_SDK_Helper::verify_notify( $notify, $ali_c['cert_pem'] ), 'verify_notify() verifies RSA2 callback using Alipay PEM certificate directly' );
$tampered = $notify;
$tampered['total_amount'] = '0.01';
w42c_assert( false === Alipay_SDK_Helper::verify_notify( $tampered, $ali_c['cert_pem'] ), 'verify_notify() rejects tampered callback in cert mode' );

// 4. cert_health() expiry thresholds (ok >30d, warn <=30d, bad <=7d / expired / invalid).
$now = time();
$h_ok = Alipay_SDK_Helper::cert_health( $cert_settings, $now );
w42c_assert( 'ok' === $h_ok['status'] && 'CERT · RSA2' === $h_ok['label'], 'cert_health() reports ok when >30 days remain' );

$h_warn = Alipay_SDK_Helper::cert_health( $cert_settings, $now + ( 70 * 86400 ) );
w42c_assert( 'warn' === $h_warn['status'], 'cert_health() reports warn when <=30 days remain' );

$h_bad = Alipay_SDK_Helper::cert_health( $cert_settings, $now + ( 85 * 86400 ) );
w42c_assert( 'bad' === $h_bad['status'], 'cert_health() reports bad when <=7 days remain' );

$bad_pem_settings = $cert_settings;
$bad_pem_settings['app_public_cert'] = 'not-a-valid-pem';
$h_invalid = Alipay_SDK_Helper::cert_health( $bad_pem_settings, $now );
w42c_assert( 'bad' === $h_invalid['status'], 'cert_health() reports bad on invalid PEM' );
w42c_assert( null === Alipay_SDK_Helper::create_alipay_service( Alipay_SDK_Helper::get_alipay_config( $bad_pem_settings ) ), 'create_alipay_service() fails closed on invalid PEM' );

echo "[PASS] 4.2 M1 Public Key Certificate Mode offline crypto & contract suite\n";
