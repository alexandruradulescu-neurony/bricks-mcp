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
     * Extract the tool_use block's input payload + usage from an Anthropic Messages
     * API response envelope.
     *
     * @param array<string,mixed> $response  Raw provider response (content[] + usage).
     * @return array{
     *     description: string,
     *     design_plan: array<string,mixed>,
     *     global_classes_to_create: array<int, array{name:string, settings:array}>,
     *     content_map: array<string,string>,
     *     usage: array<string,int>
     * }|\WP_Error
     */
    public function extract_tool_output( array $response ): array|\WP_Error {
        $blocks = $response['content'] ?? [];
        if ( ! is_array( $blocks ) ) {
            return new \WP_Error( 'vision_response_malformed', 'Provider response has no content array.' );
        }
        $tool_input = null;
        foreach ( $blocks as $block ) {
            if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'tool_use' && is_array( $block['input'] ?? null ) ) {
                $tool_input = $block['input'];
                break;
            }
        }
        if ( ! is_array( $tool_input ) ) {
            return new \WP_Error( 'vision_no_tool_use', 'Provider returned no tool_use block — model refused or emitted text only.' );
        }

        return [
            'description'              => (string) ( $tool_input['description'] ?? '' ),
            'design_plan'              => is_array( $tool_input['design_plan'] ?? null ) ? $tool_input['design_plan'] : [],
            'global_classes_to_create' => is_array( $tool_input['global_classes_to_create'] ?? null ) ? $tool_input['global_classes_to_create'] : [],
            'content_map'              => is_array( $tool_input['content_map'] ?? null ) ? $tool_input['content_map'] : [],
            'usage'                    => [
                'input_tokens'  => (int) ( $response['usage']['input_tokens']  ?? 0 ),
                'output_tokens' => (int) ( $response['usage']['output_tokens'] ?? 0 ),
            ],
        ];
    }
}
