<?php
/**
 * Global CSS class management sub-service.
 *
 * Handles CRUD for Bricks global classes, categories, CSS import/export,
 * and class-element associations.
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
 * GlobalClassService class.
 */
class GlobalClassService {

	/**
	 * Maximum number of posts to scan when finding class references.
	 */
	private const MAX_REFERENCE_POSTS = 200;

	/**
	 * Static cache of all global classes. Loaded once per PHP request.
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $cached_all = null;

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
	 * Get all global CSS classes, optionally filtered by search term.
	 *
	 * @param string $search   Optional partial name match filter.
	 * @param string $category Optional category ID filter.
	 * @return array<int, array<string, mixed>> Array of global classes.
	 */
	public function get_global_classes( string $search = '', string $category = '' ): array {
		// Use static cache — loaded once per request, invalidated on write.
		if ( null === self::$cached_all ) {
			$raw = get_option( BricksCore::OPTION_GLOBAL_CLASSES, [] );
			self::$cached_all = is_array( $raw )
				? array_values( array_filter(
					$raw,
					static fn( $entry ) => is_array( $entry ) && isset( $entry['id'], $entry['name'] ) && is_string( $entry['id'] ) && is_string( $entry['name'] )
				) )
				: [];
		}

		$classes = self::$cached_all;

		if ( '' !== $category ) {
			$classes = array_filter(
				$classes,
				static fn( array $class ) => ( $class['category'] ?? '' ) === $category
			);
		}

		if ( '' !== $search ) {
			$classes = array_filter(
				$classes,
				static fn( array $class ) => false !== stripos( $class['name'] ?? '', $search )
			);
		}

		return array_values( $classes );
	}

	/**
	 * Clear the static class cache. Called after write operations.
	 */
	public static function clear_cache(): void {
		self::$cached_all = null;
	}

	/**
	 * Create a new global CSS class.
	 *
	 * @param array<string, mixed> $args Class creation arguments.
	 * @return array<string, mixed>|\WP_Error Created class array or WP_Error on failure.
	 */
	public function create_global_class( array $args ): array|\WP_Error {
		if ( ! $this->core->acquire_lock( 'global_classes' ) ) {
			return new \WP_Error( 'concurrent_write', __( 'Another global class write is in progress. Please retry.', 'bricks-mcp' ) );
		}

		try {
		$classes = get_option( BricksCore::OPTION_GLOBAL_CLASSES, [] );
		if ( ! is_array( $classes ) ) {
			$classes = [];
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'Class name is required. Provide a non-empty "name" parameter.', 'bricks-mcp' )
			);
		}

		$name = sanitize_text_field( $args['name'] );

		if ( '' === $name ) {
			return new \WP_Error(
				'missing_name',
				__( 'Class name is required. Provide a non-empty "name" parameter.', 'bricks-mcp' )
			);
		}

		$existing_names = array_column( $classes, 'name' );
		if ( in_array( $name, $existing_names, true ) ) {
			return new \WP_Error(
				'duplicate_name',
				sprintf(
					__( 'A global class named "%s" already exists. Use update_global_class to modify it.', 'bricks-mcp' ),
					$name
				)
			);
		}

		$id_generator = new ElementIdGenerator();
		$existing_ids = array_column( $classes, 'id' );
		do {
			$new_id = $id_generator->generate();
		} while ( in_array( $new_id, $existing_ids, true ) );

		// Sanitize + normalize styles. Normalization collapses per-side structures
		// that Bricks expects as scalars (e.g. _border.style must be "dashed",
		// not {top: "dashed", right: ...}). Without this, malformed styles crash
		// the frontend with "Array to string conversion" during render.
		$sanitized_styles = $this->core->sanitize_styles_array( $args['styles'] ?? [] );
		$normalized       = $this->normalize_bricks_styles( $sanitized_styles );
		$new_class = [
			'id'       => $new_id,
			'name'     => $name,
			'color'    => isset( $args['color'] ) ? sanitize_text_field( $args['color'] ) : '#686868',
			'settings' => $normalized['styles'],
		];

		if ( ! empty( $args['category'] ) ) {
			$new_class['category'] = sanitize_text_field( $args['category'] );
		}

		$classes[] = $new_class;
		update_option( BricksCore::OPTION_GLOBAL_CLASSES, $classes );
		update_option( BricksCore::OPTION_GLOBAL_CLASSES_TS, time() );
		update_option( BricksCore::OPTION_GLOBAL_CLASSES_USER, get_current_user_id() );
		self::clear_cache();

		wp_cache_delete( BricksCore::OPTION_GLOBAL_CLASSES, 'options' );
		$stored = get_option( BricksCore::OPTION_GLOBAL_CLASSES, null );
		if ( null === $stored || ! is_array( $stored ) ) {
			return new \WP_Error(
				'global_class_create_failed',
				__( 'Global class appeared to save but verification read-back failed. The database may have rejected the write.', 'bricks-mcp' )
			);
		}

		return $new_class;
		} finally {
			$this->core->release_lock( 'global_classes' );
		}
	}

	/**
	 * Deep merge two style arrays recursively.
	 *
	 * Preserves nested structure when merging Bricks composite key styles.
	 * For example, merging {"_border": {"color": "red"}} into
	 * {"_border": {"width": "1px", "style": "solid"}} will result in
	 * {"_border": {"width": "1px", "style": "solid", "color": "red"}}.
	 *
	 * @param array<string, mixed> $existing Existing styles.
	 * @param array<string, mixed> $new      New styles to merge in.
	 * @return array<string, mixed> Merged styles.
	 */
	private function deep_merge_styles( array $existing, array $new ): array {
		$result = $existing;

		foreach ( $new as $key => $value ) {
			if (
				isset( $result[ $key ] )
				&& is_array( $result[ $key ] )
				&& is_array( $value )
			) {
				$result[ $key ] = $this->deep_merge_styles( $result[ $key ], $value );
			} else {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Normalize Bricks style properties to their correct formats.
	 *
	 * Ensures properties like border style are strings (not arrays),
	 * and other Bricks-specific formats are correct.
	 *
	 * @param array<string, mixed> $styles Styles to normalize.
	 * @return array{styles: array<string, mixed>, warnings: array<string>} Normalized styles and any warnings.
	 */
	private function normalize_bricks_styles( array $styles ): array {
		$warnings = [];

		// Normalize border style - must be a string, not an array with per-side values.
		if ( isset( $styles['_border']['style'] ) && is_array( $styles['_border']['style'] ) ) {
			$style_array = $styles['_border']['style'];
			$first_value = is_string( $style_array['top'] ?? '' ) && '' !== $style_array['top']
				? $style_array['top']
				: ( is_string( reset( $style_array ) ) ? reset( $style_array ) : 'solid' );

			$styles['_border']['style'] = $first_value;
			$warnings[] = 'Border style must be a string (e.g., "solid", "dashed"). Array value was converted to "' . esc_attr( $first_value ) . '". Use a string format in future requests.';
		}

		// Recursively normalize nested style groups (for theme styles).
		foreach ( $styles as $key => $value ) {
			// Skip if this looks like a border object (has width/style/color/radius keys).
			if ( is_array( $value ) && ! isset( $value['width'] ) && ! isset( $value['style'] ) && ! isset( $value['color'] ) && ! isset( $value['radius'] ) ) {
				$nested = $this->normalize_bricks_styles( $value );
				$styles[ $key ] = $nested['styles'];
				$warnings = array_merge( $warnings, $nested['warnings'] );
			}
		}

		return array(
			'styles'   => $styles,
			'warnings' => $warnings,
		);
	}

	/**
	 * Update an existing global CSS class.
	 *
	 * @param string               $class_id Class ID to update.
	 * @param array<string, mixed> $args     Fields to update.
	 * @return array<string, mixed>|\WP_Error Updated class array or WP_Error on failure.
	 */
	public function update_global_class( string $class_id, array $args ): array|\WP_Error {
		if ( ! $this->core->acquire_lock( 'global_classes' ) ) {
			return new \WP_Error( 'concurrent_write', __( 'Another global class write is in progress. Please retry.', 'bricks-mcp' ) );
		}

		try {
		$classes = get_option( BricksCore::OPTION_GLOBAL_CLASSES, [] );
		if ( ! is_array( $classes ) ) {
			$classes = [];
		}

		foreach ( $classes as &$class ) {
			if ( ( $class['id'] ?? '' ) !== $class_id ) {
				continue;
			}

			if ( isset( $args['name'] ) ) {
				$new_name = sanitize_text_field( $args['name'] );
				foreach ( $classes as $other ) {
					if ( ( $other['id'] ?? '' ) !== $class_id && ( $other['name'] ?? '' ) === $new_name ) {
						return new \WP_Error(
							'duplicate_name',
							sprintf(
								__( 'A global class named "%s" already exists.', 'bricks-mcp' ),
								$new_name
							)
						);
					}
				}
				$class['name'] = $new_name;
			}

			if ( isset( $args['color'] ) ) {
				$class['color'] = sanitize_text_field( $args['color'] );
			}

			if ( isset( $args['category'] ) ) {
				$class['category'] = sanitize_text_field( $args['category'] );
			}

			if ( isset( $args['styles'] ) ) {
				$sanitized_styles = $this->core->sanitize_styles_array( $args['styles'] );
				$existing_styles  = $class['settings'] ?? $class['styles'] ?? [];

				if ( ! empty( $args['replace_styles'] ) ) {
					$normalized       = $this->normalize_bricks_styles( $sanitized_styles );
					$class['settings'] = $normalized['styles'];
				} else {
					$merged           = $this->deep_merge_styles( $existing_styles, $sanitized_styles );
					$normalized       = $this->normalize_bricks_styles( $merged );
					$class['settings'] = $normalized['styles'];
				}
				unset( $class['styles'] );

				// Store warnings for response.
				$warnings = $normalized['warnings'] ?? [];
				if ( ! empty( $warnings ) ) {
					$this->store_normalization_warnings( $warnings );
				}
			}

			update_option( BricksCore::OPTION_GLOBAL_CLASSES, $classes );
			update_option( BricksCore::OPTION_GLOBAL_CLASSES_TS, time() );
			update_option( BricksCore::OPTION_GLOBAL_CLASSES_USER, get_current_user_id() );
			self::clear_cache();

			wp_cache_delete( BricksCore::OPTION_GLOBAL_CLASSES, 'options' );
			$stored = get_option( BricksCore::OPTION_GLOBAL_CLASSES, null );
			if ( null === $stored || ! is_array( $stored ) ) {
				return new \WP_Error(
					'global_class_update_failed',
					__( 'Global class appeared to save but verification read-back failed. The database may have rejected the write.', 'bricks-mcp' )
				);
			}

			return $class;
		}
		unset( $class );

		return new \WP_Error(
			'class_not_found',
			sprintf(
				__( 'Global class with ID "%s" not found.', 'bricks-mcp' ),
				$class_id
			)
		);
		} finally {
			$this->core->release_lock( 'global_classes' );
		}
	}

	/**
	 * Get any stored normalization warnings from the last update operation.
	 *
	 * @return array<string> Array of warning messages.
	 */
	public function get_normalization_warnings(): array {
		global $bricks_mcp_normalization_warnings;
		return is_array( $bricks_mcp_normalization_warnings ) ? $bricks_mcp_normalization_warnings : [];
	}

	/**
	 * Store normalization warnings for the response.
	 *
	 * @param array<string> $warnings Warning messages.
	 * @return void
	 */
	private function store_normalization_warnings( array $warnings ): void {
		global $bricks_mcp_normalization_warnings;
		$bricks_mcp_normalization_warnings = $warnings;
	}

	/**
	 * Soft-delete a global CSS class to trash.
	 *
	 * @param string $class_id Class ID to trash.
	 * @return true|\WP_Error True on success, WP_Error if class not found.
	 */
	public function trash_global_class( string $class_id ): true|\WP_Error {
		if ( ! $this->core->acquire_lock( 'global_classes' ) ) {
			return new \WP_Error( 'concurrent_write', __( 'Another global class write is in progress. Please retry.', 'bricks-mcp' ) );
		}

		try {
		$classes = get_option( BricksCore::OPTION_GLOBAL_CLASSES, [] );
		if ( ! is_array( $classes ) ) {
			$classes = [];
		}

		$trash = get_option( BricksCore::OPTION_GLOBAL_CLASSES_TRASH, [] );
		if ( ! is_array( $trash ) ) {
			$trash = [];
		}

		$found = false;
		foreach ( $classes as $index => $class ) {
			if ( ( $class['id'] ?? '' ) === $class_id ) {
				$trash[] = $class;
				array_splice( $classes, $index, 1 );
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'class_not_found',
				sprintf(
					__( 'Global class with ID "%s" not found.', 'bricks-mcp' ),
					$class_id
				)
			);
		}

		update_option( BricksCore::OPTION_GLOBAL_CLASSES, $classes );
		update_option( BricksCore::OPTION_GLOBAL_CLASSES_TRASH, $trash );
		update_option( BricksCore::OPTION_GLOBAL_CLASSES_TS, time() );
		update_option( BricksCore::OPTION_GLOBAL_CLASSES_USER, get_current_user_id() );
		self::clear_cache();

		wp_cache_delete( BricksCore::OPTION_GLOBAL_CLASSES, 'options' );
		$stored = get_option( BricksCore::OPTION_GLOBAL_CLASSES, null );
		if ( null === $stored || ! is_array( $stored ) ) {
			return new \WP_Error(
				'global_class_trash_failed',
				__( 'Global class trash appeared to save but verification read-back failed. The database may have rejected the write.', 'bricks-mcp' )
			);
		}

		return true;
		} finally {
			$this->core->release_lock( 'global_classes' );
		}
	}

	/**
	 * Find all posts that reference a global class by ID.
	 *
	 * @param string $class_id Class ID to search for.
	 * @return array{references: array<int, array{post_id: int, title: string}>, truncated: bool} References data.
	 */
	public function find_class_references( string $class_id ): array {
		$meta_keys = [
			'_bricks_page_content_2',
			'_bricks_page_header_2',
			'_bricks_page_footer_2',
		];

		$query = new \WP_Query(
			[
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => self::MAX_REFERENCE_POSTS,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					[
						'key'     => '_bricks_page_content_2',
						'compare' => 'EXISTS',
					],
					[
						'key'     => '_bricks_page_header_2',
						'compare' => 'EXISTS',
					],
					[
						'key'     => '_bricks_page_footer_2',
						'compare' => 'EXISTS',
					],
				],
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		$truncated         = count( $query->posts ) >= self::MAX_REFERENCE_POSTS;
		$referencing_posts = [];

		foreach ( $query->posts as $post_id ) {
			$found_in_post = false;

			foreach ( $meta_keys as $meta_key ) {
				if ( $found_in_post ) {
					break;
				}

				$elements = get_post_meta( $post_id, $meta_key, true );
				if ( ! is_array( $elements ) ) {
					continue;
				}

				foreach ( $elements as $element ) {
					$class_ids = $element['settings']['_cssGlobalClasses'] ?? [];
					if ( is_array( $class_ids ) && in_array( $class_id, $class_ids, true ) ) {
						$referencing_posts[] = [
							'post_id' => (int) $post_id,
							'title'   => get_the_title( $post_id ),
						];
						$found_in_post = true;
						break;
					}
				}
			}
		}

		return [
			'references' => $referencing_posts,
			'truncated'  => $truncated,
		];
	}

	/**
	 * Create multiple global CSS classes in a single call.
	 *
	 * @param array<int, array<string, mixed>> $class_definitions Array of class definition objects.
	 * @return array{created: array<int, array<string, mixed>>, errors: array<int|string, string>} Partial success result.
	 */
	public function batch_create_global_classes( array $class_definitions ): array {
		if ( ! $this->core->acquire_lock( 'global_classes' ) ) {
			return [
				'created' => [],
				'errors'  => [ '_lock' => 'Another global class write is in progress. Please retry.' ],
			];
		}

		try {
		$classes = get_option( BricksCore::OPTION_GLOBAL_CLASSES, [] );
		if ( ! is_array( $classes ) ) {
			$classes = [];
		}

		$existing_ids   = array_column( $classes, 'id' );
		$existing_names = array_column( $classes, 'name' );
		$id_generator   = new ElementIdGenerator();
		$created        = [];
		$errors         = [];

		foreach ( $class_definitions as $index => $def ) {
			if ( empty( $def['name'] ) ) {
				$errors[ $index ] = 'Missing name';
				continue;
			}

			$name = sanitize_text_field( $def['name'] );

			if ( '' === $name ) {
				$errors[ $index ] = 'Empty name after sanitization';
				continue;
			}

			if ( in_array( $name, $existing_names, true ) ) {
				$errors[ $index ] = sprintf( "Name '%s' already exists", $name );
				continue;
			}

			do {
				$new_id = $id_generator->generate();
			} while ( in_array( $new_id, $existing_ids, true ) );
			$existing_ids[] = $new_id;

			$new_class = [
				'id'     => $new_id,
				'name'   => $name,
				'color'  => isset( $def['color'] ) ? sanitize_text_field( $def['color'] ) : '#686868',
				'settings' => $this->core->sanitize_styles_array( $def['styles'] ?? [] ),
			];

			if ( ! empty( $def['category'] ) ) {
				$new_class['category'] = sanitize_text_field( $def['category'] );
			}

			$classes[]        = $new_class;
			$created[]        = $new_class;
			$existing_names[] = $name;
		}

		if ( ! empty( $created ) ) {
			update_option( BricksCore::OPTION_GLOBAL_CLASSES, $classes );
			update_option( BricksCore::OPTION_GLOBAL_CLASSES_TS, time() );
			update_option( BricksCore::OPTION_GLOBAL_CLASSES_USER, get_current_user_id() );
			self::clear_cache();

			wp_cache_delete( BricksCore::OPTION_GLOBAL_CLASSES, 'options' );
			$stored = get_option( BricksCore::OPTION_GLOBAL_CLASSES, null );
			if ( null === $stored || ! is_array( $stored ) ) {
				$errors['_readback'] = 'Batch create appeared to save but verification read-back failed.';
			}
		}

		return [
			'created' => $created,
			'errors'  => $errors,
		];
		} finally {
			$this->core->release_lock( 'global_classes' );
		}
	}

	/**
	 * Soft-delete multiple global CSS classes to trash.
	 *
	 * @param array<int, string> $class_ids Array of class IDs to trash.
	 * @return array{deleted: array<int, string>, errors: array<string, string>, references: array<int, array{post_id: int, title: string}>} Result data.
	 */
	public function batch_trash_global_classes( array $class_ids ): array|\WP_Error {
		if ( ! $this->core->acquire_lock( 'global_classes' ) ) {
			return new \WP_Error( 'concurrent_write', __( 'Another operation is modifying global classes. Try again.', 'bricks-mcp' ) );
		}

		try {
		$classes = get_option( BricksCore::OPTION_GLOBAL_CLASSES, [] );
		if ( ! is_array( $classes ) ) {
			$classes = [];
		}

		$trash = get_option( BricksCore::OPTION_GLOBAL_CLASSES_TRASH, [] );
		if ( ! is_array( $trash ) ) {
			$trash = [];
		}

		$deleted        = [];
		$errors         = [];
		$all_references = [];

		foreach ( $class_ids as $class_id ) {
			$found = false;
			foreach ( $classes as $index => $class ) {
				if ( ( $class['id'] ?? '' ) === $class_id ) {
					$trash[] = $class;
					array_splice( $classes, $index, 1 );
					$deleted[] = $class['name'] ?? $class_id;
					$found     = true;

					$refs = $this->find_class_references( $class_id );
					foreach ( $refs['references'] as $ref ) {
						$all_references[] = $ref;
					}

					break;
				}
			}

			if ( ! $found ) {
				$errors[ $class_id ] = 'Class not found';
			}
		}

		if ( ! empty( $deleted ) ) {
			update_option( BricksCore::OPTION_GLOBAL_CLASSES, $classes );
			update_option( BricksCore::OPTION_GLOBAL_CLASSES_TRASH, $trash );
			update_option( BricksCore::OPTION_GLOBAL_CLASSES_TS, time() );
			update_option( BricksCore::OPTION_GLOBAL_CLASSES_USER, get_current_user_id() );
			self::clear_cache();

			wp_cache_delete( BricksCore::OPTION_GLOBAL_CLASSES, 'options' );
			$stored = get_option( BricksCore::OPTION_GLOBAL_CLASSES, null );
			if ( null === $stored || ! is_array( $stored ) ) {
				$errors['_readback'] = 'Batch trash appeared to save but verification read-back failed.';
			}
		}

		return [
			'deleted'    => $deleted,
			'errors'     => $errors,
			'references' => $all_references,
		];
		} finally {
			$this->core->release_lock( 'global_classes' );
		}
	}

	/**
	 * Get all global CSS class categories.
	 *
	 * @return array<int, array{id: string, name: string}> Category objects.
	 */
	public function get_global_class_categories(): array {
		$categories = get_option( 'bricks_global_classes_categories', [] );
		if ( ! is_array( $categories ) ) {
			return [];
		}

		return $categories;
	}

	/**
	 * Create a new global CSS class category.
	 *
	 * @param string $name Category name.
	 * @return array{id: string, name: string}|\WP_Error Created category or error.
	 */
	public function create_global_class_category( string $name ): array|\WP_Error {
		$categories = get_option( 'bricks_global_classes_categories', [] );
		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		$sanitized_name = sanitize_text_field( $name );

		if ( '' === $sanitized_name ) {
			return new \WP_Error(
				'missing_name',
				__( 'Category name is required.', 'bricks-mcp' )
			);
		}

		$existing_names = array_column( $categories, 'name' );
		if ( in_array( $sanitized_name, $existing_names, true ) ) {
			return new \WP_Error(
				'duplicate_name',
				sprintf(
					__( 'A category named "%s" already exists.', 'bricks-mcp' ),
					$sanitized_name
				)
			);
		}

		$id_generator = new ElementIdGenerator();
		$existing_ids = array_column( $categories, 'id' );
		do {
			$new_id = $id_generator->generate();
		} while ( in_array( $new_id, $existing_ids, true ) );

		$new_category = [
			'id'   => $new_id,
			'name' => $sanitized_name,
		];

		$categories[] = $new_category;
		update_option( 'bricks_global_classes_categories', $categories );

		return $new_category;
	}

	/**
	 * Delete a global CSS class category.
	 *
	 * @param string $category_id Category ID to delete.
	 * @return true|\WP_Error True on success, WP_Error if not found.
	 */
	public function delete_global_class_category( string $category_id ): true|\WP_Error {
		$categories = get_option( 'bricks_global_classes_categories', [] );
		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		$found = false;
		foreach ( $categories as $index => $category ) {
			if ( ( $category['id'] ?? '' ) === $category_id ) {
				array_splice( $categories, $index, 1 );
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'category_not_found',
				sprintf(
					__( 'Category with ID "%s" not found. Use list_global_class_categories to find valid IDs.', 'bricks-mcp' ),
					$category_id
				)
			);
		}

		update_option( 'bricks_global_classes_categories', $categories );

		$classes  = get_option( BricksCore::OPTION_GLOBAL_CLASSES, [] );
		$modified = false;

		if ( is_array( $classes ) ) {
			foreach ( $classes as &$class ) {
				if ( ( $class['category'] ?? '' ) === $category_id ) {
					unset( $class['category'] );
					$modified = true;
				}
			}
			unset( $class );
		}

		if ( $modified ) {
			update_option( BricksCore::OPTION_GLOBAL_CLASSES, $classes );
			update_option( BricksCore::OPTION_GLOBAL_CLASSES_TS, time() );
			update_option( BricksCore::OPTION_GLOBAL_CLASSES_USER, get_current_user_id() );
			self::clear_cache();
		}

		return true;
	}

	/**
	 * Resolve a global class name to its full class data.
	 *
	 * @param string $name Exact class name to resolve.
	 * @return array<string, mixed>|null Full class array or null if not found.
	 */
	public function resolve_class_name( string $name ): ?array {
		$classes = get_option( BricksCore::OPTION_GLOBAL_CLASSES, [] );

		if ( ! is_array( $classes ) ) {
			return null;
		}

		foreach ( $classes as $class ) {
			if ( ( $class['name'] ?? '' ) === $name ) {
				return $class;
			}
		}

		return null;
	}

	/**
	 * Apply a global class to one or more elements on a page.
	 *
	 * @param int                $post_id     Post ID containing the elements.
	 * @param string             $class_id    Global class ID to apply.
	 * @param array<int, string> $element_ids Element IDs to apply the class to.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function apply_class_to_elements( int $post_id, string $class_id, array $element_ids ): true|\WP_Error {
		$elements = $this->core->get_elements( $post_id );

		$id_map = [];
		foreach ( $elements as $index => $element ) {
			$id_map[ $element['id'] ] = $index;
		}

		$invalid_ids = [];
		foreach ( $element_ids as $eid ) {
			if ( ! isset( $id_map[ $eid ] ) ) {
				$invalid_ids[] = $eid;
			}
		}

		if ( ! empty( $invalid_ids ) ) {
			return new \WP_Error(
				'invalid_element_ids',
				sprintf(
					__( 'Element IDs not found on post %1$d: %2$s. Use page:get to retrieve valid element IDs.', 'bricks-mcp' ),
					$post_id,
					implode( ', ', $invalid_ids )
				)
			);
		}

		foreach ( $element_ids as $eid ) {
			$index   = $id_map[ $eid ];
			$current = $elements[ $index ]['settings']['_cssGlobalClasses'] ?? [];

			if ( ! in_array( $class_id, $current, true ) ) {
				$current[] = $class_id;
				$elements[ $index ]['settings']['_cssGlobalClasses'] = $current;
			}
		}

		return $this->core->save_elements( $post_id, $elements );
	}

	/**
	 * Remove a global class from one or more elements on a page.
	 *
	 * @param int                $post_id     Post ID containing the elements.
	 * @param string             $class_id    Global class ID to remove.
	 * @param array<int, string> $element_ids Element IDs to remove the class from.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function remove_class_from_elements( int $post_id, string $class_id, array $element_ids ): true|\WP_Error {
		$elements = $this->core->get_elements( $post_id );

		$id_map = [];
		foreach ( $elements as $index => $element ) {
			$id_map[ $element['id'] ] = $index;
		}

		$invalid_ids = [];
		foreach ( $element_ids as $eid ) {
			if ( ! isset( $id_map[ $eid ] ) ) {
				$invalid_ids[] = $eid;
			}
		}

		if ( ! empty( $invalid_ids ) ) {
			return new \WP_Error(
				'invalid_element_ids',
				sprintf(
					__( 'Element IDs not found on post %1$d: %2$s. Use page:get to retrieve valid element IDs.', 'bricks-mcp' ),
					$post_id,
					implode( ', ', $invalid_ids )
				)
			);
		}

		foreach ( $element_ids as $eid ) {
			$index   = $id_map[ $eid ];
			$current = $elements[ $index ]['settings']['_cssGlobalClasses'] ?? [];
			$current = array_values( array_filter( $current, static fn( string $cid ) => $cid !== $class_id ) );

			$elements[ $index ]['settings']['_cssGlobalClasses'] = $current;
		}

		return $this->core->save_elements( $post_id, $elements );
	}

	/**
	 * Import CSS class definitions from a raw CSS string.
	 *
	 * @param string $css_string Raw CSS to parse.
	 * @return array{created: array, errors: array, mapped_properties: string[], custom_css_properties: string[]} Import results.
	 */
	public function import_classes_from_css( string $css_string ): array {
		$mapped_properties     = [];
		$custom_css_properties = [];
		$class_styles          = [];

		$media_blocks = [];
		$base_css     = preg_replace_callback(
			'/@media\s*\(([^)]+)\)\s*\{((?:[^{}]*|\{[^{}]*\})*)\}/s',
			static function ( array $matches ) use ( &$media_blocks ) {
				$media_blocks[] = [
					'query'   => trim( $matches[1] ),
					'content' => trim( $matches[2] ),
				];
				return '';
			},
			$css_string
		);

		$this->parse_css_rules( $base_css ?? '', '', $class_styles, $mapped_properties, $custom_css_properties );

		foreach ( $media_blocks as $block ) {
			$breakpoint = $this->resolve_media_query_to_breakpoint( $block['query'] );
			$this->parse_css_rules( $block['content'], $breakpoint, $class_styles, $mapped_properties, $custom_css_properties );
		}

		$class_definitions = [];
		foreach ( $class_styles as $class_name => $styles ) {
			$class_definitions[] = [
				'name'   => $class_name,
				'styles' => $styles,
			];
		}

		if ( empty( $class_definitions ) ) {
			return [
				'created'               => [],
				'errors'                => [],
				'mapped_properties'     => array_values( array_unique( $mapped_properties ) ),
				'custom_css_properties' => array_values( array_unique( $custom_css_properties ) ),
			];
		}

		$result = $this->batch_create_global_classes( $class_definitions );

		$result['mapped_properties']     = array_values( array_unique( $mapped_properties ) );
		$result['custom_css_properties'] = array_values( array_unique( $custom_css_properties ) );

		return $result;
	}

	/**
	 * Export global classes as JSON.
	 *
	 * @param string $category Optional category ID to filter by.
	 * @return array<string, mixed> Export data.
	 */
	public function export_global_classes( string $category = '' ): array {
		$classes = get_option( BricksCore::OPTION_GLOBAL_CLASSES, array() );

		if ( ! empty( $category ) ) {
			$classes = array_filter(
				$classes,
				fn( $c ) => ( $c['category'] ?? '' ) === $category
			);
		}

		$categories = get_option( 'bricks_global_classes_categories', array() );

		return array(
			'classes'    => array_values( $classes ),
			'categories' => is_array( $categories ) ? $categories : array(),
			'count'      => count( $classes ),
		);
	}

	/**
	 * Import global classes from JSON data, merging by name.
	 *
	 * @param array<string, mixed> $data Classes data.
	 * @return array<string, mixed>|\WP_Error Import summary or error.
	 */
	public function import_global_classes_from_json( array $data ): array|\WP_Error {
		if ( isset( $data['classes'] ) && is_array( $data['classes'] ) ) {
			$classes_to_import = $data['classes'];
		} elseif ( ! empty( $data ) && isset( $data[0]['name'] ) ) {
			$classes_to_import = $data;
		} else {
			return new \WP_Error(
				'invalid_classes_data',
				__( 'classes_data must be an object with a "classes" array or a raw array of class objects with "name" keys.', 'bricks-mcp' )
			);
		}

		$existing       = get_option( BricksCore::OPTION_GLOBAL_CLASSES, array() );
		$existing_names = array_column( $existing, 'name' );
		$existing_ids   = array_column( $existing, 'id' );
		$id_generator   = new ElementIdGenerator();

		$added   = array();
		$skipped = array();

		foreach ( $classes_to_import as $class ) {
			if ( empty( $class['name'] ) ) {
				continue;
			}

			// Normalize: accept both 'settings' (Bricks native) and 'styles' (API param).
			$raw_styles = $class['settings'] ?? $class['styles'] ?? [];

			$class['name'] = sanitize_text_field( $class['name'] );
			if ( is_array( $raw_styles ) && ! empty( $raw_styles ) ) {
				$raw_styles = $this->core->sanitize_styles_array( $raw_styles );
			}

			if ( in_array( $class['name'], $existing_names, true ) ) {
				$skipped[] = $class['name'];
				continue;
			}

			do {
				$new_id = $id_generator->generate();
			} while ( in_array( $new_id, $existing_ids, true ) );

			// Build clean class with only known/safe keys — prevent arbitrary data persistence.
			$clean = [
				'id'   => $new_id,
				'name' => $class['name'],
			];
			if ( is_array( $raw_styles ) && ! empty( $raw_styles ) ) {
				$clean['settings'] = $raw_styles; // Already sanitized above.
			}
			if ( ! empty( $class['category'] ) ) {
				$clean['category'] = sanitize_text_field( $class['category'] );
			}

			$existing_ids[] = $new_id;
			$existing[]     = $clean;
			$added[]        = $class['name'];
		}

		if ( ! empty( $added ) ) {
			update_option( BricksCore::OPTION_GLOBAL_CLASSES, $existing );
			update_option( BricksCore::OPTION_GLOBAL_CLASSES_TS, time() );
			update_option( BricksCore::OPTION_GLOBAL_CLASSES_USER, get_current_user_id() );
			self::clear_cache();

			$this->core->regenerate_style_manager_css();
		}

		if ( ! empty( $data['categories'] ) && is_array( $data['categories'] ) ) {
			$existing_categories = get_option( 'bricks_global_classes_categories', array() );
			if ( ! is_array( $existing_categories ) ) {
				$existing_categories = array();
			}
			$existing_cat_ids = array_column( $existing_categories, 'id' );

			foreach ( $data['categories'] as $cat ) {
				if ( ! empty( $cat['id'] ) && ! in_array( $cat['id'], $existing_cat_ids, true ) ) {
					$existing_categories[] = $cat;
					$existing_cat_ids[]    = $cat['id'];
				}
			}

			update_option( 'bricks_global_classes_categories', $existing_categories );
		}

		return array(
			'added'         => $added,
			'skipped'       => $skipped,
			'added_count'   => count( $added ),
			'skipped_count' => count( $skipped ),
			'total'         => count( $existing ),
		);
	}

	/**
	 * Merge global classes during template import.
	 *
	 * @param array<int, array<string, mixed>> $import_classes Array of global class objects.
	 * @return array<string, array<int, string>> Summary with added and skipped class names.
	 */
	public function merge_imported_global_classes( array $import_classes ): array {
		$existing       = get_option( BricksCore::OPTION_GLOBAL_CLASSES, array() );
		$existing_names = array_column( $existing, 'name' );
		$existing_ids   = array_column( $existing, 'id' );
		$id_generator   = new ElementIdGenerator();

		$added   = array();
		$skipped = array();

		foreach ( $import_classes as $class ) {
			if ( empty( $class['name'] ) ) {
				continue;
			}

			$class['name'] = sanitize_text_field( $class['name'] );
			$raw_styles = $class['settings'] ?? $class['styles'] ?? [];
			if ( is_array( $raw_styles ) && ! empty( $raw_styles ) ) {
				$raw_styles = $this->core->sanitize_styles_array( $raw_styles );
			}

			if ( in_array( $class['name'], $existing_names, true ) ) {
				$skipped[] = $class['name'];
				continue;
			}

			do {
				$new_id = $id_generator->generate();
			} while ( in_array( $new_id, $existing_ids, true ) );

			// Build clean class with only known/safe keys.
			$clean = [
				'id'   => $new_id,
				'name' => $class['name'],
			];
			if ( is_array( $raw_styles ) && ! empty( $raw_styles ) ) {
				$clean['settings'] = $raw_styles; // Already sanitized above.
			}
			if ( ! empty( $class['category'] ) ) {
				$clean['category'] = sanitize_text_field( $class['category'] );
			}

			$existing_ids[] = $new_id;
			$existing[]     = $clean;
			$added[]        = $class['name'];
		}

		if ( ! empty( $added ) ) {
			update_option( BricksCore::OPTION_GLOBAL_CLASSES, $existing );
			update_option( BricksCore::OPTION_GLOBAL_CLASSES_TS, time() );
			update_option( BricksCore::OPTION_GLOBAL_CLASSES_USER, get_current_user_id() );
			self::clear_cache();
		}

		return array(
			'added'   => $added,
			'skipped' => $skipped,
		);
	}

	/**
	 * Regenerate the Bricks style manager CSS file.
	 *
	 * @return bool True if CSS was regenerated, false if method not available.
	 */
	public function regenerate_style_manager_css(): bool {
		return $this->core->regenerate_style_manager_css();
	}

	// =========================================================================
	// CSS Parsing Helpers (private)
	// =========================================================================

	/**
	 * Parse CSS rules from a string into Bricks style keys.
	 *
	 * @param string   $css                    CSS string to parse.
	 * @param string   $breakpoint             Bricks breakpoint key.
	 * @param array    $class_styles           Reference to accumulated class styles map.
	 * @param string[] $mapped_properties      Reference to list of successfully mapped properties.
	 * @param string[] $custom_css_properties  Reference to list of custom CSS properties.
	 */
	private function parse_css_rules(
		string $css,
		string $breakpoint,
		array &$class_styles,
		array &$mapped_properties,
		array &$custom_css_properties
	): void {
		preg_match_all(
			'/\.([a-zA-Z_-][\w-]*)(?::(\w+(?:-\w+)*))?[\s,]*\{([^}]*)\}/s',
			$css,
			$matches,
			PREG_SET_ORDER
		);

		foreach ( $matches as $match ) {
			$class_name = $match[1];
			$pseudo     = $match[2] ?? '';
			$body       = trim( $match[3] );

			if ( '' === $body ) {
				continue;
			}

			$declarations = $this->parse_css_declarations( $body );

			foreach ( $declarations as $property => $value ) {
				$bricks_key = $this->css_property_to_bricks_key( $property, $value );

				if ( null === $bricks_key ) {
					$custom_css_properties[] = $property;
					$suffix                  = $this->build_composite_suffix( $breakpoint, $pseudo );
					$custom_key              = '_cssCustom' . $suffix;

					if ( ! isset( $class_styles[ $class_name ] ) ) {
						$class_styles[ $class_name ] = [];
					}

					$existing_custom = $class_styles[ $class_name ][ $custom_key ] ?? '';
					$selector        = '.' . $class_name;
					if ( '' !== $pseudo ) {
						$selector .= ':' . $pseudo;
					}
					$class_styles[ $class_name ][ $custom_key ] = $existing_custom . $selector . ' { ' . $property . ': ' . $value . '; } ';
					continue;
				}

				$mapped_properties[] = $property;
				$suffix              = $this->build_composite_suffix( $breakpoint, $pseudo );

				if ( ! isset( $class_styles[ $class_name ] ) ) {
					$class_styles[ $class_name ] = [];
				}

				$full_key = $bricks_key['key'] . $suffix;

				if ( is_array( $bricks_key['value'] ) ) {
					$existing                                 = $class_styles[ $class_name ][ $full_key ] ?? [];
					$class_styles[ $class_name ][ $full_key ] = array_merge(
						is_array( $existing ) ? $existing : [],
						$bricks_key['value']
					);
				} else {
					$class_styles[ $class_name ][ $full_key ] = $bricks_key['value'];
				}
			}
		}
	}

	/**
	 * Parse CSS declarations from a block body.
	 *
	 * @param string $body CSS declarations.
	 * @return array<string, string> Property => value map.
	 */
	private function parse_css_declarations( string $body ): array {
		$declarations = [];
		$parts        = explode( ';', $body );

		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}

			$colon = strpos( $part, ':' );
			if ( false === $colon ) {
				continue;
			}

			$property = strtolower( trim( substr( $part, 0, $colon ) ) );
			$value    = trim( substr( $part, $colon + 1 ) );

			if ( '' !== $property && '' !== $value ) {
				$declarations[ $property ] = $value;
			}
		}

		return $declarations;
	}

	/**
	 * Map a CSS property and value to a Bricks style key.
	 *
	 * @param string $property CSS property name.
	 * @param string $value    CSS property value.
	 * @return array{key: string, value: mixed}|null Bricks key and structured value, or null.
	 */
	private function css_property_to_bricks_key( string $property, string $value ): ?array {
		switch ( $property ) {
			case 'padding':
				return [ 'key' => '_padding', 'value' => $this->expand_spacing_shorthand( $value ) ];
			case 'margin':
				return [ 'key' => '_margin', 'value' => $this->expand_spacing_shorthand( $value ) ];
			case 'padding-top':
				return [ 'key' => '_padding', 'value' => [ 'top' => $value ] ];
			case 'padding-right':
				return [ 'key' => '_padding', 'value' => [ 'right' => $value ] ];
			case 'padding-bottom':
				return [ 'key' => '_padding', 'value' => [ 'bottom' => $value ] ];
			case 'padding-left':
				return [ 'key' => '_padding', 'value' => [ 'left' => $value ] ];
			case 'margin-top':
				return [ 'key' => '_margin', 'value' => [ 'top' => $value ] ];
			case 'margin-right':
				return [ 'key' => '_margin', 'value' => [ 'right' => $value ] ];
			case 'margin-bottom':
				return [ 'key' => '_margin', 'value' => [ 'bottom' => $value ] ];
			case 'margin-left':
				return [ 'key' => '_margin', 'value' => [ 'left' => $value ] ];
			case 'background-color':
				$color_val = ( str_starts_with( $value, 'var(' ) || str_starts_with( $value, 'rgba' ) || str_starts_with( $value, 'rgb(' ) || str_starts_with( $value, 'hsl' ) )
					? [ 'raw' => $value ] : [ 'hex' => $value ];
				return [ 'key' => '_background', 'value' => [ 'color' => $color_val ] ];
			case 'color':
				$tc_val = ( str_starts_with( $value, 'var(' ) || str_starts_with( $value, 'rgba' ) || str_starts_with( $value, 'rgb(' ) || str_starts_with( $value, 'hsl' ) )
					? [ 'raw' => $value ] : [ 'hex' => $value ];
				return [ 'key' => '_typography', 'value' => [ 'color' => $tc_val ] ];
			case 'font-size':
				return [ 'key' => '_typography', 'value' => [ 'font-size' => $value ] ];
			case 'font-weight':
				return [ 'key' => '_typography', 'value' => [ 'font-weight' => $value ] ];
			case 'line-height':
				return [ 'key' => '_typography', 'value' => [ 'line-height' => $value ] ];
			case 'letter-spacing':
				return [ 'key' => '_typography', 'value' => [ 'letter-spacing' => $value ] ];
			case 'font-style':
				return [ 'key' => '_typography', 'value' => [ 'font-style' => $value ] ];
			case 'font-family':
				return [ 'key' => '_typography', 'value' => [ 'font-family' => $value ] ];
			case 'text-transform':
				return [ 'key' => '_typography', 'value' => [ 'text-transform' => $value ] ];
			case 'text-decoration':
				return [ 'key' => '_typography', 'value' => [ 'text-decoration' => $value ] ];
			case 'border-radius':
				return [ 'key' => '_borderRadius', 'value' => $this->expand_spacing_shorthand( $value ) ];
			case 'display': return [ 'key' => '_display', 'value' => $value ];
			case 'flex-direction': return [ 'key' => '_direction', 'value' => $value ];
			case 'align-items':    return [ 'key' => '_alignItems', 'value' => $value ];
			case 'justify-content': return [ 'key' => '_justifyContent', 'value' => $value ];
			case 'flex-grow':      return [ 'key' => '_flexGrow', 'value' => (int)$value ];
			case 'flex-shrink':    return [ 'key' => '_flexShrink', 'value' => (int)$value ];
			case 'gap':            return [ 'key' => '_gap', 'value' => $value ];
			case 'width':      return [ 'key' => '_width', 'value' => $value ];
			case 'max-width':  return [ 'key' => '_widthMax', 'value' => $value ];
			case 'min-width':  return [ 'key' => '_widthMin', 'value' => $value ];
			case 'height':     return [ 'key' => '_height', 'value' => $value ];
			case 'max-height': return [ 'key' => '_heightMax', 'value' => $value ];
			case 'min-height': return [ 'key' => '_heightMin', 'value' => $value ];
			case 'position': return [ 'key' => '_position', 'value' => $value ];
			case 'z-index':  return [ 'key' => '_zIndex', 'value' => $value ];
			case 'top':      return [ 'key' => '_top', 'value' => $value ];
			case 'right':    return [ 'key' => '_right', 'value' => $value ];
			case 'bottom':   return [ 'key' => '_bottom', 'value' => $value ];
			case 'left':     return [ 'key' => '_left', 'value' => $value ];
			case 'overflow':   return [ 'key' => '_overflow', 'value' => $value ];
			case 'overflow-x': return [ 'key' => '_overflowX', 'value' => $value ];
			case 'overflow-y': return [ 'key' => '_overflowY', 'value' => $value ];
			case 'opacity':    return [ 'key' => '_opacity', 'value' => $value ];
			default:
				return null;
		}
	}

	/**
	 * Expand CSS spacing shorthand.
	 *
	 * @param string $shorthand CSS shorthand value.
	 * @return array{top: string, right: string, bottom: string, left: string} Expanded values.
	 */
	private function expand_spacing_shorthand( string $shorthand ): array {
		$parts = preg_split( '/\s+/', trim( $shorthand ) );

		if ( ! is_array( $parts ) || 0 === count( $parts ) ) {
			return [ 'top' => $shorthand, 'right' => $shorthand, 'bottom' => $shorthand, 'left' => $shorthand ];
		}

		switch ( count( $parts ) ) {
			case 1:
				return [ 'top' => $parts[0], 'right' => $parts[0], 'bottom' => $parts[0], 'left' => $parts[0] ];
			case 2:
				return [ 'top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[0], 'left' => $parts[1] ];
			case 3:
				return [ 'top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[2], 'left' => $parts[1] ];
			default:
				return [ 'top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[2], 'left' => $parts[3] ];
		}
	}

	/**
	 * Resolve a CSS media query string to a Bricks breakpoint key.
	 *
	 * @param string $query Media query string.
	 * @return string Bricks breakpoint key, or empty string.
	 */
	private function resolve_media_query_to_breakpoint( string $query ): string {
		$max_width_map = [
			478  => 'mobile_portrait',
			767  => 'mobile_landscape',
			768  => 'mobile_landscape',
			991  => 'tablet_portrait',
			1023 => 'tablet_portrait',
			1279 => 'desktop',
		];

		$min_width_map = [
			479  => 'mobile_landscape',
			768  => 'tablet_portrait',
			992  => 'desktop',
		];

		if ( preg_match( '/max-width\s*:\s*(\d+)/', $query, $matches ) ) {
			$width = (int) $matches[1];

			if ( isset( $max_width_map[ $width ] ) ) {
				return $max_width_map[ $width ];
			}

			$best_key  = '';
			$best_diff = 51;
			foreach ( $max_width_map as $px => $bp ) {
				$diff = abs( $width - $px );
				if ( $diff < $best_diff ) {
					$best_diff = $diff;
					$best_key  = $bp;
				}
			}

			return $best_key;
		}

		if ( preg_match( '/min-width\s*:\s*(\d+)/', $query, $matches ) ) {
			$width = (int) $matches[1];

			if ( $width >= 1200 ) {
				return '';
			}

			if ( isset( $min_width_map[ $width ] ) ) {
				return $min_width_map[ $width ];
			}

			$best_key  = '';
			$best_diff = 51;
			foreach ( $min_width_map as $px => $bp ) {
				$diff = abs( $width - $px );
				if ( $diff < $best_diff ) {
					$best_diff = $diff;
					$best_key  = $bp;
				}
			}

			return $best_key;
		}

		return '';
	}

	/**
	 * Build a Bricks composite key suffix.
	 *
	 * @param string $breakpoint Bricks breakpoint key.
	 * @param string $pseudo     CSS pseudo-state.
	 * @return string Composite suffix.
	 */
	private function build_composite_suffix( string $breakpoint, string $pseudo ): string {
		$suffix = '';

		if ( '' !== $breakpoint ) {
			$suffix .= ':' . $breakpoint;
		}

		if ( '' !== $pseudo ) {
			$suffix .= ':' . $pseudo;
		}

		return $suffix;
	}
}
