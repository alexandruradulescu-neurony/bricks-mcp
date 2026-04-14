<?php
/**
 * Design pattern service.
 *
 * 3-tier pattern library: plugin-shipped → user files → database.
 * Provides CRUD, semantic search, export/import for reusable section compositions.
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

	/** @var array<int, array>|null Cached merged patterns from all 3 tiers. */
	private static ?array $all_patterns = null;

	/** WP option key for database-tier patterns. */
	private const DB_OPTION = 'bricks_mcp_custom_patterns';

	/** WP option key for hidden pattern IDs. */
	private const HIDDEN_OPTION = 'bricks_mcp_hidden_patterns';

	// ──────────────────────────────────────────────
	// Loading — 3-tier merge
	// ──────────────────────────────────────────────

	/**
	 * Load all patterns from plugin files, user files, and database.
	 *
	 * Merge order: database overrides user-file overrides plugin-shipped (same ID).
	 * Hidden patterns (soft-deleted plugin/user-file) are filtered out.
	 *
	 * @return array<int, array>
	 */
	private static function load_all(): array {
		if ( null !== self::$all_patterns ) {
			return self::$all_patterns;
		}

		$by_id = [];

		// Tier 1: Plugin-shipped patterns.
		$plugin_dir = dirname( __DIR__, 3 ) . '/data/design-patterns/';
		self::load_from_directory( $plugin_dir, 'plugin', $by_id );

		// Tier 2: User custom patterns from uploads directory.
		if ( function_exists( 'wp_upload_dir' ) ) {
			$upload = wp_upload_dir();
			$user_dir = $upload['basedir'] . '/bricks-mcp/design-patterns/';
			self::load_from_directory( $user_dir, 'user_file', $by_id );
		}

		// Tier 3: Database patterns (highest priority).
		$db_patterns = get_option( self::DB_OPTION, [] );
		if ( is_array( $db_patterns ) ) {
			foreach ( $db_patterns as $pattern ) {
				if ( ! is_array( $pattern ) || empty( $pattern['id'] ) ) {
					continue;
				}
				$pattern['source'] = 'database';
				$by_id[ $pattern['id'] ] = $pattern;
			}
		}

		// Filter out hidden patterns.
		$hidden = get_option( self::HIDDEN_OPTION, [] );
		if ( is_array( $hidden ) ) {
			foreach ( $hidden as $hid ) {
				unset( $by_id[ $hid ] );
			}
		}

		self::$all_patterns = array_values( $by_id );
		return self::$all_patterns;
	}

	/**
	 * Load patterns from a directory of category JSON files.
	 *
	 * @param string $dir    Directory path.
	 * @param string $source Source label ('plugin' or 'user_file').
	 * @param array  &$by_id Accumulator keyed by pattern ID (later tiers override earlier).
	 */
	private static function load_from_directory( string $dir, string $source, array &$by_id ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = glob( $dir . '*.json' );
		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( basename( $file ) === '_schema.json' ) {
				continue;
			}

			$json = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_string( $json ) ) {
				continue;
			}

			$data = json_decode( $json, true );
			if ( ! is_array( $data ) || empty( $data['patterns'] ) ) {
				continue;
			}

			$category = $data['category'] ?? 'generic';
			foreach ( $data['patterns'] as $pattern ) {
				if ( empty( $pattern['id'] ) ) {
					continue;
				}
				$pattern['category'] = $category;
				$pattern['source']   = $source;
				$by_id[ $pattern['id'] ] = $pattern;
			}
		}
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
				'source'          => $pattern['source'] ?? 'plugin',
			];
		}
		return $summaries;
	}

	// ──────────────────────────────────────────────
	// Write operations (database tier only)
	// ──────────────────────────────────────────────

	/**
	 * Create a new pattern in the database tier.
	 *
	 * If a pattern with the same ID already exists in any tier, auto-suffixes (-v2, -v3, ...).
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
	 * Update an existing database-tier pattern.
	 *
	 * Plugin and user-file patterns are read-only.
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

		$source = $existing['source'] ?? 'plugin';
		if ( 'database' !== $source ) {
			return new \WP_Error(
				'read_only_source',
				sprintf( 'Pattern "%s" is from a read-only source (%s). Only database patterns can be updated via MCP.', $id, $source )
			);
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
	 * Delete a pattern.
	 *
	 * Database patterns are removed. Plugin/user-file patterns are hidden.
	 *
	 * @param string $id Pattern ID.
	 * @return array|\WP_Error Result with action taken.
	 */
	public static function delete( string $id ): array|\WP_Error {
		$existing = self::get( $id );
		if ( null === $existing ) {
			return new \WP_Error( 'not_found', sprintf( 'Pattern "%s" not found.', $id ) );
		}

		$source = $existing['source'] ?? 'plugin';

		if ( 'database' === $source ) {
			// Remove from DB.
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

		// Plugin or user-file: hide instead of delete.
		$hidden   = get_option( self::HIDDEN_OPTION, [] );
		$hidden   = is_array( $hidden ) ? $hidden : [];
		$hidden[] = $id;
		$hidden   = array_unique( $hidden );
		update_option( self::HIDDEN_OPTION, $hidden, false );
		self::clear_cache();

		return [
			'id'     => $id,
			'action' => 'hidden',
			'source' => $source,
			'note'   => 'Plugin/user-file patterns cannot be deleted, only hidden from all lists. Remove from hidden list via design_pattern:update if needed.',
		];
	}

	// ──────────────────────────────────────────────
	// Semantic search
	// ──────────────────────────────────────────────

	/**
	 * Search patterns by natural language query.
	 *
	 * Heuristic scoring mirrors global_class:semantic_search.
	 *
	 * @param string $query Natural language query.
	 * @param int    $limit Max results (default 10).
	 * @return array{query: string, matches: array}
	 */
	public static function semantic_search( string $query, int $limit = 10 ): array {
		$raw_words = preg_split( '/[\s,]+/', strtolower( $query ) );
		$stopwords = [ 'a', 'an', 'the', 'with', 'and', 'or', 'for', 'in', 'on', 'to', 'of', 'is', 'has', 'that', 'this' ];
		$keywords  = array_values( array_filter(
			is_array( $raw_words ) ? $raw_words : [],
			fn( $w ) => strlen( $w ) > 1 && ! in_array( $w, $stopwords, true )
		) );

		if ( empty( $keywords ) ) {
			return [ 'query' => $query, 'matches' => [] ];
		}

		$all    = self::load_all();
		$scored = [];

		foreach ( $all as $pattern ) {
			$score   = 0;
			$reasons = [];
			$name_lc = strtolower( $pattern['name'] ?? '' );
			$id_lc   = strtolower( $pattern['id'] ?? '' );
			$desc_lc = strtolower( $pattern['ai_description'] ?? $pattern['description'] ?? '' );

			foreach ( $keywords as $kw ) {
				// Name contains keyword (10 pts).
				if ( str_contains( $name_lc, $kw ) ) {
					$score    += 10;
					$reasons[] = "name contains '{$kw}'";
				}

				// ID word-part stem match (5 pts).
				$id_parts = explode( '-', $id_lc );
				foreach ( $id_parts as $part ) {
					if ( strlen( $part ) > 2 && ( str_starts_with( $part, $kw ) || str_starts_with( $kw, $part ) ) ) {
						$score    += 5;
						$reasons[] = "id part '{$part}' matches '{$kw}'";
						break;
					}
				}

				// ai_description contains keyword (6 pts).
				if ( '' !== $desc_lc && str_contains( $desc_lc, $kw ) ) {
					$score    += 6;
					$reasons[] = "description contains '{$kw}'";
				}

				// Tags match (4 pts).
				foreach ( $pattern['tags'] ?? [] as $tag ) {
					if ( strtolower( $tag ) === $kw ) {
						$score    += 4;
						$reasons[] = "tags match '{$kw}'";
						break;
					}
				}

				// Category match (3 pts).
				if ( strtolower( $pattern['category'] ?? '' ) === $kw ) {
					$score    += 3;
					$reasons[] = "category matches '{$kw}'";
				}

				// Layout or background match (2 pts).
				if ( strtolower( $pattern['layout'] ?? '' ) === $kw || strtolower( $pattern['background'] ?? '' ) === $kw ) {
					$score    += 2;
					$reasons[] = "layout/background matches '{$kw}'";
				}
			}

			if ( $score > 0 ) {
				$scored[] = [
					'id'              => $pattern['id'] ?? '',
					'name'            => $pattern['name'] ?? '',
					'score'           => $score,
					'match_reasons'   => array_unique( $reasons ),
					'category'        => $pattern['category'] ?? '',
					'layout'          => $pattern['layout'] ?? '',
					'source'          => $pattern['source'] ?? 'plugin',
					'ai_description'  => $pattern['ai_description'] ?? null,
				];
			}
		}

		usort( $scored, fn( $a, $b ) => $b['score'] <=> $a['score'] );

		return [
			'query'   => $query,
			'matches' => array_slice( $scored, 0, $limit ),
		];
	}

	// ──────────────────────────────────────────────
	// Export / Import
	// ──────────────────────────────────────────────

	/**
	 * Export patterns as a portable JSON array.
	 *
	 * @param array<string> $ids Pattern IDs to export. Empty = all DB patterns.
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
			// If no specific IDs, export DB patterns only.
			if ( empty( $ids ) && ( $pattern['source'] ?? '' ) !== 'database' ) {
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
}
