<?php
/**
 * EOM Install
 *
 * Handles plugin activation and deactivation.
 * Creates all required database tables and sets default options.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Install
 *
 * Static utility class called on plugin activation and deactivation.
 */
class EOM_Install {

	/**
	 * Activate the plugin.
	 *
	 * Creates database tables, sets default options, and flushes rewrite rules.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		self::set_default_options();
		self::schedule_cron_events();

		// Register roles and capabilities.
		if ( class_exists( 'EOM_Team_Management' ) ) {
			$team = new EOM_Team_Management();
			$team->register_roles();
		}

		// Flush rewrite rules for endpoints.
		flush_rewrite_rules();
	}

	/**
	 * Deactivate the plugin.
	 *
	 * Clears scheduled cron events and flushes rewrite rules.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::clear_cron_events();
		flush_rewrite_rules();
	}

	/**
	 * Create all required database tables.
	 *
	 * Tables created:
	 *   - eom_courier_bookings
	 *   - eom_charge_discrepancies
	 *   - eom_activity_log
	 *   - eom_inventory_log
	 *
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 1. Courier bookings table.
		$table_bookings = $wpdb->prefix . 'eom_courier_bookings';
		$sql_bookings   = "CREATE TABLE IF NOT EXISTS {$table_bookings} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			order_id BIGINT UNSIGNED NOT NULL,
			courier_slug VARCHAR(50) NOT NULL,
			tracking_id VARCHAR(255) DEFAULT '',
			consignment_id VARCHAR(255) DEFAULT '',
			status VARCHAR(50) DEFAULT 'booked',
			charge DECIMAL(10,2) DEFAULT 0.00,
			response_data LONGTEXT,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_order_id (order_id),
			INDEX idx_courier_slug (courier_slug),
			INDEX idx_tracking_id (tracking_id),
			INDEX idx_status (status),
			INDEX idx_updated_at (updated_at)
		) {$charset_collate};";
		dbDelta( $sql_bookings );

		// 2. Charge discrepancies table.
		$table_discrepancies = $wpdb->prefix . 'eom_charge_discrepancies';
		$sql_discrepancies   = "CREATE TABLE IF NOT EXISTS {$table_discrepancies} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			order_id BIGINT UNSIGNED NOT NULL,
			courier VARCHAR(50) DEFAULT '',
			expected DECIMAL(10,2) DEFAULT 0.00,
			actual DECIMAL(10,2) DEFAULT 0.00,
			difference DECIMAL(10,2) DEFAULT 0.00,
			status VARCHAR(20) DEFAULT 'open',
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_order_id (order_id),
			INDEX idx_status (status)
		) {$charset_collate};";
		dbDelta( $sql_discrepancies );

		// 3. Activity log table.
		$table_activity = $wpdb->prefix . 'eom_activity_log';
		$sql_activity   = "CREATE TABLE IF NOT EXISTS {$table_activity} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT UNSIGNED NOT NULL,
			user_name VARCHAR(255) NOT NULL DEFAULT '',
			action VARCHAR(100) NOT NULL DEFAULT '',
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			details TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_user (user_id),
			INDEX idx_action (action),
			INDEX idx_order (order_id),
			INDEX idx_created (created_at)
		) {$charset_collate};";
		dbDelta( $sql_activity );

		// 4. Inventory log table.
		$table_inventory = $wpdb->prefix . 'eom_inventory_log';
		$sql_inventory   = "CREATE TABLE IF NOT EXISTS {$table_inventory} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			product_id BIGINT UNSIGNED NOT NULL,
			product_name VARCHAR(255) NOT NULL DEFAULT '',
			quantity INT NOT NULL DEFAULT 0,
			type VARCHAR(50) NOT NULL DEFAULT 'adjustment',
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			note TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_product (product_id),
			INDEX idx_type (type),
			INDEX idx_order (order_id),
			INDEX idx_created (created_at)
		) {$charset_collate};";
		dbDelta( $sql_inventory );
	}

	/**
	 * Set default plugin options.
	 *
	 * @return void
	 */
	private static function set_default_options() {
		$defaults = array(
			'eom_low_stock_threshold'       => 10,
			'eom_charge_discrepancy_threshold' => 20,
			'eom_urgent_days_threshold'     => 7,
			'eom_urgent_auto_alert'         => false,
			'eom_invoice_store_name'        => get_bloginfo( 'name' ),
			'eom_invoice_store_email'       => get_bloginfo( 'admin_email' ),
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}

	/**
	 * Schedule recurring cron events.
	 *
	 * @return void
	 */
	private static function schedule_cron_events() {
		if ( ! wp_next_scheduled( 'eom_daily_urgent_check' ) ) {
			wp_schedule_event( time(), 'daily', 'eom_daily_urgent_check' );
		}
		if ( ! wp_next_scheduled( 'eom_daily_charge_check' ) ) {
			wp_schedule_event( time(), 'daily', 'eom_daily_charge_check' );
		}
		if ( ! wp_next_scheduled( 'eom_weekly_inventory_check' ) ) {
			wp_schedule_event( time(), 'weekly', 'eom_weekly_inventory_check' );
		}
	}

	/**
	 * Clear all scheduled cron events.
	 *
	 * @return void
	 */
	private static function clear_cron_events() {
		$events = array(
			'eom_daily_urgent_check',
			'eom_daily_charge_check',
			'eom_weekly_inventory_check',
		);

		foreach ( $events as $event ) {
			$timestamp = wp_next_scheduled( $event );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $event );
			}
		}
	}
}
