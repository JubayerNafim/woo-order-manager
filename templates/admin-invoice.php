<?php
/**
 * EOM Invoice Template
 *
 * Professional invoice template with store logo, barcode,
 * and clean print layout. Supports bulk printing with page breaks.
 *
 * @package EasyOrderManager
 * @var WC_Order $order The WooCommerce order to invoice.
 */

defined( 'ABSPATH' ) || exit;

$store_logo   = get_option( 'eom_invoice_logo', '' );
$store_name   = get_option( 'eom_invoice_store_name', get_bloginfo( 'name' ) );
$store_addr   = get_option( 'eom_invoice_store_address', '' );
$store_phone  = get_option( 'eom_invoice_store_phone', '' );
$store_email  = get_option( 'eom_invoice_store_email', get_bloginfo( 'admin_email' ) );
$footer_text  = get_option( 'eom_invoice_footer', __( 'Thank you for your business!', 'easy-order-manager' ) );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Invoice', 'easy-order-manager' ); ?> #<?php echo esc_html( $order->get_order_number() ); ?></title>
	<style>
		* { margin: 0; padding: 0; box-sizing: border-box; }
		body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 11pt; color: #333; line-height: 1.5; padding: 0; margin: 0; }
		.invoice-page { width: 210mm; min-height: 297mm; padding: 15mm 20mm; margin: 0 auto; page-break-after: always; }
		.invoice-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #2563eb; }
		.invoice-logo { max-height: 70px; max-width: 200px; }
		.invoice-title { text-align: right; }
		.invoice-title h1 { font-size: 24pt; color: #2563eb; margin-bottom: 5px; }
		.invoice-title .invoice-number { font-size: 12pt; color: #666; }
		.store-info { margin-bottom: 20px; font-size: 9pt; color: #555; }
		.store-info .store-name { font-size: 14pt; font-weight: 700; color: #111; }
		.parties { display: flex; justify-content: space-between; margin-bottom: 20px; }
		.party { width: 48%; }
		.party h3 { font-size: 10pt; color: #666; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
		.party p { font-size: 10pt; line-height: 1.6; }
		table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
		table.items th { background: #2563eb; color: #fff; padding: 8px 10px; text-align: left; font-size: 9pt; text-transform: uppercase; }
		table.items td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; font-size: 10pt; }
		table.items tr:nth-child(even) td { background: #f9fafb; }
		.totals { margin-left: auto; width: 300px; }
		.totals table { width: 100%; border-collapse: collapse; }
		.totals td { padding: 5px 10px; font-size: 10pt; }
		.totals .total-row td { font-weight: 700; font-size: 12pt; border-top: 2px solid #333; }
		.totals .total-amount { color: #2563eb; }
		.barcode { text-align: center; margin-top: 30px; }
		.barcode svg { max-width: 200px; }
		.invoice-footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #ddd; text-align: center; font-size: 9pt; color: #999; }
		.print-btn { display: block; width: 200px; margin: 20px auto; padding: 10px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-size: 11pt; cursor: pointer; text-align: center; }
		@media print { .print-btn, .no-print { display: none !important; } }
		@page { margin: 0; size: A4; }
	</style>
</head>
<body>
	<div class="invoice-page">
		<div class="invoice-header">
			<div>
				<?php if ( $store_logo ) : ?>
					<img src="<?php echo esc_url( $store_logo ); ?>" alt="<?php echo esc_attr( $store_name ); ?>" class="invoice-logo">
				<?php else : ?>
					<div class="store-info"><div class="store-name"><?php echo esc_html( $store_name ); ?></div></div>
				<?php endif; ?>
			</div>
			<div class="invoice-title">
				<h1><?php esc_html_e( 'INVOICE', 'easy-order-manager' ); ?></h1>
				<div class="invoice-number">#<?php echo esc_html( $order->get_order_number() ); ?></div>
			</div>
		</div>

		<div class="store-info">
			<div class="store-name"><?php echo esc_html( $store_name ); ?></div>
			<?php if ( $store_addr ) : ?><div><?php echo esc_html( $store_addr ); ?></div><?php endif; ?>
			<?php if ( $store_phone ) : ?><div><?php echo esc_html( $store_phone ); ?></div><?php endif; ?>
			<?php if ( $store_email ) : ?><div><?php echo esc_html( $store_email ); ?></div><?php endif; ?>
		</div>

		<div class="parties">
			<div class="party">
				<h3><?php esc_html_e( 'Bill To', 'easy-order-manager' ); ?></h3>
				<p>
					<strong><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></strong><br>
					<?php echo esc_html( $order->get_billing_phone() ); ?><br>
					<?php echo esc_html( $order->get_billing_email() ); ?><br>
					<?php echo nl2br( esc_html( $order->get_billing_address_1() ) ); ?>
					<?php if ( $order->get_billing_address_2() ) : ?>, <?php echo esc_html( $order->get_billing_address_2() ); ?><?php endif; ?>
				</p>
			</div>
			<div class="party" style="text-align:right">
				<h3><?php esc_html_e( 'Order Info', 'easy-order-manager' ); ?></h3>
				<p>
					<strong><?php esc_html_e( 'Date:', 'easy-order-manager' ); ?></strong> <?php echo esc_html( $order->get_date_created()->format( get_option( 'date_format' ) ) ); ?><br>
					<strong><?php esc_html_e( 'Status:', 'easy-order-manager' ); ?></strong> <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?><br>
					<strong><?php esc_html_e( 'Payment:', 'easy-order-manager' ); ?></strong> <?php echo esc_html( $order->get_payment_method_title() ); ?>
				</p>
			</div>
		</div>

		<table class="items">
			<thead>
				<tr>
					<th style="width:5%">#</th>
					<th style="width:45%"><?php esc_html_e( 'Product', 'easy-order-manager' ); ?></th>
					<th style="width:10%"><?php esc_html_e( 'Qty', 'easy-order-manager' ); ?></th>
					<th style="width:15%"><?php esc_html_e( 'Price', 'easy-order-manager' ); ?></th>
					<th style="width:15%"><?php esc_html_e( 'Total', 'easy-order-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php $i = 1; foreach ( $order->get_items() as $item ) : $product = $item->get_product(); ?>
				<tr>
					<td><?php echo (int) $i++; ?></td>
					<td>
						<strong><?php echo esc_html( $item->get_name() ); ?></strong>
						<?php if ( $product && $product->get_sku() ) : ?>
							<br><small><?php esc_html_e( 'SKU:', 'easy-order-manager' ); ?> <?php echo esc_html( $product->get_sku() ); ?></small>
						<?php endif; ?>
					</td>
					<td><?php echo (int) $item->get_quantity(); ?></td>
					<td><?php echo wp_kses_post( wc_price( $order->get_item_subtotal( $item ) ) ); ?></td>
					<td><?php echo wp_kses_post( wc_price( $item->get_total() ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="totals">
			<table>
				<tr><td><?php esc_html_e( 'Subtotal', 'easy-order-manager' ); ?></td><td style="text-align:right"><?php echo wp_kses_post( wc_price( $order->get_subtotal() ) ); ?></td></tr>
				<?php if ( $order->get_shipping_total() > 0 ) : ?>
					<tr><td><?php esc_html_e( 'Shipping', 'easy-order-manager' ); ?></td><td style="text-align:right"><?php echo wp_kses_post( wc_price( $order->get_shipping_total() ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( $order->get_discount_total() > 0 ) : ?>
					<tr><td><?php esc_html_e( 'Discount', 'easy-order-manager' ); ?></td><td style="text-align:right">-<?php echo wp_kses_post( wc_price( $order->get_discount_total() ) ); ?></td></tr>
				<?php endif; ?>
				<tr class="total-row">
					<td><strong><?php esc_html_e( 'Total', 'easy-order-manager' ); ?></strong></td>
					<td style="text-align:right" class="total-amount"><strong><?php echo wp_kses_post( wc_price( $order->get_total() ) ); ?></strong></td>
				</tr>
			</table>
		</div>

		<div class="barcode">
			<svg width="200" height="40">
				<rect x="0" y="0" width="2" height="30" fill="#333"/>
				<rect x="4" y="0" width="3" height="30" fill="#333"/>
				<rect x="9" y="0" width="1" height="30" fill="#333"/>
				<rect x="12" y="0" width="4" height="30" fill="#333"/>
				<rect x="18" y="0" width="2" height="30" fill="#333"/>
				<rect x="22" y="0" width="1" height="30" fill="#333"/>
				<rect x="25" y="0" width="3" height="30" fill="#333"/>
				<rect x="30" y="0" width="2" height="30" fill="#333"/>
				<rect x="34" y="0" width="4" height="30" fill="#333"/>
				<rect x="40" y="0" width="1" height="30" fill="#333"/>
				<rect x="43" y="0" width="2" height="30" fill="#333"/>
				<rect x="47" y="0" width="3" height="30" fill="#333"/>
				<rect x="52" y="0" width="1" height="30" fill="#333"/>
				<rect x="55" y="0" width="2" height="30" fill="#333"/>
				<rect x="59" y="0" width="4" height="30" fill="#333"/>
				<rect x="65" y="0" width="1" height="30" fill="#333"/>
				<rect x="68" y="0" width="3" height="30" fill="#333"/>
				<rect x="73" y="0" width="2" height="30" fill="#333"/>
				<rect x="77" y="0" width="1" height="30" fill="#333"/>
				<rect x="80" y="0" width="4" height="30" fill="#333"/>
				<rect x="86" y="0" width="2" height="30" fill="#333"/>
				<rect x="90" y="0" width="3" height="30" fill="#333"/>
				<rect x="95" y="0" width="1" height="30" fill="#333"/>
				<rect x="98" y="0" width="2" height="30" fill="#333"/>
				<rect x="102" y="0" width="4" height="30" fill="#333"/>
				<rect x="108" y="0" width="1" height="30" fill="#333"/>
				<rect x="111" y="0" width="3" height="30" fill="#333"/>
				<rect x="116" y="0" width="2" height="30" fill="#333"/>
				<rect x="120" y="0" width="1" height="30" fill="#333"/>
				<rect x="123" y="0" width="4" height="30" fill="#333"/>
				<rect x="129" y="0" width="2" height="30" fill="#333"/>
				<rect x="133" y="0" width="3" height="30" fill="#333"/>
				<rect x="138" y="0" width="1" height="30" fill="#333"/>
				<rect x="141" y="0" width="2" height="30" fill="#333"/>
				<rect x="145" y="0" width="4" height="30" fill="#333"/>
				<rect x="151" y="0" width="1" height="30" fill="#333"/>
				<rect x="154" y="0" width="3" height="30" fill="#333"/>
				<rect x="159" y="0" width="2" height="30" fill="#333"/>
				<rect x="163" y="0" width="1" height="30" fill="#333"/>
				<rect x="166" y="0" width="4" height="30" fill="#333"/>
				<rect x="172" y="0" width="2" height="30" fill="#333"/>
				<rect x="176" y="0" width="3" height="30" fill="#333"/>
				<rect x="181" y="0" width="1" height="30" fill="#333"/>
				<rect x="184" y="0" width="2" height="30" fill="#333"/>
				<rect x="188" y="0" width="3" height="30" fill="#333"/>
				<text x="100" y="38" text-anchor="middle" font-size="8" fill="#333"><?php echo esc_html( $order->get_order_number() ); ?></text>
			</svg>
		</div>

		<div class="invoice-footer"><?php echo esc_html( $footer_text ); ?></div>
		<button class="print-btn no-print" onclick="window.print()"><?php esc_html_e( '🖨 Print Invoice', 'easy-order-manager' ); ?></button>
	</div>
</body>
</html>
<?php
