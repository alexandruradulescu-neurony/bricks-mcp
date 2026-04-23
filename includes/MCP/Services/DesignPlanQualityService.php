<?php
/**
 * Non-blocking quality analysis for design plans.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DesignPlanQualityService {

	/**
	 * Element types treated as media/visual anchors in layout analysis.
	 */
	private const MEDIA_TYPES = [ 'image', 'image-gallery', 'slider-nested', 'carousel', 'video' ];

	/**
	 * Very generic role names that tend to produce weak populate/search behavior.
	 */
	private const GENERIC_ROLE_NAMES = [ 'text', 'heading', 'button', 'image', 'content', 'card', 'item' ];

	/**
	 * @param array<string, mixed> $plan
	 * @return array<int, string>
	 */
	public function analyze( array $plan ): array {
		$warnings = [];

		$section_type = (string) ( $plan['section_type'] ?? 'generic' );
		$layout       = (string) ( $plan['layout'] ?? 'centered' );
		$elements     = is_array( $plan['elements'] ?? null ) ? $plan['elements'] : [];
		$patterns     = is_array( $plan['patterns'] ?? null ) ? $plan['patterns'] : [];
		$content_plan = is_array( $plan['content_plan'] ?? null ) ? $plan['content_plan'] : [];

		$warnings = array_merge(
			$warnings,
			$this->check_layout_coverage( $section_type, $layout, $elements, $patterns ),
			$this->check_content_hint_quality( $elements, $patterns, $content_plan ),
			$this->check_repeat_quality( $section_type, $elements, $patterns )
		);

		return array_values( array_unique( $warnings ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @param array<int, array<string, mixed>> $patterns
	 * @return array<int, string>
	 */
	private function check_layout_coverage( string $section_type, string $layout, array $elements, array $patterns ): array {
		$warnings   = [];
		$has_media  = false;
		$has_button = false;
		$has_heading = false;

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$type = (string) ( $element['type'] ?? '' );
			if ( in_array( $type, self::MEDIA_TYPES, true ) ) {
				$has_media = true;
			}
			if ( 'button' === $type ) {
				$has_button = true;
			}
			if ( 'heading' === $type || ( 'text-basic' === $type && str_contains( (string) ( $element['role'] ?? '' ), 'heading' ) ) ) {
				$has_heading = true;
			}
		}

		if ( str_starts_with( $layout, 'split' ) && ! $has_media && empty( $patterns ) ) {
			$warnings[] = 'Split layouts usually need a visual anchor (image/gallery/video or a repeat pattern). This plan has no obvious right-column visual content.';
		}
		if ( 'cta' === $section_type && ! $has_button ) {
			$warnings[] = 'CTA sections usually need at least one button. This plan has no button element.';
		}
		if ( 'hero' === $section_type && ! $has_heading ) {
			$warnings[] = 'Hero sections usually need a clear heading. This plan has no heading-like element.';
		}

		return $warnings;
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @param array<int, array<string, mixed>> $patterns
	 * @param array<string, mixed>             $content_plan
	 * @return array<int, string>
	 */
	private function check_content_hint_quality( array $elements, array $patterns, array $content_plan ): array {
		$warnings = [];

		foreach ( $elements as $idx => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$role         = (string) ( $element['role'] ?? '' );
			$type         = (string) ( $element['type'] ?? '' );
			$content_hint = trim( (string) ( $element['content_hint'] ?? '' ) );

			if ( '' !== $role && $this->is_generic_role( $role ) ) {
				$warnings[] = sprintf( 'elements[%d] uses a very generic role "%s". Use more specific roles like main_heading, primary_cta, feature_card_title, or hero_image.', $idx, $role );
			}

			if ( in_array( $type, self::MEDIA_TYPES, true ) && '' === $content_hint ) {
				$warnings[] = sprintf( 'elements[%d] (%s) has no content_hint. Image/media search quality will be weak without a descriptive hint.', $idx, $type );
			}

			if ( 'button' === $type && '' === $content_hint && ! isset( $content_plan[ $role ] ) ) {
				$warnings[] = sprintf( 'elements[%d] (%s) has no content_hint or content_plan entry. Populate quality for CTA text/link intent will be weaker.', $idx, $role ?: 'button' );
			}
		}

		foreach ( $patterns as $idx => $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}
			$hint = trim( (string) ( $pattern['content_hint'] ?? '' ) );
			if ( '' === $hint ) {
				$warnings[] = sprintf( 'patterns[%d] has no content_hint. Repeated item content will be harder to populate consistently.', $idx );
			}
			foreach ( (array) ( $pattern['element_structure'] ?? [] ) as $element_idx => $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}
				$role = (string) ( $element['role'] ?? '' );
				$type = (string) ( $element['type'] ?? '' );
				$element_hint = trim( (string) ( $element['content_hint'] ?? '' ) );
				if ( '' !== $role && $this->is_generic_role( $role ) ) {
					$warnings[] = sprintf( 'patterns[%d].element_structure[%d] uses a generic role "%s". Repeated items need more specific roles for reliable content mapping.', $idx, $element_idx, $role );
				}
				if ( in_array( $type, self::MEDIA_TYPES, true ) && '' === $element_hint ) {
					$warnings[] = sprintf( 'patterns[%d].element_structure[%d] (%s) has no content_hint. Repeated media items need descriptive hints for image selection quality.', $idx, $element_idx, $type );
				}
				if ( 'button' === $type && '' === $element_hint ) {
					$warnings[] = sprintf( 'patterns[%d].element_structure[%d] (%s) has no content_hint. Repeated CTA items will be harder to populate consistently.', $idx, $element_idx, $role ?: 'button' );
				}
			}
		}

		return $warnings;
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @param array<int, array<string, mixed>> $patterns
	 * @return array<int, string>
	 */
	private function check_repeat_quality( string $section_type, array $elements, array $patterns ): array {
		if ( ! empty( $patterns ) ) {
			return [];
		}

		if ( ! in_array( $section_type, [ 'features', 'pricing', 'testimonials' ], true ) ) {
			return [];
		}

		$repeatish = 0;
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$role = strtolower( (string) ( $element['role'] ?? '' ) );
			if (
				str_contains( $role, 'card' )
				|| str_contains( $role, 'feature' )
				|| str_contains( $role, 'service' )
				|| str_contains( $role, 'plan' )
				|| str_contains( $role, 'tier' )
				|| str_contains( $role, 'testimonial' )
				|| str_contains( $role, 'review' )
			) {
				$repeatish++;
			}
		}

		if ( $repeatish >= 3 ) {
			return [ 'This plan appears to model repeated items inline. Use patterns[] for repeated cards/testimonials/pricing tiers so structure and content stay consistent.' ];
		}

		return [];
	}

	private function is_generic_role( string $role ): bool {
		$normalized = DesignPlanNormalizationService::normalize_role_key( $role );
		return in_array( $normalized, self::GENERIC_ROLE_NAMES, true );
	}
}
