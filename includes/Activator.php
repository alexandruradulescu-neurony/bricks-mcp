<?php
/**
 * Plugin activation handler.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP;

use BricksMCP\MCP\Services\BricksCore;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activator class.
 *
 * Handles plugin activation tasks.
 */
final class Activator {

	/**
	 * Transient key where activation-check results are stored for admin-notice display.
	 *
	 * Read/deleted by Admin\Settings::render_settings_page() via this constant —
	 * single source of truth so the producer and consumer cannot drift.
	 *
	 * @var string
	 */
	public const ACTIVATION_CHECK_TRANSIENT = 'bricks_mcp_activation_checks';

	/**
	 * TTL for the activation-check transient.
	 *
	 * @var int
	 */
	private const ACTIVATION_CHECK_TTL = HOUR_IN_SECONDS;

	/**
	 * Run activation tasks.
	 *
	 * This method is called when the plugin is activated.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Store activation timestamp.
		if ( ! get_option( BricksCore::OPTION_ACTIVATED_AT ) ) {
			update_option( BricksCore::OPTION_ACTIVATED_AT, time() );
		}

		// Store plugin version.
		update_option( BricksCore::OPTION_VERSION, BRICKS_MCP_VERSION );

		// Set default options if they don't exist.
		self::set_default_options();

		// Run lightweight activation checks and store results for admin notice.
		self::run_activation_checks();

		// Flush rewrite rules for REST API endpoints.
		flush_rewrite_rules();
	}

	/**
	 * Run lightweight activation checks (no HTTP requests).
	 *
	 * Checks 3 conditions via pure PHP function calls and stores results
	 * as a transient for display as an admin notice on the settings page.
	 *
	 * @return void
	 */
	private static function run_activation_checks(): void {
		$results = [];

		// Check 1: Application Passwords available.
		// wp_is_application_passwords_available() has existed since WP 5.6 and the plugin
		// requires WP 6.4+, so the function is always defined here. The function_exists()
		// guard is kept only as a cheap belt-and-braces for unusual hosting overrides.
		if ( function_exists( 'wp_is_application_passwords_available' ) ) {
			$app_pw_available = wp_is_application_passwords_available();
			$results[]        = [
				'id'      => 'app_passwords',
				'label'   => __( 'Application Passwords', 'bricks-mcp' ),
				'status'  => $app_pw_available ? 'pass' : 'fail',
				'message' => $app_pw_available
					? __( 'Application Passwords are available.', 'bricks-mcp' )
					: __( 'Application Passwords are disabled. MCP clients require Application Passwords for authentication.', 'bricks-mcp' ),
			];
		}

		// Check 2: Bricks Builder active.
		$bricks_active = class_exists( '\Bricks\Elements' );
		$results[]     = [
			'id'      => 'bricks_active',
			'label'   => __( 'Bricks Builder', 'bricks-mcp' ),
			'status'  => $bricks_active ? 'pass' : 'fail',
			'message' => $bricks_active
				? __( 'Bricks Builder is active.', 'bricks-mcp' )
				: __( 'Bricks Builder is not active. Bricks-specific MCP tools will be unavailable.', 'bricks-mcp' ),
		];

		// Store results as transient for admin notice display only if issues exist.
		$has_issues = false;
		foreach ( $results as $r ) {
			if ( 'fail' === $r['status'] ) {
				$has_issues = true;
				break;
			}
		}

		if ( $has_issues ) {
			set_transient( self::ACTIVATION_CHECK_TRANSIENT, $results, self::ACTIVATION_CHECK_TTL );
		}
	}

	/**
	 * Set default plugin options.
	 *
	 * @return void
	 */
	private static function set_default_options(): void {
		$defaults = [
			BricksCore::SETTING_ENABLED      => true,
			BricksCore::SETTING_REQUIRE_AUTH => true,
		];

		$existing = get_option( BricksCore::OPTION_SETTINGS, [] );

		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		// Merge defaults with existing settings.
		$settings = array_merge( $defaults, $existing );

		// Explicit autoload=true: Plugin::migrate_settings() also writes this option and
		// must agree on autoload semantics, otherwise a future refactor could silently
		// flip this option off the autoload list and hammer the DB on every request.
		update_option( BricksCore::OPTION_SETTINGS, $settings, true );
	}
}
