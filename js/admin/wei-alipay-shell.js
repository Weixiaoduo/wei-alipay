(function () {
	'use strict';

	function hideBrokenAvatars() {
		var imgs = document.querySelectorAll( '.wei-alipay-avatar' );
		for ( var i = 0; i < imgs.length; i++ ) {
			( function ( img ) {
				if ( img.complete && 0 === img.naturalWidth ) {
					img.classList.add( 'is-broken' );
					return;
				}
				img.addEventListener( 'error', function () {
					img.classList.add( 'is-broken' );
				} );
			} )( imgs[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', hideBrokenAvatars );
	} else {
		hideBrokenAvatars();
	}
})();
