<?php
/**
 * Design-to-Bricks mapper service.
 *
 * Takes AI-generated section descriptions from a visual design analysis and
 * matches them against the site's existing global classes, color palettes,
 * saved patterns, and page summaries. Returns per-section mapping data
 * ready for Bricks Builder element creation.
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
 * DesignMapperService class.
 *
 * Maps design section descriptions to existing Bricks site assets:
 * global classes, color palettes, saved patterns, and page sections.
 */
final class DesignMapperService {

	/**
	 * Stop words excluded from keyword tokenization.
	 *
	 * @var array<int, string>
	 */
	private const STOP_WORDS = [
		'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
		'of', 'with', 'by', 'from', 'is', 'it', 'as', 'be', 'was', 'are',
		'has', 'had', 'not', 'this', 'that', 'its', 'can', 'will', 'may',
		'use', 'using', 'used', 'into', 'over', 'also', 'than', 'then',
		'each', 'all', 'any', 'some', 'such', 'very', 'just', 'about',
	];

	/**
	 * Background attribute keywords for dark themes.
	 *
	 * @var array<int, string>
	 */
	private const DARK_KEYWORDS = [ 'dark', 'inverse', 'night', 'black' ];

	/**
	 * Background attribute keywords for light themes.
	 *
	 * @var array<int, string>
	 */
	private const LIGHT_KEYWORDS = [ 'light', 'white', 'bright' ];

	/**
	 * Layout attribute keywords for split layouts.
	 *
	 * @var array<int, string>
	 */
	private const SPLIT_KEYWORDS = [ 'split', 'half', 'two-col' ];

	/**
	 * Layout attribute keywords for grid layouts.
	 *
	 * @var array<int, string>
	 */
	private const GRID_KEYWORDS = [ 'grid', 'col', 'column' ];

	/**
	 * Purpose groups for class categorization.
	 *
	 * Maps a purpose label to an array of keywords that indicate membership.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const PURPOSE_KEYWORDS = [
		'section'    => [ 'section', 'hero', 'banner', 'footer', 'header', 'cta', 'intro', 'feature', 'pricing' ],
		'layout'     => [ 'layout', 'grid', 'flex', 'col', 'row', 'split', 'half', 'stack', 'wrap', 'gap' ],
		'typography' => [ 'heading', 'title', 'text', 'font', 'label', 'caption', 'paragraph', 'body', 'display' ],
		'spacing'    => [ 'padding', 'margin', 'space', 'gap', 'offset', 'indent', 'narrow', 'wide' ],
		'media'      => [ 'image', 'img', 'video', 'media', 'photo', 'icon', 'illustration', 'avatar', 'logo' ],
		'card'       => [ 'card', 'tile', 'panel', 'box', 'item', 'block' ],
		'button'     => [ 'button', 'btn', 'cta', 'link', 'action' ],
		'container'  => [ 'container', 'wrapper', 'inner', 'outer', 'frame', 'content' ],
	];

	/**
	 * Content type to Bricks element name mapping.
	 *
	 * @var array<string, string>
	 */
	private const CONTENT_TYPE_MAP = [
		'heading'   => 'heading',
		'text'      => 'text-basic',
		'paragraph' => 'text-basic',
		'button'    => 'button',
		'image'     => 'image',
		'icon'      => 'icon',
		'card'      => 'block',
		'list'      => 'list',
		'form'      => 'form',
		'video'     => 'video',
		'accordion' => 'accordion',
		'tabs'      => 'tabs',
		'slider'    => 'slider',
	];

	/**
	 * Global classes from the site.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $classes;

	/**
	 * Color palettes from the site.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $palettes;

	/**
	 * Saved patterns from the site.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $patterns;

	/**
	 * Page summaries from the site.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $page_summaries;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $classes        Global class objects.
	 * @param array<int, array<string, mixed>> $palettes       Color palette objects.
	 * @param array<int, array<string, mixed>> $patterns       Saved pattern objects.
	 * @param array<int, array<string, mixed>> $page_summaries Page summary objects.
	 */
	public function __construct( array $classes, array $palettes, array $patterns, array $page_summaries ) {
		$this->classes        = $classes;
		$this->palettes       = $palettes;
		$this->patterns       = $patterns;
		$this->page_summaries = $page_summaries;
	}

	/**
	 * Map an array of section descriptions to site assets.
	 *
	 * @param array<int, array<string, mixed>> $sections Section descriptions from design analysis.
	 * @return array{sections: array, global_summary: array} Mapped results.
	 */
	public function map( array $sections ): array {
		$mapped_sections = [];

		foreach ( $sections as $section ) {
			$description   = (string) ( $section['description'] ?? '' );
			$background    = (string) ( $section['background'] ?? '' );
			$layout        = (string) ( $section['layout'] ?? '' );
			$content_types = (array) ( $section['content_types'] ?? [] );
			$columns       = (int) ( $section['columns'] ?? 0 );

			$tokens          = $this->tokenize( $description );
			$matched_classes = $this->match_classes( $tokens, $background, $layout );
			$matched_pats    = $this->match_patterns( $tokens, $layout, $content_types );
			$matched_colors  = $this->match_colors( $tokens, $background );
			$similar_pages   = $this->find_similar_pages( $tokens, $description );
			$skeleton        = $this->build_skeleton( $layout, $content_types, $columns );

			$notes = '';
			if ( ! empty( $similar_pages ) ) {
				$first = $similar_pages[0];
				$notes = sprintf(
					'Similar section found on page "%s" (ID %s) — review for reusable patterns.',
					$first['page_title'] ?? '',
					$first['post_id'] ?? ''
				);
			}

			$mapped_sections[] = [
				'input_description' => $description,
				'matched_patterns'  => $matched_pats,
				'suggested_classes' => $matched_classes,
				'suggested_colors'  => $matched_colors,
				'element_skeleton'  => $skeleton,
				'similar_existing'  => $similar_pages,
				'notes'             => $notes,
			];
		}

		return [
			'sections'       => $mapped_sections,
			'global_summary' => [
				'total_classes'  => count( $this->classes ),
				'total_patterns' => count( $this->patterns ),
				'total_pages'    => count( $this->page_summaries ),
			],
		];
	}

	/**
	 * Tokenize a description string into lowercase keywords.
	 *
	 * Strips stop words and tokens shorter than 3 characters.
	 *
	 * @param string $text Input text.
	 * @return array<int, string> Unique lowercase keyword tokens.
	 */
	private function tokenize( string $text ): array {
		$lower  = strtolower( $text );
		$words  = preg_split( '/[^a-z0-9-]+/', $lower, -1, PREG_SPLIT_NO_EMPTY );
		$tokens = [];

		foreach ( $words as $word ) {
			if ( strlen( $word ) < 3 ) {
				continue;
			}

			if ( in_array( $word, self::STOP_WORDS, true ) ) {
				continue;
			}

			$tokens[] = $word;
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Match global classes against section description.
	 *
	 * Groups matched classes by purpose (section, layout, typography, etc.).
	 *
	 * @param array<int, string> $tokens     Tokenized description keywords.
	 * @param string             $background Background attribute (dark/light).
	 * @param string             $layout     Layout attribute (split/grid/stacked).
	 * @return array<string, array<int, string>> Classes grouped by purpose.
	 */
	private function match_classes( array $tokens, string $background, string $layout ): array {
		$matched = [];

		foreach ( $this->classes as $class ) {
			$class_name = (string) ( $class['name'] ?? '' );

			if ( '' === $class_name ) {
				continue;
			}

			$class_lower = strtolower( $class_name );

			// Keyword substring match.
			$is_match = false;
			foreach ( $tokens as $token ) {
				if ( false !== strpos( $class_lower, $token ) ) {
					$is_match = true;
					break;
				}
			}

			// Background attribute match.
			if ( ! $is_match && '' !== $background ) {
				$bg_lower  = strtolower( $background );
				$bg_keys   = 'dark' === $bg_lower ? self::DARK_KEYWORDS : ( 'light' === $bg_lower ? self::LIGHT_KEYWORDS : [] );

				foreach ( $bg_keys as $bk ) {
					if ( false !== strpos( $class_lower, $bk ) ) {
						$is_match = true;
						break;
					}
				}
			}

			// Layout attribute match.
			if ( ! $is_match && '' !== $layout ) {
				$layout_lower = strtolower( $layout );
				$layout_keys  = [];

				if ( 'split' === $layout_lower ) {
					$layout_keys = self::SPLIT_KEYWORDS;
				} elseif ( 'grid' === $layout_lower ) {
					$layout_keys = self::GRID_KEYWORDS;
				}

				foreach ( $layout_keys as $lk ) {
					if ( false !== strpos( $class_lower, $lk ) ) {
						$is_match = true;
						break;
					}
				}
			}

			if ( $is_match ) {
				$matched[] = $class_name;
			}
		}

		return $this->group_classes_by_purpose( $matched );
	}

	/**
	 * Group class names by purpose using keyword-based categorization.
	 *
	 * @param array<int, string> $class_names Matched class names.
	 * @return array<string, array<int, string>> Classes grouped by purpose.
	 */
	private function group_classes_by_purpose( array $class_names ): array {
		$groups = [];

		foreach ( $class_names as $name ) {
			$name_lower = strtolower( $name );
			$placed     = false;

			foreach ( self::PURPOSE_KEYWORDS as $purpose => $keywords ) {
				foreach ( $keywords as $keyword ) {
					if ( false !== strpos( $name_lower, $keyword ) ) {
						$groups[ $purpose ][] = $name;
						$placed               = true;
						break 2;
					}
				}
			}

			if ( ! $placed ) {
				$groups['other'][] = $name;
			}
		}

		// Deduplicate within each group.
		foreach ( $groups as $purpose => $names ) {
			$groups[ $purpose ] = array_values( array_unique( $names ) );
		}

		return $groups;
	}

	/**
	 * Score and match saved patterns against a section description.
	 *
	 * Scoring: keyword overlap (0.4), layout match (0.3), content type overlap (0.3).
	 * Returns top 2 patterns with score >= 0.3.
	 *
	 * @param array<int, string> $tokens        Tokenized description keywords.
	 * @param string             $layout        Layout attribute.
	 * @param array<int, string> $content_types Content types present in the section.
	 * @return array<int, array<string, mixed>> Matched patterns with scores.
	 */
	private function match_patterns( array $tokens, string $layout, array $content_types ): array {
		if ( empty( $this->patterns ) || empty( $tokens ) ) {
			return [];
		}

		$scored = [];

		foreach ( $this->patterns as $pattern ) {
			$pattern_id      = (string) ( $pattern['id'] ?? '' );
			$pattern_name    = (string) ( $pattern['name'] ?? '' );
			$pattern_classes = (array) ( $pattern['classes'] ?? [] );
			$pattern_subtree = (array) ( $pattern['subtree'] ?? [] );

			// 1. Keyword overlap score (weight 0.4).
			$pattern_text  = strtolower( $pattern_name . ' ' . implode( ' ', $pattern_classes ) );
			$keyword_hits  = 0;
			$keyword_total = count( $tokens );

			foreach ( $tokens as $token ) {
				if ( false !== strpos( $pattern_text, $token ) ) {
					++$keyword_hits;
				}
			}

			$keyword_score = $keyword_total > 0 ? ( $keyword_hits / $keyword_total ) : 0.0;

			// 2. Layout match score (weight 0.3).
			$layout_score = 0.0;
			if ( '' !== $layout ) {
				$layout_lower = strtolower( $layout );
				$layout_check = [];

				if ( 'split' === $layout_lower ) {
					$layout_check = self::SPLIT_KEYWORDS;
				} elseif ( 'grid' === $layout_lower ) {
					$layout_check = self::GRID_KEYWORDS;
				} else {
					$layout_check = [ $layout_lower ];
				}

				foreach ( $layout_check as $lk ) {
					if ( false !== strpos( $pattern_text, $lk ) ) {
						$layout_score = 1.0;
						break;
					}
				}
			}

			// 3. Content type overlap score (weight 0.3).
			$content_score = 0.0;
			if ( ! empty( $content_types ) ) {
				$element_names = [];
				foreach ( $content_types as $ct ) {
					$ct_lower = strtolower( $ct );
					if ( isset( self::CONTENT_TYPE_MAP[ $ct_lower ] ) ) {
						$element_names[] = self::CONTENT_TYPE_MAP[ $ct_lower ];
					}
				}

				if ( ! empty( $element_names ) ) {
					$subtree_types = $this->extract_subtree_element_types( $pattern_subtree );
					$overlap       = array_intersect( $element_names, $subtree_types );
					$content_score = count( $element_names ) > 0
						? count( $overlap ) / count( $element_names )
						: 0.0;
				}
			}

			$total_score = ( $keyword_score * 0.4 ) + ( $layout_score * 0.3 ) + ( $content_score * 0.3 );

			if ( $total_score >= 0.3 ) {
				$scored[] = [
					'id'         => $pattern_id,
					'name'       => $pattern_name,
					'score'      => round( $total_score, 2 ),
					'how_to_use' => sprintf(
						'Use pattern "%s" as a starting point. It contains classes: %s.',
						$pattern_name,
						implode( ', ', array_slice( $pattern_classes, 0, 5 ) )
					),
				];
			}
		}

		// Sort by score descending.
		usort( $scored, static fn( array $a, array $b ) => $b['score'] <=> $a['score'] );

		return array_slice( $scored, 0, 2 );
	}

	/**
	 * Extract unique element type names from a pattern subtree.
	 *
	 * @param array<int, array<string, mixed>> $subtree Pattern subtree elements.
	 * @return array<int, string> Unique element type names.
	 */
	private function extract_subtree_element_types( array $subtree ): array {
		$types = [];

		foreach ( $subtree as $element ) {
			$name = (string) ( $element['name'] ?? '' );

			if ( '' !== $name ) {
				$types[] = $name;
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Match colors from palettes based on section attributes.
	 *
	 * Selects colors by background brightness, keyword matching, and always
	 * includes primary/accent colors. Returns max 5, deduplicated.
	 *
	 * @param array<int, string> $tokens     Tokenized description keywords.
	 * @param string             $background Background attribute (dark/light).
	 * @return array<int, array<string, mixed>> Matched color suggestions.
	 */
	private function match_colors( array $tokens, string $background ): array {
		$all_colors = $this->flatten_palette_colors();

		if ( empty( $all_colors ) ) {
			return [];
		}

		$matched = [];
		$seen    = [];

		// 1. Background-based brightness matching.
		$bg_lower = strtolower( $background );
		if ( 'dark' === $bg_lower || 'light' === $bg_lower ) {
			foreach ( $all_colors as $color ) {
				$hex = (string) ( $color['light'] ?? '' );

				if ( '' === $hex ) {
					continue;
				}

				$brightness = $this->hex_brightness( $hex );

				if ( 'dark' === $bg_lower && $brightness < 80 ) {
					$this->add_color_suggestion( $color, $matched, $seen );
				} elseif ( 'light' === $bg_lower && $brightness > 200 ) {
					$this->add_color_suggestion( $color, $matched, $seen );
				}
			}
		}

		// 2. Keyword matching against CSS variable names.
		foreach ( $all_colors as $color ) {
			$raw = strtolower( (string) ( $color['raw'] ?? '' ) );

			foreach ( $tokens as $token ) {
				if ( false !== strpos( $raw, $token ) ) {
					$this->add_color_suggestion( $color, $matched, $seen );
					break;
				}
			}
		}

		// 3. Always include primary/accent colors.
		foreach ( $all_colors as $color ) {
			$raw = strtolower( (string) ( $color['raw'] ?? '' ) );

			if ( false !== strpos( $raw, 'primary' ) || false !== strpos( $raw, 'accent' ) ) {
				$this->add_color_suggestion( $color, $matched, $seen );
			}
		}

		return array_slice( $matched, 0, 5 );
	}

	/**
	 * Flatten all palette colors into a single array.
	 *
	 * @return array<int, array<string, mixed>> All colors across all palettes.
	 */
	private function flatten_palette_colors(): array {
		$all = [];

		foreach ( $this->palettes as $palette ) {
			$colors = (array) ( $palette['colors'] ?? [] );

			foreach ( $colors as $color ) {
				$all[] = $color;
			}
		}

		return $all;
	}

	/**
	 * Add a color suggestion to the matched list if not already seen.
	 *
	 * @param array<string, mixed>                 $color   Color data.
	 * @param array<int, array<string, mixed>>     &$matched Accumulated matches (modified by reference).
	 * @param array<string, bool>                  &$seen   Seen color IDs (modified by reference).
	 */
	private function add_color_suggestion( array $color, array &$matched, array &$seen ): void {
		$id = (string) ( $color['id'] ?? '' );

		if ( '' === $id || isset( $seen[ $id ] ) ) {
			return;
		}

		$seen[ $id ] = true;

		$raw          = (string) ( $color['raw'] ?? '' );
		$display_name = $this->derive_display_name( $raw );
		$hex_value    = (string) ( $color['light'] ?? '' );

		$matched[] = [
			'name'     => $display_name,
			'value'    => $hex_value,
			'variable' => $raw,
		];
	}

	/**
	 * Derive a human-readable display name from a CSS variable reference.
	 *
	 * Strips `var(--` prefix and `)` suffix, then converts hyphens to spaces
	 * and title-cases the result.
	 *
	 * @param string $raw CSS variable reference (e.g., `var(--brand-primary)`).
	 * @return string Display name (e.g., "Brand Primary").
	 */
	private function derive_display_name( string $raw ): string {
		$name = $raw;

		// Strip var(-- prefix.
		if ( str_starts_with( $name, 'var(--' ) ) {
			$name = substr( $name, 6 );
		} elseif ( str_starts_with( $name, 'var(' ) ) {
			$name = substr( $name, 4 );
		}

		// Strip trailing parenthesis.
		$name = rtrim( $name, ')' );

		// Strip leading dashes.
		$name = ltrim( $name, '-' );

		// Convert hyphens to spaces and title-case.
		$name = str_replace( '-', ' ', $name );
		$name = ucwords( $name );

		return $name;
	}

	/**
	 * Calculate perceived brightness of a hex color.
	 *
	 * Uses the formula: (R*299 + G*587 + B*114) / 1000.
	 *
	 * @param string $hex Hex color value (e.g., "#1a2b3c" or "#fff").
	 * @return float Brightness value (0-255).
	 */
	private function hex_brightness( string $hex ): float {
		$hex = ltrim( $hex, '#' );

		// Expand shorthand hex.
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return 128.0;
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		return ( $r * 299 + $g * 587 + $b * 114 ) / 1000;
	}

	/**
	 * Find existing page sections similar to the given description.
	 *
	 * Splits page summaries into individual section labels and compares
	 * keyword overlap in both directions.
	 *
	 * @param array<int, string> $tokens      Tokenized description keywords.
	 * @param string             $description Raw section description.
	 * @return array<int, array<string, mixed>> Top 3 similar page sections.
	 */
	private function find_similar_pages( array $tokens, string $description ): array {
		if ( empty( $this->page_summaries ) || empty( $tokens ) ) {
			return [];
		}

		$candidates = [];

		foreach ( $this->page_summaries as $page ) {
			$post_id    = $page['id'] ?? 0;
			$page_title = (string) ( $page['title'] ?? '' );
			$summary    = (string) ( $page['summary'] ?? '' );

			// Split summary into individual section labels.
			$labels = array_map( 'trim', explode( ',', $summary ) );

			foreach ( $labels as $label ) {
				if ( '' === $label ) {
					continue;
				}

				$label_tokens = $this->tokenize( $label );

				if ( empty( $label_tokens ) ) {
					continue;
				}

				// Score: overlap in both directions.
				$desc_to_label = 0;
				foreach ( $tokens as $token ) {
					foreach ( $label_tokens as $lt ) {
						if ( false !== strpos( $lt, $token ) || false !== strpos( $token, $lt ) ) {
							++$desc_to_label;
							break;
						}
					}
				}

				$label_to_desc = 0;
				foreach ( $label_tokens as $lt ) {
					foreach ( $tokens as $token ) {
						if ( false !== strpos( $token, $lt ) || false !== strpos( $lt, $token ) ) {
							++$label_to_desc;
							break;
						}
					}
				}

				$total_possible = count( $tokens ) + count( $label_tokens );
				$relevance      = $total_possible > 0
					? ( $desc_to_label + $label_to_desc ) / $total_possible
					: 0.0;

				if ( $relevance > 0.0 ) {
					$candidates[] = [
						'post_id'       => $post_id,
						'page_title'    => $page_title,
						'section_label' => $label,
						'relevance'     => $relevance,
					];
				}
			}
		}

		// Sort by relevance descending.
		usort( $candidates, static fn( array $a, array $b ) => $b['relevance'] <=> $a['relevance'] );

		// Return top 3, stripping internal relevance score.
		$result = [];
		foreach ( array_slice( $candidates, 0, 3 ) as $candidate ) {
			$result[] = [
				'post_id'       => $candidate['post_id'],
				'page_title'    => $candidate['page_title'],
				'section_label' => $candidate['section_label'],
			];
		}

		return $result;
	}

	/**
	 * Build an element hierarchy skeleton string for a section.
	 *
	 * @param string             $layout        Layout type (split/grid/stacked/full-width).
	 * @param array<int, string> $content_types Content types in the section.
	 * @param int                $columns       Number of columns (for grid layouts).
	 * @return string Skeleton notation string.
	 */
	private function build_skeleton( string $layout, array $content_types, int $columns ): string {
		$layout_lower = strtolower( $layout );

		// Map content types to Bricks element names.
		$element_types = [];
		foreach ( $content_types as $ct ) {
			$ct_lower = strtolower( $ct );

			if ( isset( self::CONTENT_TYPE_MAP[ $ct_lower ] ) ) {
				$element_types[] = self::CONTENT_TYPE_MAP[ $ct_lower ];
			}
		}

		// If layout is unspecified, infer from content.
		if ( '' === $layout_lower || 'unspecified' === $layout_lower ) {
			$layout_lower = $this->infer_layout( $content_types, $columns );
		}

		$types_str = implode( ', ', $element_types );

		switch ( $layout_lower ) {
			case 'split':
				$non_image = array_filter(
					$element_types,
					static fn( string $t ) => 'image' !== $t
				);
				$content_str = ! empty( $non_image ) ? implode( ', ', $non_image ) : 'heading, text-basic, button';
				return 'section > container > block(content)[' . $content_str . '] + block(media)[image]';

			case 'grid':
				$card_types = array_filter(
					$element_types,
					static fn( string $t ) => ! in_array( $t, [ 'heading', 'text-basic' ], true )
				);
				$card_str   = ! empty( $card_types ) ? implode( ', ', $card_types ) : 'image, heading, text-basic';
				$count      = $columns > 0 ? $columns : 3;
				return 'section > container > block(intro)[heading, text-basic] + block(grid, tag:ul)[block(card, tag:li)[' . $card_str . '] x ' . $count . ']';

			case 'stacked':
				$body_types = ! empty( $types_str ) ? $types_str : 'heading, text-basic';
				return 'section > container > block(intro)[heading, text-basic] + ' . $body_types;

			case 'full-width':
				$body_types = ! empty( $types_str ) ? $types_str : 'heading, text-basic';
				return 'section > container > ' . $body_types;

			default:
				// Fallback: treat as stacked.
				$body_types = ! empty( $types_str ) ? $types_str : 'heading, text-basic';
				return 'section > container > ' . $body_types;
		}
	}

	/**
	 * Infer layout type from content types and column count.
	 *
	 * @param array<int, string> $content_types Content types.
	 * @param int                $columns       Number of columns.
	 * @return string Inferred layout type.
	 */
	private function infer_layout( array $content_types, int $columns ): string {
		$lower_types = array_map( 'strtolower', $content_types );

		$has_image = in_array( 'image', $lower_types, true );
		$has_other = false;

		foreach ( $lower_types as $type ) {
			if ( 'image' !== $type ) {
				$has_other = true;
				break;
			}
		}

		// Has image + other content = split layout.
		if ( $has_image && $has_other ) {
			return 'split';
		}

		// Multiple columns = grid layout.
		if ( $columns > 1 ) {
			return 'grid';
		}

		return 'stacked';
	}
}
