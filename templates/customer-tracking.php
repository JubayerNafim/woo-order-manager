<?php
/**
 * EOM Customer Order Tracking Template
 *
 * Front-facing page where customers can track their orders
 * by entering Order ID and Phone number.
 *
 * @package EasyOrderManager
 */

defined( 'ABSPATH' ) || exit;

$order_id    = isset( $_POST['eom_track_order_id'] ) ? absint( $_POST['eom_track_order_id'] ) : 0;
$phone       = isset( $_POST['eom_track_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['eom_track_phone'] ) ) : '';
$tracking_info = null;
$error        = '';

if ( $order_id && $phone ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		$error = __( 'Order not found. Please check your Order ID.', 'easy-order-manager' );
	} else {
		$billing_phone = $order->get_billing_phone();
		$billing_phone_clean = preg_replace( '/[^0-9]/', '', $billing_phone );
		$input_phone_clean   = preg_replace( '/[^0-9]/', '', $phone );

		if ( $input_phone_clean !== $billing_phone_clean && strpos( $billing_phone_clean, $input_phone_clean ) === false && strpos( $input_phone_clean, $billing_phone_clean ) === false ) {
			$error = __( 'Phone number does not match this order.', 'easy-order-manager' );
		} else {
			$tracking_info = array(
				'order_id'       => $order->get_order_number(),
				'status'         => wc_get_order_status_name( $order->get_status() ),
				'status_code'    => $order->get_status(),
				'date'           => $order->get_date_created()->format( get_option( 'date_format' ) ),
				'total'          => wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ),
				'payment_method' => $order->get_payment_method_title(),
				'courier'        => $order->get_meta( 'eom_courier_name', true ),
				'tracking_id'    => $order->get_meta( 'eom_tracking_id', true ),
				'items'          => array(),
			);

			foreach ( $order->get_items() as $item ) {
				$tracking_info['items'][] = $item->get_name() . ' × ' . $item->get_quantity();
			}

			// Get courier tracking URL if available.
			if ( $tracking_info['courier'] && class_exists( 'EOM_Courier_Manager' ) ) {
				$cm = EOM_Courier_Manager::instance();
				$courier = $cm->get_courier( $tracking_info['courier'] );
				if ( $courier && $tracking_info['tracking_id'] ) {
					$tracking_info['tracking_url'] = $courier->get_tracking_url( $tracking_info['tracking_id'] );
				}
			}
		}
	}
}

// Status progress steps.
$status_steps = array(
	'pending'             => 1,
	'processing'          => 2,
	'wc-eom-awaiting-shipment' => 2.5,
	'on-hold'             => 2,
	'wc-eom-confirmed'    => 3,
	'wc-eom-courier-assigned' => 4,
	'wc-eom-in-transit'   => 5,
	'completed'           => 6,
	'delivered'           => 6,
	'cancelled'           => -1,
	'refunded'            => -1,
	'failed'              => -1,
	'wc-eom-return-requested' => -1,
);
$current_step = isset( $status_steps[ $tracking_info['status_code'] ] ) ? $status_steps[ $tracking_info['status_code'] ] : 0;
?>
<div class="eom-tracking-wrap" style="max-width:600px;margin:40px auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif">

	<div style="text-align:center;margin-bottom:30px">
		<h2 style="font-size:1.5rem;font-weight:700;color:#111827;margin-bottom:8px"><?php esc_html_e( 'Track Your Order', 'easy-order-manager' ); ?></h2>
		<p style="color:#6b7280;font-size:0.95rem"><?php esc_html_e( 'Enter your Order ID and Phone Number to see the latest status.', 'easy-order-manager' ); ?></p>
	</div>

	<?php if ( $error ) : ?>
		<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;margin-bottom:20px;color:#b91c1c;font-size:0.9rem"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>

	<form method="post" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,0.08);margin-bottom:24px">
		<div style="display:flex;flex-direction:column;gap:16px">
			<div>
				<label for="eom_track_order_id" style="display:block;font-weight:600;font-size:0.9rem;color:#374151;margin-bottom:4px"><?php esc_html_e( 'Order ID', 'easy-order-manager' ); ?></label>
				<input type="number" id="eom_track_order_id" name="eom_track_order_id" value="<?php echo esc_attr( $order_id ); ?>" required style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:1rem" placeholder="<?php esc_attr_e( 'e.g. 1234', 'easy-order-manager' ); ?>">
			</div>
			<div>
				<label for="eom_track_phone" style="display:block;font-weight:600;font-size:0.9rem;color:#374151;margin-bottom:4px"><?php esc_html_e( 'Phone Number', 'easy-order-manager' ); ?></label>
				<input type="tel" id="eom_track_phone" name="eom_track_phone" value="<?php echo esc_attr( $phone ); ?>" required style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:1rem" placeholder="<?php esc_attr_e( 'e.g. 01XXXXXXXXX', 'easy-order-manager' ); ?>">
			</div>
			<button type="submit" style="background:#2563eb;color:#fff;border:none;padding:12px 24px;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;transition:background 0.2s"><?php esc_html_e( '🔍 Track Order', 'easy-order-manager' ); ?></button>
		</div>
	</form>

	<?php if ( $tracking_info ) : ?>
		<!-- Order Summary -->
		<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,0.08)">
			<h3 style="font-size:1.1rem;font-weight:700;color:#111827;margin:0 0 16px;padding-bottom:12px;border-bottom:1px solid #f3f4f6"><?php esc_html_e( 'Order Summary', 'easy-order-manager' ); ?></h3>
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:0.9rem">
				<div><strong style="color:#6b7280"><?php esc_html_e( 'Order:', 'easy-order-manager' ); ?></strong> #<?php echo esc_html( $tracking_info['order_id'] ); ?></div>
				<div><strong style="color:#6b7280"><?php esc_html_e( 'Date:', 'easy-order-manager' ); ?></strong> <?php echo esc_html( $tracking_info['date'] ); ?></div>
				<div><strong style="color:#6b7280"><?php esc_html_e( 'Status:', 'easy-order-manager' ); ?></strong> <span style="display:inline-block;padding:2px 10px;border-radius:10px;font-weight:500;font-size:0.85rem;background:<?php echo $current_step >= 6 ? '#dcfce7' : ($current_step < 0 ? '#fef2f2' : '#fef3c7'); ?>;color:<?php echo $current_step >= 6 ? '#166534' : ($current_step < 0 ? '#991b1b' : '#92400e'); ?>"><?php echo esc_html( $tracking_info['status'] ); ?></span></div>
				<div><strong style="color:#6b7280"><?php esc_html_e( 'Total:', 'easy-order-manager' ); ?></strong> <?php echo wp_kses_post( $tracking_info['total'] ); ?></div>
				<div style="grid-column:1/-1"><strong style="color:#6b7280"><?php esc_html_e( 'Items:', 'easy-order-manager' ); ?></strong> <?php echo esc_html( implode( ', ', $tracking_info['items'] ) ); ?></div>
			</div>
		</div>

		<!-- Progress Tracker -->
		<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px 24px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,0.08)">
			<h3 style="font-size:1.1rem;font-weight:700;color:#111827;margin:0 0 24px;text-align:center"><?php esc_html_e( 'Order Progress', 'easy-order-manager' ); ?></h3>

			<?php if ( $current_step < 0 ) : ?>
				<div style="text-align:center;padding:20px;background:#fef2f2;border-radius:8px;color:#991b1b">
					<?php esc_html_e( 'This order was cancelled / returned.', 'easy-order-manager' ); ?>
				</div>
			<?php else : ?>
				<div style="display:flex;justify-content:space-between;position:relative;padding:0 10px">
					<div style="position:absolute;top:16px;left:30px;right:30px;height:4px;background:#e5e7eb;z-index:0;border-radius:2px">
						<div style="height:100%;width:<?php echo max( 0, ( $current_step - 1 ) / 5 * 100 ); ?>%;background:#2563eb;border-radius:2px;transition:width 0.5s"></div>
					</div>
					<?php
					$steps = array(
						1 => __( 'Pending', 'easy-order-manager' ),
						2 => __( 'Processing', 'easy-order-manager' ),
						3 => __( 'Confirmed', 'easy-order-manager' ),
						4 => __( 'Courier', 'easy-order-manager' ),
						5 => __( 'In Transit', 'easy-order-manager' ),
						6 => __( 'Delivered', 'easy-order-manager' ),
					);
					foreach ( $steps as $step => $label ) :
						$done = $current_step >= $step;
						$active = (int) $current_step === $step;
					?>
						<div style="display:flex;flex-direction:column;align-items:center;position:relative;z-index:1;flex:1">
							<div style="width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.85rem;background:<?php echo $done ? '#2563eb' : '#fff'; ?>;color:<?php echo $done ? '#fff' : '#9ca3af'; ?>;border:3px solid <?php echo $done ? '#2563eb' : '#e5e7eb'; ?>;box-shadow:<?php echo $active ? '0 0 0 4px rgba(37,99,235,0.2)' : 'none'; ?>;transition:all 0.3s">
								<?php echo $done ? '✓' : $step; ?>
							</div>
							<span style="font-size:0.75rem;font-weight:<?php echo $done ? '600' : '400'; ?>;color:<?php echo $done ? '#2563eb' : '#9ca3af'; ?>;margin-top:8px;text-align:center"><?php echo esc_html( $label ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- Courier Tracking -->
		<?php if ( ! empty( $tracking_info['courier'] ) ) : ?>
		<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,0.08)">
			<h3 style="font-size:1.1rem;font-weight:700;color:#111827;margin:0 0 16px;padding-bottom:12px;border-bottom:1px solid #f3f4f6"><?php esc_html_e( 'Courier Info', 'easy-order-manager' ); ?></h3>
			<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
				<div>
					<strong style="color:#6b7280;font-size:0.85rem"><?php esc_html_e( 'Courier:', 'easy-order-manager' ); ?></strong>
					<span style="text-transform:capitalize"><?php echo esc_html( $tracking_info['courier'] ); ?></span>
				</div>
				<div>
					<strong style="color:#6b7280;font-size:0.85rem"><?php esc_html_e( 'Tracking ID:', 'easy-order-manager' ); ?></strong>
					<span><?php echo esc_html( $tracking_info['tracking_id'] ); ?></span>
				</div>
				<?php if ( ! empty( $tracking_info['tracking_url'] ) ) : ?>
					<a href="<?php echo esc_url( $tracking_info['tracking_url'] ); ?>" target="_blank" rel="noopener" style="background:#f3f4f6;padding:8px 16px;border-radius:8px;color:#374151;text-decoration:none;font-weight:500;font-size:0.9rem"><?php esc_html_e( '🔗 Track on Courier', 'easy-order-manager' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php
