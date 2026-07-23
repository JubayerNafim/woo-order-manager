<?php
/**
 * Plugin Name:         Easy Order Manager
 * Plugin URI:          https://easyordermanager.com.bd/
 * Description:         🇧🇩 Ultimate WooCommerce order management plugin for Bangladesh. All-in-one dashboard, inline editing, bulk actions, courier integrations (Pathao, Steadfast, RedX, CarryBee, eCourier, Sundarban, Paperfly), fraud protection, invoicing, profit/loss, inventory, team management, and more.
 * Version:             1.0.1
 * Requires at least:   6.0
 * Requires PHP:        7.4
 * Author:              Easy Order Manager Team
 * Author URI:          https://easyordermanager.com.bd/
 * License:             GPL v2 or later
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:         easy-order-manager
 * Domain Path:         /languages
 * WC requires at least: 5.0
 * WC tested up to:     8.5
 *
 * @package             EasyOrderManager
 */

defined( 'ABSPATH' ) || exit;

define( 'EOM_VERSION', '1.0.1' );
define( 'EOM_FILE', __FILE__ );
define( 'EOM_PATH', plugin_dir_path( __FILE__ ) );
define( 'EOM_URL', plugin_dir_url( __FILE__ ) );
define( 'EOM_BASENAME', plugin_basename( __FILE__ ) );

require_once EOM_PATH . 'includes/class-eom-main.php';

function eom_activate() {
	require_once EOM_PATH . 'includes/class-eom-install.php';
	EOM_Install::activate();
}
register_activation_hook( __FILE__, 'eom_activate' );

function eom_deactivate() {
	require_once EOM_PATH . 'includes/class-eom-install.php';
	EOM_Install::deactivate();
}
register_deactivation_hook( __FILE__, 'eom_deactivate' );

function eom_init() {
	$GLOBALS['eom_main'] = EOM_Main::instance();
}
add_action( 'plugins_loaded', 'eom_init' );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);
