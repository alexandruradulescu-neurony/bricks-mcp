<?php
/**
 * Design proposal service.
 *
 * Creates lightweight design proposals that resolve an AI's description
 * against real site data: classes, variables, element schemas, and briefs.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProposalService {

	private const TTL = 600; // 10 minutes.

	private GlobalClassService $class_service;
	private SchemaGenerator $schema_generator;

	public function __construct( GlobalClassService $class_service, SchemaGenerator $schema_generator ) {
		$this->class_service    = $class_service;
		$this->schema_generator = $schema_generator;
	}

	/**
	 * Create a design proposal from a description.
	 *
	 * Resolves classes, variables, element schemas, and briefs against
	 * the actual site data. Returns everything the AI needs to write
	 * a valid schema.
	 *
	 * @param int    $page_id     Target page ID.
	 * @param string $description Free-text design description.
	 * @return array<string, mixed> Proposal with resolved data.
	 */
	public function create( int $page_id, string $description ): array|\WP_Error {
		$post = get_post( $page_id );
		if ( ! $post ) {
			return new \WP_Error( 'invalid_page', sprintf( 'Page %d not found.', $page_id ) );
		}

		// 1. Detect element types from description keywords.
		$element_types = $this->detect_element_types( $description );

		// 2. Get all global classes with styles.
		$all_classes = $this->class_service->get_global_classes();
		$class_summary = [];
		$suggested_classes = [];

		foreach ( $all_classes as $class ) {
			$name     = $class['name'] ?? '';
			$settings = $class['settings'] ?? [];
			$class_summary[] = [
				'name'     => $name,
				'id'       => $class['id'] ?? '',
				'has_styles' => ! empty( $settings ),
			];

			// Suggest classes that match description keywords.
			$desc_lower = strtolower( $description );
			$name_lower = strtolower( $name );
			$name_parts = preg_split( '/[-_]/', $name_lower );

			foreach ( $name_parts as $part ) {
				if ( strlen( $part ) > 2 && str_contains( $desc_lower, $part ) ) {
					$suggested_classes[ $name ] = $class['id'] ?? '';
					break;
				}
			}
		}

		// 3. Get scoped variables (only categories relevant to description).
		$categories = get_option( 'bricks_global_variables_categories', [] );
		$variables  = get_option( 'bricks_global_variables', [] );

		if ( ! is_array( $categories ) ) $categories = [];
		if ( ! is_array( $variables ) ) $variables = [];

		// Build category name map.
		$cat_names = [];
		foreach ( $categories as $cat ) {
			$cat_names[ $cat['id'] ?? '' ] = $cat['name'] ?? '';
		}

		// Determine which categories are relevant.
		$relevant_categories = $this->detect_relevant_categories( $description );

		$scoped_variables = [];
		foreach ( $variables as $var ) {
			$cat_id   = $var['category'] ?? '';
			$cat_name = $cat_names[ $cat_id ] ?? 'uncategorized';

			if ( in_array( $cat_name, $relevant_categories, true ) ) {
				if ( ! isset( $scoped_variables[ $cat_name ] ) ) {
					$scoped_variables[ $cat_name ] = [];
				}
				$scoped_variables[ $cat_name ][] = $var['name'] ?? '';
			}
		}

		// 4. Prefetch element schemas for detected types.
		$element_schemas = [];
		foreach ( $element_types as $type ) {
			$schema = $this->schema_generator->get_element_schema( $type );
			if ( ! is_wp_error( $schema ) ) {
				$element_schemas[ $type ] = [
					'label'           => $schema['label'] ?? $type,
					'category'        => $schema['category'] ?? 'general',
					'nesting'         => $schema['nesting'] ?? [],
					'working_example' => $schema['working_example'] ?? [],
				];
			}
		}

		// 5. Load briefs.
		$briefs = get_option( 'bricks_mcp_briefs', [] );
		$design_brief   = is_array( $briefs ) ? trim( $briefs['design_brief'] ?? '' ) : '';
		$business_brief = is_array( $briefs ) ? trim( $briefs['business_brief'] ?? '' ) : '';

		// 6. Generate proposal ID and store.
		$proposal_id = 'prop_' . substr( md5( (string) time() . wp_generate_password( 8 ) ), 0, 12 );

		$proposal = [
			'proposal_id' => $proposal_id,
			'page_id'     => $page_id,
			'description' => $description,
			'created_at'  => current_time( 'mysql' ),
			'resolved'    => [
				'classes' => [
					'available' => $class_summary,
					'suggested' => $suggested_classes,
				],
				'variables' => $scoped_variables,
				'elements'  => [
					'types_detected' => $element_types,
					'schemas'        => $element_schemas,
				],
				'briefs' => [],
			],
			'warnings' => [],
		];

		if ( '' !== $design_brief ) {
			$proposal['resolved']['briefs']['design'] = $design_brief;
		}
		if ( '' !== $business_brief ) {
			$proposal['resolved']['briefs']['business'] = $business_brief;
		}

		// Add warnings.
		if ( empty( $all_classes ) ) {
			$proposal['warnings'][] = 'No global classes exist. New classes will be created for every class_intent.';
		}
		if ( '' === $design_brief ) {
			$proposal['warnings'][] = 'No design brief set. Go to Bricks MCP > Briefs to add visual guidelines.';
		}
		if ( '' === $business_brief ) {
			$proposal['warnings'][] = 'No business brief set. Content will be generic placeholder text.';
		}

		// Store as transient.
		set_transient( "bricks_mcp_proposal_{$proposal_id}", $proposal, self::TTL );

		return $proposal;
	}

	/**
	 * Validate that a proposal exists and hasn't expired.
	 */
	public function validate( string $proposal_id ): bool {
		return false !== get_transient( "bricks_mcp_proposal_{$proposal_id}" );
	}

	/**
	 * Consume a proposal — returns stored data and deletes it.
	 */
	public function consume( string $proposal_id ): ?array {
		$proposal = get_transient( "bricks_mcp_proposal_{$proposal_id}" );
		if ( false === $proposal ) {
			return null;
		}
		delete_transient( "bricks_mcp_proposal_{$proposal_id}" );
		return is_array( $proposal ) ? $proposal : null;
	}

	/**
	 * Detect element types mentioned in the description.
	 */
	private function detect_element_types( string $description ): array {
		$desc = strtolower( $description );

		// Always include structural elements.
		$types = [ 'section', 'container', 'block' ];

		$keyword_map = [
			'heading'         => [ 'heading', 'title', 'h1', 'h2', 'h3', 'h4' ],
			'text-basic'      => [ 'text', 'paragraph', 'description', 'subtitle', 'subheading' ],
			'text-link'       => [ 'link', 'read more', 'view all', 'learn more' ],
			'button'          => [ 'button', 'cta', 'call to action' ],
			'image'           => [ 'image', 'photo', 'picture', 'thumbnail' ],
			'icon'            => [ 'icon' ],
			'icon-box'        => [ 'icon box', 'feature box' ],
			'video'           => [ 'video', 'youtube', 'vimeo' ],
			'divider'         => [ 'divider', 'separator', 'line' ],
			'tabs-nested'     => [ 'tab', 'tabs' ],
			'accordion-nested' => [ 'accordion', 'faq', 'collapsible' ],
			'slider-nested'   => [ 'slider', 'carousel', 'slideshow' ],
			'form'            => [ 'form', 'contact form', 'input' ],
			'counter'         => [ 'counter', 'number count', 'stat' ],
			'list'            => [ 'list', 'bullet' ],
			'pricing-tables'  => [ 'pricing', 'price table', 'plan' ],
			'div'             => [ 'overlay', 'wrapper' ],
		];

		foreach ( $keyword_map as $element_type => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( str_contains( $desc, $keyword ) ) {
					$types[] = $element_type;
					break;
				}
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Detect which variable categories are relevant to the description.
	 */
	private function detect_relevant_categories( string $description ): array {
		$desc = strtolower( $description );

		// Always include these.
		$categories = [ 'Spacing', 'Gaps/Padding' ];

		if ( preg_match( '/dark|light|color|background|overlay|gradient|accent|primary/', $desc ) ) {
			$categories[] = 'Colors';
		}
		if ( preg_match( '/radius|rounded|corner|pill|circle/', $desc ) ) {
			$categories[] = 'Radius';
		}
		if ( preg_match( '/font|text|heading|size|weight|typography/', $desc ) ) {
			$categories[] = 'Texts';
			$categories[] = 'Headings';
			$categories[] = 'Styles';
		}
		if ( preg_match( '/width|height|container|max|min|viewport/', $desc ) ) {
			$categories[] = 'Sizes';
		}
		if ( preg_match( '/grid|column|layout/', $desc ) ) {
			$categories[] = 'Grid';
		}

		return array_values( array_unique( $categories ) );
	}
}
