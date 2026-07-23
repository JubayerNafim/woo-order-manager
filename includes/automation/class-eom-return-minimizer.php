<?php
/**
 * EOM Return Minimizer
 *
 * AI-suggested return loss minimizer. Analyzes returned parcels and
 * suggests potential alternative buyers from existing customer base
 * who have purchased the same or similar products before.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Return_Minimizer
 *
 * Helps minimize losses from returned parcels by identifying potential
 * buyers for returned products. Provides admin interface for reviewing
 * return items and reassigning them to new customers.
 */
class EOM_Return_Minimizer {

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

		// AJAX handlers.
		add_action( 'wp_ajax_eom_get_suggestions', array( $this, 'ajax_get_suggestions' ) );
		add_action( 'wp_ajax_eom_reassign_parcel', array( $this, 'ajax_reassign_parcel' ) );
	}

	/**
	 * Get returned parcels from the courier bookings.
	 *
	 * Queries the bookings table for orders with status 'returned'
	 * along with WooCommerce order details.
	 *
	 * @param int $limit Maximum number of records to return.
	 *
	 * @return array[] Array of returned parcel records.
	 */
	public function get_returned_parcels( int $limit = 50 ): array {
		global $wpdb;

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT b.*, p.post_title as order_title
				FROM {$this->bookings_table} b
				INNER JOIN {$wpdb->posts} p ON b.order_id = p.ID
				WHERE (
				    b.status IN ('returned', 'cancelled', 'return_to_merchant', 'hold')
				    OR EXISTS (
				        SELECT 1 FROM {$wpdb->postmeta}
				        WHERE post_id = b.order_id
				        AND meta_key = 'eom_steadfast_delivery_status'
				        AND meta_value IN ('cancelled', 'return_to_merchant', 'hold')
				    )
				    OR b.order_id IN (
				        SELECT ID FROM {$wpdb->posts}
				        WHERE post_type = 'shop_order'
				        AND post_status = 'wc-eom-return-requested'
				    )
				)
				AND b.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
				ORDER BY b.updated_at DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			return array();
		}

		$returned = array();
		foreach ( $results as $row ) {
			$order = wc_get_order( (int) $row['order_id'] );
			if ( ! $order ) {
				continue;
			}

			$items = $order->get_items();
			$products = array();
			foreach ( $items as $item ) {
				$product = $item->get_product();
				$products[] = array(
					'product_id'   => $item->get_product_id(),
					'variation_id' => $item->get_variation_id(),
					'name'         => $item->get_name(),
					'quantity'     => $item->get_quantity(),
					'price'        => (float) $item->get_total(),
					'image'        => $product ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '',
				);
			}

			$returned[] = array(
				'order_id'       => (int) $row['order_id'],
				'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'customer_phone' => $order->get_billing_phone(),
				'customer_email' => $order->get_billing_email(),
				'order_total'    => (float) $order->get_total(),
				'courier_slug'   => $row['courier_slug'],
				'tracking_id'    => $row['tracking_id'],
				'return_date'    => $row['updated_at'],
				'products'       => $products,
				'has_suggestions'=> false,
			);
		}

		return $returned;
	}

	/**
	 * Find potential buyers for a product who are not the original customer.
	 *
	 * Searches order history for customers who have purchased the same
	 * product or products in the same category, excluding the original
	 * customer who returned the item.
	 *
	 * @param int $product_id         Product ID to find buyers for.
	 * @param int $exclude_customer_id Customer ID to exclude (original buyer).
	 *
	 * @return array[] Array of potential buyer records with order history.
	 */
	public function find_potential_buyers( int $product_id, int $exclude_customer_id = 0 ): array {
		global $wpdb;

		// Get the product categories.
		$category_ids = wc_get_product_term_ids( $product_id, 'product_cat' );

		// Find customers who ordered this product before.
		$buyers = array();

		// Query for customers who purchased the same product.
		$orders = wc_get_orders( array(
			'limit'        => 20,
			'return'       => 'ids',
			'status'       => array( 'completed', 'processing' ),
			'date_created' => '>' . gmdate( 'Y-m-d', strtotime( '-90 days' ) ),
		) );

		$customer_map = array();

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$customer_id = $order->get_customer_id();
			if ( $customer_id === $exclude_customer_id ) {
				continue;
			}

			$items = $order->get_items();
			$matched = false;

			foreach ( $items as $item ) {
				$ordered_product_id = $item->get_product_id();

				if ( (int) $ordered_product_id === $product_id ) {
					$matched = true;
					break;
				}

				// Also check category match.
				if ( ! empty( $category_ids ) ) {
					$item_category_ids = wc_get_product_term_ids( $ordered_product_id, 'product_cat' );
					$common_categories = array_intersect( $category_ids, $item_category_ids );
					if ( ! empty( $common_categories ) ) {
						$matched = true;
						break;
					}
				}
			}

			if ( ! $matched ) {
				continue;
			}

			$key = $customer_id > 0 ? 'user_' . $customer_id : 'guest_' . $order->get_billing_email();

			if ( ! isset( $customer_map[ $key ] ) ) {
				$customer_map[ $key ] = array(
					'customer_id'    => $customer_id,
					'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'customer_phone' => $order->get_billing_phone(),
					'customer_email' => $order->get_billing_email(),
					'city'           => $order->get_billing_city(),
					'total_orders'   => 0,
					'total_spent'    => 0,
					'last_order_date'=> '',
					'previous_products' => array(),
					'relevance_score'=> 0,
				);
			}

			$customer_map[ $key ]['total_orders']++;
			$customer_map[ $key ]['total_spent'] += (float) $order->get_total();
			$order_date = $order->get_date_created();
			if ( $order_date ) {
				$date_str = $order_date->date_i18n( 'Y-m-d' );
				if ( $date_str > $customer_map[ $key ]['last_order_date'] ) {
					$customer_map[ $key ]['last_order_date'] = $date_str;
				}
			}

			foreach ( $items as $item ) {
				$customer_map[ $key ]['previous_products'][] = $item->get_name();
			}

			// Relevance: exact product match scores higher.
			foreach ( $items as $item ) {
				if ( (int) $item->get_product_id() === $product_id ) {
					$customer_map[ $key ]['relevance_score'] += 10;
				} elseif ( ! empty( $category_ids ) ) {
					$item_cats = wc_get_product_term_ids( $item->get_product_id(), 'product_cat' );
					if ( ! empty( array_intersect( $category_ids, $item_cats ) ) ) {
						$customer_map[ $key ]['relevance_score'] += 5;
					}
				}
			}
		}

		// Sort by relevance score descending.
		usort( $customer_map, function( $a, $b ) {
			return $b['relevance_score'] - $a['relevance_score'];
		} );

		// Deduplicate product names.
		foreach ( $customer_map as &$buyer ) {
			$buyer['previous_products'] = array_unique( array_slice( $buyer['previous_products'], 0, 5 ) );
		}

		return array_slice( $customer_map, 0, 10 );
	}

	/**
	 * Suggest potential new customers for a returned order.
	 *
	 * @param int $return_order_id The order ID that was returned.
	 *
	 * @return array {
	 *     Suggestion data.
	 *
	 *     @type int   $order_id       Original order ID.
	 *     @type array $products       Products in the order.
	 *     @type array $suggestions    Array of potential buyer records.
	 * }
	 */
	public function suggest_reassign( int $return_order_id ): array {
		$order = wc_get_order( $return_order_id );
		if ( ! $order ) {
			return array(
				'order_id' => $return_order_id,
				'products' => array(),
				'suggestions' => array(),
			);
		}

		$original_customer_id = $order->get_customer_id();
		$items       = $order->get_items();
		$products    = array();
		$suggestions = array();

		foreach ( $items as $item ) {
			$product_id = $item->get_product_id();
			$product    = $item->get_product();

			$products[] = array(
				'product_id' => $product_id,
				'name'       => $item->get_name(),
				'quantity'   => $item->get_quantity(),
				'price'      => (float) $item->get_total(),
				'image'      => $product ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '',
			);

			// Find potential buyers for the first product (primary item).
			if ( empty( $suggestions ) ) {
				$potential   = $this->find_potential_buyers( $product_id, $original_customer_id );
				$suggestions = $potential;
			}
		}

		return array(
			'order_id'       => $return_order_id,
			'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'original_total' => (float) $order->get_total(),
			'products'       => $products,
			'suggestions'    => $suggestions,
		);
	}

	/**
	 * Reassign a returned parcel to a new customer by creating a new order.
	 *
	 * @param int  $return_order_id The returned order ID.
	 * @param int  $new_customer_id The new customer user ID (0 for guest).
	 * @param array $new_customer_data Optional. Guest customer data (name, phone, address).
	 *
	 * @return array {
	 *     Reassignment result.
	 *
	 *     @type bool  $success       Whether reassignment succeeded.
	 *     @type int   $new_order_id  The new order ID, if created.
	 *     @type string $message      Status message.
	 * }
	 */
	public function reassign_parcel( int $return_order_id, int $new_customer_id = 0, array $new_customer_data = array() ): array {
		$original_order = wc_get_order( $return_order_id );
		if ( ! $original_order ) {
			return array(
				'success' => false,
				'message' => __( 'Original order not found.', 'easy-order-manager' ),
			);
		}

		// Create a new order.
		$new_order = wc_create_order();

		if ( is_wp_error( $new_order ) ) {
			return array(
				'success' => false,
				'message' => $new_order->get_error_message(),
			);
		}

		// Copy products from original order.
		$items = $original_order->get_items();
		foreach ( $items as $item ) {
			$product_id = $item->get_product_id();
			$quantity   = $item->get_quantity();

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$new_order->add_product( $product, $quantity, array(
				'total' => $item->get_total(),
			) );
		}

		// Set customer.
		if ( $new_customer_id > 0 ) {
			$new_order->set_customer_id( $new_customer_id );
			$customer_user = get_userdata( $new_customer_id );
			if ( $customer_user ) {
				$new_order->set_billing_email( $customer_user->user_email );
			}
		} elseif ( ! empty( $new_customer_data ) ) {
			// Guest customer.
			if ( ! empty( $new_customer_data['email'] ) ) {
				$new_order->set_billing_email( $new_customer_data['email'] );
			}
			if ( ! empty( $new_customer_data['phone'] ) ) {
				$new_order->set_billing_phone( $new_customer_data['phone'] );
			}
		}

		// Set billing address from new customer data or original order.
		if ( ! empty( $new_customer_data ) ) {
			$address = array(
				'first_name' => isset( $new_customer_data['first_name'] ) ? $new_customer_data['first_name'] : ( isset( $new_customer_data['name'] ) ? $new_customer_data['name'] : '' ),
				'last_name'  => isset( $new_customer_data['last_name'] ) ? $new_customer_data['last_name'] : '',
				'address_1'  => isset( $new_customer_data['address'] ) ? $new_customer_data['address'] : '',
				'city'       => isset( $new_customer_data['city'] ) ? $new_customer_data['city'] : '',
				'phone'      => isset( $new_customer_data['phone'] ) ? $new_customer_data['phone'] : '',
				'email'      => isset( $new_customer_data['email'] ) ? $new_customer_data['email'] : '',
			);
			$new_order->set_address( $address, 'billing' );
			$new_order->set_address( $address, 'shipping' );
		} else {
			$new_order->set_address( $original_order->get_address( 'billing' ), 'billing' );
			$new_order->set_address( $original_order->get_address( 'shipping' ), 'shipping' );
		}

		// Set payment method.
		$new_order->set_payment_method( $original_order->get_payment_method() );
		$new_order->set_payment_method_title( $original_order->get_payment_method_title() );

		// Set totals.
		$new_order->set_total( $original_order->get_total() );

		// Set status to pending.
		$new_order->set_status( 'pending' );

		// Add meta to track as reassignment.
		$new_order->update_meta_data( 'eom_reassigned_from', $return_order_id );
		$new_order->update_meta_data( 'eom_is_reassignment', 'yes' );

		// Save the order.
		$new_order->save();

		$new_order_id = $new_order->get_id();

		// Log the reassignment.
		$this->log_reassignment( $original_order, $new_order );

		return array(
			'success'      => true,
			'new_order_id' => $new_order_id,
			'message'      => sprintf(
				/* translators: %1$d: New order ID, %2$d: Original order ID */
				__( 'New order #%1$d created from returned order #%2$d.', 'easy-order-manager' ),
				$new_order_id,
				$return_order_id
			),
		);
	}

	/**
	 * Log a reassignment to the activity log.
	 *
	 * @param \WC_Order $original_order Original returned order.
	 * @param \WC_Order $new_order      Newly created order.
	 *
	 * @return void
	 */
	public function log_reassignment( \WC_Order $original_order, \WC_Order $new_order ): void {
		global $wpdb;

		$details = sprintf(
			/* translators: %1$d: New order ID, %2$d: Original order ID */
			__( 'Return minimized: Created order #%1$d from returned order #%2$d.', 'easy-order-manager' ),
			$new_order->get_id(),
			$original_order->get_id()
		);

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->activity_table,
			array(
				'order_id'   => $new_order->get_id(),
				'user_id'    => get_current_user_id(),
				'action'     => 'return_reassignment',
				'details'    => $details,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		// Also log on the original order.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->activity_table,
			array(
				'order_id'   => $original_order->get_id(),
				'user_id'    => get_current_user_id(),
				'action'     => 'return_reassignment_source',
				'details'    => sprintf(
					/* translators: %1$d: New order ID */
					__( 'Products reassigned to new order #%1$d.', 'easy-order-manager' ),
					$new_order->get_id()
				),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Add admin submenu page.
	 *
	 * @return void
	 */
	public function add_admin_page(): void {
		add_submenu_page(
			'eom-dashboard',
			__( 'Return Minimizer', 'easy-order-manager' ),
			__( 'Return Minimizer', 'easy-order-manager' ),
			'manage_woocommerce',
			'eom-return-minimizer',
			array( $this, 'render_suggestions_page' )
		);
	}

	/**
	 * Render the return minimizer admin page.
	 *
	 * @return void
	 */
	public function render_suggestions_page(): void {
		$returned_parcels = $this->get_returned_parcels();

		?>
		<div class="wrap eom-return-minimizer-wrap">
			<h1><?php esc_html_e( 'Return Minimizer', 'easy-order-manager' ); ?></h1>
			<p><?php esc_html_e( 'Review returned parcels and find alternative buyers to minimize return losses.', 'easy-order-manager' ); ?></p>

			<?php if ( empty( $returned_parcels ) ) : ?>
				<div class="notice notice-success">
					<p><?php esc_html_e( 'No returned parcels found in the last 30 days. Good job!', 'easy-order-manager' ); ?></p>
				</div>
			<?php else : ?>
				<div class="eom-return-stats">
					<p>
						<strong><?php echo esc_html( count( $returned_parcels ) ); ?></strong>
						<?php esc_html_e( 'returned parcel(s) found in the last 30 days.', 'easy-order-manager' ); ?>
					</p>
				</div>

				<div class="eom-return-cards">
					<?php foreach ( $returned_parcels as $parcel ) : ?>
						<div class="eom-return-card" data-order-id="<?php echo esc_attr( $parcel['order_id'] ); ?>">
							<div class="eom-return-card-header">
								<h3>
									<?php esc_html_e( 'Order', 'easy-order-manager' ); ?> #<?php echo esc_html( $parcel['order_id'] ); ?>
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $parcel['order_id'] . '&action=edit' ) ); ?>" class="button button-small" target="_blank">
										<?php esc_html_e( 'View', 'easy-order-manager' ); ?>
									</a>
								</h3>
								<span class="eom-return-customer"><?php echo esc_html( $parcel['customer_name'] ); ?></span>
								<span class="eom-return-date"><?php echo esc_html( $parcel['return_date'] ); ?></span>
							</div>

							<div class="eom-return-card-body">
								<div class="eom-return-products">
									<h4><?php esc_html_e( 'Products:', 'easy-order-manager' ); ?></h4>
									<ul>
										<?php foreach ( $parcel['products'] as $product ) : ?>
											<li>
												<?php if ( $product['image'] ) : ?>
													<img src="<?php echo esc_url( $product['image'] ); ?>" width="40" height="40" alt="">
												<?php endif; ?>
												<span><?php echo esc_html( $product['name'] ); ?> x<?php echo esc_html( $product['quantity'] ); ?></span>
												<span>৳<?php echo esc_html( number_format( $product['price'], 2 ) ); ?></span>
											</li>
										<?php endforeach; ?>
									</ul>
								</div>

								<div class="eom-return-actions">
									<button type="button" class="button button-primary eom-find-buyers" data-order-id="<?php echo esc_attr( $parcel['order_id'] ); ?>">
										<?php esc_html_e( 'Suggest New Customer', 'easy-order-manager' ); ?>
									</button>
								</div>

								<div class="eom-suggestions-container" id="eom-suggestions-<?php echo esc_attr( $parcel['order_id'] ); ?>" style="display:none;">
									<div class="eom-suggestions-loading">
										<span class="spinner is-active"></span>
										<?php esc_html_e( 'Finding potential buyers...', 'easy-order-manager' ); ?>
									</div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<style>
		.eom-return-cards {
			display: grid;
			gap: 20px;
			margin-top: 20px;
		}
		.eom-return-card {
			background: #fff;
			border: 1px solid #dcdcde;
			border-radius: 8px;
			padding: 20px;
			box-shadow: 0 1px 3px rgba(0,0,0,0.05);
		}
		.eom-return-card-header {
			display: flex;
			align-items: center;
			gap: 15px;
			margin-bottom: 15px;
			padding-bottom: 10px;
			border-bottom: 1px solid #f0f0f1;
		}
		.eom-return-card-header h3 {
			margin: 0;
			display: flex;
			align-items: center;
			gap: 10px;
		}
		.eom-return-customer {
			color: #646970;
		}
		.eom-return-date {
			color: #8c8f94;
			font-size: 12px;
			margin-left: auto;
		}
		.eom-return-products ul {
			list-style: none;
			padding: 0;
			margin: 0;
		}
		.eom-return-products li {
			display: flex;
			align-items: center;
			gap: 10px;
			padding: 5px 0;
		}
		.eom-return-products li img {
			border-radius: 4px;
			object-fit: cover;
		}
		.eom-return-actions {
			margin-top: 15px;
		}
		.eom-suggestions-container {
			margin-top: 15px;
			padding: 15px;
			background: #f6f7f7;
			border-radius: 6px;
		}
		.eom-suggestions-loading {
			display: flex;
			align-items: center;
			gap: 10px;
			color: #646970;
		}
		.eom-suggestion-item {
			display: flex;
			align-items: center;
			justify-content: space-between;
			padding: 10px;
			background: #fff;
			border: 1px solid #f0f0f1;
			border-radius: 4px;
			margin-bottom: 8px;
		}
		.eom-suggestion-item:last-child {
			margin-bottom: 0;
		}
		.eom-suggestion-info {
			flex: 1;
		}
		.eom-suggestion-name {
			font-weight: 600;
		}
		.eom-suggestion-meta {
			font-size: 12px;
			color: #646970;
		}
		.eom-suggestion-score {
			background: #2271b1;
			color: #fff;
			padding: 2px 8px;
			border-radius: 10px;
			font-size: 11px;
			margin-left: 10px;
		}
		.eom-no-suggestions {
			color: #646970;
			font-style: italic;
		}
		</style>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('.eom-find-buyers').on('click', function() {
				var orderId = $(this).data('order-id');
				var container = $('#eom-suggestions-' + orderId);
				var btn = $(this);

				btn.prop('disabled', true).text('<?php echo esc_js( __( 'Searching...', 'easy-order-manager' ) ); ?>');
				container.show();

				$.post(ajaxurl, {
					action: 'eom_get_suggestions',
					order_id: orderId,
					_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'eom_get_suggestions' ) ); ?>'
				}, function(response) {
					btn.prop('disabled', false).text('<?php echo esc_js( __( 'Suggest New Customer', 'easy-order-manager' ) ); ?>');

					if (response.success && response.data.suggestions.length > 0) {
						var html = '<h4><?php echo esc_js( __( 'Potential Buyers:', 'easy-order-manager' ) ); ?></h4>';
						$.each(response.data.suggestions, function(i, suggestion) {
							var products = suggestion.previous_products ? suggestion.previous_products.join(', ') : '';
							html += '<div class="eom-suggestion-item">';
							html += '<div class="eom-suggestion-info">';
							html += '<div class="eom-suggestion-name">' + $('<span>').text(suggestion.customer_name).html() + ' <span class="eom-suggestion-score">' + suggestion.relevance_score + '</span></div>';
							html += '<div class="eom-suggestion-meta">' + suggestion.customer_phone + ' | ' + suggestion.customer_email + ' | ' + suggestion.city + ' | ' + suggestion.total_orders + ' order(s) | ৳' + suggestion.total_spent.toFixed(2) + '</div>';
							if (products) {
								html += '<div class="eom-suggestion-meta"><?php echo esc_js( __( 'Previously bought:', 'easy-order-manager' ) ); ?> ' + products + '</div>';
							}
							html += '</div>';
							html += '<button type="button" class="button button-small eom-reassign-btn" data-order-id="' + orderId + '" data-customer-id="' + suggestion.customer_id + '" data-customer-name="' + $('<span>').text(suggestion.customer_name).html() + '"><?php echo esc_js( __( 'Reassign', 'easy-order-manager' ) ); ?></button>';
							html += '</div>';
						});
						container.html(html);
					} else {
						container.html('<p class="eom-no-suggestions"><?php echo esc_js( __( 'No potential buyers found. Try a different product or check back later.', 'easy-order-manager' ) ); ?></p>');
					}
				}).fail(function() {
					btn.prop('disabled', false).text('<?php echo esc_js( __( 'Suggest New Customer', 'easy-order-manager' ) ); ?>');
					container.html('<p class="eom-no-suggestions"><?php echo esc_js( __( 'Error fetching suggestions. Please try again.', 'easy-order-manager' ) ); ?></p>');
				});
			});

			$(document).on('click', '.eom-reassign-btn', function() {
				var orderId = $(this).data('order-id');
				var customerId = $(this).data('customer-id');
				var customerName = $(this).data('customer-name');
				var btn = $(this);

				if (!confirm('<?php echo esc_js( __( 'Create a new order for', 'easy-order-manager' ) ); ?> ' + customerName + '?')) {
					return;
				}

				btn.prop('disabled', true).text('<?php echo esc_js( __( 'Creating...', 'easy-order-manager' ) ); ?>');

				$.post(ajaxurl, {
					action: 'eom_reassign_parcel',
					order_id: orderId,
					customer_id: customerId,
					_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'eom_reassign_parcel' ) ); ?>'
				}, function(response) {
					if (response.success) {
						btn.text('<?php echo esc_js( __( 'Done!', 'easy-order-manager' ) ); ?>').removeClass('button').css('color', '#10b981');
						alert('<?php echo esc_js( __( 'New order created:', 'easy-order-manager' ) ); ?> #' + response.data.new_order_id);
					} else {
						btn.prop('disabled', false).text('<?php echo esc_js( __( 'Reassign', 'easy-order-manager' ) ); ?>');
						alert(response.data.message || '<?php echo esc_js( __( 'Failed to create order.', 'easy-order-manager' ) ); ?>');
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX handler to get suggestions for a returned order.
	 *
	 * @return void
	 */
	public function ajax_get_suggestions(): void {
		check_ajax_referer( 'eom_get_suggestions' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( __( 'Invalid order ID.', 'easy-order-manager' ) );
		}

		$suggestions = $this->suggest_reassign( $order_id );
		wp_send_json_success( $suggestions );
	}

	/**
	 * AJAX handler to reassign a returned parcel to a new customer.
	 *
	 * @return void
	 */
	public function ajax_reassign_parcel(): void {
		check_ajax_referer( 'eom_reassign_parcel' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-order-manager' ) ) );
		}

		$order_id    = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;

		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'easy-order-manager' ) ) );
		}

		$result = $this->reassign_parcel( $order_id, $customer_id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}
}
