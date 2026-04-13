<?php
/**
 * Design pattern service.
 *
 * Loads curated design patterns from /data/design-patterns/ and matches
 * them to section types and tags for the discovery phase.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DesignPatternService {

	/**
	 * Cached patterns.
	 * @var array<string, array>|null
	 */
	private static ?array $all_patterns = null;

	/**
	 * Load all patterns from data files.
	 *
	 * @return array<string, array> category => patterns array.
	 */
	private static function load_all(): array {
		if ( null !== self::$all_patterns ) {
			return self::$all_patterns;
		}

		self::$all_patterns = [];
		$dir = dirname( __DIR__, 3 ) . '/data/design-patterns/';

		if ( ! is_dir( $dir ) ) {
			return self::$all_patterns;
		}

		$files = glob( $dir . '*.json' );
		if ( ! is_array( $files ) ) {
			return self::$all_patterns;
		}

		foreach ( $files as $file ) {
			// Skip the schema file itself.
			if ( basename( $file ) === '_schema.json' ) {
				continue;
			}

			$json = file_get_contents( $file ); // phpcs:ignore
			if ( ! is_string( $json ) ) {
				continue;
			}
			$data = json_decode( $json, true );
			if ( ! is_array( $data ) || empty( $data['patterns'] ) ) {
				continue;
			}

			// In debug mode, validate against the JSON Schema so contributors catch malformed patterns.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				self::validate_against_schema( $file, $json );
			}

			$category = $data['category'] ?? 'generic';
			foreach ( $data['patterns'] as $pattern ) {
				self::$all_patterns[] = array_merge( $pattern, [ 'category' => $category ] );
			}
		}

		return self::$all_patterns;
	}

	/**
	 * Validate a pattern file against the JSON Schema.
	 *
	 * Only runs in WP_DEBUG. Violations are logged via error_log so contributors
	 * notice malformed patterns while developing. Production is never affected.
	 *
	 * @param string $file     Absolute path to the pattern file (for error messages).
	 * @param string $raw_json Raw JSON contents.
	 */
	private static function validate_against_schema( string $file, string $raw_json ): void {
		// Opis JSON Schema is optional — skip silently if the vendor autoloader didn't load it.
		if ( ! class_exists( '\Opis\JsonSchema\Validator' ) ) {
			return;
		}

		$schema_path = dirname( $file ) . '/_schema.json';
		if ( ! is_readable( $schema_path ) ) {
			return;
		}

		try {
			$validator = new \Opis\JsonSchema\Validator();
			$schema    = file_get_contents( $schema_path ); // phpcs:ignore
			if ( ! is_string( $schema ) ) {
				return;
			}

			$data_obj = json_decode( $raw_json );
			if ( null === $data_obj ) {
				return;
			}

			$result = $validator->validate( $data_obj, $schema );
			if ( ! $result->isValid() ) {
				$error = $result->error();
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging.
				error_log( sprintf(
					'BricksMCP: Design pattern file %s failed schema validation: %s',
					basename( $file ),
					$error ? $error->message() : 'unknown'
				) );
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging.
			error_log( sprintf(
				'BricksMCP: Design pattern schema validation threw on %s: %s',
				basename( $file ),
				$e->getMessage()
			) );
		}
	}

	/**
	 * Find patterns matching a section type and optional tags.
	 *
	 * @param string        $section_type  Section type (hero, features, cta, etc.)
	 * @param array<string> $tags          Optional tags to match (dark, centered, image, etc.)
	 * @param int           $limit         Max patterns to return.
	 * @return array<int, array> Matching patterns sorted by relevance.
	 */
	public static function find( string $section_type, array $tags = [], int $limit = 3 ): array {
		$all = self::load_all();
		$scored = [];

		foreach ( $all as $pattern ) {
			$score = 0;

			// Category match.
			$cat = $pattern['category'] ?? '';
			if ( $cat === $section_type ) {
				$score += 10;
			}

			// Tag matching.
			$pattern_tags = $pattern['tags'] ?? [];
			foreach ( $tags as $tag ) {
				if ( in_array( $tag, $pattern_tags, true ) ) {
					$score += 2;
				}
			}

			// Layout matching (if tags include a layout hint).
			$layout = $pattern['layout'] ?? '';
			if ( in_array( $layout, $tags, true ) ) {
				$score += 5;
			}

			if ( $score > 0 ) {
				$scored[] = [ 'pattern' => $pattern, 'score' => $score ];
			}
		}

		// Sort by score descending.
		usort( $scored, fn( $a, $b ) => $b['score'] <=> $a['score'] );

		$results = [];
		foreach ( array_slice( $scored, 0, $limit ) as $item ) {
			$results[] = $item['pattern'];
		}

		return $results;
	}

	/**
	 * Find a single pattern by ID.
	 *
	 * @param string $pattern_id Pattern ID.
	 * @return array|null Pattern or null.
	 */
	public static function get( string $pattern_id ): ?array {
		$all = self::load_all();
		foreach ( $all as $pattern ) {
			if ( ( $pattern['id'] ?? '' ) === $pattern_id ) {
				return $pattern;
			}
		}
		return null;
	}

	/**
	 * Get a summary list of all patterns (for reference).
	 *
	 * @return array<int, array{id: string, name: string, category: string, tags: array}> Pattern summaries.
	 */
	public static function list_all(): array {
		$all = self::load_all();
		$summaries = [];
		foreach ( $all as $pattern ) {
			$summaries[] = [
				'id'          => $pattern['id'] ?? '',
				'name'        => $pattern['name'] ?? '',
				'category'    => $pattern['category'] ?? '',
				'description' => $pattern['description'] ?? '',
				'tags'        => $pattern['tags'] ?? [],
				'layout'      => $pattern['layout'] ?? '',
			];
		}
		return $summaries;
	}
}
