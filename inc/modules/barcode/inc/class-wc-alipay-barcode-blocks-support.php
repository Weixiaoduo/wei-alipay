<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WC_Alipay_Barcode_Blocks_Support extends AbstractPaymentMethodType {

    protected $name = 'alipay_barcode';
    private $gateway;

    public function initialize() {
        $this->settings = get_option( 'woocommerce_alipay_barcode_settings', array() );
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = isset( $gateways['alipay_barcode'] ) ? $gateways['alipay_barcode'] : false;
    }

    public function is_active() {
        $enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'no';
        return 'yes' === $enabled;
    }

    public function get_payment_method_script_handles() {
        $script_rel = 'js/frontend/blocks-barcode.js';
        $asset_path = WEI_ALIPAY_BARCODE_PLUGIN_PATH . 'js/frontend/blocks-barcode.asset.php';
        $asset      = file_exists( $asset_path ) ? require $asset_path : array(
            'dependencies' => array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-html-entities' ),
            'version'      => '0.1.0',
        );
        $script_url = WEI_ALIPAY_BARCODE_PLUGIN_URL . $script_rel;

        wp_register_script(
            'wc-alipay-barcode-payments-blocks',
            $script_url,
            $asset['dependencies'],
            $asset['version'],
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'wc-alipay-barcode-payments-blocks', 'wei-alipay', WEI_ALIPAY_BARCODE_PLUGIN_PATH . 'languages' );
        }

        return array( 'wc-alipay-barcode-payments-blocks' );
    }

    public function get_payment_method_script_handles_for_admin() {
        return $this->get_payment_method_script_handles();
    }

    public function get_payment_method_data() {
        $icon = WEI_ALIPAY_PLUGIN_URL . 'assets/images/alipay-barcode-icon.svg';
        return array(
            'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : __( '支付宝条码支付', 'wei-alipay' ),
            'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : __( '请输入或出示支付宝付款码完成支付', 'wei-alipay' ),
            'supports'    => $this->get_supported_features(),
            'icon'        => $icon,
        );
    }

    public function get_supported_features() {
        return $this->gateway ? $this->gateway->supports : array( 'products' );
    }
}
