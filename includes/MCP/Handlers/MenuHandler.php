<?php
/**
 * Menu handler for MCP Router.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\MenuService;
use BricksMCP\MCP\ToolRegistry;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles menu tool actions.
 */
final class MenuHandler {

	/**
	 * Menu service instance.
	 *
	 * @var MenuService
	 */
	private MenuService $menu_service;

	/**
	 * Bricks check callback.
	 *
	 * @var callable
	 */
	private $require_bricks;

	/**
	 * Constructor.
	 *
	 * @param MenuService $menu_service    Menu service instance.
	 * @param callable    $require_bricks  Callback that returns \WP_Error|null.
	 */
	public function __construct( MenuService $menu_service, callable $require_bricks ) {
		$this->menu_service    = $menu_service;
		$this->require_bricks  = $require_bricks;
	}

	/**
	 * Handle menu tool actions.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$action = sanitize_text_field( $args['action'] ?? '' );

		return match ( $action ) {
			'list'           => $this->tool_list_menus( $args ),
			'get'            => $this->tool_get_menu( $args ),
			'create'         => $this->tool_create_menu( $args ),
			'update'         => $this->tool_update_menu( $args ),
			'delete'         => $this->tool_delete_menu( $args ),
			'set_items'      => $this->tool_set_menu_items( $args ),
			'assign'         => $this->tool_assign_menu( $args ),
			'unassign'       => $this->tool_unassign_menu( $args ),
			'list_locations' => $this->tool_list_menu_locations( $args ),
			default          => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, get, create, update, delete, set_items, assign, unassign, list_locations', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Create a new navigation menu.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created menu data or error.
	 */
	private function tool_create_menu( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['name'] ) || ! is_string( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name parameter is required and must be a non-empty string.', 'bricks-mcp' )
			);
		}

		return $this->menu_service->create_menu( $args['name'] );
	}

	/**
	 * Tool: Update a navigation menu's name.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated menu data or error.
	 */
	private function tool_update_menu( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['menu_id'] ) || ! is_numeric( $args['menu_id'] ) || (int) $args['menu_id'] <= 0 ) {
			return new \WP_Error(
				'missing_menu_id',
				__( 'menu_id parameter is required and must be a positive integer. Use list_menus to find valid menu IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['name'] ) || ! is_string( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name parameter is required and must be a non-empty string.', 'bricks-mcp' )
			);
		}

		return $this->menu_service->update_menu( (int) $args['menu_id'], $args['name'] );
	}

	/**
	 * Tool: Delete a navigation menu.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_menu( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['menu_id'] ) || ! is_numeric( $args['menu_id'] ) || (int) $args['menu_id'] <= 0 ) {
			return new \WP_Error(
				'missing_menu_id',
				__( 'menu_id parameter is required and must be a positive integer. Use list_menus to find valid menu IDs.', 'bricks-mcp' )
			);
		}

		$menu_id = (int) $args['menu_id'];

		// Confirm check.
		if ( empty( $args['confirm'] ) ) {
			$menu  = wp_get_nav_menu_object( $menu_id );
			$items = $menu ? wp_get_nav_menu_items( $menu_id ) : false;
			$count = is_array( $items ) ? count( $items ) : 0;
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf(
					/* translators: 1: Menu name, 2: Menu ID, 3: Number of menu items */
					__( 'You are about to delete menu "%1$s" (ID: %2$d) with %3$d item(s). This is a permanent delete and cannot be undone. Set confirm: true to proceed.', 'bricks-mcp' ),
					$menu ? $menu->name : "ID $menu_id",
					$menu_id,
					$count
				)
			);
		}

		return $this->menu_service->delete_menu( $menu_id );
	}

	/**
	 * Tool: Get a navigation menu with its items as a nested tree.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Menu data or error.
	 */
	private function tool_get_menu( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['menu_id'] ) || ! is_numeric( $args['menu_id'] ) || (int) $args['menu_id'] <= 0 ) {
			return new \WP_Error(
				'missing_menu_id',
				__( 'menu_id parameter is required and must be a positive integer. Use list_menus to find valid menu IDs.', 'bricks-mcp' )
			);
		}

		return $this->menu_service->get_menu( (int) $args['menu_id'] );
	}

	/**
	 * Tool: List all navigation menus.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused for this tool).
	 * @return array<string, mixed>|\WP_Error List of menus with counts and locations.
	 */
	private function tool_list_menus( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return $this->menu_service->list_menus();
	}

	/**
	 * Tool: Replace all items in a navigation menu with a new nested tree.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	private function tool_set_menu_items( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['menu_id'] ) || ! is_numeric( $args['menu_id'] ) || (int) $args['menu_id'] <= 0 ) {
			return new \WP_Error(
				'missing_menu_id',
				__( 'menu_id parameter is required and must be a positive integer. Use list_menus to find valid menu IDs.', 'bricks-mcp' )
			);
		}

		if ( ! isset( $args['items'] ) || ! is_array( $args['items'] ) ) {
			return new \WP_Error(
				'missing_items',
				__( 'items parameter is required and must be an array.', 'bricks-mcp' )
			);
		}

		return $this->menu_service->set_menu_items( (int) $args['menu_id'], $args['items'] );
	}

	/**
	 * Tool: Assign a navigation menu to a theme menu location.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Assignment result or error.
	 */
	private function tool_assign_menu( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['menu_id'] ) || ! is_numeric( $args['menu_id'] ) || (int) $args['menu_id'] <= 0 ) {
			return new \WP_Error(
				'missing_menu_id',
				__( 'menu_id parameter is required and must be a positive integer. Use list_menus to find valid menu IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['location'] ) || ! is_string( $args['location'] ) ) {
			return new \WP_Error(
				'missing_location',
				__( 'location parameter is required and must be a non-empty string. Use list_menu_locations to see available slugs.', 'bricks-mcp' )
			);
		}

		return $this->menu_service->assign_menu( (int) $args['menu_id'], $args['location'] );
	}

	/**
	 * Tool: Remove a menu from a theme location without deleting it.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Unassignment result or error.
	 */
	private function tool_unassign_menu( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['location'] ) || ! is_string( $args['location'] ) ) {
			return new \WP_Error(
				'missing_location',
				__( 'location parameter is required and must be a non-empty string. Use list_menu_locations to see current assignments.', 'bricks-mcp' )
			);
		}

		return $this->menu_service->unassign_menu( $args['location'] );
	}

	/**
	 * Tool: List all registered theme menu locations with current assignments.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused for this tool).
	 * @return array<string, mixed>|\WP_Error Locations list with assignment data.
	 */
	private function tool_list_menu_locations( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return $this->menu_service->list_locations();
	}

	/**
	 * Register the menu tool with the given registry.
	 *
	 * @param ToolRegistry $registry Tool registry instance.
	 * @return void
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
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
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'handle' )
		);
	}
}
