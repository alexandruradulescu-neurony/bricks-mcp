<?php
/**
 * Populate content handler (v3.28.0).
 *
 * Phase 2 of the two-tier build. Takes section_id + content_map (role → content),
 * injects content into matching elements. Supports #element-id direct targeting.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\MediaService;
use BricksMCP\MCP\ToolRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PopulateContentHandler {

	public function __construct(
		private BricksService $bricks,
		private MediaService $media
	) {}

	public function handle( array $args ): array|\WP_Error {
		$section_id  = sanitize_text_field( $args['section_id'] ?? '' );
		$content_map = $args['content_map'] ?? [];

		if ( $section_id === '' ) {
			return new \WP_Error( 'missing_section_id', 'section_id is required.' );
		}
		if ( ! is_array( $content_map ) || empty( $content_map ) ) {
			return new \WP_Error( 'missing_content_map', 'content_map is required (keyed by role or #element-id).' );
		}

		$page_id = $this->locate_page( $section_id );
		if ( $page_id === null ) {
			return new \WP_Error(
				'section_not_found',
				sprintf( 'No section with id "%s" on any Bricks page.', $section_id ),
				[ 'suggestion' => 'Re-run build_structure to get a fresh section_id.' ]
			);
		}

		$elements = $this->bricks->get_elements( $page_id );

		$injected     = 0;
		$unmatched    = [];
		$media_errors = [];

		// Split content_map into ID-prefixed + role-keyed.
		[ $by_id, $by_role ] = $this->partition_map( $content_map );

		// Inject by direct ID first.
		foreach ( $by_id as $id => $value ) {
			$el_idx = $this->find_by_id( $elements, $id );
			if ( $el_idx === null ) {
				$unmatched[] = '#' . $id;
				continue;
			}
			$elements[ $el_idx ] = $this->inject_into_element( $elements[ $el_idx ], $value, $media_errors );
			$injected++;
		}

		// Collect role map under the section subtree.
		$roles_in_section = $this->collect_roles( $elements, $section_id );

		foreach ( $by_role as $role => $value ) {
			$ids = $roles_in_section[ $role ] ?? [];
			if ( empty( $ids ) ) {
				$unmatched[] = $role;
				continue;
			}
			if ( count( $ids ) > 1 ) {
				return new \WP_Error(
					'role_collision',
					sprintf( 'Multiple elements share role "%s" (ids: %s). Use #id prefix for targeted injection.', $role, implode( ',', $ids ) ),
					[
						'affected_role' => $role,
						'element_ids'   => $ids,
						'resolution'    => 'Use element IDs from build_structure role_map (prefix with #), OR fix duplicate role in design_plan and rebuild.',
					]
				);
			}
			$el_idx = $this->find_by_id( $elements, $ids[0] );
			if ( $el_idx === null ) {
				$unmatched[] = $role;
				continue;
			}
			$elements[ $el_idx ] = $this->inject_into_element( $elements[ $el_idx ], $value, $media_errors );
			$injected++;
		}

		// Persist via BricksService (wraps update_post_meta + validation).
		$saved = $this->bricks->save_elements( $page_id, $elements );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$response = [
			'populated'       => true,
			'section_id'      => $section_id,
			'page_id'         => $page_id,
			'injected_count'  => $injected,
			'unmatched_roles' => array_values( $unmatched ),
		];
		if ( ! empty( $media_errors ) ) {
			$response['partial_success'] = true;
			$response['media_errors']    = $media_errors;
		}
		return $response;
	}

	/** Split content_map into [#id => value] and [role => value]. */
	private function partition_map( array $map ): array {
		$by_id   = [];
		$by_role = [];
		foreach ( $map as $key => $value ) {
			if ( is_string( $key ) && str_starts_with( $key, '#' ) ) {
				$by_id[ substr( $key, 1 ) ] = $value;
			} else {
				$by_role[ (string) $key ] = $value;
			}
		}
		return [ $by_id, $by_role ];
	}

	private function locate_page( string $section_id ): ?int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$posts = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private') AND post_type IN ('page','post','bricks_template')" );
		foreach ( $posts as $post_id ) {
			$elements = get_post_meta( (int) $post_id, '_bricks_page_content_2', true );
			if ( is_array( $elements ) && $this->has_element_id( $elements, $section_id ) ) {
				return (int) $post_id;
			}
		}
		return null;
	}

	private function has_element_id( array $elements, string $id ): bool {
		foreach ( $elements as $el ) {
			if ( ( $el['id'] ?? '' ) === $id ) {
				return true;
			}
		}
		return false;
	}

	private function find_by_id( array $elements, string $id ): ?int {
		foreach ( $elements as $idx => $el ) {
			if ( ( $el['id'] ?? '' ) === $id ) {
				return (int) $idx;
			}
		}
		return null;
	}

	/** Return map of role => [element_id, ...] under the given section subtree. */
	private function collect_roles( array $elements, string $section_id ): array {
		$children_of = [];
		foreach ( $elements as $el ) {
			$parent = (string) ( $el['parent'] ?? '0' );
			$id     = (string) ( $el['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			$children_of[ $parent ][] = $id;
		}
		$by_id = [];
		foreach ( $elements as $el ) {
			$by_id[ (string) ( $el['id'] ?? '' ) ] = $el;
		}

		$roles = [];
		$queue = [ $section_id ];
		while ( ! empty( $queue ) ) {
			$id = array_shift( $queue );
			$el = $by_id[ $id ] ?? null;
			if ( $el === null ) {
				continue;
			}
			$role = $el['settings']['label'] ?? $el['label'] ?? null;
			if ( is_string( $role ) && $role !== '' ) {
				$roles[ $role ][] = $id;
			}
			foreach ( $children_of[ $id ] ?? [] as $child_id ) {
				$queue[] = $child_id;
			}
		}
		return $roles;
	}

	/** Inject content into a single element based on its type. */
	private function inject_into_element( array $el, mixed $value, array &$media_errors ): array {
		$type = $el['name'] ?? '';

		if ( $type === 'button' ) {
			if ( is_array( $value ) ) {
				if ( isset( $value['label'] ) ) {
					$el['settings']['text'] = (string) $value['label'];
				}
				if ( isset( $value['link'] ) ) {
					$el['settings']['link']['url'] = (string) $value['link'];
				}
				if ( isset( $value['icon'] ) ) {
					$el['settings']['icon']['icon'] = (string) $value['icon'];
				}
			} else {
				$el['settings']['text'] = (string) $value;
			}
			return $el;
		}

		if ( in_array( $type, [ 'heading', 'text-basic', 'text' ], true ) ) {
			$el['settings']['text'] = is_array( $value ) ? (string) ( $value['content'] ?? '' ) : (string) $value;
			return $el;
		}

		if ( $type === 'image' ) {
			if ( is_array( $value ) && isset( $value['attachment_id'] ) ) {
				$el['settings']['image']['id'] = (int) $value['attachment_id'];
				return $el;
			}
			$url = is_string( $value )
				? $value
				: ( is_array( $value ) && isset( $value['url'] ) ? (string) $value['url'] : null );

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
						} else {
							$el['settings']['image']['id'] = (int) $sideloaded['attachment_id'];
						}
					} else {
						$media_errors[] = [ 'error' => 'unsplash_no_url', 'query' => $query ];
					}
				} else {
					$media_errors[] = [ 'error' => 'unsplash_no_results', 'query' => $query ];
				}
			} elseif ( is_string( $url ) ) {
				$el['settings']['image']['url'] = $url;
			}
			return $el;
		}

		if ( $type === 'icon' ) {
			$el['settings']['icon']['icon'] = is_array( $value ) ? (string) ( $value['icon'] ?? '' ) : (string) $value;
			return $el;
		}

		// Fallback: generic content field.
		if ( is_string( $value ) ) {
			$el['settings']['content'] = $value;
		}
		return $el;
	}

	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'populate_content',
			__( "Phase 2 of two-tier build. Injects content_map (role → content values) into a built section. Use # prefix on keys to target specific element IDs.", 'bricks-mcp' ),
			[
				'type'       => 'object',
				'properties' => [
					'section_id'  => [
						'type'        => 'string',
						'description' => __( 'Section ID from build_structure response.', 'bricks-mcp' ),
					],
					'content_map' => [
						'type'        => 'object',
						'description' => __( 'Role → content value map. Prefix keys with # to target element IDs directly.', 'bricks-mcp' ),
					],
				],
				'required'   => [ 'section_id', 'content_map' ],
			],
			[ $this, 'handle' ]
		);
	}
}
