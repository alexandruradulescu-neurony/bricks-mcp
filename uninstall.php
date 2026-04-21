<?php
/**
 * Plugin uninstallation handler.
 *
 * This file is executed when the plugin is uninstalled via the WordPress admin.
 * It removes all plugin data from the database.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// Prevent direct access and ensure this is an uninstall request.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete all plugin options.
 *
 * @return void
 */
function bricks_mcp_delete_options(): void {
	// NOTE: These literals must stay in sync with the BricksCore::OPTION_* class
	// constants. The uninstall handler runs in a bare WordPress context where the
	// plugin autoloader is not available, so referencing BricksCore::OPTION_* here
	// would produce a fatal on uninstall. When you add a new OPTION_* constant to
	// BricksCore, mirror the literal value here by hand — there is no other path.
	$options = [
		'bricks_mcp_settings',              // BricksCore::OPTION_SETTINGS
		'bricks_mcp_version',               // BricksCore::OPTION_VERSION
		'bricks_mcp_activated_at',          // BricksCore::OPTION_ACTIVATED_AT
		'bricks_mcp_custom_patterns',       // BricksCore::OPTION_CUSTOM_PATTERNS
		'bricks_mcp_hidden_patterns',       // BricksCore::OPTION_HIDDEN_PATTERNS
		'bricks_mcp_pattern_categories',    // BricksCore::OPTION_PATTERN_CATEGORIES
		'bricks_mcp_patterns_migrated',     // BricksCore::OPTION_PATTERNS_MIGRATED
		'bricks_mcp_briefs',                // BricksCore::OPTION_BRIEFS
		'bricks_mcp_notes',                 // BricksCore::OPTION_NOTES
		'bricks_mcp_design_system_config',  // BricksCore::OPTION_DESIGN_SYSTEM_CONFIG
		'bricks_mcp_ds_last_applied',       // BricksCore::OPTION_DS_LAST_APPLIED
		'bricks_mcp_structured_brief',      // BricksCore::OPTION_STRUCTURED_BRIEF
		'bricks_mcp_db_version',            // BricksCore::OPTION_DB_VERSION
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}
}

/**
 * Delete all plugin transients.
 *
 * @return void
 */
function bricks_mcp_delete_transients(): void {
	global $wpdb;

	// Delete all transients with the plugin prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_bricks_mcp_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bricks_mcp_' ) . '%'
		)
	);
}

/**
 * Delete all plugin user meta.
 *
 * @return void
 */
function bricks_mcp_delete_user_meta(): void {
	global $wpdb;

	// Delete all user meta with the plugin prefix.
	// Use $wpdb->usermeta (not $wpdb->prefix . 'usermeta') — on multisite the
	// usermeta table is shared across sites and lives at {base_prefix}usermeta,
	// not {site_prefix}usermeta. Concatenating $wpdb->prefix yields a
	// nonexistent table and the DELETE silently no-ops.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'bricks_mcp_' ) . '%'
		)
	);
}

// Run cleanup.
bricks_mcp_delete_options();
bricks_mcp_delete_transients();
bricks_mcp_delete_user_meta();

// Flush rewrite rules.
flush_rewrite_rules();
