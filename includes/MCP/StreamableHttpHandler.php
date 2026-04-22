<?php
/**
 * Streamable HTTP transport handler for MCP.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP;

use BricksMCP\MCP\Services\BricksCore;
use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\OnboardingService;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * StreamableHttpHandler class.
 *
 * Implements the MCP Streamable HTTP transport (protocol version 2025-03-26).
 * Handles JSON-RPC 2.0 messages over Server-Sent Events.
 */
final class StreamableHttpHandler {

	/**
	 * MCP protocol version.
	 *
	 * @var string
	 */
	public const PROTOCOL_VERSION = '2025-03-26';

	/**
	 * JSON-RPC parse error code.
	 *
	 * @var int
	 */
	public const PARSE_ERROR = -32700;

	/**
	 * JSON-RPC invalid request error code.
	 *
	 * @var int
	 */
	public const INVALID_REQUEST = -32600;

	/**
	 * JSON-RPC method not found error code.
	 *
	 * @var int
	 */
	public const METHOD_NOT_FOUND = -32601;

	/**
	 * JSON-RPC invalid params error code.
	 *
	 * @var int
	 */
	public const INVALID_PARAMS = -32602;

	/**
	 * JSON-RPC internal error code.
	 *
	 * @var int
	 */
	public const INTERNAL_ERROR = -32603;

	/**
	 * Maximum number of messages allowed in a JSON-RPC batch request.
	 *
	 * @var int
	 */
	public const MAX_BATCH_SIZE = 20;

	/**
	 * Maximum allowed request body size in bytes (1 MB).
	 *
	 * @var int
	 */
	public const MAX_BODY_SIZE = 1048576;

	/**
	 * Absolute maximum allowed request body size in bytes (10 MB).
	 *
	 * @var int
	 */
	private const MAX_BODY_HARD_LIMIT = 10 * 1024 * 1024;

	/**
	 * Absolute minimum allowed body-size cap.
	 *
	 * A misconfigured bricks_mcp_max_body_size filter that returns a very small
	 * or zero value would otherwise DoS the endpoint by rejecting every realistic
	 * JSON-RPC payload. Floor at 1 KB.
	 *
	 * @var int
	 */
	private const MAX_BODY_FLOOR = 1024;

	/**
	 * SSE keepalive bounds in seconds.
	 *
	 * KEEPALIVE_MIN avoids tight-looping on a misconfigured filter. KEEPALIVE_MAX
	 * stays below the typical PHP-FPM default idle timeout of 60 seconds so the
	 * server emits a keepalive comment before FPM would otherwise kill the worker.
	 *
	 * @var int
	 */
	private const KEEPALIVE_MIN_SECONDS = 5;

	/**
	 * @var int
	 */
	private const KEEPALIVE_MAX_SECONDS = 55;

	/**
	 * Router instance.
	 *
	 * @var Router
	 */
	private Router $router;

	/**
	 * Constructor.
	 *
	 * @param Router $router The MCP router instance.
	 */
	public function __construct( Router $router ) {
		$this->router = $router;
	}

	/**
	 * Handle POST requests (JSON-RPC dispatch).
	 *
	 * Validates Content-Type, decodes JSON, detects batch vs single message,
	 * handles notifications (202 no body), and emits SSE responses.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return void Outputs SSE stream and exits.
	 */
	public function handle_post( \WP_REST_Request $request ): void {
		// Rate limiting is handled upstream by Server::check_permissions() (permission_callback).

		// Validate Content-Type header contains application/json.
		$content_type = $request->get_header( 'Content-Type' );
		if ( empty( $content_type ) || false === strpos( $content_type, 'application/json' ) ) {
			status_header( 415 );
			header( 'Content-Type: application/json' );
			header( 'Connection: close' );
			echo wp_json_encode(
				$this->jsonrpc_error( null, self::INVALID_REQUEST, 'Unsupported Media Type' )
			);
			exit;
		}

		// Check body size before parsing.
		// Clamp between MAX_BODY_FLOOR and MAX_BODY_HARD_LIMIT so that:
		//   - filter values above 10 MB cannot override the hard ceiling
		//   - filter values below ~1 KB cannot DoS the endpoint with 413s
		$body     = $request->get_body();
		$max_body = max(
			self::MAX_BODY_FLOOR,
			min( (int) apply_filters( 'bricks_mcp_max_body_size', self::MAX_BODY_SIZE ), self::MAX_BODY_HARD_LIMIT )
		);
		if ( strlen( $body ) > $max_body ) {
			status_header( 413 );
			header( 'Content-Type: application/json' );
			header( 'Connection: close' );
			echo wp_json_encode(
				$this->jsonrpc_error( null, self::INVALID_REQUEST, 'Request body too large' )
			);
			exit;
		}

		// Decode JSON body.
		$decoded = json_decode( $body, true );

		// Handle JSON parse errors.
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->emit_sse_headers();
			$this->emit_sse_event( $this->jsonrpc_error( null, self::PARSE_ERROR, 'Parse error' ) );
			exit;
		}

		// Detect batch vs single message.
		if ( is_array( $decoded ) && array_is_list( $decoded ) ) {
			// Reject oversized batches.
			if ( count( $decoded ) > self::MAX_BATCH_SIZE ) {
				$this->emit_sse_headers();
				$this->emit_sse_event(
					$this->jsonrpc_error( null, self::INVALID_REQUEST, sprintf( 'Batch too large (max %d messages)', self::MAX_BATCH_SIZE ) )
				);
				exit;
			}

			// Batch request — initialize must not be batched.
			foreach ( $decoded as $message ) {
				if ( is_array( $message ) && isset( $message['method'] ) && 'initialize' === $message['method'] ) {
					$this->emit_sse_headers();
					$this->emit_sse_event(
						$this->jsonrpc_error( null, self::INVALID_REQUEST, 'initialize must not be batched' )
					);
					exit;
				}
			}

			$results = $this->dispatch_batch( $decoded );
			$this->emit_sse_headers();
			$this->emit_sse_event( $results );
			exit;
		}

		// Single message.
		if ( ! is_array( $decoded ) ) {
			$this->emit_sse_headers();
			$this->emit_sse_event( $this->jsonrpc_error( null, self::INVALID_REQUEST, 'Invalid JSON-RPC request' ) );
			exit;
		}

		// Notification (no id field) — return 202 with no body.
		if ( ! array_key_exists( 'id', $decoded ) ) {
			status_header( 202 );
			exit;
		}

		// Single request — dispatch and emit SSE response.
		$result = $this->dispatch_single( $decoded );
		$this->emit_sse_headers();
		if ( null !== $result ) {
			$this->emit_sse_event( $result );
		}
		exit;
	}

	/**
	 * Handle GET requests (persistent SSE keepalive loop).
	 *
	 * Emits SSE keepalive comments every 25 seconds to keep the connection open
	 * through PHP-FPM idle timeouts. Checks for client disconnects via
	 * connection_aborted() before and after each sleep interval, exiting cleanly
	 * within one keepalive interval when the client disconnects.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return void Outputs SSE keepalive stream and exits.
	 */
	public function handle_get( \WP_REST_Request $request ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$this->emit_sse_headers();

		// Iteration cap protects against wedged PHP-FPM workers when clients hold
		// SSE connections open indefinitely. Default: (timeout / min-keepalive) = 360
		// iterations worst case. Filter-adjustable.
		$max_iterations = (int) apply_filters( 'bricks_mcp_sse_max_iterations', 360 );
		$iter           = 0;

		while ( $iter < $max_iterations ) {
			if ( connection_aborted() ) {
				break;
			}
			echo ": keepalive\n\n";
			if ( ob_get_level() > 0 ) {
				ob_flush();
			}
			flush();
			$keepalive = (int) apply_filters( 'bricks_mcp_keepalive_interval', 25 );
			sleep( max( self::KEEPALIVE_MIN_SECONDS, min( $keepalive, self::KEEPALIVE_MAX_SECONDS ) ) );
			if ( connection_aborted() ) {
				break;
			}
			$iter++;
		}
		exit;
	}

	/**
	 * Emit a JSON-RPC parse error as SSE and exit.
	 *
	 * Called by Server when WordPress detects an invalid JSON body before our callback runs.
	 * This allows us to return a spec-compliant JSON-RPC -32700 parse error over SSE
	 * instead of WordPress's own rest_invalid_json error.
	 *
	 * @return void Outputs SSE parse error and exits.
	 */
	public function emit_parse_error_and_exit(): void {
		$this->emit_sse_headers();
		$this->emit_sse_event( $this->jsonrpc_error( null, self::PARSE_ERROR, 'Parse error' ) );
		exit;
	}

	/**
	 * Handle DELETE requests (session termination no-op).
	 *
	 * Returns HTTP 200 with no body per MCP protocol decision.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return void Sets 200 status and exits.
	 */
	public function handle_delete( \WP_REST_Request $request ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		status_header( 200 );
		exit;
	}

	/**
	 * Dispatch a single JSON-RPC message.
	 *
	 * Routes the message to the appropriate handler based on method name.
	 * Returns null for notifications (no id).
	 *
	 * @param array<string, mixed> $message The decoded JSON-RPC message.
	 * @return array<string, mixed>|null Response array, or null for notifications.
	 */
	private function dispatch_single( array $message ): ?array {
		$id     = $message['id'] ?? null;
		$method = $message['method'] ?? '';
		$params = $message['params'] ?? [];

		// Notification — no response needed.
		if ( ! array_key_exists( 'id', $message ) ) {
			return null;
		}

		// Validate JSON-RPC version.
		if ( ! isset( $message['jsonrpc'] ) || '2.0' !== $message['jsonrpc'] ) {
			return $this->jsonrpc_error( $id, self::INVALID_REQUEST, 'Invalid JSON-RPC version' );
		}

		// Route to method handler.
		return match ( $method ) {
			'initialize'              => $this->handle_initialize( $id, is_array( $params ) ? $params : [] ),
			'notifications/initialized' => null,
			'tools/list'              => $this->handle_tools_list( $id, is_array( $params ) ? $params : [] ),
			'tools/call'              => $this->handle_tools_call( $id, is_array( $params ) ? $params : [] ),
			'ping'                    => $this->jsonrpc_success( $id, [] ),
			default                   => $this->jsonrpc_error( $id, self::METHOD_NOT_FOUND, 'Method not found' ),
		};
	}

	/**
	 * Dispatch a batch of JSON-RPC messages.
	 *
	 * Processes each message via dispatch_single and filters out null results
	 * (notifications that require no response).
	 *
	 * @param array<int, mixed> $messages The array of decoded JSON-RPC messages.
	 * @return array<int, array<string, mixed>> Array of response objects.
	 */
	private function dispatch_batch( array $messages ): array {
		$results = [];
		foreach ( $messages as $msg ) {
			if ( ! is_array( $msg ) ) {
				$results[] = $this->jsonrpc_error( null, self::INVALID_REQUEST, 'Each item in a JSON-RPC batch must be an object' );
				continue;
			}
			$result = $this->dispatch_single( $msg );
			if ( null !== $result ) {
				$results[] = $result;
			}
		}
		return $results;
	}

	/**
	 * Handle the initialize JSON-RPC method.
	 *
	 * Returns protocol version, server capabilities, and server info.
	 *
	 * @param int|string           $id     The JSON-RPC request id.
	 * @param array<string, mixed> $params The request params (unused but required by spec).
	 * @return array<string, mixed> JSON-RPC success response.
	 */
	private function handle_initialize( int|string $id, array $params ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$capabilities = [
			'tools' => [
				'listChanged' => true,
			],
		];

		$server_info = [
			'name'    => 'bricks-mcp',
			'version' => BRICKS_MCP_VERSION,
		];

		$instructions = $this->generate_dynamic_instructions();

		// Add onboarding data to serverInfo.
		$onboarding_service = new OnboardingService( new BricksService() );
		// String-cast with fallback — clients sending non-string sessionId would
		// otherwise pass through to OnboardingService::is_first_session() and cause
		// silently-unexpected return values.
		$raw_session_id = $params['sessionId'] ?? '';
		$session_id     = is_string( $raw_session_id ) ? $raw_session_id : '';
		$server_info['onboarding'] = $onboarding_service->generate_onboarding( get_current_user_id() );
		$server_info['requires_onboarding_review'] = $onboarding_service->is_first_session( $session_id );

		return $this->jsonrpc_success(
			$id,
			[
				'protocolVersion' => self::PROTOCOL_VERSION,
				'capabilities'    => $capabilities,
				'serverInfo'      => $server_info,
				'instructions'    => $instructions,
			]
		);
	}

	/**
	 * Handle the tools/list JSON-RPC method.
	 *
	 * Returns all available MCP tools from the router.
	 *
	 * @param int|string           $id     The JSON-RPC request id.
	 * @param array<string, mixed> $params The request params (cursor for pagination, currently unused).
	 * @return array<string, mixed> JSON-RPC success response with tools array.
	 */
	private function handle_tools_list( int|string $id, array $params ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$tools = $this->router->get_available_tools();

		return $this->jsonrpc_success( $id, [ 'tools' => $tools ] );
	}

	/**
	 * Handle the tools/call JSON-RPC method.
	 *
	 * Extracts tool name and arguments, executes via router, and returns result.
	 *
	 * @param int|string           $id     The JSON-RPC request id.
	 * @param array<string, mixed> $params The request params (name, arguments).
	 * @return array<string, mixed> JSON-RPC success or error response.
	 */
	private function handle_tools_call( int|string $id, array $params ): array {
		$name      = $params['name'] ?? '';
		$arguments = $params['arguments'] ?? [];

		// Router::execute_tool is typed `string $name`. A non-string from the JSON body
		// (array, object, bool) would throw TypeError and crash the whole request.
		// Surface a JSON-RPC error instead.
		if ( ! is_string( $name ) || '' === $name ) {
			return $this->jsonrpc_error( $id, self::INVALID_PARAMS, 'Missing or invalid required parameter: name (must be a non-empty string)' );
		}

		$result = $this->router->execute_tool( $name, is_array( $arguments ) ? $arguments : [] );

		$data   = $result->get_data();
		$status = $result->get_status();

		// Check for error conditions.
		// Three signals of error:
		//   1. HTTP status >= 400 — Response::error() (validation, capability, etc.)
		//   2. $data['error'] — legacy flag set by Response::validation_error()
		//   3. $data['isError'] — MCP tool-result envelope from Response::tool_error()
		// Previously only #1 and #2 were checked, so tool_error() (which sets isError)
		// silently surfaced as JSON-RPC success with `isError: true` nested inside —
		// AI clients that only inspect the JSON-RPC envelope missed it entirely.
		$is_data_array = is_array( $data );
		$has_error     = $status >= 400
			|| ( $is_data_array && ! empty( $data['error'] ) )
			|| ( $is_data_array && ! empty( $data['isError'] ) );

		if ( $has_error ) {
			$error_text = '';
			if ( $is_data_array && isset( $data['content'][0]['text'] ) ) {
				$error_text = wp_strip_all_tags( (string) $data['content'][0]['text'] );
			}

			return $this->jsonrpc_error(
				$id,
				self::INTERNAL_ERROR,
				$error_text ? $error_text : 'Tool execution failed'
			);
		}

		return $this->jsonrpc_success( $id, $data );
	}

	/**
	 * Emit SSE response headers.
	 *
	 * Flushes output buffers, extends PHP execution time via the filterable
	 * bricks_mcp_sse_timeout filter (default 1800 seconds), enables
	 * connection_aborted() polling via ignore_user_abort( true ), sets SSE
	 * headers, and registers a shutdown function to emit a stream-end comment
	 * so proxies know the stream closed unexpectedly.
	 *
	 * @return void
	 */
	private function emit_sse_headers(): void {
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		$timeout = (int) apply_filters( 'bricks_mcp_sse_timeout', 1800 );
		set_time_limit( $timeout );
		ignore_user_abort( true );

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		// Idempotent registration: handle_post paths that both parse-error-and-exit
		// and dispatch can invoke emit_sse_headers() twice in the same request,
		// stacking two ": stream-end" comments. Spec-compliant SSE clients ignore
		// duplicate comments but it's still noise.
		static $shutdown_registered = false;
		if ( $shutdown_registered ) {
			return;
		}
		$shutdown_registered = true;

		register_shutdown_function(
			function () {
				echo ": stream-end\n\n";
				if ( ob_get_level() > 0 ) {
					ob_flush();
				}
				flush();
			}
		);
	}

	/**
	 * Emit a single SSE event with JSON payload.
	 *
	 * @param array<string, mixed>|array<int, mixed> $payload The data to encode as JSON in the event.
	 * @return void
	 */
	private function emit_sse_event( array $payload ): void {
		// wp_json_encode() returns false on invalid UTF-8, recursion, or non-encodable
		// values. Emitting 'data: \n\n' produces a malformed SSE event that many
		// parsers reject — fall back to an inline error payload instead.
		$json = wp_json_encode( $payload );
		if ( false === $json ) {
			$json = wp_json_encode(
				[
					'jsonrpc' => '2.0',
					'error'   => [
						'code'    => self::INTERNAL_ERROR,
						'message' => 'Response encoding failed: ' . json_last_error_msg(),
					],
				]
			);
			if ( false === $json ) {
				// Truly pathological case — emit a minimal literal.
				$json = '{"jsonrpc":"2.0","error":{"code":-32603,"message":"encoding failed"}}';
			}
		}

		echo "event: message\n";
		echo 'data: ' . $json . "\n\n";

		if ( ob_get_level() > 0 ) {
			ob_flush();
		}

		flush();
	}

	/**
	 * Build a JSON-RPC 2.0 success response.
	 *
	 * @param int|string $id     The JSON-RPC request id.
	 * @param mixed      $result The result data.
	 * @return array<string, mixed> The JSON-RPC response array.
	 */
	private function jsonrpc_success( int|string $id, mixed $result ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		];
	}

	/**
	 * Build a JSON-RPC 2.0 error response.
	 *
	 * @param int|string|null $id      The JSON-RPC request id (null for parse errors).
	 * @param int             $code    The JSON-RPC error code.
	 * @param string          $message The error message.
	 * @return array<string, mixed> The JSON-RPC error response array.
	 */
	private function jsonrpc_error( int|string|null $id, int $code, string $message ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		];
	}

	/**
	 * Generate dynamic MCP instructions with site-specific context.
	 *
	 * @return string Instructions text for the AI client.
	 */
	private function generate_dynamic_instructions(): string {
		$site_name = get_bloginfo( 'name' );

		// Count pages and global classes.
		$page_count  = 0;
		$class_count = 0;

		$pages_query = new \WP_Query( [
			'post_type'      => array_values( get_post_types( [ 'public' => true ] ) ),
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[ 'key' => BricksService::META_KEY, 'compare' => 'EXISTS' ],
			],
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		$page_count = count( $pages_query->posts );

		$global_classes = get_option( BricksCore::OPTION_GLOBAL_CLASSES, [] );
		$class_count    = is_array( $global_classes ) ? count( $global_classes ) : 0;

		// Detect design system from class naming.
		$design_system = '';
		if ( is_array( $global_classes ) ) {
			foreach ( $global_classes as $gc ) {
				$name = $gc['name'] ?? '';
				if ( str_starts_with( $name, 'brxw-' ) ) {
					$design_system = 'Auron wireframe';
					break;
				}
			}
		}

		// Detect recurring patterns.
		$patterns = [];
		// Guard: $pages_query->posts can be array of IDs, WP_Post objects, or mixed
		// after plugin filters. Normalize defensively.
		$post_refs = is_array( $pages_query->posts ?? null ) ? $pages_query->posts : [];
		foreach ( $post_refs as $post_ref ) {
			if ( is_object( $post_ref ) && isset( $post_ref->ID ) ) {
				$pid = (int) $post_ref->ID;
			} elseif ( is_numeric( $post_ref ) ) {
				$pid = (int) $post_ref;
			} else {
				continue;
			}
			$raw = get_post_meta( $pid, BricksService::META_KEY, true );
			if ( ! is_array( $raw ) ) {
				continue;
			}
			foreach ( $raw as $el ) {
				if ( ! is_array( $el ) ) {
					continue;
				}
				if ( 'section' !== ( $el['name'] ?? '' ) || ! BricksCore::is_root_element( $el ) ) {
					continue;
				}
				$settings = is_array( $el['settings'] ?? null ) ? $el['settings'] : [];
				$label    = $settings['label'] ?? $el['label'] ?? '';
				if ( $label && ! in_array( $label, $patterns, true ) ) {
					$patterns[] = $label;
				}
			}
		}

		// Build site context line.
		$context_parts = [];
		$context_parts[] = sprintf( 'Site "%s"', $site_name );
		$context_parts[] = sprintf( '%d Bricks page%s', $page_count, 1 === $page_count ? '' : 's' );
		$context_parts[] = sprintf( '%d global class%s', $class_count, 1 === $class_count ? '' : 'es' );
		if ( $design_system ) {
			$context_parts[] = $design_system . ' design system';
		}
		$site_context = implode( ', ', $context_parts ) . '.';

		if ( ! empty( $patterns ) ) {
			$site_context .= ' Section patterns found: ' . implode( ', ', array_slice( $patterns, 0, 8 ) ) . '.';
		}

		// Load AI notes to embed directly in instructions.
		$notes      = get_option( BricksCore::OPTION_NOTES, [] );
		$notes_text = '';
		if ( is_array( $notes ) && ! empty( $notes ) ) {
			$notes_text = "\n\n🚨 AI NOTES (persistent corrections from the site owner — MUST follow):\n";
			foreach ( $notes as $note ) {
				$text = $note['text'] ?? '';
				if ( '' !== $text ) {
					$notes_text .= "- {$text}\n";
				}
			}
		}

		$instructions = "Bricks MCP connects AI assistants to a WordPress site running Bricks Builder.\n\n"
			. "SITE CONTEXT: {$site_context}\n\n"
			. "INTENT ROUTER — classify every user request before acting:\n\n"
			. "1. DIRECT OPERATION → use element/page/menu tools directly\n"
			. "   Signals: \"change text to\", \"move X above Y\", \"delete the\", \"update the menu\", \"swap image\"\n"
			. "   Prerequisites: get_site_info\n\n"
			. "2. INSTRUCTED BUILD → use element:bulk_add or page:append_content\n"
			. "   Signals: \"add a section with\", \"insert a heading and two columns\", \"put a form here\"\n"
			. "   Prerequisites: get_site_info + global_class:list\n"
			. "   Call bricks:get_element_schemas(elements='heading,slider-nested,image') to batch-fetch only the element schemas you need (max 20). More efficient than fetching all.\n\n"
			. "3. DESIGN BUILD → 4-step flow (ALL steps required, server-enforced):\n\n"
			. "   Step 1 — DISCOVER: Call propose_design(page_id, description) WITHOUT design_plan.\n"
			. "   You receive: site context, available element types with PURPOSE descriptions, layouts, classes, variables, briefs.\n"
			. "   No proposal_id is returned — you CANNOT skip to building.\n\n"
			. "   Step 2 — DESIGN: Think as a designer. Using the discovery data, decide:\n"
			. "   - section_type (hero, features, pricing, cta, testimonials, split, generic)\n"
			. "   - layout (centered, split-60-40, split-50-50, grid-2, grid-3, grid-4)\n"
			. "   - background (dark or light)\n"
			. "   - elements: list each element with type, role, and content_hint\n"
			. "   - patterns: for repeating elements (cards, testimonials, etc.)\n"
			. "   Then call propose_design again WITH your design_plan object.\n"
			. "   You receive: proposal_id + suggested_schema built from YOUR decisions.\n\n"
			. "   Step 3 — STRUCTURE: Call build_structure(proposal_id) to create the element tree from the suggested_schema. Do NOT modify structure or style_overrides.\n"
			. "   Step 4 — POPULATE: Call populate_content(section_id, content_map) with content keyed by role to fill the tree with real text/media.\n"
			. "   Step 5 — VERIFY: Call verify_build(page_id) to confirm the result matches your design intent.\n"
			. "   Compare type_counts and classes_used against your design_plan.\n\n"
			. "   Signals: \"design a\", \"create a page for\", \"build me a\", \"make a services section\", \"redesign the\"\n"
			. "   Prerequisites: get_site_info + global_class:list + global_variable:list\n\n"
			. "ENFORCEMENT:\n"
			. "- Prerequisites are server-enforced per workflow — write operations REJECTED if skipped.\n"
			. "- Design build gate: append_content, bulk_add, and create with SECTION elements will be REJECTED — use build_structure + populate_content instead. Non-section elements are allowed for instructed builds.\n"
			. "- build_structure requires a valid proposal_id from the PROPOSAL phase (not discovery).\n"
			. "- Destructive actions (delete, replace) require token-based confirmation.\n\n"
			. "KNOWLEDGE: For domain-specific guidance, call bricks:get_knowledge(domain). "
			. "Available domains: " . implode( ', ', \BricksMCP\MCP\Handlers\BricksToolHandler::discover_knowledge_domains() ) . ". "
			. "Call without domain to list all. Key domains: query-loops (pagination, nested loops), templates (conditions, scoring), global-classes (IDs vs names, style shape), forms (18 field types, 7 actions), animations (interactions, parallax).\n\n"
			. "PERSISTENT MEMORY: Save design decisions, corrections, and site-specific learnings via bricks:add_note(text=\"...\"). "
			. "Notes persist across sessions and appear in discovery responses + server instructions. "
			. "Read existing notes via bricks:get_notes(). Examples of what to save:\n"
			. "- \"This site uses perPage:4 for all sliders with var(--grid-gap) spacing\"\n"
			. "- \"Client prefers dark hero sections with centered layout\"\n"
			. "- \"Always use ti-truck icon for delivery/transport features\"\n"
			. "- \"Homepage sections alternate: dark → light → tinted-neutral → light\""
			. $notes_text;

		return $instructions;
	}
}
