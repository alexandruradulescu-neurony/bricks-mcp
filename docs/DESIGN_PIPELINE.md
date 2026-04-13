# Design Build Pipeline

Complete reference for the 4-step design build workflow in Bricks MCP.

## Overview

When a user says "design a hero", "build me a services page", or "create a pricing section", the MCP routes through a server-enforced 4-step pipeline:

```
DISCOVER → DESIGN → BUILD → VERIFY
```

Each step is gated — you cannot skip to building without first discovering and designing.

## Prerequisites

Before any design build, these three calls must have been made (once per session):

1. `get_site_info` — reads site design tokens, child theme CSS, briefs, existing page summaries
2. `global_class:list` — reads all global classes
3. `global_variable:list` — reads all CSS variables

The prerequisite gate is server-enforced. Flags persist 30 minutes per session. You don't need to re-run these between builds in the same session.

## Step 1: DISCOVER

**Call:** `propose_design(page_id, description)` — without `design_plan`

**Returns:**
- `phase: "discovery"` — no `proposal_id` yet (you cannot build without designing first)
- `available_elements` — 20 common Bricks elements with purpose + capabilities + rules
- `available_layouts` — `centered`, `split-60-40`, `split-50-50`, `grid-2`, `grid-3`, `grid-4`
- `section_types` — `hero`, `features`, `pricing`, `cta`, `testimonials`, `split`, `generic`
- `building_rules` — 10 critical rules (structure, centering, child theme overrides to avoid, etc.)
- `reference_patterns` — 2-3 curated compositions matching your description
- `site_context` — classes (available + suggested), scoped CSS variables, design/business briefs
- `existing_page_sections` — top 5 sections already on the target page (label, description, background, layout, classes_used)
- `site_style_hints` — aggregated common layouts, backgrounds, frequently used classes
- `bootstrap_recommendation` — appears when site has fewer than 5 global classes. Includes 13 starter class definitions.
- `design_plan_format` — structural template for the design_plan

**Slim response optimization:** If site_context hasn't changed since a previous discovery in the same session, the response is compacted (~3KB vs ~16KB). `site_context_hash` confirms cache validity.

**Skip for subsequent sections:** Once discovery happened, you can skip Phase 1 and go straight to proposal with design_plan. The `next_step` text explains this.

## Step 2: DESIGN

Think as a designer using the discovery data:

1. Pick the closest matching `reference_pattern`
2. Study `existing_page_sections` — reuse their classes/layouts for consistency
3. Decide: `section_type`, `layout`, `background`, `elements`, `patterns`

**Call:** `propose_design(page_id, description, design_plan)` — with full design_plan

**design_plan format:**

```json
{
  "section_type": "hero",
  "layout": "split-60-40",
  "background": "dark",
  "background_image": "unsplash:tow truck night",
  "elements": [
    {"type": "text-basic", "role": "eyebrow", "content_hint": "Uppercase tagline", "class_intent": "eyebrow"},
    {"type": "heading", "role": "main_heading", "tag": "h1", "content_hint": "Value proposition"},
    {"type": "text-basic", "role": "subtitle", "content_hint": "Supporting paragraph", "class_intent": "hero-description"},
    {"type": "button", "role": "primary_cta", "content_hint": "Phone CTA with ti-mobile icon", "class_intent": "btn-hero-primary"},
    {"type": "button", "role": "secondary_cta", "content_hint": "WhatsApp button", "class_intent": "btn-hero-ghost"}
  ],
  "patterns": [
    {
      "name": "stat-card",
      "repeat": 4,
      "element_structure": [
        {"type": "icon", "role": "card_icon"},
        {"type": "heading", "role": "card_title", "tag": "h4"},
        {"type": "text-basic", "role": "card_desc"}
      ],
      "content_hint": "Key business statistics"
    }
  ]
}
```

**Server validates:**
- `section_type` is a valid enum value
- `layout` is a valid enum value
- `background` is `dark` or `light`
- Each element has `type`, `role`, `content_hint`
- Each pattern has `name`, `repeat`, `element_structure`

**Returns:**
- `phase: "proposal"`
- `proposal_id` (10-minute TTL)
- `suggested_schema` — complete schema built from your design_plan, pattern columns, gap/padding defaults
- `resolved.element_schemas` — real Bricks schemas for the element types you chose
- `resolved.classes_suggested` — matched existing classes

## Step 3: BUILD

**Call:** `build_from_schema(proposal_id, schema)`

Take the `suggested_schema`, replace `[PLACEHOLDER]` content with real text, and send. Do NOT invent new keys — the validator rejects them.

**Valid node keys:**
`type`, `tag`, `label`, `content`, `class_intent`, `style_overrides`, `responsive_overrides`, `layout`, `columns`, `responsive`, `background`, `children`, `icon`, `iconPosition`, `src`, `form_type`, `ref`, `repeat`, `data`

**Unknown keys are rejected** with suggestions: `"settings"` → `"Did you mean style_overrides?"`

**Pipeline steps (server-side):**

1. Validate proposal exists (10-min TTL)
2. Validate schema structure + hierarchy + unknown keys
3. Check protected pages
4. Extract element types + class intents + class styles
5. Resolve classes (match existing by name OR create new WITH styles)
6. Prefetch Bricks element schemas for chosen types
7. Expand patterns (repeat + data substitution)
8. Re-validate expanded hierarchy
9. Generate Bricks element settings with auto-fixes:
   - Dark sections auto-color text white on children
   - Button `icon` → native Bricks icon settings
   - Form without fields → auto-detect type, apply template
   - `_gap` → `_columnGap`/`_rowGap` based on `_direction`
   - `_maxWidth` → `_widthMax`, `_textAlign` → `_typography.text-align`
   - `unsplash:query` in `_background.image` → sideload and replace
   - `background: "dark"` merges with existing background (preserves images)
10. Auto-snapshot the page for rollback safety
11. Write elements to Bricks

**Returns:**
- `success: true`
- `elements_created` count
- `classes_created` and `classes_reused` arrays
- `tree_summary` — compact text representation
- `snapshot_id` for rollback

## Step 4: VERIFY

**Call:** `verify_build(page_id)` — optionally with `section_id` for one section

**Returns rich output via `describe_page()`:**
- `page_description` — e.g., "Homepage — 3 sections"
- `sections` — per-section: label, description ("Dark section with background image and overlay. Contains h1, text, 2 buttons"), background, layout, classes, element_count
- `type_counts` — count per element type
- `classes_used` — resolved class names
- `labels` — all structural labels
- `last_section` — hierarchy summary

Compare against your `design_plan` to confirm the build matches intent.

## Building Efficiency

Building 3 sections in one session:

| Step | Calls | Notes |
|---|---|---|
| Prerequisites | 3 | Once per session |
| Section 1: discover | 1 | Full discovery |
| Section 1: design | 1 | With design_plan |
| Section 1: build | 1 | |
| Section 1: verify | 1 | |
| Section 2: design | 1 | Skip discover — use cached context |
| Section 2: build | 1 | |
| Section 2: verify | 1 | |
| Section 3: design | 1 | |
| Section 3: build | 1 | |
| Section 3: verify | 1 | |
| **Total** | **13** | (10 if skipping verify) |

Static caching inside the server:
- `GlobalClassService` caches all classes in a static property (loaded once per request)
- `SiteVariableResolver` caches variables by category
- `DesignPatternService` caches loaded patterns
- Discovery response uses hash-based cache for slim subsequent responses

## Design Pattern Library

Located in `data/design-patterns/`:

| Category | Patterns |
|---|---|
| heroes | centered-dark, centered-dark-image, split-cards, split-image, split-form-badges |
| splits | login-form, text-image, text-pills, contact-form |
| features | icon-grid-3, dark-icon-grid-4, icon-box-grid |
| ctas | centered-dark, split-image, banner-accent |
| pricing | 3-tier |
| testimonials | card-grid, dark-single |
| content | faq-accordion, stats-counter, about-team |

Each pattern defines:
- `layout`, `background`, `tags` — for matching
- `section_overrides`, `container_overrides` — applied by skeleton generator
- `columns` — left/right with `alignment`, `padding`, `gap`, `max_width`, `fill`, `elements`
- `has_two_rows` + `rows.row_1`/`rows.row_2` — for multi-row patterns (hero with badges below)
- `patterns` — embedded repeating structures (cards, badges)

Patterns capture **composition** (what elements, in what arrangement). The site's design system handles **appearance** (colors, sizes, radius).

## Troubleshooting

**"Proposal has expired"** — 10-minute TTL. Re-run propose_design with design_plan.

**"unknown key X"** — the validator rejects non-schema keys. Check the valid keys list. Most common mistake: `"settings"` should be `"style_overrides"`.

**Background image not appearing** — `apply_background()` merges with existing `_background`, but if you set a color ONLY (via `background: "dark"`) without an image first, no image is added. Use `background_image: "unsplash:query"` in the design_plan to have the pipeline resolve one, or manually update the section after building.

**Form has wrong fields** — the pipeline auto-detects form type from `role`/`content_hint`. Check your design_plan element's role text. Explicitly set `form_type` in the element for override.
