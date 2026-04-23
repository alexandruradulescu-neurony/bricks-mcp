<?php
/**
 * Extensible composition pass for design plans.
 *
 * Works at the level of composition families and traits rather than site-
 * specific styles. The built-in families are intentionally broad and the
 * registry can be extended later without changing the core pipeline.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DesignPlanCompositionService {

	/**
	 * Media element types treated as visual anchors.
	 *
	 * @var array<int, string>
	 */
	private const MEDIA_TYPES = [ 'image', 'image-gallery', 'slider-nested', 'carousel', 'video' ];

	/**
	 * Built-in composition family registry. These are broad composition traits,
	 * not a closed list of "all families". WordPress filters can extend this.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const BUILTIN_FAMILIES = [
		'hero_banner' => [
			'section_types' => [ 'hero' ],
			'requires'      => [ 'heading' => true ],
			'role_order'    => [ 'eyebrow', 'main_heading', 'section_heading', 'subtitle', 'description', 'primary_cta', 'secondary_cta', 'cta_form', 'hero_image', 'section_image', 'gallery_images' ],
		],
		'media_split' => [
			'section_types' => [ 'split', 'generic' ],
			'requires'      => [ 'media' => true, 'text' => true ],
			'role_order'    => [ 'eyebrow', 'main_heading', 'section_heading', 'subtitle', 'description', 'description_1', 'primary_cta', 'secondary_cta', 'cta_form', 'hero_image', 'section_image', 'gallery_images' ],
		],
		'repeat_grid' => [
			'section_types' => [ 'features', 'pricing', 'testimonials', 'generic' ],
			'requires'      => [ 'repeatable' => true ],
			'role_order'    => [ 'eyebrow', 'section_heading', 'main_heading', 'subtitle', 'description', 'description_1', 'primary_cta', 'secondary_cta', 'hero_image', 'section_image' ],
		],
		'action_prompt' => [
			'section_types' => [ 'cta', 'generic' ],
			'requires'      => [ 'buttons' => true ],
			'role_order'    => [ 'eyebrow', 'main_heading', 'section_heading', 'subtitle', 'description', 'description_1', 'primary_cta', 'secondary_cta', 'cta_form', 'hero_image', 'section_image' ],
		],
		'content_stack' => [
			'section_types' => [ 'generic', 'hero', 'features', 'pricing', 'testimonials', 'split', 'cta' ],
			'requires'      => [],
			'role_order'    => [ 'eyebrow', 'main_heading', 'section_heading', 'subtitle', 'description', 'description_1', 'primary_cta', 'secondary_cta', 'cta_form', 'hero_image', 'section_image', 'gallery_images' ],
		],
	];

	/**
	 * @param array<string, mixed> $plan
	 * @return array{
	 *   design_plan: array<string, mixed>,
	 *   composition_family: string,
	 *   composition_log: array<int, string>,
	 *   composition_profile: array<string, mixed>
	 * }
	 */
	public function compose( array $plan ): array {
		$profile            = $this->build_profile( $plan );
		$families           = $this->family_registry();
		$family             = $this->resolve_family( (string) ( $plan['section_type'] ?? 'generic' ), $profile, $families );
		$family_definition  = $families[ $family ] ?? self::BUILTIN_FAMILIES['content_stack'];
		$composition_log    = [];

		if ( ! empty( $plan['elements'] ) && is_array( $plan['elements'] ) ) {
			$reordered = $this->reorder_elements( array_values( $plan['elements'] ), (array) ( $family_definition['role_order'] ?? [] ) );
			if ( $this->elements_changed( (array) $plan['elements'], $reordered ) ) {
				$plan['elements'] = $reordered;
				$composition_log[] = sprintf( 'Reordered direct elements using the "%s" composition family.', $family );
			}
		}

		$layout_update = $this->recommended_layout( (string) ( $plan['layout'] ?? 'centered' ), $family, $profile );
		if ( null !== $layout_update && $layout_update !== ( $plan['layout'] ?? '' ) ) {
			$plan['layout'] = $layout_update;
			$composition_log[] = sprintf( 'Adjusted layout to "%s" for the "%s" composition family.', $layout_update, $family );
		}

		return [
			'design_plan'         => $plan,
			'composition_family'  => $family,
			'composition_log'     => $composition_log,
			'composition_profile' => $profile,
		];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function family_registry(): array {
		$families = self::BUILTIN_FAMILIES;

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'bricks_mcp_design_plan_families', $families );
			if ( is_array( $filtered ) ) {
				$families = $filtered;
			}
		}

		return $families;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_profile( array $plan ): array {
		$elements     = is_array( $plan['elements'] ?? null ) ? $plan['elements'] : [];
		$patterns     = is_array( $plan['patterns'] ?? null ) ? $plan['patterns'] : [];
		$section_type = (string) ( $plan['section_type'] ?? 'generic' );

		$heading_count = 0;
		$text_count    = 0;
		$button_count  = 0;
		$form_count    = 0;
		$media_count   = 0;
		$repeat_signals = 0;
		$repeat_item_indices = [];

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$type = (string) ( $element['type'] ?? '' );
			$role = DesignPlanNormalizationService::normalize_role_key( (string) ( $element['role'] ?? '' ) );

			if ( 'heading' === $type || $this->role_is_heading_like( $role ) ) {
				$heading_count++;
			}
			if ( in_array( $type, [ 'text-basic', 'text' ], true ) ) {
				$text_count++;
			}
			if ( 'button' === $type ) {
				$button_count++;
			}
			if ( 'form' === $type ) {
				$form_count++;
			}
			if ( in_array( $type, self::MEDIA_TYPES, true ) ) {
				$media_count++;
			}
			if ( $this->role_has_repeat_signal( $role ) ) {
				$repeat_signals++;
			}
			$repeat_index = $this->indexed_repeat_index( $role );
			if ( null !== $repeat_index ) {
				$repeat_item_indices[ $repeat_index ] = true;
			}
		}

		$pattern_repeat_count = 0;
		foreach ( $patterns as $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}
			$pattern_repeat_count += max( 0, (int) ( $pattern['repeat'] ?? 0 ) );
		}

		return [
			'section_type'       => $section_type,
			'layout'             => (string) ( $plan['layout'] ?? 'centered' ),
			'has_heading'        => $heading_count > 0,
			'has_text'           => $text_count > 0,
			'has_buttons'        => $button_count > 0,
			'has_forms'          => $form_count > 0,
			'has_media'          => $media_count > 0,
			'has_patterns'       => $patterns !== [],
			'has_repeatable'     => $patterns !== [] || count( $repeat_item_indices ) >= 2 || $repeat_signals >= 3,
			'heading_count'      => $heading_count,
			'text_count'         => $text_count,
			'button_count'       => $button_count,
			'form_count'         => $form_count,
			'media_count'        => $media_count,
			'pattern_count'      => count( $patterns ),
			'pattern_repeat_sum' => $pattern_repeat_count,
			'repeat_item_count'  => count( $repeat_item_indices ),
			'repeat_signal_count'=> $repeat_signals,
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $families
	 */
	private function resolve_family( string $section_type, array $profile, array $families ): string {
		$best_family = 'content_stack';
		$best_score  = PHP_INT_MIN;

		foreach ( $families as $family => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$score = 0;
			$section_types = is_array( $definition['section_types'] ?? null ) ? $definition['section_types'] : [];
			if ( in_array( $section_type, $section_types, true ) ) {
				$score += 5;
			}

			$requires = is_array( $definition['requires'] ?? null ) ? $definition['requires'] : [];
			foreach ( $requires as $trait => $expected ) {
				$profile_key = match ( $trait ) {
					'heading'    => 'has_heading',
					'text'       => 'has_text',
					'buttons'    => 'has_buttons',
					'forms'      => 'has_forms',
					'media'      => 'has_media',
					'repeatable' => 'has_repeatable',
					default      => null,
				};
				if ( null === $profile_key ) {
					continue;
				}
				$actual = ! empty( $profile[ $profile_key ] );
				$score += ( (bool) $expected === $actual ) ? 3 : -4;
			}

			if ( $score > $best_score ) {
				$best_score  = $score;
				$best_family = (string) $family;
			}
		}

		return $best_family;
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @param array<int, string>               $role_order
	 * @return array<int, array<string, mixed>>
	 */
	private function reorder_elements( array $elements, array $role_order ): array {
		$priority_map = [];
		foreach ( $role_order as $index => $pattern ) {
			$priority_map[ (string) $pattern ] = $index;
		}

		$decorated = [];
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$decorated[] = [
				'index'    => $index,
				'priority' => $this->element_priority( $element, $priority_map ),
				'element'  => $element,
			];
		}

		usort(
			$decorated,
			static function ( array $a, array $b ): int {
				$priority_compare = $a['priority'] <=> $b['priority'];
				if ( 0 !== $priority_compare ) {
					return $priority_compare;
				}
				return $a['index'] <=> $b['index'];
			}
		);

		return array_values( array_map( static fn( array $item ): array => $item['element'], $decorated ) );
	}

	/**
	 * @param array<string, mixed>    $element
	 * @param array<string, int>      $priority_map
	 */
	private function element_priority( array $element, array $priority_map ): int {
		$role = DesignPlanNormalizationService::normalize_role_key( (string) ( $element['role'] ?? '' ) );
		foreach ( $priority_map as $pattern => $priority ) {
			if ( '' !== $role && ( $role === $pattern || str_contains( $role, $pattern ) ) ) {
				return $priority;
			}
		}

		$type = (string) ( $element['type'] ?? '' );
		return match ( $type ) {
			'heading'    => 10,
			'text-basic',
			'text'       => 20,
			'button'     => 30,
			'form'       => 35,
			'image',
			'image-gallery',
			'slider-nested',
			'carousel',
			'video'      => 40,
			default      => 50,
		};
	}

	private function recommended_layout( string $current_layout, string $family, array $profile ): ?string {
		if ( 'repeat_grid' === $family && ! str_starts_with( $current_layout, 'grid-' ) && ! empty( $profile['has_repeatable'] ) ) {
			$repeat_total = max(
				(int) ( $profile['pattern_repeat_sum'] ?? 0 ),
				(int) ( $profile['repeat_item_count'] ?? 0 )
			);
			return $this->repeat_grid_layout( $repeat_total );
		}

		if ( 'media_split' === $family && ! str_starts_with( $current_layout, 'split-' ) && ! empty( $profile['has_media'] ) && ! empty( $profile['has_text'] ) ) {
			return 'split-50-50';
		}

		if ( 'action_prompt' === $family && str_starts_with( $current_layout, 'split-' ) && empty( $profile['has_media'] ) ) {
			return 'centered';
		}

		if ( 'hero_banner' === $family && ! str_starts_with( $current_layout, 'split-' ) && ! empty( $profile['has_media'] ) ) {
			return 'split-50-50';
		}

		return null;
	}

	private function repeat_grid_layout( int $repeat_total ): string {
		if ( $repeat_total >= 4 ) {
			return 'grid-4';
		}
		if ( $repeat_total <= 2 ) {
			return 'grid-2';
		}
		return 'grid-3';
	}

	/**
	 * @param array<int, array<string, mixed>> $before
	 * @param array<int, array<string, mixed>> $after
	 */
	private function elements_changed( array $before, array $after ): bool {
		return json_encode( array_values( $before ) ) !== json_encode( array_values( $after ) );
	}

	private function role_has_repeat_signal( string $role ): bool {
		if ( '' === $role ) {
			return false;
		}
		return
			str_contains( $role, 'card_' )
			|| str_contains( $role, 'feature_' )
			|| str_contains( $role, 'service_' )
			|| str_contains( $role, 'tier_' )
			|| str_contains( $role, 'testimonial_' )
			|| str_contains( $role, 'review_' );
	}

	private function role_is_heading_like( string $role ): bool {
		return 1 === preg_match( '/(^|_)(heading|title)($|_)/', $role );
	}

	private function indexed_repeat_index( string $role ): ?int {
		if ( '' === $role ) {
			return null;
		}
		if ( 1 === preg_match( '/_(\d+)_/', $role, $matches ) ) {
			return (int) $matches[1];
		}
		if ( 1 === preg_match( '/_(\d+)$/', $role, $matches ) ) {
			return (int) $matches[1];
		}
		return null;
	}
}
