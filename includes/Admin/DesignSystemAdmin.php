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
        add_action( 'wp_ajax_bricks_mcp_ds_render_panel', [ $this, 'ajax_render_panel' ] );
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

    private function render_panel_typography( array $config ): void {
        $head = $config['typography_headings'];
        $text = $config['typography_text'];
        $hfs  = (float) $config['html_font_size'];
        ?>
        <section class="bwm-ds-panel" data-step="typography">
            <h2 class="bwm-ds-panel-title"><?php esc_html_e( 'Typography', 'bricks-mcp' ); ?></h2>
            <p class="bwm-ds-panel-help">
                <?php esc_html_e( '--text-m is the size of the base text.', 'bricks-mcp' ); ?>
            </p>

            <div class="bwm-ds-radio-group">
                <div class="bwm-ds-field-label"><?php esc_html_e( 'HTML font-size', 'bricks-mcp' ); ?></div>
                <label><input type="radio" name="html_font_size" value="62.5" data-field="html_font_size" <?php checked( $hfs, 62.5 ); ?>> <?php esc_html_e( '62.5% (1rem = 10px)', 'bricks-mcp' ); ?></label>
                <label><input type="radio" name="html_font_size" value="100"  data-field="html_font_size" <?php checked( $hfs, 100.0 ); ?>> <?php esc_html_e( '100% (1rem = 16px)', 'bricks-mcp' ); ?></label>
            </div>

            <h3 class="bwm-ds-subsection-title"><?php esc_html_e( 'Headings (base = h3)', 'bricks-mcp' ); ?></h3>
            <div class="bwm-ds-seed">
                <div class="bwm-ds-field">
                    <label><?php esc_html_e( 'Mobile', 'bricks-mcp' ); ?></label>
                    <input type="number" value="<?php echo esc_attr( $head['base_mobile'] ); ?>" data-field="typography_headings.base_mobile" data-recompute="typography_headings" min="16" max="48" step="1">
                </div>
                <div class="bwm-ds-field">
                    <label><?php esc_html_e( 'Desktop', 'bricks-mcp' ); ?></label>
                    <input type="number" value="<?php echo esc_attr( $head['base_desktop'] ); ?>" data-field="typography_headings.base_desktop" data-recompute="typography_headings" min="16" max="72" step="1">
                </div>
                <div class="bwm-ds-field">
                    <label><?php esc_html_e( 'Scale', 'bricks-mcp' ); ?></label>
                    <input type="number" value="<?php echo esc_attr( $head['scale'] ); ?>" data-field="typography_headings.scale" data-recompute="typography_headings" min="1.05" max="2.0" step="0.05">
                </div>
            </div>

            <div class="bwm-ds-steps">
                <?php foreach ( $head['steps'] as $name => $pair ) : ?>
                    <div class="bwm-ds-step-row">
                        <div class="bwm-ds-step-name">--<?php echo esc_html( $name ); ?></div>
                        <div class="bwm-ds-field">
                            <label><?php esc_html_e( 'Mobile', 'bricks-mcp' ); ?></label>
                            <input type="number" value="<?php echo esc_attr( $pair['mobile'] ); ?>" data-field="typography_headings.steps.<?php echo esc_attr( $name ); ?>.mobile" step="0.5">
                        </div>
                        <div class="bwm-ds-type-preview bwm-ds-type-preview-mob" style="font-size:<?php echo (float) $pair['mobile']; ?>px"><?php esc_html_e( 'Heading', 'bricks-mcp' ); ?></div>
                        <div class="bwm-ds-field">
                            <label><?php esc_html_e( 'Desktop', 'bricks-mcp' ); ?></label>
                            <input type="number" value="<?php echo esc_attr( $pair['desktop'] ); ?>" data-field="typography_headings.steps.<?php echo esc_attr( $name ); ?>.desktop" step="0.5">
                        </div>
                        <div class="bwm-ds-type-preview bwm-ds-type-preview-desk" style="font-size:<?php echo (float) $pair['desktop']; ?>px"><?php esc_html_e( 'Heading', 'bricks-mcp' ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <h3 class="bwm-ds-subsection-title"><?php esc_html_e( 'Text (base = text-m)', 'bricks-mcp' ); ?></h3>
            <div class="bwm-ds-seed">
                <div class="bwm-ds-field">
                    <label><?php esc_html_e( 'Mobile', 'bricks-mcp' ); ?></label>
                    <input type="number" value="<?php echo esc_attr( $text['base_mobile'] ); ?>" data-field="typography_text.base_mobile" data-recompute="typography_text" min="10" max="24" step="1">
                </div>
                <div class="bwm-ds-field">
                    <label><?php esc_html_e( 'Desktop', 'bricks-mcp' ); ?></label>
                    <input type="number" value="<?php echo esc_attr( $text['base_desktop'] ); ?>" data-field="typography_text.base_desktop" data-recompute="typography_text" min="10" max="32" step="1">
                </div>
                <div class="bwm-ds-field">
                    <label><?php esc_html_e( 'Scale', 'bricks-mcp' ); ?></label>
                    <input type="number" value="<?php echo esc_attr( $text['scale'] ); ?>" data-field="typography_text.scale" data-recompute="typography_text" min="1.05" max="2.0" step="0.05">
                </div>
            </div>

            <div class="bwm-ds-steps">
                <?php foreach ( $text['steps'] as $name => $pair ) : ?>
                    <div class="bwm-ds-step-row">
                        <div class="bwm-ds-step-name">--text-<?php echo esc_html( $name ); ?></div>
                        <div class="bwm-ds-field">
                            <label><?php esc_html_e( 'Mobile', 'bricks-mcp' ); ?></label>
                            <input type="number" value="<?php echo esc_attr( $pair['mobile'] ); ?>" data-field="typography_text.steps.<?php echo esc_attr( $name ); ?>.mobile" step="0.5">
                        </div>
                        <div class="bwm-ds-field">
                            <label><?php esc_html_e( 'Desktop', 'bricks-mcp' ); ?></label>
                            <input type="number" value="<?php echo esc_attr( $pair['desktop'] ); ?>" data-field="typography_text.steps.<?php echo esc_attr( $name ); ?>.desktop" step="0.5">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    private function render_panel_colors( array $config ): void {
        $colors = $config['colors'];
        $families = [
            'primary'   => __( 'Primary',   'bricks-mcp' ),
            'secondary' => __( 'Secondary', 'bricks-mcp' ),
            'tertiary'  => __( 'Tertiary',  'bricks-mcp' ),
            'accent'    => __( 'Accent',    'bricks-mcp' ),
            'base'      => __( 'Base',      'bricks-mcp' ),
            'neutral'   => __( 'Neutral',   'bricks-mcp' ),
        ];
        ?>
        <section class="bwm-ds-panel" data-step="colors">
            <h2 class="bwm-ds-panel-title"><?php esc_html_e( 'Colors', 'bricks-mcp' ); ?></h2>
            <p class="bwm-ds-panel-help">
                <?php esc_html_e( 'The --base transparencies are named --base-ultra-dark-trans-N. For other families they are --name-trans-N.', 'bricks-mcp' ); ?>
            </p>

            <?php foreach ( $families as $key => $label ) : ?>
                <?php $this->render_color_family( $key, $label, $colors[ $key ] ?? [] ); ?>
            <?php endforeach; ?>

            <?php $this->render_color_bw( 'white', __( 'White', 'bricks-mcp' ), $colors['white'] ?? [] ); ?>
            <?php $this->render_color_bw( 'black', __( 'Black', 'bricks-mcp' ), $colors['black'] ?? [] ); ?>
        </section>
        <?php
    }

    private function render_color_family( string $key, string $label, array $fam ): void {
        $enabled        = ! empty( $fam['enabled'] );
        $expanded       = ! empty( $fam['expanded'] );
        $transparencies = ! empty( $fam['transparencies'] );
        $shades         = $fam['shades'] ?? [];
        $hover          = $fam['hover'] ?? '';

        $shade_order = $expanded
            ? [ 'base', 'ultra_dark', 'dark', 'semi_dark', 'medium', 'semi_light', 'light', 'ultra_light' ]
            : [ 'base', 'ultra_dark', 'dark', 'light', 'ultra_light' ];
        ?>
        <div class="bwm-ds-color-family" data-family="<?php echo esc_attr( $key ); ?>">
            <div class="bwm-ds-color-family-header">
                <label class="bwm-ds-toggle-label">
                    <input type="checkbox" data-field="colors.<?php echo esc_attr( $key ); ?>.enabled" data-restructure="colors" <?php checked( $enabled ); ?>>
                    <?php echo esc_html( sprintf( __( 'Enable %s', 'bricks-mcp' ), $label ) ); ?>
                </label>
                <label class="bwm-ds-toggle-label">
                    <input type="checkbox" data-field="colors.<?php echo esc_attr( $key ); ?>.transparencies" data-restructure="colors" <?php checked( $transparencies ); ?>>
                    <?php esc_html_e( 'Transparencies', 'bricks-mcp' ); ?>
                </label>
                <label class="bwm-ds-toggle-label">
                    <input type="checkbox" data-field="colors.<?php echo esc_attr( $key ); ?>.expanded" data-restructure="colors" <?php checked( $expanded ); ?>>
                    <?php esc_html_e( 'Expand Color Palette', 'bricks-mcp' ); ?>
                </label>
            </div>

            <div class="bwm-ds-color-shades">
                <?php foreach ( $shade_order as $shade ) : ?>
                    <?php $hex = $shades[ $shade ] ?? '#000000'; ?>
                    <div class="bwm-ds-color-shade">
                        <input type="color" value="<?php echo esc_attr( $hex ); ?>" data-field="colors.<?php echo esc_attr( $key ); ?>.shades.<?php echo esc_attr( $shade ); ?>" <?php echo ( $shade === 'base' ) ? 'data-recompute="colors.' . esc_attr( $key ) . '"' : ''; ?>>
                        <input type="text" class="bwm-ds-hex" value="<?php echo esc_attr( $hex ); ?>" data-field="colors.<?php echo esc_attr( $key ); ?>.shades.<?php echo esc_attr( $shade ); ?>" maxlength="7">
                        <label>--<?php echo esc_html( $key . ( $shade === 'base' ? '' : '-' . str_replace( '_', '-', $shade ) ) ); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="bwm-ds-color-hover">
                <input type="color" value="<?php echo esc_attr( $hover ); ?>" data-field="colors.<?php echo esc_attr( $key ); ?>.hover">
                <input type="text" class="bwm-ds-hex" value="<?php echo esc_attr( $hover ); ?>" data-field="colors.<?php echo esc_attr( $key ); ?>.hover" maxlength="7">
                <label>--<?php echo esc_html( $key ); ?>-hover</label>
            </div>

            <?php if ( $transparencies ) : ?>
                <div class="bwm-ds-trans-strip" data-family="<?php echo esc_attr( $key ); ?>"></div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_color_bw( string $key, string $label, array $fam ): void {
        $hex            = $fam['hex'] ?? ( $key === 'white' ? '#ffffff' : '#000000' );
        $transparencies = ! empty( $fam['transparencies'] );
        ?>
        <div class="bwm-ds-color-family" data-family="<?php echo esc_attr( $key ); ?>">
            <div class="bwm-ds-color-family-header">
                <label class="bwm-ds-toggle-label">
                    <input type="checkbox" data-field="colors.<?php echo esc_attr( $key ); ?>.transparencies" data-restructure="colors" <?php checked( $transparencies ); ?>>
                    <?php echo esc_html( sprintf( __( '%s Transparencies', 'bricks-mcp' ), $label ) ); ?>
                </label>
            </div>
            <div class="bwm-ds-color-shades">
                <div class="bwm-ds-color-shade">
                    <input type="color" value="<?php echo esc_attr( $hex ); ?>" data-field="colors.<?php echo esc_attr( $key ); ?>.hex">
                    <input type="text" class="bwm-ds-hex" value="<?php echo esc_attr( $hex ); ?>" data-field="colors.<?php echo esc_attr( $key ); ?>.hex" maxlength="7">
                    <label>--<?php echo esc_html( $key ); ?></label>
                </div>
            </div>
            <?php if ( $transparencies ) : ?>
                <div class="bwm-ds-trans-strip" data-family="<?php echo esc_attr( $key ); ?>"></div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_panel_gaps( array $config ): void {
        $g = $config['gaps'];
        $map = [
            'grid_gap'        => __( '--grid-gap',        'bricks-mcp' ),
            'grid_gap_s'      => __( '--grid-gap-s',      'bricks-mcp' ),
            'card_gap'        => __( '--card-gap',        'bricks-mcp' ),
            'content_gap'     => __( '--content-gap',     'bricks-mcp' ),
            'container_gap'   => __( '--container-gap',   'bricks-mcp' ),
            'padding_section' => __( '--padding-section', 'bricks-mcp' ),
            'offset'          => __( '--offset',          'bricks-mcp' ),
        ];
        ?>
        <section class="bwm-ds-panel" data-step="gaps">
            <h2 class="bwm-ds-panel-title"><?php esc_html_e( 'Gaps / Padding', 'bricks-mcp' ); ?></h2>
            <p class="bwm-ds-panel-help">
                <?php esc_html_e( '--offset is the height of your site header, used for fixed positioning and scroll anchors.', 'bricks-mcp' ); ?>
            </p>
            <div class="bwm-ds-text-fields">
                <?php foreach ( $map as $key => $label ) : ?>
                    <div class="bwm-ds-field">
                        <label><?php echo esc_html( $label ); ?></label>
                        <input type="text" value="<?php echo esc_attr( $g[ $key ] ?? '' ); ?>" data-field="gaps.<?php echo esc_attr( $key ); ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    private function render_panel_radius( array $config ): void {
        $r      = $config['radius'];
        $values = $r['values'];
        $ts     = $config['text_styles'];
        $rows = [
            'radius'         => '--radius',
            'radius_inside'  => '--radius-inside',
            'radius_outside' => '--radius-outside',
            'radius_btn'     => '--radius-btn',
            'radius_pill'    => '--radius-pill',
            'radius_circle'  => '--radius-circle',
            'radius_s'       => '--radius-s',
            'radius_m'       => '--radius-m',
            'radius_l'       => '--radius-l',
            'radius_xl'      => '--radius-xl',
        ];
        ?>
        <section class="bwm-ds-panel" data-step="radius">
            <h2 class="bwm-ds-panel-title"><?php esc_html_e( 'Radius', 'bricks-mcp' ); ?></h2>
            <p class="bwm-ds-panel-help">
                <?php esc_html_e( 'Set your border radius and border color.', 'bricks-mcp' ); ?>
            </p>

            <div class="bwm-ds-seed">
                <div class="bwm-ds-field">
                    <label><?php esc_html_e( 'Base Radius (px)', 'bricks-mcp' ); ?></label>
                    <input type="number" value="<?php echo esc_attr( $r['base'] ); ?>" data-field="radius.base" data-recompute="radius" min="0" max="50" step="1">
                </div>
            </div>

            <div class="bwm-ds-text-fields">
                <?php foreach ( $rows as $key => $label ) : ?>
                    <div class="bwm-ds-field">
                        <label><?php echo esc_html( $label ); ?></label>
                        <input type="text" value="<?php echo esc_attr( $values[ $key ] ?? '' ); ?>" data-field="radius.values.<?php echo esc_attr( $key ); ?>">
                    </div>
                <?php endforeach; ?>

                <div class="bwm-ds-field">
                    <label>--border-color</label>
                    <input type="text" value="<?php echo esc_attr( $ts['border_color'] ?? '' ); ?>" data-field="text_styles.border_color">
                </div>
                <div class="bwm-ds-field">
                    <label>--border-color-dark</label>
                    <input type="text" value="<?php echo esc_attr( $ts['border_color_dark'] ?? '' ); ?>" data-field="text_styles.border_color_dark">
                </div>
            </div>
        </section>
        <?php
    }

    private function render_panel_sizes( array $config ): void {
        $s = $config['sizes'];
        $rows = [
            'container_width'    => [ __( 'Container Width (px)',     'bricks-mcp' ), 'number' ],
            'container_min'      => [ __( 'Container Min Width (px)', 'bricks-mcp' ), 'number' ],
            'max_width'          => [ __( 'Max Width (px)',           'bricks-mcp' ), 'number' ],
            'max_width_m'        => [ __( 'Max Width M (px)',         'bricks-mcp' ), 'number' ],
            'max_width_s'        => [ __( 'Max Width S (px)',         'bricks-mcp' ), 'number' ],
            'min_height'         => [ __( 'Min Height (px)',          'bricks-mcp' ), 'number' ],
            'min_height_section' => [ __( 'Min Height Section (px)',  'bricks-mcp' ), 'number' ],
            'logo_width_mobile'  => [ __( 'Logo Width Mobile (px)',   'bricks-mcp' ), 'number' ],
            'logo_width_desktop' => [ __( 'Logo Width Desktop (px)',  'bricks-mcp' ), 'number' ],
        ];
        ?>
        <section class="bwm-ds-panel" data-step="sizes">
            <h2 class="bwm-ds-panel-title"><?php esc_html_e( 'Sizes', 'bricks-mcp' ); ?></h2>
            <p class="bwm-ds-panel-help">
                <?php esc_html_e( '--container-width and --container-min-width are used for clamp calculations.', 'bricks-mcp' ); ?>
            </p>
            <div class="bwm-ds-text-fields">
                <?php foreach ( $rows as $key => [ $label, $type ] ) : ?>
                    <div class="bwm-ds-field">
                        <label><?php echo esc_html( $label ); ?></label>
                        <input type="<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $s[ $key ] ?? '' ); ?>" data-field="sizes.<?php echo esc_attr( $key ); ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    private function render_panel_text_styles( array $config ): void {
        $ts = $config['text_styles'];
        $rows = [
            'text_color'          => [ '--text-color',          'text' ],
            'heading_color'       => [ '--heading-color',       'text' ],
            'text_font_weight'    => [ '--text-font-weight',    'number' ],
            'heading_font_weight' => [ '--heading-font-weight', 'number' ],
            'text_line_height'    => [ '--text-line-height',    'text' ],
            'heading_line_height' => [ '--heading-line-height', 'text' ],
        ];
        ?>
        <section class="bwm-ds-panel" data-step="text-styles">
            <h2 class="bwm-ds-panel-title"><?php esc_html_e( 'Text Styles', 'bricks-mcp' ); ?></h2>
            <p class="bwm-ds-panel-help">
                <?php esc_html_e( 'Colors, weights, and line-heights for text and headings. Accept CSS variables.', 'bricks-mcp' ); ?>
            </p>
            <div class="bwm-ds-text-fields">
                <?php foreach ( $rows as $key => [ $label, $type ] ) : ?>
                    <div class="bwm-ds-field">
                        <label><?php echo esc_html( $label ); ?></label>
                        <input type="<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $ts[ $key ] ?? '' ); ?>" data-field="text_styles.<?php echo esc_attr( $key ); ?>">
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

    /**
     * Render a single panel HTML string (used by JS to refresh after structural toggles).
     */
    public function ajax_render_panel(): void {
        check_ajax_referer( 'bricks_mcp_design_system', 'nonce' );

        if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $panel  = isset( $_POST['panel'] ) ? sanitize_key( wp_unslash( $_POST['panel'] ) ) : '';
        $config = json_decode( wp_unslash( $_POST['config'] ?? '{}' ), true );
        if ( ! is_array( $config ) ) {
            wp_send_json_error( 'Invalid config' );
        }
        $config = ConfigMigrator::migrate( $config );

        // Map panel slug to render method.
        $methods = [
            'spacing'     => 'render_panel_spacing',
            'typography'  => 'render_panel_typography',
            'colors'      => 'render_panel_colors',
            'gaps'        => 'render_panel_gaps',
            'radius'      => 'render_panel_radius',
            'sizes'       => 'render_panel_sizes',
            'text-styles' => 'render_panel_text_styles',
        ];

        if ( ! isset( $methods[ $panel ] ) || ! method_exists( $this, $methods[ $panel ] ) ) {
            wp_send_json_error( 'Unknown panel: ' . $panel );
        }

        ob_start();
        $this->{$methods[ $panel ]}( $config );
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }
}
