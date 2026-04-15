<?php
declare(strict_types=1);

namespace BricksMCP\MCP\Services\DesignSystem;

/**
 * Pure helper — derives shade palettes, hover variants, transparencies from a base hex.
 *
 * No WordPress deps. Mirrored in assets/js/admin-design-system.js for client-side preview.
 */
final class ColorComputer {

    /**
     * Lighten a hex color by blending toward white.
     */
    public static function lighten( string $hex, int $percent ): string {
        [ $r, $g, $b ] = self::hex_to_rgb( $hex );
        $r = min( 255, (int) round( $r + ( 255 - $r ) * ( $percent / 100 ) ) );
        $g = min( 255, (int) round( $g + ( 255 - $g ) * ( $percent / 100 ) ) );
        $b = min( 255, (int) round( $b + ( 255 - $b ) * ( $percent / 100 ) ) );
        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }

    /**
     * Darken a hex color by reducing toward black.
     */
    public static function darken( string $hex, int $percent ): string {
        [ $r, $g, $b ] = self::hex_to_rgb( $hex );
        $r = max( 0, (int) round( $r * ( 1 - $percent / 100 ) ) );
        $g = max( 0, (int) round( $g * ( 1 - $percent / 100 ) ) );
        $b = max( 0, (int) round( $b * ( 1 - $percent / 100 ) ) );
        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }

    /**
     * Derive all shades from a base hex.
     *
     * Returns 5 shades always; 8 when $expanded is true.
     *
     * @param string $hex        Base hex color.
     * @param bool   $expanded   If true, include semi_dark / medium / semi_light.
     * @param bool   $is_neutral If true, use neutral curve (lighten-only for dark shades).
     * @return array ['base' => hex, 'ultra_dark' => hex, ...]
     */
    public static function derive_shades( string $hex, bool $expanded = false, bool $is_neutral = false ): array {
        $shades = [
            'base'        => self::normalize_hex( $hex ),
            'ultra_dark'  => $is_neutral ? self::lighten( $hex, 10 )  : self::darken( $hex, 40 ),
            'dark'        => $is_neutral ? self::lighten( $hex, 25 )  : self::darken( $hex, 20 ),
            'light'       => self::lighten( $hex, 85 ),
            'ultra_light' => self::lighten( $hex, 95 ),
        ];
        if ( $expanded ) {
            $shades['semi_dark']  = $is_neutral ? self::lighten( $hex, 40 ) : self::darken( $hex, 10 );
            $shades['medium']     = $is_neutral ? self::lighten( $hex, 60 ) : self::lighten( $hex, 35 );
            $shades['semi_light'] = $is_neutral ? self::lighten( $hex, 75 ) : self::lighten( $hex, 65 );
        }
        return $shades;
    }

    /**
     * Auto-derive hover variant = darken(base, 10).
     */
    public static function derive_hover( string $hex ): string {
        return self::darken( $hex, 10 );
    }

    /**
     * Derive 9 transparency steps (90% -> 10%) as rgba strings.
     *
     * @return array ['90' => 'rgba(r,g,b,0.9)', ..., '10' => 'rgba(r,g,b,0.1)']
     */
    public static function derive_transparencies( string $hex ): array {
        [ $r, $g, $b ] = self::hex_to_rgb( $hex );
        $out = [];
        foreach ( [ 90, 80, 70, 60, 50, 40, 30, 20, 10 ] as $pct ) {
            $alpha = number_format( $pct / 100, 2, '.', '' );
            $out[ (string) $pct ] = "rgba({$r}, {$g}, {$b}, {$alpha})";
        }
        return $out;
    }

    private static function hex_to_rgb( string $hex ): array {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            (int) hexdec( substr( $hex, 0, 2 ) ),
            (int) hexdec( substr( $hex, 2, 2 ) ),
            (int) hexdec( substr( $hex, 4, 2 ) ),
        ];
    }

    private static function normalize_hex( string $hex ): string {
        [ $r, $g, $b ] = self::hex_to_rgb( $hex );
        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }
}
