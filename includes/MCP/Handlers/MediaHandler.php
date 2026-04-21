<?php
/**
 * Media handler for MCP Router.
 *
 * Handles media library, Unsplash search, image sideloading, and featured images.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\MediaService;
use BricksMCP\MCP\ToolRegistry;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles media tool actions.
 */
final class MediaHandler {

	/**
	 * Default page size for smart_search when caller supplies none.
	 */
	private const SMART_SEARCH_DEFAULT_PER_PAGE = 5;

	/**
	 * Hard cap on smart_search per_page. Prevents expensive Unsplash/WP queries.
	 */
	private const SMART_SEARCH_MAX_PER_PAGE = 30;

	/**
	 * Default page size for get_media_library when caller supplies none.
	 */
	private const MEDIA_LIBRARY_DEFAULT_PER_PAGE = 20;

	/**
	 * @var MediaService
	 */
	private MediaService $media_service;

	/**
	 * Bricks check callback.
	 *
	 * @var callable
	 */
	private $require_bricks;

	/**
	 * Constructor.
	 *
	 * @param MediaService $media_service   Media service instance.
	 * @param callable     $require_bricks  Callback that returns \WP_Error|null.
	 */
	public function __construct( MediaService $media_service, callable $require_bricks ) {
		$this->media_service   = $media_service;
		$this->require_bricks  = $require_bricks;
	}

	/**
	 * Handle media tool actions.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action = sanitize_text_field( $args['action'] ?? '' );

		// Map 'image_size' to 'size' for get_image_settings handler.
		if ( isset( $args['image_size'] ) && ! isset( $args['size'] ) ) {
			$args['size'] = $args['image_size'];
		}

		return match ( $action ) {
			'search_unsplash'    => $this->tool_search_unsplash( $args ),
			'sideload'           => $this->tool_sideload_image( $args ),
			'list'               => $this->tool_get_media_library( $args ),
			'set_featured'       => $this->tool_set_featured_image( $args ),
			'remove_featured'    => $this->tool_remove_featured_image( $args ),
			'get_image_settings' => $this->tool_get_image_element_settings( $args ),
			'smart_search'       => $this->tool_smart_search( $args ),
			default              => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: search_unsplash, sideload, list, set_featured, remove_featured, get_image_settings, smart_search', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Search Unsplash photos.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Search results or error.
	 */
	private function tool_search_unsplash( array $args ): array|\WP_Error {
		if ( empty( $args['query'] ) || ! is_string( $args['query'] ) ) {
			return new \WP_Error(
				'missing_query',
				__( 'query parameter is required and must be a non-empty string.', 'bricks-mcp' )
			);
		}

		return $this->media_service->search_photos( $args['query'] );
	}

	/**
	 * Tool: Sideload image from URL into WordPress media library.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Sideload result or error.
	 */
	private function tool_sideload_image( array $args ): array|\WP_Error {
		if ( empty( $args['url'] ) || ! is_string( $args['url'] ) ) {
			return new \WP_Error(
				'missing_url',
				__( 'url parameter is required and must be a non-empty string.', 'bricks-mcp' )
			);
		}

		$url               = $args['url'];
		$alt_text          = isset( $args['alt_text'] ) && is_string( $args['alt_text'] ) ? $args['alt_text'] : '';
		$title             = isset( $args['title'] ) && is_string( $args['title'] ) ? $args['title'] : '';
		$unsplash_id       = isset( $args['unsplash_id'] ) && is_string( $args['unsplash_id'] ) ? $args['unsplash_id'] : null;
		$download_location = isset( $args['download_location'] ) && is_string( $args['download_location'] ) ? $args['download_location'] : null;

		return $this->media_service->sideload_from_url( $url, $alt_text, $title, $unsplash_id, $download_location );
	}

	/**
	 * Tool: Browse the WordPress media library.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Media library results or error.
	 */
	private function tool_get_media_library( array $args ): array|\WP_Error {
		$search    = isset( $args['search'] ) && is_string( $args['search'] ) ? $args['search'] : '';
		$mime_type = isset( $args['mime_type'] ) && is_string( $args['mime_type'] ) ? $args['mime_type'] : 'image';
		// Accept integer OR integer-string (HTTP-transport-decoded JSON sometimes lands as string).
		$per_page  = isset( $args['per_page'] ) && is_numeric( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : self::MEDIA_LIBRARY_DEFAULT_PER_PAGE;
		$page      = isset( $args['page'] ) && is_numeric( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

		return $this->media_service->get_media_library_items( $search, $mime_type, $per_page, $page );
	}

	/**
	 * Tool: Set or replace the featured image for a post.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Featured image result or error.
	 */
	private function tool_set_featured_image( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) || ! is_numeric( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id parameter is required and must be an integer.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['attachment_id'] ) || ! is_numeric( $args['attachment_id'] ) ) {
			return new \WP_Error(
				'missing_attachment_id',
				__( 'attachment_id parameter is required and must be an integer.', 'bricks-mcp' )
			);
		}

		$post_id       = (int) $args['post_id'];
		$attachment_id = (int) $args['attachment_id'];

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf( __( 'Post %d not found. Use page:list to find valid post IDs.', 'bricks-mcp' ), $post_id )
			);
		}

		if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
			return new \WP_Error(
				'thumbnails_not_supported',
				sprintf( __( 'Post type "%s" does not support featured images (thumbnails).', 'bricks-mcp' ), $post->post_type )
			);
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error(
				'attachment_not_found',
				sprintf( __( 'Attachment %d not found in media library. Use media:sideload to upload an image first, or media:list to find existing images.', 'bricks-mcp' ), $attachment_id )
			);
		}

		$old_thumbnail_id = get_post_thumbnail_id( $post_id );

		$result = set_post_thumbnail( $post_id, $attachment_id );
		if ( ! $result ) {
			return new \WP_Error(
				'set_thumbnail_failed',
				__( 'Failed to set the featured image. The post or attachment may be invalid.', 'bricks-mcp' )
			);
		}

		$response = array(
			'post_id'       => $post_id,
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ) ?: '',
			'title'         => get_the_title( $post_id ),
		);

		if ( $old_thumbnail_id && (int) $old_thumbnail_id !== $attachment_id ) {
			$response['replaced_attachment_id'] = (int) $old_thumbnail_id;
			$response['warning']                = sprintf(
				__( 'Previous featured image (attachment ID %d) was replaced.', 'bricks-mcp' ),
				(int) $old_thumbnail_id
			);
		}

		return $response;
	}

	/**
	 * Tool: Remove the featured image from a post.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Removal result or error.
	 */
	private function tool_remove_featured_image( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) || ! is_numeric( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id parameter is required and must be an integer.', 'bricks-mcp' )
			);
		}

		$post_id = (int) $args['post_id'];

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf( __( 'Post %d not found. Use page:list to find valid post IDs.', 'bricks-mcp' ), $post_id )
			);
		}

		$current_thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $current_thumbnail_id ) {
			return array(
				'post_id' => $post_id,
				'removed' => false,
				'message' => __( 'Post has no featured image.', 'bricks-mcp' ),
			);
		}

		delete_post_thumbnail( $post_id );

		return array(
			'post_id'               => $post_id,
			'removed'               => true,
			'removed_attachment_id' => (int) $current_thumbnail_id,
		);
	}

	/**
	 * Tool: Get Bricks image element settings for an attachment.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Image settings or error.
	 */
	private function tool_get_image_element_settings( array $args ): array|\WP_Error {
		if ( empty( $args['attachment_id'] ) || ! is_numeric( $args['attachment_id'] ) ) {
			return new \WP_Error(
				'missing_attachment_id',
				__( 'attachment_id parameter is required and must be an integer.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['target'] ) || ! is_string( $args['target'] ) ) {
			return new \WP_Error(
				'missing_target',
				__( 'target parameter is required. Use "image", "background", or "gallery".', 'bricks-mcp' )
			);
		}

		$attachment_id = (int) $args['attachment_id'];
		$target        = $args['target'];
		$size          = isset( $args['size'] ) && is_string( $args['size'] ) ? $args['size'] : 'full';

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error(
				'attachment_not_found',
				sprintf( __( 'Attachment %d not found in media library. Use media:sideload to upload an image first, or media:list to find existing images.', 'bricks-mcp' ), $attachment_id )
			);
		}

		$valid_targets = array( 'image', 'background', 'gallery' );
		if ( ! in_array( $target, $valid_targets, true ) ) {
			return new \WP_Error(
				'invalid_target',
				sprintf( __( 'Invalid target "%s". Use "image", "background", or "gallery".', 'bricks-mcp' ), $target )
			);
		}

		$image_obj = $this->media_service->build_bricks_image_object( $attachment_id, $size );
		if ( is_wp_error( $image_obj ) ) {
			return $image_obj;
		}

		$response = array(
			'attachment_id'       => $attachment_id,
			'bricks_image_object' => $image_obj,
		);

		switch ( $target ) {
			case 'image':
				$response['target']       = 'image';
				$response['usage']        = __( 'Set as settings.image on an Image element', 'bricks-mcp' );
				$response['settings_key'] = 'image';
				$response['value']        = $image_obj;
				break;

			case 'background':
				$response['target']       = 'background';
				$response['usage']        = __( 'Set as settings._background.image on a section or container', 'bricks-mcp' );
				$response['settings_key'] = '_background';
				$response['value']        = array( 'image' => $image_obj );
				$response['note']         = __( "You can add 'position': 'center center', 'size': 'cover', 'repeat': 'no-repeat' alongside the image key inside _background.", 'bricks-mcp' );
				break;

			case 'gallery':
				$response['target']       = 'gallery';
				$response['usage']        = __( 'Add to settings.images array on a Gallery element', 'bricks-mcp' );
				$response['settings_key'] = 'images';
				$response['value']        = $image_obj;
				$response['note']         = __( 'This is one item. For a gallery, collect multiple items into an array and set as settings.images.', 'bricks-mcp' );
				break;
		}

		return $response;
	}

	/**
	 * Tool: Smart search — Unsplash with business context enrichment.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Smart search results or error.
	 */
	private function tool_smart_search( array $args ): array|\WP_Error {
		if ( empty( $args['query'] ) || ! is_string( $args['query'] ) ) {
			return new \WP_Error(
				'missing_query',
				__( 'query parameter is required for smart_search.', 'bricks-mcp' )
			);
		}

		$per_page = isset( $args['per_page'] )
			? min( (int) $args['per_page'], self::SMART_SEARCH_MAX_PER_PAGE )
			: self::SMART_SEARCH_DEFAULT_PER_PAGE;

		return $this->media_service->smart_search(
			sanitize_text_field( $args['query'] ),
			$per_page
		);
	}

	/**
	 * Register the media tool with the given registry.
	 *
	 * @param ToolRegistry $registry Tool registry instance.
	 * @return void
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'media',
			__( "Manage images and media library.\n\nActions: search_unsplash, sideload (URL to library), list, set_featured, remove_featured, get_image_settings, smart_search (business-context-enriched Unsplash search).", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'        => array(
						'type'        => 'string',
						'enum'        => array( 'search_unsplash', 'sideload', 'list', 'set_featured', 'remove_featured', 'get_image_settings', 'smart_search' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'query'         => array(
						'type'        => 'string',
						'description' => __( 'Search query for Unsplash photos (search_unsplash: required)', 'bricks-mcp' ),
					),
					'url'           => array(
						'type'        => 'string',
						'description' => __( 'Image URL to download (sideload: required)', 'bricks-mcp' ),
					),
					'filename'      => array(
						'type'        => 'string',
						'description' => __( 'Filename for sideloaded image (sideload: optional)', 'bricks-mcp' ),
					),
					'alt_text'      => array(
						'type'        => 'string',
						'description' => __( 'Alt text for sideloaded image (sideload: optional)', 'bricks-mcp' ),
					),
					'post_id'       => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID (set_featured, remove_featured: required)', 'bricks-mcp' ),
					),
					'attachment_id' => array(
						'type'        => 'integer',
						'description' => __( 'Attachment ID from media library (set_featured: required; get_image_settings: optional)', 'bricks-mcp' ),
					),
					'image_size'    => array(
						'type'        => 'string',
						'description' => __( 'WordPress image size (get_image_settings: optional, e.g. full, large, medium)', 'bricks-mcp' ),
					),
					'per_page'      => array(
						'type'        => 'integer',
						'description' => __( 'Results per page (search_unsplash, list: optional)', 'bricks-mcp' ),
					),
					'page'          => array(
						'type'        => 'integer',
						'description' => __( 'Page number for pagination (list: optional)', 'bricks-mcp' ),
					),
					'mime_type'     => array(
						'type'        => 'string',
						'description' => __( "MIME type filter (list: optional, e.g. 'image', 'image/jpeg')", 'bricks-mcp' ),
					),
					'target'        => array(
						'type'        => 'string',
						'enum'        => array( 'image', 'background', 'gallery' ),
						'description' => __( 'Image usage target (get_image_settings: required)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'handle' )
		);
	}
}
