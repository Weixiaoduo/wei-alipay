<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Woo_Alipay_Reconcile_Admin {
    const OPTION_KEY = 'woo_alipay_reconcile_pro_settings';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Alipay Reconcile Pro', 'woo-alipay-reconcile-pro' ),
            __( 'Alipay 对账', 'woo-alipay-reconcile-pro' ),
            'manage_woocommerce',
            'woo-alipay-reconcile-pro',
            array( $this, 'render_page' )
        );
    }

    public function register_settings() {
        register_setting( 'woo_alipay_reconcile_group', self::OPTION_KEY );

        add_settings_section(
            'woo_alipay_reconcile_main',
            __( '对账设置', 'woo-alipay-reconcile-pro' ),
            function(){
                echo '<p>' . esc_html__( '配置对账计划任务与保留策略。凭据沿用 Woo Alipay 核心插件。', 'woo-alipay-reconcile-pro' ) . '</p>';
            },
            'woo_alipay_reconcile'
        );

        add_settings_field(
            'enable_schedule',
            __( '启用计划任务', 'woo-alipay-reconcile-pro' ),
            array( $this, 'field_enable_schedule' ),
            'woo_alipay_reconcile',
            'woo_alipay_reconcile_main'
        );
        add_settings_field(
            'schedule_time',
            __( '每日执行时间', 'woo-alipay-reconcile-pro' ),
            array( $this, 'field_schedule_time' ),
            'woo_alipay_reconcile',
            'woo_alipay_reconcile_main'
        );
        add_settings_field(
            'timezone',
            __( '时区', 'woo-alipay-reconcile-pro' ),
            array( $this, 'field_timezone' ),
            'woo_alipay_reconcile',
            'woo_alipay_reconcile_main'
        );
        add_settings_field(
            'retention_days',
            __( '日志保留天数', 'woo-alipay-reconcile-pro' ),
            array( $this, 'field_retention' ),
            'woo_alipay_reconcile',
            'woo_alipay_reconcile_main'
        );
        add_settings_field(
            'notify_email',
            __( '通知邮箱', 'woo-alipay-reconcile-pro' ),
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
        echo '<h1>' . esc_html__( 'Alipay 对账', 'woo-alipay-reconcile-pro' ) . '</h1>';
        echo '<form action="options.php" method="post">';
        settings_fields( 'woo_alipay_reconcile_group' );
        do_settings_sections( 'woo_alipay_reconcile' );
        submit_button();
        echo '</form>';

        echo '<hr/>';
        echo '<h2>' . esc_html__( '手动对账', 'woo-alipay-reconcile-pro' ) . '</h2>';

        // Result panel
        $last = get_transient( 'woo_alipay_reconcile_last_result' );
        if ( $last ) {
            delete_transient( 'woo_alipay_reconcile_last_result' );
            if ( ! empty( $last['error'] ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $last['error'] ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>' . esc_html__( '对账执行完成。', 'woo-alipay-reconcile-pro' ) . '</p></div>';
                echo '<p>';
                printf( esc_html__( '账单日期：%1$s，类型：%2$s。总行数：%3$d，匹配：%4$d，金额不一致：%5$d，未匹配：%6$d。', 'woo-alipay-reconcile-pro' ),
                    esc_html( $last['date'] ?? '' ),
                    esc_html( $last['bill_type'] ?? '' ),
                    intval( $last['total_rows'] ?? 0 ),
                    intval( $last['matched'] ?? 0 ),
                    intval( $last['mismatched'] ?? 0 ),
                    intval( $last['unmatched'] ?? 0 )
                );
                echo '</p>';
                if ( ! empty( $last['file_url'] ) ) {
                    echo '<p>' . sprintf( esc_html__( '下载源文件：%s', 'woo-alipay-reconcile-pro' ), '<a href="' . esc_url( $last['file_url'] ) . '" target="_blank">' . esc_html( basename( $last['file'] ) ) . '</a>' ) . '</p>';
                }
                if ( ! empty( $last['notes'] ) && is_array( $last['notes'] ) ) {
                    echo '<details style="margin:8px 0;"><summary>' . esc_html__( '详细备注', 'woo-alipay-reconcile-pro' ) . '</summary><ul style="margin:8px 16px;">';
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
        echo '<tr><th><label for="woo-ali-bill-date">' . esc_html__( '账单日期', 'woo-alipay-reconcile-pro' ) . '</label></th><td>';
        echo '<input type="date" id="woo-ali-bill-date" name="bill_date" required />';
        echo '</td></tr>';
        echo '<tr><th><label for="woo-ali-bill-type">' . esc_html__( '账单类型', 'woo-alipay-reconcile-pro' ) . '</label></th><td>';
        echo '<select id="woo-ali-bill-type" name="bill_type">';
        echo '<option value="trade">' . esc_html__( '交易账单（trade）', 'woo-alipay-reconcile-pro' ) . '</option>';
        echo '<option value="signcustomer">' . esc_html__( '资金账单（signcustomer）', 'woo-alipay-reconcile-pro' ) . '</option>';
        echo '</select>';
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button( esc_html__( '开始对账', 'woo-alipay-reconcile-pro' ) );
        echo '</form>';

        echo '</div>';
    }

    public function field_enable_schedule() {
        $opt = $this->get_settings();
        echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[enable_schedule]" value="yes" ' . checked( 'yes', $opt['enable_schedule'], false ) . ' /> ' . esc_html__( '启用每日自动对账', 'woo-alipay-reconcile-pro' ) . '</label>';
    }
    public function field_schedule_time() {
        $opt = $this->get_settings();
        echo '<input type="time" name="' . esc_attr( self::OPTION_KEY ) . '[schedule_time]" value="' . esc_attr( $opt['schedule_time'] ) . '" />';
    }
    public function field_timezone() {
        $opt = $this->get_settings();
        echo '<input type="text" name="' . esc_attr( self::OPTION_KEY ) . '[timezone]" value="' . esc_attr( $opt['timezone'] ) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__( '默认使用站点时区，可手动覆盖。示例：Asia/Shanghai', 'woo-alipay-reconcile-pro' ) . '</p>';
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
