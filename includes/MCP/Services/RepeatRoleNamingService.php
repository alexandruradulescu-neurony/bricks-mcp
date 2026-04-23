<?php
/**
 * Helpers for naming repeated item roles consistently across proposal/build.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RepeatRoleNamingService {

	/**
	 * Generate a unique role label for one repeated clone.
	 *
	 * Examples:
	 * - feature_card_title -> feature_card_1_title
	 * - tier_price -> tier_2_price
	 * - testimonial_author -> testimonial_3_author
	 * - logo -> logo_4
	 */
	public static function indexed_role( string $role, int $index ): string {
		$normalized = DesignPlanNormalizationService::normalize_role_key( $role );
		if ( '' === $normalized ) {
			return 'item_' . (string) $index;
		}

		if ( 1 === preg_match( '/^(.*)_(title|text|meta|cta|image|icon|author|price|eyebrow|subtitle|description)$/', $normalized, $matches ) ) {
			return $matches[1] . '_' . (string) $index . '_' . $matches[2];
		}

		return $normalized . '_' . (string) $index;
	}

	/**
	 * Build the per-item data key used in schema repeat substitution.
	 */
	public static function role_data_key( string $role ): string {
		return 'role_' . DesignPlanNormalizationService::normalize_role_key( $role );
	}

	/**
	 * Build the `data.*` reference used in schema repeat substitution.
	 */
	public static function role_data_ref( string $role ): string {
		return 'data.' . self::role_data_key( $role );
	}
}
