<?php
declare(strict_types=1);

namespace BricksMCP\MCP\Services\DesignSystem;

/**
 * Pure helper — computes step values from a base/scale + generates fluid clamp() strings.
 *
 * No WordPress deps. Mirrored in assets/js/admin-design-system.js for client-side preview.
 */
final class ScaleComputer {

    private const REM_BASE = 16;

    /**
     * Compute mobile/desktop values for each step.
     *
     * @param float  $base_mobile   Base mobile px value (exponent 0 reference).
     * @param float  $base_desktop  Base desktop px value (exponent 0 reference).
     * @param float  $scale         Scale ratio (e.g. 1.25, 1.5).
     * @param array  $step_exponents ['name' => float_exponent, ...]
     * @return array ['name' => ['mobile' => float, 'desktop' => float], ...]
     */
    public static function compute_steps( float $base_mobile, float $base_desktop, float $scale, array $step_exponents ): array {
        $out = [];
        foreach ( $step_exponents as $name => $exp ) {
            $out[ $name ] = [
                'mobile'  => round( $base_mobile * pow( $scale, $exp ), 2 ),
                'desktop' => round( $base_desktop * pow( $scale, $exp ), 2 ),
            ];
        }
        return $out;
    }

    /**
     * Generate a fluid clamp() CSS value between mobile and desktop breakpoints.
     */
    public static function generate_clamp( float $mobile_px, float $desktop_px, int $container_width, int $container_min ): string {
        $min_rem = number_format( $mobile_px / self::REM_BASE, 2, '.', '' );
        $max_rem = number_format( $desktop_px / self::REM_BASE, 2, '.', '' );

        $denom = $container_width - $container_min;
        if ( $denom <= 0 ) {
            // Degenerate: no fluid range — just use desktop value.
            return "{$max_rem}rem";
        }
        $slope = number_format( ( $desktop_px - $mobile_px ) / $denom, 4, '.', '' );

        return "clamp({$min_rem}rem, calc({$min_rem}rem + {$slope} * (100vw - {$container_min}px)), {$max_rem}rem)";
    }

    /**
     * Canonical step exponents per category.
     */
    public static function spacing_exponents(): array {
        // Note: 'section' is special — computed as xxl * 1.25, not a simple exponent.
        return [ 'xs' => -2, 's' => -1, 'm' => 0, 'l' => 1, 'xl' => 2, 'xxl' => 3 ];
    }

    public static function text_exponents(): array {
        return [ 'xs' => -2, 's' => -1, 'm' => 0, 'mm' => 0.5, 'l' => 1, 'xl' => 2, 'xxl' => 3 ];
    }

    public static function heading_exponents(): array {
        // h3 is the anchor (exp 0).
        return [ 'h1' => 2, 'h2' => 1, 'h3' => 0, 'h4' => -1, 'h5' => -2, 'h6' => -3 ];
    }
}
