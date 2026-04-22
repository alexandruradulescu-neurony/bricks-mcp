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
            $out['role'] = $node['role'];
        }
        if ( isset( $node['tag'] ) ) {
            $out['tag'] = $node['tag'];
        }

        // class_refs[] → class_intent (take first, log rest).
        if ( isset( $node['class_refs'] ) && is_array( $node['class_refs'] ) && ! empty( $node['class_refs'] ) ) {
            $refs = array_values( array_filter( $node['class_refs'], 'is_string' ) );
            if ( ! empty( $refs ) ) {
                $out['class_intent'] = $refs[0];
                if ( count( $refs ) > 1 ) {
                    $log['class_refs_collapsed'][ $path . '.class_refs' ] = [
                        'kept'           => $refs[0],
                        'extras_dropped' => array_slice( $refs, 1 ),
                    ];
                }
            }
        }

        // style_tokens → style_overrides (rename only, passthrough payload).
        if ( isset( $node['style_tokens'] ) && is_array( $node['style_tokens'] ) ) {
            $out['style_overrides'] = $node['style_tokens'];
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
}
