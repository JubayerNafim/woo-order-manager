<?php
/**
 * EOM Courier - RedX
 *
 * RedX Courier API integration using Bearer token authentication.
 * Handles parcel booking, tracking, cancellation, area retrieval,
 * and delivery charge calculation.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Courier_RedX
 *
 * Integrates with the RedX Courier API (v1.0.0-beta).
 * Authentication is via a JWT bearer token obtained from the auth endpoint.
 */
class EOM_Courier_RedX extends EOM_Courier_Base {

	/**
	 * Merchant ID assigned by RedX.
	 *
	 * @var string
	 */
	private $merchant_id;

	/**
	 * Store ID assigned by RedX.
	 *
	 * @var string
	 */
	private $store_id;

	/**
	 * Bearer access token.
	 *
	 * @var string
	 */
	private $token = '';

	/**
	 * Token expiry timestamp.
	 *
	 * @var int
	 */
	private $token_expiry = 0;

	/**
	 * Constructor.
	 *
	 * @param array $config Configuration array.
	 */
	public function __construct( array $config = array() ) {
		$this->slug = 'redx';
		$this->name = __( 'RedX', 'easy-order-manager' );

		parent::__construct( $config );

		$this->merchant_id = isset( $config['merchant_id'] ) ? $config['merchant_id'] : '';
		$this->store_id    = isset( $config['store_id'] ) ? $config['store_id'] : '';
	}

	/**
	 * Get the live API URL.
	 *
	 * @return string
	 */
	protected function get_live_url(): string {
		return 'https://openapi.redx.com.bd/v1.0.0-beta/';
	}

	/**
	 * Get the sandbox API URL.
	 *
	 * @return string
	 */
	protected function get_sandbox_url(): string {
		return 'https://openapi-sandbox.redx.com.bd/v1.0.0-beta/';
	}

	/**
	 * Check if the courier is configured.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return ! empty( $this->api_key ) && ! empty( $this->api_secret );
	}

	/**
	 * Authenticate with RedX API and obtain a bearer token.
	 * Caches the token in a transient.
	 *
	 * @return bool True on successful authentication.
	 */
	public function authenticate(): bool {
		// Check cached token.
		$token_data = get_transient( 'eom_redx_token' );
		if ( false !== $token_data && isset( $token_data['token'] ) ) {
			$this->token         = $token_data['token'];
			$this->token_expiry  = isset( $token_data['expires_at'] ) ? $token_data['expires_at'] : 0;
			return true;
		}

		$url  = $this->api_url . 'auth/token';
		$body = array(
			'api_key'    => $this->api_key,
			'api_secret' => $this->api_secret,
		);

		$response = $this->remote_post( $url, $body, array(), 30 );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( isset( $response['token'] ) || isset( $response['access_token'] ) ) {
			$this->token = isset( $response['token'] ) ? $response['token'] : $response['access_token'];

			$expires_in = isset( $response['expires_in'] ) ? (int) $response['expires_in'] : 86400;
			$this->token_expiry = time() + $expires_in;

			// Cache token for 90% of lifetime.
			$cache_duration = (int) ( $expires_in * 0.9 );
			set_transient(
				'eom_redx_token',
				array(
					'token'      => $this->token,
					'expires_at' => $this->token_expiry,
				),
				$cache_duration
			);

			return true;
		}

		return false;
	}

	/**
	 * Ensure a valid token exists.
	 *
	 * @return bool
	 */
	private function ensure_authenticated(): bool {
		if ( ! empty( $this->token ) && time() < $this->token_expiry ) {
			return true;
		}
		return $this->authenticate();
	}

	/**
	 * Get authorization headers.
	 *
	 * @return array Headers with Bearer token.
	 */
	private function get_auth_headers(): array {
		$headers = array(
			'Authorization' => 'Bearer ' . $this->token,
		);

		if ( ! empty( $this->merchant_id ) ) {
			$headers['X-Merchant-Id'] = $this->merchant_id;
		}
		if ( ! empty( $this->store_id ) ) {
			$headers['X-Store-Id'] = $this->store_id;
		}

		return $headers;
	}

	/**
	 * Book a parcel with RedX.
	 *
	 * @param array $order_data {
	 *     Parcel booking data.
	 *
	 *     @type string $recipient_name     Recipient full name.
	 *     @type string $recipient_phone    Recipient phone number.
	 *     @type string $recipient_address  Recipient full address.
	 *     @type float  $cod_amount         Cash on delivery amount.
	 *     @type float  $total_weight       Parcel weight in kg.
	 *     @type int    $item_quantity      Number of items.
	 *     @type string $item_description   Item description.
	 *     @type string $pickup_store_id    Pickup store identifier.
	 *     @type string $area_id            RedX area ID for destination.
	 * }
	 *
	 * @return array Booking response.
	 */
	public function book_parcel( array $order_data ): array {
		if ( ! $this->ensure_authenticated() ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication failed with RedX.', 'easy-order-manager' ),
			);
		}

		$url  = $this->api_url . 'parcels';
		$args = array(
			'headers' => $this->get_auth_headers(),
		);

		$recipient_name    = isset( $order_data['recipient_name'] ) ? $order_data['recipient_name'] : ( isset( $order_data['recipient']['name'] ) ? $order_data['recipient']['name'] : '' );
		$recipient_phone   = isset( $order_data['recipient_phone'] ) ? $order_data['recipient_phone'] : ( isset( $order_data['recipient']['phone'] ) ? $order_data['recipient']['phone'] : '' );
		$recipient_address = isset( $order_data['recipient_address'] ) ? $order_data['recipient_address'] : ( isset( $order_data['recipient']['address'] ) ? $order_data['recipient']['address'] : '' );

		$body = array(
			'reference'         => isset( $order_data['order_id'] ) ? (string) $order_data['order_id'] : '',
			'customer_name'     => $recipient_name,
			'customer_phone'    => $recipient_phone,
			'customer_address'  => $recipient_address,
			'amount_to_collect' => isset( $order_data['cod_amount'] ) ? (float) $order_data['cod_amount'] : 0,
			'total_weight'      => isset( $order_data['total_weight'] ) ? (float) $order_data['total_weight'] : 0.5,
			'item_quantity'     => isset( $order_data['item_quantity'] ) ? (int) $order_data['item_quantity'] : 1,
			'item_description'  => isset( $order_data['item_description'] ) ? $order_data['item_description'] : '',
			'pickup_store'      => isset( $order_data['pickup_store_id'] ) ? $order_data['pickup_store_id'] : '',
			'area'              => isset( $order_data['area_id'] ) ? $order_data['area_id'] : '',
			'special_instruction' => isset( $order_data['special_instruction'] ) ? $order_data['special_instruction'] : '',
		);

		$response = $this->remote_post( $url, $body, $args, 30 );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		return array(
			'success'          => true,
			'tracking_id'      => isset( $response['tracking_number'] ) ? $response['tracking_number'] : ( isset( $response['data']['tracking_number'] ) ? $response['data']['tracking_number'] : '' ),
			'parcel_id'        => isset( $response['id'] ) ? $response['id'] : ( isset( $response['data']['id'] ) ? $response['data']['id'] : '' ),
			'tracking_url'     => $this->get_tracking_url(
				isset( $response['tracking_number'] ) ? $response['tracking_number'] : ''
			),
			'status'           => isset( $response['status'] ) ? $response['status'] : 'booked',
			'delivery_charge'  => isset( $response['delivery_charge'] ) ? (float) $response['delivery_charge'] : 0,
			'raw_response'     => $response,
		);
	}

	/**
	 * Track a RedX parcel by tracking number.
	 *
	 * @param string $tracking_id RedX tracking number.
	 *
	 * @return array Tracking data.
	 */
	public function track_parcel( string $tracking_id ): array {
		if ( ! $this->ensure_authenticated() ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication failed with RedX.', 'easy-order-manager' ),
			);
		}

		$url  = $this->api_url . 'parcels/' . urlencode( $tracking_id );
		$args = array(
			'headers' => $this->get_auth_headers(),
		);

		$response = $this->remote_get( $url, $args, 30 );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$data = isset( $response['data'] ) ? $response['data'] : $response;

		return array(
			'success'          => true,
			'tracking_id'      => $tracking_id,
			'status'           => isset( $data['status'] ) ? $data['status'] : 'unknown',
			'tracking_status'  => isset( $data['tracking_status'] ) ? $data['tracking_status'] : '',
			'current_location' => isset( $data['current_location'] ) ? $data['current_location'] : '',
			'last_update'      => isset( $data['updated_at'] ) ? $data['updated_at'] : ( isset( $data['updated_at'] ) ? $data['updated_at'] : '' ),
			'timeline'         => isset( $data['tracking_logs'] ) ? $data['tracking_logs'] : array(),
			'raw_response'     => $response,
		);
	}

	/**
	 * Cancel a RedX parcel.
	 *
	 * @param string $tracking_id Tracking number or parcel ID.
	 *
	 * @return array Cancellation response.
	 */
	public function cancel_parcel( string $tracking_id ): array {
		if ( ! $this->ensure_authenticated() ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication failed with RedX.', 'easy-order-manager' ),
			);
		}

		$url  = $this->api_url . 'parcels/' . urlencode( $tracking_id ) . '/cancel';
		$args = array(
			'headers' => $this->get_auth_headers(),
		);

		$response = $this->remote_post( $url, array(), $args, 30 );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		return array(
			'success'      => true,
			'tracking_id'  => $tracking_id,
			'status'       => 'cancelled',
			'raw_response' => $response,
		);
	}

	/**
	 * Get RedX delivery areas (division/district/area hierarchy).
	 *
	 * @return array Hierarchical area data.
	 */
	public function get_areas(): array {
		if ( ! $this->ensure_authenticated() ) {
			return array();
		}

		$url  = $this->api_url . 'areas';
		$args = array(
			'headers' => $this->get_auth_headers(),
		);

		$response = $this->remote_get( $url, $args, 30 );

		if ( is_wp_error( $response ) ) {
			return $this->get_default_areas();
		}

		$data      = isset( $response['data'] ) ? $response['data'] : $response;
		$areas     = array();
		$divisions = array();

		if ( is_array( $data ) ) {
			foreach ( $data as $item ) {
				$division = isset( $item['division_name'] ) ? $item['division_name'] : ( isset( $item['division'] ) ? $item['division'] : '' );
				$district = isset( $item['district_name'] ) ? $item['district_name'] : ( isset( $item['district'] ) ? $item['district'] : '' );
				$area_id  = isset( $item['id'] ) ? $item['id'] : ( isset( $item['area_id'] ) ? $item['area_id'] : '' );
				$area_name = isset( $item['area_name'] ) ? $item['area_name'] : ( isset( $item['name'] ) ? $item['name'] : '' );

				if ( ! isset( $divisions[ $division ] ) ) {
					$divisions[ $division ] = array(
						'id'       => sanitize_title( $division ),
						'name'     => $division,
						'type'     => 'division',
						'childs'   => array(),
					);
				}

				$district_key = sanitize_title( $district );
				if ( ! isset( $divisions[ $division ]['childs'][ $district_key ] ) ) {
					$divisions[ $division ]['childs'][ $district_key ] = array(
						'id'     => sanitize_title( $district ),
						'name'   => $district,
						'type'   => 'district',
						'childs' => array(),
					);
				}

				$divisions[ $division ]['childs'][ $district_key ]['childs'][] = array(
					'id'   => $area_id,
					'name' => $area_name,
					'type' => 'area',
				);
			}
		}

		// Convert childs from associative to indexed arrays.
		foreach ( $divisions as &$div ) {
			$div['childs'] = array_values( $div['childs'] );
		}

		return array_values( $divisions );
	}

	/**
	 * Default areas when API is unavailable.
	 *
	 * @return array Default area list.
	 */
	private function get_default_areas(): array {
		return array(
			array(
				'id'     => 'dhaka',
				'name'   => __( 'Dhaka Division', 'easy-order-manager' ),
				'type'   => 'division',
				'childs' => array(
					array(
						'id'     => 'dhaka_district',
						'name'   => __( 'Dhaka District', 'easy-order-manager' ),
						'type'   => 'district',
						'childs' => array(),
					),
					array(
						'id'     => 'gazipur',
						'name'   => __( 'Gazipur', 'easy-order-manager' ),
						'type'   => 'district',
						'childs' => array(),
					),
					array(
						'id'     => 'narayanganj',
						'name'   => __( 'Narayanganj', 'easy-order-manager' ),
						'type'   => 'district',
						'childs' => array(),
					),
				),
			),
			array(
				'id'     => 'chattogram',
				'name'   => __( 'Chattogram Division', 'easy-order-manager' ),
				'type'   => 'division',
				'childs' => array(),
			),
			array(
				'id'     => 'khulna',
				'name'   => __( 'Khulna Division', 'easy-order-manager' ),
				'type'   => 'division',
				'childs' => array(),
			),
		);
	}

	/**
	 * Calculate RedX delivery charge.
	 *
	 * RedX pricing structure:
	 * - Inside Dhaka: 55 BDT first kg, +10 BDT per additional kg
	 * - Outside Dhaka: 110 BDT first kg, +20 BDT per additional kg
	 * - COD fee: 1.5% (min 15 BDT)
	 *
	 * @param float  $weight      Parcel weight in kg.
	 * @param string $destination Destination area/division ID.
	 * @param float  $cod_amount  Cash on delivery amount.
	 *
	 * @return float Calculated charge.
	 */
	public function get_charge( float $weight, string $destination, float $cod_amount ): float {
		if ( ! $this->ensure_authenticated() ) {
			return $this->calculate_charge_fallback( $weight, $destination, $cod_amount );
		}

		$url  = $this->api_url . 'pricing';
		$args = array(
			'headers' => $this->get_auth_headers(),
		);

		$body = array(
			'weight'          => max( 0.1, $weight ),
			'destination'     => $destination,
			'amount_to_collect' => max( 0, $cod_amount ),
		);

		$response = $this->remote_post( $url, $body, $args, 15 );

		if ( is_wp_error( $response ) ) {
			return $this->calculate_charge_fallback( $weight, $destination, $cod_amount );
		}

		if ( isset( $response['delivery_charge'] ) ) {
			return (float) $response['delivery_charge'];
		}
		if ( isset( $response['data']['delivery_charge'] ) ) {
			return (float) $response['data']['delivery_charge'];
		}

		return $this->calculate_charge_fallback( $weight, $destination, $cod_amount );
	}

	/**
	 * Fallback charge calculation when API is unavailable.
	 *
	 * @param float  $weight      Parcel weight.
	 * @param string $destination Destination identifier.
	 * @param float  $cod_amount  COD amount.
	 *
	 * @return float
	 */
	private function calculate_charge_fallback( float $weight, string $destination, float $cod_amount ): float {
		$inside_dhaka = ( false !== stripos( $destination, 'dhaka' ) );

		if ( $inside_dhaka ) {
			$base_charge = 55.0;
			$extra_rate  = 10.0;
		} else {
			$base_charge = 110.0;
			$extra_rate  = 20.0;
		}

		$weight = max( 0.1, $weight );
		if ( $weight > 1.0 ) {
			$extra_kg    = ceil( $weight - 1.0 );
			$base_charge += $extra_kg * $extra_rate;
		}

		// COD fee: 1.5% (min 15 BDT).
		if ( $cod_amount > 0 ) {
			$cod_fee = $cod_amount * 0.015;
			$base_charge += max( 15.0, $cod_fee );
		}

		return round( $base_charge, 2 );
	}

	/**
	 * Get public tracking URL for RedX.
	 *
	 * @param string $tracking_id Tracking number.
	 *
	 * @return string
	 */
	public function get_tracking_url( string $tracking_id ): string {
		return 'https://redx.com.bd/tracking/' . urlencode( $tracking_id );
	}
}
