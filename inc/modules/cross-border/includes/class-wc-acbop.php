<?php
/**
 * WC Alipay Cross Border Online Payment
 *
 * Provides Alipay Cross Border Payment Gateway.
 *
 * @class 		WC_Alipay_Cross_Border
 * @extends		WC_Payment_Gateway
 * @version		2.3.0
 * @package		WooCommerce/Classes/Payment
 * @author 		WooThemes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once(dirname( __FILE__ ) . '/function-common.php');

/**
 * WC_Gateway_Paypal Class.
 */
class WC_Alipay_Cross_Border extends WC_Payment_Gateway {

	/** @var bool Whether or not logging is enabled */
	public static $log_enabled = false;

	/** @var WC_Logger Logger instance */
	public static $log = false;

	/** Common Function class instance */
	protected $function = null;

	/** @var string */
	public $partner_id = '';

	/** @var string */
	public $secure_key = '';

	/** @var string */
	public $account_type = '';

	/** @var string */
	public $order_prefix = '';

	/** @var bool */
	public $sandbox = false;

	/** @var bool */
	public $debug = false;

	/** @var string */
	public $notify_url = '';

	/** @var string */
	public $is_force_ssl = '';

	/** @var string */
	public $selected_currency = '';

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->function 			= new CommonFunction();

		$this->id 					= 'acbop';
		$this->icon 				= apply_filters( 'woocommerce_acbop_icon', plugins_url( '../images/alipay-icon-01.png', __FILE__ ) );
		$this->has_fields 			= true;
		$this->order_button_text 	= __( 'Proceed to Alipay', 'woocommerce' );
		$this->method_title 		= __( 'Alipay Cross Border', 'woocommerce' );
		$this->method_description 	= sprintf( __( 'With the growing number of Chinese consumers purchasing merchandise on the oversea merchants’ site direct, the merchant could integrate with Alipay Standard Payment solution to offer the users the familiar user experience that they comfortable with, along the convenience provided for both the merchants and the consumers on the payment, FX exchange and settlement.', 'woocommerce' ));
		$this->supports 			= array(
			'products',
			'refunds',
		);

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

        // Define user set variables
        $this->title 					= $this->get_option( 'title' );
        $this->description 				= $this->get_option( 'description' );
        $this->partner_id 				= $this->get_option( 'partner_id' );
        $this->secure_key 				= $this->get_option( 'secure_key' );
        $this->account_type 			= $this->get_option( 'account_type' );
        $this->order_prefix 			= $this->get_option( 'order_prefix' );
        $this->sandbox 					= 'yes' === $this->get_option( 'sandbox', 'no' );
        $this->debug 					= 'yes' === $this->get_option( 'debug', 'no' );
        $this->notify_url				= WC()->api_request_url( 'WC_Alipay_Cross_Border' );
        $this->is_force_ssl				= get_option('woocommerce_force_ssl_checkout');
        $this->selected_currency		= get_option('woocommerce_currency');

        self::$log_enabled    = $this->debug;

        add_action( 'admin_notices', array( $this, 'requirement_checks' ) ); 
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_api_wc_alipay_cross_border', array( $this, 'check_alipay_response' ) );
        add_action( 'woocommerce_thankyou_acbop', array( $this, 'thankyou_page' ) );
	}

    /**
     * Check if this gateway is enabled and available for the selected main currency
     *
     * @access public
     * @return bool
     */
    function is_available() {
        $is_available = ( 'yes' === $this->enabled ) ? true : false;

        if (!array_key_exists($this->selected_currency, $this->function->getAliPaySupportedCurrencies())) {
        	$is_available = false;
        }

        if (!wp_is_mobile() && $this->account_type == 'new_cross_border_wap') {
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
        if (!array_key_exists($this->selected_currency, $this->function->getAliPaySupportedCurrencies())) {
            echo '<div class="error"><p>' . sprintf( __('Alipay is enabled, but the store currency is %1s, which is not supported.', 'acbop' ), $this->selected_currency ) . '</p></div>';
        }
    }

	public function check_alipay_response() {
		// Cleanup unwanted WC response param by AliPay
		$_POST = stripslashes_deep($_POST);
		@ob_clean();
    	$aliPayNotifyParams = $this->function->getAliPayNotifyParams($this->account_type);
    	foreach($_POST as $key => $value) {
    		if (!in_array($key, $aliPayNotifyParams)) {
    			unset($_POST[$key]);
    		}
    	}

    	// Extract order Id
    	if (!isset($_POST['out_trade_no'])) {
			$this->log( 'Invalid out trade no from Alipay' );
            $this->function->showNotifyRespond(false);
    	}
    	$orderId = (empty($this->order_prefix)) ? $_POST['out_trade_no'] : preg_replace("/^".$this->order_prefix."/", "", $_POST['out_trade_no']);
    	if (!is_numeric($orderId)) {
			$this->log( 'Invalid out trade no from Alipay (Out Trade No:'.$_POST['out_trade_no'].')' );
            $this->function->showNotifyRespond(false);
    	}

    	// Verify if response coming from Alipay
		$order = wc_get_order( $orderId );
		if ( ! $order ) {
		    $this->log( 'Order not found for notify (Order ID:' . $orderId . ')' );
		    $this->function->showNotifyRespond(false);
		}

			if( $order->get_status() != 'completed' && $order->get_status() != 'processing' ){
	    	$alipayConfig = $this->function->getAliPayConfig(
	    		$this->account_type,
	    		(isset($_POST['payment_inst'])) ? $_POST['payment_inst'] : null,
	    		$this->partner_id,
	    		$this->secure_key,
	    		$this->notify_url,
	    		$this->get_return_url($order),
	    		$this->is_force_ssl
	    	);
			$alipayNotify = new AlipayNotify($alipayConfig);
			$verify_result = $alipayNotify->verifyNotify($this->sandbox);
			if (!$verify_result) {
				$this->log( 'This is not notification from Alipay (Oder ID:'.$orderId.')' );
	            $this->function->showNotifyRespond(false);
			}

			// Proceed success payment
			if ($_POST['trade_status'] == 'TRADE_FINISHED'
				|| $_POST['trade_status'] == 'TRADE_CLOSED') {
					if( $order->get_status() != 'completed' && $order->get_status() != 'processing' ){
	                $order->payment_complete();
	            }                        
	            update_post_meta( $orderId, 'Alipay Trade No.', wc_clean($_POST['trade_no']) );
				$this->log( 'Notify Payment success! (Order ID:'.$orderId.', Alipay Trade No:'.wc_clean($_POST['trade_no']).')' );
				$this->function->showNotifyRespond(true);
			}
		} else {
			$this->function->showNotifyRespond(true);
		}

		$this->function->showNotifyRespond(false);
	}

    public function thankyou_page($orderId) {
    	// Cleanup unwanted WC response param by AliPay
    	$_GET = stripslashes_deep($_GET);
    	$aliPayResponseParams = $this->function->getAliPayResponseParams($this->account_type);
    	foreach($_GET as $key => $value) {
    		if (!in_array($key, $aliPayResponseParams)) {
    			unset($_GET[$key]);
    		}
    	}

    	// Verify if response coming from Alipay
    	$order = wc_get_order( $orderId );
    	$alipayConfig = $this->function->getAliPayConfig(
    		$this->account_type,
    		null,
    		$this->partner_id,
    		$this->secure_key,
    		$this->notify_url,
    		$this->get_return_url($order),
    		$this->is_force_ssl
    	);
		$alipayNotify = new AlipayNotify($alipayConfig);
		$verify_result = $alipayNotify->verifyReturn();
		if (!$verify_result) {
			$this->log( 'This is not redirected back from Alipay (Oder ID:'.$orderId.')' );
            _e( "<p><strong>ERROR:</strong>This is not redirected back from Alipay</p>", 'acbop' );
            return;
		}

		// Verify if order id correct
		if ($_GET['out_trade_no'] != $this->order_prefix.$orderId) {
			$this->log( 'Invalid order ID! (Oder ID:'.$orderId.')' );
            _e( "<p><strong>ERROR:</strong>Invalid order ID!</p>", 'acbop' );
            return;
		}

		// Proceed success payment
		if ($_GET['trade_status'] == 'TRADE_FINISHED') {
            if( $order->get_status() != 'completed' && $order->get_status() != 'processing' ){
                $order->payment_complete();
            }                        
            update_post_meta( $orderId, 'Alipay Trade No.', wc_clean($_GET['trade_no']) );
            echo '<ul class="order_details">
				<li>' . __('Your Alipay Trade No.: ', 'acbop') . '<strong>' . $_GET['trade_no'] . '</strong></li>
			</ul>';
			$this->log( 'Return Payment success! (Order ID:'.$orderId.', Alipay Trade No:'.wc_clean($_GET['trade_no']).')' );
		}
    }

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = include(dirname( __FILE__ ) . '/form-settings-alipay.php');
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
        <h3><?php _e('Alipay', 'acbop'); ?></h3>
        <p><?php _e('Alipay is a simple, secure and fast online payment method, customer can pay via debit card, credit card or alipay balance.', 'acbop'); ?></p>
       
        <table class="form-table">
            <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html();
            ?>
        </table><!--/.form-table-->
        <?php
    }

    public function payment_fields() {
		$description = $this->get_description();
		if ( $description ) {
			echo wpautop( wptexturize( $description ) );
		}

		if ($this->account_type == 'hk_wallet_pc2mobile') { ?>
    	<p>
    		<label for="acbop_payment_inst">Alipay Account Type</label>
    		<select class="widefat" name="acbop_payment_inst" id="acbop_payment_inst">
    			<option value="ALIPAYCN">Alipay China</option>
    			<option value="ALIPAYHK">Alipay Hong Kong</option>
    		</select>
    	</p>
    	<?php }
    }

	/**
	 * Process the payment and return the result.
	 * @param  int $orderId
	 * @return array
	 */
	public function process_payment( $orderId ) {
		global $woocommerce;

		$order = wc_get_order( $orderId );
		$redirect = $this->function->buildAlipayString(
			$order,
			$this->partner_id,
			$this->secure_key,
			$this->account_type,
			(isset($_POST['acbop_payment_inst'])) ? $_POST['acbop_payment_inst'] : null,
			$this->notify_url,
			$this->get_return_url($order),
			$this->order_prefix,
			$this->is_force_ssl,
			$this->sandbox
		);

    	// Remove cart
    	$woocommerce->cart->empty_cart();

    	return array(
    		'result' => 'success',
    		'redirect' => $redirect,
    	);
	}

	/**
	 * Logging method.
	 *
	 * @param string $message Log message.
	 */
	public static function log( $message ) {
		if ( self::$log_enabled ) {
			if ( empty( self::$log ) ) {
				self::$log = new WC_Logger();
			}
			self::$log->add( 'acbop', $message );
		}
	}
}