/* global WooAlipay */
jQuery( function ( $ ) {
	'use strict';

	function showResult( type, message ) {
		$( '#wei-alipay-test-result' )
			.removeAttr( 'hidden' )
			.removeClass( 'notice-success notice-error' )
			.addClass( 'success' === type ? 'notice-success' : 'notice-error' )
			.empty()
			.append( $( '<p>' ).text( message ) );
	}

	$( '#wei-alipay-test-connection' ).on( 'click', function () {
		var button = $( this );
		var spinner = $( '.wei-alipay-settings .spinner' );
		button.prop( 'disabled', true );
		spinner.addClass( 'is-active' );
		$( '#wei-alipay-test-result' ).attr( 'hidden', true );

		$.ajax( {
			url: WooAlipay.ajax_url,
			type: 'POST',
			data: {
				nonce: $( '#woo_alipay_nonce' ).val(),
				action: 'woo_alipay_test_connection'
			}
		} ).done( function ( response ) {
			if ( response.success ) {
				showResult( 'success', WooAlipay.connection_success );
				return;
			}
			var serverMessage = response.data && 'string' === typeof response.data ? response.data : '';
			showResult( 'error', serverMessage ? WooAlipay.connection_failed + ': ' + serverMessage : WooAlipay.connection_failed );
		} ).fail( function () {
			showResult( 'error', WooAlipay.connection_network_error );
		} ).always( function () {
			spinner.removeClass( 'is-active' );
			button.prop( 'disabled', false );
		} );
	} );

	$( document ).on( 'click', '[data-wei-copy]', function () {
		var button = this;
		var target = document.querySelector( button.dataset.weiCopy );
		if ( ! target || ! navigator.clipboard ) {
			window.alert( WooAlipay.copy_failed ); // eslint-disable-line no-alert
			return;
		}
		navigator.clipboard.writeText( target.textContent ).then( function () {
			var original = button.textContent;
			button.textContent = WooAlipay.copied;
			window.setTimeout( function () { button.textContent = original; }, 1500 );
		} ).catch( function () {
			window.alert( WooAlipay.copy_failed ); // eslint-disable-line no-alert
		} );
	} );
} );
