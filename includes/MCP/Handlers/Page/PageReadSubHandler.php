<?php
/**
 * Page read sub-handler: list, search, get actions.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers\Page;

use BricksMCP\MCP\Services\BricksService;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles page read actions (list, search, get).
 */
final class PageReadSubHandler {

	/**
	 * Bricks service instance.
	 *
	 * @var BricksService
	 */
	private BricksService $bricks_service;

	/**
	 * Bricks check callback.
	 *
	 * @var callable
	 */
	private $require_bricks;

	/**
	 * Constructor.
	 *
	 * @param BricksService $bricks_service Bricks service instance.
	 * @param callable      $require_bricks Callback that returns \WP_Error|null.
	 */
	public function __construct( BricksService $bricks_service, callable $require_bricks ) {
		$this->bricks_service = $bricks_service;
		$this->require_bricks = $require_bricks;
	}

	/**
	 * List pages/posts with optional Bricks filter.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int, array<string, mixed>>|\WP_Error Posts list or error.
	 */
	public function list_pages( array $args ): array|\WP_Error {
		$post_type   = $args['post_type'] ?? 'page';
		$status      = $args['status'] ?? 'any';
		$per_page    = min( (int) ( $args['per_page'] ?? 20 ), 100 );
		$page        = (int) ( $args['page'] ?? 1 );
		$bricks_only = isset( $args['bricks_only'] ) ? (bool) $args['bricks_only'] : true;

		$query_args = array(
			'post_type'      => sanitize_text_field( $post_type ),
			'post_status'    => sanitize_text_field( $status ),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'no_found_rows'  => false,
		);

		if ( $bricks_only ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => BricksService::EDITOR_MODE_KEY,
					'value' => 'bricks',
				),
			);
		}

		$query      = new \WP_Query( $query_args );
		$result     = array();

		// Prime user cache to avoid N+1 queries for get_the_author_meta().
		$author_ids = array_unique( array_map( fn( $p ) => (int) $p->post_author, $query->posts ) );
		cache_users( $author_ids );

		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$result[] = $this->format_post_for_list( $post );
			}
		}

		return $result;
	}

	/**
	 * Search Bricks pages by title or content.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int, array<string, mixed>>|\WP_Error Search results or error.
	 */
	public function search( array $args ): array|\WP_Error {
		if ( empty( $args['query'] ) ) {
			return new \WP_Error( 'missing_query', __( 'query parameter is required.', 'bricks-mcp' ) );
		}

		$search_query = sanitize_text_field( $args['query'] );
		$post_type    = $args['post_type'] ?? 'page';
		$per_page     = min( (int) ( $args['per_page'] ?? 20 ), 100 );

		$query_args = array(
			'post_type'      => sanitize_key( $post_type ),
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			's'              => $search_query,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => BricksService::EDITOR_MODE_KEY,
					'value' => 'bricks',
				),
			),
		);

		$query      = new \WP_Query( $query_args );
		$result     = array();

		// Prime user cache to avoid N+1 queries for get_the_author_meta().
		$author_ids = array_unique( array_map( fn( $p ) => (int) $p->post_author, $query->posts ) );
		cache_users( $author_ids );

		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$result[] = $this->format_post_for_list( $post );
			}
		}

		return $result;
	}

	/**
	 * Get Bricks content for a post.
	 *
	 * Returns element JSON in native flat array format with page metadata.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Content data or error.
	 */
	public function get( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		$post_id = (int) $args['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Use page:list to find valid post IDs.', 'bricks-mcp' ), $post_id )
			);
		}

		if ( ! $this->bricks_service->is_bricks_page( $post_id ) ) {
			return new \WP_Error(
				'not_bricks_page',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d is not using the Bricks editor. Use the enable_bricks tool to enable Bricks on this post first.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		$view     = $args['view'] ?? 'detail';
		$metadata = $this->bricks_service->get_page_metadata( $post_id );

		if ( 'summary' === $view ) {
			return array(
				'metadata' => $metadata,
				'summary'  => $this->bricks_service->get_page_summary( $post_id ),
			);
		}

		if ( 'context' === $view ) {
			return array(
				'metadata' => $metadata,
				'context'  => $this->bricks_service->get_page_context( $post_id ),
			);
		}

		if ( 'describe' === $view ) {
			return $this->bricks_service->describe_page( $post_id );
		}

		$elements = $this->bricks_service->get_elements( $post_id );

		// Subtree filter: return only root element and all descendants.
		if ( ! empty( $args['root_element_id'] ) ) {
			$root_id = sanitize_text_field( $args['root_element_id'] );
			$subtree = $this->collect_subtree( $elements, $root_id );
			if ( empty( $subtree ) ) {
				return new \WP_Error(
					'element_not_found',
					sprintf(
						/* translators: %1$s: Element ID, %2$d: Post ID */
						__( 'Element "%1$s" not found on post %2$d. Use page:get with view=summary to find valid element IDs.', 'bricks-mcp' ),
						$root_id,
						$post_id
					)
				);
			}
			$elements = $subtree;
		}

		$total    = count( $elements );

		// Filter by element IDs if specified.
		if ( ! empty( $args['element_ids'] ) && is_array( $args['element_ids'] ) ) {
			$ids      = array_map( 'strval', $args['element_ids'] );
			$elements = array_values(
				array_filter( $elements, static fn( array $el ) => in_array( $el['id'] ?? '', $ids, true ) )
			);
		}

		// Pagination: offset + limit.
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : null;

		if ( $offset > 0 || null !== $limit ) {
			$elements = array_values( array_slice( $elements, $offset, $limit ) );
		}

		// Compact mode: strip empty/null/default values (default on).
		$compact = $args['compact'] ?? true;
		if ( $compact ) {
			$elements = array_map( [ $this, 'compact_element' ], $elements );
		}

		$result = array(
			'metadata' => $metadata,
			'elements' => $elements,
			'total'    => $total,
		);

		// Add pagination hints when paginated.
		if ( $offset > 0 || null !== $limit ) {
			$result['offset'] = $offset;
			$result['limit']  = $limit;
			$returned         = count( $elements );
			$result['has_more'] = ( $offset + $returned ) < $total;
		}

		return $result;
	}

	/**
	 * Format a post for list/search responses.
	 *
	 * @param \WP_Post $post The post object.
	 * @return array<string, mixed> Formatted post data.
	 */
	private function format_post_for_list( \WP_Post $post ): array {
		$has_bricks = $this->bricks_service->is_bricks_page( $post->ID );

		// Read raw meta directly to avoid full BricksService deserialization per post (N+1).
		$raw_elements  = get_post_meta( $post->ID, '_bricks_page_content_2', true );
		$element_count = is_array( $raw_elements ) ? count( $raw_elements ) : 0;

		return array(
			'id'                 => $post->ID,
			'title'              => $post->post_title,
			'status'             => $post->post_status,
			'type'               => $post->post_type,
			'slug'               => $post->post_name,
			'date'               => $post->post_date,
			'modified'           => $post->post_modified,
			'author_name'        => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'permalink'          => get_permalink( $post->ID ),
			'has_bricks_content' => $has_bricks,
			'element_count'      => $element_count,
		);
	}

	/**
	 * Collect an element and all its recursive descendants from a flat elements array.
	 *
	 * @param array  $elements All page elements (flat array).
	 * @param string $root_id  The root element ID.
	 * @return array Flat array of the root element and all descendants. Empty if root not found.
	 */
	private function collect_subtree( array $elements, string $root_id ): array {
		// Index by ID.
		$by_id = [];
		foreach ( $elements as $el ) {
			$by_id[ $el['id'] ?? '' ] = $el;
		}

		if ( ! isset( $by_id[ $root_id ] ) ) {
			return [];
		}

		// BFS to collect all descendants.
		$result_ids = [ $root_id ];
		$queue      = $by_id[ $root_id ]['children'] ?? [];

		while ( ! empty( $queue ) ) {
			$cid = array_shift( $queue );
			$result_ids[] = $cid;
			if ( isset( $by_id[ $cid ] ) && ! empty( $by_id[ $cid ]['children'] ) ) {
				foreach ( $by_id[ $cid ]['children'] as $grandchild ) {
					$queue[] = $grandchild;
				}
			}
		}

		$id_set = array_flip( $result_ids );
		return array_values(
			array_filter( $elements, static fn( array $el ) => isset( $id_set[ $el['id'] ?? '' ] ) )
		);
	}

	/**
	 * Compact an element by stripping empty/null/default values.
	 *
	 * @param array<string, mixed> $element Element data.
	 * @return array<string, mixed> Compacted element.
	 */
	private function compact_element( array $element ): array {
		// Remove themeStyles if empty.
		if ( isset( $element['themeStyles'] ) && empty( $element['themeStyles'] ) ) {
			unset( $element['themeStyles'] );
		}

		// Compact settings recursively.
		if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
			$element['settings'] = $this->compact_settings( $element['settings'] );
		}

		return $element;
	}

	/**
	 * Recursively strip empty/null values from settings.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @return array<string, mixed> Compacted settings.
	 */
	private function compact_settings( array $settings ): array {
		$result = [];
		foreach ( $settings as $key => $value ) {
			if ( null === $value ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = $this->compact_settings( $value );
				if ( empty( $value ) ) {
					continue;
				}
			}
			if ( '' === $value ) {
				continue;
			}
			$result[ $key ] = $value;
		}
		return $result;
	}
}
