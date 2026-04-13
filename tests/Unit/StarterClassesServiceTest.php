<?php
declare(strict_types=1);

namespace BricksMCP\Tests\Unit;

use BricksMCP\MCP\Services\StarterClassesService;
use PHPUnit\Framework\TestCase;

final class StarterClassesServiceTest extends TestCase {

	public function test_returns_at_least_13_starter_classes(): void {
		$classes = StarterClassesService::get_starter_classes();
		$this->assertIsArray( $classes );
		$this->assertGreaterThanOrEqual( 13, count( $classes ) );
	}

	public function test_every_class_has_required_shape(): void {
		$classes = StarterClassesService::get_starter_classes();
		foreach ( $classes as $class ) {
			$this->assertIsArray( $class );
			$this->assertArrayHasKey( 'name', $class );
			$this->assertNotEmpty( $class['name'] );
			// Settings object is optional but must be array when present.
			if ( isset( $class['settings'] ) ) {
				$this->assertIsArray( $class['settings'] );
			}
		}
	}

	public function test_core_layout_classes_are_included(): void {
		$classes = StarterClassesService::get_starter_classes();
		$names   = array_column( $classes, 'name' );

		foreach ( [ 'grid-2', 'grid-3', 'grid-4' ] as $required ) {
			$this->assertContains( $required, $names, "Missing expected starter class: $required" );
		}
	}
}
