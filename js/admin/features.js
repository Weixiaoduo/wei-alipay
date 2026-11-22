( function () {
	'use strict';

	var form = document.querySelector( '[data-wei-alipay-feature-form]' );
	if ( ! form ) {
		return;
	}

	var submit = form.querySelector( 'button[type="submit"]' );
	var dirtyMessage = form.querySelector( '.wei-alipay-features__dirty' );
	var dirty = false;

	function setDirty() {
		dirty = true;
		submit.disabled = false;
		dirtyMessage.textContent = window.WeiAlipayFeatures ? window.WeiAlipayFeatures.unsavedChanges : '';
	}

	form.addEventListener( 'change', function ( event ) {
		var checkbox = event.target;
		if ( checkbox.type !== 'checkbox' ) {
			return;
		}
		var row = checkbox.closest( '.wei-alipay-features__row' );
		var status = row ? row.querySelector( '[data-feature-status]' ) : null;
		if ( status ) {
			status.textContent = checkbox.checked ? status.dataset.on : status.dataset.off;
			status.classList.toggle( 'is-success', checkbox.checked );
			status.classList.toggle( 'is-light', ! checkbox.checked );
		}
		setDirty();
	} );

	form.addEventListener( 'submit', function () {
		dirty = false;
		submit.disabled = true;
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( ! dirty ) {
			return;
		}
		event.preventDefault();
		event.returnValue = '';
	} );
}() );
