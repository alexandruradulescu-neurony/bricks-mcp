<?php
/**
 * Vision provider interface.
 *
 * Concrete implementations call a vision-capable LLM API with an image and
 * a tool-use schema, returning the structured tool_use response or a
 * WP_Error on failure.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface VisionProvider {

    /**
     * Analyze an image via a vision-capable LLM.
     *
     * @param array<string, mixed> $image        { type: 'base64', media_type: string, data: string }.
     * @param array<string, mixed> $tool_schema  Tool-use schema (Anthropic Messages API shape).
     * @param array<string, mixed> $messages     Additional text content blocks (site context, reference_json, task).
     * @param array<string, mixed> $options      Optional: temperature, max_tokens, model override.
     * @return array{tool_input: array, input_tokens: int, output_tokens: int}|\WP_Error
     */
    public function analyze( array $image, array $tool_schema, array $messages, array $options = [] ): array|\WP_Error;

    /**
     * Call the model with text-only messages (no image). Used for reference-JSON
     * translation where no visual input is needed.
     *
     * @param array<int, array{type:string, text:string}> $messages   Same shape as analyze's messages arg.
     * @param array<string,mixed>                          $tool_schema Tool definition (name, description, input_schema).
     * @param array<string,mixed>                          $options     Optional: temperature, max_tokens, model override.
     * @return array{tool_input: array, input_tokens: int, output_tokens: int}|\WP_Error Provider response envelope matching analyze.
     */
    public function call_text_only( array $messages, array $tool_schema, array $options = [] ): array|\WP_Error;
}
