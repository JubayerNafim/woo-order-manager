<?php
/**
 * EOM Admin Filters
 *
 * Renders and processes the advanced filter bar for the order dashboard.
 * Filter state persists in user meta across page loads.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Admin_Filters
 *
 * Handles filter bar UI, query building, and filter state persistence.
 */
class EOM_Admin_Filters {

	/**
	 * User meta key for persisting filter state.
	 */
	const FILTER_META_KEY = '_eom_dashboard_filters';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_eom_search_products', array( $this, 'ajax_search_products' ) );
	}

	/**
	 * Render the full filter bar HTML.
	 *
	 * @return void
	 */
	public function render_filter_bar() {
		$filter_values = $this->get_filter_values();
		?>
		<div class="eom-filter-bar" style="background:#f0f0f1; padding:15px; margin:10px 0; border-radius:4px;">
			<div class="eom-filter-row" style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
				<!-- Status Dropdown -->
				<div class="eom-filter-item">
					<label for="eom-filter-status" style="display:block; font-weight:600; margin-bottom:3px; font-size:12px;">
						<?php esc_html_e( 'Status', 'easy-order-manager' ); ?>
					</label>
					<select id="eom-filter-status" name="eom_filter_status" style="min-width:150px;">
						<option value=""><?php esc_html_e( 'All Statuses', 'easy-order-manager' ); ?></option>
						<?php foreach ( $this->get_order_statuses() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( str_replace( 'wc-', '', $slug ) ); ?>" <?php selected( $filter_values['status'], str_replace( 'wc-', '', $slug ) ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<!-- Date Range: From -->
				<div class="eom-filter-item">
					<label for="eom-filter-date-from" style="display:block; font-weight:600; margin-bottom:3px; font-size:12px;">
						<?php esc_html_e( 'From Date', 'easy-order-manager' ); ?>
					</label>
					<input type="date" id="eom-filter-date-from" name="eom_filter_date_from" value="<?php echo esc_attr( $filter_values['date_from'] ); ?>">
				</div>

				<!-- Date Range: To -->
				<div class="eom-filter-item">
					<label for="eom-filter-date-to" style="display:block; font-weight:600; margin-bottom:3px; font-size:12px;">
						<?php esc_html_e( 'To Date', 'easy-order-manager' ); ?>
					</label>
					<input type="date" id="eom-filter-date-to" name="eom_filter_date_to" value="<?php echo esc_attr( $filter_values['date_to'] ); ?>">
				</div>

				<!-- Product Search (Select2) -->
				<div class="eom-filter-item">
					<label for="eom-filter-product" style="display:block; font-weight:600; margin-bottom:3px; font-size:12px;">
						<?php esc_html_e( 'Product', 'easy-order-manager' ); ?>
					</label>
					<select id="eom-filter-product" name="eom_filter_product" style="min-width:200px;" class="eom-select2-search">
						<option value=""><?php esc_html_e( 'All Products', 'easy-order-manager' ); ?></option>
					</select>
				</div>

				<!-- Category Dropdown -->
				<div class="eom-filter-item">
					<label for="eom-filter-category" style="display:block; font-weight:600; margin-bottom:3px; font-size:12px;">
						<?php esc_html_e( 'Category', 'easy-order-manager' ); ?>
					</label>
					<select id="eom-filter-category" name="eom_filter_category" style="min-width:150px;">
						<option value=""><?php esc_html_e( 'All Categories', 'easy-order-manager' ); ?></option>
						<?php
						$categories = get_terms( array(
							'taxonomy'   => 'product_cat',
							'hide_empty' => false,
						) );
						foreach ( $categories as $cat ) :
						?>
							<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $filter_values['category'], $cat->term_id ); ?>>
								<?php echo esc_html( $cat->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<!-- Courier Dropdown -->
				<div class="eom-filter-item">
					<label for="eom-filter-courier" style="display:block; font-weight:600; margin-bottom:3px; font-size:12px;">
						<?php esc_html_e( 'Courier', 'easy-order-manager' ); ?>
					</label>
					<select id="eom-filter-courier" name="eom_filter_courier" style="min-width:130px;">
						<option value=""><?php esc_html_e( 'All Couriers', 'easy-order-manager' ); ?></option>
						<?php foreach ( $this->get_couriers() as $courier_slug => $courier_label ) : ?>
							<option value="<?php echo esc_attr( $courier_slug ); ?>" <?php selected( $filter_values['courier'], $courier_slug ); ?>>
								<?php echo esc_html( $courier_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="eom-filter-row" style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-top:10px;">
				<!-- Staff Dropdown -->
				<div class="eom-filter-item">
					<label for="eom-filter-staff" style="display:block; font-weight:600; margin-bottom:3px; font-size:12px;">
						<?php esc_html_e( 'Assigned Staff', 'easy-order-manager' ); ?>
					</label>
					<select id="eom-filter-staff" name="eom_filter_staff" style="min-width:150px;">
						<option value=""><?php esc_html_e( 'All Staff', 'easy-order-manager' ); ?></option>
						<?php foreach ( $this->get_assigned_staff() as $staff ) : ?>
							<option value="<?php echo esc_attr( $staff->ID ); ?>" <?php selected( $filter_values['staff'], $staff->ID ); ?>>
								<?php echo esc_html( $staff->display_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<!-- Payment Method -->
				<div class="eom-filter-item">
					<label for="eom-filter-payment" style="display:block; font-weight:600; margin-bottom:3px; font-size:12px;">
						<?php esc_html_e( 'Payment Method', 'easy-order-manager' ); ?>
					</label>
					<select id="eom-filter-payment" name="eom_filter_payment" style="min-width:130px;">
						<option value=""><?php esc_html_e( 'All Methods', 'easy-order-manager' ); ?></option>
						<option value="cod" <?php selected( $filter_values['payment'], 'cod' ); ?>><?php esc_html_e( 'COD', 'easy-order-manager' ); ?></option>
						<option value="bkash" <?php selected( $filter_values['payment'], 'bkash' ); ?>><?php esc_html_e( 'bKash', 'easy-order-manager' ); ?></option>
						<option value="nagad" <?php selected( $filter_values['payment'], 'nagad' ); ?>><?php esc_html_e( 'Nagad', 'easy-order-manager' ); ?></option>
						<option value="card" <?php selected( $filter_values['payment'], 'card' ); ?>><?php esc_html_e( 'Card', 'easy-order-manager' ); ?></option>
						<option value="bank" <?php selected( $filter_values['payment'], 'bank' ); ?>><?php esc_html_e( 'Bank Transfer', 'easy-order-manager' ); ?></option>
					</select>
				</div>

				<!-- Search Box -->
				<div class="eom-filter-item">
					<label for="eom-filter-search" style="display:block; font-weight:600; margin-bottom:3px; font-size:12px;">
						<?php esc_html_e( 'Search', 'easy-order-manager' ); ?>
					</label>
					<input type="text" id="eom-filter-search" name="eom_filter_search"
						placeholder="<?php esc_attr_e( 'Order ID, Name, Phone, Email', 'easy-order-manager' ); ?>"
						value="<?php echo esc_attr( $filter_values['search'] ); ?>"
						style="min-width:220px;">
				</div>

				<!-- Action Buttons -->
				<div class="eom-filter-item" style="display:flex; gap:5px; align-items:flex-end;">
					<button type="button" class="button button-primary" id="eom-apply-filters">
						<?php esc_html_e( 'Apply', 'easy-order-manager' ); ?>
					</button>
					<button type="button" class="button" id="eom-reset-filters">
						<?php esc_html_e( 'Reset', 'easy-order-manager' ); ?>
					</button>
				</div>
			</div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Initialize Select2 for product search if available.
			if ($.fn.select2) {
				$('#eom-filter-product').select2({
					ajax: {
						url: '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>',
						dataType: 'json',
						delay: 300,
						data: function(params) {
							return {
								action: 'eom_search_products',
								search: params.term,
								_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'eom_search_products' ) ); ?>'
							};
						},
						processResults: function(response) {
							if (response.success) {
								return { results: response.data };
							}
							return { results: [] };
						},
						cache: true
					},
					minimumInputLength: 2,
					placeholder: '<?php echo esc_js( __( 'Search product...', 'easy-order-manager' ) ); ?>',
					allowClear: true
				});
			}
		});
		</script>
		<?php
	}

	/**
	 * Get current filter values from request or persisted user meta.
	 *
	 * @return array Filter values with defaults.
	 */
	public function get_filter_values() {
		$defaults = array(
			'status'    => '',
			'date_from' => '',
			'date_to'   => '',
			'product'   => '',
			'category'  => '',
			'courier'   => '',
			'staff'     => '',
			'payment'   => '',
			'search'    => '',
		);

		// Check if we have fresh request values.
		$from_request = array();
		foreach ( $defaults as $key => $default ) {
			$param = 'eom_filter_' . $key;
			if ( isset( $_REQUEST[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$from_request[ $key ] = sanitize_text_field( wp_unslash( $_REQUEST[ $param ] ) );
			}
		}

		if ( ! empty( array_filter( $from_request ) ) ) {
			// Persist to user meta.
			$this->save_filter_state( $from_request );
			return wp_parse_args( $from_request, $defaults );
		}

		// Fall back to persisted state.
		$saved = $this->get_saved_filter_state();
		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Build WP_Query / WC_Order_Query args from filter values.
	 *
	 * @param array $filter_values Filter values from get_filter_values().
	 * @return array Query args array.
	 */
	public function build_filter_query( $filter_values ) {
		$args = array();

		if ( ! empty( $filter_values['status'] ) ) {
			$status = 'wc-' === substr( $filter_values['status'], 0, 3 )
				? $filter_values['status']
				: 'wc-' . $filter_values['status'];
			$args['status'] = array( $status );
		}

		if ( ! empty( $filter_values['date_from'] ) ) {
			$args['date_created'] = '>=' . $filter_values['date_from'];
		}
		if ( ! empty( $filter_values['date_to'] ) ) {
			$date_to_end = $filter_values['date_to'] . ' 23:59:59';
			if ( isset( $args['date_created'] ) ) {
				$args['date_created'] = $args['date_created'] . ' ' . $date_to_end;
			} else {
				$args['date_created'] = '<=' . $date_to_end;
			}
		}

		if ( ! empty( $filter_values['product'] ) ) {
			$args['product_id'] = absint( $filter_values['product'] );
		}

		if ( ! empty( $filter_values['category'] ) ) {
			$args['category'] = array( absint( $filter_values['category'] ) );
		}

		if ( ! empty( $filter_values['courier'] ) ) {
			$args['meta_query'][] = array(
				'key'   => 'eom_courier_name',
				'value' => $filter_values['courier'],
			);
		}

		if ( ! empty( $filter_values['staff'] ) ) {
			$args['meta_query'][] = array(
				'key'   => 'eom_assigned_staff',
				'value' => absint( $filter_values['staff'] ),
			);
		}

		if ( ! empty( $filter_values['payment'] ) ) {
			$args['meta_query'][] = array(
				'key'   => '_payment_method',
				'value' => $filter_values['payment'],
			);
		}

		if ( ! empty( $filter_values['search'] ) ) {
			if ( is_numeric( $filter_values['search'] ) ) {
				$args['post__in'] = array( absint( $filter_values['search'] ) );
			} else {
				$args['meta_query'][] = array(
					'relation' => 'OR',
					array(
						'key'     => '_billing_first_name',
						'value'   => $filter_values['search'],
						'compare' => 'LIKE',
					),
					array(
						'key'     => '_billing_last_name',
						'value'   => $filter_values['search'],
						'compare' => 'LIKE',
					),
					array(
						'key'     => '_billing_phone',
						'value'   => $filter_values['search'],
						'compare' => 'LIKE',
					),
					array(
						'key'     => '_billing_email',
						'value'   => $filter_values['search'],
						'compare' => 'LIKE',
					),
				);
			}
		}

		return $args;
	}

	/**
	 * Get merged list of WooCommerce order statuses and custom statuses.
	 *
	 * @return array Status slug => label.
	 */
	public function get_order_statuses() {
		return wc_get_order_statuses();
	}

	/**
	 * Get enabled courier service list.
	 *
	 * @return array Courier slug => label.
	 */
	public function get_couriers() {
		$default_couriers = array(
			'steadfast'  => __( 'Steadfast', 'easy-order-manager' ),
			'redx'       => __( 'RedX', 'easy-order-manager' ),
			'pathao'     => __( 'Pathao', 'easy-order-manager' ),
			'sundarban'  => __( 'Sundarban', 'easy-order-manager' ),
			'ecourier'   => __( 'eCourier', 'easy-order-manager' ),
			'others'     => __( 'Others', 'easy-order-manager' ),
		);

		// Allow filtering so other code or settings can modify the list.
		return apply_filters( 'eom_couriers', $default_couriers );
	}

	/**
	 * Get users who have been assigned to orders.
	 *
	 * @return array List of WP_User objects.
	 */
	public function get_assigned_staff() {
		global $wpdb;

		$staff_ids = $wpdb->get_col(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
			WHERE meta_key = 'eom_assigned_staff'
			AND meta_value != ''"
		);

		if ( empty( $staff_ids ) ) {
			return array();
		}

		$staff_ids = array_map( 'absint', $staff_ids );
		$users     = get_users( array(
			'include' => $staff_ids,
		) );

		return $users;
	}

	/**
	 * AJAX handler: search products for Select2.
	 *
	 * @return void
	 */
	public function ajax_search_products() {
		check_ajax_referer( 'eom_search_products' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		$products = wc_get_products( array(
			's'     => $search,
			'limit' => 20,
			'return' => 'objects',
		) );

		$results = array();
		foreach ( $products as $product ) {
			$results[] = array(
				'id'   => $product->get_id(),
				'text' => $product->get_name() . ' (#' . $product->get_id() . ')',
			);
		}

		wp_send_json_success( $results );
	}

	/**
	 * Save filter state to user meta.
	 *
	 * @param array $filter_values Filter values to persist.
	 * @return void
	 */
	private function save_filter_state( $filter_values ) {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::FILTER_META_KEY, $filter_values );
		}
	}

	/**
	 * Get saved filter state from user meta.
	 *
	 * @return array Saved filter values or empty array.
	 */
	private function get_saved_filter_state() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}
		$saved = get_user_meta( $user_id, self::FILTER_META_KEY, true );
		return is_array( $saved ) ? $saved : array();
	}
}
