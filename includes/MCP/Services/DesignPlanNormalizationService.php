<?php
/**
 * Design plan role-key utilities.
 *
 * v5.1: trimmed from ~407 LOC to ~30. The heavy instance normalize() method
 * was vision-pipeline scaffolding (cleaned AI design plans coming back from
 * the from_image flow). With vision removed, the only callers were
 * ProposalService's create_proposal() pass and a handful of static-helper
 * uses across handlers.
 *
 * What stays: two small static helpers (`normalize_role_key`,
 * `infer_semantic_component_role`) used in 8 callsites for role canonicalization
 * and semantic role inference. Anything more involved is the AI's problem.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DesignPlanNormalizationService {

	/**
	 * Normalize a content/design role key into snake_case.
	 *
	 * "heading.title" / "heading-title" / "heading/title" / "heading TITLE"
	 * → "heading_title". Used to match content_map keys against design_plan
	 * roles and pattern role labels.
	 */
	public static function normalize_role_key( string $role ): string {
		$role = strtolower( trim( $role ) );
		if ( '' === $role ) {
			return '';
		}

		$role = str_replace( [ '>', '/', '.', '-' ], '_', $role );
		$role = preg_replace( '/[^a-z0-9_]+/', '_', $role );
		$role = preg_replace( '/_+/', '_', (string) $role );

		return trim( (string) $role, '_' );
	}

	/**
	 * Best-effort semantic-role guess for a plan/pattern role label.
	 *
	 * Maps human-named roles like "primary_cta", "outline_button",
	 * "subtitle_text", "section_eyebrow" to their semantic component roles
	 * (button.primary, button.secondary, text.subtitle, text.eyebrow, etc.).
	 *
	 * Returns null when no confident match.
	 */
	public static function infer_semantic_component_role( string $role ): ?string {
		$role_lower = self::normalize_role_key( $role );
		if ( '' === $role_lower ) {
			return null;
		}

		if (
			str_contains( $role_lower, 'primary' )
			&& ( str_contains( $role_lower, 'cta' ) || str_contains( $role_lower, 'button' ) || str_contains( $role_lower, 'btn' ) )
		) {
			return 'button.primary';
		}
		if (
			( str_contains( $role_lower, 'secondary' ) || str_contains( $role_lower, 'ghost' ) || str_contains( $role_lower, 'outline' ) )
			&& ( str_contains( $role_lower, 'cta' ) || str_contains( $role_lower, 'button' ) || str_contains( $role_lower, 'btn' ) )
		) {
			return 'button.secondary';
		}
		if ( str_contains( $role_lower, 'subtitle' ) || str_contains( $role_lower, 'sub_title' ) || str_contains( $role_lower, 'description' ) ) {
			return 'text.subtitle';
		}
		if ( str_contains( $role_lower, 'eyebrow' ) || str_contains( $role_lower, 'tagline' ) || str_contains( $role_lower, 'kicker' ) ) {
			return 'text.eyebrow';
		}
		if ( str_contains( $role_lower, 'card' ) ) {
			return str_contains( $role_lower, 'featured' ) ? 'card.featured' : 'card.default';
		}

		return null;
	}
}
