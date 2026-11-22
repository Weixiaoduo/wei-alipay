<?php
/**
 * Weixiaoduo plugin updater built on the shared WenPai Bridge update server
 * infrastructure, served under the Weixiaoduo brand domain (`updates.weixiaoduo.com`).
 *
 * Primary protocol: WenPai Bridge REST API (`/api/v1/update-check` & `/api/v1/plugins/{slug}`),
 * with release packages hosted on `downloads.feicode.com` or `updates.weixiaoduo.com`.
 * Transition fallback: if `/api/v1/*` returns HTTP 404 while DNS migration is in progress,
 * transparently falls back to `https://updates.weixiaoduo.com/{slug}.json`.
 *
 * @package  Wei_Alipay
 * @version  2.1.0
 * @requires WordPress 6.6+
 * @requires PHP 7.4+
 * @link     https://www.weixiaoduo.com/product/wei-alipay.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Wei_Alipay_WenPai_Updater' ) ) {
	return;
}

class Wei_Alipay_WenPai_Updater {

	/**
	 * Updater version.
	 *
	 * @var string
	 */
	const VERSION = '2.1.0';

	/**
	 * Shared WenPai Bridge API endpoint under Weixiaoduo domain.
	 *
	 * @var string
	 */
	const API_URL = 'https://updates.weixiaoduo.com/api/v1';

	/**
	 * Legacy manifest origin used only as HTTP-404 transition fallback.
	 *
	 * @var string
	 */
	const LEGACY_MANIFEST_BASE = 'https://updates.weixiaoduo.com';

	/**
	 * Plugin basename (e.g. wei-alipay/wei-alipay.php).
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Plugin slug (e.g. wei-alipay).
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Installed plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @param string $version     Installed version.
	 */
	public function __construct( string $plugin_file, string $version ) {
		$this->plugin_file = $plugin_file;
		$this->slug        = dirname( $plugin_file );
		$this->version     = $version;

		$this->register_hooks();
	}

	/**
	 * Register WordPress Update URI and plugin info hooks.
	 */
	private function register_hooks(): void {
		add_filter(
			'update_plugins_updates.weixiaoduo.com',
			[ $this, 'check_update' ],
			10,
			4
		);

		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );
	}

	/**
	 * Check for plugin updates via WenPai Bridge `/api/v1/update-check` (with 404 fallback).
	 *
	 * @param array|false $update      Existing update payload.
	 * @param array       $plugin_data Plugin header metadata.
	 * @param string      $plugin_file Plugin basename.
	 * @param string[]    $locales     Installed locales.
	 * @return array|false
	 */
	public function check_update( $update, array $plugin_data, string $plugin_file, array $locales ) {
		if ( $plugin_file !== $this->plugin_file ) {
			return $update;
		}

		$cache_key = 'wei_alipay_upd_' . md5( $this->slug . '_' . $this->version );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return 'none' === $cached ? false : $cached;
		}

		$info = $this->fetch_bridge_update_check();
		if ( 'fallback' === $info ) {
			$info = $this->fetch_legacy_manifest();
		}

		if ( ! is_array( $info ) || empty( $info['version'] ) ) {
			set_transient( $cache_key, 'none', 15 * MINUTE_IN_SECONDS );
			return false;
		}

		$new_version = (string) $info['version'];
		$package_url = (string) ( $info['package'] ?? ( $info['download_link'] ?? ( $info['download_url'] ?? '' ) ) );

		if (
			version_compare( $new_version, $this->version, '>' ) &&
			$this->is_allowed_distribution_url( $package_url )
		) {
			$result = [
				'id'           => 'updates.weixiaoduo.com/' . $this->slug,
				'slug'         => $this->slug,
				'version'      => $new_version,
				'url'          => $this->sanitize_external_url( (string) ( $info['url'] ?? ( $info['homepage'] ?? 'https://www.weixiaoduo.com/product/wei-alipay.html' ) ) ),
				'package'      => $package_url,
				'tested'       => (string) ( $info['tested'] ?? '' ),
				'requires_php' => (string) ( $info['requires_php'] ?? '7.4' ),
				'icons'        => is_array( $info['icons'] ?? null ) ? $info['icons'] : [],
			];
			set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
			return $result;
		}

		set_transient( $cache_key, 'none', 6 * HOUR_IN_SECONDS );
		return false;
	}

	/**
	 * Provide plugin detail modal information via WenPai Bridge `/api/v1/plugins/{slug}` (with 404 fallback).
	 *
	 * @param false|object|array $result Existing result.
	 * @param string             $action API action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public function plugin_info( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$cache_key = 'wei_alipay_info_' . md5( $this->slug );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return is_object( $cached ) ? $cached : $result;
		}

		$data = $this->fetch_bridge_plugin_info();
		if ( 'fallback' === $data ) {
			$data = $this->fetch_legacy_manifest();
		}
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			return $result;
		}

		$download_link = (string) ( $data['download_link'] ?? ( $data['download_url'] ?? '' ) );
		if ( '' !== $download_link && ! $this->is_allowed_distribution_url( $download_link ) ) {
			$download_link = '';
		}

		$sections = [];
		if ( ! empty( $data['sections'] ) && is_array( $data['sections'] ) ) {
			foreach ( $data['sections'] as $key => $content ) {
				$sections[ sanitize_key( (string) $key ) ] = $this->format_markdown_text( (string) $content );
			}
		}

		$info                = new stdClass();
		$info->name          = sanitize_text_field( (string) ( $data['name'] ?? 'Wei Alipay Payment Gateway for WooCommerce' ) );
		$info->slug          = $this->slug;
		$info->version       = sanitize_text_field( (string) $data['version'] );
		$info->author        = wp_kses_post( (string) ( $data['author'] ?? 'Weixiaoduo.com' ) );
		$info->homepage      = $this->sanitize_external_url( (string) ( $data['homepage'] ?? 'https://www.weixiaoduo.com/product/wei-alipay.html' ) );
		$info->requires      = sanitize_text_field( (string) ( $data['requires'] ?? '6.0' ) );
		$info->tested        = sanitize_text_field( (string) ( $data['tested'] ?? '' ) );
		$info->requires_php  = sanitize_text_field( (string) ( $data['requires_php'] ?? '7.4' ) );
		$info->last_updated  = sanitize_text_field( (string) ( $data['last_updated'] ?? '' ) );
		$info->download_link = $download_link;
		$info->sections      = $sections;
		$info->banners       = is_array( $data['banners'] ?? null ) ? $data['banners'] : [];
		$info->icons         = is_array( $data['icons'] ?? null ) ? $data['icons'] : [];

		set_transient( $cache_key, $info, 6 * HOUR_IN_SECONDS );
		return $info;
	}

	/**
	 * Call WenPai Bridge `POST /api/v1/update-check`.
	 *
	 * @return array<string,mixed>|string|null Array payload, 'fallback' on 404, or null on failure.
	 */
	private function fetch_bridge_update_check() {
		$response = wp_remote_post(
			self::API_URL . '/update-check',
			[
				'timeout' => 10,
				'headers' => [
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'User-Agent'   => 'Wei-Alipay-Updater/' . self::VERSION . '; ' . home_url(),
				],
				'body'    => wp_json_encode(
					[
						'plugins' => [
							$this->plugin_file => [
								'Version' => $this->version,
							],
						],
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return 'fallback';
		}
		if ( 200 !== $code ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['plugins'][ $this->plugin_file ] ) || ! is_array( $body['plugins'][ $this->plugin_file ] ) ) {
			return null;
		}
		return $body['plugins'][ $this->plugin_file ];
	}

	/**
	 * Call WenPai Bridge `GET /api/v1/plugins/{slug}`.
	 *
	 * @return array<string,mixed>|string|null Array payload, 'fallback' on 404, or null on failure.
	 */
	private function fetch_bridge_plugin_info() {
		$response = wp_remote_get(
			self::API_URL . '/plugins/' . rawurlencode( $this->slug ),
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/json',
					'User-Agent' => 'Wei-Alipay-Updater/' . self::VERSION . '; ' . home_url(),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return 'fallback';
		}
		if ( 200 !== $code ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : null;
	}

	/**
	 * Transition fallback: read static `https://updates.weixiaoduo.com/{slug}.json` manifest.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetch_legacy_manifest(): ?array {
		$url      = self::LEGACY_MANIFEST_BASE . '/' . rawurlencode( $this->slug ) . '.json';
		$response = wp_remote_get(
			$url,
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/json',
					'User-Agent' => 'Wei-Alipay-Updater/' . self::VERSION . '; ' . home_url(),
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : null;
	}

	/**
	 * Validate download package origin against exact HTTPS allowlist
	 * (`updates.weixiaoduo.com` and shared WenPai Bridge `downloads.feicode.com`).
	 *
	 * @param string $url Package URL.
	 * @return bool
	 */
	public function is_allowed_distribution_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return false;
		}
		$allowed_hosts = [ 'updates.weixiaoduo.com', 'downloads.feicode.com' ];
		return in_array( strtolower( (string) $parts['host'] ), $allowed_hosts, true );
	}

	/**
	 * Sanitize external homepage URL.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function sanitize_external_url( string $url ): string {
		$clean = esc_url_raw( $url, [ 'http', 'https' ] );
		return is_string( $clean ) ? $clean : '';
	}

	/**
	 * Convert remote markdown/text safely with protocol-sanitized links.
	 *
	 * @param string $text Remote section text.
	 * @return string
	 */
	private function format_markdown_text( string $text ): string {
		$escaped = esc_html( $text );
		$linked  = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)]+)\)/',
			static function ( array $matches ): string {
				$href = esc_url( html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' ), [ 'http', 'https' ] );
				if ( '' === $href ) {
					return $matches[1];
				}
				return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $matches[1] . '</a>';
			},
			$escaped
		);
		return wp_kses_post( nl2br( is_string( $linked ) ? $linked : $escaped ) );
	}
}
