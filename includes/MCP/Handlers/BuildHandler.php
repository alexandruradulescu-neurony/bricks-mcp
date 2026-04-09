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
		$page_id = (int) $schema['target']['page_id'];
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

		// Step 4: Extract element types and class intents.
		$element_types = $this->validator->extract_element_types( $schema );
		$class_intents = $this->validator->extract_class_intents( $schema );

		// Step 5: Resolve class intents to global class IDs.
		$class_result = $this->class_resolver->resolve( $class_intents );
		$class_map    = $class_result['map'];

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
				$element_tree   = $this->settings_generator->generate( $section['structure'], $class_map, $design_context );
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

		// Step 10: Write to Bricks (with error recovery).
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
}
