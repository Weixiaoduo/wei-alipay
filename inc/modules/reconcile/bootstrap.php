<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WEI_ALIPAY_RECONCILE_PLUGIN_FILE' ) ) {
    define( 'WEI_ALIPAY_RECONCILE_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WEI_ALIPAY_RECONCILE_PLUGIN_PATH' ) ) {
    define( 'WEI_ALIPAY_RECONCILE_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WEI_ALIPAY_RECONCILE_PLUGIN_URL' ) ) {
    define( 'WEI_ALIPAY_RECONCILE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

add_action( 'plugins_loaded', function(){
    if ( class_exists( 'Wei_Alipay' ) && class_exists( 'WooCommerce' ) ) {
        $admin = WEI_ALIPAY_RECONCILE_PLUGIN_PATH . 'inc/class-wei-alipay-reconcile-admin.php';
        if ( file_exists( $admin ) ) {
            require_once $admin;
            new Wei_Alipay_Reconcile_Admin();
        }
        $runner = WEI_ALIPAY_RECONCILE_PLUGIN_PATH . 'inc/class-wei-alipay-reconcile-runner.php';
        if ( file_exists( $runner ) ) {
            require_once $runner;
        }
    }
}, 15 );

// Handle manual reconciliation submission
add_action( 'admin_post_woo_alipay_reconcile_run', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'wei-alipay' ) );
    }
    check_admin_referer( 'woo_alipay_reconcile_run' );

    $date = isset( $_POST['bill_date'] ) ? sanitize_text_field( wp_unslash( $_POST['bill_date'] ) ) : '';
    $type = isset( $_POST['bill_type'] ) ? sanitize_key( wp_unslash( $_POST['bill_type'] ) ) : 'trade';

    if ( empty( $date ) ) {
        set_transient( 'woo_alipay_reconcile_last_result', array( 'error' => __( '请选择日期。', 'wei-alipay' ) ), 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=wei-alipay-reconcile-pro' ) );
        exit;
    }

    if ( ! class_exists( 'Wei_Alipay_Reconcile_Runner' ) ) {
        set_transient( 'woo_alipay_reconcile_last_result', array( 'error' => __( '对账引擎未加载。', 'wei-alipay' ) ), 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=wei-alipay-reconcile-pro' ) );
        exit;
    }

    $result = Wei_Alipay_Reconcile_Runner::run( $date, $type );
    set_transient( 'woo_alipay_reconcile_last_result', $result, 10 * MINUTE_IN_SECONDS );

    wp_safe_redirect( admin_url( 'admin.php?page=wei-alipay-reconcile-pro' ) );
    exit;
} );
