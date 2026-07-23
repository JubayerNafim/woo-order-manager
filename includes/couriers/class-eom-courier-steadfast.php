<?php
/**
 * EOM Courier - Steadfast
 *
 * Steadfast Courier API integration using API key + secret key authentication.
 * Supports parcel booking, tracking, cancellation, balance check,
 * delivery charge calculation, and multiple merchant accounts.
 *
 * API Reference: https://docs.google.com/document/d/e/2PACX-1vTi0sTyR353xu1AK0nR8E_WKe5onCkUXGEf8ch8uoJy9qxGfgGnboSIkNosjQ0OOdXkJhgGuAsWxnIh/pub
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Courier_Steadfast
 *
 * Integrates with Steadfast Courier's REST API.
 * Authentication is via API key and secret key passed as headers.
 * Supports multiple merchant accounts for different warehouse locations.
 *
 * Base URL: https://portal.packzy.com/api/v1
 */
class EOM_Courier_Steadfast extends EOM_Courier_Base {

	/**
	 * Option key where merchant accounts are stored.
	 */
	const MERCHANTS_OPTION = 'eom_courier_steadfast_merchants';

	/**
	 * Default merchant ID (uses main credentials).
	 */
	const DEFAULT_MERCHANT = 'default';

	/**
	 * Currently active merchant ID (for the current booking).
	 *
	 * @var string
	 */
	protected $active_merchant = 'default';

	/**
	 * Temporary override credentials when a merchant is selected.
	 *
	 * @var array|null
	 */
	protected $merchant_override = null;

	/**
	 * Constructor.
	 *
	 * @param array $config Configuration array.
	 */
	public function __construct( array $config = array() ) {
		$this->slug = 'steadfast';
		$this->name = __( 'Steadfast Courier', 'easy-order-manager' );
		parent::__construct( $config );
	}

	/**
	 * Get live API URL.
	 *
	 * @return string
	 */
	protected function get_live_url(): string {
		return 'https://portal.packzy.com/api/v1/';
	}

	/**
	 * Get sandbox API URL (uses same as live for Steadfast).
	 *
	 * @return string
	 */
	protected function get_sandbox_url(): string {
		return 'https://portal.packzy.com/api/v1/';
	}

	/**
	 * Check if the courier is configured (has default credentials or any merchant).
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! empty( $this->api_key ) && ! empty( $this->api_secret ) ) {
			return true;
		}

		$merchants = self::get_merchant_accounts();
		if ( empty( $merchants ) ) {
			return false;
		}

		// Verify at least one merchant has complete credentials.
		foreach ( $merchants as $merchant ) {
			if ( ! empty( $merchant['api_key'] ) && ! empty( $merchant['api_secret'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get all registered merchant accounts.
	 *
	 * @return array Associative array keyed by merchant ID.
	 */
	public static function get_merchant_accounts(): array {
		$accounts = get_option( self::MERCHANTS_OPTION, array() );
		if ( ! is_array( $accounts ) ) {
			return array();
		}
		return $accounts;
	}

	/**
	 * Save merchant accounts.
	 *
	 * @param array $accounts Array of merchant accounts.
	 * @return bool
	 */
	public static function save_merchant_accounts( array $accounts ): bool {
		return update_option( self::MERCHANTS_OPTION, $accounts );
	}

	/**
	 * Get a specific merchant account by ID.
	 *
	 * @param string $merchant_id Merchant account ID.
	 * @return array|null Account data or null if not found.
	 */
	public static function get_merchant( string $merchant_id ): ?array {
		$accounts = self::get_merchant_accounts();
		return isset( $accounts[ $merchant_id ] ) ? $accounts[ $merchant_id ] : null;
	}

	/**
	 * Set the active merchant for the next API call.
	 *
	 * Temporarily overrides api_key and api_secret with the
	 * selected merchant's credentials. Call reset_merchant()
	 * after the API call to restore defaults.
	 *
	 * @param string $merchant_id Merchant account ID or 'default' for main credentials.
	 * @return bool True if merchant was found and set.
	 */
	public function set_merchant( string $merchant_id ): bool {
		$this->merchant_override = null;

		if ( 'default' === $merchant_id || empty( $merchant_id ) ) {
			$this->active_merchant = 'default';
			return true;
		}

		$merchant = self::get_merchant( $merchant_id );
		if ( ! $merchant || empty( $merchant['api_key'] ) || empty( $merchant['api_secret'] ) ) {
			return false;
		}

		$this->active_merchant   = $merchant_id;
		$this->merchant_override = $merchant;
		return true;
	}

	/**
	 * Reset to default merchant credentials.
	 *
	 * @return void
	 */
	public function reset_merchant(): void {
		$this->active_merchant   = 'default';
		$this->merchant_override = null;
	}

	/**
	 * Get the currently active merchant label.
	 *
	 * @return string
	 */
	public function get_active_merchant_label(): string {
		if ( 'default' === $this->active_merchant || ! $this->merchant_override ) {
			return __( 'Default Account', 'easy-order-manager' );
		}
		return isset( $this->merchant_override['label'] ) ? $this->merchant_override['label'] : $this->active_merchant;
	}

	/**
	 * Get authentication headers for Steadfast API.
	 *
	 * Uses the currently active merchant credentials if set,
	 * otherwise falls back to the default credentials.
	 *
	 * @return array Headers with Api-Key and Secret-Key.
	 */
	private function get_auth_headers(): array {
		if ( $this->merchant_override ) {
			return array(
				'Api-Key'    => $this->merchant_override['api_key'],
				'Secret-Key' => $this->merchant_override['api_secret'],
			);
		}

		return array(
			'Api-Key'    => $this->api_key,
			'Secret-Key' => $this->api_secret,
		);
	}

	/**
	 * Book a parcel with Steadfast Courier.
	 *
	 * Official endpoint: POST /create_order
	 * Response nests consignment data under a 'consignment' key.
	 *
	 * @param array $order_data {
	 *     Parcel booking data.
	 *
	 *     @type string $merchant_id        Optional. Merchant account ID to use.
	 *     @type string $recipient_name     Recipient full name.
	 *     @type string $recipient_phone    Recipient phone number.
	 *     @type string $recipient_address  Recipient full address.
	 *     @type string $recipient_city     Recipient city.
	 *     @type string $recipient_area     Recipient area/zone (optional).
	 *     @type float  $cod_amount         Cash on delivery amount.
	 *     @type float  $total_weight       Parcel weight in kg.
	 *     @type int    $item_quantity      Number of items.
	 *     @type string $item_description   Item description.
	 *     @type string $special_instruction Delivery note.
	 * }
	 *
	 * @return array Booking response with tracking info.
	 */
	public function book_parcel( array $order_data ): array {
		// Switch to the requested merchant if specified.
		if ( ! empty( $order_data['merchant_id'] ) && 'default' !== $order_data['merchant_id'] ) {
			$this->set_merchant( $order_data['merchant_id'] );
		}

		try {
		$url  = $this->api_url . 'create_order';
		$args = array(
			'headers' => $this->get_auth_headers(),
		);

		$recipient_name    = isset( $order_data['recipient_name'] ) ? $order_data['recipient_name'] : ( isset( $order_data['recipient']['name'] ) ? $order_data['recipient']['name'] : '' );
		$recipient_phone   = isset( $order_data['recipient_phone'] ) ? $order_data['recipient_phone'] : ( isset( $order_data['recipient']['phone'] ) ? $order_data['recipient']['phone'] : '' );
		$recipient_address = isset( $order_data['recipient_address'] ) ? $order_data['recipient_address'] : ( isset( $order_data['recipient']['address'] ) ? $order_data['recipient']['address'] : '' );

		$body = array(
			'invoice'           => isset( $order_data['order_id'] ) ? (string) $order_data['order_id'] : '',
			'recipient_name'    => $recipient_name,
			'recipient_phone'   => $recipient_phone,
			'recipient_address' => $recipient_address,
			'cod_amount'        => isset( $order_data['cod_amount'] ) ? (float) $order_data['cod_amount'] : 0,
			'note'              => isset( $order_data['special_instruction'] ) ? $order_data['special_instruction'] : '',
			'item_description'  => isset( $order_data['item_description'] ) ? $order_data['item_description'] : '',
			'weight'            => (float) ( $order_data['total_weight'] ?? 0.5 ),
			'recipient_area'    => $order_data['recipient_area'] ?? '',
		);

		// Add optional fields if provided.
		if ( ! empty( $order_data['item_quantity'] ) ) {
			$body['total_lot'] = (int) $order_data['item_quantity'];
		}
		if ( ! empty( $order_data['recipient_email'] ) ) {
			$body['recipient_email'] = $order_data['recipient_email'];
		}

		$response = $this->remote_post( $url, $body, $args, 30 );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		// The API returns HTTP 200 with body {"status":200,"consignment":{...}} on success.
		// On error, the body has {"status":400/401,"message":"..."} with NO consignment key.
		$api_status = isset( $response['status'] ) ? (int) $response['status'] : 0;

		// Only treat as success if API status is 200 AND consignment key is present.
		if ( 200 === $api_status && isset( $response['consignment'] ) && is_array( $response['consignment'] ) ) {
			$consignment     = $response['consignment'];
			$consignment_id  = isset( $consignment['consignment_id'] ) ? $consignment['consignment_id'] : '';
			$tracking_code   = isset( $consignment['tracking_code'] ) ? $consignment['tracking_code'] : '';
			$tracking_link   = isset( $consignment['tracking_link'] ) ? $consignment['tracking_link'] : '';
			$order_status    = isset( $consignment['status'] ) ? $consignment['status'] : 'in_review';
			$tracking_url    = ! empty( $tracking_link ) ? $tracking_link : $this->get_tracking_url( $consignment_id ?: $tracking_code );

			return array(
				'success'         => true,
				'consignment_id'  => $consignment_id,
				'tracking_id'     => $tracking_code,
				'tracking_url'    => $tracking_url,
				'status'          => $order_status,
				'delivery_fee'    => isset( $consignment['delivery_charge'] ) ? (float) $consignment['delivery_charge'] : 0,
				'cod_fee'         => isset( $consignment['cod_charge'] ) ? (float) $consignment['cod_charge'] : 0,
				'merchant_id'     => $this->active_merchant,
				'raw_response'    => $response,
			);
		}

		// API returned an error (status 400, 401, or missing consignment key).
		$error_message = isset( $response['message'] ) ? $response['message'] : __( 'Steadfast API error', 'easy-order-manager' );
		return array(
			'success'      => false,
			'error'        => sprintf(
				/* translators: 1: HTTP status from API, 2: error message */
				__( 'Steadfast API error (status %1$d): %2$s', 'easy-order-manager' ),
				$api_status,
				$error_message
			),
			'raw_response' => $response,
		);
		} finally {
			$this->reset_merchant();
		}
	}

	/**
	 * Track a Steadfast parcel by consignment ID.
	 *
	 * Official endpoint: GET /status_by_cid/{id}
	 *
	 * @param string $tracking_id Consignment / tracking ID.
	 *
	 * @return array Tracking status data.
	 */
	public function track_parcel( string $tracking_id ): array {
		$url  = $this->api_url . 'status_by_cid/' . urlencode( $tracking_id );
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

		// The API returns delivery_status directly at the top level.
		$delivery_status = isset( $response['delivery_status'] ) ? $response['delivery_status'] : ( isset( $response['data']['delivery_status'] ) ? $response['data']['delivery_status'] : '' );

		return array(
			'success'          => true,
			'tracking_id'      => $tracking_id,
			'status'           => $delivery_status,
			'delivery_status'  => $delivery_status,
			'current_location' => isset( $response['current_location'] ) ? $response['current_location'] : '',
			'last_update'      => isset( $response['updated_at'] ) ? $response['updated_at'] : '',
			'raw_response'     => $response,
		);
	}

	/**
	 * Cancel a Steadfast parcel.
	 *
	 * Note: Official API documentation does not include a cancel endpoint.
	 * Using create_return_request as the documented cancellation method.
	 *
	 * @param string $tracking_id Consignment ID to cancel.
	 *
	 * @return array Cancellation response.
	 */
	public function cancel_parcel( string $tracking_id ): array {
		// Use the documented return request endpoint instead of undocumented cancel.
		$url  = $this->api_url . 'create_return_request';
		$args = array(
			'headers' => $this->get_auth_headers(),
		);

		$body = array(
			'consignment_id' => $tracking_id,
			'reason'         => __( 'Cancelled by merchant', 'easy-order-manager' ),
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
			'tracking_id'  => $tracking_id,
			'status'       => 'cancelled',
			'raw_response' => $response,
		);
	}

	/**
	 * Get delivery areas for Steadfast.
	 *
	 * Steadfast covers nationwide Bangladesh. Returns a predefined
	 * list of major cities and districts.
	 *
	 * @return array Predefined area list.
	 */
	public function get_areas(): array {
		$areas = array(
			array(
				'id'     => 'dhaka',
				'name'   => __( 'Dhaka', 'easy-order-manager' ),
				'type'   => 'city',
				'childs' => array(
					array(
						'id'     => 'dhaka_metro',
						'name'   => __( 'Dhaka Metro', 'easy-order-manager' ),
						'type'   => 'zone',
						'childs' => array(),
					),
					array(
						'id'     => 'dhaka_sub',
						'name'   => __( 'Dhaka Suburban', 'easy-order-manager' ),
						'type'   => 'zone',
						'childs' => array(),
					),
				),
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
				'id'     => 'barishal',
				'name'   => __( 'Barishal', 'easy-order-manager' ),
				'type'   => 'city',
				'childs' => array(),
			),
			array(
				'id'     => 'rangpur',
				'name'   => __( 'Rangpur', 'easy-order-manager' ),
				'type'   => 'city',
				'childs' => array(),
			),
			array(
				'id'     => 'mymensingh',
				'name'   => __( 'Mymensingh', 'easy-order-manager' ),
				'type'   => 'city',
				'childs' => array(),
			),
			array(
				'id'     => 'nationwide',
				'name'   => __( 'All Districts (Nationwide)', 'easy-order-manager' ),
				'type'   => 'country',
				'childs' => array(),
			),
		);

		/**
		 * Filter Steadfast delivery areas.
		 *
		 * @param array $areas Predefined area list.
		 */
		return apply_filters( 'eom_steadfast_areas', $areas );
	}

	/**
	 * Calculate Steadfast delivery charge.
	 *
	 * Steadfast pricing (based on public rate card):
	 * - Inside Dhaka: 60 BDT first 0.5 kg, +10 BDT per additional 0.5 kg
	 * - Outside Dhaka: 110 BDT first 0.5 kg, +15 BDT per additional 0.5 kg
	 *
	 * COD fee is calculated separately by the courier manager.
	 *
	 * @param float  $weight      Parcel weight in kg.
	 * @param string $destination Destination identifier ('dhaka' or other).
	 * @param float  $cod_amount  Cash on delivery amount.
	 *
	 * @return float Calculated delivery charge.
	 */
	public function get_charge( float $weight, string $destination, float $cod_amount ): float {
		$inside_dhaka = ( 'dhaka' === $destination || 'dhaka_metro' === $destination );

		if ( $inside_dhaka ) {
			$base_charge = 60.0;
			$extra_rate  = 10.0;
		} else {
			$base_charge = 110.0;
			$extra_rate  = 15.0;
		}

		$weight = max( 0.1, $weight );
		if ( $weight > 0.5 ) {
			$extra_units  = ceil( ( $weight - 0.5 ) / 0.5 );
			$base_charge += $extra_units * $extra_rate;
		}

		return round( $base_charge, 2 );
	}

	/**
	 * Get public tracking URL for Steadfast.
	 *
	 * @param string $tracking_id Consignment ID or tracking code.
	 *
	 * @return string
	 */
	public function get_tracking_url( string $tracking_id ): string {
		return 'https://track.steadfast.com.bd/' . rawurlencode( $tracking_id );
	}

	/**
	 * Check account balance.
	 *
	 * Uses the currently active merchant credentials if a merchant
	 * was set via set_merchant(), otherwise uses default credentials.
	 *
	 * Official endpoint: GET /get_balance
	 *
	 * @param string $merchant_id Optional. Merchant account to check.
	 * @return array Balance information.
	 */
	public function check_balance( string $merchant_id = '' ): array {
		if ( ! empty( $merchant_id ) ) {
			$this->set_merchant( $merchant_id );
		}

		$url  = $this->api_url . 'get_balance';
		$args = array(
			'headers' => $this->get_auth_headers(),
		);

		$response = $this->remote_get( $url, $args, 30 );

		$this->reset_merchant();

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		// Response format: { "status": 200, "current_balance": 0 }
		$balance = 0;
		if ( isset( $response['current_balance'] ) ) {
			$balance = (float) $response['current_balance'];
		} elseif ( isset( $response['data']['current_balance'] ) ) {
			$balance = (float) $response['data']['current_balance'];
		}

		return array(
			'success'      => true,
			'balance'      => $balance,
			'merchant_id'  => $this->active_merchant,
			'raw_response' => $response,
		);
	}
}
