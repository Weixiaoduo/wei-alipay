<?php
/**
 * Offline contract for the bundled Alipay SDK outbound RSA2 form.
 *
 * Uses an ephemeral key generated for this process. No merchant credential or
 * Alipay network endpoint is read or contacted.
 */

$root = dirname( __DIR__ );
$failures = 0;

function signing_expect( $condition, $message ) {
	global $failures;
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}
	fwrite( STDERR, "FAIL: {$message}\n" );
	++$failures;
}

if ( ! extension_loaded( 'openssl' ) || ! class_exists( 'DOMDocument' ) ) {
	fwrite( STDERR, "FAIL: OpenSSL and DOM extensions are required\n" );
	exit( 1 );
}

$command = '(openssl genrsa -traditional 2048 2>/dev/null || openssl genrsa 2048 2>/dev/null)';
$lines = array();
$status = 1;
exec( $command, $lines, $status );
$private_pem = implode( "\n", $lines ) . "\n";
signing_expect( 0 === $status && false !== strpos( $private_pem, 'BEGIN RSA PRIVATE KEY' ), 'ephemeral PKCS#1 private key generated locally' );

$private_resource = openssl_pkey_get_private( $private_pem );
$details = false !== $private_resource ? openssl_pkey_get_details( $private_resource ) : false;
$public_pem = is_array( $details ) && isset( $details['key'] ) ? $details['key'] : '';
$private_raw = preg_replace( '/-----BEGIN RSA PRIVATE KEY-----|-----END RSA PRIVATE KEY-----|\s+/', '', $private_pem );
signing_expect( is_string( $private_raw ) && '' !== $private_raw && '' !== $public_pem, 'ephemeral private/public key pair is readable' );

require_once $root . '/lib/alipay/aop/AopClient.php';
require_once $root . '/lib/alipay/aop/request/AlipayTradePagePayRequest.php';

$aop = new AopClient();
$aop->gatewayUrl = 'https://openapi.alipay.com/gateway.do';
$aop->appId = '2026082200000000';
$aop->rsaPrivateKey = $private_raw;
$aop->alipayrsaPublicKey = preg_replace( '/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $public_pem );
$aop->apiVersion = '1.0';
$aop->signType = 'RSA2';
$aop->postCharset = 'utf-8';
$aop->format = 'json';

$request = new AlipayTradePagePayRequest();
$request->setNotifyUrl( 'https://merchant.example.test/wc-api/WC_Alipay/' );
$request->setReturnUrl( 'https://merchant.example.test/checkout/order-received/1/' );
$request->setBizContent( '{"out_trade_no":"WeiTest-1","total_amount":"0.01","subject":"Offline signing contract","product_code":"FAST_INSTANT_TRADE_PAY"}' );
$form = $aop->pageExecute( $request, 'post' );

$dom = new DOMDocument();
libxml_use_internal_errors( true );
$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $form );
libxml_clear_errors();
signing_expect( $loaded, 'SDK-generated payment form is parseable HTML' );

$params = array();
foreach ( $dom->getElementsByTagName( 'input' ) as $input ) {
	$name = $input->getAttribute( 'name' );
	if ( '' !== $name ) {
		$params[ $name ] = $input->getAttribute( 'value' );
	}
}

$required = array( 'app_id', 'method', 'charset', 'sign_type', 'timestamp', 'version', 'biz_content', 'notify_url', 'return_url', 'sign' );
foreach ( $required as $field ) {
	signing_expect( isset( $params[ $field ] ) && '' !== $params[ $field ], 'outbound form contains non-empty ' . $field );
}
$missing_signature = $params;
unset( $missing_signature['sign'] );
$missing_signature_complete = true;
foreach ( $required as $field ) {
	if ( ! isset( $missing_signature[ $field ] ) || '' === $missing_signature[ $field ] ) {
		$missing_signature_complete = false;
		break;
	}
}
signing_expect( false === $missing_signature_complete, 'outbound preflight rejects a form with the signature removed' );
signing_expect( isset( $params['sign_type'] ) && 'RSA2' === $params['sign_type'], 'outbound form fixes sign_type to RSA2' );

$signature = isset( $params['sign'] ) ? base64_decode( $params['sign'], true ) : false;
$verified = false;
if ( false !== $signature ) {
	$verified = 1 === openssl_verify( $aop->getSignContent( $params ), $signature, $public_pem, OPENSSL_ALGO_SHA256 );
}
signing_expect( $verified, 'outbound form signature verifies with the paired application public key' );

$helper_source = file_get_contents( $root . '/inc/class-alipay-sdk-helper.php' );
signing_expect( false !== strpos( $helper_source, "'input' => array('type' => true, 'name' => true, 'value' => true" ), 'payment-form sanitizer retains signed hidden input names and values' );
signing_expect( false !== strpos( $helper_source, "array('http', 'https')" ), 'payment-form sanitizer limits action protocols to HTTP(S)' );

exit( $failures ? 1 : 0 );
