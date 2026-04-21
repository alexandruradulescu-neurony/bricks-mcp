<?php
/**
 * Element settings generator.
 *
 * Converts expanded design schema nodes into valid Bricks element settings
 * using element schema data, class intent resolution, and design context.
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
 * Generates Bricks element settings from design schema nodes.
 */
final class ElementSettingsGenerator {

	/**
	 * Element types that carry user-facing text (for text-align/color propagation).
	 * Single source of truth — previously duplicated in 4 sites within this file.
	 */
	private const TEXT_ELEMENT_TYPES = [ 'heading', 'text-basic', 'text', 'text-link' ];

	/**
	 * Default column count when a grid block declares `layout: grid` without `columns`.
	 * Matches the pattern-discovery default in SchemaSkeletonGenerator for consistency.
	 */
	private const DEFAULT_GRID_COLUMNS = 3;

	/**
	 * @var SchemaGenerator
	 */
	private SchemaGenerator $schema_generator;

	/**
	 * @var MediaService|null
	 */
	private ?MediaService $media_service;

	/**
	 * Cached element schemas keyed by element name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $schema_cache = [];

	/**
	 * Element registry loaded from data/elements.json (cached in static property).
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $element_registry = null;

	/**
	 * Constructor.
	 *
	 * @param SchemaGenerator   $schema_generator Schema generator instance.
	 * @param MediaService|null $media_service    Optional media service for Unsplash image resolution.
	 */
	public function __construct( SchemaGenerator $schema_generator, ?MediaService $media_service = null ) {
		$this->schema_generator = $schema_generator;
		$this->media_service    = $media_service;
	}

	/**
	 * Get the full Bricks element registry from data/elements.json.
	 *
	 * @return array<string, array<string, mixed>> Element name => metadata map.
	 */
	public static function get_element_registry(): array {
		if ( null === self::$element_registry ) {
			$path = BricksCore::data_path( 'elements.json' );
			// Integrity check: file must exist AND be readable AND decode to an array
			// AND contain the expected shape. On any failure, fall back to empty
			// registry — downstream code already handles missing elements gracefully.
			self::$element_registry = [];
			if ( file_exists( $path ) && is_readable( $path ) ) {
				$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( is_string( $json ) && '' !== $json ) {
					$data = json_decode( $json, true );
					if ( is_array( $data ) && isset( $data['elements'] ) && is_array( $data['elements'] ) ) {
						self::$element_registry = $data['elements'];
					} else {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( 'BricksMCP ElementSettingsGenerator: elements.json missing or malformed "elements" key. Falling back to empty registry.' );
					}
				} else {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'BricksMCP ElementSettingsGenerator: elements.json empty or unreadable. Falling back to empty registry.' );
				}
			}
		}
		return self::$element_registry;
	}

	/**
	 * Get a single element's metadata from the registry.
	 *
	 * @param string $type Element type name.
	 * @return array<string, mixed> Metadata or empty array if unknown.
	 */
	private static function get_element_meta( string $type ): array {
		$registry = self::get_element_registry();
		return $registry[ $type ] ?? [];
	}

	/**
	 * Determine whether a background-color token indicates a dark background.
	 *
	 * Recognizes exact Bricks variable tokens (var(--base-dark), var(--primary-ultra-dark),
	 * etc.) without matching incidental substrings like `--dark-blue` or `--not-so-dark`.
	 *
	 * The previous regex `(?:^|-)(?:ultra-)?dark(?:-|$|\))` produced false positives on
	 * any token ending with `-dark-*)` or containing `-dark-` anywhere — e.g. light
	 * colors named `--not-so-dark-bg` or the named color `--dark-blue-500`.
	 *
	 * @param string $token Color value (raw Bricks token or CSS var()).
	 * @return bool True when the token is semantically a dark background.
	 */
	private static function is_dark_color_token( string $token ): bool {
		$token = strtolower( trim( $token ) );
		if ( '' === $token ) {
			return false;
		}
		// Exact CSS-variable suffixes that mean "dark" or "ultra-dark" on a base/primary/secondary/accent palette.
		// Matches: var(--base-dark), var(--base-ultra-dark), var(--primary-dark), etc.
		if ( preg_match( '/var\(--(base|primary|secondary|accent)(?:-ultra)?-dark\)/', $token ) ) {
			return true;
		}
		// Exact tokens without var() wrapper (Bricks sometimes stores raw token names).
		if ( preg_match( '/^(base|primary|secondary|accent)(?:-ultra)?-dark$/', $token ) ) {
			return true;
		}
		// CSS `black` keyword or near-black hex values.
		if ( 'black' === $token ) {
			return true;
		}
		// Hex: very-dark colors (shorthand #000..#333 or full-form equivalents).
		if ( preg_match( '/^#(?:[0-2][0-9a-f])(?:[0-2][0-9a-f]){2}(?:[0-9a-f]{2})?$/i', $token ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Get the content key for an element type.
	 *
	 * @param string $type Element type name.
	 * @return string|null Settings key for content, or null if not applicable.
	 */
	private static function get_content_key( string $type ): ?string {
		$meta = self::get_element_meta( $type );
		return $meta['content_key'] ?? null;
	}

	/**
	 * Check if an element type is structural (should receive labels).
	 *
	 * @param string $type Element type name.
	 * @return bool
	 */
	private static function is_structural( string $type ): bool {
		$meta = self::get_element_meta( $type );
		return ! empty( $meta['structural'] );
	}

	/**
	 * Check if an element type defaults to flex display.
	 *
	 * @param string $type Element type name.
	 * @return bool
	 */
	private static function is_flex_by_default( string $type ): bool {
		$meta = self::get_element_meta( $type );
		return ! empty( $meta['flex_by_default'] );
	}

	/**
	 * Check if an element should skip automatic flex injection.
	 *
	 * Nestable elements (tabs-nested, accordion-nested, etc.) manage their own display.
	 *
	 * @param string $type Element type name.
	 * @return bool
	 */
	private static function should_skip_auto_flex( string $type ): bool {
		$meta = self::get_element_meta( $type );
		return ! empty( $meta['skip_auto_flex'] );
	}

	/**
	 * Pre-fetch schemas for a batch of element types.
	 *
	 * Called once before processing to avoid per-node lookups.
	 *
	 * @param array<int, string> $element_types Element type names.
	 */
	public function prefetch_schemas( array $element_types ): void {
		foreach ( $element_types as $type ) {
			if ( ! isset( $this->schema_cache[ $type ] ) ) {
				$schema = $this->schema_generator->get_element_schema( $type );
				if ( ! is_wp_error( $schema ) ) {
					$this->schema_cache[ $type ] = $schema;
				}
			}
		}
	}

	/**
	 * Generate a full Bricks element tree from an expanded schema section.
	 *
	 * Produces a nested array of element objects ready for ElementNormalizer.
	 *
	 * @param array<string, mixed>  $structure           Expanded structure node.
	 * @param array<string, string> $class_map           class_intent => global_class_id map.
	 * @param array<string, mixed>  $design_context      Design context from schema.
	 * @param array<string>         $classes_with_styles  Class intents that have styles embedded in the class (skip inline).
	 * @return array<string, mixed> Nested element tree (simplified format for ElementNormalizer).
	 */
	public function generate( array $structure, array $class_map, array $design_context, array $classes_with_styles = [] ): array {
		return $this->process_node( $structure, $class_map, $design_context, $classes_with_styles, false );
	}

	/**
	 * Process a single structure node into a Bricks element.
	 *
	 * @param array<string, mixed>  $node                Structure node.
	 * @param array<string, string> $class_map           class_intent => global_class_id map.
	 * @param array<string, mixed>  $design_context      Design context.
	 * @param array<string>         $classes_with_styles  Class intents that have styles in the class (skip inline).
	 * @param bool                  $is_dark_context     Whether this node inherits a dark-background parent context.
	 * @return array<string, mixed> Bricks element in simplified nested format.
	 */
	private function process_node(
		array $node,
		array $class_map,
		array $design_context,
		array $classes_with_styles = [],
		bool $is_dark_context = false
	): array {
		$type     = $node['type'] ?? 'div';
		$settings = $this->build_settings( $node, $type, $class_map, $design_context, $classes_with_styles );

		// Keep $settings as an array throughout the pipeline — downstream code (BuildHandler::collect_and_strip_warnings,
		// collect_style_fingerprints, etc.) subscripts settings as array. Empty arrays serialize fine via
		// PHP's serialize() in update_post_meta; the []-vs-{} JSON concern doesn't apply at the DB layer.
		// If a specific downstream consumer needs JSON object shape, it should cast to stdClass locally.

		$element = [
			'name'     => $type,
			'settings' => $settings,
		];

		// Determine if children inherit a dark context.
		// Chained `['_background']['color']['raw']` access is guarded at each level —
		// intermediate keys can be non-array values (string, bool, int) from upstream
		// non-strict inputs, which would crash `?? ''` in PHP 8+.
		$child_is_dark = $is_dark_context;
		if ( is_array( $settings ) ) {
			$bg       = $settings['_background'] ?? null;
			$bg_color = is_array( $bg ) ? ( $bg['color'] ?? null ) : null;
			$bg_raw   = is_array( $bg_color ) ? (string) ( $bg_color['raw'] ?? '' ) : '';
			if ( '' !== $bg_raw && self::is_dark_color_token( $bg_raw ) ) {
				$child_is_dark = true;
			}
		}

		// Process children recursively with dark-context propagation.
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			$children = [];
			foreach ( $node['children'] as $child ) {
				if ( is_array( $child ) ) {
					$children[] = $this->process_node(
						$child,
						$class_map,
						$design_context,
						$classes_with_styles,
						$child_is_dark
					);
				}
			}
			if ( ! empty( $children ) ) {
				// Auto-center text in children when parent is flex-centered.
				$is_centered = false;
				if ( is_array( $settings ) ) {
					$is_centered = ( $settings['_alignItems'] ?? '' ) === 'center';
				}
				if ( $is_centered ) {
					foreach ( $children as &$child_el ) {
						if ( in_array( $child_el['name'] ?? '', self::TEXT_ELEMENT_TYPES, true ) && is_array( $child_el['settings'] ?? null ) ) {
							if ( ! isset( $child_el['settings']['_typography']['text-align'] ) ) {
								$child_el['settings']['_typography']['text-align'] = 'center';
							}
						}
					}
					unset( $child_el );
				}

				// Auto-set white text on direct text children in dark context.
				if ( $child_is_dark ) {
					foreach ( $children as &$child_el ) {
						if ( in_array( $child_el['name'] ?? '', self::TEXT_ELEMENT_TYPES, true ) && is_array( $child_el['settings'] ?? null ) ) {
							if ( ! isset( $child_el['settings']['_typography']['color'] ) ) {
								$child_el['settings']['_typography']['color'] = [ 'raw' => SiteVariableResolver::white_color() ];
							}
						}
					}
					unset( $child_el );
				}

				$element['children'] = $children;
			}
		}

		return $element;
	}

	/**
	 * Build settings for a single element.
	 *
	 * @param array<string, mixed>  $node                Structure node.
	 * @param string                $type                Element type name.
	 * @param array<string, string> $class_map           class_intent => global_class_id map.
	 * @param array<string, mixed>  $design_context      Design context.
	 * @param array<string>         $classes_with_styles  Class intents that have styles embedded in the class.
	 * @return array<string, mixed> Bricks element settings.
	 */
	private function build_settings( array $node, string $type, array $class_map, array $design_context, array $classes_with_styles = [] ): array {
		$settings = [];

		// 1. Apply label on structural elements.
		if ( self::is_structural( $type ) ) {
			$label = $node['label'] ?? $node['class_intent'] ?? null;
			if ( null !== $label ) {
				$settings['label'] = ucfirst( str_replace( [ '-', '_' ], ' ', $label ) );
			}
		}

		// 2. Apply semantic tag.
		if ( ! empty( $node['tag'] ) ) {
			$settings['tag'] = $node['tag'];
		}

		// 3. Apply content to the correct settings key.
		// First try the element registry (data/elements.json), then fall back to cached schema's working_example.
		if ( isset( $node['content'] ) ) {
			$content_key = self::get_content_key( $type );

			// Schema-driven fallback: discover content key from working_example.
			if ( null === $content_key && isset( $this->schema_cache[ $type ]['working_example'] ) ) {
				$example = $this->schema_cache[ $type ]['working_example'];
				foreach ( [ 'text', 'content', 'title', 'label' ] as $candidate ) {
					if ( isset( $example[ $candidate ] ) && is_string( $example[ $candidate ] ) ) {
						$content_key = $candidate;
						break;
					}
				}
			}

			if ( null !== $content_key ) {
				$settings[ $content_key ] = $node['content'];
			}
		}

		// 4. Apply global classes via class_intent.
		if ( ! empty( $node['class_intent'] ) && isset( $class_map[ $node['class_intent'] ] ) ) {
			$settings['_cssGlobalClasses'] = [ $class_map[ $node['class_intent'] ] ];
		}

		// 5. Handle icon elements.
		if ( 'icon' === $type && ! empty( $node['icon'] ) ) {
			$settings['icon'] = $this->resolve_icon( $node['icon'] );
		}

		// 6. Handle button specifics — icon, style, link.
		if ( 'button' === $type ) {
			// Button icon support — Bricks buttons natively support icons.
			if ( ! empty( $node['icon'] ) ) {
				$settings['icon'] = $this->resolve_icon( $node['icon'] );
				$settings['iconPosition'] = $node['iconPosition'] ?? 'left';
			}
			if ( ! empty( $node['style'] ) ) {
				$settings['style'] = $node['style'];
			}
			if ( ! empty( $node['link'] ) ) {
				$settings['link'] = $node['link'];
			} elseif ( ! isset( $settings['link'] ) ) {
				$example = $this->schema_cache['button']['working_example'] ?? [];
				$settings['link'] = $example['link'] ?? [ 'type' => 'external', 'url' => '#' ];
			}
		}

		// 6b. Form element with no fields — surface a warning rather than silently inject a template.
		// Form templates used to be baked in (Romanian newsletter/contact/login), but those shipped
		// with site-specific strings. AI clients must now supply `fields` via element_settings.
		if ( 'form' === $type && empty( $settings['fields'] ) ) {
			$settings['_pipeline_warnings'][] = 'Form element has no fields. Supply a "fields" array via element_settings. Call bricks:get_knowledge(domain="forms") for the 18 field types, 7 actions, and working examples.';
		}

		// 7. Handle image elements with optional Unsplash resolution.
		if ( 'image' === $type && ! empty( $node['src'] ) ) {
			$settings = $this->resolve_image( $settings, $node['src'] );
		}

		// 8. Handle grid layout on block/div.
		if ( in_array( $type, [ 'block', 'div' ], true ) && ! empty( $node['layout'] ) && 'grid' === $node['layout'] ) {
			$columns = $node['columns'] ?? self::DEFAULT_GRID_COLUMNS;
			$settings['_display']             = 'grid';
			$settings['_gridTemplateColumns'] = "repeat({$columns}, 1fr)";
			$settings['_gap']                 = $this->get_spacing_value( 'gap', $design_context );

			// Apply responsive columns.
			if ( ! empty( $node['responsive'] ) ) {
				if ( isset( $node['responsive']['tablet'] ) ) {
					$tablet_cols = (int) $node['responsive']['tablet'];
					$settings['_gridTemplateColumns:tablet_portrait'] = "repeat({$tablet_cols}, 1fr)";
				}
				if ( isset( $node['responsive']['mobile'] ) ) {
					$mobile_cols = (int) $node['responsive']['mobile'];
					$settings['_gridTemplateColumns:mobile'] = "repeat({$mobile_cols}, 1fr)";
				}
			}
		}

		// 9. Handle flex requirement: elements not flex-by-default need explicit _display: flex.
		// Skip nestable elements (manage own display) and elements with _hidden (managed by parent).
		$has_hidden = ! empty( $settings['_hidden'] ) || ! empty( $node['style_overrides']['_hidden'] ?? null );
		if (
			! self::is_flex_by_default( $type )
			&& ! self::should_skip_auto_flex( $type )
			&& ! $has_hidden
			&& ! empty( $node['children'] )
			&& ! isset( $settings['_display'] )
		) {
			$settings['_display']   = 'flex';
			$settings['_direction'] = 'column';
		}

		// 10. Apply style overrides — but SKIP if the class already has these styles.
		// When class_intent has styles embedded in the class, style_overrides are redundant.
		if ( ! empty( $node['style_overrides'] ) && is_array( $node['style_overrides'] ) ) {
			$intent = $node['class_intent'] ?? '';
			if ( '' !== $intent && in_array( $intent, $classes_with_styles, true ) ) {
				// Class has styles — only merge _hidden (structural, not styling).
				if ( isset( $node['style_overrides']['_hidden'] ) ) {
					$settings['_hidden'] = $node['style_overrides']['_hidden'];
				}
			} else {
				// No styled class — merge all overrides inline as before.
				$settings = array_merge( $settings, $node['style_overrides'] );
			}
		}

		// 10a-ii. Quarantine _cssCustom on non-structural element types.
		// Element-level _cssCustom on text/heading/button/icon elements is unreliable
		// and can cause "Array to string conversion" frontend errors.
		if ( isset( $settings['_cssCustom'] ) && in_array( $type, [ 'text-basic', 'heading', 'button', 'icon', 'text', 'text-link' ], true ) ) {
			$css = $settings['_cssCustom'];
			unset( $settings['_cssCustom'] );
			$settings['_pipeline_warnings'][] = sprintf(
				'Element-level _cssCustom on a %s element is unreliable (causes "Array to string conversion" frontend errors). The CSS was stripped: %s. Move this CSS into a class_intent instead.',
				$type,
				substr( is_string( $css ) ? $css : '', 0, 80 )
			);
		}

		// 10b. Auto-fix common invalid keys before validation.
		if ( isset( $settings['_maxWidth'] ) ) {
			$settings['_widthMax'] = $settings['_maxWidth'];
			unset( $settings['_maxWidth'] );
		}
		if ( isset( $settings['_textAlign'] ) ) {
			if ( ! isset( $settings['_typography'] ) ) {
				$settings['_typography'] = [];
			}
			$settings['_typography']['text-align'] = $settings['_textAlign'];
			unset( $settings['_textAlign'] );
		}
		if ( isset( $settings['_minWidth'] ) ) {
			$settings['_widthMin'] = $settings['_minWidth'];
			unset( $settings['_minWidth'] );
		}

		// 10b-ii. Convert _gap to _columnGap/_rowGap on flex blocks.
		// Bricks flex layout blocks don't generate CSS from _gap — they need _columnGap or _rowGap.
		if ( isset( $settings['_gap'] ) && in_array( $type, [ 'block', 'div' ], true ) ) {
			$direction = $settings['_direction'] ?? 'column';
			if ( 'row' === $direction ) {
				if ( ! isset( $settings['_columnGap'] ) ) {
					$settings['_columnGap'] = $settings['_gap'];
				}
			} else {
				if ( ! isset( $settings['_rowGap'] ) ) {
					$settings['_rowGap'] = $settings['_gap'];
				}
			}
			unset( $settings['_gap'] );
		}

		// 10c. Resolve Unsplash queries in background images.
		if ( ! empty( $settings['_background']['image'] ) && null !== $this->media_service ) {
			$bg_image = $settings['_background']['image'];
			$bg_url   = is_array( $bg_image ) ? ( $bg_image['url'] ?? '' ) : (string) $bg_image;

			if ( is_string( $bg_url ) && str_starts_with( $bg_url, 'unsplash:' ) ) {
				$query  = trim( substr( $bg_url, 9 ) );
				$result = $this->media_service->search_photos( $query );

				if ( ! is_wp_error( $result ) && ! empty( $result['photos'][0]['urls']['regular'] ) ) {
					$photo    = $result['photos'][0];
					$url      = $photo['urls']['regular'];
					$alt      = $photo['alt_description'] ?? $query;
					$sideload = $this->media_service->sideload_from_url( $url, $alt, $query );

					if ( ! is_wp_error( $sideload ) && ! empty( $sideload['attachment_id'] ) ) {
						$settings['_background']['image'] = [
							'useDynamicData' => false,
							'id'             => (int) $sideload['attachment_id'],
							'filename'       => $sideload['filename'] ?? '',
							'full'           => $sideload['url'] ?? '',
							'url'            => $sideload['url'] ?? '',
							'size'           => 'full',
						];
						// Ensure background size is set for cover behavior.
						if ( ! isset( $settings['_background']['size'] ) ) {
							$settings['_background']['size'] = 'cover';
						}
						if ( ! isset( $settings['_background']['position'] ) ) {
							$settings['_background']['position'] = 'center center';
						}
					}
				}
			}
		}

		// 10d. Validate style_override keys against CSS property map.
		// Strip invalid underscore-prefixed keys that aren't real Bricks properties.
		foreach ( array_keys( $settings ) as $key ) {
			if ( is_string( $key ) && str_starts_with( $key, '_' ) && ! SchemaGenerator::is_valid_settings_key( $key ) ) {
				unset( $settings[ $key ] );
			}
		}

		// 11. Apply responsive overrides (breakpoint-specific settings).
		if ( ! empty( $node['responsive_overrides'] ) && is_array( $node['responsive_overrides'] ) ) {
			foreach ( $node['responsive_overrides'] as $breakpoint => $overrides ) {
				if ( is_array( $overrides ) ) {
					foreach ( $overrides as $key => $value ) {
						$settings[ "{$key}:{$breakpoint}" ] = $value;
					}
				}
			}
		}

		// 12. Convert _background.overlay to Bricks _gradient overlay format.
		// Bricks uses _gradient with applyTo: "overlay" for background overlays.
		// Guard each step of the chain: overlay may be scalar ("black"), array-without-color,
		// or array-with-non-array-color. Previously short-circuited silently leaving a
		// dangling empty gradient.
		if ( isset( $settings['_background']['overlay'] ) ) {
			$overlay = $settings['_background']['overlay'];
			$overlay_color = '';
			if ( is_string( $overlay ) && '' !== $overlay ) {
				// Scalar shorthand — treat as the raw color value directly.
				$overlay_color = $overlay;
			} elseif ( is_array( $overlay ) && isset( $overlay['color'] ) && is_array( $overlay['color'] ) ) {
				$overlay_color = (string) ( $overlay['color']['raw'] ?? $overlay['color']['hex'] ?? '' );
			}
			if ( '' !== $overlay_color ) {
				$settings['_gradient'] = [
					'colors'  => [
						[ 'color' => [ 'raw' => $overlay_color ] ],
					],
					'applyTo' => 'overlay',
				];
			}
			unset( $settings['_background']['overlay'] );
		}

		// 12b. Merge element_settings escape hatch (type-specific settings like percent, countTo, bars).
		$es_defaults = self::get_element_meta( $type )['element_settings_defaults'] ?? [];
		if ( ! empty( $node['element_settings'] ) && is_array( $node['element_settings'] ) ) {
			// Load sane defaults first, then overlay the AI's element_settings on top.
			$merged_es = array_merge( $es_defaults, $node['element_settings'] );
			foreach ( $merged_es as $es_key => $es_value ) {
				$settings[ $es_key ] = $es_value;
			}
		} elseif ( ! empty( $es_defaults ) ) {
			// No element_settings provided but defaults exist — apply defaults
			// so the element renders with sensible values out of the box.
			foreach ( $es_defaults as $es_key => $es_value ) {
				if ( ! isset( $settings[ $es_key ] ) ) {
					$settings[ $es_key ] = $es_value;
				}
			}
		}

		// 12c. Auto-fix common element_settings value mistakes that AI clients make.
		$settings = $this->auto_fix_element_settings( $settings, $type );

		// 13. Apply background from section-level hint.
		if ( 'section' === $type && ! empty( $node['background'] ) ) {
			$settings = $this->apply_background( $settings, $node['background'], $design_context );
		}

		// 14. Normalize style shapes inline (color objects, border formats, etc.).
		$settings = self::normalize_style_shapes( $settings );

		return $settings;
	}

	/**
	 * Resolve an icon reference to Bricks icon format.
	 *
	 * Accepts a string shorthand (e.g., "truck") or a full icon array.
	 *
	 * @param mixed $icon Icon data from schema.
	 * @return array<string, string> Bricks icon object.
	 */

	/**
	 * Auto-fix common AI-author mistakes in element_settings values.
	 *
	 * Track C of v3.7.0. AI clients consistently send element settings in the
	 * wrong shape: counter countTo as "500+" or "1,200" (must be numeric),
	 * video URLs as full youtube.com/watch?v=ID (must be embed format),
	 * pie-chart percent as a string. Rather than reject these, we normalize
	 * them and continue. Warnings are added to _pipeline_warnings so the AI
	 * sees what we changed.
	 *
	 * @param array<string, mixed> $settings Current element settings.
	 * @param string               $type     Element type (counter, video, pie-chart, etc.).
	 * @return array<string, mixed> Settings with auto-fixes applied.
	 */
	private function auto_fix_element_settings( array $settings, string $type ): array {
		$warnings = [];

		// Counter: countTo must be a numeric integer. Strip non-digits.
		// Extract prefix/suffix from common patterns like "$500", "1,200", "99+".
		if ( 'counter' === $type && isset( $settings['countTo'] ) && ! is_int( $settings['countTo'] ) ) {
			$raw = (string) $settings['countTo'];
			// Match optional non-numeric prefix, the digits (with optional commas/dots), optional suffix.
			if ( preg_match( '/^(?<prefix>[^\d]*)(?<num>[\d.,]+)(?<suffix>.*)$/', $raw, $m ) ) {
				$digits = (int) preg_replace( '/[^0-9]/', '', $m['num'] );
				$settings['countTo'] = $digits;
				if ( ! empty( $m['prefix'] ) && ! isset( $settings['prefix'] ) ) {
					$settings['prefix'] = $m['prefix'];
				}
				if ( ! empty( $m['suffix'] ) && ! isset( $settings['suffix'] ) ) {
					$settings['suffix'] = $m['suffix'];
				}
				if ( $raw !== (string) $digits ) {
					$warnings[] = sprintf(
						'Auto-fixed: counter.countTo "%s" → integer %d (extracted prefix="%s", suffix="%s").',
						$raw, $digits, $m['prefix'] ?? '', $m['suffix'] ?? ''
					);
				}
			}
		}

		// Pie-chart: percent must be a numeric integer 0-100.
		if ( 'pie-chart' === $type && isset( $settings['percent'] ) && ! is_int( $settings['percent'] ) ) {
			$raw = (string) $settings['percent'];
			$num = (int) preg_replace( '/[^0-9]/', '', $raw );
			$num = max( 0, min( 100, $num ) );
			$settings['percent'] = $num;
			if ( $raw !== (string) $num ) {
				$warnings[] = sprintf( 'Auto-fixed: pie-chart.percent "%s" → integer %d (clamped to 0-100).', $raw, $num );
			}
		}

		// Video: convert youtube.com/watch?v=ID and youtu.be/ID to embed format,
		// or extract ytId so Bricks builds the embed URL itself.
		if ( 'video' === $type ) {
			$raw_url = $settings['fileUrl'] ?? $settings['iframeUrl'] ?? '';
			if ( is_string( $raw_url ) && '' !== $raw_url ) {
				// youtube.com/watch?v=ID → ytId
				if ( preg_match( '~youtube\.com/watch\?v=([A-Za-z0-9_-]+)~', $raw_url, $m ) ) {
					$settings['videoType'] = 'youtube';
					$settings['ytId']      = $m[1];
					unset( $settings['fileUrl'] );
					$warnings[] = sprintf( 'Auto-fixed: video URL "%s" → ytId "%s" (videoType: youtube).', $raw_url, $m[1] );
				}
				// youtu.be/ID → ytId
				elseif ( preg_match( '~youtu\.be/([A-Za-z0-9_-]+)~', $raw_url, $m ) ) {
					$settings['videoType'] = 'youtube';
					$settings['ytId']      = $m[1];
					unset( $settings['fileUrl'] );
					$warnings[] = sprintf( 'Auto-fixed: video URL "%s" → ytId "%s" (videoType: youtube).', $raw_url, $m[1] );
				}
				// vimeo.com/ID → vimeoId
				elseif ( preg_match( '~vimeo\.com/(\d+)~', $raw_url, $m ) ) {
					$settings['videoType'] = 'vimeo';
					$settings['vimeoId']   = $m[1];
					unset( $settings['fileUrl'] );
					$warnings[] = sprintf( 'Auto-fixed: video URL "%s" → vimeoId "%s" (videoType: vimeo).', $raw_url, $m[1] );
				}
			}
		}

		// Rating: clamp to 0-maxRating range.
		if ( 'rating' === $type && isset( $settings['rating'] ) ) {
			$raw       = $settings['rating'];
			$max       = isset( $settings['maxRating'] ) ? (float) $settings['maxRating'] : 5.0;
			$num       = is_numeric( $raw ) ? (float) $raw : (float) preg_replace( '/[^0-9.]/', '', (string) $raw );
			$clamped   = max( 0, min( $max, $num ) );
			if ( $clamped !== (float) $raw ) {
				$settings['rating'] = $clamped;
				$warnings[] = sprintf( 'Auto-fixed: rating.rating "%s" → %s (clamped to 0-%s).', $raw, $clamped, $max );
			}
		}

		if ( ! empty( $warnings ) ) {
			$settings['_pipeline_warnings'] = array_merge( $settings['_pipeline_warnings'] ?? [], $warnings );
		}

		return $settings;
	}

	private function resolve_icon( mixed $icon ): array {
		if ( is_array( $icon ) && isset( $icon['library'], $icon['icon'] ) ) {
			return $icon;
		}

		// Read icon library from structured brief, fall back to element defaults.
		$brief_library = BriefResolver::get_instance()->get( 'icon_library' );
		$library_map   = [
			'themify'            => [ 'library' => 'themify', 'prefix' => 'ti-', 'fallback' => 'ti-star' ],
			'fontawesomeSolid'   => [ 'library' => 'fontawesomeSolid', 'prefix' => 'fas fa-', 'fallback' => 'fas fa-star' ],
			'fontawesomeRegular' => [ 'library' => 'fontawesomeRegular', 'prefix' => 'far fa-', 'fallback' => 'far fa-star' ],
			'ionicons'           => [ 'library' => 'ionicons', 'prefix' => 'ion-', 'fallback' => 'ion-ios-star' ],
		];
		$lib_config = $library_map[ $brief_library ] ?? $library_map['themify'];
		$library    = $lib_config['library'];
		$prefix     = $lib_config['prefix'];
		$fallback   = $lib_config['fallback'];

		if ( is_string( $icon ) ) {
			$icon_name = str_starts_with( $icon, $prefix ) ? $icon : $prefix . $icon;
			return [ 'library' => $library, 'icon' => $icon_name ];
		}

		return [ 'library' => $library, 'icon' => $fallback ];
	}

	/**
	 * Get a spacing CSS value based on design context.
	 *
	 * @param string               $purpose        Spacing purpose: 'gap', 'padding', 'section-padding'.
	 * @param array<string, mixed> $design_context Design context.
	 * @return string CSS value.
	 */
	private function get_spacing_value( string $purpose, array $design_context ): string {
		$spacing = $design_context['spacing'] ?? 'normal';

		// Resolve spacing dynamically from the site's actual CSS variables.
		return SiteVariableResolver::spacing( $purpose, $spacing );
	}

	/**
	 * Apply background settings based on a hint string.
	 *
	 * @param array<string, mixed>  $settings       Current settings.
	 * @param string                $background     Background hint: 'dark', 'light', 'gradient', etc.
	 * @param array<string, mixed>  $design_context Design context with palette hints.
	 * @return array<string, mixed> Settings with background applied.
	 */
	private function apply_background( array $settings, string $background, array $design_context ): array {
		// Preserve any existing _background settings (e.g., image from style_overrides/Unsplash resolution).
		$existing_bg = $settings['_background'] ?? [];

		$brief = BriefResolver::get_instance();
		switch ( $background ) {
			case 'dark':
				$settings['_background'] = array_merge( $existing_bg, [ 'color' => [ 'raw' => $brief->get( 'dark_bg_color' ) ] ] );
				$settings['_color']      = [ 'raw' => $brief->get( 'dark_text_color' ) ];
				break;
			case 'light':
				$settings['_background'] = array_merge( $existing_bg, [ 'color' => [ 'raw' => $brief->get( 'light_alt_bg_color' ) ] ] );
				break;
			case 'gradient':
				$settings['_gradient'] = [
					'colors' => [
						[ 'color' => [ 'raw' => SiteVariableResolver::primary_color() ] ],
						[ 'color' => [ 'raw' => SiteVariableResolver::primary_dark_color() ], 'stop' => '100' ],
					],
				];
				break;
		}

		return $settings;
	}

	/**
	 * Resolve an image source into Bricks image settings.
	 *
	 * Supports:
	 * - "unsplash:query" — searches Unsplash, sideloads first result
	 * - Numeric string — treated as attachment ID
	 * - URL string — used as external image URL
	 *
	 * @param array<string, mixed> $settings Current element settings.
	 * @param string               $src      Image source descriptor.
	 * @return array<string, mixed> Settings with image applied.
	 */
	private function resolve_image( array $settings, string $src ): array {
		// Unsplash integration: "unsplash:mountain landscape"
		if ( str_starts_with( $src, 'unsplash:' ) ) {
			$query    = trim( substr( $src, 9 ) );
			$reason   = null;

			if ( null === $this->media_service ) {
				$reason = 'media_service not available';
			} else {
				$result = $this->media_service->search_photos( $query );

				if ( is_wp_error( $result ) ) {
					$reason = 'search_photos failed: ' . $result->get_error_message();
				} elseif ( empty( $result['photos'][0]['urls']['regular'] ) ) {
					$reason = sprintf( 'No Unsplash results for query "%s" (is the Unsplash API key configured in Settings > Bricks MCP?)', $query );
				} else {
					$photo    = $result['photos'][0];
					$url      = $photo['urls']['regular'];
					$alt      = $photo['alt_description'] ?? $query;
					$sideload = $this->media_service->sideload_from_url( $url, $alt, $query );

					if ( is_wp_error( $sideload ) ) {
						$reason = 'sideload_from_url failed: ' . $sideload->get_error_message();
					} elseif ( empty( $sideload['attachment_id'] ) ) {
						$reason = 'sideload returned no attachment_id';
					} else {
						$settings['image'] = [
							'id'       => (int) $sideload['attachment_id'],
							'filename' => $sideload['filename'] ?? '',
							'size'     => 'full',
							'full'     => $sideload['url'] ?? '',
							'url'      => $sideload['url'] ?? '',
						];
						return $settings;
					}
				}
			}

			// Surface the failure instead of silently falling through to empty.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional warning.
			error_log( sprintf( 'BricksMCP: Unsplash image resolution failed for "%s" — %s. Image slot left empty.', $src, $reason ?? 'unknown' ) );

			// Attach a warning on the settings so the build response can report it.
			$settings['_pipeline_warnings'] = array_merge(
				$settings['_pipeline_warnings'] ?? [],
				[ sprintf( 'Unsplash sideload failed for "%s": %s. Upload an image manually or configure an Unsplash API key.', $src, $reason ?? 'unknown' ) ]
			);
			return $settings;
		}

		// Attachment ID: "123"
		if ( is_numeric( $src ) ) {
			$attachment_id = (int) $src;
			$url           = wp_get_attachment_url( $attachment_id );
			if ( $url ) {
				$settings['image'] = [
					'id'       => $attachment_id,
					'filename' => basename( get_attached_file( $attachment_id ) ?: '' ),
					'size'     => 'full',
					'full'     => $url,
					'url'      => $url,
				];
			}
			return $settings;
		}

		// External URL fallback.
		if ( filter_var( $src, FILTER_VALIDATE_URL ) ) {
			$settings['image'] = [
				'id'       => 0,
				'filename' => basename( wp_parse_url( $src, PHP_URL_PATH ) ?: 'image.jpg' ),
				'size'     => 'full',
				'full'     => $src,
				'url'      => $src,
			];
		}

		return $settings;
	}

	/**
	 * Normalize style shapes so Bricks never receives malformed settings.
	 *
	 * Fixes the 7 known shape mismatches that AI-generated style_overrides can
	 * introduce. Each rule matches what StyleShapeValidator previously corrected
	 * as a post-hoc band-aid; now the shapes are produced correctly at the source.
	 *
	 * @param array<string, mixed> $settings Element settings to normalize.
	 * @return array<string, mixed> Settings with correct shapes.
	 */
	private static function normalize_style_shapes( array $settings ): array {
		// Rule 1: _border.style — per-side object → string.
		if ( isset( $settings['_border']['style'] ) && is_array( $settings['_border']['style'] ) ) {
			$arr   = $settings['_border']['style'];
			$first = '';
			if ( is_string( $arr['top'] ?? null ) && '' !== $arr['top'] ) {
				$first = $arr['top'];
			} else {
				foreach ( $arr as $v ) {
					if ( is_string( $v ) && '' !== $v ) {
						$first = $v;
						break;
					}
				}
			}
			$settings['_border']['style'] = '' !== $first ? $first : 'solid';
		}

		// Rule 2: _border.color — string → {raw|hex} object.
		if ( isset( $settings['_border']['color'] ) && is_string( $settings['_border']['color'] ) ) {
			$settings['_border']['color'] = self::wrap_color_value( $settings['_border']['color'] );
		}

		// Rule 3: _border.width — flat string → per-side object.
		if ( isset( $settings['_border']['width'] ) && is_string( $settings['_border']['width'] ) ) {
			$w = $settings['_border']['width'];
			$settings['_border']['width'] = [ 'top' => $w, 'right' => $w, 'bottom' => $w, 'left' => $w ];
		}

		// Rule 4: _cssCustom — array → string.
		if ( isset( $settings['_cssCustom'] ) && is_array( $settings['_cssCustom'] ) ) {
			$settings['_cssCustom'] = implode( "\n", array_filter( $settings['_cssCustom'], 'is_string' ) );
		}

		// Rule 5: _typography.color — string → {raw|hex} object.
		if ( isset( $settings['_typography']['color'] ) && is_string( $settings['_typography']['color'] ) ) {
			$settings['_typography']['color'] = self::wrap_color_value( $settings['_typography']['color'] );
		}

		// Rule 6: _background.color — string → {raw|hex} object.
		if ( isset( $settings['_background']['color'] ) && is_string( $settings['_background']['color'] ) ) {
			$settings['_background']['color'] = self::wrap_color_value( $settings['_background']['color'] );
		}

		// Rule 7: top-level _color — string → {raw|hex} object.
		if ( isset( $settings['_color'] ) && is_string( $settings['_color'] ) ) {
			$settings['_color'] = self::wrap_color_value( $settings['_color'] );
		}

		return $settings;
	}

	/**
	 * Wrap a color string in Bricks' expected {raw|hex} object format.
	 *
	 * Hex codes (#fff, #a1b2c3) use the 'hex' key; everything else (CSS
	 * variables, rgb(), named colors) uses the 'raw' key.
	 *
	 * @param string $value Raw color string.
	 * @return array<string, string> Bricks color object.
	 */
	private static function wrap_color_value( string $value ): array {
		if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ) {
			return [ 'hex' => $value ];
		}
		return [ 'raw' => $value ];
	}
}
