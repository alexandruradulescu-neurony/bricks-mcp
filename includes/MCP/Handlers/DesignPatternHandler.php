<?php
/**
 * Design pattern handler for MCP Router.
 *
 * Manages the design pattern library: list, get, create, update, delete, export, import.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\DesignPatternService;
use BricksMCP\MCP\Services\PatternCapture;
use BricksMCP\MCP\Services\PatternValidator;
use BricksMCP\MCP\ToolRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles design_pattern tool actions.
 */
final class DesignPatternHandler {

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
	 * Handle a design_pattern tool action.
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
			'capture' => $this->tool_capture( $args ),
			'list'    => $this->tool_list( $args ),
			'get'     => $this->tool_get( $args ),
			'create'  => $this->tool_create( $args ),
			'update'  => $this->tool_update( $args ),
			'delete'  => $this->tool_delete( $args ),
			'export'  => $this->tool_export( $args ),
			'import'  => $this->tool_import( $args ),
			default   => new \WP_Error(
				'invalid_action',
				sprintf( 'Unknown action "%s". Valid: capture, list, get, create, update, delete, export, import.', $action )
			),
		};
	}

	/**
	 * Capture a pattern from an existing built section.
	 *
	 * Required args: page_id, block_id, name, category.
	 * Optional args: id (auto-generated if missing), tags.
	 */
	private function tool_capture( array $args ): array|\WP_Error {
		$page_id  = (int) ( $args['page_id'] ?? 0 );
		$block_id = sanitize_text_field( $args['block_id'] ?? '' );
		$name     = sanitize_text_field( $args['name'] ?? '' );
		$category = sanitize_text_field( $args['category'] ?? '' );

		foreach ( [ 'page_id' => $page_id, 'block_id' => $block_id, 'name' => $name, 'category' => $category ] as $k => $v ) {
			if ( empty( $v ) ) {
				return new \WP_Error( 'missing_field', sprintf( 'Argument "%s" is required.', $k ) );
			}
		}

		$meta = [
			'id'       => sanitize_key( $args['id'] ?? $this->slugify( $name ) ),
			'name'     => $name,
			'category' => $category,
			'tags'     => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $args['tags'] ?? [] ) ) ) ),
		];

		$validator = new PatternValidator();
		$bricks    = $this->bricks_service;
		$classes   = $this->bricks_service->get_global_class_service();
		$vars      = $this->bricks_service->get_global_variable_service();

		$capture = new PatternCapture( $validator, $bricks, $classes, $vars );
		$result  = $capture->capture( $page_id, $block_id, $meta );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( $result['error'], $result['message'] ?? 'Capture failed.', $result );
		}

		$saved = DesignPatternService::create( $result );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [
			'captured' => true,
			'pattern'  => $saved,
		];
	}

	/**
	 * Convert a human-readable string to a URL-safe slug.
	 */
	private function slugify( string $s ): string {
		$s = strtolower( $s );
		$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
		return trim( (string) $s, '-' );
	}

	/**
	 * List all patterns with optional filters.
	 */
	private function tool_list( array $args ): array {
		$all = DesignPatternService::list_all();

		// Filter by category.
		$category = $args['category'] ?? '';
		if ( '' !== $category ) {
			$all = array_values( array_filter( $all, fn( $p ) => is_array( $p ) && ( $p['category'] ?? '' ) === $category ) );
		}

		// Filter by tags (pattern must have ALL specified tags).
		$tags = $args['tags'] ?? [];
		if ( is_array( $tags ) && ! empty( $tags ) ) {
			$all = array_values( array_filter( $all, function ( $p ) use ( $tags ) {
				if ( ! is_array( $p ) ) {
					return false;
				}
				$ptags = $p['tags'] ?? [];
				if ( ! is_array( $ptags ) ) {
					return false;
				}
				foreach ( $tags as $t ) {
					if ( ! in_array( $t, $ptags, true ) ) {
						return false;
					}
				}
				return true;
			} ) );
		}

		return [
			'total'    => count( $all ),
			'patterns' => $all,
		];
	}

	/**
	 * Get a single pattern by ID.
	 */
	private function tool_get( array $args ): array|\WP_Error {
		$id = sanitize_text_field( $args['id'] ?? '' );
		if ( '' === $id ) {
			return new \WP_Error( 'missing_id', 'id is required.' );
		}

		$pattern = DesignPatternService::get( $id );
		if ( null === $pattern ) {
			return new \WP_Error( 'not_found', sprintf( 'Pattern "%s" not found.', $id ) );
		}

		return $pattern;
	}

	/**
	 * Create a new pattern in the database.
	 */
	private function tool_create( array $args ): array|\WP_Error {
		$pattern = $args['pattern'] ?? null;
		if ( ! is_array( $pattern ) ) {
			return new \WP_Error( 'missing_pattern', 'pattern object is required. Must have id, name, category, tags.' );
		}

		$result = DesignPatternService::create( $pattern );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'created' => true,
			'pattern' => $result,
		];
	}

	/**
	 * Update an existing pattern.
	 */
	private function tool_update( array $args ): array|\WP_Error {
		$id = sanitize_text_field( $args['id'] ?? '' );
		if ( '' === $id ) {
			return new \WP_Error( 'missing_id', 'id is required.' );
		}

		$updates = $args['pattern'] ?? null;
		if ( ! is_array( $updates ) || empty( $updates ) ) {
			return new \WP_Error( 'missing_pattern', 'pattern object with fields to update is required.' );
		}

		return DesignPatternService::update( $id, $updates );
	}

	/**
	 * Delete a pattern.
	 */
	private function tool_delete( array $args ): array|\WP_Error {
		$id = sanitize_text_field( $args['id'] ?? '' );
		if ( '' === $id ) {
			return new \WP_Error( 'missing_id', 'id is required.' );
		}

		// Check if pattern exists before requesting confirmation.
		$existing = DesignPatternService::get( $id );
		if ( null === $existing ) {
			return new \WP_Error( 'not_found', sprintf( 'Pattern "%s" not found.', $id ) );
		}

		$action_desc = sprintf( 'Delete pattern "%s" (%s).', $id, $existing['name'] ?? '' );

		// Destructive action confirmation flow.
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'bricks_mcp_confirm_required', $action_desc );
		}

		return DesignPatternService::delete( $id );
	}

	/**
	 * Export patterns as portable JSON.
	 */
	private function tool_export( array $args ): array {
		$ids = $args['ids'] ?? [];
		if ( ! is_array( $ids ) ) {
			$ids = [];
		}

		$patterns = DesignPatternService::export( $ids );

		return [
			'exported_count' => count( $patterns ),
			'patterns'       => $patterns,
			'note'           => empty( $ids )
				? 'Exported all patterns. Pass ids array to export specific patterns.'
				: sprintf( 'Exported %d pattern(s) by ID.', count( $patterns ) ),
		];
	}

	/**
	 * Import patterns from a JSON array.
	 */
	private function tool_import( array $args ): array|\WP_Error {
		$patterns = $args['patterns'] ?? null;
		if ( ! is_array( $patterns ) || empty( $patterns ) ) {
			return new \WP_Error( 'missing_patterns', 'patterns array is required. Each item must be a pattern object with id, name, category, tags.' );
		}

		return DesignPatternService::import( $patterns );
	}

	/**
	 * Register the design_pattern tool.
	 *
	 * @param ToolRegistry $registry Tool registry instance.
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'design_pattern',
			__( "Manage design patterns \u2014 reusable section compositions for the build pipeline.\n\nActions: capture, list, get, create, update, delete, export, import.\n\nUse capture to snapshot an existing built section into the pattern library. All patterns live in the database (managed via admin UI or MCP). Use export/import for cross-site sharing.", 'bricks-mcp' ),
			[
				'type'       => 'object',
				'properties' => [
					'action'   => [
						'type'        => 'string',
						'enum'        => [ 'capture', 'list', 'get', 'create', 'update', 'delete', 'export', 'import' ],
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					],
					'page_id'  => [
						'type'        => 'integer',
						'description' => __( 'Page ID containing the section to capture (capture: required)', 'bricks-mcp' ),
					],
					'block_id' => [
						'type'        => 'string',
						'description' => __( 'Element ID of the section root (capture: required)', 'bricks-mcp' ),
					],
					'name'     => [
						'type'        => 'string',
						'description' => __( 'Human-readable pattern name (capture: required)', 'bricks-mcp' ),
					],
					'id'       => [
						'type'        => 'string',
						'description' => __( 'Pattern ID (capture: optional auto-slug from name; get, update, delete: required)', 'bricks-mcp' ),
					],
					'pattern'  => [
						'type'        => 'object',
						'description' => __( 'Pattern object (create: required with id/name/category/tags, update: fields to change)', 'bricks-mcp' ),
					],
					'patterns' => [
						'type'        => 'array',
						'description' => __( 'Array of pattern objects (import: required)', 'bricks-mcp' ),
					],
					'ids'      => [
						'type'        => 'array',
						'description' => __( 'Pattern IDs to export (export: optional)', 'bricks-mcp' ),
					],
					'category' => [
						'type'        => 'string',
						'description' => __( 'Pattern category (capture: required; list: optional filter)', 'bricks-mcp' ),
					],
					'tags'     => [
						'type'        => 'array',
						'description' => __( 'Pattern tags (capture: optional; list: optional filter)', 'bricks-mcp' ),
					],
				],
				'required'   => [ 'action' ],
			],
			[ $this, 'handle' ]
		);
	}
}
