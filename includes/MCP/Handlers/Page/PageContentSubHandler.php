<?php
/**
 * Page content sub-handler: update_content, append_content, import_clipboard actions.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers\Page;

use BricksMCP\MCP\Services\BricksCore;
use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\ValidationService;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles page content actions (update_content, append_content, import_clipboard).
 */
final class PageContentSubHandler {

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
	 * Replace full Bricks element content for a page.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated content info or error.
	 */
	public function update_content( array $args ): array|\WP_Error {
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
		// Use the service resolver so header/footer template writes see the correct
		// current count. Previously BricksCore::META_KEY hardcoded the page key.
		$meta_key         = $this->bricks_service->resolve_elements_meta_key( $post_id );
		$current_elements = get_post_meta( $post_id, $meta_key, true );
		$old_count        = is_array( $current_elements ) ? count( $current_elements ) : 0;
		$new_count        = count( $elements );

		// Integer-math comparison avoids floating-point rounding errors on odd counts.
		// Previously `$new_count < (int)($old_count * 0.5)` with $old_count=5 gave
		// (int)(2.5)=2, so reducing 5→2 (60% reduction) was below the "50%" threshold
		// and bypassed confirm. Use doubled new_count against old_count instead.
		if ( $old_count > 0 && ( $new_count * 2 ) < $old_count && empty( $args['confirm'] ) ) {
			$reduction_pct = (int) round( ( 1 - ( $new_count / $old_count ) ) * 100 );
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf(
					/* translators: 1: Old element count, 2: New element count, 3: Reduction percentage */
					__( 'This update would reduce elements from %1$d to %2$d (%3$d%% reduction). Set confirm: true to proceed, or use page:append_content to add without replacing.', 'bricks-mcp' ),
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
	 * Append elements to existing Bricks content.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Result with appended element info or error.
	 */
	public function append_content( array $args ): array|\WP_Error {
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
	 * Import Bricks clipboard JSON (bricksCopiedElements format).
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Import result or error.
	 */
	public function import_clipboard( array $args ): array|\WP_Error {
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
}
