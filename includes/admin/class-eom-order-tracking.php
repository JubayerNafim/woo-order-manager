<?php
/**
 * EOM Order Tracking (Customer-Facing)
 *
 * Provides a 'track-order' endpoint for customers to look up order status
 * and courier tracking information using their Order ID and phone number.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Order_Tracking
 *
 * Handles the customer-facing order tracking page, WC endpoint,
 * and My Account menu integration.
 */
class EOM_Order_Tracking {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'add_tracking_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_my_account_tracking_link' ), 10, 1 );
		add_action( 'woocommerce_account_track-order_endpoint', array( $this, 'tracking_template' ) );
		add_shortcode( 'eom_track_order', array( $this, 'render_tracking_page' ) );
		add_action( 'wp_ajax_nopriv_eom_lookup_tracking', array( $this, 'ajax_lookup_tracking' ) );
		add_action( 'wp_ajax_eom_lookup_tracking', array( $this, 'ajax_lookup_tracking' ) );
	}

	/**
	 * Add the 'track-order' rewrite endpoint via WooCommerce.
	 *
	 * @return void
	 */
	public function add_tracking_endpoint() {
		if ( class_exists( 'WooCommerce' ) ) {
			add_rewrite_endpoint( 'track-order', EP_ROOT | EP_PAGES );
		}
	}

	/**
	 * Render the order tracking page (shortcode or endpoint).
	 *
	 * @return string HTML output.
	 */
	public function render_tracking_page() {
		ob_start();
		?>
		<div class="eom-tracking-wrap" style="max-width:800px; margin:0 auto; padding:20px;">
			<h2><?php esc_html_e( 'Track Your Order', 'easy-order-manager' ); ?></h2>
			<p><?php esc_html_e( 'Enter your Order ID and phone number to view the current status and tracking information.', 'easy-order-manager' ); ?></p>

			<form method="post" class="eom-tracking-form" style="background:#f9f9f9; padding:20px; border-radius:5px; margin-bottom:20px;">
				<?php wp_nonce_field( 'eom_track_order_action', 'eom_track_order_nonce' ); ?>
				<table class="form-table" style="width:auto;">
					<tr>
						<th scope="row">
							<label for="eom-order-id"><?php esc_html_e( 'Order ID', 'easy-order-manager' ); ?></label>
						</th>
						<td>
							<input type="text" id="eom-order-id" name="order_id" required style="width:200px;" placeholder="<?php esc_attr_e( 'e.g. 1234', 'easy-order-manager' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="eom-phone"><?php esc_html_e( 'Phone Number', 'easy-order-manager' ); ?></label>
						</th>
						<td>
							<input type="text" id="eom-phone" name="phone" required style="width:200px;" placeholder="<?php esc_attr_e( 'e.g. 01XXXXXXXXX', 'easy-order-manager' ); ?>">
						</td>
					</tr>
				</table>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Track Order', 'easy-order-manager' ); ?></button>
				</p>
			</form>

			<div id="eom-tracking-result">
				<?php
				// Handle form submission.
				if ( isset( $_POST['eom_track_order_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['eom_track_order_nonce'] ) ), 'eom_track_order_action' ) ) {
					$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
					$phone    = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
					$this->display_tracking_result( $order_id, $phone );
				}
				?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get tracking information for an order.
	 *
	 * @param int $order_id The WooCommerce order ID.
	 * @return array Tracking info including status, courier, and tracking ID.
	 */
	public function get_tracking_info( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) {
			return array();
		}

		$status      = wc_get_order_status_name( $order->get_status() );
		$courier     = $order->get_meta( 'eom_courier_name', true );
		$tracking_id = $order->get_meta( 'eom_tracking_id', true );
		$tracking_url = '';

		// Build tracking URL based on courier.
		if ( $courier && $tracking_id ) {
			$tracking_url = $this->get_courier_tracking_url( $courier, $tracking_id );
		}

		$order_date = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'F j, Y' ) : '—';

		return array(
			'order_id'     => $order_id,
			'status'       => $status,
			'courier'      => $courier ? $courier : __( 'Not assigned', 'easy-order-manager' ),
			'tracking_id'  => $tracking_id ? $tracking_id : '—',
			'tracking_url' => $tracking_url,
			'order_date'   => $order_date,
			'items'        => $order->get_item_count(),
			'total'        => $order->get_formatted_order_total(),
		);
	}

	/**
	 * Add 'Track Orders' link to My Account menu.
	 *
	 * @param array $menu_items Existing menu items.
	 * @return array Modified menu items.
	 */
	public function add_my_account_tracking_link( $menu_items ) {
		// Insert after 'orders' menu item.
		$menu_items = array_slice( $menu_items, 0, 2, true ) +
			array( 'track-order' => __( 'Track Orders', 'easy-order-manager' ) ) +
			array_slice( $menu_items, 2, null, true );

		return $menu_items;
	}

	/**
	 * Template for the My Account track-order endpoint.
	 *
	 * @return void
	 */
	public function tracking_template() {
		echo $this->render_tracking_page(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * AJAX handler: lookup tracking information.
	 *
	 * @return void
	 */
	public function ajax_lookup_tracking() {
		check_ajax_referer( 'eom_track_order_action', 'eom_track_order_nonce' );

		$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
		$phone    = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		ob_start();
		$this->display_tracking_result( $order_id, $phone );
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Display the tracking result for a given order and phone number.
	 *
	 * @param string $order_id The order ID.
	 * @param string $phone    The customer phone number.
	 * @return void
	 */
	private function display_tracking_result( $order_id, $phone ) {
		$order = wc_get_order( absint( $order_id ) );

		if ( ! $order ) {
			echo '<div class="eom-tracking-error" style="color:#dc3232; padding:15px; background:#fbeaea; border-radius:5px;">';
			echo esc_html__( 'Order not found. Please check your Order ID and try again.', 'easy-order-manager' );
			echo '</div>';
			return;
		}

		// Verify phone number matches.
		$order_phone = $order->get_billing_phone();
		$order_phone_clean = preg_replace( '/[^0-9]/', '', $order_phone );
		$input_phone_clean = preg_replace( '/[^0-9]/', '', $phone );

		if ( $order_phone_clean !== $input_phone_clean ) {
			echo '<div class="eom-tracking-error" style="color:#dc3232; padding:15px; background:#fbeaea; border-radius:5px;">';
			echo esc_html__( 'Phone number does not match this order. Please verify your details.', 'easy-order-manager' );
			echo '</div>';
			return;
		}

		$info = $this->get_tracking_info( $order_id );
		?>
		<div class="eom-tracking-success" style="background:#f0f6f0; padding:20px; border-radius:5px; border:1px solid #c3e6cb;">
			<h3 style="margin-top:0;">
				<?php
				printf(
					/* translators: %d: order ID */
					esc_html__( 'Order #%d', 'easy-order-manager' ),
					absint( $order_id )
				);
				?>
			</h3>

			<table class="eom-tracking-details" style="width:100%; border-collapse:collapse;">
				<tr>
					<td style="padding:8px; font-weight:bold; width:150px;"><?php esc_html_e( 'Status:', 'easy-order-manager' ); ?></td>
					<td style="padding:8px;">
						<span class="eom-order-status" style="display:inline-block; padding:4px 10px; border-radius:3px; background:#2271b1; color:#fff; font-size:13px;">
							<?php echo esc_html( $info['status'] ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<td style="padding:8px; font-weight:bold;"><?php esc_html_e( 'Order Date:', 'easy-order-manager' ); ?></td>
					<td style="padding:8px;"><?php echo esc_html( $info['order_date'] ); ?></td>
				</tr>
				<tr>
					<td style="padding:8px; font-weight:bold;"><?php esc_html_e( 'Items:', 'easy-order-manager' ); ?></td>
					<td style="padding:8px;"><?php echo esc_html( $info['items'] ); ?></td>
				</tr>
				<tr>
					<td style="padding:8px; font-weight:bold;"><?php esc_html_e( 'Total:', 'easy-order-manager' ); ?></td>
					<td style="padding:8px;"><?php echo $info['total']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				</tr>
				<tr>
					<td style="padding:8px; font-weight:bold;"><?php esc_html_e( 'Courier:', 'easy-order-manager' ); ?></td>
					<td style="padding:8px;"><?php echo esc_html( $info['courier'] ); ?></td>
				</tr>
				<tr>
					<td style="padding:8px; font-weight:bold;"><?php esc_html_e( 'Tracking ID:', 'easy-order-manager' ); ?></td>
					<td style="padding:8px;">
						<?php if ( $info['tracking_url'] ) : ?>
							<a href="<?php echo esc_url( $info['tracking_url'] ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $info['tracking_id'] ); ?>
							</a>
						<?php else : ?>
							<?php echo esc_html( $info['tracking_id'] ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php if ( $info['tracking_url'] ) : ?>
				<p style="margin-top:15px;">
					<a href="<?php echo esc_url( $info['tracking_url'] ); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Track with Courier', 'easy-order-manager' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<?php
			// Attempt to show tracking timeline if courier supports it.
			$this->render_tracking_timeline( $order_id );
			?>
		</div>
		<?php
	}

	/**
	 * Render a tracking timeline if available.
	 *
	 * @param int $order_id The order ID.
	 * @return void
	 */
	private function render_tracking_timeline( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$tracking_events = $order->get_meta( '_eom_tracking_events', true );

		if ( empty( $tracking_events ) || ! is_array( $tracking_events ) ) {
			return;
		}

		?>
		<div class="eom-tracking-timeline" style="margin-top:20px; border-top:1px solid #c3e6cb; padding-top:15px;">
			<h4><?php esc_html_e( 'Tracking Timeline', 'easy-order-manager' ); ?></h4>
			<ul style="list-style:none; padding:0; margin:0;">
				<?php foreach ( $tracking_events as $event ) : ?>
					<li style="padding:8px 0; border-bottom:1px solid #e8e8e8; display:flex; gap:10px;">
						<span style="font-weight:bold; min-width:140px; color:#666;">
							<?php echo esc_html( isset( $event['date'] ) ? $event['date'] : '' ); ?>
						</span>
						<span>
							<?php echo esc_html( isset( $event['status'] ) ? $event['status'] : '' ); ?>
							<?php if ( ! empty( $event['location'] ) ) : ?>
								<em style="color:#999;"> - <?php echo esc_html( $event['location'] ); ?></em>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Get the courier tracking URL for a given courier and tracking ID.
	 *
	 * @param string $courier     The courier slug.
	 * @param string $tracking_id The tracking ID/number.
	 * @return string The tracking URL or empty string.
	 */
	private function get_courier_tracking_url( $courier, $tracking_id ) {
		$urls = array(
			'steadfast'  => 'https://steadfast.com.bd/tracking/' . $tracking_id,
			'pathao'     => 'https://pathao.com/track/' . $tracking_id,
			'redx'       => 'https://redx.com.bd/tracking/' . $tracking_id,
			'paperfly'   => 'https://paperfly.com.bd/tracking/' . $tracking_id,
			'ecourier'   => 'https://ecourier.com.bd/tracking/' . $tracking_id,
			'carriebee'  => 'https://carriebee.com/tracking/' . $tracking_id,
			'sundarban'  => 'https://sundarbancourier.com/tracking/' . $tracking_id,
		);

		$courier = strtolower( $courier );

		if ( isset( $urls[ $courier ] ) ) {
			return $urls[ $courier ];
		}

		/**
		 * Filter the courier tracking URL.
		 *
		 * @param string $url         The tracking URL.
		 * @param string $courier     The courier slug.
		 * @param string $tracking_id The tracking ID.
		 */
		return apply_filters( 'eom_courier_tracking_url', '', $courier, $tracking_id );
	}
}
