<?php
/**
 * WordPress handler for MCP Router.
 *
 * Handles non-Bricks WordPress operations: posts, users, plugins.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\ToolRegistry;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles WordPress tool actions.
 */
final class WordPressHandler {

	/**
	 * Per-action capability requirements.
	 */
	private const ACTION_CAPS = [
		'get_posts'         => 'read',
		'get_post'          => 'read',
		'get_users'         => 'list_users',
		'get_plugins'       => 'activate_plugins',
		'activate_plugin'   => 'activate_plugins',
		'deactivate_plugin' => 'activate_plugins',
		'create_user'       => 'create_users',
		'update_user'       => 'edit_users',
	];

	/**
	 * Default length for auto-generated user passwords when caller omits `password`.
	 * 16 chars + special chars provides >100 bits of entropy with wp_generate_password.
	 */
	private const GENERATED_PASSWORD_LENGTH = 16;

	/**
	 * Handle WordPress tool actions.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$action = $args['action'] ?? '';

		if ( ! isset( self::ACTION_CAPS[ $action ] ) ) {
			return new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: get_posts, get_post, get_users, get_plugins, activate_plugin, deactivate_plugin, create_user, update_user', 'bricks-mcp' ),
					sanitize_text_field( $action )
				)
			);
		}

		if ( ! current_user_can( self::ACTION_CAPS[ $action ] ) ) {
			return new \WP_Error(
				'bricks_mcp_forbidden',
				sprintf(
					/* translators: %s: Required capability */
					__( 'You do not have the required capability (%s) to perform this action.', 'bricks-mcp' ),
					self::ACTION_CAPS[ $action ]
				)
			);
		}

		return match ( $action ) {
			'get_posts'         => $this->tool_get_posts( $args ),
			'get_post'          => $this->tool_get_post( $args ),
			'get_users'         => $this->tool_get_users( $args ),
			'get_plugins'       => $this->tool_get_plugins( $args ),
			'activate_plugin'   => $this->tool_activate_plugin( $args ),
			'deactivate_plugin' => $this->tool_deactivate_plugin( $args ),
			'create_user'       => $this->tool_create_user( $args ),
			'update_user'       => $this->tool_update_user( $args ),
			default             => new \WP_Error( 'invalid_action', 'Unknown action.' ),
		};
	}

	/**
	 * Tool: Get posts.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int, array<string, mixed>> Posts list.
	 */
	private function tool_get_posts( array $args ): array {
		$order = isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$query_args = array(
			'post_type'      => isset( $args['post_type'] ) ? sanitize_text_field( (string) $args['post_type'] ) : 'post',
			'posts_per_page' => isset( $args['posts_per_page'] ) ? min( absint( $args['posts_per_page'] ), 100 ) : 10,
			'orderby'        => isset( $args['orderby'] ) ? sanitize_text_field( (string) $args['orderby'] ) : 'date',
			'order'          => $order,
			'post_status'    => 'publish',
			's'              => isset( $args['s'] ) ? sanitize_text_field( (string) $args['s'] ) : '',
			'paged'          => isset( $args['paged'] ) ? absint( $args['paged'] ) : 1,
			'category_name'  => isset( $args['category_name'] ) ? sanitize_text_field( (string) $args['category_name'] ) : '',
			'tag'            => isset( $args['tag'] ) ? sanitize_text_field( (string) $args['tag'] ) : '',
			'author'         => isset( $args['author'] ) ? absint( $args['author'] ) : 0,
		);

		$posts = get_posts( $query_args );

		// Prime meta cache to avoid N+1 queries for get_the_post_thumbnail_url().
		update_postmeta_cache( wp_list_pluck( $posts, 'ID' ) );

		$result = array();

		foreach ( $posts as $post ) {
			$result[] = array(
				'id'             => $post->ID,
				'title'          => $post->post_title,
				'slug'           => $post->post_name,
				'status'         => $post->post_status,
				'type'           => $post->post_type,
				'date'           => $post->post_date,
				'modified'       => $post->post_modified,
				'excerpt'        => $post->post_excerpt,
				'author'         => (int) $post->post_author,
				'permalink'      => get_permalink( $post->ID ),
				'featured_image' => get_the_post_thumbnail_url( $post->ID, 'full' ),
			);
		}

		return $result;
	}

	/**
	 * Tool: Get single post.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Post data or error.
	 */
	private function tool_get_post( array $args ): array|\WP_Error {
		if ( empty( $args['id'] ) ) {
			return new \WP_Error( 'missing_id', __( 'Post ID is required. Use get_posts or list_pages to find valid post IDs.', 'bricks-mcp' ) );
		}

		$post = get_post( (int) $args['id'] );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found. Use get_posts or list_pages to find valid post IDs.', 'bricks-mcp' ),
					(int) $args['id']
				)
			);
		}

		return array(
			'id'             => $post->ID,
			'title'          => $post->post_title,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'type'           => $post->post_type,
			'date'           => $post->post_date,
			'modified'       => $post->post_modified,
			'author'         => (int) $post->post_author,
			'author_name'    => get_the_author_meta( 'display_name', $post->post_author ),
			'permalink'      => get_permalink( $post->ID ),
			'featured_image' => get_the_post_thumbnail_url( $post->ID, 'full' ),
			'categories'     => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
			'tags'           => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
		);
	}

	/**
	 * Tool: Get users.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int, array<string, mixed>> Users list.
	 */
	private function tool_get_users( array $args ): array {
		$allowed_orderby = array( 'display_name', 'registered', 'ID' );
		$allowed_order   = array( 'ASC', 'DESC' );

		$query_args = array(
			'number'  => min( isset( $args['number'] ) ? absint( $args['number'] ) : 10, 100 ),
			'role'    => isset( $args['role'] ) ? sanitize_text_field( (string) $args['role'] ) : '',
			'orderby' => isset( $args['orderby'] ) && in_array( $args['orderby'], $allowed_orderby, true )
				? $args['orderby']
				: 'display_name',
			'order'   => isset( $args['order'] ) && in_array( strtoupper( (string) $args['order'] ), $allowed_order, true )
				? strtoupper( (string) $args['order'] )
				: 'ASC',
			'paged'   => isset( $args['paged'] ) ? absint( $args['paged'] ) : 1,
		);

		$users  = get_users( $query_args );
		$result = array();

		$include_pii = ! empty( $args['include_pii'] );

		foreach ( $users as $user ) {
			$user_data = array(
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'registered'   => $user->user_registered,
				'roles'        => $user->roles,
			);

			if ( $include_pii ) {
				$user_data['login'] = $user->user_login;
				$user_data['email'] = $user->user_email;
			}

			$result[] = $user_data;
		}

		return $result;
	}

	/**
	 * Tool: Get plugins.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, array<string, mixed>> Plugins list.
	 */
	private function tool_get_plugins( array $args ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		$status         = $args['status'] ?? 'all';

		$result = array();

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$is_active = in_array( $plugin_file, $active_plugins, true );

			if ( 'active' === $status && ! $is_active ) {
				continue;
			}

			if ( 'inactive' === $status && $is_active ) {
				continue;
			}

			$result[ $plugin_file ] = array(
				'name'        => $plugin_data['Name'],
				'version'     => $plugin_data['Version'],
				'description' => $plugin_data['Description'],
				'author'      => $plugin_data['Author'],
				'is_active'   => $is_active,
			);
		}

		return $result;
	}

	/**
	 * Tool: Activate a plugin.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'plugin_file'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	private function tool_activate_plugin( array $args ): array|\WP_Error {
		$plugin_file = sanitize_text_field( $args['plugin_file'] ?? '' );
		if ( empty( $plugin_file ) ) {
			return new \WP_Error( 'missing_plugin_file', 'plugin_file is required. Use wordpress:get_plugins to find plugin file paths.' );
		}

		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf( 'Activating plugin "%s" will execute its activation hooks, which may alter your site. Set confirm: true to proceed.', $plugin_file )
			);
		}

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( $plugin_file ) ) {
			return array( 'plugin_file' => $plugin_file, 'status' => 'already_active', 'message' => 'Plugin is already active.' );
		}

		$result = activate_plugin( $plugin_file );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'plugin_file' => $plugin_file, 'status' => 'activated', 'message' => 'Plugin activated successfully.' );
	}

	/**
	 * Tool: Deactivate a plugin.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'plugin_file'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	private function tool_deactivate_plugin( array $args ): array|\WP_Error {
		$plugin_file = sanitize_text_field( $args['plugin_file'] ?? '' );
		if ( empty( $plugin_file ) ) {
			return new \WP_Error( 'missing_plugin_file', 'plugin_file is required.' );
		}

		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error(
				'bricks_mcp_confirm_required',
				sprintf( 'Deactivating plugin "%s" may break site functionality. Set confirm: true to proceed.', $plugin_file )
			);
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( $plugin_file ) ) {
			return array( 'plugin_file' => $plugin_file, 'status' => 'already_inactive', 'message' => 'Plugin is already inactive.' );
		}

		deactivate_plugins( $plugin_file );

		return array( 'plugin_file' => $plugin_file, 'status' => 'deactivated', 'message' => 'Plugin deactivated successfully.' );
	}

	/**
	 * Tool: Create a WordPress user.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'username', 'email', etc.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	private function tool_create_user( array $args ): array|\WP_Error {
		$username = sanitize_user( $args['username'] ?? '' );
		$email    = sanitize_email( $args['email'] ?? '' );

		if ( empty( $username ) ) {
			return new \WP_Error( 'missing_username', 'username is required for create_user.' );
		}
		if ( empty( $email ) || ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', 'A valid email is required for create_user.' );
		}

		$password = $args['password'] ?? wp_generate_password( self::GENERATED_PASSWORD_LENGTH, true );
		$role     = sanitize_text_field( $args['user_role'] ?? 'subscriber' );

		// Validate role against registered WordPress roles and block administrator creation.
		$valid_roles = array_keys( wp_roles()->get_names() );
		if ( ! in_array( $role, $valid_roles, true ) ) {
			return new \WP_Error( 'invalid_role', sprintf( 'Invalid role "%s". Valid roles: %s', $role, implode( ', ', $valid_roles ) ) );
		}
		if ( 'administrator' === $role ) {
			return new \WP_Error( 'role_blocked', 'Creating administrator accounts via MCP is not allowed for security reasons.' );
		}

		$user_id = wp_insert_user( array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => $password,
			'display_name' => sanitize_text_field( $args['display_name'] ?? $username ),
			'role'         => $role,
		) );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		return array(
			'user_id'      => $user_id,
			'username'     => $username,
			'email'        => $email,
			'display_name' => $args['display_name'] ?? $username,
			'role'         => $role,
			'message'      => 'User created successfully.',
		);
	}

	/**
	 * Tool: Update a WordPress user.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'user_id' and fields to update.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	private function tool_update_user( array $args ): array|\WP_Error {
		$user_id = (int) ( $args['user_id'] ?? 0 );
		if ( 0 === $user_id ) {
			return new \WP_Error( 'missing_user_id', 'user_id is required for update_user. Use wordpress:get_users to find user IDs.' );
		}

		$user = get_user_by( 'ID', $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'user_not_found', sprintf( 'User %d not found.', $user_id ) );
		}

		$update_data    = array( 'ID' => $user_id );
		$updated_fields = array();

		if ( ! empty( $args['email'] ) ) {
			$email = sanitize_email( $args['email'] );
			if ( is_email( $email ) ) {
				$update_data['user_email'] = $email;
				$updated_fields[]          = 'email';
			}
		}
		if ( ! empty( $args['display_name'] ) ) {
			$update_data['display_name'] = sanitize_text_field( $args['display_name'] );
			$updated_fields[]            = 'display_name';
		}
		if ( ! empty( $args['user_role'] ) ) {
			$role        = sanitize_text_field( $args['user_role'] );
			$valid_roles = array_keys( wp_roles()->get_names() );
			if ( ! in_array( $role, $valid_roles, true ) ) {
				return new \WP_Error( 'invalid_role', sprintf( 'Invalid role "%s". Valid roles: %s', $role, implode( ', ', $valid_roles ) ) );
			}
			if ( 'administrator' === $role ) {
				return new \WP_Error( 'role_blocked', 'Promoting users to administrator via MCP is not allowed for security reasons.' );
			}
			$update_data['role'] = $role;
			$updated_fields[]    = 'role';
		}
		if ( ! empty( $args['password'] ) ) {
			$update_data['user_pass'] = $args['password'];
			$updated_fields[]         = 'password';
		}

		if ( empty( $updated_fields ) ) {
			return new \WP_Error( 'no_fields', 'No fields to update. Provide email, display_name, user_role, or password.' );
		}

		$result = wp_update_user( $update_data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'user_id'        => $user_id,
			'updated_fields' => $updated_fields,
			'message'        => 'User updated successfully.',
		);
	}

	/**
	 * Register the wordpress tool.
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'wordpress',
			__( "Query and manage WordPress data.\n\nActions: get_posts, get_post, get_users, get_plugins, activate_plugin, deactivate_plugin, create_user, update_user.", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'         => array(
						'type'        => 'string',
						'enum'        => array( 'get_posts', 'get_post', 'get_users', 'get_plugins', 'activate_plugin', 'deactivate_plugin', 'create_user', 'update_user' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'post_type'      => array(
						'type'        => 'string',
						'description' => __( 'Post type to query (get_posts: default post)', 'bricks-mcp' ),
					),
					'posts_per_page' => array(
						'type'        => 'integer',
						'description' => __( 'Number of posts to return (get_posts: default 10, max 100)', 'bricks-mcp' ),
					),
					'orderby'        => array(
						'type'        => 'string',
						'description' => __( 'Order by field (get_posts: date, title, modified, etc.)', 'bricks-mcp' ),
					),
					'order'          => array(
						'type'        => 'string',
						'enum'        => array( 'ASC', 'DESC' ),
						'description' => __( 'Sort order (get_posts: ASC or DESC)', 'bricks-mcp' ),
					),
					'id'             => array(
						'type'        => 'integer',
						'description' => __( 'Post ID (get_post: required)', 'bricks-mcp' ),
					),
					'role'           => array(
						'type'        => 'string',
						'description' => __( 'Filter by user role (get_users)', 'bricks-mcp' ),
					),
					'number'         => array(
						'type'        => 'integer',
						'description' => __( 'Number of users to return (get_users: default 10)', 'bricks-mcp' ),
					),
					'include_pii'    => array(
						'type'        => 'boolean',
						'description' => __( 'Include sensitive fields (email, login). Warning: data may be logged by AI services. (get_users: default false)', 'bricks-mcp' ),
					),
					'status'         => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'active', 'inactive' ),
						'description' => __( 'Filter by plugin status (get_plugins)', 'bricks-mcp' ),
					),
					'plugin_file'    => array(
						'type'        => 'string',
						'description' => __( 'Plugin file path relative to plugins directory (activate_plugin, deactivate_plugin: required, e.g. "akismet/akismet.php")', 'bricks-mcp' ),
					),
					'username'       => array(
						'type'        => 'string',
						'description' => __( 'Username (create_user: required)', 'bricks-mcp' ),
					),
					'email'          => array(
						'type'        => 'string',
						'description' => __( 'User email (create_user: required, update_user: optional)', 'bricks-mcp' ),
					),
					'password'       => array(
						'type'        => 'string',
						'description' => __( 'User password (create_user: optional, auto-generated if omitted)', 'bricks-mcp' ),
					),
					'display_name'   => array(
						'type'        => 'string',
						'description' => __( 'Display name (create_user, update_user: optional)', 'bricks-mcp' ),
					),
					'user_role'      => array(
						'type'        => 'string',
						'description' => __( 'User role (create_user: default "subscriber", update_user: optional)', 'bricks-mcp' ),
					),
					'user_id'        => array(
						'type'        => 'integer',
						'description' => __( 'User ID (update_user: required)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'handle' )
		);
	}
}
