<?php
/**
 * BEM class normalizer.
 *
 * Parses and normalizes class_intent input (structured object or loose string)
 * into a canonical BEM class name: block[--modifier][__element].
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BEMClassNormalizer {

    /**
     * BEM validation regex.
     *
     * Block must be a single identifier (no hyphens) so hyphenated-only names
     * like `btn-b2b-primary` are rejected as legacy. Modifiers and elements
     * may contain internal hyphens (e.g. `cta--accent__primary-button`).
     */
    public const BEM_REGEX = '/^[a-z][a-z0-9]*(--[a-z][a-z0-9-]*)*(__[a-z][a-z0-9-]*)?$/';

    /**
     * Normalize any class_intent input into BEM form.
     *
     * @param string|array $input Structured object or loose string.
     * @return string Normalized BEM class name.
     */
    public function normalize( $input ): string {
        if ( is_array( $input ) ) {
            return $this->from_structured( $input );
        }
        if ( is_string( $input ) ) {
            return $this->from_string( $input );
        }
        return '';
    }

    private function from_structured( array $parts ): string {
        $block    = $this->kebab( (string) ( $parts['block'] ?? '' ) );
        $modifier = $this->kebab( (string) ( $parts['modifier'] ?? '' ) );
        $element  = $this->kebab( (string) ( $parts['element'] ?? '' ) );
        if ( $block === '' ) {
            return '';
        }
        $out = $block;
        if ( $modifier !== '' ) {
            $out .= '--' . $modifier;
        }
        if ( $element !== '' ) {
            $out .= '__' . $element;
        }
        return $out;
    }

    private function from_string( string $s ): string {
        $s = trim( $s );
        if ( $s === '' ) {
            return '';
        }

        // Already BEM? Pass through.
        if ( preg_match( self::BEM_REGEX, $s ) ) {
            return $s;
        }

        // Contains `--` or `__`? Treat as BEM-shaped but normalize spacing/case.
        if ( strpos( $s, '--' ) !== false || strpos( $s, '__' ) !== false ) {
            return strtolower( preg_replace( '/\s+/', '', $s ) ?? '' );
        }

        // Positional split on whitespace (preserve original casing so kebab() can
        // detect PascalCase word boundaries before lowercasing).
        $parts = preg_split( '/\s+/', $s );
        $parts = array_values( array_filter( $parts, static fn( $p ) => $p !== '' ) );

        if ( count( $parts ) === 0 ) {
            return '';
        }
        if ( count( $parts ) === 1 ) {
            return $this->kebab( $parts[0] );
        }
        // For multi-word inputs there is no PascalCase to detect (words are already
        // separated by spaces), so lowercase before passing to kebab().
        $parts = array_map( 'strtolower', $parts );
        if ( count( $parts ) === 1 ) {
            return $this->kebab( $parts[0] );
        }
        if ( count( $parts ) === 2 ) {
            return $this->kebab( $parts[0] ) . '__' . $this->kebab( $parts[1] );
        }
        // 3+ words: block modifier element (excess words collapsed into element).
        $block    = $this->kebab( $parts[0] );
        $modifier = $this->kebab( $parts[1] );
        $element  = $this->kebab( implode( '-', array_slice( $parts, 2 ) ) );
        return $block . '--' . $modifier . '__' . $element;
    }

    /** Kebab-case conversion: PascalCase or spaces/underscores → hyphens. */
    private function kebab( string $s ): string {
        $s = preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $s );
        $s = strtolower( (string) $s );
        $s = preg_replace( '/[\s_]+/', '-', $s );
        $s = preg_replace( '/-+/', '-', (string) $s );
        return trim( (string) $s, '-' );
    }

    /** Check whether a string matches BEM grammar. */
    public function is_valid( string $class_name ): bool {
        return (bool) preg_match( self::BEM_REGEX, $class_name );
    }

    /**
     * Classify a class name as 'bem' or 'legacy'.
     *
     * Legacy = any non-BEM-valid name on the site. Used by dedup engine
     * to skip non-BEM classes from auto-reuse pool (G1 policy).
     */
    public function classify( string $class_name ): string {
        return $this->is_valid( $class_name ) ? 'bem' : 'legacy';
    }
}
