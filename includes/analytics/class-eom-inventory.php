<?php
/**
 * EOM Inventory
 *
 * Provides stock management, low-stock alerts, inventory logging,
 * inventory value reporting, and inline cost/packaging editing.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Inventory
 *
 * Handles stock tracking, adjustments, logging, low-stock alerts,
 * and product cost/packaging management with inline editing.
 */
class EOM_Inventory {

	/**
	 * Inventory log table name.
	 */
	const LOG_TABLE = 'eom_inventory_log';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_order_status_completed', array( $this, 'auto_deduct_on_order' ), 10, 1 );
		add_action( 'eom_weekly_inventory_check', array( $this, 'send_low_stock_alert' ) );
		add_filter( 'cron_schedules', array( $this, 'add_weekly_schedule' ) );
		add_action( 'wp_ajax_eom_save_product_cost', array( $this, 'ajax_save_product_cost' ) );
	}

	/**
	 * Add weekly schedule to cron.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_weekly_schedule( $schedules ) {
		$schedules['weekly'] = array(
			'interval' => 604800,
			'display'  => __( 'Once Weekly', 'easy-order-manager' ),
		);
		return $schedules;
	}

	/**
	 * Get stock status for a product.
	 *
	 * @param int $product_id The product ID.
	 * @return array Stock data including quantity and status.
	 */
	public function get_stock_status( $product_id ) {
		$product = wc_get_product( absint( $product_id ) );
		if ( ! $product ) {
			return array(
				'quantity' => 0,
				'status'   => 'unavailable',
				'managed'  => false,
			);
		}

		if ( $product->is_type( 'variable' ) ) {
			$quantities = array();
			$variations = $product->get_children();
			foreach ( $variations as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation && $variation->get_manage_stock() ) {
					$quantities[] = $variation->get_stock_quantity();
				}
			}
			$total_qty = ! empty( $quantities ) ? array_sum( $quantities ) : 0;
			$in_stock  = $total_qty > 0;
			return array(
				'quantity' => (int) $total_qty,
				'status'   => $in_stock ? 'in_stock' : 'out_of_stock',
				'managed'  => true,
			);
		}

		$managed = $product->get_manage_stock();
		if ( $managed ) {
			$qty = $product->get_stock_quantity();
			return array(
				'quantity' => (int) $qty,
				'status'   => $product->get_stock_status(),
				'managed'  => true,
			);
		}

		return array(
			'quantity' => 0,
			'status'   => $product->get_stock_status(),
			'managed'  => false,
		);
	}

	/**
	 * Get products with low stock (below threshold).
	 *
	 * @param int $threshold The stock threshold. Default 10.
	 * @return array Array of product IDs and data.
	 */
	public function get_low_stock_products( $threshold = 10 ) {
		$low_stock = array();

		$products = wc_get_products(
			array(
				'limit'  => -1,
				'type'   => array( 'simple', 'variable' ),
				'return' => 'objects',
			)
		);

		foreach ( $products as $product ) {
			$status = $this->get_stock_status( $product->get_id() );

			if ( $status['managed'] && $status['quantity'] < $threshold && $status['quantity'] > 0 ) {
				$low_stock[] = array(
					'product_id' => $product->get_id(),
					'name'       => $product->get_name(),
					'quantity'   => $status['quantity'],
					'threshold'  => $threshold,
				);
			} elseif ( $status['managed'] && 0 >= $status['quantity'] ) {
				$low_stock[] = array(
					'product_id'   => $product->get_id(),
					'name'         => $product->get_name(),
					'quantity'     => $status['quantity'],
					'threshold'    => $threshold,
					'out_of_stock' => true,
				);
			}
		}

		return $low_stock;
	}

	/**
	 * Log a stock change in the inventory log table.
	 *
	 * Creates the log table if it does not exist.
	 *
	 * @param int    $product_id The product ID.
	 * @param int    $quantity   The quantity changed (positive for addition, negative for deduction).
	 * @param string $type       The change type (e.g., 'deduction', 'adjustment', 'restock').
	 * @param int    $order_id   Optional order ID.
	 * @param string $note       Optional note describing the change.
	 * @return void
	 */
	public function log_stock_change( $product_id, $quantity, $type = 'adjustment', $order_id = 0, $note = '' ) {
		global $wpdb;

		$this->maybe_create_log_table();

		$product = wc_get_product( absint( $product_id ) );
		$product_name = $product ? $product->get_name() : 'Unknown';

		$wpdb->insert(
			$wpdb->prefix . self::LOG_TABLE,
			array(
				'product_id'   => absint( $product_id ),
				'product_name' => sanitize_text_field( $product_name ),
				'quantity'     => intval( $quantity ),
				'type'         => sanitize_text_field( $type ),
				'order_id'     => absint( $order_id ),
				'note'         => sanitize_textarea_field( $note ),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Auto-deduct stock when an order is completed.
	 *
	 * @param int $order_id The order ID.
	 * @return void
	 */
	public function auto_deduct_on_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Check if already deducted for this order.
		$already_deducted = $order->get_meta( '_eom_stock_deducted', true );
		if ( $already_deducted ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || ! $product->get_manage_stock() ) {
				continue;
			}

			$qty = $item->get_quantity();
			wc_update_product_stock( $product, $qty, 'decrease' );

			$this->log_stock_change(
				$product->get_id(),
				-$qty,
				'deduction',
				$order_id,
				sprintf(
					/* translators: 1: order ID, 2: quantity */
					__( 'Auto-deducted for order #%1$d (x%2$d)', 'easy-order-manager' ),
					$order_id,
					$qty
				)
			);
		}

		$order->update_meta_data( '_eom_stock_deducted', 'yes' );
		$order->save_meta_data();
	}

	/**
	 * Get stock movement report for a product or all products.
	 *
	 * @param int|null $product_id Optional product ID. If null, returns all.
	 * @return array Array of log entries.
	 */
	public function stock_report( $product_id = null ) {
		global $wpdb;

		$this->maybe_create_log_table();

		$table = $wpdb->prefix . self::LOG_TABLE;

		if ( $product_id ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE product_id = %d ORDER BY created_at DESC LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					absint( $product_id )
				),
				ARRAY_A
			);
		} else {
			$results = $wpdb->get_results(
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 1000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		}

		return $results ? $results : array();
	}

	/**
	 * AJAX handler for inline product cost and packaging cost saving.
	 *
	 * @return void
	 */
	public function ajax_save_product_cost() {
		check_ajax_referer( 'eom_save_product_cost' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$product_id      = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$cost            = isset( $_POST['cost'] ) ? floatval( $_POST['cost'] ) : null;
		$packaging_cost  = isset( $_POST['packaging_cost'] ) ? floatval( $_POST['packaging_cost'] ) : null;

		if ( ! $product_id ) {
			wp_send_json_error( __( 'Invalid product ID.', 'easy-order-manager' ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( __( 'Product not found.', 'easy-order-manager' ) );
		}

		if ( null !== $cost ) {
			if ( $cost > 0 ) {
				update_post_meta( $product_id, '_eom_product_cost', $cost );
			} else {
				delete_post_meta( $product_id, '_eom_product_cost' );
			}
		}

		if ( null !== $packaging_cost ) {
			if ( $packaging_cost > 0 ) {
				update_post_meta( $product_id, '_eom_packaging_cost', $packaging_cost );
			} else {
				delete_post_meta( $product_id, '_eom_packaging_cost' );
			}
		}

		// Recalculate total inventory value.
		$total_value = $this->get_inventory_value();

		wp_send_json_success(
			array(
				'formatted_cost'           => $cost > 0 ? (string) wc_price( $cost ) : '—',
				'cost'                     => $cost,
				'formatted_packaging_cost' => $packaging_cost > 0 ? (string) wc_price( $packaging_cost ) : '—',
				'packaging_cost'           => $packaging_cost,
				'total_value'              => (string) wc_price( $total_value ),
			)
		);
	}

	/**
	 * Render the inventory management admin page.
	 *
	 * @return void
	 */
	public function render_inventory_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'easy-order-manager' ) );
		}

		$threshold = isset( $_GET['threshold'] ) ? absint( $_GET['threshold'] ) : 10;
		$products  = wc_get_products(
			array(
				'limit'  => -1,
				'type'   => array( 'simple', 'variable' ),
				'return' => 'objects',
			)
		);

		$total_value = $this->get_inventory_value();
		$currency    = get_woocommerce_currency_symbol();
		$save_nonce  = wp_create_nonce( 'eom_save_product_cost' );
		?>
		<div class="wrap eom-inventory-wrap">
			<h1><?php esc_html_e( 'Inventory Management', 'easy-order-manager' ); ?></h1>

			<div class="eom-inv-summary" style="display:flex; gap:20px; margin:15px 0;">
				<div class="eom-card" style="flex:1; background:#f0f6fc; padding:15px; border-radius:5px; text-align:center;">
					<h3><?php esc_html_e( 'Total Products', 'easy-order-manager' ); ?></h3>
					<p style="font-size:24px; font-weight:bold;"><?php echo esc_html( count( $products ) ); ?></p>
				</div>
				<div class="eom-card" style="flex:1; background:#fef7f1; padding:15px; border-radius:5px; text-align:center;">
					<h3><?php esc_html_e( 'Low Stock (< ' . absint( $threshold ) . ')', 'easy-order-manager' ); ?></h3>
					<p style="font-size:24px; font-weight:bold; color:#d6773b;"><?php echo esc_html( count( $this->get_low_stock_products( $threshold ) ) ); ?></p>
				</div>
				<div class="eom-card" style="flex:1; background:#f0f6f0; padding:15px; border-radius:5px; text-align:center;">
					<h3><?php esc_html_e( 'Inventory Value', 'easy-order-manager' ); ?></h3>
					<p id="eom-total-value" style="font-size:24px; font-weight:bold; color:#46b450;"><?php echo wp_kses_post( wc_price( $total_value ) ); ?></p>
				</div>
			</div>

			<table class="wp-list-table widefat fixed striped" id="eom-inventory-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product ID', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Name', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'SKU', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Type', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Stock', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Cost', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Packaging', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Value', 'easy-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'easy-order-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $products ) ) : ?>
						<tr>
							<td colspan="10"><?php esc_html_e( 'No products found.', 'easy-order-manager' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $products as $product ) : ?>
							<?php
							$status         = $this->get_stock_status( $product->get_id() );
							$cost           = (float) get_post_meta( $product->get_id(), '_eom_product_cost', true );
							$packaging_cost = (float) get_post_meta( $product->get_id(), '_eom_packaging_cost', true );
							$value          = $cost * $status['quantity'];
							$is_low         = $status['managed'] && $status['quantity'] < $threshold;
							$row_style      = $is_low ? 'style="background-color:#fbeaea;"' : '';
							$type_label     = $product->is_type( 'variable' ) ? __( 'Variable', 'easy-order-manager' ) : __( 'Simple', 'easy-order-manager' );
							?>
							<tr <?php echo $row_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
								<td><?php echo esc_html( $product->get_id() ); ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $product->get_id() . '&action=edit' ) ); ?>">
										<?php echo esc_html( $product->get_name() ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $product->get_sku() ? $product->get_sku() : '—' ); ?></td>
								<td><?php echo esc_html( $type_label ); ?></td>
								<td>
									<?php if ( $status['managed'] ) : ?>
										<span class="eom-stock-qty" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
											<?php echo esc_html( $status['quantity'] ); ?>
										</span>
									<?php else : ?>
										<span style="color:#999;"><?php esc_html_e( 'N/A', 'easy-order-manager' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( 'out_of_stock' === $status['status'] ) : ?>
										<span style="color:#dc3232; font-weight:bold;"><?php esc_html_e( 'Out of Stock', 'easy-order-manager' ); ?></span>
									<?php elseif ( 'onbackorder' === $status['status'] ) : ?>
										<span style="color:#d6773b;"><?php esc_html_e( 'On Backorder', 'easy-order-manager' ); ?></span>
									<?php else : ?>
										<span style="color:#46b450;"><?php esc_html_e( 'In Stock', 'easy-order-manager' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="eom-cost-cell" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-cost="<?php echo esc_attr( $cost ); ?>">
									<span class="eom-cost-view">
										<?php echo $cost > 0 ? wp_kses_post( wc_price( $cost ) ) : '—'; ?>
									</span>
									<span class="eom-cost-edit" style="display:none;">
										<input type="number" step="0.01" min="0" class="eom-cost-input" value="<?php echo esc_attr( $cost ); ?>" style="width:80px;">
										<button type="button" class="button button-small eom-cost-save" title="<?php esc_attr_e( 'Save', 'easy-order-manager' ); ?>">✓</button>
										<button type="button" class="button button-small eom-cost-cancel" title="<?php esc_attr_e( 'Cancel', 'easy-order-manager' ); ?>">✗</button>
									</span>
									<button type="button" class="button button-small eom-cost-edit-btn"><?php esc_html_e( 'Edit', 'easy-order-manager' ); ?></button>
								</td>
								<td class="eom-packaging-cell" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-packaging-cost="<?php echo esc_attr( $packaging_cost ); ?>">
									<span class="eom-packaging-view">
										<?php echo $packaging_cost > 0 ? wp_kses_post( wc_price( $packaging_cost ) ) : '—'; ?>
									</span>
									<span class="eom-packaging-edit" style="display:none;">
										<input type="number" step="0.01" min="0" class="eom-packaging-input" value="<?php echo esc_attr( $packaging_cost ); ?>" style="width:80px;">
										<button type="button" class="button button-small eom-packaging-save" title="<?php esc_attr_e( 'Save', 'easy-order-manager' ); ?>">✓</button>
										<button type="button" class="button button-small eom-packaging-cancel" title="<?php esc_attr_e( 'Cancel', 'easy-order-manager' ); ?>">✗</button>
									</span>
									<button type="button" class="button button-small eom-packaging-edit-btn"><?php esc_html_e( 'Edit', 'easy-order-manager' ); ?></button>
								</td>
								<td class="eom-value-cell">
									<?php echo $value > 0 ? wp_kses_post( wc_price( $value ) ) : '—'; ?>
								</td>
								<td>
									<button type="button" class="button eom-adjust-stock" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
										<?php esc_html_e( 'Adjust', 'easy-order-manager' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<script type="text/javascript">
		jQuery( document ).ready( function( $ ) {
			var eomSaveCostNonce = '<?php echo esc_js( $save_nonce ); ?>';
			var currencySymbol   = '<?php echo esc_js( $currency ); ?>';

			// ─── Product Cost Inline Editing ─────────────────────────────

			// Show inline edit fields for cost.
			$( document ).on( 'click', '.eom-cost-edit-btn', function() {
				var $cell = $( this ).closest( '.eom-cost-cell' );
				$cell.find( '.eom-cost-view, .eom-cost-edit-btn' ).hide();
				$cell.find( '.eom-cost-edit' ).show().find( '.eom-cost-input' ).trigger( 'focus' );
			} );

			// Cancel cost editing.
			$( document ).on( 'click', '.eom-cost-cancel', function() {
				var $cell = $( this ).closest( '.eom-cost-cell' );
				var origCost = $cell.data( 'cost' );
				$cell.find( '.eom-cost-input' ).val( origCost );
				$cell.find( '.eom-cost-edit' ).hide();
				$cell.find( '.eom-cost-view, .eom-cost-edit-btn' ).show();
			} );

			// ─── Packaging Cost Inline Editing ───────────────────────────

			// Show inline edit fields for packaging cost.
			$( document ).on( 'click', '.eom-packaging-edit-btn', function() {
				var $cell = $( this ).closest( '.eom-packaging-cell' );
				$cell.find( '.eom-packaging-view, .eom-packaging-edit-btn' ).hide();
				$cell.find( '.eom-packaging-edit' ).show().find( '.eom-packaging-input' ).trigger( 'focus' );
			} );

			// Cancel packaging editing.
			$( document ).on( 'click', '.eom-packaging-cancel', function() {
				var $cell = $( this ).closest( '.eom-packaging-cell' );
				var orig = $cell.data( 'packaging-cost' );
				$cell.find( '.eom-packaging-input' ).val( orig );
				$cell.find( '.eom-packaging-edit' ).hide();
				$cell.find( '.eom-packaging-view, .eom-packaging-edit-btn' ).show();
			} );

			// ─── Save (handles both cost and packaging) ──────────────────

			$( document ).on( 'click', '.eom-cost-save, .eom-packaging-save', function() {
				var $btn     = $( this );
				var isCost   = $btn.hasClass( 'eom-cost-save' );
				var $cell    = $btn.closest( isCost ? '.eom-cost-cell' : '.eom-packaging-cell' );
				var prodId   = $cell.data( 'product-id' );
				var $row     = $cell.closest( 'tr' );

				// Gather values from both fields so one AJAX saves both.
				var $costCell         = $row.find( '.eom-cost-cell' );
				var $packagingCell    = $row.find( '.eom-packaging-cell' );
				var costValue         = $costCell.find( '.eom-cost-input' ).val();
				var packagingValue    = $packagingCell.find( '.eom-packaging-input' ).val();

				$.post( ajaxurl, {
					action:         'eom_save_product_cost',
					product_id:     prodId,
					cost:           costValue,
					packaging_cost: packagingValue,
					_ajax_nonce:    eomSaveCostNonce
				}, function( response ) {
					if ( response.success ) {
						// Update cost display.
						$costCell.find( '.eom-cost-view' ).html( response.data.formatted_cost );
						$costCell.data( 'cost', response.data.cost );
						$costCell.find( '.eom-cost-edit' ).hide();
						$costCell.find( '.eom-cost-view, .eom-cost-edit-btn' ).show();

						// Update packaging display.
						$packagingCell.find( '.eom-packaging-view' ).html( response.data.formatted_packaging_cost );
						$packagingCell.data( 'packaging-cost', response.data.packaging_cost );
						$packagingCell.find( '.eom-packaging-edit' ).hide();
						$packagingCell.find( '.eom-packaging-view, .eom-packaging-edit-btn' ).show();

						// Update value cell = qty * cost (not including packaging).
						var qtyText  = $row.find( '.eom-stock-qty' ).text().trim();
						var qty      = parseInt( qtyText ) || 0;
						var newVal   = response.data.cost * qty;
						$row.find( '.eom-value-cell' ).html(
							newVal > 0 ? currencySymbol + newVal.toFixed( 2 ) : '—'
						);

						// Update total inventory value card.
						$( '#eom-total-value' ).html( response.data.total_value );
					} else {
						alert( response.data || 'Error saving.' );
					}
				} );
			} );

			// ─── Keyboard support ────────────────────────────────────────

			// Enter key to save.
			$( document ).on( 'keypress', '.eom-cost-input, .eom-packaging-input', function( e ) {
				if ( e.which === 13 ) {
					var $cell = $( this ).closest( 'td' );
					$cell.find( '.eom-cost-save, .eom-packaging-save' ).first().trigger( 'click' );
				}
			} );

			// Escape key to cancel.
			$( document ).on( 'keyup', '.eom-cost-input, .eom-packaging-input', function( e ) {
				if ( e.which === 27 ) {
					var $cell = $( this ).closest( 'td' );
					$cell.find( '.eom-cost-cancel, .eom-packaging-cancel' ).first().trigger( 'click' );
				}
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Send low stock alert via Telegram and/or email.
	 * Designed to be called by wp_cron.
	 *
	 * @return void
	 */
	public function send_low_stock_alert() {
		$threshold = get_option( 'eom_low_stock_threshold', 10 );
		$low_stock = $this->get_low_stock_products( (int) $threshold );

		if ( empty( $low_stock ) ) {
			return;
		}

		$message = __( 'Low Stock Alert', 'easy-order-manager' ) . "\n";
		$message .= __( 'The following products are low on stock:', 'easy-order-manager' ) . "\n\n";

		foreach ( $low_stock as $item ) {
			$out = isset( $item['out_of_stock'] ) ? __( 'OUT OF STOCK', 'easy-order-manager' ) : '';
			$message .= sprintf(
				/* translators: 1: product name, 2: quantity, 3: out of stock marker */
				"- %s (Qty: %d) %s\n",
				$item['name'],
				$item['quantity'],
				$out
			);
		}

		// Send via Telegram if configured.
		$telegram_token = get_option( 'eom_telegram_bot_token', '' );
		$telegram_chat  = get_option( 'eom_telegram_chat_id', '' );
		if ( $telegram_token && $telegram_chat ) {
			$url = 'https://api.telegram.org/bot' . $telegram_token . '/sendMessage';
			wp_remote_post(
				$url,
				array(
					'body' => array(
						'chat_id' => $telegram_chat,
						'text'    => $message,
					),
				)
			);
		}

		// Send via email.
		$admin_email = get_option( 'admin_email' );
		$subject     = __( 'Low Stock Alert - Easy Order Manager', 'easy-order-manager' );
		wp_mail( $admin_email, $subject, $message );
	}

	/**
	 * Calculate total inventory value.
	 *
	 * @return float Total value of current stock (qty * product_cost).
	 */
	public function get_inventory_value() {
		$total = 0;

		$products = wc_get_products(
			array(
				'limit'  => -1,
				'type'   => array( 'simple', 'variable' ),
				'return' => 'objects',
			)
		);

		foreach ( $products as $product ) {
			$status = $this->get_stock_status( $product->get_id() );
			$cost   = (float) get_post_meta( $product->get_id(), '_eom_product_cost', true );

			if ( $cost > 0 && $status['managed'] ) {
				$total += $cost * $status['quantity'];
			}
		}

		return round( $total, 2 );
	}

	/**
	 * Manually adjust stock for a product.
	 *
	 * @param int    $product_id The product ID.
	 * @param int    $quantity   The quantity adjustment (positive to add, negative to subtract).
	 * @param string $reason     The reason for the adjustment.
	 * @return bool True on success, false on failure.
	 */
	public function add_stock_adjustment( $product_id, $quantity, $reason = '' ) {
		$product = wc_get_product( absint( $product_id ) );
		if ( ! $product || ! $product->get_manage_stock() ) {
			return false;
		}

		$quantity = intval( $quantity );
		if ( 0 === $quantity ) {
			return false;
		}

		if ( $quantity > 0 ) {
			wc_update_product_stock( $product, $quantity, 'increase' );
		} else {
			wc_update_product_stock( $product, abs( $quantity ), 'decrease' );
		}

		$this->log_stock_change( $product_id, $quantity, 'adjustment', 0, $reason );

		return true;
	}

	/**
	 * Ensure the inventory log table exists.
	 *
	 * @return void
	 */
	private function maybe_create_log_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::LOG_TABLE;
		$charset    = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			product_id BIGINT UNSIGNED NOT NULL,
			product_name VARCHAR(255) NOT NULL DEFAULT '',
			quantity INT NOT NULL DEFAULT 0,
			type VARCHAR(50) NOT NULL DEFAULT 'adjustment',
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			note TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_product (product_id),
			INDEX idx_type (type),
			INDEX idx_order (order_id),
			INDEX idx_created (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
