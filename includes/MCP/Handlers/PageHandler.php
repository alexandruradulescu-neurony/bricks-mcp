<?php
/**
 * Page handler for MCP Router.
 *
 * Manages page CRUD, Bricks content, settings, SEO, and snapshots.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\ValidationService;
use BricksMCP\MCP\ToolRegistry;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles page tool actions.
 */
final class PageHandler {

	/**
	 * Bricks service instance.
	 *
	 * @var BricksService
	 */
	private BricksService $bricks_service;

	/**
	 * Validation service instance.
	 *
	 * @var ValidationService
	 */
	private ValidationService $validation_service;

	/**
	 * Bricks check callback.
	 *
	 * @var callable
	 */
	private $require_bricks;

	/**
	 * Constructor.
	 *
	 * @param BricksService     $bricks_service     Bricks service instance.
	 * @param ValidationService $validation_service  Validation service instance.
	 * @param callable          $require_bricks      Callback that returns \WP_Error|null.
	 */
	public function __construct( BricksService $bricks_service, ValidationService $validation_service, callable $require_bricks ) {
		$this->bricks_service     = $bricks_service;
		$this->validation_service = $validation_service;
		$this->require_bricks     = $require_bricks;
	}

	/**
	 * Handle a page tool action.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: action).
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action = sanitize_text_field( $args['action'] ?? '' );

		// Map 'search' param alias: the schema uses 'search' but tool_search_pages reads 'query'.
		if ( 'search' === $action && isset( $args['search'] ) && ! isset( $args['query'] ) ) {
			$args['query'] = $args['search'];
		}

		// Map 'posts_per_page' alias to 'per_page' for list/search.
		if ( isset( $args['posts_per_page'] ) && ! isset( $args['per_page'] ) ) {
			$args['per_page'] = $args['posts_per_page'];
		}

		// Map 'paged' alias to 'page' for list.
		if ( isset( $args['paged'] ) && ! isset( $args['page'] ) ) {
			$args['page'] = $args['paged'];
		}

		return match ( $action ) {
			'list'            => $this->tool_list_pages( $args ),
			'search'          => $this->tool_search_pages( $args ),
			'get'             => $this->tool_get_bricks_content( $args ),
			'create'          => $this->tool_create_bricks_page( $args ),
			'update_content'  => $this->tool_update_bricks_content( $args ),
			'append_content'   => $this->tool_append_bricks_content( $args ),
			'import_clipboard' => $this->tool_import_clipboard( $args ),
			'update_meta'     => $this->tool_update_page( $args ),
			'delete'          => $this->tool_delete_page( $args ),
			'duplicate'       => $this->tool_duplicate_page( $args ),
			'get_settings'    => $this->tool_get_page_settings( $args ),
			'update_settings' => $this->tool_update_page_settings( $args ),
			'get_seo'         => $this->tool_get_page_seo( $args ),
			'update_seo'      => $this->tool_update_page_seo( $args ),
			'snapshot'        => $this->tool_snapshot_page( $args ),
			'restore'         => $this->tool_restore_snapshot( $args ),
			'list_snapshots'  => $this->tool_list_snapshots( $args ),
			default           => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, search, get, create, update_content, append_content, import_clipboard, update_meta, delete, duplicate, get_settings, update_settings, get_seo, update_seo, snapshot, restore, list_snapshots', 'bricks-mcp' ),
					$action
				)
			),
		};
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

	/**
	 * Tool: Get Bricks content for a post.
	 *
	 * Returns element JSON in native flat array format with page metadata.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Content data or error.
	 */
	private function tool_get_bricks_content( array $args ): array|\WP_Error {
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
	 * Tool: List pages/posts with optional Bricks filter.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int, array<string, mixed>>|\WP_Error Posts list or error.
	 */
	private function tool_list_pages( array $args ): array|\WP_Error {
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
	 * Tool: Search Bricks pages by title or content.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int, array<string, mixed>>|\WP_Error Search results or error.
	 */
	private function tool_search_pages( array $args ): array|\WP_Error {
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
	 * Tool: Create a new Bricks page/post.
	 *
	 * Creates a post with Bricks editor enabled and optionally saves elements.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created post data or error.
	 */
	private function tool_create_bricks_page( array $args ): array|\WP_Error {
		if ( empty( $args['title'] ) ) {
			return new \WP_Error(
				'missing_title',
				__( 'title is required. Provide a non-empty page title.', 'bricks-mcp' )
			);
		}

		$post_id = $this->bricks_service->create_page( $args );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post     = get_post( $post_id );
		$elements = $this->bricks_service->get_elements( $post_id );

		return array(
			'post_id'       => $post_id,
			'title'         => $post ? $post->post_title : $args['title'],
			'status'        => $post ? $post->post_status : ( $args['status'] ?? 'draft' ),
			'permalink'     => get_permalink( $post_id ),
			'element_count' => count( $elements ),
			'edit_url'      => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		);
	}

	/**
	 * Tool: Replace full Bricks element content for a page.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated content info or error.
	 */
	private function tool_update_bricks_content( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( ! isset( $args['elements'] ) || ! is_array( $args['elements'] ) ) {
			return new \WP_Error( 'missing_elements', __( 'elements array is required. Provide an array of Bricks elements.', 'bricks-mcp' ) );
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

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( $post_id );
		if ( $protected ) {
			return $protected;
		}

		// Normalize via ElementNormalizer (handles both native and simplified format).
		$elements = $this->bricks_service->normalize_elements( $args['elements'] );

		// Element count safety check.
		$meta_key         = defined( 'BRICKS_DB_PAGE_CONTENT' ) ? BRICKS_DB_PAGE_CONTENT : '_bricks_page_content_2';
		$current_elements = get_post_meta( $post_id, $meta_key, true );
		$old_count        = is_array( $current_elements ) ? count( $current_elements ) : 0;
		$new_count        = count( $elements );

		if ( $old_count > 0 && $new_count < (int) ( $old_count * 0.5 ) && empty( $args['confirm'] ) ) {
			$reduction_pct = (int) round( ( 1 - ( $new_count / $old_count ) ) * 100 );
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf(
					__( 'This update would reduce elements from %d to %d (%d%% reduction). Set confirm: true to proceed, or use page:append_content to add without replacing.', 'bricks-mcp' ),
					$old_count,
					$new_count,
					$reduction_pct
				)
			);
		}

		$saved = $this->bricks_service->save_elements( $post_id, $elements );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$metadata = $this->bricks_service->get_page_metadata( $post_id );

		return array(
			'post_id'       => $post_id,
			'element_count' => count( $elements ),
			'metadata'      => $metadata,
		);
	}

	/**
	 * Tool: Append elements to existing Bricks content.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Result with appended element info or error.
	 */
	private function tool_append_bricks_content( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}

		if ( empty( $args['elements'] ) || ! is_array( $args['elements'] ) ) {
			return new \WP_Error( 'missing_elements', __( 'elements array is required for append_content.', 'bricks-mcp' ) );
		}

		$post_id = (int) $args['post_id'];

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( $post_id );
		if ( $protected ) {
			return $protected;
		}

		$parent_id = isset( $args['parent_id'] ) ? sanitize_text_field( (string) $args['parent_id'] ) : '0';
		$position  = isset( $args['position'] ) ? (int) $args['position'] : null;

		$result = $this->bricks_service->append_elements( $post_id, $args['elements'], $parent_id, $position );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['metadata'] = $this->bricks_service->get_page_metadata( $post_id );
		return $result;
	}

	/**
	 * Tool: Import Bricks clipboard JSON (bricksCopiedElements format).
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Import result or error.
	 */
	private function tool_import_clipboard( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}

		if ( empty( $args['clipboard_data'] ) || ! is_array( $args['clipboard_data'] ) ) {
			return new \WP_Error( 'missing_clipboard_data', __( 'clipboard_data object is required for import_clipboard. Expected Bricks copied elements JSON with content array.', 'bricks-mcp' ) );
		}

		$post_id        = (int) $args['post_id'];
		$clipboard_data = $args['clipboard_data'];
		$parent_id      = isset( $args['parent_id'] ) ? sanitize_text_field( (string) $args['parent_id'] ) : '0';
		$position       = isset( $args['position'] ) ? (int) $args['position'] : null;

		$result = $this->bricks_service->import_clipboard( $post_id, $clipboard_data, $parent_id, $position );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['metadata'] = $this->bricks_service->get_page_metadata( $post_id );
		return $result;
	}

	/**
	 * Tool: Update page/post metadata (title, status, slug).
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated metadata or error.
	 */
	private function tool_update_page( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		$post_id = (int) $args['post_id'];

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( $post_id );
		if ( $protected ) {
			return $protected;
		}

		$result = $this->bricks_service->update_page_meta( $post_id, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->bricks_service->get_page_metadata( $post_id );
	}

	/**
	 * Tool: Move a page/post to trash.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Confirmation or error.
	 */
	private function tool_delete_page( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		$post_id = (int) $args['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf( __( 'Post %d not found. Use page:list to find valid post IDs.', 'bricks-mcp' ), $post_id )
			);
		}

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( $post_id );
		if ( $protected ) {
			return $protected;
		}

		// Confirm check.
		if ( empty( $args['confirm'] ) ) {
			$raw_elements  = get_post_meta( $post_id, '_bricks_page_content_2', true );
			$element_count = is_array( $raw_elements ) ? count( $raw_elements ) : 0;
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf(
					__( 'You are about to delete page "%s" (ID: %d) with %d elements. Set confirm: true to proceed.', 'bricks-mcp' ),
					$post->post_title,
					$post_id,
					$element_count
				)
			);
		}

		// Force delete: permanently delete instead of trashing.
		if ( ! empty( $args['force'] ) ) {
			$deleted = wp_delete_post( $post_id, true );
			if ( ! $deleted ) {
				return new \WP_Error(
					'delete_failed',
					sprintf( __( 'Failed to permanently delete post %d.', 'bricks-mcp' ), $post_id )
				);
			}
			return array(
				'post_id' => $post_id,
				'status'  => 'deleted',
				'message' => __( 'Post permanently deleted. This cannot be undone.', 'bricks-mcp' ),
			);
		}

		$result = $this->bricks_service->delete_page( $post_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'post_id' => $post_id,
			'status'  => 'trash',
			'message' => __( 'Post moved to trash. It can be recovered from the WordPress trash.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Duplicate a page/post including all Bricks content.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error New post data or error.
	 */
	private function tool_duplicate_page( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		$post_id     = (int) $args['post_id'];
		$new_post_id = $this->bricks_service->duplicate_page( $post_id );

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		$post     = get_post( $new_post_id );
		$elements = $this->bricks_service->get_elements( $new_post_id );

		return array(
			'post_id'       => $new_post_id,
			'title'         => $post ? $post->post_title : '',
			'status'        => $post ? $post->post_status : 'draft',
			'permalink'     => get_permalink( $new_post_id ),
			'element_count' => count( $elements ),
		);
	}

	/**
	 * Tool: Get page-level Bricks settings.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Page settings or error.
	 */
	private function tool_get_page_settings( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->get_page_settings( (int) $args['post_id'] );
	}

	/**
	 * Tool: Update page-level Bricks settings.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	private function tool_update_page_settings( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' )
			);
		}

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( (int) $args['post_id'] );
		if ( $protected ) {
			return $protected;
		}

		if ( ! isset( $args['settings'] ) || ! is_array( $args['settings'] ) ) {
			return new \WP_Error(
				'missing_settings',
				__( 'settings object is required. Provide key-value pairs of page settings to update.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['settings'] ) ) {
			return new \WP_Error(
				'empty_settings',
				__( 'settings object must contain at least one key-value pair.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_page_settings( (int) $args['post_id'], $args['settings'] );
	}

	/**
	 * Tool: Get SEO data from active SEO plugin.
	 *
	 * Returns normalized SEO fields from whichever SEO plugin is active
	 * (Yoast, Rank Math, SEOPress, Slim SEO, or Bricks native) with inline audit.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error SEO data with audit or error.
	 */
	private function tool_get_page_seo( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Use page tool (action: list) to find valid post IDs.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->get_seo_data( (int) $args['post_id'] );
	}

	/**
	 * Tool: Update SEO fields via active SEO plugin.
	 *
	 * Writes normalized SEO field names to the correct plugin meta keys.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	private function tool_update_page_seo( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Use page tool (action: list) to find valid post IDs.', 'bricks-mcp' )
			);
		}

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( (int) $args['post_id'] );
		if ( $protected ) {
			return $protected;
		}

		// Extract all SEO fields from args.
		$seo_field_names = array(
			'title', 'description', 'robots_noindex', 'robots_nofollow', 'canonical',
			'og_title', 'og_description', 'og_image',
			'twitter_title', 'twitter_description', 'twitter_image',
			'focus_keyword',
		);

		$seo_fields = array();
		foreach ( $seo_field_names as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$seo_fields[ $field ] = $args[ $field ];
			}
		}

		if ( empty( $seo_fields ) ) {
			return new \WP_Error(
				'missing_seo_fields',
				__( 'At least one SEO field must be provided. Accepted: title, description, robots_noindex, robots_nofollow, canonical, og_title, og_description, og_image, twitter_title, twitter_description, twitter_image, focus_keyword.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_seo_data( (int) $args['post_id'], $seo_fields );
	}

	/**
	 * Tool: Create page snapshot.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Snapshot data or error.
	 */
	private function tool_snapshot_page( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}
		$label = isset( $args['label'] ) ? sanitize_text_field( $args['label'] ) : '';
		return $this->bricks_service->snapshot_page( (int) $args['post_id'], $label );
	}

	/**
	 * Tool: Restore page from snapshot.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Restore result or error.
	 */
	private function tool_restore_snapshot( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}
		if ( empty( $args['snapshot_id'] ) ) {
			return new \WP_Error( 'missing_snapshot_id', __( 'snapshot_id is required. Use page:list_snapshots to find available snapshot IDs.', 'bricks-mcp' ) );
		}
		return $this->bricks_service->restore_snapshot( (int) $args['post_id'], sanitize_text_field( $args['snapshot_id'] ) );
	}

	/**
	 * Tool: List page snapshots.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Snapshots list or error.
	 */
	private function tool_list_snapshots( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}
		return $this->bricks_service->list_snapshots( (int) $args['post_id'] );
	}

	/**
	 * Register the page tool with the given registry.
	 *
	 * @param ToolRegistry $registry Tool registry instance.
	 * @return void
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'page',
			__( "Manage pages and Bricks content.\n\nActions: list, search, get (views: detail/summary/context/describe), create, update_content, append_content (add without replacing), import_clipboard, update_meta, delete, duplicate, get_settings, update_settings, get_seo, update_seo, snapshot, restore, list_snapshots.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'              => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'search', 'get', 'create', 'update_content', 'append_content', 'import_clipboard', 'update_meta', 'delete', 'duplicate', 'get_settings', 'update_settings', 'get_seo', 'update_seo', 'snapshot', 'restore', 'list_snapshots' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'post_id'             => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID (get, update_content, update_meta, delete, duplicate, get_settings, update_settings, get_seo, update_seo: required)', 'bricks-mcp' ),
					),
					'post_type'           => array(
						'type'        => 'string',
						'description' => __( 'Post type (list, search, create: optional; default page)', 'bricks-mcp' ),
					),
					'status'              => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private', 'trash', 'any' ),
						'description' => __( 'Post status (list: filter; create/update_meta: new status)', 'bricks-mcp' ),
					),
					'posts_per_page'      => array(
						'type'        => 'integer',
						'description' => __( 'Results per page (list, search: max 100)', 'bricks-mcp' ),
					),
					'paged'               => array(
						'type'        => 'integer',
						'description' => __( 'Page number for pagination (list, search)', 'bricks-mcp' ),
					),
					'bricks_only'         => array(
						'type'        => 'boolean',
						'description' => __( 'Filter to only Bricks-enabled pages (list: default true)', 'bricks-mcp' ),
					),
					'search'              => array(
						'type'        => 'string',
						'description' => __( 'Search query string (search: required)', 'bricks-mcp' ),
					),
					'view'                => array(
						'type'        => 'string',
						'enum'        => array( 'detail', 'summary', 'context', 'describe' ),
						'description' => __( 'Detail level (get: detail=full settings, summary=tree outline, context=tree with text content and classes but no style settings, describe=human-readable section descriptions)', 'bricks-mcp' ),
					),
					'offset'              => array(
						'type'        => 'integer',
						'description' => __( 'Skip first N elements in detail view (get: optional, default 0). Use with limit for pagination.', 'bricks-mcp' ),
					),
					'limit'               => array(
						'type'        => 'integer',
						'description' => __( 'Max elements to return in detail view (get: optional, default all). Use with offset for pagination.', 'bricks-mcp' ),
					),
					'element_ids'         => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Filter to specific element IDs in detail view (get: optional). Returns only these elements and their settings.', 'bricks-mcp' ),
					),
					'root_element_id'     => array(
						'type'        => 'string',
						'description' => __( 'Return only this element and all its descendants (get: optional). Efficient for reading a single section without fetching the entire page.', 'bricks-mcp' ),
					),
					'compact'             => array(
						'type'        => 'boolean',
						'description' => __( 'Strip empty arrays, null values, and default settings to reduce response size (get: optional, default true for detail view).', 'bricks-mcp' ),
					),
					'title'               => array(
						'type'        => 'string',
						'description' => __( 'Page/post title (create: required; update_meta: optional; update_seo: SEO title)', 'bricks-mcp' ),
					),
					'elements'            => array(
						'type'        => 'array',
						'description' => __( 'Element content array (create: optional, update_content/append_content: required)', 'bricks-mcp' ),
					),
					'parent_id'           => array(
						'type'        => 'string',
						'description' => __( 'Parent element ID for appended elements (append_content/import_clipboard: optional, default root level)', 'bricks-mcp' ),
					),
					'position'            => array(
						'type'        => 'integer',
						'description' => __( "Position within parent's children (append_content/import_clipboard: optional, omit to append at end)", 'bricks-mcp' ),
					),
					'clipboard_data'      => array(
						'type'        => 'object',
						'description' => __( 'Bricks copied elements JSON object with content array and optional globalClasses array. Global class styles are flattened into inline element settings (not imported as classes). (import_clipboard: required)', 'bricks-mcp' ),
					),
					'slug'                => array(
						'type'        => 'string',
						'description' => __( 'URL slug (update_meta: optional)', 'bricks-mcp' ),
					),
					'settings'            => array(
						'type'        => 'object',
						'description' => __( 'Settings key-value pairs (update_settings: required)', 'bricks-mcp' ),
					),
					'description'         => array(
						'type'        => 'string',
						'description' => __( 'SEO meta description (update_seo: optional)', 'bricks-mcp' ),
					),
					'robots_noindex'      => array(
						'type'        => 'boolean',
						'description' => __( 'Set noindex robots directive (update_seo: optional)', 'bricks-mcp' ),
					),
					'robots_nofollow'     => array(
						'type'        => 'boolean',
						'description' => __( 'Set nofollow robots directive (update_seo: optional)', 'bricks-mcp' ),
					),
					'canonical'           => array(
						'type'        => 'string',
						'description' => __( 'Canonical URL (update_seo: optional)', 'bricks-mcp' ),
					),
					'og_title'            => array(
						'type'        => 'string',
						'description' => __( 'Open Graph title (update_seo: optional)', 'bricks-mcp' ),
					),
					'og_description'      => array(
						'type'        => 'string',
						'description' => __( 'Open Graph description (update_seo: optional)', 'bricks-mcp' ),
					),
					'og_image'            => array(
						'type'        => 'string',
						'description' => __( 'Open Graph image URL (update_seo: optional)', 'bricks-mcp' ),
					),
					'twitter_title'       => array(
						'type'        => 'string',
						'description' => __( 'Twitter card title (update_seo: optional)', 'bricks-mcp' ),
					),
					'twitter_description' => array(
						'type'        => 'string',
						'description' => __( 'Twitter card description (update_seo: optional)', 'bricks-mcp' ),
					),
					'twitter_image'       => array(
						'type'        => 'string',
						'description' => __( 'Twitter card image URL (update_seo: optional)', 'bricks-mcp' ),
					),
					'focus_keyword'       => array(
						'type'        => 'string',
						'description' => __( 'Focus keyword for SEO analysis (update_seo: optional; Yoast/Rank Math only)', 'bricks-mcp' ),
					),
					'snapshot_id'         => array(
						'type'        => 'string',
						'description' => __( 'Snapshot ID to restore (restore: required)', 'bricks-mcp' ),
					),
					'label'               => array(
						'type'        => 'string',
						'description' => __( 'Human-readable label for the snapshot (snapshot: optional)', 'bricks-mcp' ),
					),
					'confirm'             => array(
						'type'        => 'boolean',
						'description' => __( 'Deprecated. Destructive actions now require token-based confirmation via the confirm_destructive_action tool.', 'bricks-mcp' ),
					),
					'force'               => array(
						'type'        => 'boolean',
						'description' => __( 'When true, permanently delete instead of moving to trash (delete action only).', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'handle' )
		);
	}
}
