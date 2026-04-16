<?php
/**
 * Design pipeline health check.
 *
 * Verifies the pipeline's data files (elements registry, settings-keys
 * registry) and the database-backed design pattern library are loadable
 * and valid.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Admin\Checks;

use BricksMCP\Admin\DiagnosticCheck;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies that the design build pipeline's static data and services
 * are healthy on this install.
 */
class DesignPipelineCheck implements DiagnosticCheck {

	public function id(): string {
		return 'design_pipeline';
	}

	public function label(): string {
		return __( 'Design Pipeline Health', 'bricks-mcp' );
	}

	public function category(): string {
		return 'pipeline';
	}

	public function dependencies(): array {
		return array();
	}

	public function run(): array {
		$base = defined( 'BRICKS_MCP_PLUGIN_DIR' ) ? BRICKS_MCP_PLUGIN_DIR : plugin_dir_path( __DIR__ . '/../../../' );

		$problems = array();

		// 1. Design patterns are database-backed (since 3.x). Verify at least one is loadable.
		try {
			$patterns = \BricksMCP\MCP\Services\DesignPatternService::list_all();
			if ( ! is_array( $patterns ) ) {
				$problems[] = __( 'DesignPatternService::list_all() returned a non-array value.', 'bricks-mcp' );
			} elseif ( empty( $patterns ) ) {
				$problems[] = __( 'No design patterns found in the database. Bundled patterns should seed on first activation.', 'bricks-mcp' );
			}
		} catch ( \Throwable $e ) {
			$problems[] = sprintf( __( 'DesignPatternService threw: %s', 'bricks-mcp' ), $e->getMessage() );
		}

		// 2. Core JSON data files load and parse.
		$required_files = [
			'data/elements.json',
			'data/settings-keys.json',
		];
		foreach ( $required_files as $rel ) {
			$path = $base . $rel;
			if ( ! is_readable( $path ) ) {
				$problems[] = sprintf( __( 'Required data file missing or unreadable: %s', 'bricks-mcp' ), $rel );
				continue;
			}
			$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$parsed   = is_string( $contents ) ? json_decode( $contents, true ) : null;
			if ( ! is_array( $parsed ) ) {
				$problems[] = sprintf( __( 'Data file failed to parse as JSON: %s', 'bricks-mcp' ), $rel );
			}
		}

		if ( ! empty( $problems ) ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'fail',
				'message'   => implode( ' / ', $problems ),
				'fix_steps' => array(
					__( 'Reinstall or update the Bricks MCP plugin to restore missing data files.', 'bricks-mcp' ),
					__( 'If pattern count is zero, deactivate + reactivate the plugin to trigger the pattern seed.', 'bricks-mcp' ),
					__( 'If the problem persists, check file permissions on the plugin directory.', 'bricks-mcp' ),
				),
				'category'  => $this->category(),
			);
		}

		return array(
			'id'        => $this->id(),
			'label'     => $this->label(),
			'status'    => 'pass',
			'message'   => __( 'Design patterns (database) and pipeline data files are healthy.', 'bricks-mcp' ),
			'fix_steps' => array(),
			'category'  => $this->category(),
		);
	}
}
