<?php
declare(strict_types=1);

namespace BricksMCP\Tests\Unit;

use BricksMCP\MCP\Services\FormTypeDetector;
use PHPUnit\Framework\TestCase;

final class FormTypeDetectorTest extends TestCase {

	public function test_detects_newsletter_variants(): void {
		$cases = [ 'newsletter signup', 'Subscribe to our news', 'signup form', 'opt-in', 'register now', 'inregistrare' ];
		foreach ( $cases as $text ) {
			$this->assertSame( 'newsletter', FormTypeDetector::detect( $text ), "Failed on: $text" );
		}
	}

	public function test_detects_login_variants(): void {
		$cases = [ 'login form', 'sign-in', 'user auth', 'conectare', 'autentificare' ];
		foreach ( $cases as $text ) {
			$this->assertSame( 'login', FormTypeDetector::detect( $text ), "Failed on: $text" );
		}
	}

	public function test_defaults_to_contact(): void {
		$this->assertSame( 'contact', FormTypeDetector::detect( '' ) );
		$this->assertSame( 'contact', FormTypeDetector::detect( 'Get in touch' ) );
		$this->assertSame( 'contact', FormTypeDetector::detect( 'Request a quote' ) );
	}

	public function test_case_insensitive(): void {
		$this->assertSame( 'newsletter', FormTypeDetector::detect( 'NEWSLETTER' ) );
		$this->assertSame( 'login', FormTypeDetector::detect( 'LOGIN' ) );
	}

	public function test_newsletter_wins_over_contact_when_both_present(): void {
		// Newsletter pattern matched first — checks precedence, not contamination.
		$this->assertSame( 'newsletter', FormTypeDetector::detect( 'newsletter signup via contact' ) );
	}
}
