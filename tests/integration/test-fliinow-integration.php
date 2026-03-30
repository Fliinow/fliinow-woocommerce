<?php
/**
 * Integration tests against Fliinow Sandbox API.
 *
 * These tests make real HTTP requests to the Fliinow sandbox environment.
 * They require FLIINOW_TEST_API_KEY to be set (configured in phpunit.xml).
 *
 * @group integration
 * @package Fliinow_Checkout\Tests\Integration
 */

class Test_Fliinow_Integration extends PHPUnit\Framework\TestCase {

	/** @var string */
	private $api_key;

	/** @var string */
	private $base_url;

	protected function setUp(): void {
		$this->api_key  = getenv( 'FLIINOW_TEST_API_KEY' ) ?: '';
		$this->base_url = Fliinow_API::SANDBOX_URL;

		if ( empty( $this->api_key ) ) {
			$this->markTestSkipped( 'FLIINOW_TEST_API_KEY not set — skipping integration tests.' );
		}
	}

	// ── Health ─────────────────────────────────────────────────────────────

	public function test_health_endpoint(): void {
		$response = $this->http_get( '/health' );

		$this->assertNotNull( $response, 'Health endpoint should respond' );
		$this->assertArrayHasKey( 'http_code', $response );

		// 200 means the API is up; 401 means our key is checked after /health.
		$this->assertContains(
			$response['http_code'],
			array( 200, 401 ),
			'Health should return 200 or 401, got: ' . $response['http_code']
		);
	}

	// ── Create operation ───────────────────────────────────────────────────

	public function test_create_operation_with_valid_data(): void {
		$payload = $this->build_test_operation();

		$response = $this->http_post( '/operations', $payload );

		$this->assertNotNull( $response );

		if ( 201 === $response['http_code'] || 200 === $response['http_code'] ) {
			// Success — verify response structure.
			$body = $response['body'];
			$this->assertArrayHasKey( 'id', $body, 'Response must contain id' );
			$this->assertArrayHasKey( 'financingUrl', $body, 'Response must contain financingUrl' );
			$this->assertArrayHasKey( 'status', $body, 'Response must contain status' );
			$this->assertNotEmpty( $body['id'] );
			$this->assertNotEmpty( $body['financingUrl'] );
		} elseif ( 401 === $response['http_code'] ) {
			// Key not authorized — acceptable in CI.
			$body = $response['body'];
			$this->assertNotEmpty( $body, 'Error response should have body' );
			$this->markTestSkipped( 'API key not authorized for sandbox: ' . json_encode( $body ) );
		} else {
			// 400/422 = field validation — check error structure.
			$body = $response['body'];
			$this->assertArrayHasKey( 'message', $body, 'Error response must contain message' );
		}
	}

	public function test_create_operation_field_names_match_api(): void {
		$payload = $this->build_test_operation();

		// Verify all top-level field names match CreateOperationRequest in types.ts.
		$valid_fields = array(
			'externalId', 'client', 'packageName', 'packageTravel',
			'travelersNumber', 'flightDtoList', 'hotelDtoList',
			'serviceDtoList', 'feeDtoList', 'totalPrice', 'totalReserve',
			'successCallbackUrl', 'errorCallbackUrl',
		);

		foreach ( array_keys( $payload ) as $field ) {
			$this->assertContains( $field, $valid_fields, "Unexpected field in payload: $field" );
		}

		// Verify all client field names match ClientDto.
		$valid_client_fields = array(
			'firstName', 'lastName', 'email', 'prefix', 'phone',
			'documentId', 'documentValidityDate', 'gender', 'birthDate',
			'nationality', 'address', 'city', 'postalCode', 'countryCode',
		);

		foreach ( array_keys( $payload['client'] ) as $field ) {
			$this->assertContains( $field, $valid_client_fields, "Unexpected client field: $field" );
		}
	}

	// ── Get operation by external ID ───────────────────────────────────────

	public function test_get_by_external_id_returns_structured_response(): void {
		$response = $this->http_get( '/operations/by-external-id/test-nonexistent-' . time() );

		$this->assertNotNull( $response );

		// 404 = not found (expected), 401 = auth issue, 200 = found.
		$this->assertContains(
			$response['http_code'],
			array( 200, 401, 404 ),
			'Expected 200/401/404, got: ' . $response['http_code']
		);

		// Verify we got a parseable JSON body.
		$this->assertNotEmpty( $response['body'] );
	}

	// ── Error response structure ───────────────────────────────────────────

	public function test_error_response_has_correct_fields(): void {
		// Send deliberately bad request to get error structure.
		$response = $this->http_post( '/operations', array() );

		$this->assertNotNull( $response );

		if ( $response['http_code'] >= 400 ) {
			$body = $response['body'];
			// ErrorResponse should have: error, message, requestId.
			if ( is_array( $body ) ) {
				// At minimum, message should be present.
				$this->assertTrue(
					isset( $body['message'] ) || isset( $body['error'] ),
					'Error response should have message or error field'
				);
			}
		}
	}

	// ── URL constants match SDK ────────────────────────────────────────────

	public function test_sandbox_url_matches_sdk(): void {
		$this->assertSame(
			'https://demo.fliinow.com/integration-api/v1',
			Fliinow_API::SANDBOX_URL
		);
	}

	public function test_production_url_matches_sdk(): void {
		$this->assertSame(
			'https://app.fliinow.com/integration-api/v1',
			Fliinow_API::PRODUCTION_URL
		);
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private function build_test_operation(): array {
		return array(
			'externalId'         => 'wc-test-' . time(),
			'client'             => array(
				'firstName'            => 'Test',
				'lastName'             => 'WooCommerce',
				'email'                => 'test-wc@fliinow.com',
				'prefix'               => '+34',
				'phone'                => '612345678',
				'documentId'           => '12345678Z',
				'documentValidityDate' => '',
				'gender'               => 'MALE',
				'birthDate'            => '1990-01-15',
				'nationality'          => 'ESP',
				'address'              => 'Calle Test 1',
				'city'                 => 'Madrid',
				'postalCode'           => '28001',
				'countryCode'          => 'ES',
			),
			'packageName'        => 'WooCommerce Integration Test',
			'packageTravel'      => false,
			'travelersNumber'    => 1,
			'flightDtoList'      => array(),
			'hotelDtoList'       => array(),
			'serviceDtoList'     => array(),
			'feeDtoList'         => array(),
			'totalPrice'         => 150.00,
			'totalReserve'       => 150.00,
			'successCallbackUrl' => 'https://example.com/success',
			'errorCallbackUrl'   => 'https://example.com/error',
		);
	}

	/**
	 * Perform a GET request against the sandbox API.
	 *
	 * Uses cURL directly (bypasses WP mocks in the bootstrap).
	 */
	private function http_get( string $path ): ?array {
		return $this->http_request( 'GET', $path );
	}

	/**
	 * Perform a POST request against the sandbox API.
	 */
	private function http_post( string $path, array $body ): ?array {
		return $this->http_request( 'POST', $path, $body );
	}

	private function http_request( string $method, string $path, ?array $body = null ): ?array {
		$url = $this->base_url . $path;

		$ch = curl_init( $url );
		if ( ! $ch ) {
			return null;
		}

		$headers = array(
			'X-Fliinow-API-Key: ' . $this->api_key,
			'Content-Type: application/json',
			'Accept: application/json',
			'User-Agent: FliinowCheckout/' . FLIINOW_WC_VERSION . ' PHPUnit/Integration',
		);

		curl_setopt_array( $ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_CUSTOMREQUEST  => $method,
		) );

		if ( $body !== null && 'POST' === $method ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) );
		}

		$response_body = curl_exec( $ch );
		$http_code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error         = curl_error( $ch );
		curl_close( $ch );

		if ( false === $response_body ) {
			$this->markTestSkipped( 'cURL error: ' . $error );
			return null;
		}

		$decoded = json_decode( $response_body, true );

		return array(
			'http_code' => $http_code,
			'body'      => is_array( $decoded ) ? $decoded : array( 'raw' => $response_body ),
		);
	}
}
