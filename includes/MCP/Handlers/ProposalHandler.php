<?php
/**
 * Proposal handler for MCP Router.
 *
 * Two-phase design flow:
 * Phase 1 (Discovery): Call with description only → get site context and element catalog.
 * Phase 2 (Proposal): Call with description + design_plan → get validated schema skeleton.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\ToolRegistry;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the propose_design tool.
 */
final class ProposalHandler {

	/**
	 * ProposalService (or compatible duck-type) — untyped to allow test doubles.
	 * @var object
	 */
	private $proposal_service;

	/**
	 * Bricks check callback.
	 * @var callable
	 */
	private $require_bricks;

	/**
	 * Optional Bricks service; retained for potential future use.
	 */
	private ?BricksService $bricks_service;

	/**
	 * @param object             $proposal_service ProposalService (or compatible duck-type).
	 * @param callable           $require_bricks   Guard returning WP_Error when Bricks is missing.
	 * @param BricksService|null $bricks_service   Optional; retained for future use.
	 */
	public function __construct(
		object $proposal_service,
		callable $require_bricks,
		?BricksService $bricks_service = null
	) {
		$this->proposal_service = $proposal_service;
		$this->require_bricks   = $require_bricks;
		$this->bricks_service   = $bricks_service;
	}

	/**
	 * Handle the propose_design tool call.
	 *
	 * image_* args (image_url, image_id, image_base64, reference_json) are silently ignored
	 * as of v3.32. Callers should migrate to design_pattern(action: from_image, page_id: N).
	 */
	public function handle( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) { return $bricks_error; }

		$page_id     = (int) ( $args['page_id'] ?? $args['template_id'] ?? 0 );
		$description = sanitize_textarea_field( $args['description'] ?? '' );

		if ( 0 === $page_id ) {
			return new \WP_Error( 'missing_page_id', 'page_id or template_id is required.' );
		}

		$has_plan = isset( $args['design_plan'] ) && is_array( $args['design_plan'] );
		if ( '' === $description && ! $has_plan ) {
			return new \WP_Error(
				'missing_description',
				'description is required when no design_plan is provided. For image-driven flows, use design_pattern(action: from_image, page_id: N) instead.'
			);
		}

		$design_plan = $args['design_plan'] ?? null;
		return $this->proposal_service->create( $page_id, $description, is_array( $design_plan ) ? $design_plan : null );
	}

	/**
	 * Register the propose_design tool.
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'propose_design',
			__( "Two-phase design tool. MUST be called twice before build_structure.\n\n"
				. "INPUT ALTERNATIVES (at least ONE required): description, design_plan.\n"
				. "For image-driven flows, use design_pattern(action: from_image, page_id: N) — that tool routes through this handler automatically.\n\n"
				. "PHASE 1 — DISCOVERY (description only): returns site context, reference patterns.\n"
				. "PHASE 2 — PROPOSAL (description + design_plan): returns proposal_id + suggested_schema.", 'bricks-mcp' ),
			[
				'type'       => 'object',
				'properties' => [
					'page_id'     => [ 'type' => 'integer', 'description' => __( 'Target page ID.', 'bricks-mcp' ) ],
					'template_id' => [ 'type' => 'integer', 'description' => __( 'Target template ID (alternative).', 'bricks-mcp' ) ],
					'description' => [ 'type' => 'string',  'description' => __( 'Free-text description of what to build.', 'bricks-mcp' ) ],
					'design_plan' => [ 'type' => 'object',  'description' => __( 'Phase 2 design_plan object.', 'bricks-mcp' ) ],
				],
				'required' => [],
			],
			[ $this, 'handle' ]
		);
	}
}
