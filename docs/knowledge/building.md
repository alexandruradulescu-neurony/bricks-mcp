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
