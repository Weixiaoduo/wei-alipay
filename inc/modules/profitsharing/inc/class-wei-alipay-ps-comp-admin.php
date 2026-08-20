<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Wei_Alipay_PS_Comp_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_post_woo_alipay_ps_compensate', array( $this, 'handle_compensate' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'wei-alipay',
            __( 'Wei Alipay Profit Sharing Compensation', 'wei-alipay' ),
            __( 'Profit Sharing Compensation', 'wei-alipay' ),
            'manage_woocommerce',
            'wei-alipay-ps-compensation',
            array( $this, 'render_page' )
        );
    }

    private function notice_panel() {
        $notice = get_transient( 'woo_alipay_ps_comp_notice' );
        if ( $notice ) {
            delete_transient( 'woo_alipay_ps_comp_notice' );
            if ( ! empty( $notice['error'] ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html( (string) $notice['error'] ) . '</p></div>';
            } elseif ( ! empty( $notice['success'] ) ) {
                echo '<div class="notice notice-success"><p>' . esc_html( (string) $notice['success'] ) . '</p></div>';
            }
        }
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) { return; }
        echo '<div class="wrap wei-alipay-admin">';
        echo '<h1>' . esc_html__( 'Alipay Profit Sharing Compensation', 'wei-alipay' ) . '</h1>';
        echo '<p class="wei-alipay-admin__description">' . esc_html__( 'Create a one-time additional settlement for a specific paid order and receiver.', 'wei-alipay' ) . '</p>';
        $this->notice_panel();
        $receivers = get_option( 'woo_alipay_ps_receivers', array() );

        echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'This action sends a settlement request to Alipay.', 'wei-alipay' ) . '</strong> ' . esc_html__( 'Verify the order, receiver and amount before continuing. The generated request number prevents accidental reuse.', 'wei-alipay' ) . '</p></div>';

        echo '<form class="card wei-alipay-card-wide" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'woo_alipay_ps_compensate' );
        echo '<input type="hidden" name="action" value="woo_alipay_ps_compensate" />';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="order_id">' . esc_html__( 'Order ID', 'wei-alipay' ) . '</label></th><td><input type="number" min="1" name="order_id" id="order_id" required /></td></tr>';
        if ( ! empty( $receivers ) ) {
            echo '<tr><th><label for="receiver_id">' . esc_html__( 'Receiver', 'wei-alipay' ) . '</label></th><td><select name="receiver_id" id="receiver_id">';
            foreach ( $receivers as $rcv ) {
                $rid = (string) ( $rcv['id'] ?? '' );
                $lbl = (string) ( $rcv['label'] ?? $rid );
                echo '<option value="' . esc_attr( $rid ) . '">' . esc_html( $lbl ) . '</option>';
            }
            echo '</select></td></tr>';
        } else {
            echo '<tr><th><label for="receiver_id">' . esc_html__( 'Receiver ID', 'wei-alipay' ) . '</label></th><td><input type="text" name="receiver_id" id="receiver_id" required /></td></tr>';
        }
        echo '<tr><th><label for="identity_type">' . esc_html__( 'Identity Type', 'wei-alipay' ) . '</label></th><td><select name="identity_type" id="identity_type"><option value="ALIPAY_LOGON_ID">ALIPAY_LOGON_ID</option><option value="ALIPAY_USER_ID">ALIPAY_USER_ID</option></select></td></tr>';
        echo '<tr><th><label for="amount">' . esc_html__( 'Amount (CNY)', 'wei-alipay' ) . '</label></th><td><input type="number" step="0.01" min="0.01" name="amount" id="amount" required /></td></tr>';
        echo '<tr><th><label for="desc">' . esc_html__( 'Description', 'wei-alipay' ) . '</label></th><td><input type="text" name="desc" id="desc" class="regular-text" value="Manual Compensation" /></td></tr>';
        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary" data-wei-confirm="' . esc_attr__( 'Send this compensation settlement request to Alipay?', 'wei-alipay' ) . '">' . esc_html__( 'Create Compensation', 'wei-alipay' ) . '</button></p>';
        echo '</form>';

        echo '</div>';
    }

    public function handle_compensate() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'wei-alipay' ) );
        }
        check_admin_referer( 'woo_alipay_ps_compensate' );

        $order_id     = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $receiver_id  = isset( $_POST['receiver_id'] ) ? sanitize_text_field( wp_unslash( $_POST['receiver_id'] ) ) : '';
        $identity_type= isset( $_POST['identity_type'] ) && 'ALIPAY_USER_ID' === $_POST['identity_type'] ? 'ALIPAY_USER_ID' : 'ALIPAY_LOGON_ID';
        $amount       = isset( $_POST['amount'] ) ? (float) $_POST['amount'] : 0;
        $desc         = isset( $_POST['desc'] ) ? sanitize_text_field( wp_unslash( $_POST['desc'] ) ) : 'Manual Compensation';

        $order = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order || $amount <= 0 ) {
            set_transient( 'woo_alipay_ps_comp_notice', array( 'error' => __( 'The order or amount is invalid.', 'wei-alipay' ) ), 5 * MINUTE_IN_SECONDS );
            wp_safe_redirect( admin_url( 'admin.php?page=wei-alipay-ps-compensation' ) );
            exit;
        }

        $receivers = get_option( 'woo_alipay_ps_receivers', array() );
        // If in registry, pull identity and type
        if ( isset( $receivers[ $receiver_id ] ) ) {
            $identity      = (string) ( $receivers[ $receiver_id ]['identity'] ?? '' );
            $identity_type = (string) ( $receivers[ $receiver_id ]['identity_type'] ?? $identity_type );
        } else {
            $identity = $receiver_id; // assume raw identity was provided
        }

        if ( ! class_exists( 'Wei_Alipay_PS_Service' ) ) {
            require_once WEI_ALIPAY_PS_PLUGIN_PATH . 'inc/class-wei-alipay-ps-service.php';
        }

        $jobs = array(
            array(
                'receiver_id'   => (string) $receiver_id,
                'label'         => (string) ( $receivers[ $receiver_id ]['label'] ?? $receiver_id ),
                'identity'      => (string) $identity,
                'identity_type' => (string) $identity_type,
                'amount'        => (float) $amount,
                'desc'          => (string) $desc,
            )
        );

        // Build a unique out_request_no for this manual compensation to ensure idempotency
        $prefix = (string) ( Wei_Alipay_PS_Service::settings()['out_prefix'] ?? 'PS' );
        $manual_out = sprintf( '%s-%s%d-M%s', $prefix, is_multisite() ? get_current_blog_id() . '-' : '', $order_id, gmdate( 'YmdHis' ) );
        $res = Wei_Alipay_PS_Service::settle_order( $order, $jobs, array( 'reason' => 'manual_comp', 'out_request_no' => $manual_out ) );
        if ( ! empty( $res['error'] ) ) {
            set_transient( 'woo_alipay_ps_comp_notice', array( 'error' => $res['error'] ), 5 * MINUTE_IN_SECONDS );
            wp_safe_redirect( admin_url( 'admin.php?page=wei-alipay-ps-compensation' ) );
            exit;
        }

        // Schedule confirmation via AS if available, else rely on existing queue confirm handler
        if ( method_exists( 'Wei_Alipay_PS_Service', 'schedule_as_job' ) && Wei_Alipay_PS_Service::has_as() ) {
            Wei_Alipay_PS_Service::schedule_as_job( $order_id, 'confirm', max( 30, (int) ( Wei_Alipay_PS_Service::settings()['confirm_interval'] ?? 60 ) ) );
        }

        // Append compensation log
        $log = (array) $order->get_meta( '_alipay_ps_comp', true );
        $log[] = array(
            'time' => current_time( 'mysql' ),
            'receiver_id' => (string) $receiver_id,
            'amount' => number_format( (float) $amount, 2, '.', '' ),
            'out_request_no' => (string) $manual_out,
            'user' => get_current_user_id(),
            'desc' => (string) $desc,
        );
        $order->update_meta_data( '_alipay_ps_comp', $log );
        $order->save();

        set_transient( 'woo_alipay_ps_comp_notice', array( 'success' => __( 'The compensation settlement was created and confirmation was scheduled.', 'wei-alipay' ) ), 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=wei-alipay-ps-compensation' ) );
        exit;
    }
}
