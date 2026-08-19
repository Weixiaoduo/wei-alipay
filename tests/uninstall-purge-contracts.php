<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_UNINSTALL_PLUGIN', true );
define( 'WEI_ALIPAY_DELETE_DATA', true );
$GLOBALS['blog_id'] = 1;
$GLOBALS['deleted'] = array();
function apply_filters($hook, $value) { return $value; }
function delete_option($option) { $GLOBALS['deleted'][] = $GLOBALS['blog_id'] . ':' . $option; }
function wp_clear_scheduled_hook($hook) { $GLOBALS['deleted'][] = $GLOBALS['blog_id'] . ':cron:' . $hook; }
function is_multisite() { return true; }
function get_sites($args = array()) { return array(1, 2); }
function switch_to_blog($id) { $GLOBALS['blog_id'] = (int) $id; }
function restore_current_blog() { $GLOBALS['blog_id'] = 1; }
require dirname(__DIR__) . '/uninstall.php';
$expected = array('1:woocommerce_alipay_settings','1:woocommerce_alipay_wap_settings','1:woocommerce_acbop_settings','1:woo_alipay_plugin_version','1:woo_alipay_schema_version','1:woo_alipay_secret_rollback_v1','1:cron:woo_alipay_check_pending_orders','1:cron:woo_alipay_check_timeout_orders','1:cron:woo_alipay_retry_failed_webhooks','1:cron:woo_alipay_ps_process_jobs','2:woocommerce_alipay_settings','2:woocommerce_alipay_wap_settings','2:woocommerce_acbop_settings','2:woo_alipay_plugin_version','2:woo_alipay_schema_version','2:woo_alipay_secret_rollback_v1','2:cron:woo_alipay_check_pending_orders','2:cron:woo_alipay_check_timeout_orders','2:cron:woo_alipay_retry_failed_webhooks','2:cron:woo_alipay_ps_process_jobs');
if ($expected !== $GLOBALS['deleted']) { fwrite(STDERR, "FAIL: explicit multisite purge matrix mismatch\n"); exit(1); }
echo "PASS: explicit multisite purge removes settings and cron only\n";
