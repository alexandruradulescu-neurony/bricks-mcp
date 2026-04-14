<?php
/**
 * Design pattern handler for MCP Router.
 *
 * Manages the design pattern library: list, get, search, create, update, delete, export, import.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\DesignPatternService;
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
	 * Constructor.
	 *
	 * @param callable $require_bricks Callback that returns \WP_Error|null.
	 */
	public function __construct( callable $require_bricks ) {
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
			'list'            => $this->tool_list( $args ),
			'get'             => $this->tool_get( $args ),
			'semantic_search' => $this->tool_semantic_search( $args ),
			'create'          => $this->tool_create( $args ),
			'update'          => $this->tool_update( $args ),
			'delete'          => $this->tool_delete( $args ),
			'export'          => $this->tool_export( $args ),
			'import'          => $this->tool_import( $args ),
			default           => new \WP_Error(
				'invalid_action',
				sprintf( 'Unknown action "%s". Valid: list, get, semantic_search, create, update, delete, export, import.', $action )
			),
		};
	}

	/**
	 * List all patterns with optional filters.
	 */
	private function tool_list( array $args ): array {
		$all = DesignPatternService::list_all();

		// Filter by category.
		$category = $args['category'] ?? '';
		if ( '' !== $category ) {
			$all = array_values( array_filter( $all, fn( $p ) => ( $p['category'] ?? '' ) === $category ) );
		}

		// Filter by tags (pattern must have ALL specified tags).
		$tags = $args['tags'] ?? [];
		if ( is_array( $tags ) && ! empty( $tags ) ) {
			$all = array_values( array_filter( $all, function ( $p ) use ( $tags ) {
				$ptags = $p['tags'] ?? [];
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
	 * Semantic search for patterns.
	 */
	private function tool_semantic_search( array $args ): array|\WP_Error {
		$query = $args['query'] ?? '';
		if ( ! is_string( $query ) || '' === trim( $query ) ) {
			return new \WP_Error(
				'missing_query',
				'query is required for semantic_search. Provide a natural language description like "dark hero with form".'
			);
		}

		$limit = isset( $args['limit'] ) ? min( (int) $args['limit'], 50 ) : 10;

		return DesignPatternService::semantic_search( trim( $query ), $limit );
	}

	/**
	 * Create a new pattern in the database tier.
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

		$response = [
			'created' => true,
			'pattern' => $result,
		];

		// Warn if no ai_description.
		if ( empty( $result['ai_description'] ) ) {
			$response['warnings'] = [
				sprintf(
					'Pattern created without ai_description. AI clients won\'t find it via semantic_search. Run design_pattern:update(id: "%s", pattern: {ai_description: "..."}) to fix.',
					$result['id']
				),
			];
		}

		return $response;
	}

	/**
	 * Update an existing database-tier pattern.
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
	 * Delete a pattern (DB: remove, plugin/user: hide).
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

		$source = $existing['source'] ?? 'plugin';
		$action_desc = 'database' === $source
			? sprintf( 'Delete database pattern "%s" (%s).', $id, $existing['name'] ?? '' )
			: sprintf( 'Hide %s pattern "%s" (%s) from all lists.', $source, $id, $existing['name'] ?? '' );

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
				? 'Exported all database-tier patterns. Pass ids array to export specific patterns from any tier.'
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
			__( "Manage design patterns \u2014 reusable section compositions for the build pipeline.\n\nActions: list, get, semantic_search, create, update, delete, export, import.\n\nPatterns come from 3 tiers: plugin-shipped (read-only), user files in wp-content/uploads/ (read-only), and database (read-write via create/update/delete). Use semantic_search to find patterns by natural language. Use export/import for cross-site sharing.", 'bricks-mcp' ),
			[
				'type'       => 'object',
				'properties' => [
					'action'   => [
						'type'        => 'string',
						'enum'        => [ 'list', 'get', 'semantic_search', 'create', 'update', 'delete', 'export', 'import' ],
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					],
					'id'       => [
						'type'        => 'string',
						'description' => __( 'Pattern ID (get, update, delete: required)', 'bricks-mcp' ),
					],
					'query'    => [
						'type'        => 'string',
						'description' => __( 'Natural language search query (semantic_search: required, e.g. "dark hero with form")', 'bricks-mcp' ),
					],
					'limit'    => [
						'type'        => 'integer',
						'description' => __( 'Max results (semantic_search: optional, default 10)', 'bricks-mcp' ),
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
						'description' => __( 'Array of pattern IDs to export (export: optional, exports all DB patterns if omitted)', 'bricks-mcp' ),
					],
					'category' => [
						'type'        => 'string',
						'description' => __( 'Filter by category (list: optional)', 'bricks-mcp' ),
					],
					'tags'     => [
						'type'        => 'array',
						'description' => __( 'Filter by tags \u2014 pattern must have ALL specified tags (list: optional)', 'bricks-mcp' ),
					],
				],
				'required'   => [ 'action' ],
			],
			[ $this, 'handle' ]
		);
	}
}
