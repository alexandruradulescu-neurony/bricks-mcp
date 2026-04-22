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

        // Required-role check (Rule C - required variant).
        $missing_required = $this->check_required_roles( $structure, $content_map );
        if ( ! empty( $missing_required ) ) {
            return [
                'error'          => 'missing_required_role',
                'message'        => 'Pattern has required roles not supplied in content_map.',
                'missing_roles'  => $missing_required,
            ];
        }

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
        $structure = $this->expand_repeats( $structure, $content_map, $log );
        $structure = $this->insert_extras( $structure, $content_map, $pattern_roles, $log );
        $structure = $this->drop_missing_optional( $structure, $content_map, $log );

        return [
            'structure'      => $structure,
            'adaptation_log' => $log,
        ];
    }

    /**
     * Walk pattern tree and return a list of roles marked required:true that are absent from content_map.
     */
    private function check_required_roles( array $node, array $content_map ): array {
        $missing = [];
        $walk = static function ( $n ) use ( &$walk, &$missing, $content_map ) {
            if ( ! empty( $n['required'] ) && isset( $n['role'] ) ) {
                if ( ! array_key_exists( $n['role'], $content_map ) ) {
                    $missing[] = $n['role'];
                }
            }
            foreach ( $n as $v ) {
                if ( is_array( $v ) ) {
                    $walk( $v );
                }
            }
        };
        $walk( $node );
        return $missing;
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

    /**
     * Insert content roles that have no structural home into sensible positions.
     *
     * Uses INSERTION_RULES() map. New element inherits type, class_refs, style_tokens
     * from nearest semantic sibling (same type, same parent). If no sibling of same
     * type exists, inherits from the anchor element itself.
     */
    private function insert_extras( array $node, array $content_map, array $pattern_roles, array &$log ): array {
        $rules = $this->INSERTION_RULES();
        foreach ( array_keys( $content_map ) as $role ) {
            if ( in_array( $role, $pattern_roles, true ) ) {
                continue;
            }

            if ( isset( $rules[ $role ] ) ) {
                $node = $this->insert_by_rule( $node, $role, $rules[ $role ], $log );
                $pattern_roles[] = $role;
                continue;
            }

            if ( preg_match( '/^cta_/', $role ) ) {
                $node = $this->append_to_cta_row( $node, $role, $log );
                $pattern_roles[] = $role;
                continue;
            }

            $node = $this->append_to_last_container( $node, $role, $log );
            $pattern_roles[] = $role;
        }
        return $node;
    }

    /**
     * Execute an insertion rule like "before:heading_main" or "after:heading_main".
     */
    private function insert_by_rule( array $node, string $role, string $rule, array &$log ): array {
        [ $position, $anchor_role ] = explode( ':', $rule );

        if ( ! isset( $node['children'] ) || ! is_array( $node['children'] ) ) {
            return $node;
        }

        $new_children = [];
        foreach ( $node['children'] as $child ) {
            if ( is_array( $child ) && ( $child['role'] ?? '' ) === $anchor_role ) {
                $injected = $this->build_inserted_element( $role, $child );
                if ( $position === 'before' ) {
                    $new_children[] = $injected;
                    $new_children[] = $child;
                } else {
                    $new_children[] = $child;
                    $new_children[] = $injected;
                }
                $log[] = sprintf( 'Pattern missing "%s" role, content supplied one → inserted %s %s.', $role, $position, $anchor_role );
            } else {
                $new_children[] = is_array( $child ) ? $this->insert_by_rule( $child, $role, $rule, $log ) : $child;
            }
        }
        $node['children'] = $new_children;
        return $node;
    }

    /**
     * Append a cta_* element to a cta_row wrapper. Creates the wrapper if missing
     * (placed after subtitle, else after heading_main, else appended).
     */
    private function append_to_cta_row( array $node, string $role, array &$log ): array {
        if ( ! isset( $node['children'] ) || ! is_array( $node['children'] ) ) {
            return $node;
        }

        $found = $this->find_and_append_in_role( $node, 'cta_row', $role );
        if ( $found['found'] ) {
            $log[] = sprintf( 'Pattern missing "%s", appended to existing cta_row.', $role );
            return $found['node'];
        }

        $new_child = $this->build_inserted_element( $role, null );
        $cta_row   = [ 'type' => 'block', 'role' => 'cta_row', 'children' => [ $new_child ] ];

        $children = $node['children'];
        $insert_at = count( $children );
        foreach ( $children as $i => $c ) {
            if ( is_array( $c ) && in_array( ( $c['role'] ?? '' ), [ 'subtitle', 'heading_main' ], true ) ) {
                $insert_at = $i + 1;
            }
        }
        array_splice( $children, $insert_at, 0, [ $cta_row ] );
        $node['children'] = $children;
        $log[] = sprintf( 'Pattern missing cta_row, created with "%s" inside.', $role );
        return $node;
    }

    /**
     * Find the first descendant with a given role and append a child to it.
     */
    private function find_and_append_in_role( array $node, string $target_role, string $append_role ): array {
        if ( ! isset( $node['children'] ) || ! is_array( $node['children'] ) ) {
            return [ 'found' => false, 'node' => $node ];
        }
        foreach ( $node['children'] as $i => $child ) {
            if ( ! is_array( $child ) ) {
                continue;
            }
            if ( ( $child['role'] ?? '' ) === $target_role ) {
                $new_child                  = $this->build_inserted_element( $append_role, $child['children'][0] ?? null );
                $child['children']          = array_merge( $child['children'] ?? [], [ $new_child ] );
                $node['children'][ $i ]     = $child;
                return [ 'found' => true, 'node' => $node ];
            }
            $result = $this->find_and_append_in_role( $child, $target_role, $append_role );
            if ( $result['found'] ) {
                $node['children'][ $i ] = $result['node'];
                return [ 'found' => true, 'node' => $node ];
            }
        }
        return [ 'found' => false, 'node' => $node ];
    }

    /**
     * Append an unknown-role element to the deepest container-like child of root.
     */
    private function append_to_last_container( array $node, string $role, array &$log ): array {
        if ( ! isset( $node['children'] ) || ! is_array( $node['children'] ) || empty( $node['children'] ) ) {
            return $node;
        }
        $last_idx = count( $node['children'] ) - 1;
        $last     = $node['children'][ $last_idx ];
        if ( is_array( $last ) && isset( $last['children'] ) ) {
            $last['children'][] = $this->build_inserted_element( $role, null );
            $node['children'][ $last_idx ] = $last;
        } else {
            $node['children'][] = $this->build_inserted_element( $role, null );
        }
        $log[] = sprintf( 'Content had unknown role "%s", appended to last container.', $role );
        return $node;
    }

    /**
     * Build a new element for an inserted role, inheriting from a sibling if given.
     */
    private function build_inserted_element( string $role, ?array $sibling ): array {
        $elem = [ 'role' => $role ];
        $role_defaults = [
            'eyebrow'  => [ 'type' => 'text-basic' ],
            'subtitle' => [ 'type' => 'text-basic' ],
        ];
        if ( preg_match( '/^cta_/', $role ) ) {
            $elem['type'] = 'button';
        } elseif ( isset( $role_defaults[ $role ] ) ) {
            $elem += $role_defaults[ $role ];
        } else {
            $elem['type'] = 'text-basic';
        }

        if ( $sibling && ( $sibling['type'] ?? '' ) === $elem['type'] ) {
            if ( isset( $sibling['class_refs'] ) ) {
                $elem['class_refs'] = $sibling['class_refs'];
            }
            if ( isset( $sibling['style_tokens'] ) ) {
                $elem['style_tokens'] = $sibling['style_tokens'];
            }
        }
        return $elem;
    }

    /**
     * Remove elements from the structure whose role is not present in content_map
     * (and are not marked required). Runs recursively.
     */
    private function drop_missing_optional( array $node, array $content_map, array &$log ): array {
        if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
            $kept = [];
            foreach ( $node['children'] as $child ) {
                if ( ! is_array( $child ) ) {
                    $kept[] = $child;
                    continue;
                }
                $role = $child['role'] ?? null;
                if ( $role !== null && ! array_key_exists( $role, $content_map ) && empty( $child['required'] ) ) {
                    $log[] = sprintf( 'Pattern had optional role "%s", content omitted → element dropped.', $role );
                    continue;
                }
                $kept[] = $this->drop_missing_optional( $child, $content_map, $log );
            }
            $node['children'] = $kept;
        }
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
