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
     * Get total session count for a user.
     *
     * @param int $user_id WordPress user ID.
     * @return int Session count.
     */
    public function get_session_count( int $user_id ): int {
        $transient_key = "bricks_mcp_sessions_{$user_id}";
        $sessions      = get_transient( $transient_key );

        if ( false === $sessions ) {
            return 0;
        }

        return count( $sessions );
    }

    /**
     * Track a new session for a user.
     *
     * @param int    $user_id    WordPress user ID.
     * @param string $session_id MCP session ID.
     * @return void
     */
    public function track_session( int $user_id, string $session_id ): void {
        $transient_key = "bricks_mcp_sessions_{$user_id}";
        $sessions      = get_transient( $transient_key );

        if ( false === $sessions ) {
            $sessions = [];
        }

        $sessions[] = [
            'session_id' => $session_id,
            'started_at' => current_time( 'mysql' ),
        ];

        // Keep only last 100 sessions.
        $sessions = array_slice( $sessions, -100 );

        set_transient( $transient_key, $sessions, 30 * DAY_IN_SECONDS );
    }

    /**
     * Check if this is the user's first session.
     *
     * @param string $session_id MCP session ID.
     * @param int    $user_id    WordPress user ID. Defaults to current user.
     * @return bool True if first session.
     */
    public function is_first_session( string $session_id, int $user_id = 0 ): bool {
        if ( 0 === $user_id ) {
            $user_id = get_current_user_id();
        }

        $transient_key = "bricks_mcp_onboarding_{$session_id}";
        $data          = get_transient( $transient_key );

        // If no onboarding data exists, this is first session - track it.
        if ( false === $data ) {
            $this->track_session( $user_id, $session_id );
            return true;
        }

        return false;
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

    /**
     * Get site context information.
     *
     * @return array<string, mixed> Site context data.
     */
    private function get_site_context(): array {
        $pages = get_posts( [
            'post_type'      => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        $templates = get_posts( [
            'post_type'      => 'bricks_template',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        $global_classes   = get_option( 'bricks_global_classes', [] );
        $global_variables = get_option( 'bricks_global_variables', [] );

        return [
            'name'                  => get_bloginfo( 'name' ),
            'url'                   => home_url(),
            'has_bricks'            => defined( 'BRICKS_VERSION' ),
            'has_woocommerce'       => class_exists( 'WooCommerce' ),
            'page_count'            => count( $pages ),
            'element_count'         => $this->count_total_elements(),
            'template_count'        => count( $templates ),
            'global_class_count'    => is_array( $global_classes ) ? count( $global_classes ) : 0,
            'global_variable_count' => is_array( $global_variables ) ? count( $global_variables ) : 0,
        ];
    }

    /**
     * Count total Bricks elements across all pages.
     *
     * @return int Total element count.
     */
    private function count_total_elements(): int {
        $pages = get_posts( [
            'post_type'      => 'page',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        $total = 0;
        foreach ( $pages as $page_id ) {
            $elements = get_post_meta( $page_id, '_bricks_page_content_2', true );
            if ( is_array( $elements ) ) {
                $total += count( $elements );
            }
        }

        return $total;
    }

    /**
     * Get welcome message.
     *
     * @return string Welcome message.
     */
    private function get_welcome_message(): string {
        $site_name = get_bloginfo( 'name' );
        $site_url  = home_url();

        return sprintf(
            /* translators: 1: Site name, 2: Site URL */
            __( 'Welcome to Bricks MCP! Connected to %1$s (%2$s)', 'bricks-mcp' ),
            $site_name,
            $site_url
        );
    }

    /**
     * Get workflow guide with three tiers.
     *
     * @return array<string, array> Workflow guide.
     */
    private function get_workflow_guide(): array {
        return [
            'direct_operations' => [
                'description' => __( 'Quick edits to existing content (text, moves, swaps)', 'bricks-mcp' ),
                'prerequisites' => [ 'get_site_info' ],
                'tools' => [ 'element:update', 'element:move', 'element:remove', 'media:set_featured', 'menu:update' ],
                'example' => __( 'Change the hero heading on the homepage', 'bricks-mcp' ),
                'example_call' => [
                    'name' => 'element',
                    'arguments' => [
                        'action' => 'update',
                        'post_id' => 94,
                        'element_id' => 'abc123',
                        'settings' => [ 'text' => 'New heading text' ],
                    ],
                ],
            ],
            'instructed_builds' => [
                'description' => __( 'Add new sections or element groups', 'bricks-mcp' ),
                'prerequisites' => [ 'get_site_info', 'global_class:list' ],
                'tools' => [ 'element:bulk_add', 'page:append_content' ],
                'example' => __( 'Add a testimonials section with 3 customer cards', 'bricks-mcp' ),
                'note' => __( 'Requires bypass_design_gate: true for sections', 'bricks-mcp' ),
            ],
            'design_builds' => [
                'description' => __( 'Build complete pages from design schemas', 'bricks-mcp' ),
                'prerequisites' => [ 'get_site_info', 'global_class:list', 'global_variable:list' ],
                'tools' => [ 'propose_design', 'build_from_schema' ],
                'example' => __( 'Design a services page with hero, features, pricing, and CTA sections', 'bricks-mcp' ),
                'flow' => __( 'Call propose_design first → review resolved data → write schema → call build_from_schema', 'bricks-mcp' ),
            ],
        ];
    }

    /**
     * Get quick-start examples.
     *
     * @return array<int, array> Quick-start examples.
     */
    private function get_quick_start_examples(): array {
        return [
            [
                'title'       => __( 'Get Site Info', 'bricks-mcp' ),
                'description' => __( 'Read site metadata and design tokens', 'bricks-mcp' ),
                'tool_name'   => 'get_site_info',
                'tool_arguments' => [ 'action' => 'info' ],
                'expected'    => __( 'JSON response with site metadata, design tokens, and page summaries', 'bricks-mcp' ),
            ],
            [
                'title'       => __( 'List Global Classes', 'bricks-mcp' ),
                'description' => __( 'Discover available CSS classes', 'bricks-mcp' ),
                'tool_name'   => 'global_class',
                'tool_arguments' => [ 'action' => 'list', 'limit' => 20 ],
                'expected'    => __( 'Array of global CSS classes with styles', 'bricks-mcp' ),
            ],
            [
                'title'       => __( 'Update Element Text', 'bricks-mcp' ),
                'description' => __( 'Change text on an existing element', 'bricks-mcp' ),
                'tool_name'   => 'element',
                'tool_arguments' => [
                    'action'     => 'update',
                    'post_id'    => 94,
                    'element_id' => 'abc123',
                    'settings'   => [ 'text' => 'New text here' ],
                ],
                'expected'    => __( 'Element updated successfully', 'bricks-mcp' ),
            ],
        ];
    }

    /**
     * Get test connection example.
     *
     * @return array<string, string> Test connection details.
     */
    private function get_test_connection(): array {
        return [
            'description'    => __( 'Verify your connection works with a simple read operation', 'bricks-mcp' ),
            'tool_name'      => 'get_site_info',
            'tool_arguments' => json_encode( [ 'action' => 'info' ] ),
            'expected'       => __( 'JSON response with site metadata, design tokens, and page summaries', 'bricks-mcp' ),
        ];
    }

    /**
     * Get important notes from AI notes option.
     *
     * @return array<int, string> Important notes.
     */
    private function get_important_notes(): array {
        $briefs = get_option( 'bricks_mcp_briefs', [] );
        $notes  = [];

        // Add AI notes if they exist.
        if ( ! empty( $briefs['ai_notes'] ) && is_array( $briefs['ai_notes'] ) ) {
            $notes = $briefs['ai_notes'];
        }

        // Add default notes if none exist.
        if ( empty( $notes ) ) {
            $notes = [
                __( 'Always use Romanian content for the homepage and service pages', 'bricks-mcp' ),
                __( 'For horizontal rows, use block with _direction: row — NOT div', 'bricks-mcp' ),
                __( 'Background overlays in Bricks use _gradient with applyTo: overlay', 'bricks-mcp' ),
            ];
        }

        return $notes;
    }

    /**
     * Get design brief summary.
     *
     * @return string Design brief summary.
     */
    private function get_design_brief_summary(): string {
        $briefs = get_option( 'bricks_mcp_briefs', [] );
        $design_brief = $briefs['design_brief'] ?? '';

        if ( empty( $design_brief ) ) {
            return __( 'No design brief set. Go to Bricks MCP > Briefs to add visual guidelines.', 'bricks-mcp' );
        }

        // Return first 500 characters as summary.
        return wp_trim_words( $design_brief, 50, '...' );
    }

    /**
     * Get business brief summary.
     *
     * @return string Business brief summary.
     */
    private function get_business_brief_summary(): string {
        $briefs = get_option( 'bricks_mcp_briefs', [] );
        $business_brief = $briefs['business_brief'] ?? '';

        if ( empty( $business_brief ) ) {
            return __( 'No business brief set. Go to Bricks MCP > Briefs to add business context.', 'bricks-mcp' );
        }

        // Return first 500 characters as summary.
        return wp_trim_words( $business_brief, 50, '...' );
    }
}
