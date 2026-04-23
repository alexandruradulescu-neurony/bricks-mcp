<?php
/**
 * Structural repair pass for under-specified design plans.
 *
 * Inserts only the minimum anchors needed to keep a direct build plausible
 * without patterns or hardcoded site-specific assumptions.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DesignPlanStructureRepairService {

	/**
	 * Element types treated as media anchors.
	 *
	 * @var array<int, string>
	 */
	private const MEDIA_TYPES = [ 'image', 'image-gallery', 'slider-nested', 'carousel', 'video' ];

	/**
	 * @param array<string, mixed> $plan
	 * @return array{design_plan: array<string, mixed>, repair_log: array<int, string>}
	 */
	public function repair( array $plan ): array {
		$elements      = is_array( $plan['elements'] ?? null ) ? array_values( $plan['elements'] ) : [];
		$section_type  = (string) ( $plan['section_type'] ?? 'generic' );
		$layout        = (string) ( $plan['layout'] ?? 'centered' );
		$repair_log    = [];
		$used_roles    = $this->collect_used_roles( $elements );
		$has_patterns  = ! empty( $plan['patterns'] ) && is_array( $plan['patterns'] );

		$has_heading = $this->has_heading_like( $elements, $plan );
		$has_media   = $this->has_media_like( $elements, $plan );
		$has_button  = $this->has_type_like( $elements, $plan, 'button' );
		$has_form    = $this->has_type_like( $elements, $plan, 'form' );
		$has_text    = $this->has_text_like( $elements, $plan );

		if ( 'hero' === $section_type && ! $has_heading ) {
			$elements = $this->insert_after_intro_text(
				$elements,
				[
					'type'         => 'heading',
					'role'         => $this->unique_role( 'main_heading', $used_roles ),
					'content_hint' => 'Main heading for this hero section',
				]
			);
			$repair_log[] = 'Inserted a missing main_heading for the hero section.';
			$has_heading  = true;
		}

		if ( in_array( $section_type, [ 'features', 'pricing', 'testimonials', 'split', 'cta' ], true ) && ! $has_heading ) {
			array_unshift(
				$elements,
				[
					'type'         => 'heading',
					'role'         => $this->unique_role( 'section_heading', $used_roles ),
					'content_hint' => 'Section heading for this ' . $section_type . ' section',
				]
			);
			$repair_log[] = 'Inserted a missing section_heading for the ' . $section_type . ' section.';
			$has_heading  = true;
		}

		if ( 'hero' === $section_type && ! $has_text ) {
			$elements = $this->insert_after_first_heading(
				$elements,
				[
					'type'         => 'text-basic',
					'role'         => $this->unique_role( 'subtitle', $used_roles ),
					'content_hint' => 'Supporting text that explains the main message',
				]
			);
			$repair_log[] = 'Inserted a missing subtitle for the hero section.';
			$has_text     = true;
		}

		if ( 'cta' === $section_type && ! $has_button && ! $has_form ) {
			$elements[]   = [
				'type'         => 'button',
				'role'         => $this->unique_role( 'primary_cta', $used_roles ),
				'content_hint' => 'Primary call to action for this cta section',
			];
			$repair_log[] = 'Inserted a missing primary_cta for the CTA section.';
		}

		if ( str_starts_with( $layout, 'split' ) && ! $has_media && ! $has_patterns ) {
			$elements[]   = [
				'type'         => 'image',
				'role'         => $this->unique_role( 'hero_image', $used_roles ),
				'content_hint' => 'Relevant visual for the split section',
			];
			$repair_log[] = 'Inserted a missing media anchor for the split layout.';
		}

		$plan['elements'] = array_values( $elements );

		return [
			'design_plan' => $plan,
			'repair_log'  => $repair_log,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @param array<string, mixed>             $plan
	 */
	private function has_heading_like( array $elements, array $plan ): bool {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$type = (string) ( $element['type'] ?? '' );
			$role = DesignPlanNormalizationService::normalize_role_key( (string) ( $element['role'] ?? '' ) );
			if ( 'heading' === $type || $this->role_is_heading_like( $role ) ) {
				return true;
			}
		}

		foreach ( (array) ( $plan['patterns'] ?? [] ) as $pattern ) {
			foreach ( (array) ( $pattern['element_structure'] ?? [] ) as $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}
				$type = (string) ( $element['type'] ?? '' );
				$role = DesignPlanNormalizationService::normalize_role_key( (string) ( $element['role'] ?? '' ) );
				if ( 'heading' === $type || $this->role_is_heading_like( $role ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @param array<string, mixed>             $plan
	 */
	private function has_media_like( array $elements, array $plan ): bool {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( in_array( (string) ( $element['type'] ?? '' ), self::MEDIA_TYPES, true ) ) {
				return true;
			}
		}

		foreach ( (array) ( $plan['patterns'] ?? [] ) as $pattern ) {
			foreach ( (array) ( $pattern['element_structure'] ?? [] ) as $element ) {
				if ( is_array( $element ) && in_array( (string) ( $element['type'] ?? '' ), self::MEDIA_TYPES, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @param array<string, mixed>             $plan
	 */
	private function has_text_like( array $elements, array $plan ): bool {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( in_array( (string) ( $element['type'] ?? '' ), [ 'text-basic', 'text' ], true ) ) {
				return true;
			}
		}

		foreach ( (array) ( $plan['patterns'] ?? [] ) as $pattern ) {
			foreach ( (array) ( $pattern['element_structure'] ?? [] ) as $element ) {
				if ( is_array( $element ) && in_array( (string) ( $element['type'] ?? '' ), [ 'text-basic', 'text' ], true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @param array<string, mixed>             $plan
	 */
	private function has_type_like( array $elements, array $plan, string $type_name ): bool {
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && (string) ( $element['type'] ?? '' ) === $type_name ) {
				return true;
			}
		}

		foreach ( (array) ( $plan['patterns'] ?? [] ) as $pattern ) {
			foreach ( (array) ( $pattern['element_structure'] ?? [] ) as $element ) {
				if ( is_array( $element ) && (string) ( $element['type'] ?? '' ) === $type_name ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @return array<int, string>
	 */
	private function collect_used_roles( array $elements ): array {
		$roles = [];
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$role = DesignPlanNormalizationService::normalize_role_key( (string) ( $element['role'] ?? '' ) );
			if ( '' !== $role ) {
				$roles[] = $role;
			}
		}
		return array_values( array_unique( $roles ) );
	}

	/**
	 * @param array<int, string> $used_roles
	 */
	private function unique_role( string $base_role, array &$used_roles ): string {
		$role = DesignPlanNormalizationService::normalize_role_key( $base_role );
		if ( '' === $role ) {
			$role = 'element';
		}
		if ( ! in_array( $role, $used_roles, true ) ) {
			$used_roles[] = $role;
			return $role;
		}

		$suffix = 2;
		while ( in_array( $role . '_' . (string) $suffix, $used_roles, true ) ) {
			$suffix++;
		}
		$resolved     = $role . '_' . (string) $suffix;
		$used_roles[] = $resolved;
		return $resolved;
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @param array<string, mixed>             $new_element
	 * @return array<int, array<string, mixed>>
	 */
	private function insert_after_intro_text( array $elements, array $new_element ): array {
		$offset = 0;
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$role = DesignPlanNormalizationService::normalize_role_key( (string) ( $element['role'] ?? '' ) );
			$type = (string) ( $element['type'] ?? '' );
			if ( in_array( $type, [ 'text-basic', 'text' ], true ) && ( 'eyebrow' === $role || str_contains( $role, 'eyebrow' ) ) ) {
				$offset = $index + 1;
				continue;
			}
			break;
		}

		array_splice( $elements, $offset, 0, [ $new_element ] );
		return $elements;
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @param array<string, mixed>             $new_element
	 * @return array<int, array<string, mixed>>
	 */
	private function insert_after_first_heading( array $elements, array $new_element ): array {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$type = (string) ( $element['type'] ?? '' );
			$role = DesignPlanNormalizationService::normalize_role_key( (string) ( $element['role'] ?? '' ) );
			if ( 'heading' === $type || $this->role_is_heading_like( $role ) ) {
				array_splice( $elements, $index + 1, 0, [ $new_element ] );
				return $elements;
			}
		}

		array_unshift( $elements, $new_element );
		return $elements;
	}

	private function role_is_heading_like( string $role ): bool {
		return 1 === preg_match( '/(^|_)(heading|title)($|_)/', $role );
	}
}
