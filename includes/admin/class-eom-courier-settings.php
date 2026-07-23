<?php
/**
 * EOM Courier Settings
 *
 * Admin settings page for configuring courier API credentials.
 * Supports all 7 Bangladesh courier integrations with individual
 * credential forms, test connection buttons, and sandbox toggles.
 * Steadfast supports multiple merchant accounts for warehouse-specific keys.
 *
 * @package EasyOrderManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EOM_Courier_Settings
 *
 * Renders and processes the courier settings forms under the EOM admin menu.
 */
class EOM_Courier_Settings {

	/**
	 * Registered courier definitions.
	 *
	 * @var array
	 */
	private $courier_defs = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ), 20 );
		add_action( 'admin_post_eom_save_courier_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'wp_ajax_eom_test_courier_connection', array( $this, 'ajax_test_connection' ) );

		$this->courier_defs = array(
			'steadfast' => array(
				'name'        => __( 'Steadfast Courier', 'easy-order-manager' ),
				'description' => __( 'API key + Secret key authentication. Covers nationwide Bangladesh.', 'easy-order-manager' ),
				'fields'      => array(
					'api_key'    => __( 'API Key', 'easy-order-manager' ),
					'api_secret' => __( 'Secret Key', 'easy-order-manager' ),
				),
				'doc_url'     => 'https://steadfast.com.bd/',
			),
			'pathao'    => array(
				'name'        => __( 'Pathao Courier', 'easy-order-manager' ),
				'description' => __( 'OAuth2 authentication with client credentials + username/password.', 'easy-order-manager' ),
				'fields'      => array(
					'client_id'     => __( 'Client ID', 'easy-order-manager' ),
					'client_secret' => __( 'Client Secret', 'easy-order-manager' ),
					'username'      => __( 'Username / Email', 'easy-order-manager' ),
					'password'      => __( 'Password', 'easy-order-manager' ),
				),
				'doc_url'     => 'https://pathao.com/courier/',
			),
			'redx'      => array(
				'name'        => __( 'RedX Courier', 'easy-order-manager' ),
				'description' => __( 'Token-based authentication (bearer token).', 'easy-order-manager' ),
				'fields'      => array(
					'api_key'    => __( 'API Key', 'easy-order-manager' ),
					'api_secret' => __( 'API Secret', 'easy-order-manager' ),
				),
				'doc_url'     => 'https://redx.com.bd/',
			),
			'carriebee' => array(
				'name'        => __( 'CarryBee Courier', 'easy-order-manager' ),
				'description' => __( 'API-based integration.', 'easy-order-manager' ),
				'fields'      => array(
					'api_key'    => __( 'API Key', 'easy-order-manager' ),
					'api_secret' => __( 'API Secret', 'easy-order-manager' ),
				),
				'doc_url'     => 'https://carriebee.com/',
			),
			'ecourier'  => array(
				'name'        => __( 'eCourier', 'easy-order-manager' ),
				'description' => __( 'API key + secret key + user ID authentication.', 'easy-order-manager' ),
				'fields'      => array(
					'api_key'    => __( 'API Key', 'easy-order-manager' ),
					'api_secret' => __( 'API Secret', 'easy-order-manager' ),
					'user_id'    => __( 'User ID', 'easy-order-manager' ),
				),
				'doc_url'     => 'https://ecourier.com.bd/',
			),
			'sundarban' => array(
				'name'        => __( 'Sundarban Courier', 'easy-order-manager' ),
				'description' => __( 'API-based integration.', 'easy-order-manager' ),
				'fields'      => array(
					'api_key'    => __( 'API Key', 'easy-order-manager' ),
					'api_secret' => __( 'API Secret', 'easy-order-manager' ),
				),
				'doc_url'     => 'https://sundarban.com/',
			),
			'paperfly'  => array(
				'name'        => __( 'Paperfly Courier', 'easy-order-manager' ),
				'description' => __( 'API-based integration.', 'easy-order-manager' ),
				'fields'      => array(
					'api_key'    => __( 'API Key', 'easy-order-manager' ),
					'api_secret' => __( 'API Secret', 'easy-order-manager' ),
				),
				'doc_url'     => 'https://paperfly.com.bd/',
			),
		);

		$this->courier_defs = apply_filters( 'eom_courier_settings_defs', $this->courier_defs );
	}

	/**
	 * Add the courier settings submenu page.
	 *
	 * @return void
	 */
	public function add_admin_page(): void {
		add_submenu_page(
			'eom-dashboard',
			__( 'Courier Settings', 'easy-order-manager' ),
			__( 'Courier Settings', 'easy-order-manager' ),
			'manage_options',
			'eom-courier-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the full courier settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'easy-order-manager' ) );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'steadfast';
		if ( ! isset( $this->courier_defs[ $active_tab ] ) ) {
			$active_tab = 'steadfast';
		}

		$saved = get_option( 'eom_courier_' . $active_tab, array() );
		$is_configured = ! empty( $saved['api_key'] ) || ! empty( $saved['client_id'] );

		$merchants = array();
		if ( 'steadfast' === $active_tab && class_exists( 'EOM_Courier_Steadfast' ) ) {
			$merchants = EOM_Courier_Steadfast::get_merchant_accounts();
		}
		?>
		<div class="wrap eom-courier-settings-wrap">
			<h1><?php esc_html_e( 'Courier Settings', 'easy-order-manager' ); ?></h1>
			<p><?php esc_html_e( 'Configure your courier service API credentials below. Each courier has its own settings form.', 'easy-order-manager' ); ?></p>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $this->courier_defs as $slug => $def ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $slug ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $def['name'] ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eom-courier-form">
				<input type="hidden" name="action" value="eom_save_courier_settings">
				<input type="hidden" name="courier_slug" value="<?php echo esc_attr( $active_tab ); ?>">
				<?php wp_nonce_field( 'eom_save_courier_' . $active_tab, '_eom_courier_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'easy-order-manager' ); ?></th>
							<td>
								<?php if ( $is_configured ) : ?>
									<span style="color:#10b981; font-weight:600;">&#10003; <?php esc_html_e( 'Configured', 'easy-order-manager' ); ?></span>
									<button type="button" class="button button-small eom-test-connection"
											data-courier="<?php echo esc_attr( $active_tab ); ?>"
											style="margin-left:10px;">
										<?php esc_html_e( 'Test Connection', 'easy-order-manager' ); ?>
									</button>
									<span class="eom-test-result" style="margin-left:10px;"></span>
								<?php else : ?>
									<span style="color:#ef4444;">&#10007; <?php esc_html_e( 'Not configured', 'easy-order-manager' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>

						<?php if ( ! empty( $this->courier_defs[ $active_tab ]['description'] ) ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'About', 'easy-order-manager' ); ?></th>
								<td>
									<p class="description"><?php echo esc_html( $this->courier_defs[ $active_tab ]['description'] ); ?></p>
									<?php if ( ! empty( $this->courier_defs[ $active_tab ]['doc_url'] ) ) : ?>
										<a href="<?php echo esc_url( $this->courier_defs[ $active_tab ]['doc_url'] ); ?>" target="_blank">
											<?php esc_html_e( 'Visit courier website &rarr;', 'easy-order-manager' ); ?>
										</a>
									<?php endif; ?>
									</td>
							</tr>
						<?php endif; ?>

						<?php foreach ( $this->courier_defs[ $active_tab ]['fields'] as $field_key => $field_label ) : ?>
							<?php
							$value = isset( $saved[ $field_key ] ) ? $saved[ $field_key ] : '';
							$is_secret = in_array( $field_key, array( 'api_secret', 'client_secret', 'password' ), true );
							?>
							<tr>
								<th scope="row">
									<label for="eom-<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $field_label ); ?></label>
								</th>
								<td>
									<input type="<?php echo $is_secret ? 'password' : 'text'; ?>"
										   name="eom_<?php echo esc_attr( $field_key ); ?>"
										   id="eom-<?php echo esc_attr( $field_key ); ?>"
										   value="<?php echo esc_attr( $value ); ?>"
										   class="regular-text"
										   autocomplete="off"
										   <?php echo $is_secret ? ' spellcheck="false"' : ''; ?>>
									<?php if ( $is_secret && ! empty( $value ) ) : ?>
										<button type="button" class="button button-small eom-toggle-secret" data-target="eom-<?php echo esc_attr( $field_key ); ?>">
											<?php esc_html_e( 'Show', 'easy-order-manager' ); ?>
										</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>

						<?php
						$sandbox = isset( $saved['sandbox_mode'] ) ? (bool) $saved['sandbox_mode'] : false;
						?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Sandbox Mode', 'easy-order-manager' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="eom_sandbox_mode" value="1" <?php checked( $sandbox ); ?>>
									<?php esc_html_e( 'Use sandbox/test environment', 'easy-order-manager' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Custom API URL', 'easy-order-manager' ); ?></th>
							<td>
								<input type="text" name="eom_api_url" id="eom-api-url"
									value="<?php echo esc_attr( $saved['api_url'] ?? '' ); ?>"
									class="regular-text code" placeholder="<?php esc_attr_e( 'Leave empty to use default', 'easy-order-manager' ); ?>">
								<p class="description">
									<?php esc_html_e( 'Override the default API base URL.', 'easy-order-manager' ); ?>
									<?php if ( 'steadfast' === $active_tab ) : ?>
										<strong><?php esc_html_e( 'Default: https://portal.packzy.com/api/v1/', 'easy-order-manager' ); ?></strong>
									<?php endif; ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php if ( 'steadfast' === $active_tab ) : ?>
				<hr>
				<div class="eom-courier-merchants-section" style="background:#faf5ff; padding:20px; border-radius:4px; margin-top:20px; max-width:800px;">
					<h3><?php esc_html_e( 'Merchant Accounts (Warehouses)', 'easy-order-manager' ); ?></h3>
					<p><?php esc_html_e( 'Add multiple Steadfast merchant accounts for different warehouse locations. You can choose which account to use when booking a courier from the order dashboard.', 'easy-order-manager' ); ?></p>

					<table class="wp-list-table widefat striped" id="eom-merchant-accounts-table" style="margin-bottom:15px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'ID', 'easy-order-manager' ); ?></th>
								<th><?php esc_html_e( 'Label', 'easy-order-manager' ); ?></th>
								<th><?php esc_html_e( 'API Key', 'easy-order-manager' ); ?></th>
								<th><?php esc_html_e( 'Secret Key', 'easy-order-manager' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'easy-order-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $merchants ) ) : ?>
								<tr class="eom-no-merchants-row">
									<td colspan="5" style="text-align:center; color:#999;"><?php esc_html_e( 'No merchant accounts yet. Add one below.', 'easy-order-manager' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $merchants as $mid => $m ) : ?>
									<tr>
										<td><code><?php echo esc_html( $mid ); ?></code></td>
										<td><?php echo esc_html( isset( $m['label'] ) ? $m['label'] : '' ); ?></td>
										<td><code><?php echo esc_html( substr( $m['api_key'] ?? '', 0, 8 ) . '...' ); ?></code></td>
										<td><code>****</code></td>
										<td>
											<button type="button" class="button button-small eom-test-merchant-connection" data-merchant-id="<?php echo esc_attr( $mid ); ?>">
												<?php esc_html_e( 'Test', 'easy-order-manager' ); ?>
											</button>
											<button type="button" class="button button-small eom-remove-merchant" data-merchant-id="<?php echo esc_attr( $mid ); ?>">
												<?php esc_html_e( 'Remove', 'easy-order-manager' ); ?>
											</button>
											<span class="eom-test-result" style="margin-left:5px;"></span>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

					<h4 style="margin-bottom:10px;"><?php esc_html_e( 'Add New Merchant Account', 'easy-order-manager' ); ?></h4>
					<table class="form-table" style="max-width:500px;">
						<tr>
							<th scope="row"><label for="eom-new-merchant-id"><?php esc_html_e( 'Account ID', 'easy-order-manager' ); ?></label></th>
							<td>
								<input type="text" id="eom-new-merchant-id" class="regular-text code" placeholder="e.g. wh1, dhaka, ctg" style="width:200px;">
								<p class="description"><?php esc_html_e( 'Unique ID (letters, numbers, hyphens). Used to identify this account during booking.', 'easy-order-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="eom-new-merchant-label"><?php esc_html_e( 'Label', 'easy-order-manager' ); ?></label></th>
							<td><input type="text" id="eom-new-merchant-label" class="regular-text" placeholder="e.g. Warehouse 1 - Dhaka"></td>
						</tr>
						<tr>
							<th scope="row"><label for="eom-new-merchant-api-key"><?php esc_html_e( 'API Key', 'easy-order-manager' ); ?></label></th>
							<td><input type="password" id="eom-new-merchant-api-key" class="regular-text"></td>
						</tr>
						<tr>
							<th scope="row"><label for="eom-new-merchant-api-secret"><?php esc_html_e( 'Secret Key', 'easy-order-manager' ); ?></label></th>
							<td><input type="password" id="eom-new-merchant-api-secret" class="regular-text"></td>
						</tr>
					</table>
					<button type="button" class="button" id="eom-add-merchant-btn"><?php esc_html_e( 'Add Merchant Account', 'easy-order-manager' ); ?></button>
					<div id="eom-merchant-ids-container"></div>
					<p class="description" style="margin-top:10px;">
						<?php esc_html_e( 'After adding accounts above, click "Save Settings" below to persist them.', 'easy-order-manager' ); ?>
					</p>
				</div>
				<?php endif; ?>

				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Save Settings', 'easy-order-manager' ); ?>
					</button>
				</p>
			</form>

			<script type="text/javascript">
			jQuery(function($) {
				$('.eom-toggle-secret').on('click', function() {
					var target = $('#' + $(this).data('target'));
					if (target.attr('type') === 'password') {
						target.attr('type', 'text');
						$(this).text('<?php echo esc_js( __( 'Hide', 'easy-order-manager' ) ); ?>');
					} else {
						target.attr('type', 'password');
						$(this).text('<?php echo esc_js( __( 'Show', 'easy-order-manager' ) ); ?>');
					}
				});

				$('.eom-test-connection').on('click', function() {
					var btn = $(this);
					var courier = btn.data('courier');
					var resultSpan = btn.siblings('.eom-test-result');

					btn.prop('disabled', true).text('<?php echo esc_js( __( 'Testing...', 'easy-order-manager' ) ); ?>');
					resultSpan.html('<span style="color:#999;"><?php echo esc_js( __( 'Testing connection...', 'easy-order-manager' ) ); ?></span>');

					$.post(ajaxurl, {
						action: 'eom_test_courier_connection',
						courier_slug: courier,
						_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'eom_test_connection' ) ); ?>'
					}, function(response) {
						btn.prop('disabled', false).text('<?php echo esc_js( __( 'Test Connection', 'easy-order-manager' ) ); ?>');
						if (response.success) {
							resultSpan.html('<span style="color:#10b981; font-weight:600;">&#10003; ' + (response.data.message || '<?php echo esc_js( __( 'Connection successful!', 'easy-order-manager' ) ); ?>') + '</span>');
							if (response.data.balance !== undefined) {
								resultSpan.append(' <span style="color:#555;">| <?php echo esc_js( __( 'Balance:', 'easy-order-manager' ) ); ?> ৳' + response.data.balance + '</span>');
							}
						} else {
							resultSpan.html('<span style="color:#ef4444; font-weight:600;">&#10007; ' + (response.data || '<?php echo esc_js( __( 'Connection failed.', 'easy-order-manager' ) ); ?>') + '</span>');
						}
					}).fail(function() {
						btn.prop('disabled', false).text('<?php echo esc_js( __( 'Test Connection', 'easy-order-manager' ) ); ?>');
						resultSpan.html('<span style="color:#ef4444; font-weight:600;">&#10007; <?php echo esc_js( __( 'Network error.', 'easy-order-manager' ) ); ?></span>');
					});
				});

				<?php if ( 'steadfast' === $active_tab ) : ?>
				$('#eom-add-merchant-btn').on('click', function() {
					var id = $('#eom-new-merchant-id').val().trim();
					var label = $('#eom-new-merchant-label').val().trim();
					var apiKey = $('#eom-new-merchant-api-key').val().trim();
					var apiSecret = $('#eom-new-merchant-api-secret').val().trim();
					if (!id || !apiKey || !apiSecret) { alert('<?php echo esc_js( __( 'ID, API Key, and Secret Key are required.', 'easy-order-manager' ) ); ?>'); return; }
					if (!/^[a-zA-Z0-9\-]+$/.test(id)) { alert('<?php echo esc_js( __( 'ID can only contain letters, numbers, and hyphens.', 'easy-order-manager' ) ); ?>'); return; }
					var container = $('#eom-merchant-ids-container');
					container.append(
						'<input type="hidden" name="eom_new_merchant_ids[]" value="' + $('<span>').text(id).html() + '">' +
						'<input type="hidden" name="eom_new_merchant_labels[]" value="' + $('<span>').text(label).html() + '">' +
						'<input type="hidden" name="eom_new_merchant_api_keys[]" value="' + $('<span>').text(apiKey).html() + '">' +
						'<input type="hidden" name="eom_new_merchant_secrets[]" value="' + $('<span>').text(apiSecret).html() + '">'
					);
					$('.eom-no-merchants-row').remove();
					var newRow = $('<tr><td><code>' + $('<span>').text(id).html() + '</code></td><td>' + $('<span>').text(label).html() + '</td><td><code>' + apiKey.substr(0, 8) + '...</code></td><td><code>****</code></td><td><button type="button" class="button button-small eom-remove-merchant" data-merchant-id="' + $('<span>').text(id).html() + '"><?php echo esc_js( __( 'Remove', 'easy-order-manager' ) ); ?></button></td></tr>');
					$('#eom-merchant-accounts-table tbody').append(newRow);
					$('html, body').animate({ scrollTop: newRow.offset().top - 100 }, 300);
					$('#eom-new-merchant-id, #eom-new-merchant-label, #eom-new-merchant-api-key, #eom-new-merchant-api-secret').val('');
					$('#eom-new-merchant-id').trigger('focus');
				});

				$(document).on('click', '.eom-remove-merchant', function() {
					if (!confirm('<?php echo esc_js( __( 'Remove this merchant account?', 'easy-order-manager' ) ); ?>')) return;
					var mid = $(this).data('merchant-id');
					var row = $(this).closest('tr');
					$('#eom-merchant-ids-container').append('<input type="hidden" name="eom_remove_merchants[]" value="' + $('<span>').text(mid).html() + '">');
					row.fadeOut(300, function() { $(this).remove(); });
				});

				$(document).on('click', '.eom-test-merchant-connection', function() {
					var btn = $(this);
					var mid = btn.data('merchant-id');
					var resultSpan = btn.siblings('.eom-test-result');

					btn.prop('disabled', true).text('...');
					resultSpan.html('<span style="color:#999;"><?php echo esc_js( __( 'Testing...', 'easy-order-manager' ) ); ?></span>');

					$.post(ajaxurl, {
						action: 'eom_test_courier_connection',
						courier_slug: 'steadfast',
						merchant_id: mid,
						_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'eom_test_connection' ) ); ?>'
					}, function(response) {
						btn.prop('disabled', false).text('<?php echo esc_js( __( 'Test', 'easy-order-manager' ) ); ?>');
						if (response.success) {
							resultSpan.html('<span style="color:#10b981; font-weight:600;">&#10003; ' + (response.data.message || '<?php echo esc_js( __( 'OK', 'easy-order-manager' ) ); ?>') + '</span>');
							if (response.data.balance !== undefined) {
								resultSpan.append(' <span style="color:#555;">| <?php echo esc_js( __( 'Balance:', 'easy-order-manager' ) ); ?> ৳' + response.data.balance + '</span>');
							}
						} else {
							resultSpan.html('<span style="color:#ef4444; font-weight:600;">&#10007; ' + (response.data || '<?php echo esc_js( __( 'Connection failed.', 'easy-order-manager' ) ); ?>') + '</span>');
						}
					}).fail(function() {
						btn.prop('disabled', false).text('<?php echo esc_js( __( 'Test', 'easy-order-manager' ) ); ?>');
						resultSpan.html('<span style="color:#ef4444; font-weight:600;">&#10007; <?php echo esc_js( __( 'Network error.', 'easy-order-manager' ) ); ?></span>');
					});
				});
				<?php endif; ?>
			});
			</script>
		</div>
		<?php
	}

	/**
	 * Handle saving courier settings.
	 *
	 * @return void
	 */
	public function handle_save_settings(): void {
		$courier_slug = isset( $_POST['courier_slug'] ) ? sanitize_key( $_POST['courier_slug'] ) : '';

		if ( empty( $courier_slug ) || ! isset( $this->courier_defs[ $courier_slug ] ) ) {
			wp_die( esc_html__( 'Invalid courier.', 'easy-order-manager' ) );
		}

		check_admin_referer( 'eom_save_courier_' . $courier_slug, '_eom_courier_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'easy-order-manager' ) );
		}

		// Save main credentials.
		$settings = array();
		$fields   = $this->courier_defs[ $courier_slug ]['fields'];

		foreach ( array_keys( $fields ) as $field_key ) {
			$post_key = 'eom_' . $field_key;
			if ( isset( $_POST[ $post_key ] ) ) {
				$settings[ $field_key ] = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
			}
		}

		$settings['sandbox_mode'] = isset( $_POST['eom_sandbox_mode'] ) && '1' === $_POST['eom_sandbox_mode'];

		$settings['api_url'] = isset( $_POST['eom_api_url'] ) ? esc_url_raw( wp_unslash( $_POST['eom_api_url'] ) ) : '';

		// Preserve existing password/secret fields if left blank.
		$existing = get_option( 'eom_courier_' . $courier_slug, array() );
		foreach ( array_keys( $fields ) as $field_key ) {
			if ( empty( $settings[ $field_key ] ) && isset( $existing[ $field_key ] ) && ! empty( $existing[ $field_key ] ) ) {
				$settings[ $field_key ] = $existing[ $field_key ];
			}
		}

		update_option( 'eom_courier_' . $courier_slug, $settings );

		// Handle Steadfast merchant accounts.
		if ( 'steadfast' === $courier_slug && class_exists( 'EOM_Courier_Steadfast' ) ) {
			$this->handle_merchant_accounts_save();
		}

		$redirect_url = add_query_arg(
			array(
				'page'    => 'eom-courier-settings',
				'tab'     => $courier_slug,
				'saved'   => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Process new/removed Steadfast merchant accounts from POST data.
	 *
	 * @return void
	 */
	private function handle_merchant_accounts_save(): void {
		$merchants = EOM_Courier_Steadfast::get_merchant_accounts();

		// Collect IDs marked for removal.
		$remove_ids = array();
		if ( isset( $_POST['eom_remove_merchants'] ) && is_array( $_POST['eom_remove_merchants'] ) ) {
			$remove_ids = array_map( 'sanitize_key', wp_unslash( $_POST['eom_remove_merchants'] ) );
		}

		// Remove marked merchants.
		foreach ( $remove_ids as $remove_id ) {
			if ( isset( $merchants[ $remove_id ] ) ) {
				unset( $merchants[ $remove_id ] );
			}
		}

		// Add new merchants.
		if ( isset( $_POST['eom_new_merchant_ids'] ) && is_array( $_POST['eom_new_merchant_ids'] ) ) {
			$new_ids      = array_map( 'sanitize_key', wp_unslash( $_POST['eom_new_merchant_ids'] ) );
			$new_labels   = isset( $_POST['eom_new_merchant_labels'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['eom_new_merchant_labels'] ) ) : array();
			$new_api_keys = isset( $_POST['eom_new_merchant_api_keys'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['eom_new_merchant_api_keys'] ) ) : array();
			$new_secrets  = isset( $_POST['eom_new_merchant_secrets'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['eom_new_merchant_secrets'] ) ) : array();

			foreach ( $new_ids as $i => $mid ) {
				if ( empty( $mid ) ) {
					continue;
				}
				// Skip if also marked for removal (add-then-remove before save).
				if ( in_array( $mid, $remove_ids, true ) ) {
					continue;
				}
				$api_key    = isset( $new_api_keys[ $i ] ) ? $new_api_keys[ $i ] : '';
				$api_secret = isset( $new_secrets[ $i ] ) ? $new_secrets[ $i ] : '';
				if ( empty( $api_key ) || empty( $api_secret ) ) {
					continue;
				}
				$merchants[ $mid ] = array(
					'id'         => $mid,
					'label'      => isset( $new_labels[ $i ] ) ? $new_labels[ $i ] : $mid,
					'api_key'    => $api_key,
					'api_secret' => $api_secret,
				);
			}
		}

		EOM_Courier_Steadfast::save_merchant_accounts( $merchants );
	}

	/**
	 * AJAX handler: test courier connection.
	 *
	 * @return void
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'eom_test_connection' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'easy-order-manager' ) );
		}

		$courier_slug = isset( $_POST['courier_slug'] ) ? sanitize_key( $_POST['courier_slug'] ) : '';

		if ( empty( $courier_slug ) ) {
			wp_send_json_error( __( 'No courier specified.', 'easy-order-manager' ) );
		}

		$manager = EOM_Courier_Manager::instance();
		$courier = $manager->get_courier( $courier_slug );

		if ( ! $courier ) {
			wp_send_json_error( __( 'Courier class not found.', 'easy-order-manager' ) );
		}

		if ( ! $courier->is_available() ) {
			wp_send_json_error( __( 'Credentials not configured. Please save settings first.', 'easy-order-manager' ) );
		}

		switch ( $courier_slug ) {
			case 'steadfast':
				$merchant_id = isset( $_POST['merchant_id'] ) ? sanitize_key( $_POST['merchant_id'] ) : '';
				if ( method_exists( $courier, 'check_balance' ) ) {
					if ( ! empty( $merchant_id ) ) {
						$result = $courier->check_balance( $merchant_id );
					} else {
						$result = $courier->check_balance();
					}
					if ( isset( $result['success'] ) && $result['success'] ) {
						wp_send_json_success( array(
							'message' => __( 'Connected successfully!', 'easy-order-manager' ),
							'balance' => isset( $result['balance'] ) ? $result['balance'] : 0,
						) );
					} else {
						$error = isset( $result['error'] ) ? $result['error'] : __( 'Unknown error.', 'easy-order-manager' );
						wp_send_json_error( $error );
					}
				}
				break;

			default:
				try {
					$areas = $courier->get_areas();
					if ( ! empty( $areas ) ) {
						wp_send_json_success( array(
							'message' => sprintf(
								__( 'Connected! %d delivery areas available.', 'easy-order-manager' ),
								count( $areas )
							),
						) );
					} else {
						wp_send_json_success( array(
							'message' => __( 'Connected but no areas returned.', 'easy-order-manager' ),
						) );
					}
				} catch ( \Exception $e ) {
					wp_send_json_error( $e->getMessage() );
				}
		}

		wp_send_json_error( __( 'Test method not available for this courier.', 'easy-order-manager' ) );
	}
}
