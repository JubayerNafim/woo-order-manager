<?php
/**
 * EOM Google Sheets Integration
 *
 * Syncs WooCommerce order data to Google Sheets with live courier
 * delivery charges and COD fees from the courier API.
 *
 * Uses Google Service Account authentication (JWT bearer token)
 * for secure server-to-server API access to Google Sheets v4.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Google_Sheets
 *
 * Handles authentication with Google Sheets API and syncs
 * full order data including live delivery/COD costs from
 * courier integrations like Steadfast.
 */
class EOM_Google_Sheets {

	/**
	 * Google Sheets API base URL.
	 */
	const API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets/';

	/**
	 * OAuth2 token endpoint.
	 */
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * Cached access token.
	 *
	 * @var string|null
	 */
	private $access_token = null;

	/**
	 * Get sheet configuration.
	 *
	 * @return array Configuration or empty array.
	 */
	private function get_config(): array {
		return get_option( 'eom_google_sheets', array() );
	}

	/**
	 * Check if Google Sheets integration is configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		$config = $this->get_config();
		return ! empty( $config['spreadsheet_id'] ) && ! empty( $config['service_account_json'] );
	}

	/**
	 * Get the Google Sheet URL for display.
	 *
	 * @return string
	 */
	public function get_sheet_url(): string {
		$config = $this->get_config();
		if ( ! empty( $config['spreadsheet_id'] ) ) {
			return 'https://docs.google.com/spreadsheets/d/' . $config['spreadsheet_id'];
		}
		return '';
	}

	/**
	 * Obtain a JWT bearer access token from the service account JSON.
	 *
	 * Uses the service account's private key to sign a JWT assertion
	 * and exchange it for an OAuth2 access token.
	 *
	 * @return string|false Access token or false on failure.
	 */
	public function get_access_token() {
		if ( $this->access_token ) {
			return $this->access_token;
		}

		$config = $this->get_config();
		if ( empty( $config['service_account_json'] ) ) {
			return false;
		}

		$sa_data = json_decode( $config['service_account_json'], true );
		if ( ! $sa_data || empty( $sa_data['client_email'] ) || empty( $sa_data['private_key'] ) ) {
			return false;
		}

		$now     = time();
		$jwt_header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		$jwt_claim = array(
			'iss'   => $sa_data['client_email'],
			'scope' => 'https://www.googleapis.com/auth/spreadsheets',
			'aud'   => self::TOKEN_URL,
			'exp'   => $now + 3600,
			'iat'   => $now,
		);

		// Encode header & claim.
		$base64_header = $this->base64url_encode( wp_json_encode( $jwt_header ) );
		$base64_claim  = $this->base64url_encode( wp_json_encode( $jwt_claim ) );
		$signature_input = $base64_header . '.' . $base64_claim;

		// Sign with RSA-SHA256.
		$private_key = $sa_data['private_key'];
		$signature   = '';
		if ( ! openssl_sign( $signature_input, $signature, $private_key, 'sha256WithRSAEncryption' ) ) {
			return false;
		}
		$base64_signature = $this->base64url_encode( $signature );

		$jwt = $signature_input . '.' . $base64_signature;

		// Exchange JWT for access token.
		$response = wp_remote_post( self::TOKEN_URL, array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return false;
		}

		$this->access_token = $body['access_token'];
		return $this->access_token;
	}

	/**
	 * Base64 URL-safe encode.
	 *
	 * @param string $data Data to encode.
	 * @return string URL-safe base64.
	 */
	private function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Initialize a new spreadsheet header row.
	 *
	 * Creates the header columns if the sheet doesn't have them.
	 *
	 * @param string $sheet_name Optional sheet/tab name. Default 'Sheet1'.
	 * @return bool True on success.
	 */
	public function init_sheet( string $sheet_name = 'Sheet1' ): bool {
		$token = $this->get_access_token();
		if ( ! $token ) {
			return false;
		}

		$config = $this->get_config();
		$spreadsheet_id = $config['spreadsheet_id'];

		$headers = array(
			'Order ID',
			'Date',
			'Customer Name',
			'Phone',
			'Email',
			'Products',
			'Total (BDT)',
			'Payment Method',
			'COD Amount (BDT)',
			'Order Status',
			'Courier',
			'Tracking ID',
			'Delivery Charge (BDT)',
			'COD Fee (BDT)',
			'Assigned Staff',
			'Product Cost (BDT)',
			'Profit/Loss (BDT)',
		);

		$range = urlencode( $sheet_name . '!A1:Q1' );

		$response = wp_remote_get(
			self::API_BASE . $spreadsheet_id . '/values/' . $range,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Only write headers if the sheet is empty (no existing rows).
		if ( ! empty( $body['values'] ) && count( $body['values'] ) > 0 && count( $body['values'][0] ) > 0 ) {
			return true; // Headers already exist.
		}

		return $this->write_range( $sheet_name . '!A1:Q1', array( $headers ) );
	}

	/**
	 * Write a range of values to the sheet.
	 *
	 * @param string $range  Range string (e.g. 'Sheet1!A1:Q1').
	 * @param array  $values Array of rows to write.
	 * @return bool True on success.
	 */
	private function write_range( string $range, array $values ): bool {
		$token = $this->get_access_token();
		if ( ! $token ) {
			return false;
		}

		$config = $this->get_config();
		$spreadsheet_id = $config['spreadsheet_id'];

		$response = wp_remote_post(
			self::API_BASE . $spreadsheet_id . '/values/' . urlencode( $range ) . '?valueInputOption=USER_ENTERED',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'values'        => $values,
					'majorDimension' => 'ROWS',
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status = wp_remote_retrieve_response_code( $response );
		return $status >= 200 && $status < 300;
	}

	/**
	 * Append rows to the sheet.
	 *
	 * @param string $sheet_name Sheet/tab name.
	 * @param array  $values     Array of rows to append.
	 * @return bool True on success.
	 */
	private function append_rows( string $sheet_name, array $values ): bool {
		$token = $this->get_access_token();
		if ( ! $token ) {
			return false;
		}

		$config = $this->get_config();
		$spreadsheet_id = $config['spreadsheet_id'];

		$response = wp_remote_post(
			self::API_BASE . $spreadsheet_id . '/values/' . urlencode( $sheet_name . '!A:Q' ) . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'values'        => $values,
					'majorDimension' => 'ROWS',
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status = wp_remote_retrieve_response_code( $response );
		return $status >= 200 && $status < 300;
	}

	/**
	 * Sync a single order to Google Sheets.
	 *
	 * Prepares the full order data row including live delivery charge
	 * and COD fee from the courier API response.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return bool True on success.
	 */
	public function sync_order( int $order_id ): bool {
		if ( ! $this->is_configured() ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$config = $this->get_config();
		$sheet_name = ! empty( $config['sheet_name'] ) ? $config['sheet_name'] : 'Sheet1';

		// Ensure headers exist.
		$this->init_sheet( $sheet_name );

		// Build the order data row.
		$row = $this->prepare_order_row( $order );

		// Check if order already exists in the sheet.
		if ( $this->order_exists_in_sheet( $order_id, $sheet_name ) ) {
			return $this->update_order_row( $order_id, $sheet_name, $row );
		}

		// Append new row.
		return $this->append_rows( $sheet_name, array( $row ) );
	}

	/**
	 * Sync multiple orders to Google Sheets.
	 *
	 * @param array $order_ids Array of WooCommerce order IDs.
	 * @return array{'success': int, 'failed': int} Counts.
	 */
	public function sync_orders( array $order_ids ): array {
		$success = 0;
		$failed  = 0;

		foreach ( $order_ids as $order_id ) {
			if ( $this->sync_order( $order_id ) ) {
				$success++;
			} else {
				$failed++;
			}
		}

		return array(
			'success' => $success,
			'failed'  => $failed,
		);
	}

	/**
	 * Sync all orders (used for initial full sync).
	 *
	 * @param int $batch_size Number of orders to process per batch.
	 * @return array{'success': int, 'failed': int, 'total': int} Results.
	 */
	public function sync_all_orders( int $batch_size = 50 ): array {
		$total   = 0;
		$success = 0;
		$failed  = 0;
		$offset  = 0;

		while ( true ) {
			$orders = wc_get_orders( array(
				'limit'  => $batch_size,
				'offset' => $offset,
				'return' => 'ids',
				'orderby' => 'date',
				'order'  => 'DESC',
			) );

			if ( empty( $orders ) ) {
				break;
			}

			$total += count( $orders );
			$result = $this->sync_orders( $orders );
			$success += $result['success'];
			$failed  += $result['failed'];

			$offset += $batch_size;

			// Safety break.
			if ( $offset > 10000 ) {
				break;
			}
		}

		return array(
			'success' => $success,
			'failed'  => $failed,
			'total'   => $total,
		);
	}

	/**
	 * Check if an order already exists in the sheet by Order ID.
	 *
	 * @param int    $order_id   Order ID to check.
	 * @param string $sheet_name Sheet/tab name.
	 * @return bool True if exists.
	 */
	private function order_exists_in_sheet( int $order_id, string $sheet_name ): bool {
		$token = $this->get_access_token();
		if ( ! $token ) {
			return false;
		}

		$config = $this->get_config();

		$response = wp_remote_get(
			self::API_BASE . $config['spreadsheet_id'] . '/values/' . urlencode( $sheet_name . '!A:A' ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['values'] ) ) {
			return false;
		}

		$order_id_str = (string) $order_id;
		foreach ( $body['values'] as $row ) {
			if ( isset( $row[0] ) && (string) $row[0] === $order_id_str ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Update an existing order row in the sheet.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $sheet_name Sheet/tab name.
	 * @param array  $row_data   New row data.
	 * @return bool True on success.
	 */
	private function update_order_row( int $order_id, string $sheet_name, array $row_data ): bool {
		$token = $this->get_access_token();
		if ( ! $token ) {
			return false;
		}

		$config = $this->get_config();

		// Find row number.
		$response = wp_remote_get(
			self::API_BASE . $config['spreadsheet_id'] . '/values/' . urlencode( $sheet_name . '!A:A' ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['values'] ) ) {
			return false;
		}

		$order_id_str = (string) $order_id;
		$row_number   = 0;

		foreach ( $body['values'] as $index => $row ) {
			if ( isset( $row[0] ) && (string) $row[0] === $order_id_str ) {
				$row_number = $index + 1; // Sheets are 1-indexed.
				break;
			}
		}

		if ( $row_number < 2 ) { // Don't overwrite header (row 1).
			return false;
		}

		$range = $sheet_name . '!A' . $row_number . ':Q' . $row_number;
		return $this->write_range( $range, array( $row_data ) );
	}

	/**
	 * Prepare a full order data row for the sheet.
	 *
	 * Includes live delivery charge and COD fee from the courier
	 * API response stored in the booking record.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return array Order data row.
	 */
	private function prepare_order_row( \WC_Order $order ): array {
		$order_id = $order->get_id();

		// Get product names.
		$items         = $order->get_items();
		$product_names = array();
		$total_cost    = 0;

		foreach ( $items as $item ) {
			$product_names[] = $item->get_name() . ' x' . $item->get_quantity();
			$product = $item->get_product();
			if ( $product ) {
				$cost = (float) $product->get_price();
				$total_cost += $cost * $item->get_quantity();
			}
		}

		// Get courier info from order meta.
		$courier_name   = $order->get_meta( 'eom_courier_name', true );
		$tracking_id    = $order->get_meta( 'eom_tracking_id', true );
		$delivery_charge = $order->get_meta( 'eom_courier_charge', true );

		// Get live delivery fee and COD fee from the courier booking record.
		$booking_charge = 0;
		$cod_fee        = 0;
		$booking = null;

		if ( ! empty( $courier_name ) ) {
			$manager = EOM_Courier_Manager::instance();
			$booking = $manager->get_booking( $order_id );
			if ( $booking && ! empty( $booking['charge'] ) ) {
				$booking_charge = (float) $booking['charge'];

				// Try to extract COD fee from the courier response data.
				if ( ! empty( $booking['response_data'] ) ) {
					$response_data = json_decode( $booking['response_data'], true );
					if ( $response_data && isset( $response_data['cod_fee'] ) ) {
						$cod_fee = (float) $response_data['cod_fee'];
					}
				}
			}
		}

		// Calculate COD amount.
		$cod_amount = 0;
		if ( in_array( $order->get_payment_method(), array( 'cod', 'cash_on_delivery' ), true ) ) {
			$cod_amount = (float) $order->get_total();
		}

		// Calculate expected delivery charge using the courier's formula.
		$expected_charge = 0;
		if ( $booking_charge > 0 ) {
			$expected_charge = $booking_charge;
		} elseif ( ! empty( $delivery_charge ) ) {
			$expected_charge = (float) $delivery_charge;
		} elseif ( ! empty( $courier_name ) ) {
			// Manually calculate using courier's get_charge method.
			$manager = EOM_Courier_Manager::instance();
			$courier = $manager->get_courier( $courier_name );
			if ( $courier ) {
				$weight = 0.5;
				foreach ( $items as $item ) {
					$product = $item->get_product();
					if ( $product ) {
						$w = (float) $product->get_weight();
						if ( $w > 0 ) {
							$weight += $w * $item->get_quantity();
						}
					}
				}
				$destination = $order->get_shipping_city() ?: $order->get_billing_city();
				$expected_charge = $courier->get_charge( $weight, $destination, $cod_amount );
			}
		}

		// Calculate profit/loss if product cost is known.
		$product_cost = (float) $order->get_meta( '_eom_product_cost', true );
		if ( $product_cost <= 0 ) {
			$product_cost = $total_cost;
		}
		$profit_loss = (float) $order->get_total() - $product_cost - $expected_charge - $cod_fee;

		// Staff info.
		$assigned_staff = $order->get_meta( 'eom_assigned_staff', true );
		$staff_name     = '';
		if ( $assigned_staff ) {
			$staff_user = get_userdata( absint( $assigned_staff ) );
			$staff_name = $staff_user ? $staff_user->display_name : '';
		}

		$date_created = $order->get_date_created();
		$date_str     = $date_created ? $date_created->date_i18n( 'Y-m-d H:i' ) : '';

		return array(
			(string) $order_id,
			$date_str,
			trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			$order->get_billing_phone(),
			$order->get_billing_email(),
			implode( ', ', $product_names ),
			(string) (float) $order->get_total(),
			$order->get_payment_method_title(),
			(string) $cod_amount,
			wc_get_order_status_name( $order->get_status() ),
			$courier_name,
			$tracking_id,
			(string) $expected_charge,
			(string) $cod_fee,
			$staff_name,
			(string) $product_cost,
			(string) round( $profit_loss, 2 ),
		);
	}
}
