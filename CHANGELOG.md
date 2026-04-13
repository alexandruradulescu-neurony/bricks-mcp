# Changelog

All notable changes to the Bricks MCP plugin are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project follows [Semantic Versioning](https://semver.org/) where practical.

For the WordPress.org plugin update system, see also `readme.txt` (same content, WP format).

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
