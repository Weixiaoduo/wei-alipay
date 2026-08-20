<?php

$root = dirname( __DIR__ );
$failures = 0;

function design_expect( $condition, $message ) {
	global $failures;
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}
	fwrite( STDERR, "FAIL: {$message}\n" );
	++$failures;
}

function design_source( $root, $relative ) {
	$content = file_get_contents( $root . '/' . $relative );
	if ( false === $content ) {
		throw new RuntimeException( 'Cannot read ' . $relative );
	}
	return $content;
}

$contract = design_source( $root, 'DESIGN.md' );
$css      = design_source( $root, 'css/admin/design-system.css' );
$script   = design_source( $root, 'js/admin/design-system.js' );
$loader   = design_source( $root, 'inc/class-wei-alipay-features.php' );

design_expect( false !== strpos( $contract, 'WordPress native first' ), 'DESIGN.md makes native WordPress components the default' );
foreach ( array( 'nav-tab-wrapper', 'notice', 'card', 'form-table', 'wp-list-table', 'tablenav', 'button-link-delete' ) as $native_class ) {
	design_expect( false !== strpos( $contract, $native_class ), 'DESIGN.md documents native ' . $native_class );
}
foreach ( array( '--wei-color-accent', '--wei-color-success-surface', '--wei-font-size-body', '--wei-space-8', '--wei-radius-card', '--wei-shadow-card', '--wei-motion-disclosure', '--wei-content-wide', '@media (max-width: 782px)', 'prefers-reduced-motion' ) as $token ) {
	design_expect( false !== strpos( $css, $token ), 'shared design CSS contains ' . $token );
}
design_expect( false === strpos( $css, '.wei-alipay-panel' ), 'shared CSS does not create a parallel panel component' );
design_expect( false === strpos( $css, '.wei-alipay-nav-tabs' ), 'shared CSS does not create parallel tabs' );
design_expect( false === strpos( $css, '.wei-alipay-callout' ), 'shared CSS does not create parallel notices' );
design_expect( false !== strpos( $script, "'[data-wei-confirm]'" ) && false !== strpos( $script, "'[data-wei-dirty]'" ), 'progressive enhancement covers confirmations and dirty forms' );
design_expect( false !== strpos( $loader, "'wei-alipay_page_wei-alipay-'" ), 'shared assets are scoped to Wei Alipay child pages' );

$pages = array(
	'inc/class-wc-alipay-webhook-retry.php',
	'inc/modules/profitsharing/inc/class-wei-alipay-ps-admin.php',
	'inc/modules/profitsharing/inc/class-wei-alipay-ps-jobs-admin.php',
	'inc/modules/profitsharing/inc/class-wei-alipay-ps-comp-admin.php',
	'inc/modules/reconcile/inc/class-wei-alipay-reconcile-admin.php',
	'inc/modules/subscriptions/inc/class-wc-alipay-subscriptions-admin.php',
);
foreach ( $pages as $relative ) {
	$page = design_source( $root, $relative );
	design_expect( false !== strpos( $page, 'wrap wei-alipay-admin' ), $relative . ' uses the shared native-first shell' );
}

$profit = design_source( $root, 'inc/modules/profitsharing/inc/class-wei-alipay-ps-admin.php' );
design_expect( false !== strpos( $profit, 'nav-tab-wrapper wei-alipay-tabs' ) && false !== strpos( $profit, 'nav-tab-active' ), 'profit sharing uses native URL-addressable tabs with the WooCommerce settings composition' );
design_expect( false !== strpos( $profit, "esc_attr__( 'Profit sharing sections'" ), 'profit sharing tab navigation has an accessible label' );
design_expect( false === strpos( $profit, 'wei-alipay-panel' ), 'profit sharing no longer uses custom panels' );
design_expect( false !== strpos( $profit, 'private function nonce_field' ), 'profit sharing avoids duplicate nonce DOM IDs on multi-form pages' );

$jobs = design_source( $root, 'inc/modules/profitsharing/inc/class-wei-alipay-ps-jobs-admin.php' );
design_expect( false !== strpos( $jobs, 'name="page" value="wei-alipay-ps-jobs"' ), 'job filters retain the correct page route' );
design_expect( false !== strpos( $jobs, "'pending', 'processing', 'succeeded', 'failed'" ), 'job filters include failed and use a fixed allow-list' );

$webhooks = design_source( $root, 'inc/class-wc-alipay-webhook-retry.php' );
design_expect( false !== strpos( $webhooks, 'id="wei-alipay-select-all"' ), 'webhook table select-all control is wired' );
design_expect( false === strpos( $webhooks, '<style>' ), 'webhook page has no inline style block' );
design_expect( false !== strpos( $webhooks, 'error: function()' ), 'webhook retry restores its control after a transport failure' );

$subscriptions = design_source( $root, 'inc/modules/subscriptions/inc/class-wc-alipay-subscriptions-admin.php' );
$subscriptions_bootstrap = design_source( $root, 'inc/modules/subscriptions/bootstrap.php' );
design_expect( false !== strpos( $subscriptions, '$is_sandbox' ) && false !== strpos( $subscriptions, 'Start sandbox signing' ), 'administrator signing shortcut is sandbox-gated' );
design_expect( false !== strpos( $subscriptions, "array( 'sign_only', 'sign_and_pay' )" ), 'subscription signing mode uses an allow-list' );
design_expect( false !== strpos( $subscriptions, 'max( $retry_days ) > 365' ), 'subscription retry schedule is bounded' );
design_expect( false === strpos( $subscriptions_bootstrap, 'Wei Alipay subscriptions require the WooCommerce Subscriptions extension.' ), 'missing subscriptions dependency does not create a duplicate global admin notice' );
design_expect( false !== strpos( $subscriptions_bootstrap, 'feature centre renders the dependency' ), 'missing subscriptions dependency is delegated to the inline feature state' );

$reconcile = design_source( $root, 'inc/modules/reconcile/inc/class-wei-alipay-reconcile-admin.php' );
design_expect( false !== strpos( $reconcile, "'woo_alipay_reconcile_run_nonce'" ), 'manual reconciliation uses a unique nonce field name' );

$gateway = design_source( $root, 'inc/class-wc-alipay.php' );
$gateway_js = design_source( $root, 'js/admin/main.js' );
$gateway_css = design_source( $root, 'css/admin/main.css' );
design_expect( 1 === substr_count( $gateway, 'id="wei-alipay-test-connection"' ), 'gateway connection test has one stable control ID' );
foreach ( array( 'wei-alipay-notify-url', 'wei-alipay-sign-start', 'wei-alipay-sign-notify', 'wei-alipay-sign-return' ) as $endpoint_id ) {
	design_expect( 1 === substr_count( $gateway, 'id="' . $endpoint_id . '"' ), 'gateway endpoint ID is unique: ' . $endpoint_id );
}
design_expect( false === strpos( $gateway, 'onclick=' ) && false === strpos( $gateway, '<script type="text/javascript">' ), 'gateway settings contain no inline JavaScript' );
design_expect( false !== strpos( $gateway_js, '.text( message )' ) && false === strpos( $gateway_js, '.html(' ), 'gateway connection result renders server text without HTML injection' );
design_expect( false !== strpos( $gateway_js, "'[data-wei-copy]'" ) && false !== strpos( $gateway_js, 'navigator.clipboard' ), 'gateway endpoint copy uses one delegated clipboard interaction' );
design_expect( false === strpos( $gateway_css, 'form-table tr[data-field-type="title"]' ), 'gateway CSS does not restyle native WooCommerce section rows' );
$settings_start = strpos( $gateway, 'protected function setup_form_fields' );
$settings_end   = strpos( $gateway, 'public function is_account_connected' );
$settings_ui    = substr( $gateway, $settings_start, $settings_end - $settings_start );
design_expect( 0 === preg_match( "/(?:__|_e|esc_html__|esc_attr__)\(\s*'[^']*[\x{4e00}-\x{9fff}]/u", $settings_ui ), 'gateway administration uses English source msgids consistently' );

$zh_cn = design_source( $root, 'languages/wei-alipay-zh_CN.po' );
foreach ( array( 'Basic settings', 'Alipay Profit Sharing', 'Alipay Webhook Logs', 'Alipay Subscriptions', 'Single-order Reconciliation' ) as $msgid ) {
	$pattern = '/msgid "' . preg_quote( $msgid, '/' ) . '"\Rmsgstr "[^"\r\n]+"/';
	design_expect( 1 === preg_match( $pattern, $zh_cn ), 'zh_CN translates admin string: ' . $msgid );
}

$evidence = json_decode( design_source( $root, 'docs/design/woopayments-admin-tokens.json' ), true );
design_expect( is_array( $evidence ) && 'https://weixiaoduo.com/schemas/woo-admin-design-evidence-v2.json' === $evidence['schema'], 'design evidence uses the two-product v2 schema' );
design_expect( '11.0.0' === $evidence['sources']['woopayments']['version'], 'design evidence identifies WooPayments 11.0.0' );
design_expect( '11.0.1' === $evidence['sources']['woocommerce']['version'], 'design evidence identifies WooCommerce 11.0.1' );
foreach ( array( 'woopayments_css', 'woopayments_js', 'woocommerce_css', 'woocommerce_js' ) as $evidence_group ) {
	design_expect( ! empty( $evidence['scope']['artifacts'][ $evidence_group ] ), 'design evidence inventories ' . $evidence_group );
}
design_expect( 30 === $evidence['scope']['artifact_counts']['woopayments_css'], 'design evidence covers 30 WooPayments admin CSS artifacts' );
design_expect( 27 === $evidence['scope']['artifact_counts']['woocommerce_css'], 'design evidence covers 27 WooCommerce admin CSS artifacts' );

exit( $failures ? 1 : 0 );
