<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WOO_ALIPAY_SUBSCRIPTIONS_FILE' ) ) {
    define( 'WOO_ALIPAY_SUBSCRIPTIONS_FILE', __FILE__ );
}
if ( ! defined( 'WOO_ALIPAY_SUBSCRIPTIONS_PATH' ) ) {
    define( 'WOO_ALIPAY_SUBSCRIPTIONS_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WOO_ALIPAY_SUBSCRIPTIONS_URL' ) ) {
    define( 'WOO_ALIPAY_SUBSCRIPTIONS_URL', plugin_dir_url( __FILE__ ) );
}

add_action( 'init', function() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    if ( ! function_exists( 'wcs_is_subscription' ) && ! class_exists( 'WC_Subscriptions' ) && ! class_exists( 'WC_Subscription' ) ) {
        if ( is_admin() ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-warning"><p>' . esc_html__( 'Woo Alipay Subscriptions 需要 WooCommerce Subscriptions 插件支持。', 'woo-alipay' ) . '</p></div>';
            } );
        }
        return;
    }

    if ( ! class_exists( 'WC_Alipay' ) ) {
        if ( is_admin() ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-warning"><p>' . esc_html__( '请先安装并启用 Woo Alipay 核心插件。', 'woo-alipay' ) . '</p></div>';
            } );
        }
        return;
    }

    $token_class = WOO_ALIPAY_SUBSCRIPTIONS_PATH . 'inc/class-wc-payment-token-alipay-agreement.php';
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
        WOO_ALIPAY_SUBSCRIPTIONS_PATH . 'inc/class-wc-alipay-subscriptions-admin.php',
        WOO_ALIPAY_SUBSCRIPTIONS_PATH . 'inc/class-wc-alipay-subscriptions-emails.php',
        WOO_ALIPAY_SUBSCRIPTIONS_PATH . 'inc/class-wc-alipay-subscriptions-signing.php',
        WOO_ALIPAY_SUBSCRIPTIONS_PATH . 'inc/class-wc-alipay-subscriptions-charge.php',
        WOO_ALIPAY_SUBSCRIPTIONS_PATH . 'inc/class-wc-alipay-subscriptions-checkout.php',
        WOO_ALIPAY_SUBSCRIPTIONS_PATH . 'inc/class-wc-alipay-subscriptions.php',
    );

    foreach ( $files as $file ) {
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}, 20 );
