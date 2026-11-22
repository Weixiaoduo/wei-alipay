<?php

$root = dirname( __DIR__ );

$expect = static function( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
    echo "PASS: {$message}\n";
};

$core = file_get_contents( $root . '/inc/class-wei-alipay.php' );
$expect( 1 === substr_count( $core, 'add_menu_page(' ), 'one dedicated Wei Alipay top-level menu is registered' );
$expect( false !== strpos( $core, "'wei-alipay-reconcile'" ), 'fallback reconciliation route is unique' );

$routes = array(
    'inc/class-wc-alipay-webhook-retry.php' => 'wei-alipay-webhooks',
    'inc/modules/profitsharing/inc/class-wei-alipay-ps-admin.php' => 'wei-alipay-profitsharing',
    'inc/modules/profitsharing/inc/class-wei-alipay-ps-jobs-admin.php' => 'wei-alipay-ps-jobs',
    'inc/modules/profitsharing/inc/class-wei-alipay-ps-comp-admin.php' => 'wei-alipay-ps-compensation',
    'inc/modules/reconcile/inc/class-wei-alipay-reconcile-admin.php' => 'wei-alipay-reconcile-pro',
    'inc/modules/subscriptions/inc/class-wc-alipay-subscriptions-admin.php' => 'wei-alipay-subscriptions',
);

foreach ( $routes as $relative => $slug ) {
    $source = file_get_contents( $root . '/' . $relative );
    $expect( false !== strpos( $source, "'wei-alipay'," ), $relative . ' is grouped under the Wei Alipay menu' );
    $expect( false !== strpos( $source, "'{$slug}'," ), $relative . ' uses route ' . $slug );
    $expect( 0 === preg_match( "/add_submenu_page\(\s*'woocommerce'/", $source ), $relative . ' does not pollute the WooCommerce submenu' );
}

$expect( count( array_unique( array_values( $routes ) ) ) === count( $routes ), 'all operational pages have unique routes' );
