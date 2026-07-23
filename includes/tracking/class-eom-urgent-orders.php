<?php
/**
 * EOM Urgent Orders Tracking
 *
 * Monitors courier-tracked orders that have stalled (no update for X days)
 * and provides admin interfaces for review and alerting.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Urgent_Orders
 *
 * Detects orders where courier tracking shows no update for a configured
 * number of days. Provides admin UI, Telegram alerts, and WP-Cron integration
 * for automated daily checks.
 */
class EOM_Urgent_Orders {

	/**
	 * Bookings table name.
	 *
	 * @var string
	 */
	private $bookings_table;

	/**
	 * Activity log table name.
	 *
	 * @var string
	 */
	private $activity_table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->bookings_table = $wpdb->prefix . 'eom_courier_bookings';
		$this->activity_table = $wpdb->prefix . 'eom_activity_log';

		// Admin hooks.
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_post_eom_dismiss_urgent', array( $this, 'handle_dismiss' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_eom_send_urgent_alert', array( $this, 'ajax_send_alert' ) );
		add_action( 'wp_ajax_eom_dismiss_urgent', array( $this, 'ajax_dismiss' ) );

		// Cron hook.
		add_action( 'eom_daily_urgent_check', array( $this, 'cron_check_urgent' ) );
	}

	/**
	 * Get urgent orders where tracking has stalled.
	 *
	 * Queries the eom_courier_bookings table for orders whose status
	 * is not 'delivered' or 'cancelled' and whose updated_at timestamp
	 * is older than the specified stall threshold.
	 *
	 * @param int $days_stalled Number of days without update to consider urgent. Default 7.
	 *
	 * @return array[] Array of urgent order records with courier info.
	 */
	public function get_urgent_orders( int $days_stalled = 7 ): array {
		global $wpdb;

		$threshold_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_stalled} days" ) );

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT b.*, p.post_title as order_title
				FROM {$this->bookings_table} b
				LEFT JOIN {$wpdb->posts} p ON b.order_id = p.ID
				WHERE b.status NOT IN ('delivered', 'cancelled', 'returned')
				AND b.updated_at < %s
				AND b.created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)
				ORDER BY b.updated_at ASC",
				$threshold_date
			),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			return array();
		}

		$urgent_orders = array();
		foreach ( $results as $row ) {
			$order_id      = (int) $row['order_id'];
			$days_since    = $this->days_since_update( $row['updated_at'] );
			$is_dismissed  = $this->is_dismissed( $order_id );

			if ( $is_dismissed ) {
				continue;
			}

			$order = wc_get_order( $order_id );

			$urgent_orders[] = array(
				'order_id'       => $order_id,
				'order_total'    => $order ? (float) $order->get_total() : 0,
				'customer_name'  => $order ? trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) : '',
				'customer_phone' => $order ? $order->get_billing_phone() : '',
				'courier_slug'   => $row['courier_slug'],
				'tracking_id'    => $row['tracking_id'],
				'consignment_id' => $row['consignment_id'],
				'status'         => $row['status'],
				'last_update'    => $row['updated_at'],
				'days_since_update' => $days_since,
				'dismissed'      => false,
			);
		}

		return $urgent_orders;
	}

	/**
	 * Calculate days since last tracking update.
	 *
	 * @param string $updated_at MySQL datetime string.
	 *
	 * @return int Number of days.
	 */
	private function days_since_update( string $updated_at ): int {
		$update_time = strtotime( $updated_at );
		if ( ! $update_time ) {
			return 0;
		}

		$diff = time() - $update_time;
		return (int) floor( $diff / DAY_IN_SECONDS );
	}

	/**
	 * Check if an order has been dismissed (marked as reviewed).
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return bool
	 */
	private function is_dismissed( int $order_id ): bool {
		$dismissed = get_transient( 'eom_urgent_dismissed_' . $order_id );
		return false !== $dismissed;
	}

	/**
	 * Dismiss an order from the urgent list (mark as reviewed).
	 *
	 * @param int $order_id Order ID to dismiss.
	 *
	 * @return bool
	 */
	public function dismiss_urgent( int $order_id ): bool {
		// Dismiss for 7 days.
		set_transient( 'eom_urgent_dismissed_' . $order_id, true, WEEK_IN_SECONDS );

		$this->log_activity(
			$order_id,
			'urgent_dismissed',
			sprintf(
				/* translators: %d: Order ID */
				__( 'Urgent alert dismissed for order #%d.', 'easy-order-manager' ),
				$order_id
			)
		);

		return true;
	}

	/**
	 * Send a Telegram notification for an urgent order.
	 *
	 * @param int $order_id Order ID to alert about.
	 *
	 * @return bool True if sent successfully.
	 */
	public function send_urgent_alert( int $order_id ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$courier_name  = $order->get_meta( 'eom_courier_name', true );
		$tracking_id   = $order->get_meta( 'eom_tracking_id', true );
		$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$order_total   = $order->get_total();

		$booking = $this->get_latest_booking( $order_id );
		$last_update = $booking ? $booking['updated_at'] : __( 'Unknown', 'easy-order-manager' );
		$days_stalled = $booking ? $this->days_since_update( $booking['updated_at'] ) : 0;

		$message = sprintf(
			"🚨 *Urgent Order Alert*\nOrder: #%d\nCustomer: %s\nAmount: ৳%s\nCourier: %s\nTracking: %s\nLast Update: %s\nDays Stalled: %d\n\nAction required!",
			$order_id,
			$customer_name,
			$order_total,
			$courier_name,
			$tracking_id,
			$last_update,
			$days_stalled
		);

		$sent = $this->send_telegram_message( $message );

		if ( $sent ) {
			$this->log_activity(
				$order_id,
				'urgent_alert_sent',
				sprintf(
					/* translators: %d: Order ID */
					__( 'Urgent alert sent via Telegram for order #%d.', 'easy-order-manager' ),
					$order_id
				)
			);
		}

		return $sent;
	}

	/**
	 * Get the latest booking record for an order.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array|null
	 */
	private function get_latest_booking( int $order_id ): ?array {
		global $wpdb;

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT * FROM {$this->bookings_table} WHERE order_id = %d ORDER BY updated_at DESC LIMIT 1",
				$order_id
			),
			ARRAY_A
		);
	}

	/**
	 * Send a message via Telegram bot.
	 *
	 * @param string $message Message text.
	 *
	 * @return bool
	 */
	private function send_telegram_message( string $message ): bool {
		$bot_token = get_option( 'eom_telegram_bot_token', '' );
		$chat_id   = get_option( 'eom_telegram_chat_id', '' );

		if ( empty( $bot_token ) || empty( $chat_id ) ) {
			return false;
		}

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
	 * Log activity to the eom_activity_log table.
	 *
	 * @param int    $order_id  Order ID.
	 * @param string $action    Action slug.
	 * @param string $details   Description of the activity.
	 *
	 * @return void
	 */
	private function log_activity( int $order_id, string $action, string $details ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->activity_table,
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
	 * WP-Cron hook to check for urgent orders daily.
	 * Sends alerts automatically for orders stalled beyond threshold.
	 *
	 * @return void
	 */
	public function cron_check_urgent(): void {
		$days_stalled = (int) get_option( 'eom_urgent_days_threshold', 7 );
		$urgent_orders = $this->get_urgent_orders( $days_stalled );

		if ( empty( $urgent_orders ) ) {
			return;
		}

		$auto_alert = get_option( 'eom_urgent_auto_alert', false );

		foreach ( $urgent_orders as $order ) {
			if ( $auto_alert ) {
				$this->send_urgent_alert( $order['order_id'] );
			}

			$this->log_activity(
				$order['order_id'],
				'urgent_cron_check',
				sprintf(
					/* translators: %1$d: Order ID, %2$d: Days stalled */
					__( 'Order #%1$d flagged as urgent (%2$d days without update).', 'easy-order-manager' ),
					$order['order_id'],
					$order['days_since_update']
				)
			);
		}
	}

	/**
	 * Add the urgent orders admin page.
	 *
	 * @return void
	 */
	public function add_admin_page(): void {
		add_submenu_page(
			'eom-dashboard',
			__( 'Urgent Orders', 'easy-order-manager' ),
			__( 'Urgent Orders', 'easy-order-manager' ),
			'manage_woocommerce',
			'eom-urgent-orders',
			array( $this, 'render_urgent_orders_page' )
		);
	}

	/**
	 * Render the urgent orders admin page.
	 *
	 * @return void
	 */
	public function render_urgent_orders_page(): void {
		$days_threshold = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : (int) get_option( 'eom_urgent_days_threshold', 7 );
		$urgent_orders  = $this->get_urgent_orders( $days_threshold );

		?>
		<div class="wrap eom-urgent-wrap">
			<h1><?php esc_html_e( 'Urgent Orders', 'easy-order-manager' ); ?></h1>

			<form method="get" class="eom-urgent-filter">
				<input type="hidden" name="page" value="eom-urgent-orders">
				<label for="eom-urgent-days"><?php esc_html_e( 'Stalled for (days):', 'easy-order-manager' ); ?></label>
				<input type="number" id="eom-urgent-days" name="days" value="<?php echo esc_attr( $days_threshold ); ?>" min="1" max="90" style="width:80px;">
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'easy-order-manager' ); ?></button>
			</form>

			<?php if ( empty( $urgent_orders ) ) : ?>
				<div class="notice notice-success">
					<p><?php esc_html_e( 'No urgent orders found. All tracked parcels are within normal delivery timeframes.', 'easy-order-manager' ); ?></p>
				</div>
			<?php else : ?>
				<p><?php echo esc_html( sprintf( __( 'Found %d urgent order(s) requiring attention.', 'easy-order-manager' ), count( $urgent_orders ) ) ); ?></p>

				<table class="wp-list-table widefat fixed striped eom-urgent-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order ID', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Customer', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Phone', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Total', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Courier', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Tracking ID', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Status', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Last Update', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Days Stalled', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'easy-order-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $urgent_orders as $order ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order['order_id'] . '&action=edit' ) ); ?>" target="_blank">
										#<?php echo esc_html( $order['order_id'] ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $order['customer_name'] ); ?></td>
								<td><?php echo esc_html( $order['customer_phone'] ); ?></td>
								<td>৳<?php echo esc_html( number_format( $order['order_total'], 2 ) ); ?></td>
								<td><?php echo esc_html( ucfirst( $order['courier_slug'] ) ); ?></td>
								<td>
									<?php if ( $order['tracking_id'] ) : ?>
										<a href="#" class="eom-tracking-link" data-courier="<?php echo esc_attr( $order['courier_slug'] ); ?>" data-tracking="<?php echo esc_attr( $order['tracking_id'] ); ?>">
											<?php echo esc_html( $order['tracking_id'] ); ?>
										</a>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( ucfirst( $order['status'] ) ); ?></td>
								<td><?php echo esc_html( $order['last_update'] ); ?></td>
								<td><strong style="color:#d63638;"><?php echo esc_html( $order['days_since_update'] ); ?></strong></td>
								<td class="eom-urgent-actions">
									<button type="button" class="button button-small eom-send-alert" data-order-id="<?php echo esc_attr( $order['order_id'] ); ?>">
										<?php esc_html_e( 'Send Alert', 'easy-order-manager' ); ?>
									</button>
									<button type="button" class="button button-small eom-dismiss-urgent" data-order-id="<?php echo esc_attr( $order['order_id'] ); ?>">
										<?php esc_html_e( 'Dismiss', 'easy-order-manager' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('.eom-send-alert').on('click', function() {
				var orderId = $(this).data('order-id');
				var btn = $(this);
				btn.prop('disabled', true).text('<?php echo esc_js( __( 'Sending...', 'easy-order-manager' ) ); ?>');

				$.post(ajaxurl, {
					action: 'eom_send_urgent_alert',
					order_id: orderId,
					_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'eom_urgent_alert' ) ); ?>'
				}, function(response) {
					if (response.success) {
						btn.text('<?php echo esc_js( __( 'Alert Sent', 'easy-order-manager' ) ); ?>');
					} else {
						alert(response.data || '<?php echo esc_js( __( 'Failed to send alert.', 'easy-order-manager' ) ); ?>');
						btn.prop('disabled', false).text('<?php echo esc_js( __( 'Send Alert', 'easy-order-manager' ) ); ?>');
					}
				});
			});

			$('.eom-dismiss-urgent').on('click', function() {
				var orderId = $(this).data('order-id');
				var row = $(this).closest('tr');
				row.css('opacity', '0.5');

				$.post(ajaxurl, {
					action: 'eom_dismiss_urgent',
					order_id: orderId,
					_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'eom_dismiss_urgent' ) ); ?>'
				}, function(response) {
					if (response.success) {
						row.fadeOut(400, function() { $(this).remove(); });
					} else {
						row.css('opacity', '1');
						alert(response.data || '<?php echo esc_js( __( 'Failed to dismiss.', 'easy-order-manager' ) ); ?>');
					}
				});
			});

			$('.eom-tracking-link').on('click', function(e) {
				e.preventDefault();
				var courier = $(this).data('courier');
				var tracking = $(this).data('tracking');
				// Open tracking URL in new tab.
				var url = '';
				switch (courier) {
					case 'pathao': url = 'https://pathao.com/track/' + tracking; break;
					case 'steadfast': url = 'https://steadfast.com.bd/tracking/' + tracking; break;
					case 'redx': url = 'https://redx.com.bd/tracking/' + tracking; break;
					default: url = 'https://google.com/search?q=' + encodeURIComponent(courier + ' tracking ' + tracking);
				}
				window.open(url, '_blank');
			});
		});
		</script>
		<?php
	}

	/**
	 * Handle dismiss action via admin_post.
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'easy-order-manager' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( $order_id ) {
			$this->dismiss_urgent( $order_id );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=eom-urgent-orders' ) );
		exit;
	}

	/**
	 * AJAX handler to send an urgent alert.
	 *
	 * @return void
	 */
	public function ajax_send_alert(): void {
		check_ajax_referer( 'eom_urgent_alert' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( __( 'Invalid order ID.', 'easy-order-manager' ) );
		}

		$sent = $this->send_urgent_alert( $order_id );

		if ( $sent ) {
			wp_send_json_success( __( 'Alert sent successfully.', 'easy-order-manager' ) );
		} else {
			wp_send_json_error( __( 'Failed to send alert. Check Telegram configuration.', 'easy-order-manager' ) );
		}
	}

	/**
	 * AJAX handler to dismiss an urgent order.
	 *
	 * @return void
	 */
	public function ajax_dismiss(): void {
		check_ajax_referer( 'eom_dismiss_urgent' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( __( 'Invalid order ID.', 'easy-order-manager' ) );
		}

		$this->dismiss_urgent( $order_id );
		wp_send_json_success( __( 'Order dismissed.', 'easy-order-manager' ) );
	}
}
