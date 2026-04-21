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
