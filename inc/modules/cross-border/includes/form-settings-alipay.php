<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings for the Gateway.
 */
return array(
    'enabled' => array(
        'title'     => __('Enable/Disable', 'wei-alipay'),
        'type'      => 'checkbox',
        'label'     => __('Enable Alipay Payment', 'wei-alipay'),
        'default'   => 'no'
    ),
    'title' => array(
        'title'       => __('Title', 'wei-alipay'),
        'type'        => 'text',
        'description' => __('This controls the title which the user sees during checkout.', 'wei-alipay'),
        'default'     => __('Alipay', 'wei-alipay'),
    ),
    'description' => array(
        'title'       => __('Customer Message', 'wei-alipay'),
        'type'        => 'textarea',
        'description' => __('This controls the description which the user sees during checkout.', 'wei-alipay'),
        'default'     => __('Pay in Chinese Renminbi/Yuan using your mainland China debit/credit card or Alipay account.', 'wei-alipay'),
    ),
    'partner_id' => array(
        'title'       => __('Alipay Partner ID (PID)', 'wei-alipay'),
        'type'        => 'text',
        'description' => __('Please enter the 16 characters partner ID (PID) assigned by Alipay when you signup your Alipay account.<br />If you don\'t have one, you can signup an Alipay account with Cross Border Online Payment at <a href="https://global.alipay.com/products/online" target="_blank">https://global.alipay.com/products/online</a>.', 'wei-alipay'),
        'css'         => 'width:400px'
    ),
    'secure_key' => array(
        'title'       => __('Alipay Security Code/Key', 'wei-alipay'),
        'type'        => 'text',
        'description' => __('Please enter the 32 characters Security Code(Or Security Key) assigned by Alipay when you opened your Alipay account. <br />This security code is also known as "Security Key for the MD5 signature mechanism"', 'wei-alipay'),
        'css'         => 'width:400px'
    ),

    'account_type' => array(
        'title'       => __( 'Alipay Account Type', 'wei-alipay' ),
        'type'        => 'select',
        'class'       => 'wc-enhanced-select',
        'description' => __( 'Choose the type of Alipay Cross Border Account that you\'ve applied.', 'wei-alipay' ),
        'default'     => 'cross_border',
        'desc_tip'    => true,
        'options'     => array(
            'cross_border'      => __( 'Cross-Border (PC)', 'wei-alipay' ),
            'new_cross_border_pc' => __( 'New Cross-Border (PC)', 'wei-alipay' ),
            'new_cross_border_wap' => __( 'New Cross-Border (WAP)', 'wei-alipay' ),
            'new_cross_border_auto' => __( 'New Cross-Border (AUTO)', 'wei-alipay' ),
            'hk_wallet_pc2mobile' => __( 'HK Wallet (PC2Mobile)', 'wei-alipay' ),
        ),
    ),

    'order_prefix' => array(
        'title'       => __( 'Order No. Prefix', 'wei-alipay' ),
        'type'        => 'text',
        'description' => __( 'eg.WC-. If you <strong>use your Alipay account for multiple stores</strong>, please enter this prefix and make sure it is unique as Alipay will not allow orders with the same merchant order number.', 'wei-alipay'),
        'default'     => 'WC-'
    ),
    'sandbox' => array(
        'title'     => __('Alipay Sandbox', 'wei-alipay'),
        'type'      => 'checkbox',
        'label'     => __('Enable Alipay sandbox', 'wei-alipay'),
        'default'   => 'no',
        'description' => __('By setting Alipay in sandbox mode allows you to make FREE test transaction to check if this plugin and your Alipay account are both setup correctly. Please refer Alipay <a href="https://global.alipay.com/service/sandbox/1" target="_blank">Sandbox Document</a> for more detail.', 'wei-alipay')
    ),
    'debug' => array(
        'title'       => __('Debug Log', 'wei-alipay'),
        'type'        => 'checkbox',
        'label'       => __('Enable logging', 'wei-alipay'),
        'default'     => 'no',
        'description' => __( 'Log Alipay events, such as payment requests, in WooCommerce logs.', 'wei-alipay' ),
    )
);