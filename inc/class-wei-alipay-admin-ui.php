<?php
/**
 * Shared, native-first administration UI helpers.
 *
 * @package Wei_Alipay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wei_Alipay_Admin_UI {
	/**
	 * Render the common page heading.
	 *
	 * @param string $title       Page title.
	 * @param string $description One-sentence task description.
	 */
	public static function page_header( $title, $description ) {
		echo '<header class="wei-alipay-page-header">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p class="wei-alipay-admin__description">' . esc_html( $description ) . '</p>';
		echo '</header>';
		self::wp_header_end();
	}

	/**
	 * Emit the native WordPress page-header boundary after the plugin heading.
	 */
	public static function wp_header_end() {
		echo '<div class="wp-header-end" aria-hidden="true"></div>';
	}

	/**
	 * Render page-scoped feedback in one predictable region.
	 *
	 * WordPress keeps system-wide notices in its global notice area. This
	 * helper is only for feedback owned by the current Wei Alipay page.
	 *
	 * @param array<int,array{message:string,type?:string,dismissible?:bool}> $notices Notice definitions.
	 * @param string                                                            $label   Region label.
	 */
	public static function notice_region( $notices, $label ) {
		if ( empty( $notices ) ) {
			return;
		}

		echo '<section class="wei-alipay-notice-region" aria-label="' . esc_attr( $label ) . '">';
		foreach ( $notices as $notice ) {
			self::notice(
				isset( $notice['message'] ) ? $notice['message'] : '',
				isset( $notice['type'] ) ? $notice['type'] : 'info',
				! empty( $notice['dismissible'] )
			);
		}
		echo '</section>';
	}

	/**
	 * Render one native WordPress notice without redefining its appearance.
	 *
	 * @param string $message     Notice body. Safe HTML is allowed.
	 * @param string $type        success, error, warning or info.
	 * @param bool   $dismissible Whether WordPress should add dismiss controls.
	 */
	private static function notice( $message, $type, $dismissible ) {
		$allowed = array( 'success', 'error', 'warning', 'info' );
		$type = in_array( $type, $allowed, true ) ? $type : 'info';
		$classes = 'notice notice-' . $type . ' inline';
		if ( $dismissible ) {
			$classes .= ' is-dismissible';
		}
		$role = in_array( $type, array( 'error', 'warning' ), true ) ? 'alert' : 'status';

		echo '<div class="' . esc_attr( $classes ) . '" role="' . esc_attr( $role ) . '"><p>' . wp_kses_post( $message ) . '</p></div>';
	}

	/**
	 * Render URL-addressable tabs using native WordPress tab markup.
	 *
	 * @param array<string,array{label:string,url:string}> $tabs   Tab definitions.
	 * @param string                                  $active Active tab key.
	 * @param string                                  $label  Navigation label.
	 */
	public static function tabs( $tabs, $active, $label ) {
		echo '<nav class="nav-tab-wrapper wei-alipay-tabs" aria-label="' . esc_attr( $label ) . '">';
		foreach ( $tabs as $key => $tab ) {
			$class = $active === $key ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $tab['url'] ) . '"' . ( $active === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $tab['label'] ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Render navigation shared by all profit-sharing screens.
	 *
	 * @param string $active Active section key.
	 */
	public static function profit_sharing_tabs( $active ) {
		$admin = admin_url( 'admin.php' );
		self::tabs(
			array(
				'settings'     => array( 'label' => __( 'Settings', 'wei-alipay' ), 'url' => add_query_arg( array( 'page' => 'wei-alipay-profitsharing', 'view' => 'settings' ), $admin ) ),
				'query'        => array( 'label' => __( 'Settlement Query', 'wei-alipay' ), 'url' => add_query_arg( array( 'page' => 'wei-alipay-profitsharing', 'view' => 'query' ), $admin ) ),
				'receivers'    => array( 'label' => __( 'Receivers', 'wei-alipay' ), 'url' => add_query_arg( array( 'page' => 'wei-alipay-profitsharing', 'view' => 'receivers' ), $admin ) ),
				'rules'        => array( 'label' => __( 'Allocation Rules', 'wei-alipay' ), 'url' => add_query_arg( array( 'page' => 'wei-alipay-profitsharing', 'view' => 'rules' ), $admin ) ),
				'jobs'         => array( 'label' => __( 'Jobs', 'wei-alipay' ), 'url' => add_query_arg( 'page', 'wei-alipay-ps-jobs', $admin ) ),
				'compensation' => array( 'label' => __( 'Compensation', 'wei-alipay' ), 'url' => add_query_arg( 'page', 'wei-alipay-ps-compensation', $admin ) ),
			),
			$active,
			__( 'Profit sharing sections', 'wei-alipay' )
		);
	}
}
