# Dynamic Data in Bricks Builder

## Tag Syntax

Dynamic tags use curly braces: `{tag_name}`. The tag resolves at render time based on context (current post, user, archive, etc.).

Common tags:
- `{post_title}`, `{post_content}`, `{post_excerpt}`, `{post_date}`
- `{post_url}`, `{post_id}`
- `{featured_image}` - returns image URL
- `{author_name}`, `{author_url}`
- `{site_title}`, `{site_url}`

## Format by Field Type

Different setting types expect dynamic data in different formats:

**Text fields** (headings, rich text, button text):
```json
{ "tag": "{post_title}" }
```

**Image fields** (image element, background image):
```json
{ "useDynamicData": "{featured_image}" }
```

**Link fields** (button link, wrapper link):
```json
{ "type": "dynamic", "dynamicData": "{post_url}" }
```

## Context Requirement

Dynamic tags resolve based on the current post context:
- **Single post templates**: tags resolve to the displayed post
- **Query loops**: tags resolve to each iterated post
- **Archive templates**: some tags (like `{archive_title}`) work at page level

Tags used outside a valid context render empty.

## Query Loops

Set `query` settings on a container or block element to create a loop:
- `post_type` - which post type to query
- `posts_per_page` - how many items
- `orderby`, `order` - sorting

Children of the query container repeat for each result. Dynamic tags inside resolve per iteration.

## Meta Box Integration

Meta Box field values use: `{mb_FIELD_ID}` where FIELD_ID matches the Meta Box field definition.

Use `bricks:get_dynamic_tags` to list all available tags, or filter by group:
- `bricks:get_dynamic_tags` with `group: "Post"` for post-related tags
- `metabox:get_dynamic_tags` for Meta Box-specific tags

## Common Pitfalls

1. Using `{featured_image}` in a text field shows the URL string, not the image
2. Dynamic tags in static pages (not templates) only resolve to that page's own data
3. Nested query loops: inner loop tags resolve to inner context, not outer
4. Empty dynamic values render nothing -- add fallback content where needed

## Reference

Use `bricks:get_dynamic_tags` for the full list of available tags and their groups.
