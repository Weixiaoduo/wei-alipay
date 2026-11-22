<?php
/**
 * WC Alipay Cross Border Online Payment
 *
 * Provides Alipay Cross Border Payment Gateway.
 *
 * @class               Wei_Alipay_Cross_Border_Gateway
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
class Wei_Alipay_Cross_Border_Gateway extends WC_Payment_Gateway {

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

	/** @var array */
	protected $required_core_fields = array( 'appid', 'private_key', 'public_key' );

	/** @var string */
	protected static $refund_request_id = '';

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->function 			= new CommonFunction();

		$this->id 					= 'acbop';
		$this->icon 				= apply_filters( 'woocommerce_acbop_icon', plugins_url( '../images/alipay-icon-01.png', __FILE__ ) );
		$this->has_fields 			= true;
		$this->order_button_text 	= __( 'Proceed to Alipay', 'wei-alipay' );
		$this->method_title 		= __( 'Alipay Cross Border', 'wei-alipay' );
		$this->method_description 	= sprintf( __( 'With the growing number of Chinese consumers purchasing merchandise on the oversea merchants’ site direct, the merchant could integrate with Alipay Standard Payment solution to offer the users the familiar user experience that they comfortable with, along the convenience provided for both the merchants and the consumers on the payment, FX exchange and settlement.', 'wei-alipay' ));
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
        $this->notify_url				= WC()->api_request_url( 'Wei_Alipay_Cross_Border_Gateway' );
        $this->is_force_ssl				= get_option('woocommerce_force_ssl_checkout');
        $this->selected_currency		= get_option('woocommerce_currency');

        self::$log_enabled    = $this->debug;

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_api_wei_alipay_cross_border_gateway', array( $this, 'check_alipay_response' ) );
        add_action( 'woocommerce_api_woo_alipay_cross_border_gateway', array( $this, 'check_alipay_response' ) );
        add_action( 'woocommerce_thankyou_acbop', array( $this, 'thankyou_page' ) );
        add_action( 'woocommerce_create_refund', array( $this, 'remember_refund_request_id' ), 10, 2 );
	}

    /**
     * Check if this gateway is enabled and available for the selected main currency
     *
     * @access public
     * @return bool
     */
    function is_available() {
        $is_available = ( 'yes' === $this->enabled ) ? true : false;

		if ( $is_available && ! $this->has_core_credentials() ) {
			$is_available = false;
		}

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
        if ( 'yes' !== $this->enabled ) {
            return;
        }

        if (!array_key_exists($this->selected_currency, $this->function->getAliPaySupportedCurrencies())) {
            /* translators: %1s: store currency code */
            echo '<div class="notice notice-error inline wei-alipay-settings-notice" role="alert"><p>' . esc_html( sprintf( __( 'Alipay is enabled, but the store currency is %1s, which is not supported.', 'wei-alipay' ), $this->selected_currency ) ) . '</p></div>';
        }

		$missing_fields = $this->get_missing_core_fields();
		if ( ! empty( $missing_fields ) ) {
			/* translators: %s: comma-separated list of missing configuration field names */
            echo '<div class="notice notice-error inline wei-alipay-settings-notice" role="alert"><p>' . esc_html( sprintf( __( 'Alipay core configuration is incomplete. Missing fields: %s', 'wei-alipay' ), implode( ', ', $missing_fields ) ) ) . '</p></div>';
		}
    }

	/**
	 * Get normalized core settings from the main gateway.
	 *
	 * @return array
	 */
	private function get_core_settings() {
		return wp_parse_args(
			get_option( 'woocommerce_alipay_settings', array() ),
			array(
				'appid'                => '',
				'private_key'          => '',
				'public_key'           => '',
				'seller_id'            => '',
				'sandbox'              => 'no',
				'sign_credential_mode' => 'public_key',
				'app_public_cert'      => '',
				'alipay_public_cert'   => '',
				'alipay_root_cert'     => '',
			)
		);
	}

	/**
	 * Return missing required core fields.
	 *
	 * @return array
	 */
	private function get_missing_core_fields() {
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
	private function has_core_credentials() {
		return empty( $this->get_missing_core_fields() );
	}

	public function check_alipay_response() {
		$notify_params = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- signed Alipay server callback; verified before fields are used.
		@ob_clean();

		require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';

		$main_settings = $this->get_core_settings();
		$alipay_public_key = Alipay_SDK_Helper::resolve_verify_key( $main_settings );

		if ( empty( $alipay_public_key ) ) {
			$this->log( 'Notify verify skipped: missing public key', array() );
			$this->function->showNotifyRespond( false );
		}

		if ( ! Alipay_SDK_Helper::verify_notify( $notify_params, $alipay_public_key ) ) {
			$this->log( 'Notify sign verify failed', array() );
			$this->function->showNotifyRespond( false );
		}

		$out_trade_no = isset( $notify_params['out_trade_no'] ) ? wc_clean( $notify_params['out_trade_no'] ) : '';
		$trade_no = isset( $notify_params['trade_no'] ) ? wc_clean( $notify_params['trade_no'] ) : '';
		$trade_status = isset( $notify_params['trade_status'] ) ? wc_clean( $notify_params['trade_status'] ) : '';

		if ( empty( $out_trade_no ) || empty( $trade_status ) ) {
			$this->log( 'Missing out_trade_no from notify', array(
				'out_trade_no' => $out_trade_no,
				'trade_status' => $trade_status,
			) );
			$this->function->showNotifyRespond( false );
		}

		$lookup = Alipay_SDK_Helper::find_order_by_out_trade_no( $out_trade_no, array( $this->id ) );
		if ( $lookup instanceof WC_Order ) {
			$order = $lookup;
		} else {
			if ( is_wp_error( $lookup ) && 'order_not_found' !== $lookup->get_error_code() ) {
				$this->log( 'Ambiguous merchant order number', array( 'out_trade_no' => $out_trade_no ) );
				$this->function->showNotifyRespond( false );
			}
			$orderId = (int) preg_replace( '/^' . preg_quote( $this->order_prefix, '/' ) . '/', '', $out_trade_no );
			$order = $orderId ? wc_get_order( $orderId ) : false;
		}

		if ( ! $order ) {
			$this->log( 'Order not found for notify', array( 'out_trade_no' => $out_trade_no ) );
			$this->function->showNotifyRespond( false );
		}

		$context_check = Alipay_SDK_Helper::validate_notify_context(
			$notify_params,
			$order,
			$main_settings['appid'],
			$order->get_total(),
			(string) $order->get_meta( '_alipay_out_trade_no' ),
			array( 'acbop' ),
			$main_settings['seller_id']
		);
		if ( is_wp_error( $context_check ) ) {
			$this->log( 'Notify context mismatch', array( 'reason' => $context_check->get_error_code(), 'order_id' => $order->get_id() ) );
			$this->function->showNotifyRespond( false );
		}

		$transition = Alipay_SDK_Helper::validate_notify_transition( $order, $trade_status );
		if ( is_wp_error( $transition ) ) {
			$this->log( 'Notify transition rejected', array( 'reason' => $transition->get_error_code(), 'order_id' => $order->get_id() ) );
			$this->function->showNotifyRespond( false );
		}

		$lock = Alipay_SDK_Helper::acquire_lock( 'notify', $out_trade_no, 30 );
		if ( false === $lock ) {
			$this->function->showNotifyRespond( false );
		}

		$order = wc_get_order( $order->get_id() );
		$context_check = Alipay_SDK_Helper::validate_notify_context(
			$notify_params,
			$order,
			$main_settings['appid'],
			$order->get_total(),
			(string) $order->get_meta( '_alipay_out_trade_no' ),
			array( 'acbop' ),
			$main_settings['seller_id']
		);
		$transition = is_wp_error( $context_check ) ? $context_check : Alipay_SDK_Helper::validate_notify_transition( $order, $trade_status );
		if ( is_wp_error( $transition ) ) {
			Alipay_SDK_Helper::release_lock( 'notify', $out_trade_no, $lock );
			$this->function->showNotifyRespond( false );
		}

		if ( 'process_payment' === $transition ) {
			$order->payment_complete( $trade_no );
			$order->update_meta_data( 'Alipay Trade No.', $trade_no );
			$order->save();
		} elseif ( 'process_close' === $transition ) {
			$order->update_status( 'cancelled', __( 'Alipay closed the transaction.', 'wei-alipay' ) );
		}

		$this->log( 'Notify processed', array(
			'order_id'     => $order->get_id(),
			'out_trade_no' => $out_trade_no,
			'trade_no'     => $trade_no,
			'trade_status' => $trade_status,
		) );
		Alipay_SDK_Helper::release_lock( 'notify', $out_trade_no, $lock );
		$this->function->showNotifyRespond( true );
	}

    public function thankyou_page($orderId) {
    	// Cleanup unwanted WC response param by AliPay
		$return_params = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signed provider return; verified below.
    	$aliPayResponseParams = $this->function->getAliPayResponseParams($this->account_type);
		foreach ( $return_params as $key => $value ) {
    		if (!in_array($key, $aliPayResponseParams)) {
			unset( $return_params[ $key ] );
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
            echo '<p><strong>' . esc_html__( 'ERROR: This is not redirected back from Alipay', 'wei-alipay' ) . '</strong></p>';
            return;
		}

		// Verify if order id correct
		if ( ! isset( $return_params['out_trade_no'] ) || ! hash_equals( $this->order_prefix . $orderId, sanitize_text_field( (string) $return_params['out_trade_no'] ) ) ) {
			$this->log( 'Invalid order ID! (Oder ID:'.$orderId.')' );
            echo '<p><strong>' . esc_html__( 'ERROR: Invalid order ID!', 'wei-alipay' ) . '</strong></p>';
            return;
		}

		// Proceed success payment
		if ( isset( $return_params['trade_status'] ) && 'TRADE_FINISHED' === sanitize_text_field( (string) $return_params['trade_status'] ) ) {
            if( $order->get_status() != 'completed' && $order->get_status() != 'processing' ){
                $order->payment_complete();
            }                        
            $trade_no = isset( $return_params['trade_no'] ) ? wc_clean( (string) $return_params['trade_no'] ) : '';
            $order->update_meta_data( 'Alipay Trade No.', $trade_no );
            $order->save();
            echo '<ul class="order_details">
				<li>' . esc_html__( 'Your Alipay Trade No.: ', 'wei-alipay' ) . '<strong>' . esc_html( $trade_no ) . '</strong></li>
			</ul>';
			$this->log( 'Return Payment success! (Order ID:'.$orderId.', Alipay Trade No:'.$trade_no.')' );
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
        <h3><?php esc_html_e( 'Alipay Cross Border', 'wei-alipay' ); ?><?php if ( function_exists( 'wc_back_link' ) ) { wc_back_link( __( 'Return to payments', 'wei-alipay' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ); } ?></h3>
        <p><?php esc_html_e( 'Alipay is a simple, secure and fast online payment method, customer can pay via debit card, credit card or alipay balance.', 'wei-alipay' ); ?></p>
        <section class="wei-alipay-settings-notices" aria-label="<?php esc_attr_e( 'Gateway notifications', 'wei-alipay' ); ?>">
            <?php $this->requirement_checks(); ?>
        </section>
       
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
			echo wp_kses_post( wpautop( wptexturize( $description ) ) );
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
			wc_add_notice( __( 'Invalid order.', 'wei-alipay' ), 'error' );
			return array( 'result' => 'fail' );
		}

		require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
		require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
		require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradePagePayRequest.php';

		$missing_fields = $this->get_missing_core_fields();
		if ( ! empty( $missing_fields ) ) {
			/* translators: %s: comma-separated list of missing configuration field names */
			wc_add_notice( sprintf( __( 'Alipay core configuration is incomplete. Missing fields: %s', 'wei-alipay' ), implode( ', ', $missing_fields ) ), 'error' );
			$this->log( 'Payment init blocked: missing core settings', array(
				'missing'  => implode( '|', $missing_fields ),
				'order_id' => $orderId,
			) );
			return array( 'result' => 'fail' );
		}

		$main_settings = $this->get_core_settings();
		$config = Alipay_SDK_Helper::get_alipay_config( $main_settings );

		$aop = Alipay_SDK_Helper::create_alipay_service( $config );
		if ( ! $aop ) {
			wc_add_notice( __( 'Alipay service initialization failed.', 'wei-alipay' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$out_trade_no = $this->order_prefix . ltrim( $order->get_order_number(), '#' );
		$order->update_meta_data( '_alipay_out_trade_no', $out_trade_no );
		$order->save();
		$this->log( 'Payment init', array( 'order_id' => $orderId, 'out_trade_no' => $out_trade_no ) );
		$payment_inst = isset( $_POST['acbop_payment_inst'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['acbop_payment_inst'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout request; order/customer authorization is handled by checkout.
		$biz_content = $this->build_cross_border_biz_content( $order, $out_trade_no, $payment_inst );

		if ( empty( $biz_content['product_code'] ) ) {
			$this->log( 'Unsupported account type for SDK flow', array( 'account_type' => $this->account_type, 'order_id' => $orderId ) );
			wc_add_notice( __( 'Unsupported Alipay account type configuration.', 'wei-alipay' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$request = new AlipayTradePagePayRequest();
		$request->setBizContent( wp_json_encode( $biz_content ) );
		$request->setNotifyUrl( $this->notify_url );
		$request->setReturnUrl( $this->get_return_url( $order ) );

		$redirect = $aop->pageExecute( $request, 'GET' );
		$this->log( 'Payment redirect built', array( 'order_id' => $orderId, 'out_trade_no' => $out_trade_no ) );

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
			/* translators: %s: order number */
			'subject'      => sprintf( __( 'Order #%s', 'wei-alipay' ), $order->get_order_number() ),
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
	 * Record deterministic refund request id when WooCommerce creates a refund object.
	 *
	 * @param WC_Order_Refund $refund Refund object.
	 * @param array           $args   Refund arguments.
	 */
	public function remember_refund_request_id( $refund, $args ) {
		if ( ! $refund instanceof WC_Order_Refund ) {
			return;
		}
		$order = wc_get_order( $refund->get_parent_id() );
		if ( ! $order || $this->id !== $order->get_payment_method() ) {
			return;
		}
		$blog = is_multisite() ? get_current_blog_id() . '-' : '';
		self::$refund_request_id = 'CBDR-' . $blog . $refund->get_id();
	}

	/**
	 * Process an idempotent cross-border refund via AlipayTradeRefundRequest.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Refund amount.
	 * @param string     $reason   Refund reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $this->id !== $order->get_payment_method() ) {
			return new WP_Error( 'invalid_order', __( '退款失败：订单或支付方式不匹配。', 'wei-alipay' ) );
		}

		/** @phpstan-ignore requireOnce.fileNotFound */
		require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
		/** @phpstan-ignore requireOnce.fileNotFound */
		require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradeRefundRequest.php';

		$missing_fields = $this->get_missing_core_fields();
		if ( ! empty( $missing_fields ) ) {
			/* translators: %s: comma-separated list of missing configuration field names */
			return new WP_Error( 'config_missing', sprintf( __( 'Alipay core configuration is incomplete. Missing fields: %s', 'wei-alipay' ), implode( ', ', $missing_fields ) ) );
		}

		$refund_amount = null === $amount ? $order->get_total() : $amount;
		$amount_minor  = Alipay_SDK_Helper::amount_to_minor_units( $refund_amount );
		$total_minor   = Alipay_SDK_Helper::amount_to_minor_units( $order->get_total() );
		if ( false === $amount_minor || false === $total_minor || $amount_minor <= 0 || $amount_minor > $total_minor ) {
			return new WP_Error( 'invalid_refund_amount', __( '退款金额必须大于零且不能超过订单总额。', 'wei-alipay' ) );
		}

		$refund_amount = Alipay_SDK_Helper::format_amount( $refund_amount );
		$refund_reason = $reason ? sanitize_text_field( (string) $reason ) : __( '订单退款', 'wei-alipay' );
		$out_trade_no  = (string) $order->get_meta( '_alipay_out_trade_no' );
		$trade_no      = (string) $order->get_transaction_id();
		if ( '' === $trade_no ) {
			$trade_no = (string) $order->get_meta( 'Alipay Trade No.' );
		}
		if ( '' === $out_trade_no || '' === $trade_no ) {
			return new WP_Error( 'missing_transaction_context', __( '退款失败：缺少原始支付宝订单号或交易号。', 'wei-alipay' ) );
		}

		if ( '' === self::$refund_request_id ) {
			$blog       = is_multisite() ? get_current_blog_id() . '-' : '';
			$fingerprint = substr( hash( 'sha256', $out_trade_no . '|' . $refund_amount . '|' . $refund_reason ), 0, 12 );
			self::$refund_request_id = 'CBDR-' . $blog . $order->get_id() . '-' . $fingerprint;
		}
		$request_id = self::$refund_request_id;

		$lock = Alipay_SDK_Helper::acquire_lock( 'refund', $out_trade_no, 60 );
		if ( false === $lock ) {
			self::$refund_request_id = '';
			return new WP_Error( 'refund_locked', __( '该订单已有退款请求正在处理中，请稍后重试。', 'wei-alipay' ) );
		}

		try {
			$order     = wc_get_order( $order_id );
			$processed = $order ? (array) $order->get_meta( '_alipay_processed_refund_requests', true ) : array();
			if ( in_array( $request_id, $processed, true ) ) {
				return true;
			}

			$main_settings = $this->get_core_settings();
			$config        = Alipay_SDK_Helper::get_alipay_config( $main_settings );
			$aop           = Alipay_SDK_Helper::create_alipay_service( $config );
			if ( ! $aop ) {
				return new WP_Error( 'sdk_error', __( '创建支付宝服务失败', 'wei-alipay' ) );
			}

			$biz = array(
				'out_trade_no'   => $out_trade_no,
				'trade_no'       => $trade_no,
				'refund_amount'  => $refund_amount,
				'refund_reason'  => $refund_reason,
				'out_request_no' => $request_id,
			);
			if ( method_exists( $order, 'get_currency' ) && $order->get_currency() ) {
				$biz['refund_currency'] = $order->get_currency();
			}

			$request = new AlipayTradeRefundRequest();
			$request->setBizContent( wp_json_encode( $biz ) );
			$response = $aop->execute( $request );
			$node     = 'alipay_trade_refund_response';
			$result   = isset( $response->$node ) ? $response->$node : null;

			if ( ! is_object( $result ) || ! isset( $result->code ) || '10000' !== (string) $result->code ) {
				$message = is_object( $result ) ? ( $result->sub_msg ?? $result->msg ?? __( '退款失败', 'wei-alipay' ) ) : __( '退款失败：支付宝返回为空。', 'wei-alipay' );
				$this->log( 'Refund failed', array( 'order_id' => $order_id, 'out_request_no' => $request_id, 'error' => (string) $message ) );
				return new WP_Error( 'refund_failed', (string) $message );
			}

			if ( ! isset( $result->out_trade_no, $result->trade_no, $result->refund_fee )
				|| ! hash_equals( $out_trade_no, (string) $result->out_trade_no )
				|| ! hash_equals( $trade_no, (string) $result->trade_no )
				|| ! Alipay_SDK_Helper::amounts_equal( $refund_amount, $result->refund_fee ) ) {
				$this->log( 'Refund context mismatch', array( 'order_id' => $order_id, 'out_request_no' => $request_id ) );
				return new WP_Error( 'refund_context_mismatch', __( '支付宝退款响应与原退款请求不匹配。', 'wei-alipay' ) );
			}

			$processed[] = $request_id;
			$order->update_meta_data( '_alipay_processed_refund_requests', array_values( array_unique( $processed ) ) );
			$order->save();
			/* translators: %s: refunded amount */
			$order->add_order_note( sprintf( __( '跨境支付宝退款成功，退款金额：%s', 'wei-alipay' ), $refund_amount ) );
			$this->log( 'Refund completed', array( 'order_id' => $order_id, 'out_request_no' => $request_id, 'amount' => $refund_amount ) );
			return true;
		} catch ( Exception $e ) {
			$this->log( 'Refund exception', array( 'order_id' => $order_id, 'error' => $e->getMessage() ) );
			return new WP_Error( 'refund_exception', $e->getMessage() );
		} finally {
			Alipay_SDK_Helper::release_lock( 'refund', $out_trade_no, $lock );
			self::$refund_request_id = '';
		}
	}

	/**
	 * Logging method.
	 *
	 * @param string $message Log message.
	 * @param array  $context Structured context fields.
	 */
	public static function log( $message, $context = array() ) {
		if ( self::$log_enabled ) {
			if ( empty( self::$log ) ) {
				self::$log = new WC_Logger();
			}

			$pairs = array();
			foreach ( $context as $key => $value ) {
				if ( '' === (string) $value ) {
					continue;
				}
				$pairs[] = $key . '=' . $value;
			}

			$line = $message;
			if ( ! empty( $pairs ) ) {
				$line .= ' [' . implode( ', ', $pairs ) . ']';
			}

			self::$log->add( 'acbop', $line );
		}
	}
}
