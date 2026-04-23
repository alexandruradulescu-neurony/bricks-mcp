<?php
/**
 * Site variable resolver.
 *
 * Reads CSS variables from the site's Bricks global variables at runtime.
 * Provides semantic lookup methods for spacing, colors, and backgrounds
 * instead of hardcoding variable names.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVariableResolver {

	/** @var array<string, array<string, mixed>>|null Variables indexed by name. */
	private static ?array $by_name = null;

	/** @var array<string, array<int, array<string, mixed>>>|null Variables grouped by category name. */
	private static ?array $by_category = null;

	/**
	 * Load all variables and categories from WordPress options. Cached per request.
	 */
	private static function load(): void {
		if ( null !== self::$by_name ) {
			return;
		}

		$categories = get_option( BricksCore::OPTION_VARIABLE_CATEGORIES, [] );
		$variables  = get_option( BricksCore::OPTION_GLOBAL_VARIABLES, [] );

		if ( ! is_array( $categories ) ) {
			$categories = [];
		}
		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		// Build category ID → name map.
		$cat_names = [];
		foreach ( $categories as $cat ) {
			$cat_names[ $cat['id'] ?? '' ] = $cat['name'] ?? '';
		}

		self::$by_name     = [];
		self::$by_category = [];

		foreach ( $variables as $var ) {
			$raw_name = $var['name'] ?? '';
			$name     = is_string( $raw_name ) ? ltrim( $raw_name, '-' ) : '';
			$cat_id   = $var['category'] ?? '';
			$cat_name = $cat_names[ $cat_id ] ?? 'uncategorized';

			if ( '' === $name ) {
				continue;
			}

			$normalized_var         = $var;
			$normalized_var['name'] = $name;

			self::$by_name[ $name ] = $normalized_var;

			if ( ! isset( self::$by_category[ $cat_name ] ) ) {
				self::$by_category[ $cat_name ] = [];
			}
			self::$by_category[ $cat_name ][] = $normalized_var;
		}
	}

	/**
	 * Get a variable reference by exact name. Returns "var(--name)" or the fallback.
	 */
	public static function get( string $name, string $fallback = '' ): string {
		self::load();
		if ( isset( self::$by_name[ $name ] ) ) {
			return "var(--{$name})";
		}
		return $fallback;
	}

	/**
	 * Find a variable in a category by pattern matching on the variable name.
	 * Returns "var(--name)" or the fallback.
	 *
	 * @param string $category_name Category name (e.g., "Colors", "Spacing", "Gaps/Padding").
	 * @param string $contains      Substring the variable name must contain.
	 * @param string $not_contains  Optional substring the variable name must NOT contain.
	 * @param string $fallback      Fallback if no match found.
	 */
	public static function find( string $category_name, string $contains, string $not_contains = '', string $fallback = '' ): string {
		self::load();

		$vars = self::$by_category[ $category_name ] ?? [];

		foreach ( $vars as $var ) {
			$name = $var['name'] ?? '';
			if ( '' === $name ) {
				continue;
			}
			if ( str_contains( $name, $contains ) ) {
				if ( '' !== $not_contains && str_contains( $name, $not_contains ) ) {
					continue;
				}
				return "var(--{$name})";
			}
		}

		return $fallback;
	}

	/**
	 * Whether the site has any CSS variables configured.
	 */
	public static function has_variables(): bool {
		self::load();
		return ! empty( self::$by_name );
	}

	/**
	 * Whether a Bricks CSS variable exists by name.
	 *
	 * Accepts bare names ("primary") or CSS custom property names ("--primary").
	 *
	 * @param string $name Variable name.
	 * @return bool True when the variable exists.
	 */
	public static function exists( string $name ): bool {
		self::load();
		$name = ltrim( trim( $name ), '-' );
		return '' !== $name && isset( self::$by_name[ $name ] );
	}

	/**
	 * Find the darkest background color variable.
	 */
	public static function dark_background(): string {
		// Look in Colors category for a variable with "ultra-dark" (not transparent).
		$result = self::find( 'Colors', 'ultra-dark', 'trans', '' );
		if ( '' !== $result ) {
			return $result;
		}
		// Fallback: any variable with "dark" in Colors.
		$result = self::find( 'Colors', 'dark', 'trans', '' );
		if ( '' !== $result ) {
			return $result;
		}
		return self::has_variables() ? 'var(--base-ultra-dark)' : '#18181b';
	}

	/**
	 * Find the lightest background color variable.
	 */
	public static function light_background(): string {
		$result = self::find( 'Colors', 'ultra-light', 'trans', '' );
		if ( '' !== $result ) {
			return $result;
		}
		$result = self::find( 'Colors', 'light', 'trans', '' );
		if ( '' !== $result ) {
			return $result;
		}
		return self::has_variables() ? 'var(--base-ultra-light)' : '#f4f4f5';
	}

	/**
	 * Find the white color variable.
	 */
	public static function white_color(): string {
		// Exact match first.
		$exact = self::get( 'white', '' );
		if ( '' !== $exact ) {
			return $exact;
		}
		// Search in Colors for "white" without "trans".
		$found = self::find( 'Colors', 'white', 'trans', '' );
		if ( '' !== $found ) {
			return $found;
		}
		return self::has_variables() ? 'var(--white)' : '#ffffff';
	}

	/**
	 * Find the primary color variable.
	 */
	public static function primary_color(): string {
		// Exact match "primary".
		$exact = self::get( 'primary', '' );
		if ( '' !== $exact ) {
			return $exact;
		}
		$found = self::find( 'Colors', 'primary', 'dark', '' );
		if ( '' !== $found ) {
			return $found;
		}
		return self::has_variables() ? 'var(--primary)' : '#3f4fdf';
	}

	/**
	 * Find the primary dark color variable.
	 */
	public static function primary_dark_color(): string {
		$exact = self::get( 'primary-dark', '' );
		if ( '' !== $exact ) {
			return $exact;
		}
		$found = self::find( 'Colors', 'primary-dark', '', '' );
		if ( '' !== $found ) {
			return $found;
		}
		return self::has_variables() ? 'var(--primary-dark)' : '#2d3ab8';
	}

	/**
	 * Get a spacing value based on purpose and tier.
	 *
	 * @param string $purpose 'gap', 'padding', or 'section-padding'.
	 * @param string $tier    'compact', 'normal', or 'spacious'.
	 */
	public static function spacing( string $purpose, string $tier ): string {
		self::load();

		return match ( "{$purpose}:{$tier}" ) {
			'gap:compact'             => self::find_spacing_var( 'gap', true ),
			'gap:normal'              => self::find_spacing_var( 'gap', false ),
			'gap:spacious'            => self::find_spacing_var( 'container', false ),
			'padding:compact'         => self::find_size_var( 'space-m' ),
			'padding:normal'          => self::find_spacing_var( 'content', false ),
			'padding:spacious'        => self::find_size_var( 'space-xl' ),
			'section-padding:compact' => self::find_size_var( 'space-l' ),
			'section-padding:normal'  => self::find_spacing_var( 'padding-section', false ),
			'section-padding:spacious' => self::find_size_var( 'space-section' ),
			default                    => self::find_spacing_var( 'gap', false ),
		};
	}

	/**
	 * Find a gap/padding variable from the Gaps/Padding category.
	 *
	 * @param string $contains Substring to search for.
	 * @param bool   $small    If true, prefer the smaller variant (e.g., grid-gap-s).
	 */
	private static function find_spacing_var( string $contains, bool $small ): string {
		$result = self::find( 'Gaps/Padding', $contains, '', '' );
		if ( '' !== $result ) {
			if ( $small ) {
				// Look for a "-s" variant first.
				$small_result = self::find( 'Gaps/Padding', $contains . '-s', '', '' );
				if ( '' !== $small_result ) {
					return $small_result;
				}
				$small_result = self::find( 'Gaps/Padding', $contains . 's', '', '' );
				if ( '' !== $small_result ) {
					return $small_result;
				}
			}
			return $result;
		}
		// Fallback to Spacing category.
		$spacing = self::find( 'Spacing', $contains, '', '' );
		if ( '' !== $spacing ) {
			return $spacing;
		}
		return self::has_variables() ? 'var(--space-m)' : '16px';
	}

	/**
	 * Find a size/spacing variable by exact name from Spacing category.
	 */
	private static function find_size_var( string $name ): string {
		$exact = self::get( $name, '' );
		if ( '' !== $exact ) {
			return $exact;
		}
		if ( self::has_variables() ) {
			return "var(--{$name})";
		}
		// Raw fallbacks when no variables configured.
		return match ( $name ) {
			'space-xs' => '8px',
			'space-s'  => '12px',
			'space-m'  => '16px',
			'space-l'  => '24px',
			'space-xl' => '48px',
			'space-section' => '64px',
			default    => '16px',
		};
	}

	/**
	 * Get all variables grouped by category name.
	 *
	 * Returns the cached data from load(). Each key is a category name,
	 * each value is an array of variable arrays with 'name', 'value', etc.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Variables by category.
	 */
	public static function get_variables_by_category(): array {
		self::load();
		return self::$by_category ?? [];
	}

	/**
	 * Clear the cache (useful for testing).
	 */
	public static function clear_cache(): void {
		self::$by_name     = null;
		self::$by_category = null;
	}
}
