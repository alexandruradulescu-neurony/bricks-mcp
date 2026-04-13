<?php
/**
 * Minimal bootstrap for pure-PHP unit tests that don't need WordPress.
 *
 * For tests that stub WP functions with Brain/Monkey or simple helpers —
 * not full WP_UnitTestCase integration tests.
 *
 * @package BricksMCP
 */

declare(strict_types=1);

// Plugin directory.
if ( ! defined( 'BRICKS_MCP_PLUGIN_DIR' ) ) {
	define( 'BRICKS_MCP_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Minimal WP function stubs used by the services under test.
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string { return $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string { return $text; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string { return trim( $str ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0 ) { return json_encode( $data, $options ); }
}
if ( ! function_exists( 'get_transient' ) ) {
	$GLOBALS['__transients'] = [];
	function get_transient( string $key ) {
		return $GLOBALS['__transients'][ $key ] ?? false;
	}
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		$GLOBALS['__transients'][ $key ] = $value;
		return true;
	}
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['__transients'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int { return 1; }
}

// PSR-4 autoloader for BricksMCP namespace.
spl_autoload_register( static function ( string $class ): void {
	$prefix   = 'BricksMCP\\';
	$base_dir = dirname( __DIR__ ) . '/includes/';

	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}
} );
