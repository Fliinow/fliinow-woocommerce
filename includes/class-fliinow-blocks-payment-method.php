<?php
/**
 * Fliinow payment method for WooCommerce Blocks Checkout.
 *
 * Registers Fliinow as a payment method in the block-based checkout using
 * the AbstractPaymentMethodType API.
 *
 * @package Fliinow_Checkout
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class Fliinow_Blocks_Payment_Method extends AbstractPaymentMethodType {

	/** @var string */
	protected $name = 'fliinow';

	/** @var bool */
	private $scripts_registered = false;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_fliinow_settings', array() );
	}

	public function is_active() {
		if ( empty( $this->settings['enabled'] ) || 'yes' !== $this->settings['enabled'] ) {
			return false;
		}
		if ( empty( $this->settings['api_key'] ) ) {
			return false;
		}
		return true;
	}

	public function get_payment_method_script_handles() {
		if ( ! $this->scripts_registered ) {
			$this->register_scripts();
		}
		return array( 'fliinow-blocks-integration' );
	}

	public function get_payment_method_script_handles_for_editor() {
		if ( ! $this->scripts_registered ) {
			$this->register_scripts();
		}
		return array( 'fliinow-blocks-integration' );
	}

	public function get_payment_method_data() {
		$title = $this->get_setting( 'title' );
		$desc  = $this->get_setting( 'description' );
		return array(
			'title'       => $title ? $title : __( 'Financiar con Fliinow', 'fliinow-checkout' ),
			'description' => $desc ? $desc : __( 'Financia tu compra a plazos.', 'fliinow-checkout' ),
			'icon'        => FLIINOW_WC_PLUGIN_URL . 'assets/fliinow-logo.svg',
			'supports'    => $this->get_supported_features(),
			'min_amount'  => (float) ( $this->get_setting( 'min_amount' ) ? $this->get_setting( 'min_amount' ) : 60 ),
			'max_amount'  => (float) ( $this->get_setting( 'max_amount' ) ? $this->get_setting( 'max_amount' ) : 0 ),
		);
	}

	private function register_scripts() {
		$asset_path = FLIINOW_WC_PLUGIN_DIR . 'build/index.asset.php';
		$script_url = FLIINOW_WC_PLUGIN_URL . 'build/index.js';

		if ( ! file_exists( FLIINOW_WC_PLUGIN_DIR . 'build/index.js' ) ) {
			return;
		}

		$asset = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array(),
				'version'      => FLIINOW_WC_VERSION,
			);

		wp_register_script(
			'fliinow-blocks-integration',
			$script_url,
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				'fliinow-blocks-integration',
				'fliinow-checkout',
				FLIINOW_WC_PLUGIN_DIR . 'languages'
			);
		}

		$this->scripts_registered = true;
	}

	private function get_supported_features(): array {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( isset( $gateways['fliinow'] ) ) {
			return $gateways['fliinow']->supports;
		}
		return array( 'products' );
	}
}
