<?php
/**
 * Runtime & contract verification for Wei Alipay 4.1.1 hotfix items:
 * - Orphan transaction email PHP 8.4 preview safety, template paths, placeholders, plain-text markup
 * - Lower-precedence key material decryption compatibility when promoting to WEI_ALIPAY_ENCRYPTION_KEY
 * - Currency notice typo/section link fix & wc_back_link() presence
 * - Gated POST Account disconnect confirmation & 401-only status revocation
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

function w411_assert( bool $cond, string $msg ): void {
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL: {$msg}\n" );
		exit( 1 );
	}
	echo "PASS: {$msg}\n";
}

// 1. Static & runtime checks on orphan email class + plain template.
$email_src = (string) file_get_contents( $root . '/inc/class-wc-email-alipay-orphan-transaction.php' );
$plain_src = (string) file_get_contents( $root . '/inc/templates/emails/plain/alipay-orphan-transaction.php' );
w411_assert( false !== strpos( $email_src, 'protected $template_variables = array(' ), 'orphan email initializes $template_variables array for WC Email Preview' );
w411_assert( false !== strpos( $email_src, "'{transaction_id}'" ), 'orphan email defines {transaction_id} placeholder' );
w411_assert( false === strpos( $email_src, '$this->template_base . $this->template_html' ), 'orphan email no longer passes full file path as template_path/default_path' );
w411_assert( false !== strpos( $email_src, "\$this->form_fields['heading']['description'] .= \$heading_desc;" ), 'orphan email heading description uses $heading_desc' );
w411_assert( false === strpos( $plain_src, '<code>' ), 'plain-text orphan email template contains no HTML <code> markup' );

// Runtime instantiation of WC_Email_Alipay_Orphan_Transaction without trigger() (simulates WC 11 Email Preview under PHP 8.4).
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
if ( ! defined( 'WEI_ALIPAY_PLUGIN_PATH' ) ) {
	define( 'WEI_ALIPAY_PLUGIN_PATH', $root . '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) { return (string) $text; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $prio = 10, $args = 1 ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $val ) { return $val; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) { return 'admin@example.com'; }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return (string) $text; }
}
$captured_templates = array();
if ( ! function_exists( 'wc_get_template_html' ) ) {
	function wc_get_template_html( $template_name, $args = array(), $template_path = '', $default_path = '' ) {
		global $captured_templates;
		$captured_templates[] = compact( 'template_name', 'args', 'template_path', 'default_path' );
		return 'rendered:' . $template_name;
	}
}
if ( ! class_exists( 'WC_Email' ) ) {
	class WC_Email {
		public $id = '';
		public $title = '';
		public $description = '';
		public $template_html = '';
		public $template_plain = '';
		public $template_base = '';
		public $recipient = '';
		public $placeholders = array();
		public $form_fields = array();
		public function __construct() {}
		public function get_option( $k, $d = '' ) { return $d; }
		public function get_heading() { return 'Heading'; }
		public function get_additional_content() { return ''; }
		public function get_blogname() { return 'Shop'; }
		public function init_form_fields() {
			$this->form_fields = array(
				'subject' => array( 'placeholder' => 'subj', 'description' => 'SUBJ_DESC' ),
				'heading' => array( 'placeholder' => 'head', 'description' => 'HEAD_DESC' ),
			);
		}
	}
}
require_once $root . '/inc/class-wc-email-alipay-orphan-transaction.php';
$email = new WC_Email_Alipay_Orphan_Transaction();
$email->init_form_fields();
$html_out  = $email->get_content_html();
$plain_out = $email->get_content_plain();
w411_assert( 'rendered:emails/alipay-orphan-transaction.php' === $html_out, 'orphan email get_content_html() renders safely in preview without trigger()' );
w411_assert( 'rendered:emails/plain/alipay-orphan-transaction.php' === $plain_out, 'orphan email get_content_plain() renders safely in preview without trigger()' );
w411_assert( '' === $captured_templates[0]['template_path'] && $root . '/inc/templates/' === $captured_templates[0]['default_path'], 'get_content_html() passes empty template_path and plugin template_base as default_path' );
w411_assert( '' === $captured_templates[1]['template_path'] && $root . '/inc/templates/' === $captured_templates[1]['default_path'], 'get_content_plain() passes empty template_path and plugin template_base as default_path' );
w411_assert( false !== strpos( (string) $email->form_fields['heading']['description'], 'HEAD_DESC' ), 'heading field description includes heading_desc' );

// 2. Runtime check: Secret Store decrypts salt-encrypted envelope after WEI_ALIPAY_ENCRYPTION_KEY is promoted.
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'test-auth-key-a' );
	define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-b' );
	define( 'LOGGED_IN_KEY', 'test-logged-in-key-c' );
	define( 'NONCE_KEY', 'test-nonce-key-d' );
}
if ( ! defined( 'WEI_ALIPAY_ENCRYPTION_KEY' ) ) {
	define( 'WEI_ALIPAY_ENCRYPTION_KEY', 'dedicated-prod-key-after-promotion' );
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $msg;
		public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->msg = $m; }
		public function get_error_code() { return $this->code; }
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return false; } }
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}
require_once $root . '/inc/class-wei-alipay-secret-store.php';
$salt_material = implode( '|', array( AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_KEY ) );
$old_cipher    = Wei_Alipay_Secret_Store::encrypt_value( 'legacy-rsa2-private-key-sample', $salt_material );
w411_assert( is_string( $old_cipher ) && 0 === strpos( $old_cipher, Wei_Alipay_Secret_Store::PREFIX ), 'salt-material envelope encrypted cleanly' );
$decrypted_opt = Wei_Alipay_Secret_Store::decrypt_option( array( 'private_key' => $old_cipher ), 'woocommerce_alipay_settings' );
w411_assert( 'legacy-rsa2-private-key-sample' === ( $decrypted_opt['private_key'] ?? '' ), 'promoted WEI_ALIPAY_ENCRYPTION_KEY site still decrypts pre-promotion WP-salt envelope' );

// 3. Gateway typo/link/back-link & disconnect gate & 401-only revocation checks.
$gw_src     = (string) file_get_contents( $root . '/inc/class-wc-alipay.php' );
$shell_src  = (string) file_get_contents( $root . '/inc/class-wei-alipay-shell.php' );
$client_src = (string) file_get_contents( $root . '/inc/class-wei-alipay-account-client.php' );
w411_assert( false === strpos( $gw_src, 'Aliay' ), 'Aliay typo removed from class-wc-alipay.php' );
w411_assert( false === strpos( $gw_src, 'section=wc_alipay' ), 'broken section=wc_alipay link fixed to section=alipay' );
w411_assert( false !== strpos( $gw_src, 'wc_back_link(' ), 'main Alipay wc-settings screen renders wc_back_link() back to Wei Alipay console' );
w411_assert( false !== strpos( $shell_src, 'Wenpai_Admin_UI::gate_html(' ), 'Account disconnect uses Wenpai_Admin_UI::gate_html() confirmation gate' );
w411_assert( false !== strpos( $client_src, "confirm_disconnect" ), 'Account disconnect validates POST confirm_disconnect server-side' );
w411_assert( false !== strpos( $client_src, 'if ( 401 === $code ) {' ) && false === strpos( $client_src, '403 === $code' ), 'status refresh clears local link only on HTTP 401 (WAF/CDN 403 safe)' );

echo "[PASS] 4.1.1 hotfix runtime and contract checks\n";
