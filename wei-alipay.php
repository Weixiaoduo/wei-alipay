<?php
/*
* Plugin Name: Wei Alipay Payment Gateway for WooCommerce
* Plugin URI: https://wenpai.org/plugins/wei-alipay
* Update URI: https://updates.wenpai.net
* Description: Integrate Woocommerce with Alipay (Mainland China). Supports WooCommerce Blocks and HPOS.
* Version: 4.0.1
* Author: Weixiaoduo.com
* Author URI: https://weixiaoduo.com/
* Text Domain: wei-alipay
* Domain Path: /languages
* WC tested up to: 10.9.4
* WC requires at least: 8.3
* Requires PHP: 7.4
* Requires at least: 6.6
* Requires Plugins: woocommerce
* License: GPLv3
* License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! defined( 'WEI_ALIPAY_PLUGIN_FILE' ) ) {
	define( 'WEI_ALIPAY_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'WEI_ALIPAY_PLUGIN_PATH' ) ) {
	define( 'WEI_ALIPAY_PLUGIN_PATH', plugin_dir_path( WEI_ALIPAY_PLUGIN_FILE ) );
}

if ( ! defined( 'WEI_ALIPAY_PLUGIN_URL' ) ) {
	define( 'WEI_ALIPAY_PLUGIN_URL', plugin_dir_url( WEI_ALIPAY_PLUGIN_FILE ) );
}

if ( ! defined( 'WEI_ALIPAY_VERSION' ) ) {
	define( 'WEI_ALIPAY_VERSION', '4.0.1' );
}

require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wei-alipay.php';
require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wei-alipay-secret-store.php';
require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wei-alipay-features.php';
Wei_Alipay_Secret_Store::register();
Wei_Alipay_Features::register();

if ( file_exists( WEI_ALIPAY_PLUGIN_PATH . 'includes/class-wenpai-updater.php' ) ) {
	require_once WEI_ALIPAY_PLUGIN_PATH . 'includes/class-wenpai-updater.php';
}

if ( class_exists( 'Wei_Alipay_WenPai_Updater' ) ) {
	new Wei_Alipay_WenPai_Updater( plugin_basename( WEI_ALIPAY_PLUGIN_FILE ), WEI_ALIPAY_VERSION );
}

if ( ! defined( 'WEI_ALIPAY_PS_PLUGIN_PATH' ) ) {
	define( 'WEI_ALIPAY_PS_PLUGIN_PATH', WEI_ALIPAY_PLUGIN_PATH . 'inc/modules/profitsharing/' );
}

if ( ! defined( 'WEI_ALIPAY_PS_PLUGIN_URL' ) ) {
	define( 'WEI_ALIPAY_PS_PLUGIN_URL', WEI_ALIPAY_PLUGIN_URL . 'inc/modules/profitsharing/' );
}

if ( ! defined( 'WEI_ALIPAY_FUNDAUTH_PLUGIN_PATH' ) ) {
	define( 'WEI_ALIPAY_FUNDAUTH_PLUGIN_PATH', WEI_ALIPAY_PLUGIN_PATH . 'inc/modules/fund-auth/' );
}

if ( ! defined( 'WEI_ALIPAY_FUNDAUTH_PLUGIN_URL' ) ) {
	define( 'WEI_ALIPAY_FUNDAUTH_PLUGIN_URL', WEI_ALIPAY_PLUGIN_URL . 'inc/modules/fund-auth/' );
}

if ( ! defined( 'WEI_ALIPAY_RECONCILE_PLUGIN_PATH' ) ) {
	define( 'WEI_ALIPAY_RECONCILE_PLUGIN_PATH', WEI_ALIPAY_PLUGIN_PATH . 'inc/modules/reconcile/' );
}

if ( ! defined( 'WEI_ALIPAY_RECONCILE_PLUGIN_URL' ) ) {
	define( 'WEI_ALIPAY_RECONCILE_PLUGIN_URL', WEI_ALIPAY_PLUGIN_URL . 'inc/modules/reconcile/' );
}

if ( ! defined( 'WEI_ALIPAY_BARCODE_PLUGIN_PATH' ) ) {
	define( 'WEI_ALIPAY_BARCODE_PLUGIN_PATH', WEI_ALIPAY_PLUGIN_PATH . 'inc/modules/barcode/' );
}

if ( ! defined( 'WEI_ALIPAY_BARCODE_PLUGIN_URL' ) ) {
	define( 'WEI_ALIPAY_BARCODE_PLUGIN_URL', WEI_ALIPAY_PLUGIN_URL . 'inc/modules/barcode/' );
}

if ( ! defined( 'WEI_ALIPAY_F2F_PLUGIN_PATH' ) ) {
	define( 'WEI_ALIPAY_F2F_PLUGIN_PATH', WEI_ALIPAY_PLUGIN_PATH . 'inc/modules/f2f/' );
}

if ( ! defined( 'WEI_ALIPAY_F2F_PLUGIN_URL' ) ) {
	define( 'WEI_ALIPAY_F2F_PLUGIN_URL', WEI_ALIPAY_PLUGIN_URL . 'inc/modules/f2f/' );
}

if ( ! defined( 'WEI_ALIPAY_HUABEI_PLUGIN_PATH' ) ) {
	define( 'WEI_ALIPAY_HUABEI_PLUGIN_PATH', WEI_ALIPAY_PLUGIN_PATH . 'inc/modules/huabei/' );
}

if ( ! defined( 'WEI_ALIPAY_HUABEI_PLUGIN_URL' ) ) {
	define( 'WEI_ALIPAY_HUABEI_PLUGIN_URL', WEI_ALIPAY_PLUGIN_URL . 'inc/modules/huabei/' );
}

if ( ! defined( 'WEI_ALIPAY_SUBSCRIPTIONS_PLUGIN_PATH' ) ) {
	define( 'WEI_ALIPAY_SUBSCRIPTIONS_PLUGIN_PATH', WEI_ALIPAY_PLUGIN_PATH . 'inc/modules/subscriptions/' );
}

if ( ! defined( 'WEI_ALIPAY_SUBSCRIPTIONS_PLUGIN_URL' ) ) {
	define( 'WEI_ALIPAY_SUBSCRIPTIONS_PLUGIN_URL', WEI_ALIPAY_PLUGIN_URL . 'inc/modules/subscriptions/' );
}

if ( ! defined( 'WEI_ALIPAY_MINIPROGRAM_PLUGIN_PATH' ) ) {
	define( 'WEI_ALIPAY_MINIPROGRAM_PLUGIN_PATH', WEI_ALIPAY_PLUGIN_PATH . 'inc/modules/mini-program/' );
}

if ( ! defined( 'WEI_ALIPAY_MINIPROGRAM_PLUGIN_URL' ) ) {
	define( 'WEI_ALIPAY_MINIPROGRAM_PLUGIN_URL', WEI_ALIPAY_PLUGIN_URL . 'inc/modules/mini-program/' );
}

if ( ! defined( 'WEI_ALIPAY_CROSS_BORDER_PLUGIN_PATH' ) ) {
	define( 'WEI_ALIPAY_CROSS_BORDER_PLUGIN_PATH', WEI_ALIPAY_PLUGIN_PATH . 'inc/modules/cross-border/' );
}

if ( ! defined( 'WEI_ALIPAY_CROSS_BORDER_PLUGIN_URL' ) ) {
	define( 'WEI_ALIPAY_CROSS_BORDER_PLUGIN_URL', WEI_ALIPAY_PLUGIN_URL . 'inc/modules/cross-border/' );
}

if ( Wei_Alipay_Features::is_enabled( 'profitsharing' ) && file_exists( WEI_ALIPAY_PS_PLUGIN_PATH . 'bootstrap.php' ) ) {
	require_once WEI_ALIPAY_PS_PLUGIN_PATH . 'bootstrap.php';
}

if ( Wei_Alipay_Features::is_enabled( 'fund_auth' ) && file_exists( WEI_ALIPAY_FUNDAUTH_PLUGIN_PATH . 'bootstrap.php' ) ) {
	require_once WEI_ALIPAY_FUNDAUTH_PLUGIN_PATH . 'bootstrap.php';
}

if ( Wei_Alipay_Features::is_enabled( 'reconcile' ) && file_exists( WEI_ALIPAY_RECONCILE_PLUGIN_PATH . 'bootstrap.php' ) ) {
	require_once WEI_ALIPAY_RECONCILE_PLUGIN_PATH . 'bootstrap.php';
}

if ( Wei_Alipay_Features::is_enabled( 'barcode' ) && file_exists( WEI_ALIPAY_BARCODE_PLUGIN_PATH . 'bootstrap.php' ) ) {
	require_once WEI_ALIPAY_BARCODE_PLUGIN_PATH . 'bootstrap.php';
}

if ( Wei_Alipay_Features::is_enabled( 'f2f' ) && file_exists( WEI_ALIPAY_F2F_PLUGIN_PATH . 'bootstrap.php' ) ) {
	require_once WEI_ALIPAY_F2F_PLUGIN_PATH . 'bootstrap.php';
}

if ( Wei_Alipay_Features::is_enabled( 'huabei' ) && file_exists( WEI_ALIPAY_HUABEI_PLUGIN_PATH . 'bootstrap.php' ) ) {
	require_once WEI_ALIPAY_HUABEI_PLUGIN_PATH . 'bootstrap.php';
}

if ( Wei_Alipay_Features::is_enabled( 'subscriptions' ) && file_exists( WEI_ALIPAY_SUBSCRIPTIONS_PLUGIN_PATH . 'bootstrap.php' ) ) {
	require_once WEI_ALIPAY_SUBSCRIPTIONS_PLUGIN_PATH . 'bootstrap.php';
}

if ( Wei_Alipay_Features::is_enabled( 'mini_program' ) && file_exists( WEI_ALIPAY_MINIPROGRAM_PLUGIN_PATH . 'bootstrap.php' ) ) {
	require_once WEI_ALIPAY_MINIPROGRAM_PLUGIN_PATH . 'bootstrap.php';
}

if ( Wei_Alipay_Features::is_enabled( 'cross_border' ) && file_exists( WEI_ALIPAY_CROSS_BORDER_PLUGIN_PATH . 'bootstrap.php' ) ) {
	require_once WEI_ALIPAY_CROSS_BORDER_PLUGIN_PATH . 'bootstrap.php';
}

// Load agreement manager (subscriptions auto-renew support)
if ( Wei_Alipay_Features::is_enabled( 'subscriptions' ) && file_exists( WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay-agreement.php' ) ) {
	require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay-agreement.php';
}
// Load subscription UI helpers
if ( Wei_Alipay_Features::is_enabled( 'subscriptions' ) && file_exists( WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay-subscription-ui.php' ) ) {
	require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay-subscription-ui.php';
}

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

register_activation_hook( WEI_ALIPAY_PLUGIN_FILE, array( 'Wei_Alipay', 'activate' ) );
register_deactivation_hook( WEI_ALIPAY_PLUGIN_FILE, array( 'Wei_Alipay', 'deactivate' ) );
register_uninstall_hook( WEI_ALIPAY_PLUGIN_FILE, array( 'Wei_Alipay', 'uninstall' ) );
add_action( 'admin_init', array( 'Wei_Alipay', 'maybe_upgrade' ), 1 );

function woo_alipay_run() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay.php';

	// Load SDK helper class
	if ( file_exists( WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php' ) ) {
		require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
	}

	// Extension gateways are now fully owned and loaded by their respective extensions.
	// The core plugin no longer requires Huabei or Face-to-Face classes here.

	// Load enhanced payment features
	if ( file_exists( WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay-order-query.php' ) ) {
		require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay-order-query.php';
	}

	if ( file_exists( WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay-order-timeout.php' ) ) {
		require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay-order-timeout.php';
	}

	if ( Wei_Alipay_Features::is_enabled( 'webhook_retry' ) && file_exists( WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay-webhook-retry.php' ) ) {
		require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay-webhook-retry.php';
	}

	$alipay_lib_paths = array(
		'payment_mobile'     => array(
			'AlipayTradeService'              => WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/wappay/service/AlipayTradeService.php',
			'AlipayTradeWapPayContentBuilder' => WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/wappay/buildermodel/AlipayTradeWapPayContentBuilder.php',
		),
		'payment_computer'   => array(
			'AlipayTradeService'               => WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/pagepay/service/AlipayTradeService.php',
			'AlipayTradePagePayContentBuilder' => WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/pagepay/buildermodel/AlipayTradePagePayContentBuilder.php',
		),
		'check_notification' => array(
			'AlipayTradeService' => WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/pagepay/service/AlipayTradeService.php',
		),
		'refund'             => array(
			'AlipayTradeService'              => WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/pagepay/service/AlipayTradeService.php',
			'AlipayTradeRefundContentBuilder' => WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/pagepay/buildermodel/AlipayTradeRefundContentBuilder.php',
			'AlipayTradeRefundRequest'        => WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradeRefundRequest.php',
		),
		'dummy_query'        => array(
			'AlipayTradeService'      => WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/pagepay/service/AlipayTradeService.php',
			'AlipayTradeQueryRequest' => WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradeQueryRequest.php',
		),
	);

	$wc_alipay = new WC_Alipay( true );
	$wooalipay = new Wei_Alipay( $alipay_lib_paths, true );
}
add_action( 'init', 'woo_alipay_run', 0, 0 );
