<?php
/**
 * Build handler for MCP Router.
 *
 * Processes design schemas into Bricks elements via the build_from_schema pipeline:
 * validate → extract intents → resolve classes → expand patterns → generate settings → normalize → write.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\ClassIntentResolver;
use BricksMCP\MCP\Services\DesignSchemaValidator;
use BricksMCP\MCP\Services\ElementSettingsGenerator;
use BricksMCP\MCP\Services\SchemaExpander;
use BricksMCP\MCP\ToolRegistry;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the build_from_schema tool.
 */
final class BuildHandler {

	/**
	 * @var BricksService
	 */
	private BricksService $bricks_service;

	/**
	 * @var DesignSchemaValidator
	 */
	private DesignSchemaValidator $validator;

	/**
	 * @var ClassIntentResolver
	 */
	private ClassIntentResolver $class_resolver;

	/**
	 * @var SchemaExpander
	 */
	private SchemaExpander $expander;

	/**
	 * @var ElementSettingsGenerator
	 */
	private ElementSettingsGenerator $settings_generator;

	/**
	 * @var \BricksMCP\MCP\Services\ProposalService
	 */
	private \BricksMCP\MCP\Services\ProposalService $proposal_service;

	/**
	 * Constructor.
	 *
	 * @param BricksService            $bricks_service     Bricks service instance.
	 * @param DesignSchemaValidator     $validator          Schema validator.
	 * @param ClassIntentResolver       $class_resolver     Class intent resolver.
	 * @param SchemaExpander            $expander           Schema expander.
	 * @param ElementSettingsGenerator  $settings_generator Element settings generator.
	 */
	public function __construct(
		BricksService $bricks_service,
		DesignSchemaValidator $validator,
		ClassIntentResolver $class_resolver,
		SchemaExpander $expander,
		ElementSettingsGenerator $settings_generator,
		\BricksMCP\MCP\Services\ProposalService $proposal_service
	) {
		$this->bricks_service     = $bricks_service;
		$this->validator          = $validator;
		$this->class_resolver     = $class_resolver;
		$this->expander           = $expander;
		$this->settings_generator = $settings_generator;
		$this->proposal_service   = $proposal_service;
	}

	/**
	 * Handle the build_from_schema tool call.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'schema' and optional 'dry_run'.
	 * @return array<string, mixed>|\WP_Error Build result or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$schema      = $args['schema'] ?? null;
		$dry_run_raw = $args['dry_run'] ?? false;
		$dry_run     = false !== $dry_run_raw && '' !== $dry_run_raw;
		$dry_run_summary = is_string( $dry_run_raw ) && 'summary' === $dry_run_raw;

		if ( null === $schema || ! is_array( $schema ) ) {
			return new \WP_Error(
				'missing_schema',
				__( 'schema parameter is required and must be an object.', 'bricks-mcp' )
			);
		}

		// Step 0: Require a valid design proposal.
		$proposal_id = $args['proposal_id'] ?? '';
		if ( '' === $proposal_id ) {
			return new \WP_Error(
				'missing_proposal',
				__( 'proposal_id is required. Call propose_design first to create a validated design proposal, then pass the returned proposal_id here.', 'bricks-mcp' )
			);
		}

		if ( ! $this->proposal_service->validate( $proposal_id ) ) {
			return new \WP_Error(
				'invalid_proposal',
				__( 'Proposal has expired or does not exist. Call propose_design again to create a new proposal (proposals expire after 10 minutes).', 'bricks-mcp' )
			);
		}

		// Step 1: Validate the schema.
		$validation = $this->validator->validate( $schema );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Collect non-blocking validation warnings (grid/content/responsive checks).
		$schema_warnings = $this->validator->get_warnings();

		// Step 2: Check protected page.
		$page_id = (int) ( $schema['target']['page_id'] ?? $schema['target']['template_id'] ?? 0 );
		$protect = $this->bricks_service->check_protected_page( $page_id );
		if ( is_wp_error( $protect ) ) {
			return $protect;
		}

		// Step 3: Handle replace action — requires confirmation.
		if ( 'replace' === $schema['target']['action'] && empty( $args['confirm'] ) ) {
			$existing = $this->bricks_service->get_elements( $page_id );
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf(
					/* translators: 1: Page ID, 2: Existing element count */
					__( 'This will replace ALL %2$d elements on page %1$d with the design schema output. Set confirm: true to proceed.', 'bricks-mcp' ),
					$page_id,
					count( $existing )
				)
			);
		}

		// Step 4: Extract element types, class intents, and class styles.
		$element_types = $this->validator->extract_element_types( $schema );
		$class_intents = $this->validator->extract_class_intents( $schema );
		$style_map     = $this->validator->extract_class_styles( $schema );

		// Step 5: Resolve class intents to global class IDs.
		// In dry_run mode, only match existing classes — never create new ones.
		// Passes style_map so new classes are created WITH their styles.
		$class_result       = $this->class_resolver->resolve( $class_intents, $dry_run, $style_map );
		$class_map          = $class_result['map'];
		$classes_with_styles = $class_result['classes_with_styles'] ?? [];

		// Step 6: Pre-fetch element schemas for all referenced types.
		$this->settings_generator->prefetch_schemas( $element_types );

		// Step 7: Expand patterns and repeats.
		$expanded = $this->expander->expand( $schema );

		// Step 7b: Re-validate expanded sections (catches hierarchy violations from expansion).
		$expanded_sections = $expanded['sections'] ?? [];
		if ( ! is_array( $expanded_sections ) ) {
			$expanded_sections = [];
		}
		foreach ( $expanded_sections as $idx => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			if ( ! empty( $section['structure'] ) && is_array( $section['structure'] ) ) {
				$expansion_errors = [];
				$this->validator->validate_expanded_node( $section['structure'], "sections[{$idx}].structure", $expansion_errors );
				if ( ! empty( $expansion_errors ) ) {
					return new \WP_Error(
						'invalid_expanded_schema',
						sprintf( 'Expanded schema has %d error(s): %s', count( $expansion_errors ), implode( '; ', $expansion_errors ) ),
						[ 'errors' => $expansion_errors ]
					);
				}
			}
		}

		// Step 8: Generate Bricks element trees from expanded sections.
		$design_context = $expanded['design_context'] ?? [];
		if ( ! is_array( $design_context ) ) {
			$design_context = [];
		}
		$all_elements = [];

		foreach ( $expanded_sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			if ( ! empty( $section['structure'] ) && is_array( $section['structure'] ) ) {
				// Apply section-level background hint to the root structure node.
				if ( ! empty( $section['background'] ) && empty( $section['structure']['background'] ) ) {
					$section['structure']['background'] = $section['background'];
				}
				$element_tree   = $this->settings_generator->generate( $section['structure'], $class_map, $design_context, $classes_with_styles );
				$all_elements[] = $element_tree;
			}
		}

		if ( empty( $all_elements ) ) {
			return new \WP_Error(
				'empty_build',
				__( 'Schema produced no elements. Check that sections have valid structure definitions.', 'bricks-mcp' )
			);
		}

		// Step 8c: Post-process — fix block width inside flex-row parents.
		// Bricks blocks default to width:100%. Inside a flex-row parent, child blocks
		// should be width:auto to prevent stretching the full row.
		foreach ( $all_elements as &$tree ) {
			$this->fix_block_widths_in_rows( $tree );
		}
		unset( $tree );

		// Step 8d: Post-build class extraction — deduplicate shared inline styles.
		// Scan elements for identical inline style fingerprints. When 2+ elements share
		// the same styles and have no class_intent, extract those styles into a new global class.
		if ( ! $dry_run ) {
			$extracted = $this->extract_shared_styles_to_classes( $all_elements );
			$class_result['classes_created'] = array_merge( $class_result['classes_created'], $extracted['classes_created'] );
		}

		// Initialize pipeline warnings collector (populated by steps below + element-level warnings).
		$pipeline_warnings = $schema_warnings; // Seed with non-blocking validation warnings (grid, content, responsive).

		// Step 8e: Knowledge nudges — warn when building elements whose domain knowledge wasn't fetched.
		$fetched_knowledge = BricksToolHandler::get_fetched_knowledge();
		$element_types     = $this->validator->extract_element_types( $schema );
		$knowledge_map     = [
			'form'             => 'forms',
			'slider-nested'    => 'building',
			'accordion-nested' => 'building',
			'tabs-nested'      => 'building',
			'nav-nested'       => 'building',
			'popup'            => 'popups',
			'offcanvas'        => 'popups',
		];
		$missing_domains = [];
		foreach ( $element_types as $et ) {
			$domain = $knowledge_map[ $et ] ?? null;
			if ( null !== $domain && ! isset( $fetched_knowledge[ $domain ] ) && ! isset( $missing_domains[ $domain ] ) ) {
				$missing_domains[ $domain ] = $et;
			}
		}
		foreach ( $missing_domains as $domain => $trigger_element ) {
			$pipeline_warnings[] = sprintf(
				"Building '%s' without reading domain knowledge. Call bricks:get_knowledge('%s') for correct settings, gotchas, and examples.",
				$trigger_element,
				$domain
			);
		}

		// Step 9: Build summary.
		$element_count = $this->count_elements( $all_elements );
		$tree_summary  = $this->build_tree_summary( $all_elements );

		// Step 9b: Element count reconciliation.
		// Compare intended (from input schema) vs actual (from generated tree).
		// Pattern expansion (ref + repeat) legitimately produces more elements than
		// the input schema describes, so only warn when actual < intended (lost elements).
		$intended_count = $this->count_intended_elements( $schema );
		if ( $intended_count > 0 && $element_count < $intended_count ) {
			$pipeline_warnings[] = sprintf(
				'Element count mismatch: schema described %d element(s) but only %d were generated. Some elements may have been dropped during expansion.',
				$intended_count,
				$element_count
			);
		}
		// Log counts for diagnostics — only when WP_DEBUG is active. Previously this
		// fired on every production build, bloating error logs.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'BricksMCP build: intended=%d, generated=%d elements.', $intended_count, $element_count ) );
		}

		// Extract and strip _pipeline_warnings from element settings.
		// These are internal markers from ElementSettingsGenerator for problems
		// that couldn't be hard-errored (e.g. Unsplash sideload failed, image
		// slot left empty). Surface them in the response so the AI knows to fix.
		$pipeline_warnings = array_merge( $pipeline_warnings, $this->collect_and_strip_warnings( $all_elements ) );

		// Dry run: return what would be built without writing.
		if ( $dry_run ) {
			// Slim summary mode: compact response without full element tree.
			if ( $dry_run_summary ) {
				$class_intents_resolved = [];
				foreach ( $class_intents as $intent ) {
					$class_intents_resolved[ $intent ] = $class_map[ $intent ] ?? null;
				}

				$classes_created_preview = [];
				$classes_reused_preview  = [];
				foreach ( $class_intents_resolved as $intent_name => $resolved_id ) {
					if ( null === $resolved_id ) {
						$classes_created_preview[] = $intent_name;
					} else {
						$classes_reused_preview[] = $intent_name;
					}
				}

				return [
					'dry_run'                  => 'summary',
					'intended_element_count'   => $element_count,
					'tree_summary'             => $tree_summary,
					'validation_warnings'      => $pipeline_warnings,
					'class_intents_resolved'   => $class_intents_resolved,
					'classes_created_preview'  => $classes_created_preview,
					'classes_reused_preview'   => $classes_reused_preview,
				];
			}

			// Full dry-run: return complete element tree preview.
			return [
				'dry_run'          => true,
				'page_id'          => $page_id,
				'action'           => $schema['target']['action'],
				'elements_count'   => $element_count,
				'classes_created'  => $class_result['classes_created'],
				'classes_reused'   => $class_result['classes_reused'],
				'tree_summary'     => $tree_summary,
				'elements_preview' => $all_elements,
			];
		}

		// Consume the proposal (one-time use) — all validation passed, about to write.
		$this->proposal_service->consume( $proposal_id );

		// Step 10: Auto-snapshot before writing for safety.
		$snapshot = $this->bricks_service->snapshot_page( $page_id, 'Pre build_from_schema' );
		$snapshot_id = is_array( $snapshot ) ? ( $snapshot['snapshot_id'] ?? null ) : null;

		// Step 11: Write to Bricks (with error recovery).
		$target = $schema['target'];

		try {
			if ( 'replace' === $target['action'] ) {
				$normalized = $this->bricks_service->normalize_elements( $all_elements );
				$this->bricks_service->save_elements( $page_id, $normalized );
			} else {
				$parent_id = $target['parent_id'] ?? '0';
				$position  = $target['position'] ?? null;
				$result    = $this->bricks_service->append_elements( $page_id, $all_elements, $parent_id, $position );

				if ( is_wp_error( $result ) ) {
					return new \WP_Error(
						'build_write_failed',
						$result->get_error_message(),
						[
							'partial_result'  => true,
							'classes_created' => $class_result['classes_created'],
							'classes_reused'  => $class_result['classes_reused'],
							'elements_count'  => $element_count,
							'tree_summary'    => $tree_summary,
						]
					);
				}
			}
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'build_write_error',
				sprintf(
					/* translators: %s: Throwable message */
					__( 'Failed to write elements: %s', 'bricks-mcp' ),
					$e->getMessage()
				),
				[
					'partial_result'  => true,
					'classes_created' => $class_result['classes_created'],
					'elements_count'  => $element_count,
				]
			);
		}

		$response = [
			'success'          => true,
			'page_id'          => $page_id,
			'action'           => $target['action'],
			'elements_created' => $element_count,
			'classes_created'  => $class_result['classes_created'],
			'classes_reused'   => $class_result['classes_reused'],
			'tree_summary'     => $tree_summary,
			'snapshot_id'      => $snapshot_id,
		];

		if ( ! empty( $pipeline_warnings ) ) {
			$response['warnings'] = $pipeline_warnings;
		}

		$response['next_steps'] = [
			sprintf( 'Call verify_build(page_id=%d) to confirm the result matches your design intent.', $page_id ),
			'If you discovered site-specific preferences, corrections, or design patterns worth remembering, save them via bricks:add_note(text="..."). Notes persist across sessions.',
		];

		return $response;
	}

	/**
	 * Recursively collect and strip `_pipeline_warnings` from element settings.
	 *
	 * ElementSettingsGenerator attaches these to surface non-fatal issues
	 * (e.g. Unsplash sideload failed, image slot empty) that would otherwise
	 * be silent. Strip them from settings before write — Bricks rejects
	 * unknown root keys in settings.
	 *
	 * @param array<int, array<string, mixed>> $elements  Nested element trees (modified in place).
	 * @return array<int, string>  Collected warning messages.
	 */
	private function collect_and_strip_warnings( array &$elements ): array {
		$warnings = [];
		foreach ( $elements as &$el ) {
			// Defensive: settings should always be array, but guard against stdClass edge cases.
			if ( isset( $el['settings'] ) && is_array( $el['settings'] ) && isset( $el['settings']['_pipeline_warnings'] ) && is_array( $el['settings']['_pipeline_warnings'] ) ) {
				foreach ( $el['settings']['_pipeline_warnings'] as $w ) {
					$warnings[] = $w;
				}
				unset( $el['settings']['_pipeline_warnings'] );
			}
			if ( ! empty( $el['children'] ) && is_array( $el['children'] ) ) {
				$warnings = array_merge( $warnings, $this->collect_and_strip_warnings( $el['children'] ) );
			}
		}
		return $warnings;
	}

	/**
	 * Count total elements in a nested tree.
	 *
	 * @param array<int, array<string, mixed>> $elements Nested element trees.
	 * @return int Total element count.
	 */
	private function count_elements( array $elements ): int {
		$count = 0;
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$count++;
			if ( ! empty( $element['children'] ) && is_array( $element['children'] ) ) {
				$count += $this->count_elements( $element['children'] );
			}
		}
		return $count;
	}

	/**
	 * Build a human-readable tree summary.
	 *
	 * @param array<int, array<string, mixed>> $elements Nested element trees.
	 * @param int                               $depth   Current depth.
	 * @return string Tree summary string.
	 */
	private function build_tree_summary( array $elements, int $depth = 0 ): string {
		$parts = [];
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$name = $element['name'] ?? 'unknown';
			if ( ! empty( $element['children'] ) && is_array( $element['children'] ) ) {
				$children_summary = $this->build_tree_summary( $element['children'], $depth + 1 );
				$parts[]          = "{$name} > [{$children_summary}]";
			} else {
				$parts[] = $name;
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Count the number of intended elements in the input schema (pre-expansion).
	 *
	 * Walks sections and counts every node + descendant. Pattern refs count as 1
	 * (not their expanded repeat count), so the intended count is a lower bound.
	 *
	 * @param array<string, mixed> $schema Input design schema.
	 * @return int Intended element count.
	 */
	private function count_intended_elements( array $schema ): int {
		$count = 0;
		foreach ( $schema['sections'] ?? [] as $section ) {
			if ( ! empty( $section['structure'] ) ) {
				$count += $this->count_node_recursive( $section['structure'] );
			}
		}
		return $count;
	}

	/**
	 * Recursively count nodes in a structure tree.
	 *
	 * @param array<string, mixed> $node Structure node.
	 * @return int Node count (this node + descendants).
	 */
	private function count_node_recursive( array $node ): int {
		$count = 1;
		foreach ( $node['children'] ?? [] as $child ) {
			if ( is_array( $child ) ) {
				$count += $this->count_node_recursive( $child );
			}
		}
		return $count;
	}

	/**
	 * Fix block widths inside flex-row parents.
	 *
	 * Bricks blocks default to width:100%. Inside a flex-row parent, child blocks
	 * should be width:auto to prevent stretching. Walks the tree recursively.
	 *
	 * @param array $node Element tree node (modified in place).
	 */
	private function fix_block_widths_in_rows( array &$node ): void {
		$settings = $node['settings'] ?? [];
		$is_row   = ( $settings['_direction'] ?? '' ) === 'row';
		$children = &$node['children'];

		if ( ! is_array( $children ?? null ) ) {
			return;
		}

		foreach ( $children as &$child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$child_name = $child['name'] ?? '';

			// If parent is row-direction and child is a block without explicit width, set auto.
			if ( $is_row && 'block' === $child_name ) {
				if ( ! isset( $child['settings']['_width'] ) ) {
					$child['settings']['_width'] = 'auto';
				}
			}

			// Recurse.
			$this->fix_block_widths_in_rows( $child );
		}
		unset( $child );
	}

	/**
	 * Extract shared inline styles into new global classes.
	 *
	 * Walks the element tree, fingerprints style-relevant settings on each element,
	 * and when 2+ elements share the same fingerprint (and have no class already),
	 * creates a global class with those styles and assigns it to all matching elements.
	 *
	 * @param array<int, array<string, mixed>> $elements Element trees (modified in place).
	 * @return array{classes_created: string[]} Created class names.
	 */
	private function extract_shared_styles_to_classes( array &$elements ): array {
		// Style keys worth extracting into classes.
		$style_keys = [
			'_background', '_typography', '_padding', '_margin', '_border',
			'_boxShadow', '_gradient',
		];

		// Collect: fingerprint → [element references, element type, styles].
		$fingerprints = [];
		$this->collect_style_fingerprints( $elements, $style_keys, $fingerprints );

		// Filter to fingerprints shared by 2+ elements with no existing class.
		$shared = array_filter( $fingerprints, fn( $group ) => count( $group['refs'] ) >= 2 );

		if ( empty( $shared ) ) {
			return [ 'classes_created' => [] ];
		}

		$created = [];

		foreach ( $shared as $fp => $group ) {
			// Generate a class name from the element type and a short hash.
			$base_name  = $group['type'] . '-style-' . substr( $fp, 0, 6 );
			$class_args = [
				'name'   => $base_name,
				'styles' => $group['styles'],
			];

			// Clear resolver cache so newly-created class is discoverable on next resolve.
			// Return value is void/bool — kept separate to avoid clobbering the create result.
			$this->class_resolver->clear_cache();

			$result = $this->bricks_service->get_global_class_service()->create_global_class( $class_args );

			if ( is_wp_error( $result ) ) {
				continue;
			}

			$class_id = is_array( $result ) ? ( $result['id'] ?? '' ) : '';
			if ( '' === $class_id ) {
				continue;
			}

			$created[] = $base_name;

			// Apply class to all matching elements and remove the inline styles.
			foreach ( $group['refs'] as &$el_ref ) {
				if ( ! is_array( $el_ref ) ) {
					continue;
				}
				if ( ! isset( $el_ref['settings'] ) || ! is_array( $el_ref['settings'] ) ) {
					$el_ref['settings'] = [];
				}
				$el_ref['settings']['_cssGlobalClasses'] = array_merge(
					$el_ref['settings']['_cssGlobalClasses'] ?? [],
					[ $class_id ]
				);
				// Remove extracted style keys from inline settings.
				foreach ( $style_keys as $key ) {
					unset( $el_ref['settings'][ $key ] );
				}
			}
			unset( $el_ref );
		}

		return [ 'classes_created' => $created ];
	}

	/**
	 * Recursively collect style fingerprints from element trees.
	 *
	 * @param array      $elements    Element trees (by reference for later modification).
	 * @param array      $style_keys  Style keys to fingerprint.
	 * @param array      $fingerprints Collected fingerprints (by reference).
	 */
	private function collect_style_fingerprints( array &$elements, array $style_keys, array &$fingerprints ): void {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$settings = $el['settings'] ?? [];
			if ( ! is_array( $settings ) ) {
				$settings = [];
			}

			// Skip elements that already have a global class.
			if ( ! empty( $settings['_cssGlobalClasses'] ) ) {
				if ( ! empty( $el['children'] ) && is_array( $el['children'] ) ) {
					$this->collect_style_fingerprints( $el['children'], $style_keys, $fingerprints );
				}
				continue;
			}

			// Extract style-relevant keys.
			$style_data = [];
			foreach ( $style_keys as $key ) {
				if ( isset( $settings[ $key ] ) ) {
					$style_data[ $key ] = $settings[ $key ];
				}
			}

			// Only fingerprint elements with meaningful styles (2+ style keys).
			if ( count( $style_data ) >= 2 ) {
				$fp = md5( wp_json_encode( $style_data ) );
				$type = $el['name'] ?? 'element';

				if ( ! isset( $fingerprints[ $fp ] ) ) {
					$fingerprints[ $fp ] = [
						'type'   => $type,
						'styles' => $style_data,
						'refs'   => [],
					];
				}

				$fingerprints[ $fp ]['refs'][] = &$el;
			}

			// Recurse into children.
			if ( ! empty( $el['children'] ) && is_array( $el['children'] ) ) {
				$this->collect_style_fingerprints( $el['children'], $style_keys, $fingerprints );
			}
		}
		unset( $el );
	}

	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'build_from_schema',
			__( "Build Bricks page content from a design schema.\n\n"
				. "REQUIRED WORKFLOW:\n"
				. "1. Call propose_design first — it returns proposal_id + suggested_schema\n"
				. "2. Take the suggested_schema JSON object as-is\n"
				. "3. Replace [PLACEHOLDER] text content with real content\n"
				. "4. Pass the modified schema here along with the proposal_id\n\n"
				. "DO NOT write schemas from scratch. DO NOT invent keys. The suggested_schema from propose_design has the correct structure.\n\n"
				. "Valid node keys: type, tag, label, content, class_intent, style_overrides, responsive_overrides, layout, columns, responsive, background, children, icon, src, ref, repeat, data.\n"
				. "Any other key (settings, _background, _padding, styles, css, etc.) will be REJECTED.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'proposal_id' => array(
						'type'        => 'string',
						'description' => __( 'REQUIRED. The proposal_id returned by propose_design. Call propose_design first.', 'bricks-mcp' ),
					),
					'schema'  => array(
						'type'        => 'object',
						'description' => __( 'REQUIRED. The design schema — use suggested_schema from propose_design as your starting point. Replace [PLACEHOLDER] content with real text. Do NOT modify the structure, class_intents, or style_overrides unless you have a specific reason.', 'bricks-mcp' ),
					),
					'dry_run' => array(
						'description' => __( 'When true, validate and return full element preview without writing. When "summary", return a slim response with element count, tree summary, and class resolution only (no full element tree). Default: false.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'proposal_id', 'schema' ),
			),
			array( $this, 'handle' )
		);
	}
}
