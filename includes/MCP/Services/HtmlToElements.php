<?php
/**
 * HTML → Bricks schema converter.
 *
 * Pure parser. Takes an HTML fragment and returns a Bricks element tree
 * shaped to match {@see DesignSchemaValidator}'s VALID_NODE_KEYS so the
 * output drops directly into build_structure's existing pipeline.
 *
 * Stateless. No DB calls, no class resolution, no media sideload —
 * those are downstream concerns handled by ClassIntentResolver,
 * StyleNormalizationService, and the media handler.
 *
 * Clean-room implementation. Reference behavior was studied in
 * agent-to-bricks (GPL-3.0); this code is independent and licensed under
 * GPL-2.0-or-later. The CSS-property → Bricks-key map was re-derived from
 * Bricks element schemas + bricks-mcp's normalization rules.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convert HTML strings into Bricks schema accepted by build_structure.
 */
final class HtmlToElements {

	/**
	 * HTML tag → Bricks element type.
	 *
	 * @var array<string, string>
	 */
	private const TAG_MAP = [
		// Containers / structural.
		'section'    => 'section',
		'header'     => 'section',
		'footer'     => 'section',
		'main'       => 'section',
		// <article> is a content unit (card, post, item) — it stays as a
		// styled block so its children remain grouped. Other wrapper tags
		// (header/footer/main) collapse via SKIP_TAGS_RECURSE because they
		// rarely carry their own styling.
		'article'    => 'block',
		// HTML <div> maps to Bricks `block`, not `div`. Both accept the same
		// children and parents, but Bricks ships `.brxe-div` with a default
		// `display: flex; flex-direction: column` rule that beats inline
		// `display: grid` style overrides through specificity. `.brxe-block`
		// has neutral defaults, so layout intent set via inline style is
		// honored at render. Verified empirically with a 3-column grid that
		// rendered as flex-column under `div` and as grid under `block`.
		'div'        => 'block',
		'aside'      => 'block',
		'nav'        => 'block',
		'figure'     => 'block',
		'li'         => 'block',

		// Headings.
		'h1'         => 'heading',
		'h2'         => 'heading',
		'h3'         => 'heading',
		'h4'         => 'heading',
		'h5'         => 'heading',
		'h6'         => 'heading',

		// Text.
		'p'          => 'text-basic',
		'span'       => 'text-basic',
		'blockquote' => 'text-basic',
		'figcaption' => 'text-basic',

		// Links / clickable.
		'a'          => 'text-link',
		'button'     => 'button',

		// Media.
		'img'        => 'image',
		'video'      => 'video',

		// Lists.
		'ul'         => 'list',
		'ol'         => 'list',

		// Other.
		'hr'         => 'divider',
	];

	/**
	 * Rich Bricks elements addressable from HTML via the
	 * `data-bricks-element="..."` convention. Maps the keyword to the
	 * builder method that synthesizes the full Bricks element shape.
	 *
	 * @var array<string, string>
	 */
	private const RICH_ELEMENTS = [
		'icon'             => 'build_icon_node',
		'counter'          => 'build_counter_node',
		'accordion-nested' => 'build_accordion_nested_node',
		'tabs-nested'      => 'build_tabs_nested_node',
		'slider-nested'    => 'build_slider_nested_node',
	];

	/**
	 * HTML tags that are always skipped (their children are still walked).
	 *
	 * @var array<int, string>
	 */
	private const SKIP_TAGS_RECURSE = [ 'main', 'header', 'footer' ];

	/**
	 * HTML tags that are skipped entirely (no recursion).
	 *
	 * @var array<int, string>
	 */
	private const SKIP_TAGS_HARD = [ 'script', 'style', 'meta', 'link', 'head', 'html', 'body', 'br', 'noscript' ];

	/**
	 * CSS properties that map into the `_typography` setting object.
	 * Bricks compiler reads kebab-case keys inside _typography (silent fail
	 * on camelCase — see knowledge doc data/knowledge/global-classes.md).
	 *
	 * @var array<int, string>
	 */
	private const TYPOGRAPHY_PROPS = [
		'color',
		'font-size',
		'font-weight',
		'font-family',
		'text-align',
		'line-height',
		'letter-spacing',
		'font-style',
		'text-transform',
		'text-decoration',
	];

	/**
	 * CSS properties that map directly to top-level Bricks setting keys.
	 *
	 * Note: bricks-mcp's StyleNormalizationService rewrites a few legacy
	 * shapes (e.g., _maxWidth → _widthMax). We emit the canonical keys.
	 *
	 * @var array<string, string>
	 */
	private const TOP_LEVEL_PROPS = [
		'display'                => '_display',
		'flex-direction'         => '_direction',
		'flex-wrap'              => '_flexWrap',
		'flex-grow'              => '_flexGrow',
		'flex-shrink'            => '_flexShrink',
		'flex-basis'             => '_flexBasis',
		'order'                  => '_order',
		'align-self'             => '_alignSelf',
		'justify-content'        => '_justifyContent',
		'align-items'            => '_alignItems',
		'align-content'          => '_alignContent',
		'gap'                    => '_gap',
		'row-gap'                => '_rowGap',
		'column-gap'             => '_columnGap',
		'grid-template-columns'  => '_gridTemplateColumns',
		'grid-template-rows'     => '_gridTemplateRows',
		'grid-auto-flow'         => '_gridAutoFlow',
		'width'                  => '_width',
		'max-width'              => '_widthMax',
		'min-width'              => '_widthMin',
		'height'                 => '_height',
		'max-height'             => '_heightMax',
		'min-height'             => '_minHeight',
		'aspect-ratio'           => '_aspectRatio',
		'object-fit'             => '_objectFit',
		'object-position'        => '_objectPosition',
		'overflow'               => '_overflow',
		'position'               => '_position',
		'top'                    => '_top',
		'right'                  => '_right',
		'bottom'                 => '_bottom',
		'left'                   => '_left',
		'z-index'                => '_zIndex',
		'opacity'                => '_opacity',
		'cursor'                 => '_cursor',
		'transition'             => '_transition',
		'transform'              => '_transform',
		'box-shadow'             => '_boxShadow',
	];

	/**
	 * Convert an HTML fragment to a Bricks schema for build_structure.
	 *
	 * @param string $html HTML fragment (may include multiple top-level elements).
	 * @return array{
	 *     sections: array<int, array<string, mixed>>,
	 *     class_names_seen: array<int, string>,
	 *     css_rules_dropped: array<int, array{property: string, value: string, element_path: string}>,
	 *     warnings: array<int, string>,
	 *     stats: array<string, int>
	 * }|WP_Error
	 */
	public static function convert( string $html ): array|WP_Error {
		$html = trim( $html );
		if ( '' === $html ) {
			return new WP_Error(
				'html_to_elements_empty',
				__( 'No HTML input provided.', 'bricks-mcp' )
			);
		}

		$body = self::parse_to_body( $html );
		if ( $body instanceof WP_Error ) {
			return $body;
		}

		$context = [
			'class_names_seen'   => [],
			'css_rules_dropped'  => [],
			'warnings'           => [],
			'tags_processed'     => 0,
			'tags_skipped'       => 0,
		];

		$top_level = self::process_children( $body, '', $context );

		// Wrap loose top-level non-section children in a synthetic section
		// so the output always conforms to build_structure's "sections" shape.
		$sections = [];
		$loose    = [];
		foreach ( $top_level as $node ) {
			if ( ( $node['type'] ?? '' ) === 'section' ) {
				$sections[] = [
					'intent'    => 'html_converted_section',
					'structure' => $node,
				];
			} else {
				$loose[] = $node;
			}
		}
		if ( ! empty( $loose ) ) {
			// Synthetic section gets the same auto-container wrap that build_node
			// applies for explicit <section> nodes — section's only valid Bricks
			// child is container.
			$sections[] = [
				'intent'    => 'html_converted_loose_content',
				'structure' => [
					'type'     => 'section',
					'label'    => 'Converted Content',
					'children' => [
						[
							'type'     => 'container',
							'label'    => 'Container',
							'children' => $loose,
						],
					],
				],
			];
		}

		return [
			'sections'          => $sections,
			'class_names_seen'  => array_values( array_unique( $context['class_names_seen'] ) ),
			'css_rules_dropped' => $context['css_rules_dropped'],
			'warnings'          => $context['warnings'],
			'stats'             => [
				'tags_processed' => $context['tags_processed'],
				'tags_skipped'   => $context['tags_skipped'],
				'sections_count' => count( $sections ),
			],
		];
	}

	/**
	 * Parse an HTML fragment into a DOMElement representing <body>.
	 *
	 * @param string $html
	 * @return DOMElement|WP_Error
	 */
	private static function parse_to_body( string $html ): DOMElement|WP_Error {
		$prev_libxml = libxml_use_internal_errors( true );

		$doc = new DOMDocument( '1.0', 'UTF-8' );
		// Charset wrapper ensures DOMDocument treats input as UTF-8.
		$wrapped = '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
		$doc->loadHTML(
			$wrapped,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $prev_libxml );

		$body_list = $doc->getElementsByTagName( 'body' );
		$body      = $body_list->item( 0 );

		if ( ! ( $body instanceof DOMElement ) ) {
			return new WP_Error(
				'html_to_elements_parse_failed',
				__( 'Could not parse HTML body.', 'bricks-mcp' )
			);
		}

		return $body;
	}

	/**
	 * Walk a DOM node's children and convert each into a schema node.
	 *
	 * @param DOMNode             $parent
	 * @param string              $path     JSON-pointer-like trail for diagnostics.
	 * @param array<string,mixed> $context  Mutable accumulator.
	 * @return array<int, array<string, mixed>>
	 */
	private static function process_children( DOMNode $parent, string $path, array &$context ): array {
		$children = [];
		$child_index = 0;

		foreach ( $parent->childNodes as $child ) {
			if ( ! ( $child instanceof DOMElement ) ) {
				continue;
			}

			$tag = strtolower( $child->tagName );

			if ( in_array( $tag, self::SKIP_TAGS_HARD, true ) ) {
				continue;
			}

			if ( in_array( $tag, self::SKIP_TAGS_RECURSE, true ) ) {
				// Walk through but don't emit a node for these wrapper tags.
				$context['tags_skipped']++;
				$descendants = self::process_children( $child, $path . '/' . $tag, $context );
				foreach ( $descendants as $descendant ) {
					$children[] = $descendant;
				}
				continue;
			}

			// v5.2.0: rich-element marker overrides tag mapping. An element
			// like <i data-bricks-element="icon"> is a valid rich-element
			// placeholder even though <i> isn't in TAG_MAP — let build_node
			// route it via RICH_ELEMENTS.
			$rich_marker = strtolower( trim( $child->getAttribute( 'data-bricks-element' ) ) );
			$has_rich    = '' !== $rich_marker && isset( self::RICH_ELEMENTS[ $rich_marker ] );

			$mapped_type = self::TAG_MAP[ $tag ] ?? null;
			if ( null === $mapped_type && ! $has_rich ) {
				$context['warnings'][] = sprintf(
					/* translators: %s: HTML tag */
					__( 'Skipped unknown tag: <%s>', 'bricks-mcp' ),
					$tag
				);
				$context['tags_skipped']++;
				// Promote children inline instead of dropping them.
				$promoted = self::process_children( $child, $path . '/' . $tag, $context );
				foreach ( $promoted as $node ) {
					$children[] = $node;
				}
				continue;
			}
			if ( null === $mapped_type ) {
				// Tag not in TAG_MAP but a rich-element marker is present —
				// build_node will dispatch through RICH_ELEMENTS based on the
				// marker, ignoring the tag name. Use a placeholder type so the
				// pipeline doesn't choke before build_node runs.
				$mapped_type = $rich_marker;
			}

			$context['tags_processed']++;
			$child_index++;
			$node_path = $path . '/' . $tag . '[' . $child_index . ']';

			$node = self::build_node( $child, $tag, $mapped_type, $node_path, $context );
			$children[] = $node;
		}

		return $children;
	}

	/**
	 * Build a single schema node from a DOM element.
	 *
	 * @param DOMElement          $el
	 * @param string              $tag
	 * @param string              $type
	 * @param string              $path
	 * @param array<string,mixed> $context
	 * @return array<string, mixed>
	 */
	private static function build_node( DOMElement $el, string $tag, string $type, string $path, array &$context ): array {
		// v5.2.0: rich-element override. data-bricks-element="icon|counter|
		// accordion-nested|tabs-nested|slider-nested" routes to a dedicated
		// builder that emits the proper Bricks element shape (icon library +
		// name, counter target/duration, the deeply-nested accordion/tabs/
		// slider structures) — things HTML's vocabulary can't express.
		$bricks_override = strtolower( trim( $el->getAttribute( 'data-bricks-element' ) ) );
		if ( '' !== $bricks_override && isset( self::RICH_ELEMENTS[ $bricks_override ] ) ) {
			$method = self::RICH_ELEMENTS[ $bricks_override ];
			return self::$method( $el, $path, $context );
		}

		$node = [ 'type' => $type ];

		if ( 'heading' === $type ) {
			$node['tag'] = $tag;
		}

		// Class extraction.
		$class_attr = trim( $el->getAttribute( 'class' ) );
		if ( '' !== $class_attr ) {
			$class_list = preg_split( '/\s+/', $class_attr, -1, PREG_SPLIT_NO_EMPTY );
			if ( is_array( $class_list ) && ! empty( $class_list ) ) {
				foreach ( $class_list as $cls ) {
					$context['class_names_seen'][] = $cls;
				}
				// First class becomes class_intent; resolver maps it to a global class.
				$node['class_intent'] = $class_list[0];
				// Extras get carried in element_settings._cssClasses (string, Bricks-shape).
				if ( count( $class_list ) > 1 ) {
					$extras = array_slice( $class_list, 1 );
					$node['element_settings'] = $node['element_settings'] ?? [];
					$node['element_settings']['_cssClasses'] = implode( ' ', $extras );
				}
				$node['label'] = self::label_from_class( $class_list[0] );
			}
		}

		// Fallback label.
		if ( ! isset( $node['label'] ) ) {
			$id_attr = trim( $el->getAttribute( 'id' ) );
			if ( '' !== $id_attr ) {
				$node['label'] = self::humanize( $id_attr );
			}
		}

		// Inline text content for text-bearing elements.
		// v5.2.0: use concatenated extraction so inline child elements (spans,
		// strong, em, etc.) contribute their text. Mixed-color heading/text
		// runs lose their per-span styling at this stage (Bricks heading is a
		// single styled run) but the text content survives.
		if ( in_array( $type, [ 'heading', 'text-basic', 'text-link', 'button' ], true ) ) {
			$text = self::extract_concatenated_text( $el );
			if ( '' !== $text ) {
				$node['content'] = $text;
			}
		}

		// Tag-specific attribute handling.
		if ( 'image' === $type ) {
			$src = trim( $el->getAttribute( 'src' ) );
			if ( '' !== $src ) {
				$node['src'] = $src;
			}
			$alt = trim( $el->getAttribute( 'alt' ) );
			if ( '' !== $alt ) {
				$node['element_settings'] = $node['element_settings'] ?? [];
				$node['element_settings']['altText'] = $alt;
			}
		}

		if ( in_array( $type, [ 'text-link', 'button' ], true ) ) {
			$href = trim( $el->getAttribute( 'href' ) );
			if ( '' !== $href ) {
				$target_blank = strtolower( $el->getAttribute( 'target' ) ) === '_blank';
				$node['element_settings'] = $node['element_settings'] ?? [];
				$node['element_settings']['link'] = self::build_link_setting( $href, $target_blank );
			}
		}

		if ( 'video' === $type ) {
			$src = trim( $el->getAttribute( 'src' ) );
			if ( '' !== $src ) {
				$node['element_settings'] = $node['element_settings'] ?? [];
				$node['element_settings']['videoUrl'] = $src;
			}
		}

		// Inline style → style_overrides.
		$style_attr = trim( $el->getAttribute( 'style' ) );
		if ( '' !== $style_attr ) {
			$style_overrides = self::parse_inline_styles( $style_attr, $path, $context );
			if ( ! empty( $style_overrides ) ) {
				$node['style_overrides'] = $style_overrides;
			}
		}

		// data-* attributes preserved in element_settings._attributes.
		$data_attrs = self::collect_data_attributes( $el );
		if ( ! empty( $data_attrs ) ) {
			$node['element_settings'] = $node['element_settings'] ?? [];
			$node['element_settings']['_attributes'] = $data_attrs;
		}

		// Recurse for children-bearing types.
		if ( ! in_array( $type, [ 'heading', 'text-basic', 'text-link', 'button', 'image', 'video', 'divider' ], true ) ) {
			$inner = self::process_children( $el, $path, $context );
			if ( ! empty( $inner ) ) {
				// Bricks hierarchy: <section>'s only valid child is <container>.
				// HTML naturally puts <div> right inside <section>; auto-wrap to
				// keep the schema valid without forcing the AI to write the
				// extra Bricks-specific layer.
				if ( 'section' === $type ) {
					$needs_wrap = false;
					foreach ( $inner as $child_node ) {
						if ( ( $child_node['type'] ?? '' ) !== 'container' ) {
							$needs_wrap = true;
							break;
						}
					}
					if ( $needs_wrap ) {
						$inner = [
							[
								'type'     => 'container',
								'label'    => 'Container',
								'children' => $inner,
							],
						];
					}
				}
				$node['children'] = $inner;
			} else {
				// v5.2.0: a layout-type element (block/div/etc) with no element
				// children but with direct text content (e.g. <div class="icon">🚛</div>
				// or <div class="badge">99+</div>) — synthesize a text-basic child
				// so the text actually renders. Without this, layout containers
				// silently drop their direct-text content.
				if ( in_array( $type, [ 'block', 'div', 'container' ], true ) ) {
					$direct_text = self::extract_direct_text( $el );
					if ( '' !== $direct_text ) {
						$node['children'] = [
							[
								'type'    => 'text-basic',
								'content' => $direct_text,
							],
						];
					}
				}
			}
		}

		return $node;
	}

	/**
	 * Build a Bricks `link` setting from an href.
	 *
	 * @param string $href
	 * @param bool   $new_tab
	 * @return array<string, mixed>
	 */
	private static function build_link_setting( string $href, bool $new_tab ): array {
		// Internal anchor (e.g. #contact).
		if ( str_starts_with( $href, '#' ) ) {
			return [
				'type' => 'external',
				'url'  => $href,
			];
		}
		$type = ( str_starts_with( $href, 'tel:' ) || str_starts_with( $href, 'mailto:' ) || str_contains( $href, '://' ) )
			? 'external'
			: 'external'; // Internal post lookup is the resolver's job; treat unknown as external.

		$out = [
			'type' => $type,
			'url'  => esc_url_raw( $href ),
		];
		if ( $new_tab ) {
			$out['newTab'] = true;
		}
		return $out;
	}

	/**
	 * Parse a CSS `style="..."` attribute into Bricks-shaped overrides.
	 *
	 * @param string              $style
	 * @param string              $path
	 * @param array<string,mixed> $context
	 * @return array<string, mixed>
	 */
	private static function parse_inline_styles( string $style, string $path, array &$context ): array {
		$overrides = [];

		foreach ( self::split_declarations( $style ) as $decl ) {
			$colon = strpos( $decl, ':' );
			if ( false === $colon ) {
				continue;
			}
			$prop  = strtolower( trim( substr( $decl, 0, $colon ) ) );
			$value = trim( substr( $decl, $colon + 1 ) );
			if ( '' === $prop || '' === $value ) {
				continue;
			}

			// Typography props nested in _typography.
			if ( in_array( $prop, self::TYPOGRAPHY_PROPS, true ) ) {
				if ( ! isset( $overrides['_typography'] ) ) {
					$overrides['_typography'] = [];
				}
				if ( 'color' === $prop ) {
					$overrides['_typography']['color'] = [ 'raw' => $value ];
				} else {
					$overrides['_typography'][ $prop ] = $value;
				}
				continue;
			}

			// Direct top-level mappings.
			if ( isset( self::TOP_LEVEL_PROPS[ $prop ] ) ) {
				$overrides[ self::TOP_LEVEL_PROPS[ $prop ] ] = $value;
				continue;
			}

			// Padding shorthands.
			if ( 'padding' === $prop ) {
				$overrides['_padding'] = self::expand_box_shorthand( $value );
				continue;
			}
			if ( str_starts_with( $prop, 'padding-' ) ) {
				$side = substr( $prop, strlen( 'padding-' ) );
				if ( ! isset( $overrides['_padding'] ) ) {
					$overrides['_padding'] = [];
				}
				$overrides['_padding'][ $side ] = $value;
				continue;
			}

			// Margin shorthands.
			if ( 'margin' === $prop ) {
				$overrides['_margin'] = self::expand_box_shorthand( $value );
				continue;
			}
			if ( str_starts_with( $prop, 'margin-' ) ) {
				$side = substr( $prop, strlen( 'margin-' ) );
				if ( ! isset( $overrides['_margin'] ) ) {
					$overrides['_margin'] = [];
				}
				$overrides['_margin'][ $side ] = $value;
				continue;
			}

			// Border-radius shorthand → per-side object.
			if ( 'border-radius' === $prop ) {
				$overrides['_border'] = $overrides['_border'] ?? [];
				$overrides['_border']['radius'] = self::expand_box_shorthand( $value );
				continue;
			}

			// Other border-* properties.
			if ( str_starts_with( $prop, 'border-' ) ) {
				$sub = substr( $prop, strlen( 'border-' ) );
				$overrides['_border'] = $overrides['_border'] ?? [];
				if ( 'color' === $sub ) {
					$overrides['_border']['color'] = [ 'raw' => $value ];
				} elseif ( 'width' === $sub ) {
					$overrides['_border']['width'] = self::expand_box_shorthand( $value );
				} elseif ( 'style' === $sub ) {
					$overrides['_border']['style'] = $value;
				} else {
					// border-top-color etc. — not handled at this granularity.
					$context['css_rules_dropped'][] = [
						'property'     => $prop,
						'value'        => $value,
						'element_path' => $path,
					];
				}
				continue;
			}

			// Background — handle gradient + solid color.
			if ( 'background' === $prop || 'background-color' === $prop || 'background-image' === $prop ) {
				$overrides['_background'] = $overrides['_background'] ?? [];
				if ( str_contains( $value, 'gradient(' ) ) {
					// Bricks needs different gradient shapes at element-level vs
					// class-level (the auto-class-pushdown moves these into a
					// generated class). Emit all three keys to satisfy both:
					//   - _background.image.gradient — element-level renderer
					//   - _gradient.{type,angle,colors[]} — class-level compiler
					//   - _background.color.raw — fallback solid + first-stop color
					$overrides['_background']['useGradient'] = true;
					$overrides['_background']['image']       = $overrides['_background']['image'] ?? [];
					$overrides['_background']['image']['gradient'] = $value;

					$parsed = self::parse_linear_gradient( $value );
					if ( null !== $parsed ) {
						if ( ! isset( $overrides['_background']['color'] ) && ! empty( $parsed['colors'] ) ) {
							$overrides['_background']['color'] = $parsed['colors'][0]['color'];
						}
						$overrides['_gradient'] = [
							'type'   => $parsed['type'],
							'angle'  => $parsed['angle'],
							'colors' => $parsed['colors'],
						];
					}
				} elseif ( 'background-image' === $prop ) {
					$overrides['_background']['image'] = $overrides['_background']['image'] ?? [];
					$overrides['_background']['image']['url'] = $value;
				} else {
					// Solid color.
					$overrides['_background']['color'] = [ 'raw' => $value ];
				}
				continue;
			}

			// Unrecognized property — log, drop.
			$context['css_rules_dropped'][] = [
				'property'     => $prop,
				'value'        => $value,
				'element_path' => $path,
			];
		}

		return $overrides;
	}

	/**
	 * Split a CSS declaration list at semicolons that aren't inside
	 * parentheses or quoted strings.
	 *
	 * @param string $style
	 * @return array<int, string>
	 */
	private static function split_declarations( string $style ): array {
		$out         = [];
		$buffer      = '';
		$paren_depth = 0;
		$quote       = '';
		$len         = strlen( $style );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $style[ $i ];

			if ( '' !== $quote ) {
				$buffer .= $ch;
				if ( '\\' === $ch && $i + 1 < $len ) {
					$buffer .= $style[ $i + 1 ];
					$i++;
					continue;
				}
				if ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
				$buffer .= $ch;
			} elseif ( '(' === $ch ) {
				$paren_depth++;
				$buffer .= $ch;
			} elseif ( ')' === $ch && $paren_depth > 0 ) {
				$paren_depth--;
				$buffer .= $ch;
			} elseif ( ';' === $ch && 0 === $paren_depth ) {
				$decl = trim( $buffer );
				if ( '' !== $decl ) {
					$out[] = $decl;
				}
				$buffer = '';
			} else {
				$buffer .= $ch;
			}
		}

		$tail = trim( $buffer );
		if ( '' !== $tail ) {
			$out[] = $tail;
		}

		return $out;
	}

	/**
	 * Expand a CSS box-shorthand value into a per-side object.
	 *
	 * @param string $value e.g. "1rem" or "10px 20px"
	 * @return array{top: string, right: string, bottom: string, left: string}
	 */
	private static function expand_box_shorthand( string $value ): array {
		$parts = preg_split( '/\s+/', trim( $value ) ) ?: [];
		switch ( count( $parts ) ) {
			case 1:
				return [ 'top' => $parts[0], 'right' => $parts[0], 'bottom' => $parts[0], 'left' => $parts[0] ];
			case 2:
				return [ 'top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[0], 'left' => $parts[1] ];
			case 3:
				return [ 'top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[2], 'left' => $parts[1] ];
			default:
				return [ 'top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[2], 'left' => $parts[3] ];
		}
	}

	/**
	 * Parse a `linear-gradient(...)` value into Bricks _gradient shape.
	 *
	 * Returns null when the value isn't a linear-gradient or can't be parsed.
	 * Output:
	 *   {
	 *     type: 'linear',
	 *     angle: '135',
	 *     colors: [
	 *       { id: 'g0', color: { raw: 'var(--primary)' }, stop: '0%' },
	 *       { id: 'g1', color: { raw: '#fff' },           stop: '100%' },
	 *     ]
	 *   }
	 *
	 * @param string $value
	 * @return array<string, mixed>|null
	 */
	private static function parse_linear_gradient( string $value ): ?array {
		$value = trim( $value );
		if ( ! preg_match( '/^linear-gradient\((.*)\)$/is', $value, $m ) ) {
			return null;
		}
		$inner = $m[1];

		// Top-level comma split, respecting parens (var(--x), rgba(...)).
		$parts = [];
		$buf   = '';
		$depth = 0;
		for ( $i = 0, $len = strlen( $inner ); $i < $len; $i++ ) {
			$ch = $inner[ $i ];
			if ( '(' === $ch ) {
				$depth++;
				$buf .= $ch;
			} elseif ( ')' === $ch ) {
				$depth--;
				$buf .= $ch;
			} elseif ( ',' === $ch && 0 === $depth ) {
				$parts[] = trim( $buf );
				$buf     = '';
			} else {
				$buf .= $ch;
			}
		}
		$tail = trim( $buf );
		if ( '' !== $tail ) {
			$parts[] = $tail;
		}

		if ( count( $parts ) < 2 ) {
			return null;
		}

		// First part may be the angle (e.g., "135deg") or the first color stop.
		$angle      = '180';
		$first_part = $parts[0];
		if ( preg_match( '/^([\-]?\d+(?:\.\d+)?)\s*deg\s*$/i', $first_part, $am ) ) {
			$angle = $am[1];
			array_shift( $parts );
		} elseif ( preg_match( '/^to\s+/i', $first_part ) ) {
			// "to right", "to top right" etc. — translate to a numeric default.
			$direction_map = [
				'to top'         => '0',
				'to top right'   => '45',
				'to right'       => '90',
				'to bottom right'=> '135',
				'to bottom'      => '180',
				'to bottom left' => '225',
				'to left'        => '270',
				'to top left'    => '315',
			];
			$key = strtolower( preg_replace( '/\s+/', ' ', trim( $first_part ) ) );
			if ( isset( $direction_map[ $key ] ) ) {
				$angle = $direction_map[ $key ];
			}
			array_shift( $parts );
		}

		$colors = [];
		foreach ( $parts as $idx => $part ) {
			// Each remaining part = "<color> [<stop>]". Split at last whitespace
			// so var(--x) stays intact.
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$stop_pos = strrpos( $part, ' ' );
			$has_stop = false !== $stop_pos
				&& preg_match( '/^[\d.\-]+%?$/', substr( $part, $stop_pos + 1 ) );
			if ( $has_stop ) {
				$color_part = trim( substr( $part, 0, $stop_pos ) );
				$stop       = trim( substr( $part, $stop_pos + 1 ) );
			} else {
				$color_part = $part;
				$stop       = $idx === 0 ? '0%' : '100%';
			}
			$colors[] = [
				'id'    => 'g' . $idx,
				'color' => [ 'raw' => $color_part ],
				'stop'  => $stop,
			];
		}

		if ( count( $colors ) < 2 ) {
			return null;
		}

		return [
			'type'   => 'linear',
			'angle'  => $angle,
			'colors' => $colors,
		];
	}

	/**
	 * Direct text content of a node (excluding text inside child elements).
	 *
	 * Used when we want only the bare text nodes that sit at this level,
	 * skipping any element children entirely. Layout containers (block/div)
	 * use this to detect "is there text I should hoist into a synthetic
	 * text-basic child?".
	 *
	 * @param DOMNode $node
	 * @return string
	 */
	private static function extract_direct_text( DOMNode $node ): string {
		$text = '';
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$text .= $child->textContent;
			}
		}
		return trim( $text );
	}

	/**
	 * Concatenated text content for a text-bearing element.
	 *
	 * v5.2.0: replacement for `extract_direct_text` when emitting content
	 * for heading / text-basic / text-link / button. Includes both direct
	 * text nodes AND the textContent of inline element children (spans,
	 * em, strong, etc.) so mixed-color or mixed-weight runs survive.
	 *
	 * Skips child elements that carry `data-bricks-element` — those are
	 * placeholders for rich elements that own their own rendering and
	 * shouldn't have their text leaked into the parent's content string.
	 *
	 * @param DOMNode $node
	 * @return string
	 */
	private static function extract_concatenated_text( DOMNode $node ): string {
		$parts = [];
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$parts[] = $child->textContent;
				continue;
			}
			if ( $child instanceof DOMElement ) {
				$rich_marker = trim( $child->getAttribute( 'data-bricks-element' ) );
				if ( '' !== $rich_marker ) {
					// Rich element placeholder — owns its own content; don't merge it
					// into the parent text run.
					continue;
				}
				$parts[] = $child->textContent;
			}
		}
		// Collapse runs of whitespace introduced by interleaved text + element
		// children (e.g., "Increase of <span>126</span> this month").
		$joined = implode( '', $parts );
		$joined = preg_replace( '/\s+/', ' ', $joined );
		return trim( (string) $joined );
	}

	/**
	 * Collect `data-*` attributes for preservation in element_settings.
	 *
	 * @param DOMElement $el
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function collect_data_attributes( DOMElement $el ): array {
		$out = [];
		if ( ! $el->hasAttributes() ) {
			return $out;
		}
		foreach ( $el->attributes as $attr ) {
			if ( str_starts_with( $attr->name, 'data-' ) ) {
				$out[] = [
					'name'  => sanitize_text_field( $attr->name ),
					'value' => sanitize_text_field( $attr->value ),
				];
			}
		}
		return $out;
	}

	/**
	 * Convert a class name (e.g. "hero__cta-primary") to a human-readable label.
	 *
	 * @param string $class_name
	 * @return string
	 */
	private static function label_from_class( string $class_name ): string {
		// Take the BEM element if present (after __); otherwise the whole thing.
		$candidate = $class_name;
		if ( str_contains( $class_name, '__' ) ) {
			$candidate = substr( $class_name, strpos( $class_name, '__' ) + 2 );
		}
		return self::humanize( $candidate );
	}

	/**
	 * Replace dashes/underscores with spaces and uppercase the first letter.
	 *
	 * @param string $value
	 * @return string
	 */
	private static function humanize( string $value ): string {
		$spaced = str_replace( [ '-', '_' ], ' ', $value );
		return ucfirst( trim( $spaced ) );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Rich-element builders (v5.2.0).
	//
	// Each method emits the full Bricks element shape — including any
	// required nested children that Bricks expects but HTML can't model
	// faithfully (e.g. accordion-item title-row + content-pane structure).
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Apply class + style + label common attributes to a rich-element node.
	 *
	 * Mirrors the per-element handling that build_node() does for normal
	 * tag-mapped elements. Returns the node with class_intent, label,
	 * style_overrides applied.
	 *
	 * @param array<string, mixed> $node
	 * @param DOMElement           $el
	 * @param string               $path
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	private static function apply_common_attrs( array $node, DOMElement $el, string $path, array &$context ): array {
		$class_attr = trim( $el->getAttribute( 'class' ) );
		if ( '' !== $class_attr ) {
			$class_list = preg_split( '/\s+/', $class_attr, -1, PREG_SPLIT_NO_EMPTY );
			if ( is_array( $class_list ) && ! empty( $class_list ) ) {
				foreach ( $class_list as $cls ) {
					$context['class_names_seen'][] = $cls;
				}
				$node['class_intent'] = $class_list[0];
				if ( count( $class_list ) > 1 ) {
					$extras                                  = array_slice( $class_list, 1 );
					$node['element_settings']                = $node['element_settings'] ?? [];
					$node['element_settings']['_cssClasses'] = implode( ' ', $extras );
				}
				$node['label'] = self::label_from_class( $class_list[0] );
			}
		}

		$style_attr = trim( $el->getAttribute( 'style' ) );
		if ( '' !== $style_attr ) {
			$style_overrides = self::parse_inline_styles( $style_attr, $path, $context );
			if ( ! empty( $style_overrides ) ) {
				$node['style_overrides'] = $style_overrides;
			}
		}

		return $node;
	}

	/**
	 * Pull a `data-bricks-{prefix}-{key}` attribute, falling back to a default.
	 */
	private static function attr( DOMElement $el, string $name, string $default = '' ): string {
		$value = trim( $el->getAttribute( $name ) );
		return '' !== $value ? $value : $default;
	}

	/**
	 * Build an `icon` Bricks element from a `<i data-bricks-element="icon" ...>`
	 * (or any tag with the right marker).
	 *
	 * Recognized data-bricks-* attributes:
	 * - data-bricks-icon-library  (themify | ionicons | fontawesome | bxs | …)
	 * - data-bricks-icon-name     (e.g. ti-truck, ion-ios-arrow-forward)
	 * - data-bricks-icon-size     (e.g. 24px, 1em)
	 * - data-bricks-icon-color    (CSS color or var(--*))
	 *
	 * @return array<string, mixed>
	 */
	private static function build_icon_node( DOMElement $el, string $path, array &$context ): array {
		$library = self::attr( $el, 'data-bricks-icon-library', 'themify' );
		$name    = self::attr( $el, 'data-bricks-icon-name', '' );
		$size    = self::attr( $el, 'data-bricks-icon-size', '' );
		$color   = self::attr( $el, 'data-bricks-icon-color', '' );

		$settings = [
			'icon' => [
				'library' => $library,
				'icon'    => $name,
			],
		];
		if ( '' !== $size ) {
			$settings['iconSize'] = $size;
		}

		$node = [
			'type'             => 'icon',
			'element_settings' => $settings,
		];
		if ( '' !== $color ) {
			$node['style_overrides']         = $node['style_overrides'] ?? [];
			$node['style_overrides']['_color'] = [ 'raw' => $color ];
		}

		// Optional: data-bricks-icon-link-href for clickable icons.
		$href = self::attr( $el, 'data-bricks-icon-link-href' );
		if ( '' !== $href ) {
			$new_tab                       = strtolower( self::attr( $el, 'data-bricks-icon-link-target' ) ) === '_blank';
			$settings['link']              = self::build_link_setting( $href, $new_tab );
			$node['element_settings']      = $settings;
		}

		return self::apply_common_attrs( $node, $el, $path, $context );
	}

	/**
	 * Build a `counter` Bricks element from a `<span data-bricks-element="counter" ...>`.
	 *
	 * Recognized data-bricks-* attributes:
	 * - data-bricks-count-to        (target number — required, e.g. 1951)
	 * - data-bricks-count-from      (start number, default 0)
	 * - data-bricks-count-prefix    (e.g. "$")
	 * - data-bricks-count-suffix    (e.g. "+", "%")
	 * - data-bricks-count-duration  (animation duration in ms, default Bricks default)
	 *
	 * @return array<string, mixed>
	 */
	private static function build_counter_node( DOMElement $el, string $path, array &$context ): array {
		$count_to = self::attr( $el, 'data-bricks-count-to', '0' );
		$settings = [ 'countTo' => $count_to ];

		foreach ( [ 'count-from' => 'countFrom', 'count-prefix' => 'prefix', 'count-suffix' => 'suffix', 'count-duration' => 'duration' ] as $attr_suffix => $bricks_key ) {
			$v = self::attr( $el, 'data-bricks-' . $attr_suffix );
			if ( '' !== $v ) {
				$settings[ $bricks_key ] = $v;
			}
		}

		$node = [
			'type'             => 'counter',
			'element_settings' => $settings,
		];

		return self::apply_common_attrs( $node, $el, $path, $context );
	}

	/**
	 * Build an `accordion-nested` Bricks element from
	 * `<div data-bricks-element="accordion-nested"><div data-bricks-accordion-title="Q1">A1</div>…</div>`.
	 *
	 * Each child div with `data-bricks-accordion-title="..."` becomes one
	 * accordion item. The plugin synthesizes the title-wrapper + content-wrapper
	 * structure Bricks needs (block > [block(_hidden=accordion-title-wrapper) >
	 * [heading + icon], block(_hidden=accordion-content-wrapper) > [text-basic]]).
	 *
	 * @return array<string, mixed>
	 */
	private static function build_accordion_nested_node( DOMElement $el, string $path, array &$context ): array {
		$items = [];
		$idx   = 0;

		foreach ( $el->childNodes as $child ) {
			if ( ! ( $child instanceof DOMElement ) ) {
				continue;
			}
			$title = trim( $child->getAttribute( 'data-bricks-accordion-title' ) );
			if ( '' === $title ) {
				continue;
			}
			$idx++;
			$item_path = $path . '/accordion-item[' . $idx . ']';
			$body_text = self::extract_concatenated_text( $child );

			$items[] = [
				'type'  => 'block',
				'label' => 'Accordion Item',
				'children' => [
					[
						'type'             => 'block',
						'label'            => 'Title',
						'element_settings' => [
							'_direction'      => 'row',
							'_justifyContent' => 'space-between',
							'_alignItems'     => 'center',
							'_hidden'         => [ '_cssClasses' => 'accordion-title-wrapper' ],
						],
						'children'         => [
							[
								'type'    => 'heading',
								'tag'     => 'h3',
								'content' => $title,
							],
							[
								'type'             => 'icon',
								'element_settings' => [
									'icon'             => [ 'library' => 'ionicons', 'icon' => 'ion-ios-arrow-forward' ],
									'iconSize'         => '1em',
									'isAccordionIcon'  => true,
								],
							],
						],
					],
					[
						'type'             => 'block',
						'label'            => 'Content',
						'element_settings' => [
							'_hidden' => [ '_cssClasses' => 'accordion-content-wrapper' ],
						],
						'children'         => [
							[
								'type'    => 'text-basic',
								'content' => '' !== $body_text ? $body_text : '',
							],
						],
					],
				],
			];
		}

		$node = [
			'type'     => 'accordion-nested',
			'children' => $items,
		];

		return self::apply_common_attrs( $node, $el, $path, $context );
	}

	/**
	 * Build a `tabs-nested` Bricks element from
	 * `<div data-bricks-element="tabs-nested"><div data-bricks-tab-label="Day 1">…content…</div>…</div>`.
	 *
	 * Bricks's tabs require TWO sibling blocks: a tab-menu (with .tab-title divs
	 * per label) and a tab-content (with .tab-pane blocks per content).
	 *
	 * @return array<string, mixed>
	 */
	private static function build_tabs_nested_node( DOMElement $el, string $path, array &$context ): array {
		$labels  = [];
		$contents = [];
		$idx     = 0;

		foreach ( $el->childNodes as $child ) {
			if ( ! ( $child instanceof DOMElement ) ) {
				continue;
			}
			$label = trim( $child->getAttribute( 'data-bricks-tab-label' ) );
			if ( '' === $label ) {
				continue;
			}
			$idx++;
			$labels[]   = $label;
			$contents[] = self::process_children( $child, $path . '/tab[' . $idx . ']', $context );
		}

		$tab_menu_children = [];
		foreach ( $labels as $label ) {
			$tab_menu_children[] = [
				'type'             => 'div',
				'element_settings' => [ '_hidden' => [ '_cssClasses' => 'tab-title' ] ],
				'children'         => [
					[ 'type' => 'text-basic', 'content' => $label ],
				],
			];
		}

		$tab_content_children = [];
		foreach ( $contents as $pane_kids ) {
			$tab_content_children[] = [
				'type'             => 'block',
				'element_settings' => [ '_hidden' => [ '_cssClasses' => 'tab-pane' ] ],
				'children'         => $pane_kids,
			];
		}

		$node = [
			'type'     => 'tabs-nested',
			'children' => [
				[
					'type'             => 'block',
					'label'            => 'Tab Menu',
					'element_settings' => [
						'_direction' => 'row',
						'_hidden'    => [ '_cssClasses' => 'tab-menu' ],
					],
					'children'         => $tab_menu_children,
				],
				[
					'type'             => 'block',
					'label'            => 'Tab Content',
					'element_settings' => [ '_hidden' => [ '_cssClasses' => 'tab-content' ] ],
					'children'         => $tab_content_children,
				],
			],
		];

		return self::apply_common_attrs( $node, $el, $path, $context );
	}

	/**
	 * Build a `slider-nested` Bricks element from
	 * `<div data-bricks-element="slider-nested" data-bricks-slider-autoplay="true">
	 *    <div data-bricks-slide>…content…</div>…
	 *  </div>`.
	 *
	 * Each `<div data-bricks-slide>` becomes one slide block (Bricks's expected
	 * shape). Slider-level attributes (autoplay/arrows/dots/perPage) move into
	 * element_settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function build_slider_nested_node( DOMElement $el, string $path, array &$context ): array {
		$slides = [];
		$idx    = 0;
		foreach ( $el->childNodes as $child ) {
			if ( ! ( $child instanceof DOMElement ) ) {
				continue;
			}
			$is_slide = $child->hasAttribute( 'data-bricks-slide' )
				|| trim( $child->getAttribute( 'data-bricks-element' ) ) === 'slide';
			if ( ! $is_slide ) {
				continue;
			}
			$idx++;
			$slide_kids = self::process_children( $child, $path . '/slide[' . $idx . ']', $context );
			$slides[]   = [
				'type'     => 'block',
				'label'    => 'Slide',
				'children' => $slide_kids,
			];
		}

		$settings = [];
		foreach ( [ 'autoplay' => 'autoplay', 'arrows' => 'arrows', 'dots' => 'dots', 'per-page' => 'perPage', 'gap' => 'gap', 'speed' => 'speed', 'loop' => 'loop' ] as $attr_suffix => $bricks_key ) {
			$v = self::attr( $el, 'data-bricks-slider-' . $attr_suffix );
			if ( '' !== $v ) {
				$settings[ $bricks_key ] = ( 'true' === strtolower( $v ) ) ? true : ( ( 'false' === strtolower( $v ) ) ? false : $v );
			}
		}

		$node = [
			'type'     => 'slider-nested',
			'children' => $slides,
		];
		if ( ! empty( $settings ) ) {
			$node['element_settings'] = $settings;
		}

		return self::apply_common_attrs( $node, $el, $path, $context );
	}
}
