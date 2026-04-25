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
	 * Orphaned settings keys from previous plugin versions that migrate_settings()
	 * strips on version bump. Kept as a constant so future developers can see the
	 * deprecation history at a glance and not re-introduce them.
	 *
	 * @var array<int, string>
	 */
	private const LEGACY_SETTING_KEYS = [ 'rate_limit', 'rate_limit_window', 'allowed_endpoints' ];

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
				$this->maybe_wipe_v1_patterns();
			} catch ( \Throwable $e ) {
				$migration_ok = false;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'BricksMCP maybe_wipe_v1_patterns failed: ' . $e->getMessage() );
			}
			try {
				$this->maybe_apply_v3_28_pattern_metadata();
			} catch ( \Throwable $e ) {
				$migration_ok = false;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'BricksMCP maybe_apply_v3_28_pattern_metadata failed: ' . $e->getMessage() );
			}
			try {
				$this->maybe_normalize_legacy_class_shapes();
			} catch ( \Throwable $e ) {
				$migration_ok = false;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'BricksMCP maybe_normalize_legacy_class_shapes failed: ' . $e->getMessage() );
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
	}

	/**
	 * Pattern System v2 — one-time wipe of legacy pattern options.
	 *
	 * Guarded by OPTION_PATTERNS_V2_WIPED so it only fires once per install.
	 * Runs on version-bump from Plugin::init() (covers zip-upload upgrades
	 * that don't trigger activation hooks).
	 */
	private function maybe_wipe_v1_patterns(): void {
		$flag = MCP\Services\BricksCore::OPTION_PATTERNS_V2_WIPED;
		if ( get_option( $flag ) ) {
			return;
		}
		delete_option( 'bricks_mcp_custom_patterns' );
		delete_option( 'bricks_mcp_patterns_migrated' );
		update_option( $flag, time(), false );
		set_transient( 'bricks_mcp_show_patterns_v2_notice', 1, DAY_IN_SECONDS );
	}

	/**
	 * v3.28.0 — backfill BEM metadata on all stored patterns.
	 *
	 * Guarded by OPTION_PATTERNS_V3_28_METADATA_APPLIED so it runs once per install.
	 * Computes bem_purity, non_bem_classes, bem_migration_hints for each pattern.
	 */
	private function maybe_apply_v3_28_pattern_metadata(): void {
		$flag = MCP\Services\BricksCore::OPTION_PATTERNS_V3_28_METADATA_APPLIED;
		if ( get_option( $flag ) ) {
			return;
		}
		$patterns = get_option( MCP\Services\BricksCore::OPTION_PATTERNS, [] );
		if ( ! is_array( $patterns ) ) {
			update_option( $flag, time(), false );
			return;
		}
		$validator = new MCP\Services\PatternValidator();
		foreach ( $patterns as &$p ) {
			if ( ! is_array( $p ) ) continue;
			$meta                       = $validator->compute_bem_metadata( $p );
			$p['bem_purity']            = $meta['bem_purity'];
			$p['non_bem_classes']       = $meta['non_bem_classes'];
			$p['bem_migration_hints']   = $meta['bem_migration_hints'];
		}
		unset( $p );
		update_option( MCP\Services\BricksCore::OPTION_PATTERNS, $patterns, false );
		update_option( $flag, time(), false );
		set_transient( 'bricks_mcp_show_v3_28_notice', 1, DAY_IN_SECONDS );
	}

	/**
	 * v3.33.7 — re-normalize every global class in the DB through
	 * StyleNormalizationService. Rewrites legacy shapes that don't emit CSS on
	 * Bricks' compiler: camelCase typography keys → kebab, `_background.backgroundColor`
	 * → `_background.color.raw`, scalar `_border.radius` → per-side object. Idempotent.
	 *
	 * Guarded by OPTION_LEGACY_CLASS_SHAPES_MIGRATED so it runs once per install.
	 * Stores migration stats (scanned/rewritten counts) in the flag option value
	 * for later audit. After this runs, existing hero__*, hero-69__*, recruit__*,
	 * mcp-* classes get their silent-fail properties fixed.
	 */
	private function maybe_normalize_legacy_class_shapes(): void {
		$flag = 'bricks_mcp_legacy_class_shapes_migrated';
		if ( get_option( $flag ) ) {
			return;
		}
		$stats = MCP\Services\StyleNormalizationService::migrate_existing_classes();
		update_option( $flag, [
			'ran_at'    => time(),
			'scanned'   => $stats['scanned'],
			'rewritten' => $stats['rewritten'],
		], false );
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

		foreach ( self::LEGACY_SETTING_KEYS as $key ) {
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
