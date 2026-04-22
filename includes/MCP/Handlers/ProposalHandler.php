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
use BricksMCP\MCP\Services\VisionPatternGenerator;
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
	 * Optional vision orchestrator (required when caller passes image_*).
	 */
	private ?VisionPatternGenerator $vision;

	/**
	 * Optional image resolver (required when caller passes image_*).
	 */
	private ?ImageInputResolver $image_resolver;

	/**
	 * @param ProposalService             $proposal_service Proposal orchestration service.
	 * @param callable                    $require_bricks   Guard returning WP_Error when Bricks is missing.
	 * @param BricksService|null          $bricks_service   Optional; required for image-input branch.
	 * @param VisionPatternGenerator|null $vision           Optional; required for image-input branch.
	 * @param ImageInputResolver|null     $image_resolver   Optional; required for image-input branch.
	 */
	public function __construct(
		ProposalService $proposal_service,
		callable $require_bricks,
		?BricksService $bricks_service = null,
		?VisionPatternGenerator $vision = null,
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
		if ( '' === $description ) {
			return new \WP_Error( 'missing_description', 'description is required. Describe what you want to build.' );
		}

		// v3.31: if image input provided and no design_plan, let vision produce design_plan.
		$has_image = isset( $args['image_url'] ) || isset( $args['image_id'] ) || isset( $args['image_base64'] );
		$has_plan  = isset( $args['design_plan'] ) && is_array( $args['design_plan'] );

		if ( $has_image && ! $has_plan ) {
			if ( null === $this->vision || null === $this->image_resolver || null === $this->bricks_service ) {
				return new \WP_Error(
					'propose_design_vision_unavailable',
					'Vision pipeline not initialized. This tool must be constructed with vision dependencies to support image inputs.'
				);
			}
			$image = $this->image_resolver->resolve( $args );
			if ( is_wp_error( $image ) ) {
				return $image;
			}

			$reference_json = null;
			if ( isset( $args['reference_json'] ) && is_array( $args['reference_json'] ) ) {
				$reference_json = $args['reference_json'];
			}

			$bricks_service = $this->bricks_service;
			$variables_raw  = $bricks_service->get_global_variable_service()->get_all_with_values();
			$site_context   = [
				'classes'   => $bricks_service->get_global_class_service()->get_all_by_name(),
				'variables' => $variables_raw,
				'theme'     => ( static function ( $vars ) {
					foreach ( $vars as $name => $_ ) {
						$lname = strtolower( (string) $name );
						if ( str_contains( $lname, 'base-ultra-dark' ) || str_contains( $lname, 'base-dark' ) ) {
							return 'dark';
						}
					}
					return 'light';
				} )( $variables_raw ),
			];

			$mapped = $this->vision->generate_schema(
				$image,
				$site_context,
				$reference_json,
				[
					'category' => sanitize_text_field( $args['category'] ?? 'generic' ),
					'variant'  => sanitize_text_field( $args['background'] ?? '' ),
				]
			);
			if ( is_wp_error( $mapped ) ) {
				return $mapped;
			}

			$args['design_plan']  = $mapped['design_plan'];
			$args['vision_debug'] = [
				'new_classes'        => $mapped['new_classes']        ?? [],
				'reused_classes'     => $mapped['reused_classes']     ?? [],
				'deduped_classes'    => $mapped['deduped_classes']    ?? [],
				'vision_cost_tokens' => $mapped['vision_cost_tokens'] ?? [],
				'conversion_log'     => $mapped['conversion_log']     ?? [],
			];
			// Fall-through to existing design_plan processing.
		}

		$design_plan = $args['design_plan'] ?? null;

		// Pass design_plan (null for Phase 1, array for Phase 2).
		$result = $this->proposal_service->create( $page_id, $description, is_array( $design_plan ) ? $design_plan : null );

		// Surface vision_debug in successful responses when vision ran.
		if ( isset( $args['vision_debug'] ) && is_array( $result ) && ! is_wp_error( $result ) ) {
			$result['vision_debug'] = $args['vision_debug'];
		}

		return $result;
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
				. "CRITICAL: Phase 1 also returns reference_patterns — curated design compositions showing proven arrangements.\n"
				. "Pick the closest matching pattern and adapt it into your design_plan. Do NOT invent layouts from scratch.\n\n"
				. "PHASE 2 — PROPOSAL (description + design_plan):\n"
				. "After reviewing Phase 1 data, think as a DESIGNER and provide a design_plan with your decisions.\n"
				. "Returns proposal_id + suggested_schema generated from YOUR design decisions.\n"
				. "Replace [PLACEHOLDER] content in suggested_schema, then call build_from_schema.\n\n"
				. "IMAGE INPUT (v3.31, alternative to design_plan):\n"
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
				'required'   => array( 'description' ),
			),
			array( $this, 'handle' )
		);
	}
}
