<?php
/**
 * Bricks element normalizer.
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
 * ElementNormalizer class.
 */
class ElementNormalizer {

	/**
	 * HTML content settings keys (sanitized with wp_kses_post).
	 * @var array<int, string>
	 */
	private const HTML_SETTINGS_KEYS = [
		'text',
		'content',
		'html',
		'innerHtml',
		'body',
		'excerpt',
		'description',
		'label',
		'caption',
	];

	/**
	 * CSS code block keys (raw CSS - preserve newlines, braces, combinators).
	 * Use wp_strip_all_tags() only - never sanitize_text_field() or wp_kses_post().
	 * @var array<int, string>
	 */
	private const CSS_CODE_KEYS = [
		'_cssCustom',
		'cssCode',
		'customCss',
		'css',
	];

	/**
	 * Raw code keys (HTML/SVG/JS for Code element, SVG element, etc.).
	 * Preserved as-is — security boundary is the authenticated MCP API layer.
	 * @var array<int, string>
	 */
	private const RAW_CODE_KEYS = [
		'code',
	];

	/**
	 * Invalid Bricks key corrections. null = drop the key entirely.
	 * @var array<string, string|null>
	 */
	private const KEY_CORRECTIONS = [
		'_maxWidth'  => '_widthMax',
		'_textAlign' => null,
	];

	/**
	 * Element ID generator instance.
	 * @var ElementIdGenerator
	 */
	private ElementIdGenerator $id_generator;

	public function __construct( ElementIdGenerator $id_generator ) {
		$this->id_generator = $id_generator;
	}

	/**
	 * Normalize element input to native Bricks flat array format.
	 */
	public function normalize( array $input, array $existing_elements = [] ): array {
		if ( empty( $input ) ) {
			return [];
		}
		if ( $this->is_flat_format( $input ) ) {
			return $this->normalize_flat_elements( $input );
		}
		// Flat array with parent ID references (has 'id' + 'parent' but not 'children' ID arrays).
		if ( $this->is_parent_ref_format( $input ) ) {
			$tree = $this->parent_refs_to_tree( $input );
			return $this->simplified_to_flat( $tree, $existing_elements );
		}
		return $this->simplified_to_flat( $input, $existing_elements );
	}

	/**
	 * Apply key corrections, sanitization, and %root% replacement to flat-format elements.
	 *
	 * Flat-format elements were previously returned as-is, skipping all normalization.
	 * This ensures they receive the same processing as simplified-format elements.
	 *
	 * @param array<int, array<string, mixed>> $elements Flat array of elements.
	 * @return array<int, array<string, mixed>> Processed flat array.
	 */
	private function normalize_flat_elements( array $elements ): array {
		foreach ( $elements as $index => $element ) {
			if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) || empty( $element['settings'] ) ) {
				continue;
			}

			$name     = $element['name'] ?? 'div';
			$settings = $this->apply_key_corrections( $element['settings'], $name );
			$settings = $this->sanitize_settings( $settings, $name );

			// Replace %root% shorthand with actual element selector.
			if ( isset( $settings['_cssCustom'] ) && is_string( $settings['_cssCustom'] ) && str_contains( $settings['_cssCustom'], '%root%' ) ) {
				$settings['_cssCustom'] = str_replace( '%root%', '#brxe-' . $element['id'], $settings['_cssCustom'] );
			}

			$elements[ $index ]['settings'] = $settings;
		}

		return $elements;
	}

	/**
	 * Detect native Bricks flat array format.
	 */
	public function is_flat_format( array $elements ): bool {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				return false;
			}
			if (
				! array_key_exists( 'id', $element ) ||
				! array_key_exists( 'parent', $element ) ||
				! array_key_exists( 'children', $element )
			) {
				return false;
			}

			// Children must contain scalar IDs, not nested element objects.
			if ( ! empty( $element['children'] ) && isset( $element['children'][0] ) && is_array( $element['children'][0] ) ) {
				return false; // children contains objects, not IDs -- this is tree format.
			}
		}
		return true;
	}

	/**
	 * Detect parent-reference flat format: elements have 'id' and 'parent' (as string refs)
	 * but not 'children' as ID arrays.
	 */
	private function is_parent_ref_format( array $elements ): bool {
		$ids = [];
		foreach ( $elements as $el ) {
			if ( is_array( $el ) && isset( $el['id'] ) ) {
				$ids[] = (string) $el['id'];
			}
		}
		if ( empty( $ids ) ) {
			return false;
		}
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) || ! isset( $el['parent'] ) ) {
				continue;
			}
			$parent = (string) $el['parent'];
			if ( '' !== $parent && '0' !== $parent && in_array( $parent, $ids, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Convert parent-reference flat array to nested tree format for simplified_to_flat().
	 */
	private function parent_refs_to_tree( array $elements ): array {
		// Index elements by their provided ID.
		$by_id = [];
		foreach ( $elements as $idx => $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$id = (string) ( $el['id'] ?? 'auto_' . $idx );
			$el['_orig_id'] = $id;
			// Reset children to build from parent refs.
			$el['children'] = $el['children'] ?? [];
			// Only keep children if they're nested element objects (not ID strings).
			if ( ! empty( $el['children'] ) && isset( $el['children'][0] ) && ! is_array( $el['children'][0] ) ) {
				$el['children'] = [];
			}
			$by_id[ $id ]   = $el;
		}

		$tree = [];
		foreach ( $by_id as $id => &$el ) {
			$parent = isset( $el['parent'] ) ? (string) $el['parent'] : '0';
			// Remove flat-format keys before passing to tree converter.
			unset( $el['id'], $el['parent'], $el['_orig_id'] );

			if ( '0' === $parent || '' === $parent || ! isset( $by_id[ $parent ] ) ) {
				$tree[] = &$el;
			} else {
				$by_id[ $parent ]['children'][] = &$el;
			}
		}
		unset( $el );

		return $tree;
	}

	/**
	 * Convert simplified nested format to Bricks native flat array.
	 */
	public function simplified_to_flat( array $tree, array $existing_elements, int|string $parent_id = 0 ): array {
		$flat = [];

		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$name     = $node['name'] ?? 'div';
			$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
			$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];

			$all_existing = array_merge( $existing_elements, $flat );
			$element_id   = $this->id_generator->generate_unique( $all_existing );

			// Apply key corrections before sanitization.
			$settings = $this->apply_key_corrections( $settings, $name );

			// Sanitize with Bricks-aware strategy.
			$sanitized_settings = $this->sanitize_settings( $settings, $name );

			// Replace %root% shorthand with actual element selector.
			if ( isset( $sanitized_settings['_cssCustom'] ) && is_string( $sanitized_settings['_cssCustom'] ) && str_contains( $sanitized_settings['_cssCustom'], '%root%' ) ) {
				$sanitized_settings['_cssCustom'] = str_replace( '%root%', '#brxe-' . $element_id, $sanitized_settings['_cssCustom'] );
			}

			$child_flat   = $this->simplified_to_flat( $children, array_merge( $all_existing, [ [ 'id' => $element_id ] ] ), $element_id );
			$children_ids = array_map(
				static fn( array $el ) => $el['id'],
				array_filter(
					$child_flat,
					static fn( array $el ) => (string) $el['parent'] === (string) $element_id
				)
			);

			// Use explicit parent from node if provided (flat-style input within tree format),
			// otherwise use the recursive parent_id.
			$effective_parent = isset( $node['parent'] ) ? $node['parent'] : $parent_id;

			$element = [
				'id'       => $element_id,
				'name'     => sanitize_text_field( $name ),
				'parent'   => $effective_parent,
				'children' => array_values( $children_ids ),
				'settings' => $sanitized_settings,
			];

			$flat[] = $element;
			foreach ( $child_flat as $child_element ) {
				$flat[] = $child_element;
			}
		}

		return $flat;
	}

	/**
	 * Apply key corrections: rename invalid keys, drop null-mapped keys,
	 * and hoist misplaced nested properties to the correct level.
	 */
	public function apply_key_corrections( array $settings, string $element_name = '' ): array {
		$corrected = [];

		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) ) {
				$corrected[ $key ] = $value;
				continue;
			}

			$base_key = explode( ':', $key )[0];

			if ( array_key_exists( $base_key, self::KEY_CORRECTIONS ) ) {
				$replacement = self::KEY_CORRECTIONS[ $base_key ];
				if ( null === $replacement ) {
					continue; // Drop invalid key.
				}
				$suffix                              = substr( $key, strlen( $base_key ) );
				$corrected[ $replacement . $suffix ] = $value;
				continue;
			}

			$corrected[ $key ] = $value;
		}

		// Hoist _gradient out of _background — _gradient is a top-level style key in Bricks.
		$corrected = $this->hoist_misplaced_gradient( $corrected );

		// Auto-inject _display: flex on div elements when _direction is set.
		// Block/container default to display:flex in Bricks, but div does not.
		// Without this, _direction: row on a div silently does nothing.
		if ( 'div' === $element_name && isset( $corrected['_direction'] ) && ! isset( $corrected['_display'] ) ) {
			$corrected['_display'] = 'flex';
		}

		return $corrected;
	}

	/**
	 * Hoist _gradient nested inside _background to a top-level settings key.
	 *
	 * AI clients commonly nest gradients as _background._gradient, but Bricks
	 * stores _gradient as a separate top-level style property. This auto-corrects
	 * the mistake so gradients render properly.
	 */
	private function hoist_misplaced_gradient( array $settings ): array {
		if ( ! isset( $settings['_background'] ) || ! is_array( $settings['_background'] ) ) {
			return $settings;
		}

		$bg = $settings['_background'];

		if ( ! isset( $bg['_gradient'] ) && ! isset( $bg['gradient'] ) ) {
			return $settings;
		}

		// Extract the gradient (try both _gradient and gradient keys).
		$gradient_data = $bg['_gradient'] ?? $bg['gradient'];
		unset( $bg['_gradient'], $bg['gradient'] );

		// Only hoist if _gradient isn't already set at top level.
		if ( ! isset( $settings['_gradient'] ) && is_array( $gradient_data ) ) {
			$settings['_gradient'] = $gradient_data;
		}

		// Clean up _background: if empty after removing gradient, drop it.
		if ( empty( $bg ) ) {
			unset( $settings['_background'] );
		} else {
			$settings['_background'] = $bg;
		}

		return $settings;
	}

	/**
	 * Sanitize element settings with Bricks-aware type detection.
	 *
	 * Strategy:
	 * 1. CSS code keys (_cssCustom, cssCode) -> wp_strip_all_tags() only.
	 * 2. Bricks style keys (underscore prefix: _padding, _background, etc.) -> recurse with sanitize_style_value().
	 * 3. HTML content keys (text, html, label, etc.) -> wp_kses_post().
	 * 4. All other strings -> sanitize_text_field().
	 */
	public function sanitize_settings( array $settings, string $element_name = '' ): array {
		$sanitized = [];

		foreach ( $settings as $key => $value ) {
			// Numeric keys (repeater items, list entries, etc.): recurse or preserve.
			if ( ! is_string( $key ) ) {
				if ( is_array( $value ) ) {
					$sanitized[ $key ] = $this->sanitize_settings( $value, $element_name );
				} elseif ( is_string( $value ) ) {
					$sanitized[ $key ] = sanitize_text_field( $value );
				} else {
					$sanitized[ $key ] = $value;
				}
				continue;
			}

			$base_key    = explode( ':', $key )[0];
			$is_css_key  = $this->is_css_style_key( $base_key );
			$is_css_code = $this->is_css_code_key( $base_key );

			// Raw code blocks (Code element, SVG element): require dangerous_actions toggle.
			if ( in_array( $base_key, self::RAW_CODE_KEYS, true ) && in_array( $element_name, [ 'code', 'svg' ], true ) ) {
				$settings_option = get_option( BricksCore::OPTION_SETTINGS, [] );
				if ( ! empty( $settings_option['dangerous_actions'] ) ) {
					$sanitized[ $key ] = $value;
				} else {
					// Strip to safe HTML when dangerous_actions is disabled.
					$sanitized[ $key ] = is_string( $value ) ? wp_kses_post( $value ) : $value;
				}
				continue;
			}

			// CSS code blocks: preserve newlines, braces, combinators.
			// CSS code blocks (_cssCustom, customCss, etc.): sanitized but NOT gated behind
			// dangerous_actions. This is intentional — element-level custom CSS is a core Bricks
			// feature. JS vectors are stripped by sanitize_css_string(). Page-level customCss in
			// page settings IS gated (see SettingsService::update_page_settings).
			if ( $is_css_code ) {
				if ( is_string( $value ) ) {
					$sanitized[ $key ] = $this->sanitize_css_string( $value );
				} elseif ( is_array( $value ) ) {
					$sanitized[ $key ] = $this->sanitize_css_string( implode( "\n", $value ) );
				} else {
					$sanitized[ $key ] = $value;
				}
				continue;
			}

			// Bricks style keys: recurse with CSS-safe sanitization.
			if ( $is_css_key ) {
				if ( is_array( $value ) ) {
					$sanitized[ $key ] = $this->sanitize_style_value( $value );
				} elseif ( is_string( $value ) ) {
					$sanitized[ $key ] = $this->sanitize_css_value( $value );
				} elseif ( ! is_null( $value ) ) {
					$sanitized[ $key ] = $value;
				}
				continue;
			}

			// Non-style arrays (query, link, icon, etc.): recurse.
			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_settings( $value, $element_name );
				continue;
			}

			// Non-string primitives.
			if ( ! is_string( $value ) ) {
				$sanitized[ $key ] = $value;
				continue;
			}

			// HTML content keys.
			$is_html_key   = in_array( $base_key, self::HTML_SETTINGS_KEYS, true );
			$contains_html = wp_strip_all_tags( $value ) !== $value;

			if ( $is_html_key || $contains_html ) {
				$sanitized[ $key ] = wp_kses_post( $value );
				continue;
			}

			// Default: plain text sanitization.
			$sanitized[ $key ] = sanitize_text_field( $value );
		}

		return $sanitized;
	}

	/**
	 * Sanitize a nested Bricks style value (color, dimension, typography, border objects).
	 * Recurses into nested arrays; applies sanitize_css_value() to leaf strings.
	 */
	private function sanitize_style_value( array $value ): array {
		$sanitized = [];
		foreach ( $value as $k => $v ) {
			if ( ! is_string( $k ) ) {
				if ( is_string( $v ) ) {
					$sanitized[ $k ] = $this->sanitize_css_value( $v );
				} elseif ( is_array( $v ) ) {
					$sanitized[ $k ] = $this->sanitize_style_value( $v );
				} else {
					$sanitized[ $k ] = $v;
				}
				continue;
			}
			if ( is_array( $v ) ) {
				$sanitized[ $k ] = $this->sanitize_style_value( $v );
			} elseif ( is_string( $v ) ) {
				$sanitized[ $k ] = $this->sanitize_css_value( $v );
			} elseif ( ! is_null( $v ) ) {
				$sanitized[ $k ] = $v;
			}
		}
		return $sanitized;
	}

	/**
	 * Sanitize a CSS code block string.
	 * Preserves newlines, braces, CSS combinators (>), pseudo-selectors, CSS variables.
	 * NEVER use sanitize_text_field() (collapses newlines) or wp_kses_post() (encodes >).
	 */
	private function sanitize_css_string( string $css ): string {
		$s = wp_strip_all_tags( $css );
		$s = BricksCore::strip_dangerous_css( $s );
		// Strip dangerous at-rules including the full rule up to the semicolon.
		$s = (string) preg_replace( '/@import\s+[^;]+;?/i', '', $s );
		$s = (string) preg_replace( '/@charset\s+[^;]+;?/i', '', $s );
		return $s;
	}

	/**
	 * Sanitize a single CSS value string (units, vars, keywords, colors).
	 */
	private function sanitize_css_value( string $value ): string {
		return BricksCore::strip_dangerous_css( wp_strip_all_tags( trim( $value ) ) );
	}

	/**
	 * Detect Bricks CSS style keys (underscore-prefixed, including composite with :breakpoint/:pseudo).
	 */
	private function is_css_style_key( string $key ): bool {
		return str_starts_with( $key, '_' );
	}

	/**
	 * Detect keys that hold raw CSS code blocks.
	 */
	private function is_css_code_key( string $key ): bool {
		return in_array( $key, self::CSS_CODE_KEYS, true );
	}

	/**
	 * Merge new elements into an existing flat array under a specified parent.
	 */
	public function merge_elements( array $existing, array $new_elements, string $parent_id, ?int $position = null ): array {
		$new_child_ids = array_map(
			static fn( array $el ) => $el['id'],
			array_filter(
				$new_elements,
				static fn( array $el ) => (string) $el['parent'] === $parent_id
			)
		);

		if ( '0' !== $parent_id ) {
			$existing = array_map(
				static function ( array $el ) use ( $parent_id, $new_child_ids, $position ) {
					if ( $el['id'] === $parent_id ) {
						if ( null === $position ) {
							$el['children'] = array_values(
								array_unique( array_merge( $el['children'], $new_child_ids ) )
							);
						} else {
							$children = array_values(
								array_filter(
									$el['children'],
									static fn( string $cid ) => ! in_array( $cid, $new_child_ids, true )
								)
							);
							array_splice( $children, $position, 0, $new_child_ids );
							$el['children'] = array_values( array_unique( $children ) );
						}
					}
					return $el;
				},
				$existing
			);
			return array_merge( $existing, $new_elements );
		}

		if ( null !== $position ) {
			array_splice( $existing, $position, 0, $new_elements );
			return $existing;
		}

		return array_merge( $existing, $new_elements );
	}
}
