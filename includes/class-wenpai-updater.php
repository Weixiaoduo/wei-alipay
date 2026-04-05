<?php
/**
 * WenPai 插件更新器
 *
 * 为文派系插件提供自建更新服务支持。
 * 通过文派云桥 (WenPai Bridge) 检查插件更新，
 * 利用 WordPress 5.8+ 的 Update URI 机制。
 *
 * 当 wp-china-yes 插件激活并启用集中更新时，
 * 此更新器会通过 wenpai_updater_override filter 自动让位。
 *
 * @package  WenPai
 * @version  1.0.0
 * @requires WordPress 5.8+
 * @requires PHP 7.4+
 * @link     https://feicode.com/WenPai-org
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( class_exists( 'WenPai_Updater' ) ) {
    return;
}

class WenPai_Updater {

    /**
     * 更新器版本号。
     *
     * @var string
     */
    const VERSION = '1.2.1';

    /**
     * 云桥 API 地址。
     *
     * @var string
     */
    const API_URL = 'https://updates.wenpai.net/api/v1';

    /**
     * 插件主文件 basename（如 wpslug/wpslug.php）。
     *
     * @var string
     */
    private $plugin_file;

    /**
     * 插件 slug（如 wpslug）。
     *
     * @var string
     */
    private $slug;

    /**
     * 当前插件版本。
     *
     * @var string
     */
    private $version;

    /**
     * 初始化更新器。
     *
     * @param string $plugin_file 插件主文件路径（plugin_basename 格式）。
     * @param string $version     当前插件版本号。
     */
    public function __construct( string $plugin_file, string $version ) {
        $this->plugin_file = $plugin_file;
        $this->slug        = dirname( $plugin_file );
        $this->version     = $version;

        // 检查是否被 wp-china-yes 集中更新接管
        $is_overridden = apply_filters(
            'wenpai_updater_override',
            false,
            $this->slug
        );

        if ( ! $is_overridden ) {
            $this->register_hooks();
        }
    }

    /**
     * 注册 WordPress hooks。
     */
    private function register_hooks(): void {
        // Update URI: https://updates.wenpai.net 触发此 filter
        add_filter(
            'update_plugins_updates.wenpai.net',
            [ $this, 'check_update' ],
            10,
            4
        );

        // 插件详情弹窗
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );
    }

    /**
     * 检查插件更新。
     *
     * WordPress 在检查更新时，对声明了 Update URI 的插件
     * 触发 update_plugins_{hostname} filter。
     *
     * @param array|false $update      当前更新数据。
     * @param array       $plugin_data 插件头信息。
     * @param string      $plugin_file 插件文件路径。
     * @param string[]    $locales     语言列表。
     * @return object|false 更新数据或 false。
     */
    public function check_update( $update, array $plugin_data, string $plugin_file, array $locales ) {
        if ( $plugin_file !== $this->plugin_file ) {
            return $update;
        }

        $response = $this->api_request( 'update-check', [
            'plugins' => [
                $this->plugin_file => [
                    'Version' => $this->version,
                ],
            ],
        ] );

        if ( is_wp_error( $response ) || empty( $response['plugins'][ $this->plugin_file ] ) ) {
            return $update;
        }

        $data = $response['plugins'][ $this->plugin_file ];

        return (object) [
            'id'           => $data['id'] ?? '',
            'slug'         => $data['slug'] ?? $this->slug,
            'plugin'       => $this->plugin_file,
            'version'      => $data['version'] ?? '',
            'new_version'  => $data['version'] ?? '',
            'url'          => $data['url'] ?? '',
            'package'      => $data['package'] ?? '',
            'icons'        => $data['icons'] ?? [],
            'banners'      => $data['banners'] ?? [],
            'requires'     => $data['requires'] ?? '',
            'tested'       => $data['tested'] ?? '',
            'requires_php' => $data['requires_php'] ?? '',
        ];
    }

    /**
     * 插件详情弹窗数据。
     *
     * 当用户在 WP 后台点击"查看详情"时触发。
     *
     * @param false|object|array $result 当前结果。
     * @param string             $action API 动作。
     * @param object             $args   请求参数。
     * @return false|object 插件信息或 false。
     */
    public function plugin_info( $result, string $action, object $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
            return $result;
        }

        $response = $this->api_request( "plugins/{$this->slug}/info" );

        if ( ! is_wp_error( $response ) && ! isset( $response['error'] ) && ! empty( $response['name'] ) ) {
            $info                = new stdClass();
            $info->name          = $response['name'];
            $info->slug          = $response['slug'] ?? $this->slug;
            $info->version       = $response['version'] ?? '';
            $info->author        = $response['author'] ?? '';
            $info->homepage      = $response['homepage'] ?? '';
            $info->download_link = $response['download_link'] ?? '';
            $info->requires      = $response['requires'] ?? '';
            $info->tested        = $response['tested'] ?? '';
            $info->requires_php  = $response['requires_php'] ?? '';
            $info->last_updated  = $response['last_updated'] ?? '';
            $info->icons         = $response['icons'] ?? [];
            $info->banners       = $response['banners'] ?? [];
            $info->sections      = array_map( [ $this, 'markdown_to_html' ], $response['sections'] ?? [] );
            $info->external      = true;

            return $info;
        }

        // API 不可用或插件未注册时，用本地插件头信息兜底
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
     * 向云桥 API 发送请求。
     *
     * @param string     $endpoint API 端点（不含 /api/v1/ 前缀）。
     * @param array|null $body     POST 请求体（null 则用 GET）。
     * @return array|WP_Error 解码后的响应或错误。
     */
    private function api_request( string $endpoint, ?array $body = null ) {
        $url = self::API_URL . '/' . ltrim( $endpoint, '/' );

        $args = [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ];

        if ( null !== $body ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode( $body );
            $response = wp_remote_post( $url, $args );
        } else {
            $response = wp_remote_get( $url, $args );
        }

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new WP_Error(
                'wenpai_bridge_error',
                sprintf( 'WenPai Bridge API returned %d', $code )
            );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) ) {
            return new WP_Error(
                'wenpai_bridge_parse_error',
                'Invalid JSON response from WenPai Bridge'
            );
        }

        return $data;
    }

    /**
     * 将 Markdown 文本转换为 HTML。
     *
     * 仅处理 API 返回的 changelog 中常见的 Markdown 子集：
     * 标题、列表、加粗、斜体、行内代码、链接。
     *
     * @param string $text Markdown 文本。
     * @return string HTML。
     */
    private function markdown_to_html( string $text ): string {
        if ( empty( $text ) || str_starts_with( trim( $text ), '<' ) ) {
            return $text;
        }

        // 截断 feicode-ai 自动追加的 AI 摘要（以 HTML 注释标记为界）
        $text = preg_replace( '/---\s*\n\s*<!--\s*feicode-ai.*$/s', '', $text );

        // 去掉 Checksums 表格（不适合在 WordPress 弹窗展示）
        $text = preg_replace( '/^###?\s+Checksums\s*\n+(\|.*\n)+/mi', '', $text );

        // 去掉 CI 自动生成的冗余标题行（WordPress 弹窗已显示插件名和版本）
        $text = preg_replace( '/^##\s+\S+\s+v[\d.]+\s*\n+/m', '', $text );
        $text = preg_replace( '/^###?\s+What\'s Changed\s*\n+/mi', '', $text );

        $lines  = explode( "\n", $text );
        $html   = '';
        $in_ul  = false;

        foreach ( $lines as $line ) {
            $trimmed = trim( $line );

            if ( '' === $trimmed ) {
                if ( $in_ul ) {
                    $html .= "</ul>\n";
                    $in_ul = false;
                }
                continue;
            }

            // 标题 h2-h4
            if ( preg_match( '/^(#{2,4})\s+(.+)$/', $trimmed, $m ) ) {
                if ( $in_ul ) {
                    $html .= "</ul>\n";
                    $in_ul = false;
                }
                $level = strlen( $m[1] );
                $html .= sprintf( "<h%d>%s</h%d>\n", $level, esc_html( $m[2] ), $level );
                continue;
            }

            // 无序列表
            if ( preg_match( '/^[-*]\s+(.+)$/', $trimmed, $m ) ) {
                if ( ! $in_ul ) {
                    $html .= "<ul>\n";
                    $in_ul = true;
                }
                $html .= '<li>' . $this->inline_markdown( $m[1] ) . "</li>\n";
                continue;
            }

            // 普通段落
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
     * 处理行内 Markdown：加粗、斜体、行内代码、链接。
     */
    private function inline_markdown( string $text ): string {
        $text = esc_html( $text );
        $text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
        $text = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $text );
        $text = preg_replace( '/`(.+?)`/', '<code>$1</code>', $text );
        $text = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text );

        return $text;
    }
}
