<?php
/**
 * EOM Admin Inline Edit
 *
 * AJAX handler for inline order field editing and modal editor rendering.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Admin_Inline_Edit
 *
 * Handles inline editing of order fields via AJAX.
 */
class EOM_Admin_Inline_Edit {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_eom_save_order_field', array( $this, 'save_order_field' ) );
		add_action( 'wp_ajax_eom_get_inline_editor', array( $this, 'ajax_get_inline_editor' ) );
	}

	/**
	 * Get the list of editable fields and their labels.
	 *
	 * @return array Associative array of field_name => label.
	 */
	public function get_editable_fields() {
		return array(
			'billing_first_name' => __( 'First Name', 'easy-order-manager' ),
			'billing_last_name'  => __( 'Last Name', 'easy-order-manager' ),
			'billing_phone'      => __( 'Phone', 'easy-order-manager' ),
			'billing_email'      => __( 'Email', 'easy-order-manager' ),
			'billing_address_1'  => __( 'Address Line 1', 'easy-order-manager' ),
			'billing_address_2'  => __( 'Address Line 2', 'easy-order-manager' ),
			'billing_city'       => __( 'City', 'easy-order-manager' ),
			'billing_state'      => __( 'State', 'easy-order-manager' ),
			'billing_postcode'   => __( 'Postcode', 'easy-order-manager' ),
			'shipping_address_1' => __( 'Shipping Address', 'easy-order-manager' ),
			'shipping_city'      => __( 'Shipping City', 'easy-order-manager' ),
			'shipping_state'     => __( 'Shipping State', 'easy-order-manager' ),
			'customer_note'      => __( 'Customer Note', 'easy-order-manager' ),
			'order_status'       => __( 'Order Status', 'easy-order-manager' ),
			'assigned_staff'     => __( 'Assigned Staff', 'easy-order-manager' ),
		);
	}

	/**
	 * AJAX handler: save a single order field.
	 *
	 * Receives order_id, field_name, field_value via POST.
	 * Validates field is allowed, updates order/meta, returns success/error.
	 *
	 * @return void
	 */
	public function save_order_field() {
		check_ajax_referer( 'eom_save_order_field' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$order_id   = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$field_name = isset( $_POST['field_name'] ) ? sanitize_text_field( wp_unslash( $_POST['field_name'] ) ) : '';
		$value      = isset( $_POST['field_value'] ) ? sanitize_text_field( wp_unslash( $_POST['field_value'] ) ) : '';

		if ( ! $order_id || ! $field_name ) {
			wp_send_json_error( __( 'Missing required parameters.', 'easy-order-manager' ) );
		}

		$allowed_fields = array_keys( $this->get_editable_fields() );
		if ( ! in_array( $field_name, $allowed_fields, true ) ) {
			wp_send_json_error( __( 'Field is not editable.', 'easy-order-manager' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( __( 'Order not found.', 'easy-order-manager' ) );
		}

		try {
			switch ( $field_name ) {
				case 'billing_first_name':
					$order->set_billing_first_name( $value );
					break;
				case 'billing_last_name':
					$order->set_billing_last_name( $value );
					break;
				case 'billing_phone':
					$order->set_billing_phone( $value );
					break;
				case 'billing_email':
					$order->set_billing_email( $value );
					break;
				case 'billing_address_1':
					$order->set_billing_address_1( $value );
					break;
				case 'billing_address_2':
					$order->set_billing_address_2( $value );
					break;
				case 'billing_city':
					$order->set_billing_city( $value );
					break;
				case 'billing_state':
					$order->set_billing_state( $value );
					break;
				case 'billing_postcode':
					$order->set_billing_postcode( $value );
					break;
				case 'shipping_address_1':
					$order->set_shipping_address_1( $value );
					break;
				case 'shipping_city':
					$order->set_shipping_city( $value );
					break;
				case 'shipping_state':
					$order->set_shipping_state( $value );
					break;
				case 'customer_note':
					$order->set_customer_note( $value );
					break;
				case 'order_status':
					$order->update_status( $value );
					break;
				case 'assigned_staff':
					update_post_meta( $order_id, 'eom_assigned_staff', $value );
					break;
				default:
					wp_send_json_error( __( 'Unknown field.', 'easy-order-manager' ) );
			}

			if ( 'assigned_staff' !== $field_name ) {
				$order->save();
			}

			// Log activity.
			$this->log_activity( $order_id, sprintf(
				/* translators: 1: field name, 2: new value */
				__( 'Updated %1$s to "%2$s" via inline edit.', 'easy-order-manager' ),
				$field_name,
				$value
			) );

			wp_send_json_success( array(
				'message' => __( 'Field updated successfully.', 'easy-order-manager' ),
				'field'   => $field_name,
				'value'   => $value,
			) );

		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * AJAX handler: return inline editor HTML for a given order.
	 *
	 * @return void
	 */
	public function ajax_get_inline_editor() {
		check_ajax_referer( 'eom_get_inline_editor' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( __( 'Invalid order ID.', 'easy-order-manager' ) );
		}

		$html = $this->render_inline_editor( $order_id );
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Render the inline editor modal HTML for a given order.
	 *
	 * @param int $order_id Order ID.
	 * @return string HTML content.
	 */
	public function render_inline_editor( $order_id ) {
		$order   = wc_get_order( $order_id );
		if ( ! $order ) {
			return '<p>' . esc_html__( 'Order not found.', 'easy-order-manager' ) . '</p>';
		}

		$fields      = $this->get_editable_fields();
		$statuses    = wc_get_order_statuses();
		$staff_users = get_users( array( 'role__in' => array( 'administrator', 'shop_manager' ) ) );

		ob_start();
		?>
		<div class="eom-inline-editor-wrap">
			<h3><?php echo esc_html( sprintf( __( 'Quick Edit: Order #%d', 'easy-order-manager' ), $order_id ) ); ?></h3>
			<form id="eom-inline-edit-form" data-order-id="<?php echo esc_attr( $order_id ); ?>">
				<table class="form-table">
					<?php foreach ( $fields as $field_name => $field_label ) : ?>
						<tr>
							<th scope="row">
								<label for="eom-field-<?php echo esc_attr( $field_name ); ?>"><?php echo esc_html( $field_label ); ?></label>
							</th>
							<td>
								<?php if ( 'order_status' === $field_name ) : ?>
									<select name="<?php echo esc_attr( $field_name ); ?>" id="eom-field-<?php echo esc_attr( $field_name ); ?>" class="eom-inline-field">
										<?php foreach ( $statuses as $slug => $label ) : ?>
											<option value="<?php echo esc_attr( str_replace( 'wc-', '', $slug ) ); ?>" <?php selected( $order->get_status(), str_replace( 'wc-', '', $slug ) ); ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php elseif ( 'assigned_staff' === $field_name ) : ?>
									<select name="<?php echo esc_attr( $field_name ); ?>" id="eom-field-<?php echo esc_attr( $field_name ); ?>" class="eom-inline-field">
										<option value=""><?php esc_html_e( 'None', 'easy-order-manager' ); ?></option>
										<?php foreach ( $staff_users as $staff ) : ?>
											<option value="<?php echo esc_attr( $staff->ID ); ?>" <?php selected( $order->get_meta( 'eom_assigned_staff', true ), $staff->ID ); ?>>
												<?php echo esc_html( $staff->display_name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php elseif ( 'customer_note' === $field_name ) : ?>
									<textarea name="<?php echo esc_attr( $field_name ); ?>" id="eom-field-<?php echo esc_attr( $field_name ); ?>" class="eom-inline-field" rows="3"><?php echo esc_textarea( $order->get_customer_note() ); ?></textarea>
								<?php else : ?>
									<?php
									$getter = 'get_' . $field_name;
									$current_value = '';
									if ( method_exists( $order, $getter ) ) {
										$current_value = $order->$getter();
									}
									?>
									<input type="text" name="<?php echo esc_attr( $field_name ); ?>" id="eom-field-<?php echo esc_attr( $field_name ); ?>" class="eom-inline-field regular-text" value="<?php echo esc_attr( $current_value ); ?>">
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'easy-order-manager' ); ?></button>
					<button type="button" class="button eom-inline-cancel"><?php esc_html_e( 'Cancel', 'easy-order-manager' ); ?></button>
					<span class="eom-inline-spinner" style="display:none;"></span>
				</p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Log activity to the eom_activity_log meta.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $message  Activity message.
	 * @return void
	 */
	private function log_activity( $order_id, $message ) {
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$log      = $order->get_meta( 'eom_activity_log', true );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[]    = array(
			'time'    => current_time( 'mysql' ),
			'user_id' => get_current_user_id(),
			'action'  => $message,
		);
		$order->update_meta_data( 'eom_activity_log', $log );
		$order->save_meta_data();
	}
}
