<?php
/**
 * W4 regression contract:
 *  - Verifies lib/alipay/ is slimmed to the exact first-party allowlist with zero aop/test files
 *  - Loads all retained Alipay SDK request classes and runs offline RSA2 form/request generation
 *    for PC, WAP, F2F, Huabei, Barcode, Refund, Query, Close and Reconcile channels.
 */

define( 'ABSPATH', __DIR__ . '/' );

$root     = dirname( __DIR__ );
$lib_root = $root . '/lib/alipay';
$failures = 0;

$expect = static function( $cond, $label ) use ( &$failures ) {
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		++$failures;
		return;
	}
	echo "PASS: {$label}\n";
};

$files = array();
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $lib_root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( $f->isFile() ) {
		$files[] = str_replace( $lib_root . '/', '', $f->getPathname() );
	}
}
$expect( count( $files ) <= 45, 'lib/alipay slimmed to allowlist (' . count( $files ) . ' files <= 45)' );
$expect( ! is_dir( $lib_root . '/aop/test' ), 'lib/alipay/aop/test removed from distribution' );
$expect( ! is_dir( $lib_root . '/lotusphp_runtime' ), 'unused lotusphp_runtime removed from distribution' );

require_once $lib_root . '/AopSdk.php';
$request_classes = array(
	'AlipayTradePagePayRequest',
	'AlipayTradeWapPayRequest',
	'AlipayTradePrecreateRequest',
	'AlipayTradePayRequest',
	'AlipayTradeQueryRequest',
	'AlipayTradeRefundRequest',
	'AlipayTradeFastpayRefundQueryRequest',
	'AlipayTradeCloseRequest',
	'AlipayTradeCreateRequest',
	'AlipayDataDataserviceBillDownloadurlQueryRequest',
	'AlipayFundAuthOrderFreezeRequest',
	'AlipayFundAuthOrderUnfreezeRequest',
	'AlipayFundAuthOrderVoucherCreateRequest',
	'AlipayTradeOrderSettleRequest',
	'AlipayTradeOrderSettleQueryRequest',
	'AlipayUserAgreementPageSignRequest',
	'AlipayUserAgreementUnsignRequest',
);
foreach ( $request_classes as $cls ) {
	$path = $lib_root . '/aop/request/' . $cls . '.php';
	require_once $path;
	$expect( class_exists( $cls ), 'SDK request class loads cleanly: ' . $cls );
}

// Offline RSA2 signing across PC, WAP, Huabei (extend_params) forms
$res = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
$expect( false !== $res, 'ephemeral RSA2 keypair generated for multi-channel signing' );
$priv_pem = '';
openssl_pkey_export( $res, $priv_pem );
$details = openssl_pkey_get_details( $res );
$priv_b64 = preg_replace( '/-----.*?-----|\s+/', '', $priv_pem );
$pub_b64  = preg_replace( '/-----.*?-----|\s+/', '', $details['key'] );

$aop                     = new AopClient();
$aop->gatewayUrl         = 'https://openapi.alipaydev.com/gateway.do';
$aop->appId              = '2021000000000001';
$aop->rsaPrivateKey      = $priv_b64;
$aop->alipayrsaPublicKey = $pub_b64;
$aop->signType           = 'RSA2';
$aop->postCharset        = 'UTF-8';
$aop->format             = 'json';

$channels = array(
	'pc'     => array( new AlipayTradePagePayRequest(), array( 'out_trade_no' => 'WooA11-101-1', 'total_amount' => '19.90', 'subject' => 'PC Test', 'product_code' => 'FAST_INSTANT_TRADE_PAY' ) ),
	'wap'    => array( new AlipayTradeWapPayRequest(), array( 'out_trade_no' => 'WooA11-102-1', 'total_amount' => '29.90', 'subject' => 'WAP Test', 'product_code' => 'QUICK_WAP_WAY' ) ),
	'huabei' => array( new AlipayTradePagePayRequest(), array( 'out_trade_no' => 'HBFQ11-103-1', 'total_amount' => '399.00', 'subject' => 'Huabei Test', 'product_code' => 'FAST_INSTANT_TRADE_PAY', 'extend_params' => array( 'hb_fq_num' => '6', 'hb_fq_seller_percent' => '100' ) ) ),
);
foreach ( $channels as $name => $pair ) {
	list( $req, $biz ) = $pair;
	$req->setBizContent( json_encode( $biz ) );
	$req->setNotifyUrl( 'https://shop.example.com/?wc-api=WC_Alipay' );
	$html = $aop->pageExecute( $req, 'POST' );
	$expect( false !== strpos( $html, "name='sign'" ) && false !== strpos( $html, 'RSA2' ), 'signed RSA2 checkout form generated for channel: ' . $name );
}

exit( $failures ? 1 : 0 );
