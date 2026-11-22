<?php
/**
 * Weixiaoduo UpdatePulse plugin updater for Wei Alipay.
 *
 * Uses WordPress 5.8+ Update URI (`https://updates.weixiaoduo.com`) against the
 * UpdatePulse JSON manifest at `https://updates.weixiaoduo.com/{slug}.json`.
 *
 * @package  Wei_Alipay
 * @version  2.0.0
 * @requires WordPress 6.6+
 * @requires PHP 7.4+
 * @link     https://weixiaoduo.com/product/wei-alipay.html
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
	const VERSION = '2.0.0';

	/**
	 * Weixiaoduo UpdatePulse base URL.
	 *
	 * @var string
	 */
	const API_URL = 'https://updates.weixiaoduo.com';

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
	 * Check for plugin updates via UpdatePulse manifest JSON.
	 *
	 * @param array|false $update      Current update payload.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @param string[]    $locales     Installed locales.
	 * @return object|false Update object or false.
	 */
	public function check_update( $update, array $plugin_data, string $plugin_file, array $locales ) {
		if ( $plugin_file !== $this->plugin_file ) {
			return $update;
		}

		$data = $this->fetch_manifest();
		if ( is_wp_error( $data ) || empty( $data['version'] ) ) {
			return $update;
		}

		$new_version = (string) $data['version'];
		if ( version_compare( $this->version, $new_version, '>=' ) ) {
			return $update;
		}

		$raw_package = $data['download_url'] ?? ( $data['package'] ?? '' );
		$package     = is_string( $raw_package ) ? esc_url_raw( $raw_package ) : '';
		if ( '' === $package || ! $this->is_allowed_distribution_url( $package ) ) {
			return $update;
		}

		return (object) [
			'id'           => $data['id'] ?? ( self::API_URL . '/' . $this->slug . '.json' ),
			'slug'         => $data['slug'] ?? $this->slug,
			'plugin'       => $this->plugin_file,
			'version'      => $new_version,
			'new_version'  => $new_version,
			'url'          => $data['homepage'] ?? 'https://weixiaoduo.com/product/wei-alipay.html',
			'package'      => $package,
			'icons'        => is_array( $data['icons'] ?? null ) ? $data['icons'] : [],
			'banners'      => is_array( $data['banners'] ?? null ) ? $data['banners'] : [],
			'requires'     => $data['requires'] ?? '',
			'tested'       => $data['tested'] ?? '',
			'requires_php' => $data['requires_php'] ?? '',
		];
	}

	/**
	 * Populate WordPress plugin details modal.
	 *
	 * @param false|object|array $result Current result.
	 * @param string             $action API action.
	 * @param object             $args   Request arguments.
	 * @return false|object Plugin info or false.
	 */
	public function plugin_info( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$response = $this->fetch_manifest();
		if ( ! is_wp_error( $response ) && ! empty( $response['name'] ) ) {
			$info                = new stdClass();
			$info->name          = (string) $response['name'];
			$info->slug          = $response['slug'] ?? $this->slug;
			$info->version       = $response['version'] ?? $this->version;
			$info->author        = $response['author'] ?? 'Weixiaoduo.com';
			$info->homepage      = $response['homepage'] ?? 'https://weixiaoduo.com/product/wei-alipay.html';
			$raw_dl              = $response['download_url'] ?? ( $response['download_link'] ?? '' );
			$download_link       = is_string( $raw_dl ) ? esc_url_raw( $raw_dl ) : '';
			$info->download_link = $this->is_allowed_distribution_url( $download_link ) ? $download_link : '';
			$info->requires      = $response['requires'] ?? '';
			$info->tested        = $response['tested'] ?? '';
			$info->requires_php  = $response['requires_php'] ?? '';
			$info->last_updated  = $response['last_updated'] ?? '';
			$info->icons         = is_array( $response['icons'] ?? null ) ? $response['icons'] : [];
			$info->banners       = is_array( $response['banners'] ?? null ) ? $response['banners'] : [];
			$sections            = is_array( $response['sections'] ?? null ) ? $response['sections'] : [];
			$info->sections      = array_map( [ $this, 'markdown_to_html' ], $sections );
			$info->external      = true;

			return $info;
		}

		$plugin_path = WP_PLUGIN_DIR . '/' . $this->plugin_file;
		if ( ! file_exists( $plugin_path ) ) {
			return $result;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_data = get_plugin_data( $plugin_path );

		$info               = new stdClass();
		$info->name         = $plugin_data['Name'] ?? $this->slug;
		$info->slug         = $this->slug;
		$info->version      = $this->version;
		$info->author       = $plugin_data['AuthorName'] ?? '';
		$info->homepage     = $plugin_data['PluginURI'] ?? '';
		$info->requires     = $plugin_data['RequiresWP'] ?? '';
		$info->requires_php = $plugin_data['RequiresPHP'] ?? '';
		$info->sections     = [
			'description' => $plugin_data['Description'] ?? '',
		];
		$info->external     = true;

		return $info;
	}

	/**
	 * Fetch plugin UpdatePulse JSON manifest.
	 *
	 * @return array|WP_Error Decoded JSON array or error.
	 */
	private function fetch_manifest() {
		$url = self::API_URL . '/' . rawurlencode( $this->slug ) . '.json';

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 10,
				'headers' => [
					'Accept' => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'weixiaoduo_update_error',
				sprintf( 'Weixiaoduo UpdatePulse returned %d', $code )
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'weixiaoduo_update_parse_error',
				'Invalid JSON response from Weixiaoduo UpdatePulse'
			);
		}

		return $data;
	}

	/**
	 * Only accept HTTPS packages from the Weixiaoduo distribution host.
	 *
	 * @param string $url Candidate package URL.
	 * @return bool
	 */
	private function is_allowed_distribution_url( string $url ): bool {
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return 'https' === $scheme && in_array( $host, [ 'updates.weixiaoduo.com' ], true );
	}

	/**
	 * Convert a safe Markdown subset to HTML.
	 *
	 * @param string $text Markdown text.
	 * @return string HTML.
	 */
	private function markdown_to_html( string $text ): string {
		if ( empty( $text ) || 0 === strpos( trim( $text ), '<' ) ) {
			return $text;
		}

		$lines = explode( "\n", $text );
		$html  = '';
		$in_ul = false;

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			if ( '' === $trimmed ) {
				if ( $in_ul ) {
					$html .= "</ul>\n";
					$in_ul = false;
				}
				continue;
			}

			if ( preg_match( '/^(#{2,4})\s+(.+)$/', $trimmed, $m ) ) {
				if ( $in_ul ) {
					$html .= "</ul>\n";
					$in_ul = false;
				}
				$level = strlen( $m[1] );
				$html .= sprintf( "<h%d>%s</h%d>\n", $level, esc_html( $m[2] ), $level );
				continue;
			}

			if ( preg_match( '/^[-*]\s+(.+)$/', $trimmed, $m ) ) {
				if ( ! $in_ul ) {
					$html .= "<ul>\n";
					$in_ul = true;
				}
				$html .= '<li>' . $this->inline_markdown( $m[1] ) . "</li>\n";
				continue;
			}

			if ( $in_ul ) {
				$html .= "</ul>\n";
				$in_ul = false;
			}
			$html .= '<p>' . $this->inline_markdown( $trimmed ) . "</p>\n";
		}

		if ( $in_ul ) {
			$html .= "</ul>\n";
		}

		return $html;
	}

	/**
	 * Inline Markdown conversion with strict HTTPS link sanitization.
	 *
	 * @param string $text Raw text.
	 * @return string Safe HTML.
	 */
	private function inline_markdown( string $text ): string {
		$text = esc_html( $text );
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $text );
		$text = preg_replace( '/`(.+?)`/', '<code>$1</code>', $text );
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)]+)\)/',
			static function( $matches ) {
				$url = esc_url( html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' ), [ 'https' ] );
				return '' === $url ? $matches[1] : '<a href="' . $url . '">' . $matches[1] . '</a>';
			},
			$text
		);

		return $text;
	}
}
