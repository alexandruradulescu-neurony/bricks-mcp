<?php
/**
 * Content contract helpers for built sections.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ContentContractService {

	/**
	 * Element types that require user-facing content after build_structure.
	 */
	private const REQUIRED_CONTENT_TYPES = [ 'heading', 'text-basic', 'text', 'button', 'alert', 'icon-box' ];

	/**
	 * Analyze required content roles under a section.
	 *
	 * @param array<int, array<string, mixed>> $elements Flat Bricks element array.
	 * @return array{required_roles: array<string, array<string, mixed>>, missing_roles: array<int, string>}
	 */
	public function analyze( array $elements, string $section_id ): array {
		$ids      = $this->section_subtree_ids( $elements, $section_id );
		$registry = ElementSettingsGenerator::get_element_registry();
		$required = [];
		$missing  = [];

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$id = (string) ( $element['id'] ?? '' );
			if ( '' === $id || ! isset( $ids[ $id ] ) ) {
				continue;
			}

			$type = (string) ( $element['name'] ?? '' );
			if ( ! in_array( $type, self::REQUIRED_CONTENT_TYPES, true ) ) {
				continue;
			}

			$content_key = (string) ( $registry[ $type ]['content_key'] ?? '' );
			if ( '' === $content_key ) {
				continue;
			}

			$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
			$role     = (string) ( $settings['label'] ?? $element['label'] ?? '' );
			if ( '' === $role ) {
				$role = '#' . $id;
			}

			$value = $settings[ $content_key ] ?? null;
			$is_empty = $this->is_empty_content_value( $value );

			$required[ $role ] = [
				'element_id'   => $id,
				'element_type' => $type,
				'content_key'  => $content_key,
				'empty'        => $is_empty,
			];

			if ( $is_empty ) {
				$missing[] = $role;
			}
		}

		return [
			'required_roles' => $required,
			'missing_roles'  => array_values( array_unique( $missing ) ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @return array<string, true>
	 */
	private function section_subtree_ids( array $elements, string $section_id ): array {
		$children_of = [];
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$id = (string) ( $element['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$parent = (string) ( $element['parent'] ?? '0' );
			$children_of[ $parent ][] = $id;
		}

		$ids   = [ $section_id => true ];
		$queue = [ $section_id ];
		while ( ! empty( $queue ) ) {
			$current = array_shift( $queue );
			foreach ( $children_of[ $current ] ?? [] as $child_id ) {
				if ( isset( $ids[ $child_id ] ) ) {
					continue;
				}
				$ids[ $child_id ] = true;
				$queue[] = $child_id;
			}
		}

		return $ids;
	}

	private function is_empty_content_value( mixed $value ): bool {
		if ( null === $value ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return '' === trim( wp_strip_all_tags( $value ) );
		}
		if ( is_array( $value ) ) {
			return empty( $value );
		}
		return false;
	}
}
