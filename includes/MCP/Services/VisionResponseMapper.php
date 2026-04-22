<?php
/**
 * Vision response mapper.
 *
 * Parses tool_use output into either a pattern structure or a design_plan.
 * Runs class audit (exact-name match, signature-match via ClassDedupEngine)
 * and BEM normalization on new names. Optionally diffs against a
 * user-supplied reference_json.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class VisionResponseMapper {

    public function __construct(
        private ClassDedupEngine $dedup,
        private BEMClassNormalizer $bem
    ) {}

    /**
     * Map a tool_use input block into a pattern-save payload.
     *
     * @param array $tool_input   Decoded tool_use.input (must have 'structure' key).
     * @param array $site_classes name => class_def map.
     * @param array|null $reference_json  Optional reference pattern for diffing.
     * @param string $category    Pattern category (drives BEM block).
     * @param string $variant     Background/modifier (drives BEM modifier).
     * @param array $site_vars    name => value (for identifying new variables).
     * @return array { structure, new_classes, reused_classes, deduped_classes, new_variables, conversion_log }
     */
    public function map_to_pattern( array $tool_input, array $site_classes, ?array $reference_json, string $category, string $variant = '', array $site_vars = [] ): array {
        $structure = $tool_input['structure'] ?? [];
        if ( ! is_array( $structure ) ) {
            return [
                'structure'       => [],
                'new_classes'     => [],
                'reused_classes'  => [],
                'deduped_classes' => [],
                'new_variables'   => [],
                'conversion_log'  => [ 'error' => 'no_structure_in_tool_input' ],
            ];
        }

        $new_classes     = [];
        $reused_classes  = [];
        $deduped_classes = [];

        $structure = $this->walk_and_audit_classes( $structure, $site_classes, $category, $variant, $new_classes, $reused_classes, $deduped_classes );

        $new_variables = $this->collect_new_variables( $structure, $site_vars );

        $conversion_log = [];
        if ( is_array( $reference_json ) ) {
            $conversion_log['reference_diff'] = $this->diff_against_reference( $structure, $reference_json['structure'] ?? [] );
        }

        return [
            'structure'       => $structure,
            'layout'          => (string) ( $tool_input['layout'] ?? '' ),
            'background'      => (string) ( $tool_input['background'] ?? $variant ),
            'notes'           => (string) ( $tool_input['notes'] ?? '' ),
            'new_classes'     => array_values( $new_classes ),
            'reused_classes'  => array_values( $reused_classes ),
            'deduped_classes' => array_values( $deduped_classes ),
            'new_variables'   => array_values( $new_variables ),
            'conversion_log'  => $conversion_log,
        ];
    }

    /**
     * Map a tool_use input block into a design_plan payload for propose_design.
     */
    public function map_to_schema( array $tool_input, array $site_classes, ?array $reference_json, string $category, string $variant = '' ): array {
        $design_plan = [
            'section_type' => (string) ( $tool_input['section_type'] ?? 'generic' ),
            'layout'       => (string) ( $tool_input['layout'] ?? 'centered' ),
            'background'   => (string) ( $tool_input['background'] ?? 'light' ),
            'elements'     => is_array( $tool_input['elements'] ?? null ) ? $tool_input['elements'] : [],
            'patterns'     => is_array( $tool_input['patterns'] ?? null ) ? $tool_input['patterns'] : [],
        ];

        // Normalize element hierarchy: vision sometimes emits 'container' or
        // 'section' inside design_plan.elements, but SchemaSkeletonGenerator
        // wraps the whole element list in its own section + container frame.
        // Inner container/section would then violate Bricks hierarchy rules
        // (container's valid_parents is ['section']; section is root-only).
        // Coerce to 'block' (generic wrapper) — preserves visual grouping
        // without breaking the parent-child registry.
        foreach ( $design_plan['elements'] as &$el ) {
            $type = $el['type'] ?? '';
            if ( $type === 'container' || $type === 'section' ) {
                $el['type'] = 'block';
            }
        }
        unset( $el );

        // audit class_intent references inside elements (best-effort dedup).
        $new_classes = $reused_classes = $deduped_classes = [];
        foreach ( $design_plan['elements'] as &$el ) {
            if ( isset( $el['class_intent'] ) ) {
                $audited = $this->audit_class_intent( $el['class_intent'], $site_classes, $category, $variant, $new_classes, $reused_classes, $deduped_classes );
                $el['class_intent'] = $audited;
            }
        }
        unset( $el );

        $conversion_log = [];
        if ( is_array( $reference_json ) ) {
            $conversion_log['reference_diff'] = $this->diff_against_reference_schema( $design_plan, $reference_json );
        }

        return [
            'design_plan'     => $design_plan,
            'new_classes'     => array_values( $new_classes ),
            'reused_classes'  => array_values( $reused_classes ),
            'deduped_classes' => array_values( $deduped_classes ),
            'conversion_log'  => $conversion_log,
        ];
    }

    /**
     * Recursive walker that audits class_refs per node.
     */
    private function walk_and_audit_classes( array $node, array $site_classes, string $category, string $variant, array &$new_classes, array &$reused_classes, array &$deduped_classes ): array {
        if ( isset( $node['class_refs'] ) && is_array( $node['class_refs'] ) ) {
            $resolved = [];
            foreach ( $node['class_refs'] as $name ) {
                if ( ! is_string( $name ) || $name === '' ) { continue; }

                // Layer 1: exact name match.
                if ( isset( $site_classes[ $name ] ) ) {
                    $reused_classes[ $name ] = [ 'name' => $name ];
                    $resolved[] = $name;
                    continue;
                }

                // Layer 2: style-signature match via ClassDedupEngine.
                $node_tokens = is_array( $node['style_tokens'] ?? null ) ? $node['style_tokens'] : [];
                $dedup_hit   = $this->dedup_find( $node_tokens, $site_classes );
                if ( $dedup_hit !== null ) {
                    $deduped_classes[ $name ] = [ 'vision_name' => $name, 'reused_as' => $dedup_hit ];
                    $resolved[] = $dedup_hit;
                    $reused_classes[ $dedup_hit ] = [ 'name' => $dedup_hit ];
                    continue;
                }

                // Layer 3: new class, BEM-normalized name.
                $new_name = $this->bem_normalize( $category, $variant, (string) ( $node['role'] ?? $name ) );
                $new_classes[ $new_name ] = [ 'name' => $new_name, 'style_tokens' => $node_tokens ];
                $resolved[] = $new_name;
            }
            $node['class_refs'] = $resolved;
        }

        if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
            foreach ( $node['children'] as $i => $child ) {
                if ( is_array( $child ) ) {
                    $node['children'][ $i ] = $this->walk_and_audit_classes( $child, $site_classes, $category, $variant, $new_classes, $reused_classes, $deduped_classes );
                }
            }
        }

        return $node;
    }

    private function audit_class_intent( $intent, array $site_classes, string $category, string $variant, array &$new_classes, array &$reused_classes, array &$deduped_classes ) {
        // class_intent can be string | array | object. We only audit string names; arrays/objects pass through.
        if ( is_string( $intent ) ) {
            if ( isset( $site_classes[ $intent ] ) ) {
                $reused_classes[ $intent ] = [ 'name' => $intent ];
                return $intent;
            }
            $new_name = $this->bem_normalize( $category, $variant, $intent );
            $new_classes[ $new_name ] = [ 'name' => $new_name, 'style_tokens' => [] ];
            return $new_name;
        }
        return $intent;
    }

    /**
     * Ask ClassDedupEngine whether a set of style_tokens already exists.
     * The concrete method/signature is discovered in Task 3.2 Step 1.
     * If no direct find_match method exists, compare canonicalized JSON hash.
     *
     * Note: ClassDedupEngine::find_match expects pool values to BE the tokens
     * (not class_def wrappers). Site classes in our pipeline carry the shape
     * [ 'name' => [ 'style_tokens' => [...] ] ], so we unwrap before calling.
     */
    private function dedup_find( array $style_tokens, array $site_classes ): ?string {
        if ( $style_tokens === [] ) {
            return null;
        }
        $pool = $this->build_dedup_pool( $site_classes );
        // If ClassDedupEngine exposes a public matcher, prefer that.
        if ( method_exists( $this->dedup, 'find_match' ) ) {
            $result = $this->dedup->find_match( $style_tokens, $pool );
            return is_string( $result ) && $result !== '' ? $result : null;
        }
        // Fallback: exact-hash comparison (matches spec §6.2 "exact hash of canonicalized style_tokens").
        $target_hash = $this->hash_tokens( $style_tokens );
        foreach ( $pool as $name => $existing_tokens ) {
            if ( ! is_array( $existing_tokens ) ) { continue; }
            if ( $this->hash_tokens( $existing_tokens ) === $target_hash ) {
                return (string) $name;
            }
        }
        return null;
    }

    /**
     * Flatten $site_classes into a name => style_tokens pool shape that
     * ClassDedupEngine::find_match expects. Accepts both wrapped
     * [ 'style_tokens' => [...] ] and bare token arrays.
     */
    private function build_dedup_pool( array $site_classes ): array {
        $pool = [];
        foreach ( $site_classes as $name => $def ) {
            if ( ! is_array( $def ) ) { continue; }
            if ( isset( $def['style_tokens'] ) && is_array( $def['style_tokens'] ) ) {
                $pool[ $name ] = $def['style_tokens'];
            } else {
                $pool[ $name ] = $def;
            }
        }
        return $pool;
    }

    private function hash_tokens( array $tokens ): string {
        $canon = $this->canonicalize( $tokens );
        return hash( 'sha256', (string) wp_json_encode( $canon ) );
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

    private function bem_normalize( string $category, string $variant, string $hint ): string {
        $element = preg_replace( '/[^a-z0-9]+/', '-', strtolower( $hint ) );
        $element = trim( (string) $element, '-' );
        $parts   = [ 'block' => $category ?: 'section' ];
        if ( $variant !== '' ) {
            $parts['modifier'] = $variant;
        }
        if ( $element !== '' ) {
            $parts['element'] = $element;
        }
        if ( method_exists( $this->bem, 'normalize' ) ) {
            $result = $this->bem->normalize( $parts );
            if ( is_string( $result ) && $result !== '' ) {
                return $result;
            }
        }
        // Fallback: assemble manually.
        $name = $parts['block'];
        if ( isset( $parts['modifier'] ) ) { $name .= '--' . $parts['modifier']; }
        if ( isset( $parts['element'] ) )  { $name .= '__' . $parts['element']; }
        return $name;
    }

    /**
     * Walk structure collecting var(--name) refs not present in site_vars.
     */
    private function collect_new_variables( array $node, array $site_vars, array &$bucket = [] ): array {
        foreach ( $node as $key => $value ) {
            if ( is_string( $value ) ) {
                if ( preg_match_all( '/var\(\s*(--[a-z0-9-]+)\s*\)/i', $value, $matches ) ) {
                    foreach ( $matches[1] as $var_name ) {
                        if ( ! isset( $site_vars[ $var_name ] ) && ! isset( $bucket[ $var_name ] ) ) {
                            $bucket[ $var_name ] = [ 'name' => $var_name, 'value' => '' ];
                        }
                    }
                }
            } elseif ( is_array( $value ) ) {
                $this->collect_new_variables( $value, $site_vars, $bucket );
            }
        }
        return $bucket;
    }

    /**
     * Structural diff: compare type+role per node at each depth.
     */
    private function diff_against_reference( array $vision, array $reference, string $path = '$' ): array {
        $diffs = [];
        $vt = $vision['type'] ?? null;
        $rt = $reference['type'] ?? null;
        if ( $vt !== $rt ) {
            $diffs[] = [ 'path' => $path, 'vision' => $vt, 'reference' => $rt, 'kind' => 'type_mismatch' ];
        }
        $vr = $vision['role'] ?? null;
        $rr = $reference['role'] ?? null;
        if ( $vr !== $rr ) {
            $diffs[] = [ 'path' => $path . '.role', 'vision' => $vr, 'reference' => $rr, 'kind' => 'role_mismatch' ];
        }
        $vc = is_array( $vision['children'] ?? null ) ? $vision['children'] : [];
        $rc = is_array( $reference['children'] ?? null ) ? $reference['children'] : [];
        $max = max( count( $vc ), count( $rc ) );
        for ( $i = 0; $i < $max; $i++ ) {
            $vi = $vc[ $i ] ?? null;
            $ri = $rc[ $i ] ?? null;
            if ( $vi === null ) {
                $diffs[] = [ 'path' => $path . ".children[$i]", 'vision' => null, 'reference' => $ri['type'] ?? null, 'kind' => 'missing_in_vision' ];
                continue;
            }
            if ( $ri === null ) {
                $diffs[] = [ 'path' => $path . ".children[$i]", 'vision' => $vi['type'] ?? null, 'reference' => null, 'kind' => 'extra_in_vision' ];
                continue;
            }
            $diffs = array_merge( $diffs, $this->diff_against_reference( is_array( $vi ) ? $vi : [], is_array( $ri ) ? $ri : [], $path . ".children[$i]" ) );
        }
        return $diffs;
    }

    private function diff_against_reference_schema( array $vision_plan, array $reference ): array {
        $diffs = [];
        foreach ( [ 'section_type', 'layout', 'background' ] as $field ) {
            $v = $vision_plan[ $field ] ?? null;
            $r = $reference[ $field ] ?? null;
            if ( $v !== $r ) {
                $diffs[] = [ 'path' => '$.' . $field, 'vision' => $v, 'reference' => $r, 'kind' => 'field_mismatch' ];
            }
        }
        return $diffs;
    }
}
