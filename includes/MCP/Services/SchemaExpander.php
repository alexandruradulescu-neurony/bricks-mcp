<?php
/**
 * Schema expander service.
 *
 * Resolves pattern references, expands repeat/data, and substitutes
 * data values in design schema structure trees.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expands design schema structures into concrete element trees.
 */
final class SchemaExpander {

	/**
	 * Expand a full design schema, resolving all patterns and repeats.
	 *
	 * @param array<string, mixed> $schema The validated design schema.
	 * @return array<string, mixed> Schema with all sections expanded.
	 */
	public function expand( array $schema ): array {
		$patterns = $schema['patterns'] ?? [];

		foreach ( $schema['sections'] as &$section ) {
			if ( ! empty( $section['structure'] ) ) {
				$section['structure'] = $this->expand_node( $section['structure'], $patterns, [] );
			}
		}
		unset( $section );

		// Patterns are resolved — remove from schema.
		unset( $schema['patterns'] );

		return $schema;
	}

	/**
	 * Recursively expand a structure node.
	 *
	 * Handles:
	 * - ref: replaces with the referenced pattern tree
	 * - repeat + data: duplicates the node N times with data substitution
	 * - Recurses into children
	 *
	 * @param array<string, mixed> $node     Structure node.
	 * @param array<string, mixed> $patterns Pattern definitions.
	 * @return array<string, mixed> Expanded node.
	 */
	private function expand_node( array $node, array $patterns, array $visited = [] ): array {
		// Handle ref + repeat + data (pattern instantiation with repetition).
		if ( ! empty( $node['ref'] ) ) {
			$ref_name = $node['ref'];

			// Circular ref detection.
			if ( in_array( $ref_name, $visited, true ) ) {
				return [ 'type' => 'text-basic', 'content' => "[ERROR: Circular pattern ref: {$ref_name}]" ];
			}
			$visited[] = $ref_name;

			$pattern = $patterns[ $ref_name ] ?? null;

			if ( null === $pattern ) {
				// Unresolvable ref — return as-is (validator should have caught this).
				return $node;
			}

			$repeat = $node['repeat'] ?? 1;
			$data   = $node['data'] ?? [];

			if ( $repeat > 1 && ! empty( $data ) ) {
				// Return multiple expanded copies with data substitution.
				// The parent's children array needs to splice these in.
				$expanded = [];
				for ( $i = 0; $i < $repeat; $i++ ) {
					$instance_data = $data[ $i ] ?? [];
					$instance      = $this->substitute_data( $pattern, $instance_data );
					$instance      = $this->expand_node( $instance, $patterns, $visited );
					$expanded[]    = $instance;
				}
				// Mark as multi-expansion for parent to handle.
				return [ '_expanded_multi' => $expanded ];
			}

			// Single pattern instantiation (possibly with first data item).
			$instance = ! empty( $data ) ? $this->substitute_data( $pattern, $data[0] ?? [] ) : $pattern;
			return $this->expand_node( $instance, $patterns, $visited );
		}

		// Expand children recursively.
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			$expanded_children = [];
			foreach ( $node['children'] as $child ) {
				if ( ! is_array( $child ) ) {
					continue;
				}
				$expanded = $this->expand_node( $child, $patterns, $visited );

				// Handle multi-expansion (ref with repeat > 1).
				if ( isset( $expanded['_expanded_multi'] ) ) {
					foreach ( $expanded['_expanded_multi'] as $multi_child ) {
						$expanded_children[] = $multi_child;
					}
				} else {
					$expanded_children[] = $expanded;
				}
			}
			$node['children'] = $expanded_children;
		}

		return $node;
	}

	/**
	 * Structural keys that must never be modified by data substitution.
	 *
	 * @var array<int, string>
	 */
	private const PROTECTED_KEYS = [ 'type', 'tag', 'ref', 'repeat', 'layout' ];

	/**
	 * Substitute data values into a pattern tree.
	 *
	 * Replaces string values matching "data.key" pattern with actual data values.
	 * Protects structural keys (type, tag, ref, etc.) from substitution.
	 *
	 * @param array<string, mixed> $node Pattern node.
	 * @param array<string, mixed> $data Data key-value pairs.
	 * @return array<string, mixed> Node with substituted values.
	 */
	private function substitute_data( array $node, array $data ): array {
		if ( empty( $data ) ) {
			return $node;
		}

		$result = [];
		foreach ( $node as $key => $value ) {
			// Never substitute structural keys.
			if ( in_array( $key, self::PROTECTED_KEYS, true ) ) {
				$result[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$result[ $key ] = $this->replace_data_references( $value, $data );
			} elseif ( is_array( $value ) ) {
				$result[ $key ] = $this->substitute_data( $value, $data );
			} else {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Replace data references in a string value.
	 *
	 * Only matches explicit "data.key" prefix or {data.key} interpolation.
	 * Does NOT do bare key matching to avoid collisions with element type names.
	 *
	 * @param string               $value The string value.
	 * @param array<string, mixed> $data  Data key-value pairs.
	 * @return mixed The replaced value (may change type if entire string is a reference).
	 */
	private function replace_data_references( string $value, array $data ): mixed {
		// Exact match: "data.key" → replace with data value (preserving type).
		if ( str_starts_with( $value, 'data.' ) ) {
			$key = substr( $value, 5 );
			if ( array_key_exists( $key, $data ) ) {
				return $data[ $key ];
			}
			// Data key not found — return empty string instead of literal "data.key".
			return '';
		}

		// Interpolation: replace {data.key} within longer strings.
		$replaced = preg_replace_callback(
			'/\{data\.(\w+)\}/',
			static function ( array $matches ) use ( $data ): string {
				$key = $matches[1];
				return array_key_exists( $key, $data ) ? (string) $data[ $key ] : '';
			},
			$value
		);

		return $replaced ?? $value;
	}
}
