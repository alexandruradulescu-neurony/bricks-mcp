<?php
/**
 * Schema/reference data handler.
 *
 * Contains large static data methods extracted from Router for element schemas,
 * dynamic tags, query types, filters, conditions, forms, interactions,
 * components, and popups.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\SchemaGenerator;
use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Reference\FilterSchemaCatalog;
use BricksMCP\MCP\Reference\ConditionSchemaCatalog;
use BricksMCP\MCP\Reference\FormSchemaCatalog;
use BricksMCP\MCP\Reference\InteractionSchemaCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SchemaHandler class.
 *
 * Handles schema and reference data tool calls dispatched from Router::tool_bricks().
 */
final class SchemaHandler {

	/**
	 * Schema generator instance.
	 *
	 * @var SchemaGenerator
	 */
	private SchemaGenerator $schema_generator;

	/**
	 * Bricks service instance.
	 *
	 * @var BricksService
	 */
	private BricksService $bricks_service;

	/**
	 * Constructor.
	 *
	 * @param SchemaGenerator $schema_generator Schema generator instance.
	 * @param BricksService   $bricks_service   Bricks service instance.
	 */
	public function __construct( SchemaGenerator $schema_generator, BricksService $bricks_service ) {
		$this->schema_generator = $schema_generator;
		$this->bricks_service   = $bricks_service;
	}

	/**
	 * Tool: Get element schemas.
	 *
	 * Returns Bricks element type schemas with settings definitions and working examples.
	 * Supports full catalog, single element, or catalog-only (names/categories only).
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Schema data or error.
	 */
	public function tool_get_element_schemas( array $args ): array|\WP_Error {
		$catalog_only = isset( $args['catalog_only'] ) && true === $args['catalog_only'];
		$element_name = $args['element'] ?? '';

		if ( $catalog_only ) {
			$catalog = $this->schema_generator->get_element_catalog();

			// Category filter.
			if ( ! empty( $args['category'] ) ) {
				$filter_category = sanitize_text_field( $args['category'] );
				$catalog         = array_values(
					array_filter(
						$catalog,
						static fn( array $item ) => ( $item['category'] ?? '' ) === $filter_category
					)
				);
			}

			$total  = count( $catalog );
			$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
			$limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : null;

			if ( $offset > 0 || null !== $limit ) {
				$catalog = array_values( array_slice( $catalog, $offset, $limit ) );
			}

			$result = array(
				'total_elements' => $total,
				'bricks_version' => $this->schema_generator->get_bricks_version(),
				'catalog'        => $catalog,
			);

			if ( $offset > 0 || null !== $limit ) {
				$result['offset']   = $offset;
				$result['limit']    = $limit;
				$result['has_more'] = ( $offset + count( $catalog ) ) < $total;
			}

			return $result;
		}

		if ( ! empty( $element_name ) ) {
			$schema = $this->schema_generator->get_element_schema( $element_name );
			if ( is_wp_error( $schema ) ) {
				return $schema;
			}

			$result = array(
				'total_elements' => 1,
				'bricks_version' => $this->schema_generator->get_bricks_version(),
				'cached'         => false,
				'schema'         => $schema,
			);

			// Surface related elements sharing the same name prefix (e.g., tabs → tabs-nested).
			$catalog  = $this->schema_generator->get_element_catalog();
			$base     = preg_replace( '/-(nested|nestable)$/', '', $element_name );
			$related  = [];
			foreach ( $catalog as $item ) {
				$item_name = $item['name'] ?? '';
				if ( $item_name !== $element_name && ( str_starts_with( $item_name, $base . '-' ) || str_starts_with( $element_name, $item_name . '-' ) ) ) {
					$related[] = $item;
				}
			}
			if ( ! empty( $related ) ) {
				$result['related_elements'] = $related;
			}

			// Warn if a nestable variant exists and the requested element is non-nestable.
			$is_nestable = $schema['nesting']['nestable'] ?? false;
			if ( ! $is_nestable ) {
				$nestable_name = $element_name . '-nested';
				foreach ( $catalog as $item ) {
					if ( ( $item['name'] ?? '' ) === $nestable_name ) {
						$result['warning'] = sprintf(
							'"%s" is a basic element with a flat repeater (plain text only). For rich content with child elements (headings, images, custom layouts), use "%s" instead. Call get_element_schemas(element=\'%s\') for the correct structure.',
							$element_name,
							$nestable_name,
							$nestable_name
						);
						break;
					}
				}
			}

			return $result;
		}

		// Full catalog with schemas.
		$all_schemas = $this->schema_generator->get_all_schemas();
		$schemas     = array_values( $all_schemas );

		// Category filter.
		if ( ! empty( $args['category'] ) ) {
			$filter_category = sanitize_text_field( $args['category'] );
			$schemas         = array_values(
				array_filter(
					$schemas,
					static fn( array $schema ) => ( $schema['category'] ?? '' ) === $filter_category
				)
			);
		}

		$total  = count( $schemas );
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : null;

		if ( $offset > 0 || null !== $limit ) {
			$schemas = array_values( array_slice( $schemas, $offset, $limit ) );
		}

		$result = array(
			'total_elements'   => $total,
			'bricks_version'   => $this->schema_generator->get_bricks_version(),
			'cached'           => false,
			'schemas'          => $schemas,
			'settings_keys'    => $this->schema_generator->get_settings_keys_flat(),
			'breakpoints'      => $this->bricks_service->get_breakpoints(),
		);

		if ( $offset > 0 || null !== $limit ) {
			$result['offset']   = $offset;
			$result['limit']    = $limit;
			$result['has_more'] = ( $offset + count( $schemas ) ) < $total;
		}

		return $result;
	}

	/**
	 * Tool: Get dynamic data tags.
	 *
	 * Enumerates all available dynamic data tags via the bricks/dynamic_tags_list filter,
	 * including tags from third-party plugins (ACF, MetaBox, JetEngine, etc.).
	 * Results are grouped by tag group and can be filtered by group name.
	 *
	 * @param array<string, mixed> $args Tool arguments. Optional 'group' to filter by group name.
	 * @return array<string, mixed>|\WP_Error Grouped tag data or error.
	 */
	public function tool_get_dynamic_tags( array $args ): array|\WP_Error {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Bricks core filter uses slash separator.
		$all_tags = apply_filters( 'bricks/dynamic_tags_list', array() );

		// Group tags by their group key.
		$grouped     = array();
		$total_count = 0;

		foreach ( $all_tags as $tag ) {
			$name = $tag['name'] ?? '';

			// Security: strip any tags related to query editor PHP execution.
			if ( stripos( $name, 'queryEditor' ) !== false || stripos( $name, 'useQueryEditor' ) !== false ) {
				continue;
			}

			$group = $tag['group'] ?? 'Other';
			$label = $tag['label'] ?? $name;

			if ( ! isset( $grouped[ $group ] ) ) {
				$grouped[ $group ] = array();
			}

			$grouped[ $group ][] = array(
				'name'  => $name,
				'label' => $label,
			);

			++$total_count;
		}

		// Filter by group if requested.
		$filter_group = $args['group'] ?? '';
		if ( '' !== $filter_group ) {
			$filtered = array();
			foreach ( $grouped as $group_name => $tags ) {
				if ( strcasecmp( $group_name, $filter_group ) === 0 ) {
					$filtered[ $group_name ] = $tags;
				}
			}
			$grouped     = $filtered;
			$total_count = 0;
			foreach ( $grouped as $tags ) {
				$total_count += count( $tags );
			}
		}

		// Sort groups alphabetically.
		ksort( $grouped );

		return array(
			'total_tags' => $total_count,
			'groups'     => $grouped,
			'usage_hint' => 'Embed tags directly in element settings values. Text fields use bare tag string e.g. "{post_title}". Image fields use {"useDynamicData": "{featured_image}"}. Link fields use {"type": "dynamic", "dynamicData": "{post_url}"}.',
		);
	}

	/**
	 * Tool: Get query loop types.
	 *
	 * Returns a static reference of the three query object types (post, term, user)
	 * and their available settings keys for configuring query loops on Bricks elements.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Query type reference or error.
	 */
	public function tool_get_query_types( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return array(
			'query_types'   => array(
				array(
					'objectType'  => 'post',
					'label'       => 'Posts (WP_Query)',
					'description' => 'Query WordPress posts, pages, and custom post types',
					'settings'    => array(
						'postType'           => array(
							'type'        => 'array',
							'description' => 'Post type slugs, e.g. ["post"], ["portfolio"]',
						),
						'orderby'            => array(
							'type'        => 'string',
							'description' => 'Sort by: date, title, ID, modified, comment_count, rand, menu_order',
						),
						'order'              => array(
							'type'        => 'string',
							'description' => 'ASC or DESC',
						),
						'postsPerPage'       => array(
							'type'        => 'integer',
							'description' => 'Posts per page (-1 for all)',
						),
						'offset'             => array(
							'type'        => 'integer',
							'description' => 'Number of posts to skip',
						),
						'is_main_query'      => array(
							'type'        => 'boolean',
							'description' => 'REQUIRED true for archive templates to prevent 404 on pagination',
						),
						'ignoreStickyPosts'  => array(
							'type'        => 'boolean',
							'description' => 'Ignore sticky posts',
						),
						'excludeCurrentPost' => array(
							'type'        => 'boolean',
							'description' => 'Exclude current post from results',
						),
						'taxonomyQuery'      => array(
							'type'        => 'array',
							'description' => 'Taxonomy filter objects',
						),
						'metaQuery'          => array(
							'type'        => 'array',
							'description' => 'Custom field filter objects',
						),
					),
				),
				array(
					'objectType'  => 'term',
					'label'       => 'Terms (WP_Term_Query)',
					'description' => 'Query taxonomy terms (categories, tags, custom taxonomies)',
					'settings'    => array(
						'taxonomies' => array(
							'type'        => 'array',
							'description' => 'Taxonomy slugs, e.g. ["category"]',
						),
						'orderby'    => array(
							'type'        => 'string',
							'description' => 'Sort by: name, count, term_id, parent',
						),
						'order'      => array(
							'type'        => 'string',
							'description' => 'ASC or DESC',
						),
						'number'     => array(
							'type'        => 'integer',
							'description' => 'Terms per page',
						),
						'offset'     => array(
							'type'        => 'integer',
							'description' => 'Number of terms to skip',
						),
						'hideEmpty'  => array(
							'type'        => 'boolean',
							'description' => 'Hide terms with no posts',
						),
					),
				),
				array(
					'objectType'  => 'user',
					'label'       => 'Users (WP_User_Query)',
					'description' => 'Query WordPress users by role',
					'settings'    => array(
						'roles'   => array(
							'type'        => 'array',
							'description' => 'Role slugs, e.g. ["author"]',
						),
						'orderby' => array(
							'type'        => 'string',
							'description' => 'Sort by: display_name, registered, post_count, user_login',
						),
						'order'   => array(
							'type'        => 'string',
							'description' => 'ASC or DESC',
						),
						'number'  => array(
							'type'        => 'integer',
							'description' => 'Users per page',
						),
						'offset'  => array(
							'type'        => 'integer',
							'description' => 'Number of users to skip',
						),
					),
				),
				array(
					'objectType'  => 'api',
					'label'       => 'REST API (Query_API)',
					'description' => 'Fetch data from any external REST API endpoint. Available since Bricks 2.1.',
					'settings'    => array(
						'api_url'            => array(
							'type'        => 'string',
							'description' => 'Full API endpoint URL. Supports dynamic tags.',
							'required'    => true,
						),
						'api_method'         => array(
							'type'        => 'string',
							'description' => 'HTTP method: GET (default), POST, PUT, PATCH, DELETE',
						),
						'response_path'      => array(
							'type'        => 'string',
							'description' => 'Dot-notation path to extract array from JSON response, e.g. "data.items". Required for most APIs.',
						),
						'api_auth_type'      => array(
							'type'        => 'string',
							'description' => 'Auth type: none (default), apiKey, bearer, basic',
						),
						'api_params'         => array(
							'type'        => 'array',
							'description' => 'Query parameter repeater: [{key: string, value: string}]',
						),
						'api_headers'        => array(
							'type'        => 'array',
							'description' => 'Request headers repeater: [{key: string, value: string}]',
						),
						'cache_time'         => array(
							'type'        => 'integer',
							'description' => 'Cache duration in seconds (default 300 = 5 min; 0 = disable cache)',
						),
						'pagination_enabled' => array(
							'type'        => 'boolean',
							'description' => 'Enable AJAX pagination for API results',
						),
					),
				),
				array(
					'objectType'  => 'array',
					'label'       => 'Array (static data)',
					'description' => 'Loop over a static PHP/JSON array. Available since Bricks 2.2. WARNING: May require code execution permission in Bricks settings.',
					'settings'    => array(
						'arrayEditor' => array(
							'type'        => 'string',
							'description' => 'PHP code returning an array, or JSON array literal. Requires code execution enabled in Bricks settings.',
						),
					),
				),
			),
			'pagination_options'  => array(
				'infinite_scroll'        => array(
					'type'        => 'boolean',
					'description' => 'Enable infinite scroll (auto-loads next page on scroll). Set in query.infinite_scroll.',
					'key'         => 'query.infinite_scroll',
				),
				'infinite_scroll_margin' => array(
					'type'        => 'string',
					'description' => 'Trigger distance from bottom: "200px", "10%". Default: 0px',
					'key'         => 'query.infinite_scroll_margin',
				),
				'infinite_scroll_delay'  => array(
					'type'        => 'integer',
					'description' => 'Delay in ms before loading next page (since Bricks 1.12)',
					'key'         => 'query.infinite_scroll_delay',
				),
				'ajax_loader_animation'  => array(
					'type'        => 'string',
					'description' => 'AJAX loader animation type while loading',
					'key'         => 'query.ajax_loader_animation',
				),
				'load_more_button'       => array(
					'description' => 'Use interaction action=loadMore on a button element for manual load more. Set loadMoreQuery to the query element ID in _interactions.',
					'pattern'     => '{"trigger":"click","action":"loadMore","loadMoreQuery":"<element_id>"}',
				),
				'note'                   => 'Infinite scroll and load more button are mutually exclusive. Choose one per query loop.',
			),
			'global_query_hint'   => 'Set query.id to a global query ID instead of inline settings. Bricks resolves the global query at render time. Use bricks:get_global_queries to list available global queries.',
			'setup_hint'          => 'To create a query loop, set hasLoop: true and a query object on any layout element (container, div, block). For archive templates, ALWAYS set is_main_query: true in the query object.',
			'security_note'       => 'Never set useQueryEditor or queryEditor — these enable PHP execution and are a security risk.',
		);
	}

	/**
	 * Tool: Get filter schema reference.
	 *
	 * Returns Bricks filter element types, required settings, common settings,
	 * and setup workflow for AJAX-powered query filtering.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Filter schema or error.
	 */
	public function tool_get_filter_schema( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$filters_enabled = class_exists( '\Bricks\Helpers' ) ? \Bricks\Helpers::enabled_query_filters() : false;

		$data                     = FilterSchemaCatalog::data();
		$data['filters_enabled']  = $filters_enabled;

		return $data;
	}
	/**
	 * Tool: Get element condition schema.
	 *
	 * Returns the complete element condition reference — groups, keys, compare operators,
	 * value types, and usage examples. Hardcoded because Bricks' Conditions::$options is
	 * only populated in builder context (bricks_is_builder() check in conditions.php).
	 *
	 * Element conditions (_conditions in element settings) are distinct from template
	 * conditions (template_condition tool). Template conditions use "main" key and control
	 * page targeting. Element conditions use "key/compare/value" and control per-element
	 * render visibility.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Condition schema or error.
	 */
	public function tool_get_condition_schema( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return ConditionSchemaCatalog::data();
	}

	/**
	 * Tool: Get form schema reference.
	 *
	 * Returns form element field types, action settings keys, and example patterns.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed> Form schema reference.
	 */
	public function tool_get_form_schema( array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return FormSchemaCatalog::data();
	}

	/**
	 * Tool: Get interaction schema reference.
	 *
	 * Returns element interaction/animation triggers, actions, animation types, and example patterns.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed> Interaction schema reference.
	 */
	public function tool_get_interaction_schema( array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return InteractionSchemaCatalog::data();
	}

	/**
	 * Tool: Get component schema reference.
	 *
	 * Returns a static reference for component property types, connection wiring,
	 * slot mechanics, and instantiation patterns.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed> Component schema reference.
	 */
	public function tool_get_component_schema( array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return array(
			'property_types'       => array(
				array(
					'type'         => 'text',
					'description'  => 'Text, textarea, or rich-text controls',
					'value_format' => 'string',
				),
				array(
					'type'         => 'icon',
					'description'  => 'Icon picker controls',
					'value_format' => 'object with library and icon keys',
				),
				array(
					'type'         => 'image',
					'description'  => 'Image controls',
					'value_format' => 'object with id and url keys',
				),
				array(
					'type'         => 'gallery',
					'description'  => 'Image gallery controls',
					'value_format' => 'array of image objects',
				),
				array(
					'type'         => 'link',
					'description'  => 'Link controls',
					'value_format' => 'object with url, type, and newTab keys',
				),
				array(
					'type'         => 'select',
					'description'  => 'Select/radio controls (with options array)',
					'value_format' => 'string (selected option value)',
				),
				array(
					'type'         => 'toggle',
					'description'  => 'Toggle controls',
					'value_format' => "'on' or 'off'",
				),
				array(
					'type'         => 'query',
					'description'  => 'Query loop controls',
					'value_format' => 'object with query parameters',
				),
				array(
					'type'         => 'class',
					'description'  => 'Global class pickers',
					'value_format' => 'array of global class IDs',
				),
			),
			'connections_format'   => array(
				'description' => 'Each property has a "connections" object mapping element IDs to arrays of setting keys. When an instance sets a property value, Bricks applies it to the connected element settings.',
				'example'     => array(
					'element_id' => array( 'text' ),
				),
				'note'        => 'Without connections, property values have no effect on rendering.',
			),
			'slot_mechanics'       => array(
				'description'        => "Slots are special elements with name='slot' placed inside a component definition. Instance slot content is stored in the page element array, referenced via slotChildren on the instance element.",
				'slot_element'       => array(
					'name'     => 'slot',
					'parent'   => '<parent_in_component>',
					'children' => array(),
					'settings' => array(),
				),
				'instance_slot_fill' => array(
					'<slot_element_id>' => array( '<content_element_id_1>', '<content_element_id_2>' ),
				),
				'fill_note'          => 'Slot content elements live in the page\'s flat element array with parent = instance element ID. Use component:fill_slot action to manage this atomically.',
			),
			'instantiation_pattern' => array(
				'description'      => 'To place a component on a page, use component:instantiate. The instance is an element with name=cid=component_id.',
				'instance_keys'    => array(
					'name'         => '<component_id>',
					'cid'          => '<component_id>',
					'properties'   => array(),
					'slotChildren' => array(),
				),
				'propagation_note' => 'Changes to the component definition automatically affect all instances at render time.',
			),
			'important_notes'      => array(
				'Root element ID in component elements array MUST equal the component ID',
				'Element name for instances equals the component ID (not a human-readable element type)',
				'Properties without connections have no effect on rendering — always set connections',
				'Slot elements MUST use name=\'slot\' — other nestable elements do not trigger slot behavior',
				'Component IDs use the same 6-char alphanumeric format as element IDs',
				'Nested components are supported up to 10 levels deep',
			),
		);
	}

	/**
	 * Tool: Get popup schema.
	 *
	 * Returns all popup display settings keys organized by category, trigger patterns,
	 * popup creation workflow, and important notes.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed> Popup schema data.
	 */
	public function tool_get_popup_schema( array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return array(
			'popup_settings'   => array(
				'outer'                => array(
					'popupPadding'          => array( 'type' => 'spacing object', 'default' => null, 'description' => 'Padding of the .brx-popup container (skipped when popupIsInfoBox=true)' ),
					'popupJustifyContent'   => array( 'type' => 'string', 'default' => null, 'description' => 'justify-content CSS value for popup main axis alignment (skipped when popupIsInfoBox=true)' ),
					'popupAlignItems'       => array( 'type' => 'string', 'default' => null, 'description' => 'align-items CSS value for popup cross axis (skipped when popupIsInfoBox=true)' ),
					'popupCloseOn'          => array( 'type' => 'string', 'default' => 'both (unset)', 'description' => "Close behavior. 'backdrop' = click only, 'esc' = ESC only, 'none' = neither. Unset = both backdrop+ESC. Do NOT pass 'both'. (skipped when popupIsInfoBox=true)" ),
					'popupZindex'           => array( 'type' => 'number', 'default' => 10000, 'description' => 'CSS z-index of the popup (skipped when popupIsInfoBox=true)' ),
					'popupBodyScroll'       => array( 'type' => 'boolean', 'default' => false, 'description' => 'Allow body scroll when popup is open (skipped when popupIsInfoBox=true)' ),
					'popupScrollToTop'      => array( 'type' => 'boolean', 'default' => false, 'description' => 'Scroll popup to top on open (skipped when popupIsInfoBox=true)' ),
					'popupDisableAutoFocus' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Do not auto-focus first focusable element on open (skipped when popupIsInfoBox=true)' ),
				),
				'info_box'             => array(
					'popupIsInfoBox'    => array( 'type' => 'boolean', 'default' => false, 'description' => 'Enable Map Info Box mode (disables many other settings)' ),
					'popupInfoBoxWidth' => array( 'type' => 'number (px)', 'default' => 300, 'description' => 'Width of info box in pixels' ),
				),
				'ajax'                 => array(
					'popupAjax'                => array( 'type' => 'boolean', 'default' => false, 'description' => 'Fetch popup content via AJAX. Only supports Post, Term, and User context types.' ),
					'popupIsWoo'               => array( 'type' => 'boolean', 'default' => false, 'description' => 'WooCommerce Quick View mode (requires popupAjax=true)' ),
					'popupAjaxLoaderAnimation' => array( 'type' => 'string', 'default' => null, 'description' => 'Loading animation type from ajaxLoaderAnimations option set' ),
					'popupAjaxLoaderColor'     => array( 'type' => 'color object', 'default' => null, 'description' => 'AJAX loader color' ),
					'popupAjaxLoaderScale'     => array( 'type' => 'number', 'default' => 1, 'description' => 'AJAX loader scale factor' ),
					'popupAjaxLoaderSelector'  => array( 'type' => 'string', 'default' => '.brx-popup-content', 'description' => 'CSS selector to inject loader into' ),
				),
				'breakpoint_visibility' => array(
					'popupBreakpointMode' => array( 'type' => 'string', 'default' => null, 'description' => "'at' = show starting at breakpoint, 'on' = show on specific breakpoints only" ),
					'popupShowAt'         => array( 'type' => 'string', 'default' => null, 'description' => "Breakpoint key (e.g. 'tablet_portrait'). Used when mode='at'" ),
					'popupShowOn'         => array( 'type' => 'string[]', 'default' => null, 'description' => "Array of breakpoint keys. Used when mode='on'" ),
				),
				'backdrop'             => array(
					'popupDisableBackdrop'   => array( 'type' => 'boolean', 'default' => false, 'description' => 'Remove backdrop element (enables page interaction while popup open)' ),
					'popupBackground'        => array( 'type' => 'background object', 'default' => null, 'description' => 'Backdrop background (color, image, etc.)' ),
					'popupBackdropTransition' => array( 'type' => 'string', 'default' => null, 'description' => 'CSS transition value for backdrop' ),
				),
				'content_sizing'       => array(
					'popupContentPadding'    => array( 'type' => 'spacing object', 'default' => '30px all sides', 'description' => 'Padding inside .brx-popup-content' ),
					'popupContentWidth'      => array( 'type' => 'number+unit', 'default' => 'container width', 'description' => 'Width of content box' ),
					'popupContentMinWidth'   => array( 'type' => 'number+unit', 'default' => null, 'description' => 'Min-width of content box' ),
					'popupContentMaxWidth'   => array( 'type' => 'number+unit', 'default' => null, 'description' => 'Max-width of content box' ),
					'popupContentHeight'     => array( 'type' => 'number+unit', 'default' => null, 'description' => 'Height of content box' ),
					'popupContentMinHeight'  => array( 'type' => 'number+unit', 'default' => null, 'description' => 'Min-height of content box' ),
					'popupContentMaxHeight'  => array( 'type' => 'number+unit', 'default' => null, 'description' => 'Max-height of content box' ),
					'popupContentBackground' => array( 'type' => 'background object', 'default' => null, 'description' => 'Content box background' ),
					'popupContentBorder'     => array( 'type' => 'border object', 'default' => null, 'description' => 'Content box border' ),
					'popupContentBoxShadow'  => array( 'type' => 'box-shadow object', 'default' => null, 'description' => 'Content box shadow' ),
				),
				'display_limits'       => array(
					'popupLimitWindow'         => array( 'type' => 'number', 'default' => null, 'description' => 'Max times per page load (window variable)' ),
					'popupLimitSessionStorage' => array( 'type' => 'number', 'default' => null, 'description' => 'Max times per session (sessionStorage)' ),
					'popupLimitLocalStorage'   => array( 'type' => 'number', 'default' => null, 'description' => 'Max times across sessions (localStorage)' ),
					'popupLimitTimeStorage'    => array( 'type' => 'number (hours)', 'default' => null, 'description' => 'Show again only after N hours' ),
				),
				'template_interactions' => array(
					'type'        => 'repeater (same structure as element _interactions)',
					'description' => "Popup-level interactions. Stored in _bricks_template_settings.template_interactions. Supports special triggers 'showPopup' (fires when popup is shown) and 'hidePopup' (fires after popup is hidden). Used for chaining animations or running JS on popup open/close — NOT for making the popup open itself.",
				),
			),
			'infobox_behavior' => array(
				'description'      => 'An infobox is a popup with popupIsInfoBox=true. Infoboxes are lightweight popups designed for Google Maps info windows. They skip many popup display settings.',
				'skipped_settings' => array(
					'popupPadding'        => 'Outer padding (infobox has no backdrop/overlay)',
					'popupJustifyContent' => 'Main axis alignment (infobox positioned by map marker)',
					'popupAlignItems'     => 'Cross axis alignment (infobox positioned by map marker)',
					'popupCloseOn'        => 'Close behavior (infobox closes when map marker deselected)',
					'popupZindex'         => 'Z-index (infobox managed by map layer)',
					'popupBodyScroll'     => 'Body scroll lock (infobox does not overlay page)',
				),
				'active_settings'  => array(
					'popupIsInfoBox'         => 'Must be true',
					'popupInfoBoxWidth'      => 'Width in px (default 300)',
					'popupContentPadding'    => 'Content padding',
					'popupContentWidth'      => 'Content width',
					'popupContentBackground' => 'Content background',
					'popupContentBorder'     => 'Content border',
					'popupContentBoxShadow'  => 'Content shadow',
					'popupAjax'              => 'AJAX loading supported',
					'template_interactions'  => 'Popup-level interactions supported',
				),
				'creation_workflow' => array(
					'step_1' => "template:create with type='popup' and title='My Infobox'",
					'step_2' => "template:set_popup_settings with settings={popupIsInfoBox: true, popupInfoBoxWidth: 300}",
					'step_3' => 'page:update_content to add elements to the infobox template',
					'step_4' => 'Use in a Bricks Map element by referencing the template ID',
				),
			),
			'trigger_patterns' => array(
				'click'      => array(
					'description' => 'Open popup when a button is clicked. Set on the button element _interactions.',
					'interaction' => array(
						'id'         => '<6-char-id>',
						'trigger'    => 'click',
						'action'     => 'show',
						'target'     => 'popup',
						'templateId' => '<popup_template_id>',
					),
				),
				'page_load'  => array(
					'description' => 'Auto-open popup on page load with optional delay. Set on any element or in popup template_interactions.',
					'interaction' => array(
						'id'         => '<6-char-id>',
						'trigger'    => 'contentLoaded',
						'delay'      => '2s',
						'action'     => 'show',
						'target'     => 'popup',
						'templateId' => '<popup_template_id>',
					),
				),
				'scroll'     => array(
					'description' => 'Open popup when user scrolls to a percentage. scrollOffset accepts px or % values.',
					'interaction' => array(
						'id'           => '<6-char-id>',
						'trigger'      => 'scroll',
						'scrollOffset' => '50%',
						'action'       => 'show',
						'target'       => 'popup',
						'templateId'   => '<popup_template_id>',
					),
				),
				'exit_intent' => array(
					'description' => 'Open popup when mouse leaves browser window. Use runOnce to fire only once.',
					'interaction' => array(
						'id'         => '<6-char-id>',
						'trigger'    => 'mouseleaveWindow',
						'action'     => 'show',
						'target'     => 'popup',
						'templateId' => '<popup_template_id>',
						'runOnce'    => true,
					),
				),
			),
			'workflow'         => array(
				'step_1' => 'template:create — type=popup, title="My Popup"',
				'step_2' => 'page:update_content — add elements (heading, text, form, button, etc.) to the popup template',
				'step_3' => 'template:set_popup_settings — set popupCloseOn, popupContentMaxWidth, popupLimitLocalStorage, etc.',
				'step_4' => 'element:update — add _interactions to a trigger element on any page: {trigger: "click", action: "show", target: "popup", templateId: <popup_id>}',
				'step_5' => 'template_condition:set — set conditions to control which pages include the popup',
			),
			'important_notes'  => array(
				'Popup display settings go in _bricks_template_settings (template level), NOT in element settings',
				"Triggers go in _interactions on OTHER elements (or template_interactions on the popup itself for showPopup/hidePopup reactions)",
				'Template conditions control WHICH PAGES show the popup. Interactions control WHEN it opens.',
				"popupCloseOn: unset=both backdrop+ESC, 'backdrop'=click only, 'esc'=key only, 'none'=disabled. Do NOT pass 'both'.",
				'popupAjax only supports Post, Term, and User context types',
				'Use bricks:get_interaction_schema for full trigger/action reference',
				'Use template:get_popup_settings to read current popup config, template:set_popup_settings to write',
				'Null value in set_popup_settings deletes that key (reverts to default)',
				'An infobox is a popup sub-type (popupIsInfoBox=true). Create as popup, then set popupIsInfoBox via set_popup_settings. See infobox_behavior for which settings apply.',
				'template:list and template:get responses include is_infobox boolean for quick identification.',
				),
		);
	}
}
