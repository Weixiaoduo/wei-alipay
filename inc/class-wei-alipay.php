<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Wei_Alipay {

	protected static $alipay_lib_paths;

	public function __construct( $alipay_lib_paths, $init_hooks = false ) {

		self::$alipay_lib_paths = $alipay_lib_paths;
		$plugin_base_name       = plugin_basename( WEI_ALIPAY_PLUGIN_PATH );

		if ( $init_hooks ) {
			// Add translation
			add_action( 'init', array( $this, 'load_textdomain' ), 0, 0 );
			// Add main scripts & styles
			add_action( 'wp_enqueue_scripts', array( $this, 'add_frontend_scripts' ), 10, 0 );
            // Add admin scripts
            add_action( 'admin_enqueue_scripts', array( $this, 'add_admin_scripts' ), 99, 1 );
            // Admin tools
            add_action( 'admin_menu', array( $this, 'add_admin_pages' ), 5 );
			// Add custom cron intervals
			add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ), 10, 1 );

			// Add alipay payment gateway
			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ), 10, 1 );
			// Add alipay payment gateway settings page
			add_filter( 'plugin_action_links_' . $plugin_base_name, array( $this, 'plugin_edit_link' ), 10, 1 );
			// Display alipay transction number on order page
			add_filter( 'woocommerce_get_order_item_totals', array( $this, 'display_order_meta_for_customer' ), 10, 2 );
			// Add Alipay orphan transactions email notification
			add_filter( 'woocommerce_email_classes', array( $this, 'add_orphan_transaction_woocommerce_email' ), 10, 1 );

			// Add WooCommerce Blocks support
			$this->woocommerce_gateway_alipay_woocommerce_block_support();

			// Enhance Payments providers response with contextual links for Alipay gateways
			add_filter( 'rest_post_dispatch', array( $this, 'filter_payments_providers_response' ), 10, 3 );
		}
	}

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::upgrade_current_site();
				restore_current_blog();
			}
		} else {
			self::upgrade_current_site();
		}
		wp_cache_flush();
	}

	public static function maybe_upgrade() {
		if ( '3.2.0-r3' !== get_option( 'woo_alipay_schema_version' ) ) {
			self::upgrade_current_site();
		}
	}

	private static function upgrade_current_site() {
		$mini = get_option( 'woocommerce_alipay_wap_settings', array() );
		if ( is_array( $mini ) && ! isset( $mini['protocol_mode'] ) ) {
			$has_legacy = ! empty( $mini['partnerID'] ) || ! empty( $mini['secure_key'] );
			$mini['protocol_mode']   = $has_legacy ? 'legacy_md5' : 'rsa2';
			$mini['legacy_md5_ack']  = 'no';
			update_option( 'woocommerce_alipay_wap_settings', $mini, false );
		}
		if ( class_exists( 'Wei_Alipay_Secret_Store' ) ) {
			Wei_Alipay_Secret_Store::migrate_current_site();
		}
		if ( class_exists( 'Wei_Alipay_Features' ) ) {
			Wei_Alipay_Features::maybe_migrate();
		}
		update_option( 'woo_alipay_plugin_version', WEI_ALIPAY_VERSION, false );
		update_option( 'woo_alipay_schema_version', '3.2.0-r3', false );
	}

	public static function deactivate() {
		foreach ( array(
			'woo_alipay_check_pending_orders',
			'woo_alipay_check_timeout_orders',
			'woo_alipay_retry_failed_webhooks',
			'woo_alipay_ps_process_jobs',
		) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	public static function uninstall() {
		require_once WEI_ALIPAY_PLUGIN_PATH . 'uninstall.php';
	}

	public static function require_lib( $operation_type ) {

		foreach ( self::$alipay_lib_paths[ $operation_type ] as $class_name => $path ) {

			if ( ! class_exists( $class_name ) ) {
				require_once $path;
			}
		}
	}

	public static function locate_template( $template_name, $load = false, $require_once = true ) {
		$paths    = array(
			'plugins/wei-alipay/' . $template_name,
			'wei-alipay/' . $template_name,
			'woocommerce/wei-alipay/' . $template_name,
			$template_name,
		);
		$template = locate_template(
			$paths,
			$load,
			$require_once
		);

		if ( empty( $template ) ) {
			$template = WEI_ALIPAY_PLUGIN_PATH . 'inc/templates/' . $template_name;

			if ( $load && '' !== $template ) {
				load_template( $template, $require_once );
			}
		}

		return $template;
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'wei-alipay', false, 'wei-alipay/languages' );
	}

	public function add_frontend_scripts() {
		$debug   = (bool) ( constant( 'WP_DEBUG' ) );
		$css_ext = ( $debug ) ? '.css' : '.min.css';
		$version = filemtime( WEI_ALIPAY_PLUGIN_PATH . 'css/main' . $css_ext );

		wp_enqueue_style( 'wei-alipay', WEI_ALIPAY_PLUGIN_URL . 'css/main.css', array(), $version );

		// Enqueue payment polling only for the authorized Alipay order-pay page.
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id  = absint( get_query_var( 'order-pay' ) );
			$order     = $order_id ? wc_get_order( $order_id ) : false;
			$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce order key authorizes this read-only asset decision.
			if ( ! $order || 'alipay' !== $order->get_payment_method() || ! hash_equals( $order->get_order_key(), $order_key ) ) {
				return;
			}

			$gateways = WC()->payment_gateways->payment_gateways();
			$gateway = isset( $gateways['alipay'] ) ? $gateways['alipay'] : null;

			if ( $gateway && 'yes' === $gateway->get_option( 'enable_payment_polling', 'yes' ) ) {
				$script_version = file_exists( WEI_ALIPAY_PLUGIN_PATH . 'js/payment-polling.js' )
					? filemtime( WEI_ALIPAY_PLUGIN_PATH . 'js/payment-polling.js' )
					: '3.3.0';

				wp_enqueue_script(
					'wei-alipay',
					WEI_ALIPAY_PLUGIN_URL . 'js/payment-polling.js',
					array( 'jquery' ),
					$script_version,
					true
				);

				// Get settings
				$polling_interval = absint( $gateway->get_option( 'polling_interval', '3' ) ) * 1000;
				$max_attempts = absint( $gateway->get_option( 'polling_max_attempts', '60' ) );

				// Localize script
				wp_localize_script(
					'wei-alipay',
					'woo_alipay_polling',
					array(
						'ajax_url'      => admin_url( 'admin-ajax.php' ),
						'nonce'         => wp_create_nonce( 'woo_alipay_query_order' ),
						'order_id'      => $order_id,
						'order_key'     => $order_key,
						'poll_interval' => $polling_interval,
						'max_attempts'  => $max_attempts,
						'strings'       => array(
							'checking'     => __( '检查中...', 'wei-alipay' ),
							'check_status' => __( '检查支付状态', 'wei-alipay' ),
							'timeout'      => __( '支付状态查询超时，请手动刷新页面。', 'wei-alipay' ),
							'error'        => __( '查询失败，请稍后再试。', 'wei-alipay' ),
						),
					)
				);
			}
		}
	}

    public function add_admin_scripts( $hook ) {

        if ( 'woocommerce_page_wc-settings' !== $hook ) {
            return;
        }

        // Only load our admin assets on our gateway settings sections, not on the Payments list view.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only settings screen routing.
        $tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only settings screen routing.
        $section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
        $our_sections = array( 'alipay', 'alipay_installment', 'alipay_facetopay' );
        if ( 'checkout' !== $tab || ! in_array( $section, $our_sections, true ) ) {
            return;
        }

        $debug       = (bool) ( constant( 'WP_DEBUG' ) );
        $css_ext     = ( $debug ) ? '.css' : '.min.css';
        $js_ext      = ( $debug ) ? '.js' : '.min.js';
        $version_css = filemtime( WEI_ALIPAY_PLUGIN_PATH . 'css/admin/main' . $css_ext );
        $version_js  = filemtime( WEI_ALIPAY_PLUGIN_PATH . 'js/admin/main' . $js_ext );

        wp_enqueue_style(
            'wei-alipay',
            WEI_ALIPAY_PLUGIN_URL . 'css/admin/main' . $css_ext,
            array(),
            $version_css
        );

        $parameters = array(
            'ajax_url'                 => admin_url( 'admin-ajax.php' ),
            'connection_success'       => __( 'Connection test succeeded. The Alipay gateway configuration is valid.', 'wei-alipay' ),
            'connection_failed'        => __( 'Connection test failed', 'wei-alipay' ),
            'connection_network_error' => __( 'Connection test failed because the request could not be completed.', 'wei-alipay' ),
            'copied'                   => __( 'Copied', 'wei-alipay' ),
            'copy_failed'              => __( 'Could not copy automatically. Select and copy the URL manually.', 'wei-alipay' ),
        );

        wp_enqueue_script(
            'wei-alipay',
            WEI_ALIPAY_PLUGIN_URL . 'js/admin/main' . $js_ext,
            array( 'jquery' ),
            $version_js,
            true
        );
        wp_localize_script( 'wei-alipay', 'WooAlipay', $parameters );
    }

    public function add_admin_pages() {
        add_menu_page(
            __( 'Wei Alipay', 'wei-alipay' ),
            __( 'Wei Alipay', 'wei-alipay' ),
            'manage_woocommerce',
            'wei-alipay',
            array( $this, 'render_admin_overview_page' ),
            'dashicons-money-alt',
            56
        );

        add_submenu_page(
            'wei-alipay',
            __( 'Wei Alipay Feature Center', 'wei-alipay' ),
            __( 'Features', 'wei-alipay' ),
            'manage_woocommerce',
            'wei-alipay',
            array( $this, 'render_admin_overview_page' )
        );

        // Keep the basic reconciliation tool available only when the merchant enables reconciliation.
        if ( Wei_Alipay_Features::is_enabled( 'reconcile' ) && ! class_exists( 'Wei_Alipay_Reconcile_Admin' ) ) {
            add_submenu_page(
                'wei-alipay',
                __( 'Alipay Reconciliation', 'wei-alipay' ),
                __( 'Reconciliation', 'wei-alipay' ),
                'manage_woocommerce',
                'wei-alipay-reconcile',
                array( $this, 'render_admin_reconcile_page' )
            );
        }
    }

    public function render_admin_overview_page() {
        Wei_Alipay_Features::render();
    }

    /**
     * Inject provider links into the WooCommerce Payments providers REST response.
     */
    public function filter_payments_providers_response( $response, $server, $request ) {
        try {
            // Allow site owners to disable this injection for troubleshooting.
            if ( false === apply_filters( 'woo_alipay_enable_payments_links_injection', true ) ) {
                return $response;
            }

            if ( ! $response || ! is_a( $response, 'WP_REST_Response' ) ) {
                return $response;
            }

            $route  = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
            $method = is_object( $request ) && method_exists( $request, 'get_method' ) ? (string) $request->get_method() : '';

            // Only act on the Payments providers endpoint used by the settings page (POST /wc-admin/settings/payments/providers).
            if ( false === strpos( $route, '/wc-admin/settings/payments/providers' ) || 'POST' !== strtoupper( $method ) ) {
                return $response;
            }

            $data = $response->get_data();
            if ( ! is_array( $data ) || empty( $data['providers'] ) || ! is_array( $data['providers'] ) ) {
                return $response;
            }

            $pricing_url = apply_filters( 'woo_alipay_pricing_url', 'https://weixiaoduo.com/product/wei-alipay.html#pricing' );
            $about_url   = apply_filters( 'woo_alipay_learn_more_url', 'https://weixiaoduo.com/document/wei-alipay' );
            $terms_url   = apply_filters( 'woo_alipay_terms_url', 'https://weixiaoduo.com/terms' );
            $docs_url    = apply_filters( 'woo_alipay_docs_url', 'https://weixiaoduo.com/document/wei-alipay' );
            $support_url = apply_filters( 'woo_alipay_support_url', 'https://weixiaoduo.com/support' );

            $target_ids = array( 'alipay', 'alipay_installment', 'alipay_facetopay' );

            foreach ( $data['providers'] as $idx => $provider ) {
                if ( ! is_array( $provider ) || empty( $provider['id'] ) || ! in_array( $provider['id'], $target_ids, true ) ) {
                    continue;
                }

                $links = array();
                if ( isset( $provider['links'] ) && is_array( $provider['links'] ) ) {
                    $links = $provider['links'];
                }

                // Track existing link types to avoid duplicates.
                $existing_types = array();
                foreach ( $links as $link ) {
                    if ( is_array( $link ) && ! empty( $link['_type'] ) ) {
                        $existing_types[] = $link['_type'];
                    }
                }

                $to_add = array(
                    array( '_type' => 'pricing',        'url' => esc_url_raw( $pricing_url ) ),
                    array( '_type' => 'about',          'url' => esc_url_raw( $about_url ) ),
                    array( '_type' => 'terms',          'url' => esc_url_raw( $terms_url ) ),
                    array( '_type' => 'documentation',  'url' => esc_url_raw( $docs_url ) ),
                    array( '_type' => 'support',        'url' => esc_url_raw( $support_url ) ),
                );

                foreach ( $to_add as $entry ) {
                    if ( empty( $entry['_type'] ) || empty( $entry['url'] ) ) {
                        continue;
                    }
                    if ( in_array( $entry['_type'], $existing_types, true ) ) {
                        continue;
                    }
                    $links[] = $entry;
                }

                $data['providers'][ $idx ]['links'] = $links;
            }

            $response->set_data( $data );
        } catch ( \Throwable $e ) {
            // Fail-safe: do not block the response.
        }

        return $response;
    }

    public function render_admin_reconcile_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Wei Alipay.', 'wei-alipay' ) );
        }
        $result = null; $error = '';
		$nonce = isset( $_POST['woo_alipay_reconcile_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_alipay_reconcile_nonce'] ) ) : '';
		if ( '' !== $nonce && wp_verify_nonce( $nonce, 'woo_alipay_reconcile' ) ) {
			$out_trade_no = isset( $_POST['out_trade_no'] ) ? sanitize_text_field( wp_unslash( $_POST['out_trade_no'] ) ) : '';
			$trade_no = isset( $_POST['trade_no'] ) ? sanitize_text_field( wp_unslash( $_POST['trade_no'] ) ) : '';
            require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
            $main_gateway = new WC_Alipay(false);
            $config = Alipay_SDK_Helper::get_alipay_config(array(
                'appid' => $main_gateway->get_option('appid'),
                'private_key' => $main_gateway->get_option('private_key'),
                'public_key' => $main_gateway->get_option('public_key'),
                'sandbox' => $main_gateway->get_option('sandbox'),
            ));
            $query = Alipay_SDK_Helper::query_order( $out_trade_no, $trade_no, $config );
            if ( is_wp_error( $query ) ) {
                $error = $query->get_error_message();
            } else {
                $result = $query;
                // 可选：尝试同步 Woo 订单状态
                if ( isset($_POST['sync_order']) && $result['success'] && in_array( $result['trade_status'], array('TRADE_SUCCESS','TRADE_FINISHED'), true ) ) {
                    $orders = wc_get_orders( array( 'meta_query' => array( array( 'key' => '_alipay_out_trade_no', 'value' => $result['out_trade_no'] ) ), 'limit' => 1 ) );
                    if ( ! empty( $orders ) ) {
                        $order = $orders[0];
                        $expected_amount = Alipay_SDK_Helper::convert_currency(
                            $order->get_total(),
                            $order->get_currency(),
                            $main_gateway->get_option( 'exchange_rate', 0 )
                        );
                        $amount_matches = hash_equals(
                            Alipay_SDK_Helper::format_amount( $expected_amount ),
                            Alipay_SDK_Helper::format_amount( $result['total_amount'] )
                        );
                        $transaction_matches = ! $order->get_transaction_id() || hash_equals( (string) $order->get_transaction_id(), (string) $result['trade_no'] );
                        if ( 'alipay' !== $order->get_payment_method() || ! $amount_matches || ! $transaction_matches ) {
                            $error = __( 'The query result did not match the order payment method, amount or transaction number. Synchronization was refused.', 'wei-alipay' );
                        } elseif ( ! $order->is_paid() ) {
                            $order->payment_complete( $result['trade_no'] );
                            /* translators: %s: Alipay transaction number */
                            $order->add_order_note( sprintf( __( 'Reconciliation sync: marked paid with transaction number %s.', 'wei-alipay' ), $result['trade_no'] ) );
                        }
                    }
                }
            }
        }
        echo '<div class="wrap wei-alipay-admin"><h1>' . esc_html__( 'Single-order Reconciliation', 'wei-alipay' ) . '</h1>';
        echo '<p class="wei-alipay-admin__description">' . esc_html__( 'Query one transaction from the configured Alipay environment and optionally synchronize a matching WooCommerce order.', 'wei-alipay' ) . '</p>';
        echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Querying contacts Alipay. Synchronization is offered only after the payment method, amount and transaction number match the order.', 'wei-alipay' ) . '</p></div>';
        echo '<form class="card wei-alipay-card-wide" method="post">';
        wp_nonce_field( 'woo_alipay_reconcile', 'woo_alipay_reconcile_nonce' );
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="out_trade_no">' . esc_html__( 'Merchant order number (out_trade_no)', 'wei-alipay' ) . '</label></th><td><input type="text" name="out_trade_no" id="out_trade_no" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="trade_no">' . esc_html__( 'Alipay transaction number (trade_no)', 'wei-alipay' ) . '</label></th><td><input type="text" name="trade_no" id="trade_no" class="regular-text" /></td></tr>';
        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary" data-wei-confirm="' . esc_attr__( 'Query this transaction in the configured Alipay environment?', 'wei-alipay' ) . '">' . esc_html__( 'Query transaction', 'wei-alipay' ) . '</button></p>';
        if ( $result ) {
            echo '<h2>' . esc_html__( 'Query result', 'wei-alipay' ) . '</h2>';
            echo '<pre class="wei-alipay-code">' . esc_html( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
            echo '<p><label><input type="checkbox" name="sync_order" value="1" /> ' . esc_html__( 'If the payment succeeded, synchronize the matching WooCommerce order as paid', 'wei-alipay' ) . '</label></p>';
            echo '<p class="submit"><button type="submit" class="button" data-wei-confirm="' . esc_attr__( 'Re-query Alipay and synchronize the matching order if every binding check passes?', 'wei-alipay' ) . '">' . esc_html__( 'Synchronize order status', 'wei-alipay' ) . '</button></p>';
        }
        if ( $error ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
        }
        echo '</form></div>';
    }

	public function add_gateway( $methods ) {
		$methods[] = 'WC_Alipay';

		// Extension gateways are registered by their own plugins now.
		return $methods;
	}

	public function plugin_edit_link( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=alipay' );

		return array_merge(
			array(
				'settings' => '<a href="' . $url . '">' . __( 'Settings', 'wei-alipay' ) . '</a>',
			),
			$links
		);
	}

	public function display_order_meta_for_customer( $total_rows, $order ) {
		$trade_no = $order->get_transaction_id();

		if ( ! empty( $trade_no ) && $order->get_payment_method() === 'alipay' ) {
			$new_row = array(
				'alipay_trade_no' => array(
					'label' => __( 'Transaction:', 'wei-alipay' ),
					'value' => $trade_no,
				),
			);

			$total_rows = array_merge( array_splice( $total_rows, 0, 2 ), $new_row, $total_rows );
        }

        // 花呗分期：展示期数与免息
        if ( $order->get_payment_method() === 'alipay_installment' ) {
            $period = $order->get_meta('_alipay_installment_period');
            if ( $period ) {
                /* translators: %s: number of installment periods */
                $label = sprintf( __( '分期：%s期', 'wei-alipay' ), esc_html( $period ) );
                // 判断是否免息
                $fee_note = '';
                $installment_gateway = null;
                if ( function_exists('WC') && isset( WC()->payment_gateways ) ) {
                    $gateways = WC()->payment_gateways->payment_gateways();
                    $installment_gateway = isset($gateways['alipay_installment']) ? $gateways['alipay_installment'] : null;
                }
                if ( $installment_gateway && 'seller' === $installment_gateway->get_option('fee_bearer', 'user') ) {
                    $fee_note = '（' . __( '免息', 'wei-alipay' ) . '）';
                }
                $new_row = array(
                    'alipay_installment' => array(
                        'label' => __( '分期：', 'wei-alipay' ),
                        'value' => esc_html( $period ) . $fee_note,
                    ),
                );
                $total_rows = array_merge( $total_rows, $new_row );
            }
        }

        return $total_rows;
	}

	public function pay_notification_endpoint( $endpoint ) {

		return 'wc-api/WC_Alipay/';
	}

	public function add_orphan_transaction_woocommerce_email( $email_classes ) {
		require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wc-email-alipay-orphan-transaction.php';

		$email_classes['WC_Email_Alipay_Orphan_Transaction'] = new WC_Email_Alipay_Orphan_Transaction();

		return $email_classes;
	}

	public function woocommerce_gateway_alipay_woocommerce_block_support() {
		// 始终挂载注册回调；在 Blocks 触发该钩子时再加载依赖并注册方法
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
				// 到这里时，Blocks 已加载，父类可用，安全加载支持类
				if ( file_exists( WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay-blocks-support.php' ) ) {
					require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay-blocks-support.php';
				}


				// 注册主支付宝网关（扩展的 Blocks 支持由扩展自身注册）
				if ( class_exists( 'WC_Alipay_Blocks_Support' ) ) {
					$payment_method_registry->register( new WC_Alipay_Blocks_Support() );
				}
			}
		);
	}

	/**
	 * Add custom cron intervals
	 *
	 * @param array $schedules
	 * @return array
	 */
	public function add_cron_intervals( $schedules ) {
		// Add 5 minute interval
		if ( ! isset( $schedules['woo_alipay_5min'] ) ) {
			$schedules['woo_alipay_5min'] = array(
				'interval' => 300,
				'display'  => __( 'Every 5 Minutes', 'wei-alipay' ),
			);
		}

		// Add 10 minute interval
		if ( ! isset( $schedules['woo_alipay_10min'] ) ) {
			$schedules['woo_alipay_10min'] = array(
				'interval' => 600,
				'display'  => __( 'Every 10 Minutes', 'wei-alipay' ),
			);
		}

		// Add 15 minute interval
		if ( ! isset( $schedules['woo_alipay_15min'] ) ) {
			$schedules['woo_alipay_15min'] = array(
				'interval' => 900,
				'display'  => __( 'Every 15 Minutes', 'wei-alipay' ),
			);
		}

		// Add 30 minute interval
		if ( ! isset( $schedules['woo_alipay_30min'] ) ) {
			$schedules['woo_alipay_30min'] = array(
				'interval' => 1800,
				'display'  => __( 'Every 30 Minutes', 'wei-alipay' ),
			);
		}

		return $schedules;
	}
}
