<?php
/**
 * Plugin Name: Fliinow - Financiación para WooCommerce
 * Plugin URI: https://api.docs.fliinow.com/
 * Description: Ofrece financiación a plazos en el checkout de WooCommerce con Fliinow. Compatible con WooCommerce Blocks.
 * Version: 1.0.0
 * Author: Fliinow
 * Author URI: https://fliinow.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fliinow-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.7
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.6
 *
 * @package Fliinow_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'FLIINOW_WC_VERSION', '1.0.0' );
define( 'FLIINOW_WC_PLUGIN_FILE', __FILE__ );
define( 'FLIINOW_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLIINOW_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ─── HPOS + Blocks compatibility (must run before WC init) ─────────────────
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

// ─── Load text domain ──────────────────────────────────────────────────────
add_action( 'init', function () {
	load_plugin_textdomain( 'fliinow-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

// ─── Core init ─────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'fliinow_wc_init' );

function fliinow_wc_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'fliinow_wc_missing_wc_notice' );
		return;
	}

	require_once FLIINOW_WC_PLUGIN_DIR . 'includes/class-fliinow-api.php';
	require_once FLIINOW_WC_PLUGIN_DIR . 'includes/class-fliinow-gateway.php';
	require_once FLIINOW_WC_PLUGIN_DIR . 'includes/class-fliinow-webhook.php';

	add_filter( 'woocommerce_payment_gateways', 'fliinow_wc_add_gateway' );
	Fliinow_Webhook::init();

	add_action( 'wp_ajax_fliinow_health_check', 'fliinow_wc_ajax_health_check' );
}

function fliinow_wc_missing_wc_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Fliinow para WooCommerce requiere que WooCommerce esté instalado y activo.', 'fliinow-woocommerce' )
	);
}

function fliinow_wc_add_gateway( array $gateways ): array {
	$gateways[] = 'Fliinow_Gateway';
	return $gateways;
}

// ─── Blocks integration ───────────────────────────────────────────────────
add_action( 'woocommerce_blocks_loaded', 'fliinow_wc_blocks_support' );

function fliinow_wc_blocks_support() {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	require_once FLIINOW_WC_PLUGIN_DIR . 'includes/class-fliinow-blocks.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ) {
			$registry->register( new Fliinow_Blocks_Payment_Method() );
		}
	);
}

// ─── Plugin action links ──────────────────────────────────────────────────
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( array $links ): array {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=fliinow' );
	array_unshift(
		$links,
		'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Ajustes', 'fliinow-woocommerce' ) . '</a>'
	);
	return $links;
} );

// ─── Admin AJAX: health-check ─────────────────────────────────────────────
function fliinow_wc_ajax_health_check() {
	check_ajax_referer( 'fliinow_health_check', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
	}

	$settings = get_option( 'woocommerce_fliinow_settings', array() );
	$api_key  = $settings['api_key'] ?? '';
	$sandbox  = ( $settings['sandbox'] ?? 'yes' ) === 'yes';

	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'API Key no configurada.', 'fliinow-woocommerce' ) ) );
	}

	$api    = new Fliinow_API( $api_key, $sandbox );
	$result = $api->health();

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	$env = $sandbox ? 'sandbox' : 'production';
	wp_send_json_success( array(
		'message' => sprintf( __( 'Conexión OK (%s)', 'fliinow-woocommerce' ), $env ),
	) );
}

// ─── Cron: status polling for pending/on-hold orders ──────────────────────
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'fliinow_check_pending_orders' ) ) {
		wp_schedule_event( time(), 'hourly', 'fliinow_check_pending_orders' );
	}
} );

add_action( 'fliinow_check_pending_orders', 'fliinow_wc_check_pending_orders' );

function fliinow_wc_check_pending_orders() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	$orders = wc_get_orders( array(
		'status'         => array( 'pending', 'on-hold' ),
		'payment_method' => 'fliinow',
		'limit'          => 50,
		'date_created'   => '>' . gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
		'meta_key'       => '_fliinow_operation_id',
		'meta_compare'   => 'EXISTS',
		'orderby'        => 'date',
		'order'          => 'ASC',
	) );

	if ( empty( $orders ) ) {
		return;
	}

	$gateways = WC()->payment_gateways()->payment_gateways();
	$gateway  = $gateways['fliinow'] ?? null;
	if ( ! $gateway || ! $gateway->get_api() ) {
		return;
	}

	$api = $gateway->get_api();

	foreach ( $orders as $order ) {
		$operation_id = $order->get_meta( '_fliinow_operation_id' );
		if ( empty( $operation_id ) ) {
			continue;
		}

		$result = $api->get_operation_status( $operation_id );
		if ( is_wp_error( $result ) ) {
			continue;
		}

		$new_status = $result['status'] ?? '';
		$old_status = $order->get_meta( '_fliinow_status' );
		if ( $new_status === $old_status ) {
			continue;
		}

		$order->update_meta_data( '_fliinow_status', sanitize_text_field( $new_status ) );

		if ( in_array( $new_status, array( 'FAVORABLE', 'CONFIRMED', 'FINISHED' ), true ) ) {
			$order->payment_complete( $operation_id );
			$order->add_order_note(
				sprintf( __( '[Cron] Financiación Fliinow aprobada — %s', 'fliinow-woocommerce' ), $new_status )
			);
		} elseif ( in_array( $new_status, array( 'REFUSED', 'EXPIRED', 'ERROR' ), true ) ) {
			$order->set_status( 'cancelled',
				sprintf( __( '[Cron] Financiación rechazada/expirada — %s', 'fliinow-woocommerce' ), $new_status )
			);
		}

		$order->save();
	}
}

// ─── Deactivation ─────────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'fliinow_check_pending_orders' );
} );
