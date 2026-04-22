<?php
/**
 * Vision pattern generator — orchestrator for image → pattern/schema.
 *
 * Public API:
 *   generate_pattern(image, site_context, reference_json, meta): pattern-save payload
 *   generate_schema(image, site_context, reference_json, meta): design_plan payload
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class VisionPatternGenerator {

    private const MAX_STRUCTURE_DEPTH = 10;

    public function __construct(
        private VisionProvider $provider,
        private VisionPromptBuilder $prompt_builder,
        private VisionResponseMapper $mapper
    ) {}

    /**
     * Flow A — pattern from image.
     *
     * @param array{type:string,media_type:string,data:string} $image
     * @param array{classes:array,variables:array,theme:string} $site_context
     * @param array|null $reference_json
     * @param array{category:string, variant?:string, max_tokens?:int, model?:string, temperature?:float} $meta
     * @return array|\WP_Error
     */
    public function generate_pattern( array $image, array $site_context, ?array $reference_json, array $meta ): array|\WP_Error {
        $built = $this->prompt_builder->build_for_pattern( $site_context, $reference_json );
        $resp  = $this->provider->analyze( $image, $built['tool_schema'], $built['messages'], $this->provider_options( $meta ) );
        if ( is_wp_error( $resp ) ) {
            return $resp;
        }
        $tool_input = $resp['tool_input'];
        if ( ! is_array( $tool_input ) || ! isset( $tool_input['structure'] ) ) {
            return new \WP_Error( 'vision_output_incomplete', 'Vision output missing required "structure" key.', [ 'tool_input' => $tool_input ] );
        }
        if ( $this->depth( $tool_input['structure'] ) > self::MAX_STRUCTURE_DEPTH ) {
            return new \WP_Error( 'vision_output_runaway', 'Vision output structure exceeds maximum depth of ' . self::MAX_STRUCTURE_DEPTH . '.', [ 'tool_input' => $tool_input ] );
        }

        $mapped = $this->mapper->map_to_pattern(
            $tool_input,
            $site_context['classes'] ?? [],
            $reference_json,
            (string) ( $meta['category'] ?? 'generic' ),
            (string) ( $meta['variant'] ?? '' ),
            $site_context['variables'] ?? []
        );
        $mapped['vision_cost_tokens'] = [
            'input'  => (int) ( $resp['input_tokens']  ?? 0 ),
            'output' => (int) ( $resp['output_tokens'] ?? 0 ),
        ];
        return $mapped;
    }

    /**
     * Flow B — build from image (design_plan emission).
     */
    public function generate_schema( array $image, array $site_context, ?array $reference_json, array $meta ): array|\WP_Error {
        $built = $this->prompt_builder->build_for_schema( $site_context, $reference_json );
        $resp  = $this->provider->analyze( $image, $built['tool_schema'], $built['messages'], $this->provider_options( $meta ) );
        if ( is_wp_error( $resp ) ) {
            return $resp;
        }
        $tool_input = $resp['tool_input'];
        if ( ! is_array( $tool_input ) || ! isset( $tool_input['elements'] ) ) {
            return new \WP_Error( 'vision_output_incomplete', 'Vision output missing required "elements" key.', [ 'tool_input' => $tool_input ] );
        }

        $mapped = $this->mapper->map_to_schema(
            $tool_input,
            $site_context['classes'] ?? [],
            $reference_json,
            (string) ( $meta['category'] ?? 'generic' ),
            (string) ( $meta['variant'] ?? '' )
        );
        $mapped['vision_cost_tokens'] = [
            'input'  => (int) ( $resp['input_tokens']  ?? 0 ),
            'output' => (int) ( $resp['output_tokens'] ?? 0 ),
        ];
        return $mapped;
    }

    private function provider_options( array $meta ): array {
        $opts = [];
        foreach ( [ 'max_tokens', 'model', 'temperature' ] as $k ) {
            if ( isset( $meta[ $k ] ) ) {
                $opts[ $k ] = $meta[ $k ];
            }
        }
        return $opts;
    }

    private function depth( $node, int $level = 1 ): int {
        if ( ! is_array( $node ) ) {
            return $level;
        }
        $max = $level;
        if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
            foreach ( $node['children'] as $child ) {
                $d = $this->depth( $child, $level + 1 );
                if ( $d > $max ) { $max = $d; }
            }
        }
        return $max;
    }
}
