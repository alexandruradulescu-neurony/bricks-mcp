<?php
/**
 * Code handler for MCP Router.
 *
 * Handles page-level custom CSS and JavaScript.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\BricksService;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles code tool actions.
 */
final class CodeHandler {

	/**
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
	 * Handle code tool actions.
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

		if ( 'set_page_scripts' === $action ) {
			if ( ! $this->bricks_service->is_dangerous_actions_enabled() ) {
				return new \WP_Error(
					'dangerous_actions_disabled',
					__( 'Custom scripts require the Dangerous Actions toggle to be enabled in Settings > Bricks MCP.', 'bricks-mcp' )
				);
			}
		}

		return match ( $action ) {
			'get_page_css'     => $this->tool_get_page_css( $args ),
			'set_page_css'     => $this->tool_set_page_css( $args ),
			'get_page_scripts' => $this->tool_get_page_scripts( $args ),
			'set_page_scripts' => $this->tool_set_page_scripts( $args ),
			default            => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: get_page_css, set_page_css, get_page_scripts, set_page_scripts', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Get page custom CSS.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id'.
	 * @return array<string, mixed>|\WP_Error Code data or error.
	 */
	private function tool_get_page_css( array $args ): array|\WP_Error {
		$post_id = $args['post_id'] ?? null;

		if ( null === $post_id ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required for get_page_css.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->get_page_code( (int) $post_id );
	}

	/**
	 * Tool: Set page custom CSS.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id' and 'css'.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	private function tool_set_page_css( array $args ): array|\WP_Error {
		$post_id = $args['post_id'] ?? null;

		if ( null === $post_id ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required for set_page_css.', 'bricks-mcp' )
			);
		}

		if ( ! array_key_exists( 'css', $args ) ) {
			return new \WP_Error(
				'missing_css',
				__( 'css is required for set_page_css. Send empty string to remove custom CSS.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_page_css( (int) $post_id, (string) $args['css'] );
	}

	/**
	 * Tool: Get page custom scripts only.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id'.
	 * @return array<string, mixed>|\WP_Error Script data or error.
	 */
	private function tool_get_page_scripts( array $args ): array|\WP_Error {
		$post_id = $args['post_id'] ?? null;

		if ( null === $post_id ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required for get_page_scripts.', 'bricks-mcp' )
			);
		}

		$code = $this->bricks_service->get_page_code( (int) $post_id );

		if ( is_wp_error( $code ) ) {
			return $code;
		}

		return array(
			'post_id'                 => $code['post_id'],
			'customScriptsHeader'     => $code['customScriptsHeader'],
			'customScriptsBodyHeader' => $code['customScriptsBodyHeader'],
			'customScriptsBodyFooter' => $code['customScriptsBodyFooter'],
			'has_scripts'             => $code['has_scripts'],
		);
	}

	/**
	 * Tool: Set page custom scripts.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id' and script placement params.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	private function tool_set_page_scripts( array $args ): array|\WP_Error {
		$post_id = $args['post_id'] ?? null;

		if ( null === $post_id ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required for set_page_scripts.', 'bricks-mcp' )
			);
		}

		$scripts = array();

		if ( array_key_exists( 'header', $args ) ) {
			$scripts['customScriptsHeader'] = (string) $args['header'];
		}

		if ( array_key_exists( 'body_header', $args ) ) {
			$scripts['customScriptsBodyHeader'] = (string) $args['body_header'];
		}

		if ( array_key_exists( 'body_footer', $args ) ) {
			$scripts['customScriptsBodyFooter'] = (string) $args['body_footer'];
		}

		if ( empty( $scripts ) ) {
			return new \WP_Error(
				'no_scripts',
				__( 'At least one script parameter is required: header, body_header, or body_footer.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_page_scripts( (int) $post_id, $scripts );
	}
}
