<?php
/**
 * Page layout handler for MCP Router.
 *
 * Proposes full-page section sequences based on page intent,
 * returning design_plan skeletons ready for propose_design.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\PageLayoutService;
use BricksMCP\MCP\ToolRegistry;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the propose_page_layout tool.
 */
final class PageLayoutHandler {

	/**
	 * @var PageLayoutService
	 */
	private PageLayoutService $layout_service;

	/**
	 * Bricks check callback.
	 *
	 * @var callable
	 */
	private $require_bricks;

	/**
	 * Constructor.
	 *
	 * @param PageLayoutService $layout_service  Page layout service instance.
	 * @param callable          $require_bricks  Callback that returns \WP_Error|null.
	 */
	public function __construct( PageLayoutService $layout_service, callable $require_bricks ) {
		$this->layout_service = $layout_service;
		$this->require_bricks = $require_bricks;
	}

	/**
	 * Handle the propose_page_layout tool call.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Layout recommendation or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$intent = sanitize_text_field( $args['intent'] ?? '' );

		if ( '' === $intent ) {
			return new \WP_Error(
				'missing_intent',
				__( 'intent is required. Describe the page purpose, e.g. "services landing page", "company about page", "product launch page".', 'bricks-mcp' )
			);
		}

		$tone    = isset( $args['tone'] ) ? sanitize_text_field( $args['tone'] ) : null;
		$page_id = ! empty( $args['page_id'] ) ? (int) $args['page_id'] : null;

		return $this->layout_service->compose( $intent, $tone, $page_id );
	}

	/**
	 * Register the propose_page_layout tool with the given registry.
	 *
	 * @param ToolRegistry $registry Tool registry instance.
	 * @return void
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'propose_page_layout',
			__( "Propose a full-page section sequence based on page intent.\n\n"
				. "Given an intent like \"services landing page\" or \"company about page\", returns an ordered list of recommended sections "
				. "(hero, features, pricing, testimonials, split, cta, etc.) with:\n"
				. "- A design_plan skeleton per section (section_type, layout, background, basic elements)\n"
				. "- A recommended_pattern_id from the pattern library\n"
				. "- A rationale explaining each section choice\n\n"
				. "Next step: for each section, enrich the design_plan with content_hints, then call propose_design + build_from_schema.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'intent' => array(
						'type'        => 'string',
						'description' => __( 'Page intent description (required). E.g. "services landing page", "company about page", "product launch page".', 'bricks-mcp' ),
					),
					'page_id' => array(
						'type'        => 'integer',
						'description' => __( 'Target page ID (optional). Helps contextualize if existing sections should be considered.', 'bricks-mcp' ),
					),
					'tone' => array(
						'type'        => 'string',
						'description' => __( 'Desired tone (optional). E.g. "professional", "playful", "minimal". Defaults to "professional".', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'intent' ),
			),
			array( $this, 'handle' ),
			array( 'readOnlyHint' => true )
		);
	}
}
