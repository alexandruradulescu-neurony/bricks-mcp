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

    public function __construct( private ?BEMClassNormalizer $normalizer = null ) {
        $this->normalizer = $normalizer ?? new BEMClassNormalizer();
    }

    /**
     * Compute a deterministic signature for a style_tokens tree.
     *
     * Canonicalizes by sorting keys recursively + dropping empty arrays + normalizing whitespace.
     */
    public function signature( array $style_tokens ): string {
        $canon = $this->canonicalize( $style_tokens );
        return 'sig:' . substr( hash( 'sha256', wp_json_encode( $canon ) ), 0, 16 );
    }

    /**
     * Look up a class in a pool whose style signature matches the candidate's.
     *
     * @param array                 $candidate_tokens Style tokens to match.
     * @param array<string, array>  $pool             Map of class_name => style_tokens.
     * @return string|null Matching class name, or null.
     */
    public function find_match( array $candidate_tokens, array $pool ): ?string {
        $candidate_sig = $this->signature( $candidate_tokens );
        foreach ( $pool as $name => $tokens ) {
            if ( ! is_array( $tokens ) ) {
                continue;
            }
            if ( $this->signature( $tokens ) === $candidate_sig ) {
                return (string) $name;
            }
        }
        return null;
    }

    /**
     * Variant of find_match that only considers BEM-named entries.
     *
     * Legacy (non-BEM) classes are silently skipped — they can be referenced
     * by explicit class_intent, but the dedup engine will never reuse them
     * by style signature alone (G1 policy).
     */
    public function find_bem_match( array $candidate_tokens, array $pool ): ?string {
        $candidate_sig = $this->signature( $candidate_tokens );
        foreach ( $pool as $name => $tokens ) {
            if ( ! is_array( $tokens ) || $this->normalizer->classify( (string) $name ) !== 'bem' ) {
                continue;
            }
            if ( $this->signature( $tokens ) === $candidate_sig ) {
                return (string) $name;
            }
        }
        return null;
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
