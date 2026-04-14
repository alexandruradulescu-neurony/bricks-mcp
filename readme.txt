=== Bricks MCP ===
Contributors: alexradulescu
Tags: ai, bricks builder, mcp, artificial intelligence, page builder
Requires at least: 6.4
Tested up to: 6.9
Stable tag: 3.11.3
Requires PHP: 8.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI assistants like Claude to your Bricks Builder site. Build and edit pages using natural language — no clicking required.

== Description ==

Bricks MCP turns your WordPress site into an AI-controlled page builder. It implements the Model Context Protocol (MCP) — an open standard for connecting AI assistants to external tools — so that any MCP-compatible client (Claude Desktop, Claude Code, and others) can read and modify your Bricks Builder pages through plain conversation.

The plugin supports three workflows:

1. **Direct operations** — Edit text, move elements, swap images, update menus. The AI uses element/page/menu tools directly.
2. **Instructed builds** — Add a heading and two columns, insert a form. The AI uses bulk_add or append_content with awareness of your global classes.
3. **Design builds** — Design a services page, build a landing page, redesign the hero. Server-enforced 4-step pipeline: DISCOVER (site context + element capabilities + reference patterns) → DESIGN (structured design_plan) → BUILD (build_from_schema with class resolution, pattern expansion, auto-fixes) → VERIFY (human-readable section descriptions).

Tell your AI assistant "design a services section with three feature cards" and the design build pipeline analyzes your existing site, picks a reference pattern, generates a valid schema, and writes the final Bricks elements with proper global classes and CSS variables.

= Site-Aware Building =

When connecting to a site with existing designed pages, the discovery phase analyzes those sections and tells the AI to reuse the same classes, layouts, and background treatments for visual consistency. For sites with no design system yet, the plugin offers 13 curated starter classes (grids, typography, buttons, cards, tags) that can be bootstrapped with one `global_class:batch_create` call.

= Design Pattern Library =

17 curated section compositions (heroes, splits, features, CTAs, pricing, testimonials, content) serve as reference examples during discovery. Patterns capture *composition* — what elements in what arrangement — not appearance. The site's design system handles how sections actually look.

= Pipeline Auto-Fixes =

- `_gap` on flex blocks auto-converts to `_columnGap` / `_rowGap` based on direction
- `_maxWidth` → `_widthMax`, `_textAlign` → `_typography.text-align`
- Button `icon` → native Bricks icon settings (no emoji)
- Form without fields → auto-detects type (newsletter/login/contact) and applies template
- `unsplash:query` in `_background.image` → auto sideload
- `background: "dark"` → merges with existing background settings (preserves images)
- Unknown schema keys → rejected with suggestions ("Did you mean style_overrides?")

= How It Works =

The plugin registers a REST API endpoint on your WordPress site that speaks the MCP protocol. You add the endpoint URL to your AI client's MCP configuration, authenticate with a WordPress Application Password, and your AI can start working with your site immediately.

An intent router classifies every request into one of the three workflows above, each with server-enforced prerequisites (site info, global classes, CSS variables). A design build gate ensures that section-level layouts and large element trees are always routed through `build_from_schema` for consistent, high-quality output.

= Available Tools (23 tools) =

* **get_site_info** — Site config, design tokens, color palette, page summaries
* **confirm_destructive_action** — Token-based confirmation for delete/replace operations
* **page** — List, search, get (detail/summary/context/describe views), create, update content, append, import clipboard, delete, duplicate, snapshots, SEO
* **element** — Add, update, remove, move, bulk add/update, duplicate, find elements on pages
* **template** — Manage Bricks templates (header, footer, content, popup), import/export
* **template_condition** — Set template display conditions
* **template_taxonomy** — Manage template tags and bundles
* **bricks** — Enable/disable Bricks on pages, builder settings, element schemas, breakpoints, dynamic tags, query types, form/interaction/component/popup/filter/condition schemas, global queries, AI notes, on-demand knowledge fragments
* **global_class** — Create/edit/delete CSS classes with styles, batch operations, import CSS/JSON, categories
* **global_variable** — Manage CSS variables and categories
* **color_palette** — Manage color palettes and individual colors
* **typography_scale** — Manage typography scale variables
* **theme_style** — Manage Bricks theme styles (site-wide typography, colors, spacing)
* **component** — List/create/update components, instantiate, update properties, fill slots
* **font** — Adobe Fonts, font settings, webfont loading
* **code** — Page CSS and custom scripts
* **media** — Unsplash search, sideload images, manage featured images, image settings
* **menu** — Create/edit/delete menus, assign to locations
* **wordpress** — Get posts/users/plugins, activate/deactivate plugins, create/update users
* **metabox** — Read Meta Box custom fields, list field groups, get dynamic tags
* **woocommerce** — WooCommerce status, elements, dynamic tags, template scaffolding
* **build_from_schema** — Declarative design pipeline: validates schema, resolves class intents, expands patterns, generates element settings, resolves CSS variables, and writes the final Bricks content

All tools are free to use. The plugin is open source and hosted on [GitHub](https://github.com/alexandruradulescu-neurony/bricks-mcp).

= Authentication =

All requests are authenticated using WordPress Application Passwords, the built-in authentication system available since WordPress 5.6. No third-party authentication service is involved.

= Requirements =

* WordPress 6.4 or later
* PHP 8.2 or later
* Bricks Builder theme 1.6 or later (required for Bricks-specific tools)

= Getting Started =

1. Install and activate the plugin.
2. Go to **Settings > Bricks MCP** and enable the plugin.
3. Create a WordPress Application Password under **Users > Profile**.
4. Add the MCP server URL to your AI client configuration.
5. Start building pages with natural language.

Full setup documentation is available in the [GitHub repository](https://github.com/alexandruradulescu-neurony/bricks-mcp).

== External Services ==

This plugin optionally connects to the Unsplash API to search for images.

**Service:** Unsplash (api.unsplash.com)
**When used:** Only when the `search_media` tool is called by an AI assistant, and only if you have configured an Unsplash API key in the plugin settings.
**What is sent:** Your search query string and your Unsplash API key.
**Unsplash Terms of Service:** https://unsplash.com/terms
**Unsplash Privacy Policy:** https://unsplash.com/privacy
**Unsplash API Guidelines:** https://unsplash.com/documentation

No data is sent to Unsplash unless you explicitly configure an API key and an AI assistant invokes the image search tool.

No other external services are contacted by this plugin.

== Installation ==

1. Upload the `bricks-mcp` folder to the `/wp-content/plugins/` directory, or install the plugin via the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Settings > Bricks MCP** to configure the plugin.
4. Enable the MCP server and optionally require authentication (strongly recommended for production sites).
5. Go to **Users > Your Profile** and scroll to **Application Passwords**. Create a new Application Password and copy it — you will need it for your AI client.
6. Add your site's MCP endpoint URL and credentials to your AI client (see the [GitHub repository](https://github.com/alexandruradulescu-neurony/bricks-mcp) for client-specific setup guides).
7. (Optional) Enter an Unsplash API key in the settings to enable image search.

== Frequently Asked Questions ==

= What is MCP (Model Context Protocol)? =

MCP is an open protocol created by Anthropic that gives AI assistants a standard way to connect to external tools and data sources. It works like a universal adapter: the AI client connects to an MCP server, discovers what tools are available, and calls them by name. This plugin implements that server for WordPress and Bricks Builder.

= Does this plugin work without Bricks Builder? =

Yes, partially. The core WordPress tools (get_site_info, wordpress, media, menu) work on any WordPress site regardless of the active theme. The Bricks-specific tools (page, element, template, bricks, global_class, component, etc.) require Bricks Builder to be installed and active.

= Which AI tools and clients are supported? =

Any MCP-compatible client can connect to this plugin. Verified clients include Claude Desktop and Claude Code. Because MCP is an open protocol, support for other clients is expected to grow over time.

= Is it safe to expose a REST API endpoint for AI access? =

Yes, when configured correctly. The plugin includes multiple security layers: WordPress Application Password authentication (enabled by default), per-tool capability checks, configurable rate limiting (120-1000 RPM), a Dangerous Actions toggle that gates JavaScript/code injection, token-based confirmation for destructive operations (delete, replace, cascade remove), auto-snapshots before content writes, element count safety checks that prevent accidental content wipes, and centralized CSS sanitization. Never disable authentication on a publicly accessible site.

== Screenshots ==

1. The Bricks MCP settings page under Settings > Bricks MCP.
2. Example Claude Desktop configuration connecting to the MCP server endpoint.
3. An AI assistant creating a Bricks Builder hero section from a plain-text prompt.

== Changelog ==

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