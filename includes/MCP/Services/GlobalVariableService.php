<?php
/**
 * Global variable management sub-service.
 *
 * Handles CRUD for Bricks global CSS custom property variables and categories.
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
 * GlobalVariableService class.
 */
class GlobalVariableService {

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
	 * Get all global variables organized by category.
	 *
	 * @return array<string, mixed> Variables organized by category.
	 */
	public function get_global_variables(): array {
		$categories = get_option( 'bricks_global_variables_categories', [] );
		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		$variables = get_option( 'bricks_global_variables', [] );
		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		$result_categories = [];

		foreach ( $categories as $cat ) {
			$cat_id = $cat['id'] ?? '';

			$cat_vars = array_values(
				array_filter( $variables, static fn( array $var ) => ( $var['category'] ?? '' ) === $cat_id )
			);

			$formatted_vars = array_map(
				static fn( array $var ) => [
					'id'       => $var['id'] ?? '',
					'name'     => $var['name'] ?? '',
					'value'    => $var['value'] ?? '',
					'category' => $var['category'] ?? '',
				],
				$cat_vars
			);

			$cat_entry = [
				'id'             => $cat_id,
				'name'           => $cat['name'] ?? '',
				'variables'      => $formatted_vars,
				'variable_count' => count( $formatted_vars ),
			];

			if ( isset( $cat['scale'] ) ) {
				$cat_entry['scale'] = true;
			}

			$result_categories[] = $cat_entry;
		}

		$all_cat_ids   = array_column( $categories, 'id' );
		$uncategorized = array_values(
			array_filter( $variables, static fn( array $var ) => '' === ( $var['category'] ?? '' ) || ! in_array( $var['category'] ?? '', $all_cat_ids, true ) )
		);

		$formatted_uncategorized = array_map(
			static fn( array $var ) => [
				'id'       => $var['id'] ?? '',
				'name'     => $var['name'] ?? '',
				'value'    => $var['value'] ?? '',
				'category' => $var['category'] ?? '',
			],
			$uncategorized
		);

		$total = 0;
		foreach ( $result_categories as $cat ) {
			$total += $cat['variable_count'];
		}
		$total += count( $formatted_uncategorized );

		return [
			'categories'      => $result_categories,
			'uncategorized'   => $formatted_uncategorized,
			'total_variables' => $total,
			'note'            => __( 'Plain global variables are stored as design tokens for AI reference. Only color palette colors and typography scale variables generate CSS output in style-manager.min.css.', 'bricks-mcp' ),
		];
	}

	/**
	 * Create a non-scale variable category.
	 *
	 * @param string $name Category name.
	 * @return array<string, mixed>|\WP_Error Created category or WP_Error on failure.
	 */
	public function create_variable_category( string $name ): array|\WP_Error {
		$sanitized_name = sanitize_text_field( $name );
		if ( '' === $sanitized_name ) {
			return new \WP_Error( 'missing_name', __( 'Category name is required.', 'bricks-mcp' ) );
		}

		$categories = get_option( 'bricks_global_variables_categories', [] );
		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		$id_generator = new ElementIdGenerator();
		$existing_ids = array_column( $categories, 'id' );

		do {
			$cat_id = $id_generator->generate();
		} while ( in_array( $cat_id, $existing_ids, true ) );

		$new_category = [ 'id' => $cat_id, 'name' => $sanitized_name ];

		$categories[] = $new_category;
		update_option( 'bricks_global_variables_categories', $categories );

		$css_regenerated = $this->core->regenerate_style_manager_css();

		return [ 'id' => $cat_id, 'name' => $sanitized_name, 'css_regenerated' => $css_regenerated ];
	}

	/**
	 * Rename a non-scale variable category.
	 *
	 * @param string $category_id Category ID.
	 * @param string $name        New name.
	 * @return array<string, mixed>|\WP_Error Updated category or WP_Error on failure.
	 */
	public function update_variable_category( string $category_id, string $name ): array|\WP_Error {
		$sanitized_name = sanitize_text_field( $name );
		if ( '' === $sanitized_name ) {
			return new \WP_Error( 'missing_name', __( 'Category name is required.', 'bricks-mcp' ) );
		}

		$categories = get_option( 'bricks_global_variables_categories', [] );
		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		$cat_index = null;
		foreach ( $categories as $i => $cat ) {
			if ( ( $cat['id'] ?? '' ) === $category_id ) {
				$cat_index = $i;
				break;
			}
		}

		if ( null === $cat_index ) {
			return new \WP_Error( 'not_found', sprintf( __( 'Variable category "%s" not found. Use list_global_variables to discover available category IDs.', 'bricks-mcp' ), $category_id ) );
		}

		if ( isset( $categories[ $cat_index ]['scale'] ) ) {
			return new \WP_Error( 'is_scale_category', sprintf( __( 'Category "%s" is a typography scale. Use update_typography_scale to modify scale categories.', 'bricks-mcp' ), $category_id ) );
		}

		$categories[ $cat_index ]['name'] = $sanitized_name;
		update_option( 'bricks_global_variables_categories', $categories );

		$css_regenerated = $this->core->regenerate_style_manager_css();

		return [ 'id' => $category_id, 'name' => $sanitized_name, 'css_regenerated' => $css_regenerated ];
	}

	/**
	 * Delete a non-scale variable category and all its variables.
	 *
	 * @param string $category_id Category ID.
	 * @return array<string, mixed>|\WP_Error Deletion result or WP_Error on failure.
	 */
	public function delete_variable_category( string $category_id ): array|\WP_Error {
		$categories = get_option( 'bricks_global_variables_categories', [] );
		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		$cat_index = null;
		$cat_name  = '';
		foreach ( $categories as $i => $cat ) {
			if ( ( $cat['id'] ?? '' ) === $category_id ) {
				$cat_index = $i;
				$cat_name  = $cat['name'] ?? '';
				break;
			}
		}

		if ( null === $cat_index ) {
			return new \WP_Error( 'not_found', sprintf( __( 'Variable category "%s" not found. Use list_global_variables to discover available category IDs.', 'bricks-mcp' ), $category_id ) );
		}

		if ( isset( $categories[ $cat_index ]['scale'] ) ) {
			return new \WP_Error( 'is_scale_category', sprintf( __( 'Category "%s" is a typography scale. Use delete_typography_scale to remove scale categories.', 'bricks-mcp' ), $category_id ) );
		}

		array_splice( $categories, $cat_index, 1 );
		update_option( 'bricks_global_variables_categories', $categories );

		$variables     = get_option( 'bricks_global_variables', [] );
		$removed_count = 0;

		if ( is_array( $variables ) ) {
			$original_count = count( $variables );
			$variables      = array_values( array_filter( $variables, static fn( array $var ) => ( $var['category'] ?? '' ) !== $category_id ) );
			$removed_count  = $original_count - count( $variables );
			update_option( 'bricks_global_variables', $variables );
		}

		$css_regenerated = $this->core->regenerate_style_manager_css();

		return [
			'action'            => 'deleted',
			'category_id'       => $category_id,
			'category_name'     => $cat_name,
			'variables_removed' => $removed_count,
			'css_regenerated'   => $css_regenerated,
		];
	}

	/**
	 * Normalize a variable name to include the -- prefix.
	 *
	 * @param string $name Variable name.
	 * @return string Normalized name.
	 */
	private function normalize_variable_name( string $name ): string {
		$name = sanitize_text_field( $name );
		if ( ! str_starts_with( $name, '--' ) ) {
			$name = '--' . $name;
		}
		return $name;
	}

	/**
	 * Create a global CSS custom property variable.
	 *
	 * @param string $name        Variable name.
	 * @param string $value       CSS value.
	 * @param string $category_id Optional category ID.
	 * @return array<string, mixed>|\WP_Error Created variable or WP_Error on failure.
	 */
	public function create_global_variable( string $name, string $value, string $category_id = '' ): array|\WP_Error {
		$normalized_name = $this->normalize_variable_name( $name );

		if ( '--' === $normalized_name ) {
			return new \WP_Error( 'missing_name', __( 'Variable name is required.', 'bricks-mcp' ) );
		}

		$sanitized_value = sanitize_text_field( $value );
		if ( '' === $sanitized_value ) {
			return new \WP_Error( 'missing_value', __( 'Variable value is required.', 'bricks-mcp' ) );
		}

		if ( '' !== $category_id ) {
			$categories = get_option( 'bricks_global_variables_categories', [] );
			if ( ! is_array( $categories ) ) {
				$categories = [];
			}

			$cat_found = false;
			foreach ( $categories as $cat ) {
				if ( ( $cat['id'] ?? '' ) === $category_id ) {
					if ( isset( $cat['scale'] ) ) {
						return new \WP_Error( 'is_scale_category', sprintf( __( 'Category "%s" is a typography scale. Use create_typography_scale to add variables to scale categories.', 'bricks-mcp' ), $category_id ) );
					}
					$cat_found = true;
					break;
				}
			}

			if ( ! $cat_found ) {
				return new \WP_Error( 'category_not_found', sprintf( __( 'Category "%s" not found. Use list_global_variables to discover available category IDs, or create_variable_category to create one.', 'bricks-mcp' ), $category_id ) );
			}
		}

		$variables = get_option( 'bricks_global_variables', [] );
		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		$id_generator = new ElementIdGenerator();
		$existing_ids = array_column( $variables, 'id' );

		do {
			$var_id = $id_generator->generate();
		} while ( in_array( $var_id, $existing_ids, true ) );

		$new_variable = [
			'id'       => $var_id,
			'name'     => $normalized_name,
			'value'    => $sanitized_value,
			'category' => $category_id,
		];

		$variables[] = $new_variable;
		update_option( 'bricks_global_variables', $variables );

		$css_regenerated = $this->core->regenerate_style_manager_css();

		return [
			'id'              => $var_id,
			'name'            => $normalized_name,
			'value'           => $sanitized_value,
			'category'        => $category_id,
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Update a global variable's name, value, or category.
	 *
	 * @param string               $variable_id Variable ID.
	 * @param array<string, mixed> $fields      Fields to update.
	 * @return array<string, mixed>|\WP_Error Updated variable or WP_Error on failure.
	 */
	public function update_global_variable( string $variable_id, array $fields ): array|\WP_Error {
		$variables = get_option( 'bricks_global_variables', [] );
		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		$var_index = null;
		foreach ( $variables as $i => $var ) {
			if ( ( $var['id'] ?? '' ) === $variable_id ) {
				$var_index = $i;
				break;
			}
		}

		if ( null === $var_index ) {
			return new \WP_Error( 'not_found', sprintf( __( 'Variable "%s" not found. Use list_global_variables to discover available variable IDs.', 'bricks-mcp' ), $variable_id ) );
		}

		$old_name       = $variables[ $var_index ]['name'] ?? '';
		$rename_warning = '';

		if ( isset( $fields['name'] ) ) {
			$new_name = $this->normalize_variable_name( $fields['name'] );
			if ( '--' === $new_name ) {
				return new \WP_Error( 'missing_name', __( 'Variable name cannot be empty.', 'bricks-mcp' ) );
			}

			if ( $new_name !== $old_name ) {
				$variables[ $var_index ]['name'] = $new_name;
				$rename_warning = sprintf( __( 'Variable renamed. Existing references to var(%s) in elements and styles will NOT be automatically updated.', 'bricks-mcp' ), $old_name );
			}
		}

		if ( isset( $fields['value'] ) ) {
			$sanitized_value = sanitize_text_field( $fields['value'] );
			if ( '' === $sanitized_value ) {
				return new \WP_Error( 'missing_value', __( 'Variable value cannot be empty.', 'bricks-mcp' ) );
			}
			$variables[ $var_index ]['value'] = $sanitized_value;
		}

		if ( array_key_exists( 'category', $fields ) ) {
			$new_category = $fields['category'] ?? '';

			if ( '' !== $new_category ) {
				$categories = get_option( 'bricks_global_variables_categories', [] );
				if ( ! is_array( $categories ) ) {
					$categories = [];
				}

				$cat_found = false;
				foreach ( $categories as $cat ) {
					if ( ( $cat['id'] ?? '' ) === $new_category ) {
						if ( isset( $cat['scale'] ) ) {
							return new \WP_Error( 'is_scale_category', sprintf( __( 'Category "%s" is a typography scale. Cannot assign plain variables to scale categories.', 'bricks-mcp' ), $new_category ) );
						}
						$cat_found = true;
						break;
					}
				}

				if ( ! $cat_found ) {
					return new \WP_Error( 'category_not_found', sprintf( __( 'Category "%s" not found.', 'bricks-mcp' ), $new_category ) );
				}
			}

			$variables[ $var_index ]['category'] = $new_category;
		}

		update_option( 'bricks_global_variables', $variables );

		$css_regenerated = $this->core->regenerate_style_manager_css();

		$result = [
			'id'              => $variable_id,
			'name'            => $variables[ $var_index ]['name'] ?? '',
			'value'           => $variables[ $var_index ]['value'] ?? '',
			'category'        => $variables[ $var_index ]['category'] ?? '',
			'css_regenerated' => $css_regenerated,
		];

		if ( '' !== $rename_warning ) {
			$result['warning'] = $rename_warning;
		}

		return $result;
	}

	/**
	 * Delete a global variable permanently.
	 *
	 * @param string $variable_id Variable ID.
	 * @return array<string, mixed>|\WP_Error Deletion result or WP_Error on failure.
	 */
	public function delete_global_variable( string $variable_id ): array|\WP_Error {
		$variables = get_option( 'bricks_global_variables', [] );
		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		$var_index = null;
		$var_name  = '';
		foreach ( $variables as $i => $var ) {
			if ( ( $var['id'] ?? '' ) === $variable_id ) {
				$var_index = $i;
				$var_name  = $var['name'] ?? '';
				break;
			}
		}

		if ( null === $var_index ) {
			return new \WP_Error( 'not_found', sprintf( __( 'Variable "%s" not found. Use list_global_variables to discover available variable IDs.', 'bricks-mcp' ), $variable_id ) );
		}

		array_splice( $variables, $var_index, 1 );
		update_option( 'bricks_global_variables', $variables );

		$css_regenerated = $this->core->regenerate_style_manager_css();

		return [
			'action'          => 'deleted',
			'id'              => $variable_id,
			'name'            => $var_name,
			'note'            => sprintf( __( 'Existing elements referencing var(%s) will show CSS fallback values.', 'bricks-mcp' ), $var_name ),
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Batch-create multiple global variables in one call.
	 *
	 * @param array<int, array{name: string, value: string}> $variable_defs Variable definitions.
	 * @param string                                         $category_id   Optional shared category ID.
	 * @return array<string, mixed> Result with created, errors, and css_regenerated.
	 */
	public function batch_create_global_variables( array $variable_defs, string $category_id = '' ): array {
		if ( '' !== $category_id ) {
			$categories = get_option( 'bricks_global_variables_categories', [] );
			if ( ! is_array( $categories ) ) {
				$categories = [];
			}

			$cat_found = false;
			foreach ( $categories as $cat ) {
				if ( ( $cat['id'] ?? '' ) === $category_id ) {
					if ( isset( $cat['scale'] ) ) {
						return [ 'created' => [], 'errors' => [ 'category' => __( 'Cannot add plain variables to a typography scale category.', 'bricks-mcp' ) ], 'css_regenerated' => false ];
					}
					$cat_found = true;
					break;
				}
			}

			if ( ! $cat_found ) {
				return [ 'created' => [], 'errors' => [ 'category' => sprintf( __( 'Category "%s" not found.', 'bricks-mcp' ), $category_id ) ], 'css_regenerated' => false ];
			}
		}

		$variables    = get_option( 'bricks_global_variables', [] );
		$existing_ids = is_array( $variables ) ? array_column( $variables, 'id' ) : [];
		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		$id_generator = new ElementIdGenerator();
		$created      = [];
		$errors       = [];

		foreach ( $variable_defs as $index => $def ) {
			if ( empty( $def['name'] ) ) {
				$errors[ $index ] = __( 'Missing name', 'bricks-mcp' );
				continue;
			}

			$normalized_name = $this->normalize_variable_name( $def['name'] );
			if ( '--' === $normalized_name ) {
				$errors[ $index ] = __( 'Empty name after normalization', 'bricks-mcp' );
				continue;
			}

			if ( ! isset( $def['value'] ) || '' === $def['value'] ) {
				$errors[ $index ] = __( 'Missing value', 'bricks-mcp' );
				continue;
			}

			$sanitized_value = sanitize_text_field( $def['value'] );

			do {
				$var_id = $id_generator->generate();
			} while ( in_array( $var_id, $existing_ids, true ) );
			$existing_ids[] = $var_id;

			$new_variable = [
				'id'       => $var_id,
				'name'     => $normalized_name,
				'value'    => $sanitized_value,
				'category' => $category_id,
			];

			$variables[] = $new_variable;
			$created[]   = $new_variable;
		}

		if ( ! empty( $created ) ) {
			update_option( 'bricks_global_variables', $variables );
		}

		$css_regenerated = ! empty( $created ) ? $this->core->regenerate_style_manager_css() : false;

		return [
			'created'         => $created,
			'errors'          => $errors,
			'created_count'   => count( $created ),
			'error_count'     => count( $errors ),
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Delete multiple global variables in a single operation.
	 *
	 * @param array<int, string> $variable_ids Array of variable ID strings.
	 * @return array<string, mixed>|\WP_Error Partial result or WP_Error if all fail.
	 */
	public function batch_delete_global_variables( array $variable_ids ): array|\WP_Error {
		if ( count( $variable_ids ) > BricksCore::BATCH_SIZE ) {
			return new \WP_Error(
				'batch_too_large',
				sprintf(
					/* translators: %d: Maximum batch size */
					__( 'Maximum %d variable deletions per call.', 'bricks-mcp' ),
					BricksCore::BATCH_SIZE
				)
			);
		}

		$variables = get_option( 'bricks_global_variables', [] );
		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		$var_map = [];
		foreach ( $variables as $i => $var ) {
			$var_map[ $var['id'] ?? '' ] = $i;
		}

		$success           = [];
		$errors            = [];
		$indices_to_remove = [];

		foreach ( $variable_ids as $vid ) {
			if ( isset( $var_map[ $vid ] ) ) {
				$success[]           = [ 'id' => $vid, 'status' => 'deleted' ];
				$indices_to_remove[] = $var_map[ $vid ];
			} else {
				$errors[] = [ 'id' => $vid, 'error' => 'Variable not found' ];
			}
		}

		if ( empty( $success ) ) {
			return new \WP_Error( 'all_failed', __( 'None of the specified variable IDs were found.', 'bricks-mcp' ) );
		}

		rsort( $indices_to_remove );
		foreach ( $indices_to_remove as $idx ) {
			array_splice( $variables, $idx, 1 );
		}

		update_option( 'bricks_global_variables', $variables );

		$css_regenerated = $this->core->regenerate_style_manager_css();

		return [
			'success'         => $success,
			'errors'          => $errors,
			'summary'         => [ 'total' => count( $variable_ids ), 'succeeded' => count( $success ), 'failed' => count( $errors ) ],
			'css_regenerated' => $css_regenerated,
		];
	}

	// -------------------------------------------------------------------------
	// Pattern capture helpers
	// -------------------------------------------------------------------------

	/**
	 * Return a map of variable name (including leading --) → definition with value.
	 *
	 * Reads the raw flat option directly (not the structured get_global_variables()
	 * result) so we get a simple iterable list of variable records.
	 *
	 * @return array<string, array>
	 */
	public function get_all_with_values(): array {
		$vars = get_option( 'bricks_global_variables', [] );
		if ( ! is_array( $vars ) ) {
			$vars = [];
		}
		$map = [];
		foreach ( $vars as $v ) {
			$name = $v['name'] ?? '';
			// Ensure name starts with --.
			if ( $name !== '' && ! str_starts_with( $name, '--' ) ) {
				$name = '--' . $name;
			}
			if ( $name !== '' ) {
				$map[ $name ] = $v;
			}
		}
		return $map;
	}

	/**
	 * Check whether a variable with the given name exists.
	 *
	 * @param string $name Variable name, including leading `--`.
	 */
	public function exists( string $name ): bool {
		$key = str_starts_with( $name, '--' ) ? $name : '--' . $name;
		return isset( $this->get_all_with_values()[ $key ] );
	}

	/**
	 * Create a variable from an exported payload.
	 *
	 * @param string $name    Variable name, including leading `--`.
	 * @param array  $payload { value: string, category?: string }.
	 * @return bool True on success, false on error.
	 */
	public function create_from_payload( string $name, array $payload ): bool {
		$clean_name = str_starts_with( $name, '--' ) ? substr( $name, 2 ) : $name;
		$value      = (string) ( $payload['value'] ?? '' );
		$category   = (string) ( $payload['category'] ?? '' );

		$result = $this->create_global_variable( $clean_name, $value, $category );
		return ! is_wp_error( $result );
	}

	/**
	 * Search global variables by name and/or value substring.
	 *
	 * @param string $name        Name substring filter.
	 * @param string $value       Value substring filter.
	 * @param string $category_id Category ID filter.
	 * @return array<string, mixed> Search results with count and variables.
	 */
	public function search_global_variables( string $name = '', string $value = '', string $category_id = '' ): array {
		$variables = get_option( 'bricks_global_variables', [] );
		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		if ( '' === $name && '' === $value && '' === $category_id ) {
			return [ 'variables' => array_values( $variables ), 'count' => count( $variables ), 'filters' => [] ];
		}

		$filtered = array_values(
			array_filter(
				$variables,
				function ( array $var ) use ( $name, $value, $category_id ): bool {
					if ( '' !== $name && false === stripos( $var['name'] ?? '', $name ) ) {
						return false;
					}
					if ( '' !== $value && false === stripos( $var['value'] ?? '', $value ) ) {
						return false;
					}
					if ( '' !== $category_id && ( $var['category'] ?? '' ) !== $category_id ) {
						return false;
					}
					return true;
				}
			)
		);

		return [
			'variables' => $filtered,
			'count'     => count( $filtered ),
			'filters'   => array_filter( [ 'name' => $name, 'value' => $value, 'category_id' => $category_id ] ),
		];
	}
}
