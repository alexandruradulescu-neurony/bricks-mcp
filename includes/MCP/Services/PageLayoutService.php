<?php
/**
 * Page layout composition service.
 *
 * Maps page intents (e.g. "services landing page") to recommended section
 * sequences with design_plan skeletons, using DesignPatternService for
 * pattern matching.
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
 * PageLayoutService class.
 */
final class PageLayoutService {

	/**
	 * Intent-to-section-sequence map.
	 *
	 * Keys are matched against the intent string (case-insensitive, partial match).
	 * Values are ordered arrays of section types.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const INTENT_MAP = [
		'landing page' => [ 'hero', 'features', 'pricing', 'testimonials', 'cta' ],
		'services'     => [ 'hero', 'features', 'split', 'testimonials', 'cta' ],
		'about'        => [ 'hero', 'split', 'features', 'testimonials', 'cta' ],
		'product'      => [ 'hero', 'features', 'pricing', 'split', 'cta' ],
		'contact'      => [ 'hero', 'split', 'cta' ],
		'pricing'      => [ 'hero', 'pricing', 'features', 'testimonials', 'cta' ],
		'portfolio'    => [ 'hero', 'features', 'split', 'cta' ],
		'blog'         => [ 'hero', 'features', 'cta' ],
		'home'         => [ 'hero', 'features', 'split', 'testimonials', 'pricing', 'cta' ],
	];

	/**
	 * Default section sequence when no intent matches.
	 *
	 * @var array<int, string>
	 */
	private const DEFAULT_SEQUENCE = [ 'hero', 'features', 'pricing', 'cta' ];

	/**
	 * Section type to recommended layout and background defaults.
	 *
	 * @var array<string, array{layout: string, background: string, rationale: string}>
	 */
	private const SECTION_DEFAULTS = [
		'hero'         => [
			'layout'     => 'centered',
			'background' => 'dark',
			'rationale'  => 'Centered dark hero gives the page a strong opening; classic conversion pattern.',
		],
		'features'     => [
			'layout'     => 'grid-3',
			'background' => 'light',
			'rationale'  => 'Three-column icon grid is the standard features layout for scannability.',
		],
		'pricing'      => [
			'layout'     => 'grid-3',
			'background' => 'light',
			'rationale'  => 'Three-tier pricing grid enables easy plan comparison.',
		],
		'testimonials' => [
			'layout'     => 'grid-3',
			'background' => 'light',
			'rationale'  => 'Card grid testimonials provide social proof with visual variety.',
		],
		'split'        => [
			'layout'     => 'split-50-50',
			'background' => 'light',
			'rationale'  => 'Even split balances text content with visual media.',
		],
		'cta'          => [
			'layout'     => 'centered',
			'background' => 'dark',
			'rationale'  => 'Centered dark CTA creates urgency and clear focus on the action.',
		],
		'generic'      => [
			'layout'     => 'centered',
			'background' => 'light',
			'rationale'  => 'Generic centered section for flexible content.',
		],
	];

	/**
	 * Minimal element skeletons per section type.
	 *
	 * These are intentionally sparse — the AI enriches them with content_hints
	 * and patterns before calling propose_design.
	 *
	 * @var array<string, array<int, array{type: string, role: string}>>
	 */
	private const ELEMENT_SKELETONS = [
		'hero' => [
			[ 'type' => 'heading', 'role' => 'title' ],
			[ 'type' => 'text-basic', 'role' => 'description' ],
			[ 'type' => 'button', 'role' => 'cta_primary' ],
		],
		'features' => [
			[ 'type' => 'heading', 'role' => 'section_title' ],
			[ 'type' => 'text-basic', 'role' => 'section_subtitle' ],
		],
		'pricing' => [
			[ 'type' => 'heading', 'role' => 'section_title' ],
			[ 'type' => 'text-basic', 'role' => 'section_subtitle' ],
		],
		'testimonials' => [
			[ 'type' => 'heading', 'role' => 'section_title' ],
		],
		'split' => [
			[ 'type' => 'heading', 'role' => 'title' ],
			[ 'type' => 'text-basic', 'role' => 'description' ],
			[ 'type' => 'image', 'role' => 'media' ],
		],
		'cta' => [
			[ 'type' => 'heading', 'role' => 'title' ],
			[ 'type' => 'text-basic', 'role' => 'description' ],
			[ 'type' => 'button', 'role' => 'cta_primary' ],
		],
		'generic' => [
			[ 'type' => 'heading', 'role' => 'section_title' ],
			[ 'type' => 'text-basic', 'role' => 'description' ],
		],
	];

	/**
	 * Compose a page layout recommendation from an intent string.
	 *
	 * Returns a section sequence with recommended patterns, design_plan
	 * skeletons, and rationales for each section.
	 *
	 * @param string      $intent      Free-text intent (e.g. "services landing page").
	 * @param string|null $tone        Optional tone hint (e.g. "professional", "playful").
	 * @param int|null    $page_id     Optional target page ID for context.
	 * @return array<string, mixed> Layout recommendation.
	 */
	public function compose( string $intent, ?string $tone = null, ?int $page_id = null ): array {
		$sequence = $this->resolve_sequence( $intent );
		$tone     = $tone ?? 'professional';

		$sections = [];
		$order    = 0;

		foreach ( $sequence as $section_type ) {
			$order++;
			$defaults = self::SECTION_DEFAULTS[ $section_type ] ?? self::SECTION_DEFAULTS['generic'];

			// Adjust background for tone.
			$background = $defaults['background'];
			if ( 'playful' === $tone && 'dark' === $background && 'cta' === $section_type ) {
				$background = 'light';
			}

			// Find best matching pattern.
			$tags     = [ $defaults['layout'], $background ];
			$patterns = DesignPatternService::find( $section_type, $tags, 1 );
			$pattern  = ! empty( $patterns ) ? $patterns[0] : null;

			$design_plan = [
				'section_type' => $section_type,
				'layout'       => $defaults['layout'],
				'background'   => $background,
				'elements'     => self::ELEMENT_SKELETONS[ $section_type ] ?? self::ELEMENT_SKELETONS['generic'],
			];

			$section_entry = [
				'order'        => $order,
				'section_type' => $section_type,
				'design_plan'  => $design_plan,
				'rationale'    => $defaults['rationale'],
			];

			if ( null !== $pattern ) {
				$section_entry['recommended_pattern_id'] = $pattern['id'] ?? $pattern['name'] ?? '';
			}

			$sections[] = $section_entry;
		}

		$result = [
			'intent'        => $intent,
			'tone'          => $tone,
			'section_count' => count( $sections ),
			'sections'      => $sections,
			'next_step'     => 'Loop: for each section, call propose_design with the design_plan (enrich elements with content_hint first), then build_from_schema. Use bypass_design_gate: false (default).',
		];

		if ( null !== $page_id ) {
			$result['page_id'] = $page_id;
		}

		return $result;
	}

	/**
	 * Resolve an intent string to a section sequence.
	 *
	 * Matches against known intent keywords; falls back to the default sequence.
	 *
	 * @param string $intent Free-text intent.
	 * @return array<int, string> Section type sequence.
	 */
	private function resolve_sequence( string $intent ): array {
		$lower = strtolower( $intent );

		// Check each intent keyword (longest match first for specificity).
		$candidates = self::INTENT_MAP;
		uksort( $candidates, static fn( string $a, string $b ) => strlen( $b ) <=> strlen( $a ) );

		foreach ( $candidates as $keyword => $sequence ) {
			if ( str_contains( $lower, $keyword ) ) {
				return $sequence;
			}
		}

		return self::DEFAULT_SEQUENCE;
	}
}
