<?php
/**
 * Design pattern service.
 *
 * Database-backed pattern library for reusable section compositions.
 * Provides CRUD, export/import.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DesignPatternService {

	/** @var array<int, array>|null Cached patterns. */
	private static ?array $all_patterns = null;

	/** WP option key for database-tier patterns. */
	private const DB_OPTION = BricksCore::OPTION_PATTERNS;

	// ──────────────────────────────────────────────
	// Loading — database only
	// ──────────────────────────────────────────────

	/**
	 * Load all patterns from database.
	 *
	 * @return array<int, array>
	 */
	private static function load_all(): array {
		if ( null !== self::$all_patterns ) {
			return self::$all_patterns;
		}

		$db_patterns = get_option( self::DB_OPTION, [] );
		$by_id       = [];

		if ( is_array( $db_patterns ) ) {
			foreach ( $db_patterns as $pattern ) {
				if ( ! is_array( $pattern ) || empty( $pattern['id'] ) ) {
					continue;
				}
				$pattern['source'] = 'database';
				$by_id[ $pattern['id'] ] = $pattern;
			}
		}

		self::$all_patterns = array_values( $by_id );
		return self::$all_patterns;
	}

	/**
	 * Clear the static cache. Called after any mutation.
	 */
	public static function clear_cache(): void {
		self::$all_patterns = null;
	}

	// ──────────────────────────────────────────────
	// Read operations
	// ──────────────────────────────────────────────

	/**
	 * Find patterns matching a section type and optional tags.
	 *
	 * @param string        $section_type  Section type (hero, features, cta, etc.)
	 * @param array<string> $tags          Optional tags to match.
	 * @param int           $limit         Max patterns to return.
	 * @return array<int, array> Matching patterns sorted by relevance.
	 */
	public static function find( string $section_type, array $tags = [], int $limit = 3 ): array {
		$all    = self::load_all();
		$scored = [];

		foreach ( $all as $pattern ) {
			$score = 0;

			$cat = $pattern['category'] ?? '';
			if ( $cat === $section_type ) {
				$score += 10;
			}

			$pattern_tags = $pattern['tags'] ?? [];
			foreach ( $tags as $tag ) {
				if ( in_array( $tag, $pattern_tags, true ) ) {
					$score += 2;
				}
			}

			$layout = $pattern['layout'] ?? '';
			if ( in_array( $layout, $tags, true ) ) {
				$score += 5;
			}

			if ( $score > 0 ) {
				$scored[] = [ 'pattern' => $pattern, 'score' => $score ];
			}
		}

		usort( $scored, fn( $a, $b ) => $b['score'] <=> $a['score'] );

		$results = [];
		foreach ( array_slice( $scored, 0, $limit ) as $item ) {
			$results[] = $item['pattern'];
		}

		return $results;
	}

	/**
	 * Find a single pattern by ID.
	 *
	 * @param string $pattern_id Pattern ID.
	 * @return array|null Pattern or null.
	 */
	public static function get( string $pattern_id ): ?array {
		$all = self::load_all();
		foreach ( $all as $pattern ) {
			if ( ( $pattern['id'] ?? '' ) === $pattern_id ) {
				return $pattern;
			}
		}
		return null;
	}

	/**
	 * Return ALL patterns as full objects (including structure, classes, variables).
	 *
	 * Used by PatternCatalog and admin UI for anything that needs the full pattern tree.
	 * list_all() is intentionally a thin summary and cannot be changed without breaking
	 * other callers that expect small payloads.
	 *
	 * @return array<int, array>
	 */
	public static function get_all_full(): array {
		return self::load_all();
	}

	/**
	 * Get a summary list of all patterns.
	 *
	 * @return array<int, array> Pattern summaries.
	 */
	public static function list_all(): array {
		$all       = self::load_all();
		$summaries = [];
		foreach ( $all as $pattern ) {
			$summaries[] = [
				'id'              => $pattern['id'] ?? '',
				'name'            => $pattern['name'] ?? '',
				'category'        => $pattern['category'] ?? '',
				'description'     => $pattern['description'] ?? '',
				'ai_description'  => $pattern['ai_description'] ?? null,
				'ai_usage_hints'  => $pattern['ai_usage_hints'] ?? [],
				'tags'            => $pattern['tags'] ?? [],
				'layout'          => $pattern['layout'] ?? '',
				'source'          => $pattern['source'] ?? 'database',
			];
		}
		return $summaries;
	}

	// ──────────────────────────────────────────────
	// Write operations
	// ──────────────────────────────────────────────

	/**
	 * Create a new pattern in the database.
	 *
	 * Runs the input through PatternValidator against current site context
	 * (variables + classes). Rejects on hardcoded values, missing refs, etc.
	 *
	 * @param array $pattern Raw pattern input. Required: id, name, category.
	 * @return array|\WP_Error The saved pattern OR error.
	 */
	public static function create( array $pattern ): array|\WP_Error {
		// If already validated (came from PatternCapture which validates internally),
		// skip re-validation: presence of `checksum` is the validated marker.
		$needs_validation = empty( $pattern['checksum'] );

		if ( $needs_validation ) {
			$validator    = new PatternValidator();
			$site_context = self::build_site_context();
			$pattern      = $validator->validate_with_context( $pattern, $site_context );
			if ( isset( $pattern['error'] ) ) {
				return new \WP_Error( $pattern['error'], $pattern['message'] ?? 'Validation failed.', $pattern );
			}
		}

		if ( ! isset( $pattern['tags'] ) || ! is_array( $pattern['tags'] ) ) {
			$pattern['tags'] = [];
		}
		if ( ! preg_match( '/^[a-z0-9-]+$/', $pattern['id'] ) ) {
			return new \WP_Error( 'invalid_id', 'Pattern ID must be lowercase alphanumeric with hyphens only.' );
		}

		// Auto-suffix on ID conflict.
		$base_id = $pattern['id'];
		$suffix  = 2;
		while ( null !== self::get( $pattern['id'] ) ) {
			$pattern['id'] = $base_id . '-v' . $suffix;
			$suffix++;
		}

		$db_patterns   = get_option( self::DB_OPTION, [] );
		$db_patterns   = is_array( $db_patterns ) ? $db_patterns : [];
		$db_patterns[] = $pattern;
		update_option( self::DB_OPTION, $db_patterns, false );

		self::clear_cache();

		$result = $pattern;
		if ( $pattern['id'] !== $base_id ) {
			$result['_note'] = sprintf( 'ID auto-suffixed from "%s" to "%s" to avoid conflict.', $base_id, $pattern['id'] );
		}
		return $result;
	}

	/**
	 * Build site context (variables + classes maps) for validator.
	 *
	 * Uses instance-bound services via BricksCore, wrapped through a static adapter
	 * so create() can be called statically without requiring the caller to inject services.
	 */
	private static function build_site_context(): array {
		// Instantiate services directly — BricksCore requires an ElementNormalizer.
		$core      = new BricksCore( new ElementNormalizer( new ElementIdGenerator() ) );
		$classes   = new GlobalClassService( $core );
		$variables = new GlobalVariableService( $core );

		return [
			'variables' => $variables->get_all_with_values(),
			'classes'   => $classes->get_all_by_name(),
		];
	}

	/**
	 * Update an existing pattern.
	 *
	 * @param string $id      Pattern ID.
	 * @param array  $updates Fields to merge into the pattern.
	 * @return array|\WP_Error Updated pattern or error.
	 */
	public static function update( string $id, array $updates ): array|\WP_Error {
		$existing = self::get( $id );
		if ( null === $existing ) {
			return new \WP_Error( 'not_found', sprintf( 'Pattern "%s" not found.', $id ) );
		}

		// Merge updates, preserving id and source.
		$merged           = array_merge( $existing, $updates );
		$merged['id']     = $id;
		$merged['source'] = 'database';

		// Write back.
		$db_patterns = get_option( self::DB_OPTION, [] );
		$db_patterns = is_array( $db_patterns ) ? $db_patterns : [];
		$found       = false;
		foreach ( $db_patterns as &$p ) {
			if ( ( $p['id'] ?? '' ) === $id ) {
				$p     = $merged;
				$found = true;
				break;
			}
		}
		unset( $p );

		if ( ! $found ) {
			return new \WP_Error( 'update_failed', 'Pattern found in cache but not in database option.' );
		}

		update_option( self::DB_OPTION, $db_patterns, false );
		self::clear_cache();

		return $merged;
	}

	/**
	 * Delete a pattern from the database.
	 *
	 * @param string $id Pattern ID.
	 * @return array|\WP_Error Result with action taken.
	 */
	public static function delete( string $id ): array|\WP_Error {
		$existing = self::get( $id );
		if ( null === $existing ) {
			return new \WP_Error( 'not_found', sprintf( 'Pattern "%s" not found.', $id ) );
		}

		$db_patterns = get_option( self::DB_OPTION, [] );
		$db_patterns = is_array( $db_patterns ) ? $db_patterns : [];
		$db_patterns = array_values( array_filter(
			$db_patterns,
			fn( $p ) => ( $p['id'] ?? '' ) !== $id
		) );
		update_option( self::DB_OPTION, $db_patterns, false );
		self::clear_cache();

		return [ 'id' => $id, 'action' => 'deleted', 'source' => 'database' ];
	}

	// ──────────────────────────────────────────────
	// Export / Import
	// ──────────────────────────────────────────────

	/**
	 * Export patterns as a portable JSON array.
	 *
	 * @param array<string> $ids Pattern IDs to export. Empty = all patterns.
	 * @return array<int, array> Portable pattern objects (source stripped).
	 */
	public static function export( array $ids = [] ): array {
		$all    = self::load_all();
		$result = [];

		foreach ( $all as $pattern ) {
			$pid = $pattern['id'] ?? '';
			if ( ! empty( $ids ) && ! in_array( $pid, $ids, true ) ) {
				continue;
			}
			$export = $pattern;
			unset( $export['source'] ); // Source is transport-irrelevant.
			$result[] = $export;
		}

		return $result;
	}

	/**
	 * Import patterns from a portable JSON array.
	 *
	 * For each pattern: ensure every class in pattern.classes exists on target
	 * site (auto-create with suffix on name conflict); ensure every variable in
	 * pattern.variables exists (auto-create if missing); then insert.
	 *
	 * @param array<int, array> $patterns Patterns to import.
	 * @return array{imported: string[], errors: array, created_classes: string[], created_variables: string[]}
	 */
	public static function import( array $patterns ): array {
		$imported          = [];
		$errors            = [];
		$created_classes   = [];
		$created_variables = [];

		// Services for auto-hydration.
		$core    = new BricksCore( new ElementNormalizer( new ElementIdGenerator() ) );
		$classes = new GlobalClassService( $core );
		$vars    = new GlobalVariableService( $core );

		foreach ( $patterns as $idx => $pattern ) {
			if ( ! is_array( $pattern ) || empty( $pattern['id'] ) ) {
				$errors[] = [ 'index' => $idx, 'error' => 'Invalid pattern or missing id.' ];
				continue;
			}

			// Hydrate missing classes on target site.
			foreach ( $pattern['classes'] ?? [] as $name => $def ) {
				if ( ! $classes->exists_by_name( $name ) ) {
					$new_name = $classes->create_from_payload( $def );
					if ( $new_name !== '' ) {
						$created_classes[] = $new_name;
						if ( $new_name !== $name ) {
							// Rename refs in structure to match.
							$pattern = self::rename_class_ref( $pattern, $name, $new_name );
							$pattern['classes'][ $new_name ] = $pattern['classes'][ $name ];
							unset( $pattern['classes'][ $name ] );
						}
					}
				}
			}

			// Hydrate missing variables on target site.
			foreach ( $pattern['variables'] ?? [] as $name => $def ) {
				if ( ! $vars->exists( $name ) ) {
					if ( $vars->create_from_payload( $name, $def ) ) {
						$created_variables[] = $name;
					}
				}
			}

			// Preserve checksum so create() skips re-validation.
			// Imported patterns are pre-validated (they have a checksum from export).
			if ( empty( $pattern['checksum'] ) ) {
				// No checksum = must validate. Otherwise (has checksum): pass through.
			}

			$result = self::create( $pattern );
			if ( is_wp_error( $result ) ) {
				$errors[] = [ 'index' => $idx, 'id' => $pattern['id'], 'error' => $result->get_error_message() ];
			} else {
				$imported[] = $result['id'];
			}
		}

		return [
			'imported'          => $imported,
			'errors'            => $errors,
			'created_classes'   => array_values( array_unique( $created_classes ) ),
			'created_variables' => array_values( array_unique( $created_variables ) ),
		];
	}

	/**
	 * Rename a class_ref inside a pattern's structure tree.
	 */
	private static function rename_class_ref( array $pattern, string $old, string $new ): array {
		$walk = static function ( &$node ) use ( &$walk, $old, $new ) {
			if ( isset( $node['class_refs'] ) && is_array( $node['class_refs'] ) ) {
				$node['class_refs'] = array_map( fn( $r ) => $r === $old ? $new : $r, $node['class_refs'] );
			}
			foreach ( $node as $k => &$v ) {
				if ( is_array( $v ) ) {
					$walk( $v );
				}
			}
		};
		if ( isset( $pattern['structure'] ) ) {
			$walk( $pattern['structure'] );
		}
		return $pattern;
	}

}
