<?php
/**
 * Runtime regression tests for W2-A3:
 *  - Connect-Latch VERSION 3 semantics (unconnected / connected / disconnected-after-enable)
 *  - Server-side option save-lock on WooCommerce payment settings when latched
 *  - Local tools stay configurable & editable while disconnected
 *  - PKCE S256 challenge derivation & Cravatar allowlist (zero cravatar.cn)
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['test_options'] = array();
$GLOBALS['test_filters'] = array();
$GLOBALS['test_errors']  = array();

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $key ] : $default;
}
function update_option( $key, $val, $autoload = null ) {
	$GLOBALS['test_options'][ $key ] = $val;
	return true;
}
function add_action( $tag, $cb, $prio = 10, $args = 1 ) {}
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['test_filters'][ $tag ][] = $cb;
}
function apply_filters( $tag, $val ) {
	if ( ! empty( $GLOBALS['test_filters'][ $tag ] ) ) {
		foreach ( $GLOBALS['test_filters'][ $tag ] as $cb ) {
			$val = $cb( $val );
		}
	}
	return $val;
}
function __( $s, $d = '' ) { return $s; }
function esc_url_raw( $u, $p = null ) { return (string) $u; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function admin_url( $p = '' ) { return 'https://shop.example.com/wp-admin/' . ltrim( (string) $p, '/' ); }
function home_url( $p = '' ) { return 'https://shop.example.com/' . ltrim( (string) $p, '/' ); }
function wp_nonce_url( $u, $a = -1 ) { return $u . '&_wpnonce=test'; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function current_time( $type ) { return '2026-11-22 12:00:00'; }
function wp_generate_password( $len = 12, $s = true, $e = false ) { return str_repeat( 'a', (int) $len ); }
function absint( $v ) { return abs( (int) $v ); }

class WC_Admin_Settings {
	public static function add_error( $msg ) {
		$GLOBALS['test_errors'][] = $msg;
	}
}

require dirname( __DIR__ ) . '/inc/class-wei-alipay-account-client.php';
require dirname( __DIR__ ) . '/inc/class-wei-alipay-features.php';

$failures = 0;
$expect   = static function( $cond, $label ) use ( &$failures ) {
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		++$failures;
		return;
	}
	echo "PASS: {$label}\n";
};

// 1. PKCE S256 RFC 7636 known vector
$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
$expected  = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
$expect( $expected === Wei_Alipay_Account_Client::pkce_challenge( $verifier ), 'PKCE S256 matches RFC 7636 test vector' );

// 2. Cravatar host allowlist rejects cravatar.cn and accepts cravatar.com
$expect( '' === Wei_Alipay_Account_Client::sanitize_cravatar_url( 'https://cravatar.cn/avatar/abc' ), 'cravatar.cn is rejected' );
$expect( 'https://cravatar.com/avatar/abc' === Wei_Alipay_Account_Client::sanitize_cravatar_url( 'https://cravatar.com/avatar/abc' ), 'cravatar.com is accepted' );
$expect( '' === Wei_Alipay_Account_Client::sanitize_cravatar_url( 'http://cravatar.com/avatar/abc' ), 'non-HTTPS Cravatar URL is rejected' );

// 3. Disconnected + previously enabled f2f (LATCHED state)
$GLOBALS['test_options'][ Wei_Alipay_Features::VERSION_KEY ] = Wei_Alipay_Features::VERSION;
$GLOBALS['test_options'][ Wei_Alipay_Features::OPTION_KEY ]  = array(
	'f2f'           => 'yes',
	'huabei'        => 'no',
	'reconcile'     => 'yes',
	'subscriptions' => 'yes', // unavailable must still fail closed!
);
$GLOBALS['test_options'][ Wei_Alipay_Account_Client::OPTION_KEY ] = array(
	'status' => 'not-connected',
	'token'  => '',
);

$expect( true === Wei_Alipay_Features::is_enabled( 'f2f' ), 'Latched f2f keeps running after disconnect' );
$expect( false === Wei_Alipay_Features::is_enabled( 'subscriptions' ), 'Unavailable subscriptions always fails closed' );
$expect( true === Wei_Alipay_Features::is_feature_locked_for_edit( 'f2f' ), 'Latched f2f reports locked for option edits while disconnected' );
$expect( false === Wei_Alipay_Features::is_feature_locked_for_edit( 'reconcile' ), 'Local reconcile tool is never locked while disconnected' );

$old_opt = array( 'enabled' => 'yes', 'qrcode_timeout' => '120' );
$new_opt = array( 'enabled' => 'yes', 'qrcode_timeout' => '300' );
$guarded = Wei_Alipay_Features::guard_locked_option_update( 'f2f', $new_opt, $old_opt );
$expect( $old_opt === $guarded, 'Disconnected save-lock returns old option unchanged' );
$expect( count( $GLOBALS['test_errors'] ) === 1, 'Disconnected save-lock emits WC_Admin_Settings error' );

// 4. Connected state unlocks option edits
$GLOBALS['test_options'][ Wei_Alipay_Account_Client::OPTION_KEY ] = array(
	'status' => 'connected',
	'token'  => 'tok_live_test',
);
$expect( false === Wei_Alipay_Features::is_feature_locked_for_edit( 'f2f' ), 'Reconnected f2f unlocks option edits' );
$allowed = Wei_Alipay_Features::guard_locked_option_update( 'f2f', $new_opt, $old_opt );
$expect( $new_opt === $allowed, 'Reconnected f2f allows saving updated options' );

// 5. Legacy mini_program is absent from registry
$reg = Wei_Alipay_Features::registry();
$expect( ! isset( $reg['mini_program'] ), 'Legacy mini_program is removed from feature registry' );

exit( $failures ? 1 : 0 );
