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

use BricksMCP\MCP\Services\ProposalService;
use BricksMCP\MCP\ToolRegistry;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the propose_design tool.
 */
final class ProposalHandler {

	private ProposalService $proposal_service;

	/**
	 * Bricks check callback.
	 * @var callable
	 */
	private $require_bricks;

	public function __construct( ProposalService $proposal_service, callable $require_bricks ) {
		$this->proposal_service = $proposal_service;
		$this->require_bricks   = $require_bricks;
	}

	/**
	 * Handle the propose_design tool call.
	 */
	public function handle( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$page_id     = (int) ( $args['page_id'] ?? $args['template_id'] ?? 0 );
		$description = sanitize_textarea_field( $args['description'] ?? '' );
		$design_plan = $args['design_plan'] ?? null;

		if ( 0 === $page_id ) {
			return new \WP_Error( 'missing_page_id', 'page_id or template_id is required.' );
		}
		if ( '' === $description ) {
			return new \WP_Error( 'missing_description', 'description is required. Describe what you want to build.' );
		}

		// Pass design_plan (null for Phase 1, array for Phase 2).
		return $this->proposal_service->create( $page_id, $description, is_array( $design_plan ) ? $design_plan : null );
	}

	/**
	 * Register the propose_design tool.
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'propose_design',
			__( "Two-phase design tool. MUST be called twice before build_from_schema.\n\n"
				. "PHASE 1 — DISCOVERY (description only, no design_plan):\n"
				. "Returns site context, available element types with PURPOSE descriptions, available layouts, global classes, CSS variables, and design/business briefs.\n"
				. "You use this to understand WHAT building blocks exist and WHAT the site looks like.\n"
				. "Does NOT return a proposal_id or schema.\n\n"
				. "PHASE 2 — PROPOSAL (description + design_plan):\n"
				. "After reviewing Phase 1 data, think as a DESIGNER and provide a design_plan with your decisions.\n"
				. "Returns proposal_id + suggested_schema generated from YOUR design decisions.\n"
				. "Replace [PLACEHOLDER] content in suggested_schema, then call build_from_schema.\n\n"
				. "design_plan REQUIRED fields:\n"
				. "- section_type: hero|features|pricing|cta|testimonials|split|generic\n"
				. "- layout: centered|split-60-40|split-50-50|grid-2|grid-3|grid-4\n"
				. "- elements: [{type, role, content_hint, tag?, class_intent?}]\n"
				. "- background?: dark|light\n"
				. "- patterns?: [{name, repeat, element_structure: [{type, role}], content_hint}]", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'page_id'     => array(
						'type'        => 'integer',
						'description' => __( 'Target page ID to build on.', 'bricks-mcp' ),
					),
					'template_id' => array(
						'type'        => 'integer',
						'description' => __( 'Target template ID (alternative to page_id).', 'bricks-mcp' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'Free-text description of what to build.', 'bricks-mcp' ),
					),
					'design_plan' => array(
						'type'        => 'object',
						'description' => __( 'Phase 2 ONLY. Your structured design decisions: section_type, layout, background, elements (each with type + role + content_hint), and optional patterns. Omit this for Phase 1 discovery.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'description' ),
			),
			array( $this, 'handle' )
		);
	}
}
