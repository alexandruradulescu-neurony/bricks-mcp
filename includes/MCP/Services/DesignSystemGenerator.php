<?php
declare(strict_types=1);

namespace BricksMCP\MCP\Services;

use BricksMCP\MCP\Services\DesignSystem\ConfigMigrator;
use BricksMCP\MCP\Services\DesignSystem\ScaleComputer;
use BricksMCP\MCP\Services\DesignSystem\ColorComputer;

class DesignSystemGenerator {

    public const OWNED_CATEGORIES = [
        'Spacing',
        'Texts',
        'Headings',
        'Gaps/Padding',
        'Styles',
        'Radius',
        'Sizes',
        'Colors',
        'Grid',
    ];

    /**
     * Get default seed configuration.
     */
    public static function get_default_config(): array {
        return ConfigMigrator::migrate( [] );
    }

    /**
     * Generate a random 6-character alphanumeric ID (Bricks format).
     */
    private function random_id(): string {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $alpha_len = strlen( $alphabet );
        $id = '';
        for ( $i = 0; $i < 6; $i++ ) {
            $id .= $alphabet[ random_int( 0, $alpha_len - 1 ) ];
        }
        return $id;
    }

    /**
     * Generate complete design system from seed config.
     *
     * @param array $config Seed configuration.
     * @return array { variables: array[], categories: array[], palette: array, css: string }
     */
    public function generate( array $config ): array {
        // Ensure we always receive a fully-populated v2 config.
        $config = ConfigMigrator::migrate( $config );

        $sizes = $config['sizes'];
        $cw    = (int) $sizes['container_width'];
        $cm    = (int) $sizes['container_min'];

        $variables  = [];
        $categories = [];

        // Spacing.
        $cat_id       = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Spacing' ];
        $variables    = array_merge( $variables, $this->compute_spacing( $config['spacing'], $cat_id, $cw, $cm ) );

        // Texts.
        $cat_id       = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Texts' ];
        $variables    = array_merge( $variables, $this->compute_typography_text( $config['typography_text'], $cat_id, $cw, $cm ) );

        // Headings.
        $cat_id       = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Headings' ];
        $variables    = array_merge( $variables, $this->compute_headings( $config['typography_headings'], $cat_id, $cw, $cm ) );

        // Gaps/Padding.
        $cat_id       = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Gaps/Padding' ];
        $variables    = array_merge( $variables, $this->compute_gaps( $config['gaps'], $cat_id ) );

        // Styles.
        $cat_id       = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Styles' ];
        $variables    = array_merge( $variables, $this->compute_styles( $config['text_styles'], $cat_id ) );

        // Radius.
        $cat_id       = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Radius' ];
        $variables    = array_merge( $variables, $this->compute_radius_vars( $config['radius']['values'], $cat_id ) );

        // Sizes.
        $cat_id       = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Sizes' ];
        $variables    = array_merge( $variables, $this->compute_sizes( $config['sizes'], $cat_id, $cw, $cm ) );

        // Colors.
        $cat_id       = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Colors' ];
        $variables    = array_merge( $variables, $this->compute_color_variables( $config['colors'], $cat_id ) );

        // Grid.
        $cat_id       = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Grid' ];
        $variables    = array_merge( $variables, $this->compute_grid( $cat_id ) );

        // Palette.
        $palette = $this->compute_color_palette( $config['colors'] );

        // Framework CSS (optionally prepend html font-size override).
        $css = $this->get_framework_css();
        if ( (float) $config['html_font_size'] === 100.0 ) {
            $css = "html { font-size: 100%; }\n\n" . $css;
        }

        return compact( 'variables', 'categories', 'palette', 'css' );
    }

    /**
     * Generate and apply design system to Bricks.
     *
     * @param array $config Seed configuration.
     * @return array Summary of what was applied.
     */
    public function apply( array $config ): array {
        $generated = $this->generate( $config );
        $summary   = [
            'variables_count' => count( $generated['variables'] ),
            'categories'      => count( $generated['categories'] ),
            'palette_colors'  => count( $generated['palette']['colors'] ),
            'css_applied'     => false,
        ];

        // --- Variables: namespaced replace ---
        $existing_vars = get_option( 'bricks_global_variables', [] );
        $existing_cats = get_option( 'bricks_global_variables_categories', [] );

        // Build set of owned category IDs from existing data.
        $owned_cat_ids = [];
        foreach ( $existing_cats as $cat ) {
            if ( in_array( $cat['name'] ?? '', self::OWNED_CATEGORIES, true ) ) {
                $owned_cat_ids[] = $cat['id'];
            }
        }

        // Remove owned categories and their variables.
        $kept_cats = array_values( array_filter( $existing_cats, function ( $cat ) {
            return ! in_array( $cat['name'] ?? '', self::OWNED_CATEGORIES, true );
        } ) );
        $kept_vars = array_values( array_filter( $existing_vars, function ( $var ) use ( $owned_cat_ids ) {
            return ! in_array( $var['category'] ?? '', $owned_cat_ids, true );
        } ) );

        // Merge fresh generated data.
        $new_cats = array_merge( $kept_cats, $generated['categories'] );
        $new_vars = array_merge( $kept_vars, $generated['variables'] );

        update_option( 'bricks_global_variables_categories', $new_cats );
        update_option( 'bricks_global_variables', $new_vars );

        // --- Color palette: replace BricksCore palette ---
        $existing_palettes = get_option( 'bricks_color_palette', [] );
        $kept_palettes     = array_values( array_filter( $existing_palettes, function ( $p ) {
            return ( $p['name'] ?? '' ) !== 'BricksCore';
        } ) );
        $kept_palettes[]   = $generated['palette'];
        update_option( 'bricks_color_palette', $kept_palettes );

        // --- Framework CSS: write between markers ---
        $settings     = get_option( BricksCore::OPTION_GLOBAL_SETTINGS, [] );
        $existing_css = $settings['customCss'] ?? '';
        $new_css      = $generated['css'];

        $start_marker = '/* BricksCore Framework Start */';
        $end_marker   = '/* BricksCore Framework End */';

        $start_pos = strpos( $existing_css, $start_marker );
        $end_pos   = strpos( $existing_css, $end_marker );

        if ( false !== $start_pos && false !== $end_pos ) {
            // Replace existing block.
            $before = substr( $existing_css, 0, $start_pos );
            $after  = substr( $existing_css, $end_pos + strlen( $end_marker ) );
            $settings['customCss'] = $before . $new_css . $after;
        } else {
            // Append.
            $settings['customCss'] = $existing_css . "\n\n" . $new_css;
        }

        update_option( BricksCore::OPTION_GLOBAL_SETTINGS, $settings );
        $summary['css_applied'] = true;

        return $summary;
    }

    /**
     * Compute spacing variables.
     */
    private function compute_spacing( array $spacing, string $cat_id, int $cw, int $cm ): array {
        $out = [];
        foreach ( $spacing['steps'] as $name => $pair ) {
            $out[] = [
                'id'       => $this->random_id(),
                'category' => $cat_id,
                'name'     => "space-{$name}",
                'value'    => ScaleComputer::generate_clamp( (float) $pair['mobile'], (float) $pair['desktop'], $cw, $cm ),
            ];
        }
        return $out;
    }

    /**
     * Compute text typography variables.
     */
    private function compute_typography_text( array $text, string $cat_id, int $cw, int $cm ): array {
        $out = [];
        foreach ( $text['steps'] as $name => $pair ) {
            $out[] = [
                'id'       => $this->random_id(),
                'category' => $cat_id,
                'name'     => "text-{$name}",
                'value'    => ScaleComputer::generate_clamp( (float) $pair['mobile'], (float) $pair['desktop'], $cw, $cm ),
            ];
        }
        return $out;
    }

    /**
     * Compute heading typography variables.
     */
    private function compute_headings( array $headings, string $cat_id, int $cw, int $cm ): array {
        $out = [];
        foreach ( $headings['steps'] as $name => $pair ) {
            $out[] = [
                'id'       => $this->random_id(),
                'category' => $cat_id,
                'name'     => "{$name}",
                'value'    => ScaleComputer::generate_clamp( (float) $pair['mobile'], (float) $pair['desktop'], $cw, $cm ),
            ];
        }
        return $out;
    }

    /**
     * Compute gap/padding variables.
     */
    private function compute_gaps( array $gaps, string $cat_id ): array {
        $map = [
            'grid_gap'        => 'grid-gap',
            'grid_gap_s'      => 'grid-gap-s',
            'card_gap'        => 'card-gap',
            'content_gap'     => 'content-gap',
            'container_gap'   => 'container-gap',
            'padding_section' => 'padding-section',
            'offset'          => 'offset',
        ];
        $out = [];
        foreach ( $map as $key => $var ) {
            $out[] = [
                'id'       => $this->random_id(),
                'category' => $cat_id,
                'name'     => $var,
                'value'    => (string) ( $gaps[ $key ] ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * Compute style variables (line-height, font-weight, colors, borders).
     */
    private function compute_styles( array $styles, string $cat_id ): array {
        $map = [
            'text_color'          => 'text-color',
            'heading_color'       => 'heading-color',
            'text_font_weight'    => 'text-font-weight',
            'heading_font_weight' => 'heading-font-weight',
            'text_line_height'    => 'text-line-height',
            'heading_line_height' => 'heading-line-height',
            'border_color'        => 'border-color',
            'border_color_dark'   => 'border-color-dark',
        ];
        $out = [];
        foreach ( $map as $key => $var ) {
            $out[] = [
                'id'       => $this->random_id(),
                'category' => $cat_id,
                'name'     => $var,
                'value'    => (string) ( $styles[ $key ] ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * Compute radius variables from v2 values map.
     */
    private function compute_radius_vars( array $values, string $cat_id ): array {
        $map = [
            'radius'         => 'radius',
            'radius_inside'  => 'radius-inside',
            'radius_outside' => 'radius-outside',
            'radius_btn'     => 'radius-btn',
            'radius_pill'    => 'radius-pill',
            'radius_circle'  => 'radius-circle',
            'radius_s'       => 'radius-s',
            'radius_m'       => 'radius-m',
            'radius_l'       => 'radius-l',
            'radius_xl'      => 'radius-xl',
        ];
        $out = [];
        foreach ( $map as $key => $var ) {
            $out[] = [
                'id'       => $this->random_id(),
                'category' => $cat_id,
                'name'     => $var,
                'value'    => (string) ( $values[ $key ] ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * Compute size variables.
     */
    private function compute_sizes( array $sizes, string $cat_id, int $cw, int $cm ): array {
        $out = [];
        $fixed_map = [
            'container_width'    => 'container-width',
            'container_min'      => 'container-min-width',
            'max_width'          => 'max-width',
            'max_width_m'        => 'max-width-m',
            'max_width_s'        => 'max-width-s',
            'min_height'         => 'min-height',
            'min_height_section' => 'min-height-section',
        ];
        foreach ( $fixed_map as $key => $var ) {
            $out[] = [
                'id'       => $this->random_id(),
                'category' => $cat_id,
                'name'     => $var,
                'value'    => (int) $sizes[ $key ] . 'px',
            ];
        }

        // Logo width — fluid clamp between mobile and desktop.
        $out[] = [
            'id'       => $this->random_id(),
            'category' => $cat_id,
            'name'     => 'logo-width',
            'value'    => ScaleComputer::generate_clamp(
                (float) $sizes['logo_width_mobile'],
                (float) $sizes['logo_width_desktop'],
                $cw,
                $cm
            ),
        ];

        // content-width + width-10..90.
        $out[] = [
            'id'       => $this->random_id(),
            'category' => $cat_id,
            'name'     => 'content-width',
            'value'    => 'var(--container-width)',
        ];
        for ( $i = 10; $i <= 90; $i += 10 ) {
            $frac = number_format( $i / 100, 1, '.', '' );
            $out[] = [
                'id'       => $this->random_id(),
                'category' => $cat_id,
                'name'     => "width-{$i}",
                'value'    => "calc(var(--content-width) * {$frac})",
            ];
        }
        return $out;
    }

    /**
     * Compute color CSS variables for 6 families + white + black.
     */
    private function compute_color_variables( array $colors, string $cat_id ): array {
        $out        = [];
        $family_ids = [ 'primary', 'secondary', 'tertiary', 'accent', 'base', 'neutral' ];

        foreach ( $family_ids as $name ) {
            $fam = $colors[ $name ] ?? null;
            if ( ! is_array( $fam ) || empty( $fam['enabled'] ) ) {
                continue;
            }

            // Shade vars (5 or 8).
            $shade_order = [ 'base', 'ultra_dark', 'dark', 'light', 'ultra_light' ];
            if ( ! empty( $fam['expanded'] ) ) {
                $shade_order = [ 'base', 'ultra_dark', 'dark', 'semi_dark', 'medium', 'semi_light', 'light', 'ultra_light' ];
            }
            foreach ( $shade_order as $shade ) {
                if ( ! isset( $fam['shades'][ $shade ] ) ) {
                    continue;
                }
                $var_suffix = ( $shade === 'base' ) ? '' : '-' . str_replace( '_', '-', $shade );
                $out[] = [
                    'id'       => $this->random_id(),
                    'category' => $cat_id,
                    'name'     => "{$name}{$var_suffix}",
                    'value'    => (string) $fam['shades'][ $shade ],
                ];
            }

            // Hover.
            if ( ! empty( $fam['hover'] ) ) {
                $out[] = [
                    'id'       => $this->random_id(),
                    'category' => $cat_id,
                    'name'     => "{$name}-hover",
                    'value'    => (string) $fam['hover'],
                ];
            }

            // Transparencies.
            if ( ! empty( $fam['transparencies'] ) ) {
                // Base family: transparencies derived from --base-ultra-dark shade (matches var name).
                // Other families: derived from --{name} (base) shade.
                $source_shade = ( $name === 'base' ) ? 'ultra_dark' : 'base';
                $hex          = (string) ( $fam['shades'][ $source_shade ] ?? '#000000' );
                $trans        = ColorComputer::derive_transparencies( $hex );
                // Historical naming: base family uses --base-ultra-dark-trans-NN (not --base-trans-NN).
                $trans_prefix = ( $name === 'base' ) ? "{$name}-ultra-dark-trans-" : "{$name}-trans-";
                foreach ( $trans as $pct => $rgba ) {
                    $out[] = [
                        'id'       => $this->random_id(),
                        'category' => $cat_id,
                        'name'     => $trans_prefix . $pct,
                        'value'    => $rgba,
                    ];
                }
            }
        }

        // White.
        $white = $colors['white'] ?? [ 'hex' => '#ffffff', 'transparencies' => true ];
        $out[] = [
            'id'       => $this->random_id(),
            'category' => $cat_id,
            'name'     => 'white',
            'value'    => (string) $white['hex'],
        ];
        if ( ! empty( $white['transparencies'] ) ) {
            foreach ( ColorComputer::derive_transparencies( (string) $white['hex'] ) as $pct => $rgba ) {
                $out[] = [
                    'id'       => $this->random_id(),
                    'category' => $cat_id,
                    'name'     => "white-trans-{$pct}",
                    'value'    => $rgba,
                ];
            }
        }

        // Black.
        $black = $colors['black'] ?? [ 'hex' => '#000000', 'transparencies' => false ];
        $out[] = [
            'id'       => $this->random_id(),
            'category' => $cat_id,
            'name'     => 'black',
            'value'    => (string) $black['hex'],
        ];
        if ( ! empty( $black['transparencies'] ) ) {
            foreach ( ColorComputer::derive_transparencies( (string) $black['hex'] ) as $pct => $rgba ) {
                $out[] = [
                    'id'       => $this->random_id(),
                    'category' => $cat_id,
                    'name'     => "black-trans-{$pct}",
                    'value'    => $rgba,
                ];
            }
        }

        return $out;
    }

    /**
     * Compute grid template variables.
     */
    private function compute_grid( string $cat_id ): array {
        $vars = [];

        // grid-1 through grid-12.
        for ( $i = 1; $i <= 12; $i++ ) {
            $vars[] = [
                'id'       => $this->random_id(),
                'name'     => "grid-{$i}",
                'value'    => "repeat({$i}, minmax(0, 1fr))",
                'category' => $cat_id,
            ];
        }

        // Ratio grids.
        $ratios = [
            '1-2', '1-3', '1-4',
            '2-1', '2-3', '2-4',
            '3-1', '3-2', '3-4',
            '4-1', '4-2', '4-3',
        ];
        foreach ( $ratios as $ratio ) {
            [ $a, $b ] = explode( '-', $ratio );
            $vars[] = [
                'id'       => $this->random_id(),
                'name'     => "grid-{$ratio}",
                'value'    => "minmax(0, {$a}fr) minmax(0, {$b}fr)",
                'category' => $cat_id,
            ];
        }

        return $vars;
    }

    /**
     * Compute Bricks-native color palette (core shades + hover + white/black).
     */
    private function compute_color_palette( array $colors ): array {
        $palette_id  = $this->random_id();
        $all_colors  = [];
        $family_ids  = [ 'primary', 'secondary', 'tertiary', 'accent', 'base', 'neutral' ];

        foreach ( $family_ids as $name ) {
            $fam = $colors[ $name ] ?? null;
            if ( ! is_array( $fam ) || empty( $fam['enabled'] ) ) {
                continue;
            }
            $all_colors = array_merge( $all_colors, $this->build_palette_family(
                ucfirst( $name ),
                $name,
                (string) ( $fam['shades']['base']        ?? '#000000' ),
                (string) ( $fam['shades']['ultra_dark']  ?? '#000000' ),
                (string) ( $fam['shades']['dark']        ?? '#000000' ),
                (string) ( $fam['shades']['light']       ?? '#ffffff' ),
                (string) ( $fam['shades']['ultra_light'] ?? '#ffffff' )
            ) );

            // Flat hover entry.
            if ( ! empty( $fam['hover'] ) ) {
                $all_colors[] = [
                    'id'    => $this->random_id(),
                    'name'  => ucfirst( $name ) . ' Hover',
                    'raw'   => "var(--{$name}-hover)",
                    'light' => (string) $fam['hover'],
                ];
            }
        }

        // White + Black — flat entries.
        if ( isset( $colors['white'] ) ) {
            $all_colors[] = [
                'id'    => $this->random_id(),
                'name'  => 'White',
                'raw'   => 'var(--white)',
                'light' => (string) ( $colors['white']['hex'] ?? '#ffffff' ),
            ];
        }
        if ( isset( $colors['black'] ) ) {
            $all_colors[] = [
                'id'    => $this->random_id(),
                'name'  => 'Black',
                'raw'   => 'var(--black)',
                'light' => (string) ( $colors['black']['hex'] ?? '#000000' ),
            ];
        }

        return [
            'id'      => $palette_id,
            'name'    => 'BricksCore',
            'colors'  => $all_colors,
            'default' => true,
        ];
    }

    /**
     * Build palette colors for one family (5 entries: base + 4 pre-computed shades).
     */
    private function build_palette_family( string $label, string $prefix, string $base_hex, string $ultra_dark_hex, string $dark_hex, string $light_hex, string $ultra_light_hex ): array {
        $parent_id = $this->random_id();
        return [
            [
                'id'    => $parent_id,
                'name'  => $label,
                'raw'   => "var(--{$prefix})",
                'light' => $base_hex,
            ],
            [
                'id'     => $this->random_id(),
                'type'   => 'dark',
                'raw'    => "var(--{$prefix}-dark)",
                'parent' => $parent_id,
                'index'  => 0,
                'light'  => $dark_hex,
            ],
            [
                'id'     => $this->random_id(),
                'type'   => 'dark',
                'raw'    => "var(--{$prefix}-ultra-dark)",
                'parent' => $parent_id,
                'index'  => 1,
                'light'  => $ultra_dark_hex,
            ],
            [
                'id'     => $this->random_id(),
                'type'   => 'light',
                'raw'    => "var(--{$prefix}-light)",
                'parent' => $parent_id,
                'index'  => 0,
                'light'  => $light_hex,
            ],
            [
                'id'     => $this->random_id(),
                'type'   => 'light',
                'raw'    => "var(--{$prefix}-ultra-light)",
                'parent' => $parent_id,
                'index'  => 1,
                'light'  => $ultra_light_hex,
            ],
        ];
    }

    /**
     * Get framework CSS block with comment markers.
     */
    private function get_framework_css(): string {
        return <<<'CSS'
/* BricksCore Framework Start */

/* Framework */
[id]{ scroll-margin-top: calc(var(--offset) / 1.6); }
html { scroll-behavior: smooth; }

/* Accessibility */
body.bricks-is-frontend :focus{ outline: none; }
body.bricks-is-frontend :focus-visible{
  outline: solid 1px var(--primary);
  outline-offset: 5px;
  transition: all .3s;
}

/* Normalize */
ul{ padding: 0; margin: 0; }
.bricks-nav-menu { flex-wrap: wrap; justify-content: center; }

/* Text */
body{ font-size: var(--text-m); color: var(--text-color); }
:where(p), :where(span){ line-height: var(--text-line-height); }
:where(p){ font-weight: var(--text-font-weight); }
h1, h2, h3, h4, h5, h6{
  line-height: var(--heading-line-height);
  color: var(--heading-color);
  font-weight: var(--heading-font-weight);
}
h1 { font-size: var(--h1); }
h2 { font-size: var(--h2); }
h3 { font-size: var(--h3); }
h4 { font-size: var(--h4); }
h5 { font-size: var(--h5); }
h6 { font-size: var(--h6); }

/* Containers */
.brxe-section:not(.bricks-shape-divider) { padding: var(--padding-section); }
.brxe-section:not(.bricks-shape-divider) { gap: var(--container-gap); }
:where(.brxe-container) > .brxe-block, :where(.brxe-container){ gap: var(--content-gap); }

/* Fix overflow */
.bricks-is-frontend header{ max-width: 100vw; }
body.bricks-is-frontend{ overflow-x: clip; }
body.bricks-is-frontend.no-scroll{ overflow: hidden !important; }

/* Custom link */
body .brxe-post-content a:not([class]), body .brxe-text a:not([class]), body label a{
  text-decoration-line: underline;
  text-decoration-color: var(--primary);
  text-underline-offset: .2em;
  text-decoration-thickness: 1px;
  transition: all .3s;
}
body .brxe-post-content a:hover:not([class]), body .brxe-text a:hover:not([class]), body label a:hover{
  color: var(--primary);
}

/* BricksCore Framework End */
CSS;
    }
}
