<?php
/**
 * ConditionSchemaCatalog reference data.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Reference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ConditionSchemaCatalog class.
 *
 * Provides the static reference array for condition schema.
 */
final class ConditionSchemaCatalog {

	/**
	 * Filter hook applied to the returned schema so integrators can extend it.
	 *
	 * @var string
	 */
	public const FILTER_HOOK = 'bricks_mcp_condition_schema';

	/**
	 * Return the reference data array.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$data = array(
			'description'    => 'Element visibility conditions — show/hide elements based on runtime context. Stored in element settings[\'_conditions\']. Distinct from template conditions (which control page targeting).',
			'data_structure' => array(
				'description' => '_conditions is an array of condition SETS. Outer array = OR logic (any set passing renders element). Inner arrays = AND logic (all conditions in a set must pass).',
				'format'      => '[[{key, compare, value}, {key, compare, value}], [{key, compare, value}]]',
				'example'     => 'Show if (logged in AND admin) OR (post author): [[{"key":"user_logged_in","compare":"==","value":"1"},{"key":"user_role","compare":"==","value":["administrator"]}],[{"key":"post_author","compare":"==","value":"1"}]]',
			),
			'groups'         => array(
				array(
					'name'  => 'post',
					'label' => 'Post',
					'keys'  => array(
						array(
							'key'         => 'post_id',
							'label'       => 'Post ID',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (numeric)',
							'description' => 'Current post ID',
						),
						array(
							'key'         => 'post_title',
							'label'       => 'Post title',
							'compare'     => array( '==', '!=', 'contains', 'contains_not' ),
							'value_type'  => 'string',
							'description' => 'Current post title',
						),
						array(
							'key'         => 'post_parent',
							'label'       => 'Post parent',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (numeric, default 0)',
							'description' => 'Parent post ID',
						),
						array(
							'key'         => 'post_status',
							'label'       => 'Post status',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'array (status slugs, e.g. ["publish","draft"])',
							'description' => 'Post status',
						),
						array(
							'key'         => 'post_author',
							'label'       => 'Post author',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'string (user ID)',
							'description' => 'Post author user ID',
						),
						array(
							'key'         => 'post_date',
							'label'       => 'Post date',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (Y-m-d)',
							'description' => 'Post publish date',
						),
						array(
							'key'         => 'featured_image',
							'label'       => 'Featured image',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'string ("1" = set, "0" = not set)',
							'description' => 'Whether post has featured image',
						),
					),
				),
				array(
					'name'  => 'user',
					'label' => 'User',
					'keys'  => array(
						array(
							'key'         => 'user_logged_in',
							'label'       => 'User login',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'string ("1" = logged in, "0" = logged out)',
							'description' => 'Login status',
						),
						array(
							'key'         => 'user_id',
							'label'       => 'User ID',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (numeric)',
							'description' => 'Current user ID',
						),
						array(
							'key'         => 'user_registered',
							'label'       => 'User registered',
							'compare'     => array( '<', '>' ),
							'value_type'  => 'string (Y-m-d)',
							'description' => 'Registration date (< = after date, > = before date)',
						),
						array(
							'key'         => 'user_role',
							'label'       => 'User role',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'array (role slugs, e.g. ["administrator","editor"])',
							'description' => 'User role(s)',
						),
					),
				),
				array(
					'name'  => 'date',
					'label' => 'Date & time',
					'keys'  => array(
						array(
							'key'         => 'weekday',
							'label'       => 'Weekday',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (1-7, Monday=1 through Sunday=7)',
							'description' => 'Day of week',
						),
						array(
							'key'         => 'date',
							'label'       => 'Date',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (Y-m-d)',
							'description' => 'Current date (uses WP timezone)',
						),
						array(
							'key'         => 'time',
							'label'       => 'Time',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (H:i, e.g. "09:00")',
							'description' => 'Current time (uses WP timezone)',
						),
						array(
							'key'         => 'datetime',
							'label'       => 'Datetime',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (Y-m-d h:i a)',
							'description' => 'Date and time combined (uses WP timezone)',
						),
					),
				),
				array(
					'name'  => 'other',
					'label' => 'Other',
					'keys'  => array(
						array(
							'key'          => 'dynamic_data',
							'label'        => 'Dynamic data',
							'compare'      => array( '==', '!=', '>=', '<=', '>', '<', 'contains', 'contains_not', 'empty', 'empty_not' ),
							'value_type'   => 'string (comparison value; can contain dynamic data tags)',
							'description'  => 'Compare any dynamic data tag output. IMPORTANT: Set the dynamic data tag in the "dynamic_data" field (e.g. "{acf_my_field}"), and the comparison target in "value".',
							'extra_fields' => array( 'dynamic_data' => 'string — the dynamic data tag to evaluate (e.g. "{acf_my_field}", "{post_author_id}")' ),
						),
						array(
							'key'         => 'browser',
							'label'       => 'Browser',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'string (chrome, firefox, safari, edge, opera, msie)',
							'description' => 'Browser detection via user agent',
						),
						array(
							'key'         => 'operating_system',
							'label'       => 'Operating system',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'string (windows, mac, linux, ubuntu, iphone, ipad, ipod, android, blackberry, webos)',
							'description' => 'OS detection via user agent',
						),
						array(
							'key'         => 'current_url',
							'label'       => 'Current URL',
							'compare'     => array( '==', '!=', 'contains', 'contains_not' ),
							'value_type'  => 'string',
							'description' => 'Current page URL including query parameters',
						),
						array(
							'key'         => 'referer',
							'label'       => 'Referrer URL',
							'compare'     => array( '==', '!=', 'contains', 'contains_not' ),
							'value_type'  => 'string',
							'description' => 'HTTP referrer URL',
						),
					),
				),
			),
			'woocommerce_group' => array(
				'note'  => 'WooCommerce conditions are available only when WooCommerce is active.',
				'name'  => 'woocommerce',
				'label' => 'WooCommerce',
				'keys'  => array(
					array(
						'key'        => 'woo_product_type',
						'label'      => 'Product type',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string (simple, grouped, external, variable)',
					),
					array(
						'key'        => 'woo_product_sale',
						'label'      => 'Product sale status',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = on sale, "0" = not)',
					),
					array(
						'key'        => 'woo_product_new',
						'label'      => 'Product new status',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = new, "0" = not)',
					),
					array(
						'key'        => 'woo_product_stock_status',
						'label'      => 'Product stock status',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string (instock, outofstock, onbackorder)',
					),
					array(
						'key'        => 'woo_product_stock_quantity',
						'label'      => 'Product stock quantity',
						'compare'    => array( '==', '!=', '>=', '<=', '>', '<' ),
						'value_type' => 'string (numeric)',
					),
					array(
						'key'        => 'woo_product_stock_management',
						'label'      => 'Product stock management',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = enabled, "0" = disabled)',
					),
					array(
						'key'        => 'woo_product_sold_individually',
						'label'      => 'Product sold individually',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = enabled, "0" = disabled)',
					),
					array(
						'key'        => 'woo_product_purchased_by_user',
						'label'      => 'Product purchased by user',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = true, "0" = false)',
					),
					array(
						'key'        => 'woo_product_featured',
						'label'      => 'Product featured',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = true, "0" = false)',
					),
					array(
						'key'        => 'woo_product_rating',
						'label'      => 'Product rating',
						'compare'    => array( '==', '!=', '>=', '<=', '>', '<' ),
						'value_type' => 'string (numeric, average rating)',
					),
					array(
						'key'        => 'woo_product_category',
						'label'      => 'Product category',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'array (term IDs)',
					),
					array(
						'key'        => 'woo_product_tag',
						'label'      => 'Product tag',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'array (term IDs)',
					),
				),
			),
			'examples'          => array(
				'logged_in_only'          => array(
					'description' => 'Show element only to logged-in users',
					'conditions'  => array( array( array( 'key' => 'user_logged_in', 'compare' => '==', 'value' => '1' ) ) ),
				),
				'admin_or_editor'         => array(
					'description' => 'Show element to administrators or editors',
					'conditions'  => array( array( array( 'key' => 'user_role', 'compare' => '==', 'value' => array( 'administrator', 'editor' ) ) ) ),
				),
				'weekday_business_hours'  => array(
					'description' => 'Show element Monday-Friday between 9am-5pm',
					'conditions'  => array(
						array(
							array( 'key' => 'weekday', 'compare' => '>=', 'value' => '1' ),
							array( 'key' => 'weekday', 'compare' => '<=', 'value' => '5' ),
							array( 'key' => 'time', 'compare' => '>=', 'value' => '09:00' ),
							array( 'key' => 'time', 'compare' => '<=', 'value' => '17:00' ),
						),
					),
				),
				'dynamic_data_acf'        => array(
					'description' => 'Show element when ACF field "show_banner" is true',
					'conditions'  => array( array( array( 'key' => 'dynamic_data', 'compare' => '==', 'value' => '1', 'dynamic_data' => '{acf_show_banner}' ) ) ),
				),
				'or_logic'                => array(
					'description' => 'Show to admins OR when post has featured image (two condition sets = OR)',
					'conditions'  => array(
						array( array( 'key' => 'user_role', 'compare' => '==', 'value' => array( 'administrator' ) ) ),
						array( array( 'key' => 'featured_image', 'compare' => '==', 'value' => '1' ) ),
					),
				),
			),
			'notes'             => array(
				'Element conditions (this schema) are DIFFERENT from template conditions (template_condition tool). Template conditions use "main" key and control which pages a template targets. Element conditions use "key/compare/value" and control whether an individual element renders.',
				'Third-party plugins can register custom condition keys via the bricks/conditions/options filter. Unknown keys are accepted with a warning.',
				'Conditions are evaluated server-side at render time by Bricks Conditions::check(). The MCP only configures conditions — it does not evaluate them.',
			),
		);

		/**
		 * Filter: bricks_mcp_condition_schema
		 * Validate filtered result — third-party filters may return non-array.
		 */
		$filtered = apply_filters( self::FILTER_HOOK, $data );
		return is_array( $filtered ) ? $filtered : $data;
	}
}
