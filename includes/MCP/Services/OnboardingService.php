<?php
/**
 * Onboarding service for MCP.
 *
 * Generates onboarding payloads for new sessions with workflow guidance,
 * site context, and quick-start examples.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OnboardingService {

    private BricksService $bricks_service;

    public function __construct( BricksService $bricks_service ) {
        $this->bricks_service = $bricks_service;
    }

    /**
     * Generate onboarding payload for a user.
     *
     * @param int $user_id WordPress user ID.
     * @return array<string, mixed> Onboarding payload.
     */
    public function generate_onboarding( int $user_id ): array {
        return [
            'welcome_message' => $this->get_welcome_message(),
            'site_context'    => $this->get_site_context(),
            'workflow_guide'  => $this->get_workflow_guide(),
            'quick_start_examples' => $this->get_quick_start_examples(),
            'important_notes' => $this->get_important_notes(),
            'design_brief_summary' => $this->get_design_brief_summary(),
            'business_brief_summary' => $this->get_business_brief_summary(),
            'test_connection' => $this->get_test_connection(),
        ];
    }

    /**
     * Check if this is the user's first session.
     *
     * @param string $session_id MCP session ID.
     * @return bool True if first session.
     */
    public function is_first_session( string $session_id ): bool {
        $transient_key = "bricks_mcp_onboarding_{$session_id}";
        $data          = get_transient( $transient_key );
        return false === $data;
    }

    /**
     * Mark onboarding as acknowledged for a session.
     *
     * @param string $session_id MCP session ID.
     * @return void
     */
    public function mark_acknowledged( string $session_id ): void {
        $transient_key = "bricks_mcp_onboarding_{$session_id}";
        set_transient(
            $transient_key,
            [
                'acknowledged_at' => current_time( 'mysql' ),
                'session_id'      => $session_id,
            ],
            DAY_IN_SECONDS
        );
    }

    // Stub methods for Task 1 - these will be implemented in subsequent tasks
    private function get_welcome_message(): string {
        return 'Welcome to Bricks MCP!';
    }

    private function get_site_context(): array {
        return [];
    }

    private function get_workflow_guide(): array {
        return [];
    }

    private function get_quick_start_examples(): array {
        return [];
    }

    private function get_important_notes(): array {
        return [];
    }

    private function get_design_brief_summary(): string {
        return '';
    }

    private function get_business_brief_summary(): string {
        return '';
    }

    private function get_test_connection(): array {
        return [];
    }
}
