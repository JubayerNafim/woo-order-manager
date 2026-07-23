<?php
/**
 * EOM Courier - Pathao
 *
 * Pathao Merchant API integration using OAuth2 authentication.
 * Handles parcel booking, tracking, cancellation, area retrieval,
 * and delivery charge calculation.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Courier_Pathao
 *
 * Integrates with the Pathao Merchant API (v1).
 * Uses OAuth2 client_credentials grant with additional username/password
 * for authentication. Stores tokens with expiry in wp_options.
 */
class EOM_Courier_Pathao extends EOM_Courier_Base {

	/**
	 * OAuth2 access token.
	 *
	 * @var string
	 */
	private $access_token = '';

	/**
	 * OAuth2 refresh token.
	 *
	 * @var string
	 */
	private $refresh_token = '';

	/**
	 * Token expiry timestamp.
	 *
	 * @var int
	 */
	private $token_expiry = 0;

	/**
	 * Merchant username (email) for Pathao.
	 *
	 * @var string
	 */
	private $username;

	/**
	 * Merchant password for Pathao.
	 *
	 * @var string
	 */
	private $password;

	/**
	 * Constructor.
	 *
	 * @param array $config Configuration array.
	 */
	public function __construct( array $config = array() ) {
		$this->slug = 'pathao';
		$this->name = __( 'Pathao', 'easy-order-manager' );

		parent::__construct( $config );

		$this->username = isset( $config['username'] ) ? $config['username'] : '';
		$this->password = isset( $config['password'] ) ? $config['password'] : '';
	}

	/**
	 * Get the live API URL.
	 *
	 * @return string
	 */
	protected function get_live_url(): string {
		return 'https://api-hermes.pathao.com/';
	}

	/**
	 * Get the sandbox API URL.
	 *
	 * @return string
	 */
	protected function get_sandbox_url(): string {
		return 'https://api-hermes.pathao.com/'; // Pathao uses same base, differentiate via credentials.
	}

	/**
	 * Check if the courier is configured with all required credentials.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return ! empty( $this->api_key )
			&& ! empty( $this->api_secret )
			&& ! empty( $this->username )
			&& ! empty( $this->password );
	}

	/**
	 * Authenticate with Pathao API and obtain OAuth2 token.
	 * Stores the token and its expiry in a transient.
	 *
	 * @return bool True if authentication succeeded.
	 */
	public function authenticate(): bool {
		// Check for existing valid token.
		$token_data = get_transient( 'eom_pathao_token' );
		if ( false !== $token_data && isset( $token_data['access_token'] ) ) {
			$this->access_token  = $token_data['access_token'];
			$this->refresh_token = isset( $token_data['refresh_token'] ) ? $token_data['refresh_token'] : '';
			$this->token_expiry  = isset( $token_data['expires_at'] ) ? $token_data['expires_at'] : 0;
			return true;
		}

		$url  = $this->api_url . 'aladdin/api/v1/issue-token';
		$body = array(
			'client_id'     => $this->api_key,
			'client_secret' => $this->api_secret,
			'username'      => $this->username,
			'password'      => $this->password,
			'grant_type'    => 'password',
		);

		$response = $this->remote_post( $url, $body, array(), 30 );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( isset( $response['access_token'] ) ) {
			$this->access_token  = $response['access_token'];
			$this->refresh_token = isset( $response['refresh_token'] ) ? $response['refresh_token'] : '';
			$expires_in          = isset( $response['expires_in'] ) ? (int) $response['expires_in'] : 3600;

			$this->token_expiry = time() + $expires_in;

			// Cache token for 90% of its lifetime.
			$cache_duration = (int) ( $expires_in * 0.9 );
			set_transient(
				'eom_pathao_token',
				array(
					'access_token'  => $this->access_token,
					'refresh_token' => $this->refresh_token,
					'expires_at'    => $this->token_expiry,
				),
				$cache_duration
			);

			return true;
		}

		return false;
	}

	/**
	 * Ensure a valid token exists, authenticating if necessary.
	 *
	 * @return bool
	 */
	private function ensure_authenticated(): bool {
		if ( ! empty( $this->access_token ) && time() < $this->token_expiry ) {
			return true;
		}
		return $this->authenticate();
	}

	/**
	 * Get the authorization headers for API requests.
	 *
	 * @return array Headers array with Bearer token.
	 */
	private function get_auth_headers(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->access_token,
		);
	}

	/**
	 * Book a parcel with Pathao.
	 *
	 * @param array $order_data {
	 *     Parcel booking data.
	 *
	 *     @type array  $recipient      Recipient info (name, phone, address).
	 *     @type string $recipient_name  Recipient full name.
	 *     @type string $recipient_phone Recipient phone number.
	 *     @type string $recipient_address Recipient full address.
	 *     @type int    $area_id        Pathao area ID.
	 *     @type int    $zone_id        Pathao zone ID (optional).
	 *     @type int    $store_id       Pathao store ID.
	 *     @type array  $items          Order items (name, quantity, price).
	 *     @type float  $cod_amount     Cash on delivery amount.
	 *     @type float  $total_weight   Total parcel weight.
	 *     @type string $special_instruction Special delivery instruction.
	 * }
	 *
	 * @return array Response with consignment_id, tracking info, etc.
	 */
	public function book_parcel( array $order_data ): array {
		if ( ! $this->ensure_authenticated() ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication failed with Pathao.', 'easy-order-manager' ),
			);
		}

		$url = $this->api_url . 'aladdin/api/v1/orders';

		// Map recipient fields.
		$recipient_name    = isset( $order_data['recipient_name'] ) ? $order_data['recipient_name'] : ( isset( $order_data['recipient']['name'] ) ? $order_data['recipient']['name'] : '' );
		$recipient_phone   = isset( $order_data['recipient_phone'] ) ? $order_data['recipient_phone'] : ( isset( $order_data['recipient']['phone'] ) ? $order_data['recipient']['phone'] : '' );
		$recipient_address = isset( $order_data['recipient_address'] ) ? $order_data['recipient_address'] : ( isset( $order_data['recipient']['address'] ) ? $order_data['recipient']['address'] : '' );

		$body = array(
			'store_id'            => isset( $order_data['store_id'] ) ? (int) $order_data['store_id'] : 0,
			'merchant_order_id'   => isset( $order_data['order_id'] ) ? (string) $order_data['order_id'] : '',
			'recipient_name'      => $recipient_name,
			'recipient_phone'     => $recipient_phone,
			'recipient_address'   => $recipient_address,
			'recipient_city'      => isset( $order_data['area_id'] ) ? (int) $order_data['area_id'] : 0,
			'recipient_zone'      => isset( $order_data['zone_id'] ) ? (int) $order_data['zone_id'] : 0,
			'recipient_area'      => isset( $order_data['area_id'] ) ? (int) $order_data['area_id'] : 0,
			'delivery_type'       => isset( $order_data['delivery_type'] ) ? (int) $order_data['delivery_type'] : 48,
			'item_type'           => isset( $order_data['item_type'] ) ? (int) $order_data['item_type'] : 2,
			'special_instruction' => isset( $order_data['special_instruction'] ) ? $order_data['special_instruction'] : '',
			'item_quantity'       => isset( $order_data['item_quantity'] ) ? (int) $order_data['item_quantity'] : 1,
			'item_weight'         => isset( $order_data['total_weight'] ) ? (float) $order_data['total_weight'] : 0.5,
			'amount_to_collect'   => isset( $order_data['cod_amount'] ) ? (float) $order_data['cod_amount'] : 0,
			'item_description'    => isset( $order_data['item_description'] ) ? $order_data['item_description'] : '',
		);

		$args = array(
			'headers' => $this->get_auth_headers(),
		);

		$response = $this->remote_post( $url, $body, $args, 30 );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		return array(
			'success'         => true,
			'consignment_id'  => isset( $response['data']['consignment_id'] ) ? $response['data']['consignment_id'] : ( isset( $response['id'] ) ? $response['id'] : '' ),
			'tracking_id'     => isset( $response['data']['tracking_code'] ) ? $response['data']['tracking_code'] : ( isset( $response['tracking_code'] ) ? $response['tracking_code'] : '' ),
			'tracking_url'    => $this->get_tracking_url(
				isset( $response['data']['consignment_id'] ) ? $response['data']['consignment_id'] : ''
			),
			'status'          => isset( $response['data']['order_status'] ) ? $response['data']['order_status'] : 'booked',
			'raw_response'    => $response,
		);
	}

	/**
	 * Track a Pathao parcel.
	 *
	 * @param string $tracking_id Consignment ID or tracking code.
	 *
	 * @return array Tracking data with status, timeline, etc.
	 */
	public function track_parcel( string $tracking_id ): array {
		if ( ! $this->ensure_authenticated() ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication failed with Pathao.', 'easy-order-manager' ),
			);
		}

		$url  = $this->api_url . 'aladdin/api/v1/orders/' . urlencode( $tracking_id ) . '/tracking';
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
			'success'           => true,
			'tracking_id'       => $tracking_id,
			'status'            => isset( $data['order_status'] ) ? $data['order_status'] : ( isset( $data['status'] ) ? $data['status'] : 'unknown' ),
			'status_label'      => isset( $data['order_status_label'] ) ? $data['order_status_label'] : '',
			'current_location'  => isset( $data['current_location'] ) ? $data['current_location'] : '',
			'last_update'       => isset( $data['updated_at'] ) ? $data['updated_at'] : '',
			'timeline'          => isset( $data['tracking_logs'] ) ? $data['tracking_logs'] : array(),
			'raw_response'      => $response,
		);
	}

	/**
	 * Cancel a Pathao parcel.
	 *
	 * @param string $tracking_id Consignment ID to cancel.
	 *
	 * @return array Cancellation response.
	 */
	public function cancel_parcel( string $tracking_id ): array {
		if ( ! $this->ensure_authenticated() ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication failed with Pathao.', 'easy-order-manager' ),
			);
		}

		$url  = $this->api_url . 'aladdin/api/v1/orders/' . urlencode( $tracking_id ) . '/cancel';
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
	 * Fetch Pathao delivery areas (cities, zones, areas hierarchy).
	 *
	 * Uses the /cities, /zones, and /areas endpoints to build a
	 * hierarchical list.
	 *
	 * @return array Hierarchical area data.
	 */
	public function get_areas(): array {
		if ( ! $this->ensure_authenticated() ) {
			return array();
		}

		$headers = array( 'headers' => $this->get_auth_headers() );
		$areas   = array();

		// Get cities.
		$cities_response = $this->remote_get( $this->api_url . 'aladdin/api/v1/cities', $headers, 30 );
		if ( is_wp_error( $cities_response ) ) {
			return array();
		}

		$cities = isset( $cities_response['data']['data'] ) ? $cities_response['data']['data'] : ( isset( $cities_response['data'] ) ? $cities_response['data'] : array() );

		foreach ( $cities as $city ) {
			$city_id   = isset( $city['id'] ) ? (int) $city['id'] : 0;
			$city_name = isset( $city['city_name'] ) ? $city['city_name'] : ( isset( $city['name'] ) ? $city['name'] : '' );

			$city_entry = array(
				'id'     => $city_id,
				'name'   => $city_name,
				'type'   => 'city',
				'childs' => array(),
			);

			// Get zones for this city.
			$zones_response = $this->remote_get(
				$this->api_url . 'aladdin/api/v1/cities/' . $city_id . '/zone-list',
				$headers,
				30
			);

			if ( ! is_wp_error( $zones_response ) ) {
				$zones = isset( $zones_response['data']['data'] ) ? $zones_response['data']['data'] : ( isset( $zones_response['data'] ) ? $zones_response['data'] : array() );

				foreach ( $zones as $zone ) {
					$zone_id   = isset( $zone['id'] ) ? (int) $zone['id'] : 0;
					$zone_name = isset( $zone['zone_name'] ) ? $zone['zone_name'] : ( isset( $zone['name'] ) ? $zone['name'] : '' );

					$zone_entry = array(
						'id'     => $zone_id,
						'name'   => $zone_name,
						'type'   => 'zone',
						'childs' => array(),
					);

					// Get areas for this zone.
					$areas_response = $this->remote_get(
						$this->api_url . 'aladdin/api/v1/zones/' . $zone_id . '/area-list',
						$headers,
						30
					);

					if ( ! is_wp_error( $areas_response ) ) {
						$area_list = isset( $areas_response['data']['data'] ) ? $areas_response['data']['data'] : ( isset( $areas_response['data'] ) ? $areas_response['data'] : array() );

						foreach ( $area_list as $area ) {
							$zone_entry['childs'][] = array(
								'id'   => isset( $area['id'] ) ? (int) $area['id'] : 0,
								'name' => isset( $area['area_name'] ) ? $area['area_name'] : ( isset( $area['name'] ) ? $area['name'] : '' ),
								'type' => 'area',
							);
						}
					}

					$city_entry['childs'][] = $zone_entry;
				}
			}

			$areas[] = $city_entry;
		}

		return $areas;
	}

	/**
	 * Calculate Pathao delivery charge.
	 *
	 * Pathao pricing is based on weight, delivery area category
	 * (inside Dhaka / outside Dhaka), and COD amount.
	 *
	 * @param float  $weight      Parcel weight in kg.
	 * @param string $destination Destination area identifier.
	 * @param float  $cod_amount  Cash on delivery amount.
	 *
	 * @return float Estimated delivery charge.
	 */
	public function get_charge( float $weight, string $destination, float $cod_amount ): float {
		if ( ! $this->ensure_authenticated() ) {
			return 0.0;
		}

		$url  = $this->api_url . 'aladdin/api/v1/merchant/price-calculation';
		$args = array(
			'headers' => $this->get_auth_headers(),
		);

		$body = array(
			'sender_city'     => 1, // Default to Dhaka.
			'recipient_city'  => (int) $destination,
			'item_type'       => 2,
			'item_weight'     => max( 0.1, $weight ),
			'amount_to_collect' => max( 0, $cod_amount ),
		);

		$response = $this->remote_post( $url, $body, $args, 30 );

		if ( is_wp_error( $response ) ) {
			return $this->calculate_charge_fallback( $weight, $cod_amount );
		}

		if ( isset( $response['data']['price'] ) ) {
			return (float) $response['data']['price'];
		}

		return $this->calculate_charge_fallback( $weight, $cod_amount );
	}

	/**
	 * Fallback calculation when API is unavailable.
	 *
	 * @param float $weight     Parcel weight.
	 * @param float $cod_amount COD amount.
	 *
	 * @return float Estimated charge.
	 */
	private function calculate_charge_fallback( float $weight, float $cod_amount ): float {
		$base_charge = 60.0; // Inside Dhaka starting.
		if ( $weight > 0.5 ) {
			$base_charge += ceil( ( $weight - 0.5 ) / 0.5 ) * 10;
		}
		if ( $cod_amount > 0 ) {
			$cod_fee = $cod_amount * 0.012; // 1.2% COD fee.
			$base_charge += max( 10, $cod_fee );
		}
		return $base_charge;
	}

	/**
	 * Get public tracking URL for Pathao.
	 *
	 * @param string $tracking_id Consignment ID or tracking code.
	 *
	 * @return string
	 */
	public function get_tracking_url( string $tracking_id ): string {
		return 'https://pathao.com/track/' . urlencode( $tracking_id );
	}

	/**
	 * Get merchant store information.
	 *
	 * @return array Store info including store_id, name, address, etc.
	 */
	public function get_store_info(): array {
		if ( ! $this->ensure_authenticated() ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication failed with Pathao.', 'easy-order-manager' ),
			);
		}

		$url  = $this->api_url . 'aladdin/api/v1/merchant/store';
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

		return array(
			'success'      => true,
			'store_id'     => isset( $response['data']['id'] ) ? $response['data']['id'] : '',
			'store_name'   => isset( $response['data']['store_name'] ) ? $response['data']['store_name'] : '',
			'store_phone'  => isset( $response['data']['store_phone'] ) ? $response['data']['store_phone'] : '',
			'store_address'=> isset( $response['data']['store_address'] ) ? $response['data']['store_address'] : '',
			'raw_response' => $response,
		);
	}
}
