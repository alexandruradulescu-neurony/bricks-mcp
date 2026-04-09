<?php
/**
 * Tool registry for MCP Router.
 *
 * Stores tool definitions and provides lookup methods.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry for MCP tool definitions.
 */
final class ToolRegistry {

	/**
	 * Registered tools keyed by name.
	 *
	 * @var array<string, array{name: string, description: string, inputSchema: array, handler: callable, annotations?: array}>
	 */
	private array $tools = [];

	/**
	 * Register a tool.
	 *
	 * @param string   $name         Tool name.
	 * @param string   $description  Tool description.
	 * @param array    $input_schema JSON Schema for the tool's input.
	 * @param callable $handler      Callback that handles tool execution.
	 * @param array    $annotations  Optional MCP annotations (readOnlyHint, destructiveHint, etc.).
	 */
	public function register( string $name, string $description, array $input_schema, callable $handler, array $annotations = [] ): void {
		$this->tools[ $name ] = [
			'name'        => $name,
			'description' => $description,
			'inputSchema' => $input_schema,
			'handler'     => $handler,
			'annotations' => $annotations,
		];
	}

	/**
	 * Get a tool definition by name.
	 *
	 * @param string $name Tool name.
	 * @return array|null Tool definition or null if not found.
	 */
	public function get( string $name ): ?array {
		return $this->tools[ $name ] ?? null;
	}

	/**
	 * Check if a tool is registered.
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public function has( string $name ): bool {
		return isset( $this->tools[ $name ] );
	}

	/**
	 * Get all registered tools in MCP format.
	 *
	 * @return array<int, array{name: string, description: string, inputSchema: array}> Tools list.
	 */
	public function get_all(): array {
		$result = [];
		foreach ( $this->tools as $tool ) {
			$entry = [
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'inputSchema' => $tool['inputSchema'],
			];
			if ( ! empty( $tool['annotations'] ) ) {
				$entry['annotations'] = $tool['annotations'];
			}
			$result[] = $entry;
		}
		return $result;
	}

	/**
	 * Get all tools as a keyed array (for filters and internal use).
	 *
	 * @return array<string, array{name: string, description: string, inputSchema: array, handler: callable, annotations?: array}>
	 */
	public function get_all_raw(): array {
		return $this->tools;
	}

	/**
	 * Remove a tool from the registry.
	 *
	 * @param string $name Tool name.
	 */
	public function remove( string $name ): void {
		unset( $this->tools[ $name ] );
	}

	/**
	 * Get the count of registered tools.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->tools );
	}
}
