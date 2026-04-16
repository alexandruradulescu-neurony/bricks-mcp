# Dynamic Data in Bricks Builder

## Related Knowledge

- `building` — schema rules, where dynamic tags fit in element settings
- `query-loops` — query loop setup, nested loops, pagination — dynamic tags resolve per iteration inside loops
- `components` — wrapping dynamic data in reusable components via property connections
- `woocommerce` — WooCommerce-specific dynamic tags (product price, stock, etc.)
- **Live tag discovery (authoritative):** `bricks:get_dynamic_tags` — full list of 70+ tags across 12 groups. Filter by group: `bricks:get_dynamic_tags(group="post"|"author"|"terms"|...)`.
- **Meta Box fields:** `metabox:get_dynamic_tags(post_type)` — separate tool; Meta Box exposes its own dynamic tags per field group.

## Tag Syntax

Tags use curly braces and resolve at render time:
```
{tag_name}
```

Some tags accept an argument after a colon — used for meta keys, URL params, format strings, taxonomy slugs:
```
{author_meta:linkedin_url}
{wp_user_meta:custom_field}
{url_parameter:utm_source}
{term_meta:hero_image}
{post_terms_category}        ← built-in pattern: {post_terms_TAXONOMY}
{post_terms_post_tag}
{post_terms_my_custom_tax}
```

Always verify exact format via `bricks:get_dynamic_tags(group=...)` — colon-arg support varies per tag.

## Tag Groups (12)

| Group | Count | Examples | Use |
|---|---|---|---|
| `post` | 14 | `{post_title}`, `{post_url}`, `{post_excerpt}`, `{featured_image}`, `{read_more}` | Current post data |
| `terms` | 12 | `{term_name}`, `{term_url}`, `{post_terms_category}`, `{term_meta:KEY}` | Taxonomy / category data |
| `userProfile` | 15 | `{wp_user_display_name}`, `{wp_user_email}`, `{wp_user_picture}`, `{wp_user_meta:KEY}` | Logged-in WP user (front-end) |
| `author` | 8 | `{author_name}`, `{author_avatar}`, `{author_archive_url}`, `{author_meta:KEY}` | Post author |
| `site` | 6 | `{site_title}`, `{site_url}`, `{site_login}`, `{url_parameter:KEY}` | Site / request context |
| `query` | 4 | `{query_loop_index}`, `{query_results_count}`, `{query_api}`, `{query_array}` | Inside a query loop |
| `queryFilters` | 3 | `{query_results_count_filter}`, `{active_filters_count}`, `{search_term_filter}` | With Bricks query filters |
| `date` | 3 | `{current_date}`, `{current_wp_date}`, `{format_date}` | Date helpers |
| `archive` | 2 | `{archive_title}`, `{archive_description}` | Archive templates |
| `misc` | 1 | `{search_term}` | Search results page |
| `advanced` | 2 | `{echo}`, `{do_action}` | PHP function output, WP action firing |

## Format by Field Type

Different setting shapes accept dynamic data differently. **Always check the field's expected shape first** via `bricks:get_element_schemas(element=NAME)`.

**Text fields** (heading text, button text, rich text, label):
```json
{ "text": "{post_title}" }
{ "text": "Welcome, {wp_user_display_name}!" }   ← inline mixing OK
```

**Image fields** (image element, background image):
```json
{ "image": { "useDynamicData": "{featured_image}" } }
{ "_background": { "image": { "useDynamicData": "{featured_image}" } } }
```

**Link fields** (button link, wrapper link):
```json
{ "link": { "type": "dynamic", "dynamicData": "{post_url}" } }
{ "link": { "type": "dynamic", "dynamicData": "{author_archive_url}" } }
```

**HTML attributes** (via `_attributes`):
```json
{ "_attributes": [
    { "name": "data-post-id", "value": "{post_id}" }
  ] }
```

**Element conditions** (show/hide based on data — preferred over fallback content):
```json
{ "_conditions": [
    [ { "key": "{wp_user_role}", "compare": "==", "value": "subscriber" } ]
  ] }
```

## Context Requirement

Tags resolve based on the active context — wrong context = empty render:

| Context | What resolves |
|---|---|
| Single post template | Tags resolve to displayed post |
| Query loop children | Tags resolve to each iterated post per iteration |
| Archive template | `{archive_*}`, `{term_*}`, page-level tags work |
| Static page (no template) | Only own-page tags resolve; many post-context tags empty |
| Logged-out front-end | `{wp_user_*}` returns empty |

## Power Patterns

### Format dates
```json
{ "text": "{format_date:F j, Y:post_date}" }     ← syntax varies; verify via get_dynamic_tags
{ "text": "Updated {post_modified}" }
```

### Custom meta access
```json
// Author meta (custom user meta key)
{ "text": "Connect on {author_meta:linkedin_url}" }

// Term meta (taxonomy meta key)
{ "_background": { "image": { "useDynamicData": "{term_meta:hero_image}" } } }

// URL query parameter
{ "text": "Search: {url_parameter:q}" }
```

### Output PHP function
```json
{ "text": "{echo:my_helper_function}" }
```
Requires `unfiltered_html` capability. Subject to security filters — see Bricks docs.

### WordPress action firing
```json
{ "_cssCustom": "{do_action:my_custom_hook}" }
```

### ACF / Meta Box / Pods integration
- ACF: tags appear as `{acf_FIELD_NAME}` (when ACF is active)
- Meta Box: discovered via `metabox:get_dynamic_tags(post_type)` MCP action
- Pods: similar pattern `{pods_FIELD_NAME}` (when Pods is active)

Always call `bricks:get_dynamic_tags` to see what's actually registered on this site — third-party plugins inject their own tags.

## Query Loops

Set `query` settings on a container or block to repeat its children for each result:

```json
{
  "name": "block",
  "settings": {
    "query": {
      "objectType": "post",
      "post_type": ["post"],
      "posts_per_page": 6,
      "orderby": "date",
      "order": "DESC"
    }
  },
  "children": [...]   ← these repeat per result
}
```

Inside the loop:
- `{post_*}` tags resolve to the iterated post
- `{query_loop_index}` = current iteration (0-based)
- `{query_results_count}` = total results in this loop

**Discover query types** via `bricks:get_query_types` for the full schema (post, term, user, custom).

**Manage reusable queries** via `bricks:set_global_query` / `get_global_queries`.

## Query Filters (Bricks 1.12+)

Bricks ships a query-filter system (search, term filter, range, etc.) tied to a query loop:
- `{search_term_filter}` — current search input value
- `{query_results_count_filter}` — count after filters applied
- `{active_filters_count}` — number of active filters

Filter elements have their own settings — inspect via `bricks:get_filter_schema`.

## Common Pitfalls

1. **Wrong format per field type** — `{featured_image}` in a text field outputs the URL string, not an image. Use `useDynamicData` on image fields.
2. **Static page assumption** — page templates have full post context; static pages only have their own data. Many `{post_*}` tags empty on static pages.
3. **Nested query loops** — inner-loop tags resolve to inner context, not outer. No automatic outer access; capture outer values into globals or use parent variables.
4. **Empty values render as empty string** — use `_conditions` to hide elements when data missing (cleaner than fallback text).
5. **Inventing tags** — only registered tags resolve. `{my_made_up_tag}` renders literally as that string. Always discover via `bricks:get_dynamic_tags` first.
6. **Wrong colon-arg syntax** — `{author_meta:linkedin_url}` works; `{author_meta(linkedin_url)}` doesn't. Format is colon-separated.
7. **`{echo}` security** — requires `unfiltered_html` cap; sanitization rules apply. Don't expect arbitrary PHP execution.
8. **Logged-out user tags** — `{wp_user_*}` are empty when no user is logged in. Pair with `_conditions` checking `{wp_user_id}`.

## Reference

- `bricks:get_dynamic_tags` — full list (no group filter)
- `bricks:get_dynamic_tags(group="post")` — single group
- `bricks:get_query_types` — query loop schema
- `bricks:get_filter_schema` — query filter elements
- `metabox:get_dynamic_tags(post_type)` — Meta Box field tags
