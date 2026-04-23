<?php
/**
 * Design system readiness and introspection.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DesignSystemIntrospector {

	private ?GlobalClassService $class_service;

	public function __construct( ?GlobalClassService $class_service = null ) {
		$this->class_service = $class_service;
	}

	/**
	 * Analyze the current Bricks site design system.
	 *
	 * The response distinguishes foundations (tokens/base CSS) from component
	 * classes and patterns. A fresh site, plugin-injected site, and custom
	 * existing site therefore produce different operating modes.
	 *
	 * @return array<string, mixed>
	 */
	public function analyze(): array {
		$categories      = $this->load_categories();
		$variables       = $this->load_variables();
		$classes         = $this->load_classes();
		$pattern_records = $this->load_patterns();
		$custom_css      = $this->load_custom_css();

		$category_names      = $this->category_names( $categories );
		$owned_category_hits = array_values( array_intersect( $category_names, DesignSystemGenerator::OWNED_CATEGORIES ) );
		$framework_css       = $this->has_framework_css( $custom_css );
		$styled_classes      = array_values( array_filter(
			$classes,
			static fn( array $class ): bool => ! empty( $class['settings'] ) && is_array( $class['settings'] )
		) );
		$empty_classes       = max( 0, count( $classes ) - count( $styled_classes ) );

		$role_resolver = new StyleRoleResolver( $classes, $variables, $categories );
		$style_roles   = $role_resolver->resolve_all();

		$resolved_class_roles = array_values( array_filter(
			$style_roles,
			static fn( array $role ): bool => ( $role['kind'] ?? '' ) === 'class' && ( $role['status'] ?? '' ) === 'resolved'
		) );
		$resolved_token_roles = array_values( array_filter(
			$style_roles,
			static fn( array $role ): bool => ( $role['kind'] ?? '' ) === 'token' && ( $role['status'] ?? '' ) === 'resolved'
		) );
		$unresolved_roles = array_keys( array_filter(
			$style_roles,
			static fn( array $role ): bool => ( $role['status'] ?? '' ) !== 'resolved'
		) );

		$foundation_score = $this->foundation_score(
			count( $variables ),
			count( $category_names ),
			count( $owned_category_hits ),
			$framework_css
		);
		$component_score = $this->component_score(
			count( $styled_classes ),
			count( $resolved_class_roles )
		);
		$pattern_score = min( 100, count( $pattern_records ) * 20 );

		$foundation_ready = $foundation_score >= 55;
		$components_ready = $component_score >= 55;
		$patterns_ready   = count( $pattern_records ) > 0;

		return [
			'readiness_version' => 1,
			'operating_mode'    => $this->operating_mode( $foundation_ready, $components_ready, $patterns_ready ),
			'readiness'         => [
				'foundation_design_system' => [
					'ready' => $foundation_ready,
					'score' => $foundation_score,
				],
				'component_style_layer' => [
					'ready' => $components_ready,
					'score' => $component_score,
				],
				'pattern_library' => [
					'ready' => $patterns_ready,
					'score' => $pattern_score,
				],
				'ready_for_design_build' => [
					'ready' => $foundation_ready && ( $components_ready || $patterns_ready ),
					'score' => min( 100, (int) round( ( $foundation_score + $component_score + $pattern_score ) / 3 ) ),
				],
			],
			'foundation'        => [
				'variables_count'         => count( $variables ),
				'variable_categories'     => $category_names,
				'owned_categories_detected' => $owned_category_hits,
				'framework_css_injected'  => $framework_css,
				'structured_brief_fields' => count( array_filter( BriefResolver::load_raw(), static fn( $value ): bool => is_scalar( $value ) && '' !== trim( (string) $value ) ) ),
			],
			'components'        => [
				'classes_count'         => count( $classes ),
				'styled_classes_count'  => count( $styled_classes ),
				'empty_classes_count'   => $empty_classes,
				'resolved_class_roles'  => array_column( $resolved_class_roles, 'role' ),
				'unresolved_style_roles' => $unresolved_roles,
			],
			'patterns'          => [
				'count' => count( $pattern_records ),
			],
			'style_roles'       => $style_roles,
			'adaptive_policy'   => [
				'use_existing_when_confident' => true,
				'create_missing_component_classes_only_when_allowed' => true,
				'do_not_overwrite_user_owned_classes' => true,
				'candidate_names_are_evidence_not_requirements' => true,
			],
			'next_actions'      => $this->next_actions( $foundation_ready, $components_ready, $patterns_ready, $unresolved_roles, count( $resolved_token_roles ) ),
		];
	}

	private function foundation_score( int $variables, int $categories, int $owned_categories, bool $framework_css ): int {
		$score = 0;
		$score += min( 35, $variables * 2 );
		$score += min( 20, $categories * 3 );
		$score += min( 25, $owned_categories * 3 );
		$score += $framework_css ? 20 : 0;
		return min( 100, $score );
	}

	private function component_score( int $styled_classes, int $resolved_class_roles ): int {
		$score = 0;
		$score += min( 45, $styled_classes * 6 );
		$score += min( 55, $resolved_class_roles * 14 );
		return min( 100, $score );
	}

	private function operating_mode( bool $foundation_ready, bool $components_ready, bool $patterns_ready ): string {
		if ( ! $foundation_ready ) {
			return 'fresh_site_needs_foundation';
		}
		if ( $components_ready && $patterns_ready ) {
			return 'adaptive_existing_system_with_patterns';
		}
		if ( $components_ready ) {
			return 'adaptive_existing_system';
		}
		if ( $patterns_ready ) {
			return 'foundation_with_patterns';
		}
		return 'foundation_only_needs_component_layer';
	}

	/**
	 * @param array<int, string> $unresolved_roles
	 * @return array<int, string>
	 */
	private function next_actions( bool $foundation_ready, bool $components_ready, bool $patterns_ready, array $unresolved_roles, int $resolved_token_count ): array {
		$actions = [];

		if ( ! $foundation_ready ) {
			$actions[] = 'Apply or map a foundation design system before relying on section generation.';
		}
		if ( $resolved_token_count < 3 ) {
			$actions[] = 'Map core color, spacing, and radius tokens so generated styles use site variables instead of literals.';
		}
		if ( ! $components_ready ) {
			$actions[] = 'Map or generate component classes for buttons, cards, subtitles, and eyebrows.';
		}
		if ( ! $patterns_ready ) {
			$actions[] = 'Capture real Bricks sections as design patterns if the site already has preferred layouts.';
		}
		if ( ! empty( $unresolved_roles ) ) {
			$actions[] = 'Unresolved style roles: ' . implode( ', ', array_slice( $unresolved_roles, 0, 8 ) ) . '.';
		}

		return $actions;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function load_categories(): array {
		$raw = get_option( BricksCore::OPTION_VARIABLE_CATEGORIES, [] );
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function load_variables(): array {
		$raw = get_option( BricksCore::OPTION_GLOBAL_VARIABLES, [] );
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function load_classes(): array {
		if ( null !== $this->class_service ) {
			return $this->class_service->get_global_classes();
		}
		$raw = get_option( BricksCore::OPTION_GLOBAL_CLASSES, [] );
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function load_patterns(): array {
		$raw = get_option( BricksCore::OPTION_PATTERNS, [] );
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
	}

	private function load_custom_css(): string {
		$settings = get_option( BricksCore::OPTION_GLOBAL_SETTINGS, [] );
		if ( ! is_array( $settings ) ) {
			return '';
		}
		return is_string( $settings['customCss'] ?? null ) ? $settings['customCss'] : '';
	}

	private function has_framework_css( string $custom_css ): bool {
		return str_contains( $custom_css, 'BricksCore Framework Start' )
			&& str_contains( $custom_css, 'BricksCore Framework End' );
	}

	/**
	 * @param array<int, array<string, mixed>> $categories
	 * @return array<int, string>
	 */
	private function category_names( array $categories ): array {
		$names = [];
		foreach ( $categories as $category ) {
			$name = (string) ( $category['name'] ?? '' );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		return array_values( array_unique( $names ) );
	}
}
