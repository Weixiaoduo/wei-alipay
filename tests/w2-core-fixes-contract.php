<?php
/**
 * W2-A1 regression contracts:
 *  - P0-5: cross-border acbop secret masking + password field
 *  - P0-6: F2F template path uses WEI_ALIPAY_F2F_PLUGIN_PATH
 *  - P0-7: Huabei CSS/JS URL, auto-submit validator, and Blocks Store API period hook
 *  - P1-1: Weixiaoduo Update URI / Plugin URI / UpdatePulse manifest
 *  - P1-3: Cross-border callback hook matches notify_url and drops unimplemented refunds
 *  - P1-4: Core gateway gates subscriptions/tokenization behind feature flag
 */

$root = dirname( __DIR__ );
$failures = 0;

$check = static function( $cond, $label ) use ( &$failures ) {
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		++$failures;
		return;
	}
	echo "PASS: {$label}\n";
};

$main = file_get_contents( $root . '/wei-alipay.php' );
$check( false !== strpos( $main, 'Plugin URI: https://weixiaoduo.com/product/wei-alipay.html' ), 'Plugin URI points to weixiaoduo.com' );
$check( false !== strpos( $main, 'Update URI: https://updates.weixiaoduo.com' ), 'Update URI points to updates.weixiaoduo.com' );
$check( false === strpos( $main, 'wenpai.org' ) && false === strpos( $main, 'updates.wenpai.net' ), 'Main plugin header removes wenpai URLs' );
$check( ! is_dir( $root . '/inc/modules/mini-program' ), 'Legacy mini-program module directory removed' );

$updater = file_get_contents( $root . '/includes/class-wenpai-updater.php' );
$check( false !== strpos( $updater, 'update_plugins_updates.weixiaoduo.com' ), 'Updater hooks update_plugins_updates.weixiaoduo.com' );
$check( false === strpos( $updater, 'wenpai_updater_override' ), 'Updater does not yield to wp-china-yes override' );

$secrets = file_get_contents( $root . '/inc/class-wei-alipay-secret-store.php' );
$check( false !== strpos( $secrets, "'acbop'" ), 'Secret store masks acbop settings screen (P0-5)' );

$cb_form = file_get_contents( $root . '/inc/modules/cross-border/includes/form-settings-alipay.php' );
$check( false !== strpos( $cb_form, "'password'" ), 'Cross-border secure_key uses password input type (P0-5)' );

$acbop = file_get_contents( $root . '/inc/modules/cross-border/includes/class-wc-acbop.php' );
$check( false !== strpos( $acbop, 'woocommerce_api_wei_alipay_cross_border_gateway' ), 'Cross-border callback matches Wei_Alipay_Cross_Border_Gateway notify_url (P1-3)' );
$check( false === strpos( $acbop, "'refunds'" ), 'Cross-border removes unimplemented refunds capability (P1-3)' );

$core = file_get_contents( $root . '/inc/class-wc-alipay.php' );
$check( false !== strpos( $core, "Wei_Alipay_Features::is_enabled( 'subscriptions' )" ), 'Core gateway gates subscriptions/tokenization behind feature flag (P1-4)' );

$f2f = file_get_contents( $root . '/inc/modules/f2f/inc/class-wc-alipay-facetopay.php' );
$check( false !== strpos( $f2f, "WEI_ALIPAY_F2F_PLUGIN_PATH . 'inc/templates/payment-qrcode.php'" ), 'F2F QR template uses WEI_ALIPAY_F2F_PLUGIN_PATH (P0-6)' );

$huabei = file_get_contents( $root . '/inc/modules/huabei/inc/class-wc-alipay-installment.php' );
$huabei_boot = file_get_contents( $root . '/inc/modules/huabei/bootstrap.php' );
$check( false !== strpos( $huabei, "WEI_ALIPAY_HUABEI_PLUGIN_URL . 'css/alipay-installment.css'" ), 'Huabei CSS uses WEI_ALIPAY_HUABEI_PLUGIN_URL (P0-7)' );
$check( false !== strpos( $huabei, "WEI_ALIPAY_HUABEI_PLUGIN_URL . 'js/alipay-installment.js'" ), 'Huabei JS uses WEI_ALIPAY_HUABEI_PLUGIN_URL (P0-7)' );
$check( false !== strpos( $huabei, 'wei-alipay-installment-auto-submit' ), 'Huabei receipt page appends validated auto-submit script (P0-7)' );
$check( false !== strpos( $huabei_boot, 'woocommerce_store_api_checkout_update_order_from_request' ), 'Huabei captures Blocks Store API installment period (P0-7)' );

exit( $failures ? 1 : 0 );
