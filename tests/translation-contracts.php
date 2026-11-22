<?php

$root = dirname( __DIR__ );
$po_file = $root . '/languages/wei-alipay-zh_CN.po';
$mo_file = $root . '/languages/wei-alipay-zh_CN.mo';

$expect = static function( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
};

$expect( is_readable( $po_file ), 'Simplified Chinese PO catalog is readable' );
$expect( is_readable( $mo_file ) && filesize( $mo_file ) > 0, 'compiled Simplified Chinese MO catalog is present' );

$catalog = file_get_contents( $po_file );
$expect( false !== $catalog, 'Simplified Chinese PO catalog can be read' );

$untranslated = array();
foreach ( preg_split( '/\R{2,}/', (string) $catalog ) as $block ) {
	if ( ! preg_match( '/^msgid (.+)$/m', $block, $msgid_match ) ) {
		continue;
	}
	if ( '""' === trim( $msgid_match[1] ) ) {
		continue;
	}
	if ( preg_match( '/^msgstr ""\s*$/m', $block ) && ! preg_match( '/^msgstr ""\R".+"/m', $block ) ) {
		$untranslated[] = $msgid_match[1];
		continue;
	}
	if ( preg_match( '/^msgstr\[0\] ""\s*$/m', $block ) ) {
		$untranslated[] = $msgid_match[1];
	}
}

$expect( array() === $untranslated, 'Simplified Chinese catalog has zero untranslated messages' );
$expect( false === strpos( $catalog, '#, fuzzy' ), 'Simplified Chinese catalog has zero fuzzy messages' );

$required = array(
	'msgid "Account and authorization"' => 'msgstr "账户与授权"',
	'msgid "Data shared when connected"' => 'msgstr "连接时会发送哪些数据"',
	'msgid "Connect to enable"' => 'msgstr "连接后可启用"',
	'msgid "Not available yet"' => 'msgstr "暂不开放"',
	'msgid "Enabled, options locked"' => 'msgstr "已开启，选项已锁定"',
	'msgid "Payment status"' => 'msgstr "收款状态"',
	'msgid "Weixiaoduo account avatar"' => 'msgstr "薇晓朵账户头像"',
	'msgid "Manage on Weixiaoduo.com"' => 'msgstr "在 Weixiaoduo.com 中管理"',
	'msgid "Callbacks and background recovery"' => 'msgstr "回调与后台任务"',
	'msgid "Payment activity in the last 7 days"' => 'msgstr "近 7 天收款情况"',
	'msgid "Compatibility and diagnostics"' => 'msgstr "兼容性与诊断"',
	'msgid "Private key protection"' => 'msgstr "私钥保护"',
	'msgid "Enabled payment services"' => 'msgstr "已启用的支付服务"',
	'msgid "No payment sample recorded"' => 'msgstr "近 7 天暂无收款记录"',
	'msgid "No callback sample"' => 'msgstr "尚无回调记录"',
	'msgid "Alipay notification amount does not match the order."' => 'msgstr "支付宝通知金额与订单不匹配。"',
);
foreach ( $required as $msgid => $msgstr ) {
	$expect( false !== strpos( $catalog, $msgid ) && false !== strpos( $catalog, $msgstr ), "zh_CN includes {$msgid}" );
}

$js_json = glob( $root . '/languages/wei-alipay-zh_CN-*.json' );
$expect( is_array( $js_json ) && count( $js_json ) >= 3, 'compiled JS JSON translation files present (' . count( (array) $js_json ) . ' files)' );
