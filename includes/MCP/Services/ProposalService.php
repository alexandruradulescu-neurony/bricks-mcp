<?php
/**
 * Design proposal service.
 *
 * Two-phase design flow:
 * Phase 1 (Discovery): AI calls with description only → gets site context,
 *   element catalog, classes, variables, briefs. No proposal_id.
 * Phase 2 (Proposal): AI calls with description + design_plan → plan is
 *   validated, skeleton generated from AI's decisions, proposal_id returned.
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

	/**
	 * Valid section types for design_plan.
	 */
	private const VALID_SECTION_TYPES = [
		'hero', 'features', 'pricing', 'cta', 'testimonials', 'split', 'generic',
	];

	/**
	 * Valid layout types for design_plan.
	 */
	private const VALID_LAYOUTS = [
		'centered', 'split-60-40', 'split-50-50', 'grid-2', 'grid-3', 'grid-4',
	];

	/**
	 * Element purpose descriptions for discovery phase.
	 * The AI sees WHAT elements do, not Bricks internals.
	 */
	private const ELEMENT_PURPOSES = [
		'section'           => 'Full-width page section — the outermost wrapper for content rows',
		'container'         => 'Content container inside a section — controls max-width and centering',
		'block'             => 'Structural grouping element — use for columns, rows, card wrappers. Supports flex direction and CSS grid',
		'div'               => 'Small wrapper — for icon circles, overlays. Does NOT support flex direction',
		'heading'           => 'Title text (h1–h6) — use h1 for page titles, h2 for section titles, h3–h4 for card titles',
		'text-basic'        => 'Paragraph or body text — descriptions, subtitles, taglines',
		'text-link'         => 'Clickable text link — "View all →", "Read more", navigation links',
		'button'            => 'Call-to-action button — primary CTAs, secondary actions, form submits',
		'image'             => 'Image element — photos, illustrations, logos. Supports Unsplash auto-fetch',
		'icon'              => 'Single icon — decorative or functional. Uses Themify icon library (ti-*)',
		'icon-box'          => 'Icon + heading + text as a feature card — good for service/feature highlights',
		'video'             => 'Video embed — YouTube, Vimeo, or self-hosted',
		'divider'           => 'Horizontal line separator between content sections',
		'tabs-nested'       => 'Tabbed content panels — each tab has its own content area',
		'accordion-nested'  => 'Collapsible FAQ/accordion — click to expand/collapse sections',
		'slider-nested'     => 'Image or content carousel/slideshow',
		'form'              => 'Contact or input form with fields and submit button',
		'counter'           => 'Animated number counter — good for statistics (e.g., "5000+ clients")',
		'list'              => 'Bulleted or numbered list',
		'pricing-tables'    => 'Pricing tier card with features list and CTA',
	];

	private GlobalClassService $class_service;
	private SchemaGenerator $schema_generator;
	private SchemaSkeletonGenerator $skeleton_generator;

	public function __construct( GlobalClassService $class_service, SchemaGenerator $schema_generator ) {
		$this->class_service      = $class_service;
		$this->schema_generator   = $schema_generator;
		$this->skeleton_generator = new SchemaSkeletonGenerator();
	}

	/**
	 * Create a design proposal — two-phase flow.
	 *
	 * Without design_plan: returns discovery data (Phase 1).
	 * With design_plan: validates plan, generates skeleton, returns proposal (Phase 2).
	 *
	 * @param int         $page_id      Target page ID.
	 * @param string      $description  Free-text design description.
	 * @param array|null  $design_plan  Structured design decisions (Phase 2 only).
	 * @return array<string, mixed> Discovery data or proposal with suggested_schema.
	 */
	public function create( int $page_id, string $description, ?array $design_plan = null ): array|\WP_Error {
		$post = get_post( $page_id );
		if ( ! $post ) {
			return new \WP_Error( 'invalid_page', sprintf( 'Page %d not found.', $page_id ) );
		}

		// ── Phase 1: Discovery (no design_plan) ──────────────────
		if ( null === $design_plan ) {
			return $this->create_discovery( $page_id, $description );
		}

		// ── Phase 2: Proposal (with design_plan) ─────────────────
		return $this->create_proposal( $page_id, $description, $design_plan );
	}

	// ================================================================
	// Phase 1: Discovery
	// ================================================================

	/**
	 * Return site context and element catalog for design thinking.
	 * No proposal_id — the AI must think first.
	 */
	private function create_discovery( int $page_id, string $description ): array {
		// Get classes.
		$all_classes       = $this->class_service->get_global_classes();
		$class_summary     = [];
		$suggested_classes = [];

		foreach ( $all_classes as $class ) {
			$name     = $class['name'] ?? '';
			$settings = $class['settings'] ?? [];
			$class_summary[] = [
				'name'       => $name,
				'id'         => $class['id'] ?? '',
				'has_styles' => ! empty( $settings ),
			];

			$desc_lower = strtolower( $description );
			$name_parts = preg_split( '/[-_]/', strtolower( $name ) );
			foreach ( $name_parts as $part ) {
				if ( strlen( $part ) > 2 && str_contains( $desc_lower, $part ) ) {
					$suggested_classes[ $name ] = $class['id'] ?? '';
					break;
				}
			}
		}

		// Get scoped variables.
		$scoped_variables = $this->get_scoped_variables( $description );

		// Load briefs.
		$briefs         = get_option( 'bricks_mcp_briefs', [] );
		$design_brief   = is_array( $briefs ) ? trim( $briefs['design_brief'] ?? '' ) : '';
		$business_brief = is_array( $briefs ) ? trim( $briefs['business_brief'] ?? '' ) : '';

		return [
			'phase'       => 'discovery',
			'page_id'     => $page_id,
			'description' => $description,

			'next_step' => 'You now have the site context and available building blocks. '
				. 'Think as a DESIGNER: decide on section_type, layout, background, which elements to use, and what content goes where. '
				. 'Then call propose_design again with the same description PLUS a design_plan object. '
				. 'The design_plan must include: section_type, layout, elements (each with type, role, content_hint), and optional patterns.',

			'available_elements' => self::ELEMENT_PURPOSES,
			'available_layouts'  => self::VALID_LAYOUTS,
			'section_types'      => self::VALID_SECTION_TYPES,

			'site_context' => [
				'classes' => [
					'available' => $class_summary,
					'suggested' => $suggested_classes,
				],
				'variables' => $scoped_variables,
				'briefs'    => array_filter( [
					'design'   => $design_brief ?: null,
					'business' => $business_brief ?: null,
				] ),
			],

			'design_plan_format' => [
				'section_type' => 'hero|features|pricing|cta|testimonials|split|generic (REQUIRED)',
				'layout'       => 'centered|split-60-40|split-50-50|grid-2|grid-3|grid-4 (REQUIRED)',
				'background'   => 'dark|light (optional, default: light)',
				'elements'     => [
					[
						'type'         => 'REQUIRED — element type from available_elements',
						'role'         => 'REQUIRED — what this element does (e.g., "main_heading", "primary_cta", "tagline")',
						'content_hint' => 'REQUIRED — describe what content goes here',
						'tag'          => 'optional — h1-h6 for headings',
						'class_intent' => 'optional — name of an existing class to reuse',
					],
				],
				'patterns' => [
					[
						'name'              => 'REQUIRED — pattern name (e.g., "feature-card")',
						'repeat'            => 'REQUIRED — how many times to repeat',
						'element_structure' => 'REQUIRED — array of {type, role} objects defining the pattern',
						'content_hint'      => 'REQUIRED — describe what each instance contains',
					],
				],
			],
		];
	}

	// ================================================================
	// Phase 2: Proposal
	// ================================================================

	/**
	 * Validate design_plan, generate skeleton, return proposal.
	 */
	private function create_proposal( int $page_id, string $description, array $design_plan ): array|\WP_Error {
		// Validate the design plan.
		$validation = $this->validate_design_plan( $design_plan );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Get classes.
		$all_classes       = $this->class_service->get_global_classes();
		$suggested_classes = [];

		foreach ( $all_classes as $class ) {
			$name     = $class['name'] ?? '';
			$desc_lower = strtolower( $description );
			$name_parts = preg_split( '/[-_]/', strtolower( $name ) );
			foreach ( $name_parts as $part ) {
				if ( strlen( $part ) > 2 && str_contains( $desc_lower, $part ) ) {
					$suggested_classes[ $name ] = $class['id'] ?? '';
					break;
				}
			}
		}

		// Also map class_intents from the design_plan elements.
		foreach ( $design_plan['elements'] ?? [] as $el ) {
			$intent = $el['class_intent'] ?? '';
			if ( '' !== $intent ) {
				foreach ( $all_classes as $class ) {
					if ( ( $class['name'] ?? '' ) === $intent ) {
						$suggested_classes[ $intent ] = $class['id'] ?? '';
						break;
					}
				}
			}
		}

		// Get scoped variables.
		$scoped_variables = $this->get_scoped_variables( $description );

		// Generate skeleton from the AI's design decisions.
		$suggested_schema = $this->skeleton_generator->generate_from_plan(
			$page_id,
			$design_plan,
			$suggested_classes,
			$scoped_variables
		);

		// Generate proposal ID.
		$proposal_id = 'prop_' . substr( md5( (string) time() . wp_generate_password( 8 ) ), 0, 12 );

		$proposal = [
			'phase'            => 'proposal',
			'proposal_id'      => $proposal_id,
			'page_id'          => $page_id,
			'description'      => $description,
			'design_plan'      => $design_plan,
			'created_at'       => current_time( 'mysql' ),
			'suggested_schema' => $suggested_schema,
			'next_step'        => 'Review the suggested_schema. Replace [PLACEHOLDER] content with real text based on the briefs and your content_hints. Then call build_from_schema with this proposal_id and the modified schema.',
			'resolved'         => [
				'classes_suggested' => $suggested_classes,
				'variables'         => $scoped_variables,
			],
		];

		// Store as transient.
		set_transient( "bricks_mcp_proposal_{$proposal_id}", $proposal, self::TTL );

		return $proposal;
	}

	// ================================================================
	// Design Plan Validation
	// ================================================================

	/**
	 * Validate a design_plan object.
	 *
	 * @param array<string, mixed> $plan The design plan.
	 * @return true|\WP_Error True if valid, WP_Error with details.
	 */
	private function validate_design_plan( array $plan ): true|\WP_Error {
		$errors = [];

		// section_type.
		$section_type = $plan['section_type'] ?? '';
		if ( '' === $section_type ) {
			$errors[] = 'design_plan.section_type is required. Valid values: ' . implode( ', ', self::VALID_SECTION_TYPES );
		} elseif ( ! in_array( $section_type, self::VALID_SECTION_TYPES, true ) ) {
			$errors[] = sprintf( 'design_plan.section_type "%s" is not valid. Valid values: %s', $section_type, implode( ', ', self::VALID_SECTION_TYPES ) );
		}

		// layout.
		$layout = $plan['layout'] ?? '';
		if ( '' === $layout ) {
			$errors[] = 'design_plan.layout is required. Valid values: ' . implode( ', ', self::VALID_LAYOUTS );
		} elseif ( ! in_array( $layout, self::VALID_LAYOUTS, true ) ) {
			$errors[] = sprintf( 'design_plan.layout "%s" is not valid. Valid values: %s', $layout, implode( ', ', self::VALID_LAYOUTS ) );
		}

		// background.
		$bg = $plan['background'] ?? 'light';
		if ( ! in_array( $bg, [ 'dark', 'light' ], true ) ) {
			$errors[] = 'design_plan.background must be "dark" or "light".';
		}

		// elements.
		$elements = $plan['elements'] ?? [];
		if ( empty( $elements ) || ! is_array( $elements ) ) {
			$errors[] = 'design_plan.elements is required and must be a non-empty array. Each element needs: type, role, content_hint.';
		} else {
			foreach ( $elements as $idx => $el ) {
				$path = "design_plan.elements[{$idx}]";
				if ( empty( $el['type'] ) ) {
					$errors[] = "{$path}.type is required — pick from available_elements.";
				} elseif ( ! isset( self::ELEMENT_PURPOSES[ $el['type'] ] ) && ! in_array( $el['type'], [ 'section', 'container', 'block', 'div' ], true ) ) {
					// Check against Bricks catalog as fallback.
					$schema = $this->schema_generator->get_element_schema( $el['type'] );
					if ( is_wp_error( $schema ) ) {
						$errors[] = "{$path}.type \"{$el['type']}\" is not a known element.";
					}
				}
				if ( empty( $el['role'] ) ) {
					$errors[] = "{$path}.role is required — describe what this element does (e.g., 'main_heading', 'primary_cta').";
				}
				if ( empty( $el['content_hint'] ) ) {
					$errors[] = "{$path}.content_hint is required — describe what content goes here.";
				}
			}
		}

		// patterns (optional).
		$patterns = $plan['patterns'] ?? [];
		if ( ! empty( $patterns ) && is_array( $patterns ) ) {
			foreach ( $patterns as $idx => $pat ) {
				$path = "design_plan.patterns[{$idx}]";
				if ( empty( $pat['name'] ) ) {
					$errors[] = "{$path}.name is required.";
				}
				if ( empty( $pat['repeat'] ) || ! is_int( $pat['repeat'] ) || $pat['repeat'] < 1 ) {
					$errors[] = "{$path}.repeat must be a positive integer.";
				}
				if ( empty( $pat['element_structure'] ) || ! is_array( $pat['element_structure'] ) ) {
					$errors[] = "{$path}.element_structure is required — array of {type, role} objects.";
				} else {
					foreach ( $pat['element_structure'] as $si => $sel ) {
						if ( empty( $sel['type'] ) ) {
							$errors[] = "{$path}.element_structure[{$si}].type is required.";
						}
						if ( empty( $sel['role'] ) ) {
							$errors[] = "{$path}.element_structure[{$si}].role is required.";
						}
					}
				}
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'invalid_design_plan',
				sprintf( 'Design plan has %d error(s): %s', count( $errors ), implode( '; ', $errors ) ),
				[ 'errors' => $errors ]
			);
		}

		return true;
	}

	// ================================================================
	// Shared helpers
	// ================================================================

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
	 * Get scoped variables relevant to a description.
	 */
	private function get_scoped_variables( string $description ): array {
		$categories = get_option( 'bricks_global_variables_categories', [] );
		$variables  = get_option( 'bricks_global_variables', [] );

		if ( ! is_array( $categories ) ) $categories = [];
		if ( ! is_array( $variables ) ) $variables = [];

		$cat_names = [];
		foreach ( $categories as $cat ) {
			$cat_names[ $cat['id'] ?? '' ] = $cat['name'] ?? '';
		}

		$relevant = $this->detect_relevant_categories( $description );

		$scoped = [];
		foreach ( $variables as $var ) {
			$cat_id   = $var['category'] ?? '';
			$cat_name = $cat_names[ $cat_id ] ?? 'uncategorized';

			if ( in_array( $cat_name, $relevant, true ) ) {
				$scoped[ $cat_name ][] = $var['name'] ?? '';
			}
		}

		return $scoped;
	}

	/**
	 * Detect which variable categories are relevant to the description.
	 */
	private function detect_relevant_categories( string $description ): array {
		$desc = strtolower( $description );
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
		if ( preg_match( '/grid|column|layout/', $desc ) ) {
			$categories[] = 'Grid';
		}

		return array_values( array_unique( $categories ) );
	}
}
