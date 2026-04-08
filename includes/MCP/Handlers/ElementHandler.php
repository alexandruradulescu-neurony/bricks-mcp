<?php
/**
 * Element handler for MCP Router.
 *
 * Manages element CRUD, conditions, movement, and bulk operations.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\BricksService;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles element tool actions.
 */
final class ElementHandler {

	/**
	 * Bricks service instance.
	 *
	 * @var BricksService
	 */
	private BricksService $bricks_service;

	/**
	 * Constructor.
	 *
	 * @param BricksService $bricks_service Bricks service instance.
	 */
	public function __construct( BricksService $bricks_service ) {
		$this->bricks_service = $bricks_service;
	}

	/**
	 * Handle an element tool action.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: action).
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$action = sanitize_text_field( $args['action'] ?? '' );

		return match ( $action ) {
			'add'            => $this->tool_add_element( $args ),
			'update'         => $this->tool_update_element( $args ),
			'remove'         => $this->tool_remove_element( $args ),
			'get_conditions' => $this->tool_get_conditions( $args ),
			'set_conditions' => $this->tool_set_conditions( $args ),
			'move'           => $this->tool_move_element( $args ),
			'bulk_update'    => $this->tool_bulk_update_elements( $args ),
			'bulk_add'       => $this->tool_bulk_add_elements( $args ),
			'duplicate'      => $this->tool_duplicate_element( $args ),
			'find'           => $this->tool_find_elements( $args ),
			default          => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: add, update, remove, get_conditions, set_conditions, move, bulk_update, bulk_add, duplicate, find', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Add a single element to an existing Bricks page.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Element info or error.
	 */
	private function tool_add_element( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error( 'missing_name', __( 'name is required. Provide the Bricks element type (e.g. heading, container, section).', 'bricks-mcp' ) );
		}

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( (int) $args['post_id'] );
		if ( $protected ) {
			return $protected;
		}

		$post_id   = (int) $args['post_id'];
		$parent_id = isset( $args['parent_id'] ) ? (string) $args['parent_id'] : '0';
		$position  = isset( $args['position'] ) ? (int) $args['position'] : null;
		$element   = array(
			'name'     => sanitize_text_field( $args['name'] ),
			'settings' => isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : array(),
		);

		return $this->bricks_service->add_element( $post_id, $element, $parent_id, $position );
	}

	/**
	 * Tool: Update settings for a specific element.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Update info or error.
	 */
	private function tool_update_element( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['element_id'] ) ) {
			return new \WP_Error( 'missing_element_id', __( 'element_id is required. Use page:get to retrieve element IDs.', 'bricks-mcp' ) );
		}

		if ( ! isset( $args['settings'] ) || ! is_array( $args['settings'] ) ) {
			return new \WP_Error( 'missing_settings', __( 'settings object is required. Provide the settings keys and values to update.', 'bricks-mcp' ) );
		}

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( (int) $args['post_id'] );
		if ( $protected ) {
			return $protected;
		}

		$post_id    = (int) $args['post_id'];
		$element_id = sanitize_text_field( $args['element_id'] );
		$settings   = $args['settings'];

		return $this->bricks_service->update_element( $post_id, $element_id, $settings );
	}

	/**
	 * Tool: Remove an element from a Bricks page.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Removal info or error.
	 */
	private function tool_remove_element( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['element_id'] ) ) {
			return new \WP_Error( 'missing_element_id', __( 'element_id is required. Use page:get to retrieve element IDs.', 'bricks-mcp' ) );
		}

		$post_id    = (int) $args['post_id'];
		$element_id = sanitize_text_field( $args['element_id'] );
		$cascade    = ! empty( $args['cascade'] );

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( $post_id );
		if ( $protected ) {
			return $protected;
		}

		// Confirm check: only when cascade is true and would remove >3 descendants.
		if ( $cascade && empty( $args['confirm'] ) ) {
			$elements = $this->bricks_service->get_elements( $post_id );
			// Count descendants via BFS.
			$children_map = array();
			foreach ( $elements as $el ) {
				$eid = $el['id'] ?? '';
				if ( ! empty( $el['children'] ) ) {
					$children_map[ $eid ] = $el['children'];
				}
			}
			$descendant_count = 0;
			$queue            = $children_map[ $element_id ] ?? array();
			while ( ! empty( $queue ) ) {
				$cid = array_shift( $queue );
				++$descendant_count;
				if ( ! empty( $children_map[ $cid ] ) ) {
					foreach ( $children_map[ $cid ] as $grandchild ) {
						$queue[] = $grandchild;
					}
				}
			}
			if ( $descendant_count > 3 ) {
				return new \WP_Error(
					'bricks_mcp_confirm_required',
					sprintf(
						__( 'Cascade remove of element "%s" would also delete %d descendant element(s). Set confirm: true to proceed.', 'bricks-mcp' ),
						$element_id,
						$descendant_count
					)
				);
			}
		}

		return $this->bricks_service->remove_element( $post_id, $element_id, $cascade );
	}

	/**
	 * Tool: Move or reorder element within page.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	private function tool_move_element( array $args ): array|\WP_Error {
		$post_id          = (int) ( $args['post_id'] ?? 0 );
		$element_id       = sanitize_text_field( $args['element_id'] ?? '' );
		$target_parent_id = sanitize_text_field( $args['target_parent_id'] ?? '' );
		$position         = isset( $args['position'] ) ? (int) $args['position'] : null;

		if ( 0 === $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}
		if ( '' === $element_id ) {
			return new \WP_Error( 'missing_element_id', __( 'element_id is required.', 'bricks-mcp' ) );
		}

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( $post_id );
		if ( $protected ) {
			return $protected;
		}

		return $this->bricks_service->move_element( $post_id, $element_id, $target_parent_id, $position );
	}

	/**
	 * Tool: Bulk update element settings.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	private function tool_bulk_update_elements( array $args ): array|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$updates = $args['updates'] ?? [];

		if ( 0 === $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}
		if ( empty( $updates ) || ! is_array( $updates ) ) {
			return new \WP_Error( 'missing_updates', __( 'updates array is required with at least one {element_id, settings} object.', 'bricks-mcp' ) );
		}

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( $post_id );
		if ( $protected ) {
			return $protected;
		}

		return $this->bricks_service->bulk_update_elements( $post_id, $updates );
	}

	/**
	 * Tool: Bulk add multiple elements to a page.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id', 'elements', optional 'parent_id', 'position'.
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	private function tool_bulk_add_elements( array $args ): array|\WP_Error {
		$post_id  = (int) ( $args['post_id'] ?? 0 );
		$elements = $args['elements'] ?? [];

		if ( 0 === $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}
		if ( empty( $elements ) || ! is_array( $elements ) ) {
			return new \WP_Error( 'missing_elements', __( 'elements array is required for bulk_add.', 'bricks-mcp' ) );
		}

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( $post_id );
		if ( $protected ) {
			return $protected;
		}

		$parent_id = isset( $args['parent_id'] ) ? sanitize_text_field( (string) $args['parent_id'] ) : '0';
		$position  = isset( $args['position'] ) ? (int) $args['position'] : null;

		return $this->bricks_service->append_elements( $post_id, $elements, $parent_id, $position );
	}

	/**
	 * Tool: Duplicate an element and all its descendants.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	private function tool_duplicate_element( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}
		if ( empty( $args['element_id'] ) ) {
			return new \WP_Error( 'missing_element_id', __( 'element_id is required.', 'bricks-mcp' ) );
		}

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( (int) $args['post_id'] );
		if ( $protected ) {
			return $protected;
		}

		$post_id         = (int) $args['post_id'];
		$element_id      = sanitize_text_field( $args['element_id'] );
		$target_parent   = isset( $args['target_parent_id'] ) ? sanitize_text_field( $args['target_parent_id'] ) : null;
		$position        = isset( $args['position'] ) ? (int) $args['position'] : null;

		return $this->bricks_service->duplicate_element( $post_id, $element_id, $target_parent, $position );
	}

	/**
	 * Tool: Find elements matching criteria.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Search results or error.
	 */
	private function tool_find_elements( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}

		$criteria = [];
		if ( ! empty( $args['type'] ) ) {
			$criteria['type'] = sanitize_text_field( $args['type'] );
		}
		if ( ! empty( $args['class_id'] ) ) {
			$criteria['class_id'] = sanitize_text_field( $args['class_id'] );
		}
		if ( ! empty( $args['has_setting'] ) ) {
			$criteria['has_setting'] = sanitize_text_field( $args['has_setting'] );
		}
		if ( ! empty( $args['text_contains'] ) ) {
			$criteria['text_contains'] = sanitize_text_field( $args['text_contains'] );
		}

		return $this->bricks_service->find_elements( (int) $args['post_id'], $criteria );
	}

	/**
	 * Tool: Get element visibility conditions.
	 *
	 * Returns the raw _conditions settings from a specific element on a page.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id' and 'element_id'.
	 * @return array<string, mixed>|\WP_Error Conditions data or error.
	 */
	private function tool_get_conditions( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}

		if ( empty( $args['element_id'] ) ) {
			return new \WP_Error( 'missing_element_id', __( 'element_id is required.', 'bricks-mcp' ) );
		}

		$post_id    = (int) $args['post_id'];
		$element_id = sanitize_text_field( $args['element_id'] );
		$post       = get_post( $post_id );

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

		$elements = $this->bricks_service->get_elements( $post_id );
		$target   = null;

		foreach ( $elements as $element ) {
			if ( ( $element['id'] ?? '' ) === $element_id ) {
				$target = $element;
				break;
			}
		}

		if ( null === $target ) {
			return new \WP_Error(
				'element_not_found',
				sprintf(
					/* translators: 1: Element ID, 2: Post ID */
					__( 'Element "%1$s" not found on post %2$d.', 'bricks-mcp' ),
					$element_id,
					$post_id
				)
			);
		}

		$conditions = $target['settings']['_conditions'] ?? array();

		return array(
			'post_id'        => $post_id,
			'element_id'     => $element_id,
			'element_name'   => $target['name'] ?? 'unknown',
			'has_conditions' => ! empty( $conditions ),
			'condition_sets' => count( $conditions ),
			'conditions'     => $conditions,
			'note'           => empty( $conditions )
				? __( 'No conditions set on this element. Use element:set_conditions to add visibility conditions. Call bricks:get_condition_schema for available condition types.', 'bricks-mcp' )
				: __( 'Outer array = OR logic (any set renders element). Inner arrays = AND logic (all conditions in a set must pass). Use element:set_conditions to replace.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Set element visibility conditions.
	 *
	 * Validates condition structure (2-level array nesting, key whitelist, user role
	 * validation) and sets _conditions on a specific element. Accepts full Bricks
	 * condition format only -- no simplified shorthand.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id', 'element_id', 'conditions'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	private function tool_set_conditions( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}

		if ( empty( $args['element_id'] ) ) {
			return new \WP_Error( 'missing_element_id', __( 'element_id is required.', 'bricks-mcp' ) );
		}

		if ( ! isset( $args['conditions'] ) ) {
			return new \WP_Error( 'missing_conditions', __( 'conditions is required. Pass an array of condition sets, or an empty array [] to clear all conditions.', 'bricks-mcp' ) );
		}

		if ( ! is_array( $args['conditions'] ) ) {
			return new \WP_Error( 'invalid_conditions', __( 'conditions must be an array. Pass an array of condition sets (array of arrays of condition objects), or an empty array [] to clear.', 'bricks-mcp' ) );
		}

		$post_id    = (int) $args['post_id'];
		$element_id = sanitize_text_field( $args['element_id'] );
		$conditions = $args['conditions'];
		$post       = get_post( $post_id );

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

		$elements = $this->bricks_service->get_elements( $post_id );
		$target_index = null;

		foreach ( $elements as $index => $element ) {
			if ( ( $element['id'] ?? '' ) === $element_id ) {
				$target_index = $index;
				break;
			}
		}

		if ( null === $target_index ) {
			return new \WP_Error(
				'element_not_found',
				sprintf(
					/* translators: 1: Element ID, 2: Post ID */
					__( 'Element "%1$s" not found on post %2$d.', 'bricks-mcp' ),
					$element_id,
					$post_id
				)
			);
		}

		$warnings = array();

		// Known condition keys from Bricks conditions.php.
		$known_keys = array(
			'post_id', 'post_title', 'post_parent', 'post_status', 'post_author', 'post_date', 'featured_image',
			'user_logged_in', 'user_id', 'user_registered', 'user_role',
			'weekday', 'date', 'time', 'datetime',
			'dynamic_data', 'browser', 'operating_system', 'current_url', 'referer',
			'woo_product_type', 'woo_product_sale', 'woo_product_new', 'woo_product_stock_status',
			'woo_product_stock_quantity', 'woo_product_stock_management', 'woo_product_sold_individually',
			'woo_product_purchased_by_user', 'woo_product_featured', 'woo_product_rating',
			'woo_product_category', 'woo_product_tag',
		);

		$valid_compare = array( '==', '!=', '>=', '<=', '>', '<', 'contains', 'contains_not', 'empty', 'empty_not' );

		// Validate condition structure: must be array of arrays of condition objects.
		foreach ( $conditions as $set_index => $condition_set ) {
			if ( ! is_array( $condition_set ) ) {
				return new \WP_Error(
					'invalid_condition_structure',
					sprintf(
						/* translators: %d: Set index */
						__( 'Condition set at index %d must be an array of condition objects. Expected format: [[{key, compare, value}, ...], ...]. Each outer element is a condition set (OR logic), each inner element is a condition (AND logic within set).', 'bricks-mcp' ),
						$set_index
					)
				);
			}

			foreach ( $condition_set as $cond_index => $condition ) {
				if ( ! is_array( $condition ) ) {
					return new \WP_Error(
						'invalid_condition_object',
						sprintf(
							/* translators: 1: Condition index, 2: Set index */
							__( 'Condition at index %1$d in set %2$d must be an object with at least a "key" field. Example: {"key": "user_logged_in", "compare": "==", "value": "1"}', 'bricks-mcp' ),
							$cond_index,
							$set_index
						)
					);
				}

				$key = $condition['key'] ?? null;

				if ( null === $key || '' === $key ) {
					return new \WP_Error(
						'missing_condition_key',
						sprintf(
							/* translators: 1: Condition index, 2: Set index */
							__( 'Condition at index %1$d in set %2$d is missing required "key" field.', 'bricks-mcp' ),
							$cond_index,
							$set_index
						)
					);
				}

				// Validate key against known keys -- warn on unknown (3rd-party plugins may add custom keys).
				if ( ! in_array( $key, $known_keys, true ) ) {
					$warnings[] = sprintf(
						/* translators: 1: Key name, 2: Set index, 3: Condition index */
						__( 'Unknown condition key "%1$s" at set %2$d, condition %3$d. This may be from a third-party plugin -- saving anyway.', 'bricks-mcp' ),
						$key,
						$set_index,
						$cond_index
					);
				}

				// Validate user_role values against wp_roles -- reject unknown roles per CONTEXT.md decision.
				if ( 'user_role' === $key && isset( $condition['value'] ) ) {
					$role_values = is_array( $condition['value'] ) ? $condition['value'] : array( $condition['value'] );
					$valid_roles = array_keys( wp_roles()->get_names() );
					$invalid     = array_diff( $role_values, $valid_roles );

					if ( ! empty( $invalid ) ) {
						return new \WP_Error(
							'invalid_user_role',
							sprintf(
								/* translators: 1: Invalid role names, 2: Valid role names */
								__( 'Unknown user role(s): %1$s. Valid roles: %2$s.', 'bricks-mcp' ),
								implode( ', ', $invalid ),
								implode( ', ', $valid_roles )
							)
						);
					}
				}

				// Validate dynamic_data field presence when key is dynamic_data.
				if ( 'dynamic_data' === $key && empty( $condition['dynamic_data'] ) ) {
					$warnings[] = sprintf(
						/* translators: 1: Set index, 2: Condition index */
						__( 'Condition at set %1$d, condition %2$d has key "dynamic_data" but no "dynamic_data" field for the tag. The "dynamic_data" field should contain the tag to evaluate (e.g. "{acf_my_field}"), and "value" should contain the comparison target.', 'bricks-mcp' ),
						$set_index,
						$cond_index
					);
				}

				// Validate compare operator -- warn on unknown.
				if ( isset( $condition['compare'] ) && ! in_array( $condition['compare'], $valid_compare, true ) ) {
					$warnings[] = sprintf(
						/* translators: 1: Operator, 2: Set index, 3: Condition index */
						__( 'Unknown compare operator "%1$s" at set %2$d, condition %3$d. Known operators: ==, !=, >=, <=, >, <, contains, contains_not, empty, empty_not.', 'bricks-mcp' ),
						$condition['compare'],
						$set_index,
						$cond_index
					);
				}
			}
		}

		// Set conditions on the element. Empty array clears all conditions.
		if ( empty( $conditions ) ) {
			unset( $elements[ $target_index ]['settings']['_conditions'] );
		} else {
			$elements[ $target_index ]['settings']['_conditions'] = $conditions;
		}

		// Save elements back via save_elements() which resolves the correct meta key.
		$saved = $this->bricks_service->save_elements( $post_id, $elements );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$result = array(
			'post_id'        => $post_id,
			'element_id'     => $element_id,
			'element_name'   => $elements[ $target_index ]['name'] ?? 'unknown',
			'condition_sets' => count( $conditions ),
			'action'         => empty( $conditions ) ? 'cleared' : 'set',
		);

		if ( ! empty( $warnings ) ) {
			$result['warnings'] = $warnings;
		}

		return $result;
	}

}
