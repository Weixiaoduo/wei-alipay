<?php

$root = dirname( __DIR__ );

$expect = static function( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
};

$loader = file_get_contents( $root . '/wei-alipay.php' );
$features = file_get_contents( $root . '/inc/class-wei-alipay-features.php' );
$admin = file_get_contents( $root . '/inc/class-wei-alipay.php' );
$css = file_get_contents( $root . '/css/admin/features.css' );
$shared_css = file_get_contents( $root . '/css/admin/design-system.css' );
$js = file_get_contents( $root . '/js/admin/features.js' );

$gates = array(
	'profitsharing', 'fund_auth', 'reconcile', 'barcode', 'f2f', 'huabei',
	'subscriptions', 'mini_program', 'cross_border', 'webhook_retry',
);
foreach ( $gates as $key ) {
	$expect( false !== strpos( $loader, "Wei_Alipay_Features::is_enabled( '{$key}' )" ), "{$key} runtime is explicitly gated" );
}

$expect( false === strpos( $loader, "require_once WEI_ALIPAY_PLUGIN_PATH . 'enable-gateways.php'" ), 'legacy bulk-enable endpoint is not loaded' );
$expect( false !== strpos( $features, "const OPTION_KEY = 'wei_alipay_features'" ), 'feature allow-list uses a stable dedicated option' );
$expect( false !== strpos( $features, "check_admin_referer( 'wei_alipay_save_features' )" ), 'feature save requires a nonce' );
$expect( false !== strpos( $features, "current_user_can( 'manage_woocommerce' )" ), 'feature read and save require WooCommerce management permission' );
$expect( false !== strpos( $features, "map_deep( wp_unslash( \$_POST['features'] ), 'sanitize_text_field' )" ), 'posted feature keys are unslashed and deeply sanitized' );
$expect( false !== strpos( $features, "sanitize_key( (string) \$posted_feature )" ), 'posted feature keys are scalar and sanitized' );
$expect( false === strpos( $features, 'delete_option(' ), 'feature disable never deletes retained configuration' );
$expect( false !== strpos( $features, "'payment' === \$feature['group']" ), 'migration only enables payment modules from explicit gateway state' );
$expect( false !== strpos( $features, "isset( \$settings['enabled'] ) && 'yes' === \$settings['enabled']" ), 'existing enabled gateways migrate without being disabled' );
$expect( false !== strpos( $features, "'toplevel_page_wei-alipay'" ), 'feature assets are scoped to the independent settings page' );
$expect( false !== strpos( $admin, "Wei_Alipay_Features::render()" ), 'independent Wei Alipay page renders the feature centre' );
$expect( false !== strpos( $admin, "Wei_Alipay_Features::is_enabled( 'reconcile' )" ), 'fallback reconciliation menu is conditional' );
$expect( false !== strpos( $admin, 'Wei_Alipay_Features::maybe_migrate()' ), 'multisite activation migrates the feature allow-list per site' );

foreach ( array( '--wei-space-2', '--wei-color-accent', 'prefers-reduced-motion', '@media (max-width: 782px)' ) as $contract ) {
	$expect( false !== strpos( $shared_css, $contract ), "shared admin CSS includes {$contract}" );
}
$expect( false !== strpos( $js, "beforeunload" ), 'unsaved feature changes have a navigation guard' );
$expect( false !== strpos( $js, "submit.disabled = false" ), 'save action is enabled only after a change' );

$tokens = json_decode( file_get_contents( $root . '/docs/design/woopayments-admin-tokens.json' ), true );
$expect( is_array( $tokens ) && 4 === count( $tokens['source']['artifacts'] ), 'design inventory records all four scoped WooPayments admin artifacts' );
$expect( isset( $tokens['raw_inventory']['colors'][0]['occurrences'] ), 'design inventory includes deterministic raw token counts' );
