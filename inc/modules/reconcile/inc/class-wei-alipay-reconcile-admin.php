<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Wei_Alipay_Reconcile_Admin {
    const OPTION_KEY = 'woo_alipay_reconcile_pro_settings';

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
        $last = get_transient( 'woo_alipay_reconcile_last_result' );
        if ( $last ) {
            delete_transient( 'woo_alipay_reconcile_last_result' );
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
                if ( ! empty( $last['file_url'] ) ) {
                    /* translators: %s: link to the downloaded source file */
                    echo '<p>' . sprintf( esc_html__( 'Source file: %s', 'wei-alipay' ), '<a href="' . esc_url( $last['file_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( basename( $last['file'] ) ) . '</a>' ) . '</p>';
                }
                if ( ! empty( $last['notes'] ) && is_array( $last['notes'] ) ) {
                    echo '<details><summary>' . esc_html__( 'Details', 'wei-alipay' ) . '</summary><ul class="ul-disc">';
                    foreach ( $last['notes'] as $note ) {
                        echo '<li>' . esc_html( $note ) . '</li>';
                    }
                    echo '</ul></details>';
                }
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
