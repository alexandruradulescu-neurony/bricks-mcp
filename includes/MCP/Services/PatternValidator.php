<?php
/**
 * Pattern validator.
 *
 * Single entry point for all pattern creation paths (capture, create, import).
 * Strips content, snaps raw values to tokens, resolves classes/variables,
 * and emits either a valid pattern or a structured rejection.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PatternValidator {

    /**
     * Fields removed during content stripping (case-sensitive, exact match).
     * These are human-facing values; structural metadata (type, role, tag,
     * class_refs, style_tokens) is preserved.
     */
    private const STRIPPED_FIELDS = [
        'content', 'content_example', 'text', 'label', 'title', 'description',
        'link', 'href', 'target', 'icon', 'image', 'src', 'url',
        'placeholder', 'note',
    ];

    /**
     * Recursively strip human-facing content fields from a pattern tree.
     *
     * @param array<string, mixed> $node Pattern tree or subtree.
     * @return array<string, mixed> Cloned tree with content fields removed.
     */
    public function strip_content( array $node ): array {
        foreach ( self::STRIPPED_FIELDS as $field ) {
            unset( $node[ $field ] );
        }
        foreach ( $node as $key => $value ) {
            if ( is_array( $value ) ) {
                $node[ $key ] = $this->strip_content( $value );
            }
        }
        return $node;
    }

    /**
     * Validate and transform a pattern input through the full pipeline.
     *
     * @param array<string, mixed> $input Raw pattern input.
     * @return array<string, mixed> Either a valid pattern or an error structure.
     */
    public function validate( array $input ): array {
        if ( empty( $input ) ) {
            return [
                'error' => 'empty_input',
                'message' => 'Pattern input is empty.',
            ];
        }

        // Placeholder — filled in by subsequent tasks.
        return $input;
    }
}
