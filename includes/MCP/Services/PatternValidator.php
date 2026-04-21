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
