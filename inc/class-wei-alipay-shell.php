<?php
/**
 * Wei Alipay 4.1 administration shell built on vendored wenpai-admin-ui v0.13.8
 * with scoped Weixiaoduo brand tokens and optional Alipay platform palette toggle.
 *
 * @package WeiAlipay
 * @since   4.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Wei_Alipay_Shell {

	const PALETTE_META_KEY = 'wei_alipay_admin_palette';
	const PALETTE_DEFAULT  = 'weixiaoduo';
	const PALETTE_PLATFORM = 'platform';

	/**
	 * Register shell asset loading and palette preference persistence hooks.
	 */
	public static function register() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
		add_action( 'admin_post_wei_alipay_save_palette', array( __CLASS__, 'handle_save_palette' ) );
	}

	/**
	 * Return the active admin palette ('weixiaoduo' or 'platform') for the current user.
	 *
	 * @return string
	 */
	public static function active_palette() {
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$stored  = $user_id ? (string) get_user_meta( $user_id, self::PALETTE_META_KEY, true ) : '';
		return self::PALETTE_PLATFORM === $stored ? self::PALETTE_PLATFORM : self::PALETTE_DEFAULT;
	}

	/**
	 * Save the current admin user's 2-mode palette preference.
	 */
	public static function handle_save_palette() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to update Wei Alipay preferences.', 'wei-alipay' ) );
		}
		check_admin_referer( 'wei_alipay_save_palette' );

		$palette = isset( $_POST['wei_alipay_palette'] ) ? sanitize_key( wp_unslash( $_POST['wei_alipay_palette'] ) ) : self::PALETTE_DEFAULT;
		$palette = self::PALETTE_PLATFORM === $palette ? self::PALETTE_PLATFORM : self::PALETTE_DEFAULT;
		update_user_meta( get_current_user_id(), self::PALETTE_META_KEY, $palette );

		$return_view = isset( $_POST['return_view'] ) ? sanitize_key( wp_unslash( $_POST['return_view'] ) ) : 'overview';
		if ( ! in_array( $return_view, array( 'overview', 'payments', 'account', 'help' ), true ) ) {
			$return_view = 'overview';
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'wei-alipay',
					'view'            => $return_view,
					'palette-updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Enqueue vendored wenpai-admin-ui kit and Weixiaoduo brand token overlay on Wei Alipay pages only.
	 * Never loads on WooCommerce wc-settings screens so native gateway forms stay untouched.
	 *
	 * @param string $hook Current admin screen hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! self::is_shell_hook( (string) $hook ) ) {
			return;
		}
		if ( ! class_exists( 'Wenpai_Admin_Loader' ) ) {
			return;
		}

		$palette_class = self::PALETTE_PLATFORM === self::active_palette() ? 'wei-palette-platform' : 'wei-palette-weixiaoduo';
		Wenpai_Admin_Loader::enqueue(
			array(
				'body' => 'wei-alipay-admin',
				'full' => true,
			)
		);
		add_filter(
			'admin_body_class',
			static function( $classes ) use ( $palette_class ) {
				return trim( (string) $classes . ' ' . $palette_class );
			}
		);

		$overlay = WEI_ALIPAY_PLUGIN_PATH . 'css/admin/wei-alipay-shell.css';
		if ( file_exists( $overlay ) ) {
			wp_enqueue_style(
				'wei-alipay-shell',
				WEI_ALIPAY_PLUGIN_URL . 'css/admin/wei-alipay-shell.css',
				array( Wenpai_Admin_Loader::STYLE_HANDLE . '-wp' ),
				(string) filemtime( $overlay )
			);
		}
	}

	/**
	 * Whether an admin hook belongs to Wei Alipay's own standalone screens.
	 *
	 * @param string $hook Screen hook suffix.
	 * @return bool
	 */
	public static function is_shell_hook( $hook ) {
		return 'toplevel_page_wei-alipay' === $hook || 0 === strpos( $hook, 'wei-alipay_page_wei-alipay-' );
	}

	/**
	 * Whether vendored wenpai-admin-ui classes are booted and ready.
	 *
	 * @return bool
	 */
	public static function is_kit_ready() {
		return class_exists( 'Wenpai_Admin_Shell' ) && class_exists( 'Wenpai_Admin_UI' ) && class_exists( 'Wenpai_Admin_Icons' );
	}

	/**
	 * Open the shared 5-tab top-bar shell for any Wei Alipay standalone screen.
	 *
	 * @param string $current Active tab id (overview|payments|reconcile|webhooks|account|help).
	 */
	public static function open( $current ) {
		if ( ! self::is_kit_ready() ) {
			return;
		}
		$palette = self::active_palette();
		echo '<div class="wei-alipay-shell-root" data-wei-palette="' . esc_attr( $palette ) . '">';
		Wenpai_Admin_Shell::open(
			array(
				'slug'               => 'wei-alipay',
				'name'               => 'Wei Alipay',
				'mark_html'          => self::brand_mark_svg(),
				'home_url'           => admin_url( 'admin.php?page=wei-alipay&view=overview' ),
				'tabs'               => self::shell_tabs(),
				'current'            => $current,
				'help_url'           => admin_url( 'admin.php?page=wei-alipay&view=help' ),
				'help_label'         => __( '帮助', 'wei-alipay' ),
				'help_icon_html'     => Wenpai_Admin_Icons::svg( 'help' ),
				'feedback_url'       => 'https://www.weixiaoduo.com/support',
				'feedback_label'     => __( '支持', 'wei-alipay' ),
				'feedback_icon_html' => Wenpai_Admin_Icons::svg( 'feedback' ),
				'main_class'         => 'wenpai-measure-wide',
			)
		);
	}

	/**
	 * Close the shared top-bar shell and print footer.
	 */
	public static function close() {
		if ( ! self::is_kit_ready() ) {
			return;
		}
		Wenpai_Admin_Shell::close(
			array(
				'version' => 'Wei Alipay ' . ( defined( 'WEI_ALIPAY_VERSION' ) ? WEI_ALIPAY_VERSION : '4.1.0' ),
				'links'   => array(
					array(
						'url'   => 'https://www.weixiaoduo.com/plugins',
						'label' => __( '插件目录', 'wei-alipay' ),
					),
					array(
						'url'   => 'https://www.weixiaoduo.com/support',
						'label' => __( '支持', 'wei-alipay' ),
					),
				),
			)
		);
		echo '</div>';
	}

	/**
	 * Render the primary Wei Alipay admin screen (overview / payments / account / help).
	 *
	 * @param array<string,mixed> $ctx Runtime context assembled by Wei_Alipay_Features.
	 */
	public static function render_primary_page( array $ctx ) {
		$view = isset( $ctx['view'] ) ? (string) $ctx['view'] : 'overview';
		if ( ! in_array( $view, array( 'overview', 'payments', 'account', 'help' ), true ) ) {
			$view = 'overview';
		}

		self::open( $view );

		if ( ! empty( $ctx['notices'] ) && is_array( $ctx['notices'] ) ) {
			foreach ( $ctx['notices'] as $notice ) {
				$type  = isset( $notice['type'] ) ? (string) $notice['type'] : 'info';
				$level = 'success' === $type ? 'ok' : ( 'error' === $type ? 'bad' : ( 'warning' === $type ? 'warn' : 'info' ) );
				Wenpai_Admin_UI::notice(
					array(
						'level' => $level,
						'text'  => isset( $notice['message'] ) ? wp_strip_all_tags( (string) $notice['message'] ) : '',
					)
				);
			}
		}

		if ( 'payments' === $view ) {
			self::render_payments( $ctx );
		} elseif ( 'account' === $view ) {
			self::render_account( $ctx );
		} elseif ( 'help' === $view ) {
			self::render_help();
		} else {
			self::render_overview( $ctx );
		}

		self::close();
	}

	/**
	 * Render the approved Overview screen.
	 *
	 * @param array<string,mixed> $ctx Runtime context.
	 */
	private static function render_overview( array $ctx ) {
		$account      = (array) ( $ctx['account'] ?? array() );
		$connected    = 'connected' === ( $account['status'] ?? '' );
		$main_active  = ! empty( $ctx['main_active'] );
		$main_ready   = ! empty( $ctx['main_ready'] );
		$enabled_map  = (array) ( $ctx['enabled'] ?? array() );
		$health       = (array) ( $ctx['health'] ?? array() );
		$activity     = (array) ( $ctx['activity'] ?? array() );
		$secret       = (array) ( $ctx['secret_health'] ?? array() );
		$sdk_count    = self::count_sdk_files();
		$main_url     = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=alipay' );
		$payments_url = admin_url( 'admin.php?page=wei-alipay&view=payments' );
		$account_url  = admin_url( 'admin.php?page=wei-alipay&view=account' );

		$active_methods = array();
		if ( $main_active ) {
			$active_methods[] = __( '主网关', 'wei-alipay' );
		}
		foreach ( array( 'f2f' => __( '当面付', 'wei-alipay' ), 'huabei' => __( '花呗分期', 'wei-alipay' ), 'barcode' => __( '条码付', 'wei-alipay' ) ) as $k => $lbl ) {
			if ( 'yes' === ( $enabled_map[ $k ] ?? 'no' ) ) {
				$active_methods[] = $lbl;
			}
		}

		self::render_page_head(
			__( '概览', 'wei-alipay' ),
			$main_ready
				? __( '主网关已就绪，可查看通道自检与近期回调。', 'wei-alipay' )
				: __( '填入支付宝应用密钥后即可开始收款，无需先连接账户。', 'wei-alipay' )
		);

		if ( ! $main_ready ) {
			Wenpai_Admin_UI::next(
				array(
					'icon_html' => Wenpai_Admin_Icons::svg( 'shield-check' ),
					'title'     => __( '完成主网关密钥配置即可开始收款', 'wei-alipay' ),
					'desc'      => __( '前往 WooCommerce 付款设置填入 AppID、应用私钥与支付宝公钥，私钥将自动以 AES-256-GCM 加密保存。', 'wei-alipay' ),
					'actions'   => array(
						array(
							'label'   => __( '配置主网关', 'wei-alipay' ),
							'href'    => $main_url,
							'variant' => 'primary',
						),
					),
				)
			);
		}

		$cb_total   = (int) ( $health['callback_success'] ?? 0 ) + (int) ( $health['callback_failed'] ?? 0 );
		$cb_rate    = $cb_total > 0 ? round( ( (int) ( $health['callback_success'] ?? 0 ) / $cb_total ) * 100 ) . '%' : __( '无样本', 'wei-alipay' );
		$cb_sub     = $cb_total > 0
			/* translators: %d: verified callback count */
			? sprintf( __( '%d 笔已验签通知', 'wei-alipay' ), $cb_total )
			: __( '尚未收到回调样本', 'wei-alipay' );

		Wenpai_Admin_UI::statbar(
			array(
				'items' => array(
					array(
						'label' => __( '主网关状态', 'wei-alipay' ),
					'value' => $main_ready ? __( '已就绪', 'wei-alipay' ) : __( '待配置', 'wei-alipay' ),
					'desc'  => $main_ready ? __( '电脑网站与手机网站正常', 'wei-alipay' ) : __( '缺少 AppID 或私钥', 'wei-alipay' ),
				),
				array(
					'label' => __( '已启用方式', 'wei-alipay' ),
					'value' => count( $active_methods ) . ' / 4',
					'desc'  => ! empty( $active_methods ) ? implode( '、', $active_methods ) : __( '尚未开启支付方式', 'wei-alipay' ),
				),
				array(
					'label' => __( '近期回调成功率', 'wei-alipay' ),
					'value' => $cb_rate,
					'desc'  => $cb_sub,
				),
					array(
						'label' => __( '薇晓朵账户', 'wei-alipay' ),
						'value' => $connected ? __( '已连接', 'wei-alipay' ) : __( '未连接', 'wei-alipay' ),
						'desc'  => $connected ? (string) ( $account['account_label'] ?? '' ) : __( '主网关可直接使用', 'wei-alipay' ),
					),
				),
			)
		);

		echo '<div class="wenpai-grid-2">';

		Wenpai_Admin_UI::card_open(
			array(
				'tile'            => false,
				'title'           => __( '收款通道自检', 'wei-alipay' ),
				'head_actions_html' => Wenpai_Admin_UI::button_html(
					array(
						'label'   => __( '配置主网关', 'wei-alipay' ),
						'href'    => $main_url,
						'variant' => 'secondary',
					)
				),
			)
		);
		$secret_ok = 'ready' === ( $secret['status'] ?? '' );
		Wenpai_Admin_UI::metric_tiles(
			array(
				'items' => array(
					array(
						'label' => __( '私钥加密状态', 'wei-alipay' ),
						'badge' => 'RSA2',
						'value' => $secret_ok ? __( '正常', 'wei-alipay' ) : __( '待配置', 'wei-alipay' ),
						'sub'   => $secret_ok ? __( '已加密存储，不回显明文', 'wei-alipay' ) : __( '前往主网关填入密钥', 'wei-alipay' ),
						'tone'  => $secret_ok ? '' : 'warn',
					),
					array(
						'label' => __( '异步回调入口', 'wei-alipay' ),
						'badge' => 'POST',
						'value' => is_ssl() ? __( 'HTTPS 就绪', 'wei-alipay' ) : __( '非 HTTPS', 'wei-alipay' ),
						'sub'   => __( '先验签后查单，未验签不写库', 'wei-alipay' ),
						'tone'  => is_ssl() ? '' : 'warn',
					),
					array(
						'label' => __( '单号解析范围', 'wei-alipay' ),
						'badge' => is_multisite() ? sprintf( __( '博客 #%d', 'wei-alipay' ), get_current_blog_id() ) : __( '单站点', 'wei-alipay' ),
						'value' => __( '正常', 'wei-alipay' ),
						'sub'   => 'WooA / F2F / HBFQ / WooB',
						'tone'  => '',
					),
					array(
						'label' => __( '内置 SDK 体积', 'wei-alipay' ),
						'badge' => 'allowlist',
						'value' => sprintf( __( '%d 个文件', 'wei-alipay' ), $sdk_count ),
						'sub'   => ! is_dir( WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/test' ) ? __( '无测试文件残留', 'wei-alipay' ) : __( '含测试目录', 'wei-alipay' ),
						'tone'  => $sdk_count <= 45 ? '' : 'warn',
					),
				),
			)
		);
		Wenpai_Admin_UI::card_foot(
			array(
				'start'   => true,
				'meta'    => __( '本地自检不代替支付宝沙箱实付验收', 'wei-alipay' ),
				'actions' => array(
					array(
						'label' => __( '管理支付方式', 'wei-alipay' ),
						'href'  => $payments_url,
					),
				),
			)
		);
		Wenpai_Admin_UI::card_close();

		Wenpai_Admin_UI::card_open(
			array(
				'tile'              => false,
				'title'             => __( '近 7 日本地活动', 'wei-alipay' ),
				'head_actions_html' => Wenpai_Admin_UI::button_html(
					array(
						'label'   => __( '回调日志', 'wei-alipay' ),
						'href'    => admin_url( 'admin.php?page=wei-alipay-webhooks' ),
						'variant' => 'ghost',
					)
				),
			)
		);
		$paid_7d   = (int) ( $activity['paid'] ?? 0 );
		$failed_7d = (int) ( $activity['failed'] ?? 0 );
		$refund_7d = (int) ( $activity['refunded'] ?? 0 );
		Wenpai_Admin_UI::tag_feed(
			array(
				'items' => array(
					array(
						'tag'   => __( '收款', 'wei-alipay' ),
						'tone'  => $paid_7d > 0 ? 'ok' : 'mute',
						'title' => sprintf( __( '近 7 日已付款订单：%d 笔', 'wei-alipay' ), $paid_7d ),
						'when'  => __( '7 日内', 'wei-alipay' ),
					),
					array(
						'tag'   => __( '失败', 'wei-alipay' ),
						'tone'  => $failed_7d > 0 ? 'warn' : 'mute',
						'title' => sprintf( __( '近 7 日失败订单：%d 笔', 'wei-alipay' ), $failed_7d ),
						'when'  => __( '7 日内', 'wei-alipay' ),
					),
					array(
						'tag'   => __( '退款', 'wei-alipay' ),
						'tone'  => 'mute',
						'title' => sprintf( __( '近 7 日退款操作：%d 笔', 'wei-alipay' ), $refund_7d ),
						'when'  => __( '7 日内', 'wei-alipay' ),
					),
				),
			)
		);
		Wenpai_Admin_UI::card_foot(
			array(
				'start'   => true,
				'meta'    => __( '仅记录本地匿名计数，不存金额与买家资料', 'wei-alipay' ),
				'actions' => array(
					array(
						'label' => __( '进入对账', 'wei-alipay' ),
						'href'  => admin_url( 'admin.php?page=wei-alipay-reconcile-pro' ),
					),
				),
			)
		);
		Wenpai_Admin_UI::card_close();

		echo '</div>';

		// Low-frequency collapsible environment & palette preference drawer
		Wenpai_Admin_UI::card_open(
			array(
				'tile'  => false,
				'title' => __( '环境与界面偏好', 'wei-alipay' ),
			)
		);
		echo '<div class="wenpai-folds"><details class="wenpai-fold">';
		echo '<summary><span class="tile">' . Wenpai_Admin_Icons::svg( 'tools' ) . '</span><span class="wenpai-fold__text"><span class="wenpai-fold__title">' . esc_html__( '环境信息与品牌配色切换', 'wei-alipay' ) . '</span><span class="wenpai-fold__desc">' . esc_html( (string) ( $ctx['runtime_summary'] ?? '' ) ) . '</span></span><span class="wenpai-fold__side">' . Wenpai_Admin_Icons::svg( 'chevron-down', 'wenpai-fold__chevron' ) . '</span></summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icons.
		echo '<div class="wenpai-fold__body">';
		echo '<p>' . esc_html( (string) ( $ctx['runtime_summary'] ?? '' ) ) . '</p>';
		self::render_palette_toggle_form( 'overview' );
		echo '</div></details></div>';
		Wenpai_Admin_UI::card_close();

		// Quick actions grid
		Wenpai_Admin_UI::card_open(
			array(
				'tile'  => false,
				'title' => __( '常用入口', 'wei-alipay' ),
			)
		);
		Wenpai_Admin_UI::icon_grid(
			array(
				'columns' => 6,
				'items'   => array(
					array( 'label' => __( '主网关设置', 'wei-alipay' ), 'href' => $main_url, 'icon_html' => Wenpai_Admin_Icons::svg( 'settings' ), 'state' => $main_ready ? 'ok' : '' ),
					array( 'label' => __( '当面付配置', 'wei-alipay' ), 'href' => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=alipay_facetopay' ), 'icon_html' => Wenpai_Admin_Icons::svg( 'flash' ), 'state' => 'yes' === ( $enabled_map['f2f'] ?? 'no' ) ? 'ok' : '' ),
					array( 'label' => __( '花呗分期', 'wei-alipay' ), 'href' => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=alipay_installment' ), 'icon_html' => Wenpai_Admin_Icons::svg( 'sparkle' ), 'state' => 'yes' === ( $enabled_map['huabei'] ?? 'no' ) ? 'ok' : '' ),
					array( 'label' => __( '条码付', 'wei-alipay' ), 'href' => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=alipay_barcode' ), 'icon_html' => Wenpai_Admin_Icons::svg( 'search' ), 'state' => 'yes' === ( $enabled_map['barcode'] ?? 'no' ) ? 'ok' : '' ),
					array( 'label' => __( '拉取对账单', 'wei-alipay' ), 'href' => admin_url( 'admin.php?page=wei-alipay-reconcile-pro' ), 'icon_html' => Wenpai_Admin_Icons::svg( 'download' ) ),
					array( 'label' => __( '薇晓朵账户', 'wei-alipay' ), 'href' => $account_url, 'icon_html' => Wenpai_Admin_Icons::svg( 'user' ), 'state' => $connected ? 'ok' : '' ),
				),
			)
		);
		Wenpai_Admin_UI::card_close();
	}

	/**
	 * Render the approved Payment Methods & Local Tools screen (all 3 latch states).
	 *
	 * @param array<string,mixed> $ctx Runtime context.
	 */
	private static function render_payments( array $ctx ) {
		$account      = (array) ( $ctx['account'] ?? array() );
		$connected    = 'connected' === ( $account['status'] ?? '' );
		$enabled      = (array) ( $ctx['enabled'] ?? array() );
		$registry     = Wei_Alipay_Features::registry();
		$account_url  = admin_url( 'admin.php?page=wei-alipay&view=account' );
		$main_url     = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=alipay' );
		$has_latched  = ! $connected && (
			'yes' === ( $enabled['f2f'] ?? 'no' ) ||
			'yes' === ( $enabled['huabei'] ?? 'no' ) ||
			'yes' === ( $enabled['barcode'] ?? 'no' )
		);

		self::render_page_head(
			__( '支付方式', 'wei-alipay' ),
			$connected
				? __( '已连接薇晓朵账户，可按需开启或调整可用支付方式。', 'wei-alipay' )
				: ( $has_latched
					? __( '账户已断开：已开启的扩展继续收款，重连后可修改选项。', 'wei-alipay' )
					: __( '主网关安装即用；当面付、花呗与条码付连上账户后可启用。', 'wei-alipay' ) )
		);

		if ( ! $connected ) {
			Wenpai_Admin_UI::next(
				array(
					'icon_html' => Wenpai_Admin_Icons::svg( $has_latched ? 'shield-check' : 'user' ),
					'title'     => $has_latched
						? __( '账户已断开：已开启的扩展继续收款，选项已锁定', 'wei-alipay' )
						: __( '连接薇晓朵账户以启用扩展支付方式', 'wei-alipay' ),
					'desc'      => $has_latched
						? __( '已开启的支付方式仍在正常收款、处理回调与退款；重新连接账户后可继续调整开关与参数。', 'wei-alipay' )
						: __( '连接只用于站点身份认证，不是许可证。已启用的方式即便断开也照常收款。', 'wei-alipay' ),
					'actions'   => array(
						array(
							'label'   => $has_latched ? __( '重新连接账户', 'wei-alipay' ) : __( '连接薇晓朵账户', 'wei-alipay' ),
							'href'    => $account_url,
							'variant' => 'primary',
						),
					),
				)
			);
		}

		// Payment methods form (saves group=payment)
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wei-alipay-feature-form>';
		echo '<input type="hidden" name="action" value="wei_alipay_save_features">';
		echo '<input type="hidden" name="feature_group" value="payment">';
		wp_nonce_field( 'wei_alipay_save_features' );

		// Main gateway + f2f + huabei top 3 cards
		echo '<div class="wenpai-grid-3">';
		self::render_payment_card(
			__( '支付宝主网关', 'wei-alipay' ),
			__( '电脑网站与手机网站收银台，支持退款与查单，安装即可直接使用。', 'wei-alipay' ),
			Wenpai_Admin_Icons::svg( 'shield-check' ),
			'ok',
			'<span class="pill ok">' . esc_html__( '安装即用', 'wei-alipay' ) . '</span>',
			__( '已就绪 · 不受连接影响', 'wei-alipay' ),
			$main_url,
			__( '配置主网关', 'wei-alipay' )
		);

		foreach ( array( 'f2f' => 'flash', 'huabei' => 'sparkle' ) as $key => $icon_name ) {
			if ( ! isset( $registry[ $key ] ) ) {
				continue;
			}
			$feat       = $registry[ $key ];
			$is_on      = 'yes' === ( $enabled[ $key ] ?? 'no' );
			$can_toggle = $connected;
			$cfg_url    = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . rawurlencode( (string) $feat['settings_section'] ) );
			$foot_meta  = $is_on
				? ( $connected ? __( '已开启', 'wei-alipay' ) : __( '运行中 · 选项已锁定', 'wei-alipay' ) )
				: ( $connected ? __( '未开启', 'wei-alipay' ) : __( '连接后可启用', 'wei-alipay' ) );
			$foot_href  = $connected ? ( $is_on ? $cfg_url : '' ) : $account_url;
			$foot_label = $connected ? ( $is_on ? __( '前往配置', 'wei-alipay' ) : '' ) : ( $is_on ? __( '重连后修改', 'wei-alipay' ) : __( '去连接', 'wei-alipay' ) );
			self::render_payment_card(
				(string) $feat['name'],
				(string) $feat['description'],
				Wenpai_Admin_Icons::svg( $icon_name ),
				$is_on ? 'accent' : '',
				self::switch_html( 'features[]', $key, (string) $feat['name'], $is_on, ! $can_toggle, false ),
				$foot_meta,
				$foot_href,
				$foot_label
			);
		}
		echo '</div>';

		// More payment methods card (barcode + 4 unavailable rows)
		Wenpai_Admin_UI::card_open(
			array(
				'tile'  => false,
				'title' => __( '更多支付方式', 'wei-alipay' ),
			)
		);
		foreach ( array( 'barcode', 'cross_border', 'fund_auth', 'subscriptions', 'profitsharing' ) as $key ) {
			if ( ! isset( $registry[ $key ] ) ) {
				continue;
			}
			$feat = $registry[ $key ];
			$gate = (string) ( $feat['gate'] ?? 'unavailable' );
			$is_on = 'yes' === ( $enabled[ $key ] ?? 'no' ) && 'unavailable' !== $gate;
			echo '<div class="simple-row">';
			$row_icons = array(
				'barcode'       => 'search',
				'cross_border'  => 'cloud',
				'fund_auth'     => 'shield-check',
				'subscriptions' => 'refresh',
				'profitsharing' => 'equalizer',
			);
			echo '<span class="tile">' . Wenpai_Admin_Icons::svg( $row_icons[ $key ] ?? 'shield-check' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon from kit.
			echo '<div><div class="t">' . esc_html( (string) $feat['name'] ) . '</div><div class="d">' . esc_html( (string) $feat['description'] ) . '</div></div>';
			echo '<div class="r">';
			if ( 'unavailable' === $gate ) {
				$pill_cls  = $connected ? 'pill warn' : 'pill mute';
				$pill_text = $connected ? __( '暂不开放', 'wei-alipay' ) : __( '连接后查看', 'wei-alipay' );
				echo '<span class="' . esc_attr( $pill_cls ) . '">' . esc_html( $pill_text ) . '</span>';
			} else {
				if ( $is_on && $connected && ! empty( $feat['settings_section'] ) ) {
					$cfg = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . rawurlencode( (string) $feat['settings_section'] ) );
					echo '<a class="btn btn-ghost" href="' . esc_url( $cfg ) . '">' . esc_html__( '前往配置', 'wei-alipay' ) . '</a>';
				}
				if ( $connected || $is_on ) {
					echo self::switch_html( 'features[]', $key, (string) $feat['name'], $is_on, ! $connected ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside switch_html.
				} else {
					echo '<span class="pill warn">' . esc_html__( '连接后可启用', 'wei-alipay' ) . '</span>';
				}
			}
			echo '</div></div>';
		}
		if ( $connected ) {
			Wenpai_Admin_UI::card_foot(
				array(
					'start'   => true,
					'meta'    => __( '关闭扩展不会删除已保存的参数配置', 'wei-alipay' ),
					'actions' => array(
						array(
							'label'   => __( '保存支付方式设置', 'wei-alipay' ),
							'type'    => 'submit',
							'variant' => 'primary',
						),
					),
				)
			);
		}
		Wenpai_Admin_UI::card_close();
		echo '</form>';

		// Local operational tools form (reconcile + webhook_retry — ALWAYS configurable even when disconnected!)
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wei-alipay-feature-form>';
		echo '<input type="hidden" name="action" value="wei_alipay_save_features">';
		echo '<input type="hidden" name="feature_group" value="operations">';
		wp_nonce_field( 'wei_alipay_save_features' );

		Wenpai_Admin_UI::card_open(
			array(
				'tile'  => false,
				'title' => __( '本地运营工具', 'wei-alipay' ),
			)
		);
		foreach ( array( 'reconcile' => array( 'download', __( '进入对账', 'wei-alipay' ) ), 'webhook_retry' => array( 'refresh', __( '查看日志', 'wei-alipay' ) ) ) as $key => $meta ) {
			if ( ! isset( $registry[ $key ] ) ) {
				continue;
			}
			$feat  = $registry[ $key ];
			$is_on = 'yes' === ( $enabled[ $key ] ?? 'no' );
			$url   = admin_url( 'admin.php?page=' . rawurlencode( (string) $feat['page'] ) );
			echo '<div class="simple-row">';
			echo '<span class="tile">' . Wenpai_Admin_Icons::svg( $meta[0] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon from kit.
			echo '<div><div class="t">' . esc_html( (string) $feat['name'] ) . '</div><div class="d">' . esc_html( (string) $feat['description'] ) . '</div></div>';
			echo '<div class="r">';
			if ( $is_on ) {
				echo '<a class="btn btn-ghost" href="' . esc_url( $url ) . '">' . esc_html( $meta[1] ) . '</a>';
			}
			echo self::switch_html( 'features[]', $key, (string) $feat['name'], $is_on, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside switch_html.
			echo '</div></div>';
		}
		Wenpai_Admin_UI::card_foot(
			array(
				'start'   => true,
				'meta'    => __( '本地工具无需连接薇晓朵账户，随时可用', 'wei-alipay' ),
				'actions' => array(
					array(
						'label'   => __( '保存本地工具设置', 'wei-alipay' ),
						'type'    => 'submit',
						'variant' => 'secondary',
					),
				),
			)
		);
		Wenpai_Admin_UI::card_close();
		echo '</form>';
	}

	/**
	 * Render the approved Account page with live PKCE S256 connect/disconnect & palette switch.
	 *
	 * @param array<string,mixed> $ctx Runtime context.
	 */
	private static function render_account( array $ctx ) {
		$account   = (array) ( $ctx['account'] ?? array() );
		$connected = 'connected' === ( $account['status'] ?? '' );

		self::render_page_head(
			__( '薇晓朵账户', 'wei-alipay' ),
			__( '连接用于确认本站归属身份，开启扩展支付方式后即便断开也照常收款。', 'wei-alipay' )
		);

		Wenpai_Admin_UI::card_open(
			array(
				'tile'  => false,
				'title' => $connected ? __( '已连接站点', 'wei-alipay' ) : __( '站点连接认证', 'wei-alipay' ),
			)
		);
		echo '<div class="simple-row">';
		if ( $connected && ! empty( $account['avatar_url'] ) ) {
			echo '<span class="wei-alipay-avatar-wrap">' . Wenpai_Admin_Icons::svg( 'user' ) . '<img class="wei-alipay-avatar" src="' . esc_url( (string) $account['avatar_url'] ) . '" alt="" aria-hidden="true" width="40" height="40" loading="lazy" decoding="async" referrerpolicy="no-referrer"></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon.
		} else {
			echo '<span class="tile">' . Wenpai_Admin_Icons::svg( 'user' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon from kit.
		}
		if ( $connected ) {
			echo '<div><div class="t">' . esc_html( (string) ( $account['account_label'] ?? __( '薇晓朵账户', 'wei-alipay' ) ) ) . '</div>';
			echo '<div class="d">' . esc_html(
				sprintf(
					/* translators: 1: site host, 2: last sync time */
					__( '已连接站点 %1$s · 最近同步 %2$s', 'wei-alipay' ),
					(string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
					(string) ( $account['last_sync_label'] ?? __( '刚刚', 'wei-alipay' ) )
				)
			) . '</div></div>';
			echo '<div class="r"><span class="pill ok">' . esc_html__( '已连接', 'wei-alipay' ) . '</span>';
			if ( ! empty( $account['manage_url'] ) ) {
				echo '<a class="btn btn-secondary" href="' . esc_url( (string) $account['manage_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '在 Weixiaoduo.com 中管理', 'wei-alipay' ) . '</a>';
			}
			echo '</div>';
		} else {
			echo '<div><div class="t">' . esc_html__( '尚未连接薇晓朵账户', 'wei-alipay' ) . '</div>';
			echo '<div class="d">' . esc_html__( '前往 account.weixiaoduo.com 登录确认即可完成本站认证，无需手动复制密钥。', 'wei-alipay' ) . '</div></div>';
			echo '<div class="r"><a class="btn btn-primary" href="' . esc_url( (string) ( $account['connect_url'] ?? Wei_Alipay_Account_Client::connect_action_url() ) ) . '">' . esc_html__( '连接薇晓朵账户', 'wei-alipay' ) . '</a></div>';
		}
		echo '</div>';
		Wenpai_Admin_UI::card_close();

		Wenpai_Admin_UI::card_open(
			array(
				'tile'  => false,
				'title' => __( '连接与功能对照', 'wei-alipay' ),
			)
		);
		$rows = array(
			array( 'shield-check', __( '支付宝主网关（电脑网站 / 手机网站）', 'wei-alipay' ), __( '安装并填写支付宝密钥后即可直接收款，不依赖账户连接。', 'wei-alipay' ), 'pill ok', __( '安装即用', 'wei-alipay' ) ),
			array( 'link', __( '当面付、花呗分期、条码付', 'wei-alipay' ), __( '连上账户后即可首次启用；启用后断开仍照常收款，只锁定选项修改。', 'wei-alipay' ), $connected ? 'pill ok' : 'pill warn', $connected ? __( '可启用与配置', 'wei-alipay' ) : __( '连接后可启用', 'wei-alipay' ) ),
			array( 'tools', __( '跨境支付、资金授权、自动续费订阅、多商户分账', 'wei-alipay' ), __( '涉及额外接口完善与支付宝商户资质审核，当前不提供开启开关。', 'wei-alipay' ), $connected ? 'pill warn' : 'pill mute', $connected ? __( '暂不开放', 'wei-alipay' ) : __( '连接后查看', 'wei-alipay' ) ),
		);
		foreach ( $rows as $r ) {
			echo '<div class="simple-row"><span class="tile">' . Wenpai_Admin_Icons::svg( $r[0] ) . '</span><div><div class="t">' . esc_html( $r[1] ) . '</div><div class="d">' . esc_html( $r[2] ) . '</div></div><div class="r"><span class="' . esc_attr( $r[3] ) . '">' . esc_html( $r[4] ) . '</span></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon.
		}
		Wenpai_Admin_UI::card_close();

		Wenpai_Admin_UI::card_open(
			array(
				'tile'  => false,
				'title' => __( '详情与显示偏好', 'wei-alipay' ),
			)
		);
		echo '<div class="wenpai-folds"><details class="wenpai-fold"><summary><span class="tile">' . Wenpai_Admin_Icons::svg( 'info' ) . '</span><span class="wenpai-fold__text"><span class="wenpai-fold__title">' . esc_html__( '连接详情与传输数据说明', 'wei-alipay' ) . '</span></span><span class="wenpai-fold__side">' . Wenpai_Admin_Icons::svg( 'chevron-down', 'wenpai-fold__chevron' ) . '</span></summary><div class="wenpai-fold__body">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icons.
		echo '<p>' . esc_html__( '仅同步站点域名、安装标识、插件与环境版本，绝不传订单、金额、买家信息或支付宝私钥。', 'wei-alipay' ) . '</p>';
		echo '<p>' . esc_html( sprintf( __( '安装标识：%s', 'wei-alipay' ), (string) ( $account['installation_label'] ?? '' ) ) ) . '</p>';
		echo '</div></details></div>';

		self::render_palette_toggle_form( 'account' );

		if ( $connected && ! empty( $account['disconnect_url'] ) ) {
			echo '<div class="wenpai-folds"><details class="wenpai-fold"><summary><span class="tile">' . Wenpai_Admin_Icons::svg( 'shield-check' ) . '</span><span class="wenpai-fold__text"><span class="wenpai-fold__title">' . esc_html__( '断开此站点连接', 'wei-alipay' ) . '</span></span><span class="wenpai-fold__side"><span class="pill bad">' . esc_html__( '危险操作', 'wei-alipay' ) . '</span>' . Wenpai_Admin_Icons::svg( 'chevron-down', 'wenpai-fold__chevron' ) . '</span></summary><div class="wenpai-fold__body">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icons.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wenpai-gated-form>';
			echo '<input type="hidden" name="action" value="wei_alipay_disconnect_account">';
			wp_nonce_field( 'wei_alipay_disconnect_account' );
			echo Wenpai_Admin_UI::gate_html(
				array(
					'name'    => 'confirm_disconnect',
					'id'      => 'wei-alipay-confirm-disconnect',
					'text_id' => 'wei-alipay-confirm-disconnect-text',
					'label'   => __( '我已知晓：断开不会停掉已启用的支付方式，但会锁定扩展方式的开关与参数修改。', 'wei-alipay' ),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Wenpai_Admin_UI::gate_html().
			echo '<div class="wenpai-fold__foot"><button type="submit" class="btn btn-danger" aria-describedby="wei-alipay-confirm-disconnect-text">' . esc_html__( '确认断开此站点连接', 'wei-alipay' ) . '</button></div>';
			echo '</form></div></details></div>';
		}
		Wenpai_Admin_UI::card_close();
	}

	/**
	 * Render the Help screen linked from the top-bar right action.
	 */
	private static function render_help() {
		self::render_page_head(
			__( '帮助', 'wei-alipay' ),
			__( '查看支付宝密钥配置、回调校验与常见问题处理步骤。', 'wei-alipay' )
		);

		Wenpai_Admin_UI::card_open(
			array(
				'tile'  => false,
				'title' => __( '首次配置主网关（3 步）', 'wei-alipay' ),
			)
		);
		$steps = array(
			array( '1', __( '准备支付宝应用密钥（RSA2）', 'wei-alipay' ), __( '在支付宝开放平台创建网页/移动应用，生成 RSA2 密钥对并上传应用公钥，获取对应的支付宝公钥。', 'wei-alipay' ) ),
			array( '2', __( '在 WooCommerce 付款设置填入凭据', 'wei-alipay' ), __( '打开「WooCommerce → 设置 → 付款 → 支付宝」，填入 AppID、应用私钥与支付宝公钥。插件会自动以 AES-256-GCM 加密保存私钥。', 'wei-alipay' ) ),
			array( '3', __( '确认回调地址与币种', 'wei-alipay' ), __( '确认站点已启用 HTTPS 且异步回调地址可被公网 POST 访问，商店币种为人民币（CNY）或已配置固定汇率。', 'wei-alipay' ) ),
		);
		foreach ( $steps as $st ) {
			echo '<div class="simple-row"><span class="wenpai-help__no">' . esc_html( $st[0] ) . '</span><div><div class="t">' . esc_html( $st[1] ) . '</div><div class="d">' . esc_html( $st[2] ) . '</div></div></div>';
		}
		Wenpai_Admin_UI::card_foot(
			array(
				'start'   => true,
				'actions' => array(
					array( 'label' => __( '薇晓朵文档中心', 'wei-alipay' ), 'href' => 'https://www.weixiaoduo.com/document/' ),
					array( 'label' => __( '支付宝开放平台文档', 'wei-alipay' ), 'href' => 'https://opendocs.alipay.com/' ),
					array( 'label' => __( '提交薇晓朵工单', 'wei-alipay' ), 'href' => 'https://www.weixiaoduo.com/support' ),
				),
			)
		);
		Wenpai_Admin_UI::card_close();
	}

	/**
	 * Render the kit page-head block (h1 + lede + wp-header-end).
	 *
	 * @param string $title Page title.
	 * @param string $lede  One-line description.
	 */
	private static function render_page_head( $title, $lede ) {
		echo '<div class="wenpai-page-head"><div><h1 class="wenpai-h1">' . esc_html( $title ) . '</h1>';
		if ( '' !== (string) $lede ) {
			echo '<p class="wenpai-lede">' . esc_html( $lede ) . '</p>';
		}
		echo '</div></div><div class="wp-header-end" aria-hidden="true"></div>';
	}

	/**
	 * Render the quiet 2-mode brand palette preference switcher (Weixiaoduo default vs Alipay Blue #1677ff).
	 *
	 * @param string $return_view Current view slug for post-save redirect.
	 */
	private static function render_palette_toggle_form( $return_view ) {
		$current = self::active_palette();
		echo '<form class="wei-alipay-palette-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wei_alipay_save_palette">';
		echo '<input type="hidden" name="return_view" value="' . esc_attr( $return_view ) . '">';
		wp_nonce_field( 'wei_alipay_save_palette' );
		echo '<span class="wei-alipay-palette-form__label">' . esc_html__( '后台品牌配色', 'wei-alipay' ) . '</span>';
		echo '<div class="seg seg--radio">';
		echo '<label><input type="radio" name="wei_alipay_palette" value="weixiaoduo" ' . checked( self::PALETTE_DEFAULT, $current, false ) . '><span>' . esc_html__( '薇晓朵默认', 'wei-alipay' ) . '</span></label>';
		echo '<label><input type="radio" name="wei_alipay_palette" value="platform" ' . checked( self::PALETTE_PLATFORM, $current, false ) . '><span>' . esc_html__( '支付宝蓝', 'wei-alipay' ) . '</span></label>';
		echo '</div>';
		echo '<button type="submit" class="btn btn-secondary">' . esc_html__( '应用配色', 'wei-alipay' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Render one payment method card in the top 3-column grid.
	 */
	private static function render_payment_card( $title, $desc, $icon_html, $tile_tone, $control_html, $foot_meta, $foot_href, $foot_label ) {
		echo '<section class="card">';
		echo '<div class="card-head">';
		echo '<span class="tile' . ( $tile_tone ? ' ' . esc_attr( $tile_tone ) : '' ) . '">' . $icon_html . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon.
		echo '<h2 class="card-title">' . esc_html( $title ) . '</h2>';
		echo '<div class="card-head__actions">' . $control_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped pill or switch markup.
		echo '</div>';
		echo '<p class="card-sub">' . esc_html( $desc ) . '</p>';
		$actions = array();
		if ( '' !== $foot_href && '' !== $foot_label ) {
			$actions[] = array( 'label' => $foot_label, 'href' => $foot_href );
		}
		Wenpai_Admin_UI::card_foot(
			array(
				'start'   => true,
				'meta'    => $foot_meta,
				'actions' => $actions,
			)
		);
		echo '</section>';
	}

	/**
	 * Build an accessible wenpai-switch checkbox for feature toggles.
	 *
	 * @return string
	 */
	private static function switch_html( $name, $value, $label, $checked, $disabled, $show_state = true ) {
		$state_html = $show_state
			? '<span class="wenpai-switch__state" aria-hidden="true">'
				. '<span class="wenpai-switch__on">' . esc_html__( '已开启', 'wei-alipay' ) . '</span>'
				. '<span class="wenpai-switch__off">' . esc_html__( '未开启', 'wei-alipay' ) . '</span>'
				. '</span>'
			: '';
		return '<label class="wenpai-switch">'
			. '<input type="checkbox" role="switch" class="wenpai-switch__input" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"'
			. ( $checked ? ' checked="checked"' : '' )
			. ( $disabled ? ' disabled="disabled"' : '' )
			. ' aria-label="' . esc_attr( $label ) . '">'
			. '<span class="wenpai-switch__track" aria-hidden="true"></span>'
			. $state_html
			. '</label>';
	}

	/**
	 * Return the 5 approved top-bar tabs.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function shell_tabs() {
		return array(
			array(
				'id'        => 'overview',
				'label'     => __( '概览', 'wei-alipay' ),
				'url'       => admin_url( 'admin.php?page=wei-alipay&view=overview' ),
				'icon_html' => Wenpai_Admin_Icons::svg( 'overview' ),
			),
			array(
				'id'        => 'payments',
				'label'     => __( '支付方式', 'wei-alipay' ),
				'url'       => admin_url( 'admin.php?page=wei-alipay&view=payments' ),
				'icon_html' => Wenpai_Admin_Icons::svg( 'flash' ),
			),
			array(
				'id'        => 'reconcile',
				'label'     => __( '对账', 'wei-alipay' ),
				'url'       => admin_url( 'admin.php?page=wei-alipay-reconcile-pro' ),
				'icon_html' => Wenpai_Admin_Icons::svg( 'search' ),
			),
			array(
				'id'        => 'webhooks',
				'label'     => __( '回调日志', 'wei-alipay' ),
				'url'       => admin_url( 'admin.php?page=wei-alipay-webhooks' ),
				'icon_html' => Wenpai_Admin_Icons::svg( 'refresh' ),
			),
			array(
				'id'        => 'account',
				'label'     => __( '账户', 'wei-alipay' ),
				'url'       => admin_url( 'admin.php?page=wei-alipay&view=account' ),
				'icon_html' => Wenpai_Admin_Icons::svg( 'user' ),
			),
		);
	}

	/**
	 * White "支" glyph SVG rendered directly inside .wenpai-mark.
	 *
	 * @return string
	 */
	public static function brand_mark_svg() {
		return '<svg class="wenpai-ico" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
			. '<path d="M5 7.5h14M12 4v3.5M6.2 11.2h11.6c-.8 3.2-2.6 5.6-5.5 7.2-2.4 1.2-5 1.1-6.2-.2-1.1-1-.7-2.9 1-3.7 2.2-1 5.1-.2 7.8 1.4 1.5.9 3.1 1.6 4.6 1.9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
			. '</svg>';
	}

	/**
	 * Count bundled Alipay SDK files on disk for live diagnostics.
	 *
	 * @return int
	 */
	private static function count_sdk_files() {
		$dir = WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay';
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$count = 0;
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() ) {
				++$count;
			}
		}
		return $count;
	}
}
