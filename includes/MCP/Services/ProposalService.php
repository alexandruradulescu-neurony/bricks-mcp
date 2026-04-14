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
	 * Request-scoped cache (first-level).
	 * @var string|null
	 */
	private static ?string $last_discovery_hash = null;

	/**
	 * Cross-request discovery cache (second-level).
	 *
	 * Stored in a user-scoped transient with the same 30-min TTL as
	 * PrerequisiteGateService. Survives across MCP HTTP requests so the
	 * slim-response optimization also applies to the *first* discovery call
	 * in a subsequent request (not just second+ calls in the same process).
	 */
	private const DISCOVERY_CACHE_TTL = 1800;

	/**
	 * Get the transient key for the current user.
	 */
	private static function discovery_cache_key(): string {
		return 'bricks_mcp_discovery_hash_' . get_current_user_id();
	}

	/**
	 * Read the persisted discovery hash from the transient.
	 *
	 * Falls back to the static request-scoped hash when the transient is empty.
	 */
	private static function get_persisted_discovery_hash(): ?string {
		if ( null !== self::$last_discovery_hash ) {
			return self::$last_discovery_hash;
		}
		$value = get_transient( self::discovery_cache_key() );
		return is_string( $value ) && $value !== '' ? $value : null;
	}

	/**
	 * Persist the discovery hash across requests.
	 */
	private static function persist_discovery_hash( string $hash ): void {
		self::$last_discovery_hash = $hash;
		set_transient( self::discovery_cache_key(), $hash, self::DISCOVERY_CACHE_TTL );
	}

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
	 * Valid background values for design_plan.
	 *
	 * Core: dark (ultra-dark base) / light (white).
	 * Tinted: resolve to site's *-ultra-light CSS variables, used for the
	 * "alternating sections" pattern recommended by most design briefs.
	 * The values are mapped to actual CSS in BACKGROUND_COLOR_MAP.
	 */
	public const VALID_BACKGROUNDS = [
		'dark', 'light',
		'tinted-neutral',  // var(--base-ultra-light) — off-white alternation
		'tinted-accent',   // var(--accent-ultra-light) — trust/success tone
		'tinted-warning',  // var(--secondary-ultra-light) — pricing/offer tone
		'tinted-danger',   // var(--primary-ultra-light) — urgency/accident tone
	];

	/**
	 * Map background keyword to its resolved CSS color value.
	 *
	 * Consumed by SchemaSkeletonGenerator when building the section's
	 * _background.color style_override. Keeping the mapping here means the
	 * source of truth for "what does tinted-neutral look like" lives with
	 * the vocabulary that defines it.
	 */
	/**
	 * Get dynamic background color map using BriefResolver.
	 *
	 * @return array<string, string> Map of background type to CSS color value.
	 */
	public static function get_background_color_map(): array {
		$brief = BriefResolver::get_instance();
		return [
			'tinted-neutral' => $brief->get( 'light_alt_bg_color' ),
			'tinted-accent'  => SiteVariableResolver::find( 'Colors', 'accent-ultra-light', '', 'var(--accent-ultra-light)' ),
			'tinted-warning' => SiteVariableResolver::find( 'Colors', 'secondary-ultra-light', '', 'var(--secondary-ultra-light)' ),
			'tinted-danger'  => SiteVariableResolver::find( 'Colors', 'primary-ultra-light', '', 'var(--primary-ultra-light)' ),
		];
	}

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
		'progress-bar' => [
			'purpose'      => 'Animated horizontal progress bar — for skill bars, completion meters',
			'capabilities' => [ 'multiple bars in one element via "bars" array', 'animation on scroll', 'percentage label visibility' ],
			'rules'        => [ 'use element_settings.bars for an array of {label, percentage} objects', 'pair with a heading for context' ],
		],
		'pie-chart' => [
			'purpose'      => 'Circular percentage chart / radial progress',
			'capabilities' => [ 'percent (0-100)', 'bar color, track color', 'inner content (percent text)' ],
			'rules'        => [ 'use element_settings.percent (integer 0-100), barColor and trackColor are {raw} color objects' ],
		],
		'alert' => [
			'purpose'      => 'Notification banner — info, success, warning, error',
			'capabilities' => [ 'icon + text content', 'dismissible flag', 'color variants' ],
			'rules'        => [ 'content goes in the alert text setting; choose alert type for color' ],
		],
		'animated-typing' => [
			'purpose'      => 'Typewriter / typing animation effect — cycles through a list of strings',
			'capabilities' => [ 'multiple strings to cycle through', 'type/back speed', 'loop' ],
			'rules'        => [ 'use element_settings.strings (array of strings to cycle), typeSpeed (ms per char)' ],
		],
		'rating' => [
			'purpose'      => 'Star rating display (or other icon) — for review summaries, product ratings',
			'capabilities' => [ 'rating value (e.g. 4.5)', 'max rating (default 5)', 'icon style' ],
			'rules'        => [ 'use element_settings.rating (number 0-maxRating)' ],
		],
		'social-icons' => [
			'purpose'      => 'Row of social media icons with links',
			'capabilities' => [ 'icons array (facebook, twitter, instagram, etc.)', 'shape (circle, square, none)', 'size variants' ],
			'rules'        => [ 'each icon needs a URL; pair with a heading for context' ],
		],
	];

	/**
	 * Building rules — included in every discovery response.
	 * The no_override rule is adjusted at runtime based on site state.
	 */
	private const BUILDING_RULES_BASE = [
		'structure'   => 'Every page follows: section > container > block/div > content elements. Multiple visual rows = multiple containers inside a section.',
		'centering'   => 'Use flex alignment (_alignItems: center, _justifyContent: center) — NOT text-align. text-align only affects text inside an element.',
		'classes'     => 'Use class_intent on every element when possible. The pipeline creates reusable classes WITH styles. Inline style_overrides only for instance-specific overrides.',
		'labels'      => 'Add label to sections ("Hero"), containers (row description), and blocks ("CTA Buttons", "Cards Grid").',
		'variables'   => 'Always use var(--name) — never hardcode colors, spacing, radius, or font sizes. Examples: var(--space-m), var(--primary), var(--radius), var(--h2).',
		'rows'        => 'For horizontal rows, use block with _direction: row. NOT div — div ignores _direction.',
		'responsive'  => 'Composite keys for responsive: _property:tablet_portrait, _property:mobile. Grids should collapse: 3-col → 2-col at tablet → 1-col at mobile.',
		'backgrounds' => 'Background overlays use _gradient with applyTo: "overlay". Background images need actual URLs (sideload from Unsplash first). Section background: "dark" auto-sets dark bg + white text on children.',
		'buttons'     => 'Buttons support native icons (icon + iconPosition settings). Do NOT use emoji in button text. Use class_intent for styling (btn-hero-primary, btn-hero-ghost).',
		'gaps'        => 'On flex blocks: use _columnGap for horizontal spacing (row direction), _rowGap for vertical spacing (column direction). Plain _gap does NOT generate CSS on flex layout blocks. The pipeline auto-converts _gap to the correct key based on _direction.',
	];

	/**
	 * Get building rules, adjusted based on whether the site has a design system.
	 *
	 * @param bool $has_design_system True if site has meaningful classes/theme styles.
	 */
	private static function get_building_rules( bool $has_design_system ): array {
		$rules = self::BUILDING_RULES_BASE;

		if ( $has_design_system ) {
			$rules['no_override'] = 'DO NOT set these inline — the design system handles them globally: section _padding, container _gap, heading font-size/color/line-height/font-weight, body text font-size/color/line-height.';
		} else {
			$rules['no_override'] = 'No design system detected. Set explicit values for section _padding, container _gap, heading typography, and body text styles. Use the starter classes or var(--name, fallback) pattern.';
		}

		return $rules;
	}

	/**
	 * Get element capabilities, adjusted based on site state.
	 *
	 * @param bool $has_design_system True if site has meaningful classes/theme styles.
	 */
	private static function get_element_capabilities( bool $has_design_system ): array {
		$caps = self::ELEMENT_CAPABILITIES;

		if ( ! $has_design_system ) {
			$caps['section']['rules']    = [ 'Set explicit _padding or use starter classes' ];
			$caps['container']['rules']  = [ 'Set explicit _gap or use starter classes' ];
			$caps['heading']['rules']    = [ 'Set explicit font-size, color, and font-weight or use class_intent' ];
			$caps['text-basic']['rules'] = [ 'Set explicit font-size and color or use class_intent' ];
		}

		return $caps;
	}

	private GlobalClassService $class_service;
	private SchemaGenerator $schema_generator;
	private SchemaSkeletonGenerator $skeleton_generator;
	private ?BricksService $bricks_service;

	public function __construct( GlobalClassService $class_service, SchemaGenerator $schema_generator, ?BricksService $bricks_service = null ) {
		$this->class_service      = $class_service;
		$this->schema_generator   = $schema_generator;
		$this->bricks_service     = $bricks_service;
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
		$briefs         = get_option( BricksCore::OPTION_BRIEFS, [] );
		$design_brief   = is_array( $briefs ) ? trim( $briefs['design_brief'] ?? '' ) : '';
		$business_brief = is_array( $briefs ) ? trim( $briefs['business_brief'] ?? '' ) : '';

		// Analyze existing page sections (site-aware design).
		$existing_sections = $this->analyze_existing_sections( $page_id );
		$style_hints       = $this->aggregate_site_style_hints( $existing_sections );

		// Bootstrap check — few or no global classes.
		$bootstrap_recommendation = null;
		if ( count( $all_classes ) < 5 ) {
			$bootstrap_recommendation = [
				'warning' => 'Site has few/no global classes. Building will create new classes as needed, but starting with a curated set produces more consistent results.',
				'action'  => 'Optional: call global_class:batch_create with suggested_starter_classes below before building.',
				'suggested_starter_classes' => StarterClassesService::get_starter_classes(),
			];
		}

		$next_step = 'You now have the site context, available building blocks, and REFERENCE PATTERNS showing proven compositions. '
			. 'Think as a DESIGNER: pick the closest reference_pattern that matches the user\'s request, '
			. 'then adapt it into a design_plan. The pattern shows the correct composition — you adjust the content. '
			. 'Call propose_design again with the same description PLUS a design_plan object.';

		if ( ! empty( $existing_sections ) ) {
			$next_step .= ' IMPORTANT: This page already has ' . count( $existing_sections ) . ' section(s). Study existing_page_sections and site_style_hints — reuse the same classes, layout patterns, and background treatments for visual consistency. Only deviate if the user explicitly asks for a different style.';
		}

		$response = [
			'phase'       => 'discovery',
			'page_id'     => $page_id,
			'description' => $description,

			'next_step' => $next_step,

			'reference_patterns' => $this->find_reference_patterns( $description ),
		];

		if ( ! empty( $existing_sections ) ) {
			$response['existing_page_sections'] = $existing_sections;
			$response['site_style_hints']       = $style_hints;
		}

		if ( null !== $bootstrap_recommendation ) {
			$response['bootstrap_recommendation'] = $bootstrap_recommendation;
		}

		// Build site_context.
		$site_context = [
			'classes' => [
				'available' => $class_summary,
				'suggested' => $suggested_classes,
			],
			'variables' => $scoped_variables,
			'briefs'    => array_filter( [
				'design'   => $design_brief ?: null,
				'business' => $business_brief ?: null,
			] ),
		];

		$context_hash    = md5( wp_json_encode( $site_context ) );
		$previous_hash   = self::get_persisted_discovery_hash();
		$context_changed = ( null === $previous_hash ) || ( $context_hash !== $previous_hash );

		// If site_context is unchanged from a previous discovery (this request OR prior one), return slim response.
		if ( ! $context_changed ) {
			$response['site_context_hash']    = $context_hash;
			$response['site_context_changed'] = false;
			$response['site_context_note']    = 'Unchanged from previous discovery — use cached context. Only reference_patterns are new (matched to your description).';
			$response['next_step'] .= ' For subsequent sections, you can skip Phase 1 and call propose_design directly with a design_plan.';
			$response['available_layouts']  = self::VALID_LAYOUTS;
			$response['section_types']      = self::VALID_SECTION_TYPES;
			$response['design_plan_format'] = $this->get_design_plan_format();
			return $response;
		}

		// First discovery or site data changed — return full response and persist the hash.
		self::persist_discovery_hash( $context_hash );

		$has_design_system = count( $all_classes ) >= 5 || SiteVariableResolver::has_variables();

		$response['available_elements']   = self::get_element_capabilities( $has_design_system );
		$response['available_layouts']    = self::VALID_LAYOUTS;
		$response['section_types']        = self::VALID_SECTION_TYPES;
		$response['building_rules']       = self::get_building_rules( $has_design_system );
		$response['site_context']         = $site_context;
		$response['site_context_hash']    = $context_hash;
		$response['site_context_changed'] = true;
		$response['design_plan_format']   = $this->get_design_plan_format();
		$response['next_step'] .= ' For subsequent sections, you can skip Phase 1 and call propose_design directly with a design_plan.';

		return $response;
	}

	/**
	 * Get the design_plan_format structure (reused in full and slim responses).
	 */
	private function get_design_plan_format(): array {
		return [
			'section_type'     => 'hero|features|pricing|cta|testimonials|split|generic (REQUIRED)',
			'layout'           => 'centered|split-60-40|split-50-50|grid-2|grid-3|grid-4 (REQUIRED)',
			'background'       => 'dark|light|tinted-neutral|tinted-accent|tinted-warning|tinted-danger (optional, default: light). Use tinted-* values for alternating sections on light-themed sites.',
			'background_image' => 'optional — "unsplash:query" to auto-fetch a background image.',
			'elements'     => [
				[
					'type'         => 'REQUIRED — element type from available_elements',
					'role'         => 'REQUIRED — what this element does',
					'content_hint' => 'REQUIRED — describe what content goes here',
					'tag'          => 'optional — h1-h6 for headings',
					'class_intent' => 'optional — name of an existing class to reuse',
				],
			],
			'patterns' => [
				[
					'name'              => 'REQUIRED — pattern name',
					'repeat'            => 'REQUIRED — how many times to repeat',
					'element_structure' => 'REQUIRED — array of {type, role} objects',
					'content_hint'      => 'REQUIRED — describe what each instance contains',
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

		// Section-type-aware suggestions: surface classes that are conventionally
		// useful for a given section type even when the description doesn't name
		// them. Without this, a pricing section gets zero button/eyebrow class
		// suggestions unless the AI description happens to contain those words.
		$section_type = $design_plan['section_type'] ?? '';
		foreach ( $this->get_section_type_class_hints( $section_type ) as $pattern ) {
			foreach ( $all_classes as $class ) {
				$name = $class['name'] ?? '';
				if ( '' === $name || isset( $suggested_classes[ $name ] ) ) {
					continue;
				}
				if ( str_starts_with( $name, $pattern ) || $name === $pattern ) {
					$suggested_classes[ $name ] = $class['id'] ?? '';
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

		// background — supports dark/light plus 4 tinted variants for alternating section patterns.
		$bg = $plan['background'] ?? 'light';
		if ( ! in_array( $bg, self::VALID_BACKGROUNDS, true ) ) {
			$errors[] = sprintf( 'design_plan.background must be one of: %s', implode( ', ', self::VALID_BACKGROUNDS ) );
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
	 * Get class-name prefix patterns that are conventionally useful for each section type.
	 *
	 * Returned patterns are matched against global class names via str_starts_with
	 * OR exact-match. This lets a pricing section auto-suggest any `btn-*`,
	 * `eyebrow`, `hero-subtitle`, `card*` etc. even when the description doesn't
	 * name them explicitly.
	 *
	 * @param string $section_type  Section type (hero, features, pricing, etc.).
	 * @return array<int, string>   Class-name patterns (prefixes or exact names).
	 */
	private function get_section_type_class_hints( string $section_type ): array {
		// Common hints across most section types.
		$common = [ 'eyebrow', 'tagline', 'hero-subtitle', 'btn-' ];

		$by_type = [
			'hero'         => [ 'hero-', 'btn-hero-', 'hero-description', 'hero-price-pill', 'hero-trust-text' ],
			'features'     => [ 'feature-', 'card', 'stat-card-', 'icon-' ],
			'pricing'      => [ 'pricing-', 'btn-primary', 'btn-outline', 'card' ],
			'testimonials' => [ 'testimonial-', 'rating-stars', 'card' ],
			'cta'          => [ 'btn-primary', 'btn-hero-', 'btn-outline' ],
			'split'        => [ 'card', 'btn-' ],
			'generic'      => [],
		];

		return array_merge( $common, $by_type[ $section_type ] ?? [] );
	}

	/**
	 * Get scoped variables relevant to a description.
	 */
	private function get_scoped_variables( string $description ): array {
		// Use SiteVariableResolver's static cache instead of calling get_option() directly.
		$by_category = SiteVariableResolver::get_variables_by_category();
		$relevant    = $this->detect_relevant_categories( $description );

		$scoped = [];
		foreach ( $by_category as $cat_name => $vars ) {
			if ( in_array( $cat_name, $relevant, true ) ) {
				foreach ( $vars as $var ) {
					$scoped[ $cat_name ][] = $var['name'] ?? '';
				}
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

	/**
	 * Find reference patterns matching a description.
	 *
	 * Extracts section type and mood tags from the description text,
	 * then queries the DesignPatternService for matching patterns.
	 *
	 * @param string $description Free-text description.
	 * @return array<int, array> Matching patterns (up to 3).
	 */
	private function find_reference_patterns( string $description ): array {
		$desc = strtolower( $description );

		// Detect section type.
		$section_type = 'generic';
		$type_map = [
			'hero'         => '/\bhero\b/',
			'features'     => '/feature|service|benefit/',
			'pricing'      => '/pricing|price|plan|tier/',
			'cta'          => '/\bcta\b|call.to.action/',
			'testimonials' => '/testimonial|review|quote/',
			'split'        => '/split|login|signup|register|form.*image|image.*form/',
		];
		foreach ( $type_map as $type => $regex ) {
			if ( preg_match( $regex, $desc ) ) {
				$section_type = $type;
				break;
			}
		}

		// Detect mood/style tags.
		$tags = [];
		if ( preg_match( '/dark|gradient|overlay/', $desc ) ) {
			$tags[] = 'dark';
		}
		if ( preg_match( '/light|white|clean|minimal/', $desc ) ) {
			$tags[] = 'light';
		}
		if ( preg_match( '/center|centred/', $desc ) ) {
			$tags[] = 'centered';
		}
		if ( preg_match( '/split|column|left.*right/', $desc ) ) {
			$tags[] = 'split';
		}
		if ( preg_match( '/image|photo|picture/', $desc ) ) {
			$tags[] = 'image';
		}
		if ( preg_match( '/card|grid/', $desc ) ) {
			$tags[] = 'cards';
		}
		if ( preg_match( '/form|login|signup|contact/', $desc ) ) {
			$tags[] = 'form';
		}
		if ( preg_match( '/icon/', $desc ) ) {
			$tags[] = 'icons';
		}

		return DesignPatternService::find( $section_type, $tags, 3 );
	}

	/**
	 * Analyze existing sections on the target page.
	 *
	 * Returns up to 5 existing sections with their label, description,
	 * background, layout, and classes used. Gives the AI context about
	 * the site's visual language so new sections match.
	 *
	 * @param int $page_id Target page ID.
	 * @return array<int, array<string, mixed>> Section summaries.
	 */
	private function analyze_existing_sections( int $page_id ): array {
		if ( null === $this->bricks_service ) {
			return [];
		}

		$described = $this->bricks_service->describe_page( $page_id );
		if ( is_wp_error( $described ) || empty( $described['sections'] ) ) {
			return [];
		}

		$summaries = [];
		foreach ( array_slice( $described['sections'], 0, 5 ) as $section ) {
			$summaries[] = [
				'label'         => $section['label'] ?? 'Section',
				'description'   => $section['description'] ?? '',
				'background'    => $section['background'] ?? 'light',
				'layout'        => $section['layout'] ?? 'stacked',
				'classes_used'  => $section['classes'] ?? [],
				'element_count' => $section['element_count'] ?? 0,
			];
		}

		return $summaries;
	}

	/**
	 * Aggregate style hints across all existing sections.
	 *
	 * Identifies patterns in the site's design: common layouts, common
	 * class combinations, background treatments. The AI uses these as
	 * style signals to keep new sections consistent with existing ones.
	 *
	 * @param array<int, array<string, mixed>> $sections Output of analyze_existing_sections.
	 * @return array<string, mixed> Aggregated style hints.
	 */
	private function aggregate_site_style_hints( array $sections ): array {
		if ( empty( $sections ) ) {
			return [];
		}

		$layouts     = [];
		$backgrounds = [];
		$all_classes = [];

		foreach ( $sections as $s ) {
			$layouts[ $s['layout'] ]         = ( $layouts[ $s['layout'] ] ?? 0 ) + 1;
			$backgrounds[ $s['background'] ] = ( $backgrounds[ $s['background'] ] ?? 0 ) + 1;
			foreach ( $s['classes_used'] ?? [] as $cls ) {
				$all_classes[ $cls ] = ( $all_classes[ $cls ] ?? 0 ) + 1;
			}
		}

		arsort( $layouts );
		arsort( $backgrounds );
		arsort( $all_classes );

		return [
			'most_common_layouts'     => array_keys( array_slice( $layouts, 0, 3, true ) ),
			'most_common_backgrounds' => array_keys( array_slice( $backgrounds, 0, 3, true ) ),
			'frequently_used_classes' => array_keys( array_slice( $all_classes, 0, 10, true ) ),
			'guidance'                => 'For visual consistency, match the most common background and layout patterns when building similar section types. Reuse frequently_used_classes when their purpose aligns with your new section.',
		];
	}
}
