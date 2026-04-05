<?php
/**
 * WC Alipay Cross Border Online Payment
 *
 * Provides Alipay Cross Border Payment Gateway.
 *
 * @class               Woo_Alipay_Cross_Border_Gateway
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
class Woo_Alipay_Cross_Border_Gateway extends WC_Payment_Gateway {

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
        $this->notify_url				= WC()->api_request_url( 'Woo_Alipay_Cross_Border_Gateway' );
        $this->is_force_ssl				= get_option('woocommerce_force_ssl_checkout');
        $this->selected_currency		= get_option('woocommerce_currency');

        self::$log_enabled    = $this->debug;

        add_action( 'admin_notices', array( $this, 'requirement_checks' ) ); 
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_api_woo_alipay_cross_border_gateway', array( $this, 'check_alipay_response' ) );
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
		$_POST = stripslashes_deep( $_POST );
		@ob_clean();

		require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';

		$main_settings = get_option( 'woocommerce_alipay_settings', array() );
		$alipay_public_key = isset( $main_settings['public_key'] ) ? $main_settings['public_key'] : '';

		if ( empty( $alipay_public_key ) ) {
			$this->log( 'Notify verify skipped: missing public key' );
			$this->function->showNotifyRespond( false );
		}

		if ( ! Alipay_SDK_Helper::verify_notify( $_POST, $alipay_public_key ) ) {
			$this->log( 'Notify sign verify failed' );
			$this->function->showNotifyRespond( false );
		}

		$out_trade_no = isset( $_POST['out_trade_no'] ) ? wc_clean( $_POST['out_trade_no'] ) : '';
		$trade_no = isset( $_POST['trade_no'] ) ? wc_clean( $_POST['trade_no'] ) : '';
		$trade_status = isset( $_POST['trade_status'] ) ? wc_clean( $_POST['trade_status'] ) : '';

		if ( empty( $out_trade_no ) || empty( $trade_status ) ) {
			$this->log( 'Missing out_trade_no from notify' );
			$this->function->showNotifyRespond( false );
		}

		$orders = wc_get_orders( array(
			'meta_key'   => '_alipay_out_trade_no',
			'meta_value' => $out_trade_no,
			'limit'      => 1,
		) );

		if ( ! empty( $orders ) ) {
			$order = $orders[0];
		} else {
			$orderId = (int) preg_replace( '/^' . preg_quote( $this->order_prefix, '/' ) . '/', '', $out_trade_no );
			$order = $orderId ? wc_get_order( $orderId ) : false;
		}

		if ( ! $order ) {
			$this->log( 'Order not found for notify (Out Trade No:' . $out_trade_no . ')' );
			$this->function->showNotifyRespond( false );
		}

		$stored_trade_no = $order->get_meta( 'Alipay Trade No.' );
		if ( $order->is_paid() && ! empty( $stored_trade_no ) && ! empty( $trade_no ) && $stored_trade_no === $trade_no ) {
			$this->log( 'Duplicate notify ignored (Order ID:' . $order->get_id() . ')' );
			$this->function->showNotifyRespond( true );
		}

		if ( in_array( $trade_status, array( 'TRADE_SUCCESS', 'TRADE_FINISHED' ), true ) ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $trade_no );
			}

			if ( ! empty( $trade_no ) ) {
				update_post_meta( $order->get_id(), 'Alipay Trade No.', $trade_no );
			}

			$this->log( 'Notify payment success! (Order ID:' . $order->get_id() . ', Alipay Trade No:' . $trade_no . ')' );
			$this->function->showNotifyRespond( true );
		}

		if ( 'WAIT_BUYER_PAY' === $trade_status ) {
			$this->log( 'Notify pending payment acknowledged (Order ID:' . $order->get_id() . ')' );
			$this->function->showNotifyRespond( true );
		}

		$this->log( 'Notify rejected for unsupported trade status (Order ID:' . $order->get_id() . ', Trade Status:' . $trade_status . ')' );
		$this->function->showNotifyRespond( false );
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
		$order = wc_get_order( $orderId );
		if ( ! $order ) {
			wc_add_notice( __( 'Invalid order.', 'acbop' ), 'error' );
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
			wc_add_notice( __( 'Alipay service initialization failed.', 'acbop' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$out_trade_no = $this->order_prefix . ltrim( $order->get_order_number(), '#' );
		$order->update_meta_data( '_alipay_out_trade_no', $out_trade_no );
		$order->save();
		$payment_inst = isset( $_POST['acbop_payment_inst'] ) ? wc_clean( wp_unslash( $_POST['acbop_payment_inst'] ) ) : '';
		$biz_content = $this->build_cross_border_biz_content( $order, $out_trade_no, $payment_inst );

		if ( empty( $biz_content['product_code'] ) ) {
			// Keep legacy behavior for account types that cannot be represented safely in current SDK flow.
			$redirect = $this->function->buildAlipayString(
				$order,
				$this->partner_id,
				$this->secure_key,
				$this->account_type,
				$payment_inst,
				$this->notify_url,
				$this->get_return_url( $order ),
				$this->order_prefix,
				$this->is_force_ssl,
				$this->sandbox
			);

			if ( WC()->cart ) {
				WC()->cart->empty_cart();
			}

			return array(
				'result'   => 'success',
				'redirect' => $redirect,
			);
		}

		$request = new AlipayTradePagePayRequest();
		$request->setBizContent( wp_json_encode( $biz_content ) );
		$request->setNotifyUrl( $this->notify_url );
		$request->setReturnUrl( $this->get_return_url( $order ) );

		$redirect = $aop->pageExecute( $request, 'GET' );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $redirect,
		);
	}

	/**
	 * Build cross-border request payload while preserving account-type semantics.
	 *
	 * @param WC_Order $order
	 * @param string   $out_trade_no
	 * @param string   $payment_inst
	 * @return array
	 */
	private function build_cross_border_biz_content( $order, $out_trade_no, $payment_inst ) {
		$product_code = 'NEW_OVERSEAS_SELLER';
		if ( in_array( $this->account_type, array( 'new_cross_border_wap', 'hk_wallet_pc2mobile' ), true ) ) {
			$product_code = 'NEW_WAP_OVERSEAS_SELLER';
		} elseif ( 'new_cross_border_auto' === $this->account_type ) {
			$product_code = wp_is_mobile() ? 'NEW_WAP_OVERSEAS_SELLER' : 'NEW_OVERSEAS_SELLER';
		} elseif ( ! in_array( $this->account_type, array( 'cross_border', 'new_cross_border_pc' ), true ) ) {
			$product_code = '';
		}

		$biz_content = array(
			'out_trade_no' => $out_trade_no,
			'total_amount' => Alipay_SDK_Helper::format_amount( $order->get_total() ),
			'subject'      => sprintf( __( 'Order #%s', 'acbop' ), $order->get_order_number() ),
			'product_code' => $product_code,
		);

		if ( $payment_inst ) {
			$biz_content['payment_inst'] = $payment_inst;
		}

		if ( method_exists( $order, 'get_currency' ) ) {
			$biz_content['currency'] = $order->get_currency();
		}

		return $biz_content;
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