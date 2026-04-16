# WooCommerce in Bricks Builder

## Related Knowledge

- `building` — page structure, schema rules (WC templates follow same section > container > block pattern)
- `templates` — WC uses 8 template types with auto-conditions; see template scoring system
- `query-loops` — Cart Contents query loop for cart pages, product archive loops
- `dynamic-data` — `{woo_*}` tags use same syntax as core dynamic data; modifier colon format
- `popups` — WC Quick View uses popup AJAX mode (`popupIsWoo: true`)
- `forms` — checkout forms are NOT Bricks form elements; they're native WC checkout fields

## Prerequisites

WooCommerce plugin must be active. Check with `woocommerce:status` — returns installed version, WC page IDs, active theme, and whether key pages are set up.

## Template Types (8)

Bricks uses custom templates for each WooCommerce page. Each template type must:
1. Be created via `template:create(type=TYPE)`
2. Have the correct template condition (auto-set by `scaffold_template`)

| Type | Purpose | Condition |
|---|---|---|
| `wc_product` | Single product page | WooCommerce single product |
| `wc_archive` | Shop page + product category archives | WooCommerce shop / product archive |
| `wc_cart` | Cart page (with items) | WooCommerce cart page |
| `wc_cart_empty` | Empty cart state | WooCommerce cart (empty) |
| `wc_checkout` | Checkout page | WooCommerce checkout page |
| `wc_account_form` | Login / register form | WooCommerce account (logged out) |
| `wc_account_page` | My Account dashboard | WooCommerce account (logged in) |
| `wc_thankyou` | Order confirmation | WooCommerce thank you page |

## Scaffolding

Create all templates at once:
```json
{ "action": "scaffold_store" }
```
Options:
- `skip_existing: true` (default) — won't overwrite existing templates
- `types: ["wc_product", "wc_cart"]` — scaffold only specific types (array of any of the 8 types above)
- `status: "draft"` — create as draft instead of publish

Single template:
```json
{ "action": "scaffold_template", "template_type": "wc_product" }
```

Scaffold creates basic structures with essential WC elements. Customize after scaffolding.

## WooCommerce Elements (6 categories)

Bricks ships WC-specific elements that handle cart/checkout/product logic automatically. Use `woocommerce:get_elements` to list them. Filter by category:

| Category | Use | Example elements |
|---|---|---|
| `product` | Single product page | product-title, product-price, product-gallery, add-to-cart, product-tabs, product-rating, product-stock, product-meta |
| `cart` | Cart page | cart-table, cart-totals, cross-sells |
| `checkout` | Checkout page | checkout-form-billing, checkout-form-shipping, checkout-order-review, checkout-payment |
| `account` | My Account page | account-navigation, account-content, account-orders |
| `archive` | Shop / category page | product-archive, product-filters, product-sorting, result-count |
| `utility` | Cross-page | WC notices, breadcrumbs, mini-cart |

**Always use WC-specific elements** for WC pages — they wire into WooCommerce's hooks + AJAX automatically. Building a "cart" from generic blocks/headings won't process cart updates.

## WooCommerce Dynamic Tags

Tags use `{woo_*}` prefix (NOT `{wc_*}`). Modifiers appended with colon: `{woo_product_price:value}`.

Use `woocommerce:get_dynamic_tags` for full list. Filter by category:

| Category | Purpose | Example tags |
|---|---|---|
| `product_price` | Price display | `{woo_product_price}`, `{woo_product_price:value}` (raw number), `{woo_product_regular_price}`, `{woo_product_sale_price}` |
| `product_display` | Visual product data | `{woo_product_image}`, `{woo_product_gallery}`, `{woo_product_short_description}` |
| `product_info` | Product metadata | `{woo_product_sku}`, `{woo_product_stock_status}`, `{woo_product_stock_quantity}`, `{woo_product_weight}`, `{woo_product_dimensions}` |
| `cart` | Cart context | Cart totals, item counts (used inside Cart Contents query loops) |
| `order` | Order/thank-you | Order details, status, totals (used in wc_thankyou templates) |
| `post_compatible` | Works on products + posts | Tags that work in both product and regular post contexts |

### Usage context
- **Product templates** (`wc_product`): all `product_*` tags resolve
- **Cart templates** (`wc_cart`): `cart` tags inside a Cart Contents query loop
- **Thank-you templates** (`wc_thankyou`): `order` tags resolve to completed order
- **Archive templates** (`wc_archive`): `product_*` tags resolve per product in query loop

## Quick View Popups

WooCommerce Quick View uses Bricks popups in AJAX mode:

```json
{
  "popupAjax": true,
  "popupIsWoo": true
}
```

Set these via `template:set_popup_settings` on a popup-type template. See `bricks:get_knowledge('popups')` for full popup docs.

## Common Pitfalls

1. **Wrong tag prefix** — `{wc_product_price}` does NOT work. Use `{woo_product_price}`.
2. **Building WC pages without WC templates** — WooCommerce hooks (add to cart, checkout validation, account tabs) only fire inside the correct template type. A regular page with generic elements won't work.
3. **Missing template conditions** — scaffolding auto-sets conditions, but manual templates need conditions like "WooCommerce single product" via `template_condition:set`.
4. **Generic elements instead of WC elements** — a `heading` with `{woo_product_title}` works for display but misses WC structured data hooks. Use the native `product-title` element.
5. **Checkout form as Bricks form** — the checkout is NOT a Bricks form element. It uses WooCommerce's native checkout fields. Use `checkout-form-billing`, `checkout-form-shipping`, etc.
6. **Cart tags outside query loop** — `cart` dynamic tags resolve inside a Cart Contents query loop (set on a block/container). Using them outside the loop returns empty.
7. **`scaffold_store` on existing site** — `skip_existing: true` (default) is safe. Set `false` to overwrite, but destructive.

## Reference

- `woocommerce:status` — installed version, page IDs, setup checks
- `woocommerce:get_elements(category?)` — all WC elements with name, label, description
- `woocommerce:get_dynamic_tags(category?)` — all WC tags with modifiers
- `woocommerce:scaffold_store(skip_existing?, types?, status?)` — create all templates
- `woocommerce:scaffold_template(template_type, status?)` — create single template
