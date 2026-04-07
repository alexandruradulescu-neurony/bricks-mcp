<?php
/**
 * Bricks Builder data access service.
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
 * BricksService class.
 *
 * Provides the data access layer for reading and writing Bricks Builder content.
 * All Bricks-specific operations go through this service.
 */
class BricksService {

	/**
	 * Core infrastructure shared by all sub-services.
	 *
	 * @var BricksCore
	 */
	private BricksCore $core;

	/**
	 * Global CSS class management sub-service.
	 *
	 * @var GlobalClassService
	 */
	private GlobalClassService $global_class_service;

	/**
	 * Color palette management sub-service.
	 *
	 * @var ColorPaletteService
	 */
	private ColorPaletteService $color_palette_service;

	/**
	 * Global variable management sub-service.
	 *
	 * @var GlobalVariableService
	 */
	private GlobalVariableService $global_variable_service;

	/**
	 * Template management sub-service.
	 *
	 * @var TemplateService
	 */
	private TemplateService $template_service;

	/**
	 * SEO data management sub-service.
	 *
	 * @var SeoService
	 */
	private SeoService $seo_service;

	/**
	 * Typography scale management sub-service.
	 *
	 * @var TypographyScaleService
	 */
	private TypographyScaleService $typography_scale_service;

	/**
	 * Theme style management sub-service.
	 *
	 * @var ThemeStyleService
	 */
	private ThemeStyleService $theme_style_service;

	/**
	 * Page operations sub-service.
	 *
	 * @var PageOperationsService
	 */
	private PageOperationsService $page_operations_service;

	/**
	 * Settings management sub-service.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings_service;

	/**
	 * Constructor.
	 *
	 * Creates the shared core and all domain sub-services.
	 */
	public function __construct() {
		$normalizer = new ElementNormalizer( new ElementIdGenerator() );

		$this->core                    = new BricksCore( $normalizer );
		$this->global_class_service    = new GlobalClassService( $this->core );
		$this->color_palette_service   = new ColorPaletteService( $this->core );
		$this->global_variable_service = new GlobalVariableService( $this->core );
		$this->template_service        = new TemplateService( $this->core, $this->global_class_service );
		$this->seo_service             = new SeoService( $this->core );
		$this->typography_scale_service = new TypographyScaleService( $this->core );
		$this->theme_style_service     = new ThemeStyleService( $this->core );
		$this->page_operations_service = new PageOperationsService( $this->core );
		$this->settings_service        = new SettingsService( $this->core );
	}

	/**
	 * Set the validation service.
	 *
	 * When set, element settings are validated against Bricks schemas before every save.
	 * Invalid elements are rejected with detailed error messages including JSON paths.
	 *
	 * @param ValidationService $service Validation service instance.
	 * @return void
	 */
	public function set_validation_service( ValidationService $service ): void {
		$this->core->set_validation_service( $service );
	}

	/**
	 * Post meta key for Bricks page content.
	 * @see BricksCore::META_KEY
	 */
	public const META_KEY = BricksCore::META_KEY;

	/**
	 * Post meta key for Bricks editor mode.
	 * @see BricksCore::EDITOR_MODE_KEY
	 */
	public const EDITOR_MODE_KEY = BricksCore::EDITOR_MODE_KEY;

	/**
	 * Check if Bricks Builder is active.
	 *
	 * This is the single gate for all Bricks-specific functionality.
	 *
	 * @return bool True if Bricks Builder is installed and active.
	 */
	public function is_bricks_active(): bool {
		return $this->core->is_bricks_active();
	}

	/**
	 * Normalize element input using the ElementNormalizer.
	 *
	 * Detects input format (native flat array or simplified nested) and normalizes
	 * to the Bricks native flat array format. Exposed for use by the Router.
	 *
	 * @param array<int, array<string, mixed>> $input             Input elements.
	 * @param array<int, array<string, mixed>> $existing_elements Existing elements for collision-free IDs.
	 * @return array<int, array<string, mixed>> Normalized flat element array.
	 */
	public function normalize_elements( array $input, array $existing_elements = [] ): array {
		return $this->core->normalize_elements( $input, $existing_elements );
	}

	/**
	 * Check if a post is using the Bricks editor.
	 *
	 * @param int $post_id The post ID to check.
	 * @return bool True if the post uses Bricks editor.
	 */
	public function is_bricks_page( int $post_id ): bool {
		return $this->core->is_bricks_page( $post_id );
	}

	/**
	 * Get Bricks elements for a post.
	 *
	 * Reads the flat element array from post meta.
	 * WordPress automatically unserializes the stored data.
	 *
	 * @param int $post_id The post ID.
	 * @return array<int, array<string, mixed>> Flat array of elements, empty array if none.
	 */
	public function get_elements( int $post_id ): array {
		return $this->core->get_elements( $post_id );
	}

	/**
	 * Save Bricks elements for a post.
	 *
	 * @param int                              $post_id  The post ID.
	 * @param array<int, array<string, mixed>> $elements Flat array of elements to save.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function save_elements( int $post_id, array $elements ): true|\WP_Error {
		return $this->core->save_elements( $post_id, $elements );
	}

	/**
	 * Enable the Bricks editor for a post.
	 *
	 * Sets the editor mode meta key without requiring elements.
	 * Called when creating pages without initial elements.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function enable_bricks_editor( int $post_id ): void {
		$this->core->enable_bricks_editor( $post_id );
	}

	/**
	 * Disable the Bricks editor for a post.
	 *
	 * Removes the editor mode meta key. Bricks element content is preserved
	 * in the database and can be restored by re-enabling Bricks.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function disable_bricks_editor( int $post_id ): void {
		$this->core->disable_bricks_editor( $post_id );
	}

	/**
	 * Remove Bricks meta sanitize/update filters that block programmatic writes.
	 *
	 * @param string $meta_key Optional specific meta key to unhook.
	 * @return void
	 */
	public function unhook_bricks_meta_filters( string $meta_key = '' ): void {
		$this->core->unhook_bricks_meta_filters( $meta_key );
	}

	/**
	 * Re-hook Bricks meta filters after programmatic write.
	 *
	 * @return void
	 */
	public function rehook_bricks_meta_filters(): void {
		$this->core->rehook_bricks_meta_filters();
	}

	/**
	 * Validate Bricks element parent/children dual-linkage integrity.
	 *
	 * @param array<int, array<string, mixed>> $elements Flat array of elements.
	 * @return true|\WP_Error True if valid, WP_Error with code 'invalid_element_structure' on failure.
	 */
	public function validate_element_linkage( array $elements ): true|\WP_Error {
		return $this->core->validate_element_linkage( $elements );
	}

	/**
	 * Get all available responsive breakpoints.
	 *
	 * Returns breakpoint objects with key, label, width (px), and base indicator.
	 * Resolution: Bricks static property > bricks_breakpoints option > hardcoded defaults.
	 *
	 * @return array<int, array{key: string, label: string, width: int, base: bool}> Breakpoints list.
	 */
	public function get_breakpoints(): array {
		// 1. Try Bricks static Breakpoints class.
		if ( $this->is_bricks_active() && class_exists( '\Bricks\Breakpoints' ) ) {
			$bricks_bps = \Bricks\Breakpoints::$breakpoints ?? null;
			if ( is_array( $bricks_bps ) && ! empty( $bricks_bps ) ) {
				return $this->format_breakpoints( $bricks_bps );
			}
		}

		// 2. Try bricks_breakpoints option.
		$option_bps = get_option( 'bricks_breakpoints', [] );
		if ( is_array( $option_bps ) && ! empty( $option_bps ) ) {
			return $this->format_breakpoints( $option_bps );
		}

		// 3. Hardcoded defaults.
		return [
			[
				'key'   => 'desktop',
				'label' => 'Desktop',
				'width' => 1200,
				'base'  => true,
			],
			[
				'key'   => 'tablet_landscape',
				'label' => 'Tablet Landscape',
				'width' => 1024,
				'base'  => false,
			],
			[
				'key'   => 'tablet_portrait',
				'label' => 'Tablet Portrait',
				'width' => 768,
				'base'  => false,
			],
			[
				'key'   => 'mobile_landscape',
				'label' => 'Mobile Landscape',
				'width' => 480,
				'base'  => false,
			],
			[
				'key'   => 'mobile',
				'label' => 'Mobile',
				'width' => 0,
				'base'  => false,
			],
		];
	}

	/**
	 * Normalize Bricks breakpoint data into a standard format.
	 *
	 * Handles various formats Bricks may provide and normalizes to
	 * a consistent array of [key, label, width, base] objects.
	 *
	 * @param array<int|string, mixed> $breakpoints Raw breakpoint data from Bricks.
	 * @return array<int, array{key: string, label: string, width: int, base: bool}> Normalized breakpoints.
	 */
	private function format_breakpoints( array $breakpoints ): array {
		$result      = [];
		$has_desktop = false;

		foreach ( $breakpoints as $key => $bp ) {
			if ( is_array( $bp ) ) {
				$bp_key   = (string) ( $bp['key'] ?? $key );
				$bp_label = (string) ( $bp['label'] ?? ucwords( str_replace( '_', ' ', $bp_key ) ) );
				$bp_width = (int) ( $bp['width'] ?? 0 );
				$bp_base  = ! empty( $bp['base'] ) || 'desktop' === $bp_key;

				if ( $bp_base ) {
					$has_desktop = true;
				}

				$result[] = [
					'key'   => $bp_key,
					'label' => $bp_label,
					'width' => $bp_width,
					'base'  => $bp_base,
				];
			}
		}

		// If no breakpoint is marked as base, mark the first one.
		if ( ! $has_desktop && ! empty( $result ) ) {
			$result[0]['base'] = true;
		}

		return $result;
	}

	/**
	 * Create a new Bricks template.
	 *
	 * Inserts a bricks_template post, sets template type meta, enables Bricks editor,
	 * and optionally merges conditions into template settings.
	 *
	 * @param array<string, mixed> $args {
	 *     Template creation arguments.
	 *     @type string $title      Post title (required).
	 *     @type string $type       Template type slug (required, e.g., 'header', 'footer').
	 *     @type string $status     Post status (default: 'publish').
	 *     @type array  $conditions Optional Bricks condition objects to set on creation.
	 * }
	 * @return int|\WP_Error New template post ID on success, WP_Error on failure.
	 */
	public function create_template( array $args ): int|\WP_Error {
		return $this->template_service->create_template( $args );
	}

	/**
	 * Update Bricks template metadata.
	 *
	 * Updates title, status, slug, type, tags, and bundles. Does not touch element content.
	 * Changing type returns a warning in the response.
	 *
	 * @param int                  $template_id Template post ID.
	 * @param array<string, mixed> $args        Fields to update: title, status, slug, type, tags, bundles.
	 * @return true|array<string, mixed>|\WP_Error True on success, array with warning when type changed, WP_Error on failure.
	 */
	public function update_template_meta( int $template_id, array $args ): true|array|\WP_Error {
		return $this->template_service->update_template_meta( $template_id, $args );
	}

	/**
	 * @param int $template_id Template post ID to duplicate.
	 * @return int|\WP_Error New template post ID on success, WP_Error on failure.
	 */
	public function duplicate_template( int $template_id ): int|\WP_Error {
		return $this->template_service->duplicate_template( $template_id );
	}

	/**
	 * @param string $type   Optional template type filter.
	 * @param string $status Post status filter (default: 'publish').
	 * @param string $tag    Optional template_tag taxonomy slug filter.
	 * @param string $bundle Optional template_bundle taxonomy slug filter.
	 * @return array<int, array<string, mixed>> Array of template metadata.
	 */
	public function get_templates( string $type = '', string $status = 'publish', string $tag = '', string $bundle = '' ): array {
		return $this->template_service->get_templates( $type, $status, $tag, $bundle );
	}

	/**
	 * @param int $template_id Template post ID.
	 * @return array<string, mixed>|\WP_Error Template content or WP_Error if not found.
	 */
	public function get_template_content_data( int $template_id ): array|\WP_Error {
		return $this->template_service->get_template_content_data( $template_id );
	}

	/**
	 * @param mixed $settings Template settings (may be array, empty, or non-array).
	 * @return array<int, array{summary: string, raw: array}> Formatted conditions.
	 */
	public function format_conditions( mixed $settings ): array {
		return $this->template_service->format_conditions( $settings );
	}

	/**
	 * @param string $search   Optional partial name match filter.
	 * @param string $category Optional category ID filter.
	 * @return array<int, array<string, mixed>> Array of global classes.
	 */
	public function get_global_classes( string $search = '', string $category = '' ): array {
		return $this->global_class_service->get_global_classes( $search, $category );
	}

	public function create_global_class( array $args ): array|\WP_Error {
		return $this->global_class_service->create_global_class( $args );
	}

	public function update_global_class( string $class_id, array $args ): array|\WP_Error {
		return $this->global_class_service->update_global_class( $class_id, $args );
	}

	public function trash_global_class( string $class_id ): true|\WP_Error {
		return $this->global_class_service->trash_global_class( $class_id );
	}

	public function find_class_references( string $class_id ): array {
		return $this->global_class_service->find_class_references( $class_id );
	}

	public function batch_create_global_classes( array $class_definitions ): array {
		return $this->global_class_service->batch_create_global_classes( $class_definitions );
	}

	public function batch_trash_global_classes( array $class_ids ): array {
		return $this->global_class_service->batch_trash_global_classes( $class_ids );
	}

	public function get_global_class_categories(): array {
		return $this->global_class_service->get_global_class_categories();
	}

	public function create_global_class_category( string $name ): array|\WP_Error {
		return $this->global_class_service->create_global_class_category( $name );
	}

	public function delete_global_class_category( string $category_id ): true|\WP_Error {
		return $this->global_class_service->delete_global_class_category( $category_id );
	}

	public function import_classes_from_css( string $css_string ): array {
		return $this->global_class_service->import_classes_from_css( $css_string );
	}

	public function resolve_class_name( string $name ): ?array {
		return $this->global_class_service->resolve_class_name( $name );
	}

	public function apply_class_to_elements( int $post_id, string $class_id, array $element_ids ): true|\WP_Error {
		return $this->global_class_service->apply_class_to_elements( $post_id, $class_id, $element_ids );
	}

	public function remove_class_from_elements( int $post_id, string $class_id, array $element_ids ): true|\WP_Error {
		return $this->global_class_service->remove_class_from_elements( $post_id, $class_id, $element_ids );
	}

	/**
	 * Get a tree outline summary of a page's Bricks elements.
	 *
	 * Returns element names/IDs in tree structure with type counts.
	 *
	 * @param int $post_id The post ID.
	 * @return array<string, mixed> Tree outline with type counts.
	 */
	public function get_page_summary( int $post_id ): array {
		return $this->page_operations_service->get_page_summary( $post_id );
	}

	public function get_page_context( int $post_id ): array {
		return $this->page_operations_service->get_page_context( $post_id );
	}

	public function get_page_metadata( int $post_id ): array {
		return $this->page_operations_service->get_page_metadata( $post_id );
	}

	/**
	 * Describe a page: human-readable section-by-section descriptions.
	 *
	 * @param int $post_id The post ID.
	 * @return array<string, mixed>|\WP_Error Page description with section details.
	 */
	public function describe_page( int $post_id ): array|\WP_Error {
		return $this->page_operations_service->describe_page( $post_id );
	}

	/**
	 * Get all post types that have Bricks editing enabled.
	 *
	 * Checks Bricks database settings if Bricks is active, falls back to defaults.
	 *
	 * @return array<int, string> Array of post type slugs.
	 */
	public function get_bricks_post_types(): array {
		if ( $this->is_bricks_active() && class_exists( '\Bricks\Database' ) ) {
			$post_types = \Bricks\Database::get_setting( 'postTypes' );
			if ( is_array( $post_types ) && ! empty( $post_types ) ) {
				return array_values( $post_types );
			}
		}

		return [ 'page', 'post' ];
	}

	/**
	 * Create a new post/page with Bricks editor enabled.
	 *
	 * Inserts the post, enables Bricks editor, optionally saves elements.
	 * Title is sanitized. Elements are normalized and validated before save.
	 *
	 * @param array<string, mixed> $args {
	 *     Page creation arguments.
	 *     @type string $title     Post title (required).
	 *     @type string $post_type Post type, default 'page'.
	 *     @type string $status    Post status, default 'draft'.
	 *     @type array  $elements  Optional initial elements (native or simplified format).
	 * }
	 * @return int|\WP_Error New post ID on success, WP_Error on failure.
	 */
	public function create_page( array $args ): int|\WP_Error {
		return $this->page_operations_service->create_page( $args );
	}

	public function update_page_meta( int $post_id, array $args ): true|\WP_Error {
		return $this->page_operations_service->update_page_meta( $post_id, $args );
	}

	public function delete_page( int $post_id ): true|\WP_Error {
		return $this->page_operations_service->delete_page( $post_id );
	}

	/**
	 * Check if a page is protected from AI modifications.
	 *
	 * @param int $post_id Post ID to check.
	 * @return \WP_Error|null WP_Error if protected, null if allowed.
	 */
	public function check_protected_page( int $post_id ): ?\WP_Error {
		return $this->page_operations_service->check_protected_page( $post_id );
	}

	public function duplicate_page( int $post_id ): int|\WP_Error {
		return $this->page_operations_service->duplicate_page( $post_id );
	}

	public function snapshot_page( int $post_id, string $label = '' ): array|\WP_Error {
		return $this->page_operations_service->snapshot_page( $post_id, $label );
	}

	public function restore_snapshot( int $post_id, string $snapshot_id ): array|\WP_Error {
		return $this->page_operations_service->restore_snapshot( $post_id, $snapshot_id );
	}

	public function list_snapshots( int $post_id ): array|\WP_Error {
		return $this->page_operations_service->list_snapshots( $post_id );
	}

	public function get_condition_types(): array {
		return $this->template_service->get_condition_types();
	}

	public function set_template_conditions( int $template_id, array $conditions ): true|\WP_Error {
		return $this->template_service->set_template_conditions( $template_id, $conditions );
	}

	public function resolve_templates_for_post( int $post_id ): array|\WP_Error {
		return $this->template_service->resolve_templates_for_post( $post_id );
	}
	/**
	 * Add a single element to an existing Bricks page.
	 *
	 * Gets current elements, normalizes the new element via ElementNormalizer,
	 * merges into existing, validates linkage, then saves.
	 * Supports both simplified and native format for the new element.
	 *
	 * @param int                  $post_id   Post ID.
	 * @param array<string, mixed> $element   Element data (simplified or native format).
	 * @param string               $parent_id Parent element ID ('0' for root).
	 * @param int|null             $position  Position in parent's children array (null = append at end).
	 * @return array<string, mixed>|\WP_Error Array with element_id on success, WP_Error on failure.
	 */
	public function add_element( int $post_id, array $element, string $parent_id = '0', ?int $position = null ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id )
			);
		}

		$existing = $this->get_elements( $post_id );

		// Wrap single element in array for normalization.
		$input     = [ $element ];
		$parent    = '0' !== $parent_id ? $parent_id : 0;
		$new_input = array_map(
			static function ( array $el ) use ( $parent ) {
				if ( ! array_key_exists( 'parent', $el ) ) {
					$el['parent'] = $parent;
				}
				return $el;
			},
			$input
		);

		$normalized = $this->core->get_normalizer()->normalize( $new_input, $existing );

		// For simplified format output, set parent_id correctly on top-level elements.
		if ( ! empty( $normalized ) ) {
			$normalized[0]['parent'] = '0' !== $parent_id ? $parent_id : 0;

			// Regenerate children IDs in first element to correct any issues.
			$child_ids      = [];
			$normalized_len = count( $normalized );
			for ( $i = 1; $i < $normalized_len; $i++ ) {
				if ( (string) $normalized[ $i ]['parent'] === $normalized[0]['id'] ) {
					$child_ids[] = $normalized[ $i ]['id'];
				}
			}
			$normalized[0]['children'] = $child_ids;
		}

		$merged = $this->core->get_normalizer()->merge_elements( $existing, $normalized, $parent_id, $position );
		$saved  = $this->save_elements( $post_id, $merged );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [
			'element_id'    => $normalized[0]['id'] ?? '',
			'post_id'       => $post_id,
			'element_count' => count( $merged ),
		];
	}

	/**
	 * Append multiple elements to existing page content.
	 *
	 * Unlike update_content (which replaces), this merges new elements into existing content.
	 * Supports all three input formats: flat, parent-ref, and nested tree.
	 *
	 * @param int      $post_id      Post ID.
	 * @param array    $new_elements New elements to append (any supported format).
	 * @param string   $parent_id    Parent element ID ('0' for root level).
	 * @param int|null $position     Position within parent's children (null = append at end).
	 * @return array|\WP_Error Result with element count and appended IDs, or error.
	 */
	public function append_elements( int $post_id, array $new_elements, string $parent_id = '0', ?int $position = null ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id )
			);
		}

		$existing = $this->get_elements( $post_id );

		// Set parent on top-level elements that don't specify one.
		$parent       = '0' !== $parent_id ? $parent_id : 0;
		$new_elements = array_map(
			static function ( $el ) use ( $parent ) {
				if ( is_array( $el ) && ! array_key_exists( 'parent', $el ) ) {
					$el['parent'] = $parent;
				}
				return $el;
			},
			$new_elements
		);

		// Normalize new elements (supports flat, parent-ref, and tree formats).
		$normalized = $this->core->get_normalizer()->normalize( $new_elements, $existing );

		if ( empty( $normalized ) ) {
			return new \WP_Error( 'empty_elements', __( 'No valid elements to append after normalization.', 'bricks-mcp' ) );
		}

		// Collect IDs of top-level appended elements (direct children of target parent).
		$appended_root_ids = [];
		foreach ( $normalized as $el ) {
			if ( (string) $el['parent'] === $parent_id || ( '0' === $parent_id && 0 === $el['parent'] ) ) {
				$appended_root_ids[] = $el['id'];
			}
		}

		// Merge into existing content.
		$merged = $this->core->get_normalizer()->merge_elements( $existing, $normalized, $parent_id, $position );
		$saved  = $this->save_elements( $post_id, $merged );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [
			'post_id'             => $post_id,
			'appended_ids'        => $appended_root_ids,
			'appended_count'      => count( $normalized ),
			'total_element_count' => count( $merged ),
			'changes'             => $this->diff_elements( $existing, $merged ),
		];
	}

	/**
	 * Import Bricks clipboard JSON (bricksCopiedElements format).
	 *
	 * Creates global classes from the clipboard data, remaps class IDs in elements,
	 * and appends elements to the page.
	 *
	 * @param int      $post_id        Post ID.
	 * @param array    $clipboard_data Clipboard JSON with 'content' and optional 'globalClasses'.
	 * @param string   $parent_id      Parent element ID ('0' for root level).
	 * @param int|null $position       Position within parent's children.
	 * @return array|\WP_Error Result or error.
	 */
	public function import_clipboard( int $post_id, array $clipboard_data, string $parent_id = '0', ?int $position = null ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf( __( 'Post %d not found.', 'bricks-mcp' ), $post_id )
			);
		}

		$content        = $clipboard_data['content'] ?? [];
		$global_classes = $clipboard_data['globalClasses'] ?? [];

		if ( empty( $content ) || ! is_array( $content ) ) {
			return new \WP_Error( 'empty_content', __( 'clipboard_data must contain a non-empty content array.', 'bricks-mcp' ) );
		}

		// Build class ID → settings map from clipboard global classes.
		$class_settings_map = [];
		if ( ! empty( $global_classes ) && is_array( $global_classes ) ) {
			foreach ( $global_classes as $gc ) {
				if ( isset( $gc['id'] ) && isset( $gc['settings'] ) && is_array( $gc['settings'] ) && ! empty( $gc['settings'] ) ) {
					$class_settings_map[ $gc['id'] ] = $gc['settings'];
				}
			}
		}

		// Flatten: resolve global class styles into inline element settings,
		// then remove _cssGlobalClasses references. This produces self-contained
		// elements that don't depend on foreign global classes.
		$content         = $this->flatten_global_classes_into_elements( $content, $class_settings_map );
		$flattened_count = count( $class_settings_map );

		// Append elements to page.
		$append_result = $this->append_elements( $post_id, $content, $parent_id, $position );

		if ( is_wp_error( $append_result ) ) {
			return $append_result;
		}

		return [
			'post_id'             => $post_id,
			'flattened_classes'   => $flattened_count,
			'appended_ids'        => $append_result['appended_ids'] ?? [],
			'appended_count'      => $append_result['appended_count'] ?? 0,
			'total_element_count' => $append_result['total_element_count'] ?? 0,
		];
	}

	/**
	 * Flatten global class styles into inline element settings.
	 *
	 * For each element with _cssGlobalClasses, looks up the class settings from
	 * the provided map and merges them into the element's inline settings.
	 * Class settings are applied first (lower priority), then element's own
	 * settings override on top. The _cssGlobalClasses key is removed.
	 *
	 * This produces self-contained elements using CSS variables instead of
	 * depending on foreign global classes that may not exist on the target site.
	 *
	 * @param array $elements          Elements array (flat or tree format).
	 * @param array $class_settings_map Class ID → settings array mapping.
	 * @return array Elements with flattened inline settings.
	 */
	private function flatten_global_classes_into_elements( array $elements, array $class_settings_map ): array {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['settings']['_cssGlobalClasses'] ) && is_array( $element['settings']['_cssGlobalClasses'] ) ) {
				$merged_class_settings = [];

				// Merge all referenced class settings (in order, later classes override earlier).
				foreach ( $element['settings']['_cssGlobalClasses'] as $class_id ) {
					if ( isset( $class_settings_map[ $class_id ] ) ) {
						$merged_class_settings = $this->deep_merge_settings( $merged_class_settings, $class_settings_map[ $class_id ] );
					}
				}

				// Element's own settings override class settings.
				$own_settings = $element['settings'];
				unset( $own_settings['_cssGlobalClasses'] );
				$element['settings'] = $this->deep_merge_settings( $merged_class_settings, $own_settings );
			}

			// Recurse into children (for nested tree format).
			if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
				$has_nested_children = false;
				foreach ( $element['children'] as $child ) {
					if ( is_array( $child ) && isset( $child['name'] ) ) {
						$has_nested_children = true;
						break;
					}
				}
				if ( $has_nested_children ) {
					$element['children'] = $this->flatten_global_classes_into_elements( $element['children'], $class_settings_map );
				}
			}
		}
		unset( $element );

		return $elements;
	}

	/**
	 * Deep merge two settings arrays. Values in $override replace values in $base.
	 * For nested arrays with string keys, merge recursively.
	 * For sequential arrays (numeric keys), $override replaces entirely.
	 *
	 * @param array $base     Base settings (lower priority).
	 * @param array $override Override settings (higher priority).
	 * @return array Merged settings.
	 */
	private function deep_merge_settings( array $base, array $override ): array {
		$merged = $base;

		foreach ( $override as $key => $value ) {
			if ( is_int( $key ) ) {
				// Sequential array — override replaces entirely (handled at parent level).
				$merged[ $key ] = $value;
				continue;
			}

			if ( is_array( $value ) && isset( $merged[ $key ] ) && is_array( $merged[ $key ] ) ) {
				// Both are arrays with string keys — recurse.
				if ( $this->is_associative( $value ) && $this->is_associative( $merged[ $key ] ) ) {
					$merged[ $key ] = $this->deep_merge_settings( $merged[ $key ], $value );
					continue;
				}
			}

			$merged[ $key ] = $value;
		}

		return $merged;
	}

	/**
	 * Check if an array is associative (has string keys).
	 */
	private function is_associative( array $arr ): bool {
		if ( empty( $arr ) ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Update settings for a specific element on a Bricks page.
	 *
	 * Finds element by ID in the flat array, merges new settings into existing,
	 * validates, and saves. Returns error if element not found.
	 *
	 * IMPORTANT: The settings merge is shallow (array_merge). Callers must send
	 * complete sub-objects for nested keys — partial nested objects will replace
	 * the entire existing value, not deep-merge into it.
	 *
	 * @param int                  $post_id    Post ID.
	 * @param string               $element_id Element ID to update.
	 * @param array<string, mixed> $settings   Settings to merge with existing (shallow merge).
	 * @return array<string, mixed>|\WP_Error Updated info on success, WP_Error on failure.
	 */
	public function update_element( int $post_id, string $element_id, array $settings ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id )
			);
		}

		$elements = $this->get_elements( $post_id );
		$found    = false;

		$normalizer = $this->core->get_normalizer();

		foreach ( $elements as $index => $element ) {
			if ( $element['id'] === $element_id ) {
				$existing_settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
				$element_name      = $element['name'] ?? '';

				// Apply key corrections and sanitization (same as add/append paths).
				$corrected = $normalizer->apply_key_corrections( $settings, $element_name );
				$sanitized = $normalizer->sanitize_settings( $corrected, $element_name );

				$elements[ $index ]['settings'] = array_merge( $existing_settings, $sanitized );
				$found                          = true;
				break;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'element_not_found',
				sprintf(
					/* translators: 1: Element ID, 2: Post ID */
					__( 'Element "%1$s" not found on post %2$d. Use page:get to retrieve valid element IDs.', 'bricks-mcp' ),
					$element_id,
					$post_id
				)
			);
		}

		$saved = $this->save_elements( $post_id, $elements );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [
			'element_id'             => $element_id,
			'updated_settings_count' => count( $settings ),
		];
	}

	public function get_template_terms( string $taxonomy ): array|\WP_Error {
		return $this->template_service->get_template_terms( $taxonomy );
	}

	public function create_template_term( string $taxonomy, string $name ): array|\WP_Error {
		return $this->template_service->create_template_term( $taxonomy, $name );
	}

	public function delete_template_term( string $taxonomy, int $term_id ): true|\WP_Error {
		return $this->template_service->delete_template_term( $taxonomy, $term_id );
	}

	/**
	 * Remove an element from a Bricks page.
	 *
	 * Removes element by ID. Children of the removed element are re-parented
	 * to the removed element's parent, maintaining hierarchy integrity.
	 * Removes the element from its parent's children array.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $element_id Element ID to remove.
	 * @return array<string, mixed>|\WP_Error Removal info on success, WP_Error on failure.
	 */
	public function remove_element( int $post_id, string $element_id, bool $cascade = false ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id )
			);
		}

		$elements = $this->get_elements( $post_id );

		// Find the element to remove.
		$target_index = null;
		$target       = null;

		foreach ( $elements as $index => $element ) {
			if ( $element['id'] === $element_id ) {
				$target_index = $index;
				$target       = $element;
				break;
			}
		}

		if ( null === $target ) {
			return new \WP_Error(
				'element_not_found',
				sprintf(
					/* translators: 1: Element ID, 2: Post ID */
					__( 'Element "%1$s" not found on post %2$d. Use page:get to retrieve valid element IDs.', 'bricks-mcp' ),
					$element_id,
					$post_id
				)
			);
		}

		$target_parent   = $target['parent'];
		$target_children = $target['children'];

		if ( $cascade ) {
			// Collect ALL descendant IDs recursively.
			$remove_ids = [ $element_id ];
			$queue      = $target_children;
			$by_id      = [];
			foreach ( $elements as $el ) {
				$by_id[ $el['id'] ] = $el;
			}
			while ( ! empty( $queue ) ) {
				$cid = array_shift( $queue );
				$remove_ids[] = $cid;
				if ( isset( $by_id[ $cid ] ) && ! empty( $by_id[ $cid ]['children'] ) ) {
					foreach ( $by_id[ $cid ]['children'] as $grandchild ) {
						$queue[] = $grandchild;
					}
				}
			}

			$remove_set = array_flip( $remove_ids );

			// Filter out all removed elements and update parent's children.
			$updated_elements = [];
			foreach ( $elements as $element ) {
				if ( isset( $remove_set[ $element['id'] ] ) ) {
					continue;
				}
				// Update parent's children array to remove the target.
				if ( $element['id'] === (string) $target_parent ) {
					$element['children'] = array_values(
						array_filter(
							$element['children'],
							static fn( string $cid ) => $cid !== $element_id
						)
					);
				}
				$updated_elements[] = $element;
			}

			$removed_count = count( $remove_ids );
		} else {
			// Original behavior: re-parent children to grandparent.
			$updated_elements = [];
			foreach ( $elements as $index => $element ) {
				if ( $index === $target_index ) {
					continue;
				}
				if ( in_array( $element['id'], $target_children, true ) ) {
					$element['parent'] = $target_parent;
				}
				if ( $element['id'] === (string) $target_parent ) {
					$element['children'] = array_values(
						array_filter(
							$element['children'],
							static fn( string $cid ) => $cid !== $element_id
						)
					);
					$element['children'] = array_values(
						array_unique( array_merge( $element['children'], $target_children ) )
					);
				}
				$updated_elements[] = $element;
			}

			$removed_count = 1;
		}

		$saved = $this->save_elements( $post_id, $updated_elements );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [
			'removed_element_id' => $element_id,
			'removed_count'      => $removed_count,
			'post_id'            => $post_id,
			'element_count'      => count( $updated_elements ),
			'changes'            => $this->diff_elements( $elements, $updated_elements ),
		];
	}

	/**
	 * Duplicate an element and all its descendants with new IDs.
	 *
	 * @param int         $post_id       Post ID.
	 * @param string      $element_id    Element ID to duplicate.
	 * @param string|null $target_parent Target parent ID (null = same parent as original).
	 * @param int|null    $position      Position in target parent's children (null = after original).
	 * @return array|\WP_Error Result with new element ID, or error.
	 */
	public function duplicate_element( int $post_id, string $element_id, ?string $target_parent = null, ?int $position = null ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf( __( 'Post %d not found.', 'bricks-mcp' ), $post_id )
			);
		}

		$elements = $this->get_elements( $post_id );

		// Find the target element.
		$target       = null;
		$target_index = null;
		foreach ( $elements as $index => $el ) {
			if ( $el['id'] === $element_id ) {
				$target       = $el;
				$target_index = $index;
				break;
			}
		}

		if ( null === $target ) {
			return new \WP_Error(
				'element_not_found',
				sprintf( __( 'Element "%s" not found on post %d.', 'bricks-mcp' ), $element_id, $post_id )
			);
		}

		// Collect the element and all descendants via BFS.
		$subtree_ids = [ $element_id ];
		$by_id       = [];
		foreach ( $elements as $el ) {
			$by_id[ $el['id'] ] = $el;
		}
		$queue = $target['children'] ?? [];
		while ( ! empty( $queue ) ) {
			$cid           = array_shift( $queue );
			$subtree_ids[] = $cid;
			if ( isset( $by_id[ $cid ] ) && ! empty( $by_id[ $cid ]['children'] ) ) {
				foreach ( $by_id[ $cid ]['children'] as $grandchild ) {
					$queue[] = $grandchild;
				}
			}
		}

		// Generate new IDs for each element in the subtree.
		$id_generator = new ElementIdGenerator();
		$id_map       = [];
		foreach ( $subtree_ids as $old_id ) {
			$id_map[ $old_id ] = $id_generator->generate_unique(
				array_merge(
					$elements,
					array_map( fn( $id ) => [ 'id' => $id ], array_values( $id_map ) )
				)
			);
		}

		// Deep-copy subtree elements with new IDs and remapped parent/children refs.
		$cloned = [];
		foreach ( $subtree_ids as $old_id ) {
			if ( ! isset( $by_id[ $old_id ] ) ) {
				continue;
			}
			$el       = $by_id[ $old_id ];
			$el['id'] = $id_map[ $old_id ];

			// Remap parent.
			if ( isset( $id_map[ (string) $el['parent'] ] ) ) {
				$el['parent'] = $id_map[ (string) $el['parent'] ];
			}

			// Remap children.
			$el['children'] = array_map(
				fn( string $cid ) => $id_map[ $cid ] ?? $cid,
				$el['children'] ?? []
			);

			// Update _cssCustom references to old ID if present.
			if ( ! empty( $el['settings']['_cssCustom'] ) && is_string( $el['settings']['_cssCustom'] ) ) {
				$el['settings']['_cssCustom'] = str_replace(
					'#brxe-' . $old_id,
					'#brxe-' . $id_map[ $old_id ],
					$el['settings']['_cssCustom']
				);
			}

			$cloned[] = $el;
		}

		// Set parent of the root cloned element.
		$clone_parent        = $target_parent ?? (string) $target['parent'];
		$cloned[0]['parent'] = '0' === $clone_parent || '' === $clone_parent ? 0 : $clone_parent;

		// Insert cloned elements into the page.
		if ( null === $target_parent ) {
			$parent_key    = (string) $target['parent'];
			$is_root_level = '0' === $parent_key || '' === $parent_key || 0 === $target['parent'];

			// Update parent's children array (skip for root-level — no parent element to update).
			if ( ! $is_root_level ) {
				foreach ( $elements as &$el ) {
					if ( $el['id'] === $parent_key ) {
						if ( null === $position ) {
							$orig_pos = array_search( $element_id, $el['children'], true );
							if ( false !== $orig_pos ) {
								array_splice( $el['children'], $orig_pos + 1, 0, [ $cloned[0]['id'] ] );
							} else {
								$el['children'][] = $cloned[0]['id'];
							}
						} else {
							array_splice( $el['children'], $position, 0, [ $cloned[0]['id'] ] );
						}
						break;
					}
				}
				unset( $el );
			}

			// Insert cloned elements into flat array after original subtree.
			$last_subtree_index = $target_index;
			$subtree_set        = array_flip( $subtree_ids );
			foreach ( $elements as $idx => $el ) {
				if ( isset( $subtree_set[ $el['id'] ] ) && $idx > $last_subtree_index ) {
					$last_subtree_index = $idx;
				}
			}
			if ( null !== $position && $is_root_level ) {
				array_splice( $elements, $position * ( count( $subtree_ids ) + 1 ), 0, $cloned );
			} else {
				array_splice( $elements, $last_subtree_index + 1, 0, $cloned );
			}
		} else {
			// Different target parent — use the normalizer's merge.
			$elements = $this->core->get_normalizer()->merge_elements( $elements, $cloned, $target_parent, $position );
		}

		$saved = $this->save_elements( $post_id, $elements );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [
			'original_id'   => $element_id,
			'duplicate_id'  => $cloned[0]['id'],
			'cloned_count'  => count( $cloned ),
			'post_id'       => $post_id,
			'element_count' => count( $elements ),
		];
	}

	/**
	 * Move or reorder a Bricks element within a page's element tree.
	 *
	 * Supports both reparenting (changing parent) and reordering (changing position
	 * within same parent). Moving a parent element moves its entire subtree automatically
	 * since children reference their parent by ID.
	 *
	 * @param int      $post_id          Post ID.
	 * @param string   $element_id       Element ID to move.
	 * @param string   $target_parent_id Target parent ID ('' to keep current parent for reorder-only, '0' for root).
	 * @param int|null $position         0-indexed position among siblings (null to append at end).
	 * @return array<string, mixed>|\WP_Error Move result or WP_Error on failure.
	 */
	public function move_element( int $post_id, string $element_id, string $target_parent_id = '', ?int $position = null ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id )
			);
		}

		$elements = $this->get_elements( $post_id );

		if ( empty( $elements ) ) {
			return new \WP_Error(
				'no_elements',
				sprintf(
					/* translators: %d: Post ID */
					__( 'No Bricks elements found on post %d.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		// Build id_map for O(1) lookups.
		$id_map = [];
		foreach ( $elements as $index => $element ) {
			$id_map[ $element['id'] ] = $index;
		}

		// Validate element exists.
		if ( ! isset( $id_map[ $element_id ] ) ) {
			return new \WP_Error(
				'element_not_found',
				sprintf(
					/* translators: 1: Element ID, 2: Post ID */
					__( 'Element "%1$s" not found on post %2$d. Use page:get to retrieve valid element IDs.', 'bricks-mcp' ),
					$element_id,
					$post_id
				)
			);
		}

		// Determine effective target parent.
		// '' (empty string) = reorder-only, keep current parent.
		// '0' = move to root level.
		// Otherwise = move to specified parent.
		$old_parent = $elements[ $id_map[ $element_id ] ]['parent'];

		if ( '' === $target_parent_id ) {
			// Reorder-only: keep current parent.
			$effective_target = $old_parent;
		} elseif ( '0' === $target_parent_id ) {
			// Move to root.
			$effective_target = 0;
		} else {
			// Validate target parent exists.
			if ( ! isset( $id_map[ $target_parent_id ] ) ) {
				return new \WP_Error(
					'parent_not_found',
					sprintf(
						/* translators: %s: Parent element ID */
						__( 'Target parent element "%s" not found. Use page:get to retrieve valid element IDs.', 'bricks-mcp' ),
						$target_parent_id
					)
				);
			}
			$effective_target = $target_parent_id;
		}

		// Remove element_id from old parent's children array (if old parent is not root).
		if ( 0 !== $old_parent && '' !== (string) $old_parent ) {
			$old_parent_str = (string) $old_parent;
			if ( isset( $id_map[ $old_parent_str ] ) ) {
				$old_parent_idx                          = $id_map[ $old_parent_str ];
				$elements[ $old_parent_idx ]['children'] = array_values(
					array_filter(
						$elements[ $old_parent_idx ]['children'],
						static fn( string $cid ) => $cid !== $element_id
					)
				);
			}
		}

		// Update element's parent field (reorder-only leaves parent unchanged).
		if ( '0' === $target_parent_id ) {
			$elements[ $id_map[ $element_id ] ]['parent'] = 0;
		} elseif ( '' !== $target_parent_id ) {
			$elements[ $id_map[ $element_id ] ]['parent'] = $target_parent_id;
		}

		// Insert into new parent's children array.
		if ( 0 === $effective_target || '0' === (string) $effective_target ) {
			// Root-level: reposition element within the flat array among root elements.
			// Extract element from current position.
			$el = array_splice( $elements, $id_map[ $element_id ], 1 )[0];

			// Rebuild id_map since indices shifted.
			$id_map = [];
			foreach ( $elements as $idx => $elem ) {
				$id_map[ $elem['id'] ] = $idx;
			}

			if ( null === $position ) {
				// Append after the last root element.
				$last_root_idx = -1;
				foreach ( $elements as $idx => $elem ) {
					if ( 0 === $elem['parent'] ) {
						$last_root_idx = $idx;
					}
				}
				array_splice( $elements, $last_root_idx + 1, 0, [ $el ] );
			} else {
				// Count root elements to find correct flat array insertion point.
				$root_count      = 0;
				$insertion_point = count( $elements ); // Default: append.
				foreach ( $elements as $idx => $elem ) {
					if ( 0 === $elem['parent'] ) {
						if ( $root_count === $position ) {
							$insertion_point = $idx;
							break;
						}
						++$root_count;
					}
				}
				array_splice( $elements, $insertion_point, 0, [ $el ] );
			}
		} else {
			// Non-root target: update target parent's children array.
			$target_parent_idx = $id_map[ (string) $effective_target ];
			if ( null === $position ) {
				$elements[ $target_parent_idx ]['children'][] = $element_id;
			} else {
				array_splice( $elements[ $target_parent_idx ]['children'], $position, 0, [ $element_id ] );
			}
		}

		// Save (validate_element_linkage runs automatically inside save_elements).
		$saved = $this->save_elements( $post_id, $elements );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Rebuild id_map to get accurate new_parent from post-save state.
		$id_map = [];
		foreach ( $elements as $index => $element ) {
			$id_map[ $element['id'] ] = $index;
		}

		$moved_element = $elements[ $id_map[ $element_id ] ];

		return [
			'element_id'    => $element_id,
			'old_parent'    => $old_parent,
			'new_parent'    => $moved_element['parent'],
			'position'      => $position,
			'subtree_moved' => ! empty( $moved_element['children'] ),
		];
	}

	/**
	 * Find elements matching criteria on a page.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $criteria Search criteria (AND logic): type, class_id, has_setting, text_contains.
	 * @return array|\WP_Error Matching elements or error.
	 */
	public function find_elements( int $post_id, array $criteria ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', sprintf( __( 'Post %d not found.', 'bricks-mcp' ), $post_id ) );
		}

		$elements = $this->get_elements( $post_id );
		$matches  = [];

		$type          = $criteria['type'] ?? '';
		$class_id      = $criteria['class_id'] ?? '';
		$has_setting   = $criteria['has_setting'] ?? '';
		$text_contains = $criteria['text_contains'] ?? '';
		$content_keys  = [ 'text', 'content', 'html', 'label', 'caption', 'description', 'buttonText' ];

		foreach ( $elements as $el ) {
			$settings = $el['settings'] ?? [];

			// Filter by element type.
			if ( '' !== $type && ( $el['name'] ?? '' ) !== $type ) {
				continue;
			}

			// Filter by global class ID.
			if ( '' !== $class_id ) {
				$classes = $settings['_cssGlobalClasses'] ?? [];
				if ( ! in_array( $class_id, $classes, true ) ) {
					continue;
				}
			}

			// Filter by setting key existence.
			if ( '' !== $has_setting && ! isset( $settings[ $has_setting ] ) ) {
				continue;
			}

			// Filter by text content.
			if ( '' !== $text_contains ) {
				$found = false;
				foreach ( $content_keys as $key ) {
					if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) && false !== stripos( $settings[ $key ], $text_contains ) ) {
						$found = true;
						break;
					}
				}
				if ( ! $found ) {
					continue;
				}
			}

			$match = [
				'id'     => $el['id'],
				'name'   => $el['name'] ?? '',
				'parent' => $el['parent'],
			];
			if ( ! empty( $el['label'] ) ) {
				$match['label'] = $el['label'];
			}

			$matches[] = $match;
		}

		return [
			'post_id' => $post_id,
			'matches' => $matches,
			'count'   => count( $matches ),
		];
	}

	/**
	 * Compute diff between two element arrays.
	 *
	 * @param array $before Elements before mutation.
	 * @param array $after  Elements after mutation.
	 * @return array Diff with added, removed, and modified elements.
	 */
	public function diff_elements( array $before, array $after ): array {
		$before_map = [];
		foreach ( $before as $el ) {
			$before_map[ $el['id'] ] = $el;
		}

		$after_map = [];
		foreach ( $after as $el ) {
			$after_map[ $el['id'] ] = $el;
		}

		$added    = [];
		$removed  = [];
		$modified = [];

		// Find added and modified.
		foreach ( $after_map as $id => $el ) {
			if ( ! isset( $before_map[ $id ] ) ) {
				$entry = [ 'id' => $id, 'name' => $el['name'] ?? '' ];
				if ( ! empty( $el['label'] ) ) {
					$entry['label'] = $el['label'];
				}
				$added[] = $entry;
			} else {
				$change_detail = [];

				// Check if settings changed.
				$old_settings = $before_map[ $id ]['settings'] ?? [];
				$new_settings = $el['settings'] ?? [];
				if ( $old_settings !== $new_settings ) {
					$changed_keys = array_unique( array_merge(
						array_keys( array_diff_key( $new_settings, $old_settings ) ),
						array_keys( array_diff_key( $old_settings, $new_settings ) ),
						array_keys( array_filter( $new_settings, fn( $v, $k ) => isset( $old_settings[ $k ] ) && $old_settings[ $k ] !== $v, ARRAY_FILTER_USE_BOTH ) )
					) );
					if ( ! empty( $changed_keys ) ) {
						$change_detail['changed_keys'] = array_values( $changed_keys );
					}
				}

				// Check if parent changed.
				$old_parent = $before_map[ $id ]['parent'] ?? 0;
				$new_parent = $el['parent'] ?? 0;
				if ( $old_parent !== $new_parent ) {
					$change_detail['parent_changed'] = true;
				}

				// Check if children array changed.
				$old_children = $before_map[ $id ]['children'] ?? [];
				$new_children = $el['children'] ?? [];
				if ( $old_children !== $new_children ) {
					$change_detail['children_changed'] = true;
				}

				if ( ! empty( $change_detail ) ) {
					$modified[] = array_merge( [ 'id' => $id, 'name' => $el['name'] ?? '' ], $change_detail );
				}
			}
		}

		// Find removed.
		foreach ( $before_map as $id => $el ) {
			if ( ! isset( $after_map[ $id ] ) ) {
				$removed[] = [ 'id' => $id, 'name' => $el['name'] ?? '' ];
			}
		}

		return [
			'added'    => $added,
			'removed'  => $removed,
			'modified' => $modified,
		];
	}

	/**
	 * Bulk update settings on multiple elements in a single call.
	 *
	 * Applies all valid updates in memory, then saves once. Uses partial-success
	 * model: each item returns individual success/error status.
	 *
	 * IMPORTANT: The settings merge is shallow (array_merge). Callers must send
	 * complete sub-objects for nested keys — partial nested objects will replace
	 * the entire existing value, not deep-merge into it.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $updates Array of {element_id: string, settings: array} objects.
	 * @return array<string, mixed>|\WP_Error Partial result or WP_Error if all fail.
	 */
	public function bulk_update_elements( int $post_id, array $updates ): array|\WP_Error {
		if ( count( $updates ) > 50 ) {
			return new \WP_Error(
				'batch_too_large',
				__( 'Maximum 50 element updates per call. Split into multiple calls.', 'bricks-mcp' )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id )
			);
		}

		$elements = $this->get_elements( $post_id );

		if ( empty( $elements ) ) {
			return new \WP_Error(
				'no_elements',
				sprintf(
					/* translators: %d: Post ID */
					__( 'No Bricks elements found on post %d.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		// Build id_map for O(1) lookups.
		$id_map = [];
		foreach ( $elements as $index => $element ) {
			$id_map[ $element['id'] ] = $index;
		}

		$normalizer = $this->core->get_normalizer();
		$success    = [];
		$errors     = [];

		foreach ( $updates as $update ) {
			$upd_element_id = $update['element_id'] ?? '';
			$upd_settings   = $update['settings'] ?? [];

			if ( '' === $upd_element_id ) {
				$errors[] = [
					'element_id' => $upd_element_id,
					'error'      => 'Missing element_id',
				];
				continue;
			}

			if ( empty( $upd_settings ) ) {
				$errors[] = [
					'element_id' => $upd_element_id,
					'error'      => 'Missing settings',
				];
				continue;
			}

			if ( ! isset( $id_map[ $upd_element_id ] ) ) {
				$errors[] = [
					'element_id' => $upd_element_id,
					'error'      => 'Element not found',
				];
				continue;
			}

			$idx          = $id_map[ $upd_element_id ];
			$existing     = $elements[ $idx ]['settings'] ?? [];
			$element_name = $elements[ $idx ]['name'] ?? '';

			// Apply key corrections and sanitization (same as add/append paths).
			$corrected = $normalizer->apply_key_corrections( $upd_settings, $element_name );
			$sanitized = $normalizer->sanitize_settings( $corrected, $element_name );

			$elements[ $idx ]['settings'] = array_merge( $existing, $sanitized );

			$success[] = [
				'element_id' => $upd_element_id,
				'status'     => 'updated',
			];
		}

		if ( empty( $success ) ) {
			return new \WP_Error(
				'all_failed',
				__( 'All element updates failed. Check element IDs and settings.', 'bricks-mcp' )
			);
		}

		$saved = $this->save_elements( $post_id, $elements );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [
			'success' => $success,
			'errors'  => $errors,
			'summary' => [
				'total'     => count( $updates ),
				'succeeded' => count( $success ),
				'failed'    => count( $errors ),
			],
		];
	}

	/**
	 * Get all global theme styles.
	 *
	 * Reads the `bricks_theme_styles` option and returns all styles
	 * formatted via format_theme_style_response().
	 *
	 * @return array<int, array<string, mixed>> Array of formatted theme style objects.
	 */
	public function get_theme_styles(): array {
		return $this->theme_style_service->get_theme_styles();
	}

	public function get_theme_style( string $style_id ): array|\WP_Error {
		return $this->theme_style_service->get_theme_style( $style_id );
	}

	public function create_theme_style( string $label, array $settings = [], array $conditions = [] ): array|\WP_Error {
		return $this->theme_style_service->create_theme_style( $label, $settings, $conditions );
	}

	public function update_theme_style( string $style_id, ?string $label = null, ?array $settings = null, ?array $conditions = null, bool $replace_section = false ): array|\WP_Error {
		return $this->theme_style_service->update_theme_style( $style_id, $label, $settings, $conditions, $replace_section );
	}

	public function delete_theme_style( string $style_id, bool $hard_delete = false ): array|\WP_Error {
		return $this->theme_style_service->delete_theme_style( $style_id, $hard_delete );
	}

	public function get_typography_scales(): array {
		return $this->typography_scale_service->get_typography_scales();
	}

	public function create_typography_scale( string $name, array $steps, string $prefix, array $utility_classes = [] ): array|\WP_Error {
		return $this->typography_scale_service->create_typography_scale( $name, $steps, $prefix, $utility_classes );
	}

	public function update_typography_scale( string $category_id, ?string $name = null, ?array $steps = null, ?string $prefix = null, ?array $utility_classes = null ): array|\WP_Error {
		return $this->typography_scale_service->update_typography_scale( $category_id, $name, $steps, $prefix, $utility_classes );
	}

	public function delete_typography_scale( string $category_id ): array|\WP_Error {
		return $this->typography_scale_service->delete_typography_scale( $category_id );
	}

	// =========================================================================
	// Color Palette CRUD
	// =========================================================================

	public function get_color_palettes(): array {
		return $this->color_palette_service->get_color_palettes();
	}

	public function create_color_palette( string $name, array $colors = [] ): array|\WP_Error {
		return $this->color_palette_service->create_color_palette( $name, $colors );
	}

	public function update_color_palette( string $palette_id, string $name ): array|\WP_Error {
		return $this->color_palette_service->update_color_palette( $palette_id, $name );
	}

	public function delete_color_palette( string $palette_id ): array|\WP_Error {
		return $this->color_palette_service->delete_color_palette( $palette_id );
	}

	public function add_color_to_palette( string $palette_id, string $light, string $name, string $raw = '', string $parent = '', array $utility_classes = [] ): array|\WP_Error {
		return $this->color_palette_service->add_color_to_palette( $palette_id, $light, $name, $raw, $parent, $utility_classes );
	}

	public function update_color_in_palette( string $palette_id, string $color_id, array $fields ): array|\WP_Error {
		return $this->color_palette_service->update_color_in_palette( $palette_id, $color_id, $fields );
	}

	public function delete_color_from_palette( string $palette_id, string $color_id ): array|\WP_Error {
		return $this->color_palette_service->delete_color_from_palette( $palette_id, $color_id );
	}

	// =========================================================================
	// Global Variables CRUD (Non-Scale)
	// =========================================================================

	public function get_global_variables(): array {
		return $this->global_variable_service->get_global_variables();
	}

	public function create_variable_category( string $name ): array|\WP_Error {
		return $this->global_variable_service->create_variable_category( $name );
	}

	public function update_variable_category( string $category_id, string $name ): array|\WP_Error {
		return $this->global_variable_service->update_variable_category( $category_id, $name );
	}

	public function delete_variable_category( string $category_id ): array|\WP_Error {
		return $this->global_variable_service->delete_variable_category( $category_id );
	}

	public function create_global_variable( string $name, string $value, string $category_id = '' ): array|\WP_Error {
		return $this->global_variable_service->create_global_variable( $name, $value, $category_id );
	}

	public function update_global_variable( string $variable_id, array $fields ): array|\WP_Error {
		return $this->global_variable_service->update_global_variable( $variable_id, $fields );
	}

	public function delete_global_variable( string $variable_id ): array|\WP_Error {
		return $this->global_variable_service->delete_global_variable( $variable_id );
	}

	public function batch_create_global_variables( array $variable_defs, string $category_id = '' ): array {
		return $this->global_variable_service->batch_create_global_variables( $variable_defs, $category_id );
	}

	public function batch_delete_global_variables( array $variable_ids ): array|\WP_Error {
		return $this->global_variable_service->batch_delete_global_variables( $variable_ids );
	}

	public function search_global_variables( string $name = '', string $value = '', string $category_id = '' ): array {
		return $this->global_variable_service->search_global_variables( $name, $value, $category_id );
	}

	/**
	 * Get Bricks global settings with optional category filtering and key masking.
	 *
	 * Returns build-relevant settings categorized by group. API keys are always
	 * masked as ****configured****. Restricted settings (code execution, SVG) are
	 * flagged but values hidden.
	 *
	 * @param string $category Optional category filter.
	 * @return array<string, mixed>|\WP_Error Settings data or error.
	 */
	public function get_bricks_settings( string $category = '' ): array|\WP_Error {
		return $this->settings_service->get_bricks_settings( $category );
	}

	public function get_page_settings( int $post_id ): array|\WP_Error {
		return $this->settings_service->get_page_settings( $post_id );
	}

	public function update_page_settings( int $post_id, array $updates ): array|\WP_Error {
		return $this->settings_service->update_page_settings( $post_id, $updates );
	}

	public function get_popup_settings( int $template_id ): array|\WP_Error {
		return $this->settings_service->get_popup_settings( $template_id );
	}

	public function set_popup_settings( int $template_id, array $popup_settings ): array|\WP_Error {
		return $this->settings_service->set_popup_settings( $template_id, $popup_settings );
	}

	public function is_dangerous_actions_enabled(): bool {
		return $this->core->is_dangerous_actions_enabled();
	}

	public function get_seo_data( int $post_id ): array|\WP_Error {
		return $this->seo_service->get_seo_data( $post_id );
	}

	public function update_seo_data( int $post_id, array $fields ): array|\WP_Error {
		return $this->seo_service->update_seo_data( $post_id, $fields );
	}

	public function export_template( int $template_id, bool $include_classes = false ): array|\WP_Error {
		return $this->template_service->export_template( $template_id, $include_classes );
	}

	public function import_template( array $data ): array|\WP_Error {
		return $this->template_service->import_template( $data );
	}

	public function import_template_from_url( string $url ): array|\WP_Error {
		return $this->template_service->import_template_from_url( $url );
	}

	public function export_global_classes( string $category = '' ): array {
		return $this->global_class_service->export_global_classes( $category );
	}

	public function import_global_classes_from_json( array $data ): array|\WP_Error {
		return $this->global_class_service->import_global_classes_from_json( $data );
	}

	public function get_font_status(): array {
		return $this->settings_service->get_font_status();
	}

	public function get_adobe_fonts(): array {
		return $this->settings_service->get_adobe_fonts();
	}

	public function update_font_settings( array $fields ): array|\WP_Error {
		return $this->settings_service->update_font_settings( $fields );
	}

	public function get_page_code( int $post_id ): array|\WP_Error {
		return $this->settings_service->get_page_code( $post_id );
	}

	public function update_page_css( int $post_id, string $css ): array|\WP_Error {
		return $this->settings_service->update_page_css( $post_id, $css );
	}

	public function update_page_scripts( int $post_id, array $scripts ): array|\WP_Error {
		return $this->settings_service->update_page_scripts( $post_id, $scripts );
	}
}
