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
	 * @var SchemaGenerator
	 */
	private SchemaGenerator $schema_generator;

	/**
	 * @var MediaService|null
	 */
	private ?MediaService $media_service;

	/**
	 * @var ClassIntentResolver|null
	 */
	private ?ClassIntentResolver $class_resolver;

	/**
	 * Cached element schemas keyed by element name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $schema_cache = [];

	/**
	 * Element defaults loaded from data/element-defaults.json.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $element_defaults = null;

	/**
	 * Constructor.
	 *
	 * @param SchemaGenerator        $schema_generator Schema generator instance.
	 * @param MediaService|null      $media_service    Optional media service for Unsplash image resolution.
	 * @param ClassIntentResolver|null $class_resolver Optional class resolver for contextual class suggestions.
	 */
	public function __construct( SchemaGenerator $schema_generator, ?MediaService $media_service = null, ?ClassIntentResolver $class_resolver = null ) {
		$this->schema_generator = $schema_generator;
		$this->media_service    = $media_service;
		$this->class_resolver   = $class_resolver;
	}

	/**
	 * Load element defaults from the data file (cached in static property).
	 *
	 * @return array<string, mixed>
	 */
	private static function get_defaults(): array {
		if ( null === self::$element_defaults ) {
			$path = dirname( __DIR__, 3 ) . '/data/element-defaults.json';
			if ( file_exists( $path ) ) {
				$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				self::$element_defaults = is_string( $json ) ? json_decode( $json, true ) : [];
			} else {
				self::$element_defaults = [];
			}
		}
		return self::$element_defaults;
	}

	/**
	 * Get the content key for an element type.
	 *
	 * @param string $type Element type name.
	 * @return string|null Settings key for content, or null if not applicable.
	 */
	private static function get_content_key( string $type ): ?string {
		$defaults = self::get_defaults();
		return $defaults['content_keys'][ $type ] ?? null;
	}

	/**
	 * Check if an element type is structural (should receive labels).
	 *
	 * @param string $type Element type name.
	 * @return bool
	 */
	private static function is_structural( string $type ): bool {
		$defaults = self::get_defaults();
		return in_array( $type, $defaults['structural_elements'] ?? [ 'section', 'container', 'block', 'div' ], true );
	}

	/**
	 * Check if an element type defaults to flex display.
	 *
	 * @param string $type Element type name.
	 * @return bool
	 */
	private static function is_flex_by_default( string $type ): bool {
		$defaults = self::get_defaults();
		return in_array( $type, $defaults['flex_by_default'] ?? [ 'section', 'container', 'block' ], true );
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
		$defaults = self::get_defaults();
		return in_array( $type, $defaults['skip_auto_flex'] ?? [], true );
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
		return $this->process_node( $structure, $class_map, $design_context, 'root', [], 0, null, $classes_with_styles, false );
	}

	/**
	 * Process a single structure node into a Bricks element.
	 *
	 * @param array<string, mixed>  $node                Structure node.
	 * @param array<string, string> $class_map           class_intent => global_class_id map.
	 * @param array<string, mixed>  $design_context      Design context.
	 * @param string                $parent_type         Parent element type ('root' for top-level).
	 * @param array<string>         $sibling_types       Types of all siblings in order.
	 * @param int                   $position            This node's position among siblings.
	 * @param string|null           $parent_class        Class name applied to parent (for context rules).
	 * @param array<string>         $classes_with_styles  Class intents that have styles in the class (skip inline).
	 * @return array<string, mixed> Bricks element in simplified nested format.
	 */
	private function process_node(
		array $node,
		array $class_map,
		array $design_context,
		string $parent_type = 'root',
		array $sibling_types = [],
		int $position = 0,
		?string $parent_class = null,
		array $classes_with_styles = [],
		bool $is_dark_context = false
	): array {
		$type     = $node['type'] ?? 'div';
		$settings = $this->build_settings( $node, $type, $class_map, $design_context, $classes_with_styles );

		// Auto-suggest class if none was explicitly set and resolver is available.
		if ( null !== $this->class_resolver && ! isset( $settings['_cssGlobalClasses'] ) ) {
			$child_types = [];
			foreach ( $node['children'] ?? [] as $child ) {
				if ( is_array( $child ) && ! empty( $child['type'] ) ) {
					$child_types[] = $child['type'];
				}
			}

			$suggested_id = $this->class_resolver->suggest_for_context(
				$type,
				$parent_type,
				$sibling_types,
				$position,
				$child_types,
				$parent_class
			);

			if ( null !== $suggested_id ) {
				if ( is_array( $settings ) ) {
					$settings['_cssGlobalClasses'] = [ $suggested_id ];
				}
			}
		}

		// Ensure settings is never an empty array (would serialize as JSON [] instead of {}).
		if ( empty( $settings ) ) {
			$settings = new \stdClass();
		}

		$element = [
			'name'     => $type,
			'settings' => $settings,
		];

		// Determine this node's class name for child context (used by inside_pill rule etc.).
		$this_class_name = null;
		if ( ! empty( $node['class_intent'] ) ) {
			$this_class_name = $node['class_intent'];
		} elseif ( is_array( $settings ) && ! empty( $settings['_cssGlobalClasses'] ) ) {
			// Reverse-lookup class name from ID for child context.
			$this_class_name = $this->resolve_class_name_from_id( $settings['_cssGlobalClasses'][0] );
		}

		// Build sibling type list for children.
		$child_sibling_types = [];
		foreach ( $node['children'] ?? [] as $child ) {
			if ( is_array( $child ) ) {
				$child_sibling_types[] = $child['type'] ?? 'div';
			}
		}

		// Determine if children inherit a dark context.
		$child_is_dark = $is_dark_context;
		if ( is_array( $settings ) ) {
			$bg_raw = $settings['_background']['color']['raw'] ?? '';
			if ( str_contains( $bg_raw, 'dark' ) || str_contains( $bg_raw, 'ultra-dark' ) ) {
				$child_is_dark = true;
			}
		}

		// Process children recursively with context.
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			$children = [];
			$child_pos = 0;
			foreach ( $node['children'] as $child ) {
				if ( is_array( $child ) ) {
					$children[] = $this->process_node(
						$child,
						$class_map,
						$design_context,
						$type,
						$child_sibling_types,
						$child_pos,
						$this_class_name,
						$classes_with_styles,
						$child_is_dark
					);
					$child_pos++;
				}
			}
			if ( ! empty( $children ) ) {
				// Auto-center text in children when parent is flex-centered.
				$is_centered = false;
				if ( is_array( $settings ) ) {
					$is_centered = ( $settings['_alignItems'] ?? '' ) === 'center';
				}
				if ( $is_centered ) {
					$text_types = [ 'heading', 'text-basic', 'text', 'text-link' ];
					foreach ( $children as &$child_el ) {
						if ( in_array( $child_el['name'] ?? '', $text_types, true ) && is_array( $child_el['settings'] ?? null ) ) {
							if ( ! isset( $child_el['settings']['_typography']['text-align'] ) ) {
								$child_el['settings']['_typography']['text-align'] = 'center';
							}
						}
					}
					unset( $child_el );
				}

				// Auto-set white text on direct text children in dark context.
				if ( $child_is_dark ) {
					$dark_text_types = [ 'heading', 'text-basic', 'text', 'text-link' ];
					foreach ( $children as &$child_el ) {
						if ( in_array( $child_el['name'] ?? '', $dark_text_types, true ) && is_array( $child_el['settings'] ?? null ) ) {
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
		// First try element-defaults.json, then fall back to cached schema's working_example.
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

		// 6. Handle button specifics — use schema working_example for defaults.
		if ( 'button' === $type ) {
			if ( ! empty( $node['style'] ) ) {
				$settings['style'] = $node['style'];
			}
			if ( ! empty( $node['link'] ) ) {
				$settings['link'] = $node['link'];
			} elseif ( ! isset( $settings['link'] ) ) {
				// Schema-driven default: use working_example link if available.
				$example = $this->schema_cache['button']['working_example'] ?? [];
				$settings['link'] = $example['link'] ?? [ 'type' => 'external', 'url' => '#' ];
			}
		}

		// 7. Handle image elements with optional Unsplash resolution.
		if ( 'image' === $type && ! empty( $node['src'] ) ) {
			$settings = $this->resolve_image( $settings, $node['src'] );
		}

		// 8. Handle grid layout on block/div.
		if ( in_array( $type, [ 'block', 'div' ], true ) && ! empty( $node['layout'] ) && 'grid' === $node['layout'] ) {
			$columns = $node['columns'] ?? 3;
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

		// 12. Apply background from section-level hint.
		if ( 'section' === $type && ! empty( $node['background'] ) ) {
			$settings = $this->apply_background( $settings, $node['background'], $design_context );
		}

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
	private function resolve_icon( mixed $icon ): array {
		if ( is_array( $icon ) && isset( $icon['library'], $icon['icon'] ) ) {
			return $icon;
		}

		$defaults = self::get_defaults();
		$library  = $defaults['icon_defaults']['library'] ?? 'themify';
		$prefix   = $defaults['icon_defaults']['prefix'] ?? 'ti-';
		$fallback = $defaults['icon_defaults']['fallback'] ?? 'ti-star';

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
		switch ( $background ) {
			case 'dark':
				$settings['_background'] = [ 'color' => [ 'raw' => SiteVariableResolver::dark_background() ] ];
				$settings['_color']      = [ 'raw' => SiteVariableResolver::white_color() ];
				break;
			case 'light':
				$settings['_background'] = [ 'color' => [ 'raw' => SiteVariableResolver::light_background() ] ];
				break;
			case 'gradient':
				// Use _gradient key (separate from _background in Bricks).
				$settings['_gradient'] = [
					'colors' => [
						[ 'color' => [ 'raw' => SiteVariableResolver::primary_color() ] ],
						[ 'color' => [ 'raw' => SiteVariableResolver::primary_dark_color() ], 'stop' => '100' ],
					],
				];
				break;
			// 'image' backgrounds need actual image data — skip (handled via style_overrides).
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
		if ( str_starts_with( $src, 'unsplash:' ) && null !== $this->media_service ) {
			$query  = trim( substr( $src, 9 ) );
			$result = $this->media_service->search_photos( $query );

			if ( ! is_wp_error( $result ) && ! empty( $result['photos'][0]['urls']['regular'] ) ) {
				$photo    = $result['photos'][0];
				$url      = $photo['urls']['regular'];
				$alt      = $photo['alt_description'] ?? $query;
				$sideload = $this->media_service->sideload_from_url( $url, $alt, $query );

				if ( ! is_wp_error( $sideload ) && ! empty( $sideload['attachment_id'] ) ) {
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
			// Fall through to URL-based if sideload fails.
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
	 * Reverse-lookup a class name from its ID.
	 *
	 * Used to pass parent class name context to child elements for context rules.
	 *
	 * @param string $class_id Global class ID.
	 * @return string|null Class name or null if not found.
	 */
	private function resolve_class_name_from_id( string $class_id ): ?string {
		if ( null === $this->class_resolver ) {
			return null;
		}
		foreach ( $this->class_resolver->get_classes_public() as $class ) {
			if ( ( $class['id'] ?? '' ) === $class_id ) {
				return $class['name'] ?? null;
			}
		}
		return null;
	}
}
