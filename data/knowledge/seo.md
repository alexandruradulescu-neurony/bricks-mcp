# SEO in Bricks Builder

## Tools

- `page:get_seo` - read current SEO settings for a page
- `page:update_seo` - update SEO fields for a page

Both require `post_id`.

## Supported SEO Plugins

The MCP works with:
- **Yoast SEO** - reads/writes Yoast meta
- **Rank Math** - reads/writes Rank Math meta
- **Native Bricks** - uses Bricks' built-in SEO fields when no plugin is active

The MCP auto-detects which system is active. No configuration needed.

## Available Fields

| Field | Description |
|-------|-------------|
| `title` | SEO title (appears in search results) |
| `description` | Meta description (search snippet) |
| `canonical` | Canonical URL (avoid duplicate content) |
| `og_title` | Open Graph title (social sharing) |
| `og_description` | Open Graph description |
| `og_image` | Open Graph image URL |
| `twitter_title` | Twitter card title |
| `twitter_description` | Twitter card description |
| `twitter_image` | Twitter card image URL |
| `robots_noindex` | Boolean - prevent indexing |
| `robots_nofollow` | Boolean - prevent link following |
| `focus_keyword` | Primary keyword (Yoast/Rank Math only) |

## Usage

Read SEO data:
```
page:get_seo with post_id: 123
```

Update SEO data (partial updates supported):
```
page:update_seo with post_id: 123, title: "Page Title", description: "Meta description"
```

## Best Practices

1. **Unique title and description** per page -- no duplicates across the site
2. **One H1 per page** -- use heading hierarchy (H1 > H2 > H3) properly
3. **Title length**: 50-60 characters for full display in search results
4. **Description length**: 120-160 characters
5. **Focus keyword** should appear in title, description, H1, and early body content
6. Set `og_image` for pages shared on social media -- falls back to featured image if unset
7. Use `robots_noindex` for utility pages (thank you, landing page variants) that shouldn't rank
8. Set `canonical` when content is accessible at multiple URLs

## Common Pitfalls

1. Setting SEO fields on a template instead of the actual page
2. `focus_keyword` is ignored if neither Yoast nor Rank Math is active
3. Blank `og_title`/`og_description` falls back to the regular title/description
4. `robots_noindex: true` removes the page from search entirely -- use sparingly
