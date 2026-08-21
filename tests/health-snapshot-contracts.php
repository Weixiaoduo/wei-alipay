<?php

$root = dirname( __DIR__ );
$loader = file_get_contents( $root . '/wei-alipay.php' );
$health = file_get_contents( $root . '/inc/class-wei-alipay-health.php' );
$features = file_get_contents( $root . '/inc/class-wei-alipay-features.php' );
$secrets = file_get_contents( $root . '/inc/class-wei-alipay-secret-store.php' );

$expect = static function( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
};

$expect( false !== strpos( $loader, "require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wei-alipay-health.php'" ), 'the local health recorder is loaded before the overview renders' );
$expect( false !== strpos( $loader, 'Wei_Alipay_Health::register()' ), 'the local health recorder registers its event observers' );
$expect( false !== strpos( $health, "add_action( 'woo_alipay_webhook_processed'" ), 'callback outcomes update the local health snapshot' );
$expect( false !== strpos( $health, "add_action( 'woocommerce_payment_complete'" ), 'successful payments feed the seven-day counters' );
$expect( false !== strpos( $health, "add_action( 'woocommerce_order_status_failed'" ), 'failed payment orders feed the seven-day counters' );
$expect( false !== strpos( $health, "add_action( 'woocommerce_refund_created'" ), 'refund creation feeds the seven-day counters' );
$expect( false !== strpos( $health, "add_action( 'woo_alipay_check_pending_orders'" ), 'pending-order job completion is observed' );
$expect( false !== strpos( $health, "add_action( 'woo_alipay_check_timeout_orders'" ), 'timeout job completion is observed' );
$expect( false !== strpos( $health, "const RETENTION_DAYS = 8" ), 'health event hashes have a bounded retention window' );
$expect( false !== strpos( $health, "array_slice( \$snapshot['activity']['seen'], -500, 500, true )" ), 'opaque activity deduplication keys are bounded' );
$expect( false !== strpos( $health, "hash_hmac( 'sha256'" ), 'event identifiers are stored only as keyed hashes' );
$expect( false === strpos( $health, 'wc_get_orders(' ), 'health recording never scans the order table' );
$expect( false === strpos( $health, '$wpdb' ), 'health recording uses bounded options rather than direct SQL' );
$expect( false === strpos( $health, 'get_total(' ) && false === strpos( $health, 'billing_' ), 'health counters do not read amounts or customer fields' );
$expect( false !== strpos( $secrets, 'public static function main_secret_health()' ), 'the overview receives a presentation-safe private-key health result' );
$expect( false !== strpos( $secrets, "array( 'status' => 'unreadable', 'protection' => 'encrypted' )" ), 'unreadable encrypted keys fail closed in health state' );
$expect( false !== strpos( $features, 'Wei_Alipay_Health::snapshot()' ), 'the overview reads the local reliability snapshot' );
$expect( false !== strpos( $features, 'Wei_Alipay_Health::activity_summary()' ), 'the overview reads bounded seven-day counters' );
$expect( false !== strpos( $features, "array_slice( \$attention, 0, 3 )" ), 'the needs-attention list is capped at three items' );
$expect( false !== strpos( $features, "get_site_transient( 'update_plugins' )" ), 'update state comes from the local WordPress cache' );
$expect( false !== strpos( $features, "'scope_summary' =>" ) && false !== strpos( $features, "'offline_grace_label' =>" ), 'account state supports scopes, expiry and offline grace' );
$expect( false !== strpos( $features, 'no amounts or customer data are stored' ), 'the seven-day module states its local privacy boundary' );
