<?php
/**
 * Persistent correction notes service.
 *
 * Stores AI-generated notes that are appended to the gotchas section
 * of the builder guide, providing persistent learning across sessions.
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
 * NotesService class.
 *
 * Manages persistent correction notes stored as a WordPress option.
 */
class NotesService {

	/**
	 * WordPress option name for storing notes.
	 *
	 * @var string
	 */
	private const OPTION_NAME = 'bricks_mcp_notes';

	/**
	 * Get all stored notes.
	 *
	 * @return array<int, array{id: string, text: string, created_at: string}> List of notes.
	 */
	public function get_notes(): array {
		$notes = get_option( self::OPTION_NAME, [] );
		return is_array( $notes ) ? $notes : [];
	}

	/**
	 * Add a new note.
	 *
	 * @param string $text Note text content.
	 * @return array{id: string, text: string, created_at: string} Created note.
	 */
	public function add_note( string $text ): array {
		$notes = $this->get_notes();
		$id    = 'note_' . bin2hex( random_bytes( 4 ) );
		$note  = [
			'id'         => $id,
			'text'       => sanitize_text_field( $text ),
			'created_at' => current_time( 'mysql' ),
		];
		$notes[] = $note;
		update_option( self::OPTION_NAME, $notes, false );
		return $note;
	}

	/**
	 * Delete a note by ID.
	 *
	 * @param string $note_id Note ID to delete.
	 * @return bool True if note was found and deleted, false if not found.
	 */
	public function delete_note( string $note_id ): bool {
		$notes    = $this->get_notes();
		$filtered = array_values( array_filter( $notes, fn( $n ) => ( $n['id'] ?? '' ) !== $note_id ) );
		if ( count( $filtered ) === count( $notes ) ) {
			return false; // Not found.
		}
		update_option( self::OPTION_NAME, $filtered, false );
		return true;
	}
}
