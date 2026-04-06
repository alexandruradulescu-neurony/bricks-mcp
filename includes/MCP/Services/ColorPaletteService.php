<?php
/**
 * Color palette management sub-service.
 *
 * Handles CRUD for Bricks color palettes and individual colors.
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
 * ColorPaletteService class.
 */
class ColorPaletteService {

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
	 * Get all color palettes with their colors.
	 *
	 * @return array<int, array<string, mixed>> All palettes with their colors.
	 */
	public function get_color_palettes(): array {
		$palettes = get_option( 'bricks_color_palette', [] );

		if ( ! is_array( $palettes ) ) {
			return [];
		}

		$result = [];

		foreach ( $palettes as $palette ) {
			$colors = [];

			foreach ( $palette['colors'] ?? [] as $color ) {
				$formatted = [
					'id'    => $color['id'] ?? '',
					'light' => $color['light'] ?? '',
					'raw'   => $color['raw'] ?? '',
				];

				if ( ! empty( $color['type'] ) ) {
					$formatted['type'] = $color['type'];
				}

				if ( ! empty( $color['parent'] ) ) {
					$formatted['parent'] = $color['parent'];
				}

				if ( ! empty( $color['utilityClasses'] ) ) {
					$formatted['utilityClasses'] = $color['utilityClasses'];
				}

				$colors[] = $formatted;
			}

			$result[] = [
				'id'          => $palette['id'] ?? '',
				'name'        => $palette['name'] ?? '',
				'colors'      => $colors,
				'color_count' => count( $colors ),
			];
		}

		return $result;
	}

	/**
	 * Derive a CSS variable name from a friendly color name.
	 *
	 * @param string $name Friendly color name.
	 * @return string CSS variable reference.
	 */
	private function derive_css_variable_from_name( string $name ): string {
		$css_name = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', trim( $name ) ) );
		$css_name = trim( $css_name, '-' );

		return 'var(--' . $css_name . ')';
	}

	/**
	 * Normalize a raw CSS variable reference to var(--name) format.
	 *
	 * @param string $raw The raw CSS variable reference.
	 * @return string Normalized CSS variable reference.
	 */
	private function normalize_raw_css_variable( string $raw ): string {
		if ( str_starts_with( $raw, 'var(' ) ) {
			return $raw;
		}

		if ( str_starts_with( $raw, '--' ) ) {
			return 'var(' . $raw . ')';
		}

		return 'var(--' . $raw . ')';
	}

	/**
	 * Create a new color palette with optional initial colors.
	 *
	 * @param string                           $name   Palette name.
	 * @param array<int, array<string, mixed>> $colors Optional initial colors.
	 * @return array<string, mixed>|\WP_Error Created palette or WP_Error on failure.
	 */
	public function create_color_palette( string $name, array $colors = [] ): array|\WP_Error {
		$sanitized_name = sanitize_text_field( $name );

		if ( '' === $sanitized_name ) {
			return new \WP_Error(
				'missing_name',
				__( 'Palette name is required.', 'bricks-mcp' )
			);
		}

		$palettes = get_option( 'bricks_color_palette', [] );

		if ( ! is_array( $palettes ) ) {
			$palettes = [];
		}

		$id_generator = new ElementIdGenerator();

		$existing_ids = [];
		foreach ( $palettes as $p ) {
			$existing_ids[] = $p['id'] ?? '';
			foreach ( $p['colors'] ?? [] as $c ) {
				$existing_ids[] = $c['id'] ?? '';
			}
		}

		do {
			$palette_id = $id_generator->generate();
		} while ( in_array( $palette_id, $existing_ids, true ) );
		$existing_ids[] = $palette_id;

		$palette_colors   = [];
		$color_name_to_id = [];

		// First pass: root colors.
		foreach ( $colors as $color_def ) {
			if ( ! empty( $color_def['parent'] ) ) {
				continue;
			}

			if ( empty( $color_def['name'] ) || empty( $color_def['light'] ) ) {
				continue;
			}

			$hex = sanitize_hex_color( $color_def['light'] );

			if ( null === $hex ) {
				continue;
			}

			do {
				$color_id = $id_generator->generate();
			} while ( in_array( $color_id, $existing_ids, true ) );
			$existing_ids[] = $color_id;

			$raw = ! empty( $color_def['raw'] )
				? $this->normalize_raw_css_variable( $color_def['raw'] )
				: $this->derive_css_variable_from_name( $color_def['name'] );

			$color_obj = [
				'id'    => $color_id,
				'light' => $hex,
				'raw'   => $raw,
			];

			if ( ! empty( $color_def['utility_classes'] ) && is_array( $color_def['utility_classes'] ) ) {
				$color_obj['utilityClasses'] = array_values(
					array_intersect(
						$color_def['utility_classes'],
						[ 'bg', 'text', 'border', 'outline', 'fill', 'stroke' ]
					)
				);
			}

			$palette_colors[]                       = $color_obj;
			$color_name_to_id[ $color_def['name'] ] = $color_id;
		}

		// Second pass: child colors.
		foreach ( $colors as $color_def ) {
			if ( empty( $color_def['parent'] ) ) {
				continue;
			}

			if ( empty( $color_def['name'] ) || empty( $color_def['light'] ) ) {
				continue;
			}

			$hex = sanitize_hex_color( $color_def['light'] );

			if ( null === $hex ) {
				continue;
			}

			$parent_id = $color_name_to_id[ $color_def['parent'] ] ?? '';

			if ( '' === $parent_id ) {
				continue;
			}

			do {
				$color_id = $id_generator->generate();
			} while ( in_array( $color_id, $existing_ids, true ) );
			$existing_ids[] = $color_id;

			$raw = ! empty( $color_def['raw'] )
				? $this->normalize_raw_css_variable( $color_def['raw'] )
				: $this->derive_css_variable_from_name( $color_def['name'] );

			$palette_colors[] = [
				'id'     => $color_id,
				'light'  => $hex,
				'raw'    => $raw,
				'type'   => 'custom',
				'parent' => $parent_id,
			];
		}

		$new_palette = [
			'id'     => $palette_id,
			'name'   => $sanitized_name,
			'colors' => $palette_colors,
		];

		$palettes[] = $new_palette;
		update_option( 'bricks_color_palette', $palettes );

		$css_regenerated = $this->core->regenerate_style_manager_css();

		return [
			'id'              => $palette_id,
			'name'            => $sanitized_name,
			'colors'          => $palette_colors,
			'color_count'     => count( $palette_colors ),
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Rename a color palette.
	 *
	 * @param string $palette_id Palette ID.
	 * @param string $name       New palette name.
	 * @return array<string, mixed>|\WP_Error Updated palette or WP_Error on failure.
	 */
	public function update_color_palette( string $palette_id, string $name ): array|\WP_Error {
		$sanitized_name = sanitize_text_field( $name );

		if ( '' === $sanitized_name ) {
			return new \WP_Error( 'missing_name', __( 'Palette name is required.', 'bricks-mcp' ) );
		}

		$palettes = get_option( 'bricks_color_palette', [] );
		if ( ! is_array( $palettes ) ) {
			$palettes = [];
		}

		$palette_index = null;
		foreach ( $palettes as $i => $p ) {
			if ( ( $p['id'] ?? '' ) === $palette_id ) {
				$palette_index = $i;
				break;
			}
		}

		if ( null === $palette_index ) {
			return new \WP_Error( 'not_found', sprintf( __( 'Palette "%s" not found. Use color_palette:list to discover available palette IDs.', 'bricks-mcp' ), $palette_id ) );
		}

		$palettes[ $palette_index ]['name'] = $sanitized_name;
		update_option( 'bricks_color_palette', $palettes );

		$css_regenerated = $this->core->regenerate_style_manager_css();

		return [
			'id'              => $palette_id,
			'name'            => $sanitized_name,
			'color_count'     => count( $palettes[ $palette_index ]['colors'] ?? [] ),
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Delete a color palette and all its colors permanently.
	 *
	 * @param string $palette_id Palette ID.
	 * @return array<string, mixed>|\WP_Error Deletion result or WP_Error on failure.
	 */
	public function delete_color_palette( string $palette_id ): array|\WP_Error {
		$palettes = get_option( 'bricks_color_palette', [] );
		if ( ! is_array( $palettes ) ) {
			$palettes = [];
		}

		$palette_index = null;
		$deleted_name  = '';
		$color_count   = 0;
		foreach ( $palettes as $i => $p ) {
			if ( ( $p['id'] ?? '' ) === $palette_id ) {
				$palette_index = $i;
				$deleted_name  = $p['name'] ?? '';
				$color_count   = count( $p['colors'] ?? [] );
				break;
			}
		}

		if ( null === $palette_index ) {
			return new \WP_Error( 'not_found', sprintf( __( 'Palette "%s" not found. Use color_palette:list to discover available palette IDs.', 'bricks-mcp' ), $palette_id ) );
		}

		array_splice( $palettes, $palette_index, 1 );
		update_option( 'bricks_color_palette', $palettes );

		$css_regenerated = $this->core->regenerate_style_manager_css();

		return [
			'action'          => 'deleted',
			'id'              => $palette_id,
			'name'            => $deleted_name,
			'colors_removed'  => $color_count,
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Add a color to an existing palette.
	 *
	 * @param string        $palette_id      Palette ID.
	 * @param string        $light           Hex color value.
	 * @param string        $name            Friendly color name.
	 * @param string        $raw             Optional CSS variable override.
	 * @param string        $parent          Optional parent color ID.
	 * @param array<string> $utility_classes Optional utility class types.
	 * @return array<string, mixed>|\WP_Error Created color or WP_Error on failure.
	 */
	public function add_color_to_palette( string $palette_id, string $light, string $name, string $raw = '', string $parent = '', array $utility_classes = [] ): array|\WP_Error {
		$hex = sanitize_hex_color( $light );
		if ( null === $hex ) {
			return new \WP_Error( 'invalid_hex', sprintf( __( 'Invalid hex color "%s". Provide a valid hex color (e.g., "#3498db" or "#fff").', 'bricks-mcp' ), $light ) );
		}

		$sanitized_name = sanitize_text_field( $name );
		if ( '' === $sanitized_name ) {
			return new \WP_Error( 'missing_name', __( 'Color name is required.', 'bricks-mcp' ) );
		}

		$palettes = get_option( 'bricks_color_palette', [] );
		if ( ! is_array( $palettes ) ) {
			$palettes = [];
		}

		$palette_index = null;
		foreach ( $palettes as $i => $p ) {
			if ( ( $p['id'] ?? '' ) === $palette_id ) {
				$palette_index = $i;
				break;
			}
		}

		if ( null === $palette_index ) {
			return new \WP_Error( 'not_found', sprintf( __( 'Palette "%s" not found. Use color_palette:list to discover available palette IDs.', 'bricks-mcp' ), $palette_id ) );
		}

		$id_generator       = new ElementIdGenerator();
		$existing_color_ids = array_column( $palettes[ $palette_index ]['colors'] ?? [], 'id' );

		do {
			$color_id = $id_generator->generate();
		} while ( in_array( $color_id, $existing_color_ids, true ) );

		$css_raw = '' !== $raw
			? $this->normalize_raw_css_variable( $raw )
			: $this->derive_css_variable_from_name( $sanitized_name );

		$color_obj = [
			'id'    => $color_id,
			'light' => $hex,
			'raw'   => $css_raw,
		];

		if ( '' !== $parent ) {
			$parent_found = false;
			foreach ( $palettes[ $palette_index ]['colors'] ?? [] as $c ) {
				if ( ( $c['id'] ?? '' ) === $parent ) {
					$parent_found = true;
					break;
				}
			}

			if ( ! $parent_found ) {
				return new \WP_Error( 'parent_not_found', sprintf( __( 'Parent color "%1$s" not found in palette "%2$s". Use color_palette:list to see existing color IDs.', 'bricks-mcp' ), $parent, $palette_id ) );
			}

			$color_obj['type']   = 'custom';
			$color_obj['parent'] = $parent;
		} elseif ( ! empty( $utility_classes ) ) {
			$color_obj['utilityClasses'] = array_values(
				array_intersect( $utility_classes, [ 'bg', 'text', 'border', 'outline', 'fill', 'stroke' ] )
			);
		}

		$palettes[ $palette_index ]['colors'][] = $color_obj;
		update_option( 'bricks_color_palette', $palettes );

		$css_regenerated = $this->core->regenerate_style_manager_css();

		return [
			'id'              => $color_id,
			'light'           => $hex,
			'raw'             => $css_raw,
			'palette_id'      => $palette_id,
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Update an existing color in a palette.
	 *
	 * @param string               $palette_id Palette ID.
	 * @param string               $color_id   Color ID.
	 * @param array<string, mixed> $fields     Fields to update.
	 * @return array<string, mixed>|\WP_Error Updated color or WP_Error on failure.
	 */
	public function update_color_in_palette( string $palette_id, string $color_id, array $fields ): array|\WP_Error {
		$palettes = get_option( 'bricks_color_palette', [] );
		if ( ! is_array( $palettes ) ) {
			$palettes = [];
		}

		$palette_index = null;
		foreach ( $palettes as $i => $p ) {
			if ( ( $p['id'] ?? '' ) === $palette_id ) {
				$palette_index = $i;
				break;
			}
		}

		if ( null === $palette_index ) {
			return new \WP_Error( 'not_found', sprintf( __( 'Palette "%s" not found. Use color_palette:list to discover available palette IDs.', 'bricks-mcp' ), $palette_id ) );
		}

		$color_index = null;
		foreach ( $palettes[ $palette_index ]['colors'] ?? [] as $ci => $c ) {
			if ( ( $c['id'] ?? '' ) === $color_id ) {
				$color_index = $ci;
				break;
			}
		}

		if ( null === $color_index ) {
			return new \WP_Error( 'color_not_found', sprintf( __( 'Color "%1$s" not found in palette "%2$s". Use color_palette:list to see existing color IDs.', 'bricks-mcp' ), $color_id, $palette_id ) );
		}

		$color = &$palettes[ $palette_index ]['colors'][ $color_index ];

		if ( isset( $fields['light'] ) ) {
			$hex = sanitize_hex_color( $fields['light'] );
			if ( null === $hex ) {
				return new \WP_Error( 'invalid_hex', sprintf( __( 'Invalid hex color "%s". Provide a valid hex color (e.g., "#3498db" or "#fff").', 'bricks-mcp' ), $fields['light'] ) );
			}
			$color['light'] = $hex;
		}

		if ( isset( $fields['name'] ) ) {
			if ( isset( $fields['raw'] ) && '' !== $fields['raw'] ) {
				$color['raw'] = $this->normalize_raw_css_variable( $fields['raw'] );
			} else {
				$color['raw'] = $this->derive_css_variable_from_name( $fields['name'] );
			}
		} elseif ( isset( $fields['raw'] ) && '' !== $fields['raw'] ) {
			$color['raw'] = $this->normalize_raw_css_variable( $fields['raw'] );
		}

		if ( array_key_exists( 'parent', $fields ) ) {
			if ( '' === $fields['parent'] || null === $fields['parent'] ) {
				unset( $color['type'], $color['parent'] );
			} else {
				$parent_found = false;
				foreach ( $palettes[ $palette_index ]['colors'] ?? [] as $c ) {
					if ( ( $c['id'] ?? '' ) === $fields['parent'] && $c['id'] !== $color_id ) {
						$parent_found = true;
						break;
					}
				}

				if ( ! $parent_found ) {
					return new \WP_Error( 'parent_not_found', sprintf( __( 'Parent color "%s" not found in this palette.', 'bricks-mcp' ), $fields['parent'] ) );
				}

				$color['type']   = 'custom';
				$color['parent'] = $fields['parent'];
			}
		}

		if ( array_key_exists( 'utilityClasses', $fields ) ) {
			if ( empty( $fields['utilityClasses'] ) ) {
				unset( $color['utilityClasses'] );
			} else {
				$color['utilityClasses'] = array_values(
					array_intersect( $fields['utilityClasses'], [ 'bg', 'text', 'border', 'outline', 'fill', 'stroke' ] )
				);
			}
		}

		unset( $color );

		update_option( 'bricks_color_palette', $palettes );

		$css_regenerated = $this->core->regenerate_style_manager_css();

		$updated_color = $palettes[ $palette_index ]['colors'][ $color_index ];

		return array_merge(
			$updated_color,
			[
				'palette_id'      => $palette_id,
				'css_regenerated' => $css_regenerated,
			]
		);
	}

	/**
	 * Delete a color from a palette permanently.
	 *
	 * @param string $palette_id Palette ID.
	 * @param string $color_id   Color ID.
	 * @return array<string, mixed>|\WP_Error Deletion result or WP_Error on failure.
	 */
	public function delete_color_from_palette( string $palette_id, string $color_id ): array|\WP_Error {
		$palettes = get_option( 'bricks_color_palette', [] );
		if ( ! is_array( $palettes ) ) {
			$palettes = [];
		}

		$palette_index = null;
		foreach ( $palettes as $i => $p ) {
			if ( ( $p['id'] ?? '' ) === $palette_id ) {
				$palette_index = $i;
				break;
			}
		}

		if ( null === $palette_index ) {
			return new \WP_Error( 'not_found', sprintf( __( 'Palette "%s" not found. Use color_palette:list to discover available palette IDs.', 'bricks-mcp' ), $palette_id ) );
		}

		$colors      = $palettes[ $palette_index ]['colors'] ?? [];
		$color_found = false;
		$deleted_raw = '';

		foreach ( $colors as $c ) {
			if ( ( $c['id'] ?? '' ) === $color_id ) {
				$color_found = true;
				$deleted_raw = $c['raw'] ?? '';
				break;
			}
		}

		if ( ! $color_found ) {
			return new \WP_Error( 'color_not_found', sprintf( __( 'Color "%1$s" not found in palette "%2$s". Use color_palette:list to see existing color IDs.', 'bricks-mcp' ), $color_id, $palette_id ) );
		}

		$children_removed = 0;
		$ids_to_remove    = [ $color_id ];

		foreach ( $colors as $c ) {
			if ( ( $c['parent'] ?? '' ) === $color_id ) {
				$ids_to_remove[] = $c['id'] ?? '';
				++$children_removed;
			}
		}

		$palettes[ $palette_index ]['colors'] = array_values(
			array_filter(
				$colors,
				static fn( array $c ) => ! in_array( $c['id'] ?? '', $ids_to_remove, true )
			)
		);

		update_option( 'bricks_color_palette', $palettes );

		$css_regenerated = $this->core->regenerate_style_manager_css();

		return [
			'action'           => 'deleted',
			'id'               => $color_id,
			'raw'              => $deleted_raw,
			'palette_id'       => $palette_id,
			'children_removed' => $children_removed,
			'css_regenerated'  => $css_regenerated,
		];
	}
}
