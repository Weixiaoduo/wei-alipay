<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Define extension path/url constants
if ( ! defined( 'WEI_ALIPAY_HUABEI_PLUGIN_FILE' ) ) {
    define( 'WEI_ALIPAY_HUABEI_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WEI_ALIPAY_HUABEI_PLUGIN_PATH' ) ) {
    define( 'WEI_ALIPAY_HUABEI_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WEI_ALIPAY_HUABEI_PLUGIN_URL' ) ) {
    define( 'WEI_ALIPAY_HUABEI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Ensure WooCommerce and core Wei Alipay are active before loading classes
add_action( 'plugins_loaded', function () {
    if ( class_exists( 'WC_Payment_Gateway' ) && class_exists( 'Wei_Alipay' ) ) {
        // Load gateway class from this extension
        $gateway_file = WEI_ALIPAY_HUABEI_PLUGIN_PATH . 'inc/class-wc-alipay-installment.php';
        if ( file_exists( $gateway_file ) ) {
            require_once $gateway_file;
        }
    }
}, 15 );

// Register gateway into WooCommerce (core will no longer add extension gateways)
add_filter( 'woocommerce_payment_gateways', function( $methods ) {
    if ( class_exists( 'WC_Alipay_Installment' ) ) {
        $methods[] = 'WC_Alipay_Installment';
    }
    return $methods;
}, 11, 1 );

// Save selected installment period from Store API payment_data for Blocks Checkout compatibility.
add_action( 'woocommerce_store_api_checkout_update_order_from_request', function( $order, $request ) {
    try {
        if ( ! ( $order instanceof WC_Order ) ) {
            return;
        }
        $payment_data = array();
        if ( ! empty( $request['payment_data'] ) && is_array( $request['payment_data'] ) ) {
            foreach ( $request['payment_data'] as $pair ) {
                if ( isset( $pair['key'], $pair['value'] ) ) {
                    $payment_data[ sanitize_key( $pair['key'] ) ] = wc_clean( $pair['value'] );
                }
            }
        }
        if ( isset( $payment_data['alipay_installment_period'] ) && in_array( (string) $payment_data['alipay_installment_period'], array( '3', '6', '12' ), true ) ) {
            $order->update_meta_data( '_alipay_installment_period', (string) $payment_data['alipay_installment_period'] );
            $order->save();
        }
    } catch ( Exception $e ) {
        // Silence.
    }
}, 10, 2 );

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

        $file = WEI_ALIPAY_HUABEI_PLUGIN_PATH . 'inc/class-wc-alipay-installment-blocks-support.php';
        if ( file_exists( $file ) ) {
            require_once $file;
        }

        if ( class_exists( 'WC_Alipay_Installment_Blocks_Support' ) ) {
            $registry->register( new WC_Alipay_Installment_Blocks_Support() );
        }
    },
    10,
    1
);
