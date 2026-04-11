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
 * Supports tiered gating:
 * - 'direct'      → only site_info required (text edits, moves, property changes)
 * - 'instructed'  → site_info + classes required (explicit structure builds)
 * - 'full'        → site_info + classes + variables required (design builds)
 */
final class PrerequisiteGateService {

	/**
	 * Transient TTL in seconds (30 minutes).
	 */
	private const TTL = 1800;

	/**
	 * Valid flag names.
	 */
	private const VALID_FLAGS = [ 'site_info', 'classes', 'variables', 'design_discovery', 'design_plan' ];

	/**
	 * Tier definitions: which flags are required for each tier.
	 */
	public const TIER_DIRECT     = [ 'site_info' ];
	public const TIER_INSTRUCTED = [ 'site_info', 'classes' ];
	public const TIER_FULL       = [ 'site_info', 'classes', 'variables' ];
	public const TIER_DESIGN     = [ 'site_info', 'classes', 'variables', 'design_discovery', 'design_plan' ];

	/**
	 * Map tier names to their required flags.
	 */
	private const TIER_MAP = [
		'direct'     => self::TIER_DIRECT,
		'instructed' => self::TIER_INSTRUCTED,
		'full'       => self::TIER_FULL,
		'design'     => self::TIER_DESIGN,
	];

	/**
	 * Human-readable tool names for each flag (used in error messages).
	 */
	private const FLAG_TOOL_NAMES = [
		'site_info'        => 'get_site_info',
		'classes'          => 'global_class:list',
		'variables'        => 'global_variable:list',
		'design_discovery' => 'propose_design (Phase 1 — call without design_plan)',
		'design_plan'      => 'propose_design (Phase 2 — call WITH design_plan)',
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
	 * @param string $flag One of: site_info, classes, variables.
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
	 * @param string $tier One of 'direct', 'instructed', 'full'. Defaults to 'full' for backward compatibility.
	 * @return true|array{missing: string[], satisfied: string[], missing_tools: string[]}
	 *               True if all required flags set, or array with missing/satisfied details.
	 */
	public static function check( string $tier = 'full' ): true|array {
		$required_flags = self::TIER_MAP[ $tier ] ?? self::TIER_FULL;

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
	 * Reset all flags (e.g. on session termination).
	 */
	public static function reset(): void {
		delete_transient( self::transient_key() );
	}
}
