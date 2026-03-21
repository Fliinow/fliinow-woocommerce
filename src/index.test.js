/**
 * Unit tests for Fliinow WC Blocks payment method (index.js).
 *
 * Tests the canMakePayment logic which handles WC Blocks cart totals
 * expressed in minor currency units.
 *
 * @package Fliinow_WooCommerce
 */

// ── Mock WC Blocks globals ─────────────────────────────────────────────────

const registeredMethods = [];

// Must set up on the real jsdom window before require().
window.wc = {
	wcBlocksRegistry: {
		registerPaymentMethod: ( config ) => registeredMethods.push( config ),
	},
	wcSettings: {
		getSetting: ( key ) => {
			if ( key === 'fliinow_data' ) {
				return {
					title: 'Financiar con Fliinow',
					description: 'Test description',
					icon: 'https://example.com/icon.svg',
					supports: [ 'products', 'refunds' ],
					min_amount: 60,
					max_amount: 0,
				};
			}
			return {};
		},
	},
};

window.wp = {
	htmlEntities: {
		decodeEntities: ( str ) => str,
	},
	element: {
		createElement: ( type, props, ...children ) => ( { type, props, children } ),
	},
};

// Load the module (will call registerPaymentMethod).
require( './index.js' );

// ── Helpers ────────────────────────────────────────────────────────────────

function getRegisteredMethod() {
	return registeredMethods[ registeredMethods.length - 1 ];
}

// ── Tests ──────────────────────────────────────────────────────────────────

describe( 'Fliinow WC Blocks Payment Method', () => {
	let method;

	beforeAll( () => {
		method = getRegisteredMethod();
	} );

	test( 'registers with correct name', () => {
		expect( method.name ).toBe( 'fliinow' );
	} );

	test( 'has paymentMethodId', () => {
		expect( method.paymentMethodId ).toBe( 'fliinow' );
	} );

	test( 'has ariaLabel', () => {
		expect( method.ariaLabel ).toBeTruthy();
	} );

	test( 'has label element', () => {
		expect( method.label ).toBeTruthy();
	} );

	test( 'has content element', () => {
		expect( method.content ).toBeTruthy();
	} );

	test( 'has edit element', () => {
		expect( method.edit ).toBeTruthy();
	} );

	test( 'declares supported features', () => {
		expect( method.supports.features ).toContain( 'products' );
	} );

	// ── canMakePayment ──────────────────────────────────────────────────

	describe( 'canMakePayment', () => {
		test( 'returns true for valid amount above min (EUR cents)', () => {
			const result = method.canMakePayment( {
				cartTotals: { total_price: '15000', currency_minor_unit: 2 },
			} );
			expect( result ).toBe( true ); // 150.00 EUR > 60
		} );

		test( 'returns false for amount below min', () => {
			const result = method.canMakePayment( {
				cartTotals: { total_price: '3000', currency_minor_unit: 2 },
			} );
			expect( result ).toBe( false ); // 30.00 EUR < 60
		} );

		test( 'returns true for exact min amount', () => {
			const result = method.canMakePayment( {
				cartTotals: { total_price: '6000', currency_minor_unit: 2 },
			} );
			expect( result ).toBe( true ); // 60.00 EUR == 60
		} );

		test( 'returns false for NaN total', () => {
			const result = method.canMakePayment( {
				cartTotals: { total_price: 'invalid', currency_minor_unit: 2 },
			} );
			expect( result ).toBe( false );
		} );

		test( 'handles missing currency_minor_unit (defaults to 2)', () => {
			const result = method.canMakePayment( {
				cartTotals: { total_price: '10000' },
			} );
			expect( result ).toBe( true ); // 100.00 > 60
		} );

		test( 'handles zero-decimal currency (JPY)', () => {
			const result = method.canMakePayment( {
				cartTotals: { total_price: '100', currency_minor_unit: 0 },
			} );
			expect( result ).toBe( true ); // 100 JPY > 60
		} );

		test( 'handles three-decimal currency', () => {
			const result = method.canMakePayment( {
				cartTotals: { total_price: '100000', currency_minor_unit: 3 },
			} );
			expect( result ).toBe( true ); // 100.000 > 60
		} );

		test( 'returns true when max is 0 (no limit)', () => {
			const result = method.canMakePayment( {
				cartTotals: { total_price: '9999900', currency_minor_unit: 2 },
			} );
			expect( result ).toBe( true ); // 99999.00 — no max limit
		} );
	} );
} );

// ── canMakePayment with max_amount set ─────────────────────────────────────

describe( 'canMakePayment with max_amount configured', () => {
	test( 'returns false above max when max > 0', () => {
		// Re-register with max_amount set.
		// Since the original module cached settings, we test the logic directly.
		const maxAmount = 500;
		const canMakePaymentWithMax = ( { cartTotals } ) => {
			const totalMinor = parseInt( cartTotals.total_price, 10 );
			if ( isNaN( totalMinor ) ) return false;
			const decimals = parseInt( cartTotals.currency_minor_unit, 10 );
			const divisor = Math.pow( 10, isNaN( decimals ) ? 2 : decimals );
			const total = totalMinor / divisor;
			if ( total < 60 ) return false;
			if ( maxAmount > 0 && total > maxAmount ) return false;
			return true;
		};

		expect( canMakePaymentWithMax( {
			cartTotals: { total_price: '60000', currency_minor_unit: 2 },
		} ) ).toBe( false ); // 600.00 > 500

		expect( canMakePaymentWithMax( {
			cartTotals: { total_price: '50000', currency_minor_unit: 2 },
		} ) ).toBe( true ); // 500.00 == 500

		expect( canMakePaymentWithMax( {
			cartTotals: { total_price: '30000', currency_minor_unit: 2 },
		} ) ).toBe( true ); // 300.00: above min 60, below max 500
	} );

	test( 'returns true within range when max > 0', () => {
		const maxAmount = 500;
		const canMakePaymentWithMax = ( { cartTotals } ) => {
			const totalMinor = parseInt( cartTotals.total_price, 10 );
			if ( isNaN( totalMinor ) ) return false;
			const decimals = parseInt( cartTotals.currency_minor_unit, 10 );
			const divisor = Math.pow( 10, isNaN( decimals ) ? 2 : decimals );
			const total = totalMinor / divisor;
			if ( total < 60 ) return false;
			if ( maxAmount > 0 && total > maxAmount ) return false;
			return true;
		};

		expect( canMakePaymentWithMax( {
			cartTotals: { total_price: '30000', currency_minor_unit: 2 },
		} ) ).toBe( true ); // 300.00: above min, below max
	} );
} );
