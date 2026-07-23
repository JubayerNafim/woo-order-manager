<?php
/**
 * EOM Team Management
 *
 * Manages custom roles (eom_manager, eom_staff), staff assignment,
 * activity logging, and order view restrictions.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Team_Management
 *
 * Handles team roles, permissions, staff assignment, and activity logging.
 */
class EOM_Team_Management {

	/**
	 * Activity log table name.
	 */
	const ACTIVITY_LOG_TABLE = 'eom_activity_log';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_roles' ) );
		add_action( 'eom_register_capabilities', array( $this, 'register_standard_capabilities' ) );
		add_filter( 'pre_get_posts', array( $this, 'restrict_order_view' ) );
		add_action( 'wp_ajax_eom_assign_staff', array( $this, 'ajax_assign_staff' ) );
		add_action( 'admin_post_eom_assign_staff', array( $this, 'handle_admin_assign_staff' ) );
		add_action( 'wp_ajax_eom_log_activity', array( $this, 'ajax_log_activity' ) );
	}

	/**
	 * Register custom roles and capabilities.
	 *
	 * @return void
	 */
	public function register_roles() {
		// eom_manager role.
		if ( null === get_role( 'eom_manager' ) ) {
			$manager_caps = array(
				'read'                      => true,
				'edit_posts'                => true,
				'manage_woocommerce'        => true,
				'view_woocommerce_reports'  => true,
				'assign_product_terms'      => true,
				'edit_products'             => true,
				'publish_products'          => true,
				'edit_shop_orders'          => true,
				'publish_shop_orders'       => true,
				'read_shop_orders'          => true,
				'delete_shop_orders'        => false,
				'list_users'                => true,
				'promote_users'             => false,
				'remove_users'              => false,
			);

			/**
			 * Filter the eom_manager role capabilities before creation.
			 *
			 * @param array $manager_caps Default capabilities.
			 */
			$manager_caps = apply_filters( 'eom_manager_capabilities', $manager_caps );
			add_role( 'eom_manager', __( 'EOM Manager', 'easy-order-manager' ), $manager_caps );
		}

		// eom_staff role.
		if ( null === get_role( 'eom_staff' ) ) {
			$staff_caps = array(
				'read'                => true,
				'edit_posts'          => false,
				'read_shop_orders'    => true,
				'edit_shop_orders'    => true,
				'publish_shop_orders' => false,
			);

			/**
			 * Filter the eom_staff role capabilities before creation.
			 *
			 * @param array $staff_caps Default capabilities.
			 */
			$staff_caps = apply_filters( 'eom_staff_capabilities', $staff_caps );
			add_role( 'eom_staff', __( 'EOM Staff', 'easy-order-manager' ), $staff_caps );
		}

		// Ensure standard capabilities are registered.
		do_action( 'eom_register_capabilities' );
	}

	/**
	 * Register standard EOM capabilities on existing roles.
	 *
	 * @return void
	 */
	public function register_standard_capabilities() {
		$manager = get_role( 'eom_manager' );
		if ( $manager ) {
			$manager->add_cap( 'eom_view_orders' );
			$manager->add_cap( 'eom_edit_orders' );
			$manager->add_cap( 'eom_book_courier' );
			$manager->add_cap( 'eom_manage_team' );
			$manager->add_cap( 'eom_export_data' );
			$manager->add_cap( 'eom_view_profit' );
		}

		$staff = get_role( 'eom_staff' );
		if ( $staff ) {
			$staff->add_cap( 'eom_view_orders' );
			$staff->add_cap( 'eom_edit_orders' );
			$staff->add_cap( 'eom_book_courier' );
		}
	}

	/**
	 * Get available roles with their capability mappings.
	 *
	 * @return array Associative array of role slug => role data.
	 */
	public function get_roles() {
		$roles = array(
			'eom_manager' => array(
				'name'         => __( 'EOM Manager', 'easy-order-manager' ),
				'capabilities' => array(
					'eom_view_orders'   => __( 'View Orders', 'easy-order-manager' ),
					'eom_edit_orders'   => __( 'Edit Orders', 'easy-order-manager' ),
					'eom_book_courier'  => __( 'Book Courier', 'easy-order-manager' ),
					'eom_manage_team'   => __( 'Manage Team', 'easy-order-manager' ),
					'eom_export_data'   => __( 'Export Data', 'easy-order-manager' ),
					'eom_view_profit'   => __( 'View Profit', 'easy-order-manager' ),
				),
			),
			'eom_staff'   => array(
				'name'         => __( 'EOM Staff', 'easy-order-manager' ),
				'capabilities' => array(
					'eom_view_orders'  => __( 'View Orders', 'easy-order-manager' ),
					'eom_edit_orders'  => __( 'Edit Orders', 'easy-order-manager' ),
					'eom_book_courier' => __( 'Book Courier', 'easy-order-manager' ),
				),
			),
		);

		/**
		 * Filter available EOM roles.
		 *
		 * @param array $roles Default roles.
		 */
		return apply_filters( 'eom_roles', $roles );
	}

	/**
	 * Get all team members with EOM roles.
	 *
	 * @return array Array of WP_User objects with eom roles.
	 */
	public function get_team_members() {
		$roles  = array_keys( $this->get_roles() );
		$members = array();

		foreach ( $roles as $role ) {
			$users = get_users(
				array(
					'role'   => $role,
					'fields' => 'all',
				)
			);
			$members = array_merge( $members, $users );
		}

		return $members;
	}

	/**
	 * Assign a staff member to an order.
	 *
	 * @param int $order_id The order ID.
	 * @param int $staff_id The staff user ID.
	 * @return void
	 */
	public function assign_order_staff( $order_id, $staff_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) {
			return;
		}

		$staff_id = absint( $staff_id );
		$order->update_meta_data( 'eom_assigned_staff', $staff_id );
		$order->save_meta_data();

		$staff_user = get_userdata( $staff_id );
		$staff_name = $staff_user ? $staff_user->display_name : 'Unknown';

		$this->log_activity(
			$staff_id,
			'assigned',
			$order_id,
			sprintf(
				/* translators: %s: staff display name */
				__( 'Order assigned to %s', 'easy-order-manager' ),
				$staff_name
			)
		);
	}

	/**
	 * Get orders assigned to a specific staff member.
	 *
	 * @param int $staff_id The staff user ID.
	 * @return WC_Order[] Array of order objects.
	 */
	public function get_assigned_orders( $staff_id ) {
		$orders = wc_get_orders(
			array(
				'limit'        => -1,
				'return'       => 'objects',
				'meta_key'     => 'eom_assigned_staff', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => absint( $staff_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => '=',
				'type'         => 'shop_order',
			)
		);

		return $orders;
	}

	/**
	 * Log an activity entry.
	 *
	 * @param int    $user_id  The user performing the action.
	 * @param string $action   The action type (e.g., 'assigned', 'status_change', 'courier_booked').
	 * @param int    $order_id The related order ID (0 if none).
	 * @param string $details  Description of the action.
	 * @return void
	 */
	public function log_activity( $user_id, $action, $order_id = 0, $details = '' ) {
		global $wpdb;

		$this->maybe_create_activity_log_table();

		$user = get_userdata( absint( $user_id ) );
		$user_name = $user ? $user->display_name : 'Unknown';

		$wpdb->insert(
			$wpdb->prefix . self::ACTIVITY_LOG_TABLE,
			array(
				'user_id'   => absint( $user_id ),
				'user_name' => sanitize_text_field( $user_name ),
				'action'    => sanitize_text_field( $action ),
				'order_id'  => absint( $order_id ),
				'details'   => sanitize_textarea_field( $details ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Query the activity log with optional filters.
	 *
	 * @param array $args Query arguments: user_id, date_from, date_to, action, limit.
	 * @return array Array of activity log entries.
	 */
	public function get_activity_log( $args = array() ) {
		global $wpdb;

		$this->maybe_create_activity_log_table();

		$table  = $wpdb->prefix . self::ACTIVITY_LOG_TABLE;
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = absint( $args['user_id'] );
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = sanitize_text_field( $args['date_from'] ) . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
		}

		if ( ! empty( $args['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = sanitize_text_field( $args['action'] );
		}

		if ( ! empty( $args['order_id'] ) ) {
			$where[]  = 'order_id = %d';
			$params[] = absint( $args['order_id'] );
		}

		$limit = isset( $args['limit'] ) ? min( absint( $args['limit'] ), 500 ) : 100;

		$where_clause = implode( ' AND ', $where );
		$sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params[] = $limit;

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $results ? $results : array();
	}

	/**
	 * Render the team management admin page.
	 *
	 * @return void
	 */
	public function render_team_page() {
		if ( ! current_user_can( 'eom_manage_team' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'easy-order-manager' ) );
		}

		$members = $this->get_team_members();
		$roles   = $this->get_roles();
		?>
		<div class="wrap eom-team-wrap">
			<h1><?php esc_html_e( 'Team Management', 'easy-order-manager' ); ?></h1>

			<table class="wp-list-table widefat fixed striped" id="eom-team-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User ID', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Name', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Username', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Role', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Assigned Orders', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Last Activity', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'easy-order-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $members ) ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'No team members found.', 'easy-order-manager' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $members as $member ) : ?>
							<?php
							$assigned_orders = $this->get_assigned_orders( $member->ID );
							$last_activity   = $this->get_last_activity( $member->ID );
							$role_names      = array();
							foreach ( $member->roles as $role_slug ) {
								if ( isset( $roles[ $role_slug ] ) ) {
									$role_names[] = $roles[ $role_slug ]['name'];
								}
							}
							$role_display = ! empty( $role_names ) ? implode( ', ', $role_names ) : implode( ', ', $member->roles );
							?>
							<tr>
								<td><?php echo esc_html( $member->ID ); ?></td>
								<td><?php echo esc_html( $member->display_name ); ?></td>
								<td><?php echo esc_html( $member->user_login ); ?></td>
								<td><?php echo esc_html( $role_display ); ?></td>
								<td><?php echo esc_html( count( $assigned_orders ) ); ?></td>
								<td><?php echo $last_activity ? esc_html( $last_activity ) : '—'; ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . $member->ID ) ); ?>" class="button button-small">
										<?php esc_html_e( 'Edit', 'easy-order-manager' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:30px;"><?php esc_html_e( 'Assign Order to Staff', 'easy-order-manager' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eom-assign-form">
				<?php wp_nonce_field( 'eom_assign_staff_action', 'eom_assign_staff_nonce' ); ?>
				<input type="hidden" name="action" value="eom_assign_staff">
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="eom-order-id"><?php esc_html_e( 'Order ID', 'easy-order-manager' ); ?></label>
						</th>
						<td>
							<input type="number" id="eom-order-id" name="order_id" min="1" required>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="eom-staff-select"><?php esc_html_e( 'Staff Member', 'easy-order-manager' ); ?></label>
						</th>
						<td>
							<select id="eom-staff-select" name="staff_id" required>
								<option value=""><?php esc_html_e( 'Select Staff', 'easy-order-manager' ); ?></option>
								<?php foreach ( $members as $member ) : ?>
									<option value="<?php echo esc_attr( $member->ID ); ?>"><?php echo esc_html( $member->display_name ); ?> (<?php echo esc_html( $member->user_login ); ?>)</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Assign Order', 'easy-order-manager' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the activity log admin sub-page.
	 *
	 * @return void
	 */
	public function render_activity_log_page() {
		if ( ! current_user_can( 'eom_manage_team' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'easy-order-manager' ) );
		}

		$user_id   = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		$action    = isset( $_GET['action_type'] ) ? sanitize_text_field( wp_unslash( $_GET['action_type'] ) ) : '';

		$log_args = array(
			'user_id'   => $user_id,
			'date_from' => $date_from,
			'date_to'   => $date_to,
			'action'    => $action,
			'limit'     => 500,
		);

		$log_entries = $this->get_activity_log( $log_args );
		$members     = $this->get_team_members();
		?>
		<div class="wrap eom-activity-log-wrap">
			<h1><?php esc_html_e( 'Activity Log', 'easy-order-manager' ); ?></h1>

			<form method="get" class="eom-log-filters" style="margin:15px 0;">
				<input type="hidden" name="page" value="eom-activity-log">
				<label for="log-user-id"><?php esc_html_e( 'User:', 'easy-order-manager' ); ?></label>
				<select id="log-user-id" name="user_id">
					<option value=""><?php esc_html_e( 'All Users', 'easy-order-manager' ); ?></option>
					<?php foreach ( $members as $member ) : ?>
						<option value="<?php echo esc_attr( $member->ID ); ?>" <?php selected( $user_id, $member->ID ); ?>>
							<?php echo esc_html( $member->display_name ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="log-date-from"><?php esc_html_e( 'From:', 'easy-order-manager' ); ?></label>
				<input type="date" id="log-date-from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">

				<label for="log-date-to"><?php esc_html_e( 'To:', 'easy-order-manager' ); ?></label>
				<input type="date" id="log-date-to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">

				<label for="log-action-type"><?php esc_html_e( 'Action:', 'easy-order-manager' ); ?></label>
				<select id="log-action-type" name="action_type">
					<option value=""><?php esc_html_e( 'All Actions', 'easy-order-manager' ); ?></option>
					<option value="assigned" <?php selected( $action, 'assigned' ); ?>><?php esc_html_e( 'Assigned', 'easy-order-manager' ); ?></option>
					<option value="status_change" <?php selected( $action, 'status_change' ); ?>><?php esc_html_e( 'Status Change', 'easy-order-manager' ); ?></option>
					<option value="courier_booked" <?php selected( $action, 'courier_booked' ); ?>><?php esc_html_e( 'Courier Booked', 'easy-order-manager' ); ?></option>
					<option value="note_added" <?php selected( $action, 'note_added' ); ?>><?php esc_html_e( 'Note Added', 'easy-order-manager' ); ?></option>
				</select>

				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'easy-order-manager' ); ?></button>
			</form>

			<table class="wp-list-table widefat fixed striped" id="eom-activity-log-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'User', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Action', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Order ID', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Details', 'easy-order-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $log_entries ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No activity log entries found.', 'easy-order-manager' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $log_entries as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['created_at'] ); ?></td>
								<td><?php echo esc_html( $entry['user_name'] ); ?></td>
								<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $entry['action'] ) ) ); ?></td>
								<td>
									<?php if ( ! empty( $entry['order_id'] ) ) : ?>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $entry['order_id'] . '&action=edit' ) ); ?>">
											#<?php echo esc_html( $entry['order_id'] ); ?>
										</a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $entry['details'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Restrict order view for staff users.
	 * Staff can only see orders assigned to them.
	 *
	 * @param WP_Query $query The query object.
	 * @return WP_Query Modified query.
	 */
	public function restrict_order_view( $query ) {
		// Only apply in admin area for shop_order post type.
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return $query;
		}

		$post_type = $query->get( 'post_type' );
		if ( 'shop_order' !== $post_type && 'shop_orders' !== $post_type ) {
			return $query;
		}

		$current_user = wp_get_current_user();
		if ( ! $current_user || 0 === $current_user->ID ) {
			return $query;
		}

		// Check if user has staff role.
		if ( ! in_array( 'eom_staff', (array) $current_user->roles, true ) ) {
			return $query;
		}

		// Managers and admins see all orders.
		if ( $current_user->has_cap( 'manage_woocommerce' ) || $current_user->has_cap( 'eom_manage_team' ) ) {
			return $query;
		}

		// Staff only see orders assigned to them.
		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}

		$meta_query[] = array(
			'key'   => 'eom_assigned_staff',
			'value' => $current_user->ID,
		);

		$query->set( 'meta_query', $meta_query );

		return $query;
	}

	/**
	 * Get performance statistics for a staff member.
	 *
	 * @param int    $staff_id   The staff user ID.
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 * @return array Performance stats.
	 */
	public function get_performance_stats( $staff_id, $start_date = '', $end_date = '' ) {
		$stats = array(
			'orders_processed' => 0,
			'actions_taken'    => 0,
		);

		$assigned_orders = $this->get_assigned_orders( $staff_id );
		$stats['orders_processed'] = count( $assigned_orders );

		$log_args = array(
			'user_id'   => $staff_id,
			'date_from' => $start_date,
			'date_to'   => $end_date,
			'limit'     => 1000,
		);

		$log_entries = $this->get_activity_log( $log_args );
		$stats['actions_taken'] = count( $log_entries );

		return $stats;
	}

	/**
	 * Get the last activity datetime for a user.
	 *
	 * @param int $user_id The user ID.
	 * @return string|null Formatted datetime or null.
	 */
	private function get_last_activity( $user_id ) {
		global $wpdb;

		$table = $wpdb->prefix . self::ACTIVITY_LOG_TABLE;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id )
			)
		);

		return $result ? $result : null;
	}

	/**
	 * Admin POST handler: assign staff to order.
	 * Handles the non-AJAX form submission from the team page.
	 *
	 * @return void
	 */
	public function handle_admin_assign_staff() {
		if ( ! isset( $_POST['eom_assign_staff_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['eom_assign_staff_nonce'] ) ), 'eom_assign_staff_action' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'easy-order-manager' ) );
		}

		if ( ! current_user_can( 'eom_manage_team' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'easy-order-manager' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$staff_id = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;

		if ( $order_id && $staff_id ) {
			$this->assign_order_staff( $order_id, $staff_id );
		}

		wp_safe_redirect( add_query_arg( 'staff_assigned', $order_id, wp_get_referer() ?: admin_url( 'admin.php?page=eom-team' ) ) );
		exit;
	}

	/**
	 * AJAX handler: assign staff to order.
	 *
	 * @return void
	 */
	public function ajax_assign_staff() {
		check_ajax_referer( 'eom_assign_staff_action', 'eom_assign_staff_nonce' );

		if ( ! current_user_can( 'eom_manage_team' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$staff_id = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;

		if ( ! $order_id || ! $staff_id ) {
			wp_send_json_error( __( 'Missing order ID or staff ID.', 'easy-order-manager' ) );
		}

		$this->assign_order_staff( $order_id, $staff_id );
		wp_send_json_success( __( 'Staff assigned successfully.', 'easy-order-manager' ) );
	}

	/**
	 * AJAX handler: log an activity entry.
	 *
	 * @return void
	 */
	public function ajax_log_activity() {
		check_ajax_referer( 'eom_log_activity' );

		if ( ! current_user_can( 'eom_view_orders' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$action   = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$details  = isset( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '';

		if ( empty( $action ) ) {
			wp_send_json_error( __( 'Action type is required.', 'easy-order-manager' ) );
		}

		$this->log_activity( get_current_user_id(), $action, $order_id, $details );
		wp_send_json_success( __( 'Activity logged.', 'easy-order-manager' ) );
	}

	/**
	 * Ensure the activity log table exists.
	 *
	 * @return void
	 */
	private function maybe_create_activity_log_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::ACTIVITY_LOG_TABLE;
		$charset    = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT UNSIGNED NOT NULL,
			user_name VARCHAR(255) NOT NULL DEFAULT '',
			action VARCHAR(100) NOT NULL DEFAULT '',
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			details TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_user (user_id),
			INDEX idx_action (action),
			INDEX idx_order (order_id),
			INDEX idx_created (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
