# Query Loops in Bricks Builder

## Related Knowledge

- `building` — page structure (query loops go on layout elements), CSS variables
- `dynamic-data` — `{post_*}`, `{term_*}`, `{query_loop_index}` tags resolve per iteration inside loops
- `woocommerce` — Cart Contents query loop for cart/checkout pages
- `forms` — `formSuccess`/`formError` triggers can refresh query loops via AJAX
- **Live query schema (authoritative):** `bricks:get_query_types` — all 5 query types with settings + pagination options. Always current.

## Setup

To create a query loop, set two keys on any layout element (container, div, block):

```json
{
  "name": "block",
  "settings": {
    "hasLoop": true,
    "query": {
      "objectType": "post",
      "post_type": ["post"],
      "posts_per_page": 6,
      "orderby": "date",
      "order": "DESC"
    }
  },
  "children": [...]
}
```

- `hasLoop: true` — REQUIRED, activates looping
- `query` — object with query settings (varies by objectType)
- Children of the loop element repeat for each result
- Dynamic tags inside children resolve per iteration

## Query Types (5)

### `post` — WP_Query (most common)

```json
{
  "objectType": "post",
  "post_type": ["post"],
  "posts_per_page": 6,
  "orderby": "date",
  "order": "DESC"
}
```

| Key | Type | Use |
|---|---|---|
| `post_type` | array | Post type slugs: `["post"]`, `["page"]`, `["portfolio"]` |
| `posts_per_page` | integer | Results per page. `-1` = all. |
| `orderby` | string | `date`, `title`, `ID`, `modified`, `comment_count`, `rand`, `menu_order` |
| `order` | string | `ASC` or `DESC` |
| `offset` | integer | Skip N posts |
| `ignoreStickyPosts` | boolean | Ignore sticky posts |
| `excludeCurrentPost` | boolean | Exclude currently displayed post |
| `taxonomyQuery` | array | Taxonomy filter objects (category, tag, custom taxonomy) |
| `metaQuery` | array | Custom field filter objects (meta_key comparisons) |
| `is_main_query` | boolean | **REQUIRED true for archive templates** — prevents 404 on pagination |

### `term` — WP_Term_Query

```json
{
  "objectType": "term",
  "taxonomies": ["category"],
  "orderby": "name",
  "order": "ASC",
  "number": 12,
  "hideEmpty": true
}
```

| Key | Type | Use |
|---|---|---|
| `taxonomies` | array | Taxonomy slugs: `["category"]`, `["product_cat"]` |
| `orderby` | string | `name`, `count`, `term_id`, `parent` |
| `number` | integer | Terms per page |
| `hideEmpty` | boolean | Hide terms with no posts |

### `user` — WP_User_Query

```json
{
  "objectType": "user",
  "roles": ["author"],
  "orderby": "display_name",
  "number": 10
}
```

| Key | Type | Use |
|---|---|---|
| `roles` | array | Role slugs: `["author"]`, `["subscriber"]` |
| `orderby` | string | `display_name`, `registered`, `post_count`, `user_login` |
| `number` | integer | Users per page |

### `api` — REST API (Bricks 2.1+)

```json
{
  "objectType": "api",
  "api_url": "https://jsonplaceholder.typicode.com/posts",
  "api_method": "GET",
  "response_path": "data.items",
  "cache_time": 300
}
```

| Key | Type | Use |
|---|---|---|
| `api_url` | string | Full endpoint URL. Supports dynamic tags. REQUIRED. |
| `api_method` | string | `GET` (default), `POST`, `PUT`, `PATCH`, `DELETE` |
| `response_path` | string | Dot-notation path to extract array from JSON response (e.g. `"data.items"`). Required for most APIs. |
| `api_auth_type` | string | `none` (default), `apiKey`, `bearer`, `basic` |
| `api_params` | array | Query params: `[{key, value}]` |
| `api_headers` | array | Request headers: `[{key, value}]` |
| `cache_time` | integer | Cache seconds (default 300 = 5min; 0 = disable) |
| `pagination_enabled` | boolean | Enable AJAX pagination for API results |

### `array` — Static data (Bricks 2.2+)

```json
{
  "objectType": "array",
  "arrayEditor": "[{\"name\":\"Item 1\"},{\"name\":\"Item 2\"}]"
}
```

⚠️ Requires code execution enabled in Bricks settings. `arrayEditor` accepts PHP code returning an array or a JSON literal string.

## Dynamic Tags Inside Loops

| Tag | Resolves to |
|---|---|
| `{post_title}` | Current iterated post title |
| `{post_url}` | Current iterated post URL |
| `{featured_image}` | Current post's featured image |
| `{term_name}` | Current iterated term name |
| `{query_loop_index}` | 0-based iteration index |
| `{query_results_count}` | Total results in this loop |
| `{query_api}` / `{query_array}` | Access API/array response fields |

See `bricks:get_knowledge('dynamic-data')` for full tag reference.

## Pagination

### Infinite scroll (AJAX auto-load on scroll)

Set inside the query object:
```json
{
  "query": {
    "objectType": "post",
    "posts_per_page": 6,
    "infinite_scroll": true,
    "infinite_scroll_margin": "200px",
    "infinite_scroll_delay": 500,
    "ajax_loader_animation": "default"
  }
}
```

### Load-more button (manual)

Add `_interactions` on a button OUTSIDE the query loop:
```json
{
  "name": "button",
  "settings": {
    "text": "Load More",
    "_interactions": [
      {
        "id": "lm01ab",
        "trigger": "click",
        "action": "loadMore",
        "loadMoreQuery": "<query_element_id>"
      }
    ]
  }
}
```

`loadMoreQuery` = the element ID of the block/container with `hasLoop: true`.

**Infinite scroll and load-more are mutually exclusive.** Pick one per loop.

## Global Queries (reusable named queries)

Instead of inline settings, reference a saved global query by ID:

```json
{
  "hasLoop": true,
  "query": { "id": "saved_query_id" }
}
```

Bricks resolves the global query at render time. Manage via:
- `bricks:get_global_queries` — list all saved queries
- `bricks:set_global_query(name, settings, category?)` — create/update
- `bricks:delete_global_query(query_id)` — remove

Global queries are shared across all pages — change once, updates everywhere.

## Nested Loops

Inner loops have their own `query` object. Dynamic tags resolve to the INNER context:

```
block (query: {objectType: "term", taxonomies: ["category"]})
  heading: {term_name}                    ← outer term
  block (query: {objectType: "post", taxonomyQuery: [{...}]})
    heading: {post_title}                 ← inner post
    text: {query_loop_index}              ← inner index
```

No automatic outer-context access. To use outer values in inner content, capture via `_attributes` or global PHP vars.

## Archive Templates (critical rule)

For archive templates (blog, category, tag, custom taxonomy):

**ALWAYS set `is_main_query: true`** in the query object:

```json
{
  "hasLoop": true,
  "query": {
    "objectType": "post",
    "is_main_query": true
  }
}
```

Without this: pagination returns 404. The main query hooks into WordPress's native query instead of creating a separate one.

## Security

**Never set `useQueryEditor: true` or `queryEditor`** — these enable arbitrary PHP execution and are a security risk. The MCP plugin blocks these keys.

## Common Pitfalls

1. **Missing `hasLoop: true`** — `query` object alone does nothing. Both keys required.
2. **`posts_per_page` as string** — must be integer. `"6"` may work but `-1` (all) requires integer.
3. **Archive template without `is_main_query: true`** — pagination 404.
4. **`post_type` as string** — must be array: `["post"]` not `"post"`.
5. **`taxonomyQuery` shape** — needs `{taxonomy, field, terms, operator}` objects. Verify via `bricks:get_query_types`.
6. **Infinite scroll + load-more button** — mutually exclusive. Pick one.
7. **Nested loop context** — `{post_title}` inside inner loop = inner post, not outer. No automatic parent access.
8. **API rate limits** — `cache_time: 0` hits the API every page load. Default 300s is a sane minimum.
9. **`loadMoreQuery` wrong ID** — must be the loop ELEMENT id, not a query name or post ID.

## Reference

- `bricks:get_query_types` — all 5 types with settings + pagination options
- `bricks:get_global_queries` — list saved queries
- `bricks:set_global_query(name, settings)` — create/update
- `bricks:get_knowledge('dynamic-data')` — tags that resolve inside loops
- `bricks:get_knowledge('woocommerce')` — Cart Contents query loops
- `bricks:get_filter_schema` — query filter elements
