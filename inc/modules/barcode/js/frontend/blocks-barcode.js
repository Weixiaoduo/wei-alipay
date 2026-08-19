(function(){
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { __ } = window.wp.i18n;
	const { createElement, useState, useEffect } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;
	const settings = window.wc.wcSettings.getSetting( 'alipay_barcode_data', {} );

	const defaultLabel = __( 'Alipay Barcode Pay', 'wei-alipay-barcode' );
	const defaultDescription = __( 'Enter or show your Alipay auth code to complete payment.', 'wei-alipay-barcode' );

	const Label = ( props ) => {
		const { PaymentMethodLabel } = props.components;
		const iconElement = settings.icon ? createElement( 'img', {
			src: settings.icon,
			alt: decodeEntities( settings.title || defaultLabel ),
			style: { width: '24px', height: '24px', marginRight: '8px', verticalAlign: 'middle' }
		} ) : null;
		return createElement( 'div', { style: { display: 'flex', alignItems: 'center' } }, [
			iconElement,
			createElement( PaymentMethodLabel, { text: decodeEntities( settings.title || defaultLabel ), key: 'label' } )
		] );
	};

	const Content = () => {
		const [authCode, setAuthCode] = useState( '' );

		useEffect( () => {
			// Hook into Blocks checkout lifecycle to pass auth_code to the Store API.
			if ( window.wc && window.wc.blocksCheckout && typeof window.wc.blocksCheckout === 'object' ) {
				const { onPaymentSetup } = window.wc.blocksCheckout;
				if ( typeof onPaymentSetup === 'function' ) {
					onPaymentSetup( ( { setPaymentMethodData, responseTypes } ) => {
						if ( ! authCode ) {
							return { type: responseTypes.ERROR, message: __( 'Please enter the Alipay auth code.', 'wei-alipay-barcode' ) };
						}
						// Provide data to the checkout store so the gateway can read it server-side.
						if ( typeof setPaymentMethodData === 'function' ) {
							setPaymentMethodData( { alipay_auth_code: authCode } );
						}
						return { type: responseTypes.SUCCESS };
					} );
				}
			}
		}, [ authCode ] );

		return createElement( 'div', { style: { padding: '10px 0' } }, [
			createElement( 'p', { key: 'description', style: { marginBottom: '8px' } }, decodeEntities( settings.description || defaultDescription ) ),
			createElement( 'label', { key: 'label', htmlFor: 'alipay_auth_code_blocks', style: { display: 'block', marginBottom: '6px' } }, __( 'Alipay auth code', 'wei-alipay-barcode' ) ),
			createElement( 'input', {
				key: 'input', id: 'alipay_auth_code_blocks', type: 'text',
				placeholder: '2853 1234 5678 9012 345',
				style: { width: '260px' },
				value: authCode,
				onChange: (e) => setAuthCode( e.target.value )
			} ),
		] );
	};

	const method = {
		name: 'alipay_barcode',
		label: createElement( Label ),
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: () => true,
		ariaLabel: decodeEntities( settings.title || defaultLabel ),
		supports: { features: settings?.supports ?? [ 'products' ] },
	};

	registerPaymentMethod( method );
})();
