<?php
/**
 * MetaBox handler for MCP Router.
 *
 * Handles Meta Box custom field operations.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles MetaBox tool actions.
 */
final class MetaBoxHandler {

	/**
	 * Handle MetaBox tool actions.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$action = sanitize_text_field( $args['action'] ?? '' );

		// Check if MetaBox is available.
		if ( ! function_exists( 'rwmb_meta' ) ) {
			return new \WP_Error(
				'bricks_mcp_metabox_not_active',
				'Meta Box plugin is not installed or activated. Install it from https://metabox.io/'
			);
		}

		return match ( $action ) {
			'list_field_groups' => $this->tool_list_field_groups(),
			'get_fields'        => $this->tool_get_fields( $args ),
			'get_field_value'   => $this->tool_get_field_value( $args ),
			'get_dynamic_tags'  => $this->tool_get_dynamic_tags( $args ),
			default             => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list_field_groups, get_fields, get_field_value, get_dynamic_tags', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * List all field groups with their fields.
	 *
	 * @return array<string, mixed> Field groups data.
	 */
	private function tool_list_field_groups(): array {
		if ( ! function_exists( 'rwmb_get_registry' ) ) {
			return array( 'field_groups' => array(), 'message' => 'Meta Box registry not available.' );
		}

		$registry = rwmb_get_registry( 'meta_box' );
		$all      = $registry->all();
		$groups   = array();

		foreach ( $all as $meta_box ) {
			$fields = array();
			foreach ( $meta_box->fields as $field ) {
				$field_info = array(
					'id'   => $field['id'] ?? '',
					'name' => $field['name'] ?? '',
					'type' => $field['type'] ?? '',
				);
				if ( ! empty( $field['options'] ) ) {
					$field_info['options'] = $field['options'];
				}
				if ( ! empty( $field['clone'] ) ) {
					$field_info['cloneable'] = true;
				}
				if ( ! empty( $field['multiple'] ) ) {
					$field_info['multiple'] = true;
				}
				$fields[] = $field_info;
			}

			$groups[] = array(
				'id'         => $meta_box->id,
				'title'      => $meta_box->title,
				'post_types' => $meta_box->post_types ?? array(),
				'fields'     => $fields,
			);
		}

		return array( 'field_groups' => $groups, 'total' => count( $groups ) );
	}

	/**
	 * Get fields for a specific post type.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'post_type'.
	 * @return array<string, mixed>|\WP_Error Fields data or error.
	 */
	private function tool_get_fields( array $args ): array|\WP_Error {
		$post_type = sanitize_text_field( $args['post_type'] ?? '' );
		if ( empty( $post_type ) ) {
			return new \WP_Error( 'missing_post_type', 'post_type is required for get_fields.' );
		}

		if ( ! function_exists( 'rwmb_get_registry' ) ) {
			return array( 'fields' => array() );
		}

		$registry = rwmb_get_registry( 'meta_box' );
		$all      = $registry->all();
		$fields   = array();

		foreach ( $all as $meta_box ) {
			$box_post_types = $meta_box->post_types ?? array();
			if ( ! in_array( $post_type, $box_post_types, true ) ) {
				continue;
			}
			foreach ( $meta_box->fields as $field ) {
				$fields[] = array(
					'id'          => $field['id'] ?? '',
					'name'        => $field['name'] ?? '',
					'type'        => $field['type'] ?? '',
					'group'       => $meta_box->title,
					'dynamic_tag' => '{mb_' . ( $field['id'] ?? '' ) . '}',
				);
			}
		}

		return array( 'post_type' => $post_type, 'fields' => $fields, 'total' => count( $fields ) );
	}

	/**
	 * Get a field value for a specific post.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'post_id' and 'field_id'.
	 * @return array<string, mixed>|\WP_Error Field value data or error.
	 */
	private function tool_get_field_value( array $args ): array|\WP_Error {
		$post_id  = (int) ( $args['post_id'] ?? 0 );
		$field_id = sanitize_text_field( $args['field_id'] ?? '' );

		if ( 0 === $post_id ) {
			return new \WP_Error( 'missing_post_id', 'post_id is required for get_field_value.' );
		}
		if ( empty( $field_id ) ) {
			return new \WP_Error( 'missing_field_id', 'field_id is required for get_field_value.' );
		}

		$value = rwmb_meta( $field_id, array(), $post_id );

		// Handle different value types.
		if ( is_array( $value ) ) {
			$simplified = array();
			foreach ( $value as $item ) {
				if ( is_array( $item ) && isset( $item['url'] ) ) {
					$simplified[] = array(
						'id'    => $item['ID'] ?? 0,
						'url'   => $item['url'],
						'title' => $item['title'] ?? '',
					);
				} else {
					$simplified[] = $item;
				}
			}
			$value = $simplified;
		}

		return array(
			'post_id'  => $post_id,
			'field_id' => $field_id,
			'value'    => $value,
		);
	}

	/**
	 * Get available dynamic data tags for Bricks.
	 *
	 * @param array<string, mixed> $args Tool arguments with optional 'post_type' filter.
	 * @return array<string, mixed> Dynamic tags data.
	 */
	private function tool_get_dynamic_tags( array $args ): array {
		$post_type = sanitize_text_field( $args['post_type'] ?? '' );

		if ( ! function_exists( 'rwmb_get_registry' ) ) {
			return array( 'tags' => array(), 'message' => 'Meta Box not active.' );
		}

		$registry = rwmb_get_registry( 'meta_box' );
		$all      = $registry->all();
		$tags     = array();

		foreach ( $all as $meta_box ) {
			if ( ! empty( $post_type ) ) {
				$box_post_types = $meta_box->post_types ?? array();
				if ( ! in_array( $post_type, $box_post_types, true ) ) {
					continue;
				}
			}
			foreach ( $meta_box->fields as $field ) {
				$fid  = $field['id'] ?? '';
				$type = $field['type'] ?? 'text';

				$tag_info = array(
					'field_id'    => $fid,
					'field_name'  => $field['name'] ?? '',
					'field_type'  => $type,
					'dynamic_tag' => '{mb_' . $fid . '}',
				);

				// Add usage hints based on field type.
				if ( in_array( $type, array( 'image', 'image_advanced', 'image_upload', 'single_image', 'file', 'file_advanced', 'file_upload' ), true ) ) {
					$tag_info['usage'] = 'Use in image element: {"useDynamicData": "{mb_' . $fid . '}"}';
				} elseif ( in_array( $type, array( 'url', 'post', 'taxonomy' ), true ) ) {
					$tag_info['usage'] = 'Use in link: {"type": "dynamic", "dynamicData": "{mb_' . $fid . '}"}';
				} else {
					$tag_info['usage'] = 'Use in text elements: {mb_' . $fid . '}';
				}

				$tags[] = $tag_info;
			}
		}

		return array( 'tags' => $tags, 'total' => count( $tags ), 'post_type_filter' => $post_type ?: 'all' );
	}
}
