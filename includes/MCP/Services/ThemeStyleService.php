<?php
/**
 * Theme style management sub-service.
 *
 * Handles CRUD for Bricks global theme styles with condition-based activation.
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
 * ThemeStyleService class.
 */
class ThemeStyleService {

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
	 * Get all theme styles.
	 *
	 * @return array<int, array<string, mixed>> All theme styles formatted for response.
	 */
	public function get_theme_styles(): array {
		$styles = get_option( 'bricks_theme_styles', [] );

		if ( ! is_array( $styles ) ) {
			return [];
		}

		$result = [];

		foreach ( $styles as $style_id => $style ) {
			$result[] = $this->format_theme_style_response( (string) $style_id, $style );
		}

		return $result;
	}

	/**
	 * Get a single theme style by ID.
	 *
	 * @param string $style_id Theme style ID.
	 * @return array<string, mixed>|\WP_Error Formatted theme style or WP_Error if not found.
	 */
	public function get_theme_style( string $style_id ): array|\WP_Error {
		$styles = get_option( 'bricks_theme_styles', [] );

		if ( ! is_array( $styles ) ) {
			$styles = [];
		}

		if ( ! isset( $styles[ $style_id ] ) ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Style ID */
					__( 'Theme style "%s" not found. Use list_theme_styles to discover available style IDs.', 'bricks-mcp' ),
					$style_id
				)
			);
		}

		return $this->format_theme_style_response( $style_id, $styles[ $style_id ] );
	}

	/**
	 * Create a new global theme style.
	 *
	 * Generates a collision-free ID, builds the style entry with label,
	 * optional settings, and optional conditions, then writes to the option.
	 *
	 * @param string               $label      Style label (required).
	 * @param array<string, mixed> $settings   Settings organized by group (optional).
	 * @param array<int, mixed>    $conditions Condition objects (optional).
	 * @return array<string, mixed>|\WP_Error Created style or WP_Error on failure.
	 */
	public function create_theme_style( string $label, array $settings = [], array $conditions = [] ): array|\WP_Error {
		$sanitized_label = sanitize_text_field( $label );

		if ( '' === $sanitized_label ) {
			return new \WP_Error(
				'missing_label',
				__( 'Theme style label is required. Provide a non-empty "label" parameter.', 'bricks-mcp' )
			);
		}

		$styles = get_option( 'bricks_theme_styles', [] );

		if ( ! is_array( $styles ) ) {
			$styles = [];
		}

		// Generate collision-free ID.
		$id_generator = new ElementIdGenerator();
		$existing_ids = array_keys( $styles );
		do {
			$new_id = $id_generator->generate();
		} while ( in_array( $new_id, $existing_ids, true ) );

		// Build style entry — sanitize settings to prevent CSS injection.
		$style = [
			'label'    => $sanitized_label,
			'settings' => ! empty( $settings ) ? $this->core->sanitize_styles_array( $settings ) : [],
		];

		// Add conditions if provided.
		if ( ! empty( $conditions ) ) {
			$style['settings']['conditions'] = [ 'conditions' => $conditions ];
		}

		$styles[ $new_id ] = $style;
		update_option( 'bricks_theme_styles', $styles );

		return $this->format_theme_style_response( $new_id, $styles[ $new_id ] );
	}

	/**
	 * Deep merge two style arrays recursively.
	 *
	 * Preserves nested structure when merging Bricks composite key styles.
	 * For example, merging {"_fontSize": "18px"} into
	 * {"_fontFamily": "Inter", "_fontWeight": "400"} will result in
	 * {"_fontFamily": "Inter", "_fontWeight": "400", "_fontSize": "18px"}.
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
	 * Update an existing theme style with partial merge support.
	 *
	 * Supports deep-merge (default) or section-replace for settings groups.
	 * Returns before/after snapshots and site-wide active detection.
	 *
	 * @param string                    $style_id        Style ID.
	 * @param string|null               $label           New label (null to skip).
	 * @param array<string, mixed>|null $settings        Settings groups to update (null to skip).
	 * @param array<int, mixed>|null    $conditions      Replacement conditions (null to skip, empty array to clear).
	 * @param bool                      $replace_section If true, replace entire group instead of merging.
	 * @return array<string, mixed>|\WP_Error Update result or WP_Error on failure.
	 */
	public function update_theme_style( string $style_id, ?string $label = null, ?array $settings = null, ?array $conditions = null, bool $replace_section = false ): array|\WP_Error {
		$styles = get_option( 'bricks_theme_styles', [] );

		if ( ! is_array( $styles ) ) {
			$styles = [];
		}

		if ( ! isset( $styles[ $style_id ] ) ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Style ID */
					__( 'Theme style "%s" not found. Use list_theme_styles to discover available style IDs.', 'bricks-mcp' ),
					$style_id
				)
			);
		}

		// Capture before snapshot.
		$before = $styles[ $style_id ]['settings'];

		// Update label if provided.
		if ( null !== $label ) {
			$styles[ $style_id ]['label'] = sanitize_text_field( $label );
		}

		// Update settings if provided — sanitize to prevent CSS injection.
		if ( null !== $settings ) {
			$sanitized_settings = $this->core->sanitize_styles_array( $settings );
			foreach ( $sanitized_settings as $group_key => $group_settings ) {
				// Skip conditions key — handled separately below.
				if ( 'conditions' === $group_key ) {
					continue;
				}

				if ( $replace_section || ! isset( $styles[ $style_id ]['settings'][ $group_key ] ) ) {
					// Replace the entire group or create new group.
					$styles[ $style_id ]['settings'][ $group_key ] = $group_settings;
				} else {
					// Deep merge: recursively merge nested style properties.
					$styles[ $style_id ]['settings'][ $group_key ] = $this->deep_merge_styles(
						$styles[ $style_id ]['settings'][ $group_key ],
						$group_settings
					);
				}
			}
		}

		// Update conditions if provided (including empty array to clear).
		if ( null !== $conditions ) {
			$styles[ $style_id ]['settings']['conditions'] = [ 'conditions' => $conditions ];
		}

		update_option( 'bricks_theme_styles', $styles );

		// Capture after snapshot.
		$after = $styles[ $style_id ]['settings'];

		// Detect site-wide active status.
		$style_conditions = $styles[ $style_id ]['settings']['conditions']['conditions'] ?? [];
		$is_sitewide      = ! empty(
			array_filter(
				$style_conditions,
				static fn( $c ) => ( $c['main'] ?? '' ) === 'any'
			)
		);

		return [
			'style'              => $this->format_theme_style_response( $style_id, $styles[ $style_id ] ),
			'before'             => $before,
			'after'              => $after,
			'changed_groups'     => array_values( array_unique( array_merge(
				// Groups whose settings differ between before and after.
				array_keys( array_filter(
					array_diff_key( $after, [ 'conditions' => true ] ),
					static fn( $val, $key ) => ! isset( $before[ $key ] ) || $before[ $key ] !== $val,
					ARRAY_FILTER_USE_BOTH
				) ),
				// Groups that existed before but were removed.
				array_keys( array_diff_key(
					array_diff_key( $before, [ 'conditions' => true ] ),
					$after
				) )
			) ) ),
			'is_sitewide_active' => $is_sitewide,
		];
	}

	/**
	 * Delete or deactivate a theme style.
	 *
	 * By default, deactivates by clearing conditions (soft delete).
	 * Set hard_delete to true to permanently remove the style.
	 *
	 * @param string $style_id    Style ID.
	 * @param bool   $hard_delete Whether to permanently delete (default: false).
	 * @return array<string, mixed>|\WP_Error Deletion result or WP_Error on failure.
	 */
	public function delete_theme_style( string $style_id, bool $hard_delete = false ): array|\WP_Error {
		$styles = get_option( 'bricks_theme_styles', [] );

		if ( ! is_array( $styles ) ) {
			$styles = [];
		}

		if ( ! isset( $styles[ $style_id ] ) ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Style ID */
					__( 'Theme style "%s" not found. Use list_theme_styles to discover available style IDs.', 'bricks-mcp' ),
					$style_id
				)
			);
		}

		if ( $hard_delete ) {
			unset( $styles[ $style_id ] );
			update_option( 'bricks_theme_styles', $styles );

			return [
				'action'   => 'deleted',
				'style_id' => $style_id,
			];
		}

		// Soft delete: clear conditions only.
		$styles[ $style_id ]['settings']['conditions'] = [ 'conditions' => [] ];
		update_option( 'bricks_theme_styles', $styles );

		return [
			'action'   => 'deactivated',
			'style_id' => $style_id,
		];
	}

	/**
	 * Format a theme style for API response.
	 *
	 * Extracts conditions, detects site-wide active status, lists settings groups.
	 *
	 * @param string               $style_id Style ID.
	 * @param array<string, mixed> $style    Raw style data.
	 * @return array<string, mixed> Formatted theme style response.
	 */
	private function format_theme_style_response( string $style_id, array $style ): array {
		$conditions = $style['settings']['conditions']['conditions'] ?? [];
		$is_active  = ! empty(
			array_filter(
				$conditions,
				static fn( $c ) => ( $c['main'] ?? '' ) === 'any'
			)
		);

		// List settings groups, excluding the 'conditions' metadata key.
		$settings_groups = array_values(
			array_filter(
				array_keys( $style['settings'] ?? [] ),
				static fn( string $key ) => 'conditions' !== $key
			)
		);

		return [
			'id'              => $style_id,
			'label'           => $style['label'] ?? '',
			'conditions'      => $conditions,
			'is_active'       => $is_active,
			'settings_groups' => $settings_groups,
			'settings'        => $style['settings'] ?? [],
		];
	}
}
