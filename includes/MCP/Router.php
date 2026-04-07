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
use BricksMCP\MCP\Services\ElementIdGenerator;
use BricksMCP\MCP\Services\MediaService;
use BricksMCP\MCP\Services\MenuService;
use BricksMCP\MCP\Services\SchemaGenerator;
use BricksMCP\MCP\Services\ValidationService;
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
	 * Registered tools.
	 *
	 * @var array<string, array{name: string, description: string, inputSchema: array, handler: callable}>
	 */
	private array $tools = array();

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
	 * Constructor.
	 */
	public function __construct() {
		$this->schema_generator   = new SchemaGenerator();
		$this->validation_service = new ValidationService( $this->schema_generator );
		$this->bricks_service     = new BricksService();
		$this->bricks_service->set_validation_service( $this->validation_service );
		$this->media_service = new MediaService();
		$this->menu_service       = new MenuService();
		$this->component_handler    = new Handlers\ComponentHandler( $this->bricks_service );
		$this->woocommerce_handler  = new Handlers\WooCommerceHandler( $this->bricks_service, $this->schema_generator );
		$this->schema_handler       = new Handlers\SchemaHandler( $this->schema_generator, $this->bricks_service );
		$this->menu_handler           = new Handlers\MenuHandler( $this->menu_service, \Closure::fromCallable( array( $this, 'require_bricks' ) ) );
		$this->page_handler           = new Handlers\PageHandler( $this->bricks_service, $this->validation_service );
		$this->element_handler        = new Handlers\ElementHandler( $this->bricks_service );
		$this->template_handler       = new Handlers\TemplateHandler( $this->bricks_service );
		$this->global_class_handler   = new Handlers\GlobalClassHandler( $this->bricks_service );
		$this->design_system_handler  = new Handlers\DesignSystemHandler( $this->bricks_service );

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

		// WordPress consolidated tool (replaces get_posts, get_post, get_users, get_plugins).
		$this->register_tool(
			'wordpress',
			__( "Query WordPress data.\n\nActions: get_posts, get_post, get_users, get_plugins.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'         => array(
						'type'        => 'string',
						'enum'        => array( 'get_posts', 'get_post', 'get_users', 'get_plugins' ),
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
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_wordpress' ),
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
		$this->tools[ $name ] = array(
			'name'        => $name,
			'description' => $description,
			'inputSchema' => $input_schema,
			'handler'     => $handler,
			'annotations' => $annotations,
		);
	}

	/**
	 * Get available tools in MCP format.
	 *
	 * @return array<int, array{name: string, description: string, inputSchema: array}> Tools list.
	 */
	public function get_available_tools(): array {
		$tools = array();

		foreach ( $this->tools as $tool ) {
			$entry = array(
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'inputSchema' => $tool['inputSchema'],
			);
			if ( ! empty( $tool['annotations'] ) ) {
				$entry['annotations'] = $tool['annotations'];
			}
			$tools[] = $entry;
		}

		return $tools;
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
		if ( ! isset( $this->tools[ $name ] ) ) {
			return Response::error(
				'unknown_tool',
				/* translators: %s: Tool name */
				sprintf( __( 'Unknown tool: %s', 'bricks-mcp' ), $name ),
				404
			);
		}

		$tool = $this->tools[ $name ];

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
				return Response::tool_error( $result );
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
		$public_tools = array(
			'get_builder_guide',
		);

		if ( in_array( $tool_name, $public_tools, true ) ) {
			return null;
		}

		$read_tools = array(
			'get_site_info',
		);

		if ( in_array( $tool_name, $read_tools, true ) ) {
			return 'read';
		}

		// All other tools (bricks, page, element, template, etc.) require manage_options.
		return 'manage_options';
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

		// Append child theme CSS if present.
		$child_style = get_stylesheet_directory() . '/style.css';
		if ( get_stylesheet_directory() !== get_template_directory() && file_exists( $child_style ) ) {
			$size = filesize( $child_style );
			if ( false === $size || $size <= 0 || $size > 102400 ) {
				// Skip files that are empty, unreadable, or exceed 100 KB cap.
				$css = '';
			} else {
				$css = file_get_contents( $child_style ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
			if ( is_string( $css ) && '' !== trim( $css ) ) {
				// Strip all CSS comments (theme header and any others that may contain sensitive info).
				$css = (string) preg_replace( '/\/\*[\s\S]*?\*\/\s*/', '', $css );
				$css = trim( $css );
				if ( '' !== $css ) {
					$info['child_theme_css'] = $css;
					$info['child_theme_css_note'] = 'This CSS is loaded globally. Do NOT duplicate these styles on elements — headings already get var(--h1)…var(--h6) sizes, sections get var(--padding-section), containers get var(--content-gap), etc.';
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
			$raw      = get_post_meta( (int) $pid, '_bricks_page_content_2', true );
			$elements = is_array( $raw ) ? $raw : [];

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

			$post_obj        = get_post( (int) $pid );
			$pages_summary[] = [
				'id'       => (int) $pid,
				'title'    => $post_obj ? $post_obj->post_title : '',
				'slug'     => $post_obj ? $post_obj->post_name : '',
				'sections' => $section_count,
				'elements' => count( $elements ),
				'summary'  => $section_types ? implode( ', ', $section_types ) : 'No labeled sections',
			];
		}
		$info['pages_summary'] = $pages_summary;

		// Class groups: organize global classes by component type.
		$all_classes = get_option( 'bricks_global_classes', [] );
		if ( ! is_array( $all_classes ) ) {
			$all_classes = [];
		}
		if ( ! empty( $all_classes ) ) {
			$groups = [
				'heroes'     => [],
				'sections'   => [],
				'containers' => [],
				'grids'      => [],
				'cards'      => [],
				'typography' => [],
				'navigation' => [],
				'media'      => [],
				'other'      => [],
			];

			foreach ( $all_classes as $class ) {
				$name = $class['name'] ?? '';
				if ( empty( $name ) ) {
					continue;
				}

				$n = strtolower( $name );
				if ( str_contains( $n, 'hero' ) ) {
					$groups['heroes'][] = $name;
				} elseif ( str_contains( $n, 'grid' ) || str_contains( $n, 'logos-' ) ) {
					$groups['grids'][] = $name;
				} elseif ( str_contains( $n, 'card' ) || str_contains( $n, 'profile' ) ) {
					$groups['cards'][] = $name;
				} elseif ( str_contains( $n, 'section' ) || str_contains( $n, 'cta' ) || str_contains( $n, 'contact' ) ) {
					$groups['sections'][] = $name;
				} elseif ( str_contains( $n, 'container' ) || str_contains( $n, 'wrapper' ) || str_contains( $n, 'content-wrapper' ) || str_contains( $n, 'intro' ) ) {
					$groups['containers'][] = $name;
				} elseif ( str_contains( $n, 'heading' ) || str_contains( $n, 'tagline' ) || str_contains( $n, 'lede' ) || str_contains( $n, 'text' ) ) {
					$groups['typography'][] = $name;
				} elseif ( str_contains( $n, 'header' ) || str_contains( $n, 'footer' ) || str_contains( $n, 'nav' ) || str_contains( $n, 'menu' ) || str_contains( $n, 'toggle' ) || str_contains( $n, 'subfooter' ) ) {
					$groups['navigation'][] = $name;
				} elseif ( str_contains( $n, 'media' ) || str_contains( $n, 'image' ) || str_contains( $n, 'logo' ) ) {
					$groups['media'][] = $name;
				} else {
					$groups['other'][] = $name;
				}
			}

			// Remove empty groups.
			$info['class_groups']      = array_filter( $groups, fn( $g ) => ! empty( $g ) );
			$info['class_groups_note'] = 'Global classes grouped by component type. Use global_class:list for full details with IDs and styles.';
		}

		// Design patterns: detect recurring section patterns across pages.
		$pattern_fingerprints = [];
		foreach ( $pages_query->posts as $pid ) {
			$raw      = get_post_meta( (int) $pid, '_bricks_page_content_2', true );
			$elements = is_array( $raw ) ? $raw : [];
			foreach ( $elements as $el ) {
				if ( ( $el['name'] ?? '' ) === 'section' && (string) ( $el['parent'] ?? '0' ) === '0' ) {
					// Create fingerprint from section's own classes.
					$section_classes = $el['settings']['_cssGlobalClasses'] ?? [];
					if ( empty( $section_classes ) ) {
						continue;
					}

					// Use sorted class names as fingerprint.
					$class_names_for_fp = [];
					foreach ( $section_classes as $cid ) {
						foreach ( $all_classes as $gc ) {
							if ( ( $gc['id'] ?? '' ) === $cid ) {
								$class_names_for_fp[] = $gc['name'];
								break;
							}
						}
					}
					sort( $class_names_for_fp );
					$fp = implode( '+', $class_names_for_fp );

					if ( ! isset( $pattern_fingerprints[ $fp ] ) ) {
						$pattern_fingerprints[ $fp ] = [
							'classes' => $class_names_for_fp,
							'pages'   => [],
							'label'   => $el['settings']['label'] ?? $el['label'] ?? '',
						];
					}
					$pattern_fingerprints[ $fp ]['pages'][] = (int) $pid;
				}
			}
		}

		// Only report patterns used on 2+ pages.
		$design_patterns = [];
		foreach ( $pattern_fingerprints as $fp => $data ) {
			$unique_pages = array_unique( $data['pages'] );
			if ( count( $unique_pages ) >= 2 ) {
				$name              = $data['label'] ?: implode( ' + ', $data['classes'] );
				$design_patterns[] = sprintf( '%s (used on %d pages)', $name, count( $unique_pages ) );
			}
		}
		if ( ! empty( $design_patterns ) ) {
			$info['design_patterns'] = $design_patterns;
		}

		return $info;
	}

	/**
	 * Tool: Get posts.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int, array<string, mixed>> Posts list.
	 */
	private function tool_get_posts( array $args ): array {
		$order = isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$query_args = array(
			'post_type'      => isset( $args['post_type'] ) ? sanitize_text_field( (string) $args['post_type'] ) : 'post',
			'posts_per_page' => isset( $args['posts_per_page'] ) ? min( absint( $args['posts_per_page'] ), 100 ) : 10,
			'orderby'        => isset( $args['orderby'] ) ? sanitize_text_field( (string) $args['orderby'] ) : 'date',
			'order'          => $order,
			'post_status'    => 'publish',
			's'              => isset( $args['s'] ) ? sanitize_text_field( (string) $args['s'] ) : '',
			'paged'          => isset( $args['paged'] ) ? absint( $args['paged'] ) : 1,
			'category_name'  => isset( $args['category_name'] ) ? sanitize_text_field( (string) $args['category_name'] ) : '',
			'tag'            => isset( $args['tag'] ) ? sanitize_text_field( (string) $args['tag'] ) : '',
			'author'         => isset( $args['author'] ) ? absint( $args['author'] ) : 0,
		);

		$posts = get_posts( $query_args );

		// Prime meta cache (includes thumbnail IDs) to avoid N+1 queries for get_the_post_thumbnail_url().
		update_postmeta_cache( wp_list_pluck( $posts, 'ID' ) );

		$result = array();

		foreach ( $posts as $post ) {
			$result[] = array(
				'id'             => $post->ID,
				'title'          => $post->post_title,
				'slug'           => $post->post_name,
				'status'         => $post->post_status,
				'type'           => $post->post_type,
				'date'           => $post->post_date,
				'modified'       => $post->post_modified,
				'excerpt'        => $post->post_excerpt,
				'author'         => (int) $post->post_author,
				'permalink'      => get_permalink( $post->ID ),
				'featured_image' => get_the_post_thumbnail_url( $post->ID, 'full' ),
			);
		}

		return $result;
	}

	/**
	 * Tool: Get single post.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Post data or error.
	 */
	private function tool_get_post( array $args ): array|\WP_Error {
		if ( empty( $args['id'] ) ) {
			return new \WP_Error( 'missing_id', __( 'Post ID is required. Use get_posts or list_pages to find valid post IDs.', 'bricks-mcp' ) );
		}

		$post = get_post( (int) $args['id'] );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found. Use get_posts or list_pages to find valid post IDs.', 'bricks-mcp' ),
					(int) $args['id']
				)
			);
		}

		return array(
			'id'             => $post->ID,
			'title'          => $post->post_title,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'type'           => $post->post_type,
			'date'           => $post->post_date,
			'modified'       => $post->post_modified,
			'author'         => (int) $post->post_author,
			'author_name'    => get_the_author_meta( 'display_name', $post->post_author ),
			'permalink'      => get_permalink( $post->ID ),
			'featured_image' => get_the_post_thumbnail_url( $post->ID, 'full' ),
			'categories'     => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
			'tags'           => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
		);
	}

	/**
	 * Tool: Get users.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int, array<string, mixed>> Users list.
	 */
	private function tool_get_users( array $args ): array {
		$allowed_orderby = array( 'display_name', 'registered', 'ID' );
		$allowed_order   = array( 'ASC', 'DESC' );

		$query_args = array(
			'number'  => min( isset( $args['number'] ) ? absint( $args['number'] ) : 10, 100 ),
			'role'    => isset( $args['role'] ) ? sanitize_text_field( (string) $args['role'] ) : '',
			'orderby' => isset( $args['orderby'] ) && in_array( $args['orderby'], $allowed_orderby, true )
				? $args['orderby']
				: 'display_name',
			'order'   => isset( $args['order'] ) && in_array( strtoupper( (string) $args['order'] ), $allowed_order, true )
				? strtoupper( (string) $args['order'] )
				: 'ASC',
			'paged'   => isset( $args['paged'] ) ? absint( $args['paged'] ) : 1,
		);

		$users  = get_users( $query_args );
		$result = array();

		$include_pii = ! empty( $args['include_pii'] );

		foreach ( $users as $user ) {
			$user_data = array(
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'registered'   => $user->user_registered,
				'roles'        => $user->roles,
			);

			if ( $include_pii ) {
				$user_data['login'] = $user->user_login;
				$user_data['email'] = $user->user_email;
			}

			$result[] = $user_data;
		}

		return $result;
	}

	/**
	 * Tool: Get plugins.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, array<string, mixed>> Plugins list.
	 */
	private function tool_get_plugins( array $args ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		$status         = $args['status'] ?? 'all';

		$result = array();

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$is_active = in_array( $plugin_file, $active_plugins, true );

			if ( 'active' === $status && ! $is_active ) {
				continue;
			}

			if ( 'inactive' === $status && $is_active ) {
				continue;
			}

			$result[ $plugin_file ] = array(
				'name'        => $plugin_data['Name'],
				'version'     => $plugin_data['Version'],
				'description' => $plugin_data['Description'],
				'author'      => $plugin_data['Author'],
				'is_active'   => $is_active,
			);
		}

		return $result;
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

		// Tool: get_builder_guide.
		$this->register_tool(
			'get_builder_guide',
			__( "Get the Bricks MCP builder guide — element settings reference, CSS gotchas, patterns, and workflows. Call this FIRST before building pages.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'section' => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'professional', 'settings', 'animations', 'interactions', 'dynamic_data', 'forms', 'components', 'popups', 'element_conditions', 'woocommerce', 'seo', 'custom_code', 'fonts', 'import_export', 'workflows', 'gotchas', 'workflow', 'recipes', 'connection_troubleshooting' ),
						'description' => __( 'Which section to return. Defaults to "all" which returns a table of contents. Use a specific section key (e.g. "settings", "gotchas", "workflows") for full content.', 'bricks-mcp' ),
					),
				),
			),
			array( $this, 'tool_get_builder_guide' ),
			array( 'readOnlyHint' => true )
		);

		// Bricks consolidated tool (replaces enable_bricks, disable_bricks, get_bricks_settings, get_breakpoints, get_element_schemas).
		$this->register_tool(
			'bricks',
			__( "Manage Bricks Builder settings, schema, and pattern library.\n\nActions: enable, disable, get_settings, get_breakpoints, get_element_schemas, get_dynamic_tags, get_query_types, get_form_schema, get_interaction_schema, get_component_schema, get_popup_schema, get_filter_schema, get_condition_schema, get_global_queries, set_global_query, delete_global_query, analyze_patterns, save_pattern, use_pattern, get_notes, add_note, delete_note.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'       => array(
						'type'        => 'string',
						'enum'        => array( 'enable', 'disable', 'get_settings', 'get_breakpoints', 'get_element_schemas', 'get_dynamic_tags', 'get_query_types', 'get_form_schema', 'get_interaction_schema', 'get_component_schema', 'get_popup_schema', 'get_filter_schema', 'get_condition_schema', 'get_global_queries', 'set_global_query', 'delete_global_query', 'analyze_patterns', 'save_pattern', 'use_pattern', 'get_notes', 'add_note', 'delete_note' ),
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
					'pattern_id'      => array(
						'type'        => 'string',
						'description' => __( 'Pattern ID (use_pattern: required). Get IDs from analyze_patterns or save_pattern.', 'bricks-mcp' ),
					),
					'root_element_id' => array(
						'type'        => 'string',
						'description' => __( 'Root element ID of the subtree to save as a pattern (save_pattern: required)', 'bricks-mcp' ),
					),
					'overrides'       => array(
						'type'        => 'object',
						'description' => __( 'Placeholder overrides for pattern instantiation (use_pattern: optional). Keys are placeholder names (e.g., "heading", "text"), values are replacement strings.', 'bricks-mcp' ),
					),
					'text'            => array(
						'type'        => 'string',
						'description' => __( 'Note text (add_note: required)', 'bricks-mcp' ),
					),
					'note_id'         => array(
						'type'        => 'string',
						'description' => __( 'Note ID to delete (delete_note: required)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_bricks' )
		);

		// Page consolidated tool (replaces list_pages, search_pages, get_bricks_content, create_bricks_page, update_bricks_content, update_page, delete_page, duplicate_page, get_page_settings, update_page_settings + SEO).
		$this->register_tool(
			'page',
			__( "Manage pages and Bricks content.\n\n⚠️ CRITICAL: Before using 'create', 'update_content', or 'append_content' actions, you MUST call get_site_info, global_class:list, and get_builder_guide(section='professional') first. Use _cssGlobalClasses on every element. Create global classes if none exist. Inline styles only for instance overrides.\n\nActions: list, search, get (views: detail/summary/context), create, update_content, append_content (add without replacing), import_clipboard, update_meta, delete, duplicate, get_settings, update_settings, get_seo, update_seo, snapshot, restore, list_snapshots.", 'bricks-mcp' ),
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
						'description' => __( 'Set to true to confirm destructive operations (required for delete actions).', 'bricks-mcp' ),
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
			__( "Manage individual Bricks elements on a page.\n\n⚠️ CRITICAL: Before using 'add', 'update', or 'bulk_add' actions, you MUST call get_site_info, global_class:list, and get_builder_guide(section='professional') first. Use _cssGlobalClasses on every element. Create global classes if none exist. Inline styles only for instance overrides.\n\nActions: add, update, remove (optional cascade), get_conditions, set_conditions, move, bulk_update, bulk_add (supports nested tree format), duplicate, find.", 'bricks-mcp' ),
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
						'description' => __( 'Set to true to confirm destructive operations (required for delete actions).', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_element' )
		);

		// Template consolidated tool (replaces list_templates, get_template_content, create_template, update_template, delete_template, duplicate_template).
		$this->register_tool(
			'template',
			__( "Manage Bricks templates (headers, footers, sections, popups, etc.).\n\n⚠️ CRITICAL: Before using 'create' or 'update' actions with element content, you MUST call get_site_info, global_class:list, and get_builder_guide(section='professional') first. Use _cssGlobalClasses on every element. Create global classes if none exist. Inline styles only for instance overrides.\n\nActions: list, get, create, update, delete, duplicate, get_popup_settings, set_popup_settings, export, import, import_url.", 'bricks-mcp' ),
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
						'description' => __( 'Set to true to confirm destructive operations (required for delete actions).', 'bricks-mcp' ),
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
						'description' => __( 'Set to true to confirm destructive operations (required for delete actions).', 'bricks-mcp' ),
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
						'description' => __( 'Set to true to confirm destructive operations (required for delete actions).', 'bricks-mcp' ),
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
						'description' => __( 'Set to true to confirm destructive operations (required for delete actions).', 'bricks-mcp' ),
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
						'description' => __( 'Set to true to confirm destructive operations (required for delete actions).', 'bricks-mcp' ),
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
						'description' => __( 'Set to true to confirm destructive operations (required for delete actions).', 'bricks-mcp' ),
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
						'description' => __( 'Set to true to confirm destructive operations (required for delete actions).', 'bricks-mcp' ),
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
			array( $this, 'tool_media' )
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
						'description' => __( 'Set to true to confirm destructive operations (required for delete actions).', 'bricks-mcp' ),
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
			array( $this, 'tool_font' )
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
			array( $this, 'tool_code' )
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
	 * Tool: WordPress dispatcher — routes to get_posts, get_post, get_users, get_plugins.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_wordpress( array $args ): array|\WP_Error {
		$action = $args['action'] ?? '';

		$action_caps = array(
			'get_posts'   => 'read',
			'get_post'    => 'read',
			'get_users'   => 'list_users',
			'get_plugins' => 'activate_plugins',
		);

		// Reject unknown actions before capability check to prevent future actions
		// from accidentally bypassing caps if added to match but not to $action_caps.
		if ( ! isset( $action_caps[ $action ] ) ) {
			return new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: get_posts, get_post, get_users, get_plugins', 'bricks-mcp' ),
					sanitize_text_field( $action )
				)
			);
		}

		if ( ! current_user_can( $action_caps[ $action ] ) ) {
			return new \WP_Error(
				'bricks_mcp_forbidden',
				sprintf(
					/* translators: %s: Required capability */
					__( 'You do not have the required capability (%s) to perform this action.', 'bricks-mcp' ),
					$action_caps[ $action ]
				)
			);
		}

		return match ( $action ) {
			'get_posts'   => $this->tool_get_posts( $args ),
			'get_post'    => $this->tool_get_post( $args ),
			'get_users'   => $this->tool_get_users( $args ),
			'get_plugins' => $this->tool_get_plugins( $args ),
			default       => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: get_posts, get_post, get_users, get_plugins', 'bricks-mcp' ),
					sanitize_text_field( $action )
				)
			),
		};
	}

	/**
	 * Tool: Bricks dispatcher — routes to enable, disable, get_settings, get_breakpoints, get_element_schemas, get_dynamic_tags, get_query_types, get_form_schema, get_interaction_schema, get_component_schema, get_popup_schema, get_filter_schema, get_global_queries, set_global_query, delete_global_query.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_bricks( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = sanitize_text_field( $args['action'] ?? '' );


		return match ( $action ) {
			'enable'                  => $this->tool_enable_bricks( $args ),
			'disable'                 => $this->tool_disable_bricks( $args ),
			'get_settings'            => $this->tool_get_bricks_settings( $args ),
			'get_breakpoints'         => $this->tool_get_breakpoints( $args ),
			'get_element_schemas'     => $this->schema_handler->tool_get_element_schemas( $args ),
			'get_dynamic_tags'        => $this->schema_handler->tool_get_dynamic_tags( $args ),
			'get_query_types'         => $this->schema_handler->tool_get_query_types( $args ),
			'get_form_schema'         => $this->schema_handler->tool_get_form_schema( $args ),
			'get_interaction_schema'  => $this->schema_handler->tool_get_interaction_schema( $args ),
			'get_component_schema'    => $this->schema_handler->tool_get_component_schema( $args ),
			'get_popup_schema'        => $this->schema_handler->tool_get_popup_schema( $args ),
			'get_filter_schema'       => $this->schema_handler->tool_get_filter_schema( $args ),
			'get_condition_schema'    => $this->schema_handler->tool_get_condition_schema( $args ),
			'get_global_queries'      => $this->tool_get_global_queries( $args ),
			'set_global_query'        => $this->tool_set_global_query( $args ),
			'delete_global_query'     => $this->tool_delete_global_query( $args ),
			'analyze_patterns'        => $this->bricks_service->analyze_patterns(),
			'save_pattern'            => $this->bricks_service->save_pattern( (int) ( $args['post_id'] ?? 0 ), sanitize_text_field( $args['root_element_id'] ?? '' ), sanitize_text_field( $args['name'] ?? '' ) ),
			'use_pattern'             => $this->bricks_service->use_pattern( sanitize_text_field( $args['pattern_id'] ?? '' ), (int) ( $args['post_id'] ?? 0 ), $args['overrides'] ?? [] ),
			'get_notes'               => [ 'notes' => $this->bricks_service->get_notes() ],
			'add_note'                => $this->bricks_service->add_note( sanitize_text_field( $args['text'] ?? '' ) ),
			'delete_note'             => [ 'deleted' => $this->bricks_service->delete_note( sanitize_text_field( $args['note_id'] ?? '' ) ) ],
			default                   => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: enable, disable, get_settings, get_breakpoints, get_element_schemas, get_dynamic_tags, get_query_types, get_form_schema, get_interaction_schema, get_component_schema, get_popup_schema, get_filter_schema, get_condition_schema, get_global_queries, set_global_query, delete_global_query, analyze_patterns, save_pattern, use_pattern, get_notes, add_note, delete_note', 'bricks-mcp' ),
					$action
				)
			),
		};
	}


	/**
	 * Tool: Get global queries.
	 *
	 * Returns all reusable global query definitions stored in bricks_global_queries option.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Global queries list or error.
	 */
	private function tool_get_global_queries( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$queries = get_option( 'bricks_global_queries', array() );
		$queries = is_array( $queries ) ? $queries : array();

		return array(
			'global_queries' => $queries,
			'count'          => count( $queries ),
			'usage_hint'     => 'Reference a global query on any loop element: set query.id to the global query ID. Bricks resolves the settings at runtime.',
		);
	}

	/**
	 * Tool: Set global query (create or update).
	 *
	 * Creates a new global query or updates an existing one by query_id.
	 *
	 * @param array<string, mixed> $args Tool arguments including name, settings, optional query_id and category.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	private function tool_set_global_query( array $args ): array|\WP_Error {
		$queries  = get_option( 'bricks_global_queries', array() );
		$queries  = is_array( $queries ) ? $queries : array();
		$query_id = isset( $args['query_id'] ) ? sanitize_text_field( (string) $args['query_id'] ) : '';
		$name     = sanitize_text_field( $args['name'] ?? '' );
		$settings = $args['settings'] ?? array();
		$category = sanitize_text_field( $args['category'] ?? '' );

		if ( empty( $name ) ) {
			return new \WP_Error( 'missing_name', __( 'name is required for set_global_query.', 'bricks-mcp' ) );
		}
		if ( ! is_array( $settings ) || empty( $settings ) ) {
			return new \WP_Error( 'missing_settings', __( 'settings (object with query configuration) is required for set_global_query.', 'bricks-mcp' ) );
		}

		// Security: strip queryEditor/useQueryEditor and sanitize settings recursively.
		unset( $settings['queryEditor'], $settings['useQueryEditor'] );
		$settings = $this->sanitize_query_settings( $settings );

		$existing_index = false;
		if ( ! empty( $query_id ) ) {
			foreach ( $queries as $idx => $q ) {
				if ( isset( $q['id'] ) && $q['id'] === $query_id ) {
					$existing_index = $idx;
					break;
				}
			}
		}

		$id_generator = new ElementIdGenerator();
		$entry        = array(
			'id'       => ! empty( $query_id ) && false !== $existing_index
				? $query_id
				: $id_generator->generate_unique( $queries ),
			'name'     => $name,
			'settings' => $settings,
		);
		if ( ! empty( $category ) ) {
			$entry['category'] = $category;
		}

		if ( false !== $existing_index ) {
			$queries[ $existing_index ] = $entry;
			$action_taken               = 'updated';
		} else {
			$queries[]    = $entry;
			$action_taken = 'created';
		}

		update_option( 'bricks_global_queries', $queries );

		return array(
			'action'     => $action_taken,
			'query'      => $entry,
			'usage_hint' => sprintf( 'Reference this global query on any loop element: set query.id to "%s".', $entry['id'] ),
		);
	}

	/**
	 * Recursively sanitize global query settings to prevent XSS/injection via stored values.
	 *
	 * @param array<string, mixed> $settings The query settings array.
	 * @return array<string, mixed> Sanitized settings.
	 */
	private function sanitize_query_settings( array $settings ): array {
		$sanitized = [];
		foreach ( $settings as $key => $value ) {
			$safe_key = sanitize_text_field( (string) $key );
			if ( is_array( $value ) ) {
				$sanitized[ $safe_key ] = $this->sanitize_query_settings( $value );
			} elseif ( is_string( $value ) ) {
				$sanitized[ $safe_key ] = sanitize_text_field( $value );
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
				$sanitized[ $safe_key ] = $value;
			}
		}
		return $sanitized;
	}

	/**
	 * Tool: Delete global query.
	 *
	 * Deletes a global query by ID and warns about orphaned element references.
	 *
	 * @param array<string, mixed> $args Tool arguments including query_id.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	private function tool_delete_global_query( array $args ): array|\WP_Error {
		$query_id = isset( $args['query_id'] ) ? sanitize_text_field( (string) $args['query_id'] ) : '';
		if ( empty( $query_id ) ) {
			return new \WP_Error( 'missing_query_id', __( 'query_id is required for delete_global_query.', 'bricks-mcp' ) );
		}

		$queries = get_option( 'bricks_global_queries', array() );
		$queries = is_array( $queries ) ? $queries : array();

		$found_index = false;
		$found_query = null;
		foreach ( $queries as $idx => $q ) {
			if ( isset( $q['id'] ) && $q['id'] === $query_id ) {
				$found_index = $idx;
				$found_query = $q;
				break;
			}
		}

		if ( false === $found_index ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Query ID */
					__( 'Global query "%s" not found. Use bricks:get_global_queries to list available queries.', 'bricks-mcp' ),
					$query_id
				)
			);
		}

		array_splice( $queries, $found_index, 1 );
		update_option( 'bricks_global_queries', $queries );

		return array(
			'deleted'  => true,
			'query_id' => $query_id,
			'name'     => $found_query['name'] ?? '',
			'warning'  => 'Any elements referencing this global query ID will fall back to empty query settings. Check for elements with query.id set to this ID.',
		);
	}


	/**
	 * Tool: Enable the Bricks editor for a post.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	private function tool_enable_bricks( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Provide the ID of the post to enable Bricks on.', 'bricks-mcp' )
			);
		}

		$post_id = (int) $args['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found. Use page:list to find valid post IDs.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		$was_already_enabled = $this->bricks_service->is_bricks_page( $post_id );
		$this->bricks_service->enable_bricks_editor( $post_id );
		$elements = $this->bricks_service->get_elements( $post_id );

		return array(
			'post_id'             => $post_id,
			'title'               => $post->post_title,
			'bricks_enabled'      => true,
			'was_already_enabled' => $was_already_enabled,
			'element_count'       => count( $elements ),
			'edit_url'            => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		);
	}

	/**
	 * Tool: Disable the Bricks editor for a post.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	private function tool_disable_bricks( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Provide the ID of the post to disable Bricks on.', 'bricks-mcp' )
			);
		}

		$post_id = (int) $args['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found. Use page:list to find valid post IDs.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		$was_already_disabled = ! $this->bricks_service->is_bricks_page( $post_id );
		$this->bricks_service->disable_bricks_editor( $post_id );

		return array(
			'post_id'              => $post_id,
			'title'                => $post->post_title,
			'bricks_enabled'       => false,
			'was_already_disabled' => $was_already_disabled,
			'note'                 => __( 'Bricks content preserved in database. Re-enable with bricks tool (action: enable).', 'bricks-mcp' ),
		);
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
	 * Tool: Media dispatcher — routes to search_unsplash, sideload, list, set_featured, remove_featured, get_image_settings.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_media( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = sanitize_text_field( $args['action'] ?? '' );


		// Map 'image_size' to 'size' for get_image_settings handler.
		if ( isset( $args['image_size'] ) && ! isset( $args['size'] ) ) {
			$args['size'] = $args['image_size'];
		}

		return match ( $action ) {
			'search_unsplash'  => $this->tool_search_unsplash( $args ),
			'sideload'         => $this->tool_sideload_image( $args ),
			'list'             => $this->tool_get_media_library( $args ),
			'set_featured'     => $this->tool_set_featured_image( $args ),
			'remove_featured'  => $this->tool_remove_featured_image( $args ),
			'get_image_settings' => $this->tool_get_image_element_settings( $args ),
			default            => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: search_unsplash, sideload, list, set_featured, remove_featured, get_image_settings', 'bricks-mcp' ),
					$action
				)
			),
		};
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
	 * Tool: Get responsive breakpoints.
	 *
	 * Returns all available breakpoints with composite key format and examples.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Breakpoint data or error.
	 */
	private function tool_get_breakpoints( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$breakpoints = $this->bricks_service->get_breakpoints();

		// Detect custom breakpoints setting.
		$is_custom = false;
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_setting' ) ) {
			$is_custom = ! empty( \Bricks\Database::get_setting( 'customBreakpoints' ) );
		} else {
			$global_settings = get_option( 'bricks_global_settings', array() );
			$is_custom       = ! empty( $global_settings['customBreakpoints'] );
		}

		// Add sort_order and is_custom to each breakpoint.
		$base_key   = 'desktop';
		$base_width = 0;

		foreach ( $breakpoints as $index => &$bp ) {
			$bp['sort_order'] = $index;
			$bp['is_custom']  = $is_custom;

			if ( ! empty( $bp['base'] ) ) {
				$base_key   = $bp['key'];
				$base_width = $bp['width'];
			}
		}
		unset( $bp );

		// Determine approach.
		$max_width = 0;
		$min_width = PHP_INT_MAX;
		foreach ( $breakpoints as $bp ) {
			if ( $bp['width'] > $max_width ) {
				$max_width = $bp['width'];
			}
			if ( $bp['width'] < $min_width ) {
				$min_width = $bp['width'];
			}
		}

		if ( $base_width >= $max_width ) {
			$approach = 'desktop-first';
		} elseif ( $base_width <= $min_width ) {
			$approach = 'mobile-first';
		} else {
			$approach = 'custom';
		}

		return array(
			'breakpoints'                => $breakpoints,
			'base_breakpoint'            => $base_key,
			'approach'                   => $approach,
			'custom_breakpoints_enabled' => $is_custom,
			'composite_key_format'       => '{property}:{breakpoint}:{pseudo}',
			'examples'                   => array(
				'_margin:tablet_portrait' => 'Margin on tablet portrait',
				'_padding:mobile'         => 'Padding on mobile',
				'_background:hover'       => 'Background on hover state',
				'_margin:mobile:hover'    => 'Margin on mobile hover',
			),
		);
	}

	/**
	 * Tool: Font dispatcher — routes to get_status, get_adobe_fonts, update_settings.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_font( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = sanitize_text_field( $args['action'] ?? '' );


		return match ( $action ) {
			'get_status'      => $this->tool_get_font_status( $args ),
			'get_adobe_fonts' => $this->tool_get_adobe_fonts( $args ),
			'update_settings' => $this->tool_update_font_settings( $args ),
			default           => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: get_status, get_adobe_fonts, update_settings', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Get font configuration status overview.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed> Font status data.
	 */
	private function tool_get_font_status( array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->bricks_service->get_font_status();
	}

	/**
	 * Tool: Get cached Adobe Fonts.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed> Adobe Fonts data.
	 */
	private function tool_get_adobe_fonts( array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->bricks_service->get_adobe_fonts();
	}

	/**
	 * Tool: Update font-related settings.
	 *
	 * @param array<string, mixed> $args Tool arguments with font setting fields.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	private function tool_update_font_settings( array $args ): array|\WP_Error {
		$fields = array();

		if ( array_key_exists( 'disable_google_fonts', $args ) ) {
			$fields['disableGoogleFonts'] = $args['disable_google_fonts'];
		}

		if ( array_key_exists( 'webfont_loading', $args ) ) {
			$fields['webfontLoading'] = $args['webfont_loading'];
		}

		if ( array_key_exists( 'custom_fonts_preload', $args ) ) {
			$fields['customFontsPreload'] = $args['custom_fonts_preload'];
		}

		if ( empty( $fields ) ) {
			return new \WP_Error(
				'no_fields',
				__( 'No font settings provided. Use disable_google_fonts (boolean), webfont_loading (string), or custom_fonts_preload (boolean).', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_font_settings( $fields );
	}

	/**
	 * Tool: Code dispatcher — routes to get_page_css, set_page_css, get_page_scripts, set_page_scripts.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_code( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = sanitize_text_field( $args['action'] ?? '' );


		if ( 'set_page_scripts' === $action ) {
			if ( ! $this->bricks_service->is_dangerous_actions_enabled() ) {
				return new \WP_Error(
					'dangerous_actions_disabled',
					__( 'Custom scripts require the Dangerous Actions toggle to be enabled in Settings > Bricks MCP.', 'bricks-mcp' )
				);
			}
		}

		return match ( $action ) {
			'get_page_css'     => $this->tool_get_page_css( $args ),
			'set_page_css'     => $this->tool_set_page_css( $args ),
			'get_page_scripts' => $this->tool_get_page_scripts( $args ),
			'set_page_scripts' => $this->tool_set_page_scripts( $args ),
			default            => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: get_page_css, set_page_css, get_page_scripts, set_page_scripts', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Get page custom CSS and scripts.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id'.
	 * @return array<string, mixed>|\WP_Error Code data or error.
	 */
	private function tool_get_page_css( array $args ): array|\WP_Error {
		$post_id = $args['post_id'] ?? null;

		if ( null === $post_id ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required for get_page_css.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->get_page_code( (int) $post_id );
	}

	/**
	 * Tool: Set page custom CSS.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id' and 'css'.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	private function tool_set_page_css( array $args ): array|\WP_Error {
		$post_id = $args['post_id'] ?? null;

		if ( null === $post_id ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required for set_page_css.', 'bricks-mcp' )
			);
		}

		if ( ! array_key_exists( 'css', $args ) ) {
			return new \WP_Error(
				'missing_css',
				__( 'css is required for set_page_css. Send empty string to remove custom CSS.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_page_css( (int) $post_id, (string) $args['css'] );
	}

	/**
	 * Tool: Get page custom scripts only.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id'.
	 * @return array<string, mixed>|\WP_Error Script data or error.
	 */
	private function tool_get_page_scripts( array $args ): array|\WP_Error {
		$post_id = $args['post_id'] ?? null;

		if ( null === $post_id ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required for get_page_scripts.', 'bricks-mcp' )
			);
		}

		$code = $this->bricks_service->get_page_code( (int) $post_id );

		if ( is_wp_error( $code ) ) {
			return $code;
		}

		return array(
			'post_id'                 => $code['post_id'],
			'customScriptsHeader'     => $code['customScriptsHeader'],
			'customScriptsBodyHeader' => $code['customScriptsBodyHeader'],
			'customScriptsBodyFooter' => $code['customScriptsBodyFooter'],
			'has_scripts'             => $code['has_scripts'],
		);
	}

	/**
	 * Tool: Set page custom scripts.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id' and script placement params.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	private function tool_set_page_scripts( array $args ): array|\WP_Error {
		$post_id = $args['post_id'] ?? null;

		if ( null === $post_id ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required for set_page_scripts.', 'bricks-mcp' )
			);
		}

		$scripts = array();

		if ( array_key_exists( 'header', $args ) ) {
			$scripts['customScriptsHeader'] = (string) $args['header'];
		}

		if ( array_key_exists( 'body_header', $args ) ) {
			$scripts['customScriptsBodyHeader'] = (string) $args['body_header'];
		}

		if ( array_key_exists( 'body_footer', $args ) ) {
			$scripts['customScriptsBodyFooter'] = (string) $args['body_footer'];
		}

		if ( empty( $scripts ) ) {
			return new \WP_Error(
				'no_scripts',
				__( 'At least one script parameter is required: header, body_header, or body_footer.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_page_scripts( (int) $post_id, $scripts );
	}

	/**
	 * Tool: Get builder guide.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array{guide: string}|array{section: string, content: string} Guide content.
	 */
	public function tool_get_builder_guide( array $args ): array {
		static $cached_content = null;

		$guide_path = BRICKS_MCP_PLUGIN_DIR . 'docs/BUILDER_GUIDE.md';

		if ( ! file_exists( $guide_path ) ) {
			return array( 'guide' => 'Builder guide not found. Use get_element_schemas to discover available elements.' );
		}

		if ( null === $cached_content ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
			$cached_content = file_get_contents( $guide_path );
		}

		$content = $cached_content;

		if ( false === $content ) {
			return array( 'guide' => 'Failed to read builder guide.' );
		}

		$section_map = array(
			'professional'              => '## Professional Page Building',
			'settings'                  => '## Element Settings Reference',
			'animations'                => '## Animations',
			'interactions'              => '## Animations',
			'dynamic_data'              => '## Dynamic Data & Query Loops',
			'forms'                     => '## Forms',
			'components'                => '## Components',
			'popups'                    => '## Popups',
			'element_conditions'        => '## Element Conditions & Visibility',
			'woocommerce'               => '## WooCommerce',
			'seo'                       => '## SEO Optimization',
			'custom_code'               => '## Custom Code',
			'fonts'                     => '## Font Management',
			'import_export'             => '## Import & Export',
			'workflows'                 => '## Common Workflows',
			'gotchas'                   => '## Key Gotchas',
			'workflow'                  => '## Workflow',
			'recipes'                   => '## Recipes',
			'connection_troubleshooting' => '## Connection Troubleshooting',
		);

		$section = $args['section'] ?? 'all';

		// Return table of contents when requesting all sections (full guide is too large for a single response).
		if ( 'all' === $section ) {
			$toc = "# Bricks MCP Builder Guide — Table of Contents\n\n";
			$toc .= "The full guide is split into sections. Request a specific section for detailed content.\n\n";
			$toc .= "| Section key | Topic |\n|---|---|\n";
			foreach ( $section_map as $key => $heading ) {
				if ( 'interactions' === $key ) {
					continue; // Alias for animations.
				}
				$toc .= "| `{$key}` | {$heading} |\n";
			}
			$toc .= "\nUse `get_builder_guide` with `section` parameter to fetch a specific section.";
			$toc .= "\n\nRecommended first reads: `professional` (global-class-first approach and design system), `settings` (element properties reference), `gotchas` (common mistakes).";

			return array( 'guide' => $toc );
		}

		if ( ! isset( $section_map[ $section ] ) ) {
			return array(
				'error'             => "Unknown section: '{$section}'.",
				'available_sections' => array_keys( $section_map ),
			);
		}

		$heading = $section_map[ $section ];
		$pos     = strpos( $content, $heading );

		if ( false === $pos ) {
			return array( 'error' => "Section heading '{$heading}' not found in guide." );
		}

		// Extract from heading to next ## heading or end of file.
		$rest      = substr( $content, $pos );
		$next_h2   = strpos( $rest, "\n## ", strlen( $heading ) );
		$extracted = false !== $next_h2 ? substr( $rest, 0, $next_h2 ) : $rest;

		// Append persistent correction notes to gotchas section.
		if ( 'gotchas' === $section ) {
			$notes = $this->bricks_service->get_notes();
			if ( ! empty( $notes ) ) {
				$notes_text = "\n\n### AI Notes (persistent corrections)\n\n";
				foreach ( $notes as $note ) {
					$notes_text .= '- ' . ( $note['text'] ?? '' ) . "\n";
				}
				$extracted .= $notes_text;
			}
		}

		return array(
			'section' => $section,
			'content' => trim( $extracted ),
		);
	}

	/**
	 * Tool: Get Bricks global settings.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Settings data or error.
	 */
	private function tool_get_bricks_settings( array $args ): array|\WP_Error {
		$category = sanitize_key( $args['category'] ?? '' );

		return $this->bricks_service->get_bricks_settings( $category );
	}

	/**
	 * Tool: Search Unsplash photos.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Search results or error.
	 */
	private function tool_search_unsplash( array $args ): array|\WP_Error {
		if ( empty( $args['query'] ) || ! is_string( $args['query'] ) ) {
			return new \WP_Error(
				'missing_query',
				__( 'query parameter is required and must be a non-empty string.', 'bricks-mcp' )
			);
		}

		return $this->media_service->search_photos( $args['query'] );
	}

	/**
	 * Tool: Sideload image from URL into WordPress media library.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Sideload result or error.
	 */
	private function tool_sideload_image( array $args ): array|\WP_Error {
		if ( empty( $args['url'] ) || ! is_string( $args['url'] ) ) {
			return new \WP_Error(
				'missing_url',
				__( 'url parameter is required and must be a non-empty string.', 'bricks-mcp' )
			);
		}

		$url               = $args['url'];
		$alt_text          = isset( $args['alt_text'] ) && is_string( $args['alt_text'] ) ? $args['alt_text'] : '';
		$title             = isset( $args['title'] ) && is_string( $args['title'] ) ? $args['title'] : '';
		$unsplash_id       = isset( $args['unsplash_id'] ) && is_string( $args['unsplash_id'] ) ? $args['unsplash_id'] : null;
		$download_location = isset( $args['download_location'] ) && is_string( $args['download_location'] ) ? $args['download_location'] : null;

		return $this->media_service->sideload_from_url( $url, $alt_text, $title, $unsplash_id, $download_location );
	}

	/**
	 * Tool: Browse the WordPress media library.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Media library results or error.
	 */
	private function tool_get_media_library( array $args ): array|\WP_Error {
		$search    = isset( $args['search'] ) && is_string( $args['search'] ) ? $args['search'] : '';
		$mime_type = isset( $args['mime_type'] ) && is_string( $args['mime_type'] ) ? $args['mime_type'] : 'image';
		$per_page  = isset( $args['per_page'] ) && is_int( $args['per_page'] ) ? $args['per_page'] : 20;
		$page      = isset( $args['page'] ) && is_int( $args['page'] ) ? $args['page'] : 1;

		return $this->media_service->get_media_library_items( $search, $mime_type, $per_page, $page );
	}

	/**
	 * Tool: Set or replace the featured image for a post.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Featured image result or error.
	 */
	private function tool_set_featured_image( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) || ! is_numeric( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id parameter is required and must be an integer.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['attachment_id'] ) || ! is_numeric( $args['attachment_id'] ) ) {
			return new \WP_Error(
				'missing_attachment_id',
				__( 'attachment_id parameter is required and must be an integer.', 'bricks-mcp' )
			);
		}

		$post_id       = (int) $args['post_id'];
		$attachment_id = (int) $args['attachment_id'];

		// Validate post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'Post %d not found. Use page:list to find valid post IDs.', 'bricks-mcp' ), $post_id )
			);
		}

		// Validate post type supports thumbnails.
		if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
			return new \WP_Error(
				'thumbnails_not_supported',
				/* translators: %s: post type name */
				sprintf( __( 'Post type "%s" does not support featured images (thumbnails).', 'bricks-mcp' ), $post->post_type )
			);
		}

		// Validate attachment exists and is an attachment.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error(
				'attachment_not_found',
				/* translators: %d: attachment ID */
				sprintf( __( 'Attachment %d not found in media library. Use media:sideload to upload an image first, or media:list to find existing images.', 'bricks-mcp' ), $attachment_id )
			);
		}

		// Get old thumbnail before replacing.
		$old_thumbnail_id = get_post_thumbnail_id( $post_id );

		$result = set_post_thumbnail( $post_id, $attachment_id );
		if ( ! $result ) {
			return new \WP_Error(
				'set_thumbnail_failed',
				__( 'Failed to set the featured image. The post or attachment may be invalid.', 'bricks-mcp' )
			);
		}

		$response = array(
			'post_id'       => $post_id,
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ) ? wp_get_attachment_url( $attachment_id ) : '',
			'title'         => get_the_title( $post_id ),
		);

		if ( $old_thumbnail_id && (int) $old_thumbnail_id !== $attachment_id ) {
			$response['replaced_attachment_id'] = (int) $old_thumbnail_id;
			$response['warning']                = sprintf(
				/* translators: %d: old attachment ID */
				__( 'Previous featured image (attachment ID %d) was replaced.', 'bricks-mcp' ),
				(int) $old_thumbnail_id
			);
		}

		return $response;
	}

	/**
	 * Tool: Remove the featured image from a post.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Removal result or error.
	 */
	private function tool_remove_featured_image( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) || ! is_numeric( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id parameter is required and must be an integer.', 'bricks-mcp' )
			);
		}

		$post_id = (int) $args['post_id'];

		// Validate post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'Post %d not found. Use page:list to find valid post IDs.', 'bricks-mcp' ), $post_id )
			);
		}

		// Check if post has a featured image.
		$current_thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $current_thumbnail_id ) {
			return array(
				'post_id' => $post_id,
				'removed' => false,
				'message' => __( 'Post has no featured image.', 'bricks-mcp' ),
			);
		}

		delete_post_thumbnail( $post_id );

		return array(
			'post_id'               => $post_id,
			'removed'               => true,
			'removed_attachment_id' => (int) $current_thumbnail_id,
		);
	}

	/**
	 * Tool: Get Bricks image element settings for an attachment.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Image settings or error.
	 */
	private function tool_get_image_element_settings( array $args ): array|\WP_Error {
		if ( empty( $args['attachment_id'] ) || ! is_numeric( $args['attachment_id'] ) ) {
			return new \WP_Error(
				'missing_attachment_id',
				__( 'attachment_id parameter is required and must be an integer.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['target'] ) || ! is_string( $args['target'] ) ) {
			return new \WP_Error(
				'missing_target',
				__( 'target parameter is required. Use "image", "background", or "gallery".', 'bricks-mcp' )
			);
		}

		$attachment_id = (int) $args['attachment_id'];
		$target        = $args['target'];
		$size          = isset( $args['size'] ) && is_string( $args['size'] ) ? $args['size'] : 'full';

		// Validate attachment exists.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error(
				'attachment_not_found',
				/* translators: %d: attachment ID */
				sprintf( __( 'Attachment %d not found in media library. Use media:sideload to upload an image first, or media:list to find existing images.', 'bricks-mcp' ), $attachment_id )
			);
		}

		// Validate target.
		$valid_targets = array( 'image', 'background', 'gallery' );
		if ( ! in_array( $target, $valid_targets, true ) ) {
			return new \WP_Error(
				'invalid_target',
				/* translators: %s: provided target value */
				sprintf( __( 'Invalid target "%s". Use "image", "background", or "gallery".', 'bricks-mcp' ), $target )
			);
		}

		$image_obj = $this->media_service->build_bricks_image_object( $attachment_id, $size );
		if ( is_wp_error( $image_obj ) ) {
			return $image_obj;
		}

		$response = array(
			'attachment_id'       => $attachment_id,
			'bricks_image_object' => $image_obj,
		);

		switch ( $target ) {
			case 'image':
				$response['target']       = 'image';
				$response['usage']        = __( 'Set as settings.image on an Image element', 'bricks-mcp' );
				$response['settings_key'] = 'image';
				$response['value']        = $image_obj;
				break;

			case 'background':
				$response['target']       = 'background';
				$response['usage']        = __( 'Set as settings._background.image on a section or container', 'bricks-mcp' );
				$response['settings_key'] = '_background';
				$response['value']        = array( 'image' => $image_obj );
				$response['note']         = __( "You can add 'position': 'center center', 'size': 'cover', 'repeat': 'no-repeat' alongside the image key inside _background.", 'bricks-mcp' );
				break;

			case 'gallery':
				$response['target']       = 'gallery';
				$response['usage']        = __( 'Add to settings.images array on a Gallery element', 'bricks-mcp' );
				$response['settings_key'] = 'images';
				$response['value']        = $image_obj;
				$response['note']         = __( 'This is one item. For a gallery, collect multiple items into an array and set as settings.images.', 'bricks-mcp' );
				break;
		}

		return $response;
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
