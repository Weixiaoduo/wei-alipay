<?php

$root = dirname( __DIR__ );

$expect = static function( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
};

$loader = file_get_contents( $root . '/wei-alipay.php' );
$features = file_get_contents( $root . '/inc/class-wei-alipay-features.php' );
$admin = file_get_contents( $root . '/inc/class-wei-alipay.php' );
$css = file_get_contents( $root . '/css/admin/features.css' );
$shared_css = file_get_contents( $root . '/css/admin/design-system.css' );
$js = file_get_contents( $root . '/js/admin/features.js' );
$placeholder_logo = file_get_contents( $root . '/assets/images/wei-alipay-placeholder.svg' );
$render_start = strpos( $features, 'public static function render()' );
$render_end = strpos( $features, 'private static function render_tabs', $render_start );
$render_shell = substr( $features, $render_start, $render_end - $render_start );

$gates = array(
	'profitsharing', 'fund_auth', 'reconcile', 'barcode', 'f2f', 'huabei',
	'subscriptions', 'mini_program', 'cross_border', 'webhook_retry',
);
foreach ( $gates as $key ) {
	$expect( false !== strpos( $loader, "Wei_Alipay_Features::is_enabled( '{$key}' )" ), "{$key} runtime is explicitly gated" );
}

$expect( false === strpos( $loader, "require_once WEI_ALIPAY_PLUGIN_PATH . 'enable-gateways.php'" ), 'legacy bulk-enable endpoint is not loaded' );
$expect( false !== strpos( $features, "const OPTION_KEY = 'wei_alipay_features'" ), 'feature allow-list uses a stable dedicated option' );
$expect( false !== strpos( $features, "check_admin_referer( 'wei_alipay_save_features' )" ), 'feature save requires a nonce' );
$expect( false !== strpos( $features, "current_user_can( 'manage_woocommerce' )" ), 'feature read and save require WooCommerce management permission' );
$expect( false !== strpos( $features, "map_deep( wp_unslash( \$_POST['features'] ), 'sanitize_text_field' )" ), 'posted feature keys are unslashed and deeply sanitized' );
$expect( false !== strpos( $features, "sanitize_key( (string) \$posted_feature )" ), 'posted feature keys are scalar and sanitized' );
$expect( false === strpos( $features, 'delete_option(' ), 'feature disable never deletes retained configuration' );
$expect( false !== strpos( $features, "'payment' === \$feature['group']" ), 'migration only enables payment modules from explicit gateway state' );
$expect( false !== strpos( $features, "isset( \$settings['enabled'] ) && 'yes' === \$settings['enabled']" ), 'migration discovers explicitly enabled gateway configuration' );
$expect( false !== strpos( $features, "const VERSION = '2'" ), 'feature policy migration has a distinct schema version' );
$expect( false !== strpos( $features, "'access' => 'restricted'" ), 'restricted payment products declare an explicit access policy' );
$expect( false !== strpos( $features, "if ( ! self::access_available( \$feature ) )" ), 'restricted products fail closed in the runtime gate' );
$expect( false !== strpos( $features, "if ( ! self::feature_access_available( \$feature ) )" ), 'save and migration reject restricted products rather than trusting posted options' );
$expect( false !== strpos( $features, "\$new[ \$key ] = 'no'" ), 'restricted products are persisted off while authorization is unavailable' );
$expect( false !== strpos( $features, "'tier' => 'free-connected'" ), 'free connected features declare an explicit product tier' );
$expect( false !== strpos( $features, "'tier' => 'commercial'" ), 'commercial features declare an explicit product tier' );
$expect( false !== strpos( $features, "'required_scope' => 'subscriptions'" ), 'commercial feature scopes are explicit in the registry' );
$expect( false !== strpos( $features, "Free after connection" ), 'free connected features have a stable merchant-facing state' );
$expect( false !== strpos( $features, "Commercial authorization required" ), 'commercial features have a distinct authorization state' );
$expect( false !== strpos( $features, "Request access from Weixiaoduo.com" ), 'restricted products link to the Weixiaoduo application surface' );
$expect( false !== strpos( $features, "'toplevel_page_wei-alipay'" ), 'feature assets are scoped to the independent settings page' );
$expect( false !== strpos( $admin, "Wei_Alipay_Features::render()" ), 'independent Wei Alipay page renders the feature centre' );
$expect( false !== strpos( $admin, "Wei_Alipay_Features::is_enabled( 'reconcile' )" ), 'fallback reconciliation menu is conditional' );
$expect( false !== strpos( $admin, 'Wei_Alipay_Features::maybe_migrate()' ), 'multisite activation migrates the feature allow-list per site' );

foreach ( array( '--wei-space-2', '--wei-color-accent', 'prefers-reduced-motion', '@media (max-width: 782px)' ) as $contract ) {
	$expect( false !== strpos( $shared_css, $contract ), "shared admin CSS includes {$contract}" );
}
$expect( false !== strpos( $js, "beforeunload" ), 'unsaved feature changes have a navigation guard' );
$expect( false !== strpos( $js, "submit.disabled = false" ), 'save action is enabled only after a change' );
$expect( false !== strpos( $features, "name=\"feature_group\"" ) && false !== strpos( $features, "\$new = \$old" ), 'separate feature tabs preserve values from the other feature group' );
$expect( false !== strpos( $features, "array( 'overview', 'payments', 'operations', 'account' )" ), 'the feature centre keeps four stable task-based views' );
$expect( false === strpos( $features, "\$tabs['services']" ) && false === strpos( $features, 'private static function render_services' ), 'the separate value-added-services tab and duplicate catalogue are removed' );
$expect( 2 === substr_count( $features, "'ui_group' => 'payment'" ), 'subscriptions and profit sharing are presented in payment methods without changing runtime identities' );
$expect( false !== strpos( $features, 'private static function feature_ui_group' ), 'merchant-facing grouping is explicit and separate from stable runtime grouping' );
$expect( false !== strpos( $features, "if ( \$group !== self::feature_ui_group( \$feature ) )" ), 'payment and operations forms save their merchant-facing groups independently' );
$expect( false !== strpos( $features, 'private static function render_enabled_payment_summary' ), 'enabled payment services use one compact summary block instead of dynamic tabs' );
$expect( false !== strpos( $features, 'Quick readiness check. Turn methods on or off in the list below.' ), 'the payment summary preserves the original list as the configuration source' );
$expect( false !== strpos( $features, '<details class="wei-alipay-disclosure">' ), 'account technical details use native progressive disclosure' );
$expect( false !== strpos( $features, 'Data shared when connected' ), 'the account view discloses required connection data on demand' );
$expect( false !== strpos( $features, 'Never sent:' ), 'the account view states the payment and credential data exclusion' );
$expect( false !== strpos( $features, 'Secure account connection is not available in this candidate yet' ), 'the candidate does not expose a fake or insecure account connection action' );
$expect( false === strpos( $features, 'wp_remote_' ), 'the presentation-only account view does not send external requests' );
$expect( false !== strpos( $features, "'avatar_url' => ''" ), 'the account presentation contract accepts a connector-provided avatar URL' );
$expect( false !== strpos( $features, "array( 'cravatar.com', 'www.cravatar.com', 'cravatar.cn', 'cn.cravatar.com' )" ), 'account avatars are restricted to explicit Cravatar HTTPS hosts' );
$expect( false !== strpos( $features, 'referrerpolicy="no-referrer"' ), 'connected account avatars suppress the referring admin URL' );
$expect( false === strpos( $features, 'md5(' ) && false === strpos( $features, 'sha256(' ), 'the payment plugin does not derive an avatar hash from account PII' );
$expect( false !== strpos( $features, "'connected' === \$account['status'] && \$account['avatar_url']" ), 'the remote avatar is rendered only for a connected account' );
$expect( false !== strpos( $features, 'private static function render_header_account_avatar' ), 'the connected identity is rendered in the shared page header' );
$expect( false !== strpos( $features, 'class="wei-alipay-header-logo"' ) && false !== strpos( $features, "assets/images/wei-alipay-placeholder.svg" ), 'the page title uses a local placeholder plugin logo' );
$expect( false !== $placeholder_logo && false !== strpos( $placeholder_logo, '<svg' ) && false === strpos( $placeholder_logo, '<image' ) && false === strpos( $placeholder_logo, ' href=' ), 'the placeholder logo is a self-contained local SVG' );
$expect( strpos( $features, 'class="wei-alipay-header-logo"' ) < strpos( $features, '<h1>' ) && strpos( $features, 'render_header_account_avatar( $account )' ) > strpos( $features, '<h1>' ), 'the plugin logo precedes the title and the connected account avatar follows its status' );
$expect( false !== strpos( $features, 'class="wei-alipay-header-avatar"' ) && false !== strpos( $features, 'width="28" height="28"' ), 'the header keeps a smaller bounded account avatar distinct from the plugin logo' );
$expect( false !== strpos( $features, 'wei-alipay-header-avatar is-fallback dashicons dashicons-admin-users' ), 'a connected account without an avatar URL has an accessible native fallback' );
$expect( false === strpos( $render_shell, 'Main gateway settings' ), 'the compact page header does not duplicate the gateway action from the payment status card' );
$expect( false !== strpos( $css, '.wei-alipay-features__header > div > p' ) && false === strpos( $css, '.wei-alipay-features__header > .button' ), 'the page header keeps one bounded identity column without a detached action' );
$expect( false !== strpos( $css, "background: transparent;\n\tborder: 0;" ), 'the identity header has no white banner surface or divider' );
$expect( false === strpos( $features, 'wei-alipay-overview-snapshot' ) && false === strpos( $css, '.wei-alipay-overview-snapshot' ), 'the redundant overview snapshot module is removed' );
$expect( false !== strpos( $features, 'Payment status' ) && false !== strpos( $features, 'Ready to accept payments' ), 'the overview leads with one payment conclusion' );
$expect( false !== strpos( $features, 'Account authorization' ), 'the overview reports account authorization state' );
$expect( false !== strpos( $features, 'array_slice( $attention, 0, 3 )' ), 'the overview limits visible issues to three priorities' );
$expect( false !== strpos( $features, '<details class="wei-alipay-disclosure wei-alipay-overview-disclosure"' ), 'diagnostic evidence uses native progressive disclosure' );
$expect( false !== strpos( $features, 'class="wei-alipay-overview-details"' ) && false !== strpos( $features, 'Setup details' ), 'overview disclosures share one labelled module container' );
$expect( false !== strpos( $features, '<summary><span>' ) && false !== strpos( $features, 'self::status_chip( $payment_ready' ), 'each collapsed module exposes its conclusion before expansion' );
$expect( false !== strpos( $css, '.wei-alipay-overview-details .wei-alipay-overview-disclosure:last-child' ), 'folded modules use one continuous border instead of disconnected cards' );
$expect( false !== strpos( $css, '.wei-alipay-overview-details .wei-alipay-overview-disclosure > div' ), 'expanded module content has one consistent inset surface' );
$expect( false !== strpos( $css, 'border-top: 1px dashed var(--wei-color-border)' ), 'expanded status rows use subtle dashed internal dividers' );
$expect( false !== strpos( $css, '.wei-alipay-overview-details .wei-alipay-overview-disclosure summary:focus-visible' ), 'fold controls retain a visible keyboard focus state' );
$expect( false !== strpos( $css, '.wei-alipay-overview-details .wei-alipay-overview-disclosure[open] summary' ), 'the open fold has a distinct native-like surface state' );
$expect( false !== strpos( $features, 'Payment environment and security' ), 'payment readiness evidence is disclosed on demand' );
$expect( false !== strpos( $features, 'Callbacks and background recovery' ), 'callback and background-job evidence is disclosed on demand' );
$expect( false !== strpos( $features, 'Payment activity in the last 7 days' ), 'bounded local payment activity is disclosed on demand' );
$expect( false !== strpos( $features, 'Compatibility and diagnostics' ), 'compatibility evidence is disclosed on demand' );
$expect( false !== strpos( $features, 'No payment sample recorded' ) && false !== strpos( $features, 'No callback sample' ), 'zero observations are labelled as missing samples rather than healthy results' );
$expect( false !== strpos( $features, '$payment_ready && $reliability_issue ? \' open\' : \'\'' ), 'only the highest-priority failing diagnostic group opens automatically' );
$expect( false !== strpos( $features, "wp_next_scheduled( 'woo_alipay_check_pending_orders' )" ), 'overview health reads the existing pending-order schedule' );
$expect( false !== strpos( $features, "wp_next_scheduled( 'woo_alipay_check_timeout_orders' )" ), 'overview health reads the existing timeout schedule' );
$expect( false !== strpos( $features, 'does not replace an Alipay sandbox acceptance test' ), 'a clean local overview does not imply provider acceptance' );
$expect( false !== strpos( $features, '$effective_enabled = $is_enabled && $available' ), 'missing dependencies produce an unavailable effective state' );
$expect( false !== strpos( $features, 'checked( $effective_enabled, true, false )' ), 'an unavailable feature never renders a misleading checked switch' );
$expect( false !== strpos( $features, 'aria-describedby=' ), 'disabled feature switches reference their dependency explanation' );
$expect( false !== strpos( $css, 'opacity: 0 !important' ) && false !== strpos( $css, 'clip-path: inset(50%)' ), 'native checkbox remains accessible but cannot leak beside the visual switch' );
$expect( false !== strpos( $css, 'input:disabled:checked + span::after' ), 'disabled switch thumb is forced to the off position' );

$tokens = json_decode( file_get_contents( $root . '/docs/design/woopayments-admin-tokens.json' ), true );
$expect( is_array( $tokens ) && 30 === count( $tokens['scope']['artifacts']['woopayments_css'] ), 'design inventory records the scoped WooPayments admin CSS artifacts' );
$expect( 27 === count( $tokens['scope']['artifacts']['woocommerce_css'] ), 'design inventory records the scoped WooCommerce admin CSS artifacts' );
$expect( isset( $tokens['inventories']['woopayments_css']['colors'][0]['occurrences'] ), 'design inventory includes deterministic raw token counts' );
