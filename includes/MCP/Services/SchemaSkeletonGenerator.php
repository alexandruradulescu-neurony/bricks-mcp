<?php
/**
 * Schema skeleton generator.
 *
 * Generates a ready-to-use design schema skeleton from a natural language
 * description and resolved site data. The AI reviews the skeleton, adjusts
 * content, and passes it directly to build_from_schema.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SchemaSkeletonGenerator {

	/**
	 * Generate a complete schema skeleton from a description.
	 *
	 * @param int                    $page_id           Target page ID.
	 * @param string                 $description       Free-text description.
	 * @param array<string, string>  $suggested_classes  class_name => class_id map.
	 * @param array<string, array>   $scoped_variables   category => variable names.
	 * @return array<string, mixed> Complete schema ready for build_from_schema.
	 */
	public function generate( int $page_id, string $description, array $suggested_classes, array $scoped_variables ): array {
		$desc = strtolower( $description );

		$section_type = $this->detect_section_type( $desc );
		$is_dark      = $this->is_dark( $desc );
		$is_split     = $this->is_split( $desc );
		$has_cards    = (bool) preg_match( '/card|stat|feature box/', $desc );
		$has_image    = (bool) preg_match( '/image|photo|picture|thumbnail/', $desc );
		$has_pills    = (bool) preg_match( '/pill|tag|badge/', $desc );
		$roles        = $this->map_classes_to_roles( $suggested_classes );

		$result = match ( $section_type ) {
			'hero'         => $is_split
				? $this->skeleton_hero_split( $roles, $is_dark, $has_cards, $has_image, $has_pills )
				: $this->skeleton_hero( $roles, $is_dark ),
			'features'     => $this->skeleton_features( $roles, $is_dark ),
			'cta'          => $this->skeleton_cta( $roles, $is_dark ),
			'pricing'      => $this->skeleton_pricing( $roles ),
			'testimonials' => $this->skeleton_testimonials( $roles ),
			'split'        => $this->skeleton_split( $roles, $is_dark, $has_image ),
			default        => $this->skeleton_generic( $roles, $is_dark ),
		};

		$schema = [
			'target'         => [ 'page_id' => $page_id, 'action' => 'append' ],
			'design_context' => [ 'summary' => $description, 'spacing' => 'normal' ],
			'sections'       => [ $result['section'] ],
		];

		if ( ! empty( $result['patterns'] ) ) {
			$schema['patterns'] = $result['patterns'];
		}

		return $schema;
	}

	// ──────────────────────────────────────────────
	// Section type detection
	// ──────────────────────────────────────────────

	private function detect_section_type( string $desc ): string {
		if ( preg_match( '/\bhero\b/', $desc ) ) {
			return 'hero';
		}
		if ( preg_match( '/pricing|price table|plan tier/', $desc ) ) {
			return 'pricing';
		}
		if ( preg_match( '/testimonial|review|customer quote/', $desc ) ) {
			return 'testimonials';
		}
		if ( preg_match( '/feature|service|benefit|advantage/', $desc ) ) {
			return 'features';
		}
		if ( preg_match( '/\bcta\b|call.to.action|final.*section/', $desc ) ) {
			return 'cta';
		}
		if ( preg_match( '/split|two.col|left.*right|50.50|60.40/', $desc ) ) {
			return 'split';
		}

		return 'generic';
	}

	private function is_dark( string $desc ): bool {
		return (bool) preg_match( '/dark|gradient|overlay/', $desc );
	}

	private function is_split( string $desc ): bool {
		return (bool) preg_match( '/split|two.col|left.*right|column|50.50|60.40|side/', $desc );
	}

	// ──────────────────────────────────────────────
	// Class → role mapping
	// ──────────────────────────────────────────────

	/**
	 * Map suggested class names to semantic roles.
	 *
	 * @param array<string, string> $suggested class_name => class_id.
	 * @return array<string, string> role => class_name.
	 */
	private function map_classes_to_roles( array $suggested ): array {
		$roles = [];
		$names = array_keys( $suggested );

		foreach ( $names as $name ) {
			$n = strtolower( $name );

			if ( preg_match( '/eyebrow|tagline/', $n ) && ! isset( $roles['eyebrow'] ) ) {
				$roles['eyebrow'] = $name;
			}
			if ( preg_match( '/hero.*(desc|subtitle|sub)/', $n ) && ! isset( $roles['hero_description'] ) ) {
				$roles['hero_description'] = $name;
			}
			if ( preg_match( '/btn.*hero.*primary|hero.*btn.*primary/', $n ) && ! isset( $roles['btn_primary'] ) ) {
				$roles['btn_primary'] = $name;
			}
			if ( preg_match( '/btn.*hero.*ghost|hero.*btn.*(ghost|outline|secondary)/', $n ) && ! isset( $roles['btn_ghost'] ) ) {
				$roles['btn_ghost'] = $name;
			}
			if ( preg_match( '/btn.*primary/', $n ) && ! isset( $roles['btn_primary'] ) ) {
				$roles['btn_primary'] = $name;
			}
			if ( preg_match( '/btn.*(outline|ghost|secondary)/', $n ) && ! isset( $roles['btn_ghost'] ) ) {
				$roles['btn_ghost'] = $name;
			}
			if ( preg_match( '/tag.grid|pill.grid/', $n ) && ! isset( $roles['tag_grid'] ) ) {
				$roles['tag_grid'] = $name;
			}
			if ( preg_match( '/tag.pill|pill$/', $n ) && ! isset( $roles['tag_pill'] ) ) {
				$roles['tag_pill'] = $name;
			}
			if ( preg_match( '/tag.pill.icon|pill.icon/', $n ) && ! isset( $roles['tag_pill_icon'] ) ) {
				$roles['tag_pill_icon'] = $name;
			}
			if ( preg_match( '/stat.*card|feature.*card|card.*dark|card.*glass/', $n ) && ! isset( $roles['stat_card'] ) ) {
				$roles['stat_card'] = $name;
			}
			if ( preg_match( '/dark.*service|service.*card/', $n ) && ! isset( $roles['service_card'] ) ) {
				$roles['service_card'] = $name;
			}
			if ( preg_match( '/hero.*card.*image/', $n ) && ! isset( $roles['hero_image'] ) ) {
				$roles['hero_image'] = $name;
			}
			if ( preg_match( '/scroll.*indicator/', $n ) && ! isset( $roles['scroll_indicator'] ) ) {
				$roles['scroll_indicator'] = $name;
			}
		}

		return $roles;
	}

	/**
	 * Get class_intent for a role, or null if no class mapped.
	 */
	private function role( array $roles, string $role ): ?string {
		return $roles[ $role ] ?? null;
	}

	// ──────────────────────────────────────────────
	// Skeleton builders
	// ──────────────────────────────────────────────

	/**
	 * Build a node array, omitting null values.
	 */
	private function node( string $type, array $props = [], array $children = [] ): array {
		$node = [ 'type' => $type ];

		foreach ( $props as $key => $value ) {
			if ( null !== $value ) {
				$node[ $key ] = $value;
			}
		}

		if ( ! empty( $children ) ) {
			$node['children'] = $children;
		}

		return $node;
	}

	/**
	 * Horizontal row block (flex row).
	 */
	private function row( string $label, array $children, array $extra_overrides = [] ): array {
		$overrides = array_merge(
			[ '_direction' => 'row', '_gap' => 'var(--space-m)', '_flexWrap' => 'wrap' ],
			$extra_overrides
		);
		return $this->node( 'block', [ 'label' => $label, 'style_overrides' => $overrides ], $children );
	}

	/**
	 * Grid block (CSS grid).
	 */
	private function grid( int $columns, string $label, array $children, array $extra_overrides = [] ): array {
		$node = [
			'type'       => 'block',
			'layout'     => 'grid',
			'columns'    => $columns,
			'label'      => $label,
			'responsive' => [ 'tablet' => $columns > 2 ? 2 : 1, 'mobile' => 1 ],
			'children'   => $children,
		];
		if ( ! empty( $extra_overrides ) ) {
			$node['style_overrides'] = $extra_overrides;
		}
		return $node;
	}

	// ── Hero (centered) ──────────────────────────

	private function skeleton_hero( array $roles, bool $dark ): array {
		$children = [];

		$children[] = $this->node( 'text-basic', [
			'content'      => '[TAGLINE — e.g. uppercase eyebrow text]',
			'class_intent' => $this->role( $roles, 'eyebrow' ),
		] );

		$children[] = $this->node( 'heading', [
			'tag'     => 'h1',
			'content' => '[MAIN HEADING]',
		] );

		$children[] = $this->node( 'text-basic', [
			'content'      => '[SUBTITLE — 1-2 sentences describing the value proposition]',
			'class_intent' => $this->role( $roles, 'hero_description' ),
		] );

		$children[] = $this->row( 'CTA Buttons', [
			$this->node( 'button', [
				'content'      => '[PRIMARY CTA]',
				'class_intent' => $this->role( $roles, 'btn_primary' ),
			] ),
			$this->node( 'button', [
				'content'      => '[SECONDARY CTA]',
				'class_intent' => $this->role( $roles, 'btn_ghost' ),
			] ),
		] );

		$section = [
			'intent'    => 'Hero section',
			'structure' => $this->node( 'section', [
				'label'           => 'Hero',
				'style_overrides' => [ '_minHeight' => '80vh', '_justifyContent' => 'center' ],
			], [
				$this->node( 'container', [], $children ),
			] ),
		];

		if ( $dark ) {
			$section['background'] = 'dark';
		}

		return [ 'section' => $section, 'patterns' => [] ];
	}

	// ── Hero (split — 2 columns) ─────────────────

	private function skeleton_hero_split( array $roles, bool $dark, bool $has_cards, bool $has_image, bool $has_pills ): array {
		$patterns = [];

		// Left column: text content.
		$left_children = [];

		$left_children[] = $this->node( 'text-basic', [
			'content'      => '[TAGLINE]',
			'class_intent' => $this->role( $roles, 'eyebrow' ),
		] );

		$left_children[] = $this->node( 'heading', [
			'tag'     => 'h1',
			'content' => '[MAIN HEADING]',
		] );

		$left_children[] = $this->node( 'text-basic', [
			'content'      => '[SUBTITLE — 1-2 sentences]',
			'class_intent' => $this->role( $roles, 'hero_description' ),
		] );

		$left_children[] = $this->row( 'CTA Buttons', [
			$this->node( 'button', [
				'content'      => '[PRIMARY CTA]',
				'class_intent' => $this->role( $roles, 'btn_primary' ),
			] ),
			$this->node( 'button', [
				'content'      => '[SECONDARY CTA]',
				'class_intent' => $this->role( $roles, 'btn_ghost' ),
			] ),
		] );

		$left = $this->node( 'block', [ 'label' => 'Left — Hero Text' ], $left_children );

		// Right column: cards, image, pills, or placeholder.
		$right_children = [];

		if ( $has_cards ) {
			$card_class = $this->role( $roles, 'stat_card' ) ?? 'stat-card-glass';

			$patterns['stat-card'] = $this->node( 'block', [
				'class_intent'    => $card_class,
				'style_overrides' => [
					'_direction'  => 'row',
					'_alignItems' => 'center',
					'_gap'        => 'var(--space-m)',
					'_padding'    => [
						'top'    => 'var(--space-m)',
						'right'  => 'var(--space-m)',
						'bottom' => 'var(--space-m)',
						'left'   => 'var(--space-m)',
					],
					'_border' => [
						'radius' => [
							'top'    => 'var(--radius)',
							'right'  => 'var(--radius)',
							'bottom' => 'var(--radius)',
							'left'   => 'var(--radius)',
						],
					],
				],
			], [
				$this->node( 'block', [
					'label'           => 'Icon Circle',
					'style_overrides' => [
						'_width'          => '48px',
						'_height'         => '48px',
						'_minWidth'       => '48px',
						'_alignItems'     => 'center',
						'_justifyContent' => 'center',
						'_border'         => [
							'radius' => [
								'top'    => 'var(--radius-m)',
								'right'  => 'var(--radius-m)',
								'bottom' => 'var(--radius-m)',
								'left'   => 'var(--radius-m)',
							],
						],
						'_background' => [ 'color' => [ 'raw' => 'data.icon_bg' ] ],
					],
				], [
					$this->node( 'icon', [ 'icon' => 'data.icon' ] ),
				] ),
				$this->node( 'block', [ 'label' => 'Card Text' ], [
					$this->node( 'heading', [ 'tag' => 'h4', 'content' => 'data.title' ] ),
					$this->node( 'text-basic', [ 'content' => 'data.desc' ] ),
				] ),
			] );

			$right_children[] = [
				'ref'    => 'stat-card',
				'repeat' => 4,
				'data'   => [
					[ 'icon' => 'timer', 'icon_bg' => 'rgba(236, 78, 56, 0.2)', 'title' => '[STAT 1 TITLE]', 'desc' => '[STAT 1 DESC]' ],
					[ 'icon' => 'shield', 'icon_bg' => 'rgba(128, 90, 213, 0.2)', 'title' => '[STAT 2 TITLE]', 'desc' => '[STAT 2 DESC]' ],
					[ 'icon' => 'settings', 'icon_bg' => 'rgba(56, 152, 236, 0.2)', 'title' => '[STAT 3 TITLE]', 'desc' => '[STAT 3 DESC]' ],
					[ 'icon' => 'car', 'icon_bg' => 'rgba(94, 210, 112, 0.2)', 'title' => '[STAT 4 TITLE]', 'desc' => '[STAT 4 DESC]' ],
				],
			];
		} elseif ( $has_image ) {
			$right_children[] = $this->node( 'image', [
				'src'             => 'unsplash:[RELEVANT QUERY]',
				'style_overrides' => [
					'_border' => [
						'radius' => [
							'top'    => 'var(--radius-l)',
							'right'  => 'var(--radius-l)',
							'bottom' => 'var(--radius-l)',
							'left'   => 'var(--radius-l)',
						],
					],
				],
			] );
		} elseif ( $has_pills ) {
			$pill_class      = $this->role( $roles, 'tag_pill' ) ?? 'tag-pill';
			$pill_icon_class = $this->role( $roles, 'tag_pill_icon' ) ?? 'tag-pill-icon';
			$grid_class      = $this->role( $roles, 'tag_grid' ) ?? 'tag-grid';

			$patterns['pill'] = $this->node( 'block', [ 'class_intent' => $pill_class ], [
				$this->node( 'icon', [ 'icon' => 'data.icon', 'class_intent' => $pill_icon_class ] ),
				$this->node( 'text-basic', [ 'content' => 'data.label' ] ),
			] );

			$right_children[] = $this->node( 'block', [ 'class_intent' => $grid_class, 'label' => 'Tag Grid' ], [
				[
					'ref'    => 'pill',
					'repeat' => 6,
					'data'   => [
						[ 'icon' => 'star', 'label' => '[TAG 1]' ],
						[ 'icon' => 'check', 'label' => '[TAG 2]' ],
						[ 'icon' => 'bolt-alt', 'label' => '[TAG 3]' ],
						[ 'icon' => 'truck', 'label' => '[TAG 4]' ],
						[ 'icon' => 'shield', 'label' => '[TAG 5]' ],
						[ 'icon' => 'settings', 'label' => '[TAG 6]' ],
					],
				],
			] );
		} else {
			$right_children[] = $this->node( 'text-basic', [ 'content' => '[RIGHT COLUMN CONTENT]' ] );
		}

		$right = $this->node( 'block', [ 'label' => 'Right — Visual' ], $right_children );

		// Build the grid.
		$grid = [
			'type'                => 'block',
			'layout'              => 'grid',
			'columns'             => 2,
			'label'               => 'Hero Grid',
			'style_overrides'     => [ '_gridTemplateColumns' => 'var(--grid-3-2)', '_alignItems' => 'center' ],
			'responsive'          => [ 'tablet' => 1, 'mobile' => 1 ],
			'children'            => [ $left, $right ],
		];

		$section = [
			'intent'    => 'Hero section with split layout',
			'structure' => $this->node( 'section', [
				'label'           => 'Hero',
				'style_overrides' => [ '_minHeight' => '80vh', '_justifyContent' => 'center' ],
			], [
				$this->node( 'container', [ 'label' => 'Hero Content' ], [ $grid ] ),
			] ),
		];

		if ( $dark ) {
			$section['background'] = 'dark';
		}

		return [ 'section' => $section, 'patterns' => $patterns ];
	}

	// ── Features / Services ──────────────────────

	private function skeleton_features( array $roles, bool $dark ): array {
		$patterns = [];

		$card_class = $this->role( $roles, 'service_card' )
			?? $this->role( $roles, 'stat_card' )
			?? 'feature-card';

		$patterns['feature-card'] = $this->node( 'block', [
			'class_intent'    => $card_class,
			'style_overrides' => [
				'_padding' => [
					'top'    => 'var(--space-l)',
					'right'  => 'var(--space-l)',
					'bottom' => 'var(--space-l)',
					'left'   => 'var(--space-l)',
				],
				'_border' => [
					'radius' => [
						'top'    => 'var(--radius)',
						'right'  => 'var(--radius)',
						'bottom' => 'var(--radius)',
						'left'   => 'var(--radius)',
					],
				],
			],
		], [
			$this->node( 'icon', [ 'icon' => 'data.icon' ] ),
			$this->node( 'heading', [ 'tag' => 'h3', 'content' => 'data.title' ] ),
			$this->node( 'text-basic', [ 'content' => 'data.desc' ] ),
		] );

		$section_children = [];

		$section_children[] = $this->node( 'text-basic', [
			'content'      => '[SECTION TAGLINE]',
			'class_intent' => $this->role( $roles, 'eyebrow' ),
		] );

		$section_children[] = $this->node( 'heading', [
			'tag'     => 'h2',
			'content' => '[SECTION HEADING]',
		] );

		$section_children[] = $this->node( 'text-basic', [
			'content' => '[SECTION DESCRIPTION — 1 sentence]',
		] );

		$section_children[] = $this->grid( 3, 'Features Grid', [
			[
				'ref'    => 'feature-card',
				'repeat' => 3,
				'data'   => [
					[ 'icon' => 'star', 'title' => '[FEATURE 1]', 'desc' => '[FEATURE 1 DESCRIPTION]' ],
					[ 'icon' => 'shield', 'title' => '[FEATURE 2]', 'desc' => '[FEATURE 2 DESCRIPTION]' ],
					[ 'icon' => 'bolt-alt', 'title' => '[FEATURE 3]', 'desc' => '[FEATURE 3 DESCRIPTION]' ],
				],
			],
		] );

		$section = [
			'intent'    => 'Features/services section',
			'structure' => $this->node( 'section', [ 'label' => 'Features' ], [
				$this->node( 'container', [], $section_children ),
			] ),
		];

		if ( $dark ) {
			$section['background'] = 'dark';
		}

		return [ 'section' => $section, 'patterns' => $patterns ];
	}

	// ── CTA ──────────────────────────────────────

	private function skeleton_cta( array $roles, bool $dark ): array {
		$children = [];

		$children[] = $this->node( 'heading', [
			'tag'     => 'h2',
			'content' => '[CTA HEADING]',
		] );

		$children[] = $this->node( 'text-basic', [
			'content' => '[CTA SUBTITLE]',
		] );

		$children[] = $this->row( 'CTA Buttons', [
			$this->node( 'button', [
				'content'      => '[PRIMARY CTA]',
				'class_intent' => $this->role( $roles, 'btn_primary' ),
			] ),
			$this->node( 'button', [
				'content'      => '[SECONDARY CTA]',
				'class_intent' => $this->role( $roles, 'btn_ghost' ),
			] ),
		], [ '_justifyContent' => 'center' ] );

		$section = [
			'intent'    => 'Call to action section',
			'structure' => $this->node( 'section', [ 'label' => 'CTA' ], [
				$this->node( 'container', [
					'style_overrides' => [ '_alignItems' => 'center', '_textAlign' => 'center' ],
				], $children ),
			] ),
		];

		if ( $dark ) {
			$section['background'] = 'dark';
		}

		return [ 'section' => $section, 'patterns' => [] ];
	}

	// ── Pricing ──────────────────────────────────

	private function skeleton_pricing( array $roles ): array {
		$patterns = [];

		$patterns['pricing-card'] = $this->node( 'block', [
			'class_intent'    => 'pricing-card',
			'style_overrides' => [
				'_padding' => [
					'top'    => 'var(--space-l)',
					'right'  => 'var(--space-l)',
					'bottom' => 'var(--space-l)',
					'left'   => 'var(--space-l)',
				],
				'_border' => [
					'radius' => [
						'top'    => 'var(--radius)',
						'right'  => 'var(--radius)',
						'bottom' => 'var(--radius)',
						'left'   => 'var(--radius)',
					],
				],
				'_alignItems' => 'center',
			],
		], [
			$this->node( 'text-basic', [ 'content' => 'data.tier_name' ] ),
			$this->node( 'heading', [ 'tag' => 'h3', 'content' => 'data.price' ] ),
			$this->node( 'text-basic', [ 'content' => 'data.features' ] ),
			$this->node( 'button', [
				'content'      => 'data.cta',
				'class_intent' => $this->role( $roles, 'btn_primary' ),
			] ),
		] );

		$section_children = [];

		$section_children[] = $this->node( 'text-basic', [
			'content'      => '[SECTION TAGLINE]',
			'class_intent' => $this->role( $roles, 'eyebrow' ),
		] );

		$section_children[] = $this->node( 'heading', [
			'tag'     => 'h2',
			'content' => '[PRICING HEADING]',
		] );

		$section_children[] = $this->grid( 3, 'Pricing Grid', [
			[
				'ref'    => 'pricing-card',
				'repeat' => 3,
				'data'   => [
					[ 'tier_name' => '[TIER 1 NAME]', 'price' => '[PRICE 1]', 'features' => '[FEATURES LIST 1]', 'cta' => '[CTA 1]' ],
					[ 'tier_name' => '[TIER 2 NAME]', 'price' => '[PRICE 2]', 'features' => '[FEATURES LIST 2]', 'cta' => '[CTA 2]' ],
					[ 'tier_name' => '[TIER 3 NAME]', 'price' => '[PRICE 3]', 'features' => '[FEATURES LIST 3]', 'cta' => '[CTA 3]' ],
				],
			],
		] );

		return [
			'section'  => [
				'intent'    => 'Pricing section',
				'structure' => $this->node( 'section', [ 'label' => 'Pricing' ], [
					$this->node( 'container', [], $section_children ),
				] ),
			],
			'patterns' => $patterns,
		];
	}

	// ── Testimonials ─────────────────────────────

	private function skeleton_testimonials( array $roles ): array {
		$patterns = [];

		$patterns['testimonial-card'] = $this->node( 'block', [
			'class_intent'    => 'testimonial-card',
			'style_overrides' => [
				'_padding' => [
					'top'    => 'var(--space-l)',
					'right'  => 'var(--space-l)',
					'bottom' => 'var(--space-l)',
					'left'   => 'var(--space-l)',
				],
				'_border' => [
					'radius' => [
						'top'    => 'var(--radius)',
						'right'  => 'var(--radius)',
						'bottom' => 'var(--radius)',
						'left'   => 'var(--radius)',
					],
				],
			],
		], [
			$this->node( 'text-basic', [ 'content' => 'data.quote' ] ),
			$this->node( 'text-basic', [ 'content' => 'data.author' ] ),
		] );

		$section_children = [];
		$section_children[] = $this->node( 'heading', [ 'tag' => 'h2', 'content' => '[TESTIMONIALS HEADING]' ] );
		$section_children[] = $this->grid( 3, 'Testimonials Grid', [
			[
				'ref'    => 'testimonial-card',
				'repeat' => 3,
				'data'   => [
					[ 'quote' => '[TESTIMONIAL 1]', 'author' => '[AUTHOR 1]' ],
					[ 'quote' => '[TESTIMONIAL 2]', 'author' => '[AUTHOR 2]' ],
					[ 'quote' => '[TESTIMONIAL 3]', 'author' => '[AUTHOR 3]' ],
				],
			],
		] );

		return [
			'section'  => [
				'intent'    => 'Testimonials section',
				'structure' => $this->node( 'section', [ 'label' => 'Testimonials' ], [
					$this->node( 'container', [], $section_children ),
				] ),
			],
			'patterns' => $patterns,
		];
	}

	// ── Split (generic 2-column) ─────────────────

	private function skeleton_split( array $roles, bool $dark, bool $has_image ): array {
		$left_children = [];
		$left_children[] = $this->node( 'text-basic', [
			'content'      => '[TAGLINE]',
			'class_intent' => $this->role( $roles, 'eyebrow' ),
		] );
		$left_children[] = $this->node( 'heading', [ 'tag' => 'h2', 'content' => '[HEADING]' ] );
		$left_children[] = $this->node( 'text-basic', [ 'content' => '[PARAGRAPH]' ] );

		$right_children = [];
		if ( $has_image ) {
			$right_children[] = $this->node( 'image', [
				'src'             => 'unsplash:[RELEVANT QUERY]',
				'style_overrides' => [
					'_border' => [
						'radius' => [
							'top'    => 'var(--radius-l)',
							'right'  => 'var(--radius-l)',
							'bottom' => 'var(--radius-l)',
							'left'   => 'var(--radius-l)',
						],
					],
				],
			] );
		} else {
			$right_children[] = $this->node( 'text-basic', [ 'content' => '[RIGHT COLUMN CONTENT]' ] );
		}

		$grid = $this->grid( 2, 'Split Grid', [
			$this->node( 'block', [ 'label' => 'Left Column' ], $left_children ),
			$this->node( 'block', [ 'label' => 'Right Column' ], $right_children ),
		] );

		$section = [
			'intent'    => 'Split content section',
			'structure' => $this->node( 'section', [ 'label' => 'Split Section' ], [
				$this->node( 'container', [], [ $grid ] ),
			] ),
		];

		if ( $dark ) {
			$section['background'] = 'dark';
		}

		return [ 'section' => $section, 'patterns' => [] ];
	}

	// ── Generic (fallback) ───────────────────────

	private function skeleton_generic( array $roles, bool $dark ): array {
		$children = [];

		$children[] = $this->node( 'text-basic', [
			'content'      => '[SECTION TAGLINE]',
			'class_intent' => $this->role( $roles, 'eyebrow' ),
		] );

		$children[] = $this->node( 'heading', [
			'tag'     => 'h2',
			'content' => '[SECTION HEADING]',
		] );

		$children[] = $this->node( 'text-basic', [
			'content' => '[SECTION CONTENT]',
		] );

		$section = [
			'intent'    => 'Content section',
			'structure' => $this->node( 'section', [ 'label' => 'Section' ], [
				$this->node( 'container', [], $children ),
			] ),
		];

		if ( $dark ) {
			$section['background'] = 'dark';
		}

		return [ 'section' => $section, 'patterns' => [] ];
	}
}
