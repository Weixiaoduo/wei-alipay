<?php
/**
 * Optional feature registry and merchant-facing feature centre.
 *
 * @package WeiAlipay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wei_Alipay_Features {

	const OPTION_KEY = 'wei_alipay_features';
	const VERSION_KEY = 'wei_alipay_features_version';
	const VERSION = '2';

	/**
	 * Register feature-centre hooks.
	 */
	public static function register() {
		self::maybe_migrate();
		add_action( 'admin_post_wei_alipay_save_features', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'admin_title', array( __CLASS__, 'filter_admin_title' ), 10, 2 );
	}

	/**
	 * Keep the browser title aligned with the active URL-addressable view.
	 *
	 * @param string $admin_title Complete WordPress administration title.
	 * @param string $title       Registered menu-page title.
	 * @return string
	 */
	public static function filter_admin_title( $admin_title, $title ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.
		if ( 'wei-alipay' !== $page ) {
			return $admin_title;
		}
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.
		$labels = array(
			'overview' => __( 'Wei Alipay Overview', 'wei-alipay' ),
			'payments' => __( 'Wei Alipay Payment Methods', 'wei-alipay' ),
			'operations' => __( 'Wei Alipay Operational Tools', 'wei-alipay' ),
			'account' => __( 'Wei Alipay Account and Authorization', 'wei-alipay' ),
		);
		$label = isset( $labels[ $view ] ) ? $labels[ $view ] : $labels['overview'];
		return str_replace( (string) $title, $label, (string) $admin_title );
	}

	/**
	 * Return the complete first-party optional feature registry.
	 *
	 * Runtime identifiers are deliberately stable even though the UI is new.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function registry() {
		return array(
			'f2f' => array(
				'group' => 'payment',
				'tier' => 'free-connected',
				'required_scope' => 'f2f',
				'provider_requirement' => 'alipay-product',
				'name' => __( 'Alipay Face-to-Face Pay', 'wei-alipay' ),
				'description' => __( 'Accept merchant-presented QR code payments for counters and physical stores.', 'wei-alipay' ),
				'icon' => 'dashicons-store',
				'settings_option' => 'woocommerce_alipay_facetopay_settings',
				'settings_section' => 'alipay_facetopay',
				'access' => 'restricted',
				'requirement' => __( 'Requires the corresponding Alipay merchant product and Wei Alipay authorization.', 'wei-alipay' ),
				'requirements_url' => 'https://opendocs.alipay.com/open/194/105072',
			),
			'huabei' => array(
				'group' => 'payment',
				'tier' => 'free-connected',
				'required_scope' => 'huabei',
				'provider_requirement' => 'alipay-product',
				'name' => __( 'Alipay Huabei Installments', 'wei-alipay' ),
				'description' => __( 'Offer eligible customers 3, 6 or 12-installment Huabei payments.', 'wei-alipay' ),
				'icon' => 'dashicons-money-alt',
				'settings_option' => 'woocommerce_alipay_installment_settings',
				'settings_section' => 'alipay_installment',
				'access' => 'restricted',
				'requirement' => __( 'Requires Huabei admission, an eligible acquiring product and Wei Alipay authorization.', 'wei-alipay' ),
				'requirements_url' => 'https://opendocs.alipay.com/open/repo-00phph',
			),
			'barcode' => array(
				'group' => 'payment',
				'tier' => 'free-connected',
				'required_scope' => 'barcode',
				'provider_requirement' => 'alipay-product',
				'name' => __( 'Alipay Barcode Pay', 'wei-alipay' ),
				'description' => __( 'Accept customer-presented Alipay barcode or QR code payments.', 'wei-alipay' ),
				'icon' => 'dashicons-screenoptions',
				'settings_option' => 'woocommerce_alipay_barcode_settings',
				'settings_section' => 'alipay_barcode',
				'access' => 'restricted',
				'requirement' => __( 'Requires an eligible in-person Alipay product and Wei Alipay authorization.', 'wei-alipay' ),
				'requirements_url' => 'https://opendocs.alipay.com/open/194/105072',
			),
			'fund_auth' => array(
				'group' => 'payment',
				'tier' => 'specialist',
				'required_scope' => 'fund-auth',
				'provider_requirement' => 'alipay-review',
				'name' => __( 'Alipay Fund Authorization', 'wei-alipay' ),
				'description' => __( 'Freeze customer funds and capture or release them later.', 'wei-alipay' ),
				'icon' => 'dashicons-lock',
				'settings_option' => 'woocommerce_alipay_fund_auth_settings',
				'settings_section' => 'alipay_fund_auth',
				'access' => 'restricted',
				'requirement' => __( 'Requires Alipay fund-authorization admission and Wei Alipay authorization.', 'wei-alipay' ),
				'requirements_url' => 'https://opendocs.alipay.com/open/repo-0243e2',
			),
			'mini_program' => array(
				'group' => 'payment',
				'tier' => 'free-connected',
				'required_scope' => 'mini-program',
				'provider_requirement' => 'alipay-product',
				'name' => __( 'Alipay Mini Program', 'wei-alipay' ),
				'description' => __( 'Accept payments initiated in an Alipay Mini Program. RSA2 is the default protocol.', 'wei-alipay' ),
				'icon' => 'dashicons-smartphone',
				'settings_option' => 'woocommerce_alipay_wap_settings',
				'settings_section' => 'alipay_wap',
				'access' => 'restricted',
				'requirement' => __( 'Requires an approved Alipay Mini Program, an associated merchant account and Wei Alipay authorization.', 'wei-alipay' ),
				'requirements_url' => 'https://opendocs.alipay.com/mini/08dcbo',
			),
			'cross_border' => array(
				'group' => 'payment',
				'tier' => 'specialist',
				'required_scope' => 'cross-border',
				'provider_requirement' => 'alipay-plus-review',
				'name' => __( 'Alipay Cross-Border', 'wei-alipay' ),
				'description' => __( 'Accept supported non-CNY cross-border Alipay payments.', 'wei-alipay' ),
				'icon' => 'dashicons-airplane',
				'settings_option' => 'woocommerce_acbop_settings',
				'settings_section' => 'acbop',
				'access' => 'restricted',
				'requirement' => __( 'Requires separate Alipay+ cross-border onboarding and Wei Alipay authorization.', 'wei-alipay' ),
				'requirements_url' => 'https://global.alipay.com/developer/helpcenter',
			),
			'profitsharing' => array(
				'group' => 'operations',
				'ui_group' => 'payment',
				'tier' => 'commercial',
				'required_scope' => 'profit-sharing',
				'provider_requirement' => 'alipay-review',
				'name' => __( 'Profit Sharing', 'wei-alipay' ),
				'description' => __( 'Manage receivers, settlement rules and profit-sharing jobs.', 'wei-alipay' ),
				'icon' => 'dashicons-networking',
				'settings_option' => 'woo_alipay_ps_settings',
				'page' => 'wei-alipay-profitsharing',
				'access' => 'restricted',
				'requirement' => __( 'Requires the Alipay profit-sharing product, the required merchant relationships and Wei Alipay authorization.', 'wei-alipay' ),
				'requirements_url' => 'https://opendocs.alipay.com/open/009yja',
			),
			'reconcile' => array(
				'group' => 'operations',
				'tier' => 'local',
				'required_scope' => '',
				'provider_requirement' => 'configured-gateway',
				'name' => __( 'Reconciliation', 'wei-alipay' ),
				'description' => __( 'Review Alipay transactions and reconciliation results.', 'wei-alipay' ),
				'icon' => 'dashicons-chart-area',
				'settings_option' => 'woo_alipay_reconcile_pro_settings',
				'page' => 'wei-alipay-reconcile-pro',
			),
			'webhook_retry' => array(
				'group' => 'operations',
				'tier' => 'local',
				'required_scope' => '',
				'provider_requirement' => 'configured-gateway',
				'name' => __( 'Webhook Logs and Retry', 'wei-alipay' ),
				'description' => __( 'Inspect asynchronous notification delivery and retry failed processing.', 'wei-alipay' ),
				'icon' => 'dashicons-update',
				'settings_option' => 'woocommerce_alipay_settings',
				'settings_key' => 'enable_webhook_retry',
				'page' => 'wei-alipay-webhooks',
			),
			'subscriptions' => array(
				'group' => 'operations',
				'ui_group' => 'payment',
				'tier' => 'commercial',
				'required_scope' => 'subscriptions',
				'provider_requirement' => 'alipay-review',
				'name' => __( 'Subscriptions', 'wei-alipay' ),
				'description' => __( 'Use Alipay agreements for recurring WooCommerce Subscriptions charges.', 'wei-alipay' ),
				'icon' => 'dashicons-controls-repeat',
				'settings_option' => 'woo_alipay_subscriptions_settings',
				'page' => 'wei-alipay-subscriptions',
				'dependency' => 'woocommerce-subscriptions',
				'access' => 'restricted',
				'requirement' => __( 'Requires an eligible enterprise Alipay account, an approved recurring-debit product and Wei Alipay authorization.', 'wei-alipay' ),
				'requirements_url' => 'https://opendocs.alipay.com/open/repo-0243dw',
			),
		);
	}

	/**
	 * Whether an optional feature is enabled.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public static function is_enabled( $feature ) {
		if ( ! self::access_available( $feature ) ) {
			return false;
		}
		$values = get_option( self::OPTION_KEY, array() );
		return is_array( $values ) && isset( $values[ $feature ] ) && 'yes' === $values[ $feature ];
	}

	/**
	 * One-time, non-destructive migration from explicit existing configuration.
	 */
	public static function maybe_migrate() {
		if ( self::VERSION === get_option( self::VERSION_KEY ) && is_array( get_option( self::OPTION_KEY, null ) ) ) {
			return;
		}

		$current = get_option( self::OPTION_KEY, null );
		if ( ! is_array( $current ) ) {
			$current = array();
			foreach ( self::registry() as $key => $feature ) {
				$settings = get_option( $feature['settings_option'], null );
				$enabled = false;
				if ( is_array( $settings ) ) {
					if ( isset( $feature['settings_key'] ) ) {
						$enabled = isset( $settings[ $feature['settings_key'] ] ) && 'yes' === $settings[ $feature['settings_key'] ];
					} elseif ( 'payment' === $feature['group'] ) {
						$enabled = isset( $settings['enabled'] ) && 'yes' === $settings['enabled'];
					} else {
						$enabled = ! empty( $settings );
					}
				}
				$current[ $key ] = $enabled ? 'yes' : 'no';
			}
			update_option( self::OPTION_KEY, $current, false );
		}

		foreach ( self::registry() as $key => $feature ) {
			if ( ! self::feature_access_available( $feature ) ) {
				$current[ $key ] = 'no';
			}
		}
		update_option( self::OPTION_KEY, $current, false );
		update_option( self::VERSION_KEY, self::VERSION, false );
	}

	/**
	 * Save the explicit feature allow-list.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Wei Alipay.', 'wei-alipay' ) );
		}
		check_admin_referer( 'wei_alipay_save_features' );

		$group = isset( $_POST['feature_group'] ) ? sanitize_key( wp_unslash( $_POST['feature_group'] ) ) : '';
		if ( ! in_array( $group, array( 'payment', 'operations' ), true ) ) {
			wp_die( esc_html__( 'Invalid Wei Alipay feature group.', 'wei-alipay' ) );
		}

		$old = get_option( self::OPTION_KEY, array() );
		$old = is_array( $old ) ? $old : array();
		$posted = array();
		if ( isset( $_POST['features'] ) && is_array( $_POST['features'] ) ) {
			$submitted_features = map_deep( wp_unslash( $_POST['features'] ), 'sanitize_text_field' );
			foreach ( $submitted_features as $posted_feature ) {
				if ( is_scalar( $posted_feature ) ) {
					$posted[] = sanitize_key( (string) $posted_feature );
				}
			}
		}
		$new = $old;
		foreach ( self::registry() as $key => $feature ) {
			if ( $group !== self::feature_ui_group( $feature ) ) {
				continue;
			}
			if ( ! self::feature_access_available( $feature ) ) {
				$new[ $key ] = 'no';
				continue;
			}
			if ( ! self::dependency_available( $feature ) ) {
				$new[ $key ] = isset( $old[ $key ] ) ? $old[ $key ] : 'no';
				continue;
			}
			$new[ $key ] = in_array( $key, $posted, true ) ? 'yes' : 'no';
		}
		update_option( self::OPTION_KEY, $new, false );
		self::clear_disabled_schedules( $old, $new );

		$view = 'payment' === $group ? 'payments' : 'operations';
		wp_safe_redirect( add_query_arg( array( 'page' => 'wei-alipay', 'view' => $view, 'features-updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Load the shared design system on Wei Alipay admin surfaces.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		$is_feature_page = 'toplevel_page_wei-alipay' === $hook;
		$is_tool_page = 0 === strpos( (string) $hook, 'wei-alipay_page_wei-alipay-' );
		if ( ! $is_feature_page && ! $is_tool_page ) {
			return;
		}
		$design_css = WEI_ALIPAY_PLUGIN_PATH . 'css/admin/design-system.css';
		$design_js = WEI_ALIPAY_PLUGIN_PATH . 'js/admin/design-system.js';
		wp_enqueue_style( 'wei-alipay-design-system', WEI_ALIPAY_PLUGIN_URL . 'css/admin/design-system.css', array(), filemtime( $design_css ) );
		wp_enqueue_script( 'wei-alipay-design-system', WEI_ALIPAY_PLUGIN_URL . 'js/admin/design-system.js', array(), filemtime( $design_js ), true );
		wp_localize_script(
			'wei-alipay-design-system',
			'WeiAlipayDesign',
			array(
				'unsavedChanges' => __( 'Unsaved changes', 'wei-alipay' ),
			)
		);
		if ( ! $is_feature_page ) {
			return;
		}
		$css = WEI_ALIPAY_PLUGIN_PATH . 'css/admin/features.css';
		$js = WEI_ALIPAY_PLUGIN_PATH . 'js/admin/features.js';
		wp_enqueue_style( 'wei-alipay-features', WEI_ALIPAY_PLUGIN_URL . 'css/admin/features.css', array( 'wei-alipay-design-system' ), filemtime( $css ) );
		wp_enqueue_script( 'wei-alipay-features', WEI_ALIPAY_PLUGIN_URL . 'js/admin/features.js', array( 'wei-alipay-design-system' ), filemtime( $js ), true );
		wp_localize_script( 'wei-alipay-features', 'WeiAlipayFeatures', array( 'unsavedChanges' => __( 'Unsaved changes', 'wei-alipay' ) ) );
	}

	/**
	 * Render the merchant feature centre.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Wei Alipay.', 'wei-alipay' ) );
		}
		$account = self::account_state();
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation state.
		if ( ! in_array( $view, array( 'overview', 'payments', 'operations', 'account' ), true ) ) {
			$view = 'overview';
		}
		$enabled = get_option( self::OPTION_KEY, array() );
		$enabled = is_array( $enabled ) ? $enabled : array();
		$main = get_option( 'woocommerce_alipay_settings', array() );
		$main_active = is_array( $main ) && isset( $main['enabled'] ) && 'yes' === $main['enabled'];

		echo '<div class="wrap wei-alipay-admin wei-alipay-features">';
		echo '<div class="wei-alipay-features__header"><div><div class="wei-alipay-features__heading">';
		echo '<img class="wei-alipay-header-logo" src="' . esc_url( WEI_ALIPAY_PLUGIN_URL . 'assets/images/wei-alipay-placeholder.svg' ) . '" alt="" width="32" height="32">';
		echo '<h1>' . esc_html__( 'Wei Alipay', 'wei-alipay' ) . '</h1>' . wp_kses_post( self::status_chip( $account['status_label'], $account['status_class'] ) );
		self::render_header_account_avatar( $account );
		echo '</div><p>' . esc_html__( 'Manage Alipay checkout, optional payment methods, operational tools and your Weixiaoduo account.', 'wei-alipay' ) . '</p></div>';
		echo '</div>';
		Wei_Alipay_Admin_UI::wp_header_end();
		self::render_tabs( $view );
		if ( isset( $_GET['features-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- success notice only.
			Wei_Alipay_Admin_UI::notice_region(
				array(
					array(
						'message'     => esc_html__( 'Wei Alipay features saved.', 'wei-alipay' ),
						'type'        => 'success',
						'dismissible' => true,
					),
				),
				esc_html__( 'Wei Alipay notifications', 'wei-alipay' )
			);
		}

		if ( 'overview' === $view ) {
			self::render_overview( $main_active, $account );
		} elseif ( 'account' === $view ) {
			self::render_account( $account );
		} else {
			$group = 'payments' === $view ? 'payment' : 'operations';
			self::render_feature_form( $group, $enabled, $main_active );
		}
		echo '</div>';
	}

	/**
	 * Render the compact page navigation.
	 *
	 * @param string $active Active view.
	 */
	private static function render_tabs( $active ) {
		$tabs = array(
			'overview' => __( 'Overview', 'wei-alipay' ),
			'payments' => __( 'Payment methods', 'wei-alipay' ),
			'operations' => __( 'Operational tools', 'wei-alipay' ),
			'account' => __( 'Account and authorization', 'wei-alipay' ),
		);
		echo '<nav class="nav-tab-wrapper wei-alipay-tabs" aria-label="' . esc_attr__( 'Wei Alipay sections', 'wei-alipay' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$class = $active === $key ? 'nav-tab nav-tab-active' : 'nav-tab';
			$url = add_query_arg( array( 'page' => 'wei-alipay', 'view' => $key ), admin_url( 'admin.php' ) );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '"' . ( $active === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Render a short overview; detailed controls live in their own tabs.
	 *
	 * @param bool                 $main_active Core gateway state.
	 * @param array<string,string> $account     Account presentation state.
	 */
	private static function render_overview( $main_active, $account ) {
		$secret_health = Wei_Alipay_Secret_Store::main_secret_health();
		$gateway_ready = self::main_gateway_ready( $secret_health );
		$health = Wei_Alipay_Health::snapshot();
		$activity = Wei_Alipay_Health::activity_summary();
		$main_settings = get_option( 'woocommerce_alipay_settings', array() );
		$main_settings = is_array( $main_settings ) ? $main_settings : array();
		$sandbox = 'yes' === ( $main_settings['sandbox'] ?? 'no' );
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : get_option( 'woocommerce_currency', '' );
		$currency_ready = in_array( strtoupper( (string) $currency ), array( 'CNY', 'RMB' ), true );
		$site_secure = is_ssl();
		$order_query_scheduled = (bool) wp_next_scheduled( 'woo_alipay_check_pending_orders' );
		$timeout_scheduled = (bool) wp_next_scheduled( 'woo_alipay_check_timeout_orders' );
		$blocks_loaded = class_exists( 'WC_Alipay_Blocks_Support' );
		$hpos_enabled = false;
		$order_util = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
		if ( class_exists( $order_util ) && is_callable( array( $order_util, 'custom_orders_table_usage_is_enabled' ) ) ) {
			$hpos_enabled = (bool) $order_util::custom_orders_table_usage_is_enabled();
		}
		$failed_webhooks = count( $health['webhook']['failed_records'] );
		$last_webhook_at = absint( $health['webhook']['last_at'] );
		$last_webhook_success = $health['webhook']['last_success'];
		$last_webhook_label = $last_webhook_at ? self::relative_time_label( $last_webhook_at ) : __( 'No callback recorded', 'wei-alipay' );
		$last_webhook_class = null === $last_webhook_success ? 'light' : ( $last_webhook_success ? 'success' : 'warning' );
		$update_available = self::update_available();
		$payment_ready = $main_active && $gateway_ready && $currency_ready && $site_secure;
		$reliability_issue = $failed_webhooks || false === $last_webhook_success || ( $main_active && ( ! $order_query_scheduled || ! $timeout_scheduled ) );
		$activity_total = absint( $activity['paid'] ) + absint( $activity['failed'] );
		$attention = array();
		if ( 'unreadable' === $secret_health['status'] ) {
			$attention[] = array( __( 'The stored private key cannot be decrypted. Payments must remain disabled until the encryption key is restored.', 'wei-alipay' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=alipay' ), __( 'Review key health', 'wei-alipay' ) );
		}
		if ( ! $payment_ready ) {
			$attention[] = array( __( 'Complete gateway setup before accepting live payments.', 'wei-alipay' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=alipay' ), __( 'Open gateway settings', 'wei-alipay' ) );
		}
		if ( 'connected' !== $account['status'] || 'warning' === $account['authorization_class'] ) {
			$attention[] = array( __( 'Check your Weixiaoduo.com connection and cached feature authorization.', 'wei-alipay' ), add_query_arg( array( 'page' => 'wei-alipay', 'view' => 'account' ), admin_url( 'admin.php' ) ), __( 'Open account', 'wei-alipay' ) );
		}
		if ( $failed_webhooks || false === $last_webhook_success ) {
			$attention[] = array( __( 'Some recent payment callbacks need review.', 'wei-alipay' ), admin_url( 'admin.php?page=wei-alipay-webhooks&status=failed' ), __( 'Open callback logs', 'wei-alipay' ) );
		}
		if ( $main_active && ( ! $order_query_scheduled || ! $timeout_scheduled ) ) {
			$attention[] = array( __( 'Some payment recovery tasks are not scheduled.', 'wei-alipay' ), admin_url( 'admin.php?page=wc-status&tab=action-scheduler' ), __( 'Open scheduled actions', 'wei-alipay' ) );
		}
		if ( $update_available ) {
			$attention[] = array( __( 'A Wei Alipay update is available.', 'wei-alipay' ), admin_url( 'plugins.php' ), __( 'Open plugins', 'wei-alipay' ) );
		}
		$attention = array_slice( $attention, 0, 3 );
		$attention_count = count( $attention );
		/* translators: %d is the number of prioritized setup issues. */
		$attention_status = $attention_count ? sprintf( _n( '%d issue', '%d issues', $attention_count, 'wei-alipay' ), $attention_count ) : __( 'No local issues', 'wei-alipay' );

		echo '<div class="wei-alipay-summary-grid">';
		self::summary_card( __( 'Payment status', 'wei-alipay' ), $payment_ready ? __( 'Ready to accept payments', 'wei-alipay' ) : __( 'Action needed', 'wei-alipay' ), $payment_ready ? 'success' : 'warning', $payment_ready ? __( 'Gateway credentials and security checks passed.', 'wei-alipay' ) : __( 'Complete the blocking gateway checks before accepting live payments.', 'wei-alipay' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=alipay' ), __( 'Open gateway settings', 'wei-alipay' ) );
		self::summary_card( __( 'Account authorization', 'wei-alipay' ), $account['status_label'], $account['status_class'], __( 'Weixiaoduo account connection is separate from Alipay payment processing.', 'wei-alipay' ), add_query_arg( array( 'page' => 'wei-alipay', 'view' => 'account' ), admin_url( 'admin.php' ) ), __( 'Open account', 'wei-alipay' ) );
		self::summary_card( __( 'Needs attention', 'wei-alipay' ), $attention_status, $attention_count ? 'warning' : 'success', $attention_count ? __( 'Up to three setup issues are listed below.', 'wei-alipay' ) : __( 'No blocking setup issues were found.', 'wei-alipay' ), $attention_count ? '#wei-alipay-attention' : '#wei-alipay-diagnostics', $attention_count ? __( 'View issues', 'wei-alipay' ) : __( 'View details', 'wei-alipay' ) );
		echo '</div>';

		if ( $attention_count ) {
			echo '<section class="notice notice-warning inline wei-alipay-overview-attention" id="wei-alipay-attention"><h2>' . esc_html__( 'Needs attention', 'wei-alipay' ) . '</h2><ul class="wei-alipay-attention-list">';
			foreach ( $attention as $item ) {
				echo '<li><span>' . esc_html( $item[0] ) . '</span><a href="' . esc_url( $item[1] ) . '">' . esc_html( $item[2] ) . '</a></li>';
			}
			echo '</ul></section>';
		}

		echo '<div class="wei-alipay-overview-details" id="wei-alipay-diagnostics" aria-label="' . esc_attr__( 'Setup details', 'wei-alipay' ) . '">';
		echo '<details class="wei-alipay-disclosure wei-alipay-overview-disclosure"' . ( $payment_ready ? '' : ' open' ) . '><summary><span>' . esc_html__( 'Payment environment and security', 'wei-alipay' ) . '</span>' . wp_kses_post( self::status_chip( $payment_ready ? __( 'Ready', 'wei-alipay' ) : __( 'Action needed', 'wei-alipay' ), $payment_ready ? 'success' : 'warning' ) ) . '</summary><div><p>' . esc_html__( 'Local checks only. Opening this section does not contact Alipay.', 'wei-alipay' ) . '</p><div class="wei-alipay-overview-list">';
		self::overview_status_row( __( 'Payment environment', 'wei-alipay' ), $sandbox ? __( 'Sandbox', 'wei-alipay' ) : __( 'Live', 'wei-alipay' ), $sandbox ? 'light' : 'warning' );
		self::overview_status_row( __( 'Gateway enabled', 'wei-alipay' ), $main_active ? __( 'Enabled', 'wei-alipay' ) : __( 'Disabled', 'wei-alipay' ), $main_active ? 'success' : 'warning' );
		self::overview_status_row( __( 'Merchant credentials', 'wei-alipay' ), $gateway_ready ? __( 'Configured', 'wei-alipay' ) : __( 'Configuration required', 'wei-alipay' ), $gateway_ready ? 'success' : 'warning' );
		self::overview_status_row( __( 'Private key protection', 'wei-alipay' ), self::secret_protection_label( $secret_health ), self::secret_protection_class( $secret_health ) );
		self::overview_status_row( __( 'Store currency', 'wei-alipay' ), $currency ? strtoupper( (string) $currency ) : __( 'Unknown', 'wei-alipay' ), $currency_ready ? 'success' : 'warning' );
		self::overview_status_row( __( 'Secure callback URL', 'wei-alipay' ), $site_secure ? __( 'HTTPS', 'wei-alipay' ) : __( 'HTTPS required', 'wei-alipay' ), $site_secure ? 'success' : 'warning' );
		echo '</div><a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=alipay' ) ) . '">' . esc_html__( 'Open gateway settings', 'wei-alipay' ) . '</a></div></details>';

		$last_error_code = (string) $health['webhook']['last_error_code'];
		$reliability_label = $reliability_issue ? __( 'Review required', 'wei-alipay' ) : ( $last_webhook_at ? __( 'No issue recorded', 'wei-alipay' ) : __( 'No callback sample', 'wei-alipay' ) );
		echo '<details class="wei-alipay-disclosure wei-alipay-overview-disclosure"' . ( $payment_ready && $reliability_issue ? ' open' : '' ) . '><summary><span>' . esc_html__( 'Callbacks and background recovery', 'wei-alipay' ) . '</span>' . wp_kses_post( self::status_chip( $reliability_label, $reliability_issue ? 'warning' : 'light' ) ) . '</summary><div><p>' . esc_html__( 'No callback sample means no data yet, not that callbacks are broken.', 'wei-alipay' ) . '</p><div class="wei-alipay-overview-list">';
		self::overview_status_row( __( 'Last callback', 'wei-alipay' ), $last_webhook_label, $last_webhook_class );
		self::overview_status_row( __( 'Last callback issue', 'wei-alipay' ), $last_error_code ? str_replace( '_', ' ', $last_error_code ) : __( 'None recorded', 'wei-alipay' ), $last_error_code ? 'warning' : 'light' );
		self::overview_status_row( __( 'Unresolved callback failures', 'wei-alipay' ), number_format_i18n( $failed_webhooks ), $failed_webhooks ? 'warning' : 'light' );
		self::overview_status_row( __( 'Last pending-order check', 'wei-alipay' ), self::job_status_label( $health['jobs']['order_query'], $order_query_scheduled ), $order_query_scheduled ? 'light' : ( $main_active ? 'warning' : 'light' ) );
		self::overview_status_row( __( 'Last timeout check', 'wei-alipay' ), self::job_status_label( $health['jobs']['timeout'], $timeout_scheduled ), $timeout_scheduled ? 'light' : ( $main_active ? 'warning' : 'light' ) );
		echo '</div><a href="' . esc_url( admin_url( 'admin.php?page=wei-alipay-webhooks' ) ) . '">' . esc_html__( 'Open callback logs', 'wei-alipay' ) . '</a></div></details>';

		echo '<details class="wei-alipay-disclosure wei-alipay-overview-disclosure"><summary><span>' . esc_html__( 'Compatibility and diagnostics', 'wei-alipay' ) . '</span>' . wp_kses_post( self::status_chip( $blocks_loaded ? __( 'Integration loaded', 'wei-alipay' ) : __( 'Not loaded', 'wei-alipay' ), $blocks_loaded ? 'light' : 'warning' ) ) . '</summary><div><div class="wei-alipay-overview-list">';
		self::overview_status_row( __( 'Order storage', 'wei-alipay' ), $hpos_enabled ? __( 'HPOS enabled', 'wei-alipay' ) : __( 'WordPress posts mode', 'wei-alipay' ), 'light' );
		self::overview_status_row( __( 'Checkout Blocks', 'wei-alipay' ), $blocks_loaded ? __( 'Integration loaded', 'wei-alipay' ) : __( 'Not loaded', 'wei-alipay' ), $blocks_loaded ? 'success' : 'warning' );
		self::overview_status_row( __( 'Software versions', 'wei-alipay' ), self::version_summary(), 'light' );
		self::overview_status_row( __( 'Plugin update', 'wei-alipay' ), $update_available ? __( 'Update available', 'wei-alipay' ) : __( 'No pending update', 'wei-alipay' ), $update_available ? 'warning' : 'light' );
		echo '</div><a href="' . esc_url( admin_url( 'admin.php?page=wc-status' ) ) . '">' . esc_html__( 'Open WooCommerce status', 'wei-alipay' ) . '</a></div></details>';

		$orders_url = $hpos_enabled ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
		echo '<details class="wei-alipay-disclosure wei-alipay-overview-disclosure"><summary><span>' . esc_html__( 'Payment activity in the last 7 days', 'wei-alipay' ) . '</span>' . wp_kses_post( self::status_chip( $activity_total ? __( 'Events recorded', 'wei-alipay' ) : __( 'No payment sample recorded', 'wei-alipay' ), 'light' ) ) . '</summary><div><p>' . esc_html__( 'Counts successful, failed and refunded payments from this plugin only. Amounts and customer data are not stored.', 'wei-alipay' ) . '</p><div class="wei-alipay-overview-list">';
		self::overview_status_row( __( 'Activity sample', 'wei-alipay' ), $activity_total ? __( 'Events recorded', 'wei-alipay' ) : __( 'No payment sample recorded', 'wei-alipay' ), $activity_total ? 'success' : 'light' );
		self::overview_status_row( __( 'Successful payments', 'wei-alipay' ), number_format_i18n( $activity['paid'] ), $activity['paid'] ? 'success' : 'light' );
		self::overview_status_row( __( 'Failed payments', 'wei-alipay' ), number_format_i18n( $activity['failed'] ), $activity['failed'] ? 'warning' : 'light' );
		self::overview_status_row( __( 'Refunds created', 'wei-alipay' ), number_format_i18n( $activity['refunded'] ), 'light' );
		self::overview_status_row( __( 'Payment success rate', 'wei-alipay' ), null === $activity['success_rate'] ? __( 'Not enough data', 'wei-alipay' ) : $activity['success_rate'] . '%', null === $activity['success_rate'] ? 'light' : ( $activity['success_rate'] >= 95 ? 'success' : 'warning' ) );
		echo '</div><a href="' . esc_url( $orders_url ) . '">' . esc_html__( 'Open WooCommerce orders', 'wei-alipay' ) . '</a></div></details>';
		echo '</div><p class="description wei-alipay-overview-caveat">' . esc_html__( 'Passing local checks does not replace an Alipay sandbox acceptance test.', 'wei-alipay' ) . '</p>';
	}

	/**
	 * Check whether the core gateway has the three merchant values it requires.
	 *
	 * This reads presence only and never exposes or decrypts credential values.
	 *
	 * @return bool
	 */
	private static function main_gateway_ready( $secret_health ) {
		$settings = get_option( 'woocommerce_alipay_settings', array() );
		if ( ! is_array( $settings ) ) {
			return false;
		}
		foreach ( array( 'appid', 'public_key' ) as $key ) {
			if ( empty( $settings[ $key ] ) || ! is_string( $settings[ $key ] ) ) {
				return false;
			}
		}
		return 'ready' === ( $secret_health['status'] ?? 'missing' );
	}

	/**
	 * Present the private-key storage mode without exposing key material.
	 */
	private static function secret_protection_label( $health ) {
		$status = isset( $health['status'] ) ? $health['status'] : 'missing';
		$protection = isset( $health['protection'] ) ? $health['protection'] : 'none';
		if ( 'unreadable' === $status ) {
			return __( 'Encrypted key unreadable', 'wei-alipay' );
		}
		if ( 'missing' === $status ) {
			return __( 'Private key missing', 'wei-alipay' );
		}
		$labels = array(
			'external' => __( 'External secret provider', 'wei-alipay' ),
			'dedicated' => __( 'Dedicated encryption key', 'wei-alipay' ),
			'salts' => __( 'Protected by WordPress salts', 'wei-alipay' ),
			'plaintext' => __( 'Plaintext migration required', 'wei-alipay' ),
		);
		return isset( $labels[ $protection ] ) ? $labels[ $protection ] : __( 'Protection state unknown', 'wei-alipay' );
	}

	private static function secret_protection_class( $health ) {
		if ( 'ready' !== ( $health['status'] ?? 'missing' ) || 'plaintext' === ( $health['protection'] ?? 'none' ) ) {
			return 'warning';
		}
		return 'success';
	}

	/**
	 * Format a timestamp as a bounded relative time.
	 */
	private static function relative_time_label( $timestamp ) {
		/* translators: %s is a human-readable time difference such as "5 minutes". */
		return sprintf( __( '%s ago', 'wei-alipay' ), human_time_diff( absint( $timestamp ), time() ) );
	}

	/**
	 * Describe whether a recurring job is scheduled and has been observed.
	 */
	private static function job_status_label( $last_run, $scheduled ) {
		if ( $last_run ) {
			return self::relative_time_label( $last_run );
		}
		return $scheduled ? __( 'Scheduled; no run recorded', 'wei-alipay' ) : __( 'Not scheduled', 'wei-alipay' );
	}

	/**
	 * Return a compact local runtime version string.
	 */
	private static function version_summary() {
		global $wp_version;
		$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : __( 'unknown', 'wei-alipay' );
		return sprintf(
			'WP %1$s · WC %2$s · PHP %3$s · Wei %4$s',
			(string) $wp_version,
			(string) $wc_version,
			PHP_VERSION,
			defined( 'WEI_ALIPAY_VERSION' ) ? WEI_ALIPAY_VERSION : __( 'unknown', 'wei-alipay' )
		);
	}

	/**
	 * Read the existing WordPress update cache without making a remote request.
	 */
	private static function update_available() {
		$updates = get_site_transient( 'update_plugins' );
		$basename = defined( 'WEI_ALIPAY_PLUGIN_FILE' ) ? plugin_basename( WEI_ALIPAY_PLUGIN_FILE ) : 'wei-alipay/wei-alipay.php';
		return is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) && isset( $updates->response[ $basename ] );
	}

	/**
	 * Render one compact overview fact.
	 */
	private static function overview_status_row( $label, $status, $class ) {
		echo '<div><span>' . esc_html( $label ) . '</span>' . wp_kses_post( self::status_chip( $status, $class ) ) . '</div>';
	}

	/**
	 * Render a single overview card.
	 */
	private static function summary_card( $title, $status, $class, $description, $url, $action ) {
		echo '<section class="wei-alipay-summary-card"><div><h2>' . esc_html( $title ) . '</h2>' . wp_kses_post( self::status_chip( $status, $class ) ) . '<p>' . esc_html( $description ) . '</p></div><a href="' . esc_url( $url ) . '">' . esc_html( $action ) . '</a></section>';
	}

	/**
	 * Render the account page with native progressive disclosure.
	 *
	 * @param array<string,string> $account Account presentation state.
	 */
	private static function render_account( $account ) {
		echo '<div class="wei-alipay-account">';
		echo '<section class="wei-alipay-account__primary"><div class="wei-alipay-account__identity">';
		self::render_account_avatar( $account );
		echo '<div><div class="wei-alipay-features__title">' . esc_html__( 'Weixiaoduo account', 'wei-alipay' ) . ' ' . wp_kses_post( self::status_chip( $account['status_label'], $account['status_class'] ) ) . '</div>';
		if ( 'connected' === $account['status'] ) {
			echo '<p>' . esc_html( $account['account_label'] ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Connect your Weixiaoduo account to unlock free features, commercial authorization and updates.', 'wei-alipay' ) . '</p>';
		}
		echo '</div></div><div class="wei-alipay-account__actions">';
		if ( 'connected' === $account['status'] && $account['manage_url'] ) {
			echo '<a class="button button-primary" href="' . esc_url( $account['manage_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Manage on Weixiaoduo.com', 'wei-alipay' ) . '</a>';
		} else {
			echo '<button type="button" class="button button-primary" disabled aria-describedby="wei-alipay-connect-help">' . esc_html__( 'Connect account', 'wei-alipay' ) . '</button><span id="wei-alipay-connect-help" class="description">' . esc_html__( 'Secure account connection is not available in this candidate yet. Production features remain locked.', 'wei-alipay' ) . '</span>';
		}
		echo '</div></section>';

		echo '<section class="wei-alipay-account__entitlements"><h2>' . esc_html__( 'Feature access', 'wei-alipay' ) . '</h2><div class="wei-alipay-account__status-list">';
		self::account_status_row( __( 'Standard store features', 'wei-alipay' ), 'connected' === $account['status'] ? __( 'Free authorization', 'wei-alipay' ) : __( 'Free after connection', 'wei-alipay' ), 'connected' === $account['status'] ? 'success' : 'light' );
		self::account_status_row( __( 'Subscriptions and profit sharing', 'wei-alipay' ), __( 'Commercial authorization required', 'wei-alipay' ), 'warning' );
		echo '</div></section>';

		echo '<details class="wei-alipay-disclosure"><summary>' . esc_html__( 'Data shared when connected', 'wei-alipay' ) . '</summary><div><p>' . esc_html__( 'Required connection data is limited to the site host, installation identifier, software versions, locale, store currency, enabled feature scopes and connection timestamps.', 'wei-alipay' ) . '</p><p><strong>' . esc_html__( 'Never sent:', 'wei-alipay' ) . '</strong> ' . esc_html__( 'orders, payment amounts, customer details, Alipay private keys, certificates or notification payloads.', 'wei-alipay' ) . '</p><a href="' . esc_url( 'https://weixiaoduo.com/product/wei-alipay.html#privacy' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Read the data and privacy notice', 'wei-alipay' ) . '</a></div></details>';

		echo '<details class="wei-alipay-disclosure"><summary>' . esc_html__( 'Connection details', 'wei-alipay' ) . '</summary><div class="wei-alipay-account__facts"><dl><dt>' . esc_html__( 'Site', 'wei-alipay' ) . '</dt><dd>' . esc_html( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) . '</dd><dt>' . esc_html__( 'Installation ID', 'wei-alipay' ) . '</dt><dd>' . esc_html( $account['installation_label'] ) . '</dd><dt>' . esc_html__( 'Authorized features', 'wei-alipay' ) . '</dt><dd>' . esc_html( $account['scope_summary'] ) . '</dd><dt>' . esc_html__( 'Authorization expires', 'wei-alipay' ) . '</dt><dd>' . esc_html( $account['expires_label'] ) . '</dd><dt>' . esc_html__( 'Offline grace', 'wei-alipay' ) . '</dt><dd>' . esc_html( $account['offline_grace_label'] ) . '</dd><dt>' . esc_html__( 'Last successful sync', 'wei-alipay' ) . '</dt><dd>' . esc_html( $account['last_sync_label'] ) . '</dd></dl></div></details>';

		if ( 'connected' === $account['status'] && $account['disconnect_url'] ) {
			echo '<details class="wei-alipay-disclosure is-danger"><summary>' . esc_html__( 'Disconnect this site', 'wei-alipay' ) . '</summary><div><p>' . esc_html__( 'Disconnecting revokes new feature authorization. Existing payment callbacks and recovery operations must remain available.', 'wei-alipay' ) . '</p><a class="button" href="' . esc_url( $account['disconnect_url'] ) . '">' . esc_html__( 'Review disconnection', 'wei-alipay' ) . '</a></div></details>';
		}
		echo '</div>';
	}

	private static function account_status_row( $label, $status, $class ) {
		echo '<div><span>' . esc_html( $label ) . '</span>' . wp_kses_post( self::status_chip( $status, $class ) ) . '</div>';
	}

	/**
	 * Account state is presentation-only until the reviewed Wei Account connector lands.
	 *
	 * @return array<string,mixed>
	 */
	private static function account_state() {
		$defaults = array(
			'status' => 'not-connected',
			'status_label' => __( 'Not connected', 'wei-alipay' ),
			'status_class' => 'light',
			'account_label' => '',
			'installation_label' => __( 'Created when connected', 'wei-alipay' ),
			'last_sync_label' => __( 'Never', 'wei-alipay' ),
			'manage_url' => '',
			'disconnect_url' => '',
			'avatar_url' => '',
			'avatar_alt' => __( 'Weixiaoduo account avatar', 'wei-alipay' ),
			'authorization_class' => 'light',
			'scope_summary' => __( 'No authorized features cached', 'wei-alipay' ),
			'expires_label' => __( 'Not available', 'wei-alipay' ),
			'offline_grace_label' => __( 'Not available', 'wei-alipay' ),
		);
		$state = apply_filters( 'wei_alipay_account_state', $defaults );
		if ( ! is_array( $state ) ) {
			return $defaults;
		}
		$state = array_merge( $defaults, array_intersect_key( $state, $defaults ) );
		$state['avatar_url'] = self::sanitize_cravatar_url( $state['avatar_url'] );
		return $state;
	}

	/**
	 * Whether the presentation adapter reports an established account connection.
	 *
	 * This controls navigation only and is never used as the runtime entitlement gate.
	 *
	 * @param array<string,mixed> $account Account presentation state.
	 * @return bool
	 */
	private static function account_connected( $account ) {
		return isset( $account['status'] ) && 'connected' === $account['status'];
	}

	/**
	 * Show the connected Weixiaoduo identity in the shared page header.
	 *
	 * @param array<string,string> $account Account presentation state.
	 */
	private static function render_header_account_avatar( $account ) {
		if ( ! self::account_connected( $account ) ) {
			return;
		}
		if ( $account['avatar_url'] ) {
			echo '<img class="wei-alipay-header-avatar" src="' . esc_url( $account['avatar_url'] ) . '" alt="' . esc_attr( $account['avatar_alt'] ) . '" width="28" height="28" loading="lazy" decoding="async" referrerpolicy="no-referrer">';
			return;
		}
		echo '<span class="wei-alipay-header-avatar is-fallback dashicons dashicons-admin-users" role="img" aria-label="' . esc_attr( $account['avatar_alt'] ) . '"></span>';
	}

	/**
	 * Render the account avatar without deriving or exposing an email address.
	 *
	 * The reviewed Wei Account connector supplies a pre-built Cravatar URL through
	 * wei_alipay_account_state. No avatar request is made when the site is not connected.
	 *
	 * @param array<string,string> $account Account presentation state.
	 */
	private static function render_account_avatar( $account ) {
		if ( 'connected' === $account['status'] && $account['avatar_url'] ) {
			echo '<img class="wei-alipay-account__avatar" src="' . esc_url( $account['avatar_url'] ) . '" alt="' . esc_attr( $account['avatar_alt'] ) . '" width="40" height="40" loading="lazy" decoding="async" referrerpolicy="no-referrer">';
			return;
		}
		echo '<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>';
	}

	/**
	 * Accept only HTTPS Cravatar URLs supplied by the account connector.
	 *
	 * @param mixed $url Candidate avatar URL.
	 * @return string
	 */
	private static function sanitize_cravatar_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return '';
		}
		$allowed_hosts = apply_filters( 'wei_alipay_cravatar_hosts', array( 'cravatar.com', 'www.cravatar.com', 'cravatar.cn', 'cn.cravatar.com' ) );
		$allowed_hosts = is_array( $allowed_hosts ) ? array_map( 'strtolower', array_map( 'strval', $allowed_hosts ) ) : array();
		if ( ! in_array( strtolower( $parts['host'] ), $allowed_hosts, true ) ) {
			return '';
		}
		return esc_url_raw( $url, array( 'https' ) );
	}

	private static function status_chip( $label, $class ) {
		return '<span class="wei-alipay-chip is-' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Render one feature group. Saving this form leaves the other group intact.
	 */
	private static function render_feature_form( $group, $enabled, $main_active ) {
		echo '<div class="notice notice-info inline" role="status"><p><strong>' . esc_html__( 'How activation works', 'wei-alipay' ) . '</strong> ' . esc_html__( 'Features stay off until dependencies, Alipay product approval and Wei Alipay authorization are all satisfied.', 'wei-alipay' ) . '</p></div>';
		if ( 'payment' === $group ) {
			self::render_enabled_payment_summary( $enabled );
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wei-alipay-feature-form>';
		echo '<input type="hidden" name="action" value="wei_alipay_save_features">';
		echo '<input type="hidden" name="feature_group" value="' . esc_attr( $group ) . '">';
		wp_nonce_field( 'wei_alipay_save_features' );
		if ( 'payment' === $group ) {
			self::render_required_gateway( $main_active );
			self::render_group( 'payment', __( 'Optional payment methods', 'wei-alipay' ), __( 'Enabled methods appear in WooCommerce → Settings → Payments.', 'wei-alipay' ), $enabled );
		} else {
			self::render_group( 'operations', __( 'Operational tools', 'wei-alipay' ), __( 'Disabled tools hide their admin pages and stop background jobs.', 'wei-alipay' ), $enabled );
		}

		echo '<div class="wei-alipay-features__actions"><span class="wei-alipay-features__dirty" aria-live="polite"></span><button type="submit" class="button button-primary" disabled>' . esc_html__( 'Save feature settings', 'wei-alipay' ) . '</button></div>';
		echo '</form>';
	}

	private static function render_required_gateway( $active ) {
		$status = $active ? __( 'Active', 'wei-alipay' ) : __( 'Action needed', 'wei-alipay' );
		$class = $active ? 'success' : 'warning';
		echo '<section class="wei-alipay-features__section"><div class="wei-alipay-features__section-copy"><h2>' . esc_html__( 'Main payment gateway', 'wei-alipay' ) . '</h2><p>' . esc_html__( 'Standard Alipay checkout. Configure credentials in WooCommerce gateway settings.', 'wei-alipay' ) . '</p></div>';
		echo '<div class="wei-alipay-features__card"><div class="wei-alipay-features__row is-required"><span class="dashicons dashicons-money-alt" aria-hidden="true"></span><div class="wei-alipay-features__row-copy"><div class="wei-alipay-features__title">' . esc_html__( 'Alipay', 'wei-alipay' ) . ' <span class="wei-alipay-chip is-' . esc_attr( $class ) . '">' . esc_html( $status ) . '</span></div><p>' . esc_html__( 'Standard Alipay checkout for CNY stores.', 'wei-alipay' ) . '</p></div><span class="wei-alipay-chip is-light">' . esc_html__( 'Required', 'wei-alipay' ) . '</span></div></div></section>';
	}

	/**
	 * Show one compact status block when optional payment services are active.
	 *
	 * The block never replaces the existing payment-method list or creates
	 * dynamic navigation. Only effectively available features are summarized.
	 *
	 * @param array<string,string> $enabled Stored optional-feature preferences.
	 */
	private static function render_enabled_payment_summary( $enabled ) {
		$active = array();
		foreach ( self::registry() as $key => $feature ) {
			if ( 'payment' !== self::feature_ui_group( $feature ) || 'yes' !== ( $enabled[ $key ] ?? 'no' ) ) {
				continue;
			}
			if ( ! self::dependency_available( $feature ) || ! self::feature_access_available( $feature ) ) {
				continue;
			}
			$active[] = $feature;
		}
		if ( empty( $active ) ) {
			return;
		}

		echo '<section class="wei-alipay-overview-panel is-wide"><h2>' . esc_html__( 'Enabled payment services', 'wei-alipay' ) . '</h2><p>' . esc_html__( 'Quick readiness check. Turn methods on or off in the list below.', 'wei-alipay' ) . '</p><div class="wei-alipay-overview-list">';
		foreach ( $active as $feature ) {
			$configured = self::is_configured( $feature );
			self::overview_status_row( $feature['name'], $configured ? __( 'Ready', 'wei-alipay' ) : __( 'Configuration required', 'wei-alipay' ), $configured ? 'success' : 'warning' );
		}
		echo '</div></section>';
	}

	private static function render_group( $group, $title, $description, $enabled ) {
		echo '<section class="wei-alipay-features__section"><div class="wei-alipay-features__section-copy"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $description ) . '</p></div><div class="wei-alipay-features__card">';
		foreach ( self::registry() as $key => $feature ) {
			if ( $group !== self::feature_ui_group( $feature ) ) {
				continue;
			}
			$is_enabled = isset( $enabled[ $key ] ) && 'yes' === $enabled[ $key ];
			$dependency_available = self::dependency_available( $feature );
			$access_available = self::feature_access_available( $feature );
			$available = $dependency_available && $access_available;
			$effective_enabled = $is_enabled && $available;
			$configured = self::is_configured( $feature );
			$status = $effective_enabled ? __( 'Enabled', 'wei-alipay' ) : __( 'Inactive', 'wei-alipay' );
			$status_class = $effective_enabled ? 'success' : 'light';
			if ( $effective_enabled && ! $configured ) {
				$status = __( 'Action needed', 'wei-alipay' );
				$status_class = 'warning';
			}
			if ( ! $dependency_available ) {
				$status = __( 'Dependency missing', 'wei-alipay' );
				$status_class = 'warning';
			} elseif ( ! $access_available ) {
				$status = self::locked_status_label( $feature );
				$status_class = 'warning';
			}
			$dependency_id = 'wei-alipay-feature-' . sanitize_html_class( $key ) . '-dependency';
			echo '<div class="wei-alipay-features__row' . ( $available ? '' : ' is-unavailable' ) . '"' . ( $available ? '' : ' aria-disabled="true"' ) . '>';
			echo '<span class="dashicons ' . esc_attr( $feature['icon'] ) . '" aria-hidden="true"></span><div class="wei-alipay-features__row-copy"><div class="wei-alipay-features__title">' . esc_html( $feature['name'] ) . ' <span class="wei-alipay-chip is-' . esc_attr( $status_class ) . '" data-feature-status data-on="' . esc_attr__( 'Enabled', 'wei-alipay' ) . '" data-off="' . esc_attr__( 'Inactive', 'wei-alipay' ) . '">' . esc_html( $status ) . '</span></div><p>' . esc_html( $feature['description'] ) . '</p>';
			if ( ! $dependency_available ) {
				echo '<p class="wei-alipay-features__dependency" id="' . esc_attr( $dependency_id ) . '"><span class="dashicons dashicons-warning" aria-hidden="true"></span>' . esc_html__( 'Install and activate WooCommerce Subscriptions before enabling this feature.', 'wei-alipay' ) . '</p>';
			} elseif ( ! $access_available ) {
				echo '<details class="wei-alipay-feature-disclosure" id="' . esc_attr( $dependency_id ) . '"><summary>' . esc_html__( 'View requirements', 'wei-alipay' ) . '</summary><div><p>' . esc_html( $feature['requirement'] ) . '</p><p><a href="' . esc_url( $feature['requirements_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Alipay product requirements', 'wei-alipay' ) . '</a><span aria-hidden="true"> · </span><a href="' . esc_url( self::application_url( $key ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Request access from Weixiaoduo.com', 'wei-alipay' ) . '</a></p></div></details>';
			}
			if ( $effective_enabled ) {
				$url = self::configuration_url( $feature );
				if ( $url ) {
					echo '<p class="wei-alipay-features__configure"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Configure', 'wei-alipay' ) . '</a></p>';
				}
			}
			/* translators: %s is the optional Wei Alipay feature name. */
			$toggle_label = sprintf( __( 'Enable %s', 'wei-alipay' ), $feature['name'] );
			echo '</div><label class="wei-alipay-switch"><span class="screen-reader-text">' . esc_html( $toggle_label ) . '</span><input type="checkbox" name="features[]" value="' . esc_attr( $key ) . '" ' . checked( $effective_enabled, true, false ) . ' ' . disabled( $available, false, false ) . ( $available ? '' : ' aria-describedby="' . esc_attr( $dependency_id ) . '"' ) . '><span aria-hidden="true"></span></label></div>';
		}
		 echo '</div></section>';
	}

	/**
	 * Return a stable, merchant-facing state for a locked feature.
	 *
	 * @param array<string,mixed> $feature Feature registry row.
	 * @return string
	 */
	private static function locked_status_label( $feature ) {
		if ( isset( $feature['tier'] ) && 'commercial' === $feature['tier'] ) {
			return __( 'Commercial authorization required', 'wei-alipay' );
		}
		if ( isset( $feature['tier'] ) && 'specialist' === $feature['tier'] ) {
			return __( 'Provider review required', 'wei-alipay' );
		}
		return __( 'Free after connection', 'wei-alipay' );
	}

	/**
	 * Return the merchant-facing group without changing stable runtime semantics.
	 *
	 * @param array<string,mixed> $feature Registry entry.
	 * @return string
	 */
	private static function feature_ui_group( $feature ) {
		return isset( $feature['ui_group'] ) ? (string) $feature['ui_group'] : (string) $feature['group'];
	}

	private static function dependency_available( $feature ) {
		if ( empty( $feature['dependency'] ) ) {
			return true;
		}
		if ( 'woocommerce-subscriptions' === $feature['dependency'] ) {
			return function_exists( 'wcs_is_subscription' ) || class_exists( 'WC_Subscriptions' ) || class_exists( 'WC_Subscription' ) || class_exists( 'WC_Subscriptions_Core_Plugin' );
		}
		return false;
	}

	/**
	 * Whether the current release permits a feature to boot.
	 *
	 * Restricted products remain fail-closed until a separately reviewed
	 * entitlement implementation is available. This is a runtime boundary,
	 * not merely a disabled control in the admin interface.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	private static function access_available( $feature ) {
		$registry = self::registry();
		return isset( $registry[ $feature ] ) && self::feature_access_available( $registry[ $feature ] );
	}

	/**
	 * Whether a registry entry is locally available in this release.
	 *
	 * @param array<string,mixed> $feature Registry entry.
	 * @return bool
	 */
	private static function feature_access_available( $feature ) {
		return ! isset( $feature['access'] ) || 'restricted' !== $feature['access'];
	}

	/**
	 * Return the platform application URL for a restricted feature.
	 *
	 * @param string $feature Feature key.
	 * @return string
	 */
	private static function application_url( $feature ) {
		return (string) apply_filters( 'wei_alipay_feature_application_url', 'https://weixiaoduo.com/product/wei-alipay.html#features', $feature );
	}

	private static function is_configured( $feature ) {
		$settings = get_option( $feature['settings_option'], array() );
		if ( ! is_array( $settings ) ) {
			return false;
		}
		if ( 'payment' === $feature['group'] ) {
			return isset( $settings['enabled'] ) && 'yes' === $settings['enabled'];
		}
		if ( isset( $feature['settings_key'] ) ) {
			return isset( $settings[ $feature['settings_key'] ] ) && 'yes' === $settings[ $feature['settings_key'] ];
		}
		return ! empty( $settings );
	}

	private static function configuration_url( $feature ) {
		if ( ! empty( $feature['settings_section'] ) ) {
			return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . rawurlencode( $feature['settings_section'] ) );
		}
		if ( ! empty( $feature['page'] ) ) {
			return admin_url( 'admin.php?page=' . rawurlencode( $feature['page'] ) );
		}
		return '';
	}

	private static function clear_disabled_schedules( $old, $new ) {
		$hooks = array(
			'profitsharing' => array( 'woo_alipay_ps_process_jobs' ),
			'webhook_retry' => array( 'woo_alipay_retry_failed_webhooks' ),
			'subscriptions' => array( 'woo_alipay_subscriptions_retry_charge' ),
		);
		foreach ( $hooks as $feature => $scheduled_hooks ) {
			if ( 'yes' !== ( isset( $old[ $feature ] ) ? $old[ $feature ] : 'no' ) || 'no' !== ( isset( $new[ $feature ] ) ? $new[ $feature ] : 'no' ) ) {
				continue;
			}
			foreach ( $scheduled_hooks as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
		}
		if ( 'yes' === ( isset( $old['profitsharing'] ) ? $old['profitsharing'] : 'no' ) && 'no' === ( isset( $new['profitsharing'] ) ? $new['profitsharing'] : 'no' ) && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'woo_alipay_ps_run_job', array(), 'woo_alipay_ps' );
		}
	}
}
