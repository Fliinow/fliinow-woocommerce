<?php
/**
 * Fliinow API Client for PHP.
 *
 * Robust HTTP wrapper around the Fliinow Partner API v1.
 * Uses WordPress HTTP API with retry logic, structured errors, and logging.
 *
 * @package Fliinow_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Fliinow_API {

	const PRODUCTION_URL  = 'https://app.fliinow.com/integration-api/v1';
	const SANDBOX_URL     = 'https://demo.fliinow.com/integration-api/v1';
	const RETRY_DELAY_SEC = 1;
	const MAX_RETRY_WAIT  = 5;

	/** @var string */
	private $api_key;

	/** @var string */
	private $base_url;

	/** @var int */
	private $timeout;

	/** @var int */
	private $max_retries;

	/** @var bool */
	private $debug;

	/**
	 * @param string $api_key     Fliinow API key (fk_test_* or fk_live_*).
	 * @param bool   $sandbox     Whether to use sandbox environment.
	 * @param int    $timeout     Request timeout in seconds.
	 * @param int    $max_retries Max retries on transient failures.
	 */
	public function __construct( string $api_key, bool $sandbox = false, int $timeout = 8, int $max_retries = 0 ) {
		$this->api_key     = $api_key;
		$this->base_url    = $sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
		$this->timeout     = $timeout;
		$this->max_retries = $max_retries;
		$this->debug       = $this->is_debug_enabled();
	}

	/**
	 * Return a clone configured for background work (cron).
	 * Higher timeout (30 s) and up to 2 retries — acceptable in non-user-facing context.
	 */
	public function for_background(): self {
		$clone = clone $this;
		$clone->timeout     = 30;
		$clone->max_retries = 2;
		return $clone;
	}

	// ── Public API methods ─────────────────────────────────────────────────

	public function create_operation( array $data ) {
		return $this->request( 'POST', '/operations', $data );
	}

	public function get_operation( string $operation_id ) {
		return $this->request( 'GET', '/operations/' . rawurlencode( $operation_id ) );
	}

	public function get_operation_status( string $operation_id ) {
		return $this->request( 'GET', '/operations/' . rawurlencode( $operation_id ) . '/status' );
	}

	public function get_operation_by_external_id( string $external_id ) {
		return $this->request( 'GET', '/operations/by-external-id/' . rawurlencode( $external_id ) );
	}

	public function cancel_operation( string $operation_id, string $reason = '' ) {
		$data = ! empty( $reason ) ? array( 'reason' => $reason ) : null;
		return $this->request( 'POST', '/operations/' . rawurlencode( $operation_id ) . '/cancel', $data );
	}

	public function health() {
		return $this->request( 'GET', '/health' );
	}

	// ── Core request handler ───────────────────────────────────────────────

	/**
	 * Execute an HTTP request with retry on transient failures.
	 *
	 * @param string     $method HTTP method (GET, POST, etc).
	 * @param string     $path   API path (e.g. /operations).
	 * @param array|null $body   Request body for POST/PUT.
	 * @return array|WP_Error Decoded response body or WP_Error.
	 */
	private function request( string $method, string $path, ?array $body = null ) {
		$url = $this->base_url . $path;

		$args = array(
			'method'    => $method,
			'headers'   => array(
				'X-Fliinow-API-Key' => $this->api_key,
				'Content-Type'      => 'application/json',
				'Accept'            => 'application/json',
				'User-Agent'        => 'FliinowWooCommerce/' . FLIINOW_WC_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
			),
			'timeout'   => $this->timeout,
			'sslverify' => true,
		);

		if ( $body !== null ) {
			$encoded = wp_json_encode( $body );
			if ( false === $encoded ) {
				return new WP_Error( 'fliinow_json_encode', 'Failed to encode request body to JSON.' );
			}
			$args['body'] = $encoded;
		}

		$last_error = null;
		$attempts   = $this->max_retries + 1;

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$this->log_debug( sprintf( '[%s %s] attempt %d', $method, $path, $attempt ) );

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				$this->log_debug( 'Transport error: ' . $response->get_error_message() );

				if ( $attempt < $attempts ) {
					sleep( self::RETRY_DELAY_SEC * $attempt );
					continue;
				}
				break;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );

			// Retry on 502/503/504 (server-side transient errors).
			if ( in_array( $status_code, array( 502, 503, 504 ), true ) && $attempt < $attempts ) {
				$this->log_debug( sprintf( 'HTTP %d — retrying…', $status_code ) );
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				$wait = min( max( $retry_after, self::RETRY_DELAY_SEC * $attempt ), self::MAX_RETRY_WAIT );
				sleep( $wait );
				continue;
			}

			return $this->parse_response( $response, $status_code, $method, $path );
		}

		// All retries exhausted.
		return $last_error ?? new WP_Error( 'fliinow_request_failed', 'Request to Fliinow API failed after retries.' );
	}

	/**
	 * Parse the raw HTTP response.
	 *
	 * @param array|WP_Error $response    wp_remote_request result.
	 * @param int            $status_code HTTP status code.
	 * @param string         $method      HTTP method (for logging).
	 * @param string         $path        Request path (for logging).
	 * @return array|WP_Error
	 */
	private function parse_response( $response, int $status_code, string $method, string $path ) {
		$body_raw = wp_remote_retrieve_body( $response );

		// 204 No Content (e.g. cancel).
		if ( 204 === $status_code || ( $status_code >= 200 && $status_code < 300 && '' === $body_raw ) ) {
			return array( 'success' => true );
		}

		$decoded = json_decode( $body_raw, true );

		if ( null === $decoded && '' !== $body_raw ) {
			$this->log_debug( sprintf( 'JSON decode error for %s %s (HTTP %d)', $method, $path, $status_code ) );
			return new WP_Error(
				'fliinow_json_decode',
				'Invalid JSON response from Fliinow API.',
				array( 'status_code' => $status_code, 'body' => mb_substr( $body_raw, 0, 500 ) )
			);
		}

		if ( $status_code >= 400 ) {
			$error_message = $decoded['message'] ?? $decoded['error_description'] ?? 'Unknown Fliinow API error';
			$error_code    = $decoded['error'] ?? 'FLIINOW_ERROR';

			$this->log_debug( sprintf( 'API error %d [%s]: %s', $status_code, $error_code, $error_message ) );

			return new WP_Error(
				'fliinow_api_error',
				$error_message,
				array(
					'status_code' => $status_code,
					'error_code'  => $error_code,
					'request_id'  => $decoded['requestId'] ?? null,
					'response'    => $decoded,
				)
			);
		}

		return $decoded ?? array();
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private function is_debug_enabled(): bool {
		$settings = get_option( 'woocommerce_fliinow_settings', array() );
		return ( $settings['debug'] ?? 'no' ) === 'yes';
	}

	private function log_debug( string $message ) {
		if ( $this->debug && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( $message, array( 'source' => 'fliinow' ) );
		}
	}
}
