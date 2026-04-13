<?php
/**
 * Page CRUD sub-handler: create, update_meta, delete, duplicate actions.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers\Page;

use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\ValidationService;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles page CRUD actions (create, update_meta, delete, duplicate).
 */
final class PageCrudSubHandler {

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
	 * Create a new Bricks page/post.
	 *
	 * Creates a post with Bricks editor enabled and optionally saves elements.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created post data or error.
	 */
	public function create( array $args ): array|\WP_Error {
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
	 * Update page/post metadata (title, status, slug).
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated metadata or error.
	 */
	public function update_meta( array $args ): array|\WP_Error {
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
	 * Move a page/post to trash.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Confirmation or error.
	 */
	public function delete( array $args ): array|\WP_Error {
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
	 * Duplicate a page/post including all Bricks content.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error New post data or error.
	 */
	public function duplicate( array $args ): array|\WP_Error {
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
}
