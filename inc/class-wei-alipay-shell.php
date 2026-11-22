<?php
/**
 * Wei Alipay administration shell built on vendored wenpai-admin-ui v0.13.8
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

		$return_view = isset( $_POST['return_view'] ) ? sanitize_key( wp_unslash( $_POST['return_view'] ) ) : 'account';
		if ( ! in_array( $return_view, array( 'overview', 'payments', 'account', 'help' ), true ) ) {
			$return_view = 'account';
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

		$shell_js = WEI_ALIPAY_PLUGIN_PATH . 'js/admin/wei-alipay-shell.js';
		if ( file_exists( $shell_js ) ) {
			wp_enqueue_script(
				'wei-alipay-shell',
				WEI_ALIPAY_PLUGIN_URL . 'js/admin/wei-alipay-shell.js',
				array(),
				(string) filemtime( $shell_js ),
				true
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
				'version' => 'Wei Alipay ' . ( defined( 'WEI_ALIPAY_VERSION' ) ? WEI_ALIPAY_VERSION : '4.3.1' ),
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
	 * Render a calm, 2-layer Overview screen without developer jargon or duplicated links.
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
		$main_url     = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=alipay' );
		$payments_url = admin_url( 'admin.php?page=wei-alipay&view=payments' );

		$active_methods = array();
		if ( $main_active ) {
			$active_methods[] = __( '主网关', 'wei-alipay' );
		}
		foreach ( array( 'f2f' => __( '当面付', 'wei-alipay' ), 'huabei' => __( '花呗分期', 'wei-alipay' ), 'barcode' => __( '条码付', 'wei-alipay' ) ) as $k => $lbl ) {
			if ( 'yes' === ( $enabled_map[ $k ] ?? 'no' ) ) {
				$active_methods[] = $lbl;
			}
		}

		$paid_7d   = (int) ( $activity['paid'] ?? 0 );
		$failed_7d = (int) ( $activity['failed'] ?? 0 );
		$refund_7d = (int) ( $activity['refunded'] ?? 0 );
		$cb_ok     = (int) ( $health['callback_success'] ?? 0 );
		$cb_bad    = (int) ( $health['callback_failed'] ?? 0 );
		$cb_total  = $cb_ok + $cb_bad;
		$cb_rate   = $cb_total > 0 ? round( ( $cb_ok / $cb_total ) * 100 ) . '%' : '';

		self::render_page_head(
			__( '概览', 'wei-alipay' ),
			$main_ready
				? __( '查看收款运行状态、近期订单动态与对账摘要。', 'wei-alipay' )
				: __( '填入支付宝应用密钥后即可开始收款，无需先连接账户。', 'wei-alipay' )
		);

		if ( ! $main_ready ) {
			Wenpai_Admin_UI::next(
				array(
					'icon_html' => Wenpai_Admin_Icons::svg( 'shield-check' ),
					'title'     => __( '完成主网关密钥配置即可开始收款', 'wei-alipay' ),
					'desc'      => __( '前往 WooCommerce 付款设置填入 AppID、应用私钥与支付宝公钥，私钥自动加密保存。', 'wei-alipay' ),
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

		Wenpai_Admin_UI::statbar(
			array(
				'items' => array(
					array(
						'label' => __( '主网关状态', 'wei-alipay' ),
						'value' => $main_ready ? __( '已就绪', 'wei-alipay' ) : __( '待配置', 'wei-alipay' ),
						'desc'  => $main_ready ? __( '电脑与手机收银台正常', 'wei-alipay' ) : __( '缺少 AppID 或私钥', 'wei-alipay' ),
					),
					array(
						'label' => __( '已启用方式', 'wei-alipay' ),
						'value' => count( $active_methods ) . ' / 4',
						'desc'  => ! empty( $active_methods ) ? implode( '、', $active_methods ) : __( '尚未开启支付方式', 'wei-alipay' ),
					),
					array(
						'label' => __( '近 7 日收款', 'wei-alipay' ),
						'value' => sprintf( __( '%d 笔', 'wei-alipay' ), $paid_7d ),
						'desc'  => $refund_7d > 0
							? sprintf( __( '退款 %d 笔', 'wei-alipay' ), $refund_7d )
							: ( $paid_7d > 0 ? __( '无退款记录', 'wei-alipay' ) : __( '暂无成交记录', 'wei-alipay' ) ),
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

		// Left card: 3 quiet merchant-readable status rows (zero nested boxes, zero dev jargon).
		Wenpai_Admin_UI::card_open(
			array(
				'tile'  => false,
				'title' => __( '运行状态', 'wei-alipay' ),
			)
		);
		$secret_ok   = 'ready' === ( $secret['status'] ?? '' );
		$cert_health = class_exists( 'Alipay_SDK_Helper' ) ? Alipay_SDK_Helper::cert_health() : array( 'mode' => 'public_key', 'status' => 'ok', 'label' => 'RSA2', 'detail' => '' );
		$is_cert     = 'cert' === ( $cert_health['mode'] ?? 'public_key' );
		$cred_pill   = ! $secret_ok
			? array( 'warn', __( '待配置', 'wei-alipay' ) )
			: ( $is_cert && 'bad' === ( $cert_health['status'] ?? '' )
				? array( 'bad', __( '证书异常', 'wei-alipay' ) )
				: ( $is_cert && 'missing' === ( $cert_health['status'] ?? '' )
					? array( 'warn', __( '待补证书', 'wei-alipay' ) )
					: array( 'ok', $is_cert ? __( '证书正常', 'wei-alipay' ) : __( '公钥正常', 'wei-alipay' ) ) ) );
		$cred_desc   = ! $secret_ok
			? __( '前往主网关填入 AppID 与密钥', 'wei-alipay' )
			: ( $is_cert && '' !== (string) ( $cert_health['detail'] ?? '' )
				? (string) $cert_health['detail']
				: __( '私钥已加密保存，页面不回显明文', 'wei-alipay' ) );

		$sched_row   = self::reconcile_status_row();
		$status_rows = array(
			array(
				'icon'  => 'shield-check',
				'title' => __( '密钥与签名', 'wei-alipay' ),
				'desc'  => $cred_desc,
				'tone'  => $cred_pill[0],
				'label' => $cred_pill[1],
			),
			array(
				'icon'  => 'link',
				'title' => __( '异步回调通道', 'wei-alipay' ),
				'desc'  => is_ssl()
					? __( '站点已启用 HTTPS，回调验签后自动更新订单', 'wei-alipay' )
					: __( '当前非 HTTPS，上线收款前请启用 HTTPS', 'wei-alipay' ),
				'tone'  => is_ssl() ? 'ok' : 'warn',
				'label' => is_ssl() ? __( 'HTTPS 就绪', 'wei-alipay' ) : __( '非 HTTPS', 'wei-alipay' ),
			),
			$sched_row,
		);
		foreach ( $status_rows as $row ) {
			echo '<div class="simple-row">';
			echo '<span class="tile">' . Wenpai_Admin_Icons::svg( $row['icon'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon.
			echo '<div><div class="t">' . esc_html( $row['title'] ) . '</div><div class="d">' . esc_html( $row['desc'] ) . '</div></div>';
			echo '<div class="r"><span class="pill ' . esc_attr( $row['tone'] ) . '">' . esc_html( $row['label'] ) . '</span></div>';
			echo '</div>';
		}
		Wenpai_Admin_UI::card_foot(
			array(
				'start'   => true,
				'meta'    => __( '配置变更后建议测试连接', 'wei-alipay' ),
				'actions' => array(
					array(
						'label'   => __( '支付方式', 'wei-alipay' ),
						'href'    => $payments_url,
						'variant' => 'ghost',
					),
					array(
						'label'   => __( '配置主网关', 'wei-alipay' ),
						'href'    => $main_url,
						'variant' => 'secondary',
					),
				),
			)
		);
		Wenpai_Admin_UI::card_close();

		// Right card: 3 concise local activity lines (no repetitive "7 日内" column).
		Wenpai_Admin_UI::card_open(
			array(
				'tile'  => false,
				'title' => __( '近 7 日动态', 'wei-alipay' ),
			)
		);
		$reconcile_item = self::reconcile_activity_item();
		Wenpai_Admin_UI::tag_feed(
			array(
				'items' => array(
					array(
						'tag'   => __( '订单', 'wei-alipay' ),
						'tone'  => $paid_7d > 0 ? 'ok' : 'mute',
						'title' => sprintf(
							/* translators: 1: paid count, 2: failed count, 3: refund count */
							__( '已付款 %1$d 笔 · 失败 %2$d 笔 · 退款 %3$d 笔', 'wei-alipay' ),
							$paid_7d,
							$failed_7d,
							$refund_7d
						),
						'when'  => '',
					),
					array(
						'tag'   => __( '回调', 'wei-alipay' ),
						'tone'  => $cb_bad > 0 ? 'warn' : ( $cb_total > 0 ? 'ok' : 'mute' ),
						'title' => $cb_total > 0
							? sprintf(
								/* translators: 1: rate, 2: total callbacks */
								__( '验签成功率 %1$s（共 %2$d 笔通知）', 'wei-alipay' ),
								$cb_rate,
								$cb_total
							)
							: __( '尚未收到异步回调记录', 'wei-alipay' ),
						'when'  => '',
					),
					$reconcile_item,
				),
			)
		);
		Wenpai_Admin_UI::card_foot(
			array(
				'start'   => true,
				'meta'    => __( '仅统计本地计数，不记录金额与买家资料', 'wei-alipay' ),
				'actions' => array(
					array(
						'label'   => __( '回调日志', 'wei-alipay' ),
						'href'    => admin_url( 'admin.php?page=wei-alipay-webhooks' ),
						'variant' => 'ghost',
					),
					array(
						'label'   => __( '进入对账', 'wei-alipay' ),
						'href'    => admin_url( 'admin.php?page=wei-alipay-reconcile-pro' ),
						'variant' => 'secondary',
					),
				),
			)
		);
		Wenpai_Admin_UI::card_close();

		echo '</div>';
	}

	/**
	 * Build the scheduled reconciliation row for the Overview status card.
	 *
	 * @return array<string,string>
	 */
	private static function reconcile_status_row() {
		if ( ! class_exists( 'Wei_Alipay_Reconcile_Scheduler' ) ) {
			return array(
				'icon'  => 'refresh',
				'title' => __( '自动日对账', 'wei-alipay' ),
				'desc'  => __( '可每日自动拉取账单并提醒差异', 'wei-alipay' ),
				'tone'  => 'mute',
				'label' => __( '未开启', 'wei-alipay' ),
			);
		}
		$cfg = Wei_Alipay_Reconcile_Scheduler::get_settings();
		$on  = 'yes' === ( $cfg['enabled'] ?? 'no' );
		return array(
			'icon'  => 'refresh',
			'title' => __( '自动日对账', 'wei-alipay' ),
			'desc'  => $on
				? sprintf( __( '每日 %s 自动比对前一日账单', 'wei-alipay' ), (string) ( $cfg['run_time'] ?? '10:30' ) )
				: __( '开启后每日自动核对账单并邮件提醒差异', 'wei-alipay' ),
			'tone'  => $on ? 'ok' : 'mute',
			'label' => $on ? __( '已开启', 'wei-alipay' ) : __( '未开启', 'wei-alipay' ),
		);
	}

	/**
	 * Build the Overview activity row summarizing recent scheduled reconciliation runs.
	 *
	 * @return array<string,string>
	 */
	private static function reconcile_activity_item() {
		if ( ! class_exists( 'Wei_Alipay_Reconcile_Scheduler' ) ) {
			return array(
				'tag'   => __( '对账', 'wei-alipay' ),
				'tone'  => 'mute',
				'title' => __( '自动对账尚未启用', 'wei-alipay' ),
				'when'  => '',
			);
		}
		$sum = Wei_Alipay_Reconcile_Scheduler::recent_diff_summary( 7 );
		if ( 0 === (int) $sum['runs'] ) {
			$cfg = Wei_Alipay_Reconcile_Scheduler::get_settings();
			return array(
				'tag'   => __( '对账', 'wei-alipay' ),
				'tone'  => 'mute',
				'title' => 'yes' === $cfg['enabled']
					? sprintf( __( '自动对账已排期（每日 %s），等待首次运行', 'wei-alipay' ), $cfg['run_time'] )
					: __( '自动对账尚未启用', 'wei-alipay' ),
				'when'  => '',
			);
		}
		$has_issue = (int) $sum['diffs'] > 0 || (int) $sum['errors'] > 0;
		return array(
			'tag'   => __( '对账', 'wei-alipay' ),
			'tone'  => $has_issue ? 'warn' : 'ok',
			'title' => $has_issue
				? sprintf( __( '自动对账 %1$d 次，发现 %2$d 笔差异待复核', 'wei-alipay' ), (int) $sum['runs'], (int) $sum['diffs'] )
				: sprintf( __( '自动对账 %d 次，全部无差异', 'wei-alipay' ), (int) $sum['runs'] ),
			'when'  => (string) ( $sum['latest_date'] ?: '' ),
		);
	}

	/**
	 * Render the Payment Methods & Local Tools screen with equal-height cards,
	 * folded unlaunched advanced modules, and one unified save button.
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
				? __( '按需开启支付方式与本地运营工具，调整后统一点底部保存。', 'wei-alipay' )
				: ( $has_latched
					? __( '账户已断开：已开启的扩展继续收款，重连后可修改选项。', 'wei-alipay' )
					: __( '主网关安装即用；当面付、花呗与条码付连接账户后可启用。', 'wei-alipay' ) )
		);

		if ( ! $connected ) {
			Wenpai_Admin_UI::next(
				array(
					'icon_html' => Wenpai_Admin_Icons::svg( $has_latched ? 'shield-check' : 'user' ),
					'title'     => $has_latched
						? __( '账户已断开：已开启的扩展继续收款，选项已锁定', 'wei-alipay' )
						: __( '连接薇晓朵账户以启用扩展支付方式', 'wei-alipay' ),
					'desc'      => $has_latched
						? __( '已开启的支付方式仍在正常收款与退款；重新连接后可继续调整开关与参数。', 'wei-alipay' )
						: __( '连接仅用于站点身份认证，不是许可证；已启用的方式即便断开也照常收款。', 'wei-alipay' ),
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

		// Single unified form saves both available payment methods and local tools cleanly.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wei-alipay-feature-form>';
		echo '<input type="hidden" name="action" value="wei_alipay_save_features">';
		echo '<input type="hidden" name="feature_group" value="all">';
		wp_nonce_field( 'wei_alipay_save_features' );

		// Top 3 equal-height cards: main gateway + f2f + huabei.
		echo '<div class="wenpai-grid-3">';
		self::render_payment_card(
			__( '支付宝主网关', 'wei-alipay' ),
			__( '电脑与手机收银台，支持退款与查单。', 'wei-alipay' ),
			Wenpai_Admin_Icons::svg( 'shield-check' ),
			'ok',
			'<span class="pill ok">' . esc_html__( '安装即用', 'wei-alipay' ) . '</span>',
			__( '已就绪', 'wei-alipay' ),
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
				? ( $connected ? __( '已开启', 'wei-alipay' ) : __( '运行中 · 已锁定', 'wei-alipay' ) )
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

		// Card 2: Barcode + local tools + folded unlaunched advanced modules + ONE save button.
		Wenpai_Admin_UI::card_open(
			array(
				'tile'  => false,
				'title' => __( '扩展与本地工具', 'wei-alipay' ),
			)
		);

		if ( isset( $registry['barcode'] ) ) {
			$feat  = $registry['barcode'];
			$is_on = 'yes' === ( $enabled['barcode'] ?? 'no' );
			echo '<div class="simple-row">';
			echo '<span class="tile">' . Wenpai_Admin_Icons::svg( 'search' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon.
			echo '<div><div class="t">' . esc_html( (string) $feat['name'] ) . '</div><div class="d">' . esc_html( (string) $feat['description'] ) . '</div></div>';
			echo '<div class="r">';
			if ( $is_on && $connected && ! empty( $feat['settings_section'] ) ) {
				$cfg = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . rawurlencode( (string) $feat['settings_section'] ) );
				echo '<a class="btn btn-ghost" href="' . esc_url( $cfg ) . '">' . esc_html__( '前往配置', 'wei-alipay' ) . '</a>';
			}
			if ( $connected || $is_on ) {
				echo self::switch_html( 'features[]', 'barcode', (string) $feat['name'], $is_on, ! $connected ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside switch_html.
			} else {
				echo '<span class="pill warn">' . esc_html__( '连接后可启用', 'wei-alipay' ) . '</span>';
			}
			echo '</div></div>';
		}

		foreach ( array( 'reconcile' => array( 'download', __( '进入对账', 'wei-alipay' ) ), 'webhook_retry' => array( 'refresh', __( '查看日志', 'wei-alipay' ) ) ) as $key => $meta ) {
			if ( ! isset( $registry[ $key ] ) ) {
				continue;
			}
			$feat  = $registry[ $key ];
			$is_on = 'yes' === ( $enabled[ $key ] ?? 'no' );
			$url   = admin_url( 'admin.php?page=' . rawurlencode( (string) $feat['page'] ) );
			echo '<div class="simple-row">';
			echo '<span class="tile">' . Wenpai_Admin_Icons::svg( $meta[0] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon.
			echo '<div><div class="t">' . esc_html( (string) $feat['name'] ) . '</div><div class="d">' . esc_html( (string) $feat['description'] ) . '</div></div>';
			echo '<div class="r">';
			if ( $is_on ) {
				echo '<a class="btn btn-ghost" href="' . esc_url( $url ) . '">' . esc_html( $meta[1] ) . '</a>';
			}
			echo self::switch_html( 'features[]', $key, (string) $feat['name'], $is_on, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside switch_html.
			echo '</div></div>';
		}

		// Collapse the 4 unlaunched advanced modules so they don't dominate the screen with yellow pills.
		$unavailable_icons = array(
			'cross_border'  => 'cloud',
			'fund_auth'     => 'shield-check',
			'subscriptions' => 'refresh',
			'profitsharing' => 'equalizer',
		);
		echo '<div class="wenpai-folds"><details class="wenpai-fold">';
		echo '<summary><span class="tile">' . Wenpai_Admin_Icons::svg( 'equalizer' ) . '</span><span class="wenpai-fold__text"><span class="wenpai-fold__title">' . esc_html__( '高阶支付模块（待沙箱实测开放）', 'wei-alipay' ) . '</span><span class="wenpai-fold__desc">' . esc_html__( '跨境支付、资金预授权、订阅代扣、多商户分账', 'wei-alipay' ) . '</span></span><span class="wenpai-fold__side"><span class="pill mute">' . esc_html__( '4 项暂未开放', 'wei-alipay' ) . '</span>' . Wenpai_Admin_Icons::svg( 'chevron-down', 'wenpai-fold__chevron' ) . '</span></summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icons.
		echo '<div class="wenpai-fold__body">';
		foreach ( $unavailable_icons as $key => $icon_name ) {
			if ( ! isset( $registry[ $key ] ) ) {
				continue;
			}
			$feat      = $registry[ $key ];
			$pill_cls  = $connected ? 'pill warn' : 'pill mute';
			$pill_text = $connected ? __( '暂不开放', 'wei-alipay' ) : __( '连接后查看', 'wei-alipay' );
			echo '<div class="simple-row">';
			echo '<span class="tile">' . Wenpai_Admin_Icons::svg( $icon_name ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon.
			echo '<div><div class="t">' . esc_html( (string) $feat['name'] ) . '</div><div class="d">' . esc_html( (string) $feat['description'] ) . '</div></div>';
			echo '<div class="r"><span class="' . esc_attr( $pill_cls ) . '">' . esc_html( $pill_text ) . '</span></div>';
			echo '</div>';
		}
		echo '</div></details></div>';

		Wenpai_Admin_UI::card_foot(
			array(
				'start'   => true,
				'meta'    => __( '关闭模块不会删除已保存参数，本地工具无需连接账户。', 'wei-alipay' ),
				'actions' => array(
					array(
						'label'   => __( '保存设置', 'wei-alipay' ),
						'type'    => 'submit',
						'variant' => 'primary',
					),
				),
			)
		);
		Wenpai_Admin_UI::card_close();
		echo '</form>';
	}

	/**
	 * Render the Account page with aligned palette row, folded details, and broken-avatar guard.
	 *
	 * @param array<string,mixed> $ctx Runtime context.
	 */
	private static function render_account( array $ctx ) {
		$account   = (array) ( $ctx['account'] ?? array() );
		$connected = 'connected' === ( $account['status'] ?? '' );

		self::render_page_head(
			__( '薇晓朵账户', 'wei-alipay' ),
			__( '管理本站身份连接与后台显示偏好。', 'wei-alipay' )
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
			echo '<span class="tile">' . Wenpai_Admin_Icons::svg( 'user' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon.
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
				echo '<a class="btn btn-secondary" href="' . esc_url( (string) $account['manage_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '管理账户', 'wei-alipay' ) . '</a>';
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
				'title' => __( '偏好与连接管理', 'wei-alipay' ),
			)
		);
		// Palette row aligned to the exact same 36px icon column as fold rows below.
		echo '<div class="simple-row">';
		echo '<span class="tile">' . Wenpai_Admin_Icons::svg( 'sparkle' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon.
		echo '<div><div class="t">' . esc_html__( '后台品牌配色', 'wei-alipay' ) . '</div><div class="d">' . esc_html__( '切换仅对当前管理员生效，可在薇晓朵默认与支付宝蓝之间切换。', 'wei-alipay' ) . '</div></div>';
		echo '<div class="r">';
		self::render_palette_toggle_form( 'account' );
		echo '</div></div>';

		echo '<div class="wenpai-folds"><details class="wenpai-fold"><summary><span class="tile">' . Wenpai_Admin_Icons::svg( 'info' ) . '</span><span class="wenpai-fold__text"><span class="wenpai-fold__title">' . esc_html__( '连接详情与运行环境', 'wei-alipay' ) . '</span></span><span class="wenpai-fold__side">' . Wenpai_Admin_Icons::svg( 'chevron-down', 'wenpai-fold__chevron' ) . '</span></summary><div class="wenpai-fold__body">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icons.
		echo '<p>' . esc_html__( '仅同步站点域名、安装标识与版本信息，绝不传输订单、金额、买家资料或支付宝私钥。', 'wei-alipay' ) . '</p>';
		echo '<p>' . esc_html( sprintf( __( '安装标识：%s', 'wei-alipay' ), (string) ( $account['installation_label'] ?? '' ) ) ) . '</p>';
		if ( ! empty( $ctx['runtime_summary'] ) ) {
			echo '<p>' . esc_html( sprintf( __( '运行环境：%s', 'wei-alipay' ), (string) $ctx['runtime_summary'] ) ) . '</p>';
		}
		echo '</div></details>';

		if ( $connected && ! empty( $account['disconnect_url'] ) ) {
			echo '<details class="wenpai-fold"><summary><span class="tile">' . Wenpai_Admin_Icons::svg( 'shield-check' ) . '</span><span class="wenpai-fold__text"><span class="wenpai-fold__title">' . esc_html__( '断开此站点连接', 'wei-alipay' ) . '</span></span><span class="wenpai-fold__side"><span class="pill bad">' . esc_html__( '危险操作', 'wei-alipay' ) . '</span>' . Wenpai_Admin_Icons::svg( 'chevron-down', 'wenpai-fold__chevron' ) . '</span></summary><div class="wenpai-fold__body">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icons.
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
			echo '</form></div></details>';
		}
		echo '</div>';
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
			array( '1', __( '准备支付宝应用密钥（RSA2）', 'wei-alipay' ), __( '在支付宝开放平台创建网页/移动应用，生成 RSA2 密钥对并上传应用公钥，获取支付宝公钥。', 'wei-alipay' ) ),
			array( '2', __( '在 WooCommerce 付款设置填入凭据', 'wei-alipay' ), __( '打开「WooCommerce → 设置 → 付款 → 支付宝」，填入 AppID、应用私钥与支付宝公钥。', 'wei-alipay' ) ),
			array( '3', __( '确认回调地址与币种', 'wei-alipay' ), __( '确认站点已启用 HTTPS 且异步回调地址可被公网 POST 访问，商店币种为 CNY 或已设固定汇率。', 'wei-alipay' ) ),
		);
		foreach ( $steps as $st ) {
			echo '<div class="simple-row"><span class="wenpai-help__no">' . esc_html( $st[0] ) . '</span><div><div class="t">' . esc_html( $st[1] ) . '</div><div class="d">' . esc_html( $st[2] ) . '</div></div></div>';
		}
		Wenpai_Admin_UI::card_foot(
			array(
				'start'   => true,
				'meta'    => __( '文档与人工技术支持', 'wei-alipay' ),
				'actions' => array(
					array( 'label' => __( '薇晓朵文档中心', 'wei-alipay' ), 'href' => 'https://www.weixiaoduo.com/document/', 'variant' => 'ghost' ),
					array( 'label' => __( '支付宝开放平台文档', 'wei-alipay' ), 'href' => 'https://opendocs.alipay.com/', 'variant' => 'ghost' ),
					array( 'label' => __( '提交薇晓朵工单', 'wei-alipay' ), 'href' => 'https://www.weixiaoduo.com/support', 'variant' => 'secondary' ),
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
		echo '<div class="seg seg--radio">';
		echo '<label><input type="radio" name="wei_alipay_palette" value="weixiaoduo" ' . checked( self::PALETTE_DEFAULT, $current, false ) . '><span>' . esc_html__( '薇晓朵默认', 'wei-alipay' ) . '</span></label>';
		echo '<label><input type="radio" name="wei_alipay_palette" value="platform" ' . checked( self::PALETTE_PLATFORM, $current, false ) . '><span>' . esc_html__( '支付宝蓝', 'wei-alipay' ) . '</span></label>';
		echo '</div>';
		echo '<button type="submit" class="btn btn-secondary">' . esc_html__( '应用', 'wei-alipay' ) . '</button>';
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
}
