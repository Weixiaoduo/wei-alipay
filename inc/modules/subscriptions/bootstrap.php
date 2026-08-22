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
        // The feature centre renders the dependency beside the disabled control.
        // A global admin notice duplicates that state and obscures unrelated pages.
        return;
    }

    if ( ! class_exists( 'WC_Alipay' ) ) {
        // The bundled module never owns a global notice. Its parent plugin or
        // feature screen is responsible for presenting dependency state.
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
