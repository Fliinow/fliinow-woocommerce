<?php
/**
 * Mock WooCommerce Blocks classes in their proper namespaces.
 *
 * PHP requires namespace declarations at the very top of a file or in
 * separate files. This file provides the Blocks mocks.
 *
 * @package Fliinow_Checkout\Tests
 */

namespace Automattic\WooCommerce\Blocks\Payments\Integrations;

if ( ! class_exists( __NAMESPACE__ . '\AbstractPaymentMethodType' ) ) {
	abstract class AbstractPaymentMethodType {
		protected $name = '';
		protected $settings = array();

		abstract public function initialize();
		abstract public function is_active();
		abstract public function get_payment_method_script_handles();
		abstract public function get_payment_method_data();

		public function get_name(): string {
			return $this->name;
		}

		protected function get_setting( string $key ) {
			return $this->settings[ $key ] ?? '';
		}

		public function get_payment_method_script_handles_for_editor() {
			return $this->get_payment_method_script_handles();
		}
	}
}

namespace Automattic\WooCommerce\Blocks\Payments;

if ( ! class_exists( __NAMESPACE__ . '\PaymentMethodRegistry' ) ) {
	class PaymentMethodRegistry {
		private $methods = array();
		public function register( $method ): void {
			$this->methods[ $method->get_name() ] = $method;
		}
	}
}
