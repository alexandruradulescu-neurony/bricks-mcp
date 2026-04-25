<?php
/**
 * MCP Router implementation.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP;

use BricksMCP\MCP\Services\BricksCore;
use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\ClassIntentResolver;
use BricksMCP\MCP\Services\DesignSchemaValidator;
use BricksMCP\MCP\Services\DesignSystemIntrospector;
use BricksMCP\MCP\Services\ElementSettingsGenerator;
use BricksMCP\MCP\Services\MediaService;
use BricksMCP\MCP\Services\MenuService;
use BricksMCP\MCP\Services\OnboardingService;
use BricksMCP\MCP\Services\PendingActionService;
use BricksMCP\MCP\Services\SchemaExpander;
use BricksMCP\MCP\Services\SchemaGenerator;
use BricksMCP\MCP\Services\ValidationService;
use BricksMCP\MCP\Services\ProposalService;
use BricksMCP\MCP\Services\PageLayoutService;
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
	 * Tool registry instance.
	 *
	 * @var ToolRegistry
	 */
	private ToolRegistry $registry;

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

		// v5.1: vision pipeline removed. design_pattern is CRUD-only; no LLM provider lives in this plugin.
		$onboarding_service = new OnboardingService( $this->bricks_service );

		// BuildHandler, BuildStructureHandler, PopulateContentHandler, VerifyHandler:
		// extracted as locals so DesignPatternHandler::make_v32 can receive them by ref.
		$build_handler = new Handlers\BuildHandler(
			$this->bricks_service,
			$design_validator,
			$class_resolver,
			$schema_expander,
			$element_settings_gen,
			$proposal_service
		);
		$build_structure_handler = new Handlers\BuildStructureHandler(
			$build_handler,
			$this->bricks_service,
			$proposal_service,
			$this->bricks_service->get_global_class_service(),
			$this->bricks_service->get_global_variable_service(),
			$this->media_service
		);
		$populate_content_handler = new Handlers\PopulateContentHandler( $this->bricks_service, $this->media_service );
		$verify_handler           = new Handlers\VerifyHandler( $this->bricks_service, $require_bricks );

		// v5 (Phase B): HTML input mode wraps BuildStructureHandler so the
		// resolve / normalize / validate / write / verify pipeline still applies.
		$build_from_html_handler = new Handlers\BuildFromHtmlHandler(
			$build_structure_handler,
			$require_bricks
		);

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
				$this->bricks_service
			),
			'build'         => $build_handler,
			'build_structure'  => $build_structure_handler,
			'build_from_html'  => $build_from_html_handler,
			'populate_content' => $populate_content_handler,
			'onboarding'    => new OnboardingHandler( $onboarding_service ),
			'verify'        => $verify_handler,
			'page_layout'      => new Handlers\PageLayoutHandler( new PageLayoutService(), $require_bricks ),
			'design_pattern'   => new Handlers\DesignPatternHandler( $this->bricks_service, $require_bricks ),
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
			__( "Get WordPress site information including design tokens, child theme CSS, and color palette.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action' => array(
						'type'        => 'string',
						'enum'        => array( 'info' ),
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

		try {
			$result = call_user_func( $tool['handler'], $arguments );

			if ( is_wp_error( $result ) ) {
				// Intercept confirmation-required errors to generate a token.
				if ( self::ERROR_CONFIRM_REQUIRED === $result->get_error_code() ) {
					return $this->create_confirmation_response( $name, $arguments, $result->get_error_message() );
				}
				return Response::tool_error( $result );
			}

			// Prerequisite flag tracking removed — gates removed in v4.0.

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

		$design_system = new DesignSystemIntrospector( $this->bricks_service->get_global_class_service() );
		$info['design_system_readiness'] = $design_system->analyze();
		$info['design_system_readiness_note'] = 'Use this before building: foundation tokens, component classes, and patterns are separate readiness layers. Reuse existing resolved style_roles when confidence is high; otherwise map or generate missing component classes instead of inventing hardcoded names.';

		// Pages summary: brief overview of all Bricks-enabled pages (cached 5 min).
		$cached_summary = get_transient( 'bricks_mcp_pages_summary' );
		if ( false !== $cached_summary && is_array( $cached_summary ) ) {
			$info['pages_summary'] = $cached_summary;
		} else {
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
			set_transient( 'bricks_mcp_pages_summary', $pages_summary, 5 * MINUTE_IN_SECONDS );
		}

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
		// NOTE: 'build' handler stays instantiated (BuildStructureHandler calls it
		// internally via _internal=true) but is NOT registered as a public tool.
		$bricks_handler_keys = [
			'bricks_tool', 'page', 'element', 'template', 'global_class',
			'design_system', 'media', 'menu', 'component', 'woocommerce',
			'font', 'code', 'proposal', 'verify', 'page_layout',
			'design_pattern', 'build_structure', 'build_from_html', 'populate_content',
		];

		foreach ( $bricks_handler_keys as $key ) {
			if ( isset( $this->handlers[ $key ] ) && method_exists( $this->handlers[ $key ], 'register' ) ) {
				$this->handlers[ $key ]->register( $this->registry );
			}
		}
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
