<?php
/**
 * Page operations sub-service.
 *
 * Handles page CRUD, duplication, snapshots, metadata, and tree summaries.
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
 * PageOperationsService class.
 */
class PageOperationsService {

	/**
	 * Maximum tree depth traversed when rendering page summaries / contexts.
	 * Guards against stack overflow on circular references; the Bricks editor
	 * does not enforce this, so the cap is intentionally generous.
	 *
	 * @var int
	 */
	private const MAX_TREE_DEPTH = 50;

	/**
	 * Maximum characters kept from a text field when rendering get_page_context.
	 * Longer values are truncated with an ellipsis so the AI payload stays small.
	 *
	 * @var int
	 */
	private const CONTEXT_EXCERPT_CHARS = 120;

	/**
	 * Maximum number of auto-pruned snapshots retained per page.
	 * Oldest snapshots are removed once this cap is exceeded.
	 *
	 * @var int
	 */
	private const MAX_SNAPSHOTS = 10;

	/**
	 * Core infrastructure.
	 *
	 * @var BricksCore
	 */
	private BricksCore $core;

	/**
	 * Constructor.
	 *
	 * @param BricksCore $core Shared infrastructure.
	 */
	public function __construct( BricksCore $core ) {
		$this->core = $core;
	}

	/**
	 * Create a new post/page with Bricks editor enabled.
	 *
	 * Inserts the post, enables Bricks editor, optionally saves elements.
	 * Title is sanitized. Elements are normalized and validated before save.
	 *
	 * @param array<string, mixed> $args {
	 *     Page creation arguments.
	 *     @type string $title     Post title (required).
	 *     @type string $post_type Post type, default 'page'.
	 *     @type string $status    Post status, default 'draft'.
	 *     @type array  $elements  Optional initial elements (native or simplified format).
	 * }
	 * @return int|\WP_Error New post ID on success, WP_Error on failure.
	 */
	public function create_page( array $args ): int|\WP_Error {
		if ( empty( $args['title'] ) ) {
			return new \WP_Error(
				'missing_title',
				__( 'Post title is required. Provide a non-empty "title" parameter.', 'bricks-mcp' )
			);
		}

		$post_data = [
			'post_title'   => sanitize_text_field( $args['title'] ),
			'post_type'    => sanitize_key( $args['post_type'] ?? 'page' ),
			'post_status'  => sanitize_key( $args['status'] ?? 'draft' ),
			'post_content' => '',
		];

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Always enable the Bricks editor.
		$this->core->enable_bricks_editor( $post_id );

		// Save elements if provided.
		if ( ! empty( $args['elements'] ) && is_array( $args['elements'] ) ) {
			$elements = $this->core->get_normalizer()->normalize( $args['elements'] );
			$saved    = $this->core->save_elements( $post_id, $elements );

			if ( is_wp_error( $saved ) ) {
				// Clean up the post we just created.
				wp_delete_post( $post_id, true );
				return $saved;
			}
		}

		return $post_id;
	}

	/**
	 * Update WordPress post metadata (title, status, slug, featured image).
	 *
	 * Only updates fields present in $args. Does not touch Bricks content.
	 *
	 * @param int                  $post_id Post ID to update.
	 * @param array<string, mixed> $args    Fields to update: title, status, slug, featured_image.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function update_page_meta( int $post_id, array $args ): true|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id )
			);
		}

		$post_data = [ 'ID' => $post_id ];

		if ( isset( $args['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $args['title'] );
		}

		if ( isset( $args['status'] ) ) {
			$post_data['post_status'] = sanitize_key( $args['status'] );
		}

		if ( isset( $args['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $args['slug'] );
		}

		// Only call wp_update_post if there is something to update beyond the ID.
		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( isset( $args['featured_image'] ) ) {
			$attachment_id = (int) $args['featured_image'];
			if ( $attachment_id > 0 ) {
				set_post_thumbnail( $post_id, $attachment_id );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		return true;
	}

	/**
	 * Move a post to trash.
	 *
	 * Does not permanently delete — post can be recovered from WordPress trash.
	 *
	 * @param int $post_id Post ID to trash.
	 * @return true|\WP_Error True on success, WP_Error if post not found.
	 */
	public function delete_page( int $post_id ): true|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. The post may have already been deleted or the ID is incorrect.', 'bricks-mcp' ), $post_id )
			);
		}

		$trashed = wp_trash_post( $post_id );
		if ( ! $trashed ) {
			return new \WP_Error(
				'trash_failed',
				/* translators: %d: Post ID */
				sprintf( __( 'Failed to trash post %d. Check WordPress error logs for details.', 'bricks-mcp' ), $post_id )
			);
		}

		return true;
	}

	/**
	 * Duplicate a post including all Bricks content and meta.
	 *
	 * Creates a deep copy of the post. New post is always created as 'draft'
	 * with ' (Copy)' appended to the title. Copies ALL post meta including
	 * Bricks content and editor mode keys.
	 *
	 * @param int $post_id Post ID to duplicate.
	 * @return int|\WP_Error New post ID on success, WP_Error if original not found.
	 */
	public function duplicate_page( int $post_id ): int|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id )
			);
		}

		// Create new post with same data but draft status.
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

		// Copy post meta using a denylist to skip transient/lock keys.
		$denied_meta_keys = [
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
		];

		$all_meta = get_post_meta( $post_id );
		if ( is_array( $all_meta ) ) {
			foreach ( $all_meta as $meta_key => $meta_values ) {
				if ( in_array( $meta_key, $denied_meta_keys, true ) ) {
					continue;
				}

				foreach ( $meta_values as $meta_value ) {
					$unserialized = maybe_unserialize( $meta_value );
					add_post_meta( $new_post_id, $meta_key, $unserialized );
				}
			}
		}

		return $new_post_id;
	}

	/**
	 * Create a snapshot of the current page state.
	 *
	 * Stores elements as post meta. Max 10 snapshots per page (auto-prunes oldest).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $label   Optional human-readable label.
	 * @return array|\WP_Error Snapshot info or error.
	 */
	public function snapshot_page( int $post_id, string $label = '' ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', sprintf( __( 'Post %d not found.', 'bricks-mcp' ), $post_id ) );
		}

		$elements      = $this->core->get_elements( $post_id );
		$snapshot_id   = 'snap_' . bin2hex( random_bytes( 6 ) );
		$element_count = count( $elements );

		// Store snapshot data.
		$snapshot_data = [
			'elements'      => $elements,
			'element_count' => $element_count,
			'label'         => $label,
			'created'       => gmdate( 'Y-m-d H:i:s' ),
		];

		update_post_meta( $post_id, '_bricks_mcp_snapshot_' . $snapshot_id, $snapshot_data );

		// Update snapshot index.
		$index = get_post_meta( $post_id, '_bricks_mcp_snapshots', true );
		if ( ! is_array( $index ) ) {
			$index = [];
		}

		$index[] = [
			'id'            => $snapshot_id,
			'label'         => $label,
			'created'       => $snapshot_data['created'],
			'element_count' => $element_count,
		];

		// Auto-prune: keep max MAX_SNAPSHOTS snapshots (remove oldest).
		while ( count( $index ) > self::MAX_SNAPSHOTS ) {
			$oldest = array_shift( $index );
			delete_post_meta( $post_id, '_bricks_mcp_snapshot_' . $oldest['id'] );
		}

		update_post_meta( $post_id, '_bricks_mcp_snapshots', $index );

		return [
			'snapshot_id'     => $snapshot_id,
			'label'           => $label,
			'element_count'   => $element_count,
			'post_id'         => $post_id,
			'total_snapshots' => count( $index ),
		];
	}

	/**
	 * Restore a page from a snapshot.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $snapshot_id Snapshot ID.
	 * @return array|\WP_Error Result or error.
	 */
	public function restore_snapshot( int $post_id, string $snapshot_id ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', sprintf( __( 'Post %d not found.', 'bricks-mcp' ), $post_id ) );
		}

		$snapshot_data = get_post_meta( $post_id, '_bricks_mcp_snapshot_' . $snapshot_id, true );

		if ( empty( $snapshot_data ) || ! is_array( $snapshot_data ) || ! isset( $snapshot_data['elements'] ) ) {
			return new \WP_Error(
				'snapshot_not_found',
				sprintf( __( 'Snapshot "%s" not found for post %d. Use page:list_snapshots to find valid snapshot IDs.', 'bricks-mcp' ), $snapshot_id, $post_id )
			);
		}

		$elements = $snapshot_data['elements'];
		$saved    = $this->core->save_elements( $post_id, $elements );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [
			'snapshot_id'   => $snapshot_id,
			'label'         => $snapshot_data['label'] ?? '',
			'restored_from' => $snapshot_data['created'] ?? '',
			'element_count' => count( $elements ),
			'post_id'       => $post_id,
		];
	}

	/**
	 * List snapshots for a page.
	 *
	 * @param int $post_id Post ID.
	 * @return array|\WP_Error Snapshots list or error.
	 */
	public function list_snapshots( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', sprintf( __( 'Post %d not found.', 'bricks-mcp' ), $post_id ) );
		}

		$index = get_post_meta( $post_id, '_bricks_mcp_snapshots', true );
		if ( ! is_array( $index ) ) {
			$index = [];
		}

		return [
			'post_id'   => $post_id,
			'snapshots' => $index,
			'count'     => count( $index ),
		];
	}

	/**
	 * Get standard metadata for a post.
	 *
	 * Returns title, status, slug, author, dates, featured image, and template.
	 *
	 * @param int $post_id The post ID.
	 * @return array<string, mixed> Post metadata.
	 */
	public function get_page_metadata( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return [];
		}

		return [
			'id'             => $post->ID,
			'title'          => $post->post_title,
			'status'         => $post->post_status,
			'slug'           => $post->post_name,
			'type'           => $post->post_type,
			'permalink'      => get_permalink( $post->ID ),
			'author'         => [
				'id'   => (int) $post->post_author,
				'name' => get_the_author_meta( 'display_name', (int) $post->post_author ),
			],
			'dates'          => [
				'created'  => $post->post_date,
				'modified' => $post->post_modified,
			],
			'featured_image' => get_the_post_thumbnail_url( $post->ID, 'full' ) ? get_the_post_thumbnail_url( $post->ID, 'full' ) : null,
			'template'       => get_page_template_slug( $post->ID ) ? get_page_template_slug( $post->ID ) : null,
		];
	}

	/**
	 * Get page summary with element type counts and tree structure.
	 *
	 * @param int $post_id The post ID.
	 * @return array<string, mixed> Page summary.
	 */
	public function get_page_summary( int $post_id ): array {
		$elements = $this->core->get_elements( $post_id );

		if ( empty( $elements ) ) {
			return [
				'type_counts' => [],
				'tree'        => [],
				'total'       => 0,
			];
		}

		// Count element types.
		$type_counts = [];
		$id_map      = [];

		foreach ( $elements as $index => $element ) {
			$name = $element['name'] ?? 'unknown';

			if ( ! isset( $type_counts[ $name ] ) ) {
				$type_counts[ $name ] = 0;
			}
			++$type_counts[ $name ];

			$id_map[ $element['id'] ] = $index;
		}

		// Build tree from root elements (parent === 0).
		$tree = [];
		foreach ( $elements as $element ) {
			if ( 0 === $element['parent'] ) {
				$tree[] = $this->build_tree_node( $element, $elements, $id_map, 0 );
			}
		}

		return [
			'type_counts' => $type_counts,
			'tree'        => $tree,
			'total'       => count( $elements ),
		];
	}

	/**
	 * Build a tree node for the page summary.
	 *
	 * @param array<string, mixed>             $element  The current element.
	 * @param array<int, array<string, mixed>> $elements All elements.
	 * @param array<string, int>               $id_map   Map of element ID to array index.
	 * @param int                              $depth    Current depth in the tree.
	 * @return array<string, mixed> Tree node with children.
	 */
	private function build_tree_node( array $element, array $elements, array $id_map, int $depth ): array {
		$node = [
			'id'    => $element['id'],
			'name'  => $element['name'] ?? 'unknown',
			'depth' => $depth,
		];

		// Prevent stack overflow on circular references.
		if ( $depth > self::MAX_TREE_DEPTH ) {
			return $node;
		}

		if ( ! empty( $element['children'] ) ) {
			$node['children'] = [];
			foreach ( $element['children'] as $child_id ) {
				if ( isset( $id_map[ $child_id ] ) ) {
					$node['children'][] = $this->build_tree_node( $elements[ $id_map[ $child_id ] ], $elements, $id_map, $depth + 1 );
				}
			}
		}

		return $node;
	}

	/**
	 * Get page context: tree with content text but no style settings.
	 *
	 * Middle ground between summary (no content) and detail (everything).
	 * Includes: id, name, label, tag, text content, _cssGlobalClasses.
	 * Excludes: all style settings (_padding, _margin, _typography, _background, etc.)
	 *
	 * @param int $post_id The post ID.
	 * @return array<string, mixed> Page context.
	 */
	public function get_page_context( int $post_id ): array {
		$elements = $this->core->get_elements( $post_id );

		if ( empty( $elements ) ) {
			return [
				'type_counts' => [],
				'tree'        => [],
				'total'       => 0,
			];
		}

		$content_keys = [ 'text', 'content', 'html', 'label', 'caption', 'description', 'title', 'buttonText', 'prefix', 'suffix' ];
		$type_counts  = [];
		$id_map       = [];

		foreach ( $elements as $index => $element ) {
			$name                = $element['name'] ?? 'unknown';
			$type_counts[ $name ] = ( $type_counts[ $name ] ?? 0 ) + 1;
			$id_map[ $element['id'] ] = $index;
		}

		$tree = [];
		foreach ( $elements as $element ) {
			if ( 0 === $element['parent'] ) {
				$tree[] = $this->build_context_node( $element, $elements, $id_map, $content_keys, 0 );
			}
		}

		return [
			'type_counts' => $type_counts,
			'tree'        => $tree,
			'total'       => count( $elements ),
		];
	}

	/**
	 * Build a context tree node with content but no style settings.
	 *
	 * @param array<string, mixed>             $element      The current element.
	 * @param array<int, array<string, mixed>> $elements     All elements.
	 * @param array<string, int>               $id_map       Map of element ID to array index.
	 * @param array<int, string>               $content_keys Keys to extract as text content.
	 * @param int                              $depth        Current depth in the tree.
	 * @return array<string, mixed> Context tree node.
	 */
	private function build_context_node( array $element, array $elements, array $id_map, array $content_keys, int $depth ): array {
		$settings = $element['settings'] ?? [];
		$node     = [
			'id'   => $element['id'],
			'name' => $element['name'] ?? 'unknown',
		];

		if ( ! empty( $element['label'] ) ) {
			$node['label'] = $element['label'];
		}
		if ( ! empty( $settings['tag'] ) ) {
			$node['tag'] = $settings['tag'];
		}

		foreach ( $content_keys as $key ) {
			if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
				$text = wp_strip_all_tags( $settings[ $key ] );
				if ( strlen( $text ) > self::CONTEXT_EXCERPT_CHARS ) {
					$text = mb_substr( $text, 0, self::CONTEXT_EXCERPT_CHARS ) . '...';
				}
				$node['text'] = $text;
				break;
			}
		}

		if ( ! empty( $settings['_cssGlobalClasses'] ) ) {
			$node['classes'] = $settings['_cssGlobalClasses'];
		}

		// Prevent stack overflow on circular references.
		if ( $depth > self::MAX_TREE_DEPTH ) {
			return $node;
		}

		if ( ! empty( $element['children'] ) ) {
			$node['children'] = [];
			foreach ( $element['children'] as $child_id ) {
				if ( isset( $id_map[ $child_id ] ) ) {
					$node['children'][] = $this->build_context_node( $elements[ $id_map[ $child_id ] ], $elements, $id_map, $content_keys, $depth + 1 );
				}
			}
		}

		return $node;
	}

	/**
	 * Check if a page is protected from AI modifications.
	 *
	 * @param int $post_id Post ID to check.
	 * @return \WP_Error|null WP_Error if protected, null if allowed.
	 */
	public function check_protected_page( int $post_id ): ?\WP_Error {
		$settings      = get_option( BricksCore::OPTION_SETTINGS, [] );
		$protected_raw = $settings['protected_pages'] ?? '';

		if ( empty( $protected_raw ) ) {
			return null;
		}

		// Split, trim, cast to int, then drop zeros so a stray non-numeric entry
		// cannot match post_id 0 (which would never exist, but keeps the list clean).
		$protected_ids = array_values( array_filter(
			array_map( 'intval', array_map( 'trim', explode( ',', $protected_raw ) ) ),
			static fn( int $id ) => $id > 0
		) );

		if ( in_array( $post_id, $protected_ids, true ) ) {
			$post  = get_post( $post_id );
			$title = $post ? $post->post_title : "ID $post_id";
			return new \WP_Error(
				'bricks_mcp_page_protected',
				sprintf(
					__( "Page '%s' (ID: %d) is protected. Remove it from protected pages in Settings > Bricks MCP to allow modifications.", 'bricks-mcp' ),
					$title,
					$post_id
				)
			);
		}

		return null;
	}

	/**
	 * Describe a page: human-readable section-by-section descriptions.
	 *
	 * Gives AI assistants "eyes" — they can understand what a page looks like
	 * without screenshots by getting a structured description of each section.
	 *
	 * @param int $post_id The post ID.
	 * @return array<string, mixed>|\WP_Error Page description with section details.
	 */
	public function describe_page( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found. Use page:list to find valid post IDs.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		$elements = $this->core->get_elements( $post_id );

		if ( ! is_array( $elements ) ) {
			$elements = [];
		}

		// Load global classes for reference.
		$global_classes = get_option( BricksCore::OPTION_GLOBAL_CLASSES, [] );
		$class_map      = [];

		if ( is_array( $global_classes ) ) {
			foreach ( $global_classes as $gc ) {
				$class_map[ $gc['id'] ] = $gc;
			}
		}

		// Build element lookup by ID.
		$by_id = [];
		foreach ( $elements as $el ) {
			$by_id[ $el['id'] ] = $el;
		}

		// Find top-level sections (parent === 0 and name === 'section').
		$sections = [];
		foreach ( $elements as $el ) {
			if ( 0 === ( $el['parent'] ?? 0 ) && 'section' === ( $el['name'] ?? '' ) ) {
				$sections[] = $this->describe_section( $el, $by_id, $class_map );
			}
		}

		$section_count = count( $sections );

		return [
			'metadata'         => $this->get_page_metadata( $post_id ),
			'page_description' => sprintf(
				'%s — %d section%s',
				$post->post_title,
				$section_count,
				1 === $section_count ? '' : 's'
			),
			'sections'         => $sections,
			'total_elements'   => count( $elements ),
		];
	}

	/**
	 * Describe a single section with human-readable details.
	 *
	 * @param array<string, mixed> $section   The section element.
	 * @param array<string, mixed> $by_id     All elements keyed by ID.
	 * @param array<string, mixed> $class_map Global classes keyed by ID.
	 * @return array<string, mixed> Section description.
	 */
	private function describe_section( array $section, array $by_id, array $class_map ): array {
		// Count all descendants.
		$descendants   = $this->collect_descendants( $section['id'], $by_id );
		$element_count = count( $descendants ) + 1; // +1 for section itself.

		// Collect all class names used in this section tree.
		$class_ids    = [];
		$all_elements = array_merge( [ $section ], array_map( static fn( $id ) => $by_id[ $id ] ?? [], $descendants ) );

		foreach ( $all_elements as $el ) {
			foreach ( ( $el['settings']['_cssGlobalClasses'] ?? [] ) as $cid ) {
				$class_ids[] = $cid;
			}
		}

		$class_names = [];
		foreach ( array_unique( $class_ids ) as $cid ) {
			if ( isset( $class_map[ $cid ] ) ) {
				$class_names[] = $class_map[ $cid ]['name'];
			}
		}

		// Detect background and layout.
		$bg     = $this->detect_background( $section, $class_map );
		$layout = $this->detect_layout( $section, $by_id, $class_map );

		// Categorize content elements.
		$content_types = [];
		foreach ( $all_elements as $el ) {
			$name = $el['name'] ?? '';
			if ( in_array( $name, [ 'heading', 'text-basic', 'text', 'text-link', 'button', 'image', 'video', 'icon', 'form', 'accordion', 'tabs', 'map', 'map-leaflet' ], true ) ) {
				$tag = $el['settings']['tag'] ?? '';
				if ( 'heading' === $name && $tag ) {
					$content_types[] = $tag;
				} else {
					$content_types[] = $name;
				}
			}
		}

		// Build description.
		$desc_parts   = [];
		$desc_parts[] = ucfirst( $bg ) . ' section';

		// Check for background image.
		$has_bg_image = ! empty( $section['settings']['_background']['image'] );
		$has_overlay  = ! empty( $section['settings']['_gradient'] );

		if ( $has_bg_image && $has_overlay ) {
			$desc_parts[0] .= ' with background image and overlay';
		} elseif ( $has_bg_image ) {
			$desc_parts[0] .= ' with background image';
		}

		// Describe content.
		$type_counts   = array_count_values( $content_types );
		$content_parts = [];
		foreach ( $type_counts as $type => $count ) {
			if ( $count > 1 ) {
				$content_parts[] = "{$count} {$type}s";
			} else {
				$content_parts[] = $type;
			}
		}

		if ( ! empty( $content_parts ) ) {
			$desc_parts[] = 'Contains ' . implode( ', ', $content_parts );
		}

		// Check for grid layout.
		if ( str_contains( $layout, 'grid' ) ) {
			$desc_parts[] = ucfirst( $layout );
		}

		$label = $section['settings']['label'] ?? $section['label'] ?? 'Section';

		// Extract section-level style values that AI callers commonly need to verify:
		// min_height (hero ~80vh, full viewport, etc.) + raw background color when set inline.
		$min_height = $section['settings']['_minHeight'] ?? '';
		$bg_raw     = $section['settings']['_background']['color']['raw'] ?? '';

		$styles = [];
		if ( '' !== $min_height && null !== $min_height ) {
			$styles['min_height'] = is_scalar( $min_height ) ? (string) $min_height : '';
		}
		if ( '' !== $bg_raw ) {
			$styles['background_color'] = (string) $bg_raw;
		}

		$result = [
			'label'         => $label,
			'description'   => implode( '. ', $desc_parts ) . '.',
			'element_count' => $element_count,
			'classes_used'  => $class_names,
			'layout'        => $layout,
			'background'    => $bg,
		];

		if ( ! empty( $styles ) ) {
			$result['styles'] = $styles;
		}

		return $result;
	}

	/**
	 * Collect all descendant element IDs for a given parent.
	 *
	 * @param string               $element_id The parent element ID.
	 * @param array<string, mixed> $by_id      All elements keyed by ID.
	 * @return array<int, string> List of descendant element IDs.
	 */
	private function collect_descendants( string $element_id, array $by_id ): array {
		$result = [];
		$queue  = $by_id[ $element_id ]['children'] ?? [];

		while ( ! empty( $queue ) ) {
			$child_id = array_shift( $queue );
			$result[] = $child_id;
			$grandchildren = $by_id[ $child_id ]['children'] ?? [];
			foreach ( $grandchildren as $gc ) {
				$queue[] = $gc;
			}
		}

		return $result;
	}

	/**
	 * Detect the background style of an element (dark or light).
	 *
	 * Checks inline background color and global class settings for dark indicators.
	 *
	 * @param array<string, mixed> $element   The element to check.
	 * @param array<string, mixed> $class_map Global classes keyed by ID.
	 * @return string 'dark' or 'light'.
	 */
	private function detect_background( array $element, array $class_map ): string {
		// Check inline background color.
		$bg_color = $element['settings']['_background']['color']['raw'] ?? '';

		if ( $bg_color && self::is_dark_color_hint( $bg_color ) ) {
			return 'dark';
		}

		// Check classes for background hints.
		foreach ( ( $element['settings']['_cssGlobalClasses'] ?? [] ) as $cid ) {
			$class = $class_map[ $cid ] ?? null;

			if ( ! $class ) {
				continue;
			}

			$class_bg = $class['settings']['_background']['color']['raw'] ?? '';

			if ( $class_bg && self::is_dark_color_hint( $class_bg ) ) {
				return 'dark';
			}

			// Check class name for dark-related patterns.
			if ( preg_match( '/(?:^|-)dark(?:-|$)/', $class['name'] ?? '' ) ) {
				return 'dark';
			}
		}

		return 'light';
	}

	/**
	 * Check if a CSS color value hints at a dark background.
	 *
	 * Matches variable names ending in dark segments (e.g., --base-dark, --ultra-dark)
	 * and high-weight color tokens (800, 900, 950). Avoids false positives from
	 * names like --sidebar-dark-border by requiring segment boundaries.
	 */
	private static function is_dark_color_hint( string $value ): bool {
		// Match var names with dark as a standalone segment: --base-dark, --ultra-dark, --dark.
		if ( preg_match( '/(?:^|-)(?:ultra-)?dark(?:-|$|\))/', $value ) ) {
			return true;
		}
		// Match high-weight color tokens (800, 900, 950) as standalone segments.
		if ( preg_match( '/(?:^|-)(?:800|900|950)(?:-|$|\))/', $value ) ) {
			return true;
		}
		// Overlay is always dark.
		if ( str_contains( $value, 'overlay' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Detect the layout pattern of a section.
	 *
	 * Looks at direct children and grandchildren for grid/layout patterns
	 * in both global classes and inline settings.
	 *
	 * @param array<string, mixed> $section   The section element.
	 * @param array<string, mixed> $by_id     All elements keyed by ID.
	 * @param array<string, mixed> $class_map Global classes keyed by ID.
	 * @return string Layout description (e.g., 'stacked', 'grid', '3-column grid').
	 */
	private function detect_layout( array $section, array $by_id, array $class_map ): string {
		// Look at direct children and grandchildren for grid/layout patterns.
		$children = $section['children'] ?? [];

		foreach ( $children as $child_id ) {
			$child         = $by_id[ $child_id ] ?? [];
			$child_classes = $child['settings']['_cssGlobalClasses'] ?? [];

			foreach ( $child_classes as $cid ) {
				$class = $class_map[ $cid ] ?? null;

				if ( ! $class ) {
					continue;
				}

				$name = $class['name'];

				if ( str_contains( $name, 'grid' ) ) {
					// Try to detect column count from class settings.
					$cols = $class['settings']['_gridTemplateColumns'] ?? '';

					if ( str_contains( $cols, 'grid-4' ) ) {
						return '4-column grid';
					}
					if ( str_contains( $cols, 'grid-3' ) ) {
						return '3-column grid';
					}
					if ( str_contains( $cols, 'grid-2' ) ) {
						return '2-column grid';
					}

					return 'grid';
				}
			}

			// Check inline grid.
			if ( ! empty( $child['settings']['_display'] ) && 'grid' === $child['settings']['_display'] ) {
				$cols = $child['settings']['_gridTemplateColumns'] ?? '';

				if ( str_contains( $cols, '1fr 1fr 1fr 1fr' ) ) {
					return '4-column grid';
				}
				if ( str_contains( $cols, '1fr 1fr 1fr' ) ) {
					return '3-column grid';
				}
				if ( str_contains( $cols, '1fr 1fr' ) ) {
					return '2-column grid';
				}

				return 'grid';
			}

			// Check grandchildren for grids.
			foreach ( ( $child['children'] ?? [] ) as $gc_id ) {
				$gc = $by_id[ $gc_id ] ?? [];

				foreach ( ( $gc['settings']['_cssGlobalClasses'] ?? [] ) as $gcid ) {
					$gc_class = $class_map[ $gcid ] ?? null;

					if ( $gc_class && str_contains( $gc_class['name'], 'grid' ) ) {
						$cols = $gc_class['settings']['_gridTemplateColumns'] ?? '';

						if ( str_contains( $cols, 'grid-4' ) ) {
							return '4-column grid';
						}
						if ( str_contains( $cols, 'grid-3' ) ) {
							return '3-column grid';
						}
						if ( str_contains( $cols, 'grid-2' ) ) {
							return '2-column grid';
						}

						return 'grid';
					}
				}
			}
		}

		return 'stacked';
	}
}
