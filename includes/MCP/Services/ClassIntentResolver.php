<?php
/**
 * Class intent resolver.
 *
 * Matches class_intent values from design schemas to existing global classes
 * by semantic name/purpose. Creates new classes when no match exists.
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
 * Resolves class_intent strings to global class IDs.
 */
final class ClassIntentResolver {

	/**
	 * @var GlobalClassService
	 */
	private GlobalClassService $class_service;

	/**
	 * Cached global classes for the current request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $cached_classes = null;

	/**
	 * Constructor.
	 *
	 * @param GlobalClassService $class_service Global class service.
	 */
	public function __construct( GlobalClassService $class_service ) {
		$this->class_service = $class_service;
	}

	/**
	 * Resolve an array of class_intent strings to global class IDs.
	 *
	 * Matching priority:
	 * 1. Exact name match (case-sensitive)
	 * 2. Normalized match (lowercase, strip hyphens/underscores)
	 * 3. Create new global class with the intent as the name
	 *
	 * @param array<int, string> $intents  Unique class intent strings.
	 * @param bool              $dry_run  When true, only match existing classes — never create new ones.
	 * @return array{map: array<string, string>, classes_reused: string[], classes_created: string[], classes_unresolved: string[]}
	 */
	public function resolve( array $intents, bool $dry_run = false ): array {
		$classes = $this->get_classes();

		// Build lookup indexes.
		$exact_index      = []; // name => id
		$normalized_index = []; // normalized_name => id

		foreach ( $classes as $class ) {
			$name = $class['name'] ?? '';
			$id   = $class['id'] ?? '';
			if ( '' === $name || '' === $id ) {
				continue;
			}
			$exact_index[ $name ]                      = $id;
			$normalized_index[ self::normalize( $name ) ] = $id;
		}

		$map             = [];
		$classes_reused  = [];
		$classes_created = [];

		foreach ( $intents as $intent ) {
			if ( '' === $intent ) {
				continue;
			}

			// 1. Exact name match.
			if ( isset( $exact_index[ $intent ] ) ) {
				$map[ $intent ]   = $exact_index[ $intent ];
				$classes_reused[] = $intent;
				continue;
			}

			// 2. Normalized match.
			$normalized = self::normalize( $intent );
			if ( isset( $normalized_index[ $normalized ] ) ) {
				$map[ $intent ]   = $normalized_index[ $normalized ];
				$classes_reused[] = $intent;
				continue;
			}

			// 3. Create new class (skip in dry_run — just report it would be created).
			if ( $dry_run ) {
				$classes_created[] = $intent;
				continue;
			}

			$result = $this->class_service->create_global_class( [
				'name' => $intent,
			] );

			if ( is_wp_error( $result ) ) {
				// If creation fails (e.g. duplicate), try to resolve again.
				$resolved = $this->class_service->resolve_class_name( $intent );
				if ( null !== $resolved && ! empty( $resolved['id'] ) ) {
					$map[ $intent ]   = $resolved['id'];
					$classes_reused[] = $intent;
				}
				// Skip silently if still unresolvable.
				continue;
			}

			$new_id = $result['id'] ?? '';
			if ( '' !== $new_id ) {
				$map[ $intent ]     = $new_id;
				$classes_created[]  = $intent;

				// Update indexes so subsequent intents can match.
				$exact_index[ $intent ]                      = $new_id;
				$normalized_index[ self::normalize( $intent ) ] = $new_id;
			}
		}

		return [
			'map'             => $map,
			'classes_reused'  => $classes_reused,
			'classes_created' => $classes_created,
		];
	}

	/**
	 * Normalize a class name for fuzzy matching.
	 *
	 * Strips hyphens, underscores, and lowercases.
	 *
	 * @param string $name Class name.
	 * @return string Normalized name.
	 */
	private static function normalize( string $name ): string {
		return strtolower( str_replace( [ '-', '_' ], '', $name ) );
	}

	/**
	 * Get all global classes (cached per request). Public accessor for reverse lookups.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_classes_public(): array {
		return $this->get_classes();
	}

	/**
	 * Get all global classes (cached per request).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_classes(): array {
		if ( null === $this->cached_classes ) {
			$this->cached_classes = $this->class_service->get_global_classes();
		}
		return $this->cached_classes;
	}

	/**
	 * Clear the cached classes (useful after creating new ones).
	 */
	public function clear_cache(): void {
		$this->cached_classes = null;
	}

	/**
	 * Suggest a global class for an element based on its type and context.
	 *
	 * Used when no explicit class_intent is set. Checks context rules
	 * against existing global classes to find a semantic match.
	 *
	 * @param string        $element_type  Bricks element type (e.g., 'text-basic', 'block', 'icon').
	 * @param string        $parent_type   Parent element type (e.g., 'container', 'block').
	 * @param array<string> $sibling_types Types of sibling elements in order.
	 * @param int           $position      This element's position among siblings (0-indexed).
	 * @param array<string> $child_types   Types of this element's children.
	 * @param string|null   $parent_class  Class applied to the parent element (name, not ID).
	 * @return string|null Global class ID if a match is found, null otherwise.
	 */
	public function suggest_for_context(
		string $element_type,
		string $parent_type,
		array $sibling_types,
		int $position,
		array $child_types = [],
		?string $parent_class = null
	): ?string {
		$rules   = self::get_context_rules();
		$classes = $this->get_classes();

		// Build name → id + name → settings maps.
		$class_by_name = [];
		foreach ( $classes as $class ) {
			$name = $class['name'] ?? '';
			if ( '' !== $name ) {
				$class_by_name[ $name ] = $class;
			}
		}

		foreach ( $rules as $rule ) {
			$pattern      = $rule['class_pattern'] ?? '';
			$rule_type    = $rule['element_type'] ?? '';
			$context      = $rule['context'] ?? '';

			// Rule must match element type.
			if ( $rule_type !== $element_type ) {
				continue;
			}

			// Check context condition.
			$context_matches = match ( $context ) {
				'before_heading' => $this->check_before_heading( $sibling_types, $position ),
				'grid_columns_2' => $this->check_grid_columns( $child_types, 2 ),
				'grid_columns_3' => $this->check_grid_columns( $child_types, 3 ),
				'parent_of_pills' => $this->check_parent_of_pills( $child_types ),
				'has_icon_and_text' => $this->check_icon_and_text( $child_types ),
				'inside_pill' => $this->check_inside_pill( $parent_class ),
				default => false,
			};

			if ( ! $context_matches ) {
				continue;
			}

			// Find a class matching this pattern.
			foreach ( $class_by_name as $name => $class ) {
				if ( str_contains( $name, $pattern ) || $name === $pattern ) {
					return $class['id'] ?? null;
				}
			}
		}

		return null;
	}

	/**
	 * Context check: element is text-basic and next sibling is a heading.
	 */
	private function check_before_heading( array $sibling_types, int $position ): bool {
		return isset( $sibling_types[ $position + 1 ] ) && 'heading' === $sibling_types[ $position + 1 ];
	}

	/**
	 * Context check: element is a block/div with exactly N children (grid columns).
	 */
	private function check_grid_columns( array $child_types, int $expected ): bool {
		return count( $child_types ) === $expected;
	}

	/**
	 * Context check: element has children that look like pill blocks (icon + text pairs).
	 */
	private function check_parent_of_pills( array $child_types ): bool {
		// At least 3 children and all are blocks.
		if ( count( $child_types ) < 3 ) {
			return false;
		}
		foreach ( $child_types as $type ) {
			if ( 'block' !== $type ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Context check: element has exactly icon + text-basic children.
	 */
	private function check_icon_and_text( array $child_types ): bool {
		if ( 2 !== count( $child_types ) ) {
			return false;
		}
		return 'icon' === $child_types[0] && 'text-basic' === $child_types[1];
	}

	/**
	 * Context check: element is inside a parent with tag-pill class.
	 */
	private function check_inside_pill( ?string $parent_class ): bool {
		return null !== $parent_class && str_contains( $parent_class, 'tag-pill' );
	}

	/**
	 * Cached context rules from data/class-context-rules.json.
	 *
	 * @var array<int, array<string, string>>|null
	 */
	private static ?array $context_rules = null;

	/**
	 * Load context rules from the data file.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function get_context_rules(): array {
		if ( null === self::$context_rules ) {
			$path = dirname( __DIR__, 3 ) . '/data/class-context-rules.json';
			if ( file_exists( $path ) ) {
				$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$data = is_string( $json ) ? json_decode( $json, true ) : [];
				self::$context_rules = $data['rules'] ?? [];
			} else {
				self::$context_rules = [];
			}
		}
		return self::$context_rules;
	}
}
