<?php
/**
 * Heuristic enrichment for weak design plans before validation/build.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DesignPlanEnrichmentService {

	private const GENERIC_ROLE_NAMES = [ 'text', 'heading', 'button', 'image', 'content', 'title', 'subtitle', 'card', 'item' ];

	/**
	 * @param array<string, mixed> $plan
	 * @return array{design_plan: array<string, mixed>, enrichment_log: array<string, mixed>, role_key_map: array<string, string>}
	 */
	public function enrich( array $plan ): array {
		$log          = [
			'roles_rewritten'       => [],
			'content_hints_added'   => [],
			'pattern_hints_added'   => [],
			'pattern_roles_rewritten' => [],
			'pattern_content_hints_added' => [],
		];
		$role_key_map = [];
		$section_type = (string) ( $plan['section_type'] ?? 'generic' );

		$elements = is_array( $plan['elements'] ?? null ) ? $plan['elements'] : [];
		$elements = $this->enrich_elements( $elements, $section_type, $log, $role_key_map );
		$plan['elements'] = $elements;

		$patterns = is_array( $plan['patterns'] ?? null ) ? $plan['patterns'] : [];
		$patterns = $this->enrich_patterns( $patterns, $section_type, $log, $role_key_map );
		$plan['patterns'] = $patterns;
		if ( isset( $plan['content_plan'] ) && is_array( $plan['content_plan'] ) ) {
			$plan['content_plan'] = $this->rewrite_map_keys( $plan['content_plan'], $role_key_map );
		}

		return [
			'design_plan'     => $plan,
			'enrichment_log'  => array_filter( $log, static fn( $value ): bool => is_array( $value ) ? [] !== $value : null !== $value ),
			'role_key_map'    => $role_key_map,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @param array<string, mixed>             $log
	 * @param array<string, string>            $role_key_map
	 * @return array<int, array<string, mixed>>
	 */
	private function enrich_elements( array $elements, string $section_type, array &$log, array &$role_key_map ): array {
		$used_roles = [];
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$original_role   = (string) ( $element['role'] ?? '' );
			$normalized_role = DesignPlanNormalizationService::normalize_role_key( $original_role );
			$role            = $normalized_role;

			if ( '' === $role || $this->is_generic_role( $role ) ) {
				$role = $this->infer_element_role( $element, $index, $elements, $section_type );
			}
			$role = $this->unique_role( $role, $used_roles );
			$used_roles[] = $role;

			if ( '' !== $role ) {
				$element['role'] = $role;
			}

			if ( $role !== $normalized_role && '' !== $role ) {
				$log['roles_rewritten'][ 'elements[' . (string) $index . ']' ] = [
					'from' => $original_role,
					'to'   => $role,
				];
				if ( '' !== $normalized_role ) {
					$role_key_map[ $normalized_role ] = $role;
				}
			}

			$content_hint = trim( (string) ( $element['content_hint'] ?? '' ) );
			if ( '' === $content_hint ) {
				$generated_hint = $this->default_content_hint( $role, (string) ( $element['type'] ?? '' ), $section_type );
				if ( '' !== $generated_hint ) {
					$element['content_hint'] = $generated_hint;
					$log['content_hints_added'][ 'elements[' . (string) $index . ']' ] = $generated_hint;
				}
			}

			$elements[ $index ] = $element;
		}

		return $elements;
	}

	/**
	 * @param array<int, array<string, mixed>> $patterns
	 * @param array<string, mixed>             $log
	 * @param array<string, string>            $role_key_map
	 * @return array<int, array<string, mixed>>
	 */
	private function enrich_patterns( array $patterns, string $section_type, array &$log, array &$role_key_map ): array {
		foreach ( $patterns as $pattern_index => $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}

			$pattern_name = (string) ( $pattern['name'] ?? 'pattern' );
			if ( '' === trim( (string) ( $pattern['content_hint'] ?? '' ) ) ) {
				$pattern['content_hint'] = $this->default_pattern_hint( $pattern_name, $section_type );
				$log['pattern_hints_added'][ 'patterns[' . (string) $pattern_index . ']' ] = $pattern['content_hint'];
			}

			$used_roles = [];
			$structure  = is_array( $pattern['element_structure'] ?? null ) ? $pattern['element_structure'] : [];
			foreach ( $structure as $element_index => $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}

				$original_role   = (string) ( $element['role'] ?? '' );
				$normalized_role = DesignPlanNormalizationService::normalize_role_key( $original_role );
				$role            = $normalized_role;

				if ( '' === $role || $this->is_generic_role( $role ) ) {
					$role = $this->infer_pattern_role( $element, $element_index, $structure, $section_type, $pattern_name );
				}
				$role = $this->unique_role( $role, $used_roles );
				$used_roles[] = $role;

				if ( '' !== $role ) {
					$element['role'] = $role;
				}

				if ( $role !== $normalized_role && '' !== $role ) {
					$log['pattern_roles_rewritten'][ 'patterns[' . (string) $pattern_index . '].element_structure[' . (string) $element_index . ']' ] = [
						'from' => $original_role,
						'to'   => $role,
					];
					if ( '' !== $normalized_role ) {
						$role_key_map[ $normalized_role ] = $role;
					}
				}

				$content_hint = trim( (string) ( $element['content_hint'] ?? '' ) );
				if ( '' === $content_hint ) {
					$generated_hint = $this->default_pattern_child_hint( $role, (string) ( $element['type'] ?? '' ), $pattern_name, $section_type );
					if ( '' !== $generated_hint ) {
						$element['content_hint'] = $generated_hint;
						$log['pattern_content_hints_added'][ 'patterns[' . (string) $pattern_index . '].element_structure[' . (string) $element_index . ']' ] = $generated_hint;
					}
				}

				$structure[ $element_index ] = $element;
			}

			$pattern['element_structure'] = $structure;
			$patterns[ $pattern_index ]   = $pattern;
		}

		return $patterns;
	}

	/**
	 * @param array<string, mixed>              $element
	 * @param array<int, array<string, mixed>>  $all_elements
	 */
	private function infer_element_role( array $element, int $index, array $all_elements, string $section_type ): string {
		$type = (string) ( $element['type'] ?? '' );

		return match ( $type ) {
			'heading' => 0 === $this->count_previous_type( $all_elements, $index, 'heading' ) ? 'main_heading' : 'section_heading_' . (string) ( $this->count_previous_type( $all_elements, $index, 'heading' ) + 1 ),
			'button' => 0 === $this->count_previous_type( $all_elements, $index, 'button' ) ? 'primary_cta' : 'secondary_cta',
			'image' => in_array( $section_type, [ 'hero', 'split' ], true ) && 0 === $this->count_previous_type( $all_elements, $index, 'image' ) ? 'hero_image' : 'section_image_' . (string) ( $this->count_previous_type( $all_elements, $index, 'image' ) + 1 ),
			'image-gallery' => 'gallery_images',
			'form' => str_contains( $section_type, 'cta' ) ? 'cta_form' : 'contact_form',
			'icon' => 'feature_icon_' . (string) ( $this->count_previous_type( $all_elements, $index, 'icon' ) + 1 ),
			'text', 'text-basic' => $this->infer_text_role( $index, $all_elements, $section_type ),
			default => $type !== '' ? $type . '_' . (string) ( $index + 1 ) : 'element_' . (string) ( $index + 1 ),
		};
	}

	/**
	 * @param array<string, mixed>              $element
	 * @param array<int, array<string, mixed>>  $structure
	 */
	private function infer_pattern_role( array $element, int $index, array $structure, string $section_type, string $pattern_name ): string {
		$type = (string) ( $element['type'] ?? '' );
		$base = match ( $section_type ) {
			'pricing'      => 'tier',
			'testimonials' => 'testimonial',
			'features'     => str_contains( strtolower( $pattern_name ), 'service' ) ? 'service_card' : 'feature_card',
			default        => str_contains( strtolower( $pattern_name ), 'card' ) ? 'card' : DesignPlanNormalizationService::normalize_role_key( $pattern_name ),
		};

		return match ( $type ) {
			'heading' => $base . '_title',
			'text', 'text-basic' => 0 === $this->count_previous_type( $structure, $index, 'text-basic', 'text' ) ? $base . '_text' : $base . '_meta',
			'button' => $base . '_cta',
			'image' => $base . '_image',
			'icon' => $base . '_icon',
			default => $base . '_' . ( $type !== '' ? $type : 'item' ),
		};
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 */
	private function infer_text_role( int $index, array $elements, string $section_type ): string {
		$headings_before = $this->count_previous_type( $elements, $index, 'heading' );
		$texts_before    = $this->count_previous_type( $elements, $index, 'text-basic', 'text' );

		if ( 0 === $headings_before && 0 === $texts_before && 'hero' === $section_type ) {
			return 'eyebrow';
		}
		if ( $headings_before > 0 && 0 === $texts_before ) {
			return 'subtitle';
		}
		return 'description_' . (string) ( $texts_before + 1 );
	}

	private function default_content_hint( string $role, string $type, string $section_type ): string {
		if ( str_contains( $role, 'primary_cta' ) ) {
			return 'Primary call to action for this ' . $section_type . ' section';
		}
		if ( str_contains( $role, 'secondary_cta' ) ) {
			return 'Secondary call to action for this ' . $section_type . ' section';
		}
		if ( str_contains( $role, 'main_heading' ) ) {
			return 'Main heading for this ' . $section_type . ' section';
		}
		if ( 'subtitle' === $role || str_contains( $role, 'description' ) ) {
			return 'Supporting text that explains the main message';
		}
		if ( str_contains( $role, 'image' ) || in_array( $type, [ 'image', 'image-gallery', 'video' ], true ) ) {
			return 'Relevant visual for ' . str_replace( '_', ' ', $role );
		}
		if ( str_contains( $role, 'form' ) ) {
			return 'Lead capture or contact form for this section';
		}
		if ( str_contains( $role, 'title' ) ) {
			return 'Title text for ' . str_replace( '_', ' ', $role );
		}
		if ( str_contains( $role, 'text' ) ) {
			return 'Body copy for ' . str_replace( '_', ' ', $role );
		}

		return '';
	}

	private function default_pattern_hint( string $pattern_name, string $section_type ): string {
		$name = DesignPlanNormalizationService::normalize_role_key( $pattern_name );
		if ( 'pricing' === $section_type ) {
			return 'One pricing tier with title, price, details, and CTA';
		}
		if ( 'testimonials' === $section_type ) {
			return 'One testimonial item with quote, author, and supporting details';
		}
		if ( 'features' === $section_type ) {
			return 'One feature or service card with title, description, and supporting visual';
		}
		return 'One repeated ' . str_replace( '_', ' ', $name ?: 'item' );
	}

	private function default_pattern_child_hint( string $role, string $type, string $pattern_name, string $section_type ): string {
		$pattern_label = str_replace( '_', ' ', DesignPlanNormalizationService::normalize_role_key( $pattern_name ?: 'item' ) );
		$base_hint     = $this->default_content_hint( $role, $type, $section_type );

		if ( str_contains( $role, 'price' ) ) {
			return 'Price or plan cost for one ' . $pattern_label;
		}
		if ( str_contains( $role, 'author' ) ) {
			return 'Author or customer name for one ' . $pattern_label;
		}
		if ( str_contains( $role, 'cta' ) ) {
			return 'Call to action for one ' . $pattern_label;
		}
		if ( str_contains( $role, 'title' ) ) {
			return 'Title for one ' . $pattern_label;
		}
		if ( str_contains( $role, 'text' ) || str_contains( $role, 'description' ) || str_contains( $role, 'meta' ) ) {
			return 'Supporting copy for one ' . $pattern_label;
		}
		if ( str_contains( $role, 'image' ) || in_array( $type, [ 'image', 'image-gallery', 'video' ], true ) ) {
			return 'Relevant visual for one ' . $pattern_label;
		}
		if ( str_contains( $role, 'icon' ) ) {
			return 'Supporting icon for one ' . $pattern_label;
		}

		return '' !== $base_hint ? $base_hint : 'Content for one ' . $pattern_label;
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @param string ...$types
	 */
	private function count_previous_type( array $elements, int $index, string ...$types ): int {
		$count = 0;
		for ( $i = 0; $i < $index; $i++ ) {
			$type = (string) ( $elements[ $i ]['type'] ?? '' );
			if ( in_array( $type, $types, true ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * @param array<int, string> $used_roles
	 */
	private function unique_role( string $role, array $used_roles ): string {
		$role = DesignPlanNormalizationService::normalize_role_key( $role );
		if ( '' === $role ) {
			return '';
		}
		if ( ! in_array( $role, $used_roles, true ) ) {
			return $role;
		}

		$suffix = 2;
		$base   = $role;
		while ( in_array( $base . '_' . (string) $suffix, $used_roles, true ) ) {
			$suffix++;
		}
		return $base . '_' . (string) $suffix;
	}

	private function is_generic_role( string $role ): bool {
		return in_array( DesignPlanNormalizationService::normalize_role_key( $role ), self::GENERIC_ROLE_NAMES, true );
	}

	/**
	 * @param array<string, mixed>  $map
	 * @param array<string, string> $role_key_map
	 * @return array<string, mixed>
	 */
	private function rewrite_map_keys( array $map, array $role_key_map ): array {
		$rewritten = [];
		foreach ( $map as $key => $value ) {
			$normalized = DesignPlanNormalizationService::normalize_role_key( (string) $key );
			$target     = $role_key_map[ $normalized ] ?? $normalized;
			$rewritten[ $target !== '' ? $target : (string) $key ] = $value;
		}
		return $rewritten;
	}
}
