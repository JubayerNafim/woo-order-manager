<?php
/**
 * EOM Courier Manager
 *
 * Central hub for managing all courier integrations.
 * Handles courier registration, retrieval, bulk booking,
 * and order-to-courier assignments.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Courier_Manager
 *
 * Singleton class that serves as the registry and factory for all
 * courier integrations. Couriers register via the 'eom_register_couriers'
 * filter, allowing third-party plugins to add their own.
 */
class EOM_Courier_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var EOM_Courier_Manager|null
	 */
	private static $instance = null;

	/**
	 * Registered courier instances, keyed by slug.
	 *
	 * @var EOM_Courier_Base[]
	 */
	private $couriers = array();

	/**
	 * Registered courier class names, keyed by slug.
	 *
	 * @var string[]
	 */
	private $registry = array();

	/**
	 * Database table name for courier bookings.
	 *
	 * @var string
	 */
	private $bookings_table;

	/**
	 * Get the singleton instance.
	 *
	 * @return EOM_Courier_Manager
	 */
	public static function instance(): EOM_Courier_Manager {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;
		$this->bookings_table = $wpdb->prefix . 'eom_courier_bookings';

		$this->load_couriers();
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}

	/**
	 * Load and register all couriers.
	 *
	 * Fires the 'eom_register_couriers' filter so other plugins
	 * can add their own courier integrations.
	 *
	 * @return void
	 */
	private function load_couriers(): void {
		/**
		 * Filter the list of courier class names.
		 *
		 * Other plugins can use this filter to register their own courier
		 * integrations by adding entries with a unique slug and class name.
		 *
		 * @param array $couriers {
		 *     Associative array of courier slugs to class names.
		 *
		 *     @type string $slug Fully qualified class name extending EOM_Courier_Base.
		 * }
		 */
		$default_couriers = array(
			'pathao'    => 'EOM_Courier_Pathao',
			'steadfast' => 'EOM_Courier_Steadfast',
			'redx'      => 'EOM_Courier_RedX',
			'carriebee' => 'EOM_Courier_CarrieBee',
			'ecourier'  => 'EOM_Courier_eCourier',
			'sundarban' => 'EOM_Courier_Sundarban',
			'paperfly'  => 'EOM_Courier_Paperfly',
		);

		/**
		 * Filter the list of courier class names to register.
		 *
		 * @param array $default_couriers Default courier slugs to class names.
		 */
		$this->registry = apply_filters( 'eom_register_couriers', $default_couriers );

		// Instantiate each registered courier.
		foreach ( $this->registry as $slug => $class_name ) {
			$this->instantiate_courier( $slug, $class_name );
		}
	}

	/**
	 * Instantiate a courier class and store it.
	 *
	 * @param string $slug       Courier slug.
	 * @param string $class_name Fully qualified class name.
	 *
	 * @return bool True if instantiated successfully.
	 */
	private function instantiate_courier( string $slug, string $class_name ): bool {
		if ( ! class_exists( $class_name ) ) {
			return false;
		}

		$settings = get_option( 'eom_courier_' . $slug, array() );
		$config   = array_merge( $settings, array( 'slug' => $slug ) );

		try {
			$courier = new $class_name( $config );
			if ( $courier instanceof EOM_Courier_Base ) {
				$this->couriers[ $slug ] = $courier;
				return true;
			}
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'EOM Courier: Failed to instantiate ' . $class_name . ': ' . $e->getMessage() );
		}

		return false;
	}

	/**
	 * Register a courier at runtime.
	 *
	 * @param string $slug       Unique courier identifier.
	 * @param string $class_name Fully qualified class name extending EOM_Courier_Base.
	 *
	 * @return bool True if registered successfully.
	 */
	public function register_courier( string $slug, string $class_name ): bool {
		if ( isset( $this->registry[ $slug ] ) ) {
			return false;
		}

		$this->registry[ $slug ] = $class_name;
		return $this->instantiate_courier( $slug, $class_name );
	}

	/**
	 * Get a specific courier instance by slug.
	 *
	 * @param string $slug Courier slug (e.g. 'pathao', 'steadfast').
	 *
	 * @return EOM_Courier_Base|null Courier instance or null if not found.
	 */
	public function get_courier( string $slug ): ?EOM_Courier_Base {
		return isset( $this->couriers[ $slug ] ) ? $this->couriers[ $slug ] : null;
	}

	/**
	 * Get all registered couriers (configured or not).
	 *
	 * @return EOM_Courier_Base[] Array of courier instances.
	 */
	public function get_all_couriers(): array {
		return $this->couriers;
	}

	/**
	 * Get only configured/available couriers.
	 *
	 * @return EOM_Courier_Base[] Array of courier instances that have credentials.
	 */
	public function get_available_couriers(): array {
		$available = array();

		foreach ( $this->couriers as $slug => $courier ) {
			if ( $courier->is_available() ) {
				$available[ $slug ] = $courier;
			}
		}

		return $available;
	}

	/**
	 * Get courier slugs that are configured.
	 *
	 * @return string[] Array of available courier slugs.
	 */
	public function get_available_slugs(): array {
		return array_keys( $this->get_available_couriers() );
	}

	/**
	 * Bulk book multiple orders with a single courier.
	 *
	 * @param array  $order_ids     Array of WooCommerce order IDs.
	 * @param string $courier_slug  Courier slug to book with.
	 *
	 * @return array {
	 *     Results of bulk booking.
	 *
	 *     @type array $success List of order IDs that booked successfully.
	 *     @type array $failed  List of errors keyed by order ID.
	 * }
	 */
	public function bulk_book( array $order_ids, string $courier_slug, $merchant_id = '' ): array {
		$courier = $this->get_courier( $courier_slug );
		if ( ! $courier ) {
			return array(
				'success' => array(),
				'failed'  => array(
					'global' => sprintf(
						/* translators: %s: Courier slug */
						__( 'Courier "%s" not found.', 'easy-order-manager' ),
						$courier_slug
					),
				),
			);
		}

		$results = array(
			'success' => array(),
			'failed'  => array(),
		);

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				$results['failed'][ $order_id ] = __( 'Order not found.', 'easy-order-manager' );
				continue;
			}

			$order_merchant_id = is_array( $merchant_id ) ? ( $merchant_id[ $order_id ] ?? '' ) : $merchant_id;
			$order_data        = $this->prepare_order_data( $order, $courier, $order_merchant_id );
			$response   = $courier->book_parcel( $order_data );

			if ( isset( $response['success'] ) && $response['success'] ) {
				$this->save_booking( $order_id, $courier_slug, $response );
				$results['success'][] = $order_id;
			} else {
				$results['failed'][ $order_id ] = isset( $response['error'] ) ? $response['error'] : __( 'Unknown error.', 'easy-order-manager' );
			}
		}

		return $results;
	}

	/**
	 * Prepare order data for courier booking.
	 *
	 * Sanitizes phone numbers (removes +/country codes for Steadfast),
	 * calculates COD amount, and builds item descriptions.
	 *
	 * @param \WC_Order       $order        WooCommerce order object.
	 * @param EOM_Courier_Base $courier      Courier instance.
	 *
	 * @return array Order data formatted for the courier API.
	 */
	private function prepare_order_data( \WC_Order $order, EOM_Courier_Base $courier, string $merchant_id = '' ): array {
		$items       = $order->get_items();
		$item_names = array();
		$total_qty  = 0;
		$total_weight = 0;

		foreach ( $items as $item ) {
			$product = $item->get_product();
			$item_names[] = $item->get_name();
			$total_qty   += $item->get_quantity();

			if ( $product ) {
				$weight = (float) $product->get_weight();
				if ( $weight > 0 ) {
					$total_weight += $weight * $item->get_quantity();
				}
			}
		}

		if ( $total_weight <= 0 ) {
			$total_weight = 0.5; // Default weight.
		}

		$shipping_address = $order->get_address( 'shipping' );
		$billing_address  = $order->get_address( 'billing' );

		// Use shipping address if available, fall back to billing.
		$recipient_name    = ! empty( $shipping_address['first_name'] ) ? trim( $shipping_address['first_name'] . ' ' . $shipping_address['last_name'] ) : trim( $billing_address['first_name'] . ' ' . $billing_address['last_name'] );

		// Sanitize phone: remove +, (, ), -, spaces, and leading country codes for Steadfast (needs exactly 11 digits).
		$recipient_phone   = ! empty( $shipping_address['phone'] ) ? $shipping_address['phone'] : $order->get_billing_phone();
		$recipient_phone   = $this->sanitize_phone( $recipient_phone );

		$recipient_address = ! empty( $shipping_address['address_1'] ) ? trim( $shipping_address['address_1'] . ' ' . $shipping_address['address_2'] ) : trim( $billing_address['address_1'] . ' ' . $billing_address['address_2'] );
		// Truncate to 250 chars max (Steadfast limit).
		$recipient_address = mb_substr( $recipient_address, 0, 250 );

		$recipient_city    = ! empty( $shipping_address['city'] ) ? $shipping_address['city'] : $billing_address['city'];
		$recipient_area    = ! empty( $shipping_address['state'] ) ? $shipping_address['state'] : $billing_address['state'];
		$recipient_email   = $order->get_billing_email();

		$cod_amount = 0;
		$payment_method = $order->get_payment_method();
		if ( in_array( $payment_method, array( 'cod', 'cash_on_delivery' ), true ) ) {
			$cod_amount = (float) $order->get_total();
		}

		// Calculate expected delivery charges.
		$expected_charge = 0;
		$expected_cod_fee = 0;
		$destination      = ! empty( $recipient_city ) ? strtolower( $recipient_city ) : 'dhaka';
		$expected_charge  = $courier->get_charge( $total_weight, $destination, $cod_amount );
		// COD fee is 1% of COD amount (min 10 BDT) per Steadfast's rate card.
		if ( $cod_amount > 0 ) {
			$expected_cod_fee = max( 10.0, $cod_amount * 0.01 );
		}

		return array(
			'order_id'           => $order->get_id(),
			'recipient_name'     => mb_substr( $recipient_name, 0, 100 ),
			'recipient_phone'    => $recipient_phone,
			'recipient_address'  => $recipient_address,
			'recipient_city'     => $recipient_city,
			'recipient_area'     => $recipient_area,
			'recipient_email'    => $recipient_email,
			'cod_amount'         => $cod_amount,
			'total_weight'       => $total_weight,
			'item_quantity'      => $total_qty,
			'item_description'   => implode( ', ', array_slice( $item_names, 0, 5 ) ),
			'special_instruction'=> $order->get_customer_note(),
			'expected_charge'    => $expected_charge,
			'expected_cod_fee'   => $expected_cod_fee,
			'pickup_address'     => get_option( 'eom_pickup_address', '' ),
			'pickup_phone'       => get_option( 'eom_pickup_phone', '' ),
			'store_id'           => get_option( 'eom_courier_' . $courier->get_slug() . '_store_id', '' ),
			'merchant_id'        => $merchant_id,
		);
	}

	/**
	 * Save a courier booking record to the database.
	 *
	 * Saves tracking info, calculates expected delivery charges using
	 * the courier's rate card, and persists to order meta.
	 *
	 * @param int    $order_id     WooCommerce order ID.
	 * @param string $courier_slug Courier slug used.
	 * @param array  $response     Booking response from courier.
	 *
	 * @return int|false The number of rows inserted, or false on error.
	 */
	private function save_booking( int $order_id, string $courier_slug, array $response ) {
		global $wpdb;

		$tracking_id  = isset( $response['tracking_id'] ) ? $response['tracking_id'] : '';
		$consignment  = isset( $response['consignment_id'] ) ? $response['consignment_id'] : '';
		$status       = isset( $response['status'] ) ? $response['status'] : 'in_review';

		// Calculate expected delivery charge and COD fee using the rate card.
		$expected_charge = $this->calculate_order_charge( $order_id, $courier_slug );

		$data = array(
			'order_id'       => $order_id,
			'courier_slug'   => $courier_slug,
			'tracking_id'    => $tracking_id,
			'consignment_id' => $consignment,
			'status'         => $status,
			'charge'         => $expected_charge,
			'response_data'  => wp_json_encode( $response ),
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		);

		$format = array( '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s' );

		$result = $wpdb->insert( $this->bookings_table, $data, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		// Update order meta with all booking info.
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( 'eom_courier_name', $courier_slug );
			$order->update_meta_data( 'eom_tracking_id', $tracking_id );
			$order->update_meta_data( 'eom_consignment_id', $consignment );
			$order->update_meta_data( 'eom_courier_status', $status );
			$order->update_meta_data( 'eom_courier_charge', $expected_charge );
			$order->update_meta_data( 'eom_courier_tracking_url', isset( $response['tracking_url'] ) ? $response['tracking_url'] : '' );

			// Extract COD fee from courier response if present.
			$cod_fee = isset( $response['cod_fee'] ) ? (float) $response['cod_fee'] : 0;
			$order->update_meta_data( 'eom_courier_cod_fee', $cod_fee );

			// Store the merchant ID used for this booking (important for multi-merchant tracking).
			if ( isset( $response['merchant_id'] ) ) {
				$order->update_meta_data( 'eom_steadfast_merchant_id', $response['merchant_id'] );
			}

			// Store initial delivery status from the booking response.
			$order->update_meta_data( 'eom_steadfast_delivery_status', $status );

			$order->save();
		}

		/**
		 * Fires after a courier booking is saved.
		 *
		 * @param int    $order_id     Order ID.
		 * @param array  $response     The booking response from the courier API.
		 * @param string $courier_slug Courier slug.
		 */
		do_action( 'eom_after_courier_booking', $order_id, $response, $courier_slug );

		return $result;
	}

	/**
	 * Get the courier assigned to an order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 *
	 * @return EOM_Courier_Base|null Courier instance or null if none assigned.
	 */
	public function get_courier_for_order( int $order_id ): ?EOM_Courier_Base {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}

		$courier_slug = $order->get_meta( 'eom_courier_name', true );
		if ( empty( $courier_slug ) ) {
			return null;
		}

		return $this->get_courier( $courier_slug );
	}

	/**
	 * Get the courier booking records for an order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 *
	 * @return array|null Booking record or null.
	 */
	public function get_booking( int $order_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT * FROM {$this->bookings_table} WHERE order_id = %d ORDER BY created_at DESC LIMIT 1",
				$order_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Calculate expected delivery charge for an order using the courier's rate card.
	 *
	 * @param int    $order_id     Order ID.
	 * @param string $courier_slug Courier slug.
	 * @return float Expected charge.
	 */
	private function calculate_order_charge( int $order_id, string $courier_slug ): float {
		$courier = $this->get_courier( $courier_slug );
		if ( ! $courier ) {
			return 0;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return 0;
		}

		$weight = 0;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product ) {
				$w = (float) $product->get_weight();
				if ( $w > 0 ) {
					$weight += $w * $item->get_quantity();
				}
			}
		}

		if ( $weight <= 0 ) {
			$weight = 0.5; // Minimum weight.
		}

		$destination = $order->get_shipping_city() ?: $order->get_billing_city();
		$destination = strtolower( $destination );

		$cod_amount = 0;
		if ( in_array( $order->get_payment_method(), array( 'cod', 'cash_on_delivery' ), true ) ) {
			$cod_amount = (float) $order->get_total();
		}

		return $courier->get_charge( $weight, $destination, $cod_amount );
	}

	/**
	 * Sanitize a phone number for Steadfast API (exactly 11 digits).
	 *
	 * Removes +, (), -, spaces, and leading country codes (880, 00880).
	 *
	 * @param string $phone Raw phone number.
	 * @return string Sanitized 11-digit phone number.
	 */
	private function sanitize_phone( string $phone ): string {
		// Remove all non-digit characters.
		$phone = preg_replace( '/[^0-9]/', '', $phone );

		// Remove leading country codes (0088 or 880).
		if ( strlen( $phone ) === 14 && substr( $phone, 0, 4 ) === '0088' ) {
			$phone = substr( $phone, 4 );
		} elseif ( strlen( $phone ) === 13 && substr( $phone, 0, 3 ) === '880' ) {
			$phone = substr( $phone, 3 );
		}

		// If length is 10 after stripping country code, prepend "0" to make 11 digits.
		// This handles "+880" prefix which becomes 13 digits then 10 after stripping "880".
		if ( strlen( $phone ) === 10 ) {
			$phone = '0' . $phone;
		}

		// If still 11 digits, return as-is. Otherwise return original digits.
		if ( strlen( $phone ) === 11 ) {
			return $phone;
		}

		// Return the cleaned number even if not exactly 11 digits.
		return $phone;
	}

	/**
	 * Update a booking record's status and tracking data.
	 *
	 * @param int    $order_id   Order ID.
	 * @param array  $track_data Tracking data to update.
	 *
	 * @return bool
	 */
	public function update_booking( int $order_id, array $track_data ): bool {
		global $wpdb;

		$data = array(
			'updated_at' => current_time( 'mysql' ),
		);

		if ( isset( $track_data['status'] ) ) {
			$data['status'] = $track_data['status'];
		}
		if ( isset( $track_data['tracking_id'] ) ) {
			$data['tracking_id'] = $track_data['tracking_id'];
		}

		if ( isset( $track_data['response_data'] ) ) {
			$data['response_data'] = is_string( $track_data['response_data'] ) ? $track_data['response_data'] : wp_json_encode( $track_data['response_data'] );
		}

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->bookings_table,
			$data,
			array( 'order_id' => $order_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Update order meta.
		$order = wc_get_order( $order_id );
		if ( $order ) {
			if ( isset( $track_data['status'] ) ) {
				$order->update_meta_data( 'eom_courier_status', $track_data['status'] );
			}
			if ( isset( $track_data['tracking_id'] ) ) {
				$order->update_meta_data( 'eom_tracking_id', $track_data['tracking_id'] );
			}
			$order->save();
		}

		return false !== $result;
	}

	/**
	 * Create the bookings database table on plugin activation.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'eom_courier_bookings';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			order_id BIGINT UNSIGNED NOT NULL,
			courier_slug VARCHAR(50) NOT NULL,
			tracking_id VARCHAR(255) DEFAULT '',
			consignment_id VARCHAR(255) DEFAULT '',
			status VARCHAR(50) DEFAULT 'booked',
			charge DECIMAL(10,2) DEFAULT 0.00,
			response_data LONGTEXT,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_order_id (order_id),
			INDEX idx_courier_slug (courier_slug),
			INDEX idx_tracking_id (tracking_id),
			INDEX idx_status (status),
			INDEX idx_updated_at (updated_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
