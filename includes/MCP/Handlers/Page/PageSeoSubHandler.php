<?php
/**
 * Page SEO sub-handler: get_seo, update_seo actions.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers\Page;

use BricksMCP\MCP\Services\BricksService;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles page SEO actions (get_seo, update_seo).
 */
final class PageSeoSubHandler {

	/**
	 * Canonical list of SEO field names accepted by update_seo.
	 *
	 * Kept as a single source of truth for the accept-list loop, the error
	 * message when none is present, and any future schema/validator mirrors.
	 */
	private const SEO_FIELD_NAMES = [
		'title',
		'description',
		'robots_noindex',
		'robots_nofollow',
		'canonical',
		'og_title',
		'og_description',
		'og_image',
		'twitter_title',
		'twitter_description',
		'twitter_image',
		'focus_keyword',
	];

	/**
	 * Bricks service instance.
	 *
	 * @var BricksService
	 */
	private BricksService $bricks_service;

	/**
	 * Bricks check callback.
	 *
	 * @var callable
	 */
	private $require_bricks;

	/**
	 * Constructor.
	 *
	 * @param BricksService $bricks_service Bricks service instance.
	 * @param callable      $require_bricks Callback that returns \WP_Error|null.
	 */
	public function __construct( BricksService $bricks_service, callable $require_bricks ) {
		$this->bricks_service = $bricks_service;
		$this->require_bricks = $require_bricks;
	}

	/**
	 * Get SEO data from active SEO plugin.
	 *
	 * Returns normalized SEO fields from whichever SEO plugin is active
	 * (Yoast, Rank Math, SEOPress, Slim SEO, or Bricks native) with inline audit.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error SEO data with audit or error.
	 */
	public function get_seo( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Use page tool (action: list) to find valid post IDs.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->get_seo_data( (int) $args['post_id'] );
	}

	/**
	 * Update SEO fields via active SEO plugin.
	 *
	 * Writes normalized SEO field names to the correct plugin meta keys.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	public function update_seo( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Use page tool (action: list) to find valid post IDs.', 'bricks-mcp' )
			);
		}

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( (int) $args['post_id'] );
		if ( $protected ) {
			return $protected;
		}

		// Extract all SEO fields from args. Accept-list lives on self::SEO_FIELD_NAMES.
		$seo_fields = array();
		foreach ( self::SEO_FIELD_NAMES as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$seo_fields[ $field ] = $args[ $field ];
			}
		}

		if ( empty( $seo_fields ) ) {
			return new \WP_Error(
				'missing_seo_fields',
				__( 'At least one SEO field must be provided. Accepted: title, description, robots_noindex, robots_nofollow, canonical, og_title, og_description, og_image, twitter_title, twitter_description, twitter_image, focus_keyword.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_seo_data( (int) $args['post_id'], $seo_fields );
	}
}
