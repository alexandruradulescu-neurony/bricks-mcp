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

	private const NEXT_STEP = 'Review the suggested_schema below. Replace [PLACEHOLDER] content with real text, adjust structure if needed, then pass it directly to build_from_schema with this proposal_id. The schema is already valid — only content needs changing.';

	private GlobalClassService $class_service;
	private SchemaGenerator $schema_generator;
	private SchemaSkeletonGenerator $skeleton_generator;

	public function __construct( GlobalClassService $class_service, SchemaGenerator $schema_generator ) {
		$this->class_service        = $class_service;
		$this->schema_generator     = $schema_generator;
		$this->skeleton_generator   = new SchemaSkeletonGenerator();
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

		// 6. Generate summary.
		$summary = $this->generate_summary(
			$element_types,
			$all_classes,
			$suggested_classes,
			$scoped_variables,
			$design_brief,
			$business_brief
		);

		// 7. Generate proposal ID and store.
		$proposal_id = 'prop_' . substr( md5( (string) time() . wp_generate_password( 8 ) ), 0, 12 );

		$proposal = [
			'proposal_id' => $proposal_id,
			'page_id'     => $page_id,
			'description' => $description,
			'created_at'  => current_time( 'mysql' ),
			'summary'     => $summary,
			'next_step'   => self::NEXT_STEP,
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

		// Generate schema skeleton — the AI reviews and fills in content.
		$proposal['suggested_schema'] = $this->skeleton_generator->generate(
			$page_id,
			$description,
			$suggested_classes,
			$scoped_variables
		);

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

	/**
	 * Generate a human-readable summary of the proposal.
	 *
	 * Synthesizes resolved data into a concise summary for quick AI review.
	 *
	 * @param array<int, string>    $element_types     Detected element types.
	 * @param array<int, array>     $all_classes       All global classes.
	 * @param array<string, string> $suggested_classes Suggested classes map.
	 * @param array<string, array>  $scoped_variables  Scoped CSS variables.
	 * @param string                $design_brief      Design brief text.
	 * @param string                $business_brief    Business brief text.
	 * @return string Summary string.
	 */
	private function generate_summary(
		array $element_types,
		array $all_classes,
		array $suggested_classes,
		array $scoped_variables,
		string $design_brief,
		string $business_brief
	): string {
		$summary_parts = [];

		// Element types detected.
		$element_count = count( $element_types );
		if ( $element_count > 0 ) {
			$summary_parts[] = sprintf(
				'Detected %d element type(s): %s',
				$element_count,
				implode( ', ', $element_types )
			);
		}

		// Class availability.
		$class_count = count( $all_classes );
		$suggested_count = count( $suggested_classes );
		if ( $suggested_count > 0 ) {
			$summary_parts[] = sprintf(
				'Found %d matching global class(es): %s',
				$suggested_count,
				implode( ', ', array_keys( $suggested_classes ) )
			);
		} else {
			$summary_parts[] = 'No matching global classes found — new classes will be created for class_intent values';
		}

		// Variable scope.
		$var_categories = array_keys( $scoped_variables );
		$var_count = count( $var_categories );
		if ( $var_count > 0 ) {
			$summary_parts[] = sprintf(
				'Scoped %d variable category(ies): %s',
				$var_count,
				implode( ', ', $var_categories )
			);
		}

		// Briefs status.
		if ( '' !== $design_brief || '' !== $business_brief ) {
			$brief_parts = [];
			if ( '' !== $design_brief ) {
				$brief_parts[] = 'design';
			}
			if ( '' !== $business_brief ) {
				$brief_parts[] = 'business';
			}
			$summary_parts[] = sprintf(
				'%s brief(s) loaded: %s',
				count( $brief_parts ) === 2 ? 'Both' : ucfirst( $brief_parts[0] ),
				implode( ' + ', $brief_parts )
			);
		}

		return implode( '. ', $summary_parts ) . '.';
	}
}
