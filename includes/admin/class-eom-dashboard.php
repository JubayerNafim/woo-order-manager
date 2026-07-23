<?php
/**
 * EOM Admin Dashboard
 *
 * Renders the main order dashboard page with server-side DataTable processing.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Admin_Dashboard
 *
 * Handles rendering and AJAX data for the main dashboard order list.
 */
class EOM_Admin_Dashboard {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_eom_get_orders', array( $this, 'get_orders_data' ) );
		add_action( 'wp_ajax_eom_get_steadfast_status', array( $this, 'ajax_get_steadfast_status' ) );
	}

	/**
	 * Render the full dashboard page HTML.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		$version = defined( 'EOM_VERSION' ) ? EOM_VERSION : '1.0.0';
		?>
		<div class="wrap eom-dashboard-wrap">
			<h1><?php echo esc_html__( 'Easy Order Manager', 'easy-order-manager' ); ?> <span class="eom-version">v<?php echo esc_html( $version ); ?></span></h1>

			<?php
			if ( class_exists( 'EOM_Admin_Filters' ) ) {
				$filters = new EOM_Admin_Filters();
				$filters->render_filter_bar();
			}
			?>

			<div class="eom-status-summary">
				<?php $this->render_status_summary(); ?>
			</div>

			<div class="eom-bulk-action-bar">
				<select id="eom-bulk-action-select">
					<option value=""><?php esc_html_e( 'Bulk Actions', 'easy-order-manager' ); ?></option>
					<?php
					if ( class_exists( 'EOM_Admin_Bulk_Actions' ) ) {
						$bulk_actions = new EOM_Admin_Bulk_Actions();
						foreach ( $bulk_actions->get_bulk_actions() as $slug => $label ) {
							echo '<option value="' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</option>';
						}
					}
					?>
				</select>
				<button type="button" class="button" id="eom-apply-bulk-action">
					<?php esc_html_e( 'Apply', 'easy-order-manager' ); ?>
				</button>
				<span class="eom-bulk-count">
					<?php esc_html_e( 'Selected:', 'easy-order-manager' ); ?> <span id="eom-selected-count">0</span>
				</span>
			</div>

			<?php
			// Render the Steadfast import UI.
			if ( class_exists( 'EOM_Steadfast_Import' ) ) {
				$import = new EOM_Steadfast_Import();
				$import->render_import_ui();
			}
			?>

			<div class="eom-table-container">
				<table id="eom-orders-table" class="display responsive nowrap" style="width:100%">
					<thead>
						<tr>
							<th class="eom-col-cb"><input type="checkbox" id="eom-select-all"></th>
							<th class="eom-col-id"><?php esc_html_e( 'Order ID', 'easy-order-manager' ); ?></th>
							<th class="eom-col-date"><?php esc_html_e( 'Date', 'easy-order-manager' ); ?></th>
							<th class="eom-col-customer"><?php esc_html_e( 'Customer Name', 'easy-order-manager' ); ?></th>
							<th class="eom-col-phone"><?php esc_html_e( 'Phone', 'easy-order-manager' ); ?></th>
							<th class="eom-col-email"><?php esc_html_e( 'Email', 'easy-order-manager' ); ?></th>
							<th class="eom-col-products"><?php esc_html_e( 'Products', 'easy-order-manager' ); ?></th>
							<th class="eom-col-total"><?php esc_html_e( 'Total', 'easy-order-manager' ); ?></th>
							<th class="eom-col-payment"><?php esc_html_e( 'Payment Method', 'easy-order-manager' ); ?></th>
							<th class="eom-col-status"><?php esc_html_e( 'Status', 'easy-order-manager' ); ?></th>
							<th class="eom-col-sf-status"><?php esc_html_e( 'Del. Status', 'easy-order-manager' ); ?> <span class="eom-sf-refresh-all" title="<?php esc_attr_e( 'Refresh all delivery statuses', 'easy-order-manager' ); ?>" style="cursor:pointer; font-size:14px;">&#x21bb;</span></th>
								<th class="eom-col-courier"><?php esc_html_e( 'Courier', 'easy-order-manager' ); ?></th>
							<th class="eom-col-consignment"><?php esc_html_e( 'Consignment', 'easy-order-manager' ); ?></th>
							<th class="eom-col-tracking"><?php esc_html_e( 'Tracking', 'easy-order-manager' ); ?></th>
								<th class="eom-col-charge"><?php esc_html_e( 'Del. Charge', 'easy-order-manager' ); ?></th>
								<th class="eom-col-cod-fee"><?php esc_html_e( 'COD Fee', 'easy-order-manager' ); ?></th>
								<th class="eom-col-staff"><?php esc_html_e( 'Assigned Staff', 'easy-order-manager' ); ?></th>
							<th class="eom-col-actions"><?php esc_html_e( 'Actions', 'easy-order-manager' ); ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>

			<div id="eom-inline-edit-modal" class="eom-modal" style="display:none;"></div>
			<div id="eom-courier-modal" class="eom-modal" style="display:none;"></div>
			<div id="eom-sms-modal" class="eom-modal" style="display:none;"></div>
		</div>

		
		<?php
	}

	/**
	 * Render status summary bar showing counts per status.
	 *
	 * @return void
	 */
	private function render_status_summary() {
		$statuses      = wc_get_order_statuses();
		$status_counts = (array) wp_count_posts( 'shop_order' );

		foreach ( $statuses as $slug => $label ) {
			$count = isset( $status_counts[ $slug ] ) ? (int) $status_counts[ $slug ] : 0;
			if ( $count < 1 ) {
				continue;
			}
			$display_slug = str_replace( 'wc-', '', $slug );
			$color        = $this->get_status_color( $display_slug );
			?>
			<span class="eom-status-pill" data-status="<?php echo esc_attr( $display_slug ); ?>"
				style="background:<?php echo esc_attr( $color ); ?>; color:#fff; padding:4px 12px; border-radius:12px; cursor:pointer; display:inline-block; margin:2px 4px; font-size:13px;">
				<?php echo esc_html( $label ); ?>: <?php echo esc_html( $count ); ?>
			</span>
			<?php
		}
	}

	/**
	 * AJAX handler -- returns JSON for DataTable (server-side processing).
	 *
	 * @return void
	 */
	public function get_orders_data() {
		check_ajax_referer( 'eom_get_orders' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$draw            = isset( $_POST['draw'] ) ? absint( $_POST['draw'] ) : 1;
		$start           = isset( $_POST['start'] ) ? absint( $_POST['start'] ) : 0;
		$length          = isset( $_POST['length'] ) ? absint( $_POST['length'] ) : 25;
		$search_value    = isset( $_POST['search']['value'] ) ? sanitize_text_field( wp_unslash( $_POST['search']['value'] ) ) : '';
		$order_column    = isset( $_POST['order'][0]['column'] ) ? absint( $_POST['order'][0]['column'] ) : 1;
		$order_dir       = isset( $_POST['order'][0]['dir'] ) ? sanitize_text_field( wp_unslash( $_POST['order'][0]['dir'] ) ) : 'DESC';
		$status_filter   = isset( $_POST['eom_filter_status'] ) ? sanitize_text_field( wp_unslash( $_POST['eom_filter_status'] ) ) : '';
		$date_from       = isset( $_POST['eom_filter_date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['eom_filter_date_from'] ) ) : '';
		$date_to         = isset( $_POST['eom_filter_date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['eom_filter_date_to'] ) ) : '';
		$product_filter  = isset( $_POST['eom_filter_product'] ) ? sanitize_text_field( wp_unslash( $_POST['eom_filter_product'] ) ) : '';
		$category_filter = isset( $_POST['eom_filter_category'] ) ? absint( $_POST['eom_filter_category'] ) : 0;
		$courier_filter  = isset( $_POST['eom_filter_courier'] ) ? sanitize_text_field( wp_unslash( $_POST['eom_filter_courier'] ) ) : '';
		$staff_filter    = isset( $_POST['eom_filter_staff'] ) ? absint( $_POST['eom_filter_staff'] ) : 0;
		$payment_filter  = isset( $_POST['eom_filter_payment'] ) ? sanitize_text_field( wp_unslash( $_POST['eom_filter_payment'] ) ) : '';

		$args = array(
			'limit'    => $length,
			'offset'   => $start,
			'return'   => 'ids',
			'orderby'  => 'date',
			'order'    => 'DESC',
			'paginate' => true,
		);

		$columns_map = array( 1 => 'ID', 2 => 'date', 3 => 'billing_first_name', 7 => 'total', 9 => 'status' );
		if ( isset( $columns_map[ $order_column ] ) ) {
			$args['orderby'] = $columns_map[ $order_column ];
			$args['order']   = 'asc' === strtolower( $order_dir ) ? 'ASC' : 'DESC';
		}

		if ( ! empty( $status_filter ) ) {
			$args['status'] = array( 'wc-' === substr( $status_filter, 0, 3 ) ? $status_filter : 'wc-' . $status_filter );
		}

		if ( ! empty( $date_from ) ) {
			$args['date_created'] = '>=' . $date_from;
		}
		if ( ! empty( $date_to ) ) {
			$date_to_end = $date_to . ' 23:59:59';
			$args['date_created'] = isset( $args['date_created'] ) ? $args['date_created'] . ' ' . $date_to_end : '<=' . $date_to_end;
		}

		if ( ! empty( $search_value ) ) {
			if ( is_numeric( $search_value ) ) {
				$args['post__in'] = array( absint( $search_value ) );
			} else {
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array( 'key' => '_billing_first_name', 'value' => $search_value, 'compare' => 'LIKE' ),
					array( 'key' => '_billing_last_name', 'value' => $search_value, 'compare' => 'LIKE' ),
					array( 'key' => '_billing_phone', 'value' => $search_value, 'compare' => 'LIKE' ),
					array( 'key' => '_billing_email', 'value' => $search_value, 'compare' => 'LIKE' ),
				);
			}
		}

		if ( ! empty( $product_filter ) ) {
			$args['product_id'] = absint( $product_filter );
		}
		if ( ! empty( $category_filter ) ) {
			$args['category'] = array( absint( $category_filter ) );
		}

		foreach ( array(
			array( 'key' => 'eom_courier_name', 'val' => $courier_filter, 'cond' => ! empty( $courier_filter ) ),
			array( 'key' => 'eom_assigned_staff', 'val' => $staff_filter, 'cond' => ! empty( $staff_filter ) ),
			array( 'key' => '_payment_method', 'val' => $payment_filter, 'cond' => ! empty( $payment_filter ) ),
		) as $mq ) {
			if ( $mq['cond'] ) {
				$item = array( 'key' => $mq['key'], 'value' => $mq['val'] );
				if ( isset( $args['meta_query'] ) ) {
					$args['meta_query'][] = $item;
				} else {
					$args['meta_query'] = array( $item ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				}
			}
		}

		$result = wc_get_orders( $args );

		$data = array();
		foreach ( $result->orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$date_str = '';
			$date_created = $order->get_date_created();
			if ( $date_created ) {
				$date_str = $date_created->date_i18n( 'Y-m-d H:i' );
			}

			$items = $order->get_items();
			$product_list = array();
			foreach ( $items as $item ) {
				$product_list[] = $item->get_name() . ' x' . $item->get_quantity();
			}
			$products_str = implode( ', ', array_slice( $product_list, 0, 3 ) );
			if ( count( $product_list ) > 3 ) {
				$products_str .= '...';
			}

			$assigned_staff = $order->get_meta( 'eom_assigned_staff', true );
			$staff_name     = '';
			if ( $assigned_staff ) {
				$staff_user = get_userdata( absint( $assigned_staff ) );
				$staff_name = $staff_user ? $staff_user->display_name : '';
			}

			$status       = $order->get_status();
			$status_color = $this->get_status_color( $status );
			$edit_link    = admin_url( 'post.php?post=' . $order_id . '&action=edit' );

			$data[] = array(
				'checkbox'       => '<input type="checkbox" class="eom-order-checkbox" value="' . esc_attr( $order_id ) . '">',
				'order_id'       => '<a href="' . esc_url( $edit_link ) . '" target="_blank">#' . esc_html( $order_id ) . '</a>',
				'date'           => esc_html( $date_str ),
				'customer_name'  => esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ),
				'phone'          => esc_html( $order->get_billing_phone() ),
				'email'          => esc_html( $order->get_billing_email() ),
				'products'       => esc_html( $products_str ),
				'total'          => wp_kses_post( wc_price( $order->get_total() ) ),
				'payment_method' => esc_html( $order->get_payment_method_title() ),
				'status'         => '<span class="eom-status-badge" style="background:' . esc_attr( $status_color ) . '; color:#fff; padding:2px 8px; border-radius:10px; font-size:12px;">' . esc_html( wc_get_order_status_name( $status ) ) . '</span>',
				'courier'        => esc_html( $order->get_meta( 'eom_courier_name', true ) ),
				'consignment_id' => esc_html( $order->get_meta( 'eom_consignment_id', true ) ),
				'tracking_id'    => $this->format_tracking_link( $order_id ),
					'delivery_charge' => esc_html( $order->get_meta( 'eom_courier_charge', true ) ? number_format( (float) $order->get_meta( 'eom_courier_charge', true ), 0 ) . ' BDT' : '' ),
					'cod_fee'        => esc_html( $order->get_meta( 'eom_courier_cod_fee', true ) ? number_format( (float) $order->get_meta( 'eom_courier_cod_fee', true ), 0 ) . ' BDT' : '' ),
					'assigned_staff' => esc_html( $staff_name ),
					'steadfast_status' => $this->format_steadfast_status( $order_id ),
					'actions'        => $this->get_action_buttons( $order_id ),
			);
		}

		wp_send_json( array(
			'draw'            => $draw,
			'recordsTotal'    => $result->total,
			'recordsFiltered' => $result->total,
			'data'            => $data,
		) );
	}

	/**
	 * Render action buttons for a single order row.
	 *
	 * @param int $order_id Order ID.
	 * @return string HTML of action buttons.
	 */
	private function get_action_buttons( $order_id ) {
		$edit_link = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		ob_start();
		?>
		<div class="eom-action-buttons">
			<a href="<?php echo esc_url( $edit_link ); ?>" class="button button-small" target="_blank"><?php esc_html_e( 'View', 'easy-order-manager' ); ?></a>
			<a href="<?php echo esc_url( $edit_link ); ?>" class="button button-small" target="_blank"><?php esc_html_e( 'Edit', 'easy-order-manager' ); ?></a>
			<button type="button" class="button button-small eom-inline-edit-btn" data-order-id="<?php echo esc_attr( $order_id ); ?>"><?php esc_html_e( 'Quick Edit', 'easy-order-manager' ); ?></button>
			<button type="button" class="button button-small eom-print-btn" data-order-id="<?php echo esc_attr( $order_id ); ?>"><?php esc_html_e( 'Print', 'easy-order-manager' ); ?></button>
			<button type="button" class="button button-small eom-block-customer-btn" data-order-id="<?php echo esc_attr( $order_id ); ?>"><?php esc_html_e( 'Block', 'easy-order-manager' ); ?></button>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get a color hex code for a given order status.
	 *
	 * @param string $status Order status slug.
	 * @return string Hex color.
	 */
	private function get_status_color( $status ) {
		$colors = array(
			'pending'        => '#f59e0b',
			'processing'     => '#3b82f6',
			'on-hold'        => '#ef4444',
			'completed'      => '#10b981',
			'cancelled'      => '#6b7280',
			'refunded'       => '#8b5cf6',
			'failed'         => '#dc2626',
			'draft'          => '#9ca3af',
			'checkout-draft' => '#9ca3af',
		);
		return isset( $colors[ $status ] ) ? $colors[ $status ] : '#6b7280';
	}

	/**
	 * Format tracking ID as a clickable link with courier-specific URL.
	 *
	 * @param int $order_id Order ID.
	 * @return string HTML link or empty string.
	 */
	private function format_tracking_link( int $order_id ): string {
		$tracking_id = get_post_meta( $order_id, 'eom_tracking_id', true );
		if ( empty( $tracking_id ) ) {
			return '';
		}

		$courier_slug = get_post_meta( $order_id, 'eom_courier_name', true );
		$tracking_url = get_post_meta( $order_id, 'eom_courier_tracking_url', true );

		// If we have a stored tracking URL, use it.
		if ( ! empty( $tracking_url ) ) {
			return '<a href="' . esc_url( $tracking_url ) . '" target="_blank" title="' . esc_attr__( 'Track parcel', 'easy-order-manager' ) . '">' . esc_html( $tracking_id ) . ' &rarr;</a>';
		}

		// Otherwise generate based on courier.
		$urls = array(
			'steadfast' => 'https://track.steadfast.com.bd/',
			'pathao'    => 'https://pathao.com/track/',
			'redx'      => 'https://redx.com.bd/tracking/',
		);

		if ( ! empty( $courier_slug ) && isset( $urls[ $courier_slug ] ) ) {
			$url = $urls[ $courier_slug ] . urlencode( $tracking_id );
			return '<a href="' . esc_url( $url ) . '" target="_blank" title="' . esc_attr__( 'Track parcel', 'easy-order-manager' ) . '">' . esc_html( $tracking_id ) . ' &rarr;</a>';
		}

		// Fallback: plain text.
		return esc_html( $tracking_id );
		}

	/**
	 * Format the Steadfast delivery status as a colored badge.
	 *
	 * Only shows for orders booked with Steadfast courier.
	 * Returns empty string for non-Steadfast orders.
	 *
	 * @param int $order_id Order ID.
	 * @return string HTML badge or empty string.
	 */
	private function format_steadfast_status( int $order_id ): string {
		$courier_name = get_post_meta( $order_id, 'eom_courier_name', true );
		if ( 'steadfast' !== $courier_name ) {
			return '';
		}

		$status = get_post_meta( $order_id, 'eom_steadfast_delivery_status', true );
		if ( empty( $status ) ) {
			$status = get_post_meta( $order_id, 'eom_courier_status', true );
		}
		if ( empty( $status ) ) {
			return '<span class="eom-sf-status" style="color:#999;">' . esc_html__( 'Pending', 'easy-order-manager' ) . '</span>';
		}

		$colors = array(
			'delivered'                     => '#10b981',
			'partial_delivered'             => '#3b82f6',
			'delivered_approval_pending'    => '#f59e0b',
			'partial_delivered_approval_pending' => '#f59e0b',
			'cancelled_approval_pending'    => '#ef4444',
			'unknown_approval_pending'      => '#9ca3af',
			'cancelled'                     => '#ef4444',
			'hold'                          => '#f97316',
			'in_review'                     => '#3b82f6',
			'pending'                       => '#f59e0b',
			'unknown'                       => '#9ca3af',
		);

		$color   = isset( $colors[ $status ] ) ? $colors[ $status ] : '#6b7280';
		$label   = ucwords( str_replace( '_', ' ', $status ) );

		return '<div class="eom-sf-status-wrap" style="display:inline-flex; align-items:center; gap:4px;">' .
				'<span class="eom-sf-status" data-order-id="' . esc_attr( $order_id ) . '" style="background:' . esc_attr( $color ) . '; color:#fff; padding:2px 8px; border-radius:10px; font-size:12px; display:inline-block; cursor:pointer;" title="' . esc_attr__( 'Click to refresh', 'easy-order-manager' ) . '">' . esc_html( $label ) . '</span>' .
				'<span class="eom-sf-refresh-single" data-order-id="' . esc_attr( $order_id ) . '" style="cursor:pointer; font-size:12px; color:#666;" title="' . esc_attr__( 'Refresh status', 'easy-order-manager' ) . '">&#x21bb;</span>' .
			'</div>';
	}

	/**
	 * AJAX handler: fetch the latest Steadfast delivery status for an order.
	 *
	 * Receives order_id via POST, calls Steadfast API, stores the result,
	 * and returns a formatted status badge.
	 *
	 * @return void
	 */
	public function ajax_get_steadfast_status() {
		check_ajax_referer( 'eom_get_steadfast_status' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-order-manager' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'easy-order-manager' ) ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'easy-order-manager' ) ) );
		}

		// Verify this is a Steadfast order.
		$courier_name = $order->get_meta( 'eom_courier_name', true );
		if ( 'steadfast' !== $courier_name ) {
			wp_send_json_error( array( 'message' => __( 'Not a Steadfast order.', 'easy-order-manager' ) ) );
		}

		$consignment_id = $order->get_meta( 'eom_consignment_id', true );
		if ( empty( $consignment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No consignment ID found.', 'easy-order-manager' ) ) );
		}

		// Get the Steadfast courier instance.
		$manager = EOM_Courier_Manager::instance();
		$courier = $manager->get_courier( 'steadfast' );
		if ( ! $courier ) {
			wp_send_json_error( array( 'message' => __( 'Steadfast courier not configured.', 'easy-order-manager' ) ) );
		}

		// Resolve the merchant ID used for booking.
		// 1) First check the dedicated meta (set by new bookings).
		$merchant_id = $order->get_meta( 'eom_steadfast_merchant_id', true );

		// 2) Fallback: parse merchant_id from the bookings table response_data (for older orders).
			if ( empty( $merchant_id ) || 'default' === $merchant_id ) {
		 $booking = $manager->get_booking( $order_id );
		 if ( $booking && ! empty( $booking['response_data'] ) ) {
					$response_data = json_decode( $booking['response_data'], true );
					if ( isset( $response_data['merchant_id'] ) && 'default' !== $response_data['merchant_id'] ) {
						$merchant_id = $response_data['merchant_id'];
					}
				}
			}

			if ( ! empty( $merchant_id ) && 'default' !== $merchant_id ) {
				$courier->set_merchant( $merchant_id );
			}

			// Call the Steadfast API to get the latest delivery status.
			$result = $courier->track_parcel( $consignment_id );

		// Reset merchant (good practice even though this instance is request-scoped).
		$courier->reset_merchant();

		if ( ! $result['success'] ) {
			$error_msg = isset( $result['error'] ) ? $result['error'] : __( 'Failed to fetch status.', 'easy-order-manager' );
			wp_send_json_error( array( 'message' => $error_msg ) );
		}

		$delivery_status = isset( $result['delivery_status'] ) ? $result['delivery_status'] : '';
		if ( empty( $delivery_status ) ) {
			wp_send_json_error( array( 'message' => __( 'Empty status response from API.', 'easy-order-manager' ) ) );
		}

		// Store the updated status in order meta.
		$order->update_meta_data( 'eom_steadfast_delivery_status', $delivery_status );
		$order->update_meta_data( 'eom_courier_status', $delivery_status );
		$order->save();

		// Also update the bookings table.
		$manager->update_booking( $order_id, array( 'status' => $delivery_status ) );

		// Return the formatted status badge.
		$badge = '';
		$colors = array(
			'delivered'                     => '#10b981',
			'partial_delivered'             => '#3b82f6',
			'delivered_approval_pending'    => '#f59e0b',
			'partial_delivered_approval_pending' => '#f59e0b',
			'cancelled_approval_pending'    => '#ef4444',
			'unknown_approval_pending'      => '#9ca3af',
			'cancelled'                     => '#ef4444',
			'hold'                          => '#f97316',
			'in_review'                     => '#3b82f6',
			'pending'                       => '#f59e0b',
			'unknown'                       => '#9ca3af',
		);
		$color = isset( $colors[ $delivery_status ] ) ? $colors[ $delivery_status ] : '#6b7280';
		$label = ucwords( str_replace( '_', ' ', $delivery_status ) );
		$badge = '<div class="eom-sf-status-wrap" style="display:inline-flex; align-items:center; gap:4px;">' .
				'<span class="eom-sf-status" data-order-id="' . esc_attr( $order_id ) . '" style="background:' . esc_attr( $color ) . '; color:#fff; padding:2px 8px; border-radius:10px; font-size:12px; display:inline-block; cursor:pointer;" title="' . esc_attr__( 'Click to refresh', 'easy-order-manager' ) . '">' . esc_html( $label ) . '</span>' .
				'<span class="eom-sf-refresh-single" data-order-id="' . esc_attr( $order_id ) . '" style="cursor:pointer; font-size:12px; color:#666;" title="' . esc_attr__( 'Refresh status', 'easy-order-manager' ) . '">&#x21bb;</span>' .
			'</div>';

		wp_send_json_success( array(
			'status' => $delivery_status,
			'badge'  => $badge,
			'label'  => $label,
		) );
	}
	}
