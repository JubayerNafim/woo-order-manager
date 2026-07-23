<?php
/**
 * EOM Courier - Paperfly
 *
 * Paperfly Courier API integration.
 * Supports parcel booking, tracking, cancellation, area retrieval,
 * and delivery charge calculation.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Courier_Paperfly
 *
 * Integrates with the Paperfly Courier API.
 * Uses API key and secret key authentication.
 */
class EOM_Courier_Paperfly extends EOM_Courier_Base {

	/**
	 * Constructor.
	 *
	 * @param array $config Configuration array.
	 */
	public function __construct( array $config = array() ) {
		$this->slug = 'paperfly';
		$this->name = __( 'Paperfly', 'easy-order-manager' );
		parent::__construct( $config );
	}

	/**
	 * Get live API URL.
	 *
	 * @return string
	 */
	protected function get_live_url(): string {
		return 'https://api.paperfly.com.bd/v1/';
	}

	/**
	 * Get sandbox API URL.
	 *
	 * @return string
	 */
	protected function get_sandbox_url(): string {
		return 'https://sandbox.paperfly.com.bd/v1/';
	}

	/**
	 * Get authentication headers.
	 *
	 * @return array
	 */
	private function get_auth_headers(): array {
		return array(
			'X-Api-Key'    => $this->api_key,
			'X-Api-Secret' => $this->api_secret,
		);
	}

	/**
	 * Book a parcel with Paperfly.
	 *
	 * @param array $order_data {
	 *     Parcel booking data.
	 *
	 *     @type string $recipient_name     Recipient full name.
	 *     @type string $recipient_phone    Recipient phone number.
	 *     @type string $recipient_address  Recipient full address.
	 *     @type string $recipient_city     Recipient city.
	 *     @type string $recipient_area     Recipient area.
	 *     @type float  $cod_amount         Cash on delivery amount.
	 *     @type float  $total_weight       Parcel weight in kg.
	 *     @type string $item_description   Item description.
	 *     @type int    $item_quantity      Number of items.
	 * }
	 *
	 * @return array Booking response.
	 */
	public function book_parcel( array $order_data ): array {
		$url  = $this->api_url . 'parcels';
		$args = array(
			'headers' => $this->get_auth_headers(),
		);

		$recipient_name    = isset( $order_data['recipient_name'] ) ? $order_data['recipient_name'] : ( isset( $order_data['recipient']['name'] ) ? $order_data['recipient']['name'] : '' );
		$recipient_phone   = isset( $order_data['recipient_phone'] ) ? $order_data['recipient_phone'] : ( isset( $order_data['recipient']['phone'] ) ? $order_data['recipient']['phone'] : '' );
		$recipient_address = isset( $order_data['recipient_address'] ) ? $order_data['recipient_address'] : ( isset( $order_data['recipient']['address'] ) ? $order_data['recipient']['address'] : '' );

		$body = array(
			'reference'          => isset( $order_data['order_id'] ) ? (string) $order_data['order_id'] : '',
			'customer_name'      => $recipient_name,
			'customer_mobile'    => $recipient_phone,
			'customer_address'   => $recipient_address,
			'customer_city'      => isset( $order_data['recipient_city'] ) ? $order_data['recipient_city'] : '',
			'customer_area'      => isset( $order_data['recipient_area'] ) ? $order_data['recipient_area'] : '',
			'cod_amount'         => isset( $order_data['cod_amount'] ) ? (float) $order_data['cod_amount'] : 0,
			'product_weight'     => isset( $order_data['total_weight'] ) ? (float) $order_data['total_weight'] : 0.5,
			'product_description'=> isset( $order_data['item_description'] ) ? $order_data['item_description'] : '',
			'product_quantity'   => isset( $order_data['item_quantity'] ) ? (int) $order_data['item_quantity'] : 1,
			'pickup_location'    => isset( $order_data['pickup_location'] ) ? $order_data['pickup_location'] : '',
			'note'               => isset( $order_data['special_instruction'] ) ? $order_data['special_instruction'] : '',
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
			'parcel_id'    => isset( $response['id'] ) ? $response['id'] : ( isset( $response['data']['id'] ) ? $response['data']['id'] : '' ),
			'tracking_url' => $this->get_tracking_url(
				isset( $response['tracking_id'] ) ? $response['tracking_id'] : ''
			),
			'status'       => isset( $response['status'] ) ? $response['status'] : 'booked',
			'charge'       => isset( $response['delivery_charge'] ) ? (float) $response['delivery_charge'] : 0,
			'raw_response' => $response,
		);
	}

	/**
	 * Track a Paperfly parcel.
	 *
	 * @param string $tracking_id Tracking ID.
	 *
	 * @return array Tracking data.
	 */
	public function track_parcel( string $tracking_id ): array {
		$url  = $this->api_url . 'parcels/' . urlencode( $tracking_id ) . '/track';
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
			'status_label'     => isset( $data['status_text'] ) ? $data['status_text'] : '',
			'current_location' => isset( $data['location'] ) ? $data['location'] : '',
			'last_update'      => isset( $data['updated_at'] ) ? $data['updated_at'] : '',
			'timeline'         => isset( $data['tracking_log'] ) ? $data['tracking_log'] : array(),
			'raw_response'     => $response,
		);
	}

	/**
	 * Cancel a Paperfly parcel.
	 *
	 * @param string $tracking_id Tracking ID.
	 *
	 * @return array Cancellation response.
	 */
	public function cancel_parcel( string $tracking_id ): array {
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
	 * Get Paperfly service areas.
	 *
	 * @return array Area list.
	 */
	public function get_areas(): array {
		$url  = $this->api_url . 'service-areas';
		$args = array(
			'headers' => $this->get_auth_headers(),
		);

		$response = $this->remote_get( $url, $args, 30 );

		if ( is_wp_error( $response ) ) {
			return $this->get_default_areas();
		}

		$data  = isset( $response['data'] ) ? $response['data'] : $response;
		$areas = array();

		if ( is_array( $data ) ) {
			foreach ( $data as $item ) {
				$areas[] = array(
					'id'     => isset( $item['id'] ) ? $item['id'] : '',
					'name'   => isset( $item['name'] ) ? $item['name'] : ( isset( $item['area_name'] ) ? $item['area_name'] : '' ),
					'type'   => isset( $item['type'] ) ? $item['type'] : 'area',
					'childs' => array(),
				);
			}
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
				'id'     => 'rajshahi',
				'name'   => __( 'Rajshahi', 'easy-order-manager' ),
				'type'   => 'city',
				'childs' => array(),
			),
			array(
				'id'     => 'sylhet',
				'name'   => __( 'Sylhet', 'easy-order-manager' ),
				'type'   => 'city',
				'childs' => array(),
			),
			array(
				'id'     => 'nationwide',
				'name'   => __( 'Nationwide', 'easy-order-manager' ),
				'type'   => 'country',
				'childs' => array(),
			),
		);
	}

	/**
	 * Calculate Paperfly delivery charge.
	 *
	 * @param float  $weight      Parcel weight in kg.
	 * @param string $destination Destination area/city ID.
	 * @param float  $cod_amount  Cash on delivery amount.
	 *
	 * @return float Estimated charge.
	 */
	public function get_charge( float $weight, string $destination, float $cod_amount ): float {
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
			return $this->calculate_charge_fallback( $weight, $destination, $cod_amount );
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

		return $this->calculate_charge_fallback( $weight, $destination, $cod_amount );
	}

	/**
	 * Fallback charge calculation.
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
			$base_charge = 100.0;
			$extra_rate  = 15.0;
		}

		$weight = max( 0.1, $weight );
		if ( $weight > 0.5 ) {
			$extra_units  = ceil( ( $weight - 0.5 ) / 0.5 );
			$base_charge += $extra_units * $extra_rate;
		}

		if ( $cod_amount > 0 ) {
			$cod_fee = $cod_amount * 0.01;
			$base_charge += max( 10.0, $cod_fee );
		}

		return round( $base_charge, 2 );
	}

	/**
	 * Get public tracking URL for Paperfly.
	 *
	 * @param string $tracking_id Tracking ID.
	 *
	 * @return string
	 */
	public function get_tracking_url( string $tracking_id ): string {
		return 'https://paperfly.com.bd/tracking/' . urlencode( $tracking_id );
	}
}
