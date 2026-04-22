<?php
declare(strict_types=1);

namespace BricksMCP\Admin;

use BricksMCP\MCP\Services\BricksCore;
use BricksMCP\MCP\Services\DesignPatternService;

/**
 * Patterns tab admin handler.
 *
 * Manages the Patterns tab UI and pattern AJAX endpoints.
 */
class PatternsAdmin {

	/**
	 * Register AJAX handlers.
	 */
	public function init(): void {
		add_action( 'wp_ajax_bricks_mcp_list_patterns', [ $this, 'ajax_list_patterns' ] );
		add_action( 'wp_ajax_bricks_mcp_delete_pattern', [ $this, 'ajax_delete_pattern' ] );
		add_action( 'wp_ajax_bricks_mcp_export_patterns', [ $this, 'ajax_export_patterns' ] );
		add_action( 'wp_ajax_bricks_mcp_import_patterns', [ $this, 'ajax_import_patterns' ] );
		add_action( 'admin_notices', [ $this, 'maybe_render_patterns_v2_notice' ] );
	}

	/**
	 * Enqueue tab-specific assets.
	 */
	public function enqueue_assets(): void {
		wp_enqueue_script(
			'bricks-mcp-admin-patterns',
			BRICKS_MCP_PLUGIN_URL . 'assets/js/admin-patterns.js',
			[],
			BRICKS_MCP_VERSION,
			true
		);

		wp_localize_script(
			'bricks-mcp-admin-patterns',
			'bricksMcpPatterns',
			[
				'nonce' => wp_create_nonce( BricksCore::ADMIN_NONCE_ACTION ),
			]
		);

}

	/**
	 * Render the Patterns tab content.
	 */
	public function render(): void {
		$patterns = DesignPatternService::list_all();

		// Collect unique categories from patterns for the filter dropdown.
		$categories = [];
		foreach ( $patterns as $p ) {
			$cat = $p['category'] ?? '';
			if ( '' !== $cat && ! isset( $categories[ $cat ] ) ) {
				$categories[ $cat ] = ucfirst( $cat );
			}
		}
		ksort( $categories );
		?>

		<!-- ── Patterns Section ── -->
		<div class="bricks-mcp-config-section">
			<h3><?php esc_html_e( 'Design Patterns', 'bricks-mcp' ); ?></h3>
			<p class="description"><?php esc_html_e( 'All patterns live in the database. Manage via the form below or via the MCP design_pattern tool.', 'bricks-mcp' ); ?></p>

			<div class="bwm-patterns-toolbar" style="display:flex;gap:12px;margin:16px 0;align-items:center;flex-wrap:wrap;">
				<select id="bricks-mcp-pattern-filter-category">
					<option value=""><?php esc_html_e( 'All categories', 'bricks-mcp' ); ?></option>
					<?php foreach ( $categories as $cat_id => $cat_label ) : ?>
						<option value="<?php echo esc_attr( $cat_id ); ?>"><?php echo esc_html( $cat_label ); ?></option>
					<?php endforeach; ?>
				</select>
<button type="button" class="button button-secondary" id="bricks-mcp-export-patterns"><?php esc_html_e( 'Export', 'bricks-mcp' ); ?></button>
				<button type="button" class="button button-secondary" id="bricks-mcp-import-patterns-btn"><?php esc_html_e( 'Import', 'bricks-mcp' ); ?></button>
				<input type="file" id="bricks-mcp-import-file" accept=".json" style="display:none;">
				<span class="bwm-patterns-count" style="margin-left:auto;color:#666;">
					<?php
					printf(
						/* translators: %d is the total number of patterns. */
						esc_html__( '%d patterns total', 'bricks-mcp' ),
						(int) count( $patterns )
					);
					?>
				</span>
			</div>

			<table class="widefat striped" id="bricks-mcp-patterns-table">
				<thead><tr>
					<th style="width:30px;"><input type="checkbox" id="bricks-mcp-patterns-select-all"></th>
					<th><?php esc_html_e( 'Name', 'bricks-mcp' ); ?></th>
					<th style="width:100px;"><?php esc_html_e( 'Category', 'bricks-mcp' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Layout', 'bricks-mcp' ); ?></th>
					<th><?php esc_html_e( 'AI Description', 'bricks-mcp' ); ?></th>
					<th style="width:160px;"><?php esc_html_e( 'Actions', 'bricks-mcp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $patterns ) ) : ?>
					<tr class="bricks-mcp-no-patterns"><td colspan="6"><?php esc_html_e( 'No patterns found.', 'bricks-mcp' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $patterns as $p ) : ?>
					<tr data-pattern-id="<?php echo esc_attr( $p['id'] ); ?>" data-category="<?php echo esc_attr( $p['category'] ?? '' ); ?>">
						<td><input type="checkbox" class="bricks-mcp-pattern-select" value="<?php echo esc_attr( $p['id'] ); ?>"></td>
						<td>
							<strong><?php echo esc_html( (string) ( $p['name'] ?? $p['id'] ?? '' ) ); ?></strong>
							<div class="bwm-pattern-tags" style="margin-top:4px;">
								<?php foreach ( $p['tags'] ?? [] as $tag ) : ?>
									<span class="bwm-tag"><?php echo esc_html( $tag ); ?></span>
								<?php endforeach; ?>
							</div>
						</td>
						<td><?php echo esc_html( ucfirst( $p['category'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $p['layout'] ?? '' ); ?></td>
						<td style="font-size:12px;color:#666;"><?php echo esc_html( $p['ai_description'] ?? '' ); ?></td>
						<td>
							<button type="button" class="button button-small bricks-mcp-delete-pattern" data-id="<?php echo esc_attr( $p['id'] ); ?>"><?php esc_html_e( 'Delete', 'bricks-mcp' ); ?></button>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<?php
	}

	/**
	 * Render one-time admin notice for Pattern System v2 migration.
	 *
	 * Consumes the 'bricks_mcp_show_patterns_v2_notice' transient set by
	 * the Activator. Displays notice and deletes the transient.
	 */
	public function maybe_render_patterns_v2_notice(): void {
		if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
			return;
		}
		if ( ! get_transient( 'bricks_mcp_show_patterns_v2_notice' ) ) {
			return;
		}
		delete_transient( 'bricks_mcp_show_patterns_v2_notice' );
		?>
		<div class="notice notice-info is-dismissible">
			<p><strong><?php esc_html_e( 'Bricks MCP — Pattern system redesigned.', 'bricks-mcp' ); ?></strong></p>
			<p><?php esc_html_e( 'Previous patterns wiped. Capture new patterns from your built sections via MCP or paste JSON through the design_pattern tool.', 'bricks-mcp' ); ?></p>
		</div>
		<?php
	}

	// --- AJAX Handlers ---

	/**
	 * AJAX: List patterns (for refresh after mutations).
	 */
	public function ajax_list_patterns(): void {
		check_ajax_referer( BricksCore::ADMIN_NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
			return;
		}
		wp_send_json_success( DesignPatternService::list_all() );
	}

	/**
	 * AJAX: Delete a pattern.
	 */
	public function ajax_delete_pattern(): void {
		check_ajax_referer( BricksCore::ADMIN_NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
			return;
		}

		$id = isset( $_POST['pattern_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern_id'] ) ) : '';
		if ( '' === $id ) {
			wp_send_json_error( [ 'message' => __( 'Missing pattern ID.', 'bricks-mcp' ) ] );
			return;
		}

		$result = DesignPatternService::delete( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			return;
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Export patterns as JSON download.
	 */
	public function ajax_export_patterns(): void {
		check_ajax_referer( BricksCore::ADMIN_NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
			return;
		}

		$ids = [];
		if ( ! empty( $_POST['pattern_ids'] ) ) {
			$raw = wp_unslash( $_POST['pattern_ids'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_array( $raw ) ) {
				// Filter out non-scalar entries first (nested arrays would silently become '' via sanitize_text_field).
				$scalars = array_filter( $raw, 'is_scalar' );
				$ids     = array_values( array_filter( array_map( 'sanitize_text_field', $scalars ) ) );
			}
		}

		$exported = DesignPatternService::export( $ids );

		wp_send_json_success( [
			'count'    => count( $exported ),
			'patterns' => $exported,
		] );
	}

	/**
	 * AJAX: Import patterns from JSON.
	 */
	public function ajax_import_patterns(): void {
		check_ajax_referer( BricksCore::ADMIN_NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( BricksCore::REQUIRED_CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
			return;
		}

		$json = isset( $_POST['patterns_json'] ) ? wp_unslash( $_POST['patterns_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$patterns = json_decode( $json, true );
		if ( ! is_array( $patterns ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid JSON. Expected an array of pattern objects.', 'bricks-mcp' ) ] );
			return;
		}

		$result = DesignPatternService::import( $patterns );
		wp_send_json_success( $result );
	}

}
