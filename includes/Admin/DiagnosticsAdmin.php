<?php
declare(strict_types=1);

namespace BricksMCP\Admin;

use BricksMCP\MCP\Services\BricksCore;

/**
 * Diagnostics tab admin — extracted from Settings.
 *
 * Handles the System Health diagnostic panel: AJAX handler, asset
 * enqueue, and HTML render.  Follows the same extraction pattern as
 * DesignSystemAdmin.
 */
class DiagnosticsAdmin {

    /**
     * Register AJAX handlers.
     */
    public function init(): void {
        add_action( 'wp_ajax_bricks_mcp_run_diagnostics', [ $this, 'ajax_run_diagnostics' ] );
    }

    /**
     * Enqueue tab-specific assets.
     *
     * The diagnostics JS reads ajaxUrl / nonce from the shared
     * bricksMcpUpdates global (enqueued by Settings), so we keep that
     * dependency.  Only the UI-string bundle lives here.
     */
    public function enqueue_assets(): void {
        wp_enqueue_script(
            'bricks-mcp-admin-diagnostics',
            BRICKS_MCP_PLUGIN_URL . 'assets/js/admin-diagnostics.js',
            [ 'bricks-mcp-admin-updates' ],
            BRICKS_MCP_VERSION,
            true
        );

        wp_localize_script(
            'bricks-mcp-admin-diagnostics',
            'bricksMcpDiagnostics',
            [
                'errorText'         => __( 'An error occurred.', 'bricks-mcp' ),
                'howToFixText'      => __( 'How to fix:', 'bricks-mcp' ),
                'requestFailedText' => __( 'Request failed. Please try again.', 'bricks-mcp' ),
                'copiedText'        => __( 'Copied!', 'bricks-mcp' ),
                'copyResultsText'   => __( 'Copy Results', 'bricks-mcp' ),
            ]
        );
    }

    /**
     * Render the System Health diagnostic panel.
     *
     * Provides a Run Diagnostics button that executes all checks via
     * AJAX and renders a colored checklist, plus a Copy Results button
     * for support-ticket use.
     */
    public function render(): void {
        ?>
        <div class="bricks-mcp-diagnostics" id="bricks-mcp-diagnostics">
            <h3><?php esc_html_e( 'System Health', 'bricks-mcp' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Check if your site is ready for AI connections.', 'bricks-mcp' ); ?></p>
            <div class="bricks-mcp-diagnostics-actions">
                <button type="button" class="button button-primary" id="bricks-mcp-run-diagnostics">
                    <?php esc_html_e( 'Run Diagnostics', 'bricks-mcp' ); ?>
                </button>
                <button type="button" class="button" id="bricks-mcp-copy-results" style="display:none;">
                    <?php esc_html_e( 'Copy Results', 'bricks-mcp' ); ?>
                </button>
                <span class="spinner" id="bricks-mcp-diagnostics-spinner"></span>
            </div>
            <div id="bricks-mcp-diagnostics-results"></div>
        </div>

        <?php
    }

    /**
     * AJAX handler: Run all diagnostic checks and return structured results.
     */
    public function ajax_run_diagnostics(): void {
        check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );

        if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
        }

        $runner = new DiagnosticRunner();
        $runner->register_defaults();
        $results = $runner->run_all();

        wp_send_json_success( $results );
    }
}
