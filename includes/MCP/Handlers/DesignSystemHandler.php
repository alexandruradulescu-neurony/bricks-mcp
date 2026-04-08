<?php
/**
 * Design system handler for MCP Router.
 *
 * Manages theme styles, typography scales, color palettes, and global variables.
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
 * Handles design system tool actions.
 */
final class DesignSystemHandler {

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
	 * Handle a theme style tool action.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: action).
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	public function handle_theme_style( array $args ): array|\WP_Error {
		$action = sanitize_text_field( $args['action'] ?? '' );

		// Map 'name' param to 'label' for theme style handlers that expect 'label'.
		if ( isset( $args['name'] ) && ! isset( $args['label'] ) ) {
			$args['label'] = $args['name'];
		}

		return match ( $action ) {
			'list'   => $this->tool_list_theme_styles( $args ),
			'get'    => $this->tool_get_theme_style( $args ),
			'create' => $this->tool_create_theme_style( $args ),
			'update' => $this->tool_update_theme_style( $args ),
			'delete' => $this->tool_delete_theme_style( $args ),
			default  => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, get, create, update, delete', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Handle a typography scale tool action.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: action).
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	public function handle_typography_scale( array $args ): array|\WP_Error {
		$action = sanitize_text_field( $args['action'] ?? '' );

		// Map 'scale_id' param to 'category_id' for handlers that expect 'category_id'.
		if ( isset( $args['scale_id'] ) && ! isset( $args['category_id'] ) ) {
			$args['category_id'] = $args['scale_id'];
		}

		return match ( $action ) {
			'list'   => $this->tool_get_typography_scales( $args ),
			'create' => $this->tool_create_typography_scale( $args ),
			'update' => $this->tool_update_typography_scale( $args ),
			'delete' => $this->tool_delete_typography_scale( $args ),
			default  => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, create, update, delete', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Handle a color palette tool action.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: action).
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	public function handle_color_palette( array $args ): array|\WP_Error {
		$action = sanitize_text_field( $args['action'] ?? '' );

		// Map consolidated 'color' object to flat params for underlying handlers.
		if ( isset( $args['color'] ) && is_array( $args['color'] ) ) {
			foreach ( $args['color'] as $k => $v ) {
				if ( ! isset( $args[ $k ] ) ) {
					$args[ $k ] = $v;
				}
			}
		}

		return match ( $action ) {
			'list'         => $this->tool_list_color_palettes( $args ),
			'create'       => $this->tool_create_color_palette( $args ),
			'update'       => $this->tool_update_color_palette( $args ),
			'delete'       => $this->tool_delete_color_palette( $args ),
			'add_color'    => $this->tool_add_color_to_palette( $args ),
			'update_color' => $this->tool_update_color_in_palette( $args ),
			'delete_color' => $this->tool_delete_color_from_palette( $args ),
			default        => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, create, update, delete, add_color, update_color, delete_color', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Handle a global variable tool action.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: action).
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	public function handle_global_variable( array $args ): array|\WP_Error {
		$action = sanitize_text_field( $args['action'] ?? '' );

		// Map 'category_name' to 'name' for category handlers.
		if ( isset( $args['category_name'] ) && ! isset( $args['name'] ) ) {
			$args['name'] = $args['category_name'];
		}

		// Map 'category' to 'category_id' for create handler.
		if ( isset( $args['category'] ) && ! isset( $args['category_id'] ) ) {
			$args['category_id'] = $args['category'];
		}

		return match ( $action ) {
			'list'            => $this->tool_list_global_variables( $args ),
			'create_category' => $this->tool_create_variable_category( $args ),
			'update_category' => $this->tool_update_variable_category( $args ),
			'delete_category' => $this->tool_delete_variable_category( $args ),
			'create'          => $this->tool_create_global_variable( $args ),
			'update'          => $this->tool_update_global_variable( $args ),
			'delete'          => $this->tool_delete_global_variable( $args ),
			'batch_create'    => $this->tool_batch_create_global_variables( $args ),
			'batch_delete'    => $this->tool_batch_delete_global_variables( $args ),
			'search'          => $this->tool_search_global_variables( $args ),
			default           => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, create_category, update_category, delete_category, create, update, delete, batch_create, batch_delete, search', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	// -------------------------------------------------------------------------
	// Theme styles
	// -------------------------------------------------------------------------

	/**
	 * Tool: List all theme styles with condition types reference.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Styles list or error.
	 */
	private function tool_list_theme_styles( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$result = $this->bricks_service->get_theme_styles();

		$condition_types = array(
			'any'         => array(
				'label'        => 'Entire website',
				'score'        => 2,
				'extra_fields' => array(),
			),
			'frontpage'   => array(
				'label'        => 'Front page',
				'score'        => 9,
				'extra_fields' => array(),
			),
			'postType'    => array(
				'label'        => 'Post type',
				'score'        => 7,
				'extra_fields' => array( 'postType' => 'array of post type slugs' ),
			),
			'archiveType' => array(
				'label'        => 'Archive',
				'score'        => '3-8',
				'extra_fields' => array( 'archiveType' => 'any|author|date|term' ),
			),
			'terms'       => array(
				'label'        => 'Terms',
				'score'        => 8,
				'extra_fields' => array( 'terms' => 'array of taxonomy::term_id strings' ),
			),
			'ids'         => array(
				'label'        => 'Individual posts',
				'score'        => 10,
				'extra_fields' => array(
					'ids'                => 'array of post IDs',
					'idsIncludeChildren' => 'boolean',
				),
			),
			'search'      => array(
				'label'        => 'Search results',
				'score'        => 0,
				'extra_fields' => array(),
			),
			'error'       => array(
				'label'        => '404 error page',
				'score'        => 0,
				'extra_fields' => array(),
			),
		);

		return array(
			'styles'          => $result,
			'count'           => count( $result ),
			'condition_types' => $condition_types,
		);
	}

	/**
	 * Tool: Get a single theme style by ID.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Theme style data or error.
	 */
	private function tool_get_theme_style( array $args ): array|\WP_Error {
		if ( empty( $args['style_id'] ) ) {
			return new \WP_Error(
				'missing_style_id',
				__( 'style_id is required. Use list_theme_styles to discover available style IDs.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->get_theme_style( $args['style_id'] );
	}

	/**
	 * Tool: Create a new theme style.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created style or error.
	 */
	private function tool_create_theme_style( array $args ): array|\WP_Error {
		// Accept both 'name' (schema param) and 'label' (legacy) for the style label.
		$label = $args['name'] ?? $args['label'] ?? '';
		if ( empty( $label ) ) {
			return new \WP_Error(
				'missing_label',
				__( 'name is required. Provide a human-readable name for the theme style.', 'bricks-mcp' )
			);
		}

		// Accept both 'styles' (schema param) and 'settings' (legacy) for the style settings.
		$styles = $args['styles'] ?? $args['settings'] ?? array();

		return $this->bricks_service->create_theme_style(
			$label,
			$styles,
			$args['conditions'] ?? array()
		);
	}

	/**
	 * Tool: Update an existing theme style.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	private function tool_update_theme_style( array $args ): array|\WP_Error {
		if ( empty( $args['style_id'] ) ) {
			return new \WP_Error(
				'missing_style_id',
				__( 'style_id is required. Use list_theme_styles to discover available style IDs.', 'bricks-mcp' )
			);
		}

		// Accept both 'name'/'styles' (schema params) and 'label'/'settings' (legacy).
		$label    = $args['name'] ?? $args['label'] ?? null;
		$styles   = $args['styles'] ?? $args['settings'] ?? null;

		$result = $this->bricks_service->update_theme_style(
			$args['style_id'],
			$label,
			$styles,
			isset( $args['conditions'] ) ? $args['conditions'] : null,
			! empty( $args['replace_section'] )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add warning if modifying the site-wide active style.
		if ( ! empty( $result['is_sitewide_active'] ) ) {
			$result['warning'] = __( 'This style applies to the entire website. Changes are live immediately.', 'bricks-mcp' );
		}

		return $result;
	}

	/**
	 * Tool: Delete or deactivate a theme style.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_theme_style( array $args ): array|\WP_Error {
		if ( empty( $args['style_id'] ) ) {
			return new \WP_Error(
				'missing_style_id',
				__( 'style_id is required. Use list_theme_styles to discover available style IDs.', 'bricks-mcp' )
			);
		}

		// Confirm check.
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf(
					__( 'You are about to delete theme style "%s". Set confirm: true to proceed.', 'bricks-mcp' ),
					sanitize_text_field( $args['style_id'] )
				)
			);
		}

		return $this->bricks_service->delete_theme_style(
			$args['style_id'],
			! empty( $args['hard_delete'] )
		);
	}

	// -------------------------------------------------------------------------
	// Typography scales
	// -------------------------------------------------------------------------

	/**
	 * Tool: Get all typography scales.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Scales list or error.
	 */
	private function tool_get_typography_scales( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$result = $this->bricks_service->get_typography_scales();

		return array(
			'scales' => $result,
			'count'  => count( $result ),
			'note'   => __( 'Use var(--prefix-step) syntax in typography settings. Scales generate both CSS variables and utility classes.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Create a typography scale.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created scale or error.
	 */
	private function tool_create_typography_scale( array $args ): array|\WP_Error {
		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a scale name (e.g., "Typography Scale").', 'bricks-mcp' )
			);
		}

		if ( empty( $args['prefix'] ) ) {
			return new \WP_Error(
				'missing_prefix',
				__( 'prefix is required. Provide a CSS variable prefix starting with -- (e.g., "--text-").', 'bricks-mcp' )
			);
		}

		if ( empty( $args['steps'] ) || ! is_array( $args['steps'] ) ) {
			return new \WP_Error(
				'missing_steps',
				__( 'steps is required. Provide an array of {name, value} objects (e.g., [{"name": "sm", "value": "0.875rem"}]).', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->create_typography_scale(
			$args['name'],
			$args['steps'],
			$args['prefix'],
			$args['utility_classes'] ?? array()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['css_regenerated'] ) ) {
			$result['note'] = __( 'CSS file not regenerated -- Bricks version may not support style manager. Variables are saved but may not appear in frontend CSS until Bricks regenerates styles.', 'bricks-mcp' );
		}

		return $result;
	}

	/**
	 * Tool: Update a typography scale.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated scale or error.
	 */
	private function tool_update_typography_scale( array $args ): array|\WP_Error {
		if ( empty( $args['category_id'] ) ) {
			return new \WP_Error(
				'missing_category_id',
				__( 'category_id is required. Use get_typography_scales to discover available scale IDs.', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->update_typography_scale(
			$args['category_id'],
			$args['name'] ?? null,
			$args['steps'] ?? null,
			$args['prefix'] ?? null,
			$args['utility_classes'] ?? null
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['css_regenerated'] ) ) {
			$result['note'] = __( 'CSS file not regenerated -- Bricks version may not support style manager. Variables are saved but may not appear in frontend CSS until Bricks regenerates styles.', 'bricks-mcp' );
		}

		return $result;
	}

	/**
	 * Tool: Delete a typography scale.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_typography_scale( array $args ): array|\WP_Error {
		if ( empty( $args['category_id'] ) ) {
			return new \WP_Error(
				'missing_category_id',
				__( 'category_id is required. Use get_typography_scales to discover available scale IDs.', 'bricks-mcp' )
			);
		}

		// Confirm check.
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf(
					__( 'You are about to delete typography scale "%s". Set confirm: true to proceed.', 'bricks-mcp' ),
					sanitize_text_field( $args['category_id'] )
				)
			);
		}

		$result = $this->bricks_service->delete_typography_scale( $args['category_id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['css_regenerated'] ) ) {
			$result['note'] = __( 'CSS file not regenerated -- Bricks version may not support style manager. Variables are saved but removed scale will still appear in frontend CSS until Bricks regenerates styles.', 'bricks-mcp' );
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Color palettes
	// -------------------------------------------------------------------------

	/**
	 * Tool: List all color palettes.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Palettes list or error.
	 */
	private function tool_list_color_palettes( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$palettes = $this->bricks_service->get_color_palettes();

		return array(
			'palettes' => $palettes,
			'count'    => count( $palettes ),
		);
	}

	/**
	 * Tool: Create a new color palette.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created palette or error.
	 */
	private function tool_create_color_palette( array $args ): array|\WP_Error {
		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a palette name.', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->create_color_palette(
			$args['name'],
			$args['colors'] ?? array()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['css_regenerated'] ) ) {
			$result['note'] = __( 'CSS file not regenerated -- Bricks version may not support style manager.', 'bricks-mcp' );
		}

		return $result;
	}

	/**
	 * Tool: Rename a color palette.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated palette or error.
	 */
	private function tool_update_color_palette( array $args ): array|\WP_Error {
		if ( empty( $args['palette_id'] ) ) {
			return new \WP_Error(
				'missing_palette_id',
				__( 'palette_id is required. Use color_palette:list to discover available palette IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_color_palette( $args['palette_id'], $args['name'] );
	}

	/**
	 * Tool: Delete a color palette.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_color_palette( array $args ): array|\WP_Error {
		if ( empty( $args['palette_id'] ) ) {
			return new \WP_Error(
				'missing_palette_id',
				__( 'palette_id is required. Use color_palette:list to discover available palette IDs.', 'bricks-mcp' )
			);
		}

		// Confirm check.
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf(
					__( 'You are about to delete color palette "%s". Set confirm: true to proceed.', 'bricks-mcp' ),
					sanitize_text_field( $args['palette_id'] )
				)
			);
		}

		return $this->bricks_service->delete_color_palette( $args['palette_id'] );
	}

	/**
	 * Tool: Add a color to a palette.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created color or error.
	 */
	private function tool_add_color_to_palette( array $args ): array|\WP_Error {
		if ( empty( $args['palette_id'] ) ) {
			return new \WP_Error(
				'missing_palette_id',
				__( 'palette_id is required. Use color_palette:list to discover available palette IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a color name (e.g., "Primary Blue").', 'bricks-mcp' )
			);
		}

		$light_value = $args['light'] ?? $args['hex'] ?? '';

		if ( empty( $light_value ) ) {
			return new \WP_Error(
				'missing_light',
				__( 'light (or hex) is required. Provide a hex color value (e.g., "#3498db").', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->add_color_to_palette(
			$args['palette_id'],
			$light_value,
			$args['name'],
			$args['raw'] ?? '',
			$args['parent_color_id'] ?? $args['parent'] ?? '',
			$args['utility_classes'] ?? array()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['css_regenerated'] ) ) {
			$result['note'] = __( 'CSS file not regenerated -- Bricks version may not support style manager.', 'bricks-mcp' );
		}

		return $result;
	}

	/**
	 * Tool: Update a color in a palette.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated color or error.
	 */
	private function tool_update_color_in_palette( array $args ): array|\WP_Error {
		if ( empty( $args['palette_id'] ) ) {
			return new \WP_Error(
				'missing_palette_id',
				__( 'palette_id is required. Use color_palette:list to discover available palette IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['color_id'] ) ) {
			return new \WP_Error(
				'missing_color_id',
				__( 'color_id is required. Use color_palette:list to discover available color IDs.', 'bricks-mcp' )
			);
		}

		// Build fields array mapping tool params to BricksService field names.
		$fields = array();

		$light_value = $args['light'] ?? $args['hex'] ?? null;
		if ( null !== $light_value ) {
			$fields['light'] = $light_value;
		}

		if ( isset( $args['name'] ) ) {
			$fields['name'] = $args['name'];
		}

		if ( isset( $args['raw'] ) ) {
			$fields['raw'] = $args['raw'];
		}

		if ( array_key_exists( 'parent_color_id', $args ) ) {
			$fields['parent'] = $args['parent_color_id'];
		}

		if ( array_key_exists( 'utility_classes', $args ) ) {
			$fields['utilityClasses'] = $args['utility_classes'];
		}

		if ( empty( $fields ) ) {
			return new \WP_Error(
				'no_fields',
				__( 'At least one field to update is required (name, light (or hex), raw, parent_color_id, or utility_classes).', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_color_in_palette(
			$args['palette_id'],
			$args['color_id'],
			$fields
		);
	}

	/**
	 * Tool: Delete a color from a palette.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_color_from_palette( array $args ): array|\WP_Error {
		if ( empty( $args['palette_id'] ) ) {
			return new \WP_Error(
				'missing_palette_id',
				__( 'palette_id is required. Use color_palette:list to discover available palette IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['color_id'] ) ) {
			return new \WP_Error(
				'missing_color_id',
				__( 'color_id is required. Use color_palette:list to discover available color IDs.', 'bricks-mcp' )
			);
		}

		// Confirm check.
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf(
					__( 'You are about to delete color "%s" from palette "%s". Set confirm: true to proceed.', 'bricks-mcp' ),
					sanitize_text_field( $args['color_id'] ),
					sanitize_text_field( $args['palette_id'] )
				)
			);
		}

		return $this->bricks_service->delete_color_from_palette(
			$args['palette_id'],
			$args['color_id']
		);
	}

	// -------------------------------------------------------------------------
	// Global variables
	// -------------------------------------------------------------------------

	/**
	 * Tool: List all global variables.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Variables list or error.
	 */
	private function tool_list_global_variables( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->bricks_service->get_global_variables();
	}

	/**
	 * Tool: Create a variable category.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created category or error.
	 */
	private function tool_create_variable_category( array $args ): array|\WP_Error {
		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a category name (e.g., "Spacing").', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->create_variable_category( $args['name'] );
	}

	/**
	 * Tool: Update a variable category.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated category or error.
	 */
	private function tool_update_variable_category( array $args ): array|\WP_Error {
		if ( empty( $args['category_id'] ) ) {
			return new \WP_Error(
				'missing_category_id',
				__( 'category_id is required. Use list_global_variables to discover available category IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_variable_category( $args['category_id'], $args['name'] );
	}

	/**
	 * Tool: Delete a variable category.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_variable_category( array $args ): array|\WP_Error {
		if ( empty( $args['category_id'] ) ) {
			return new \WP_Error(
				'missing_category_id',
				__( 'category_id is required. Use list_global_variables to discover available category IDs.', 'bricks-mcp' )
			);
		}

		// Confirm check.
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf(
					__( 'You are about to delete variable category "%s". Set confirm: true to proceed.', 'bricks-mcp' ),
					sanitize_text_field( $args['category_id'] )
				)
			);
		}

		return $this->bricks_service->delete_variable_category( $args['category_id'] );
	}

	/**
	 * Tool: Create a global variable.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created variable or error.
	 */
	private function tool_create_global_variable( array $args ): array|\WP_Error {
		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a CSS property name (e.g., "spacing-md").', 'bricks-mcp' )
			);
		}

		if ( ! isset( $args['value'] ) || '' === $args['value'] ) {
			return new \WP_Error(
				'missing_value',
				__( 'value is required. Provide a CSS value (e.g., "1rem").', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->create_global_variable(
			$args['name'],
			$args['value'],
			$args['category_id'] ?? ''
		);
	}

	/**
	 * Tool: Update a global variable.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated variable or error.
	 */
	private function tool_update_global_variable( array $args ): array|\WP_Error {
		if ( empty( $args['variable_id'] ) ) {
			return new \WP_Error(
				'missing_variable_id',
				__( 'variable_id is required. Use list_global_variables to discover available variable IDs.', 'bricks-mcp' )
			);
		}

		$fields = array();

		if ( isset( $args['name'] ) ) {
			$fields['name'] = $args['name'];
		}

		if ( isset( $args['value'] ) ) {
			$fields['value'] = $args['value'];
		}

		if ( array_key_exists( 'category_id', $args ) ) {
			$fields['category'] = $args['category_id'];
		}

		if ( empty( $fields ) ) {
			return new \WP_Error(
				'no_fields',
				__( 'At least one field to update is required (name, value, or category_id).', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_global_variable( $args['variable_id'], $fields );
	}

	/**
	 * Tool: Delete a global variable.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_global_variable( array $args ): array|\WP_Error {
		if ( empty( $args['variable_id'] ) ) {
			return new \WP_Error(
				'missing_variable_id',
				__( 'variable_id is required. Use list_global_variables to discover available variable IDs.', 'bricks-mcp' )
			);
		}

		// Confirm check.
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf(
					__( 'You are about to delete global variable "%s". Set confirm: true to proceed.', 'bricks-mcp' ),
					sanitize_text_field( $args['variable_id'] )
				)
			);
		}

		return $this->bricks_service->delete_global_variable( $args['variable_id'] );
	}

	/**
	 * Tool: Batch-create global variables.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Batch result or error.
	 */
	private function tool_batch_create_global_variables( array $args ): array|\WP_Error {
		if ( empty( $args['variables'] ) || ! is_array( $args['variables'] ) ) {
			return new \WP_Error(
				'missing_variables',
				__( 'variables is required. Provide an array of {name, value} objects.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->batch_create_global_variables(
			$args['variables'],
			$args['category_id'] ?? ''
		);
	}

	/**
	 * Handler: Batch delete global variables.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	private function tool_batch_delete_global_variables( array $args ): array|\WP_Error {
		$variable_ids = $args['variable_ids'] ?? [];

		if ( empty( $variable_ids ) || ! is_array( $variable_ids ) ) {
			return new \WP_Error( 'missing_variable_ids', __( 'variable_ids array is required with at least one variable ID string.', 'bricks-mcp' ) );
		}

		// Confirm check.
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf(
					__( 'You are about to delete %d global variable(s). Set confirm: true to proceed.', 'bricks-mcp' ),
					count( $variable_ids )
				)
			);
		}

		return $this->bricks_service->batch_delete_global_variables( $variable_ids );
	}

	/**
	 * Handler: Search global variables by name/value/category.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Search results.
	 */
	private function tool_search_global_variables( array $args ): array|\WP_Error {
		$name        = $args['query'] ?? '';
		$value       = $args['value_query'] ?? '';
		$category_id = $args['category_id'] ?? '';

		return $this->bricks_service->search_global_variables( $name, $value, $category_id );
	}
}
