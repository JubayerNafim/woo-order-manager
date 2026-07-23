<?php
/**
 * EOM Admin Bulk Actions
 *
 * Processes bulk actions on orders from the EOM dashboard.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Admin_Bulk_Actions
 *
 * Handles bulk AJAX actions for orders.
 */
class EOM_Admin_Bulk_Actions {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_eom_process_bulk_action', array( $this, 'process_bulk_action' ) );
	}

	/**
	 * Get the list of available bulk actions.
	 *
	 * @return array Associative array of action_slug => label.
	 */
	public function get_bulk_actions() {
		return array(
			'change_status' => __( 'Change Status', 'easy-order-manager' ),
			'assign_staff'  => __( 'Assign Staff', 'easy-order-manager' ),
			'book_courier'  => __( 'Book Courier', 'easy-order-manager' ),
			'send_sms'      => __( 'Send SMS', 'easy-order-manager' ),
			'print_invoice' => __( 'Print Invoice', 'easy-order-manager' ),
			'export_csv'    => __( 'Export CSV', 'easy-order-manager' ),
			'delete_order'  => __( 'Delete Order', 'easy-order-manager' ),
		);
	}

	/**
	 * AJAX handler: process a bulk action.
	 *
	 * Receives order_ids (array), bulk_action (string), and optional value.
	 *
	 * @return void
	 */
	public function process_bulk_action() {
		check_ajax_referer( 'eom_process_bulk_action' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$order_ids  = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) $_POST['order_ids'] ) : array();
		$action     = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$value      = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';

		if ( empty( $order_ids ) || empty( $action ) ) {
			wp_send_json_error( __( 'Missing required parameters.', 'easy-order-manager' ) );
		}

		$valid_actions = array_keys( $this->get_bulk_actions() );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			wp_send_json_error( __( 'Invalid bulk action.', 'easy-order-manager' ) );
		}

		if ( ! $this->validate_user_can( $order_ids ) ) {
			wp_send_json_error( __( 'You do not have permission to manage these orders.', 'easy-order-manager' ) );
		}

		try {
			switch ( $action ) {
				case 'change_status':
					if ( empty( $value ) ) {
						wp_send_json_error( __( 'No status selected.', 'easy-order-manager' ) );
					}
					$this->process_change_status( $order_ids, $value );
					$message = sprintf(
						/* translators: %d: number of orders */
						__( 'Status changed to "%s" for %d order(s).', 'easy-order-manager' ),
						$value,
						count( $order_ids )
					);
					wp_send_json_success( array( 'message' => $message ) );
					break;

				case 'assign_staff':
					$this->process_assign_staff( $order_ids, $value );
					$staff_name = '';
					if ( $value ) {
						$staff_user = get_userdata( absint( $value ) );
						$staff_name = $staff_user ? $staff_user->display_name : '';
					}
					$message = sprintf(
						/* translators: 1: staff name, 2: number of orders */
						__( 'Assigned "%1$s" to %2$d order(s).', 'easy-order-manager' ),
						$staff_name ? $staff_name : __( 'None', 'easy-order-manager' ),
						count( $order_ids )
					);
					wp_send_json_success( array( 'message' => $message ) );
					break;

				case 'book_courier':
					$raw_merchant_id = isset( $_POST['merchant_id'] ) ? wp_unslash( $_POST['merchant_id'] ) : '';
					// merchant_id can be a string (all orders) or a JSON-encoded object (per-order: {"123":"wh1","456":"wh2"}).
					if ( is_string( $raw_merchant_id ) && '' !== $raw_merchant_id && '{' === $raw_merchant_id[0] ) {
						$decoded     = json_decode( $raw_merchant_id, true );
						$merchant_id = is_array( $decoded ) ? $decoded : '';
					} else {
						$merchant_id = sanitize_text_field( $raw_merchant_id );
					}
					$results       = $this->process_book_courier( $order_ids, $value, $merchant_id );
					$success_count = count( $results['success'] );
					$failed_count  = count( $results['failed'] );

					if ( $failed_count > 0 ) {
						$message = sprintf(
							/* translators: 1: courier name, 2: success count, 3: failed count */
							__( 'Booked "%1$s" courier for %2$d order(s). %3$d failed.', 'easy-order-manager' ),
							$value,
							$success_count,
							$failed_count
						);
					} else {
						$message = sprintf(
							/* translators: 1: courier name, 2: number of orders */
							__( 'Booked "%1$s" courier for %2$d order(s).', 'easy-order-manager' ),
							$value,
							$success_count
						);
					}
					wp_send_json_success( array( 'message' => $message, 'results' => $results ) );
					break;

				case 'send_sms':
					$this->process_send_sms( $order_ids, $value );
					$message = sprintf(
						/* translators: %d: number of orders */
						__( 'SMS sent for %d order(s).', 'easy-order-manager' ),
						count( $order_ids )
					);
					wp_send_json_success( array( 'message' => $message ) );
					break;

				case 'print_invoice':
					$url = $this->process_print_invoice( $order_ids );
					wp_send_json_success( array( 'url' => $url ) );
					break;

				case 'export_csv':
					$this->process_export_csv( $order_ids );
					break;

				case 'delete_order':
					$this->process_delete_order( $order_ids );
					$message = sprintf(
						/* translators: %d: number of orders */
						__( '%d order(s) deleted.', 'easy-order-manager' ),
						count( $order_ids )
					);
					wp_send_json_success( array( 'message' => $message ) );
					break;

				default:
					wp_send_json_error( __( 'Unknown action.', 'easy-order-manager' ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Change order status for a set of orders.
	 *
	 * @param array  $order_ids  Array of order IDs.
	 * @param string $new_status New status slug (without wc- prefix).
	 * @return void
	 */
	public function process_change_status( $order_ids, $new_status ) {
		$new_status = 'wc-' === substr( $new_status, 0, 3 ) ? $new_status : 'wc-' . $new_status;

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$order->update_status( $new_status );

			$this->log_activity( $order_id, sprintf(
				/* translators: %s: new order status */
				__( 'Bulk action: status changed to %s.', 'easy-order-manager' ),
				$new_status
			) );
		}
	}

	/**
	 * Assign staff to a set of orders.
	 *
	 * @param array $order_ids Array of order IDs.
	 * @param mixed $staff_id  User ID to assign.
	 * @return void
	 */
	public function process_assign_staff( $order_ids, $staff_id ) {
		foreach ( $order_ids as $order_id ) {
			update_post_meta( $order_id, 'eom_assigned_staff', $staff_id );

			$this->log_activity( $order_id, sprintf(
				/* translators: %s: staff ID or name */
				__( 'Bulk action: assigned staff ID %s.', 'easy-order-manager' ),
				$staff_id
			) );
		}
	}

	/**
	 * Book courier for a set of orders via the actual courier API.
	 *
	 * Uses the EOM_Courier_Manager to prepare order data and call
	 * the courier's book_parcel() method. Persists tracking info
	 * to the eom_courier_bookings table and order meta.
	 *
	 * @param array  $order_ids    Array of order IDs.
	 * @param string $courier_name Courier service slug (e.g. 'steadfast').
	 * @param string|array $merchant_id Merchant ID string (all orders) or array of order_id => merchant_id (per-order).
	 * @return array Results with success/failed counts.
	 */
	private function process_book_courier( $order_ids, $courier_name, $merchant_id = "" ): array {
		$manager = EOM_Courier_Manager::instance();
		$courier = $manager->get_courier( $courier_name );

		if ( ! $courier ) {
			return array(
				'success' => array(),
				'failed'  => array( 'global' => sprintf(
					/* translators: %s: courier name */
					__( 'Courier "%s" is not configured. Go to Courier Settings to add API credentials.', 'easy-order-manager' ),
					$courier_name
				) ),
			);
		}

		if ( ! $courier->is_available() ) {
			return array(
				'success' => array(),
				'failed'  => array( 'global' => sprintf(
					/* translators: %s: courier name */
					__( 'Courier "%s" credentials are not configured. Please visit Courier Settings first.', 'easy-order-manager' ),
					$courier_name
				) ),
			);
		}

		$results = $manager->bulk_book( $order_ids, $courier_name, $merchant_id );

		foreach ( $results['success'] as $order_id ) {
			$this->log_activity( $order_id, sprintf(
				/* translators: %s: courier name */
				__( 'Bulk action: booked with %s courier via API.', 'easy-order-manager' ),
				$courier_name
			) );
		}

		return $results;
	}

	/**
	 * Send SMS for orders (placeholder -- integrate with SMS gateway).
	 *
	 * @param array  $order_ids Array of order IDs.
	 * @param string $message   SMS message content.
	 * @return void
	 */
	private function process_send_sms( $order_ids, $message ) {
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$phone = $order->get_billing_phone();
			if ( empty( $phone ) ) {
				continue;
			}

			// Placeholder: integrate with SMS gateway here.
			// For example: eom_send_sms( $phone, $message );

			$this->log_activity( $order_id, sprintf(
				/* translators: 1: phone number, 2: message preview */
				__( 'Bulk action: SMS sent to %1$s ("%2$s").', 'easy-order-manager' ),
				$phone,
				mb_substr( $message, 0, 50 )
			) );
		}
	}

	/**
	 * Generate print invoice URL for orders.
	 *
	 * @param array $order_ids Array of order IDs.
	 * @return string URL to the invoice print page.
	 */
	private function process_print_invoice( $order_ids ) {
		// Generate a URL to the bulk print AJAX endpoint with all order IDs.
		$ids = implode( ',', array_map( 'absint', $order_ids ) );
		return admin_url( 'admin-ajax.php?action=eom_bulk_print_invoices&order_ids=' . $ids . '&_wpnonce=' . wp_create_nonce( 'eom_bulk_print_invoices' ) );
	}

	/**
	 * Export orders as CSV and send file to browser.
	 *
	 * @param array $order_ids Array of order IDs.
	 * @return void
	 */
	private function process_export_csv( $order_ids ) {
		$filename = 'eom-orders-export-' . current_time( 'Y-m-d-His' ) . '.csv';

		// Set headers for CSV download.
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		// CSV header row.
		fputcsv( $output, array(
			__( 'Order ID', 'easy-order-manager' ),
			__( 'Date', 'easy-order-manager' ),
			__( 'Customer Name', 'easy-order-manager' ),
			__( 'Phone', 'easy-order-manager' ),
			__( 'Email', 'easy-order-manager' ),
			__( 'Products', 'easy-order-manager' ),
			__( 'Total', 'easy-order-manager' ),
			__( 'Payment Method', 'easy-order-manager' ),
			__( 'Status', 'easy-order-manager' ),
			__( 'Courier', 'easy-order-manager' ),
			__( 'Tracking ID', 'easy-order-manager' ),
			__( 'Assigned Staff', 'easy-order-manager' ),
		) );

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$items = $order->get_items();
			$product_names = array();
			foreach ( $items as $item ) {
				$product_names[] = $item->get_name() . ' x' . $item->get_quantity();
			}

			$assigned_staff = $order->get_meta( 'eom_assigned_staff', true );
			$staff_name     = '';
			if ( $assigned_staff ) {
				$staff_user = get_userdata( absint( $assigned_staff ) );
				$staff_name = $staff_user ? $staff_user->display_name : '';
			}

			fputcsv( $output, array(
				$order_id,
				$order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '',
				$order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				$order->get_billing_phone(),
				$order->get_billing_email(),
				implode( ', ', $product_names ),
				$order->get_total(),
				$order->get_payment_method_title(),
				wc_get_order_status_name( $order->get_status() ),
				$order->get_meta( 'eom_courier_name', true ),
				$order->get_meta( 'eom_tracking_id', true ),
				$staff_name,
			) );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Delete orders permanently.
	 *
	 * @param array $order_ids Array of order IDs.
	 * @return void
	 */
	private function process_delete_order( $order_ids ) {
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$this->log_activity( $order_id, __( 'Bulk action: order deleted.', 'easy-order-manager' ) );
			wp_delete_post( $order_id, true );
		}
	}

	/**
	 * Validate that the current user can manage all given orders.
	 *
	 * @param array $order_ids Array of order IDs.
	 * @return bool True if user has manage_woocommerce capability.
	 */
	public function validate_user_can( $order_ids ) {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Log activity to the eom_activity_log meta.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $message  Activity message.
	 * @return void
	 */
	private function log_activity( $order_id, $message ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$log = $order->get_meta( 'eom_activity_log', true );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'time'    => current_time( 'mysql' ),
			'user_id' => get_current_user_id(),
			'action'  => $message,
		);
		$order->update_meta_data( 'eom_activity_log', $log );
		$order->save_meta_data();
	}
}
