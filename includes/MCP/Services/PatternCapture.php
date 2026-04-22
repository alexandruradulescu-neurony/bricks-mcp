<?php
/**
 * Pattern capture.
 *
 * Reads a Bricks section's element tree at a block_id, normalizes it into
 * pattern structure, detects repeating nodes, and hands off to the validator.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PatternCapture {

	public function __construct(
		private PatternValidator $validator,
		private BricksService $bricks,
		private GlobalClassService $classes,
		private GlobalVariableService $variables
	) {}

	/**
	 * Capture a pattern from an existing built section on a page.
	 *
	 * @param int                  $page_id  Target page.
	 * @param string               $block_id Root element ID of section to capture.
	 * @param array<string, mixed> $meta     { id, name, category, tags }.
	 * @return array<string, mixed> Validated pattern OR error structure.
	 */
	public function capture( int $page_id, string $block_id, array $meta ): array {
		$tree = $this->bricks->get_element_subtree( $page_id, $block_id );
		if ( null === $tree ) {
			return [ 'error' => 'block_not_found', 'message' => sprintf( 'Block "%s" not found on page %d.', $block_id, $page_id ) ];
		}

		$structure = $this->normalize( $tree );
		$structure = $this->detect_repeats( $structure );

		$site_context = [
			'variables' => $this->variables->get_all_with_values(),
			'classes'   => $this->classes->get_all_by_name(),
		];

		$pattern = array_merge( $meta, [
			'structure'     => $structure,
			'captured_from' => [
				'page_id'  => $page_id,
				'block_id' => $block_id,
				'at'       => gmdate( 'c' ),
			],
		] );

		// Infer layout + background if the handler didn't pass them.
		if ( empty( $pattern['layout'] ) ) {
			$pattern['layout'] = $this->infer_layout( $structure );
		}
		if ( empty( $pattern['background'] ) ) {
			$pattern['background'] = $this->infer_background( $structure );
		}

		return $this->validator->validate_with_context( $pattern, $site_context );
	}

	/**
	 * Infer layout shape from the captured structure.
	 *
	 * Heuristic: look at section > container > direct children.
	 *   - 2 sibling containers/blocks (split layout) → "split-50-50" (can't detect 60-40 reliably)
	 *   - grid layout keyword in style_tokens → map to grid-N
	 *   - default: "centered"
	 */
	private function infer_layout( array $structure ): string {
		// Find the first container.
		$container = null;
		foreach ( $structure['children'] ?? [] as $child ) {
			if ( is_array( $child ) && ( $child['type'] ?? '' ) === 'container' ) {
				$container = $child;
				break;
			}
		}
		if ( $container === null ) {
			return 'centered';
		}

		$children  = $container['children'] ?? [];
		$grid_cols = $container['style_tokens']['_gridTemplateColumns'] ?? '';

		if ( $grid_cols !== '' ) {
			// e.g. "var(--grid-3)" → 3; "repeat(3, 1fr)" → 3
			if ( preg_match( '/grid-(\d)/', $grid_cols, $m ) ) {
				return 'grid-' . (int) $m[1];
			}
			if ( preg_match( '/repeat\((\d)/', $grid_cols, $m ) ) {
				return 'grid-' . (int) $m[1];
			}
		}

		if ( count( $children ) === 2 ) {
			return 'split-50-50';
		}

		return 'centered';
	}

	/**
	 * Infer background tone from top-level section _background color.
	 * "dark" if color resolves to a dark variable; else "light".
	 */
	private function infer_background( array $structure ): string {
		$bg = $structure['style_tokens']['_background']['color']['raw'] ?? '';
		if ( $bg === '' ) {
			return 'light';
		}
		$lower = strtolower( $bg );
		// Common dark variable patterns.
		if ( str_contains( $lower, 'base-ultra-dark' ) ||
			 str_contains( $lower, 'base-dark' ) ||
			 str_contains( $lower, '--black' ) ||
			 str_contains( $lower, 'primary-ultra-dark' ) ) {
			return 'dark';
		}
		// Check raw rgb/hex for darkness.
		if ( preg_match( '/#([0-9a-f]{6})/i', $bg, $m ) ) {
			$hex       = $m[1];
			$r         = hexdec( substr( $hex, 0, 2 ) );
			$g         = hexdec( substr( $hex, 2, 2 ) );
			$b         = hexdec( substr( $hex, 4, 2 ) );
			$luminance = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;
			return $luminance < 0.5 ? 'dark' : 'light';
		}
		return 'light';
	}

	/**
	 * Normalize a Bricks element tree into pattern structure shape.
	 * Drops Bricks-specific IDs, keeps type/role/tag/class_refs/style_tokens/children.
	 */
	private function normalize( array $tree ): array {
		$out = [ 'type' => $tree['name'] ?? 'block' ];

		if ( isset( $tree['label'] ) && is_string( $tree['label'] ) && $tree['label'] !== '' ) {
			$out['role'] = sanitize_key( strtolower( str_replace( ' ', '_', $tree['label'] ) ) );
		}
		if ( isset( $tree['settings']['tag'] ) ) {
			$out['tag'] = (string) $tree['settings']['tag'];
		}

		// Class refs come from Bricks element.settings._cssGlobalClasses (array of class IDs).
		// Resolve to class NAMES at capture time (ids are unstable across sites).
		$class_ids = $tree['settings']['_cssGlobalClasses'] ?? [];
		if ( is_array( $class_ids ) && $class_ids !== [] ) {
			$out['class_refs'] = $this->classes->ids_to_names( $class_ids );
		}

		// Style tokens = settings keys prefixed with _ (Bricks convention), minus dangerous ones.
		$tokens = [];
		foreach ( $tree['settings'] ?? [] as $key => $value ) {
			if ( is_string( $key ) && str_starts_with( $key, '_' ) && ! in_array( $key, [ '_cssCustom', '_cssGlobalClasses' ], true ) ) {
				$tokens[ $key ] = $value;
			}
		}
		if ( $tokens !== [] ) {
			$out['style_tokens'] = $tokens;
		}

		// Children.
		if ( isset( $tree['children'] ) && is_array( $tree['children'] ) && $tree['children'] !== [] ) {
			$out['children'] = array_map( fn( $c ) => $this->normalize( $c ), $tree['children'] );
		}

		return $out;
	}

	/**
	 * Detect homogeneous grid children and collapse to single template with repeat:true.
	 */
	private function detect_repeats( array $node ): array {
		if ( isset( $node['children'] ) && is_array( $node['children'] ) && count( $node['children'] ) > 1 ) {
			$children = $node['children'];
			if ( $this->children_homogeneous( $children ) ) {
				$template           = $this->detect_repeats( $children[0] );
				$template['repeat'] = true;
				$node['children']   = [ $template ];
			} else {
				$node['children'] = array_map( fn( $c ) => $this->detect_repeats( $c ), $children );
			}
		}
		return $node;
	}

	private function children_homogeneous( array $children ): bool {
		if ( count( $children ) < 2 ) {
			return false;
		}
		$first_fingerprint = $this->shape_fingerprint( $children[0] );
		foreach ( array_slice( $children, 1 ) as $c ) {
			if ( $this->shape_fingerprint( $c ) !== $first_fingerprint ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Fingerprint a node's shape: type + class_refs (sorted) + recursive child fingerprints.
	 */
	private function shape_fingerprint( array $node ): string {
		$type = $node['type'] ?? '';
		$refs = $node['class_refs'] ?? [];
		sort( $refs );
		$child_prints = [];
		foreach ( $node['children'] ?? [] as $c ) {
			if ( is_array( $c ) ) {
				$child_prints[] = $this->shape_fingerprint( $c );
			}
		}
		return $type . ':[' . implode( ',', $refs ) . ']:(' . implode( '|', $child_prints ) . ')';
	}
}
