# Templates in Bricks Builder

## Related Knowledge

- `building` — section > container > block structure applies inside templates too
- `popups` — popup templates have their own display settings + trigger system
- `woocommerce` — 8 WC-specific template types with auto-conditions
- `query-loops` — archive templates need `is_main_query: true`

## What Templates Are

Templates are reusable Bricks content assigned to page contexts via conditions. They replace WordPress theme templates. One template can serve thousands of pages (e.g. a single "Blog Post" template for all posts).

## Template Types

| Type | Purpose | Typical use |
|---|---|---|
| `header` | Site header | Global across all pages |
| `footer` | Site footer | Global across all pages |
| `content` | Single post/page body | Blog post template, page template |
| `archive` | Archive pages (blog, category, tag) | Blog listing, category pages |
| `search` | Search results page | Custom search layout |
| `error` | 404 page | Custom not-found page |
| `section` | Reusable section (embeddable) | Section inserted on multiple pages |
| `popup` | Popup overlay | See `bricks:get_knowledge('popups')` |
| `password_protection` | Password-protected content overlay | Custom password gate |

Plus 8 WooCommerce types when WC is active — see `bricks:get_knowledge('woocommerce')`.

## Condition System (scoring)

Each template has conditions that define WHERE it applies. When multiple templates match, **highest score wins**.

| Score | Condition | `main` key | Extra fields |
|---|---|---|---|
| **10** | Specific post IDs | `ids` | `ids: [42, 99]`, optional `includeChildren: true` |
| **9** | Front page | `frontpage` | — |
| **8** | All posts of type | `postType` | `postType: "post"` |
| **8** | Specific taxonomy terms | `terms` | `terms: ["category::5", "post_tag::12"]` |
| **8** | Search results | `search` | — |
| **8** | 404 error | `error` | — |
| **7** | Archive for post type | `archivePostType` | `archivePostType: "post"` |
| **3** | Archive by type | `archiveType` | `archiveType: "author"` or `"date"` or `"tag"` or `"category"` |
| **2** | Entire website | `any` | — |

**Scoring logic:** specific ID (10) beats post type (8) beats entire site (2). If a header template targets `ids: [42]` and another targets `any`, page 42 gets the specific one.

## Workflow — Create a Template

```json
// Step 1: Create
{ "action": "create", "type": "header", "title": "Main Header" }

// Step 2: Add content (template is a post — same as page:update_content)
{ "action": "update_content", "post_id": <template_id>, "elements": [...] }

// Step 3: Set conditions
// template_condition:set
{ "template_id": <template_id>, "conditions": [{ "main": "any" }] }
```

## Available Actions

### `template` tool (11 actions)

| Action | Use |
|---|---|
| `list(type?, status?, tag?, bundle?)` | List templates. Filter by type, status, tag slug, bundle slug. |
| `get(template_id)` | Full content (elements + settings) |
| `create(type, title, elements?, conditions?)` | New template |
| `update(template_id, ...)` | Update title, type, status, tags, bundles |
| `delete(template_id, force?)` | Trash or permanent delete |
| `duplicate(template_id, title?)` | Copy template |
| `get_popup_settings(template_id)` | Read popup display settings (popup type only) |
| `set_popup_settings(template_id, settings)` | Write popup settings (null deletes key) |
| `export(template_id, include_classes?)` | Export as JSON (optional: include global classes used) |
| `import(template_data)` | Import from JSON object |
| `import_url(url)` | Import from remote URL |

### `template_condition` tool (3 actions)

| Action | Use |
|---|---|
| `get_types` | List all condition types with scores + required fields |
| `set(template_id, conditions)` | Set conditions (replaces all). Empty array = remove. |
| `resolve(post_id?, post_type?)` | Resolve which templates would apply for a given context |

### `template_taxonomy` tool (6 actions)

| Action | Use |
|---|---|
| `list_tags` / `list_bundles` | List taxonomy terms for organizing templates |
| `create_tag(name)` / `create_bundle(name)` | Create taxonomy terms |
| `delete_tag(term_id)` / `delete_bundle(term_id)` | Remove terms |

## Condition Object Shape

Always an array of objects. Each object has `main` plus type-specific fields:

```json
// Entire site
[{ "main": "any" }]

// Specific pages
[{ "main": "ids", "ids": [42, 99] }]

// All blog posts
[{ "main": "postType", "postType": "post" }]

// Category "news" (taxonomy::term_id format)
[{ "main": "terms", "terms": ["category::5"] }]

// Front page
[{ "main": "frontpage" }]

// Multiple conditions = OR logic (any match applies)
[
  { "main": "ids", "ids": [42] },
  { "main": "postType", "postType": "page" }
]
```

## Template Precedence

When building a page, Bricks resolves active templates:

1. **Header template** — highest-scoring header condition for this page
2. **Footer template** — highest-scoring footer
3. **Content template** — highest-scoring content. If none matches, falls back to page's own Bricks content.
4. **Archive template** — for archive pages only

Each template type resolves independently. A page can have a global header (score 2, `any`) but a specific content template (score 10, `ids`).

Use `template_condition:resolve(post_id=42)` to see which templates would apply.

## Import / Export

### Export
```json
{ "action": "export", "template_id": 123, "include_classes": true }
```
Returns JSON with `title`, `content`, `templateType`, `globalClasses`, `themeStyles`, `colorPalette`. Portable to another site.

### Import from JSON
```json
{ "action": "import", "template_data": { "title": "My Header", "content": [...], "templateType": "header" } }
```

### Import from URL
```json
{ "action": "import_url", "url": "https://example.com/template-export.json" }
```

Global classes from the import are merged with existing (no duplicates).

## Common Pitfalls

1. **No conditions set** — template never renders. Always set at least `[{"main":"any"}]` or a specific condition.
2. **Multiple templates, same score** — undefined winner. Use specific conditions (higher score) to guarantee precedence.
3. **Archive without `is_main_query: true`** — query loop inside archive template must use `is_main_query: true` or pagination 404s. See `bricks:get_knowledge('query-loops')`.
4. **Editing template instead of page** — `page:update_content(post_id=<template_id>)` works but affects ALL pages using this template. Edit the template when you want global changes; edit the page directly for page-specific content.
5. **Popup type conditions ≠ triggers** — popup conditions control WHERE the popup template loads. `_interactions` on other elements control WHEN it opens. Both needed.
6. **`terms` format** — `"category::5"` (taxonomy slug + `::` + term ID), NOT `"category:5"` or `"5"`.
7. **WC templates without WooCommerce** — `wc_*` types only available when WooCommerce is active. Scaffold checks this automatically.
8. **`force: true` on delete** — permanently removes (bypasses trash). Default = trash.
9. **Section templates** — `type: "section"` is embeddable inside other pages/templates via the `template` element. Not a standalone page template.
10. **`password_protection`** — overrides default WP password form. Very high score (~100). Only one should exist.

## Reference

- `template:list(type?, status?)` — list templates
- `template_condition:get_types` — condition types with scoring
- `template_condition:set(template_id, conditions)` — assign conditions
- `template_condition:resolve(post_id?)` — preview which templates apply
- `bricks:get_knowledge('popups')` — popup template settings + triggers
- `bricks:get_knowledge('woocommerce')` — WC template types + scaffolding
