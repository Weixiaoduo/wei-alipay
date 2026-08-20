<?php
$root = dirname( __DIR__ );
$failures = 0;
function check_contract( $condition, $message ) {
    global $failures;
    if ( $condition ) {
        echo "PASS: {$message}\n";
        return;
    }
    fwrite( STDERR, "FAIL: {$message}\n" );
    ++$failures;
}
function source_file( $root, $relative ) {
    $content = file_get_contents( $root . '/' . $relative );
    if ( false === $content ) {
        throw new RuntimeException( 'Cannot read ' . $relative );
    }
    return $content;
}

$signing = source_file( $root, 'inc/modules/subscriptions/inc/class-wc-alipay-subscriptions-signing.php' );
$checkout = source_file( $root, 'inc/modules/subscriptions/inc/class-wc-alipay-subscriptions-checkout.php' );
$charge = source_file( $root, 'inc/modules/subscriptions/inc/class-wc-alipay-subscriptions-charge.php' );
$admin = source_file( $root, 'inc/modules/subscriptions/inc/class-wc-alipay-subscriptions-admin.php' );
check_contract( false !== strpos( $signing, 'is_auto_debit_enabled()' ), 'subscription sign and charge require explicit feature enablement' );
check_contract( false !== strpos( $signing, 'sign_nonce_action( $user_id, $order_id )' ), 'subscription sign start verifies an order-bound nonce' );
check_contract( false !== strpos( $signing, '(int) $order->get_user_id() !== $user_id' ), 'subscription sign start enforces order ownership' );
check_contract( false !== strpos( $signing, 'Agreement notify app_id mismatch' ), 'agreement callback binds app_id' );
check_contract( false !== strpos( $charge, 'charge_context_mismatch' ) && false !== strpos( $charge, 'hash_equals' ), 'subscription charge binds the provider response to its merchant order number' );
check_contract( false !== strpos( $signing, 'Agreement notify order binding mismatch' ), 'agreement callback binds order to signed user' );
check_contract( false !== strpos( $signing, "acquire_lock( 'agreement_charge'" ), 'agreement initial charge uses a database lock' );
check_contract( false !== strpos( $signing, "_alipay_agreement_initial_charge_done" ), 'agreement initial charge persists idempotency state' );
check_contract( false !== strpos( $signing, "woo_alipay_subscription_unsign_" ), 'subscription unsign verifies a user-bound nonce' );
check_contract( false !== strpos( $checkout, 'wp_nonce_url(' ) && false !== strpos( $admin, 'wp_nonce_url(' ), 'all generated subscription sign links carry nonces' );

$legacy_agreement = source_file( $root, 'inc/class-wc-alipay-agreement.php' );
$legacy_agreement_ui = source_file( $root, 'inc/class-wc-alipay-subscription-ui.php' );
check_contract( false !== strpos( $signing, 'Agreement notify seller_id mismatch' ), 'subscription agreement callback binds configured seller_id' );
check_contract( false !== strpos( $legacy_agreement, "wp_verify_nonce( \$nonce, 'woo_alipay_agreement_start_'" ), 'legacy agreement start requires a user-bound nonce' );
check_contract( false !== strpos( $legacy_agreement, 'wp_validate_redirect' ) && false !== strpos( $legacy_agreement, 'wp_parse_url( home_url(), PHP_URL_HOST )' ), 'legacy agreement return target is restricted to the store host' );
check_contract( false !== strpos( $legacy_agreement, '! hash_equals( $expected_app_id, $notified_app_id )' ), 'legacy agreement callback binds app_id' );
check_contract( false !== strpos( $legacy_agreement, '! hash_equals( $expected_seller_id, $notified_seller_id )' ), 'legacy agreement callback binds configured seller_id' );
check_contract( false !== strpos( $legacy_agreement, "'enable_auto_renew'] ?? 'no'" ), 'legacy agreement start requires explicit automatic-renewal enablement' );
check_contract( false !== strpos( $legacy_agreement, "in_array( \$normalized_status, [ 'NORMAL', 'SUCCESS' ], true )" ), 'legacy agreement stores a usable token only for accepted states' );
check_contract( false === strpos( $legacy_agreement, '! $user_id && is_user_logged_in()' ), 'server callback never falls back to browser session identity' );
check_contract( 2 === substr_count( $legacy_agreement_ui, 'wp_nonce_url(' ), 'all legacy agreement start links carry nonces' );

foreach ( array(
    'inc/modules/f2f/inc/class-wc-alipay-facetopay.php',
    'inc/modules/huabei/inc/class-wc-alipay-installment.php',
) as $refund_file ) {
    $refund = source_file( $root, $refund_file );
    check_contract( false !== strpos( $refund, "'out_request_no' => \$request_id" ), basename( $refund_file ) . ' refund sends a stable request id' );
    check_contract( false !== strpos( $refund, "acquire_lock('refund', \$request_id" ), basename( $refund_file ) . ' refund serializes concurrent requests' );
    check_contract( false !== strpos( $refund, "_alipay_processed_refund_requests" ), basename( $refund_file ) . ' refund persists completed request ids' );
    check_contract( false !== strpos( $refund, 'refund_context_mismatch' ), basename( $refund_file ) . ' refund binds response context' );
    check_contract( false !== strpos( $refund, 'amounts_equal($refund_amount, $result->refund_fee)' ), basename( $refund_file ) . ' refund validates exact amount' );
}

$asset_contracts = array(
    'inc/modules/f2f/inc/class-wc-alipay-facetopay-blocks-support.php' => 'inc/modules/f2f/js/frontend/blocks-facetopay.js',
    'inc/modules/huabei/inc/class-wc-alipay-installment-blocks-support.php' => 'inc/modules/huabei/js/frontend/blocks-installment.js',
);
foreach ( $asset_contracts as $support_file => $asset_file ) {
    $support = source_file( $root, $support_file );
    check_contract( file_exists( $root . '/' . $asset_file ), $asset_file . ' exists' );
    check_contract(
        false !== strpos( $support, 'WEI_ALIPAY_' ) && false !== strpos( $support, '_PLUGIN_URL ) . $script_path' ),
        basename( $support_file ) . ' uses its module URL'
    );
}

$core = source_file( $root, 'inc/class-wc-alipay.php' );
$mini = source_file( $root, 'inc/modules/mini-program/class-wc-alipay-wap.php' );
check_contract( false !== strpos( $core, '$this->multi_currency_enabled ? get_woocommerce_currency() : $this->current_currency' ), 'core notices use effective checkout currency' );
check_contract( false !== strpos( $mini, '$this->multi_currency_enabled ? get_woocommerce_currency() : $this->current_currency' ), 'mini notices use effective checkout currency' );

$secrets = source_file( $root, 'inc/class-wei-alipay-secret-store.php' );
check_contract( false !== strpos( $secrets, 'cannot decrypt one or more stored payment secrets' ), 'secret decryption failures produce an operator health notice' );
check_contract( false !== strpos( $secrets, 'Configure WEI_ALIPAY_ENCRYPTION_KEY before cloning' ), 'salt-derived encryption warns before clone or restore' );

$updater = source_file( $root, 'includes/class-wenpai-updater.php' );
check_contract( false !== strpos( $updater, 'is_allowed_distribution_url' ), 'private updater validates package origin' );
check_contract( false !== strpos( $updater, "[ 'updates.wenpai.net', 'feicode.com' ]" ), 'private updater has an exact host allowlist' );
check_contract( false !== strpos( $updater, "esc_url( html_entity_decode" ), 'remote markdown links are protocol-sanitized' );

if ( $failures ) {
    exit( 1 );
}
