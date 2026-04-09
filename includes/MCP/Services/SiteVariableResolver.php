<?php
/**
 * Site variable resolver.
 *
 * Dynamically resolves CSS variable references from the site's actual
 * bricks_global_variables at runtime. Eliminates hardcoded variable names
 * in the build pipeline.
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
 * Resolves CSS variables dynamically from site configuration.
 */
final class SiteVariableResolver {

	/**
	 * Cached variables (flat array).
	 *
	 * @var array<int, array{name: string, value: string, category: string}>|null
	 */
	private static ?array $variables = null;

	/**
	 * Cached categories.
	 *
	 * @var array<int, array{id: string, name: string}>|null
	 */
	private static ?array $categories = null;

	/**
	 * Variables indexed by category name.
	 *
	 * @var array<string, array<int, array{name: string, value: string}>>|null
	 */
	private static ?array $by_category = null;

	/**
	 * Variables indexed by name.
	 *
	 * @var array<string, string>|null  name => value
	 */
	private static ?array $by_name = null;

	/**
	 * Load variables from database (once per request).
	 */
	private static function load(): void {
		if ( null !== self::$variables ) {
			return;
		}

		self::$categories = get_option( 'bricks_global_variables_categories', [] );
		self::$variables  = get_option( 'bricks_global_variables', [] );

		if ( ! is_array( self::$categories ) ) {
			self::$categories = [];
		}
		if ( ! is_array( self::$variables ) ) {
			self::$variables = [];
		}

		// Build category ID → name map.
		$cat_id_to_name = [];
		foreach ( self::$categories as $cat ) {
			$cat_id_to_name[ $cat['id'] ?? '' ] = $cat['name'] ?? '';
		}

		// Index by category name and by variable name.
		self::$by_category = [];
		self::$by_name     = [];

		foreach ( self::$variables as $var ) {
			$name     = $var['name'] ?? '';
			$value    = $var['value'] ?? '';
			$cat_id   = $var['category'] ?? '';
			$cat_name = $cat_id_to_name[ $cat_id ] ?? 'Uncategorized';

			if ( '' === $name ) {
				continue;
			}

			self::$by_name[ $name ] = $value;

			if ( ! isset( self::$by_category[ $cat_name ] ) ) {
				self::$by_category[ $cat_name ] = [];
			}
			self::$by_category[ $cat_name ][] = [ 'name' => $name, 'value' => $value ];
		}
	}

	/**
	 * Get a variable reference by exact name.
	 *
	 * @param string $name    Variable name (without --).
	 * @param string $default Fallback if not found.
	 * @return string CSS var() reference.
	 */
	public static function get( string $name, string $default = '' ): string {
		self::load();
		if ( isset( self::$by_name[ $name ] ) ) {
			return "var(--{$name})";
		}
		return $default;
	}

	/**
	 * Find first variable in a category matching a pattern.
	 *
	 * @param string   $category_name Category name (e.g., "Colors", "Spacing").
	 * @param callable $matcher       Function(string $name): bool.
	 * @param string   $default       Fallback value.
	 * @return string CSS var() reference.
	 */
	public static function find_in_category( string $category_name, callable $matcher, string $default = '' ): string {
		self::load();
		$vars = self::$by_category[ $category_name ] ?? [];
		foreach ( $vars as $var ) {
			if ( $matcher( $var['name'] ) ) {
				return 'var(--' . $var['name'] . ')';
			}
		}
		return $default;
	}

	/**
	 * Find the darkest background color variable.
	 *
	 * Searches Colors category for a variable containing "ultra-dark" (not trans).
	 *
	 * @return string CSS var() reference.
	 */
	public static function dark_background(): string {
		return self::find_in_category(
			'Colors',
			static fn( string $name ) => str_contains( $name, 'ultra-dark' ) && ! str_contains( $name, 'trans' ),
			'var(--base-ultra-dark)'
		);
	}

	/**
	 * Find the lightest background color variable.
	 *
	 * Searches Colors category for a variable containing "ultra-light".
	 *
	 * @return string CSS var() reference.
	 */
	public static function light_background(): string {
		return self::find_in_category(
			'Colors',
			static fn( string $name ) => str_contains( $name, 'ultra-light' ) && ! str_contains( $name, 'trans' ) && str_contains( $name, 'base' ),
			'var(--base-ultra-light)'
		);
	}

	/**
	 * Find the white color variable.
	 *
	 * @return string CSS var() reference.
	 */
	public static function white_color(): string {
		return self::get( 'white', 'var(--white)' );
	}

	/**
	 * Find the primary color variable.
	 *
	 * @return string CSS var() reference.
	 */
	public static function primary_color(): string {
		return self::find_in_category(
			'Colors',
			static fn( string $name ) => 'primary' === $name,
			'var(--primary)'
		);
	}

	/**
	 * Find the primary-dark color variable.
	 *
	 * @return string CSS var() reference.
	 */
	public static function primary_dark_color(): string {
		return self::find_in_category(
			'Colors',
			static fn( string $name ) => 'primary-dark' === $name || 'primary_dark' === $name,
			'var(--primary-dark)'
		);
	}

	/**
	 * Resolve a spacing variable by purpose and tier.
	 *
	 * @param string $purpose 'gap', 'padding', or 'section-padding'.
	 * @param string $tier    'compact', 'normal', or 'spacious'.
	 * @return string CSS var() reference.
	 */
	public static function spacing( string $purpose, string $tier = 'normal' ): string {
		self::load();

		// Search in Gaps/Padding category first, then Spacing.
		$gap_vars     = self::$by_category['Gaps/Padding'] ?? [];
		$spacing_vars = self::$by_category['Spacing'] ?? [];

		return match ( $purpose ) {
			'gap' => match ( $tier ) {
				'compact'  => self::find_gap_var( $gap_vars, $spacing_vars, [ 'grid-gap-s', 'gap-s' ], 'var(--space-m)' ),
				'normal'   => self::find_gap_var( $gap_vars, $spacing_vars, [ 'grid-gap' ], 'var(--space-l)' ),
				'spacious' => self::find_gap_var( $gap_vars, $spacing_vars, [ 'container-gap' ], 'var(--space-xl)' ),
				default    => self::find_gap_var( $gap_vars, $spacing_vars, [ 'grid-gap' ], 'var(--space-l)' ),
			},
			'padding' => match ( $tier ) {
				'compact'  => self::find_spacing_var( $spacing_vars, [ 'space-m' ], 'var(--space-m)' ),
				'normal'   => self::find_gap_var( $gap_vars, $spacing_vars, [ 'content-gap' ], 'var(--space-m)' ),
				'spacious' => self::find_spacing_var( $spacing_vars, [ 'space-xl' ], 'var(--space-xl)' ),
				default    => self::find_gap_var( $gap_vars, $spacing_vars, [ 'content-gap' ], 'var(--space-m)' ),
			},
			'section-padding' => match ( $tier ) {
				'compact'  => self::find_spacing_var( $spacing_vars, [ 'space-l' ], 'var(--space-l)' ),
				'normal'   => self::find_gap_var( $gap_vars, $spacing_vars, [ 'padding-section' ], 'var(--space-xl)' ),
				'spacious' => self::find_spacing_var( $spacing_vars, [ 'space-section' ], 'var(--space-section)' ),
				default    => self::find_gap_var( $gap_vars, $spacing_vars, [ 'padding-section' ], 'var(--space-xl)' ),
			},
			default => 'var(--space-m)',
		};
	}

	/**
	 * Find a gap/padding variable by preferred names.
	 *
	 * @param array<int, array{name: string}> $gap_vars     Gaps/Padding category variables.
	 * @param array<int, array{name: string}> $spacing_vars Spacing category variables.
	 * @param array<int, string>              $preferred    Preferred variable names in order.
	 * @param string                          $default      Fallback.
	 * @return string CSS var() reference.
	 */
	private static function find_gap_var( array $gap_vars, array $spacing_vars, array $preferred, string $default ): string {
		// Search gaps first, then spacing.
		$all = array_merge( $gap_vars, $spacing_vars );
		foreach ( $preferred as $pref ) {
			foreach ( $all as $var ) {
				if ( $var['name'] === $pref ) {
					return 'var(--' . $var['name'] . ')';
				}
			}
		}
		// Fallback: try partial match.
		foreach ( $preferred as $pref ) {
			foreach ( $all as $var ) {
				if ( str_contains( $var['name'], $pref ) ) {
					return 'var(--' . $var['name'] . ')';
				}
			}
		}
		return $default;
	}

	/**
	 * Find a spacing variable by preferred names.
	 *
	 * @param array<int, array{name: string}> $spacing_vars Spacing category variables.
	 * @param array<int, string>              $preferred    Preferred variable names in order.
	 * @param string                          $default      Fallback.
	 * @return string CSS var() reference.
	 */
	private static function find_spacing_var( array $spacing_vars, array $preferred, string $default ): string {
		foreach ( $preferred as $pref ) {
			foreach ( $spacing_vars as $var ) {
				if ( $var['name'] === $pref ) {
					return 'var(--' . $var['name'] . ')';
				}
			}
		}
		return $default;
	}

	/**
	 * Clear cached data (useful for testing).
	 */
	public static function reset(): void {
		self::$variables   = null;
		self::$categories  = null;
		self::$by_category = null;
		self::$by_name     = null;
	}
}
