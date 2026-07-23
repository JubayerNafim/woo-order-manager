<?php
/**
 * EOM Google Sheets Settings
 *
 * Admin settings page for Google Sheets sync integration.
 * Manages Service Account JSON upload, spreadsheet ID, and sync controls.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Google_Sheets_Settings
 *
 * Settings page UI and AJAX handlers for the Google Sheets sync.
 */
class EOM_Google_Sheets_Settings {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ), 25 );
		add_action( 'admin_post_eom_save_google_sheets', array( $this, 'handle_save_settings' ) );
		add_action( 'wp_ajax_eom_gsheets_test', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_eom_gsheets_sync', array( $this, 'ajax_sync_orders' ) );
		add_action( 'wp_ajax_eom_gsheets_sync_selected', array( $this, 'ajax_sync_selected' ) );

		// Register auto-sync hooks if enabled.
		$this->maybe_register_auto_sync();
	}

	/**
	 * Register auto-sync hooks if auto-sync is enabled.
	 *
	 * Hooks into WooCommerce order status transitions and
	 * courier booking events to push data to Google Sheets.
	 *
	 * @return void
	 */
	public function maybe_register_auto_sync(): void {
		$config = get_option( 'eom_google_sheets', array() );
		if ( empty( $config['auto_sync'] ) ) {
			return;
		}

		// Sync on order status change.
		add_action( 'woocommerce_order_status_changed', array( $this, 'auto_sync_on_status_change' ), 10, 4 );

		// Sync on new order.
		add_action( 'woocommerce_new_order', array( $this, 'auto_sync_on_new_order' ), 10, 1 );

		// Sync after courier booking.
		add_action( 'eom_after_courier_booking', array( $this, 'auto_sync_on_courier_booking' ), 10, 2 );
	}

	/**
	 * Auto-sync on order status change.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $old_from Previous status.
	 * @param string   $new_to   New status.
	 * @param \WC_Order $order   Order object.
	 * @return void
	 */
	public function auto_sync_on_status_change( int $order_id, string $old_from, string $new_to, \WC_Order $order ): void {
		$sheets = new EOM_Google_Sheets();
		$sheets->sync_order( $order_id );
	}

	/**
	 * Auto-sync on new order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function auto_sync_on_new_order( int $order_id ): void {
		$sheets = new EOM_Google_Sheets();
		$sheets->sync_order( $order_id );
	}

	/**
	 * Auto-sync after courier booking (provides live delivery/COD costs).
	 *
	 * @param int   $order_id Order ID.
	 * @param array $booking  Booking response from courier API.
	 * @return void
	 */
	public function auto_sync_on_courier_booking( int $order_id, array $booking ): void {
		$sheets = new EOM_Google_Sheets();
		$sheets->sync_order( $order_id );
	}

	/**
	 * Add admin submenu page.
	 *
	 * @return void
	 */
	public function add_admin_page(): void {
		add_submenu_page(
			'eom-dashboard',
			__( 'Google Sheets Sync', 'easy-order-manager' ),
			__( 'Google Sheets', 'easy-order-manager' ),
			'manage_options',
			'eom-google-sheets',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'easy-order-manager' ) );
		}

		$config = get_option( 'eom_google_sheets', array() );
		$is_configured = ! empty( $config['spreadsheet_id'] ) && ! empty( $config['service_account_json'] );
		$sheet_url     = '';
		if ( ! empty( $config['spreadsheet_id'] ) ) {
			$sheet_url = 'https://docs.google.com/spreadsheets/d/' . $config['spreadsheet_id'];
		}
		$sa_data = ! empty( $config['service_account_json'] ) ? json_decode( $config['service_account_json'], true ) : array();
		$sa_email = ! empty( $sa_data['client_email'] ) ? $sa_data['client_email'] : '';

		$saved = isset( $_GET['saved'] ) && '1' === $_GET['saved'];
		?>
		<div class="wrap eom-gsheets-wrap">
			<h1><?php esc_html_e( 'Google Sheets Sync', 'easy-order-manager' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved successfully.', 'easy-order-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="eom-gsheets-intro" style="background:#f0f6ff; padding:15px; border-radius:4px; margin-bottom:20px;">
				<h3><?php esc_html_e( 'How to set up Google Sheets Sync', 'easy-order-manager' ); ?></h3>
				<ol style="margin-left:20px;">
					<li>
						<?php esc_html_e( 'Go to', 'easy-order-manager' ); ?>
						<a href="https://console.cloud.google.com/apis/credentials" target="_blank"><?php esc_html_e( 'Google Cloud Console', 'easy-order-manager' ); ?></a>
						<?php esc_html_e( '→ Create a project (or select existing) → Enable "Google Sheets API"', 'easy-order-manager' ); ?>
					</li>
					<li>
						<?php esc_html_e( 'Go to "Credentials" → "Create Credentials" → "Service Account" → Give it a name → Create and continue', 'easy-order-manager' ); ?>
					</li>
					<li>
						<?php esc_html_e( 'After creation, click the service account email → "Keys" tab → "Add Key" → "JSON" → Download the JSON file', 'easy-order-manager' ); ?>
					</li>
					<li>
						<?php esc_html_e( 'Open the downloaded JSON file, copy its entire content, and paste it in the field below', 'easy-order-manager' ); ?>
					</li>
					<li>
						<?php esc_html_e( 'Create a new Google Sheet, click "Share" in the top-right, and add the Service Account email (', 'easy-order-manager' ); ?>
						<strong><?php echo esc_html( $sa_email ? $sa_email : 'your-sa@project.iam.gserviceaccount.com' ); ?></strong>
						<?php esc_html_e( ') as an Editor', 'easy-order-manager' ); ?>
					</li>
					<li>
						<?php esc_html_e( 'Copy the Sheet ID from the URL (the long string between /d/ and /edit) and paste it below', 'easy-order-manager' ); ?>
					</li>
					<li>
						<?php esc_html_e( 'Click "Test Connection" to verify, then "Sync All Orders" to start', 'easy-order-manager' ); ?>
					</li>
				</ol>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eom_save_google_sheets">
				<?php wp_nonce_field( 'eom_save_google_sheets', '_eom_gsheets_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'easy-order-manager' ); ?></th>
						<td>
							<?php if ( $is_configured ) : ?>
								<span style="color:#10b981; font-weight:600;">&#10003; <?php esc_html_e( 'Configured', 'easy-order-manager' ); ?></span>
								<?php if ( $sheet_url ) : ?>
									&mdash; <a href="<?php echo esc_url( $sheet_url ); ?>" target="_blank"><?php esc_html_e( 'Open Sheet &rarr;', 'easy-order-manager' ); ?></a>
								<?php endif; ?>
								<br>
								<?php if ( $sa_email ) : ?>
									<small><?php esc_html_e( 'Service Account:', 'easy-order-manager' ); ?> <code><?php echo esc_html( $sa_email ); ?></code></small>
								<?php endif; ?>
							<?php else : ?>
								<span style="color:#ef4444;">&#10007; <?php esc_html_e( 'Not configured', 'easy-order-manager' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="eom-gsheets-sa-json"><?php esc_html_e( 'Service Account JSON', 'easy-order-manager' ); ?></label>
						</th>
						<td>
							<textarea name="eom_gsheets_sa_json" id="eom-gsheets-sa-json" rows="8" class="large-text code" placeholder="<?php esc_attr_e( 'Paste the entire content of your Google Service Account JSON key file here...', 'easy-order-manager' ); ?>"><?php echo esc_textarea( $config['service_account_json'] ?? '' ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'The full JSON content from your Google Cloud Service Account key file.', 'easy-order-manager' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="eom-gsheets-spreadsheet"><?php esc_html_e( 'Spreadsheet ID', 'easy-order-manager' ); ?></label>
						</th>
						<td>
							<input type="text" name="eom_gsheets_spreadsheet_id" id="eom-gsheets-spreadsheet"
								   value="<?php echo esc_attr( $config['spreadsheet_id'] ?? '' ); ?>"
								   class="regular-text" placeholder="1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms">
							<p class="description">
								<?php esc_html_e( 'The spreadsheet ID from the URL: https://docs.google.com/spreadsheets/d/', 'easy-order-manager' ); ?>
								<strong><?php esc_html_e( 'SPREADSHEET_ID', 'easy-order-manager' ); ?></strong><?php esc_html_e( '/edit', 'easy-order-manager' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="eom-gsheets-sheet-name"><?php esc_html_e( 'Sheet Tab Name', 'easy-order-manager' ); ?></label>
						</th>
						<td>
							<input type="text" name="eom_gsheets_sheet_name" id="eom-gsheets-sheet-name"
								   value="<?php echo esc_attr( $config['sheet_name'] ?? 'Sheet1' ); ?>"
								   class="regular-text" placeholder="Sheet1">
							<p class="description">
								<?php esc_html_e( 'The name of the sheet/tab within your spreadsheet (default: Sheet1).', 'easy-order-manager' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-Sync', 'easy-order-manager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="eom_gsheets_auto_sync" value="1" <?php checked( ! empty( $config['auto_sync'] ) ); ?>>
								<?php esc_html_e( 'Automatically sync new orders when they are created or updated', 'easy-order-manager' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When enabled, each new order and courier booking will be synced to the sheet automatically.', 'easy-order-manager' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Save Settings', 'easy-order-manager' ); ?>
					</button>

					<button type="button" class="button" id="eom-gsheets-test" style="margin-left:10px;">
						<?php esc_html_e( 'Test Connection', 'easy-order-manager' ); ?>
					</button>
					<span id="eom-gsheets-test-result" style="margin-left:10px;"></span>
				</p>
			</form>

			<?php if ( $is_configured ) : ?>
				<hr style="margin:30px 0;">

				<h2><?php esc_html_e( 'Sync Orders', 'easy-order-manager' ); ?></h2>
				<p><?php esc_html_e( 'Manually sync orders to your Google Sheet. Initial sync may take a few minutes.', 'easy-order-manager' ); ?></p>

				<div class="eom-gsheets-sync-actions" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
					<button type="button" class="button button-primary" id="eom-gsheets-sync-all">
						<?php esc_html_e( 'Sync All Orders', 'easy-order-manager' ); ?>
					</button>

					<button type="button" class="button" id="eom-gsheets-sync-recent">
						<?php esc_html_e( 'Sync Last 30 Days', 'easy-order-manager' ); ?>
					</button>

					<span id="eom-gsheets-sync-status" style="margin-left:10px;"></span>
				</div>

				<div id="eom-gsheets-sync-progress" style="display:none; margin-top:15px; background:#f0f0f1; padding:15px; border-radius:4px;">
					<div style="margin-bottom:8px;">
						<span id="eom-gsheets-progress-label"><?php esc_html_e( 'Syncing...', 'easy-order-manager' ); ?></span>
						<span id="eom-gsheets-progress-count" style="float:right;">0 / 0</span>
					</div>
					<div style="background:#fff; border:1px solid #c3c4c7; height:20px; border-radius:10px; overflow:hidden;">
						<div id="eom-gsheets-progress-bar" style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;"></div>
					</div>
				</div>

				<div id="eom-gsheets-sync-result" style="display:none; margin-top:15px;"></div>
			<?php endif; ?>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Test connection.
			$('#eom-gsheets-test').on('click', function() {
				var btn = $(this);
				var result = $('#eom-gsheets-test-result');

				btn.prop('disabled', true).text('<?php echo esc_js( __( 'Testing...', 'easy-order-manager' ) ); ?>');
				result.html('<span style="color:#999;"><?php echo esc_js( __( 'Testing...', 'easy-order-manager' ) ); ?></span>');

				$.post(ajaxurl, {
					action: 'eom_gsheets_test',
					_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'eom_gsheets_test' ) ); ?>'
				}, function(response) {
					btn.prop('disabled', false).text('<?php echo esc_js( __( 'Test Connection', 'easy-order-manager' ) ); ?>');
					if (response.success) {
						result.html('<span style="color:#10b981; font-weight:600;">&#10003; ' + (response.data.message || '<?php echo esc_js( __( 'Connected!', 'easy-order-manager' ) ); ?>') + '</span>');
					} else {
						result.html('<span style="color:#ef4444; font-weight:600;">&#10007; ' + (response.data || '<?php echo esc_js( __( 'Connection failed.', 'easy-order-manager' ) ); ?>') + '</span>');
					}
				}).fail(function() {
					btn.prop('disabled', false).text('<?php echo esc_js( __( 'Test Connection', 'easy-order-manager' ) ); ?>');
					result.html('<span style="color:#ef4444; font-weight:600;">&#10007; <?php echo esc_js( __( 'Network error.', 'easy-order-manager' ) ); ?></span>');
				});
			});

			// Sync handlers.
			function doSync(type) {
				var btn = type === 'all' ? $('#eom-gsheets-sync-all') : $('#eom-gsheets-sync-recent');
				var status = $('#eom-gsheets-sync-status');
				var progress = $('#eom-gsheets-sync-progress');
				var bar = $('#eom-gsheets-progress-bar');
				var countLabel = $('#eom-gsheets-progress-count');
				var resultDiv = $('#eom-gsheets-sync-result');

				btn.prop('disabled', true);
				status.html('<span style="color:#999;"><?php echo esc_js( __( 'Starting sync...', 'easy-order-manager' ) ); ?></span>');
				progress.show();
				resultDiv.hide();
				bar.css('width', '0%');

				$.post(ajaxurl, {
					action: 'eom_gsheets_sync',
					sync_type: type,
					_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'eom_gsheets_sync' ) ); ?>'
				}, function(response) {
					btn.prop('disabled', false);
					progress.hide();
					if (response.success) {
						var d = response.data;
						resultDiv.html(
							'<div class="notice notice-success" style="margin:0;">' +
							'<p><strong><?php echo esc_js( __( 'Sync Complete!', 'easy-order-manager' ) ); ?></strong></p>' +
							'<p><?php echo esc_js( __( 'Total:', 'easy-order-manager' ) ); ?> ' + d.total + ' | ' +
							'<?php echo esc_js( __( 'Synced:', 'easy-order-manager' ) ); ?> ' + d.success + ' | ' +
							'<?php echo esc_js( __( 'Failed:', 'easy-order-manager' ) ); ?> ' + d.failed + '</p>' +
							'</div>'
						).show();
						status.html('');
					} else {
						resultDiv.html(
							'<div class="notice notice-error" style="margin:0;"><p>' + (response.data || '<?php echo esc_js( __( 'Sync failed.', 'easy-order-manager' ) ); ?>') + '</p></div>'
						).show();
						status.html('');
					}
				}).fail(function() {
					btn.prop('disabled', false);
					progress.hide();
					resultDiv.html(
						'<div class="notice notice-error" style="margin:0;"><p><?php echo esc_js( __( 'Network error during sync.', 'easy-order-manager' ) ); ?></p></div>'
					).show();
					status.html('');
				});
			}

			$('#eom-gsheets-sync-all').on('click', function() { doSync('all'); });
			$('#eom-gsheets-sync-recent').on('click', function() { doSync('recent'); });
		});
		</script>
		<?php
	}

	/**
	 * Handle saving Google Sheets settings.
	 *
	 * @return void
	 */
	public function handle_save_settings(): void {
		check_admin_referer( 'eom_save_google_sheets', '_eom_gsheets_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'easy-order-manager' ) );
		}

		$config = array(
			'service_account_json' => isset( $_POST['eom_gsheets_sa_json'] ) ? wp_unslash( $_POST['eom_gsheets_sa_json'] ) : '',
			'spreadsheet_id'       => isset( $_POST['eom_gsheets_spreadsheet_id'] ) ? sanitize_text_field( wp_unslash( $_POST['eom_gsheets_spreadsheet_id'] ) ) : '',
			'sheet_name'           => isset( $_POST['eom_gsheets_sheet_name'] ) ? sanitize_text_field( wp_unslash( $_POST['eom_gsheets_sheet_name'] ) ) : 'Sheet1',
			'auto_sync'            => isset( $_POST['eom_gsheets_auto_sync'] ) && '1' === $_POST['eom_gsheets_auto_sync'],
		);

		update_option( 'eom_google_sheets', $config );

		wp_safe_redirect( add_query_arg( array(
			'page'  => 'eom-google-sheets',
			'saved' => '1',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * AJAX handler: test Google Sheets connection.
	 *
	 * @return void
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'eom_gsheets_test' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$sheets = new EOM_Google_Sheets();

		if ( ! $sheets->is_configured() ) {
			wp_send_json_error( __( 'Please save your Service Account JSON and Spreadsheet ID first.', 'easy-order-manager' ) );
		}

		$token = $sheets->get_access_token();
		if ( ! $token ) {
			wp_send_json_error( __( 'Failed to authenticate. Check your Service Account JSON.', 'easy-order-manager' ) );
		}

		// Try to initialize the sheet (creates headers if empty).
		$init = $sheets->init_sheet();
		if ( $init ) {
			wp_send_json_success( array(
				'message' => __( 'Connected successfully! Sheet headers initialized.', 'easy-order-manager' ),
			) );
		} else {
			wp_send_json_error( __( 'Connected but could not initialize the sheet. Check your Spreadsheet ID and share permissions.', 'easy-order-manager' ) );
		}
	}

	/**
	 * AJAX handler: sync orders to Google Sheets.
	 *
	 * @return void
	 */
	public function ajax_sync_orders(): void {
		check_ajax_referer( 'eom_gsheets_sync' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$sync_type = isset( $_POST['sync_type'] ) ? sanitize_text_field( wp_unslash( $_POST['sync_type'] ) ) : 'all';
		$sheets    = new EOM_Google_Sheets();

		if ( ! $sheets->is_configured() ) {
			wp_send_json_error( __( 'Google Sheets is not configured.', 'easy-order-manager' ) );
		}

		if ( 'recent' === $sync_type ) {
			// Get orders from last 30 days.
			$order_ids = wc_get_orders( array(
				'limit'        => -1,
				'return'       => 'ids',
				'date_created' => '>' . gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
				'orderby'      => 'date',
				'order'        => 'DESC',
			) );
			$result = $sheets->sync_orders( $order_ids );
			$result['total'] = count( $order_ids );
		} else {
			// Sync all orders.
			$result = $sheets->sync_all_orders();
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: sync selected orders (called from dashboard).
	 *
	 * @return void
	 */
	public function ajax_sync_selected(): void {
		check_ajax_referer( 'eom_gsheets_sync' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$order_ids = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) $_POST['order_ids'] ) : array();

		if ( empty( $order_ids ) ) {
			wp_send_json_error( __( 'No orders selected.', 'easy-order-manager' ) );
		}

		$sheets = new EOM_Google_Sheets();
		if ( ! $sheets->is_configured() ) {
			wp_send_json_error( __( 'Google Sheets is not configured.', 'easy-order-manager' ) );
		}

		$result = $sheets->sync_orders( $order_ids );
		wp_send_json_success( $result );
	}
}
