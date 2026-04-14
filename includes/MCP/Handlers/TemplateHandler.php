<?php
/**
 * Template handler for MCP Router.
 *
 * Manages Bricks templates, template conditions, and template taxonomies.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\ToolRegistry;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles template tool actions.
 */
final class TemplateHandler {

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
	 * Handle a template tool action.
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
			'list'                => $this->tool_list_templates( $args ),
			'get'                 => $this->tool_get_template_content( $args ),
			'create'              => $this->tool_create_template( $args ),
			'update'              => $this->tool_update_template( $args ),
			'delete'              => $this->tool_delete_template( $args ),
			'duplicate'           => $this->tool_duplicate_template( $args ),
			'get_popup_settings'  => $this->tool_get_popup_settings( $args ),
			'set_popup_settings'  => $this->tool_set_popup_settings( $args ),
			'export'              => $this->tool_export_template( $args ),
			'import'              => $this->tool_import_template( $args ),
			'import_url'          => $this->tool_import_template_url( $args ),
			default               => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, get, create, update, delete, duplicate, get_popup_settings, set_popup_settings, export, import, import_url', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Handle a template condition tool action.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: action).
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	public function handle_condition( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action = sanitize_text_field( $args['action'] ?? '' );

		return match ( $action ) {
			'get_types' => $this->tool_get_condition_types( $args ),
			'set'       => $this->tool_set_template_conditions( $args ),
			'resolve'   => $this->tool_resolve_templates( $args ),
			default     => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: get_types, set, resolve', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Handle a template taxonomy tool action.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: action).
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	public function handle_taxonomy( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action = sanitize_text_field( $args['action'] ?? '' );

		return match ( $action ) {
			'list_tags'      => $this->tool_list_template_tags( $args ),
			'list_bundles'   => $this->tool_list_template_bundles( $args ),
			'create_tag'     => $this->tool_create_template_tag( $args ),
			'create_bundle'  => $this->tool_create_template_bundle( $args ),
			'delete_tag'     => $this->tool_delete_template_tag( $args ),
			'delete_bundle'  => $this->tool_delete_template_bundle( $args ),
			default          => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list_tags, list_bundles, create_tag, create_bundle, delete_tag, delete_bundle', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: List templates with optional type/status/tag/bundle filters.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Templates list or error.
	 */
	private function tool_list_templates( array $args ): array|\WP_Error {
		$type      = sanitize_key( $args['type'] ?? '' );
		$status    = sanitize_key( $args['status'] ?? 'publish' );
		$tag       = sanitize_key( $args['tag'] ?? '' );
		$bundle    = sanitize_key( $args['bundle'] ?? '' );
		$templates = $this->bricks_service->get_templates( $type, $status, $tag, $bundle );

		return array(
			'total'     => count( $templates ),
			'templates' => $templates,
		);
	}

	/**
	 * Tool: Create a new Bricks template.
	 *
	 * Creates a bricks_template post with type, optional conditions.
	 * Returns full template data to save an extra get_template_content call.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Template data or error.
	 */
	private function tool_create_template( array $args ): array|\WP_Error {
		if ( empty( $args['title'] ) ) {
			return new \WP_Error(
				'missing_title',
				__( 'title is required. Provide a non-empty template title.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['type'] ) ) {
			return new \WP_Error(
				'missing_type',
				__( 'type is required. Provide a template type (e.g., header, footer, content, section, popup).', 'bricks-mcp' )
			);
		}

		$template_id = $this->bricks_service->create_template( $args );

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		$template_data = $this->bricks_service->get_template_content_data( $template_id );

		if ( is_wp_error( $template_data ) ) {
			return $template_data;
		}

		$post = get_post( $template_id );

		return array_merge(
			$template_data,
			array(
				'status'    => $post ? $post->post_status : ( $args['status'] ?? 'publish' ),
				'permalink' => get_permalink( $template_id ),
				'edit_url'  => admin_url( 'post.php?post=' . $template_id . '&action=edit' ),
			)
		);
	}

	/**
	 * Tool: Update Bricks template metadata.
	 *
	 * Updates title, status, type, slug, tags, and bundles.
	 * Does not modify element content. Returns warning if type changed.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated template data or error.
	 */
	private function tool_update_template( array $args ): array|\WP_Error {
		if ( empty( $args['template_id'] ) ) {
			return new \WP_Error(
				'missing_template_id',
				__( 'template_id is required. Use template:list to find valid template IDs.', 'bricks-mcp' )
			);
		}

		$template_id = (int) $args['template_id'];
		$result      = $this->bricks_service->update_template_meta( $template_id, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$template_data = $this->bricks_service->get_template_content_data( $template_id );

		if ( is_wp_error( $template_data ) ) {
			return $template_data;
		}

		$post = get_post( $template_id );
		$data = array_merge(
			$template_data,
			array(
				'status'    => $post ? $post->post_status : '',
				'permalink' => get_permalink( $template_id ),
			)
		);

		// Append warning if type was changed.
		if ( is_array( $result ) && isset( $result['warning'] ) ) {
			$data['warning'] = $result['warning'];
		}

		return $data;
	}

	/**
	 * Tool: Move a Bricks template to trash.
	 *
	 * Soft-delete -- template can be recovered from WordPress trash.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Confirmation or error.
	 */
	private function tool_delete_template( array $args ): array|\WP_Error {
		if ( empty( $args['template_id'] ) ) {
			return new \WP_Error(
				'missing_template_id',
				__( 'template_id is required. Use template:list to find valid template IDs.', 'bricks-mcp' )
			);
		}

		$template_id = (int) $args['template_id'];
		$post        = get_post( $template_id );

		if ( ! $post || 'bricks_template' !== $post->post_type ) {
			return new \WP_Error(
				'template_not_found',
				sprintf(
					/* translators: %d: Template ID */
					__( 'Bricks template %d not found. Verify the template_id is a valid bricks_template post.', 'bricks-mcp' ),
					$template_id
				)
			);
		}

		// Confirm check.
		if ( empty( $args['confirm'] ) ) {
			$template_type = get_post_meta( $template_id, '_bricks_template_type', true );
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf(
					__( 'You are about to delete %s template "%s" (ID: %d). Set confirm: true to proceed.', 'bricks-mcp' ),
					$template_type ?: 'unknown',
					$post->post_title,
					$template_id
				)
			);
		}

		// Force delete: permanently delete instead of trashing.
		if ( ! empty( $args['force'] ) ) {
			$deleted = wp_delete_post( $template_id, true );
			if ( ! $deleted ) {
				return new \WP_Error(
					'delete_failed',
					sprintf( __( 'Failed to permanently delete template %d.', 'bricks-mcp' ), $template_id )
				);
			}
			return array(
				'template_id' => $template_id,
				'title'       => $post->post_title,
				'status'      => 'deleted',
				'message'     => __( 'Template permanently deleted. This cannot be undone.', 'bricks-mcp' ),
			);
		}

		$trashed = wp_trash_post( $template_id );

		if ( ! $trashed ) {
			return new \WP_Error(
				'trash_failed',
				/* translators: %d: Template ID */
				sprintf( __( 'Failed to trash template %d. Check WordPress error logs for details.', 'bricks-mcp' ), $template_id )
			);
		}

		return array(
			'template_id' => $template_id,
			'title'       => $post->post_title,
			'status'      => 'trash',
			'message'     => __( 'Template moved to trash. It can be recovered from the WordPress trash.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Duplicate a Bricks template.
	 *
	 * Creates a draft copy without conditions to prevent activation conflicts.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error New template data or error.
	 */
	private function tool_duplicate_template( array $args ): array|\WP_Error {
		if ( empty( $args['template_id'] ) ) {
			return new \WP_Error(
				'missing_template_id',
				__( 'template_id is required. Use template:list to find valid template IDs.', 'bricks-mcp' )
			);
		}

		$template_id     = (int) $args['template_id'];
		$new_template_id = $this->bricks_service->duplicate_template( $template_id );

		if ( is_wp_error( $new_template_id ) ) {
			return $new_template_id;
		}

		$template_data = $this->bricks_service->get_template_content_data( $new_template_id );

		if ( is_wp_error( $template_data ) ) {
			return $template_data;
		}

		$post = get_post( $new_template_id );

		return array_merge(
			$template_data,
			array(
				'status'    => $post ? $post->post_status : 'draft',
				'permalink' => get_permalink( $new_template_id ),
				'warning'   => __( 'Template conditions were not copied. Use set_template_conditions on the new template to configure where it should apply.', 'bricks-mcp' ),
			)
		);
	}

	/**
	 * Tool: Get full template content.
	 *
	 * Returns complete element data with template context and class names.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Template content or error.
	 */
	private function tool_get_template_content( array $args ): array|\WP_Error {
		if ( empty( $args['template_id'] ) ) {
			return new \WP_Error( 'missing_template_id', __( 'template_id is required. Provide a valid Bricks template post ID.', 'bricks-mcp' ) );
		}

		return $this->bricks_service->get_template_content_data( (int) $args['template_id'] );
	}

	/**
	 * Tool: Get popup display settings for a popup-type template.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'template_id'.
	 * @return array<string, mixed>|\WP_Error Popup settings data or error.
	 */
	private function tool_get_popup_settings( array $args ): array|\WP_Error {
		$template_id = $args['template_id'] ?? null;

		if ( null === $template_id ) {
			return new \WP_Error(
				'missing_template_id',
				__( 'template_id is required for get_popup_settings.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->get_popup_settings( (int) $template_id );
	}

	/**
	 * Tool: Set popup display settings on a popup-type template.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'template_id' and 'settings'.
	 * @return array<string, mixed>|\WP_Error Updated settings data or error.
	 */
	private function tool_set_popup_settings( array $args ): array|\WP_Error {
		$template_id = $args['template_id'] ?? null;
		$settings    = $args['settings'] ?? null;

		if ( null === $template_id ) {
			return new \WP_Error(
				'missing_template_id',
				__( 'template_id is required for set_popup_settings.', 'bricks-mcp' )
			);
		}

		if ( null === $settings || ! is_array( $settings ) ) {
			return new \WP_Error(
				'missing_settings',
				__( 'settings (object) is required for set_popup_settings. Use bricks:get_popup_schema to see valid keys.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->set_popup_settings( (int) $template_id, $settings );
	}

	/**
	 * Tool: Export a template as Bricks-compatible JSON.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'template_id' and optional 'include_classes'.
	 * @return array<string, mixed>|\WP_Error Export data or error.
	 */
	private function tool_export_template( array $args ): array|\WP_Error {
		$template_id = $args['template_id'] ?? null;

		if ( null === $template_id ) {
			return new \WP_Error(
				'missing_template_id',
				__( 'template_id is required for export.', 'bricks-mcp' )
			);
		}

		$include_classes = ! empty( $args['include_classes'] );

		return $this->bricks_service->export_template( (int) $template_id, $include_classes );
	}

	/**
	 * Tool: Import a template from JSON data.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'template_data'.
	 * @return array<string, mixed>|\WP_Error Import result or error.
	 */
	private function tool_import_template( array $args ): array|\WP_Error {
		$template_data = $args['template_data'] ?? null;

		if ( null === $template_data || ! is_array( $template_data ) ) {
			return new \WP_Error(
				'missing_template_data',
				__( 'template_data (object with title and content) is required for import.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->import_template( $template_data );
	}

	/**
	 * Tool: Import a template from a remote URL.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'url'.
	 * @return array<string, mixed>|\WP_Error Import result or error.
	 */
	private function tool_import_template_url( array $args ): array|\WP_Error {
		$url = $args['url'] ?? null;

		if ( empty( $url ) || ! is_string( $url ) ) {
			return new \WP_Error(
				'missing_url',
				__( 'url is required for import_url.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->import_template_from_url( $url );
	}

	/**
	 * Tool: Get all available template condition types.
	 *
	 * Returns condition type metadata to guide AI in writing valid conditions.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Condition types or error.
	 */
	private function tool_get_condition_types( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$types = $this->bricks_service->get_condition_types();

		return array(
			'condition_types'     => $types,
			'scoring_explanation' => 'Higher score wins when multiple templates match. Score 10 (specific IDs) beats score 8 (post type) beats score 2 (entire site).',
			'usage_note'          => 'Pass conditions as objects with "main" key plus any required extra_fields. Example: {"main":"any"} or {"main":"ids","ids":[42,99]}.',
		);
	}

	/**
	 * Tool: Set conditions on a Bricks template.
	 *
	 * Validates condition types and writes the complete set of conditions.
	 * Merges into existing template settings to preserve non-condition keys.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated conditions or error.
	 */
	private function tool_set_template_conditions( array $args ): array|\WP_Error {
		if ( empty( $args['template_id'] ) ) {
			return new \WP_Error(
				'missing_template_id',
				__( 'template_id is required. Use template:list to find valid template IDs.', 'bricks-mcp' )
			);
		}

		if ( ! isset( $args['conditions'] ) || ! is_array( $args['conditions'] ) ) {
			return new \WP_Error(
				'missing_conditions',
				__( 'conditions is required. Pass an array of condition objects (use get_condition_types to discover valid formats). Pass empty array [] to remove all conditions.', 'bricks-mcp' )
			);
		}

		$template_id = (int) $args['template_id'];
		$conditions  = $args['conditions'];

		$result = $this->bricks_service->set_template_conditions( $template_id, $conditions );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Return the updated conditions via format_conditions() for confirmation.
		$settings  = get_post_meta( $template_id, '_bricks_template_settings', true );
		$formatted = $this->bricks_service->format_conditions( $settings );

		return array(
			'template_id' => $template_id,
			'conditions'  => $formatted,
			'count'       => count( $conditions ),
			'message'     => 0 === count( $conditions )
				? __( 'All conditions removed. Template is now inactive.', 'bricks-mcp' )
				: sprintf(
					/* translators: %d: Number of conditions set */
					__( '%d condition(s) set successfully.', 'bricks-mcp' ),
					count( $conditions )
				),
		);
	}

	/**
	 * Tool: Resolve which Bricks templates apply to a given post.
	 *
	 * Evaluates all published template conditions against the post context
	 * and returns the winning template for each slot based on scoring.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Resolution data or error.
	 */
	private function tool_resolve_templates( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Provide the ID of the post to resolve templates for.', 'bricks-mcp' )
			);
		}

		$post_id = (int) $args['post_id'];

		return $this->bricks_service->resolve_templates_for_post( $post_id );
	}

	/**
	 * Tool: List all template tags.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Tags data or error.
	 */
	private function tool_list_template_tags( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$tags = $this->bricks_service->get_template_terms( 'template_tag' );

		if ( is_wp_error( $tags ) ) {
			return $tags;
		}

		return array(
			'total' => count( $tags ),
			'tags'  => $tags,
		);
	}

	/**
	 * Tool: List all template bundles.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Bundles data or error.
	 */
	private function tool_list_template_bundles( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bundles = $this->bricks_service->get_template_terms( 'template_bundle' );

		if ( is_wp_error( $bundles ) ) {
			return $bundles;
		}

		return array(
			'total'   => count( $bundles ),
			'bundles' => $bundles,
		);
	}

	/**
	 * Tool: Create a new template tag.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created term data or error.
	 */
	private function tool_create_template_tag( array $args ): array|\WP_Error {
		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a non-empty tag name.', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->create_template_term( 'template_tag', sanitize_text_field( $args['name'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_merge( $result, array( 'message' => __( 'Tag created. Assign it to templates via update_template\'s tags parameter.', 'bricks-mcp' ) ) );
	}

	/**
	 * Tool: Create a new template bundle.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created term data or error.
	 */
	private function tool_create_template_bundle( array $args ): array|\WP_Error {
		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a non-empty bundle name.', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->create_template_term( 'template_bundle', sanitize_text_field( $args['name'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_merge( $result, array( 'message' => __( "Bundle created. Assign it to templates via update_template's bundles parameter.", 'bricks-mcp' ) ) );
	}

	/**
	 * Tool: Delete a template tag.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Confirmation or error.
	 */
	private function tool_delete_template_tag( array $args ): array|\WP_Error {
		if ( empty( $args['term_id'] ) ) {
			return new \WP_Error(
				'missing_term_id',
				__( 'term_id is required. Use template_taxonomy:list_tags to find valid term IDs.', 'bricks-mcp' )
			);
		}

		$term_id = (int) $args['term_id'];

		// Confirm check.
		$term = get_term( $term_id, 'template_tag' );
		if ( $term && ! is_wp_error( $term ) ) {
			if ( empty( $args['confirm'] ) ) {
				return new \WP_Error(
					'bricks_mcp_confirm_required',
					sprintf(
						__( 'You are about to permanently delete tag "%s" (ID: %d) assigned to %d template(s). This cannot be undone. Set confirm: true to proceed.', 'bricks-mcp' ),
						$term->name,
						$term_id,
						$term->count
					)
				);
			}

			// Backup term data before deletion.
			$trash   = get_option( 'bricks_mcp_term_trash', array() );
			$trash[] = array(
				'term_id'    => $term_id,
				'name'       => $term->name,
				'slug'       => $term->slug,
				'taxonomy'   => $term->taxonomy,
				'count'      => $term->count,
				'deleted_at' => current_time( 'mysql' ),
			);
			if ( count( $trash ) > 50 ) {
				$trash = array_slice( $trash, -50 );
			}
			update_option( 'bricks_mcp_term_trash', $trash, false );
		}

		$result = $this->bricks_service->delete_template_term( 'template_tag', $term_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'term_id' => $term_id,
			'message' => __( 'Tag deleted and removed from all templates that had it assigned.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Delete a template bundle.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Confirmation or error.
	 */
	private function tool_delete_template_bundle( array $args ): array|\WP_Error {
		if ( empty( $args['term_id'] ) ) {
			return new \WP_Error(
				'missing_term_id',
				__( 'term_id is required. Use list_template_bundles to find valid term IDs.', 'bricks-mcp' )
			);
		}

		$term_id = (int) $args['term_id'];

		// Confirm check.
		$term = get_term( $term_id, 'template_bundle' );
		if ( $term && ! is_wp_error( $term ) ) {
			if ( empty( $args['confirm'] ) ) {
				return new \WP_Error(
					'bricks_mcp_confirm_required',
					sprintf(
						__( 'You are about to permanently delete bundle "%s" (ID: %d) assigned to %d template(s). This cannot be undone. Set confirm: true to proceed.', 'bricks-mcp' ),
						$term->name,
						$term_id,
						$term->count
					)
				);
			}

			// Backup term data before deletion.
			$trash   = get_option( 'bricks_mcp_term_trash', array() );
			$trash[] = array(
				'term_id'    => $term_id,
				'name'       => $term->name,
				'slug'       => $term->slug,
				'taxonomy'   => $term->taxonomy,
				'count'      => $term->count,
				'deleted_at' => current_time( 'mysql' ),
			);
			if ( count( $trash ) > 50 ) {
				$trash = array_slice( $trash, -50 );
			}
			update_option( 'bricks_mcp_term_trash', $trash, false );
		}

		$result = $this->bricks_service->delete_template_term( 'template_bundle', $term_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'term_id' => $term_id,
			'message' => __( 'Bundle deleted and removed from all templates that had it assigned.', 'bricks-mcp' ),
		);
	}

	/**
	 * Register template, template_condition, and template_taxonomy tools with the given registry.
	 *
	 * @param ToolRegistry $registry Tool registry instance.
	 * @return void
	 */
	public function register( ToolRegistry $registry ): void {
		// Template tool.
		$registry->register(
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
					'force'       => array(
						'type'        => 'boolean',
						'description' => __( 'When true, permanently delete instead of moving to trash (delete action only).', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'handle' )
		);

		// Template condition tool.
		$registry->register(
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
			array( $this, 'handle_condition' )
		);

		// Template taxonomy tool.
		$registry->register(
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
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'handle_taxonomy' )
		);
	}
}
