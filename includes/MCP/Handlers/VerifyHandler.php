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
use BricksMCP\MCP\ToolRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VerifyHandler {

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
			$name = $el['name'] ?? 'unknown';
			$type_counts[ $name ] = ( $type_counts[ $name ] ?? 0 ) + 1;

			$settings = $el['settings'] ?? [];
			if ( ! empty( $settings['label'] ) ) {
				$labels[] = $settings['label'];
			}
			if ( ! empty( $settings['_cssGlobalClasses'] ) ) {
				foreach ( $settings['_cssGlobalClasses'] as $class_id ) {
					$classes_used[ $class_id ] = true;
				}
			}
		}

		// Resolve class IDs to names.
		$all_classes = $this->bricks_service->get_global_class_service()->get_global_classes();
		$class_id_to_name = [];
		foreach ( $all_classes as $cls ) {
			$class_id_to_name[ $cls['id'] ?? '' ] = $cls['name'] ?? '';
		}

		$class_names = [];
		foreach ( array_keys( $classes_used ) as $id ) {
			$class_names[] = $class_id_to_name[ $id ] ?? $id;
		}

		// Build hierarchy tree for last section (most recently built).
		$sections = array_filter( $elements, fn( $el ) => ( $el['name'] ?? '' ) === 'section' && ( $el['parent'] ?? '' ) === '0' );
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

		return [
			'page_id'           => $page_id,
			'page_description'  => $page_description,
			'sections'          => $described_sections,
			'element_count'     => count( $elements ),
			'type_counts'       => $type_counts,
			'classes_used'      => array_values( array_unique( $class_names ) ),
			'labels'            => $labels,
			'last_section'      => $hierarchy,
			'section_count'     => count( $sections ),
			'status'            => 'ok',
			'verification'      => 'Compare page_description and sections[*].description with your design intent. Compare type_counts and classes_used against your design_plan to verify structural match.',
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
			$parent = $el['parent'] ?? '0';
			$children_map[ $parent ][] = $el;
		}

		// BFS from section_id.
		$queue = [ $section_id ];
		while ( ! empty( $queue ) ) {
			$current = array_shift( $queue );
			foreach ( $children_map[ $current ] ?? [] as $child ) {
				$child_id = $child['id'] ?? '';
				$ids[ $child_id ] = true;
				$queue[] = $child_id;
			}
		}

		// Filter.
		foreach ( $elements as $el ) {
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
			if ( ( $el['id'] ?? '' ) === $root_id ) {
				$element = $el;
				break;
			}
		}

		if ( ! $element ) {
			return '';
		}

		$name = $element['name'] ?? 'unknown';
		$label = $element['settings']['label'] ?? '';
		$display = $label ? "{$name}({$label})" : $name;

		// Find children.
		$children = array_filter( $elements, fn( $el ) => ( $el['parent'] ?? '' ) === $root_id );

		if ( empty( $children ) ) {
			return $display;
		}

		$child_summaries = [];
		foreach ( $children as $child ) {
			$child_summaries[] = $this->build_hierarchy_summary( $elements, $child['id'], $depth + 1 );
		}

		return $display . ' > [' . implode( ', ', $child_summaries ) . ']';
	}

	/**
	 * Register the verify_build tool.
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'verify_build',
			__( "Post-build verification. Call after build_from_schema to confirm the result matches your design intent.\n\n"
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
				),
			),
			array( $this, 'handle' ),
			array( 'readOnlyHint' => true )
		);
	}
}
