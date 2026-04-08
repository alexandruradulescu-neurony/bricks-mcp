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
			'css_property_map' => $this->schema_generator->get_css_property_map(),
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

		return array(
			'filters_enabled'     => $filters_enabled,
			'enable_filters_hint' => 'Query filters must be enabled in Bricks > Settings > Performance > "Enable query sort / filter / live search". Without this, filter elements render as empty.',
			'filter_elements'     => array(
				'filter-checkbox'       => array(
					'label'           => 'Filter - Checkbox',
					'supports_source' => array( 'taxonomy', 'wpField', 'customField' ),
					'required'        => array( 'filterQueryId', 'filterSource' ),
					'bricks_2_3'     => array(
						'show_more_less' => array(
							'description'  => 'Load more / show less button for long option lists (@since 2.3).',
							'limitOptions' => 'Number of visible options before "Show more" button appears (number). Leave empty to show all.',
							'showMoreText' => 'Custom "Show more" button text. Use %number% placeholder for count of hidden items. Default: "Show %number% more".',
							'showLessText' => 'Custom "Show less" button text. Default: "Show Less".',
							'styling_note' => 'Button styling controls: showMoreButtonSize, showMoreButtonStyle, showMoreButtonOutline, showMoreButtonTypography, showMoreButtonBackground, showMoreButtonBorder.',
						),
						'countAlignEnd'  => 'Align item count to end of row (checkbox, requires displayMode=default). @since 2.3.',
					),
				),
				'filter-radio'          => array(
					'label'           => 'Filter - Radio',
					'supports_source' => array( 'taxonomy', 'wpField', 'customField' ),
					'supports_action' => array( 'filter', 'sort', 'per_page' ),
					'required'        => array( 'filterQueryId', 'filterSource' ),
					'bricks_2_3'     => array(
						'show_more_less' => array(
							'description'  => 'Load more / show less button for long option lists (@since 2.3).',
							'limitOptions' => 'Number of visible options before "Show more" button appears (number). Leave empty to show all.',
							'showMoreText' => 'Custom "Show more" button text. Use %number% placeholder for count of hidden items. Default: "Show %number% more".',
							'showLessText' => 'Custom "Show less" button text. Default: "Show Less".',
							'styling_note' => 'Button styling controls: showMoreButtonSize, showMoreButtonStyle, showMoreButtonOutline, showMoreButtonTypography, showMoreButtonBackground, showMoreButtonBorder.',
						),
						'countAlignEnd'  => 'Align item count to end of row (checkbox, requires displayMode=default). @since 2.3.',
					),
				),
				'filter-select'         => array(
					'label'           => 'Filter - Select',
					'supports_source' => array( 'taxonomy', 'wpField', 'customField' ),
					'supports_action' => array( 'filter', 'sort', 'per_page' ),
					'required'        => array( 'filterQueryId', 'filterSource' ),
					'bricks_2_3'     => array(
						'choices_js' => array(
							'description'     => 'Enhanced select powered by Choices.js library (@since 2.3). Adds search, multiple selection, and advanced styling.',
							'choicesJs'       => 'Enable enhanced select (checkbox). Requires filterAction=filter or empty.',
							'choicesPosition' => 'Dropdown position: auto (default) | bottom | top.',
							'search'          => array(
								'choicesSearch'            => 'Enable search within dropdown (checkbox).',
								'choicesSearchPlaceholder' => 'Search input placeholder text. Default: "Search".',
								'choicesNoResultsText'     => 'Text shown when search returns no results. Default: "No results found".',
								'choicesNoChoicesText'     => 'Text shown when no choices exist or all are selected.',
								'styling_note'             => 'Styling: choicesSearchBackground, choicesSearchTypography, choicesSearchInputTypography, choicesSearchInputPadding.',
							),
							'multiple'        => array(
								'enableMultiple'   => 'Enable multiple option selection (checkbox). Requires choicesJs + filterAction=filter.',
								'filterMultiLogic' => 'Logic for combining multiple values (now requires choicesJs + enableMultiple in 2.3).',
								'styling_note'     => 'Pill styling: choicesPillGap, choicesPillBackground, choicesPillBorder, choicesPillTypography.',
							),
							'styling_note'    => 'General: choicesPadding, choicesBackgroundColor, choicesBorderBase, choicesBorderColor, choicesBorderRadius, choicesFontSize, choicesTextColor, choicesArrowColor. Item: choicesItemPadding, choicesDropdownBackground, choicesHighlightBackground, choicesHighlightTextColor, choicesDisabledBackground, choicesDisabledTextColor.',
						),
					),
				),
				'filter-search'         => array(
					'label'           => 'Filter - Search (Live Search)',
					'supports_source' => array(),
					'required'        => array( 'filterQueryId' ),
				),
				'filter-range'          => array(
					'label'           => 'Filter - Range (slider)',
					'supports_source' => array( 'taxonomy', 'wpField', 'customField' ),
					'required'        => array( 'filterQueryId', 'filterSource' ),
					'bricks_2_3'     => array(
						'decimalPlaces'         => 'Number of decimal places to display (number, default: 0). Applies to both slider and input modes.',
						'inputUseCustomStepper' => 'Show custom +/- stepper buttons (checkbox, requires displayMode=input). Renders increment/decrement buttons next to min and max inputs.',
						'stepper_styling_note'  => 'Stepper styling controls (require inputUseCustomStepper): inputStepperGap, inputStepperMarginStart, inputStepperBackground, inputStepperBorder, inputStepperTypography.',
					),
				),
				'filter-datepicker'     => array(
					'label'           => 'Filter - Datepicker',
					'supports_source' => array( 'wpField', 'customField' ),
					'required'        => array( 'filterQueryId', 'filterSource' ),
					'bricks_2_3'     => array(
						'dateFormat' => 'Custom display format for the datepicker using flatpickr tokens (e.g. "d/m/Y", "M j, Y"). Default: "Y-m-d". Only affects the visual display — database storage format is determined separately. @since 2.3.',
					),
				),
				'filter-submit'         => array(
					'label'           => 'Filter - Submit button',
					'supports_source' => array(),
					'required'        => array( 'filterQueryId' ),
					'note'            => 'Use when filterApplyOn=click on other filters. Triggers filter application.',
				),
				'filter-active-filters' => array(
					'label'           => 'Filter - Active Filters display',
					'supports_source' => array(),
					'required'        => array( 'filterQueryId' ),
					'note'            => 'Shows currently active filters as removable tags.',
				),
			),
			'common_settings'     => array(
				'filterQueryId'  => 'Element ID of the target query loop element (the container/posts element with hasLoop: true)',
				'filterSource'   => 'taxonomy | wpField | customField — what data type to filter by',
				'filterAction'   => 'filter (default) | sort | per_page — what the filter does',
				'filterApplyOn'  => 'change (default, instant) | click (requires filter-submit element)',
				'filterNiceName' => 'URL parameter name (optional, e.g. "_color"). Use unique prefix to avoid conflicts.',
				'filterTaxonomy' => 'Taxonomy slug when filterSource=taxonomy, e.g. "category"',
				'wpPostField'    => 'WordPress post field when filterSource=wpField: post_id | post_date | post_author | post_type | post_status | post_modified',
			),
			'filterQueryId_note'  => 'filterQueryId must be the 6-character Bricks element ID of the query loop container, NOT a post ID. Get it from the element array (element["id"]).',
			'workflow_example'    => array(
				'1. Create posts query loop'     => 'Add container element with hasLoop:true and query.objectType:post, query.post_type:["post"]',
				'2. Note the element ID'         => 'The container element ID (e.g. "abc123") is the filterQueryId for all filters on this page',
				'3. Add filter elements'         => 'Add filter-checkbox, set filterQueryId="abc123", filterSource="taxonomy", filterTaxonomy="category"',
				'4. Enable in Bricks settings'   => 'Bricks > Settings > Performance > Enable query sort / filter / live search',
				'5. Rebuild index'               => 'Bricks automatically indexes on post save. May need manual reindex after enabling.',
			),
		);
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
		return array(
			'description'    => 'Element visibility conditions — show/hide elements based on runtime context. Stored in element settings[\'_conditions\']. Distinct from template conditions (which control page targeting).',
			'data_structure' => array(
				'description' => '_conditions is an array of condition SETS. Outer array = OR logic (any set passing renders element). Inner arrays = AND logic (all conditions in a set must pass).',
				'format'      => '[[{key, compare, value}, {key, compare, value}], [{key, compare, value}]]',
				'example'     => 'Show if (logged in AND admin) OR (post author): [[{"key":"user_logged_in","compare":"==","value":"1"},{"key":"user_role","compare":"==","value":["administrator"]}],[{"key":"post_author","compare":"==","value":"1"}]]',
			),
			'groups'         => array(
				array(
					'name'  => 'post',
					'label' => 'Post',
					'keys'  => array(
						array(
							'key'         => 'post_id',
							'label'       => 'Post ID',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (numeric)',
							'description' => 'Current post ID',
						),
						array(
							'key'         => 'post_title',
							'label'       => 'Post title',
							'compare'     => array( '==', '!=', 'contains', 'contains_not' ),
							'value_type'  => 'string',
							'description' => 'Current post title',
						),
						array(
							'key'         => 'post_parent',
							'label'       => 'Post parent',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (numeric, default 0)',
							'description' => 'Parent post ID',
						),
						array(
							'key'         => 'post_status',
							'label'       => 'Post status',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'array (status slugs, e.g. ["publish","draft"])',
							'description' => 'Post status',
						),
						array(
							'key'         => 'post_author',
							'label'       => 'Post author',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'string (user ID)',
							'description' => 'Post author user ID',
						),
						array(
							'key'         => 'post_date',
							'label'       => 'Post date',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (Y-m-d)',
							'description' => 'Post publish date',
						),
						array(
							'key'         => 'featured_image',
							'label'       => 'Featured image',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'string ("1" = set, "0" = not set)',
							'description' => 'Whether post has featured image',
						),
					),
				),
				array(
					'name'  => 'user',
					'label' => 'User',
					'keys'  => array(
						array(
							'key'         => 'user_logged_in',
							'label'       => 'User login',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'string ("1" = logged in, "0" = logged out)',
							'description' => 'Login status',
						),
						array(
							'key'         => 'user_id',
							'label'       => 'User ID',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (numeric)',
							'description' => 'Current user ID',
						),
						array(
							'key'         => 'user_registered',
							'label'       => 'User registered',
							'compare'     => array( '<', '>' ),
							'value_type'  => 'string (Y-m-d)',
							'description' => 'Registration date (< = after date, > = before date)',
						),
						array(
							'key'         => 'user_role',
							'label'       => 'User role',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'array (role slugs, e.g. ["administrator","editor"])',
							'description' => 'User role(s)',
						),
					),
				),
				array(
					'name'  => 'date',
					'label' => 'Date & time',
					'keys'  => array(
						array(
							'key'         => 'weekday',
							'label'       => 'Weekday',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (1-7, Monday=1 through Sunday=7)',
							'description' => 'Day of week',
						),
						array(
							'key'         => 'date',
							'label'       => 'Date',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (Y-m-d)',
							'description' => 'Current date (uses WP timezone)',
						),
						array(
							'key'         => 'time',
							'label'       => 'Time',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (H:i, e.g. "09:00")',
							'description' => 'Current time (uses WP timezone)',
						),
						array(
							'key'         => 'datetime',
							'label'       => 'Datetime',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (Y-m-d h:i a)',
							'description' => 'Date and time combined (uses WP timezone)',
						),
					),
				),
				array(
					'name'  => 'other',
					'label' => 'Other',
					'keys'  => array(
						array(
							'key'          => 'dynamic_data',
							'label'        => 'Dynamic data',
							'compare'      => array( '==', '!=', '>=', '<=', '>', '<', 'contains', 'contains_not', 'empty', 'empty_not' ),
							'value_type'   => 'string (comparison value; can contain dynamic data tags)',
							'description'  => 'Compare any dynamic data tag output. IMPORTANT: Set the dynamic data tag in the "dynamic_data" field (e.g. "{acf_my_field}"), and the comparison target in "value".',
							'extra_fields' => array( 'dynamic_data' => 'string — the dynamic data tag to evaluate (e.g. "{acf_my_field}", "{post_author_id}")' ),
						),
						array(
							'key'         => 'browser',
							'label'       => 'Browser',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'string (chrome, firefox, safari, edge, opera, msie)',
							'description' => 'Browser detection via user agent',
						),
						array(
							'key'         => 'operating_system',
							'label'       => 'Operating system',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'string (windows, mac, linux, ubuntu, iphone, ipad, ipod, android, blackberry, webos)',
							'description' => 'OS detection via user agent',
						),
						array(
							'key'         => 'current_url',
							'label'       => 'Current URL',
							'compare'     => array( '==', '!=', 'contains', 'contains_not' ),
							'value_type'  => 'string',
							'description' => 'Current page URL including query parameters',
						),
						array(
							'key'         => 'referer',
							'label'       => 'Referrer URL',
							'compare'     => array( '==', '!=', 'contains', 'contains_not' ),
							'value_type'  => 'string',
							'description' => 'HTTP referrer URL',
						),
					),
				),
			),
			'woocommerce_group' => array(
				'note'  => 'WooCommerce conditions are available only when WooCommerce is active.',
				'name'  => 'woocommerce',
				'label' => 'WooCommerce',
				'keys'  => array(
					array(
						'key'        => 'woo_product_type',
						'label'      => 'Product type',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string (simple, grouped, external, variable)',
					),
					array(
						'key'        => 'woo_product_sale',
						'label'      => 'Product sale status',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = on sale, "0" = not)',
					),
					array(
						'key'        => 'woo_product_new',
						'label'      => 'Product new status',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = new, "0" = not)',
					),
					array(
						'key'        => 'woo_product_stock_status',
						'label'      => 'Product stock status',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string (instock, outofstock, onbackorder)',
					),
					array(
						'key'        => 'woo_product_stock_quantity',
						'label'      => 'Product stock quantity',
						'compare'    => array( '==', '!=', '>=', '<=', '>', '<' ),
						'value_type' => 'string (numeric)',
					),
					array(
						'key'        => 'woo_product_stock_management',
						'label'      => 'Product stock management',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = enabled, "0" = disabled)',
					),
					array(
						'key'        => 'woo_product_sold_individually',
						'label'      => 'Product sold individually',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = enabled, "0" = disabled)',
					),
					array(
						'key'        => 'woo_product_purchased_by_user',
						'label'      => 'Product purchased by user',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = true, "0" = false)',
					),
					array(
						'key'        => 'woo_product_featured',
						'label'      => 'Product featured',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = true, "0" = false)',
					),
					array(
						'key'        => 'woo_product_rating',
						'label'      => 'Product rating',
						'compare'    => array( '==', '!=', '>=', '<=', '>', '<' ),
						'value_type' => 'string (numeric, average rating)',
					),
					array(
						'key'        => 'woo_product_category',
						'label'      => 'Product category',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'array (term IDs)',
					),
					array(
						'key'        => 'woo_product_tag',
						'label'      => 'Product tag',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'array (term IDs)',
					),
				),
			),
			'examples'          => array(
				'logged_in_only'          => array(
					'description' => 'Show element only to logged-in users',
					'conditions'  => array( array( array( 'key' => 'user_logged_in', 'compare' => '==', 'value' => '1' ) ) ),
				),
				'admin_or_editor'         => array(
					'description' => 'Show element to administrators or editors',
					'conditions'  => array( array( array( 'key' => 'user_role', 'compare' => '==', 'value' => array( 'administrator', 'editor' ) ) ) ),
				),
				'weekday_business_hours'  => array(
					'description' => 'Show element Monday-Friday between 9am-5pm',
					'conditions'  => array(
						array(
							array( 'key' => 'weekday', 'compare' => '>=', 'value' => '1' ),
							array( 'key' => 'weekday', 'compare' => '<=', 'value' => '5' ),
							array( 'key' => 'time', 'compare' => '>=', 'value' => '09:00' ),
							array( 'key' => 'time', 'compare' => '<=', 'value' => '17:00' ),
						),
					),
				),
				'dynamic_data_acf'        => array(
					'description' => 'Show element when ACF field "show_banner" is true',
					'conditions'  => array( array( array( 'key' => 'dynamic_data', 'compare' => '==', 'value' => '1', 'dynamic_data' => '{acf_show_banner}' ) ) ),
				),
				'or_logic'                => array(
					'description' => 'Show to admins OR when post has featured image (two condition sets = OR)',
					'conditions'  => array(
						array( array( 'key' => 'user_role', 'compare' => '==', 'value' => array( 'administrator' ) ) ),
						array( array( 'key' => 'featured_image', 'compare' => '==', 'value' => '1' ) ),
					),
				),
			),
			'notes'             => array(
				'Element conditions (this schema) are DIFFERENT from template conditions (template_condition tool). Template conditions use "main" key and control which pages a template targets. Element conditions use "key/compare/value" and control whether an individual element renders.',
				'Third-party plugins can register custom condition keys via the bricks/conditions/options filter. Unknown keys are accepted with a warning.',
				'Conditions are evaluated server-side at render time by Bricks Conditions::check(). The MCP only configures conditions — it does not evaluate them.',
			),
		);
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
		return array(
			'description'              => 'Bricks form element settings reference. Forms are standard elements (name: "form") added via element:add or page:update_content.',
			'field_types'              => array(
				'text'       => array(
					'description' => 'Single-line text input',
					'properties'  => array( 'placeholder', 'required', 'minLength', 'maxLength', 'pattern', 'width' ),
				),
				'email'      => array(
					'description' => 'Email input with validation',
					'properties'  => array( 'placeholder', 'required', 'width' ),
				),
				'textarea'   => array(
					'description' => 'Multi-line text',
					'properties'  => array( 'placeholder', 'required', 'height', 'width' ),
				),
				'richtext'   => array(
					'description' => 'TinyMCE rich text editor (since 2.1)',
					'properties'  => array( 'height', 'width' ),
				),
				'tel'        => array(
					'description' => 'Telephone input',
					'properties'  => array( 'placeholder', 'pattern', 'width' ),
				),
				'number'     => array(
					'description' => 'Numeric input',
					'properties'  => array( 'min', 'max', 'step', 'width' ),
				),
				'url'        => array(
					'description' => 'URL input',
					'properties'  => array( 'placeholder', 'width' ),
				),
				'password'   => array(
					'description' => 'Password with optional toggle',
					'properties'  => array( 'placeholder', 'required', 'width' ),
				),
				'select'     => array(
					'description' => 'Dropdown select',
					'properties'  => array( 'options (newline-separated string)', 'valueLabelOptions (bool)', 'required', 'width' ),
				),
				'checkbox'   => array(
					'description' => 'Checkbox group',
					'properties'  => array( 'options (newline-separated string)', 'valueLabelOptions (bool)', 'required', 'width' ),
				),
				'radio'      => array(
					'description' => 'Radio button group',
					'properties'  => array( 'options (newline-separated string)', 'valueLabelOptions (bool)', 'required', 'width' ),
				),
				'file'       => array(
					'description' => 'File upload',
					'properties'  => array( 'fileUploadLimit', 'fileUploadSize', 'fileUploadAllowedTypes', 'fileUploadStorage', 'width' ),
				),
				'datepicker' => array(
					'description' => 'Date/time picker (Flatpickr)',
					'properties'  => array( 'time (bool)', 'l10n (language code)', 'width' ),
				),
				'image'      => array(
					'description' => 'Image picker (since 2.1)',
					'properties'  => array( 'width' ),
				),
				'gallery'    => array(
					'description' => 'Gallery picker',
					'properties'  => array( 'width' ),
				),
				'hidden'     => array(
					'description' => 'Hidden field',
					'properties'  => array( 'value' ),
				),
				'html'       => array(
					'description' => 'Static HTML output (not an input)',
					'properties'  => array(),
				),
				'rememberme' => array(
					'description' => 'Remember me checkbox (for login forms)',
					'properties'  => array(),
				),
			),
			'field_required_properties' => array(
				'id'   => '6-char lowercase alphanumeric (e.g. abc123) — REQUIRED on every field',
				'type' => 'One of the field types listed above — REQUIRED',
			),
			'field_common_properties'  => array(
				'label'        => 'string — displayed above the field',
				'placeholder'  => 'string — hint text inside the field',
				'value'        => 'string — default value',
				'required'     => 'bool — marks field as required',
				'width'        => 'number (0-100) — column width as percentage (100 = full width)',
				'name'         => 'string — custom name attribute (defaults to form-field-{id})',
				'errorMessage' => 'string — custom validation error message',
				'isHoneypot'   => 'bool — invisible spam trap (always available, no API key needed)',
			),
			'actions'                  => array(
				'email'        => array(
					'description'   => 'Send email notification',
					'required_keys' => array( 'emailSubject', 'emailTo' ),
					'optional_keys' => array( 'emailToCustom (when emailTo=custom)', 'emailBcc', 'fromEmail', 'fromName', 'replyToEmail', 'emailContent (use {{field_id}} or {{all_fields}})', 'htmlEmail (bool, default true)', 'emailErrorMessage' ),
					'confirmation'  => 'For confirmation email to submitter: confirmationEmailSubject, confirmationEmailContent, confirmationEmailTo',
				),
				'redirect'     => array(
					'description'   => 'Redirect after submission (always runs LAST regardless of position in actions array)',
					'required_keys' => array( 'redirect (URL)' ),
					'optional_keys' => array( 'redirectTimeout (ms delay)' ),
				),
				'webhook'      => array(
					'description'    => 'POST data to external URL (since 2.0)',
					'required_keys'  => array( 'webhooks (array of objects)' ),
					'webhook_object' => array(
						'name'         => 'string — endpoint label',
						'url'          => 'string — endpoint URL',
						'contentType'  => 'json or form-data (default: json)',
						'dataTemplate' => 'string — JSON template with {{field_id}} placeholders; empty sends all fields',
						'headers'      => 'string — JSON headers e.g. {"Authorization": "Bearer token"}',
					),
					'optional_keys'  => array( 'webhookMaxSize (KB, default 1024)', 'webhookErrorIgnore (bool)' ),
				),
				'login'        => array(
					'description'   => 'User login',
					'required_keys' => array( 'loginName (field ID for username/email)', 'loginPassword (field ID for password)' ),
					'optional_keys' => array( 'loginRemember (field ID for remember me)', 'loginErrorMessage' ),
				),
				'registration' => array(
					'description'   => 'User registration',
					'required_keys' => array( 'registrationEmail (field ID)', 'registrationPassword (field ID)' ),
					'optional_keys' => array( 'registrationUserName (field ID)', 'registrationFirstName (field ID)', 'registrationLastName (field ID)', 'registrationRole (slug, NEVER administrator)', 'registrationAutoLogin (bool)', 'registrationPasswordMinLength (default 6)', 'registrationWPNotification (bool)' ),
				),
				'create-post'  => array(
					'description'   => 'Create a WordPress post from form data (since 2.1)',
					'required_keys' => array( 'createPostType (post type slug)', 'createPostTitle (field ID)' ),
					'optional_keys' => array( 'createPostContent (field ID)', 'createPostExcerpt (field ID)', 'createPostFeaturedImage (field ID)', 'createPostStatus (draft/publish)', 'createPostMeta (repeater: metaKey, metaValue, sanitizationMethod)', 'createPostTaxonomies (repeater: taxonomy, fieldId)' ),
				),
				'custom'       => array(
					'description'   => 'Custom action via bricks/form/custom_action hook',
					'required_keys' => array(),
				),
			),
			'general_settings'         => array(
				'successMessage'            => 'string — shown after successful submit',
				'submitButtonText'          => 'string — button text (default: Send)',
				'requiredAsterisk'          => 'bool — show asterisk on required fields',
				'showLabels'                => 'bool — show field labels',
				'enableRecaptcha'           => 'bool — Google reCAPTCHA v3 (needs API key in Bricks settings)',
				'enableHCaptcha'            => 'bool — hCaptcha (needs API key in Bricks settings)',
				'enableTurnstile'           => 'bool — Cloudflare Turnstile (needs API key in Bricks settings)',
				'disableBrowserValidation'  => 'bool — add novalidate attribute',
				'validateAllFieldsOnSubmit' => 'bool — show all errors on submit, not just first',
			),
			'examples'                 => array(
				'contact_form'      => array(
					'fields'           => array(
						array(
							'id'          => 'abc123',
							'type'        => 'text',
							'label'       => 'Name',
							'placeholder' => 'Your Name',
							'width'       => 100,
						),
						array(
							'id'          => 'def456',
							'type'        => 'email',
							'label'       => 'Email',
							'placeholder' => 'you@example.com',
							'required'    => true,
							'width'       => 100,
						),
						array(
							'id'          => 'ghi789',
							'type'        => 'textarea',
							'label'       => 'Message',
							'placeholder' => 'Your Message',
							'required'    => true,
							'width'       => 100,
						),
					),
					'actions'          => array( 'email' ),
					'emailSubject'     => 'Contact form request',
					'emailTo'          => 'admin_email',
					'htmlEmail'        => true,
					'successMessage'   => 'Thank you! We will get back to you soon.',
					'submitButtonText' => 'Send Message',
				),
				'login_form'        => array(
					'fields'           => array(
						array(
							'id'       => 'lgn001',
							'type'     => 'email',
							'label'    => 'Email',
							'required' => true,
							'width'    => 100,
						),
						array(
							'id'       => 'lgn002',
							'type'     => 'password',
							'label'    => 'Password',
							'required' => true,
							'width'    => 100,
						),
						array(
							'id'    => 'lgn003',
							'type'  => 'rememberme',
							'label' => 'Remember Me',
						),
					),
					'actions'          => array( 'login', 'redirect' ),
					'loginName'        => 'lgn001',
					'loginPassword'    => 'lgn002',
					'loginRemember'    => 'lgn003',
					'redirect'         => '/account',
					'submitButtonText' => 'Log In',
				),
				'registration_form' => array(
					'fields'                => array(
						array(
							'id'       => 'reg001',
							'type'     => 'text',
							'label'    => 'Username',
							'required' => true,
							'width'    => 100,
						),
						array(
							'id'       => 'reg002',
							'type'     => 'email',
							'label'    => 'Email',
							'required' => true,
							'width'    => 100,
						),
						array(
							'id'       => 'reg003',
							'type'     => 'password',
							'label'    => 'Password',
							'required' => true,
							'width'    => 100,
						),
					),
					'actions'               => array( 'registration', 'redirect' ),
					'registrationUserName'  => 'reg001',
					'registrationEmail'     => 'reg002',
					'registrationPassword'  => 'reg003',
					'registrationRole'      => 'subscriber',
					'registrationAutoLogin' => true,
					'redirect'              => '/welcome',
					'successMessage'        => 'Registration successful!',
					'submitButtonText'      => 'Create Account',
				),
			),
			'notes'                    => array(
				'Field IDs must be 6-char lowercase alphanumeric (same format as element IDs). Bricks uses form-field-{id} as the submission key.',
				'Options for select/checkbox/radio use newline-separated strings: "Option 1\nOption 2\nOption 3" — NOT arrays.',
				'Redirect action always runs last regardless of position in the actions array.',
				'CAPTCHA (reCAPTCHA, hCaptcha, Turnstile) requires API keys configured in Bricks > Settings > API Keys. Honeypot (isHoneypot: true) works without any configuration.',
				'Never set registrationRole to "administrator" — Bricks blocks this for security.',
				'Use {{field_id}} in emailContent/dataTemplate to reference field values. Use {{all_fields}} to include all fields.',
			),
		);
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
		return array(
			'description'       => 'Bricks element interaction/animation settings reference. Interactions are stored in settings._interactions as a repeater array on any element. Use element:update or page:update_content to add interactions.',
			'important'         => 'NEVER use deprecated _animation/_animationDuration/_animationDelay keys. Always use _interactions array. Each interaction needs a unique 6-char lowercase alphanumeric id field.',
			'triggers'          => array(
				'click'            => 'Element clicked',
				'mouseover'        => 'Mouse over element',
				'mouseenter'       => 'Mouse enters element',
				'mouseleave'       => 'Mouse leaves element',
				'focus'            => 'Element receives focus',
				'blur'             => 'Element loses focus',
				'enterView'        => 'Element enters viewport (IntersectionObserver)',
				'leaveView'        => 'Element leaves viewport',
				'animationEnd'     => 'Another interaction\'s animation ends (chain via animationId)',
				'contentLoaded'    => 'DOM content loaded (optional delay field)',
				'scroll'           => 'Window scroll reaches scrollOffset value',
				'mouseleaveWindow' => 'Mouse leaves browser window',
				'ajaxStart'        => 'Query loop AJAX starts (requires ajaxQueryId)',
				'ajaxEnd'          => 'Query loop AJAX ends (requires ajaxQueryId)',
				'formSubmit'       => 'Form submitted (requires formId)',
				'formSuccess'      => 'Form submission succeeded (requires formId)',
				'formError'        => 'Form submission failed (requires formId)',
			),
			'actions'           => array(
				'startAnimation'   => 'Run Animate.css animation (requires animationType)',
				'show'             => 'Show target element (remove display:none)',
				'hide'             => 'Hide target element (set display:none)',
				'click'            => 'Programmatically click target element',
				'setAttribute'     => 'Set HTML attribute on target',
				'removeAttribute'  => 'Remove HTML attribute from target',
				'toggleAttribute'  => 'Toggle HTML attribute on target',
				'toggleOffCanvas'  => 'Toggle Bricks off-canvas element',
				'loadMore'         => 'Load more results in query loop (requires loadMoreQuery)',
				'loadMoreGallery'  => 'Load more images in Image Gallery element (Bricks 2.3+). Configure loadMoreInitial, loadMoreStep, loadMoreInfiniteScroll, loadMoreInfiniteScrollDelay, loadMoreInfiniteScrollOffset on the Image Gallery element settings, then add this interaction action on the trigger element (e.g. a button)',
				'scrollTo'         => 'Smooth scroll to target element',
				'javascript'       => 'Call a global JS function (GSAP bridge, requires jsFunction)',
				'openAddress'      => 'Open map info box',
				'closeAddress'     => 'Close map info box',
				'clearForm'        => 'Clear form fields',
				'storageAdd'       => 'Add to browser storage',
				'storageRemove'    => 'Remove from browser storage',
				'storageCount'     => 'Count browser storage items',
			),
			'target_options'    => array(
				'self'   => 'The element the interaction is on (default)',
				'custom' => 'CSS selector in targetSelector field (e.g. "#brxe-abc123", ".my-class")',
				'popup'  => 'Popup template by templateId',
			),
			'interaction_fields' => array(
				'id'                    => 'Required. 6-char lowercase alphanumeric, unique per interaction',
				'trigger'               => 'Required. See triggers list',
				'action'                => 'Required. See actions list',
				'target'                => 'Optional. "self" (default), "custom", or "popup"',
				'targetSelector'        => 'Required when target="custom". Full CSS selector',
				'animationType'         => 'Required when action="startAnimation". See animation_types',
				'animationDuration'     => 'Optional. CSS time value, e.g. "0.8s" or "800ms" (default "1s")',
				'animationDelay'        => 'Optional. CSS time value, e.g. "0.3s" (default "0s")',
				'rootMargin'            => 'Optional for enterView. IntersectionObserver rootMargin, e.g. "0px 0px -80px 0px"',
				'runOnce'               => 'Optional boolean. Animate only on first trigger occurrence',
				'delay'                 => 'Optional for contentLoaded. Delay before execution, e.g. "0.5s"',
				'scrollOffset'          => 'Optional for scroll trigger. Offset value in px/vh/%',
				'animationId'           => 'Required for animationEnd trigger. ID of the interaction to wait for',
				'jsFunction'            => 'Required for javascript action. Global function name, e.g. "myAnimations.parallax"',
				'jsFunctionArgs'        => 'Optional for javascript action. Array of {id, jsFunctionArg} objects. Use "%brx%" for Bricks params object',
				'disablePreventDefault' => 'Optional boolean for click trigger. Allow link default behavior',
				'ajaxQueryId'           => 'Required for ajaxStart/ajaxEnd triggers',
				'formId'                => 'Required for formSubmit/formSuccess/formError triggers',
				'templateId'            => 'Required for target="popup"',
				'loadMoreQuery'         => 'Required for loadMore action. Typically "main"',
				'loadMoreTargetSelector' => 'Required for loadMoreGallery action. CSS selector of the Image Gallery element, e.g. "#brxe-abc123"',
				'interactionConditions' => 'Optional. Array of condition objects for conditional execution',
			),
			'animation_types'   => array(
				'attention'  => array( 'bounce', 'flash', 'pulse', 'rubberBand', 'shakeX', 'shakeY', 'headShake', 'swing', 'tada', 'wobble', 'jello', 'heartBeat' ),
				'back'       => array( 'backInDown', 'backInLeft', 'backInRight', 'backInUp', 'backOutDown', 'backOutLeft', 'backOutRight', 'backOutUp' ),
				'bounce'     => array( 'bounceIn', 'bounceInDown', 'bounceInLeft', 'bounceInRight', 'bounceInUp', 'bounceOut', 'bounceOutDown', 'bounceOutLeft', 'bounceOutRight', 'bounceOutUp' ),
				'fade'       => array( 'fadeIn', 'fadeInDown', 'fadeInDownBig', 'fadeInLeft', 'fadeInLeftBig', 'fadeInRight', 'fadeInRightBig', 'fadeInUp', 'fadeInUpBig', 'fadeInTopLeft', 'fadeInTopRight', 'fadeInBottomLeft', 'fadeInBottomRight', 'fadeOut', 'fadeOutDown', 'fadeOutDownBig', 'fadeOutLeft', 'fadeOutLeftBig', 'fadeOutRight', 'fadeOutRightBig', 'fadeOutUp', 'fadeOutUpBig', 'fadeOutTopLeft', 'fadeOutTopRight', 'fadeOutBottomRight', 'fadeOutBottomLeft' ),
				'flip'       => array( 'flip', 'flipInX', 'flipInY', 'flipOutX', 'flipOutY' ),
				'lightspeed' => array( 'lightSpeedInRight', 'lightSpeedInLeft', 'lightSpeedOutRight', 'lightSpeedOutLeft' ),
				'rotate'     => array( 'rotateIn', 'rotateInDownLeft', 'rotateInDownRight', 'rotateInUpLeft', 'rotateInUpRight', 'rotateOut', 'rotateOutDownLeft', 'rotateOutDownRight', 'rotateOutUpLeft', 'rotateOutUpRight' ),
				'special'    => array( 'hinge', 'jackInTheBox', 'rollIn', 'rollOut' ),
				'zoom'       => array( 'zoomIn', 'zoomInDown', 'zoomInLeft', 'zoomInRight', 'zoomInUp', 'zoomOut', 'zoomOutDown', 'zoomOutLeft', 'zoomOutRight', 'zoomOutUp' ),
				'slide'      => array( 'slideInUp', 'slideInDown', 'slideInLeft', 'slideInRight', 'slideOutUp', 'slideOutDown', 'slideOutLeft', 'slideOutRight' ),
			),
			'examples'          => array(
				'scroll_reveal'  => array(
					'description'   => 'Fade in element when scrolled into view',
					'_interactions' => array(
						array(
							'id'                => 'aa1bb2',
							'trigger'           => 'enterView',
							'rootMargin'        => '0px 0px -80px 0px',
							'action'            => 'startAnimation',
							'animationType'     => 'fadeInUp',
							'animationDuration' => '0.8s',
							'animationDelay'    => '0s',
							'target'            => 'self',
							'runOnce'           => true,
						),
					),
				),
				'stagger_cards'  => array(
					'description'          => 'Three cards fade in with incremental delays (apply to each card element)',
					'card_1_interactions'  => array(
						array( 'id' => 'cc3dd4', 'trigger' => 'enterView', 'action' => 'startAnimation', 'animationType' => 'fadeInUp', 'animationDuration' => '0.8s', 'animationDelay' => '0s', 'target' => 'self', 'runOnce' => true ),
					),
					'card_2_interactions'  => array(
						array( 'id' => 'ee5ff6', 'trigger' => 'enterView', 'action' => 'startAnimation', 'animationType' => 'fadeInUp', 'animationDuration' => '0.8s', 'animationDelay' => '0.15s', 'target' => 'self', 'runOnce' => true ),
					),
					'card_3_interactions'  => array(
						array( 'id' => 'gg7hh8', 'trigger' => 'enterView', 'action' => 'startAnimation', 'animationType' => 'fadeInUp', 'animationDuration' => '0.8s', 'animationDelay' => '0.3s', 'target' => 'self', 'runOnce' => true ),
					),
				),
				'chained_hero'   => array(
					'description'            => 'Hero title animates on load, then subtitle fades in after title finishes',
					'title_interactions'     => array(
						array( 'id' => 'ii9jj0', 'trigger' => 'contentLoaded', 'action' => 'startAnimation', 'animationType' => 'fadeInDown', 'animationDuration' => '0.8s', 'animationDelay' => '0s', 'target' => 'self' ),
					),
					'subtitle_interactions'  => array(
						array( 'id' => 'kk1ll2', 'trigger' => 'animationEnd', 'animationId' => 'ii9jj0', 'action' => 'startAnimation', 'animationType' => 'fadeIn', 'animationDuration' => '0.6s', 'animationDelay' => '0s', 'target' => 'self' ),
					),
				),
				'native_parallax' => array(
					'description'          => 'Native parallax (Bricks 2.3+). Style properties under Transform group — no GSAP or interactions needed. Prefer this over GSAP for simple parallax.',
					'element_parallax'     => array(
						'_motionElementParallax'       => true,
						'_motionElementParallaxSpeedX'  => 0,
						'_motionElementParallaxSpeedY'  => -20,
						'_motionStartVisiblePercent'    => 0,
					),
					'background_parallax'  => array(
						'_motionBackgroundParallax'      => true,
						'_motionBackgroundParallaxSpeed' => -15,
						'_motionStartVisiblePercent'     => 0,
					),
					'notes'                => array(
						'Speed values are percentages. Negative = opposite scroll direction.',
						'_motionStartVisiblePercent: 0 = element entering viewport, 50 = near center.',
						'Not visible in builder preview — only on live frontend.',
						'These are style properties, NOT interactions. Set directly on element settings.',
					),
				),
				'gsap_parallax'  => array(
					'description'                => 'GSAP ScrollTrigger parallax via javascript action. Requires GSAP loaded on page. For simple parallax, prefer native_parallax example above. Use GSAP only for advanced control (custom easing, scrub values, timeline sequencing).',
					'step_1_page_script'         => 'Use page:update_settings with customScriptsBodyFooter to add: <script>document.addEventListener("DOMContentLoaded",function(){if(typeof gsap==="undefined")return;gsap.registerPlugin(ScrollTrigger);window.brxGsap={parallax:function(b){gsap.to(b.source,{yPercent:-20,ease:"none",scrollTrigger:{trigger:b.source,scrub:1}})}}});</script>',
					'step_2_element_interaction'  => array(
						array( 'id' => 'mm3nn4', 'trigger' => 'contentLoaded', 'action' => 'javascript', 'jsFunction' => 'brxGsap.parallax', 'jsFunctionArgs' => array( array( 'id' => 'oo5pp6', 'jsFunctionArg' => '%brx%' ) ), 'target' => 'self' ),
					),
				),
			),
			'image_gallery_load_more' => array(
					'description'               => 'Image Gallery with load more + infinite scroll (Bricks 2.3+). Step 1: Set load more settings on the Image Gallery element. Step 2: Add a button with loadMoreGallery interaction targeting the gallery.',
					'step_1_gallery_settings'   => array(
						'note'                          => 'Set these on the Image Gallery element settings (not in _interactions)',
						'loadMoreInitial'               => 6,
						'loadMoreStep'                  => 3,
						'loadMoreInfiniteScroll'        => true,
						'loadMoreInfiniteScrollDelay'   => '600ms',
						'loadMoreInfiniteScrollOffset'  => '200px',
					),
					'step_2_button_interactions' => array(
						'note'           => 'Add this interaction on the button or trigger element. loadMoreGallery does not use target/targetSelector — it uses loadMoreTargetSelector instead.',
						'_interactions'  => array(
							array(
								'id'                     => 'pp7qq8',
								'trigger'                => 'click',
								'action'                 => 'loadMoreGallery',
								'loadMoreTargetSelector' => '#brxe-{galleryElementId}',
							),
						),
					),
				),
			'notes'             => array(
				'Each interaction id must be unique — 6-char lowercase alphanumeric (same format as element IDs).',
				'Animation types containing "In" (case-sensitive) automatically hide the element on page load and reveal on animation.',
				'Use "In" types for enterView/contentLoaded triggers. "Out" types are for exit animations or click-triggered hiding.',
				'Bricks auto-enqueues Animate.css when startAnimation action is detected — no manual enqueue needed.',
				'For GSAP: the plugin does NOT enqueue GSAP. The site owner must load it (CDN or local). AI should inject via page:update_settings customScriptsBodyFooter.',
				'The deprecated _animation, _animationDuration, _animationDelay keys still work but show converter warnings. Never generate them.',
			),
		);
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
