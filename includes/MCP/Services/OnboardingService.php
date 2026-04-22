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
        // Load briefs once, pass to all methods that need them (was 3 separate get_option calls).
        $briefs = get_option( BricksCore::OPTION_BRIEFS, [] );
        if ( ! is_array( $briefs ) ) {
            $briefs = [];
        }

        return [
            'welcome_message' => $this->get_welcome_message(),
            'site_context'    => $this->get_site_context(),
            'workflow_guide'  => $this->get_workflow_guide(),
            'quick_start_examples' => $this->get_quick_start_examples(),
            'important_notes' => $this->get_important_notes( $briefs ),
            'design_brief_summary' => $this->get_design_brief_summary( $briefs ),
            'business_brief_summary' => $this->get_business_brief_summary( $briefs ),
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
     * Request-scoped cache (first-level, avoids re-hitting object cache within the same request).
     *
     * @var array<string, mixed>|null
     */
    private static ?array $site_context_cache = null;

    /**
     * Object cache group + key (second-level, survives across MCP HTTP requests).
     *
     * Invalidated via bump_cache_version() on save_post (Bricks post types) and
     * on updates to bricks_global_classes / bricks_global_variables options.
     */
    private const CACHE_GROUP        = 'bricks_mcp';
    private const CACHE_KEY_BASE     = 'site_context_v1';
    private const CACHE_VERSION_OPT  = 'bricks_mcp_site_context_cache_v';
    private const CACHE_TTL          = 1800; // 30 minutes.

    /**
     * Word-count cap for the brief summaries surfaced during onboarding.
     * Keeps payloads compact without truncating mid-sentence (wp_trim_words
     * handles word boundaries and ellipsis).
     */
    private const BRIEF_SUMMARY_WORD_LIMIT = 50;

    /**
     * Register the invalidation hooks. Called once from the constructor.
     */
    private static function register_cache_invalidation(): void {
        static $registered = false;
        if ( $registered ) {
            return;
        }
        $registered = true;

        // Invalidate when any Bricks content post is saved.
        add_action( 'save_post', function ( int $post_id ): void {
            $meta = get_post_meta( $post_id, BricksCore::META_KEY, true );
            if ( ! empty( $meta ) ) {
                self::bump_cache_version();
            }
        }, 10, 1 );

        // Invalidate when the underlying options change.
        foreach ( [ BricksCore::OPTION_GLOBAL_CLASSES, BricksCore::OPTION_GLOBAL_VARIABLES ] as $option ) {
            add_action( "update_option_{$option}", fn() => self::bump_cache_version() );
            add_action( "add_option_{$option}", fn() => self::bump_cache_version() );
        }
    }

    /**
     * Bump the cache version. Effectively invalidates all previous entries.
     */
    private static function bump_cache_version(): void {
        $v = (int) get_option( self::CACHE_VERSION_OPT, 1 );
        update_option( self::CACHE_VERSION_OPT, $v + 1, false );
        self::$site_context_cache = null;
    }

    /**
     * Build the versioned cache key (so we don't have to enumerate keys on invalidation).
     */
    private static function cache_key(): string {
        $v = (int) get_option( self::CACHE_VERSION_OPT, 1 );
        return self::CACHE_KEY_BASE . '_v' . $v;
    }

    /**
     * Get site context information.
     *
     * Two-level cache: request-scoped static → object cache (persists across requests).
     * Invalidates on Bricks content saves and global class/variable option changes.
     *
     * @return array<string, mixed> Site context data.
     */
    public function get_site_context(): array {
        self::register_cache_invalidation();

        if ( null !== self::$site_context_cache ) {
            return self::$site_context_cache;
        }

        $cached = wp_cache_get( self::cache_key(), self::CACHE_GROUP );
        if ( is_array( $cached ) ) {
            self::$site_context_cache = $cached;
            return $cached;
        }

        // Count posts across all public post types efficiently.
        $page_count = 0;
        $public_types = get_post_types( [ 'public' => true ] );
        foreach ( $public_types as $pt ) {
            $counts = wp_count_posts( $pt );
            if ( isset( $counts->publish ) ) {
                $page_count += (int) $counts->publish;
            }
        }

        $template_counts = wp_count_posts( 'bricks_template' );
        $template_count  = isset( $template_counts->publish ) ? (int) $template_counts->publish : 0;

        $global_classes   = get_option( BricksCore::OPTION_GLOBAL_CLASSES, [] );
        $global_variables = get_option( BricksCore::OPTION_GLOBAL_VARIABLES, [] );

        $context = [
            'name'                  => get_bloginfo( 'name' ),
            'url'                   => home_url(),
            'has_bricks'            => defined( 'BRICKS_VERSION' ),
            'has_woocommerce'       => class_exists( 'WooCommerce' ),
            'page_count'            => $page_count,
            'element_count'         => $this->count_total_elements(),
            'template_count'        => $template_count,
            'global_class_count'    => is_array( $global_classes ) ? count( $global_classes ) : 0,
            'global_variable_count' => is_array( $global_variables ) ? count( $global_variables ) : 0,
        ];

        self::$site_context_cache = $context;
        wp_cache_set( self::cache_key(), $context, self::CACHE_GROUP, self::CACHE_TTL );

        return $context;
    }

    /**
     * Count total Bricks elements across all pages.
     *
     * @return int Total element count.
     */
    private function count_total_elements(): int {
        global $wpdb;

        // Single DB query to sum element counts across all pages with Bricks content.
        // Each meta value is a serialized array; we count top-level entries via LENGTH heuristic.
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                 AND p.post_status = 'publish'",
                BricksCore::META_KEY
            )
        );

        $total = 0;
        foreach ( $results as $row ) {
            $elements = maybe_unserialize( $row->meta_value );
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
    public function get_workflow_guide(): array {
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
                'tools' => [ 'propose_design', 'build_structure', 'populate_content' ],
                'example' => __( 'Design a services page with hero, features, pricing, and CTA sections', 'bricks-mcp' ),
                'flow' => __( 'Call propose_design → review resolved data → build_structure(proposal_id) → populate_content(section_id, content_map) → verify_build', 'bricks-mcp' ),
            ],
            'knowledge_library' => [
                'description' => __( 'Domain-specific guidance for complex subsystems', 'bricks-mcp' ),
                'tools'       => [ 'bricks:get_knowledge' ],
                'domains'     => \BricksMCP\MCP\Handlers\BricksToolHandler::discover_knowledge_domains(),
                'usage'       => __( 'Call bricks:get_knowledge(domain=NAME) for deep reference. Call without domain to list all. Key domains: query-loops (pagination, nested loops), templates (conditions, scoring), global-classes (IDs vs names), forms (18 field types, 7 actions), animations (interactions, parallax).', 'bricks-mcp' ),
            ],
        ];
    }

    /**
     * Get quick-start examples.
     *
     * @return array<int, array> Quick-start examples.
     */
    public function get_quick_start_examples(): array {
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
                'expected'    => __( 'Success response with updated element data', 'bricks-mcp' ),
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
     * @param array<string, mixed> $briefs Pre-loaded briefs option.
     * @return array<int, string> Important notes.
     */
    private function get_important_notes( array $briefs ): array {
        $notes = [];

        if ( ! empty( $briefs['ai_notes'] ) && is_array( $briefs['ai_notes'] ) ) {
            $notes = $briefs['ai_notes'];
        }

        if ( empty( $notes ) ) {
            $notes = [
                __( 'For horizontal rows, use block with _direction: row — NOT div. Div elements ignore _direction.', 'bricks-mcp' ),
                __( 'Background overlays in Bricks use _gradient with applyTo: overlay — NOT _background.overlay.', 'bricks-mcp' ),
            ];
        }

        return $notes;
    }

    /**
     * Get design brief summary.
     *
     * @param array<string, mixed> $briefs Pre-loaded briefs option.
     * @return string Design brief summary.
     */
    public function get_design_brief_summary( array $briefs = [] ): string {
        if ( empty( $briefs ) ) {
            $briefs = get_option( BricksCore::OPTION_BRIEFS, [] );
            if ( ! is_array( $briefs ) ) {
                $briefs = [];
            }
        }

        $design_brief = $briefs['design_brief'] ?? '';

        if ( empty( $design_brief ) ) {
            return __( 'No design brief set. Go to Bricks MCP > Briefs to add visual guidelines.', 'bricks-mcp' );
        }

        return wp_trim_words( $design_brief, self::BRIEF_SUMMARY_WORD_LIMIT, '...' );
    }

    /**
     * Get business brief summary.
     *
     * @param array<string, mixed> $briefs Pre-loaded briefs option. Loaded from DB if empty.
     * @return string Business brief summary.
     */
    public function get_business_brief_summary( array $briefs = [] ): string {
        if ( empty( $briefs ) ) {
            $briefs = get_option( BricksCore::OPTION_BRIEFS, [] );
            if ( ! is_array( $briefs ) ) {
                $briefs = [];
            }
        }

        $business_brief = $briefs['business_brief'] ?? '';

        if ( empty( $business_brief ) ) {
            return __( 'No business brief set. Go to Bricks MCP > Briefs to add business context.', 'bricks-mcp' );
        }

        return wp_trim_words( $business_brief, self::BRIEF_SUMMARY_WORD_LIMIT, '...' );
    }
}
