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
		ElementSettingsGenerator $settings_generator
	) {
		$this->bricks_service     = $bricks_service;
		$this->validator          = $validator;
		$this->class_resolver     = $class_resolver;
		$this->expander           = $expander;
		$this->settings_generator = $settings_generator;
	}

	/**
	 * Handle the build_from_schema tool call.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'schema' and optional 'dry_run'.
	 * @return array<string, mixed>|\WP_Error Build result or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$schema  = $args['schema'] ?? null;
		$dry_run = ! empty( $args['dry_run'] );

		if ( null === $schema || ! is_array( $schema ) ) {
			return new \WP_Error(
				'missing_schema',
				__( 'schema parameter is required and must be an object.', 'bricks-mcp' )
			);
		}

		// Step 1: Validate the schema.
		$validation = $this->validator->validate( $schema );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

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

		// Step 8: Generate Bricks element trees from expanded sections.
		$design_context = $expanded['design_context'] ?? [];
		$all_elements   = [];

		foreach ( $expanded['sections'] as $section ) {
			if ( ! empty( $section['structure'] ) ) {
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

		// Step 9: Build summary.
		$element_count = $this->count_elements( $all_elements );
		$tree_summary  = $this->build_tree_summary( $all_elements );

		// Dry run: return what would be built without writing.
		if ( $dry_run ) {
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
				sprintf( __( 'Failed to write elements: %s', 'bricks-mcp' ), $e->getMessage() ),
				[
					'partial_result'  => true,
					'classes_created' => $class_result['classes_created'],
					'elements_count'  => $element_count,
				]
			);
		}

		return [
			'success'          => true,
			'page_id'          => $page_id,
			'action'           => $target['action'],
			'elements_created' => $element_count,
			'classes_created'  => $class_result['classes_created'],
			'classes_reused'   => $class_result['classes_reused'],
			'tree_summary'     => $tree_summary,
			'snapshot_id'      => $snapshot_id,
		];
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
			$count++;
			if ( ! empty( $element['children'] ) ) {
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
			$name = $element['name'] ?? 'unknown';
			if ( ! empty( $element['children'] ) ) {
				$children_summary = $this->build_tree_summary( $element['children'], $depth + 1 );
				$parts[]          = "{$name} > [{$children_summary}]";
			} else {
				$parts[] = $name;
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Register the build_from_schema tool with the given registry.
	 *
	 * @param ToolRegistry $registry Tool registry instance.
	 * @return void
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'build_from_schema',
			__( "Build Bricks page content from a declarative design schema. The schema describes structure, layout, and class intents; the MCP handles all Bricks mechanics (element IDs, settings, class resolution, normalization).\n\nAccepts a design schema with target (page_id, action), design_context (summary, mood, spacing), sections with nested structure trees, and optional patterns. Returns created elements, resolved classes, and a tree summary.\n\nUse dry_run: true to validate and preview without writing.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'schema'  => array(
						'type'        => 'object',
						'description' => __( "Design schema object with target, design_context, sections, and optional patterns. See tool description for full format.\n\n"
							. "SCHEMA FORMAT:\n"
							. "{\n"
							. "  \"target\": { \"page_id\": int, \"action\": \"append\"|\"replace\", \"parent_id\"?: string, \"position\"?: int },\n"
							. "  \"design_context\": { \"summary\": string, \"mood\"?: string, \"palette_hints\"?: string[], \"spacing\"?: \"compact\"|\"normal\"|\"spacious\" },\n"
							. "  \"sections\": [{ \"intent\": string, \"background\"?: \"dark\"|\"light\"|\"gradient\", \"structure\": <node> }],\n"
							. "  \"patterns\"?: { \"pattern_name\": <node> }\n"
							. "}\n\n"
							. "STRUCTURE NODE FORMAT:\n"
							. "{\n"
							. "  \"type\": string,           // Bricks element name (section, container, block, div, heading, text-basic, button, image, icon, tabs-nested, etc.)\n"
							. "  \"content\"?: string,        // Text content — mapped to correct Bricks key (text for heading/text-basic/button, content for icon-box/alert)\n"
							. "  \"tag\"?: string,            // HTML tag (h1-h6, p, ul, li, figure, address)\n"
							. "  \"label\"?: string,          // Editor label for structural elements\n"
							. "  \"class_intent\"?: string,   // Semantic class purpose — matched to existing global classes by name, or created if new\n"
							. "  \"layout\"?: \"grid\",         // Set on block/div to enable CSS grid\n"
							. "  \"columns\"?: int,           // Grid column count (used with layout:grid)\n"
							. "  \"responsive\"?: { \"tablet\"?: int, \"mobile\"?: int },  // Column overrides per breakpoint\n"
							. "  \"responsive_overrides\"?: { \"breakpoint\": { \"_key\": value } },  // Per-breakpoint style overrides using composite keys\n"
							. "  \"src\"?: string,            // Image source: attachment ID (\"105\"), URL, or \"unsplash:query\"\n"
							. "  \"icon\"?: string|object,    // Icon shorthand (\"truck\" → ti-truck) or full {library, icon} object\n"
							. "  \"ref\"?: string,            // Reference to a pattern defined in \"patterns\"\n"
							. "  \"repeat\"?: int,            // Repeat the referenced pattern N times\n"
							. "  \"data\"?: array,            // Array of data objects for each repeat instance\n"
							. "  \"style_overrides\"?: {},    // Raw Bricks settings merged last (for _hidden, _background, etc.)\n"
							. "  \"children\"?: [<node>]      // Nested child nodes\n"
							. "}\n\n"
							. "DATA SUBSTITUTION: In patterns, use \"data.key\" prefix for values (e.g. \"content\": \"data.title\"). Bare keys are NOT matched. Interpolation: \"Hello {data.name}\".\n\n"
							. "KEY RULES:\n"
							. "- Section > container > block/div > content elements. Use multiple containers for multiple rows.\n"
							. "- Use style_overrides for _hidden (tab-menu, tab-title, tab-content, tab-pane CSS classes).\n"
							. "- Use layout:grid + columns for grid layouts. The MCP generates _display:grid + _gridTemplateColumns.\n"
							. "- class_intent matches by semantic name, not CSS similarity. \"hero-title\" won't match \"section-title\".\n"
							. "- target accepts page_id or template_id (for headers, footers, content templates).\n\n"
							. "EXAMPLE SCHEMA:\n"
							. "{\"target\":{\"page_id\":94,\"action\":\"append\"},\"design_context\":{\"summary\":\"CTA section\",\"spacing\":\"normal\"},\"sections\":[{\"intent\":\"Call to action\",\"background\":\"dark\",\"structure\":{\"type\":\"section\",\"label\":\"CTA\",\"children\":[{\"type\":\"container\",\"children\":[{\"type\":\"text-basic\",\"content\":\"Tagline\"},{\"type\":\"heading\",\"tag\":\"h2\",\"content\":\"Ready to start?\"},{\"type\":\"button\",\"content\":\"Contact Us\"}]}]}}]}", 'bricks-mcp' ),
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => __( 'When true, validate and resolve but do not write. Returns preview of what would be built.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'schema' ),
			),
			array( $this, 'handle' )
		);
	}
}
