<?php
/**
 * Convert inline repeated direct elements into repeat templates when the
 * design plan already exposes a clear indexed repeated structure.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DesignPlanRepeatExtractionService {

	/**
	 * Roles supported for indexed repeated extraction.
	 *
	 * @var array<int, string>
	 */
	private const REPEATABLE_SUFFIXES = [ 'title', 'text', 'meta', 'cta', 'image', 'icon', 'author', 'price', 'eyebrow', 'subtitle', 'description' ];

	/**
	 * Section types where inline repeat extraction is allowed by default.
	 *
	 * @var array<int, string>
	 */
	private const REPEATABLE_SECTION_TYPES = [ 'features', 'pricing', 'testimonials' ];

	/**
	 * @param array<string, mixed> $plan
	 * @return array{design_plan: array<string, mixed>, extraction_log: array<int, string>}
	 */
	public function extract( array $plan ): array {
		$elements      = is_array( $plan['elements'] ?? null ) ? array_values( $plan['elements'] ) : [];
		$patterns      = is_array( $plan['patterns'] ?? null ) ? array_values( $plan['patterns'] ) : [];
		$section_type  = (string) ( $plan['section_type'] ?? 'generic' );
		$extraction_log = [];

		if ( [] === $elements || ! $this->should_extract_repeats( $section_type, $elements ) ) {
			return [
				'design_plan'    => $plan,
				'extraction_log' => [],
			];
		}

		$existing_pattern_names = [];
		foreach ( $patterns as $pattern ) {
			if ( is_array( $pattern ) && ! empty( $pattern['name'] ) ) {
				$existing_pattern_names[ DesignPlanNormalizationService::normalize_role_key( (string) $pattern['name'] ) ] = true;
			}
		}

		$groups = $this->collect_repeat_groups( $elements );
		$remove_indexes = [];

		foreach ( $groups as $group_name => $group ) {
			if ( isset( $existing_pattern_names[ $group_name ] ) ) {
				continue;
			}
			if ( ! $this->group_is_extractable( $group ) ) {
				continue;
			}

			$indices = array_keys( $group['items'] );
			sort( $indices );
			$first_index = $indices[0] ?? null;
			if ( null === $first_index ) {
				continue;
			}

			$pattern_elements = [];
			$ordered = $group['items'][ $first_index ];
			uasort(
				$ordered,
				static fn( array $a, array $b ): int => ( (int) ( $a['_position'] ?? 0 ) ) <=> ( (int) ( $b['_position'] ?? 0 ) )
			);

			foreach ( $ordered as $suffix => $element ) {
				unset( $element['_position'] );
				$element['role'] = $group_name . '_' . $suffix;
				$pattern_elements[] = $element;
			}

			$patterns[] = array_filter(
				[
					'name'              => $group_name,
					'repeat'            => count( $indices ),
					'element_structure' => $pattern_elements,
					'content_hint'      => $this->default_pattern_hint( $group_name, $section_type ),
				],
				static fn( $value ): bool => !( is_string( $value ) && '' === $value )
			);

			foreach ( $group['items'] as $item_elements ) {
				foreach ( $item_elements as $element ) {
					if ( isset( $element['_position'] ) ) {
						$remove_indexes[ (int) $element['_position'] ] = true;
					}
				}
			}

			$extraction_log[] = sprintf(
				'Converted %d inline repeated item(s) with base "%s" into patterns[].',
				count( $indices ),
				$group_name
			);
		}

		if ( [] !== $remove_indexes ) {
			$kept = [];
			foreach ( $elements as $index => $element ) {
				if ( isset( $remove_indexes[ $index ] ) ) {
					continue;
				}
				$kept[] = $element;
			}
			$plan['elements'] = $kept;
			$plan['patterns'] = $patterns;
		}

		return [
			'design_plan'    => $plan,
			'extraction_log' => $extraction_log,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 */
	private function should_extract_repeats( string $section_type, array $elements ): bool {
		if ( in_array( $section_type, self::REPEATABLE_SECTION_TYPES, true ) ) {
			return true;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$role = strtolower( (string) ( $element['role'] ?? '' ) );
			if (
				str_contains( $role, 'card' )
				|| str_contains( $role, 'tier' )
				|| str_contains( $role, 'testimonial' )
				|| str_contains( $role, 'review' )
				|| str_contains( $role, 'service' )
				|| str_contains( $role, 'feature' )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @return array<string, array{items: array<int, array<string, array<string, mixed>>>}>
	 */
	private function collect_repeat_groups( array $elements ): array {
		$groups = [];

		foreach ( $elements as $position => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$parsed = $this->parse_repeat_role( (string) ( $element['role'] ?? '' ) );
			if ( null === $parsed ) {
				continue;
			}

			$group_name = $parsed['base'];
			$index      = $parsed['index'];
			$suffix     = $parsed['suffix'];

			$groups[ $group_name ]['items'][ $index ][ $suffix ] = array_merge( $element, [ '_position' => $position ] );
		}

		return $groups;
	}

	/**
	 * @param array{items: array<int, array<string, array<string, mixed>>>} $group
	 */
	private function group_is_extractable( array $group ): bool {
		$items = $group['items'] ?? [];
		if ( count( $items ) < 2 ) {
			return false;
		}

		$indices = array_keys( $items );
		sort( $indices );
		if ( $indices !== range( min( $indices ), max( $indices ) ) ) {
			return false;
		}

		$first_index = $indices[0];
		$first_item  = $items[ $first_index ] ?? [];
		if ( count( $first_item ) < 2 ) {
			return false;
		}

		$expected_suffixes = array_keys( $first_item );
		sort( $expected_suffixes );

		foreach ( $items as $item ) {
			$suffixes = array_keys( $item );
			sort( $suffixes );
			if ( $suffixes !== $expected_suffixes ) {
				return false;
			}
			foreach ( $expected_suffixes as $suffix ) {
				$reference = $first_item[ $suffix ] ?? null;
				$current   = $item[ $suffix ] ?? null;
				if ( ! is_array( $reference ) || ! is_array( $current ) ) {
					return false;
				}
				if ( (string) ( $reference['type'] ?? '' ) !== (string) ( $current['type'] ?? '' ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * @return array{base: string, index: int, suffix: string}|null
	 */
	private function parse_repeat_role( string $role ): ?array {
		$normalized = DesignPlanNormalizationService::normalize_role_key( $role );
		if ( '' === $normalized ) {
			return null;
		}

		$suffix_pattern = implode( '|', self::REPEATABLE_SUFFIXES );

		if ( 1 === preg_match( '/^(.*)_(\d+)_(' . $suffix_pattern . ')$/', $normalized, $matches ) ) {
			return [
				'base'   => $matches[1],
				'index'  => (int) $matches[2],
				'suffix' => $matches[3],
			];
		}

		if ( 1 === preg_match( '/^(.*)_(' . $suffix_pattern . ')_(\d+)$/', $normalized, $matches ) ) {
			return [
				'base'   => $matches[1],
				'index'  => (int) $matches[3],
				'suffix' => $matches[2],
			];
		}

		return null;
	}

	private function default_pattern_hint( string $pattern_name, string $section_type ): string {
		$name = str_replace( '_', ' ', DesignPlanNormalizationService::normalize_role_key( $pattern_name ) );
		if ( 'pricing' === $section_type ) {
			return 'One pricing tier with title, price, details, and CTA';
		}
		if ( 'testimonials' === $section_type ) {
			return 'One testimonial item with quote, author, and supporting details';
		}
		if ( 'features' === $section_type ) {
			return 'One feature or service card with title, description, and supporting visual';
		}
		return 'One repeated ' . ( $name !== '' ? $name : 'item' );
	}
}
