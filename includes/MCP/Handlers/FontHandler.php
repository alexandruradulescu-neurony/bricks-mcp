<?php
/**
 * Font handler for MCP Router.
 *
 * Handles font configuration: status, Adobe Fonts, and settings.
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
 * Handles font tool actions.
 */
final class FontHandler {

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
	 * Handle font tool actions.
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

		return match ( $action ) {
			'get_status'      => $this->tool_get_font_status( $args ),
			'get_adobe_fonts' => $this->tool_get_adobe_fonts( $args ),
			'update_settings' => $this->tool_update_font_settings( $args ),
			default           => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: get_status, get_adobe_fonts, update_settings', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Get font configuration status overview.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed> Font status data.
	 */
	private function tool_get_font_status( array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->bricks_service->get_font_status();
	}

	/**
	 * Tool: Get cached Adobe Fonts.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed> Adobe Fonts data.
	 */
	private function tool_get_adobe_fonts( array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->bricks_service->get_adobe_fonts();
	}

	/**
	 * Tool: Update font-related settings.
	 *
	 * @param array<string, mixed> $args Tool arguments with font setting fields.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	private function tool_update_font_settings( array $args ): array|\WP_Error {
		$fields = array();

		if ( array_key_exists( 'disable_google_fonts', $args ) ) {
			$fields['disableGoogleFonts'] = $args['disable_google_fonts'];
		}

		if ( array_key_exists( 'webfont_loading', $args ) ) {
			$fields['webfontLoading'] = $args['webfont_loading'];
		}

		if ( array_key_exists( 'custom_fonts_preload', $args ) ) {
			$fields['customFontsPreload'] = $args['custom_fonts_preload'];
		}

		if ( empty( $fields ) ) {
			return new \WP_Error(
				'no_fields',
				__( 'No font settings provided. Use disable_google_fonts (boolean), webfont_loading (string), or custom_fonts_preload (boolean).', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_font_settings( $fields );
	}
}
