<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WOO_ALIPAY_CROSS_BORDER_FILE' ) ) {
    define( 'WOO_ALIPAY_CROSS_BORDER_FILE', __FILE__ );
}
if ( ! defined( 'WOO_ALIPAY_CROSS_BORDER_PATH' ) ) {
    define( 'WOO_ALIPAY_CROSS_BORDER_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WOO_ALIPAY_CROSS_BORDER_URL' ) ) {
    define( 'WOO_ALIPAY_CROSS_BORDER_URL', plugin_dir_url( __FILE__ ) );
}

add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    $gateway_file = WOO_ALIPAY_CROSS_BORDER_PATH . 'includes/class-wc-acbop.php';
    if ( file_exists( $gateway_file ) ) {
        require_once $gateway_file;
    }

    add_filter( 'woocommerce_payment_gateways', function( $methods ) {
        if ( class_exists( 'WC_Alipay_Cross_Border' ) ) {
            $methods[] = 'WC_Alipay_Cross_Border';
        }
        return $methods;
    } );
}, 20 );
