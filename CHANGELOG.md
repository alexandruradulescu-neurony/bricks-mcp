# Changelog

All notable changes to the Bricks MCP plugin are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project follows [Semantic Versioning](https://semver.org/) where practical.

For the WordPress.org plugin update system, see also `readme.txt` (same content, WP format).

## [3.28.5] — 2026-04-22

**Hotfix — class_intent normalization before delegation**
* Fix: build_structure now normalizes structured class_intent objects ({block, modifier?, element?}) and loose strings into BEM class names before passing to downstream validator. Previously threw "Illegal offset type" because DesignSchemaValidator expected scalar strings.

## [3.28.4] — 2026-04-22

**Hotfix — build_structure scanner false positive on routing fields**
* Fix: removed `target` from forbidden list (link target attr is structural, not content).
* Fix: scanner now skips `target`, `design_context`, `intent` at top level of schema (schema-routing metadata, not element content).

## [3.28.3] — 2026-04-22

**Hotfix — build_structure validator**
* Fix: removed label, title, description from forbidden content fields. These are Bricks structural metadata (element organization labels, tooltip attrs), not user-facing content. Kept as forbidden: content, content_example, text, link, href, target, icon, image, src, url, placeholder.

## [3.28.2] — 2026-04-22

**Hotfix — design_plan validator**
* Fix: content_hint is now optional at element level (was still rejecting Phase 2 calls without it). Hints still extracted into content_plan map when supplied.
* Fix: next_step text in Phase 2 response pointed to deprecated build_from_schema with [PLACEHOLDER] guidance. Now points to build_structure + populate_content with role-keyed content_map.
* design_plan_format descriptor documents content_hint as optional + content_plan as the v3.28.0 replacement map.

## [3.28.1] — 2026-04-22

**Critical hotfix for v3.28.0**
* Fix: fatal error on plugin load caused by PHP array literal evaluation — `$this->handlers['build']` was null when referenced during array construction. Now captured via local variable and passed to BuildStructureHandler explicitly.

## [3.28.0] — 2026-04-22

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

## [3.27.2] — 2026-04-22

**Bug fixes for pattern catalog**
* Fix: discovery catalog now shows populated structural_summary + class_refs_preview (was empty because catalog used pattern summaries instead of full structures).
* Fix: admin Patterns table Structure column now renders the actual tree.
* Fix: capture action now accepts and infers layout + background fields from structure.
* Added: DesignPatternService::get_all_full() public API for consumers that need full pattern trees.

## [3.27.1] — 2026-04-22

**Bug fixes for v3.27.0**
* Fix: legacy patterns now wipe on zip-upload upgrade (moved from activation hook to version-bump migration).
* Fix: added "Delete Selected" bulk delete button to Patterns admin.
* Fix: pattern detail view now renders as proper modal overlay (was rendering inline at page bottom).

## [3.27.0] — 2026-04-22

### Pattern System v2 — Complete Redesign

Patterns are now site-specific design memory, not a shipped template gallery.
The plugin ships with ZERO patterns; you capture them from real built sections
on your site via MCP. Each pattern is stripped of content, tokenized to your
site's design system (colors, spacing, radii, fonts), and fully self-contained
(carries its own class + variable payloads).

#### Breaking Changes

- Legacy `bricks_mcp_custom_patterns` option is wiped on upgrade. One-time admin notice appears.
- Admin "Add Pattern" modal removed. Pattern creation is MCP-only via `design_pattern` tool.
- `reference_patterns` field replaced with `pattern_catalog` in propose_design Phase 1 response.
- `find_matching_pattern` in SchemaSkeletonGenerator removed. Use `use_pattern` in design_plan instead.

#### Added

- `design_pattern(action: 'capture')` — captures pattern from an existing built section.
- `design_pattern(action: 'import')` — auto-creates missing classes + variables on target site.
- `use_pattern` in design_plan + `content_map` for pattern-based builds.
- Adaptive build: patterns adapt to content (repeat cloning, role insertion, shape-mismatch rejection).
- `adaptation_log` in build responses shows every structural change made.
- Admin UI: read-only structural_summary column + detail panel (view classes, variables, full JSON).

#### Under the hood

- New services: PatternValidator, PatternCapture, PatternCatalog, PatternAdapter.
- 32 new unit tests covering validation, token snapping (CIELAB ΔE for colors, pixel delta for spacing/radius/fonts), repeat expansion, role insertion, required-role checks.

#### Removed

- `data/design-patterns.json` seed file (21 generic patterns). No more shipped patterns.
- Admin reseed button + AJAX endpoint.
- DesignPatternService::migrate_plugin_patterns / reseed_plugin_patterns / load_and_merge_seed.
- SchemaSkeletonGenerator::find_matching_pattern / PLACEHOLDER_ICONS / get_placeholder_icon.

### Risk

HIGH — breaking changes to pattern storage, admin UI, and design pipeline fields.

## [3.26.1] — 2026-04-21

### Phase 13: continuation sweep after v3.26.0

54 additional commits from 2 parallel agents (Core+Admin, Handlers+Services). All PHP files pass `php -l`. No semantics changes.

#### Core + Admin layer (34 commits, 21 files)

- **BricksCore** — 4 new constants: `ADMIN_NONCE_ACTION`, `NOTES_NONCE_ACTION`, `SETTING_ENABLED`, `SETTING_REQUIRE_AUTH`. Consumed by Settings, Server, Activator, PatternsAdmin, DiagnosticsAdmin (retiring ~15 duplicated nonce/setting-key literals).
- **Admin Checks × 10** — correct `run()` return-shape docblocks (`array{id, label, status, message, fix_steps, category}` instead of generic `array<string, mixed>`). `DesignPipelineCheck` gets full interface-method docblocks.
- **DesignSystemAdmin** — `PANEL_METHODS` constant (DRY between `render()` and `ajax_render_panel()`) + `NONCE_ACTION` constant.
- **Settings** — `CONNECTION_STATUS_TRANSIENT`, `REACHABLE_HTTP_CODES`, `BRIEFS_NONCE_ACTION`, `BRIEFS_NONCE_FIELD` constants. `ajax_generate_app_password` guards against `WP_User(0)` before calling `WP_Application_Passwords::create_new_application_password`.
- **Activator** — `ACTIVATION_CHECK_TRANSIENT` promoted `public` so Settings can reference it; consumer updated.
- **uninstall.php** — `flush_rewrite_rules()` rationale documented; per-option sync comments noting `OPTION_*` constants on BricksCore.
- **Autoloader** — documents `unregister()` / `get_class_map()` as tooling-only API (prevents dead-code removal).
- **UpdateChecker** — `phpcs:ignore` on unused `$upgrader` param in `verify_download`.

#### Handlers + Services layer (20 commits)

- **WordPressHandler::MAX_POSTS_PER_PAGE** — `100` cap magic number as constant.
- **OnboardingService::BRIEF_SUMMARY_WORD_LIMIT** — 50-word trim threshold.
- **ComponentHandler::ID_COLLISION_MAX_RETRIES** — 50-retry cap with probability-math docblock preserved.
- **PageSeoSubHandler::SEO_FIELD_NAMES** — 12-entry SEO field accept-list deduplication.
- **BuildHandler::KNOWLEDGE_NUDGE_MAP** + **EXTRACTABLE_STYLE_KEYS** — the 7-entry element→domain map and 7-key extractable style set (distinct from ElementHandler::STYLE_KEYS).
- **WooCommerceHandler::WC_TEMPLATE_TYPES** + **WC_INTEGRATION_SETTING_KEYS** — single source of truth for the 8 WC template types and 12 integration settings (previously duplicated across scaffold handlers).
- **SchemaSkeletonGenerator::PLACEHOLDER_ICONS** — 10-entry rotating icon list.
- **BuildHandler** — guards `count($existing)` against WP_Error in replace-confirm path; defensively coalesces `$group['refs'] ?? []` in shared-styles collector.
- **BricksCore** CSS sanitizer — collapsed 6-call `preg_replace` chain into documented two-phase array-mode sanitation.

#### i18n

Translator comments + numbered placeholders added across:
MenuHandler (confirm-delete), ComponentHandler (4 sprintf sites: create, update, delete, instantiate), DesignSystemHandler (6 confirm-delete sites), TemplateHandler (tag/bundle delete), WooCommerceHandler (scaffold_save_failed), DesignSystemAdmin (color-family sprintfs).

#### Style

- `ElementHandler::STYLE_KEYS` moved to top of class (grouped with other constants).
- `BuildHandler` drops redundant `\BricksMCP\MCP\Handlers\BricksToolHandler` FQN (uses unqualified reference — already in-namespace).

### Risk

LOW — defensive + refactor. Zero behavior change on valid input.

## [3.26.0] — 2026-04-21

### Phase 12: Consolidated MEDIUM/LOW/NIT sweep across all 4 layers

57 commits from parallel agent sweep (Core, Admin, Handlers, Services). All PHP files pass `php -l`. Zero behavior change on valid input.

#### Defensive guards

`is_array` / `is_iterable` added across:
- `MetaBoxHandler` — registry iteration, field loops, tag renderer
- `ComponentHandler` — list paths, foreach inside array_filter
- `VerifyHandler` — sections filter, content-sample extraction
- `PageReadSubHandler` — BFS in `collect_subtree`
- `TemplateHandler` — settings meta before `format_conditions`
- `Router` — prerequisite transient reads, input guards
- `StreamableHttpHandler` — keepalive + body-size bounds
- `Server` — `is_string` guard on parsed path, idempotent `init()`
- `ProposalService` — brief reads + `preg_split` fallback
- `DesignPatternHandler` — array_filter lambdas
- `Updates\UpdateChecker` — asset-loop guards, version guard
- `BricksCore::resolve_elements_meta_key` — uses new header/footer helpers

#### Constants extracted (~20 sites)

`ProposalService::TRANSIENT_PREFIX`; `Plugin::LEGACY_SETTING_KEYS`;
`ElementSettingsGenerator::NON_STRUCTURAL_ELEMENT_TYPES`, `CSS_WARN_EXCERPT_LEN`;
`SchemaGenerator::MAX_CONTROLS_PER_ELEMENT`, `SIMILAR_NAME_THRESHOLD`;
`WordPressHandler::GENERATED_PASSWORD_LENGTH`;
`Activator` activation-check TTL + transient key;
`PageOperationsService::MAX_TREE_DEPTH` + snapshot constants;
`GlobalClassService::BP_DIFF_TOLERANCE`;
`TemplateService::MAX_IMPORT_BODY_BYTES`;
`MediaService::UNSPLASH_META_KEY`, `MAX_DOWNLOAD_BYTES`;
`RateLimiter::SETTING_KEY_RPM`;
`VerifyHandler::CONTENT_SAMPLE_*_LIMIT` (3);
`DesignSchemaValidator::GRID_RESPONSIVE_COL_THRESHOLD`;
`DesignSystemAdmin::DEFAULT_BLACK`, `DEFAULT_WHITE`, `DEFAULT_PX_FALLBACK`, `GAP_PREVIEW_MIN/MAX`;
`Settings::RATE_LIMIT_RPM_*`, `CONNECTION_PROBE_TIMEOUT`;
Admin Checks `HTTP_TIMEOUT_SECONDS`.

#### i18n hardening

Translator comments + numbered placeholders added across 11 files:
`ElementHandler`, `TemplateHandler`, `BuildHandler`, `MediaHandler`,
`PageCrudSubHandler`, `PageContentSubHandler`, `PageReadSubHandler`,
`BricksToolHandler`, `DesignSystemHandler`, `GlobalClassHandler`,
`Admin/Checks/DesignPipelineCheck`. `DiagnosticRunner` summary strings
now wrap in `__()` + `_n()` so translators can reach them.

#### Correctness fixes

- `uninstall.php` uses `$wpdb->usermeta` for multisite correctness (previously could miss sites on subdomain setups)
- Deterministic border-style collapse in `normalize_bricks_styles` (was insertion-order-dependent via `reset()`)
- `Plugin` + `Activator` throw on clone
- `OPTION_SETTINGS` writes use explicit `autoload=true` — two writers previously disagreed, flipping the option out of autoload cache and hammering DB
- `Server::init` is idempotent — no stacked filter/action registrations on re-entry
- `StreamableHttpHandler` body-size bounds: `MAX_BODY_FLOOR = 1024`, `MAX_BODY_HARD_LIMIT = 10MB` — filter can't DoS with a tiny cap or exceed the hard ceiling
- `StreamableHttpHandler` keepalive bounds `KEEPALIVE_MIN/MAX_SECONDS = 5/55` named
- `Response::add_server_header` DRYs the `X-MCP-Server` header
- `ElementIdGenerator::id_regex()` centralizer — other services share one regex
- `SchemaGenerator` logs malformed registry context instead of silent empty fallback
- `ConditionSchemaCatalog` uses `sprintf` for backslash-escaped quotes in description (readability)
- `Reference\*Catalog::FILTER_HOOK` constants — each catalog exposes its filter name

#### Safety + flow control

- `DiagnosticsAdmin` + 5 admin AJAX handlers add missing `return` after `wp_send_json_error` (filtered-`wp_die` defense)
- `DiagnosticRunner::run()` wraps `$check->run()` in `try/catch ( \Throwable )` — third-party broken check can no longer silently swallow its own exception
- `DesignSystemHandler` sanitizes `style_id` at handler boundary (was relied on downstream)
- `GlobalClassHandler` `esc_html` on user-controlled error-message interpolation
- `DesignPatternHandler` array_filter lambdas guard `is_array($p)` (stdClass from third-party filters)
- `ProposalService` hardens brief reads + `preg_split` `false`-return fallback (PHP 8.2 warning on foreach(false))
- `TemplateHandler` guards template settings meta as array before `format_conditions`
- `PageReadSubHandler` guards `children` array during BFS in `collect_subtree`
- `MediaHandler` dedupes duplicate `wp_get_attachment_url` call in set_featured response
- `Settings` note ID entropy bumped from `random_bytes(4)` (8 hex chars, 32 bits) to `random_bytes(8)` (16 hex chars, 64 bits)
- `BricksToolHandler` translator comment on `knowledge_not_found`
- `uninstall.php` uses correct $wpdb table reference

#### Documentation fixes

- `OnboardingHandler` return type annotations corrected to match actual shape
- `BuildHandler` orphan register docblock removed, `@var` added for `$proposal_service`
- `NotesService` uses `BricksCore::OPTION_NOTES` instead of literal
- `WooCommerceHandler` whitespace normalized around action dispatch
- `SchemaSkeletonGenerator` dead legacy-generation comment block removed
- Admin Checks `sslverify => false` now commented (loopback probe intentional)

### Risk

LOW — defensive additions + refactor. Zero behavior change on valid input.

### Files touched

40+ files across all 4 layers. See 57 individual commits from `5c8efe5..HEAD` for per-file breakdown.

## [3.25.8] — 2026-04-21

### Phase 11 of repair roadmap: final MEDIUM/LOW cleanup

- App password generator client-name now filterable: `bricks_mcp_app_password_client_name`. Default unchanged ("Bricks MCP - Claude Code"). Sites using multiple MCP clients can override to distinguish sessions in WP admin.
- `ajax_generate_app_password` gets missing `return` after `wp_send_json_error`.

## [3.25.7] — 2026-04-21

### Phase 10 of repair roadmap: MEDIUM sweep part 2

#### i18n: SEO length thresholds

SEO audit previously hardcoded Google's English-optimized character bounds (title 30–60, description 120–160). Non-English content where character-to-pixel-width ratio differs (Romanian, CJK, Cyrillic) false-flagged:

- Constants added: `SeoService::TITLE_MIN_CHARS`, `TITLE_MAX_CHARS`, `DESCRIPTION_MIN_CHARS`, `DESCRIPTION_MAX_CHARS`.
- New filter `bricks_mcp_seo_length_bounds` lets sites override: `add_filter( 'bricks_mcp_seo_length_bounds', fn() => [ 'title_min' => 25, 'title_max' => 55, ... ] );`
- Defensive: filter may return malformed data; each bound falls back to the constant when missing.

#### SchemaSkeletonGenerator featured-card logic

Pricing section `middle_idx = floor(pat_repeat / 2)` produced:

- `repeat=1` → index 0 → solo tier marked "featured" (meaningless).
- `repeat=2` → index 1 → second card featured (arbitrary).
- `repeat=3+` → middle (correct).

Fix:

- `repeat=1` → no featured card (sentinel `-1`; second pattern_ref entry is skipped).
- `repeat=2` → second card featured (upgrade tier convention).
- `repeat=3+` → middle card featured (unchanged).

### Risk

LOW — behavior change on solo-pricing case is strictly better (no longer ships a featured-marker for single-tier pricing).

## [3.25.6] — 2026-04-21

### Phase 9 of repair roadmap: MEDIUM sweep part 1

#### Data integrity

- **`BricksService::remove_element(cascade)` stale child-ID scrub.** Before: cascade removed the target + all descendants but surviving elements' `children` arrays could still contain gone IDs. On save, the linkage validator rejected the result with "element X lists child Y which doesn't exist". Fix: after building `$remove_set`, the survivors loop also prunes each element's children array of any ID in the remove set.
- **`Plugin::migrate_settings` update_option return check.** `update_option` can return false when the DB rejects the write (filter, transaction conflict). Previously silent — migration appeared successful, `db_version` bumped, retry never happened. Now throws RuntimeException; the outer try/catch in `init()` leaves version un-bumped so migration retries on next load.

#### Magic-number extraction

- `Admin/Settings`:
  - `RATE_LIMIT_RPM_MIN = 10`, `RATE_LIMIT_RPM_MAX = 1000`, `RATE_LIMIT_RPM_DEFAULT = 120` — replaces 3 sites with inline literals.
  - `CONNECTION_PROBE_TIMEOUT = 3` — documented intent for settings-page probe.
- `Admin/Checks/McpEndpointCheck::HTTP_TIMEOUT_SECONDS = 5`
- `Admin/Checks/RestApiReachableCheck::HTTP_TIMEOUT_SECONDS = 5`

### Risk

LOW — defensive + refactor.

## [3.25.5] — 2026-04-21

### Phase 8 of repair roadmap: Core + Admin HIGH items

#### Correctness

- **SSE loop cap.** `StreamableHttpHandler::handle_get` was `while ( true )` with no iteration bound. 10 clients holding connections open → 10 wedged PHP-FPM workers. Now capped at 360 iterations (filterable via `bricks_mcp_sse_max_iterations`).
- **Proxy-aware IP resolution.** `Server::resolve_client_ip()` consults `X-Forwarded-For`, `CF-Connecting-IP`, `X-Real-IP` — but only when `bricks_mcp_trust_proxy` filter returns true (opt-in). Previously, traffic behind Cloudflare/nginx shared a single rate-limit bucket.
- **Tool-name type check.** `StreamableHttpHandler::handle_tools_call` now verifies `$name` is a non-empty string before calling `Router::execute_tool(string $name)`. Non-string values previously crashed with `TypeError` (fatal 500).
- **Reference catalog filter guards.** All 4 catalogs (Condition, Filter, Form, Interaction) now validate `apply_filters()` return before returning. Third-party filter misuse no longer propagates non-arrays downstream.
- **InteractionSchemaCatalog misplaced example.** `image_gallery_load_more` was structurally a sibling of `notes` at the top level of `$data` instead of a child of `examples`. Moved into `examples`.

#### Version gate

- `BRICKS_MCP_MIN_BRICKS_VERSION` bumped from `1.6` to `1.12`. 1.12 introduced the element-tree APIs and meta-filter surface this plugin depends on. Users on 1.6–1.11 previously passed the gate but failed at first write with obscure errors — 1.12 is the true floor.

#### Option-name extraction

10 more option names consolidated into `BricksCore::OPTION_*` constants, applied across:

- `DesignPatternService` — `OPTION_CUSTOM_PATTERNS`, `OPTION_PATTERNS_MIGRATED`
- `BriefResolver` — `OPTION_STRUCTURED_BRIEF`
- `TemplateHandler` — `OPTION_TERM_TRASH` (2 sites)
- `Plugin` — `OPTION_DB_VERSION` (2 sites)
- `DesignSystemAdmin` — `OPTION_DESIGN_SYSTEM_CONFIG`, `OPTION_DS_LAST_APPLIED`, `OPTION_STRUCTURED_BRIEF`
- `Settings` — `OPTION_STRUCTURED_BRIEF` (4 sites including nonce action)

### Risk

LOW–MEDIUM. The Bricks version bump may block installs on pre-1.12 Bricks; affected users will see the standard activation gate, not a broken plugin.

## [3.25.4] — 2026-04-21

### Phase 7B of repair roadmap: remaining Services HIGH items

#### GlobalClassService hardening

- **Case-insensitive duplicate-name check.** Previously "heroButton" and "HeroButton" both succeeded, producing two classes with effectively-identical CSS identifiers. Now rejects on normalized-lowercase match with clearer error message.
- **Bounded ID-collision retry.** The previous `do { generate_id } while ( in_array )` had no retry cap — a mock generator or system-entropy failure could spin forever. Now capped at 100 attempts with explicit `id_generation_failed` error.

#### ElementSettingsGenerator fixes

- **Dark-color detection tightened.** The previous regex `(?:^|-)(?:ultra-)?dark(?:-|$|\))` produced false positives on tokens like `--dark-blue-500`, `--not-so-dark-bg`, and `--mediumdark-surface`. Replaced with `is_dark_color_token()` helper that matches only:
  - Exact Bricks variable tokens: `var(--base-dark)`, `var(--primary-ultra-dark)`, etc.
  - Exact raw tokens: `base-dark`, `accent-ultra-dark`, etc.
  - CSS `black` keyword
  - Near-black hex values (0x00–0x33 per channel)
- **Overlay chain guarded.** `$settings['_background']['overlay']` can be scalar ("black"), array-without-color, or array-with-non-array-color. Previous code short-circuited silently leaving no gradient. Now handles all three shapes correctly.
- **`DEFAULT_GRID_COLUMNS = 3`** — extracted inline `?? 3` literal.

#### ProposalService

- `create()` now surfaces a separate `page_trashed` error instead of generic `invalid_page` when the post is in the trash. AI clients can suggest a restore flow to the user.

#### BricksService

- `compute_diff()` — settings-diff path now `is_array()`-checks `$old_settings` and `$new_settings` before passing to `array_diff_key` / `array_filter`. Non-array settings previously crashed the diff computation.

### Risk

LOW — defensive guards + helper extractions.

## [3.25.3] — 2026-04-21

### Phase 7 of repair roadmap: remaining HIGH items (continued)

#### Correctness fixes

- **Meta-key resolver bypass (3 handlers).** `PageCrudSubHandler::delete`, `PageReadSubHandler::format_post_for_list`, and `PageContentSubHandler::update_content` used `BricksService::META_KEY` directly, which is the page-content meta key. For `bricks_template` posts (headers, footers, popups, archives, etc.), the element count and reduction checks read from the wrong key. Element counts in list views always showed 0 for templates. Write-safety threshold used wrong "current" count. **Fix:** added `BricksService::resolve_elements_meta_key( int $post_id ): string` as a public passthrough to `BricksCore::resolve_elements_meta_key`, and updated all three handlers to use it.
- **PageContentSubHandler element reduction threshold.** Previously `$new_count < (int) ( $old_count * 0.5 )` — on odd `$old_count`, rounding produced wrong results. Example: `old=5, new=2` gives `2 < (int)(2.5) = 2 < 2 = false`, so a 60% reduction bypassed the confirm prompt. **Fix:** `$new_count * 2 < $old_count` — integer math, correct for all inputs.

#### MetaBoxHandler hardening

- `DYNAMIC_TAG_PREFIX = 'mb_'` constant + `mb_tag( string $field_id ): string` helper. Replaced 6 sites that constructed `'{mb_' . $fid . '}'` inline.
- `is_array()` guards added on `$meta_box->fields` iteration. MetaBox can deliver field definitions as stdClass in edge cases (custom hooks, third-party extensions), which previously crashed subscript access.

#### ElementHandler constants extraction

- `KNOWN_CONDITION_KEYS` — the 31 known Bricks condition keys (post, user, date, request, WooCommerce) now live as a named class constant with source link and category grouping.
- `VALID_CONDITION_COMPARE` — 10 compare operators (`==`, `!=`, `contains`, etc.) as named constant.

#### BricksToolHandler + SchemaHandler + MediaHandler + ComponentHandler refactor

- `BricksToolHandler::KNOWLEDGE_FETCH_TTL = 2 * HOUR_IN_SECONDS` — replaces inline literal.
- `SchemaHandler::MAX_ELEMENT_SCHEMAS_PER_BATCH = 20` — replaces inline `> 20` check. Error message now interpolates constant.
- `SchemaHandler::tool_get_dynamic_tags` — queryEditor/useQueryEditor stripping uses `in_array()` exact match, not `stripos()` substring. Previously blocked legitimate third-party tags containing those substrings. Also adds `is_array( $tag )` guard.
- `MediaHandler::SMART_SEARCH_DEFAULT_PER_PAGE = 5`, `SMART_SEARCH_MAX_PER_PAGE = 30`, `MEDIA_LIBRARY_DEFAULT_PER_PAGE = 20` — replaces 3 magic numbers.
- `ComponentHandler` ID-collision retry count (50) now annotated with probability math explaining the value.

#### TemplateHandler

- `> 50 / array_slice(-50)` trash cap → `BricksCore::BATCH_SIZE`. Single source of truth.

### Risk

LOW–MEDIUM. Meta-key resolver fix changes behavior for template post types (element counts will now be correct). No change for page posts.

## [3.25.2] — 2026-04-21

### Phase 6 of repair roadmap: remaining HIGH items

#### More BricksCore constants

- `OPTION_COMPONENTS = 'bricks_components'` — replaces `ComponentHandler::COMPONENTS_OPTION` hardcoded literal.
- `OPTION_GLOBAL_QUERIES = 'bricks_global_queries'` — replaces 5 literal occurrences in `BricksToolHandler`.
- `META_TEMPLATE_TYPE = '_bricks_template_type'` — replaces literals in:
  - `TemplateHandler` (2 sites)
  - `WooCommerceHandler` (3 sites: conditions meta queries)
  - `SettingsService` (3 sites)
  - `TemplateService` (5 sites)
- `META_TEMPLATE_SETTINGS = '_bricks_template_settings'` — replaces literals in:
  - `TemplateHandler` (1 site)
  - `SettingsService` (3 sites)
  - `TemplateService` (8 sites)

Total: ~20 literal → constant replacements across 6 files. Centralizes Bricks core schema identifiers against drift.

#### Noise reduction

- `BuildHandler` unconditional `error_log()` on every build now gated on `WP_DEBUG`. Previously bloated production error logs.

#### Input flexibility

- `MediaHandler::tool_get_media_library` — `per_page` and `page` now accept integer-strings. HTTP-transport-decoded JSON sometimes delivers `"20"` instead of `20`; previous strict `is_int()` check rejected all integer-string inputs and silently defaulted to 20/1.

### Risk

LOW — pure refactor + permissive input parsing. No behavior change on valid input.

## [3.25.1] — 2026-04-21

### Phase 5 of repair roadmap: admin + migration hardening

Most Phase 5 items already shipped in Phase 3 (migration try/catch). This release covers the remaining admin-layer findings.

#### Security: pattern ingest sanitization

- `PatternsAdmin::ajax_create_pattern` previously accepted raw JSON and handed it to `DesignPatternService::create()` with no handler-level key-level sanitization, relying entirely on downstream escaping discipline.
- **Fix:** boundary-sanitize `id` (sanitize_key), `name` (sanitize_text_field), `description` (wp_kses_post), `category` (sanitize_text_field), and `tags` (per-element sanitize_text_field). Stored XSS in any pattern-render path is now a depth-2 bug requiring both ingest AND escape failures.

#### Flow-control: missing returns after wp_send_json_*

- `DesignSystemAdmin::ajax_render_panel` and `PatternsAdmin::ajax_create_pattern/ajax_delete_pattern` previously relied on `wp_send_json_error` calling `wp_die` internally to halt. In test/headless environments where `wp_die` is filtered to not exit, execution fell through into the dynamic method call (`$this->{null}($config)`).
- **Fix:** explicit `return` after every `wp_send_json_*` call.

#### Cryptographic: proposal ID collision space

- `ProposalService` generated proposal IDs via `substr(md5(time() . wp_generate_password(8)), 0, 12)` — 48 bits of entropy. Concurrent requests within the same microsecond could collide; second proposal overwrites first in the transient store.
- **Fix:** switched to `wp_generate_uuid4()` — 122 bits. Collision odds now cryptographically negligible.

#### Data integrity: JSON file reads

- `SchemaGenerator::get_settings_keys` and `ElementSettingsGenerator::get_element_registry` only checked `file_exists` before `file_get_contents` + `json_decode`. Missing `properties`/`elements` keys produced silent empty registries (hard to diagnose) or `undefined index` warnings.
- **Fix:** layered integrity check — file_exists + is_readable + non-empty content + json_decode succeeds + expected shape present. Any failure logs via `error_log` with a diagnostic message.

### Risk

LOW — defensive additions, no behavior change on valid input.

## [3.25.0] — 2026-04-21

### Phase 4 of repair roadmap: magic strings/numbers extraction

Pure refactor release. Zero behavior change on valid input. Centralizes duplicated literals against the drift class that surfaced in v3.24.2 (data-inconsistency between two files that carried the same "50" constant).

#### New BricksCore constants

- `BATCH_SIZE = 50` — single source for bulk-operation caps. Replaced `count > 50` literals in `BricksService::bulk_update_elements` and `GlobalVariableService::batch_delete_global_variables`. Error messages now interpolate the constant value.
- `META_KEY_HEADER_FALLBACK = '_bricks_page_header_2'` — fallback when Bricks hasn't defined `BRICKS_DB_PAGE_HEADER`.
- `META_KEY_FOOTER_FALLBACK = '_bricks_page_footer_2'` — same for footer.

#### New BricksCore helpers

- `data_path( string $filename ): string` — replaces `dirname( __DIR__, 3 ) . '/data/...'` duplicated across 5 sites:
  - `SchemaGenerator::get_settings_keys` — settings-keys.json
  - `ElementSettingsGenerator::get_element_registry` — elements.json
  - `DesignPatternService::load_and_merge_seed` — design-patterns.json
  - `ProposalService::load_building_rules` — building-rules.json
  - `ProposalService::get_building_rules` — building-rules.json (second instance)
- `header_meta_key(): string` — wraps the Bricks `BRICKS_DB_PAGE_HEADER` ternary. Replaces 2 sites in `BricksCore::unhook_bricks_meta_filters` + 3 sites in `GlobalClassService::find_class_references`.
- `footer_meta_key(): string` — same for footer.

#### Domain-local constants

- `ElementSettingsGenerator::TEXT_ELEMENT_TYPES` — `[ 'heading', 'text-basic', 'text', 'text-link' ]`. Previously duplicated in 2 sites (one named `$text_types`, one `$dark_text_types`) within the same method.
- `SchemaExpander::MAX_REPEAT` — promoted from `private` to `public`. `DesignSchemaValidator` now imports it via `SchemaExpander::MAX_REPEAT` instead of using a separate `> 50` literal. Change one, both agree.

#### Capability literal replacement

- `'manage_options'` in `Admin/Settings.php:98` and `Router.php:593` replaced with `BricksCore::REQUIRED_CAPABILITY`. Capability rename now requires touching one declaration instead of three literals.

### Risk

LOW — pure refactor, no logic changes. PHP syntax verified on all 12 touched files.

### Files touched

- `MCP/Services/BricksCore.php` — constants added, 2 ternary replacements in `unhook_bricks_meta_filters`
- `MCP/Services/BricksService.php` — BATCH_SIZE replacement + i18n fix in `bulk_update_elements`
- `MCP/Services/ElementSettingsGenerator.php` — TEXT_ELEMENT_TYPES constant + 2 deduplications + data_path() call
- `MCP/Services/SchemaExpander.php` — MAX_REPEAT promoted public
- `MCP/Services/DesignSchemaValidator.php` — imports SchemaExpander::MAX_REPEAT
- `MCP/Services/SchemaGenerator.php` — data_path() call
- `MCP/Services/DesignPatternService.php` — data_path() call
- `MCP/Services/ProposalService.php` — 2 data_path() calls
- `MCP/Services/GlobalVariableService.php` — BATCH_SIZE replacement + i18n fix
- `MCP/Services/GlobalClassService.php` — 3 header/footer ternary replacements
- `MCP/Router.php` — manage_options replacement
- `Admin/Settings.php` — manage_options replacement

## [3.24.5] — 2026-04-21

### Phase 3 of repair roadmap: pipeline data integrity

Fixes seven silent-data-corruption bugs in the tree-mutation pipeline.

#### `ElementNormalizer::parent_refs_to_tree` — tree corruption via reference aliasing

Previous code used `foreach ($by_id as $id => &$el)` with `$tree[] = &$el` / `$by_id[$parent]['children'][] = &$el`. Every stored aliased reference pointed at the same PHP variable slot — subsequent iterations overwrote the target, silently duplicating the last-iterated element throughout the output tree.

**Fix:** Rewrote with value semantics. A two-pass approach builds a `parent → [child_ids]` map, then recursively assembles the tree via a closure that returns child nodes by value. No references, no aliasing hazard.

#### `BricksCore::save_elements` — delete-then-add data-loss window

When `update_post_meta` returned false (indistinguishable from "unchanged value"), the previous code fell through to `delete_post_meta` + `add_post_meta` with a backup restore. During that window, concurrent readers saw empty meta.

**Fix:** Removed the destructive fallback. On genuine mismatch (value differs), return a `save_elements_failed` error. Caller retries at the next write. Data integrity preserved; visibility window closed.

#### `BricksCore::rehook_bricks_meta_filters` — filter state loss

Previous code stashed the entire `WP_Hook` object via `$wp_filter[$key] = $filter` on rehook. Any other plugin that registered callbacks during the unhook window had its callbacks wiped when the original hook was wholesale-restored.

**Fix:** Per-callback recording at unhook time (hook name, priority, id, callback). On rehook, re-add each callback via `add_filter()` — preserves concurrent registrations from other plugins.

#### `BricksService::duplicate_element` — wrong root insert index

`array_splice( $elements, $position * ( count( $subtree_ids ) + 1 ), 0, $cloned )` assumed every root sibling has the same subtree size as the one being duplicated. On varied trees (common), the splice landed inside another subtree, corrupting hierarchy.

**Fix:** Walk root siblings in flat order, counting each toward `$position`. Insert at the flat index of the target sibling — correct for any tree shape.

#### `BricksService::move_element` — parent comparison drift

Used strict `0 === $elem['parent']` which missed root elements stored with string `'0'` (some Bricks migrations store numeric strings). Result: move-to-root inserted in the wrong slot on mixed-parent-type pages.

**Fix:** Use `BricksCore::is_root_element()` (added in v3.24.3) — normalizes `'0'`/`0`/`null`/`''` uniformly. Same comparison logic across Router, StreamableHttpHandler, and now BricksService.

#### `SchemaExpander::expand` — top-level `_expanded_multi` leak

When a section's top-level structure was a ref with `repeat > 1`, `expand_node` returned a `_expanded_multi` wrapper, but nothing unwrapped it at the section level. Downstream read the wrapper as an invalid structure and silently dropped the section.

**Fix:** `expand()` now detects top-level `_expanded_multi` and fans out into multiple sections at the same position, each carrying one expanded structure. `repeat > 1` on section-level refs now works.

#### `Plugin::maybe_migrate` — unsafe version bump

Version was written unconditionally after migrations. Uncaught `\Throwable` in a migration left the db_version bumped anyway — migration skipped forever on next load, plugin in permanent half-migrated state.

**Fix:** Each migration step wrapped in try/catch. Version bumped only when all steps succeed. Failures logged via `error_log` for diagnostics.

### Risk

MEDIUM — touches pipeline core. Silent-data-corruption bugs have subtle test surfaces; wider integration testing recommended.

## [3.24.4] — 2026-04-21

### Phase 2 of repair roadmap: auth + protocol correctness

#### Security: destructive action confirm bypass closed

- `Router::tool_confirm_destructive_action` previously bypassed `execute_tool()` entirely. No capability re-check. A user demoted between the original call and the confirm could still execute via an unexpired pre-demotion token.
- **Fix:** capability check added on the confirm path. Tokens now prove intent, not current authorization.
- **Secondary fix:** handler call wrapped in try/catch. Previously uncaught `\Throwable` crashed the MCP dispatcher with HTTP 500 instead of a JSON-RPC error envelope.
- **Defensive:** `$tool_args` normalized to array before use (guards against stale transient data).

#### Protocol: tool errors no longer silently look like successes

- `Response::tool_error()` sets `isError: true` in the MCP tool-result envelope (per spec).
- `StreamableHttpHandler::handle_tools_call` dispatched based on `$data['error']` (JSON-RPC envelope) — completely missed `isError`.
- Consequence: tool errors from handlers (`return new \WP_Error(...)`) surfaced to AI clients as JSON-RPC **successes** with `isError` buried inside `data.content`. HTTP status 200. AI clients inspecting the outer JSON-RPC envelope never saw the error.
- **Fix:** dispatcher now checks HTTP status >= 400 OR `$data['error']` OR `$data['isError']`. All three error-signaling paths correctly surface as JSON-RPC errors.

#### Availability: Redis hiccup no longer = 429 for everyone

- `RateLimiter::check` delegates counting to `wp_cache_incr()`, which returns `false` on Redis/Memcached backend failure.
- Previously `false` was treated as "rate limit exceeded" (same branch as "count > limit"). A Redis restart = all traffic 429'd for the entire WINDOW.
- **Fix:** explicit `false === $count` branch → fail-open, `error_log()` warning for diagnostics. Rate limiting is a best-effort safety measure, not a security boundary.

### Risk

MEDIUM — touches security-critical paths (capability check) and protocol envelope. Manual verification recommended:
1. Call a destructive tool (e.g. `page:delete`); receive confirmation token; demote user; try to confirm — should now fail.
2. Call a tool that returns `WP_Error` (e.g. `page:get` with invalid ID); AI client should receive JSON-RPC error, not success with `isError`.
3. Stop Redis mid-request; MCP endpoint should continue serving, not 429.

## [3.24.3] — 2026-04-21

### Hardening: stdClass guard sweep (Phase 1 of repair roadmap)

v3.24.1 patched one instance of the `Cannot use object of type stdClass as array` pipeline bug. A full code review across all 97 PHP files in the plugin identified 21+ more locations with the identical failure mode — any upstream source that produces stdClass instead of array (third-party `the_posts` filters, JSON-decode-without-assoc in transient-layer cache plugins, Bricks internal post-filter hooks) could trigger the same crash at any time.

This release sweeps all hot sites with defensive `is_array()` guards, centralized via new type-guard helpers in `BricksCore`.

### New helpers in BricksCore

- `BricksCore::is_element_array( mixed $value ): bool` — true only when value is an array carrying `name` (Bricks element) or `id` (tree node).
- `BricksCore::is_subscriptable( mixed $value ): bool` — loose sibling, equivalent to `is_array()` but named to document intent.
- `BricksCore::is_root_element( mixed $element ): bool` — centralized root-parent check. Normalizes `'0'`, `0`, `null`, and `''` uniformly. Previously, Router used string cast (`(string) $p === '0'`) and StreamableHttpHandler used strict integer compare (`$p !== 0`), producing divergent results on the same data.

### Files patched

- `Services/BricksCore.php` — helpers added.
- `Services/ElementSettingsGenerator.php:201-206` — chained `['_background']['color']['raw']` subscript now guarded at each level.
- `Handlers/BuildHandler.php` — 7 sites: sections iteration (169, 188), `count_elements`, `build_tree_summary`, `collect_style_fingerprints`, `extract_shared_styles_to_classes`. Also: the `$result = clear_cache(); $result = create_global_class()` clobber bug fixed (separate variables now).
- `Handlers/VerifyHandler.php` — 4 sites: main elements loop, `get_global_classes` return, `build_hierarchy_summary`, `filter_to_section`, `extract_content_sample`.
- `Handlers/ComponentHandler.php` — 4 sites: `elements[0]` root access, slot_elements iteration with existing-element conflict check, parent_id lookup. Also: the inverted `check_protected_page` null check fixed — previously ran only when no error.
- `Handlers/ElementHandler.php` — 2 sites: conditions lookup, copy_styling source/target iteration + target settings mutation.
- `Handlers/Page/PageReadSubHandler.php` — 2 sites: section-scoped index build, `collect_subtree` index build.
- `Handlers/BricksToolHandler.php` — 1 site: global queries existing-index lookup.
- `Router.php` — `tool_get_site_info` pages loop now normalizes `$pages_query->posts` defensively (supports int IDs, WP_Post objects, and mixed arrays from plugin filters). Uses `BricksCore::is_root_element()`.
- `StreamableHttpHandler.php` — parallel pattern-detection loop in `handle_post` — same defensive normalization + `is_root_element()` usage.

### Risk

LOW — additive defensive checks. No behavior change for well-formed input. The only observable difference: upstream bugs that previously triggered a 500 crash now surface as empty arrays / skipped elements. Call sites that need to know about malformed input should check their returned collections.

### Next phases

See `REPAIR-PLAN.md` for the phased roadmap (v3.24.4 auth + protocol correctness, v3.24.5 pipeline data integrity, v3.25.0 magic-string extraction, etc.).

## [3.24.2] — 2026-04-21

### Bugfix: slider-nested schema validation

- **`slider-nested` schema validation fixed.** `div` and `block` registry entries (`data/elements.json`) now list `slider-nested` as a valid parent. Previously, schema validator rejected `slider-nested → div` and `slider-nested → block` structures even though `slider-nested.typical_children` already declared them — a data inconsistency surfaced on any slider build.
- **`slider-nested.typical_children`** expanded from `["div"]` to `["div", "block"]` — matches real Bricks usage where slide panes commonly use `block` for flex-row layouts.
- **Grid columns-vs-children false-positive warning.** `DesignSchemaValidator` W1 rule no longer fires incorrectly when grid children include a pattern `ref`. Validator now counts `ref.repeat` toward the effective child count instead of treating the ref as a single child. Previously, a 3-col grid with `{ref: "card", repeat: 6}` would warn "grid has 3 columns but 1 children" despite producing 6 expanded cards.

## [3.24.1] — 2026-04-21

### Bugfix: form element crash in build_from_schema

- **`Cannot use object of type stdClass as array`** no longer occurs when building schemas that contain elements with no explicit settings (most commonly `form` without `element_settings.fields`, or any element where the generated settings happen to be empty).
- **Root cause:** `ElementSettingsGenerator::process_node()` converted empty `$settings` to `new \stdClass()` so the final JSON would serialize as `{}` instead of `[]`. But downstream pipeline code (`BuildHandler::collect_and_strip_warnings()`, `collect_style_fingerprints()`, etc.) subscripts `$el['settings']['...']` as an array. PHP 8+ throws on object subscript access for non-`ArrayAccess` types.
- **Fix:** Settings now stay as plain arrays throughout the pipeline. Empty arrays round-trip correctly through `update_post_meta`'s PHP `serialize()` — the `[]` vs `{}` JSON concern only applies at JSON-API boundaries, not DB storage.
- **Hardening:** Added defensive `is_array()` guard in `BuildHandler::collect_and_strip_warnings()` to prevent future regressions if any other code path introduces stdClass settings.

## [3.24.0] — 2026-04-17

### System hardening

- **Persistent memory:** AI notes now included in discovery responses (`ai_notes` + `ai_notes_hint`). Build and verify responses include `next_steps`/`notes_hint` prompting the AI to save design decisions via `bricks:add_note`. Server instructions document the notes workflow with examples.
- **Pattern repeat cap:** Schema validator rejects `repeat > 50` with clear error. SchemaExpander hard-caps with `min()` as defense-in-depth. Prevents memory bombs from unbounded expansion.
- **Design gate simplified:** Removed arbitrary 8-element count threshold. Gate now checks section presence only — non-section instructed builds of any element count are allowed. Cleaner, less confusing, no false rejections for legitimate bulk operations.
- **Server instructions updated:** Gate enforcement text reflects section-only check. New PERSISTENT MEMORY section explains `bricks:add_note` / `bricks:get_notes` workflow with concrete examples of what to save.
- **Tool descriptions updated:** `bypass_design_gate` parameter descriptions in PageHandler and ElementHandler reflect section-only gating.

### Element coverage — 100%

- **76/76 elements annotated** with `purpose` field (up from 26). AI discovery now describes every element type — post-*, query, navigation, media, maps, interactive, utility, deprecated.
- **73/76 with `capabilities`** (3 deprecated elements correctly excluded).
- **23 with `rules`** — do's and don'ts for elements that need guidance.

### Batch element schema fetch

- **New `elements` parameter** for `bricks:get_element_schemas` — comma-separated list, max 20. Fetch only the schemas needed instead of all 76: `bricks(action="get_element_schemas", elements="heading,slider-nested,image")`.

### New knowledge: template-elements (231 lines)

- **`template-elements.md`** — complete guide for building single post and archive templates. Covers all 13 post-* elements, posts/pagination/query-results-summary, dynamic data tags, layout patterns, and 8 common pitfalls. Knowledge domains now total 12 files, 2466 lines.

## [3.23.0] — 2026-04-17

### Working examples extracted to data layer (IMP 5)

- **Data migration:** 76 element working examples moved from ~560 lines of hardcoded PHP (`SchemaGenerator::generate_working_example()`) into `data/elements.json` `working_example` field — single source of truth, updatable without code release.
- **32 new element entries** added to registry: post-title, post-excerpt, post-content, post-meta, post-author, post-taxonomy, post-comments, post-navigation, post-reading-time, post-reading-progress-bar, post-sharing, post-toc, related-posts, posts, nav-menu, search, shortcode, sidebar, wordpress, breadcrumbs, back-to-top, audio, instagram-feed, facebook-page, logo, custom-title, team-members, map-leaflet, map-connector, pagination, query-results-summary, slot.
- **SchemaGenerator refactored:** `generate_working_example()` reads from element registry first, falls back to control-based detection for unknown/third-party elements only. File reduced from 1409 → 847 lines.
- **No behavior change:** all consumers (`bricks:get_element_schemas`, `propose_design`, `ElementSettingsGenerator` content_key fallback) receive identical data.

## [3.21.2] — 2026-04-16

### Codebase audit (88 PHP files)

- **Bug fix:** `verify_build` root section detection — parent compared to string `'0'` but Bricks stores integer `0`. Hierarchy summary was empty. Fixed with `empty()`.
- **Bug fix:** WooCommerce scaffold hints referenced dead `get_builder_guide()` tool — updated to `bricks:get_knowledge('woocommerce')`.
- **Fix:** `uninstall.php` missing `bricks_mcp_db_version` option cleanup.
- **Fix:** `DesignPipelineCheck` missing from WP Site Health screen — 10 of 11 checks were bridged; this was the gap.
- **Fix:** `DesignPipelineCheck` fallback `plugin_dir_path()` received directory instead of file path.
- **Fix:** `BricksCore` undefined variable risk — `$stored` not initialized before try block.
- **Fix:** `ComponentHandler` empty array guard on `elements[0]` access.
- **Cleanup:** `PageContentSubHandler` hardcoded meta key string replaced with `BricksCore::META_KEY` constant.
- **Cleanup:** `BricksService` orphaned `@param` docblock fragment removed.
- **Cleanup:** `OnboardingHandler` 4-space indentation converted to tabs (project standard).
- **Dead code:** `UpdateChecker` unused deprecated `GITHUB_API_URL` constant removed.

**Data layer alignment: ALL CLEAN.** Zero stale references across all 88 files.

## [3.21.1] — 2026-04-16

### Knowledge integration

- **Building rules extracted** to `data/building-rules.json` — single source of truth, `BUILDING_RULES_BASE` PHP constant deleted.
- **Discovery suggests knowledge domains** — `recommended_knowledge` array in Phase 1 response, keyword-scanned from description (form → `forms`, popup → `popups`, etc.).
- **10 schema responses include `knowledge_hint`** — every `get_*_schema` and `get_breakpoints` response points to the matching knowledge domain.
- **Form pipeline warning** cross-links to `bricks:get_knowledge('forms')`.
- **Breakpoints example** fixed: `_padding:mobile` → `_padding:mobile_portrait`.

## [3.21.0] — 2026-04-16

### Architecture — data layer refactor

- **Unified element registry** (`data/elements.json`): merged `element-defaults.json` + `element-hierarchy-rules.json` + `ELEMENT_CAPABILITIES` (from ProposalService) + `NESTING_RULES` (from SchemaGenerator) into one 44-element registry. Single source of truth for hierarchy, content keys, flex behavior, element_settings defaults, purpose/capabilities/rules.
- **Unified settings key registry** (`data/settings-keys.json`): renamed from `css-property-map.json`, expanded from 37 to 66 keys (all Bricks 2.3 properties including parallax, 3D transforms, grid family, cursor, filter, attributes). Deleted redundant PHP `CSS_PROPERTY_MAP` constant — `get_settings_keys_flat()` reads from JSON. AI reference and pipeline validator now guaranteed in sync.
- **Design pattern seed file** (`data/design-patterns.json`): 21 curated patterns shipped with plugin, auto-seeded into DB on first activation via `DesignPatternService::migrate_plugin_patterns()`. Fixes fresh-install bug where new sites got empty pattern library. Admin "Reset to plugin defaults" button re-seeds (overwrites matching IDs, preserves custom patterns).
- **Deleted `StarterClassesService`** (245 lines): removed service + `bootstrap_recommendation` from discovery response + health check. Not needed.
- **Deleted `class-context-rules.json`** + all auto-suggest code: removed `suggest_for_context()`, 6 check methods, guard evaluator, context-rules loader from `ClassIntentResolver` + `ElementSettingsGenerator`. ~260 lines of code removed. AI must now specify `class_intent` explicitly.
- **Deleted form templates**: removed hardcoded Romanian newsletter/contact/login form templates. Pipeline now emits warning if form has no fields — AI must supply fields per site language/branding.

### Knowledge library — rewritten + expanded (8 → 11 files)

- **All 8 existing knowledge files rewritten** against live MCP schemas (`get_form_schema`, `get_popup_schema`, `get_component_schema`, `get_dynamic_tags`, `get_element_schemas`). Every claim verified. Major bugs fixed: popup action names (`show_popup` → `show` + `target: popup`), WC tag prefix (`{wc_*}` → `{woo_*}`), component connection format (`{true}` → `["key"]`), form options format (array → newline string), breakpoints (`mobile` → `mobile_landscape`/`mobile_portrait`).
- **3 new knowledge files**: `query-loops.md` (5 query types, pagination, global queries, nested loops), `templates.md` (9 types, condition scoring, precedence, import/export), `global-classes.md` (IDs vs names, 16 actions, style shape, batch ops, CSS import).
- **Auto-discovery**: `BricksToolHandler::discover_knowledge_domains()` scans `data/knowledge/*.md` — drop a new file and it's instantly available. Replaces hardcoded enum.
- **Server instructions** now mention knowledge library with domain list on every session init.
- **Onboarding** workflow guide includes `knowledge_library` tier with usage instructions.
- **Cross-links** between all 11 files. Each file's "Related Knowledge" section lists relevant siblings.

### Documentation updated

- `readme.txt`: tool count 23→27, added 5 missing tools, design system 6→7 panels / 14 categories, pattern count 17→21, rate limit range corrected, changelog backfilled.
- `data/knowledge/building.md`: form auto-detection section rewritten (templates deleted), breakpoints fixed, Tractari examples replaced with neutral English, auto-fix list expanded, cross-links to all 10 sibling domains.

## [3.20.0] — 2026-04-15

### Removed

- **Dark mode removed** from the plugin admin. The `@media (prefers-color-scheme: dark)` blocks in `admin-settings.css` and `admin-design-system.css` are gone. Admin now renders in the light scheme regardless of OS/browser preference — consistent look across all users.

## [3.19.3] — 2026-04-15

### Polish + accessibility

- **Shadow inputs** get a dedicated full-width row layout instead of the narrow card-grid, so long shadow values no longer truncate. Preview box sits inline at 80px wide.
- **Transition demo button** now uses your `--shadow-xl` for the hover state (was hardcoded rgba). Text color auto-contrasts on the primary background.
- **Heading preview caps at 42px** in step rows — very large h1 values no longer blow out the row height.
- **Pill radius preview** is now a 96×32 rectangle (vs. 48×48 square) so it visually differs from the circle preview.
- **Reset** rendered as a `<button>` (was `<a href="#">`) for correct keyboard + screen reader semantics.
- **Typography HTML font-size** uses `<fieldset>` + `<legend>` semantic grouping.
- **Status messages** announced via `aria-live="polite"` after Apply / Reset actions.
- **Inactive panels** get `aria-hidden="true"` so screen readers don't traverse them.
- **Focus ring** on stepper buttons visible in both light and dark mode.
- **aria-label** added to every step input, color picker, and hex field so screen readers announce which token you're editing.

## [3.19.2] — 2026-04-15

### Dark mode + contrast fixes

- **Dark mode text is now readable.** The `--bwm-gray-900` variable (primary text color) was not being remapped in the `prefers-color-scheme: dark` block, so panel titles / step labels / help text rendered nearly black on a dark admin background. Now remaps to `#f9fafb` in dark mode.
- **Shadow preview boxes keep their light background** in dark mode so the shadow is actually visible against a contrasting surface.
- **Live preview auto-contrasts text colors.** Hero title / body / button text now uses a WCAG luminance check on the background — picks white or dark text automatically, so pastel primary/secondary colors no longer produce invisible labels.
- Stats card labels, showcase subtitles, and footer link now pick readable colors based on their actual background luminance.
- Token label pills and transparency checkerboard got dark-mode tweaks so they stay visible.
- New focus ring on stepper buttons for keyboard navigation (light + dark mode).

## [3.19.1] — 2026-04-15

### Live preview enhancements

- **Live preview now showcases the 3.19 token categories**. A new "Effects & Tokens Showcase" section under the footer displays:
  - **Shadows row** — 5 floating cards rendered with `--shadow-xs` through `--shadow-xl` side-by-side.
  - **Border widths row** — 3 cards using `--border-thin/medium/thick` with the primary color.
  - **Aspect ratios row** — 5 primary-colored boxes rendered with `--aspect-square/video/photo/portrait/wide`.
  - **Transitions demo** — a hover-me button wired with `transition: transform var(--duration-base) var(--ease-out)` so you can feel the motion values live.
- Existing feature cards and the sidebar quick-start card now apply `--shadow-m` / `--shadow-l` and `--border-thin` so the hero mockup reflects your tokens out of the box.

## [3.19.0] — 2026-04-15

### New

- **5 new design token categories** (27 new variables):
  - **Shadows** (`--shadow-xs`, `--shadow-s`, `--shadow-m`, `--shadow-l`, `--shadow-xl`, `--shadow-inset`)
  - **Transitions** (`--duration-fast/base/slow`, `--ease-out/in-out/spring`)
  - **Z-Index** (`--z-base` through `--z-tooltip`, 100× gaps per layer)
  - **Border Widths** (`--border-thin/medium/thick`)
  - **Aspect Ratios** (`--aspect-square/video/photo/portrait/wide`)
- New admin "Effects" stepper panel (Shadows + Transitions + Z-Index subsections).
- Border Widths added to the Radius panel.
- Aspect Ratios added to the Sizes panel.
- Shadow inputs have live preview boxes next to them so you can see the shadow applied.
- Existing configs auto-migrated on read — new defaults populate, no user action needed.

## [3.18.10] — 2026-04-15

### Change

- **Palette renamed** from `BricksCore` to `Bricks-WP-MCP`. Apply now replaces palettes named either `Bricks-WP-MCP` or `BricksCore` (migration path — old palettes on upgrade get replaced rather than duplicated).

## [3.18.9] — 2026-04-15

### Fixes

- **Scales actually appear in Bricks Style Manager now.** 3.18.7/3.18.8 only wrote the `prefix` field on the scale metadata, but Bricks expects a full config object with `scaleScope`, `scaleType`, `scaleNames`, `baseline`, `manualValues`, `isManual`, and min/max font size + scale ratio. Now writes the complete shape matching Fancy Framework's import format.
- Headings scale prefix corrected to `h` (not `--`). Step names in scaleNames are `1`–`6` (prefix `h` + step = variable `h1`..`h6`).

## [3.18.8] — 2026-04-15

### Fixes

- **Scale prefix format**: Bricks Style Manager requires scale prefixes to start with `--` (matching the CSS custom property syntax). 3.18.7 wrote `space-` / `text-` / empty, which the Bricks UI silently rejected ("No scales found"). Now writes `--space-`, `--text-`, `--` so scales appear correctly in Style Manager → Spacing and Typography tabs.

## [3.18.7] — 2026-04-15

### Fixes

- **Spacing / Texts / Headings now appear as scales in Bricks Style Manager.** Apply marks these categories with `scale` metadata so they show up in the Bricks native Spacing and Typography scale pickers, not just as raw variables.
- After Apply, the Bricks style manager CSS file is regenerated so scale changes appear immediately.

## [3.18.6] — 2026-04-15

### Fixes

- **Stepper button hover**: active tab is no longer washed out on hover. Hover + focus no longer override the brand background.
- **Design Pipeline Health diagnostic**: rewrote the check to verify database-backed design patterns via `DesignPatternService` (patterns moved from `data/design-patterns/` to a WP option in 3.x; the diagnostic was still looking at the filesystem and always failing).
- **Typography + Spacing step layout**: inputs are now grouped together on the left (Mobile + Desktop next to each other) and previews/swatches grouped on the right, instead of interleaving input / preview / input / preview.
- Removed diagnostic lifecycle logging that 3.18.5 added temporarily.

## [3.18.5] — 2026-04-15

### Diagnostic

- **Added extensive lifecycle debug logging** to `bricks-mcp.php` (plugin file load, activation hook, deactivation hook with caller stack trace, `deactivated_plugin` action hook listener, init hook) to diagnose a silent auto-deactivation reported by a user after recent upgrades.
- When WP deactivates the plugin (for any reason), the call stack is written to `debug.log` so we can identify the trigger (normal upload flow vs WP fatal-error recovery vs other).
- No functional code changes. Logging is prefixed `[BricksMCP 3.18.5]` for easy grep. Will be removed in 3.18.6 once diagnosed.

## [3.18.4] — 2026-04-15

### Critical fix

- **ZIP packaging**: release archive now includes the top-level `bricks-mcp/` wrapper directory. Without it, WordPress could install the plugin under a directory named after the zip filename (e.g. `bricks-mcp-3.18.3/`), breaking the previous activation and silently deactivating the plugin on upgrade. With the wrapper, upgrades land in the canonical `bricks-mcp/` directory.

### Fixes

- **Gap visual indicators** now correctly resolve `var(--space-X)` references to actual pixel values from your spacing scale. Previously they fell back to a static 16px for any composite value. Server resolves at render time, JS resolves on live edits.
- **`ajax_save_config` and `ajax_apply` now run the config through `ConfigMigrator::migrate()` before storing**. Defensive normalization guarantees the stored config always has the v2 shape, even if the JS sent partial data.
- Removed dead `render_panel_text_styles()` method (merged into Typography panel in 3.18.2).

## [3.18.3] — 2026-04-15

### Critical fix

- **Typography panel was missing `</section>` close tag** — the Text Styles subsection added in 3.18.2 swallowed the closing tag. As a result every panel after Typography (Colors, Gaps/Padding, Radius, Sizes) became a nested child of the Typography section, inheriting `display: none` and never appearing when their stepper button was clicked. Adds the missing close tag.

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
