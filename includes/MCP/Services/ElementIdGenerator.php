<?php
/**
 * Bricks element ID generator.
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
 * ElementIdGenerator class.
 *
 * Generates cryptographically secure 6-character lowercase alphanumeric IDs
 * for Bricks Builder elements, matching the native Bricks ID format.
 */
class ElementIdGenerator {

	/**
	 * Alphabet used for ID generation (lowercase alphanumeric).
	 *
	 * @var string
	 */
	private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';

	/**
	 * Length of generated IDs.
	 *
	 * @var int
	 */
	private const ID_LENGTH = 6;

	/**
	 * Maximum collision retry attempts before throwing.
	 *
	 * @var int
	 */
	private const MAX_ATTEMPTS = 100;

	/**
	 * Return the canonical regex used to validate an element ID string.
	 *
	 * Derived from ALPHABET and ID_LENGTH so BricksCore::validate_element_linkage
	 * and any other consumer stays in sync if either constant ever changes.
	 *
	 * @return string Delimited regex suitable for preg_match().
	 */
	public static function id_regex(): string {
		return '/^[a-z0-9]{' . self::ID_LENGTH . '}$/';
	}

	/**
	 * Generate a cryptographically secure 6-character lowercase alphanumeric ID.
	 *
	 * Uses random_int() for uniform distribution across the full alphabet.
	 *
	 * @return string A 6-character lowercase alphanumeric ID.
	 * @throws \RuntimeException If random byte generation fails.
	 */
	public function generate(): string {
		$id        = '';
		$alpha_len = strlen( self::ALPHABET );

		for ( $i = 0; $i < self::ID_LENGTH; $i++ ) {
			$id .= self::ALPHABET[ random_int( 0, $alpha_len - 1 ) ];
		}

		return $id;
	}

	/**
	 * Check if an ID already exists in the elements array.
	 *
	 * @param string                           $id       The ID to check.
	 * @param array<int, array<string, mixed>> $elements The existing elements array.
	 * @return bool True if a collision exists, false if the ID is unique.
	 */
	public function is_collision( string $id, array $elements ): bool {
		// Use isset for O(1) lookup when elements are keyed by ID (batch operations).
		if ( isset( $elements[ $id ] ) ) {
			return true;
		}
		// Fallback to linear scan for numerically-indexed arrays.
		foreach ( $elements as $element ) {
			if ( isset( $element['id'] ) && $element['id'] === $id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Generate a unique ID not already present in the elements array.
	 *
	 * Loops generate() with collision checking up to MAX_ATTEMPTS times.
	 *
	 * @param array<int, array<string, mixed>> $existing_elements The existing elements to check against.
	 * @return string A unique 6-character lowercase alphanumeric ID.
	 * @throws \RuntimeException If a unique ID cannot be generated after MAX_ATTEMPTS attempts.
	 */
	public function generate_unique( array $existing_elements ): string {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$id = $this->generate();

			if ( ! $this->is_collision( $id, $existing_elements ) ) {
				return $id;
			}
		}

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not output to the browser.
		throw new \RuntimeException(
			sprintf(
				'Unable to generate a unique element ID after %d attempts. The elements array may be too large.',
				self::MAX_ATTEMPTS
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}
}
