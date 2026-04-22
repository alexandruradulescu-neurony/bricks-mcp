<?php
/**
 * Class dedup engine.
 *
 * Computes style signatures and resolves class_intent against existing
 * BEM classes in the pattern library + current site. Non-BEM classes are
 * NEVER auto-reused (G1 policy) — they must be referenced explicitly by name.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ClassDedupEngine {

    /**
     * Compute a deterministic signature for a style_tokens tree.
     *
     * Canonicalizes by sorting keys recursively + dropping empty arrays + normalizing whitespace.
     */
    public function signature( array $style_tokens ): string {
        $canon = $this->canonicalize( $style_tokens );
        return 'sig:' . substr( hash( 'sha256', wp_json_encode( $canon ) ), 0, 16 );
    }

    private function canonicalize( $value ) {
        if ( ! is_array( $value ) ) {
            if ( is_string( $value ) ) {
                return preg_replace( '/\s+/', ' ', trim( $value ) );
            }
            return $value;
        }
        if ( empty( $value ) ) {
            return null;
        }
        $out  = [];
        $keys = array_keys( $value );
        sort( $keys );
        foreach ( $keys as $k ) {
            $canon = $this->canonicalize( $value[ $k ] );
            if ( $canon !== null && $canon !== '' ) {
                $out[ $k ] = $canon;
            }
        }
        return empty( $out ) ? null : $out;
    }
}
