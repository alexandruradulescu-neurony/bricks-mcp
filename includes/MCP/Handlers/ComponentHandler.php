<?php
/**
 * Component handler for MCP Router.
 *
 * Manages Bricks Builder component definitions and instances.
 * Components operate directly on the bricks_components wp_option.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\ElementIdGenerator;
use BricksMCP\MCP\ToolRegistry;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles component tool actions.
 */
final class ComponentHandler {

	/**
	 * WordPress option name for Bricks component definitions.
	 *
	 * @var string
	 */
	private const COMPONENTS_OPTION = 'bricks_components';

	/**
	 * Bricks service instance.
	 *
	 * @var BricksService
	 */
	private BricksService $bricks_service;

	/**
	 * Bricks check callback.
	 *
	 * @var callable
	 */
	private $require_bricks;

	/**
	 * Constructor.
	 *
	 * @param BricksService $bricks_service Bricks service instance.
	 * @param callable      $require_bricks Callback that returns \WP_Error|null.
	 */
	public function __construct( BricksService $bricks_service, callable $require_bricks ) {
		$this->bricks_service = $bricks_service;
		$this->require_bricks = $require_bricks;
	}

	/**
	 * Handle a component tool action.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: action).
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action = sanitize_text_field( $args['action'] ?? '' );

		return match ( $action ) {
			'list'              => $this->tool_list_components( $args ),
			'get'               => $this->tool_get_component( $args ),
			'create'            => $this->tool_create_component( $args ),
			'update'            => $this->tool_update_component( $args ),
			'delete'            => $this->tool_delete_component( $args ),
			'instantiate'       => $this->tool_instantiate_component( $args ),
			'update_properties' => $this->tool_update_instance_properties( $args ),
			'fill_slot'         => $this->tool_fill_slot( $args ),
			default             => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, get, create, update, delete, instantiate, update_properties, fill_slot', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: List all component definitions with summary metadata.
	 *
	 * @param array<string, mixed> $args Tool arguments (optional: category filter).
	 * @return array<string, mixed> Components list with total count.
	 */
	private function tool_list_components( array $args ): array {
		$components      = get_option( self::COMPONENTS_OPTION, array() );
		$category_filter = isset( $args['category'] ) ? strtolower( sanitize_text_field( $args['category'] ) ) : '';

		$result = array();
		foreach ( $components as $component ) {
			if ( '' !== $category_filter ) {
				$comp_category = strtolower( $component['category'] ?? '' );
				if ( $comp_category !== $category_filter ) {
					continue;
				}
			}

			$elements = $component['elements'] ?? array();
			$result[] = array(
				'id'             => $component['id'],
				'label'          => $component['label'] ?? '',
				'category'       => $component['category'] ?? '',
				'description'    => $component['description'] ?? '',
				'element_count'  => count( $elements ),
				'slot_count'     => count( array_filter( $elements, fn( $el ) => ( $el['name'] ?? '' ) === 'slot' ) ),
				'property_count' => count( $component['properties'] ?? array() ),
			);
		}

		return array(
			'components' => $result,
			'total'      => count( $result ),
		);
	}

	/**
	 * Tool: Get a single component's full definition.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: component_id).
	 * @return array<string, mixed>|\WP_Error Component data or error.
	 */
	private function tool_get_component( array $args ): array|\WP_Error {
		if ( empty( $args['component_id'] ) ) {
			return new \WP_Error( 'missing_component_id', __( 'component_id is required. Use component:list to find component IDs.', 'bricks-mcp' ) );
		}

		$component_id = sanitize_text_field( $args['component_id'] );
		$components   = get_option( self::COMPONENTS_OPTION, array() );
		$index        = array_search( $component_id, array_column( $components, 'id' ), true );

		if ( false === $index ) {
			return new \WP_Error(
				'component_not_found',
				sprintf(
					/* translators: %s: Component ID */
					__( 'Component "%s" not found. Use component:list to see available components.', 'bricks-mcp' ),
					$component_id
				)
			);
		}

		$component = $components[ $index ];
		$elements  = $component['elements'] ?? array();

		// Enrich with computed metadata.
		$component['element_count']  = count( $elements );
		$component['slot_count']     = count( array_filter( $elements, fn( $el ) => ( $el['name'] ?? '' ) === 'slot' ) );
		$component['slot_ids']       = array_values( array_map(
			fn( $el ) => $el['id'],
			array_filter( $elements, fn( $el ) => ( $el['name'] ?? '' ) === 'slot' )
		) );
		$component['property_count'] = count( $component['properties'] ?? array() );

		return $component;
	}

	/**
	 * Tool: Create a new component from a label and element tree.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: label, elements).
	 * @return array<string, mixed>|\WP_Error Created component summary or error.
	 */
	private function tool_create_component( array $args ): array|\WP_Error {
		if ( empty( $args['label'] ) ) {
			return new \WP_Error( 'missing_label', __( 'label is required. Provide a display name for the component.', 'bricks-mcp' ) );
		}

		if ( empty( $args['elements'] ) || ! is_array( $args['elements'] ) ) {
			return new \WP_Error( 'missing_elements', __( 'elements is required. Provide a non-empty flat element array (same structure as page content).', 'bricks-mcp' ) );
		}

		$label      = sanitize_text_field( $args['label'] );
		$category   = isset( $args['category'] ) ? sanitize_text_field( $args['category'] ) : '';
		$desc       = isset( $args['description'] ) ? sanitize_text_field( $args['description'] ) : '';
		$elements   = $args['elements'];
		$properties = isset( $args['properties'] ) && is_array( $args['properties'] ) ? $args['properties'] : array();

		$components   = get_option( self::COMPONENTS_OPTION, array() );
		$id_generator = new ElementIdGenerator();
		$component_id = $id_generator->generate_unique( $components );

		// Prevent collision with registered Bricks element names.
		if ( class_exists( '\Bricks\Elements' ) && isset( \Bricks\Elements::$elements ) ) {
			$registered_names = array_keys( \Bricks\Elements::$elements );
			$max_retries      = 50;
			$retries          = 0;
			while ( in_array( $component_id, $registered_names, true ) && $retries < $max_retries ) {
				$component_id = $id_generator->generate_unique( $components );
				++$retries;
			}
		}

		// Set root element ID to match component ID.
		if ( empty( $elements ) ) {
			return new \WP_Error( 'empty_elements', __( 'Elements array is empty after normalization.', 'bricks-mcp' ) );
		}
		if ( ! is_array( $elements[0] ) ) {
			return new \WP_Error( 'invalid_root_element', __( 'Root element has invalid shape — expected array, got non-array.', 'bricks-mcp' ) );
		}
		$elements[0]['id']     = $component_id;
		$elements[0]['parent'] = 0;

		$new_component = array(
			'id'          => $component_id,
			'label'       => $label,
			'category'    => $category,
			'description' => $desc,
			'elements'    => $elements,
			'properties'  => $properties,
		);

		$components[] = $new_component;
		update_option( self::COMPONENTS_OPTION, $components );

		$slot_count = count( array_filter( $elements, fn( $el ) => ( $el['name'] ?? '' ) === 'slot' ) );

		return array(
			'created'        => true,
			'id'             => $component_id,
			'label'          => $label,
			'category'       => $category,
			'element_count'  => count( $elements ),
			'slot_count'     => $slot_count,
			'property_count' => count( $properties ),
		);
	}

	/**
	 * Tool: Update an existing component definition.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: component_id; optional: label, category, description, elements, properties).
	 * @return array<string, mixed>|\WP_Error Updated component summary or error.
	 */
	private function tool_update_component( array $args ): array|\WP_Error {
		if ( empty( $args['component_id'] ) ) {
			return new \WP_Error( 'missing_component_id', __( 'component_id is required. Use component:list to find component IDs.', 'bricks-mcp' ) );
		}

		$component_id = sanitize_text_field( $args['component_id'] );
		$components   = get_option( self::COMPONENTS_OPTION, array() );
		$index        = array_search( $component_id, array_column( $components, 'id' ), true );

		if ( false === $index ) {
			return new \WP_Error(
				'component_not_found',
				sprintf(
					/* translators: %s: Component ID */
					__( 'Component "%s" not found. Use component:list to see available components.', 'bricks-mcp' ),
					$component_id
				)
			);
		}

		// Merge allowed fields.
		$allowed_fields = array( 'label', 'category', 'description', 'elements', 'properties' );
		foreach ( $allowed_fields as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				if ( 'label' === $field || 'category' === $field || 'description' === $field ) {
					$components[ $index ][ $field ] = sanitize_text_field( $args[ $field ] );
				} else {
					$components[ $index ][ $field ] = $args[ $field ];
				}
			}
		}

		// Enforce root element ID = component ID if elements were updated.
		if ( isset( $args['elements'] ) && is_array( $args['elements'] ) && ! empty( $args['elements'] ) ) {
			$components[ $index ]['elements'][0]['id']     = $component_id;
			$components[ $index ]['elements'][0]['parent'] = 0;
		}

		update_option( self::COMPONENTS_OPTION, $components );

		$updated  = $components[ $index ];
		$elements = $updated['elements'] ?? array();

		return array(
			'updated'        => true,
			'id'             => $component_id,
			'label'          => $updated['label'] ?? '',
			'category'       => $updated['category'] ?? '',
			'element_count'  => count( $elements ),
			'slot_count'     => count( array_filter( $elements, fn( $el ) => ( $el['name'] ?? '' ) === 'slot' ) ),
			'property_count' => count( $updated['properties'] ?? array() ),
		);
	}

	/**
	 * Tool: Delete a component definition by ID.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: component_id).
	 * @return array<string, mixed>|\WP_Error Deletion confirmation or error.
	 */
	private function tool_delete_component( array $args ): array|\WP_Error {
		if ( empty( $args['component_id'] ) ) {
			return new \WP_Error( 'missing_component_id', __( 'component_id is required. Use component:list to find component IDs.', 'bricks-mcp' ) );
		}

		$component_id = sanitize_text_field( $args['component_id'] );
		$components   = get_option( self::COMPONENTS_OPTION, array() );
		$index        = array_search( $component_id, array_column( $components, 'id' ), true );

		if ( false === $index ) {
			return new \WP_Error(
				'component_not_found',
				sprintf(
					/* translators: %s: Component ID */
					__( 'Component "%s" not found. Use component:list to see available components.', 'bricks-mcp' ),
					$component_id
				)
			);
		}

		$label = $components[ $index ]['label'] ?? '';

		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf(
					__( 'You are about to permanently delete component "%s" (ID: %s). Existing instances will render empty. Set confirm: true to proceed.', 'bricks-mcp' ),
					$label,
					$component_id
				)
			);
		}

		array_splice( $components, $index, 1 );
		update_option( self::COMPONENTS_OPTION, $components );

		return array(
			'deleted'      => true,
			'component_id' => $component_id,
			'label'        => $label,
			'note'         => __( 'Existing instances will render empty. Remove instances manually from pages.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Instantiate a component on a page.
	 *
	 * Creates a component instance element in the page's element array.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: component_id, post_id; optional: parent_id, position, properties).
	 * @return array<string, mixed>|\WP_Error Instantiation result or error.
	 */
	private function tool_instantiate_component( array $args ): array|\WP_Error {
		if ( empty( $args['component_id'] ) ) {
			return new \WP_Error( 'missing_component_id', __( 'component_id is required. Use component:list to find component IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		$component_id = sanitize_text_field( $args['component_id'] );
		$post_id      = (int) $args['post_id'];
		$parent_id    = isset( $args['parent_id'] ) ? sanitize_text_field( $args['parent_id'] ) : '0';
		$position     = isset( $args['position'] ) ? (int) $args['position'] : null;

		// Protected page check.
		// check_protected_page returns WP_Error on protected page, null/void otherwise.
		// The previous `if ( $protected )` was inverted — it ran only when no error.
		$protected = $this->bricks_service->check_protected_page( $post_id );
		if ( is_wp_error( $protected ) ) {
			return $protected;
		}

		// Verify component exists.
		$components = get_option( self::COMPONENTS_OPTION, array() );
		if ( ! is_array( $components ) ) {
			$components = array();
		}
		$comp_index = array_search( $component_id, array_column( $components, 'id' ), true );

		if ( false === $comp_index ) {
			return new \WP_Error(
				'component_not_found',
				sprintf(
					/* translators: %s: Component ID */
					__( 'Component "%s" not found. Use component:list to see available components.', 'bricks-mcp' ),
					$component_id
				)
			);
		}

		$component_label = is_array( $components[ $comp_index ] ?? null ) ? ( $components[ $comp_index ]['label'] ?? '' ) : '';

		// Get existing page elements.
		$elements = $this->bricks_service->get_elements( $post_id );
		if ( ! is_array( $elements ) ) {
			$elements = array();
		}

		// Generate unique instance element ID.
		$id_generator = new ElementIdGenerator();
		$instance_id  = $id_generator->generate_unique( $elements );

		// Normalize root-level parent: string '0' → integer 0 (validate_element_linkage uses strict comparison).
		$parent = '0' !== $parent_id ? $parent_id : 0;

		// Build instance element.
		$instance_element = array(
			'id'           => $instance_id,
			'name'         => $component_id,
			'cid'          => $component_id,
			'parent'       => $parent,
			'children'     => array(),
			'settings'     => array(),
			'properties'   => isset( $args['properties'] ) && is_array( $args['properties'] ) ? $args['properties'] : array(),
			'slotChildren' => array(),
		);

		// If parent is specified and not root, validate parent exists and update its children.
		if ( '0' !== $parent_id ) {
			$parent_found = false;
			foreach ( $elements as &$el ) {
				if ( ! is_array( $el ) ) {
					continue;
				}
				if ( ( $el['id'] ?? '' ) === $parent_id ) {
					$parent_found = true;
					if ( ! isset( $el['children'] ) || ! is_array( $el['children'] ) ) {
						$el['children'] = array();
					}
					if ( null !== $position && $position >= 0 && $position <= count( $el['children'] ) ) {
						array_splice( $el['children'], $position, 0, array( $instance_id ) );
					} else {
						$el['children'][] = $instance_id;
					}
					break;
				}
			}
			unset( $el );

			if ( ! $parent_found ) {
				return new \WP_Error(
					'parent_not_found',
					sprintf(
						/* translators: %s: Parent element ID */
						__( 'Parent element "%s" not found on post %d. Use page:get to inspect elements.', 'bricks-mcp' ),
						$parent_id,
						$post_id
					)
				);
			}
		}

		$elements[] = $instance_element;

		$save_result = $this->bricks_service->save_elements( $post_id, $elements );
		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		return array(
			'instantiated'    => true,
			'instance_id'     => $instance_id,
			'component_id'    => $component_id,
			'component_label' => $component_label,
			'post_id'         => $post_id,
			'parent_id'       => $parent_id,
		);
	}

	/**
	 * Tool: Update property values on a component instance.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: post_id, instance_id, properties).
	 * @return array<string, mixed>|\WP_Error Updated properties or error.
	 */
	private function tool_update_instance_properties( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['instance_id'] ) ) {
			return new \WP_Error( 'missing_instance_id', __( 'instance_id is required. Use page:get to find component instance element IDs.', 'bricks-mcp' ) );
		}

		if ( ! isset( $args['properties'] ) || ! is_array( $args['properties'] ) ) {
			return new \WP_Error( 'missing_properties', __( 'properties object is required. Provide property ID to value mappings.', 'bricks-mcp' ) );
		}

		$post_id     = (int) $args['post_id'];
		$instance_id = sanitize_text_field( $args['instance_id'] );

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( $post_id );
		if ( $protected ) {
			return $protected;
		}

		$elements    = $this->bricks_service->get_elements( $post_id );

		$found = false;
		foreach ( $elements as &$element ) {
			if ( $element['id'] === $instance_id ) {
				if ( ! isset( $element['cid'] ) ) {
					return new \WP_Error(
						'not_component_instance',
						sprintf(
							/* translators: %s: Element ID */
							__( 'Element "%s" is not a component instance (missing cid key).', 'bricks-mcp' ),
							$instance_id
						)
					);
				}
				$element['properties'] = array_merge( $element['properties'] ?? array(), $args['properties'] );
				$found                 = true;
				break;
			}
		}
		unset( $element );

		if ( ! $found ) {
			return new \WP_Error(
				'instance_not_found',
				sprintf(
					/* translators: %s: Instance ID */
					__( 'Instance element "%s" not found on post %d. Use page:get to inspect elements.', 'bricks-mcp' ),
					$instance_id,
					$post_id
				)
			);
		}

		$save_result = $this->bricks_service->save_elements( $post_id, $elements );
		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		// Re-find element for response.
		foreach ( $elements as $el ) {
			if ( $el['id'] === $instance_id ) {
				return array(
					'updated'     => true,
					'instance_id' => $instance_id,
					'properties'  => $el['properties'],
				);
			}
		}

		return array(
			'updated'     => true,
			'instance_id' => $instance_id,
			'properties'  => $args['properties'],
		);
	}

	/**
	 * Tool: Fill a slot on a component instance with element content.
	 *
	 * Atomically adds content elements to the page array and updates the
	 * instance element's slotChildren map.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: post_id, instance_id, slot_id, slot_elements).
	 * @return array<string, mixed>|\WP_Error Fill result or error.
	 */
	private function tool_fill_slot( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['instance_id'] ) ) {
			return new \WP_Error( 'missing_instance_id', __( 'instance_id is required. Use page:get to find component instance element IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['slot_id'] ) ) {
			return new \WP_Error( 'missing_slot_id', __( 'slot_id is required. Use component:get to find slot element IDs in the component definition.', 'bricks-mcp' ) );
		}

		if ( empty( $args['slot_elements'] ) || ! is_array( $args['slot_elements'] ) ) {
			return new \WP_Error( 'missing_slot_elements', __( 'slot_elements is required. Provide a non-empty flat element array for the slot content.', 'bricks-mcp' ) );
		}

		$post_id       = (int) $args['post_id'];
		$instance_id   = sanitize_text_field( $args['instance_id'] );
		$slot_id       = sanitize_text_field( $args['slot_id'] );
		$slot_elements = $args['slot_elements'];

		// Protected page check.
		$protected = $this->bricks_service->check_protected_page( $post_id );
		if ( $protected ) {
			return $protected;
		}

		$elements = $this->bricks_service->get_elements( $post_id );

		// Find instance element.
		$instance_index = null;
		foreach ( $elements as $idx => $element ) {
			if ( $element['id'] === $instance_id ) {
				if ( ! isset( $element['cid'] ) ) {
					return new \WP_Error(
						'not_component_instance',
						sprintf(
							/* translators: %s: Element ID */
							__( 'Element "%s" is not a component instance (missing cid key).', 'bricks-mcp' ),
							$instance_id
						)
					);
				}
				$instance_index = $idx;
				break;
			}
		}

		if ( null === $instance_index ) {
			return new \WP_Error(
				'instance_not_found',
				sprintf(
					/* translators: %s: Instance ID */
					__( 'Instance element "%s" not found on post %d. Use page:get to inspect elements.', 'bricks-mcp' ),
					$instance_id,
					$post_id
				)
			);
		}

		// Verify the slot exists in the component definition.
		$component_id = $elements[ $instance_index ]['cid'];
		$components   = get_option( self::COMPONENTS_OPTION, array() );
		$comp_index   = array_search( $component_id, array_column( $components, 'id' ), true );

		if ( false === $comp_index ) {
			return new \WP_Error(
				'component_not_found',
				sprintf(
					/* translators: %s: Component ID */
					__( 'Component definition "%s" not found. The component may have been deleted.', 'bricks-mcp' ),
					$component_id
				)
			);
		}

		$comp_elements = $components[ $comp_index ]['elements'] ?? array();
		$slot_found    = false;
		foreach ( $comp_elements as $comp_el ) {
			if ( ( $comp_el['id'] ?? '' ) === $slot_id && ( $comp_el['name'] ?? '' ) === 'slot' ) {
				$slot_found = true;
				break;
			}
		}

		if ( ! $slot_found ) {
			return new \WP_Error(
				'slot_not_found',
				sprintf(
					/* translators: %1$s: Slot ID, %2$s: Component ID */
					__( 'Slot element "%1$s" not found in component "%2$s". Use component:get to find slot IDs.', 'bricks-mcp' ),
					$slot_id,
					$component_id
				)
			);
		}

		// Generate IDs for slot content elements and set parent to instance.
		$id_generator    = new ElementIdGenerator();
		$new_element_ids = array();

		foreach ( $slot_elements as &$slot_el ) {
			if ( ! is_array( $slot_el ) ) {
				continue;
			}
			// Generate new ID if missing or conflicting.
			$needs_new_id = empty( $slot_el['id'] ) || ! is_string( $slot_el['id'] );
			if ( ! $needs_new_id ) {
				// Check for conflict with existing elements.
				foreach ( $elements as $existing ) {
					if ( ! is_array( $existing ) ) {
						continue;
					}
					if ( ( $existing['id'] ?? null ) === $slot_el['id'] ) {
						$needs_new_id = true;
						break;
					}
				}
			}

			if ( $needs_new_id ) {
				$slot_el['id'] = $id_generator->generate_unique( $elements );
			}

			// Top-level slot content elements get parent = instance_id.
			if ( ! isset( $slot_el['parent'] ) || 0 === $slot_el['parent'] || '0' === $slot_el['parent'] ) {
				$slot_el['parent'] = $instance_id;
			}

			if ( ! isset( $slot_el['children'] ) ) {
				$slot_el['children'] = array();
			}

			$new_element_ids[] = $slot_el['id'];

			// Add to the tracking array for conflict checking.
			$elements[] = $slot_el;
		}
		unset( $slot_el );

		// Update instance element's slotChildren.
		$elements[ $instance_index ]['slotChildren']              = $elements[ $instance_index ]['slotChildren'] ?? array();
		$elements[ $instance_index ]['slotChildren'][ $slot_id ] = $new_element_ids;

		$save_result = $this->bricks_service->save_elements( $post_id, $elements );
		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		return array(
			'filled'         => true,
			'instance_id'    => $instance_id,
			'slot_id'        => $slot_id,
			'elements_added' => count( $slot_elements ),
			'element_ids'    => $new_element_ids,
		);
	}

	/**
	 * Register the component tool with the given registry.
	 *
	 * @param ToolRegistry $registry Tool registry instance.
	 * @return void
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
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
			array( $this, 'handle' )
		);
	}
}
