<?php
declare(strict_types=1);
namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Validates and auto-fixes Bricks style settings shape.
 *
 * Many Bricks settings have specific shape requirements (e.g. _border.style
 * must be a string, _typography.color must be a {raw|hex} object). When the
 * pipeline produces malformed shapes, Bricks crashes with "Array to string
 * conversion" or silently drops the style. This service catches and
 * auto-fixes the common shape mismatches before write.
 */
final class StyleShapeValidator {

    /**
     * Validate + auto-fix a styles array. Recursively walks nested style groups
     * (theme styles have multiple groups; element settings are flat).
     *
     * @param array<string,mixed> $styles Style settings to validate.
     * @return array{styles: array<string,mixed>, warnings: string[]}
     */
    public static function validate_and_fix( array $styles ): array {
        $warnings = [];

        // Rule 1: _border.style must be string (not per-side object).
        if ( isset( $styles['_border']['style'] ) && is_array( $styles['_border']['style'] ) ) {
            $first = self::first_string_value( $styles['_border']['style'], 'solid' );
            $styles['_border']['style'] = $first;
            $warnings[] = sprintf( 'Auto-fixed: _border.style was a per-side object; collapsed to string "%s".', $first );
        }

        // Rule 2: _border.color string -> {raw: ...} object.
        if ( isset( $styles['_border']['color'] ) && is_string( $styles['_border']['color'] ) ) {
            $styles['_border']['color'] = self::wrap_color( $styles['_border']['color'] );
            $warnings[] = 'Auto-fixed: _border.color was a string; wrapped in {raw} object.';
        }

        // Rule 3: _border.width string -> per-side object.
        if ( isset( $styles['_border']['width'] ) && is_string( $styles['_border']['width'] ) ) {
            $w = $styles['_border']['width'];
            $styles['_border']['width'] = [ 'top' => $w, 'right' => $w, 'bottom' => $w, 'left' => $w ];
            $warnings[] = 'Auto-fixed: _border.width was a flat string; expanded to per-side object.';
        }

        // Rule 4: _cssCustom array -> string (if it slipped through).
        if ( isset( $styles['_cssCustom'] ) && is_array( $styles['_cssCustom'] ) ) {
            $styles['_cssCustom'] = implode( "\n", array_filter( $styles['_cssCustom'], 'is_string' ) );
            $warnings[] = 'Auto-fixed: _cssCustom was an array; joined to string with newlines.';
        }

        // Rule 5: _typography.color string -> {raw|hex} object.
        if ( isset( $styles['_typography']['color'] ) && is_string( $styles['_typography']['color'] ) ) {
            $styles['_typography']['color'] = self::wrap_color( $styles['_typography']['color'] );
            $warnings[] = 'Auto-fixed: _typography.color was a string; wrapped in color object.';
        }

        // Rule 6: _background.color string -> object.
        if ( isset( $styles['_background']['color'] ) && is_string( $styles['_background']['color'] ) ) {
            $styles['_background']['color'] = self::wrap_color( $styles['_background']['color'] );
            $warnings[] = 'Auto-fixed: _background.color was a string; wrapped in color object.';
        }

        // Rule 7: top-level _color string -> object.
        if ( isset( $styles['_color'] ) && is_string( $styles['_color'] ) ) {
            $styles['_color'] = self::wrap_color( $styles['_color'] );
            $warnings[] = 'Auto-fixed: _color was a string; wrapped in color object.';
        }

        // Recurse into nested style groups (for theme styles).
        foreach ( $styles as $key => $value ) {
            if ( is_array( $value ) && self::looks_like_nested_group( $value ) ) {
                $nested = self::validate_and_fix( $value );
                $styles[ $key ] = $nested['styles'];
                $warnings = array_merge( $warnings, $nested['warnings'] );
            }
        }

        return [ 'styles' => $styles, 'warnings' => $warnings ];
    }

    /**
     * Wrap a color string in Bricks' expected {raw|hex} object format.
     *
     * @param string $value Raw color string.
     * @return array<string, string> Bricks color object.
     */
    private static function wrap_color( string $value ): array {
        // Hex codes use {hex: ...}; everything else (var(), rgb, named) uses {raw: ...}.
        if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ) {
            return [ 'hex' => $value ];
        }
        return [ 'raw' => $value ];
    }

    /**
     * Pick the first non-empty string value from an array, or fallback.
     *
     * @param array<string, mixed> $arr      Array to search.
     * @param string               $fallback Fallback value.
     * @return string First non-empty string value found, or fallback.
     */
    private static function first_string_value( array $arr, string $fallback ): string {
        $top = $arr['top'] ?? '';
        if ( is_string( $top ) && '' !== $top ) {
            return $top;
        }
        foreach ( $arr as $v ) {
            if ( is_string( $v ) && '' !== $v ) {
                return $v;
            }
        }
        return $fallback;
    }

    /**
     * Heuristic: a nested style group is an array WITHOUT Bricks' known
     * shape-sensitive keys (width/style/color/radius). Used to recurse only
     * into theme-style group containers, not into _border or _padding objects.
     *
     * @param array<string, mixed> $value Array to check.
     * @return bool True if this looks like a nested style group.
     */
    private static function looks_like_nested_group( array $value ): bool {
        return ! isset( $value['width'], $value['style'], $value['color'], $value['radius'], $value['raw'], $value['hex'] );
    }
}
