<?php
/**
 * Pattern adapter.
 *
 * Given a pattern + content_map, produces an adapted structure that fits
 * the content (insertions for extra roles, clones for repeating content,
 * drops for missing optional roles, rejection for gross shape mismatch).
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PatternAdapter {

    /** Reject build when this fraction of content roles have no structural home. */
    private const SHAPE_MISMATCH_THRESHOLD = 0.30;

    public function __construct( private ?PatternCatalog $catalog = null ) {}

    /**
     * Adapt a pattern's structure to fit a content_map.
     *
     * @param array<string, mixed> $pattern Full pattern (structure, classes, variables).
     * @param array<string, mixed> $content_map Role → content value map.
     * @return array{structure: array, adaptation_log: array, error?: string, ...}
     */
    public function adapt( array $pattern, array $content_map ): array {
        $structure = $pattern['structure'] ?? [];
        $pattern_roles = $this->collect_roles( $structure );

        // Shape mismatch gate (Rule D).
        $mismatch = $this->assess_shape_mismatch( array_keys( $content_map ), $pattern_roles );
        if ( $mismatch['fraction'] > self::SHAPE_MISMATCH_THRESHOLD ) {
            return [
                'error'              => 'shape_mismatch',
                'message'            => 'Pattern shape incompatible with content roles.',
                'incompatible_roles' => $mismatch['unmatched'],
                'fraction'           => round( $mismatch['fraction'], 2 ),
            ];
        }

        $log = [];

        // Rule B: expand repeats.
        $structure = $this->expand_repeats( $structure, $content_map, $log );

        // Rule A: insert extra roles.
        $structure = $this->insert_extras( $structure, $content_map, $pattern_roles, $log );

        // Rule C: drop optional roles not supplied.
        $structure = $this->drop_missing_optional( $structure, $content_map, $log );

        return [
            'structure'      => $structure,
            'adaptation_log' => $log,
        ];
    }

    /**
     * Walk pattern and collect every role referenced.
     */
    private function collect_roles( array $node ): array {
        $roles = [];
        $walk = static function ( $n ) use ( &$walk, &$roles ) {
            if ( isset( $n['role'] ) && is_string( $n['role'] ) ) {
                $roles[] = $n['role'];
            }
            foreach ( $n as $v ) {
                if ( is_array( $v ) ) {
                    $walk( $v );
                }
            }
        };
        $walk( $node );
        return array_values( array_unique( $roles ) );
    }

    /**
     * Calculate how many content roles have no structural home.
     */
    private function assess_shape_mismatch( array $content_roles, array $pattern_roles ): array {
        if ( empty( $content_roles ) ) {
            return [ 'fraction' => 0.0, 'unmatched' => [] ];
        }
        $insertable = array_keys( $this->INSERTION_RULES() );
        $unmatched = [];
        foreach ( $content_roles as $role ) {
            if ( in_array( $role, $pattern_roles, true ) ) {
                continue;
            }
            if ( in_array( $role, $insertable, true ) || preg_match( '/^cta_/', $role ) ) {
                continue;  // insertable via known rule
            }
            $unmatched[] = $role;
        }
        return [
            'fraction'  => count( $unmatched ) / count( $content_roles ),
            'unmatched' => $unmatched,
        ];
    }

    /**
     * Walk the tree; for each element with repeat:true, clone it N times where
     * N = length of content_map[role], and replace the single template in the
     * parent's children with the clone list.
     */
    private function expand_repeats( array $node, array $content_map, array &$log ): array {
        if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
            $new_children = [];
            foreach ( $node['children'] as $child ) {
                if ( is_array( $child ) && ! empty( $child['repeat'] ) ) {
                    $role  = $child['role'] ?? '';
                    $items = $content_map[ $role ] ?? [];
                    $count = is_array( $items ) ? count( $items ) : 0;

                    if ( $count === 0 ) {
                        $log[] = sprintf( 'Pattern had repeat:true on role "%s", content supplied 0 items → removed.', $role );
                        continue;
                    }

                    $template = $child;
                    unset( $template['repeat'] );
                    for ( $i = 0; $i < $count; $i++ ) {
                        $new_children[] = $this->expand_repeats( $template, $content_map, $log );
                    }
                    $log[] = sprintf( 'Pattern had repeat:true on role "%s", cloned to %d instances.', $role, $count );
                } else {
                    $new_children[] = is_array( $child ) ? $this->expand_repeats( $child, $content_map, $log ) : $child;
                }
            }
            $node['children'] = $new_children;
        }
        return $node;
    }

    /** Placeholder — filled in subsequent tasks. */
    private function insert_extras( array $node, array $content_map, array $pattern_roles, array &$log ): array {
        return $node;
    }

    /** Placeholder — filled in subsequent tasks. */
    private function drop_missing_optional( array $node, array $content_map, array &$log ): array {
        return $node;
    }

    /**
     * Role → structural insertion rule.
     *   'before:X' — insert as sibling immediately before element with role X
     *   'after:X'  — insert as sibling immediately after element with role X
     */
    private function INSERTION_RULES(): array {
        return [
            'eyebrow'  => 'before:heading_main',
            'subtitle' => 'after:heading_main',
            // cta_* handled by regex in assess_shape_mismatch
        ];
    }
}
