<?php
/**
 * Page handler for MCP Router.
 *
 * Manages page CRUD, Bricks content, settings, SEO, and snapshots.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Handlers\Page;
use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\ValidationService;
use BricksMCP\MCP\ToolRegistry;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles page tool actions.
 */
final class PageHandler {

	/**
	 * Bricks service instance.
	 *
	 * @var BricksService
	 */
	private BricksService $bricks_service;

	/**
	 * Validation service instance.
	 *
	 * @var ValidationService
	 */
	private ValidationService $validation_service;

	/**
	 * Bricks check callback.
	 *
	 * @var callable
	 */
	private $require_bricks;

	/**
	 * Read sub-handler (list, search, get).
	 *
	 * @var Page\PageReadSubHandler
	 */
	private Page\PageReadSubHandler $read_sub;

	/**
	 * Snapshot sub-handler (snapshot, restore, list_snapshots).
	 *
	 * @var Page\PageSnapshotSubHandler
	 */
	private Page\PageSnapshotSubHandler $snapshot_sub;

	/**
	 * Settings sub-handler (get_settings, update_settings).
	 *
	 * @var Page\PageSettingsSubHandler
	 */
	private Page\PageSettingsSubHandler $settings_sub;

	/**
	 * SEO sub-handler (get_seo, update_seo).
	 *
	 * @var Page\PageSeoSubHandler
	 */
	private Page\PageSeoSubHandler $seo_sub;

	/**
	 * CRUD sub-handler (create, update_meta, delete, duplicate).
	 *
	 * @var Page\PageCrudSubHandler
	 */
	private Page\PageCrudSubHandler $crud_sub;

	/**
	 * Content sub-handler (update_content, append_content, import_clipboard).
	 *
	 * @var Page\PageContentSubHandler
	 */
	private Page\PageContentSubHandler $content_sub;

	/**
	 * Constructor.
	 *
	 * @param BricksService     $bricks_service     Bricks service instance.
	 * @param ValidationService $validation_service  Validation service instance.
	 * @param callable          $require_bricks      Callback that returns \WP_Error|null.
	 */
	public function __construct( BricksService $bricks_service, ValidationService $validation_service, callable $require_bricks ) {
		$this->bricks_service     = $bricks_service;
		$this->validation_service = $validation_service;
		$this->require_bricks     = $require_bricks;

		$this->read_sub     = new Page\PageReadSubHandler( $bricks_service, $require_bricks );
		$this->snapshot_sub = new Page\PageSnapshotSubHandler( $bricks_service, $require_bricks );
		$this->settings_sub = new Page\PageSettingsSubHandler( $bricks_service, $require_bricks );
		$this->seo_sub      = new Page\PageSeoSubHandler( $bricks_service, $require_bricks );
		$this->crud_sub     = new Page\PageCrudSubHandler( $bricks_service, $validation_service, $require_bricks );
		$this->content_sub  = new Page\PageContentSubHandler( $bricks_service, $validation_service, $require_bricks );
	}

	/**
	 * Handle a page tool action.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: action).
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action = sanitize_text_field( $args['action'] ?? '' );

		// Map 'search' param alias: the schema uses 'search' but the search action reads 'query'.
		if ( 'search' === $action && isset( $args['search'] ) && ! isset( $args['query'] ) ) {
			$args['query'] = $args['search'];
		}

		// Map 'posts_per_page' alias to 'per_page' for list/search.
		if ( isset( $args['posts_per_page'] ) && ! isset( $args['per_page'] ) ) {
			$args['per_page'] = $args['posts_per_page'];
		}

		// Map 'paged' alias to 'page' for list.
		if ( isset( $args['paged'] ) && ! isset( $args['page'] ) ) {
			$args['page'] = $args['paged'];
		}

		return match ( $action ) {
			'list'             => $this->read_sub->list_pages( $args ),
			'search'           => $this->read_sub->search( $args ),
			'get'              => $this->read_sub->get( $args ),
			'describe_section' => $this->read_sub->describe_section( $args ),
			'create'           => $this->crud_sub->create( $args ),
			'update_content'   => $this->content_sub->update_content( $args ),
			'append_content'   => $this->content_sub->append_content( $args ),
			'import_clipboard' => $this->content_sub->import_clipboard( $args ),
			'update_meta'      => $this->crud_sub->update_meta( $args ),
			'delete'           => $this->crud_sub->delete( $args ),
			'duplicate'        => $this->crud_sub->duplicate( $args ),
			'get_settings'     => $this->settings_sub->get_settings( $args ),
			'update_settings'  => $this->settings_sub->update_settings( $args ),
			'get_seo'          => $this->seo_sub->get_seo( $args ),
			'update_seo'       => $this->seo_sub->update_seo( $args ),
			'snapshot'         => $this->snapshot_sub->snapshot( $args ),
			'restore'          => $this->snapshot_sub->restore( $args ),
			'list_snapshots'   => $this->snapshot_sub->list_snapshots( $args ),
			default            => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, search, get, describe_section, create, update_content, append_content, import_clipboard, update_meta, delete, duplicate, get_settings, update_settings, get_seo, update_seo, snapshot, restore, list_snapshots', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Register the page tool with the given registry.
	 *
	 * @param ToolRegistry $registry Tool registry instance.
	 * @return void
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'page',
			__( "Manage pages and Bricks content.\n\nActions: list, search, get (views: detail/summary/context/describe), describe_section (rich per-section description with style details), create, update_content, append_content (add without replacing), import_clipboard, update_meta, delete, duplicate, get_settings, update_settings, get_seo, update_seo, snapshot, restore, list_snapshots.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'              => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'search', 'get', 'describe_section', 'create', 'update_content', 'append_content', 'import_clipboard', 'update_meta', 'delete', 'duplicate', 'get_settings', 'update_settings', 'get_seo', 'update_seo', 'snapshot', 'restore', 'list_snapshots' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'post_id'             => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID (get, update_content, update_meta, delete, duplicate, get_settings, update_settings, get_seo, update_seo: required)', 'bricks-mcp' ),
					),
					'post_type'           => array(
						'type'        => 'string',
						'description' => __( 'Post type (list, search, create: optional; default page)', 'bricks-mcp' ),
					),
					'status'              => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private', 'trash', 'any' ),
						'description' => __( 'Post status (list: filter; create/update_meta: new status)', 'bricks-mcp' ),
					),
					'posts_per_page'      => array(
						'type'        => 'integer',
						'description' => __( 'Results per page (list, search: max 100)', 'bricks-mcp' ),
					),
					'paged'               => array(
						'type'        => 'integer',
						'description' => __( 'Page number for pagination (list, search)', 'bricks-mcp' ),
					),
					'bricks_only'         => array(
						'type'        => 'boolean',
						'description' => __( 'Filter to only Bricks-enabled pages (list: default true)', 'bricks-mcp' ),
					),
					'search'              => array(
						'type'        => 'string',
						'description' => __( 'Search query string (search: required)', 'bricks-mcp' ),
					),
					'view'                => array(
						'type'        => 'string',
						'enum'        => array( 'detail', 'summary', 'context', 'describe' ),
						'description' => __( 'Detail level (get: detail=full settings, summary=tree outline, context=tree with text content and classes but no style settings, describe=human-readable section descriptions)', 'bricks-mcp' ),
					),
					'offset'              => array(
						'type'        => 'integer',
						'description' => __( 'Skip first N elements in detail view (get: optional, default 0). Use with limit for pagination.', 'bricks-mcp' ),
					),
					'limit'               => array(
						'type'        => 'integer',
						'description' => __( 'Max elements to return in detail view (get: optional, default all). Use with limit for pagination.', 'bricks-mcp' ),
					),
					'element_ids'         => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Filter to specific element IDs in detail view (get: optional). Returns only these elements and their settings.', 'bricks-mcp' ),
					),
					'section_id'          => array(
						'type'        => 'string',
						'description' => __( 'Section element ID for describe_section action (describe_section: required). Use page:get with view=summary to find section IDs.', 'bricks-mcp' ),
					),
					'root_element_id'     => array(
						'type'        => 'string',
						'description' => __( 'Return only this element and all its descendants (get: optional). Efficient for reading a single section without fetching the entire page.', 'bricks-mcp' ),
					),
					'compact'             => array(
						'type'        => 'boolean',
						'description' => __( 'Strip empty arrays, null values, and default settings to reduce response size (get: optional, default true for detail view).', 'bricks-mcp' ),
					),
					'title'               => array(
						'type'        => 'string',
						'description' => __( 'Page/post title (create: required; update_meta: optional; update_seo: SEO title)', 'bricks-mcp' ),
					),
					'elements'            => array(
						'type'        => 'array',
						'description' => __( 'Element content array (create: optional, update_content/append_content: required)', 'bricks-mcp' ),
					),
					'parent_id'           => array(
						'type'        => 'string',
						'description' => __( 'Parent element ID for appended elements (append_content/import_clipboard: optional, default root level)', 'bricks-mcp' ),
					),
					'position'            => array(
						'type'        => 'integer',
						'description' => __( "Position within parent's children (append_content/import_clipboard: optional, omit to append at end)", 'bricks-mcp' ),
					),
					'clipboard_data'      => array(
						'type'        => 'object',
						'description' => __( 'Bricks copied elements JSON object with content array and optional globalClasses array. Global class styles are flattened into inline element settings (not imported as classes). (import_clipboard: required)', 'bricks-mcp' ),
					),
					'slug'                => array(
						'type'        => 'string',
						'description' => __( 'URL slug (update_meta: optional)', 'bricks-mcp' ),
					),
					'settings'            => array(
						'type'        => 'object',
						'description' => __( 'Settings key-value pairs (update_settings: required)', 'bricks-mcp' ),
					),
					'description'         => array(
						'type'        => 'string',
						'description' => __( 'SEO meta description (update_seo: optional)', 'bricks-mcp' ),
					),
					'robots_noindex'      => array(
						'type'        => 'boolean',
						'description' => __( 'Set noindex robots directive (update_seo: optional)', 'bricks-mcp' ),
					),
					'robots_nofollow'     => array(
						'type'        => 'boolean',
						'description' => __( 'Set nofollow robots directive (update_seo: optional)', 'bricks-mcp' ),
					),
					'canonical'           => array(
						'type'        => 'string',
						'description' => __( 'Canonical URL (update_seo: optional)', 'bricks-mcp' ),
					),
					'og_title'            => array(
						'type'        => 'string',
						'description' => __( 'Open Graph title (update_seo: optional)', 'bricks-mcp' ),
					),
					'og_description'      => array(
						'type'        => 'string',
						'description' => __( 'Open Graph description (update_seo: optional)', 'bricks-mcp' ),
					),
					'og_image'            => array(
						'type'        => 'string',
						'description' => __( 'Open Graph image URL (update_seo: optional)', 'bricks-mcp' ),
					),
					'twitter_title'       => array(
						'type'        => 'string',
						'description' => __( 'Twitter card title (update_seo: optional)', 'bricks-mcp' ),
					),
					'twitter_description' => array(
						'type'        => 'string',
						'description' => __( 'Twitter card description (update_seo: optional)', 'bricks-mcp' ),
					),
					'twitter_image'       => array(
						'type'        => 'string',
						'description' => __( 'Twitter card image URL (update_seo: optional)', 'bricks-mcp' ),
					),
					'focus_keyword'       => array(
						'type'        => 'string',
						'description' => __( 'Focus keyword for SEO analysis (update_seo: optional; Yoast/Rank Math only)', 'bricks-mcp' ),
					),
					'snapshot_id'         => array(
						'type'        => 'string',
						'description' => __( 'Snapshot ID to restore (restore: required)', 'bricks-mcp' ),
					),
					'label'               => array(
						'type'        => 'string',
						'description' => __( 'Human-readable label for the snapshot (snapshot: optional)', 'bricks-mcp' ),
					),
					'force'               => array(
						'type'        => 'boolean',
						'description' => __( 'When true, permanently delete instead of moving to trash (delete action only).', 'bricks-mcp' ),
					),
					'bypass_design_gate'  => array(
						'type'        => 'boolean',
						'description' => __( 'Set true to bypass the design build gate. By default, append_content/update_content/create with section elements will be rejected and redirected to the two-tier build_structure + populate_content flow.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'handle' )
		);
	}
}
