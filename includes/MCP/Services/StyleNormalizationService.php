<?php
/**
 * Bricks style normalization helpers.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes AI-generated Bricks style arrays before they reach storage.
 */
final class StyleNormalizationService {

	/**
	 * Common typography keys that models emit in camelCase even though Bricks
	 * expects kebab-case inside the _typography group.
	 *
	 * @var array<string, string>
	 */
	private const TYPOGRAPHY_KEY_MAP = [
		'fontSize'       => 'font-size',
		'fontWeight'     => 'font-weight',
		'fontFamily'     => 'font-family',
		'lineHeight'     => 'line-height',
		'letterSpacing'  => 'letter-spacing',
		'textAlign'      => 'text-align',
		'textTransform'  => 'text-transform',
		'textDecoration' => 'text-decoration',
	];

	/**
	 * Normalize a Bricks style/settings array and return warnings for repairs.
	 *
	 * @param array<string, mixed> $styles Style/settings array.
	 * @return array{styles: array<string, mixed>, warnings: array<string>}
	 */
	public static function normalize( array $styles ): array {
		$warnings = [];
		$styles   = self::normalize_node( $styles, '', $warnings );
		self::collect_variable_warnings( $styles, '', $warnings );

		return [
			'styles'   => $styles,
			'warnings' => array_values( array_unique( $warnings ) ),
		];
	}

	/**
	 * Normalize a node in the style tree.
	 *
	 * @param array<string, mixed> $node     Current style node.
	 * @param string               $path     Dot path used in warnings.
	 * @param array<int, string>   $warnings Warning accumulator.
	 * @return array<string, mixed>
	 */
	private static function normalize_node( array $node, string $path, array &$warnings ): array {
		$node = self::normalize_shape_rules( $node, $path, $warnings );

		foreach ( $node as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			$key_string = (string) $key;
			$child_path = '' === $path ? $key_string : $path . '.' . $key_string;

			if ( self::is_typography_group( $key_string ) ) {
				$value = self::normalize_typography_keys( $value, $child_path, $warnings );
			}
			if ( self::is_color_group( $key_string ) && isset( $value['color'] ) && is_string( $value['color'] ) ) {
				$value['color'] = self::wrap_color_value( $value['color'] );
				$warnings[] = $child_path . '.color was a string; wrapped in a Bricks color object.';
			}
			if ( self::is_border_group( $key_string ) ) {
				$value = self::normalize_border_group( $value, $child_path, $warnings );
			}

			if ( ! self::is_color_object( $value ) ) {
				$value = self::normalize_node( $value, $child_path, $warnings );
			}

			$node[ $key ] = $value;
		}

		return $node;
	}

	/**
	 * Apply known Bricks shape repairs at the current node.
	 *
	 * @param array<string, mixed> $node     Current style node.
	 * @param string               $path     Dot path used in warnings.
	 * @param array<int, string>   $warnings Warning accumulator.
	 * @return array<string, mixed>
	 */
	private static function normalize_shape_rules( array $node, string $path, array &$warnings ): array {
		$prefix = '' === $path ? '' : $path . '.';

		if ( isset( $node['_border']['style'] ) && is_array( $node['_border']['style'] ) ) {
			$style_array = $node['_border']['style'];
			$first_value = 'solid';
			foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
				if ( is_string( $style_array[ $side ] ?? null ) && '' !== $style_array[ $side ] ) {
					$first_value = $style_array[ $side ];
					break;
				}
			}
			$node['_border']['style'] = $first_value;
			$warnings[] = $prefix . '_border.style was an object; converted to "' . $first_value . '". Bricks expects a single string.';
		}

		if ( isset( $node['_border']['color'] ) && is_string( $node['_border']['color'] ) ) {
			$node['_border']['color'] = self::wrap_color_value( $node['_border']['color'] );
			$warnings[] = $prefix . '_border.color was a string; wrapped in a Bricks color object.';
		}

		if ( isset( $node['_border']['width'] ) && is_string( $node['_border']['width'] ) ) {
			$width = $node['_border']['width'];
			$node['_border']['width'] = [
				'top'    => $width,
				'right'  => $width,
				'bottom' => $width,
				'left'   => $width,
			];
			$warnings[] = $prefix . '_border.width was a flat string; expanded to per-side values.';
		}

		if ( isset( $node['_cssCustom'] ) && is_array( $node['_cssCustom'] ) ) {
			$node['_cssCustom'] = implode( "\n", array_filter( $node['_cssCustom'], 'is_string' ) );
			$warnings[] = $prefix . '_cssCustom was an array; joined to a CSS string.';
		}

		if ( isset( $node['_typography']['color'] ) && is_string( $node['_typography']['color'] ) ) {
			$node['_typography']['color'] = self::wrap_color_value( $node['_typography']['color'] );
			$warnings[] = $prefix . '_typography.color was a string; wrapped in a Bricks color object.';
		}

		if ( isset( $node['_background']['color'] ) && is_string( $node['_background']['color'] ) ) {
			$node['_background']['color'] = self::wrap_color_value( $node['_background']['color'] );
			$warnings[] = $prefix . '_background.color was a string; wrapped in a Bricks color object.';
		}

		// v3.33.7: legacy wrong-shape `_background: {backgroundColor: "var(...)"}` — vision v3.32
		// era emitted this. Bricks CSS compiler expects `_background.color.raw`. Rewrite.
		if ( isset( $node['_background']['backgroundColor'] ) && is_string( $node['_background']['backgroundColor'] ) ) {
			$bg = $node['_background']['backgroundColor'];
			if ( ! isset( $node['_background']['color'] ) ) {
				$node['_background']['color'] = self::wrap_color_value( $bg );
				$warnings[] = $prefix . '_background.backgroundColor was relocated to _background.color (object shape). Bricks CSS compiler does not emit _background.backgroundColor.';
			}
			unset( $node['_background']['backgroundColor'] );
		}

		// v3.33.7: legacy scalar `_border.radius: "var(...)"` — Bricks CSS compiler expects
		// per-side object {top, right, bottom, left}. Expand.
		if ( isset( $node['_border']['radius'] ) && is_string( $node['_border']['radius'] ) ) {
			$radius = $node['_border']['radius'];
			$node['_border']['radius'] = [
				'top'    => $radius,
				'right'  => $radius,
				'bottom' => $radius,
				'left'   => $radius,
			];
			$warnings[] = $prefix . '_border.radius was a flat string; expanded to per-side values. Bricks CSS compiler ignores scalar _border.radius.';
		}

		if ( isset( $node['_color'] ) && is_string( $node['_color'] ) ) {
			$node['_color'] = self::wrap_color_value( $node['_color'] );
			$warnings[] = $prefix . '_color was a string; wrapped in a Bricks color object.';
		}

		return $node;
	}

	/**
	 * v3.33.7 one-shot migration: walk every global class in the DB, re-normalize its
	 * stored styles through `normalize()` so pre-existing legacy shapes (camelCase
	 * typography, scalar `_border.radius`, `_background.backgroundColor`) get rewritten
	 * to the shapes Bricks' CSS compiler actually emits. Idempotent — calling it on
	 * already-clean classes is a no-op.
	 *
	 * Triggered by Plugin bootstrap under a one-shot option flag so it runs once per
	 * install and never blocks admin page loads after the first hit.
	 *
	 * @return array{scanned:int, rewritten:int, warnings_total:int}
	 */
	public static function migrate_existing_classes(): array {
		$option_key = defined( 'BRICKS_MCP_OPTION_GLOBAL_CLASSES_KEY' )
			? BRICKS_MCP_OPTION_GLOBAL_CLASSES_KEY
			: 'bricks_global_classes';
		$classes = get_option( $option_key, [] );
		if ( ! is_array( $classes ) ) {
			return [ 'scanned' => 0, 'rewritten' => 0, 'warnings_total' => 0 ];
		}

		$scanned    = 0;
		$rewritten  = 0;
		$warnings_total = 0;

		foreach ( $classes as &$class ) {
			if ( ! is_array( $class ) ) {
				continue;
			}
			$scanned++;
			$original = $class['settings'] ?? $class['styles'] ?? [];
			if ( ! is_array( $original ) || $original === [] ) {
				continue;
			}
			$result = self::normalize( $original );
			$new    = is_array( $result['styles'] ?? null ) ? $result['styles'] : $result;
			$warn   = is_array( $result['warnings'] ?? null ) ? $result['warnings'] : [];
			$warnings_total += count( $warn );
			if ( $new !== $original ) {
				$class['settings'] = $new;
				unset( $class['styles'] ); // canonicalize on settings key
				$rewritten++;
			}
		}
		unset( $class );

		if ( $rewritten > 0 ) {
			update_option( $option_key, $classes, false );
		}

		return [
			'scanned'        => $scanned,
			'rewritten'      => $rewritten,
			'warnings_total' => $warnings_total,
		];
	}

	/**
	 * Convert camelCase typography keys to Bricks' kebab-case settings keys.
	 *
	 * @param array<string, mixed> $typography Typography group.
	 * @param string               $path       Dot path used in warnings.
	 * @param array<int, string>   $warnings   Warning accumulator.
	 * @return array<string, mixed>
	 */
	private static function normalize_typography_keys( array $typography, string $path, array &$warnings ): array {
		if ( isset( $typography['color'] ) && is_string( $typography['color'] ) ) {
			$typography['color'] = self::wrap_color_value( $typography['color'] );
			$warnings[] = $path . '.color was a string; wrapped in a Bricks color object.';
		}

		foreach ( self::TYPOGRAPHY_KEY_MAP as $from => $to ) {
			if ( ! array_key_exists( $from, $typography ) ) {
				continue;
			}

			if ( ! array_key_exists( $to, $typography ) ) {
				$typography[ $to ] = $typography[ $from ];
				$warnings[] = $path . '.' . $from . ' was converted to ' . $path . '.' . $to . '. Bricks ignores camelCase typography keys.';
			} else {
				$warnings[] = $path . '.' . $from . ' was removed because ' . $path . '.' . $to . ' already exists. Bricks ignores camelCase typography keys.';
			}

			unset( $typography[ $from ] );
		}

		return $typography;
	}

	/**
	 * Normalize a direct _border or _border:* group.
	 *
	 * @param array<string, mixed> $border   Border group.
	 * @param string               $path     Dot path used in warnings.
	 * @param array<int, string>   $warnings Warning accumulator.
	 * @return array<string, mixed>
	 */
	private static function normalize_border_group( array $border, string $path, array &$warnings ): array {
		if ( isset( $border['style'] ) && is_array( $border['style'] ) ) {
			$style_array = $border['style'];
			$first_value = 'solid';
			foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
				if ( is_string( $style_array[ $side ] ?? null ) && '' !== $style_array[ $side ] ) {
					$first_value = $style_array[ $side ];
					break;
				}
			}
			$border['style'] = $first_value;
			$warnings[] = $path . '.style was an object; converted to "' . $first_value . '". Bricks expects a single string.';
		}

		if ( isset( $border['color'] ) && is_string( $border['color'] ) ) {
			$border['color'] = self::wrap_color_value( $border['color'] );
			$warnings[] = $path . '.color was a string; wrapped in a Bricks color object.';
		}

		if ( isset( $border['width'] ) && is_string( $border['width'] ) ) {
			$width = $border['width'];
			$border['width'] = [
				'top'    => $width,
				'right'  => $width,
				'bottom' => $width,
				'left'   => $width,
			];
			$warnings[] = $path . '.width was a flat string; expanded to per-side values.';
		}

		return $border;
	}

	/**
	 * Collect warnings for CSS variable references that do not exist in Bricks.
	 *
	 * @param mixed              $value    Current value.
	 * @param string             $path     Dot path used in warnings.
	 * @param array<int, string> $warnings Warning accumulator.
	 * @return void
	 */
	private static function collect_variable_warnings( mixed $value, string $path, array &$warnings ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$key_string = (string) $key;
				$child_path = '' === $path ? $key_string : $path . '.' . $key_string;

				if ( '_cssCustom' === $key_string ) {
					continue;
				}

				self::collect_variable_warnings( $child, $child_path, $warnings );
			}
			return;
		}

		if ( ! is_string( $value ) || ! SiteVariableResolver::has_variables() ) {
			return;
		}

		if ( ! preg_match_all( '/var\(\s*--([a-zA-Z0-9_-]+)(\s*,[^)]*)?\)/', $value, $matches, PREG_SET_ORDER ) ) {
			return;
		}

		foreach ( $matches as $match ) {
			$name = $match[1] ?? '';
			if ( '' === $name || SiteVariableResolver::exists( $name ) ) {
				continue;
			}

			$ref = 'var(--' . $name . ')';
			if ( str_starts_with( $name, 'brxw-' ) ) {
				$warnings[] = $path . ' references foreign variable ' . $ref . '. Translate it to an existing Bricks site variable before building.';
				continue;
			}

			$fallback_note = isset( $match[2] ) && '' !== trim( $match[2] )
				? ' The CSS fallback may render, but the Bricks design token is missing.'
				: ' Add the variable or replace it with an existing Bricks design token.';
			$warnings[] = $path . ' references missing Bricks variable ' . $ref . '.' . $fallback_note;
		}
	}

	/**
	 * Whether a style key is a typography settings group.
	 */
	private static function is_typography_group( string $key ): bool {
		return '_typography' === $key || str_starts_with( $key, '_typography:' );
	}

	/**
	 * Whether a style key is a color-bearing group.
	 */
	private static function is_color_group( string $key ): bool {
		return '_background' === $key || str_starts_with( $key, '_background:' );
	}

	/**
	 * Whether a style key is a border settings group.
	 */
	private static function is_border_group( string $key ): bool {
		return '_border' === $key || str_starts_with( $key, '_border:' );
	}

	/**
	 * Whether an array is a Bricks color object.
	 *
	 * @param array<string, mixed> $value Candidate value.
	 */
	private static function is_color_object( array $value ): bool {
		return isset( $value['raw'] ) || isset( $value['hex'] ) || isset( $value['rgb'] );
	}

	/**
	 * Wrap a color string in Bricks' expected {raw|hex} object format.
	 *
	 * @return array<string, string>
	 */
	private static function wrap_color_value( string $value ): array {
		if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ) {
			return [ 'hex' => $value ];
		}
		return [ 'raw' => $value ];
	}
}
