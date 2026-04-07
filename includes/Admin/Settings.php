<?php
/**
 * Admin settings page.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings class.
 *
 * Handles the plugin settings page in WordPress admin.
 */
final class Settings {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'bricks-mcp';

	/**
	 * Settings option name.
	 *
	 * @var string
	 */
	private const OPTION_NAME = 'bricks_mcp_settings';

	/**
	 * Settings option group.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'bricks_mcp_settings_group';

	/**
	 * Initialize admin settings.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ], 99 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'wp_ajax_bricks_mcp_run_diagnostics', [ $this, 'ajax_run_diagnostics' ] );
		add_action( 'wp_ajax_bricks_mcp_generate_app_password', [ $this, 'ajax_generate_app_password' ] );
		add_action( 'wp_ajax_bricks_mcp_delete_note', [ $this, 'ajax_delete_note' ] );
		add_action( 'wp_ajax_bricks_mcp_add_note', [ $this, 'ajax_add_note' ] );
	}

	/**
	 * Add settings page to admin menu.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		add_submenu_page(
			'bricks',
			__( 'MCP Settings', 'bricks-mcp' ),
			__( 'MCP', 'bricks-mcp' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => $this->get_defaults(),
			]
		);

		// General settings section.
		add_settings_section(
			'bricks_mcp_general',
			__( 'General Settings', 'bricks-mcp' ),
			[ $this, 'render_general_section' ],
			self::PAGE_SLUG
		);

		// Enable/disable field.
		add_settings_field(
			'enabled',
			__( 'Enable MCP Server', 'bricks-mcp' ),
			[ $this, 'render_enabled_field' ],
			self::PAGE_SLUG,
			'bricks_mcp_general'
		);

		// Require authentication field.
		add_settings_field(
			'require_auth',
			__( 'Require Authentication', 'bricks-mcp' ),
			[ $this, 'render_require_auth_field' ],
			self::PAGE_SLUG,
			'bricks_mcp_general'
		);

		// Custom base URL field.
		add_settings_field(
			'custom_base_url',
			__( 'Custom Base URL', 'bricks-mcp' ),
			[ $this, 'render_custom_base_url_field' ],
			self::PAGE_SLUG,
			'bricks_mcp_general'
		);

		// Rate limit field.
		add_settings_field(
			'rate_limit_rpm',
			__( 'Rate Limit (requests/minute)', 'bricks-mcp' ),
			[ $this, 'render_rate_limit_rpm_field' ],
			self::PAGE_SLUG,
			'bricks_mcp_general'
		);

		// Dangerous actions field.
		add_settings_field(
			'dangerous_actions',
			__( 'Dangerous Actions', 'bricks-mcp' ),
			[ $this, 'render_dangerous_actions_field' ],
			self::PAGE_SLUG,
			'bricks_mcp_general'
		);

		// Protected pages field.
		add_settings_field(
			'protected_pages',
			__( 'Protected Pages', 'bricks-mcp' ),
			[ $this, 'render_protected_pages_field' ],
			self::PAGE_SLUG,
			'bricks_mcp_general'
		);
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed> Default settings.
	 */
	private function get_defaults(): array {
		return [
			'enabled'           => true,
			'require_auth'      => true,
			'custom_base_url'   => '',
			'dangerous_actions' => false,
			'rate_limit_rpm'    => 120,
			'protected_pages'   => '',
		];
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> Sanitized settings.
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = [];

		$sanitized['enabled']         = ! empty( $input['enabled'] );
		$sanitized['require_auth']    = ! empty( $input['require_auth'] );
		$sanitized['custom_base_url'] = isset( $input['custom_base_url'] )
			? esc_url_raw( trim( $input['custom_base_url'] ) )
			: '';

		$sanitized['dangerous_actions'] = ! empty( $input['dangerous_actions'] );
		$sanitized['rate_limit_rpm']    = max( 10, min( 1000, (int) ( $input['rate_limit_rpm'] ?? 120 ) ) );
		$sanitized['protected_pages']   = isset( $input['protected_pages'] )
			? sanitize_text_field( $input['protected_pages'] )
			: '';

		return $sanitized;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'bricks-mcp' ) );
		}

		// Display activation notice if issues were detected on plugin activation.
		$activation_results = get_transient( 'bricks_mcp_activation_checks' );
		if ( false !== $activation_results && is_array( $activation_results ) ) {
			delete_transient( 'bricks_mcp_activation_checks' );
			$has_issues = false;
			foreach ( $activation_results as $check ) {
				if ( 'fail' === ( $check['status'] ?? '' ) ) {
					$has_issues = true;
					break;
				}
			}
			if ( $has_issues ) {
				echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'Bricks MCP: Some configuration issues were detected during activation.', 'bricks-mcp' ) . '</strong> ';
				echo esc_html__( 'Click "Run Diagnostics" in the Diagnostics tab for details and fix instructions.', 'bricks-mcp' ) . '</p></div>';
			}
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'connection';

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors(); ?>

			<nav class="nav-tab-wrapper">
				<a href="?page=bricks-mcp&tab=connection" class="nav-tab <?php echo 'connection' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Connection', 'bricks-mcp' ); ?>
				</a>
				<a href="?page=bricks-mcp&tab=settings" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'bricks-mcp' ); ?>
				</a>
				<a href="?page=bricks-mcp&tab=notes" class="nav-tab <?php echo 'notes' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'AI Notes', 'bricks-mcp' ); ?>
				</a>
				<a href="?page=bricks-mcp&tab=diagnostics" class="nav-tab <?php echo 'diagnostics' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Diagnostics', 'bricks-mcp' ); ?>
				</a>
			</nav>

			<div class="bricks-mcp-tab-content" style="margin-top: 20px;">
			<?php
			switch ( $active_tab ) {
				case 'connection':
					$this->render_tab_connection();
					break;
				case 'settings':
					$this->render_tab_settings();
					break;
				case 'notes':
					$this->render_tab_notes();
					break;
				case 'diagnostics':
					$this->render_tab_diagnostics();
					break;
			}
			?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Connection tab content.
	 *
	 * Shows MCP Server Endpoints info box and MCP configuration snippets.
	 *
	 * @return void
	 */
	private function render_tab_connection(): void {
		?>
		<div class="bricks-mcp-info">
			<h3><?php esc_html_e( 'MCP Server Endpoints', 'bricks-mcp' ); ?></h3>
			<p>
				<strong><?php esc_html_e( 'MCP Endpoint:', 'bricks-mcp' ); ?></strong>
				<code><?php echo esc_html( rest_url( 'bricks-mcp/v1/mcp' ) ); ?></code>
			</p>
			<p class="description">
				<?php esc_html_e( 'This single endpoint handles all MCP protocol communication via JSON-RPC 2.0.', 'bricks-mcp' ); ?>
			</p>
		</div>
		<?php
		$this->render_mcp_config();
	}

	/**
	 * Render Settings tab content.
	 *
	 * Shows the settings form with enable, auth, URL, rate limit,
	 * dangerous actions, and protected pages fields.
	 *
	 * @return void
	 */
	private function render_tab_settings(): void {
		?>
		<form action="options.php" method="post">
			<?php
			settings_fields( self::OPTION_GROUP );
			do_settings_sections( self::PAGE_SLUG );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * Render AI Notes tab content.
	 *
	 * Shows the notes table with add/delete functionality.
	 *
	 * @return void
	 */
	private function render_tab_notes(): void {
		$notes       = get_option( 'bricks_mcp_notes', [] );
		$notes       = is_array( $notes ) ? $notes : [];
		$notes_nonce = wp_create_nonce( 'bricks_mcp_notes' );
		?>
		<h2><?php esc_html_e( 'AI Notes', 'bricks-mcp' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Persistent corrections and preferences stored by AI assistants. These are automatically included in the gotchas section of the builder guide.', 'bricks-mcp' ); ?></p>

		<div id="bricks-mcp-notes-add" style="margin: 15px 0;">
			<input type="text" id="bricks-mcp-note-text" class="regular-text" placeholder="<?php esc_attr_e( 'Add a new note...', 'bricks-mcp' ); ?>" style="width: 60%;">
			<button type="button" class="button button-secondary" id="bricks-mcp-add-note-btn"><?php esc_html_e( 'Add Note', 'bricks-mcp' ); ?></button>
		</div>

		<table class="widefat striped" id="bricks-mcp-notes-table">
			<thead><tr>
				<th><?php esc_html_e( 'Note', 'bricks-mcp' ); ?></th>
				<th style="width:160px"><?php esc_html_e( 'Created', 'bricks-mcp' ); ?></th>
				<th style="width:80px"><?php esc_html_e( 'Actions', 'bricks-mcp' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $notes ) ) : ?>
				<tr class="bricks-mcp-no-notes"><td colspan="3"><?php esc_html_e( 'No notes yet. AI assistants can add notes, or you can add one above.', 'bricks-mcp' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $notes as $note ) : ?>
				<tr data-note-id="<?php echo esc_attr( $note['id'] ?? '' ); ?>">
					<td><?php echo esc_html( $note['text'] ?? '' ); ?></td>
					<td><?php echo esc_html( $note['created_at'] ?? '' ); ?></td>
					<td><button type="button" class="button button-small bricks-mcp-delete-note" data-id="<?php echo esc_attr( $note['id'] ?? '' ); ?>"><?php esc_html_e( 'Delete', 'bricks-mcp' ); ?></button></td>
				</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<script>
		(function() {
			var nonce = <?php echo wp_json_encode( $notes_nonce ); ?>;

			document.querySelectorAll('.bricks-mcp-delete-note').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var noteId = this.getAttribute('data-id');
					var row = this.closest('tr');
					if (!confirm('Delete this note?')) return;
					btn.disabled = true;
					btn.textContent = '...';
					fetch(ajaxurl, {
						method: 'POST',
						headers: {'Content-Type': 'application/x-www-form-urlencoded'},
						body: 'action=bricks_mcp_delete_note&note_id=' + encodeURIComponent(noteId) + '&_wpnonce=' + encodeURIComponent(nonce)
					}).then(function(r) { return r.json(); }).then(function(data) {
						if (data.success) row.remove();
						else { btn.disabled = false; btn.textContent = 'Delete'; alert(data.data || 'Error'); }
					});
				});
			});

			document.getElementById('bricks-mcp-add-note-btn').addEventListener('click', function() {
				var input = document.getElementById('bricks-mcp-note-text');
				var text = input.value.trim();
				if (!text) return;
				this.disabled = true;
				var btn = this;
				fetch(ajaxurl, {
					method: 'POST',
					headers: {'Content-Type': 'application/x-www-form-urlencoded'},
					body: 'action=bricks_mcp_add_note&text=' + encodeURIComponent(text) + '&_wpnonce=' + encodeURIComponent(nonce)
				}).then(function(r) { return r.json(); }).then(function(data) {
					btn.disabled = false;
					if (data.success) {
						var note = data.data;
						var tbody = document.querySelector('#bricks-mcp-notes-table tbody');
						var noNotes = tbody.querySelector('.bricks-mcp-no-notes');
						if (noNotes) noNotes.remove();
						var tr = document.createElement('tr');
						tr.setAttribute('data-note-id', note.id);
						tr.innerHTML = '<td>' + note.text.replace(/</g, '&lt;') + '</td><td>' + note.created_at + '</td><td><button type="button" class="button button-small bricks-mcp-delete-note" data-id="' + note.id + '">Delete</button></td>';
						tbody.appendChild(tr);
						tr.querySelector('.bricks-mcp-delete-note').addEventListener('click', function() {
							var noteId = this.getAttribute('data-id');
							var row = this.closest('tr');
							if (!confirm('Delete this note?')) return;
							this.disabled = true;
							this.textContent = '...';
							fetch(ajaxurl, {
								method: 'POST',
								headers: {'Content-Type': 'application/x-www-form-urlencoded'},
								body: 'action=bricks_mcp_delete_note&note_id=' + encodeURIComponent(noteId) + '&_wpnonce=' + encodeURIComponent(nonce)
							}).then(function(r) { return r.json(); }).then(function(d) {
								if (d.success) row.remove();
							});
						});
						input.value = '';
					} else { alert(data.data || 'Error'); }
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render Diagnostics tab content.
	 *
	 * Shows version card and diagnostic panel.
	 *
	 * @return void
	 */
	private function render_tab_diagnostics(): void {
		$this->render_version_card();
		$this->render_diagnostic_panel();
	}

	/**
	 * Render general section description.
	 *
	 * @return void
	 */
	public function render_general_section(): void {
		echo '<p>' . esc_html__( 'Configure general MCP server settings.', 'bricks-mcp' ) . '</p>';
	}

	/**
	 * Render enabled field.
	 *
	 * @return void
	 */
	public function render_enabled_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		?>
		<label for="bricks-mcp-enabled">
			<input type="checkbox" id="bricks-mcp-enabled" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
			<?php esc_html_e( 'Enable the MCP server endpoints', 'bricks-mcp' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When disabled, all MCP endpoints will return a 503 Service Unavailable response.', 'bricks-mcp' ); ?>
		</p>
		<?php
	}

	/**
	 * Render require authentication field.
	 *
	 * @return void
	 */
	public function render_require_auth_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		?>
		<label for="bricks-mcp-require-auth">
			<input type="checkbox" id="bricks-mcp-require-auth" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[require_auth]" value="1" <?php checked( ! empty( $settings['require_auth'] ) ); ?>>
			<?php esc_html_e( 'Require user authentication for MCP endpoints', 'bricks-mcp' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, only authenticated users with manage_options capability can access the MCP server.', 'bricks-mcp' ); ?>
		</p>
		<?php
	}

	/**
	 * Render custom base URL field.
	 *
	 * @return void
	 */
	public function render_custom_base_url_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value    = $settings['custom_base_url'] ?? '';
		?>
		<input type="url" id="bricks-mcp-custom-base-url" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[custom_base_url]"
			value="<?php echo esc_attr( $value ); ?>" class="regular-text"
			placeholder="<?php echo esc_attr( get_site_url() ); ?>">
		<p class="description">
			<?php esc_html_e( 'Override the site URL used in MCP config snippets. Useful for reverse proxies or custom domains. Leave empty to use the default site URL.', 'bricks-mcp' ); ?>
		</p>
		<?php
	}

	/**
	 * Render dangerous actions field.
	 *
	 * @return void
	 */
	public function render_dangerous_actions_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		?>
		<label for="bricks-mcp-dangerous-actions">
			<input type="checkbox" id="bricks-mcp-dangerous-actions" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[dangerous_actions]" value="1" <?php checked( ! empty( $settings['dangerous_actions'] ) ); ?>>
			<?php esc_html_e( 'Enable dangerous actions mode', 'bricks-mcp' ); ?>
		</label>
		<div class="bricks-mcp-danger-notice">
			<strong><?php esc_html_e( 'Warning: This enables unrestricted write access', 'bricks-mcp' ); ?></strong>
			<p>
				<?php esc_html_e( 'When enabled, AI tools can: write to global Bricks settings, execute custom JavaScript on pages, and modify code execution settings. Only enable this on development sites or when running trusted AI agent teams. API keys and secrets remain masked regardless of this setting.', 'bricks-mcp' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render rate limit RPM field.
	 *
	 * @return void
	 */
	public function render_rate_limit_rpm_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value    = (int) ( $settings['rate_limit_rpm'] ?? 120 );
		?>
		<input type="number" id="bricks-mcp-rate-limit-rpm" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[rate_limit_rpm]"
			value="<?php echo esc_attr( (string) $value ); ?>" min="10" max="1000" step="10" class="small-text">
		<span><?php esc_html_e( 'requests per minute per user', 'bricks-mcp' ); ?></span>
		<p class="description">
			<?php esc_html_e( 'Maximum number of MCP requests allowed per authenticated user per minute. Default: 120. Increase to 300 for intensive AI building sessions. Applies only when authentication is required.', 'bricks-mcp' ); ?>
		</p>
		<?php
	}

	/**
	 * Render protected pages field.
	 *
	 * @return void
	 */
	public function render_protected_pages_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value    = $settings['protected_pages'] ?? '';
		?>
		<input type="text" id="bricks-mcp-protected-pages" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[protected_pages]"
			value="<?php echo esc_attr( $value ); ?>" class="regular-text"
			placeholder="<?php esc_attr_e( 'e.g. 2, 15, 42', 'bricks-mcp' ); ?>">
		<p class="description">
			<?php esc_html_e( 'Comma-separated list of post/page IDs that AI tools cannot modify or delete. Use this to protect critical pages like the homepage or landing pages from accidental changes.', 'bricks-mcp' ); ?>
		</p>
		<?php
	}

	/**
	 * Enqueue admin scripts on the settings page.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_scripts( string $hook ): void {
		if ( 'bricks_page_bricks-mcp' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'bricks-mcp-admin-settings',
			BRICKS_MCP_PLUGIN_URL . 'assets/css/admin-settings.css',
			[],
			BRICKS_MCP_VERSION
		);

		wp_enqueue_script(
			'bricks-mcp-admin-updates',
			BRICKS_MCP_PLUGIN_URL . 'assets/js/admin-updates.js',
			[],
			BRICKS_MCP_VERSION,
			false
		);

		$current_user = wp_get_current_user();

		wp_localize_script(
			'bricks-mcp-admin-updates',
			'bricksMcpUpdates',
			[
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'bricks_mcp_settings_nonce' ),
				'currentVersion'  => BRICKS_MCP_VERSION,
				'siteUrl'         => get_site_url(),
				'restBase'        => rest_url( 'bricks-mcp/v1/' ),
				'mcpUrl'          => rest_url( 'bricks-mcp/v1/mcp' ),
				'currentUsername' => $current_user->user_login,
				'profileUrl'      => admin_url( 'profile.php' ),
				'updateCoreUrl'   => admin_url( 'update-core.php' ),
			]
		);
	}

	/**
	 * Render the version info card.
	 *
	 * Shows current version, update availability, and a "Check Now" button.
	 *
	 * @return void
	 */
	private function render_version_card(): void {
		$current_version = BRICKS_MCP_VERSION;
		$update_checker  = \BricksMCP\Plugin::get_instance()->get_update_checker();
		$update_data     = null !== $update_checker ? $update_checker->get_cached_update_data() : [];
		$has_update      = ! empty( $update_data['version'] )
			&& version_compare( $current_version, $update_data['version'], '<' );

		?>
		<div class="bricks-mcp-version-card<?php echo $has_update ? ' bricks-mcp-version-card--has-update' : ''; ?>">
			<h2><?php esc_html_e( 'Version', 'bricks-mcp' ); ?></h2>
			<p id="bricks-mcp-version-text">
				<strong>v<?php echo esc_html( $current_version ); ?></strong>
				<?php if ( $has_update ) : ?>
					&mdash;
					<span class="bricks-mcp-update-available">
						v<?php echo esc_html( $update_data['version'] ); ?>
						<?php esc_html_e( 'available', 'bricks-mcp' ); ?>
					</span>
					<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>">
						<?php esc_html_e( 'Update', 'bricks-mcp' ); ?>
					</a>
				<?php else : ?>
					&mdash; <span class="bricks-mcp-up-to-date"><?php esc_html_e( 'up to date', 'bricks-mcp' ); ?></span>
				<?php endif; ?>
			</p>
			<p>
				<button type="button" id="bricks-mcp-check-update-btn" class="button">
					<?php esc_html_e( 'Check Now', 'bricks-mcp' ); ?>
				</button>
				<span id="bricks-mcp-check-update-spinner" class="spinner"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Render MCP configuration tabs with Claude Code and Gemini snippets.
	 *
	 * Includes copy-to-clipboard and brief instructions.
	 *
	 * @return void
	 */
	private function render_mcp_config(): void {
		$current_user = wp_get_current_user();
		$username     = $current_user->user_login;

		// Build MCP endpoint URL with optional custom base URL override.
		$settings    = get_option( self::OPTION_NAME, $this->get_defaults() );
		$custom_base = $settings['custom_base_url'] ?? '';
		if ( ! empty( $custom_base ) ) {
			$mcp_url = trailingslashit( $custom_base ) . 'wp-json/bricks-mcp/v1/mcp';
		} else {
			$mcp_url = rest_url( 'bricks-mcp/v1/mcp' );
		}

		// Build Claude Code config snippet.
		$claude_config = json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'type'    => 'http',
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic YOUR_BASE64_AUTH_STRING',
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Build Gemini config snippet.
		$gemini_config = json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'httpUrl' => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic YOUR_BASE64_AUTH_STRING',
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Cursor config — uses .cursor/mcp.json.
		$cursor_config = json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic YOUR_BASE64_AUTH_STRING',
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// VS Code / Open Code config — uses .vscode/mcp.json.
		$vscode_config = json_encode(
			[
				'servers' => [
					'bricks-mcp' => [
						'type'    => 'http',
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic YOUR_BASE64_AUTH_STRING',
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Augment config.
		$augment_config = json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic YOUR_BASE64_AUTH_STRING',
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Qwen Code config — uses settings.json.
		$qwen_config = json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic YOUR_BASE64_AUTH_STRING',
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Claude Desktop config.
		$claude_desktop_config = json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic YOUR_BASE64_AUTH_STRING',
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		?>
		<div class="bricks-mcp-config-section">
			<h2><?php esc_html_e( 'MCP Configuration', 'bricks-mcp' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Add the following configuration to your AI tool to connect to this MCP server.', 'bricks-mcp' ); ?></p>

			<div class="bricks-mcp-tabs bricks-mcp-tabs-wrap">
				<div role="tablist">
					<button type="button" role="tab" id="bricks-mcp-tab-claude" data-tab="claude" aria-selected="true" aria-controls="bricks-mcp-panel-claude" tabindex="0" class="active">
						<?php esc_html_e( 'Claude Code', 'bricks-mcp' ); ?>
					</button>
					<button type="button" role="tab" id="bricks-mcp-tab-claude-desktop" data-tab="claude-desktop" aria-selected="false" aria-controls="bricks-mcp-panel-claude-desktop" tabindex="-1">
						<?php esc_html_e( 'Claude Desktop', 'bricks-mcp' ); ?>
					</button>
					<button type="button" role="tab" id="bricks-mcp-tab-gemini" data-tab="gemini" aria-selected="false" aria-controls="bricks-mcp-panel-gemini" tabindex="-1">
						<?php esc_html_e( 'Gemini', 'bricks-mcp' ); ?>
					</button>
					<button type="button" role="tab" id="bricks-mcp-tab-cursor" data-tab="cursor" aria-selected="false" aria-controls="bricks-mcp-panel-cursor" tabindex="-1">
						<?php esc_html_e( 'Cursor', 'bricks-mcp' ); ?>
					</button>
					<button type="button" role="tab" id="bricks-mcp-tab-vscode" data-tab="vscode" aria-selected="false" aria-controls="bricks-mcp-panel-vscode" tabindex="-1">
						<?php esc_html_e( 'VS Code', 'bricks-mcp' ); ?>
					</button>
					<button type="button" role="tab" id="bricks-mcp-tab-augment" data-tab="augment" aria-selected="false" aria-controls="bricks-mcp-panel-augment" tabindex="-1">
						<?php esc_html_e( 'Augment', 'bricks-mcp' ); ?>
					</button>
					<button type="button" role="tab" id="bricks-mcp-tab-qwen" data-tab="qwen" aria-selected="false" aria-controls="bricks-mcp-panel-qwen" tabindex="-1">
						<?php esc_html_e( 'Qwen', 'bricks-mcp' ); ?>
					</button>
				</div>

				<!-- Claude Code Panel -->
				<div role="tabpanel" id="bricks-mcp-panel-claude" aria-labelledby="bricks-mcp-tab-claude" data-panel="claude">
					<div class="bricks-mcp-code-wrap">
						<pre><code id="bricks-mcp-claude-config"><?php echo esc_html( $claude_config ); ?></code></pre>
						<button type="button" class="button bricks-mcp-copy-btn" data-target="bricks-mcp-claude-config">
							<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
						</button>
					</div>
					<p class="description bricks-mcp-tab-description">
						<?php esc_html_e( 'Add this to your .mcp.json file, or use:', 'bricks-mcp' ); ?>
						<code>claude mcp add bricks-mcp <?php echo esc_html( $mcp_url ); ?> --transport http --header "Authorization: Basic ..."</code>
					</p>
					<p class="description">
						<?php
						echo wp_kses(
							__( 'Replace <code>YOUR_BASE64_AUTH_STRING</code> with the Base64-encoded value of <code>username:app_password</code>, or use the button below to generate a ready-to-paste config.', 'bricks-mcp' ),
							[
								'code'   => [],
								'strong' => [],
							]
						);
						?>
					</p>
					<div class="bricks-mcp-tab-generate" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ccd0d4;">
						<button type="button" class="button button-primary bricks-mcp-generate-for-client" data-client="claude">
							<?php esc_html_e( 'Generate Claude Code Config', 'bricks-mcp' ); ?>
						</button>
						<span class="spinner" style="float: none;"></span>
						<div class="bricks-mcp-generated-for-client" style="display: none; margin-top: 15px;">
							<div class="bricks-mcp-important-notice">
								<strong><?php esc_html_e( 'Important:', 'bricks-mcp' ); ?></strong>
								<?php esc_html_e( 'This password is shown once. Copy your config now -- it cannot be retrieved later.', 'bricks-mcp' ); ?>
							</div>
							<h4><?php esc_html_e( 'One-liner:', 'bricks-mcp' ); ?></h4>
							<div class="bricks-mcp-code-wrap bricks-mcp-code-wrap--breakall">
								<pre><code class="bricks-mcp-gen-command"></code></pre>
								<button type="button" class="button bricks-mcp-copy-btn" data-target-class="bricks-mcp-gen-command">
									<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
								</button>
							</div>
							<h4><?php esc_html_e( 'JSON config (with real credentials):', 'bricks-mcp' ); ?></h4>
							<div class="bricks-mcp-code-wrap">
								<pre><code class="bricks-mcp-gen-config"></code></pre>
								<button type="button" class="button bricks-mcp-copy-btn" data-target-class="bricks-mcp-gen-config">
									<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
								</button>
							</div>
						</div>
					</div>
				</div>

				<!-- Claude Desktop Panel -->
				<div role="tabpanel" id="bricks-mcp-panel-claude-desktop" aria-labelledby="bricks-mcp-tab-claude-desktop" data-panel="claude-desktop" style="display:none;">
					<div class="bricks-mcp-code-wrap">
						<pre><code id="bricks-mcp-claude-desktop-config"><?php echo esc_html( $claude_desktop_config ); ?></code></pre>
						<button type="button" class="button bricks-mcp-copy-btn" data-target="bricks-mcp-claude-desktop-config">
							<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
						</button>
					</div>
					<p class="description bricks-mcp-tab-description">
						<?php esc_html_e( 'Go to Settings > Developer > Edit Config, or add this to ~/Library/Application Support/Claude/claude_desktop_config.json (macOS).', 'bricks-mcp' ); ?>
					</p>
					<p class="description">
						<?php
						echo wp_kses(
							__( 'Replace <code>YOUR_BASE64_AUTH_STRING</code> with the Base64-encoded value of <code>username:app_password</code>, or use the button below to generate a ready-to-paste config.', 'bricks-mcp' ),
							[
								'code'   => [],
								'strong' => [],
							]
						);
						?>
					</p>
					<div class="bricks-mcp-tab-generate" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ccd0d4;">
						<button type="button" class="button button-primary bricks-mcp-generate-for-client" data-client="claude-desktop">
							<?php esc_html_e( 'Generate Claude Desktop Config', 'bricks-mcp' ); ?>
						</button>
						<span class="spinner" style="float: none;"></span>
						<div class="bricks-mcp-generated-for-client" style="display: none; margin-top: 15px;">
							<div class="bricks-mcp-important-notice">
								<strong><?php esc_html_e( 'Important:', 'bricks-mcp' ); ?></strong>
								<?php esc_html_e( 'This password is shown once. Copy your config now -- it cannot be retrieved later.', 'bricks-mcp' ); ?>
							</div>
							<h4><?php esc_html_e( 'JSON config (with real credentials):', 'bricks-mcp' ); ?></h4>
							<div class="bricks-mcp-code-wrap">
								<pre><code class="bricks-mcp-gen-config"></code></pre>
								<button type="button" class="button bricks-mcp-copy-btn" data-target-class="bricks-mcp-gen-config">
									<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
								</button>
							</div>
						</div>
					</div>
				</div>

				<!-- Gemini Panel -->
				<div role="tabpanel" id="bricks-mcp-panel-gemini" aria-labelledby="bricks-mcp-tab-gemini" data-panel="gemini" style="display:none;">
					<div class="bricks-mcp-code-wrap">
						<pre><code id="bricks-mcp-gemini-config"><?php echo esc_html( $gemini_config ); ?></code></pre>
						<button type="button" class="button bricks-mcp-copy-btn" data-target="bricks-mcp-gemini-config">
							<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
						</button>
					</div>
					<p class="description bricks-mcp-tab-description">
						<?php esc_html_e( 'Add this to your ~/.gemini/settings.json file, or use:', 'bricks-mcp' ); ?>
						<code>gemini mcp add bricks-mcp --httpUrl <?php echo esc_html( $mcp_url ); ?> --header "Authorization: Basic ..."</code>
					</p>
					<p class="description">
						<?php
						echo wp_kses(
							__( 'Replace <code>YOUR_BASE64_AUTH_STRING</code> with the Base64-encoded value of <code>username:app_password</code>, or use the button below to generate a ready-to-paste config.', 'bricks-mcp' ),
							[
								'code'   => [],
								'strong' => [],
							]
						);
						?>
					</p>
					<div class="bricks-mcp-tab-generate" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ccd0d4;">
						<button type="button" class="button button-primary bricks-mcp-generate-for-client" data-client="gemini">
							<?php esc_html_e( 'Generate Gemini Config', 'bricks-mcp' ); ?>
						</button>
						<span class="spinner" style="float: none;"></span>
						<div class="bricks-mcp-generated-for-client" style="display: none; margin-top: 15px;">
							<div class="bricks-mcp-important-notice">
								<strong><?php esc_html_e( 'Important:', 'bricks-mcp' ); ?></strong>
								<?php esc_html_e( 'This password is shown once. Copy your config now -- it cannot be retrieved later.', 'bricks-mcp' ); ?>
							</div>
							<h4><?php esc_html_e( 'One-liner:', 'bricks-mcp' ); ?></h4>
							<div class="bricks-mcp-code-wrap bricks-mcp-code-wrap--breakall">
								<pre><code class="bricks-mcp-gen-command"></code></pre>
								<button type="button" class="button bricks-mcp-copy-btn" data-target-class="bricks-mcp-gen-command">
									<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
								</button>
							</div>
							<h4><?php esc_html_e( 'JSON config (with real credentials):', 'bricks-mcp' ); ?></h4>
							<div class="bricks-mcp-code-wrap">
								<pre><code class="bricks-mcp-gen-config"></code></pre>
								<button type="button" class="button bricks-mcp-copy-btn" data-target-class="bricks-mcp-gen-config">
									<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
								</button>
							</div>
						</div>
					</div>
				</div>

				<!-- Cursor Panel -->
				<div role="tabpanel" id="bricks-mcp-panel-cursor" aria-labelledby="bricks-mcp-tab-cursor" data-panel="cursor" style="display:none;">
					<div class="bricks-mcp-code-wrap">
						<pre><code id="bricks-mcp-cursor-config"><?php echo esc_html( $cursor_config ); ?></code></pre>
						<button type="button" class="button bricks-mcp-copy-btn" data-target="bricks-mcp-cursor-config">
							<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
						</button>
					</div>
					<p class="description bricks-mcp-tab-description">
						<?php esc_html_e( 'Add this to ~/.cursor/mcp.json (global) or .cursor/mcp.json (project-level).', 'bricks-mcp' ); ?>
					</p>
					<p class="description">
						<?php
						echo wp_kses(
							__( 'Replace <code>YOUR_BASE64_AUTH_STRING</code> with the Base64-encoded value of <code>username:app_password</code>, or use the button below to generate a ready-to-paste config.', 'bricks-mcp' ),
							[
								'code'   => [],
								'strong' => [],
							]
						);
						?>
					</p>
					<div class="bricks-mcp-tab-generate" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ccd0d4;">
						<button type="button" class="button button-primary bricks-mcp-generate-for-client" data-client="cursor">
							<?php esc_html_e( 'Generate Cursor Config', 'bricks-mcp' ); ?>
						</button>
						<span class="spinner" style="float: none;"></span>
						<div class="bricks-mcp-generated-for-client" style="display: none; margin-top: 15px;">
							<div class="bricks-mcp-important-notice">
								<strong><?php esc_html_e( 'Important:', 'bricks-mcp' ); ?></strong>
								<?php esc_html_e( 'This password is shown once. Copy your config now -- it cannot be retrieved later.', 'bricks-mcp' ); ?>
							</div>
							<h4><?php esc_html_e( 'JSON config (with real credentials):', 'bricks-mcp' ); ?></h4>
							<div class="bricks-mcp-code-wrap">
								<pre><code class="bricks-mcp-gen-config"></code></pre>
								<button type="button" class="button bricks-mcp-copy-btn" data-target-class="bricks-mcp-gen-config">
									<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
								</button>
							</div>
						</div>
					</div>
				</div>

				<!-- VS Code / Open Code Panel -->
				<div role="tabpanel" id="bricks-mcp-panel-vscode" aria-labelledby="bricks-mcp-tab-vscode" data-panel="vscode" style="display:none;">
					<div class="bricks-mcp-code-wrap">
						<pre><code id="bricks-mcp-vscode-config"><?php echo esc_html( $vscode_config ); ?></code></pre>
						<button type="button" class="button bricks-mcp-copy-btn" data-target="bricks-mcp-vscode-config">
							<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
						</button>
					</div>
					<p class="description bricks-mcp-tab-description">
						<?php esc_html_e( 'Add this to .vscode/mcp.json in your project root.', 'bricks-mcp' ); ?>
					</p>
					<p class="description">
						<?php
						echo wp_kses(
							__( 'Replace <code>YOUR_BASE64_AUTH_STRING</code> with the Base64-encoded value of <code>username:app_password</code>, or use the button below to generate a ready-to-paste config.', 'bricks-mcp' ),
							[
								'code'   => [],
								'strong' => [],
							]
						);
						?>
					</p>
					<div class="bricks-mcp-tab-generate" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ccd0d4;">
						<button type="button" class="button button-primary bricks-mcp-generate-for-client" data-client="vscode">
							<?php esc_html_e( 'Generate VS Code Config', 'bricks-mcp' ); ?>
						</button>
						<span class="spinner" style="float: none;"></span>
						<div class="bricks-mcp-generated-for-client" style="display: none; margin-top: 15px;">
							<div class="bricks-mcp-important-notice">
								<strong><?php esc_html_e( 'Important:', 'bricks-mcp' ); ?></strong>
								<?php esc_html_e( 'This password is shown once. Copy your config now -- it cannot be retrieved later.', 'bricks-mcp' ); ?>
							</div>
							<h4><?php esc_html_e( 'JSON config (with real credentials):', 'bricks-mcp' ); ?></h4>
							<div class="bricks-mcp-code-wrap">
								<pre><code class="bricks-mcp-gen-config"></code></pre>
								<button type="button" class="button bricks-mcp-copy-btn" data-target-class="bricks-mcp-gen-config">
									<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
								</button>
							</div>
						</div>
					</div>
				</div>

				<!-- Augment Panel -->
				<div role="tabpanel" id="bricks-mcp-panel-augment" aria-labelledby="bricks-mcp-tab-augment" data-panel="augment" style="display:none;">
					<div class="bricks-mcp-code-wrap">
						<pre><code id="bricks-mcp-augment-config"><?php echo esc_html( $augment_config ); ?></code></pre>
						<button type="button" class="button bricks-mcp-copy-btn" data-target="bricks-mcp-augment-config">
							<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
						</button>
					</div>
					<p class="description bricks-mcp-tab-description">
						<?php esc_html_e( 'Open Augment Settings Panel > MCP Servers, and paste this JSON configuration.', 'bricks-mcp' ); ?>
					</p>
					<p class="description">
						<?php
						echo wp_kses(
							__( 'Replace <code>YOUR_BASE64_AUTH_STRING</code> with the Base64-encoded value of <code>username:app_password</code>, or use the button below to generate a ready-to-paste config.', 'bricks-mcp' ),
							[
								'code'   => [],
								'strong' => [],
							]
						);
						?>
					</p>
					<div class="bricks-mcp-tab-generate" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ccd0d4;">
						<button type="button" class="button button-primary bricks-mcp-generate-for-client" data-client="augment">
							<?php esc_html_e( 'Generate Augment Config', 'bricks-mcp' ); ?>
						</button>
						<span class="spinner" style="float: none;"></span>
						<div class="bricks-mcp-generated-for-client" style="display: none; margin-top: 15px;">
							<div class="bricks-mcp-important-notice">
								<strong><?php esc_html_e( 'Important:', 'bricks-mcp' ); ?></strong>
								<?php esc_html_e( 'This password is shown once. Copy your config now -- it cannot be retrieved later.', 'bricks-mcp' ); ?>
							</div>
							<h4><?php esc_html_e( 'JSON config (with real credentials):', 'bricks-mcp' ); ?></h4>
							<div class="bricks-mcp-code-wrap">
								<pre><code class="bricks-mcp-gen-config"></code></pre>
								<button type="button" class="button bricks-mcp-copy-btn" data-target-class="bricks-mcp-gen-config">
									<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
								</button>
							</div>
						</div>
					</div>
				</div>

				<!-- Qwen Code Panel -->
				<div role="tabpanel" id="bricks-mcp-panel-qwen" aria-labelledby="bricks-mcp-tab-qwen" data-panel="qwen" style="display:none;">
					<div class="bricks-mcp-code-wrap">
						<pre><code id="bricks-mcp-qwen-config"><?php echo esc_html( $qwen_config ); ?></code></pre>
						<button type="button" class="button bricks-mcp-copy-btn" data-target="bricks-mcp-qwen-config">
							<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
						</button>
					</div>
					<p class="description bricks-mcp-tab-description">
						<?php esc_html_e( 'Add this to your settings.json configuration file.', 'bricks-mcp' ); ?>
					</p>
					<p class="description">
						<?php
						echo wp_kses(
							__( 'Replace <code>YOUR_BASE64_AUTH_STRING</code> with the Base64-encoded value of <code>username:app_password</code>, or use the button below to generate a ready-to-paste config.', 'bricks-mcp' ),
							[
								'code'   => [],
								'strong' => [],
							]
						);
						?>
					</p>
					<div class="bricks-mcp-tab-generate" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ccd0d4;">
						<button type="button" class="button button-primary bricks-mcp-generate-for-client" data-client="qwen">
							<?php esc_html_e( 'Generate Qwen Code Config', 'bricks-mcp' ); ?>
						</button>
						<span class="spinner" style="float: none;"></span>
						<div class="bricks-mcp-generated-for-client" style="display: none; margin-top: 15px;">
							<div class="bricks-mcp-important-notice">
								<strong><?php esc_html_e( 'Important:', 'bricks-mcp' ); ?></strong>
								<?php esc_html_e( 'This password is shown once. Copy your config now -- it cannot be retrieved later.', 'bricks-mcp' ); ?>
							</div>
							<h4><?php esc_html_e( 'JSON config (with real credentials):', 'bricks-mcp' ); ?></h4>
							<div class="bricks-mcp-code-wrap">
								<pre><code class="bricks-mcp-gen-config"></code></pre>
								<button type="button" class="button bricks-mcp-copy-btn" data-target-class="bricks-mcp-gen-config">
									<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
								</button>
							</div>
						</div>
					</div>
				</div>
			</div>

			</div>
		<?php
	}

	/**
	 * AJAX handler: Run all diagnostic checks and return structured results.
	 *
	 * @return void
	 */
	public function ajax_run_diagnostics(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		$runner = new \BricksMCP\Admin\DiagnosticRunner();
		$runner->register_defaults();
		$results = $runner->run_all();

		wp_send_json_success( $results );
	}

	/**
	 * Render the System Status diagnostic panel.
	 *
	 * Replaces the old Test Connection panel per D-07. Provides a Run Diagnostics
	 * button that executes all checks via AJAX and renders a colored checklist.
	 * Also provides a Copy Results button for support ticket use.
	 *
	 * @return void
	 */
	private function render_diagnostic_panel(): void {
		?>
		<style>
			.bricks-mcp-diagnostics { margin: 20px 0; background: #fff; border: 1px solid #c3c4c7; padding: 15px 20px; }
			.bricks-mcp-diagnostics h3 { margin-top: 0; }
			.bricks-mcp-diagnostics-actions { margin: 10px 0; display: flex; align-items: center; gap: 8px; }
			.bricks-mcp-check { display: flex; align-items: flex-start; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f0f0f1; }
			.bricks-mcp-check:last-child { border-bottom: none; }
			.bricks-mcp-check .dashicons { margin-top: 2px; font-size: 20px; width: 20px; height: 20px; }
			.bricks-mcp-check--pass .dashicons { color: #00a32a; }
			.bricks-mcp-check--warn .dashicons { color: #dba617; }
			.bricks-mcp-check--fail .dashicons { color: #d63638; }
			.bricks-mcp-check--skipped .dashicons { color: #787c82; }
			.bricks-mcp-check-content p { margin: 2px 0 0; }
			.bricks-mcp-check-fixes { margin-top: 5px; padding: 8px 12px; background: #f6f7f7; border-radius: 3px; }
			.bricks-mcp-check-fixes ul { margin: 5px 0 0 15px; }
			.bricks-mcp-diagnostics-summary { font-size: 14px; margin: 10px 0; }
			#bricks-mcp-diagnostics-spinner { float: none; margin: 0; }
		</style>

		<div class="bricks-mcp-diagnostics" id="bricks-mcp-diagnostics">
			<h3><?php esc_html_e( 'System Status', 'bricks-mcp' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Run diagnostics to check if your site is properly configured for MCP connections.', 'bricks-mcp' ); ?></p>
			<div class="bricks-mcp-diagnostics-actions">
				<button type="button" class="button button-primary" id="bricks-mcp-run-diagnostics">
					<?php esc_html_e( 'Run Diagnostics', 'bricks-mcp' ); ?>
				</button>
				<button type="button" class="button" id="bricks-mcp-copy-results" style="display:none;">
					<?php esc_html_e( 'Copy Results', 'bricks-mcp' ); ?>
				</button>
				<span class="spinner" id="bricks-mcp-diagnostics-spinner"></span>
			</div>
			<div id="bricks-mcp-diagnostics-results"></div>
		</div>

		<script>
		(function() {
			var iconMap = {
				pass:    'dashicons-yes-alt',
				warn:    'dashicons-warning',
				fail:    'dashicons-dismiss',
				skipped: 'dashicons-minus'
			};

			var diagnosticsData = null;

			function escHtml(s) {
				var d = document.createElement('div');
				d.appendChild(document.createTextNode(s));
				return d.innerHTML;
			}

			document.getElementById('bricks-mcp-run-diagnostics').addEventListener('click', function() {
				var btn      = this;
				var spinner  = document.getElementById('bricks-mcp-diagnostics-spinner');
				var results  = document.getElementById('bricks-mcp-diagnostics-results');
				var copyBtn  = document.getElementById('bricks-mcp-copy-results');

				btn.disabled = true;
				spinner.classList.add('is-active');
				results.innerHTML = '';
				copyBtn.style.display = 'none';

				var data = new FormData();
				data.append('action', 'bricks_mcp_run_diagnostics');
				data.append('nonce', bricksMcpUpdates.nonce);

				fetch(bricksMcpUpdates.ajaxUrl, { method: 'POST', body: data })
					.then(function(r) { return r.json(); })
					.then(function(response) {
						btn.disabled = false;
						spinner.classList.remove('is-active');

						if (!response.success) {
							results.innerHTML = '<p style="color:#d63638;">' + (response.data && response.data.message ? escHtml(response.data.message) : '<?php echo esc_js( __( 'An error occurred.', 'bricks-mcp' ) ); ?>') + '</p>';
							return;
						}

						diagnosticsData = response.data;
						var html = '<p class="bricks-mcp-diagnostics-summary"><strong>' + escHtml(response.data.summary) + '</strong></p>';

						response.data.checks.forEach(function(check) {
							var icon = iconMap[check.status] || 'dashicons-minus';
							var fixHtml = '';
							if (check.fix_steps && check.fix_steps.length > 0) {
								fixHtml = '<div class="bricks-mcp-check-fixes"><strong><?php echo esc_js( __( 'How to fix:', 'bricks-mcp' ) ); ?></strong><ul>';
								check.fix_steps.forEach(function(step) {
									fixHtml += '<li>' + escHtml(step) + '</li>';
								});
								fixHtml += '</ul></div>';
							}
							html += '<div class="bricks-mcp-check bricks-mcp-check--' + check.status + '">';
							html += '<span class="dashicons ' + icon + '"></span>';
							html += '<div class="bricks-mcp-check-content">';
							html += '<strong>' + escHtml(check.label) + '</strong>';
							html += '<p>' + escHtml(check.message) + '</p>';
							html += fixHtml;
							html += '</div></div>';
						});

						results.innerHTML = html;
						copyBtn.style.display = 'inline-block';
					})
					.catch(function(err) {
						btn.disabled = false;
						spinner.classList.remove('is-active');
						results.innerHTML = '<p style="color:#d63638;"><?php echo esc_js( __( 'Request failed. Please try again.', 'bricks-mcp' ) ); ?></p>';
					});
			});

			document.getElementById('bricks-mcp-copy-results').addEventListener('click', function() {
				if (!diagnosticsData) return;
				var copyBtn = this;
				var text = '';
				diagnosticsData.checks.forEach(function(check) {
					text += '[' + check.status.toUpperCase() + '] ' + check.label + ': ' + check.message + '\n';
					if (check.fix_steps && check.fix_steps.length > 0) {
						check.fix_steps.forEach(function(step) {
							text += '  Fix: ' + step + '\n';
						});
					}
				});
				navigator.clipboard.writeText(text).then(function() {
					copyBtn.textContent = '<?php echo esc_js( __( 'Copied!', 'bricks-mcp' ) ); ?>';
					setTimeout(function() {
						copyBtn.textContent = '<?php echo esc_js( __( 'Copy Results', 'bricks-mcp' ) ); ?>';
					}, 2000);
				});
			});
		}());
		</script>
		<?php
	}

	/**
	 * AJAX handler: Generate an Application Password and return setup commands.
	 *
	 * Creates a WordPress Application Password for the current user and returns
	 * a complete claude mcp add command with auth headers, plus JSON configs
	 * for Claude Code and Gemini with real credentials.
	 *
	 * @return void
	 */
	public function ajax_generate_app_password(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		$current_user = wp_get_current_user();
		$username     = $current_user->user_login;

		// Create Application Password.
		$result = \WP_Application_Passwords::create_new_application_password(
			$current_user->ID,
			[
				'name'   => 'Bricks MCP - Claude Code',
				'app_id' => wp_generate_uuid4(),
			]
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		// $result is [ $password, $item ] -- the raw password is only available at creation time.
		$password = $result[0];

		// Build MCP endpoint URL with optional custom base URL override.
		$settings    = get_option( self::OPTION_NAME, $this->get_defaults() );
		$custom_base = $settings['custom_base_url'] ?? '';
		if ( ! empty( $custom_base ) ) {
			$mcp_url = trailingslashit( $custom_base ) . 'wp-json/bricks-mcp/v1/mcp';
		} else {
			$mcp_url = rest_url( 'bricks-mcp/v1/mcp' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$auth_string = base64_encode( $username . ':' . $password );

		// Build the complete CLI command.
		$claude_command = sprintf(
			'claude mcp add bricks-mcp %s --transport http --header "Authorization: Basic %s"',
			$mcp_url,
			$auth_string
		);

		// Build Claude Code JSON config with real credentials.
		$claude_config = wp_json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'type'    => 'http',
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic ' . $auth_string,
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Build Gemini JSON config with real credentials.
		$gemini_config = wp_json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'httpUrl' => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic ' . $auth_string,
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Build Gemini CLI one-liner.
		$gemini_command = sprintf(
			'gemini mcp add bricks-mcp --httpUrl %s --header "Authorization: Basic %s"',
			$mcp_url,
			$auth_string
		);

		// Build Cursor JSON config with real credentials.
		$cursor_config = wp_json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic ' . $auth_string,
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Build VS Code / Open Code JSON config with real credentials.
		$vscode_config = wp_json_encode(
			[
				'servers' => [
					'bricks-mcp' => [
						'type'    => 'http',
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic ' . $auth_string,
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Build Augment JSON config with real credentials.
		$augment_config = wp_json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic ' . $auth_string,
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Build Qwen Code JSON config with real credentials.
		$qwen_config = wp_json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic ' . $auth_string,
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Build Claude Desktop JSON config with real credentials.
		$claude_desktop_config = wp_json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic ' . $auth_string,
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		wp_send_json_success(
			[
				'password'              => $password,
				'username'              => $username,
				'auth_string'           => $auth_string,
				'claude_command'        => $claude_command,
				'claude_config'         => $claude_config,
				'gemini_command'        => $gemini_command,
				'gemini_config'         => $gemini_config,
				'cursor_config'         => $cursor_config,
				'vscode_config'         => $vscode_config,
				'augment_config'        => $augment_config,
				'qwen_config'           => $qwen_config,
				'claude_desktop_config' => $claude_desktop_config,
				'mcp_url'               => $mcp_url,
			]
		);
	}

	/**
	 * AJAX handler: Delete an AI note.
	 *
	 * @return void
	 */
	public function ajax_delete_note(): void {
		check_ajax_referer( 'bricks_mcp_notes', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'bricks-mcp' ), 403 );
		}

		$note_id = isset( $_POST['note_id'] ) ? sanitize_text_field( wp_unslash( $_POST['note_id'] ) ) : '';
		if ( empty( $note_id ) ) {
			wp_send_json_error( __( 'Missing note ID.', 'bricks-mcp' ) );
		}

		$notes    = get_option( 'bricks_mcp_notes', [] );
		$filtered = array_values( array_filter( $notes, static fn( $n ) => ( $n['id'] ?? '' ) !== $note_id ) );

		if ( count( $filtered ) === count( $notes ) ) {
			wp_send_json_error( __( 'Note not found.', 'bricks-mcp' ) );
		}

		update_option( 'bricks_mcp_notes', $filtered, false );
		wp_send_json_success( true );
	}

	/**
	 * AJAX handler: Add an AI note.
	 *
	 * @return void
	 */
	public function ajax_add_note(): void {
		check_ajax_referer( 'bricks_mcp_notes', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'bricks-mcp' ), 403 );
		}

		$text = isset( $_POST['text'] ) ? sanitize_text_field( wp_unslash( $_POST['text'] ) ) : '';
		if ( empty( $text ) ) {
			wp_send_json_error( __( 'Note text is required.', 'bricks-mcp' ) );
		}

		$notes = get_option( 'bricks_mcp_notes', [] );
		if ( ! is_array( $notes ) ) {
			$notes = [];
		}

		$id   = 'note_' . substr( md5( (string) time() . wp_generate_password( 4, false ) ), 0, 8 );
		$note = [
			'id'         => $id,
			'text'       => $text,
			'created_at' => current_time( 'mysql' ),
		];

		$notes[] = $note;
		update_option( 'bricks_mcp_notes', $notes, false );
		wp_send_json_success( $note );
	}

}
