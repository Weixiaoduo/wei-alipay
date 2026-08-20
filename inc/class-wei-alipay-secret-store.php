<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Versioned encryption for merchant secrets stored in WordPress options.
 *
 * Public Alipay verification keys are intentionally not encrypted: they are
 * not confidential and must remain available to verify notifications. Private
 * application keys and legacy MD5 shared secrets are encrypted at rest.
 */
final class Wei_Alipay_Secret_Store {
	const PREFIX          = 'woo-alipay-secret:v1:';
	const ROLLBACK_OPTION = 'woo_alipay_secret_rollback_v1';

	/** @var array<string,array<int,string>> */
	private static $errors = array();

	private static $option_fields = array(
		'woocommerce_alipay_settings'     => array( 'private_key' ),
		'woocommerce_alipay_wap_settings' => array( 'secure_key' ),
		'woocommerce_acbop_settings'      => array( 'secure_key' ),
	);

	public static function register() {
		foreach ( self::$option_fields as $option => $fields ) {
			add_filter( 'option_' . $option, array( __CLASS__, 'decrypt_option' ), 10, 2 );
			add_filter( 'pre_update_option_' . $option, array( __CLASS__, 'encrypt_option' ), 10, 3 );
		}
		add_action( 'woo_alipay_secret_error', array( __CLASS__, 'remember_error' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'render_health_notice' ) );
	}

	public static function encrypt_value( $plaintext, $material ) {
		if ( '' === (string) $plaintext || self::is_envelope( $plaintext ) ) {
			return (string) $plaintext;
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new WP_Error( 'secret_crypto_unavailable', __( 'OpenSSL is required to protect Alipay secrets.', 'wei-alipay' ) );
		}

		$key = self::derive_key( $material );
		$iv  = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( (string) $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'woo-alipay:v1' );
		if ( false === $ciphertext ) {
			return new WP_Error( 'secret_encrypt_failed', __( 'Alipay secret encryption failed.', 'wei-alipay' ) );
		}

		$payload = array(
			'kid' => substr( hash( 'sha256', (string) $material ), 0, 16 ),
			'iv'  => base64_encode( $iv ),
			'tag' => base64_encode( $tag ),
			'ct'  => base64_encode( $ciphertext ),
		);
		return self::PREFIX . base64_encode( wp_json_encode( $payload ) );
	}

	public static function decrypt_value( $value, $materials ) {
		if ( ! self::is_envelope( $value ) ) {
			return (string) $value;
		}
		$encoded = substr( (string) $value, strlen( self::PREFIX ) );
		$payload = json_decode( (string) base64_decode( $encoded, true ), true );
		if ( ! is_array( $payload ) || ! isset( $payload['iv'], $payload['tag'], $payload['ct'] ) ) {
			return new WP_Error( 'secret_envelope_invalid', __( 'Stored Alipay secret is malformed.', 'wei-alipay' ) );
		}

		foreach ( array_values( array_unique( array_filter( (array) $materials ) ) ) as $material ) {
			$plaintext = openssl_decrypt(
				(string) base64_decode( (string) $payload['ct'], true ),
				'aes-256-gcm',
				self::derive_key( $material ),
				OPENSSL_RAW_DATA,
				(string) base64_decode( (string) $payload['iv'], true ),
				(string) base64_decode( (string) $payload['tag'], true ),
				'woo-alipay:v1'
			);
			if ( false !== $plaintext ) {
				return $plaintext;
			}
		}

		return new WP_Error( 'secret_decrypt_failed', __( 'Stored Alipay secret cannot be decrypted with the configured key ring.', 'wei-alipay' ) );
	}

	public static function decrypt_option( $settings, $option ) {
		if ( ! is_array( $settings ) || ! isset( self::$option_fields[ $option ] ) ) {
			return $settings;
		}
		foreach ( self::$option_fields[ $option ] as $field ) {
			if ( self::is_settings_screen() ) {
				$settings[ $field ] = '';
				continue;
			}
			$external = apply_filters( 'woo_alipay_secret_value', null, $option, $field );
			if ( is_string( $external ) && '' !== $external ) {
				$settings[ $field ] = $external;
				continue;
			}
			if ( empty( $settings[ $field ] ) ) {
				continue;
			}
			$value = self::decrypt_value( $settings[ $field ], self::keyring() );
			if ( is_wp_error( $value ) ) {
				$settings[ $field ] = '';
				do_action( 'woo_alipay_secret_error', $value, $option, $field );
			} else {
				$settings[ $field ] = $value;
			}
		}
		return $settings;
	}

	public static function encrypt_option( $new_value, $old_value, $option ) {
		if ( ! is_array( $new_value ) || ! isset( self::$option_fields[ $option ] ) ) {
			return $new_value;
		}
		$material = self::active_material();
		if ( is_wp_error( $material ) ) {
			do_action( 'woo_alipay_secret_error', $material, $option, '' );
			return $old_value;
		}
		$raw_old = self::raw_option( $option );
		foreach ( self::$option_fields[ $option ] as $field ) {
			if ( ! isset( $new_value[ $field ] ) || '' === (string) $new_value[ $field ] ) {
				if ( is_array( $raw_old ) && ! empty( $raw_old[ $field ] ) ) {
					$new_value[ $field ] = $raw_old[ $field ];
				} elseif ( is_array( $old_value ) && ! empty( $old_value[ $field ] ) ) {
					$new_value[ $field ] = $old_value[ $field ];
				}
				continue;
			}
			$encrypted = self::encrypt_value( $new_value[ $field ], $material );
			if ( is_wp_error( $encrypted ) ) {
				do_action( 'woo_alipay_secret_error', $encrypted, $option, $field );
				return $old_value;
			}
			$new_value[ $field ] = $encrypted;
		}
		return $new_value;
	}

	/**
	 * Encrypt plaintext and rotate envelopes to the active key.
	 *
	 * The previous encrypted values are saved as a single rollback generation.
	 * Plaintext is encrypted before it reaches the rollback option. Validation is
	 * completed for every configured secret before any gateway option is changed.
	 *
	 * @return true|WP_Error
	 */
	public static function migrate_current_site() {
		$active = self::active_material();
		if ( is_wp_error( $active ) ) {
			return $active;
		}

		$updates  = array();
		$rollback = array();
		foreach ( self::$option_fields as $option => $fields ) {
			$stored = self::raw_option( $option );
			if ( ! is_array( $stored ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				if ( empty( $stored[ $field ] ) ) {
					continue;
				}
				$plaintext = self::decrypt_value( $stored[ $field ], self::keyring() );
				if ( is_wp_error( $plaintext ) ) {
					do_action( 'woo_alipay_secret_error', $plaintext, $option, $field );
					return $plaintext;
				}
				$previous = self::is_envelope( $stored[ $field ] ) ? $stored[ $field ] : self::encrypt_value( $plaintext, $active );
				$rotated  = self::encrypt_value( $plaintext, $active );
				if ( is_wp_error( $previous ) || is_wp_error( $rotated ) ) {
					$error = is_wp_error( $previous ) ? $previous : $rotated;
					do_action( 'woo_alipay_secret_error', $error, $option, $field );
					return $error;
				}
				$rollback[ $option ][ $field ] = $previous;
				$stored[ $field ]             = $rotated;
			}
			$updates[ $option ] = $stored;
		}

		if ( ! empty( $rollback ) ) {
			update_option(
				self::ROLLBACK_OPTION,
				array(
					'schema'     => 1,
					'created_at' => time(),
					'values'     => $rollback,
				),
				false
			);
		}
		foreach ( $updates as $option => $stored ) {
			update_option( $option, $stored, false );
		}
		return true;
	}

	/**
	 * Restore the last encrypted rollback generation under the active key.
	 *
	 * Operators rotating an external key must keep the retired key in the
	 * `woo_alipay_secret_keyring` filter until this rollback is complete.
	 *
	 * @return true|WP_Error
	 */
	public static function rollback_current_site() {
		$active   = self::active_material();
		$snapshot = get_option( self::ROLLBACK_OPTION, array() );
		if ( is_wp_error( $active ) ) {
			return $active;
		}
		if ( ! is_array( $snapshot ) || 1 !== (int) ( $snapshot['schema'] ?? 0 ) || ! is_array( $snapshot['values'] ?? null ) ) {
			return new WP_Error( 'secret_rollback_unavailable', __( 'No valid Alipay secret rollback generation is available.', 'wei-alipay' ) );
		}

		$updates = array();
		foreach ( $snapshot['values'] as $option => $fields ) {
			if ( ! isset( self::$option_fields[ $option ] ) || ! is_array( $fields ) ) {
				return new WP_Error( 'secret_rollback_invalid', __( 'The Alipay secret rollback generation is invalid.', 'wei-alipay' ) );
			}
			$stored = self::raw_option( $option );
			$stored = is_array( $stored ) ? $stored : array();
			foreach ( $fields as $field => $envelope ) {
				if ( ! in_array( $field, self::$option_fields[ $option ], true ) || ! self::is_envelope( $envelope ) ) {
					return new WP_Error( 'secret_rollback_invalid', __( 'The Alipay secret rollback generation is invalid.', 'wei-alipay' ) );
				}
				$plaintext = self::decrypt_value( $envelope, self::keyring() );
				if ( is_wp_error( $plaintext ) ) {
					do_action( 'woo_alipay_secret_error', $plaintext, $option, $field );
					return $plaintext;
				}
				$restored = self::encrypt_value( $plaintext, $active );
				if ( is_wp_error( $restored ) ) {
					return $restored;
				}
				$stored[ $field ] = $restored;
			}
			$updates[ $option ] = $stored;
		}

		foreach ( $updates as $option => $stored ) {
			update_option( $option, $stored, false );
		}
		delete_option( self::ROLLBACK_OPTION );
		return true;
	}

	/**
	 * Remember a bounded error marker without retaining secret material.
	 *
	 * @param WP_Error $error  Secret operation error.
	 * @param string   $option Option name.
	 * @param string   $field  Secret field name.
	 */
	public static function remember_error( $error, $option, $field ) {
		if ( ! is_wp_error( $error ) || ! isset( self::$option_fields[ $option ] ) ) {
			return;
		}
		$marker = sanitize_key( (string) $error->get_error_code() ) . ':' . sanitize_key( (string) $field );
		self::$errors[ $option ] = array_slice(
			array_values( array_unique( array_merge( self::$errors[ $option ] ?? array(), array( $marker ) ) ) ),
			0,
			10
		);
	}

	public static function render_health_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::is_settings_screen() ) {
			return;
		}
		self::audit_stored_secrets();
		if ( ! empty( self::$errors ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Wei Alipay cannot decrypt one or more stored payment secrets. Payment is disabled until the configured encryption key is restored.', 'wei-alipay' ) . '</p></div>';
			return;
		}
		$has_dedicated_key = ( defined( 'WEI_ALIPAY_ENCRYPTION_KEY' ) && '' !== (string) WEI_ALIPAY_ENCRYPTION_KEY )
			|| ( defined( 'WOO_ALIPAY_ENCRYPTION_KEY' ) && '' !== (string) WOO_ALIPAY_ENCRYPTION_KEY );
		$settings = get_option( 'woocommerce_alipay_settings', array() );
		if ( ! $has_dedicated_key && is_array( $settings ) && 'yes' === ( $settings['enabled'] ?? 'no' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Wei Alipay is using WordPress salts to protect payment secrets. Configure WEI_ALIPAY_ENCRYPTION_KEY before cloning, restoring, or moving this site.', 'wei-alipay' ) . '</p></div>';
		}
	}

	/**
	 * Validate raw encrypted options on the settings screen.
	 *
	 * get_option() is intentionally not used: the display filter masks secret
	 * fields before this health check can validate their envelopes.
	 */
	private static function audit_stored_secrets() {
		foreach ( self::$option_fields as $option => $fields ) {
			$stored = self::raw_option( $option );
			if ( ! is_array( $stored ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				if ( empty( $stored[ $field ] ) || ! self::is_envelope( $stored[ $field ] ) ) {
					continue;
				}
				$value = self::decrypt_value( $stored[ $field ], self::keyring() );
				if ( is_wp_error( $value ) ) {
					self::remember_error( $value, $option, $field );
				}
			}
		}
	}

	private static function active_material() {
		if ( defined( 'WEI_ALIPAY_ENCRYPTION_KEY' ) && '' !== (string) WEI_ALIPAY_ENCRYPTION_KEY ) {
			return (string) WEI_ALIPAY_ENCRYPTION_KEY;
		}
		// Preserve decryptability during the controlled mall.weixiaoduo.com key-name cutover.
		if ( defined( 'WOO_ALIPAY_ENCRYPTION_KEY' ) && '' !== (string) WOO_ALIPAY_ENCRYPTION_KEY ) {
			return (string) WOO_ALIPAY_ENCRYPTION_KEY;
		}
		$parts = array();
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ) as $constant ) {
			if ( defined( $constant ) ) {
				$parts[] = constant( $constant );
			}
		}
		if ( empty( $parts ) ) {
			return new WP_Error( 'secret_key_unavailable', __( 'WordPress security keys are required to protect Alipay secrets.', 'wei-alipay' ) );
		}
		return implode( '|', $parts );
	}

	private static function keyring() {
		$active = self::active_material();
		$keys   = is_wp_error( $active ) ? array() : array( $active );
		return (array) apply_filters( 'woo_alipay_secret_keyring', $keys );
	}

	private static function derive_key( $material ) {
		return hash_hmac( 'sha256', 'woo-alipay', (string) $material, true );
	}

	private static function is_envelope( $value ) {
		return 0 === strpos( (string) $value, self::PREFIX );
	}

	private static function is_settings_screen() {
		if ( ! is_admin() ) {
			return false;
		}
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		return 'wc-settings' === $page && in_array( $section, array( 'alipay', 'alipay_wap', 'wei-alipay', 'woo-alipay' ), true );
	}

	private static function raw_option( $option ) {
		global $wpdb;
		if ( ! isset( $wpdb->options ) ) {
			return array();
		}
		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic/raw primitive cannot use the option/order cache.
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option )
		);
		return null === $value ? array() : maybe_unserialize( $value );
	}
}
