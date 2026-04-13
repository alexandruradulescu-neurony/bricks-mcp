<?php
/**
 * Page read sub-handler: list, search, get actions.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers\Page;

use BricksMCP\MCP\Services\BricksService;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles page read actions (list, search, get).
 */
final class PageReadSubHandler {

	/**
	 * Bricks service instance.
	 *
	 * @var BricksService
	 */
	private BricksService $bricks_service;

	/**
	 * Bricks check callback.
	 *
	 * @var callable
	 */
	private $require_bricks;

	/**
	 * Constructor.
	 *
	 * @param BricksService $bricks_service Bricks service instance.
	 * @param callable      $require_bricks Callback that returns \WP_Error|null.
	 */
	public function __construct( BricksService $bricks_service, callable $require_bricks ) {
		$this->bricks_service = $bricks_service;
		$this->require_bricks = $require_bricks;
	}

	/**
	 * List pages/posts with optional Bricks filter.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int, array<string, mixed>>|\WP_Error Posts list or error.
	 */
	public function list_pages( array $args ): array|\WP_Error {
		$post_type   = $args['post_type'] ?? 'page';
		$status      = $args['status'] ?? 'any';
		$per_page    = min( (int) ( $args['per_page'] ?? 20 ), 100 );
		$page        = (int) ( $args['page'] ?? 1 );
		$bricks_only = isset( $args['bricks_only'] ) ? (bool) $args['bricks_only'] : true;

		$query_args = array(
			'post_type'      => sanitize_text_field( $post_type ),
			'post_status'    => sanitize_text_field( $status ),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'no_found_rows'  => false,
		);

		if ( $bricks_only ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => BricksService::EDITOR_MODE_KEY,
					'value' => 'bricks',
				),
			);
		}

		$query      = new \WP_Query( $query_args );
		$result     = array();

		// Prime user cache to avoid N+1 queries for get_the_author_meta().
		$author_ids = array_unique( array_map( fn( $p ) => (int) $p->post_author, $query->posts ) );
		cache_users( $author_ids );

		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$result[] = $this->format_post_for_list( $post );
			}
		}

		return $result;
	}

	/**
	 * Search Bricks pages by title or content.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int, array<string, mixed>>|\WP_Error Search results or error.
	 */
	public function search( array $args ): array|\WP_Error {
		if ( empty( $args['query'] ) ) {
			return new \WP_Error( 'missing_query', __( 'query parameter is required.', 'bricks-mcp' ) );
		}

		$search_query = sanitize_text_field( $args['query'] );
		$post_type    = $args['post_type'] ?? 'page';
		$per_page     = min( (int) ( $args['per_page'] ?? 20 ), 100 );

		$query_args = array(
			'post_type'      => sanitize_key( $post_type ),
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			's'              => $search_query,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => BricksService::EDITOR_MODE_KEY,
					'value' => 'bricks',
				),
			),
		);

		$query      = new \WP_Query( $query_args );
		$result     = array();

		// Prime user cache to avoid N+1 queries for get_the_author_meta().
		$author_ids = array_unique( array_map( fn( $p ) => (int) $p->post_author, $query->posts ) );
		cache_users( $author_ids );

		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$result[] = $this->format_post_for_list( $post );
			}
		}

		return $result;
	}

	/**
	 * Get Bricks content for a post.
	 *
	 * Returns element JSON in native flat array format with page metadata.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Content data or error.
	 */
	public function get( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		$post_id = (int) $args['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Use page:list to find valid post IDs.', 'bricks-mcp' ), $post_id )
			);
		}

		if ( ! $this->bricks_service->is_bricks_page( $post_id ) ) {
			return new \WP_Error(
				'not_bricks_page',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d is not using the Bricks editor. Use the enable_bricks tool to enable Bricks on this post first.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		$view     = $args['view'] ?? 'detail';
		$metadata = $this->bricks_service->get_page_metadata( $post_id );

		if ( 'summary' === $view ) {
			return array(
				'metadata' => $metadata,
				'summary'  => $this->bricks_service->get_page_summary( $post_id ),
			);
		}

		if ( 'context' === $view ) {
			return array(
				'metadata' => $metadata,
				'context'  => $this->bricks_service->get_page_context( $post_id ),
			);
		}

		if ( 'describe' === $view ) {
			return $this->bricks_service->describe_page( $post_id );
		}

		$elements = $this->bricks_service->get_elements( $post_id );

		// Subtree filter: return only root element and all descendants.
		if ( ! empty( $args['root_element_id'] ) ) {
			$root_id = sanitize_text_field( $args['root_element_id'] );
			$subtree = $this->collect_subtree( $elements, $root_id );
			if ( empty( $subtree ) ) {
				return new \WP_Error(
					'element_not_found',
					sprintf(
						/* translators: %1$s: Element ID, %2$d: Post ID */
						__( 'Element "%1$s" not found on post %2$d. Use page:get with view=summary to find valid element IDs.', 'bricks-mcp' ),
						$root_id,
						$post_id
					)
				);
			}
			$elements = $subtree;
		}

		$total    = count( $elements );

		// Filter by element IDs if specified.
		if ( ! empty( $args['element_ids'] ) && is_array( $args['element_ids'] ) ) {
			$ids      = array_map( 'strval', $args['element_ids'] );
			$elements = array_values(
				array_filter( $elements, static fn( array $el ) => in_array( $el['id'] ?? '', $ids, true ) )
			);
		}

		// Pagination: offset + limit.
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : null;

		if ( $offset > 0 || null !== $limit ) {
			$elements = array_values( array_slice( $elements, $offset, $limit ) );
		}

		// Compact mode: strip empty/null/default values (default on).
		$compact = $args['compact'] ?? true;
		if ( $compact ) {
			$elements = array_map( [ $this, 'compact_element' ], $elements );
		}

		$result = array(
			'metadata' => $metadata,
			'elements' => $elements,
			'total'    => $total,
		);

		// Add pagination hints when paginated.
		if ( $offset > 0 || null !== $limit ) {
			$result['offset'] = $offset;
			$result['limit']  = $limit;
			$returned         = count( $elements );
			$result['has_more'] = ( $offset + $returned ) < $total;
		}

		return $result;
	}

	/**
	 * Describe a single section with rich human-readable details.
	 *
	 * Walks the section subtree and produces a prose description plus a
	 * structured element breakdown with key style facts per element.
	 *
	 * @param array<string, mixed> $args Tool arguments (post_id, section_id).
	 * @return array<string, mixed>|\WP_Error Section description or error.
	 */
	public function describe_section( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}
		if ( empty( $args['section_id'] ) ) {
			return new \WP_Error( 'missing_section_id', __( 'section_id is required. Use page:get with view=summary to find section element IDs.', 'bricks-mcp' ) );
		}

		$post_id    = (int) $args['post_id'];
		$section_id = sanitize_text_field( $args['section_id'] );

		if ( ! $this->bricks_service->is_bricks_page( $post_id ) ) {
			return new \WP_Error(
				'not_bricks_page',
				sprintf( __( 'Post %d is not using the Bricks editor.', 'bricks-mcp' ), $post_id )
			);
		}

		$elements = $this->bricks_service->get_elements( $post_id );
		if ( ! is_array( $elements ) ) {
			$elements = [];
		}

		// Index by ID.
		$by_id = [];
		foreach ( $elements as $el ) {
			$by_id[ $el['id'] ?? '' ] = $el;
		}

		if ( ! isset( $by_id[ $section_id ] ) ) {
			return new \WP_Error(
				'element_not_found',
				sprintf( __( 'Element "%1$s" not found on post %2$d.', 'bricks-mcp' ), $section_id, $post_id )
			);
		}

		$section = $by_id[ $section_id ];

		// Load global classes for context.
		$global_classes = get_option( 'bricks_global_classes', [] );
		$class_map      = [];
		if ( is_array( $global_classes ) ) {
			foreach ( $global_classes as $gc ) {
				if ( isset( $gc['id'], $gc['name'] ) ) {
					$class_map[ $gc['id'] ] = $gc;
				}
			}
		}

		// Collect subtree elements (flat).
		$subtree = $this->collect_subtree( $elements, $section_id );

		// Build element breakdown.
		$element_breakdown = [];
		$description_parts = [];

		// Section-level summary.
		$bg_summary        = $this->summarize_background( $section, $class_map );
		$container_summary = '';
		$label             = $section['settings']['label'] ?? $section['label'] ?? 'Section';

		// Find first container child for container summary.
		foreach ( $section['children'] ?? [] as $child_id ) {
			$child = $by_id[ $child_id ] ?? [];
			if ( 'container' === ( $child['name'] ?? '' ) ) {
				$container_summary = $this->summarize_container( $child, $class_map );
				break;
			}
		}

		$description_parts[] = ucfirst( $bg_summary ) . ' section.';
		if ( $container_summary ) {
			$description_parts[] = 'Container: ' . $container_summary . '.';
		}

		// Walk all subtree elements for breakdown.
		$content_descriptions = [];
		foreach ( $subtree as $el ) {
			$eid  = $el['id'] ?? '';
			$name = $el['name'] ?? '';

			// Skip structural wrappers from breakdown (but include container).
			if ( in_array( $name, [ 'section' ], true ) ) {
				continue;
			}

			$entry = [
				'id'   => $eid,
				'type' => $name,
			];

			$settings = $el['settings'] ?? [];

			// Tag.
			if ( ! empty( $settings['tag'] ) ) {
				$entry['tag'] = $settings['tag'];
			}

			// Text content (from content keys).
			$text = $this->extract_text_content( $el );
			if ( '' !== $text ) {
				$entry['text'] = mb_strlen( $text ) > 60 ? mb_substr( $text, 0, 57 ) . '...' : $text;
			}

			// Key styles.
			$key_styles = $this->extract_key_styles( $settings, $class_map );
			if ( ! empty( $key_styles ) ) {
				$entry['key_styles'] = $key_styles;
			}

			// Classes.
			$class_names = [];
			foreach ( $settings['_cssGlobalClasses'] ?? [] as $cid ) {
				if ( isset( $class_map[ $cid ] ) ) {
					$class_names[] = $class_map[ $cid ]['name'];
				}
			}
			if ( ! empty( $class_names ) ) {
				$entry['classes'] = $class_names;
			}

			$element_breakdown[] = $entry;

			// Build content line for prose description.
			if ( ! in_array( $name, [ 'container', 'block', 'div' ], true ) ) {
				$content_desc = $name;
				if ( ! empty( $entry['tag'] ) ) {
					$content_desc = $entry['tag'] . ' ' . $name;
				}
				if ( isset( $entry['text'] ) ) {
					$content_desc .= ": '" . $entry['text'] . "'";
				}
				$content_descriptions[] = $content_desc;
			}
		}

		if ( ! empty( $content_descriptions ) ) {
			$description_parts[] = 'Contains:';
			foreach ( $content_descriptions as $cd ) {
				$description_parts[] = '- ' . $cd;
			}
		}

		$rendered_description = implode( "\n", $description_parts );

		return [
			'section_id'           => $section_id,
			'label'                => $label,
			'background_summary'   => $bg_summary,
			'container_summary'    => $container_summary,
			'rendered_description' => $rendered_description,
			'element_breakdown'    => $element_breakdown,
		];
	}

	/**
	 * Summarize a section's background as a human-readable string.
	 *
	 * @param array<string, mixed> $element   The element.
	 * @param array<string, mixed> $class_map Global classes keyed by ID.
	 * @return string Background summary.
	 */
	private function summarize_background( array $element, array $class_map ): string {
		$parts    = [];
		$settings = $element['settings'] ?? [];

		// Inline background color.
		$bg_color = $settings['_background']['color']['raw'] ?? $settings['_background']['color']['hex'] ?? '';
		if ( $bg_color ) {
			$parts[] = $bg_color;
		}

		// Gradient.
		if ( ! empty( $settings['_gradient'] ) ) {
			$gradient = $settings['_gradient'];
			$type     = $gradient['type'] ?? 'linear';
			$apply_to = $gradient['applyTo'] ?? '';
			$colors   = [];
			foreach ( $gradient['colors'] ?? [] as $gc ) {
				$colors[] = $gc['color']['raw'] ?? $gc['color']['hex'] ?? '?';
			}
			$desc = $type . ' gradient';
			if ( ! empty( $colors ) ) {
				$desc .= ' (' . implode( ' to ', array_slice( $colors, 0, 2 ) ) . ')';
			}
			if ( 'overlay' === $apply_to ) {
				$desc .= ' overlay';
			}
			$parts[] = $desc;
		}

		// Background image.
		if ( ! empty( $settings['_background']['image'] ) ) {
			$parts[] = 'background image';
		}

		// Class-based background.
		foreach ( $settings['_cssGlobalClasses'] ?? [] as $cid ) {
			$class = $class_map[ $cid ] ?? null;
			if ( ! $class ) {
				continue;
			}
			$class_bg = $class['settings']['_background']['color']['raw'] ?? '';
			if ( $class_bg ) {
				$parts[] = 'class ' . $class['name'] . ' (' . $class_bg . ')';
			}
		}

		if ( empty( $parts ) ) {
			return 'light (no explicit background)';
		}

		return implode( ', ', $parts );
	}

	/**
	 * Summarize a container element's key layout properties.
	 *
	 * @param array<string, mixed> $element   The container element.
	 * @param array<string, mixed> $class_map Global classes keyed by ID.
	 * @return string Container summary.
	 */
	private function summarize_container( array $element, array $class_map ): string {
		$parts    = [];
		$settings = $element['settings'] ?? [];

		// Max width.
		$max_w = $settings['_widthMax'] ?? '';
		if ( $max_w ) {
			$parts[] = 'max-width ' . $max_w;
		}

		// Alignment.
		$align = $settings['_alignItems'] ?? '';
		if ( $align ) {
			$parts[] = 'align-items ' . $align;
		}

		$justify = $settings['_justifyContent'] ?? '';
		if ( $justify ) {
			$parts[] = 'justify ' . $justify;
		}

		// Padding.
		$padding = $settings['_padding'] ?? [];
		if ( ! empty( $padding ) ) {
			if ( is_string( $padding ) ) {
				$parts[] = 'padding ' . $padding;
			} elseif ( is_array( $padding ) ) {
				$top = $padding['top'] ?? '';
				if ( $top ) {
					$parts[] = 'padding ' . $top;
				}
			}
		}

		// Class names.
		$class_names = [];
		foreach ( $settings['_cssGlobalClasses'] ?? [] as $cid ) {
			if ( isset( $class_map[ $cid ] ) ) {
				$class_names[] = $class_map[ $cid ]['name'];
			}
		}
		if ( ! empty( $class_names ) ) {
			$parts[] = 'classes: ' . implode( ', ', $class_names );
		}

		return ! empty( $parts ) ? implode( ', ', $parts ) : 'default layout';
	}

	/**
	 * Extract text content from an element using known content keys.
	 *
	 * @param array<string, mixed> $element The element.
	 * @return string Extracted text or empty string.
	 */
	private function extract_text_content( array $element ): string {
		$settings = $element['settings'] ?? [];
		$name     = $element['name'] ?? '';

		// Common content keys by element type.
		$content_keys = [
			'heading'        => 'text',
			'text-basic'     => 'text',
			'text'           => 'text',
			'text-link'      => 'text',
			'button'         => 'text',
			'icon-box'       => 'title',
			'alert'          => 'content',
			'counter'        => 'countTo',
			'animated-typing' => 'content',
		];

		$key = $content_keys[ $name ] ?? '';
		if ( $key && isset( $settings[ $key ] ) ) {
			$val = $settings[ $key ];
			return is_string( $val ) ? strip_tags( $val ) : (string) $val;
		}

		return '';
	}

	/**
	 * Extract key style facts from element settings.
	 *
	 * @param array<string, mixed> $settings  Element settings.
	 * @param array<string, mixed> $class_map Global classes keyed by ID.
	 * @return array<string, string> Key style facts.
	 */
	private function extract_key_styles( array $settings, array $class_map ): array {
		$styles = [];

		// Typography color.
		$color = $settings['_typography']['color']['raw'] ?? $settings['_typography']['color']['hex'] ?? '';
		if ( $color ) {
			$styles['color'] = $color;
		}

		// Text alignment.
		$text_align = $settings['_typography']['text-align'] ?? '';
		if ( $text_align ) {
			$styles['alignment'] = $text_align;
		}

		// Font weight.
		$weight = $settings['_typography']['font-weight'] ?? '';
		if ( $weight ) {
			$styles['weight'] = (string) $weight;
		}

		// Font size.
		$font_size = $settings['_typography']['font-size'] ?? '';
		if ( $font_size ) {
			$styles['font_size'] = $font_size;
		}

		// Line height.
		$line_height = $settings['_typography']['line-height'] ?? '';
		if ( $line_height ) {
			$styles['line_height'] = $line_height;
		}

		// Background color.
		$bg = $settings['_background']['color']['raw'] ?? $settings['_background']['color']['hex'] ?? '';
		if ( $bg ) {
			$styles['background'] = $bg;
		}

		// Border.
		$border_style = $settings['_border']['style'] ?? '';
		$border_color = $settings['_border']['color']['raw'] ?? $settings['_border']['color']['hex'] ?? '';
		$border_width = $settings['_border']['width'] ?? '';
		if ( $border_style || $border_color ) {
			$border_desc = [];
			if ( $border_width ) {
				$border_desc[] = is_string( $border_width ) ? $border_width : '';
			}
			if ( $border_style ) {
				$border_desc[] = is_string( $border_style ) ? $border_style : '';
			}
			if ( $border_color ) {
				$border_desc[] = $border_color;
			}
			$styles['border'] = implode( ' ', array_filter( $border_desc ) );
		}

		// Border radius.
		$radius = $settings['_border']['radius'] ?? '';
		if ( $radius ) {
			if ( is_array( $radius ) ) {
				$styles['border_radius'] = implode( ' ', array_filter( $radius ) );
			} else {
				$styles['border_radius'] = (string) $radius;
			}
		}

		// Position.
		$position = $settings['_position'] ?? '';
		if ( $position ) {
			$styles['position'] = $position;
		}

		// Display / direction.
		$direction = $settings['_direction'] ?? '';
		if ( $direction ) {
			$styles['direction'] = $direction;
		}

		return $styles;
	}

	/**
	 * Format a post for list/search responses.
	 *
	 * @param \WP_Post $post The post object.
	 * @return array<string, mixed> Formatted post data.
	 */
	private function format_post_for_list( \WP_Post $post ): array {
		$has_bricks = $this->bricks_service->is_bricks_page( $post->ID );

		// Read raw meta directly to avoid full BricksService deserialization per post (N+1).
		$raw_elements  = get_post_meta( $post->ID, '_bricks_page_content_2', true );
		$element_count = is_array( $raw_elements ) ? count( $raw_elements ) : 0;

		return array(
			'id'                 => $post->ID,
			'title'              => $post->post_title,
			'status'             => $post->post_status,
			'type'               => $post->post_type,
			'slug'               => $post->post_name,
			'date'               => $post->post_date,
			'modified'           => $post->post_modified,
			'author_name'        => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'permalink'          => get_permalink( $post->ID ),
			'has_bricks_content' => $has_bricks,
			'element_count'      => $element_count,
		);
	}

	/**
	 * Collect an element and all its recursive descendants from a flat elements array.
	 *
	 * @param array  $elements All page elements (flat array).
	 * @param string $root_id  The root element ID.
	 * @return array Flat array of the root element and all descendants. Empty if root not found.
	 */
	private function collect_subtree( array $elements, string $root_id ): array {
		// Index by ID.
		$by_id = [];
		foreach ( $elements as $el ) {
			$by_id[ $el['id'] ?? '' ] = $el;
		}

		if ( ! isset( $by_id[ $root_id ] ) ) {
			return [];
		}

		// BFS to collect all descendants.
		$result_ids = [ $root_id ];
		$queue      = $by_id[ $root_id ]['children'] ?? [];

		while ( ! empty( $queue ) ) {
			$cid = array_shift( $queue );
			$result_ids[] = $cid;
			if ( isset( $by_id[ $cid ] ) && ! empty( $by_id[ $cid ]['children'] ) ) {
				foreach ( $by_id[ $cid ]['children'] as $grandchild ) {
					$queue[] = $grandchild;
				}
			}
		}

		$id_set = array_flip( $result_ids );
		return array_values(
			array_filter( $elements, static fn( array $el ) => isset( $id_set[ $el['id'] ?? '' ] ) )
		);
	}

	/**
	 * Compact an element by stripping empty/null/default values.
	 *
	 * @param array<string, mixed> $element Element data.
	 * @return array<string, mixed> Compacted element.
	 */
	private function compact_element( array $element ): array {
		// Remove themeStyles if empty.
		if ( isset( $element['themeStyles'] ) && empty( $element['themeStyles'] ) ) {
			unset( $element['themeStyles'] );
		}

		// Compact settings recursively.
		if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
			$element['settings'] = $this->compact_settings( $element['settings'] );
		}

		return $element;
	}

	/**
	 * Recursively strip empty/null values from settings.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @return array<string, mixed> Compacted settings.
	 */
	private function compact_settings( array $settings ): array {
		$result = [];
		foreach ( $settings as $key => $value ) {
			if ( null === $value ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = $this->compact_settings( $value );
				if ( empty( $value ) ) {
					continue;
				}
			}
			if ( '' === $value ) {
				continue;
			}
			$result[ $key ] = $value;
		}
		return $result;
	}
}
