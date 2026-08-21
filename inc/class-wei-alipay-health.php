<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded local health and activity snapshot for the Wei Alipay overview.
 *
 * The snapshot stores timestamps, counters, sanitized error codes and keyed
 * event hashes only. It never stores order numbers, transaction identifiers,
 * amounts, customer data, notification payloads or merchant credentials.
 */
final class Wei_Alipay_Health {
	const OPTION_KEY = 'wei_alipay_health_snapshot';
	const LOCK_KEY = 'wei_alipay_health_snapshot_lock';
	const VERSION = 1;
	const RETENTION_DAYS = 8;

	/**
	 * Register local event observers.
	 */
	public static function register() {
		add_action( 'woo_alipay_webhook_processed', array( __CLASS__, 'record_webhook' ), 20, 3 );
		add_action( 'woo_alipay_check_pending_orders', array( __CLASS__, 'record_order_query_run' ), PHP_INT_MAX );
		add_action( 'woo_alipay_check_timeout_orders', array( __CLASS__, 'record_timeout_run' ), PHP_INT_MAX );
		add_action( 'woo_alipay_retry_failed_webhooks', array( __CLASS__, 'record_webhook_retry_run' ), PHP_INT_MAX );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'record_payment_complete' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'record_failed_order' ), 20, 1 );
		add_action( 'woocommerce_refund_created', array( __CLASS__, 'record_refund' ), 20, 2 );
	}

	/**
	 * Return a normalized snapshot without querying order or webhook tables.
	 *
	 * @return array<string,mixed>
	 */
	public static function snapshot() {
		return self::normalize( get_option( self::OPTION_KEY, array() ) );
	}

	/**
	 * Aggregate the retained seven-day activity buckets.
	 *
	 * @return array<string,int|null>
	 */
	public static function activity_summary() {
		$snapshot = self::snapshot();
		$summary = array(
			'paid' => 0,
			'failed' => 0,
			'refunded' => 0,
			'total' => 0,
			'success_rate' => null,
		);
		$allowed_days = array();
		for ( $offset = 0; $offset < 7; $offset++ ) {
			$allowed_days[] = gmdate( 'Y-m-d', time() - ( $offset * DAY_IN_SECONDS ) );
		}
		foreach ( $snapshot['activity']['days'] as $day => $counts ) {
			if ( ! in_array( $day, $allowed_days, true ) || ! is_array( $counts ) ) {
				continue;
			}
			$summary['paid'] += absint( $counts['paid'] ?? 0 );
			$summary['failed'] += absint( $counts['failed'] ?? 0 );
			$summary['refunded'] += absint( $counts['refunded'] ?? 0 );
		}
		$summary['total'] = $summary['paid'] + $summary['failed'] + $summary['refunded'];
		$payment_attempts = $summary['paid'] + $summary['failed'];
		if ( $payment_attempts > 0 ) {
			$summary['success_rate'] = (int) round( ( $summary['paid'] / $payment_attempts ) * 100 );
		}
		return $summary;
	}

	/**
	 * Record a callback processing result without retaining its merchant number.
	 *
	 * @param string $out_trade_no Merchant number used only to derive a keyed hash.
	 * @param bool   $success      Whether the callback was accepted.
	 * @param string $error        Bounded internal error code.
	 */
	public static function record_webhook( $out_trade_no, $success, $error = '' ) {
		$record_hash = self::event_hash( 'webhook', (string) $out_trade_no );
		self::update(
			static function( $snapshot ) use ( $record_hash, $success, $error ) {
				$now = time();
				$error_code = substr( sanitize_key( (string) $error ), 0, 64 );
				$effective_success = (bool) $success || 'concurrent_notify' === $error_code;
				$snapshot['webhook']['last_at'] = $now;
				$snapshot['webhook']['last_success'] = $effective_success;
				$snapshot['webhook']['last_error_code'] = $effective_success ? '' : $error_code;
				if ( $effective_success ) {
					$snapshot['webhook']['consecutive_failures'] = 0;
					unset( $snapshot['webhook']['failed_records'][ $record_hash ] );
				} else {
					$snapshot['webhook']['consecutive_failures'] = min( 99, absint( $snapshot['webhook']['consecutive_failures'] ) + 1 );
					$snapshot['webhook']['failed_records'][ $record_hash ] = $now;
				}
				return $snapshot;
			}
		);
	}

	public static function record_order_query_run() {
		self::record_job( 'order_query' );
	}

	public static function record_timeout_run() {
		self::record_job( 'timeout' );
	}

	public static function record_webhook_retry_run() {
		self::record_job( 'webhook_retry' );
	}

	/**
	 * Record a successful Wei Alipay payment once per retained period.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public static function record_payment_complete( $order_id ) {
		self::record_order_event( 'paid', absint( $order_id ), absint( $order_id ) );
	}

	/**
	 * Record a failed Wei Alipay order transition once per retained period.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public static function record_failed_order( $order_id ) {
		self::record_order_event( 'failed', absint( $order_id ), absint( $order_id ) );
	}

	/**
	 * Record a created refund for a Wei Alipay order.
	 *
	 * @param int   $refund_id Refund order ID.
	 * @param array $args      WooCommerce refund arguments; values are not stored.
	 */
	public static function record_refund( $refund_id, $args = array() ) {
		$refund = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $refund_id ) ) : false;
		$parent_id = $refund && is_callable( array( $refund, 'get_parent_id' ) ) ? absint( $refund->get_parent_id() ) : absint( $args['order_id'] ?? 0 );
		self::record_order_event( 'refunded', absint( $refund_id ), $parent_id );
	}

	/**
	 * Record a bounded background job completion marker.
	 *
	 * @param string $job Job key.
	 */
	private static function record_job( $job ) {
		if ( ! in_array( $job, array( 'order_query', 'timeout', 'webhook_retry' ), true ) ) {
			return;
		}
		self::update(
			static function( $snapshot ) use ( $job ) {
				$snapshot['jobs'][ $job ] = time();
				return $snapshot;
			}
		);
	}

	/**
	 * Record an order event only when the parent order uses a Wei Alipay gateway.
	 *
	 * @param string $event_type Activity counter name.
	 * @param int    $event_id   Event identity used only for a keyed hash.
	 * @param int    $order_id   Parent order ID.
	 */
	private static function record_order_event( $event_type, $event_id, $order_id ) {
		if ( ! $event_id || ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_callable( array( $order, 'get_payment_method' ) ) || ! self::is_gateway_id( $order->get_payment_method() ) ) {
			return;
		}
		$event_hash = self::event_hash( $event_type, (string) $event_id );
		self::update(
			static function( $snapshot ) use ( $event_hash, $event_type ) {
				if ( isset( $snapshot['activity']['seen'][ $event_hash ] ) ) {
					return $snapshot;
				}
				$day = gmdate( 'Y-m-d' );
				if ( ! isset( $snapshot['activity']['days'][ $day ] ) || ! is_array( $snapshot['activity']['days'][ $day ] ) ) {
					$snapshot['activity']['days'][ $day ] = array( 'paid' => 0, 'failed' => 0, 'refunded' => 0 );
				}
				$snapshot['activity']['days'][ $day ][ $event_type ] = absint( $snapshot['activity']['days'][ $day ][ $event_type ] ?? 0 ) + 1;
				$snapshot['activity']['seen'][ $event_hash ] = time();
				return $snapshot;
			}
		);
	}

	/**
	 * Check the stable payment method allow-list.
	 *
	 * @param string $gateway_id WooCommerce payment method ID.
	 * @return bool
	 */
	private static function is_gateway_id( $gateway_id ) {
		$ids = array( 'alipay', 'alipay_facetopay', 'alipay_installment', 'alipay_barcode', 'alipay_fund_auth', 'alipay_wap', 'acbop' );
		$ids = (array) apply_filters( 'wei_alipay_health_gateway_ids', $ids );
		return in_array( sanitize_key( (string) $gateway_id ), array_map( 'sanitize_key', $ids ), true );
	}

	/**
	 * Update the snapshot under a short option lock.
	 *
	 * @param callable $callback Snapshot mutator.
	 */
	private static function update( $callback ) {
		if ( ! self::acquire_lock() ) {
			return;
		}
		try {
			$snapshot = self::normalize( get_option( self::OPTION_KEY, array() ) );
			$snapshot = call_user_func( $callback, $snapshot );
			$snapshot = self::prune( self::normalize( $snapshot ) );
			update_option( self::OPTION_KEY, $snapshot, false );
		} finally {
			delete_option( self::LOCK_KEY );
		}
	}

	/**
	 * Acquire a bounded local lock; stale locks self-heal after 15 seconds.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		$now = time();
		if ( add_option( self::LOCK_KEY, $now, '', false ) ) {
			return true;
		}
		$existing = absint( get_option( self::LOCK_KEY, 0 ) );
		if ( $existing && $existing < ( $now - 15 ) ) {
			delete_option( self::LOCK_KEY );
			return add_option( self::LOCK_KEY, $now, '', false );
		}
		return false;
	}

	/**
	 * Normalize untrusted option data.
	 *
	 * @param mixed $snapshot Stored snapshot.
	 * @return array<string,mixed>
	 */
	private static function normalize( $snapshot ) {
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		$webhook = isset( $snapshot['webhook'] ) && is_array( $snapshot['webhook'] ) ? $snapshot['webhook'] : array();
		$jobs = isset( $snapshot['jobs'] ) && is_array( $snapshot['jobs'] ) ? $snapshot['jobs'] : array();
		$activity = isset( $snapshot['activity'] ) && is_array( $snapshot['activity'] ) ? $snapshot['activity'] : array();
		return array(
			'version' => self::VERSION,
			'webhook' => array(
				'last_at' => absint( $webhook['last_at'] ?? 0 ),
				'last_success' => isset( $webhook['last_success'] ) ? (bool) $webhook['last_success'] : null,
				'last_error_code' => substr( sanitize_key( (string) ( $webhook['last_error_code'] ?? '' ) ), 0, 64 ),
				'consecutive_failures' => min( 99, absint( $webhook['consecutive_failures'] ?? 0 ) ),
				'failed_records' => isset( $webhook['failed_records'] ) && is_array( $webhook['failed_records'] ) ? $webhook['failed_records'] : array(),
			),
			'jobs' => array(
				'order_query' => absint( $jobs['order_query'] ?? 0 ),
				'timeout' => absint( $jobs['timeout'] ?? 0 ),
				'webhook_retry' => absint( $jobs['webhook_retry'] ?? 0 ),
			),
			'activity' => array(
				'days' => isset( $activity['days'] ) && is_array( $activity['days'] ) ? $activity['days'] : array(),
				'seen' => isset( $activity['seen'] ) && is_array( $activity['seen'] ) ? $activity['seen'] : array(),
			),
		);
	}

	/**
	 * Remove counters and opaque hashes outside the eight-day retention window.
	 *
	 * @param array<string,mixed> $snapshot Normalized snapshot.
	 * @return array<string,mixed>
	 */
	private static function prune( $snapshot ) {
		$cutoff = time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS );
		$oldest_day = gmdate( 'Y-m-d', $cutoff );
		foreach ( $snapshot['activity']['days'] as $day => $counts ) {
			if ( ! is_string( $day ) || $day < $oldest_day || ! is_array( $counts ) ) {
				unset( $snapshot['activity']['days'][ $day ] );
				continue;
			}
			$snapshot['activity']['days'][ $day ] = array(
				'paid' => absint( $counts['paid'] ?? 0 ),
				'failed' => absint( $counts['failed'] ?? 0 ),
				'refunded' => absint( $counts['refunded'] ?? 0 ),
			);
		}
		foreach ( $snapshot['activity']['seen'] as $hash => $timestamp ) {
			if ( ! is_string( $hash ) || absint( $timestamp ) < $cutoff ) {
				unset( $snapshot['activity']['seen'][ $hash ] );
			}
		}
		foreach ( $snapshot['webhook']['failed_records'] as $hash => $timestamp ) {
			if ( ! is_string( $hash ) || absint( $timestamp ) < $cutoff ) {
				unset( $snapshot['webhook']['failed_records'][ $hash ] );
			}
		}
		$snapshot['activity']['seen'] = array_slice( $snapshot['activity']['seen'], -500, 500, true );
		$snapshot['webhook']['failed_records'] = array_slice( $snapshot['webhook']['failed_records'], -100, 100, true );
		return $snapshot;
	}

	/**
	 * Derive a non-reversible local event key without storing the source value.
	 *
	 * @param string $type  Event type.
	 * @param string $value Event identity.
	 * @return string
	 */
	private static function event_hash( $type, $value ) {
		return hash_hmac( 'sha256', sanitize_key( $type ) . ':' . $value, wp_salt( 'nonce' ) );
	}
}
