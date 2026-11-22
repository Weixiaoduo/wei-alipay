<?php
/**
 * Regression contract for the two 4.0.1 production hotfixes recovered into 4.1:
 *  1) inc/templates/redirected-pay.php validated auto-submit script
 *  2) inc/class-wc-alipay-webhook-retry.php multisite order-id parsing,
 *     transient error allowlist, and exact out_trade_no order re-binding.
 */

$redirect = file_get_contents( __DIR__ . '/../inc/templates/redirected-pay.php' );
$retry    = file_get_contents( __DIR__ . '/../inc/class-wc-alipay-webhook-retry.php' );

if ( false === $redirect || false === $retry ) {
	fwrite( STDERR, "Unable to read recovered production files.\n" );
	exit( 1 );
}

$assertions = array(
	'redirected-pay retains sanitized form output' => false !== strpos( $redirect, 'Alipay_SDK_Helper::sanitize_payment_form(' ),
	'redirected-pay includes auto-submit script' => false !== strpos( $redirect, 'id="wei-alipay-auto-submit"' ),
	'redirected-pay enforces https protocol' => false !== strpos( $redirect, "'https:' !== action.protocol" ),
	'redirected-pay allowlists official Alipay hosts' => false !== strpos( $redirect, "'openapi.alipay.com'" ) && false !== strpos( $redirect, "'openapi.alipaydev.com'" ),
	'redirected-pay checks RSA2 signature before submit' => false !== strpos( $redirect, "'RSA2' !== signType.value.toUpperCase()" ),
	'redirected-pay uses HTMLFormElement.prototype.submit' => false !== strpos( $redirect, 'HTMLFormElement.prototype.submit.call' ),
	'webhook-retry parses multisite blog-prefixed WooA numbers' => false !== strpos( $retry, 'order_id_from_out_trade_no(' ) && false !== strpos( $retry, 'get_current_blog_id()' ),
	'webhook-retry restricts automatic retry to transient errors' => false !== strpos( $retry, 'AND last_error IN (%s, %s)' ) && false !== strpos( $retry, "'concurrent_notify'" ) && false !== strpos( $retry, "'temporary_processing_failure'" ),
	'webhook-retry re-binds order by exact out_trade_no' => false !== strpos( $retry, 'Alipay_SDK_Helper::find_order_by_out_trade_no(' ),
);

$failed = 0;
foreach ( $assertions as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $passed ) {
		++$failed;
	}
}

echo 'Assertions: ' . count( $assertions ) . ', failures: ' . $failed . "\n";
exit( $failed ? 1 : 0 );
