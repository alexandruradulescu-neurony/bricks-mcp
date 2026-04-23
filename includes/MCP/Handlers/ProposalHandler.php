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
use BricksMCP\MCP\Services\ImageInputResolver;
use BricksMCP\MCP\Services\ProposalService;
use BricksMCP\MCP\Services\VisionProvider;
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

	/**
	 * Optional Bricks service (needed for image-input vision branch to build site_context).
	 */
	private ?BricksService $bricks_service;

	/**
	 * Optional vision provider (image-input branch moved to design_pattern:from_image in v3.32).
	 */
	private ?VisionProvider $vision;

	/**
	 * Optional image resolver (required when caller passes image_*).
	 */
	private ?ImageInputResolver $image_resolver;

	/**
	 * @param ProposalService        $proposal_service Proposal orchestration service.
	 * @param callable               $require_bricks   Guard returning WP_Error when Bricks is missing.
	 * @param BricksService|null     $bricks_service   Optional; retained for future use.
	 * @param VisionProvider|null    $vision           Optional; image-input branch moved to design_pattern:from_image (Task 8).
	 * @param ImageInputResolver|null $image_resolver   Optional; retained for future use.
	 */
	public function __construct(
		ProposalService $proposal_service,
		callable $require_bricks,
		?BricksService $bricks_service = null,
		?VisionProvider $vision = null,
		?ImageInputResolver $image_resolver = null
	) {
		$this->proposal_service = $proposal_service;
		$this->require_bricks   = $require_bricks;
		$this->bricks_service   = $bricks_service;
		$this->vision           = $vision;
		$this->image_resolver   = $image_resolver;
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

		if ( 0 === $page_id ) {
			return new \WP_Error( 'missing_page_id', 'page_id or template_id is required.' );
		}

		// v3.31: description is required ONLY when no image_* input and no design_plan provided.
		// Image-only and design_plan-only callers are valid alternative entry points per spec §4.2.
		$has_image = isset( $args['image_url'] ) || isset( $args['image_id'] ) || isset( $args['image_base64'] );
		$has_plan  = isset( $args['design_plan'] ) && is_array( $args['design_plan'] );

		if ( '' === $description && ! $has_image && ! $has_plan ) {
			return new \WP_Error(
				'missing_description',
				'description is required when no image_* input and no design_plan is provided.'
			);
		}

		// v3.32: image-input branch moved to design_pattern(action: from_image).
		// propose_design no longer accepts image inputs (Task 8 will formalize).
		if ( $has_image && ! $has_plan ) {
			return new \WP_Error(
				'propose_design_image_moved',
				'propose_design no longer accepts image inputs. Use design_pattern(action: from_image, page_id: N) instead.'
			);
		}

		$design_plan = $args['design_plan'] ?? null;

		// Pass design_plan (null for Phase 1, array for Phase 2).
		return $this->proposal_service->create( $page_id, $description, is_array( $design_plan ) ? $design_plan : null );
	}

	/**
	 * Register the propose_design tool.
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'propose_design',
			__( "Two-phase design tool. MUST be called twice before build_structure.\n\n"
				. "INPUT ALTERNATIVES (at least ONE required): description, design_plan, or image_* (image_url/image_id/image_base64).\n"
				. "description is only required when BOTH design_plan and image_* are absent; when image_* is provided, description is optional extra guidance.\n\n"
				. "PHASE 1 — DISCOVERY (description only, no design_plan):\n"
				. "Returns site context, available element types with PURPOSE descriptions, available layouts, global classes, CSS variables, and design/business briefs.\n"
				. "You use this to understand WHAT building blocks exist and WHAT the site looks like.\n"
				. "Does NOT return a proposal_id or schema.\n\n"
				. "CRITICAL: Phase 1 also returns reference_patterns — curated design compositions showing proven arrangements.\n"
				. "Pick the closest matching pattern and adapt it into your design_plan. Do NOT invent layouts from scratch.\n\n"
				. "PHASE 2 — PROPOSAL (description + design_plan):\n"
				. "After reviewing Phase 1 data, think as a DESIGNER and provide a design_plan with your decisions.\n"
				. "Returns proposal_id + suggested_schema generated from YOUR design decisions.\n"
				. "Replace [PLACEHOLDER] content later during populate_content. Then call build_structure(proposal_id) to create the element tree, populate_content(section_id, content_map) to fill it, and verify_build to confirm.\n\n"
				. "IMAGE INPUT (v3.31, alternative to design_plan; description is optional):\n"
				. "Pass image_url/image_id/image_base64 instead of design_plan — server-side vision produces the design_plan from the image, then the normal Phase 2 flow runs. reference_json can be passed for calibration.\n"
				. "When both design_plan and image_* are provided, image_* is IGNORED (text-only caller path preserved).\n\n"
				. "design_plan REQUIRED fields:\n"
				. "- section_type: hero|features|pricing|cta|testimonials|split|generic\n"
				. "- layout: centered|split-60-40|split-50-50|grid-2|grid-3|grid-4\n"
				. "- elements: [{type, role, content_hint, tag?, class_intent?}]\n"
				. "- background?: dark|light\n"
				. "- background_image?: \"unsplash:query\" for background photo\n"
				. "- patterns?: [{name, repeat, element_structure: [{type, role}], content_hint}]", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'page_id'        => array(
						'type'        => 'integer',
						'description' => __( 'Target page ID to build on.', 'bricks-mcp' ),
					),
					'template_id'    => array(
						'type'        => 'integer',
						'description' => __( 'Target template ID (alternative to page_id).', 'bricks-mcp' ),
					),
					'description'    => array(
						'type'        => 'string',
						'description' => __( 'Free-text description of what to build.', 'bricks-mcp' ),
					),
					'design_plan'    => array(
						'type'        => 'object',
						'description' => __( 'Phase 2 ONLY. Your structured design decisions: section_type, layout, background, elements (each with type + role + content_hint), and optional patterns. Omit this for Phase 1 discovery. Ignored if an image_* arg is also provided.', 'bricks-mcp' ),
					),
					'image_url'      => array(
						'type'        => 'string',
						'description' => __( 'HTTPS image URL — server-side vision produces design_plan from the image', 'bricks-mcp' ),
					),
					'image_id'       => array(
						'type'        => 'integer',
						'description' => __( 'WP media attachment ID (alternative to image_url/image_base64)', 'bricks-mcp' ),
					),
					'image_base64'   => array(
						'type'        => 'string',
						'description' => __( 'Raw base64-encoded image bytes (alternative to image_url/image_id)', 'bricks-mcp' ),
					),
					'reference_json' => array(
						'type'        => 'object',
						'description' => __( 'Optional known-good pattern for calibration (used as few-shot + post-vision diff)', 'bricks-mcp' ),
					),
				),
				// description is no longer schema-required: callers may instead supply design_plan OR image_* as the input.
				// The handler still enforces that at least one of description / design_plan / image_* must be present.
				'required'   => array(),
			),
			array( $this, 'handle' )
		);
	}
}
