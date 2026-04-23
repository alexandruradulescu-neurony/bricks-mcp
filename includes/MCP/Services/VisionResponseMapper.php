<?php
/**
 * Vision response mapper — extracts the tool_use block's structured input
 * from a provider response envelope into a normalized, flat array.
 *
 * v3.32: Simplified from v3.31. All class dedup / audit logic moved up
 * to DesignPatternHandler where it integrates with the normal build pipeline.
 * This class is now a pure extractor — no business logic.
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

    /**
     * Extract the tool_use block's input payload + usage from the FLAT provider
     * output (ClaudeVisionProvider already unwrapped the tool_use block).
     *
     * @param array<string,mixed> $response  Flat provider output: { tool_input, input_tokens, output_tokens }.
     * @return array{
     *     description: string,
     *     design_plan: array<string,mixed>,
     *     global_classes_to_create: array<int, array{name:string, settings:array}>,
     *     content_map: array<string,string>,
     *     usage: array<string,int>
     * }|\WP_Error
     */
    public function extract_tool_output( array $response ): array|\WP_Error {
        if ( ! isset( $response['tool_input'] ) || ! is_array( $response['tool_input'] ) ) {
            return new \WP_Error( 'vision_no_tool_use', 'Provider returned no tool_use block — model refused or emitted text only.' );
        }
        $tool_input = $response['tool_input'];

        $design_plan = is_array( $tool_input['design_plan'] ?? null ) ? $tool_input['design_plan'] : [];
        // v3.32.1 defense: vision sometimes emits wrapper types (section/container/block/div) in
        // elements[] despite the schema enum excluding them + the prompt saying leaves only.
        // SchemaSkeletonGenerator owns wrappers — strip them here so the pipeline never sees
        // a container-in-container layout error.
        $design_plan = $this->strip_wrappers_from_plan( $design_plan );

        return [
            'description'              => (string) ( $tool_input['description'] ?? '' ),
            'design_plan'              => $design_plan,
            'global_classes_to_create' => is_array( $tool_input['global_classes_to_create'] ?? null ) ? $tool_input['global_classes_to_create'] : [],
            'content_map'              => is_array( $tool_input['content_map'] ?? null ) ? $tool_input['content_map'] : [],
            'usage'                    => [
                'input_tokens'  => (int) ( $response['input_tokens']  ?? 0 ),
                'output_tokens' => (int) ( $response['output_tokens'] ?? 0 ),
            ],
        ];
    }

    /**
     * Drop any wrapper-typed entries (section/container/block/div) from design_plan.elements[]
     * and patterns[].element_structure[]. Wrappers are added by SchemaSkeletonGenerator;
     * vision-emitted wrappers produce container-in-container validation errors downstream.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function strip_wrappers_from_plan( array $plan ): array {
        $wrappers = [ 'section', 'container', 'block', 'div' ];

        if ( isset( $plan['elements'] ) && is_array( $plan['elements'] ) ) {
            $plan['elements'] = array_values( array_filter(
                $plan['elements'],
                static fn( $el ) => is_array( $el ) && ! in_array( (string) ( $el['type'] ?? '' ), $wrappers, true )
            ) );
        }

        if ( isset( $plan['patterns'] ) && is_array( $plan['patterns'] ) ) {
            foreach ( $plan['patterns'] as $i => $pat ) {
                if ( ! is_array( $pat ) ) {
                    continue;
                }
                if ( isset( $pat['element_structure'] ) && is_array( $pat['element_structure'] ) ) {
                    $plan['patterns'][ $i ]['element_structure'] = array_values( array_filter(
                        $pat['element_structure'],
                        static fn( $el ) => is_array( $el ) && ! in_array( (string) ( $el['type'] ?? '' ), $wrappers, true )
                    ) );
                }
            }
        }

        return $plan;
    }
}
