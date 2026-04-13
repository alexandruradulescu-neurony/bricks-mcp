<?php
declare(strict_types=1);

namespace BricksMCP\Tests\Unit;

use BricksMCP\MCP\Services\PrerequisiteGateService;
use PHPUnit\Framework\TestCase;

final class PrerequisiteGateServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		PrerequisiteGateService::reset();
	}

	public function test_tier_inclusion_chain(): void {
		$direct     = PrerequisiteGateService::get_required_flags( 'direct' );
		$instructed = PrerequisiteGateService::get_required_flags( 'instructed' );
		$full       = PrerequisiteGateService::get_required_flags( 'full' );
		$design     = PrerequisiteGateService::get_required_flags( 'design' );

		// Each tier is a strict superset of the previous one.
		$this->assertEmpty( array_diff( $direct, $instructed ) );
		$this->assertEmpty( array_diff( $instructed, $full ) );
		$this->assertEmpty( array_diff( $full, $design ) );

		// Expected sizes.
		$this->assertCount( 1, $direct );
		$this->assertCount( 2, $instructed );
		$this->assertCount( 3, $full );
		$this->assertCount( 5, $design );
	}

	public function test_unknown_tier_returns_empty(): void {
		$this->assertSame( [], PrerequisiteGateService::get_required_flags( 'nonexistent' ) );
	}

	public function test_check_reports_missing_flags(): void {
		$result = PrerequisiteGateService::check( 'instructed' );
		$this->assertIsArray( $result );
		$this->assertContains( 'site_info', $result['missing'] );
		$this->assertContains( 'classes', $result['missing'] );
	}

	public function test_check_passes_when_all_flags_set(): void {
		PrerequisiteGateService::set_flag( 'site_info' );
		PrerequisiteGateService::set_flag( 'classes' );

		$this->assertTrue( PrerequisiteGateService::check( 'instructed' ) );
	}

	public function test_invalid_flag_is_ignored(): void {
		PrerequisiteGateService::set_flag( 'totally_made_up' );
		$result = PrerequisiteGateService::check( 'direct' );
		$this->assertIsArray( $result );
		$this->assertContains( 'site_info', $result['missing'] );
	}

	public function test_reset_clears_all_flags(): void {
		PrerequisiteGateService::set_flag( 'site_info' );
		PrerequisiteGateService::set_flag( 'classes' );
		PrerequisiteGateService::reset();

		$result = PrerequisiteGateService::check( 'instructed' );
		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['missing'] );
	}
}
