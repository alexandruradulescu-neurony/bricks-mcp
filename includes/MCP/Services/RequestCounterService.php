<?php
/**
 * Request counter service.
 *
 * Tracks daily MCP request counts using WordPress transients.
 * Each day gets its own transient key with a 48-hour TTL for
 * automatic cleanup.
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
 * RequestCounterService class.
 */
final class RequestCounterService {

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	private const TRANSIENT_PREFIX = 'bricks_mcp_requests_';

	/**
	 * TTL in seconds (48 hours — ensures yesterday's count survives until end of day).
	 *
	 * @var int
	 */
	private const TTL = 48 * HOUR_IN_SECONDS;

	/**
	 * Increment today's request counter.
	 *
	 * @return void
	 */
	public function increment(): void {
		$key   = self::TRANSIENT_PREFIX . gmdate( 'Y-m-d' );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::TTL );
	}

	/**
	 * Get today's request count.
	 *
	 * @return int Number of requests today.
	 */
	public function get_today_count(): int {
		$key = self::TRANSIENT_PREFIX . gmdate( 'Y-m-d' );
		return (int) get_transient( $key );
	}
}
