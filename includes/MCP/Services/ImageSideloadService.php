<?php
/**
 * Image sideload service — content-aware per-element image resolution.
 *
 * Walks a design_plan.elements[] array, finds each type: image node,
 * runs MediaService::smart_search using the element's content_hint + site's
 * business_brief context, sideloads the top result into the WP media library,
 * and mutates the element to carry the attachment_id as `src`.
 *
 * Called BEFORE ProposalService::create so the proposal transient sees final URLs.
 *
 * patterns[] (repeat templates) are NOT walked — per-clone image resolution
 * lives in populate_content downstream.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ImageSideloadService {

    /** @var object */
    private $media_service;

    /** @var callable(string): int */
    private $sideload_fn;

    /**
     * @param object   $media_service  Any object exposing smart_search( string, int ): array. In production, pass MediaService.
     * @param callable $sideload_fn    fn(string $url): int attachment_id (0 on failure).
     *                                 In production this is a closure that wraps media_sideload_image + media_handle_sideload.
     */
    public function __construct( $media_service, callable $sideload_fn ) {
        $this->media_service = $media_service;
        $this->sideload_fn   = $sideload_fn;
    }

    /**
     * Sideload every type:image element in the design_plan.
     *
     * @param array<string,mixed> $design_plan     Design plan with elements[] and optional patterns[].
     * @param string              $business_brief  Short site-context string (e.g. "tractari 24-7") appended to each query.
     * @return array{
     *     plan: array<string,mixed>,
     *     attachment_ids: array<int,int>,
     *     misses: array<int, array{role:string, query:string}>
     * }
     */
    public function sideload( array $design_plan, string $business_brief ): array {
        $attachment_ids = [];
        $misses         = [];
        $elements       = $design_plan['elements'] ?? [];
        if ( ! is_array( $elements ) ) {
            return [ 'plan' => $design_plan, 'attachment_ids' => [], 'misses' => [] ];
        }

        foreach ( $elements as $i => $el ) {
            if ( ! is_array( $el ) || ( $el['type'] ?? '' ) !== 'image' ) {
                continue;
            }
            $hint  = (string) ( $el['content_hint'] ?? $el['role'] ?? 'image' );
            $query = trim( $hint . ' business:' . $business_brief );

            $results = $this->media_service->smart_search( $query, 1 );
            $list    = is_array( $results ) ? ( $results['results'] ?? [] ) : [];
            $top_url = is_array( $list ) && isset( $list[0]['url'] ) ? (string) $list[0]['url'] : '';

            if ( $top_url === '' ) {
                $misses[] = [ 'role' => (string) ( $el['role'] ?? '' ), 'query' => $query ];
                continue;
            }

            $attachment_id = (int) ( $this->sideload_fn )( $top_url );
            if ( $attachment_id <= 0 ) {
                $misses[] = [ 'role' => (string) ( $el['role'] ?? '' ), 'query' => $query ];
                continue;
            }
            $elements[ $i ]['src'] = $attachment_id;
            $attachment_ids[]      = $attachment_id;
        }

        $design_plan['elements'] = $elements;
        return [
            'plan'           => $design_plan,
            'attachment_ids' => $attachment_ids,
            'misses'         => $misses,
        ];
    }
}
