<?php
/**
 * Normalization for vision/reference design plans before proposal/build.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DesignPlanNormalizationService {

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $existing_classes_by_name;

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $style_roles;

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $component_classes;

	/**
	 * @param array<string, array<string, mixed>> $existing_classes_by_name
	 */
	public function __construct( array $existing_classes_by_name ) {
		$this->existing_classes_by_name = $existing_classes_by_name;
		$this->style_roles             = ( new StyleRoleResolver( array_values( $existing_classes_by_name ) ) )->resolve_all();
		$this->component_classes       = ( new ComponentClassGenerator( $this->style_roles ) )->missing_component_definitions();
	}

	/**
	 * Normalize a design plan, class provisioning list, and content map.
	 *
	 * @param array<string, mixed> $design_plan
	 * @param array<int, mixed>    $global_classes_to_create
	 * @param array<string, mixed> $content_map
	 * @return array{
	 *   design_plan: array<string, mixed>,
	 *   global_classes_to_create: array<int, array<string, mixed>>,
	 *   content_map: array<string, mixed>,
	 *   style_roles: array<string, array<string, mixed>>,
	 *   component_classes: array<string, array<string, mixed>>,
	 *   normalization_log: array<string, mixed>
	 * }
	 */
	public function normalize( array $design_plan, array $global_classes_to_create = [], array $content_map = [] ): array {
		$log = [
			'roles_normalized'          => [],
			'content_roles_normalized'  => [],
			'class_intents_rewritten'   => [],
			'class_intents_removed'     => [],
			'global_classes_dropped'    => [],
			'global_class_style_warnings' => [],
		];

		$normalized_classes = $this->normalize_global_classes_to_create( $global_classes_to_create, $log );
		$class_sources      = $this->build_class_source_index( $normalized_classes );

		$design_plan = $this->normalize_design_plan_roles( $design_plan, $class_sources, $log );
		$content_map = $this->normalize_content_map( $content_map, $log );

		return [
			'design_plan'               => $design_plan,
			'global_classes_to_create'  => $normalized_classes,
			'content_map'               => $content_map,
			'style_roles'               => $this->style_roles,
			'component_classes'         => $this->component_classes,
			'normalization_log'         => $this->prune_log( $log ),
		];
	}

	/**
	 * Normalize a content/design role key into snake_case.
	 */
	public static function normalize_role_key( string $role ): string {
		$role = strtolower( trim( $role ) );
		if ( '' === $role ) {
			return '';
		}

		$role = str_replace( [ '>', '/', '.', '-' ], '_', $role );
		$role = preg_replace( '/[^a-z0-9_]+/', '_', $role );
		$role = preg_replace( '/_+/', '_', (string) $role );

		return trim( (string) $role, '_' );
	}

	/**
	 * Infer the semantic component role for a plan/pattern role.
	 */
	public static function infer_semantic_component_role( string $role ): ?string {
		$role_lower = self::normalize_role_key( $role );
		if ( '' === $role_lower ) {
			return null;
		}

		if (
			str_contains( $role_lower, 'primary' )
			&& ( str_contains( $role_lower, 'cta' ) || str_contains( $role_lower, 'button' ) || str_contains( $role_lower, 'btn' ) )
		) {
			return 'button.primary';
		}
		if (
			( str_contains( $role_lower, 'secondary' ) || str_contains( $role_lower, 'ghost' ) || str_contains( $role_lower, 'outline' ) )
			&& ( str_contains( $role_lower, 'cta' ) || str_contains( $role_lower, 'button' ) || str_contains( $role_lower, 'btn' ) )
		) {
			return 'button.secondary';
		}
		if (
			str_contains( $role_lower, 'eyebrow' )
			|| str_contains( $role_lower, 'tagline' )
			|| str_contains( $role_lower, 'overline' )
			|| str_contains( $role_lower, 'kicker' )
		) {
			return 'text.eyebrow';
		}
		if (
			str_contains( $role_lower, 'subtitle' )
			|| str_contains( $role_lower, 'description' )
			|| str_contains( $role_lower, 'lead' )
		) {
			return 'text.subtitle';
		}
		if (
			str_contains( $role_lower, 'card' )
			|| str_contains( $role_lower, 'feature' )
			|| str_contains( $role_lower, 'service' )
			|| str_contains( $role_lower, 'pricing' )
		) {
			return str_contains( $role_lower, 'featured' ) ? 'card.featured' : 'card.default';
		}

		return null;
	}

	/**
	 * Detect obviously invalid class_intent strings emitted by models.
	 */
	public static function is_invalid_class_intent( string $intent ): bool {
		$intent = trim( $intent );
		if ( '' === $intent ) {
			return true;
		}

		if ( str_contains( $intent, 'var(' ) || str_contains( $intent, '{' ) || str_contains( $intent, '[' ) || str_contains( $intent, ':' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param array<int, mixed>    $global_classes_to_create
	 * @param array<string, mixed> $log
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_global_classes_to_create( array $global_classes_to_create, array &$log ): array {
		$normalized = [];

		foreach ( $global_classes_to_create as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				$log['global_classes_dropped'][] = 'global_classes_to_create[' . (string) $index . '] was not an object.';
				continue;
			}

			$name = trim( (string) ( $entry['name'] ?? '' ) );
			if ( '' === $name ) {
				$log['global_classes_dropped'][] = 'global_classes_to_create[' . (string) $index . '] had no class name.';
				continue;
			}

			if ( isset( $this->existing_classes_by_name[ $name ] ) ) {
				$log['global_classes_dropped'][] = sprintf( 'Skipped "%s" because the class already exists on the site.', $name );
				continue;
			}

			$settings = $entry['settings'] ?? $entry['styles'] ?? [];
			if ( ! is_array( $settings ) || [] === $settings ) {
				$log['global_classes_dropped'][] = sprintf( 'Skipped "%s" because it had no styles.', $name );
				continue;
			}

			$normalized_styles = StyleNormalizationService::normalize( $settings );
			if ( ! empty( $normalized_styles['warnings'] ) ) {
				$log['global_class_style_warnings'][ $name ] = $normalized_styles['warnings'];
			}

			if ( isset( $normalized[ $name ] ) ) {
				$normalized[ $name ]['settings'] = $this->deep_merge( $normalized[ $name ]['settings'], $normalized_styles['styles'] );
				continue;
			}

			$normalized[ $name ] = [
				'name'     => $name,
				'settings' => $normalized_styles['styles'],
			];

			if ( ! empty( $entry['category'] ) ) {
				$normalized[ $name ]['category'] = (string) $entry['category'];
			}
		}

		return array_values( $normalized );
	}

	/**
	 * @param array<string, mixed>                $plan
	 * @param array<string, true>                 $class_sources
	 * @param array<string, mixed>                $log
	 * @return array<string, mixed>
	 */
	private function normalize_design_plan_roles( array $plan, array $class_sources, array &$log ): array {
		if ( isset( $plan['elements'] ) && is_array( $plan['elements'] ) ) {
			foreach ( $plan['elements'] as $index => $element ) {
				if ( is_array( $element ) ) {
					$plan['elements'][ $index ] = $this->normalize_plan_node( $element, 'elements[' . (string) $index . ']', $class_sources, $log );
				}
			}
		}

		if ( isset( $plan['patterns'] ) && is_array( $plan['patterns'] ) ) {
			foreach ( $plan['patterns'] as $pattern_index => $pattern ) {
				if ( ! is_array( $pattern ) || ! isset( $pattern['element_structure'] ) || ! is_array( $pattern['element_structure'] ) ) {
					continue;
				}

				foreach ( $pattern['element_structure'] as $element_index => $element ) {
					if ( is_array( $element ) ) {
						$pattern['element_structure'][ $element_index ] = $this->normalize_plan_node(
							$element,
							'patterns[' . (string) $pattern_index . '].element_structure[' . (string) $element_index . ']',
							$class_sources,
							$log
						);
					}
				}

				$plan['patterns'][ $pattern_index ] = $pattern;
			}
		}

		return $plan;
	}

	/**
	 * @param array<string, mixed> $node
	 * @param array<string, true>  $class_sources
	 * @param array<string, mixed> $log
	 * @return array<string, mixed>
	 */
	private function normalize_plan_node( array $node, string $path, array $class_sources, array &$log ): array {
		$original_role   = (string) ( $node['role'] ?? '' );
		$normalized_role = self::normalize_role_key( $original_role );
		if ( '' !== $original_role && $normalized_role !== $original_role ) {
			$log['roles_normalized'][ $path ] = [ 'from' => $original_role, 'to' => $normalized_role ];
		}

		if ( '' !== $normalized_role ) {
			$node['role'] = $normalized_role;
		}

		if ( ! isset( $node['class_intent'] ) || ! is_string( $node['class_intent'] ) ) {
			return $node;
		}

		$class_intent = trim( (string) $node['class_intent'] );
		$fallback     = $this->semantic_fallback_class_for_role( $normalized_role );
		$class_key    = $this->normalize_class_key( $class_intent );

		if ( self::is_invalid_class_intent( $class_intent ) ) {
			if ( null !== $fallback ) {
				$node['class_intent'] = $fallback;
				$log['class_intents_rewritten'][ $path ] = [ 'from' => $class_intent, 'to' => $fallback, 'reason' => 'invalid_class_intent' ];
			} else {
				unset( $node['class_intent'] );
				$log['class_intents_removed'][ $path ] = [ 'from' => $class_intent, 'reason' => 'invalid_class_intent' ];
			}
			return $node;
		}

		if ( isset( $class_sources[ $class_key ] ) ) {
			$node['class_intent'] = $class_intent;
			return $node;
		}

		if ( null !== $fallback ) {
			$node['class_intent'] = $fallback;
			$log['class_intents_rewritten'][ $path ] = [ 'from' => $class_intent, 'to' => $fallback, 'reason' => 'semantic_fallback' ];
			return $node;
		}

		unset( $node['class_intent'] );
		$log['class_intents_removed'][ $path ] = [ 'from' => $class_intent, 'reason' => 'no_class_source' ];
		return $node;
	}

	/**
	 * @param array<string, mixed> $content_map
	 * @param array<string, mixed> $log
	 * @return array<string, mixed>
	 */
	private function normalize_content_map( array $content_map, array &$log ): array {
		$normalized = [];

		foreach ( $content_map as $role => $value ) {
			$normalized_role = self::normalize_role_key( (string) $role );
			if ( '' === $normalized_role ) {
				$normalized_role = (string) $role;
			}

			if ( $normalized_role !== (string) $role ) {
				$log['content_roles_normalized'][ (string) $role ] = $normalized_role;
			}

			$normalized[ $normalized_role ] = $value;
		}

		return $normalized;
	}

	/**
	 * @param array<int, array<string, mixed>> $normalized_classes
	 * @return array<string, true>
	 */
	private function build_class_source_index( array $normalized_classes ): array {
		$index = [];

		foreach ( array_keys( $this->existing_classes_by_name ) as $name ) {
			$index[ $this->normalize_class_key( (string) $name ) ] = true;
		}
		foreach ( $normalized_classes as $class ) {
			$name = (string) ( $class['name'] ?? '' );
			if ( '' !== $name ) {
				$index[ $this->normalize_class_key( $name ) ] = true;
			}
		}

		return $index;
	}

	private function semantic_fallback_class_for_role( string $role ): ?string {
		$semantic_role = self::infer_semantic_component_role( $role );
		if ( null === $semantic_role ) {
			return null;
		}

		$resolution = $this->style_roles[ $semantic_role ] ?? null;
		if ( is_array( $resolution ) && ( $resolution['status'] ?? '' ) === 'resolved' && ! empty( $resolution['class_name'] ) ) {
			return (string) $resolution['class_name'];
		}

		$component = $this->component_classes[ $semantic_role ] ?? null;
		if ( is_array( $component ) && ! empty( $component['name'] ) ) {
			return (string) $component['name'];
		}

		return null;
	}

	private function normalize_class_key( string $name ): string {
		return strtolower( trim( $name ) );
	}

	/**
	 * @param array<string, mixed> $left
	 * @param array<string, mixed> $right
	 * @return array<string, mixed>
	 */
	private function deep_merge( array $left, array $right ): array {
		$result = $left;

		foreach ( $right as $key => $value ) {
			if ( isset( $result[ $key ] ) && is_array( $result[ $key ] ) && is_array( $value ) ) {
				$result[ $key ] = $this->deep_merge( $result[ $key ], $value );
			} else {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $log
	 * @return array<string, mixed>
	 */
	private function prune_log( array $log ): array {
		return array_filter(
			$log,
			static function ( $value ): bool {
				return is_array( $value ) ? [] !== $value : null !== $value;
			}
		);
	}
}
