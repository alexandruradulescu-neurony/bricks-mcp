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
            if ( ! is_array( $el ) ) {
                continue;
            }
            $type = (string) ( $el['type'] ?? '' );
            if ( $type === 'image' ) {
                $out = $this->sideload_single( $el, $business_brief, $misses );
                if ( $out !== null ) {
                    $elements[ $i ]['src'] = $out;
                    $attachment_ids[]      = $out;
                }
            } elseif ( $type === 'image-gallery' ) {
                $ids = $this->sideload_gallery( $el, $business_brief, $misses );
                if ( $ids !== [] ) {
                    $items = [];
                    foreach ( $ids as $aid ) {
                        $items[] = [ 'id' => $aid ];
                    }
                    $elements[ $i ]['items']          = $items;
                    $elements[ $i ]['attachment_ids'] = $ids;   // convenience for handler
                    $attachment_ids                   = array_merge( $attachment_ids, $ids );
                }
            }
        }

        $design_plan['elements'] = $elements;
        return [
            'plan'           => $design_plan,
            'attachment_ids' => $attachment_ids,
            'misses'         => $misses,
        ];
    }

    /**
     * Sideload one image for a type:image element.
     *
     * @param array<string,mixed> $el
     * @param string              $business_brief
     * @param array<int, array{role:string, query:string}> $misses  Mutated by reference.
     * @return int|null Attachment id, or null on miss.
     */
    private function sideload_single( array $el, string $business_brief, array &$misses ): ?int {
        $hint  = (string) ( $el['content_hint'] ?? $el['role'] ?? 'image' );
        $query = trim( $hint . ' business:' . $business_brief );

        $results = $this->media_service->smart_search( $query, 1 );
        $list    = is_array( $results ) ? ( $results['results'] ?? [] ) : [];
        $top_url = is_array( $list ) && isset( $list[0]['url'] ) ? (string) $list[0]['url'] : '';

        if ( $top_url === '' ) {
            $misses[] = [ 'role' => (string) ( $el['role'] ?? '' ), 'query' => $query ];
            return null;
        }
        $attachment_id = (int) ( $this->sideload_fn )( $top_url );
        if ( $attachment_id <= 0 ) {
            $misses[] = [ 'role' => (string) ( $el['role'] ?? '' ), 'query' => $query ];
            return null;
        }
        return $attachment_id;
    }

    /**
     * Sideload N images for a type:image-gallery element. Count inferred from
     * the element's `count` field if present, else from content_hint (first
     * integer found), else default 5. Always caps at 12 to prevent runaway
     * vision over-requesting and Unsplash rate-limit abuse.
     *
     * @param array<string,mixed> $el
     * @param string              $business_brief
     * @param array<int, array{role:string, query:string}> $misses Mutated by reference.
     * @return array<int, int> Attachment ids in order.
     */
    private function sideload_gallery( array $el, string $business_brief, array &$misses ): array {
        $hint  = (string) ( $el['content_hint'] ?? $el['role'] ?? 'gallery image' );
        $role  = (string) ( $el['role'] ?? '' );
        $count = $this->resolve_gallery_count( $el, $hint );
        $query = trim( $hint . ' business:' . $business_brief );

        // Request count+2 to have buffer if some sideloads fail.
        $results = $this->media_service->smart_search( $query, $count + 2 );
        $list    = is_array( $results ) ? ( $results['results'] ?? [] ) : [];

        $ids = [];
        foreach ( $list as $result ) {
            if ( count( $ids ) >= $count ) {
                break;
            }
            $url = is_array( $result ) ? (string) ( $result['url'] ?? '' ) : '';
            if ( $url === '' ) {
                continue;
            }
            $attachment_id = (int) ( $this->sideload_fn )( $url );
            if ( $attachment_id > 0 ) {
                $ids[] = $attachment_id;
            }
        }

        if ( $ids === [] ) {
            $misses[] = [ 'role' => $role, 'query' => $query ];
        } elseif ( count( $ids ) < $count ) {
            $misses[] = [ 'role' => $role, 'query' => $query . ' (partial: ' . count( $ids ) . '/' . $count . ')' ];
        }

        return $ids;
    }

    /**
     * Resolve how many images a gallery needs. Priority:
     * 1. explicit $el['count'] int
     * 2. first integer token in content_hint ("5 images", "three feature cards")
     * 3. default 5
     * Hard cap: 12.
     */
    private function resolve_gallery_count( array $el, string $hint ): int {
        if ( isset( $el['count'] ) && is_numeric( $el['count'] ) ) {
            $n = (int) $el['count'];
        } elseif ( preg_match( '/\b(\d+)\b/', $hint, $m ) ) {
            $n = (int) $m[1];
        } else {
            // Try simple number words.
            $words = [ 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10 ];
            $n     = 5;
            foreach ( $words as $w => $v ) {
                if ( stripos( $hint, $w ) !== false ) {
                    $n = $v;
                    break;
                }
            }
        }
        if ( $n < 1 )  $n = 1;
        if ( $n > 12 ) $n = 12;
        return $n;
    }
}
