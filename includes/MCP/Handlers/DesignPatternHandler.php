<?php
/**
 * Design pattern handler for MCP Router.
 *
 * Pattern CRUD: capture from a built section, list, get, create, update,
 * delete, export, import, mark_required.
 *
 * v5.1: vision-driven pattern generation (`from_image`) was extracted —
 * the plugin no longer ships an LLM provider or vision pipeline. AI clients
 * that want to seed patterns from a screenshot can do so by writing HTML
 * via build_from_html and capturing the result via design_pattern:capture.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

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
	 * Bricks check callback.
	 *
	 * @var callable
	 */
	private $require_bricks;

	/**
	 * Bricks service instance.
	 *
	 * Loose-typed to accept test anon stubs that expose get_global_class_service()
	 * and get_global_variable_service() without extending the final class.
	 *
	 * @var object|null
	 */
	private $bricks_service = null;

	/**
	 * Constructor.
	 *
	 * @param object   $bricks_service Bricks service.
	 * @param callable $require_bricks Callback that returns \WP_Error|null.
	 */
	public function __construct( $bricks_service, callable $require_bricks ) {
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
			'capture'       => $this->tool_capture( $args ),
			'list'          => $this->tool_list( $args ),
			'get'           => $this->tool_get( $args ),
			'create'        => $this->tool_create( $args ),
			'update'        => $this->tool_update( $args ),
			'delete'        => $this->tool_delete( $args ),
			'export'        => $this->tool_export( $args ),
			'import'        => $this->tool_import( $args ),
			'mark_required' => $this->tool_mark_required( $args ),
			default         => new \WP_Error(
				'invalid_action',
				sprintf( 'Unknown action "%s". Valid: capture, list, get, create, update, delete, export, import, mark_required.', $action )
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
			'id'         => sanitize_key( $args['id'] ?? $this->slugify( $name ) ),
			'name'       => $name,
			'category'   => $category,
			'tags'       => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $args['tags'] ?? [] ) ) ) ),
			'layout'     => sanitize_text_field( $args['layout'] ?? '' ),
			'background' => sanitize_text_field( $args['background'] ?? '' ),
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
	 * List patterns.
	 */
	private function tool_list( array $args ): array {
		$all = DesignPatternService::list_all();

		$category = $args['category'] ?? '';
		if ( '' !== $category ) {
			$all = array_values( array_filter( $all, fn( $p ) => is_array( $p ) && ( $p['category'] ?? '' ) === $category ) );
		}

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
	 *
	 * Optional args:
	 *   include_drift (bool) — attach drift_report to response (default false).
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

		$include_drift = ! empty( $args['include_drift'] );
		if ( $include_drift ) {
			$pattern['drift_report'] = $this->compute_drift( $pattern );
		}

		return $pattern;
	}

	/**
	 * Compute drift report for a pattern. Cached in transient for 60s.
	 */
	private function compute_drift( array $pattern ): array {
		$cache_key = 'bricks_mcp_pattern_drift_' . ( $pattern['id'] ?? '' );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$class_service = $this->bricks_service->get_global_class_service();
		$site_classes  = $class_service->get_all_by_name();

		$detector = new \BricksMCP\MCP\Services\PatternDriftDetector();
		$report   = $detector->detect( $pattern, $site_classes );

		set_transient( $cache_key, $report, 60 );
		return $report;
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

		$result = DesignPatternService::update( $id, $updates );

		if ( ! is_wp_error( $result ) ) {
			delete_transient( 'bricks_mcp_pattern_drift_' . $id );
		}

		return $result;
	}

	/**
	 * Delete a pattern.
	 */
	private function tool_delete( array $args ): array|\WP_Error {
		$id = sanitize_text_field( $args['id'] ?? '' );
		if ( '' === $id ) {
			return new \WP_Error( 'missing_id', 'id is required.' );
		}

		$existing = DesignPatternService::get( $id );
		if ( null === $existing ) {
			return new \WP_Error( 'not_found', sprintf( 'Pattern "%s" not found.', $id ) );
		}

		$action_desc = sprintf( 'Delete pattern "%s" (%s).', $id, $existing['name'] ?? '' );

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
	 * Mark a role as required (or unmark) on a pattern.
	 */
	private function tool_mark_required( array $args ): array|\WP_Error {
		$pattern_id = sanitize_text_field( $args['pattern_id'] ?? '' );
		$role       = sanitize_text_field( $args['role'] ?? '' );
		$required   = (bool) ( $args['required'] ?? true );

		if ( $pattern_id === '' ) {
			return new \WP_Error( 'missing_field', 'Argument "pattern_id" is required.' );
		}
		if ( $role === '' ) {
			return new \WP_Error( 'missing_field', 'Argument "role" is required.' );
		}

		$pattern = DesignPatternService::get( $pattern_id );
		if ( null === $pattern ) {
			return new \WP_Error( 'pattern_not_found', sprintf( 'Pattern "%s" not found.', $pattern_id ) );
		}

		$nodes_updated   = 0;
		$available_roles = [];
		$walker = function ( array &$node ) use ( $role, $required, &$nodes_updated, &$available_roles, &$walker ) {
			if ( isset( $node['role'] ) && is_string( $node['role'] ) && $node['role'] !== '' ) {
				$available_roles[] = $node['role'];
				if ( $node['role'] === $role ) {
					if ( $required ) {
						$node['required'] = true;
					} else {
						unset( $node['required'] );
					}
					$nodes_updated++;
				}
			}
			if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
				foreach ( $node['children'] as &$child ) {
					if ( is_array( $child ) ) {
						$walker( $child );
					}
				}
				unset( $child );
			}
		};

		if ( isset( $pattern['structure'] ) && is_array( $pattern['structure'] ) ) {
			$walker( $pattern['structure'] );
		}

		if ( $nodes_updated === 0 ) {
			return new \WP_Error(
				'role_not_in_pattern',
				sprintf( 'Role "%s" not found in pattern "%s".', $role, $pattern_id ),
				[ 'available_roles' => array_values( array_unique( $available_roles ) ) ]
			);
		}

		if ( class_exists( '\BricksMCP\MCP\Services\PatternValidator' ) ) {
			$pattern['checksum'] = ( new \BricksMCP\MCP\Services\PatternValidator() )->checksum( $pattern );
		}

		$updated = DesignPatternService::update( $pattern_id, $pattern );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		delete_transient( 'bricks_mcp_pattern_drift_' . $pattern_id );

		return [
			'updated'       => true,
			'pattern_id'    => $pattern_id,
			'role'          => $role,
			'required'      => $required,
			'nodes_updated' => $nodes_updated,
			'checksum'      => $updated['checksum'] ?? ( $pattern['checksum'] ?? '' ),
		];
	}

	/**
	 * Register the design_pattern tool.
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'design_pattern',
			__( "Manage design patterns — reusable section compositions captured from existing built sections.\n\nActions: capture, list, get, create, update, delete, export, import, mark_required.\n\nUse capture to snapshot an existing built section into the pattern library. Patterns live in the database (managed via admin UI or MCP). Use export/import for cross-site sharing.\n\nNote: vision-driven pattern generation (from_image) was removed in v5.1 — to seed a pattern from a screenshot, build the section via build_from_html (HTML mode) and capture the result.", 'bricks-mcp' ),
			[
				'type'       => 'object',
				'properties' => [
					'action'   => [
						'type'        => 'string',
						'enum'        => [ 'capture', 'list', 'get', 'create', 'update', 'delete', 'export', 'import', 'mark_required' ],
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					],
					'page_id'  => [
						'type'        => 'integer',
						'description' => __( 'Page ID (capture: required as section source)', 'bricks-mcp' ),
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
					'layout' => [
						'type'        => 'string',
						'enum'        => [ 'centered', 'split-60-40', 'split-50-50', 'grid-2', 'grid-3', 'grid-4', 'stacked' ],
						'description' => __( 'Layout shape (capture: optional, default inferred)', 'bricks-mcp' ),
					],
					'background' => [
						'type'        => 'string',
						'enum'        => [ 'dark', 'light' ],
						'description' => __( 'Background tone (capture: optional, default inferred)', 'bricks-mcp' ),
					],
					'role' => [
						'type'        => 'string',
						'description' => __( 'Role name to mark/unmark required (mark_required: required)', 'bricks-mcp' ),
					],
					'required' => [
						'type'        => 'boolean',
						'description' => __( 'Mark role as required (true) or unmark (false). Default true. (mark_required: optional)', 'bricks-mcp' ),
					],
					'pattern_id' => [
						'type'        => 'string',
						'description' => __( 'Pattern ID to mark a role on (mark_required: required)', 'bricks-mcp' ),
					],
					'include_drift' => [
						'type'        => 'boolean',
						'description' => __( 'Include drift_report in response (get: optional, default false)', 'bricks-mcp' ),
					],
					'confirm' => [
						'type'        => 'boolean',
						'description' => __( 'Confirm destructive delete (delete: required after token return)', 'bricks-mcp' ),
					],
				],
				'required'   => [ 'action' ],
			],
			[ $this, 'handle' ]
		);
	}
}
