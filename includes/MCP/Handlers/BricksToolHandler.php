<?php
/**
 * Bricks tool handler for MCP Router.
 *
 * Handles the consolidated 'bricks' tool: enable/disable editor, settings,
 * breakpoints, global queries, element schemas, and AI notes.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\ElementIdGenerator;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the 'bricks' consolidated tool actions.
 */
final class BricksToolHandler {

	/**
	 * @var BricksService
	 */
	private BricksService $bricks_service;

	/**
	 * @var SchemaHandler
	 */
	private SchemaHandler $schema_handler;

	/**
	 * Constructor.
	 *
	 * @param BricksService $bricks_service Bricks service instance.
	 * @param SchemaHandler $schema_handler Schema handler instance.
	 */
	public function __construct( BricksService $bricks_service, SchemaHandler $schema_handler ) {
		$this->bricks_service = $bricks_service;
		$this->schema_handler = $schema_handler;
	}

	/**
	 * Handle bricks tool actions.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$action = sanitize_text_field( $args['action'] ?? '' );

		return match ( $action ) {
			'enable'                 => $this->tool_enable_bricks( $args ),
			'disable'                => $this->tool_disable_bricks( $args ),
			'get_settings'           => $this->tool_get_bricks_settings( $args ),
			'get_breakpoints'        => $this->tool_get_breakpoints( $args ),
			'get_element_schemas'    => $this->schema_handler->tool_get_element_schemas( $args ),
			'get_dynamic_tags'       => $this->schema_handler->tool_get_dynamic_tags( $args ),
			'get_query_types'        => $this->schema_handler->tool_get_query_types( $args ),
			'get_form_schema'        => $this->schema_handler->tool_get_form_schema( $args ),
			'get_interaction_schema' => $this->schema_handler->tool_get_interaction_schema( $args ),
			'get_component_schema'   => $this->schema_handler->tool_get_component_schema( $args ),
			'get_popup_schema'       => $this->schema_handler->tool_get_popup_schema( $args ),
			'get_filter_schema'      => $this->schema_handler->tool_get_filter_schema( $args ),
			'get_condition_schema'   => $this->schema_handler->tool_get_condition_schema( $args ),
			'get_global_queries'     => $this->tool_get_global_queries( $args ),
			'set_global_query'       => $this->tool_set_global_query( $args ),
			'delete_global_query'    => $this->tool_delete_global_query( $args ),
			'get_notes'              => [ 'notes' => $this->bricks_service->get_notes() ],
			'add_note'               => $this->bricks_service->add_note( sanitize_text_field( $args['text'] ?? '' ) ),
			'delete_note'            => [ 'deleted' => $this->bricks_service->delete_note( sanitize_text_field( $args['note_id'] ?? '' ) ) ],
			default                  => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: enable, disable, get_settings, get_breakpoints, get_element_schemas, get_dynamic_tags, get_query_types, get_form_schema, get_interaction_schema, get_component_schema, get_popup_schema, get_filter_schema, get_condition_schema, get_global_queries, set_global_query, delete_global_query, get_notes, add_note, delete_note', 'bricks-mcp' ),
					$action
				)
			),
		};
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
	 * Tool: Get responsive breakpoints.
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
	 * Tool: Get global queries.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Global queries data or error.
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
	 * Recursively sanitize global query settings.
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

		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf(
					/* translators: 1: Query name, 2: Query ID */
					__( 'This will delete global query "%1$s" (ID: %2$s). Any elements referencing this query ID will stop working. Set confirm: true to proceed.', 'bricks-mcp' ),
					$found_query['name'] ?? $query_id,
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
}
