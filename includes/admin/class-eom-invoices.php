<?php
/**
 * EOM Admin Invoices
 *
 * Generates HTML invoices, supports bulk printing, PDF downloads,
 * and provides settings for store information on invoices.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Admin_Invoices
 *
 * Handles invoice generation, bulk printing, and settings.
 */
class EOM_Admin_Invoices {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_eom_print_invoice', array( $this, 'ajax_print_invoice' ) );
		add_action( 'wp_ajax_eom_bulk_print_invoices', array( $this, 'ajax_bulk_print_invoices' ) );
		add_action( 'wp_ajax_eom_download_invoice', array( $this, 'ajax_download_invoice' ) );
		add_action( 'woocommerce_admin_order_actions', array( $this, 'add_invoice_button' ), 10, 2 );
		add_action( 'eom_register_capabilities', array( $this, 'register_invoice_capabilities' ) );
	}

	/**
	 * Register invoice-related capabilities.
	 *
	 * @return void
	 */
	public function register_invoice_capabilities() {
		$role = get_role( 'eom_manager' );
		if ( $role ) {
			$role->add_cap( 'eom_print_invoice' );
			$role->add_cap( 'eom_download_invoice' );
		}
		$staff = get_role( 'eom_staff' );
		if ( $staff ) {
			$staff->add_cap( 'eom_print_invoice' );
		}
	}

	/**
	 * Generate an HTML invoice for a given order.
	 *
	 * @param int $order_id The WooCommerce order ID.
	 * @return string Generated HTML invoice.
	 */
	public function generate_invoice( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return '<p>' . esc_html__( 'Order not found.', 'easy-order-manager' ) . '</p>';
		}

		return $this->invoice_template( $order );
	}

	/**
	 * Bulk print invoices for multiple orders.
	 *
	 * @param array $order_ids Array of WooCommerce order IDs.
	 * @return void Outputs HTML directly with page breaks.
	 */
	public function print_invoices( $order_ids ) {
		if ( empty( $order_ids ) ) {
			echo '<p>' . esc_html__( 'No orders selected.', 'easy-order-manager' ) . '</p>';
			return;
		}

		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<title><?php esc_html_e( 'Print Invoices', 'easy-order-manager' ); ?></title>
			<style>
				@media print {
					body { font-family: Arial, sans-serif; font-size: 12px; margin: 0; padding: 0; }
					.invoice-page { page-break-after: always; padding: 20px; }
					.invoice-page:last-child { page-break-after: avoid; }
				}
				body { font-family: Arial, sans-serif; font-size: 12px; }
				.invoice-page { padding: 20px; border-bottom: 2px dashed #ccc; margin-bottom: 20px; }
				.invoice-header { text-align: center; margin-bottom: 20px; }
				.invoice-header img { max-height: 80px; }
				.invoice-header h2 { margin: 5px 0; }
				.invoice-info { display: flex; justify-content: space-between; margin-bottom: 15px; }
				.invoice-info .store-details { width: 48%; }
				.invoice-info .customer-details { width: 48%; text-align: right; }
				table { width: 100%; border-collapse: collapse; margin: 15px 0; }
				table th, table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
				table th { background-color: #f4f4f4; font-weight: bold; }
				table .product-img { width: 50px; }
				table .product-img img { max-width: 40px; max-height: 40px; }
				.totals { text-align: right; margin-top: 10px; }
				.totals table { width: auto; margin-left: auto; }
				.totals table td { border: none; padding: 4px 10px; }
				.totals table td.label { font-weight: bold; }
				.barcode { text-align: center; margin-top: 20px; }
				.invoice-footer { text-align: center; margin-top: 20px; font-size: 11px; color: #666; }
			</style>
		</head>
		<body>
			<?php
			foreach ( $order_ids as $oid ) {
				$order = wc_get_order( absint( $oid ) );
				if ( $order ) {
					echo '<div class="invoice-page">';
					echo $this->invoice_template( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '</div>';
				}
			}
			?>
			<script>window.onload = function() { window.print(); }</script>
		</body>
		</html>
		<?php
	}

	/**
	 * Download invoice as PDF or HTML.
	 *
	 * @param int $order_id The WooCommerce order ID.
	 * @return void
	 */
	public function download_invoice( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'easy-order-manager' ) );
		}

		$html = $this->invoice_template( $order );

		// Attempt PDF generation if DOMPDF or mPDF is available.
		if ( class_exists( 'Dompdf\Dompdf' ) ) {
			$dompdf = new Dompdf\Dompdf();
			$dompdf->loadHtml( $html );
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->render();
			$dompdf->stream( 'invoice-' . $order_id . '.pdf', array( 'Attachment' => true ) );
			exit;
		} elseif ( class_exists( 'mPDF' ) ) {
			$mpdf = new mPDF();
			$mpdf->WriteHTML( $html );
			$mpdf->Output( 'invoice-' . $order_id . '.pdf', 'D' );
			exit;
		}

		// Fallback: force download as HTML.
		$filename = 'invoice-' . $order_id . '.html';
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Render the invoice template HTML for a given order.
	 *
	 * @param WC_Order $order The WooCommerce order object.
	 * @return string Rendered invoice HTML.
	 */
	public function invoice_template( $order ) {
		$order_id   = $order->get_id();
		$store_name = get_option( 'eom_invoice_store_name', get_bloginfo( 'name' ) );
		$store_addr = get_option( 'eom_invoice_store_address', '' );
		$store_phone = get_option( 'eom_invoice_store_phone', '' );
		$store_email = get_option( 'eom_invoice_store_email', get_bloginfo( 'admin_email' ) );
		$store_logo = get_option( 'eom_invoice_store_logo', '' );
		$footer_text = get_option( 'eom_invoice_footer_text', '' );

		$billing_name    = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
		$billing_phone   = $order->get_billing_phone();
		$billing_email   = $order->get_billing_email();
		$billing_address = $order->get_billing_address_1();
		if ( $order->get_billing_address_2() ) {
			$billing_address .= ', ' . $order->get_billing_address_2();
		}
		$billing_address .= ', ' . $order->get_billing_city();
		$billing_address .= ', ' . $order->get_billing_state();
		$billing_address .= ' - ' . $order->get_billing_postcode();

		$shipping_name    = $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();
		$shipping_address = $order->get_shipping_address_1();
		if ( $order->get_shipping_address_2() ) {
			$shipping_address .= ', ' . $order->get_shipping_address_2();
		}
		$shipping_address .= ', ' . $order->get_shipping_city();
		$shipping_address .= ', ' . $order->get_shipping_state();
		$shipping_address .= ' - ' . $order->get_shipping_postcode();

		$order_date     = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'F j, Y' ) : '—';
		$order_status   = wc_get_order_status_name( $order->get_status() );
		$payment_method = $order->get_payment_method_title();

		$subtotal   = $order->get_subtotal();
		$shipping   = $order->get_shipping_total();
		$discount   = $order->get_discount_total();
		$total      = $order->get_total();
		$cod_charge = $order->get_meta( '_eom_cod_charge', true );

		$currency = $order->get_currency();

		ob_start();
		?>
		<div class="invoice-header">
			<?php if ( $store_logo ) : ?>
				<img src="<?php echo esc_url( $store_logo ); ?>" alt="<?php echo esc_attr( $store_name ); ?>">
			<?php endif; ?>
			<h2><?php echo esc_html( $store_name ); ?></h2>
		</div>

		<div class="invoice-info">
			<div class="store-details">
				<strong><?php esc_html_e( 'Store:', 'easy-order-manager' ); ?></strong><br>
				<?php echo esc_html( $store_addr ); ?><br>
				<?php echo esc_html( $store_phone ); ?><br>
				<?php echo esc_html( $store_email ); ?>
			</div>
			<div class="customer-details">
				<strong><?php esc_html_e( 'Bill To:', 'easy-order-manager' ); ?></strong><br>
				<?php echo esc_html( $billing_name ); ?><br>
				<?php echo esc_html( $billing_phone ); ?><br>
				<?php echo esc_html( $billing_email ); ?><br>
				<?php echo esc_html( $billing_address ); ?>
				<?php if ( ! empty( $shipping_name ) ) : ?>
					<br><br>
					<strong><?php esc_html_e( 'Ship To:', 'easy-order-manager' ); ?></strong><br>
					<?php echo esc_html( $shipping_name ); ?><br>
					<?php echo esc_html( $shipping_address ); ?>
				<?php endif; ?>
			</div>
		</div>

		<table>
			<tr>
				<td><strong><?php esc_html_e( 'Order #', 'easy-order-manager' ); ?></strong> <?php echo esc_html( $order_id ); ?></td>
				<td><strong><?php esc_html_e( 'Date:', 'easy-order-manager' ); ?></strong> <?php echo esc_html( $order_date ); ?></td>
				<td><strong><?php esc_html_e( 'Status:', 'easy-order-manager' ); ?></strong> <?php echo esc_html( $order_status ); ?></td>
				<td><strong><?php esc_html_e( 'Payment:', 'easy-order-manager' ); ?></strong> <?php echo esc_html( $payment_method ); ?></td>
			</tr>
		</table>

		<table>
			<thead>
				<tr>
					<th class="product-img"><?php esc_html_e( 'Image', 'easy-order-manager' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'easy-order-manager' ); ?></th>
					<th><?php esc_html_e( 'Product', 'easy-order-manager' ); ?></th>
					<th><?php esc_html_e( 'Qty', 'easy-order-manager' ); ?></th>
					<th><?php esc_html_e( 'Price', 'easy-order-manager' ); ?></th>
					<th><?php esc_html_e( 'Total', 'easy-order-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $order->get_items() as $item ) : ?>
					<?php
					$product   = $item->get_product();
					$image     = $product ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '';
					$sku       = $product ? $product->get_sku() : '';
					$item_name = $item->get_name();
					$qty       = $item->get_quantity();
					$price     = wc_format_decimal( $item->get_subtotal() / $qty, 2 );
					$item_total = wc_format_decimal( $item->get_total(), 2 );
					?>
					<tr>
						<td><?php echo $image ? '<img src="' . esc_url( $image ) . '" alt="">' : '—'; ?></td>
						<td><?php echo esc_html( $sku ? $sku : '—' ); ?></td>
						<td><?php echo esc_html( $item_name ); ?></td>
						<td><?php echo esc_html( $qty ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $price, array( 'currency' => $currency ) ) ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $item_total, array( 'currency' => $currency ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="totals">
			<table>
				<tr>
					<td class="label"><?php esc_html_e( 'Subtotal:', 'easy-order-manager' ); ?></td>
					<td><?php echo wp_kses_post( wc_price( $subtotal, array( 'currency' => $currency ) ) ); ?></td>
				</tr>
				<?php if ( $shipping > 0 ) : ?>
				<tr>
					<td class="label"><?php esc_html_e( 'Shipping:', 'easy-order-manager' ); ?></td>
					<td><?php echo wp_kses_post( wc_price( $shipping, array( 'currency' => $currency ) ) ); ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( $discount > 0 ) : ?>
				<tr>
					<td class="label"><?php esc_html_e( 'Discount:', 'easy-order-manager' ); ?></td>
					<td>-<?php echo wp_kses_post( wc_price( $discount, array( 'currency' => $currency ) ) ); ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( $cod_charge ) : ?>
				<tr>
					<td class="label"><?php esc_html_e( 'COD Charge:', 'easy-order-manager' ); ?></td>
					<td><?php echo wp_kses_post( wc_price( $cod_charge, array( 'currency' => $currency ) ) ); ?></td>
				</tr>
				<?php endif; ?>
				<tr>
					<td class="label"><strong><?php esc_html_e( 'Total:', 'easy-order-manager' ); ?></strong></td>
					<td><strong><?php echo wp_kses_post( wc_price( $total, array( 'currency' => $currency ) ) ); ?></strong></td>
				</tr>
			</table>
		</div>

		<div class="barcode">
			<?php echo $this->generate_barcode_svg( (string) $order_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<br><small><?php esc_html_e( 'Order #', 'easy-order-manager' ); ?> <?php echo esc_html( $order_id ); ?></small>
		</div>

		<?php if ( $footer_text ) : ?>
			<div class="invoice-footer">
				<?php echo wp_kses_post( nl2br( $footer_text ) ); ?>
			</div>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	/**
	 * Generate a simple Code128 barcode SVG for a given value.
	 *
	 * This is a lightweight SVG barcode generator. For production use,
	 * consider a dedicated PHP barcode library.
	 *
	 * @param string $code The data to encode (typically order ID).
	 * @return string SVG markup for the barcode.
	 */
	private function generate_barcode_svg( $code ) {
		$code = preg_replace( '/[^0-9A-Za-z]/', '', $code );
		if ( empty( $code ) ) {
			$code = '0';
		}

		// Simple visual representation using thin/thick bars.
		$bars = '';
		$chars = str_split( $code );
		$x = 0;
		$height = 50;

		foreach ( $chars as $char ) {
			$val = ord( $char );
			for ( $i = 0; $i < 8; $i++ ) {
				$bit = ( $val >> ( 7 - $i ) ) & 1;
				$width = $bit ? 3 : 1;
				if ( $bit ) {
					$bars .= '<rect x="' . $x . '" y="0" width="' . $width . '" height="' . $height . '" fill="#000"/>';
				}
				$x += $width + 1;
				if ( $x > 300 ) {
					break 2;
				}
			}
		}

		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $x . '" height="' . $height . '" viewBox="0 0 ' . $x . ' ' . $height . '">' . $bars . '</svg>';
	}

	/**
	 * Render invoice settings fields.
	 *
	 * @return void
	 */
	public function render_invoice_settings() {
		?>
		<h3><?php esc_html_e( 'Invoice Settings', 'easy-order-manager' ); ?></h3>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="eom_invoice_store_logo"><?php esc_html_e( 'Store Logo', 'easy-order-manager' ); ?></label>
				</th>
				<td>
					<input type="text" id="eom_invoice_store_logo" name="eom_invoice_store_logo" value="<?php echo esc_attr( get_option( 'eom_invoice_store_logo', '' ) ); ?>" class="regular-text" style="max-width:300px;">
					<button type="button" class="button eom-upload-logo"><?php esc_html_e( 'Upload Logo', 'easy-order-manager' ); ?></button>
					<p class="description"><?php esc_html_e( 'Upload or enter the URL of your store logo.', 'easy-order-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="eom_invoice_store_name"><?php esc_html_e( 'Store Name', 'easy-order-manager' ); ?></label>
				</th>
				<td>
					<input type="text" id="eom_invoice_store_name" name="eom_invoice_store_name" value="<?php echo esc_attr( get_option( 'eom_invoice_store_name', get_bloginfo( 'name' ) ) ); ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="eom_invoice_store_address"><?php esc_html_e( 'Store Address', 'easy-order-manager' ); ?></label>
				</th>
				<td>
					<textarea id="eom_invoice_store_address" name="eom_invoice_store_address" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'eom_invoice_store_address', '' ) ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="eom_invoice_store_phone"><?php esc_html_e( 'Store Phone', 'easy-order-manager' ); ?></label>
				</th>
				<td>
					<input type="text" id="eom_invoice_store_phone" name="eom_invoice_store_phone" value="<?php echo esc_attr( get_option( 'eom_invoice_store_phone', '' ) ); ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="eom_invoice_store_email"><?php esc_html_e( 'Store Email', 'easy-order-manager' ); ?></label>
				</th>
				<td>
					<input type="email" id="eom_invoice_store_email" name="eom_invoice_store_email" value="<?php echo esc_attr( get_option( 'eom_invoice_store_email', get_bloginfo( 'admin_email' ) ) ); ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="eom_invoice_footer_text"><?php esc_html_e( 'Footer Text', 'easy-order-manager' ); ?></label>
				</th>
				<td>
					<textarea id="eom_invoice_footer_text" name="eom_invoice_footer_text" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'eom_invoice_footer_text', '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Text to display at the bottom of every invoice (e.g., "Thank you for your business!").', 'easy-order-manager' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Add a "Print Invoice" action button to the WooCommerce order list row.
	 *
	 * @param WC_Order $order The order object.
	 * @return void
	 */
	public function add_invoice_button( $order ) {
		if ( ! current_user_can( 'eom_print_invoice' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$order_id = $order->get_id();
		?>
		<a class="button tips eom-print-invoice" href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=eom_print_invoice&order_id=' . $order_id . '&_wpnonce=' . wp_create_nonce( 'eom_print_invoice' ) ) ); ?>" target="_blank" data-tip="<?php esc_attr_e( 'Print Invoice', 'easy-order-manager' ); ?>">
			<?php esc_html_e( 'Invoice', 'easy-order-manager' ); ?>
		</a>
		<?php
	}

	/**
	 * AJAX handler: print a single invoice.
	 *
	 * @return void
	 */
	public function ajax_print_invoice() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( ! $order_id || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'eom_print_invoice' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'easy-order-manager' ) );
		}
		if ( ! current_user_can( 'eom_print_invoice' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'easy-order-manager' ) );
		}

		$this->print_invoices( array( $order_id ) );
		exit;
	}

	/**
	 * AJAX handler: bulk print invoices for multiple orders.
	 *
	 * Receives comma-separated order_ids via GET, renders all invoices
	 * with page breaks for bulk printing.
	 *
	 * @return void
	 */
	public function ajax_bulk_print_invoices() {
		$order_ids = isset( $_GET['order_ids'] ) ? array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['order_ids'] ) ) ) ) : array();

		if ( empty( $order_ids ) ) {
			wp_die( esc_html__( 'No orders selected.', 'easy-order-manager' ) );
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'eom_bulk_print_invoices' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'easy-order-manager' ) );
		}

		if ( ! current_user_can( 'eom_print_invoice' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'easy-order-manager' ) );
		}

		$this->print_invoices( $order_ids );
		exit;
	}

	/**
	 * AJAX handler: download a single invoice.
	 *
	 * @return void
	 */
	public function ajax_download_invoice() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( ! $order_id || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'eom_download_invoice' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'easy-order-manager' ) );
		}
		if ( ! current_user_can( 'eom_download_invoice' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'easy-order-manager' ) );
		}

		$this->download_invoice( $order_id );
		exit;
	}
}
