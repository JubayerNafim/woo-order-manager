<?php
/**
 * EOM Courier - eCourier
 *
 * eCourier Bangladesh API integration.
 * Supports parcel booking, tracking, cancellation, area retrieval,
 * and delivery charge calculation.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Courier_eCourier
 *
 * Integrates with the eCourier Bangladesh API.
 * Uses API key + secret + user ID for authentication.
 */
class EOM_Courier_eCourier extends EOM_Courier_Base {

	/**
	 * eCourier user ID.
	 *
	 * @var string
	 */
	private $user_id;

	/**
	 * Authentication token.
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
		$this->slug = 'ecourier';
		$this->name = __( 'eCourier', 'easy-order-manager' );

		parent::__construct( $config );

		$this->user_id = isset( $config['user_id'] ) ? $config['user_id'] : '';
	}

	/**
	 * Get live API URL.
	 *
	 * @return string
	 */
	protected function get_live_url(): string {
		return 'https://api.ecourier.com.bd/api/';
	}

	/**
	 * Get sandbox API URL.
	 *
	 * @return string
	 */
	protected function get_sandbox_url(): string {
		return 'https://sandbox.ecourier.com.bd/api/';
	}

	/**
	 * Check if the courier is configured.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return ! empty( $this->api_key ) && ! empty( $this->api_secret ) && ! empty( $this->user_id );
	}

	/**
	 * Authenticate with eCourier API.
	 *
	 * @return bool True on success.
	 */
	public function authenticate(): bool {
		$token_data = get_transient( 'eom_ecourier_token' );
		if ( false !== $token_data && isset( $token_data['token'] ) ) {
			$this->token        = $token_data['token'];
			$this->token_expiry = isset( $token_data['expires_at'] ) ? $token_data['expires_at'] : 0;
			return true;
		}

		$url  = $this->api_url . 'login';
		$body = array(
			'api_key'    => $this->api_key,
			'api_secret' => $this->api_secret,
			'user_id'    => $this->user_id,
		);

		$response = $this->remote_post( $url, $body, array(), 30 );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( isset( $response['token'] ) || isset( $response['access_token'] ) ) {
			$this->token = isset( $response['token'] ) ? $response['token'] : $response['access_token'];

			$expires_in = isset( $response['expires_in'] ) ? (int) $response['expires_in'] : 3600;
			$this->token_expiry = time() + $expires_in;

			set_transient(
				'eom_ecourier_token',
				array(
					'token'      => $this->token,
					'expires_at' => $this->token_expiry,
				),
				(int) ( $expires_in * 0.9 )
			);

			return true;
		}

		return false;
	}

	/**
	 * Ensure valid authentication.
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
	 * Get authentication headers.
	 *
	 * @return array
	 */
	private function get_auth_headers(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->token,
			'X-User-Id'     => $this->user_id,
		);
	}

	/**
	 * Book a parcel with eCourier.
	 *
	 * @param array $order_data Order information.
	 *
	 * @return array Booking response.
	 */
	public function book_parcel( array $order_data ): array {
		if ( ! $this->ensure_authenticated() ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication failed with eCourier.', 'easy-order-manager' ),
			);
		}

		$url  = $this->api_url . 'parcels/create';
		$args = array(
			'headers' => $this->get_auth_headers(),
		);

		$recipient_name    = isset( $order_data['recipient_name'] ) ? $order_data['recipient_name'] : ( isset( $order_data['recipient']['name'] ) ? $order_data['recipient']['name'] : '' );
		$recipient_phone   = isset( $order_data['recipient_phone'] ) ? $order_data['recipient_phone'] : ( isset( $order_data['recipient']['phone'] ) ? $order_data['recipient']['phone'] : '' );
		$recipient_address = isset( $order_data['recipient_address'] ) ? $order_data['recipient_address'] : ( isset( $order_data['recipient']['address'] ) ? $order_data['recipient']['address'] : '' );

		$body = array(
			'reference'         => isset( $order_data['order_id'] ) ? (string) $order_data['order_id'] : '',
			'recipient_name'    => $recipient_name,
			'recipient_mobile'  => $recipient_phone,
			'recipient_address' => $recipient_address,
			'recipient_city'    => isset( $order_data['recipient_city'] ) ? $order_data['recipient_city'] : '',
			'recipient_thana'   => isset( $order_data['recipient_thana'] ) ? $order_data['recipient_thana'] : '',
			'cod_amount'        => isset( $order_data['cod_amount'] ) ? (float) $order_data['cod_amount'] : 0,
			'product_weight'    => isset( $order_data['total_weight'] ) ? (float) $order_data['total_weight'] : 0.5,
			'product_quantity'  => isset( $order_data['item_quantity'] ) ? (int) $order_data['item_quantity'] : 1,
			'product_description' => isset( $order_data['item_description'] ) ? $order_data['item_description'] : '',
			'pickup_address'    => isset( $order_data['pickup_address'] ) ? $order_data['pickup_address'] : '',
			'pickup_mobile'     => isset( $order_data['pickup_phone'] ) ? $order_data['pickup_phone'] : '',
			'note'              => isset( $order_data['special_instruction'] ) ? $order_data['special_instruction'] : '',
		);

		$response = $this->remote_post( $url, $body, $args, 30 );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		return array(
			'success'      => true,
			'tracking_id'  => isset( $response['tracking_id'] ) ? $response['tracking_id'] : ( isset( $response['data']['tracking_id'] ) ? $response['data']['tracking_id'] : '' ),
			'consignment'  => isset( $response['consignment_id'] ) ? $response['consignment_id'] : '',
			'tracking_url' => $this->get_tracking_url(
				isset( $response['tracking_id'] ) ? $response['tracking_id'] : ''
			),
			'status'       => isset( $response['status'] ) ? $response['status'] : 'booked',
			'charge'       => isset( $response['delivery_charge'] ) ? (float) $response['delivery_charge'] : 0,
			'raw_response' => $response,
		);
	}

	/**
	 * Track an eCourier parcel.
	 *
	 * @param string $tracking_id Tracking ID.
	 *
	 * @return array Tracking data.
	 */
	public function track_parcel( string $tracking_id ): array {
		if ( ! $this->ensure_authenticated() ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication failed with eCourier.', 'easy-order-manager' ),
			);
		}

		$url  = $this->api_url . 'parcels/' . urlencode( $tracking_id ) . '/status';
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
			'status_label'     => isset( $data['status_label'] ) ? $data['status_label'] : '',
			'current_location' => isset( $data['current_location'] ) ? $data['current_location'] : '',
			'last_update'      => isset( $data['updated_at'] ) ? $data['updated_at'] : '',
			'timeline'         => isset( $data['tracking_log'] ) ? $data['tracking_log'] : array(),
			'raw_response'     => $response,
		);
	}

	/**
	 * Cancel an eCourier parcel.
	 *
	 * @param string $tracking_id Tracking ID.
	 *
	 * @return array Cancellation response.
	 */
	public function cancel_parcel( string $tracking_id ): array {
		if ( ! $this->ensure_authenticated() ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication failed with eCourier.', 'easy-order-manager' ),
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
	 * Get eCourier delivery areas (city/thana hierarchy).
	 *
	 * @return array Area data.
	 */
	public function get_areas(): array {
		if ( ! $this->ensure_authenticated() ) {
			return array();
		}

		$headers = array( 'headers' => $this->get_auth_headers() );
		$areas   = array();

		// Get cities.
		$cities_response = $this->remote_get( $this->api_url . 'cities', $headers, 30 );
		if ( is_wp_error( $cities_response ) ) {
			return $this->get_default_areas();
		}

		$cities = isset( $cities_response['data'] ) ? $cities_response['data'] : ( isset( $cities_response['cities'] ) ? $cities_response['cities'] : array() );

		foreach ( $cities as $city ) {
			$city_id   = isset( $city['id'] ) ? $city['id'] : ( isset( $city['city_id'] ) ? $city['city_id'] : '' );
			$city_name = isset( $city['name'] ) ? $city['name'] : ( isset( $city['city_name'] ) ? $city['city_name'] : '' );

			$city_entry = array(
				'id'     => $city_id,
				'name'   => $city_name,
				'type'   => 'city',
				'childs' => array(),
			);

			// Get thanas for this city.
			$thana_response = $this->remote_get(
				$this->api_url . 'cities/' . $city_id . '/thanas',
				$headers,
				30
			);

			if ( ! is_wp_error( $thana_response ) ) {
				$thanas = isset( $thana_response['data'] ) ? $thana_response['data'] : ( isset( $thana_response['thanas'] ) ? $thana_response['thanas'] : array() );

				foreach ( $thanas as $thana ) {
					$city_entry['childs'][] = array(
						'id'     => isset( $thana['id'] ) ? $thana['id'] : '',
						'name'   => isset( $thana['name'] ) ? $thana['name'] : ( isset( $thana['thana_name'] ) ? $thana['thana_name'] : '' ),
						'type'   => 'thana',
						'childs' => array(),
					);
				}
			}

			$areas[] = $city_entry;
		}

		return $areas;
	}

	/**
	 * Default areas when API is unavailable.
	 *
	 * @return array
	 */
	private function get_default_areas(): array {
		return array(
			array(
				'id'     => 'dhaka',
				'name'   => __( 'Dhaka', 'easy-order-manager' ),
				'type'   => 'city',
				'childs' => array(),
			),
			array(
				'id'     => 'chattogram',
				'name'   => __( 'Chattogram', 'easy-order-manager' ),
				'type'   => 'city',
				'childs' => array(),
			),
			array(
				'id'     => 'khulna',
				'name'   => __( 'Khulna', 'easy-order-manager' ),
				'type'   => 'city',
				'childs' => array(),
			),
			array(
				'id'     => 'sylhet',
				'name'   => __( 'Sylhet', 'easy-order-manager' ),
				'type'   => 'city',
				'childs' => array(),
			),
		);
	}

	/**
	 * Calculate eCourier delivery charge.
	 *
	 * @param float  $weight      Parcel weight in kg.
	 * @param string $destination Destination city/thana ID.
	 * @param float  $cod_amount  COD amount.
	 *
	 * @return float Estimated charge.
	 */
	public function get_charge( float $weight, string $destination, float $cod_amount ): float {
		if ( ! $this->ensure_authenticated() ) {
			return $this->calculate_charge_fallback( $weight, $cod_amount );
		}

		$url  = $this->api_url . 'pricing/calculate';
		$args = array(
			'headers' => $this->get_auth_headers(),
		);

		$body = array(
			'weight'     => max( 0.1, $weight ),
			'destination'=> $destination,
			'cod_amount' => max( 0, $cod_amount ),
		);

		$response = $this->remote_post( $url, $body, $args, 15 );

		if ( is_wp_error( $response ) ) {
			return $this->calculate_charge_fallback( $weight, $cod_amount );
		}

		if ( isset( $response['charge'] ) ) {
			return (float) $response['charge'];
		}
		if ( isset( $response['data']['charge'] ) ) {
			return (float) $response['data']['charge'];
		}
		if ( isset( $response['delivery_charge'] ) ) {
			return (float) $response['delivery_charge'];
		}

		return $this->calculate_charge_fallback( $weight, $cod_amount );
	}

	/**
	 * Fallback charge calculation.
	 *
	 * @param float $weight     Parcel weight.
	 * @param float $cod_amount COD amount.
	 *
	 * @return float
	 */
	private function calculate_charge_fallback( float $weight, float $cod_amount ): float {
		$base_charge = 65.0;
		$weight      = max( 0.1, $weight );

		if ( $weight > 0.5 ) {
			$extra_units  = ceil( ( $weight - 0.5 ) / 0.5 );
			$base_charge += $extra_units * 12.0;
		}

		if ( $cod_amount > 0 ) {
			$cod_fee = $cod_amount * 0.012;
			$base_charge += max( 12.0, $cod_fee );
		}

		return round( $base_charge, 2 );
	}

	/**
	 * Get public tracking URL for eCourier.
	 *
	 * @param string $tracking_id Tracking ID.
	 *
	 * @return string
	 */
	public function get_tracking_url( string $tracking_id ): string {
		return 'https://ecourier.com.bd/track/' . urlencode( $tracking_id );
	}
}
