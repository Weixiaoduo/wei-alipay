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
	const VERSION = '1';

	/**
	 * Register feature-centre hooks.
	 */
	public static function register() {
		self::maybe_migrate();
		add_action( 'admin_post_wei_alipay_save_features', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
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
				'name' => __( 'Alipay Face-to-Face Pay', 'wei-alipay' ),
				'description' => __( 'Accept merchant-presented QR code payments for counters and physical stores.', 'wei-alipay' ),
				'icon' => 'dashicons-store',
				'settings_option' => 'woocommerce_alipay_facetopay_settings',
				'settings_section' => 'alipay_facetopay',
			),
			'huabei' => array(
				'group' => 'payment',
				'name' => __( 'Alipay Huabei Installments', 'wei-alipay' ),
				'description' => __( 'Offer eligible customers 3, 6 or 12-installment Huabei payments.', 'wei-alipay' ),
				'icon' => 'dashicons-money-alt',
				'settings_option' => 'woocommerce_alipay_installment_settings',
				'settings_section' => 'alipay_installment',
			),
			'barcode' => array(
				'group' => 'payment',
				'name' => __( 'Alipay Barcode Pay', 'wei-alipay' ),
				'description' => __( 'Accept customer-presented Alipay barcode or QR code payments.', 'wei-alipay' ),
				'icon' => 'dashicons-screenoptions',
				'settings_option' => 'woocommerce_alipay_barcode_settings',
				'settings_section' => 'alipay_barcode',
			),
			'fund_auth' => array(
				'group' => 'payment',
				'name' => __( 'Alipay Fund Authorization', 'wei-alipay' ),
				'description' => __( 'Freeze customer funds and capture or release them later.', 'wei-alipay' ),
				'icon' => 'dashicons-lock',
				'settings_option' => 'woocommerce_alipay_fund_auth_settings',
				'settings_section' => 'alipay_fund_auth',
			),
			'mini_program' => array(
				'group' => 'payment',
				'name' => __( 'Alipay Mini Program', 'wei-alipay' ),
				'description' => __( 'Accept payments initiated in an Alipay Mini Program. RSA2 is the default protocol.', 'wei-alipay' ),
				'icon' => 'dashicons-smartphone',
				'settings_option' => 'woocommerce_alipay_wap_settings',
				'settings_section' => 'alipay_wap',
			),
			'cross_border' => array(
				'group' => 'payment',
				'name' => __( 'Alipay Cross-Border', 'wei-alipay' ),
				'description' => __( 'Accept supported non-CNY cross-border Alipay payments.', 'wei-alipay' ),
				'icon' => 'dashicons-airplane',
				'settings_option' => 'woocommerce_acbop_settings',
				'settings_section' => 'acbop',
			),
			'profitsharing' => array(
				'group' => 'operations',
				'name' => __( 'Profit Sharing', 'wei-alipay' ),
				'description' => __( 'Manage receivers, settlement rules and profit-sharing jobs.', 'wei-alipay' ),
				'icon' => 'dashicons-networking',
				'settings_option' => 'woo_alipay_ps_settings',
				'page' => 'wei-alipay-profitsharing',
			),
			'reconcile' => array(
				'group' => 'operations',
				'name' => __( 'Reconciliation', 'wei-alipay' ),
				'description' => __( 'Review Alipay transactions and reconciliation results.', 'wei-alipay' ),
				'icon' => 'dashicons-chart-area',
				'settings_option' => 'woo_alipay_reconcile_pro_settings',
				'page' => 'wei-alipay-reconcile-pro',
			),
			'webhook_retry' => array(
				'group' => 'operations',
				'name' => __( 'Webhook Logs and Retry', 'wei-alipay' ),
				'description' => __( 'Inspect asynchronous notification delivery and retry failed processing.', 'wei-alipay' ),
				'icon' => 'dashicons-update',
				'settings_option' => 'woocommerce_alipay_settings',
				'settings_key' => 'enable_webhook_retry',
				'page' => 'wei-alipay-webhooks',
			),
			'subscriptions' => array(
				'group' => 'operations',
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
		$new = array();
		foreach ( self::registry() as $key => $feature ) {
			if ( ! self::dependency_available( $feature ) ) {
				$new[ $key ] = isset( $old[ $key ] ) ? $old[ $key ] : 'no';
				continue;
			}
			$new[ $key ] = in_array( $key, $posted, true ) ? 'yes' : 'no';
		}
		update_option( self::OPTION_KEY, $new, false );
		self::clear_disabled_schedules( $old, $new );

		wp_safe_redirect( add_query_arg( 'features-updated', '1', admin_url( 'admin.php?page=wei-alipay' ) ) );
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
		$enabled = get_option( self::OPTION_KEY, array() );
		$enabled = is_array( $enabled ) ? $enabled : array();
		$main = get_option( 'woocommerce_alipay_settings', array() );
		$main_active = is_array( $main ) && isset( $main['enabled'] ) && 'yes' === $main['enabled'];

		echo '<div class="wrap wei-alipay-admin wei-alipay-features">';
		echo '<div class="wei-alipay-features__header"><div><h1>' . esc_html__( 'Wei Alipay', 'wei-alipay' ) . '</h1><p>' . esc_html__( 'Choose which payment methods and operational tools this store loads.', 'wei-alipay' ) . '</p></div>';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=alipay' ) ) . '">' . esc_html__( 'Main gateway settings', 'wei-alipay' ) . '</a></div>';
		if ( isset( $_GET['features-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- success notice only.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Wei Alipay features saved.', 'wei-alipay' ) . '</p></div>';
		}
		echo '<div class="wei-alipay-features__notice" role="status"><strong>' . esc_html__( 'Two-step activation', 'wei-alipay' ) . '</strong><span>' . esc_html__( 'Turn a feature on here to load it. Payment methods must then be configured and enabled in WooCommerce.', 'wei-alipay' ) . '</span></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wei-alipay-feature-form>';
		echo '<input type="hidden" name="action" value="wei_alipay_save_features">';
		wp_nonce_field( 'wei_alipay_save_features' );

		self::render_required_gateway( $main_active );
		self::render_group( 'payment', __( 'Optional payment methods', 'wei-alipay' ), __( 'Only enabled methods are registered with WooCommerce and shown in its Payments list.', 'wei-alipay' ), $enabled );
		self::render_group( 'operations', __( 'Operational tools', 'wei-alipay' ), __( 'Disabled tools do not register admin pages or background processing.', 'wei-alipay' ), $enabled );

		echo '<div class="wei-alipay-features__actions"><span class="wei-alipay-features__dirty" aria-live="polite"></span><button type="submit" class="button button-primary" disabled>' . esc_html__( 'Save feature settings', 'wei-alipay' ) . '</button></div>';
		echo '</form></div>';
	}

	private static function render_required_gateway( $active ) {
		$status = $active ? __( 'Active', 'wei-alipay' ) : __( 'Action needed', 'wei-alipay' );
		$class = $active ? 'success' : 'warning';
		echo '<section class="wei-alipay-features__section"><div class="wei-alipay-features__section-copy"><h2>' . esc_html__( 'Main payment gateway', 'wei-alipay' ) . '</h2><p>' . esc_html__( 'The core Alipay gateway is always available and cannot be removed here.', 'wei-alipay' ) . '</p></div>';
		echo '<div class="wei-alipay-features__card"><div class="wei-alipay-features__row is-required"><span class="dashicons dashicons-money-alt" aria-hidden="true"></span><div class="wei-alipay-features__row-copy"><div class="wei-alipay-features__title">' . esc_html__( 'Alipay', 'wei-alipay' ) . ' <span class="wei-alipay-chip is-' . esc_attr( $class ) . '">' . esc_html( $status ) . '</span></div><p>' . esc_html__( 'Standard Alipay checkout for CNY stores.', 'wei-alipay' ) . '</p></div><span class="wei-alipay-chip is-light">' . esc_html__( 'Required', 'wei-alipay' ) . '</span></div></div></section>';
	}

	private static function render_group( $group, $title, $description, $enabled ) {
		echo '<section class="wei-alipay-features__section"><div class="wei-alipay-features__section-copy"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $description ) . '</p></div><div class="wei-alipay-features__card">';
		foreach ( self::registry() as $key => $feature ) {
			if ( $group !== $feature['group'] ) {
				continue;
			}
			$is_enabled = isset( $enabled[ $key ] ) && 'yes' === $enabled[ $key ];
			$available = self::dependency_available( $feature );
			$effective_enabled = $is_enabled && $available;
			$configured = self::is_configured( $feature );
			$status = $effective_enabled ? __( 'Enabled', 'wei-alipay' ) : __( 'Inactive', 'wei-alipay' );
			$status_class = $effective_enabled ? 'success' : 'light';
			if ( $effective_enabled && ! $configured ) {
				$status = __( 'Action needed', 'wei-alipay' );
				$status_class = 'warning';
			}
			if ( ! $available ) {
				$status = __( 'Dependency missing', 'wei-alipay' );
				$status_class = 'warning';
			}
			$dependency_id = 'wei-alipay-feature-' . sanitize_html_class( $key ) . '-dependency';
			echo '<div class="wei-alipay-features__row' . ( $available ? '' : ' is-unavailable' ) . '"' . ( $available ? '' : ' aria-disabled="true"' ) . '>';
			echo '<span class="dashicons ' . esc_attr( $feature['icon'] ) . '" aria-hidden="true"></span><div class="wei-alipay-features__row-copy"><div class="wei-alipay-features__title">' . esc_html( $feature['name'] ) . ' <span class="wei-alipay-chip is-' . esc_attr( $status_class ) . '" data-feature-status data-on="' . esc_attr__( 'Enabled', 'wei-alipay' ) . '" data-off="' . esc_attr__( 'Inactive', 'wei-alipay' ) . '">' . esc_html( $status ) . '</span></div><p>' . esc_html( $feature['description'] ) . '</p>';
			if ( ! $available ) {
				echo '<p class="wei-alipay-features__dependency" id="' . esc_attr( $dependency_id ) . '"><span class="dashicons dashicons-warning" aria-hidden="true"></span>' . esc_html__( 'Install and activate WooCommerce Subscriptions before enabling this feature.', 'wei-alipay' ) . '</p>';
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
