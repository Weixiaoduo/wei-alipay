<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WEI_ALIPAY_SUBSCRIPTIONS_FILE' ) ) {
    define( 'WEI_ALIPAY_SUBSCRIPTIONS_FILE', __FILE__ );
}
if ( ! defined( 'WEI_ALIPAY_SUBSCRIPTIONS_PATH' ) ) {
    define( 'WEI_ALIPAY_SUBSCRIPTIONS_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WEI_ALIPAY_SUBSCRIPTIONS_URL' ) ) {
    define( 'WEI_ALIPAY_SUBSCRIPTIONS_URL', plugin_dir_url( __FILE__ ) );
}

add_action( 'init', function() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    if ( ! function_exists( 'wcs_is_subscription' ) && ! class_exists( 'WC_Subscriptions' ) && ! class_exists( 'WC_Subscription' ) ) {
        if ( is_admin() ) {
            add_action( 'admin_notices', function() {
                $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
                $screen_id = $screen ? (string) $screen->id : '';
                if ( 'toplevel_page_wei-alipay' !== $screen_id && 0 !== strpos( $screen_id, 'wei-alipay_page_' ) && 'woocommerce_page_wc-settings' !== $screen_id ) {
                    return;
                }
                echo '<div class="notice notice-warning"><p>' . esc_html__( 'Wei Alipay subscriptions require the WooCommerce Subscriptions extension.', 'wei-alipay' ) . '</p></div>';
            } );
        }
        return;
    }

    if ( ! class_exists( 'WC_Alipay' ) ) {
        if ( is_admin() ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-warning"><p>' . esc_html__( 'Install and activate the main Wei Alipay plugin first.', 'wei-alipay' ) . '</p></div>';
            } );
        }
        return;
    }

    $token_class = WEI_ALIPAY_SUBSCRIPTIONS_PATH . 'inc/class-wc-payment-token-alipay-agreement.php';
    if ( file_exists( $token_class ) ) {
        require_once $token_class;
    }

    add_filter( 'woocommerce_payment_token_class', function( $class, $type ) {
        if ( 'alipay_agreement' === $type ) {
            return 'WC_Payment_Token_Alipay_Agreement';
        }
        return $class;
    }, 10, 2 );

    $files = array(
        WEI_ALIPAY_SUBSCRIPTIONS_PATH . 'inc/class-wc-alipay-subscriptions-admin.php',
        WEI_ALIPAY_SUBSCRIPTIONS_PATH . 'inc/class-wc-alipay-subscriptions-emails.php',
        WEI_ALIPAY_SUBSCRIPTIONS_PATH . 'inc/class-wc-alipay-subscriptions-signing.php',
        WEI_ALIPAY_SUBSCRIPTIONS_PATH . 'inc/class-wc-alipay-subscriptions-charge.php',
        WEI_ALIPAY_SUBSCRIPTIONS_PATH . 'inc/class-wc-alipay-subscriptions-checkout.php',
        WEI_ALIPAY_SUBSCRIPTIONS_PATH . 'inc/class-wc-alipay-subscriptions.php',
    );

    foreach ( $files as $file ) {
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}, 20 );
