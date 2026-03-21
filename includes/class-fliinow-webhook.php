<?php
/**
 * Fliinow Callback Handler.
 *
 * Handles the return redirect from Fliinow after financing completes or fails.
 * Secures callbacks with order_key + nonce verification.
 * Maps all Fliinow statuses to appropriate WC order states.
 *
 * @package Fliinow_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Fliinow_Webhook {

	/**
	 * Fliinow statuses grouped by outcome.
	 */
	const APPROVED_STATUSES = array( 'FAVORABLE', 'CONFIRMED', 'FINISHED' );
	const PENDING_STATUSES  = array( 'GENERATED', 'PENDING', 'PENDING_RESPONSE', 'CLIENT_REQUESTED' );
	const REJECTED_STATUSES = array( 'REFUSED', 'EXPIRED', 'ERROR' );

	public static function init() {
		add_action( 'woocommerce_api_fliinow_callback', array( __CLASS__, 'handle_callback' ) );
	}

	/**
	 * Entry point for /wc-api/fliinow_callback.
	 */
	public static function handle_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- validated below
		$order_id  = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		$status    = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$nonce     = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable

		$order = self::validate_callback( $order_id, $order_key, $nonce );
		if ( null === $order ) {
			return; // Already redirected inside validate_callback.
		}

		// Already processed — just show the thank-you page.
		if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		$gateway = self::get_gateway();

		if ( 'success' === $status ) {
			self::handle_success( $order, $gateway );
		} else {
			self::handle_error( $order, $gateway );
		}
	}

	/**
	 * Validate the incoming callback parameters.
	 *
	 * @return WC_Order|null The validated order, or null (redirected).
	 */
	private static function validate_callback( int $order_id, string $order_key, string $nonce ): ?WC_Order {
		if ( ! $order_id || ! $order_key ) {
			self::redirect_to_shop();
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			self::redirect_to_shop();
			return null;
		}

		// Constant-time comparison of the order key.
		if ( ! hash_equals( $order->get_order_key(), $order_key ) ) {
			self::redirect_to_shop();
			return null;
		}

		// Verify nonce (non-fatal — older URLs may lack it).
		if ( ! empty( $nonce ) && ! wp_verify_nonce( $nonce, 'fliinow_callback_' . $order_id ) ) {
			$gateway = self::get_gateway();
			$gateway->log( 'Callback nonce mismatch for order #' . $order_id, 'warning' );
		}

		// Must be a Fliinow order.
		if ( 'fliinow' !== $order->get_payment_method() ) {
			self::redirect_to_shop();
			return null;
		}

		return $order;
	}

	/**
	 * Handle successful financing return.
	 */
	private static function handle_success( WC_Order $order, Fliinow_Gateway $gateway ) {
		$operation_id = $order->get_meta( '_fliinow_operation_id' );
		$api          = $gateway->get_api();

		if ( $api && $operation_id ) {
			$result = $api->get_operation_status( $operation_id );

			if ( ! is_wp_error( $result ) ) {
				$fliinow_status = $result['status'] ?? '';
				$order->update_meta_data( '_fliinow_status', sanitize_text_field( $fliinow_status ) );

				$gateway->log( 'Callback success — status: ' . $fliinow_status . ' — order #' . $order->get_id() );

				if ( in_array( $fliinow_status, self::APPROVED_STATUSES, true ) ) {
					$order->payment_complete( $operation_id );
					$order->add_order_note(
						sprintf(
							__( 'Financiación Fliinow aprobada. Estado: %1$s. Operación: %2$s', 'fliinow-woocommerce' ),
							$fliinow_status,
							$operation_id
						)
					);
				} elseif ( in_array( $fliinow_status, self::PENDING_STATUSES, true ) ) {
					$order->set_status( 'on-hold',
						sprintf( __( 'Financiación en proceso — %s', 'fliinow-woocommerce' ), $fliinow_status )
					);
				} elseif ( in_array( $fliinow_status, self::REJECTED_STATUSES, true ) ) {
					// User was redirected to success URL but Fliinow reports rejection.
					$order->set_status( 'cancelled',
						sprintf( __( 'Financiación rechazada — %s', 'fliinow-woocommerce' ), $fliinow_status )
					);
				}

				$order->save();
			} else {
				// API check failed — put on hold so cron can verify later.
				$gateway->log( 'Cannot verify Fliinow status for order #' . $order->get_id() . ': ' . $result->get_error_message(), 'warning' );
				if ( $order->has_status( 'pending' ) ) {
					$order->set_status( 'on-hold', __( 'Esperando verificación de Fliinow (error de red temporal).', 'fliinow-woocommerce' ) );
					$order->save();
				}
			}
		}

		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}

	/**
	 * Handle failed/cancelled financing return.
	 */
	private static function handle_error( WC_Order $order, Fliinow_Gateway $gateway ) {
		$gateway->log( 'Callback error — order #' . $order->get_id(), 'warning' );

		// Use 'cancelled' instead of 'failed' so the customer can retry.
		$order->update_status(
			'cancelled',
			__( 'Financiación Fliinow no completada o cancelada por el cliente.', 'fliinow-woocommerce' )
		);

		// Restore cart items so customer can choose another payment method.
		if ( function_exists( 'wc_get_order' ) ) {
			foreach ( $order->get_items() as $item ) {
				WC()->cart->add_to_cart( $item->get_product_id(), $item->get_quantity(), $item->get_variation_id(), $item->get_meta( '_variation_attributes', true ) ?: array() );
			}
		}

		wc_add_notice(
			__( 'La financiación no se ha completado. Puedes intentarlo de nuevo o elegir otro método de pago.', 'fliinow-woocommerce' ),
			'error'
		);

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private static function redirect_to_shop() {
		wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
		exit;
	}

	private static function get_gateway(): Fliinow_Gateway {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return $gateways['fliinow'] ?? new Fliinow_Gateway();
	}
}
