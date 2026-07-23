# Woo-Order-Manager
Contributors: Jubayer_Nafim
Tags: woocommerce, order management, courier, bangladesh, pathao, steadfast, redx, invoice, inventory, team management
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The ultimate WooCommerce order management plugin for Bangladesh — manage, track, and automate orders 10x faster.

== Description ==

Woo Order Manager is a comprehensive WooCommerce order management plugin designed specifically for Bangladesh e-commerce businesses. It transforms the default WooCommerce order interface into a powerful, Google-Sheets-like dashboard with inline editing, bulk actions, advanced filtering, courier integrations, invoicing, profit tracking, inventory management, team collaboration, and more.

**🇧🇩 Built for Bangladesh — 7+ Courier Integrations**
Pathao, Steadfast, RedX, CarryBee, eCourier, Sundarban, Paperfly — all integrated with one-click bulk booking.

---

### 🏠 Core Dashboard & Time-Saving Tools

**✅ All-in-One Order Dashboard**
DataTable with server-side processing, status-colored badges, and action buttons (View, Edit, Quick Edit, Print).

**✅ Inline Table Editing**
Modal editor for billing/shipping fields, order status, and staff assignment with AJAX save and per-order activity logging.

**✅ Bulk Actions**
Perform quick operations across multiple orders including changing order status, deleting orders, exporting CSV files, booking couriers, and assigning staff members.

**✅ Advanced Filtering**
Filter by status, date range, product (Select2 search), category, courier, staff, and payment method. Filter state persists in user meta.

**✅ Custom Order Statuses**
Pre-registered statuses for "Awaiting Shipment" and "Return Requested" to streamline fulfillment workflows.

**✅ Block Customer**
Block troublesome customers directly from the dashboard to prevent future checkouts, and manage blocked emails/phone numbers from a dedicated admin page.

---

### 🚚 Courier & Logistics

**✅ Steadfast Courier Integration**
Full integration featuring one-click booking, real-time tracking with delivery status badges, balance checking, multi-merchant account support, and order cancellation.

**✅ XLSX Import for Steadfast**
Upload Steadfast export `.xlsx` files to batch-update delivery statuses, tracking codes, charges, and COD fees directly into your system.

**✅ Multi-Courier Integration**
Complete integration classes for 7 local couriers: Pathao, Steadfast, RedX, CarryBee, eCourier, Sundarban, and Paperfly. Supports bulk parcel booking, status tracking, charge estimation, area lookups, and parcel cancellation.

**✅ Automatic Tracking Sync & Alerts**
Tracking IDs, consignment IDs, and courier names automatically save to order meta. Includes built-in admin pages and Telegram alerts for delivery charge discrepancies and urgent/stalled order tracking.

**✅ Return Minimizer**
Smart risk analysis engine that identifies past return patterns by checking courier booking statuses, delivery meta, and custom return statuses to protect your margins.

---

### 📊 Operations, Team & Analytics

**✅ Professional Invoices**
HTML invoice generation with store logo, SVG barcodes, customer addresses, product details, and totals. Supports single downloads, quick print from the dashboard, and bulk printing via AJAX.

**✅ Profit/Loss Calculation**
Detailed per-order financial breakdown: revenue, product cost, delivery, gateway fees, COD fees, advertising costs, and net margin percentage. Includes packaging loss tracking for returned orders and CSV export.

**✅ Inventory Management**
Track stock levels, set low-stock thresholds, auto-deduct inventory on order completion, and manage product/packaging costs. Generates total inventory values and sends weekly alerts via email and Telegram.

**✅ Team Management & Activity Logs**
Dedicated roles (EOM Manager / EOM Staff) with staff-specific order view restrictions, team listing, order assignment tools, and a full audit log filtered by user, date, or action.

**✅ Customer Order Tracking**
Provide order lookup on your frontend via the `[eom_track_order]` shortcode or the WooCommerce My Account endpoint with secure phone verification and visual progress bars.

**✅ Telegram Notifications**
Connect your Telegram bot to receive instant alerts for low stock levels and delivery charge discrepancies.

**✅ WooCommerce HPOS Compatible**
Fully compatible with High-Performance Order Storage (HPOS).

---

### 🔮 Planned for Future Updates

1. **🔜 Custom Order Status Manager** — Visual UI to create, edit, and delete custom order statuses.
2. **🔜 Live SMS Gateway Integration** — Real SMS sending directly from bulk dashboard actions and order state events.
3. **🔜 Built-in PDF Engine** — Direct PDF invoice generation without requiring external mPDF/DOMPDF libraries.
4. **🔜 Google Sheets Auto-Sync** — Automated two-way background sync with Google Sheets.

---

== Installation ==

1. Upload the `woo-order-manager` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **EOM Orders** in the WordPress admin to access the dashboard
4. Configure courier API credentials under **EOM Orders → Courier Settings**
5. Set up your team members under **EOM Orders → Team Management**

== Requirements ==

* WordPress 6.0 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher
* MySQL 5.7+ or MariaDB 10.3+

== Frequently Asked Questions ==

= Which couriers are supported? =
Pathao, Steadfast, RedX, CarryBee, eCourier, Sundarban, and Paperfly. All 7 have complete integration classes. Additional couriers can be registered via the `eom_register_couriers` filter.

= Is WooCommerce HPOS supported? =
Yes. Full HPOS compatibility is declared.

= Can I assign team members with restricted permissions? =
Yes. EOM Manager and EOM Staff roles are available, allowing you to restrict staff to viewing only their assigned orders.

= Can customers track their own orders? =
Yes. You can display the tracking form using the `[eom_track_order]` shortcode or enable the My Account "Track Orders" endpoint.

= Does it support SMS notifications? =
Real SMS sending is currently in development and will be released in an upcoming update.

== Changelog ==

= 1.0.1 =
* Core performance enhancements, improved stability across courier API modules, and team assignment updates.

= 1.0.0 =
* Initial release

== Credits ==

Developed by Jubayer Nafim
Built with DataTables, WooCommerce, and lots of ☕.
