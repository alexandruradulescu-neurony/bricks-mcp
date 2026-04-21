<?php
/**
 * Design brief resolver.
 *
 * Single source of truth for structured design brief values.
 * Resolves each field through a fallback chain:
 *   1. Structured brief (wp_option)
 *   2. Auto-detect from site variables/classes
 *   3. Default constant
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BriefResolver class.
 */
final class BriefResolver {

	/**
	 * WordPress option key for the structured brief.
	 *
	 * @var string
	 */
	public const OPTION_KEY = BricksCore::OPTION_STRUCTURED_BRIEF;

	/**
	 * Loaded brief values from wp_option.
	 *
	 * @var array<string, string>
	 */
	private array $brief;

	/**
	 * Lazy-loaded site global variables.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $site_variables = null;

	/**
	 * Lazy-loaded site global classes.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $site_classes = null;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Constructor. Loads the brief from wp_option.
	 */
	private function __construct() {
		$raw = get_option( self::OPTION_KEY, [] );

		$this->brief = is_array( $raw ) ? $raw : [];
	}

	/**
	 * Get or create the singleton instance. Cached per PHP request.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	// ──────────────────────────────────────────────────────────────
	// Main API.
	// ──────────────────────────────────────────────────────────────

	/**
	 * Resolve a single brief field through the fallback chain.
	 *
	 * 1. Structured brief value (explicit user override).
	 * 2. Auto-detect from site variables/classes.
	 * 3. Hardcoded default.
	 *
	 * @param string $key Brief field key.
	 * @return string Resolved value (may be empty string for fields with no default).
	 */
	public function get( string $key ): string {
		// 1. Structured brief value.
		$value = $this->brief[ $key ] ?? '';
		if ( '' !== $value ) {
			return $value;
		}

		// 2. Auto-detect from site.
		$detected = $this->auto_detect( $key );
		if ( '' !== $detected ) {
			return $detected;
		}

		// 3. Default.
		return self::defaults()[ $key ] ?? '';
	}

	/**
	 * Resolve all brief fields and return as an associative array.
	 *
	 * @return array<string, string> All resolved field values.
	 */
	public function all(): array {
		$resolved = [];

		foreach ( array_keys( self::defaults() ) as $key ) {
			$resolved[ $key ] = $this->get( $key );
		}

		return $resolved;
	}

	// ──────────────────────────────────────────────────────────────
	// Save / load.
	// ──────────────────────────────────────────────────────────────

	/**
	 * Persist a structured brief to wp_options.
	 *
	 * @param array<string, string> $brief Key-value pairs to save.
	 */
	public static function save( array $brief ): void {
		update_option( self::OPTION_KEY, $brief, false );

		// Bust the singleton so the next get_instance() picks up new data.
		self::$instance = null;
	}

	/**
	 * Load the raw brief from wp_option without any fallback resolution.
	 *
	 * @return array<string, string> Raw stored values.
	 */
	public static function load_raw(): array {
		$raw = get_option( self::OPTION_KEY, [] );

		return is_array( $raw ) ? $raw : [];
	}

	// ──────────────────────────────────────────────────────────────
	// Defaults.
	// ──────────────────────────────────────────────────────────────

	/**
	 * Return the canonical default values for every brief field.
	 *
	 * This is the ONLY place hardcoded values live. All fields must
	 * appear here even if the default is an empty string.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return [
			'dark_bg_color'        => '#1a1a2e',
			'dark_text_color'      => '#ffffff',
			'dark_subtitle_color'  => 'rgba(255,255,255,0.7)',
			'light_bg_color'       => '',
			'light_alt_bg_color'   => '#f6f6f7',
			'card_radius'          => '8px',
			'card_border_color'    => '#e4e4e8',
			'card_padding'         => '24px',
			'section_header_align' => 'center',
			'btn_primary_class'    => '',
			'btn_secondary_class'  => '',
			'eyebrow_class'        => '',
			'icon_library'         => 'themify',
			'grid_gap'             => '24px',
			'content_gap'          => '20px',
			'container_gap'        => '40px',
			'hero_min_height'      => '80vh',
			'icon_circle_size'     => '48px',
		];
	}

	// ──────────────────────────────────────────────────────────────
	// Auto-detect.
	// ──────────────────────────────────────────────────────────────

	/**
	 * Attempt to resolve a field by inspecting site variables and classes.
	 *
	 * @param string $key Brief field key.
	 * @return string Detected value, or empty string if nothing found.
	 */
	private function auto_detect( string $key ): string {
		return match ( $key ) {
			'dark_bg_color'       => $this->find_variable( 'base-ultra-dark' ) ?? '',
			'dark_text_color'     => $this->find_variable( 'white' ) ?? '',
			'dark_subtitle_color' => $this->find_variable( 'white-trans-70' ) ?? '',
			'light_alt_bg_color'  => $this->find_variable( 'base-ultra-light' ) ?? '',
			'card_radius'         => $this->find_variable( 'radius' ) ?? '',
			'card_border_color'   => $this->find_variable( 'border-color' ) ?? '',
			'card_padding'        => $this->find_variable( 'space-l' ) ?? '',
			'grid_gap'            => $this->find_variable( 'grid-gap' ) ?? '',
			'content_gap'         => $this->find_variable( 'content-gap' ) ?? '',
			'container_gap'       => $this->find_variable( 'container-gap' ) ?? '',
			'btn_primary_class'   => $this->find_class_pattern( [ 'btn-hero-primary', 'btn-primary', 'btn-cta' ] ) ?? '',
			'btn_secondary_class' => $this->find_class_pattern( [ 'btn-hero-ghost', 'btn-ghost', 'btn-outline', 'btn-secondary' ] ) ?? '',
			'eyebrow_class'       => $this->find_class_pattern( [ 'eyebrow', 'tagline', 'overline' ] ) ?? '',
			default               => '',
		};
	}

	// ──────────────────────────────────────────────────────────────
	// Helpers.
	// ──────────────────────────────────────────────────────────────

	/**
	 * Check if a variable name exists in global variables.
	 *
	 * @param string $name Variable name (without -- prefix).
	 * @return string|null "var(--name)" if found, null otherwise.
	 */
	private function find_variable( string $name ): ?string {
		$vars = $this->get_site_variables();

		foreach ( $vars as $var ) {
			if ( ( $var['name'] ?? '' ) === $name ) {
				return 'var(--' . $name . ')';
			}
		}

		return null;
	}

	/**
	 * Check if any class name matches one of the candidate names.
	 *
	 * Candidates are checked in priority order; the first match wins.
	 *
	 * @param array<int, string> $candidates Ordered list of class names to look for.
	 * @return string|null Matching class name, or null if none found.
	 */
	private function find_class_pattern( array $candidates ): ?string {
		$classes = $this->get_site_classes();

		foreach ( $candidates as $candidate ) {
			foreach ( $classes as $class ) {
				if ( ( $class['name'] ?? '' ) === $candidate ) {
					return $candidate;
				}
			}
		}

		return null;
	}

	/**
	 * Lazy-load global variables from wp_options.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_site_variables(): array {
		if ( null === $this->site_variables ) {
			$raw                  = get_option( BricksCore::OPTION_GLOBAL_VARIABLES, [] );
			$this->site_variables = is_array( $raw ) ? $raw : [];
		}

		return $this->site_variables;
	}

	/**
	 * Lazy-load global classes from wp_options.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_site_classes(): array {
		if ( null === $this->site_classes ) {
			$raw                = get_option( BricksCore::OPTION_GLOBAL_CLASSES, [] );
			$this->site_classes = is_array( $raw ) ? $raw : [];
		}

		return $this->site_classes;
	}

	/**
	 * Clear the singleton cache (useful for testing).
	 */
	public static function clear_cache(): void {
		self::$instance = null;
	}
}
