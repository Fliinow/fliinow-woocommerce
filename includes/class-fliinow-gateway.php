<?php
/**
 * Fliinow Payment Gateway for WooCommerce.
 *
 * Extends WC_Payment_Gateway to add Fliinow financing as a checkout option.
 * Handles operation creation, redirects, refunds, and admin configuration.
 *
 * @package Fliinow_Checkout_Financing
 */

defined( 'ABSPATH' ) || exit;

class Fliinow_Gateway extends WC_Payment_Gateway {

	/** @var Fliinow_API|null */
	private $api;

	public function __construct() {
		$this->id                 = 'fliinow';
		$this->icon               = FLIINOW_WC_PLUGIN_URL . 'assets/fliinow-logo.svg';
		$this->has_fields         = false;
		$this->method_title       = __( 'Fliinow - Financiación', 'fliinow-checkout-financing' );
		$this->method_description = __( 'Permite a tus clientes financiar sus compras a plazos con Fliinow.', 'fliinow-checkout-financing' );
		$this->order_button_text  = __( 'Financiar con Fliinow', 'fliinow-checkout-financing' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		$api_key = $this->get_option( 'api_key' );
		$sandbox = 'yes' === $this->get_option( 'sandbox' );
		if ( ! empty( $api_key ) ) {
			// 1 retry: 16s worst case — avoids lost sales on transient timeouts.
			$this->api = new Fliinow_API( $api_key, $sandbox, 8, 1 );
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
	}

	// ── Admin Settings ────────────────────────────────────────────────────

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Activar/Desactivar', 'fliinow-checkout-financing' ),
				'type'    => 'checkbox',
				'label'   => __( 'Activar financiación con Fliinow', 'fliinow-checkout-financing' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Título', 'fliinow-checkout-financing' ),
				'type'        => 'text',
				'description' => __( 'Nombre que verá el cliente en el checkout.', 'fliinow-checkout-financing' ),
				'default'     => __( 'Financiar con Fliinow', 'fliinow-checkout-financing' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Descripción', 'fliinow-checkout-financing' ),
				'type'        => 'textarea',
				'description' => __( 'Descripción que verá el cliente en el checkout.', 'fliinow-checkout-financing' ),
				'default'     => __( 'Financia tu compra a plazos. Serás redirigido a Fliinow para completar la solicitud de financiación.', 'fliinow-checkout-financing' ),
				'desc_tip'    => true,
			),
			'api_key'        => array(
				'title'       => __( 'API Key', 'fliinow-checkout-financing' ),
				'type'        => 'password',
				'description' => __( 'Claves sandbox: fk_test_*   Producción: fk_live_*', 'fliinow-checkout-financing' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'sandbox'        => array(
				'title'       => __( 'Modo Sandbox', 'fliinow-checkout-financing' ),
				'type'        => 'checkbox',
				'label'       => __( 'Activar entorno de pruebas (demo.fliinow.com)', 'fliinow-checkout-financing' ),
				'default'     => 'yes',
				'description' => __( 'Desactiva para producción (app.fliinow.com).', 'fliinow-checkout-financing' ),
			),
			'health_check'   => array(
				'title'       => __( 'Verificar conexión', 'fliinow-checkout-financing' ),
				'type'        => 'title',
				'description' => '<button type="button" class="button" id="fliinow-health-check">'
					. esc_html__( 'Probar conexión API', 'fliinow-checkout-financing' )
					. '</button> <span id="fliinow-health-result"></span>',
			),
			'min_amount'     => array(
				'title'             => __( 'Importe mínimo (€)', 'fliinow-checkout-financing' ),
				'type'              => 'number',
				'description'       => __( 'Importe mínimo del carrito para mostrar la opción.', 'fliinow-checkout-financing' ),
				'default'           => '60',
				'desc_tip'          => true,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
			),
			'max_amount'     => array(
				'title'             => __( 'Importe máximo (€)', 'fliinow-checkout-financing' ),
				'type'              => 'number',
				'description'       => __( '0 = sin límite.', 'fliinow-checkout-financing' ),
				'default'           => '0',
				'desc_tip'          => true,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
			),
			'package_travel' => array(
				'title'   => __( 'Viaje combinado', 'fliinow-checkout-financing' ),
				'type'    => 'checkbox',
				'label'   => __( 'Marcar como viaje combinado (EU Package Travel Directive)', 'fliinow-checkout-financing' ),
				'default' => 'yes',
			),
			'debug'          => array(
				'title'       => __( 'Log de depuración', 'fliinow-checkout-financing' ),
				'type'        => 'checkbox',
				'label'       => __( 'Activar logs', 'fliinow-checkout-financing' ),
				'default'     => 'no',
				'description' => __( 'WooCommerce → Estado → Logs (fuente: fliinow).', 'fliinow-checkout-financing' ),
			),
		);
	}

	/**
	 * Inline admin JS for health-check button (no external file needed).
	 */
	public function admin_scripts() {
		$screen = get_current_screen();
		if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['section'] ) || 'fliinow' !== sanitize_text_field( wp_unslash( $_GET['section'] ) ) ) {
			return;
		}

		$nonce = wp_create_nonce( 'fliinow_health_check' );
		$ajax  = admin_url( 'admin-ajax.php' );

		wp_add_inline_script(
			'jquery',
			"
			jQuery(function($){
				$('#fliinow-health-check').on('click',function(){
					var btn = $(this), res = $('#fliinow-health-result');
					btn.prop('disabled',true);
					res.text('Comprobando…').css('color','#666');
					$.post('" . esc_js( $ajax ) . "',{action:'fliinow_health_check',nonce:'" . esc_js( $nonce ) . "'},function(r){
						btn.prop('disabled',false);
						if(r.success){res.text('✓ '+r.data.message).css('color','green');}
						else{res.text('✗ '+r.data.message).css('color','red');}
					}).fail(function(){btn.prop('disabled',false);res.text('✗ Error de conexión').css('color','red');});
				});
			});
		"
		);
	}

	// ── Availability ──────────────────────────────────────────────────────

	public function is_available() {
		if ( ! parent::is_available() || empty( $this->get_option( 'api_key' ) ) ) {
			return false;
		}

		$total = $this->get_cart_total();
		if ( null === $total ) {
			return true; // Admin or non-cart context — allow display.
		}

		$min = (float) $this->get_option( 'min_amount', 60 );
		$max = (float) $this->get_option( 'max_amount', 0 );

		if ( $total < $min ) {
			return false;
		}
		if ( $max > 0 && $total > $max ) {
			return false;
		}

		return true;
	}

	/**
	 * Safely get the cart total, returning null if not in a cart context.
	 */
	private function get_cart_total(): ?float {
		if ( ! WC()->cart || is_admin() ) {
			return null;
		}
		return (float) WC()->cart->get_total( 'raw' );
	}

	// ── Payment Processing ────────────────────────────────────────────────

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Error al procesar el pedido.', 'fliinow-checkout-financing' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( ! $this->api ) {
			$this->log( 'API not initialized — missing API key', 'error' );
			wc_add_notice( __( 'Fliinow no está configurado correctamente.', 'fliinow-checkout-financing' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Prevent duplicate operations on double-submit: reuse existing if present and valid.
		$existing_op_id = $order->get_meta( '_fliinow_operation_id' );
		$existing_url   = $order->get_meta( '_fliinow_financing_url' );
		if ( ! empty( $existing_op_id ) && ! empty( $existing_url ) && $order->has_status( 'pending' ) ) {
			$this->log( 'Reusing existing operation ' . $existing_op_id . ' for order #' . $order_id );
			return array(
				'result'   => 'success',
				'redirect' => $existing_url,
			);
		}

		$this->log( 'Creating Fliinow operation for order #' . $order_id );

		$data   = $this->build_operation_data( $order );
		$result = $this->api->create_operation( $data );

		if ( is_wp_error( $result ) ) {
			$this->log( 'API error: ' . $result->get_error_message(), 'error' );
			wc_add_notice(
				__( 'No se pudo iniciar la financiación. Por favor, inténtalo de nuevo.', 'fliinow-checkout-financing' ),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		if ( empty( $result['financingUrl'] ) || empty( $result['id'] ) ) {
			$this->log( 'Invalid API response — missing financingUrl or id: ' . wp_json_encode( $result ), 'error' );
			wc_add_notice(
				__( 'Error inesperado al conectar con Fliinow.', 'fliinow-checkout-financing' ),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_fliinow_operation_id', sanitize_text_field( $result['id'] ) );
		$order->update_meta_data( '_fliinow_financing_url', esc_url_raw( $result['financingUrl'] ) );
		$order->update_meta_data( '_fliinow_status', sanitize_text_field( $result['status'] ?? 'GENERATED' ) );
		$order->set_status( 'pending', __( 'Esperando financiación de Fliinow.', 'fliinow-checkout-financing' ) );
		$order->save();

		$this->log( 'Operation created: ' . $result['id'] . ' → redirecting customer' );

		return array(
			'result'   => 'success',
			'redirect' => $result['financingUrl'],
		);
	}

	// ── Refunds ───────────────────────────────────────────────────────────

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'fliinow_refund_error', __( 'Pedido no encontrado.', 'fliinow-checkout-financing' ) );
		}

		if ( ! $this->api ) {
			return new WP_Error( 'fliinow_refund_error', __( 'Fliinow no está configurado.', 'fliinow-checkout-financing' ) );
		}

		// Fliinow only supports full cancellation — reject partial refunds.
		if ( null !== $amount && round( (float) $amount, 2 ) < round( (float) $order->get_total(), 2 ) ) {
			return new WP_Error( 'fliinow_refund_error', __( 'Fliinow no soporta reembolsos parciales. Solo se puede cancelar la operación completa.', 'fliinow-checkout-financing' ) );
		}

		$operation_id = $order->get_meta( '_fliinow_operation_id' );
		if ( empty( $operation_id ) ) {
			return new WP_Error( 'fliinow_refund_error', __( 'No se encontró la operación de Fliinow asociada.', 'fliinow-checkout-financing' ) );
		}

		$result = $this->api->cancel_operation( $operation_id, $reason );

		if ( is_wp_error( $result ) ) {
			$this->log( 'Refund/cancel error — order #' . $order_id . ': ' . $result->get_error_message(), 'error' );
			return $result;
		}

		$order->update_meta_data( '_fliinow_status', 'CANCELLED' );
		$order->save();

		$this->log( 'Operation ' . $operation_id . ' cancelled — order #' . $order_id );
		return true;
	}

	// ── Build operation payload ───────────────────────────────────────────

	private function build_operation_data( WC_Order $order ): array {
		$callback_base = WC()->api_request_url( 'fliinow_callback' );

		$common_args = array(
			'order_id'  => $order->get_id(),
			'order_key' => $order->get_order_key(),
		);

		$success_url = add_query_arg( array_merge( $common_args, array( 'status' => 'success' ) ), $callback_base );
		$error_url   = add_query_arg( array_merge( $common_args, array( 'status' => 'error' ) ), $callback_base );

		$phone_raw = $order->get_billing_phone();
		$phone     = preg_replace( '/[^0-9]/', '', $phone_raw );
		$prefix    = $this->get_phone_prefix( $order->get_billing_country() );

		// If customer typed the prefix in the phone field, strip it.
		$prefix_digits = ltrim( $prefix, '+' );
		if ( ! empty( $prefix_digits ) && 0 === strpos( $phone, $prefix_digits ) && strlen( $phone ) > strlen( $prefix_digits ) ) {
			$phone = substr( $phone, strlen( $prefix_digits ) );
		}

		// Build item descriptions for packageName.
		$items_desc = array();
		foreach ( $order->get_items() as $item ) {
			$items_desc[] = $item->get_name() . ' x' . $item->get_quantity();
		}
		$package_name = ! empty( $items_desc )
			? mb_substr( implode( ', ', $items_desc ), 0, 200 )
			/* translators: %s: order ID */
			: sprintf( __( 'Pedido #%s', 'fliinow-checkout-financing' ), $order->get_id() );

		$data = array(
			'externalId'         => (string) $order->get_id(),
			'client'             => array(
				'firstName'            => $order->get_billing_first_name(),
				'lastName'             => $order->get_billing_last_name(),
				'email'                => $order->get_billing_email(),
				'prefix'               => $prefix,
				'phone'                => $phone,
				'documentId'           => $order->get_meta( '_billing_document_id' ) ? $order->get_meta( '_billing_document_id' ) : '',
				'documentValidityDate' => $order->get_meta( '_billing_document_validity' ) ? $order->get_meta( '_billing_document_validity' ) : '',
				'gender'               => $this->normalize_gender( $order->get_meta( '_billing_gender' ) ),
				'birthDate'            => $order->get_meta( '_billing_birth_date' ) ? $order->get_meta( '_billing_birth_date' ) : '',
				'nationality'          => $this->get_nationality_code( $order->get_billing_country() ),
				'address'              => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
				'city'                 => $order->get_billing_city(),
				'postalCode'           => $order->get_billing_postcode(),
				'countryCode'          => $order->get_billing_country(),
			),
			'packageName'        => $package_name,
			'packageTravel'      => 'yes' === $this->get_option( 'package_travel', 'yes' ),
			'travelersNumber'    => 1,
			'flightDtoList'      => array(),
			'hotelDtoList'       => array(),
			'serviceDtoList'     => array(),
			'feeDtoList'         => array(),
			'totalPrice'         => round( (float) $order->get_total(), 2 ),
			'totalReserve'       => round( (float) $order->get_total(), 2 ),
			'successCallbackUrl' => $success_url,
			'errorCallbackUrl'   => $error_url,
		);

		/**
		 * Filter operation data before API call.
		 *
		 * Use this to add flights, hotels, services, fees, or change any field.
		 *
		 * @param array    $data  Operation payload.
		 * @param WC_Order $order The WooCommerce order.
		 */
		return apply_filters( 'fliinow_wc_operation_data', $data, $order );
	}

	// ── Utility methods ───────────────────────────────────────────────────

	private function normalize_gender( string $raw ): string {
		$val = strtoupper( trim( $raw ) );
		if ( in_array( $val, array( 'MALE', 'FEMALE' ), true ) ) {
			return $val;
		}
		// Accept common Spanish equivalents.
		$map = array(
			'HOMBRE' => 'MALE',
			'MUJER'  => 'FEMALE',
			'M'      => 'MALE',
			'F'      => 'FEMALE',
			'H'      => 'MALE',
		);
		return $map[ $val ] ?? 'MALE';
	}

	private function get_phone_prefix( string $country_code ): string {
		// Fliinow only supports Spanish phone numbers.
		return '+34';
	}

	private function get_nationality_code( string $country_code ): string {
		// Default to Spain; API validates on their side.
		return 'ESP';
	}

	/**
	 * Write to the WooCommerce log (source: fliinow).
	 *
	 * @param string $message Log message.
	 * @param string $level   error|warning|info|debug.
	 */
	public function log( string $message, string $level = 'info' ) {
		if ( 'yes' === $this->get_option( 'debug' ) && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'fliinow' ) );
		}
	}

	/**
	 * Get the API client (used by webhook handler & cron).
	 */
	public function get_api(): ?Fliinow_API {
		return $this->api;
	}
}
