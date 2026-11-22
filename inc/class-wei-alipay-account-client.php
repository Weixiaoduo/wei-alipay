<?php
/**
 * Weixiaoduo site-link client (Connect-Latch minimal model).
 *
 * Connects this WordPress site to account.weixiaoduo.com via PKCE S256
 * (`/connect/wei-alipay` + `/wp-json/wei-account/v1/site-links/{exchange,status,disconnect}`).
 *
 * Connection identifies the site owner only; it never issues commercial grants
 * and never stops already-enabled payment methods if disconnected or unreachable.
 *
 * @package WeiAlipay
 * @since   4.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Wei_Alipay_Account_Client {

	const OPTION_KEY       = 'wei_alipay_account_link';
	const PRODUCT_SLUG     = 'wei-alipay';
	const DEFAULT_BASE_URL = 'https://account.weixiaoduo.com';
	const PKCE_PREFIX      = 'wei_alipay_pkce_';

	/**
	 * Register admin-post handlers for connect, callback, refresh and disconnect.
	 */
	public static function register() {
		add_action( 'admin_post_wei_alipay_connect_account', array( __CLASS__, 'handle_connect_start' ) );
		add_action( 'admin_post_wei_alipay_connect_callback', array( __CLASS__, 'handle_connect_callback' ) );
		add_action( 'admin_post_wei_alipay_disconnect_account', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_wei_alipay_refresh_account', array( __CLASS__, 'handle_refresh' ) );
	}

	/**
	 * Return the configured Weixiaoduo Account base URL.
	 *
	 * @return string
	 */
	public static function base_url() {
		$url = (string) apply_filters( 'wei_alipay_account_base_url', self::DEFAULT_BASE_URL );
		$url = rtrim( trim( $url ), '/' );
		return '' !== $url ? $url : self::DEFAULT_BASE_URL;
	}

	/**
	 * Return or create the stable 32-char ASCII installation identifier for this site.
	 *
	 * @return string
	 */
	public static function installation_id() {
		$data = self::stored_data();
		if ( ! empty( $data['installation_id'] ) && is_string( $data['installation_id'] ) ) {
			return $data['installation_id'];
		}
		try {
			$id = bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			$id = md5( home_url( '/' ) . '|' . wp_generate_password( 32, false ) );
		}
		$data['installation_id'] = $id;
		update_option( self::OPTION_KEY, $data, false );
		return $id;
	}

	/**
	 * Compute PKCE S256 code_challenge from a code_verifier.
	 *
	 * @param string $verifier Code verifier.
	 * @return string Base64URL-encoded SHA-256 hash without padding.
	 */
	public static function pkce_challenge( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', (string) $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Return the site origin (scheme://host[:port]).
	 *
	 * @return string
	 */
	public static function site_origin() {
		$parts = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return rtrim( home_url( '/' ), '/' );
		}
		$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}

	/**
	 * Build nonce-protected admin-post URL to start the PKCE connection flow.
	 *
	 * @return string
	 */
	public static function connect_action_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=wei_alipay_connect_account' ),
			'wei_alipay_connect_account'
		);
	}

	/**
	 * Build nonce-protected admin-post URL to disconnect this site.
	 *
	 * @return string
	 */
	public static function disconnect_action_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=wei_alipay_disconnect_account' ),
			'wei_alipay_disconnect_account'
		);
	}

	/**
	 * Start PKCE S256 redirect to account.weixiaoduo.com/connect/wei-alipay.
	 */
	public static function handle_connect_start() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to connect Wei Alipay.', 'wei-alipay' ) );
		}
		check_admin_referer( 'wei_alipay_connect_account' );

		try {
			$verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
			$state    = bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			$verifier = wp_generate_password( 64, false, false );
			$state    = md5( wp_generate_password( 32, true, true ) . microtime( true ) );
		}

		$installation_id = self::installation_id();
		set_transient(
			self::PKCE_PREFIX . $state,
			array(
				'verifier'        => $verifier,
				'user_id'         => get_current_user_id(),
				'installation_id' => $installation_id,
			),
			10 * MINUTE_IN_SECONDS
		);

		$redirect_uri = admin_url( 'admin-post.php?action=wei_alipay_connect_callback' );
		$authorize    = add_query_arg(
			array(
				'site_origin'           => self::site_origin(),
				'site_name'             => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'installation_id'       => $installation_id,
				'redirect_uri'          => $redirect_uri,
				'code_challenge'        => self::pkce_challenge( $verifier ),
				'code_challenge_method' => 'S256',
				'state'                 => $state,
			),
			self::base_url() . '/connect/' . rawurlencode( self::PRODUCT_SLUG )
		);

		// Redirect to the configured Weixiaoduo Account origin.
		wp_redirect( esc_url_raw( $authorize ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- intentional cross-origin redirect to account.weixiaoduo.com.
		exit;
	}

	/**
	 * Handle redirect callback from /connect/wei-alipay and exchange PKCE code for token.
	 */
	public static function handle_connect_callback() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to complete Wei Alipay connection.', 'wei-alipay' ) );
		}

		$account_page = admin_url( 'admin.php?page=wei-alipay&view=account' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth/PKCE callback validated by single-use state transient below.
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth/PKCE callback validated by single-use state transient below.
		$state = isset( $_GET['state'] ) ? preg_replace( '/[^a-f0-9]/', '', strtolower( sanitize_text_field( wp_unslash( $_GET['state'] ) ) ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth/PKCE callback validated by single-use state transient below.
		$code = isset( $_GET['code'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['code'] ) ) ) : '';

		if ( '' === $state ) {
			wp_safe_redirect( add_query_arg( 'wei_alipay_connect', 'invalid_state', $account_page ) );
			exit;
		}

		$pkce_key = self::PKCE_PREFIX . $state;
		$pending  = get_transient( $pkce_key );
		delete_transient( $pkce_key );

		if ( ! is_array( $pending ) || (int) ( $pending['user_id'] ?? 0 ) !== get_current_user_id() ) {
			wp_safe_redirect( add_query_arg( 'wei_alipay_connect', 'invalid_state', $account_page ) );
			exit;
		}

		if ( '' !== $error ) {
			wp_safe_redirect( add_query_arg( 'wei_alipay_connect', 'access_denied' === $error ? 'cancelled' : 'failed', $account_page ) );
			exit;
		}

		if ( '' === $code || empty( $pending['verifier'] ) || empty( $pending['installation_id'] ) ) {
			wp_safe_redirect( add_query_arg( 'wei_alipay_connect', 'failed', $account_page ) );
			exit;
		}

		$response = wp_remote_post(
			self::base_url() . '/wp-json/wei-account/v1/site-links/exchange',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'code'            => $code,
						'code_verifier'   => (string) $pending['verifier'],
						'installation_id' => (string) $pending['installation_id'],
						'product'         => self::PRODUCT_SLUG,
					)
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			wp_safe_redirect( add_query_arg( 'wei_alipay_connect', 'exchange_failed', $account_page ) );
			exit;
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data    = is_array( $payload ) && isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : $payload;
		if ( ! is_array( $data ) || empty( $data['token'] ) || ! is_string( $data['token'] ) ) {
			wp_safe_redirect( add_query_arg( 'wei_alipay_connect', 'exchange_failed', $account_page ) );
			exit;
		}

		self::persist_connection( $data, (string) $pending['installation_id'] );
		wp_safe_redirect( add_query_arg( 'wei_alipay_connect', 'connected', $account_page ) );
		exit;
	}

	/**
	 * Handle explicit disconnect request from admin UI.
	 * Never disables already-enabled payment features (latch rule).
	 */
	public static function handle_disconnect() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to disconnect Wei Alipay.', 'wei-alipay' ) );
		}
		check_admin_referer( 'wei_alipay_disconnect_account' );

		$token = self::decrypt_stored_token();
		if ( '' !== $token ) {
			wp_remote_post(
				self::base_url() . '/wp-json/wei-account/v1/site-links/disconnect',
				array(
					'timeout' => 10,
					'headers' => array(
						'Accept'        => 'application/json',
						'Authorization' => 'Bearer ' . $token,
					),
				)
			);
		}

		self::clear_local_connection();
		wp_safe_redirect( add_query_arg( 'wei_alipay_connect', 'disconnected', admin_url( 'admin.php?page=wei-alipay&view=account' ) ) );
		exit;
	}

	/**
	 * Manually refresh connection status against account.weixiaoduo.com.
	 */
	public static function handle_refresh() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to refresh Wei Alipay connection.', 'wei-alipay' ) );
		}
		check_admin_referer( 'wei_alipay_refresh_account' );

		$result = self::refresh_status();
		$flag   = is_wp_error( $result ) ? 'refresh_failed' : ( $result ? 'refreshed' : 'disconnected' );
		wp_safe_redirect( add_query_arg( 'wei_alipay_connect', $flag, admin_url( 'admin.php?page=wei-alipay&view=account' ) ) );
		exit;
	}

	/**
	 * Query `/site-links/status`. A 401 marks this site disconnected (preserving latches);
	 * transient network/5xx errors keep the last known state.
	 *
	 * @return bool|WP_Error True if connected, false if revoked (401), WP_Error on transport failure.
	 */
	public static function refresh_status() {
		$token = self::decrypt_stored_token();
		if ( '' === $token ) {
			return false;
		}

		$response = wp_remote_get(
			self::base_url() . '/wp-json/wei-account/v1/site-links/status',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		// Only HTTP 401 from wei-account indicates token revocation; 403 may come from WAF/CDN and must keep last state.
		if ( 401 === $code ) {
			self::clear_local_connection();
			return false;
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'site_link_status_error', sprintf( 'Unexpected status HTTP %d', $code ) );
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data    = is_array( $payload ) && isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : $payload;
		if ( is_array( $data ) && ! empty( $data['connected'] ) ) {
			$stored                  = self::stored_data();
			$stored['last_sync_at']  = current_time( 'mysql' );
			if ( isset( $data['account'] ) && is_array( $data['account'] ) ) {
				$stored['account_label'] = self::extract_account_label( $data['account'] );
				$stored['avatar_url']    = self::sanitize_cravatar_url( $data['account']['avatarUrl'] ?? ( $data['account']['avatar_url'] ?? '' ) );
			}
			update_option( self::OPTION_KEY, $stored, false );
			return true;
		}

		return true;
	}

	/**
	 * Return presentation-safe connection state for Wei_Alipay_Features and admin views.
	 *
	 * @return array<string,string>
	 */
	public static function account_state() {
		$stored    = self::stored_data();
		$connected = 'connected' === ( $stored['status'] ?? '' ) && ! empty( $stored['token'] );

		$state = array(
			'status'             => $connected ? 'connected' : 'not-connected',
			'status_label'       => $connected ? __( 'Connected', 'wei-alipay' ) : __( 'Not connected', 'wei-alipay' ),
			'status_class'       => $connected ? 'success' : 'light',
			'account_label'      => $connected ? (string) ( $stored['account_label'] ?? __( 'Weixiaoduo account', 'wei-alipay' ) ) : '',
			'installation_label' => ! empty( $stored['installation_id'] ) ? (string) $stored['installation_id'] : __( 'Created when connected', 'wei-alipay' ),
			'last_sync_label'    => ! empty( $stored['last_sync_at'] ) ? (string) $stored['last_sync_at'] : __( 'Never', 'wei-alipay' ),
			'connect_url'        => self::connect_action_url(),
			'manage_url'         => $connected ? ( self::base_url() . '/settings/connected-sites' ) : '',
			'disconnect_url'     => $connected ? self::disconnect_action_url() : '',
			'avatar_url'         => $connected ? self::sanitize_cravatar_url( $stored['avatar_url'] ?? '' ) : '',
			'avatar_alt'         => __( 'Weixiaoduo account avatar', 'wei-alipay' ),
		);

		$filtered = apply_filters( 'wei_alipay_account_state', $state );
		if ( ! is_array( $filtered ) ) {
			return $state;
		}
		$merged               = array_merge( $state, array_intersect_key( $filtered, $state ) );
		$merged['avatar_url'] = self::sanitize_cravatar_url( $merged['avatar_url'] );
		return $merged;
	}

	/**
	 * Accept only HTTPS Cravatar URLs from sanctioned hosts (never cravatar.cn).
	 *
	 * @param mixed $url Candidate avatar URL.
	 * @return string
	 */
	public static function sanitize_cravatar_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return '';
		}
		$allowed_hosts = apply_filters( 'wei_alipay_cravatar_hosts', array( 'cravatar.com', 'www.cravatar.com', 'cn.cravatar.com' ) );
		$allowed_hosts = is_array( $allowed_hosts ) ? array_map( 'strtolower', array_map( 'strval', $allowed_hosts ) ) : array();
		if ( ! in_array( strtolower( $parts['host'] ), $allowed_hosts, true ) ) {
			return '';
		}
		return esc_url_raw( $url, array( 'https' ) );
	}

	/**
	 * Persist successful exchange response with encrypted connection token.
	 *
	 * @param array<string,mixed> $data            Exchange payload.
	 * @param string              $installation_id Installation identifier.
	 */
	private static function persist_connection( array $data, $installation_id ) {
		$stored_token = (string) $data['token'];
		if ( class_exists( 'Wei_Alipay_Secret_Store' ) ) {
			$material = self::token_key_material();
			if ( '' !== $material ) {
				$encrypted = Wei_Alipay_Secret_Store::encrypt_value( $stored_token, $material );
				if ( is_string( $encrypted ) && '' !== $encrypted ) {
					$stored_token = $encrypted;
				}
			}
		}

		$account = isset( $data['account'] ) && is_array( $data['account'] ) ? $data['account'] : array();
		$record  = array(
			'status'          => 'connected',
			'link_id'         => isset( $data['linkId'] ) ? absint( $data['linkId'] ) : 0,
			'token'           => $stored_token,
			'installation_id' => $installation_id,
			'site_origin'     => self::site_origin(),
			'connected_at'    => isset( $data['connectedAt'] ) ? sanitize_text_field( (string) $data['connectedAt'] ) : current_time( 'mysql' ),
			'last_sync_at'    => current_time( 'mysql' ),
			'account_label'   => self::extract_account_label( $account ),
			'avatar_url'      => self::sanitize_cravatar_url( $account['avatarUrl'] ?? ( $account['avatar_url'] ?? '' ) ),
		);
		update_option( self::OPTION_KEY, $record, false );
	}

	/**
	 * Clear local connection token while preserving installation_id and enabled feature latches.
	 */
	private static function clear_local_connection() {
		$stored                    = self::stored_data();
		$stored['status']          = 'not-connected';
		$stored['token']           = '';
		$stored['link_id']         = 0;
		$stored['account_label']   = '';
		$stored['avatar_url']      = '';
		update_option( self::OPTION_KEY, $stored, false );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function stored_data() {
		$raw = get_option( self::OPTION_KEY, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @return string
	 */
	private static function decrypt_stored_token() {
		$stored = self::stored_data();
		$token  = isset( $stored['token'] ) && is_string( $stored['token'] ) ? $stored['token'] : '';
		if ( '' === $token ) {
			return '';
		}
		if ( class_exists( 'Wei_Alipay_Secret_Store' ) && 0 === strpos( $token, Wei_Alipay_Secret_Store::PREFIX ) ) {
			$material = self::token_key_material();
			if ( '' === $material ) {
				return '';
			}
			$plain = Wei_Alipay_Secret_Store::decrypt_value( $token, array( $material ) );
			return is_string( $plain ) ? $plain : '';
		}
		return $token;
	}

	/**
	 * @return string
	 */
	private static function token_key_material() {
		if ( defined( 'WEI_ALIPAY_ENCRYPTION_KEY' ) && is_string( WEI_ALIPAY_ENCRYPTION_KEY ) && '' !== WEI_ALIPAY_ENCRYPTION_KEY ) {
			return WEI_ALIPAY_ENCRYPTION_KEY;
		}
		if ( defined( 'WOO_ALIPAY_ENCRYPTION_KEY' ) && is_string( WOO_ALIPAY_ENCRYPTION_KEY ) && '' !== WOO_ALIPAY_ENCRYPTION_KEY ) {
			return WOO_ALIPAY_ENCRYPTION_KEY;
		}
		if ( defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) ) {
			return AUTH_KEY . '|' . SECURE_AUTH_KEY;
		}
		return '';
	}

	/**
	 * @param array<string,mixed> $account Account summary from wei-account.
	 * @return string
	 */
	private static function extract_account_label( array $account ) {
		foreach ( array( 'maskedEmail', 'emailMasked', 'displayName', 'label', 'email' ) as $key ) {
			if ( ! empty( $account[ $key ] ) && is_string( $account[ $key ] ) ) {
				return sanitize_text_field( $account[ $key ] );
			}
		}
		return __( 'Weixiaoduo account', 'wei-alipay' );
	}
}
