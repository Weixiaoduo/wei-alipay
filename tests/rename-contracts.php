<?php

$root = dirname( __DIR__ );

$expect = static function( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}
" );
		exit( 1 );
	}
	echo "PASS: {$message}
";
};

$main = file_get_contents( $root . '/wei-alipay.php' );
$expect( false !== $main, 'renamed plugin entry file is readable' );
$expect( ! file_exists( $root . '/woo-alipay.php' ), 'legacy plugin entry file is absent from candidate' );
$expect( false !== strpos( $main, 'Plugin Name: Wei Alipay Payment Gateway for WooCommerce' ), 'plugin header uses approved product name' );
$expect( false !== strpos( $main, 'Version: 4.0.1' ), 'plugin header uses 4.0.1 candidate version' );
$expect( false !== strpos( $main, 'Text Domain: wei-alipay' ), 'plugin header uses renamed text domain' );
$expect( false !== strpos( $main, 'Author: Weixiaoduo.com' ), 'plugin header uses approved developer identity' );
$expect( false !== strpos( $main, "declare_compatibility( 'custom_order_tables'" ), 'HPOS compatibility declaration remains present' );
$expect( false !== strpos( $main, "declare_compatibility( 'cart_checkout_blocks'" ), 'Checkout Blocks compatibility declaration remains present' );

$gateway = file_get_contents( $root . '/inc/class-wc-alipay.php' );
$expect( false !== strpos( $gateway, "const GATEWAY_ID = 'alipay';" ), 'provider gateway ID remains stable' );
$expect( false !== strpos( $gateway, "api_request_url('WC_Alipay')" ), 'Alipay callback route remains stable' );
$expect( false !== strpos( $gateway, "get_option('woocommerce_alipay_settings'" ), 'WooCommerce gateway settings identity remains stable' );

$f2f = file_get_contents( $root . '/inc/modules/f2f/inc/class-wc-alipay-facetopay.php' );
$expect( false !== strpos( $f2f, "js/qrcodejs/qrcode.min.js" ), 'face-to-face QR renderer is bundled locally' );
$expect( false === strpos( $f2f, 'cdn.jsdelivr.net/npm/qrcodejs' ), 'face-to-face checkout does not load remote QR JavaScript' );
$expect( file_exists( $root . '/inc/modules/f2f/js/qrcodejs/LICENSE' ), 'vendored QR renderer includes its MIT license' );

$secrets = file_get_contents( $root . '/inc/class-wei-alipay-secret-store.php' );
$expect( false !== strpos( $secrets, "const PREFIX          = 'woo-alipay-secret:v1:';" ), 'encrypted secret envelope remains decryptable' );
$expect( false !== strpos( $secrets, "hash_hmac( 'sha256', 'woo-alipay'" ), 'encrypted secret KDF context remains stable' );
$expect( false !== strpos( $secrets, "defined( 'WOO_ALIPAY_ENCRYPTION_KEY' )" ), 'mall key-name cutover fallback remains available' );

$workflow = file_get_contents( $root . '/.forgejo/workflows/sync-github-downstream.yml' );
$expect( false !== strpos( $workflow, "workflow_dispatch:" ), 'GitHub synchronization is manual only' );
$expect( false !== strpos( $workflow, 'board_change_id:' ), 'GitHub synchronization requires Board change identity' );

$mirror = file_get_contents( $root . '/scripts/sync-github-downstream.sh' );
$expect( false !== strpos( $mirror, 'merge-base --is-ancestor' ), 'GitHub synchronization enforces fast-forward ancestry' );
foreach ( preg_split( '/\R/', $mirror ) as $line ) {
	if ( false !== strpos( $line, 'git -C "$ROOT" push' ) ) {
		$expect( false === strpos( $line, '--force' ) && false === strpos( $line, '--mirror' ), 'GitHub push command is neither forced nor full-ref mirror' );
	}
}

$sbom = json_decode( file_get_contents( $root . '/docs/sbom/wei-alipay.spdx.json' ), true );
$expect( is_array( $sbom ) && 'wei-alipay-4.0.1-candidate' === $sbom['name'], 'SBOM uses renamed 4.0.1 candidate identity' );
