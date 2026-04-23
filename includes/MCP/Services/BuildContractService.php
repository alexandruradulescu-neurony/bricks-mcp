<?php
/**
 * Static quality contract for design builds.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BuildContractService {

	private GlobalClassService $class_service;

	public function __construct( GlobalClassService $class_service ) {
		$this->class_service = $class_service;
	}

	/**
	 * Validate schema quality before class creation and element writes.
	 *
	 * This catches failures that are structurally valid but visually broken:
	 * unresolved variables and new class_intents with no style source.
	 *
	 * @return array{errors: array<int, string>, warnings: array<int, string>}
	 */
	public function validate( array $schema ): array {
		$errors   = [];
		$warnings = [];
		$classes  = $this->classes_by_name();

		foreach ( $schema['sections'] ?? [] as $idx => $section ) {
			if ( ! is_array( $section ) || empty( $section['structure'] ) || ! is_array( $section['structure'] ) ) {
				continue;
			}
			$this->validate_node( $section['structure'], "sections[{$idx}].structure", $classes, $errors, $warnings );
		}

		foreach ( $schema['patterns'] ?? [] as $name => $pattern ) {
			if ( is_array( $pattern ) ) {
				$this->validate_node( $pattern, 'patterns.' . (string) $name, $classes, $errors, $warnings );
			}
		}

		return [
			'errors'   => array_values( array_unique( $errors ) ),
			'warnings' => array_values( array_unique( $warnings ) ),
		];
	}

	/**
	 * @param array<string, mixed> $node
	 * @param array<string, array<string, mixed>> $classes
	 * @param array<int, string> &$errors
	 * @param array<int, string> &$warnings
	 */
	private function validate_node( array $node, string $path, array $classes, array &$errors, array &$warnings ): void {
		$intent = is_string( $node['class_intent'] ?? null ) ? trim( (string) $node['class_intent'] ) : '';
		$styles = is_array( $node['style_overrides'] ?? null ) ? $node['style_overrides'] : [];

		if ( ! empty( $styles ) ) {
			$normalized = StyleNormalizationService::normalize( $styles );
			foreach ( $normalized['warnings'] as $warning ) {
				$warnings[] = $path . ': ' . $warning;
			}
		}

		if ( '' !== $intent ) {
			$existing = $classes[ $intent ] ?? $classes[ $this->normalize_class_name( $intent ) ] ?? null;

			if ( null === $existing && empty( $styles ) ) {
				$errors[] = sprintf(
					'%s: class_intent "%s" does not match an existing class and has no style_overrides. Building it would create an empty visual class.',
					$path,
					$intent
				);
			} elseif ( is_array( $existing ) && empty( $existing['settings'] ) && empty( $styles ) ) {
				$warnings[] = sprintf(
					'%s: class_intent "%s" resolves to an existing empty class. Add styles, map it to a styled class, or remove the intent.',
					$path,
					$intent
				);
			}
		}

		foreach ( $node['children'] ?? [] as $idx => $child ) {
			if ( is_array( $child ) ) {
				$this->validate_node( $child, $path . '.children[' . (string) $idx . ']', $classes, $errors, $warnings );
			}
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function classes_by_name(): array {
		$map = [];
		foreach ( $this->class_service->get_global_classes() as $class ) {
			$name = (string) ( $class['name'] ?? '' );
			if ( '' !== $name ) {
				$map[ $name ] = $class;
				$normalized = $this->normalize_class_name( $name );
				if ( ! isset( $map[ $normalized ] ) ) {
					$map[ $normalized ] = $class;
				}
			}
		}
		return $map;
	}

	private function normalize_class_name( string $name ): string {
		return strtolower( str_replace( [ '-', '_' ], '', $name ) );
	}
}
