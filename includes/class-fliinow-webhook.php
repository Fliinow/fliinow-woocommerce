<?php
/**
 * Fliinow Callback Handler.
 *
 * Handles the return redirect from Fliinow after financing completes or fails.
 * Validates callbacks via order_key (constant-time comparison).
 * Always verifies real operation status against Fliinow API before acting.
 *
 * @package Fliinow_Checkout
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- order_key is the auth factor
		$order_id  = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		$status    = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:enable

		$order = self::validate_callback( $order_id, $order_key );
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
	private static function validate_callback( int $order_id, string $order_key ): ?WC_Order {
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
							__( 'Financiación Fliinow aprobada. Estado: %1$s. Operación: %2$s', 'fliinow-checkout' ),
							$fliinow_status,
							$operation_id
						)
					);
				} elseif ( in_array( $fliinow_status, self::PENDING_STATUSES, true ) ) {
					$order->set_status( 'on-hold',
						sprintf( __( 'Financiación en proceso — %s', 'fliinow-checkout' ), $fliinow_status )
					);
				} elseif ( in_array( $fliinow_status, self::REJECTED_STATUSES, true ) ) {
					// User was redirected to success URL but Fliinow reports rejection.
					$order->set_status( 'cancelled',
						sprintf( __( 'Financiación rechazada — %s', 'fliinow-checkout' ), $fliinow_status )
					);
				}

				$order->save();
			} else {
				// API check failed — put on hold so cron can verify later.
				$gateway->log( 'Cannot verify Fliinow status for order #' . $order->get_id() . ': ' . $result->get_error_message(), 'warning' );
				if ( $order->has_status( 'pending' ) ) {
					$order->set_status( 'on-hold', __( 'Esperando verificación de Fliinow (error de red temporal).', 'fliinow-checkout' ) );
					$order->save();
				}
			}
		} else {
			// Cannot verify — hold for cron.
			$gateway->log( 'Cannot verify Fliinow status for order #' . $order->get_id() . ': no API or operation ID', 'warning' );
			if ( $order->has_status( 'pending' ) ) {
				$order->set_status( 'on-hold', __( 'Esperando verificación de Fliinow (sin datos de operación).', 'fliinow-checkout' ) );
				$order->save();
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

		// Always verify actual status in Fliinow before cancelling.
		// The error callback URL only means the user was redirected back — NOT
		// that the operation is necessarily rejected.
		$operation_id = $order->get_meta( '_fliinow_operation_id' );
		$api          = $gateway->get_api();

		if ( $api && $operation_id ) {
			$result = $api->get_operation_status( $operation_id );

			if ( ! is_wp_error( $result ) ) {
				$fliinow_status = $result['status'] ?? '';
				$order->update_meta_data( '_fliinow_status', sanitize_text_field( $fliinow_status ) );

				if ( in_array( $fliinow_status, self::APPROVED_STATUSES, true ) ) {
					$gateway->log( 'Error callback but Fliinow reports APPROVED — completing order #' . $order->get_id() );
					$order->payment_complete( $operation_id );
					$order->add_order_note(
						sprintf(
							__( 'Financiación Fliinow aprobada (verificada en callback error). Estado: %1$s. Operación: %2$s', 'fliinow-checkout' ),
							$fliinow_status,
							$operation_id
						)
					);
					$order->save();
					wp_safe_redirect( $order->get_checkout_order_received_url() );
					exit;
				}

				if ( in_array( $fliinow_status, self::PENDING_STATUSES, true ) ) {
					$gateway->log( 'Error callback but Fliinow reports PENDING — holding order #' . $order->get_id() );
					$order->set_status( 'on-hold',
						sprintf( __( 'Financiación en proceso — %s', 'fliinow-checkout' ), $fliinow_status )
					);
					$order->save();
					wp_safe_redirect( $order->get_checkout_order_received_url() );
					exit;
				}
				// REJECTED — confirmed by API, cancel the order.
				$gateway->log( 'Error callback — Fliinow confirms REJECTED (' . $fliinow_status . ') for order #' . $order->get_id() );
				$order->update_status(
					'cancelled',
					sprintf( __( 'Financiación rechazada — %s', 'fliinow-checkout' ), $fliinow_status )
				);

				foreach ( $order->get_items() as $item ) {
					WC()->cart->add_to_cart( $item->get_product_id(), $item->get_quantity(), $item->get_variation_id(), $item->get_meta( '_variation_attributes', true ) ?: array() );
				}

				wc_add_notice(
					__( 'La financiación no se ha completado. Puedes intentarlo de nuevo o elegir otro método de pago.', 'fliinow-checkout' ),
					'error'
				);

				wp_safe_redirect( wc_get_checkout_url() );
				exit;
			} else {
				// API unreachable — put on hold so cron can verify later.
				$gateway->log( 'Error callback but cannot verify Fliinow status for order #' . $order->get_id() . ': ' . $result->get_error_message(), 'warning' );
				$order->set_status( 'on-hold', __( 'Esperando verificación de Fliinow (error de red temporal).', 'fliinow-checkout' ) );
				$order->save();
				wp_safe_redirect( $order->get_checkout_order_received_url() );
				exit;
			}
		} else {
			// Cannot verify — hold for cron instead of cancelling blind.
			$gateway->log( 'Error callback but cannot verify Fliinow status for order #' . $order->get_id() . ': no API or operation ID', 'warning' );
			$order->set_status( 'on-hold', __( 'Esperando verificación de Fliinow (sin datos de operación).', 'fliinow-checkout' ) );
			$order->save();
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}
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
