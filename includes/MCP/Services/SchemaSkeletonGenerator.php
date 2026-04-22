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
	// generate() removed — was legacy fallback for description-only mode.
	// Only generate_from_plan() is used by ProposalService.

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

		// NEW: use_pattern branch.
		if ( ! empty( $plan['use_pattern'] ) ) {
			return $this->generate_from_pattern(
				$page_id,
				(string) $plan['use_pattern'],
				(array) ( $plan['content_map'] ?? [] ),
				$suggested_classes,
				$scoped_variables
			);
		}

		$elements     = $plan['elements'] ?? [];
		$patterns_def = $plan['patterns'] ?? [];
		$roles        = $this->map_classes_to_roles( $suggested_classes );

		// v3.28.0: plan-level variant becomes the default modifier for elements
		// whose structured class_intent omits an explicit modifier.
		$default_modifier = isset( $plan['variant'] ) && is_string( $plan['variant'] ) ? trim( $plan['variant'] ) : '';

		// Build element nodes from the plan's element list.
		$content_nodes = [];
		foreach ( $elements as $el ) {
			$content_nodes[] = $this->build_plan_element( $el, $roles, false, $default_modifier );
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
				$pat_children[] = $this->build_plan_element( $pel, $roles, true, $default_modifier );
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
				// Pricing: emit sequential refs preserving card order.
				// Featured card uses the variant with accent border.
				//
				// Featured-index rules:
				//   repeat=1 → no featured card (solo tier, featured is pointless)
				//   repeat=2 → second card featured (left = standard, right = upgrade)
				//   repeat=3+ → middle card featured (center tier is conventional for pricing)
				//
				// Previous `floor(pat_repeat / 2)` selected index 0 for repeat=1
				// (featured solo card — meaningless) and index 1 for repeat=2 (arbitrary
				// but preserved). The new logic makes the solo case non-featured.
				if ( 1 === $pat_repeat ) {
					$middle_idx = -1; // sentinel: no featured card
				} elseif ( 2 === $pat_repeat ) {
					$middle_idx = 1;  // second of two
				} else {
					$middle_idx = (int) floor( $pat_repeat / 2 );
				}
				$before     = [];
				$middle     = null;
				$after      = [];
				for ( $i = 1; $i <= $pat_repeat; $i++ ) {
					$is_featured = ( $middle_idx >= 0 && $i - 1 === $middle_idx );
					$item        = $this->make_pattern_item( $pat_elements, $pat_hint, $i, $section_type, $is_featured );
					if ( $middle_idx >= 0 && $i - 1 < $middle_idx ) {
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
				if ( null !== $middle ) {
					$pattern_refs[] = [ 'ref' => $featured_pattern_name, 'repeat' => 1, 'data' => [ $middle ] ];
				}
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

		// Pattern matching removed (Task 5.7): non-pattern path emits a plain skeleton.
		$matched_pattern = null;

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
				$right_nodes[] = $this->node( 'text-basic' );
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
			$section_overrides['_minHeight']     = BriefResolver::get_instance()->get( 'hero_min_height' );
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
				$right_nodes[] = $this->node( 'text-basic' );
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
	 *
	 * @param array  $el               Element definition from the design plan.
	 * @param array  $roles            Role → class name map for this section.
	 * @param bool   $is_pattern       True when building a pattern child element.
	 * @param string $default_modifier Plan-level variant to use as BEM modifier when
	 *                                 a structured class_intent omits its own modifier.
	 *                                 Only applies to structured (array) intents; loose
	 *                                 strings are left unchanged.
	 */
	private function build_plan_element( array $el, array $roles, bool $is_pattern = false, string $default_modifier = '' ): array {
		$type         = $el['type'] ?? 'text-basic';
		$role         = $el['role'] ?? '';
		$tag          = $el['tag'] ?? null;
		$class_intent = $el['class_intent'] ?? null;

		// Auto-assign class from role if not explicitly set.
		if ( null === $class_intent ) {
			$class_intent = $this->role_to_class( $role, $roles );
		}

		// v3.28.0: apply plan-level variant as default modifier when a structured
		// class_intent doesn't specify its own modifier. Loose strings are opaque
		// (positional-only parsing) and are intentionally left unchanged.
		if ( $default_modifier !== '' && is_array( $class_intent ) && ! isset( $class_intent['modifier'] ) ) {
			$class_intent['modifier'] = $default_modifier;
		}

		$props = [];

		// v3.28.6: surface role on emitted schema so BuildStructureHandler can
		// tag elements with `label = role` pre-delegation. This lets
		// populate_content resolve content_map roles → element IDs post-build
		// without relying on class_intent name matching.
		if ( $role !== '' ) {
			$props['role'] = $role;
		}
		if ( null !== $tag ) {
			$props['tag'] = $tag;
		}
		if ( null !== $class_intent ) {
			$props['class_intent'] = $class_intent;
		}

		if ( 'form' === $type ) {
			$props['form_type'] = FormTypeDetector::detect( $role );
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
		if ( $is_featured && 'pricing' === $section_type ) {
			$item['_featured'] = true;
		}
		return $item;
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

	// All 8 skeleton_* methods removed (hero, hero_split, features, cta,
	// pricing, testimonials, split, generic). They were only called by the
	// deleted generate() method. generate_from_plan() builds schemas
	// directly from the AI's design_plan without hardcoded skeletons.

	// ──────────────────────────────────────────────
	// Pattern-driven generation
	// ──────────────────────────────────────────────

	/**
	 * Generate schema by adapting a pattern + content_map.
	 *
	 * @param int                   $page_id          Target page ID.
	 * @param string                $pattern_id       Pattern ID to look up.
	 * @param array<string, mixed>  $content_map      role => content value map.
	 * @param array<string, string> $suggested_classes class_name => class_id map.
	 * @param array<string, array>  $scoped_variables  category => variable names.
	 * @return array<string, mixed>
	 */
	private function generate_from_pattern( int $page_id, string $pattern_id, array $content_map, array $suggested_classes, array $scoped_variables ): array {
		$pattern = DesignPatternService::get( $pattern_id );
		if ( null === $pattern ) {
			return [
				'error'   => 'pattern_not_found',
				'message' => sprintf( 'Pattern "%s" does not exist. Call design_pattern(action: "list") for valid IDs.', $pattern_id ),
			];
		}

		// Site compatibility: create missing classes + variables before building.
		$core    = new BricksCore( new ElementNormalizer( new ElementIdGenerator() ) );
		$classes = new GlobalClassService( $core );
		$vars    = new GlobalVariableService( $core );

		foreach ( $pattern['classes'] ?? [] as $name => $def ) {
			if ( ! $classes->exists_by_name( $name ) ) {
				$classes->create_from_payload( $def );
			}
		}
		foreach ( $pattern['variables'] ?? [] as $name => $def ) {
			if ( ! $vars->exists( $name ) ) {
				$vars->create_from_payload( $name, $def );
			}
		}

		// Adapt.
		$adapter = new PatternAdapter( new PatternCatalog() );
		$adapted = $adapter->adapt( $pattern, $content_map );
		if ( isset( $adapted['error'] ) ) {
			return $adapted;
		}

		// Inject content into adapted structure.
		$with_content = $this->inject_content_map( $adapted['structure'], $content_map );

		return [
			'pattern_id'     => $pattern_id,
			'structure'      => $with_content,
			'adaptation_log' => $adapted['adaptation_log'],
		];
	}

	/**
	 * Walk adapted structure; for each element with a role present in content_map,
	 * attach the content value to the appropriate field based on element type.
	 *
	 * @param array<string, mixed> $node        Element node (may have 'children').
	 * @param array<string, mixed> $content_map role => content value map.
	 * @return array<string, mixed> Node with content injected.
	 */
	private function inject_content_map( array $node, array $content_map ): array {
		$role = $node['role'] ?? null;
		if ( $role !== null && array_key_exists( $role, $content_map ) ) {
			$value = $content_map[ $role ];
			$type  = $node['type'] ?? '';

			if ( $type === 'button' ) {
				$node['label'] = is_array( $value ) ? ( $value['label'] ?? '' ) : (string) $value;
				if ( is_array( $value ) ) {
					if ( isset( $value['link'] ) ) { $node['link'] = $value['link']; }
					if ( isset( $value['icon'] ) ) { $node['icon'] = $value['icon']; }
				}
			} elseif ( in_array( $type, [ 'heading', 'text-basic', 'text' ], true ) ) {
				$node['content'] = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
			} elseif ( $type === 'image' ) {
				if ( is_array( $value ) && isset( $value['url'] ) ) {
					$node['src'] = $value['url'];
				} elseif ( is_string( $value ) ) {
					$node['src'] = $value;
				}
			} else {
				$node['content'] = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
			}
		}
		if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
			$node['children'] = array_map( fn( $c ) => is_array( $c ) ? $this->inject_content_map( $c, $content_map ) : $c, $node['children'] );
		}
		return $node;
	}
}
