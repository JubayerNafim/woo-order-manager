<?php
/**
 * Plugin Name: Easy Order Manager
 * Plugin URI: https://example.com/easy-order-manager
 * Description: Comprehensive WooCommerce order management with advanced filtering, bulk actions, courier integrations (Steadfast, RedX, Pathao, Sundarban, eCourier, Paperfly, CarrieBee), inventory tracking, profit analytics, team management, invoice generation, and return minimization.
 * Version: 1.0.0
 * Author: Easy Order Manager Team
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: easy-order-manager
 * Domain Path: /languages
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 * Requires PHP: 7.4
 *
 * @package EasyOrderManager
 *
 * IMPORTANT: This file is included from the root-level easy-order-manager.php
 * which is the WordPress plugin entry point. Do not delete this file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constants are now defined in the root-level easy-order-manager.php
// These are fallback for direct inclusion
if ( ! defined( 'EOM_VERSION' ) ) {
	define( 'EOM_VERSION', '1.0.1' );
}
if ( ! defined( 'EOM_PATH' ) ) {
	define( 'EOM_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'EOM_URL' ) ) {
	define( 'EOM_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'EOM_BASENAME' ) ) {
	define( 'EOM_BASENAME', plugin_basename( __FILE__ ) );
}

require_once EOM_PATH . 'includes/class-eom-main.php';

// Activation, deactivation, and init hooks are registered in the
// root-level easy-order-manager.php which loads this file.
