<?php
declare(strict_types=1);

namespace BricksMCP\Admin;

use BricksMCP\MCP\Services\DesignSystemGenerator;

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
     * Get saved config or defaults.
     */
    public function get_config(): array {
        $saved = get_option( self::CONFIG_OPTION, null );
        if ( null === $saved || ! is_array( $saved ) ) {
            return DesignSystemGenerator::get_default_config();
        }
        return wp_parse_args( $saved, DesignSystemGenerator::get_default_config() );
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
            <?php $this->render_section_colors( $config ); ?>
            <?php $this->render_section_spacing( $config ); ?>
            <?php $this->render_section_typography( $config ); ?>
            <?php $this->render_section_radius( $config ); ?>
            <?php $this->render_section_sizes( $config ); ?>

            <div class="bwm-ds-actions">
                <button type="button" class="button button-primary button-hero" id="bwm-ds-apply">
                    <?php esc_html_e( 'Apply to Site', 'bricks-mcp' ); ?>
                </button>
                <a href="#" class="bwm-ds-reset" id="bwm-ds-reset">
                    <?php esc_html_e( 'Reset to Defaults', 'bricks-mcp' ); ?>
                </a>
                <div class="bwm-ds-status" id="bwm-ds-status"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Colors accordion section.
     */
    private function render_section_colors( array $config ): void {
        $colors = $config['colors'] ?? [];
        ?>
        <div class="bwm-ds-section" data-section="colors">
            <div class="bwm-ds-section-header bwm-ds-section-open">
                <span class="bwm-ds-toggle">&#9660;</span>
                <?php esc_html_e( 'Colors', 'bricks-mcp' ); ?>
                <span class="bwm-ds-section-info"><?php esc_html_e( '4 base colors', 'bricks-mcp' ); ?></span>
            </div>
            <div class="bwm-ds-section-body" style="display:block;">
                <div class="bwm-ds-inputs">
                    <div class="bwm-ds-color-row">
                        <input type="color" id="bwm-ds-primary" value="<?php echo esc_attr( $colors['primary'] ?? '#3b82f6' ); ?>" data-field="colors.primary">
                        <input type="text" class="bwm-ds-hex" value="<?php echo esc_attr( $colors['primary'] ?? '#3b82f6' ); ?>" data-field="colors.primary" maxlength="7">
                        <label><?php esc_html_e( 'Primary', 'bricks-mcp' ); ?></label>
                    </div>
                    <div class="bwm-ds-color-row">
                        <input type="color" id="bwm-ds-secondary" value="<?php echo esc_attr( $colors['secondary']['hex'] ?? '#f59e0b' ); ?>" data-field="colors.secondary.hex">
                        <input type="text" class="bwm-ds-hex" value="<?php echo esc_attr( $colors['secondary']['hex'] ?? '#f59e0b' ); ?>" data-field="colors.secondary.hex" maxlength="7">
                        <label><?php esc_html_e( 'Secondary', 'bricks-mcp' ); ?></label>
                        <label class="bwm-ds-toggle-label">
                            <input type="checkbox" data-field="colors.secondary.enabled" <?php checked( $colors['secondary']['enabled'] ?? true ); ?>>
                            <?php esc_html_e( 'Enabled', 'bricks-mcp' ); ?>
                        </label>
                    </div>
                    <div class="bwm-ds-color-row">
                        <input type="color" id="bwm-ds-accent" value="<?php echo esc_attr( $colors['accent']['hex'] ?? '#10b981' ); ?>" data-field="colors.accent.hex">
                        <input type="text" class="bwm-ds-hex" value="<?php echo esc_attr( $colors['accent']['hex'] ?? '#10b981' ); ?>" data-field="colors.accent.hex" maxlength="7">
                        <label><?php esc_html_e( 'Accent', 'bricks-mcp' ); ?></label>
                        <label class="bwm-ds-toggle-label">
                            <input type="checkbox" data-field="colors.accent.enabled" <?php checked( $colors['accent']['enabled'] ?? true ); ?>>
                            <?php esc_html_e( 'Enabled', 'bricks-mcp' ); ?>
                        </label>
                    </div>
                    <div class="bwm-ds-color-row">
                        <input type="color" id="bwm-ds-base" value="<?php echo esc_attr( $colors['base'] ?? '#374151' ); ?>" data-field="colors.base">
                        <input type="text" class="bwm-ds-hex" value="<?php echo esc_attr( $colors['base'] ?? '#374151' ); ?>" data-field="colors.base" maxlength="7">
                        <label><?php esc_html_e( 'Base', 'bricks-mcp' ); ?></label>
                    </div>
                </div>
                <hr class="bwm-ds-divider">
                <div class="bwm-ds-preview" id="bwm-ds-color-preview">
                    <div class="bwm-ds-preview-label"><?php esc_html_e( 'Generated Shades', 'bricks-mcp' ); ?></div>
                    <!-- JS populates shade strips here -->
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Spacing accordion section.
     */
    private function render_section_spacing( array $config ): void {
        $s = $config['spacing'] ?? [];
        ?>
        <div class="bwm-ds-section" data-section="spacing">
            <div class="bwm-ds-section-header">
                <span class="bwm-ds-toggle">&#9654;</span>
                <?php esc_html_e( 'Spacing', 'bricks-mcp' ); ?>
            </div>
            <div class="bwm-ds-section-body">
                <div class="bwm-ds-inputs bwm-ds-inputs-row">
                    <div class="bwm-ds-field">
                        <label><?php esc_html_e( 'Base Mobile (px)', 'bricks-mcp' ); ?></label>
                        <input type="number" value="<?php echo esc_attr( $s['base_mobile'] ?? 20 ); ?>" data-field="spacing.base_mobile" min="8" max="48" step="1">
                    </div>
                    <div class="bwm-ds-field">
                        <label><?php esc_html_e( 'Base Desktop (px)', 'bricks-mcp' ); ?></label>
                        <input type="number" value="<?php echo esc_attr( $s['base_desktop'] ?? 24 ); ?>" data-field="spacing.base_desktop" min="8" max="64" step="1">
                    </div>
                    <div class="bwm-ds-field">
                        <label><?php esc_html_e( 'Scale Ratio', 'bricks-mcp' ); ?></label>
                        <input type="number" value="<?php echo esc_attr( $s['scale'] ?? 1.5 ); ?>" data-field="spacing.scale" min="1.1" max="3.0" step="0.05">
                    </div>
                </div>
                <hr class="bwm-ds-divider">
                <div class="bwm-ds-preview" id="bwm-ds-spacing-preview">
                    <div class="bwm-ds-preview-label"><?php esc_html_e( 'Computed Values', 'bricks-mcp' ); ?></div>
                    <!-- JS populates table here -->
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Typography accordion section.
     */
    private function render_section_typography( array $config ): void {
        $text = $config['typography_text'] ?? [];
        $head = $config['typography_headings'] ?? [];
        ?>
        <div class="bwm-ds-section" data-section="typography">
            <div class="bwm-ds-section-header">
                <span class="bwm-ds-toggle">&#9654;</span>
                <?php esc_html_e( 'Typography', 'bricks-mcp' ); ?>
            </div>
            <div class="bwm-ds-section-body">
                <h4 class="bwm-ds-subsection-title"><?php esc_html_e( 'Text', 'bricks-mcp' ); ?></h4>
                <div class="bwm-ds-inputs bwm-ds-inputs-row">
                    <div class="bwm-ds-field">
                        <label><?php esc_html_e( 'Base Mobile (px)', 'bricks-mcp' ); ?></label>
                        <input type="number" value="<?php echo esc_attr( $text['base_mobile'] ?? 16 ); ?>" data-field="typography_text.base_mobile" min="10" max="24" step="1">
                    </div>
                    <div class="bwm-ds-field">
                        <label><?php esc_html_e( 'Base Desktop (px)', 'bricks-mcp' ); ?></label>
                        <input type="number" value="<?php echo esc_attr( $text['base_desktop'] ?? 18 ); ?>" data-field="typography_text.base_desktop" min="10" max="32" step="1">
                    </div>
                    <div class="bwm-ds-field">
                        <label><?php esc_html_e( 'Scale Ratio', 'bricks-mcp' ); ?></label>
                        <input type="number" value="<?php echo esc_attr( $text['scale'] ?? 1.25 ); ?>" data-field="typography_text.scale" min="1.05" max="2.0" step="0.05">
                    </div>
                </div>
                <div class="bwm-ds-preview" id="bwm-ds-text-preview"></div>

                <h4 class="bwm-ds-subsection-title"><?php esc_html_e( 'Headings', 'bricks-mcp' ); ?></h4>
                <div class="bwm-ds-inputs bwm-ds-inputs-row">
                    <div class="bwm-ds-field">
                        <label><?php esc_html_e( 'Base Mobile (px)', 'bricks-mcp' ); ?></label>
                        <input type="number" value="<?php echo esc_attr( $head['base_mobile'] ?? 28 ); ?>" data-field="typography_headings.base_mobile" min="16" max="48" step="1">
                    </div>
                    <div class="bwm-ds-field">
                        <label><?php esc_html_e( 'Base Desktop (px)', 'bricks-mcp' ); ?></label>
                        <input type="number" value="<?php echo esc_attr( $head['base_desktop'] ?? 35 ); ?>" data-field="typography_headings.base_desktop" min="16" max="72" step="1">
                    </div>
                    <div class="bwm-ds-field">
                        <label><?php esc_html_e( 'Scale Ratio', 'bricks-mcp' ); ?></label>
                        <input type="number" value="<?php echo esc_attr( $head['scale'] ?? 1.25 ); ?>" data-field="typography_headings.scale" min="1.05" max="2.0" step="0.05">
                    </div>
                </div>
                <div class="bwm-ds-preview" id="bwm-ds-headings-preview"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Radius accordion section.
     */
    private function render_section_radius( array $config ): void {
        ?>
        <div class="bwm-ds-section" data-section="radius">
            <div class="bwm-ds-section-header">
                <span class="bwm-ds-toggle">&#9654;</span>
                <?php esc_html_e( 'Radius', 'bricks-mcp' ); ?>
            </div>
            <div class="bwm-ds-section-body">
                <div class="bwm-ds-inputs">
                    <div class="bwm-ds-field">
                        <label><?php esc_html_e( 'Base Radius (px)', 'bricks-mcp' ); ?></label>
                        <input type="number" value="<?php echo esc_attr( $config['radius'] ?? 8 ); ?>" data-field="radius" min="0" max="50" step="1">
                    </div>
                </div>
                <hr class="bwm-ds-divider">
                <div class="bwm-ds-preview" id="bwm-ds-radius-preview">
                    <div class="bwm-ds-preview-label"><?php esc_html_e( 'Computed Variants', 'bricks-mcp' ); ?></div>
                    <!-- JS populates radius preview boxes here -->
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Sizes accordion section.
     */
    private function render_section_sizes( array $config ): void {
        ?>
        <div class="bwm-ds-section" data-section="sizes">
            <div class="bwm-ds-section-header">
                <span class="bwm-ds-toggle">&#9654;</span>
                <?php esc_html_e( 'Sizes', 'bricks-mcp' ); ?>
            </div>
            <div class="bwm-ds-section-body">
                <div class="bwm-ds-inputs bwm-ds-inputs-row">
                    <div class="bwm-ds-field">
                        <label><?php esc_html_e( 'Container Width (px)', 'bricks-mcp' ); ?></label>
                        <input type="number" value="<?php echo esc_attr( $config['container_width'] ?? 1280 ); ?>" data-field="container_width" min="960" max="1920" step="10">
                    </div>
                    <div class="bwm-ds-field">
                        <label><?php esc_html_e( 'Container Min Width (px)', 'bricks-mcp' ); ?></label>
                        <input type="number" value="<?php echo esc_attr( $config['container_min'] ?? 380 ); ?>" data-field="container_min" min="320" max="480" step="10">
                    </div>
                </div>
                <hr class="bwm-ds-divider">
                <div class="bwm-ds-preview" id="bwm-ds-sizes-preview">
                    <div class="bwm-ds-preview-label"><?php esc_html_e( 'Computed Sizes', 'bricks-mcp' ); ?></div>
                    <!-- JS populates table here -->
                </div>
            </div>
        </div>
        <?php
    }

    // --- AJAX Handlers ---

    /**
     * Save config via debounced AJAX.
     */
    public function ajax_save_config(): void {
        check_ajax_referer( 'bricks_mcp_design_system', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
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

        if ( ! current_user_can( 'manage_options' ) ) {
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

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $default = DesignSystemGenerator::get_default_config();
        update_option( self::CONFIG_OPTION, $default );

        wp_send_json_success( [ 'config' => $default ] );
    }
}
