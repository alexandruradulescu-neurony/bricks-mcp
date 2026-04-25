<?php
/**
 * build_from_html tool — accept an HTML fragment, emit Bricks elements.
 *
 * Phase B of the v5-html-hybrid pipeline. The AI writes HTML using site
 * classes and CSS variables; this handler converts the HTML to a build
 * schema (via {@see HtmlToElements}) and delegates to BuildStructureHandler
 * so the existing resolve / normalize / validate / write / verify chain
 * still applies.
 *
 * HTML mode is intentionally narrow: popups, components, and query loops
 * are NOT representable as plain HTML. Use build_structure (schema mode)
 * for those.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */
declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\HtmlToElements;
use BricksMCP\MCP\ToolRegistry;
use Closure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for the `build_from_html` MCP tool.
 */
final class BuildFromHtmlHandler {

	/**
	 * Element types that HTML mode refuses (no clean HTML analog).
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN_TYPES = [ 'popup', 'component', 'query-loop' ];

	/**
	 * Class-name prefixes that hint at unsupported features so we can fail
	 * fast with a useful message before going through the converter.
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN_CLASS_HINTS = [ 'bricks-popup-', 'brx-component-' ];

	/**
	 * @param BuildStructureHandler $build_structure_handler Existing pipeline entry point.
	 * @param Closure               $require_bricks          Returns WP_Error|null when Bricks must be active.
	 */
	public function __construct(
		private BuildStructureHandler $build_structure_handler,
		private Closure $require_bricks
	) {}

	/**
	 * Handle the build_from_html tool call.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public function handle( array $args ): array|\WP_Error {
		$bricks_check = ( $this->require_bricks )();
		if ( $bricks_check instanceof \WP_Error ) {
			return $bricks_check;
		}

		$html    = isset( $args['html'] ) && is_string( $args['html'] ) ? $args['html'] : '';
		$page_id = (int) ( $args['page_id'] ?? $args['template_id'] ?? 0 );
		$action  = isset( $args['action'] ) && is_string( $args['action'] ) ? $args['action'] : 'append';

		if ( '' === trim( $html ) ) {
			return new \WP_Error(
				'missing_html',
				__( 'html is required.', 'bricks-mcp' )
			);
		}
		if ( $page_id <= 0 ) {
			return new \WP_Error(
				'missing_target',
				__( 'page_id (or template_id) is required.', 'bricks-mcp' )
			);
		}
		if ( ! in_array( $action, [ 'append', 'replace' ], true ) ) {
			return new \WP_Error(
				'invalid_action',
				__( 'action must be "append" or "replace".', 'bricks-mcp' )
			);
		}

		// 1. Convert HTML → Bricks schema.
		$convert = HtmlToElements::convert( $html );
		if ( $convert instanceof \WP_Error ) {
			return $convert;
		}

		if ( empty( $convert['sections'] ) ) {
			return new \WP_Error(
				'html_no_sections',
				__( 'HTML contained no convertible content.', 'bricks-mcp' )
			);
		}

		// 2. Refuse element types HTML mode can't honestly express.
		$unsupported = $this->collect_unsupported( $convert );
		if ( ! empty( $unsupported ) ) {
			return new \WP_Error(
				'html_mode_unsupported',
				sprintf(
					/* translators: %s: comma-separated element types. */
					__( 'HTML mode does not support: %s. Use build_structure (schema mode) for popups, components, and query loops.', 'bricks-mcp' ),
					implode( ', ', $unsupported )
				),
				[ 'unsupported_types' => $unsupported ]
			);
		}

		// 3. Wrap into the build_structure schema shape.
		$is_dark = $this->detect_dark_section( $convert['sections'] );
		$schema  = [
			'target'         => [
				'page_id' => $page_id,
				'action'  => $action,
			],
			'design_context' => [
				'summary' => 'HTML mode build (' . count( $convert['sections'] ) . ' section)',
				'spacing' => 'normal',
			],
			'sections'       => array_map(
				static function ( array $section ) use ( $is_dark ): array {
					if ( $is_dark && empty( $section['background'] ) ) {
						$section['background'] = 'dark';
					}
					return $section;
				},
				$convert['sections']
			),
		];

		// 4. Synthesize a proposal_id so BuildStructureHandler accepts the call.
		// Stored as a transient with a short TTL — the handler reads it once.
		$proposal_id = 'html_' . bin2hex( random_bytes( 8 ) );
		$proposal    = [
			'page_id'        => $page_id,
			'description'    => 'HTML mode build',
			'mode'           => 'html_to_elements',
			'design_plan'    => null,
			'class_names_seen' => $convert['class_names_seen'],
		];
		set_transient( 'bricks_mcp_proposal_' . $proposal_id, $proposal, 60 );

		// 5. Delegate to the existing pipeline.
		$result = $this->build_structure_handler->handle(
			[
				'proposal_id' => $proposal_id,
				'schema'      => $schema,
			]
		);

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		// 6. Augment response with HTML-mode audit data.
		$result['html_mode'] = [
			'class_names_seen'  => $convert['class_names_seen'],
			'css_rules_dropped' => $convert['css_rules_dropped'],
			'warnings'          => $convert['warnings'],
			'stats'             => $convert['stats'],
		];

		return $result;
	}

	/**
	 * Walk the converted schema; return any element types HTML mode can't
	 * faithfully represent. Empty array means schema is safe to build.
	 *
	 * @param array<string, mixed> $convert HtmlToElements::convert() result.
	 * @return array<int, string>
	 */
	private function collect_unsupported( array $convert ): array {
		$found = [];

		$walk = static function ( array $nodes ) use ( &$walk, &$found ): void {
			foreach ( $nodes as $node ) {
				$type = $node['type'] ?? '';
				if ( in_array( $type, self::FORBIDDEN_TYPES, true ) ) {
					$found[] = $type;
				}
				$class_intent = $node['class_intent'] ?? '';
				if ( is_string( $class_intent ) && '' !== $class_intent ) {
					foreach ( self::FORBIDDEN_CLASS_HINTS as $prefix ) {
						if ( str_starts_with( $class_intent, $prefix ) ) {
							$found[] = 'class:' . $prefix . '*';
						}
					}
				}
				if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
					$walk( $node['children'] );
				}
			}
		};

		foreach ( $convert['sections'] ?? [] as $section ) {
			if ( isset( $section['structure'] ) && is_array( $section['structure'] ) ) {
				$walk( [ $section['structure'] ] );
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Heuristic: does any top-level section appear to use a dark background?
	 * Used to set the section's `background: dark` flag so downstream
	 * normalization picks the right text-color contrast.
	 *
	 * @param array<int, array<string, mixed>> $sections
	 * @return bool
	 */
	private function detect_dark_section( array $sections ): bool {
		foreach ( $sections as $section ) {
			$structure = $section['structure'] ?? [];
			$bg        = $structure['style_overrides']['_background'] ?? null;
			if ( ! is_array( $bg ) ) {
				continue;
			}
			$color = $bg['color']['raw']                ?? '';
			$grad  = $bg['image']['gradient']            ?? '';
			$candidate = strtolower( $color . ' ' . $grad );
			if ( str_contains( $candidate, 'ultra-dark' )
				|| str_contains( $candidate, '--black' )
				|| str_contains( $candidate, 'dark' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Register the build_from_html tool with the MCP registry.
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'build_from_html',
			__(
				"Build a Bricks section from an HTML fragment. The AI writes HTML using the site's existing classes and CSS variables; the converter transforms it deterministically into Bricks elements, then the standard pipeline resolves classes, normalizes shapes, validates, writes, and verifies.\n\nUse this for content sections (heroes, features, CTAs, content blocks). For popups, components, and query loops, use build_structure (schema mode) instead.\n\nGuidelines for the HTML you emit:\n- Wrap the section in <section class=\"...\"> at the top level.\n- Use ONLY classes from this site (call global_class:list to see them). Inventing class names will leave styling undone.\n- Use CSS variables for colors and spacing — e.g. var(--primary), var(--space-l). Available variables come from get_site_info.\n- Inline styles (style=\"...\") are honored. Padding/margin/border-radius accept CSS shorthand.\n- For dark backgrounds use background: linear-gradient(135deg, var(--primary-ultra-dark), var(--base-ultra-dark)).\n- Buttons: <a class=\"hero__cta-primary\" href=\"...\">Label</a> for links, <button> for non-link CTAs.\n- Images: <img src=\"unsplash:query terms\" alt=\"...\"> triggers media sideload at write time.\n- Do NOT use <details>, <dialog>, or custom elements; they are dropped with a warning.\n- Do NOT try to express popups, components, or query loops.\n\nThe response includes html_mode.class_names_seen, html_mode.css_rules_dropped, and html_mode.warnings so you can see what survived the conversion.",
				'bricks-mcp'
			),
			[
				'type'       => 'object',
				'properties' => [
					'html'    => [
						'type'        => 'string',
						'description' => __( 'The HTML fragment to convert. Top-level should be one or more <section> elements.', 'bricks-mcp' ),
					],
					'page_id' => [
						'type'        => 'integer',
						'description' => __( 'Target page ID. Either page_id or template_id is required.', 'bricks-mcp' ),
					],
					'template_id' => [
						'type'        => 'integer',
						'description' => __( 'Target template ID (alternative to page_id).', 'bricks-mcp' ),
					],
					'action'  => [
						'type'        => 'string',
						'enum'        => [ 'append', 'replace' ],
						'description' => __( 'append (default) adds new sections; replace overwrites existing page content.', 'bricks-mcp' ),
					],
				],
				'required'   => [ 'html' ],
			],
			[ $this, 'handle' ],
			[]
		);
	}
}
