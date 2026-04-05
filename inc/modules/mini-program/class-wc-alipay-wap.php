<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Alipay Payment Gateway
 *
 * Provides an Alipay Payment Gateway.
 *
 * @class       WC_Alipay_Wap
 * @extends     WC_Payment_Gateway
 * @version     1.3.3
 */

class WC_Alipay_Wap extends WC_Payment_Gateway {

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
        $this->method_title           = __( 'Alipay', 'alipaywap' );
        $this->order_button_text      = __( 'Proceed to Alipay', 'alipaywap' );
        $this->notify_url             = WC()->api_request_url( 'WC_Alipay_Wap' );

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title                  = $this->get_option( 'title' );
        $this->description            = $this->get_option( 'description' );
        $this->alipay_account         = $this->get_option( 'alipay_account' );
        $this->partnerID              = $this->get_option( 'partnerID' );
        $this->secure_key             = $this->get_option( 'secure_key' );
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
        add_action( 'woocommerce_api_wc_alipay', array( $this, 'check_alipay_response' ) );

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
        if ( !in_array( $this->current_currency, array( 'RMB', 'CNY') ) && !$this->exchange_rate ) {
            echo '<div class="error"><p>' . sprintf( __('Alipay is enabled, but the store currency is not set to Chinese Yuan. Please <a href="%1s">set the %2s against the Chinese Yuan exchange rate</a>.', 'alipay' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_alipay_wap#woocommerce_alipay_exchange_rate' ), $this->current_currency ) . '</p></div>';
        }
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
        <h3><?php _e('Alipay', 'alipaywap'); ?></h3>
        <p><?php _e('Alipay is a simple, secure and fast online payment method, customer can pay via debit card, credit card or alipay balance.', 'alipaywap'); ?></p>
       
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
                'title'     => __('Enable/Disable', 'alipaywap'),
                'type'      => 'checkbox',
                'label'     => __('Enable Alipay Payment', 'alipaywap'),
                'default'   => 'no'
            ),
            'title' => array(
                'title'       => __('Title', 'alipaywap'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'alipaywap'),
                'default'     => __('Alipay', 'alipaywap'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'alipaywap'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'alipaywap'),
                'default'     => __('Pay via Alipay, if you don\'t have an Alipay account, you can also pay with your debit card or credit card', 'alipaywap'),
                'desc_tip'    => true,
            ),
//            'payment_method' => array(
//                'title'       => __('Alipay Payment Gateway Type', 'alipaywap'),
//                'type'        => 'select',
//                'description' => __('Please choose a payment method, note that the Dual requires a corporate account', 'alipaywap'),
//                'options'     => array(
//                    'escrow'  => __('Escrow Payment', 'alipaywap'),
//                    'dualfun' => __('Dual(Direct Payment + Escrow payment)', 'alipaywap'),
//                    'direct'  => __('Direct Payment', 'alipaywap')
//                ),
//                'desc_tip'    => true,
//            ),
            'partnerID' => array(
                'title'       => __('Partner ID', 'alipaywap'),
                'type'        => 'text',
                'description' => __('Please enter the partner ID<br />If you don\'t have one, <a href="https://b.alipay.com/newIndex.htm" target="_blank">click here</a> to get.', 'alipay'),
                'css'         => 'width:400px'
            ),
            'secure_key' => array(
                'title'       => __('Security Key', 'alipaywap'),
                'type'        => 'password',
                'description' => __('Please enter the security key<br />If you don\'t have one, <a href="https://b.alipay.com/newIndex.htm" target="_blank">click here</a> to get.', 'alipaywap'),
                'css'         => 'width:400px'
            ),
            'alipay_account' => array(
                'title'       => __('Alipay Account', 'alipaywap'),
                'type'        => 'text',
                'description' => __('Please enter your Alipay account ( Email or Phone Number ); this is needed in order to take payment.', 'alipaywap'),
                'css'         => 'width:200px',
                'desc_tip'    => true,
            ),
            'order_prefix' => array(
                'title'       => __( 'Order No. Prefix', 'alipaywap' ),
                'type'        => 'text',
                'description' => __( 'eg.WC-. If you <strong>use your Alipay account for multiple stores</strong>, Please enter this prefix and make sure it is unique as Alipay will not allow orders with the same merchant order number.', 'alipaywap' ),
                'default'     => 'WC-'
            ),
            'form_submission_method' => array(
                'title'       => __('Submission method', 'alipaywap'),
                'type'        => 'checkbox',
                'label'       => __('Use form submission method.', 'alipaywap'),
                'description' => __('Enable this to post order data to Alipay via a form instead of using a redirect/querystring.', 'alipaywap'),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
            'order_title_format' => array(
                'title'       => __('Preferred format for order title', 'alipaywap'),
                'type'        => 'select',
                'label'       => __('Select your preferred order title format', 'alipaywap'),
                'description' => __('Select the format of order title when making payment at Alipay', 'alipaywap'),
                'options'     => array(
                    'customer_name' => __('Customer Full Name|#Order ID', 'alipaywap'),
                    'product_title' => __('Name of the first Product|#Order ID', 'alipaywap'),
                    'shop_name'     => sprintf( __( '[Customer Full Name]\'s Order From %s|#Order ID', 'alipaywap' ), get_bloginfo('name') )
                ),
                'desc_tip'    => true,
            ),
            'debug' => array(
                'title'       => __('Debug Log', 'alipaywap'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'alipaywap'),
                'default'     => 'no',
                'description' => __('Log Alipay events, such as trade status, inside <code>woocommerce/logs/alipay.txt</code>', 'alipaywap')
            )
        );

        if (!in_array( $this->current_currency, array( 'RMB', 'CNY') )) {

            $this->form_fields['exchange_rate'] = array(
                'title'       => __('Exchange Rate', 'alipaywap'),
                'type'        => 'text',
                'description' => sprintf(__("Please set the %s against Chinese Yuan exchange rate, eg if your currency is US Dollar, then you should enter 6.19", 'alipaywap'), $this->current_currency),
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
               
                $sql = "SELECT (value) FROM " . $wpdb->prefix . "icl_currencies WHERE code = '" . get_woocommerce_currency() . "'";
                $currency = $wpdb->get_results($sql, OBJECT);

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

        $_GET = stripslashes_deep( $_GET );

        if ( isset( $_GET['trade_status'] ) && !empty( $_GET['trade_status'] ) ) {

            require_once __DIR__ . '/lib/alipay_notify.class.php';

            $aliapy_config  = $this->get_alipay_config();
            $alipayNotify   = new AlipayNotifyWap( $aliapy_config );
            $order_key      = $_GET['key'];

            // Delete parameters added by WC from $_GET
            if( isset( $_GET['page_id'] ) )         unset( $_GET['page_id'] );          // When using default permlaink format
            if( isset( $_GET['order-received'] ) )  unset( $_GET['order-received'] );   // When using default permlaink format
            unset( $_GET['order'] );
            unset( $_GET['key'] );

            if ( $this->debug == 'yes' ){
                $log = true;
            }

            $verify_result = $alipayNotify->verifyReturn( $log );

            if ( $verify_result ) {

                $trade_no      = $_GET['trade_no'];         // Alipay Order Number
                $out_trade_no  = $_GET['out_trade_no'];     // Merchant Order Number

                // Check order ID
                if( is_numeric( $out_trade_no ) ){
                    if( !empty( $this->order_prefix ) ){
                        $check  = (int) str_replace( $this->order_prefix, '', $out_trade_no );
                    } else {
                        $check  = (int) $out_trade_no;
                    }
                } else {
                    $check = (int) str_replace( $this->order_prefix, '', $out_trade_no );
                }

                if( $order_id != $check ){
                    // We have an invalid $order_id, probably because order_prefix has changed
                    $check  = wc_get_order_id_by_order_key( $order_key );
                    if( $order_id != $check ){
                        _e( "<p><strong>ERROR:</strong>The order ID doesn't match!</p>", 'alipaywap' );
                        return;
                    }
                }

                // Order ID is correct.
                $order = new WC_Order( $order_id );

                echo '<ul class="order_details">
                        <li class="alipayNo">' . __('Your Alipay Trade No.: ', 'alipaywap') . '<strong>' . $trade_no . '</strong></li>
                    </ul>';


                // Update the order according to data carried with the return_url
                $trade_status = $_GET['trade_status'];
                switch( $trade_status ){

                    case 'WAIT_SELLER_SEND_GOODS' :

                        $order_needs_updating = ( in_array( $order->status, array('processing', 'completed') ) ) ? false : true;
                        $status               = apply_filters( 'woocommerce_alipay_payment_successful_status', 'processing', $order); 

                        if( $order_needs_updating ){

                            // Update order status if IPN has not process the order
                            update_post_meta( $order_id, 'Alipay Trade No.', wc_clean( $trade_no ) );
                            $order->update_status( $status, __( "Payment received, awaiting fulfilment. ", 'alipaywap' ) );
                            
                            // Send deliver notification to alipay if the order is vitural and downloadable.
                            $success = $this->send_goods_confirm( wc_clean( $trade_no ), $order );
                            if( strpos( $success, 'error' ) !== false ){
                                // Failed to update status
                                if ( 'yes' == $this->debug ){
                                    $message = sprintf( __('ERROR: Failed to send goods automatically for order %d, ', 'alipaywap' ), $order_id ) ;
                                    $message .= $success;
                                    $this->log->add( 'alipaywap',  $message );
                                }
                            } else if( $success === true ) {
                                $order->add_order_note( __( 'Your order has been shipped, awaiting buyer\'s confirmation', 'alipaywap' ) );
                            }

                            // Reduce stock level
                            $order->reduce_order_stock();
                        }
                        break;

                    case 'TRADE_FINISHED':
                    case 'TRADE_SUCCESS' :

                        if( $order->status != 'completed' && $order->status != 'processing' ){
                            $order->payment_complete();
                        }                        
                        update_post_meta( $order_id, 'Alipay Trade No.', wc_clean( $trade_no ) );
                        break;

                    default :
                        break;
                }
            }
        }
    }

    /**
     * Generate the alipay button link (POST method)
     *
     * @access public
     * @param mixed $order_id
     * @return string
     */
    function generate_alipay_form( $order_id ) {

        $order = new WC_Order($order_id);
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
                    message: "' . esc_js( __( 'Thank you for your order. We are now redirecting you to Alipay to make payment.', 'alipaywap' ) ) . '",
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
        
        return '<form id="alipaysubmit" name="alipaysubmit" action="' . $alipay_adr . '_input_charset=' . trim( strtolower($alipay_config['input_charset'] ) ) . '" method="post" target="_top">' . implode('', $alipay_args_array) . '
                    <!-- Button Fallback -->
                    <div class="payment_buttons">
                        <input type="submit" class="button-alt" id="submit_alipay_payment_form" value="' . __('Pay via Alipay', 'alipaywap') . '" /> <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'alipaywap') . '</a>
                    </div>
                    <script type="text/javascript">
                        jQuery(".payment_buttons").hide();
                    </script>
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
            wc_add_notice( __( 'Invalid order.', 'alipaywap' ), 'error' );
            return array( 'result' => 'fail' );
        }

        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradePagePayRequest.php';

        $main_settings = get_option( 'woocommerce_alipay_settings', array() );
        $config = Alipay_SDK_Helper::get_alipay_config( array(
            'appid'       => isset( $main_settings['appid'] ) ? $main_settings['appid'] : '',
            'private_key' => isset( $main_settings['private_key'] ) ? $main_settings['private_key'] : '',
            'public_key'  => isset( $main_settings['public_key'] ) ? $main_settings['public_key'] : '',
            'sandbox'     => isset( $main_settings['sandbox'] ) ? $main_settings['sandbox'] : 'no',
        ) );

        $aop = Alipay_SDK_Helper::create_alipay_service( $config );
        if ( ! $aop ) {
            wc_add_notice( __( 'Alipay service initialization failed.', 'alipaywap' ), 'error' );
            return array( 'result' => 'fail' );
        }

        $alipay_args = $this->get_alipay_args( $order );
        $out_trade_no = isset( $alipay_args['out_trade_no'] ) ? $alipay_args['out_trade_no'] : ( $this->order_prefix . ltrim( $order->get_order_number(), '#' ) );

        $order->update_meta_data( '_alipay_out_trade_no', $out_trade_no );
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

        if ( 'yes' == $this->debug ) {
            $this->log->add( 'alipaywap', 'SDK payment redirect: ' . $redirect );
        }

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

        echo '<p>' . __('Thank you for your order, please click the button below to pay with Alipay.', 'alipaywap') . '</p>';

        echo $this->generate_alipay_form( $order );
    }

    /**
     * Check for Alipay IPN Response
     *
     * @access public
     * @return void
     */

    function check_alipay_response() {

        $_POST = stripslashes_deep( $_POST );
        @ob_clean();

        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';

        $main_settings = get_option( 'woocommerce_alipay_settings', array() );
        $alipay_public_key = isset( $main_settings['public_key'] ) ? $main_settings['public_key'] : '';

        if ( empty( $alipay_public_key ) ) {
            if ( 'yes' == $this->debug ) {
                $this->log->add( 'alipaywap', 'Verification result: Missing public key' );
            }
            wp_die( 'Fail' );
        }

        if ( ! Alipay_SDK_Helper::verify_notify( $_POST, $alipay_public_key ) ) {
            if ( 'yes' == $this->debug ) {
                $this->log->add( 'alipaywap', 'Verification result: Invalid' );
            }
            wp_die( 'Fail' );
        }

        if ( 'yes' == $this->debug ) {
            $this->log->add( 'alipaywap', 'Verification result: Valid' );
        }

        $out_trade_no = isset( $_POST['out_trade_no'] ) ? wc_clean( $_POST['out_trade_no'] ) : '';
        $trade_status = isset( $_POST['trade_status'] ) ? wc_clean( $_POST['trade_status'] ) : '';
        $trade_no = isset( $_POST['trade_no'] ) ? wc_clean( $_POST['trade_no'] ) : '';

        if ( empty( $out_trade_no ) || empty( $trade_status ) ) {
            wp_die( 'Invalid Order ID' );
        }

        $orders = wc_get_orders( array(
            'meta_key'   => '_alipay_out_trade_no',
            'meta_value' => $out_trade_no,
            'limit'      => 1,
        ) );

        if ( ! empty( $orders ) ) {
            $order = $orders[0];
            $order_id = $order->get_id();
        } else {
            $fallback_id = (int) str_replace( $this->order_prefix, '', $out_trade_no );
            $order = $fallback_id ? wc_get_order( $fallback_id ) : false;
            if ( ! $order ) {
                wp_die( 'Invalid Order ID' );
            }
            $order_id = $order->get_id();
        }

        $stored_trade_no = $order->get_meta( 'Alipay Trade No.' );
        if ( $order->is_paid() && ! empty( $stored_trade_no ) && ! empty( $trade_no ) && $stored_trade_no === $trade_no ) {
            $this->successful_request( $_POST, $order_id );
        }

        if ( in_array( $trade_status, array( 'TRADE_SUCCESS', 'TRADE_FINISHED' ), true ) ) {
            if ( ! $order->is_paid() ) {
                $order->payment_complete( $trade_no );

                // Some environments may not complete payment through hooks; move to processing as a safe fallback.
                $order = wc_get_order( $order_id );
                if ( $order && ! $order->is_paid() ) {
                    if ( ! empty( $trade_no ) ) {
                        $order->set_transaction_id( $trade_no );
                    }
                    $order->update_status( 'processing', __( 'Alipay payment confirmed by notify fallback.', 'alipaywap' ) );
                }
            }

            if ( ! empty( $trade_no ) ) {
                update_post_meta( $order_id, 'Alipay Trade No.', $trade_no );
            }
        } elseif ( 'WAIT_SELLER_SEND_GOODS' === $trade_status ) {
            if ( ! $order->is_paid() ) {
                if ( ! empty( $trade_no ) ) {
                    $order->set_transaction_id( $trade_no );
                    update_post_meta( $order_id, 'Alipay Trade No.', $trade_no );
                }
                $order->update_status( 'processing', __( 'Payment received and waiting for fulfilment.', 'alipaywap' ) );
            }
        } elseif ( 'yes' == $this->debug ) {
            $this->log->add( 'alipaywap', 'Unhandled trade status: ' . $trade_status . ', order: ' . $order_id );
        }

        $this->successful_request( $_POST, $order_id );
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
            return 'error: ' . __( 'Trade No. is not provided.', 'alipaywap' );
        } else if( !function_exists('curl_version') ){
            return 'error: ' . __( 'cURL is not installed on this server', 'alipaywap' );
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
                    $title .= __( ' etc.', 'alipaywap');
                }

                $title .= '|#'.$order_id;
                break;

            case 'shop_name' :
                if( !empty( $order->billing_last_name ) || !empty( $order->billing_first_name ) ){
                    $customer_name = $order->billing_last_name . $order->billing_first_name;
                }
                if( !empty($customer_name) ){
                    $title = sprintf( __( "Order of %1s from %2s", 'alipaywap'), $customer_name, get_bloginfo( 'name' ) );
                } else{
                    $title = sprintf( __( 'Your order from %s', 'alipaywap' ) , get_bloginfo( 'name' ) );
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
        $trade_no = get_post_meta( $order->id, 'Alipay Trade No.', true );
        if( !empty($trade_no ) ){
            echo '<p><strong>' . __( 'Alipay Trade No.:', 'alipaywap') . '</strong><br />' .$trade_no. '</p>';
        }
    }
}

if ( class_exists( 'WC_Alipay_Wap' ) && ! class_exists( 'Woo_Alipay_Mini_Program_Gateway' ) ) {
    class_alias( 'WC_Alipay_Wap', 'Woo_Alipay_Mini_Program_Gateway' );
}
?>