=== Bricks MCP ===
Contributors: alexradulescu
Tags: ai, bricks builder, mcp, artificial intelligence, page builder
Requires at least: 6.4
Tested up to: 6.9
Stable tag: 3.33.6
Requires PHP: 8.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI assistants like Claude to your Bricks Builder site. Build and edit pages using natural language — no clicking required.

== Description ==

Bricks MCP turns your WordPress site into an AI-controlled page builder. It implements the Model Context Protocol (MCP) — an open standard for connecting AI assistants to external tools — so that any MCP-compatible client (Claude Desktop, Claude Code, and others) can read and modify your Bricks Builder pages through plain conversation.

The plugin supports four workflows:

1. **Direct operations** — Edit text, move elements, swap images, update menus. The AI uses element/page/menu tools directly.
2. **Instructed builds** — Add a heading and two columns, insert a form. The AI uses bulk_add or append_content with awareness of your global classes.
3. **Design builds** — Design a services page, build a landing page, redesign the hero. Server-enforced four-step pipeline: **propose_design** (Phase 1 discovery returns site context + element capabilities + reference patterns; Phase 2 consumes a design_plan and returns a proposal_id + validated schema) → **build_structure** (writes the structural element tree with class creation + variable resolution + auto-fixes, no content) → **populate_content** (injects content_map into role-tagged elements) → **verify_build** (human-readable section descriptions, content extraction, min-height + background-color per section, placeholder detection).
4. **Vision-driven pattern capture** — Paste a screenshot or Bricks clipboard JSON; the AI vision translator (`design_pattern(action: from_image)`) produces a site-BEM design_plan with global_classes_to_create (inferring styles from the image using site variables), auto-sideloads per-element images via business-brief-enriched Unsplash search, and — when a target page_id is supplied — auto-chains through the design-build pipeline above. Supports three input modes: image only, reference_json only (text-only Claude translates foreign classes → site BEM + foreign variables → site equivalents), image + JSON. The incoming plan is normalized before build: role keys are canonicalized, duplicate/empty class creates are dropped, and invalid class guesses are remapped to resolved semantic roles or generated fallback component classes.

Tell your AI assistant "design a services section with three feature cards" and the design-build pipeline analyzes your existing site, picks a reference pattern, generates a valid schema, and writes the final Bricks elements with proper global classes and CSS variables.

= Site-Aware Building =

When connecting to a site with existing designed pages, the discovery phase analyzes those sections and tells the AI to reuse the same classes, layouts, and background treatments for visual consistency. It now reports separate readiness layers for foundation tokens, component classes, and pattern coverage so a fresh site, plugin-managed design system, and custom existing system are handled differently.

For existing systems with custom names, use `style_role` mappings instead of renaming or hardcoding. Example: map `button.primary` to an existing `.cta-main` class, or `color.primary` to an existing `--brand` variable. The build pipeline treats those mappings as site-owned and does not overwrite the underlying classes or variables. If a semantic component role is still missing, the proposal can generate token-driven fallback classes in a dedicated "Bricks MCP Components" category.

= Design Pattern Library =

Database-backed section composition library. Patterns capture *composition* — what elements in what arrangement — not appearance. The site's design system handles how sections actually look. Patterns live in the `bricks_mcp_patterns` option and are fully editable via the Patterns admin tab or the `design_pattern` tool.

Patterns can be added four ways:
- **Manual authoring** — hand-write via Patterns admin or `design_pattern:create(pattern)`
- **Capture live section** — `design_pattern:capture(page_id, block_id, name, category)` snapshots an existing built section with style snapshots into the library
- **Import portable JSON** — `design_pattern:import(patterns)` with cross-site class/variable reconciliation
- **Vision capture** — `design_pattern:from_image` reads a screenshot (or Bricksies-format clipboard JSON, or both) and produces a site-BEM pattern, including auto-sideloaded Unsplash images when a business-brief is set

Phase 1 of `propose_design` automatically scopes the catalog to the detected section_type and returns the best matches with `structural_summary` + `ai_description` + `ai_usage_hints` for AI selection. Drift detection compares a pattern's embedded class fingerprints against the current live site — surfaces via `design_pattern:get(include_drift: true)`.

= Pipeline Auto-Fixes =

- `_gap` on flex blocks auto-converts to `_columnGap` / `_rowGap` based on direction
- `_maxWidth` → `_widthMax`, `_textAlign` → `_typography.text-align`
- Button `icon` → native Bricks icon settings (no emoji)
- Form without fields → warning with guidance to supply `fields` via `element_settings`
- `unsplash:query` in `_background.image` → auto sideload
- `background: "dark"` → merges with existing background settings (preserves images)
- Unknown schema keys → rejected with suggestions ("Did you mean style_overrides?")
- New class intents without a style source → rejected before write
- `from_image` / `reference_json` class guesses with no valid style source → normalized or dropped before pre-creating classes
- Proposal/build role keys are canonicalized before validation and content injection; duplicate direct roles are rejected early, and `build_structure` returns `role_collisions` if built labels still collide
- `propose_design` Phase 2 and `design_pattern(from_image)` surface `design_plan_warnings` for weak but still-buildable plans before anything is written
- Weak generic roles in direct/image plans are enriched before validation/build. The pipeline can rewrite roles like `heading`, `text`, `button`, and `image` into more useful semantic roles and synthesize missing `content_hint` values.
- If a direct/image plan is still structurally thin after enrichment, the server can repair it before validation/build by inserting missing singleton anchors like `main_heading`, `subtitle`, `section_heading`, `primary_cta`, or a split-layout media element. Responses include `repair_log` when this happens.
- Repeated `patterns[]` items now expand to unique role labels per clone (for example `feature_card_1_title`, `feature_card_2_title`, `tier_3_cta`) so `build_structure` and `populate_content` can target repeated content without role collisions.
- Proposal `content_plan` now includes indexed repeated-item hints when `patterns[]` are used, so repeated cards/tiers/testimonials have concrete keys and guidance before the content phase.
- If a direct/image plan models repeated items inline with indexed flat roles (for example `feature_card_1_title`, `tier_price_2`), the server can automatically convert that flat shape into `patterns[]` and returns `repeat_extraction_log`.
- An extensible composition layer now reshapes weak plans before build. It can reorder elements into a more coherent flow, infer broad composition families, and adjust obviously weak layouts like repeat-heavy centered stacks or media/text sections that should be split. Responses can include `composition_family` and `composition_log`.
- `populate_content` enforces required content roles unless `allow_partial=true`

= Design System Generator =

The plugin ships with a visual design system editor under Bricks MCP → Design System. A left-rail stepper walks through seven editable sections:

- **Spacing** — base mobile/desktop + scale ratio; seven clamp-based steps (xs → xxl, section)
- **Typography** — separate scales for headings (h1–h6) and body text (xs → xxl) with live "Heading" / "Body text" previews, HTML font-size toggle (62.5% vs 100%), and text styles (text/heading color, font weights, line heights)
- **Colors** — six families (primary, secondary, tertiary, accent, base, neutral) + white/black. Per-family toggles for Enable, Expand Color Palette (5 vs 8 shades), Transparencies (9 alpha steps). Hover variants auto-derived, editable. Core shades written to the Bricks color palette; expanded shades + transparencies available as CSS variables.
- **Gaps / Padding** — grid-gap, card-gap, content-gap, container-gap, padding-section, offset. Accept `var()` references.
- **Radius** — individually editable variants (radius, radius-inside, radius-outside, radius-btn, radius-pill, radius-circle, radius-s/m/l/xl) plus border colors and border widths (thin/medium/thick). Visual shape indicators.
- **Sizes** — container width / min-width (drive the clamp formula), max-widths, min-heights, logo-width mobile/desktop pair, and aspect ratios (square/video/photo/portrait/wide).
- **Effects** — shadows (xs → xl + inset) with live preview boxes, transitions (durations fast/base/slow + easings out/in-out/spring), and z-index layers (base → tooltip).

Apply writes to `bricks_global_variables` (namespaced replace across fourteen owned categories: Spacing, Texts, Headings, Gaps/Padding, Styles, Radius, Sizes, Colors, Grid, Shadows, Transitions, Z-Index, Borders, Aspect Ratios), `bricks_color_palette` (Bricks-WP-MCP palette with parent/child shade hierarchy + hover entries), and `bricks_global_settings['customCss']` (framework CSS between markers). Configs from earlier plugin versions are auto-migrated on read — no user action required.

= How It Works =

The plugin registers a REST API endpoint on your WordPress site that speaks the MCP protocol. You add the endpoint URL to your AI client's MCP configuration, authenticate with a WordPress Application Password, and your AI can start working with your site immediately.

An intent router classifies every request into one of the four workflows above, each with server-enforced prerequisites (site info, global classes, CSS variables, knowledge domains). A design-build gate blocks any write operation that would introduce a root-level `section` element outside the two-tier pipeline, redirecting callers to `propose_design → build_structure → populate_content → verify_build` for validation, class resolution, and design consistency. Non-section element writes (adding a button, swapping an image, updating text) are not gated. The gate can be bypassed per-call with `bypass_design_gate: true` when there is a specific reason (e.g. restoring a clipboard paste verbatim). A knowledge-domain gate (v3.33.1+) blocks class writes that include styles until the session has fetched `bricks:get_knowledge('global-classes')` + `bricks:get_knowledge('building')` — Bricks has non-obvious per-key conventions (kebab-case inside `_typography`, camelCase at top-level, child-theme heading specificity) and silent failures were shipping broken classes. All destructive operations (delete, bulk replace, cascade remove) are gated behind single-use confirmation tokens.

= Available Tools =

* **get_site_info** — Site config, design tokens, color palette, page summaries
* **get_onboarding_guide** — Auto-orientation for AI assistants on new sessions: workflows, quick-start examples, site context, briefs
* **confirm_destructive_action** — Token-based confirmation for delete/replace operations
* **page** — List, search, get (detail/summary/context/describe views), create, update content, append, import clipboard, delete, duplicate, snapshots, SEO
* **element** — Add, update, remove, move, bulk add/update, duplicate, find, copy styling between elements
* **template** — Manage Bricks templates (header, footer, content, popup), import from URL / JSON / clipboard, export
* **template_condition** — Set template display conditions
* **template_taxonomy** — Manage template tags and bundles
* **bricks** — Enable/disable Bricks on pages, builder settings, element schemas, breakpoints, dynamic tags, query types, form/interaction/component/popup/filter/condition schemas, global queries, AI notes, on-demand knowledge fragments (8 domain guides)
* **global_class** — Create/edit/delete CSS classes with styles, batch operations, import CSS/JSON, categories, semantic search, render sample
* **global_variable** — Manage CSS variables and categories
* **color_palette** — Manage color palettes and individual colors
* **typography_scale** — Manage typography scale variables
* **theme_style** — Manage Bricks theme styles (site-wide typography, colors, spacing)
* **style_role** — Map semantic roles like `button.primary`, `card.default`, and `color.primary` to existing site classes/variables
* **component** — List/create/update components, instantiate, update properties, fill slots
* **font** — Adobe Fonts, font settings, webfont loading
* **code** — Page CSS and custom scripts
* **media** — Unsplash search, sideload images, manage featured images, image settings, smart_search (business-brief-enriched Unsplash)
* **menu** — Create/edit/delete menus, assign to locations
* **wordpress** — Get posts/users/plugins, activate/deactivate plugins, create/update users
* **metabox** — Read Meta Box custom fields, list field groups, get dynamic tags
* **woocommerce** — WooCommerce status, elements, dynamic tags, template scaffolding
* **propose_design** — Two-phase design proposal: Phase 1 returns site context + element capabilities + reference patterns + recommended_knowledge domains; Phase 2 (with design_plan) returns proposal_id + validated suggested_schema + `design_plan_warnings` + enrichment/normalization traces when the server had to improve weak role/hint data. The schema is structure-only — content is injected separately via populate_content for reliability.
* **propose_page_layout** — Maps page intent (landing, services, about, product, contact) to a sequenced list of section types with recommended pattern IDs and ready-to-use design_plan skeletons
* **build_structure** — Phase 1 of the two-tier build: consumes proposal_id + structure-only schema, resolves class intents (reuse or auto-create classes with site variables), expands patterns, generates element settings, resolves CSS variables, writes elements. Returns section_id + role_map for content injection. Emits non-blocking validation warnings (grid-column vs child-count mismatches, empty content, missing responsive overrides, heading hierarchy issues like duplicate h1 / skipped levels).
* **populate_content** — Phase 2 of the two-tier build: takes the section_id from build_structure + a role → content map, injects text / image-object / link values into the right elements. Role keys match `role` fields on schema elements; prefix a key with `#` to target a specific element ID directly.
* **verify_build** — Post-build verification: element count, type counts, classes used, human-readable section descriptions, per-section styles (min_height, inline background_color), content_sample (extracted headings / buttons / texts + placeholder-content detection), and hierarchy tree for the last-built section so the AI can self-verify the result matches intent.
* **design_pattern** — Manage the database-backed pattern library. Actions: `capture` a live section as a pattern, `list`, `get`, `create`, `update`, `delete`, `export`, `import`, `mark_required` (roles that must be populated during use), and **`from_image`** — vision-based pattern creation with three input modes (image only, reference JSON only, or both). When `page_id` is supplied to `from_image` the pattern is auto-built on the page via the pipeline. Requires Anthropic API key for `from_image`.

All tools are free to use. The plugin is open source and hosted on [GitHub](https://github.com/alexandruradulescu-neurony/bricks-mcp).

= Authentication =

All requests are authenticated using WordPress Application Passwords, the built-in authentication system available since WordPress 5.6. No third-party authentication service is involved.

= Requirements =

* WordPress 6.4 or later
* PHP 8.2 or later
* Bricks Builder theme 2.0 or later (required — the plugin does not boot without Bricks)

= Getting Started =

1. Install and activate the plugin.
2. Go to **Bricks → Bricks WP MCP → Connection & Settings** and enable the plugin.
3. Create a WordPress Application Password under **Users > Profile**.
4. Add the MCP server URL to your AI client configuration.
5. (Optional) Under **Bricks → Bricks WP MCP → AI Context**, fill the **Business Brief** (what the site does, target audience, tone) and **Design Brief** (visual language, button/card conventions, dark-section usage). AI reads these during discovery and uses them to generate on-brand content + drive business-context-enriched Unsplash searches via `media:smart_search`.
6. (Optional) Under **Bricks → Settings → API Keys**, add an **Unsplash Access Key** (Bricks' own setting — the plugin reads `apiKeyUnsplash` / `unsplashAccessKey` from Bricks global settings) to enable image search / sideload.
7. (Optional) Under **Bricks → Bricks WP MCP → Connection & Settings**, add an **Anthropic API key** (`sk-ant-...`) to enable the `design_pattern(from_image)` vision tool.
8. Start building pages with natural language.

Full setup documentation is available in the [GitHub repository](https://github.com/alexandruradulescu-neurony/bricks-mcp).

== External Services ==

This plugin optionally connects to two external services.

**Service:** Unsplash (api.unsplash.com)
**When used:** Only when the `media:search_unsplash` / `media:smart_search` / `media:sideload` tools are called by an AI assistant, and only if you have configured an Unsplash Access Key under Bricks → Settings → API Keys.
**What is sent:** Your search query string and your Unsplash API key. Sideload additionally downloads the chosen image URL into your WordPress media library.
**Unsplash Terms of Service:** https://unsplash.com/terms
**Unsplash Privacy Policy:** https://unsplash.com/privacy
**Unsplash API Guidelines:** https://unsplash.com/documentation

**Service:** Anthropic Messages API (api.anthropic.com)
**When used:** Only when the `design_pattern(action: from_image)` tool is called by an AI assistant, and only if you have configured an Anthropic API key (`sk-ant-...`) under Bricks → Bricks WP MCP → Connection & Settings.
**What is sent:** The model name, messages array (base64-encoded image bytes when an image is supplied, otherwise text-only for reference_json translation), site-context text block (existing class names, CSS variable names, inferred theme), optional reference_json template, and the tool schema. Outputs are returned as a `tool_use` block containing the `description` + `design_plan` + `global_classes_to_create` + `content_map`.
**Anthropic Terms of Service:** https://www.anthropic.com/legal/commercial-terms
**Anthropic Privacy Policy:** https://www.anthropic.com/legal/privacy

No data is sent to either service unless you explicitly configure the corresponding API key and an AI assistant invokes a tool that uses it.

No other external services are contacted by this plugin.

== Installation ==

1. Upload the `bricks-mcp` folder to the `/wp-content/plugins/` directory, or install the plugin via the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Bricks → Bricks WP MCP → Connection & Settings** to configure the plugin.
4. Enable the MCP server and optionally require authentication (strongly recommended for production sites).
5. Go to **Users > Your Profile** and scroll to **Application Passwords**. Create a new Application Password and copy it — you will need it for your AI client.
6. Add your site's MCP endpoint URL and credentials to your AI client (see the [GitHub repository](https://github.com/alexandruradulescu-neurony/bricks-mcp) for client-specific setup guides).
7. (Optional) Enter an **Unsplash Access Key** under **Bricks → Settings → API Keys** (Bricks-level setting, read via `apiKeyUnsplash` / `unsplashAccessKey`).
8. (Optional) Enter an **Anthropic API key** (`sk-ant-...`) under **Bricks → Bricks WP MCP → Connection & Settings** to enable the `design_pattern(from_image)` vision tool (screenshot → site-BEM pattern via Claude).
9. (Optional) Fill the **Business Brief** and **Design Brief** under **Bricks → Bricks WP MCP → AI Context** to give the AI site context for content generation and design decisions.

== Frequently Asked Questions ==

= What is MCP (Model Context Protocol)? =

MCP is an open protocol created by Anthropic that gives AI assistants a standard way to connect to external tools and data sources. It works like a universal adapter: the AI client connects to an MCP server, discovers what tools are available, and calls them by name. This plugin implements that server for WordPress and Bricks Builder.

= Does this plugin work without Bricks Builder? =

No. Bricks Builder 2.0+ must be installed and active — the plugin refuses to boot otherwise. All tools assume Bricks data structures (`bricks_global_classes`, `bricks_global_variables`, page `_bricks_page_content_2` meta, Bricks element registry) and there is no non-Bricks fallback path.

= Which AI tools and clients are supported? =

Any MCP-compatible client can connect to this plugin. Verified clients include Claude Desktop and Claude Code. Because MCP is an open protocol, support for other clients is expected to grow over time.

= Is it safe to expose a REST API endpoint for AI access? =

Yes, when configured correctly. The plugin includes multiple security layers: WordPress Application Password authentication (enabled by default), per-tool capability checks, configurable rate limiting (10-1000 RPM, default 120), a Dangerous Actions toggle that gates JavaScript/code injection, token-based confirmation for destructive operations (delete, replace, cascade remove), auto-snapshots before content writes, element count safety checks that prevent accidental content wipes, and centralized CSS sanitization. Never disable authentication on a publicly accessible site.

== Screenshots ==

1. The Bricks MCP settings page under Bricks → Bricks WP MCP.
2. Example Claude Desktop configuration connecting to the MCP server endpoint.
3. An AI assistant creating a Bricks Builder hero section from a plain-text prompt.

== Changelog ==

= 3.33.6 =
**Guidance cleanup for Claude/client failures**

* Changed: Active setup docs now use the real admin path: Bricks → Bricks WP MCP, with Connection & Settings / AI Context tab names.
* Changed: Runtime Anthropic, dangerous-actions, protected-page, and Unsplash warnings now point to the correct UI locations.
* Changed: Unsplash guidance consistently says the key lives in Bricks → Settings → API Keys, because the plugin reads Bricks global settings (`apiKeyUnsplash` / `unsplashAccessKey`).
* Changed: Activation warning now states Bricks Builder 2.0+ is required and the plugin will not run until Bricks is active.
* Fixed: Class and element style normalization now converts camelCase typography keys (`fontSize`, `fontWeight`, etc.) to Bricks' required kebab-case keys before save, and build responses warn when styles reference missing site variables or foreign `var(--brxw-*)` tokens.

= 3.33.5 =
**Claim-alignment pass — Bricks 2.0 floor, option name, actions, Unsplash location, design-gate scope**

Second audit caught docs/code drift across several surfaces. Every claim now matches code behavior.

* Changed: `BRICKS_MCP_MIN_BRICKS_VERSION` 1.12 → 2.0. Requirements in readme updated to match.
* Changed: FAQ "Does this plugin work without Bricks?" — now "No" (plugin refuses to boot without Bricks; no fallback path).
* Changed: Pattern library option name corrected — `bricks_mcp_design_patterns` → `bricks_mcp_patterns` (actual key at `BricksCore::OPTION_PATTERNS`).
* Changed: `design_pattern` action list in readme — dropped `semantic_search` / `normalize` / `generate_prompt` / category CRUD (not registered on this handler). Actual actions: capture, list, get, create, update, delete, export, import, mark_required, from_image.
* Changed: Unsplash key location — the plugin reads from Bricks' own global settings (`apiKeyUnsplash` / `unsplashAccessKey` under Bricks → Settings → API Keys), not Bricks MCP Advanced (only Anthropic lives there).
* Changed: design-gate scope clarified — blocks root-level `section` writes only, not "section-level layouts and large element trees". Non-section writes are not gated. `bypass_design_gate: true` per-call.
* Changed: remaining `build_from_schema` references in knowledge docs (`building.md`, `global-classes.md`) replaced with two-tier pipeline naming.

= 3.33.4 =
**Stale-claim cleanup — pattern seed + diagnostic false-positive**

* Fixed: `data/knowledge/building.md` "Design Pattern Library" section — no longer claims "21 curated patterns auto-migrated on first install". The plugin does not bundle a pattern seed; libraries start empty and are populated via capture / import / from_image / manual authoring.
* Fixed: `DesignPipelineCheck` admin diagnostic — empty pattern library no longer flagged as a failure. Removed the stale "deactivate + reactivate to trigger seed" fix step (no seed code exists).

= 3.33.3 =
**readme main description refresh**

The plugin description + tool list were stale — still referring to `build_from_schema` (removed v3.31.0) and missing v3.29–v3.33 features.

* Changed: Description now lists 4 workflows (added vision-driven pattern capture). Design-build pipeline corrected: `propose_design → build_structure → populate_content → verify_build`.
* Changed: Tool list — dropped `build_from_schema`, added `build_structure` + `populate_content` + enriched `verify_build` + `design_pattern(from_image/capture/mark_required)`.
* Changed: Pattern library section rewritten — database-backed, 4 creation paths (manual/capture/import/vision), drift detection documented.
* Changed: External Services — Anthropic API declared (used only when `design_pattern(from_image)` invoked + key configured).
* Changed: Getting Started / Installation — added Briefs + Anthropic API key setup steps.

= 3.33.2 =
**Documentation — key conventions, specificity trap, verification**

* Changed: `data/knowledge/global-classes.md` — added explicit "Setting Key Conventions (CRITICAL — silent failure)" section with camelCase-vs-kebab tables, object-shape reference, heading specificity trap + `text-basic` workaround, `render_sample` verification workflow. 3 new pitfall entries.
* Changed: `data/knowledge/building.md` — added key-convention warning up front, heading specificity explanation, v3.33.1 gate reference.

= 3.33.1 =
**Hard gate: class writes require knowledge read first**

Bricks style-setting keys have non-obvious conventions (kebab inside `_typography`, camel at top-level, object for `_border.radius`, child-theme tag specificity). Writing classes without reading the knowledge docs silently ships broken CSS.

* Added: hard gate on `global_class:create` / `batch_create` / `update` — blocks operations carrying underscore-prefixed style keys until `bricks:get_knowledge('global-classes')` and `bricks:get_knowledge('building')` have been called in the session. Returns `knowledge_gate_blocked` error with fix instruction.
* Fixed: `VisionPromptBuilder` Rule 8a — corrected typography subkey convention from camelCase to kebab-case. The v3.33.0 rule was wrong; emitted classes only rendered `color`. Also added heading `font-size` specificity warning (child-theme h1..h6 tag selectors beat class styles).

= 3.33.0 =
**Intelligence layer final polish**

* Added: heading hierarchy warning — non-blocking flag when a section has multiple h1 or skips levels (h2 → h4). Accessibility improvement.
* Added: `verify_build` now reports `styles.min_height` and `styles.background_color` per section. Raw CSS values — verify hero height / bg without browser.
* Added: 10 more elements now have `element_settings_defaults` (accordion-nested, countdown, image-gallery, dropdown, offcanvas, popup, toggle, posts, form, carousel). Pipeline auto-merges defaults when `element_settings` omitted.
* Changed: `_cssCustom` added to `DANGEROUS_SETTINGS_BLOCKED`. Closes element_settings bypass for raw CSS injection.

= 3.32.3 =
**Fix: [object Object] in class settings**

v3.32.2 vision emitted class settings like `_width: {maxWidth: "var(--max-width)"}`. Bricks width/height use DISCRETE scalar keys (`_width`, `_widthMax`, `_widthMin`, `_height`, `_heightMax`, `_heightMin`) — nested shapes render as literal `[object Object]` in the UI.

* Fix: `VisionResponseMapper` now flattens nested dimension objects in every `global_classes_to_create[].settings` before passing to `GlobalClassService::create_from_payload`. `_width: {maxWidth: x}` → `_widthMax: x` automatically.
* Fix: VisionPromptBuilder Rule 8a documents Bricks setting shapes — scalar keys (width, height, display, flex*, textAlign, columnGap, rowGap, etc.) vs nested keys (padding, margin, border), typography camelCase, with explicit correct/wrong examples.

= 3.32.2 =
**Fix: empty class shells + image-gallery has no images**

v3.32.1 pipeline worked structurally but generated near-empty class styles (`{ text-align: center }`, `{}`, `{}`) and `image-gallery` elements emitted zero sideloaded images.

* Fix: `global_classes_to_create[]` is now REQUIRED (was OPTIONAL) for every new class_intent label. Vision infers style values from the image, expressed via site variables (`var(--text-2xl)`, `var(--space-m)`, etc.).
* Fix: `ImageSideloadService::sideload` now walks `type: image-gallery` elements in addition to `type: image`. Resolves image count from `count` field → integer in content_hint → number-word → default 5 (cap 12). Populates `items: [{id}, ...]` array on the element.

= 3.32.1 =
**Fix: vision container-in-container schema error**

v3.32.0's vision prompt said "elements[] is flat leaves only, no wrappers" but the type enum still allowed section/container/block/div. Claude reliably emitted a `container` for image galleries, producing `container is not typically placed inside container` schema validation errors downstream.

* Fix: `emit_design_plan` tool schema — `elements[].type` and `patterns[].element_structure[].type` enums now EXCLUDE section/container/block/div. Pipeline owns wrappers; vision emits leaves only.
* Fix: `VisionResponseMapper::extract_tool_output` strips any wrapper-typed entries that slip past the schema enum. Defensive filter before design_plan reaches the skeleton generator.
* Changed: prompt Rule 3 + Rule 4 — explicit guidance for image galleries (use `type: image-gallery` for 2-5 tile rows; `patterns[]` for >2 identical repeats).

= 3.32.0 =
**Pattern from Image Corrective Rework (M3.1)**

v3.31.0 shipped the `from_image` path with architecturally wrong output: vision emitted a parallel schema with inline style values, bypassing the existing build pipeline. Result: 0 classes applied, empty image boxes. v3.32.0 is a full rewrite into a thin translation layer that feeds the existing `propose_design → build_structure → populate_content → verify_build` pipeline.

* Changed: `design_pattern(action: "from_image")` now emits the exact shape a human types into `propose_design(description, design_plan)`. When `page_id` is supplied, auto-chains through the full build pipeline.
* Changed: `class_intent` is a BEM string label ONLY (no style values, no `var(--*)`). ClassIntentResolver creates classes with site design tokens.
* Added: 3 input flows — image only, reference_json only (text-only Claude translates foreign classes/variables to site equivalents), image + reference_json (structure from JSON, content from image).
* Added: `ImageSideloadService` — per-element Unsplash sideload via `media:smart_search(content_hint + business_brief)` before proposal staging. No more grey placeholder boxes.
* Added: `ReferenceJsonTranslator` — text-only Claude call for JSON-only flow. Translates Bricksies globalClasses → site-BEM + `var(--brxw-*)` → closest site variable.
* Added: `VisionProvider::call_text_only(messages, tool_schema)` — interface method for non-vision Claude calls.
* Removed: `VisionPatternGenerator` service (replaced by direct orchestration in DesignPatternHandler). `propose_design(image_*)` args removed — migrate to `design_pattern(action: "from_image", page_id: N)`.
* Note: Pattern library save deferred for from_image flow. Canonical-shape save planned for M3.2.

= 3.31.0 =
**Pattern from Image + build_from_schema Removal (M3)**

AI vision can now generate Bricks patterns directly from screenshots. Point a URL, upload a file, or paste base64 — the plugin calls Anthropic Claude (Sonnet 4.5) with a tool-use schema and gets back a validated pattern or design_plan. Server-side vision means any MCP client works, not just vision-capable ones.

* Added: `design_pattern(action: "from_image")` — image → validated pattern, saved to library. Accepts image_url (HTTPS, SSRF-safe), image_id (WP media attachment), image_base64 (inline ≤5MB), and optional reference_json (Bricksies calibration). dry_run flag returns preview without saving.
* Added: `propose_design` now accepts image_url / image_id / image_base64 / reference_json. Server-side vision produces the design_plan — one call bypasses the 2-phase discovery dance.
* Added: Admin setting "Anthropic API key (vision features)" under Settings → Bricks MCP. Format sk-ant-... validated on save; stored in existing bricks_mcp_settings option.
* Added: New vision service layer — VisionProvider interface + ClaudeVisionProvider (wp_remote_post, retry-on-429/5xx, no SDK) + VisionPromptBuilder + VisionResponseMapper + VisionPatternGenerator orchestrator + ImageInputResolver. Driver pattern ready for future OpenAI / Gemini providers.
* Added: Class-reuse pipeline for vision output — site-context injection into prompt + ClassDedupEngine signature-match + BEMClassNormalizer on new names. Prevents proliferation across repeated vision runs.
* Removed (breaking): public `build_from_schema` MCP tool. Callers must use `build_structure` + `populate_content` (shipped v3.28.0, stabilized v3.29.0). Internal `BuildHandler` class retained as the pipeline implementation invoked by `BuildStructureHandler`. Error messages + onboarding guide + tool descriptions updated to reference the two-tier flow.
* Changed: PrerequisiteGateService `'design'` tier removed (no public callers post-M3). FLAG_DESIGN_READY deprecated.

Vision features require an Anthropic API key. Set it under Settings → Bricks MCP before calling from_image or propose_design with image input.

= 3.29.0 =
**Pattern Usability (M1)**

use_pattern two-tier build now works end-to-end. Pattern captured on site can be
used via propose_design(use_pattern) → build_structure (auto-provisions missing
classes + variables from pattern's embedded payload) → populate_content.

* Added: PatternToSchemaBridge service — converts adapted pattern tree to schema shape
* Added: PatternDriftDetector service — deep-diff between pattern's embedded class payload and live site class
* Added: design_pattern(action: mark_required) — mark roles required at per-node level
* Added: design_pattern(action: get, include_drift: true) — returns drift_report
* Added: PatternsAdmin detail panel gets Class Drift tile + 60s transient cache
* Added: Auto-provisioning — missing classes + variables created from pattern's embedded payload during build_structure
* Changed: Required-role enforcement deferred to populate_content phase (content-supply time) — adapter preserves structure at propose_design
* Fixed: GlobalClassService::create_from_payload styles now actually persist (was passing 'settings' but create_global_class reads 'styles')
* Fixed: generate_from_pattern returns full schema shape instead of broken {pattern_id, structure, adaptation_log}

No migration needed; backward compatible with v3.28 patterns.

= 3.28.7 =
**Hotfix — label applied to all element types + role identifiers preserved verbatim**
* Fix: ElementSettingsGenerator now writes `label` setting on ALL element types (not just structural wrappers). Required for populate_content role-keyed injection to resolve leaf elements (heading, button, text-basic, etc.).
* Fix: role-identifier labels (lowercase underscore_case) kept verbatim instead of prettified — content_map keys match element labels directly.

= 3.28.6 =
**Hotfix — role_map + section_id in build_structure response**
* Fix: build_structure now returns section_id (new section's Bricks element ID) so populate_content doesn't need a separate page:get query.
* Fix: build_structure tags each element with label = role before delegation. populate_content's role-keyed content_map now works end-to-end.
* Fix: role_map in build_structure response is now populated with real element IDs (was null placeholders keyed by class_intent).
* SchemaSkeletonGenerator now emits `role` field on schema nodes. DesignSchemaValidator whitelist updated.

= 3.28.5 =
**Hotfix — class_intent normalization before delegation**
* Fix: build_structure now normalizes structured class_intent objects ({block, modifier?, element?}) and loose strings into BEM class names before passing to downstream validator. Previously threw "Illegal offset type" because DesignSchemaValidator expected scalar strings.

= 3.28.4 =
**Hotfix — build_structure scanner false positive on routing fields**
* Fix: removed `target` from forbidden list (link target attr is structural, not content).
* Fix: scanner now skips `target`, `design_context`, `intent` at top level of schema (schema-routing metadata, not element content).

= 3.28.3 =
**Hotfix — build_structure validator**
* Fix: removed label, title, description from forbidden content fields. These are Bricks structural metadata (element organization labels, tooltip attrs), not user-facing content. Kept as forbidden: content, content_example, text, link, href, target, icon, image, src, url, placeholder.

= 3.28.2 =
**Hotfix — design_plan validator**
* Fix: content_hint is now optional at element level (was still rejecting Phase 2 calls without it). Hints still extracted into content_plan map when supplied.
* Fix: next_step text in Phase 2 response pointed to deprecated build_from_schema with [PLACEHOLDER] guidance. Now points to build_structure + populate_content with role-keyed content_map.
* design_plan_format descriptor documents content_hint as optional + content_plan as the v3.28.0 replacement map.

= 3.28.1 =
**Critical hotfix for v3.28.0**
* Fix: fatal error on plugin load caused by PHP array literal evaluation — `$this->handlers['build']` was null when referenced during array construction. Now captured via local variable and passed to BuildStructureHandler explicitly.

= 3.28.0 =
**Pattern + Class System v3 — BEM + two-tier build**

Unifies pattern and class pipelines. Every styled element gets a BEM class (auto-normalized from AI input). Build split into two steps: build_structure (classes + layout, no content) then populate_content (content injected by role).

**Breaking:**
* build_from_schema tool deprecated — returns redirect error pointing to build_structure + populate_content.
* suggested_schema from propose_design is now content-free. No [PLACEHOLDER] text.
* design_plan element-level content_hint field stripped; content hints echoed back as content_plan map.

**Added:**
* BEMClassNormalizer — parses structured + loose class_intent into BEM form.
* ClassDedupEngine — style-signature dedup across section + pattern pool (BEM-only).
* build_structure tool — returns section_id + role_map + classes_created/reused.
* populate_content tool — role-keyed injection, #id-prefix direct targeting, Unsplash sideload.
* PatternValidator BEM awareness — bem_purity + non_bem_classes + bem_migration_hints on every pattern.
* Admin pattern detail panel shows BEM compliance tile.
* variant field on design_plan becomes default class_intent modifier.

**Preserved (G1):**
* Existing non-BEM classes on deployed sites remain untouched.
* New classes always BEM.
* Legacy classes reusable by explicit class_intent but never auto-deduped.

= 3.27.2 =
**Bug fixes for pattern catalog**
* Fix: discovery catalog now shows populated structural_summary + class_refs_preview (was empty because catalog used pattern summaries instead of full structures).
* Fix: admin Patterns table Structure column now renders the actual tree.
* Fix: capture action now accepts and infers layout + background fields from structure.
* Added: DesignPatternService::get_all_full() public API for consumers that need full pattern trees.

= 3.27.1 =
**Bug fixes for v3.27.0**
* Fix: legacy patterns now wipe on zip-upload upgrade (moved from activation hook to version-bump migration).
* Fix: added "Delete Selected" bulk delete button to Patterns admin.
* Fix: pattern detail view now renders as proper modal overlay (was rendering inline at page bottom).

= 3.27.0 =
**Pattern System v2 — Complete Redesign**

Patterns are now site-specific design memory, not a shipped template gallery.
The plugin ships with ZERO patterns; you capture them from real built sections
on your site via MCP. Each pattern is stripped of content, tokenized to your
site's design system (colors, spacing, radii, fonts), and fully self-contained
(carries its own class + variable payloads).

**Breaking Changes**
* Legacy `bricks_mcp_custom_patterns` option is wiped on upgrade. One-time admin notice appears.
* Admin "Add Pattern" modal removed. Pattern creation is MCP-only via `design_pattern` tool.
* `reference_patterns` field replaced with `pattern_catalog` in propose_design Phase 1 response.
* `find_matching_pattern` in SchemaSkeletonGenerator removed. Use `use_pattern` in design_plan instead.

**Added**
* `design_pattern(action: 'capture')` — captures pattern from an existing built section.
* `design_pattern(action: 'import')` — auto-creates missing classes + variables on target site.
* `use_pattern` in design_plan + `content_map` for pattern-based builds.
* Adaptive build: patterns adapt to content (repeat cloning, role insertion, shape-mismatch rejection).
* `adaptation_log` in build responses shows every structural change made.
* Admin UI: read-only structural_summary column + detail panel (view classes, variables, full JSON).

**Under the hood**
* New services: PatternValidator, PatternCapture, PatternCatalog, PatternAdapter.
* 32 new unit tests covering validation, token snapping (CIELAB ΔE for colors, pixel delta for spacing/radius/fonts), repeat expansion, role insertion, required-role checks.

**Removed**
* `data/design-patterns.json` seed file (21 generic patterns). No more shipped patterns.
* Admin reseed button + AJAX endpoint.
* DesignPatternService::migrate_plugin_patterns / reseed_plugin_patterns / load_and_merge_seed.
* SchemaSkeletonGenerator::find_matching_pattern / PLACEHOLDER_ICONS / get_placeholder_icon.

= 3.26.1 =
* 54 additional commits from Phase 13 parallel agent sweep (Core+Admin, Handlers+Services). Continuation of v3.26.0.
* Core+Admin: 10 Admin Check classes get correct `run()` return-shape docblocks; `DesignPipelineCheck` adds full interface-method docblocks; `BricksCore` adds `ADMIN_NONCE_ACTION`, `NOTES_NONCE_ACTION`, `SETTING_ENABLED`, `SETTING_REQUIRE_AUTH` constants consumed by Settings/Server/Activator/PatternsAdmin/DiagnosticsAdmin; `DesignSystemAdmin` extracts `PANEL_METHODS` + `NONCE_ACTION` constants; `Settings` extracts `CONNECTION_STATUS_TRANSIENT`, `REACHABLE_HTTP_CODES`, `BRIEFS_NONCE_ACTION/FIELD`; `Activator::ACTIVATION_CHECK_TRANSIENT` promoted public; `uninstall.php` gets `flush_rewrite_rules()` rationale + sync-comments on OPTION_* literals; `UpdateChecker` annotates unused `$upgrader` param; `Settings::ajax_generate_app_password` guards against `WP_User(0)`.
* Handlers+Services: `WordPressHandler::MAX_POSTS_PER_PAGE`, `OnboardingService::BRIEF_SUMMARY_WORD_LIMIT`, `ComponentHandler::ID_COLLISION_MAX_RETRIES`, `PageSeoSubHandler::SEO_FIELD_NAMES` (12-entry deduplication), `BuildHandler::KNOWLEDGE_NUDGE_MAP` + `EXTRACTABLE_STYLE_KEYS`, `WooCommerceHandler::WC_TEMPLATE_TYPES` + `WC_INTEGRATION_SETTING_KEYS`, `SchemaSkeletonGenerator::PLACEHOLDER_ICONS`. `BuildHandler` guards `count($existing)` against WP_Error in replace-confirm path; defensively coalesces `$group['refs'] ?? []`.
* i18n: translator comments + numbered placeholders across MenuHandler, ComponentHandler (4 sprintf sites), DesignSystemHandler (6 confirm-delete sprintfs), TemplateHandler (tag/bundle delete), WooCommerceHandler, DesignSystemAdmin color-family sites.
* Style: `BricksCore` CSS sanitizer collapse to two-phase array-mode sanitation with docstring; `ElementHandler::STYLE_KEYS` moved to top of class; `BuildHandler` drops redundant `\BricksMCP\MCP\Handlers\BricksToolHandler` FQN.

= 3.26.0 =
* 57 commits from parallel MEDIUM/LOW/NIT sweep across 4 layers (Core, Admin, Handlers, Services). Consolidated release.
* Defensive: is_array/is_iterable guards added across MetaBoxHandler registry iteration, ComponentHandler list paths, VerifyHandler sections filter, PageReadSubHandler BFS, TemplateHandler settings meta, Router, StreamableHttpHandler, Server, ProposalService, DesignPatternHandler, Update checker.
* Constants extracted: ProposalService::TRANSIENT_PREFIX, Plugin::LEGACY_SETTING_KEYS, ElementSettingsGenerator::NON_STRUCTURAL_ELEMENT_TYPES + CSS_WARN_EXCERPT_LEN, SchemaGenerator::MAX_CONTROLS_PER_ELEMENT + SIMILAR_NAME_THRESHOLD, WordPressHandler::GENERATED_PASSWORD_LENGTH, Activator activation-check TTL, PageOperationsService::MAX_TREE_DEPTH + snapshot constants, GlobalClassService::BP_DIFF_TOLERANCE, TemplateService::MAX_IMPORT_BODY_BYTES, MediaService UNSPLASH_META_KEY + MAX_DOWNLOAD_BYTES, RateLimiter::SETTING_KEY_RPM, VerifyHandler content-sample limits, DesignSchemaValidator::GRID_RESPONSIVE_COL_THRESHOLD, DesignSystemAdmin DEFAULT_BLACK/WHITE/PX_FALLBACK + GAP_PREVIEW_MIN/MAX, Settings RATE_LIMIT_RPM + CONNECTION_PROBE_TIMEOUT, Admin Checks HTTP_TIMEOUT_SECONDS.
* i18n: translator comments + numbered placeholders added across ElementHandler, TemplateHandler, BuildHandler, MediaHandler, PageCrudSubHandler, PageContentSubHandler, PageReadSubHandler, BricksToolHandler, DesignSystemHandler, GlobalClassHandler, DesignPipelineCheck. DiagnosticRunner summary strings now translatable.
* Correctness: uninstall.php uses `$wpdb->usermeta` for multisite; deterministic border-style collapse in normalize_bricks_styles; Plugin + Activator throw on clone, explicit autoload=true on OPTION_SETTINGS; idempotent Server::init; StreamableHttpHandler body-size + keepalive bounds; Response::add_server_header DRY; ElementIdGenerator::id_regex centralizer; SchemaGenerator log context; ConditionSchemaCatalog sprintf for backslash-escaped quotes; Reference catalogs FILTER_HOOK constants.
* Safety: DiagnosticsAdmin + 5 admin AJAX handlers add missing `return` after `wp_send_json_error`; DiagnosticRunner wraps check->run() in try/catch\Throwable; DesignSystemHandler sanitizes style_id at boundary; GlobalClassHandler esc_html on user input in error messages; DesignPatternHandler array_filter lambdas guard is_array; ProposalService hardens brief reads + preg_split fallback; TemplateHandler guards template settings meta; PageReadSubHandler guards children in BFS; MediaHandler dedupes wp_get_attachment_url; BricksToolHandler translator comment on knowledge_not_found; Settings note ID entropy bumped to 16 hex chars (was 8).
* Docs: OnboardingHandler return types corrected; BuildHandler orphan docblock removed + @var added; NotesService uses BricksCore::OPTION_NOTES; WooCommerceHandler whitespace normalized; SchemaSkeletonGenerator dead legacy comment block removed.

= 3.25.8 =
* Fix: App password generator client-name now filterable via `bricks_mcp_app_password_client_name`. Default remains "Bricks MCP - Claude Code" but sites using multiple MCP clients (ChatGPT, Cursor, Aider) can override per-context to distinguish WP admin sessions.
* Fix: `ajax_generate_app_password` adds `return` after `wp_send_json_error` (defense against filtered wp_die).

= 3.25.7 =
* Fix: `SeoService` title/description length thresholds extracted into class constants (`TITLE_MIN_CHARS=30`, `TITLE_MAX_CHARS=60`, `DESCRIPTION_MIN_CHARS=120`, `DESCRIPTION_MAX_CHARS=160`). New `bricks_mcp_seo_length_bounds` filter allows non-English sites to override — Romanian/CJK/Cyrillic content with different character-to-pixel-width ratios no longer false-flags.
* Fix: `SchemaSkeletonGenerator` pricing featured-card selection. Previously `floor(pat_repeat / 2)` produced nonsensical results for pat_repeat=1 (featured the solo tier) and arbitrary result for pat_repeat=2. Now: repeat=1 → no featured card (sentinel -1), repeat=2 → second card featured, repeat=3+ → middle card featured.

= 3.25.6 =
* Fix: `BricksService::remove_element(cascade: true)` now scrubs stale child-ID references from surviving elements. Previously the cascade removed the target + descendants but left parent.children arrays pointing at gone IDs, which the linkage validator flagged on save. Also adds is_array guards per element.
* Fix: `Plugin::migrate_settings` now checks `update_option` return value — false triggers a RuntimeException so outer try/catch leaves `db_version` un-bumped and retry happens on next load. Previously silent success on DB rejection led to permanent half-migrated state.
* Refactor: `Admin/Settings::RATE_LIMIT_RPM_{MIN,MAX,DEFAULT}` constants replace 3 `max(10, min(1000, ... ?? 120))` magic numbers. `CONNECTION_PROBE_TIMEOUT` constant replaces hardcoded `3`.
* Refactor: `McpEndpointCheck::HTTP_TIMEOUT_SECONDS` + `RestApiReachableCheck::HTTP_TIMEOUT_SECONDS` constants replace hardcoded `5`-second timeouts with documented intent.

= 3.25.5 =
* Fix: `StreamableHttpHandler::handle_get` SSE keepalive loop gains an iteration cap (default 360, filterable via `bricks_mcp_sse_max_iterations`). Previously unbounded — wedged PHP-FPM workers when clients held connections open.
* Fix: `Server::resolve_client_ip()` adds proxy-awareness for rate-limit IP extraction. Consults `X-Forwarded-For`, `CF-Connecting-IP`, `X-Real-IP` ONLY when `bricks_mcp_trust_proxy` filter returns true (opt-in to prevent spoofing). Without this, all traffic behind a reverse proxy/CDN shared one rate-limit bucket.
* Fix: `StreamableHttpHandler::handle_tools_call` type-checks the `name` parameter. Non-string values previously caused `TypeError` crashes in `Router::execute_tool(string $name)`.
* Fix: All 4 Reference catalogs (`ConditionSchemaCatalog`, `FilterSchemaCatalog`, `FormSchemaCatalog`, `InteractionSchemaCatalog`) now validate `apply_filters()` return values. Third-party filters returning non-array would crash downstream callers; now fall back to unfiltered defaults.
* Fix: `InteractionSchemaCatalog::image_gallery_load_more` example was structurally misplaced OUTSIDE the `examples` array at top level of `$data`. Moved inside `examples` where it belongs alongside other examples.
* Breaking change (prerequisites): `BRICKS_MCP_MIN_BRICKS_VERSION` bumped from 1.6 to 1.12. Earlier versions passed the gate but failed at first write — 1.12 is the true minimum.
* Refactor: 10 more option names extracted into `BricksCore::OPTION_*` constants (OPTION_CUSTOM_PATTERNS, OPTION_HIDDEN_PATTERNS, OPTION_PATTERN_CATEGORIES, OPTION_PATTERNS_MIGRATED, OPTION_STRUCTURED_BRIEF, OPTION_DS_LAST_APPLIED, OPTION_DESIGN_SYSTEM_CONFIG, OPTION_TERM_TRASH, OPTION_DB_VERSION, OPTION_VERSION, OPTION_ACTIVATED_AT). Applied across DesignPatternService, BriefResolver, TemplateHandler, Plugin, DesignSystemAdmin, Settings.

= 3.25.4 =
* Fix: `GlobalClassService::create_global_class` — duplicate-name check now case-insensitive (previously "heroButton" + "HeroButton" both succeeded). Unbounded `do-while` collision retry loop capped at 100 attempts with `id_generation_failed` error on exhaustion.
* Fix: `ElementSettingsGenerator::is_dark_color_token()` replaces the overly-broad regex `(?:^|-)(?:ultra-)?dark(?:-|$|\))` that produced false positives on any token with "-dark" anywhere (e.g. `--dark-blue-500`, `--not-so-dark-bg`). New helper matches only exact base/primary/secondary/accent (-ultra)-dark tokens, black keyword, and near-black hex values.
* Fix: `ElementSettingsGenerator::_background.overlay` chain now guards intermediate values — scalar overlay strings (`"black"`) and malformed array shapes no longer silently produce empty gradients.
* Fix: `ElementSettingsGenerator::DEFAULT_GRID_COLUMNS = 3` extracted from inline `?? 3` literal.
* Fix: `ProposalService::create()` now distinguishes trashed pages from missing pages. Separate `page_trashed` error code with "restore it" guidance instead of generic "not found".
* Hardening: `BricksService::compute_diff()` — settings array-helpers now defensively `is_array()`-check `$old_settings`/`$new_settings` before `array_diff_key`/`array_filter`. Non-array settings (from legacy data or upstream non-strict inputs) previously crashed the diff path.

= 3.25.3 =
* Fix: Meta-key resolver bypass in three page sub-handlers. `PageCrudSubHandler::delete`, `PageReadSubHandler::format_post_for_list`, and `PageContentSubHandler::update_content` used the hardcoded page-content meta key directly — for `bricks_template` posts (headers, footers, popups, etc.) this meant element counts read from the wrong key. Now use `BricksService::resolve_elements_meta_key()` (new public passthrough to BricksCore resolver).
* Fix: `PageContentSubHandler` element reduction threshold uses integer math (`new_count * 2 < old_count`) instead of floating-point (`new_count < (int)($old_count * 0.5)`). Previously a 5→2 reduction (60%) bypassed the confirm prompt because `(int)(5 * 0.5) = 2`.
* Refactor: `MetaBoxHandler` adds `DYNAMIC_TAG_PREFIX` constant + `mb_tag()` helper. Replaced 6 `'{mb_' . $fid . '}'` literal concatenations. Also adds `is_array()` guards on `$meta_box->fields` iteration (MetaBox can deliver stdClass in edge cases).
* Refactor: `ElementHandler` extracts 31 condition keys + 10 compare operators into class constants (`KNOWN_CONDITION_KEYS`, `VALID_CONDITION_COMPARE`).
* Refactor: `BricksToolHandler` extracts knowledge-fetch TTL into `KNOWLEDGE_FETCH_TTL` constant.
* Refactor: `SchemaHandler` extracts 20-element batch limit into `MAX_ELEMENT_SCHEMAS_PER_BATCH` constant. Fix: `tool_get_dynamic_tags` uses exact tag-name match for queryEditor/useQueryEditor stripping instead of substring `stripos`, preventing false-positive blocking of third-party tags.
* Refactor: `ComponentHandler` documents the 50-retry ID-collision constant with probability math.
* Refactor: `MediaHandler` extracts smart_search 30/5 and media_library 20 magic numbers into named constants.
* Refactor: `TemplateHandler` replaces `> 50` / `array_slice(-50)` with `BricksCore::BATCH_SIZE`.

= 3.25.2 =
* Refactor: 4 new `BricksCore` constants for Bricks core option/meta keys: `OPTION_COMPONENTS`, `OPTION_GLOBAL_QUERIES`, `META_TEMPLATE_TYPE`, `META_TEMPLATE_SETTINGS`. Replaced ~20 literal usages across `BricksToolHandler` (5×), `ComponentHandler`, `TemplateHandler` (7×), `WooCommerceHandler` (3×), `SettingsService` (6×), `TemplateService` (13×).
* Fix: `BuildHandler` no longer calls `error_log()` on every build. Now gated on `WP_DEBUG`. Previously bloated production error logs.
* Fix: `MediaHandler::tool_get_media_library` accepts integer-strings for `per_page`/`page` (previously `is_int()` strictly rejected `"20"`). HTTP-decoded JSON sometimes lands pagination params as strings.

= 3.25.1 =
* Security: `PatternsAdmin::ajax_create_pattern` now boundary-sanitizes pattern metadata (`id`, `name`, `description`, `category`, `tags`) at ingest. Stored XSS in downstream render paths is now a depth-2 bug, not a depth-1 bug.
* Fix: `DesignSystemAdmin::ajax_render_panel` adds `return` after every `wp_send_json_error`. Previously fell through to a dynamic method call on a null method name when `wp_die` was filtered (test/headless environments).
* Fix: `ProposalService` proposal ID generation switched from `substr(md5(time()+password), 0, 12)` (48 bits) to `wp_generate_uuid4()` (122 bits). Concurrent-microsecond collisions now cryptographically negligible.
* Fix: `SchemaGenerator::get_settings_keys` + `ElementSettingsGenerator::get_element_registry` added integrity checks on data/*.json reads. Missing/unreadable/malformed files no longer produce `undefined index` warnings or silently-empty registries — they log via `error_log` for diagnostics.
* Fix: `PatternsAdmin::ajax_create_pattern` + `::ajax_delete_pattern` add `return` after every `wp_send_json_*` call (same class of bug as DesignSystemAdmin).

= 3.25.0 =
* Refactor: Pure extraction release — no behavior change. Centralizes magic strings and numbers into `BricksCore` constants and helpers.
* New: `BricksCore::BATCH_SIZE = 50` — single source for bulk-operation limits (bulk_update_elements, batch_delete_global_variables).
* New: `BricksCore::data_path($filename)` — replaces `dirname(__DIR__, 3) . '/data/...'` duplicated across SchemaGenerator, ProposalService (×2), DesignPatternService, ElementSettingsGenerator.
* New: `BricksCore::header_meta_key()` / `footer_meta_key()` — replace `defined(BRICKS_DB_PAGE_HEADER) ? : '_bricks_page_header_2'` ternaries in BricksCore + GlobalClassService (×3).
* New: `ElementSettingsGenerator::TEXT_ELEMENT_TYPES` — single source for the 4 element types that carry user-facing text. Deduplicated 2 inline arrays in same file.
* New: `SchemaExpander::MAX_REPEAT` promoted to public so `DesignSchemaValidator` imports it. Previously: private constant + separate literal `> 50` in validator. Same drift class as v3.24.2 data inconsistency.
* Fix: `'manage_options'` literals in Settings.php and Router.php replaced with `BricksCore::REQUIRED_CAPABILITY`. Capability changes now require touching one line, not three.

= 3.24.5 =
* Fix: `ElementNormalizer::parent_refs_to_tree` no longer corrupts trees silently. Previous reference-in-foreach pattern (`foreach ($by_id as &$el) { $tree[] = &$el; }`) aliased every stored entry to the loop variable — every child in the output tree ended up pointing at the last source element. Rewritten with pure value semantics using a recursive builder.
* Fix: `BricksCore::save_elements` removed the delete-then-add fallback on `update_post_meta` failure. Concurrent readers previously saw empty meta during the window. Now returns a `save_elements_failed` error; caller can retry at the next write.
* Fix: `BricksCore::rehook_bricks_meta_filters` now re-adds sanitize_post_meta_* callbacks via `add_filter()` per-callback instead of wholesale-assigning the stashed WP_Hook object. Other plugins that registered callbacks during the unhook window are preserved.
* Fix: `BricksService::duplicate_element` root-level flat insert no longer uses `$position * (count($subtree_ids) + 1)` math that assumed equal subtree sizes. Walks root siblings in flat order instead — correct for varied trees.
* Fix: `BricksService::move_element` root-level insertion now uses `BricksCore::is_root_element()` for parent comparison (consistent with v3.24.3 root check) and guards against non-array rows.
* Fix: `SchemaExpander::expand` now unwraps top-level `_expanded_multi` wrappers. Previously a section whose structure was a ref with repeat > 1 returned a `_expanded_multi` wrapper that nothing unwrapped — downstream saw empty output. Now the section fans out into multiple sections at the same position.
* Fix: `Plugin::maybe_migrate` wraps each migration in try/catch. Previously an uncaught Throwable bumped the db_version anyway, leaving the plugin permanently half-migrated. Version now bumps only when all migrations succeed; failures logged via error_log.

= 3.24.4 =
* Security: `tool_confirm_destructive_action` now re-checks capability on confirm. Previously it bypassed `execute_tool()` entirely, including the capability check. A user demoted between original call and confirm could still execute via a pre-demotion token. Tokens now prove intent, not current authorization.
* Security: `tool_confirm_destructive_action` now wraps handler calls in try/catch. Previously an uncaught `\Throwable` crashed the MCP dispatcher with HTTP 500 instead of a JSON-RPC error envelope.
* Fix: MCP dispatcher now recognizes `isError: true` in tool result data. Previously `Response::tool_error()` set `isError` (MCP tool-result envelope) but the dispatcher only inspected `error` (JSON-RPC envelope) — tool errors silently surfaced to clients as successes with `isError` buried in data. AI clients inspecting the JSON-RPC envelope missed them entirely.
* Fix: RateLimiter now fails open on cache backend outage. `wp_cache_incr()` returns `false` on Redis/Memcached failure; previously this was treated as "rate limit exceeded" so any Redis hiccup would 429 all traffic site-wide. Fail-open policy with error_log warning — rate limiting is a safety measure, not a security boundary.

= 3.24.3 =
* Hardening: stdClass guard sweep across the pipeline. v3.24.1 patched one site; this release sweeps the 21+ remaining hot sites in BuildHandler, VerifyHandler, ComponentHandler, ElementHandler, PageReadSubHandler, BricksToolHandler, Router, StreamableHttpHandler, and ElementSettingsGenerator against the `Cannot use object of type stdClass as array` crash class.
* New: `BricksCore::is_element_array()`, `is_subscriptable()`, and `is_root_element()` type-guard helpers. Centralized single source of truth for element-shape and root-parent checks.
* Fix: `BricksCore::is_root_element()` replaces two contradictory parent-comparison paths (Router string cast vs StreamableHttpHandler integer `!==`). Same data now yields same result in both paths.
* Fix: `ComponentHandler::get_instance_element` had an inverted null check on `check_protected_page` (ran only when no error). Now correctly blocks writes to protected pages.
* Fix: `BuildHandler::extract_shared_styles_to_classes` no longer clobbers the class-creation return value with a prior `clear_cache()` assignment. `class_id` extraction now returns `''` for non-array results instead of trying to subscript.

= 3.24.2 =
* Fix: `div` and `block` registry entries now list `slider-nested` as a valid parent. Previously schema validator rejected slider-nested → div/block slide panes even though slider-nested declares them as typical_children — data inconsistency surfaced on any slider build.
* Fix: `slider-nested` typical_children now includes `block` in addition to `div`.
* Fix: Grid columns-vs-children validation warning no longer fires as a false positive when grid children include a pattern ref. Validator now counts `ref.repeat` toward the effective child count instead of treating the ref as a single child.

= 3.24.1 =
* Fix: `build_from_schema` no longer crashes with `Cannot use object of type stdClass as array` when schema contains elements without explicit settings (most commonly `form` with no `element_settings.fields`). Root cause: ElementSettingsGenerator converted empty settings to `stdClass` for JSON `{}` serialization, but downstream subscript access (`$el['settings']['_pipeline_warnings']`) errored on object. Settings now stay as arrays throughout the pipeline; PHP `serialize()` at DB write handles empty arrays correctly.
* Hardening: Added defensive `is_array()` guard in `BuildHandler::collect_and_strip_warnings()` against future stdClass leaks.

= 3.24.0 =
* New: AI notes included in discovery responses — persistent memory across sessions.
* New: Build + verify responses include hints prompting AI to save design learnings.
* New: Server instructions document the notes workflow with concrete examples.
* Safety: Pattern repeat capped at 50 (validator rejects, expander hard-caps).
* Fix: Design gate simplified — section presence only, removed arbitrary 8-element count threshold.
* Data: 76/76 elements now have purpose annotations — 100% AI discovery coverage (was 26/76).
* New: Batch element schema fetch — `elements` parameter for `get_element_schemas` (comma-separated, max 20).
* New: `template-elements` knowledge file (231 lines) — post-* elements, archive patterns, dynamic data tags.

= 3.23.0 =
* Data: 76 element working examples extracted from PHP to `data/elements.json` — updatable without code release.
* Data: 32 new element entries added to registry (post-*, WordPress widgets, maps, misc).
* Refactor: `SchemaGenerator` reads working examples from registry; 562 lines of hardcoded PHP removed.
* No behavior change — all MCP tool consumers receive identical data.

= 3.21.2 =
* Fix: `verify_build` root section detection broken — parent compared to string '0' but Bricks stores integer 0.
* Fix: WooCommerce scaffold hints referenced dead `get_builder_guide()` — updated to `bricks:get_knowledge('woocommerce')`.
* Fix: `uninstall.php` missing `bricks_mcp_db_version` option cleanup.
* Fix: `DesignPipelineCheck` missing from WP Site Health screen.
* Fix: `BricksCore` undefined variable risk, `ComponentHandler` empty array guard, `DesignPipelineCheck` fallback path.
* Cleanup: hardcoded meta key, orphaned docblock, indentation consistency.
* Dead code: unused `GITHUB_API_URL` constant removed.
* 88-file audit: zero stale references to deleted data layer files.

= 3.21.1 =
* New: building rules extracted to `data/building-rules.json` — single source of truth.
* New: discovery response includes `recommended_knowledge` array — suggests relevant knowledge domains per description.
* New: 10 schema responses include `knowledge_hint` pointing to matching knowledge domain.
* Fix: form pipeline warning cross-links to `bricks:get_knowledge('forms')`.
* Fix: breakpoints example `_padding:mobile` → `_padding:mobile_portrait`.

= 3.21.0 =
* Architecture: unified element registry (`data/elements.json`) — merged 4 sources into one 44-element file. Single source of truth for hierarchy, content keys, flex behavior, defaults, purpose/capabilities/rules.
* Architecture: unified settings key registry (`data/settings-keys.json`) — 66 Bricks settings keys including Bricks 2.3 parallax + 3D transforms. Deleted redundant PHP constant.
* New: design pattern seed file (`data/design-patterns.json`) — 21 patterns auto-seed on first activation. Fixes fresh-install empty pattern library. Admin "Reset to plugin defaults" button.
* New: 3 knowledge files — `query-loops` (5 query types, pagination, global queries), `templates` (condition scoring, precedence, import/export), `global-classes` (IDs vs names, 16 actions, style shape).
* New: knowledge auto-discovery — drop a `.md` file in `data/knowledge/` and it's instantly available. No code changes needed.
* New: server instructions + onboarding now mention knowledge library with domain list.
* Rewrite: all 8 existing knowledge files verified against live MCP schemas. Major bug fixes: popup action names, WC tag prefix, component connection format, form options format, breakpoint names.
* Removed: `StarterClassesService` (245 lines), `class-context-rules.json` + auto-suggest code (~260 lines), hardcoded Romanian form templates.
* Documentation: readme tool count 23→27, design system description updated, changelog backfilled.

= 3.20.0 =
* Removed: dark mode removed from plugin admin. `@media (prefers-color-scheme: dark)` blocks deleted from `admin-settings.css` and `admin-design-system.css`. Admin renders in light scheme regardless of OS/browser preference.

= 3.19.3 =
* Polish + accessibility: shadow inputs get full-width row layout (long values no longer truncate), heading preview caps at 42px, pill radius preview is now a 96×32 rectangle (vs. 48×48 square), Reset is a semantic `<button>`, Typography HTML font-size uses `<fieldset>` + `<legend>`, status messages announced via `aria-live="polite"`, inactive panels get `aria-hidden="true"`, focus ring on stepper buttons, `aria-label` on every step input + color picker + hex field.

= 3.19.2 =
* Fix: dark mode text readable — `--bwm-gray-900` was not being remapped in the `prefers-color-scheme: dark` block.
* Fix: shadow preview boxes keep light background in dark mode so shadow is visible.
* Fix: live preview auto-contrasts text colors via WCAG luminance check — hero title / body / button text pick white or dark automatically, pastel backgrounds no longer produce invisible labels.
* (Dark mode was later removed entirely in 3.20.0.)

= 3.19.1 =
* Live preview now showcases the 3.19 token categories: Shadows row (5 cards with `--shadow-xs` through `--shadow-xl`), Border Widths row, Aspect Ratios row, and a hover-me Transitions demo button wired with `--duration-base` + `--ease-out`. Existing feature cards and sidebar card apply `--shadow-m` / `--shadow-l` + `--border-thin`.

= 3.19.0 =
* New: 5 new design token categories (27 new variables): Shadows, Transitions, Z-Index, Border Widths, Aspect Ratios.
* New: "Effects" stepper panel in the Design System admin (Shadows, Transitions, Z-Index subsections).
* New: Border Widths subsection added to the Radius panel.
* New: Aspect Ratios subsection added to the Sizes panel.
* New: Shadow inputs show a live preview box so you can see the shadow applied.
* Existing configs auto-migrated on read — new defaults populate automatically, no user action needed.

= 3.18.7 – 3.18.10 =
* Iteration fixes so Spacing / Texts / Headings appear as native scales in the Bricks Style Manager: scale metadata now writes the complete `scaleScope` / `scaleType` / `scaleNames` / `baseline` / `manualValues` / `isManual` / `minFontSize` / `maxFontSize` / `minScaleRatio` / `maxScaleRatio` shape matching Fancy Framework's import format. Scale prefix format corrected (`--space-`, `--text-`, `h`). Bricks style manager CSS regenerated after Apply so changes appear immediately.
* Palette renamed from `BricksCore` to `Bricks-WP-MCP`. Apply now replaces palettes named either, so old palettes on upgrade get replaced rather than duplicated.

= 3.18.5 =
* Internal diagnostic: lifecycle logging to investigate a silent auto-deactivation report (removed in 3.18.6).

= 3.18.6 =
* Fix: stepper button hover no longer makes active tab unreadable.
* Fix: Design Pipeline Health diagnostic now verifies database-backed patterns (was checking a filesystem directory that no longer exists).
* Typography and Spacing step rows now group the Mobile + Desktop inputs together and the previews/swatches together (readable grid instead of interleaved).
* Removed the temporary lifecycle debug logging introduced in 3.18.5.

= 3.18.4 =
* Critical: release ZIP now includes the top-level `bricks-mcp/` wrapper directory. Without it, WordPress could install under a directory named after the zip filename, silently deactivating the plugin on upgrade.
* Gap visual indicators now resolve `var(--space-X)` references to actual pixel values from your spacing scale (instead of falling back to a static 16px).
* `ajax_save_config` and `ajax_apply` now normalize config through `ConfigMigrator::migrate()` before storing (defensive).
* Removed dead `render_panel_text_styles()` method.

= 3.18.3 =
* Critical: Typography panel was missing its `</section>` close tag. Every panel after Typography (Colors, Gaps/Padding, Radius, Sizes) was nested inside it and inherited `display: none`, never appearing when their stepper button was clicked.

= 3.18.2 =
* Text typography step rows now show live "Body text" preview at both mobile and desktop sizes.
* Text Styles merged into Typography panel (removed standalone step). Border colors stay in Radius step.
* Gaps / Padding panel visual indicators — two boxes showing the gap value as spacing between them.
* Radius panel visual indicators — colored square with the actual border-radius value applied.
* Live preview restored to full mockup (hero with gradient + buttons, feature stats cards, sidebar card, footer, token labels).

= 3.18.1 =
* Fix: `BRICKS_MCP_VERSION` constant bumped to match docblock (was stale at 3.17.0, causing wrong version in admin header and spurious "update available" notice).
* Heading previews grow live when step values change.
* Spacing swatches grow live with the desktop value.
* Structural toggles (Enable / Expand Color Palette / Transparencies) now re-render the Colors panel so new shade inputs and transparency strips appear immediately. New AJAX endpoint `bricks_mcp_ds_render_panel` returns server-rendered panel HTML.

= 3.18.0 =
* Design System v2: admin Design System tab rewritten as left-rail stepper with 7 panels (Spacing, Typography, Colors, Gaps/Padding, Radius, Sizes, Text Styles). Every generated value is individually editable.
* Two new color families: **tertiary**, **neutral** (both disabled by default).
* **Expand Color Palette** toggle per family — switches from 5 shades to 8 (adds semi-dark, medium, semi-light).
* **Transparencies** toggle per family — generates 9 transparency variants (90% → 10%).
* **Hover variants** per family (auto-derived `darken(base, 10%)`, editable).
* **White** and **Black** families with independent transparency toggles.
* **HTML font-size** toggle (62.5% / 100%) — emits `html { font-size: 100%; }` when 100% is selected.
* Editable **Text Styles** (`--text-color`, `--heading-color`, font weights, line heights).
* Editable **Gaps/Padding** refs (accept `var()` values).
* **Radius** individual variant overrides (all 10 derived values editable) + border colors.
* **Sizes** extra fields (max-width, max-width-m, max-width-s, min-height, min-height-section, logo-width mobile/desktop).
* Refactor: extract `ScaleComputer`, `ColorComputer`, `ConfigMigrator` helpers from `DesignSystemGenerator`.
* Existing configs are auto-migrated on read — no user action required.
* BricksCore palette now includes hover variants (flat entries per enabled family). Expanded shades and transparencies live as CSS variables only (keeping the Bricks color picker usable).

= 3.17.0 =
* Internal improvements and stability fixes.

= 3.16.0 =
* Fresh-site portability: starter classes use CSS `var()` fallbacks, `SiteVariableResolver` returns hex on empty sites, `BUILDING_RULES` adapt to site state, WooCommerce scaffolds portable.
* Infrastructure: centralized `BRICKS_MCP_GITHUB_REPO` constant, `BricksCore::META_KEY` replaces 12 hardcoded strings, filterable SSE keepalive interval, prerequisite TTL extended to 2 hours, optional `BRICKS_MCP_GITHUB_TOKEN` for authenticated update checks.
* Code quality: dead code removed, `confirm` parameter removed from 10 schemas, migrations gated on version change, stronger randomness (`random_int` / `random_bytes`), unbounded queries fixed.
* Extensibility: 7 new `apply_filters()` hooks for hosting providers, security plugins, schemas, and intent map.

= 3.15.0 =
* Internal improvements.

= 3.14.1 =
* Bug fixes.

= 3.14.0 =
* Feature updates.

= 3.13.0 =
* Feature updates.

= 3.12.x =
* Various improvements and bug fixes (3.12.0 through 3.12.3).

= 3.11.x =
* Various improvements and bug fixes (3.11.0 through 3.11.3).

= 3.10.1 =
* Pattern import normalization: imported patterns auto-mapped to site's classes and variables. Class references matched via semantic search; unmatched references flagged with warnings. Both admin import and MCP import_normalized run through normalization.
* AI prompt generation: "Generate AI Prompt" button in pattern creator copies a structured prompt (with site context, element capabilities, layouts, classes) to clipboard. Paste into Claude Code or any AI, get composition JSON back.
* 2 new MCP actions on design_pattern: normalize (map external pattern to site), generate_prompt (get AI prompt for pattern creation from description).
* Admin import now normalizes patterns before saving — classes auto-matched, variables checked, warnings shown.

= 3.10.0 =
* Database-first pattern library: all 21 plugin-shipped patterns auto-migrate to the database on first update. No more hardcoded patterns.
* Category registry: categories stored as standalone wp_option. Create, rename, delete categories independently of patterns.
* Full admin UI rewrite for Patterns tab: categories section with inline edit/delete, pattern creator form with structured fields (name, ID, category, tags, layout, background, AI description, composition JSON, reference image via WP Media Library), edit mode for existing DB patterns.
* 4 new MCP actions: list_categories, create_category, update_category, delete_category.
* Pattern create/update validates category against registry.
* Plugin-file tier skipped after migration. User-file tier still active.
* Migration idempotent (version-flagged, skips already-imported IDs).
* uninstall.php cleanup expanded with all new option keys.

= 3.9.1 =
* Admin UI for Design Pattern Library: new "Patterns" tab in Settings > Bricks MCP.
* Pattern table with source badges (Plugin/User File/Database), category, layout, tags, AI description per row.
* Category and source dropdown filters for quick navigation.
* View JSON modal (read-only for plugin/user patterns, editable for database patterns).
* Create pattern via JSON paste in modal.
* Delete database patterns / hide plugin patterns from admin.
* Export selected or all DB patterns as downloadable JSON file.
* Import patterns from JSON file upload with auto-suffix on ID conflicts.
* Select-all checkbox for bulk export.

= 3.9.0 =
* New tool: design_pattern with 8 actions (list, get, semantic_search, create, update, delete, export, import) for managing a 3-tier design pattern library.
* 3-tier pattern loading: plugin-shipped (data/design-patterns/, read-only) + user files (wp-content/uploads/bricks-mcp/design-patterns/, survives updates) + database (wp_options, full CRUD via MCP). Override priority: database > user files > plugin.
* Semantic search for patterns: natural language queries scored by name match (10pts), ID stems (5pts), ai_description (6pts), tags (4pts), category (3pts), layout/background (2pts).
* AI metadata backfilled on all 21 plugin-shipped patterns: ai_description (1-2 sentence visual description) + ai_usage_hints (2-3 actionable tips per pattern).
* Pattern export/import for cross-site sharing: export selected or all DB patterns as JSON, import with auto-suffix on ID conflicts (-v2, -v3).
* Hidden patterns: plugin/user-file patterns can be soft-deleted (hidden from all lists) without modifying source files.
* propose_design Phase 1 discovery now automatically surfaces patterns from all 3 tiers with source labels.
* Design Pattern Library section added to docs/knowledge/building.md.

= 3.8.0 =
* New tool: propose_page_layout(intent, page_id, tone) — maps page intent (landing, services, about, product, contact) to a sequenced list of section types with recommended pattern IDs and ready-to-use design_plan skeletons. AI loops through sections instead of thinking section-by-section.
* New action page:describe_section — rich human-readable styling description of a section. Returns rendered_description prose + element_breakdown structured data with key style values per element. AI can self-verify what was actually built without screenshots.
* New action global_class:semantic_search — natural language class search (e.g. "card with white bg and shadow"). Heuristic scoring by name match, settings match, word stems. Returns ranked matches with score + match_reasons + settings_summary.
* New action global_class:render_sample — generates structured description, equivalent CSS rules, and sample HTML snippet for any global class. AI can verify a class produces what it expects before applying.
* New action element:copy_styling — copy style settings between elements. Modes: classes_only, inline_only, both. Filters to 27 style-relevant keys; preserves target content/label/tag.
* New action media:smart_search — Unsplash search enriched with business_brief context. Extracts top context terms (proper nouns, services, location) and appends to query. Returns enrichment_applied so AI sees what was added.
* Slim dry-run mode for build_from_schema: pass dry_run: "summary" (string, not boolean) to get intended_element_count + tree_summary + class_intents_resolved without the full element tree. Backward compatible — dry_run: true still returns full preview.
* docs/knowledge/building.md updated with new "Element-Specific Settings via element_settings" section documenting the v3.7.0 escape hatch (whitelisted keys per element type, examples, defaults, auto-fixes), plus a "Color Object Format" section documenting that color values must be {raw} or {hex} objects (auto-fixed in v3.7.0 but preferred from start).

= 3.7.0 =
* StyleShapeValidator service: 7 auto-fix rules for Bricks settings shape mismatches that previously caused silent failures or "Array to string conversion" frontend errors. Wraps color strings in {raw|hex} objects (typography.color, background.color, _color, border.color), collapses per-side _border.style to string, expands flat _border.width to per-side, joins _cssCustom arrays. All fixes return warnings — never hard-error.
* element_settings escape hatch: design schema now accepts an element_settings key on 8 element types (pie-chart, counter, video, slider-nested, form, progress-bar, rating, animated-typing) with type-specific whitelisted keys. Pie-chart can finally specify percent, counter can specify countTo, video can specify URL/autoplay/etc.
* Element coverage: ELEMENT_CAPABILITIES now describes 6 additional elements with purpose + capabilities + rules: progress-bar, pie-chart, alert, animated-typing, rating, social-icons. AI clients can now intentionally use these instead of working around them.
* Sane defaults for element_settings: when an element of these types is created without element_settings, defaults are applied (counter countTo=100, pie-chart percent=75, progress-bar with sample bar, rating=5, animated-typing strings).
* Class context guards: tagline rule now requires content under 60 chars and uppercase/short check; tag-grid rule now requires children to have tag-pill class. Stops the over-application of these classes to neutral text and CSS grids.
* Class attribution warnings: build_from_schema response now includes warnings when classes were auto-attached by context rules without being requested in the design plan, so AI sees "tagline auto-attached to 3 subtitles".
* Element count reconciliation: pipeline warns when fewer elements were generated than the schema intended (catches silent drops). Always logs intended/actual counts via error_log for debugging.
* Element-level _cssCustom quarantine: when _cssCustom appears on text-basic, heading, button, icon, text, or text-link elements (which crash the frontend with array-to-string), the CSS is stripped and a warning recommends moving it to a class_intent.
* Track C auto-fixes for element_settings values: counter countTo "500+" → integer 500 with prefix/suffix extraction, pie-chart percent string → integer clamped 0-100, video YouTube/Vimeo URL → ytId/vimeoId with videoType, rating value clamped to 0-maxRating range.
* GlobalClassService now wires StyleShapeValidator after normalize_bricks_styles in both create and update paths. ElementSettingsGenerator runs StyleShapeValidator at the end of build_settings.

= 3.6.5 =
* Fix: global_class:update crashed with TypeError when styles were updated — store_normalization_warnings() was called with null instead of the warnings array from normalize_bricks_styles(). The update flow is now correctly wired.
* Fix: create_global_class now runs normalize_bricks_styles() on new classes. Previously only global_class:update normalized; auto-created classes from ClassIntentResolver (the design pipeline) could slip per-side _border.style objects through, causing "Array to string conversion" PHP errors on frontend render. Affected any pipeline build that specified dashed/dotted borders via style_overrides.
* Auto-created classes now have the same correctness guarantees as manually-updated classes.

= 3.6.4 =
* Unsplash image resolution no longer fails silently. ElementSettingsGenerator now attaches _pipeline_warnings to element settings when Unsplash search, sideload, or API key lookup fails. BuildHandler collects warnings and returns them as "warnings" array in the build response so the AI knows image slots were left empty.
* Warnings include the specific failure reason (missing API key, network error, zero results) so users know whether to configure the Unsplash API key in settings or upload manually.

= 3.6.3 =
* Phase 2 gate fix: propose_design Phase 2 now sets BOTH design_discovery + design_plan flags. This unblocks the documented "skip Phase 1 for subsequent sections" flow when session flags reset mid-session (e.g. plugin reload).
* Background vocabulary expanded: design_plan.background now accepts tinted-neutral, tinted-accent, tinted-warning, tinted-danger in addition to dark/light. Tinted values resolve to *-ultra-light CSS variables for the alternating-section pattern.
* Pricing sections auto-feature the middle tier: SchemaSkeletonGenerator emits a second "{pattern}-featured" variant with yellow border + larger padding for the middle card when section_type=pricing and repeat>=3. Matches industry convention.
* verify_build section_id validation: now rejects non-string, empty, or malformed section IDs with a clear error instead of silently returning an empty sections array.
* Class suggestions are section-type-aware: ProposalService adds conventional class-name patterns per section type (e.g. pricing → btn-primary, btn-outline, pricing-*, card). Previously only classes whose names matched words in the description were suggested.

= 3.6.0 =
* PageHandler split: 1074 lines of handler monolith broken into 6 focused sub-handlers under includes/MCP/Handlers/Page/ (PageReadSubHandler, PageSnapshotSubHandler, PageSettingsSubHandler, PageSeoSubHandler, PageCrudSubHandler, PageContentSubHandler). PageHandler is now a 342-line dispatcher + tool schema.
* No external behavior changes. The page tool schema and all 17 action names and argument shapes are byte-for-byte identical. Destructive confirm flow, auto-snapshot, content wipe protection, and design gate interactions all preserved.
* Each sub-handler is now independently testable. Added PageHandlerDispatchTest covering dispatcher behavior, action completeness, and destructive confirm preservation.

= 3.5.0 =
* Refactor pass: Router collapsed 18 typed handler properties into a single $handlers array (1099 → 866 lines). SchemaHandler extracted 4 large reference arrays into dedicated catalog classes under includes/MCP/Reference/ (1615 → 787 lines).
* Tool registration: wordpress and metabox handlers now self-register via register(ToolRegistry) methods, matching the existing VerifyHandler/OnboardingHandler pattern.
* Cross-request discovery cache: ProposalService now persists the site_context hash in a user-scoped transient (30-min TTL) so the slim discovery response applies across MCP HTTP requests, not just within one process. New site_context_changed boolean in the response.
* Tier hierarchy documented: PrerequisiteGateService tiers are now strict supersets using spread syntax (direct ⊂ instructed ⊂ full ⊂ design) with a class-level doc table and a new get_required_flags() public API.
* Design gate error messages now include a copy-pasteable propose_design(page_id=X, ...) next call, trimming one round-trip for AI clients learning the gate.
* Design pipeline diagnostic: new DesignPipelineCheck verifies data/design-patterns/ readable, pattern JSON parses, StarterClassesService returns >= 13 classes, core data JSON files valid. Surfaces as the 11th admin diagnostic.
* OnboardingService site context now uses two-level caching (request-scoped + wp_cache with version bump) and invalidates on Bricks save_post + bricks_global_classes/variables option updates.
* Design patterns JSON Schema (data/design-patterns/_schema.json) + Opis JSON Schema validation when WP_DEBUG is on. Malformed patterns log via error_log for contributors; production is unaffected.
* Minimal unit test harness under tests/ with PSR-4 autoloader and WP function stubs. Covers FormTypeDetector, PrerequisiteGateService, StarterClassesService.
* New CONTRIBUTING.md (how to add a handler, design pattern, diagnostic check) and CHANGELOG.md (Keep-a-Changelog format for GitHub release notes).

= 3.4.0 =
* Site-aware design: discovery response includes existing_page_sections (top 5 sections with label, description, background, layout, classes) and site_style_hints (aggregated common layouts, backgrounds, frequently used classes). AI is instructed to match existing patterns for consistency.
* Bootstrap design system: when site has fewer than 5 global classes, discovery returns bootstrap_recommendation with 13 starter classes (grid-2/3/4, eyebrow, tagline, hero-description, btn-primary, btn-outline, card, card-dark, card-glass, tag-pill, tag-grid). AI can bootstrap upfront or let classes emerge.
* verify_build now returns human-readable section descriptions via describe_page() — "Dark section with background image and overlay. Contains h1, text, 2 buttons" — alongside type_counts and classes_used.
* New StarterClassesService with curated starter set using CSS variables for portability.

= 3.3.2 =
* Pattern column gap and padding now applied from pattern definitions. extract_column_overrides() reads gap, padding, alignment, max_width, fill.
* All split and hero patterns updated with gap: var(--space-l).
* Default _rowGap on content columns when no pattern matched.

= 3.3.1 =
* FormTypeDetector utility: extracted duplicate form type detection regex from 3 files into single static class.
* Option key constants in BricksCore: replaced 58 hardcoded option keys across services.
* OnboardingService briefs caching: 3 get_option calls per session → 1.
* Added content keys for video, pricing-tables, testimonials, social-icons, progress-bar, rating, pie-chart.

= 3.3.0 =
* Static cache in GlobalClassService: 11 class fetches per 3-section build → 1 database read.
* ProposalService uses SiteVariableResolver's cache for variables.
* Multi-row pattern support: build_multi_row_layout() handles has_two_rows patterns with separate row_1 (split) and row_2 (grid) blocks.
* Form styling defaults: auto-detect form type (newsletter/login/contact) and apply template with proper fields.
* Discovery response hash caching: second+ calls return slim response (~3KB vs ~16KB).

= 3.2.1 =
* New design pattern: hero-split-form-badges (from Brixies) — 3:2 split with newsletter form + 4-column trust badge grid below.

= 3.2.0 =
* Design Pattern Library: 17 curated compositions across 6 categories (heroes, splits, features, CTAs, pricing, testimonials, content).
* DesignPatternService loads patterns from /data/design-patterns/ with tag-based matching.
* Discovery returns reference_patterns (2-3 matches) for AI to adapt.
* SchemaSkeletonGenerator uses pattern column overrides for layout intelligence.

= 3.1.3 =
* Background merge fix: apply_background() merges with existing _background instead of replacing (preserves images through dark mode).
* background_image field in design_plan with "unsplash:query" support.
* Auto-wrap consecutive buttons in row block.

= 3.1.2 =
* Fix: stale ELEMENT_PURPOSES reference (renamed to ELEMENT_CAPABILITIES).

= 3.1.1 =
* Element capabilities with purpose + capabilities + rules for 20 elements.
* Building rules from BUILDER_GUIDE.md served in discovery.
* Dynamic element schemas in proposal phase for all 80-90 Bricks elements.
* Button icon pipeline support, Unsplash background resolution, invalid key auto-fix (_maxWidth → _widthMax, _textAlign → _typography.text-align).
* Gap auto-conversion: _gap on flex blocks → _columnGap/_rowGap based on direction.

= 3.1.0 =
* Design-first pipeline with two-phase propose_design. Phase 1 discovery returns site context and element capabilities, no proposal_id. Phase 2 with design_plan returns proposal_id and suggested_schema.
* New prerequisite gates: design_discovery and design_plan flags. build_from_schema requires 5 flags.
* New verify_build tool with element counts, type counts, classes used, hierarchy summary.
* Strict schema validation: unknown keys rejected with suggestions.

= 3.0.7 =
* Fixed: get_onboarding_guide tool not being registered when Bricks Builder is not active
* The onboarding tool now registers in register_default_tools() instead of register_bricks_tools()
* Onboarding only reads WordPress options and doesn't require Bricks Builder
* Fixed build_from_schema proposal consumption — proposals now consumed only after validation passes, allowing schema errors without burning the proposal

= 3.0.5 =
* Fixed: Missing import statement for OnboardingHandler in Router.php

= 3.0.4 =
* Fixed: Add static caching to get_site_context() for better performance on sites with many pages
* Improved: Example description accuracy in onboarding guide

= 3.0.3 =
* Added MCP onboarding system for automatic AI assistant orientation on new sessions
* Added get_onboarding_guide tool with section filtering (all, workflows, examples, site_context, briefs, acknowledge)
* Added onboarding payload with site context, 3-tier workflow guide, quick-start examples, and briefs
* Added session tracking with first-session detection and acknowledgment tracking
* Integrated onboarding into MCP initialize response with requires_onboarding_review flag

= 3.0.2 =
* Added summary and next_step fields to propose_design tool output.
* Summary provides human-readable synthesis of resolved data: element types detected, matching global classes, scoped variable categories, and briefs status.
* Next step gives clear call-to-action for AI clients to write schema and call build_from_schema.

= 3.0.1 =
* Minor fixes and improvements.

= 3.0.0 =
* Initial 3.0 release with comprehensive build pipeline.

= 2.10.0 =
* Added MetaBox integration: list field groups, get fields, read field values, dynamic tags for Meta Box custom fields.
* Added WooCommerce builder tools: status checks, WC-specific elements and dynamic tags, template scaffolding for all WC pages.
* Added template import from URL and JSON data.
* Added page import from Bricks clipboard format.

= 2.9.0 =
* Added on-demand knowledge fragments via `bricks:get_knowledge` — 8 domain-specific guides (building, forms, dynamic-data, components, popups, woocommerce, animations, seo).
* Replaced monolithic builder guide with modular knowledge system.
* Removed `get_builder_guide` tool.

= 2.8.0 =
* Added element defaults system (`data/element-defaults.json`) for automatic element settings.
* Added element hierarchy rules (`data/element-hierarchy-rules.json`) for structural validation.
* Added class context rules (`data/class-context-rules.json`) for contextual class auto-suggestion.
* Smart global class creation: styles are now accepted directly in `global_class:create` and `global_class:batch_create`.

= 2.7.0 =
* Added CSS variable resolution in `build_from_schema` — palette color references in class_intent automatically resolve to site CSS variables.
* Added SiteVariableResolver service.
* Contextual class auto-suggestion: `build_from_schema` suggests relevant global classes based on element type and section context.

= 2.6.0 =
* Added auto-snapshot before all content write operations (update_content, append_content, build_from_schema).
* Added snapshot/restore/list_snapshots actions to the page tool.
* Added page duplicate action.

= 2.5.0 =
* Added typography scale management tool for CSS variable-based type scales.
* Added theme style management tool for site-wide typography, colors, and spacing.
* Added font management tool (Adobe Fonts, webfont loading settings).

= 2.4.0 =
* Added component tool: list, create, update, delete, instantiate, update properties, fill slots.
* Added `page:get` views: summary (tree outline), context (tree with text/classes), describe (human-readable descriptions).
* Added element find action with type, text, and class filters.

= 2.3.0 =
* Added design build gate: section-level layouts and large element trees (>8 elements) are redirected to `build_from_schema` for consistent output.
* Added tiered prerequisite gating: direct operations require site info, instructed builds add global classes, design builds add full context.
* Intent router now classifies every request and enforces prerequisites via server instructions.

= 2.2.0 =
* Added SchemaExpander service for pattern references (`ref`/`repeat`/`data` substitution) in `build_from_schema`.
* Added responsive_overrides support in design schemas for per-breakpoint style overrides.
* Dark section auto-colors: text and headings in dark sections automatically get light color treatment.

= 2.1.0 =
* Added `build_from_schema` tool — declarative design pipeline that translates design intent into Bricks elements.
* Pipeline services: DesignSchemaValidator, ClassIntentResolver, SchemaExpander, ElementSettingsGenerator, BuildHandler.
* Automatic element ID generation, parent/child linkage, and settings normalization.

= 2.0.0 =
* Major architecture rewrite: Router reduced from ~3900 to ~1050 lines.
* Introduced ToolRegistry for centralized tool definition and storage.
* Split monolithic router into 16 focused handler files.
* Introduced StreamableHttpHandler for MCP Streamable HTTP transport.
* Added PrerequisiteGateService for server-enforced workflow prerequisites.
* Moved from single REST endpoint to Streamable HTTP with session support.

= 1.9.0 =
* Added design interpretation workflow for building pages from visual references.
* Added map_design tool for matching design descriptions to site assets.
* Added token-based confirmation system for destructive operations.
* Enhanced admin settings with connection status badge, getting started checklist, and request counter.
* Comprehensive security hardening: CSS sanitization, role validation, input sanitization, depth limits.

= 1.0.0 =
* Initial release.
