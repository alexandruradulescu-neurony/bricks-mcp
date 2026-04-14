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

	/** WP option key for the category registry. */
	private const CATEGORY_OPTION = 'bricks_mcp_pattern_categories';

	/** WP option key for the one-time migration flag. */
	private const MIGRATION_FLAG = 'bricks_mcp_patterns_migrated';

	/** Default categories seeded on first migration. */
	private const DEFAULT_CATEGORIES = [
		[ 'id' => 'hero',         'name' => 'Hero',         'description' => 'Full-height homepage hero sections' ],
		[ 'id' => 'features',     'name' => 'Features',     'description' => 'Feature grids and benefit sections' ],
		[ 'id' => 'cta',          'name' => 'CTA',          'description' => 'Call-to-action sections' ],
		[ 'id' => 'pricing',      'name' => 'Pricing',      'description' => 'Pricing tables and plan comparisons' ],
		[ 'id' => 'testimonials', 'name' => 'Testimonials', 'description' => 'Social proof and review sections' ],
		[ 'id' => 'splits',       'name' => 'Splits',       'description' => 'Split-layout content sections' ],
		[ 'id' => 'content',      'name' => 'Content',      'description' => 'General content sections (FAQ, stats, team)' ],
		[ 'id' => 'generic',      'name' => 'Generic',      'description' => 'Catch-all section type' ],
	];

	// ──────────────────────────────────────────────
	// Loading — database-first with file fallback
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

		// Tier 1: Plugin-shipped patterns — only loaded as fallback before migration.
		// After migration runs, all plugin patterns live in the database tier.
		if ( ! get_option( self::MIGRATION_FLAG, false ) ) {
			$plugin_dir = dirname( __DIR__, 3 ) . '/data/design-patterns/';
			self::load_from_directory( $plugin_dir, 'plugin', $by_id );
		}

		// Tier 2: User custom patterns from uploads directory (always active).
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

		// Validate category against registry.
		$categories = self::get_categories();
		$cat_ids    = array_column( $categories, 'id' );
		if ( ! in_array( $pattern['category'], $cat_ids, true ) ) {
			return new \WP_Error(
				'invalid_category',
				sprintf( 'Category "%s" is not registered. Available: %s. Create it first via category management.', $pattern['category'], implode( ', ', $cat_ids ) )
			);
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

		// Validate category if being changed.
		if ( isset( $updates['category'] ) ) {
			$categories = self::get_categories();
			$cat_ids    = array_column( $categories, 'id' );
			if ( ! in_array( $updates['category'], $cat_ids, true ) ) {
				return new \WP_Error(
					'invalid_category',
					sprintf( 'Category "%s" is not registered. Available: %s.', $updates['category'], implode( ', ', $cat_ids ) )
				);
			}
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

	// ──────────────────────────────────────────────
	// Category registry
	// ──────────────────────────────────────────────

	/**
	 * Get all registered categories.
	 *
	 * Returns the registry from wp_options. Falls back to DEFAULT_CATEGORIES
	 * if the option doesn't exist (pre-migration state).
	 *
	 * @return array<int, array{id: string, name: string, description: string}>
	 */
	public static function get_categories(): array {
		$cats = get_option( self::CATEGORY_OPTION, null );
		if ( is_array( $cats ) && ! empty( $cats ) ) {
			return $cats;
		}
		return self::DEFAULT_CATEGORIES;
	}

	/**
	 * Create a new category.
	 *
	 * @param array{id?: string, name: string, description?: string} $category Category data.
	 * @return array|\WP_Error The created category or error.
	 */
	public static function create_category( array $category ): array|\WP_Error {
		if ( empty( $category['name'] ) ) {
			return new \WP_Error( 'missing_name', 'Category name is required.' );
		}

		// Auto-generate ID from name if not provided.
		if ( empty( $category['id'] ) ) {
			$category['id'] = sanitize_title( $category['name'] );
		}

		if ( ! preg_match( '/^[a-z0-9-]+$/', $category['id'] ) ) {
			return new \WP_Error( 'invalid_id', 'Category ID must be lowercase alphanumeric with hyphens only.' );
		}

		$existing = self::get_categories();
		$ids      = array_column( $existing, 'id' );

		if ( in_array( $category['id'], $ids, true ) ) {
			return new \WP_Error( 'duplicate_id', sprintf( 'Category "%s" already exists.', $category['id'] ) );
		}

		$new_cat = [
			'id'          => $category['id'],
			'name'        => sanitize_text_field( $category['name'] ),
			'description' => sanitize_text_field( $category['description'] ?? '' ),
		];

		$existing[] = $new_cat;
		update_option( self::CATEGORY_OPTION, $existing, false );

		return $new_cat;
	}

	/**
	 * Update an existing category.
	 *
	 * @param string $id      Category ID.
	 * @param array  $updates Fields to update (name, description).
	 * @return array|\WP_Error Updated category or error.
	 */
	public static function update_category( string $id, array $updates ): array|\WP_Error {
		$categories = self::get_categories();
		$found      = false;

		foreach ( $categories as &$cat ) {
			if ( ( $cat['id'] ?? '' ) === $id ) {
				if ( isset( $updates['name'] ) ) {
					$cat['name'] = sanitize_text_field( $updates['name'] );
				}
				if ( isset( $updates['description'] ) ) {
					$cat['description'] = sanitize_text_field( $updates['description'] );
				}
				$found = true;
				$updated_cat = $cat;
				break;
			}
		}
		unset( $cat );

		if ( ! $found ) {
			return new \WP_Error( 'not_found', sprintf( 'Category "%s" not found.', $id ) );
		}

		update_option( self::CATEGORY_OPTION, $categories, false );

		return $updated_cat;
	}

	/**
	 * Delete a category from the registry.
	 *
	 * Patterns using this category keep their category string but it becomes
	 * unregistered. The category can be re-created to re-associate them.
	 *
	 * @param string $id Category ID.
	 * @return array|\WP_Error Result with pattern count warning.
	 */
	public static function delete_category( string $id ): array|\WP_Error {
		$categories = self::get_categories();
		$new_cats   = [];
		$found      = false;

		foreach ( $categories as $cat ) {
			if ( ( $cat['id'] ?? '' ) === $id ) {
				$found = true;
				continue;
			}
			$new_cats[] = $cat;
		}

		if ( ! $found ) {
			return new \WP_Error( 'not_found', sprintf( 'Category "%s" not found.', $id ) );
		}

		// Count patterns using this category.
		$all            = self::load_all();
		$affected_count = 0;
		foreach ( $all as $pattern ) {
			if ( ( $pattern['category'] ?? '' ) === $id ) {
				$affected_count++;
			}
		}

		update_option( self::CATEGORY_OPTION, $new_cats, false );

		$result = [ 'id' => $id, 'action' => 'deleted' ];
		if ( $affected_count > 0 ) {
			$result['warning'] = sprintf(
				'%d pattern(s) still use category "%s". They will appear as uncategorized until the category is re-created or patterns are re-categorized.',
				$affected_count,
				$id
			);
		}

		return $result;
	}

	/**
	 * Seed the category registry with defaults (idempotent).
	 *
	 * Called during migration. Skips if the option already exists.
	 */
	public static function seed_categories(): void {
		if ( false !== get_option( self::CATEGORY_OPTION, false ) ) {
			return;
		}
		update_option( self::CATEGORY_OPTION, self::DEFAULT_CATEGORIES, false );
	}

	/**
	 * Run the one-time migration of plugin-shipped patterns to the database.
	 *
	 * Reads all patterns from data/design-patterns/*.json, inserts any that
	 * don't already exist in the DB tier, seeds the category registry, and
	 * sets the migration flag.
	 */
	public static function migrate_plugin_patterns(): void {
		if ( get_option( self::MIGRATION_FLAG, false ) ) {
			return;
		}

		// Read plugin-shipped patterns.
		$plugin_patterns = [];
		$plugin_dir      = dirname( __DIR__, 3 ) . '/data/design-patterns/';
		if ( is_dir( $plugin_dir ) ) {
			$files = glob( $plugin_dir . '*.json' );
			if ( is_array( $files ) ) {
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
						$pattern['source']   = 'database';
						$plugin_patterns[]   = $pattern;
					}
				}
			}
		}

		// Merge into existing DB patterns (skip duplicates by ID).
		$db_patterns = get_option( self::DB_OPTION, [] );
		$db_patterns = is_array( $db_patterns ) ? $db_patterns : [];
		$existing_ids = [];
		foreach ( $db_patterns as $p ) {
			$existing_ids[ $p['id'] ?? '' ] = true;
		}

		$added = 0;
		foreach ( $plugin_patterns as $pp ) {
			if ( ! isset( $existing_ids[ $pp['id'] ] ) ) {
				$db_patterns[] = $pp;
				$added++;
			}
		}

		if ( $added > 0 ) {
			update_option( self::DB_OPTION, $db_patterns, false );
		}

		// Seed categories.
		self::seed_categories();

		// Set migration flag.
		update_option( self::MIGRATION_FLAG, defined( 'BRICKS_MCP_VERSION' ) ? BRICKS_MCP_VERSION : '3.10.0', false );

		// Clear cache so new patterns are visible.
		self::clear_cache();
	}
}
