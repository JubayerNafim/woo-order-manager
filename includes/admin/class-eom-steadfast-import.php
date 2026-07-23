<?php
/**
 * EOM Steadfast Import
 *
 * Handles importing Steadfast courier export XLSX files to update
 * COD charges, delivery charges, delivery status, and parcel tracking
 * information for existing WooCommerce orders.
 *
 * XLSX column mapping:
 *   - Order ID          → eom_consignment_id (the match key)
 *   - Tracking Code     → eom_tracking_id / tracking URL
 *   - Delivery Status   → eom_steadfast_delivery_status / eom_courier_status
 *   - Shipping Charge   → eom_courier_charge
 *   - COD Charge        → eom_courier_cod_fee
 *
 * Matching logic:
 *   1. Match by Order ID (consignment ID) against eom_consignment_id
 *   2. Fallback: match by Recipient Name + Recipient Phone against billing details
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Steadfast_Import
 */
class EOM_Steadfast_Import {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_eom_import_steadfast_xlsx', array( $this, 'ajax_import_xlsx' ) );
	}

	/**
	 * Render the import UI section for the dashboard.
	 *
	 * @return void
	 */
	public function render_import_ui() {
		?>
		<div class="eom-import-section">
			<div class="eom-import-header">
				<h3><?php esc_html_e( '📥 Import Steadfast Export Data', 'easy-order-manager' ); ?></h3>
				<button type="button" class="button eom-import-toggle" aria-expanded="false">
					<?php esc_html_e( 'Show', 'easy-order-manager' ); ?>
				</button>
			</div>
			<div class="eom-import-body" style="display:none;">
				<p class="description">
					<?php esc_html_e( 'Upload the consignment export .xlsx file downloaded from Steadfast. The system will:', 'easy-order-manager' ); ?>
				</p>
				<ol class="eom-import-steps">
					<li><?php esc_html_e( 'Match parcels by Order ID (consignment ID) — updates delivery status, delivery charge, COD fee & tracking code.', 'easy-order-manager' ); ?></li>
					<li><?php esc_html_e( 'If no consignment match found, match by Customer Name & Phone — sets all parcel info automatically.', 'easy-order-manager' ); ?></li>
				</ol>
				<div class="eom-import-form">
					<input type="file" id="eom-steadfast-import-file" accept=".xlsx" />
					<button type="button" class="button button-primary" id="eom-import-steadfast-btn">
						<?php esc_html_e( 'Upload & Process', 'easy-order-manager' ); ?>
					</button>
					<span class="eom-import-spinner" style="display:none;">
						<span class="eom-spinner"></span>
						<?php esc_html_e( 'Processing...', 'easy-order-manager' ); ?>
					</span>
				</div>
				<div id="eom-import-results" style="display:none;"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler: import Steadfast XLSX file.
	 *
	 * @return void
	 */
	public function ajax_import_xlsx() {
		check_ajax_referer( 'eom_import_steadfast_xlsx' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-order-manager' ) ) );
		}

		if ( ! isset( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'] ) || empty( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'easy-order-manager' ) ) );
		}

		$file = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Validate file extension.
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'xlsx' !== $ext ) {
			wp_send_json_error( array( 'message' => __( 'Please upload an .xlsx file.', 'easy-order-manager' ) ) );
		}

		// Validate file size (max 10MB).
		if ( $file['size'] > 10 * 1024 * 1024 ) {
			wp_send_json_error( array( 'message' => __( 'File too large. Maximum 10MB.', 'easy-order-manager' ) ) );
		}

		// Parse the XLSX file.
		$rows = $this->parse_xlsx( $file['tmp_name'] );
		if ( false === $rows ) {
			wp_send_json_error( array( 'message' => __( 'Failed to parse the XLSX file. Invalid format or missing ZipArchive extension.', 'easy-order-manager' ) ) );
		}

		if ( empty( $rows ) ) {
			wp_send_json_error( array( 'message' => __( 'No data found in the file.', 'easy-order-manager' ) ) );
		}

		// Process each row.
		$results = $this->process_rows( $rows );

		$summary = sprintf(
			/* translators: %1$d: total rows, %2$d: matched by consignment, %3$d: matched by name/phone, %4$d: no match */
			__( 'Processed <strong>%1$d</strong> parcels. ✅ Matched by parcel ID: <strong>%2$d</strong> | ✅ Matched by name &amp; phone: <strong>%3$d</strong> | ❌ Not matched: <strong>%4$d</strong>', 'easy-order-manager' ),
			$results['total'],
			$results['matched_by_consignment'],
			$results['matched_by_name_phone'],
			$results['not_matched']
		);

		wp_send_json_success(
			array(
				'results' => $results,
				'message' => $summary,
			)
		);
	}

	/**
	 * Parse an XLSX file and return rows as associative arrays.
	 *
	 * Uses PHP's built-in ZipArchive and SimpleXML — no external library needed.
	 *
	 * @param string $filepath Path to the XLSX file.
	 * @return array|false Array of rows (each an assoc array of column_name => value), or false on failure.
	 */
	private function parse_xlsx( $filepath ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$zip = new ZipArchive();
		if ( $zip->open( $filepath ) !== true ) {
			return false;
		}

		// Parse shared strings table.
		$shared_strings = $this->parse_shared_strings( $zip );

		// Parse sheet data.
		$rows = $this->parse_sheet_data( $zip, $shared_strings );
		$zip->close();

		return $rows;
	}

	/**
	 * Parse shared strings from xlsx.
	 *
	 * @param ZipArchive $zip Opened zip archive.
	 * @return array
	 */
	private function parse_shared_strings( $zip ) {
		$strings = array();
		$ss_xml  = $zip->getFromName( 'xl/sharedStrings.xml' );

		if ( false === $ss_xml ) {
			return $strings;
		}

		$ss_sxml = simplexml_load_string( $ss_xml );
		if ( false === $ss_sxml ) {
			return $strings;
		}

		foreach ( $ss_sxml->si as $si ) {
			if ( $si->t ) {
				$strings[] = (string) $si->t;
			} elseif ( $si->r ) {
				$text = '';
				foreach ( $si->r as $r ) {
					$text .= (string) $r->t;
				}
				$strings[] = $text;
			} else {
				$strings[] = '';
			}
		}

		return $strings;
	}

	/**
	 * Parse sheet data from xlsx.
	 *
	 * @param ZipArchive $zip            Opened zip archive.
	 * @param array      $shared_strings Shared strings table.
	 * @return array|false
	 */
	private function parse_sheet_data( $zip, array $shared_strings ) {
		$sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		if ( false === $sheet_xml ) {
			return false;
		}

		$sxml = simplexml_load_string( $sheet_xml );
		if ( false === $sxml ) {
			return false;
		}

		if ( ! isset( $sxml->sheetData ) || ! isset( $sxml->sheetData->row ) ) {
			return false;
		}

		$headers  = array();
		$rows     = array();
		$is_first = true;

		foreach ( $sxml->sheetData->row as $row ) {
			$row_data = array();

			foreach ( $row->c as $cell ) {
				$ref   = (string) $cell['r'];
				$type  = (string) $cell['t'];
				$value = (string) $cell->v;

				if ( 's' === $type && isset( $shared_strings[ (int) $value ] ) ) {
					$value = $shared_strings[ (int) $value ];
				}

				$col_letter              = preg_replace( '/[0-9]/', '', $ref );
				$row_data[ $col_letter ] = $value;
			}

			if ( $is_first ) {
				$headers  = $row_data;
				$is_first = false;
			} else {
				$mapped = array();
				foreach ( $headers as $col_letter => $header_name ) {
					$header_name_clean = trim( $header_name );
					$mapped[ $header_name_clean ] = isset( $row_data[ $col_letter ] ) ? $row_data[ $col_letter ] : '';
				}
				$rows[] = $mapped;
			}
		}

		return $rows;
	}

	/**
	 * Process imported rows against WooCommerce orders.
	 *
	 * @param array $rows Parsed rows from XLSX.
	 * @return array Summary results with details.
	 */
	private function process_rows( array $rows ) {
		$results = array(
			'total'                  => count( $rows ),
			'matched_by_consignment' => 0,
			'matched_by_name_phone'  => 0,
			'not_matched'            => 0,
			'updated'                => 0,
			'details'                => array(),
		);

		foreach ( $rows as $index => $row ) {
			// XLSX column mapping:
			// Order ID       → the consignment ID in Steadfast (match key)
			// Tracking Code  → the public tracking code
			// Delivery Status→ the delivery status text (e.g. "Delivered")
			$consignment_id = isset( $row['Order ID'] ) ? trim( (string) $row['Order ID'] ) : '';
			$tracking_code  = isset( $row['Tracking Code'] ) ? trim( $row['Tracking Code'] ) : '';
			$delivery_status = isset( $row['Delivery Status'] ) ? trim( $row['Delivery Status'] ) : '';
			$recipient_name = isset( $row['Recipient Name'] ) ? trim( $row['Recipient Name'] ) : '';
			$recipient_phone = isset( $row['Recipient Phone'] ) ? trim( (string) $row['Recipient Phone'] ) : '';
			$shipping_charge = isset( $row['Shipping Charge'] ) ? floatval( $row['Shipping Charge'] ) : 0;
			$cod_charge      = isset( $row['COD Charge'] ) ? floatval( $row['COD Charge'] ) : 0;

			// Normalise delivery status: lowercase, replace spaces with underscores.
			$status_slug = strtolower( str_replace( ' ', '_', $delivery_status ) );

			if ( empty( $consignment_id ) ) {
				$results['not_matched']++;
				$results['details'][] = $this->build_detail( $index, 'skipped', $recipient_name, $recipient_phone, $consignment_id, array( 'reason' => __( 'Empty Order ID (consignment ID)', 'easy-order-manager' ) ) );
				continue;
			}

			// Step 1: Try to match by consignment ID (Order ID from xlsx).
			$order_id = $this->find_order_by_consignment( $consignment_id );

			if ( $order_id ) {
				$this->update_order_charges_and_status( $order_id, $tracking_code, $status_slug, $shipping_charge, $cod_charge );
				$results['matched_by_consignment']++;
				$results['updated']++;
				$results['details'][] = $this->build_detail( $index, 'matched_consignment', $recipient_name, $recipient_phone, $consignment_id, array(
					'order_id'        => $order_id,
					'tracking'        => $tracking_code,
					'delivery_status' => $status_slug,
					'charge'          => $shipping_charge,
					'cod_fee'         => $cod_charge,
				) );
				continue;
			}

			// Step 2: Try to match by name and phone.
			if ( ! empty( $recipient_name ) && ! empty( $recipient_phone ) ) {
				$order_id = $this->find_order_by_name_phone( $recipient_name, $recipient_phone );

				if ( $order_id ) {
					$this->update_order_full( $order_id, $consignment_id, $tracking_code, $status_slug, $shipping_charge, $cod_charge );
					$results['matched_by_name_phone']++;
					$results['updated']++;
					$results['details'][] = $this->build_detail( $index, 'matched_name_phone', $recipient_name, $recipient_phone, $consignment_id, array(
						'order_id'        => $order_id,
						'tracking'        => $tracking_code,
						'delivery_status' => $status_slug,
						'charge'          => $shipping_charge,
						'cod_fee'         => $cod_charge,
					) );
					continue;
				}
			}

			// No match.
			$results['not_matched']++;
			$results['details'][] = $this->build_detail( $index, 'no_match', $recipient_name, $recipient_phone, $consignment_id, array(
				'reason' => __( 'No matching WooCommerce order found', 'easy-order-manager' ),
			) );
		}

		return $results;
	}

	/**
	 * Build a detail entry for results.
	 *
	 * @param int    $index     Row index.
	 * @param string $status    Status label.
	 * @param string $name      Customer name.
	 * @param string $phone     Customer phone.
	 * @param string $consignment Consignment/order ID.
	 * @param array  $extra     Extra fields.
	 * @return array
	 */
	private function build_detail( $index, $status, $name, $phone, $consignment, array $extra = array() ) {
		return array_merge(
			array(
				'row'        => $index + 2,
				'status'     => $status,
				'name'       => $name,
				'phone'      => $phone,
				'consignment' => $consignment,
			),
			$extra
		);
	}

	/**
	 * Find an order by its consignment ID.
	 *
	 * @param string $consignment_id The consignment ID (Order ID from xlsx).
	 * @return int|false Order ID if found, false otherwise.
	 */
	private function find_order_by_consignment( $consignment_id ) {
		global $wpdb;

		$order_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'eom_consignment_id' AND meta_value = %s LIMIT 1",
			$consignment_id
		) );

		if ( $order_id ) {
			return (int) $order_id;
		}

		// Also search by eom_tracking_id as a fallback.
		$order_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'eom_tracking_id' AND meta_value = %s LIMIT 1",
			$consignment_id
		) );

		return $order_id ? (int) $order_id : false;
	}

	/**
	 * Find an order by customer name and phone number.
	 *
	 * Performs intelligent matching:
	 * - Normalizes phone numbers (strips non-digits, handles 88/0 prefixes)
	 * - Compares full name and partial name matches
	 *
	 * @param string $name  Customer name from Steadfast export.
	 * @param string $phone Customer phone from Steadfast export.
	 * @return int|false Order ID if found, false otherwise.
	 */
	private function find_order_by_name_phone( $name, $phone ) {
		global $wpdb;

		$digits      = preg_replace( '/[^0-9]/', '', $phone );
		$local_number = ltrim( $digits, '0' ); // Strip leading zeros.
		$phone_variants = array_unique( array(
			$phone,
			$digits,
			'0' . $local_number,
			'88' . $local_number,
			'880' . $local_number,
			'+880' . $local_number,
			$local_number,
		) );
		$phone_variants = array_values( array_filter( $phone_variants ) );

		if ( empty( $phone_variants ) ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $phone_variants ), '%s' ) );

		$order_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			WHERE meta_key = '_billing_phone'
			AND meta_value IN ({$placeholders})",
			$phone_variants
		) );

		if ( empty( $order_ids ) ) {
			return false;
		}

		$name_normalised = strtolower( trim( preg_replace( '/\s+/', ' ', $name ) ) );
		$name_parts      = explode( ' ', $name_normalised );
		$first_name_part = $name_parts[0] ?? '';
		$last_name_part  = count( $name_parts ) > 1 ? implode( ' ', array_slice( $name_parts, 1 ) ) : '';

		foreach ( $order_ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order ) {
				continue;
			}

			$billing_first = strtolower( trim( $order->get_billing_first_name() ) );
			$billing_last  = strtolower( trim( $order->get_billing_last_name() ) );
			$billing_full  = strtolower( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) );

			if (
				$name_normalised === $billing_full ||
				$name_normalised === $billing_first . ' ' . $billing_last ||
				$billing_full === $first_name_part . ' ' . $last_name_part ||
				( ! empty( $billing_first ) && $name_normalised === $billing_first ) ||
				( ! empty( $billing_last ) && $name_normalised === $billing_last )
			) {
				return (int) $oid;
			}

			$billing_parts = explode( ' ', $billing_full );
			$common        = array_intersect( $name_parts, $billing_parts );
			if ( count( $common ) >= min( 2, count( $name_parts ) ) ) {
				return (int) $oid;
			}
		}

		return false;
	}

	/**
	 * Update charges, tracking code, and delivery status for a consignment-matched order.
	 *
	 * @param int    $order_id        Order ID.
	 * @param string $tracking_code   Tracking code from xlsx.
	 * @param string $delivery_status Normalised delivery status slug.
	 * @param float  $shipping_charge Delivery charge.
	 * @param float  $cod_charge      COD fee.
	 * @return void
	 */
	private function update_order_charges_and_status( $order_id, $tracking_code, $delivery_status, $shipping_charge, $cod_charge ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Update delivery status.
		if ( ! empty( $delivery_status ) ) {
			$order->update_meta_data( 'eom_steadfast_delivery_status', $delivery_status );
			$order->update_meta_data( 'eom_courier_status', $delivery_status );
		}

		// Update tracking code if not already set.
		$existing_tracking = $order->get_meta( 'eom_tracking_id', true );
		if ( empty( $existing_tracking ) && ! empty( $tracking_code ) ) {
			$order->update_meta_data( 'eom_tracking_id', $tracking_code );
			$order->update_meta_data( 'eom_courier_tracking_url', 'https://track.steadfast.com.bd/' . rawurlencode( $tracking_code ) );
		}

		if ( $shipping_charge > 0 ) {
			$order->update_meta_data( 'eom_courier_charge', $shipping_charge );
		}
		if ( $cod_charge > 0 ) {
			$order->update_meta_data( 'eom_courier_cod_fee', $cod_charge );
		}
		$order->save();

		// Also update bookings table status.
		$this->update_booking_status( $order_id, $delivery_status );

		$this->log_activity( $order_id, 'steadfast_import_charges', sprintf(
			/* translators: 1: delivery status, 2: delivery charge, 3: COD fee */
			__( 'Steadfast import: Status %1$s, Delivery: ৳%2$s, COD Fee: ৳%3$s', 'easy-order-manager' ),
			$delivery_status,
			number_format( $shipping_charge, 2 ),
			number_format( $cod_charge, 2 )
		) );
	}

	/**
	 * Update full order info for a name/phone matched order.
	 *
	 * Sets consignment ID, tracking ID, tracking URL, courier name,
	 * delivery status, delivery charge, COD fee, and updates the bookings table.
	 *
	 * @param int    $order_id        Order ID.
	 * @param string $consignment_id  Consignment ID (Order ID from xlsx).
	 * @param string $tracking_code   Tracking code from xlsx.
	 * @param string $delivery_status Normalised delivery status slug.
	 * @param float  $shipping_charge Delivery charge.
	 * @param float  $cod_charge      COD fee.
	 * @return void
	 */
	private function update_order_full( $order_id, $consignment_id, $tracking_code, $delivery_status, $shipping_charge, $cod_charge ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( 'eom_consignment_id', $consignment_id );
		$order->update_meta_data( 'eom_tracking_id', $tracking_code );
		$order->update_meta_data( 'eom_courier_tracking_url', 'https://track.steadfast.com.bd/' . rawurlencode( $tracking_code ) );

		// Only set courier name if not already set.
		$existing_courier = $order->get_meta( 'eom_courier_name', true );
		if ( empty( $existing_courier ) || 'manual' === $existing_courier ) {
			$order->update_meta_data( 'eom_courier_name', 'steadfast' );
		}

		if ( ! empty( $delivery_status ) ) {
			$order->update_meta_data( 'eom_steadfast_delivery_status', $delivery_status );
			$order->update_meta_data( 'eom_courier_status', $delivery_status );
		}

		if ( $shipping_charge > 0 ) {
			$order->update_meta_data( 'eom_courier_charge', $shipping_charge );
		}
		if ( $cod_charge > 0 ) {
			$order->update_meta_data( 'eom_courier_cod_fee', $cod_charge );
		}
		$order->save();

		// Update the courier bookings table.
		$this->upsert_booking( $order_id, $consignment_id, $tracking_code, $shipping_charge, $delivery_status );

		$this->log_activity( $order_id, 'steadfast_import_full', sprintf(
			/* translators: 1: consignment ID, 2: tracking code, 3: delivery status, 4: delivery charge, 5: COD fee */
			__( 'Steadfast import: Consignment %1$s, Tracking %2$s, Status %3$s, Delivery: ৳%4$s, COD Fee: ৳%5$s', 'easy-order-manager' ),
			$consignment_id,
			$tracking_code,
			$delivery_status,
			number_format( $shipping_charge, 2 ),
			number_format( $cod_charge, 2 )
		) );
	}

	/**
	 * Update the status in the courier bookings table.
	 *
	 * @param int    $order_id        Order ID.
	 * @param string $delivery_status Delivery status slug.
	 * @return void
	 */
	private function update_booking_status( $order_id, $delivery_status ) {
		global $wpdb;

		if ( empty( $delivery_status ) ) {
			return;
		}

		$wpdb->update(
			$wpdb->prefix . 'eom_courier_bookings',
			array(
				'status'     => $delivery_status,
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'order_id'     => $order_id,
				'courier_slug' => 'steadfast',
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Insert or update the courier bookings table.
	 *
	 * @param int    $order_id      Order ID.
	 * @param string $consignment_id Consignment ID.
	 * @param string $tracking_code Tracking code.
	 * @param float  $charge        Delivery charge.
	 * @param string $delivery_status Delivery status slug.
	 * @return void
	 */
	private function upsert_booking( $order_id, $consignment_id, $tracking_code, $charge, $delivery_status ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'eom_courier_bookings';
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE order_id = %d AND courier_slug = 'steadfast' LIMIT 1",
			$order_id
		) );

		if ( $existing ) {
			$data = array(
				'consignment_id' => $consignment_id,
				'tracking_id'    => $tracking_code,
				'charge'         => $charge,
				'updated_at'     => current_time( 'mysql' ),
			);
			$data_types = array( '%s', '%s', '%f', '%s' );

			if ( ! empty( $delivery_status ) ) {
				$data['status'] = $delivery_status;
				$data_types[]   = '%s';
			}

			$wpdb->update(
				$table,
				$data,
				array( 'id' => $existing ),
				$data_types,
				array( '%d' )
			);
		} else {
			$data = array(
				'order_id'       => $order_id,
				'courier_slug'   => 'steadfast',
				'tracking_id'    => $tracking_code,
				'consignment_id' => $consignment_id,
				'charge'         => $charge,
				'status'         => ! empty( $delivery_status ) ? $delivery_status : 'imported',
				'created_at'     => current_time( 'mysql' ),
			);

			$wpdb->insert(
				$table,
				$data,
				array( '%d', '%s', '%s', '%s', '%f', '%s', '%s' )
			);
		}
	}

	/**
	 * Log activity to the activity log table.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $action   Action slug.
	 * @param string $details  Description.
	 * @return void
	 */
	private function log_activity( $order_id, $action, $details ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'eom_activity_log',
			array(
				'order_id'   => $order_id,
				'user_id'    => get_current_user_id(),
				'user_name'  => wp_get_current_user()->display_name,
				'action'     => $action,
				'details'    => $details,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}
}
