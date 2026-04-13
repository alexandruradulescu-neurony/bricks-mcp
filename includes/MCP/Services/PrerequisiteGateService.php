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
 * ## Tier inclusion hierarchy
 *
 * Tiers are strict supersets — each tier includes all flags from the tier above:
 *
 *     direct      ⊂ instructed ⊂ full ⊂ design
 *     site_info   + classes    + variables + (design_discovery, design_plan)
 *
 * | Tier         | Required flags                                                                | Used by                                          |
 * |--------------|-------------------------------------------------------------------------------|--------------------------------------------------|
 * | `direct`     | site_info                                                                      | element:update, element:bulk_update              |
 * | `instructed` | direct + classes                                                               | element:add, element:bulk_add, page:append, etc. |
 * | `full`       | instructed + variables                                                         | propose_design (Phase 1 + Phase 2)               |
 * | `design`     | full + design_discovery + design_plan                                          | build_from_schema                                |
 *
 * The two design flags are set automatically by ProposalService when the AI
 * completes Phase 1 (discovery) and Phase 2 (proposal) of `propose_design`.
 * This means `build_from_schema` is unreachable without first running both
 * phases, enforcing the 4-step pipeline server-side.
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
	 *
	 * Defined as strict supersets so the inclusion chain is provable at a glance.
	 */
	public const TIER_DIRECT     = [ 'site_info' ];
	public const TIER_INSTRUCTED = [ ...self::TIER_DIRECT, 'classes' ];
	public const TIER_FULL       = [ ...self::TIER_INSTRUCTED, 'variables' ];
	public const TIER_DESIGN     = [ ...self::TIER_FULL, 'design_discovery', 'design_plan' ];

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
	 * Get the required flags for a named tier.
	 *
	 * Public API for callers that want to introspect tier requirements
	 * (e.g. for documentation, error messages, or testing) without depending
	 * on the private TIER_MAP constant.
	 *
	 * @param string $tier One of 'direct', 'instructed', 'full', 'design'.
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
