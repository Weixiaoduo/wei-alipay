<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'WEI_ALIPAY_VERSION', '3.2.0' );
define( 'WEI_ALIPAY_PLUGIN_FILE', dirname( __DIR__ ) . '/wei-alipay.php' );
define( 'WEI_ALIPAY_PLUGIN_PATH', dirname( __DIR__ ) . '/' );
$GLOBALS['blog_id'] = 1;
$GLOBALS['options'] = array(1 => array('woocommerce_alipay_wap_settings' => array('partnerID' => 'p1', 'secure_key' => 'synthetic-one')), 2 => array('woocommerce_alipay_wap_settings' => array()));
$GLOBALS['migrated'] = array();
function is_multisite() { return true; }
function get_sites($args = array()) { return array(1, 2); }
function switch_to_blog($id) { $GLOBALS['blog_id'] = (int) $id; }
function restore_current_blog() { $GLOBALS['blog_id'] = 1; }
function get_option($name, $default = false) { return $GLOBALS['options'][$GLOBALS['blog_id']][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['options'][$GLOBALS['blog_id']][$name] = $value; return true; }
function wp_cache_flush() { return true; }
class Wei_Alipay_Secret_Store { public static function migrate_current_site() { $GLOBALS['migrated'][] = $GLOBALS['blog_id']; return true; } }
require dirname(__DIR__) . '/inc/class-wei-alipay.php';
Wei_Alipay::activate(true);
$fail = 0;
function lifecycle_expect($condition, $label) { global $fail; if ($condition) { echo "PASS: $label\n"; } else { fwrite(STDERR, "FAIL: $label\n"); ++$fail; } }
lifecycle_expect(array(1, 2) === $GLOBALS['migrated'], 'network activation migrates every site exactly once');
lifecycle_expect('legacy_md5' === $GLOBALS['options'][1]['woocommerce_alipay_wap_settings']['protocol_mode'], 'existing legacy site is marked for explicit MD5 migration');
lifecycle_expect('no' === $GLOBALS['options'][1]['woocommerce_alipay_wap_settings']['legacy_md5_ack'], 'legacy migration remains disabled until acknowledgement');
lifecycle_expect('rsa2' === $GLOBALS['options'][2]['woocommerce_alipay_wap_settings']['protocol_mode'], 'credential-free site defaults to RSA2');
lifecycle_expect('3.2.0-r3' === $GLOBALS['options'][1]['woo_alipay_schema_version'] && '3.2.0-r3' === $GLOBALS['options'][2]['woo_alipay_schema_version'], 'schema version advances per site');
exit($fail ? 1 : 0);
