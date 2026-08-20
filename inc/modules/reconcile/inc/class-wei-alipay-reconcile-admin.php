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
            __( '对账设置', 'wei-alipay' ),
            function(){
                echo '<p>' . esc_html__( '配置对账计划任务与保留策略。凭据沿用 Wei Alipay 核心插件。', 'wei-alipay' ) . '</p>';
            },
            'woo_alipay_reconcile'
        );

        add_settings_field(
            'enable_schedule',
            __( '启用计划任务', 'wei-alipay' ),
            array( $this, 'field_enable_schedule' ),
            'woo_alipay_reconcile',
            'woo_alipay_reconcile_main'
        );
        add_settings_field(
            'schedule_time',
            __( '每日执行时间', 'wei-alipay' ),
            array( $this, 'field_schedule_time' ),
            'woo_alipay_reconcile',
            'woo_alipay_reconcile_main'
        );
        add_settings_field(
            'timezone',
            __( '时区', 'wei-alipay' ),
            array( $this, 'field_timezone' ),
            'woo_alipay_reconcile',
            'woo_alipay_reconcile_main'
        );
        add_settings_field(
            'retention_days',
            __( '日志保留天数', 'wei-alipay' ),
            array( $this, 'field_retention' ),
            'woo_alipay_reconcile',
            'woo_alipay_reconcile_main'
        );
        add_settings_field(
            'notify_email',
            __( '通知邮箱', 'wei-alipay' ),
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
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Alipay 对账', 'wei-alipay' ) . '</h1>';
        echo '<form action="options.php" method="post">';
        settings_fields( 'woo_alipay_reconcile_group' );
        do_settings_sections( 'woo_alipay_reconcile' );
        submit_button();
        echo '</form>';

        echo '<hr/>';
        echo '<h2>' . esc_html__( '手动对账', 'wei-alipay' ) . '</h2>';

        // Result panel
        $last = get_transient( 'woo_alipay_reconcile_last_result' );
        if ( $last ) {
            delete_transient( 'woo_alipay_reconcile_last_result' );
            if ( ! empty( $last['error'] ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $last['error'] ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>' . esc_html__( '对账执行完成。', 'wei-alipay' ) . '</p></div>';
                echo '<p>';
                /* translators: %1$s: bill date. %2$s: bill type. %3$d: total row count. %4$d: matched count. %5$d: mismatched count. %6$d: unmatched count. */
                printf( esc_html__( '账单日期：%1$s，类型：%2$s。总行数：%3$d，匹配：%4$d，金额不一致：%5$d，未匹配：%6$d。', 'wei-alipay' ),
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
                    echo '<p>' . sprintf( esc_html__( '下载源文件：%s', 'wei-alipay' ), '<a href="' . esc_url( $last['file_url'] ) . '" target="_blank">' . esc_html( basename( $last['file'] ) ) . '</a>' ) . '</p>';
                }
                if ( ! empty( $last['notes'] ) && is_array( $last['notes'] ) ) {
                    echo '<details style="margin:8px 0;"><summary>' . esc_html__( '详细备注', 'wei-alipay' ) . '</summary><ul style="margin:8px 16px;">';
                    foreach ( $last['notes'] as $note ) {
                        echo '<li>' . esc_html( $note ) . '</li>';
                    }
                    echo '</ul></details>';
                }
            }
        }

        // Manual form
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;">';
        wp_nonce_field( 'woo_alipay_reconcile_run' );
        echo '<input type="hidden" name="action" value="woo_alipay_reconcile_run" />';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="woo-ali-bill-date">' . esc_html__( '账单日期', 'wei-alipay' ) . '</label></th><td>';
        echo '<input type="date" id="woo-ali-bill-date" name="bill_date" required />';
        echo '</td></tr>';
        echo '<tr><th><label for="woo-ali-bill-type">' . esc_html__( '账单类型', 'wei-alipay' ) . '</label></th><td>';
        echo '<select id="woo-ali-bill-type" name="bill_type">';
        echo '<option value="trade">' . esc_html__( '交易账单（trade）', 'wei-alipay' ) . '</option>';
        echo '<option value="signcustomer">' . esc_html__( '资金账单（signcustomer）', 'wei-alipay' ) . '</option>';
        echo '</select>';
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button( esc_html__( '开始对账', 'wei-alipay' ) );
        echo '</form>';

        echo '</div>';
    }

    public function field_enable_schedule() {
        $opt = $this->get_settings();
        echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[enable_schedule]" value="yes" ' . checked( 'yes', $opt['enable_schedule'], false ) . ' /> ' . esc_html__( '启用每日自动对账', 'wei-alipay' ) . '</label>';
    }
    public function field_schedule_time() {
        $opt = $this->get_settings();
        echo '<input type="time" name="' . esc_attr( self::OPTION_KEY ) . '[schedule_time]" value="' . esc_attr( $opt['schedule_time'] ) . '" />';
    }
    public function field_timezone() {
        $opt = $this->get_settings();
        echo '<input type="text" name="' . esc_attr( self::OPTION_KEY ) . '[timezone]" value="' . esc_attr( $opt['timezone'] ) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__( '默认使用站点时区，可手动覆盖。示例：Asia/Shanghai', 'wei-alipay' ) . '</p>';
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
