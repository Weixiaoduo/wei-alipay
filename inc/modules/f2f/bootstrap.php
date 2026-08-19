<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Define extension path/url constants
if ( ! defined( 'WEI_ALIPAY_F2F_PLUGIN_FILE' ) ) {
    define( 'WEI_ALIPAY_F2F_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WEI_ALIPAY_F2F_PLUGIN_PATH' ) ) {
    define( 'WEI_ALIPAY_F2F_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WEI_ALIPAY_F2F_PLUGIN_URL' ) ) {
    define( 'WEI_ALIPAY_F2F_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Ensure WooCommerce and core Wei Alipay are active before loading classes
add_action( 'plugins_loaded', function () {
    if ( class_exists( 'WC_Payment_Gateway' ) && class_exists( 'Wei_Alipay' ) ) {
        // Load gateway class from this extension
        $gateway_file = WEI_ALIPAY_F2F_PLUGIN_PATH . 'inc/class-wc-alipay-facetopay.php';
        if ( file_exists( $gateway_file ) ) {
            require_once $gateway_file;
        }
    }
}, 15 );

// Register gateway into WooCommerce (core will no longer add extension gateways)
add_filter( 'woocommerce_payment_gateways', function( $methods ) {
    if ( class_exists( 'WC_Alipay_FaceToPay' ) ) {
        $methods[] = 'WC_Alipay_FaceToPay';
    }
    return $methods;
}, 11, 1 );

// Register Blocks support from this extension
add_action(
    'woocommerce_blocks_payment_method_type_registration',
    function( $registry ) {
        if ( ! class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
            return;
        }

        if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
            return;
        }

        $file = WEI_ALIPAY_F2F_PLUGIN_PATH . 'inc/class-wc-alipay-facetopay-blocks-support.php';
        if ( file_exists( $file ) ) {
            require_once $file;
        }

        if ( class_exists( 'WC_Alipay_FaceToPay_Blocks_Support' ) ) {
            $registry->register( new WC_Alipay_FaceToPay_Blocks_Support() );
        }
    },
    10,
    1
);
