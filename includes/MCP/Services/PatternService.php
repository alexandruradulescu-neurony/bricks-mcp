<?php
/**
 * Pattern library service for site analysis and pattern reuse.
 *
 * Scans Bricks pages, extracts reusable section patterns, and provides
 * pattern instantiation with placeholder replacement.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PatternService class.
 *
 * Provides site pattern analysis, pattern saving, and pattern instantiation.
 */
class PatternService {

	/**
	 * Option key for storing discovered and saved patterns.
	 *
	 * @var string
	 */
	private const PATTERNS_OPTION = 'bricks_mcp_patterns';

	/**
	 * Maximum number of posts to scan during analysis.
	 *
	 * @var int
	 */
	private const MAX_SCAN_POSTS = 100;

	/**
	 * Maximum depth for structure string generation.
	 *
	 * @var int
	 */
	private const MAX_STRUCTURE_DEPTH = 3;

	/**
	 * Content setting keys that should be replaced with placeholders.
	 *
	 * @var array<int, string>
	 */
	private const CONTENT_KEYS = [ 'text', 'content', 'title', 'subtitle', 'buttonText' ];

	/**
	 * Core infrastructure.
	 *
	 * @var BricksCore
	 */
	private BricksCore $core;

	/**
	 * Constructor.
	 *
	 * @param BricksCore $core Shared infrastructure.
	 */
	public function __construct( BricksCore $core ) {
		$this->core = $core;
	}

	/**
	 * Analyze all Bricks pages and extract reusable section patterns.
	 *
	 * Scans published posts with Bricks content, fingerprints top-level sections
	 * by their class combinations, groups similar ones, and stores patterns.
	 *
	 * @return array{patterns: array, class_cooccurrence: array, pages_scanned: int}
	 */
	public function analyze_patterns(): array {
		$posts = $this->get_bricks_posts();

		$section_groups    = [];
		$cooccurrence_raw  = [];
		$class_name_map    = $this->build_class_name_map();
		$pages_scanned     = 0;

		foreach ( $posts as $post_id ) {
			$elements = $this->core->get_elements( $post_id );

			if ( empty( $elements ) ) {
				continue;
			}

			++$pages_scanned;

			// Build element lookup by ID.
			$by_id = [];
			foreach ( $elements as $el ) {
				$by_id[ $el['id'] ] = $el;
			}

			// Find top-level sections (parent = 0, name = section).
			foreach ( $elements as $el ) {
				if ( 0 !== $el['parent'] && '0' !== (string) $el['parent'] ) {
					continue;
				}
				if ( ( $el['name'] ?? '' ) !== 'section' ) {
					continue;
				}

				// Collect all descendant elements.
				$descendants = $this->collect_descendants( $el['id'], $by_id );
				$subtree     = array_merge( [ $el ], $descendants );

				// Collect all class IDs from subtree.
				$class_ids = $this->collect_class_ids( $subtree );

				// Track co-occurrence.
				$this->track_cooccurrence( $class_ids, $cooccurrence_raw );

				// Map to class names for fingerprinting.
				$class_names = [];
				foreach ( $class_ids as $cid ) {
					if ( isset( $class_name_map[ $cid ] ) ) {
						$class_names[] = $class_name_map[ $cid ];
					}
				}
				sort( $class_names );

				$fingerprint = implode( '|', $class_names );

				if ( '' === $fingerprint ) {
					continue;
				}

				$pattern_id = 'pat_' . substr( md5( $fingerprint ), 0, 8 );

				if ( ! isset( $section_groups[ $pattern_id ] ) ) {
					$section_groups[ $pattern_id ] = [
						'id'               => $pattern_id,
						'fingerprint'      => $fingerprint,
						'found_on'         => [],
						'classes'          => $class_names,
						'first_section'    => $el,
						'first_subtree'    => $subtree,
						'first_by_id'      => $by_id,
					];
				}

				if ( ! in_array( $post_id, $section_groups[ $pattern_id ]['found_on'], true ) ) {
					$section_groups[ $pattern_id ]['found_on'][] = $post_id;
				}
			}
		}

		// Build final patterns array.
		$patterns = [];
		foreach ( $section_groups as $group ) {
			$section  = $group['first_section'];
			$subtree  = $group['first_subtree'];
			$by_id    = $group['first_by_id'];

			$patterns[] = [
				'id'               => $group['id'],
				'name'             => $this->generate_pattern_name( $group['classes'] ),
				'found_on'         => $group['found_on'],
				'occurrence_count' => count( $group['found_on'] ),
				'structure'        => $this->build_structure_string( $section, $by_id, 0 ),
				'classes'          => $group['classes'],
				'traits'           => $this->detect_traits( $subtree ),
				'element_template' => $this->templatize_subtree( $subtree ),
			];
		}

		// Sort by occurrence count descending.
		usort( $patterns, static fn( $a, $b ) => $b['occurrence_count'] <=> $a['occurrence_count'] );

		// Build class co-occurrence map.
		$class_cooccurrence = $this->build_cooccurrence_map( $cooccurrence_raw, $class_name_map );

		// Store patterns.
		update_option( self::PATTERNS_OPTION, $patterns, false );

		return [
			'patterns'           => $patterns,
			'class_cooccurrence' => $class_cooccurrence,
			'pages_scanned'      => $pages_scanned,
			'patterns_found'     => count( $patterns ),
		];
	}

	/**
	 * Save a specific element subtree as a named pattern.
	 *
	 * @param int    $post_id         Post ID containing the element.
	 * @param string $root_element_id Root element ID of the subtree to save.
	 * @param string $name            Pattern name.
	 * @return array<string, mixed>|\WP_Error Saved pattern data or error.
	 */
	public function save_pattern( int $post_id, string $root_element_id, string $name ): array|\WP_Error {
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}

		if ( '' === $root_element_id ) {
			return new \WP_Error( 'missing_root_element_id', __( 'root_element_id is required.', 'bricks-mcp' ) );
		}

		if ( '' === $name ) {
			return new \WP_Error( 'missing_name', __( 'name is required for the pattern.', 'bricks-mcp' ) );
		}

		$elements = $this->core->get_elements( $post_id );

		if ( empty( $elements ) ) {
			return new \WP_Error( 'no_elements', sprintf( __( 'No Bricks elements found on post %d.', 'bricks-mcp' ), $post_id ) );
		}

		// Build lookup.
		$by_id = [];
		foreach ( $elements as $el ) {
			$by_id[ $el['id'] ] = $el;
		}

		if ( ! isset( $by_id[ $root_element_id ] ) ) {
			return new \WP_Error(
				'element_not_found',
				sprintf( __( 'Element "%s" not found on post %d.', 'bricks-mcp' ), $root_element_id, $post_id )
			);
		}

		$root_element = $by_id[ $root_element_id ];
		$descendants  = $this->collect_descendants( $root_element_id, $by_id );
		$subtree      = array_merge( [ $root_element ], $descendants );

		// Collect classes.
		$class_ids      = $this->collect_class_ids( $subtree );
		$class_name_map = $this->build_class_name_map();
		$class_names    = [];
		foreach ( $class_ids as $cid ) {
			if ( isset( $class_name_map[ $cid ] ) ) {
				$class_names[] = $class_name_map[ $cid ];
			}
		}
		sort( $class_names );

		$fingerprint = implode( '|', $class_names );
		$pattern_id  = 'pat_' . substr( md5( $fingerprint . $name ), 0, 8 );

		$pattern = [
			'id'               => $pattern_id,
			'name'             => sanitize_text_field( $name ),
			'found_on'         => [ $post_id ],
			'occurrence_count' => 1,
			'structure'        => $this->build_structure_string( $root_element, $by_id, 0 ),
			'classes'          => $class_names,
			'traits'           => $this->detect_traits( $subtree ),
			'element_template' => $this->templatize_subtree( $subtree ),
			'saved_manually'   => true,
		];

		// Load existing patterns and append/update.
		$patterns = get_option( self::PATTERNS_OPTION, [] );
		if ( ! is_array( $patterns ) ) {
			$patterns = [];
		}

		// Replace if pattern ID already exists.
		$found = false;
		foreach ( $patterns as $idx => $existing ) {
			if ( ( $existing['id'] ?? '' ) === $pattern_id ) {
				$patterns[ $idx ] = $pattern;
				$found            = true;
				break;
			}
		}
		if ( ! $found ) {
			$patterns[] = $pattern;
		}

		update_option( self::PATTERNS_OPTION, $patterns, false );

		return $pattern;
	}

	/**
	 * Instantiate a pattern on a target page with placeholder overrides.
	 *
	 * Loads the pattern template, replaces placeholders with override values,
	 * generates fresh element IDs, and appends elements to the target page.
	 *
	 * @param string               $pattern_id Pattern ID to instantiate.
	 * @param int                  $post_id    Target post ID.
	 * @param array<string, mixed> $overrides  Placeholder overrides (e.g., ['heading' => 'My Title']).
	 * @return array<string, mixed>|\WP_Error Result with appended elements or error.
	 */
	public function use_pattern( string $pattern_id, int $post_id, array $overrides = [] ): array|\WP_Error {
		if ( '' === $pattern_id ) {
			return new \WP_Error( 'missing_pattern_id', __( 'pattern_id is required.', 'bricks-mcp' ) );
		}

		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', sprintf( __( 'Post %d not found.', 'bricks-mcp' ), $post_id ) );
		}

		$patterns = get_option( self::PATTERNS_OPTION, [] );
		if ( ! is_array( $patterns ) ) {
			$patterns = [];
		}

		// Find the pattern.
		$pattern = null;
		foreach ( $patterns as $p ) {
			if ( ( $p['id'] ?? '' ) === $pattern_id ) {
				$pattern = $p;
				break;
			}
		}

		if ( null === $pattern ) {
			return new \WP_Error(
				'pattern_not_found',
				sprintf( __( 'Pattern "%s" not found. Use analyze_patterns or save_pattern first.', 'bricks-mcp' ), $pattern_id )
			);
		}

		$template = $pattern['element_template'] ?? [];

		if ( empty( $template ) ) {
			return new \WP_Error( 'empty_template', __( 'Pattern has an empty element template.', 'bricks-mcp' ) );
		}

		// Clone template and replace placeholders.
		$elements = $this->replace_placeholders( $template, $overrides );

		// Generate fresh IDs and remap parent/children references.
		$existing_elements = $this->core->get_elements( $post_id );
		$elements          = $this->regenerate_ids( $elements, $existing_elements );

		// Normalize parent of root element(s) to root level (0).
		foreach ( $elements as &$el ) {
			if ( ! $this->has_parent_in_set( $el, $elements ) ) {
				$el['parent'] = 0;
			}
		}
		unset( $el );

		// Merge and save.
		$normalizer = $this->core->get_normalizer();
		$merged     = $normalizer->merge_elements( $existing_elements, $elements, '0', null );
		$saved      = $this->core->save_elements( $post_id, $merged );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Collect root-level appended IDs.
		$appended_ids = [];
		foreach ( $elements as $el ) {
			if ( 0 === $el['parent'] || '0' === (string) $el['parent'] ) {
				$appended_ids[] = $el['id'];
			}
		}

		return [
			'pattern_id'          => $pattern_id,
			'pattern_name'        => $pattern['name'] ?? '',
			'post_id'             => $post_id,
			'appended_ids'        => $appended_ids,
			'appended_count'      => count( $elements ),
			'total_element_count' => count( $merged ),
			'placeholders_used'   => array_keys( $overrides ),
		];
	}

	/**
	 * Get class co-occurrence data from all Bricks pages.
	 *
	 * Returns a map of class_name to co-occurring class names, based on
	 * which classes appear together in the same element subtrees.
	 *
	 * @return array<string, array<int, string>> Map of class name to co-occurring class names.
	 */
	public function get_class_cooccurrence(): array {
		$posts         = $this->get_bricks_posts();
		$class_name_map = $this->build_class_name_map();
		$cooccurrence_raw = [];

		foreach ( $posts as $post_id ) {
			$elements = $this->core->get_elements( $post_id );

			if ( empty( $elements ) ) {
				continue;
			}

			$by_id = [];
			foreach ( $elements as $el ) {
				$by_id[ $el['id'] ] = $el;
			}

			// Find top-level sections.
			foreach ( $elements as $el ) {
				if ( 0 !== $el['parent'] && '0' !== (string) $el['parent'] ) {
					continue;
				}

				$descendants = $this->collect_descendants( $el['id'], $by_id );
				$subtree     = array_merge( [ $el ], $descendants );
				$class_ids   = $this->collect_class_ids( $subtree );

				$this->track_cooccurrence( $class_ids, $cooccurrence_raw );
			}
		}

		return $this->build_cooccurrence_map( $cooccurrence_raw, $class_name_map );
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Get all published post IDs with Bricks content.
	 *
	 * @return array<int, int> Array of post IDs.
	 */
	private function get_bricks_posts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance: single indexed query is faster than WP_Query for meta-only check.
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_status = 'publish'
				AND pm.meta_key = %s
				LIMIT %d",
				BricksCore::META_KEY,
				self::MAX_SCAN_POSTS
			)
		);

		return array_map( 'intval', $results );
	}

	/**
	 * Build a map of global class ID to class name.
	 *
	 * @return array<string, string> Class ID to name map.
	 */
	private function build_class_name_map(): array {
		$classes = get_option( 'bricks_global_classes', [] );

		if ( ! is_array( $classes ) ) {
			return [];
		}

		$map = [];
		foreach ( $classes as $class ) {
			if ( isset( $class['id'], $class['name'] ) ) {
				$map[ $class['id'] ] = $class['name'];
			}
		}

		return $map;
	}

	/**
	 * Collect all descendant elements of a given element via BFS.
	 *
	 * @param string                           $element_id Root element ID.
	 * @param array<string, array<string, mixed>> $by_id   Elements indexed by ID.
	 * @return array<int, array<string, mixed>> Flat array of descendant elements.
	 */
	private function collect_descendants( string $element_id, array $by_id ): array {
		$descendants = [];
		$queue       = $by_id[ $element_id ]['children'] ?? [];

		while ( ! empty( $queue ) ) {
			$child_id = array_shift( $queue );

			if ( ! isset( $by_id[ $child_id ] ) ) {
				continue;
			}

			$child         = $by_id[ $child_id ];
			$descendants[] = $child;

			if ( ! empty( $child['children'] ) ) {
				foreach ( $child['children'] as $grandchild_id ) {
					$queue[] = $grandchild_id;
				}
			}
		}

		return $descendants;
	}

	/**
	 * Collect all unique global class IDs from a set of elements.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements to scan.
	 * @return array<int, string> Unique class IDs.
	 */
	private function collect_class_ids( array $elements ): array {
		$class_ids = [];

		foreach ( $elements as $el ) {
			$settings = $el['settings'] ?? [];
			$classes  = $settings['_cssGlobalClasses'] ?? [];

			if ( is_array( $classes ) ) {
				foreach ( $classes as $cid ) {
					if ( is_string( $cid ) && '' !== $cid ) {
						$class_ids[ $cid ] = true;
					}
				}
			}
		}

		return array_keys( $class_ids );
	}

	/**
	 * Track which class IDs co-occur within the same subtree.
	 *
	 * @param array<int, string>                         $class_ids       Class IDs from a subtree.
	 * @param array<string, array<string, int>>          &$cooccurrence   Running co-occurrence counts.
	 * @return void
	 */
	private function track_cooccurrence( array $class_ids, array &$cooccurrence ): void {
		$count = count( $class_ids );

		for ( $i = 0; $i < $count; $i++ ) {
			$a = $class_ids[ $i ];

			if ( ! isset( $cooccurrence[ $a ] ) ) {
				$cooccurrence[ $a ] = [];
			}

			for ( $j = 0; $j < $count; $j++ ) {
				if ( $i === $j ) {
					continue;
				}

				$b = $class_ids[ $j ];

				if ( ! isset( $cooccurrence[ $a ][ $b ] ) ) {
					$cooccurrence[ $a ][ $b ] = 0;
				}

				++$cooccurrence[ $a ][ $b ];
			}
		}
	}

	/**
	 * Build the final co-occurrence map with class names, sorted by frequency.
	 *
	 * @param array<string, array<string, int>> $raw            Raw co-occurrence counts by class ID.
	 * @param array<string, string>             $class_name_map Class ID to name map.
	 * @return array<string, array<int, string>> Map of class name to sorted co-occurring class names.
	 */
	private function build_cooccurrence_map( array $raw, array $class_name_map ): array {
		$result = [];

		foreach ( $raw as $class_id => $peers ) {
			$class_name = $class_name_map[ $class_id ] ?? null;

			if ( null === $class_name ) {
				continue;
			}

			// Sort peers by co-occurrence count descending.
			arsort( $peers );

			$peer_names = [];
			foreach ( $peers as $peer_id => $count ) {
				$peer_name = $class_name_map[ $peer_id ] ?? null;
				if ( null !== $peer_name ) {
					$peer_names[] = $peer_name;
				}
			}

			if ( ! empty( $peer_names ) ) {
				// Limit to top 10 co-occurring classes.
				$result[ $class_name ] = array_slice( $peer_names, 0, 10 );
			}
		}

		return $result;
	}

	/**
	 * Generate a human-readable pattern name from class names.
	 *
	 * Strips common prefixes (brxw-, brx-) and picks the most descriptive class.
	 *
	 * @param array<int, string> $class_names Class names used in the pattern.
	 * @return string Generated pattern name.
	 */
	private function generate_pattern_name( array $class_names ): string {
		if ( empty( $class_names ) ) {
			return 'Unnamed Pattern';
		}

		// Strip common Bricks prefixes and find meaningful names.
		$cleaned = [];
		foreach ( $class_names as $name ) {
			$clean = $name;
			$clean = (string) preg_replace( '/^brxw-/', '', $clean );
			$clean = (string) preg_replace( '/^brx-/', '', $clean );
			$clean = (string) preg_replace( '/^brxe-/', '', $clean );

			// Skip very generic utility names.
			if ( in_array( $clean, [ 'w-100', 'h-100', 'd-flex', 'p-0', 'm-0' ], true ) ) {
				continue;
			}

			$cleaned[] = $clean;
		}

		if ( empty( $cleaned ) ) {
			return 'Unnamed Pattern';
		}

		// Pick the first non-utility class name, preferring longer/more descriptive names.
		usort( $cleaned, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		// Humanize: replace dashes/underscores with spaces, title case.
		$primary = $cleaned[0];
		$primary = str_replace( [ '-', '_' ], ' ', $primary );
		$primary = ucwords( $primary );

		// If we have a second distinctive name, append it.
		if ( count( $cleaned ) > 1 && $cleaned[1] !== $cleaned[0] ) {
			$secondary = str_replace( [ '-', '_' ], ' ', $cleaned[1] );
			$secondary = ucwords( $secondary );

			return $primary . ' / ' . $secondary;
		}

		return $primary;
	}

	/**
	 * Build a simplified structure string for a section element tree.
	 *
	 * Format: "section > container > [heading(h2), text-basic, block > image]"
	 *
	 * @param array<string, mixed>                    $element Current element.
	 * @param array<string, array<string, mixed>>     $by_id   All elements indexed by ID.
	 * @param int                                     $depth   Current depth.
	 * @return string Structure notation.
	 */
	private function build_structure_string( array $element, array $by_id, int $depth ): string {
		$name     = $element['name'] ?? 'unknown';
		$tag      = $element['settings']['tag'] ?? '';
		$label    = $element['label'] ?? '';
		$children = $element['children'] ?? [];

		// Build this node's representation.
		$node = $name;
		if ( '' !== $tag && $tag !== $name ) {
			$node .= '(' . $tag . ')';
		} elseif ( '' !== $label ) {
			$node .= '(' . $label . ')';
		}

		// Stop at max depth or no children.
		if ( $depth >= self::MAX_STRUCTURE_DEPTH || empty( $children ) ) {
			return $node;
		}

		// Recurse into children.
		$child_strings = [];
		foreach ( $children as $child_id ) {
			if ( isset( $by_id[ $child_id ] ) ) {
				$child_strings[] = $this->build_structure_string( $by_id[ $child_id ], $by_id, $depth + 1 );
			}
		}

		if ( empty( $child_strings ) ) {
			return $node;
		}

		if ( count( $child_strings ) === 1 ) {
			return $node . ' > ' . $child_strings[0];
		}

		return $node . ' > [' . implode( ', ', $child_strings ) . ']';
	}

	/**
	 * Detect visual traits of a section pattern.
	 *
	 * Looks for dark/light backgrounds, grid layouts, rounded corners, overlays, etc.
	 *
	 * @param array<int, array<string, mixed>> $subtree All elements in the subtree.
	 * @return array<string, bool> Detected traits.
	 */
	private function detect_traits( array $subtree ): array {
		$traits = [
			'has_dark_bg'       => false,
			'has_light_bg'      => false,
			'has_grid_layout'   => false,
			'has_min_height'    => false,
			'has_rounded'       => false,
			'has_overlay'       => false,
			'has_background_image' => false,
		];

		foreach ( $subtree as $el ) {
			$settings = $el['settings'] ?? [];

			// Check background color for dark/light.
			$bg_color = $settings['_background']['color'] ?? ( $settings['_backgroundColor'] ?? '' );
			if ( is_string( $bg_color ) && '' !== $bg_color ) {
				if ( preg_match( '/(dark|black|#[0-3])/i', $bg_color ) ) {
					$traits['has_dark_bg'] = true;
				}
				if ( preg_match( '/(light|white|#[ef])/i', $bg_color ) ) {
					$traits['has_light_bg'] = true;
				}
			}

			// Check for dark via CSS variables.
			$global_classes = $settings['_cssGlobalClasses'] ?? [];
			if ( is_array( $global_classes ) ) {
				foreach ( $global_classes as $class_id ) {
					if ( is_string( $class_id ) && ( str_contains( $class_id, 'dark' ) || str_contains( $class_id, 'light' ) ) ) {
						// Class IDs typically don't contain these words, but class names might.
						// This is a heuristic.
					}
				}
			}

			// Check for grid layout.
			$display = $settings['_display'] ?? '';
			if ( 'grid' === $display ) {
				$traits['has_grid_layout'] = true;
			}
			$css_custom = $settings['_cssCustom'] ?? '';
			if ( is_string( $css_custom ) && str_contains( $css_custom, 'grid' ) ) {
				$traits['has_grid_layout'] = true;
			}

			// Check min-height.
			if ( isset( $settings['_height'] ) || isset( $settings['_minHeight'] ) ) {
				$traits['has_min_height'] = true;
			}

			// Check border-radius / rounded.
			if ( isset( $settings['_border']['radius'] ) || isset( $settings['_borderRadius'] ) ) {
				$traits['has_rounded'] = true;
			}

			// Check overlay.
			if ( isset( $settings['_overlay'] ) || isset( $settings['hasOverlay'] ) ) {
				$traits['has_overlay'] = true;
			}

			// Check background image.
			if ( isset( $settings['_background']['image'] ) || isset( $settings['_backgroundImage'] ) ) {
				$traits['has_background_image'] = true;
			}
		}

		// Remove false traits for compact output.
		return array_filter( $traits );
	}

	/**
	 * Create a templatized copy of a subtree with text content replaced by placeholders.
	 *
	 * Preserves structure, classes, tags, labels. Replaces text/content settings
	 * with {{placeholder}} notation.
	 *
	 * @param array<int, array<string, mixed>> $subtree Flat array of elements in the subtree.
	 * @return array<int, array<string, mixed>> Templatized elements.
	 */
	private function templatize_subtree( array $subtree ): array {
		$template   = [];
		$type_counts = [];

		foreach ( $subtree as $el ) {
			$clone    = $el;
			$settings = $clone['settings'] ?? [];
			$name     = $clone['name'] ?? 'element';

			foreach ( self::CONTENT_KEYS as $key ) {
				if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
					// Determine placeholder name.
					$placeholder_base = $this->get_placeholder_name( $name, $key );

					if ( ! isset( $type_counts[ $placeholder_base ] ) ) {
						$type_counts[ $placeholder_base ] = 0;
					}
					++$type_counts[ $placeholder_base ];

					if ( $type_counts[ $placeholder_base ] > 1 ) {
						$placeholder = '{{' . $placeholder_base . '_' . $type_counts[ $placeholder_base ] . '}}';
					} else {
						$placeholder = '{{' . $placeholder_base . '}}';
					}

					$settings[ $key ] = $placeholder;
				}
			}

			$clone['settings'] = $settings;
			$template[]        = $clone;
		}

		// Fix numbering: if any base had count > 1, rename the first occurrence to _1.
		foreach ( $template as &$el ) {
			$settings = $el['settings'] ?? [];

			foreach ( self::CONTENT_KEYS as $key ) {
				if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
					foreach ( $type_counts as $base => $count ) {
						if ( $count > 1 && $settings[ $key ] === '{{' . $base . '}}' ) {
							$settings[ $key ] = '{{' . $base . '_1}}';
						}
					}
				}
			}

			$el['settings'] = $settings;
		}
		unset( $el );

		return $template;
	}

	/**
	 * Get a placeholder name based on element type and setting key.
	 *
	 * @param string $element_name Element name (e.g., 'heading', 'text-basic').
	 * @param string $setting_key  Setting key (e.g., 'text', 'content').
	 * @return string Placeholder base name.
	 */
	private function get_placeholder_name( string $element_name, string $setting_key ): string {
		// Map element types to meaningful placeholder names.
		$name_map = [
			'heading'    => 'heading',
			'text-basic' => 'text',
			'text'       => 'text',
			'button'     => 'button_text',
			'image'      => 'image',
		];

		if ( isset( $name_map[ $element_name ] ) ) {
			return $name_map[ $element_name ];
		}

		// For text/content keys on unknown elements, use the element name.
		if ( 'text' === $setting_key || 'content' === $setting_key ) {
			return str_replace( '-', '_', $element_name );
		}

		return $setting_key;
	}

	/**
	 * Replace {{placeholder}} values in element settings with overrides.
	 *
	 * @param array<int, array<string, mixed>> $template  Templatized elements.
	 * @param array<string, mixed>             $overrides Placeholder name to value map.
	 * @return array<int, array<string, mixed>> Elements with replacements applied.
	 */
	private function replace_placeholders( array $template, array $overrides ): array {
		$elements = [];

		foreach ( $template as $el ) {
			$clone    = $el;
			$settings = $clone['settings'] ?? [];

			foreach ( self::CONTENT_KEYS as $key ) {
				if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
					// Check if the value is a placeholder.
					if ( preg_match( '/^\{\{(\w+)\}\}$/', $settings[ $key ], $matches ) ) {
						$placeholder_name = $matches[1];

						if ( isset( $overrides[ $placeholder_name ] ) ) {
							$settings[ $key ] = sanitize_text_field( (string) $overrides[ $placeholder_name ] );
						}
					}
				}
			}

			$clone['settings'] = $settings;
			$elements[]        = $clone;
		}

		return $elements;
	}

	/**
	 * Regenerate all element IDs in a set and remap parent/children references.
	 *
	 * @param array<int, array<string, mixed>> $elements          Elements to re-ID.
	 * @param array<int, array<string, mixed>> $existing_elements Existing elements for collision avoidance.
	 * @return array<int, array<string, mixed>> Elements with fresh IDs.
	 */
	private function regenerate_ids( array $elements, array $existing_elements ): array {
		$id_generator = new ElementIdGenerator();
		$id_map       = [];

		// First pass: generate new IDs.
		$all_for_collision = $existing_elements;
		foreach ( $elements as $el ) {
			$old_id = $el['id'];
			$new_id = $id_generator->generate_unique(
				array_merge(
					$all_for_collision,
					array_map( static fn( $id ) => [ 'id' => $id ], array_values( $id_map ) )
				)
			);
			$id_map[ $old_id ] = $new_id;
		}

		// Second pass: remap IDs, parents, children, and _cssCustom references.
		$result = [];
		foreach ( $elements as $el ) {
			$old_id    = $el['id'];
			$el['id']  = $id_map[ $old_id ];

			// Remap parent.
			$parent_str = (string) $el['parent'];
			if ( isset( $id_map[ $parent_str ] ) ) {
				$el['parent'] = $id_map[ $parent_str ];
			}

			// Remap children.
			$el['children'] = array_map(
				static fn( string $cid ) => $id_map[ $cid ] ?? $cid,
				$el['children'] ?? []
			);

			// Update _cssCustom references.
			if ( ! empty( $el['settings']['_cssCustom'] ) && is_string( $el['settings']['_cssCustom'] ) ) {
				foreach ( $id_map as $old => $new ) {
					$el['settings']['_cssCustom'] = str_replace(
						'#brxe-' . $old,
						'#brxe-' . $new,
						$el['settings']['_cssCustom']
					);
				}
			}

			$result[] = $el;
		}

		return $result;
	}

	/**
	 * Check if an element's parent exists within the provided element set.
	 *
	 * @param array<string, mixed>             $element  Element to check.
	 * @param array<int, array<string, mixed>> $elements Set of elements.
	 * @return bool True if parent is found in the set.
	 */
	private function has_parent_in_set( array $element, array $elements ): bool {
		$parent = (string) $element['parent'];

		if ( '0' === $parent || '' === $parent ) {
			return false;
		}

		foreach ( $elements as $el ) {
			if ( $el['id'] === $parent ) {
				return true;
			}
		}

		return false;
	}
}
