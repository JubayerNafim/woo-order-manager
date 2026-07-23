<?php
/**
 * EOM Delivery Charge Alert
 *
 * Monitors courier delivery charges for discrepancies between expected
 * and actual charges. Sends alerts via Telegram when significant
 * differences are detected.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Delivery_Charge_Alert
 *
 * Compares expected delivery charges (calculated based on order weight,
 * destination, and COD) against actual charges returned by courier APIs.
 * Logs discrepancies and sends configurable alerts.
 */
class EOM_Delivery_Charge_Alert {

	/**
	 * Discrepancy log table name.
	 *
	 * @var string
	 */
	private $log_table;

	/**
	 * Bookings table name.
	 *
	 * @var string
	 */
	private $bookings_table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->log_table      = $wpdb->prefix . 'eom_charge_discrepancies';
		$this->bookings_table = $wpdb->prefix . 'eom_courier_bookings';

		// Admin hooks.
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_eom_dismiss_discrepancy', array( $this, 'ajax_dismiss' ) );

		// Cron hook.
		add_action( 'eom_daily_charge_check', array( $this, 'cron_check_charges' ) );

		// Hook into courier booking to check charge right after booking.
		add_action( 'eom_after_courier_booking', array( $this, 'check_booking_charge' ), 10, 2 );
	}

	/**
	 * Check for charge discrepancy after a courier booking.
	 *
	 * @param int   $order_id  Order ID.
	 * @param array $booking   Booking response data.
	 *
	 * @return void
	 */
	public function check_booking_charge( int $order_id, array $booking ): void {
		$actual_charge = isset( $booking['delivery_fee'] ) ? (float) $booking['delivery_fee'] :
						( isset( $booking['charge'] ) ? (float) $booking['charge'] :
						( isset( $booking['delivery_charge'] ) ? (float) $booking['delivery_charge'] : 0 ) );

		if ( $actual_charge <= 0 ) {
			return;
		}

		$this->check_charge_discrepancy( $order_id, $actual_charge );
	}

	/**
	 * Check for a delivery charge discrepancy.
	 *
	 * Compares the actual charge from the courier against the expected
	 * charge calculated from order data.
	 *
	 * @param int   $order_id      WooCommerce order ID.
	 * @param float $actual_charge The actual charge returned by the courier.
	 *
	 * @return bool True if a discrepancy was logged.
	 */
	public function check_charge_discrepancy( int $order_id, float $actual_charge ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$expected_charge = $this->get_expected_charge( $order );
		if ( $expected_charge <= 0 ) {
			return false;
		}

		$difference = abs( $actual_charge - $expected_charge );
		$threshold  = (float) get_option( 'eom_charge_discrepancy_threshold', 20 );

		// Only log if difference exceeds threshold.
		if ( $difference < $threshold ) {
			return false;
		}

		$courier_name = $order->get_meta( 'eom_courier_name', true );

		$this->log_discrepancy( $order_id, $expected_charge, $actual_charge, $courier_name );

		// Send alert if difference is significant (over 2x threshold).
		if ( $difference >= $threshold * 2 ) {
			$this->send_telegram_alert( array(
				'order_id'       => $order_id,
				'expected'       => $expected_charge,
				'actual'         => $actual_charge,
				'difference'     => $difference,
				'courier'        => $courier_name,
				'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'order_total'    => $order->get_total(),
			) );
		}

		return true;
	}

	/**
	 * Log a charge discrepancy to the database.
	 *
	 * @param int    $order_id   Order ID.
	 * @param float  $expected   Expected charge.
	 * @param float  $actual     Actual charge from courier.
	 * @param string $courier    Courier slug/name.
	 *
	 * @return int|false Insert ID or false.
	 */
	public function log_discrepancy( int $order_id, float $expected, float $actual, string $courier ) {
		global $wpdb;

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->log_table,
			array(
				'order_id'   => $order_id,
				'courier'    => $courier,
				'expected'   => $expected,
				'actual'     => $actual,
				'difference' => $actual - $expected,
				'status'     => 'open',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%f', '%f', '%f', '%s', '%s' )
		);

		if ( false !== $result ) {
			$this->log_activity(
				$order_id,
				'charge_discrepancy',
				sprintf(
					/* translators: %1$f: Expected charge, %2$f: Actual charge, %3$s: Courier */
					__( 'Delivery charge discrepancy: Expected ৳%1$f, Actual ৳%2$f (%3$s).', 'easy-order-manager' ),
					$expected,
					$actual,
					$courier
				)
			);
		}

		return $result;
	}

	/**
	 * Calculate the expected delivery charge for an order.
	 *
	 * Uses the configured courier's charge calculation if available,
	 * otherwise falls back to a simple weight-based estimate.
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 *
	 * @return float Expected delivery charge.
	 */
	public function get_expected_charge( \WC_Order $order ): float {
		$courier_slug = $order->get_meta( 'eom_courier_name', true );
		if ( empty( $courier_slug ) ) {
			return 0.0;
		}

		$manager = EOM_Courier_Manager::instance();
		$courier = $manager->get_courier( $courier_slug );

		if ( ! $courier ) {
			return 0.0;
		}

		// Calculate total weight from order items.
		$weight = 0;
		$items  = $order->get_items();
		foreach ( $items as $item ) {
			$product = $item->get_product();
			if ( $product ) {
				$item_weight = (float) $product->get_weight();
				if ( $item_weight > 0 ) {
					$weight += $item_weight * $item->get_quantity();
				}
			}
		}

		if ( $weight <= 0 ) {
			$weight = 0.5;
		}

		// Determine destination.
		$shipping    = $order->get_address( 'shipping' );
		$destination = ! empty( $shipping['city'] ) ? $shipping['city'] : $order->get_billing_city();

		// Determine COD amount.
		$cod_amount = 0;
		if ( in_array( $order->get_payment_method(), array( 'cod', 'cash_on_delivery' ), true ) ) {
			$cod_amount = (float) $order->get_total();
		}

		return $courier->get_charge( $weight, $destination, $cod_amount );
	}

	/**
	 * Send a Telegram alert for a charge discrepancy.
	 *
	 * @param array $discrepancy_data {
	 *     Discrepancy information.
	 *
	 *     @type int    $order_id       Order ID.
	 *     @type float  $expected       Expected charge.
	 *     @type float  $actual         Actual charge.
	 *     @type float  $difference     Difference amount.
	 *     @type string $courier        Courier name.
	 *     @type string $customer_name  Customer name.
	 *     @type float  $order_total    Order total.
	 * }
	 *
	 * @return bool True if sent successfully.
	 */
	public function send_telegram_alert( array $discrepancy_data ): bool {
		$bot_token = get_option( 'eom_telegram_bot_token', '' );
		$chat_id   = get_option( 'eom_telegram_chat_id', '' );

		if ( empty( $bot_token ) || empty( $chat_id ) ) {
			return false;
		}

		$difference_symbol = $discrepancy_data['difference'] > 0 ? '+' : '';
		$message = sprintf(
			"⚡ *Delivery Charge Discrepancy*\nOrder: #%d\nCustomer: %s\nOrder Total: ৳%s\nCourier: %s\nExpected: ৳%.2f\nActual: ৳%.2f\nDifference: %s৳%.2f\n\nReview required!",
			$discrepancy_data['order_id'],
			$discrepancy_data['customer_name'],
			number_format( $discrepancy_data['order_total'], 2 ),
			$discrepancy_data['courier'],
			$discrepancy_data['expected'],
			$discrepancy_data['actual'],
			$difference_symbol,
			abs( $discrepancy_data['difference'] )
		);

		$url = 'https://api.telegram.org/bot' . $bot_token . '/sendMessage';

		$response = wp_remote_post( $url, array(
			'timeout' => 15,
			'body'    => array(
				'chat_id'    => $chat_id,
				'text'       => $message,
				'parse_mode' => 'Markdown',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $body['ok'] ) && true === $body['ok'];
	}

	/**
	 * Log activity to the activity log table.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $action   Action slug.
	 * @param string $details  Description.
	 *
	 * @return void
	 */
	private function log_activity( int $order_id, string $action, string $details ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'eom_activity_log',
			array(
				'order_id'   => $order_id,
				'user_id'    => get_current_user_id(),
				'action'     => $action,
				'details'    => $details,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Add admin submenu page for charge alerts.
	 *
	 * @return void
	 */
	public function add_admin_page(): void {
		add_submenu_page(
			'eom-dashboard',
			__( 'Charge Discrepancies', 'easy-order-manager' ),
			__( 'Charge Alerts', 'easy-order-manager' ),
			'manage_woocommerce',
			'eom-charge-alerts',
			array( $this, 'render_alerts_page' )
		);
	}

	/**
	 * Render the charge discrepancies admin page.
	 *
	 * @return void
	 */
	public function render_alerts_page(): void {
		global $wpdb;

		$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'open';
		$per_page      = 20;
		$current_page  = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$offset        = ( $current_page - 1 ) * $per_page;

		$where = $status_filter ? $wpdb->prepare( 'WHERE status = %s', $status_filter ) : '';

		$total = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			"SELECT COUNT(*) FROM {$this->log_table} {$where}"
		);

		$discrepancies = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT * FROM {$this->log_table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$total_pages = ceil( $total / $per_page );

		?>
		<div class="wrap eom-charge-alerts-wrap">
			<h1><?php esc_html_e( 'Delivery Charge Discrepancies', 'easy-order-manager' ); ?></h1>

			<ul class="subsubsub">
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'status', 'open' ) ); ?>" class="<?php echo 'open' === $status_filter ? 'current' : ''; ?>">
						<?php esc_html_e( 'Open', 'easy-order-manager' ); ?>
					</a> |
				</li>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'status', 'reviewed' ) ); ?>" class="<?php echo 'reviewed' === $status_filter ? 'current' : ''; ?>">
						<?php esc_html_e( 'Reviewed', 'easy-order-manager' ); ?>
					</a> |
				</li>
				<li>
					<a href="<?php echo esc_url( remove_query_arg( 'status' ) ); ?>" class="<?php echo empty( $status_filter ) ? 'current' : ''; ?>">
						<?php esc_html_e( 'All', 'easy-order-manager' ); ?>
					</a>
				</li>
			</ul>

			<?php if ( empty( $discrepancies ) ) : ?>
				<div class="notice notice-success">
					<p><?php esc_html_e( 'No charge discrepancies found.', 'easy-order-manager' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order ID', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Courier', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Expected (BDT)', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Actual (BDT)', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Difference (BDT)', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Date', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Status', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'easy-order-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $discrepancies as $disc ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $disc['order_id'] . '&action=edit' ) ); ?>" target="_blank">
										#<?php echo esc_html( $disc['order_id'] ); ?>
									</a>
								</td>
								<td><?php echo esc_html( ucfirst( $disc['courier'] ) ); ?></td>
								<td>৳<?php echo esc_html( number_format( (float) $disc['expected'], 2 ) ); ?></td>
								<td>৳<?php echo esc_html( number_format( (float) $disc['actual'], 2 ) ); ?></td>
								<td>
									<strong class="<?php echo (float) $disc['difference'] > 0 ? 'eom-higher' : 'eom-lower'; ?>">
										<?php echo( (float) $disc['difference'] > 0 ? '+' : '' ); ?>
										৳<?php echo esc_html( number_format( (float) $disc['difference'], 2 ) ); ?>
									</strong>
								</td>
								<td><?php echo esc_html( $disc['created_at'] ); ?></td>
								<td><?php echo esc_html( ucfirst( $disc['status'] ) ); ?></td>
								<td>
									<?php if ( 'open' === $disc['status'] ) : ?>
										<button type="button" class="button button-small eom-dismiss-disc" data-id="<?php echo esc_attr( $disc['id'] ); ?>">
											<?php esc_html_e( 'Mark Reviewed', 'easy-order-manager' ); ?>
										</button>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<span class="displaying-num">
								<?php echo esc_html( sprintf( __( '%d items', 'easy-order-manager' ), $total ) ); ?>
							</span>
							<?php
							echo paginate_links( array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
								'total'     => $total_pages,
								'current'   => $current_page,
							) );
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('.eom-dismiss-disc').on('click', function() {
				var id = $(this).data('id');
				var row = $(this).closest('tr');

				$.post(ajaxurl, {
					action: 'eom_dismiss_discrepancy',
					discrepancy_id: id,
					_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'eom_dismiss_disc' ) ); ?>'
				}, function(response) {
					if (response.success) {
						row.find('td:eq(6)').text('<?php echo esc_js( __( 'Reviewed', 'easy-order-manager' ) ); ?>');
						row.find('td:eq(7)').html('&mdash;');
					} else {
						alert(response.data || '<?php echo esc_js( __( 'Error.', 'easy-order-manager' ) ); ?>');
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX handler to mark a discrepancy as reviewed.
	 *
	 * @return void
	 */
	public function ajax_dismiss(): void {
		check_ajax_referer( 'eom_dismiss_disc' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$disc_id = isset( $_POST['discrepancy_id'] ) ? absint( $_POST['discrepancy_id'] ) : 0;
		if ( ! $disc_id ) {
			wp_send_json_error( __( 'Invalid ID.', 'easy-order-manager' ) );
		}

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->log_table,
			array( 'status' => 'reviewed' ),
			array( 'id' => $disc_id ),
			array( '%s' ),
			array( '%d' )
		);

		wp_send_json_success();
	}

	/**
	 * Cron hook to flag bookings missing actual charge data or pending review.
	 *
	 * The real-time hook eom_after_courier_booking (check_booking_charge) is
	 * the primary mechanism for detecting charge discrepancies -- it compares
	 * the actual delivery_fee from the API response against the expected
	 * charge. This cron is a secondary sweep that catches edge cases:
	 *
	 * 1. Bookings where the stored charge is zero (the API never returned a
	 *    delivery_fee at booking time, so no real-time check could run).
	 * 2. Bookings older than 3 days that have not yet been reviewed (safety
	 *    net for missed alerts).
	 *
	 * @return void
	 */
	public function cron_check_charges(): void {
		global $wpdb;

		// Find bookings with zero charge (missed API fee) or unreviewed
		// bookings older than 3 days that never triggered an alert.
		$bookings = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT b.* FROM {$this->bookings_table} b
				LEFT JOIN {$this->log_table} l ON b.order_id = l.order_id
				WHERE l.id IS NULL
				AND (
				    b.charge = 0
				    OR
				    (b.created_at < DATE_SUB(NOW(), INTERVAL 3 DAY))
				)
				AND b.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
				LIMIT 50"
			),
			ARRAY_A
		);

		foreach ( $bookings as $booking ) {
			$order_id = (int) $booking['order_id'];
			$charge   = isset( $booking['charge'] ) ? (float) $booking['charge'] : 0;
			$order    = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			if ( $charge <= 0 ) {
				// Charge was never recorded from the API; log a zero-charge
				// discrepancy so it appears on the alerts page for review.
				$expected = $this->get_expected_charge( $order );
				if ( $expected > 0 ) {
					$this->log_discrepancy(
						$order_id,
						$expected,
						0.00,
						$order->get_meta( 'eom_courier_name', true )
					);
				}
			} else {
				// Bookings with a stored charge that were missed by the
				// real-time hook -- re-run the standard check.
				$this->check_charge_discrepancy( $order_id, $charge );
			}
		}
	}

	/**
	 * Create the discrepancies log table on activation.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'eom_charge_discrepancies';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			order_id BIGINT UNSIGNED NOT NULL,
			courier VARCHAR(50) DEFAULT '',
			expected DECIMAL(10,2) DEFAULT 0.00,
			actual DECIMAL(10,2) DEFAULT 0.00,
			difference DECIMAL(10,2) DEFAULT 0.00,
			status VARCHAR(20) DEFAULT 'open',
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_order_id (order_id),
			INDEX idx_status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
