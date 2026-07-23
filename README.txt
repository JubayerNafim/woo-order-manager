=== Easy Order Manager ===
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

Easy Order Manager is a comprehensive WooCommerce order management plugin designed specifically for Bangladesh e-commerce businesses. It transforms the default WooCommerce order interface into a powerful, Google-Sheets-like dashboard with inline editing, bulk actions, advanced filtering, courier integrations, invoicing, profit tracking, inventory management, team collaboration, and more.

**🇧🇩 Built for Bangladesh — 7+ Courier Integrations**
Pathao, Steadfast, RedX, CarryBee, eCourier, Sundarban, Paperfly — all integrated with one-click bulk booking.

---

### 🏠 Core Dashboard & Time-Saving Tools

**✅ All-in-One Order Dashboard**
DataTable with server-side processing, status-colored badges, and action buttons (View, Edit, Quick Edit, Print). Fully implemented.

**✅ Inline Table Editing**
Modal editor for billing/shipping fields, order status, staff assignment. AJAX save with per-order activity logging.

**✅ Bulk Actions — Change Status / Delete Order / Export CSV**
Work correctly via AJAX. CSV export nonce parameter was fixed (was sending `_wpnonce` instead of `_ajax_nonce` — **fixed**).

**✅ Bulk Actions — Book Courier**
Courier selection modal with booking via courier API. Works for Steadfast; other couriers depend on API credentials.

**✅ Bulk Actions — Assign Staff**
Staff dropdown was previously empty (no users populated). Now fetches staff users from the server and displays them in the modal. **Fixed.**

**⚠️ Bulk Actions — Send SMS (Placeholder)**
Logs the action to activity log but does not send real SMS. Code contains a placeholder comment awaiting SMS gateway integration.

**✅ Advanced Filtering**
Filter by status, date range, product (Select2 search), category, courier, staff, payment method. Filter state persists in user meta.

**⚠️ Custom Order Statuses (Limited)**
Two statuses pre-registered: "Awaiting Shipment" and "Return Requested". No UI for creating additional statuses yet.

**✅ Block Customer (New)**
Dashboard "Block" button now works — stores blocked email/phone in plugin options, prevents checkout for blocked customers, and provides a Blocked Customers admin page to manage the list.

**⚠️ Google Sheets Integration**
Settings page and sync class structure exist. Requires Google Service Account. Full testing pending.

---

### 🚚 Courier & Logistics

**✅ Steadfast Courier**
Full integration: booking, real-time tracking with delivery status badges, balance check, multi-merchant accounts, cancellation. The booking response now captures actual `delivery_fee` and `cod_fee` from the API rather than hardcoding zero. **Fixed.**

**✅ XLSX Import for Steadfast**
Upload Steadfast export .xlsx to batch-update delivery status, tracking codes, charges, COD fees. ZipArchive + SimpleXML, no external library.

**✅ Bulk Courier Booking**
Iterates orders, formats data per courier, calls API, persists to `eom_courier_bookings` table + order meta.

**✅ Automatic Tracking Sync**
Tracking IDs, consignment IDs, courier names auto-saved to order meta.

**✅ Pathao, RedX, CarryBee, eCourier, Sundarban, Paperfly**
All 7 courier classes implement the full interface: `book_parcel()`, `track_parcel()`, `cancel_parcel()`, `get_areas()`, `get_charge()`, `get_tracking_url()`. Should be tested with live API credentials.

**✅ Delivery Charge Alert (Fixed)**
Admin page, dismissal, and Telegram alerts work. Two bugs fixed:
- Steadfast `book_parcel()` now captures `delivery_fee` from the API response instead of hardcoding zero.
- Cron check now looks for bookings where no charge was returned from the API (charge=0) and for bookings older than 3 days that may have slipped through.

**✅ Urgent Order Tracking**
Admin page, stall-threshold filter, dismiss via transients, Telegram alerts. Daily cron checks for bookings with no recent updates.

**✅ Return Minimizer (Fixed)**
The suggestion engine and order creation logic were correct, but `get_returned_parcels()` was querying for status `'returned'` — a status no courier API ever sets. **Fixed** to also detect:
- Courier booking statuses: `cancelled`, `return_to_merchant`, `hold`
- Steadfast delivery status in order meta
- WooCommerce order status `wc-eom-return-requested`

---

### 📊 Operations, Team & Analytics

**✅ Professional Invoices (Fixed)**
HTML invoice generation with store logo, SVG barcode, addresses, product table, totals. Bulk print and single download. PDF via DOMPDF/mPDF if available.
- **Fixed:** Dashboard "Print" button now opens the invoice in a new tab.
- **Fixed:** Bulk "Print Invoice" action now generates a working URL to the bulk print AJAX endpoint.
- Added `eom_bulk_print_invoices` AJAX handler for printing multiple orders at once.

**✅ CSV Export (via Bulk Action + Button)**
Both export paths work. Nonce parameter mismatch in the button export was **fixed**.

**✅ Profit/Loss Calculation**
Per-order profit breakdown: revenue, product cost, delivery, gateway fee, COD fee (1%), ad cost, margin %. Packaging loss tracking for returned orders. Admin page with cards + table + CSV export.

**✅ Inventory Management**
Stock tracking, low-stock detection, auto-deduction on completion, per-product cost & packaging cost (inline editable). Inventory value calculation. Weekly alerts via email + Telegram.

**✅ Team Management (Fixed)**
Roles (EOM Manager/Staff), team listing, activity log, staff order-view restriction all work.
- **Fixed:** The "Assign Order to Staff" form on the team page now has the missing `admin_post_eom_assign_staff` handler — previously only the AJAX handler was hooked.
- **Fixed:** Bulk "Assign Staff" modal dropdown now populates staff users from the server.

**✅ Activity Log**
Database-backed with user/date/action filters.

**✅ Customer Order Tracking (Fixed)**
Shortcode `[eom_track_order]` and My Account endpoint. Order lookup by ID + phone with verification. Progress tracker.
- **Fixed:** AJAX lookup nonce was checking a nonce key that was never generated. Changed to use the same nonce that the tracking form produces.

**✅ Telegram Notifications**
Bot token + chat ID config. Used by delivery charge alerts and low-stock alerts.

**✅ WooCommerce HPOS Compatible**
Declared via `FeaturesUtil::declare_compatibility`.

---

### 🔮 Planned for Future Updates

1. **🔜 Unlimited Custom Order Statuses UI** — Admin interface to create/edit/delete custom statuses.
2. **🔜 SMS Gateway Integration** — Real SMS sending from bulk actions + events.
3. **🔜 SMS Credit/Purchase System** — As mentioned in the FAQ.
4. **🔜 Built-in PDF Invoice** — Without requiring external DOMPDF/mPDF.
5. **🔜 Non-Steadfast Courier API Testing** — Real-world validation for the other 6 couriers.

---

== Installation ==

1. Upload the `easy-order-manager` folder to the `/wp-content/plugins/` directory
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
Pathao, Steadfast, RedX, CarryBee, eCourier, Sundarban, and Paperfly. All 7 have complete integration classes. More can be added via the `eom_register_couriers` filter.

= Is WooCommerce HPOS supported? =
Yes. Full compatibility declared.

= Can I assign team members with restricted permissions? =
Yes. EOM Manager and EOM Staff roles are registered. Staff see only their assigned orders. Both the team page form and bulk action dropdown now work correctly.

= Can customers track their own orders? =
Yes. Use `[eom_track_order]` shortcode or My Account "Track Orders" page. POST and AJAX both work.

= Is SMS sending functional? =
Not yet. The bulk SMS action logs activity but doesn't send. SMS gateway integration is planned.

= Does the Delivery Charge Alert work? =
Yes — two bugs were fixed. It now captures actual fees from the Stedfast API response and the cron check properly looks for missing or mismatched charges.

= Does the Return Minimizer work? =
Yes — the query was fixed to detect returned parcels by courier status, delivery meta, and WooCommerce return status instead of relying on a status that no courier API ever sets.

== Changelog ==

= 1.0.1 =
* **FIXED:** CSV export nonce parameter mismatch (`_wpnonce` → `_ajax_nonce`)
* **FIXED:** Team "Assign Staff" form missing `admin_post_eom_assign_staff` handler
* **FIXED:** Bulk "Assign Staff" modal showing empty dropdown (now populates staff users)
* **FIXED:** Customer tracking AJAX nonce not generated (now uses the form's existing nonce)
* **FIXED:** Steadfast booking response hardcoding `delivery_fee: 0` (now extracts from API)
* **FIXED:** Delivery charge alert cron comparing expected charge against itself (now checks for missing/actual charges)
* **FIXED:** Return minimizer querying for status no courier ever returns (now checks courier statuses, delivery meta, and WooCommerce return status)
* Codebase audit with per-feature status documentation

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.1 =
Bug fix release addressing 7 critical issues identified during code audit.

== Credits ==

Developed by Jubayer Nafim
Built with DataTables, WooCommerce, and lots of ☕.
