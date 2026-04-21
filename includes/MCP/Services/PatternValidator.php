<?php
/**
 * Pattern validator.
 *
 * Single entry point for all pattern creation paths (capture, create, import).
 * Strips content, snaps raw values to tokens, resolves classes/variables,
 * and emits either a valid pattern or a structured rejection.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PatternValidator {

    /**
     * Fields removed during content stripping (case-sensitive, exact match).
     * These are human-facing values; structural metadata (type, role, tag,
     * class_refs, style_tokens) is preserved.
     */
    private const STRIPPED_FIELDS = [
        'content', 'content_example', 'text', 'label', 'title', 'description',
        'link', 'href', 'target', 'icon', 'image', 'src', 'url',
        'placeholder', 'note',
    ];

    /**
     * Recursively strip human-facing content fields from a pattern tree.
     *
     * @param array<string, mixed> $node Pattern tree or subtree.
     * @return array<string, mixed> Cloned tree with content fields removed.
     */
    public function strip_content( array $node ): array {
        foreach ( self::STRIPPED_FIELDS as $field ) {
            unset( $node[ $field ] );
        }
        foreach ( $node as $key => $value ) {
            if ( is_array( $value ) ) {
                $node[ $key ] = $this->strip_content( $value );
            }
        }
        return $node;
    }

    /**
     * Snap threshold percentage for pixel-based categories.
     * Values exceeding threshold from nearest variable → rejected.
     */
    private const SNAP_THRESHOLD_PCT = 20;

    /**
     * Snap a raw style value to the nearest matching site variable.
     *
     * For pixel categories (spacing, radius, font-size): computes percent
     * distance to each variable of the same category, returns nearest if
     * within SNAP_THRESHOLD_PCT, else returns null.
     *
     * @param string                $value     Raw value or var() reference.
     * @param array<string, string> $site_vars Map of var name → raw value.
     * @param string                $category  One of: spacing, radius, font-size.
     * @return array{snapped: ?string, delta_pct: float, raw: string}
     */
    public function snap_value( string $value, array $site_vars, string $category ): array {
        $trimmed = trim( $value );

        // Pass through var() references untouched.
        if ( preg_match( '/^var\(\s*(--[a-z0-9-]+)\s*\)$/i', $trimmed, $m ) ) {
            return [ 'snapped' => 'var(' . $m[1] . ')', 'delta_pct' => 0, 'raw' => $value ];
        }

        $px = $this->parse_px( $trimmed );
        if ( null === $px ) {
            return [ 'snapped' => null, 'delta_pct' => 100.0, 'raw' => $value ];
        }

        $best_name  = null;
        $best_delta = PHP_FLOAT_MAX;
        foreach ( $site_vars as $name => $raw ) {
            if ( ! str_starts_with( $name, $this->category_prefix( $category ) ) ) {
                continue;
            }
            $var_px = $this->parse_px( $raw );
            if ( null === $var_px || $var_px <= 0 ) {
                continue;
            }
            $delta = abs( ( $px - $var_px ) / $var_px ) * 100;
            if ( $delta < $best_delta ) {
                $best_delta = $delta;
                $best_name  = $name;
            }
        }

        if ( null === $best_name || $best_delta > self::SNAP_THRESHOLD_PCT ) {
            return [ 'snapped' => null, 'delta_pct' => round( $best_delta, 1 ), 'raw' => $value ];
        }

        return [ 'snapped' => 'var(' . $best_name . ')', 'delta_pct' => round( $best_delta, 1 ), 'raw' => $value ];
    }

    /**
     * Parse a CSS pixel value ("16px", "1rem" → 16) or return null.
     */
    private function parse_px( string $v ): ?float {
        if ( preg_match( '/^(-?\d+(?:\.\d+)?)\s*px$/i', $v, $m ) ) {
            return (float) $m[1];
        }
        if ( preg_match( '/^(-?\d+(?:\.\d+)?)\s*rem$/i', $v, $m ) ) {
            return (float) $m[1] * 16.0;
        }
        return null;
    }

    /**
     * Return the CSS variable prefix for a snap category.
     */
    private function category_prefix( string $category ): string {
        return match ( $category ) {
            'spacing'   => '--space-',
            'radius'    => '--radius',
            'font-size' => '--font-size',
            default     => '--',
        };
    }

    /**
     * Snap threshold for color distance (ΔE CIE76).
     * ΔE < 1 = imperceptible, < 10 = close, > 10 = clearly different.
     */
    private const COLOR_DELTA_E_THRESHOLD = 10.0;

    /**
     * Snap a raw color to the nearest site color variable by perceptual distance.
     *
     * @param string                $value     Raw color (#hex, rgba, hsl) or var().
     * @param array<string, string> $site_vars Map of var name → color value.
     * @return array{snapped: ?string, delta_e: float, raw: string}
     */
    public function snap_color( string $value, array $site_vars ): array {
        $trimmed = trim( $value );

        if ( preg_match( '/^var\(\s*(--[a-z0-9-]+)\s*\)$/i', $trimmed, $m ) ) {
            return [ 'snapped' => 'var(' . $m[1] . ')', 'delta_e' => 0.0, 'raw' => $value ];
        }

        $rgb = $this->parse_rgb( $trimmed );
        if ( null === $rgb ) {
            return [ 'snapped' => null, 'delta_e' => 999.0, 'raw' => $value ];
        }
        $lab = $this->rgb_to_lab( $rgb );

        $best_name  = null;
        $best_delta = PHP_FLOAT_MAX;
        foreach ( $site_vars as $name => $raw ) {
            $var_rgb = $this->parse_rgb( $raw );
            if ( null === $var_rgb ) {
                continue;
            }
            $var_lab = $this->rgb_to_lab( $var_rgb );
            $delta   = $this->delta_e( $lab, $var_lab );
            if ( $delta < $best_delta ) {
                $best_delta = $delta;
                $best_name  = $name;
            }
        }

        if ( null === $best_name || $best_delta > self::COLOR_DELTA_E_THRESHOLD ) {
            return [ 'snapped' => null, 'delta_e' => round( $best_delta, 2 ), 'raw' => $value ];
        }

        return [ 'snapped' => 'var(' . $best_name . ')', 'delta_e' => round( $best_delta, 2 ), 'raw' => $value ];
    }

    /**
     * Parse hex/rgb/rgba into [r,g,b] 0-255 or null.
     */
    private function parse_rgb( string $v ): ?array {
        $v = strtolower( trim( $v ) );
        if ( preg_match( '/^#([0-9a-f]{6})$/i', $v, $m ) ) {
            return [
                hexdec( substr( $m[1], 0, 2 ) ),
                hexdec( substr( $m[1], 2, 2 ) ),
                hexdec( substr( $m[1], 4, 2 ) ),
            ];
        }
        if ( preg_match( '/^#([0-9a-f]{3})$/i', $v, $m ) ) {
            return [
                hexdec( str_repeat( $m[1][0], 2 ) ),
                hexdec( str_repeat( $m[1][1], 2 ) ),
                hexdec( str_repeat( $m[1][2], 2 ) ),
            ];
        }
        if ( preg_match( '/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $v, $m ) ) {
            return [ (int) $m[1], (int) $m[2], (int) $m[3] ];
        }
        return null;
    }

    /**
     * Convert RGB 0-255 to CIELAB (CIE76 distance input).
     */
    private function rgb_to_lab( array $rgb ): array {
        $linear = array_map( static function ( $c ) {
            $c /= 255.0;
            return $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
        }, $rgb );

        $x = $linear[0] * 0.4124 + $linear[1] * 0.3576 + $linear[2] * 0.1805;
        $y = $linear[0] * 0.2126 + $linear[1] * 0.7152 + $linear[2] * 0.0722;
        $z = $linear[0] * 0.0193 + $linear[1] * 0.1192 + $linear[2] * 0.9505;

        $xn = $x / 0.95047;
        $yn = $y / 1.00000;
        $zn = $z / 1.08883;

        $f = static function ( $t ) {
            return $t > 0.008856 ? pow( $t, 1 / 3 ) : 7.787 * $t + 16 / 116;
        };

        return [
            116 * $f( $yn ) - 16,
            500 * ( $f( $xn ) - $f( $yn ) ),
            200 * ( $f( $yn ) - $f( $zn ) ),
        ];
    }

    /**
     * CIE76 ΔE distance between two LAB colors.
     */
    private function delta_e( array $a, array $b ): float {
        return sqrt(
            pow( $a[0] - $b[0], 2 ) +
            pow( $a[1] - $b[1], 2 ) +
            pow( $a[2] - $b[2], 2 )
        );
    }

    /**
     * CSS property → snap category map.
     * Properties absent from this map are passed through as raw.
     */
    private const PROPERTY_CATEGORY = [
        '_padding'   => 'spacing',
        '_margin'    => 'spacing',
        '_gap'       => 'spacing',
        '_border'    => 'radius',
        '_radius'    => 'radius',
        '_font-size' => 'font-size',
        '_background' => 'color',
        '_color'     => 'color',
    ];

    /**
     * Walk a pattern tree, snap every raw value in style_tokens to site tokens.
     *
     * @param array<string, mixed>  $pattern  Pattern after content stripping.
     * @param array<string, string> $site_vars Map of var name → raw value.
     * @return array{pattern: array, snap_log: array, rejections: array}
     */
    public function tokenize( array $pattern, array $site_vars ): array {
        $snap_log   = [];
        $rejections = [];

        if ( isset( $pattern['structure'] ) && is_array( $pattern['structure'] ) ) {
            $pattern['structure'] = $this->walk_tokenize(
                $pattern['structure'],
                $site_vars,
                'structure',
                $snap_log,
                $rejections
            );
        }

        return [
            'pattern'    => $pattern,
            'snap_log'   => $snap_log,
            'rejections' => $rejections,
        ];
    }

    /**
     * Recursive walker for tokenize().
     */
    private function walk_tokenize( array $node, array $site_vars, string $path, array &$snap_log, array &$rejections ): array {
        if ( isset( $node['style_tokens'] ) && is_array( $node['style_tokens'] ) ) {
            $node['style_tokens'] = $this->snap_style_tokens(
                $node['style_tokens'],
                $site_vars,
                $path . '.style_tokens',
                $snap_log,
                $rejections
            );
        }
        if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
            foreach ( $node['children'] as $i => $child ) {
                if ( is_array( $child ) ) {
                    $node['children'][ $i ] = $this->walk_tokenize(
                        $child,
                        $site_vars,
                        $path . '.children[' . $i . ']',
                        $snap_log,
                        $rejections
                    );
                }
            }
        }
        return $node;
    }

    /**
     * Apply snap recursively to a style_tokens subtree.
     * String leaf-level values are snap candidates; arrays recurse.
     */
    private function snap_style_tokens( array $tokens, array $site_vars, string $path, array &$snap_log, array &$rejections ): array {
        foreach ( $tokens as $key => $value ) {
            $category = self::PROPERTY_CATEGORY[ $key ] ?? null;
            $sub_path = $path . '.' . $key;

            if ( is_array( $value ) ) {
                // Recurse with inherited category if child has no override.
                $tokens[ $key ] = $this->snap_style_tokens_inner( $value, $site_vars, $sub_path, $snap_log, $rejections, $category );
                continue;
            }

            if ( ! is_string( $value ) || $category === null ) {
                continue;
            }

            $result = $category === 'color'
                ? $this->snap_color( $value, $this->filter_color_vars( $site_vars ) )
                : $this->snap_value( $value, $site_vars, $category );

            if ( $result['snapped'] !== null ) {
                $tokens[ $key ] = $result['snapped'];
                $delta_key = $category === 'color' ? 'delta_e' : 'delta_pct';
                $snap_log[] = [
                    'path'     => $sub_path,
                    'raw'      => $result['raw'],
                    'snapped'  => $result['snapped'],
                    $delta_key => $result[ $delta_key ],
                ];
            } else {
                $rejections[] = [
                    'path'     => $sub_path,
                    'value'    => $value,
                    'category' => $category,
                    'reason'   => 'no_snap_candidate_within_threshold',
                ];
            }
        }
        return $tokens;
    }

    /**
     * Inner recursive snap that inherits category from parent property
     * (e.g. _padding → spacing applies to .top, .right, .bottom, .left leaves).
     */
    private function snap_style_tokens_inner( array $tokens, array $site_vars, string $path, array &$snap_log, array &$rejections, ?string $inherited_category ): array {
        foreach ( $tokens as $key => $value ) {
            $category = self::PROPERTY_CATEGORY[ $key ] ?? $inherited_category;
            $sub_path = $path . '.' . $key;

            if ( is_array( $value ) ) {
                $tokens[ $key ] = $this->snap_style_tokens_inner( $value, $site_vars, $sub_path, $snap_log, $rejections, $category );
                continue;
            }

            if ( ! is_string( $value ) || $category === null ) {
                continue;
            }

            $result = $category === 'color'
                ? $this->snap_color( $value, $this->filter_color_vars( $site_vars ) )
                : $this->snap_value( $value, $site_vars, $category );

            if ( $result['snapped'] !== null ) {
                $tokens[ $key ] = $result['snapped'];
                $delta_key = $category === 'color' ? 'delta_e' : 'delta_pct';
                $snap_log[] = [
                    'path'     => $sub_path,
                    'raw'      => $result['raw'],
                    'snapped'  => $result['snapped'],
                    $delta_key => $result[ $delta_key ],
                ];
            } else {
                $rejections[] = [
                    'path'     => $sub_path,
                    'value'    => $value,
                    'category' => $category,
                    'reason'   => 'no_snap_candidate_within_threshold',
                ];
            }
        }
        return $tokens;
    }

    /**
     * Filter a site_vars map to only color-valued variables.
     */
    private function filter_color_vars( array $site_vars ): array {
        $out = [];
        foreach ( $site_vars as $name => $value ) {
            if ( $this->parse_rgb( $value ) !== null ) {
                $out[ $name ] = $value;
            }
        }
        return $out;
    }

    /**
     * Validate and transform a pattern input through the full pipeline.
     *
     * @param array<string, mixed> $input Raw pattern input.
     * @return array<string, mixed> Either a valid pattern or an error structure.
     */
    public function validate( array $input ): array {
        if ( empty( $input ) ) {
            return [
                'error' => 'empty_input',
                'message' => 'Pattern input is empty.',
            ];
        }

        // Placeholder — filled in by subsequent tasks.
        return $input;
    }
}
