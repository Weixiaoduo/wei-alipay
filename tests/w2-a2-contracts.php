<?php
/**
 * W2-A2 regression contracts (P1-8, P1-9, P1-10, P1-11, P1-12, uninstall).
 */

$root     = dirname( __DIR__ );
$failures = 0;

$check = static function( $cond, $label ) use ( &$failures ) {
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		++$failures;
		return;
	}
	echo "PASS: {$label}\n";
};

$barcode = file_get_contents( $root . '/inc/modules/barcode/inc/class-wc-alipay-barcode.php' );
$check( false !== strpos( $barcode, "'refunds'" ) && false !== strpos( $barcode, 'function process_refund(' ), 'Barcode gateway supports idempotent refunds (P1-8)' );
$check( false !== strpos( $barcode, "wc_get_page_screen_id( 'shop-order' )" ), 'Barcode POS metabox registers for HPOS screen (P1-8)' );
$check( false !== strpos( $barcode, "delete_meta_data( '_alipay_auth_code' )" ), 'Barcode erases buyer auth_code after charge attempt (P1-8)' );

$webhook = file_get_contents( $root . '/inc/class-wc-alipay-webhook-retry.php' );
$ps_boot = file_get_contents( $root . '/inc/modules/profitsharing/bootstrap.php' );
$ps_jobs = file_get_contents( $root . '/inc/modules/profitsharing/inc/class-wei-alipay-ps-jobs-admin.php' );
$check( false === strpos( $webhook, 'post.php?post=' ), 'Webhook retry uses HPOS get_edit_order_url (P1-9)' );
$check( false === strpos( $ps_boot, 'get_edit_post_link' ) && false === strpos( $ps_jobs, 'get_edit_post_link' ), 'Profitsharing uses HPOS get_edit_order_url (P1-9)' );

foreach ( array(
	'inc/class-wc-alipay-blocks-support.php',
	'inc/modules/f2f/inc/class-wc-alipay-facetopay-blocks-support.php',
	'inc/modules/huabei/inc/class-wc-alipay-installment-blocks-support.php',
	'inc/modules/barcode/inc/class-wc-alipay-barcode-blocks-support.php',
	'inc/modules/fund-auth/inc/class-wc-alipay-fund-auth-blocks-support.php',
) as $bf ) {
	$src = file_get_contents( $root . '/' . $bf );
	$check( false !== strpos( $src, '->is_available()' ), basename( $bf ) . ' delegates is_active() to gateway is_available() (P1-10)' );
}

$timeout = file_get_contents( $root . '/inc/class-wc-alipay-order-timeout.php' );
$check( false !== strpos( $timeout, 'woocommerce_store_api_checkout_order_processed' ), 'Order timeout covers WooCommerce Blocks Store API checkout (P1-10)' );

$rec_runner = file_get_contents( $root . '/inc/modules/reconcile/inc/class-wei-alipay-reconcile-runner.php' );
$rec_admin  = file_get_contents( $root . '/inc/modules/reconcile/inc/class-wei-alipay-reconcile-admin.php' );
$check( false !== strpos( $rec_runner, 'WooA|F2F|HBFQ|WooB' ), 'Reconcile runner recognizes F2F, HBFQ, and WooB prefixes (P1-11)' );
$check( false === strpos( $rec_admin, 'Schedule and retention' ), 'Reconcile hides empty schedule tab in 4.1 (P1-11)' );

$check( false === strpos( $webhook, "add_action( 'woocommerce_api_wc_alipay', array( \$this, 'log_webhook_attempt' ), 5 )" ), 'Webhook retry no longer writes unverified POSTs before signature check (P1-12)' );
$check( false !== strpos( $webhook, "'invalid_response_signature'" ) && false !== strpos( $webhook, 'woo_alipay_cleanup_webhook_logs' ), 'Webhook retry verifies before write and schedules daily retention cleanup (P1-12)' );

$uninstall = file_get_contents( $root . '/uninstall.php' );
$check( false !== strpos( $uninstall, 'woo_alipay_webhook_logs' ) && false !== strpos( $uninstall, 'wei_alipay_features' ), 'Explicit uninstall purges 4.1 options and webhook log table' );

exit( $failures ? 1 : 0 );
