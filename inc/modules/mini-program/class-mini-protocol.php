<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Explicit protocol policy for the historical Mini-program/WAP gateway. */
final class Wei_Alipay_Mini_Protocol {
	const RSA2       = 'rsa2';
	const LEGACY_MD5 = 'legacy_md5';

	public static function resolve( $configured, $legacy_acknowledged = false, $legacy_allowed = false ) {
		$mode = sanitize_key( (string) $configured );
		if ( '' === $mode || self::RSA2 === $mode ) {
			return self::RSA2;
		}
		if ( self::LEGACY_MD5 !== $mode ) {
			return new WP_Error( 'unsupported_mini_protocol', __( 'Unsupported Alipay Mini-program protocol.', 'wei-alipay' ) );
		}
		if ( ! $legacy_acknowledged || ! $legacy_allowed ) {
			return new WP_Error( 'legacy_md5_policy_required', __( 'Legacy MD5 requires both an explicit site acknowledgement and an operator policy.', 'wei-alipay' ) );
		}
		return self::LEGACY_MD5;
	}

	public static function legacy_allowed() {
		$constant = defined( 'WEI_ALIPAY_ALLOW_LEGACY_MD5' ) && true === WEI_ALIPAY_ALLOW_LEGACY_MD5;
		return (bool) apply_filters( 'woo_alipay_allow_legacy_md5', $constant );
	}

	/** No automatic fallback is allowed after an RSA2 build or verify failure. */
	public static function fallback_mode( $selected_mode, $failure ) {
		return new WP_Error( 'protocol_fallback_forbidden', __( 'Automatic Alipay protocol fallback is forbidden.', 'wei-alipay' ) );
	}

	public static function sign_legacy_md5( $params, $secret ) {
		$filtered = array();
		foreach ( (array) $params as $key => $value ) {
			if ( 'sign' === $key || 'sign_type' === $key || '' === (string) $value || is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$filtered[ (string) $key ] = (string) $value;
		}
		ksort( $filtered, SORT_STRING );
		$pairs = array();
		foreach ( $filtered as $key => $value ) {
			$pairs[] = $key . '=' . $value;
		}
		return md5( implode( '&', $pairs ) . (string) $secret );
	}

	public static function verify_legacy_md5( $params, $secret ) {
		if ( ! isset( $params['sign'], $params['sign_type'] ) || ! is_string( $params['sign'] ) || 'MD5' !== strtoupper( (string) $params['sign_type'] ) || '' === (string) $secret ) {
			return false;
		}
		return hash_equals( self::sign_legacy_md5( $params, $secret ), strtolower( $params['sign'] ) );
	}

	/** Bind a verified legacy callback to the configured merchant identities. */
	public static function validate_legacy_identity( $params, $expected_partner, $expected_seller ) {
		$partner = isset( $params['partner'] ) && is_string( $params['partner'] ) ? trim( $params['partner'] ) : '';
		$seller  = isset( $params['seller_id'] ) && is_string( $params['seller_id'] ) ? trim( $params['seller_id'] ) : '';
		if ( '' === $partner || '' === (string) $expected_partner || ! hash_equals( (string) $expected_partner, $partner ) ) {
			return new WP_Error( 'partner_mismatch', __( 'Alipay partner ID does not match.', 'wei-alipay' ) );
		}
		if ( '' === $seller || '' === (string) $expected_seller || ! hash_equals( (string) $expected_seller, $seller ) ) {
			return new WP_Error( 'seller_id_mismatch', __( 'Alipay seller ID does not match.', 'wei-alipay' ) );
		}
		return true;
	}
}
