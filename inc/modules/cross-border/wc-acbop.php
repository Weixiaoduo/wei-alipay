<?php
/*
 * Plugin Name: Alipay Cross Border Online Payment
 * Plugin URI: http://centil.co
 * Description: Integrate the Chinese Alipay payment gateway with Woocommerce. Alipay is one of the most widely used payment method in China. With the plugin, your website will be able to accept payments from China's customer, using their Alipay account.
 * Version: 1.7.0
 * Author: Centil Technology
 * Author URI: http://centil.co
 * Requires at least: 4.7
 * Tested up to: 5.4.1
 *
 * Text Domain: alipay-cross-border-online-payment
 * Domain Path: /alipay-cross-border-online-payment/
 *
 * WC requires at least: 2.6
 * WC tested up to: 4.1
 */
if (! defined('ABSPATH')) {
    exit;
}

function wc_acbop_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once(dirname(__FILE__) . '/includes/class-wc-acbop.php');

    add_filter('woocommerce_payment_gateways', 'add_wc_acbop_class');
}
add_action('plugins_loaded', 'wc_acbop_init');

function add_wc_acbop_class($methods)
{
    $methods[] = 'WC_Alipay_Cross_Border';
    return $methods;
}
