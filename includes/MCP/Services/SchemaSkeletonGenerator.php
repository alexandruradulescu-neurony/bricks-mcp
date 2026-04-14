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

	// ================================================================
	// Plan-driven generation (Phase 2)
	// ================================================================

	/**
	 * Generate schema from a validated design_plan.
	 *
	 * Uses the AI's actual design decisions — section_type, layout, elements,
	 * patterns — instead of keyword matching.
	 *
	 * @param int                    $page_id           Target page ID.
	 * @param array<string, mixed>   $plan              Validated design_plan.
	 * @param array<string, string>  $suggested_classes  class_name => class_id map.
	 * @param array<string, array>   $scoped_variables   category => variable names.
	 * @return array<string, mixed> Complete schema ready for build_from_schema.
	 */
	public function generate_from_plan( int $page_id, array $plan, array $suggested_classes, array $scoped_variables ): array {
		$section_type = $plan['section_type'] ?? 'generic';
		$layout       = $plan['layout'] ?? 'centered';
		$background   = $plan['background'] ?? 'light';
		$elements     = $plan['elements'] ?? [];
		$patterns_def = $plan['patterns'] ?? [];
		$roles        = $this->map_classes_to_roles( $suggested_classes );

		// Build element nodes from the plan's element list.
		$content_nodes = [];
		foreach ( $elements as $el ) {
			$content_nodes[] = $this->build_plan_element( $el, $roles );
		}

		// Build pattern definitions.
		$schema_patterns = [];
		$pattern_refs    = [];
		foreach ( $patterns_def as $pat ) {
			$pat_name    = $pat['name'];
			$pat_repeat  = $pat['repeat'] ?? 3;
			$pat_hint    = $pat['content_hint'] ?? '';
			$pat_elements = $pat['element_structure'] ?? [];

			// Build pattern node.
			$pat_children = [];
			foreach ( $pat_elements as $pel ) {
				$pat_children[] = $this->build_plan_element( $pel, $roles, true );
			}

			$pat_class = $this->find_class_for_pattern( $pat_name, $roles );

			$schema_patterns[ $pat_name ] = $this->node( 'block', [
				'class_intent'    => $pat_class,
				'style_overrides' => [
					'_padding' => [
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
			], $pat_children );

			// For pricing sections with 3+ tiers, emit a featured variant for the middle card.
			// This matches the industry convention where the recommended/middle tier gets
			// a distinct visual treatment (highlighted border, featured badge).
			$featured_pattern_name = null;
			if ( 'pricing' === $section_type && $pat_repeat >= 3 ) {
				$featured_pattern_name                         = $pat_name . '-featured';
				$schema_patterns[ $featured_pattern_name ]     = $this->node( 'block', [
					'class_intent'    => $pat_class . '-featured',
					'style_overrides' => [
						'_padding' => [
							'top'    => 'var(--space-l)',
							'right'  => 'var(--space-l)',
							'bottom' => 'var(--space-l)',
							'left'   => 'var(--space-l)',
						],
						'_border' => [
							'width'  => [ 'top' => '2px', 'right' => '2px', 'bottom' => '2px', 'left' => '2px' ],
							'color'  => [ 'raw' => 'var(--secondary)' ],
							'radius' => [
								'top'    => 'var(--radius)',
								'right'  => 'var(--radius)',
								'bottom' => 'var(--radius)',
								'left'   => 'var(--radius)',
							],
						],
					],
				], $pat_children );
			}

			// Generate placeholder data + pattern refs.
			if ( null !== $featured_pattern_name ) {
				// Pricing: emit three sequential refs preserving card order.
				// Middle card uses the featured pattern variant with yellow accent border.
				$middle_idx = (int) floor( $pat_repeat / 2 );
				$before     = [];
				$middle     = null;
				$after      = [];
				for ( $i = 1; $i <= $pat_repeat; $i++ ) {
					$is_featured = ( $i - 1 === $middle_idx );
					$item        = $this->make_pattern_item( $pat_elements, $pat_hint, $i, $section_type, $is_featured );
					if ( $i - 1 < $middle_idx ) {
						$before[] = $item;
					} elseif ( $is_featured ) {
						$middle = $item;
					} else {
						$after[] = $item;
					}
				}
				if ( ! empty( $before ) ) {
					$pattern_refs[] = [ 'ref' => $pat_name, 'repeat' => count( $before ), 'data' => $before ];
				}
				$pattern_refs[] = [ 'ref' => $featured_pattern_name, 'repeat' => 1, 'data' => [ $middle ] ];
				if ( ! empty( $after ) ) {
					$pattern_refs[] = [ 'ref' => $pat_name, 'repeat' => count( $after ), 'data' => $after ];
				}
			} else {
				// Default: one ref with all items (no featured variant).
				$data_items = [];
				for ( $i = 1; $i <= $pat_repeat; $i++ ) {
					$data_items[] = $this->make_pattern_item( $pat_elements, $pat_hint, $i, $section_type, false );
				}
				$pattern_refs[] = [ 'ref' => $pat_name, 'repeat' => $pat_repeat, 'data' => $data_items ];
			}
		}

		// Try to find a matching design pattern for layout intelligence.
		$matched_pattern = $this->find_matching_pattern( $section_type, $layout, $background );

		// Check for multi-row patterns (e.g., hero with split + badge grid below).
		$is_multi_row = ! empty( $matched_pattern['has_two_rows'] ) && ! empty( $matched_pattern['rows'] );

		// Decide layout structure.
		$is_split = str_starts_with( $layout, 'split' );
		$is_grid  = str_starts_with( $layout, 'grid' );

		$section_children = [];

		if ( $is_multi_row && $matched_pattern ) {
			$section_children = $this->build_multi_row_layout( $matched_pattern, $content_nodes, $pattern_refs, $layout );
		} elseif ( $is_split ) {
			// Separate elements by role: image/visual types go right, everything else left.
			$left_nodes  = [];
			$right_nodes = [];

			foreach ( $content_nodes as $cn ) {
				$type = $cn['type'] ?? '';
				if ( 'image' === $type ) {
					$right_nodes[] = $cn;
				} else {
					$left_nodes[] = $cn;
				}
			}

			// Patterns go to the right column if left has content.
			if ( ! empty( $pattern_refs ) && ! empty( $left_nodes ) ) {
				foreach ( $pattern_refs as $pr ) {
					$right_nodes[] = $pr;
				}
			} elseif ( ! empty( $pattern_refs ) ) {
				foreach ( $pattern_refs as $pr ) {
					$right_nodes[] = $pr;
				}
			}

			if ( empty( $right_nodes ) ) {
				$right_nodes[] = $this->node( 'text-basic', [ 'content' => '[RIGHT COLUMN CONTENT]' ] );
			}

			$grid_template = 'split-60-40' === $layout ? 'var(--grid-3-2)' : 'var(--grid-2)';

			// Apply pattern column overrides if matched.
			$left_overrides  = [];
			$right_overrides = [];

			if ( $matched_pattern ) {
				$left_col  = $matched_pattern['columns']['left'] ?? [];
				$right_col = $matched_pattern['columns']['right'] ?? [];

				$left_overrides  = self::extract_column_overrides( $left_col );
				$right_overrides = self::extract_column_overrides( $right_col );
			} else {
				// Default gap for split content columns when no pattern matched.
				$left_overrides['_rowGap'] = 'var(--space-l)';
			}

			$left_props  = [ 'label' => 'Left Column' ];
			$right_props = [ 'label' => 'Right Column' ];
			if ( ! empty( $left_overrides ) ) {
				$left_props['style_overrides'] = $left_overrides;
			}
			if ( ! empty( $right_overrides ) ) {
				$right_props['style_overrides'] = $right_overrides;
			}

			$section_children[] = [
				'type'            => 'block',
				'layout'          => 'grid',
				'columns'         => 2,
				'label'           => 'Split Grid',
				'style_overrides' => [ '_gridTemplateColumns' => $grid_template, '_alignItems' => 'stretch' ],
				'responsive'      => [ 'tablet' => 1, 'mobile' => 1 ],
				'children'        => [
					$this->node( 'block', $left_props, $left_nodes ),
					$this->node( 'block', $right_props, $right_nodes ),
				],
			];
		} elseif ( $is_grid ) {
			$cols = (int) substr( $layout, -1 ); // grid-2 → 2, grid-3 → 3
			if ( $cols < 2 ) $cols = 3;

			// Content nodes before the grid (heading, tagline, etc.).
			$pre_grid  = [];
			$grid_items = [];

			foreach ( $content_nodes as $cn ) {
				$type = $cn['type'] ?? '';
				if ( in_array( $type, [ 'heading', 'text-basic' ], true ) && empty( $grid_items ) ) {
					$pre_grid[] = $cn;
				} else {
					$grid_items[] = $cn;
				}
			}

			// Add pattern refs as grid items.
			foreach ( $pattern_refs as $pr ) {
				$grid_items[] = $pr;
			}

			$section_children = $pre_grid;
			if ( ! empty( $grid_items ) ) {
				$section_children[] = $this->grid( $cols, ucfirst( $section_type ) . ' Grid', $grid_items );
			}
		} else {
			// Centered layout: all elements stacked, patterns at the end.
			$section_children = $content_nodes;
			foreach ( $pattern_refs as $pr ) {
				$section_children[] = $pr;
			}
		}

		// Auto-wrap consecutive buttons in a row block.
		$section_children = $this->auto_wrap_buttons( $section_children );

		// Wrap in section > container.
		// Start with pattern overrides, then layer plan-specific overrides on top.
		$section_overrides   = $matched_pattern['section_overrides'] ?? [];
		$container_overrides = $matched_pattern['container_overrides'] ?? [];

		// Hero default overrides (if no pattern matched).
		if ( 'hero' === $section_type && empty( $section_overrides ) ) {
			$section_overrides['_minHeight']     = '80vh';
			$section_overrides['_justifyContent'] = 'center';
		}

		// Background image from design_plan.
		$bg_image = $plan['background_image'] ?? '';
		if ( '' !== $bg_image ) {
			$section_overrides['_background'] = array_merge(
				$section_overrides['_background'] ?? [],
				[
					'image'    => [ 'url' => $bg_image ],
					'size'     => 'cover',
					'position' => 'center center',
				]
			);
			if ( 'dark' === $background ) {
				$brief   = BriefResolver::get_instance();
				$dark_bg = $brief->get( 'dark_bg_color' );
				$gradient = $matched_pattern['gradient_overlay'] ?? [
					'colors'  => [
						[ 'color' => [ 'raw' => $dark_bg ], 'stop' => '0' ],
						[ 'color' => [ 'raw' => $dark_bg ], 'stop' => '100' ],
					],
					'applyTo' => 'overlay',
				];
				$section_overrides['_gradient'] = $gradient;
			}
		}

		$container_props = [ 'label' => ucfirst( $section_type ) . ' Content' ];
		if ( ! empty( $container_overrides ) ) {
			$container_props['style_overrides'] = $container_overrides;
		}

		// Apply tinted background via style_overrides. Pipeline's own 'background'
		// key still handles dark mode (text coloring, overlay merging), so tinted
		// backgrounds flow in separately as an explicit _background.color.
		$bg_color_map = ProposalService::get_background_color_map();
		if ( isset( $bg_color_map[ $background ] ) ) {
			$section_overrides['_background'] = [
				'color' => [ 'raw' => $bg_color_map[ $background ] ],
			];
		}

		$section = [
			'intent'    => $plan['section_type'] . ' section',
			'structure' => $this->node( 'section', array_filter( [
				'label'           => ucfirst( $section_type ),
				'style_overrides' => ! empty( $section_overrides ) ? $section_overrides : null,
			] ), [
				$this->node( 'container', $container_props, $section_children ),
			] ),
		];

		if ( 'dark' === $background ) {
			$section['background'] = 'dark';
		}

		$schema = [
			'target'         => [ 'page_id' => $page_id, 'action' => 'append' ],
			'design_context' => [ 'summary' => $plan['section_type'] . ' section — ' . $layout, 'spacing' => 'normal' ],
			'sections'       => [ $section ],
		];

		if ( ! empty( $schema_patterns ) ) {
			$schema['patterns'] = $schema_patterns;
		}

		return $schema;
	}

	/**
	 * Find a matching design pattern for the given section type and layout.
	 *
	 * @param string $section_type Section type (hero, split, features, etc.)
	 * @param string $layout       Layout (centered, split-50-50, etc.)
	 * @param string $background   Background hint (dark, light).
	 * @return array|null Matched pattern or null.
	 */
	private function find_matching_pattern( string $section_type, string $layout, string $background ): ?array {
		$tags = [ $background ];
		if ( str_starts_with( $layout, 'split' ) ) {
			$tags[] = 'split';
		}
		if ( str_starts_with( $layout, 'grid' ) ) {
			$tags[] = 'grid';
		}
		if ( 'centered' === $layout ) {
			$tags[] = 'centered';
		}

		$matches = DesignPatternService::find( $section_type, $tags, 1 );
		return $matches[0] ?? null;
	}

	/**
	 * Extract style overrides from a pattern column definition.
	 *
	 * Handles: alignment (center-vertically), padding, gap, max_width, fill.
	 *
	 * @param array<string, mixed> $col Column definition from pattern.
	 * @return array<string, mixed> Style overrides for the column block.
	 */
	private static function extract_column_overrides( array $col ): array {
		$overrides = [];

		if ( 'center-vertically' === ( $col['alignment'] ?? '' ) ) {
			$overrides['_justifyContent'] = 'center';
		}

		if ( ! empty( $col['padding'] ) && is_array( $col['padding'] ) ) {
			$overrides['_padding'] = $col['padding'];
		}

		if ( ! empty( $col['gap'] ) ) {
			$overrides['_rowGap'] = $col['gap'];
		}

		if ( ! empty( $col['max_width'] ) ) {
			$overrides['_widthMax'] = $col['max_width'];
		}

		if ( ! empty( $col['fill'] ) ) {
			$overrides['_alignSelf'] = 'stretch';
		}

		return $overrides;
	}

	/**
	 * Build a multi-row layout from a pattern with has_two_rows.
	 *
	 * Row 1: typically a split grid (left content, right image).
	 * Row 2: typically a flat grid (badges, counters, etc.).
	 *
	 * @param array $pattern       Matched design pattern with 'rows' key.
	 * @param array $content_nodes Content element nodes from the plan.
	 * @param array $pattern_refs  Pattern repeat references.
	 * @param string $layout       Layout string (split-60-40, etc.).
	 * @return array<int, array> Section children (row blocks).
	 */
	private function build_multi_row_layout( array $pattern, array $content_nodes, array $pattern_refs, string $layout ): array {
		$rows    = $pattern['rows'] ?? [];
		$result  = [];

		// ── Row 1 ──
		$row1 = $rows['row_1'] ?? null;
		if ( $row1 && 'split' === ( $row1['type'] ?? '' ) ) {
			$grid_template = $row1['grid_template'] ?? ( 'split-60-40' === $layout ? 'var(--grid-3-2)' : 'var(--grid-2)' );

			// Distribute content_nodes: images → right, everything else → left.
			$left_nodes  = [];
			$right_nodes = [];
			foreach ( $content_nodes as $cn ) {
				if ( 'image' === ( $cn['type'] ?? '' ) ) {
					$right_nodes[] = $cn;
				} else {
					$left_nodes[] = $cn;
				}
			}

			if ( empty( $right_nodes ) ) {
				$right_nodes[] = $this->node( 'text-basic', [ 'content' => '[RIGHT COLUMN CONTENT]' ] );
			}

			// Apply column overrides from pattern.
			$left_col  = $row1['columns']['left'] ?? [];
			$right_col = $row1['columns']['right'] ?? [];

			$left_overrides  = self::extract_column_overrides( $left_col );
			$right_overrides = self::extract_column_overrides( $right_col );

			$left_props  = [ 'label' => 'Left Column' ];
			$right_props = [ 'label' => 'Right Column' ];
			if ( ! empty( $left_overrides ) ) {
				$left_props['style_overrides'] = $left_overrides;
			}
			if ( ! empty( $right_overrides ) ) {
				$right_props['style_overrides'] = $right_overrides;
			}

			$result[] = [
				'type'            => 'block',
				'layout'          => 'grid',
				'columns'         => 2,
				'label'           => 'Split Grid',
				'style_overrides' => [ '_gridTemplateColumns' => $grid_template, '_alignItems' => 'stretch' ],
				'responsive'      => [ 'tablet' => 1, 'mobile' => 1 ],
				'children'        => [
					$this->node( 'block', $left_props, $this->auto_wrap_buttons( $left_nodes ) ),
					$this->node( 'block', $right_props, $right_nodes ),
				],
			];
		}

		// ── Row 2 ──
		$row2 = $rows['row_2'] ?? null;
		if ( $row2 && 'grid' === ( $row2['type'] ?? '' ) ) {
			$cols        = $row2['columns'] ?? 4;
			$tag         = $row2['tag'] ?? null;
			$pat_name    = $row2['pattern_name'] ?? '';

			// Find matching pattern_ref for this row.
			$row2_children = [];
			if ( '' !== $pat_name ) {
				foreach ( $pattern_refs as $pr ) {
					if ( ( $pr['ref'] ?? '' ) === $pat_name ) {
						$row2_children[] = $pr;
						break;
					}
				}
			}

			// If no pattern matched, put remaining pattern_refs here.
			if ( empty( $row2_children ) && ! empty( $pattern_refs ) ) {
				$row2_children = $pattern_refs;
			}

			if ( ! empty( $row2_children ) ) {
				$grid_props = [ 'label' => 'Badge Grid' ];
				if ( null !== $tag ) {
					$grid_props['tag'] = $tag;
				}

				$result[] = $this->grid( $cols, $grid_props['label'], $row2_children, $tag ? [ 'tag' => $tag ] : [] );
			}
		}

		return $result;
	}

	/**
	 * Auto-wrap consecutive buttons in a row block.
	 *
	 * Scans children for runs of 2+ consecutive button elements and wraps
	 * them in a block with _direction: row.
	 *
	 * @param array<int, array> $children Child nodes.
	 * @return array<int, array> Children with button runs wrapped.
	 */
	private function auto_wrap_buttons( array $children ): array {
		$result      = [];
		$button_run  = [];

		foreach ( $children as $child ) {
			$is_button = ( $child['type'] ?? '' ) === 'button';

			if ( $is_button ) {
				$button_run[] = $child;
			} else {
				// Flush any accumulated buttons.
				if ( count( $button_run ) >= 2 ) {
					$result[] = $this->row( 'CTA Buttons', $button_run, [ '_justifyContent' => 'center' ] );
				} elseif ( ! empty( $button_run ) ) {
					// Single button — don't wrap.
					$result[] = $button_run[0];
				}
				$button_run = [];
				$result[]   = $child;
			}
		}

		// Flush remaining buttons.
		if ( count( $button_run ) >= 2 ) {
			$result[] = $this->row( 'CTA Buttons', $button_run, [ '_justifyContent' => 'center' ] );
		} elseif ( ! empty( $button_run ) ) {
			$result[] = $button_run[0];
		}

		return $result;
	}

	/**
	 * Build a single element node from a plan element.
	 */
	private function build_plan_element( array $el, array $roles, bool $is_pattern = false ): array {
		$type         = $el['type'] ?? 'text-basic';
		$role         = $el['role'] ?? '';
		$content_hint = $el['content_hint'] ?? '';
		$tag          = $el['tag'] ?? null;
		$class_intent = $el['class_intent'] ?? null;

		// Auto-assign class from role if not explicitly set.
		if ( null === $class_intent ) {
			$class_intent = $this->role_to_class( $role, $roles );
		}

		$props = [];

		if ( null !== $tag ) {
			$props['tag'] = $tag;
		}
		if ( null !== $class_intent ) {
			$props['class_intent'] = $class_intent;
		}

		// Content: use data substitution in patterns, placeholder otherwise.
		if ( in_array( $type, [ 'heading', 'text-basic', 'text-link', 'button' ], true ) ) {
			$props['content'] = $is_pattern ? "data.{$role}" : "[{$content_hint}]";
		}

		if ( 'icon' === $type ) {
			$props['icon'] = $is_pattern ? "data.{$role}" : 'star';
		}

		if ( 'image' === $type ) {
			$props['src'] = 'unsplash:[RELEVANT QUERY]';
		}

		if ( 'form' === $type ) {
			$props['form_type'] = FormTypeDetector::detect( $role . ' ' . $content_hint );
		}

		return $this->node( $type, $props );
	}

	/**
	 * Map a role name to a class from the roles map.
	 */
	private function role_to_class( string $role, array $roles ): ?string {
		$role_lower = strtolower( $role );

		// Direct role matches — class roles resolved from structured brief when available.
		$brief         = BriefResolver::get_instance();
		$eyebrow_role  = $brief->get( 'eyebrow_class' ) ?: 'eyebrow';
		$btn_pri_role  = $brief->get( 'btn_primary_class' ) ?: 'btn_primary';
		$btn_sec_role  = $brief->get( 'btn_secondary_class' ) ?: 'btn_ghost';

		$role_map = [
			'tagline'       => $eyebrow_role,
			'eyebrow'       => $eyebrow_role,
			'main_heading'  => null, // Headings don't usually need a class.
			'subtitle'      => 'hero_description',
			'description'   => 'hero_description',
			'primary_cta'   => $btn_pri_role,
			'secondary_cta' => $btn_sec_role,
		];

		foreach ( $role_map as $key => $mapped_role ) {
			if ( str_contains( $role_lower, $key ) && null !== $mapped_role ) {
				return $this->role( $roles, $mapped_role );
			}
		}

		return null;
	}

	/**
	 * Find a class for a pattern name from the roles map.
	 */
	private function find_class_for_pattern( string $pattern_name, array $roles ): ?string {
		$name = strtolower( $pattern_name );

		if ( str_contains( $name, 'stat' ) || str_contains( $name, 'card' ) ) {
			return $this->role( $roles, 'stat_card' ) ?? $pattern_name;
		}
		if ( str_contains( $name, 'feature' ) || str_contains( $name, 'service' ) ) {
			return $this->role( $roles, 'service_card' ) ?? $this->role( $roles, 'stat_card' ) ?? $pattern_name;
		}
		if ( str_contains( $name, 'testimonial' ) ) {
			return $pattern_name;
		}
		if ( str_contains( $name, 'pill' ) || str_contains( $name, 'tag' ) ) {
			return $this->role( $roles, 'tag_pill' ) ?? $pattern_name;
		}

		return $pattern_name;
	}

	/**
	 * Get a placeholder icon name by index.
	 */
	private function get_placeholder_icon( int $index ): string {
		$icons = [ 'star', 'shield', 'settings', 'truck', 'bolt-alt', 'timer', 'car', 'check', 'heart', 'location-pin' ];
		return $icons[ ( $index - 1 ) % count( $icons ) ];
	}

	/**
	 * Build a single pattern data item from the element structure.
	 *
	 * For pricing sections, items can be marked as featured — the hint gets
	 * an extra annotation and an explicit "_featured_badge" key is added so
	 * the AI knows to include "RECOMANDAT" / "FEATURED" marker content.
	 *
	 * @param array<int, array>   $pat_elements  Pattern element_structure.
	 * @param string              $pat_hint      Content hint for the whole pattern.
	 * @param int                 $index         1-based index of the repeated instance.
	 * @param string              $section_type  The section type (pricing, features, etc.).
	 * @param bool                $is_featured   True if this item is the featured middle item.
	 * @return array<string, mixed>
	 */
	private function make_pattern_item( array $pat_elements, string $pat_hint, int $index, string $section_type, bool $is_featured ): array {
		$item = [];
		foreach ( $pat_elements as $pel ) {
			$role = $pel['role'] ?? 'item';
			if ( 'icon' === ( $pel['type'] ?? '' ) || str_contains( $role, 'icon' ) ) {
				$item[ $role ] = $this->get_placeholder_icon( $index );
			} else {
				$annotation     = $is_featured ? ' (FEATURED / RECOMMENDED TIER)' : '';
				$item[ $role ]  = "[{$pat_hint}{$annotation} — ITEM {$index} " . strtoupper( $role ) . ']';
			}
		}
		if ( $is_featured && 'pricing' === $section_type ) {
			$item['_featured_badge'] = 'RECOMANDAT';
		}
		return $item;
	}

	// ================================================================
	// Legacy keyword-based generation (kept for backward compatibility)
	// ================================================================

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
