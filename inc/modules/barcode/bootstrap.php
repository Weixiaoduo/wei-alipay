<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Define extension path/url constants
if ( ! defined( 'WOO_ALIPAY_BARCODE_PLUGIN_FILE' ) ) {
    define( 'WOO_ALIPAY_BARCODE_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WOO_ALIPAY_BARCODE_PLUGIN_PATH' ) ) {
    define( 'WOO_ALIPAY_BARCODE_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WOO_ALIPAY_BARCODE_PLUGIN_URL' ) ) {
    define( 'WOO_ALIPAY_BARCODE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Ensure WooCommerce and core Woo Alipay are active before loading classes
add_action( 'plugins_loaded', function () {
    if ( class_exists( 'WC_Payment_Gateway' ) && class_exists( 'Woo_Alipay' ) ) {
        // Load gateway class from this extension
        $gateway_file = WOO_ALIPAY_BARCODE_PLUGIN_PATH . 'inc/class-wc-alipay-barcode.php';
        if ( file_exists( $gateway_file ) ) {
            require_once $gateway_file;
        }
    }
}, 15 );

// Register gateway into WooCommerce
add_filter( 'woocommerce_payment_gateways', function( $methods ) {
    if ( class_exists( 'WC_Alipay_Barcode_Pay' ) ) {
        $methods[] = 'WC_Alipay_Barcode_Pay';
    }
    return $methods;
}, 11, 1 );

// Save auth code from Store API request into order meta for Blocks Checkout compatibility.
add_action( 'woocommerce_store_api_checkout_update_order_from_request', function( $order, $request ) {
    try {
        if ( ! ( $order instanceof WC_Order ) ) { return; }
        // payment_data is an array of { key, value } pairs; normalize to map.
        $payment_data = array();
        if ( ! empty( $request['payment_data'] ) && is_array( $request['payment_data'] ) ) {
            foreach ( $request['payment_data'] as $pair ) {
                if ( isset( $pair['key'], $pair['value'] ) ) {
                    $payment_data[ sanitize_key( $pair['key'] ) ] = wc_clean( $pair['value'] );
                }
            }
        }
        if ( isset( $payment_data['alipay_auth_code'] ) && $payment_data['alipay_auth_code'] ) {
            $order->update_meta_data( '_alipay_auth_code', $payment_data['alipay_auth_code'] );
            $order->save();
        }
    } catch ( Exception $e ) {
        // Silence.
    }
}, 10, 2 );

// Register Blocks support for this payment method
add_action(
    'woocommerce_blocks_payment_method_type_registration',
    function( $registry ) {
        if ( ! class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
            return;
        }

        if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
            return;
        }

        $file = WOO_ALIPAY_BARCODE_PLUGIN_PATH . 'inc/class-wc-alipay-barcode-blocks-support.php';
        if ( file_exists( $file ) ) {
            require_once $file;
        }

        if ( class_exists( 'WC_Alipay_Barcode_Blocks_Support' ) ) {
            $registry->register( new WC_Alipay_Barcode_Blocks_Support() );
        }
    },
    10,
    1
);

// REST response enhancement: add official links for payments providers list (for alipay_barcode)
add_filter( 'rest_post_dispatch', function( $result, $server, $request ) {
    try {
        if ( ! ( $request instanceof WP_REST_Request ) ) { return $result; }
        $route = $request->get_route();
        if ( ! is_string( $route ) || false === strpos( $route, '/wc/v3/payments/providers' ) ) { return $result; }
        if ( ! ( $result instanceof WP_REST_Response ) ) { return $result; }

        $data = $result->get_data();
        if ( ! is_array( $data ) ) { return $result; }

        foreach ( $data as &$provider ) {
            if ( ! is_array( $provider ) || ! isset( $provider['id'] ) ) { continue; }
            if ( 'alipay_barcode' !== $provider['id'] ) { continue; }

            $links = array(
                array(
                    'id'    => 'learn_more',
                    'title' => __( 'Learn more', 'woo-alipay-barcode' ),
                    'href'  => 'https://woocn.com/document/woo-alipay-barcode',
                    'type'  => 'external',
                ),
                array(
                    'id'    => 'view_pricing_and_fees',
                    'title' => __( 'View pricing and fees', 'woo-alipay-barcode' ),
                    'href'  => 'https://woocn.com/pricing/woo-alipay',
                    'type'  => 'external',
                ),
                array(
                    'id'    => 'view_terms_of_service',
                    'title' => __( 'View terms of service', 'woo-alipay-barcode' ),
                    'href'  => 'https://woocn.com/terms',
                    'type'  => 'external',
                ),
                array(
                    'id'    => 'support',
                    'title' => __( 'Support', 'woo-alipay-barcode' ),
                    'href'  => 'https://woocn.com/support',
                    'type'  => 'external',
                ),
            );

            // Merge or override provider meta
            $provider['links'] = isset( $provider['links'] ) && is_array( $provider['links'] )
                ? array_values( array_merge( $provider['links'], $links ) )
                : $links;
        }
        unset( $provider );

        $result->set_data( $data );
        return $result;
    } catch ( Exception $e ) {
        return $result;
    }
}, 10, 3 );
