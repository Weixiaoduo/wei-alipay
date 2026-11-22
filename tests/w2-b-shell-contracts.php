<?php
/**
 * W2-B contracts:
 *  - Vendored wenpai-admin-ui v0.13.8 kit + VENDOR_MANIFEST.sha256 present
 *  - Product CSS scoped to [data-wenpai-product="wei-alipay"], zero #3858e9 anywhere in product code
 *  - 2-mode brand palette preference (weixiaoduo default vs Alipay Blue #1677ff) stored in user_meta
 *  - Kit CSS never enqueued on WooCommerce wc-settings screens
 */

$root     = dirname( __DIR__ );
$failures = 0;
$expect   = static function( $cond, $label ) use ( &$failures ) {
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		++$failures;
		return;
	}
	echo "PASS: {$label}\n";
};

$expect( file_exists( $root . '/lib/wenpai-admin-ui/VERSION' ), 'vendored wenpai-admin-ui VERSION exists' );
$expect( file_exists( $root . '/lib/wenpai-admin-ui/VENDOR_MANIFEST.sha256' ), 'vendored wenpai-admin-ui VENDOR_MANIFEST.sha256 exists' );
$expect( '0.13.8' === trim( (string) file_get_contents( $root . '/lib/wenpai-admin-ui/VERSION' ) ), 'vendored kit version is 0.13.8' );

$shell_css = file_get_contents( $root . '/css/admin/wei-alipay-shell.css' );
$expect( false !== strpos( $shell_css, '[data-wenpai-product="wei-alipay"]' ), 'shell CSS is scoped to wei-alipay product attribute' );
$expect( false !== strpos( $shell_css, '--wenpai-accent: #d93d2e;' ), 'default palette uses Weixiaoduo butterfly red' );
$expect( false !== strpos( $shell_css, '[data-wei-palette="platform"]' ) && false !== strpos( $shell_css, '--wenpai-accent: #1677ff;' ), 'platform palette mode overrides accent to Alipay Blue #1677ff' );
$expect( false === strpos( $shell_css, ':root' ), 'shell CSS never writes to :root' );

// Zero #3858e9 across all first-party PHP/CSS files (excluding vendored kit)
$blue_hits = array();
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( ! $f->isFile() ) {
		continue;
	}
	$rel = str_replace( $root . '/', '', $f->getPathname() );
	if ( preg_match( '#^(?:\.git|vendor|node_modules|lib/wenpai-admin-ui|docs|tests)/#', $rel ) ) {
		continue;
	}
	$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
	if ( ! in_array( $ext, array( 'php', 'css', 'scss' ), true ) ) {
		continue;
	}
	if ( false !== stripos( (string) file_get_contents( $f->getPathname() ), '3858e9' ) ) {
		$blue_hits[] = $rel;
	}
}
$expect( empty( $blue_hits ), 'zero #3858e9 in product PHP/CSS (' . implode( ', ', $blue_hits ) . ')' );

$shell_php = file_get_contents( $root . '/inc/class-wei-alipay-shell.php' );
$expect( false !== strpos( $shell_php, 'wei_alipay_admin_palette' ) && false !== strpos( $shell_php, 'update_user_meta(' ), '2-mode palette stored per-admin in user_meta' );
$expect( false === strpos( $shell_php, 'style="' ), 'shell PHP templates contain zero inline style attributes' );
$expect( false !== strpos( $shell_php, "is_shell_hook(" ) && false === strpos( $shell_php, 'wc-settings' ) || false !== strpos( $shell_php, "'toplevel_page_wei-alipay' === \$hook" ), 'kit assets only enqueue on standalone Wei Alipay screens' );

exit( $failures ? 1 : 0 );
