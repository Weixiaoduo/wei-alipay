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
	) as $option ) {
		delete_option( $option );
	}
	foreach ( array(
		'woo_alipay_check_pending_orders',
		'woo_alipay_check_timeout_orders',
		'woo_alipay_retry_failed_webhooks',
		'woo_alipay_ps_process_jobs',
	) as $hook ) {
		wp_clear_scheduled_hook( $hook );
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
