/* global WooAlipay */
jQuery( document ).ready( function( $ ) {


	$( '#wei-alipay-test-connection, #woo_alipay_test_connection' ).on( 'click', function( e ) {
		e.preventDefault();

		var spinner      = $('.wei-alipay-settings .spinner'),
			failure      = $('.wei-alipay-settings .test-status .failure, .wei-alipay-settings .test-status-message.failure'),
			error        = $('.wei-alipay-settings .test-status .error, .wei-alipay-settings .test-status-message.error'),
			success      = $('.wei-alipay-settings .test-status .success, .wei-alipay-settings .test-status-message.success'),
			help         = $('.wei-alipay-settings .description.help'),
			testResult   = $('#wei-alipay-test-result'),
			data         = {
			nonce : $('#woo_alipay_nonce').val(),
			action: 'woo_alipay_test_connection'
		};

		spinner.addClass('is-active');
		failure.removeClass('is-active');
		error.removeClass('is-active');
		success.removeClass('is-active');
		testResult.hide().removeClass('success error');

		$.ajax( {
			url: WooAlipay.ajax_url,
			type: 'POST',
			data: data
		} ).done( function( response ) {
			spinner.removeClass('is-active');
			window.console.log( response );

			if ( response.success ) {
				success.addClass('is-active');
				help.removeClass('is-active');
				testResult.addClass('success').html('连接测试成功！支付宝网关配置正确。').show();
			} else {
				if ( response.data ) {
					error.addClass('is-active');
					help.removeClass('is-active');
					var errorMessage = typeof response.data === 'string' ? response.data : 
						(response.data.message || '配置错误或网络问题');
					testResult.addClass('error').html('连接测试失败：' + errorMessage).show();
				} else {
					failure.addClass('is-active');
					help.removeClass('is-active');
					testResult.addClass('error').html('连接测试失败：网络错误或配置问题。').show();
				}
			}
		} ).fail( function( qXHR, textStatus ) {
			WooAlipay.debug && window.console.log( textStatus );
			spinner.removeClass('is-active');
			success.removeClass('is-active');
			help.removeClass('is-active');
			failure.addClass('is-active');
			testResult.addClass('error').html('连接测试失败：网络请求失败。').show();
		} );
	} );



} );