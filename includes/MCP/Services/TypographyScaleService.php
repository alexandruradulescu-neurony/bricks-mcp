<?php
/**
 * Typography scale management sub-service.
 *
 * Handles CRUD for typography scale categories and their CSS variable steps.
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
 * TypographyScaleService class.
 */
class TypographyScaleService {

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
	 * Get all typography scale categories with their variables.
	 *
	 * Reads scale categories from bricks_global_variables_categories and
	 * their associated variables from bricks_global_variables.
	 * Only returns categories that have a 'scale' property.
	 *
	 * @return array<int, array<string, mixed>> Array of scale category objects with variables.
	 */
	public function get_typography_scales(): array {
		$categories = get_option( 'bricks_global_variables_categories', [] );

		if ( ! is_array( $categories ) ) {
			return [];
		}

		$variables = get_option( 'bricks_global_variables', [] );

		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		$result = [];

		foreach ( $categories as $category ) {
			// Only include scale categories (those with a 'scale' property).
			if ( ! isset( $category['scale'] ) ) {
				continue;
			}

			$cat_id = $category['id'] ?? '';

			// Filter variables belonging to this category.
			$cat_variables = array_values(
				array_filter(
					$variables,
					static fn( array $var ) => ( $var['category'] ?? '' ) === $cat_id
				)
			);

			$formatted_vars = array_map(
				static fn( array $var ) => [
					'id'    => $var['id'] ?? '',
					'name'  => $var['name'] ?? '',
					'value' => $var['value'] ?? '',
				],
				$cat_variables
			);

			$result[] = [
				'id'              => $cat_id,
				'name'            => $category['name'] ?? '',
				'prefix'          => $category['scale']['prefix'] ?? '',
				'utility_classes' => $category['utilityClasses'] ?? [],
				'variables'       => $formatted_vars,
				'variable_count'  => count( $formatted_vars ),
			];
		}

		return $result;
	}

	/**
	 * Create a new typography scale category with variables.
	 *
	 * Creates a category with scale config and generates CSS variables
	 * for each step. Regenerates style manager CSS after creation.
	 *
	 * @param string                                         $name            Scale name.
	 * @param array<int, array{name: string, value: string}> $steps Steps with name and value.
	 * @param string                                         $prefix          CSS variable prefix (must start with --).
	 * @param array<int, array<string, mixed>>               $utility_classes Utility class configs (optional).
	 * @return array<string, mixed>|\WP_Error Created scale object or WP_Error on failure.
	 */
	public function create_typography_scale( string $name, array $steps, string $prefix, array $utility_classes = [] ): array|\WP_Error {
		$sanitized_name = sanitize_text_field( $name );

		if ( '' === $sanitized_name ) {
			return new \WP_Error(
				'missing_name',
				__( 'Scale name is required. Provide a non-empty "name" parameter.', 'bricks-mcp' )
			);
		}

		if ( ! str_starts_with( $prefix, '--' ) ) {
			return new \WP_Error(
				'invalid_prefix',
				__( 'CSS variable prefix must start with "--" (e.g., "--text-", "--heading-").', 'bricks-mcp' )
			);
		}

		if ( empty( $steps ) ) {
			return new \WP_Error(
				'missing_steps',
				__( 'At least one step is required. Each step must have "name" and "value" (e.g., {"name": "sm", "value": "0.875rem"}).', 'bricks-mcp' )
			);
		}

		// Validate steps.
		foreach ( $steps as $index => $step ) {
			if ( empty( $step['name'] ) || ! isset( $step['value'] ) ) {
				return new \WP_Error(
					'invalid_step',
					sprintf(
						/* translators: %d: Step index */
						__( 'Step at index %d must have both "name" and "value" properties.', 'bricks-mcp' ),
						$index
					)
				);
			}
		}

		$categories = get_option( 'bricks_global_variables_categories', [] );

		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		$variables = get_option( 'bricks_global_variables', [] );

		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		// Generate collision-free category ID.
		$id_generator = new ElementIdGenerator();
		$existing_ids = array_column( $categories, 'id' );
		do {
			$cat_id = $id_generator->generate();
		} while ( in_array( $cat_id, $existing_ids, true ) );

		// Default utility classes if empty.
		if ( empty( $utility_classes ) ) {
			$class_prefix    = str_replace( '--', '', $prefix );
			$class_prefix    = rtrim( $class_prefix, '-' );
			$utility_classes = [
				[
					'className'   => $class_prefix . '-*',
					'cssProperty' => 'font-size',
				],
			];
		}

		// Build category entry.
		$new_category = [
			'id'             => $cat_id,
			'name'           => $sanitized_name,
			'scale'          => [ 'prefix' => $prefix ],
			'utilityClasses' => $utility_classes,
		];

		$categories[] = $new_category;
		update_option( 'bricks_global_variables_categories', $categories );

		// Generate variables for each step.
		$existing_var_ids = array_column( $variables, 'id' );
		$new_variables    = [];

		foreach ( $steps as $step ) {
			do {
				$var_id = $id_generator->generate();
			} while ( in_array( $var_id, $existing_var_ids, true ) || in_array( $var_id, array_column( $new_variables, 'id' ), true ) );

			$new_var = [
				'id'       => $var_id,
				'name'     => $prefix . sanitize_text_field( $step['name'] ),
				'value'    => sanitize_text_field( $step['value'] ),
				'category' => $cat_id,
			];

			$new_variables[]    = $new_var;
			$existing_var_ids[] = $var_id;
		}

		$variables = array_merge( $variables, $new_variables );
		update_option( 'bricks_global_variables', $variables );

		// Regenerate style manager CSS.
		$css_regenerated = $this->core->regenerate_style_manager_css();

		return [
			'id'              => $cat_id,
			'name'            => $sanitized_name,
			'prefix'          => $prefix,
			'utility_classes' => $utility_classes,
			'variables'       => array_map(
				static fn( array $var ) => [
					'id'    => $var['id'],
					'name'  => $var['name'],
					'value' => $var['value'],
				],
				$new_variables
			),
			'variable_count'  => count( $new_variables ),
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Update a typography scale category and/or its variables.
	 *
	 * Supports renaming, prefix change (auto-renames existing variables),
	 * utility class updates, and step add/update/delete operations.
	 *
	 * @param string                                $category_id    Scale category ID.
	 * @param string|null                           $name           New name (null to skip).
	 * @param array<int, array<string, mixed>>|null $steps        Steps to add/update/delete (null to skip).
	 * @param string|null                           $prefix         New CSS variable prefix (null to skip).
	 * @param array<int, array<string, mixed>>|null $utility_classes New utility classes (null to skip).
	 * @return array<string, mixed>|\WP_Error Updated scale object or WP_Error on failure.
	 */
	public function update_typography_scale( string $category_id, ?string $name = null, ?array $steps = null, ?string $prefix = null, ?array $utility_classes = null ): array|\WP_Error {
		$categories = get_option( 'bricks_global_variables_categories', [] );

		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		// Find the category.
		$cat_index = null;
		foreach ( $categories as $index => $cat ) {
			if ( ( $cat['id'] ?? '' ) === $category_id ) {
				$cat_index = $index;
				break;
			}
		}

		if ( null === $cat_index ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Category ID */
					__( 'Typography scale category "%s" not found. Use get_typography_scales to discover available scale IDs.', 'bricks-mcp' ),
					$category_id
				)
			);
		}

		// Verify it is a scale category.
		if ( ! isset( $categories[ $cat_index ]['scale'] ) ) {
			return new \WP_Error(
				'not_a_scale',
				sprintf(
					/* translators: %s: Category ID */
					__( 'Category "%s" is not a typography scale (no scale property). Use get_typography_scales to find scale categories.', 'bricks-mcp' ),
					$category_id
				)
			);
		}

		$variables = get_option( 'bricks_global_variables', [] );

		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		// Update name if provided.
		if ( null !== $name ) {
			$categories[ $cat_index ]['name'] = sanitize_text_field( $name );
		}

		// Update prefix if provided — also rename existing variables.
		if ( null !== $prefix ) {
			if ( ! str_starts_with( $prefix, '--' ) ) {
				return new \WP_Error(
					'invalid_prefix',
					__( 'CSS variable prefix must start with "--" (e.g., "--text-", "--heading-").', 'bricks-mcp' )
				);
			}

			$old_prefix                                  = $categories[ $cat_index ]['scale']['prefix'] ?? '';
			$categories[ $cat_index ]['scale']['prefix'] = $prefix;

			// Rename existing variables for this category.
			if ( '' !== $old_prefix ) {
				foreach ( $variables as &$var ) {
					if ( ( $var['category'] ?? '' ) === $category_id && str_starts_with( $var['name'] ?? '', $old_prefix ) ) {
						$step_name   = substr( $var['name'], strlen( $old_prefix ) );
						$var['name'] = $prefix . $step_name;
					}
				}
				unset( $var );
			}
		}

		// Update utility classes if provided.
		if ( null !== $utility_classes ) {
			$categories[ $cat_index ]['utilityClasses'] = $utility_classes;
		}

		update_option( 'bricks_global_variables_categories', $categories );

		// Update steps if provided.
		if ( null !== $steps ) {
			$id_generator     = new ElementIdGenerator();
			$existing_var_ids = array_column( $variables, 'id' );
			$current_prefix   = $categories[ $cat_index ]['scale']['prefix'] ?? '';

			foreach ( $steps as $step ) {
				if ( ! empty( $step['id'] ) ) {
					if ( ! empty( $step['delete'] ) ) {
						// Delete the variable.
						$variables = array_values(
							array_filter(
								$variables,
								static fn( array $var ) => ( $var['id'] ?? '' ) !== $step['id']
							)
						);
					} else {
						// Update existing variable.
						foreach ( $variables as &$var ) {
							if ( ( $var['id'] ?? '' ) === $step['id'] ) {
								if ( isset( $step['name'] ) ) {
									$var['name'] = $current_prefix . sanitize_text_field( $step['name'] );
								}
								if ( isset( $step['value'] ) ) {
									$var['value'] = sanitize_text_field( $step['value'] );
								}
								break;
							}
						}
						unset( $var );
					}
				} else {
					// New step — create a new variable.
					if ( empty( $step['name'] ) || ! isset( $step['value'] ) ) {
						continue; // Skip invalid new steps.
					}

					do {
						$var_id = $id_generator->generate();
					} while ( in_array( $var_id, $existing_var_ids, true ) );

					$variables[]        = [
						'id'       => $var_id,
						'name'     => $current_prefix . sanitize_text_field( $step['name'] ),
						'value'    => sanitize_text_field( $step['value'] ),
						'category' => $category_id,
					];
					$existing_var_ids[] = $var_id;
				}
			}
		}

		update_option( 'bricks_global_variables', $variables );

		// Regenerate style manager CSS.
		$css_regenerated = $this->core->regenerate_style_manager_css();

		// Build response with current state.
		$cat_variables = array_values(
			array_filter(
				$variables,
				static fn( array $var ) => ( $var['category'] ?? '' ) === $category_id
			)
		);

		return [
			'id'              => $category_id,
			'name'            => $categories[ $cat_index ]['name'] ?? '',
			'prefix'          => $categories[ $cat_index ]['scale']['prefix'] ?? '',
			'utility_classes' => $categories[ $cat_index ]['utilityClasses'] ?? [],
			'variables'       => array_map(
				static fn( array $var ) => [
					'id'    => $var['id'] ?? '',
					'name'  => $var['name'] ?? '',
					'value' => $var['value'] ?? '',
				],
				$cat_variables
			),
			'variable_count'  => count( $cat_variables ),
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Delete a typography scale category and all its variables.
	 *
	 * Removes the category from bricks_global_variables_categories and
	 * removes all variables belonging to it from bricks_global_variables.
	 *
	 * @param string $category_id Scale category ID.
	 * @return array<string, mixed>|\WP_Error Deletion result or WP_Error on failure.
	 */
	public function delete_typography_scale( string $category_id ): array|\WP_Error {
		$categories = get_option( 'bricks_global_variables_categories', [] );

		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		// Find and verify the category.
		$found     = false;
		$cat_index = null;
		foreach ( $categories as $index => $cat ) {
			if ( ( $cat['id'] ?? '' ) === $category_id ) {
				$cat_index = $index;
				$found     = true;
				break;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Category ID */
					__( 'Typography scale category "%s" not found. Use get_typography_scales to discover available scale IDs.', 'bricks-mcp' ),
					$category_id
				)
			);
		}

		// Verify it is a scale category.
		if ( ! isset( $categories[ $cat_index ]['scale'] ) ) {
			return new \WP_Error(
				'not_a_scale',
				sprintf(
					/* translators: %s: Category ID */
					__( 'Category "%s" is not a typography scale. Will not delete plain variable categories from this tool.', 'bricks-mcp' ),
					$category_id
				)
			);
		}

		// Remove category.
		array_splice( $categories, $cat_index, 1 );
		update_option( 'bricks_global_variables_categories', $categories );

		// Remove all variables belonging to this category.
		$variables     = get_option( 'bricks_global_variables', [] );
		$removed_count = 0;

		if ( is_array( $variables ) ) {
			$original_count = count( $variables );
			$variables      = array_values(
				array_filter(
					$variables,
					static fn( array $var ) => ( $var['category'] ?? '' ) !== $category_id
				)
			);
			$removed_count  = $original_count - count( $variables );
			update_option( 'bricks_global_variables', $variables );
		}

		// Regenerate style manager CSS.
		$css_regenerated = $this->core->regenerate_style_manager_css();

		return [
			'action'            => 'deleted',
			'category_id'       => $category_id,
			'variables_removed' => $removed_count,
			'css_regenerated'   => $css_regenerated,
		];
	}
}
