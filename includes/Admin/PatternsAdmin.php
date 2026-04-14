<?php
declare(strict_types=1);

namespace BricksMCP\Admin;

use BricksMCP\MCP\Services\DesignPatternService;

/**
 * Patterns tab admin handler.
 *
 * Manages the Patterns tab UI and all pattern/category AJAX endpoints.
 */
class PatternsAdmin {

	/**
	 * Register AJAX handlers.
	 */
	public function init(): void {
		add_action( 'wp_ajax_bricks_mcp_list_patterns', [ $this, 'ajax_list_patterns' ] );
		add_action( 'wp_ajax_bricks_mcp_create_pattern', [ $this, 'ajax_create_pattern' ] );
		add_action( 'wp_ajax_bricks_mcp_delete_pattern', [ $this, 'ajax_delete_pattern' ] );
		add_action( 'wp_ajax_bricks_mcp_export_patterns', [ $this, 'ajax_export_patterns' ] );
		add_action( 'wp_ajax_bricks_mcp_import_patterns', [ $this, 'ajax_import_patterns' ] );
		add_action( 'wp_ajax_bricks_mcp_list_categories', [ $this, 'ajax_list_categories' ] );
		add_action( 'wp_ajax_bricks_mcp_create_category', [ $this, 'ajax_create_category' ] );
		add_action( 'wp_ajax_bricks_mcp_update_category', [ $this, 'ajax_update_category' ] );
		add_action( 'wp_ajax_bricks_mcp_delete_category', [ $this, 'ajax_delete_category' ] );
		add_action( 'wp_ajax_bricks_mcp_generate_prompt', [ $this, 'ajax_generate_prompt' ] );
		add_action( 'wp_ajax_bricks_mcp_normalize_patterns', [ $this, 'ajax_normalize_patterns' ] );
	}

	/**
	 * Enqueue tab-specific assets.
	 */
	public function enqueue_assets(): void {
		wp_enqueue_script(
			'bricks-mcp-admin-patterns',
			BRICKS_MCP_PLUGIN_URL . 'assets/js/admin-patterns.js',
			[ 'jquery' ],
			BRICKS_MCP_VERSION,
			true
		);

		wp_localize_script(
			'bricks-mcp-admin-patterns',
			'bricksMcpPatterns',
			[
				'nonce' => wp_create_nonce( 'bricks_mcp_settings_nonce' ),
			]
		);

		// WP Media Library for pattern reference images.
		wp_enqueue_media();
	}

	/**
	 * Render the Patterns tab content.
	 */
	public function render(): void {
		$patterns   = DesignPatternService::list_all();
		$categories = DesignPatternService::get_categories();

		// Count patterns per category for the categories table.
		$cat_counts = [];
		foreach ( $patterns as $p ) {
			$c = $p['category'] ?? '';
			$cat_counts[ $c ] = ( $cat_counts[ $c ] ?? 0 ) + 1;
		}
		?>

		<!-- ── Categories Section ── -->
		<div class="bricks-mcp-config-section">
			<h3><?php esc_html_e( 'Categories', 'bricks-mcp' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Manage pattern categories. Categories persist independently of patterns.', 'bricks-mcp' ); ?></p>

			<table class="widefat striped" id="bricks-mcp-categories-table" style="max-width:800px;">
				<thead><tr>
					<th><?php esc_html_e( 'Name', 'bricks-mcp' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( 'ID', 'bricks-mcp' ); ?></th>
					<th><?php esc_html_e( 'Description', 'bricks-mcp' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'Patterns', 'bricks-mcp' ); ?></th>
					<th style="width:160px;"><?php esc_html_e( 'Actions', 'bricks-mcp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $categories as $cat ) :
					$cid   = $cat['id'] ?? '';
					$count = $cat_counts[ $cid ] ?? 0;
				?>
				<tr data-category-id="<?php echo esc_attr( $cid ); ?>">
					<td>
						<span class="bwm-cat-display"><?php echo esc_html( $cat['name'] ?? '' ); ?></span>
						<input type="text" class="bwm-cat-edit bwm-cat-edit-name regular-text" value="<?php echo esc_attr( $cat['name'] ?? '' ); ?>" style="display:none;">
					</td>
					<td><code><?php echo esc_html( $cid ); ?></code></td>
					<td>
						<span class="bwm-cat-display"><?php echo esc_html( $cat['description'] ?? '' ); ?></span>
						<input type="text" class="bwm-cat-edit bwm-cat-edit-desc regular-text" value="<?php echo esc_attr( $cat['description'] ?? '' ); ?>" style="display:none;">
					</td>
					<td><?php echo (int) $count; ?></td>
					<td>
						<button type="button" class="button button-small bricks-mcp-edit-category"><?php esc_html_e( 'Edit', 'bricks-mcp' ); ?></button>
						<button type="button" class="button button-small bricks-mcp-save-category" style="display:none;"><?php esc_html_e( 'Save', 'bricks-mcp' ); ?></button>
						<button type="button" class="button button-small bricks-mcp-cancel-edit-category" style="display:none;"><?php esc_html_e( 'Cancel', 'bricks-mcp' ); ?></button>
						<button type="button" class="button button-small bricks-mcp-delete-category" data-id="<?php echo esc_attr( $cid ); ?>" data-count="<?php echo (int) $count; ?>"><?php esc_html_e( 'Delete', 'bricks-mcp' ); ?></button>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<div style="margin-top:12px;display:flex;gap:8px;align-items:center;max-width:800px;">
				<input type="text" id="bricks-mcp-new-category-name" class="regular-text" placeholder="<?php esc_attr_e( 'Category name...', 'bricks-mcp' ); ?>" style="flex:1;">
				<input type="text" id="bricks-mcp-new-category-desc" class="regular-text" placeholder="<?php esc_attr_e( 'Description (optional)', 'bricks-mcp' ); ?>" style="flex:1;">
				<button type="button" class="button button-secondary" id="bricks-mcp-add-category-btn"><?php esc_html_e( 'Add Category', 'bricks-mcp' ); ?></button>
			</div>
		</div>

		<!-- ── Patterns Section ── -->
		<div class="bricks-mcp-config-section">
			<h3><?php esc_html_e( 'Design Patterns', 'bricks-mcp' ); ?></h3>
			<p class="description"><?php esc_html_e( 'All patterns live in the database. Manage via the form below or via the MCP design_pattern tool.', 'bricks-mcp' ); ?></p>

			<div class="bwm-patterns-toolbar" style="display:flex;gap:12px;margin:16px 0;align-items:center;flex-wrap:wrap;">
				<select id="bricks-mcp-pattern-filter-category">
					<option value=""><?php esc_html_e( 'All categories', 'bricks-mcp' ); ?></option>
					<?php foreach ( $categories as $cat ) : ?>
						<option value="<?php echo esc_attr( $cat['id'] ); ?>"><?php echo esc_html( $cat['name'] ?? ucfirst( $cat['id'] ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<select id="bricks-mcp-pattern-filter-source">
					<option value=""><?php esc_html_e( 'All sources', 'bricks-mcp' ); ?></option>
					<option value="database"><?php esc_html_e( 'Database', 'bricks-mcp' ); ?></option>
					<option value="user_file"><?php esc_html_e( 'User File', 'bricks-mcp' ); ?></option>
				</select>
				<button type="button" class="button button-primary" id="bricks-mcp-add-pattern-btn"><?php esc_html_e( 'Add Pattern', 'bricks-mcp' ); ?></button>
				<button type="button" class="button button-secondary" id="bricks-mcp-export-patterns"><?php esc_html_e( 'Export', 'bricks-mcp' ); ?></button>
				<button type="button" class="button button-secondary" id="bricks-mcp-import-patterns-btn"><?php esc_html_e( 'Import', 'bricks-mcp' ); ?></button>
				<input type="file" id="bricks-mcp-import-file" accept=".json" style="display:none;">
				<span class="bwm-patterns-count" style="margin-left:auto;color:#666;">
					<?php printf( esc_html__( '%d patterns total', 'bricks-mcp' ), count( $patterns ) ); ?>
				</span>
			</div>

			<table class="widefat striped" id="bricks-mcp-patterns-table">
				<thead><tr>
					<th style="width:30px;"><input type="checkbox" id="bricks-mcp-patterns-select-all"></th>
					<th><?php esc_html_e( 'Name', 'bricks-mcp' ); ?></th>
					<th style="width:100px;"><?php esc_html_e( 'Category', 'bricks-mcp' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Layout', 'bricks-mcp' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'Source', 'bricks-mcp' ); ?></th>
					<th><?php esc_html_e( 'AI Description', 'bricks-mcp' ); ?></th>
					<th style="width:160px;"><?php esc_html_e( 'Actions', 'bricks-mcp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $patterns ) ) : ?>
					<tr class="bricks-mcp-no-patterns"><td colspan="7"><?php esc_html_e( 'No patterns found.', 'bricks-mcp' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $patterns as $p ) :
						$source = $p['source'] ?? 'database';
						$is_db  = 'database' === $source;
						$badge_class = match( $source ) {
							'database'  => 'bwm-badge-db',
							'user_file' => 'bwm-badge-user',
							default     => 'bwm-badge-plugin',
						};
					?>
					<tr data-pattern-id="<?php echo esc_attr( $p['id'] ); ?>" data-category="<?php echo esc_attr( $p['category'] ?? '' ); ?>" data-source="<?php echo esc_attr( $source ); ?>">
						<td><input type="checkbox" class="bricks-mcp-pattern-select" value="<?php echo esc_attr( $p['id'] ); ?>"></td>
						<td>
							<strong><?php echo esc_html( $p['name'] ?? $p['id'] ); ?></strong>
							<div class="bwm-pattern-tags" style="margin-top:4px;">
								<?php foreach ( $p['tags'] ?? [] as $tag ) : ?>
									<span class="bwm-tag"><?php echo esc_html( $tag ); ?></span>
								<?php endforeach; ?>
							</div>
						</td>
						<td><?php echo esc_html( ucfirst( $p['category'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $p['layout'] ?? '' ); ?></td>
						<td><span class="bwm-source-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $source ); ?></span></td>
						<td style="font-size:12px;color:#666;"><?php echo esc_html( $p['ai_description'] ?? '' ); ?></td>
						<td>
							<?php if ( $is_db ) : ?>
								<button type="button" class="button button-small bricks-mcp-edit-pattern" data-id="<?php echo esc_attr( $p['id'] ); ?>"><?php esc_html_e( 'Edit', 'bricks-mcp' ); ?></button>
								<button type="button" class="button button-small bricks-mcp-delete-pattern" data-id="<?php echo esc_attr( $p['id'] ); ?>"><?php esc_html_e( 'Delete', 'bricks-mcp' ); ?></button>
							<?php else : ?>
								<button type="button" class="button button-small bricks-mcp-view-pattern" data-id="<?php echo esc_attr( $p['id'] ); ?>"><?php esc_html_e( 'View', 'bricks-mcp' ); ?></button>
								<button type="button" class="button button-small bricks-mcp-hide-pattern" data-id="<?php echo esc_attr( $p['id'] ); ?>"><?php esc_html_e( 'Hide', 'bricks-mcp' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<!-- View Pattern JSON Modal (read-only for non-DB) -->
		<div id="bricks-mcp-pattern-modal" style="display:none;">
			<div class="bwm-modal-backdrop"></div>
			<div class="bwm-modal-content">
				<div class="bwm-modal-header">
					<h3 id="bricks-mcp-modal-title"><?php esc_html_e( 'Pattern JSON', 'bricks-mcp' ); ?></h3>
					<button type="button" class="bwm-modal-close">&times;</button>
				</div>
				<textarea id="bricks-mcp-pattern-json" rows="20" style="width:100%;font-family:monospace;font-size:12px;"></textarea>
				<div class="bwm-modal-footer" style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end;">
					<button type="button" class="button button-secondary bwm-modal-close"><?php esc_html_e( 'Close', 'bricks-mcp' ); ?></button>
					<button type="button" class="button button-primary" id="bricks-mcp-save-pattern" style="display:none;"><?php esc_html_e( 'Save Changes', 'bricks-mcp' ); ?></button>
				</div>
			</div>
		</div>

		<!-- Pattern Creator/Editor Modal -->
		<div id="bricks-mcp-creator-modal" style="display:none;">
			<div class="bwm-modal-backdrop"></div>
			<div class="bwm-modal-content" style="max-width:900px;">
				<div class="bwm-modal-header">
					<h3 id="bricks-mcp-creator-modal-title"><?php esc_html_e( 'Add New Pattern', 'bricks-mcp' ); ?></h3>
					<button type="button" class="bwm-modal-close">&times;</button>
				</div>

				<div class="bwm-creator-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
					<!-- Left column: metadata -->
					<div>
						<p><label><strong><?php esc_html_e( 'Name', 'bricks-mcp' ); ?> *</strong></label><br>
						<input type="text" id="bricks-mcp-creator-name" class="regular-text" style="width:100%;"></p>

						<p><label><strong><?php esc_html_e( 'ID', 'bricks-mcp' ); ?></strong> <small>(<?php esc_html_e( 'auto-generated from name', 'bricks-mcp' ); ?>)</small></label><br>
						<input type="text" id="bricks-mcp-creator-id" class="regular-text" style="width:100%;" pattern="[a-z0-9-]+"></p>

						<p><label><strong><?php esc_html_e( 'Category', 'bricks-mcp' ); ?> *</strong></label><br>
						<select id="bricks-mcp-creator-category" style="width:100%;">
							<option value=""><?php esc_html_e( 'Select...', 'bricks-mcp' ); ?></option>
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo esc_attr( $cat['id'] ); ?>"><?php echo esc_html( $cat['name'] ?? ucfirst( $cat['id'] ) ); ?></option>
							<?php endforeach; ?>
						</select></p>

						<p><label><strong><?php esc_html_e( 'Tags', 'bricks-mcp' ); ?></strong> <small>(<?php esc_html_e( 'comma-separated', 'bricks-mcp' ); ?>)</small></label><br>
						<input type="text" id="bricks-mcp-creator-tags" class="regular-text" style="width:100%;" placeholder="dark, centered, cta"></p>

						<div style="display:flex;gap:12px;">
							<p style="flex:1;"><label><strong><?php esc_html_e( 'Layout', 'bricks-mcp' ); ?></strong></label><br>
							<select id="bricks-mcp-creator-layout" style="width:100%;">
								<option value="">—</option>
								<option value="centered">centered</option>
								<option value="split-60-40">split-60-40</option>
								<option value="split-50-50">split-50-50</option>
								<option value="grid-2">grid-2</option>
								<option value="grid-3">grid-3</option>
								<option value="grid-4">grid-4</option>
								<option value="stacked">stacked</option>
							</select></p>

							<p style="flex:1;"><label><strong><?php esc_html_e( 'Background', 'bricks-mcp' ); ?></strong></label><br>
							<select id="bricks-mcp-creator-bg" style="width:100%;">
								<option value="light">light</option>
								<option value="dark">dark</option>
							</select></p>
						</div>

						<p><label><strong><?php esc_html_e( 'Reference Image', 'bricks-mcp' ); ?></strong></label><br>
						<button type="button" class="button button-secondary" id="bricks-mcp-creator-upload-image"><?php esc_html_e( 'Upload Image', 'bricks-mcp' ); ?></button>
						<input type="hidden" id="bricks-mcp-creator-image-id">
						<br><img id="bricks-mcp-creator-image-preview" src="" style="display:none;max-width:200px;margin-top:8px;border-radius:8px;"></p>
					</div>

					<!-- Right column: AI metadata + composition -->
					<div>
						<p><label><strong><?php esc_html_e( 'AI Description', 'bricks-mcp' ); ?></strong> <small id="bricks-mcp-creator-char-count">0/300</small></label><br>
						<textarea id="bricks-mcp-creator-ai-desc" rows="3" style="width:100%;" maxlength="300" placeholder="<?php esc_attr_e( '1-2 sentence description of what the pattern looks like when built...', 'bricks-mcp' ); ?>"></textarea></p>

						<p><label><strong><?php esc_html_e( 'AI Usage Hints', 'bricks-mcp' ); ?></strong> <small>(<?php esc_html_e( 'one per line, max 5', 'bricks-mcp' ); ?>)</small></label><br>
						<textarea id="bricks-mcp-creator-ai-hints" rows="3" style="width:100%;" placeholder="<?php esc_attr_e( "Best as first section on homepage\nPair with features section below", 'bricks-mcp' ); ?>"></textarea></p>

						<p><label><strong><?php esc_html_e( 'Composition / Structure JSON', 'bricks-mcp' ); ?></strong></label><br>
						<button type="button" class="button button-small" id="bricks-mcp-creator-generate-ai" style="margin-bottom:8px;"><?php esc_html_e( 'Generate AI Prompt', 'bricks-mcp' ); ?></button>
						<small style="color:#666;"><?php esc_html_e( 'Copies a prompt to clipboard — paste into Claude Code, get the JSON back, paste below.', 'bricks-mcp' ); ?></small><br>
						<textarea id="bricks-mcp-creator-composition" rows="12" style="width:100%;font-family:monospace;font-size:12px;" placeholder='<?php echo esc_attr( "{\n  \"composition\": [...],\n  \"columns\": {...},\n  \"patterns\": {...}\n}" ); ?>'></textarea></p>
					</div>
				</div>

				<div class="bwm-modal-footer" style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
					<button type="button" class="button button-secondary bwm-modal-close"><?php esc_html_e( 'Cancel', 'bricks-mcp' ); ?></button>
					<button type="button" class="button button-primary" id="bricks-mcp-creator-save"><?php esc_html_e( 'Save Pattern', 'bricks-mcp' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	// --- AJAX Handlers ---

	/**
	 * AJAX: List patterns (for refresh after mutations).
	 */
	public function ajax_list_patterns(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}
		wp_send_json_success( DesignPatternService::list_all() );
	}

	/**
	 * AJAX: Create a new database pattern.
	 */
	public function ajax_create_pattern(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		$json = isset( $_POST['pattern_json'] ) ? wp_unslash( $_POST['pattern_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$pattern = json_decode( $json, true );
		if ( ! is_array( $pattern ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid JSON.', 'bricks-mcp' ) ] );
		}

		$result = DesignPatternService::create( $pattern );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Delete/hide a pattern.
	 */
	public function ajax_delete_pattern(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		$id = isset( $_POST['pattern_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern_id'] ) ) : '';
		if ( '' === $id ) {
			wp_send_json_error( [ 'message' => __( 'Missing pattern ID.', 'bricks-mcp' ) ] );
		}

		$result = DesignPatternService::delete( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Export patterns as JSON download.
	 */
	public function ajax_export_patterns(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		$ids = [];
		if ( ! empty( $_POST['pattern_ids'] ) ) {
			$raw = wp_unslash( $_POST['pattern_ids'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$ids = is_array( $raw ) ? array_map( 'sanitize_text_field', $raw ) : [];
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
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		$json = isset( $_POST['patterns_json'] ) ? wp_unslash( $_POST['patterns_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$patterns = json_decode( $json, true );
		if ( ! is_array( $patterns ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid JSON. Expected an array of pattern objects.', 'bricks-mcp' ) ] );
		}

		$result = DesignPatternService::import( $patterns );
		wp_send_json_success( $result );
	}

	// ──────────────────────────────────────────────
	// Category AJAX handlers
	// ──────────────────────────────────────────────

	/**
	 * AJAX: List categories.
	 */
	public function ajax_list_categories(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}
		wp_send_json_success( DesignPatternService::get_categories() );
	}

	/**
	 * AJAX: Create a category.
	 */
	public function ajax_create_category(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		$result = DesignPatternService::create_category( [
			'name'        => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'description' => sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) ),
		] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Update a category.
	 */
	public function ajax_update_category(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		$id      = sanitize_text_field( wp_unslash( $_POST['category_id'] ?? '' ) );
		$updates = [];
		if ( isset( $_POST['name'] ) ) {
			$updates['name'] = sanitize_text_field( wp_unslash( $_POST['name'] ) );
		}
		if ( isset( $_POST['description'] ) ) {
			$updates['description'] = sanitize_text_field( wp_unslash( $_POST['description'] ) );
		}

		$result = DesignPatternService::update_category( $id, $updates );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Delete a category.
	 */
	public function ajax_delete_category(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		$id     = sanitize_text_field( wp_unslash( $_POST['category_id'] ?? '' ) );
		$result = DesignPatternService::delete_category( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Generate AI prompt for pattern creation.
	 */
	public function ajax_generate_prompt(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		$description = sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) );
		$category    = sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) );

		if ( '' === $description ) {
			wp_send_json_error( [ 'message' => __( 'Description is required.', 'bricks-mcp' ) ] );
		}

		$result = DesignPatternService::generate_prompt_template( $description, $category );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Normalize patterns (map classes/variables to site).
	 */
	public function ajax_normalize_patterns(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		$json     = isset( $_POST['patterns_json'] ) ? wp_unslash( $_POST['patterns_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$patterns = json_decode( $json, true );
		if ( ! is_array( $patterns ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid JSON.', 'bricks-mcp' ) ] );
		}

		$normalized = [];
		$all_warnings = [];
		foreach ( $patterns as $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}
			$result       = DesignPatternService::normalize_pattern( $pattern );
			$normalized[] = $result['pattern'];
			$all_warnings = array_merge( $all_warnings, $result['warnings'] );
		}

		wp_send_json_success( [
			'patterns' => $normalized,
			'warnings' => array_unique( $all_warnings ),
		] );
	}
}
