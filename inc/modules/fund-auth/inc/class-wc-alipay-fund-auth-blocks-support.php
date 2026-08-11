<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WC_Alipay_Fund_Auth_Blocks_Support extends AbstractPaymentMethodType {

    protected $name = 'alipay_fund_auth';
    private $gateway;

    public function initialize() {
        $this->settings = get_option( 'woocommerce_alipay_fund_auth_settings', array() );
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = isset( $gateways['alipay_fund_auth'] ) ? $gateways['alipay_fund_auth'] : false;
    }

    public function is_active() {
        $enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'no';
        return 'yes' === $enabled;
    }

    public function get_payment_method_script_handles() {
        $asset_path = WOO_ALIPAY_FUNDAUTH_PLUGIN_PATH . 'js/frontend/blocks-fund-auth.asset.php';
        $asset      = file_exists( $asset_path ) ? require $asset_path : array(
            'dependencies' => array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-html-entities' ),
            'version'      => '0.1.0',
        );
        $script_url = WOO_ALIPAY_FUNDAUTH_PLUGIN_URL . 'js/frontend/blocks-fund-auth.js';

        wp_register_script(
            'wc-alipay-fund-auth-blocks',
            $script_url,
            $asset['dependencies'],
            $asset['version'],
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'wc-alipay-fund-auth-blocks', 'woo-alipay', WOO_ALIPAY_FUNDAUTH_PLUGIN_PATH . 'languages' );
        }

        return array( 'wc-alipay-fund-auth-blocks' );
    }

    public function get_payment_method_script_handles_for_admin() {
        return $this->get_payment_method_script_handles();
    }

    public function get_payment_method_data() {
        $icon = WOO_ALIPAY_PLUGIN_URL . 'assets/images/alipay-icon.svg';
        return array(
            'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'Alipay Fund Authorization', 'woo-alipay' ),
            'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : __( 'Your payment will be authorized (frozen) now and captured upon fulfillment.', 'woo-alipay' ),
            'supports'    => $this->get_supported_features(),
            'icon'        => $icon,
        );
    }

    public function get_supported_features() {
        return $this->gateway ? $this->gateway->supports : array( 'products' );
    }
}
