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
	const VERSION = '3';

	/**
	 * Register feature-centre hooks.
	 */
	public static function register() {
		self::maybe_migrate();
		add_action( 'admin_post_wei_alipay_save_features', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'admin_title', array( __CLASS__, 'filter_admin_title' ), 10, 2 );
		foreach ( self::registry() as $key => $feature ) {
			if ( 'connect_to_enable' === self::feature_gate( $feature ) && ! empty( $feature['settings_option'] ) ) {
				$option_name = (string) $feature['settings_option'];
				add_filter(
					'pre_update_option_' . $option_name,
					static function( $value, $old_value ) use ( $key ) {
						return self::guard_locked_option_update( $key, $value, $old_value );
					},
					5,
					2
				);
			}
		}
	}

	/**
	 * Whether a connect_to_enable feature is currently enabled but locked for option edits
	 * because the site is disconnected from Weixiaoduo Account.
	 *
	 * @param string $feature_key Registry feature key.
	 * @return bool
	 */
	public static function is_feature_locked_for_edit( $feature_key ) {
		$registry = self::registry();
		if ( ! isset( $registry[ $feature_key ] ) ) {
			return false;
		}
		if ( 'connect_to_enable' !== self::feature_gate( $registry[ $feature_key ] ) ) {
			return false;
		}
		return self::is_enabled( $feature_key ) && ! self::is_connected();
	}

	/**
	 * Enforce the disconnect save-lock at the WordPress option persistence layer.
	 * Already-enabled methods keep processing payments, callbacks and refunds,
	 * while option changes are refused until the site reconnects.
	 *
	 * @param string $feature_key Registry feature key.
	 * @param mixed  $value       Proposed option value.
	 * @param mixed  $old_value   Existing option value.
	 * @return mixed
	 */
	public static function guard_locked_option_update( $feature_key, $value, $old_value ) {
		if ( ! self::is_feature_locked_for_edit( $feature_key ) ) {
			return $value;
		}
		if ( $value === $old_value ) {
			return $value;
		}
		if ( class_exists( 'WC_Admin_Settings' ) ) {
			WC_Admin_Settings::add_error(
				__( 'Wei Alipay options for this payment method are locked while the site is disconnected. Active payments continue normally; reconnect your Weixiaoduo account to save changes.', 'wei-alipay' )
			);
		}
		return $old_value;
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
				'gate' => 'connect_to_enable',
				'visibility' => 'always',
				'name' => __( 'Alipay Face-to-Face Pay', 'wei-alipay' ),
				'description' => __( 'Accept merchant-presented QR code payments for counters and physical stores.', 'wei-alipay' ),
				'icon' => 'dashicons-store',
				'settings_option' => 'woocommerce_alipay_facetopay_settings',
				'settings_section' => 'alipay_facetopay',
			),
			'huabei' => array(
				'group' => 'payment',
				'gate' => 'connect_to_enable',
				'visibility' => 'always',
				'name' => __( 'Alipay Huabei Installments', 'wei-alipay' ),
				'description' => __( 'Offer eligible customers 3, 6 or 12-installment Huabei payments.', 'wei-alipay' ),
				'icon' => 'dashicons-money-alt',
				'settings_option' => 'woocommerce_alipay_installment_settings',
				'settings_section' => 'alipay_installment',
			),
			'barcode' => array(
				'group' => 'payment',
				'gate' => 'connect_to_enable',
				'visibility' => 'always',
				'name' => __( 'Alipay Barcode Pay', 'wei-alipay' ),
				'description' => __( 'Accept customer-presented Alipay barcode or QR code payments.', 'wei-alipay' ),
				'icon' => 'dashicons-screenoptions',
				'settings_option' => 'woocommerce_alipay_barcode_settings',
				'settings_section' => 'alipay_barcode',
			),
			'fund_auth' => array(
				'group' => 'payment',
				'gate' => 'unavailable',
				'visibility' => 'always',
				'name' => __( 'Alipay Fund Authorization', 'wei-alipay' ),
				'description' => __( 'Freeze customer funds and capture or release them later.', 'wei-alipay' ),
				'icon' => 'dashicons-lock',
				'settings_option' => 'woocommerce_alipay_fund_auth_settings',
				'settings_section' => 'alipay_fund_auth',
			),
			'cross_border' => array(
				'group' => 'payment',
				'gate' => 'unavailable',
				'visibility' => 'always',
				'name' => __( 'Alipay Cross-Border', 'wei-alipay' ),
				'description' => __( 'Accept supported non-CNY cross-border Alipay payments.', 'wei-alipay' ),
				'icon' => 'dashicons-airplane',
				'settings_option' => 'woocommerce_acbop_settings',
				'settings_section' => 'acbop',
			),
			'profitsharing' => array(
				'group' => 'operations',
				'ui_group' => 'payment',
				'gate' => 'unavailable',
				'visibility' => 'always',
				'name' => __( 'Profit Sharing', 'wei-alipay' ),
				'description' => __( 'Manage receivers, settlement rules and profit-sharing jobs.', 'wei-alipay' ),
				'icon' => 'dashicons-networking',
				'settings_option' => 'woo_alipay_ps_settings',
				'page' => 'wei-alipay-profitsharing',
			),
			'reconcile' => array(
				'group' => 'operations',
				'gate' => 'local',
				'visibility' => 'always',
				'name' => __( 'Reconciliation', 'wei-alipay' ),
				'description' => __( 'Review Alipay transactions and reconciliation results.', 'wei-alipay' ),
				'icon' => 'dashicons-chart-area',
				'settings_option' => 'woo_alipay_reconcile_pro_settings',
				'page' => 'wei-alipay-reconcile-pro',
			),
			'webhook_retry' => array(
				'group' => 'operations',
				'gate' => 'local',
				'visibility' => 'always',
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
				'gate' => 'unavailable',
				'visibility' => 'always',
				'name' => __( 'Subscriptions', 'wei-alipay' ),
				'description' => __( 'Use Alipay agreements for recurring WooCommerce Subscriptions charges.', 'wei-alipay' ),
				'icon' => 'dashicons-controls-repeat',
				'settings_option' => 'woo_alipay_subscriptions_settings',
				'page' => 'wei-alipay-subscriptions',
				'dependency' => 'woocommerce-subscriptions',
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
		$registry = self::registry();
		if ( ! isset( $registry[ $feature ] ) ) {
			return false;
		}
		if ( 'unavailable' === self::feature_gate( $registry[ $feature ] ) ) {
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
			if ( 'unavailable' === self::feature_gate( $feature ) ) {
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
		if ( ! in_array( $group, array( 'payment', 'operations', 'all' ), true ) ) {
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
		$connected = self::is_connected();
		foreach ( self::registry() as $key => $feature ) {
			if ( 'all' !== $group ) {
				if ( $group !== self::feature_ui_group( $feature ) ) {
					continue;
				}
			}
			if ( 'unavailable' === self::feature_gate( $feature ) ) {
				$new[ $key ] = 'no';
				continue;
			}
			if ( ! self::feature_can_change( $feature, $connected ) ) {
				$new[ $key ] = isset( $old[ $key ] ) ? $old[ $key ] : 'no';
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

		$view = ( 'payment' === $group || 'all' === $group || ( class_exists( 'Wei_Alipay_Shell' ) && Wei_Alipay_Shell::is_kit_ready() ) ) ? 'payments' : 'operations';
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
		// 4.1 shell pages are styled exclusively by vendored wenpai-admin-ui + wei-alipay-shell.css.
		if ( class_exists( 'Wei_Alipay_Shell' ) && Wei_Alipay_Shell::is_kit_ready() ) {
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
		if ( class_exists( 'Wei_Alipay_Shell' ) && Wei_Alipay_Shell::is_kit_ready() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab routing.
			$raw_view    = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'overview';
			$shell_view  = 'operations' === $raw_view ? 'payments' : ( in_array( $raw_view, array( 'overview', 'payments', 'account', 'help' ), true ) ? $raw_view : 'overview' );
			$enabled_opt = get_option( self::OPTION_KEY, array() );
			$main_opt    = get_option( 'woocommerce_alipay_settings', array() );
			$main_active = is_array( $main_opt ) && 'yes' === ( $main_opt['enabled'] ?? 'no' );
			$secret_h    = class_exists( 'Wei_Alipay_Secret_Store' ) ? Wei_Alipay_Secret_Store::main_secret_health() : array( 'status' => 'missing' );
			$main_ready  = $main_active && ! empty( $main_opt['appid'] ) && 'ready' === ( $secret_h['status'] ?? '' );
			$notices     = array();
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag.
			if ( ! empty( $_GET['features-updated'] ) ) {
				$notices[] = array( 'type' => 'success', 'message' => __( '支付方式与工具设置已保存。', 'wei-alipay' ) );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag.
			if ( ! empty( $_GET['palette-updated'] ) ) {
				$notices[] = array( 'type' => 'success', 'message' => __( '后台品牌配色偏好已更新。', 'wei-alipay' ) );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag.
			$conn_flag = isset( $_GET['wei_alipay_connect'] ) ? sanitize_key( wp_unslash( $_GET['wei_alipay_connect'] ) ) : '';
			if ( 'connected' === $conn_flag ) {
				$notices[] = array( 'type' => 'success', 'message' => __( '已成功连接薇晓朵账户。', 'wei-alipay' ) );
			} elseif ( 'disconnected' === $conn_flag ) {
				$notices[] = array( 'type' => 'warning', 'message' => __( '已断开薇晓朵账户连接，已启用的支付方式仍会继续收款。', 'wei-alipay' ) );
			} elseif ( 'cancelled' === $conn_flag ) {
				$notices[] = array( 'type' => 'info', 'message' => __( '已取消账户连接授权。', 'wei-alipay' ) );
			} elseif ( in_array( $conn_flag, array( 'invalid_state', 'failed', 'exchange_failed' ), true ) ) {
				$notices[] = array( 'type' => 'error', 'message' => __( '账户连接未完成，请重试。', 'wei-alipay' ) );
			}
			Wei_Alipay_Shell::render_primary_page(
				array(
					'view'            => $shell_view,
					'account'         => $account,
					'enabled'         => is_array( $enabled_opt ) ? $enabled_opt : array(),
					'main_active'     => $main_active,
					'main_ready'      => $main_ready,
					'secret_health'   => $secret_h,
					'health'          => class_exists( 'Wei_Alipay_Health' ) ? Wei_Alipay_Health::snapshot() : array(),
					'activity'        => class_exists( 'Wei_Alipay_Health' ) ? Wei_Alipay_Health::activity_summary() : array(),
					'runtime_summary' => self::version_summary(),
					'notices'         => $notices,
				)
			);
			return;
		}
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
		echo '</div><p>' . esc_html__( 'The main Alipay gateway works after installation. Connect a Weixiaoduo account to enable optional methods.', 'wei-alipay' ) . '</p></div>';
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
		if ( 'connected' !== $account['status'] ) {
			$attention[] = array( __( 'Connect a Weixiaoduo account to enable optional payment methods. The main Alipay gateway works without a connection.', 'wei-alipay' ), add_query_arg( array( 'page' => 'wei-alipay', 'view' => 'account' ), admin_url( 'admin.php' ) ), __( 'Open account', 'wei-alipay' ) );
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
		self::summary_card( __( 'Weixiaoduo account', 'wei-alipay' ), $account['status_label'], $account['status_class'], __( 'Connection identifies this site. It does not license the main gateway.', 'wei-alipay' ), add_query_arg( array( 'page' => 'wei-alipay', 'view' => 'account' ), admin_url( 'admin.php' ) ), __( 'Open account', 'wei-alipay' ) );
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
			echo '<p>' . esc_html__( 'Connect this site to a Weixiaoduo account. The main Alipay gateway works after installation. Optional methods such as Face-to-Face Pay can be turned on after you connect.', 'wei-alipay' ) . '</p>';
		}
		echo '</div></div><div class="wei-alipay-account__actions">';
		if ( 'connected' === $account['status'] && $account['manage_url'] ) {
			echo '<a class="button button-primary" href="' . esc_url( $account['manage_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Manage on Weixiaoduo.com', 'wei-alipay' ) . '</a>';
		} else {
			$connect_url = ! empty( $account['connect_url'] ) ? $account['connect_url'] : ( class_exists( 'Wei_Alipay_Account_Client' ) ? Wei_Alipay_Account_Client::connect_action_url() : '' );
			echo '<a class="button button-primary" href="' . esc_url( $connect_url ) . '">' . esc_html__( 'Connect account', 'wei-alipay' ) . '</a>';
		}
		echo '</div></section>';

		echo '<section class="wei-alipay-account__entitlements"><h2>' . esc_html__( 'What connection is for', 'wei-alipay' ) . '</h2><div class="wei-alipay-account__status-list">';
		self::account_status_row( __( 'Main Alipay gateway', 'wei-alipay' ), __( 'Available after install', 'wei-alipay' ), 'success' );
		self::account_status_row( __( 'Face-to-Face, Huabei and barcode', 'wei-alipay' ), 'connected' === $account['status'] ? __( 'Can be enabled', 'wei-alipay' ) : __( 'Connect to enable', 'wei-alipay' ), 'connected' === $account['status'] ? 'success' : 'light' );
		self::account_status_row( __( 'Cross-border, fund authorization, subscriptions and profit sharing', 'wei-alipay' ), 'connected' === $account['status'] ? __( 'Not available yet', 'wei-alipay' ) : __( 'Connect to see status', 'wei-alipay' ), 'connected' === $account['status'] ? 'warning' : 'light' );
		echo '</div></section>';

		echo '<details class="wei-alipay-disclosure"><summary>' . esc_html__( 'Data shared when connected', 'wei-alipay' ) . '</summary><div><p>' . esc_html__( 'Required connection data is limited to the site host, installation identifier, software versions, locale, store currency and connection timestamps.', 'wei-alipay' ) . '</p><p><strong>' . esc_html__( 'Never sent:', 'wei-alipay' ) . '</strong> ' . esc_html__( 'orders, payment amounts, customer details, Alipay private keys, certificates or notification payloads.', 'wei-alipay' ) . '</p><a href="' . esc_url( 'https://weixiaoduo.com/product/wei-alipay.html#privacy' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Read the data and privacy notice', 'wei-alipay' ) . '</a></div></details>';

		echo '<details class="wei-alipay-disclosure"><summary>' . esc_html__( 'Connection details', 'wei-alipay' ) . '</summary><div class="wei-alipay-account__facts"><dl><dt>' . esc_html__( 'Site', 'wei-alipay' ) . '</dt><dd>' . esc_html( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) . '</dd><dt>' . esc_html__( 'Installation ID', 'wei-alipay' ) . '</dt><dd>' . esc_html( $account['installation_label'] ) . '</dd><dt>' . esc_html__( 'Last successful sync', 'wei-alipay' ) . '</dt><dd>' . esc_html( $account['last_sync_label'] ) . '</dd></dl></div></details>';

		if ( 'connected' === $account['status'] && $account['disconnect_url'] ) {
			echo '<details class="wei-alipay-disclosure is-danger"><summary>' . esc_html__( 'Disconnect this site', 'wei-alipay' ) . '</summary><div><p>' . esc_html__( 'Disconnecting does not stop methods that are already enabled. You will not be able to change their options until you connect again.', 'wei-alipay' ) . '</p><a class="button" href="' . esc_url( $account['disconnect_url'] ) . '">' . esc_html__( 'Review disconnection', 'wei-alipay' ) . '</a></div></details>';
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
		if ( class_exists( 'Wei_Alipay_Account_Client' ) ) {
			return Wei_Alipay_Account_Client::account_state();
		}
		$defaults = array(
			'status' => 'not-connected',
			'status_label' => __( 'Not connected', 'wei-alipay' ),
			'status_class' => 'light',
			'account_label' => '',
			'installation_label' => __( 'Created when connected', 'wei-alipay' ),
			'last_sync_label' => __( 'Never', 'wei-alipay' ),
			'connect_url' => '',
			'manage_url' => '',
			'disconnect_url' => '',
			'avatar_url' => '',
			'avatar_alt' => __( 'Weixiaoduo account avatar', 'wei-alipay' ),
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
		$allowed_hosts = apply_filters( 'wei_alipay_cravatar_hosts', array( 'cravatar.com', 'www.cravatar.com', 'cn.cravatar.com' ) );
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
		echo '<div class="notice notice-info inline" role="status"><p><strong>' . esc_html__( 'How activation works', 'wei-alipay' ) . '</strong> ' . esc_html__( 'The main Alipay gateway works after installation. Face-to-Face Pay, Huabei and barcode can be turned on after you connect a Weixiaoduo account. After they are enabled, disconnecting keeps them running but locks their options.', 'wei-alipay' ) . '</p></div>';
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
			if ( ! self::dependency_available( $feature ) || 'unavailable' === self::feature_gate( $feature ) ) {
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
		$connected = self::is_connected();
		echo '<section class="wei-alipay-features__section"><div class="wei-alipay-features__section-copy"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $description ) . '</p></div><div class="wei-alipay-features__card">';
		foreach ( self::registry() as $key => $feature ) {
			if ( $group !== self::feature_ui_group( $feature ) || ! self::feature_is_visible( $feature, $connected ) ) {
				continue;
			}
			$gate = self::feature_gate( $feature );
			$is_enabled = isset( $enabled[ $key ] ) && 'yes' === $enabled[ $key ];
			$dependency_available = self::dependency_available( $feature );
			$can_change = self::feature_can_change( $feature, $connected ) && $dependency_available;
			$effective_enabled = $is_enabled && 'unavailable' !== $gate;
			$configured = self::is_configured( $feature );
			$status = $effective_enabled ? __( 'Enabled', 'wei-alipay' ) : __( 'Inactive', 'wei-alipay' );
			$status_class = $effective_enabled ? 'success' : 'light';
			if ( 'unavailable' === $gate && $connected ) {
				$status = __( 'Not available yet', 'wei-alipay' );
				$status_class = 'warning';
			} elseif ( 'unavailable' === $gate ) {
				$status = __( 'Connect to see status', 'wei-alipay' );
				$status_class = 'light';
			} elseif ( ! $dependency_available ) {
				$status = __( 'Dependency missing', 'wei-alipay' );
				$status_class = 'warning';
			} elseif ( 'connect_to_enable' === $gate && ! $connected && ! $effective_enabled ) {
				$status = __( 'Connect to enable', 'wei-alipay' );
				$status_class = 'warning';
			} elseif ( 'connect_to_enable' === $gate && ! $connected && $effective_enabled ) {
				$status = __( 'Enabled, options locked', 'wei-alipay' );
				$status_class = 'success';
			} elseif ( $effective_enabled && ! $configured ) {
				$status = __( 'Action needed', 'wei-alipay' );
				$status_class = 'warning';
			}
			$help_id = 'wei-alipay-feature-' . sanitize_html_class( $key ) . '-help';
			$show_toggle = 'unavailable' !== $gate;
			echo '<div class="wei-alipay-features__row' . ( $can_change ? '' : ' is-unavailable' ) . '"' . ( $can_change ? '' : ' aria-disabled="true"' ) . '>';
			echo '<span class="dashicons ' . esc_attr( $feature['icon'] ) . '" aria-hidden="true"></span><div class="wei-alipay-features__row-copy"><div class="wei-alipay-features__title">' . esc_html( $feature['name'] ) . ' <span class="wei-alipay-chip is-' . esc_attr( $status_class ) . '" data-feature-status data-on="' . esc_attr__( 'Enabled', 'wei-alipay' ) . '" data-off="' . esc_attr__( 'Inactive', 'wei-alipay' ) . '">' . esc_html( $status ) . '</span></div><p>' . esc_html( $feature['description'] ) . '</p>';
			if ( ! $dependency_available ) {
				echo '<p class="wei-alipay-features__dependency" id="' . esc_attr( $help_id ) . '"><span class="dashicons dashicons-warning" aria-hidden="true"></span>' . esc_html__( 'Install and activate WooCommerce Subscriptions before enabling this feature.', 'wei-alipay' ) . '</p>';
			} elseif ( 'unavailable' === $gate && $connected ) {
				echo '<p class="wei-alipay-features__dependency" id="' . esc_attr( $help_id ) . '">' . esc_html__( 'This method is not open yet.', 'wei-alipay' ) . '</p>';
			} elseif ( 'unavailable' === $gate ) {
				echo '<p class="wei-alipay-features__dependency" id="' . esc_attr( $help_id ) . '">' . esc_html__( 'Connect a Weixiaoduo account to see whether this method can be enabled.', 'wei-alipay' ) . '</p>';
			} elseif ( 'connect_to_enable' === $gate && ! $connected && ! $effective_enabled ) {
				echo '<p class="wei-alipay-features__dependency" id="' . esc_attr( $help_id ) . '">' . esc_html__( 'Connect a Weixiaoduo account to turn this method on.', 'wei-alipay' ) . '</p>';
			} elseif ( 'connect_to_enable' === $gate && ! $connected && $effective_enabled ) {
				echo '<p class="wei-alipay-features__dependency" id="' . esc_attr( $help_id ) . '">' . esc_html__( 'This method stays on. Connect again to change its options.', 'wei-alipay' ) . '</p>';
			}
			if ( $effective_enabled && ( $connected || 'local' === $gate ) ) {
				$url = self::configuration_url( $feature );
				if ( $url ) {
					echo '<p class="wei-alipay-features__configure"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Configure', 'wei-alipay' ) . '</a></p>';
				}
			}
			/* translators: %s is the optional Wei Alipay feature name. */
			$toggle_label = sprintf( __( 'Enable %s', 'wei-alipay' ), $feature['name'] );
			echo '</div>';
			if ( $show_toggle ) {
				echo '<label class="wei-alipay-switch"><span class="screen-reader-text">' . esc_html( $toggle_label ) . '</span><input type="checkbox" name="features[]" value="' . esc_attr( $key ) . '" ' . checked( $effective_enabled, true, false ) . ' ' . disabled( $can_change, false, false ) . ( $can_change ? '' : ' aria-describedby="' . esc_attr( $help_id ) . '"' ) . '><span aria-hidden="true"></span></label>';
			}
			echo '</div>';
		}
		echo '</div></section>';
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

	/**
	 * @param array<string,mixed> $feature Registry entry.
	 * @return string
	 */
	private static function feature_gate( $feature ) {
		return isset( $feature['gate'] ) ? (string) $feature['gate'] : 'local';
	}

	/**
	 * @param array<string,mixed> $feature   Registry entry.
	 * @param bool                $connected Whether the site is connected.
	 * @return bool
	 */
	private static function feature_is_visible( $feature, $connected ) {
		$visibility = isset( $feature['visibility'] ) ? (string) $feature['visibility'] : 'always';
		if ( 'hidden' === $visibility ) {
			return false;
		}
		if ( 'connected' === $visibility ) {
			return $connected;
		}
		return true;
	}

	/**
	 * @param array<string,mixed> $feature   Registry entry.
	 * @param bool                $connected Whether the site is connected.
	 * @return bool
	 */
	private static function feature_can_change( $feature, $connected ) {
		$gate = self::feature_gate( $feature );
		if ( 'unavailable' === $gate ) {
			return false;
		}
		if ( 'connect_to_enable' === $gate ) {
			return $connected;
		}
		return true;
	}

	/**
	 * @return bool
	 */
	private static function is_connected() {
		return self::account_connected( self::account_state() );
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
