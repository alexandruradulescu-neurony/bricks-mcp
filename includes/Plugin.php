<?php
/**
 * Main plugin class.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin class.
 *
 * The main orchestrator class for the plugin.
 * Uses singleton pattern to ensure only one instance runs.
 */
final class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * MCP Server instance.
	 *
	 * @var MCP\Server|null
	 */
	private ?MCP\Server $mcp_server = null;

	/**
	 * Admin settings instance.
	 *
	 * @var Admin\Settings|null
	 */
	private ?Admin\Settings $admin_settings = null;

	/**
	 * Update checker instance.
	 *
	 * @var Updates\UpdateChecker|null
	 */
	private ?Updates\UpdateChecker $update_checker = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self The plugin instance.
	 */
	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation.
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Prevent cloning.
	 *
	 * Symmetric with __wakeup(): cloning a singleton via ReflectionClass would
	 * otherwise silently produce a second instance. Throw to keep the invariant.
	 *
	 * @throws \Exception When attempting to clone the singleton.
	 * @return void
	 */
	private function __clone() {
		throw new \Exception( 'Cannot clone singleton.' );
	}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \Exception When attempting to unserialize.
	 * @return void
	 */
	public function __wakeup(): void {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	private function init(): void {
		// Run migrations only when plugin version changes.
		// Previously the version was written unconditionally AFTER migrations; if a
		// migration threw an uncaught Throwable the version bump happened anyway,
		// leaving the plugin in a permanently-skipped half-migrated state on the
		// next page load. Now each migration step is wrapped in try/catch, and the
		// version is only bumped when ALL steps succeed.
		$stored_version = get_option( MCP\Services\BricksCore::OPTION_DB_VERSION, '' );
		if ( $stored_version !== BRICKS_MCP_VERSION ) {
			$migration_ok = true;
			try {
				$this->migrate_settings();
			} catch ( \Throwable $e ) {
				$migration_ok = false;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'BricksMCP migrate_settings failed: ' . $e->getMessage() );
			}
			try {
				MCP\Services\DesignPatternService::migrate_plugin_patterns();
			} catch ( \Throwable $e ) {
				$migration_ok = false;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'BricksMCP migrate_plugin_patterns failed: ' . $e->getMessage() );
			}
			if ( $migration_ok ) {
				update_option( MCP\Services\BricksCore::OPTION_DB_VERSION, BRICKS_MCP_VERSION, true );
			}
		}

		// Initialize internationalization.
		$this->init_i18n();

		// Initialize MCP server.
		$this->init_mcp_server();

		// Initialize admin functionality only in admin context (or cron for update checks).
		if ( is_admin() || wp_doing_cron() ) {
			$this->init_admin();
		}

		// Register hooks.
		$this->register_hooks();
	}

	/**
	 * Initialize internationalization.
	 *
	 * @return void
	 */
	private function init_i18n(): void {
		$i18n = new I18n();
		$i18n->init();
	}

	/**
	 * Initialize MCP server.
	 *
	 * @return void
	 */
	private function init_mcp_server(): void {
		$this->mcp_server = new MCP\Server();
		$this->mcp_server->init();
	}

	/**
	 * Initialize admin functionality.
	 *
	 * @return void
	 */
	private function init_admin(): void {
		$this->admin_settings = new Admin\Settings();
		$this->admin_settings->init();

		// Initialize update checker (unconditional — fires on admin and cron).
		$this->update_checker = new Updates\UpdateChecker();
		$this->update_checker->init();

		$site_health = new Admin\SiteHealth();
		$site_health->init();
	}

	/**
	 * Migrate stored settings to strip orphaned keys from previous versions.
	 *
	 * Removes rate_limit, rate_limit_window, and allowed_endpoints keys
	 * that are no longer used. No-op after first run (keys already removed).
	 *
	 * @return void
	 */
	private function migrate_settings(): void {
		$settings = get_option( MCP\Services\BricksCore::OPTION_SETTINGS, [] );

		if ( ! is_array( $settings ) ) {
			return;
		}

		$dirty = false;

		foreach ( [ 'rate_limit', 'rate_limit_window', 'allowed_endpoints' ] as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				unset( $settings[ $key ] );
				$dirty = true;
			}
		}

		if ( $dirty ) {
			// Check update_option return — false on failure (DB issue, filter rejection).
			// Surface via exception so the outer try/catch in init() keeps the db_version
			// un-bumped and migration retries next load.
			// Explicit autoload=true matches Activator::set_default_options() — both writers
			// must agree or the option silently flips out of the autoload cache, hammering
			// the DB on every request.
			$ok = update_option( MCP\Services\BricksCore::OPTION_SETTINGS, $settings, true );
			if ( false === $ok ) {
				throw new \RuntimeException( 'migrate_settings: update_option returned false; retry on next load.' );
			}
		}
	}

	/**
	 * Register plugin hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// Add plugin action links.
		add_filter(
			'plugin_action_links_' . BRICKS_MCP_PLUGIN_BASENAME,
			[ $this, 'add_action_links' ]
		);
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array<string, string> $links Existing action links.
	 * @return array<string, string> Modified action links.
	 */
	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=bricks-mcp' ) ),
			esc_html__( 'Settings', 'bricks-mcp' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Get the MCP server instance.
	 *
	 * @return MCP\Server|null The MCP server instance.
	 */
	public function get_mcp_server(): ?MCP\Server {
		return $this->mcp_server;
	}

	/**
	 * Get the update checker instance.
	 *
	 * @return Updates\UpdateChecker|null The update checker instance.
	 */
	public function get_update_checker(): ?Updates\UpdateChecker {
		return $this->update_checker;
	}

	/**
	 * Get the plugin version.
	 *
	 * @return string The plugin version.
	 */
	public function get_version(): string {
		return BRICKS_MCP_VERSION;
	}
}
