<?php
/**
 * Pattern → Schema bridge.
 *
 * Converts an adapted pattern tree (output of PatternAdapter::adapt) into
 * a schema tree in the shape BuildHandler / DesignSchemaValidator expect.
 * Strips pattern-only fields (required, repeat — already consumed by adapter),
 * renames style_tokens → style_overrides, collapses class_refs[] to the first
 * name as class_intent.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PatternToSchemaBridge {

    private const STRUCTURAL_TYPES = [ 'section', 'container', 'block', 'div' ];

    /**
     * @param array<string, array<string, mixed>> $known_classes
     * @param array<string, array<string, mixed>> $style_roles
     * @param array<string, array<string, mixed>> $component_classes
     */
    public function __construct(
        private array $known_classes = [],
        private array $style_roles = [],
        private array $component_classes = []
    ) {}

    /**
     * Convert an adapted pattern to a full schema.
     *
     * @param array $adapted_pattern Output of PatternAdapter::adapt().
     *                               Has `structure` key at top level.
     * @param array $meta            page_id, pattern_id, action?, background?
     * @return array { schema, conversion_log }
     */
    public function pattern_to_schema( array $adapted_pattern, array $meta ): array {
        $log = [ 'dropped_keys' => [], 'class_refs_collapsed' => [] ];
        $structure = $this->convert_node( $adapted_pattern['structure'] ?? [], 'structure', $log );

        $schema = [
            'target' => [
                'page_id' => $meta['page_id'] ?? 0,
                'action'  => $meta['action'] ?? 'append',
            ],
            'design_context' => [
                'summary' => 'pattern ' . ( $meta['pattern_id'] ?? 'unknown' ),
                'spacing' => 'normal',
            ],
            'sections' => [[
                'intent'     => 'use_pattern:' . ( $meta['pattern_id'] ?? 'unknown' ),
                'structure'  => $structure,
                'background' => $meta['background'] ?? 'light',
            ]],
        ];

        return [ 'schema' => $schema, 'conversion_log' => $log ];
    }

    /**
     * Recursively convert a pattern structure node to a schema node.
     */
    private function convert_node( array $node, string $path, array &$log ): array {
        $out = [];

        if ( isset( $node['type'] ) ) {
            $out['type'] = $node['type'];
        }
        if ( isset( $node['role'] ) && is_string( $node['role'] ) && $node['role'] !== '' ) {
            $normalized_role = DesignPlanNormalizationService::normalize_role_key( $node['role'] );
            $out['role']     = '' !== $normalized_role ? $normalized_role : $node['role'];
            if ( $out['role'] !== $node['role'] ) {
                $log['roles_normalized'][ $path . '.role' ] = [
                    'from' => $node['role'],
                    'to'   => $out['role'],
                ];
            }
        }
        if ( isset( $node['tag'] ) ) {
            $out['tag'] = $node['tag'];
        }

        // style_tokens → style_overrides (rename only, passthrough payload).
        if ( isset( $node['style_tokens'] ) && is_array( $node['style_tokens'] ) ) {
            $out['style_overrides'] = $node['style_tokens'];
        }

        $resolved_intent = $this->resolve_class_intent( $node, $out['role'] ?? '', $path, $log );
        if ( null !== $resolved_intent ) {
            $out['class_intent'] = $resolved_intent;
        }

        // Inject label when structural + has role.
        $type = $out['type'] ?? '';
        $role = $out['role'] ?? '';
        if ( $role !== '' && in_array( $type, self::STRUCTURAL_TYPES, true ) ) {
            $out['label'] = $role;
        }

        // Recurse children.
        if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
            $out['children'] = [];
            foreach ( $node['children'] as $idx => $child ) {
                if ( is_array( $child ) ) {
                    $out['children'][] = $this->convert_node( $child, $path . '.children[' . $idx . ']', $log );
                }
            }
        }

        // Log anything we dropped from the pattern node.
        $kept_keys = [ 'type', 'role', 'tag', 'class_refs', 'style_tokens', 'children', 'required', 'repeat' ];
        foreach ( $node as $key => $value ) {
            if ( ! in_array( $key, $kept_keys, true ) ) {
                $log['dropped_keys'][] = $path . '.' . $key;
            }
        }

        return $out;
    }

    /**
     * Resolve the best class intent for a bridged pattern node.
     */
    private function resolve_class_intent( array $node, string $role, string $path, array &$log ): ?string {
        $refs            = isset( $node['class_refs'] ) && is_array( $node['class_refs'] )
            ? array_values( array_filter( $node['class_refs'], 'is_string' ) )
            : [];
        $has_inline_style = isset( $node['style_tokens'] ) && is_array( $node['style_tokens'] ) && ! empty( $node['style_tokens'] );

        if ( ! empty( $refs ) ) {
            foreach ( $refs as $index => $ref ) {
                if ( DesignPlanNormalizationService::is_invalid_class_intent( $ref ) ) {
                    continue;
                }

                if ( $this->class_exists( $ref ) ) {
                    if ( $index > 0 ) {
                        $log['class_refs_collapsed'][ $path . '.class_refs' ] = [
                            'kept'           => $ref,
                            'extras_dropped' => array_values( array_diff( $refs, [ $ref ] ) ),
                            'reason'         => 'preferred_existing_class',
                        ];
                    } elseif ( count( $refs ) > 1 ) {
                        $log['class_refs_collapsed'][ $path . '.class_refs' ] = [
                            'kept'           => $ref,
                            'extras_dropped' => array_slice( $refs, 1 ),
                        ];
                    }
                    return $ref;
                }
            }

            if ( $has_inline_style && ! DesignPlanNormalizationService::is_invalid_class_intent( $refs[0] ) ) {
                if ( count( $refs ) > 1 ) {
                    $log['class_refs_collapsed'][ $path . '.class_refs' ] = [
                        'kept'           => $refs[0],
                        'extras_dropped' => array_slice( $refs, 1 ),
                        'reason'         => 'kept_pattern_specific_class_with_inline_styles',
                    ];
                }
                return $refs[0];
            }
        }

        $fallback = $this->semantic_fallback_class_for_role( $role );
        if ( null !== $fallback ) {
            if ( ! empty( $refs ) ) {
                $log['class_refs_remapped'][ $path . '.class_refs' ] = [
                    'from'   => $refs[0],
                    'to'     => $fallback,
                    'reason' => 'semantic_role_fallback',
                ];
            }
            return $fallback;
        }

        if ( ! empty( $refs ) ) {
            $log['class_refs_dropped'][ $path . '.class_refs' ] = [
                'dropped' => $refs,
                'reason'  => 'unknown_class_without_inline_styles',
            ];
        }

        return null;
    }

    private function semantic_fallback_class_for_role( string $role ): ?string {
        $semantic_role = DesignPlanNormalizationService::infer_semantic_component_role( $role );
        if ( null === $semantic_role ) {
            return null;
        }

        $resolution = $this->style_roles[ $semantic_role ] ?? null;
        if ( is_array( $resolution ) && ( $resolution['status'] ?? '' ) === 'resolved' && ! empty( $resolution['class_name'] ) ) {
            return (string) $resolution['class_name'];
        }

        $definition = $this->component_classes[ $semantic_role ] ?? null;
        if ( is_array( $definition ) && ! empty( $definition['name'] ) ) {
            return (string) $definition['name'];
        }

        return null;
    }

    private function class_exists( string $name ): bool {
        if ( isset( $this->known_classes[ $name ] ) ) {
            return true;
        }

        $normalized = strtolower( trim( $name ) );
        foreach ( array_keys( $this->known_classes ) as $existing_name ) {
            if ( strtolower( trim( (string) $existing_name ) ) === $normalized ) {
                return true;
            }
        }

        return false;
    }
}
