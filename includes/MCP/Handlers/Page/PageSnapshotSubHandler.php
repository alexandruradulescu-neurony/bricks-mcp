<?php
/**
 * Page snapshot sub-handler: snapshot, restore, list_snapshots actions.
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
 * Handles page snapshot actions (snapshot, restore, list_snapshots).
 */
final class PageSnapshotSubHandler {

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
	 * Create page snapshot.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Snapshot data or error.
	 */
	public function snapshot( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}
		$label = isset( $args['label'] ) ? sanitize_text_field( $args['label'] ) : '';
		return $this->bricks_service->snapshot_page( (int) $args['post_id'], $label );
	}

	/**
	 * Restore page from snapshot.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Restore result or error.
	 */
	public function restore( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}
		if ( empty( $args['snapshot_id'] ) ) {
			return new \WP_Error( 'missing_snapshot_id', __( 'snapshot_id is required. Use page:list_snapshots to find available snapshot IDs.', 'bricks-mcp' ) );
		}
		return $this->bricks_service->restore_snapshot( (int) $args['post_id'], sanitize_text_field( $args['snapshot_id'] ) );
	}

	/**
	 * List page snapshots.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Snapshots list or error.
	 */
	public function list_snapshots( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}
		return $this->bricks_service->list_snapshots( (int) $args['post_id'] );
	}
}
