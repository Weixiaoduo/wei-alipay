<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Alipay_Subscriptions_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
    }

    public function add_menu() {
        add_submenu_page(
            'wei-alipay',
            __( 'Wei Alipay Subscription Settings', 'wei-alipay' ),
            __( 'Subscriptions', 'wei-alipay' ),
            'manage_woocommerce',
            'wei-alipay-subscriptions',
            array( $this, 'render_settings_page' )
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage Alipay subscriptions.', 'wei-alipay' ) );
        }

        $saved = false; $error = '';
        if ( isset( $_POST['woo_alipay_subscriptions_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woo_alipay_subscriptions_nonce'] ) ), 'woo_alipay_subscriptions_settings' ) ) {
            $enable_auto = isset( $_POST['enable_auto_debit'] ) ? 'yes' : 'no';
            $retry_plan  = isset( $_POST['retry_plan'] ) ? preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['retry_plan'] ) ) ) : '0,1,3,7';
            $email_hint  = isset( $_POST['email_hint'] ) ? wp_kses_post( wp_unslash( $_POST['email_hint'] ) ) : '';
            $product_code = isset( $_POST['product_code'] ) ? sanitize_key( wp_unslash( $_POST['product_code'] ) ) : 'general_withholding';
            $product_code = in_array( $product_code, array( 'general_withholding', 'cycle_pay_auth' ), true ) ? strtoupper( $product_code ) : 'GENERAL_WITHHOLDING';
            $sign_mode    = isset( $_POST['sign_mode'] ) ? sanitize_key( wp_unslash( $_POST['sign_mode'] ) ) : 'sign_only';
            $sign_mode    = in_array( $sign_mode, array( 'sign_only', 'sign_and_pay' ), true ) ? $sign_mode : 'sign_only';

            $retry_days = array_map( 'absint', explode( ',', $retry_plan ) );
            if ( ! preg_match( '/^\d{1,3}(?:,\d{1,3})*$/', $retry_plan ) || count( $retry_days ) > 12 || max( $retry_days ) > 365 ) {
                $error = __( 'The retry schedule must contain up to 12 comma-separated day numbers from 0 to 365.', 'wei-alipay' );
            } else {
                update_option( 'woo_alipay_subscriptions_enable_auto_debit', $enable_auto );
                update_option( 'woo_alipay_subscriptions_retry_plan', implode( ',', array_values( array_unique( $retry_days ) ) ) );
                update_option( 'woo_alipay_subscriptions_email_hint', $email_hint );
                update_option( 'woo_alipay_subscriptions_product_code', $product_code );
                update_option( 'woo_alipay_subscriptions_sign_mode', $sign_mode );
                $saved = true;
            }
        }

        $enable_auto = get_option( 'woo_alipay_subscriptions_enable_auto_debit', 'no' );
        $retry_plan  = get_option( 'woo_alipay_subscriptions_retry_plan', '0,1,3,7' );
        $email_hint  = get_option( 'woo_alipay_subscriptions_email_hint', __( 'Select Alipay from the payment link in this email to complete the renewal. If automatic debit is active, the renewal will be processed automatically.', 'wei-alipay' ) );

        $core_settings = get_option( 'woocommerce_alipay_settings', array() );
        $is_sandbox    = 'yes' === ( $core_settings['sandbox'] ?? 'no' );

        echo '<div class="wrap wei-alipay-admin">';
        Wei_Alipay_Admin_UI::page_header(
            __( 'Alipay Subscriptions', 'wei-alipay' ),
            __( 'Configure agreement payments and renewal behavior. Credentials and provider environment are inherited from the main Wei Alipay gateway.', 'wei-alipay' )
        );
        if ( $saved ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wei-alipay' ) . '</p></div>';
        }
        if ( $error ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
        }
        echo '<form class="card wei-alipay-card-wide" method="post" data-wei-dirty>';
        wp_nonce_field( 'woo_alipay_subscriptions_settings', 'woo_alipay_subscriptions_nonce' );
        echo '<table class="form-table"><tbody>';

        if ( $is_sandbox ) {
            echo '<tr>';
            echo '<th scope="row">' . esc_html__( 'Sandbox signing', 'wei-alipay' ) . '</th>';
            echo '<td>';
            $sign_url = wp_nonce_url(
                wc_get_endpoint_url( '', '', home_url( '/?wc-api=wc_alipay_subscriptions_sign_start' ) ),
                'woo_alipay_subscription_sign_' . get_current_user_id() . '_0'
            );
            echo '<a class="button" href="' . esc_url( $sign_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Start sandbox signing', 'wei-alipay' ) . '</a>';
            echo '<p class="description">' . esc_html__( 'Available only while the main gateway is in sandbox mode. Production customers sign during subscription checkout.', 'wei-alipay' ) . '</p>';
            echo '</td>';
            echo '</tr>';
        } else {
            echo '<tr><th scope="row">' . esc_html__( 'Signing flow', 'wei-alipay' ) . '</th><td><div class="notice notice-info inline"><p>' . esc_html__( 'The administrator signing shortcut is hidden in production mode. Customers start signing during subscription checkout.', 'wei-alipay' ) . '</p></div></td></tr>';
        }

        // Enable auto debit
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Enable automatic debit', 'wei-alipay' ) . '</th>';
        echo '<td><label><input type="checkbox" name="enable_auto_debit" value="1" ' . checked( 'yes', $enable_auto, false ) . ' /> ' . esc_html__( 'Enable automatic renewals only after Alipay has activated an agreement debit product for the merchant.', 'wei-alipay' ) . '</label></td>';
        echo '</tr>';

        // Retry plan
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Retry schedule (days)', 'wei-alipay' ) . '</th>';
        echo '<td><input type="text" class="regular-text" name="retry_plan" value="' . esc_attr( $retry_plan ) . '" />';
        echo '<p class="description">' . esc_html__( 'Comma-separated days, for example 0,1,3,7 for the same day and days 1, 3 and 7.', 'wei-alipay' ) . '</p></td>';
        echo '</tr>';

        // Product code
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Agreement product code', 'wei-alipay' ) . '</th>';
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
        echo '<p class="description">' . esc_html__( 'Use the product code enabled for the merchant by Alipay.', 'wei-alipay' ) . '</p>';
        echo '</td>';
        echo '</tr>';

        // Sign mode
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Signing strategy', 'wei-alipay' ) . '</th>';
        echo '<td>';
        $sign_mode = get_option( 'woo_alipay_subscriptions_sign_mode', 'sign_only' );
        echo '<select name="sign_mode">';
        $modes = array(
            'sign_only'      => __( 'Sign only (commonly used for trials or a zero first payment)', 'wei-alipay' ),
            'sign_and_pay'   => __( 'Sign and debit the first payment', 'wei-alipay' ),
        );
        foreach ( $modes as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '" ' . selected( $sign_mode, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '</td>';
        echo '</tr>';

        // Agreement URLs (display only)
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Agreement callback URL', 'wei-alipay' ) . '</th>';
        echo '<td>';
        if ( class_exists( 'WC_Alipay_Subscriptions_Signing' ) ) {
            echo '<code>' . esc_html( WC_Alipay_Subscriptions_Signing::agreement_notify_url() ) . '</code>';
        } else {
            echo '<code>-</code>';
        }
        echo '<p class="description">' . esc_html__( 'Enter this as the signing and cancellation notification URL in Alipay Open Platform.', 'wei-alipay' ) . '</p>';
        echo '</td>';
        echo '</tr>';

        // Email hint
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Renewal email guidance', 'wei-alipay' ) . '</th>';
        echo '<td><textarea name="email_hint" class="large-text" rows="4">' . esc_textarea( $email_hint ) . '</textarea>';
        echo '<p class="description">' . esc_html__( 'Inserted near the top of renewal invoice emails to explain how to pay with Alipay.', 'wei-alipay' ) . '</p></td>';
        echo '</tr>';

        echo '</tbody></table>';
        submit_button();
        echo '<span data-wei-dirty-message aria-live="polite"></span>';
        echo '</form>';
        echo '</div>';
    }
}

new WC_Alipay_Subscriptions_Admin();
