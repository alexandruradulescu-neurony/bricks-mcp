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
					'_gridTemplateColumns'                 => 'var(--grid-2)',
					'_gridGap'                             => 'var(--grid-gap)',
					'_gridTemplateColumns:tablet_portrait' => 'var(--grid-1)',
				],
			],
			[
				'name'     => 'grid-3',
				'category' => 'layout',
				'settings' => [
					'_display'                             => 'grid',
					'_gridTemplateColumns'                 => 'var(--grid-3)',
					'_gridGap'                             => 'var(--grid-gap)',
					'_gridTemplateColumns:tablet_portrait' => 'var(--grid-2)',
					'_gridTemplateColumns:mobile'          => 'var(--grid-1)',
				],
			],
			[
				'name'     => 'grid-4',
				'category' => 'layout',
				'settings' => [
					'_display'                             => 'grid',
					'_gridTemplateColumns'                 => 'var(--grid-4)',
					'_gridGap'                             => 'var(--grid-gap)',
					'_gridTemplateColumns:tablet_portrait' => 'var(--grid-2)',
					'_gridTemplateColumns:mobile'          => 'var(--grid-1)',
				],
			],

			// ── Typography: Eyebrow, tagline, description ──
			[
				'name'     => 'eyebrow',
				'category' => 'typography',
				'settings' => [
					'_typography' => [
						'color'           => [ 'raw' => 'var(--primary)' ],
						'font-size'       => 'var(--text-s)',
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
						'color'       => [ 'raw' => 'var(--primary)' ],
						'font-size'   => 'var(--text-s)',
						'font-weight' => '500',
					],
				],
			],
			[
				'name'     => 'hero-description',
				'category' => 'typography',
				'settings' => [
					'_typography' => [
						'font-size'   => 'var(--text-l)',
						'font-weight' => '300',
					],
				],
			],

			// ── Buttons ──
			[
				'name'     => 'btn-primary',
				'category' => 'buttons',
				'settings' => [
					'_background' => [ 'color' => [ 'raw' => 'var(--primary)' ] ],
					'_typography' => [
						'color'       => [ 'raw' => 'var(--white)' ],
						'font-weight' => '600',
					],
					'_padding' => [
						'top' => '14px', 'right' => '28px', 'bottom' => '14px', 'left' => '28px',
					],
					'_border' => [
						'radius' => [
							'top' => 'var(--radius-btn)', 'right' => 'var(--radius-btn)',
							'bottom' => 'var(--radius-btn)', 'left' => 'var(--radius-btn)',
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
							'top' => 'var(--radius-btn)', 'right' => 'var(--radius-btn)',
							'bottom' => 'var(--radius-btn)', 'left' => 'var(--radius-btn)',
						],
						'width' => [ 'top' => '1px', 'right' => '1px', 'bottom' => '1px', 'left' => '1px' ],
						'color' => [ 'raw' => 'var(--base-light)' ],
					],
					'_typography' => [
						'color'       => [ 'raw' => 'var(--base-ultra-dark)' ],
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
					'_background' => [ 'color' => [ 'raw' => 'var(--white)' ] ],
					'_padding' => [
						'top' => 'var(--space-l)', 'right' => 'var(--space-l)',
						'bottom' => 'var(--space-l)', 'left' => 'var(--space-l)',
					],
					'_border' => [
						'radius' => [
							'top' => 'var(--radius)', 'right' => 'var(--radius)',
							'bottom' => 'var(--radius)', 'left' => 'var(--radius)',
						],
						'width' => [ 'top' => '1px', 'right' => '1px', 'bottom' => '1px', 'left' => '1px' ],
						'color' => [ 'raw' => 'var(--base-light)' ],
					],
				],
			],
			[
				'name'     => 'card-dark',
				'category' => 'cards',
				'settings' => [
					'_background' => [ 'color' => [ 'raw' => 'var(--base-ultra-dark)' ] ],
					'_padding' => [
						'top' => 'var(--space-l)', 'right' => 'var(--space-l)',
						'bottom' => 'var(--space-l)', 'left' => 'var(--space-l)',
					],
					'_border' => [
						'radius' => [
							'top' => 'var(--radius)', 'right' => 'var(--radius)',
							'bottom' => 'var(--radius)', 'left' => 'var(--radius)',
						],
					],
				],
			],
			[
				'name'     => 'card-glass',
				'category' => 'cards',
				'settings' => [
					'_background' => [ 'color' => [ 'raw' => 'var(--white-trans-10)' ] ],
					'_padding' => [
						'top' => 'var(--space-m)', 'right' => 'var(--space-m)',
						'bottom' => 'var(--space-m)', 'left' => 'var(--space-m)',
					],
					'_border' => [
						'radius' => [
							'top' => 'var(--radius)', 'right' => 'var(--radius)',
							'bottom' => 'var(--radius)', 'left' => 'var(--radius)',
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
					'_columnGap'  => 'var(--space-s)',
					'_padding'    => [
						'top' => 'var(--space-xs)', 'right' => 'var(--space-m)',
						'bottom' => 'var(--space-xs)', 'left' => 'var(--space-m)',
					],
					'_border' => [
						'radius' => [
							'top' => 'var(--radius-pill)', 'right' => 'var(--radius-pill)',
							'bottom' => 'var(--radius-pill)', 'left' => 'var(--radius-pill)',
						],
						'width' => [ 'top' => '1px', 'right' => '1px', 'bottom' => '1px', 'left' => '1px' ],
						'color' => [ 'raw' => 'var(--base-light)' ],
					],
					'_background' => [ 'color' => [ 'raw' => 'var(--base-ultra-light)' ] ],
				],
			],
			[
				'name'     => 'tag-grid',
				'category' => 'layout',
				'settings' => [
					'_direction' => 'row',
					'_flexWrap'  => 'wrap',
					'_rowGap'    => 'var(--space-m)',
					'_columnGap' => 'var(--space-m)',
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
