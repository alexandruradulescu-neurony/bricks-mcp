<?php
/**
 * WP Site Health integration.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SiteHealth class.
 *
 * Registers Bricks MCP checks in the WordPress Site Health screen.
 */
class SiteHealth {

	/**
	 * Register hooks for WP Site Health integration.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'site_status_tests', [ $this, 'register_tests' ] );
	}

	/**
	 * Register Bricks MCP direct tests with WP Site Health.
	 *
	 * @param array<string, mixed> $tests Existing tests.
	 * @return array<string, mixed> Modified tests with Bricks MCP checks added.
	 */
	public function register_tests( array $tests ): array {
		$tests['direct']['bricks_mcp_https'] = [
			'label' => __( 'Bricks MCP: HTTPS enabled', 'bricks-mcp' ),
			'test'  => [ $this, 'test_https' ],
		];
		$tests['direct']['bricks_mcp_permalinks'] = [
			'label' => __( 'Bricks MCP: Permalink structure', 'bricks-mcp' ),
			'test'  => [ $this, 'test_permalinks' ],
		];
		$tests['direct']['bricks_mcp_rest_api'] = [
			'label' => __( 'Bricks MCP: REST API reachable', 'bricks-mcp' ),
			'test'  => [ $this, 'test_rest_api' ],
		];
		$tests['direct']['bricks_mcp_app_passwords'] = [
			'label' => __( 'Bricks MCP: Application Passwords', 'bricks-mcp' ),
			'test'  => [ $this, 'test_app_passwords' ],
		];
		$tests['direct']['bricks_mcp_app_passwords_user'] = [
			'label' => __( 'Bricks MCP: User can use Application Passwords', 'bricks-mcp' ),
			'test'  => [ $this, 'test_app_passwords_user' ],
		];
		$tests['direct']['bricks_mcp_bricks_active'] = [
			'label' => __( 'Bricks MCP: Bricks Builder active', 'bricks-mcp' ),
			'test'  => [ $this, 'test_bricks_active' ],
		];
		$tests['direct']['bricks_mcp_security_plugin'] = [
			'label' => __( 'Bricks MCP: Security plugin compatibility', 'bricks-mcp' ),
			'test'  => [ $this, 'test_security_plugin' ],
		];
		$tests['direct']['bricks_mcp_hosting'] = [
			'label' => __( 'Bricks MCP: Hosting provider compatibility', 'bricks-mcp' ),
			'test'  => [ $this, 'test_hosting' ],
		];
		$tests['direct']['bricks_mcp_endpoint'] = [
			'label' => __( 'Bricks MCP: MCP endpoint available', 'bricks-mcp' ),
			'test'  => [ $this, 'test_mcp_endpoint' ],
		];
		$tests['direct']['bricks_mcp_php_timeout'] = [
			'label' => __( 'Bricks MCP: PHP timeout', 'bricks-mcp' ),
			'test'  => [ $this, 'test_php_timeout' ],
		];
		$tests['direct']['bricks_mcp_design_pipeline'] = [
			'label' => __( 'Bricks MCP: Design pipeline health', 'bricks-mcp' ),
			'test'  => [ $this, 'test_design_pipeline' ],
		];
		return $tests;
	}

	/**
	 * Run the REST API reachable check.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_rest_api(): array {
		$check  = new Checks\RestApiReachableCheck();
		$result = $check->run();
		return $this->format_site_health_result( $result, 'security' );
	}

	/**
	 * Run the Application Passwords available check.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_app_passwords(): array {
		$check  = new Checks\AppPasswordsAvailableCheck();
		$result = $check->run();
		return $this->format_site_health_result( $result, 'security' );
	}

	/**
	 * Run the Bricks Builder active check.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_bricks_active(): array {
		$check  = new Checks\BricksActiveCheck();
		$result = $check->run();
		return $this->format_site_health_result( $result, 'performance' );
	}

	/**
	 * Run the HTTPS check.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_https(): array {
		$check  = new Checks\HttpsCheck();
		$result = $check->run();
		return $this->format_site_health_result( $result, 'security' );
	}

	/**
	 * Run the permalink structure check.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_permalinks(): array {
		$check  = new Checks\PermalinkStructureCheck();
		$result = $check->run();
		return $this->format_site_health_result( $result, 'performance' );
	}

	/**
	 * Run the Application Passwords user check.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_app_passwords_user(): array {
		$check  = new Checks\AppPasswordsUserCheck();
		$result = $check->run();
		return $this->format_site_health_result( $result, 'security' );
	}

	/**
	 * Run the security plugin compatibility check.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_security_plugin(): array {
		$check  = new Checks\SecurityPluginCheck();
		$result = $check->run();
		return $this->format_site_health_result( $result, 'security' );
	}

	/**
	 * Run the hosting provider compatibility check.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_hosting(): array {
		$check  = new Checks\HostingProviderCheck();
		$result = $check->run();
		return $this->format_site_health_result( $result, 'performance' );
	}

	/**
	 * Run the MCP endpoint availability check.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_mcp_endpoint(): array {
		$check  = new Checks\McpEndpointCheck();
		$result = $check->run();
		return $this->format_site_health_result( $result, 'performance' );
	}

	/**
	 * Run the PHP timeout check.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_php_timeout(): array {
		$check  = new Checks\PhpTimeoutCheck();
		$result = $check->run();
		return $this->format_site_health_result( $result, 'performance' );
	}

	/**
	 * Run the design pipeline health check.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_design_pipeline(): array {
		$check  = new Checks\DesignPipelineCheck();
		$result = $check->run();
		return $this->format_site_health_result( $result, 'performance' );
	}

	/**
	 * Convert a DiagnosticCheck result to WP Site Health format.
	 *
	 * @param array<string, mixed> $check_result Result from DiagnosticCheck::run().
	 * @param string               $test_type    Site Health test type ('security' | 'performance').
	 * @return array<string, mixed> Formatted Site Health result.
	 */
	private function format_site_health_result( array $check_result, string $test_type ): array {
		$status_map = [
			'pass'    => 'good',
			'warn'    => 'recommended',
			'fail'    => 'critical',
			'skipped' => 'recommended',
		];

		$description = '<p>' . esc_html( $check_result['message'] ) . '</p>';
		if ( ! empty( $check_result['fix_steps'] ) ) {
			$description .= '<ul>';
			foreach ( $check_result['fix_steps'] as $step ) {
				$description .= '<li>' . esc_html( $step ) . '</li>';
			}
			$description .= '</ul>';
		}

		return [
			'label'       => $check_result['label'],
			'status'      => $status_map[ $check_result['status'] ] ?? 'recommended',
			'badge'       => [
				'label' => __( 'Bricks MCP', 'bricks-mcp' ),
				'color' => 'blue',
			],
			'description' => $description,
			'actions'     => '',
			'test'        => 'bricks_mcp_' . $check_result['id'],
		];
	}
}
