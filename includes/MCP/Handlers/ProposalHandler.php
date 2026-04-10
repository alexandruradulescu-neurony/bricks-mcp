<?php
/**
 * Proposal handler for MCP Router.
 *
 * Registers the propose_design tool that creates validated design
 * proposals before build_from_schema can be called.
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

		if ( 0 === $page_id ) {
			return new \WP_Error( 'missing_page_id', 'page_id or template_id is required.' );
		}
		if ( '' === $description ) {
			return new \WP_Error( 'missing_description', 'description is required. Describe what you want to build: layout, elements, style hints.' );
		}

		return $this->proposal_service->create( $page_id, $description );
	}

	/**
	 * Register the propose_design tool.
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'propose_design',
			__( "Create a validated design proposal before calling build_from_schema. Describe what you want to build and the MCP resolves it against the site's actual classes, variables, element schemas, and briefs.\n\nReturns a proposal_id (required by build_from_schema) plus resolved data: available and suggested classes, scoped variables, prefetched element schemas, and design/business briefs.\n\nThe proposal expires after 10 minutes.", 'bricks-mcp' ),
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
						'description' => __( 'Free-text description of what to build. Include: layout structure (rows, columns), element types (heading, image, cards, tabs), style hints (dark background, rounded corners), and content intent. The more specific, the better the resolved data.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'description' ),
			),
			array( $this, 'handle' )
		);
	}
}
