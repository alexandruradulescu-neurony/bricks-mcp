<?php
declare(strict_types=1);

namespace BricksMCP\MCP\Services;

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

    private const REM_BASE = 16;

    /**
     * Get default seed configuration.
     */
    public static function get_default_config(): array {
        return [
            'project_name'         => 'My Site',
            'colors'               => [
                'primary'   => '#3b82f6',
                'secondary' => [ 'enabled' => true, 'hex' => '#f59e0b' ],
                'accent'    => [ 'enabled' => true, 'hex' => '#10b981' ],
                'base'      => '#374151',
            ],
            'spacing'              => [
                'base_mobile'  => 20,
                'base_desktop' => 24,
                'scale'        => 1.5,
            ],
            'typography_text'      => [
                'base_mobile'  => 16,
                'base_desktop' => 18,
                'scale'        => 1.25,
            ],
            'typography_headings'  => [
                'base_mobile'  => 28,
                'base_desktop' => 35,
                'scale'        => 1.25,
            ],
            'radius'               => 8,
            'container_width'      => 1280,
            'container_min'        => 380,
        ];
    }

    /**
     * Generate a fluid clamp() value.
     */
    private function generate_clamp( float $mobile_px, float $desktop_px, int $container_width, int $container_min ): string {
        $min_rem = number_format( $mobile_px / self::REM_BASE, 2, '.', '' );
        $max_rem = number_format( $desktop_px / self::REM_BASE, 2, '.', '' );
        $slope   = number_format( ( $desktop_px - $mobile_px ) / ( $container_width - $container_min ), 4, '.', '' );

        return "clamp({$min_rem}rem, calc({$min_rem}rem + {$slope} * (100vw - {$container_min}px)), {$max_rem}rem)";
    }

    /**
     * Lighten a hex color by blending toward white.
     */
    private function lighten_color( string $hex, int $percent ): string {
        $hex = ltrim( $hex, '#' );
        $r   = (int) hexdec( substr( $hex, 0, 2 ) );
        $g   = (int) hexdec( substr( $hex, 2, 2 ) );
        $b   = (int) hexdec( substr( $hex, 4, 2 ) );

        $r = min( 255, (int) round( $r + ( 255 - $r ) * ( $percent / 100 ) ) );
        $g = min( 255, (int) round( $g + ( 255 - $g ) * ( $percent / 100 ) ) );
        $b = min( 255, (int) round( $b + ( 255 - $b ) * ( $percent / 100 ) ) );

        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }

    /**
     * Darken a hex color by reducing toward black.
     */
    private function darken_color( string $hex, int $percent ): string {
        $hex = ltrim( $hex, '#' );
        $r   = (int) hexdec( substr( $hex, 0, 2 ) );
        $g   = (int) hexdec( substr( $hex, 2, 2 ) );
        $b   = (int) hexdec( substr( $hex, 4, 2 ) );

        $r = max( 0, (int) round( $r * ( 1 - $percent / 100 ) ) );
        $g = max( 0, (int) round( $g * ( 1 - $percent / 100 ) ) );
        $b = max( 0, (int) round( $b * ( 1 - $percent / 100 ) ) );

        return sprintf( '#%02x%02x%02x', $r, $g, $b );
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
        $cw  = (int) ( $config['container_width'] ?? 1280 );
        $cm  = (int) ( $config['container_min'] ?? 380 );

        $variables  = [];
        $categories = [];

        // Spacing.
        $cat_id      = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Spacing' ];
        $variables    = array_merge( $variables, $this->compute_spacing( $config['spacing'] ?? [], $cat_id, $cw, $cm ) );

        // Texts.
        $cat_id      = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Texts' ];
        $variables    = array_merge( $variables, $this->compute_typography_text( $config['typography_text'] ?? [], $cat_id, $cw, $cm ) );

        // Headings.
        $cat_id      = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Headings' ];
        $variables    = array_merge( $variables, $this->compute_headings( $config['typography_headings'] ?? [], $cat_id, $cw, $cm ) );

        // Gaps/Padding.
        $cat_id      = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Gaps/Padding' ];
        $variables    = array_merge( $variables, $this->compute_gaps( $cat_id ) );

        // Styles.
        $cat_id      = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Styles' ];
        $variables    = array_merge( $variables, $this->compute_styles( $cat_id ) );

        // Radius.
        $cat_id      = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Radius' ];
        $variables    = array_merge( $variables, $this->compute_radius( (int) ( $config['radius'] ?? 8 ), $cat_id ) );

        // Sizes.
        $cat_id      = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Sizes' ];
        $variables    = array_merge( $variables, $this->compute_sizes( $cw, $cm, $cat_id ) );

        // Colors.
        $cat_id      = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Colors' ];
        $variables    = array_merge( $variables, $this->compute_color_variables( $config['colors'] ?? [], $cat_id ) );

        // Grid.
        $cat_id      = $this->random_id();
        $categories[] = [ 'id' => $cat_id, 'name' => 'Grid' ];
        $variables    = array_merge( $variables, $this->compute_grid( $cat_id ) );

        // Color palette.
        $palette = $this->compute_color_palette( $config['colors'] ?? [] );

        // Framework CSS.
        $css = $this->get_framework_css();

        return [
            'variables'  => $variables,
            'categories' => $categories,
            'palette'    => $palette,
            'css'        => $css,
        ];
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
        $settings     = get_option( 'bricks_global_settings', [] );
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

        update_option( 'bricks_global_settings', $settings );
        $summary['css_applied'] = true;

        return $summary;
    }

    /**
     * Compute spacing variables.
     */
    private function compute_spacing( array $spacing, string $cat_id, int $cw, int $cm ): array {
        $base_m = (float) ( $spacing['base_mobile'] ?? 20 );
        $base_d = (float) ( $spacing['base_desktop'] ?? 24 );
        $scale  = (float) ( $spacing['scale'] ?? 1.5 );

        $steps = [
            'space-xs'  => [ $base_m / ( $scale * $scale ), $base_d / ( $scale * $scale ) ],
            'space-s'   => [ $base_m / $scale, $base_d / $scale ],
            'space-m'   => [ $base_m, $base_d ],
            'space-l'   => [ $base_m * $scale, $base_d * $scale ],
            'space-xl'  => [ $base_m * $scale * $scale, $base_d * $scale * $scale ],
            'space-xxl' => [ $base_m * pow( $scale, 3 ), $base_d * pow( $scale, 3 ) ],
        ];

        $vars = [];
        foreach ( $steps as $name => [ $mob, $desk ] ) {
            $vars[] = [
                'id'       => $this->random_id(),
                'name'     => $name,
                'value'    => $this->generate_clamp( round( $mob, 2 ), round( $desk, 2 ), $cw, $cm ),
                'category' => $cat_id,
            ];
        }

        // space-section = xxl * 1.25.
        $section_m = $base_m * pow( $scale, 3 ) * 1.25;
        $section_d = $base_d * pow( $scale, 3 ) * 1.25;
        $vars[]    = [
            'id'       => $this->random_id(),
            'name'     => 'space-section',
            'value'    => $this->generate_clamp( round( $section_m, 2 ), round( $section_d, 2 ), $cw, $cm ),
            'category' => $cat_id,
        ];

        // logo-width (fixed).
        $vars[] = [
            'id'       => $this->random_id(),
            'name'     => 'logo-width',
            'value'    => $this->generate_clamp( 120, 200, $cw, $cm ),
            'category' => $cat_id,
        ];

        return $vars;
    }

    /**
     * Compute text typography variables (text-xs through text-xxl).
     */
    private function compute_typography_text( array $typo, string $cat_id, int $cw, int $cm ): array {
        $base_m = (float) ( $typo['base_mobile'] ?? 16 );
        $base_d = (float) ( $typo['base_desktop'] ?? 18 );
        $scale  = (float) ( $typo['scale'] ?? 1.25 );

        // m is baseline. Steps: xs, s, m, mm, l, xl, xxl.
        $steps = [
            'text-xs'  => [ $base_m / ( $scale * $scale ), $base_d / ( $scale * $scale ) ],
            'text-s'   => [ $base_m / $scale, $base_d / $scale ],
            'text-m'   => [ $base_m, $base_d ],
            'text-mm'  => [ $base_m * pow( $scale, 0.5 ), $base_d * pow( $scale, 0.5 ) ],
            'text-l'   => [ $base_m * $scale, $base_d * $scale ],
            'text-xl'  => [ $base_m * $scale * $scale, $base_d * $scale * $scale ],
            'text-xxl' => [ $base_m * pow( $scale, 3 ), $base_d * pow( $scale, 3 ) ],
        ];

        $vars = [];
        foreach ( $steps as $name => [ $mob, $desk ] ) {
            $vars[] = [
                'id'       => $this->random_id(),
                'name'     => $name,
                'value'    => $this->generate_clamp( round( $mob, 2 ), round( $desk, 2 ), $cw, $cm ),
                'category' => $cat_id,
            ];
        }

        return $vars;
    }

    /**
     * Compute heading typography variables (h1 through h6).
     */
    private function compute_headings( array $typo, string $cat_id, int $cw, int $cm ): array {
        $base_m = (float) ( $typo['base_mobile'] ?? 28 );
        $base_d = (float) ( $typo['base_desktop'] ?? 35 );
        $scale  = (float) ( $typo['scale'] ?? 1.25 );

        // h3 is baseline. Scale up for h2, h1. Scale down for h4, h5, h6.
        $steps = [
            'h1' => [ $base_m * $scale * $scale, $base_d * $scale * $scale ],
            'h2' => [ $base_m * $scale, $base_d * $scale ],
            'h3' => [ $base_m, $base_d ],
            'h4' => [ $base_m / $scale, $base_d / $scale ],
            'h5' => [ $base_m / ( $scale * $scale ), $base_d / ( $scale * $scale ) ],
            'h6' => [ $base_m / pow( $scale, 3 ), $base_d / pow( $scale, 3 ) ],
        ];

        $vars = [];
        foreach ( $steps as $name => [ $mob, $desk ] ) {
            $vars[] = [
                'id'       => $this->random_id(),
                'name'     => $name,
                'value'    => $this->generate_clamp( round( $mob, 2 ), round( $desk, 2 ), $cw, $cm ),
                'category' => $cat_id,
            ];
        }

        return $vars;
    }

    /**
     * Compute gap/padding variables (derived from spacing var names).
     */
    private function compute_gaps( string $cat_id ): array {
        return [
            [ 'id' => $this->random_id(), 'name' => 'grid-gap',        'value' => 'var(--space-xl) var(--space-l)',         'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'grid-gap-s',      'value' => 'var(--space-l) var(--space-m)',          'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'card-gap',        'value' => 'var(--space-s)',                         'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'content-gap',     'value' => 'var(--space-m)',                         'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'container-gap',   'value' => 'var(--space-xxl)',                       'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'padding-section', 'value' => 'var(--space-section) var(--space-m)',    'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'offset',          'value' => '80px',                                  'category' => $cat_id ],
        ];
    }

    /**
     * Compute style variables (line-height, font-weight, colors, borders).
     */
    private function compute_styles( string $cat_id ): array {
        return [
            [ 'id' => $this->random_id(), 'name' => 'text-line-height',    'value' => 'calc(10px + 2ex)',        'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'heading-line-height', 'value' => 'calc(7px + 2ex)',         'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'text-font-weight',    'value' => '400',                     'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'heading-font-weight', 'value' => '600',                     'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'text-color',          'value' => 'var(--base)',             'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'heading-color',       'value' => 'var(--base-ultra-dark)',  'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'border-color',        'value' => 'var(--base-light)',       'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'border-color-dark',   'value' => 'var(--base-dark)',        'category' => $cat_id ],
        ];
    }

    /**
     * Compute radius variables from base.
     */
    private function compute_radius( int $base, string $cat_id ): array {
        return [
            [ 'id' => $this->random_id(), 'name' => 'radius',         'value' => $base . 'px',                          'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'radius-inside',  'value' => 'calc(var(--radius) * 0.5)',            'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'radius-outside', 'value' => 'calc(var(--radius) * 1.4)',            'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'radius-btn',     'value' => '.3em',                                'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'radius-pill',    'value' => '9999px',                              'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'radius-circle',  'value' => '50%',                                 'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'radius-s',       'value' => (int) floor( $base * 0.7 ) . 'px',     'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'radius-m',       'value' => $base . 'px',                          'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'radius-l',       'value' => (int) floor( $base * 1.5 ) . 'px',     'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'radius-xl',      'value' => (int) floor( $base * 2.25 ) . 'px',    'category' => $cat_id ],
        ];
    }

    /**
     * Compute size variables.
     */
    private function compute_sizes( int $cw, int $cm, string $cat_id ): array {
        $vars = [
            [ 'id' => $this->random_id(), 'name' => 'container-width',     'value' => $cw . 'px',                            'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'container-min-width', 'value' => $cm . 'px',                            'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'max-width',           'value' => (int) floor( $cw * 0.766 ) . 'px',     'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'max-width-m',         'value' => (int) floor( $cw * 0.656 ) . 'px',     'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'max-width-s',         'value' => (int) floor( $cw * 0.5 ) . 'px',       'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'min-height',          'value' => '340px',                               'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'min-height-section',  'value' => '540px',                               'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => 'content-width',       'value' => 'var(--container-width)',               'category' => $cat_id ],
        ];

        // width-10 through width-90.
        for ( $i = 10; $i <= 90; $i += 10 ) {
            $vars[] = [
                'id'       => $this->random_id(),
                'name'     => "width-{$i}",
                'value'    => 'calc(var(--content-width) * 0.' . $i / 10 . ')',
                'category' => $cat_id,
            ];
        }

        return $vars;
    }

    /**
     * Compute color CSS variables (hex values stored as variables).
     */
    private function compute_color_variables( array $colors, string $cat_id ): array {
        $vars = [];

        // Primary (always enabled).
        $primary = $colors['primary'] ?? '#3b82f6';
        $vars    = array_merge( $vars, $this->build_color_family_vars( 'primary', $primary, false, $cat_id ) );

        // Secondary (optional).
        $sec = $colors['secondary'] ?? [ 'enabled' => true, 'hex' => '#f59e0b' ];
        if ( ! empty( $sec['enabled'] ) ) {
            $vars = array_merge( $vars, $this->build_color_family_vars( 'secondary', $sec['hex'], false, $cat_id ) );
        }

        // Accent (optional).
        $acc = $colors['accent'] ?? [ 'enabled' => true, 'hex' => '#10b981' ];
        if ( ! empty( $acc['enabled'] ) ) {
            $vars = array_merge( $vars, $this->build_color_family_vars( 'accent', $acc['hex'], false, $cat_id ) );
        }

        // Base (always, uses neutral shading curve).
        $base = $colors['base'] ?? '#374151';
        $vars = array_merge( $vars, $this->build_color_family_vars( 'base', $base, true, $cat_id ) );

        // Base-ultra-dark transparencies.
        $base_ud_hex = $this->lighten_color( $base, 10 );
        $base_ud_r   = hexdec( substr( ltrim( $base_ud_hex, '#' ), 0, 2 ) );
        $base_ud_g   = hexdec( substr( ltrim( $base_ud_hex, '#' ), 2, 2 ) );
        $base_ud_b   = hexdec( substr( ltrim( $base_ud_hex, '#' ), 4, 2 ) );
        for ( $i = 10; $i <= 90; $i += 10 ) {
            $alpha  = number_format( $i / 100, 1, '.', '' );
            $vars[] = [
                'id'       => $this->random_id(),
                'name'     => "base-ultra-dark-trans-{$i}",
                'value'    => "rgba({$base_ud_r}, {$base_ud_g}, {$base_ud_b}, {$alpha})",
                'category' => $cat_id,
            ];
        }

        // White + black.
        $vars[] = [ 'id' => $this->random_id(), 'name' => 'white', 'value' => '#ffffff', 'category' => $cat_id ];

        // White transparencies.
        for ( $i = 10; $i <= 90; $i += 10 ) {
            $alpha  = number_format( $i / 100, 1, '.', '' );
            $vars[] = [
                'id'       => $this->random_id(),
                'name'     => "white-trans-{$i}",
                'value'    => "rgba(255, 255, 255, {$alpha})",
                'category' => $cat_id,
            ];
        }

        $vars[] = [ 'id' => $this->random_id(), 'name' => 'black', 'value' => '#000000', 'category' => $cat_id ];

        return $vars;
    }

    /**
     * Build 5 color variables for a color family (base + 4 shades).
     */
    private function build_color_family_vars( string $prefix, string $hex, bool $is_neutral, string $cat_id ): array {
        if ( $is_neutral ) {
            $ultra_dark = $this->lighten_color( $hex, 10 );
            $dark       = $this->lighten_color( $hex, 25 );
        } else {
            $ultra_dark = $this->darken_color( $hex, 40 );
            $dark       = $this->darken_color( $hex, 20 );
        }
        $light       = $this->lighten_color( $hex, 85 );
        $ultra_light = $this->lighten_color( $hex, 95 );

        return [
            [ 'id' => $this->random_id(), 'name' => $prefix,                'value' => $hex,         'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => "{$prefix}-ultra-dark", 'value' => $ultra_dark,  'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => "{$prefix}-dark",       'value' => $dark,        'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => "{$prefix}-light",      'value' => $light,       'category' => $cat_id ],
            [ 'id' => $this->random_id(), 'name' => "{$prefix}-ultra-light",'value' => $ultra_light, 'category' => $cat_id ],
        ];
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
     * Compute Bricks-native color palette.
     */
    private function compute_color_palette( array $colors ): array {
        $palette_id = $this->random_id();
        $all_colors = [];

        // Primary.
        $primary_hex = $colors['primary'] ?? '#3b82f6';
        $all_colors  = array_merge( $all_colors, $this->build_palette_family( 'Primary', 'primary', $primary_hex, false ) );

        // Secondary.
        $sec = $colors['secondary'] ?? [ 'enabled' => true, 'hex' => '#f59e0b' ];
        if ( ! empty( $sec['enabled'] ) ) {
            $all_colors = array_merge( $all_colors, $this->build_palette_family( 'Secondary', 'secondary', $sec['hex'], false ) );
        }

        // Accent.
        $acc = $colors['accent'] ?? [ 'enabled' => true, 'hex' => '#10b981' ];
        if ( ! empty( $acc['enabled'] ) ) {
            $all_colors = array_merge( $all_colors, $this->build_palette_family( 'Accent', 'accent', $acc['hex'], false ) );
        }

        // Base.
        $base_hex   = $colors['base'] ?? '#374151';
        $all_colors = array_merge( $all_colors, $this->build_palette_family( 'Base', 'base', $base_hex, true ) );

        // White + transparencies.
        $white_id     = $this->random_id();
        $all_colors[] = [ 'id' => $white_id, 'name' => 'White', 'raw' => 'var(--white)', 'light' => '#ffffff' ];
        for ( $i = 10; $i <= 90; $i += 10 ) {
            $alpha        = number_format( $i / 100, 1, '.', '' );
            $all_colors[] = [
                'id'     => $this->random_id(),
                'type'   => 'transparent',
                'raw'    => "var(--white-trans-{$i})",
                'index'  => ( $i / 10 ) - 1,
                'parent' => $white_id,
                'light'  => "rgba(255, 255, 255, {$alpha})",
            ];
        }

        // Black.
        $all_colors[] = [ 'id' => $this->random_id(), 'name' => 'Black', 'raw' => 'var(--black)', 'light' => '#000000' ];

        return [
            'id'      => $palette_id,
            'name'    => 'BricksCore',
            'colors'  => $all_colors,
            'default' => true,
        ];
    }

    /**
     * Build palette colors for one color family (5 entries: base + 4 shades).
     */
    private function build_palette_family( string $label, string $prefix, string $hex, bool $is_neutral ): array {
        $parent_id = $this->random_id();

        if ( $is_neutral ) {
            $ultra_dark = $this->lighten_color( $hex, 10 );
            $dark       = $this->lighten_color( $hex, 25 );
        } else {
            $ultra_dark = $this->darken_color( $hex, 40 );
            $dark       = $this->darken_color( $hex, 20 );
        }
        $light       = $this->lighten_color( $hex, 85 );
        $ultra_light = $this->lighten_color( $hex, 95 );

        return [
            [ 'id' => $parent_id,         'name' => $label,     'raw' => "var(--{$prefix})",             'light' => $hex ],
            [ 'id' => $this->random_id(), 'type' => 'dark',  'raw' => "var(--{$prefix}-ultra-dark)", 'index' => 0, 'parent' => $parent_id, 'light' => $ultra_dark ],
            [ 'id' => $this->random_id(), 'type' => 'dark',  'raw' => "var(--{$prefix}-dark)",       'index' => 1, 'parent' => $parent_id, 'light' => $dark ],
            [ 'id' => $this->random_id(), 'type' => 'light', 'raw' => "var(--{$prefix}-light)",      'index' => 0, 'parent' => $parent_id, 'light' => $light ],
            [ 'id' => $this->random_id(), 'type' => 'light', 'raw' => "var(--{$prefix}-ultra-light)",'index' => 1, 'parent' => $parent_id, 'light' => $ultra_light ],
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
