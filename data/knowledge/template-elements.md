# Template Elements — Building Single Post & Archive Templates

## Related Knowledge

- `templates` — template types, conditions, scoring, import/export
- `query-loops` — archive templates, WP_Query parameters, pagination
- `dynamic-data` — dynamic tags used inside template elements
- `building` — section > container > block structure applies inside templates

## When to Use This Guide

When building **content templates** (single post, single CPT) or **archive templates** (blog listing, category pages). These templates use special Bricks elements that pull data dynamically from whatever post/page they render on.

## Single Post Template Elements

These elements go inside a `content` template. They render data from the current post.

### Layout Pattern — Blog Post Template

```
section > container >
  post-title (tag: h1)
  block (_direction: row) >
    post-meta (author, date, reading time)
    post-taxonomy (category badges)
  post-content
  post-sharing
  post-navigation
  post-comments
```

### Element Reference

| Element | Purpose | Key Settings |
|---|---|---|
| `post-title` | Dynamic title from current post | `tag`: h1-h6 (default h1 for single) |
| `post-content` | Full post body (the_content) | No settings needed — renders automatically |
| `post-excerpt` | Short summary text | Auto-generated or manual excerpt |
| `post-meta` | Author, date, comments count | `meta[]`: array of `{dynamicData}` items |
| `post-author` | Author bio box | Toggle: `avatar`, `name`, `website`, `bio`, `postsLink` |
| `post-taxonomy` | Category/tag badges | `taxonomy`: "category" or "post_tag" or custom |
| `post-comments` | Comment list + reply form | Toggle: `title`, `avatar`, `formTitle`, `label` |
| `post-navigation` | Previous/next post links | Toggle: `label`, `title`, `image` + arrow icons |
| `post-reading-time` | "5 min read" display | `prefix`, `suffix` (e.g. "Reading time: ", " minutes") |
| `post-reading-progress-bar` | Scroll progress indicator | Auto-tracks — just place it (usually fixed at top) |
| `post-sharing` | Social share buttons | `items[]`: array of `{service}` (facebook, twitter, etc.) |
| `post-toc` | Table of contents | Auto-scans headings in post-content |
| `related-posts` | Related posts grid | `taxonomies[]`, `content[]` with dynamic data fields |

### post-meta — Dynamic Data Tags

The `meta` array uses Bricks dynamic data tags. Common tags:

```json
{
  "meta": [
    { "id": "m1", "dynamicData": "{author_name}" },
    { "id": "m2", "dynamicData": "{post_date}" },
    { "id": "m3", "dynamicData": "{post_comments}" },
    { "id": "m4", "dynamicData": "{post_terms_category}" }
  ]
}
```

Use `bricks:get_dynamic_tags(group="Post")` to see all available post tags.

### post-sharing — Service List

Available services for the `items` array:

```json
{
  "items": [
    { "id": "s1", "service": "facebook" },
    { "id": "s2", "service": "twitter" },
    { "id": "s3", "service": "linkedin" },
    { "id": "s4", "service": "whatsapp" },
    { "id": "s5", "service": "pinterest" },
    { "id": "s6", "service": "telegram" },
    { "id": "s7", "service": "email" }
  ],
  "brandColors": true
}
```

### post-taxonomy — Taxonomy Selection

```json
{ "taxonomy": "category", "style": "dark" }
```

Valid taxonomy values: `"category"`, `"post_tag"`, or any registered custom taxonomy slug.

### related-posts — Content Fields

Uses dynamic data fields just like the `posts` element:

```json
{
  "taxonomies": ["category", "post_tag"],
  "content": [
    { "id": "r1", "dynamicData": "{post_title:link}", "tag": "h3" },
    { "id": "r2", "dynamicData": "{post_excerpt:20}" }
  ]
}
```

## Archive Template Elements

Archive templates use the `posts` element as the primary query loop. Supporting elements:

| Element | Purpose | Placement |
|---|---|---|
| `posts` | Main query loop — renders post cards | Inside container |
| `pagination` | Page navigation for the loop | After `posts` element |
| `query-results-summary` | "Showing 1-10 of 42 results" | Before `posts` element |
| `search` | Search form for filtering | Header or above posts |

### Layout Pattern — Blog Archive

```
section > container >
  heading (content: "Blog" or dynamic archive title)
  query-results-summary
  posts (grid layout, dynamic fields)
  pagination
```

### posts Element — Field Configuration

The `fields` array defines what appears in each post card:

```json
{
  "gutter": "30px",
  "fields": [
    {
      "id": "f1",
      "dynamicData": "{featured_image:large}",
      "tag": "figure"
    },
    {
      "id": "f2",
      "dynamicData": "{post_title:link}",
      "tag": "h3",
      "dynamicMargin": { "top": 20, "right": 0, "bottom": 10, "left": 0 }
    },
    {
      "id": "f3",
      "dynamicData": "{post_date}"
    },
    {
      "id": "f4",
      "dynamicData": "{post_excerpt:20}"
    }
  ]
}
```

### Archive Query — Important

For archive templates, the `posts` element must use the main WordPress query (not a custom query). Set via element_settings:

```json
{ "element_settings": { "query": { "is_main_query": true } } }
```

Without this, pagination breaks with 404 errors.

## Common Pitfalls

1. **Using heading instead of post-title** — Static heading shows same text on every post. `post-title` pulls from each post dynamically.
2. **Missing `is_main_query: true`** on archive posts element — Pagination 404s. Always set for archive templates.
3. **post-content in archive template** — Shows full post content for every card. Use `post-excerpt` or `{post_excerpt:20}` dynamic tag instead.
4. **post-toc without post-content** — TOC scans headings inside `post-content`. If content is in regular `text` elements, TOC finds nothing.
5. **post-navigation outside content template** — Only works in single post templates where previous/next post context exists.
6. **post-sharing without page URL** — Works automatically in templates. In standalone pages, sharing URL is the current page.
7. **related-posts taxonomy mismatch** — Must match taxonomies that actually have terms on the current post. Using `["post_tag"]` when posts have no tags = empty results.
8. **post-reading-progress-bar placement** — Best as first element in section with fixed positioning via CSS. Otherwise it scrolls with content.

## Complete Single Post Template Example

```json
{
  "sections": [{
    "section_type": "generic",
    "layout": "centered",
    "background": "light",
    "structure": {
      "type": "section",
      "children": [{
        "type": "container",
        "children": [
          { "type": "post-title", "tag": "h1" },
          {
            "type": "block",
            "layout": "row",
            "children": [
              { "type": "post-meta", "element_settings": {
                "meta": [
                  { "id": "m1", "dynamicData": "{author_name}" },
                  { "id": "m2", "dynamicData": "{post_date}" }
                ]
              }},
              { "type": "post-taxonomy", "element_settings": { "taxonomy": "category" } }
            ]
          },
          { "type": "post-content" },
          { "type": "post-sharing", "element_settings": {
            "items": [
              { "id": "s1", "service": "facebook" },
              { "id": "s2", "service": "twitter" },
              { "id": "s3", "service": "linkedin" }
            ]
          }},
          { "type": "post-navigation" },
          { "type": "post-comments" }
        ]
      }]
    }
  }]
}
```

## Reference

- `bricks:get_element_schemas(elements="post-title,post-content,post-meta,post-sharing")` — batch-fetch schemas
- `bricks:get_dynamic_tags(group="Post")` — all available post dynamic data tags
- `bricks:get_knowledge('templates')` — template types, conditions, scoring
- `bricks:get_knowledge('query-loops')` — WP_Query, pagination, nested loops
- `bricks:get_knowledge('dynamic-data')` — all dynamic data tag formats
