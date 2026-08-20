<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Alipay_Agreement {
	const META_USER_AGREEMENT_NO = '_alipay_agreement_no';
	const META_USER_AGREEMENT_STATUS = '_alipay_agreement_status';
	const META_USER_PENDING_EXTERNAL_SIGN_NO = '_alipay_pending_external_sign_no';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_api_endpoints' ] );
	}

	public static function register_api_endpoints() {
		add_action( 'woocommerce_api_wc_alipay_agreement_notify', [ __CLASS__, 'handle_agreement_notify' ] );
		add_action( 'woocommerce_api_wc_alipay_agreement_return', [ __CLASS__, 'handle_agreement_return' ] );
		add_action( 'woocommerce_api_wc_alipay_agreement_start', [ __CLASS__, 'handle_agreement_start' ] );
	}

	public static function get_sign_url( $user_id = 0, $context = [] ) {
		$gateway = new WC_Alipay( false );
		$notify_url = add_query_arg( [], WC()->api_request_url( 'WC_Alipay_Agreement_Notify' ) );
		$return_url = add_query_arg( [], WC()->api_request_url( 'WC_Alipay_Agreement_Return' ) );

		require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
		require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayUserAgreementPageSignRequest.php';

		$aop = new AopClient();
		$aop->gatewayUrl        = ( 'yes' === $gateway->get_option( 'sandbox' ) ) ? WC_Alipay::GATEWAY_SANDBOX_URL : WC_Alipay::GATEWAY_URL;
		$aop->appId             = $gateway->get_option( 'appid' );
		$aop->rsaPrivateKey     = $gateway->get_option( 'private_key' );
		$aop->alipayrsaPublicKey= $gateway->get_option( 'public_key' );
		$aop->charset           = 'utf-8';
		$aop->signType          = 'RSA2';

		$external_sign_no = 'AGREE' . ( $user_id ? $user_id : get_current_user_id() ) . '-' . current_time( 'timestamp' );
		// 建立 external_sign_no 到用户的临时映射
		if ( $user_id ) {
			set_transient( 'woo_alipay_pending_user_' . $external_sign_no, absint( $user_id ), DAY_IN_SECONDS );
			update_user_meta( $user_id, self::META_USER_PENDING_EXTERNAL_SIGN_NO, $external_sign_no );
		}

		$personal_product_code = apply_filters( 'woo_alipay_agreement_personal_product_code', 'CYCLE_PAY_AUTH', $user_id );
		$sign_scene            = apply_filters( 'woo_alipay_agreement_sign_scene', 'INDUSTRY|DEFAULT', $user_id );

		$biz = [
			'external_sign_no'      => $external_sign_no,
			'personal_product_code' => $personal_product_code,
			'sign_scene'            => $sign_scene,
			'access_params'         => [ 'channel' => 'ALIPAYAPP' ],
			'merchant_process_url'  => $return_url,
			'notify_url'            => $notify_url,
		];
		if ( ! empty( $context['return_url'] ) ) {
			$validated_return_url = wp_validate_redirect( (string) $context['return_url'], '' );
			if ( '' !== $validated_return_url && wp_parse_url( home_url(), PHP_URL_HOST ) === wp_parse_url( $validated_return_url, PHP_URL_HOST ) ) {
				$biz['merchant_process_url'] = $validated_return_url;
			}
		}

		$request = new AlipayUserAgreementPageSignRequest();
		$request->setBizContent( wp_json_encode( $biz ) );
		$request->setNotifyUrl( $notify_url );
		$request->setReturnUrl( $return_url );

		// pageExecute 返回跳转 URL/表单
		$sign_form_or_url = $aop->pageExecute( $request );
		return [ 'payload' => $sign_form_or_url, 'external_sign_no' => $external_sign_no ];
	}

	public static function handle_agreement_return() {
		// 用户浏览器跳转回站点，可用于展示成功页面；协议最终以异步通知为准
		wp_safe_redirect( home_url() );
		exit;
	}

	public static function handle_agreement_notify() {
		$gateway = new WC_Alipay( false );
		require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
		$aop = new AopClient();
		$aop->alipayrsaPublicKey= $gateway->get_option( 'public_key' );
		$aop->charset           = 'utf-8';
		$aop->signType          = 'RSA2';

		$params = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- signed Alipay server callback; verified before fields are used.
		$verified = $aop->rsaCheckV1( $params, $aop->alipayrsaPublicKey, $aop->signType );
		if ( ! $verified ) {
			echo 'fail';
			exit;
		}

		$expected_app_id = (string) $gateway->get_option( 'appid' );
		$notified_app_id = isset( $params['app_id'] ) && is_scalar( $params['app_id'] ) ? sanitize_text_field( (string) $params['app_id'] ) : '';
		if ( '' === $expected_app_id || '' === $notified_app_id || ! hash_equals( $expected_app_id, $notified_app_id ) ) {
			status_header( 400 );
			echo 'fail';
			exit;
		}
		$expected_seller_id = (string) $gateway->get_option( 'seller_id' );
		$notified_seller_id = isset( $params['seller_id'] ) && is_scalar( $params['seller_id'] ) ? sanitize_text_field( (string) $params['seller_id'] ) : '';
		if ( '' !== $expected_seller_id && ( '' === $notified_seller_id || ! hash_equals( $expected_seller_id, $notified_seller_id ) ) ) {
			status_header( 400 );
			echo 'fail';
			exit;
		}

		$agreement_no = isset( $params['agreement_no'] ) ? sanitize_text_field( $params['agreement_no'] ) : '';
		$alipay_user_id = isset( $params['alipay_user_id'] ) ? sanitize_text_field( $params['alipay_user_id'] ) : '';
		$status = isset( $params['status'] ) ? sanitize_text_field( $params['status'] ) : '';
		$external_sign_no = isset( $params['external_sign_no'] ) ? sanitize_text_field( $params['external_sign_no'] ) : '';

		// 根据 external_sign_no 还原用户
		$user_id = 0;
		if ( $external_sign_no ) {
			$user_id = absint( get_transient( 'woo_alipay_pending_user_' . $external_sign_no ) );
		}
		if ( $user_id ) {
			$normalized_status = strtoupper( $status );
			update_user_meta( $user_id, self::META_USER_AGREEMENT_STATUS, $normalized_status );
			if ( '' !== $agreement_no && in_array( $normalized_status, [ 'NORMAL', 'SUCCESS' ], true ) ) {
				update_user_meta( $user_id, self::META_USER_AGREEMENT_NO, $agreement_no );
			} else {
				delete_user_meta( $user_id, self::META_USER_AGREEMENT_NO );
			}
			delete_transient( 'woo_alipay_pending_user_' . $external_sign_no );
			delete_user_meta( $user_id, self::META_USER_PENDING_EXTERNAL_SIGN_NO );
		}

		echo 'success';
		exit;
	}

	public static function get_user_agreement_no( $user_id ) {
		return get_user_meta( $user_id, self::META_USER_AGREEMENT_NO, true );
	}

	public static function has_user_agreement( $user_id ) {
		return (bool) self::get_user_agreement_no( $user_id );
	}

	public static function handle_agreement_start() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( '请先登录后再进行签约授权。', 'wei-alipay' ) );
		}
		$gateway_settings = get_option( 'woocommerce_alipay_settings', [] );
		if ( 'yes' !== ( $gateway_settings['enable_auto_renew'] ?? 'no' ) ) {
			wp_die( esc_html__( 'Alipay automatic renewal is not enabled.', 'wei-alipay' ), '', [ 'response' => 403 ] );
		}
		$user_id = get_current_user_id();
		$nonce = isset( $_GET['_wpnonce'] ) && is_scalar( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'woo_alipay_agreement_start_' . $user_id ) ) {
			wp_die( esc_html__( 'Invalid or expired signing request.', 'wei-alipay' ), '', [ 'response' => 403 ] );
		}
		$context = [];
		if ( isset( $_GET['return_url'] ) && is_scalar( $_GET['return_url'] ) ) {
			$context['return_url'] = esc_url_raw( wp_unslash( (string) $_GET['return_url'] ) );
		}
		$result = self::get_sign_url( $user_id, $context );
		if ( is_array( $result ) && ! empty( $result['payload'] ) ) {
			// 输出表单/HTML，通常将自动跳转到支付宝
			echo Alipay_SDK_Helper::sanitize_payment_form( $result['payload'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized against a payment-form-only allowlist.
			exit;
		}
		wp_die( esc_html__( '无法生成签约请求，请稍后重试。', 'wei-alipay' ) );
	}

}

WC_Alipay_Agreement::init();
