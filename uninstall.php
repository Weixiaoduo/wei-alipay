<?php

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // Exit if accessed directly
}

$delete_data = defined( 'WEI_ALIPAY_DELETE_DATA' ) && true === WEI_ALIPAY_DELETE_DATA;
$delete_data = (bool) apply_filters( 'woo_alipay_uninstall_delete_data', $delete_data );

// Payment settings and historical order metadata are retained by default.
if ( ! $delete_data ) {
	return;
}

$purge_current_site = static function() {
	foreach ( array(
		'woocommerce_alipay_settings',
		'woocommerce_alipay_wap_settings',
		'woocommerce_acbop_settings',
		'woo_alipay_plugin_version',
		'woo_alipay_schema_version',
		'woo_alipay_secret_rollback_v1',
		'wei_alipay_features',
		'wei_alipay_features_version',
		'wei_alipay_account_link',
		'wei_alipay_reconcile_schedule',
		'wei_alipay_reconcile_history',
		'woo_alipay_webhook_schema_version',
		'woo_alipay_reconcile_options',
	) as $option ) {
		delete_option( $option );
	}
	foreach ( array(
		'woo_alipay_check_pending_orders',
		'woo_alipay_check_timeout_orders',
		'woo_alipay_retry_failed_webhooks',
		'woo_alipay_cleanup_webhook_logs',
		'woo_alipay_scheduled_reconcile',
		'woo_alipay_ps_process_jobs',
	) as $hook ) {
		wp_clear_scheduled_hook( $hook );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, array(), 'wei-alipay' );
		}
	}
	global $wpdb;
	if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) && method_exists( $wpdb, 'query' ) ) {
		$table = $wpdb->prefix . 'woo_alipay_webhook_logs';
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- explicit uninstall purge of plugin-owned log table.
	}
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( (int) $site_id );
		$purge_current_site();
		restore_current_blog();
	}
} else {
	$purge_current_site();
}
