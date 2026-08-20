<?php

define( 'ABSPATH', __DIR__ );

class WC_Payment_Gateway {
	public $enabled = 'no';
}

function __( $message, $domain = '' ) {
	return $message;
}

function esc_html( $value ) {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

function get_option( $key, $default = false ) {
	return $default;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, is_array( $args ) ? $args : array() );
}

class WP_Error {
	private $message;
	public function __construct( $code, $message = '' ) {
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $value ) );
}

function apply_filters( $hook, $value ) {
	return $value;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

require dirname( __DIR__ ) . '/inc/modules/cross-border/includes/class-wc-acbop.php';
require dirname( __DIR__ ) . '/inc/modules/mini-program/class-mini-protocol.php';
require dirname( __DIR__ ) . '/inc/modules/mini-program/class-wc-alipay-wap.php';

class Wei_Alipay_Test_Cross_Border_Gateway extends Wei_Alipay_Cross_Border_Gateway {
	public function prime( $enabled, $currency ) {
		$this->enabled           = $enabled;
		$this->selected_currency = $currency;
		$this->function          = new CommonFunction();
	}
}

$failures = 0;

function expect_output( $expected, $actual, $label ) {
	global $failures;
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$label}\nExpected: {$expected}\nActual: {$actual}\n" );
		++$failures;
		return;
	}
	echo "PASS: {$label}\n";
}

$gateway = ( new ReflectionClass( Wei_Alipay_Test_Cross_Border_Gateway::class ) )->newInstanceWithoutConstructor();

$gateway->prime( 'no', 'CNY' );
ob_start();
$gateway->requirement_checks();
expect_output( '', ob_get_clean(), 'disabled cross-border gateway emits no currency or credential notices' );

$gateway->prime( 'yes', 'CNY' );
ob_start();
$gateway->requirement_checks();
$notice = ob_get_clean();
if ( false === strpos( $notice, 'store currency is CNY, which is not supported' ) ) {
	fwrite( STDERR, "FAIL: enabled cross-border gateway reports unsupported CNY\n" );
	++$failures;
} else {
	echo "PASS: enabled cross-border gateway reports unsupported CNY\n";
}


class Wei_Alipay_Test_Mini_Program_Gateway extends Wei_Alipay_Mini_Program_Gateway {
	public function prime( $enabled, $currency, $protocol, $acknowledged ) {
		$this->enabled          = $enabled;
		$this->current_currency = $currency;
		$this->exchange_rate    = '';
		$this->protocol_mode    = $protocol;
		$this->legacy_md5_ack   = $acknowledged;
	}
}

$mini = ( new ReflectionClass( Wei_Alipay_Test_Mini_Program_Gateway::class ) )->newInstanceWithoutConstructor();
$mini->prime( 'no', 'CNY', 'legacy_md5', 'no' );
ob_start();
$mini->requirement_checks();
expect_output( '', ob_get_clean(), 'disabled Mini-program gateway emits no legacy MD5 policy notice' );

exit( $failures ? 1 : 0 );
