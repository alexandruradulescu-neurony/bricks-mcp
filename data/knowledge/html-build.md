# HTML build mode (`build_from_html`)

The `build_from_html` tool accepts an HTML fragment and writes Bricks elements to the target page. The conversion is deterministic — there is no AI in the plugin's pipeline. Your job is to write good HTML; the converter handles the Bricks shape.

## Modes (the `mode` argument)

`mode` declares your intent so the response can flag mismatches between intent and what the converter actually did. Three values, default `section`:

- **`section`** (default) — one new section. The handler keeps `action=append` by default. The response warns if your HTML produced more than one top-level `<section>`. Use for adding a single section to a page.

- **`page`** — multi-section full-page build. The handler forces `action=replace` regardless of what you sent (a "page build" with `append` would double up content). Your HTML should contain every section of the new page, top to bottom.

- **`modify`** — guardrail for restructuring an existing section. The handler forces `action=replace`. **Prefer `element:update` / `element:bulk_update` for narrow edits** (text changes, single-style tweaks, content swaps); reach for `modify` mode only when the structural shape of the section must change. See the `modify-workflow` knowledge doc for the decision tree.

The mode does not change the conversion. It only adjusts the action default and surfaces `mode_warnings` in the response.

## When to use HTML mode

Use HTML mode for **content sections**:

- Heroes (with tagline, heading, subtitle, CTAs, image)
- Feature grids (cards in a layout)
- CTAs (banner with heading + button)
- Testimonials, logo strips, content blocks

Use **schema mode (`build_structure`)** for:

- Popups
- Components and component instances
- Query loops (post grids, archive lists)
- Any element that has no plain HTML analog

If the user asks for one of those, do not try to express it in HTML — `build_from_html` will refuse with `html_mode_unsupported`.

## Mandatory rules

1. **Wrap the section in `<section class="...">`** at the top level. Multiple top-level `<section>` tags become multiple Bricks sections.
2. **Use ONLY classes that already exist on this site.** Call `global_class:list` first; do not invent class names. Unknown class names land in `_cssClasses` as a string but won't produce styled output.
3. **Use site CSS variables for colors and spacing.** Call `get_site_info` to enumerate them. Hardcoded hex (`#234e94`) and px (`40px`) work but break the design system.
4. **One class per element gets resolved as the BEM intent.** Extra classes are kept as a `_cssClasses` string but won't be auto-resolved to global class IDs. Put the styled BEM class first.

## Style attribute conventions

The converter parses `style="..."` and translates each declaration:

| CSS in HTML | Bricks setting written |
|---|---|
| `color: var(--white)` | `_typography.color.raw = var(--white)` |
| `font-size: var(--text-m)` | `_typography.font-size = var(--text-m)` |
| `text-align: left` | `_typography.text-align = left` |
| `padding: 1rem 2rem` | `_padding.{top,bottom} = 1rem`, `_padding.{left,right} = 2rem` |
| `padding-top: var(--space-section)` | `_padding.top = var(--space-section)` |
| `margin: ...` (any shorthand) | expands to `_margin.{top,right,bottom,left}` |
| `border-radius: 16px` | `_border.radius.{all four sides} = 16px` |
| `border: solid` / `border-width: 1px` / `border-color: ...` | `_border.{style,width,color}` |
| `display: grid` | `_display = grid` |
| `grid-template-columns: 1fr 1fr` | `_gridTemplateColumns = 1fr 1fr` |
| `gap`, `row-gap`, `column-gap` | `_gap` / `_rowGap` / `_columnGap` |
| `flex-direction: column` | `_direction = column` |
| `width / max-width / min-width` | `_width` / `_widthMax` / `_widthMin` |
| `aspect-ratio: 4/3` | `_aspectRatio = 4/3` |
| `object-fit: cover` | `_objectFit = cover` |
| `background: linear-gradient(...)` | `_background.image.gradient = linear-gradient(...)` + `_background.useGradient = true` |
| `background: var(--primary)` | `_background.color.raw = var(--primary)` |
| `transition / transform / box-shadow / opacity / cursor / position / top/right/bottom/left / z-index` | corresponding `_*` keys |

CSS rules that don't map to a Bricks key are dropped and surfaced in the response as `html_mode.css_rules_dropped` so you can inspect what didn't survive.

## Element conventions

| HTML | Bricks element | Notes |
|---|---|---|
| `<section>`, `<header>`, `<footer>`, `<main>`, `<article>` | `section` | `<main>`/`<article>`/`<header>`/`<footer>` collapse to a single section; their direct children come up one level. |
| `<div>`, `<aside>`, `<nav>`, `<figure>`, `<li>` | `div` | Use for grouping. Add `style="display: grid"` etc. for layouts. |
| `<h1>` … `<h6>` | `heading` | The `tag` setting is preserved automatically. |
| `<p>`, `<span>`, `<blockquote>`, `<figcaption>` | `text-basic` | Inline text content goes to `content`. |
| `<a href="...">` | `text-link` | `href` becomes `element_settings.link`. `target="_blank"` honored. |
| `<button>` | `button` | Use for non-link CTAs (submit, JS handlers). |
| `<img src="..." alt="...">` | `image` | `src` becomes the image url; alt → `element_settings.altText`. Prefix with `unsplash:query` to trigger sideload. |
| `<video src="...">` | `video` | |
| `<ul>`, `<ol>` | `list` | |
| `<hr>` | `divider` | |
| `<details>`, `<dialog>`, `<table>`, custom elements | dropped + warning | Use schema mode if you need these. |

## Example: dark split hero

```html
<section class="hero hero--dark" style="padding: var(--space-section) var(--space-l); background: linear-gradient(135deg, var(--primary-ultra-dark) 0%, var(--base-ultra-dark) 100%);">
  <div class="hero__content" style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--container-gap); align-items: center;">
    <div class="hero__left" style="display: flex; flex-direction: column; row-gap: var(--space-m); align-items: flex-start;">
      <p class="hero__tagline" style="text-transform: uppercase; letter-spacing: 0.12em; color: var(--accent); font-size: var(--text-s); font-weight: 600;">ASISTENȚĂ RUTIERĂ 24/7</p>
      <h1 class="hero__heading" style="color: var(--white); text-align: left;">Tractări auto rapide oriunde în țară</h1>
      <p class="hero__description" style="color: var(--white-trans-80); text-align: left;">Echipa noastră ajunge la tine în cel mai scurt timp posibil.</p>
      <div class="hero__cta-group" style="display: flex; column-gap: var(--space-s);">
        <a class="hero__cta-primary" href="tel:+40744555666" style="background: var(--primary); color: var(--white);">Sună acum: 0744 555 666</a>
        <a class="hero__cta-secondary" href="#contact" style="background: transparent; color: var(--white); border-style: solid; border-width: 1px; border-color: var(--white-trans-30); border-radius: var(--radius-btn);">Cere ofertă</a>
      </div>
    </div>
    <div class="hero__right">
      <img class="hero__gallery-image" src="unsplash:tow truck highway night" alt="Asistență rutieră" style="aspect-ratio: 4/3; object-fit: cover; width: 100%; border-radius: var(--radius-l);" />
    </div>
  </div>
</section>
```

After calling `build_from_html`, the response contains:

- `section_id` — the new top-level element ID
- `role_map` — class-name-derived labels mapped to element IDs
- `classes_reused` — which existing global classes were applied
- `classes_created` — new classes (only when intent didn't match anything)
- `html_mode.class_names_seen` — every class the converter saw (whether resolved or not)
- `html_mode.css_rules_dropped` — CSS declarations the converter couldn't translate
- `html_mode.warnings` — skipped tags

Inspect `html_mode.css_rules_dropped` after the build. If a style you cared about doesn't appear there AND doesn't appear in the rendered result, that's a normalizer drop — switch to `style_overrides` via schema mode for that property.

## Common mistakes

- **Inventing classes**: writing `class="my-cool-button"` when the site has `cta--primary` instead. Always read `global_class:list` first and pick from real names.
- **Hardcoded values**: `padding: 40px` instead of `var(--space-l)`. Works once; breaks the spacing rhythm. Use site variables.
- **Per-side variables that are shorthands**: `padding-top: var(--padding-section)` fails when `--padding-section` is a 2-value shorthand (e.g. `clamp(...) clamp(...)`). Use single-value variables (`--space-section`) for per-side properties; reserve shorthand variables for full `padding: var(--padding-section)`.
- **Trying to express a popup**: HTML mode rejects this. Use `build_structure`.
- **Cramming a query loop into HTML**: same — schema mode only.
