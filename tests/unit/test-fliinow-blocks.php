<?php
/**
 * Unit tests for Fliinow_Blocks_Payment_Method.
 *
 * @package Fliinow_Checkout\Tests\Unit
 */

class Test_Fliinow_Blocks extends PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		fliinow_test_reset_mocks();
	}

	// ── is_active ──────────────────────────────────────────────────────────

	public function test_is_active_when_enabled_with_key(): void {
		$GLOBALS['fliinow_test_mocks']['options']['woocommerce_fliinow_settings'] = array(
			'enabled' => 'yes',
			'api_key' => 'fk_test_key',
		);
		$blocks = new Fliinow_Blocks_Payment_Method();
		$blocks->initialize();

		$this->assertTrue( $blocks->is_active() );
	}

	public function test_not_active_when_disabled(): void {
		$GLOBALS['fliinow_test_mocks']['options']['woocommerce_fliinow_settings'] = array(
			'enabled' => 'no',
			'api_key' => 'fk_test_key',
		);
		$blocks = new Fliinow_Blocks_Payment_Method();
		$blocks->initialize();

		$this->assertFalse( $blocks->is_active() );
	}

	public function test_not_active_without_api_key(): void {
		$GLOBALS['fliinow_test_mocks']['options']['woocommerce_fliinow_settings'] = array(
			'enabled' => 'yes',
			'api_key' => '',
		);
		$blocks = new Fliinow_Blocks_Payment_Method();
		$blocks->initialize();

		$this->assertFalse( $blocks->is_active() );
	}

	public function test_not_active_when_missing_enabled(): void {
		$GLOBALS['fliinow_test_mocks']['options']['woocommerce_fliinow_settings'] = array(
			'api_key' => 'fk_test_key',
		);
		$blocks = new Fliinow_Blocks_Payment_Method();
		$blocks->initialize();

		$this->assertFalse( $blocks->is_active() );
	}

	// ── get_payment_method_data ────────────────────────────────────────────

	public function test_payment_method_data_structure(): void {
		$GLOBALS['fliinow_test_mocks']['options']['woocommerce_fliinow_settings'] = array(
			'enabled'     => 'yes',
			'api_key'     => 'fk_test_key',
			'title'       => 'Test Title',
			'description' => 'Test Description',
			'min_amount'  => '100',
			'max_amount'  => '5000',
		);
		$blocks = new Fliinow_Blocks_Payment_Method();
		$blocks->initialize();

		$data = $blocks->get_payment_method_data();

		$this->assertArrayHasKey( 'title', $data );
		$this->assertArrayHasKey( 'description', $data );
		$this->assertArrayHasKey( 'icon', $data );
		$this->assertArrayHasKey( 'supports', $data );
		$this->assertArrayHasKey( 'min_amount', $data );
		$this->assertArrayHasKey( 'max_amount', $data );

		$this->assertSame( 'Test Title', $data['title'] );
		$this->assertSame( 'Test Description', $data['description'] );
		$this->assertSame( 100.0, $data['min_amount'] );
		$this->assertSame( 5000.0, $data['max_amount'] );
	}

	public function test_payment_method_data_defaults(): void {
		$GLOBALS['fliinow_test_mocks']['options']['woocommerce_fliinow_settings'] = array(
			'enabled' => 'yes',
			'api_key' => 'fk_test_key',
		);
		$blocks = new Fliinow_Blocks_Payment_Method();
		$blocks->initialize();

		$data = $blocks->get_payment_method_data();

		$this->assertSame( 'Financiar con Fliinow', $data['title'] );
		$this->assertSame( 60.0, $data['min_amount'] );
		$this->assertSame( 0.0, $data['max_amount'] );
	}

	public function test_payment_method_data_icon_url(): void {
		$GLOBALS['fliinow_test_mocks']['options']['woocommerce_fliinow_settings'] = array(
			'enabled' => 'yes',
			'api_key' => 'fk_test_key',
		);
		$blocks = new Fliinow_Blocks_Payment_Method();
		$blocks->initialize();

		$data = $blocks->get_payment_method_data();

		$this->assertStringContainsString( 'fliinow-logo.svg', $data['icon'] );
	}

	// ── Script handles ────────────────────────────────────────────────────

	public function test_script_handles(): void {
		$blocks = new Fliinow_Blocks_Payment_Method();
		$blocks->initialize();

		$handles = $blocks->get_payment_method_script_handles();

		$this->assertContains( 'fliinow-blocks-integration', $handles );
	}

	public function test_editor_script_handles(): void {
		$blocks = new Fliinow_Blocks_Payment_Method();
		$blocks->initialize();

		$handles = $blocks->get_payment_method_script_handles_for_editor();

		$this->assertContains( 'fliinow-blocks-integration', $handles );
	}

	// ── Name ──────────────────────────────────────────────────────────────

	public function test_name(): void {
		$blocks = new Fliinow_Blocks_Payment_Method();
		$this->assertSame( 'fliinow', $blocks->get_name() );
	}
}
