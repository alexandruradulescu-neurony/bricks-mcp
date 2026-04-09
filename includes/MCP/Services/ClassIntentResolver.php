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
	 * @param array<int, string> $intents Unique class intent strings.
	 * @return array{map: array<string, string>, classes_reused: string[], classes_created: string[]}
	 */
	public function resolve( array $intents ): array {
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

			// 3. Create new class.
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
}
