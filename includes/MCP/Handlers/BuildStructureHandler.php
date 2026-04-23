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
use BricksMCP\MCP\Services\ContentContractService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BuildStructureHandler {

	/**
	 * Content fields forbidden in build_structure schemas.
	 *
	 * Excluded deliberately:
	 *   - label: Bricks structural metadata (section/container organization), not user-facing content
	 *   - title: commonly used as tooltip/aria on interactive elements, not only content
	 *   - description: same
	 *
	 * Content generation belongs in populate_content; these rejected fields are the ones
	 * the suggested_schema from propose_design never emits but user-modified schemas might.
	 */
	private const FORBIDDEN_CONTENT_FIELDS = [
		'content', 'content_example', 'text',
		'link', 'href',
		'icon', 'image', 'src', 'url',
		'placeholder',
	];

	/**
	 * Top-level keys that must be skipped by the content-field scanner.
	 * These are schema-routing / structural metadata, not element content.
	 */
	private const SCAN_SKIP_TOP_KEYS = [ 'target', 'design_context', 'intent' ];

	public function __construct(
		private BuildHandler $delegate,
		private ?\BricksMCP\MCP\Services\BricksService $bricks = null,
		private ?\BricksMCP\MCP\Services\ProposalService $proposal_service = null,
		private ?\BricksMCP\MCP\Services\GlobalClassService $classes = null,
		private ?\BricksMCP\MCP\Services\GlobalVariableService $variables = null
	) {}

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

		// v3.28.6: tag each element carrying a `role` with `label = role` so
		// Bricks stores the role name as the element label. populate_content
		// resolves content_map keys by scanning element labels. Without this,
		// role-keyed populate misses every element.
		$schema = $this->tag_labels_from_roles( $schema );

		// Normalize class_intent: downstream DesignSchemaValidator + ClassIntentResolver
		// treat class_intent as a scalar (string). v3.28.0 introduced structured
		// class_intent objects ({block, modifier?, element?}) and loose strings.
		// Convert every class_intent in the schema tree to its normalized BEM string
		// before delegating — otherwise array values trip "Illegal offset type".
		$schema = $this->normalize_class_intents( $schema );
		$args['schema'] = $schema;

		// v3.29: auto-provision pattern classes + variables from proposal manifest.
		$proposal_id           = $args['proposal_id'] ?? '';
		$provisioned_classes   = [];
		$provisioned_variables = [];
		$provisioning_errors   = [];
		if ( $proposal_id !== '' && $this->proposal_service !== null ) {
			// Read transient directly (non-destructive) — consume() deletes the
			// transient, which would prevent BuildHandler (delegated below) from
			// validating the same proposal_id.
			$proposal = get_transient( 'bricks_mcp_proposal_' . $proposal_id );

			if ( is_array( $proposal ) && ! empty( $proposal['provisioning_manifest'] ) ) {
				$manifest = $proposal['provisioning_manifest'];

				// Classes.
				if ( ! empty( $manifest['classes'] ) && is_array( $manifest['classes'] ) && $this->classes !== null ) {
					foreach ( $manifest['classes'] as $name => $def ) {
						if ( ! is_string( $name ) || $name === '' ) continue;
						if ( $this->classes->exists_by_name( $name ) ) continue;
						$created = $this->classes->create_from_payload( $def );
						if ( is_string( $created ) && $created !== '' ) {
							$provisioned_classes[] = $created;
						} else {
							$provisioning_errors[] = [ 'kind' => 'class', 'name' => $name, 'error' => 'create_from_payload returned empty' ];
						}
					}
				}

				// Variables.
				if ( ! empty( $manifest['variables'] ) && is_array( $manifest['variables'] ) && $this->variables !== null ) {
					foreach ( $manifest['variables'] as $name => $def ) {
						if ( ! is_string( $name ) || $name === '' ) continue;
						if ( $this->variables->exists( $name ) ) continue;
						$ok = $this->variables->create_from_payload( $name, $def );
						if ( $ok ) {
							$provisioned_variables[] = $name;
						} else {
							$provisioning_errors[] = [ 'kind' => 'variable', 'name' => $name, 'error' => 'create_from_payload failed' ];
						}
					}
				}
			}
		}

		// v3.28.6: capture pre-build element IDs so we can diff post-build to
		// identify the newly-created section + its descendants. This enables a
		// complete role_map with real element IDs (not null placeholders) and
		// returns the section_id populate_content needs.
		$page_id       = (int) ( $schema['target']['page_id'] ?? $schema['target']['template_id'] ?? 0 );
		$pre_build_ids = [];
		if ( $page_id > 0 && $this->bricks !== null ) {
			$pre_elements = $this->bricks->get_elements( $page_id );
			if ( is_array( $pre_elements ) ) {
				foreach ( $pre_elements as $el ) {
					if ( isset( $el['id'] ) ) {
						$pre_build_ids[ (string) $el['id'] ] = true;
					}
				}
			}
		}

		// Delegate element emission to existing BuildHandler.
		// The _internal flag signals Task 4.4's deprecation wrapper to skip the
		// "use build_structure instead" nudge for this programmatic call.
		$result = $this->delegate->handle( array_merge( $args, [ '_internal' => true ] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Post-build: find new elements, section_id, and build role_map by label.
		$section_id      = null;
		$role_map        = [];
		$role_collisions = [];
		if ( $page_id > 0 && $this->bricks !== null ) {
			$post_elements = $this->bricks->get_elements( $page_id );
			if ( is_array( $post_elements ) ) {
				foreach ( $post_elements as $el ) {
					$id = (string) ( $el['id'] ?? '' );
					if ( $id === '' || isset( $pre_build_ids[ $id ] ) ) {
						continue; // pre-existing element
					}
					// New element. Is it a section at root? Capture first as section_id.
					$parent = (string) ( $el['parent'] ?? '0' );
					$name   = (string) ( $el['name'] ?? '' );
					if ( $section_id === null && $name === 'section' && $parent === '0' ) {
						$section_id = $id;
					}
					// Role map: label → element_id.
					$label = (string) ( $el['settings']['label'] ?? $el['label'] ?? '' );
					if ( $label !== '' ) {
						if ( isset( $role_map[ $label ] ) && $role_map[ $label ] !== $id ) {
							$role_collisions[ $label ]   = $role_collisions[ $label ] ?? [ $role_map[ $label ] ];
							$role_collisions[ $label ][] = $id;
							continue;
						}
						$role_map[ $label ] = $id;
					}
				}
			}
		}

		$response = array_merge(
			$result,
			[
				'role_map'  => $role_map,
				'next_step' => 'Call populate_content with section_id + content_map keyed by role. Use content_contract.required_roles when present; populate_content rejects missing required roles unless allow_partial=true.',
			]
		);
		if ( $section_id !== null ) {
			$response['section_id'] = $section_id;
			if ( $page_id > 0 && $this->bricks !== null ) {
				$post_elements = $this->bricks->get_elements( $page_id );
				if ( is_array( $post_elements ) ) {
					$response['content_contract'] = ( new ContentContractService() )->analyze( $post_elements, $section_id );
				}
			}
		}
		if ( ! empty( $role_collisions ) ) {
			$response['role_collisions'] = array_map(
				static fn( array $ids ): array => array_values( array_unique( $ids ) ),
				$role_collisions
			);
			$response['next_step'] = 'Call populate_content with section_id + content_map keyed by role. This build has role_collisions; use #element-id keys for collided roles, or rebuild with unique roles. populate_content rejects ambiguous role keys unless you target exact IDs.';
		}
		if ( ! empty( $provisioned_classes ) ) {
			$response['classes_provisioned_from_pattern'] = $provisioned_classes;
		}
		if ( ! empty( $provisioned_variables ) ) {
			$response['variables_provisioned_from_pattern'] = $provisioned_variables;
		}
		if ( ! empty( $provisioning_errors ) ) {
			$response['provisioning_warnings'] = $provisioning_errors;
		}
		return $response;
	}

	/**
	 * Walk schema; for each element carrying a `role` field, set `label = role`
	 * so Bricks stores the role name as the element's label.
	 */
	private function tag_labels_from_roles( array $node ): array {
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$node[ $key ] = $this->tag_labels_from_roles( $value );
			}
		}
		if ( isset( $node['role'] ) && is_string( $node['role'] ) && $node['role'] !== '' ) {
			$node['role'] = \BricksMCP\MCP\Services\DesignPlanNormalizationService::normalize_role_key( $node['role'] );
			// Only set label when absent so explicit schema labels (e.g. "Hero") win.
			if ( empty( $node['label'] ) ) {
				$node['label'] = $node['role'];
			}
		}
		return $node;
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
			// Skip schema-routing / structural metadata at top level.
			if ( $path === '' && in_array( $key, self::SCAN_SKIP_TOP_KEYS, true ) ) {
				continue;
			}
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
	 * Walk the schema tree and normalize every class_intent into a BEM string.
	 *
	 * Structured objects ({block, modifier?, element?}) and loose strings are
	 * both converted via BEMClassNormalizer. Downstream DesignSchemaValidator +
	 * ClassIntentResolver treat class_intent as a scalar; passing an array
	 * triggers "Illegal offset type" in isset() lookups.
	 *
	 * @param array<string, mixed> $node Any schema subtree.
	 * @return array<string, mixed> Same shape with class_intent values flattened to BEM strings.
	 */
	private function normalize_class_intents( array $node ): array {
		$normalizer = new \BricksMCP\MCP\Services\BEMClassNormalizer();
		foreach ( $node as $key => $value ) {
			if ( $key === 'class_intent' && ( is_array( $value ) || is_string( $value ) ) ) {
				$normalized = $normalizer->normalize( $value );
				if ( $normalized !== '' ) {
					$node[ $key ] = $normalized;
				} else {
					unset( $node[ $key ] );
				}
				continue;
			}
			if ( is_array( $value ) ) {
				$node[ $key ] = $this->normalize_class_intents( $value );
			}
		}
		return $node;
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
			__( "Phase 1 of two-tier build. Takes structure-only schema (no content). Returns section_id + role_map + content_contract + class creation summary. Call populate_content next.", 'bricks-mcp' ),
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
