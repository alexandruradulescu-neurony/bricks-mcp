<?php
/**
 * Prerequisite gate service.
 *
 * Tracks which mandatory tool calls have been made per user session
 * and gates content write operations behind them.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PrerequisiteGateService class.
 *
 * Uses a WP transient keyed by user ID to track which prerequisite
 * calls have been made. Flags expire after 30 minutes of inactivity.
 *
 * ## Flags
 *
 * | Flag           | Set when                                            |
 * |----------------|-----------------------------------------------------|
 * | site_context   | BOTH get_site_info AND global_class:list called     |
 *
 * ## Tiers
 *
 * | Tier     | Required flags   | Used by                                                         |
 * |----------|------------------|-----------------------------------------------------------------|
 * | direct   | site_context     | element:update, element:bulk_update, element:add, page:append…  |
 *
 * Note: the 'design' tier was removed in M3 (v3.31.0) when build_from_schema
 * was retired in favour of the two-tier build_structure + populate_content
 * flow. No public tool currently requires more than site_context.
 */
final class PrerequisiteGateService {

	/**
	 * Transient TTL in seconds (30 minutes).
	 */
	private const TTL = 1800;

	/**
	 * Flag constants.
	 */
	public const FLAG_SITE_CONTEXT = 'site_context';

	/**
	 * Valid flag names.
	 */
	private const VALID_FLAGS = [ self::FLAG_SITE_CONTEXT ];

	/**
	 * Tier definitions: which flags are required for each tier.
	 */
	public const TIER_DIRECT = [ self::FLAG_SITE_CONTEXT ];

	/**
	 * Map tier names to their required flags.
	 */
	private const TIER_MAP = [
		'direct' => self::TIER_DIRECT,
	];

	/**
	 * Human-readable tool names for each flag (used in error messages).
	 */
	private const FLAG_TOOL_NAMES = [
		self::FLAG_SITE_CONTEXT => 'get_site_info + global_class:list',
	];

	/**
	 * Get the transient key for the current user.
	 *
	 * @return string
	 */
	private static function transient_key(): string {
		return 'bricks_mcp_prereqs_' . get_current_user_id();
	}

	/**
	 * Set a prerequisite flag.
	 *
	 * @param string $flag One of: site_context.
	 */
	public static function set_flag( string $flag ): void {
		if ( ! in_array( $flag, self::VALID_FLAGS, true ) ) {
			return;
		}

		$flags = get_transient( self::transient_key() );
		if ( ! is_array( $flags ) ) {
			$flags = [];
		}

		$flags[ $flag ] = true;
		set_transient( self::transient_key(), $flags, self::TTL );
	}

	/**
	 * Check if prerequisites are met for a given tier.
	 *
	 * @param string $tier One of 'direct'.
	 * @return true|array{missing: string[], satisfied: string[], missing_tools: string[]}
	 *               True if all required flags set, or array with missing/satisfied details.
	 */
	public static function check( string $tier = 'direct' ): true|array {
		$required_flags = self::TIER_MAP[ $tier ] ?? self::TIER_DIRECT;

		$flags = get_transient( self::transient_key() );
		if ( ! is_array( $flags ) ) {
			$flags = [];
		}

		$missing       = [];
		$satisfied     = [];
		$missing_tools = [];

		foreach ( $required_flags as $flag ) {
			if ( ! empty( $flags[ $flag ] ) ) {
				$satisfied[] = $flag;
			} else {
				$missing[]       = $flag;
				$missing_tools[] = self::FLAG_TOOL_NAMES[ $flag ];
			}
		}

		if ( empty( $missing ) ) {
			return true;
		}

		return [
			'missing'       => $missing,
			'satisfied'     => $satisfied,
			'missing_tools' => $missing_tools,
		];
	}

	/**
	 * Get the required flags for a named tier.
	 *
	 * Public API for callers that want to introspect tier requirements
	 * (e.g. for documentation, error messages, or testing) without depending
	 * on the private TIER_MAP constant.
	 *
	 * @param string $tier One of 'direct'.
	 * @return string[] Required flag names for that tier (empty array on unknown tier).
	 */
	public static function get_required_flags( string $tier ): array {
		return self::TIER_MAP[ $tier ] ?? [];
	}

	/**
	 * Reset all flags (e.g. on session termination).
	 */
	public static function reset(): void {
		delete_transient( self::transient_key() );
	}
}
