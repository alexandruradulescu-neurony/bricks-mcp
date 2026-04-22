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
     * Walk a pattern tree and collect every class name referenced in class_refs arrays.
     *
     * @param array<string, mixed> $node Pattern tree.
     * @return array<int, string> Unique class names (unsorted).
     */
    public function collect_class_refs( array $node ): array {
        $names = [];
        $this->walk_collect_classes( $node, $names );
        return array_values( array_unique( $names ) );
    }

    private function walk_collect_classes( array $node, array &$names ): void {
        if ( isset( $node['class_refs'] ) && is_array( $node['class_refs'] ) ) {
            foreach ( $node['class_refs'] as $name ) {
                if ( is_string( $name ) && $name !== '' ) {
                    $names[] = $name;
                }
            }
        }
        foreach ( $node as $value ) {
            if ( is_array( $value ) ) {
                $this->walk_collect_classes( $value, $names );
            }
        }
    }

    /**
     * Walk a pattern tree and collect every --variable referenced in any string value.
     *
     * @param array<string, mixed> $node Pattern tree.
     * @return array<int, string> Unique variable names (unsorted, including leading --).
     */
    public function collect_variable_refs( array $node ): array {
        $names = [];
        $this->walk_collect_vars( $node, $names );
        return array_values( array_unique( $names ) );
    }

    private function walk_collect_vars( array $node, array &$names ): void {
        foreach ( $node as $value ) {
            if ( is_string( $value ) ) {
                if ( preg_match_all( '/var\(\s*(--[a-z0-9-]+)\s*\)/i', $value, $matches ) ) {
                    foreach ( $matches[1] as $name ) {
                        $names[] = $name;
                    }
                }
            } elseif ( is_array( $value ) ) {
                $this->walk_collect_vars( $value, $names );
            }
        }
    }

    /**
     * Compute a deterministic SHA-256 checksum over the pattern.
     *
     * Canonicalizes by sorting keys recursively before JSON encoding.
     * Excludes the `checksum` field itself if present.
     *
     * @param array<string, mixed> $pattern Pattern object.
     * @return string "sha256:<hex>".
     */
    public function checksum( array $pattern ): string {
        unset( $pattern['checksum'] );
        $canon = $this->canonicalize( $pattern );
        return 'sha256:' . hash( 'sha256', wp_json_encode( $canon ) );
    }

    private function canonicalize( $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }
        $out  = [];
        $keys = array_keys( $value );
        sort( $keys );
        foreach ( $keys as $k ) {
            $out[ $k ] = $this->canonicalize( $value[ $k ] );
        }
        return $out;
    }

    /**
     * Verify every class_refs and var(--*) in structure has a matching payload.
     *
     * @param array<string, mixed> $pattern Pattern with structure/classes/variables maps.
     * @return array<int, string> Error messages (empty = valid).
     */
    public function integrity_check( array $pattern ): array {
        $errors = [];

        $class_refs  = $this->collect_class_refs( $pattern['structure'] ?? [] );
        $classes_map = $pattern['classes'] ?? [];
        foreach ( $class_refs as $name ) {
            if ( ! isset( $classes_map[ $name ] ) ) {
                $errors[] = sprintf( 'class_ref "%s" referenced in structure has no matching entry in classes map.', $name );
            }
        }

        $var_refs = $this->collect_variable_refs( $pattern['structure'] ?? [] );
        $vars_map = $pattern['variables'] ?? [];
        foreach ( $var_refs as $name ) {
            if ( ! isset( $vars_map[ $name ] ) ) {
                $errors[] = sprintf( 'var(%s) referenced in structure has no matching entry in variables map.', $name );
            }
        }

        return $errors;
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

    /**
     * Compute BEM compliance metrics for a pattern.
     *
     * @param array $pattern Full pattern structure.
     * @return array{bem_purity: float, non_bem_classes: array, bem_migration_hints: array}
     */
    public function compute_bem_metadata( array $pattern ): array {
        $normalizer = new BEMClassNormalizer();
        $class_refs = $this->collect_class_refs( $pattern['structure'] ?? [] );
        if ( empty( $class_refs ) ) {
            return [ 'bem_purity' => 1.0, 'non_bem_classes' => [], 'bem_migration_hints' => [] ];
        }
        $bem_count = 0;
        $non_bem   = [];
        $hints     = [];

        $section_type = $pattern['category'] ?? 'generic';
        $variant      = $pattern['variant'] ?? ( $pattern['background'] ?? '' );

        foreach ( $class_refs as $name ) {
            if ( $normalizer->classify( $name ) === 'bem' ) {
                $bem_count++;
                continue;
            }
            $non_bem[] = $name;

            // Simple migration hint: strip common prefixes, construct BEM.
            $element_part = preg_replace( '/^(btn|b2b)[-_]/', '', $name );
            $hint_parts   = [ 'block' => $section_type ];
            if ( $variant !== '' ) {
                $hint_parts['modifier'] = $variant;
            }
            $hint_parts['element'] = $element_part;
            $hints[ $name ] = $normalizer->normalize( $hint_parts );
        }

        return [
            'bem_purity'          => round( $bem_count / count( $class_refs ), 2 ),
            'non_bem_classes'     => array_values( $non_bem ),
            'bem_migration_hints' => $hints,
        ];
    }

    /**
     * Full validation pipeline against a prepared site context.
     *
     * Pipeline order:
     *   1. Required field check (id, name, category)
     *   2. Strip content (§4A)
     *   3. Tokenize raw values (§4B)
     *   4. Build classes map from class_refs (§4C)
     *   5. Build variables map from var() refs (§4D)
     *   6. Integrity check (§4E)
     *   7. Compute checksum
     *
     * @param array<string, mixed> $input        Raw pattern input.
     * @param array<string, mixed> $site_context ['variables' => map, 'classes' => map].
     * @return array<string, mixed> Valid pattern OR {error, ...} structure.
     */
    public function validate_with_context( array $input, array $site_context ): array {
        foreach ( [ 'id', 'name', 'category' ] as $required ) {
            if ( empty( $input[ $required ] ) ) {
                return [
                    'error'   => 'missing_field',
                    'message' => sprintf( 'Pattern field "%s" is required.', $required ),
                ];
            }
        }

        $site_vars    = $site_context['variables'] ?? [];
        $site_classes = $site_context['classes'] ?? [];

        // 1. Strip content.
        $input = $this->strip_content( $input );

        // 2. Tokenize — need raw string values for site_vars map.
        $site_vars_values = [];
        foreach ( $site_vars as $name => $def ) {
            $site_vars_values[ $name ] = is_array( $def ) ? ( $def['value'] ?? '' ) : (string) $def;
        }
        $tokenize_result = $this->tokenize( $input, $site_vars_values );
        if ( ! empty( $tokenize_result['rejections'] ) ) {
            return [
                'error'      => 'hardcoded_values',
                'message'    => sprintf( 'Pattern has %d raw values that cannot be snapped to tokens.', count( $tokenize_result['rejections'] ) ),
                'issues'     => $tokenize_result['rejections'],
                'suggestion' => 'Edit the section to use existing tokens, or create new variables for these values.',
            ];
        }
        $input             = $tokenize_result['pattern'];
        $input['snap_log'] = $tokenize_result['snap_log'];

        // 3. Build classes map from referenced names.
        $class_refs = $this->collect_class_refs( $input['structure'] ?? [] );
        $input['classes'] = [];
        foreach ( $class_refs as $name ) {
            if ( ! isset( $site_classes[ $name ] ) ) {
                return [
                    'error'   => 'missing_class',
                    'message' => sprintf( 'Pattern references class "%s" which does not exist on this site.', $name ),
                ];
            }
            $input['classes'][ $name ] = $site_classes[ $name ];
        }

        // 4. Build variables map from referenced names.
        $var_refs = $this->collect_variable_refs( $input['structure'] ?? [] );
        $input['variables'] = [];
        foreach ( $var_refs as $name ) {
            if ( ! isset( $site_vars[ $name ] ) ) {
                return [
                    'error'   => 'missing_variable',
                    'message' => sprintf( 'Pattern references variable "%s" which does not exist on this site.', $name ),
                ];
            }
            $input['variables'][ $name ] = $site_vars[ $name ];
        }

        // 5. Integrity check.
        $integrity_errors = $this->integrity_check( $input );
        if ( ! empty( $integrity_errors ) ) {
            return [
                'error'   => 'integrity_failure',
                'message' => 'Pattern integrity check failed.',
                'issues'  => $integrity_errors,
            ];
        }

        // 6. Checksum.
        $input['checksum'] = $this->checksum( $input );

        return $input;
    }
}
