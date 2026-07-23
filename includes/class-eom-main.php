<?php
/**
 * EOM Main
 *
 * Central bootstrap class that initializes all submodules,
 * registers admin menus, enqueues assets, and defines hooks.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Main
 *
 * Singleton that wires together every component of the plugin.
 */
class EOM_Main {

	/**
	 * Singleton instance.
	 *
	 * @var EOM_Main|null
	 */
	private static $instance = null;

	/**
	 * Registered submodule references.
	 */
	private $dashboard;
	private $bulk_actions;
	private $csv_export;
	private $filters;
	private $inline_edit;
	private $invoices;
	private $order_list;
	private $order_tracking;
	private $inventory;
	private $profit_loss;
	private $delivery_charge_alert;
	private $return_minimizer;
	private $team_management;
	private $urgent_orders;
	private $courier_manager;

	/**
	 * Get singleton instance.
	 *
	 * @return EOM_Main
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Load all required files and instantiate submodules.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		$base = EOM_PATH . 'includes/';

		// Core.
		require_once $base . 'class-eom-order-list.php';

		// Admin.
		require_once $base . 'admin/class-eom-dashboard.php';
		require_once $base . 'admin/class-eom-bulk-actions.php';
		require_once $base . 'admin/class-eom-csv-export.php';
		require_once $base . 'admin/class-eom-filters.php';
		require_once $base . 'admin/class-eom-inline-edit.php';
		require_once $base . 'admin/class-eom-invoices.php';
		require_once $base . 'admin/class-eom-order-tracking.php';
		require_once $base . 'admin/class-eom-delivery-charge-alert.php';
	require_once $base . 'admin/class-eom-courier-settings.php';

		// Couriers.
		require_once $base . 'couriers/class-eom-courier-base.php';
		require_once $base . 'couriers/class-eom-courier-manager.php';
		require_once $base . 'couriers/class-eom-courier-steadfast.php';
		require_once $base . 'couriers/class-eom-courier-redx.php';
		require_once $base . 'couriers/class-eom-courier-pathao.php';
		require_once $base . 'couriers/class-eom-courier-sundarban.php';
		require_once $base . 'couriers/class-eom-courier-ecourier.php';
		require_once $base . 'couriers/class-eom-courier-paperfly.php';
		require_once $base . 'couriers/class-eom-courier-carriebee.php';

		// Analytics.
		require_once $base . 'analytics/class-eom-inventory.php';
		require_once $base . 'analytics/class-eom-profit-loss.php';

		// Automation.
		require_once $base . 'automation/class-eom-return-minimizer.php';

		// Team.
		require_once $base . 'team/class-eom-team-management.php';

		// Tracking.
		require_once $base . 'tracking/class-eom-urgent-orders.php';

			// Integrations.
			require_once $base . 'integrations/class-eom-google-sheets.php';
			require_once $base . 'admin/class-eom-google-sheets-settings.php';
		require_once $base . 'admin/class-eom-block-customer.php';
	}

	/**
	 * Initialize WordPress hooks.
	 *
	 * @return void
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'init_submodules' ), 5 );
		add_action( 'init', array( $this, 'register_order_statuses' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_filter( 'woocommerce_order_statuses', array( $this, 'add_custom_order_statuses' ) );
	}

	/**
	 * Instantiate all submodule classes so they register their hooks.
	 *
	 * @return void
	 */
	public function init_submodules() {
		// Core order list columns.
		$this->order_list = new EOM_Order_List();

		// Admin modules.
		$this->dashboard     = new EOM_Admin_Dashboard();
		$this->bulk_actions  = new EOM_Admin_Bulk_Actions();
		$this->csv_export    = new EOM_CSV_Export();
		$this->filters       = new EOM_Admin_Filters();
		$this->inline_edit   = new EOM_Admin_Inline_Edit();
		$this->invoices      = new EOM_Admin_Invoices();
		$this->order_tracking = new EOM_Order_Tracking();

		// Delivery charge alert (registers its own submenu via admin_menu).
		$this->delivery_charge_alert = new EOM_Delivery_Charge_Alert();

		// Analytics.
		$this->inventory   = new EOM_Inventory();
		$this->profit_loss = new EOM_Profit_Loss();

		// Automation.
		$this->return_minimizer = new EOM_Return_Minimizer();

		// Team management (registers custom roles and capabilities).
		$this->team_management = new EOM_Team_Management();

		// Urgent orders tracking (registers its own submenu via admin_menu).
		$this->urgent_orders = new EOM_Urgent_Orders();

		// Courier manager singleton (instantiated on first call).
		$this->courier_manager = EOM_Courier_Manager::instance();

		// Courier settings page.
		$this->courier_settings = new EOM_Courier_Settings();
		// Google Sheets integration.
		$this->google_sheets_settings = new EOM_Google_Sheets_Settings();
			// Blocked customers.
			$this->block_customer = new EOM_Block_Customer();

		// Steadfast import.
		require_once EOM_PATH . 'includes/admin/class-eom-steadfast-import.php';
		$this->steadfast_import = new EOM_Steadfast_Import();

	}

	/**
	 * Register the main admin menu page and submenu pages.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		// Main menu page.
		add_menu_page(
			__( 'Easy Order Manager', 'easy-order-manager' ),
			__( 'EOM Orders', 'easy-order-manager' ),
			'manage_woocommerce',
			'eom-dashboard',
			array( $this, 'render_dashboard_page' ),
			'dashicons-screenoptions',
			30
		);

		// Submenu: Dashboard (duplicate of main, but explicit).
		add_submenu_page(
			'eom-dashboard',
			__( 'Dashboard', 'easy-order-manager' ),
			__( 'Dashboard', 'easy-order-manager' ),
			'manage_woocommerce',
			'eom-dashboard',
			array( $this, 'render_dashboard_page' )
		);

		// Submenu: Inventory.
		add_submenu_page(
			'eom-dashboard',
			__( 'Inventory', 'easy-order-manager' ),
			__( 'Inventory', 'easy-order-manager' ),
			'manage_woocommerce',
			'eom-inventory',
			array( $this, 'render_inventory_page' )
		);

		// Submenu: Profit & Loss.
		add_submenu_page(
			'eom-dashboard',
			__( 'Profit & Loss', 'easy-order-manager' ),
			__( 'Profit & Loss', 'easy-order-manager' ),
			'manage_woocommerce',
			'eom-profit-loss',
			array( $this, 'render_profit_loss_page' )
		);

		// Submenu: Team Management.
		add_submenu_page(
			'eom-dashboard',
			__( 'Team Management', 'easy-order-manager' ),
			__( 'Team Management', 'easy-order-manager' ),
			'manage_woocommerce',
			'eom-team',
			array( $this, 'render_team_page' )
		);

		// Submenu: Activity Log.
		add_submenu_page(
			'eom-dashboard',
			__( 'Activity Log', 'easy-order-manager' ),
			__( 'Activity Log', 'easy-order-manager' ),
			'manage_woocommerce',
			'eom-activity-log',
			array( $this, 'render_activity_log_page' )
		);
	}

	/**
	 * Render the main dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard_page() {
		if ( class_exists( 'EOM_Admin_Dashboard' ) ) {
			$dashboard = new EOM_Admin_Dashboard();
			$dashboard->render_dashboard();
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Easy Order Manager', 'easy-order-manager' ) . '</h1>';
			echo '<p>' . esc_html__( 'Dashboard component not available.', 'easy-order-manager' ) . '</p></div>';
		}
	}

	/**
	 * Render the inventory admin page.
	 *
	 * @return void
	 */
	public function render_inventory_page() {
		if ( class_exists( 'EOM_Inventory' ) ) {
			$inventory = new EOM_Inventory();
			$inventory->render_inventory_page();
		}
	}

	/**
	 * Render the profit & loss admin page.
	 *
	 * @return void
	 */
	public function render_profit_loss_page() {
		if ( class_exists( 'EOM_Profit_Loss' ) ) {
			$profit_loss = new EOM_Profit_Loss();
			$profit_loss->render_profit_page();
		}
	}

	/**
	 * Render the team management admin page.
	 *
	 * @return void
	 */
	public function render_team_page() {
		if ( class_exists( 'EOM_Team_Management' ) ) {
			$team = new EOM_Team_Management();
			$team->render_team_page();
		}
	}

	/**
	 * Render the activity log admin page.
	 *
	 * @return void
	 */
	public function render_activity_log_page() {
		if ( class_exists( 'EOM_Team_Management' ) ) {
			$team = new EOM_Team_Management();
			$team->render_activity_log_page();
		}
	}

	/**
	 * Register custom order statuses with WooCommerce.
	 *
	 * @return void
	 */
	public function register_order_statuses() {
		register_post_status( 'wc-eom-awaiting-shipment', array(
			'label'                     => __( 'Awaiting Shipment', 'easy-order-manager' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop( 'Awaiting Shipment <span class="count">(%s)</span>', 'Awaiting Shipment <span class="count">(%s)</span>', 'easy-order-manager' ),
		) );

		register_post_status( 'wc-eom-return-requested', array(
			'label'                     => __( 'Return Requested', 'easy-order-manager' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop( 'Return Requested <span class="count">(%s)</span>', 'Return Requested <span class="count">(%s)</span>', 'easy-order-manager' ),
		) );
	}

	/**
	 * Add custom statuses to the WooCommerce order status list.
	 *
	 * @param array $statuses Existing statuses.
	 * @return array Modified statuses.
	 */
	public function add_custom_order_statuses( $statuses ) {
		$statuses['wc-eom-awaiting-shipment'] = __( 'Awaiting Shipment', 'easy-order-manager' );
		$statuses['wc-eom-return-requested']  = __( 'Return Requested', 'easy-order-manager' );
		return $statuses;
	}

	/**
	 * Enqueue admin CSS and JS assets.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on EOM pages.
		if ( false === strpos( $hook, 'eom-' ) && 'toplevel_page_eom-dashboard' !== $hook ) {
			return;
		}

		// CSS (supports both assets/ and includes/assets/ locations).
		$css_url = file_exists( EOM_PATH . 'assets/css/eom-admin.css' ) ? EOM_URL . 'assets/css/eom-admin.css' : EOM_URL . 'includes/assets/css/eom-admin.css';
		wp_enqueue_style(
			'eom-admin',
			$css_url,
			array( 'woocommerce_admin_styles' ),
			EOM_VERSION
		);

		// JS dependencies.
		$js_deps = array( 'jquery', 'jquery-ui-dialog', 'jquery-ui-datepicker' );

		// Enqueue DataTables if available on the dashboard.
		if ( 'eom-dashboard' === $hook || 'toplevel_page_eom-dashboard' === $hook ) {
			wp_enqueue_style( 'datatables', 'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css', array(), '1.13.6' );
			wp_enqueue_style( 'datatables-responsive', 'https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css', array(), '2.5.0' );
			wp_enqueue_script( 'datatables', 'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js', array( 'jquery' ), '1.13.6', true );
			wp_enqueue_script( 'datatables-responsive', 'https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js', array( 'datatables' ), '2.5.0', true );
			$js_deps[] = 'datatables';
		}

		// Enqueue Select2 if available.
		if ( function_exists( 'WC' ) && isset( WC()->version ) && version_compare( WC()->version, '3.2', '>=' ) ) {
			wp_enqueue_script( 'select2' );
			wp_enqueue_style( 'select2' );
		}

		$js_url = file_exists( EOM_PATH . 'assets/js/eom-admin.js' ) ? EOM_URL . 'assets/js/eom-admin.js' : EOM_URL . 'includes/assets/js/eom-admin.js';
		wp_enqueue_script(
			'eom-admin',
			$js_url,
			$js_deps,
			EOM_VERSION,
			true
		);

		wp_localize_script(
			'eom-admin',
			'eom_ajax',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonces'   => array(
					'eom_get_orders'           => wp_create_nonce( 'eom_get_orders' ),
					'eom_process_bulk_action'  => wp_create_nonce( 'eom_process_bulk_action' ),
					'eom_save_order_field'     => wp_create_nonce( 'eom_save_order_field' ),
					'eom_get_inline_editor'    => wp_create_nonce( 'eom_get_inline_editor' ),
					'eom_search_products'      => wp_create_nonce( 'eom_search_products' ),
					'eom_export_csv'           => wp_create_nonce( 'eom_export_csv' ),
					'eom_export_selected'      => wp_create_nonce( 'eom_export_selected' ),
					'eom_test_connection'     => wp_create_nonce( 'eom_test_connection' ),
					'eom_get_steadfast_status' => wp_create_nonce( 'eom_get_steadfast_status' ),
						'eom_dismiss_disc'        => wp_create_nonce( 'eom_dismiss_disc' ),
					'eom_urgent_alert'        => wp_create_nonce( 'eom_urgent_alert' ),
					'eom_dismiss_urgent'      => wp_create_nonce( 'eom_dismiss_urgent' ),
					'eom_save_product_cost'   => wp_create_nonce( 'eom_save_product_cost' ),
						'eom_import_steadfast_xlsx' => wp_create_nonce( 'eom_import_steadfast_xlsx' ),
				'eom_print_invoice'    => wp_create_nonce( 'eom_print_invoice' ),
				'eom_block_customer'   => wp_create_nonce( 'eom_block_customer' ),
				),
				'merchant_accounts' => class_exists( 'EOM_Courier_Steadfast' ) ? EOM_Courier_Steadfast::get_merchant_accounts() : array(),
					'order_statuses' => wc_get_order_statuses(),
					'staff_users'   => $this->get_staff_users_for_js(),
					'i18n'     => array(
					'selectAction'    => __( 'Please select a bulk action.', 'easy-order-manager' ),
					'selectOrders'    => __( 'Please select at least one order.', 'easy-order-manager' ),
					'errorProcessing'          => __( 'Error processing bulk action.', 'easy-order-manager' ),
					'selectDefaultMerchant'    => __( 'Select default merchant for all orders.', 'easy-order-manager' ),
						'confirmBlock'     => __( 'Block this customer from placing new orders?', 'easy-order-manager' ),
				),
			)
		);
	}

	/**
	 * Get staff users formatted for JavaScript localization.
	 *
	 * @return array
	 */
	private function get_staff_users_for_js() {
		$staff = get_users(
			array(
				'role__in' => array( 'eom_manager', 'eom_staff', 'administrator', 'shop_manager' ),
			)
		);

		$staff_users = array();
		foreach ( $staff as $user ) {
			$staff_users[] = array(
				'id'   => $user->ID,
				'name' => $user->display_name,
			);
		}

		return $staff_users;
	}

	/**
	 * Enqueue frontend assets (tracking page, etc.).
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets() {
		if ( is_account_page() || has_shortcode( get_post()->post_content ?? '', 'eom_track_order' ) ) {
			$frontend_css = file_exists( EOM_PATH . 'assets/css/eom-admin.css' ) ? EOM_URL . 'assets/css/eom-admin.css' : EOM_URL . 'includes/assets/css/eom-admin.css';
			wp_enqueue_style(
				'eom-frontend',
				$frontend_css,
				array(),
				EOM_VERSION
			);
		}
	}
}
