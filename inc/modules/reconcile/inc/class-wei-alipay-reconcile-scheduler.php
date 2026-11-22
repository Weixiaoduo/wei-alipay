<?php
/**
 * Scheduled daily Alipay reconciliation & discrepancy digest alerting (4.2 M2).
 *
 * Uses WooCommerce Action Scheduler when available and falls back to WP-Cron.
 * Persists bounded privacy-safe daily summaries only (no PII, no raw bill URLs).
 *
 * @package WeiAlipay
 * @since   4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Wei_Alipay_Reconcile_Scheduler {

	const OPTION_KEY         = 'wei_alipay_reconcile_schedule';
	const HISTORY_OPTION_KEY = 'wei_alipay_reconcile_history';
	const HOOK               = 'woo_alipay_scheduled_reconcile';
	const AS_GROUP           = 'wei-alipay';
	const MAX_HISTORY        = 30;

	/**
	 * Register cron/Action Scheduler runner and admin-post settings save hook.
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'handle_scheduled_hook' ) );
		add_action( 'admin_post_wei_alipay_save_reconcile_schedule', array( __CLASS__, 'handle_save_schedule' ) );
		add_action( 'init', array( __CLASS__, 'maybe_ensure_schedule' ), 30 );
	}

	/**
	 * Return normalized schedule settings.
	 *
	 * @return array{enabled:string,run_time:string,bill_type:string,notify_email:string,notify_on_diff_only:string}
	 */
	public static function get_settings() {
		$raw = function_exists( 'get_option' ) ? get_option( self::OPTION_KEY, array() ) : array();
		return self::sanitize_settings( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Sanitize schedule settings payload.
	 *
	 * @param array<string,mixed> $raw Raw input.
	 * @return array{enabled:string,run_time:string,bill_type:string,notify_email:string,notify_on_diff_only:string}
	 */
	public static function sanitize_settings( array $raw ) {
		$enabled = ! empty( $raw['enabled'] ) && in_array( (string) $raw['enabled'], array( 'yes', '1', 'true', 'on' ), true ) ? 'yes' : 'no';
		$time    = isset( $raw['run_time'] ) ? trim( (string) $raw['run_time'] ) : '10:30';
		if ( 1 !== preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$time = '10:30';
		}
		$bill_type = isset( $raw['bill_type'] ) && 'signcustomer' === (string) $raw['bill_type'] ? 'signcustomer' : 'trade';
		$email     = isset( $raw['notify_email'] ) ? trim( (string) $raw['notify_email'] ) : '';
		if ( '' !== $email && function_exists( 'sanitize_email' ) ) {
			$email = sanitize_email( $email );
		}
		$diff_only = ! isset( $raw['notify_on_diff_only'] ) || in_array( (string) $raw['notify_on_diff_only'], array( 'yes', '1', 'true', 'on' ), true ) ? 'yes' : 'no';

		return array(
			'enabled'             => $enabled,
			'run_time'            => $time,
			'bill_type'           => $bill_type,
			'notify_email'        => $email,
			'notify_on_diff_only' => $diff_only,
		);
	}

	/**
	 * Compute the next UTC timestamp matching HH:MM in site timezone.
	 *
	 * @param string   $run_time HH:MM.
	 * @param int|null $now      Optional Unix timestamp override for tests.
	 * @return int
	 */
	public static function next_timestamp_for_time( $run_time, $now = null ) {
		$tz     = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Shanghai' );
		$now_ts = null === $now ? time() : (int) $now;
		$base   = ( new DateTimeImmutable( '@' . $now_ts ) )->setTimezone( $tz );
		$parts  = explode( ':', $run_time );
		$hour   = isset( $parts[0] ) ? (int) $parts[0] : 10;
		$min    = isset( $parts[1] ) ? (int) $parts[1] : 30;
		$target = $base->setTime( $hour, $min, 0 );
		if ( $target->getTimestamp() <= $now_ts ) {
			$target = $target->modify( '+1 day' );
		}
		return $target->getTimestamp();
	}

	/**
	 * Reconcile Action Scheduler / WP-Cron schedule with stored settings.
	 *
	 * @param array<string,mixed>|null $settings Optional settings override.
	 * @return string Backend used ('action_scheduler'|'wp_cron'|'disabled').
	 */
	public static function sync_schedule( $settings = null ) {
		$cfg = is_array( $settings ) ? self::sanitize_settings( $settings ) : self::get_settings();

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::AS_GROUP );
		}
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		}

		if ( 'yes' !== $cfg['enabled'] ) {
			return 'disabled';
		}

		$next_ts = self::next_timestamp_for_time( $cfg['run_time'] );
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			as_schedule_recurring_action( $next_ts, DAY_IN_SECONDS, self::HOOK, array(), self::AS_GROUP, true );
			return 'action_scheduler';
		}
		if ( function_exists( 'wp_schedule_event' ) ) {
			wp_schedule_event( $next_ts, 'daily', self::HOOK );
			return 'wp_cron';
		}
		return 'disabled';
	}

	/**
	 * Ensure enabled schedule exists without re-scheduling every request.
	 */
	public static function maybe_ensure_schedule() {
		$cfg = self::get_settings();
		if ( 'yes' !== $cfg['enabled'] ) {
			return;
		}
		if ( 0 === self::next_run_timestamp() ) {
			self::sync_schedule( $cfg );
		}
	}

	/**
	 * Return the next scheduled run timestamp (0 if not scheduled).
	 *
	 * @return int
	 */
	public static function next_run_timestamp() {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$ts = as_next_scheduled_action( self::HOOK, array(), self::AS_GROUP );
			if ( is_int( $ts ) && $ts > 0 ) {
				return $ts;
			}
		}
		if ( function_exists( 'wp_next_scheduled' ) ) {
			$ts = wp_next_scheduled( self::HOOK );
			if ( is_int( $ts ) && $ts > 0 ) {
				return $ts;
			}
		}
		return 0;
	}

	/**
	 * Return bounded privacy-safe daily reconciliation history (newest first).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_history() {
		$raw = function_exists( 'get_option' ) ? get_option( self::HISTORY_OPTION_KEY, array() ) : array();
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_filter( $raw, 'is_array' ) );
	}

	/**
	 * Return aggregate counts of scheduled reconciliation runs over the last N days.
	 *
	 * @param int      $days Window in days.
	 * @param int|null $now  Optional timestamp override.
	 * @return array{runs:int,diffs:int,errors:int,latest_date:string}
	 */
	public static function recent_diff_summary( $days = 7, $now = null ) {
		$now_ts = null === $now ? time() : (int) $now;
		$cutoff = $now_ts - ( max( 1, (int) $days ) * 86400 );
		$runs   = 0;
		$diffs  = 0;
		$errors = 0;
		$latest = '';

		foreach ( self::get_history() as $entry ) {
			$ts = isset( $entry['ran_ts'] ) ? (int) $entry['ran_ts'] : 0;
			if ( $ts > 0 && $ts < $cutoff ) {
				continue;
			}
			++$runs;
			if ( '' === $latest && ! empty( $entry['date'] ) ) {
				$latest = (string) $entry['date'];
			}
			if ( 'error' === ( $entry['status'] ?? '' ) ) {
				++$errors;
			} else {
				$diffs += max( 0, (int) ( $entry['mismatched'] ?? 0 ) ) + max( 0, (int) ( $entry['unmatched'] ?? 0 ) );
			}
		}

		return array(
			'runs'        => $runs,
			'diffs'       => $diffs,
			'errors'      => $errors,
			'latest_date' => $latest,
		);
	}

	/**
	 * Persist a sanitized reconciliation summary without buyer PII or bill download URLs.
	 *
	 * @param string              $date      Bill date (Y-m-d).
	 * @param string              $bill_type Bill type (trade|signcustomer).
	 * @param array<string,mixed> $result    Raw runner result.
	 * @param string              $notified  ISO/MySQL timestamp if alert was sent.
	 * @return array<string,mixed>
	 */
	public static function record_result( $date, $bill_type, array $result, $notified = '' ) {
		$has_error  = ! empty( $result['error'] );
		$total_rows = max( 0, (int) ( $result['total_rows'] ?? 0 ) );
		$matched    = max( 0, (int) ( $result['matched'] ?? 0 ) );
		$mismatched = max( 0, (int) ( $result['mismatched'] ?? 0 ) );
		$unmatched  = max( 0, (int) ( $result['unmatched'] ?? 0 ) );
		$status     = $has_error ? 'error' : ( ( $mismatched > 0 || $unmatched > 0 ) ? 'diff' : 'ok' );
		$ran_at     = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

		$history = self::get_history();
		$prev_notified = '';
		$filtered = array();
		foreach ( $history as $row ) {
			if ( (string) ( $row['date'] ?? '' ) === (string) $date && (string) ( $row['bill_type'] ?? '' ) === (string) $bill_type ) {
				$prev_notified = (string) ( $row['notified_at'] ?? '' );
				continue;
			}
			$filtered[] = $row;
		}

		$entry = array(
			'date'        => (string) $date,
			'bill_type'   => 'signcustomer' === $bill_type ? 'signcustomer' : 'trade',
			'ran_at'      => $ran_at,
			'ran_ts'      => time(),
			'total_rows'  => $total_rows,
			'matched'     => $matched,
			'mismatched'  => $mismatched,
			'unmatched'   => $unmatched,
			'status'      => $status,
			'error_msg'   => $has_error ? mb_substr( strip_tags( (string) $result['error'] ), 0, 180 ) : '',
			'notified_at' => '' !== $notified ? $notified : $prev_notified,
		);

		array_unshift( $filtered, $entry );
		$filtered = array_slice( $filtered, 0, self::MAX_HISTORY );
		if ( function_exists( 'update_option' ) ) {
			update_option( self::HISTORY_OPTION_KEY, $filtered, false );
		}
		return $entry;
	}

	/**
	 * Action Scheduler / WP-Cron callback wrapper.
	 */
	public static function handle_scheduled_hook() {
		$cfg = self::get_settings();
		if ( 'yes' !== $cfg['enabled'] ) {
			return;
		}
		self::execute_scheduled();
	}

	/**
	 * Execute one idempotent daily reconciliation run and send throttled discrepancy alert.
	 *
	 * @param string|null   $date   Optional Y-m-d date (defaults to yesterday in site timezone).
	 * @param bool          $force  Bypass same-day idempotency skip.
	 * @param callable|null $runner Optional injectable runner(string $date, string $type): array for tests.
	 * @return array<string,mixed>
	 */
	public static function execute_scheduled( $date = null, $force = false, $runner = null ) {
		$cfg       = self::get_settings();
		$bill_type = $cfg['bill_type'];

		if ( null === $date || '' === trim( (string) $date ) ) {
			$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Shanghai' );
			$date = ( new DateTimeImmutable( 'yesterday', $tz ) )->format( 'Y-m-d' );
		}

		// Idempotency guard: skip if this date + bill_type already completed without error.
		$existing_notified = '';
		foreach ( self::get_history() as $row ) {
			if ( (string) ( $row['date'] ?? '' ) === (string) $date && (string) ( $row['bill_type'] ?? '' ) === (string) $bill_type ) {
				$existing_notified = (string) ( $row['notified_at'] ?? '' );
				if ( ! $force && in_array( (string) ( $row['status'] ?? '' ), array( 'ok', 'diff' ), true ) ) {
					return array(
						'skipped' => true,
						'reason'  => 'already_reconciled',
						'date'    => $date,
						'entry'   => $row,
					);
				}
			}
		}

		if ( is_callable( $runner ) ) {
			$result = (array) call_user_func( $runner, $date, $bill_type );
		} elseif ( class_exists( 'Wei_Alipay_Reconcile_Runner' ) ) {
			$result = (array) Wei_Alipay_Reconcile_Runner::run( $date, $bill_type );
		} else {
			$result = array( 'error' => __( '对账引擎未加载。', 'wei-alipay' ) );
		}

		$entry = self::record_result( $date, $bill_type, $result, $existing_notified );

		$notified = false;
		if ( '' === (string) ( $entry['notified_at'] ?? '' ) ) {
			$should_notify = 'diff' === $entry['status'] || 'error' === $entry['status'] || 'no' === $cfg['notify_on_diff_only'];
			if ( $should_notify ) {
				$notified = self::send_digest_email( $entry, $cfg );
				if ( $notified ) {
					$ts    = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
					$entry = self::record_result( $date, $bill_type, $result, $ts );
				}
			}
		}

		return array(
			'skipped'  => false,
			'notified' => $notified,
			'entry'    => $entry,
		);
	}

	/**
	 * Send concise privacy-safe reconciliation digest email to administrator.
	 *
	 * @param array<string,mixed> $entry Summary entry.
	 * @param array<string,mixed> $cfg   Schedule settings.
	 * @return bool
	 */
	private static function send_digest_email( array $entry, array $cfg ) {
		if ( ! function_exists( 'wp_mail' ) ) {
			return false;
		}
		$to = ! empty( $cfg['notify_email'] ) ? (string) $cfg['notify_email'] : (string) get_option( 'admin_email', '' );
		if ( '' === $to ) {
			return false;
		}
		$site = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'WordPress';
		$date = (string) ( $entry['date'] ?? '' );

		if ( 'error' === ( $entry['status'] ?? '' ) ) {
			/* translators: 1: site name, 2: bill date */
			$subject = sprintf( __( '[%1$s] 支付宝自动对账异常（%2$s）', 'wei-alipay' ), $site, $date );
			$body    = sprintf(
				/* translators: 1: date, 2: error message */
				__( "自动对账执行失败。\n账单日期：%1\$s\n原因：%2\$s\n请登录后台「Wei Alipay → 对账」查看并手动复核。", 'wei-alipay' ),
				$date,
				(string) ( $entry['error_msg'] ?? '' )
			);
		} else {
			/* translators: 1: site name, 2: bill date, 3: mismatched count, 4: unmatched count */
			$subject = sprintf( __( '[%1$s] 支付宝日对账差异提醒（%2$s：金额差 %3$d / 未匹配 %4$d）', 'wei-alipay' ), $site, $date, (int) $entry['mismatched'], (int) $entry['unmatched'] );
			$body    = sprintf(
				/* translators: 1: date, 2: type, 3: total, 4: matched, 5: mismatched, 6: unmatched */
				__( "支付宝自动日对账已完成，检测到待复核差异：\n账单日期：%1\$s\n账单类型：%2\$s\n总行数：%3\$d\n匹配：%4\$d\n金额差异：%5\$d\n未匹配：%6\$d\n\n本邮件仅含统计摘要，不含买家信息或账单链接。请前往后台「Wei Alipay → 对账」拉取明细复核。", 'wei-alipay' ),
				$date,
				(string) $entry['bill_type'],
				(int) $entry['total_rows'],
				(int) $entry['matched'],
				(int) $entry['mismatched'],
				(int) $entry['unmatched']
			);
		}

		return (bool) wp_mail( $to, $subject, $body );
	}

	/**
	 * Handle admin-post schedule save from Reconcile screen.
	 */
	public static function handle_save_schedule() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wei-alipay' ) );
		}
		check_admin_referer( 'wei_alipay_save_reconcile_schedule', 'wei_alipay_reconcile_schedule_nonce' );

		$raw = array(
			'enabled'             => isset( $_POST['schedule_enabled'] ) ? 'yes' : 'no',
			'run_time'            => isset( $_POST['schedule_run_time'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_run_time'] ) ) : '10:30',
			'bill_type'           => isset( $_POST['schedule_bill_type'] ) ? sanitize_key( wp_unslash( $_POST['schedule_bill_type'] ) ) : 'trade',
			'notify_email'        => isset( $_POST['schedule_notify_email'] ) ? sanitize_email( wp_unslash( $_POST['schedule_notify_email'] ) ) : '',
			'notify_on_diff_only' => isset( $_POST['schedule_notify_diff_only'] ) ? 'yes' : 'no',
		);

		$clean = self::sanitize_settings( $raw );
		update_option( self::OPTION_KEY, $clean, false );
		self::sync_schedule( $clean );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'wei-alipay-reconcile-pro',
					'schedule-updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
