# Bricks MCP Schema Gaps Audit

**Date**: 2026-04-13
**Scope**: Knowledge gaps between Bricks Builder capabilities and what the MCP exposes to AI clients
**Baseline**: Plugin version in `/Users/alex/Code/bricks-mcp-plugin/bricks-mcp/`

---

## 1. Executive Summary

The MCP's `ELEMENT_CAPABILITIES` constant exposes only 20 of ~80-90 registered Bricks elements, leaving common elements like `progress-bar`, `pie-chart`, `alert`, `social-icons`, `breadcrumbs`, `search`, and all WordPress/query loop elements undocumented for AI decision-making. The style normalization layer (`normalize_bricks_styles`) only fixes `_border.style` per-side arrays but leaves at least 5 other shape mismatches unchecked (color objects vs hex strings, `_boxShadow` as object vs string, `_typography.color` as string vs object, `_padding`/`_margin` flat strings vs per-side objects, and `_cssCustom` passed as array). The class context rules file has only 4 rules, all specific to the tagline/pill pattern, with no guard rails for common misapplications. The design pattern library covers 7 categories but is missing stats-only, FAQ-standalone, team, footer, blog/post-grid, comparison-table, and timeline patterns.

---

## 2. Gap 1: Element Coverage

### What's exposed in `ELEMENT_CAPABILITIES` (20 elements)

`section`, `container`, `block`, `div`, `heading`, `text-basic`, `text-link`, `button`, `image`, `icon`, `icon-box`, `video`, `divider`, `tabs-nested`, `accordion-nested`, `slider-nested`, `form`, `counter`, `list`, `pricing-tables`

### What's in `NESTING_RULES` but NOT in `ELEMENT_CAPABILITIES`

These elements have nesting rules (SchemaGenerator lines 92-129) but no AI-facing capability descriptions:

| Element | Status | Impact |
|---------|--------|--------|
| `text` | In NESTING_RULES | Confusable with `text-basic`; AI may use wrong one |
| `code` | In NESTING_RULES | AI cannot build code blocks |
| `map` | In NESTING_RULES | AI cannot build map sections |
| `rating` | In NESTING_RULES | AI cannot build star ratings |
| `countdown` | In NESTING_RULES | AI cannot build countdown timers |
| `progress-bar` | In NESTING_RULES | AI cannot build progress bars |
| `pie-chart` | In NESTING_RULES | AI cannot build pie charts |
| `alert` | In NESTING_RULES | AI cannot build alert banners |
| `accordion` (basic) | In NESTING_RULES | AI only knows nested variant |
| `tabs` (basic) | In NESTING_RULES | AI only knows nested variant |
| `slider` (basic) | In NESTING_RULES | AI only knows nested variant |
| `nav-nested` | In NESTING_RULES | AI cannot build navigation menus |
| `offcanvas` | In NESTING_RULES | AI cannot build offcanvas panels |
| `dropdown` | In NESTING_RULES | AI cannot build dropdown menus |
| `toggle` | In NESTING_RULES | AI cannot build toggle elements |
| `popup` | In NESTING_RULES | AI cannot build popup structures |
| `template` | In NESTING_RULES | AI cannot embed template references |

### Elements likely in the full Bricks registry but missing from both constants

These are standard Bricks elements that have no representation in either `ELEMENT_CAPABILITIES` or `NESTING_RULES`:

- **WordPress elements**: `posts`, `pagination`, `breadcrumbs`, `search`, `sidebar`, `shortcode`, `wp-menu`
- **Media elements**: `audio`, `image-gallery`, `carousel` (non-nested)
- **Social/sharing**: `social-icons`
- **Dynamic data**: `post-title`, `post-content`, `post-excerpt`, `post-meta`, `post-navigation`, `related-posts`
- **Archive elements**: `archive-title`, `post-author`
- **Interaction elements**: `animated-typing` (in `element-hierarchy-rules.json` and `element-defaults.json` but NOT in ELEMENT_CAPABILITIES)
- **Other**: `logo`, `testimonials` (native element), `team-members`

### Content key coverage in `element-defaults.json`

The `content_keys` map covers: `heading`, `text-basic`, `text`, `text-link`, `button`, `icon-box`, `alert`, `counter`, `animated-typing`, `video`, `pricing-tables`, `testimonials`, `social-icons`, `progress-bar`, `rating`, `pie-chart`

Notable: `pie-chart` has `content_key: "percent"` and `counter` has `content_key: "countTo"` -- these are mapped but the AI has no ELEMENT_CAPABILITIES description explaining how to use them. The AI is told `counter` exists with "count-to target number" but `pie-chart` is completely absent from ELEMENT_CAPABILITIES.

### Recommended additions to ELEMENT_CAPABILITIES

**High priority** (AI frequently attempts to use these):
1. `progress-bar` -- animated progress indicator
2. `pie-chart` -- circular percentage chart
3. `alert` -- notification/banner element
4. `animated-typing` -- typewriter text effect
5. `rating` -- star rating display
6. `social-icons` -- social media icon row

**Medium priority** (common in page designs):
7. `breadcrumbs` -- navigation breadcrumb trail
8. `search` -- search input element
9. `map` -- embedded map
10. `code` -- code snippet display
11. `countdown` -- timer element
12. `nav-nested` -- navigation menu builder

---

## 3. Gap 2: Style Shape Rules

### Current normalization (GlobalClassService + ThemeStyleService)

Both services have identical `normalize_bricks_styles()` methods that handle exactly ONE case:

```
_border.style: array{top, right, bottom, left} --> string
```

The recursive loop skips any object that has `width`, `style`, `color`, or `radius` keys, meaning it specifically targets `_border.style` and nothing else.

### Missing normalizations needed

#### 3a. `_typography.color` -- object vs string

Bricks expects `_typography.color` as `{"raw": "var(--primary)"}` or `{"hex": "#333"}`. AI frequently sends:
```json
{"_typography": {"color": "#333333"}}
```
**Expected**: `{"_typography": {"color": {"hex": "#333333"}}}`

This won't crash but the color silently fails to render.

#### 3b. `_background.color` -- object vs string

Same pattern. Bricks expects `{"color": {"raw": "..."}}`. AI sends:
```json
{"_background": {"color": "var(--primary)"}}
```
**Expected**: `{"_background": {"color": {"raw": "var(--primary)"}}}`

#### 3c. `_color` -- object vs string

Top-level `_color` (used on sections for inherited text color) expects the object format `{"raw": "..."}` but AI sends plain strings.

#### 3d. `_border.color` -- object vs string

Like `_border.style`, the `_border.color` key sometimes receives a plain string when Bricks expects `{"raw": "var(--base-light)"}`. Unlike `_border.style` (which crashes), this silently fails.

#### 3e. `_boxShadow` -- string vs Bricks object

`css-property-map.json` declares `_boxShadow` as type `"string"` with format `"CSS box-shadow value"`. But Bricks actually stores box-shadow as an array of shadow objects:
```json
{"_boxShadow": [{"values": {"offsetX": "0", "offsetY": "4", "blur": "20", "spread": "0"}, "color": {"raw": "rgba(0,0,0,0.1)"}}]}
```
AI sends `"0 4px 20px rgba(0,0,0,0.1)"` as a string -- this silently does nothing.

#### 3f. `_padding` / `_margin` -- flat string vs per-side object

Bricks expects `{"top": "var(--space-m)", "right": "var(--space-m)", ...}`. AI sometimes sends `"var(--space-m)"` as a flat string (CSS shorthand). The normalizer should expand it to the per-side object format.

#### 3g. `_cssCustom` -- array vs string

`ElementNormalizer` line 396 already handles array-to-string by `implode("\n", $value)`, but `GlobalClassService` does not -- it passes arrays through to `sanitize_styles_array()` which may produce the "Array to string conversion" PHP warning reported in the known issues.

#### 3h. `_border.width` -- string vs per-side object

Like `_border.style`, `_border.width` can receive a flat string `"1px"` when Bricks expects `{"top": "1px", "right": "1px", ...}`. Unlike style, this does not crash but produces inconsistent rendering.

### Recommended `StyleShapeValidator` rules

| Rule | Input shape | Expected shape | Action |
|------|------------|----------------|--------|
| `color_object` | `"#hex"` or `"var(--x)"` string in color position | `{"raw": "..."}` or `{"hex": "..."}` | Wrap in object |
| `border_style_scalar` | `{top, right, bottom, left}` object | string | Collapse (existing) |
| `border_width_expand` | `"1px"` string | `{top, right, bottom, left}` object | Expand to 4 sides |
| `spacing_expand` | `"var(--x)"` string for `_padding`/`_margin` | `{top, right, bottom, left}` object | Expand to 4 sides |
| `boxshadow_parse` | CSS string | Bricks shadow array | Parse to shadow objects |
| `cssCustom_stringify` | array | string | Join with newlines |
| `typography_color_wrap` | string in `_typography.color` | `{"raw": "..."}` object | Wrap in color object |

---

## 4. Gap 3: Class Context Rules

### What's in `data/class-context-rules.json`

Exactly 4 rules, all related to the tagline/pill design pattern:

| Rule | Element type | Context | Description |
|------|-------------|---------|-------------|
| `tagline` | `text-basic` | `before_heading` | Auto-applies tagline class to text before a heading |
| `tag-grid` | `block` | `parent_of_pills` | Auto-applies to blocks with 3+ block children |
| `tag-pill` | `block` | `has_icon_and_text` | Auto-applies to blocks with exactly icon + text-basic children |
| `tag-pill-icon` | `icon` | `inside_pill` | Auto-applies to icons inside tag-pill parents |

### Context checks implemented in `ClassIntentResolver`

The `suggest_for_context()` method supports these contexts:
- `before_heading` -- text-basic positioned before a heading sibling
- `grid_columns_2`, `grid_columns_3` -- blocks with exactly 2 or 3 children (NOT referenced by any rule)
- `parent_of_pills` -- blocks with 3+ block children (ALL blocks)
- `has_icon_and_text` -- blocks with exactly [icon, text-basic] children
- `inside_pill` -- element inside a parent with "tag-pill" in its class name

### Known issues

1. **`tagline` over-application**: The `before_heading` rule matches ANY `text-basic` before ANY `heading`, regardless of content. A neutral subtitle like "We offer reliable service" gets the `tagline` class (which typically has red/accent color styling). There is NO content-based guard -- it purely checks sibling position.

2. **`tag-grid` over-application**: The `parent_of_pills` rule matches ANY `block` with 3+ `block` children. A CSS grid of feature cards, pricing cards, or team member cards all match. The rule lacks any check for the children being pill-like (small, icon+text).

3. **No negative rules**: There is no way to say "DO NOT apply this class when..." -- only positive matches exist. This means classes get applied when they shouldn't, but there's no mechanism to prevent specific misapplications.

### Recommended additions

| Rule | Element type | Context | Purpose |
|------|-------------|---------|---------|
| `section-dark` | `section` | `has_dark_background` | Auto-tag dark sections |
| `section-hero` | `section` | `has_h1_child` | Auto-tag hero sections |
| `card` | `block` | `has_padding_and_radius` | Auto-detect card blocks |
| `btn-primary` | `button` | `first_button_in_cta` | First button gets primary style |
| `btn-ghost` | `button` | `second_button_in_cta` | Second button gets ghost style |

Also needed: **Guard conditions** on existing rules:
- `tagline`: Add content length check (tagline < 60 chars, or starts with uppercase/emoji pattern)
- `tag-grid`: Add child-size check (children must have `_flexWrap: wrap` or similar pill indicators)

---

## 5. Gap 4: Element Hierarchy Rules

### What's in `data/element-hierarchy-rules.json`

23 element types with `valid_parents` and `accepts_children` rules:

`section`, `container`, `block`, `div`, `heading`, `text-basic`, `text`, `button`, `image`, `icon`, `icon-box`, `divider`, `video`, `tabs-nested`, `accordion-nested`, `nav-nested`, `slider-nested`, `form`, `alert`, `counter`, `list`, `pricing-tables`, `animated-typing`

### Missing from hierarchy rules

Elements that ARE in `NESTING_RULES` (SchemaGenerator) but NOT in `element-hierarchy-rules.json`:
- `text-link` -- in NESTING_RULES, missing from hierarchy rules
- `code` -- in NESTING_RULES, missing
- `map` -- in NESTING_RULES, missing
- `rating` -- in NESTING_RULES, missing
- `countdown` -- in NESTING_RULES, missing
- `progress-bar` -- in NESTING_RULES, missing
- `pie-chart` -- in NESTING_RULES, missing
- `accordion` (basic) -- in NESTING_RULES, missing
- `tabs` (basic) -- in NESTING_RULES, missing
- `slider` (basic) -- in NESTING_RULES, missing
- `offcanvas` -- in NESTING_RULES, missing
- `dropdown` -- in NESTING_RULES, missing
- `toggle` -- in NESTING_RULES, missing
- `popup` -- in NESTING_RULES, missing
- `template` -- in NESTING_RULES, missing

### Missing validation rules that would catch AI mistakes

| Rule | Current behavior | Expected |
|------|-----------------|----------|
| `button` inside `button` | No check | Reject: buttons cannot nest |
| `section` inside `section` | Checked (valid_parents: ["root"]) | Already handled |
| `form` inside `form` | No check | Reject: nested forms are invalid HTML |
| `heading` inside `heading` | No check | Reject: headings cannot nest |
| `section` inside `container` | Checked | Already handled |
| `pie-chart` inside `button` | No hierarchy rule for pie-chart | Add rule: valid_parents should be [container, block, div] |
| `counter` needs minimum content | `accepts_children: false` but no required-settings check | Add required: `countTo` must be numeric |
| `slider-nested` requires child divs | Noted but not enforced | Enforce minimum 1 child div |

### Discrepancy between `NESTING_RULES` and `element-hierarchy-rules.json`

The `NESTING_RULES` constant in SchemaGenerator (30+ elements) and the JSON file (23 elements) are maintained separately and can drift. `container.valid_parents` in the JSON is `["section"]` but in NESTING_RULES it's `["section", "container", "block", "div"]`. This means the JSON is stricter -- a container inside a block would be flagged by the validator but allowed by the schema generator.

---

## 6. Gap 5: Element Defaults

### What's in `data/element-defaults.json`

- `content_keys`: 16 elements mapped to their content setting key
- `default_tags`: heading->h2, text->p, text-basic->p
- `structural_elements`: section, container, block, div
- `flex_by_default`: section, container, block
- `nestable_variants`: tabs->tabs-nested, accordion->accordion-nested, nav->nav-nested
- `skip_auto_flex`: tabs-nested, accordion-nested, nav-nested, slider-nested
- `icon_defaults`: library->themify, prefix->ti-, fallback->ti-star
- `button_defaults`: style->primary, link->{type: external, url: #}
- `form_templates`: newsletter, contact, login (full field definitions)

### Missing defaults that would prevent AI failures

| Element | Missing default | Impact |
|---------|----------------|--------|
| `counter` | No default `countTo` value | AI sends content "500+" but `countTo` expects a numeric integer. The "+" suffix breaks rendering. Default: `countTo: 100` |
| `pie-chart` | No default `percent` | AI must always specify percent or get empty chart. Default: `percent: 75` |
| `progress-bar` | No default `bars` array | Element renders empty without at least one bar definition. Default: `[{label: "Progress", percentage: 75}]` |
| `video` | No default `iframeUrl` | Content key maps to `iframeUrl` but no fallback. AI sends YouTube URLs without the embed prefix. Default: placeholder video URL or auto-convert youtube.com to embed format |
| `rating` | No default `rating` value | Empty rating renders nothing. Default: `rating: 5` |
| `countdown` | No default target date | Countdown to nothing. Default: target date 30 days from now |
| `slider-nested` | No default minimum children | Slider with 0 slides is empty. Should enforce/generate 2-3 placeholder slide children |
| `image` | No default fallback | Empty image element renders broken. Default: `{id: 0, url: "placeholder", alt: "Placeholder image"}` or auto-Unsplash |
| `divider` | No default `_width` | Some themes render dividers as 0-width. Default: `_width: "100%"` |

### Form templates language issue

All three form templates have Romanian-language labels and messages (`"Adresa ta de email"`, `"Inregistrare"`, `"Trimite mesajul"`). These are hardcoded, not localized. Sites in other languages will get Romanian form text unless the AI explicitly overrides every field.

---

## 7. Gap 6: Knowledge Fragments

### What exists in `docs/knowledge/`

| File | Domain | Coverage |
|------|--------|----------|
| `building.md` | General building rules | Comprehensive: structure, centering, classes, variables, gaps, backgrounds, buttons, forms, auto-fixes |
| `forms.md` | Form elements | Good: field types, auto-detection, actions, validation, pitfalls |
| `animations.md` | Interactions | Good: _interactions structure, triggers, actions, pitfalls. Notes _animation deprecation |
| `dynamic-data.md` | Dynamic tags | Good: syntax, format by field type, query loops, Meta Box, pitfalls |
| `components.md` | Reusable components | Good: properties, connections, slots, instantiation |
| `popups.md` | Popup templates | Good: two-system model, triggers, conditions, pitfalls |
| `seo.md` | SEO fields | Good: supported plugins, fields, best practices |
| `woocommerce.md` | WooCommerce | Good: template types, scaffolding, elements, dynamic tags |

### Missing knowledge files

| Recommended file | Domain | Why needed |
|-----------------|--------|------------|
| `charts.md` | Pie chart, counter, progress bar, rating | AI has no guidance on element-specific settings (`percent`, `countTo`, `bars`, `rating`). Currently these settings must be passed through `content` and the content_key mapping, but the mapping is undocumented |
| `responsive.md` | Breakpoint composite keys | `building.md` mentions composite keys briefly but doesn't explain the full system: which breakpoints exist (`tablet_landscape`, `tablet_portrait`, `mobile`), how to use `_property:breakpoint:pseudo` format, common responsive patterns (grid collapse, hide on mobile, font-size overrides) |
| `troubleshooting.md` | Common pitfalls | Cross-cutting issues: `_border.style` crash, `_cssCustom` array warning, `_gap` not generating CSS on flex blocks, `_animation` deprecation, color object format, unknown key rejection. Currently scattered across multiple files |
| `query-loops.md` | Query/loop elements | `dynamic-data.md` mentions query loops briefly but doesn't cover the `query` settings object structure, post type filtering, pagination, or how to build post grids |
| `navigation.md` | Menus, breadcrumbs, nav-nested | No knowledge file covers navigation patterns. The menu tool exists but AI doesn't know how to build navigation elements on pages |
| `media.md` | Images, galleries, video | No dedicated guidance for image optimization, gallery settings, video embed formats, Unsplash integration patterns |
| `style-system.md` | CSS variables, theme styles, typography scales | The variable system (--space-xs through --space-section, color tokens, grid variables) is only partially documented in `building.md`. A dedicated file would cover the full token catalog, how theme styles cascade, and typography scale usage |

---

## 8. Gap 7: Design Pattern Coverage

### Current pattern files

| File | Category | Pattern count | Pattern IDs |
|------|----------|---------------|-------------|
| `heroes.json` | hero | 5 | hero-centered-dark, hero-centered-dark-image, hero-split-cards, hero-split-image, hero-split-form-badges |
| `features.json` | features | 3 | features-icon-grid-3, features-dark-icon-grid-4, features-icon-box-grid |
| `ctas.json` | cta | 3 | cta-centered-dark, cta-split-image, cta-banner-accent |
| `pricing.json` | pricing | 1 | pricing-3-tier |
| `testimonials.json` | testimonials | 2 | testimonials-card-grid, testimonials-dark-single |
| `splits.json` | split | 4 | split-login-form, split-text-image, split-text-pills, split-contact-form |
| `content.json` | generic | 3 | content-faq-accordion, content-stats-counter, content-about-team |

**Total: 21 patterns across 7 categories** (schema enum lists 8 including "content" as "generic")

### Schema category enum vs actual files

The `_schema.json` defines valid categories as: `hero`, `features`, `cta`, `pricing`, `testimonials`, `splits`, `content`, `generic`. The `content.json` file declares `"category": "generic"` -- there is a naming mismatch.

### Missing pattern categories

| Category | Use case | Current coverage | Gap |
|----------|---------|-----------------|-----|
| **stats** | Standalone metrics/numbers section | `content-stats-counter` exists but as "generic" | Should be its own category with variants: stat-row-4, stat-cards-dark, stat-with-icon |
| **faq** | FAQ sections | `content-faq-accordion` exists but as "generic" | Should be its own category with variants: faq-2-column, faq-with-categories, faq-search |
| **team** | Team member sections | `content-about-team` exists but as "generic" | Should be its own category with variants: team-grid-3, team-grid-4, team-with-bio, team-carousel |
| **footer** | Page footer sections | None | footer-4-column, footer-centered, footer-minimal, footer-with-newsletter |
| **blog** | Post grid / article listing | None | blog-grid-3, blog-list, blog-featured-plus-grid, blog-category-tabs |
| **contact** | Full contact sections | `split-contact-form` is a split | contact-centered, contact-map-split, contact-info-cards |
| **comparison** | Feature comparison tables | None | comparison-table-3, comparison-toggle-annual-monthly |
| **timeline** | Process/step visualization | None | timeline-vertical, timeline-horizontal, steps-numbered |
| **logo-bar** | Client/partner logos | None | logo-bar-scrolling, logo-grid-grayscale |
| **before-after** | Image comparison | None | before-after-slider |
| **video** | Video showcase sections | None | video-centered-play, video-split-text |
| **newsletter** | Newsletter signup sections | hero-split-form-badges covers this | newsletter-centered, newsletter-banner, newsletter-footer-inline |

### Pattern structure observations

Patterns reference `class_role` values like `eyebrow`, `hero_description`, `btn_primary`, `btn_ghost`, `stat_card`, `service_card`, `testimonial_card`, `tag_grid`, `tag_pill`, `tag_pill_icon`. These are used by `SchemaSkeletonGenerator::map_classes_to_roles()` to match existing global classes. If a site doesn't have classes with these names, the patterns still work but lose site-specific styling.

---

## 9. Gap 8: Auto-Fix Candidates

### Current auto-fixes in `ElementSettingsGenerator`

| Fix | Location | What it does |
|-----|----------|-------------|
| `_maxWidth` -> `_widthMax` | Line 459-461 | Renames key |
| `_minWidth` -> `_widthMin` | Line 471-473 | Renames key |
| `_textAlign` -> `_typography.text-align` | Line 463-469 | Nests into typography object |
| `_gap` -> `_columnGap`/`_rowGap` | Lines 477-489 | Converts based on `_direction` |
| Dark section text coloring | Lines 291-301 | Auto-sets white `_typography.color` on text children in dark context |
| Auto-center text in centered layouts | Lines 275-288 | Sets `_typography.text-align: center` on text children of flex-centered parents |
| Button icon string -> object | `resolve_icon()` Lines 580-596 | Converts `"truck"` to `{"library": "themify", "icon": "ti-truck"}` |
| Form auto-detection | Lines 386-401 | Detects form type from role/label/content_hint and applies template |
| Unsplash `src` sideload | Lines 659-705 | Converts `"unsplash:query"` to attachment |
| Background Unsplash sideload | Lines 492-525 | Same for `_background.image` |
| Overlay -> gradient conversion | Lines 548-561 | Converts `_background.overlay` to `_gradient` with `applyTo: overlay` |
| Invalid key stripping | Lines 529-533 | Removes unknown `_`-prefixed keys |

Additionally in `ElementNormalizer`:
| Fix | What it does |
|-----|-------------|
| `_gradient` inside `_background` -> top-level | Hoists misplaced gradient |
| `_direction` on `div` -> auto-inject `_display: flex` | Ensures flex works on divs |
| `%root%` -> `#brxe-{id}` in `_cssCustom` | Replaces CSS selector shorthand |

### Recommended new auto-fixes

#### Fix 1: Color string -> color object

**Frequency**: High. AI consistently sends `"var(--primary)"` or `"#333"` where Bricks expects `{"raw": "var(--primary)"}` or `{"hex": "#333"}`.

**Locations affected**: `_typography.color`, `_background.color`, `_color`, `_border.color`

**Implementation**: In `build_settings()` after style_overrides merge, walk all known color positions and wrap bare strings in `{"raw": value}` (for `var()` or `rgba()`) or `{"hex": value}` (for `#hex`).

#### Fix 2: `countTo` numeric extraction

**Frequency**: Medium. AI sends counter content like `"500+"`, `"1,200"`, `"$99"`. The `countTo` setting must be a plain integer.

**Implementation**: Strip non-numeric characters from `countTo` value when it's a string. Extract prefix/suffix into `prefix`/`suffix` settings automatically.

#### Fix 3: YouTube URL -> embed URL

**Frequency**: Medium. Video element's `iframeUrl` receives `https://www.youtube.com/watch?v=ID` instead of `https://www.youtube.com/embed/ID`.

**Implementation**: Detect youtube.com/watch URLs and convert to embed format. Same for vimeo.com links.

#### Fix 4: `_padding`/`_margin` shorthand expansion

**Frequency**: Medium. AI sends `_padding: "var(--space-m)"` as a flat string.

**Implementation**: If `_padding` or `_margin` is a string, expand to `{top: value, right: value, bottom: value, left: value}`.

#### Fix 5: `_boxShadow` CSS string -> Bricks array

**Frequency**: Low-medium. AI sends CSS shorthand `"0 4px 20px rgba(0,0,0,0.1)"`.

**Implementation**: Parse CSS box-shadow syntax into Bricks shadow object format `[{values: {offsetX, offsetY, blur, spread}, color: {raw}}]`.

---

## 10. Recommended Priorities

### Priority 1: Color Object Normalization (Auto-fix #1 + Style Shape Rule)

**Why first**: This is the single most frequent silent failure. Every time an AI sets a color as a string instead of an object, the style silently fails to render. It affects `_typography.color`, `_background.color`, `_color`, and `_border.color` -- touching nearly every design build. The fix is deterministic (wrap strings in objects) and low-risk.

**Files to change**: `ElementSettingsGenerator::build_settings()`, `GlobalClassService::normalize_bricks_styles()`, `ThemeStyleService::normalize_bricks_styles()`

### Priority 2: Element Coverage Expansion (Gap 1)

**Why second**: The 20-element ELEMENT_CAPABILITIES list causes the AI to either avoid elements it could use (`progress-bar`, `pie-chart`, `alert`, `rating`) or attempt to use them without understanding their settings, leading to broken output. Adding capability descriptions for the top 6 missing elements (`progress-bar`, `pie-chart`, `alert`, `animated-typing`, `rating`, `social-icons`) would cover the most common AI requests that currently fail.

**Files to change**: `ProposalService::ELEMENT_CAPABILITIES`, `data/element-hierarchy-rules.json`, `data/element-defaults.json`

### Priority 3: Class Context Guard Conditions (Gap 3)

**Why third**: The `tagline` and `tag-grid` over-application is a visible, user-facing issue. Neutral text blocks get red tagline styling, and card grids get pill-grid wrapping behavior. Adding content-length guards to the `tagline` rule and child-structure checks to `tag-grid` would immediately improve output quality for all design builds.

**Files to change**: `data/class-context-rules.json`, `ClassIntentResolver::suggest_for_context()` (add new context check methods)

---

## Appendix: File Locations

| File | Purpose |
|------|---------|
| `includes/MCP/Services/ProposalService.php` | ELEMENT_CAPABILITIES, VALID_SECTION_TYPES, VALID_LAYOUTS, BUILDING_RULES |
| `includes/MCP/Services/SchemaGenerator.php` | NESTING_RULES, CSS_PROPERTY_MAP, element catalog, schema generation |
| `includes/MCP/Services/GlobalClassService.php` | `normalize_bricks_styles()` -- style shape normalization |
| `includes/MCP/Services/ThemeStyleService.php` | Duplicate `normalize_bricks_styles()` |
| `includes/MCP/Services/ElementSettingsGenerator.php` | Auto-fixes, content key mapping, style override handling |
| `includes/MCP/Services/ElementNormalizer.php` | Key corrections, gradient hoisting, sanitization |
| `includes/MCP/Services/ClassIntentResolver.php` | Context-based class suggestion logic |
| `includes/MCP/Services/DesignSchemaValidator.php` | Schema structure validation, hierarchy enforcement |
| `includes/MCP/Services/SchemaSkeletonGenerator.php` | Plan-to-schema generation |
| `data/class-context-rules.json` | Auto-class suggestion rules (4 rules) |
| `data/element-hierarchy-rules.json` | Parent-child validation rules (23 elements) |
| `data/element-defaults.json` | Content keys, structural elements, form templates |
| `data/css-property-map.json` | CSS property type/format definitions |
| `data/design-patterns/*.json` | 21 patterns across 7 categories |
| `docs/knowledge/*.md` | 8 knowledge files across 8 domains |
