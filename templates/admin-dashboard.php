<?php
/**
 * Admin Dashboard Template
 *
 * Main dashboard page for Easy Order Manager.
 * Layout: title, filter bar, status summary, DataTable, bulk actions, modals.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the admin dashboard page.
 *
 * This template is loaded by the EOM admin page hook.
 * It relies on the EOM_Admin_Dashboard class.
 */
function eom_render_admin_dashboard() {
	$dashboard = new EOM_Admin_Dashboard();
	$dashboard->render_dashboard();
}

// Call the render function.
eom_render_admin_dashboard();
