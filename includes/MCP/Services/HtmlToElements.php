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
		'article'    => 'section',
		'div'        => 'div',
		'aside'      => 'div',
		'nav'        => 'div',
		'figure'     => 'div',
		'li'         => 'div',

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
	 * HTML tags that are always skipped (their children are still walked).
	 *
	 * @var array<int, string>
	 */
	private const SKIP_TAGS_RECURSE = [ 'main', 'article', 'header', 'footer' ];

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
			$sections[] = [
				'intent'    => 'html_converted_loose_content',
				'structure' => [
					'type'     => 'section',
					'label'    => 'Converted Content',
					'children' => $loose,
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

			$mapped_type = self::TAG_MAP[ $tag ] ?? null;
			if ( null === $mapped_type ) {
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
		if ( in_array( $type, [ 'heading', 'text-basic', 'text-link', 'button' ], true ) ) {
			$text = self::extract_direct_text( $el );
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
				$node['children'] = $inner;
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
					// Bricks reads gradient at _background.image.gradient (verified empirically).
					$overrides['_background']['useGradient'] = true;
					$overrides['_background']['image']       = $overrides['_background']['image'] ?? [];
					$overrides['_background']['image']['gradient'] = $value;
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
	 * Direct text content of a node (excluding text inside child elements).
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
}
