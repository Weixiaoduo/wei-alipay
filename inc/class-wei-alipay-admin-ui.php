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
