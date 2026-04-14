<?php
/**
 * WooCommerce handler for MCP Router.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\SchemaGenerator;
use BricksMCP\MCP\ToolRegistry;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles WooCommerce tool actions.
 *
 * Consolidated dispatcher for WooCommerce status, element discovery,
 * dynamic data tags, and template scaffolding.
 */
final class WooCommerceHandler {

	/**
	 * Bricks service instance.
	 *
	 * @var BricksService
	 */
	private BricksService $bricks_service;

	/**
	 * Schema generator instance.
	 *
	 * @var SchemaGenerator
	 */
	private SchemaGenerator $schema_generator;

	/**
	 * Bricks check callback.
	 *
	 * @var callable
	 */
	private $require_bricks;

	/**
	 * Constructor.
	 *
	 * @param BricksService   $bricks_service   Bricks service instance.
	 * @param SchemaGenerator $schema_generator  Schema generator instance.
	 * @param callable        $require_bricks    Callback that returns \WP_Error|null.
	 */
	public function __construct( BricksService $bricks_service, SchemaGenerator $schema_generator, callable $require_bricks ) {
		$this->bricks_service   = $bricks_service;
		$this->schema_generator = $schema_generator;
		$this->require_bricks   = $require_bricks;
	}

	/**
	 * Handle WooCommerce tool dispatch.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Response data or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return new \WP_Error(
				'woocommerce_not_active',
				__( 'WooCommerce is not installed or not active. Install and activate WooCommerce before using WooCommerce builder tools.', 'bricks-mcp' )
			);
		}

		$action        = sanitize_text_field( $args['action'] ?? '' );


		return match ( $action ) {
			'status'            => $this->tool_woocommerce_status(),
			'get_elements'      => $this->tool_woocommerce_get_elements( $args ),
			'get_dynamic_tags'  => $this->tool_woocommerce_get_dynamic_tags( $args ),
			'scaffold_template' => $this->tool_woocommerce_scaffold_template( $args ),
			'scaffold_store'    => $this->tool_woocommerce_scaffold_store( $args ),
			default             => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: status, get_elements, get_dynamic_tags, scaffold_template, scaffold_store', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * WooCommerce: Get status.
	 *
	 * Returns WooCommerce version, page assignments, Bricks WooCommerce settings,
	 * available template types, and count of existing WooCommerce templates.
	 *
	 * @return array<string, mixed> WooCommerce status data.
	 */
	private function tool_woocommerce_status(): array {
		$global_settings = get_option( 'bricks_global_settings', array() );
		$woo_settings    = array();
		$woo_keys        = array(
			'woocommerceEnableAjaxAddToCart',
			'woocommerceDisableBuilder',
			'woocommerceAjaxAddedText',
			'woocommerceAjaxAddingText',
			'woocommerceAjaxHideViewCart',
			'woocommerceAjaxShowNotice',
			'woocommerceBadgeNew',
			'woocommerceBadgeSale',
			'woocommerceUseQtyInLoop',
			'woocommerceUseVariationSwatches',
			'woocommerceDisableProductGalleryLightbox',
			'woocommerceDisableProductGalleryZoom',
		);

		foreach ( $woo_keys as $key ) {
			if ( isset( $global_settings[ $key ] ) ) {
				$woo_settings[ $key ] = $global_settings[ $key ];
			}
		}

		// Get WooCommerce template types (wc_ prefixed only).
		$all_types = $this->bricks_service->get_condition_types();
		$wc_types  = array();
		foreach ( $all_types as $slug => $type_data ) {
			if ( str_starts_with( $slug, 'wc_' ) ) {
				$wc_types[] = array(
					'slug'  => $slug,
					'label' => $type_data['label'],
				);
			}
		}

		// Count existing WooCommerce templates.
		$existing_query = new \WP_Query(
			array(
				'post_type'      => 'bricks_template',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_bricks_template_type',
						'value'   => 'wc_',
						'compare' => 'LIKE',
					),
				),
			)
		);

		return array(
			'woocommerce_version'         => WC()->version,
			'woocommerce_active'          => true,
			'shop_page_id'                => wc_get_page_id( 'shop' ),
			'cart_page_id'                => wc_get_page_id( 'cart' ),
			'checkout_page_id'            => wc_get_page_id( 'checkout' ),
			'myaccount_page_id'           => wc_get_page_id( 'myaccount' ),
			'terms_page_id'               => wc_get_page_id( 'terms' ),
			'bricks_woocommerce_settings' => $woo_settings,
			'template_types_available'    => $wc_types,
			'existing_woo_templates'      => $existing_query->found_posts,
		);
	}

	/**
	 * WooCommerce: Get WooCommerce-specific elements.
	 *
	 * Filters the Bricks element catalog to WooCommerce elements only.
	 *
	 * @param array<string, mixed> $args Tool arguments. Optional 'category'.
	 * @return array<string, mixed> Filtered element catalog.
	 */
	private function tool_woocommerce_get_elements( array $args ): array {
		$catalog       = $this->schema_generator->get_element_catalog();
		$category      = $args['category'] ?? '';
		$woo_elements  = array();
		$woo_prefixes  = array( 'product-', 'cart-', 'checkout-', 'account-', 'woocommerce-', 'products' );

		foreach ( $catalog as $element ) {
			$name = $element['name'];
			$cat  = strtolower( $element['category'] ?? '' );

			// Match by Bricks category or name prefix.
			$is_woo = str_contains( $cat, 'woocommerce' );
			if ( ! $is_woo ) {
				foreach ( $woo_prefixes as $prefix ) {
					if ( str_starts_with( $name, $prefix ) ) {
						$is_woo = true;
						break;
					}
				}
			}

			if ( ! $is_woo ) {
				continue;
			}

			// Assign a normalized category for filtering.
			$norm_cat = 'utility';
			if ( str_contains( $name, 'product-' ) || str_starts_with( $name, 'product' ) ) {
				$norm_cat = 'product';
			} elseif ( str_contains( $name, 'cart-' ) || str_starts_with( $name, 'cart' ) ) {
				$norm_cat = 'cart';
			} elseif ( str_contains( $name, 'checkout-' ) || str_starts_with( $name, 'checkout' ) ) {
				$norm_cat = 'checkout';
			} elseif ( str_contains( $name, 'account-' ) || str_starts_with( $name, 'account' ) ) {
				$norm_cat = 'account';
			} elseif ( str_starts_with( $name, 'products' ) ) {
				$norm_cat = 'archive';
			}

			if ( '' !== $category && $norm_cat !== $category ) {
				continue;
			}

			$woo_elements[] = array(
				'name'            => $name,
				'label'           => $element['label'],
				'bricks_category' => $element['category'],
				'woo_category'    => $norm_cat,
			);
		}

		return array(
			'total_elements' => count( $woo_elements ),
			'note'           => 'WooCommerce-specific elements available when WooCommerce is active. Use these element names with page:create, element:add, and scaffold_template.',
			'elements'       => $woo_elements,
		);
	}

	/**
	 * WooCommerce: Get dynamic data tags reference.
	 *
	 * Returns a categorized reference of WooCommerce dynamic data tags.
	 *
	 * @param array<string, mixed> $args Tool arguments. Optional 'category'.
	 * @return array<string, mixed> Dynamic data tags reference.
	 */
	private function tool_woocommerce_get_dynamic_tags( array $args ): array {
		$category = $args['category'] ?? '';

		$tags = array(
			'product_price'   => array(
				'label'   => 'Product Price',
				'context' => 'Single product templates, product archive loops',
				'tags'    => array(
					array(
						'tag'         => '{woo_product_price}',
						'description' => 'Full product price with currency and HTML (shows sale + regular when on sale)',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_product_regular_price}',
						'description' => 'Regular price with currency and HTML',
						'modifiers'   => array( ':plain (no HTML)', ':value (numeric only)' ),
					),
					array(
						'tag'         => '{woo_product_sale_price}',
						'description' => 'Sale price (empty if not on sale)',
						'modifiers'   => array( ':plain', ':value' ),
					),
				),
			),
			'product_display' => array(
				'label'   => 'Product Display',
				'context' => 'Single product templates',
				'tags'    => array(
					array(
						'tag'         => '{woo_product_images}',
						'description' => 'Featured + gallery images',
						'modifiers'   => array( ':value (comma-separated attachment IDs)' ),
					),
					array(
						'tag'         => '{woo_product_gallery_images}',
						'description' => 'Gallery images only (excludes featured image)',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_product_cat_image}',
						'description' => 'Product category image',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_add_to_cart}',
						'description' => 'Renders add to cart button',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_product_on_sale}',
						'description' => 'On-sale badge (empty if not on sale)',
						'modifiers'   => array(),
					),
				),
			),
			'product_info'    => array(
				'label'   => 'Product Information',
				'context' => 'Single product templates, product archive loops',
				'tags'    => array(
					array(
						'tag'         => '{woo_product_rating}',
						'description' => 'Star rating display',
						'modifiers'   => array( ':plain (text)', ':format (shows even without reviews)' ),
					),
					array(
						'tag'         => '{woo_product_sku}',
						'description' => 'Product SKU',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_product_excerpt}',
						'description' => 'Product short description',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_product_stock}',
						'description' => 'Stock info text',
						'modifiers'   => array( ':value (quantity number)', ':status (instock/outofstock/onbackorder)' ),
					),
					array(
						'tag'         => '{woo_product_badge_new}',
						'description' => 'New product badge',
						'modifiers'   => array( ':plain (text only)' ),
					),
				),
			),
			'cart'            => array(
				'label'   => 'Cart (for Cart Contents query loop)',
				'context' => 'Cart template with Cart Contents query loop',
				'tags'    => array(
					array(
						'tag'         => '{woo_cart_product_name}',
						'description' => 'Product name with link to product page',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_cart_remove_link}',
						'description' => 'Remove from cart anchor element',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_cart_quantity}',
						'description' => 'Quantity input field',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_cart_subtotal}',
						'description' => 'Line item subtotal (price x quantity)',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_cart_update}',
						'description' => 'Update cart button',
						'modifiers'   => array(),
					),
				),
			),
			'order'           => array(
				'label'   => 'Order (for Thank You / Order templates)',
				'context' => 'Thank you template, order receipt, pay template',
				'tags'    => array(
					array(
						'tag'         => '{woo_order_id}',
						'description' => 'Order ID number',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_order_total}',
						'description' => 'Order total with currency',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_order_email}',
						'description' => 'Customer email address',
						'modifiers'   => array(),
					),
				),
			),
			'post_compatible' => array(
				'label'   => 'Standard Post Tags (WooCommerce compatible)',
				'context' => 'Any WooCommerce template (products are posts)',
				'tags'    => array(
					array(
						'tag'         => '{post_id}',
						'description' => 'Product/post ID',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{post_title}',
						'description' => 'Product/post title',
						'modifiers'   => array( ':link (as hyperlink)' ),
					),
					array(
						'tag'         => '{post_terms_product_cat}',
						'description' => 'Product categories',
						'modifiers'   => array( ':plain (no links)' ),
					),
					array(
						'tag'         => '{post_terms_product_tag}',
						'description' => 'Product tags',
						'modifiers'   => array( ':plain (no links)' ),
					),
				),
			),
		);

		$template_hooks = array(
			'single_product'  => array(
				'woocommerce_before_single_product',
				'woocommerce_before_single_product_summary',
				'woocommerce_single_product_summary',
				'woocommerce_after_single_product_summary',
				'woocommerce_after_single_product',
			),
			'product_archive' => array(
				'woocommerce_archive_description',
				'woocommerce_before_shop_loop',
				'woocommerce_after_shop_loop',
			),
			'cart'            => array(
				'woocommerce_before_cart',
				'woocommerce_before_cart_collaterals',
				'woocommerce_after_cart',
			),
			'empty_cart'      => array(
				'woocommerce_cart_is_empty',
			),
		);

		if ( '' !== $category ) {
			if ( ! isset( $tags[ $category ] ) ) {
				return array(
					'note'            => 'These dynamic data tags can be used in text fields of any element by wrapping them in curly braces. Modifiers are appended with colon, e.g. {woo_product_price:value}.',
					'categories'      => array_keys( $tags ),
					'tags'            => $tags,
					'template_hooks'  => $template_hooks,
				);
			}
			$tags = array( $category => $tags[ $category ] );
		}

		return array(
			'note'           => 'These dynamic data tags can be used in text fields of any element by wrapping them in curly braces. Use in product templates for dynamic product data, in cart templates within Cart Contents query loops, and in order templates for order details. Modifiers are appended with colon, e.g. {woo_product_price:value}.',
			'tags'           => $tags,
			'template_hooks' => $template_hooks,
		);
	}

	/**
	 * Get default titles for WooCommerce template types.
	 *
	 * @return array<string, string> Map of template type slug to default title.
	 */
	private function get_woocommerce_default_titles(): array {
		return array(
			'wc_product'      => 'Single Product',
			'wc_archive'      => 'Product Archive',
			'wc_cart'         => 'Shopping Cart',
			'wc_cart_empty'   => 'Empty Cart',
			'wc_checkout'     => 'Checkout',
			'wc_account_form' => 'Account Login / Register',
			'wc_account_page' => 'My Account',
			'wc_thankyou'     => 'Thank You',
		);
	}

	/**
	 * Get pre-populated element scaffold for a WooCommerce template type.
	 *
	 * Returns a simplified nested element array for the given template type.
	 * Element names are based on Bricks Builder documentation and may need
	 * updating if Bricks changes its internal registration names.
	 *
	 * @param string $template_type WooCommerce template type slug.
	 * @return array<int, array<string, mixed>> Simplified nested element array.
	 */
	private function get_woocommerce_scaffold( string $template_type ): array {
		return match ( $template_type ) {
			'wc_product'      => $this->get_scaffold_wc_product(),
			'wc_archive'      => $this->get_scaffold_wc_archive(),
			'wc_cart'         => $this->get_scaffold_wc_cart(),
			'wc_cart_empty'   => $this->get_scaffold_wc_cart_empty(),
			'wc_checkout'     => $this->get_scaffold_wc_checkout(),
			'wc_account_form' => $this->get_scaffold_wc_account_form(),
			'wc_account_page' => $this->get_scaffold_wc_account_page(),
			'wc_thankyou'     => $this->get_scaffold_wc_thankyou(),
			default           => array(),
		};
	}

	/**
	 * Scaffold: Single Product template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_product(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'                  => 'row',
							'_justifyContent'             => 'space-between',
							'_direction:mobile_portrait'  => 'column',
						),
						'children' => array(
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '50%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'product-gallery', 'settings' => array() ),
								),
							),
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '45%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'woocommerce-breadcrumbs', 'settings' => array() ),
									array( 'name' => 'product-title', 'settings' => array() ),
									array( 'name' => 'product-rating', 'settings' => array() ),
									array( 'name' => 'product-price', 'settings' => array() ),
									array( 'name' => 'product-short-description', 'settings' => array() ),
									array( 'name' => 'product-add-to-cart', 'settings' => array() ),
									array( 'name' => 'product-meta', 'settings' => array() ),
								),
							),
						),
					),
				),
			),
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'children' => array(
							array( 'name' => 'product-tabs', 'settings' => array() ),
						),
					),
				),
			),
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'children' => array(
							array( 'name' => 'product-upsells', 'settings' => array() ),
						),
					),
				),
			),
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'children' => array(
							array( 'name' => 'product-related', 'settings' => array() ),
						),
					),
				),
			),
		);
	}

	/**
	 * Scaffold: Product Archive template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_archive(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'children' => array(
							array( 'name' => 'woocommerce-breadcrumbs', 'settings' => array() ),
							array( 'name' => 'woocommerce-products-archive-description', 'settings' => array() ),
						),
					),
				),
			),
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'      => 'row',
							'_justifyContent' => 'space-between',
							'_alignItems'     => 'center',
						),
						'children' => array(
							array( 'name' => 'woocommerce-products-total-results', 'settings' => array() ),
							array( 'name' => 'woocommerce-products-orderby', 'settings' => array() ),
						),
					),
				),
			),
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'                 => 'row',
							'_direction:mobile_portrait' => 'column',
						),
						'children' => array(
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '25%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'woocommerce-products-filter', 'settings' => array() ),
								),
							),
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '75%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'woocommerce-products', 'settings' => array() ),
									array( 'name' => 'woocommerce-products-pagination', 'settings' => array() ),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Scaffold: Cart template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_cart(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'children' => array(
		array(
								'name'     => 'heading',
								'settings' => array(
									'tag'  => 'h1',
									'text' => 'Shopping Cart',
								),
							),
						),
					),
				),
			),
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'                 => 'row',
							'_direction:mobile_portrait' => 'column',
						),
						'children' => array(
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '65%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'woocommerce-cart-items', 'settings' => array() ),
									array( 'name' => 'woocommerce-cart-coupon', 'settings' => array() ),
								),
							),
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '30%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'woocommerce-cart-collaterals', 'settings' => array() ),
									array(
										'name'     => 'container',
										'settings' => array(
											'_padding'    => array( 'top' => 'var(--space-m, 16px)', 'right' => 'var(--space-m, 16px)', 'bottom' => 'var(--space-m, 16px)', 'left' => 'var(--space-m, 16px)' ),
											'_background' => array( 'color' => array( 'raw' => 'var(--base-ultra-light, #f4f4f5)' ) ),
											'_border'     => array( 'radius' => array( 'top' => 'var(--radius, 8px)', 'right' => 'var(--radius, 8px)', 'bottom' => 'var(--radius, 8px)', 'left' => 'var(--radius, 8px)' ) ),
											'_direction'  => 'column',
											'_alignItems' => 'center',
										),
										'children' => array(
											array(
												'name'     => 'heading',
												'settings' => array(
													'tag'  => 'h4',
													'text' => 'Secure Checkout',
												),
											),
											array(
												'name'     => 'text-basic',
												'settings' => array( 'text' => 'Your payment information is processed securely. We do not store credit card details.' ),
											),
											array(
												'name'     => 'text-basic',
												'settings' => array( 'text' => '30-Day Money-Back Guarantee' ),
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Scaffold: Empty Cart template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_cart_empty(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'  => 'column',
							'_alignItems' => 'center',
							'_padding'    => array( 'top' => 'var(--space-3xl, 96px)', 'bottom' => 'var(--space-3xl, 96px)' ),
						),
						'children' => array(
		array(
								'name'     => 'heading',
								'settings' => array( 'tag' => 'h2', 'text' => 'Your cart is empty' ),
							),
							array(
								'name'     => 'text-basic',
								'settings' => array( 'text' => 'Browse our products and find something you love.' ),
							),
							array(
								'name'     => 'button',
								'settings' => array(
									'text' => 'Return to Shop',
									'link' => array( 'type' => 'external', 'url' => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '/shop' ),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Scaffold: Checkout template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_checkout(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'children' => array(
		array(
								'name'     => 'heading',
								'settings' => array( 'tag' => 'h1', 'text' => 'Checkout' ),
							),
							array( 'name' => 'woocommerce-account-form-login', 'settings' => array() ),
							array( 'name' => 'woocommerce-cart-coupon', 'settings' => array() ),
						),
					),
				),
			),
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'                 => 'row',
							'_direction:mobile_portrait' => 'column',
						),
						'children' => array(
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '60%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'woocommerce-checkout-customer-details', 'settings' => array() ),
								),
							),
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '35%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'woocommerce-checkout-order-review', 'settings' => array() ),
									array(
										'name'     => 'container',
										'settings' => array(
											'_padding'    => array( 'top' => 'var(--space-m, 16px)', 'right' => 'var(--space-m, 16px)', 'bottom' => 'var(--space-m, 16px)', 'left' => 'var(--space-m, 16px)' ),
											'_background' => array( 'color' => array( 'raw' => 'var(--base-ultra-light, #f4f4f5)' ) ),
											'_border'     => array( 'radius' => array( 'top' => 'var(--radius, 8px)', 'right' => 'var(--radius, 8px)', 'bottom' => 'var(--radius, 8px)', 'left' => 'var(--radius, 8px)' ) ),
											'_direction'  => 'column',
											'_alignItems' => 'center',
										),
										'children' => array(
											array(
												'name'     => 'heading',
												'settings' => array(
													'tag'  => 'h4',
													'text' => 'Secure Payment',
												),
											),
											array(
												'name'     => 'text-basic',
												'settings' => array( 'text' => 'SSL encrypted payment. Your information is safe.' ),
											),
											array(
												'name'     => 'text-basic',
												'settings' => array( 'text' => '100% Satisfaction Guarantee' ),
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Scaffold: Account Login / Register template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_account_form(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'  => 'column',
							'_alignItems' => 'center',
						),
						'children' => array(
		array(
								'name'     => 'heading',
								'settings' => array( 'tag' => 'h1', 'text' => 'My Account' ),
							),
							array(
								'name'     => 'container',
								'settings' => array(
									'_direction'                 => 'row',
									'_direction:mobile_portrait' => 'column',
									'_justifyContent'            => 'center',
								),
								'children' => array(
									array(
										'name'     => 'container',
										'settings' => array(
											'_width'                 => '45%',
											'_width:mobile_portrait' => '100%',
										),
										'children' => array(
											array( 'name' => 'woocommerce-account-form-login', 'settings' => array() ),
										),
									),
									array(
										'name'     => 'container',
										'settings' => array(
											'_width'                 => '45%',
											'_width:mobile_portrait' => '100%',
										),
										'children' => array(
											array( 'name' => 'woocommerce-account-form-register', 'settings' => array() ),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Scaffold: My Account page template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_account_page(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'children' => array(
		array(
								'name'     => 'heading',
								'settings' => array( 'tag' => 'h1', 'text' => 'My Account' ),
							),
							array( 'name' => 'woocommerce-account-page', 'settings' => array() ),
						),
					),
				),
			),
		);
	}

	/**
	 * Scaffold: Thank You template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_thankyou(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'  => 'column',
							'_alignItems' => 'center',
						),
						'children' => array(
							array(
								'name'     => 'heading',
								'settings' => array(
									'tag'  => 'h1',
									'text' => 'Thank You!',
								),
							),
							array(
								'name'     => 'text-basic',
								'settings' => array(
									'text'        => 'Your order has been placed successfully.',
									'_typography' => array( 'color' => array( 'raw' => 'var(--base-dark, #27272a)' ) ),
								),
							),
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'     => '100%',
									'_direction' => 'column',
								),
								'children' => array(
									array(
										'name'     => 'woocommerce-checkout-thankyou',
										'settings' => array(
											// Message section — success notice styling.
											'messageMargin'     => array( 'top' => 'var(--space-l, 24px)', 'right' => '0', 'bottom' => 'var(--space-l, 24px)', 'left' => '0' ),
											'messagePadding'    => array( 'top' => 'var(--space-m, 16px)', 'right' => 'var(--space-m, 16px)', 'bottom' => 'var(--space-m, 16px)', 'left' => 'var(--space-m, 16px)' ),
											'messageBackground' => array( 'raw' => 'var(--success-light, #DFF0D8)' ),
											'messageBorder'     => array(
												'radius' => array( 'top' => 'var(--radius, 8px)', 'right' => 'var(--radius, 8px)', 'bottom' => 'var(--radius, 8px)', 'left' => 'var(--radius, 8px)' ),
											),
											'messageTypography' => array( 'color' => array( 'raw' => 'var(--success-dark, #3C763D)' ) ),

											// Order details section.
											'detailsMargin'  => array( 'top' => 'var(--space-l, 24px)', 'right' => '0', 'bottom' => 'var(--space-l, 24px)', 'left' => '0' ),
											'detailsPadding' => array( 'top' => 'var(--space-s, 12px)', 'right' => 'var(--space-m, 16px)', 'bottom' => 'var(--space-s, 12px)', 'left' => 'var(--space-m, 16px)' ),
											'detailsBorder'  => array(
												'radius' => array( 'top' => 'var(--radius, 8px)', 'right' => 'var(--radius, 8px)', 'bottom' => 'var(--radius, 8px)', 'left' => 'var(--radius, 8px)' ),
											),

											// Action buttons in order details table (Bricks 2.3).
											'detailsActionButtonGap'        => 'var(--space-xs, 8px)',
											'detailsActionButtonPadding'    => array( 'top' => 'var(--space-xs, 8px)', 'right' => 'var(--space-s, 12px)', 'bottom' => 'var(--space-xs, 8px)', 'left' => 'var(--space-s, 12px)' ),
											'detailsActionButtonBackground' => array( 'raw' => 'var(--base-ultra-dark, #18181b)' ),
											'detailsActionButtonBorder'     => array(
												'radius' => array( 'top' => 'var(--radius, 8px)', 'right' => 'var(--radius, 8px)', 'bottom' => 'var(--radius, 8px)', 'left' => 'var(--radius, 8px)' ),
											),
											'detailsActionButtonTypography' => array( 'color' => array( 'raw' => 'var(--white, #ffffff)' ) ),

											// Order again button (Bricks 2.3).
											'orderAgainButtonPadding'    => array( 'top' => 'var(--space-s, 12px)', 'right' => 'var(--space-m, 16px)', 'bottom' => 'var(--space-s, 12px)', 'left' => 'var(--space-m, 16px)' ),
											'orderAgainButtonBackground' => array( 'raw' => 'var(--base-ultra-dark, #18181b)' ),
											'orderAgainButtonBorder'     => array(
												'radius' => array( 'top' => 'var(--radius, 8px)', 'right' => 'var(--radius, 8px)', 'bottom' => 'var(--radius, 8px)', 'left' => 'var(--radius, 8px)' ),
											),
											'orderAgainButtonTypography' => array( 'color' => array( 'raw' => 'var(--white, #ffffff)' ) ),

											// Failed order buttons (Bricks 2.3).
											'failedOrderButtonPadding'    => array( 'top' => 'var(--space-s, 12px)', 'right' => 'var(--space-m, 16px)', 'bottom' => 'var(--space-s, 12px)', 'left' => 'var(--space-m, 16px)' ),
											'failedOrderButtonBackground' => array( 'raw' => 'var(--danger, #D9534F)' ),
											'failedOrderButtonBorder'     => array(
												'radius' => array( 'top' => 'var(--radius, 8px)', 'right' => 'var(--radius, 8px)', 'bottom' => 'var(--radius, 8px)', 'left' => 'var(--radius, 8px)' ),
											),
											'failedOrderButtonTypography' => array( 'color' => array( 'raw' => 'var(--white, #ffffff)' ) ),

											// Billing address section.
											'addressMargin' => array( 'top' => 'var(--space-l, 24px)', 'right' => '0', 'bottom' => 'var(--space-l, 24px)', 'left' => '0' ),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * WooCommerce: Scaffold a single template.
	 *
	 * Creates a pre-populated WooCommerce template with standard elements,
	 * auto-assigned conditions, and responsive settings.
	 *
	 * @param array<string, mixed> $args Tool arguments. Required: template_type.
	 * @return array<string, mixed>|\WP_Error Created template data or error.
	 */
	private function tool_woocommerce_scaffold_template( array $args ): array|\WP_Error {
		$template_type = $args['template_type'] ?? '';
		$valid_types   = array( 'wc_product', 'wc_archive', 'wc_cart', 'wc_cart_empty', 'wc_checkout', 'wc_account_form', 'wc_account_page', 'wc_thankyou' );

		if ( '' === $template_type ) {
			return new \WP_Error(
				'missing_template_type',
				sprintf(
					__( 'template_type is required. Valid types: %s', 'bricks-mcp' ),
					implode( ', ', $valid_types )
				)
			);
		}

		if ( ! in_array( $template_type, $valid_types, true ) ) {
			return new \WP_Error(
				'invalid_template_type',
				sprintf(
					__( 'Invalid template_type "%s". Valid types: %s', 'bricks-mcp' ),
					$template_type,
					implode( ', ', $valid_types )
				)
			);
		}

		$default_titles = $this->get_woocommerce_default_titles();
		$title          = $args['title'] ?? $default_titles[ $template_type ] ?? $template_type;
		$status         = $args['status'] ?? 'publish';

		// Check for existing templates of this type.
		$existing_warning = null;
		$existing_query   = new \WP_Query(
			array(
				'post_type'      => 'bricks_template',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_bricks_template_type',
						'value' => $template_type,
					),
				),
			)
		);

		if ( $existing_query->found_posts > 0 ) {
			$existing_id      = $existing_query->posts[0];
			$existing_warning = sprintf(
				'A %s template already exists (ID: %d, title: "%s"). Creating another — the one with the higher condition score or later creation date will take priority.',
				$template_type,
				$existing_id,
				get_the_title( $existing_id )
			);
		}

		// Create template.
		$template_id = $this->bricks_service->create_template(
			array(
				'title'  => $title,
				'type'   => $template_type,
				'status' => $status,
			)
		);

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		// Save pre-populated elements (scaffold uses simplified nested format, normalize to flat).
		$elements    = $this->get_woocommerce_scaffold( $template_type );
		$elements    = $this->bricks_service->normalize_elements( $elements );
		$save_result = $this->bricks_service->save_elements( $template_id, $elements );

		if ( is_wp_error( $save_result ) ) {
			wp_delete_post( $template_id, true );
			return new \WP_Error(
				'scaffold_save_failed',
				sprintf(
					__( 'Template created but element save failed: %s. Template has been rolled back.', 'bricks-mcp' ),
					$save_result->get_error_message()
				)
			);
		}

		// Auto-assign condition.
		$condition_result = $this->bricks_service->set_template_conditions(
			$template_id,
			array( array( 'main' => $template_type ) )
		);

		// Count elements saved (uses resolver for correct meta key).
		$saved_content = $this->bricks_service->get_elements( $template_id );
		$element_count = count( $saved_content );

		$result = array(
			'template_id'         => $template_id,
			'title'               => $title,
			'type'                => $template_type,
			'status'              => $status,
			'condition_assigned'  => $template_type,
			'element_count'       => $element_count,
			'customization_hints' => array(
				'Use template:get to view the full element tree',
				'Use element:update to modify individual element settings',
				'Use element:add to insert additional elements',
				'Use element:remove to remove unwanted elements',
				'Use get_builder_guide(section="woocommerce") for WooCommerce building patterns',
			),
		);

		if ( null !== $existing_warning ) {
			$result['warning'] = $existing_warning;
		}

		if ( is_wp_error( $condition_result ) ) {
			$result['condition_warning'] = 'Template created but condition assignment failed: ' . $condition_result->get_error_message();
		}

		return $result;
	}

	/**
	 * WooCommerce: Scaffold all essential templates.
	 *
	 * Creates pre-populated templates for all (or specified) WooCommerce types.
	 *
	 * @param array<string, mixed> $args Tool arguments. Optional: types, skip_existing.
	 * @return array<string, mixed>|\WP_Error Summary of created/skipped templates.
	 */
	private function tool_woocommerce_scaffold_store( array $args ): array|\WP_Error {
		$all_types     = array( 'wc_product', 'wc_archive', 'wc_cart', 'wc_cart_empty', 'wc_checkout', 'wc_account_form', 'wc_account_page', 'wc_thankyou' );
		$types         = $args['types'] ?? $all_types;
		$skip_existing = $args['skip_existing'] ?? true;

		// Validate types.
		foreach ( $types as $type ) {
			if ( ! in_array( $type, $all_types, true ) ) {
				return new \WP_Error(
					'invalid_template_type',
					sprintf(
						__( 'Invalid template type "%s" in types array. Valid types: %s', 'bricks-mcp' ),
						$type,
						implode( ', ', $all_types )
					)
				);
			}
		}

		$created = array();
		$skipped = array();
		$failed  = array();

		foreach ( $types as $type ) {
			// Check for existing.
			if ( $skip_existing ) {
				$existing_query = new \WP_Query(
					array(
						'post_type'      => 'bricks_template',
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							array(
								'key'   => '_bricks_template_type',
								'value' => $type,
							),
						),
					)
				);

				if ( $existing_query->found_posts > 0 ) {
					$skipped[] = array(
						'type'                 => $type,
						'reason'               => 'Template already exists',
						'existing_template_id' => $existing_query->posts[0],
					);
					continue;
				}
			}

			// Create scaffold.
			$result = $this->tool_woocommerce_scaffold_template(
				array(
					'template_type' => $type,
					'status'        => $args['status'] ?? 'publish',
				)
			);

			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'type'  => $type,
					'error' => $result->get_error_message(),
				);
			} else {
				$created[] = array(
					'template_id' => $result['template_id'],
					'title'       => $result['title'],
					'type'        => $result['type'],
					'condition'   => $result['condition_assigned'],
				);
			}
		}

		if ( count( $created ) === 0 && count( $skipped ) === 0 ) {
			return new \WP_Error(
				'scaffold_store_failed',
				__( 'All template scaffolds failed. Check WooCommerce and Bricks are properly configured.', 'bricks-mcp' )
			);
		}

		return array(
			'created'       => $created,
			'skipped'       => $skipped,
			'failed'        => $failed,
			'total_created' => count( $created ),
			'total_skipped' => count( $skipped ),
			'total_failed'  => count( $failed ),
			'next_steps'    => 'Use template:get to view and customize individual templates. Use page:update_content or element:add/update to modify elements. Use get_builder_guide(section="woocommerce") for patterns.',
		);
	}

	/**
	 * Register the woocommerce tool with the given registry.
	 *
	 * @param ToolRegistry $registry Tool registry instance.
	 * @return void
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'woocommerce',
			__( "WooCommerce builder tools. Requires WooCommerce active.\n\nActions: status, get_elements, get_dynamic_tags, scaffold_template, scaffold_store (create all WC templates).", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'        => array(
						'type'        => 'string',
						'enum'        => array( 'status', 'get_elements', 'get_dynamic_tags', 'scaffold_template', 'scaffold_store' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'category'      => array(
						'type'        => 'string',
						'description' => __( 'Filter category (get_elements: product, cart, checkout, account, archive, utility; get_dynamic_tags: product_price, product_display, product_info, cart, order, post_compatible)', 'bricks-mcp' ),
					),
					'template_type' => array(
						'type'        => 'string',
						'enum'        => array( 'wc_product', 'wc_archive', 'wc_cart', 'wc_cart_empty', 'wc_checkout', 'wc_account_form', 'wc_account_page', 'wc_thankyou' ),
						'description' => __( 'WooCommerce template type (scaffold_template: required)', 'bricks-mcp' ),
					),
					'title'         => array(
						'type'        => 'string',
						'description' => __( 'Custom template title (scaffold_template: optional, defaults to human-readable name)', 'bricks-mcp' ),
					),
					'status'        => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft' ),
						'description' => __( 'Template post status (scaffold_template: optional, default publish)', 'bricks-mcp' ),
					),
					'types'         => array(
						'type'        => 'array',
						'description' => __( 'Specific template types to scaffold (scaffold_store: optional, defaults to all 8 types)', 'bricks-mcp' ),
					),
					'skip_existing' => array(
						'type'        => 'boolean',
						'description' => __( 'Skip types that already have a template (scaffold_store: optional, default true)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'handle' )
		);
	}
}
