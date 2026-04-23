<?php
/**
 * Reference-JSON translator. Text-only Claude call that rewrites foreign
 * Bricksies globalClasses into site-equivalent BEM names + replaces
 * foreign variable references (var(--brxw-*)) with closest site variables.
 *
 * Used by DesignPatternHandler::tool_from_image when only reference_json
 * is provided (no image).
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ReferenceJsonTranslator {

    /** @var object Provider exposing call_text_only( array $messages, array $tool_schema, array $options = [] ): array|\WP_Error */
    private $provider;

    /** @var VisionPromptBuilder */
    private VisionPromptBuilder $prompt_builder;

    public function __construct( $provider, ?VisionPromptBuilder $prompt_builder = null ) {
        $this->provider       = $provider;
        $this->prompt_builder = $prompt_builder ?? new VisionPromptBuilder();
    }

    /**
     * Translate a Bricksies-format reference JSON into a site-native design_plan.
     *
     * @param array<string,mixed> $reference    Bricksies clipboard JSON (content[], globalClasses[]).
     * @param array{classes:array,variables:array,theme:string} $site_context
     * @return array{
     *     description: string,
     *     design_plan: array<string,mixed>,
     *     global_classes_to_create: array<int, array{name:string, settings:array}>,
     *     content_map: array<string,string>,
     *     usage: array<string,int>
     * }|\WP_Error
     */
    public function translate( array $reference, array $site_context ): array|\WP_Error {
        // Reuse the same schema the vision path uses — contract is identical.
        $schema_build = $this->prompt_builder->build_for_schema( $site_context, $reference );
        $tool_schema  = $schema_build['tool_schema'];

        // Build text-only messages: site context + reference + translation directive.
        $messages = $schema_build['messages'];
        // Append an extra directive specifically for the no-image case.
        $messages[] = [
            'type' => 'text',
            'text' => "[JSON-ONLY MODE] No image provided. Your job is PURE TRANSLATION of the reference JSON:\n"
                . "1. Preserve element tree structure verbatim from reference.content[].\n"
                . "2. For each entry in reference.globalClasses[], emit a global_classes_to_create[] entry with a site-BEM name (drop the foreign prefix, use role-appropriate prefix matching site conventions).\n"
                . "3. For each var(--brxw-*) value in class settings, REPLACE with the closest site variable from [SITE CONTEXT] variables above based on semantic match (e.g. var(--brxw-text-2xl) → var(--text-2xl) if available).\n"
                . "4. Do NOT adapt content — retain reference's Lorem ipsum / placeholder labels. content_map stays empty unless reference has concrete copy.\n"
                . "5. Do NOT attempt to guess images — reference's placeholder URLs are kept; user replaces later.",
        ];

        $response = $this->provider->call_text_only( $messages, $tool_schema );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $tool_input = null;
        foreach ( $response['content'] ?? [] as $block ) {
            if ( ( $block['type'] ?? '' ) === 'tool_use' && is_array( $block['input'] ?? null ) ) {
                $tool_input = $block['input'];
                break;
            }
        }
        if ( ! is_array( $tool_input ) ) {
            return new \WP_Error( 'translation_malformed', 'Provider returned no tool_use block with design_plan.' );
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
