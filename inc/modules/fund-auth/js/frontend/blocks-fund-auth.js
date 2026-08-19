(function(){
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { __ } = window.wp.i18n;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;
	const settings = window.wc.wcSettings.getSetting( 'alipay_fund_auth_data', {} );

	const defaultLabel = __( 'Alipay Fund Authorization', 'wei-alipay-fund-auth' );
	const defaultDescription = __( 'Your payment will be authorized (frozen) now and captured upon fulfillment.', 'wei-alipay-fund-auth' );

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
		return createElement( 'div', { style: { padding: '10px 0' } }, [
			createElement( 'p', { key: 'description', style: { marginBottom: '8px' } }, decodeEntities( settings.description || defaultDescription ) ),
		] );
	};

	const method = {
		name: 'alipay_fund_auth',
		label: createElement( Label ),
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: () => true,
		ariaLabel: decodeEntities( settings.title || defaultLabel ),
		supports: { features: settings?.supports ?? [ 'products' ] },
	};

	registerPaymentMethod( method );
})();
