<?php
/**
 * Form type detector utility.
 *
 * Consolidates form type detection logic used by ElementSettingsGenerator
 * and SchemaSkeletonGenerator. Single source of truth for the regex patterns.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FormTypeDetector {

	/**
	 * Detect form type from free text (role, label, content_hint, etc.).
	 *
	 * @param string $text Combined text to analyze.
	 * @return string Form type: 'newsletter', 'login', or 'contact'.
	 */
	public static function detect( string $text ): string {
		$text = strtolower( $text );

		if ( preg_match( '/newsletter|subscribe|signup|opt.?in|register|inregistr/', $text ) ) {
			return 'newsletter';
		}

		if ( preg_match( '/login|sign.?in|auth|conecta|autentific/', $text ) ) {
			return 'login';
		}

		return 'contact';
	}
}
