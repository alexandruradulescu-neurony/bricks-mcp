<?php
/**
 * Page settings sub-handler: get_settings, update_settings actions.
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
 * Handles page settings actions (get_settings, update_settings).
 */
final class PageSettingsSubHandler {

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
	 * Get page-level Bricks settings.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Page settings or error.
	 */
	public function get_settings( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->get_page_settings( (int) $args['post_id'] );
	}

	/**
	 * Update page-level Bricks settings.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	public function update_settings( array $args ): array|\WP_Error {
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
}
