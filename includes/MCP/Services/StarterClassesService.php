<?php
/**
 * Starter classes service.
 *
 * Provides a curated starter set of global class definitions for sites
 * that have no (or very few) global classes. Used by ProposalService
 * in the bootstrap_recommendation response.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StarterClassesService {

	/**
	 * Get the starter class definitions.
	 *
	 * Grouped by purpose: layout, typography, buttons, cards.
	 * Each class uses CSS variables for portability across sites.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_starter_classes(): array {
		return [
			// ── Layout: Grids with responsive collapse ──
			[
				'name'     => 'grid-2',
				'category' => 'layout',
				'settings' => [
					'_display'                             => 'grid',
					'_gridTemplateColumns'                 => 'var(--grid-2, repeat(2, 1fr))',
					'_gridGap'                             => 'var(--grid-gap, 24px)',
					'_gridTemplateColumns:tablet_portrait' => 'var(--grid-1, 1fr)',
				],
			],
			[
				'name'     => 'grid-3',
				'category' => 'layout',
				'settings' => [
					'_display'                             => 'grid',
					'_gridTemplateColumns'                 => 'var(--grid-3, repeat(3, 1fr))',
					'_gridGap'                             => 'var(--grid-gap, 24px)',
					'_gridTemplateColumns:tablet_portrait' => 'var(--grid-2, repeat(2, 1fr))',
					'_gridTemplateColumns:mobile'          => 'var(--grid-1, 1fr)',
				],
			],
			[
				'name'     => 'grid-4',
				'category' => 'layout',
				'settings' => [
					'_display'                             => 'grid',
					'_gridTemplateColumns'                 => 'var(--grid-4, repeat(4, 1fr))',
					'_gridGap'                             => 'var(--grid-gap, 24px)',
					'_gridTemplateColumns:tablet_portrait' => 'var(--grid-2, repeat(2, 1fr))',
					'_gridTemplateColumns:mobile'          => 'var(--grid-1, 1fr)',
				],
			],

			// ── Typography: Eyebrow, tagline, description ──
			[
				'name'     => 'eyebrow',
				'category' => 'typography',
				'settings' => [
					'_typography' => [
						'color'           => [ 'raw' => 'var(--primary, #3f4fdf)' ],
						'font-size'       => 'var(--text-s, 0.875rem)',
						'font-weight'     => '600',
						'letter-spacing'  => '0.15em',
						'text-transform'  => 'uppercase',
					],
				],
			],
			[
				'name'     => 'tagline',
				'category' => 'typography',
				'settings' => [
					'_typography' => [
						'color'       => [ 'raw' => 'var(--primary, #3f4fdf)' ],
						'font-size'   => 'var(--text-s, 0.875rem)',
						'font-weight' => '500',
					],
				],
			],
			[
				'name'     => 'hero-description',
				'category' => 'typography',
				'settings' => [
					'_typography' => [
						'font-size'   => 'var(--text-l, 1.125rem)',
						'font-weight' => '300',
					],
				],
			],

			// ── Buttons ──
			[
				'name'     => 'btn-primary',
				'category' => 'buttons',
				'settings' => [
					'_background' => [ 'color' => [ 'raw' => 'var(--primary, #3f4fdf)' ] ],
					'_typography' => [
						'color'       => [ 'raw' => 'var(--white, #ffffff)' ],
						'font-weight' => '600',
					],
					'_padding' => [
						'top' => '14px', 'right' => '28px', 'bottom' => '14px', 'left' => '28px',
					],
					'_border' => [
						'radius' => [
							'top' => 'var(--radius-btn, 6px)', 'right' => 'var(--radius-btn, 6px)',
							'bottom' => 'var(--radius-btn, 6px)', 'left' => 'var(--radius-btn, 6px)',
						],
					],
				],
			],
			[
				'name'     => 'btn-outline',
				'category' => 'buttons',
				'settings' => [
					'_border' => [
						'radius' => [
							'top' => 'var(--radius-btn, 6px)', 'right' => 'var(--radius-btn, 6px)',
							'bottom' => 'var(--radius-btn, 6px)', 'left' => 'var(--radius-btn, 6px)',
						],
						'width' => [ 'top' => '1px', 'right' => '1px', 'bottom' => '1px', 'left' => '1px' ],
						'color' => [ 'raw' => 'var(--base-light, #e4e4e7)' ],
					],
					'_typography' => [
						'color'       => [ 'raw' => 'var(--base-ultra-dark, #18181b)' ],
						'font-weight' => '600',
					],
					'_padding' => [
						'top' => '14px', 'right' => '28px', 'bottom' => '14px', 'left' => '28px',
					],
				],
			],

			// ── Cards ──
			[
				'name'     => 'card',
				'category' => 'cards',
				'settings' => [
					'_background' => [ 'color' => [ 'raw' => 'var(--white, #ffffff)' ] ],
					'_padding' => [
						'top' => 'var(--space-l, 24px)', 'right' => 'var(--space-l, 24px)',
						'bottom' => 'var(--space-l, 24px)', 'left' => 'var(--space-l, 24px)',
					],
					'_border' => [
						'radius' => [
							'top' => 'var(--radius, 8px)', 'right' => 'var(--radius, 8px)',
							'bottom' => 'var(--radius, 8px)', 'left' => 'var(--radius, 8px)',
						],
						'width' => [ 'top' => '1px', 'right' => '1px', 'bottom' => '1px', 'left' => '1px' ],
						'color' => [ 'raw' => 'var(--base-light, #e4e4e7)' ],
					],
				],
			],
			[
				'name'     => 'card-dark',
				'category' => 'cards',
				'settings' => [
					'_background' => [ 'color' => [ 'raw' => 'var(--base-ultra-dark, #18181b)' ] ],
					'_padding' => [
						'top' => 'var(--space-l, 24px)', 'right' => 'var(--space-l, 24px)',
						'bottom' => 'var(--space-l, 24px)', 'left' => 'var(--space-l, 24px)',
					],
					'_border' => [
						'radius' => [
							'top' => 'var(--radius, 8px)', 'right' => 'var(--radius, 8px)',
							'bottom' => 'var(--radius, 8px)', 'left' => 'var(--radius, 8px)',
						],
					],
				],
			],
			[
				'name'     => 'card-glass',
				'category' => 'cards',
				'settings' => [
					'_background' => [ 'color' => [ 'raw' => 'var(--white-trans-10, rgba(255,255,255,0.1))' ] ],
					'_padding' => [
						'top' => 'var(--space-m, 16px)', 'right' => 'var(--space-m, 16px)',
						'bottom' => 'var(--space-m, 16px)', 'left' => 'var(--space-m, 16px)',
					],
					'_border' => [
						'radius' => [
							'top' => 'var(--radius, 8px)', 'right' => 'var(--radius, 8px)',
							'bottom' => 'var(--radius, 8px)', 'left' => 'var(--radius, 8px)',
						],
					],
				],
			],

			// ── Tag/Pill (for feature lists) ──
			[
				'name'     => 'tag-pill',
				'category' => 'components',
				'settings' => [
					'_direction'  => 'row',
					'_alignItems' => 'center',
					'_columnGap'  => 'var(--space-s, 12px)',
					'_padding'    => [
						'top' => 'var(--space-xs, 8px)', 'right' => 'var(--space-m, 16px)',
						'bottom' => 'var(--space-xs, 8px)', 'left' => 'var(--space-m, 16px)',
					],
					'_border' => [
						'radius' => [
							'top' => 'var(--radius-pill, 999px)', 'right' => 'var(--radius-pill, 999px)',
							'bottom' => 'var(--radius-pill, 999px)', 'left' => 'var(--radius-pill, 999px)',
						],
						'width' => [ 'top' => '1px', 'right' => '1px', 'bottom' => '1px', 'left' => '1px' ],
						'color' => [ 'raw' => 'var(--base-light, #e4e4e7)' ],
					],
					'_background' => [ 'color' => [ 'raw' => 'var(--base-ultra-light, #f4f4f5)' ] ],
				],
			],
			[
				'name'     => 'tag-grid',
				'category' => 'layout',
				'settings' => [
					'_direction' => 'row',
					'_flexWrap'  => 'wrap',
					'_rowGap'    => 'var(--space-m, 16px)',
					'_columnGap' => 'var(--space-m, 16px)',
				],
			],
		];
	}

	/**
	 * Get starter class names as a simple array (for summary responses).
	 *
	 * @return array<int, string>
	 */
	public static function get_starter_class_names(): array {
		return array_map( fn( $c ) => $c['name'], self::get_starter_classes() );
	}
}
