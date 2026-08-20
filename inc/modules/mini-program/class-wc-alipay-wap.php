<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Alipay Payment Gateway
 *
 * Provides an Alipay Payment Gateway.
 *
 * @class       Wei_Alipay_Mini_Program_Gateway
 * @extends     WC_Payment_Gateway
 * @version     1.3.3
 */

class Wei_Alipay_Mini_Program_Gateway extends WC_Payment_Gateway {

    var $current_currency;
    var $multi_currency_enabled;
    var $supported_currencies;
    var $lib_path;
    var $charset;
    var $notify_url;
    var $alipay_account;
    var $partnerID;
    var $secure_key;
    var $debug;
    var $form_submission_method;
    var $order_title_format;
    var $exchange_rate;
    var $order_prefix;
    var $log;
    var $protocol_mode;
    var $legacy_md5_ack;

    /**
     * Core settings fields required by SDK flow.
     *
     * @var array
     */
    protected $required_core_fields = array( 'appid', 'private_key', 'public_key' );

    /**
     * Constructor for the gateway.
     *
     * @access public
     * @return void
     */
    public function __construct() {

        // WPML + Multi Currency related settings
        $this->current_currency       = get_option('woocommerce_currency');
        $this->multi_currency_enabled = in_array( 'woocommerce-multilingual/wpml-woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) && get_option( 'icl_enable_multi_currency' ) == 'yes';
        $this->supported_currencies   = array( 'RMB', 'CNY' );
        $this->lib_path               = plugin_dir_path( __FILE__ ) . 'lib';

        $this->charset                = strtolower( get_bloginfo( 'charset' ) );
        if( !in_array( $this->charset, array( 'gbk', 'utf-8') ) ) {
            $this->charset = 'utf-8';
        }

        // WooCommerce required settings
        $this->id                     = 'alipay_wap';
        $this->icon                   = apply_filters( 'woocommerce_alipay_icon', plugins_url( 'images/alipay.png', __FILE__ ) );
        $this->has_fields             = false;
        $this->method_title           = __( 'Alipay', 'wei-alipay' );
        $this->order_button_text      = __( 'Proceed to Alipay', 'wei-alipay' );
        $this->notify_url             = WC()->api_request_url( 'Wei_Alipay_Mini_Program_Gateway' );

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title                  = $this->get_option( 'title' );
        $this->description            = $this->get_option( 'description' );
        $this->alipay_account         = $this->get_option( 'alipay_account' );
        $this->partnerID              = $this->get_option( 'partnerID' );
        $this->secure_key             = $this->get_option( 'secure_key' );
        $this->protocol_mode          = $this->get_option( 'protocol_mode', 'rsa2' );
        $this->legacy_md5_ack         = $this->get_option( 'legacy_md5_ack', 'no' );
        //$this->payment_method         = $this->get_option( 'payment_method' );
        $this->debug                  = $this->get_option( 'debug' );
        $this->form_submission_method = $this->get_option( 'form_submission_method' ) == 'yes' ? true : false;
        $this->order_title_format     = $this->get_option( 'order_title_format' );
        $this->exchange_rate          = $this->get_option( 'exchange_rate' );
        $this->order_prefix           = $this->get_option( 'order_prefix' );
        
        // Logs
        if ( 'yes' == $this->debug ) {
            $this->log = new WC_Logger();
        }

        // Actions
        add_action( 'admin_notices', array( $this, 'requirement_checks' ) );
        add_action( 'woocommerce_update_options_payment_gateways_wap', array( $this, 'process_admin_options' ) ); // WC <= 1.6.6
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) ); // WC >= 2.0
        add_action( 'woocommerce_thankyou_alipay_wap', array( $this, 'thankyou_page' ) );
        add_action( 'woocommerce_receipt_alipay_wap', array( $this, 'receipt_page' ) );

        // Payment listener/API hook
        add_action( 'woocommerce_api_woo_alipay_mini_program_gateway', array( $this, 'check_alipay_response' ) );

        // Display Alipay Trade No. in the backend.
        add_action( 'woocommerce_admin_order_data_after_billing_address',array( $this, 'wc_alipay_wap_display_order_meta_for_admin' ) );
    }

    /**
     * Check if this gateway is enabled and available for the selected main currency
     *
     * @access public
     * @return bool
     */
    function is_available() {

        $is_available = ( 'yes' === $this->enabled ) ? true : false;

        if ( $is_available ) {
            $protocol = $this->selected_protocol();
            if ( is_wp_error( $protocol ) ) {
                $is_available = false;
            } elseif ( Wei_Alipay_Mini_Protocol::RSA2 === $protocol && ! $this->has_core_credentials() ) {
                $is_available = false;
            } elseif ( Wei_Alipay_Mini_Protocol::LEGACY_MD5 === $protocol && ( empty( $this->partnerID ) || empty( $this->secure_key ) || empty( $this->alipay_account ) ) ) {
                $is_available = false;
            }
        }

        if ($this->multi_currency_enabled) {
            if ( !in_array( get_woocommerce_currency(), array( 'RMB', 'CNY') ) && !$this->exchange_rate) {
                $is_available = false;
            }
        } else if ( !in_array( $this->current_currency, array( 'RMB', 'CNY') ) && !$this->exchange_rate) {
            $is_available = false;
        }

        return $is_available;
    }

    /**
     * Check if requirements are met and display notices
     *
     * @access public
     * @return void
     */
    function requirement_checks() { 
        if ( 'yes' !== $this->enabled ) {
            return;
        }

        if ( !in_array( $this->current_currency, array( 'RMB', 'CNY') ) && !$this->exchange_rate ) {
            /* translators: %1$s: link to the exchange rate settings page. %2$s: store currency code. */
            echo '<div class="error"><p>' . wp_kses_post( sprintf( __('Alipay is enabled, but the store currency is not set to Chinese Yuan. Please <a href="%1$s">set the %2$s against the Chinese Yuan exchange rate</a>.', 'wei-alipay' ), esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_alipay_wap#woocommerce_alipay_exchange_rate' ) ), esc_html( $this->current_currency ) ) ) . '</p></div>';
        }

        $protocol = $this->selected_protocol();
        $missing_fields = $this->get_missing_core_fields();
        if ( Wei_Alipay_Mini_Protocol::RSA2 === $protocol && ! empty( $missing_fields ) ) {
            /* translators: %s: comma-separated list of missing configuration field names */
            echo '<div class="error"><p>' . esc_html( sprintf( __( 'Alipay core configuration is incomplete. Missing fields: %s', 'wei-alipay' ), implode( ', ', $missing_fields ) ) ) . '</p></div>';
        }
        if ( is_wp_error( $protocol ) ) {
            echo '<div class="error"><p>' . esc_html( $protocol->get_error_message() ) . '</p></div>';
        }
    }

    /**
     * Get normalized core settings from main gateway.
     *
     * @return array
     */
    protected function get_core_settings() {
        return wp_parse_args(
            get_option( 'woocommerce_alipay_settings', array() ),
            array(
                'appid'       => '',
                'private_key' => '',
                'public_key'  => '',
                'seller_id'   => '',
                'sandbox'     => 'no',
            )
        );
    }

    /**
     * Return missing required core fields.
     *
     * @return array
     */
    protected function get_missing_core_fields() {
        $settings = $this->get_core_settings();
        $missing = array();

        foreach ( $this->required_core_fields as $field ) {
            if ( empty( $settings[ $field ] ) ) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Check whether SDK-required credentials are complete.
     *
     * @return bool
     */
    protected function has_core_credentials() {
        return empty( $this->get_missing_core_fields() );
    }

    protected function selected_protocol() {
        return Wei_Alipay_Mini_Protocol::resolve(
            $this->protocol_mode,
            'yes' === $this->legacy_md5_ack,
            Wei_Alipay_Mini_Protocol::legacy_allowed()
        );
    }

    /**
     * Write a structured log line for payment lifecycle events.
     *
     * @param string $event
     * @param array  $context
     * @return void
     */
    protected function log_event( $event, $context = array() ) {
        if ( 'yes' !== $this->debug || ! $this->log ) {
            return;
        }

        $pairs = array();
        foreach ( $context as $key => $value ) {
            if ( '' === (string) $value ) {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }

        $message = $event;
        if ( ! empty( $pairs ) ) {
            $message .= ' [' . implode( ', ', $pairs ) . ']';
        }

        $this->log->add( 'alipaywap', $message );
    }

    /**
     * Admin Panel Options
     * - Options for bits like 'title' and account etc.
     *
     * @access public
     * @return void
     */
    public function admin_options() {

        ?>
        <h3><?php esc_html_e('Alipay', 'wei-alipay'); ?></h3>
        <p><?php esc_html_e('Alipay is a simple, secure and fast online payment method, customer can pay via debit card, credit card or alipay balance.', 'wei-alipay'); ?></p>
       
        <table class="form-table">
            <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html();
            ?>
        </table><!--/.form-table-->
        <?php
    }
    
    /**
     * Initialise Gateway Settings Form Fields
     *
     * @access public
     * @return void
     */
    function init_form_fields() {

        $this->form_fields = array(
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
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'wei-alipay'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'wei-alipay'),
                'default'     => __('Pay via Alipay, if you don\'t have an Alipay account, you can also pay with your debit card or credit card', 'wei-alipay'),
                'desc_tip'    => true,
            ),
//            'payment_method' => array(
//                'title'       => __('Alipay Payment Gateway Type', 'wei-alipay'),
//                'type'        => 'select',
//                'description' => __('Please choose a payment method, note that the Dual requires a corporate account', 'wei-alipay'),
//                'options'     => array(
//                    'escrow'  => __('Escrow Payment', 'wei-alipay'),
//                    'dualfun' => __('Dual(Direct Payment + Escrow payment)', 'wei-alipay'),
//                    'direct'  => __('Direct Payment', 'wei-alipay')
//                ),
//                'desc_tip'    => true,
//            ),
            'protocol_mode' => array(
                'title'       => __( 'Signing protocol', 'wei-alipay' ),
                'type'        => 'select',
                'default'     => 'rsa2',
                'options'     => array(
                    'rsa2'       => __( 'RSA2 (recommended)', 'wei-alipay' ),
                    'legacy_md5' => __( 'Legacy MD5 (migration only)', 'wei-alipay' ),
                ),
                'description' => __( 'RSA2 never falls back to MD5. Legacy MD5 also requires an operator policy in wp-config.php.', 'wei-alipay' ),
            ),
            'legacy_md5_ack' => array(
                'title'       => __( 'Acknowledge legacy risk', 'wei-alipay' ),
                'type'        => 'checkbox',
                'label'       => __( 'Temporarily allow this site to use the legacy MD5 protocol.', 'wei-alipay' ),
                'default'     => 'no',
                'description' => __( 'Also define WEI_ALIPAY_ALLOW_LEGACY_MD5 as true. Remove both controls after migrating to RSA2.', 'wei-alipay' ),
            ),
            'partnerID' => array(
                'title'       => __('Partner ID', 'wei-alipay'),
                'type'        => 'text',
                'description' => __('Please enter the partner ID<br />If you don\'t have one, <a href="https://b.alipay.com/newIndex.htm" target="_blank">click here</a> to get.', 'wei-alipay'),
                'css'         => 'width:400px'
            ),
            'secure_key' => array(
                'title'       => __('Security Key', 'wei-alipay'),
                'type'        => 'password',
                'description' => __('Please enter the security key<br />If you don\'t have one, <a href="https://b.alipay.com/newIndex.htm" target="_blank">click here</a> to get.', 'wei-alipay'),
                'css'         => 'width:400px'
            ),
            'alipay_account' => array(
                'title'       => __('Alipay Account', 'wei-alipay'),
                'type'        => 'text',
                'description' => __('Please enter your Alipay account ( Email or Phone Number ); this is needed in order to take payment.', 'wei-alipay'),
                'css'         => 'width:200px',
                'desc_tip'    => true,
            ),
            'order_prefix' => array(
                'title'       => __( 'Order No. Prefix', 'wei-alipay' ),
                'type'        => 'text',
                'description' => __( 'eg.WC-. If you <strong>use your Alipay account for multiple stores</strong>, Please enter this prefix and make sure it is unique as Alipay will not allow orders with the same merchant order number.', 'wei-alipay' ),
                'default'     => 'WC-'
            ),
            'form_submission_method' => array(
                'title'       => __('Submission method', 'wei-alipay'),
                'type'        => 'checkbox',
                'label'       => __('Use form submission method.', 'wei-alipay'),
                'description' => __('Enable this to post order data to Alipay via a form instead of using a redirect/querystring.', 'wei-alipay'),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
            'order_title_format' => array(
                'title'       => __('Preferred format for order title', 'wei-alipay'),
                'type'        => 'select',
                'label'       => __('Select your preferred order title format', 'wei-alipay'),
                'description' => __('Select the format of order title when making payment at Alipay', 'wei-alipay'),
                'options'     => array(
                    'customer_name' => __('Customer Full Name|#Order ID', 'wei-alipay'),
                    'product_title' => __('Name of the first Product|#Order ID', 'wei-alipay'),
                    /* translators: %s: site name */
                    'shop_name'     => sprintf( __( '[Customer Full Name]\'s Order From %s|#Order ID', 'wei-alipay' ), get_bloginfo('name') )
                ),
                'desc_tip'    => true,
            ),
            'debug' => array(
                'title'       => __('Debug Log', 'wei-alipay'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'wei-alipay'),
                'default'     => 'no',
                'description' => __('Log Alipay events, such as trade status, inside <code>woocommerce/logs/alipay.txt</code>', 'wei-alipay')
            )
        );

        if (!in_array( $this->current_currency, array( 'RMB', 'CNY') )) {

            $this->form_fields['exchange_rate'] = array(
                'title'       => __('Exchange Rate', 'wei-alipay'),
                'type'        => 'text',
                /* translators: %s: store currency code */
                'description' => sprintf(__("Please set the %s against Chinese Yuan exchange rate, eg if your currency is US Dollar, then you should enter 6.19", 'wei-alipay'), $this->current_currency),
                'css'         => 'width:80px;',
                'desc_tip'    => true,
            );
        }
    }

    /**
     * Get Alipay Args 获取支付宝支付参数
     *
     * @access public
     * @param mixed $order
     * @return array
     */
    function get_alipay_args( $order ) {

        global $wpdb;

        $order_id = $order->id;

        if ( 'yes' == $this->debug ) {
            $this->log->add('alipaywap', 'Generating payment form for order #' . $order_id . '. Notify URL: ' . $this->notify_url);
        }

        // Use filter woocommerce_alipay_order_name to change the order subject 
        $subject = $this->format_order_title( $order );

        $service = 'alipay.wap.create.direct.pay.by.user';
        // Service parameter that decide the payment type
//        if ( $this->payment_method == 'direct' ){
//            $service = 'create_direct_pay_by_user';
//        } else if ( $this->payment_method == 'dualfun' ){
//            $service = 'trade_create_by_buyer';
//        } else if ( $this->payment_method == 'escrow' ){
//            $service = 'create_partner_trade_by_buyer';
//        }

        // Order total price
        $total_fee = $order->get_total();
        
        //Multi-currency supported by WooCommerce Multilingual plugin
        If ($this->multi_currency_enabled && $this->exchange_rate) {
            
            if ( !in_array(get_woocommerce_currency(), $this->supported_currencies ) && $this->current_currency != get_woocommerce_currency() ) {
               
                $table = $wpdb->prefix . 'icl_currencies';
                $sql = $wpdb->prepare( 'SELECT value FROM %i WHERE code = %s', $table, get_woocommerce_currency() );
                $currency = $wpdb->get_results( $sql, OBJECT ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared read of WPML currency configuration.

                if ( $currency ) {
                    $exchange_rate = $currency[0]->value;
                    $total_fee = round( ( $total_fee / $exchange_rate ) * $this->exchange_rate, 2 );
                }
                
            } else if ( $this->current_currency == get_woocommerce_currency() ) {
                $total_fee = round( $total_fee * $this->exchange_rate, 2 );
            }
            
        } else {
            if ( !in_array( $this->current_currency, $this->supported_currencies ) && $this->exchange_rate ) {
                $total_fee = round( $total_fee * $this->exchange_rate, 2 );
            }
        }

        // Fullfill the alipay args array
        $alipay_args = array(
            "service"           => $service,
            "partner"           => $this->partnerID,
            "seller_id"  => $this->alipay_account,
            "payment_type"      => "1",
            "notify_url"        => urldecode( $this->notify_url ),                //Avoid double encoding
            "return_url"        => urldecode( $this->get_return_url( $order ) ),  //Avoid double encoding
            "_input_charset"    => $this->charset,
            "out_trade_no"      => $this->order_prefix . ltrim( $order->get_order_number(), '#' ),
            "subject"           => $subject,
            "total_fee"             => $total_fee,
            "show_url"	=> get_permalink( $order_id ),
            "body"	=> '',
        );
//        if ($this->payment_method != 'direct') {
//            $add_args = array(
//                "logistics_fee"     => '0.00',
//                "logistics_type"    => 'EXPRESS', //optional EXPRESS（快递）、POST（平邮）、EMS（EMS）
//                "logistics_payment" => 'SELLER_PAY', //optional SELLER_PAY（卖家承担运费）、BUYER_PAY（买家承担运费）
//            );
//
//            if( !empty($buyer_name) )
//                $add_args['receive_name']       = $this->clean( $buyer_name );
//            if( !empty($order->billing_address_1) )
//                $add_args['receive_address']    = $this->clean( $order->billing_address_1 );
//            if( !empty($order->shipping_postcode) )
//                $add_args['receive_zip']        = $order->shipping_postcode;
//            if( !empty($order->billing_phone) )
//                $add_args['receive_phone']      = $order->billing_phone;
//            if( !empty($order->billing_phone) )
//                $add_args['receive_mobile']     = $order->billing_phone;
//
//            if( empty($add_args['receive_address']) ) unset($add_args['receive_address']);
//
//            $alipay_args = array_merge($alipay_args, $add_args);
//        }

        $alipay_args = apply_filters( 'woocommerce_alipay_args', $alipay_args );

        return $alipay_args;
    }

    /**
     * Get Alipay configuration
     *
     * @access public
     * @param mixed $order
     * @return array
     */
    function get_alipay_config() {        
        $alipay_config = array();
        $alipay_config['partner']       = trim( $this->partnerID );
        $alipay_config['seller_id']       = trim($this->alipay_account);
        $alipay_config['key']           = trim( $this->secure_key );

        $alipay_config['notify_url'] = urldecode( $this->notify_url );
        $alipay_config['return_url'] = '';
        $alipay_config['sign_type']     = 'MD5';
        $alipay_config['input_charset'] = $this->charset;
        $alipay_config['cacert']        = $this->lib_path . DIRECTORY_SEPARATOR . 'cacert.pem';
        $alipay_config['transport']     = 'http';

        // 支付类型 ，无需修改
        $alipay_config['payment_type'] = "1";

        // 产品类型，无需修改
        $alipay_config['service'] = "alipay.wap.create.direct.pay.by.user";
        // SSL support
        if( is_ssl() || get_option('woocommerce_force_ssl_checkout') == 'yes' ){
            $alipay_config['transport'] = 'https';
        }

        $alipay_config = apply_filters( 'woocommerce_alipay_config_args', $alipay_config );

        return $alipay_config;
    }

    /**
     * Build Alipay Query String for redirection to Alipay using GET method
     *
     * @access public
     * @param mixed $order
     * @return string
     */
    function build_alipay_string( $order ) {

        require_once __DIR__ . '/lib/alipay_submit.class.php';

        // Get alipay args
        $alipay_args    = $this->get_alipay_args( $order );
        $alipay_config  = $this->get_alipay_config();

        $alipaySubmit   = new AlipaySubmitWap( $alipay_config );

        // Build query string
        $query_string   = $alipaySubmit->buildRequestParaToString( $alipay_args );
        $alipay_string  = $alipaySubmit->alipay_gateway_new . $query_string;

        return $alipay_string;
    }

    /**
     * Return page of Alipay, show Alipay Trade No. 
     *
     * @access public
     * @param mixed Sync Notification
     * @return void
     */
    function thankyou_page( $order_id ) {
        $params = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signed provider return plus WooCommerce order key; display-only.
        $order  = wc_get_order( $order_id );
        $key    = isset( $params['key'] ) && is_string( $params['key'] ) ? wc_clean( $params['key'] ) : '';
        if ( ! $order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
            return;
        }
        unset( $params['page_id'], $params['order-received'], $params['order'], $params['key'] );
        $protocol = $this->selected_protocol();
        if ( is_wp_error( $protocol ) ) {
            return;
        }
        if ( Wei_Alipay_Mini_Protocol::LEGACY_MD5 === $protocol ) {
            $verified = Wei_Alipay_Mini_Protocol::verify_legacy_md5( $params, $this->secure_key );
        } else {
            $core = $this->get_core_settings();
            $verified = ! empty( $core['public_key'] ) && Alipay_SDK_Helper::verify_notify( $params, $core['public_key'] );
        }
        $out_trade_no = isset( $params['out_trade_no'] ) && is_string( $params['out_trade_no'] ) ? wc_clean( $params['out_trade_no'] ) : '';
        $trade_no     = isset( $params['trade_no'] ) && is_string( $params['trade_no'] ) ? wc_clean( $params['trade_no'] ) : '';
        if ( ! $verified || '' === $trade_no || ! hash_equals( (string) $order->get_meta( '_alipay_out_trade_no' ), $out_trade_no ) ) {
            return;
        }
        echo '<ul class="order_details"><li class="alipayNo">' . esc_html__( 'Your Alipay Trade No.: ', 'wei-alipay' ) . '<strong>' . esc_html( $trade_no ) . '</strong></li></ul>';
    }

    /**
     * Generate the alipay button link (POST method)
     *
     * @access public
     * @param mixed $order_id
     * @return string
     */
    function generate_alipay_form( $order_id ) {

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return '<p>' . esc_html__( 'Invalid order.', 'wei-alipay' ) . '</p>';
        }
        if ( Wei_Alipay_Mini_Protocol::LEGACY_MD5 !== $this->selected_protocol() ) {
            return '<p>' . esc_html__( 'Legacy payment form is disabled; start a new RSA2 payment from checkout.', 'wei-alipay' ) . '</p>';
        }
        require_once __DIR__ . '/lib/alipay_submit.class.php';

        $alipay_args    = $this->get_alipay_args( $order );
        $alipay_config  = $this->get_alipay_config();
        $alipaySubmit   = new AlipaySubmitWap( $alipay_config );
        $alipay_adr     = $alipaySubmit->alipay_gateway_new;
        $para           = $alipaySubmit->buildRequestPara($alipay_args, $alipay_config);

        $alipay_args_array = array();
        foreach ($para as $key => $value) {
            $alipay_args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
        }

        wc_enqueue_js( '
            $.blockUI({
                    message: "' . esc_js( __( 'Thank you for your order. We are now redirecting you to Alipay to make payment.', 'wei-alipay' ) ) . '",
                    baseZ: 99999,
                    overlayCSS:
                    {
                        background: "#fff",
                        opacity: 0.6
                    },
                    css: {
                        padding:        "20px",
                        zindex:         "9999999",
                        textAlign:      "center",
                        color:          "#555",
                        border:         "3px solid #aaa",
                        backgroundColor:"#fff",
                        cursor:         "wait",
                        lineHeight:     "24px",
                    }
                });
            jQuery("#submit_alipay_payment_form").click();
        ' );
        
        return '<form id="alipaysubmit" name="alipaysubmit" action="' . esc_url( $alipay_adr . '_input_charset=' . trim( strtolower( $alipay_config['input_charset'] ) ) ) . '" method="post" target="_top">' . implode('', $alipay_args_array) . '
                    <!-- Button Fallback -->
                    <div class="payment_buttons">
                        <input type="submit" class="button-alt" id="submit_alipay_payment_form" value="' . esc_attr__( 'Pay via Alipay', 'wei-alipay' ) . '" /> <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . esc_html__( 'Cancel order &amp; restore cart', 'wei-alipay' ) . '</a>
                    </div>
                </form>';
    }

    /**
     * Process the payment and return the result
     *
     * @access public
     * @param  int $order_id
     * @return array
     */
    function process_payment( $order_id ) {

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wc_add_notice( __( 'Invalid order.', 'wei-alipay' ), 'error' );
            return array( 'result' => 'fail' );
        }

        $protocol = $this->selected_protocol();
        if ( is_wp_error( $protocol ) ) {
            wc_add_notice( $protocol->get_error_message(), 'error' );
            return array( 'result' => 'fail' );
        }

        if ( Wei_Alipay_Mini_Protocol::LEGACY_MD5 === $protocol ) {
            $args = $this->get_alipay_args( $order );
            $order->update_meta_data( '_alipay_out_trade_no', $args['out_trade_no'] );
            $order->update_meta_data( '_woo_alipay_mini_protocol', Wei_Alipay_Mini_Protocol::LEGACY_MD5 );
            $order->save();
            return array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url( true ),
            );
        }

        require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
        require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradePagePayRequest.php';

        $missing_fields = $this->get_missing_core_fields();
        if ( ! empty( $missing_fields ) ) {
            /* translators: %s: comma-separated list of missing configuration field names */
            wc_add_notice( sprintf( __( 'Alipay core configuration is incomplete. Missing fields: %s', 'wei-alipay' ), implode( ', ', $missing_fields ) ), 'error' );
            $this->log_event( 'Payment init blocked: missing core settings', array( 'missing' => implode( '|', $missing_fields ), 'order_id' => $order_id ) );
            return array( 'result' => 'fail' );
        }

        $main_settings = $this->get_core_settings();
        $config = Alipay_SDK_Helper::get_alipay_config( array(
            'appid'       => $main_settings['appid'],
            'private_key' => $main_settings['private_key'],
            'public_key'  => $main_settings['public_key'],
            'sandbox'     => $main_settings['sandbox'],
        ) );

        $aop = Alipay_SDK_Helper::create_alipay_service( $config );
        if ( ! $aop ) {
            wc_add_notice( __( 'Alipay service initialization failed.', 'wei-alipay' ), 'error' );
            return array( 'result' => 'fail' );
        }

        $alipay_args = $this->get_alipay_args( $order );
        $out_trade_no = isset( $alipay_args['out_trade_no'] ) ? $alipay_args['out_trade_no'] : ( $this->order_prefix . ltrim( $order->get_order_number(), '#' ) );

        $this->log_event( 'Payment init', array( 'order_id' => $order_id, 'out_trade_no' => $out_trade_no ) );

        $order->update_meta_data( '_alipay_out_trade_no', $out_trade_no );
        $order->update_meta_data( '_woo_alipay_mini_protocol', Wei_Alipay_Mini_Protocol::RSA2 );
        $order->save();

        $request = new AlipayTradePagePayRequest();
        $biz_content = array(
            'out_trade_no' => $out_trade_no,
            'total_amount' => Alipay_SDK_Helper::format_amount( $alipay_args['total_fee'] ),
            'subject'      => $alipay_args['subject'],
            'product_code' => 'FAST_INSTANT_TRADE_PAY',
        );
        $request->setBizContent( wp_json_encode( $biz_content ) );
        $request->setNotifyUrl( $this->notify_url );
        $request->setReturnUrl( $this->get_return_url( $order ) );

        $redirect = $aop->pageExecute( $request, 'GET' );

        $this->log_event( 'Payment redirect built', array( 'order_id' => $order_id, 'out_trade_no' => $out_trade_no ) );

        return array(
            'result'   => 'success',
            'redirect' => $redirect,
        );
    }

    /**
     * Output for the order received page.
     *
     * @access public
     * @return void
     */
    function receipt_page( $order ) {

        echo '<p>' . esc_html__( 'Thank you for your order, please click the button below to pay with Alipay.', 'wei-alipay' ) . '</p>';

        echo wp_kses(
            $this->generate_alipay_form( $order ),
            array(
                'form'  => array( 'id' => true, 'name' => true, 'action' => true, 'method' => true, 'target' => true ),
                'input' => array( 'type' => true, 'name' => true, 'value' => true, 'class' => true, 'id' => true ),
                'div'   => array( 'class' => true ),
                'a'     => array( 'class' => true, 'href' => true ),
                'p'     => array(),
            )
        );
    }

    /**
     * Check for Alipay IPN Response
     *
     * @access public
     * @return void
     */

    function check_alipay_response() {

        $params = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- provider callback is authenticated by signature and replay lock.
        @ob_clean();

        require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';

        $main_settings = $this->get_core_settings();
        $protocol = $this->selected_protocol();
        if ( is_wp_error( $protocol ) ) {
            wp_die( 'Fail' );
        }
        if ( Wei_Alipay_Mini_Protocol::LEGACY_MD5 === $protocol ) {
            if ( ! Wei_Alipay_Mini_Protocol::verify_legacy_md5( $params, $this->secure_key ) ) {
                $this->log_event( 'Legacy notify verify failed' );
                wp_die( 'Fail' );
            }
        } else {
            $alipay_public_key = $main_settings['public_key'];
            if ( empty( $alipay_public_key ) || ! Alipay_SDK_Helper::verify_notify( $params, $alipay_public_key ) ) {
                $this->log_event( 'RSA2 notify verify failed' );
                wp_die( 'Fail' );
            }
        }

        $this->log_event( 'Notify verified' );

        $out_trade_no = isset( $params['out_trade_no'] ) && is_string( $params['out_trade_no'] ) ? wc_clean( $params['out_trade_no'] ) : '';
        $trade_status = isset( $params['trade_status'] ) && is_string( $params['trade_status'] ) ? wc_clean( $params['trade_status'] ) : '';
        $trade_no = isset( $params['trade_no'] ) && is_string( $params['trade_no'] ) ? wc_clean( $params['trade_no'] ) : '';

        if ( empty( $out_trade_no ) || empty( $trade_status ) ) {
            $this->log_event( 'Notify rejected: missing required fields', array( 'out_trade_no' => $out_trade_no, 'trade_status' => $trade_status ) );
            wp_die( 'Invalid Order ID' );
        }

        $lookup = Alipay_SDK_Helper::find_order_by_out_trade_no( $out_trade_no, array( $this->id ) );
        if ( $lookup instanceof WC_Order ) {
            $order = $lookup;
            $order_id = $order->get_id();
        } else {
            if ( is_wp_error( $lookup ) && 'order_not_found' !== $lookup->get_error_code() ) {
                $this->log_event( 'Notify rejected: ambiguous merchant order number', array( 'out_trade_no' => $out_trade_no ) );
                wp_die( 'Fail' );
            }
            $fallback_id = (int) str_replace( $this->order_prefix, '', $out_trade_no );
            $order = $fallback_id ? wc_get_order( $fallback_id ) : false;
            if ( ! $order ) {
                $this->log_event( 'Notify rejected: order not found', array( 'out_trade_no' => $out_trade_no ) );
                wp_die( 'Invalid Order ID' );
            }
            $order_id = $order->get_id();
        }

        $expected_args = $this->get_alipay_args( $order );
        $context_params = $params;
        $expected_app_id = $main_settings['appid'];
        $expected_seller = $main_settings['seller_id'];
        if ( Wei_Alipay_Mini_Protocol::LEGACY_MD5 === $protocol ) {
            $legacy_identity = Wei_Alipay_Mini_Protocol::validate_legacy_identity( $params, $this->partnerID, $this->alipay_account );
            if ( is_wp_error( $legacy_identity ) ) {
                $this->log_event( 'Notify rejected: legacy merchant mismatch', array( 'reason' => $legacy_identity->get_error_code(), 'order_id' => $order_id ) );
                wp_die( 'Fail' );
            }
            $context_params['app_id']       = 'legacy-md5:' . $this->partnerID;
            $context_params['total_amount'] = isset( $context_params['total_fee'] ) ? $context_params['total_fee'] : '';
            $context_params['sign_type']    = 'RSA2';
            $expected_app_id                = 'legacy-md5:' . $this->partnerID;
            $expected_seller                = $this->alipay_account;
        }
        $context_check = Alipay_SDK_Helper::validate_notify_context(
            $context_params,
            $order,
            $expected_app_id,
            isset( $expected_args['total_fee'] ) ? $expected_args['total_fee'] : $order->get_total(),
            (string) $order->get_meta( '_alipay_out_trade_no' ),
            array( 'alipay_wap' ),
            $expected_seller
        );
        if ( is_wp_error( $context_check ) ) {
            $this->log_event( 'Notify rejected: context mismatch', array( 'reason' => $context_check->get_error_code(), 'order_id' => $order_id ) );
            wp_die( 'Fail' );
        }

        $transition = Alipay_SDK_Helper::validate_notify_transition( $order, $trade_status );
        if ( is_wp_error( $transition ) ) {
            $this->log_event( 'Notify rejected: invalid transition', array( 'reason' => $transition->get_error_code(), 'order_id' => $order_id ) );
            wp_die( 'Fail' );
        }

        $lock = Alipay_SDK_Helper::acquire_lock( 'notify', $out_trade_no, 30 );
        if ( false === $lock ) {
            wp_die( 'Fail' );
        }

        $order = wc_get_order( $order_id );
        $context_check = Alipay_SDK_Helper::validate_notify_context(
            $context_params,
            $order,
            $expected_app_id,
            isset( $expected_args['total_fee'] ) ? $expected_args['total_fee'] : $order->get_total(),
            (string) $order->get_meta( '_alipay_out_trade_no' ),
            array( 'alipay_wap' ),
            $expected_seller
        );
        $transition = is_wp_error( $context_check ) ? $context_check : Alipay_SDK_Helper::validate_notify_transition( $order, $trade_status );
        if ( is_wp_error( $transition ) ) {
            Alipay_SDK_Helper::release_lock( 'notify', $out_trade_no, $lock );
            $this->log_event( 'Notify rejected after lock', array( 'reason' => $transition->get_error_code(), 'order_id' => $order_id ) );
            wp_die( 'Fail' );
        }

        if ( 'process_payment' === $transition ) {
            $order->payment_complete( $trade_no );
            $order->update_meta_data( 'Alipay Trade No.', $trade_no );
            $order->save();
            $this->log_event( 'Notify paid', array( 'order_id' => $order_id, 'out_trade_no' => $out_trade_no, 'trade_no' => $trade_no, 'trade_status' => $trade_status ) );
        } elseif ( 'process_close' === $transition ) {
            $order->update_status( 'cancelled', __( 'Alipay closed the transaction.', 'wei-alipay' ) );
        } else {
            $this->log_event( 'Notify replay/pending acknowledged', array( 'order_id' => $order_id, 'out_trade_no' => $out_trade_no, 'trade_no' => $trade_no, 'trade_status' => $trade_status ) );
        }

        Alipay_SDK_Helper::release_lock( 'notify', $out_trade_no, $lock );
        $this->successful_request( $params, $order_id );
    }

    /**
     * Allow completing order when status is processing
     *
     * @param array $statuses    
     * @since 1.3.4
     * @return array
     */
    function valid_order_statuses( $statuses ){
        return array( 'processing' );
    }

    function complete_order_status( $new_order_status ){
        return 'completed';
    }

    /**
     * Change order status to WAIT_BUYER_CONFIRM_GOODS
     *
     * @param string $trade_no
     * @param bool $force_send_goods
     * @param bool $require_shipping
     * @since 1.3
     * @return string
     */
    function send_goods_confirm( $trade_no, $order, $force_send_goods = false, $require_shipping = false ){

        if( empty( $trade_no ) ){
            return 'error: ' . __( 'Trade No. is not provided.', 'wei-alipay' );
        } else if( !function_exists('curl_version') ){
            return 'error: ' . __( 'cURL is not installed on this server', 'wei-alipay' );
        }

        // Decide if goodes need to be send automatically
        if( !in_array( $order->status, array( 'pending', 'failed', 'on-hold') ) ){

            $send_goods = false;

            if( $force_send_goods ){

                $send_goods = true;

            } else if ( sizeof( $order->get_items() ) > 0 ) {

                foreach( $order->get_items() as $item ) {
                    if ( $item['product_id'] > 0 ) {
                        $_product = $order->get_product_from_item( $item );
                        if ( false !== $_product && ( $_product->is_downloadable() && $_product->is_virtual() ) ) {
                            $send_goods = true;
                            continue;
                        }
                    }
                    $send_goods = false;
                    break;
                }
            }
        }

        if( !$send_goods ) return false;

        // Send requrest to alipay
        require_once __DIR__ . '/lib/alipay_submit.class.php';

        if( !$require_shipping ){

            $parameter = array(
                "service"           => "send_goods_confirm_by_platform",
                "partner"           => $this->partnerID,
                "trade_no"          => $trade_no,
                "logistics_name"    => 'ZJS',
                "transport_type"    => 'DIRECT',
                "_input_charset"    => $this->charset
            );

        } else {
            // Develop function of sending non-digital goods from WordPress backend
            // Currently this part is not used.
        }

        $alipay_config = $this->get_alipay_config();

        $alipaySubmit = new AlipaySubmitWap( $alipay_config );
        $html_text = $alipaySubmit->buildRequestHttp( $parameter );

        if( !empty( $html_text ) ){

            $doc = new DOMDocument();        
            $doc->loadXML( $html_text );

            $success = $doc->getElementsByTagName('is_success');

            if( $success->item(0)->nodeValue == 'F' ){
                // Failed to update status
                $error = $doc->getElementsByTagName('error');
                return 'error: ' . $error->item(0)->nodeValue;
            } else {
                return true;
            }       
            
        } else {
            return 'Error: Request Failed';
        }
        
    }
    

    /**
     * Successful Payment!
     *
     * @access public
     * @param array $posted
     * @return void
     */
    function successful_request( $posted, $order_id ) {

        if ( 'yes' == $this->debug ){
            $this->log->add('alipaywap', 'Trade Status Received: '.$posted['trade_status'].', Order ID: ' . $order_id );
        }

        header('HTTP/1.1 200 OK');
        echo "success";
        exit;
    }

    /**
     * Format order title
     *
     * @access public
     * @param mixed $order
     * @param int $length
     * @since 1.3
     * @return string
     */
    function format_order_title( $order, $length = 256 ){

        $order_id = $order->id;

        if( empty($this->order_title_format) ){
            $this->order_title_format = 'customer_name';
        }

        $title = '';

        switch ( $this->order_title_format ){

            case 'customer_name' :

                if( !empty( $order->billing_last_name ) || !empty( $order->billing_first_name ) ){
                    $title = $order->billing_last_name . $order->billing_first_name.'|#'.$order_id;
                }                
                break;

            case 'product_title' :

                $line_items = $order->get_items();

                if( count($line_items) > 0 ){
                    foreach( $line_items as $line_item ){
                        $title = $line_item['name'];
                        break;
                    }
                }
                if ( strlen( $title ) > $length ) {
                    $title = mb_strimwidth( $title, 0, ($length-3), '...' );
                }               

                if( count($line_items) > 1 ){
                    $title .= __( ' etc.', 'wei-alipay');
                }

                $title .= '|#'.$order_id;
                break;

            case 'shop_name' :
                if( !empty( $order->billing_last_name ) || !empty( $order->billing_first_name ) ){
                    $customer_name = $order->billing_last_name . $order->billing_first_name;
                }
                if( !empty($customer_name) ){
                    /* translators: %1$s: customer name. %2$s: site name. */
                    $title = sprintf( __( 'Order of %1$s from %2$s', 'wei-alipay'), $customer_name, get_bloginfo( 'name' ) );
                } else{
                    /* translators: %s: site name */
                    $title = sprintf( __( 'Your order from %s', 'wei-alipay' ) , get_bloginfo( 'name' ) );
                }
                $title .= '|#'.$order_id;
                break;

            default :
                break;
        }

        $title = $this->clean( $title );
        if( empty( $title ) ) $title = '#'.$order_id;

        $title = apply_filters( 'woocommerce_alipay_order_name', $title, $title, $order );

        return $title;
    }

    /**
     * Sanitize user input
     *
     * @access public
     * @param string $str
     * @since 1.3
     * @return string
     */
    function clean( $str = ''){
        $clean = str_replace( array('%'), '', $str );
        $clean = sanitize_text_field( $clean );
        $clean = html_entity_decode(  $clean , ENT_NOQUOTES );
        return $clean;
    }

    /**
     * Display Alipay Trade No. in the backend.
     * 
     * @access public
     * @param mixed $order
     * @since 1.3
     * @return void
     */
    function wc_alipay_wap_display_order_meta_for_admin( $order ){
        $trade_no = $order instanceof WC_Order ? $order->get_meta( 'Alipay Trade No.' ) : '';
        if( !empty($trade_no ) ){
            echo '<p><strong>' . esc_html__( 'Alipay Trade No.:', 'wei-alipay') . '</strong><br />' . esc_html( $trade_no ) . '</p>';
        }
    }
}

?>
