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

    /** BEM validation regex. */
    public const BEM_REGEX = '/^[a-z][a-z0-9-]*(--[a-z][a-z0-9-]*)*(__[a-z][a-z0-9-]*)?$/';

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
        return $this->kebab( strtolower( trim( $s ) ) );
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
}
