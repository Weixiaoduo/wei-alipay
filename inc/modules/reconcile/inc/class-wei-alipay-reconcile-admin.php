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
        $view = 'manual';
        $use_shell = class_exists( 'Wei_Alipay_Shell' ) && Wei_Alipay_Shell::is_kit_ready();
        $result_key = self::result_transient_key();
        $last = get_transient( $result_key );
        if ( $last ) {
            delete_transient( $result_key );
        }
        $default_date = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );

        if ( $use_shell ) {
            Wei_Alipay_Shell::open( 'reconcile' );
            echo '<div class="wenpai-page-head"><div><h1 class="wenpai-h1">' . esc_html__( '对账', 'wei-alipay' ) . '</h1><p class="wenpai-lede">' . esc_html__( '按日拉取支付宝账单并与本地订单逐笔比对，不自动修改订单状态。', 'wei-alipay' ) . '</p></div></div><div class="wp-header-end" aria-hidden="true"></div>';

            Wenpai_Admin_UI::card_open( array( 'tile' => false, 'title' => __( '拉取日账单', 'wei-alipay' ) ) );
            echo '<form class="wei-alipay-reconcile-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'woo_alipay_reconcile_run', 'woo_alipay_reconcile_run_nonce' );
            echo '<input type="hidden" name="action" value="woo_alipay_reconcile_run" />';
            echo '<div class="wei-alipay-reconcile-form__field"><label for="woo-ali-bill-date">' . esc_html__( '账单日期', 'wei-alipay' ) . '</label><input type="date" id="woo-ali-bill-date" name="bill_date" value="' . esc_attr( $default_date ) . '" required /></div>';
            echo '<div class="wei-alipay-reconcile-form__field"><label for="woo-ali-bill-type">' . esc_html__( '账单类型', 'wei-alipay' ) . '</label><select id="woo-ali-bill-type" name="bill_type"><option value="trade">' . esc_html__( '业务明细 (trade)', 'wei-alipay' ) . '</option><option value="signcustomer">' . esc_html__( '资金流水 (signcustomer)', 'wei-alipay' ) . '</option></select></div>';
            echo '<button type="submit" class="btn btn-primary" data-wei-confirm="' . esc_attr__( 'Download and reconcile this Alipay statement now?', 'wei-alipay' ) . '">' . esc_html__( '拉取并核对', 'wei-alipay' ) . '</button>';
            echo '</form>';
            Wenpai_Admin_UI::card_close();

            if ( $last ) {
                if ( ! empty( $last['error'] ) ) {
                    Wenpai_Admin_UI::notice( array( 'level' => 'bad', 'text' => (string) $last['error'] ) );
                } else {
                    $summary_line = sprintf(
                        /* translators: %1$s: bill date. %2$s: bill type. %3$d: total row count. %4$d: matched count. %5$d: mismatched count. %6$d: unmatched count. */
                        __( 'Statement date: %1$s; type: %2$s. Rows: %3$d; matched: %4$d; amount mismatches: %5$d; unmatched: %6$d.', 'wei-alipay' ),
                        (string) ( $last['date'] ?? '' ),
                        (string) ( $last['bill_type'] ?? '' ),
                        intval( $last['total_rows'] ?? 0 ),
                        intval( $last['matched'] ?? 0 ),
                        intval( $last['mismatched'] ?? 0 ),
                        intval( $last['unmatched'] ?? 0 )
                    );
                    $has_diff = intval( $last['mismatched'] ?? 0 ) > 0 || intval( $last['unmatched'] ?? 0 ) > 0;
                    Wenpai_Admin_UI::notice( array( 'level' => $has_diff ? 'warn' : 'ok', 'text' => $summary_line ) );
                    Wenpai_Admin_UI::card_open( array( 'tile' => false, 'title' => __( '核对明细', 'wei-alipay' ) ) );
                    $this->render_result_details( $last );
                    Wenpai_Admin_UI::card_close();
                }
            } else {
                Wenpai_Admin_UI::notice(
                    array(
                        'level' => 'info',
                        'text'  => __( '手动对账会向当前配置的支付宝环境请求下载日账单，仅用于核对差异，不会发起扣款、关单或退款。', 'wei-alipay' ),
                    )
                );
                Wenpai_Admin_UI::card_open( array( 'tile' => false, 'title' => __( '对账说明', 'wei-alipay' ) ) );
                echo '<div class="simple-row"><span class="tile">' . Wenpai_Admin_Icons::svg( 'search' ) . '</span><div><div class="t">' . esc_html__( '支持全通道单号前缀识别', 'wei-alipay' ) . '</div><div class="d">' . esc_html__( '覆盖主网关（WooA）、当面付（F2F）、花呗分期（HBFQ）与条码付（WooB），多站点会自动隔离博客号。', 'wei-alipay' ) . '</div></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
                echo '<div class="simple-row"><span class="tile">' . Wenpai_Admin_Icons::svg( 'shield-check' ) . '</span><div><div class="t">' . esc_html__( '账单生成时间与隐私边界', 'wei-alipay' ) . '</div><div class="d">' . esc_html__( '支付宝通常在次日 10:00 后生成前一日账单；核对明细仅对当前管理员保留 10 分钟，不公开账单文件地址。', 'wei-alipay' ) . '</div></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
                Wenpai_Admin_UI::card_close();
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only confirmation flag.
            if ( isset( $_GET['schedule-updated'] ) && '1' === (string) wp_unslash( $_GET['schedule-updated'] ) ) {
                Wenpai_Admin_UI::notice( array( 'level' => 'ok', 'text' => __( '自动日对账计划已保存。', 'wei-alipay' ) ) );
            }

            if ( class_exists( 'Wei_Alipay_Reconcile_Scheduler' ) ) {
                $sched   = Wei_Alipay_Reconcile_Scheduler::get_settings();
                $next_ts = Wei_Alipay_Reconcile_Scheduler::next_run_timestamp();
                $is_on   = 'yes' === $sched['enabled'];
                $pill    = $is_on
                    ? '<span class="pill ok">' . esc_html( sprintf( __( '每日 %s', 'wei-alipay' ), $sched['run_time'] ) ) . '</span>'
                    : '<span class="pill mute">' . esc_html__( '未开启', 'wei-alipay' ) . '</span>';

                Wenpai_Admin_UI::card_open( array( 'tile' => false, 'title' => __( '自动日对账', 'wei-alipay' ) ) );
                echo '<div class="wenpai-folds"><details class="wenpai-fold"' . ( $is_on ? ' open' : '' ) . '>';
                echo '<summary><span class="tile">' . Wenpai_Admin_Icons::svg( 'refresh' ) . '</span><span class="wenpai-fold__text"><span class="wenpai-fold__title">' . esc_html__( '每日自动拉取前一日账单与差异邮件提醒', 'wei-alipay' ) . '</span><span class="wenpai-fold__desc">' . esc_html( $next_ts > 0 ? sprintf( __( '下次执行：%s（优先复用 WooCommerce Action Scheduler）', 'wei-alipay' ), wp_date( 'Y-m-d H:i', $next_ts ) ) : __( '支付宝通常在次日 10:00 后出账，建议将定时设在 10:30 之后。', 'wei-alipay' ) ) . '</span></span><span class="wenpai-fold__side">' . $pill . Wenpai_Admin_Icons::svg( 'chevron-down', 'wenpai-fold__chevron' ) . '</span></summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG + escaped pill.
                echo '<div class="wenpai-fold__body">';
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wei-alipay-reconcile-form">';
                wp_nonce_field( 'wei_alipay_save_reconcile_schedule', 'wei_alipay_reconcile_schedule_nonce' );
                echo '<input type="hidden" name="action" value="wei_alipay_save_reconcile_schedule" />';
                echo '<div class="wei-alipay-reconcile-form__field"><label for="wei-reconcile-sched-on">' . esc_html__( '启用自动日对账', 'wei-alipay' ) . '</label><label class="wenpai-switch"><input id="wei-reconcile-sched-on" type="checkbox" role="switch" class="wenpai-switch__input" name="schedule_enabled" value="yes"' . checked( $is_on, true, false ) . '><span class="wenpai-switch__track" aria-hidden="true"></span><span class="wenpai-switch__state" aria-hidden="true"><span class="wenpai-switch__on">' . esc_html__( '已开启', 'wei-alipay' ) . '</span><span class="wenpai-switch__off">' . esc_html__( '未开启', 'wei-alipay' ) . '</span></span></label></div>';
                echo '<div class="wei-alipay-reconcile-form__field"><label for="wei-reconcile-sched-time">' . esc_html__( '每日执行时间', 'wei-alipay' ) . '</label><input type="time" id="wei-reconcile-sched-time" name="schedule_run_time" value="' . esc_attr( $sched['run_time'] ) . '" required /></div>';
                echo '<div class="wei-alipay-reconcile-form__field"><label for="wei-reconcile-sched-type">' . esc_html__( '账单类型', 'wei-alipay' ) . '</label><select id="wei-reconcile-sched-type" name="schedule_bill_type"><option value="trade"' . selected( $sched['bill_type'], 'trade', false ) . '>' . esc_html__( '业务明细 (trade)', 'wei-alipay' ) . '</option><option value="signcustomer"' . selected( $sched['bill_type'], 'signcustomer', false ) . '>' . esc_html__( '资金流水 (signcustomer)', 'wei-alipay' ) . '</option></select></div>';
                echo '<div class="wei-alipay-reconcile-form__field"><label for="wei-reconcile-sched-email">' . esc_html__( '差异提醒邮箱（留空用站点管理员）', 'wei-alipay' ) . '</label><input type="email" id="wei-reconcile-sched-email" name="schedule_notify_email" value="' . esc_attr( $sched['notify_email'] ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email', '' ) ) . '" /></div>';
                echo '<input type="hidden" name="schedule_notify_diff_only" value="yes" />';
                echo '<button type="submit" class="btn btn-secondary">' . esc_html__( '保存定时计划', 'wei-alipay' ) . '</button>';
                echo '</form></div></details></div>';
                Wenpai_Admin_UI::card_close();

                $history = Wei_Alipay_Reconcile_Scheduler::get_history();
                if ( ! empty( $history ) ) {
                    Wenpai_Admin_UI::card_open( array( 'tile' => false, 'title' => __( '最近自动对账摘要', 'wei-alipay' ) ) );
                    echo '<div class="wei-alipay-table-wrap"><table class="wp-list-table widefat fixed striped"><thead><tr>';
                    echo '<th>' . esc_html__( '账单日期', 'wei-alipay' ) . '</th><th>' . esc_html__( '类型', 'wei-alipay' ) . '</th><th>' . esc_html__( '总行数', 'wei-alipay' ) . '</th><th>' . esc_html__( '匹配', 'wei-alipay' ) . '</th><th>' . esc_html__( '金额差 / 未匹配', 'wei-alipay' ) . '</th><th>' . esc_html__( '结果', 'wei-alipay' ) . '</th>';
                    echo '</tr></thead><tbody>';
                    foreach ( array_slice( $history, 0, 10 ) as $row ) {
                        $st = (string) ( $row['status'] ?? 'ok' );
                        $st_pill = 'ok' === $st
                            ? '<span class="pill ok">' . esc_html__( '无差异', 'wei-alipay' ) . '</span>'
                            : ( 'diff' === $st
                                ? '<span class="pill warn">' . esc_html__( '有差异', 'wei-alipay' ) . '</span>'
                                : '<span class="pill bad">' . esc_html__( '失败', 'wei-alipay' ) . '</span>' );
                        echo '<tr><td>' . esc_html( (string) ( $row['date'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $row['bill_type'] ?? 'trade' ) ) . '</td><td>' . esc_html( (string) (int) ( $row['total_rows'] ?? 0 ) ) . '</td><td>' . esc_html( (string) (int) ( $row['matched'] ?? 0 ) ) . '</td><td>' . esc_html( (int) ( $row['mismatched'] ?? 0 ) . ' / ' . (int) ( $row['unmatched'] ?? 0 ) ) . '</td><td>' . $st_pill . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped pill.
                    }
                    echo '</tbody></table></div>';
                    Wenpai_Admin_UI::card_close();
                }
            }

            Wei_Alipay_Shell::close();
            return;
        }

        echo '<div class="wrap wei-alipay-admin">';
        Wei_Alipay_Admin_UI::page_header(
            __( 'Wei Alipay Reconciliation', 'wei-alipay' ),
            __( 'Download a daily Alipay statement and compare it against local WooCommerce orders.', 'wei-alipay' )
        );
        if ( 'manual' === $view ) {
            echo '<h2>' . esc_html__( 'Manual reconciliation', 'wei-alipay' ) . '</h2>';
            echo '<form class="card wei-alipay-card-wide" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'woo_alipay_reconcile_run', 'woo_alipay_reconcile_run_nonce' );
            echo '<input type="hidden" name="action" value="woo_alipay_reconcile_run" />';
            echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Start reconciliation', 'wei-alipay' ) . '</button></p>';
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
