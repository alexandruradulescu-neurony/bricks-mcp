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
use BricksMCP\MCP\Services\BricksCore;
use BricksMCP\MCP\Services\SiteVariableResolver;
use BricksMCP\MCP\Services\StyleRoleResolver;
use BricksMCP\MCP\ToolRegistry;

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
	 * Bricks check callback.
	 *
	 * @var callable
	 */
	private $require_bricks;

	/**
	 * Constructor.
	 *
	 * @param BricksService $bricks_service Bricks service instance.
	 * @param callable      $require_bricks Callback that returns \WP_Error|null.
	 */
	public function __construct( BricksService $bricks_service, callable $require_bricks ) {
		$this->bricks_service = $bricks_service;
		$this->require_bricks = $require_bricks;
	}

	/**
	 * Handle a theme style tool action.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: action).
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	public function handle_theme_style( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

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
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

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
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

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
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

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

	/**
	 * Handle semantic style role mapping actions.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: action).
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	public function handle_style_role( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action = sanitize_text_field( $args['action'] ?? '' );

		return match ( $action ) {
			'list'  => $this->tool_list_style_roles(),
			'map'   => $this->tool_map_style_role( $args ),
			'unmap' => $this->tool_unmap_style_role( $args ),
			'reset' => $this->tool_reset_style_roles(),
			default => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, map, unmap, reset', 'bricks-mcp' ),
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

		return $this->bricks_service->get_theme_style( sanitize_text_field( (string) $args['style_id'] ) );
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
		$conditions = isset( $args['conditions'] ) && is_array( $args['conditions'] ) ? $args['conditions'] : null;

		$result = $this->bricks_service->update_theme_style(
			sanitize_text_field( (string) $args['style_id'] ),
			$label,
			$styles,
			$conditions,
			! empty( $args['replace_section'] )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add normalization warnings if present.
		$warnings = $this->bricks_service->get_theme_style_service()->get_normalization_warnings();
		if ( ! empty( $warnings ) ) {
			$result['warnings'] = $warnings;
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
					/* translators: %s: Theme style ID */
					__( 'You are about to delete theme style "%s". Set confirm: true to proceed.', 'bricks-mcp' ),
					sanitize_text_field( (string) $args['style_id'] )
				)
			);
		}

		return $this->bricks_service->delete_theme_style(
			sanitize_text_field( (string) $args['style_id'] ),
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
					/* translators: %s: Typography scale / category ID */
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
					/* translators: %s: Color palette ID */
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
					/* translators: 1: Color ID, 2: Palette ID */
					__( 'You are about to delete color "%1$s" from palette "%2$s". Set confirm: true to proceed.', 'bricks-mcp' ),
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
					/* translators: %s: Variable category ID */
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
					/* translators: %s: Global variable ID */
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
					/* translators: %d: Number of global variables pending delete */
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

	// -------------------------------------------------------------------------
	// Style role mappings
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private function tool_list_style_roles(): array {
		$resolver = new StyleRoleResolver( $this->bricks_service->get_global_class_service()->get_global_classes() );

		return [
			'mappings'        => StyleRoleResolver::manual_role_map(),
			'resolved_roles'  => $resolver->resolve_all(),
			'available_roles' => [
				'classes' => array_keys( StyleRoleResolver::class_role_specs() ),
				'tokens'  => array_keys( StyleRoleResolver::token_role_specs() ),
			],
			'note'            => 'Mappings are site-specific. They let existing class and variable names satisfy semantic roles without renaming or overwriting user-owned design systems.',
		];
	}

	/**
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function tool_map_style_role( array $args ): array|\WP_Error {
		$role = sanitize_text_field( $args['role'] ?? '' );
		$kind = sanitize_text_field( $args['kind'] ?? '' );
		$name = sanitize_text_field( $args['name'] ?? '' );

		if ( '' === $role || '' === $kind || '' === $name ) {
			return new \WP_Error( 'missing_mapping_args', 'role, kind, and name are required.' );
		}
		if ( ! in_array( $kind, [ 'class', 'token' ], true ) ) {
			return new \WP_Error( 'invalid_mapping_kind', 'kind must be "class" or "token".' );
		}

		if ( 'class' === $kind ) {
			if ( ! isset( StyleRoleResolver::class_role_specs()[ $role ] ) ) {
				return new \WP_Error( 'invalid_style_role', 'Unknown class style role: ' . $role );
			}
			if ( ! $this->global_class_exists( $name ) ) {
				return new \WP_Error( 'class_not_found', 'Global class not found: ' . $name );
			}
		} else {
			if ( ! isset( StyleRoleResolver::token_role_specs()[ $role ] ) ) {
				return new \WP_Error( 'invalid_style_role', 'Unknown token style role: ' . $role );
			}
			SiteVariableResolver::clear_cache();
			if ( ! SiteVariableResolver::exists( $name ) ) {
				return new \WP_Error( 'variable_not_found', 'Global variable not found: ' . $name );
			}
			$name = ltrim( $name, '-' );
		}

		$map = StyleRoleResolver::manual_role_map();
		if ( 'class' === $kind ) {
			$map['classes'][ $role ] = $name;
		} else {
			$map['tokens'][ $role ] = $name;
		}

		update_option( BricksCore::OPTION_STYLE_ROLE_MAP, $map, false );
		SiteVariableResolver::clear_cache();

		return $this->tool_list_style_roles();
	}

	/**
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private function tool_unmap_style_role( array $args ): array {
		$role = sanitize_text_field( $args['role'] ?? '' );
		$kind = sanitize_text_field( $args['kind'] ?? '' );
		$map  = StyleRoleResolver::manual_role_map();

		if ( 'class' === $kind ) {
			unset( $map['classes'][ $role ] );
		} elseif ( 'token' === $kind ) {
			unset( $map['tokens'][ $role ] );
		}

		update_option( BricksCore::OPTION_STYLE_ROLE_MAP, $map, false );

		return $this->tool_list_style_roles();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function tool_reset_style_roles(): array {
		delete_option( BricksCore::OPTION_STYLE_ROLE_MAP );
		return $this->tool_list_style_roles();
	}

	private function global_class_exists( string $name ): bool {
		foreach ( $this->bricks_service->get_global_class_service()->get_global_classes() as $class ) {
			if ( ( $class['name'] ?? '' ) === $name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Register theme_style, typography_scale, color_palette, global_variable, and style_role tools.
	 *
	 * @param ToolRegistry $registry Tool registry instance.
	 * @return void
	 */
	public function register( ToolRegistry $registry ): void {
		// Theme style tool.
		$registry->register(
			'theme_style',
			__( "Manage Bricks theme styles (site-wide typography, colors, spacing).\n\nActions: list, get, create, update, delete.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'          => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'get', 'create', 'update', 'delete' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'style_id'        => array(
						'type'        => 'string',
						'description' => __( 'Theme style ID (get, update, delete: required)', 'bricks-mcp' ),
					),
					'name'            => array(
						'type'        => 'string',
						'description' => __( 'Style label/name (create: required; update: optional)', 'bricks-mcp' ),
					),
					'styles'          => array(
						'type'        => 'object',
						'description' => __( 'Settings organized by group: typography, links, colors, general, contextualSpacing, css, heading, button, section, container, block, div, text, form, image, navMenu, accordion, alert, carousel, divider, iconBox, imageGallery, list, iconList, postContent, postTitle, tabs, video, wordpress, woocommerceButton. (create, update: optional). When updating, styles are deep-merged within each group — nested properties are preserved. Use replace_section: true to fully replace each group instead.', 'bricks-mcp' ),
					),
					'conditions'      => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'object' ),
						'description' => __( 'Array of condition objects with "main" key (create, update: optional)', 'bricks-mcp' ),
					),
					'active'          => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the style should be active (update: optional)', 'bricks-mcp' ),
					),
					'replace_section' => array(
						'type'        => 'boolean',
						'description' => __( 'If true, fully replace each provided settings group instead of merging (update: default false)', 'bricks-mcp' ),
					),
					'hard_delete'     => array(
						'type'        => 'boolean',
						'description' => __( 'If true, permanently delete the style; if false (default), only remove conditions to deactivate (delete: optional)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'handle_theme_style' )
		);

		// Typography scale tool.
		$registry->register(
			'typography_scale',
			__( "Manage Bricks typography scales with CSS variable generation.\n\nActions: list, create, update, delete.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'          => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'create', 'update', 'delete' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'scale_id'        => array(
						'type'        => 'string',
						'description' => __( 'Scale category ID (update, delete: required)', 'bricks-mcp' ),
					),
					'name'            => array(
						'type'        => 'string',
						'description' => __( 'Scale name (create: required; update: optional)', 'bricks-mcp' ),
					),
					'settings'        => array(
						'type'        => 'object',
						'description' => __( 'Typography scale settings including prefix, steps, and utility_classes (create: required; update: optional)', 'bricks-mcp' ),
					),
					'prefix'          => array(
						'type'        => 'string',
						'description' => __( 'CSS variable prefix starting with -- (e.g., "--text-"). Used in create if not inside settings.', 'bricks-mcp' ),
					),
					'steps'           => array(
						'type'        => 'array',
						'description' => __( 'Array of scale steps, each with name and value (create: required if not inside settings)', 'bricks-mcp' ),
					),
					'utility_classes' => array(
						'type'        => 'array',
						'description' => __( 'Utility class definitions (create, update: optional)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'handle_typography_scale' )
		);

		// Color palette tool.
		$registry->register(
			'color_palette',
			__( "Manage Bricks color palettes and individual colors.\n\nActions: list, create, update, delete, add_color, update_color, delete_color.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'     => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'create', 'update', 'delete', 'add_color', 'update_color', 'delete_color' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'palette_id' => array(
						'type'        => 'string',
						'description' => __( 'Palette ID (update, delete, add_color, update_color, delete_color: required)', 'bricks-mcp' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'Palette name (create: required; update: optional)', 'bricks-mcp' ),
					),
					'colors'     => array(
						'type'        => 'array',
						'description' => __( 'Initial colors for palette (create: optional)', 'bricks-mcp' ),
					),
					'color_id'   => array(
						'type'        => 'string',
						'description' => __( 'Color ID (update_color, delete_color: required)', 'bricks-mcp' ),
					),
					'color'      => array(
						'type'        => 'object',
						'description' => __( 'Color object with light (hex value), name, raw (CSS variable) fields (add_color: required; update_color: required). "hex" accepted as alias for "light"', 'bricks-mcp' ),
					),
					'position'   => array(
						'type'        => 'integer',
						'description' => __( 'Position in palette (add_color: optional)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'handle_color_palette' )
		);

		// Global variable tool.
		$registry->register(
			'global_variable',
			__( "Manage Bricks global CSS variables organized by category.\n\nActions: list, create_category, update_category, delete_category, create, update, delete, batch_create, batch_delete, search.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'        => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'create_category', 'update_category', 'delete_category', 'create', 'update', 'delete', 'batch_create', 'batch_delete', 'search' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'category_id'   => array(
						'type'        => 'string',
						'description' => __( 'Category ID (update_category, delete_category: required; create: optional; search: optional filter)', 'bricks-mcp' ),
					),
					'category_name' => array(
						'type'        => 'string',
						'description' => __( 'Category name (create_category: required; update_category: required)', 'bricks-mcp' ),
					),
					'variable_id'   => array(
						'type'        => 'string',
						'description' => __( 'Variable ID (update, delete: required)', 'bricks-mcp' ),
					),
					'name'          => array(
						'type'        => 'string',
						'description' => __( 'Variable name (create: required; update: optional)', 'bricks-mcp' ),
					),
					'value'         => array(
						'type'        => 'string',
						'description' => __( 'CSS value (create: required; update: optional)', 'bricks-mcp' ),
					),
					'category'      => array(
						'type'        => 'string',
						'description' => __( 'Category ID for variable assignment (create: optional)', 'bricks-mcp' ),
					),
					'variables'     => array(
						'type'        => 'array',
						'description' => __( 'Array of {name, value} variable objects (batch_create: required)', 'bricks-mcp' ),
					),
					'variable_ids'  => array(
						'type'        => 'array',
						'description' => __( 'Array of variable ID strings (batch_delete: required; max 50)', 'bricks-mcp' ),
						'items'       => array( 'type' => 'string' ),
					),
					'query'         => array(
						'type'        => 'string',
						'description' => __( 'Name substring to search for (search: optional, case-insensitive)', 'bricks-mcp' ),
					),
					'value_query'   => array(
						'type'        => 'string',
						'description' => __( 'Value substring to search for (search: optional, case-insensitive)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'handle_global_variable' )
		);

		// Semantic style-role mapping tool.
		$registry->register(
			'style_role',
			__( "Map semantic style roles to this site's existing Bricks classes and variables. Use this when an existing design system uses custom names.\n\nActions: list, map, unmap, reset.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action' => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'map', 'unmap', 'reset' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'role'   => array(
						'type'        => 'string',
						'description' => __( 'Semantic role, e.g. button.primary, card.default, color.primary, space.content_gap.', 'bricks-mcp' ),
					),
					'kind'   => array(
						'type'        => 'string',
						'enum'        => array( 'class', 'token' ),
						'description' => __( 'Whether the role maps to a class or token.', 'bricks-mcp' ),
					),
					'name'   => array(
						'type'        => 'string',
						'description' => __( 'Existing global class name for kind=class, or existing variable name for kind=token.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'handle_style_role' )
		);
	}
}
