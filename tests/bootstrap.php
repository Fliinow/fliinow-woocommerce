<?php
/**
 * PHPUnit Bootstrap for Fliinow WooCommerce Plugin.
 *
 * Provides lightweight mocks for WordPress and WooCommerce so that
 * plugin unit tests can run without a full WordPress installation.
 *
 * @package Fliinow_Checkout\Tests
 */

// ── Constants ──────────────────────────────────────────────────────────────

if ( defined( 'FLIINOW_TEST_BOOTSTRAP_LOADED' ) ) {
	return;
}
define( 'FLIINOW_TEST_BOOTSTRAP_LOADED', true );

define( 'ABSPATH', '/tmp/wordpress/' );
define( 'FLIINOW_WC_VERSION', '1.3.0' );
define( 'FLIINOW_WC_PLUGIN_FILE', dirname( __DIR__ ) . '/fliinow-checkout.php' );
define( 'FLIINOW_WC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'FLIINOW_WC_PLUGIN_URL', 'https://example.com/wp-content/plugins/fliinow-checkout/' );

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// ── Mock registry ──────────────────────────────────────────────────────────

/**
 * Global mock registry to control WordPress function behaviour in tests.
 */
$GLOBALS['fliinow_test_mocks'] = array(
	// wp_remote_request responses (queue — shift from front).
	'wp_remote_responses' => array(),
	// Options storage.
	'options'             => array(
		'woocommerce_fliinow_settings' => array(
			'enabled'        => 'yes',
			'api_key'        => 'fk_test_mock_key',
			'sandbox'        => 'yes',
			'title'          => 'Financiar con Fliinow',
			'description'    => 'Financia tu compra a plazos.',
			'min_amount'     => '60',
			'max_amount'     => '0',
			'package_travel' => 'yes',
			'debug'          => 'no',
		),
	),
	// Registered actions/filters.
	'actions'             => array(),
	'filters'             => array(),
	// Log entries for assertions.
	'log_entries'         => array(),
	// Nonces.
	'nonces'              => array(),
	// Transients.
	'transients'          => array(),
);

/**
 * Queue a mock HTTP response for wp_remote_request.
 */
function fliinow_test_mock_response( int $status_code, $body, array $headers = array() ): void {
	$GLOBALS['fliinow_test_mocks']['wp_remote_responses'][] = array(
		'response' => array( 'code' => $status_code, 'message' => '' ),
		'headers'  => $headers,
		'body'     => is_array( $body ) ? json_encode( $body ) : $body,
	);
}

/**
 * Queue a WP_Error transport response for wp_remote_request.
 */
function fliinow_test_mock_transport_error( string $code = 'http_request_failed', string $message = 'Connection timed out' ): void {
	$GLOBALS['fliinow_test_mocks']['wp_remote_responses'][] = new WP_Error( $code, $message );
}

/**
 * Reset mock registry to defaults.
 */
function fliinow_test_reset_mocks(): void {
	$GLOBALS['fliinow_test_mocks']['wp_remote_responses'] = array();
	$GLOBALS['fliinow_test_mocks']['log_entries']          = array();
	$GLOBALS['fliinow_test_mocks']['nonces']               = array();
	$GLOBALS['fliinow_test_mocks']['transients']           = array();
}

// ── WP_Error class ─────────────────────────────────────────────────────────

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		protected $code;
		protected $message;
		protected $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}

		public function get_error_codes() {
			return $this->code ? array( $this->code ) : array();
		}
	}
}

// ── WordPress core function mocks ──────────────────────────────────────────

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function wp_remote_request( string $url, array $args = array() ) {
	$queue = &$GLOBALS['fliinow_test_mocks']['wp_remote_responses'];
	if ( ! empty( $queue ) ) {
		return array_shift( $queue );
	}
	// Default: 200 with empty JSON.
	return array(
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'headers'  => array(),
		'body'     => '{}',
	);
}

function wp_remote_retrieve_response_code( $response ): int {
	if ( is_wp_error( $response ) ) {
		return 0;
	}
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( $response ): string {
	if ( is_wp_error( $response ) ) {
		return '';
	}
	return $response['body'] ?? '';
}

function wp_remote_retrieve_header( $response, string $header ): string {
	if ( is_wp_error( $response ) ) {
		return '';
	}
	return $response['headers'][ $header ] ?? '';
}

function wp_json_encode( $data, int $flags = 0 ): string {
	$result = json_encode( $data, $flags );
	return false === $result ? '' : $result;
}

function get_bloginfo( string $show = '' ): string {
	if ( 'version' === $show ) {
		return '6.7';
	}
	return '';
}

function get_option( string $option, $default = false ) {
	return $GLOBALS['fliinow_test_mocks']['options'][ $option ] ?? $default;
}

function update_option( string $option, $value ): bool {
	$GLOBALS['fliinow_test_mocks']['options'][ $option ] = $value;
	return true;
}

function delete_option( string $option ): bool {
	unset( $GLOBALS['fliinow_test_mocks']['options'][ $option ] );
	return true;
}

function set_transient( string $key, $value, int $expiration = 0 ): bool {
	$GLOBALS['fliinow_test_mocks']['transients'][ $key ] = $value;
	return true;
}

function get_transient( string $key ) {
	return $GLOBALS['fliinow_test_mocks']['transients'][ $key ] ?? false;
}

function delete_transient( string $key ): bool {
	unset( $GLOBALS['fliinow_test_mocks']['transients'][ $key ] );
	return true;
}

function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['fliinow_test_mocks']['actions'][] = array(
		'hook'     => $hook,
		'callback' => $callback,
		'priority' => $priority,
	);
}

function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['fliinow_test_mocks']['filters'][] = array(
		'hook'     => $hook,
		'callback' => $callback,
		'priority' => $priority,
	);
}

function apply_filters( string $hook, $value, ...$args ) {
	return $value; // Pass through — no filters registered in tests.
}

function sanitize_text_field( $str ): string {
	return trim( strip_tags( (string) $str ) );
}

function esc_url_raw( string $url ): string {
	return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( string $text, string $domain = 'default' ): string {
	return $text;
}

function esc_js( string $text ): string {
	return addslashes( $text );
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function _e( string $text, string $domain = 'default' ): void {
	echo $text;
}

function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( string $url ): string {
	return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
}

function absint( $value ): int {
	return abs( (int) $value );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_create_nonce( string $action = '' ): string {
	$nonce = 'test_nonce_' . md5( $action );
	$GLOBALS['fliinow_test_mocks']['nonces'][ $nonce ] = $action;
	return $nonce;
}

function wp_verify_nonce( string $nonce, string $action = '' ): bool {
	return ( $GLOBALS['fliinow_test_mocks']['nonces'][ $nonce ] ?? '' ) === $action;
}

function check_ajax_referer( string $action, $query_arg = false, bool $stop = true ): bool {
	return true;
}

function current_user_can( string $capability ): bool {
	return true;
}

function admin_url( string $path = '' ): string {
	return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
}

function is_admin(): bool {
	return false;
}

function add_query_arg( $args, string $url = '' ): string {
	if ( is_array( $args ) ) {
		return $url . '?' . http_build_query( $args );
	}
	return $url;
}

function plugin_basename( string $file ): string {
	return 'fliinow-checkout/' . basename( $file );
}

function plugin_dir_path( string $file ): string {
	return dirname( $file ) . '/';
}

function plugin_dir_url( string $file ): string {
	return 'https://example.com/wp-content/plugins/fliinow-checkout/';
}

function load_plugin_textdomain( string $domain, $deprecated = false, $path = '' ): bool {
	return true;
}

function wp_schedule_event( $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
	return true;
}

function wp_next_scheduled( string $hook ): bool {
	return false;
}

function wp_clear_scheduled_hook( string $hook ): void {
}

function register_deactivation_hook( string $file, $callback ): void {
}

function wp_register_script( string $handle, string $src, array $deps = array(), $ver = false, $in_footer = false ): bool {
	return true;
}

function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
	return true;
}

function wp_set_script_translations( string $handle, string $domain, string $path = '' ): bool {
	return true;
}

function get_current_screen() {
	return null;
}

function wp_safe_redirect( string $location, int $status = 302 ): void {
	// Throw instead of no-op: prevents `exit` after redirect from killing PHPUnit.
	throw new Fliinow_Test_Redirect_Exception( $location, $status );
}

/**
 * Custom exception to simulate wp_safe_redirect + exit in tests.
 */
class Fliinow_Test_Redirect_Exception extends \RuntimeException {
	public string $url;
	public function __construct( string $url, int $status = 302 ) {
		$this->url = $url;
		parent::__construct( 'Redirect to: ' . $url, $status );
	}
}

function wp_send_json_error( $data = null, $status_code = null ): void {
	throw new \RuntimeException( 'wp_send_json_error: ' . json_encode( $data ) );
}

function wp_send_json_success( $data = null, $status_code = null ): void {
	throw new \RuntimeException( 'wp_send_json_success: ' . json_encode( $data ) );
}

if ( ! function_exists( 'hash_equals' ) ) {
	function hash_equals( string $known, string $user ): bool {
		return \hash_equals( $known, $user );
	}
}

// ── WooCommerce function mocks ─────────────────────────────────────────────

function wc_get_order( $id ) {
	return $GLOBALS['fliinow_test_mocks']['current_order'] ?? null;
}

function wc_add_notice( string $message, string $type = 'success' ): void {
	$GLOBALS['fliinow_test_mocks']['notices'][] = array(
		'message' => $message,
		'type'    => $type,
	);
}

function wc_get_checkout_url(): string {
	return 'https://example.com/checkout/';
}

function wc_get_page_permalink( string $page ): string {
	return 'https://example.com/' . $page . '/';
}

// ── Mock WC Logger ─────────────────────────────────────────────────────────

class Mock_WC_Logger {
	public function debug( string $message, array $context = array() ): void {
		$GLOBALS['fliinow_test_mocks']['log_entries'][] = array( 'level' => 'debug', 'message' => $message );
	}

	public function log( string $level, string $message, array $context = array() ): void {
		$GLOBALS['fliinow_test_mocks']['log_entries'][] = array( 'level' => $level, 'message' => $message );
	}
}

function wc_get_logger(): Mock_WC_Logger {
	static $logger;
	if ( ! $logger ) {
		$logger = new Mock_WC_Logger();
	}
	return $logger;
}

// ── Mock WC_Order ──────────────────────────────────────────────────────────

class WC_Order {
	protected $id;
	protected $data = array();
	protected $meta = array();
	protected $status = 'pending';
	protected $items = array();
	protected $saved = false;

	public function __construct( int $id = 1 ) {
		$this->id = $id;
		$this->data = array(
			'billing_first_name' => 'Juan',
			'billing_last_name'  => 'García',
			'billing_email'      => 'juan@example.com',
			'billing_phone'      => '612345678',
			'billing_address_1'  => 'Calle Mayor 1',
			'billing_address_2'  => 'Piso 3',
			'billing_city'       => 'Madrid',
			'billing_postcode'   => '28001',
			'billing_country'    => 'ES',
			'total'              => '150.00',
			'order_key'          => 'wc_order_abc123',
			'payment_method'     => 'fliinow',
		);
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_billing_first_name(): string {
		return $this->data['billing_first_name'] ?? '';
	}

	public function get_billing_last_name(): string {
		return $this->data['billing_last_name'] ?? '';
	}

	public function get_billing_email(): string {
		return $this->data['billing_email'] ?? '';
	}

	public function get_billing_phone(): string {
		return $this->data['billing_phone'] ?? '';
	}

	public function get_billing_address_1(): string {
		return $this->data['billing_address_1'] ?? '';
	}

	public function get_billing_address_2(): string {
		return $this->data['billing_address_2'] ?? '';
	}

	public function get_billing_city(): string {
		return $this->data['billing_city'] ?? '';
	}

	public function get_billing_postcode(): string {
		return $this->data['billing_postcode'] ?? '';
	}

	public function get_billing_country(): string {
		return $this->data['billing_country'] ?? '';
	}

	public function get_total( string $context = 'view' ): string {
		return $this->data['total'] ?? '0';
	}

	public function get_order_key(): string {
		return $this->data['order_key'] ?? '';
	}

	public function get_payment_method(): string {
		return $this->data['payment_method'] ?? '';
	}

	public function get_meta( string $key, bool $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function has_status( $status ): bool {
		if ( is_array( $status ) ) {
			return in_array( $this->status, $status, true );
		}
		return $this->status === $status;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function set_status( string $status, string $note = '' ): void {
		$this->status = $status;
	}

	public function update_status( string $status, string $note = '' ): void {
		$this->status = $status;
	}

	public function payment_complete( string $transaction_id = '' ): bool {
		$this->status = 'completed';
		$this->meta['_transaction_id'] = $transaction_id;
		return true;
	}

	public function add_order_note( string $note ): int {
		return 1;
	}

	public function get_items(): array {
		return $this->items;
	}

	public function get_checkout_order_received_url(): string {
		return 'https://example.com/checkout/order-received/' . $this->id . '/';
	}

	public function save(): bool {
		$this->saved = true;
		return true;
	}

	public function was_saved(): bool {
		return $this->saved;
	}

	// Test helpers

	public function set_data( string $key, $value ): void {
		$this->data[ $key ] = $value;
	}

	public function set_meta( string $key, $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function set_items( array $items ): void {
		$this->items = $items;
	}

	public function set_test_status( string $status ): void {
		$this->status = $status;
	}
}

// ── Mock WC_Order_Item_Product ─────────────────────────────────────────────

class WC_Order_Item_Product {
	private $name;
	private $qty;
	private $product_id;
	private $variation_id;
	private $meta = array();

	public function __construct( string $name, int $qty, int $product_id = 1, int $variation_id = 0 ) {
		$this->name         = $name;
		$this->qty          = $qty;
		$this->product_id   = $product_id;
		$this->variation_id = $variation_id;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_quantity(): int {
		return $this->qty;
	}

	public function get_product_id(): int {
		return $this->product_id;
	}

	public function get_variation_id(): int {
		return $this->variation_id;
	}

	public function get_meta( string $key, bool $single = true ) {
		return $this->meta[ $key ] ?? ( $single ? '' : array() );
	}
}

// ── Mock WC_Payment_Gateway ────────────────────────────────────────────────

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	class WC_Payment_Gateway {
		public $id;
		public $icon;
		public $has_fields;
		public $method_title;
		public $method_description;
		public $order_button_text;
		public $supports = array( 'products' );
		public $title;
		public $description;
		public $enabled;
		public $form_fields = array();
		protected $settings = array();

		public function init_form_fields() {}

		public function init_settings() {
			$this->settings = get_option( 'woocommerce_' . $this->id . '_settings', array() );
		}

		public function get_option( string $key, $empty_value = '' ): string {
			if ( isset( $this->settings[ $key ] ) && '' !== $this->settings[ $key ] ) {
				return $this->settings[ $key ];
			}
			// Check form_fields for default.
			if ( isset( $this->form_fields[ $key ]['default'] ) ) {
				return $this->form_fields[ $key ]['default'];
			}
			return $empty_value;
		}

		public function is_available() {
			return 'yes' === $this->enabled;
		}

		public function process_admin_options(): bool {
			return true;
		}
	}
}

// ── Mock WC() global ──────────────────────────────────────────────────────

class Mock_WC_Cart {
	private $total = 150.00;

	public function get_total( string $context = 'view' ) {
		return $context === 'raw' ? $this->total : number_format( $this->total, 2, '.', '' );
	}

	public function add_to_cart( $product_id, $quantity = 1, $variation_id = 0, $variation = array() ) {
		return true;
	}

	public function set_total( float $total ): void {
		$this->total = $total;
	}
}

class Mock_WC_Payment_Gateways {
	private $gateways = array();

	public function payment_gateways(): array {
		return $this->gateways;
	}

	public function set_gateways( array $gateways ): void {
		$this->gateways = $gateways;
	}
}

class Mock_WooCommerce {
	public $cart;
	private $payment_gateways;

	public function __construct() {
		$this->cart             = new Mock_WC_Cart();
		$this->payment_gateways = new Mock_WC_Payment_Gateways();
	}

	public function payment_gateways(): Mock_WC_Payment_Gateways {
		return $this->payment_gateways;
	}

	public function api_request_url( string $api_request ): string {
		return 'https://example.com/?wc-api=' . $api_request;
	}
}

$GLOBALS['mock_wc_instance'] = new Mock_WooCommerce();

function WC(): Mock_WooCommerce {
	return $GLOBALS['mock_wc_instance'];
}

// ── Mock WC Blocks classes (namespaced — separate file) ────────────────────
require_once __DIR__ . '/mock-namespaces.php';

// ── Load plugin classes ────────────────────────────────────────────────────
require_once FLIINOW_WC_PLUGIN_DIR . 'includes/class-fliinow-api.php';
require_once FLIINOW_WC_PLUGIN_DIR . 'includes/class-fliinow-gateway.php';
require_once FLIINOW_WC_PLUGIN_DIR . 'includes/class-fliinow-webhook.php';
require_once FLIINOW_WC_PLUGIN_DIR . 'includes/class-fliinow-blocks.php';
