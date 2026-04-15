<?php
declare(strict_types=1);

namespace BricksMCP\Admin;

use BricksMCP\MCP\Services\BricksCore;
use BricksMCP\MCP\Services\DesignSystemGenerator;
use BricksMCP\MCP\Services\DesignSystem\ConfigMigrator;

class DesignSystemAdmin {

    private const CONFIG_OPTION = 'bricks_mcp_design_system_config';

    /**
     * Register AJAX handlers.
     */
    public function init(): void {
        add_action( 'wp_ajax_bricks_mcp_ds_save_config', [ $this, 'ajax_save_config' ] );
        add_action( 'wp_ajax_bricks_mcp_ds_apply', [ $this, 'ajax_apply' ] );
        add_action( 'wp_ajax_bricks_mcp_ds_reset', [ $this, 'ajax_reset' ] );
    }

    /**
     * Get saved config or defaults — always returns v2 shape via migrator.
     */
    public function get_config(): array {
        $saved = get_option( self::CONFIG_OPTION, null );
        $raw   = is_array( $saved ) ? $saved : [];
        return ConfigMigrator::migrate( $raw );
    }

    /**
     * Enqueue tab-specific assets.
     */
    public function enqueue_assets(): void {
        wp_enqueue_style(
            'bricks-mcp-admin-design-system',
            BRICKS_MCP_PLUGIN_URL . 'assets/css/admin-design-system.css',
            [],
            BRICKS_MCP_VERSION
        );

        wp_enqueue_script(
            'bricks-mcp-admin-design-system',
            BRICKS_MCP_PLUGIN_URL . 'assets/js/admin-design-system.js',
            [],
            BRICKS_MCP_VERSION,
            true
        );

        wp_localize_script( 'bricks-mcp-admin-design-system', 'bricksMcpDesignSystem', [
            'nonce'         => wp_create_nonce( 'bricks_mcp_design_system' ),
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'config'        => $this->get_config(),
            'defaultConfig' => DesignSystemGenerator::get_default_config(),
            'lastApplied'   => get_option( 'bricks_mcp_ds_last_applied', '' ),
        ] );
    }

    /**
     * Render the Design System tab content.
     */
    public function render(): void {
        $config = $this->get_config();
        ?>
        <div class="bwm-design-system-wrap">
            <div class="bwm-ds-layout">
                <nav class="bwm-ds-stepper" aria-label="<?php esc_attr_e( 'Design System sections', 'bricks-mcp' ); ?>">
                    <button type="button" class="bwm-ds-step bwm-ds-step-active" data-step="spacing">
                        <?php esc_html_e( 'Spacing', 'bricks-mcp' ); ?>
                    </button>
                    <button type="button" class="bwm-ds-step" data-step="typography">
                        <?php esc_html_e( 'Typography', 'bricks-mcp' ); ?>
                    </button>
                    <button type="button" class="bwm-ds-step" data-step="colors">
                        <?php esc_html_e( 'Colors', 'bricks-mcp' ); ?>
                    </button>
                    <button type="button" class="bwm-ds-step" data-step="gaps">
                        <?php esc_html_e( 'Gaps/Padding', 'bricks-mcp' ); ?>
                    </button>
                    <button type="button" class="bwm-ds-step" data-step="radius">
                        <?php esc_html_e( 'Radius', 'bricks-mcp' ); ?>
                    </button>
                    <button type="button" class="bwm-ds-step" data-step="sizes">
                        <?php esc_html_e( 'Sizes', 'bricks-mcp' ); ?>
                    </button>
                    <button type="button" class="bwm-ds-step" data-step="text-styles">
                        <?php esc_html_e( 'Text Styles', 'bricks-mcp' ); ?>
                    </button>

                    <div class="bwm-ds-actions">
                        <button type="button" class="button button-primary button-hero" id="bwm-ds-apply">
                            <?php esc_html_e( 'Apply to Site', 'bricks-mcp' ); ?>
                        </button>
                        <a href="#" class="bwm-ds-reset" id="bwm-ds-reset">
                            <?php esc_html_e( 'Reset to Defaults', 'bricks-mcp' ); ?>
                        </a>
                        <div class="bwm-ds-status" id="bwm-ds-status"></div>
                    </div>
                </nav>

                <div class="bwm-ds-panes">
                    <?php $this->render_panel_spacing( $config ); ?>
                    <?php $this->render_panel_typography( $config ); ?>
                    <?php $this->render_panel_colors( $config ); ?>
                    <?php $this->render_panel_gaps( $config ); ?>
                    <?php $this->render_panel_radius( $config ); ?>
                    <?php $this->render_panel_sizes( $config ); ?>
                    <?php $this->render_panel_text_styles( $config ); ?>
                </div>
            </div>

            <details class="bwm-ds-preview-wrap" open>
                <summary><?php esc_html_e( 'Live Preview', 'bricks-mcp' ); ?></summary>
                <div id="bwm-ds-live-preview"></div>
            </details>
        </div>
        <?php
    }

    private function render_panel_spacing( array $config ): void {
        $s     = $config['spacing'];
        $steps = $s['steps'];
        ?>
        <section class="bwm-ds-panel bwm-ds-panel-active" data-step="spacing">
            <h2 class="bwm-ds-panel-title"><?php esc_html_e( 'Spacing', 'bricks-mcp' ); ?></h2>
            <p class="bwm-ds-panel-help">
                <?php esc_html_e( '--space-m and --space-section are also used for section padding.', 'bricks-mcp' ); ?>
            </p>

            <div class="bwm-ds-seed">
                <div class="bwm-ds-seed-label"><?php esc_html_e( 'Base space', 'bricks-mcp' ); ?></div>
                <div class="bwm-ds-field">
                    <label><?php esc_html_e( 'Mobile', 'bricks-mcp' ); ?></label>
                    <input type="number" value="<?php echo esc_attr( $s['base_mobile'] ); ?>" data-field="spacing.base_mobile" data-recompute="spacing" min="8" max="48" step="1">
                </div>
                <div class="bwm-ds-field">
                    <label><?php esc_html_e( 'Desktop', 'bricks-mcp' ); ?></label>
                    <input type="number" value="<?php echo esc_attr( $s['base_desktop'] ); ?>" data-field="spacing.base_desktop" data-recompute="spacing" min="8" max="64" step="1">
                </div>
                <div class="bwm-ds-field">
                    <label><?php esc_html_e( 'Scale', 'bricks-mcp' ); ?></label>
                    <input type="number" value="<?php echo esc_attr( $s['scale'] ); ?>" data-field="spacing.scale" data-recompute="spacing" min="1.1" max="3.0" step="0.05">
                </div>
            </div>

            <div class="bwm-ds-steps">
                <?php foreach ( $steps as $name => $pair ) : ?>
                    <div class="bwm-ds-step-row">
                        <div class="bwm-ds-step-name">--space-<?php echo esc_html( $name ); ?></div>
                        <div class="bwm-ds-field">
                            <label><?php esc_html_e( 'Mobile', 'bricks-mcp' ); ?></label>
                            <input type="number" value="<?php echo esc_attr( $pair['mobile'] ); ?>" data-field="spacing.steps.<?php echo esc_attr( $name ); ?>.mobile" step="0.5">
                        </div>
                        <div class="bwm-ds-field">
                            <label><?php esc_html_e( 'Desktop', 'bricks-mcp' ); ?></label>
                            <input type="number" value="<?php echo esc_attr( $pair['desktop'] ); ?>" data-field="spacing.steps.<?php echo esc_attr( $name ); ?>.desktop" step="0.5">
                        </div>
                        <div class="bwm-ds-swatch" data-swatch-size="<?php echo esc_attr( $pair['desktop'] ); ?>"></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    // --- AJAX Handlers ---

    /**
     * Auto-fill structured brief fields from the design system config.
     *
     * Only fills fields that are currently empty — never overwrites user customizations.
     */
    private function auto_fill_structured_brief( array $config ): void {
        $existing = get_option( 'bricks_mcp_structured_brief', [] );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }

        $auto = [
            'dark_bg_color'       => 'var(--base-ultra-dark)',
            'dark_text_color'     => 'var(--white)',
            'dark_subtitle_color' => 'var(--white-trans-70)',
            'light_alt_bg_color'  => 'var(--base-ultra-light)',
            'card_radius'         => 'var(--radius)',
            'card_border_color'   => 'var(--border-color)',
            'card_padding'        => 'var(--space-l)',
            'grid_gap'            => 'var(--grid-gap)',
            'content_gap'         => 'var(--content-gap)',
            'container_gap'       => 'var(--container-gap)',
        ];

        foreach ( $auto as $key => $value ) {
            if ( empty( $existing[ $key ] ) ) {
                $existing[ $key ] = $value;
            }
        }

        update_option( 'bricks_mcp_structured_brief', $existing );
    }

    /**
     * Save config via debounced AJAX.
     */
    public function ajax_save_config(): void {
        check_ajax_referer( 'bricks_mcp_design_system', 'nonce' );

        if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $config = json_decode( wp_unslash( $_POST['config'] ?? '{}' ), true );
        if ( ! is_array( $config ) ) {
            wp_send_json_error( 'Invalid config' );
        }

        update_option( self::CONFIG_OPTION, $config );
        wp_send_json_success( [ 'saved' => true ] );
    }

    /**
     * Apply design system to site.
     */
    public function ajax_apply(): void {
        check_ajax_referer( 'bricks_mcp_design_system', 'nonce' );

        if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $config = json_decode( wp_unslash( $_POST['config'] ?? '{}' ), true );
        if ( ! is_array( $config ) ) {
            wp_send_json_error( 'Invalid config' );
        }

        // Save config first.
        update_option( self::CONFIG_OPTION, $config );

        $generator = new DesignSystemGenerator();
        $summary   = $generator->apply( $config );

        // Auto-fill structured brief from design system config.
        $this->auto_fill_structured_brief( $config );

        // Store last-applied timestamp.
        $timestamp = current_time( 'mysql' );
        update_option( 'bricks_mcp_ds_last_applied', $timestamp );

        $summary['last_applied'] = $timestamp;

        wp_send_json_success( $summary );
    }

    /**
     * Reset config to defaults.
     */
    public function ajax_reset(): void {
        check_ajax_referer( 'bricks_mcp_design_system', 'nonce' );

        if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $default = DesignSystemGenerator::get_default_config();
        update_option( self::CONFIG_OPTION, $default );

        wp_send_json_success( [ 'config' => $default ] );
    }
}
