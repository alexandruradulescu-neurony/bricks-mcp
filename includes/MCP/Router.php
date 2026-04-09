<?php
/**
 * MCP Router implementation.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP;

use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\ClassIntentResolver;
use BricksMCP\MCP\Services\DesignSchemaValidator;
use BricksMCP\MCP\Services\ElementSettingsGenerator;
use BricksMCP\MCP\Services\MediaService;
use BricksMCP\MCP\Services\MenuService;
use BricksMCP\MCP\Services\PendingActionService;
use BricksMCP\MCP\Services\SchemaExpander;
use BricksMCP\MCP\Services\SchemaGenerator;
use BricksMCP\MCP\Services\ValidationService;
use BricksMCP\MCP\Services\PrerequisiteGateService;
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
	 * Tool registry instance.
	 *
	 * @var ToolRegistry
	 */
	private ToolRegistry $registry;

	/**
	 * Operations that require prerequisites, with tier level per action.
	 *
	 * Tiers: 'direct' (site_info only), 'instructed' (+ classes), 'full' (+ variables).
	 *
	 * @var array<string, array<string, string>>
	 */
	private const GATED_OPERATIONS = [
		'page'      => [
			'update_content'   => 'instructed',
			'append_content'   => 'instructed',
			'create'           => 'instructed',
			'import_clipboard' => 'instructed',
		],
		'element'   => [
			'add'         => 'instructed',
			'bulk_add'    => 'instructed',
			'update'      => 'direct',
			'bulk_update' => 'direct',
		],
		'template'  => [
			'create' => 'instructed',
		],
		'component' => [
			'create'      => 'instructed',
			'update'      => 'instructed',
			'instantiate' => 'instructed',
			'fill_slot'   => 'instructed',
		],
		'build_from_schema' => [
			'_always' => 'full',
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
	 * Component handler instance.
	 *
	 * @var Handlers\ComponentHandler
	 */
	private Handlers\ComponentHandler $component_handler;

	/**
	 * WooCommerce handler instance.
	 *
	 * @var Handlers\WooCommerceHandler
	 */
	private Handlers\WooCommerceHandler $woocommerce_handler;

	/**
	 * Schema handler instance.
	 *
	 * @var Handlers\SchemaHandler
	 */
	private Handlers\SchemaHandler $schema_handler;

	/**
	 * Menu handler instance.
	 *
	 * @var Handlers\MenuHandler
	 */
	private Handlers\MenuHandler $menu_handler;

	/**
	 * Page handler instance.
	 *
	 * @var Handlers\PageHandler
	 */
	private Handlers\PageHandler $page_handler;

	/**
	 * Element handler instance.
	 *
	 * @var Handlers\ElementHandler
	 */
	private Handlers\ElementHandler $element_handler;

	/**
	 * Template handler instance.
	 *
	 * @var Handlers\TemplateHandler
	 */
	private Handlers\TemplateHandler $template_handler;

	/**
	 * Global class handler instance.
	 *
	 * @var Handlers\GlobalClassHandler
	 */
	private Handlers\GlobalClassHandler $global_class_handler;

	/**
	 * Design system handler instance.
	 *
	 * @var Handlers\DesignSystemHandler
	 */
	private Handlers\DesignSystemHandler $design_system_handler;

	/**
	 * WordPress handler instance.
	 *
	 * @var Handlers\WordPressHandler
	 */
	private Handlers\WordPressHandler $wordpress_handler;

	/**
	 * MetaBox handler instance.
	 *
	 * @var Handlers\MetaBoxHandler
	 */
	private Handlers\MetaBoxHandler $metabox_handler;

	/**
	 * Bricks tool handler instance.
	 *
	 * @var Handlers\BricksToolHandler
	 */
	private Handlers\BricksToolHandler $bricks_tool_handler;

	/**
	 * Media handler instance.
	 *
	 * @var Handlers\MediaHandler
	 */
	private Handlers\MediaHandler $media_handler;

	/**
	 * Font handler instance.
	 *
	 * @var Handlers\FontHandler
	 */
	private Handlers\FontHandler $font_handler;

	/**
	 * Code handler instance.
	 *
	 * @var Handlers\CodeHandler
	 */
	private Handlers\CodeHandler $code_handler;

	/**
	 * Build handler instance.
	 *
	 * @var Handlers\BuildHandler
	 */
	private Handlers\BuildHandler $build_handler;

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

		// Existing handlers.
		$this->component_handler    = new Handlers\ComponentHandler( $this->bricks_service );
		$this->woocommerce_handler  = new Handlers\WooCommerceHandler( $this->bricks_service, $this->schema_generator );
		$this->schema_handler       = new Handlers\SchemaHandler( $this->schema_generator, $this->bricks_service );
		$this->menu_handler         = new Handlers\MenuHandler( $this->menu_service, $require_bricks );
		$this->page_handler         = new Handlers\PageHandler( $this->bricks_service, $this->validation_service );
		$this->element_handler      = new Handlers\ElementHandler( $this->bricks_service );
		$this->template_handler     = new Handlers\TemplateHandler( $this->bricks_service );
		$this->global_class_handler = new Handlers\GlobalClassHandler( $this->bricks_service );
		$this->design_system_handler = new Handlers\DesignSystemHandler( $this->bricks_service );

		// New extracted handlers.
		$this->wordpress_handler    = new Handlers\WordPressHandler();
		$this->metabox_handler      = new Handlers\MetaBoxHandler();
		$this->bricks_tool_handler  = new Handlers\BricksToolHandler( $this->bricks_service, $this->schema_handler );
		$this->media_handler        = new Handlers\MediaHandler( $this->media_service, $require_bricks );
		$this->font_handler         = new Handlers\FontHandler( $this->bricks_service, $require_bricks );
		$this->code_handler         = new Handlers\CodeHandler( $this->bricks_service, $require_bricks );

		// Build pipeline handler.
		$design_validator        = new DesignSchemaValidator( $this->schema_generator );
		$class_resolver          = new ClassIntentResolver( $this->bricks_service->get_global_class_service() );
		$schema_expander         = new SchemaExpander();
		$element_settings_gen    = new ElementSettingsGenerator( $this->schema_generator, $this->media_service );
		$this->build_handler     = new Handlers\BuildHandler(
			$this->bricks_service,
			$design_validator,
			$class_resolver,
			$schema_expander,
			$element_settings_gen
		);

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
		// Get site info tool.
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

		// Confirm destructive action tool (token-based confirmation).
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

		// WordPress consolidated tool (replaces get_posts, get_post, get_users, get_plugins).
		$this->register_tool(
			'wordpress',
			__( "Query and manage WordPress data.\n\nActions: get_posts, get_post, get_users, get_plugins, activate_plugin, deactivate_plugin, create_user, update_user.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'         => array(
						'type'        => 'string',
						'enum'        => array( 'get_posts', 'get_post', 'get_users', 'get_plugins', 'activate_plugin', 'deactivate_plugin', 'create_user', 'update_user' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'post_type'      => array(
						'type'        => 'string',
						'description' => __( 'Post type to query (get_posts: default post)', 'bricks-mcp' ),
					),
					'posts_per_page' => array(
						'type'        => 'integer',
						'description' => __( 'Number of posts to return (get_posts: default 10, max 100)', 'bricks-mcp' ),
					),
					'orderby'        => array(
						'type'        => 'string',
						'description' => __( 'Order by field (get_posts: date, title, modified, etc.)', 'bricks-mcp' ),
					),
					'order'          => array(
						'type'        => 'string',
						'enum'        => array( 'ASC', 'DESC' ),
						'description' => __( 'Sort order (get_posts: ASC or DESC)', 'bricks-mcp' ),
					),
					'id'             => array(
						'type'        => 'integer',
						'description' => __( 'Post ID (get_post: required)', 'bricks-mcp' ),
					),
					'role'           => array(
						'type'        => 'string',
						'description' => __( 'Filter by user role (get_users)', 'bricks-mcp' ),
					),
					'number'         => array(
						'type'        => 'integer',
						'description' => __( 'Number of users to return (get_users: default 10)', 'bricks-mcp' ),
					),
					'status'         => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'active', 'inactive' ),
						'description' => __( 'Filter by plugin status (get_plugins)', 'bricks-mcp' ),
					),
					'include_pii'    => array(
						'type'        => 'boolean',
						'description' => __( 'Include sensitive fields (email, login). Warning: data may be logged by AI services. (get_users: default false)', 'bricks-mcp' ),
					),
					'plugin_file'    => array(
						'type'        => 'string',
						'description' => __( 'Plugin file path relative to plugins directory (activate_plugin, deactivate_plugin: required, e.g. "akismet/akismet.php")', 'bricks-mcp' ),
					),
					'username'       => array(
						'type'        => 'string',
						'description' => __( 'Username (create_user: required)', 'bricks-mcp' ),
					),
					'email'          => array(
						'type'        => 'string',
						'description' => __( 'User email (create_user: required, update_user: optional)', 'bricks-mcp' ),
					),
					'password'       => array(
						'type'        => 'string',
						'description' => __( 'User password (create_user: optional, auto-generated if omitted)', 'bricks-mcp' ),
					),
					'display_name'   => array(
						'type'        => 'string',
						'description' => __( 'Display name (create_user, update_user: optional)', 'bricks-mcp' ),
					),
					'user_role'      => array(
						'type'        => 'string',
						'description' => __( 'User role (create_user: default "subscriber", update_user: optional)', 'bricks-mcp' ),
					),
					'user_id'        => array(
						'type'        => 'integer',
						'description' => __( 'User ID (update_user: required)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this->wordpress_handler, 'handle' )
		);

		// MetaBox integration tool (read-only).
		$this->register_tool(
			'metabox',
			__( "Read Meta Box custom fields and field groups.\n\nActions: list_field_groups, get_fields, get_field_value, get_dynamic_tags.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'    => array(
						'type'        => 'string',
						'enum'        => array( 'list_field_groups', 'get_fields', 'get_field_value', 'get_dynamic_tags' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'post_type' => array(
						'type'        => 'string',
						'description' => __( 'Post type to get fields for (get_fields: required, get_dynamic_tags: optional filter)', 'bricks-mcp' ),
					),
					'post_id'   => array(
						'type'        => 'integer',
						'description' => __( 'Post ID to read field values from (get_field_value: required)', 'bricks-mcp' ),
					),
					'field_id'  => array(
						'type'        => 'string',
						'description' => __( 'MetaBox field ID (get_field_value: required)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this->metabox_handler, 'handle' ),
			array( 'readOnlyHint' => true )
		);

		/**
		 * Filter the registered MCP tools.
		 *
		 * Allows other plugins to add or modify MCP tools.
		 *
		 * @param array $tools Registered tools.
		 */
		$this->tools = apply_filters( 'bricks_mcp_tools', $this->tools );

		// Validate filtered tools — reject malformed entries from third-party plugins.
		foreach ( $this->tools as $name => $tool ) {
			if ( ! is_array( $tool ) || ! isset( $tool['handler'] ) || ! is_callable( $tool['handler'] ) ) {
				unset( $this->tools[ $name ] );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging.
				error_log( 'BricksMCP: Rejected invalid tool from bricks_mcp_tools filter: ' . sanitize_text_field( $name ) );
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

		try {
			$result = call_user_func( $tool['handler'], $arguments );

			if ( is_wp_error( $result ) ) {
				// Intercept confirmation-required errors to generate a token.
				if ( 'bricks_mcp_confirm_required' === $result->get_error_code() ) {
					return $this->create_confirmation_response( $name, $arguments, $result->get_error_message() );
				}
				return Response::tool_error( $result );
			}

			// Set prerequisite flags for gate tracking.
			if ( 'global_class' === $name && ( $arguments['action'] ?? '' ) === 'list' ) {
				PrerequisiteGateService::set_flag( 'classes' );
			} elseif ( 'global_variable' === $name && ( $arguments['action'] ?? '' ) === 'list' ) {
				PrerequisiteGateService::set_flag( 'variables' );
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
	 * @return string|null Tier name ('direct', 'instructed', 'full') or null if not gated.
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

		// Strip the old "Set confirm: true to proceed" instruction.
		$clean_description = preg_replace(
			'/\s*Set confirm: true to proceed[^.]*\.?/',
			'',
			$description
		);
		$clean_description = rtrim( $clean_description, '. ' );

		$message = sprintf(
			/* translators: 1: Action description, 2: Confirmation token */
			__( "%1\$s\n\nTo proceed, call confirm_destructive_action with token: %2\$s\nThis token expires in 2 minutes and can only be used once.", 'bricks-mcp' ),
			$clean_description,
			$token
		);

		$error = new \WP_Error(
			'bricks_mcp_confirm_required',
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
		// No public tools remaining after get_builder_guide removal.
		// Kept as extension point for future public tools.
		$public_tools = array();

		if ( in_array( $tool_name, $public_tools, true ) ) {
			return null;
		}

		$read_tools = array(
			'get_site_info',
			'metabox',
		);

		if ( in_array( $tool_name, $read_tools, true ) ) {
			return 'read';
		}

		// All other tools (bricks, page, element, template, etc.) require manage_options.
		return 'manage_options';
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
		$token = sanitize_text_field( $args['token'] ?? '' );

		$pending = $this->pending_action_service->validate_and_consume( $token );
		if ( false === $pending ) {
			return new \WP_Error(
				'invalid_token',
				__( 'Invalid or expired confirmation token. Request the destructive action again to get a new token.', 'bricks-mcp' )
			);
		}

		$tool_name = $pending['tool_name'];
		$tool_args = $pending['args'];

		if ( ! isset( $this->tools[ $tool_name ] ) ) {
			return new \WP_Error(
				'tool_not_found',
				/* translators: %s: Tool name */
				sprintf( __( 'The original tool "%s" is no longer available.', 'bricks-mcp' ), $tool_name )
			);
		}

		// Re-inject confirm and call the handler directly.
		// This bypasses execute_tool() which would strip confirm again.
		$tool_args['confirm'] = true;

		return call_user_func( $this->tools[ $tool_name ]['handler'], $tool_args );
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
		$categories = get_option( 'bricks_global_variables_categories', [] );
		$variables  = get_option( 'bricks_global_variables', [] );

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

		// Append color palette names.
		$palettes = get_option( 'bricks_color_palette', [] );
		if ( is_array( $palettes ) ) {
			$palette_summary = [];
			foreach ( $palettes as $palette ) {
				$colors = [];
				foreach ( ( $palette['colors'] ?? [] ) as $color ) {
					if ( ! empty( $color['raw'] ) ) {
						$colors[] = $color['raw'];
					}
				}
				if ( ! empty( $colors ) ) {
					$palette_summary[ $palette['name'] ?? 'unnamed' ] = $colors;
				}
			}
			if ( ! empty( $palette_summary ) ) {
				$info['color_palette'] = $palette_summary;
			}
		}

		// Pages summary: brief overview of all Bricks-enabled pages.
		$pages_query = new \WP_Query( [
			'post_type'      => array_values( get_post_types( [ 'public' => true ] ) ),
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'meta_query'     => [
				[
					'key'     => '_bricks_page_content_2',
					'compare' => 'EXISTS',
				],
			],
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		$pages_summary = [];
		foreach ( $pages_query->posts as $pid ) {
			$elements = get_post_meta( (int) $pid, '_bricks_page_content_2', true );
			$elements = is_array( $elements ) ? $elements : [];
			$section_count = 0;
			$section_types = [];
			foreach ( $elements as $el ) {
				if ( ( $el['name'] ?? '' ) === 'section' && (string) ( $el['parent'] ?? '0' ) === '0' ) {
					$section_count++;
					$label = $el['settings']['label'] ?? $el['label'] ?? '';
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
			$info['ai_notes']      = array_map( fn( array $n ) => $n['text'] ?? '', $ai_notes );
			$info['ai_notes_note'] = 'These are persistent instructions from the site owner. You MUST follow them.';
		}


		PrerequisiteGateService::set_flag( 'site_info' );

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

		// Bricks consolidated tool.
		$this->register_tool(
			'bricks',
			__( "Manage Bricks Builder settings, schema, and AI notes.\n\nActions: enable, disable, get_settings, get_breakpoints, get_element_schemas, get_dynamic_tags, get_query_types, get_form_schema, get_interaction_schema, get_component_schema, get_popup_schema, get_filter_schema, get_condition_schema, get_global_queries, set_global_query, delete_global_query, get_notes, add_note, delete_note.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'       => array(
						'type'        => 'string',
						'enum'        => array( 'enable', 'disable', 'get_settings', 'get_breakpoints', 'get_element_schemas', 'get_dynamic_tags', 'get_query_types', 'get_form_schema', 'get_interaction_schema', 'get_component_schema', 'get_popup_schema', 'get_filter_schema', 'get_condition_schema', 'get_global_queries', 'set_global_query', 'delete_global_query', 'get_notes', 'add_note', 'delete_note' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'post_id'      => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID (enable, disable: required)', 'bricks-mcp' ),
					),
					'category'     => array(
						'type'        => 'string',
						'description' => __( 'Filter settings by category (get_settings: optional, e.g. "general", "performance", "builder", "templates", "integrations", "woocommerce"), filter element schemas by category (get_element_schemas: optional, e.g. "layout", "basic", "general", "media", "wordpress"), or group global query by category (set_global_query: optional)', 'bricks-mcp' ),
					),
					'element'      => array(
						'type'        => 'string',
						'description' => __( "Specific element type name (get_element_schemas: optional, e.g. 'heading')", 'bricks-mcp' ),
					),
					'catalog_only' => array(
						'type'        => 'boolean',
						'description' => __( 'Return only element names, labels, and categories without full schemas (get_element_schemas: optional)', 'bricks-mcp' ),
					),
					'group'        => array(
						'type'        => 'string',
						'description' => __( 'Filter dynamic tags by group name (get_dynamic_tags: optional, e.g. "Post", "Terms", "User")', 'bricks-mcp' ),
					),
					'query_id'     => array(
						'type'        => 'string',
						'description' => __( 'Global query ID (set_global_query: optional for update; delete_global_query: required)', 'bricks-mcp' ),
					),
					'name'         => array(
						'type'        => 'string',
						'description' => __( 'Global query name (set_global_query: required)', 'bricks-mcp' ),
					),
					'settings'     => array(
						'type'        => 'object',
						'description' => __( 'Query settings object — same structure as element query settings (set_global_query: required)', 'bricks-mcp' ),
					),
					'offset'       => array(
						'type'        => 'integer',
						'description' => __( 'Skip first N schemas (get_element_schemas: optional, default 0)', 'bricks-mcp' ),
					),
					'limit'        => array(
						'type'        => 'integer',
						'description' => __( 'Max schemas to return (get_element_schemas: optional, default all)', 'bricks-mcp' ),
					),
					'text'         => array(
						'type'        => 'string',
						'description' => __( 'Note text (add_note: required)', 'bricks-mcp' ),
					),
					'note_id'      => array(
						'type'        => 'string',
						'description' => __( 'Note ID to delete (delete_note: required)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this->bricks_tool_handler, 'handle' )
		);

		// Page consolidated tool (replaces list_pages, search_pages, get_bricks_content, create_bricks_page, update_bricks_content, update_page, delete_page, duplicate_page, get_page_settings, update_page_settings + SEO).
		$this->register_tool(
			'page',
			__( "Manage pages and Bricks content.\n\nActions: list, search, get (views: detail/summary/context/describe), create, update_content, append_content (add without replacing), import_clipboard, update_meta, delete, duplicate, get_settings, update_settings, get_seo, update_seo, snapshot, restore, list_snapshots.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'              => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'search', 'get', 'create', 'update_content', 'append_content', 'import_clipboard', 'update_meta', 'delete', 'duplicate', 'get_settings', 'update_settings', 'get_seo', 'update_seo', 'snapshot', 'restore', 'list_snapshots' ),
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
						'description' => __( 'Max elements to return in detail view (get: optional, default all). Use with offset for pagination.', 'bricks-mcp' ),
					),
					'element_ids'         => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Filter to specific element IDs in detail view (get: optional). Returns only these elements and their settings.', 'bricks-mcp' ),
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
					'confirm'             => array(
						'type'        => 'boolean',
						'description' => __( 'Deprecated. Destructive actions now require token-based confirmation via the confirm_destructive_action tool.', 'bricks-mcp' ),
					),
					'force'               => array(
						'type'        => 'boolean',
						'description' => __( 'When true, permanently delete instead of moving to trash (delete action only).', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_page' )
		);

		// Element consolidated tool (replaces add_element, update_element, remove_element).
		$this->register_tool(
			'element',
			__( "Manage individual Bricks elements on a page.\n\nActions: add, update, remove (optional cascade), get_conditions, set_conditions, move, bulk_update, bulk_add (supports nested tree format), duplicate, find.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'           => array(
						'type'        => 'string',
						'enum'        => array( 'add', 'update', 'remove', 'get_conditions', 'set_conditions', 'move', 'bulk_update', 'bulk_add', 'duplicate', 'find' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'post_id'          => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID (all actions: required)', 'bricks-mcp' ),
					),
					'element'          => array(
						'type'        => 'object',
						'description' => __( 'Element object with name and optional settings (add: used as source for element data)', 'bricks-mcp' ),
					),
					'name'             => array(
						'type'        => 'string',
						'description' => __( "Bricks element type name (add: required, e.g. 'heading', 'container', 'section')", 'bricks-mcp' ),
					),
					'element_id'       => array(
						'type'        => 'string',
						'description' => __( 'Element ID (update, remove, move: required; 6-char alphanumeric)', 'bricks-mcp' ),
					),
					'settings'         => array(
						'type'        => 'object',
						'description' => __( 'Element settings (add: optional, update: required)', 'bricks-mcp' ),
					),
					'position'         => array(
						'type'        => 'integer',
						'description' => __( "Position in parent's children array (add, move: 0-indexed, omit to append)", 'bricks-mcp' ),
					),
					'parent_id'        => array(
						'type'        => 'string',
						'description' => __( "Parent element ID (add: optional, use '0' for root level)", 'bricks-mcp' ),
					),
					'conditions'       => array(
						'type'        => 'array',
						'description' => __( 'Condition sets array — array of arrays of condition objects with key/compare/value (set_conditions: required)', 'bricks-mcp' ),
					),
					'target_parent_id' => array(
						'type'        => 'string',
						'description' => __( "Target parent element ID for move (move: optional; use '0' for root level, omit to reorder within current parent)", 'bricks-mcp' ),
					),
					'cascade'          => array(
						'type'        => 'boolean',
						'description' => __( 'When true, remove element AND all descendants. When false (default), re-parent children to grandparent. (remove: optional)', 'bricks-mcp' ),
					),
					'updates'          => array(
						'type'        => 'array',
						'description' => __( 'Array of {element_id, settings} objects (bulk_update: required; max 50 items)', 'bricks-mcp' ),
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'element_id' => array(
									'type'        => 'string',
									'description' => __( 'Element ID to update', 'bricks-mcp' ),
								),
								'settings'   => array(
									'type'        => 'object',
									'description' => __( 'Settings to merge', 'bricks-mcp' ),
								),
							),
						),
					),
					'elements'         => array(
						'type'        => 'array',
						'description' => __( 'Array of element objects to add (bulk_add: required; max 50 top-level). Supports nested tree, parent-ref, and flat formats.', 'bricks-mcp' ),
					),
					'type'             => array(
						'type'        => 'string',
						'description' => __( 'Filter by element type name (find: optional, e.g. "heading", "button", "section")', 'bricks-mcp' ),
					),
					'class_id'         => array(
						'type'        => 'string',
						'description' => __( 'Filter by global class ID (find: optional)', 'bricks-mcp' ),
					),
					'has_setting'      => array(
						'type'        => 'string',
						'description' => __( 'Filter by setting key existence (find: optional, e.g. "_cssCustom", "_background")', 'bricks-mcp' ),
					),
					'text_contains'    => array(
						'type'        => 'string',
						'description' => __( 'Filter by text content containing string (find: optional, case-insensitive)', 'bricks-mcp' ),
					),
					'confirm'          => array(
						'type'        => 'boolean',
						'description' => __( 'Deprecated. Destructive actions now require token-based confirmation via the confirm_destructive_action tool.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_element' )
		);

		// Template consolidated tool (replaces list_templates, get_template_content, create_template, update_template, delete_template, duplicate_template).
		$this->register_tool(
			'template',
			__( "Manage Bricks templates (headers, footers, sections, popups, etc.).\n\nActions: list, get, create, update, delete, duplicate, get_popup_settings, set_popup_settings, export, import, import_url.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'      => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'get', 'create', 'update', 'delete', 'duplicate', 'get_popup_settings', 'set_popup_settings', 'export', 'import', 'import_url' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'template_id' => array(
						'type'        => 'integer',
						'description' => __( 'Template post ID (get, update, delete, duplicate, export: required)', 'bricks-mcp' ),
					),
					'title'       => array(
						'type'        => 'string',
						'description' => __( 'Template title (create: required; update, duplicate: optional)', 'bricks-mcp' ),
					),
					'type'        => array(
						'type'        => 'string',
						'enum'        => array( 'header', 'footer', 'archive', 'search', 'error', 'content', 'section', 'popup', 'password_protection' ),
						'description' => __( 'Template type (create: required; list, update: optional)', 'bricks-mcp' ),
					),
					'status'      => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private', 'trash', 'any' ),
						'description' => __( 'Post status (list: filter; create/update: new status)', 'bricks-mcp' ),
					),
					'elements'    => array(
						'type'        => 'array',
						'description' => __( 'Element content array (create: optional)', 'bricks-mcp' ),
					),
					'tags'        => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Array of template_tag taxonomy slugs (list: filter; create, update: assign)', 'bricks-mcp' ),
					),
					'bundles'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Array of template_bundle taxonomy slugs (list: filter; create, update: assign)', 'bricks-mcp' ),
					),
					'tag'         => array(
						'type'        => 'string',
						'description' => __( 'Filter by template_tag taxonomy slug (list: optional)', 'bricks-mcp' ),
					),
					'bundle'      => array(
						'type'        => 'string',
						'description' => __( 'Filter by template_bundle taxonomy slug (list: optional)', 'bricks-mcp' ),
					),
					'post_type'   => array(
						'type'        => 'string',
						'description' => __( 'Post type for the template (create: optional)', 'bricks-mcp' ),
					),
					'conditions'  => array(
						'type'        => 'array',
						'description' => __( 'Array of Bricks condition objects (create: optional)', 'bricks-mcp' ),
					),
					'settings'    => array(
						'type'        => 'object',
						'description' => __( 'Popup settings key-value pairs (set_popup_settings: required). Null value deletes key. Use bricks:get_popup_schema for valid keys.', 'bricks-mcp' ),
					),
					'include_classes' => array(
						'type'        => 'boolean',
						'description' => __( 'Include used global classes in export (export: optional, default false)', 'bricks-mcp' ),
					),
					'template_data' => array(
						'type'        => 'object',
						'description' => __( 'Template JSON data to import (import: required). Must contain title (string) and content (array of Bricks elements). Optional: templateType, pageSettings, templateSettings, globalClasses.', 'bricks-mcp' ),
					),
					'url'         => array(
						'type'        => 'string',
						'description' => __( 'Remote URL to fetch template JSON from (import_url: required)', 'bricks-mcp' ),
					),
					'confirm'     => array(
						'type'        => 'boolean',
						'description' => __( 'Deprecated. Destructive actions now require token-based confirmation via the confirm_destructive_action tool.', 'bricks-mcp' ),
					),
					'force'       => array(
						'type'        => 'boolean',
						'description' => __( 'When true, permanently delete instead of moving to trash (delete action only).', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_template' )
		);

		// Template condition consolidated tool (replaces get_condition_types, set_template_conditions, resolve_templates).
		$this->register_tool(
			'template_condition',
			__( "Manage template display conditions — control which templates apply where.\n\nActions: get_types, set, resolve.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'      => array(
						'type'        => 'string',
						'enum'        => array( 'get_types', 'set', 'resolve' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'template_id' => array(
						'type'        => 'integer',
						'description' => __( 'Template post ID (set: required)', 'bricks-mcp' ),
					),
					'conditions'  => array(
						'type'        => 'array',
						'description' => __( 'Array of condition objects with "main" key and type-specific fields. Pass empty array to remove all conditions. (set: required)', 'bricks-mcp' ),
					),
					'post_id'     => array(
						'type'        => 'integer',
						'description' => __( 'Post ID to resolve templates for (resolve: optional)', 'bricks-mcp' ),
					),
					'post_type'   => array(
						'type'        => 'string',
						'description' => __( 'Post type context for resolution (resolve: optional)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_template_condition' )
		);

		// Template taxonomy consolidated tool (replaces list_template_tags, list_template_bundles, create_template_tag, create_template_bundle, delete_template_tag, delete_template_bundle).
		$this->register_tool(
			'template_taxonomy',
			__( "Manage template tags and bundles for organizing templates.\n\nActions: list_tags, list_bundles, create_tag, create_bundle, delete_tag, delete_bundle.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'  => array(
						'type'        => 'string',
						'enum'        => array( 'list_tags', 'list_bundles', 'create_tag', 'create_bundle', 'delete_tag', 'delete_bundle' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'name'    => array(
						'type'        => 'string',
						'description' => __( 'Tag or bundle name (create_tag, create_bundle: required)', 'bricks-mcp' ),
					),
					'term_id' => array(
						'type'        => 'integer',
						'description' => __( 'Term ID to delete (delete_tag, delete_bundle: required)', 'bricks-mcp' ),
					),
					'confirm' => array(
						'type'        => 'boolean',
						'description' => __( 'Deprecated. Destructive actions now require token-based confirmation via the confirm_destructive_action tool.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_template_taxonomy' )
		);

		// Global class consolidated tool (replaces get_global_classes, create_global_class, update_global_class, delete_global_class, apply_global_class, remove_global_class, batch_create_global_classes, batch_delete_global_classes, import_classes_from_css, list_global_class_categories, create_global_class_category, delete_global_class_category).
		$this->register_tool(
			'global_class',
			__( "Manage Bricks global CSS classes.\n\nActions: list, create, update, delete, apply (to elements), remove (from elements), batch_create, batch_delete, import_css, list_categories, create_category, delete_category, export, import_json.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'         => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'create', 'update', 'delete', 'apply', 'remove', 'batch_create', 'batch_delete', 'import_css', 'list_categories', 'create_category', 'delete_category', 'export', 'import_json' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'class_name'     => array(
						'type'        => 'string',
						'description' => __( 'CSS class name (update, delete, apply, remove: required; list filter: optional)', 'bricks-mcp' ),
					),
					'name'           => array(
						'type'        => 'string',
						'description' => __( 'New class name (create: required; update: optional for rename)', 'bricks-mcp' ),
					),
					'styles'         => array(
						'type'        => 'object',
						'description' => __( 'Bricks composite key styles: _padding, _background, _margin:hover, etc. (create, update: optional)', 'bricks-mcp' ),
					),
					'color'          => array(
						'type'        => 'string',
						'description' => __( 'Visual indicator color in Bricks editor, hex format like #3498db (create, update: optional)', 'bricks-mcp' ),
					),
					'category'       => array(
						'type'        => 'string',
						'description' => __( 'Category ID (create, update: assign; list: filter by category)', 'bricks-mcp' ),
					),
					'replace_styles' => array(
						'type'        => 'boolean',
						'description' => __( 'If true, replace entire styles object instead of merging (update: default false)', 'bricks-mcp' ),
					),
					'post_id'        => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID containing the elements (apply, remove: required)', 'bricks-mcp' ),
					),
					'element_ids'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Array of element IDs (apply, remove: required)', 'bricks-mcp' ),
					),
					'classes'        => array(
						'type'        => 'array',
						'description' => __( 'Array of class objects for batch_create, or array of class name strings for batch_delete', 'bricks-mcp' ),
					),
					'css'            => array(
						'type'        => 'string',
						'description' => __( 'Raw CSS string to parse and import as global classes (import_css: required)', 'bricks-mcp' ),
					),
					'category_name'  => array(
						'type'        => 'string',
						'description' => __( 'Category name (create_category: required)', 'bricks-mcp' ),
					),
					'category_id'    => array(
						'type'        => 'string',
						'description' => __( 'Category ID to delete (delete_category: required)', 'bricks-mcp' ),
					),
					'search'         => array(
						'type'        => 'string',
						'description' => __( 'Filter classes by partial name match (list: optional)', 'bricks-mcp' ),
					),
					'offset'         => array(
						'type'        => 'integer',
						'description' => __( 'Skip first N classes (list: optional, default 0)', 'bricks-mcp' ),
					),
					'limit'          => array(
						'type'        => 'integer',
						'description' => __( 'Max classes to return (list: optional, default all)', 'bricks-mcp' ),
					),
					'classes_data'   => array(
						'type'        => 'object',
						'description' => __( 'Global classes JSON data to import (import_json: required). Array of class objects with "name" key, or {classes: [...], categories: [...]}.', 'bricks-mcp' ),
					),
					'confirm'        => array(
						'type'        => 'boolean',
						'description' => __( 'Deprecated. Destructive actions now require token-based confirmation via the confirm_destructive_action tool.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_global_class' )
		);

		// Theme style consolidated tool (replaces list_theme_styles, get_theme_style, create_theme_style, update_theme_style, delete_theme_style).
		$this->register_tool(
			'theme_style',
			__( "Manage Bricks theme styles (site-wide typography, colors, spacing).\n\nActions: list, get, create, update, delete.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'          => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'get', 'create', 'update', 'delete' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'style_id'        => array(
						'type'        => 'string',
						'description' => __( 'Theme style ID (get, update, delete: required)', 'bricks-mcp' ),
					),
					'name'            => array(
						'type'        => 'string',
						'description' => __( 'Style label/name (create: required; update: optional)', 'bricks-mcp' ),
					),
					'styles'          => array(
						'type'        => 'object',
						'description' => __( 'Settings organized by group: typography, links, colors, general, contextualSpacing, css, heading, button, section, container, block, div, text, form, image, navMenu, accordion, alert, carousel, divider, iconBox, imageGallery, list, iconList, postContent, postTitle, tabs, video, wordpress, woocommerceButton. (create, update: optional)', 'bricks-mcp' ),
					),
					'conditions'      => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'object' ),
						'description' => __( 'Array of condition objects with "main" key (create, update: optional)', 'bricks-mcp' ),
					),
					'active'          => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the style should be active (update: optional)', 'bricks-mcp' ),
					),
					'replace_section' => array(
						'type'        => 'boolean',
						'description' => __( 'If true, fully replace each provided settings group instead of merging (update: default false)', 'bricks-mcp' ),
					),
					'hard_delete'     => array(
						'type'        => 'boolean',
						'description' => __( 'If true, permanently delete the style; if false (default), only remove conditions to deactivate (delete: optional)', 'bricks-mcp' ),
					),
					'confirm'         => array(
						'type'        => 'boolean',
						'description' => __( 'Deprecated. Destructive actions now require token-based confirmation via the confirm_destructive_action tool.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_theme_style' )
		);

		// Typography scale consolidated tool (replaces get_typography_scales, create_typography_scale, update_typography_scale, delete_typography_scale).
		$this->register_tool(
			'typography_scale',
			__( "Manage Bricks typography scales with CSS variable generation.\n\nActions: list, create, update, delete.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'          => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'create', 'update', 'delete' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'scale_id'        => array(
						'type'        => 'string',
						'description' => __( 'Scale category ID (update, delete: required)', 'bricks-mcp' ),
					),
					'name'            => array(
						'type'        => 'string',
						'description' => __( 'Scale name (create: required; update: optional)', 'bricks-mcp' ),
					),
					'settings'        => array(
						'type'        => 'object',
						'description' => __( 'Typography scale settings including prefix, steps, and utility_classes (create: required; update: optional)', 'bricks-mcp' ),
					),
					'prefix'          => array(
						'type'        => 'string',
						'description' => __( 'CSS variable prefix starting with -- (e.g., "--text-"). Used in create if not inside settings.', 'bricks-mcp' ),
					),
					'steps'           => array(
						'type'        => 'array',
						'description' => __( 'Array of scale steps, each with name and value (create: required if not inside settings)', 'bricks-mcp' ),
					),
					'utility_classes' => array(
						'type'        => 'array',
						'description' => __( 'Utility class definitions (create, update: optional)', 'bricks-mcp' ),
					),
					'confirm'         => array(
						'type'        => 'boolean',
						'description' => __( 'Deprecated. Destructive actions now require token-based confirmation via the confirm_destructive_action tool.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_typography_scale' )
		);

		// Color palette consolidated tool (replaces list_color_palettes, create_color_palette, update_color_palette, delete_color_palette, add_color_to_palette, update_color_in_palette, delete_color_from_palette).
		$this->register_tool(
			'color_palette',
			__( "Manage Bricks color palettes and individual colors.\n\nActions: list, create, update, delete, add_color, update_color, delete_color.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'     => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'create', 'update', 'delete', 'add_color', 'update_color', 'delete_color' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'palette_id' => array(
						'type'        => 'string',
						'description' => __( 'Palette ID (update, delete, add_color, update_color, delete_color: required)', 'bricks-mcp' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'Palette name (create: required; update: optional)', 'bricks-mcp' ),
					),
					'colors'     => array(
						'type'        => 'array',
						'description' => __( 'Initial colors for palette (create: optional)', 'bricks-mcp' ),
					),
					'color_id'   => array(
						'type'        => 'string',
						'description' => __( 'Color ID (update_color, delete_color: required)', 'bricks-mcp' ),
					),
					'color'      => array(
						'type'        => 'object',
						'description' => __( 'Color object with light (hex value), name, raw (CSS variable) fields (add_color: required; update_color: required). "hex" accepted as alias for "light"', 'bricks-mcp' ),
					),
					'position'   => array(
						'type'        => 'integer',
						'description' => __( 'Position in palette (add_color: optional)', 'bricks-mcp' ),
					),
					'confirm'    => array(
						'type'        => 'boolean',
						'description' => __( 'Deprecated. Destructive actions now require token-based confirmation via the confirm_destructive_action tool.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_color_palette' )
		);

		// Global variable consolidated tool (replaces list_global_variables, create_variable_category, update_variable_category, delete_variable_category, create_global_variable, update_global_variable, delete_global_variable, batch_create_global_variables).
		$this->register_tool(
			'global_variable',
			__( "Manage Bricks global CSS variables organized by category.\n\nActions: list, create_category, update_category, delete_category, create, update, delete, batch_create, batch_delete, search.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'        => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'create_category', 'update_category', 'delete_category', 'create', 'update', 'delete', 'batch_create', 'batch_delete', 'search' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'category_id'   => array(
						'type'        => 'string',
						'description' => __( 'Category ID (update_category, delete_category: required; create: optional; search: optional filter)', 'bricks-mcp' ),
					),
					'category_name' => array(
						'type'        => 'string',
						'description' => __( 'Category name (create_category: required; update_category: required)', 'bricks-mcp' ),
					),
					'variable_id'   => array(
						'type'        => 'string',
						'description' => __( 'Variable ID (update, delete: required)', 'bricks-mcp' ),
					),
					'name'          => array(
						'type'        => 'string',
						'description' => __( 'Variable name (create: required; update: optional)', 'bricks-mcp' ),
					),
					'value'         => array(
						'type'        => 'string',
						'description' => __( 'CSS value (create: required; update: optional)', 'bricks-mcp' ),
					),
					'category'      => array(
						'type'        => 'string',
						'description' => __( 'Category ID for variable assignment (create: optional)', 'bricks-mcp' ),
					),
					'variables'     => array(
						'type'        => 'array',
						'description' => __( 'Array of {name, value} variable objects (batch_create: required)', 'bricks-mcp' ),
					),
					'variable_ids'  => array(
						'type'        => 'array',
						'description' => __( 'Array of variable ID strings (batch_delete: required; max 50)', 'bricks-mcp' ),
						'items'       => array( 'type' => 'string' ),
					),
					'query'         => array(
						'type'        => 'string',
						'description' => __( 'Name substring to search for (search: optional, case-insensitive)', 'bricks-mcp' ),
					),
					'value_query'   => array(
						'type'        => 'string',
						'description' => __( 'Value substring to search for (search: optional, case-insensitive)', 'bricks-mcp' ),
					),
					'confirm'       => array(
						'type'        => 'boolean',
						'description' => __( 'Deprecated. Destructive actions now require token-based confirmation via the confirm_destructive_action tool.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_global_variable' )
		);

		// Media consolidated tool (replaces search_unsplash, sideload_image, get_media_library, set_featured_image, remove_featured_image, get_image_element_settings).
		$this->register_tool(
			'media',
			__( "Manage images and media library.\n\nActions: search_unsplash, sideload (URL to library), list, set_featured, remove_featured, get_image_settings.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'        => array(
						'type'        => 'string',
						'enum'        => array( 'search_unsplash', 'sideload', 'list', 'set_featured', 'remove_featured', 'get_image_settings' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'query'         => array(
						'type'        => 'string',
						'description' => __( 'Search query for Unsplash photos (search_unsplash: required)', 'bricks-mcp' ),
					),
					'url'           => array(
						'type'        => 'string',
						'description' => __( 'Image URL to download (sideload: required)', 'bricks-mcp' ),
					),
					'filename'      => array(
						'type'        => 'string',
						'description' => __( 'Filename for sideloaded image (sideload: optional)', 'bricks-mcp' ),
					),
					'alt_text'      => array(
						'type'        => 'string',
						'description' => __( 'Alt text for sideloaded image (sideload: optional)', 'bricks-mcp' ),
					),
					'post_id'       => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID (set_featured, remove_featured: required)', 'bricks-mcp' ),
					),
					'attachment_id' => array(
						'type'        => 'integer',
						'description' => __( 'Attachment ID from media library (set_featured: required; get_image_settings: optional)', 'bricks-mcp' ),
					),
					'image_size'    => array(
						'type'        => 'string',
						'description' => __( 'WordPress image size (get_image_settings: optional, e.g. full, large, medium)', 'bricks-mcp' ),
					),
					'per_page'      => array(
						'type'        => 'integer',
						'description' => __( 'Results per page (search_unsplash, list: optional)', 'bricks-mcp' ),
					),
					'page'          => array(
						'type'        => 'integer',
						'description' => __( 'Page number for pagination (list: optional)', 'bricks-mcp' ),
					),
					'mime_type'     => array(
						'type'        => 'string',
						'description' => __( "MIME type filter (list: optional, e.g. 'image', 'image/jpeg')", 'bricks-mcp' ),
					),
					'target'        => array(
						'type'        => 'string',
						'enum'        => array( 'image', 'background', 'gallery' ),
						'description' => __( 'Image usage target (get_image_settings: required)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this->media_handler, 'handle' )
		);

		// Menu consolidated tool (replaces create_menu, update_menu, delete_menu, get_menu, list_menus, set_menu_items, assign_menu, unassign_menu, list_menu_locations).
		$this->register_tool(
			'menu',
			__( "Manage WordPress navigation menus.\n\nActions: list, get, create, update, delete, set_items, assign (to location), unassign, list_locations.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'   => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'get', 'create', 'update', 'delete', 'set_items', 'assign', 'unassign', 'list_locations' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'menu_id'  => array(
						'type'        => 'integer',
						'description' => __( 'Menu ID (get, update, delete, set_items, assign: required)', 'bricks-mcp' ),
					),
					'name'     => array(
						'type'        => 'string',
						'description' => __( 'Menu name (create: required; update: required)', 'bricks-mcp' ),
					),
					'items'    => array(
						'type'        => 'array',
						'description' => __( 'Array of menu item objects as nested tree (set_items: required)', 'bricks-mcp' ),
					),
					'location' => array(
						'type'        => 'string',
						'description' => __( 'Theme menu location slug (assign: required; unassign: required)', 'bricks-mcp' ),
					),
					'confirm'  => array(
						'type'        => 'boolean',
						'description' => __( 'Deprecated. Destructive actions now require token-based confirmation via the confirm_destructive_action tool.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_menu' )
		);

		// Component consolidated tool (component definition CRUD + instance operations).
		$this->register_tool(
			'component',
			__( "Manage Bricks Components — reusable element trees with properties and slots.\n\nActions: list, get, create, update, delete, instantiate (place on page), update_properties, fill_slot.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'        => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'get', 'create', 'update', 'delete', 'instantiate', 'update_properties', 'fill_slot' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'component_id'  => array(
						'type'        => 'string',
						'description' => __( 'Component ID — 6-char alphanumeric (get, update, delete, instantiate: required)', 'bricks-mcp' ),
					),
					'label'         => array(
						'type'        => 'string',
						'description' => __( 'Component display name (create: required; update: optional)', 'bricks-mcp' ),
					),
					'category'      => array(
						'type'        => 'string',
						'description' => __( 'Category name for grouping (create/update: optional; list: filter)', 'bricks-mcp' ),
					),
					'description'   => array(
						'type'        => 'string',
						'description' => __( 'Component description (create/update: optional)', 'bricks-mcp' ),
					),
					'elements'      => array(
						'type'        => 'array',
						'description' => __( 'Flat element array — same structure as page content (create: required; update: optional). Root element ID will be auto-set to match component ID.', 'bricks-mcp' ),
					),
					'properties'    => array(
						'type'        => 'array',
						'description' => __( 'Property definitions array (create/update: optional) or property values object (instantiate/update_properties: set instance values). Each definition: {id, name, type, default, description, connections}', 'bricks-mcp' ),
					),
					'post_id'       => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID (instantiate, update_properties, fill_slot: required)', 'bricks-mcp' ),
					),
					'parent_id'     => array(
						'type'        => 'string',
						'description' => __( "Parent element ID for instance placement (instantiate: optional, default '0' for root)", 'bricks-mcp' ),
					),
					'position'      => array(
						'type'        => 'integer',
						'description' => __( "Position in parent's children array (instantiate: 0-indexed, omit to append)", 'bricks-mcp' ),
					),
					'instance_id'   => array(
						'type'        => 'string',
						'description' => __( 'Instance element ID — 6-char alphanumeric (update_properties, fill_slot: required)', 'bricks-mcp' ),
					),
					'slot_id'       => array(
						'type'        => 'string',
						'description' => __( 'Slot element ID from the component definition (fill_slot: required)', 'bricks-mcp' ),
					),
					'slot_elements' => array(
						'type'        => 'array',
						'description' => __( 'Flat element array to fill into the slot (fill_slot: required)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_component' )
		);

		// WooCommerce consolidated tool (status, elements, dynamic tags, template scaffolding).
		$this->register_tool(
			'woocommerce',
			__( "WooCommerce builder tools. Requires WooCommerce active.\n\nActions: status, get_elements, get_dynamic_tags, scaffold_template, scaffold_store (create all WC templates).", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'        => array(
						'type'        => 'string',
						'enum'        => array( 'status', 'get_elements', 'get_dynamic_tags', 'scaffold_template', 'scaffold_store' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'category'      => array(
						'type'        => 'string',
						'description' => __( 'Filter category (get_elements: product, cart, checkout, account, archive, utility; get_dynamic_tags: product_price, product_display, product_info, cart, order, post_compatible)', 'bricks-mcp' ),
					),
					'template_type' => array(
						'type'        => 'string',
						'enum'        => array( 'wc_product', 'wc_archive', 'wc_cart', 'wc_cart_empty', 'wc_checkout', 'wc_account_form', 'wc_account_page', 'wc_thankyou' ),
						'description' => __( 'WooCommerce template type (scaffold_template: required)', 'bricks-mcp' ),
					),
					'title'         => array(
						'type'        => 'string',
						'description' => __( 'Custom template title (scaffold_template: optional, defaults to human-readable name)', 'bricks-mcp' ),
					),
					'status'        => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft' ),
						'description' => __( 'Template post status (scaffold_template: optional, default publish)', 'bricks-mcp' ),
					),
					'types'         => array(
						'type'        => 'array',
						'description' => __( 'Specific template types to scaffold (scaffold_store: optional, defaults to all 8 types)', 'bricks-mcp' ),
					),
					'skip_existing' => array(
						'type'        => 'boolean',
						'description' => __( 'Skip types that already have a template (scaffold_store: optional, default true)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_woocommerce' )
		);

		// Font management consolidated tool.
		$this->register_tool(
			'font',
			__( "Manage Bricks font settings (Google Fonts, Adobe Fonts, webfont loading).\n\nActions: get_status, get_adobe_fonts, update_settings.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'               => array(
						'type'        => 'string',
						'enum'        => array( 'get_status', 'get_adobe_fonts', 'update_settings' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'disable_google_fonts' => array(
						'type'        => 'boolean',
						'description' => __( 'Disable Google Fonts loading (update_settings: optional)', 'bricks-mcp' ),
					),
					'webfont_loading'      => array(
						'type'        => 'string',
						'enum'        => array( 'swap', 'block', 'fallback', 'optional', 'auto', '' ),
						'description' => __( 'Font display strategy (update_settings: optional)', 'bricks-mcp' ),
					),
					'custom_fonts_preload' => array(
						'type'        => 'boolean',
						'description' => __( 'Preload custom fonts for performance (update_settings: optional)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this->font_handler, 'handle' )
		);

		// Custom code consolidated tool.
		$this->register_tool(
			'code',
			__( "Manage page-level custom CSS and JavaScript.\n\nActions: get_page_css, set_page_css, get_page_scripts, set_page_scripts (dangerous_actions required).", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'      => array(
						'type'        => 'string',
						'enum'        => array( 'get_page_css', 'set_page_css', 'get_page_scripts', 'set_page_scripts' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'post_id'     => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID (all actions: required)', 'bricks-mcp' ),
					),
					'css'         => array(
						'type'        => 'string',
						'description' => __( 'Custom CSS code (set_page_css: required). Empty string removes CSS.', 'bricks-mcp' ),
					),
					'header'      => array(
						'type'        => 'string',
						'description' => __( 'Script for document head (set_page_scripts: optional)', 'bricks-mcp' ),
					),
					'body_header' => array(
						'type'        => 'string',
						'description' => __( 'Script after opening body tag (set_page_scripts: optional)', 'bricks-mcp' ),
					),
					'body_footer' => array(
						'type'        => 'string',
						'description' => __( 'Script before closing body tag (set_page_scripts: optional)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this->code_handler, 'handle' )
		);

		// Build from schema tool — the design build pipeline.
		$this->register_tool(
			'build_from_schema',
			__( "Build Bricks page content from a declarative design schema. The schema describes structure, layout, and class intents; the MCP handles all Bricks mechanics (element IDs, settings, class resolution, normalization).\n\nAccepts a design schema with target (page_id, action), design_context (summary, mood, spacing), sections with nested structure trees, and optional patterns. Returns created elements, resolved classes, and a tree summary.\n\nUse dry_run: true to validate and preview without writing.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'schema'  => array(
						'type'        => 'object',
						'description' => __( 'Design schema object with target, design_context, sections, and optional patterns. See tool description for full format.', 'bricks-mcp' ),
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => __( 'When true, validate and resolve but do not write. Returns preview of what would be built.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'schema' ),
			),
			array( $this->build_handler, 'handle' )
		);
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

	/**
	 * Tool: Page dispatcher — routes to list, search, get, create, update_content, update_meta, delete, duplicate, get_settings, update_settings, get_seo, update_seo.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_page( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return $this->page_handler->handle( $args );
	}

	/**
	 * Tool: Element dispatcher — routes to add, update, remove.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_element( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return $this->element_handler->handle( $args );
	}

	/**
	 * Tool: Template dispatcher — routes to list, get, create, update, delete, duplicate.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_template( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return $this->template_handler->handle( $args );
	}

	/**
	 * Tool: Template condition dispatcher — routes to get_types, set, resolve.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_template_condition( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return $this->template_handler->handle_condition( $args );
	}

	/**
	 * Tool: Template taxonomy dispatcher — routes to list_tags, list_bundles, create_tag, create_bundle, delete_tag, delete_bundle.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_template_taxonomy( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return $this->template_handler->handle_taxonomy( $args );
	}

	/**
	 * Tool: Global class dispatcher — routes to list, create, update, delete, apply, remove, batch_create, batch_delete, import_css, list_categories, create_category, delete_category.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_global_class( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return $this->global_class_handler->handle( $args );
	}

	/**
	 * Tool: Theme style dispatcher — routes to list, get, create, update, delete.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_theme_style( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return $this->design_system_handler->handle_theme_style( $args );
	}

	/**
	 * Tool: Typography scale dispatcher — routes to list, create, update, delete.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_typography_scale( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return $this->design_system_handler->handle_typography_scale( $args );
	}

	/**
	 * Tool: Color palette dispatcher.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_color_palette( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return $this->design_system_handler->handle_color_palette( $args );
	}

	/**
	 * Tool: Global variable dispatcher.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_global_variable( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return $this->design_system_handler->handle_global_variable( $args );
	}

	/**
	 * Tool: Menu dispatcher — routes to list, get, create, update, delete, set_items, assign, unassign, list_locations.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_menu( array $args ): array|\WP_Error {
		return $this->menu_handler->handle( $args );
	}

	/**
	 * Tool: Component dispatcher — routes to list, get, create, update, delete, instantiate, update_properties, fill_slot.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_component( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return $this->component_handler->handle( $args );
	}


	/**
	 * Tool: WooCommerce builder tools.
	 *
	 * Consolidated dispatcher for WooCommerce status, element discovery,
	 * dynamic data tags, and template scaffolding.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Response data or error.
	 */
	public function tool_woocommerce( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}
		return $this->woocommerce_handler->handle( $args );
	}
}
