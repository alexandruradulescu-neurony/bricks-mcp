# Changelog

All notable changes to the Bricks MCP plugin are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project follows [Semantic Versioning](https://semver.org/) where practical.

For the WordPress.org plugin update system, see also `readme.txt` (same content, WP format).

## [3.18.2] — 2026-04-15

### Design System v2 — UI improvements

- **Text typography step rows now show live "Body text" preview** at both mobile and desktop sizes (matching the Headings panel)
- **Text Styles merged into Typography panel** — text-color, heading-color, font weights, line heights now appear at the bottom of the Typography step. Removed standalone Text Styles step from the stepper. Border colors stay in the Radius step (matches Fancy Framework convention).
- **Gaps / Padding panel now has visual indicators** — each row shows two boxes with the gap value as the spacing between them
- **Radius panel now has visual indicators** — each row shows a colored square with the actual border-radius value applied (live updates as you type)
- **Live preview restored to full mockup** — hero with gradient + buttons, body section with feature stats cards, sidebar quick-start card, and footer. Includes token labels (`--primary`, `--h1`, etc.) so you can see which variables drive each piece.

## [3.18.1] — 2026-04-15

### Fixes — Design System v2

- **Version constant**: bump `BRICKS_MCP_VERSION` to match docblock (was stale at 3.17.0, causing wrong version in admin header + spurious "update available" notice)
- **Heading previews now grow live** when step values change (font-size updated from input)
- **Spacing swatches now grow live** with the desktop value (visual size indicator works)
- **Structural toggles re-render the Colors panel** — Enable / Expand Color Palette / Transparencies now correctly add or remove shade inputs and the transparency strip (previously only the config was updated, DOM stayed stale)
- New AJAX endpoint `bricks_mcp_ds_render_panel` returns server-rendered panel HTML for structural refresh

## [3.18.0] — 2026-04-15

### Design System v2

- Rewrite admin Design System tab as left-rail stepper with 7 panels (Spacing, Typography, Colors, Gaps/Padding, Radius, Sizes, Text Styles)
- Every generated value is now individually editable, matching the Fancy Framework configurator
- Add two new color families: **tertiary**, **neutral** (both disabled by default)
- Add **Expand Color Palette** toggle per family — switches from 5 shades to 8 (adds semi-dark, medium, semi-light)
- Add **Transparencies** toggle per family — generates 9 transparency variants (90% → 10%)
- Add **hover variants** per family (auto-derived `darken(base, 10%)`, editable)
- Add **White** and **Black** families with independent transparency toggles
- Add **HTML font-size** toggle (62.5% / 100%) — emits `html { font-size: 100%; }` when 100% is selected
- Add editable **Text Styles** panel (`--text-color`, `--heading-color`, font weights, line heights)
- Add editable **Gaps/Padding** refs (accept `var()` values)
- Add **Radius** individual variant overrides (all 10 derived values editable) + border colors
- Add **Sizes** extra fields (max-width / max-width-m / max-width-s, min-height / min-height-section, logo-width mobile/desktop)
- Refactor: extract `ScaleComputer`, `ColorComputer`, `ConfigMigrator` helpers from `DesignSystemGenerator`
- Existing configs are auto-migrated on read — no user action required

### Palette

- BricksCore palette now includes hover variants (flat entries per enabled family)
- Expanded shades and transparencies live as CSS variables only (not in palette) to keep the Bricks color picker usable

## [3.16.0] — 2026-04-15

### Fixed — Fresh Site Portability
- **Starter classes use CSS fallbacks** — all `var()` references in `StarterClassesService` now include fallback values (e.g., `var(--primary, #3f4fdf)`). Works on sites with zero configured variables.
- **SiteVariableResolver returns hex on empty sites** — semantic methods (`dark_background()`, `primary_color()`, etc.) return raw hex values instead of broken `var()` references when no variables exist.
- **BUILDING_RULES adapt to site state** — `ProposalService` detects whether a design system is present. Sites with <5 classes and no variables get permissive rules instead of "child theme handles it" restrictions.
- **WooCommerce scaffolds portable** — all scaffold `var()` references include CSS fallback values. Empty cart "Return to Shop" uses `wc_get_page_permalink('shop')` instead of hardcoded `/shop`.
- **Dark/light detection improved** — removed site-specific class name checks (`article-section`, `cta-section`). Uses regex segment matching to avoid false positives on names like `--sidebar-dark-border`.

### Changed — Infrastructure
- **GitHub repo URL centralized** — new `BRICKS_MCP_GITHUB_REPO` constant. UpdateChecker, Settings footer links derive from it.
- **Meta key constant everywhere** — replaced 12 hardcoded `_bricks_page_content_2` strings with `BricksCore::META_KEY`.
- **SSE keepalive filterable** — `apply_filters('bricks_mcp_keepalive_interval', 25)`, clamped 5–55s.
- **Prerequisite TTL extended** — 30 min → 2 hours, filterable via `bricks_mcp_prerequisite_ttl`.
- **GitHub API token support** — define `BRICKS_MCP_GITHUB_TOKEN` for authenticated update checks on shared hosting.
- **UpdateChecker** — `requires_php` fallback uses `BRICKS_MCP_MIN_PHP_VERSION` constant. User-Agent reports actual plugin version.
- **Documentation URL removed** from OAuth protected-resource response.

### Changed — Code Quality
- **Dead code removed** — `render_version_card()`, `$public_tools` empty array, unregistered cron hook cleanup.
- **`confirm` parameter removed from schemas** — 10 handlers updated. Token-based confirmation works transparently.
- **OnboardingHandler lazy loading** — section requests call specific methods instead of generating full payload.
- **Migrations gated** — `migrate_settings()` and `migrate_plugin_patterns()` only run on version change.
- **Unbounded queries fixed** — `posts_per_page => -1` replaced with `wp_count_posts()` in OnboardingService.
- **Stronger randomness** — `bin2hex(random_bytes())` replaces `md5(time())` for note IDs. `random_int()` replaces `str_shuffle()` in DesignSystemGenerator.
- **DesignPatternHandler description** — removed 6 advertised-but-unimplemented actions.
- **Deprecated `rest_enabled` filter removed** from Activator.

### Added — Extensibility
- 7 new `apply_filters()` hooks: `bricks_mcp_known_hosting_providers`, `bricks_mcp_known_security_plugins`, `bricks_mcp_condition_schema`, `bricks_mcp_form_schema`, `bricks_mcp_interaction_schema`, `bricks_mcp_filter_schema`, `bricks_mcp_intent_map`.
- Named constants: `Router::DESIGN_GATE_THRESHOLD`, `RateLimiter::DEFAULT_RPM`, `StreamableHttpHandler::MAX_BODY_HARD_LIMIT`.
- `ThemeStyleService` — global variable replaced with class property.
- `MetaBoxHandler` — null checks on `rwmb_get_registry()` return.
- `RateLimiter` — `@header()` suppression replaced with `headers_sent()` check.

## [3.14.1] — 2026-04-14

### Fixed
- **bulk_add parent key respected** — `ElementNormalizer::simplified_to_flat()` now uses explicit `parent` key from input elements instead of always assigning to root. Elements with `"parent": "abc123"` in bulk_add now land in the correct parent.
- **data.* resolves in HTML content** — `SchemaExpander::replace_data_references()` now handles bare `data.key` references within HTML strings (e.g., `<h4>data.title</h4>`), not just exact matches and `{data.key}` interpolation. Fixes icon-box and other compound elements getting literal "data.feature_title" as content.

## [3.14.0] — 2026-04-14

### Changed — Code review cleanup (~1,200 LOC removed)
- **PrerequisiteGateService simplified** — 5 flags → 2 (`site_context` + `design_ready`). 4 tiers → 2 (`direct` + `design`). Same protection, fewer moving parts.
- **StyleShapeValidator deleted** — 7 auto-fix rules moved to source (ElementSettingsGenerator + GlobalClassService). Band-aid removed; shapes generated correctly from the start.
- **DesignPatternService stripped to essentials** — removed semantic_search, normalize_pattern, generate_prompt_template, category registry (5 CRUD methods), Tier 1/2 loading, migration method. Kept: list, get, create, update, delete, export, import.
- **DesignPatternHandler** — removed 8 MCP actions (semantic_search, normalize, generate_prompt, 4 category actions). Kept: list, get, create, update, delete, export, import.
- **PatternsAdmin** — removed 6 AJAX handlers, category management UI, source filter, Generate AI Prompt button. Category is now free-text input.
- **admin-patterns.js** — removed category CRUD handlers, normalize import step, prompt generation, source filter. Cleaner JS.
- **data/design-patterns/ directory deleted** — 7 JSON files dead after DB migration.
- **Deprecated BACKGROUND_COLOR_MAP constant removed** from ProposalService.
- **Romanian hardcoded text removed** from OnboardingService default notes.
- **hero_min_height** added to BriefResolver — no longer hardcoded 80vh in SchemaSkeletonGenerator.

## [3.13.0] — 2026-04-14

### Added — Structured Design Brief + BriefResolver
- **`BriefResolver` service** — new singleton that resolves design brief fields through a 3-tier fallback chain: structured brief (wp_option) → auto-detect from site variables/classes → hardcoded defaults. Single source of truth for all design values consumed by the build pipeline.
- **Structured brief admin UI** — new "Design Rules" section in AI Context tab with 16 configurable fields organized in 7 groups (Dark Sections, Light Sections, Cards, Section Headers, Buttons & Classes, Icons, Spacing). Dropdowns for class fields populated from global classes. "Parse from Text" button placeholder for future AI-powered extraction.
- **Zero hardcoded values in build pipeline** — replaced all hardcoded CSS values in ProposalService, SchemaSkeletonGenerator, and ElementSettingsGenerator with `BriefResolver::get()` calls. Dark section backgrounds, text colors, card padding/radius, button class intents, gradient overlays — all now read from the structured brief.
- **Design System auto-fill** — clicking "Apply to Site" in the Design System tab now auto-populates empty structured brief fields with the generated variable references (e.g., `var(--base-ultra-dark)` for dark bg, `var(--radius)` for card radius).
- **Dynamic background color map** — `ProposalService::get_background_color_map()` replaces the static `BACKGROUND_COLOR_MAP` constant, reading tinted background colors from BriefResolver.
- **Role-to-class mapping** — SchemaSkeletonGenerator now reads eyebrow, primary button, and secondary button class names from structured brief instead of hardcoded role map.

## [3.12.3] — 2026-04-14

### Changed — Visual polish
- **Unified table styling** — all `widefat` tables within config sections now have rounded corners, refined borders, hover states, and consistent spacing. Replaces raw WordPress table defaults.
- **Branded inputs** — text inputs, number inputs, textareas, and selects within config sections get rounded borders, focus ring with brand color (`--bwm-brand`), and consistent sizing.
- **Branded buttons** — primary buttons use indigo brand color, secondary buttons get hover accent. Consistent border-radius across all tabs.
- **Modal polish** — pattern modals get header border, better heading styling.
- **Creator form** — pattern creator fields get consistent label styling, full-width inputs, monospace textareas.
- **Category inline edit** — inputs get brand focus ring.
- **Toolbar styling** — pattern filter toolbar gets background, border, rounded corners.
- **Notes add row** — flex layout with proper spacing.

## [3.12.2] — 2026-04-14

### Changed
- **Merged AI Notes + Briefs** into single "AI Context" tab. Notes on top, Briefs below.
- **Tab order** finalized: Design System → Patterns → AI Context → Connection & Settings → System Health (5 tabs, was 6).

## [3.12.1] — 2026-04-14

### Changed — Admin polish (Phase 2)
- **Patterns JS modernized** — `admin-patterns.js` rewritten from jQuery to vanilla JavaScript. Zero jQuery dependency. Uses `fetch`/`FormData`, event delegation, `querySelector`. Matches `admin-design-system.js` coding style.
- **Settings tab merged into Connection** — Settings form now appears at the bottom of the Connection tab under a "Settings" heading. One fewer tab. Tab renamed to "Connection & Settings".
- **jQuery dependency removed** from PatternsAdmin asset enqueue.

## [3.12.0] — 2026-04-14

### Changed — Admin restructure
- **Extracted PatternsAdmin class** — all pattern/category CRUD, AJAX handlers (11 methods), tab rendering, and asset enqueue moved from Settings.php to `includes/Admin/PatternsAdmin.php` (460+ lines).
- **Extracted DiagnosticsAdmin class** — diagnostic panel rendering, AJAX handler, and asset enqueue moved to `includes/Admin/DiagnosticsAdmin.php`.
- **Settings.php reduced** from 2,213 lines to 1,665 lines. Now contains only Connection, Settings, Briefs, and AI Notes tabs.
- **Tab reorder** — logical grouping: Build (Design System, Patterns) → Configure (Connection, Settings) → Monitor (AI Notes, Briefs, System Health).
- **CSS theme alignment** — Design System tab now uses `--bwm-*` CSS variables from admin-settings.css instead of hardcoded WordPress grays.
- **Selective asset loading** — each tab's JS/CSS is only enqueued when that tab is active (via delegated `enqueue_assets()` methods).

## [3.11.3] — 2026-04-14

### Fixed
- **Preview panel too small** — removed max-width constraints from right panel, let it fill available space. Left accordion column fixed at 480px, preview expands to fill remaining width. Removed inner mockup margin.

## [3.11.2] — 2026-04-14

### Changed
- **Side-by-side layout** — Design System tab now uses a two-column layout: accordion config panels on the left, live preview always visible on the right. Preview panel is sticky and scrolls with the page.
- Preview is no longer an accordion section — it's a persistent panel that updates as you edit any section.
- Responsive: stacks vertically below 1200px.

## [3.11.1] — 2026-04-14

### Added
- **Live preview mockup** — mini-website (hero + content/sidebar + stats + footer) rendered with actual computed design token values. Updates instantly on every input change.
- **Token labels** — each element in the preview shows a semi-transparent tag indicating which CSS variable it uses (e.g., `--h1`, `--primary`, `--radius`).

## [3.11.0] — 2026-04-14

### Added — Design System Generator
- **New "Design System" admin tab** — visual configurator for generating a complete Bricks design system from seed values (colors, spacing scale, typography scale, radius, container sizes).
- **`DesignSystemGenerator` service** — PHP computation engine that generates ~106 CSS variables, a color palette (~31 colors), and framework CSS from minimal seed inputs. Uses Fancy Framework's exact algorithms: fluid `clamp()` values, RGB lighten/darken color shading, exponential scale ratios.
- **`DesignSystemAdmin` class** — handles tab rendering with accordion sections (Colors, Spacing, Typography, Radius, Sizes) and 3 AJAX endpoints (save config, apply to site, reset to defaults).
- **Direct apply to Bricks** — "Apply to Site" button writes generated variables to `bricks_global_variables`, color palette to `bricks_color_palette`, and framework CSS to `bricks_global_settings['customCss']` (between comment markers for clean re-apply).
- **Namespaced replace** — generator owns 9 variable categories (Spacing, Texts, Headings, Gaps/Padding, Styles, Radius, Sizes, Colors, Grid). User-created categories are never touched.
- **Client-side preview** — JavaScript mirrors PHP computation engine for instant preview of computed values as user adjusts inputs. Color shade strips, value tables, radius preview boxes.
- **Default preset** — ships with sensible defaults (blue/amber/emerald palette, 1.5 spacing scale, 1.25 typography scale, 8px radius, 1280px container).
- **Auto-save** — config persists via debounced AJAX on every input change.
- **Framework CSS** — includes scroll behavior, accessibility focus styles, text normalization, container defaults, overflow fixes, and custom link styling.
- **24 grid variables** — grid-1 through grid-12 plus 12 ratio grids (grid-1-2, grid-2-1, etc.).

## [3.10.1] — 2026-04-14

### Added
- **Pattern import normalization** — `DesignPatternService::normalize_pattern()` walks a pattern's class_role/class_intent references and `var(--*)` variable references, maps each to the closest site match via `semantic_search_classes()` and variable lookup. Returns normalized pattern + mapping report + warnings for unmatched references.
- **AI prompt generation** — `DesignPatternService::generate_prompt_template()` builds a structured prompt using site context (element capabilities, layouts, building rules, existing classes) for AI-assisted pattern composition. Returns prompt string + context + output schema.
- **2 new MCP actions** on `design_pattern`: `normalize` (map external pattern to site) and `generate_prompt` (get AI prompt for creating a pattern from description/image).
- **Admin "Generate AI Prompt" button** in pattern creator form — copies the structured prompt to clipboard. User pastes into Claude Code or any AI, gets composition JSON back, pastes into the Composition field.
- **Admin import normalization** — importing patterns via the admin UI now auto-normalizes before saving. Class references matched, variables checked, warnings displayed.
- **2 new AJAX endpoints**: `bricks_mcp_generate_prompt`, `bricks_mcp_normalize_patterns`.

## [3.10.0] — 2026-04-14

### Changed — Database-first pattern architecture
- **No more hardcoded patterns.** All 21 plugin-shipped patterns auto-migrate to the database tier (`bricks_mcp_custom_patterns` wp_option) on first plugin update. The `data/design-patterns/` directory is kept as an inert archive but no longer loaded after migration.
- **`DesignPatternService::load_all()`** gates Tier 1 (plugin files) behind the `bricks_mcp_patterns_migrated` flag. After migration, only Tier 2 (user files) and Tier 3 (database) are loaded.
- **Pattern `create()` and `update()`** now validate category against the registry. Unregistered categories are rejected with a clear error listing available options.

### Added — Category registry
- **`bricks_mcp_pattern_categories`** wp_option — standalone array of `{id, name, description}`. Categories persist independently of patterns.
- **Service methods:** `get_categories()`, `create_category()`, `update_category()`, `delete_category()`, `seed_categories()`, `migrate_plugin_patterns()`.
- **4 new MCP tool actions** on `design_pattern`: `list_categories` (with pattern count per category), `create_category`, `update_category`, `delete_category`.
- **8 default categories** seeded on migration: hero, features, cta, pricing, testimonials, splits, content, generic.

### Added — Admin UI rewrite
- **Categories section** in Settings > Bricks MCP > Patterns: table with name/ID/description/pattern count, inline edit, delete with in-use warning, "Add Category" form.
- **Pattern creator/editor form** (modal): structured fields for name, auto-slug ID, category dropdown, tags, layout, background, AI description with character counter, AI usage hints, composition JSON editor, reference image via WP Media Library.
- **Edit mode**: click Edit on any DB pattern → opens the creator form pre-populated. Save replaces the pattern.
- **5 new AJAX endpoints**: `list_categories`, `create_category`, `update_category`, `delete_category`, plus existing pattern endpoints.
- **`wp_enqueue_media()`** loaded for pattern reference image uploads.

### Added — Migration system
- **`Plugin::init()` → `DesignPatternService::migrate_plugin_patterns()`** — reads all JSON files from `data/design-patterns/`, bulk-inserts into wp_options (skips IDs already present), seeds category registry, sets version flag. Idempotent.

### Cleanup
- `uninstall.php` expanded with `bricks_mcp_custom_patterns`, `bricks_mcp_hidden_patterns`, `bricks_mcp_pattern_categories`, `bricks_mcp_patterns_migrated`, `bricks_mcp_briefs`, `bricks_mcp_notes`.
- `data/design-patterns/_schema.json` category field changed from fixed enum to dynamic string pattern.

## [3.9.1] — 2026-04-14

### Added
- **Admin UI for Design Pattern Library** — new "Patterns" tab in Settings > Bricks MCP.
  - Table listing all patterns across 3 tiers with source badges (Plugin = indigo, User File = amber, Database = green), category, layout, tags, AI description per row.
  - Category and source dropdown filters.
  - View JSON modal: read-only for plugin/user-file patterns, editable + saveable for database patterns.
  - Create pattern modal: paste JSON to add a new DB pattern.
  - Delete (DB patterns) / Hide (plugin/user patterns) per row.
  - Export: download selected patterns or all DB patterns as JSON file.
  - Import: upload a JSON file, auto-suffixes conflicting IDs.
  - Select-all checkbox for bulk export.
- `assets/js/admin-patterns.js` — AJAX handlers for all pattern admin operations.
- Pattern-specific CSS in `assets/css/admin-settings.css` — source badges, tag pills, modal styles.

## [3.9.0] — 2026-04-14

### Added
- **`design_pattern` MCP tool** — new top-level tool with 8 actions: `list`, `get`, `semantic_search`, `create`, `update`, `delete`, `export`, `import`. Full CRUD for a 3-tier design pattern library.
- **3-tier pattern loading** — `DesignPatternService::load_all()` now merges patterns from: (1) plugin-shipped `data/design-patterns/` (read-only), (2) user files in `wp-content/uploads/bricks-mcp/design-patterns/` (survives plugin updates), (3) database via `wp_options` (full CRUD). Override priority: database > user files > plugin-shipped.
- **Semantic search for patterns** — `design_pattern:semantic_search(query)` with heuristic scoring: name (10pts), ID stems (5pts), ai_description (6pts), tags (4pts), category (3pts), layout/background (2pts). Mirrors `global_class:semantic_search` algorithm.
- **AI metadata on all 21 patterns** — `ai_description` (1-2 sentence visual description) and `ai_usage_hints` (2-3 actionable tips) backfilled on every plugin-shipped pattern.
- **Pattern export/import** — `design_pattern:export(ids?)` returns portable JSON array; `design_pattern:import(patterns)` saves with auto-suffix on ID conflicts (-v2, -v3).
- **Hidden patterns** — `design_pattern:delete(id)` on plugin/user-file patterns sets a hidden flag (soft-delete) rather than modifying source files.
- **Pattern schema extended** — `data/design-patterns/_schema.json` now includes `ai_description`, `ai_usage_hints`, and `source` fields.
- **Documentation** — `docs/knowledge/building.md` gains a "Design Pattern Library" section documenting the 3-tier system, discovery workflow, and all MCP actions.

### Changed
- `DesignPatternService::list_all()` now returns `ai_description`, `ai_usage_hints`, and `source` per pattern.
- `propose_design` Phase 1 discovery's `reference_patterns` now includes patterns from all 3 tiers with `source` labels.

## [3.8.0] — 2026-04-14

### Added — 7 new tool actions for AI self-verification + workflow

- **`propose_page_layout`** (new top-level tool) — maps a page intent (`landing page`, `services page`, `about page`, `product page`, `contact page`) to a sequenced list of sections with recommended pattern IDs and ready-to-use `design_plan` skeletons. AI loops through sections instead of thinking section-by-section. Args: `intent`, `page_id` (optional), `tone` (optional).
- **`page:describe_section`** action — rich per-section styling description. Returns `rendered_description` prose ("Dark section with radial red gradient...") plus structured `element_breakdown` array with key style values per element. AI self-verifies built output without needing screenshots.
- **`global_class:semantic_search`** action — natural-language class search ("card with white bg and shadow"). Heuristic scoring: name keyword (10pts), settings match (6pts), word stems (5pts). Returns ranked `matches` with `score`, `match_reasons`, `settings_summary`.
- **`global_class:render_sample`** action — generates structured description, equivalent CSS rules, and sample HTML snippet for any class. Lets AI verify class output before applying.
- **`element:copy_styling`** action — copies style settings between elements. Modes: `classes_only` / `inline_only` / `both`. Filters to 27 style-relevant keys; preserves target content/label/tag.
- **`media:smart_search`** action — Unsplash search enriched with business_brief context. Extracts top context terms (proper nouns, services, location) and appends to query. Returns `enrichment_applied` so AI sees what was added.
- **Slim dry-run for `build_from_schema`** — pass `dry_run: "summary"` (string) to get `intended_element_count` + `tree_summary` + `class_intents_resolved` without the full element tree. `dry_run: true` (boolean) unchanged.

### Documentation
- `docs/knowledge/building.md` — new section "Element-Specific Settings via element_settings" documenting the v3.7.0 escape hatch (whitelisted keys per element type, examples, defaults, auto-fixes). New section "Color Object Format" reinforces the `{raw}`/`{hex}` requirement (auto-fixed in v3.7.0 but recommended from start).

### Architecture
- New `PageLayoutService` — intent-to-section-sequence mapping with pattern matching via `DesignPatternService`.
- New `PageLayoutHandler` — wires `propose_page_layout` as a top-level Bricks-only tool.

## [3.7.0] — 2026-04-13

### Added
- **`StyleShapeValidator` service** (`includes/MCP/Services/StyleShapeValidator.php`) — 7 auto-fix rules for Bricks settings shape mismatches that previously caused silent style failures or "Array to string conversion" frontend errors. Wraps color strings into `{raw}`/`{hex}` objects (`_typography.color`, `_background.color`, `_color`, `_border.color`), collapses per-side `_border.style` to a string, expands flat `_border.width` to per-side, joins `_cssCustom` arrays. **All fixes return warnings — never hard-error.** Wired into `GlobalClassService::create_global_class`, `GlobalClassService::update_global_class`, and `ElementSettingsGenerator::build_settings`.
- **`element_settings` escape hatch** — design schema now accepts an `element_settings` key on 8 element types (pie-chart, counter, video, slider-nested, form, progress-bar, rating, animated-typing) with type-specific whitelisted keys. Pie-chart can specify `percent`, counter can specify `countTo`, video can specify URL / autoplay / loop / etc.
- **Element capabilities expanded** — `ProposalService::ELEMENT_CAPABILITIES` now describes 6 additional elements: `progress-bar`, `pie-chart`, `alert`, `animated-typing`, `rating`, `social-icons`. Element hierarchy rules updated for the 5 missing ones.
- **Sane element-settings defaults** in `data/element-defaults.json`'s new `element_settings_defaults` map: counter countTo=100, pie-chart percent=75, progress-bar with sample bar, rating=5, animated-typing strings. Applied automatically when element_settings is omitted.
- **Class attribution warnings** — `ClassIntentResolver::resolve()` now returns `classes_auto_added`. `BuildHandler` surfaces these in the response so AI sees which classes were attached by context rules vs requested in the plan.
- **Element count reconciliation** in `BuildHandler` — warns when fewer elements were generated than the schema intended (catches silent drops). Always logs both counts via `error_log`.
- **Element-level `_cssCustom` quarantine** — when `_cssCustom` appears on `text-basic`/`heading`/`button`/`icon`/`text`/`text-link` (which crash with array-to-string), the CSS is stripped and a warning recommends moving it to a `class_intent`.

### Track C — element_settings value auto-fixes
- **Counter `countTo`** — "500+", "1,200", "$99" auto-extract to integer 500/1200/99 with prefix/suffix extraction.
- **Pie-chart `percent`** — string "92%" → integer 92, clamped to 0-100.
- **Video URL conversion** — `youtube.com/watch?v=ID` and `youtu.be/ID` → `ytId` + `videoType: youtube`. `vimeo.com/ID` → `vimeoId` + `videoType: vimeo`.
- **Rating value** — clamped to 0–maxRating.

### Changed
- **Class context guards** in `data/class-context-rules.json`:
  - `tagline` rule requires content under 60 chars and an uppercase/short check (stops red-text auto-attaching to neutral subtitles).
  - `tag-grid` rule requires children to have `tag-pill` class with min 2 (stops auto-attaching to feature-card grids).
- **`DesignSchemaValidator`** — adds `element_settings` to `VALID_NODE_KEYS` and a new `ELEMENT_SETTINGS_ALLOWED` constant. Validation rejects `element_settings` on disallowed element types or with non-whitelisted keys.

### Risk-mitigated by design
- `StyleShapeValidator` never throws or rejects. Worst case for a malformed input: auto-fix + warning. Hard validation lives in `DesignSchemaValidator` above it. Legacy classes with weird shapes are auto-corrected on next save, never blocked.

## [3.6.5] — 2026-04-13

### Fixed
- **`global_class:update` TypeError** — `GlobalClassService::update_global_class()` called `store_normalization_warnings()` with an undefined variable `$normalization_warnings` (should have been `$normalized['warnings']`). Every style-bearing update crashed with `Argument #1 ($warnings) must be of type array, null given`. Now correctly reads the warnings from the normalizer return value and only stores them if non-empty.
- **Per-side `_border.style` rendering crash** — `create_global_class()` previously only called `sanitize_styles_array()`, skipping `normalize_bricks_styles()`. So classes auto-created by the design pipeline (`ClassIntentResolver`) could slip through objects like `style: {top: "dashed", right: "dashed", ...}` that Bricks' frontend expects as strings. The result was `PHP Warning: Array to string conversion` in section render. Both paths (`create` and `update`) now normalize identically.

### Known gaps still being worked
- No generic way to pass element-specific settings (pie-chart `percent`, counter `count-to`, video `url`, etc.) through the design schema. The `element_settings` whitelist feature is deferred to the next release.

## [3.6.4] — 2026-04-13

### Fixed
- **Silent Unsplash failures** — `ElementSettingsGenerator::resolve_image()` no longer falls through silently when Unsplash search, sideload, or API key lookup fails. The pipeline now attaches `_pipeline_warnings` to element settings explaining the failure reason.

### Added
- **`warnings` field in `build_from_schema` response** — `BuildHandler` collects warnings from nested element trees, strips them from settings before write (Bricks rejects unknown root keys), and surfaces them in the build response. The AI now sees: "Unsplash sideload failed for 'unsplash:business handshake': No Unsplash results (is the API key configured in Settings > Bricks MCP?)" instead of silent `success: true` with empty image slots.
- `error_log` entry on each Unsplash resolution failure for server-side debugging.

## [3.6.3] — 2026-04-13

### Fixed
- **Phase 2 gate inconsistency** — `propose_design` Phase 2 now sets BOTH `design_discovery` AND `design_plan` flags. This unblocks the documented "skip Phase 1 for subsequent sections" flow after session-flag resets (e.g. plugin reload mid-session).
- **`verify_build` silent failure** — now rejects non-string, empty, or malformed `section_id` values with a clear error instead of returning an empty `sections` array.

### Added
- **Tinted background vocabulary** — `design_plan.background` now accepts `tinted-neutral`, `tinted-accent`, `tinted-warning`, `tinted-danger` in addition to `dark`/`light`. Tinted values resolve to `*-ultra-light` CSS variables via `ProposalService::BACKGROUND_COLOR_MAP`, used for the alternating-section pattern recommended by most design briefs.
- **Pricing middle-tier featured variant** — `SchemaSkeletonGenerator` emits a second `{pattern}-featured` pattern (yellow border, larger padding) and places the middle card under that variant when `section_type = pricing` and `repeat ≥ 3`. Matches the industry convention where the recommended tier gets distinct visual treatment.
- **Section-type-aware class suggestions** — `ProposalService` now returns conventional class-name patterns per section type (e.g. pricing → `btn-primary`, `btn-outline`, `pricing-*`, `card*`). Previously only classes whose names matched words in the description were surfaced.

## [3.6.0] — 2026-04-13

### Changed
- **PageHandler split** — 1074 lines of handler monolith broken into 6 focused sub-handlers under `includes/MCP/Handlers/Page/`:
  - `PageReadSubHandler` — `list`, `search`, `get`
  - `PageSnapshotSubHandler` — `snapshot`, `restore`, `list_snapshots`
  - `PageSettingsSubHandler` — `get_settings`, `update_settings`
  - `PageSeoSubHandler` — `get_seo`, `update_seo`
  - `PageCrudSubHandler` — `create`, `update_meta`, `delete`, `duplicate`
  - `PageContentSubHandler` — `update_content`, `append_content`, `import_clipboard`

  PageHandler is now a 342-line dispatcher + tool schema. Each sub-handler is independently testable.

### Preserved
- The `page` MCP tool is unchanged externally — same schema, same action names, same argument shapes, same outputs.
- Destructive confirm flow (`bricks_mcp_confirm_required` error code on `delete`) preserved exactly — Router still intercepts and issues confirmation tokens.
- Auto-snapshot before content writes, element count / content wipe protection, and upstream design build gate interactions preserved verbatim.

### Added
- `PageHandlerDispatchTest` — structural tests for the dispatcher: all 17 actions present, all 6 sub-handlers wired, no leftover `tool_*` private methods, destructive confirm code still referenced.

## [3.5.0] — 2026-04-13

### Changed
- **Router**: collapsed 18 typed handler properties into a single `$handlers` array (1099 → 866 lines).
- **SchemaHandler**: extracted 4 large reference arrays into dedicated catalog classes under `includes/MCP/Reference/` (FilterSchemaCatalog, ConditionSchemaCatalog, FormSchemaCatalog, InteractionSchemaCatalog). SchemaHandler: 1615 → 787 lines.
- **Tool registration**: `wordpress` and `metabox` handlers self-register via `register(ToolRegistry)`, matching the existing `VerifyHandler`/`OnboardingHandler` pattern.
- **Tier hierarchy**: `PrerequisiteGateService` tiers are now strict supersets using spread syntax (`direct ⊂ instructed ⊂ full ⊂ design`) with a class-level doc table and a new `get_required_flags()` public API.
- **Design gate error messages** now include a copy-pasteable `propose_design(page_id=X, ...)` next call, trimming one round-trip.
- **OnboardingService site context** now uses two-level caching (request-scoped + `wp_cache` with version bump) and invalidates on Bricks `save_post` + option updates.

### Added
- **Cross-request discovery cache** in `ProposalService` — transient-backed per-user hash (30-min TTL) so the slim discovery response applies across MCP HTTP requests. New `site_context_changed` boolean in the response.
- **`DesignPipelineCheck`** — 11th admin diagnostic verifying design patterns directory, pattern JSON parsing, `StarterClassesService` contract, and core data JSON files.
- **JSON Schema for design patterns** (`data/design-patterns/_schema.json`) + Opis JSON Schema validation in `WP_DEBUG`. Malformed patterns log via `error_log` for contributors; production is unaffected.
- **Unit test harness** under `tests/` with PSR-4 autoloader and WP function stubs. Covers `FormTypeDetector`, `PrerequisiteGateService`, `StarterClassesService`.
- **`CONTRIBUTING.md`** — how to add a handler, design pattern, or diagnostic check.
- **`CHANGELOG.md`** — Keep-a-Changelog format for GitHub release notes.

## [3.4.0] — 2026-04-08

### Added
- **Site-aware design discovery** — `propose_design` Phase 1 now returns `existing_page_sections` (top 5 sections from the target page with label, description, background, layout, classes_used) and `site_style_hints` (aggregated common layouts, backgrounds, frequently used classes). The AI is instructed to match existing patterns for visual consistency.
- **Bootstrap recommendation** — when a site has fewer than 5 global classes, discovery returns `bootstrap_recommendation` with 13 starter class definitions from the new `StarterClassesService` (grid-2/3/4, eyebrow, tagline, hero-description, btn-primary, btn-outline, card, card-dark, card-glass, tag-pill, tag-grid).
- **`verify_build` rich output** — now returns human-readable section descriptions via `describe_page()` ("Dark section with background image and overlay. Contains h1, text, 2 buttons") alongside type counts and classes used.
- New `StarterClassesService` with 13 curated starter classes using CSS variables for portability.

## [3.3.2] — 2026-04-07

### Fixed
- Pattern column gap and padding now applied from pattern definitions. `extract_column_overrides()` reads gap, padding, alignment, max_width, fill.
- All split and hero patterns updated with `gap: var(--space-l)`.
- Default `_rowGap` on content columns when no pattern matched.

## [3.3.1] — 2026-04-07

### Changed
- New `FormTypeDetector` utility — extracted duplicate form type detection regex from 3 files into a single static class.
- New option key constants in `BricksCore` — replaced 58 hardcoded option keys across services.
- `OnboardingService` briefs caching — 3 `get_option` calls per session collapsed to 1.

### Added
- Content keys for video, pricing-tables, testimonials, social-icons, progress-bar, rating, pie-chart.

## [3.3.0] — 2026-04-06

### Added
- Static cache in `GlobalClassService` (11 class fetches per 3-section build → 1 DB read).
- `ProposalService` reuses `SiteVariableResolver`'s cache for variables.
- Multi-row pattern support: `build_multi_row_layout()` handles `has_two_rows` patterns with separate `row_1` (split) and `row_2` (grid) blocks.
- Form styling defaults: auto-detect form type (newsletter/login/contact) and apply template with proper fields.
- Discovery response hash caching: second+ calls return slim response (~3KB vs ~16KB).

## [3.2.1] — 2026-04-05

### Added
- New design pattern: `hero-split-form-badges` (from Brixies) — 3:2 split with newsletter form + 4-column trust badge grid below.

## [3.2.0] — 2026-04-04

### Added
- **Design Pattern Library** — 17 curated compositions across 6 categories (heroes, splits, features, CTAs, pricing, testimonials, content).
- `DesignPatternService` loads patterns from `data/design-patterns/` with tag-based matching.
- Discovery returns `reference_patterns` (2-3 matches) for AI to adapt.
- `SchemaSkeletonGenerator` uses pattern column overrides for layout intelligence.

## [3.1.3] — 2026-04-03

### Fixed
- Background merge: `apply_background()` merges with existing `_background` instead of replacing (preserves images through dark mode).

### Added
- `background_image` field in design_plan with `"unsplash:query"` support.
- Auto-wrap consecutive buttons in row block.

## [3.1.2] — 2026-04-03

### Fixed
- Stale `ELEMENT_PURPOSES` reference (renamed to `ELEMENT_CAPABILITIES`).

## [3.1.1] — 2026-04-02

### Added
- Element capabilities with purpose + capabilities + rules for 20 elements.
- Building rules from `BUILDER_GUIDE.md` served in discovery.
- Dynamic element schemas in proposal phase for all 80-90 Bricks elements.
- Button icon pipeline support, Unsplash background resolution, invalid key auto-fix (`_maxWidth` → `_widthMax`, `_textAlign` → `_typography.text-align`).
- Gap auto-conversion: `_gap` on flex blocks → `_columnGap`/`_rowGap` based on direction.

## [3.1.0] — 2026-04-01

### Added
- **Design-first pipeline with two-phase `propose_design`**. Phase 1 discovery returns site context and element capabilities, no `proposal_id`. Phase 2 with `design_plan` returns `proposal_id` and `suggested_schema`.
- New prerequisite gates: `design_discovery` and `design_plan` flags. `build_from_schema` requires 5 flags.
- New `verify_build` tool with element counts, type counts, classes used, hierarchy summary.

### Changed
- Strict schema validation: unknown keys rejected with suggestions.

## [3.0.x] — 2026-03

### Added
- MCP onboarding system for automatic AI assistant orientation on new sessions (`get_onboarding_guide`).
- Static caching in `get_site_context()`.
- `summary` and `next_step` fields in `propose_design` output.

### Fixed
- `OnboardingHandler` registration when Bricks Builder is not active.
- `build_from_schema` proposal consumption — proposals now consumed only after validation passes.

## [2.10.0] — 2026-02

### Added
- MetaBox integration: list field groups, get fields, read field values, dynamic tags.
- WooCommerce builder tools: status checks, WC-specific elements and dynamic tags, template scaffolding for all WC pages.
- Template import from URL and JSON data.
- Page import from Bricks clipboard format.

## Earlier history

See `readme.txt` for the full historical changelog (2.0.0–2.9.0 and 1.x). Key milestones:

- **2.9.0** — On-demand knowledge fragments via `bricks:get_knowledge` (8 domain guides). Replaced monolithic builder guide.
- **2.6.0** — Auto-snapshot before all content write operations.
- **2.3.0** — Design build gate + tiered prerequisite gating.
- **2.1.0** — `build_from_schema` tool — declarative design pipeline.
- **2.0.0** — Major architecture rewrite. Router reduced from ~3900 to ~1050 lines. Streamable HTTP transport.
- **1.0.0** — Initial release.
