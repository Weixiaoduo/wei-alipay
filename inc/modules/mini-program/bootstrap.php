<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WOO_ALIPAY_MINIPROGRAM_FILE' ) ) {
    define( 'WOO_ALIPAY_MINIPROGRAM_FILE', __FILE__ );
}
if ( ! defined( 'WOO_ALIPAY_MINIPROGRAM_PATH' ) ) {
    define( 'WOO_ALIPAY_MINIPROGRAM_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WOO_ALIPAY_MINIPROGRAM_URL' ) ) {
    define( 'WOO_ALIPAY_MINIPROGRAM_URL', plugin_dir_url( __FILE__ ) );
}

add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    $class_file = WOO_ALIPAY_MINIPROGRAM_PATH . 'class-wc-alipay-wap.php';
    if ( file_exists( $class_file ) ) {
        require_once $class_file;
    }

    add_filter( 'woocommerce_payment_gateways', function( $methods ) {
        if ( class_exists( 'WC_Alipay_Wap' ) ) {
            $methods[] = 'WC_Alipay_Wap';
        }
        return $methods;
    } );
}, 20 );

add_filter( 'woocommerce_get_order_item_totals', function( $total_rows, $order ) {
    if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
        return $total_rows;
    }

    $trade_no = (string) $order->get_meta( 'Alipay Trade No.', true );
    if ( '' === $trade_no ) {
        return $total_rows;
    }

    $new_row = array(
        'alipay_trade_no' => array(
            'label' => __( 'Alipay Trade No.:', 'alipay' ),
            'value' => $trade_no,
        ),
    );

    return array_merge( array_splice( $total_rows, 0, 2 ), $new_row, $total_rows );
}, 10, 2 );
