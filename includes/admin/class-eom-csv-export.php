<?php
/**
 * EOM CSV Export
 *
 * Provides CSV export functionality for orders from the EOM dashboard.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_CSV_Export
 *
 * Handles exporting orders to CSV with configurable columns.
 */
class EOM_CSV_Export {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_eom_export_csv', array( $this, 'ajax_export_csv' ) );
		add_action( 'wp_ajax_eom_export_selected', array( $this, 'ajax_export_selected' ) );
		add_filter( 'eom_bulk_actions', array( $this, 'add_export_to_bulk_actions' ) );
	}

	/**
	 * Export orders to CSV format.
	 *
	 * @param array $order_ids_or_filters Array of order IDs or filter parameters.
	 * @return string CSV content as a string.
	 */
	public function export_orders( $order_ids_or_filters = array() ) {
		$columns = $this->get_exportable_columns();
		$headers = array_values( $columns );

		$orders = array();

		if ( ! empty( $order_ids_or_filters ) ) {
			// Check if it's an array of IDs (numeric) or filter array.
			$first = reset( $order_ids_or_filters );
			if ( is_numeric( $first ) ) {
				// Array of order IDs.
				foreach ( $order_ids_or_filters as $order_id ) {
					$order = wc_get_order( absint( $order_id ) );
					if ( $order ) {
						$orders[] = $order;
					}
				}
			} elseif ( is_array( $first ) || is_string( $first ) ) {
				// Filter-based export.
				$orders = $this->query_orders_by_filters( $order_ids_or_filters );
			}
		}

		if ( empty( $orders ) ) {
			return '';
		}

		$csv_data = array();

		// Header row.
		$csv_data[] = $this->csv_row( $headers );

		// Data rows.
		foreach ( $orders as $order ) {
			$row = $this->build_order_row( $order, $columns );
			$csv_data[] = $this->csv_row( $row );
		}

		return implode( "\r\n", $csv_data );
	}

	/**
	 * Trigger a CSV download.
	 *
	 * @param string $data     The CSV content.
	 * @param string $filename The download filename (without extension).
	 * @return void
	 */
	public function trigger_download( $data, $filename = 'orders-export' ) {
		if ( empty( $data ) ) {
			wp_die( esc_html__( 'No data to export.', 'easy-order-manager' ) );
		}

		$filename = sanitize_file_name( $filename ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Output UTF-8 BOM for Excel compatibility.
		echo "\xEF\xBB\xBF";
		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Export orders based on current dashboard filter values.
	 *
	 * @param array $filter_values Associative array of filter parameters.
	 * @return void
	 */
	public function export_filtered( $filter_values = array() ) {
		$csv_data = $this->export_orders( $filter_values );
		$this->trigger_download( $csv_data, 'orders-filtered-' . gmdate( 'Y-m-d' ) );
	}

	/**
	 * Export selected orders by IDs.
	 *
	 * @param array $order_ids Array of WooCommerce order IDs.
	 * @return void
	 */
	public function export_selected( $order_ids = array() ) {
		$csv_data = $this->export_orders( $order_ids );
		$this->trigger_download( $csv_data, 'orders-selected-' . gmdate( 'Y-m-d' ) );
	}

	/**
	 * Get all available export columns.
	 *
	 * @return array Associative array of column_key => column_label.
	 */
	public function get_exportable_columns() {
		$columns = array(
			'order_id'      => __( 'Order ID', 'easy-order-manager' ),
			'date'          => __( 'Date', 'easy-order-manager' ),
			'customer_name' => __( 'Customer Name', 'easy-order-manager' ),
			'phone'         => __( 'Phone', 'easy-order-manager' ),
			'email'         => __( 'Email', 'easy-order-manager' ),
			'address'       => __( 'Address', 'easy-order-manager' ),
			'products'      => __( 'Products', 'easy-order-manager' ),
			'quantity'      => __( 'Quantity', 'easy-order-manager' ),
			'total'         => __( 'Total', 'easy-order-manager' ),
			'payment_method' => __( 'Payment Method', 'easy-order-manager' ),
			'status'        => __( 'Status', 'easy-order-manager' ),
			'courier'       => __( 'Courier', 'easy-order-manager' ),
			'tracking_id'   => __( 'Tracking ID', 'easy-order-manager' ),
			'staff'         => __( 'Staff', 'easy-order-manager' ),
			'notes'         => __( 'Notes', 'easy-order-manager' ),
		);

		/**
		 * Filter the exportable columns.
		 *
		 * @param array $columns The default columns.
		 */
		return apply_filters( 'eom_export_columns', $columns );
	}

	/**
	 * Add "Export CSV" button to the dashboard.
	 *
	 * @return void
	 */
	public function add_export_button() {
		if ( ! current_user_can( 'eom_export_data' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<button type="button" class="button button-primary" id="eom-export-csv">
			<span class="dashicons dashicons-download" style="vertical-align:middle;"></span>
			<?php esc_html_e( 'Export CSV', 'easy-order-manager' ); ?>
		</button>
		<?php
	}

	/**
	 * Add export to bulk actions list.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array Modified bulk actions.
	 */
	public function add_export_to_bulk_actions( $actions ) {
		if ( current_user_can( 'eom_export_data' ) || current_user_can( 'manage_woocommerce' ) ) {
			$actions['export_csv'] = __( 'Export CSV', 'easy-order-manager' );
		}
		return $actions;
	}

	/**
	 * AJAX handler: export orders by filter.
	 *
	 * @return void
	 */
	public function ajax_export_csv() {
		check_ajax_referer( 'eom_export_csv' );

		if ( ! current_user_can( 'eom_export_data' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$filter_values = isset( $_POST['filters'] ) ? map_deep( wp_unslash( $_POST['filters'] ), 'sanitize_text_field' ) : array();
		$this->export_filtered( $filter_values );
	}

	/**
	 * AJAX handler: export selected orders.
	 *
	 * @return void
	 */
	public function ajax_export_selected() {
		check_ajax_referer( 'eom_export_selected' );

		if ( ! current_user_can( 'eom_export_data' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$order_ids = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) $_POST['order_ids'] ) : array();
		$this->export_selected( $order_ids );
	}

	/**
	 * Build a CSV row from an array of values.
	 *
	 * @param array $fields Array of field values.
	 * @return string CSV-encoded row.
	 */
	private function csv_row( $fields ) {
		$escaped = array();
		foreach ( $fields as $value ) {
			$value = str_replace( '"', '""', (string) $value );
			if ( preg_match( '/[,"\n\r]/', $value ) ) {
				$value = '"' . $value . '"';
			}
			$escaped[] = $value;
		}
		return implode( ',', $escaped );
	}

	/**
	 * Build a single order data row from the order object.
	 *
	 * @param WC_Order $order   The order object.
	 * @param array    $columns The requested columns (key => label).
	 * @return array Row data matching column keys.
	 */
	private function build_order_row( $order, $columns ) {
		$order_id   = $order->get_id();
		$order_date = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i:s' ) : '';

		$customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
		$phone         = $order->get_billing_phone();
		$email         = $order->get_billing_email();
		$address       = $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() . ', ' . $order->get_billing_city() . ', ' . $order->get_billing_state() . ' - ' . $order->get_billing_postcode();

		// Products.
		$product_names = array();
		$total_qty     = 0;
		foreach ( $order->get_items() as $item ) {
			$product_names[] = $item->get_name() . ' (x' . $item->get_quantity() . ')';
			$total_qty      += $item->get_quantity();
		}
		$products_str = implode( ' | ', $product_names );

		$total         = $order->get_total();
		$payment_method = $order->get_payment_method_title();
		$status        = wc_get_order_status_name( $order->get_status() );
		$courier       = $order->get_meta( 'eom_courier_name', true );
		$tracking_id   = $order->get_meta( 'eom_tracking_id', true );
		$staff_id      = $order->get_meta( 'eom_assigned_staff', true );
		$staff_name    = '';
		if ( $staff_id ) {
			$staff_user = get_userdata( absint( $staff_id ) );
			$staff_name = $staff_user ? $staff_user->display_name : '';
		}
		$notes = $order->get_customer_note();

		$row = array();
		foreach ( array_keys( $columns ) as $key ) {
			switch ( $key ) {
				case 'order_id':
					$row[ $key ] = $order_id;
					break;
				case 'date':
					$row[ $key ] = $order_date;
					break;
				case 'customer_name':
					$row[ $key ] = $customer_name;
					break;
				case 'phone':
					$row[ $key ] = $phone;
					break;
				case 'email':
					$row[ $key ] = $email;
					break;
				case 'address':
					$row[ $key ] = $address;
					break;
				case 'products':
					$row[ $key ] = $products_str;
					break;
				case 'quantity':
					$row[ $key ] = $total_qty;
					break;
				case 'total':
					$row[ $key ] = $total;
					break;
				case 'payment_method':
					$row[ $key ] = $payment_method;
					break;
				case 'status':
					$row[ $key ] = $status;
					break;
				case 'courier':
					$row[ $key ] = $courier;
					break;
				case 'tracking_id':
					$row[ $key ] = $tracking_id;
					break;
				case 'staff':
					$row[ $key ] = $staff_name;
					break;
				case 'notes':
					$row[ $key ] = $notes;
					break;
				default:
					$row[ $key ] = '';
					break;
			}
		}

		return $row;
	}

	/**
	 * Query orders by filter parameters.
	 *
	 * @param array $filters Associative array of filter parameters.
	 * @return WC_Order[] Array of order objects.
	 */
	private function query_orders_by_filters( $filters ) {
		$args = array(
			'limit'  => -1,
			'return' => 'objects',
			'type'   => 'shop_order',
		);

		if ( ! empty( $filters['status'] ) ) {
			$args['status'] = 'wc-' . ltrim( $filters['status'], 'wc-' );
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$args['date_created'] = '>=' . $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			if ( isset( $args['date_created'] ) ) {
				$args['date_created'] .= '..' . $filters['date_to'];
			} else {
				$args['date_created'] = '<=' . $filters['date_to'];
			}
		}

		if ( ! empty( $filters['product'] ) ) {
			$args['include_items'] = array( absint( $filters['product'] ) );
		}

		if ( ! empty( $filters['search'] ) ) {
			$args['s'] = sanitize_text_field( $filters['search'] );
		}

		/**
		 * Filter the query args for CSV export.
		 *
		 * @param array $args    WP_Query args.
		 * @param array $filters The raw filter values.
		 */
		$args = apply_filters( 'eom_export_query_args', $args, $filters );

		return wc_get_orders( $args );
	}
}
