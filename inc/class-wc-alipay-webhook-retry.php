<?php
/**
 * Alipay Webhook Retry Class
 * 
 * Handles recording and retrying failed Alipay notifications
 * 
 * @package Wei_Alipay
 * @since 3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Alipay_Webhook_Retry {

	/**
	 * Table name for webhook logs
	 */
	const TABLE_NAME = 'woo_alipay_webhook_logs';

	/**
	 * Maximum retry attempts (default)
	 */
	const MAX_RETRIES = 5;
	
	/**
	 * Gateway instance
	 */
	private $gateway;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// The class is loaded on init, which can be too late to register activation hooks.
		// Keep the hook for direct loads and add a versioned admin repair path.
		register_activation_hook( WEI_ALIPAY_PLUGIN_FILE, array( $this, 'create_table' ) );
		add_action( 'admin_init', array( $this, 'maybe_create_table' ) );
		
		// Log webhook attempts
		add_action( 'woocommerce_api_wc_alipay', array( $this, 'log_webhook_attempt' ), 5 );
		
		// Schedule retry cron
		add_action( 'wp', array( $this, 'schedule_retry_cron' ) );
		add_action( 'woo_alipay_retry_failed_webhooks', array( $this, 'retry_failed_webhooks' ) );
		add_action( 'woo_alipay_webhook_processed', array( $this, 'record_processing_result' ), 10, 3 );
		
		// Add admin menu for webhook logs
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 60 );
		
		// AJAX handler for manual retry
		add_action( 'wp_ajax_woo_alipay_retry_webhook', array( $this, 'ajax_retry_webhook' ) );
	}

	/**
	 * Create or upgrade the webhook table only when its schema version changes.
	 */
	public function maybe_create_table() {
		if ( '1' !== get_option( 'woo_alipay_webhook_schema_version' ) ) {
			$this->create_table();
		}
	}

	/**
	 * Create webhook logs table
	 */
	public function create_table() {
		global $wpdb;
		
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			order_id bigint(20) NOT NULL,
			out_trade_no varchar(64) NOT NULL,
			trade_no varchar(64) DEFAULT NULL,
			request_data longtext NOT NULL,
			response_data text DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			retry_count int(11) NOT NULL DEFAULT 0,
			last_error text DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY order_id (order_id),
			KEY out_trade_no (out_trade_no),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'woo_alipay_webhook_schema_version', '1', false );
	}

	/**
	 * Log webhook attempt
	 */
	public function log_webhook_attempt() {
		global $wpdb;
		
		// Check if feature is enabled
		$gateway = $this->get_gateway();
		if ( ! $gateway || 'yes' !== $gateway->get_option( 'enable_webhook_retry', 'yes' ) ) {
			return;
		}
		
		// Only log POST requests with data
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- signed callback; scalar allowlist is sanitized below.
		$raw_request = wp_unslash( $_POST );
		if ( 'POST' !== $request_method || empty( $raw_request ) ) {
			return;
		}
		$request_data = array();
		foreach ( array( 'notify_id', 'notify_type', 'out_trade_no', 'trade_no', 'trade_status', 'total_amount', 'app_id', 'sign_type' ) as $field ) {
			if ( isset( $raw_request[ $field ] ) && is_scalar( $raw_request[ $field ] ) ) {
				$request_data[ $field ] = sanitize_text_field( (string) $raw_request[ $field ] );
			}
		}
		$out_trade_no = isset( $request_data['out_trade_no'] ) ? $request_data['out_trade_no'] : '';
		$trade_no = isset( $request_data['trade_no'] ) ? sanitize_text_field( $request_data['trade_no'] ) : '';
		
		if ( empty( $out_trade_no ) ) {
			return;
		}

		// Extract order ID
		$out_trade_no_parts = explode( '-', str_replace( 'WooA', '', $out_trade_no ) );
		$order_id = absint( array_shift( $out_trade_no_parts ) );

		// Check if this webhook was already logged recently
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$existing = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.
			"SELECT id FROM %i
			WHERE out_trade_no = %s 
			AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
			ORDER BY id DESC LIMIT 1",
			$table_name,
			$out_trade_no
		) );

		$now = current_time( 'mysql' );

		if ( $existing ) {
			// Update existing log
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.
				$table_name,
				array(
					'request_data' => wp_json_encode( $request_data ),
					'trade_no'     => $trade_no,
					'updated_at'   => $now,
				),
				array( 'id' => $existing ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			// Insert new log
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.
				$table_name,
				array(
					'order_id'      => $order_id,
					'out_trade_no'  => $out_trade_no,
					'trade_no'      => $trade_no,
					'request_data'  => wp_json_encode( $request_data ),
					'status'        => 'pending',
					'retry_count'   => 0,
					'created_at'    => $now,
					'updated_at'    => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Update webhook log status
	 *
	 * @param string $out_trade_no
	 * @param string $status
	 * @param string $error
	 */
	public function update_webhook_status( $out_trade_no, $status, $error = '' ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$now = current_time( 'mysql' );

		$data = array(
			'status'     => $status,
			'updated_at' => $now,
		);
		$format = array( '%s', '%s' );

		if ( ! empty( $error ) ) {
			$data['last_error'] = $error;
			$format[] = '%s';
		}

		if ( 'failed' === $status ) {
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.
				"UPDATE %i SET retry_count = retry_count + 1 WHERE out_trade_no = %s",
				$table_name,
				$out_trade_no
			) );
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.
			$table_name,
			$data,
			array( 'out_trade_no' => $out_trade_no ),
			$format,
			array( '%s' )
		);
	}


	/**
	 * Persist the outcome emitted by the main callback processor.
	 */
	public function record_processing_result( $out_trade_no, $success, $error = '' ) {
		if ( !$out_trade_no ) {
			return;
		}
		$this->update_webhook_status( $out_trade_no, $success ? 'success' : 'failed', $error );
	}

	/**
	 * Schedule retry cron
	 */
	public function schedule_retry_cron() {
		$gateway = $this->get_gateway();
		
		// Check if feature is enabled
		if ( ! $gateway || 'yes' !== $gateway->get_option( 'enable_webhook_retry', 'yes' ) ) {
			// Unschedule if disabled
			$timestamp = wp_next_scheduled( 'woo_alipay_retry_failed_webhooks' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'woo_alipay_retry_failed_webhooks' );
			}
			return;
		}
		
		if ( ! wp_next_scheduled( 'woo_alipay_retry_failed_webhooks' ) ) {
			// Retry every hour
			wp_schedule_event( time(), 'hourly', 'woo_alipay_retry_failed_webhooks' );
		}
	}

	/**
	 * Retry failed webhooks
	 */
	public function retry_failed_webhooks() {
		global $wpdb;
		
		// Check if feature is enabled
		$gateway = $this->get_gateway();
		if ( ! $gateway || 'yes' !== $gateway->get_option( 'enable_webhook_retry', 'yes' ) ) {
			return;
		}
		
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		
		// Get max retries from settings
		$max_retries = absint( $gateway->get_option( 'webhook_max_retries', self::MAX_RETRIES ) );
		$retry_interval = absint( $gateway->get_option( 'webhook_retry_interval', '10' ) );
		
		// Get failed webhooks that haven't exceeded max retries
		$failed_webhooks = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.
			"SELECT * FROM %i
			WHERE status = 'failed' 
			AND retry_count < %d 
			AND updated_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)
			ORDER BY created_at ASC
			LIMIT 10",
			$table_name,
			$max_retries,
			$retry_interval
		) );

		foreach ( $failed_webhooks as $webhook ) {
			$this->process_webhook_retry( $webhook );
			
			// Sleep to avoid rate limiting
			sleep( 2 );
		}
	}

	/**
	 * Process webhook retry
	 *
	 * @param object $webhook
	 * @return bool
	 */
	private function process_webhook_retry( $webhook ) {
		$order = wc_get_order( $webhook->order_id );
		
		if ( ! $order ) {
			$this->update_webhook_status( $webhook->out_trade_no, 'failed', 'Order not found' );
			return false;
		}

		// If order is already paid, mark as success
		if ( $order->is_paid() ) {
			$this->update_webhook_status( $webhook->out_trade_no, 'success', '' );
			return true;
		}

		// Query order status from Alipay
		if ( class_exists( 'WC_Alipay_Order_Query' ) ) {
			$query = new WC_Alipay_Order_Query();
			$result = $query->query_single_order_status( $order );
			
			if ( true === $result ) {
				$this->update_webhook_status( $webhook->out_trade_no, 'success', '' );
				$this->log( 'Webhook retry successful for order #' . $webhook->order_id );
				return true;
			} elseif ( is_wp_error( $result ) ) {
				$this->update_webhook_status( $webhook->out_trade_no, 'failed', $result->get_error_message() );
				$this->log( 'Webhook retry failed for order #' . $webhook->order_id . ': ' . $result->get_error_message(), 'error' );
				return false;
			}
		}

		$this->update_webhook_status( $webhook->out_trade_no, 'failed', 'Unable to confirm payment status' );
		return false;
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		// Check if feature is enabled
		$gateway = $this->get_gateway();
		if ( ! $gateway || 'yes' !== $gateway->get_option( 'enable_webhook_retry', 'yes' ) ) {
			return;
		}
		
		add_submenu_page(
			'wei-alipay',
			__( 'Wei Alipay Webhook Logs', 'wei-alipay' ),
			__( 'Webhook Logs', 'wei-alipay' ),
			'manage_woocommerce',
			'wei-alipay-webhooks',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render admin page
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view webhook logs.', 'wei-alipay' ) );
		}

		global $wpdb;
		
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$retry_notice = false;
		
		// Handle bulk actions
		if ( isset( $_POST['action'], $_POST['webhook_ids'] ) && 'retry_selected' === sanitize_key( wp_unslash( $_POST['action'] ) ) ) {
			check_admin_referer( 'bulk-webhooks' );
			$webhook_ids = array_map( 'absint', wp_unslash( (array) $_POST['webhook_ids'] ) );

			foreach ( $webhook_ids as $webhook_id ) {
				$webhook = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.
					"SELECT * FROM %i WHERE id = %d",
					$table_name,
					absint( $webhook_id )
				) );
				
				if ( $webhook ) {
					$this->process_webhook_retry( $webhook );
				}
			}
			
			$retry_notice = true;
		}

		// Get statistics
		$total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.
		$pending = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'pending'", $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.
		$success = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'success'", $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.
		$failed = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'failed'", $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.

		// Get recent webhooks
		$current_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$current_status = in_array( $current_status, array( 'all', 'pending', 'success', 'failed' ), true ) ? $current_status : 'all';
		$per_page = 20;
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$offset = ( $paged - 1 ) * $per_page;

		if ( 'all' === $current_status ) {
			$webhooks = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d', $table_name, $per_page, $offset )
			);
			$total_items = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.
		} else {
			$webhooks = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.
				$wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d', $table_name, $current_status, $per_page, $offset )
			);
			$total_items = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $table_name, $current_status ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.
		}
		$total_pages = ceil( $total_items / $per_page );

		?>
		<div class="wrap wei-alipay-admin">
			<?php
			Wei_Alipay_Admin_UI::page_header(
				__( 'Wei Alipay Webhook Logs', 'wei-alipay' ),
				__( 'Inspect callback processing and retry failed records. Stored request data is limited to an allow-list and never includes signatures or private keys.', 'wei-alipay' )
			);
			if ( $retry_notice ) {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Selected webhooks have been retried.', 'wei-alipay' ) . '</p></div>';
			}
			?>
			
			<ul class="subsubsub" aria-label="<?php echo esc_attr__( 'Webhook status filters', 'wei-alipay' ); ?>">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wei-alipay-webhooks&status=all' ) ); ?>" <?php echo 'all' === $current_status ? 'class="current"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal attribute. ?>><?php /* translators: %d: total webhook log count */ printf( esc_html__( 'All (%d)', 'wei-alipay' ), esc_html( number_format_i18n( $total ) ) ); ?></a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wei-alipay-webhooks&status=pending' ) ); ?>" <?php echo 'pending' === $current_status ? 'class="current"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal attribute. ?>><?php /* translators: %d: pending webhook log count */ printf( esc_html__( 'Pending (%d)', 'wei-alipay' ), esc_html( number_format_i18n( $pending ) ) ); ?></a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wei-alipay-webhooks&status=success' ) ); ?>" <?php echo 'success' === $current_status ? 'class="current"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal attribute. ?>><?php /* translators: %d: successful webhook log count */ printf( esc_html__( 'Success (%d)', 'wei-alipay' ), esc_html( number_format_i18n( $success ) ) ); ?></a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wei-alipay-webhooks&status=failed' ) ); ?>" <?php echo 'failed' === $current_status ? 'class="current"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal attribute. ?>><?php /* translators: %d: failed webhook log count */ printf( esc_html__( 'Failed (%d)', 'wei-alipay' ), esc_html( number_format_i18n( $failed ) ) ); ?></a></li>
			</ul>
			<br class="clear" />
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Retrying may query Alipay for current transaction status. Select only records that require another verification attempt.', 'wei-alipay' ); ?></p></div>

			<form method="post">
				<?php wp_nonce_field( 'bulk-webhooks' ); ?>
				<input type="hidden" name="action" value="retry_selected" />
				
				<div class="tablenav top wei-alipay-bulk-actions">
					<div class="alignleft actions">
						<input type="submit" class="button action" data-wei-confirm="<?php echo esc_attr__( 'Retry the selected webhook records now?', 'wei-alipay' ); ?>" value="<?php esc_html_e( 'Retry Selected', 'wei-alipay' ); ?>" />
					</div>
				</div>

				<div class="wei-alipay-table-wrap"><table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" id="wei-alipay-select-all" aria-label="<?php echo esc_attr__( 'Select all webhook records', 'wei-alipay' ); ?>" /></td>
							<th><?php esc_html_e( 'Order', 'wei-alipay' ); ?></th>
							<th><?php esc_html_e( 'Trade No', 'wei-alipay' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wei-alipay' ); ?></th>
							<th><?php esc_html_e( 'Retry Count', 'wei-alipay' ); ?></th>
							<th><?php esc_html_e( 'Error', 'wei-alipay' ); ?></th>
							<th><?php esc_html_e( 'Created', 'wei-alipay' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wei-alipay' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $webhooks ) ) : ?>
							<tr class="no-items"><td class="colspanchange wei-alipay-table-empty" colspan="8"><?php esc_html_e( 'No webhook logs match the current filter.', 'wei-alipay' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $webhooks as $webhook ) : ?>
								<tr>
									<th class="check-column">
										<input type="checkbox" name="webhook_ids[]" value="<?php echo esc_attr( $webhook->id ); ?>" />
									</th>
									<td>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $webhook->order_id . '&action=edit' ) ); ?>">
											#<?php echo esc_html( $webhook->order_id ); ?>
										</a>
									</td>
									<td><?php echo esc_html( $webhook->trade_no ? $webhook->trade_no : '-' ); ?></td>
									<td>
										<?php $status_class = 'success' === $webhook->status ? 'is-success' : ( 'failed' === $webhook->status ? 'is-error' : 'is-warning' ); ?>
										<span class="wei-alipay-status <?php echo esc_attr( $status_class ); ?>">
											<?php echo esc_html( ucfirst( $webhook->status ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $webhook->retry_count ); ?> / <?php echo esc_html( self::MAX_RETRIES ); ?></td>
									<td><?php echo esc_html( $webhook->last_error ? substr( $webhook->last_error, 0, 50 ) . '...' : '-' ); ?></td>
									<td><?php echo esc_html( $webhook->created_at ); ?></td>
									<td>
										<?php if ( 'failed' === $webhook->status && $webhook->retry_count < self::MAX_RETRIES ) : ?>
											<button type="button" class="button button-small wei-alipay-retry-webhook" data-webhook-id="<?php echo esc_attr( $webhook->id ); ?>">
												<?php esc_html_e( 'Retry', 'wei-alipay' ); ?>
											</button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table></div>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<?php
							echo wp_kses_post( paginate_links( array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $paged,
								'total'   => $total_pages,
							) ) );
							?>
						</div>
					</div>
				<?php endif; ?>
			</form>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('#wei-alipay-select-all').on('change', function() {
				$('input[name="webhook_ids[]"]').prop('checked', this.checked);
			});
			$('.wei-alipay-retry-webhook').on('click', function() {
				var $btn = $(this);
				var webhookId = $btn.data('webhook-id');
				
				$btn.prop('disabled', true).text(<?php echo wp_json_encode( __( 'Retrying...', 'wei-alipay' ) ); ?>);
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'woo_alipay_retry_webhook',
						webhook_id: webhookId,
						nonce: <?php echo wp_json_encode( wp_create_nonce( 'woo_alipay_retry_webhook' ) ); ?>
					},
					success: function(response) {
						if (response.success) {
							location.reload();
						} else {
							alert(response.data.message);
							$btn.prop('disabled', false).text('<?php esc_html_e( 'Retry', 'wei-alipay' ); ?>');
						}
					},
					error: function() {
						alert(<?php echo wp_json_encode( __( 'The retry request could not be completed. Check the connection and try again.', 'wei-alipay' ) ); ?>);
						$btn.prop('disabled', false).text(<?php echo wp_json_encode( __( 'Retry', 'wei-alipay' ) ); ?>);
					}
				});
			});
		});
		</script>

		<?php
	}

	/**
	 * AJAX handler for manual retry
	 */
	public function ajax_retry_webhook() {
		check_ajax_referer( 'woo_alipay_retry_webhook', 'nonce' );
		
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'wei-alipay' ) ) );
		}

		$webhook_id = isset( $_POST['webhook_id'] ) ? absint( wp_unslash( $_POST['webhook_id'] ) ) : 0;
		
		if ( ! $webhook_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid webhook ID', 'wei-alipay' ) ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		
		$webhook = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned retry table requires current operational state.
			"SELECT * FROM %i WHERE id = %d",
			$table_name,
			$webhook_id
		) );

		if ( ! $webhook ) {
			wp_send_json_error( array( 'message' => __( 'Webhook not found', 'wei-alipay' ) ) );
		}

		$result = $this->process_webhook_retry( $webhook );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Webhook retried successfully', 'wei-alipay' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Webhook retry failed', 'wei-alipay' ) ) );
		}
	}

	/**
	 * Get gateway instance
	 *
	 * @return WC_Alipay|null
	 */
	private function get_gateway() {
		if ( ! $this->gateway ) {
			$gateways = WC()->payment_gateways->payment_gateways();
			$this->gateway = isset( $gateways['alipay'] ) ? $gateways['alipay'] : null;
		}
		
		return $this->gateway;
	}
	
	/**
	 * Log message
	 *
	 * @param string $message
	 * @param string $level
	 */
	private function log( $message, $level = 'info' ) {
		$logger = wc_get_logger();
		$logger->log( $level, $message, array( 'source' => 'alipay-webhook' ) );
	}
}

// Initialize the class
new WC_Alipay_Webhook_Retry();
