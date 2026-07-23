<?php
/**
 * EOM Order List
 *
 * Adds custom columns (Courier, Tracking, Staff) to the native WooCommerce
 * orders list and adds EOM-specific bulk actions to the WP admin orders screen.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Order_List
 *
 * Hooks into manage_edit-shop_order_columns, manage_shop_order_posts_custom_column,
 * and bulk_actions-edit-shop_order to augment the native order list.
 */
class EOM_Order_List {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_eom_columns' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_eom_columns' ), 20, 2 );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_action_eom' ), 20 );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_action_eom' ), 20, 3 );
	}

	/**
	 * Add custom columns to the WooCommerce orders list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_eom_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$new_columns['eom_courier']  = __( 'Courier', 'easy-order-manager' );
				$new_columns['eom_tracking'] = __( 'Tracking', 'easy-order-manager' );
				$new_columns['eom_staff']    = __( 'Staff', 'easy-order-manager' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render content for custom EOM columns.
	 *
	 * @param string $column   Column identifier.
	 * @param int    $order_id Order ID.
	 * @return void
	 */
	public function render_eom_columns( $column, $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		switch ( $column ) {
			case 'eom_courier':
				$courier = $order->get_meta( 'eom_courier_name', true );
				if ( $courier ) {
					echo '<span class="eom-col-value">' . esc_html( ucfirst( $courier ) ) . '</span>';
				} else {
					echo '<span class="eom-col-empty" style="color:#999;">&mdash;</span>';
				}
				break;

			case 'eom_tracking':
				$tracking_id = $order->get_meta( 'eom_tracking_id', true );
				if ( $tracking_id ) {
					echo '<span class="eom-col-value">' . esc_html( $tracking_id ) . '</span>';
				} else {
					echo '<span class="eom-col-empty" style="color:#999;">&mdash;</span>';
				}
				break;

			case 'eom_staff':
				$staff_id = $order->get_meta( 'eom_assigned_staff', true );
				if ( $staff_id ) {
					$staff_user = get_userdata( absint( $staff_id ) );
					if ( $staff_user ) {
						echo '<span class="eom-col-value">' . esc_html( $staff_user->display_name ) . '</span>';
					} else {
						echo '<span class="eom-col-empty" style="color:#999;">&mdash;</span>';
					}
				} else {
					echo '<span class="eom-col-empty" style="color:#999;">&mdash;</span>';
				}
				break;
		}
	}

	/**
	 * Add EOM-specific bulk actions to the native orders list.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array Modified actions.
	 */
	public function add_bulk_action_eom( $actions ) {
		$actions['eom_change_status'] = __( 'EOM: Change Status', 'easy-order-manager' );
		$actions['eom_assign_staff']  = __( 'EOM: Assign Staff', 'easy-order-manager' );
		$actions['eom_book_courier']  = __( 'EOM: Book Courier', 'easy-order-manager' );
		$actions['eom_export_csv']    = __( 'EOM: Export CSV', 'easy-order-manager' );
		return $actions;
	}

	/**
	 * Handle the EOM bulk actions from the native orders list.
	 *
	 * @param string $redirect_to URL to redirect to after action.
	 * @param string $action      The action being performed.
	 * @param array  $post_ids    Array of post/order IDs.
	 * @return string Redirect URL with status parameters.
	 */
	public function handle_bulk_action_eom( $redirect_to, $action, $post_ids ) {
		if ( ! in_array( $action, array( 'eom_change_status', 'eom_assign_staff', 'eom_book_courier', 'eom_export_csv' ), true ) ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return add_query_arg( 'eom_bulk_error', 'permission', $redirect_to );
		}

		$order_ids = array_map( 'absint', $post_ids );

		switch ( $action ) {
			case 'eom_change_status':
				$redirect_to = add_query_arg(
					array(
						'page'      => 'eom-dashboard',
						'order_ids' => implode( ',', $order_ids ),
						'bulk'      => 'change_status',
					),
					admin_url( 'admin.php' )
				);
				break;

			case 'eom_assign_staff':
				$redirect_to = add_query_arg(
					array(
						'page'      => 'eom-dashboard',
						'order_ids' => implode( ',', $order_ids ),
						'bulk'      => 'assign_staff',
					),
					admin_url( 'admin.php' )
				);
				break;

			case 'eom_book_courier':
				$redirect_to = add_query_arg(
					array(
						'page'      => 'eom-dashboard',
						'order_ids' => implode( ',', $order_ids ),
						'bulk'      => 'book_courier',
					),
					admin_url( 'admin.php' )
				);
				break;

			case 'eom_export_csv':
				$dashboard = new EOM_Admin_Bulk_Actions();
				$dashboard->process_export_csv( $order_ids );
				break;
		}

		return $redirect_to;
	}
}
