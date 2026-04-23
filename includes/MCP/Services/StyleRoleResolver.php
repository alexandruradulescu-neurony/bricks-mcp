<?php
/**
 * Adaptive semantic style role resolver.
 *
 * Converts site-specific classes and variables into neutral roles the build
 * pipeline can reason about without requiring fixed class or token names.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StyleRoleResolver {

	/**
	 * Minimum confidence where a role can be treated as resolved.
	 */
	public const HIGH_CONFIDENCE = 0.68;

	/**
	 * Minimum confidence where a role is worth surfacing as a candidate.
	 */
	public const CANDIDATE_CONFIDENCE = 0.42;

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $classes;

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $variables;

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $categories;

	/**
	 * @param array<int, array<string, mixed>>|null $classes Global class records. Defaults to current site classes.
	 * @param array<int, array<string, mixed>>|null $variables Bricks variable records. Defaults to current site variables.
	 * @param array<int, array<string, mixed>>|null $categories Bricks variable category records. Defaults to current site categories.
	 */
	public function __construct( ?array $classes = null, ?array $variables = null, ?array $categories = null ) {
		$this->classes    = is_array( $classes ) ? $classes : $this->load_classes();
		$this->variables  = is_array( $variables ) ? $variables : $this->load_variables();
		$this->categories = is_array( $categories ) ? $categories : $this->load_categories();
	}

	/**
	 * Resolve all known semantic style roles.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function resolve_all(): array {
		$resolved = [];

		foreach ( array_keys( self::class_role_specs() ) as $role ) {
			$resolved[ $role ] = $this->resolve_class_role( $role );
		}

		foreach ( array_keys( self::token_role_specs() ) as $role ) {
			$resolved[ $role ] = $this->resolve_token_role( $role );
		}

		return $resolved;
	}

	/**
	 * Resolve a semantic component/text role to the best existing class.
	 *
	 * @return array<string, mixed>
	 */
	public function resolve_class_role( string $role ): array {
		$specs = self::class_role_specs();
		$spec  = $specs[ $role ] ?? null;
		if ( ! is_array( $spec ) ) {
			return $this->unresolved( $role, 'class', 'unknown_role' );
		}

		$manual = $this->manual_class_mapping( $role );
		if ( null !== $manual ) {
			return $manual;
		}

		$candidates = [];
		foreach ( $this->classes as $class ) {
			$name = (string) ( $class['name'] ?? '' );
			$id   = (string) ( $class['id'] ?? '' );
			if ( '' === $name || '' === $id ) {
				continue;
			}

			$settings = is_array( $class['settings'] ?? null ) ? $class['settings'] : [];
			$score    = $this->score_class( $name, $settings, $spec );
			if ( $score < self::CANDIDATE_CONFIDENCE ) {
				continue;
			}

			$candidates[] = [
				'class_name' => $name,
				'class_id'   => $id,
				'confidence' => $score,
				'has_styles' => ! empty( $settings ),
			];
		}

		usort(
			$candidates,
			static fn( array $a, array $b ): int => ( $b['confidence'] <=> $a['confidence'] )
		);

		$best = $candidates[0] ?? null;
		if ( ! is_array( $best ) || (float) $best['confidence'] < self::HIGH_CONFIDENCE ) {
			return [
				'role'       => $role,
				'kind'       => 'class',
				'status'     => 'unresolved',
				'confidence' => is_array( $best ) ? (float) $best['confidence'] : 0.0,
				'candidates' => array_slice( $candidates, 0, 5 ),
				'reason'     => 'No existing class matched this semantic role with high confidence.',
			];
		}

		return [
			'role'       => $role,
			'kind'       => 'class',
			'status'     => 'resolved',
			'source'     => 'existing_class',
			'class_name' => $best['class_name'],
			'class_id'   => $best['class_id'],
			'confidence' => (float) $best['confidence'],
			'candidates' => array_slice( $candidates, 0, 5 ),
			'reason'     => 'Matched by class name evidence and style presence.',
		];
	}

	/**
	 * Resolve a semantic token role to the best existing Bricks variable.
	 *
	 * @return array<string, mixed>
	 */
	public function resolve_token_role( string $role ): array {
		$specs = self::token_role_specs();
		$spec  = $specs[ $role ] ?? null;
		if ( ! is_array( $spec ) ) {
			return $this->unresolved( $role, 'token', 'unknown_role' );
		}

		$manual = $this->manual_token_mapping( $role );
		if ( null !== $manual ) {
			return $manual;
		}

		$category_names = $this->category_id_to_name();
		$candidates     = [];

		foreach ( $this->variables as $variable ) {
			$name = ltrim( (string) ( $variable['name'] ?? '' ), '-' );
			if ( '' === $name ) {
				continue;
			}

			$category = (string) ( $category_names[ (string) ( $variable['category'] ?? '' ) ] ?? '' );
			$score    = $this->score_variable( $name, $category, $spec );
			if ( $score < self::CANDIDATE_CONFIDENCE ) {
				continue;
			}

			$candidates[] = [
				'variable'   => $name,
				'reference'  => 'var(--' . $name . ')',
				'category'   => $category,
				'confidence' => $score,
			];
		}

		usort(
			$candidates,
			static fn( array $a, array $b ): int => ( $b['confidence'] <=> $a['confidence'] )
		);

		$best = $candidates[0] ?? null;
		if ( ! is_array( $best ) || (float) $best['confidence'] < self::HIGH_CONFIDENCE ) {
			return [
				'role'       => $role,
				'kind'       => 'token',
				'status'     => 'unresolved',
				'confidence' => is_array( $best ) ? (float) $best['confidence'] : 0.0,
				'candidates' => array_slice( $candidates, 0, 5 ),
				'reason'     => 'No existing variable matched this semantic role with high confidence.',
			];
		}

		return [
			'role'       => $role,
			'kind'       => 'token',
			'status'     => 'resolved',
			'source'     => 'existing_variable',
			'variable'   => $best['variable'],
			'reference'  => $best['reference'],
			'category'   => $best['category'],
			'confidence' => (float) $best['confidence'],
			'candidates' => array_slice( $candidates, 0, 5 ),
			'reason'     => 'Matched by variable name and category evidence.',
		];
	}

	/**
	 * Class role vocabulary. These are semantic roles and matching clues, not
	 * required class names. Sites can override them through the filter.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function class_role_specs(): array {
		$specs = [
			'button.primary' => [
				'any'   => [ 'btn', 'button', 'cta' ],
				'prefer' => [ 'primary', 'main', 'solid', 'filled', 'brand' ],
				'avoid'  => [ 'secondary', 'ghost', 'outline', 'link' ],
				'style_keys' => [ '_background', '_typography', '_border' ],
			],
			'button.secondary' => [
				'any'   => [ 'btn', 'button', 'cta' ],
				'prefer' => [ 'secondary', 'ghost', 'outline', 'tertiary', 'link' ],
				'avoid'  => [ 'primary', 'main', 'filled' ],
				'style_keys' => [ '_background', '_typography', '_border' ],
			],
			'card.default' => [
				'any'   => [ 'card', 'panel', 'tile', 'box', 'item' ],
				'prefer' => [ 'feature', 'service', 'pricing', 'stat', 'content' ],
				'avoid'  => [ 'button', 'btn', 'link' ],
				'style_keys' => [ '_background', '_padding', '_border', '_boxShadow' ],
			],
			'card.featured' => [
				'any'   => [ 'card', 'panel', 'tile', 'box', 'item' ],
				'prefer' => [ 'featured', 'highlight', 'popular', 'recommended', 'accent' ],
				'avoid'  => [ 'button', 'btn', 'link' ],
				'style_keys' => [ '_background', '_padding', '_border', '_boxShadow' ],
			],
			'text.eyebrow' => [
				'any'   => [ 'eyebrow', 'overline', 'kicker', 'tagline', 'badge', 'label' ],
				'prefer' => [ 'section', 'hero', 'small' ],
				'avoid'  => [ 'button', 'btn', 'card' ],
				'style_keys' => [ '_typography' ],
			],
			'text.subtitle' => [
				'any'   => [ 'subtitle', 'subheading', 'lead', 'description', 'intro' ],
				'prefer' => [ 'hero', 'section', 'body' ],
				'avoid'  => [ 'button', 'btn' ],
				'style_keys' => [ '_typography' ],
			],
		];

		return is_callable( 'apply_filters' )
			? (array) apply_filters( 'bricks_mcp_style_role_class_specs', $specs )
			: $specs;
	}

	/**
	 * Token role vocabulary. These are matching clues, not required variable names.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function token_role_specs(): array {
		$specs = [
			'color.primary' => [
				'category' => 'Colors',
				'exact'    => [ 'primary', 'brand' ],
				'contains' => [ 'primary', 'brand', 'main' ],
				'avoid'    => [ 'trans', 'light', 'dark' ],
			],
			'color.surface.dark' => [
				'category' => 'Colors',
				'exact'    => [ 'base-ultra-dark', 'neutral-ultra-dark' ],
				'contains' => [ 'ultra-dark', 'dark', 'black' ],
				'avoid'    => [ 'trans', 'light' ],
			],
			'color.surface.light' => [
				'category' => 'Colors',
				'exact'    => [ 'base-ultra-light', 'neutral-ultra-light', 'white' ],
				'contains' => [ 'ultra-light', 'light', 'white' ],
				'avoid'    => [ 'trans', 'dark' ],
			],
			'space.content_gap' => [
				'category' => 'Gaps/Padding',
				'exact'    => [ 'content-gap', 'container-gap' ],
				'contains' => [ 'content', 'gap' ],
				'avoid'    => [],
			],
			'space.section_padding' => [
				'category' => 'Gaps/Padding',
				'exact'    => [ 'padding-section', 'space-section' ],
				'contains' => [ 'section', 'padding' ],
				'avoid'    => [],
			],
			'radius.card' => [
				'category' => 'Radius',
				'exact'    => [ 'radius', 'radius-card', 'card-radius' ],
				'contains' => [ 'radius', 'round' ],
				'avoid'    => [],
			],
		];

		return is_callable( 'apply_filters' )
			? (array) apply_filters( 'bricks_mcp_style_role_token_specs', $specs )
			: $specs;
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private function score_class( string $name, array $settings, array $spec ): float {
		$normalized = strtolower( str_replace( [ '_', '-' ], ' ', $name ) );
		$score      = 0.0;

		if ( $this->contains_any( $normalized, (array) ( $spec['any'] ?? [] ) ) ) {
			$score += 0.35;
		}
		if ( $this->contains_any( $normalized, (array) ( $spec['prefer'] ?? [] ) ) ) {
			$score += 0.25;
		}
		if ( $this->contains_any( $normalized, (array) ( $spec['avoid'] ?? [] ) ) ) {
			$score -= 0.25;
		}
		if ( ! empty( $settings ) ) {
			$score += 0.15;
		}
		if ( $this->has_any_setting_key( $settings, (array) ( $spec['style_keys'] ?? [] ) ) ) {
			$score += 0.2;
		}

		return max( 0.0, min( 1.0, round( $score, 2 ) ) );
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private function score_variable( string $name, string $category, array $spec ): float {
		$normalized = strtolower( $name );
		$score      = 0.0;

		if ( isset( $spec['category'] ) && strcasecmp( (string) $spec['category'], $category ) === 0 ) {
			$score += 0.22;
		}
		foreach ( (array) ( $spec['exact'] ?? [] ) as $exact ) {
			if ( $normalized === strtolower( (string) $exact ) ) {
				$score += 0.55;
				break;
			}
		}
		if ( $this->contains_any( $normalized, (array) ( $spec['contains'] ?? [] ) ) ) {
			$score += 0.24;
		}
		if ( $this->contains_any( $normalized, (array) ( $spec['avoid'] ?? [] ) ) ) {
			$score -= 0.2;
		}

		return max( 0.0, min( 1.0, round( $score, 2 ) ) );
	}

	/**
	 * @param array<int, string> $needles
	 */
	private function contains_any( string $haystack, array $needles ): bool {
		foreach ( $needles as $needle ) {
			$needle = strtolower( trim( (string) $needle ) );
			if ( '' !== $needle && str_contains( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<int, string>   $keys
	 */
	private function has_any_setting_key( array $settings, array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<string, string>
	 */
	private function category_id_to_name(): array {
		$map = [];
		foreach ( $this->categories as $category ) {
			$id = (string) ( $category['id'] ?? '' );
			if ( '' !== $id ) {
				$map[ $id ] = (string) ( $category['name'] ?? '' );
			}
		}
		return $map;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function unresolved( string $role, string $kind, string $reason ): array {
		return [
			'role'       => $role,
			'kind'       => $kind,
			'status'     => 'unresolved',
			'confidence' => 0.0,
			'candidates' => [],
			'reason'     => $reason,
		];
	}

	/**
	 * Return an explicit class mapping from site options if configured.
	 *
	 * @return array<string, mixed>|null
	 */
	private function manual_class_mapping( string $role ): ?array {
		$map  = self::manual_role_map();
		$name = (string) ( $map['classes'][ $role ] ?? '' );
		if ( '' === $name ) {
			return null;
		}

		foreach ( $this->classes as $class ) {
			if ( (string) ( $class['name'] ?? '' ) !== $name ) {
				continue;
			}
			return [
				'role'       => $role,
				'kind'       => 'class',
				'status'     => 'resolved',
				'source'     => 'manual_map',
				'class_name' => $name,
				'class_id'   => (string) ( $class['id'] ?? '' ),
				'confidence' => 1.0,
				'candidates' => [],
				'reason'     => 'Explicit site style-role mapping.',
			];
		}

		return [
			'role'       => $role,
			'kind'       => 'class',
			'status'     => 'unresolved',
			'confidence' => 0.0,
			'candidates' => [],
			'reason'     => sprintf( 'Manual mapping points to missing class "%s".', $name ),
		];
	}

	/**
	 * Return an explicit token mapping from site options if configured.
	 *
	 * @return array<string, mixed>|null
	 */
	private function manual_token_mapping( string $role ): ?array {
		$map  = self::manual_role_map();
		$name = ltrim( (string) ( $map['tokens'][ $role ] ?? '' ), '-' );
		if ( '' === $name ) {
			return null;
		}

		$category_names = $this->category_id_to_name();
		foreach ( $this->variables as $variable ) {
			if ( ltrim( (string) ( $variable['name'] ?? '' ), '-' ) !== $name ) {
				continue;
			}

			$category = (string) ( $category_names[ (string) ( $variable['category'] ?? '' ) ] ?? '' );
			return [
				'role'       => $role,
				'kind'       => 'token',
				'status'     => 'resolved',
				'source'     => 'manual_map',
				'variable'   => $name,
				'reference'  => 'var(--' . $name . ')',
				'category'   => $category,
				'confidence' => 1.0,
				'candidates' => [],
				'reason'     => 'Explicit site style-role mapping.',
			];
		}

		return [
			'role'       => $role,
			'kind'       => 'token',
			'status'     => 'unresolved',
			'confidence' => 0.0,
			'candidates' => [],
			'reason'     => sprintf( 'Manual mapping points to missing variable "%s".', $name ),
		];
	}

	/**
	 * Read persistent manual role mappings.
	 *
	 * @return array{classes: array<string, string>, tokens: array<string, string>}
	 */
	public static function manual_role_map(): array {
		$raw = get_option( BricksCore::OPTION_STYLE_ROLE_MAP, [] );
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		return [
			'classes' => is_array( $raw['classes'] ?? null ) ? array_map( 'strval', $raw['classes'] ) : [],
			'tokens'  => is_array( $raw['tokens'] ?? null ) ? array_map( 'strval', $raw['tokens'] ) : [],
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function load_classes(): array {
		$raw = get_option( BricksCore::OPTION_GLOBAL_CLASSES, [] );
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
	private function load_categories(): array {
		$raw = get_option( BricksCore::OPTION_VARIABLE_CATEGORIES, [] );
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
	}
}
