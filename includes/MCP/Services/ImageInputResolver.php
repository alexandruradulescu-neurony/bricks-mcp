<?php
/**
 * Image input resolver.
 *
 * Normalizes image_url / image_id / image_base64 into a single shape:
 * { type: 'base64', media_type: string, data: string }. Enforces size caps,
 * MIME checks, SSRF guardrails.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ImageInputResolver {

    private const MAX_BYTES = 5 * 1024 * 1024;  // 5MB

    /**
     * Resolve whichever of image_url / image_id / image_base64 is provided.
     *
     * @param array<string, mixed> $args Tool args.
     * @return array{type:string, media_type:string, data:string}|\WP_Error
     */
    public function resolve( array $args ): array|\WP_Error {
        if ( isset( $args['image_base64'] ) && is_string( $args['image_base64'] ) && $args['image_base64'] !== '' ) {
            return $this->from_base64( $args['image_base64'] );
        }
        if ( isset( $args['image_id'] ) && (int) $args['image_id'] > 0 ) {
            return $this->from_attachment( (int) $args['image_id'] );
        }
        if ( isset( $args['image_url'] ) && is_string( $args['image_url'] ) && $args['image_url'] !== '' ) {
            return $this->from_url( $args['image_url'] );
        }
        return new \WP_Error( 'missing_image_input', 'One of image_url, image_id, image_base64 is required.' );
    }

    private function from_base64( string $encoded ): array|\WP_Error {
        $decoded = base64_decode( $encoded, true );
        if ( $decoded === false ) {
            return new \WP_Error( 'image_format_invalid', 'image_base64 is not valid base64.' );
        }
        if ( strlen( $decoded ) > self::MAX_BYTES ) {
            return new \WP_Error( 'image_size_exceeded', 'Decoded image exceeds 5MB limit.' );
        }
        $mime = $this->sniff_mime( $decoded );
        if ( $mime === null ) {
            return new \WP_Error( 'image_format_invalid', 'Could not detect image format from bytes (expected PNG/JPEG/WEBP/GIF).' );
        }
        return [ 'type' => 'base64', 'media_type' => $mime, 'data' => $encoded ];
    }

    private function from_attachment( int $id ): array|\WP_Error {
        if ( ! function_exists( 'wp_get_attachment_url' ) || ! function_exists( 'get_attached_file' ) ) {
            return new \WP_Error( 'image_id_unavailable', 'WordPress attachment API unavailable.' );
        }
        $path = get_attached_file( $id );
        if ( ! is_string( $path ) || ! file_exists( $path ) ) {
            return new \WP_Error( 'image_id_not_found', 'Attachment ' . $id . ' not found on disk.' );
        }
        $size = (int) filesize( $path );
        if ( $size > self::MAX_BYTES ) {
            return new \WP_Error( 'image_size_exceeded', 'Attachment exceeds 5MB limit (' . $size . ' bytes).' );
        }
        $bytes = file_get_contents( $path );
        if ( $bytes === false ) {
            return new \WP_Error( 'image_id_unreadable', 'Could not read attachment file.' );
        }
        $mime = $this->sniff_mime( $bytes );
        if ( $mime === null ) {
            return new \WP_Error( 'image_format_invalid', 'Attachment is not a supported image format.' );
        }
        return [ 'type' => 'base64', 'media_type' => $mime, 'data' => base64_encode( $bytes ) ];
    }

    private function from_url( string $url ): array|\WP_Error {
        if ( ! preg_match( '#^https://#i', $url ) ) {
            return new \WP_Error( 'image_url_insecure', 'image_url must use HTTPS.' );
        }
        $host = parse_url( $url, PHP_URL_HOST );
        if ( ! is_string( $host ) || $host === '' ) {
            return new \WP_Error( 'image_url_invalid', 'Could not parse host from image_url.' );
        }
        if ( $this->is_private_host( $host ) ) {
            return new \WP_Error( 'image_url_private_ip', 'image_url host resolves to a private IP range; blocked.' );
        }

        if ( ! function_exists( 'wp_remote_get' ) ) {
            return new \WP_Error( 'image_url_unavailable', 'WordPress HTTP API unavailable.' );
        }
        $resp = wp_remote_get( $url, [ 'timeout' => 15 ] );
        if ( is_wp_error( $resp ) ) {
            return new \WP_Error( 'image_url_fetch_failed', 'Fetching image_url failed: ' . $resp->get_error_message() );
        }
        $code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $resp ) : 0;
        if ( $code < 200 || $code >= 300 ) {
            return new \WP_Error( 'image_url_fetch_failed', 'image_url returned HTTP ' . $code );
        }
        $bytes = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $resp ) : '';
        if ( strlen( $bytes ) > self::MAX_BYTES ) {
            return new \WP_Error( 'image_size_exceeded', 'image_url body exceeds 5MB limit.' );
        }
        $mime = $this->sniff_mime( $bytes );
        if ( $mime === null ) {
            return new \WP_Error( 'image_format_invalid', 'image_url did not return a supported image format.' );
        }
        return [ 'type' => 'base64', 'media_type' => $mime, 'data' => base64_encode( $bytes ) ];
    }

    private function is_private_host( string $host ): bool {
        // Literal loopback / link-local / private first.
        if ( preg_match( '/^(127\.|10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $host ) ) {
            return true;
        }
        if ( $host === 'localhost' || $host === '::1' ) {
            return true;
        }
        // DNS lookup; if resolves to private IP, block.
        $ip = gethostbyname( $host );
        if ( $ip === $host ) {
            // Failed resolution — allow; downstream fetch will fail naturally.
            return false;
        }
        return (bool) preg_match( '/^(127\.|10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip );
    }

    private function sniff_mime( string $bytes ): ?string {
        if ( strlen( $bytes ) < 4 ) { return null; }
        if ( str_starts_with( $bytes, "\x89PNG" ) )                { return 'image/png'; }
        if ( str_starts_with( $bytes, "\xFF\xD8\xFF" ) )           { return 'image/jpeg'; }
        if ( str_starts_with( $bytes, 'GIF87a' ) || str_starts_with( $bytes, 'GIF89a' ) ) { return 'image/gif'; }
        if ( str_starts_with( $bytes, 'RIFF' ) && substr( $bytes, 8, 4 ) === 'WEBP' )    { return 'image/webp'; }
        return null;
    }
}
