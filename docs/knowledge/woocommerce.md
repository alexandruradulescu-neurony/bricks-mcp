# WooCommerce in Bricks Builder

## Prerequisites

WooCommerce plugin must be active. Check with `woocommerce:status`.

## Template Types

Bricks uses custom templates for each WooCommerce page:

| Template Type | Purpose |
|---------------|---------|
| `wc_product` | Single product page |
| `wc_archive` | Shop page and product category archives |
| `wc_cart` | Cart page (with items) |
| `wc_cart_empty` | Empty cart state |
| `wc_checkout` | Checkout page |
| `wc_account_form` | Login/register form |
| `wc_account_page` | My account dashboard |
| `wc_thankyou` | Order confirmation |

## Scaffolding Templates

Scaffold all templates at once:
```
woocommerce:scaffold_store
```

Options:
- `skip_existing: true` (default) -- won't overwrite existing templates
- `types` -- array to scaffold only specific types
- `status` -- publish or draft

Scaffold a single template:
```
woocommerce:scaffold_template with template_type: "wc_product"
```

## WooCommerce Elements

Bricks provides WC-specific elements for product pages:
- Product title, price, description, short description
- Add to cart button, quantity selector
- Product images, gallery
- Product tabs (description, reviews, additional info)
- Related products, upsells
- Cart table, cart totals
- Checkout form fields, order review

Use `woocommerce:get_elements` for the full list. Filter by category:
- `product` - single product elements
- `cart` - cart page elements
- `checkout` - checkout elements
- `account` - account page elements
- `archive` - shop/category archive elements

## WooCommerce Dynamic Tags

Product-specific dynamic data tags:
- `{wc_product_price}`, `{wc_product_regular_price}`, `{wc_product_sale_price}`
- `{wc_product_title}`, `{wc_product_sku}`
- `{wc_product_stock_status}`, `{wc_product_stock_quantity}`

Use `woocommerce:get_dynamic_tags` for the full list. Filter by category for focused results.

## Common Pitfalls

1. Building product pages without the `wc_product` template type -- WooCommerce hooks won't fire
2. Missing template conditions -- WC templates need conditions like "WooCommerce single product" to display
3. Using generic elements instead of WC-specific ones -- WC elements handle cart/checkout logic automatically
4. Scaffold creates basic structures -- customize after scaffolding for the desired design

## Reference

Use `woocommerce:get_elements` and `woocommerce:get_dynamic_tags` for complete element and tag lists.
