<?php
/**
 * EOM Courier - Sundarban Courier Service (SCS)
 *
 * Sundarban Courier Service Bangladesh integration.
 * Since Sundarban does not provide a public REST API, this module
 * provides a structured manual/structured integration with simulated
 * API endpoints for order management.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Courier_Sundarban
 *
 * Sundarban Courier Service integration.
 * Uses structured data management for areas and pricing with
 * support for manual booking and tracking workflows.
 */
class EOM_Courier_Sundarban extends EOM_Courier_Base {

	/**
	 * Branch code for pickup.
	 *
	 * @var string
	 */
	private $branch_code;

	/**
	 * Constructor.
	 *
	 * @param array $config Configuration array.
	 */
	public function __construct( array $config = array() ) {
		$this->slug = 'sundarban';
		$this->name = __( 'Sundarban Courier', 'easy-order-manager' );
		parent::__construct( $config );

		$this->branch_code = isset( $config['branch_code'] ) ? $config['branch_code'] : '';
	}

	/**
	 * Get the live API URL (if available).
	 *
	 * @return string
	 */
	protected function get_live_url(): string {
		return 'https://portal.scs.com.bd/api/';
	}

	/**
	 * Get sandbox URL.
	 *
	 * @return string
	 */
	protected function get_sandbox_url(): string {
		return 'https://portal.scs.com.bd/api/';
	}

	/**
	 * Get authentication headers.
	 *
	 * @return array
	 */
	private function get_auth_headers(): array {
		$headers = array(
			'API-Key' => $this->api_key,
		);
		if ( ! empty( $this->api_secret ) ) {
			$headers['API-Secret'] = $this->api_secret;
		}
		if ( ! empty( $this->branch_code ) ) {
			$headers['X-Branch-Code'] = $this->branch_code;
		}
		return $headers;
	}

	/**
	 * Book a parcel with Sundarban Courier.
	 *
	 * @param array $order_data {
	 *     Parcel booking data.
	 *
	 *     @type string $recipient_name     Recipient full name.
	 *     @type string $recipient_phone    Recipient phone number.
	 *     @type string $recipient_address  Recipient full address.
	 *     @type float  $cod_amount         Cash on delivery amount.
	 *     @type float  $total_weight       Parcel weight in kg.
	 *     @type string $product_type       Document or parcel.
	 *     @type string $special_instruction Delivery note.
	 * }
	 *
	 * @return array Booking response.
	 */
	public function book_parcel( array $order_data ): array {
		$url  = $this->api_url . 'orders/create';
		$args = array(
			'headers' => $this->get_auth_headers(),
		);

		$recipient_name    = isset( $order_data['recipient_name'] ) ? $order_data['recipient_name'] : ( isset( $order_data['recipient']['name'] ) ? $order_data['recipient']['name'] : '' );
		$recipient_phone   = isset( $order_data['recipient_phone'] ) ? $order_data['recipient_phone'] : ( isset( $order_data['recipient']['phone'] ) ? $order_data['recipient']['phone'] : '' );
		$recipient_address = isset( $order_data['recipient_address'] ) ? $order_data['recipient_address'] : ( isset( $order_data['recipient']['address'] ) ? $order_data['recipient']['address'] : '' );

		$body = array(
			'reference'          => isset( $order_data['order_id'] ) ? (string) $order_data['order_id'] : '',
			'recipient_name'     => $recipient_name,
			'recipient_mobile'   => $recipient_phone,
			'recipient_address'  => $recipient_address,
			'recipient_district' => isset( $order_data['recipient_district'] ) ? $order_data['recipient_district'] : '',
			'recipient_area'     => isset( $order_data['recipient_area'] ) ? $order_data['recipient_area'] : '',
			'cod_amount'         => isset( $order_data['cod_amount'] ) ? (float) $order_data['cod_amount'] : 0,
			'declared_value'     => isset( $order_data['declared_value'] ) ? (float) $order_data['declared_value'] : 0,
			'weight'             => isset( $order_data['total_weight'] ) ? (float) $order_data['total_weight'] : 0.5,
			'product_type'       => isset( $order_data['product_type'] ) ? $order_data['product_type'] : 'parcel',
			'quantity'           => isset( $order_data['item_quantity'] ) ? (int) $order_data['item_quantity'] : 1,
			'description'        => isset( $order_data['item_description'] ) ? $order_data['item_description'] : '',
			'pickup_branch'      => $this->branch_code,
			'remarks'            => isset( $order_data['special_instruction'] ) ? $order_data['special_instruction'] : '',
		);

		$response = $this->remote_post( $url, $body, $args, 30 );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		return array(
			'success'           => true,
			'tracking_id'       => isset( $response['tracking_id'] ) ? $response['tracking_id'] : ( isset( $response['data']['tracking_id'] ) ? $response['data']['tracking_id'] : '' ),
			'consignment_no'    => isset( $response['consignment_no'] ) ? $response['consignment_no'] : '',
			'tracking_url'      => $this->get_tracking_url(
				isset( $response['tracking_id'] ) ? $response['tracking_id'] : ''
			),
			'status'            => isset( $response['status'] ) ? $response['status'] : 'booked',
			'estimated_charge'  => isset( $response['delivery_charge'] ) ? (float) $response['delivery_charge'] : 0,
			'raw_response'      => $response,
		);
	}

	/**
	 * Track a Sundarban parcel.
	 *
	 * @param string $tracking_id Tracking/consignment number.
	 *
	 * @return array Tracking data.
	 */
	public function track_parcel( string $tracking_id ): array {
		$url  = $this->api_url . 'orders/' . urlencode( $tracking_id ) . '/track';
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
			'current_branch'   => isset( $data['current_branch'] ) ? $data['current_branch'] : '',
			'last_update'      => isset( $data['updated_at'] ) ? $data['updated_at'] : '',
			'timeline'         => isset( $data['tracking_events'] ) ? $data['tracking_events'] : array(),
			'raw_response'     => $response,
		);
	}

	/**
	 * Cancel a Sundarban parcel.
	 *
	 * @param string $tracking_id Tracking/consignment number.
	 *
	 * @return array Cancellation response.
	 */
	public function cancel_parcel( string $tracking_id ): array {
		$url  = $this->api_url . 'orders/' . urlencode( $tracking_id ) . '/cancel';
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
	 * Get Sundarban service areas.
	 *
	 * Sundarban covers all 64 districts of Bangladesh.
	 * Returns predefined district list.
	 *
	 * @return array District/area list.
	 */
	public function get_areas(): array {
		/**
		 * Filter Sundarban delivery areas.
		 *
		 * @param array $areas Default district list.
		 */
		return apply_filters( 'eom_sundarban_areas', $this->get_districts() );
	}

	/**
	 * Get list of Bangladesh districts.
	 *
	 * @return array
	 */
	private function get_districts(): array {
		$districts = array(
			'barishal'     => __( 'Barishal', 'easy-order-manager' ),
			'barguna'      => __( 'Barguna', 'easy-order-manager' ),
			'bhola'        => __( 'Bhola', 'easy-order-manager' ),
			'jhalokati'    => __( 'Jhalokati', 'easy-order-manager' ),
			'patuakhali'   => __( 'Patuakhali', 'easy-order-manager' ),
			'pirojpur'     => __( 'Pirojpur', 'easy-order-manager' ),
			'chattogram'   => __( 'Chattogram', 'easy-order-manager' ),
			'bandarban'    => __( 'Bandarban', 'easy-order-manager' ),
			'b-baria'      => __( 'Brahmanbaria', 'easy-order-manager' ),
			'chandpur'     => __( 'Chandpur', 'easy-order-manager' ),
			'comilla'      => __( 'Comilla', 'easy-order-manager' ),
			'cox-bazar'    => __( 'Cox\'s Bazar', 'easy-order-manager' ),
			'feni'         => __( 'Feni', 'easy-order-manager' ),
			'khagrachari'  => __( 'Khagrachari', 'easy-order-manager' ),
			'lakshmipur'   => __( 'Lakshmipur', 'easy-order-manager' ),
			'noakhali'     => __( 'Noakhali', 'easy-order-manager' ),
			'rangamati'    => __( 'Rangamati', 'easy-order-manager' ),
			'dhaka'        => __( 'Dhaka', 'easy-order-manager' ),
			'faridpur'     => __( 'Faridpur', 'easy-order-manager' ),
			'gazipur'      => __( 'Gazipur', 'easy-order-manager' ),
			'gopalganj'    => __( 'Gopalganj', 'easy-order-manager' ),
			'kishoreganj'  => __( 'Kishoreganj', 'easy-order-manager' ),
			'madaripur'    => __( 'Madaripur', 'easy-order-manager' ),
			'manikganj'    => __( 'Manikganj', 'easy-order-manager' ),
			'munshiganj'   => __( 'Munshiganj', 'easy-order-manager' ),
			'narayanganj'  => __( 'Narayanganj', 'easy-order-manager' ),
			'narsingdi'    => __( 'Narsingdi', 'easy-order-manager' ),
			'rajbari'      => __( 'Rajbari', 'easy-order-manager' ),
			'shariatpur'   => __( 'Shariatpur', 'easy-order-manager' ),
			'tangail'      => __( 'Tangail', 'easy-order-manager' ),
			'khulna'       => __( 'Khulna', 'easy-order-manager' ),
			'bagehat'      => __( 'Bagerhat', 'easy-order-manager' ),
			'chuadanga'    => __( 'Chuadanga', 'easy-order-manager' ),
			'jashore'      => __( 'Jashore', 'easy-order-manager' ),
			'jhenaidah'    => __( 'Jhenaidah', 'easy-order-manager' ),
			'kushtia'      => __( 'Kushtia', 'easy-order-manager' ),
			'magura'       => __( 'Magura', 'easy-order-manager' ),
			'meherpur'     => __( 'Meherpur', 'easy-order-manager' ),
			'narail'       => __( 'Narail', 'easy-order-manager' ),
			'satkhira'     => __( 'Satkhira', 'easy-order-manager' ),
			'rajshahi'     => __( 'Rajshahi', 'easy-order-manager' ),
			'bagura'       => __( 'Bogura', 'easy-order-manager' ),
			'chapainawabganj' => __( 'Chapainawabganj', 'easy-order-manager' ),
			'joypurhat'    => __( 'Joypurhat', 'easy-order-manager' ),
			'naogaon'      => __( 'Naogaon', 'easy-order-manager' ),
			'natore'       => __( 'Natore', 'easy-order-manager' ),
			'nawabganj'    => __( 'Nawabganj', 'easy-order-manager' ),
			'pabna'        => __( 'Pabna', 'easy-order-manager' ),
			'sirajganj'    => __( 'Sirajganj', 'easy-order-manager' ),
			'rangpur'      => __( 'Rangpur', 'easy-order-manager' ),
			'dinajpur'     => __( 'Dinajpur', 'easy-order-manager' ),
			'gaibandha'    => __( 'Gaibandha', 'easy-order-manager' ),
			'kurigram'     => __( 'Kurigram', 'easy-order-manager' ),
			'lalmonirhat'  => __( 'Lalmonirhat', 'easy-order-manager' ),
			'nilphamari'   => __( 'Nilphamari', 'easy-order-manager' ),
			'panchagarh'   => __( 'Panchagarh', 'easy-order-manager' ),
			'thakurgaon'   => __( 'Thakurgaon', 'easy-order-manager' ),
			'sylhet'       => __( 'Sylhet', 'easy-order-manager' ),
			'habiganj'     => __( 'Habiganj', 'easy-order-manager' ),
			'moulvibazar'  => __( 'Moulvibazar', 'easy-order-manager' ),
			'sunamganj'    => __( 'Sunamganj', 'easy-order-manager' ),
			'mymensingh'   => __( 'Mymensingh', 'easy-order-manager' ),
			'jamalpur'     => __( 'Jamalpur', 'easy-order-manager' ),
			'netrokona'    => __( 'Netrokona', 'easy-order-manager' ),
			'sherpur'      => __( 'Sherpur', 'easy-order-manager' ),
		);

		$area_list = array();
		foreach ( $districts as $slug => $name ) {
			$area_list[] = array(
				'id'     => $slug,
				'name'   => $name,
				'type'   => 'district',
				'childs' => array(),
			);
		}

		return $area_list;
	}

	/**
	 * Calculate Sundarban delivery charge.
	 *
	 * Sundarban pricing structure:
	 * - Inside Dhaka: 70 BDT first kg, +10 BDT per additional kg
	 * - Outside Dhaka: 120 BDT first kg, +20 BDT per additional kg
	 * - COD fee: 1.2% (min 15 BDT)
	 *
	 * @param float  $weight      Parcel weight in kg.
	 * @param string $destination Destination district.
	 * @param float  $cod_amount  Cash on delivery amount.
	 *
	 * @return float Estimated charge.
	 */
	public function get_charge( float $weight, string $destination, float $cod_amount ): float {
		$inside_dhaka = ( 'dhaka' === $destination );

		if ( $inside_dhaka ) {
			$base_charge = 70.0;
			$extra_rate  = 10.0;
		} else {
			$base_charge = 120.0;
			$extra_rate  = 20.0;
		}

		$weight = max( 0.1, $weight );
		if ( $weight > 1.0 ) {
			$extra_kg    = ceil( $weight - 1.0 );
			$base_charge += $extra_kg * $extra_rate;
		}

		if ( $cod_amount > 0 ) {
			$cod_fee = $cod_amount * 0.012;
			$base_charge += max( 15.0, $cod_fee );
		}

		return round( $base_charge, 2 );
	}

	/**
	 * Get tracking URL for Sundarban.
	 *
	 * @param string $tracking_id Tracking/consignment number.
	 *
	 * @return string
	 */
	public function get_tracking_url( string $tracking_id ): string {
		return 'https://scs.com.bd/tracking/' . urlencode( $tracking_id );
	}
}
