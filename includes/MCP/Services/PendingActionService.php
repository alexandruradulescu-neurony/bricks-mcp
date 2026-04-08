<?php
/**
 * Pending destructive action service.
 *
 * Manages confirmation tokens for destructive operations.
 * Tokens are stored as WordPress transients with a short TTL
 * and are consumed (deleted) on first use.
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
 * PendingActionService class.
 *
 * Provides token-based confirmation for destructive MCP tool operations.
 * When a destructive action is requested, a unique token is generated and
 * stored with the action details. The AI must call confirm_destructive_action
 * with this token (a separate tool call that triggers a new user permission
 * prompt) to actually execute the operation.
 */
final class PendingActionService {

	/**
	 * Transient key prefix for pending actions.
	 *
	 * @var string
	 */
	private const TRANSIENT_PREFIX = 'bricks_mcp_pending_';

	/**
	 * Time-to-live for pending action tokens in seconds.
	 *
	 * @var int
	 */
	private const TTL = 120;

	/**
	 * Create a pending action and return its confirmation token.
	 *
	 * @param string               $tool_name   The tool that requires confirmation.
	 * @param array<string, mixed> $args        The original tool arguments.
	 * @param string               $description Human-readable description of the action.
	 * @return string 16-character hex confirmation token.
	 */
	public function create( string $tool_name, array $args, string $description ): string {
		$token = bin2hex( random_bytes( 8 ) );

		$data = array(
			'tool_name'   => $tool_name,
			'args'        => $args,
			'description' => $description,
			'created_at'  => time(),
		);

		set_transient( self::TRANSIENT_PREFIX . $token, $data, self::TTL );

		return $token;
	}

	/**
	 * Validate and consume a confirmation token.
	 *
	 * Retrieves the pending action data and immediately deletes the transient
	 * so the token cannot be reused.
	 *
	 * @param string $token The confirmation token to validate.
	 * @return array{tool_name: string, args: array<string, mixed>, description: string, created_at: int}|false
	 *         The pending action data, or false if the token is invalid/expired/consumed.
	 */
	public function validate_and_consume( string $token ): array|false {
		// Validate token format: exactly 16 hex characters.
		if ( ! preg_match( '/^[0-9a-f]{16}$/', $token ) ) {
			return false;
		}

		$transient_key = self::TRANSIENT_PREFIX . $token;
		$data          = get_transient( $transient_key );

		if ( false === $data || ! is_array( $data ) ) {
			return false;
		}

		// One-time use: delete immediately regardless of outcome.
		delete_transient( $transient_key );

		return $data;
	}
}
