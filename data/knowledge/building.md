# General Building Rules for Bricks

## Related Knowledge Domains

This file covers structure, classes, CSS variables, and general schema rules. For deeper topics call `bricks:get_knowledge(domain=...)`:

- `query-loops` — 5 query types (post/term/user/api/array), pagination, global queries, nested loops, archive `is_main_query`
- `templates` — template types, condition scoring system, header/footer/content precedence, import/export
- `global-classes` — IDs vs names, style shape, 16 actions, class-first workflow, batch ops, CSS import, semantic search
- `forms` — 18 field types, 7 actions (email/redirect/webhook/login/registration/create-post/custom), spam protection
- `dynamic-data` — `{post_title}` tag syntax, 12 groups / 70+ tags, colon-arg params, query filters
- `components` — component definitions, 9 property types, connection format, slot mechanics
- `popups` — popup template system + triggers + 30 display settings + infobox mode
- `animations` — `_interactions` array, 17 triggers + 19 actions, Animate.css, Bricks 2.3+ parallax + 3D transforms
- `woocommerce` — 8 WC template types, `{woo_*}` dynamic tags, scaffolding, Quick View
- `seo` — 5 SEO plugins auto-detected, inline audit, Open Graph / Twitter fields

For element-specific schemas (settings, controls, working examples) call `bricks:get_element_schemas(element='NAME')` — read live from the installed Bricks version, always current.

## Page Structure

Every Bricks page follows: **section > container > block/div > content elements**.

- `section` — Full-width row. Top-level wrapper. Gets padding from child theme CSS.
- `container` — Layout box inside section. Controls direction, alignment, gap. Gets gap from child theme.
- `block` — Semantic grouping (cards, wrappers). Use `tag` for `ul`, `li`, `article`, etc. Defaults to flex.
- `div` — Smaller wrappers (icon wrappers, overlays). Does NOT default to flex — needs explicit `_display: flex`.
- Content: `heading`, `text-basic`, `text`, `button`, `icon`, `image`, `video`, etc.

## Multiple Rows = Multiple Containers

Each visual row inside a section should be a separate container. Do NOT put everything in one container.

```
section
  container (Row 1: tagline + heading left, paragraph right)
  container (Row 2: image left, tag pills right)
```

## Centering and Alignment

- Use flex alignment, NOT `text-align: center`:
- Center children horizontally: `_alignItems: center` on the parent container/block
- Center children vertically: `_justifyContent: center` on the parent
- `_typography.text-align: center` only affects text INSIDE an element, not element positioning

For a centered column layout:
```
container with _alignItems: center
  heading (centered because parent aligns center)
  text-basic (centered)
  block with _direction: row, _gap (buttons side by side)
```

## DO NOT Duplicate Child Theme CSS

The child theme globally handles:
- **Section padding**: `padding: var(--padding-section)` — do NOT set `_padding` on sections
- **Container/block gap**: `gap: var(--content-gap)` — do NOT set `_gap` on containers
- **Heading sizes**: `h1 { font-size: var(--h1) }` through `h6` — do NOT set `_typography.font-size` on headings. Child theme tag selectors `h1..h6` win the specificity war against class-level `_typography.font-size`. Setting it has NO visible effect.
- **Heading styles**: color, line-height, font-weight — do NOT set these on headings
- **Body text**: font-size, color, line-height — do NOT set these on text elements

Setting these inline overrides the responsive fluid values with static ones (where it wins at all — see heading specificity warning above).

### Massive custom heading sizes (landing-page style)

If a design needs `font-size: clamp(3rem, 12vw, 11rem)` for a giant typographic hero, use `type: text-basic` with `tag: h1` / `tag: div` instead of `type: heading`. `text-basic` has no child-theme tag selector governing its size, so your class-level `_typography.font-size` renders correctly. Semantically-equivalent (`tag: h1` preserves `<h1>` in HTML) but bypasses the theme override.

## Class-First Workflow

- Use `_cssGlobalClasses` on every element when possible
- Inline styles (`style_overrides`) only for instance-specific overrides
- When using the design-build pipeline (`propose_design` → `build_structure`), reuse resolved `style_roles` from discovery before inventing class names
- If an existing site uses custom names, map semantic roles with `style_role` first (for example `button.primary` → an existing primary CTA class, `color.primary` → an existing brand variable)
- If you set a new `class_intent`, include `style_overrides`. The build contract rejects new class names with no style source because they create empty visual classes.
- `design_pattern:from_image` and `reference_json` imports are normalized before proposal/build: role keys are canonicalized, duplicate or empty class-create entries are dropped, and unsupported class guesses can be remapped to semantic role fallbacks.
- Direct `design_plan` input is normalized too. Keep direct element roles unique; `build_structure.role_collisions` means you must use `#element-id` keys in `populate_content` or rebuild with clearer roles.
- Read `design_plan_warnings` from `propose_design` or `design_pattern:from_image`. They are non-blocking signals that the plan is likely weak (missing visual anchor in split layout, generic roles, missing media/button hints, repeated cards modeled inline).
- Repeated `patterns[]` children are expanded into unique role labels per clone during schema expansion (for example `feature_card_2_title`, `testimonial_3_author`). Use those returned role keys from `build_structure.role_map` / `content_contract` when calling `populate_content`.
- Proposal `content_plan` now expands repeated pattern child hints into indexed keys too. If your design uses `patterns[]`, expect hints like `feature_card_1_title`, `feature_card_2_text`, `tier_3_cta` instead of one shared unindexed key.
- After `build_structure`, use the returned `content_contract.required_roles` to build the `populate_content.content_map`. By default `populate_content` rejects unmatched keys and missing required text/button roles; set `allow_partial: true` only for intentional partial updates.

## Labels on Structural Elements

Add `label` to sections, containers, blocks, and divs for editor UX:
- Section: "Hero", "Services", "Testimonials"
- Container: describes the row content
- Block: "Cards Grid", "CTA Buttons", "Text Content"

## Semantic HTML Tags

Use `tag` on block/div elements for semantic HTML:
- `tag: "ul"` + children with `tag: "li"` for lists
- `tag: "figure"` for image wrappers
- `tag: "address"` for contact info
- `tag: "nav"` for navigation
- `tag: "article"` for blog cards

## Color Object Format

Color values in Bricks settings MUST be objects, not plain strings:

```json
"_typography": { "color": { "raw": "var(--primary)" } }
"_typography": { "color": { "hex": "#ec4e38" } }
"_background": { "color": { "raw": "var(--base-ultra-dark)" } }
```

NOT:
```json
"_typography": { "color": "#ec4e38" }
"_background": { "color": "var(--primary)" }
```

Use `"raw"` for CSS variables (`var(--name)`) and `rgba()` values. Use `"hex"` for hex color codes.

The v3.7.0+ pipeline auto-fixes string colors (wraps them in the correct object format), but always use the correct format from the start to avoid silent rendering failures in direct element operations.

## Element-Specific Settings via element_settings

The `element_settings` key passes type-specific Bricks settings that don't fit `style_overrides`, `content`, or `class_intent`.

### Works on ANY element type

`element_settings` is accepted on every element — no whitelist restriction. The pipeline blocks only dangerous code-injection keys (`customScriptsHeader`, `customScriptsBodyHeader`, `customScriptsBodyFooter`, `useQueryEditor`, `queryEditor`). Everything else passes through.

**Before using element_settings on an element type, read the relevant knowledge domain** to learn what keys that element accepts:
- Sliders → `bricks:get_knowledge('building')` (Splide.js section) or `bricks:get_knowledge('animations')`
- Forms → `bricks:get_knowledge('forms')` + `bricks:get_form_schema`
- Components → `bricks:get_knowledge('components')` + `bricks:get_component_schema`
- Popups → `bricks:get_knowledge('popups')` + `bricks:get_popup_schema`
- Query loops → `bricks:get_knowledge('query-loops')` + `bricks:get_query_types`

### Common examples

```json
{"type": "pie-chart", "element_settings": {"percent": 92, "barColor": {"raw": "var(--accent-dark)"}, "size": 140}}

{"type": "slider-nested", "element_settings": {"perPage": "4", "gap": "var(--content-gap)", "arrows": true, "dots": true, "loop": true}}

{"type": "counter", "element_settings": {"countTo": 500, "prefix": "", "suffix": "+"}}

{"type": "accordion-nested", "element_settings": {"openFirst": true}}

{"type": "countdown", "element_settings": {"targetDate": "2026-12-31", "format": "dHMS"}}
```

### Notes

- Defaults exist when `element_settings` is omitted: counter `countTo`=100, pie-chart `percent`=75, rating `rating`=5, slider-nested `perPage`="3" + `gap`="var(--content-gap)".
- Auto-fixes: counter `"500+"` → `countTo: 500` + `suffix: "+"`. Pie-chart `"92%"` → `percent: 92`. YouTube URLs → `ytId` extraction. Rating clamped to scale range.
- Underscore-prefixed keys inside `element_settings` are validated against the settings key registry — use `style_overrides` for CSS properties instead.

## Setting Key Conventions (critical)

Bricks mixes TWO case conventions in the same settings object. Getting this wrong silently drops CSS rules — no error, no warning, just missing styles.

- **Top-level `_*` keys = camelCase**: `_alignItems`, `_justifyContent`, `_columnGap`, `_rowGap`, `_flexWrap`, `_aspectRatio`, `_objectFit`, `_widthMax`, `_widthMin`, `_heightMax`, `_heightMin`, `_textAlign`.
- **Inside `_typography`, `_border.*` = kebab-case**: `font-size`, `font-weight`, `line-height`, `letter-spacing`, `text-transform`, `text-align`, `color`.
- **Object shape (not scalar)**: `_padding`, `_margin`, `_border.radius`, `_border.width` are `{top, right, bottom, left}` objects. `*.color` is `{raw}` or `{hex}` object.

Wrong: `"_typography": { "fontSize": "var(--h2)" }` — silently dropped.
Right: `"_typography": { "font-size": "var(--h2)" }`.

**Always verify with `global_class:render_sample(class_name)`** after class creation. If a setting you passed isn't in the `css_rules` output, the key shape is wrong.

Full reference + pitfalls: `bricks:get_knowledge('global-classes')` → "Setting Key Conventions".


## Composite Key Format

Bricks uses composite keys for responsive and pseudo-state styles:

```
{property}:{breakpoint}:{pseudo}
```

Examples:
- `_margin:tablet_portrait` — margin on tablet portrait
- `_padding:mobile_landscape` — padding on mobile landscape
- `_background:hover` — background on hover
- `_typography:mobile_portrait:hover` — typography on mobile portrait hover

Available breakpoints: `tablet_landscape`, `tablet_portrait`, `mobile_landscape`, `mobile_portrait`.

**Warning:** plain `mobile` is NOT a valid breakpoint — it's silently ignored. Use `mobile_landscape` (≤768px) or `mobile_portrait` (≤478px).

**Custom breakpoints:** sites can define custom breakpoints via Bricks > Settings > General > Custom breakpoints. Always call `bricks:get_breakpoints` to discover available breakpoints rather than assuming defaults.

**Mobile-first mode:** if the site uses mobile-first (smallest breakpoint as base), styles inherit upward via `min-width` media queries instead of downward via `max-width`.

## CSS Variables

Always use `var(--name)` instead of hardcoded values:
- Spacing: `var(--space-xs)` through `var(--space-section)`
- Typography: `var(--text-xs)` through `var(--text-xxl)`, `var(--h1)` through `var(--h6)`
- Colors: `var(--primary)`, `var(--base-ultra-dark)`, `var(--white)`, etc. (+ optional tertiary, neutral families; expanded 8-shade palettes + 9-step transparencies when enabled)
- Radius: `var(--radius)`, `var(--radius-btn)`, `var(--radius-pill)`, `var(--radius-s/m/l/xl)`
- Borders: `var(--border-thin)`, `var(--border-medium)`, `var(--border-thick)`
- Grid: `var(--grid-1)` through `var(--grid-12)`, plus ratios `var(--grid-1-2)`, etc.
- Gaps: `var(--grid-gap)`, `var(--content-gap)`, `var(--container-gap)`, `var(--card-gap)`, `var(--padding-section)`, `var(--offset)`
- Shadows: `var(--shadow-xs)` through `var(--shadow-xl)`, plus `var(--shadow-inset)`
- Transitions: `var(--duration-fast/base/slow)` + `var(--ease-out)`, `var(--ease-in-out)`, `var(--ease-spring)`
- Z-Index: `var(--z-base)`, `var(--z-sticky)`, `var(--z-dropdown)`, `var(--z-overlay)`, `var(--z-modal)`, `var(--z-popover)`, `var(--z-tooltip)` (100× gaps per layer)
- Aspect Ratios: `var(--aspect-square)`, `var(--aspect-video)`, `var(--aspect-photo)`, `var(--aspect-portrait)`, `var(--aspect-wide)`
- Sizes: `var(--container-width)`, `var(--max-width)`, `var(--max-width-m/s)`, `var(--min-height)`, `var(--min-height-section)`, `var(--logo-width)`

## Gap Handling on Flex Blocks

On flex blocks (block/div with `_direction: row` or default column):
- Use `_columnGap` for horizontal spacing (row direction)
- Use `_rowGap` for vertical spacing (column direction, default)
- Plain `_gap` does NOT generate CSS on flex layout blocks

The pipeline auto-converts `_gap` to the correct key based on `_direction`. You can use `_gap` in schemas and it will work — but directly in element settings, use the specific key.

## Background Images and Overlays

Background image with gradient overlay pattern:

```json
{
  "_background": {
    "image": {"url": "https://...jpg", "id": 124, "size": "full"},
    "size": "cover",
    "position": "center center"
  },
  "_gradient": {
    "colors": [
      {"color": {"raw": "rgba(46, 46, 61, 0.8)"}, "stop": "0"},
      {"color": {"raw": "rgba(142, 47, 34, 0.6)"}, "stop": "100"}
    ],
    "applyTo": "overlay"
  }
}
```

Key rules:
- Overlay uses `_gradient` with `applyTo: "overlay"` — NOT `_background.overlay` or `_overlay`
- Background image needs a real URL (sideload from Unsplash first, OR use `"unsplash:query"` in design schemas and let the pipeline resolve it)
- When setting `background: "dark"` on a section with an image, the pipeline merges — the image is preserved, color overlay is added

## Button Icons

Buttons support native icons — do NOT use emoji in button text:

```json
{
  "type": "button",
  "content": "Call Now",
  "icon": "mobile",
  "iconPosition": "left",
  "link": { "type": "external", "url": "tel:+15550100" }
}
```

The `icon` string (e.g., `"mobile"`) resolves to the site's primary icon library (Themify by default → `ti-mobile`). The library is read from the `icon_library` brief at runtime. You can also pass a full object to override per-button: `{"library": "fontawesomeSolid", "icon": "fas fa-phone"}`. Supported libraries: `themify`, `fontawesomeSolid`, `fontawesomeRegular`, `ionicons`.

## Forms — supply fields explicitly

The `form` element requires a `fields` array via `element_settings`. The pipeline does NOT inject default field templates — emit a pipeline warning if fields are missing.

```json
{
  "type": "form",
  "element_settings": {
    "fields": [
      { "id": "ct_name", "type": "text", "label": "Name", "placeholder": "Your name", "width": "50" },
      { "id": "ct_email", "type": "email", "label": "Email", "placeholder": "Your email", "required": true, "width": "50" },
      { "id": "ct_message", "type": "textarea", "label": "Message", "placeholder": "Your message", "required": true, "width": "100" }
    ],
    "actions": ["email"],
    "submitButtonText": "Send",
    "emailTo": "admin_email"
  }
}
```

Field types: `text`, `email`, `textarea`, `password`, `select`, `checkbox`, `radio`, `file`, `hidden`, `html`. See `bricks:get_knowledge('forms')` for full field reference and `bricks:get_form_schema` for the live Bricks form schema.

## Auto-Fixes Applied by the Pipeline

These common mistakes are silently corrected with a warning attached to the build response:

**Settings keys**
- `_maxWidth` → `_widthMax` (Bricks key is `_widthMax`)
- `_minWidth` → `_widthMin`
- `_textAlign` → `_typography.text-align`
- `_gap` → `_columnGap` or `_rowGap` based on `_direction` (flex blocks only)

**Color shape**
- String colors → `{raw|hex}` object on `_typography.color`, `_background.color`, `_border.color`, `_color`
- Per-side `_border.style` object → string (Bricks expects a single style)

**Element values**
- `counter.countTo`: `"500+"` → integer `500` + `suffix: "+"`
- `pie-chart.percent`: string → integer clamped 0–100
- `video`: YouTube/Vimeo URL → `ytId`/`vimeoId` + `videoType`
- `rating.rating`: clamped to `0..maxRating`

**Background**
- `background: "dark"` merges with existing `_background` (preserves images set via `style_overrides` or `unsplash:query`)

**Quarantines**
- `_cssCustom` on text-basic / heading / button / icon / text / text-link is stripped (would crash the frontend with "Array to string conversion"). Move to a global class via `class_intent` instead.

**Rejections (no auto-fix)**
- Unknown schema-node keys: `settings`, `styles`, `css`, raw `_padding` at node level. Validator returns "Did you mean..." suggestions for known typos.
- Invalid breakpoints (e.g. plain `mobile`) are silently ignored by Bricks — pipeline does NOT reject these.

## Design Pattern Library

Database-backed pattern library. The plugin does NOT ship a bundled pattern seed — new installs start with an empty library. Patterns live in the `bricks_mcp_patterns` WP option and are populated via one of four creation paths:

- **Manual authoring** — `design_pattern:create(pattern)` or the Patterns admin tab under **Bricks → Bricks WP MCP → Patterns**
- **Capture live section** — `design_pattern:capture(page_id, block_id, name, category)` snapshots an existing built section (including style fingerprints) into the library
- **Import portable JSON** — `design_pattern:import(patterns)` — imports an array of pattern objects, auto-suffixing conflicting IDs
- **Vision capture** — `design_pattern:from_image` reads a screenshot or Bricksies-format clipboard JSON and produces a site-BEM pattern

### Discovery

- `design_pattern:list(category?, tags?)` — all patterns, filterable by category / tag
- `design_pattern:get(id, include_drift?)` — fetch single pattern, optionally with drift report (live class divergence vs pattern fingerprint)
- `propose_design` automatically returns best-matching patterns (scoped to the detected `section_type`) with `structural_summary` + `ai_description` + `ai_usage_hints` for AI selection

### Pattern metadata

Each pattern has optional AI metadata:
- `ai_description` — 1-2 sentence description of what the pattern looks like when built
- `ai_usage_hints` — array of tips for when/how to use the pattern

Use these to pick the right pattern for the task. Capture and from_image flows backfill this metadata automatically; manually-created patterns can add it via `design_pattern:update(id, pattern: {ai_description, ai_usage_hints})`.

### Managing patterns

Registered actions (exhaustive): `capture`, `list`, `get`, `create`, `update`, `delete`, `export`, `import`, `mark_required`, `from_image`.

- `design_pattern:capture(page_id, block_id, name, category, id?, tags?)` — snapshot a live section
- `design_pattern:create(pattern)` — save a new pattern (required: id, name, category, tags)
- `design_pattern:update(id, pattern)` — update any database pattern
- `design_pattern:delete(id)` — remove a pattern from the database (destructive — requires confirmation token)
- `design_pattern:export(ids?)` — export patterns as JSON for cross-site sharing
- `design_pattern:import(patterns)` — import a JSON array of patterns (auto-suffixes conflicting IDs)
- `design_pattern:mark_required(id, role, required?)` — mark / unmark a pattern role as required to be supplied during use
- `design_pattern:from_image(name, category, image_*?|reference_json?, page_id?, dry_run?)` — vision-based pattern creation; if `page_id` supplied, auto-builds on the page through the design-build pipeline

Note: category CRUD (`list_categories`, `create_category`, `delete_category`) lives on the `global_class` handler, NOT `design_pattern`. Normalization / semantic search / prompt generation actions are not provided on this handler — use `propose_design` for pattern scoring and site-context-aware suggestions.
