<?php
/**
 * MCP Router implementation.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP;

use BricksMCP\Admin\Settings;
use BricksMCP\MCP\Services\BEMClassNormalizer;
use BricksMCP\MCP\Services\BricksCore;
use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\ClassDedupEngine;
use BricksMCP\MCP\Services\ClassIntentResolver;
use BricksMCP\MCP\Services\ClaudeVisionProvider;
use BricksMCP\MCP\Services\DesignSchemaValidator;
use BricksMCP\MCP\Services\ElementSettingsGenerator;
use BricksMCP\MCP\Services\ImageInputResolver;
use BricksMCP\MCP\Services\MediaService;
use BricksMCP\MCP\Services\MenuService;
use BricksMCP\MCP\Services\OnboardingService;
use BricksMCP\MCP\Services\PendingActionService;
use BricksMCP\MCP\Services\SchemaExpander;
use BricksMCP\MCP\Services\SchemaGenerator;
use BricksMCP\MCP\Services\ValidationService;
use BricksMCP\MCP\Services\PrerequisiteGateService;
use BricksMCP\MCP\Services\ProposalService;
use BricksMCP\MCP\Services\PageLayoutService;
use BricksMCP\MCP\Services\VisionPatternGenerator;
use BricksMCP\MCP\Services\VisionPromptBuilder;
use BricksMCP\MCP\Services\VisionResponseMapper;
use BricksMCP\MCP\Handlers\OnboardingHandler;
use BricksMCP\MCP\ToolRegistry;
use BricksMCP\Plugin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Router class.
 *
 * Handles routing of MCP tool calls to their respective handlers.
 */
final class Router {

	/**
	 * Error code raised by handlers when a destructive action requires user confirmation.
	 *
	 * Handlers raise WP_Error with this code; Router intercepts and mints a token.
	 * Extracted from a magic string so the interceptor and every handler share one identity.
	 *
	 * @var string
	 */
	public const ERROR_CONFIRM_REQUIRED = 'bricks_mcp_confirm_required';

	/**
	 * Transient prefix used by maybe_set_site_context() to track per-user prerequisite flags.
	 *
	 * Suffixed with get_current_user_id() at call time. Kept as a constant so the
	 * uninstall sweep (LIKE 'bricks_mcp_%') and the composite-flag logic agree.
	 *
	 * @var string
	 */
	private const PREREQS_TRANSIENT_PREFIX = 'bricks_mcp_prereqs_';

	/**
	 * Tool registry instance.
	 *
	 * @var ToolRegistry
	 */
	private ToolRegistry $registry;

	/**
	 * Operations that require prerequisites, with tier level per action.
	 *
	 * Tiers: 'direct' (site_context), 'design' (site_context + design_ready).
	 *
	 * @var array<string, array<string, string>>
	 */
	private const GATED_OPERATIONS = [
		'page'      => [
			'update_content'   => 'direct',
			'append_content'   => 'direct',
			'create'           => 'direct',
			'import_clipboard' => 'direct',
		],
		'element'   => [
			'add'         => 'direct',
			'bulk_add'    => 'direct',
			'update'      => 'direct',
			'bulk_update' => 'direct',
		],
		'template'  => [
			'create' => 'direct',
		],
		'component' => [
			'create'      => 'direct',
			'update'      => 'direct',
			'instantiate' => 'direct',
			'fill_slot'   => 'direct',
		],
		'build_from_schema' => [
			'_always' => 'design',
		],
		'propose_design' => [
			'_always' => 'direct',
		],
	];

	/**
	 * Bricks service instance.
	 *
	 * @var BricksService
	 */
	private BricksService $bricks_service;

	/**
	 * Schema generator instance.
	 *
	 * @var SchemaGenerator
	 */
	private SchemaGenerator $schema_generator;

	/**
	 * Validation service instance.
	 *
	 * @var ValidationService
	 */
	private ValidationService $validation_service;

	/**
	 * Media service instance.
	 *
	 * @var MediaService
	 */
	private MediaService $media_service;

	/**
	 * Menu service instance.
	 *
	 * @var MenuService
	 */
	private MenuService $menu_service;

	/**
	 * Handler instances keyed by short name.
	 *
	 * Bricks-only handlers register their tools deferred (see register_bricks_tools).
	 * Universally-available handlers (onboarding) register their tools immediately.
	 *
	 * @var array<string, object>
	 */
	private array $handlers = [];

	/**
	 * Pending action service for token-based destructive action confirmation.
	 *
	 * @var PendingActionService
	 */
	private PendingActionService $pending_action_service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->registry           = new ToolRegistry();
		$this->schema_generator   = new SchemaGenerator();
		$this->validation_service = new ValidationService( $this->schema_generator );
		$this->bricks_service     = new BricksService();
		$this->bricks_service->set_validation_service( $this->validation_service );
		$this->media_service = new MediaService();
		$this->menu_service       = new MenuService();

		$require_bricks = \Closure::fromCallable( array( $this, 'require_bricks' ) );

		// Build pipeline shared dependencies (created once, injected into multiple handlers).
		$design_validator     = new DesignSchemaValidator( $this->schema_generator );
		$class_resolver       = new ClassIntentResolver( $this->bricks_service->get_global_class_service() );
		$schema_expander      = new SchemaExpander();
		$element_settings_gen = new ElementSettingsGenerator( $this->schema_generator, $this->media_service );
		$proposal_service     = new ProposalService( $this->bricks_service->get_global_class_service(), $this->schema_generator, $this->bricks_service );
		$schema_handler       = new Handlers\SchemaHandler( $this->schema_generator, $this->bricks_service );

		// M3 (v3.31): vision pipeline for design_pattern(action: from_image).
		// ClaudeVisionProvider tolerates an empty API key at construction time — the
		// actual key check happens on first analyze() call, keeping Router wiring
		// side-effect-free for installs that don't use from_image.
		$vision_normalizer    = new BEMClassNormalizer();
		$vision_dedup         = new ClassDedupEngine( $vision_normalizer );
		$vision_provider      = new ClaudeVisionProvider( Settings::get_anthropic_api_key() );
		$vision_prompt        = new VisionPromptBuilder();
		$vision_mapper        = new VisionResponseMapper( $vision_dedup, $vision_normalizer );
		$vision_generator     = new VisionPatternGenerator( $vision_provider, $vision_prompt, $vision_mapper );
		$image_resolver       = new ImageInputResolver();

		// All handlers indexed by short name.
		$this->handlers = [
			'component'     => new Handlers\ComponentHandler( $this->bricks_service, $require_bricks ),
			'woocommerce'   => new Handlers\WooCommerceHandler( $this->bricks_service, $this->schema_generator, $require_bricks ),
			'schema'        => $schema_handler,
			'menu'          => new Handlers\MenuHandler( $this->menu_service, $require_bricks ),
			'page'          => new Handlers\PageHandler( $this->bricks_service, $this->validation_service, $require_bricks ),
			'element'       => new Handlers\ElementHandler( $this->bricks_service, $require_bricks ),
			'template'      => new Handlers\TemplateHandler( $this->bricks_service, $require_bricks ),
			'global_class'  => new Handlers\GlobalClassHandler( $this->bricks_service, $require_bricks ),
			'design_system' => new Handlers\DesignSystemHandler( $this->bricks_service, $require_bricks ),
			'wordpress'     => new Handlers\WordPressHandler(),
			'metabox'       => new Handlers\MetaBoxHandler(),
			'bricks_tool'   => new Handlers\BricksToolHandler( $this->bricks_service, $schema_handler, $require_bricks ),
			'media'         => new Handlers\MediaHandler( $this->media_service, $require_bricks ),
			'font'          => new Handlers\FontHandler( $this->bricks_service, $require_bricks ),
			'code'          => new Handlers\CodeHandler( $this->bricks_service, $require_bricks ),
			'proposal'      => new Handlers\ProposalHandler(
				$proposal_service,
				$require_bricks,
				$this->bricks_service,
				$vision_generator,
				$image_resolver
			),
			'build'         => $build_handler = new Handlers\BuildHandler(
				$this->bricks_service,
				$design_validator,
				$class_resolver,
				$schema_expander,
				$element_settings_gen,
				$proposal_service
			),
			'build_structure'  => new Handlers\BuildStructureHandler(
					$build_handler,
					$this->bricks_service,
					$proposal_service,
					$this->bricks_service->get_global_class_service(),
					$this->bricks_service->get_global_variable_service()
				),
			'populate_content' => new Handlers\PopulateContentHandler( $this->bricks_service, $this->media_service ),
			'onboarding'    => new OnboardingHandler( new OnboardingService( $this->bricks_service ) ),
			'verify'        => new Handlers\VerifyHandler( $this->bricks_service, $require_bricks ),
			'page_layout'      => new Handlers\PageLayoutHandler( new PageLayoutService(), $require_bricks ),
			'design_pattern'   => new Handlers\DesignPatternHandler(
				$this->bricks_service,
				$require_bricks,
				$vision_generator,
				$image_resolver
			),
		];

		$this->pending_action_service = new PendingActionService();

		$this->register_default_tools();

		// Defer Bricks tool registration until themes are loaded.
		// Bricks is a theme, so \Bricks\Elements isn't available on plugins_loaded.
		if ( did_action( 'after_setup_theme' ) ) {
			$this->register_bricks_tools();
		} else {
			add_action( 'after_setup_theme', array( $this, 'register_bricks_tools' ), 20 );
		}

		// Flush schema cache when plugins are updated.
		add_action(
			'upgrader_process_complete',
			function (): void {
				$this->schema_generator->flush_cache();
			},
			10,
			0
		);
	}

	/**
	 * Register default tools.
	 *
	 * @return void
	 */
	private function register_default_tools(): void {
		// Universal handlers (don't require Bricks Builder).
		$this->handlers['onboarding']->register( $this->registry );
		$this->handlers['wordpress']->register( $this->registry );
		$this->handlers['metabox']->register( $this->registry );

		// Two Router-owned tools that don't fit into a handler (introspection/auth concerns).
		$this->register_tool(
			'get_site_info',
			__( "Get WordPress site information including design tokens, child theme CSS, and color palette.\n\nActions: info (default), diagnose.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action' => array(
						'type'        => 'string',
						'enum'        => array( 'info', 'diagnose' ),
						'description' => __( 'Action to perform (default: info)', 'bricks-mcp' ),
					),
				),
			),
			array( $this, 'tool_get_site_info' ),
			array( 'readOnlyHint' => true )
		);

		$this->register_tool(
			'confirm_destructive_action',
			__( "Confirm a destructive action using a one-time token. When a destructive operation (delete, bulk replace, cascade remove, etc.) is requested, the server returns a confirmation token instead of executing immediately. Present the action description to the user and call this tool with the token only after they approve.\n\nThe token expires after 2 minutes and can only be used once.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'token' => array(
						'type'        => 'string',
						'description' => __( 'The confirmation token returned by the destructive operation.', 'bricks-mcp' ),
						'pattern'     => '^[0-9a-f]{16}$',
					),
				),
				'required'   => array( 'token' ),
			),
			array( $this, 'tool_confirm_destructive_action' ),
			array( 'destructiveHint' => true )
		);

		/**
		 * Filter the registered MCP tools.
		 *
		 * Allows other plugins to add or modify MCP tools.
		 * Third-party tools must include 'name', 'description', 'inputSchema', and 'handler'.
		 *
		 * @param array $tools Registered tools keyed by name.
		 */
		$filtered = apply_filters( 'bricks_mcp_tools', $this->registry->get_all_raw() );

		// Re-register validated filtered tools.
		if ( is_array( $filtered ) ) {
			foreach ( $filtered as $name => $tool ) {
				if ( ! is_array( $tool ) || ! isset( $tool['handler'] ) || ! is_callable( $tool['handler'] ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging.
					error_log( 'BricksMCP: Rejected invalid tool from bricks_mcp_tools filter: ' . sanitize_text_field( $name ) );
					continue;
				}
				if ( ! $this->registry->has( $name ) ) {
					$this->registry->register(
						$tool['name'] ?? $name,
						$tool['description'] ?? '',
						$tool['inputSchema'] ?? [],
						$tool['handler'],
						$tool['annotations'] ?? []
					);
				}
			}
		}
	}

	/**
	 * Register a tool.
	 *
	 * @param string   $name             Tool name.
	 * @param string   $description      Tool description.
	 * @param array    $input_schema     Tool input schema.
	 * @param callable $handler          Tool handler callback.
	 * @param array    $annotations      Optional MCP annotations (e.g. readOnlyHint, destructiveHint).
	 * @return void
	 */
	public function register_tool( string $name, string $description, array $input_schema, callable $handler, array $annotations = [] ): void {
		$this->registry->register( $name, $description, $input_schema, $handler, $annotations );
	}

	/**
	 * Get available tools in MCP format.
	 *
	 * @return array<int, array{name: string, description: string, inputSchema: array}> Tools list.
	 */
	public function get_available_tools(): array {
		return $this->registry->get_all();
	}

	/**
	 * Execute a tool.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $name      Tool name.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return \WP_REST_Response The response.
	 * @throws \Throwable When tool execution fails unexpectedly.
	 */
	public function execute_tool( string $name, array $arguments ): \WP_REST_Response {
		$tool = $this->registry->get( $name );
		if ( null === $tool ) {
			return Response::error(
				'unknown_tool',
				/* translators: %s: Tool name */
				sprintf( __( 'Unknown tool: %s', 'bricks-mcp' ), $name ),
				404
			);
		}

		// Strip 'confirm' from incoming arguments to prevent AI bypass.
		// Only the confirm_destructive_action tool can inject it internally.
		unset( $arguments['confirm'] );

		$capability = $this->get_tool_capability( $name );
		if ( null !== $capability && ! current_user_can( $capability ) ) {
			return Response::error(
				'bricks_mcp_forbidden',
				/* translators: %s: Required capability */
				sprintf( __( 'You do not have the required capability (%s) to use this tool.', 'bricks-mcp' ), $capability ),
				403
			);
		}

		// Validate tool arguments against inputSchema.
		$validation = $this->validation_service->validate_arguments( $arguments, $tool['inputSchema'], $name );
		if ( is_wp_error( $validation ) ) {
			return Response::error(
				'invalid_arguments',
				$validation->get_error_message(),
				422
			);
		}

		// Prerequisite gate: block content writes unless mandatory calls have been made.
		$tier = $this->get_operation_tier( $name, $arguments );
		if ( null !== $tier ) {
			$gate_result = PrerequisiteGateService::check( $tier );
			if ( true !== $gate_result ) {
				$missing_tools = $gate_result['missing_tools'];
				return Response::error(
					'bricks_mcp_prerequisites_not_met',
					sprintf(
						'You must call these tools before modifying content: %s. Call them now, then retry.',
						implode( ', ', $missing_tools )
					),
					422,
					[
						'missing'   => $gate_result['missing'],
						'satisfied' => $gate_result['satisfied'],
					]
				);
			}
		}

		// Design build gate: reject complex element trees that should use build_from_schema.
		$design_gate = $this->check_design_build_gate( $name, $arguments );
		if ( null !== $design_gate ) {
			return $design_gate;
		}

		try {
			$result = call_user_func( $tool['handler'], $arguments );

			if ( is_wp_error( $result ) ) {
				// Intercept confirmation-required errors to generate a token.
				if ( self::ERROR_CONFIRM_REQUIRED === $result->get_error_code() ) {
					return $this->create_confirmation_response( $name, $arguments, $result->get_error_message() );
				}
				return Response::tool_error( $result );
			}

			// Set prerequisite flags for gate tracking.
			// site_context requires BOTH get_site_info and global_class:list.
			// A non-string 'action' from the JSON body yields strict !== 'list' (never
			// matches), so ValidationService must reject those upstream; this branch
			// only runs on validated string values.
			if ( 'global_class' === $name && ( $arguments['action'] ?? '' ) === 'list' ) {
				$this->maybe_set_site_context( 'classes_done' );
			} elseif ( 'propose_design' === $name && is_array( $result ) ) {
				$phase = $result['phase'] ?? '';
				if ( 'proposal' === $phase ) {
					// Phase 2 success means design_ready.
					PrerequisiteGateService::set_flag( 'design_ready' );
				}
			}

			return Response::success(
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT ),
						),
					),
				)
			);
		} catch ( \Throwable $e ) {
			return Response::error(
				'tool_execution_error',
				$e->getMessage(),
				500
			);
		}
	}

	/**
	 * Check if a tool call is a gated content write operation.
	 *
	 * @param string               $name      Tool name.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return string|null Tier name ('direct', 'design') or null if not gated.
	 */
	private function get_operation_tier( string $name, array $arguments ): ?string {
		if ( ! isset( self::GATED_OPERATIONS[ $name ] ) ) {
			return null;
		}

		$ops = self::GATED_OPERATIONS[ $name ];

		// Tools gated unconditionally (no action routing, e.g. build_from_schema).
		if ( isset( $ops['_always'] ) ) {
			return $ops['_always'];
		}

		$action = $arguments['action'] ?? '';

		// Special case: page:create and template:create are only gated when elements are provided.
		if ( in_array( $name, [ 'page', 'template' ], true ) && 'create' === $action ) {
			if ( empty( $arguments['elements'] ) ) {
				return null;
			}
		}

		return $ops[ $action ] ?? null;
	}

	/**
	 * Design build gate: reject section elements that should use build_from_schema.
	 *
	 * Checks page:append_content, page:update_content, page:create (with elements),
	 * page:import_clipboard, and element:bulk_add. Rejects when:
	 * - Any root element is a section (full sections must use build_from_schema
	 *   for proper validation, class resolution, and design consistency).
	 *
	 * Non-section elements of any count are allowed for instructed builds.
	 * Can be bypassed with bypass_design_gate: true in arguments.
	 *
	 * @param string               $name      Tool name.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return \WP_REST_Response|null Error response if gate triggered, null if allowed.
	 */
	private function check_design_build_gate( string $name, array $arguments ): ?\WP_REST_Response {
		// Only check specific tool + action combos.
		$gated_combos = [
			'page'    => [ 'append_content', 'update_content', 'create', 'import_clipboard' ],
			'element' => [ 'bulk_add' ],
		];

		if ( ! isset( $gated_combos[ $name ] ) ) {
			return null;
		}

		$action = $arguments['action'] ?? '';
		if ( ! in_array( $action, $gated_combos[ $name ], true ) ) {
			return null;
		}

		// Allow bypass for intentional instructed builds.
		if ( ! empty( $arguments['bypass_design_gate'] ) ) {
			return null;
		}

		// Extract elements array.
		$elements = $arguments['elements'] ?? [];
		if ( 'import_clipboard' === $action ) {
			$elements = $arguments['clipboard_data']['content'] ?? [];
		}

		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return null;
		}

		// Page ID for the suggested next call (if available).
		// Guard against non-scalar post_id/page_id from malformed input — (int) cast
		// on an array would silently collapse to 1 and put the wrong page id into the
		// suggested-next message that the AI follows.
		$raw_page_id = $arguments['post_id'] ?? $arguments['page_id'] ?? 0;
		$page_id     = is_scalar( $raw_page_id ) ? (int) $raw_page_id : 0;
		$next_target = $page_id > 0 ? sprintf( 'page_id=%d', $page_id ) : 'page_id=<your_page_id>';

		// Gate: section elements must use the design build pipeline.
		// Non-section elements of any count are allowed for instructed builds.
		foreach ( $elements as $el ) {
			// Guard against malformed input — an element entry that's a string/int
			// would otherwise emit E_WARNING on subscript access AND silently bypass
			// the design gate (no name match → no section → fallthrough).
			if ( ! is_array( $el ) ) {
				continue;
			}
			$el_name = $el['name'] ?? '';
			if ( 'section' === $el_name ) {
				return Response::error(
					'bricks_mcp_use_build_from_schema',
					sprintf(
						/* translators: %s: Suggested next call (e.g. propose_design(page_id=42, description='...')). */
						__( 'Section elements must be built using build_from_schema for proper validation, class resolution, and design consistency. Start the 4-step pipeline: call %s to discover site context, then again with a design_plan, then build_from_schema, then verify_build. Use bypass_design_gate: true only if you have a specific reason to bypass this.', 'bricks-mcp' ),
						sprintf( "propose_design(%s, description='<describe the section>')", $next_target )
					),
					422
				);
			}
		}

		return null;
	}

	/**
	 * Create a confirmation token response for a destructive action.
	 *
	 * Intercepts bricks_mcp_confirm_required errors, generates a one-time-use
	 * token, and returns an error response instructing the AI to call
	 * confirm_destructive_action with the token.
	 *
	 * @param string               $tool_name   The tool that requires confirmation.
	 * @param array<string, mixed> $arguments   The original arguments (with 'confirm' already stripped).
	 * @param string               $description The handler's description of what will happen.
	 * @return \WP_REST_Response The error response containing the token.
	 */
	private function create_confirmation_response( string $tool_name, array $arguments, string $description ): \WP_REST_Response {
		$token = $this->pending_action_service->create( $tool_name, $arguments, $description );

		// LEGACY: strip the pre-token "Set confirm: true to proceed" instruction from
		// handler descriptions that still carry it. Safe to remove this substitution
		// once all handler messages have been migrated (>= v4.0).
		$clean_description = preg_replace(
			'/\s*Set confirm: true to proceed[^.]*\.?/',
			'',
			$description
		);
		$clean_description = rtrim( (string) $clean_description, '. ' );

		$message = sprintf(
			/* translators: 1: Action description, 2: Confirmation token */
			__( "%1\$s\n\nTo proceed, call confirm_destructive_action with token: %2\$s\nThis token expires in 2 minutes and can only be used once.", 'bricks-mcp' ),
			$clean_description,
			$token
		);

		$error = new \WP_Error(
			self::ERROR_CONFIRM_REQUIRED,
			$message,
			array( 'token' => $token )
		);

		return Response::tool_error( $error );
	}

	/**
	 * Get the required WordPress capability for a tool.
	 *
	 * Returns null for public tools (no capability required).
	 *
	 * @since 1.0.0
	 *
	 * @param string $tool_name The tool name.
	 * @return string|null The required capability, or null if no capability is required.
	 */
	private function get_tool_capability( string $tool_name ): ?string {
		$read_tools = array(
			'get_site_info',
			'metabox',
		);

		if ( in_array( $tool_name, $read_tools, true ) ) {
			return 'read';
		}

		// All other tools (bricks, page, element, template, etc.) require manage_options.
		return BricksCore::REQUIRED_CAPABILITY;
	}

	/**
	 * Tool: Confirm a destructive action using a one-time token.
	 *
	 * Validates the token, retrieves the stored action, and re-dispatches
	 * the original tool handler with confirm: true injected internally.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: token).
	 * @return array<string, mixed>|\WP_Error Result of the confirmed action.
	 */
	public function tool_confirm_destructive_action( array $args ): array|\WP_Error {
		// Defensive string cast: the schema enforces the hex pattern via ValidationService
		// upstream, but if this handler is ever called outside execute_tool() (e.g.
		// direct PHP test harness), a non-string token must not reach the consumer.
		$raw   = $args['token'] ?? '';
		$token = is_string( $raw ) ? sanitize_text_field( $raw ) : '';

		$pending = $this->pending_action_service->validate_and_consume( $token );
		if ( false === $pending ) {
			return new \WP_Error(
				'invalid_token',
				__( 'Invalid or expired confirmation token. Request the destructive action again to get a new token.', 'bricks-mcp' )
			);
		}

		$tool_name = $pending['tool_name'] ?? '';
		$tool_args = $pending['args'] ?? [];
		if ( ! is_array( $tool_args ) ) {
			$tool_args = [];
		}

		$tool = $this->registry->get( $tool_name );
		if ( null === $tool ) {
			return new \WP_Error(
				'tool_not_found',
				/* translators: %s: Tool name */
				sprintf( __( 'The original tool "%s" is no longer available.', 'bricks-mcp' ), $tool_name )
			);
		}

		// Re-check capability on confirm. The original call went through execute_tool()'s
		// capability check, but the user may have been demoted between the original call
		// and the confirmation. Tokens are proof of intent, not proof of current authorization.
		$capability = $this->get_tool_capability( $tool_name );
		if ( null !== $capability && ! current_user_can( $capability ) ) {
			return new \WP_Error(
				'bricks_mcp_forbidden',
				/* translators: %s: Required capability */
				sprintf( __( 'You do not have the required capability (%s) to confirm this action.', 'bricks-mcp' ), $capability )
			);
		}

		// Re-inject confirm and call the handler directly. We intentionally bypass
		// execute_tool() here because it strips the `confirm` key to prevent AI bypass —
		// but at this point the token has proven intent, so confirm must stay.
		$tool_args['confirm'] = true;

		// Wrap handler call in try/catch so uncaught exceptions surface as JSON-RPC errors
		// instead of crashing the MCP dispatcher with a 500.
		try {
			$result = call_user_func( $tool['handler'], $tool_args );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'tool_execution_error',
				$e->getMessage()
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) && ! is_string( $result ) ) {
			return new \WP_Error(
				'invalid_handler_result',
				__( 'Confirmed tool returned an unexpected result shape.', 'bricks-mcp' )
			);
		}

		return is_array( $result ) ? $result : [ 'result' => $result ];
	}

	/**
	 * Tool: Get site info.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused for this tool).
	 * @return array<string, mixed> Site information.
	 */
	public function tool_get_site_info( array $args ): array {
		$action = $args['action'] ?? 'info';

		if ( 'diagnose' === $action ) {
			$runner = new \BricksMCP\Admin\DiagnosticRunner();
			$runner->register_defaults();
			return $runner->run_all();
		}

		// Default: return site info (existing behavior).
		$info = array(
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => get_bloginfo( 'url' ),
			'language'    => get_bloginfo( 'language' ),
			'version'     => get_bloginfo( 'version' ),
			'charset'     => get_bloginfo( 'charset' ),
			'timezone'    => wp_timezone_string(),
		);

		// Append design tokens: variable names grouped by category (no values).
		$categories = get_option( BricksCore::OPTION_VARIABLE_CATEGORIES, [] );
		$variables  = get_option( BricksCore::OPTION_GLOBAL_VARIABLES, [] );

		if ( is_array( $categories ) && is_array( $variables ) ) {
			$tokens = [];
			foreach ( $categories as $cat ) {
				$cat_id   = $cat['id'] ?? '';
				$cat_name = $cat['name'] ?? '';
				$names    = [];
				foreach ( $variables as $var ) {
					if ( ( $var['category'] ?? '' ) === $cat_id && ! empty( $var['name'] ) ) {
						$names[] = $var['name'];
					}
				}
				if ( ! empty( $names ) ) {
					$tokens[ $cat_name ] = $names;
				}
			}
			if ( ! empty( $tokens ) ) {
				$info['design_tokens'] = $tokens;
				$info['design_tokens_note'] = 'Use var(--name) to reference these variables in element settings (e.g. var(--space-m), var(--h2), var(--primary)). Never hardcode values when a variable exists.';
			}
		}

		// Child theme CSS summary (not the full CSS — the build phase handles specifics).
		$child_style = get_stylesheet_directory() . '/style.css';
		if ( get_stylesheet_directory() !== get_template_directory() && file_exists( $child_style ) ) {
			$size = filesize( $child_style );
			if ( false !== $size && $size > 0 ) {
				$css = file_get_contents( $child_style ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( is_string( $css ) && '' !== trim( $css ) ) {
					$css        = (string) preg_replace( '/\/\*[\s\S]*?\*\/\s*/', '', $css );
					$rule_count = substr_count( $css, '{' );
					$info['child_theme_css'] = [
						'active'     => true,
						'rule_count' => $rule_count,
						'size_bytes' => $size,
						'note'       => 'Child theme CSS is loaded globally. Headings, sections, and containers are already styled — do not duplicate inline.',
					];
				}
			}
		}

		// Color palette removed — site custom colors are already in design_tokens
		// under "Colors" category (primary, secondary, accent, base, white, etc.).
		// The Bricks default palette (grey, amber, etc.) is noise for the AI.

		// Pages summary: brief overview of all Bricks-enabled pages.
		$pages_query = new \WP_Query( [
			'post_type'      => array_values( get_post_types( [ 'public' => true ] ) ),
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'meta_query'     => [
				[
					'key'     => BricksService::META_KEY,
					'compare' => 'EXISTS',
				],
			],
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		$pages_summary = [];
		// Guard: $pages_query->posts can be an array of IDs (fields=ids), WP_Post objects,
		// or even arrays of both if a plugin filters the_posts. Normalize defensively.
		$post_ids = is_array( $pages_query->posts ?? null ) ? $pages_query->posts : [];
		foreach ( $post_ids as $post_ref ) {
			// Resolve to integer ID regardless of shape (int, numeric string, WP_Post).
			if ( is_object( $post_ref ) && isset( $post_ref->ID ) ) {
				$pid = (int) $post_ref->ID;
			} elseif ( is_numeric( $post_ref ) ) {
				$pid = (int) $post_ref;
			} else {
				continue;
			}
			$elements = get_post_meta( $pid, BricksService::META_KEY, true );
			$elements = is_array( $elements ) ? $elements : [];
			$section_count = 0;
			$section_types = [];
			foreach ( $elements as $el ) {
				if ( ! is_array( $el ) ) {
					continue;
				}
				if ( ( $el['name'] ?? '' ) === 'section' && BricksCore::is_root_element( $el ) ) {
					$section_count++;
					$settings = is_array( $el['settings'] ?? null ) ? $el['settings'] : [];
					$label    = $settings['label'] ?? $el['label'] ?? '';
					if ( $label ) {
						$section_types[] = $label;
					}
				}
			}

			$post_obj        = get_post( $pid );
			$pages_summary[] = [
				'id'       => $pid,
				'title'    => $post_obj ? $post_obj->post_title : '',
				'slug'     => $post_obj ? $post_obj->post_name : '',
				'sections' => $section_count,
				'elements' => count( $elements ),
				'summary'  => $section_types ? implode( ', ', $section_types ) : 'No labeled sections',
			];
		}
		$info['pages_summary'] = $pages_summary;



		// Include AI notes so they are visible on the first mandatory call.
		$ai_notes = $this->bricks_service->get_notes();
		if ( ! empty( $ai_notes ) ) {
			// Relax type hint: a future notes payload with mixed shapes (string entries,
			// null, etc.) would TypeError with `array $n`. Fall back to '' for non-arrays.
			$info['ai_notes']      = array_map(
				fn( $n ) => is_array( $n ) ? ( $n['text'] ?? '' ) : '',
				$ai_notes
			);
			$info['ai_notes_note'] = 'These are persistent instructions from the site owner. You MUST follow them.';
		}

		// Include design and business briefs if set.
		$briefs = get_option( BricksCore::OPTION_BRIEFS, [] );
		if ( is_array( $briefs ) ) {
			$design_brief   = trim( $briefs['design_brief'] ?? '' );
			$business_brief = trim( $briefs['business_brief'] ?? '' );

			if ( '' !== $design_brief ) {
				$info['design_brief'] = $design_brief;
				$info['design_brief_note'] = 'Design guidelines from the site owner. Follow these visual rules when building sections.';
			}
			if ( '' !== $business_brief ) {
				$info['business_brief'] = $business_brief;
				$info['business_brief_note'] = 'Business context from the site owner. Use this to generate relevant content instead of placeholder text.';
			}
		}

		$this->maybe_set_site_context( 'site_info_done' );

		return $info;
	}

	/**
	 * Register Bricks Builder-specific tools.
	 *
	 * Only registers tools if Bricks Builder is active (STNG-05 gate).
	 * Non-Bricks tools continue working regardless of Bricks status.
	 *
	 * @return void
	 */
	public function register_bricks_tools(): void {
		// Gate: skip registration when Bricks is not installed.
		if ( ! $this->bricks_service->is_bricks_active() ) {
			return;
		}

		// Handlers that register Bricks-only tools. Order matches the v3.x grouping.
		$bricks_handler_keys = [
			'bricks_tool', 'page', 'element', 'template', 'global_class',
			'design_system', 'media', 'menu', 'component', 'woocommerce',
			'font', 'code', 'proposal', 'build', 'verify', 'page_layout',
			'design_pattern', 'build_structure', 'populate_content',
		];

		foreach ( $bricks_handler_keys as $key ) {
			if ( isset( $this->handlers[ $key ] ) && method_exists( $this->handlers[ $key ], 'register' ) ) {
				$this->handlers[ $key ]->register( $this->registry );
			}
		}
	}

	/**
	 * Track sub-conditions for site_context and set the flag when both are met.
	 *
	 * site_context requires BOTH get_site_info and global_class:list to have been
	 * called. This method tracks each sub-condition in the same transient and sets
	 * the composite flag once both are satisfied.
	 *
	 * @param string $sub_key One of 'site_info_done', 'classes_done'.
	 */
	private function maybe_set_site_context( string $sub_key ): void {
		$transient_key = self::PREREQS_TRANSIENT_PREFIX . get_current_user_id();
		$flags         = get_transient( $transient_key );
		if ( ! is_array( $flags ) ) {
			$flags = [];
		}

		$flags[ $sub_key ] = true;

		// Set the composite flag when both sub-conditions are met.
		if ( ! empty( $flags['site_info_done'] ) && ! empty( $flags['classes_done'] ) ) {
			$flags['site_context'] = true;
		}

		// Floor TTL at 60s: a misconfigured filter that returns 0 would store forever
		// on some cache backends and evaporate immediately on others — neither is
		// desirable for prerequisite tracking.
		$ttl = max( 60, (int) apply_filters( 'bricks_mcp_prerequisite_ttl', 7200 ) );
		set_transient( $transient_key, $flags, $ttl );
	}

	/**
	 * Require Bricks Builder to be active for a tool.
	 *
	 * Returns a WP_Error if Bricks is not active, null if it is active.
	 *
	 * @return \WP_Error|null WP_Error if Bricks required but not active, null if active.
	 */
	private function require_bricks(): ?\WP_Error {
		if ( ! $this->bricks_service->is_bricks_active() ) {
			return new \WP_Error(
				'bricks_required',
				__( 'Bricks Builder must be installed and active to use this tool. Install and activate Bricks Builder, then retry.', 'bricks-mcp' )
			);
		}
		return null;
	}

}
