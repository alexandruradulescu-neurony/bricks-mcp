<?php
/**
 * Global class handler for MCP Router.
 *
 * Manages Bricks global CSS classes, categories, and import/export.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\BricksService;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles global class tool actions.
 */
final class GlobalClassHandler {

	/**
	 * Bricks service instance.
	 *
	 * @var BricksService
	 */
	private BricksService $bricks_service;

	/**
	 * Constructor.
	 *
	 * @param BricksService $bricks_service Bricks service instance.
	 */
	public function __construct( BricksService $bricks_service ) {
		$this->bricks_service = $bricks_service;
	}

	/**
	 * Handle a global class tool action.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: action).
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$action = sanitize_text_field( $args['action'] ?? '' );

		// Param aliasing: category_name -> name for create_category handler.
		if ( 'create_category' === $action && isset( $args['category_name'] ) && ! isset( $args['name'] ) ) {
			$args['name'] = $args['category_name'];
		}

		// Param aliasing: classes -> class_names for batch_delete handler.
		if ( 'batch_delete' === $action && isset( $args['classes'] ) && ! isset( $args['class_names'] ) ) {
			$args['class_names'] = $args['classes'];
		}

		return match ( $action ) {
			'list'            => $this->tool_get_global_classes( $args ),
			'create'          => $this->tool_create_global_class( $args ),
			'update'          => $this->tool_update_global_class( $args ),
			'delete'          => $this->tool_delete_global_class( $args ),
			'apply'           => $this->tool_apply_global_class( $args ),
			'remove'          => $this->tool_remove_global_class( $args ),
			'batch_create'    => $this->tool_batch_create_global_classes( $args ),
			'batch_delete'    => $this->tool_batch_delete_global_classes( $args ),
			'import_css'      => $this->tool_import_classes_from_css( $args ),
			'list_categories' => $this->tool_list_global_class_categories( $args ),
			'create_category' => $this->tool_create_global_class_category( $args ),
			'delete_category' => $this->tool_delete_global_class_category( $args ),
			'export'          => $this->tool_export_global_classes( $args ),
			'import_json'     => $this->tool_import_global_classes_json( $args ),
			default           => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, create, update, delete, apply, remove, batch_create, batch_delete, import_css, list_categories, create_category, delete_category, export, import_json', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Get global CSS classes.
	 *
	 * Returns all global classes with their full styles in Bricks composite key format.
	 * Supports optional search parameter for partial name match filtering.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Classes data or error.
	 */
	private function tool_get_global_classes( array $args ): array|\WP_Error {
		$search   = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
		$category = isset( $args['category'] ) ? sanitize_text_field( $args['category'] ) : '';
		$classes  = $this->bricks_service->get_global_classes( $search, $category );

		$total  = count( $classes );
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : null;

		if ( $offset > 0 || null !== $limit ) {
			$classes = array_values( array_slice( $classes, $offset, $limit ) );
		}

		$result = array(
			'total'   => $total,
			'classes' => $classes,
		);

		if ( $offset > 0 || null !== $limit ) {
			$result['offset']   = $offset;
			$result['limit']    = $limit;
			$result['has_more'] = ( $offset + count( $classes ) ) < $total;
		}

		return $result;
	}

	/**
	 * Tool: Apply a global CSS class to elements.
	 *
	 * Resolves class name to ID, validates all element IDs, and applies the class.
	 * Returns the class CSS properties so AI can confirm the visual outcome.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Application result or error.
	 */
	private function tool_apply_global_class( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['class_name'] ) ) {
			return new \WP_Error( 'missing_class_name', __( 'class_name is required. Provide the name of a global CSS class.', 'bricks-mcp' ) );
		}

		if ( empty( $args['element_ids'] ) || ! is_array( $args['element_ids'] ) ) {
			return new \WP_Error( 'missing_element_ids', __( 'element_ids is required. Provide a non-empty array of element IDs.', 'bricks-mcp' ) );
		}

		$post_id     = (int) $args['post_id'];
		$class_name  = sanitize_text_field( $args['class_name'] );
		$element_ids = array_map( 'sanitize_text_field', $args['element_ids'] );

		// Resolve class name to ID.
		$class = $this->bricks_service->resolve_class_name( $class_name );

		if ( null === $class ) {
			return new \WP_Error(
				'class_not_found',
				sprintf(
					/* translators: %s: Class name */
					__( "Global class '%s' not found. Use global_class:list to see available classes.", 'bricks-mcp' ),
					$class_name
				)
			);
		}

		$result = $this->bricks_service->apply_class_to_elements( $post_id, $class['id'], $element_ids );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'class_name' => $class['name'],
			'class_id'   => $class['id'],
			'styles'     => $class['styles'] ?? array(),
			'applied_to' => $element_ids,
			'post_id'    => $post_id,
		);
	}

	/**
	 * Tool: Remove a global CSS class from elements.
	 *
	 * Resolves class name to ID, validates all element IDs, and removes the class.
	 * Returns the class CSS properties for confirmation.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Removal result or error.
	 */
	private function tool_remove_global_class( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['class_name'] ) ) {
			return new \WP_Error( 'missing_class_name', __( 'class_name is required. Provide the name of a global CSS class.', 'bricks-mcp' ) );
		}

		if ( empty( $args['element_ids'] ) || ! is_array( $args['element_ids'] ) ) {
			return new \WP_Error( 'missing_element_ids', __( 'element_ids is required. Provide a non-empty array of element IDs.', 'bricks-mcp' ) );
		}

		$post_id     = (int) $args['post_id'];
		$class_name  = sanitize_text_field( $args['class_name'] );
		$element_ids = array_map( 'sanitize_text_field', $args['element_ids'] );

		// Resolve class name to ID.
		$class = $this->bricks_service->resolve_class_name( $class_name );

		if ( null === $class ) {
			return new \WP_Error(
				'class_not_found',
				sprintf(
					/* translators: %s: Class name */
					__( "Global class '%s' not found. Use global_class:list to see available classes.", 'bricks-mcp' ),
					$class_name
				)
			);
		}

		$result = $this->bricks_service->remove_class_from_elements( $post_id, $class['id'], $element_ids );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'class_name'   => $class['name'],
			'class_id'     => $class['id'],
			'styles'       => $class['styles'] ?? array(),
			'removed_from' => $element_ids,
			'post_id'      => $post_id,
		);
	}

	/**
	 * Tool: Create a global CSS class.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created class data or error.
	 */
	private function tool_create_global_class( array $args ): array|\WP_Error {
		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a non-empty class name (e.g., btn-primary).', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->create_global_class( $args );
	}

	/**
	 * Tool: Update a global CSS class by name.
	 *
	 * Resolves class name to ID, then delegates to BricksService.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated class data or error.
	 */
	private function tool_update_global_class( array $args ): array|\WP_Error {
		if ( empty( $args['class_name'] ) ) {
			return new \WP_Error(
				'missing_class_name',
				__( 'class_name is required. Use global_class:list to find class names.', 'bricks-mcp' )
			);
		}

		$class = $this->bricks_service->resolve_class_name( sanitize_text_field( $args['class_name'] ) );

		if ( null === $class ) {
			return new \WP_Error(
				'class_not_found',
				sprintf(
					/* translators: %s: Class name */
					__( "Class '%s' not found. Use global_class:list to list available classes.", 'bricks-mcp' ),
					$args['class_name']
				)
			);
		}

		return $this->bricks_service->update_global_class( $class['id'], $args );
	}

	/**
	 * Tool: Soft-delete a global CSS class.
	 *
	 * Resolves class name to ID, finds references, then trashes the class.
	 * Returns deletion confirmation with reference warnings.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_global_class( array $args ): array|\WP_Error {
		if ( empty( $args['class_name'] ) ) {
			return new \WP_Error(
				'missing_class_name',
				__( 'class_name is required. Use global_class:list to find class names.', 'bricks-mcp' )
			);
		}

		$class = $this->bricks_service->resolve_class_name( sanitize_text_field( $args['class_name'] ) );

		if ( null === $class ) {
			return new \WP_Error(
				'class_not_found',
				sprintf(
					/* translators: %s: Class name */
					__( "Class '%s' not found. Use global_class:list to list available classes.", 'bricks-mcp' ),
					$args['class_name']
				)
			);
		}

		$refs   = $this->bricks_service->find_class_references( $class['id'] );
		$result = $this->bricks_service->trash_global_class( $class['id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'deleted'    => $class['name'],
			'references' => $refs['references'],
			'truncated'  => $refs['truncated'],
			'note'       => __( 'Class moved to trash. References above still use this class ID -- consider using remove_global_class to clean them up.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Batch create multiple global CSS classes.
	 *
	 * Validates the classes array and delegates to BricksService.
	 * Returns partial results -- successfully created classes and errors for failed ones.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Batch creation results or error.
	 */
	private function tool_batch_create_global_classes( array $args ): array|\WP_Error {
		if ( empty( $args['classes'] ) || ! is_array( $args['classes'] ) ) {
			return new \WP_Error(
				'missing_classes',
				__( 'classes is required and must be a non-empty array of class definitions. Each object needs at least a name property.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->batch_create_global_classes( $args['classes'] );
	}

	/**
	 * Tool: Batch delete multiple global CSS classes.
	 *
	 * Resolves class names to IDs, then delegates to BricksService batch trash.
	 * Returns combined results with reference warnings.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Batch deletion results or error.
	 */
	private function tool_batch_delete_global_classes( array $args ): array|\WP_Error {
		if ( empty( $args['class_names'] ) || ! is_array( $args['class_names'] ) ) {
			return new \WP_Error(
				'missing_class_names',
				__( 'class_names is required and must be a non-empty array of class name strings. Use global_class:list to find names.', 'bricks-mcp' )
			);
		}

		$class_ids         = array();
		$resolution_errors = array();

		foreach ( $args['class_names'] as $name ) {
			$class = $this->bricks_service->resolve_class_name( sanitize_text_field( $name ) );

			if ( null === $class ) {
				$resolution_errors[ $name ] = sprintf(
					/* translators: %s: Class name */
					__( "Class '%s' not found.", 'bricks-mcp' ),
					$name
				);
			} else {
				$class_ids[] = $class['id'];
			}
		}

		$result = $this->bricks_service->batch_trash_global_classes( $class_ids );

		// Merge resolution errors with trash errors.
		$result['errors'] = array_merge( $resolution_errors, $result['errors'] );

		$result['note'] = __( 'Classes moved to trash. Check references above -- consider using remove_global_class to clean them up.', 'bricks-mcp' );

		return $result;
	}

	/**
	 * Tool: List all global CSS class categories.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Categories list or error.
	 */
	private function tool_list_global_class_categories( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$categories = $this->bricks_service->get_global_class_categories();

		return array(
			'total'      => count( $categories ),
			'categories' => $categories,
		);
	}

	/**
	 * Tool: Create a new global CSS class category.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created category or error.
	 */
	private function tool_create_global_class_category( array $args ): array|\WP_Error {
		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a category name (e.g., Buttons, Typography, Layout).', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->create_global_class_category( sanitize_text_field( $args['name'] ) );
	}

	/**
	 * Tool: Delete a global CSS class category.
	 *
	 * Classes in the deleted category are moved to uncategorized.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_global_class_category( array $args ): array|\WP_Error {
		if ( empty( $args['category_id'] ) ) {
			return new \WP_Error(
				'missing_category_id',
				__( 'category_id is required. Use list_global_class_categories to find category IDs.', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->delete_global_class_category( sanitize_text_field( $args['category_id'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'deleted' => true,
			'note'    => __( 'Classes in this category have been moved to uncategorized.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Import CSS class definitions from a raw CSS string.
	 *
	 * Parses CSS selectors, maps media queries and pseudo-selectors to Bricks
	 * breakpoint/state variants, and creates global classes via batch create.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Import results or error.
	 */
	private function tool_import_classes_from_css( array $args ): array|\WP_Error {
		if ( empty( $args['css'] ) || ! is_string( $args['css'] ) ) {
			return new \WP_Error(
				'missing_css',
				__( 'css is required and must be a non-empty CSS string containing class selectors.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->import_classes_from_css( $args['css'] );
	}

	/**
	 * Tool: Export global classes as JSON.
	 *
	 * @param array<string, mixed> $args Tool arguments with optional 'category'.
	 * @return array<string, mixed> Export data with classes, categories, and count.
	 */
	private function tool_export_global_classes( array $args ): array {
		$category = isset( $args['category'] ) ? sanitize_text_field( $args['category'] ) : '';

		return $this->bricks_service->export_global_classes( $category );
	}

	/**
	 * Tool: Import global classes from JSON data.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'classes_data'.
	 * @return array<string, mixed>|\WP_Error Import summary or error.
	 */
	private function tool_import_global_classes_json( array $args ): array|\WP_Error {
		$classes_data = $args['classes_data'] ?? null;

		if ( null === $classes_data || ! is_array( $classes_data ) ) {
			return new \WP_Error(
				'missing_classes_data',
				__( 'classes_data is required for import_json. Provide an object with "classes" array or a raw array of class objects.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->import_global_classes_from_json( $classes_data );
	}
}
