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
	private const DB_OPTION = 'bricks_mcp_custom_patterns';

	/** WP option key for the one-time migration flag. */
	private const MIGRATION_FLAG = 'bricks_mcp_patterns_migrated';

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
	 * If a pattern with the same ID already exists, auto-suffixes (-v2, -v3, ...).
	 *
	 * @param array $pattern Pattern data. Required: id, name, category, tags.
	 * @return array|\WP_Error The saved pattern (with final ID) or error.
	 */
	public static function create( array $pattern ): array|\WP_Error {
		// Validate required fields.
		foreach ( [ 'id', 'name', 'category' ] as $required ) {
			if ( empty( $pattern[ $required ] ) ) {
				return new \WP_Error( 'missing_field', sprintf( 'Pattern field "%s" is required.', $required ) );
			}
		}

		if ( ! isset( $pattern['tags'] ) || ! is_array( $pattern['tags'] ) ) {
			$pattern['tags'] = [];
		}

		// Validate ID format.
		if ( ! preg_match( '/^[a-z0-9-]+$/', $pattern['id'] ) ) {
			return new \WP_Error( 'invalid_id', 'Pattern ID must be lowercase alphanumeric with hyphens only.' );
		}

		// Auto-suffix on conflict.
		$base_id  = $pattern['id'];
		$suffix   = 2;
		while ( null !== self::get( $pattern['id'] ) ) {
			$pattern['id'] = $base_id . '-v' . $suffix;
			$suffix++;
		}

		$pattern['source'] = 'database';

		// Save to DB.
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
	 * Auto-suffixes conflicting IDs.
	 *
	 * @param array<int, array> $patterns Patterns to import.
	 * @return array{imported: string[], errors: array}
	 */
	public static function import( array $patterns ): array {
		$imported = [];
		$errors   = [];

		foreach ( $patterns as $idx => $pattern ) {
			if ( ! is_array( $pattern ) || empty( $pattern['id'] ) ) {
				$errors[] = [ 'index' => $idx, 'error' => 'Invalid pattern or missing id.' ];
				continue;
			}
			$result = self::create( $pattern );
			if ( is_wp_error( $result ) ) {
				$errors[] = [ 'index' => $idx, 'id' => $pattern['id'], 'error' => $result->get_error_message() ];
			} else {
				$imported[] = $result['id'];
			}
		}

		return [ 'imported' => $imported, 'errors' => $errors ];
	}

	// ──────────────────────────────────────────────
	// Migration (no-op, already ran)
	// ──────────────────────────────────────────────

	/**
	 * One-time migration stub.
	 *
	 * The migration has already been executed. This method is kept as a no-op
	 * so existing call sites in Plugin::init() do not break.
	 */
	public static function migrate_plugin_patterns(): void {
		// Migration already ran; flag check prevents re-execution.
		// Kept as empty method so Plugin.php call site doesn't need to change.
	}
}
