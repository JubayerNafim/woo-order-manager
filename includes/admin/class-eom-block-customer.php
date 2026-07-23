<?php
/**
 * EOM Block Customer
 *
 * Allows blocking customers from placing new orders. Stores blocked
 * customer email/phone in plugin options, provides AJAX blocking from
 * the dashboard, validates at checkout, and provides an admin
 * management page.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Block_Customer
 *
 * Handles customer blocking: AJAX block/unblock, checkout validation,
 * and admin management UI.
 */
class EOM_Block_Customer {

	/**
	 * Options key where blocked customer data is stored.
	 */
	const BLOCKED_OPTION = 'eom_blocked_customers';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_eom_block_customer', array( $this, 'ajax_block_customer' ) );
		add_action( 'wp_ajax_eom_unblock_customer', array( $this, 'ajax_unblock_customer' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_page' ), 30 );
	}

	/**
	 * Get all blocked customers.
	 *
	 * @return array Array of blocked customer records.
	 */
	public static function get_blocked_customers(): array {
		$blocked = get_option( self::BLOCKED_OPTION, array() );
		return is_array( $blocked ) ? $blocked : array();
	}

	/**
	 * Check if a customer is blocked by email or phone.
	 *
	 * @param string $email Customer email.
	 * @param string $phone Customer phone.
	 * @return array|false Blocked record if found, false otherwise.
	 */
	public static function is_blocked( string $email = '', string $phone = '' ) {
		$blocked = self::get_blocked_customers();
		if ( empty( $blocked ) ) {
			return false;
		}

		$email_clean = strtolower( trim( $email ) );
		$phone_clean = preg_replace( '/[^0-9]/', '', $phone );

		foreach ( $blocked as $record ) {
			$record_email = strtolower( trim( $record['email'] ?? '' ) );
			$record_phone = preg_replace( '/[^0-9]/', '', $record['phone'] ?? '' );

			if ( ! empty( $email_clean ) && $email_clean === $record_email ) {
				return $record;
			}
			if ( ! empty( $phone_clean ) && $phone_clean === $record_phone ) {
				return $record;
			}
		}

		return false;
	}

	/**
	 * Add a customer to the blocklist.
	 *
	 * @param int    $order_id       Order ID the block was triggered from.
	 * @param string $customer_email Customer email.
	 * @param string $customer_phone Customer phone.
	 * @param string $customer_name  Customer name.
	 * @return bool True on success.
	 */
	public function block_customer( int $order_id, string $customer_email, string $customer_phone, string $customer_name = '' ): bool {
		$blocked = self::get_blocked_customers();

		$email_clean = strtolower( trim( $customer_email ) );
		$phone_clean = preg_replace( '/[^0-9]/', '', $customer_phone );

		// Avoid duplicates.
		foreach ( $blocked as $record ) {
			$record_email = strtolower( trim( $record['email'] ?? '' ) );
			$record_phone = preg_replace( '/[^0-9]/', '', $record['phone'] ?? '' );

			if ( ( ! empty( $email_clean ) && $email_clean === $record_email ) ||
				 ( ! empty( $phone_clean ) && $phone_clean === $record_phone ) ) {
				return true; // Already blocked.
			}
		}

		$blocked[] = array(
			'email'         => $customer_email,
			'phone'         => $customer_phone,
			'name'          => $customer_name,
			'blocked_from'  => $order_id,
			'blocked_at'    => current_time( 'mysql' ),
			'blocked_by'    => get_current_user_id(),
		);

		update_option( self::BLOCKED_OPTION, $blocked );
		return true;
	}

	/**
	 * Remove a customer from the blocklist.
	 *
	 * @param string $email Email to unblock.
	 * @return bool True if found and removed.
	 */
	public function unblock_customer( string $email ): bool {
		$blocked = self::get_blocked_customers();
		$found   = false;

		foreach ( $blocked as $index => $record ) {
			if ( strtolower( trim( $record['email'] ?? '' ) ) === strtolower( trim( $email ) ) ) {
				array_splice( $blocked, $index, 1 );
				$found = true;
				break;
			}
		}

		if ( $found ) {
			update_option( self::BLOCKED_OPTION, $blocked );
		}

		return $found;
	}

	/**
	 * AJAX handler: block a customer from an order.
	 *
	 * @return void
	 */
	public function ajax_block_customer() {
		check_ajax_referer( 'eom_block_customer' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( __( 'Invalid order ID.', 'easy-order-manager' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( __( 'Order not found.', 'easy-order-manager' ) );
		}

		$email = $order->get_billing_email();
		$phone = $order->get_billing_phone();
		$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		$this->block_customer( $order_id, $email, $phone, $name );

		// Also mark this order meta.
		$order->update_meta_data( '_eom_customer_blocked', 'yes' );
		$order->save();

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %s: customer name */
				__( 'Customer "%s" has been blocked from placing new orders.', 'easy-order-manager' ),
				$name
			),
			'email'   => $email,
			'phone'   => $phone,
		) );
	}

	/**
	 * AJAX handler: unblock a customer by email.
	 *
	 * @return void
	 */
	public function ajax_unblock_customer() {
		check_ajax_referer( 'eom_unblock_customer' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( empty( $email ) ) {
			wp_send_json_error( __( 'Email is required.', 'easy-order-manager' ) );
		}

		$unblocked = $this->unblock_customer( $email );

		if ( $unblocked ) {
			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: %s: customer email */
					__( 'Customer "%s" has been unblocked.', 'easy-order-manager' ),
					$email
				),
			) );
		} else {
			wp_send_json_error( __( 'Customer not found in blocklist.', 'easy-order-manager' ) );
		}
	}

	/**
	 * Validate checkout: prevent blocked customers from placing orders.
	 *
	 * @return void
	 */
	public function validate_checkout() {
		$billing_email = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';
		$billing_phone = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';

		if ( empty( $billing_email ) && empty( $billing_phone ) ) {
			return;
		}

		$blocked = self::is_blocked( $billing_email, $billing_phone );

		if ( false !== $blocked ) {
			wc_add_notice(
				__( 'Sorry, your account has been blocked from placing new orders. Please contact the store administrator.', 'easy-order-manager' ),
				'error'
			);
		}
	}

	/**
	 * Add admin submenu page for managing blocked customers.
	 *
	 * @return void
	 */
	public function add_admin_page() {
		add_submenu_page(
			'eom-dashboard',
			__( 'Blocked Customers', 'easy-order-manager' ),
			__( 'Blocked Customers', 'easy-order-manager' ),
			'manage_woocommerce',
			'eom-blocked-customers',
			array( $this, 'render_blocked_page' )
		);
	}

	/**
	 * Render the blocked customers admin page.
	 *
	 * @return void
	 */
	public function render_blocked_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'easy-order-manager' ) );
		}

		$blocked = self::get_blocked_customers();

		// Handle unblock request from this page.
		if ( isset( $_GET['unblock'] ) && isset( $_GET['_wpnonce'] ) ) {
			if ( wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'eom_unblock' ) ) {
				$unblock_email = sanitize_email( wp_unslash( $_GET['unblock'] ) );
				$this->unblock_customer( $unblock_email );
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Customer unblocked.', 'easy-order-manager' ) . '</p></div>';
				$blocked = self::get_blocked_customers(); // Refresh.
			}
		}
		?>
		<div class="wrap eom-blocked-wrap">
			<h1><?php esc_html_e( 'Blocked Customers', 'easy-order-manager' ); ?></h1>
			<p><?php esc_html_e( 'These customers are blocked from placing new orders. Blocking is triggered from the order dashboard.', 'easy-order-manager' ); ?></p>

			<?php if ( empty( $blocked ) ) : ?>
				<div class="notice notice-info">
					<p><?php esc_html_e( 'No blocked customers.', 'easy-order-manager' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Email', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Phone', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Blocked From (Order)', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Blocked At', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'easy-order-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $blocked as $record ) : ?>
							<tr>
								<td><?php echo esc_html( $record['name'] ?? '—' ); ?></td>
								<td><?php echo esc_html( $record['email'] ?? '—' ); ?></td>
								<td><?php echo esc_html( $record['phone'] ?? '—' ); ?></td>
								<td>
									<?php if ( ! empty( $record['blocked_from'] ) ) : ?>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $record['blocked_from'] ) . '&action=edit' ) ); ?>">
											#<?php echo esc_html( $record['blocked_from'] ); ?>
										</a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $record['blocked_at'] ?? '—' ); ?></td>
								<td>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=eom-blocked-customers&unblock=' . urlencode( $record['email'] ?? '' ) ), 'eom_unblock' ) ); ?>" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Unblock this customer?', 'easy-order-manager' ) ); ?>');">
										<?php esc_html_e( 'Unblock', 'easy-order-manager' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><em><?php echo esc_html( sprintf( __( 'Total blocked: %d', 'easy-order-manager' ), count( $blocked ) ) ); ?></em></p>
			<?php endif; ?>
		</div>
		<?php
	}
}