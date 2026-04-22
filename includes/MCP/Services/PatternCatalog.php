<?php
/**
 * Pattern catalog — builds the discovery-phase catalog payload for a given section_type.
 *
 * Scoping + structural_summary generation lives here. Actual DB access delegated
 * to DesignPatternService.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PatternCatalog {

    /**
     * Build a catalog scoped to a section_type.
     *
     * @param string $section_type Detected section type (hero, features, etc.).
     * @param string $confidence   'high' | 'low'.
     * @return array{total: int, scope: string[], patterns: array}
     */
    public function build( string $section_type, string $confidence = 'high' ): array {
        $scope = $confidence === 'high'
            ? [ $section_type, 'generic' ]
            : array_unique( array_merge( $this->likely_categories( $section_type ), [ 'generic' ] ) );
        $scope = array_values( array_unique( $scope ) );

        $all = DesignPatternService::get_all_full();
        $in_scope = array_values( array_filter( $all, fn( $p ) => in_array( $p['category'] ?? '', $scope, true ) ) );

        $patterns = array_map( fn( $p ) => $this->catalog_entry( $p ), $in_scope );

        return [
            'total'    => count( $patterns ),
            'scope'    => $scope,
            'patterns' => $patterns,
        ];
    }

    /**
     * Return the top 2 most likely alternate categories for a given detected type.
     * Used when section_type confidence is "low".
     */
    private function likely_categories( string $section_type ): array {
        $map = [
            'hero'         => [ 'hero', 'split' ],
            'features'     => [ 'features', 'generic' ],
            'cta'          => [ 'cta', 'hero' ],
            'testimonials' => [ 'testimonials', 'features' ],
            'pricing'      => [ 'pricing', 'features' ],
            'split'        => [ 'split', 'hero' ],
        ];
        return $map[ $section_type ] ?? [ $section_type ];
    }

    /**
     * Build a compact catalog entry from a full pattern.
     * Keeps payload small — full structure is not included.
     */
    private function catalog_entry( array $p ): array {
        $class_refs_preview = [];
        $collector = static function ( $node ) use ( &$collector, &$class_refs_preview ) {
            if ( isset( $node['class_refs'] ) && is_array( $node['class_refs'] ) ) {
                foreach ( $node['class_refs'] as $c ) {
                    $class_refs_preview[] = $c;
                }
            }
            foreach ( $node as $v ) {
                if ( is_array( $v ) ) {
                    $collector( $v );
                }
            }
        };
        if ( isset( $p['structure'] ) ) {
            $collector( $p['structure'] );
        }

        return [
            'id'                  => $p['id'] ?? '',
            'name'                => $p['name'] ?? '',
            'category'            => $p['category'] ?? '',
            'structural_summary'  => $this->build_structural_summary( $p ),
            'layout'              => $p['layout'] ?? '',
            'background'          => $p['background'] ?? '',
            'tags'                => $p['tags'] ?? [],
            'class_refs_preview'  => array_values( array_unique( $class_refs_preview ) ),
            'bem_purity'          => $p['bem_purity'] ?? null,
            'non_bem_classes'     => $p['non_bem_classes'] ?? [],
        ];
    }

    /**
     * Generate a one-line tree summary of a pattern's structure.
     * Example: "section > [eyebrow, h1, cta_row(primary, ghost), grid[feature_card × N]]"
     */
    public function build_structural_summary( array $pattern ): string {
        if ( empty( $pattern['structure'] ) || ! is_array( $pattern['structure'] ) ) {
            return '';
        }
        return $this->summarize_node( $pattern['structure'] );
    }

    private function summarize_node( array $node ): string {
        $label = $node['role'] ?? $node['type'] ?? 'node';

        if ( ! empty( $node['repeat'] ) ) {
            $label .= ' × N';
        }

        if ( empty( $node['children'] ) || ! is_array( $node['children'] ) ) {
            return $label;
        }

        $child_parts = array_map( fn( $c ) => is_array( $c ) ? $this->summarize_node( $c ) : '', $node['children'] );
        $child_parts = array_values( array_filter( $child_parts, fn( $s ) => $s !== '' ) );

        // Root section uses "section > [...]"; nested uses "type[...]".
        if ( ( $node['type'] ?? '' ) === 'section' && ! empty( $node['children'] ) ) {
            return $label . ' > [' . implode( ', ', $child_parts ) . ']';
        }
        return $label . '[' . implode( ', ', $child_parts ) . ']';
    }
}
