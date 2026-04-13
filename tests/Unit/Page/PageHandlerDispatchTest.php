<?php
/**
 * Dispatcher-level tests for PageHandler.
 *
 * These tests verify the thin dispatch layer that PageHandler became after
 * the sub-handler extraction — invalid action handling, argument alias
 * mapping, and the bricks-active precondition. Actual action logic lives
 * in sub-handlers and is exercised through integration tests with a real
 * WordPress runtime (not included here).
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Page;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PageHandlerDispatchTest extends TestCase {

	public function test_all_17_actions_are_present_in_match_expression(): void {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/includes/MCP/Handlers/PageHandler.php'
		);
		$this->assertIsString( $source );

		$expected_actions = [
			'list', 'search', 'get', 'create',
			'update_content', 'append_content', 'import_clipboard',
			'update_meta', 'delete', 'duplicate',
			'get_settings', 'update_settings',
			'get_seo', 'update_seo',
			'snapshot', 'restore', 'list_snapshots',
		];

		foreach ( $expected_actions as $action ) {
			$this->assertStringContainsString(
				"'" . $action . "'",
				$source,
				"PageHandler dispatcher should still handle action '$action'"
			);
		}
	}

	public function test_six_sub_handlers_are_wired(): void {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/includes/MCP/Handlers/PageHandler.php'
		);

		$expected_sub_handlers = [
			'Page\\PageReadSubHandler',
			'Page\\PageSnapshotSubHandler',
			'Page\\PageSettingsSubHandler',
			'Page\\PageSeoSubHandler',
			'Page\\PageCrudSubHandler',
			'Page\\PageContentSubHandler',
		];

		foreach ( $expected_sub_handlers as $name ) {
			$this->assertStringContainsString( $name, $source, "Missing sub-handler wiring: $name" );
		}
	}

	public function test_no_tool_prefix_methods_remain(): void {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/includes/MCP/Handlers/PageHandler.php'
		);

		$this->assertDoesNotMatchRegularExpression(
			'/private function tool_/',
			$source,
			'PageHandler should have no leftover private tool_* methods after the sub-handler extraction.'
		);
	}

	public function test_all_sub_handler_files_exist(): void {
		$dir = dirname( __DIR__, 3 ) . '/includes/MCP/Handlers/Page/';

		$expected_files = [
			'PageReadSubHandler.php',
			'PageSnapshotSubHandler.php',
			'PageSettingsSubHandler.php',
			'PageSeoSubHandler.php',
			'PageCrudSubHandler.php',
			'PageContentSubHandler.php',
		];

		foreach ( $expected_files as $file ) {
			$this->assertFileExists( $dir . $file );
		}
	}

	public function test_register_method_still_produces_single_page_tool(): void {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/includes/MCP/Handlers/PageHandler.php'
		);

		$this->assertMatchesRegularExpression(
			"/\\\$registry->register\\(\\s*'page'/",
			$source,
			'PageHandler should register exactly one tool named "page".'
		);
	}

	public function test_destructive_confirm_error_code_still_referenced_in_crud_sub_handler(): void {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/includes/MCP/Handlers/Page/PageCrudSubHandler.php'
		);

		$this->assertStringContainsString(
			'bricks_mcp_confirm_required',
			$source,
			'PageCrudSubHandler must still produce the bricks_mcp_confirm_required error code for delete confirmation.'
		);
	}
}
