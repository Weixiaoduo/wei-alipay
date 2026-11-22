<?php
/**
 * Regression contract for fail-closed Alipay callback handling (migrated from ac52c3a).
 *
 * Verifies POST-only, unslashed input, signature check before order lookup,
 * fail response on invalid callback, and no order-failure mutation on invalid input.
 */

$source = file_get_contents( __DIR__ . '/../inc/class-wc-alipay.php' );
if ( false === $source ) {
	fwrite( STDERR, "Unable to read gateway source.\n" );
	exit( 1 );
}

if ( ! preg_match( '/public function check_alipay_response\(\)\s*\{(?<body>.*?)(?=\n\s*(?:public|protected|private) function |\n\})/s', $source, $matches ) ) {
	fwrite( STDERR, "Unable to isolate callback method.\n" );
	exit( 1 );
}

$body = $matches['body'];
$assertions = array(
	'unslashes callback input' => false !== strpos( $body, 'wp_unslash($_POST)' ),
	'checks HTTP method' => false !== strpos( $body, "'REQUEST_METHOD'" ),
	'requires POST' => false !== strpos( $body, "'post' !== \$request_method" ),
	'requires merchant order number' => false !== strpos( $body, "'out_trade_no'" ),
	'requires application id' => false !== strpos( $body, "'app_id'" ),
	'requires amount' => false !== strpos( $body, "'total_amount'" ),
	'verifies signature via SDK helper' => false !== strpos( $body, 'Alipay_SDK_Helper::verify_notify(' ),
	'invalid request returns fail' => false !== strpos( $body, "echo 'fail'" ),
	'invalid callback does not fail an order' => false === strpos( $body, "update_status('failed'" ),
	'does not unconditionally log the full callback payload' => false === strpos( $body, "self::log(__METHOD__ . ' Alipay response raw data" ),
);

$verify_position = strpos( $body, 'Alipay_SDK_Helper::verify_notify(' );
$lookup_position = strpos( $body, 'find_order_by_out_trade_no(' );
$order_position  = strpos( $body, 'wc_get_order(' );
$assertions['verifies signature before order lookup'] =
	false !== $verify_position
	&& ( false === $lookup_position || $verify_position < $lookup_position )
	&& ( false === $order_position || $verify_position < $order_position );

$failed = 0;
foreach ( $assertions as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $passed ) {
		++$failed;
	}
}

echo 'Assertions: ' . count( $assertions ) . ', failures: ' . $failed . "\n";
exit( $failed ? 1 : 0 );
