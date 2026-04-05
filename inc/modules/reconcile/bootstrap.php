<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WOO_ALIPAY_RECONCILE_PLUGIN_FILE' ) ) {
    define( 'WOO_ALIPAY_RECONCILE_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WOO_ALIPAY_RECONCILE_PLUGIN_PATH' ) ) {
    define( 'WOO_ALIPAY_RECONCILE_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WOO_ALIPAY_RECONCILE_PLUGIN_URL' ) ) {
    define( 'WOO_ALIPAY_RECONCILE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

add_action( 'plugins_loaded', function(){
    if ( class_exists( 'Woo_Alipay' ) && class_exists( 'WooCommerce' ) ) {
        $admin = WOO_ALIPAY_RECONCILE_PLUGIN_PATH . 'inc/class-woo-alipay-reconcile-admin.php';
        if ( file_exists( $admin ) ) {
            require_once $admin;
            new Woo_Alipay_Reconcile_Admin();
        }
        $runner = WOO_ALIPAY_RECONCILE_PLUGIN_PATH . 'inc/class-woo-alipay-reconcile-runner.php';
        if ( file_exists( $runner ) ) {
            require_once $runner;
        }
    }
}, 15 );

// Handle manual reconciliation submission
add_action( 'admin_post_woo_alipay_reconcile_run', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-reconcile-pro' ) );
    }
    check_admin_referer( 'woo_alipay_reconcile_run' );

    $date = isset( $_POST['bill_date'] ) ? sanitize_text_field( wp_unslash( $_POST['bill_date'] ) ) : '';
    $type = isset( $_POST['bill_type'] ) ? sanitize_key( wp_unslash( $_POST['bill_type'] ) ) : 'trade';

    if ( empty( $date ) ) {
        set_transient( 'woo_alipay_reconcile_last_result', array( 'error' => __( '请选择日期。', 'woo-alipay-reconcile-pro' ) ), 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-reconcile-pro' ) );
        exit;
    }

    if ( ! class_exists( 'Woo_Alipay_Reconcile_Runner' ) ) {
        set_transient( 'woo_alipay_reconcile_last_result', array( 'error' => __( '对账引擎未加载。', 'woo-alipay-reconcile-pro' ) ), 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-reconcile-pro' ) );
        exit;
    }

    $result = Woo_Alipay_Reconcile_Runner::run( $date, $type );
    set_transient( 'woo_alipay_reconcile_last_result', $result, 10 * MINUTE_IN_SECONDS );

    wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-reconcile-pro' ) );
    exit;
} );
