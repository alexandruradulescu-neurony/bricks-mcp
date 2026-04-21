<?php
/**
 * Admin settings page.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Admin;

use BricksMCP\MCP\Services\BricksCore;

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
	 * Design System admin handler.
	 */
	private DesignSystemAdmin $design_system_admin;

	/**
	 * Patterns admin handler.
	 */
	private PatternsAdmin $patterns_admin;

	/**
	 * Diagnostics admin handler.
	 */
	private DiagnosticsAdmin $diagnostics_admin;

	/**
	 * Initialize admin settings.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->design_system_admin = new DesignSystemAdmin();
		$this->design_system_admin->init();

		$this->patterns_admin = new PatternsAdmin();
		$this->patterns_admin->init();

		$this->diagnostics_admin = new DiagnosticsAdmin();
		$this->diagnostics_admin->init();

		add_action( 'admin_menu', [ $this, 'add_settings_page' ], 99 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'wp_ajax_bricks_mcp_generate_app_password', [ $this, 'ajax_generate_app_password' ] );
		add_action( 'wp_ajax_bricks_mcp_delete_note', [ $this, 'ajax_delete_note' ] );
		add_action( 'wp_ajax_bricks_mcp_add_note', [ $this, 'ajax_add_note' ] );
		add_action( 'wp_ajax_bricks_mcp_revoke_app_password', [ $this, 'ajax_revoke_app_password' ] );
		add_action( 'wp_ajax_bricks_mcp_parse_brief', [ $this, 'ajax_parse_brief' ] );
	}

	/**
	 * Add settings page to admin menu.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		add_submenu_page(
			'bricks',
			__( 'Bricks WP MCP', 'bricks-mcp' ),
			__( 'Bricks WP MCP', 'bricks-mcp' ),
			BricksCore::REQUIRED_CAPABILITY,
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

		// Server section — core on/off and auth.
		add_settings_section(
			'bricks_mcp_server',
			__( 'Server', 'bricks-mcp' ),
			[ $this, 'render_server_section' ],
			self::PAGE_SLUG . '_server'
		);

		add_settings_field(
			'enabled',
			__( 'Enable MCP Server', 'bricks-mcp' ),
			[ $this, 'render_enabled_field' ],
			self::PAGE_SLUG . '_server',
			'bricks_mcp_server'
		);

		add_settings_field(
			'require_auth',
			__( 'Require Authentication', 'bricks-mcp' ),
			[ $this, 'render_require_auth_field' ],
			self::PAGE_SLUG . '_server',
			'bricks_mcp_server'
		);

		// Advanced section — rate limits, URLs, safety.
		add_settings_section(
			'bricks_mcp_advanced',
			__( 'Advanced', 'bricks-mcp' ),
			[ $this, 'render_advanced_section' ],
			self::PAGE_SLUG . '_advanced'
		);

		add_settings_field(
			'custom_base_url',
			__( 'Custom Base URL', 'bricks-mcp' ),
			[ $this, 'render_custom_base_url_field' ],
			self::PAGE_SLUG . '_advanced',
			'bricks_mcp_advanced'
		);

		add_settings_field(
			'rate_limit_rpm',
			__( 'Rate Limit (requests/minute)', 'bricks-mcp' ),
			[ $this, 'render_rate_limit_rpm_field' ],
			self::PAGE_SLUG . '_advanced',
			'bricks_mcp_advanced'
		);

		add_settings_field(
			'dangerous_actions',
			__( 'Dangerous Actions', 'bricks-mcp' ),
			[ $this, 'render_dangerous_actions_field' ],
			self::PAGE_SLUG . '_advanced',
			'bricks_mcp_advanced'
		);

		add_settings_field(
			'protected_pages',
			__( 'Protected Pages', 'bricks-mcp' ),
			[ $this, 'render_protected_pages_field' ],
			self::PAGE_SLUG . '_advanced',
			'bricks_mcp_advanced'
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
		if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
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
			<?php settings_errors(); ?>
			<?php $this->render_page_header(); ?>

			<nav class="bwm-nav">
				<a href="?page=bricks-mcp&tab=connection" class="nav-tab <?php echo 'connection' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Connection & Settings', 'bricks-mcp' ); ?>
				</a>
				<a href="?page=bricks-mcp&tab=ai-context" class="nav-tab <?php echo 'ai-context' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'AI Context', 'bricks-mcp' ); ?>
				</a>
				<a href="?page=bricks-mcp&tab=patterns" class="nav-tab <?php echo 'patterns' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Patterns', 'bricks-mcp' ); ?>
				</a>
				<a href="?page=bricks-mcp&tab=design-system" class="nav-tab <?php echo 'design-system' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Design System', 'bricks-mcp' ); ?>
				</a>
				<a href="?page=bricks-mcp&tab=diagnostics" class="nav-tab <?php echo 'diagnostics' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'System Health', 'bricks-mcp' ); ?>
				</a>
			</nav>

			<div class="bricks-mcp-tab-content">
			<?php
			switch ( $active_tab ) {
				case 'design-system':
					$this->design_system_admin->render();
					break;
				case 'patterns':
					$this->patterns_admin->render();
					break;
				case 'ai-context':
					$this->render_tab_ai_context();
					break;
				case 'connection':
					$this->render_tab_connection();
					break;
				case 'diagnostics':
					$this->diagnostics_admin->render();
					break;
			}
			?>
			</div>

			<div class="bwm-footer">
				<span><?php echo esc_html( 'Bricks WP MCP v' . BRICKS_MCP_VERSION ); ?></span>
				<span class="bwm-footer__sep">&middot;</span>
				<a href="<?php echo esc_url( 'https://github.com/' . BRICKS_MCP_GITHUB_REPO ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'GitHub', 'bricks-mcp' ); ?></a>
				<span class="bwm-footer__sep">&middot;</span>
				<a href="<?php echo esc_url( 'https://github.com/' . BRICKS_MCP_GITHUB_REPO . '/issues' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Report an Issue', 'bricks-mcp' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the branded page header with icon, title, and version badge.
	 *
	 * @return void
	 */
	private function render_page_header(): void {
		$current_version = BRICKS_MCP_VERSION;
		$update_checker  = \BricksMCP\Plugin::get_instance()->get_update_checker();
		$update_data     = null !== $update_checker ? $update_checker->get_cached_update_data() : [];
		$has_update      = ! empty( $update_data['version'] )
			&& version_compare( $current_version, $update_data['version'], '<' );

		$version_class = $has_update ? 'bwm-header__version bwm-header__version--update' : 'bwm-header__version';

		// Connection status check (cached for 1 minute).
		$is_connected = $this->get_connection_status();

		// Request counter.
		$counter        = new \BricksMCP\MCP\Services\RequestCounterService();
		$requests_today = $counter->get_today_count();

		?>
		<div class="bwm-header">
			<div class="bwm-header__icon">
				<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6Zm2 0v4h12V6H6Zm-2 8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4Zm2 0v4h12v-4H6Zm2-6a1 1 0 1 1 2 0 1 1 0 0 1-2 0Zm4 0a1 1 0 1 1 2 0 1 1 0 0 1-2 0Zm-4 8a1 1 0 1 1 2 0 1 1 0 0 1-2 0Zm4 0a1 1 0 1 1 2 0 1 1 0 0 1-2 0Z"/></svg>
			</div>
			<div class="bwm-header__body">
				<h1 class="bwm-header__title">
					<?php esc_html_e( 'Bricks WP MCP', 'bricks-mcp' ); ?>
					<span class="<?php echo esc_attr( $version_class ); ?>" id="bricks-mcp-version-text">
						v<?php echo esc_html( $current_version ); ?>
						<?php if ( $has_update ) : ?>
							&rarr; v<?php echo esc_html( $update_data['version'] ); ?>
						<?php endif; ?>
					</span>
					<?php if ( $is_connected ) : ?>
						<span class="bwm-header__status bwm-header__status--connected"><?php esc_html_e( 'Connected', 'bricks-mcp' ); ?></span>
					<?php else : ?>
						<span class="bwm-header__status bwm-header__status--disconnected"><?php esc_html_e( 'Disconnected', 'bricks-mcp' ); ?></span>
					<?php endif; ?>
				</h1>
				<p class="bwm-header__meta">
					<?php if ( $has_update ) : ?>
						<?php esc_html_e( 'Update available', 'bricks-mcp' ); ?> &mdash;
						<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>"><?php esc_html_e( 'Install now', 'bricks-mcp' ); ?></a>
					<?php else : ?>
						<?php
						printf(
							/* translators: %s: number of requests */
							esc_html__( '%s requests today', 'bricks-mcp' ),
							'<strong>' . esc_html( number_format_i18n( $requests_today ) ) . '</strong>'
						);
						?>
					<?php endif; ?>
					&nbsp;&middot;&nbsp;
					<a href="#" id="bricks-mcp-check-update-btn"><?php esc_html_e( 'Check for updates', 'bricks-mcp' ); ?></a>
					<span id="bricks-mcp-check-update-spinner" class="spinner"></span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Check if the MCP server is enabled and the endpoint is reachable.
	 *
	 * Caches the result for 1 minute to avoid hitting the endpoint on every page load.
	 *
	 * @return bool True if connected, false otherwise.
	 */
	private function get_connection_status(): bool {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}

		$cached = get_transient( 'bricks_mcp_connection_status' );
		if ( false !== $cached ) {
			return '1' === $cached;
		}

		$response = wp_remote_get(
			rest_url( 'bricks-wp-mcp/v1/mcp' ),
			[
				'timeout'   => 3,
				'sslverify' => false,
			]
		);

		$code        = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$is_reachable = in_array( $code, [ 200, 401, 403, 405 ], true );

		set_transient( 'bricks_mcp_connection_status', $is_reachable ? '1' : '0', MINUTE_IN_SECONDS );

		return $is_reachable;
	}

	/**
	 * Render Connection tab content.
	 *
	 * Shows MCP endpoint info box and configuration snippets for AI tools.
	 *
	 * @return void
	 */
	private function render_tab_connection(): void {
		$this->render_getting_started();
		?>
		<div class="bricks-mcp-info">
			<h3><?php esc_html_e( 'Your MCP Endpoint', 'bricks-mcp' ); ?></h3>
			<p>
				<strong><?php esc_html_e( 'MCP Endpoint:', 'bricks-mcp' ); ?></strong>
				<code><?php echo esc_html( rest_url( 'bricks-wp-mcp/v1/mcp' ) ); ?></code>
			</p>
			<p class="description">
				<?php esc_html_e( 'Share this URL with your AI tool to connect.', 'bricks-mcp' ); ?>
			</p>
		</div>
		<?php
		$this->render_mcp_config();
		$this->render_active_connections();
		?>
		<hr style="margin: 2em 0;">
		<h2 id="settings"><?php esc_html_e( 'Settings', 'bricks-mcp' ); ?></h2>
		<form action="options.php" method="post">
			<?php settings_fields( self::OPTION_GROUP ); ?>

			<div class="bricks-mcp-config-section">
				<?php do_settings_sections( self::PAGE_SLUG . '_server' ); ?>
			</div>

			<div class="bricks-mcp-config-section">
				<?php do_settings_sections( self::PAGE_SLUG . '_advanced' ); ?>
			</div>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render the getting started checklist.
	 *
	 * Auto-detects completion state. Shows success banner when all steps are done.
	 *
	 * @return void
	 */
	private function render_getting_started(): void {
		$settings       = get_option( self::OPTION_NAME, $this->get_defaults() );
		$is_enabled     = ! empty( $settings['enabled'] );
		$app_passwords  = $this->get_bricks_mcp_app_passwords();
		$has_credentials = ! empty( $app_passwords );
		$all_done       = $is_enabled && $has_credentials;

		if ( $all_done ) {
			?>
			<div class="bwm-checklist bwm-checklist--done">
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'All set! Your MCP server is enabled and credentials are configured.', 'bricks-mcp' ); ?>
			</div>
			<?php
			return;
		}

		?>
		<div class="bwm-checklist">
			<h3><?php esc_html_e( 'Getting Started', 'bricks-mcp' ); ?></h3>
			<ol class="bwm-checklist__steps">
				<li class="<?php echo $is_enabled ? 'bwm-checklist__step--done' : ''; ?>">
					<span class="dashicons <?php echo $is_enabled ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>"></span>
					<div>
						<strong><?php esc_html_e( 'Enable MCP Server', 'bricks-mcp' ); ?></strong>
						<p><?php
						if ( $is_enabled ) {
							esc_html_e( 'Server is enabled.', 'bricks-mcp' );
						} else {
							printf(
								/* translators: %s: link to settings section */
								esc_html__( 'Go to %s below and enable the server.', 'bricks-mcp' ),
								'<a href="#settings">' . esc_html__( 'Settings', 'bricks-mcp' ) . '</a>'
							);
						}
						?></p>
					</div>
				</li>
				<li class="<?php echo $has_credentials ? 'bwm-checklist__step--done' : ''; ?>">
					<span class="dashicons <?php echo $has_credentials ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>"></span>
					<div>
						<strong><?php esc_html_e( 'Generate credentials', 'bricks-mcp' ); ?></strong>
						<p><?php
						if ( $has_credentials ) {
							esc_html_e( 'Credentials created.', 'bricks-mcp' );
						} else {
							esc_html_e( 'Use a "Generate Config" button below to create an Application Password.', 'bricks-mcp' );
						}
						?></p>
					</div>
				</li>
				<li>
					<span class="dashicons dashicons-marker"></span>
					<div>
						<strong><?php esc_html_e( 'Configure your AI tool', 'bricks-mcp' ); ?></strong>
						<p><?php esc_html_e( 'Copy the generated config into your AI client (Claude, Gemini, Cursor, etc).', 'bricks-mcp' ); ?></p>
					</div>
				</li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Get Application Passwords created by this plugin.
	 *
	 * Filters the current user's app passwords to those with the "Bricks MCP" name prefix.
	 *
	 * @return array<int, array<string, mixed>> Filtered app passwords.
	 */
	private function get_bricks_mcp_app_passwords(): array {
		$user_id    = get_current_user_id();
		$all_passwords = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		if ( empty( $all_passwords ) ) {
			return [];
		}
		return array_filter(
			$all_passwords,
			fn( $pw ) => str_starts_with( $pw['name'] ?? '', 'Bricks MCP' )
		);
	}

	/**
	 * Render AI Notes tab content.
	 *
	 * Shows the notes table with add/delete functionality.
	 *
	 * @return void
	 */
	private function render_tab_ai_context(): void {
		$this->render_tab_notes();
		echo '<hr style="margin: 30px 0;">';
		$this->render_structured_brief();
		echo '<hr style="margin: 30px 0;">';
		$this->render_tab_briefs();
	}

	private function render_tab_notes(): void {
		$notes       = get_option( BricksCore::OPTION_NOTES, [] );
		$notes       = is_array( $notes ) ? $notes : [];
		?>
		<div class="bricks-mcp-config-section">
			<h3><?php esc_html_e( 'AI Notes', 'bricks-mcp' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Persistent corrections and preferences stored by AI assistants. These are automatically included in the builder guide.', 'bricks-mcp' ); ?></p>

			<div class="bwm-notes-add">
				<input type="text" id="bricks-mcp-note-text" class="regular-text" placeholder="<?php esc_attr_e( 'Add a new note...', 'bricks-mcp' ); ?>">
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
		</div>
		<?php
	}

	/**
	 * Render Briefs tab content.
	 *
	 * Two textareas for Design Brief and Business Brief.
	 * Stored as separate WordPress option \BricksMCP\MCP\Services\BricksCore::OPTION_BRIEFS.
	 *
	 * @return void
	 */
	private function render_tab_briefs(): void {
		// Handle save.
		if ( isset( $_POST['bricks_mcp_briefs_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bricks_mcp_briefs_nonce'] ) ), 'bricks_mcp_save_briefs' ) ) {
			$briefs = [
				'design_brief'   => isset( $_POST['design_brief'] ) ? wp_kses_post( wp_unslash( $_POST['design_brief'] ) ) : '',
				'business_brief' => isset( $_POST['business_brief'] ) ? wp_kses_post( wp_unslash( $_POST['business_brief'] ) ) : '',
			];
			update_option( \BricksMCP\MCP\Services\BricksCore::OPTION_BRIEFS, $briefs );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Briefs saved.', 'bricks-mcp' ) . '</p></div>';
		}

		$briefs        = get_option( \BricksMCP\MCP\Services\BricksCore::OPTION_BRIEFS, [] );
		$design_brief  = $briefs['design_brief'] ?? '';
		$business_brief = $briefs['business_brief'] ?? '';
		?>
		<form method="post">
			<?php wp_nonce_field( 'bricks_mcp_save_briefs', 'bricks_mcp_briefs_nonce' ); ?>

			<div class="bricks-mcp-config-section">
				<h3><?php esc_html_e( 'Design Brief', 'bricks-mcp' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Describe the site\'s visual language: color usage, typography preferences, card styles, button styles, spacing patterns, dark/light section conventions. The AI reads this before designing any section.', 'bricks-mcp' ); ?></p>
				<textarea name="design_brief" rows="10" class="large-text" placeholder="<?php esc_attr_e( "Example:\n- Dark sections use var(--base-ultra-dark) background with 70% overlay on images\n- Cards have var(--radius-l) border radius, var(--space-l) padding\n- Buttons are pill-shaped (radius-pill), primary is filled, secondary is outline\n- Headings are centered in hero sections\n- Use Themify icons only", 'bricks-mcp' ); ?>"><?php echo esc_textarea( $design_brief ); ?></textarea>
			</div>

			<div class="bricks-mcp-config-section">
				<h3><?php esc_html_e( 'Business Brief', 'bricks-mcp' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Describe the business: what it does, who it serves, key services, target audience, tone of voice, unique selling points. The AI uses this to generate relevant content instead of placeholder text.', 'bricks-mcp' ); ?></p>
				<textarea name="business_brief" rows="10" class="large-text" placeholder="<?php esc_attr_e( "Example:\n- Towing company serving the Gub area and surroundings\n- Services: roadside assistance, vehicle towing, platform transport\n- Available 24/7 including holidays\n- Target: car owners, fleet managers, insurance companies\n- Tone: professional, trustworthy, fast response\n- USP: 20-minute average response time", 'bricks-mcp' ); ?>"><?php echo esc_textarea( $business_brief ); ?></textarea>
			</div>

			<?php submit_button( __( 'Save Briefs', 'bricks-mcp' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render server section description.
	 *
	 * @return void
	 */
	public function render_server_section(): void {
		echo '<p>' . esc_html__( 'Control how AI tools connect to your site.', 'bricks-mcp' ) . '</p>';
	}

	/**
	 * Render advanced section description.
	 *
	 * @return void
	 */
	public function render_advanced_section(): void {
		echo '<p>' . esc_html__( 'Rate limits, custom URLs, and safety controls.', 'bricks-mcp' ) . '</p>';
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
				'restBase'        => rest_url( 'bricks-wp-mcp/v1/' ),
				'mcpUrl'          => rest_url( 'bricks-wp-mcp/v1/mcp' ),
				'currentUsername' => $current_user->user_login,
				'profileUrl'      => admin_url( 'profile.php' ),
				'updateCoreUrl'   => admin_url( 'update-core.php' ),
			]
		);

		// AI Notes script.
		wp_enqueue_script(
			'bricks-mcp-admin-notes',
			BRICKS_MCP_PLUGIN_URL . 'assets/js/admin-notes.js',
			[],
			BRICKS_MCP_VERSION,
			true
		);

		wp_localize_script(
			'bricks-mcp-admin-notes',
			'bricksMcpNotes',
			[
				'nonce' => wp_create_nonce( 'bricks_mcp_notes' ),
			]
		);

		// Tab-specific assets (delegated to extracted admin classes).
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'connection';
		switch ( $active_tab ) {
			case 'design-system':
				$this->design_system_admin->enqueue_assets();
				break;
			case 'patterns':
				$this->patterns_admin->enqueue_assets();
				break;
			case 'diagnostics':
				$this->diagnostics_admin->enqueue_assets();
				break;
		}
	}

	/**
	 * Render the version info card.
	 *
	 * Build JSON config snippets for all supported MCP clients.
	 *
	 * Each client differs only in wrapper key, URL key, and optional type field.
	 * This centralizes config generation so changes to the endpoint structure
	 * only need to be made in one place.
	 *
	 * @param string $mcp_url The MCP endpoint URL.
	 * @return array<string, string> Client key => JSON config string.
	 */
	private function build_client_configs( string $mcp_url ): array {
		$auth_header = [ 'Authorization' => 'Basic YOUR_BASE64_AUTH_STRING' ];
		$json_flags  = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

		$definitions = [
			'claude'         => [ 'wrapper' => 'mcpServers', 'url_key' => 'url',     'type' => 'http' ],
			'claude-desktop' => [ 'wrapper' => 'mcpServers', 'url_key' => 'url' ],
			'gemini'         => [ 'wrapper' => 'mcpServers', 'url_key' => 'httpUrl' ],
			'cursor'         => [ 'wrapper' => 'mcpServers', 'url_key' => 'url' ],
			'vscode'         => [ 'wrapper' => 'servers',    'url_key' => 'url',     'type' => 'http' ],
			'augment'        => [ 'wrapper' => 'mcpServers', 'url_key' => 'url' ],
			'qwen'           => [ 'wrapper' => 'mcpServers', 'url_key' => 'url' ],
		];

		$configs = [];
		foreach ( $definitions as $key => $def ) {
			$server = [
				$def['url_key'] => $mcp_url,
				'headers'       => $auth_header,
			];
			if ( isset( $def['type'] ) ) {
				$server = array_merge( [ 'type' => $def['type'] ], $server );
			}
			$configs[ $key ] = json_encode(
				[ $def['wrapper'] => [ 'bricks-mcp' => $server ] ],
				$json_flags
			);
		}

		return $configs;
	}

	/**
	 * Render Active Connections section showing Bricks MCP Application Passwords.
	 *
	 * @return void
	 */
	private function render_active_connections(): void {
		$passwords = $this->get_bricks_mcp_app_passwords();

		?>
		<div class="bricks-mcp-config-section bwm-connections">
			<h3><?php esc_html_e( 'Active Connections', 'bricks-mcp' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Application Passwords created by this plugin for AI tool connections.', 'bricks-mcp' ); ?></p>

			<?php if ( empty( $passwords ) ) : ?>
				<p class="bwm-connections__empty"><?php esc_html_e( 'No connections yet. Use a "Generate Config" button above to create one.', 'bricks-mcp' ); ?></p>
			<?php else : ?>
				<table class="widefat striped bwm-connections__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'bricks-mcp' ); ?></th>
							<th><?php esc_html_e( 'Created', 'bricks-mcp' ); ?></th>
							<th><?php esc_html_e( 'Last Used', 'bricks-mcp' ); ?></th>
							<th><?php esc_html_e( 'Last IP', 'bricks-mcp' ); ?></th>
							<th style="width:80px"><?php esc_html_e( 'Actions', 'bricks-mcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $passwords as $pw ) : ?>
						<tr data-uuid="<?php echo esc_attr( $pw['uuid'] ?? '' ); ?>">
							<td><?php echo esc_html( $pw['name'] ?? '' ); ?></td>
							<td><?php echo esc_html( ! empty( $pw['created'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $pw['created'] ) : '—' ); ?></td>
							<td><?php echo esc_html( ! empty( $pw['last_used'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $pw['last_used'] ) : __( 'Never', 'bricks-mcp' ) ); ?></td>
							<td><?php echo esc_html( ! empty( $pw['last_ip'] ) ? $pw['last_ip'] : '—' ); ?></td>
							<td><button type="button" class="button button-small bwm-revoke-password" data-uuid="<?php echo esc_attr( $pw['uuid'] ?? '' ); ?>"><?php esc_html_e( 'Revoke', 'bricks-mcp' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
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
			$mcp_url = trailingslashit( $custom_base ) . 'wp-json/bricks-wp-mcp/v1/mcp';
		} else {
			$mcp_url = rest_url( 'bricks-wp-mcp/v1/mcp' );
		}

		// Build client config snippets from definitions.
		$configs = $this->build_client_configs( $mcp_url );

		$claude_config         = $configs['claude'];
		$claude_desktop_config = $configs['claude-desktop'];
		$gemini_config         = $configs['gemini'];
		$cursor_config         = $configs['cursor'];
		$vscode_config         = $configs['vscode'];
		$augment_config        = $configs['augment'];
		$qwen_config           = $configs['qwen'];

		?>
		<div class="bricks-mcp-config-section">
			<h2><?php esc_html_e( 'Quick Setup', 'bricks-mcp' ); ?></h2>
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
			</div>

			</div>
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

		if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
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
			$mcp_url = trailingslashit( $custom_base ) . 'wp-json/bricks-wp-mcp/v1/mcp';
		} else {
			$mcp_url = rest_url( 'bricks-wp-mcp/v1/mcp' );
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

		// Build Cursor CLI one-liner.
		$cursor_command = sprintf(
			'cursor mcp add bricks-mcp %s --header "Authorization: Basic %s"',
			$mcp_url,
			$auth_string
		);

		// Build Qwen CLI one-liner.
		$qwen_command = sprintf(
			'qwen mcp add bricks-mcp --url %s --header "Authorization: Basic %s"',
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
				'cursor_command'        => $cursor_command,
				'cursor_config'         => $cursor_config,
				'vscode_config'         => $vscode_config,
				'augment_config'        => $augment_config,
				'qwen_command'          => $qwen_command,
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

		if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ), 403 );
		}

		$note_id = isset( $_POST['note_id'] ) ? sanitize_text_field( wp_unslash( $_POST['note_id'] ) ) : '';
		if ( empty( $note_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing note ID.', 'bricks-mcp' ) ) );
		}

		$notes    = get_option( BricksCore::OPTION_NOTES, [] );
		$filtered = array_values( array_filter( $notes, static fn( $n ) => ( $n['id'] ?? '' ) !== $note_id ) );

		if ( count( $filtered ) === count( $notes ) ) {
			wp_send_json_error( __( 'Note not found.', 'bricks-mcp' ) );
		}

		update_option( BricksCore::OPTION_NOTES, $filtered, false );
		wp_send_json_success( true );
	}

	/**
	 * AJAX handler: Add an AI note.
	 *
	 * @return void
	 */
	public function ajax_add_note(): void {
		check_ajax_referer( 'bricks_mcp_notes', '_wpnonce' );

		if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ), 403 );
		}

		$text = isset( $_POST['text'] ) ? sanitize_text_field( wp_unslash( $_POST['text'] ) ) : '';
		if ( empty( $text ) ) {
			wp_send_json_error( array( 'message' => __( 'Note text is required.', 'bricks-mcp' ) ) );
		}

		$notes = get_option( BricksCore::OPTION_NOTES, [] );
		if ( ! is_array( $notes ) ) {
			$notes = [];
		}

		$id   = 'note_' . bin2hex( random_bytes( 4 ) );
		$note = [
			'id'         => $id,
			'text'       => $text,
			'created_at' => current_time( 'mysql' ),
		];

		$notes[] = $note;
		update_option( BricksCore::OPTION_NOTES, $notes, false );
		wp_send_json_success( $note );
	}

	/**
	 * AJAX handler: Revoke an Application Password.
	 *
	 * @return void
	 */
	public function ajax_revoke_app_password(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );

		if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		if ( empty( $uuid ) ) {
			wp_send_json_error( [ 'message' => __( 'Missing password UUID.', 'bricks-mcp' ) ] );
		}

		$deleted = \WP_Application_Passwords::delete_application_password( get_current_user_id(), $uuid );
		if ( is_wp_error( $deleted ) ) {
			wp_send_json_error( [ 'message' => $deleted->get_error_message() ] );
		}

		wp_send_json_success( [ 'message' => __( 'Application Password revoked.', 'bricks-mcp' ) ] );
	}

	/**
	 * Render the structured Design Rules brief form.
	 *
	 * Machine-readable fields that feed directly into the build pipeline.
	 * Saved as wp_option BricksCore::OPTION_STRUCTURED_BRIEF.
	 *
	 * @return void
	 */
	private function render_structured_brief(): void {
		// Handle save.
		if (
			isset( $_POST['bricks_mcp_structured_brief_nonce'] )
			&& wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['bricks_mcp_structured_brief_nonce'] ) ),
				BricksCore::OPTION_STRUCTURED_BRIEF
			)
		) {
			$fields = [
				'dark_bg_color',
				'dark_text_color',
				'dark_subtitle_color',
				'light_bg_color',
				'light_alt_bg_color',
				'card_radius',
				'card_border_color',
				'card_padding',
				'section_header_align',
				'btn_primary_class',
				'btn_secondary_class',
				'eyebrow_class',
				'icon_library',
				'grid_gap',
				'content_gap',
				'container_gap',
			];

			$data = [];
			foreach ( $fields as $field ) {
				$data[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			}

			update_option( BricksCore::OPTION_STRUCTURED_BRIEF, $data );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Design Rules saved.', 'bricks-mcp' ) . '</p></div>';
		}

		$values        = get_option( BricksCore::OPTION_STRUCTURED_BRIEF, [] );
		$values        = is_array( $values ) ? $values : [];
		$global_classes = get_option( BricksCore::OPTION_GLOBAL_CLASSES, [] );
		$global_classes = is_array( $global_classes ) ? $global_classes : [];
		?>
		<div class="bricks-mcp-config-section">
			<h3><?php esc_html_e( 'Design Rules', 'bricks-mcp' ); ?>
				<span style="font-size: 12px; font-weight: 400; color: var(--bwm-gray-500); margin-left: 8px;"><?php esc_html_e( 'Advanced', 'bricks-mcp' ); ?></span>
			</h3>
			<p class="description"><?php esc_html_e( 'Machine-readable rules that control how AI builds sections. Auto-filled when you apply the Design System. Override specific values below if needed.', 'bricks-mcp' ); ?></p>

			<p style="margin-bottom: 12px;">
				<button type="button" class="button button-secondary" id="bricks-mcp-toggle-design-rules" onclick="var c=document.getElementById('bricks-mcp-design-rules-body');var b=this;if(c.style.display==='none'){c.style.display='';b.textContent='<?php echo esc_js( __( 'Hide Design Rules', 'bricks-mcp' ) ); ?>';}else{c.style.display='none';b.textContent='<?php echo esc_js( __( 'Show Design Rules', 'bricks-mcp' ) ); ?>';}">
					<?php esc_html_e( 'Show Design Rules', 'bricks-mcp' ); ?>
				</button>
			</p>

			<div id="bricks-mcp-design-rules-body" style="display: none;">

			<p style="margin-bottom: 20px;">
				<button type="button" class="button button-secondary" id="bricks-mcp-parse-brief-btn">
					<?php esc_html_e( 'Parse from Text', 'bricks-mcp' ); ?>
				</button>
			</p>

			<!-- Parse from Text modal -->
			<div id="bricks-mcp-parse-brief-modal" style="display:none;">
				<div class="bwm-modal-backdrop" id="bricks-mcp-parse-brief-backdrop"></div>
				<div class="bwm-modal-content">
					<div class="bwm-modal-header">
						<h3><?php esc_html_e( 'Parse Design Brief from Text', 'bricks-mcp' ); ?></h3>
						<button type="button" class="bwm-modal-close" id="bricks-mcp-parse-brief-close">&times;</button>
					</div>
					<p class="description"><?php esc_html_e( 'Paste your free-text design brief below. An AI assistant will parse it into structured fields.', 'bricks-mcp' ); ?></p>
					<textarea id="bricks-mcp-parse-brief-text" rows="10" class="large-text" placeholder="<?php esc_attr_e( "Paste your design brief text here...", 'bricks-mcp' ); ?>"></textarea>
					<p style="margin-top: 12px;">
						<button type="button" class="button button-primary" id="bricks-mcp-parse-brief-submit">
							<?php esc_html_e( 'Parse Brief', 'bricks-mcp' ); ?>
						</button>
						<span class="spinner" id="bricks-mcp-parse-brief-spinner" style="float:none;"></span>
					</p>
					<div id="bricks-mcp-parse-brief-result" style="margin-top: 12px;"></div>
				</div>
			</div>

			<form method="post">
				<?php wp_nonce_field( BricksCore::OPTION_STRUCTURED_BRIEF, 'bricks_mcp_structured_brief_nonce' ); ?>

				<h4><?php esc_html_e( 'Dark Sections', 'bricks-mcp' ); ?></h4>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dark_bg_color"><?php esc_html_e( 'Background Color', 'bricks-mcp' ); ?></label></th>
						<td><input type="text" id="dark_bg_color" name="dark_bg_color" value="<?php echo esc_attr( $values['dark_bg_color'] ?? '' ); ?>" class="regular-text" placeholder="var(--base-ultra-dark)"></td>
					</tr>
					<tr>
						<th scope="row"><label for="dark_text_color"><?php esc_html_e( 'Text Color', 'bricks-mcp' ); ?></label></th>
						<td><input type="text" id="dark_text_color" name="dark_text_color" value="<?php echo esc_attr( $values['dark_text_color'] ?? '' ); ?>" class="regular-text" placeholder="var(--white)"></td>
					</tr>
					<tr>
						<th scope="row"><label for="dark_subtitle_color"><?php esc_html_e( 'Subtitle Color', 'bricks-mcp' ); ?></label></th>
						<td><input type="text" id="dark_subtitle_color" name="dark_subtitle_color" value="<?php echo esc_attr( $values['dark_subtitle_color'] ?? '' ); ?>" class="regular-text" placeholder="var(--white-trans-70)"></td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Light Sections', 'bricks-mcp' ); ?></h4>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="light_bg_color"><?php esc_html_e( 'Background Color', 'bricks-mcp' ); ?></label></th>
						<td><input type="text" id="light_bg_color" name="light_bg_color" value="<?php echo esc_attr( $values['light_bg_color'] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Leave empty for white', 'bricks-mcp' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="light_alt_bg_color"><?php esc_html_e( 'Alternating BG', 'bricks-mcp' ); ?></label></th>
						<td><input type="text" id="light_alt_bg_color" name="light_alt_bg_color" value="<?php echo esc_attr( $values['light_alt_bg_color'] ?? '' ); ?>" class="regular-text" placeholder="var(--base-ultra-light)"></td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Cards', 'bricks-mcp' ); ?></h4>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="card_radius"><?php esc_html_e( 'Border Radius', 'bricks-mcp' ); ?></label></th>
						<td><input type="text" id="card_radius" name="card_radius" value="<?php echo esc_attr( $values['card_radius'] ?? '' ); ?>" class="regular-text" placeholder="var(--radius)"></td>
					</tr>
					<tr>
						<th scope="row"><label for="card_border_color"><?php esc_html_e( 'Border Color', 'bricks-mcp' ); ?></label></th>
						<td><input type="text" id="card_border_color" name="card_border_color" value="<?php echo esc_attr( $values['card_border_color'] ?? '' ); ?>" class="regular-text" placeholder="var(--border-color)"></td>
					</tr>
					<tr>
						<th scope="row"><label for="card_padding"><?php esc_html_e( 'Padding', 'bricks-mcp' ); ?></label></th>
						<td><input type="text" id="card_padding" name="card_padding" value="<?php echo esc_attr( $values['card_padding'] ?? '' ); ?>" class="regular-text" placeholder="var(--space-l)"></td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Section Headers', 'bricks-mcp' ); ?></h4>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="section_header_align"><?php esc_html_e( 'Alignment', 'bricks-mcp' ); ?></label></th>
						<td>
							<select id="section_header_align" name="section_header_align">
								<option value="center" <?php selected( $values['section_header_align'] ?? '', 'center' ); ?>><?php esc_html_e( 'center', 'bricks-mcp' ); ?></option>
								<option value="left" <?php selected( $values['section_header_align'] ?? '', 'left' ); ?>><?php esc_html_e( 'left', 'bricks-mcp' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Buttons & Classes', 'bricks-mcp' ); ?></h4>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="btn_primary_class"><?php esc_html_e( 'Primary Button Class', 'bricks-mcp' ); ?></label></th>
						<td>
							<select id="btn_primary_class" name="btn_primary_class">
								<option value=""><?php esc_html_e( '— Auto-detect —', 'bricks-mcp' ); ?></option>
								<?php foreach ( $global_classes as $gc ) :
									$class_name = $gc['name'] ?? '';
									if ( '' === $class_name ) { continue; }
								?>
									<option value="<?php echo esc_attr( $class_name ); ?>" <?php selected( $values['btn_primary_class'] ?? '', $class_name ); ?>><?php echo esc_html( $class_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="btn_secondary_class"><?php esc_html_e( 'Secondary Button Class', 'bricks-mcp' ); ?></label></th>
						<td>
							<select id="btn_secondary_class" name="btn_secondary_class">
								<option value=""><?php esc_html_e( '— Auto-detect —', 'bricks-mcp' ); ?></option>
								<?php foreach ( $global_classes as $gc ) :
									$class_name = $gc['name'] ?? '';
									if ( '' === $class_name ) { continue; }
								?>
									<option value="<?php echo esc_attr( $class_name ); ?>" <?php selected( $values['btn_secondary_class'] ?? '', $class_name ); ?>><?php echo esc_html( $class_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eyebrow_class"><?php esc_html_e( 'Eyebrow/Tagline Class', 'bricks-mcp' ); ?></label></th>
						<td>
							<select id="eyebrow_class" name="eyebrow_class">
								<option value=""><?php esc_html_e( '— Auto-detect —', 'bricks-mcp' ); ?></option>
								<?php foreach ( $global_classes as $gc ) :
									$class_name = $gc['name'] ?? '';
									if ( '' === $class_name ) { continue; }
								?>
									<option value="<?php echo esc_attr( $class_name ); ?>" <?php selected( $values['eyebrow_class'] ?? '', $class_name ); ?>><?php echo esc_html( $class_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Icons', 'bricks-mcp' ); ?></h4>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="icon_library"><?php esc_html_e( 'Icon Library', 'bricks-mcp' ); ?></label></th>
						<td>
							<select id="icon_library" name="icon_library">
								<option value="themify" <?php selected( $values['icon_library'] ?? '', 'themify' ); ?>><?php esc_html_e( 'themify', 'bricks-mcp' ); ?></option>
								<option value="fontawesomeSolid" <?php selected( $values['icon_library'] ?? '', 'fontawesomeSolid' ); ?>><?php esc_html_e( 'fontawesomeSolid', 'bricks-mcp' ); ?></option>
								<option value="fontawesomeRegular" <?php selected( $values['icon_library'] ?? '', 'fontawesomeRegular' ); ?>><?php esc_html_e( 'fontawesomeRegular', 'bricks-mcp' ); ?></option>
								<option value="ionicons" <?php selected( $values['icon_library'] ?? '', 'ionicons' ); ?>><?php esc_html_e( 'ionicons', 'bricks-mcp' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<h4><?php esc_html_e( 'Spacing', 'bricks-mcp' ); ?></h4>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="grid_gap"><?php esc_html_e( 'Grid Gap', 'bricks-mcp' ); ?></label></th>
						<td><input type="text" id="grid_gap" name="grid_gap" value="<?php echo esc_attr( $values['grid_gap'] ?? '' ); ?>" class="regular-text" placeholder="var(--grid-gap)"></td>
					</tr>
					<tr>
						<th scope="row"><label for="content_gap"><?php esc_html_e( 'Content Gap', 'bricks-mcp' ); ?></label></th>
						<td><input type="text" id="content_gap" name="content_gap" value="<?php echo esc_attr( $values['content_gap'] ?? '' ); ?>" class="regular-text" placeholder="var(--content-gap)"></td>
					</tr>
					<tr>
						<th scope="row"><label for="container_gap"><?php esc_html_e( 'Container Gap', 'bricks-mcp' ); ?></label></th>
						<td><input type="text" id="container_gap" name="container_gap" value="<?php echo esc_attr( $values['container_gap'] ?? '' ); ?>" class="regular-text" placeholder="var(--container-gap)"></td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Design Rules', 'bricks-mcp' ) ); ?>
			</form>

			</div><!-- #bricks-mcp-design-rules-body -->
		</div>

		<script>
		(function() {
			var btn   = document.getElementById('bricks-mcp-parse-brief-btn');
			var modal = document.getElementById('bricks-mcp-parse-brief-modal');
			var close = document.getElementById('bricks-mcp-parse-brief-close');
			var back  = document.getElementById('bricks-mcp-parse-brief-backdrop');
			var submit = document.getElementById('bricks-mcp-parse-brief-submit');
			var spinner = document.getElementById('bricks-mcp-parse-brief-spinner');
			var result  = document.getElementById('bricks-mcp-parse-brief-result');

			if (!btn || !modal) return;

			function openModal()  { modal.style.display = ''; }
			function closeModal() { modal.style.display = 'none'; result.innerHTML = ''; }

			btn.addEventListener('click', openModal);
			close.addEventListener('click', closeModal);
			back.addEventListener('click', closeModal);

			submit.addEventListener('click', function() {
				var text = document.getElementById('bricks-mcp-parse-brief-text').value.trim();
				if (!text) return;

				spinner.classList.add('is-active');
				result.innerHTML = '';

				var fd = new FormData();
				fd.append('action', 'bricks_mcp_parse_brief');
				fd.append('nonce', typeof bricksMcpNotes !== 'undefined' ? bricksMcpNotes.nonce : '');
				fd.append('text', text);

				fetch(ajaxurl, { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(resp) {
						spinner.classList.remove('is-active');
						if (resp.success) {
							result.innerHTML = '<div class="notice notice-success"><p>' + resp.data.message + '</p></div>';
						} else {
							result.innerHTML = '<div class="notice notice-error"><p>' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error.') + '</p></div>';
						}
					})
					.catch(function() {
						spinner.classList.remove('is-active');
						result.innerHTML = '<div class="notice notice-error"><p>Request failed.</p></div>';
					});
			});
		})();
		</script>
		<?php
	}

	/**
	 * AJAX handler: Parse a free-text brief into structured fields.
	 *
	 * Placeholder — returns an error directing user to connect an AI assistant.
	 *
	 * @return void
	 */
	public function ajax_parse_brief(): void {
		check_ajax_referer( 'bricks_mcp_notes', 'nonce' );

		if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		wp_send_json_error( [
			'message' => __( 'Connect an AI assistant and use the MCP design_brief:parse action.', 'bricks-mcp' ),
		] );
	}

}
