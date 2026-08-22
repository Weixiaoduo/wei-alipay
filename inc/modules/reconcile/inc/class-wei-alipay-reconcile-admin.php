<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Wei_Alipay_Reconcile_Admin {
    const OPTION_KEY = 'woo_alipay_reconcile_pro_settings';

    public static function result_transient_key( $user_id = null ) {
        $user_id = null === $user_id ? get_current_user_id() : absint( $user_id );
        return 'woo_alipay_reconcile_last_result_' . $user_id;
    }

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'wei-alipay',
            __( 'Wei Alipay Reconciliation', 'wei-alipay' ),
            __( 'Reconciliation', 'wei-alipay' ),
            'manage_woocommerce',
            'wei-alipay-reconcile-pro',
            array( $this, 'render_page' )
        );
    }

    public static function sanitize_settings( $input ) {
        $input = is_array( $input ) ? $input : array();
        $time = isset( $input['schedule_time'] ) && preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $input['schedule_time'] ) ? (string) $input['schedule_time'] : '02:00';
        $timezone = isset( $input['timezone'] ) ? sanitize_text_field( (string) $input['timezone'] ) : 'Asia/Shanghai';
        if ( ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
            $timezone = 'Asia/Shanghai';
        }
        return array(
            'enable_schedule' => isset( $input['enable_schedule'] ) && 'yes' === $input['enable_schedule'] ? 'yes' : 'no',
            'schedule_time'   => $time,
            'timezone'        => $timezone,
            'retention_days'  => min( 3650, max( 0, absint( $input['retention_days'] ?? 0 ) ) ),
            'notify_email'    => isset( $input['notify_email'] ) ? sanitize_email( (string) $input['notify_email'] ) : '',
        );
    }

    public function register_settings() {
        register_setting( 'woo_alipay_reconcile_group', self::OPTION_KEY, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ) ) );

        add_settings_section(
            'woo_alipay_reconcile_main',
            __( 'Reconciliation settings', 'wei-alipay' ),
            function(){
                echo '<p>' . esc_html__( 'Configure the reconciliation schedule and retention policy. Credentials are inherited from the main Wei Alipay gateway.', 'wei-alipay' ) . '</p>';
            },
            'woo_alipay_reconcile'
        );

        add_settings_field(
            'enable_schedule',
            __( 'Enable schedule', 'wei-alipay' ),
            array( $this, 'field_enable_schedule' ),
            'woo_alipay_reconcile',
            'woo_alipay_reconcile_main'
        );
        add_settings_field(
            'schedule_time',
            __( 'Daily run time', 'wei-alipay' ),
            array( $this, 'field_schedule_time' ),
            'woo_alipay_reconcile',
            'woo_alipay_reconcile_main'
        );
        add_settings_field(
            'timezone',
            __( 'Timezone', 'wei-alipay' ),
            array( $this, 'field_timezone' ),
            'woo_alipay_reconcile',
            'woo_alipay_reconcile_main'
        );
        add_settings_field(
            'retention_days',
            __( 'Log retention (days)', 'wei-alipay' ),
            array( $this, 'field_retention' ),
            'woo_alipay_reconcile',
            'woo_alipay_reconcile_main'
        );
        add_settings_field(
            'notify_email',
            __( 'Notification email', 'wei-alipay' ),
            array( $this, 'field_notify_email' ),
            'woo_alipay_reconcile',
            'woo_alipay_reconcile_main'
        );
    }

    protected function get_settings() {
        $defaults = array(
            'enable_schedule' => 'no',
            'schedule_time'   => '03:30',
            'timezone'        => wp_timezone_string(),
            'retention_days'  => 30,
            'notify_email'    => get_option( 'admin_email' ),
        );
        $opt = get_option( self::OPTION_KEY, array() );
        return wp_parse_args( $opt, $defaults );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selection.
        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'schedule';
        $view = in_array( $view, array( 'schedule', 'manual' ), true ) ? $view : 'schedule';
        echo '<div class="wrap wei-alipay-admin">';
        Wei_Alipay_Admin_UI::page_header(
            __( 'Wei Alipay Reconciliation', 'wei-alipay' ),
            __( 'Schedule statement reconciliation or request one statement manually.', 'wei-alipay' )
        );
        Wei_Alipay_Admin_UI::tabs(
            array(
                'schedule' => array(
                    'label' => __( 'Schedule and retention', 'wei-alipay' ),
                    'url'   => add_query_arg( array( 'page' => 'wei-alipay-reconcile-pro', 'view' => 'schedule' ), admin_url( 'admin.php' ) ),
                ),
                'manual'   => array(
                    'label' => __( 'Manual reconciliation', 'wei-alipay' ),
                    'url'   => add_query_arg( array( 'page' => 'wei-alipay-reconcile-pro', 'view' => 'manual' ), admin_url( 'admin.php' ) ),
                ),
            ),
            $view,
            __( 'Reconciliation sections', 'wei-alipay' )
        );
        if ( 'schedule' === $view ) {
        echo '<form class="card wei-alipay-card-wide" action="options.php" method="post" data-wei-dirty>';
        settings_fields( 'woo_alipay_reconcile_group' );
        do_settings_sections( 'woo_alipay_reconcile' );
        submit_button();
        echo '<span data-wei-dirty-message aria-live="polite"></span>';
        echo '</form>';
        }

        if ( 'manual' === $view ) {
        echo '<h2>' . esc_html__( 'Manual reconciliation', 'wei-alipay' ) . '</h2>';
        echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Manual reconciliation downloads a statement from Alipay. It does not send a payment or refund, but it does contact the configured provider environment.', 'wei-alipay' ) . '</p></div>';

        // Result panel
        $result_key = self::result_transient_key();
        $last = get_transient( $result_key );
        if ( $last ) {
            delete_transient( $result_key );
            if ( ! empty( $last['error'] ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $last['error'] ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>' . esc_html__( 'Reconciliation completed.', 'wei-alipay' ) . '</p></div>';
                echo '<p>';
                /* translators: %1$s: bill date. %2$s: bill type. %3$d: total row count. %4$d: matched count. %5$d: mismatched count. %6$d: unmatched count. */
                printf( esc_html__( 'Statement date: %1$s; type: %2$s. Rows: %3$d; matched: %4$d; amount mismatches: %5$d; unmatched: %6$d.', 'wei-alipay' ),
                    esc_html( $last['date'] ?? '' ),
                    esc_html( $last['bill_type'] ?? '' ),
                    intval( $last['total_rows'] ?? 0 ),
                    intval( $last['matched'] ?? 0 ),
                    intval( $last['mismatched'] ?? 0 ),
                    intval( $last['unmatched'] ?? 0 )
                );
                echo '</p>';
                $this->render_result_details( $last );
            }
        }

        // Manual form
        echo '<form class="card wei-alipay-card-wide" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'woo_alipay_reconcile_run', 'woo_alipay_reconcile_run_nonce' );
        echo '<input type="hidden" name="action" value="woo_alipay_reconcile_run" />';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="woo-ali-bill-date">' . esc_html__( 'Statement date', 'wei-alipay' ) . '</label></th><td>';
        echo '<input type="date" id="woo-ali-bill-date" name="bill_date" required />';
        echo '</td></tr>';
        echo '<tr><th><label for="woo-ali-bill-type">' . esc_html__( 'Statement type', 'wei-alipay' ) . '</label></th><td>';
        echo '<select id="woo-ali-bill-type" name="bill_type">';
        echo '<option value="trade">' . esc_html__( 'Trade statement (trade)', 'wei-alipay' ) . '</option>';
        echo '<option value="signcustomer">' . esc_html__( 'Funds statement (signcustomer)', 'wei-alipay' ) . '</option>';
        echo '</select>';
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary" data-wei-confirm="' . esc_attr__( 'Download and reconcile this Alipay statement now?', 'wei-alipay' ) . '">' . esc_html__( 'Start reconciliation', 'wei-alipay' ) . '</button></p>';
        echo '</form>';
        }

        echo '</div>';
    }

    private function render_result_details( $result ) {
        $details = isset( $result['details'] ) && is_array( $result['details'] ) ? $result['details'] : array();
        $counts = isset( $result['detail_counts'] ) && is_array( $result['detail_counts'] ) ? $result['detail_counts'] : array();
        $anomalies = isset( $details['anomalies'] ) && is_array( $details['anomalies'] ) ? $details['anomalies'] : array();
        $matched = isset( $details['matched'] ) && is_array( $details['matched'] ) ? $details['matched'] : array();

        echo '<p class="description">' . esc_html__( 'Reconciliation only reports differences. It never changes an order, captures funds, closes a trade, or issues a refund.', 'wei-alipay' ) . '</p>';

        $anomaly_total = absint( $counts['anomalies'] ?? count( $anomalies ) );
        $matched_total = absint( $counts['matched'] ?? count( $matched ) );
        if ( $anomaly_total > 0 ) {
            echo '<details open class="wei-alipay-reconcile-results">';
            /* translators: %d: anomaly count. */
            echo '<summary><strong>' . esc_html( sprintf( __( 'Exceptions requiring review (%d)', 'wei-alipay' ), $anomaly_total ) ) . '</strong></summary>';
            $this->render_result_table( $anomalies );
            $this->render_result_limit_notice( count( $anomalies ), $anomaly_total );
            echo '</details>';
        } else {
            echo '<div class="notice notice-success inline"><p>' . esc_html__( 'No amount mismatch or unmatched statement row was found.', 'wei-alipay' ) . '</p></div>';
        }

        if ( $matched_total > 0 ) {
            echo '<details class="wei-alipay-reconcile-results">';
            /* translators: %d: matched count. */
            echo '<summary><strong>' . esc_html( sprintf( __( 'Matched rows (%d)', 'wei-alipay' ), $matched_total ) ) . '</strong></summary>';
            $this->render_result_table( $matched );
            $this->render_result_limit_notice( count( $matched ), $matched_total );
            echo '</details>';
        }
    }

    private function render_result_limit_notice( $shown, $total ) {
        if ( $shown >= $total ) {
            return;
        }
        /* translators: %1$d: displayed rows. %2$d: total rows in category. */
        echo '<p class="description">' . esc_html( sprintf( __( 'Showing the first %1$d of %2$d rows. Narrow the statement date before retrying.', 'wei-alipay' ), $shown, $total ) ) . '</p>';
    }

    private function render_result_table( $rows ) {
        if ( empty( $rows ) ) {
            return;
        }
        echo '<div class="wei-alipay-table-wrap"><table class="wp-list-table widefat striped wei-alipay-reconcile-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Result', 'wei-alipay' ) . '</th>';
        echo '<th>' . esc_html__( 'Merchant order number', 'wei-alipay' ) . '</th>';
        echo '<th>' . esc_html__( 'Alipay trade number', 'wei-alipay' ) . '</th>';
        echo '<th>' . esc_html__( 'Statement / order amount', 'wei-alipay' ) . '</th>';
        echo '<th>' . esc_html__( 'Statement status / time', 'wei-alipay' ) . '</th>';
        echo '<th>' . esc_html__( 'WooCommerce order', 'wei-alipay' ) . '</th>';
        echo '<th>' . esc_html__( 'Reason', 'wei-alipay' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $row = is_array( $row ) ? $row : array();
            $result = sanitize_key( $row['result'] ?? '' );
            $order_id = absint( $row['order_id'] ?? 0 );
            $order = $order_id ? wc_get_order( $order_id ) : false;
            echo '<tr>';
            echo '<td>' . esc_html( $this->result_label( $result ) ) . '</td>';
            echo '<td><code>' . esc_html( (string) ( $row['out_trade_no'] ?? '' ) ) . '</code></td>';
            echo '<td><code>' . esc_html( (string) ( $row['trade_no_masked'] ?? '' ) ) . '</code></td>';
            echo '<td>' . esc_html( $this->amount_pair_label( $row ) ) . '</td>';
            echo '<td>' . esc_html( $this->statement_state_label( $row ) ) . '</td>';
            echo '<td>';
            if ( $order && current_user_can( 'edit_shop_order', $order_id ) ) {
                echo '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( sprintf( '#%s', $order->get_order_number() ) ) . '</a>';
                echo '<br><span class="description">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</span>';
            } else {
                echo '&mdash;';
            }
            echo '</td>';
            echo '<td>' . esc_html( $this->reason_label( sanitize_key( $row['reason'] ?? '' ) ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private function result_label( $result ) {
        $labels = array(
            'matched'    => __( 'Matched', 'wei-alipay' ),
            'mismatched' => __( 'Amount mismatch', 'wei-alipay' ),
            'unmatched'  => __( 'Unmatched', 'wei-alipay' ),
        );
        return $labels[ $result ] ?? __( 'Unknown', 'wei-alipay' );
    }

    private function reason_label( $reason ) {
        $labels = array(
            'amounts_match'                    => __( 'Statement and order amounts match.', 'wei-alipay' ),
            'amount_mismatch'                  => __( 'Statement and WooCommerce order amounts differ.', 'wei-alipay' ),
            'missing_merchant_order_number'    => __( 'The statement row has no merchant order number.', 'wei-alipay' ),
            'different_site'                   => __( 'The merchant order number belongs to another site in this network.', 'wei-alipay' ),
            'unrecognized_merchant_order_number'=> __( 'The merchant order number format is not recognized.', 'wei-alipay' ),
            'order_not_found'                  => __( 'No WooCommerce order exists for the parsed order ID.', 'wei-alipay' ),
            'merchant_order_number_not_bound'  => __( 'The order exists, but its stored merchant order number does not match this statement row.', 'wei-alipay' ),
        );
        return $labels[ $reason ] ?? __( 'Review this statement row manually.', 'wei-alipay' );
    }

    private function amount_pair_label( $row ) {
        $statement = (string) ( $row['statement_amount'] ?? '' );
        $order = (string) ( $row['order_amount'] ?? '' );
        return '' === $order ? $statement : $statement . ' / ' . $order;
    }

    private function statement_state_label( $row ) {
        $parts = array_filter( array(
            sanitize_text_field( (string) ( $row['trade_status'] ?? '' ) ),
            sanitize_text_field( (string) ( $row['completed_at'] ?? '' ) ),
        ) );
        return empty( $parts ) ? '—' : implode( ' / ', $parts );
    }

    public function field_enable_schedule() {
        $opt = $this->get_settings();
        echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[enable_schedule]" value="yes" ' . checked( 'yes', $opt['enable_schedule'], false ) . ' /> ' . esc_html__( 'Run automatic reconciliation every day', 'wei-alipay' ) . '</label>';
    }
    public function field_schedule_time() {
        $opt = $this->get_settings();
        echo '<input type="time" name="' . esc_attr( self::OPTION_KEY ) . '[schedule_time]" value="' . esc_attr( $opt['schedule_time'] ) . '" />';
    }
    public function field_timezone() {
        $opt = $this->get_settings();
        echo '<input type="text" name="' . esc_attr( self::OPTION_KEY ) . '[timezone]" value="' . esc_attr( $opt['timezone'] ) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__( 'Uses the site timezone by default. Example: Asia/Shanghai', 'wei-alipay' ) . '</p>';
    }
    public function field_retention() {
        $opt = $this->get_settings();
        echo '<input type="number" min="0" step="1" name="' . esc_attr( self::OPTION_KEY ) . '[retention_days]" value="' . esc_attr( intval( $opt['retention_days'] ) ) . '" />';
    }
    public function field_notify_email() {
        $opt = $this->get_settings();
        echo '<input type="email" name="' . esc_attr( self::OPTION_KEY ) . '[notify_email]" value="' . esc_attr( $opt['notify_email'] ) . '" class="regular-text" />';
    }
}
