<?php
declare(strict_types=1);

namespace BricksMCP\MCP\Services\DesignSystem;

/**
 * Pure helper — migrates old config shapes to the v2 shape.
 *
 * Idempotent: a v2 config passes through unchanged.
 * No WordPress deps.
 */
final class ConfigMigrator {

    /**
     * Migrate an old (or partial) config into the v2 shape.
     * Fills in missing fields with computed defaults so the rest of the pipeline
     * can assume a fully populated config.
     */
    public static function migrate( array $old ): array {
        $new = $old;

        // html_font_size.
        if ( ! isset( $new['html_font_size'] ) ) {
            $new['html_font_size'] = 62.5;
        }

        // Spacing steps.
        $new['spacing'] = $new['spacing'] ?? [];
        $new['spacing']['base_mobile']  = $new['spacing']['base_mobile']  ?? 20;
        $new['spacing']['base_desktop'] = $new['spacing']['base_desktop'] ?? 24;
        $new['spacing']['scale']        = $new['spacing']['scale']        ?? 1.5;
        if ( empty( $new['spacing']['steps'] ) ) {
            $new['spacing']['steps'] = self::compute_spacing_steps(
                (float) $new['spacing']['base_mobile'],
                (float) $new['spacing']['base_desktop'],
                (float) $new['spacing']['scale']
            );
        }

        // Typography text steps.
        $new['typography_text'] = $new['typography_text'] ?? [];
        $new['typography_text']['base_mobile']  = $new['typography_text']['base_mobile']  ?? 16;
        $new['typography_text']['base_desktop'] = $new['typography_text']['base_desktop'] ?? 18;
        $new['typography_text']['scale']        = $new['typography_text']['scale']        ?? 1.25;
        if ( empty( $new['typography_text']['steps'] ) ) {
            $new['typography_text']['steps'] = ScaleComputer::compute_steps(
                (float) $new['typography_text']['base_mobile'],
                (float) $new['typography_text']['base_desktop'],
                (float) $new['typography_text']['scale'],
                ScaleComputer::text_exponents()
            );
        }

        // Typography headings steps.
        $new['typography_headings'] = $new['typography_headings'] ?? [];
        $new['typography_headings']['base_mobile']  = $new['typography_headings']['base_mobile']  ?? 28;
        $new['typography_headings']['base_desktop'] = $new['typography_headings']['base_desktop'] ?? 35;
        $new['typography_headings']['scale']        = $new['typography_headings']['scale']        ?? 1.25;
        if ( empty( $new['typography_headings']['steps'] ) ) {
            $new['typography_headings']['steps'] = ScaleComputer::compute_steps(
                (float) $new['typography_headings']['base_mobile'],
                (float) $new['typography_headings']['base_desktop'],
                (float) $new['typography_headings']['scale'],
                ScaleComputer::heading_exponents()
            );
        }

        // Text styles.
        $new['text_styles'] = array_merge( [
            'text_color'          => 'var(--base)',
            'heading_color'       => 'var(--base-ultra-dark)',
            'text_font_weight'    => 400,
            'heading_font_weight' => 600,
            'text_line_height'    => 'calc(10px + 2ex)',
            'heading_line_height' => 'calc(7px + 2ex)',
            'border_color'        => 'var(--base-light)',
            'border_color_dark'   => 'var(--base-dark)',
        ], $new['text_styles'] ?? [] );

        // Colors — migrate v1 shape to v2 family shape.
        $new['colors'] = self::migrate_colors( $new['colors'] ?? [] );

        // Gaps.
        $new['gaps'] = array_merge( [
            'grid_gap'        => 'var(--space-xl) var(--space-l)',
            'grid_gap_s'      => 'var(--space-l) var(--space-m)',
            'card_gap'        => 'var(--space-s)',
            'content_gap'     => 'var(--space-m)',
            'container_gap'   => 'var(--space-xxl)',
            'padding_section' => 'var(--space-section) var(--space-m)',
            'offset'          => '80px',
        ], $new['gaps'] ?? [] );

        // Radius — handle old scalar shape.
        if ( isset( $new['radius'] ) && ! is_array( $new['radius'] ) ) {
            $new['radius'] = [ 'base' => (int) $new['radius'] ];
        }
        $new['radius']         = $new['radius'] ?? [];
        $new['radius']['base'] = (int) ( $new['radius']['base'] ?? 8 );
        if ( empty( $new['radius']['values'] ) ) {
            $new['radius']['values'] = self::compute_radius_values( $new['radius']['base'] );
        }

        // Sizes — migrate old flat container_width / container_min to nested sizes.
        $new['sizes'] = $new['sizes'] ?? [];
        if ( isset( $old['container_width'] ) && ! isset( $new['sizes']['container_width'] ) ) {
            $new['sizes']['container_width'] = (int) $old['container_width'];
        }
        if ( isset( $old['container_min'] ) && ! isset( $new['sizes']['container_min'] ) ) {
            $new['sizes']['container_min'] = (int) $old['container_min'];
        }
        $new['sizes'] = array_merge( [
            'container_width'    => 1280,
            'container_min'      => 380,
            'max_width'          => 980,
            'max_width_m'        => 840,
            'max_width_s'        => 640,
            'min_height'         => 340,
            'min_height_section' => 540,
            'logo_width_mobile'  => 120,
            'logo_width_desktop' => 200,
        ], $new['sizes'] );

        // Shadows (new in v3.19).
        $new['shadows'] = array_merge( [
            'xs'    => '0 1px 2px rgba(0, 0, 0, 0.05)',
            's'     => '0 1px 3px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.05)',
            'm'     => '0 4px 6px rgba(0, 0, 0, 0.08), 0 2px 4px rgba(0, 0, 0, 0.05)',
            'l'     => '0 10px 15px rgba(0, 0, 0, 0.10), 0 4px 6px rgba(0, 0, 0, 0.05)',
            'xl'    => '0 20px 25px rgba(0, 0, 0, 0.10), 0 10px 10px rgba(0, 0, 0, 0.04)',
            'inset' => 'inset 0 2px 4px rgba(0, 0, 0, 0.06)',
        ], $new['shadows'] ?? [] );

        // Transitions (new in v3.19).
        $new['transitions'] = array_merge( [
            'duration_fast' => '150ms',
            'duration_base' => '300ms',
            'duration_slow' => '500ms',
            'ease_out'      => 'cubic-bezier(0.16, 1, 0.3, 1)',
            'ease_in_out'   => 'cubic-bezier(0.4, 0, 0.2, 1)',
            'ease_spring'   => 'cubic-bezier(0.34, 1.56, 0.64, 1)',
        ], $new['transitions'] ?? [] );

        // Z-Index (new in v3.19).
        $new['z_index'] = array_merge( [
            'base'     => 1,
            'sticky'   => 100,
            'dropdown' => 1000,
            'overlay'  => 2000,
            'modal'    => 3000,
            'popover'  => 4000,
            'tooltip'  => 5000,
        ], $new['z_index'] ?? [] );

        // Borders (new in v3.19).
        $new['borders'] = array_merge( [
            'thin'   => '1px',
            'medium' => '2px',
            'thick'  => '4px',
        ], $new['borders'] ?? [] );

        // Aspect ratios (new in v3.19).
        $new['aspect_ratios'] = array_merge( [
            'square'   => '1 / 1',
            'video'    => '16 / 9',
            'photo'    => '4 / 3',
            'portrait' => '3 / 4',
            'wide'     => '21 / 9',
        ], $new['aspect_ratios'] ?? [] );

        // Drop obsolete top-level keys.
        unset( $new['project_name'], $new['container_width'], $new['container_min'] );

        return $new;
    }

    private static function compute_spacing_steps( float $bm, float $bd, float $scale ): array {
        $steps = ScaleComputer::compute_steps( $bm, $bd, $scale, ScaleComputer::spacing_exponents() );
        // Derived: section = xxl * 1.25.
        $steps['section'] = [
            'mobile'  => round( $steps['xxl']['mobile']  * 1.25, 2 ),
            'desktop' => round( $steps['xxl']['desktop'] * 1.25, 2 ),
        ];
        return $steps;
    }

    private static function compute_radius_values( int $base ): array {
        return [
            'radius'         => "{$base}px",
            'radius_inside'  => 'calc(var(--radius) * 0.5)',
            'radius_outside' => 'calc(var(--radius) * 1.4)',
            'radius_btn'     => '.3em',
            'radius_pill'    => '9999px',
            'radius_circle'  => '50%',
            'radius_s'       => (int) floor( $base * 0.7 ) . 'px',
            'radius_m'       => "{$base}px",
            'radius_l'       => (int) floor( $base * 1.5 ) . 'px',
            'radius_xl'      => (int) floor( $base * 2.25 ) . 'px',
        ];
    }

    /**
     * Migrate colors from v1 flat shape to v2 family shape.
     *
     * v1 primary: string hex. v1 secondary/accent: {enabled, hex}. v1 base: string hex.
     * v2: each family has {enabled, expanded, transparencies, shades:{...}, hover}.
     */
    private static function migrate_colors( array $old_colors ): array {
        $defaults = [
            'primary'   => [ '#3b82f6', true,  false ],
            'secondary' => [ '#f59e0b', true,  false ],
            'tertiary'  => [ '#8b5cf6', false, false ],
            'accent'    => [ '#10b981', true,  false ],
            'base'      => [ '#374151', true,  true  ],
            'neutral'   => [ '#9ca3af', false, true  ],
        ];

        $out = [];
        foreach ( $defaults as $name => [ $default_hex, $default_enabled, $is_neutral ] ) {
            $existing = $old_colors[ $name ] ?? null;

            // v2 family already present.
            if ( is_array( $existing ) && isset( $existing['shades'] ) ) {
                $out[ $name ] = array_merge( [
                    'enabled'        => $default_enabled,
                    'expanded'       => false,
                    'transparencies' => false,
                    'shades'         => [],
                    'hover'          => null,
                ], $existing );
                $hex = $existing['shades']['base'] ?? $default_hex;
                // Backfill missing derived fields.
                $out[ $name ]['shades'] = array_merge(
                    ColorComputer::derive_shades( $hex, ! empty( $existing['expanded'] ), $is_neutral ),
                    $out[ $name ]['shades']
                );
                if ( empty( $out[ $name ]['hover'] ) ) {
                    $out[ $name ]['hover'] = ColorComputer::derive_hover( $hex );
                }
                continue;
            }

            // v1 flat string (primary, base).
            if ( is_string( $existing ) ) {
                $hex          = $existing;
                $out[ $name ] = self::build_family( $hex, $default_enabled, $is_neutral );
                continue;
            }

            // v1 {enabled, hex} shape (secondary, accent).
            if ( is_array( $existing ) && isset( $existing['hex'] ) ) {
                $hex  = $existing['hex'];
                $enab = isset( $existing['enabled'] ) ? (bool) $existing['enabled'] : $default_enabled;
                $out[ $name ] = self::build_family( $hex, $enab, $is_neutral );
                continue;
            }

            // Missing entirely.
            $out[ $name ] = self::build_family( $default_hex, $default_enabled, $is_neutral );
        }

        // White / Black.
        $out['white'] = array_merge( [
            'hex'            => '#ffffff',
            'transparencies' => true,
        ], is_array( $old_colors['white'] ?? null ) ? $old_colors['white'] : [] );

        $out['black'] = array_merge( [
            'hex'            => '#000000',
            'transparencies' => false,
        ], is_array( $old_colors['black'] ?? null ) ? $old_colors['black'] : [] );

        return $out;
    }

    private static function build_family( string $hex, bool $enabled, bool $is_neutral ): array {
        return [
            'enabled'        => $enabled,
            'expanded'       => false,
            'transparencies' => false,
            'shades'         => ColorComputer::derive_shades( $hex, false, $is_neutral ),
            'hover'          => ColorComputer::derive_hover( $hex ),
            'is_neutral'     => $is_neutral,
        ];
    }
}
