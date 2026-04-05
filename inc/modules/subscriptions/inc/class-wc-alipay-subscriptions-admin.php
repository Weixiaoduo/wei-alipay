<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Alipay_Subscriptions_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
    }

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Woo Alipay 订阅设置', 'woo-alipay-subscriptions' ),
            __( 'Alipay 订阅', 'woo-alipay-subscriptions' ),
            'manage_woocommerce',
            'woo-alipay-subscriptions',
            array( $this, 'render_settings_page' )
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __( '权限不足', 'woo-alipay-subscriptions' ) );
        }

        $saved = false; $error = '';
        if ( isset( $_POST['woo_alipay_subscriptions_nonce'] ) && wp_verify_nonce( $_POST['woo_alipay_subscriptions_nonce'], 'woo_alipay_subscriptions_settings' ) ) {
            $enable_auto = isset( $_POST['enable_auto_debit'] ) ? 'yes' : 'no';
            $retry_plan  = isset( $_POST['retry_plan'] ) ? sanitize_text_field( wp_unslash( $_POST['retry_plan'] ) ) : '0,1,3,7';
            $email_hint  = isset( $_POST['email_hint'] ) ? wp_kses_post( wp_unslash( $_POST['email_hint'] ) ) : '';
            $product_code = isset( $_POST['product_code'] ) ? sanitize_text_field( wp_unslash( $_POST['product_code'] ) ) : 'GENERAL_WITHHOLDING';
            $sign_mode    = isset( $_POST['sign_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['sign_mode'] ) ) : 'sign_only';

            update_option( 'woo_alipay_subscriptions_enable_auto_debit', $enable_auto );
            update_option( 'woo_alipay_subscriptions_retry_plan', $retry_plan );
            update_option( 'woo_alipay_subscriptions_email_hint', $email_hint );
            update_option( 'woo_alipay_subscriptions_product_code', $product_code );
            update_option( 'woo_alipay_subscriptions_sign_mode', $sign_mode );
            $saved = true;
        }

        $enable_auto = get_option( 'woo_alipay_subscriptions_enable_auto_debit', 'no' );
        $retry_plan  = get_option( 'woo_alipay_subscriptions_retry_plan', '0,1,3,7' );
        $email_hint  = get_option( 'woo_alipay_subscriptions_email_hint', __( '请点击邮件中的“立即支付”按钮，选择“支付宝”完成续费。若已开通自动代扣，本次续费将自动处理。', 'woo-alipay-subscriptions' ) );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Woo Alipay 订阅设置', 'woo-alipay-subscriptions' ) . '</h1>';
        if ( $saved ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( '设置已保存。', 'woo-alipay-subscriptions' ) . '</p></div>';
        }
        echo '<form method="post">';
        wp_nonce_field( 'woo_alipay_subscriptions_settings', 'woo_alipay_subscriptions_nonce' );
        echo '<table class="form-table"><tbody>';

        // Quick action: sign now link
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( '签约快捷入口', 'woo-alipay-subscriptions' ) . '</th>';
        echo '<td>'; 
        $sign_url = add_query_arg( array(), wc_get_endpoint_url( '', '', home_url( '/?wc-api=wc_alipay_subscriptions_sign_start' ) ) );
        echo '<a class="button" href="' . esc_url( $sign_url ) . '" target="_blank">' . esc_html__( '立即发起签约', 'woo-alipay-subscriptions' ) . '</a>';
        echo '<p class="description">' . esc_html__( '用于测试签约流程。生产中建议在结账含订阅时引导用户签约。', 'woo-alipay-subscriptions' ) . '</p>';
        echo '</td>';
        echo '</tr>';

        // Enable auto debit
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( '启用自动代扣（预留）', 'woo-alipay-subscriptions' ) . '</th>';
        echo '<td><label><input type="checkbox" name="enable_auto_debit" value="1" ' . checked( 'yes', $enable_auto, false ) . ' /> ' . esc_html__( '当支付宝侧代扣产品开通后，开启此项以尝试自动续费。', 'woo-alipay-subscriptions' ) . '</label></td>';
        echo '</tr>';

        // Retry plan
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( '失败重试节奏（天）', 'woo-alipay-subscriptions' ) . '</th>';
        echo '<td><input type="text" class="regular-text" name="retry_plan" value="' . esc_attr( $retry_plan ) . '" />';
        echo '<p class="description">' . esc_html__( '以逗号分隔的天数，如 0,1,3,7 表示当天、次日、第3天、第7天重试。', 'woo-alipay-subscriptions' ) . '</p></td>';
        echo '</tr>';

        // Product code
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( '代扣产品代码', 'woo-alipay-subscriptions' ) . '</th>';
        echo '<td>';
        $product_code = get_option( 'woo_alipay_subscriptions_product_code', 'GENERAL_WITHHOLDING' );
        echo '<select name="product_code">';
        $opts = array(
            'GENERAL_WITHHOLDING' => 'GENERAL_WITHHOLDING',
            'CYCLE_PAY_AUTH'      => 'CYCLE_PAY_AUTH'
        );
        foreach ( $opts as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '" ' . selected( $product_code, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( '具体取决于支付宝为你的商户开通的代扣产品。', 'woo-alipay-subscriptions' ) . '</p>';
        echo '</td>';
        echo '</tr>';

        // Sign mode
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( '签约策略', 'woo-alipay-subscriptions' ) . '</th>';
        echo '<td>';
        $sign_mode = get_option( 'woo_alipay_subscriptions_sign_mode', 'sign_only' );
        echo '<select name="sign_mode">';
        $modes = array(
            'sign_only'      => __( '仅签约（试用/首期为0时常用）', 'woo-alipay-subscriptions' ),
            'sign_and_pay'   => __( '首单合并签约与扣款', 'woo-alipay-subscriptions' ),
        );
        foreach ( $modes as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '" ' . selected( $sign_mode, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '</td>';
        echo '</tr>';

        // Agreement URLs (display only)
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( '协议回调地址（复制到支付宝）', 'woo-alipay-subscriptions' ) . '</th>';
        echo '<td>';
        if ( class_exists( 'WC_Alipay_Subscriptions_Signing' ) ) {
            echo '<code>' . esc_html( WC_Alipay_Subscriptions_Signing::agreement_notify_url() ) . '</code>';
        } else {
            echo '<code>-</code>';
        }
        echo '<p class="description">' . esc_html__( '填写到支付宝开放平台的签约/解约通知回调地址。', 'woo-alipay-subscriptions' ) . '</p>';
        echo '</td>';
        echo '</tr>';

        // Email hint
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( '续费邮件提示文案', 'woo-alipay-subscriptions' ) . '</th>';
        echo '<td><textarea name="email_hint" class="large-text" rows="4">' . esc_textarea( $email_hint ) . '</textarea>';
        echo '<p class="description">' . esc_html__( '将插入到“续费账单”邮件顶部，帮助用户使用支付宝完成支付。', 'woo-alipay-subscriptions' ) . '</p></td>';
        echo '</tr>';

        echo '</tbody></table>';
        submit_button();
        echo '</form>';
        echo '</div>';
    }
}

new WC_Alipay_Subscriptions_Admin();
