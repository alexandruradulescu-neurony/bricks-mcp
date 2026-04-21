<?php
/**
 * Bricks core infrastructure shared by all sub-services.
 *
 * Holds locking, filter management, sanitization, CSS regeneration,
 * meta key resolution, element I/O, and other shared utilities that
 * multiple domain-specific sub-services depend on.
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
 * BricksCore class.
 *
 * Shared infrastructure passed to all sub-services via constructor injection.
 */
class BricksCore {

	/**
	 * Post meta key for Bricks page content.
	 */
	public const META_KEY = '_bricks_page_content_2';

	/**
	 * Post meta key for Bricks editor mode.
	 */
	public const EDITOR_MODE_KEY = '_bricks_editor_mode';

	/**
	 * Fallback meta key for Bricks header template content.
	 * Used when Bricks has not defined BRICKS_DB_PAGE_HEADER.
	 */
	public const META_KEY_HEADER_FALLBACK = '_bricks_page_header_2';

	/**
	 * Fallback meta key for Bricks footer template content.
	 * Used when Bricks has not defined BRICKS_DB_PAGE_FOOTER.
	 */
	public const META_KEY_FOOTER_FALLBACK = '_bricks_page_footer_2';

	/**
	 * WordPress option keys used throughout the plugin.
	 */
	public const OPTION_GLOBAL_CLASSES       = 'bricks_global_classes';
	public const OPTION_GLOBAL_CLASSES_TRASH = 'bricks_global_classes_trash';
	public const OPTION_GLOBAL_CLASSES_TS    = 'bricks_global_classes_timestamp';
	public const OPTION_GLOBAL_CLASSES_USER  = 'bricks_global_classes_user';
	public const OPTION_GLOBAL_VARIABLES     = 'bricks_global_variables';
	public const OPTION_VARIABLE_CATEGORIES  = 'bricks_global_variables_categories';
	public const OPTION_BRIEFS               = 'bricks_mcp_briefs';
	public const OPTION_SETTINGS             = 'bricks_mcp_settings';
	public const OPTION_NOTES                = 'bricks_mcp_notes';
	public const OPTION_GLOBAL_SETTINGS      = 'bricks_global_settings';
	public const OPTION_COMPONENTS           = 'bricks_components';
	public const OPTION_GLOBAL_QUERIES       = 'bricks_global_queries';
	public const OPTION_CUSTOM_PATTERNS      = 'bricks_mcp_custom_patterns';
	public const OPTION_HIDDEN_PATTERNS      = 'bricks_mcp_hidden_patterns';
	public const OPTION_PATTERN_CATEGORIES   = 'bricks_mcp_pattern_categories';
	public const OPTION_PATTERNS_MIGRATED    = 'bricks_mcp_patterns_migrated';
	public const OPTION_STRUCTURED_BRIEF     = 'bricks_mcp_structured_brief';
	public const OPTION_DS_LAST_APPLIED      = 'bricks_mcp_ds_last_applied';
	public const OPTION_DESIGN_SYSTEM_CONFIG = 'bricks_mcp_design_system_config';
	public const OPTION_TERM_TRASH           = 'bricks_mcp_term_trash';
	public const OPTION_DB_VERSION           = 'bricks_mcp_db_version';
	public const OPTION_VERSION              = 'bricks_mcp_version';
	public const OPTION_ACTIVATED_AT         = 'bricks_mcp_activated_at';

	/**
	 * Bricks-template meta keys. Used on posts of type `bricks_template`.
	 * Previously scattered as literal strings across TemplateHandler,
	 * WooCommerceHandler, SchemaHandler, and other handlers (5+ duplicates).
	 */
	public const META_TEMPLATE_TYPE     = '_bricks_template_type';
	public const META_TEMPLATE_SETTINGS = '_bricks_template_settings';

	/**
	 * Required WordPress capability for MCP operations.
	 */
	public const REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Maximum items per batch across bulk operations (element:bulk_add, bulk_update,
	 * bricks:get_element_schemas batch, class batch_create/batch_delete).
	 * Centralized to prevent drift between validator caps and handler caps.
	 */
	public const BATCH_SIZE = 50;

	/**
	 * Return the canonical path to a file in the plugin's data/ directory.
	 *
	 * Replaces `dirname(__DIR__, 3) . '/data/elements.json'` scattered across
	 * SchemaGenerator, ProposalService, DesignPatternService, and
	 * ElementSettingsGenerator. Single source of truth for data-file paths.
	 *
	 * @param string $filename Relative filename inside data/.
	 * @return string Absolute path.
	 */
	public static function data_path( string $filename ): string {
		return dirname( __DIR__, 3 ) . '/data/' . ltrim( $filename, '/' );
	}

	/**
	 * Return the Bricks header template meta key.
	 *
	 * Wraps the `defined(BRICKS_DB_PAGE_HEADER) ? BRICKS_DB_PAGE_HEADER : '_bricks_page_header_2'`
	 * ternary that was duplicated across BricksCore and GlobalClassService.
	 *
	 * @return string Header meta key.
	 */
	public static function header_meta_key(): string {
		return defined( 'BRICKS_DB_PAGE_HEADER' ) ? BRICKS_DB_PAGE_HEADER : self::META_KEY_HEADER_FALLBACK;
	}

	/**
	 * Return the Bricks footer template meta key.
	 *
	 * @return string Footer meta key.
	 */
	public static function footer_meta_key(): string {
		return defined( 'BRICKS_DB_PAGE_FOOTER' ) ? BRICKS_DB_PAGE_FOOTER : self::META_KEY_FOOTER_FALLBACK;
	}

	/**
	 * Element normalizer instance.
	 *
	 * @var ElementNormalizer
	 */
	private ElementNormalizer $normalizer;

	/**
	 * Validation service instance.
	 *
	 * Optional — when set, validates element settings against Bricks schemas before saving.
	 *
	 * @var ValidationService|null
	 */
	private ?ValidationService $validation_service = null;

	/**
	 * Stack of stored Bricks meta filter callbacks for temporary removal.
	 * Stack-based to support reentrant unhook/rehook calls safely.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $filter_stack = [];

	/**
	 * Constructor.
	 *
	 * @param ElementNormalizer $normalizer Element normalizer instance.
	 */
	public function __construct( ElementNormalizer $normalizer ) {
		$this->normalizer = $normalizer;
	}

	/**
	 * Get the element normalizer instance.
	 *
	 * @return ElementNormalizer
	 */
	public function get_normalizer(): ElementNormalizer {
		return $this->normalizer;
	}

	/**
	 * Set the validation service.
	 *
	 * @param ValidationService $service Validation service instance.
	 * @return void
	 */
	public function set_validation_service( ValidationService $service ): void {
		$this->validation_service = $service;
	}

	/**
	 * Get the validation service.
	 *
	 * @return ValidationService|null
	 */
	public function get_validation_service(): ?ValidationService {
		return $this->validation_service;
	}

	/**
	 * Check if Bricks Builder is active.
	 *
	 * @return bool True if Bricks Builder is installed and active.
	 */
	public function is_bricks_active(): bool {
		return class_exists( '\Bricks\Elements' );
	}

	/**
	 * Check if a post is using the Bricks editor.
	 *
	 * @param int $post_id The post ID to check.
	 * @return bool True if the post uses Bricks editor.
	 */
	public function is_bricks_page( int $post_id ): bool {
		return get_post_meta( $post_id, self::EDITOR_MODE_KEY, true ) === 'bricks';
	}

	/**
	 * Enable the Bricks editor for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function enable_bricks_editor( int $post_id ): void {
		update_post_meta( $post_id, self::EDITOR_MODE_KEY, 'bricks' );
	}

	/**
	 * Disable the Bricks editor for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function disable_bricks_editor( int $post_id ): void {
		delete_post_meta( $post_id, self::EDITOR_MODE_KEY );
	}

	/**
	 * Normalize element input using the ElementNormalizer.
	 *
	 * @param array<int, array<string, mixed>> $input             Input elements.
	 * @param array<int, array<string, mixed>> $existing_elements Existing elements for collision-free IDs.
	 * @return array<int, array<string, mixed>> Normalized flat element array.
	 */
	public function normalize_elements( array $input, array $existing_elements = [] ): array {
		return $this->normalizer->normalize( $input, $existing_elements );
	}

	/**
	 * Get Bricks elements for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return array<int, array<string, mixed>> Flat array of elements, empty array if none.
	 */
	public function get_elements( int $post_id ): array {
		$elements = get_post_meta( $post_id, $this->resolve_elements_meta_key( $post_id ), true );

		if ( ! is_array( $elements ) ) {
			return [];
		}

		return $elements;
	}

	/**
	 * Resolve the correct Bricks meta key for reading element content.
	 *
	 * @param int $post_id The post ID.
	 * @return string The meta key to use for reading element content.
	 */
	public function resolve_elements_meta_key( int $post_id ): string {
		$template_type = get_post_meta( $post_id, self::META_TEMPLATE_TYPE, true );
		return match ( $template_type ) {
			'header' => self::header_meta_key(),
			'footer' => self::footer_meta_key(),
			default  => self::META_KEY,
		};
	}

	/**
	 * Save Bricks elements for a post.
	 *
	 * @param int                              $post_id  The post ID.
	 * @param array<int, array<string, mixed>> $elements Flat array of elements to save.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function save_elements( int $post_id, array $elements ): true|\WP_Error {
		// Always run structural linkage validation.
		$linkage_validation = $this->validate_element_linkage( $elements );

		if ( is_wp_error( $linkage_validation ) ) {
			return $linkage_validation;
		}

		// Run schema validation when Bricks is active and ValidationService is available.
		if ( null !== $this->validation_service && $this->is_bricks_active() ) {
			$schema_validation = $this->validation_service->validate_elements( $elements );

			if ( is_wp_error( $schema_validation ) ) {
				return $schema_validation;
			}
		}

		// Resolve the correct meta key — header/footer templates use dedicated keys.
		$meta_key = $this->resolve_elements_meta_key( $post_id );

		// Clear stale object cache so update_post_meta sees current DB state.
		wp_cache_delete( $post_id, 'post_meta' );

		// Temporarily unhook Bricks sanitize/update filters that block programmatic meta writes.
		$stored = null;
		$this->unhook_bricks_meta_filters( $meta_key );
		try {
			$updated = update_post_meta( $post_id, $meta_key, $elements );

			if ( false === $updated ) {
				// update_post_meta returns false both on failure AND when value is unchanged.
				// Compare against current value to distinguish.
				// Previously this branch fell through to delete_post_meta + add_post_meta,
				// which opened a window where concurrent readers saw empty meta. We now
				// surface a failure instead of trying to recover via destructive fallback —
				// the caller can retry at the next write attempt, and data integrity is
				// preserved.
				$existing = get_post_meta( $post_id, $meta_key, true );
				if ( $existing !== $elements ) {
					return new \WP_Error(
						'save_elements_failed',
						__( 'update_post_meta returned false and the stored value does not match the intended elements. Refusing delete-then-add fallback to prevent a visibility window where readers see empty meta. Retry the write.', 'bricks-mcp' )
					);
				}
				// else: value already matches, benign no-op.
			}

			update_post_meta( $post_id, self::EDITOR_MODE_KEY, 'bricks' );

			// Trigger CSS regeneration so frontend styles reflect new content.
			$this->trigger_css_regeneration( $post_id );

			// Verify write persisted — bypass cache, read raw from database.
			wp_cache_delete( $post_id, 'post_meta' );
			$stored = get_post_meta( $post_id, $meta_key, true );
		} finally {
			$this->rehook_bricks_meta_filters();
		}

		if ( ! is_array( $stored ) || count( $stored ) !== count( $elements ) ) {
			return new \WP_Error(
				'save_elements_failed',
				__( 'Elements appeared to save but verification read-back failed. The database may have rejected the write.', 'bricks-mcp' )
			);
		}

		return true;
	}

	/**
	 * Remove Bricks meta sanitize/update filters that block programmatic writes.
	 *
	 * @param string $meta_key Optional meta key for targeted filter removal.
	 * @return void
	 */
	public function unhook_bricks_meta_filters( string $meta_key = '' ): void {
		global $wp_filter;

		$stored = [
			'sanitize_meta_callbacks' => [],
			'update_post_metadata_bricks' => [],
		];

		$keys_to_unhook = array_unique( array_filter( [
			$meta_key,
			self::META_KEY,
			self::header_meta_key(),
			self::footer_meta_key(),
		] ) );

		// Record each sanitize_post_meta_* callback individually so rehook can
		// re-add them via add_filter() without overwriting any new callbacks that
		// other plugins may have registered during the unhook window.
		foreach ( $keys_to_unhook as $key ) {
			$sanitize_key = 'sanitize_post_meta_' . $key;
			if ( ! isset( $wp_filter[ $sanitize_key ] ) ) {
				continue;
			}
			$hook = $wp_filter[ $sanitize_key ];
			if ( ! is_object( $hook ) || ! isset( $hook->callbacks ) ) {
				continue;
			}
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $id => $callback ) {
					$stored['sanitize_meta_callbacks'][] = [
						'hook'     => $sanitize_key,
						'priority' => $priority,
						'id'       => $id,
						'callback' => $callback,
					];
				}
			}
			// Clear the hook entirely — we restore callback-by-callback on rehook.
			unset( $wp_filter[ $sanitize_key ] );
		}

		if ( isset( $wp_filter['update_post_metadata'] ) ) {
			foreach ( $wp_filter['update_post_metadata']->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $id => $callback ) {
					if ( is_array( $callback['function'] ) && is_object( $callback['function'][0] ) && $callback['function'][0] instanceof \Bricks\Ajax ) {
						$stored['update_post_metadata_bricks'][] = [
							'priority' => $priority,
							'id'       => $id,
							'callback' => $callback,
						];
						unset( $wp_filter['update_post_metadata']->callbacks[ $priority ][ $id ] );
					}
				}
			}
		}

		$this->filter_stack[] = $stored;
	}

	/**
	 * Re-hook Bricks meta filters after programmatic write.
	 *
	 * @return void
	 */
	public function rehook_bricks_meta_filters(): void {
		global $wp_filter;

		$stored = array_pop( $this->filter_stack );
		if ( ! is_array( $stored ) ) {
			return;
		}

		// Restore sanitize_post_meta_* callbacks one at a time. Previously this
		// method assigned the stashed WP_Hook object wholesale (`$wp_filter[$key] = $filter`),
		// which wiped out any callbacks that other plugins registered during the
		// unhook window. Per-callback add_filter() preserves concurrent registrations.
		foreach ( $stored['sanitize_meta_callbacks'] ?? [] as $entry ) {
			$hook     = $entry['hook'];
			$priority = (int) $entry['priority'];
			$callback = $entry['callback'];
			add_filter( $hook, $callback['function'], $priority, (int) ( $callback['accepted_args'] ?? 1 ) );
		}

		if ( ! empty( $stored['update_post_metadata_bricks'] ) && isset( $wp_filter['update_post_metadata'] ) ) {
			foreach ( $stored['update_post_metadata_bricks'] as $entry ) {
				$wp_filter['update_post_metadata']->callbacks[ $entry['priority'] ][ $entry['id'] ] = $entry['callback'];
			}
		}
	}

	/**
	 * Recursively sanitize a styles array for global classes.
	 *
	 * @param array<string, mixed> $styles The styles array to sanitize.
	 * @return array<string, mixed> Sanitized styles array.
	 */
	public function sanitize_styles_array( array $styles ): array {
		$sanitized = [];
		foreach ( $styles as $key => $value ) {
			$safe_key = wp_strip_all_tags( (string) $key );
			if ( is_array( $value ) ) {
				$sanitized[ $safe_key ] = $this->sanitize_styles_array( $value );
			} elseif ( is_string( $value ) ) {
				$sanitized[ $safe_key ] = self::strip_dangerous_css( wp_strip_all_tags( $value ) );
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
				$sanitized[ $safe_key ] = $value;
			}
		}
		return $sanitized;
	}

	/**
	 * Strip dangerous CSS patterns from a string value.
	 *
	 * Shared utility used by ElementNormalizer, BricksCore, and any code path
	 * that stores CSS values. Filters javascript:, expression(), data: URIs,
	 * -moz-binding, and behavior: properties.
	 *
	 * @param string $css The CSS string to sanitize.
	 * @return string Sanitized CSS string.
	 */
	public static function strip_dangerous_css( string $css ): string {
		// Strip CSS comments and hex escape sequences to prevent obfuscation bypass.
		$s = (string) preg_replace( '/\/\*.*?\*\//s', '', $css );
		$s = (string) preg_replace( '/\\\\[0-9a-fA-F]{1,6}\s?/', '', $s );

		$s = (string) preg_replace( '/\bjavascript\s*:/i', '', $s );
		$s = (string) preg_replace( '/\bexpression\s*\(/i', '', $s );
		$s = (string) preg_replace( '/url\s*\(\s*["\']?\s*data\s*:/i', 'url(about:', $s );
		$s = (string) preg_replace( '/-moz-binding\s*:/i', '', $s );
		$s = (string) preg_replace( '/\bbehavior\s*:/i', '', $s );
		return $s;
	}

	/**
	 * Type guard for element shapes used across the build pipeline.
	 *
	 * Returns true only when $value is an array carrying either a `name`
	 * (Bricks element) or `id` (tree node) key. Used to short-circuit
	 * subscript access in loops that may receive stdClass from
	 * JSON-without-assoc-decode or third-party filter hooks.
	 *
	 * Context: v3.24.1 fixed one instance of `Cannot use object of type
	 * stdClass as array` in the pipeline. v3.24.3 sweeps all 21+ remaining
	 * hot sites with this guard.
	 *
	 * @param mixed $value Candidate element.
	 * @return bool True when safe to subscript as array.
	 */
	public static function is_element_array( mixed $value ): bool {
		return is_array( $value ) && ( isset( $value['name'] ) || isset( $value['id'] ) );
	}

	/**
	 * Type guard for arbitrary array access in the pipeline.
	 *
	 * Loose sibling of is_element_array — use when you don't need the
	 * element contract (name/id), just array-ness before subscripting.
	 * Equivalent to `is_array($value)` but named to document intent.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool True when safe to subscript as array.
	 */
	public static function is_subscriptable( mixed $value ): bool {
		return is_array( $value );
	}

	/**
	 * Determine whether an element is at the root of the tree.
	 *
	 * Centralizes the root-parent check. Bricks stores `parent` as either
	 * an integer `0` or a string `'0'` depending on the write path;
	 * contradictory comparisons across Router and StreamableHttpHandler
	 * produced divergent results before this was centralized.
	 *
	 * @param mixed $element Element array (or object — handled defensively).
	 * @return bool True when the element is root-level (no parent).
	 */
	public static function is_root_element( mixed $element ): bool {
		if ( ! is_array( $element ) ) {
			return false;
		}
		$parent = $element['parent'] ?? 0;
		// Normalize to int so '0', 0, null, '' all compare uniformly.
		return 0 === (int) $parent;
	}

	/**
	 * Trigger Bricks CSS regeneration for a post after programmatic save.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function trigger_css_regeneration( int $post_id ): void {
		if ( ! $this->is_bricks_active() ) {
			return;
		}
		try {
			if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_setting' ) ) {
				$upload_dir = wp_upload_dir();
				$css_dir    = trailingslashit( $upload_dir['basedir'] ) . 'bricks/css/';
				$post_file  = $css_dir . 'post-' . $post_id . '.min.css';
				if ( file_exists( $post_file ) ) {
					wp_delete_file( $post_file );
				}
			}

			do_action( 'bricks/save_post', $post_id );

			if ( class_exists( '\Bricks\Assets' ) && method_exists( '\Bricks\Assets', 'generate_css_from_elements' ) ) {
				$elements = $this->get_elements( $post_id );
				\Bricks\Assets::generate_css_from_elements( $elements, $post_id );
			}

			$this->regenerate_style_manager_css();
		} catch ( \Throwable $e ) {
			// Log message + first frames of the trace so the root cause of a
			// regen failure is discoverable without reproducing the issue live.
			error_log( 'BricksMCP: CSS regen failed for post ' . $post_id . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() );
		}
	}

	/**
	 * Regenerate the Bricks style manager CSS file.
	 *
	 * @return bool True if CSS was regenerated, false if method not available.
	 */
	public function regenerate_style_manager_css(): bool {
		if ( class_exists( '\Bricks\Ajax' ) && method_exists( '\Bricks\Ajax', 'generate_style_manager_css_file' ) ) {
			\Bricks\Ajax::generate_style_manager_css_file();
			return true;
		}

		return false;
	}

	/**
	 * Acquire a simple mutex lock using the object cache.
	 *
	 * @param string $key Lock key suffix.
	 * @param int    $ttl Time-to-live in seconds (default 5).
	 * @return bool True if lock was acquired, false otherwise.
	 */
	public function acquire_lock( string $key, int $ttl = 5 ): bool {
		return (bool) wp_cache_add( 'bricks_mcp_lock_' . $key, 1, 'bricks_mcp', $ttl );
	}

	/**
	 * Release a previously acquired mutex lock.
	 *
	 * @param string $key Lock key suffix (must match the acquire call).
	 * @return void
	 */
	public function release_lock( string $key ): void {
		wp_cache_delete( 'bricks_mcp_lock_' . $key, 'bricks_mcp' );
	}

	/**
	 * Validate Bricks element parent/children dual-linkage integrity.
	 *
	 * @param array<int, array<string, mixed>> $elements Flat array of elements.
	 * @return true|\WP_Error True if valid, WP_Error on failure.
	 */
	public function validate_element_linkage( array $elements ): true|\WP_Error {
		$id_map = [];

		foreach ( $elements as $index => $element ) {
			foreach ( [ 'id', 'name', 'parent', 'children' ] as $key ) {
				if ( ! array_key_exists( $key, $element ) ) {
					return new \WP_Error(
						'invalid_element_structure',
						sprintf( 'Element at index %d is missing required key "%s".', $index, $key ),
						[
							'path'   => "elements[{$index}]",
							'reason' => sprintf( 'Missing required key: "%s"', $key ),
						]
					);
				}
			}

			if ( ! is_string( $element['id'] ) ) {
				return new \WP_Error(
					'invalid_element_structure',
					sprintf( 'Element at index %d has a non-string id.', $index ),
					[
						'path'   => "elements[{$index}].id",
						'reason' => 'Element ID must be a string.',
					]
				);
			}

			if ( ! preg_match( ElementIdGenerator::id_regex(), $element['id'] ) ) {
				return new \WP_Error(
					'invalid_element_structure',
					sprintf( 'Element at index %d has an invalid ID format: "%s".', $index, $element['id'] ),
					[
						'path'   => "elements[{$index}].id",
						'reason' => 'Element ID must be exactly 6 lowercase alphanumeric characters (a-z, 0-9).',
					]
				);
			}

			if ( ! is_string( $element['name'] ) ) {
				return new \WP_Error(
					'invalid_element_structure',
					sprintf( 'Element at index %d has a non-string name.', $index ),
					[
						'path'   => "elements[{$index}].name",
						'reason' => 'Element name must be a string.',
					]
				);
			}

			if ( ! is_array( $element['children'] ) ) {
				return new \WP_Error(
					'invalid_element_structure',
					sprintf( 'Element at index %d has a non-array children value.', $index ),
					[
						'path'   => "elements[{$index}].children",
						'reason' => 'Element children must be an array.',
					]
				);
			}

			if ( isset( $id_map[ $element['id'] ] ) ) {
				return new \WP_Error(
					'invalid_element_structure',
					sprintf( 'Duplicate element ID "%s" found at index %d.', $element['id'], $index ),
					[
						'path'   => "elements[{$index}].id",
						'reason' => sprintf( 'Duplicate element ID: "%s" already used at index %d.', $element['id'], $id_map[ $element['id'] ] ),
					]
				);
			}

			$id_map[ $element['id'] ] = $index;
		}

		foreach ( $elements as $index => $element ) {
			$parent = $element['parent'];

			if ( 0 !== $parent ) {
				$parent_str = (string) $parent;
				if ( ! isset( $id_map[ $parent_str ] ) ) {
					return new \WP_Error(
						'invalid_element_structure',
						sprintf( 'Element "%s" at index %d references non-existent parent "%s".', $element['id'], $index, $parent ),
						[
							'path'   => "elements[{$index}].parent",
							'reason' => sprintf( 'Parent element "%s" does not exist in the elements array.', $parent ),
						]
					);
				}

				$parent_index    = $id_map[ $parent_str ];
				$parent_children = $elements[ $parent_index ]['children'];

				if ( ! in_array( $element['id'], $parent_children, true ) ) {
					return new \WP_Error(
						'invalid_element_structure',
						sprintf( 'Element "%s" lists parent "%s", but parent\'s children array does not include "%s".', $element['id'], $parent, $element['id'] ),
						[
							'path'   => "elements[{$index}].parent",
							'reason' => sprintf( 'Linkage mismatch: parent "%s" does not list "%s" in its children array.', $parent, $element['id'] ),
						]
					);
				}
			}

			foreach ( $element['children'] as $child_index => $child_id ) {
				if ( ! isset( $id_map[ $child_id ] ) ) {
					return new \WP_Error(
						'invalid_element_structure',
						sprintf( 'Element "%s" lists non-existent child "%s".', $element['id'], $child_id ),
						[
							'path'   => "elements[{$index}].children[{$child_index}]",
							'reason' => sprintf( 'Child element "%s" does not exist in the elements array.', $child_id ),
						]
					);
				}

				$child_element = $elements[ $id_map[ $child_id ] ];
				if ( (string) $child_element['parent'] !== $element['id'] ) {
					return new \WP_Error(
						'invalid_element_structure',
						sprintf( 'Element "%s" lists "%s" as a child, but "%s" has a different parent.', $element['id'], $child_id, $child_id ),
						[
							'path'   => "elements[{$index}].children[{$child_index}]",
							'reason' => sprintf( 'Child "%s" does not list "%s" as its parent.', $child_id, $element['id'] ),
						]
					);
				}
			}
		}

		$visited  = [];
		$in_stack = [];

		foreach ( $elements as $element ) {
			if ( isset( $visited[ $element['id'] ] ) ) {
				continue;
			}

			$cycle_error = $this->detect_cycle( $element['id'], $elements, $id_map, $visited, $in_stack );
			if ( is_wp_error( $cycle_error ) ) {
				return $cycle_error;
			}
		}

		return true;
	}

	/**
	 * Detect cycles in element hierarchy using depth-first search.
	 *
	 * @param string                           $element_id  Current element ID.
	 * @param array<int, array<string, mixed>> $elements    All elements.
	 * @param array<string, int>               $id_map      Map of element ID to array index.
	 * @param array<string, bool>              $visited     Set of fully visited nodes.
	 * @param array<string, bool>              $in_stack    Set of nodes currently in recursion stack.
	 * @return true|\WP_Error True if no cycle, WP_Error if cycle detected.
	 */
	private function detect_cycle( string $element_id, array $elements, array $id_map, array &$visited, array &$in_stack ): true|\WP_Error {
		$visited[ $element_id ]  = true;
		$in_stack[ $element_id ] = true;

		if ( ! isset( $id_map[ $element_id ] ) ) {
			$in_stack[ $element_id ] = false;
			return true;
		}

		$element = $elements[ $id_map[ $element_id ] ];

		foreach ( $element['children'] as $child_id ) {
			if ( ! isset( $visited[ $child_id ] ) ) {
				$result = $this->detect_cycle( $child_id, $elements, $id_map, $visited, $in_stack );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			} elseif ( isset( $in_stack[ $child_id ] ) && $in_stack[ $child_id ] ) {
				return new \WP_Error(
					'invalid_element_structure',
					sprintf( 'Cycle detected: element "%s" is its own ancestor.', $child_id ),
					[
						'path'   => "elements[{$id_map[$element_id]}].children",
						'reason' => sprintf( 'Circular reference: "%s" creates a cycle in the element hierarchy.', $child_id ),
					]
				);
			}
		}

		$in_stack[ $element_id ] = false;
		return true;
	}

	/**
	 * Check if dangerous actions mode is enabled.
	 *
	 * @return bool True if dangerous actions mode is enabled.
	 */
	public function is_dangerous_actions_enabled(): bool {
		$settings = get_option( self::OPTION_SETTINGS, [] );
		return ! empty( $settings['dangerous_actions'] );
	}
}
