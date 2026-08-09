<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_UNINSTALL_PLUGIN', true );
$GLOBALS['deleted'] = array();
function apply_filters($hook, $value) { return $value; }
function delete_option($option) { $GLOBALS['deleted'][] = $option; }
function wp_clear_scheduled_hook($hook) { $GLOBALS['deleted'][] = 'cron:' . $hook; }
function is_multisite() { return true; }
function get_sites($args = array()) { return array(1, 2); }
function switch_to_blog($id) {}
function restore_current_blog() {}
require dirname(__DIR__) . '/uninstall.php';
if (array() !== $GLOBALS['deleted']) { fwrite(STDERR, "FAIL: default uninstall deleted retained data\n"); exit(1); }
echo "PASS: default multisite uninstall retains settings and payment history\n";
