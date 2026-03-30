<?php
/**
 * Unit tests for Fliinow_Webhook.
 *
 * @package Fliinow_Checkout_Financing\Tests\Unit
 */

class Test_Fliinow_Webhook extends PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		fliinow_test_reset_mocks();
		$GLOBALS['fliinow_test_mocks']['options']['woocommerce_fliinow_settings'] = array(
			'enabled'  => 'yes',
			'api_key'  => 'fk_test_abc123',
			'sandbox'  => 'yes',
			'debug'    => 'yes',
		);
		$GLOBALS['fliinow_test_mocks']['notices'] = array();
	}

	// ── Status constants ──────────────────────────────────────────────────

	public function test_approved_statuses(): void {
		$this->assertSame(
			array( 'FAVORABLE', 'CONFIRMED', 'FINISHED' ),
			Fliinow_Webhook::APPROVED_STATUSES
		);
	}

	public function test_pending_statuses(): void {
		$this->assertSame(
			array( 'GENERATED', 'PENDING', 'PENDING_RESPONSE', 'CLIENT_REQUESTED' ),
			Fliinow_Webhook::PENDING_STATUSES
		);
	}

	public function test_rejected_statuses(): void {
		$this->assertSame(
			array( 'REFUSED', 'EXPIRED', 'ERROR' ),
			Fliinow_Webhook::REJECTED_STATUSES
		);
	}

	// ── All OperationStatus enum values are covered ───────────────────────

	public function test_all_fliinow_statuses_are_handled(): void {
		// From Fliinow Partner API types.ts — OperationStatus enum:
		$all_statuses = array(
			'GENERATED', 'PENDING', 'PENDING_RESPONSE', 'CLIENT_REQUESTED',
			'FAVORABLE', 'CONFIRMED', 'FINISHED',
			'REFUSED', 'EXPIRED', 'ERROR',
		);

		$handled = array_merge(
			Fliinow_Webhook::APPROVED_STATUSES,
			Fliinow_Webhook::PENDING_STATUSES,
			Fliinow_Webhook::REJECTED_STATUSES
		);

		foreach ( $all_statuses as $status ) {
			$this->assertContains( $status, $handled, "Status '$status' is not handled in webhook constants" );
		}
	}

	// ── Status groupings match API spec ───────────────────────────────────

	/**
	 * @dataProvider approvedStatusProvider
	 */
	public function test_approved_status_in_group( string $status ): void {
		$this->assertContains( $status, Fliinow_Webhook::APPROVED_STATUSES );
	}

	public function approvedStatusProvider(): array {
		return array(
			'FAVORABLE' => array( 'FAVORABLE' ),
			'CONFIRMED' => array( 'CONFIRMED' ),
			'FINISHED'  => array( 'FINISHED' ),
		);
	}

	/**
	 * @dataProvider pendingStatusProvider
	 */
	public function test_pending_status_in_group( string $status ): void {
		$this->assertContains( $status, Fliinow_Webhook::PENDING_STATUSES );
	}

	public function pendingStatusProvider(): array {
		return array(
			'GENERATED'        => array( 'GENERATED' ),
			'PENDING'          => array( 'PENDING' ),
			'PENDING_RESPONSE' => array( 'PENDING_RESPONSE' ),
			'CLIENT_REQUESTED' => array( 'CLIENT_REQUESTED' ),
		);
	}

	/**
	 * @dataProvider rejectedStatusProvider
	 */
	public function test_rejected_status_in_group( string $status ): void {
		$this->assertContains( $status, Fliinow_Webhook::REJECTED_STATUSES );
	}

	public function rejectedStatusProvider(): array {
		return array(
			'REFUSED' => array( 'REFUSED' ),
			'EXPIRED' => array( 'EXPIRED' ),
			'ERROR'   => array( 'ERROR' ),
		);
	}

	// ── Validate callback logic ───────────────────────────────────────────

	public function test_validate_callback_rejects_missing_order_id(): void {
		$this->expectException( Fliinow_Test_Redirect_Exception::class );
		$this->invoke_validate_callback( 0, 'key' );
	}

	public function test_validate_callback_rejects_missing_order_key(): void {
		$this->expectException( Fliinow_Test_Redirect_Exception::class );
		$this->invoke_validate_callback( 1, '' );
	}

	public function test_validate_callback_rejects_wrong_order_key(): void {
		$order = new WC_Order( 1 );
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;

		$this->expectException( Fliinow_Test_Redirect_Exception::class );
		$this->invoke_validate_callback( 1, 'wrong_key' );
	}

	public function test_validate_callback_rejects_non_fliinow_order(): void {
		$order = new WC_Order( 1 );
		$order->set_data( 'payment_method', 'stripe' );
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;

		$this->expectException( Fliinow_Test_Redirect_Exception::class );
		$this->invoke_validate_callback( 1, 'wc_order_abc123' );
	}

	public function test_validate_callback_accepts_valid_order(): void {
		$order = new WC_Order( 1 );
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;

		$result = $this->invoke_validate_callback( 1, 'wc_order_abc123' );
		$this->assertInstanceOf( WC_Order::class, $result );
	}

	// ── Security: handle_error verifies API before cancelling (F1) ────────

	public function test_error_callback_with_approved_status_completes_order(): void {
		$order = new WC_Order( 1 );
		$order->set_meta( '_fliinow_operation_id', 'op_test_f1' );
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;

		// Simulate Fliinow API returning FAVORABLE.
		fliinow_test_mock_response( 200, array( 'status' => 'FAVORABLE' ) );

		$_GET = array(
			'order_id'  => '1',
			'order_key' => 'wc_order_abc123',
			'status'    => 'error',
		);

		try {
			Fliinow_Webhook::handle_callback();
		} catch ( Fliinow_Test_Redirect_Exception $e ) {
			// Expected redirect.
		}

		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'FAVORABLE', $order->get_meta( '_fliinow_status' ) );
	}

	public function test_error_callback_with_pending_status_holds_order(): void {
		$order = new WC_Order( 1 );
		$order->set_meta( '_fliinow_operation_id', 'op_test_f1_pending' );
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;

		fliinow_test_mock_response( 200, array( 'status' => 'PENDING' ) );

		$_GET = array(
			'order_id'  => '1',
			'order_key' => 'wc_order_abc123',
			'status'    => 'error',
		);

		try {
			Fliinow_Webhook::handle_callback();
		} catch ( Fliinow_Test_Redirect_Exception $e ) {
			// Expected redirect.
		}

		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'PENDING', $order->get_meta( '_fliinow_status' ) );
	}

	public function test_error_callback_with_api_unreachable_holds_order(): void {
		$order = new WC_Order( 1 );
		$order->set_meta( '_fliinow_operation_id', 'op_test_f1_unreachable' );
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;

		// Simulate API transport error on all attempts.
		fliinow_test_mock_transport_error();
		fliinow_test_mock_transport_error();
		fliinow_test_mock_transport_error();

		$_GET = array(
			'order_id'  => '1',
			'order_key' => 'wc_order_abc123',
			'status'    => 'error',
		);

		try {
			Fliinow_Webhook::handle_callback();
		} catch ( Fliinow_Test_Redirect_Exception $e ) {
			// Expected redirect.
		}

		$this->assertSame( 'on-hold', $order->get_status() );
	}

	public function test_error_callback_with_refused_status_cancels_order(): void {
		$order = new WC_Order( 1 );
		$order->set_meta( '_fliinow_operation_id', 'op_test_f1_refused' );
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;

		fliinow_test_mock_response( 200, array( 'status' => 'REFUSED' ) );

		$_GET = array(
			'order_id'  => '1',
			'order_key' => 'wc_order_abc123',
			'status'    => 'error',
		);

		try {
			Fliinow_Webhook::handle_callback();
		} catch ( Fliinow_Test_Redirect_Exception $e ) {
			// Expected redirect.
		}

		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertSame( 'REFUSED', $order->get_meta( '_fliinow_status' ) );
	}

	public function test_error_callback_without_operation_id_holds_order(): void {
		$order = new WC_Order( 1 );
		// No _fliinow_operation_id set.
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;

		$_GET = array(
			'order_id'  => '1',
			'order_key' => 'wc_order_abc123',
			'status'    => 'error',
		);

		try {
			Fliinow_Webhook::handle_callback();
		} catch ( Fliinow_Test_Redirect_Exception $e ) {
			// Expected redirect.
		}

		$this->assertSame( 'on-hold', $order->get_status() );
	}

	public function test_success_callback_without_operation_id_holds_order(): void {
		$order = new WC_Order( 1 );
		// No _fliinow_operation_id set.
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;

		$_GET = array(
			'order_id'  => '1',
			'order_key' => 'wc_order_abc123',
			'status'    => 'success',
		);

		try {
			Fliinow_Webhook::handle_callback();
		} catch ( Fliinow_Test_Redirect_Exception $e ) {
			// Expected redirect.
		}

		$this->assertSame( 'on-hold', $order->get_status() );
	}

	public function test_success_callback_with_api_unreachable_holds_order(): void {
		$order = new WC_Order( 1 );
		$order->set_meta( '_fliinow_operation_id', 'op_test_unreachable' );
		$GLOBALS['fliinow_test_mocks']['current_order'] = $order;

		// Gateway has 1 retry → 2 attempts total.
		fliinow_test_mock_transport_error();
		fliinow_test_mock_transport_error();

		$_GET = array(
			'order_id'  => '1',
			'order_key' => 'wc_order_abc123',
			'status'    => 'success',
		);

		try {
			Fliinow_Webhook::handle_callback();
		} catch ( Fliinow_Test_Redirect_Exception $e ) {
			// Expected redirect.
		}

		$this->assertSame( 'on-hold', $order->get_status() );
	}

	// ── Security: validate_callback uses only order_key (F4) ──────────────

	public function test_validate_callback_takes_only_two_params(): void {
		$ref = new ReflectionMethod( Fliinow_Webhook::class, 'validate_callback' );
		$this->assertSame( 2, $ref->getNumberOfParameters() );
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Call the private validate_callback method via reflection.
	 */
	private function invoke_validate_callback( int $order_id, string $order_key ) {
		$ref = new ReflectionMethod( Fliinow_Webhook::class, 'validate_callback' );
		$ref->setAccessible( true );
		return $ref->invoke( null, $order_id, $order_key );
	}
}
