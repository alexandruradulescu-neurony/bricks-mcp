<?php
/**
 * Onboarding handler for MCP Router.
 *
 * Provides onboarding guide with workflows, examples, and site context.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\OnboardingService;
use BricksMCP\MCP\ToolRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OnboardingHandler {

	private OnboardingService $onboarding_service;

	public function __construct( OnboardingService $onboarding_service ) {
		$this->onboarding_service = $onboarding_service;
	}

	/**
	 * Handle the get_onboarding_guide tool call.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed> Onboarding guide data.
	 */
	public function handle( array $args ): array {
		$section = sanitize_text_field( $args['section'] ?? 'all' );

		return match ( $section ) {
			'workflows' => $this->get_workflows(),
			'examples' => $this->get_examples(),
			'site_context' => $this->get_site_context(),
			'briefs' => $this->get_briefs(),
			'acknowledge' => $this->acknowledge( $args ),
			default => $this->get_all(),
		};
	}

	/**
	 * Get all onboarding data.
	 *
	 * @return array<string, mixed> Full onboarding payload.
	 */
	private function get_all(): array {
		return [
			'section' => 'all',
			'data' => $this->onboarding_service->generate_onboarding( get_current_user_id() ),
		];
	}

	/**
	 * Get workflow guide only.
	 *
	 * @return array<string, array> Workflow guide.
	 */
	private function get_workflows(): array {
		return [
			'section' => 'workflows',
			'data' => $this->onboarding_service->get_workflow_guide(),
		];
	}

	/**
	 * Get quick-start examples only.
	 *
	 * @return array<int, array> Quick-start examples.
	 */
	private function get_examples(): array {
		return [
			'section' => 'examples',
			'data' => $this->onboarding_service->get_quick_start_examples(),
		];
	}

	/**
	 * Get site context only.
	 *
	 * @return array<string, mixed> Site context.
	 */
	private function get_site_context(): array {
		return [
			'section' => 'site_context',
			'data' => $this->onboarding_service->get_site_context(),
		];
	}

	/**
	 * Get briefs only.
	 *
	 * @return array<string, string> Design and business briefs.
	 */
	private function get_briefs(): array {
		return [
			'section' => 'briefs',
			'data' => [
				'design_brief' => $this->onboarding_service->get_design_brief_summary(),
				'business_brief' => $this->onboarding_service->get_business_brief_summary(),
			],
		];
	}

	/**
	 * Acknowledge onboarding completion.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed> Acknowledgment result.
	 */
	private function acknowledge( array $args ): array {
		$session_id = sanitize_text_field( $args['session_id'] ?? '' );
		if ( ! empty( $session_id ) ) {
			$this->onboarding_service->mark_acknowledged( $session_id );
		}

		return [
			'acknowledged' => true,
			'session_id' => $session_id,
			'message' => __( 'Onboarding acknowledged. You can now proceed with building.', 'bricks-mcp' ),
		];
	}

	/**
	 * Register the get_onboarding_guide tool.
	 *
	 * @param ToolRegistry $registry Tool registry instance.
	 * @return void
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'get_onboarding_guide',
			__( 'Get onboarding guide with workflows, examples, and site context. Use section parameter to return specific parts.', 'bricks-mcp' ),
			[
				'type' => 'object',
				'properties' => [
					'section' => [
					    'type' => 'string',
					    'enum' => [ 'all', 'workflows', 'examples', 'site_context', 'briefs', 'acknowledge' ],
					    'description' => __( 'Which section to return (default: all)', 'bricks-mcp' ),
					],
					'session_id' => [
					    'type' => 'string',
					    'description' => __( 'Session ID for acknowledgment (required for section=acknowledge)', 'bricks-mcp' ),
					],
				],
			],
			[ $this, 'handle' ]
		);
	}
}
