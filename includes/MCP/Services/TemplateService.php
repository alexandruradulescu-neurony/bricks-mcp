<?php
/**
 * Template management sub-service.
 *
 * Handles CRUD for Bricks templates, conditions, taxonomy terms, and import/export.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TemplateService class.
 */
class TemplateService {

	/**
	 * Core infrastructure.
	 *
	 * @var BricksCore
	 */
	private BricksCore $core;

	/**
	 * Global class service for import operations.
	 *
	 * @var GlobalClassService
	 */
	private GlobalClassService $global_class_service;

	/**
	 * Constructor.
	 *
	 * @param BricksCore         $core                 Shared infrastructure.
	 * @param GlobalClassService $global_class_service Global class service.
	 */
	public function __construct( BricksCore $core, GlobalClassService $global_class_service ) {
		$this->core                 = $core;
		$this->global_class_service = $global_class_service;
	}

	/**
	 * Get valid Bricks template type slugs.
	 *
	 * @return array<int, string> Array of valid template type slugs.
	 */
	public function get_valid_template_types(): array {
		$types = [ 'header', 'footer', 'archive', 'search', 'error', 'content', 'section', 'popup', 'password_protection' ];

		if ( class_exists( 'WooCommerce' ) ) {
			$types = array_merge( $types, [ 'wc_product', 'wc_archive', 'wc_cart', 'wc_cart_empty', 'wc_checkout', 'wc_account_form', 'wc_account_page', 'wc_thankyou' ] );
		}

		return $types;
	}

	/**
	 * Create a new Bricks template.
	 *
	 * @param array<string, mixed> $args Template creation arguments.
	 * @return int|\WP_Error New template post ID on success, WP_Error on failure.
	 */
	public function create_template( array $args ): int|\WP_Error {
		if ( empty( $args['title'] ) ) {
			return new \WP_Error( 'missing_title', __( 'Template title is required. Provide a non-empty "title" parameter.', 'bricks-mcp' ) );
		}

		if ( empty( $args['type'] ) ) {
			return new \WP_Error( 'missing_type', __( 'Template type is required. Provide a "type" parameter (e.g., header, footer, content, section, popup).', 'bricks-mcp' ) );
		}

		$type        = sanitize_key( $args['type'] );
		$valid_types = $this->get_valid_template_types();

		if ( ! in_array( $type, $valid_types, true ) ) {
			return new \WP_Error( 'invalid_template_type', sprintf( __( 'Invalid template type "%1$s". Valid types: %2$s.', 'bricks-mcp' ), $type, implode( ', ', $valid_types ) ) );
		}

		$post_data = [
			'post_title'   => sanitize_text_field( $args['title'] ),
			'post_type'    => 'bricks_template',
			'post_status'  => sanitize_key( $args['status'] ?? 'publish' ),
			'post_content' => '',
		];

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->core->unhook_bricks_meta_filters();
		try {
			update_post_meta( $post_id, '_bricks_template_type', $type );
		} finally {
			$this->core->rehook_bricks_meta_filters();
		}

		$this->core->enable_bricks_editor( $post_id );

		if ( ! empty( $args['elements'] ) && is_array( $args['elements'] ) ) {
			$normalized  = $this->core->normalize_elements( $args['elements'] );
			$save_result = $this->core->save_elements( $post_id, $normalized );
			if ( is_wp_error( $save_result ) ) {
				wp_delete_post( $post_id, true );
				return $save_result;
			}
		}

		if ( ! empty( $args['conditions'] ) && is_array( $args['conditions'] ) ) {
			$this->core->unhook_bricks_meta_filters();
			try {
				$settings               = get_post_meta( $post_id, '_bricks_template_settings', true );
				$settings               = is_array( $settings ) ? $settings : [];
				$settings['conditions'] = $args['conditions'];
				update_post_meta( $post_id, '_bricks_template_settings', $settings );
			} finally {
				$this->core->rehook_bricks_meta_filters();
			}
		}

		return $post_id;
	}

	/**
	 * Update Bricks template metadata.
	 *
	 * @param int                  $template_id Template post ID.
	 * @param array<string, mixed> $args        Fields to update.
	 * @return true|array<string, mixed>|\WP_Error True on success, array with warning when type changed, WP_Error on failure.
	 */
	public function update_template_meta( int $template_id, array $args ): true|array|\WP_Error {
		$post = get_post( $template_id );
		if ( ! $post || 'bricks_template' !== $post->post_type ) {
			return new \WP_Error( 'template_not_found', sprintf( __( 'Bricks template %d not found. Verify the template_id is a valid bricks_template post.', 'bricks-mcp' ), $template_id ) );
		}

		$post_data = [ 'ID' => $template_id ];
		$warning   = null;

		if ( isset( $args['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['status'] ) ) {
			$post_data['post_status'] = sanitize_key( $args['status'] );
		}
		if ( isset( $args['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $args['slug'] );
		}

		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( isset( $args['type'] ) ) {
			$new_type    = sanitize_key( $args['type'] );
			$valid_types = $this->get_valid_template_types();

			if ( ! in_array( $new_type, $valid_types, true ) ) {
				return new \WP_Error( 'invalid_template_type', sprintf( __( 'Invalid template type "%1$s". Valid types: %2$s.', 'bricks-mcp' ), $new_type, implode( ', ', $valid_types ) ) );
			}

			$old_type = get_post_meta( $template_id, '_bricks_template_type', true );
			if ( $old_type !== $new_type ) {
				$warning = sprintf( __( 'Template type changed from "%1$s" to "%2$s". Existing elements may need to be reviewed for compatibility with the new template slot.', 'bricks-mcp' ), $old_type, $new_type );
			}

			$this->core->unhook_bricks_meta_filters();
			try {
				update_post_meta( $template_id, '_bricks_template_type', $new_type );
			} finally {
				$this->core->rehook_bricks_meta_filters();
			}
		}

		if ( isset( $args['tags'] ) && is_array( $args['tags'] ) ) {
			wp_set_object_terms( $template_id, $args['tags'], 'template_tag' );
		}
		if ( isset( $args['bundles'] ) && is_array( $args['bundles'] ) ) {
			wp_set_object_terms( $template_id, $args['bundles'], 'template_bundle' );
		}

		if ( null !== $warning ) {
			return [ 'warning' => $warning ];
		}

		return true;
	}

	/**
	 * Duplicate a Bricks template without conditions.
	 *
	 * @param int $template_id Template post ID to duplicate.
	 * @return int|\WP_Error New template post ID on success, WP_Error on failure.
	 */
	public function duplicate_template( int $template_id ): int|\WP_Error {
		$post = get_post( $template_id );
		if ( ! $post || 'bricks_template' !== $post->post_type ) {
			return new \WP_Error( 'template_not_found', sprintf( __( 'Bricks template %d not found. Verify the template_id is a valid bricks_template post.', 'bricks-mcp' ), $template_id ) );
		}

		// Duplicate using the page operations service pattern (inline since we need it here).
		$new_post_data = [
			'post_title'   => $post->post_title . __( ' (Copy)', 'bricks-mcp' ),
			'post_type'    => $post->post_type,
			'post_status'  => 'draft',
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_author'  => $post->post_author,
		];

		$new_post_id = wp_insert_post( $new_post_data, true );
		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		$denied_meta_keys = [ '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_trash_meta_status', '_wp_trash_meta_time' ];
		$all_meta         = get_post_meta( $template_id );
		if ( is_array( $all_meta ) ) {
			foreach ( $all_meta as $meta_key => $meta_values ) {
				if ( in_array( $meta_key, $denied_meta_keys, true ) ) {
					continue;
				}
				foreach ( $meta_values as $meta_value ) {
					$unserialized = maybe_unserialize( $meta_value );
					update_post_meta( $new_post_id, $meta_key, $unserialized );
				}
			}
		}

		// Strip conditions from the copy.
		$this->core->unhook_bricks_meta_filters();
		try {
			$settings = get_post_meta( $new_post_id, '_bricks_template_settings', true );
			$settings = is_array( $settings ) ? $settings : [];
			unset( $settings['conditions'] );
			update_post_meta( $new_post_id, '_bricks_template_settings', $settings );
		} finally {
			$this->core->rehook_bricks_meta_filters();
		}

		return $new_post_id;
	}

	/**
	 * Get Bricks templates with metadata.
	 *
	 * @param string $type   Optional template type filter.
	 * @param string $status Post status filter.
	 * @param string $tag    Optional template_tag taxonomy slug filter.
	 * @param string $bundle Optional template_bundle taxonomy slug filter.
	 * @return array<int, array<string, mixed>> Array of template metadata.
	 */
	public function get_templates( string $type = '', string $status = 'publish', string $tag = '', string $bundle = '' ): array {
		$query_args = [
			'post_type'      => 'bricks_template',
			'post_status'    => '' !== $status ? sanitize_key( $status ) : 'publish',
			'posts_per_page' => 100,
			'no_found_rows'  => true,
		];

		$meta_query = [];
		if ( '' !== $type ) {
			$meta_query[] = [ 'key' => '_bricks_template_type', 'value' => sanitize_key( $type ) ];
		}
		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$tax_query = [];
		if ( '' !== $tag ) {
			$tax_query[] = [ 'taxonomy' => 'template_tag', 'field' => 'slug', 'terms' => sanitize_key( $tag ) ];
		}
		if ( '' !== $bundle ) {
			$tax_query[] = [ 'taxonomy' => 'template_bundle', 'field' => 'slug', 'terms' => sanitize_key( $bundle ) ];
		}
		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$query     = new \WP_Query( $query_args );
		$templates = [];

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$template_type = get_post_meta( $post->ID, '_bricks_template_type', true );
			$settings      = get_post_meta( $post->ID, '_bricks_template_settings', true );
			$elements      = $this->core->get_elements( $post->ID );

			$tags_terms   = wp_get_object_terms( $post->ID, 'template_tag', [ 'fields' => 'slugs' ] );
			$bundle_terms = wp_get_object_terms( $post->ID, 'template_bundle', [ 'fields' => 'slugs' ] );

			$templates[] = [
				'id'            => $post->ID,
				'title'         => $post->post_title,
				'status'        => $post->post_status,
				'type'          => ! empty( $template_type ) ? $template_type : 'content',
				'is_infobox'    => 'popup' === $template_type && ! empty( $settings['popupIsInfoBox'] ),
				'conditions'    => $this->format_conditions( $settings ),
				'element_count' => count( $elements ),
				'modified'      => $post->post_modified,
				'tags'          => is_array( $tags_terms ) ? $tags_terms : [],
				'bundles'       => is_array( $bundle_terms ) ? $bundle_terms : [],
			];
		}

		return $templates;
	}

	/**
	 * Get full Bricks template content with context.
	 *
	 * @param int $template_id Template post ID.
	 * @return array<string, mixed>|\WP_Error Template content or WP_Error if not found.
	 */
	public function get_template_content_data( int $template_id ): array|\WP_Error {
		$post = get_post( $template_id );
		if ( ! $post || 'bricks_template' !== $post->post_type ) {
			return new \WP_Error( 'template_not_found', sprintf( __( 'Bricks template %d not found. Verify the template_id is a valid bricks_template post.', 'bricks-mcp' ), $template_id ) );
		}

		$elements      = $this->core->get_elements( $template_id );
		$template_type = get_post_meta( $template_id, '_bricks_template_type', true );
		$settings      = get_post_meta( $template_id, '_bricks_template_settings', true );

		$global_classes = get_option( 'bricks_global_classes', [] );
		$class_map      = [];
		if ( is_array( $global_classes ) ) {
			foreach ( $global_classes as $class ) {
				if ( isset( $class['id'], $class['name'] ) ) {
					$class_map[ $class['id'] ] = $class['name'];
				}
			}
		}

		$used_class_names = [];
		foreach ( $elements as $element ) {
			$class_ids = $element['settings']['_cssGlobalClasses'] ?? [];
			if ( is_array( $class_ids ) ) {
				foreach ( $class_ids as $class_id ) {
					if ( isset( $class_map[ $class_id ] ) ) {
						$used_class_names[] = $class_map[ $class_id ];
					}
				}
			}
		}

		return [
			'id'           => $template_id,
			'title'        => $post->post_title,
			'type'         => ! empty( $template_type ) ? $template_type : 'content',
			'is_infobox'   => 'popup' === $template_type && ! empty( $settings['popupIsInfoBox'] ),
			'conditions'   => $this->format_conditions( $settings ),
			'elements'     => $elements,
			'classes_used' => array_values( array_unique( $used_class_names ) ),
		];
	}

	/**
	 * Format template conditions into human-readable strings.
	 *
	 * @param mixed $settings Template settings.
	 * @return array<int, array{summary: string, raw: array}> Formatted conditions.
	 */
	public function format_conditions( mixed $settings ): array {
		if ( ! is_array( $settings ) || empty( $settings['conditions'] ) || ! is_array( $settings['conditions'] ) ) {
			return [];
		}

		$formatted = [];

		foreach ( $settings['conditions'] as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}

			$main    = $condition['main'] ?? 'unknown';
			$summary = match ( $main ) {
				'ids'              => 'Specific posts: ' . implode( ', ', (array) ( $condition['ids'] ?? [] ) ),
				'postType'         => 'Post type: ' . ( $condition['postType'] ?? 'any' ),
				'any'              => 'Entire website',
				'frontpage'        => 'Front page',
				'archivePostType'  => 'Archive: ' . ( $condition['archivePostType'] ?? 'any' ),
				'terms'            => 'Terms: ' . implode( ', ', (array) ( $condition['terms'] ?? [] ) ),
				default            => 'Condition: ' . $main,
			};

			$formatted[] = [ 'summary' => $summary, 'raw' => $condition ];
		}

		return $formatted;
	}

	/**
	 * Set conditions on a Bricks template.
	 *
	 * @param int                              $template_id Template post ID.
	 * @param array<int, array<string, mixed>> $conditions  Array of Bricks condition objects.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function set_template_conditions( int $template_id, array $conditions ): true|\WP_Error {
		$post = get_post( $template_id );
		if ( ! $post || 'bricks_template' !== $post->post_type ) {
			return new \WP_Error( 'template_not_found', sprintf( __( 'Bricks template %d not found. Verify the template_id is a valid bricks_template post.', 'bricks-mcp' ), $template_id ) );
		}

		$valid_types = array_keys( $this->get_condition_types() );

		foreach ( $conditions as $index => $condition ) {
			if ( ! is_array( $condition ) || ! isset( $condition['main'] ) ) {
				return new \WP_Error( 'invalid_condition', sprintf( __( 'Condition at index %d is missing required "main" key.', 'bricks-mcp' ), $index ) );
			}

			if ( ! in_array( $condition['main'], $valid_types, true ) ) {
				return new \WP_Error( 'invalid_condition_type', sprintf( __( 'Unknown condition type "%1$s". Valid types: %2$s.', 'bricks-mcp' ), $condition['main'], implode( ', ', $valid_types ) ) );
			}
		}

		$this->core->unhook_bricks_meta_filters();
		try {
			$settings               = get_post_meta( $template_id, '_bricks_template_settings', true );
			$settings               = is_array( $settings ) ? $settings : [];
			$settings['conditions'] = $conditions;
			update_post_meta( $template_id, '_bricks_template_settings', $settings );
		} finally {
			$this->core->rehook_bricks_meta_filters();
		}

		return true;
	}

	/**
	 * Resolve which Bricks templates would apply to a specific post.
	 *
	 * @param int $post_id Post ID to resolve templates for.
	 * @return array<string, mixed>|\WP_Error Resolution data or WP_Error if post not found.
	 */
	public function resolve_templates_for_post( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id ) );
		}

		$post_type     = $post->post_type;
		$front_page_id = (int) get_option( 'page_on_front' );
		$is_front_page = $front_page_id > 0 && $post_id === $front_page_id;

		$post_terms     = [];
		$all_taxonomies = get_object_taxonomies( $post_type );
		foreach ( $all_taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'id=>slug' ] );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term_id => $term_slug ) {
					$post_terms[] = $taxonomy . '::' . $term_id;
				}
			}
		}

		$template_query = new \WP_Query( [ 'post_type' => 'bricks_template', 'post_status' => 'publish', 'posts_per_page' => -1, 'no_found_rows' => true ] );
		$candidates     = [];

		foreach ( $template_query->posts as $tpl_post ) {
			if ( ! $tpl_post instanceof \WP_Post ) {
				continue;
			}

			$tpl_type     = get_post_meta( $tpl_post->ID, '_bricks_template_type', true );
			$tpl_type     = ! empty( $tpl_type ) ? (string) $tpl_type : 'content';
			$tpl_settings = get_post_meta( $tpl_post->ID, '_bricks_template_settings', true );
			$conditions   = ( is_array( $tpl_settings ) && ! empty( $tpl_settings['conditions'] ) ) ? $tpl_settings['conditions'] : [];

			if ( empty( $conditions ) ) {
				continue;
			}

			$max_score = 0;
			foreach ( $conditions as $condition ) {
				if ( ! is_array( $condition ) || ! isset( $condition['main'] ) ) {
					continue;
				}
				$score = $this->evaluate_condition_score( $condition, $post_id, $post_type, $is_front_page, $post_terms );
				if ( $score > $max_score ) {
					$max_score = $score;
				}
			}

			if ( $max_score > 0 ) {
				if ( ! isset( $candidates[ $tpl_type ] ) ) {
					$candidates[ $tpl_type ] = [];
				}
				$candidates[ $tpl_type ][] = [ 'template' => [ 'id' => $tpl_post->ID, 'title' => $tpl_post->post_title ], 'score' => $max_score ];
			}
		}

		$resolved = [];
		foreach ( $candidates as $tpl_type => $type_candidates ) {
			usort( $type_candidates, static fn( array $a, array $b ) => $b['score'] - $a['score'] );
			$resolved[ $tpl_type ] = [
				'active'     => $type_candidates[0]['template'] + [ 'score' => $type_candidates[0]['score'] ],
				'candidates' => $type_candidates,
			];
		}

		return [
			'post_id'   => $post_id,
			'post_type' => $post_type,
			'resolved'  => $resolved,
			'note'      => 'Resolution shows templates matching this specific post. Archive/search/error templates cannot be resolved by post_id.',
		];
	}

	/**
	 * Evaluate how well a single condition matches the given post context.
	 *
	 * @param array<string, mixed> $condition    Condition array.
	 * @param int                  $post_id      Post ID.
	 * @param string               $post_type    Post type slug.
	 * @param bool                 $is_front_page Whether this post is the front page.
	 * @param array<int, string>   $post_terms   Array of "taxonomy::term_id" strings.
	 * @return int Score (0 = no match).
	 */
	public function evaluate_condition_score( array $condition, int $post_id, string $post_type, bool $is_front_page, array $post_terms ): int {
		$main = $condition['main'];

		switch ( $main ) {
			case 'any':
				return 2;
			case 'frontpage':
				return $is_front_page ? 9 : 0;
			case 'ids':
				$ids = isset( $condition['ids'] ) && is_array( $condition['ids'] ) ? $condition['ids'] : [];
				return in_array( $post_id, array_map( 'intval', $ids ), true ) ? 10 : 0;
			case 'postType':
				$required_type = $condition['postType'] ?? '';
				return $post_type === $required_type ? 8 : 0;
			case 'terms':
				$required_terms = isset( $condition['terms'] ) && is_array( $condition['terms'] ) ? $condition['terms'] : [];
				foreach ( $required_terms as $term_ref ) {
					if ( in_array( $term_ref, $post_terms, true ) ) {
						return 8;
					}
				}
				return 0;
			case 'archivePostType':
			case 'archiveType':
			case 'search':
			case 'error':
				return 0;
			default:
				return 0;
		}
	}

	/**
	 * Get all available template condition types.
	 *
	 * @return array<string, mixed> Condition types.
	 */
	public function get_condition_types(): array {
		$types = [
			'any'             => [ 'label' => 'Entire website', 'score' => 2, 'extra_fields' => [] ],
			'frontpage'       => [ 'label' => 'Front page', 'score' => 9, 'extra_fields' => [] ],
			'ids'             => [ 'label' => 'Specific posts by ID', 'score' => 10, 'extra_fields' => [ 'ids' => 'array of post ID integers', 'includeChildren' => 'bool (optional, include child pages)' ] ],
			'postType'        => [ 'label' => 'All posts of post type', 'score' => 8, 'extra_fields' => [ 'postType' => 'post type slug (e.g., post, page, product)' ] ],
			'terms'           => [ 'label' => 'Specific taxonomy terms', 'score' => 8, 'extra_fields' => [ 'terms' => 'array of "taxonomy::term_id" strings' ] ],
			'archivePostType' => [ 'label' => 'Archive for post type', 'score' => 7, 'extra_fields' => [ 'archivePostType' => 'post type slug' ] ],
			'archiveType'     => [ 'label' => 'Archive by type', 'score' => 3, 'extra_fields' => [ 'archiveType' => 'author|date|tag|category' ] ],
			'search'          => [ 'label' => 'Search results page', 'score' => 8, 'extra_fields' => [] ],
			'error'           => [ 'label' => '404 error page', 'score' => 8, 'extra_fields' => [] ],
		];

		if ( class_exists( 'WooCommerce' ) ) {
			$types['wc_product']      = [ 'label' => 'WooCommerce single product', 'score' => 8, 'extra_fields' => [] ];
			$types['wc_archive']      = [ 'label' => 'WooCommerce product archive', 'score' => 7, 'extra_fields' => [] ];
			$types['wc_cart']         = [ 'label' => 'WooCommerce cart page', 'score' => 9, 'extra_fields' => [] ];
			$types['wc_cart_empty']   = [ 'label' => 'WooCommerce empty cart', 'score' => 9, 'extra_fields' => [] ];
			$types['wc_checkout']     = [ 'label' => 'WooCommerce checkout page', 'score' => 9, 'extra_fields' => [] ];
			$types['wc_account_form'] = [ 'label' => 'WooCommerce account login/register form', 'score' => 9, 'extra_fields' => [] ];
			$types['wc_account_page'] = [ 'label' => 'WooCommerce my account page', 'score' => 9, 'extra_fields' => [] ];
			$types['wc_thankyou']     = [ 'label' => 'WooCommerce thank you page', 'score' => 9, 'extra_fields' => [] ];
		}

		return $types;
	}

	/**
	 * Get all terms for a Bricks template taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<int, array{term_id: int, name: string, slug: string, count: int}>|\WP_Error Terms list or WP_Error.
	 */
	public function get_template_terms( string $taxonomy ): array|\WP_Error {
		if ( ! in_array( $taxonomy, [ 'template_tag', 'template_bundle' ], true ) ) {
			return new \WP_Error( 'invalid_taxonomy', sprintf( __( 'Invalid taxonomy "%s". Must be "template_tag" or "template_bundle".', 'bricks-mcp' ), $taxonomy ) );
		}

		$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			return [];
		}

		return array_map(
			static function ( \WP_Term $term ): array {
				return [ 'term_id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'count' => $term->count ];
			},
			$terms
		);
	}

	/**
	 * Create a new term in a Bricks template taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $name     Term name.
	 * @return array{term_id: int, name: string, slug: string}|\WP_Error Term data or WP_Error.
	 */
	public function create_template_term( string $taxonomy, string $name ): array|\WP_Error {
		if ( ! in_array( $taxonomy, [ 'template_tag', 'template_bundle' ], true ) ) {
			return new \WP_Error( 'invalid_taxonomy', sprintf( __( 'Invalid taxonomy "%s". Must be "template_tag" or "template_bundle".', 'bricks-mcp' ), $taxonomy ) );
		}

		$sanitized_name = sanitize_text_field( $name );
		$result         = wp_insert_term( $sanitized_name, $taxonomy );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [ 'term_id' => (int) $result['term_id'], 'name' => $sanitized_name, 'slug' => sanitize_title( $sanitized_name ) ];
	}

	/**
	 * Delete a term from a Bricks template taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $term_id  Term ID to delete.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function delete_template_term( string $taxonomy, int $term_id ): true|\WP_Error {
		if ( ! in_array( $taxonomy, [ 'template_tag', 'template_bundle' ], true ) ) {
			return new \WP_Error( 'invalid_taxonomy', sprintf( __( 'Invalid taxonomy "%s". Must be "template_tag" or "template_bundle".', 'bricks-mcp' ), $taxonomy ) );
		}

		$result = wp_delete_term( $term_id, $taxonomy );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			return new \WP_Error( 'term_not_found', sprintf( __( 'Term %d not found. Use template_taxonomy:list_tags or template_taxonomy:list_bundles to find valid term IDs.', 'bricks-mcp' ), $term_id ) );
		}

		if ( 0 === $result ) {
			return new \WP_Error( 'cannot_delete_default_term', sprintf( __( 'Cannot delete term %d because it is the default term for this taxonomy.', 'bricks-mcp' ), $term_id ) );
		}

		return true;
	}

	/**
	 * Export a single template as Bricks-compatible JSON.
	 *
	 * @param int  $template_id    Template post ID.
	 * @param bool $include_classes Whether to include referenced global classes.
	 * @return array<string, mixed>|\WP_Error Export data or error.
	 */
	public function export_template( int $template_id, bool $include_classes = false ): array|\WP_Error {
		$post = get_post( $template_id );
		if ( ! $post || 'bricks_template' !== $post->post_type ) {
			return new \WP_Error( 'template_not_found', sprintf( __( 'Bricks template %d not found. Verify the template_id is a valid bricks_template post.', 'bricks-mcp' ), $template_id ) );
		}

		$content = get_post_meta( $template_id, $this->core->resolve_elements_meta_key( $template_id ), true ) ?: array();

		$template_type_key = defined( 'BRICKS_DB_TEMPLATE_TYPE' ) ? BRICKS_DB_TEMPLATE_TYPE : '_bricks_template_type';
		$page_settings_key = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';

		$export = array(
			'title'            => get_the_title( $template_id ),
			'templateType'     => get_post_meta( $template_id, $template_type_key, true ) ?: '',
			'content'          => $content,
			'pageSettings'     => get_post_meta( $template_id, $page_settings_key, true ) ?: array(),
			'templateSettings' => get_post_meta( $template_id, '_bricks_template_settings', true ) ?: array(),
		);

		if ( $include_classes && is_array( $content ) && ! empty( $content ) ) {
			$referenced_ids = array();
			foreach ( $content as $element ) {
				if ( ! empty( $element['settings']['_cssGlobalClasses'] ) && is_array( $element['settings']['_cssGlobalClasses'] ) ) {
					$referenced_ids = array_merge( $referenced_ids, $element['settings']['_cssGlobalClasses'] );
				}
			}

			$referenced_ids = array_unique( $referenced_ids );
			if ( ! empty( $referenced_ids ) ) {
				$all_classes = get_option( 'bricks_global_classes', array() );
				$used_classes = array_filter( $all_classes, fn( $class ) => in_array( $class['id'] ?? '', $referenced_ids, true ) );
				$export['globalClasses'] = array_values( $used_classes );
			}
		}

		return $export;
	}

	/**
	 * Import a template from parsed JSON data.
	 *
	 * @param array<string, mixed> $data Template data.
	 * @return array<string, mixed>|\WP_Error Import result or error.
	 */
	public function import_template( array $data ): array|\WP_Error {
		if ( empty( $data['title'] ) || ! is_string( $data['title'] ) ) {
			return new \WP_Error( 'invalid_template', __( 'Template must have a non-empty title string.', 'bricks-mcp' ) );
		}

		if ( empty( $data['content'] ) || ! is_array( $data['content'] ) ) {
			return new \WP_Error( 'invalid_template', __( 'Template must have a non-empty content array of Bricks elements.', 'bricks-mcp' ) );
		}

		$template_id = wp_insert_post( array( 'post_title' => sanitize_text_field( $data['title'] ), 'post_type' => 'bricks_template', 'post_status' => 'publish' ) );

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		if ( 0 === $template_id ) {
			return new \WP_Error( 'insert_failed', __( 'Failed to create template post.', 'bricks-mcp' ) );
		}

		$content = $this->core->normalize_elements( $data['content'] );

		$template_type = sanitize_text_field( $data['templateType'] ?? 'section' );
		update_post_meta( $template_id, '_bricks_template_type', $template_type );

		$meta_key = $this->core->resolve_elements_meta_key( $template_id );
		$this->core->unhook_bricks_meta_filters( $meta_key );
		try {
			update_post_meta( $template_id, $meta_key, $content );
			update_post_meta( $template_id, BricksCore::EDITOR_MODE_KEY, 'bricks' );
		} finally {
			$this->core->rehook_bricks_meta_filters();
		}

		$page_settings_key = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
		$stripped_js_keys  = array();
		if ( ! empty( $data['pageSettings'] ) && is_array( $data['pageSettings'] ) ) {
			$js_gated_keys = array( 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter' );
			$page_settings = $data['pageSettings'];
			if ( ! $this->core->is_dangerous_actions_enabled() ) {
				$stripped_js_keys = array_values( array_intersect( array_keys( $page_settings ), $js_gated_keys ) );
				$page_settings    = array_diff_key( $page_settings, array_flip( $js_gated_keys ) );
			}
			update_post_meta( $template_id, $page_settings_key, $page_settings );
		}

		if ( ! empty( $data['templateSettings'] ) && is_array( $data['templateSettings'] ) ) {
			$allowed_template_keys = array( 'templateConditions', 'headerPosition', 'headerSticky', 'templateOrder', 'templateIncludeChildren' );
			$safe_settings = array_intersect_key( $data['templateSettings'], array_flip( $allowed_template_keys ) );
			if ( ! empty( $safe_settings ) ) {
				update_post_meta( $template_id, '_bricks_template_settings', $safe_settings );
			}
		}

		$class_summary = array();
		if ( ! empty( $data['globalClasses'] ) && is_array( $data['globalClasses'] ) ) {
			$class_summary = $this->global_class_service->merge_imported_global_classes( $data['globalClasses'] );
		}

		$result = array(
			'template_id'    => $template_id,
			'title'          => get_the_title( $template_id ),
			'template_type'  => $template_type,
			'elements_count' => count( $content ),
			'global_classes' => $class_summary,
		);

		if ( ! empty( $stripped_js_keys ) ) {
			$result['warnings'] = array( sprintf( 'Stripped JS-capable page settings keys (%s) because dangerous actions mode is disabled. Enable in Settings > Bricks MCP to allow.', implode( ', ', $stripped_js_keys ) ) );
		}

		return $result;
	}

	/**
	 * Fetch template JSON from a remote URL and import it.
	 *
	 * @param string $url Remote URL returning Bricks template JSON.
	 * @return array<string, mixed>|\WP_Error Import result or error.
	 */
	public function import_template_from_url( string $url ): array|\WP_Error {
		if ( ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'invalid_url', __( 'The provided URL is not valid.', 'bricks-mcp' ) );
		}

		$response = wp_safe_remote_get( $url, array( 'timeout' => 30, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new \WP_Error( 'fetch_failed', sprintf( __( 'Remote URL returned HTTP %d. Expected 200.', 'bricks-mcp' ), $status_code ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > 10485760 ) {
			return new \WP_Error( 'response_too_large', __( 'Remote response exceeds 10MB size limit.', 'bricks-mcp' ) );
		}

		$data = json_decode( $body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'invalid_json', __( 'Remote URL did not return valid JSON.', 'bricks-mcp' ) );
		}

		if ( ! empty( $data['global_classes'] ) && is_array( $data['global_classes'] ) ) {
			foreach ( $data['global_classes'] as &$gc ) {
				if ( isset( $gc['name'] ) ) {
					$gc['name'] = sanitize_text_field( $gc['name'] );
				}
				if ( isset( $gc['styles'] ) && is_array( $gc['styles'] ) ) {
					$gc['styles'] = $this->core->sanitize_styles_array( $gc['styles'] );
				}
			}
			unset( $gc );
		}

		return $this->import_template( $data );
	}
}
