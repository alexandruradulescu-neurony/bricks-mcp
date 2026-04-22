<?php
/**
 * Build structure handler (v3.28.0).
 *
 * Phase 1 of the two-tier build pipeline. Takes a structure-only schema,
 * emits elements with classes + role_map, returns section_id for phase 2
 * (populate_content).
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\ToolRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BuildStructureHandler {

	/** Content fields forbidden in build_structure schemas. */
	private const FORBIDDEN_CONTENT_FIELDS = [
		'content', 'content_example', 'label', 'text', 'title', 'description',
		'link', 'href', 'icon', 'image', 'src', 'url', 'placeholder',
	];

	public function __construct( private BuildHandler $delegate ) {}

	/**
	 * Handle build_structure tool invocation.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Build result with role_map, or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$schema = $args['schema'] ?? [];
		if ( ! is_array( $schema ) || empty( $schema ) ) {
			return new \WP_Error( 'missing_schema', 'schema is required.' );
		}

		$offending = $this->scan_for_content_fields( $schema );
		if ( ! empty( $offending ) ) {
			return new \WP_Error(
				'content_in_structure',
				'build_structure schema must not contain content fields. Use populate_content after.',
				[
					'offending_paths' => $offending,
					'next_step'       => 'Remove content fields from schema. Pass content separately via populate_content with section_id from this response.',
				]
			);
		}

		// Extract role_map from the input schema before delegating, because
		// BuildHandler::handle() does not return element IDs or roles in its
		// normal (non-dry-run) response — only tree_summary and counts.
		// role_map is keyed by schema label/class_intent with null values as
		// placeholders; populate_content resolves them by querying the built page.
		$role_map = $this->extract_role_map_from_schema( $schema );

		// Delegate element emission to existing BuildHandler.
		// The _internal flag signals Task 4.4's deprecation wrapper to skip the
		// "use build_structure instead" nudge for this programmatic call.
		$result = $this->delegate->handle( array_merge( $args, [ '_internal' => true ] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_merge(
			$result,
			[
				'role_map'  => $role_map,
				'next_step' => 'Call populate_content with section_id + content_map keyed by role.',
			]
		);
	}

	/**
	 * Recursively scan a schema node for forbidden content field keys.
	 *
	 * @param array<string, mixed> $node  Schema node to scan.
	 * @param string               $path  Dot-separated path prefix for reporting.
	 * @return list<string> Dot-separated paths of offending keys.
	 */
	private function scan_for_content_fields( array $node, string $path = '' ): array {
		$offending = [];
		foreach ( $node as $key => $value ) {
			$sub_path = $path === '' ? (string) $key : $path . '.' . $key;
			if ( in_array( $key, self::FORBIDDEN_CONTENT_FIELDS, true ) ) {
				$offending[] = $sub_path;
			}
			if ( is_array( $value ) ) {
				$offending = array_merge( $offending, $this->scan_for_content_fields( $value, $sub_path ) );
			}
		}
		return $offending;
	}

	/**
	 * Extract a role_map from the input schema.
	 *
	 * BuildHandler::handle() does not return element IDs or role keys in its
	 * non-dry-run response (only counts + tree_summary). Role extraction must
	 * therefore happen against the input schema nodes, using class_intent as
	 * the stable role identifier that populate_content can reference.
	 *
	 * Returns an array keyed by class_intent (or label when class_intent is
	 * absent) with null values; populate_content resolves the actual element
	 * IDs by querying the page after building.
	 *
	 * @param array<string, mixed> $schema Full design schema.
	 * @return array<string, null> role => null placeholder map.
	 */
	private function extract_role_map_from_schema( array $schema ): array {
		$map = [];
		foreach ( $schema['sections'] ?? [] as $section ) {
			if ( ! empty( $section['structure'] ) && is_array( $section['structure'] ) ) {
				$this->collect_roles_recursive( $section['structure'], $map );
			}
		}
		return $map;
	}

	/**
	 * Walk a structure node tree and collect every class_intent (or label).
	 *
	 * @param array<string, mixed>  $node Schema structure node.
	 * @param array<string, null>  &$map  Role map accumulator (passed by reference).
	 */
	private function collect_roles_recursive( array $node, array &$map ): void {
		$role = $node['class_intent'] ?? $node['role'] ?? null;
		if ( is_string( $role ) && $role !== '' ) {
			$map[ $role ] = null;
		}
		foreach ( $node['children'] ?? [] as $child ) {
			if ( is_array( $child ) ) {
				$this->collect_roles_recursive( $child, $map );
			}
		}
	}

	/**
	 * Register the build_structure tool with the MCP tool registry.
	 *
	 * @param ToolRegistry $registry Tool registry instance.
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'build_structure',
			__( "Phase 1 of two-tier build. Takes structure-only schema (no content). Returns section_id + role_map + class creation summary. Call populate_content next.", 'bricks-mcp' ),
			[
				'type'       => 'object',
				'properties' => [
					'proposal_id' => [
						'type'        => 'string',
						'description' => __( 'Proposal ID from propose_design phase 2.', 'bricks-mcp' ),
					],
					'schema'      => [
						'type'        => 'object',
						'description' => __( 'Structure-only schema. Content fields (content, label, link, etc.) forbidden.', 'bricks-mcp' ),
					],
				],
				'required'   => [ 'proposal_id', 'schema' ],
			],
			[ $this, 'handle' ]
		);
	}
}
