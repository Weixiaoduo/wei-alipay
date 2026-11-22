<?php
/**
 * Runtime & contract verification for Wei Alipay 4.2 M2 Scheduled Daily Reconciliation.
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

function w42r_assert( bool $cond, string $msg ): void {
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL: {$msg}\n" );
		exit( 1 );
	}
	echo "PASS: {$msg}\n";
}

$w42_options = array( 'admin_email' => 'ops@example.test' );
$w42_as_actions = array();
$w42_mails = array();

if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return (string) $t; } }
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $default = false ) {
		global $w42_options;
		return array_key_exists( $k, $w42_options ) ? $w42_options[ $k ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = false ) {
		global $w42_options;
		$w42_options[ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $e ) { return filter_var( trim( (string) $e ), FILTER_SANITIZE_EMAIL ); }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $t ) { return '2026-11-22 10:30:00'; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $k ) { return 'WeiShop'; }
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() { return new DateTimeZone( 'Asia/Shanghai' ); }
}
if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
		global $w42_as_actions;
		$w42_as_actions = array_values( array_filter( $w42_as_actions, static fn( $a ) => $a['hook'] !== $hook ) );
	}
}
if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	function as_schedule_recurring_action( $ts, $interval, $hook, $args = array(), $group = '', $unique = false ) {
		global $w42_as_actions;
		$w42_as_actions[] = compact( 'ts', 'interval', 'hook', 'group', 'unique' );
		return 1;
	}
}
if ( ! function_exists( 'as_next_scheduled_action' ) ) {
	function as_next_scheduled_action( $hook, $args = array(), $group = '' ) {
		global $w42_as_actions;
		foreach ( $w42_as_actions as $a ) {
			if ( $a['hook'] === $hook ) {
				return (int) $a['ts'];
			}
		}
		return false;
	}
}
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $body ) {
		global $w42_mails;
		$w42_mails[] = compact( 'to', 'subject', 'body' );
		return true;
	}
}

require_once $root . '/inc/modules/reconcile/inc/class-wei-alipay-reconcile-scheduler.php';

// 1. Settings sanitization
$clean = Wei_Alipay_Reconcile_Scheduler::sanitize_settings( array(
	'enabled'      => 'yes',
	'run_time'     => '99:99',
	'bill_type'    => 'invalid',
	'notify_email' => ' finance@example.test ',
) );
w42r_assert( 'yes' === $clean['enabled'] && '10:30' === $clean['run_time'] && 'trade' === $clean['bill_type'], 'invalid time & bill_type sanitize cleanly to defaults' );
w42r_assert( 'finance@example.test' === $clean['notify_email'], 'notify_email sanitized cleanly' );

// 2. Action Scheduler sync & unschedule
$backend = Wei_Alipay_Reconcile_Scheduler::sync_schedule( $clean );
w42r_assert( 'action_scheduler' === $backend && Wei_Alipay_Reconcile_Scheduler::next_run_timestamp() > 0, 'sync_schedule() registers Action Scheduler recurring action' );
$off = Wei_Alipay_Reconcile_Scheduler::sync_schedule( array( 'enabled' => 'no' ) );
w42r_assert( 'disabled' === $off && 0 === Wei_Alipay_Reconcile_Scheduler::next_run_timestamp(), 'sync_schedule() unschedules actions when disabled' );

// 3. Idempotent run + discrepancy email alert + email throttling on forced re-run
update_option( Wei_Alipay_Reconcile_Scheduler::OPTION_KEY, $clean );
$run_calls = 0;
$diff_runner = static function ( string $date, string $type ) use ( &$run_calls ): array {
	++$run_calls;
	return array(
		'date'       => $date,
		'bill_type'  => $type,
		'total_rows' => 12,
		'matched'    => 9,
		'mismatched' => 1,
		'unmatched'  => 2,
		'details'    => array( array( 'buyer_email' => 'secret@buyer.test', 'url' => 'https://bill.secret' ) ),
	);
};

$r1 = Wei_Alipay_Reconcile_Scheduler::execute_scheduled( '2026-11-21', false, $diff_runner );
w42r_assert( false === $r1['skipped'] && true === $r1['notified'] && 1 === $run_calls && 1 === count( $w42_mails ), 'first discrepancy run executes once and sends 1 alert email' );
w42r_assert( 'finance@example.test' === $w42_mails[0]['to'] && false === strpos( $w42_mails[0]['body'], 'secret@buyer.test' ), 'alert email goes to configured recipient and contains no buyer PII' );

$r2 = Wei_Alipay_Reconcile_Scheduler::execute_scheduled( '2026-11-21', false, $diff_runner );
w42r_assert( true === $r2['skipped'] && 1 === $run_calls && 1 === count( $w42_mails ), 'second run for same date is idempotently skipped with 0 extra emails' );

$r3 = Wei_Alipay_Reconcile_Scheduler::execute_scheduled( '2026-11-21', true, $diff_runner );
w42r_assert( false === $r3['skipped'] && false === $r3['notified'] && 2 === $run_calls && 1 === count( $w42_mails ), 'forced re-run for same date throttles duplicate alert email' );

// 4. Clean run (0 diffs) sends 0 emails when notify_on_diff_only is enabled
$ok_runner = static fn ( string $d, string $t ): array => array( 'date' => $d, 'bill_type' => $t, 'total_rows' => 5, 'matched' => 5, 'mismatched' => 0, 'unmatched' => 0 );
$r4 = Wei_Alipay_Reconcile_Scheduler::execute_scheduled( '2026-11-20', false, $ok_runner );
w42r_assert( 'ok' === $r4['entry']['status'] && false === $r4['notified'] && 1 === count( $w42_mails ), 'zero-diff run sends no alert email when notify_on_diff_only=yes' );

// 5. History stores no PII/details & caps at 30 entries + Overview 7-day summary aggregates diffs
$hist = Wei_Alipay_Reconcile_Scheduler::get_history();
w42r_assert( ! isset( $hist[0]['details'] ) && ! isset( $hist[0]['url'] ), 'stored history omits raw details and bill URLs' );
$sum = Wei_Alipay_Reconcile_Scheduler::recent_diff_summary( 7 );
w42r_assert( 2 === $sum['runs'] && 3 === $sum['diffs'] && 0 === $sum['errors'], 'recent_diff_summary(7) aggregates runs and diff counts accurately' );

for ( $i = 1; $i <= 35; $i++ ) {
	Wei_Alipay_Reconcile_Scheduler::record_result( sprintf( '2026-10-%02d', min( 28, $i ) ) . '-' . $i, 'trade', array( 'total_rows' => 1, 'matched' => 1 ) );
}
w42r_assert( 30 === count( Wei_Alipay_Reconcile_Scheduler::get_history() ), 'history ring buffer strictly caps at 30 entries' );

// 6. Uninstall purge covers 4.2 schedule options & hook
$un_src = (string) file_get_contents( $root . '/uninstall.php' );
w42r_assert( false !== strpos( $un_src, 'wei_alipay_reconcile_schedule' ) && false !== strpos( $un_src, 'wei_alipay_reconcile_history' ) && false !== strpos( $un_src, 'woo_alipay_scheduled_reconcile' ), 'uninstall.php cleans up 4.2 reconcile schedule options & hooks on explicit purge' );

echo "[PASS] 4.2 M2 Scheduled Daily Reconciliation runtime & contract suite\n";
