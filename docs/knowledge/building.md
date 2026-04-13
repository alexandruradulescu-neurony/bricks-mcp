# General Building Rules for Bricks

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

Use flex alignment, NOT `text-align: center`:
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
- **Heading sizes**: `h1 { font-size: var(--h1) }` through `h6` — do NOT set `_typography.font-size` on headings
- **Heading styles**: color, line-height, font-weight — do NOT set these on headings
- **Body text**: font-size, color, line-height — do NOT set these on text elements

Setting these inline overrides the responsive fluid values with static ones.

## Class-First Workflow

- Use `_cssGlobalClasses` on every element when possible
- Inline styles (`style_overrides`) only for instance-specific overrides
- When using `build_from_schema`, set `class_intent` + `style_overrides` — the pipeline creates a reusable class WITH the styles

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

The `element_settings` key is an escape hatch for type-specific Bricks settings that do not fit into `style_overrides`, `content`, or `class_intent` in the design schema.

### Supported element types and whitelisted keys

- **pie-chart**: `percent`, `barColor`, `trackColor`, `size`, `lineWidth`
- **counter**: `countTo`, `prefix`, `suffix`, `duration`
- **video**: `iframeUrl`, `videoType`, `overlay`
- **slider-nested**: `autoplay`, `speed`, `effect`, `loop`, `arrows`, `dots`
- **form**: `fields`, `submitButtonText`, `formId`, `actions`
- **progress-bar**: `bars` (array of `{label, percentage}`)
- **rating**: `rating`, `scale`, `iconEmpty`, `iconFull`
- **animated-typing**: `content`, `typeSpeed`, `loop`

### Example usage in design schema

```json
{
  "type": "pie-chart",
  "element_settings": {
    "percent": 92,
    "barColor": {"raw": "var(--accent-dark)"},
    "trackColor": {"raw": "var(--white)"},
    "size": 140,
    "lineWidth": 12
  }
}
```

### Important notes

- The validator REJECTS `element_settings` on disallowed element types or unknown keys.
- Defaults exist when `element_settings` is omitted: counter `countTo`=100, pie-chart `percent`=75, rating `rating`=5.
- Auto-fixes applied: counter `"500+"` extracts to `countTo: 500` with `suffix: "+"`. Pie-chart `"92%"` extracts to `percent: 92`. YouTube watch URLs convert to embed format (`ytId` extraction). Rating values are clamped to the scale range.

## Composite Key Format

Bricks uses composite keys for responsive and pseudo-state styles:

```
{property}:{breakpoint}:{pseudo}
```

Examples:
- `_margin:tablet_portrait` — margin on tablet
- `_padding:mobile` — padding on mobile
- `_background:hover` — background on hover
- `_typography:mobile:hover` — typography on mobile hover

Available breakpoints: `tablet_landscape`, `tablet_portrait`, `mobile`

## CSS Variables

Always use `var(--name)` instead of hardcoded values:
- Spacing: `var(--space-xs)` through `var(--space-section)`
- Typography: `var(--text-xs)` through `var(--text-xxl)`, `var(--h1)` through `var(--h6)`
- Colors: `var(--primary)`, `var(--base-ultra-dark)`, `var(--white)`, etc.
- Radius: `var(--radius)`, `var(--radius-btn)`, `var(--radius-pill)`
- Grid: `var(--grid-1)` through `var(--grid-12)`, plus ratios `var(--grid-1-2)`, etc.
- Gaps: `var(--grid-gap)`, `var(--content-gap)`, `var(--container-gap)`

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
  "content": "Suna Acum: 0722 222 222",
  "icon": "mobile",
  "iconPosition": "left"
}
```

The `icon` string (e.g., `"mobile"`) resolves to Themify `ti-mobile`. You can also pass full object: `{"library": "fontawesomeSolid", "icon": "fas fa-phone"}`.

## Form Auto-Detection

When a form element has no fields explicitly set, the pipeline detects the form type from the element's `role`, `label`, or `content_hint` and applies a template:

- **newsletter** — detected from: newsletter, subscribe, signup, opt-in, register, inregistr. Template: email field (67% width) + submit button (33% width) + terms HTML field.
- **login** — detected from: login, sign-in, auth, conecta, autentific. Template: email + password fields.
- **contact** (default) — Template: name (50%) + email (50%) + textarea + submit.

To use a specific type regardless of content_hint, set `form_type` on the schema node.

## Invalid Keys That Get Auto-Fixed

The pipeline auto-converts these common mistakes:
- `_maxWidth` → `_widthMax` (Bricks uses `_widthMax`)
- `_minWidth` → `_widthMin`
- `_textAlign` → `_typography.text-align`
- `_gap` → `_columnGap` or `_rowGap` based on direction

Unknown keys (e.g., `settings`, `styles`, `css`, raw Bricks keys like `_padding` on node level) are rejected with suggestions.
