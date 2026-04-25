<?php
/**
 * Build structure handler (v4.0).
 *
 * Unified build tool: accepts a complete schema with content inline.
 * Validates, resolves classes, generates settings, writes elements,
 * runs media sideload, and enforces content contract — all in one call.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\ToolRegistry;
use BricksMCP\MCP\Services\ContentContractService;
use BricksMCP\MCP\Services\MediaService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BuildStructureHandler {

	public function __construct(
		private BuildHandler $delegate,
		private ?\BricksMCP\MCP\Services\BricksService $bricks = null,
		private ?\BricksMCP\MCP\Services\ProposalService $proposal_service = null,
		private ?\BricksMCP\MCP\Services\GlobalClassService $classes = null,
		private ?\BricksMCP\MCP\Services\GlobalVariableService $variables = null,
		private ?MediaService $media = null
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

		// v5.1: pattern-flow auto-provisioning of classes/variables retired
		// (provisioning_manifest was emitted by deleted PatternToSchemaBridge).
		// Class creation now happens through BuildHandler's resolver chain.
		$proposal_id = $args['proposal_id'] ?? '';

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
		$media_errors    = [];
		$final_elements  = null; // Reused for content_contract analysis (avoids redundant DB read).
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

				// Media sideload pass: resolve unsplash:query → attachment ID in image elements.
				if ( $this->media !== null ) {
					$post_elements = $this->sideload_media( $post_elements, $media_errors );
					$save_result   = $this->bricks->save_elements( $page_id, $post_elements );
					if ( is_wp_error( $save_result ) ) {
						$media_errors[] = [ 'error' => $save_result->get_error_code(), 'query' => 'post_build_save' ];
					}
				}

				// Keep reference for content_contract analysis (avoids redundant DB read).
				$final_elements = $post_elements;
			}
		}

		$response = array_merge(
			$result,
			[
				'role_map'  => $role_map,
				'next_step' => 'Build complete. Content is inline in the schema. Use populate_content(section_id, content_map) only for post-build content updates.',
			]
		);
		if ( $section_id !== null ) {
			$response['section_id'] = $section_id;
			// Reuse already-loaded elements instead of re-reading from DB.
			if ( null !== $final_elements && is_array( $final_elements ) ) {
				$response['content_contract'] = ( new ContentContractService() )->analyze( $final_elements, $section_id );
			}
		}
		if ( ! empty( $role_collisions ) ) {
			$response['role_collisions'] = array_map(
				static fn( array $ids ): array => array_values( array_unique( $ids ) ),
				$role_collisions
			);
			$response['next_step'] = 'Build has role_collisions; use #element-id keys in populate_content for collided roles, or rebuild with unique roles.';
		}
		if ( ! empty( $media_errors ) ) {
			$response['media_errors'] = $media_errors;
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
	 * Walk elements and resolve unsplash:query → attachment ID in image elements.
	 *
	 * @param array<int, array<string, mixed>> $elements    Element tree.
	 * @param array<int, array<string, string>> &$media_errors Error accumulator.
	 * @return array<int, array<string, mixed>> Modified element tree.
	 */
	private function sideload_media( array $elements, array &$media_errors ): array {
		foreach ( $elements as $idx => $el ) {
			if ( ( $el['name'] ?? '' ) === 'image' ) {
				$settings = $el['settings'] ?? [];
				$image    = $settings['image'] ?? null;
				$url      = is_array( $image ) ? ( $image['url'] ?? '' ) : '';

				if ( is_string( $url ) && str_starts_with( $url, 'unsplash:' ) ) {
					$query   = substr( $url, 9 );
					$results = $this->media->search_photos( $query, 1 );
					if ( is_wp_error( $results ) ) {
						$media_errors[] = [ 'error' => $results->get_error_code(), 'query' => $query ];
					} elseif ( ! empty( $results['results'] ) ) {
						$photo       = $results['results'][0];
						$photo_url   = $photo['urls']['regular'] ?? $photo['urls']['full'] ?? '';
						$alt_text    = $photo['description'] ?? '';
						$unsplash_id = $photo['id'] ?? null;
						$dl_location = $photo['links']['download_location'] ?? null;
						if ( $photo_url !== '' ) {
							$sideloaded = $this->media->sideload_from_url( $photo_url, $alt_text, '', $unsplash_id, $dl_location );
							if ( is_wp_error( $sideloaded ) ) {
								$media_errors[] = [ 'error' => $sideloaded->get_error_code(), 'query' => $query ];
								// Clear invalid unsplash URL so Bricks doesn't try to render it.
								$elements[ $idx ][ 'settings' ][ 'image' ] = [];
							} else {
								$elements[ $idx ]['settings']['image']['id']    = (int) $sideloaded['attachment_id'];
								$elements[ $idx ]['settings']['image']['url']   = '';
								$elements[ $idx ]['settings']['image']['size'] = 'full';
							}
						} else {
							$media_errors[] = [ 'error' => 'unsplash_no_url', 'query' => $query ];
						}
					} else {
						$media_errors[] = [ 'error' => 'unsplash_no_results', 'query' => $query ];
					}
				}
			}

			// Recurse into children.
			if ( ! empty( $el['children'] ) && is_array( $el['children'] ) ) {
				$elements[ $idx ]['children'] = $this->sideload_media( $el['children'], $media_errors );
			}
		}
		return $elements;
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
	 * Register the build_structure tool with the MCP tool registry.
	 *
	 * @param ToolRegistry $registry Tool registry instance.
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'build_structure',
			__( "Build a section from a complete schema (structure + content inline). Validates, resolves classes, generates settings, writes elements, runs media sideload for unsplash: URLs, and returns section_id + role_map + content_contract. Content (text, links, images) goes directly in element settings — no separate populate_content step needed for initial builds. Use populate_content only for post-build content updates.", 'bricks-mcp' ),
			[
				'type'       => 'object',
				'properties' => [
					'proposal_id' => [
						'type'        => 'string',
						'description' => __( 'Proposal ID from propose_design phase 2.', 'bricks-mcp' ),
					],
					'schema'      => [
						'type'        => 'object',
						'description' => __( 'Complete schema with structure + content inline. Each element can have content, link, icon, src fields in its settings.', 'bricks-mcp' ),
					],
				],
				'required'   => [ 'proposal_id', 'schema' ],
			],
			[ $this, 'handle' ]
		);
	}
}
