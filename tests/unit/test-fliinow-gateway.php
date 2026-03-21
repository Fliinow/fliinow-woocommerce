<?php
/**
 * Unit tests for Fliinow_Gateway.
 *
 * @package Fliinow_WooCommerce\Tests\Unit
 */

class Test_Fliinow_Gateway extends PHPUnit\Framework\TestCase {

	/** @var Fliinow_Gateway */
	private $gateway;

	protected function setUp(): void {
		fliinow_test_reset_mocks();
		$GLOBALS['fliinow_test_mocks']['options']['woocommerce_fliinow_settings'] = array(
			'enabled'        => 'yes',
			'api_key'        => 'fk_test_abc123',
			'sandbox'        => 'yes',
			'title'          => 'Financiar con Fliinow',
			'description'    => 'Financia tu compra a plazos.',
			'min_amount'     => '60',
			'max_amount'     => '0',
			'package_travel' => 'yes',
			'debug'          => 'yes',
		);
		$this->gateway = new Fliinow_Gateway();
	}

	// ── Constructor / Settings ─────────────────────────────────────────────

	public function test_gateway_id(): void {
		$this->assertSame( 'fliinow', $this->gateway->id );
	}

	public function test_gateway_supports_products_and_refunds(): void {
		$this->assertContains( 'products', $this->gateway->supports );
		$this->assertContains( 'refunds', $this->gateway->supports );
	}

	public function test_gateway_has_form_fields(): void {
		$ref = new ReflectionClass( $this->gateway );
		$prop = $ref->getProperty( 'form_fields' );
		$prop->setAccessible( true );
		$fields = $prop->getValue( $this->gateway );

		$expected_keys = array(
			'enabled', 'title', 'description', 'api_key', 'sandbox',
			'health_check', 'min_amount', 'max_amount', 'package_travel', 'debug',
		);
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $fields, "Missing form field: $key" );
		}
	}

	public function test_api_initialized_with_key(): void {
		$this->assertNotNull( $this->gateway->get_api() );
	}

	public function test_api_null_without_key(): void {
		$GLOBALS['fliinow_test_mocks']['options']['woocommerce_fliinow_settings']['api_key'] = '';
		$gateway = new Fliinow_Gateway();
		$this->assertNull( $gateway->get_api() );
	}

	// ── Availability ──────────────────────────────────────────────────────

	public function test_available_when_total_above_min(): void {
		WC()->cart->set_total( 100.0 );
		$this->assertTrue( $this->gateway->is_available() );
	}

	public function test_not_available_when_total_below_min(): void {
		WC()->cart->set_total( 30.0 );
		$this->assertFalse( $this->gateway->is_available() );
	}

	public function test_not_available_when_total_above_max(): void {
		$GLOBALS['fliinow_test_mocks']['options']['woocommerce_fliinow_settings']['max_amount'] = '500';
		$gateway = new Fliinow_Gateway();
		WC()->cart->set_total( 600.0 );
		$this->assertFalse( $gateway->is_available() );
	}

	public function test_available_when_max_is_zero(): void {
		WC()->cart->set_total( 99999.0 );
		$this->assertTrue( $this->gateway->is_available() );
	}

	public function test_not_available_without_api_key(): void {
		$GLOBALS['fliinow_test_mocks']['options']['woocommerce_fliinow_settings']['api_key'] = '';
		$gateway = new Fliinow_Gateway();
		$this->assertFalse( $gateway->is_available() );
	}

	public function test_not_available_when_disabled(): void {
		$GLOBALS['fliinow_test_mocks']['options']['woocommerce_fliinow_settings']['enabled'] = 'no';
		$gateway = new Fliinow_Gateway();
		$this->assertFalse( $gateway->is_available() );
	}

	// ── Build operation data ──────────────────────────────────────────────

	public function test_build_operation_data_structure(): void {
		$order = $this->create_order();
		$data  = $this->invoke_build_operation_data( $order );

		// Top-level keys (all fields from CreateOperationRequest).
		$expected_keys = array(
			'externalId', 'client', 'packageName', 'packageTravel',
			'travelersNumber', 'flightDtoList', 'hotelDtoList',
			'serviceDtoList', 'feeDtoList', 'totalPrice', 'totalReserve',
			'successCallbackUrl', 'errorCallbackUrl',
		);
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $data, "Missing top-level key: $key" );
		}
	}

	public function test_build_operation_data_client_fields(): void {
		$order = $this->create_order();
		$data  = $this->invoke_build_operation_data( $order );

		$client = $data['client'];
		$expected_client_keys = array(
			'firstName', 'lastName', 'email', 'prefix', 'phone',
			'documentId', 'documentValidityDate', 'gender', 'birthDate',
			'nationality', 'address', 'city', 'postalCode', 'countryCode',
		);
		foreach ( $expected_client_keys as $key ) {
			$this->assertArrayHasKey( $key, $client, "Missing client field: $key" );
		}

		$this->assertSame( 'Juan', $client['firstName'] );
		$this->assertSame( 'García', $client['lastName'] );
		$this->assertSame( 'juan@example.com', $client['email'] );
		$this->assertSame( '+34', $client['prefix'] );
		$this->assertSame( '612345678', $client['phone'] );
		$this->assertSame( 'ESP', $client['nationality'] );
		$this->assertSame( 'ES', $client['countryCode'] );
		$this->assertSame( 'Madrid', $client['city'] );
		$this->assertSame( '28001', $client['postalCode'] );
		$this->assertStringContains( 'Calle Mayor 1', $client['address'] );
	}

	public function test_build_operation_data_totals(): void {
		$order = $this->create_order();
		$data  = $this->invoke_build_operation_data( $order );

		$this->assertSame( 150.0, $data['totalPrice'] );
		$this->assertSame( 150.0, $data['totalReserve'] );
	}

	public function test_build_operation_data_external_id_is_string(): void {
		$order = $this->create_order();
		$data  = $this->invoke_build_operation_data( $order );

		$this->assertIsString( $data['externalId'] );
		$this->assertSame( '1', $data['externalId'] );
	}

	public function test_build_operation_data_callbacks_contain_order_id(): void {
		$order = $this->create_order();
		$data  = $this->invoke_build_operation_data( $order );

		$this->assertStringContainsString( 'order_id=1', $data['successCallbackUrl'] );
		$this->assertStringContainsString( 'status=success', $data['successCallbackUrl'] );
		$this->assertStringContainsString( 'order_id=1', $data['errorCallbackUrl'] );
		$this->assertStringContainsString( 'status=error', $data['errorCallbackUrl'] );
	}

	public function test_build_operation_data_callbacks_contain_nonce(): void {
		$order = $this->create_order();
		$data  = $this->invoke_build_operation_data( $order );

		$this->assertStringContainsString( '_wpnonce=', $data['successCallbackUrl'] );
		$this->assertStringContainsString( '_wpnonce=', $data['errorCallbackUrl'] );
	}

	public function test_build_operation_data_package_travel_bool(): void {
		$order = $this->create_order();
		$data  = $this->invoke_build_operation_data( $order );

		$this->assertTrue( $data['packageTravel'] );
	}

	public function test_build_operation_data_empty_lists(): void {
		$order = $this->create_order();
		$data  = $this->invoke_build_operation_data( $order );

		$this->assertIsArray( $data['flightDtoList'] );
		$this->assertEmpty( $data['flightDtoList'] );
		$this->assertIsArray( $data['hotelDtoList'] );
		$this->assertEmpty( $data['hotelDtoList'] );
		$this->assertIsArray( $data['serviceDtoList'] );
		$this->assertEmpty( $data['serviceDtoList'] );
		$this->assertIsArray( $data['feeDtoList'] );
		$this->assertEmpty( $data['feeDtoList'] );
	}

	// ── Phone prefix stripping ─────────────────────────────────────────────

	public function test_phone_strips_spanish_prefix(): void {
		$order = $this->create_order();
		$order->set_data( 'billing_phone', '+34612345678' );
		$data = $this->invoke_build_operation_data( $order );

		$this->assertSame( '612345678', $data['client']['phone'] );
		$this->assertSame( '+34', $data['client']['prefix'] );
	}

	public function test_phone_strips_prefix_without_plus(): void {
		$order = $this->create_order();
		$order->set_data( 'billing_phone', '34612345678' );
		$data = $this->invoke_build_operation_data( $order );

		$this->assertSame( '612345678', $data['client']['phone'] );
	}

	public function test_phone_preserves_clean_number(): void {
		$order = $this->create_order();
		$order->set_data( 'billing_phone', '612345678' );
		$data = $this->invoke_build_operation_data( $order );

		$this->assertSame( '612345678', $data['client']['phone'] );
	}

	public function test_phone_strips_formatting(): void {
		$order = $this->create_order();
		$order->set_data( 'billing_phone', '612 345 678' );
		$data = $this->invoke_build_operation_data( $order );

		$this->assertSame( '612345678', $data['client']['phone'] );
	}

	public function test_phone_strips_dashes_and_parens(): void {
		$order = $this->create_order();
		$order->set_data( 'billing_phone', '(612) 345-678' );
		$data = $this->invoke_build_operation_data( $order );

		$this->assertSame( '612345678', $data['client']['phone'] );
	}

	public function test_portuguese_prefix(): void {
		$order = $this->create_order();
		$order->set_data( 'billing_country', 'PT' );
		$order->set_data( 'billing_phone', '351912345678' );
		$data = $this->invoke_build_operation_data( $order );

		$this->assertSame( '+351', $data['client']['prefix'] );
		$this->assertSame( '912345678', $data['client']['phone'] );
	}

	public function test_french_prefix(): void {
		$order = $this->create_order();
		$order->set_data( 'billing_country', 'FR' );
		$data = $this->invoke_build_operation_data( $order );

		$this->assertSame( '+33', $data['client']['prefix'] );
	}

	public function test_unknown_country_defaults_to_spain(): void {
		$order = $this->create_order();
		$order->set_data( 'billing_country', 'ZZ' );
		$data = $this->invoke_build_operation_data( $order );

		$this->assertSame( '+34', $data['client']['prefix'] );
	}

	// ── Gender normalization ───────────────────────────────────────────────

	public function test_gender_male(): void {
		$this->assertNormalizedGender( 'MALE', 'MALE' );
	}

	public function test_gender_female(): void {
		$this->assertNormalizedGender( 'FEMALE', 'FEMALE' );
	}

	public function test_gender_hombre(): void {
		$this->assertNormalizedGender( 'MALE', 'HOMBRE' );
	}

	public function test_gender_mujer(): void {
		$this->assertNormalizedGender( 'FEMALE', 'MUJER' );
	}

	public function test_gender_m(): void {
		$this->assertNormalizedGender( 'MALE', 'M' );
	}

	public function test_gender_f(): void {
		$this->assertNormalizedGender( 'FEMALE', 'F' );
	}

	public function test_gender_h(): void {
		$this->assertNormalizedGender( 'MALE', 'H' );
	}

	public function test_gender_lowercase(): void {
		$this->assertNormalizedGender( 'MALE', 'male' );
	}

	public function test_gender_empty_defaults_male(): void {
		$this->assertNormalizedGender( 'MALE', '' );
	}

	// ── Nationality codes ──────────────────────────────────────────────────

	public function test_nationality_spain(): void {
		$order = $this->create_order();
		$order->set_data( 'billing_country', 'ES' );
		$data = $this->invoke_build_operation_data( $order );
		$this->assertSame( 'ESP', $data['client']['nationality'] );
	}

	public function test_nationality_portugal(): void {
		$order = $this->create_order();
		$order->set_data( 'billing_country', 'PT' );
		$data = $this->invoke_build_operation_data( $order );
		$this->assertSame( 'PRT', $data['client']['nationality'] );
	}

	public function test_nationality_unknown_returns_uppercase(): void {
		$order = $this->create_order();
		$order->set_data( 'billing_country', 'jp' );
		$data = $this->invoke_build_operation_data( $order );
		$this->assertSame( 'JP', $data['client']['nationality'] );
	}

	// ── Package name ─────────────────────────────────────────────────────

	public function test_package_name_from_items(): void {
		$order = $this->create_order();
		$order->set_items( array(
			new WC_Order_Item_Product( 'Vuelo Madrid-Paris', 2 ),
			new WC_Order_Item_Product( 'Hotel Paris 3 noches', 1 ),
		) );
		$data = $this->invoke_build_operation_data( $order );

		$this->assertSame( 'Vuelo Madrid-Paris x2, Hotel Paris 3 noches x1', $data['packageName'] );
	}

	public function test_package_name_truncated_at_200(): void {
		$order = $this->create_order();
		$items = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$items[] = new WC_Order_Item_Product( 'Producto con nombre largo numero ' . $i, 1 );
		}
		$order->set_items( $items );
		$data = $this->invoke_build_operation_data( $order );

		$this->assertLessThanOrEqual( 200, mb_strlen( $data['packageName'] ) );
	}

	public function test_package_name_fallback_without_items(): void {
		$order = $this->create_order();
		$data  = $this->invoke_build_operation_data( $order );

		$this->assertStringContainsString( 'Pedido #1', $data['packageName'] );
	}

	// ── Process payment ───────────────────────────────────────────────────

	public function test_process_payment_success(): void {
		$order = $this->create_order();
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;

		fliinow_test_mock_response( 201, array(
			'id'           => 'op_test_456',
			'financingUrl' => 'https://demo.fliinow.com/financing/op_test_456',
			'status'       => 'GENERATED',
		) );

		$result = $this->gateway->process_payment( 1 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://demo.fliinow.com/financing/op_test_456', $result['redirect'] );
		$this->assertSame( 'op_test_456', $order->get_meta( '_fliinow_operation_id' ) );
		$this->assertSame( 'https://demo.fliinow.com/financing/op_test_456', $order->get_meta( '_fliinow_financing_url' ) );
	}

	public function test_process_payment_reuses_existing_operation(): void {
		$order = $this->create_order();
		$order->set_meta( '_fliinow_operation_id', 'op_existing' );
		$order->set_meta( '_fliinow_financing_url', 'https://demo.fliinow.com/financing/op_existing' );
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;

		$result = $this->gateway->process_payment( 1 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://demo.fliinow.com/financing/op_existing', $result['redirect'] );
	}

	public function test_process_payment_api_error(): void {
		$order = $this->create_order();
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;
		$GLOBALS['fliinow_test_mocks']['notices'] = array();

		fliinow_test_mock_transport_error();

		// Exhaust retries.
		fliinow_test_mock_transport_error();
		fliinow_test_mock_transport_error();

		$result = $this->gateway->process_payment( 1 );

		$this->assertSame( 'failure', $result['result'] );
	}

	public function test_process_payment_no_order(): void {
		$GLOBALS['fliinow_test_mocks']['current_order'] = null;
		$GLOBALS['fliinow_test_mocks']['notices'] = array();

		$result = $this->gateway->process_payment( 999 );

		$this->assertSame( 'failure', $result['result'] );
	}

	public function test_process_payment_no_api(): void {
		$GLOBALS['fliinow_test_mocks']['options']['woocommerce_fliinow_settings']['api_key'] = '';
		$gateway = new Fliinow_Gateway();
		$order   = $this->create_order();
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;
		$GLOBALS['fliinow_test_mocks']['notices'] = array();

		$result = $gateway->process_payment( 1 );

		$this->assertSame( 'failure', $result['result'] );
	}

	public function test_process_payment_missing_financing_url(): void {
		$order = $this->create_order();
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;
		$GLOBALS['fliinow_test_mocks']['notices'] = array();

		fliinow_test_mock_response( 201, array(
			'id'     => 'op_test_789',
			'status' => 'GENERATED',
			// Missing financingUrl.
		) );

		$result = $this->gateway->process_payment( 1 );

		$this->assertSame( 'failure', $result['result'] );
	}

	// ── Refunds ───────────────────────────────────────────────────────────

	public function test_refund_success(): void {
		$order = $this->create_order();
		$order->set_meta( '_fliinow_operation_id', 'op_test_refund' );
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;

		fliinow_test_mock_response( 204, '' );

		$result = $this->gateway->process_refund( 1, 50.0, 'Customer request' );

		$this->assertTrue( $result );
		$this->assertSame( 'CANCELLED', $order->get_meta( '_fliinow_status' ) );
	}

	public function test_refund_no_operation_id(): void {
		$order = $this->create_order();
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;

		$result = $this->gateway->process_refund( 1, 50.0 );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_refund_no_order(): void {
		$GLOBALS['fliinow_test_mocks']['current_order'] = null;

		$result = $this->gateway->process_refund( 999, 50.0 );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private function create_order(): WC_Order {
		return new WC_Order( 1 );
	}

	private function invoke_build_operation_data( WC_Order $order ): array {
		$ref    = new ReflectionMethod( $this->gateway, 'build_operation_data' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->gateway, $order );
	}

	private function assertNormalizedGender( string $expected, string $input ): void {
		$ref = new ReflectionMethod( $this->gateway, 'normalize_gender' );
		$ref->setAccessible( true );
		$this->assertSame( $expected, $ref->invoke( $this->gateway, $input ) );
	}

	/**
	 * BC shim — PHPUnit 9 renamed assertContains for strings.
	 */
	private function assertStringContains( string $needle, string $haystack ): void {
		$this->assertStringContainsString( $needle, $haystack );
	}
}
