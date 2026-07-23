<?php
/**
 * EOM Profit & Loss
 *
 * Calculates profit per order, provides profit breakdowns,
 * and renders the profit analytics admin page.
 * Tracks packaging loss from returned orders in real time.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Profit_Loss
 *
 * Handles profit calculation, product cost fields, and profit reporting.
 * Uses actual courier delivery charges and COD fees from bookings
 * for accurate real-time profit data.
 * Also tracks packaging material cost as loss for returned orders.
 */
class EOM_Profit_Loss {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_cost_fields_to_product' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_cost_field' ) );
		add_filter( 'eom_dashboard_columns', array( $this, 'add_profit_dashboard_column' ) );
		add_filter( 'eom_dashboard_column_render', array( $this, 'render_profit_column' ), 10, 2 );
			add_action( 'wp_ajax_eom_export_profit_loss', array( $this, 'ajax_export_profit_loss' ) );
	}

	/**
	 * Build date query args for wc_get_orders.
	 *
	 * Uses >= and <= with explicit time for broad HPOS compatibility.
	 *
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 * @param array  $extra      Extra query args to merge.
	 * @return array Query args.
	 */
	private function build_order_query( $start_date, $end_date, $extra = array() ) {
		$args = wp_parse_args(
			$extra,
			array(
				'limit'  => -1,
				'return' => 'ids',
			)
		);

		if ( ! empty( $start_date ) ) {
			$args['date_created'] = '>=' . $start_date;
		}
		if ( ! empty( $end_date ) ) {
			$end_date_query = $end_date . ' 23:59:59';
			if ( isset( $args['date_created'] ) ) {
				$args['date_created'] = $args['date_created'] . ' ' . $end_date_query;
			} else {
				$args['date_created'] = '<=' . $end_date_query;
			}
		}

		return $args;
	}

	/**
	 * Calculate net profit for a given order.
	 *
	 * @param int $order_id The WooCommerce order ID.
	 * @return float Calculated profit.
	 */
	public function calculate_profit( $order_id ) {
		$breakdown = $this->get_order_profit_breakdown( $order_id );
		return $breakdown['profit'] ?? 0;
	}

	/**
	 * Get a detailed profit breakdown for an order.
	 *
	 * Sources data in priority order:
	 * - Delivery charge: courier booking meta > WooCommerce shipping total
	 * - COD fee: courier booking meta > manual _eom_cod_charge meta
	 * - Revenue: net of refunds
	 *
	 * @param int $order_id The WooCommerce order ID.
	 * @return array Associative array of profit components.
	 */
	public function get_order_profit_breakdown( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array();
		}

		// Net revenue = total minus refunds.
		$revenue      = (float) $order->get_total() - (float) $order->get_total_refunded();
		$product_cost = $this->get_order_product_cost( $order );

		// Calculate product revenue (sum of item line totals).
		$product_revenue = 0;
		foreach ( $order->get_items() as $item ) {
			$product_revenue += (float) $item->get_total();
		}
		$product_revenue = round( $product_revenue, 2 );

		// Use actual courier delivery charge from booking if available.
		$courier_charge  = (float) $order->get_meta( 'eom_courier_charge', true );
		$delivery_charge = $courier_charge > 0 ? $courier_charge : (float) $order->get_shipping_total();

		$gateway_fee = (float) $order->get_meta( '_eom_gateway_fee', true );

		// COD charge: 1% of product revenue (per-product line totals).
		$cod_charge = round( $product_revenue * 0.01, 2 );

		$ad_cost = (float) $order->get_meta( '_eom_ad_cost', true );

		$profit = $revenue - $product_cost - $delivery_charge - $gateway_fee - $cod_charge - $ad_cost;
		$margin = $revenue > 0 ? round( ( $profit / $revenue ) * 100, 2 ) : 0;

		return array(
			'revenue'           => $revenue,
			'product_revenue'   => $product_revenue,
			'product_cost'      => $product_cost,
			'delivery_charge'   => $delivery_charge,
			'gateway_fee'       => $gateway_fee,
			'cod_charge'        => $cod_charge,
			'ad_cost'           => $ad_cost,
			'profit'            => round( $profit, 2 ),
			'margin_percentage' => $margin,
		);
	}

	/**
	 * Calculate packaging material loss from returned orders.
	 *
	 * When an order is returned or returned-with-charge, the packaging
	 * materials (polybags, boxes, tape, etc.) have already been consumed
	 * and cannot be recovered. This method sums the per-product packaging
	 * cost × quantity for all returned orders in the date range.
	 *
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 * @return array {
	 *     Loss summary.
	 *
	 *     @type float $packaging_loss Total packaging cost lost.
	 *     @type int   $return_count   Number of returned orders.
	 *     @type array $orders         Returned order IDs.
	 * }
	 */
	public function calculate_return_packaging_loss( $start_date, $end_date ) {
		$statuses = array( 'returned', 'returned-charge' );

		$args = $this->build_order_query(
			$start_date,
			$end_date,
			array(
				'status' => $statuses,
				'return' => 'ids',
			)
		);

		$returned_orders = wc_get_orders( $args );
		$total_loss      = 0;

		foreach ( $returned_orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( ! $product ) {
					continue;
				}

				$packaging_cost = (float) get_post_meta( $product->get_id(), '_eom_packaging_cost', true );
				if ( $packaging_cost > 0 ) {
					$total_loss += $packaging_cost * $item->get_quantity();
				}
			}
		}

		return array(
			'packaging_loss' => round( $total_loss, 2 ),
			'return_count'   => count( $returned_orders ),
			'orders'         => $returned_orders,
		);
	}

	/**
	 * Save product cost meta for a product.
	 *
	 * @param int   $product_id The product ID.
	 * @param float $cost       The product cost.
	 * @return void
	 */
	public function save_product_cost( $product_id, $cost ) {
		$cost = wc_format_decimal( $cost );
		update_post_meta( absint( $product_id ), '_eom_product_cost', $cost );
	}

	/**
	 * Save ad cost meta on an order.
	 *
	 * @param int   $order_id The order ID.
	 * @param float $cost     The advertising cost.
	 * @return void
	 */
	public function save_order_ad_cost( $order_id, $cost ) {
		$cost  = wc_format_decimal( $cost );
		$order = wc_get_order( absint( $order_id ) );
		if ( $order ) {
			$order->update_meta_data( '_eom_ad_cost', $cost );
			$order->save_meta_data();
		}
	}

	/**
	 * Get profit summary for a date range.
	 *
	 * Includes packaging loss from returned orders and net profit after losses.
	 *
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 * @return array Summary totals with loss data.
	 */
	public function get_profit_summary( $start_date, $end_date ) {
		$args = $this->build_order_query(
			$start_date,
			$end_date,
			array(
				'status' => array( 'completed', 'processing' ),
			)
		);

		$orders = wc_get_orders( $args );

		$total_revenue = 0;
		$total_cost    = 0;
		$total_profit  = 0;
		$order_count   = count( $orders );
		$margins       = array();

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$revenue      = (float) $order->get_total() - (float) $order->get_total_refunded();
			$product_cost = $this->get_order_product_cost( $order );

			$total_revenue += $revenue;
			$total_cost    += $product_cost;

			$breakdown = $this->get_order_profit_breakdown( $order_id );
			$total_profit += $breakdown['profit'];
			if ( $revenue > 0 ) {
				$margins[] = $breakdown['margin_percentage'];
			}
		}

		$avg_margin = count( $margins ) > 0 ? round( array_sum( $margins ) / count( $margins ), 2 ) : 0;

		// Calculate packaging loss from returned orders.
		$return_loss = $this->calculate_return_packaging_loss( $start_date, $end_date );

		return array(
			'total_revenue'        => round( $total_revenue, 2 ),
			'total_cost'           => round( $total_cost, 2 ),
			'total_profit'         => round( $total_profit, 2 ),
			'avg_margin'           => $avg_margin,
			'order_count'          => $order_count,
			'return_packaging_loss' => $return_loss['packaging_loss'],
			'return_count'         => $return_loss['return_count'],
			'net_profit'           => round( $total_profit - $return_loss['packaging_loss'], 2 ),
			'returned_orders'      => $return_loss['orders'],
		);
	}

	/**
	 * Render the profit analytics admin page.
	 *
	 * @return void
	 */
	public function render_profit_page() {
		if ( ! current_user_can( 'eom_view_profit' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'easy-order-manager' ) );
		}

		$start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : gmdate( 'Y-m-01' );
		$end_date   = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : gmdate( 'Y-m-d' );

		$summary = $this->get_profit_summary( $start_date, $end_date );

		// Fetch per-order data for the main profit table.
		$profit_orders = wc_get_orders(
			$this->build_order_query(
				$start_date,
				$end_date,
				array(
					'status' => array( 'completed', 'processing' ),
				)
			)
		);

		// Fetch returned orders for the loss table.
		$returned_orders = $summary['returned_orders'];
		?>
		<div class="wrap eom-profit-wrap">
			<h1><?php esc_html_e( 'Profit & Loss', 'easy-order-manager' ); ?></h1>

			<form method="get" class="eom-date-filter" style="margin:15px 0;">
				<input type="hidden" name="page" value="eom-profit-loss">
				<label for="start_date"><?php esc_html_e( 'From:', 'easy-order-manager' ); ?></label>
				<input type="date" id="start_date" name="start_date" value="<?php echo esc_attr( $start_date ); ?>">
				<label for="end_date"><?php esc_html_e( 'To:', 'easy-order-manager' ); ?></label>
				<input type="date" id="end_date" name="end_date" value="<?php echo esc_attr( $end_date ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'easy-order-manager' ); ?></button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" target="eom_pl_csv_iframe" style="display:inline-block; margin-left:8px;">
				<input type="hidden" name="action" value="eom_export_profit_loss">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'eom_export_profit_loss' ) ); ?>">
				<input type="hidden" name="start_date" value="<?php echo esc_attr( $start_date ); ?>">
				<input type="hidden" name="end_date" value="<?php echo esc_attr( $end_date ); ?>">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Export CSV', 'easy-order-manager' ); ?></button>
			</form>
			<iframe name="eom_pl_csv_iframe" style="display:none;"></iframe>
			<div class="eom-summary-cards" style="display:flex; gap:20px; margin-bottom:20px; flex-wrap:wrap;">
				<div class="eom-card" style="flex:1; min-width:160px; background:#f0f6fc; padding:15px; border-radius:5px; text-align:center;">
					<h3><?php esc_html_e( 'Revenue', 'easy-order-manager' ); ?></h3>
					<p style="font-size:22px; font-weight:bold; color:#2271b1;"><?php echo wp_kses_post( wc_price( $summary['total_revenue'] ) ); ?></p>
				</div>
				<div class="eom-card" style="flex:1; min-width:160px; background:#fef7f1; padding:15px; border-radius:5px; text-align:center;">
					<h3><?php esc_html_e( 'Product Cost', 'easy-order-manager' ); ?></h3>
					<p style="font-size:22px; font-weight:bold; color:#d6773b;"><?php echo wp_kses_post( wc_price( $summary['total_cost'] ) ); ?></p>
				</div>
				<div class="eom-card" style="flex:1; min-width:160px; background:#f0f6f0; padding:15px; border-radius:5px; text-align:center;">
					<h3><?php esc_html_e( 'Net Profit', 'easy-order-manager' ); ?></h3>
					<p style="font-size:22px; font-weight:bold; color:<?php echo $summary['total_profit'] >= 0 ? '#46b450' : '#dc3232'; ?>;">
						<?php echo wp_kses_post( wc_price( $summary['total_profit'] ) ); ?>
					</p>
				</div>
				<div class="eom-card" style="flex:1; min-width:160px; background:#fcf0f0; padding:15px; border-radius:5px; text-align:center;">
					<h3><?php esc_html_e( 'Packaging Loss', 'easy-order-manager' ); ?></h3>
					<p style="font-size:22px; font-weight:bold; color:#dc3232;">
						<?php echo $summary['return_packaging_loss'] > 0 ? '-' . wp_kses_post( wc_price( $summary['return_packaging_loss'] ) ) : wp_kses_post( wc_price( 0 ) ); ?>
					</p>
					<small style="color:#999;"><?php echo esc_html( $summary['return_count'] ); ?> <?php esc_html_e( 'returned orders', 'easy-order-manager' ); ?></small>
				</div>
				<div class="eom-card" style="flex:1; min-width:160px; background:#f0f0fa; padding:15px; border-radius:5px; text-align:center;">
					<h3><?php esc_html_e( 'Net After Loss', 'easy-order-manager' ); ?></h3>
					<p style="font-size:22px; font-weight:bold; color:<?php echo $summary['net_profit'] >= 0 ? '#46b450' : '#dc3232'; ?>;">
						<?php echo wp_kses_post( wc_price( $summary['net_profit'] ) ); ?>
					</p>
				</div>
				<div class="eom-card" style="flex:1; min-width:120px; background:#f0f0f1; padding:15px; border-radius:5px; text-align:center;">
					<h3><?php esc_html_e( 'Margin', 'easy-order-manager' ); ?></h3>
					<p style="font-size:22px; font-weight:bold;"><?php echo esc_html( $summary['avg_margin'] ); ?>%</p>
					<small style="color:#999;"><?php echo esc_html( $summary['order_count'] ); ?> <?php esc_html_e( 'orders', 'easy-order-manager' ); ?></small>
				</div>
			</div>

			<h2 style="margin-top:30px;"><?php esc_html_e( 'Profit Orders (Processing & Completed)', 'easy-order-manager' ); ?></h2>
			<table class="wp-list-table widefat fixed striped" id="eom-profit-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order ID', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Date', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Revenue', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Product Cost', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Delivery', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Gateway Fee', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'COD', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Ad Cost', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Profit', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Margin', 'easy-order-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $profit_orders ) ) : ?>
						<tr>
							<td colspan="11" style="text-align:center; color:#999;"><?php esc_html_e( 'No processing or completed orders in this date range.', 'easy-order-manager' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $profit_orders as $order_id ) : ?>
							<?php
							$order       = wc_get_order( $order_id );
							if ( ! $order ) {
								continue;
							}
							$breakdown    = $this->get_order_profit_breakdown( $order_id );
							$order_date   = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '—';
							$customer     = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
							$profit_color = $breakdown['profit'] >= 0 ? 'green' : 'red';
							?>
							<tr>
								<td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ); ?>">#<?php echo esc_html( $order_id ); ?></a></td>
								<td><?php echo esc_html( $order_date ); ?></td>
								<td><?php echo esc_html( $customer ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $breakdown['revenue'] ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $breakdown['product_cost'] ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $breakdown['delivery_charge'] ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $breakdown['gateway_fee'] ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $breakdown['cod_charge'] ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $breakdown['ad_cost'] ) ); ?></td>
								<td style="color:<?php echo esc_attr( $profit_color ); ?>; font-weight:bold;">
									<?php echo wp_kses_post( wc_price( $breakdown['profit'] ) ); ?>
								</td>
								<td style="color:<?php echo esc_attr( $profit_color ); ?>;">
									<?php echo esc_html( $breakdown['margin_percentage'] ); ?>%
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( ! empty( $returned_orders ) ) : ?>
				<h2 style="margin-top:40px; color:#dc3232;"><?php esc_html_e( 'Returned Orders — Packaging Loss', 'easy-order-manager' ); ?></h2>
				<table class="wp-list-table widefat fixed striped" id="eom-return-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order ID', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Date', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Customer', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Status', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Products', 'easy-order-manager' ); ?></th>
							<th><?php esc_html_e( 'Packaging Loss', 'easy-order-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $returned_orders as $order_id ) : ?>
							<?php
							$order        = wc_get_order( $order_id );
							if ( ! $order ) {
								continue;
							}
							$order_date   = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '—';
							$customer     = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
							$order_status = $order->get_status();
							$status_label = wc_get_order_status_name( $order_status );

							// Calculate packaging loss for this order.
							$order_packaging_loss = 0;
							$item_details         = array();
							foreach ( $order->get_items() as $item ) {
								$product = $item->get_product();
								if ( ! $product ) {
									continue;
								}
								$pkg_cost = (float) get_post_meta( $product->get_id(), '_eom_packaging_cost', true );
								if ( $pkg_cost > 0 ) {
									$loss = $pkg_cost * $item->get_quantity();
									$order_packaging_loss += $loss;
									$item_details[] = $item->get_name() . ' x' . $item->get_quantity() . ' (' . wc_price( $pkg_cost ) . '/pc)';
								}
							}
							?>
							<tr>
								<td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ); ?>">#<?php echo esc_html( $order_id ); ?></a></td>
								<td><?php echo esc_html( $order_date ); ?></td>
								<td><?php echo esc_html( $customer ); ?></td>
								<td><span style="color:#dc3232; font-weight:bold;"><?php echo esc_html( $status_label ); ?></span></td>
								<td><small><?php echo esc_html( implode( '; ', $item_details ) ); ?></small></td>
								<td style="color:#dc3232; font-weight:bold;">-<?php echo wp_kses_post( wc_price( $order_packaging_loss ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr style="font-weight:bold; background:#fcf0f0;">
							<td colspan="5" style="text-align:right;"><?php esc_html_e( 'Total Packaging Loss:', 'easy-order-manager' ); ?></td>
							<td style="color:#dc3232;">-<?php echo wp_kses_post( wc_price( $summary['return_packaging_loss'] ) ); ?></td>
						</tr>
					</tfoot>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

/**
	 * AJAX handler: export P&L data as CSV download.
	 *
	 * @return void
	 */
	public function ajax_export_profit_loss() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'eom_export_profit_loss' ) ) {
			wp_die( 'Security check failed.' );
		}

		if ( ! current_user_can( 'eom_view_profit' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Permission denied.' );
		}

		$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : gmdate( 'Y-m-01' );
		$end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : gmdate( 'Y-m-d' );
		$filename   = 'eom-profit-loss-' . gmdate( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel compat.

		$output = fopen( 'php://output', 'w' );

		// Header row.
		fputcsv( $output, array(
			'Order ID',
			'Date',
			'Customer',
			'Phone',
			'Revenue',
			'Product Revenue',
			'Product Cost',
			'Delivery Charge',
			'Gateway Fee',
			'COD Charge (1%)',
			'Ad Cost',
			'Profit',
			'Margin %',
		) );

		$order_ids   = wc_get_orders( $this->build_order_query( $start_date, $end_date, array( 'status' => array( 'completed', 'processing' ) ) ) );
		$return_loss = $this->calculate_return_packaging_loss( $start_date, $end_date );

		$totals = array_fill( 0, 8, 0.0 );

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$b     = $this->get_order_profit_breakdown( $order_id );
			$date  = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '';
			$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			$phone = $order->get_billing_phone();

			fputcsv( $output, array(
				$order_id,
				$date,
				$name,
				$phone,
				$b['revenue'],
				$b['product_revenue'],
				$b['product_cost'],
				$b['delivery_charge'],
				$b['gateway_fee'],
				$b['cod_charge'],
				$b['ad_cost'],
				$b['profit'],
				$b['margin_percentage'],
			) );

			$totals[0] += $b['revenue'];
			$totals[1] += $b['product_revenue'];
			$totals[2] += $b['product_cost'];
			$totals[3] += $b['delivery_charge'];
			$totals[4] += $b['gateway_fee'];
			$totals[5] += $b['cod_charge'];
			$totals[6] += $b['ad_cost'];
			$totals[7] += $b['profit'];
		}

		// Summary rows.
		fputcsv( $output, array() );
		fputcsv( $output, array( '=== SUMMARY ===' ) );
		fputcsv( $output, array( 'Total Orders', count( $order_ids ) ) );
		fputcsv( $output, array( 'Total Revenue', $totals[0] ) );
		fputcsv( $output, array( 'Total Product Revenue', $totals[1] ) );
		fputcsv( $output, array( 'Total Product Cost', $totals[2] ) );
		fputcsv( $output, array( 'Total Delivery Charge', $totals[3] ) );
		fputcsv( $output, array( 'Total Gateway Fee', $totals[4] ) );
		fputcsv( $output, array( 'Total COD Charge (1%)', $totals[5] ) );
		fputcsv( $output, array( 'Total Ad Cost', $totals[6] ) );
		fputcsv( $output, array( 'Total Profit', $totals[7] ) );
		fputcsv( $output, array() );
		fputcsv( $output, array( '=== PACKAGING LOSS (Returned Orders) ===' ) );
		fputcsv( $output, array( 'Returned Orders', $return_loss['return_count'] ) );
		fputcsv( $output, array( 'Packaging Loss', $return_loss['packaging_loss'] ) );
		fputcsv( $output, array( 'Net Profit After Loss', $totals[7] - $return_loss['packaging_loss'] ) );

		fclose( $output );
		exit;
	}


	/**
	 * Render the profit column for the dashboard table.
	 *
	 * @param WC_Order|int $order The order object or ID.
	 * @return void
	 */
	public function render_profit_column( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			echo '—';
			return;
		}

		$profit  = $this->calculate_profit( $order->get_id() );
		$color   = $profit >= 0 ? 'green' : 'red';
		$display = wc_price( $profit );
		?>
		<span style="color:<?php echo esc_attr( $color ); ?>; font-weight:600;">
			<?php echo wp_kses_post( $display ); ?>
		</span>
		<?php
	}

	/**
	 * Add profit column to the dashboard column list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_profit_dashboard_column( $columns ) {
		if ( current_user_can( 'eom_view_profit' ) || current_user_can( 'manage_woocommerce' ) ) {
			$columns['profit'] = __( 'Profit', 'easy-order-manager' );
		}
		return $columns;
	}

	/**
	 * Add "Product Cost" and "Packaging Cost" fields to WooCommerce product general tab.
	 *
	 * @return void
	 */
	public function add_cost_fields_to_product() {
		global $post;

		if ( ! $post ) {
			return;
		}

		$cost = get_post_meta( $post->ID, '_eom_product_cost', true );
		?>
		<div class="options_group show_if_simple show_if_variable">
			<?php
			woocommerce_wp_text_input(
				array(
					'id'                => '_eom_product_cost',
					'label'             => __( 'Product Cost', 'easy-order-manager' ),
					'description'       => __( 'The purchase/cost price of this product. Used for profit calculations.', 'easy-order-manager' ),
					'type'              => 'text',
					'data_type'         => 'price',
					'value'             => $cost ? wc_format_localized_price( $cost ) : '',
					'custom_attributes' => array(
						'step' => 'any',
						'min'  => '0',
					),
				)
			);

			$packaging_cost = get_post_meta( $post->ID, '_eom_packaging_cost', true );
			woocommerce_wp_text_input(
				array(
					'id'                => '_eom_packaging_cost',
					'label'             => __( 'Packaging Cost', 'easy-order-manager' ),
					'description'       => __( 'Per-unit packaging cost. Also used to calculate material loss on returned orders.', 'easy-order-manager' ),
					'type'              => 'text',
					'data_type'         => 'price',
					'value'             => $packaging_cost ? wc_format_localized_price( $packaging_cost ) : '',
					'custom_attributes' => array(
						'step' => 'any',
						'min'  => '0',
					),
				)
			);
			?>
			<p class="description">
				<?php esc_html_e( 'You can also edit costs and packaging inline from the EOM Inventory page.', 'easy-order-manager' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Save the product cost and packaging cost fields.
	 *
	 * @param int $product_id The product ID.
	 * @return void
	 */
	public function save_product_cost_field( $product_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( isset( $_POST['_eom_product_cost'] ) ) {
			$cost = wc_clean( wp_unslash( $_POST['_eom_product_cost'] ) );
			if ( is_numeric( $cost ) ) {
				$this->save_product_cost( $product_id, $cost );
			} else {
				delete_post_meta( $product_id, '_eom_product_cost' );
			}
		}

		// Save packaging cost field.
		if ( isset( $_POST['_eom_packaging_cost'] ) ) {
			$packaging = wc_clean( wp_unslash( $_POST['_eom_packaging_cost'] ) );
			if ( is_numeric( $packaging ) ) {
				update_post_meta( $product_id, '_eom_packaging_cost', wc_format_decimal( $packaging ) );
			} else {
				delete_post_meta( $product_id, '_eom_packaging_cost' );
			}
		}
	}

	/**
	 * Get the total product cost for an order.
	 *
	 * Reads the _eom_product_cost and _eom_packaging_cost meta set via
	 * the WooCommerce product edit page or the EOM Inventory inline editor.
	 *
	 * @param WC_Order $order The order object.
	 * @return float Total product cost including packaging.
	 */
	private function get_order_product_cost( $order ) {
		$total_cost = 0;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$product_id   = $product->get_id();
			$product_cost = (float) get_post_meta( $product_id, '_eom_product_cost', true );

			if ( $product_cost <= 0 ) {
				// Fallback: use the product's regular price as estimated cost.
				$product_cost = (float) $product->get_price();
			}

			// Add per-unit packaging cost.
			$packaging_cost = (float) get_post_meta( $product_id, '_eom_packaging_cost', true );

			$total_cost += ( $product_cost + $packaging_cost ) * $item->get_quantity();
		}

		return $total_cost;
	}
}
