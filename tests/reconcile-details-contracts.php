<?php
$root = dirname( __DIR__ );
$runner = file_get_contents( $root . '/inc/modules/reconcile/inc/class-wei-alipay-reconcile-runner.php' );
$admin = file_get_contents( $root . '/inc/modules/reconcile/inc/class-wei-alipay-reconcile-admin.php' );
$bootstrap = file_get_contents( $root . '/inc/modules/reconcile/bootstrap.php' );

$assertions = 0;
$assert = static function ( $condition, $message ) use ( &$assertions ) {
    $assertions++;
    if ( ! $condition ) {
        fwrite( STDERR, "[FAIL] {$message}\n" );
        exit( 1 );
    }
};

$assert( false !== strpos( $runner, "'details'" ), 'runner returns bounded row details' );
$assert( false !== strpos( $runner, '$detail_limit = 200;' ), 'row details have a hard display limit' );
$assert( false !== strpos( $runner, "'different_site'" ), 'multisite rows have an explicit reason' );
$assert( false !== strpos( $runner, "'merchant_order_number_not_bound'" ), 'unbound merchant numbers have an explicit reason' );
$assert( false !== strpos( $runner, 'trade_no_masked' ), 'provider trade numbers are masked before result storage' );
$assert( false !== strpos( $runner, "'time_candidates'" ) && false !== strpos( $runner, "'status_candidates'" ), 'statement time and status are extracted when present' );
$assert( false === strpos( $runner, 'payment_complete(' ) && false === strpos( $runner, 'update_status(' ) && false === strpos( $runner, 'refund' ), 'reconciliation does not mutate financial order state' );
$assert( false !== strpos( $admin, 'wp-list-table widefat striped wei-alipay-reconcile-table' ), 'details use the native WordPress table pattern' );
$assert( false !== strpos( $admin, '<details open class="wei-alipay-reconcile-results">' ), 'exceptions are disclosed by default' );
$assert( false !== strpos( $admin, '<details class="wei-alipay-reconcile-results">' ), 'matched rows are progressively disclosed' );
$assert( false !== strpos( $admin, "current_user_can( 'edit_shop_order', \$order_id )" ), 'order links require order edit capability' );
$assert( false !== strpos( $admin, "'woo_alipay_reconcile_last_result_' . \$user_id" ), 'results are scoped to the current administrator' );
$assert( 3 === substr_count( $bootstrap, 'Wei_Alipay_Reconcile_Admin::result_transient_key()' ), 'all manual result writes use the scoped transient key' );
$assert( false === strpos( $admin, "['file_url']" ) && false === strpos( $runner, "'file_url'" ), 'no public statement file URL is exposed' );

printf( "[PASS] reconciliation detail contracts: %d assertions\n", $assertions );
