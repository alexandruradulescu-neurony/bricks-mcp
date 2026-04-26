<?php
/**
 * Verify handler for MCP Router.
 *
 * Post-build verification: fetches the page after building and returns
 * a structured summary so the AI can confirm the result matches intent.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\ContentContractService;
use BricksMCP\MCP\ToolRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VerifyHandler {

	/**
	 * Max number of heading content samples returned in verify response.
	 */
	private const CONTENT_SAMPLE_HEADINGS_LIMIT = 10;

	/**
	 * Max number of button content samples returned in verify response.
	 */
	private const CONTENT_SAMPLE_BUTTONS_LIMIT = 10;

	/**
	 * Max number of generic text content samples returned in verify response.
	 */
	private const CONTENT_SAMPLE_TEXTS_LIMIT = 5;

	private BricksService $bricks_service;

	/** @var callable */
	private $require_bricks;

	public function __construct( BricksService $bricks_service, callable $require_bricks ) {
		$this->bricks_service = $bricks_service;
		$this->require_bricks = $require_bricks;
	}

	/**
	 * Handle the verify_build tool call.
	 */
	public function handle( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$page_id    = (int) ( $args['page_id'] ?? $args['template_id'] ?? 0 );
		$section_id = $args['section_id'] ?? null;

		if ( 0 === $page_id ) {
			return new \WP_Error( 'missing_page_id', 'page_id or template_id is required.' );
		}

		// Validate section_id format if provided. Bricks element IDs are 6-character
		// alphanumeric strings. Reject integers, empty strings, and malformed IDs
		// instead of silently returning an empty result.
		if ( null !== $section_id ) {
			if ( ! is_string( $section_id ) || '' === $section_id ) {
				return new \WP_Error(
					'invalid_section_id',
					sprintf( 'section_id must be a non-empty string. Received: %s (%s). Omit it to verify the whole page.', var_export( $section_id, true ), gettype( $section_id ) )
				);
			}
			if ( ! preg_match( '/^[a-zA-Z0-9_-]{3,32}$/', $section_id ) ) {
				return new \WP_Error(
					'invalid_section_id',
					sprintf( 'section_id "%s" is not a valid Bricks element ID (expected 3-32 alphanumeric chars). Omit section_id to verify the whole page.', $section_id )
				);
			}
		}

		$post = get_post( $page_id );
		if ( ! $post ) {
			return new \WP_Error( 'invalid_page', sprintf( 'Page %d not found.', $page_id ) );
		}

		$elements = $this->bricks_service->get_elements( $page_id );
		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return [
				'page_id'       => $page_id,
				'element_count' => 0,
				'status'        => 'empty',
				'message'       => 'Page has no Bricks elements.',
			];
		}

		// If section_id provided, filter to that section's descendants.
		if ( null !== $section_id ) {
			$elements = $this->filter_to_section( $elements, $section_id );
			if ( empty( $elements ) ) {
				return new \WP_Error( 'section_not_found', sprintf( 'Section "%s" not found on page %d.', $section_id, $page_id ) );
			}
		}

		// Build summary.
		$type_counts = [];
		$classes_used = [];
		$labels = [];

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$name = $el['name'] ?? 'unknown';
			$type_counts[ $name ] = ( $type_counts[ $name ] ?? 0 ) + 1;

			$settings = $el['settings'] ?? [];
			if ( ! is_array( $settings ) ) {
				continue;
			}
			if ( ! empty( $settings['label'] ) ) {
				$labels[] = $settings['label'];
			}
			if ( ! empty( $settings['_cssGlobalClasses'] ) && is_array( $settings['_cssGlobalClasses'] ) ) {
				foreach ( $settings['_cssGlobalClasses'] as $class_id ) {
					$classes_used[ $class_id ] = true;
				}
			}
		}

		// Resolve class IDs to names.
		$all_classes = $this->bricks_service->get_global_class_service()->get_global_classes();
		if ( ! is_array( $all_classes ) ) {
			$all_classes = [];
		}
		$class_id_to_name   = [];
		$class_id_to_record = [];
		foreach ( $all_classes as $cls ) {
			if ( ! is_array( $cls ) ) {
				continue;
			}
			$id = (string) ( $cls['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$class_id_to_name[ $id ]   = $cls['name'] ?? '';
			$class_id_to_record[ $id ] = $cls;
		}

		$class_names = [];
		foreach ( array_keys( $classes_used ) as $id ) {
			$class_names[] = $class_id_to_name[ $id ] ?? $id;
		}

		$quality = $this->inspect_quality( $elements, $class_id_to_name, $class_id_to_record );

		// Build hierarchy tree for last section (most recently built).
		$sections = array_filter( $elements, fn( $el ) => is_array( $el ) && ( $el['name'] ?? '' ) === 'section' && empty( $el['parent'] ) );
		$last_section = ! empty( $sections ) ? end( $sections ) : null;
		$hierarchy = null;

		if ( $last_section ) {
			$hierarchy = $this->build_hierarchy_summary( $elements, $last_section['id'] );
		}

		// Rich description from describe_page() — human-readable section breakdown.
		$described = $this->bricks_service->describe_page( $page_id );
		$described_sections = [];
		$page_description   = '';
		if ( ! is_wp_error( $described ) ) {
			$page_description   = $described['page_description'] ?? '';
			$described_sections = $described['sections'] ?? [];

			// Filter to single section if section_id provided.
			if ( null !== $section_id ) {
				$described_sections = array_values( array_filter(
					$described_sections,
					fn( $s ) => ( $s['id'] ?? '' ) === $section_id
				) );
			}
		}

		// Content extraction — lets AI verify actual text was set, not placeholders.
		$content_sample = $this->extract_content_sample( $elements );
		$content_contract = null;
		$contract_section_id = is_string( $section_id ) ? $section_id : ( is_array( $last_section ) ? (string) ( $last_section['id'] ?? '' ) : '' );
		if ( '' !== $contract_section_id ) {
			$content_contract = ( new ContentContractService() )->analyze( $elements, $contract_section_id );
		}

		// v5/C: live render verification plan — AI client with Playwright MCP can
		// execute this against the published page to catch silent CSS drops that
		// element-data inspection misses.
		// v5.1.4: opt-in. The plan carries a ~2 KB JS snippet for browser_evaluate;
		// most verify calls don't need it. Pass include_visual_plan=true to receive it.
		$include_visual_plan = ! empty( $args['include_visual_plan'] );
		$verification_plan   = null;
		if ( $include_visual_plan ) {
			$plan_section_id = is_string( $section_id ) ? $section_id : ( is_array( $last_section ) ? (string) ( $last_section['id'] ?? '' ) : '' );
			$verification_plan = $this->build_verification_plan(
				$page_id,
				$plan_section_id,
				array_values( array_unique( $class_names ) ),
				$type_counts
			);
		}

		$response = [
			'page_id'           => $page_id,
			'page_description'  => $page_description,
			'sections'          => $described_sections,
			'element_count'     => count( $elements ),
			'type_counts'       => $type_counts,
			'classes_used'      => array_values( array_unique( $class_names ) ),
			'quality_checks'    => $quality,
			'labels'            => $labels,
			'last_section'      => $hierarchy,
			'section_count'     => count( $sections ),
			'content_sample'    => $content_sample,
			'content_contract'  => $content_contract,
			'status'            => 'ok',
			'verification'      => 'Compare page_description and sections[*].description with your design intent. Check quality_checks, content_sample.headings and .buttons for actual text. If has_placeholder_content is true, replace [PLACEHOLDER] text. Compare type_counts and classes_used against your design_plan. For visual verification with Playwright, call verify_build again with include_visual_plan=true to get a page_url + section_selector + evaluate_snippet.',
			'notes_hint'        => 'If you learned something about this site during the build (e.g. preferred layouts, naming conventions, design patterns that work well, corrections you had to make), save it via bricks:add_note(text="..."). Notes persist across sessions and are shown in future discovery responses.',
		];

		if ( null !== $verification_plan ) {
			$response['verification_plan'] = $verification_plan;
		}

		return $response;
	}

	/**
	 * Build a live-render verification plan for an AI client with Playwright
	 * MCP (or any headless browser). Returns the URL to navigate, the section
	 * selector to inspect, a JS snippet that gathers computed styles, and the
	 * expected feature checklist the AI should compare against.
	 *
	 * If the AI client lacks a headless browser, the existing element-data
	 * fields above are the fallback signal — the plan is purely additive.
	 *
	 * @param int                  $page_id
	 * @param string               $section_id
	 * @param array<int, string>   $class_names_used
	 * @param array<string, int>   $type_counts
	 * @return array<string, mixed>
	 */
	private function build_verification_plan(
		int $page_id,
		string $section_id,
		array $class_names_used,
		array $type_counts
	): array {
		$permalink = get_permalink( $page_id );
		$page_url  = is_string( $permalink ) ? $permalink : '';
		$section_selector = '' !== $section_id ? '#brxe-' . $section_id : '';

		// Heuristic expected-features list. Each item is a one-line assertion the
		// AI can score against the snippet's output. None is fatal; this is signal,
		// not enforcement.
		$expected_features = [];

		$expected_features[] = sprintf( 'page renders 200 OK at %s', $page_url );

		if ( '' !== $section_selector ) {
			$expected_features[] = sprintf( 'section element %s exists in the rendered DOM', $section_selector );
			$expected_features[] = 'section computed padding-top > 0 (i.e. theme/section spacing applied)';
			$expected_features[] = 'section computed background-color or background-image is non-default';
		}

		if ( ! empty( $class_names_used ) ) {
			$expected_features[] = sprintf(
				'%d global class(es) appear in element class chains: %s',
				count( $class_names_used ),
				implode( ', ', array_slice( $class_names_used, 0, 8 ) )
				. ( count( $class_names_used ) > 8 ? ', …' : '' )
			);
		}

		if ( ! empty( $type_counts['heading'] ) ) {
			$expected_features[] = sprintf( '%d heading element(s) render with non-empty text', $type_counts['heading'] );
		}
		if ( ! empty( $type_counts['image'] ) ) {
			$expected_features[] = sprintf( '%d image element(s) have a resolved src (not literal "unsplash:*")', $type_counts['image'] );
		}
		if ( ! empty( $type_counts['text-link'] ) || ! empty( $type_counts['button'] ) ) {
			$cta_count = ( $type_counts['text-link'] ?? 0 ) + ( $type_counts['button'] ?? 0 );
			$expected_features[] = sprintf( '%d clickable element(s) carry a usable href', $cta_count );
		}

		// JS snippet for browser_evaluate. Returns enough computed-style data to
		// catch the silent failures we hit historically (no padding, no gradient,
		// dropped overrides) without bloating the response.
		$snippet = '() => {'
			. 'const sel = ' . wp_json_encode( $section_selector ) . ';'
			. 'const wanted = ["padding","padding-top","padding-right","padding-bottom","padding-left","margin","color","background-color","background-image","display","grid-template-columns","grid-template-rows","gap","row-gap","column-gap","flex-direction","justify-content","align-items","width","height","font-size","font-weight","text-align","letter-spacing","text-transform","border-radius","border-width","border-color","aspect-ratio","object-fit","opacity","position","z-index"];'
			. 'const grab = (el) => { const cs = getComputedStyle(el); const out = {tag: el.tagName.toLowerCase(), id: el.id || null, classes: el.className, rect: { w: el.getBoundingClientRect().width, h: el.getBoundingClientRect().height }, text: (el.tagName === "IMG" || el.tagName === "VIDEO") ? null : (el.innerText || "").substring(0,180), src: (el.tagName === "IMG" || el.tagName === "VIDEO") ? el.getAttribute("src") : null, styles: {} }; for (const p of wanted) { out.styles[p] = cs.getPropertyValue(p); } return out; };'
			. 'if (!sel) return { error: "no section_id known", title: document.title };'
			. 'const root = document.querySelector(sel);'
			. 'if (!root) return { error: "section not found in rendered DOM", selector: sel, title: document.title };'
			. 'const out = { selector: sel, root: grab(root), descendants: [] };'
			. 'const all = root.querySelectorAll("*");'
			. 'const cap = Math.min(all.length, 60);'
			. 'for (let i = 0; i < cap; i++) { out.descendants.push(grab(all[i])); }'
			. 'out.truncated = all.length > cap;'
			. 'out.descendant_count = all.length;'
			. 'return out;'
			. '}';

		return [
			'requires_renderer'  => 'playwright_mcp_or_headless_browser',
			'page_url'           => $page_url,
			'section_selector'   => $section_selector,
			'expected_features'  => $expected_features,
			'evaluate_snippet'   => $snippet,
			'evaluate_usage'     => 'Pass evaluate_snippet to the Playwright MCP browser_evaluate tool (or any equivalent). The snippet returns a structured object with section root + up to 60 descendants — for each element: tag, classes, computed styles (padding, background, gradient, grid, typography, etc.), text snippet, image src. Compare against expected_features and your original design intent.',
			'fallback'           => 'If no headless browser is available, the element-data fields above (type_counts, classes_used, quality_checks, content_sample) are the verification signal.',
		];
	}

	/**
	 * Inspect static visual quality issues in saved Bricks elements.
	 *
	 * @param array<int, array<string, mixed>> $elements Flat Bricks element list.
	 * @param array<string, string>            $class_id_to_name Class ID => class name map.
	 * @param array<string, array<string, mixed>> $class_id_to_record Class ID => full class record map.
	 * @return array<string, mixed>
	 */
	private function inspect_quality( array $elements, array $class_id_to_name, array $class_id_to_record ): array {
		$warnings       = [];
		$missing_ids    = [];
		$empty_classes  = [];
		$variable_notes = [];
		$role_counts    = [];

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$settings = is_array( $el['settings'] ?? null ) ? $el['settings'] : [];
			$role     = (string) ( $settings['label'] ?? $el['label'] ?? '' );
			if ( '' !== $role ) {
				$role_counts[ $role ] = ( $role_counts[ $role ] ?? 0 ) + 1;
			}

			foreach ( (array) ( $settings['_cssGlobalClasses'] ?? [] ) as $class_id ) {
				$class_id = (string) $class_id;
				if ( '' === $class_id ) {
					continue;
				}
				if ( ! isset( $class_id_to_name[ $class_id ] ) ) {
					$missing_ids[] = $class_id;
					continue;
				}
				$record = $class_id_to_record[ $class_id ] ?? [];
				if ( empty( $record['settings'] ) ) {
					$empty_classes[] = (string) $class_id_to_name[ $class_id ];
				}
			}

			$normalized = \BricksMCP\MCP\Services\StyleNormalizationService::normalize( $settings );
			foreach ( $normalized['warnings'] as $warning ) {
				if ( str_contains( $warning, 'missing Bricks variable' ) || str_contains( $warning, 'foreign variable' ) ) {
					$variable_notes[] = (string) ( $el['id'] ?? 'unknown' ) . ': ' . $warning;
				}
			}
		}

		if ( ! empty( $missing_ids ) ) {
			$warnings[] = 'Missing global class IDs on elements: ' . implode( ', ', array_values( array_unique( $missing_ids ) ) ) . '.';
		}
		if ( ! empty( $empty_classes ) ) {
			$warnings[] = 'Used empty global classes: ' . implode( ', ', array_values( array_unique( $empty_classes ) ) ) . '.';
		}
		$duplicate_roles = [];
		foreach ( $role_counts as $role => $count ) {
			if ( $count > 1 ) {
				$duplicate_roles[] = $role;
			}
		}
		if ( ! empty( $duplicate_roles ) ) {
			$warnings[] = 'Duplicate role labels in built elements: ' . implode( ', ', array_values( array_unique( $duplicate_roles ) ) ) . '.';
		}
		foreach ( array_slice( array_values( array_unique( $variable_notes ) ), 0, 10 ) as $note ) {
			$warnings[] = $note;
		}

		return [
			'status'             => empty( $warnings ) ? 'ok' : 'needs_attention',
			'warnings'           => $warnings,
			'missing_class_ids'   => array_values( array_unique( $missing_ids ) ),
			'empty_classes_used' => array_values( array_unique( $empty_classes ) ),
			'duplicate_roles'    => array_values( array_unique( $duplicate_roles ) ),
		];
	}

	/**
	 * Filter elements to a specific section and its descendants.
	 */
	private function filter_to_section( array $elements, string $section_id ): array {
		$ids = [ $section_id => true ];
		$result = [];

		// Build parent → children map.
		$children_map = [];
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$parent = $el['parent'] ?? '0';
			$children_map[ $parent ][] = $el;
		}

		// BFS from section_id.
		$queue = [ $section_id ];
		while ( ! empty( $queue ) ) {
			$current = array_shift( $queue );
			foreach ( $children_map[ $current ] ?? [] as $child ) {
				if ( ! is_array( $child ) ) {
					continue;
				}
				$child_id = $child['id'] ?? '';
				$ids[ $child_id ] = true;
				$queue[] = $child_id;
			}
		}

		// Filter.
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$id = $el['id'] ?? '';
			if ( isset( $ids[ $id ] ) ) {
				$result[] = $el;
			}
		}

		return $result;
	}

	/**
	 * Build a hierarchy summary string for a section.
	 */
	private function build_hierarchy_summary( array $elements, string $root_id, int $depth = 0 ): string {
		$element = null;
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( ( $el['id'] ?? '' ) === $root_id ) {
				$element = $el;
				break;
			}
		}

		if ( ! $element ) {
			return '';
		}

		$name     = $element['name'] ?? 'unknown';
		$settings = $element['settings'] ?? [];
		$label    = is_array( $settings ) ? ( $settings['label'] ?? '' ) : '';
		$display  = $label ? "{$name}({$label})" : $name;

		// Find children.
		$children = array_filter(
			$elements,
			fn( $el ) => is_array( $el ) && ( $el['parent'] ?? '' ) === $root_id
		);

		if ( empty( $children ) ) {
			return $display;
		}

		$child_summaries = [];
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$child_summaries[] = $this->build_hierarchy_summary( $elements, $child['id'] ?? '', $depth + 1 );
		}

		return $display . ' > [' . implode( ', ', $child_summaries ) . ']';
	}

	/**
	 * Register the verify_build tool.
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'verify_build',
			__( "Post-build verification. Call after build_structure + populate_content to confirm the result matches your design intent.\n\n"
				. "Returns: element count, type counts, classes used, labels, and hierarchy of the last section built.\n"
				. "Compare against your design_plan to verify nothing was lost or mangled.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'page_id'     => array(
						'type'        => 'integer',
						'description' => __( 'Page ID to verify.', 'bricks-mcp' ),
					),
					'template_id' => array(
						'type'        => 'integer',
						'description' => __( 'Template ID to verify (alternative to page_id).', 'bricks-mcp' ),
					),
					'section_id'  => array(
						'type'        => 'string',
						'description' => __( 'Optional: specific section element ID to verify. If omitted, verifies the whole page.', 'bricks-mcp' ),
					),
					'include_visual_plan' => array(
						'type'        => 'boolean',
						'description' => __( 'Default false. When true, response includes verification_plan with page_url + section_selector + a ~2 KB Playwright JS snippet for browser_evaluate. Skip this unless you have a headless browser; element-data verification works without it.', 'bricks-mcp' ),
					),
				),
			),
			array( $this, 'handle' ),
			array( 'readOnlyHint' => true )
		);
	}

	/**
	 * Extract content sample from elements for verification.
	 *
	 * Returns actual text content from headings, buttons, and text elements
	 * so the AI can verify real content was set. Also detects placeholder text.
	 *
	 * @param array<int, array<string, mixed>> $elements Flat element array.
	 * @return array<string, mixed> Content sample with placeholder detection.
	 */
	private function extract_content_sample( array $elements ): array {
		$registry = \BricksMCP\MCP\Services\ElementSettingsGenerator::get_element_registry();
		$headings = [];
		$buttons  = [];
		$texts    = [];
		$has_placeholder = false;

		$placeholder_patterns = [ '[PLACEHOLDER', '[placeholder', 'Lorem ipsum', 'lorem ipsum', '[YOUR', '[TITLE', '[HEADING', '[DESCRIPTION', '[CONTENT' ];

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$name     = $el['name'] ?? '';
			$settings = $el['settings'] ?? [];
			if ( ! is_array( $settings ) ) {
				continue;
			}
			$key = $registry[ $name ]['content_key'] ?? null;

			if ( null === $key || ! isset( $settings[ $key ] ) ) {
				continue;
			}

			$value = $settings[ $key ];
			if ( ! is_string( $value ) ) {
				continue;
			}

			$clean = strip_tags( $value );
			if ( '' === $clean ) {
				continue;
			}

			// Check for placeholder content.
			foreach ( $placeholder_patterns as $pattern ) {
				if ( str_contains( $value, $pattern ) ) {
					$has_placeholder = true;
					break;
				}
			}

			// Categorize by element type.
			if ( 'heading' === $name ) {
				$headings[] = mb_substr( $clean, 0, 80 );
			} elseif ( 'button' === $name ) {
				$buttons[] = $clean;
			} else {
				$texts[] = mb_substr( $clean, 0, 100 );
			}
		}

		return [
			'headings'              => array_slice( $headings, 0, self::CONTENT_SAMPLE_HEADINGS_LIMIT ),
			'buttons'               => array_slice( $buttons, 0, self::CONTENT_SAMPLE_BUTTONS_LIMIT ),
			'texts'                 => array_slice( $texts, 0, self::CONTENT_SAMPLE_TEXTS_LIMIT ),
			'text_element_count'    => count( $headings ) + count( $buttons ) + count( $texts ),
			'has_placeholder_content' => $has_placeholder,
		];
	}
}
