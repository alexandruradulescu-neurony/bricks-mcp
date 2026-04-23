<?php
/**
 * Claude vision provider.
 *
 * Wraps wp_remote_post() against Anthropic Messages API v1 with tool use.
 * No external SDK dependency.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ClaudeVisionProvider implements VisionProvider {

    private const API_ENDPOINT   = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION    = '2023-06-01';
    private const DEFAULT_MODEL  = 'claude-sonnet-4-5-20250929';
    private const DEFAULT_MAX_TOKENS = 4096;
    private const DEFAULT_TIMEOUT_SECONDS = 30;
    private const DEFAULT_RETRY_DELAY = 5;

    private string $api_key;
    private int $retry_delay_seconds;
    private int $timeout_seconds;

    public function __construct( string $api_key, array $options = [] ) {
        $this->api_key             = $api_key;
        $this->retry_delay_seconds = isset( $options['retry_delay_seconds'] ) ? (int) $options['retry_delay_seconds'] : self::DEFAULT_RETRY_DELAY;
        $this->timeout_seconds     = isset( $options['timeout_seconds'] ) ? (int) $options['timeout_seconds'] : self::DEFAULT_TIMEOUT_SECONDS;
    }

    public function analyze( array $image, array $tool_schema, array $messages, array $options = [] ): array|\WP_Error {
        if ( $this->api_key === '' ) {
            return new \WP_Error( 'vision_not_configured', 'Set Anthropic API key in Settings → Bricks MCP before using vision features.' );
        }

        $content = array_merge(
            [ [ 'type' => 'image', 'source' => $image ] ],
            $messages
        );

        return $this->do_request( $content, $tool_schema, $options );
    }

    public function call_text_only( array $messages, array $tool_schema, array $options = [] ): array|\WP_Error {
        if ( $this->api_key === '' ) {
            return new \WP_Error( 'vision_not_configured', 'Set Anthropic API key in Settings → Bricks MCP before using vision features.' );
        }

        $content = [];
        foreach ( $messages as $m ) {
            if ( is_array( $m ) && ( $m['type'] ?? '' ) === 'text' ) {
                $content[] = [ 'type' => 'text', 'text' => (string) ( $m['text'] ?? '' ) ];
            }
        }
        if ( $content === [] ) {
            return new \WP_Error( 'empty_messages', 'call_text_only requires at least one text message.' );
        }

        return $this->do_request( $content, $tool_schema, $options );
    }

    private function do_request( array $content, array $tool_schema, array $options = [] ): array|\WP_Error {
        $body = [
            'model'      => (string) ( $options['model'] ?? self::DEFAULT_MODEL ),
            'max_tokens' => (int) ( $options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS ),
            'system'     => (string) ( $options['system'] ?? 'You analyze UI section images for Bricks Builder. Emit a pattern structure matching the provided tool schema. Use existing site classes and variables when visual function matches.' ),
            'tools'      => [ $tool_schema ],
            'tool_choice'=> [ 'type' => 'tool', 'name' => (string) ( $tool_schema['name'] ?? '' ) ],
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => $content,
                ],
            ],
        ];
        if ( isset( $options['temperature'] ) ) {
            $body['temperature'] = (float) $options['temperature'];
        }

        $http_args = [
            'timeout' => $this->timeout_seconds,
            'headers' => [
                'x-api-key'         => $this->api_key,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
        ];

        // First attempt.
        $resp = wp_remote_post( self::API_ENDPOINT, $http_args );
        $retry_error = $this->classify_for_retry( $resp );
        if ( $retry_error !== null ) {
            if ( $this->retry_delay_seconds > 0 ) {
                sleep( $this->retry_delay_seconds );
            }
            $resp = wp_remote_post( self::API_ENDPOINT, $http_args );
        }

        return $this->parse_response( $resp );
    }

    /**
     * Decide whether a response should be retried. Returns retry-reason code,
     * or null if no retry warranted.
     */
    private function classify_for_retry( $response ): ?string {
        if ( is_wp_error( $response ) ) {
            // Network-layer failure — retry once.
            return 'network';
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code === 429 ) { return 'rate_limited'; }
        if ( $code >= 500 && $code <= 599 ) { return 'server_error'; }
        return null;
    }

    /**
     * Parse a final (post-retry) response into either a success payload or WP_Error.
     */
    private function parse_response( $response ): array|\WP_Error {
        if ( is_wp_error( $response ) ) {
            return new \WP_Error( 'vision_network_error', 'Vision request failed: ' . $response->get_error_message() );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );

        if ( $code === 401 || $code === 403 ) {
            return new \WP_Error( 'vision_auth_failed', 'Anthropic API rejected the API key. Verify it at Settings → Bricks MCP.', [ 'upstream_body' => $body ] );
        }
        if ( $code === 429 ) {
            return new \WP_Error( 'vision_rate_limited', 'Anthropic API rate limit exceeded and retry did not recover.', [ 'upstream_body' => $body ] );
        }
        if ( $code >= 500 && $code <= 599 ) {
            return new \WP_Error( 'vision_api_unavailable', 'Anthropic API server error (HTTP ' . $code . ').', [ 'upstream_body' => $body ] );
        }
        if ( $code === 400 ) {
            return new \WP_Error( 'vision_api_bad_request', 'Anthropic rejected the request: ' . $body, [ 'upstream_body' => $body ] );
        }
        if ( $code !== 200 ) {
            return new \WP_Error( 'vision_api_bad_request', 'Unexpected HTTP status ' . $code . ': ' . $body, [ 'upstream_body' => $body ] );
        }

        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return new \WP_Error( 'vision_output_invalid', 'Anthropic response was not valid JSON.', [ 'upstream_body' => $body ] );
        }
        $content_blocks = $decoded['content'] ?? [];
        if ( ! is_array( $content_blocks ) ) {
            return new \WP_Error( 'vision_output_invalid', 'Response missing content[] array.' );
        }
        foreach ( $content_blocks as $block ) {
            if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'tool_use' && is_array( $block['input'] ?? null ) ) {
                $usage = $decoded['usage'] ?? [];
                return [
                    'tool_input'    => $block['input'],
                    'input_tokens'  => (int) ( $usage['input_tokens'] ?? 0 ),
                    'output_tokens' => (int) ( $usage['output_tokens'] ?? 0 ),
                ];
            }
        }
        return new \WP_Error( 'vision_output_invalid', 'No tool_use block in response.', [ 'upstream_body' => $body ] );
    }
}
