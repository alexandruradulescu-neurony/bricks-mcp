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
	 * Element capabilities for discovery phase.
	 * Each entry describes PURPOSE + CAPABILITIES so the AI makes informed design decisions.
	 */
	private const ELEMENT_CAPABILITIES = [
		'section' => [
			'purpose'      => 'Full-width page section — the outermost wrapper',
			'capabilities' => [ 'background image with overlay', 'gradient background', 'min-height for hero sections', 'dark/light mode' ],
			'rules'        => [ 'DO NOT set _padding — child theme handles it', 'only contains containers as direct children' ],
		],
		'container' => [
			'purpose'      => 'Content container inside a section — controls max-width and centering',
			'capabilities' => [ 'flex alignment (_alignItems, _justifyContent)', 'text-align for centered text layouts' ],
			'rules'        => [ 'DO NOT set _gap — child theme handles it', 'use multiple containers for multiple visual rows' ],
		],
		'block' => [
			'purpose'      => 'Structural grouping — columns, rows, card wrappers',
			'capabilities' => [ 'flex direction (_direction: row for horizontal)', 'CSS grid (layout: grid + columns)', 'semantic HTML tags (ul, li, article, nav, figure)', 'responsive grid collapse' ],
			'rules'        => [ 'use block for horizontal rows — NOT div (div ignores _direction)' ],
		],
		'div' => [
			'purpose'      => 'Small wrapper — icon circles, overlays, decorative containers',
			'capabilities' => [ 'explicit _display: flex for centering content' ],
			'rules'        => [ 'does NOT support _direction — use block instead for rows' ],
		],
		'heading' => [
			'purpose'      => 'Title text (h1–h6)',
			'capabilities' => [ 'HTML in content — use <span style="color:var(--secondary)">text</span> for colored words', 'tag: h1 for page titles, h2 for sections, h3-h4 for cards' ],
			'rules'        => [ 'DO NOT set font-size, color, line-height, font-weight — child theme handles all heading styles' ],
		],
		'text-basic' => [
			'purpose'      => 'Paragraph or body text — descriptions, subtitles, taglines, eyebrows',
			'capabilities' => [ 'HTML content supported', 'can be styled as tagline/eyebrow via class_intent' ],
			'rules'        => [ 'DO NOT set font-size, color, line-height — child theme handles body text styles' ],
		],
		'text-link' => [
			'purpose'      => 'Clickable text link',
			'capabilities' => [ 'link URL (internal or external)', 'custom link text' ],
		],
		'button' => [
			'purpose'      => 'Call-to-action button',
			'capabilities' => [ 'ICON support — native icon left/right of text (ti-mobile, ti-comment-alt, etc.)', 'link with tel: or https:// URL', 'style variants via class_intent (primary, outline, ghost)' ],
			'rules'        => [ 'use native icon feature — do NOT put emoji in button text' ],
		],
		'image' => [
			'purpose'      => 'Image element — photos, illustrations, logos',
			'capabilities' => [ 'Unsplash auto-fetch via src: "unsplash:query"', 'attachment ID reference', 'external URL', 'border-radius for rounded corners', 'aspect-ratio control' ],
		],
		'icon' => [
			'purpose'      => 'Single decorative or functional icon',
			'capabilities' => [ 'Themify icon library (ti-truck, ti-shield, ti-timer, etc.)', 'custom size, color' ],
		],
		'icon-box' => [
			'purpose'      => 'Feature card — icon + heading + text combined',
			'capabilities' => [ 'built-in icon, title, and description fields', 'good for feature grids and service highlights' ],
		],
		'video' => [
			'purpose'      => 'Video embed',
			'capabilities' => [ 'YouTube, Vimeo, or self-hosted video URL' ],
		],
		'divider' => [
			'purpose'      => 'Horizontal separator line',
			'capabilities' => [ 'custom width, color, style' ],
		],
		'tabs-nested' => [
			'purpose'      => 'Tabbed content panels — each tab has full nested content',
			'capabilities' => [ 'multiple tab panes with any content inside', 'tab labels, icons' ],
			'rules'        => [ 'use tabs-nested NOT tabs — basic tabs only support plain text' ],
		],
		'accordion-nested' => [
			'purpose'      => 'Collapsible FAQ/accordion sections',
			'capabilities' => [ 'multiple panels with any nested content', 'click to expand/collapse' ],
		],
		'slider-nested' => [
			'purpose'      => 'Image or content carousel/slideshow',
			'capabilities' => [ 'multiple slides with any nested content', 'autoplay, navigation arrows, dots' ],
		],
		'form' => [
			'purpose'      => 'Contact or input form',
			'capabilities' => [ 'text fields, email, textarea, select, checkbox', 'submit button', 'email notifications' ],
		],
		'counter' => [
			'purpose'      => 'Animated number counter',
			'capabilities' => [ 'count-to target number', 'prefix/suffix text', 'animation on scroll' ],
		],
		'list' => [
			'purpose'      => 'Bulleted or numbered list',
			'capabilities' => [ 'custom icons per item', 'ordered or unordered' ],
		],
		'pricing-tables' => [
			'purpose'      => 'Pricing tier card',
			'capabilities' => [ 'plan name, price, features list, CTA button', 'highlight/featured tier' ],
		],
	];

	/**
	 * Building rules extracted from building.md — included in every discovery response.
	 */
	private const BUILDING_RULES = [
		'structure'   => 'Every page follows: section > container > block/div > content elements. Multiple visual rows = multiple containers inside a section.',
		'centering'   => 'Use flex alignment (_alignItems: center, _justifyContent: center) — NOT text-align. text-align only affects text inside an element.',
		'no_override' => 'DO NOT set these inline — the child theme handles them globally: section _padding, container _gap, heading font-size/color/line-height/font-weight, body text font-size/color/line-height.',
		'classes'     => 'Use class_intent on every element when possible. The pipeline creates reusable classes WITH styles. Inline style_overrides only for instance-specific overrides.',
		'labels'      => 'Add label to sections ("Hero"), containers (row description), and blocks ("CTA Buttons", "Cards Grid").',
		'variables'   => 'Always use var(--name) — never hardcode colors, spacing, radius, or font sizes. Examples: var(--space-m), var(--primary), var(--radius), var(--h2).',
		'rows'        => 'For horizontal rows, use block with _direction: row. NOT div — div ignores _direction.',
		'responsive'  => 'Composite keys for responsive: _property:tablet_portrait, _property:mobile. Grids should collapse: 3-col → 2-col at tablet → 1-col at mobile.',
		'backgrounds' => 'Background overlays use _gradient with applyTo: "overlay". Background images need actual URLs (sideload from Unsplash first). Section background: "dark" auto-sets dark bg + white text on children.',
		'buttons'     => 'Buttons support native icons (icon + iconPosition settings). Do NOT use emoji in button text. Use class_intent for styling (btn-hero-primary, btn-hero-ghost).',
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

			'available_elements' => self::ELEMENT_CAPABILITIES,
			'available_layouts'  => self::VALID_LAYOUTS,
			'section_types'      => self::VALID_SECTION_TYPES,
			'building_rules'     => self::BUILDING_RULES,

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

		// Fetch real element schemas for the types the AI chose.
		$chosen_types = [];
		foreach ( $design_plan['elements'] ?? [] as $el ) {
			$t = $el['type'] ?? '';
			if ( '' !== $t ) {
				$chosen_types[ $t ] = true;
			}
		}
		foreach ( $design_plan['patterns'] ?? [] as $pat ) {
			foreach ( $pat['element_structure'] ?? [] as $pel ) {
				$t = $pel['type'] ?? '';
				if ( '' !== $t ) {
					$chosen_types[ $t ] = true;
				}
			}
		}

		$element_details = [];
		foreach ( array_keys( $chosen_types ) as $type ) {
			$schema = $this->schema_generator->get_element_schema( $type );
			if ( ! is_wp_error( $schema ) ) {
				$element_details[ $type ] = [
					'label'           => $schema['label'] ?? $type,
					'nesting'         => $schema['nesting'] ?? [],
					'working_example' => $schema['working_example'] ?? [],
				];
			}
		}

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
			'next_step'        => 'Review the suggested_schema. Replace [PLACEHOLDER] content with real text based on the briefs and your content_hints. The element_schemas below show what each element accepts — use this to set correct content keys. Then call build_from_schema with this proposal_id and the modified schema.',
			'resolved'         => [
				'classes_suggested' => $suggested_classes,
				'variables'         => $scoped_variables,
				'element_schemas'   => $element_details,
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
				} elseif ( ! isset( self::ELEMENT_CAPABILITIES[ $el['type'] ] ) && ! in_array( $el['type'], [ 'section', 'container', 'block', 'div' ], true ) ) {
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
