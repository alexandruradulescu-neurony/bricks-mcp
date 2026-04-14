# Changelog

All notable changes to the Bricks MCP plugin are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project follows [Semantic Versioning](https://semver.org/) where practical.

For the WordPress.org plugin update system, see also `readme.txt` (same content, WP format).

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
