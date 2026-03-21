/**
 * Fliinow payment method for WooCommerce Blocks Checkout.
 *
 * Registers as a redirect-based payment method using the WC Blocks registry.
 * Data is provided by Fliinow_Blocks_Payment_Method::get_payment_method_data().
 */

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { decodeEntities } = window.wp.htmlEntities;
const { getSetting } = window.wc.wcSettings;
const el = window.wp.element.createElement;

const settings = getSetting( 'fliinow_data', {} );

const title       = decodeEntities( settings.title || 'Financiar con Fliinow' );
const description = decodeEntities( settings.description || '' );
const iconUrl     = settings.icon || '';
const minAmount   = parseFloat( settings.min_amount ) || 60;
const maxAmount   = parseFloat( settings.max_amount ) || 0;

/**
 * Label component — shown in the payment method list.
 */
const FliinowLabel = ( { components } ) => {
	const { PaymentMethodLabel } = components;
	return el(
		'span',
		{ style: { display: 'flex', alignItems: 'center', gap: '8px' } },
		iconUrl
			? el( 'img', {
				src: iconUrl,
				alt: 'Fliinow',
				style: { height: '22px', width: 'auto' },
			} )
			: null,
		el( PaymentMethodLabel, { text: title } )
	);
};

/**
 * Content component — shown when the method is selected.
 */
const FliinowContent = () => {
	return el(
		'div',
		{ className: 'fliinow-payment-content' },
		el( 'p', null, description ),
		el(
			'p',
			{
				className: 'fliinow-payment-redirect-note',
				style: { fontSize: '0.85em', color: '#666', marginTop: '8px' },
			},
			decodeEntities(
				'Al confirmar el pedido, serás redirigido a Fliinow para completar tu solicitud de financiación.'
			)
		)
	);
};

/**
 * Editor preview — shown in the block editor.
 */
const FliinowEdit = () => {
	return el(
		'div',
		{ className: 'fliinow-payment-edit' },
		el( 'p', { style: { fontStyle: 'italic', color: '#888' } }, title + ' — ' + description )
	);
};

/**
 * Can this payment method be used?
 * Checks cart total against min/max configured in gateway settings.
 * WC Blocks exposes totals in minor units (cents).
 */
const canMakePayment = ( { cartTotals } ) => {
	// total_price is a string of the amount in the smallest currency unit.
	const totalMinor = parseInt( cartTotals.total_price, 10 );
	if ( isNaN( totalMinor ) ) {
		return false;
	}

	// currency_minor_unit tells us the exponent (e.g. 2 for EUR => /100).
	const decimals = parseInt( cartTotals.currency_minor_unit, 10 );
	const divisor  = Math.pow( 10, isNaN( decimals ) ? 2 : decimals );
	const total    = totalMinor / divisor;

	if ( total < minAmount ) {
		return false;
	}
	if ( maxAmount > 0 && total > maxAmount ) {
		return false;
	}
	return true;
};

registerPaymentMethod( {
	name: 'fliinow',
	label: el( FliinowLabel, null ),
	content: el( FliinowContent, null ),
	edit: el( FliinowEdit, null ),
	canMakePayment,
	paymentMethodId: 'fliinow',
	ariaLabel: title,
	supports: {
		features: settings.supports || [ 'products' ],
	},
} );
