# SEO in Bricks Builder

## Related Knowledge

- `building` — heading hierarchy (one H1 per page), semantic tags for accessibility
- `dynamic-data` — dynamic meta via `{post_title}` etc. in SEO title templates

## Tools

- `page:get_seo(post_id)` — read current SEO fields + inline audit
- `page:update_seo(post_id, ...)` — update individual fields (partial updates supported)

## Supported SEO Plugins (auto-detected)

Detection priority (first match wins):
1. **Yoast SEO** — all 12 fields + focus_keyword
2. **Rank Math** — all core fields + focus_keyword
3. **SEOPress** — all core fields + social fields (no focus_keyword via MCP)
4. **Slim SEO** — core fields (limited social support)
5. **Bricks native** — basic title, description, robots, Open Graph via Bricks page settings (fallback when no plugin active)

No configuration needed — the MCP detects which system is active and reads/writes the correct meta keys.

## Available Fields

| Field | Description | Supported by |
|---|---|---|
| `title` | SEO title (search results) | All |
| `description` | Meta description (search snippet) | All |
| `canonical` | Canonical URL (duplicate content) | All |
| `robots_noindex` | Boolean — prevent indexing | All |
| `robots_nofollow` | Boolean — prevent link following | All |
| `og_title` | Open Graph title (social sharing) | Yoast, SEOPress, Bricks |
| `og_description` | Open Graph description | Yoast, SEOPress, Bricks |
| `og_image` | Open Graph image URL | All |
| `twitter_title` | Twitter card title | Yoast, SEOPress |
| `twitter_description` | Twitter card description | Yoast, SEOPress |
| `twitter_image` | Twitter card image URL | Yoast, SEOPress |
| `focus_keyword` | Primary keyword for analysis | Yoast, Rank Math only |

## Inline SEO Audit

`page:get_seo` returns an `audit` object alongside fields — no separate tool call needed:

```json
{
  "seo_plugin": "yoast",
  "plugin_active": true,
  "fields": { ... },
  "audit": {
    "title_length": 42,
    "title_ok": true,
    "title_issue": null,
    "description_length": 95,
    "description_ok": false,
    "description_issue": "too_short",
    "has_og_image": true
  }
}
```

| Audit check | OK range | Issues |
|---|---|---|
| `title_length` | 30–60 chars | `missing`, `too_short`, `too_long` |
| `description_length` | 120–160 chars | `missing`, `too_short`, `too_long` |
| `has_og_image` | true | false = no social sharing image |

Use the audit to fix issues in the same session: read → check audit → update fields → verify.

## Usage

Read:
```json
{ "action": "get_seo", "post_id": 42 }
```

Update (partial — only fields you set are changed):
```json
{ "action": "update_seo", "post_id": 42,
  "title": "Towing Services in Bucharest — 24/7",
  "description": "Professional roadside assistance and towing. 20-30 min response. Prices from 99 RON. Call now.",
  "og_image": "https://example.com/hero.jpg" }
```

## Best Practices

1. **Unique title + description** per page — no duplicates across site
2. **One H1 per page** — heading hierarchy: H1 > H2 > H3. Set via `tag: "h1"` on the page's main heading element
3. **Title length:** 30–60 characters (audit flags outside this)
4. **Description length:** 120–160 characters (audit flags outside this)
5. **Focus keyword** should appear in title, description, H1, and early body content. Only works if Yoast or Rank Math is active.
6. **`og_image`** — always set for pages shared on social media. Falls back to featured image if unset.
7. **`robots_noindex`** — use for utility pages (thank-you pages, landing variants, staging) that shouldn't rank. Removes page from search entirely.
8. **`canonical`** — set when same content is reachable at multiple URLs (www/non-www, query params, paginated variants)
9. **After building a new page**, run `page:get_seo` to check audit → fix issues immediately rather than revisiting later

## Common Pitfalls

1. **SEO on template instead of page** — templates inherit context but SEO meta lives on the actual post/page. `update_seo` on a template ID does nothing useful.
2. **`focus_keyword` ignored** — requires Yoast or Rank Math. SEOPress/Slim SEO/Bricks native skip it.
3. **Blank `og_title`/`og_description`** — falls back to regular title/description. Not an error unless you want different social copy.
4. **`robots_noindex: true` is permanent for search** — takes weeks to de-index. Use sparingly. Check audit first.
5. **Long titles get truncated** — Google cuts at ~60 chars. The audit catches this.
6. **Missing `og_image`** — social shares look bare. The audit flags `has_og_image: false`.

## Reference

- `page:get_seo(post_id)` — read fields + audit
- `page:update_seo(post_id, ...)` — write any subset of fields
- `bricks:get_knowledge('building')` — heading hierarchy + semantic structure guidance
