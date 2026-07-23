<?php
/**
 * EOM Courier Base Class
 *
 * Abstract base class that all Bangladesh courier integrations extend.
 * Provides common API communication, logging, and credential handling.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract class EOM_Courier_Base
 *
 * All courier implementations must extend this class and implement
 * the abstract methods for booking, tracking, cancellation, area
 * retrieval, and charge calculation.
 */
abstract class EOM_Courier_Base {

	/**
	 * API key / client ID.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * API secret / client secret.
	 *
	 * @var string
	 */
	protected $api_secret;

	/**
	 * Base API URL. Set to sandbox or live endpoint.
	 *
	 * @var string
	 */
	protected $api_url;

	/**
	 * Whether sandbox (test) mode is enabled.
	 *
	 * @var bool
	 */
	protected $sandbox_mode;

	/**
	 * Unique slug for this courier (e.g. 'steadfast', 'pathao').
	 *
	 * @var string
	 */
	protected $slug;

	/**
	 * Human-readable name for this courier.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Constructor.
	 *
	 * @param array $config {
	 *     Configuration array.
	 *
	 *     @type string $api_key      API key or client ID.
	 *     @type string $api_secret   API secret or client secret.
	 *     @type string $api_url      Base API URL (optional, default uses sandbox/live).
	 *     @type bool   $sandbox_mode Whether to use sandbox endpoints.
	 * }
	 */
	public function __construct( array $config = array() ) {
		$this->api_key      = isset( $config['api_key'] ) ? $config['api_key'] : '';
		$this->api_secret   = isset( $config['api_secret'] ) ? $config['api_secret'] : '';
		$this->sandbox_mode = isset( $config['sandbox_mode'] ) ? (bool) $config['sandbox_mode'] : false;

		if ( ! empty( $config['api_url'] ) ) {
			$this->api_url = trailingslashit( $config['api_url'] );
		} else {
			$this->api_url = $this->sandbox_mode ? $this->get_sandbox_url() : $this->get_live_url();
		}

		$this->slug = isset( $config['slug'] ) ? sanitize_key( $config['slug'] ) : '';
		$this->name = isset( $config['name'] ) ? $config['name'] : '';
	}

	/**
	 * Get the sandbox/base API URL for this courier.
	 * Subclasses should override this if they have a sandbox endpoint.
	 *
	 * @return string
	 */
	protected function get_sandbox_url() {
		return '';
	}

	/**
	 * Get the live/production API URL for this courier.
	 * Subclasses must override this.
	 *
	 * @return string
	 */
	protected function get_live_url() {
		return '';
	}

	/**
	 * Book a parcel with the courier.
	 *
	 * @param array $order_data Order information including recipient, items, COD, etc.
	 *
	 * @return array Response data from the courier API.
	 */
	abstract public function book_parcel( array $order_data ): array;

	/**
	 * Track a parcel by its tracking/consignment ID.
	 *
	 * @param string $tracking_id The tracking identifier.
	 *
	 * @return array Tracking status data.
	 */
	abstract public function track_parcel( string $tracking_id ): array;

	/**
	 * Cancel a parcel/order.
	 *
	 * @param string $tracking_id The tracking or consignment ID to cancel.
	 *
	 * @return array Cancellation response data.
	 */
	abstract public function cancel_parcel( string $tracking_id ): array;

	/**
	 * Retrieve available delivery areas / zones for this courier.
	 *
	 * @return array Hierarchical list of areas (cities, zones, areas).
	 */
	abstract public function get_areas(): array;

	/**
	 * Calculate the delivery charge for a given parcel.
	 *
	 * @param float  $weight      Parcel weight in kg.
	 * @param string $destination Destination area/zone identifier.
	 * @param float  $cod_amount  Cash-on-delivery amount.
	 *
	 * @return float Calculated delivery charge.
	 */
	abstract public function get_charge( float $weight, string $destination, float $cod_amount ): float;

	/**
	 * Get the public tracking URL for a tracking ID.
	 *
	 * @param string $tracking_id The tracking identifier.
	 *
	 * @return string Public tracking page URL.
	 */
	public function get_tracking_url( string $tracking_id ): string {
		return '';
	}

	/**
	 * Check if the courier is configured (credentials are provided).
	 *
	 * @return bool True if the courier has required credentials.
	 */
	public function is_available(): bool {
		return ! empty( $this->api_key ) && ! empty( $this->api_secret );
	}

	/**
	 * Get the courier slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * Get the courier display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
	return ! empty( $this->name ) ? $this->name : $this->slug;
	}

	/**
	 * Get the courier display name (alias).
	 *
	 * @return string
	 */
	public function get_display_name(): string {
		return $this->name;
	}

	/**
	 * Log an API request and its response for debugging.
	 *
	 * @param string $endpoint The API endpoint called.
	 * @param mixed  $request  The request data sent.
	 * @param mixed  $response The response received.
	 *
	 * @return void
	 */
	protected function log_request( string $endpoint, $request, $response ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$log_entry = sprintf(
			"[EOM Courier: %s] Endpoint: %s\nRequest: %s\nResponse: %s\n",
			strtoupper( $this->slug ),
			$endpoint,
			wp_json_encode( $request ),
			wp_json_encode( $response )
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $log_entry );
	}

	/**
	 * Wrapper for wp_remote_get with error handling and timeout.
	 *
	 * @param string $url     The request URL.
	 * @param array  $args    Optional. Additional wp_remote_get arguments.
	 * @param int    $timeout Optional. Request timeout in seconds. Default 30.
	 *
	 * @return array|WP_Error Response data or WP_Error on failure.
	 */
	protected function remote_get( string $url, array $args = array(), int $timeout = 30 ) {
	$defaults = array(
	'timeout' => $timeout,
	'headers' => array(
	'Accept' => 'application/json',
	),
	);

	// Merge headers separately to preserve default headers.
			$headers = array_merge( $defaults['headers'], isset( $args['headers'] ) ? $args['headers'] : array() );
	$args['headers'] = $headers;

	$args = array_merge( $defaults, $args );
			$args['headers'] = $headers;

	 $this->log_request( 'GET ' . $url, array(), array() );

			$response = wp_remote_get( $url, $args );

			return $this->handle_response( $response, $url );
		}

	/**
	 * Wrapper for wp_remote_post with error handling and timeout.
	 *
	 * @param string $url     The request URL.
	 * @param array  $body    The request body (will be JSON-encoded if array).
	 * @param array  $args    Optional. Additional wp_remote_post arguments.
	 * @param int    $timeout Optional. Request timeout in seconds. Default 30.
	 *
	 * @return array|WP_Error Response data or WP_Error on failure.
	 */
	protected function remote_post( string $url, $body = array(), array $args = array(), int $timeout = 30 ) {
	$defaults = array(
	'timeout' => $timeout,
	'headers' => array(
	'Content-Type' => 'application/json',
	'Accept'       => 'application/json',
	),
	'body'    => is_array( $body ) ? wp_json_encode( $body ) : $body,
	);

	// Merge headers separately to avoid overwriting Content-Type/ Accept with auth headers.
			$headers = array_merge( $defaults['headers'], isset( $args['headers'] ) ? $args['headers'] : array() );
	$args['headers'] = $headers;

	$args = array_merge( $defaults, $args );
			$args['headers'] = $headers; // Re-apply merged headers since array_merge above overwrites them.

	 $this->log_request( 'POST ' . $url, $body, array() );

			$response = wp_remote_post( $url, $args );

			return $this->handle_response( $response, $url );
		}

	/**
	 * Process an HTTP response, parse JSON, and handle errors.
	 *
	 * @param array|WP_Error $response The raw HTTP response.
	 * @param string         $url      The request URL (for logging).
	 *
	 * @return array|WP_Error Parsed response array or WP_Error on failure.
	 */
	protected function handle_response( $response, string $url ) {
		if ( is_wp_error( $response ) ) {
			$this->log_request( 'ERROR ' . $url, array(), $response->get_error_message() );
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		$this->log_request( 'RESPONSE ' . $url, array( 'status' => $status_code ), $data );

		if ( $status_code >= 400 ) {
			$error_message = isset( $data['message'] ) ? $data['message'] : sprintf(
				/* translators: %d: HTTP status code */
				__( 'HTTP %d error from courier API.', 'easy-order-manager' ),
				$status_code
			);

			if ( isset( $data['error'] ) ) {
				$error_message = is_string( $data['error'] ) ? $data['error'] : wp_json_encode( $data['error'] );
			}

			return new WP_Error(
				'courier_api_error',
				$error_message,
				array(
					'status' => $status_code,
					'data'   => $data,
				)
			);
		}

		if ( null === $data ) {
			return new WP_Error(
				'courier_invalid_json',
				__( 'Invalid JSON response from courier API.', 'easy-order-manager' ),
				array( 'body' => $body )
			);
		}

		return $data;
	}

	/**
	 * Retrieve courier-specific settings from the database.
	 *
	 * @param string $key Optional. Specific setting key. If empty, returns all.
	 *
	 * @return mixed Setting value(s).
	 */
	protected function get_setting( string $key = '' ) {
		$settings = get_option( 'eom_courier_' . $this->slug, array() );

		if ( empty( $key ) ) {
			return $settings;
		}

		return isset( $settings[ $key ] ) ? $settings[ $key ] : '';
	}

	/**
	 * Save courier-specific settings to the database.
	 *
	 * @param array $settings Settings data to save.
	 *
	 * @return bool True on success, false on failure.
	 */
	protected function save_setting( array $settings ): bool {
		return update_option( 'eom_courier_' . $this->slug, $settings );
	}
}
