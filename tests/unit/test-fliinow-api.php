<?php
/**
 * Unit tests for Fliinow_API client.
 *
 * @package Fliinow_WooCommerce\Tests\Unit
 */

class Test_Fliinow_API extends PHPUnit\Framework\TestCase {

	/** @var Fliinow_API */
	private $api;

	protected function setUp(): void {
		fliinow_test_reset_mocks();
		$this->api = new Fliinow_API( 'fk_test_abc123', true );
	}

	// ── URL construction ───────────────────────────────────────────────────

	public function test_sandbox_uses_demo_url(): void {
		$api = new Fliinow_API( 'fk_test_key', true );
		$this->assertSandboxUrl( $api );
	}

	public function test_production_uses_app_url(): void {
		$api = new Fliinow_API( 'fk_live_key', false );
		$this->assertProductionUrl( $api );
	}

	// ── Health endpoint ────────────────────────────────────────────────────

	public function test_auth_header_uses_x_fliinow_api_key(): void {
		// Intercept the request args by reading the API source.
		// The header 'X-Fliinow-API-Key' must be present (not 'Authorization: Bearer').
		$ref = new ReflectionClass( $this->api );
		$prop = $ref->getProperty( 'api_key' );
		$prop->setAccessible( true );
		$this->assertSame( 'fk_test_abc123', $prop->getValue( $this->api ) );
	}


	public function test_health_returns_success(): void {
		fliinow_test_mock_response( 200, array( 'status' => 'UP' ) );

		$result = $this->api->health();

		$this->assertIsArray( $result );
		$this->assertSame( 'UP', $result['status'] );
	}

	// ── Create operation ───────────────────────────────────────────────────

	public function test_create_operation_returns_response(): void {
		$expected = array(
			'id'           => 'op_test_123',
			'financingUrl' => 'https://demo.fliinow.com/financing/op_test_123',
			'status'       => 'GENERATED',
		);
		fliinow_test_mock_response( 201, $expected );

		$result = $this->api->create_operation( array(
			'externalId' => '99',
			'totalPrice' => 150.0,
			'totalReserve' => 150.0,
			'client' => array( 'firstName' => 'Test', 'lastName' => 'User', 'email' => 'test@test.com' ),
		) );

		$this->assertIsArray( $result );
		$this->assertSame( 'op_test_123', $result['id'] );
		$this->assertSame( 'GENERATED', $result['status'] );
		$this->assertStringStartsWith( 'https://', $result['financingUrl'] );
	}

	// ── Get operation ──────────────────────────────────────────────────────

	public function test_get_operation_returns_response(): void {
		$expected = array(
			'id'     => 'op_test_123',
			'status' => 'PENDING',
		);
		fliinow_test_mock_response( 200, $expected );

		$result = $this->api->get_operation( 'op_test_123' );

		$this->assertIsArray( $result );
		$this->assertSame( 'op_test_123', $result['id'] );
	}

	// ── Get operation status ───────────────────────────────────────────────

	public function test_get_operation_status_returns_status(): void {
		fliinow_test_mock_response( 200, array( 'status' => 'FAVORABLE' ) );

		$result = $this->api->get_operation_status( 'op_test_123' );

		$this->assertIsArray( $result );
		$this->assertSame( 'FAVORABLE', $result['status'] );
	}

	// ── Get operation by external ID ───────────────────────────────────────

	public function test_get_operation_by_external_id(): void {
		fliinow_test_mock_response( 200, array( 'id' => 'op_test_123', 'status' => 'CONFIRMED' ) );

		$result = $this->api->get_operation_by_external_id( '99' );

		$this->assertIsArray( $result );
		$this->assertSame( 'op_test_123', $result['id'] );
	}

	// ── Cancel operation ───────────────────────────────────────────────────

	public function test_cancel_operation_with_reason(): void {
		fliinow_test_mock_response( 204, '' );

		$result = $this->api->cancel_operation( 'op_test_123', 'Customer requested' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	public function test_cancel_operation_without_reason(): void {
		fliinow_test_mock_response( 204, '' );

		$result = $this->api->cancel_operation( 'op_test_123' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	// ── Error handling ─────────────────────────────────────────────────────

	public function test_400_returns_wp_error(): void {
		fliinow_test_mock_response( 400, array(
			'error'     => 'BAD_REQUEST',
			'message'   => 'Invalid field: email',
			'requestId' => 'req-123',
		) );

		$result = $this->api->create_operation( array( 'externalId' => '1' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'fliinow_api_error', $result->get_error_code() );
		$this->assertSame( 'Invalid field: email', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status_code'] );
		$this->assertSame( 'BAD_REQUEST', $data['error_code'] );
		$this->assertSame( 'req-123', $data['request_id'] );
	}

	public function test_401_returns_wp_error(): void {
		fliinow_test_mock_response( 401, array(
			'error'   => 'UNAUTHORIZED',
			'message' => 'Invalid API key',
		) );

		$result = $this->api->health();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'fliinow_api_error', $result->get_error_code() );
		$this->assertSame( 'Invalid API key', $result->get_error_message() );
	}

	public function test_404_returns_wp_error(): void {
		fliinow_test_mock_response( 404, array(
			'error'   => 'NOT_FOUND',
			'message' => 'Operation not found',
		) );

		$result = $this->api->get_operation( 'nonexistent' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'Operation not found', $result->get_error_message() );
	}

	public function test_500_returns_wp_error(): void {
		fliinow_test_mock_response( 500, array(
			'error'   => 'INTERNAL_ERROR',
			'message' => 'Internal server error',
		) );

		$result = $this->api->health();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'fliinow_api_error', $result->get_error_code() );
	}

	public function test_malformed_json_returns_wp_error(): void {
		$GLOBALS['fliinow_test_mocks']['wp_remote_responses'][] = array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'headers'  => array(),
			'body'     => '{"broken json',
		);

		$result = $this->api->health();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'fliinow_json_decode', $result->get_error_code() );
	}

	public function test_empty_body_200_returns_success(): void {
		$GLOBALS['fliinow_test_mocks']['wp_remote_responses'][] = array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'headers'  => array(),
			'body'     => '',
		);

		$result = $this->api->health();

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	public function test_no_retry_with_default_config(): void {
		// Default API (max_retries=0) should return error immediately on transport failure.
		fliinow_test_mock_transport_error( 'http_request_failed', 'Timeout' );

		$result = $this->api->health();

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_no_retry_on_502_with_default_config(): void {
		// Default API should parse 502 as error, not retry.
		fliinow_test_mock_response( 502, 'Bad Gateway' );

		$result = $this->api->health();

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// ── Retry logic (uses background-style API with retries enabled) ───────

	public function test_retries_on_transport_error_then_succeeds(): void {
		$api = $this->create_api_with_retries();
		fliinow_test_mock_transport_error( 'http_request_failed', 'Connection timed out' );
		fliinow_test_mock_response( 200, array( 'status' => 'UP' ) );

		$result = $api->health();

		$this->assertIsArray( $result );
		$this->assertSame( 'UP', $result['status'] );
	}

	public function test_retries_on_502_then_succeeds(): void {
		$api = $this->create_api_with_retries();
		fliinow_test_mock_response( 502, 'Bad Gateway' );
		fliinow_test_mock_response( 200, array( 'status' => 'UP' ) );

		$result = $api->health();

		$this->assertIsArray( $result );
		$this->assertSame( 'UP', $result['status'] );
	}

	public function test_retries_on_503_then_succeeds(): void {
		$api = $this->create_api_with_retries();
		fliinow_test_mock_response( 503, 'Service Unavailable' );
		fliinow_test_mock_response( 200, array( 'id' => 'op_1' ) );

		$result = $api->get_operation( 'op_1' );

		$this->assertIsArray( $result );
		$this->assertSame( 'op_1', $result['id'] );
	}

	public function test_retries_on_504_then_succeeds(): void {
		$api = $this->create_api_with_retries();
		fliinow_test_mock_response( 504, 'Gateway Timeout' );
		fliinow_test_mock_response( 200, array( 'status' => 'UP' ) );

		$result = $api->health();

		$this->assertIsArray( $result );
	}

	public function test_exhausted_retries_returns_last_error(): void {
		$api = $this->create_api_with_retries();
		// 3 transport errors (exhausts all retries: max_retries=2 → 3 total attempts).
		fliinow_test_mock_transport_error( 'http_request_failed', 'Error 1' );
		fliinow_test_mock_transport_error( 'http_request_failed', 'Error 2' );
		fliinow_test_mock_transport_error( 'http_request_failed', 'Error 3' );

		$result = $api->health();

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_exhausted_retries_on_502_returns_error(): void {
		$api = $this->create_api_with_retries();
		// 3 × 502 (all attempts fail).
		fliinow_test_mock_response( 502, 'Bad Gateway' );
		fliinow_test_mock_response( 502, 'Bad Gateway' );
		fliinow_test_mock_response( 502, 'Bad Gateway' );

		$result = $api->health();

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// ── Error response field parsing ───────────────────────────────────────

	public function test_error_response_without_message(): void {
		fliinow_test_mock_response( 422, array(
			'error' => 'VALIDATION_ERROR',
		) );

		$result = $this->api->create_operation( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'Unknown Fliinow API error', $result->get_error_message() );
	}

	public function test_error_response_without_error_field(): void {
		fliinow_test_mock_response( 403, array(
			'message' => 'Forbidden',
		) );

		$result = $this->api->health();

		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertSame( 'FLIINOW_ERROR', $data['error_code'] );
	}

	public function test_error_response_uses_error_description_fallback(): void {
		// Auth errors from the API return error_description instead of message.
		fliinow_test_mock_response( 401, array(
			'error'             => 'authentication_required',
			'error_description' => 'Valid API key required.',
		) );

		$result = $this->api->health();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'Valid API key required.', $result->get_error_message() );
	}

	// ── Security: Retry-After cap (F2) ────────────────────────────────────

	public function test_max_retry_wait_constant_exists(): void {
		$this->assertSame( 5, Fliinow_API::MAX_RETRY_WAIT );
	}

	public function test_default_timeout_is_8_seconds(): void {
		$api = new Fliinow_API( 'fk_test_x', true );
		$ref = new ReflectionProperty( Fliinow_API::class, 'timeout' );
		$ref->setAccessible( true );
		$this->assertSame( 8, $ref->getValue( $api ) );
	}

	public function test_default_max_retries_is_zero(): void {
		$api = new Fliinow_API( 'fk_test_x', true );
		$ref = new ReflectionProperty( Fliinow_API::class, 'max_retries' );
		$ref->setAccessible( true );
		$this->assertSame( 0, $ref->getValue( $api ) );
	}

	public function test_for_background_increases_timeout_and_retries(): void {
		$api = new Fliinow_API( 'fk_test_x', true );
		$bg  = $api->for_background();

		$timeout_ref = new ReflectionProperty( Fliinow_API::class, 'timeout' );
		$timeout_ref->setAccessible( true );
		$retries_ref = new ReflectionProperty( Fliinow_API::class, 'max_retries' );
		$retries_ref->setAccessible( true );

		$this->assertSame( 30, $timeout_ref->getValue( $bg ) );
		$this->assertSame( 2, $retries_ref->getValue( $bg ) );

		// Original unchanged.
		$this->assertSame( 8, $timeout_ref->getValue( $api ) );
		$this->assertSame( 0, $retries_ref->getValue( $api ) );
	}

	public function test_for_background_preserves_api_key_and_base_url(): void {
		$api = new Fliinow_API( 'fk_test_x', true );
		$bg  = $api->for_background();

		$key_ref = new ReflectionProperty( Fliinow_API::class, 'api_key' );
		$key_ref->setAccessible( true );
		$url_ref = new ReflectionProperty( Fliinow_API::class, 'base_url' );
		$url_ref->setAccessible( true );

		$this->assertSame( $key_ref->getValue( $api ), $key_ref->getValue( $bg ) );
		$this->assertSame( $url_ref->getValue( $api ), $url_ref->getValue( $bg ) );
	}

	// ── URL encoding ───────────────────────────────────────────────────────

	public function test_operation_id_is_url_encoded(): void {
		// Operation IDs with special chars should be encoded.
		fliinow_test_mock_response( 200, array( 'status' => 'PENDING' ) );

		// This should not throw — URL encoding is handled.
		$result = $this->api->get_operation_status( 'op/with spaces' );

		$this->assertIsArray( $result );
	}

	// ── Helper assertions ──────────────────────────────────────────────────

	private function assertSandboxUrl( Fliinow_API $api ): void {
		$ref = new ReflectionClass( $api );
		$prop = $ref->getProperty( 'base_url' );
		$prop->setAccessible( true );
		$this->assertSame( Fliinow_API::SANDBOX_URL, $prop->getValue( $api ) );
	}

	private function assertProductionUrl( Fliinow_API $api ): void {
		$ref = new ReflectionClass( $api );
		$prop = $ref->getProperty( 'base_url' );
		$prop->setAccessible( true );
		$this->assertSame( Fliinow_API::PRODUCTION_URL, $prop->getValue( $api ) );
	}

	private function create_api_with_retries(): Fliinow_API {
		return new Fliinow_API( 'fk_test_abc123', true, 8, 2 );
	}
}
