( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var trigger = event.target.closest( '[data-wei-confirm]' );
		if ( ! trigger ) {
			return;
		}
		if ( ! window.confirm( trigger.dataset.weiConfirm ) ) { // eslint-disable-line no-alert
			event.preventDefault();
		} else if ( trigger.form ) {
			trigger.form.dataset.weiConfirmed = '1';
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var trigger = event.submitter || document.activeElement;
		if ( '1' === form.dataset.weiConfirmed ) {
			delete form.dataset.weiConfirmed;
			return;
		}
		if ( trigger && trigger.matches( '[data-wei-confirm]' ) && ! window.confirm( trigger.dataset.weiConfirm ) ) { // eslint-disable-line no-alert
			event.preventDefault();
		}
	} );

	document.querySelectorAll( '[data-wei-dirty]' ).forEach( function ( form ) {
		var submit = form.querySelector( 'button[type="submit"], input[type="submit"]' );
		var message = form.querySelector( '[data-wei-dirty-message]' );
		var dirty = false;

		form.addEventListener( 'change', function () {
			dirty = true;
			if ( submit ) {
				submit.disabled = false;
			}
			if ( message && window.WeiAlipayDesign ) {
				message.textContent = window.WeiAlipayDesign.unsavedChanges;
			}
		} );

		form.addEventListener( 'submit', function () {
			dirty = false;
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );
	} );
}() );
