# WordPress MCP Server for Bricks — Architecture Overview

Technical architecture of the Bricks MCP plugin (v3.4.0). This document describes how the plugin is structured, how requests flow through it, and how the major subsystems work together.

For the 4-step design pipeline reference, see [DESIGN_PIPELINE.md](DESIGN_PIPELINE.md). For security details, see [SECURITY.md](SECURITY.md). For the Bricks data model internals, see [BRICKS_DATA_MODEL_DEEP_DIVE.md](BRICKS_DATA_MODEL_DEEP_DIVE.md).

---

## 1. High-Level Architecture

The plugin implements a Model Context Protocol (MCP) server as a WordPress plugin. It exposes 25 tools that allow AI assistants to read and write Bricks Builder content, manage WordPress resources, and build complete page layouts from declarative design schemas through a server-enforced 4-step pipeline.

```
AI Client (Claude Desktop / Claude Code / other MCP clients)
    |
    | Streamable HTTP (JSON-RPC 2.0)
    v
WordPress REST API
    |
    v
StreamableHttpHandler — session management, SSE transport, onboarding
    |
    v
Server — authentication, rate limiting, JSON-RPC dispatch
    |
    v
Router — tool resolution, intent classification, prerequisite gating, design build gate
    |
    v
ToolRegistry — tool definitions and input schemas
    |
    v
Handlers (~17 files) — tool execution logic
    |
    v
Services (~35 files) — business logic, Bricks/WordPress APIs, design pipeline
```

### Transport

The plugin uses **Streamable HTTP** as its MCP transport. The `StreamableHttpHandler` manages:

- REST API route registration (`/wp-json/bricks-mcp/v1/mcp`)
- Session creation and resumption via `Mcp-Session-Id` headers
- Server-Sent Events (SSE) streaming for responses
- JSON-RPC 2.0 message parsing and dispatch
- Server capability advertisement (`tools/list`, `initialize`)
- Onboarding payload delivery on `initialize` (site context, workflow guide, examples)
- Output buffering control and keep-alive for long-running operations

There is no stdio transport. All communication is HTTP-based through WordPress's REST infrastructure.

### Authentication

All requests are authenticated using WordPress Application Passwords (HTTP Basic Auth). The `Server` class handles:

- Permission checks (`manage_options` capability required by default)
- Rate limiting via `RateLimiter` (configurable 10-1000 RPM per user)
- JSON-RPC method dispatch to the Router

---

## 2. Request Flow

### Tool Call Lifecycle

1. **Client sends** a JSON-RPC `tools/call` request over HTTP POST
2. **StreamableHttpHandler** validates the session, opens an SSE stream
3. **Server** authenticates the user, checks rate limits, dispatches to Router
4. **Router** resolves the tool name, classifies intent, checks prerequisite gates, invokes the handler
5. **Handler** validates input, calls service methods, returns result
6. **Router** wraps the result in MCP response format
7. **StreamableHttpHandler** sends the response as an SSE event, closes the stream

### Intent Router and Tiered Prerequisite Gating

The Router classifies every request into one of three workflows and enforces tiered prerequisites via `PrerequisiteGateService` (5 flags total):

| Workflow | Example Operations | Required Prerequisite Flags |
|---|---|---|
| **Direct operation** | Edit text, move elements, swap images, update menus | `site_info` |
| **Instructed build** | element:bulk_add, page:append_content (small), create form | `site_info` + `global_classes` |
| **Design build** | build_from_schema, full section/page layouts | `site_info` + `global_classes` + `css_variables` + `design_discovery` + `design_plan` |

Flags persist for 30 minutes per session. Prerequisites are collected by calling:

1. `get_site_info` — site tokens, briefs, page summaries
2. `global_class:list` — all global CSS classes
3. `global_variable:list` — all CSS variables
4. `propose_design(description)` — Phase 1 (sets `design_discovery`)
5. `propose_design(description, design_plan)` — Phase 2 (sets `design_plan`)

The server instructions (embedded in `StreamableHttpHandler` and re-emitted on each tool-call response) tell the AI client which workflow applies and what prerequisites remain. This prevents the AI from building pages without first understanding the site's design system and structurally planning the output.

### Design Build Gate

The Router enforces a **design build gate**: when an AI attempts to use `page:append_content`, `page:update_content`, `element:bulk_add`, or `page:create` with section-level elements or more than 8 elements, the request is rejected with a message redirecting to the `build_from_schema` pipeline. This ensures large layouts always go through the design pipeline for consistent, high-quality output.

The gate can be bypassed with `bypass_design_gate: true` for legitimate direct operations (e.g., importing a known-good clipboard payload).

### Onboarding

On the `initialize` JSON-RPC call, the server returns an onboarding payload (via `OnboardingService`) that includes:

- Site context (name, page count, class count, discovered section patterns)
- The 3-tier workflow guide
- Quick-start examples
- AI-writable briefs (design_brief, business_brief) — loaded from cached options to minimize DB hits

Clients can fetch specific onboarding sections on demand via `get_onboarding_guide(section)`.

---

## 3. Directory Structure

```
bricks-mcp/
├── bricks-mcp.php                    # Plugin entry point, constants, bootstrap
├── includes/
│   ├── Plugin.php                     # Core plugin loader
│   ├── Activator.php                  # Activation hooks
│   ├── Deactivator.php                # Deactivation hooks
│   ├── Autoloader.php                 # PSR-4 autoloading
│   ├── I18n.php                       # Internationalization
│   ├── Admin/
│   │   ├── Settings.php               # Admin settings page
│   │   ├── SiteHealth.php             # WP Site Health integration
│   │   ├── DiagnosticRunner.php       # Connection diagnostics
│   │   ├── DiagnosticCheck.php        # Base check class
│   │   └── Checks/                    # 10 individual diagnostic checks
│   ├── MCP/
│   │   ├── StreamableHttpHandler.php  # MCP transport + SSE + onboarding
│   │   ├── Server.php                 # Auth, rate limiting, dispatch
│   │   ├── Router.php                 # Tool routing, intent classification, gates
│   │   ├── ToolRegistry.php           # Tool definitions and schemas
│   │   ├── RateLimiter.php            # Per-user rate limiting
│   │   ├── Response.php               # MCP response formatting
│   │   ├── Handlers/                  # ~17 tool handler files
│   │   └── Services/                  # ~35 service files (design pipeline, etc.)
│   └── Updates/
│       └── UpdateChecker.php          # GitHub-based update checks
├── data/
│   ├── element-defaults.json          # Default settings per element type
│   ├── element-hierarchy-rules.json   # Valid parent/child relationships
│   ├── class-context-rules.json       # Contextual class suggestions
│   └── design-patterns/               # 17 curated section compositions (v3.2.0+)
│       ├── heroes/                    # centered-dark, split-cards, split-form-badges, …
│       ├── splits/                    # login-form, text-image, text-pills, contact-form
│       ├── features/                  # icon-grid-3, dark-icon-grid-4, icon-box-grid
│       ├── ctas/                      # centered-dark, split-image, banner-accent
│       ├── pricing/                   # 3-tier
│       ├── testimonials/              # card-grid, dark-single
│       └── content/                   # faq-accordion, stats-counter, about-team
├── docs/
│   ├── WordPress MCP Server for Bricks.md  # This document
│   ├── DESIGN_PIPELINE.md             # 4-step pipeline reference
│   ├── SECURITY.md                    # Security model and safeguards
│   ├── BRICKS_DATA_MODEL_DEEP_DIVE.md  # Bricks internals
│   ├── BUILDER_GUIDE.md               # Legacy reference (superseded by knowledge/)
│   └── knowledge/                     # 8 domain-specific knowledge fragments
│       ├── building.md
│       ├── forms.md
│       ├── dynamic-data.md
│       ├── components.md
│       ├── popups.md
│       ├── woocommerce.md
│       ├── animations.md
│       └── seo.md
├── admin/                             # Admin CSS and JS assets
└── languages/                         # Translation files
```

---

## 4. Handlers

Each handler file manages one tool (or a group of related actions). The Router dispatches to handlers based on tool name.

| Handler | Tool Name(s) | Responsibility |
|---|---|---|
| **PageHandler** | `page` | CRUD for pages/posts, content views (detail/summary/context/describe), snapshots, SEO, clipboard import |
| **ElementHandler** | `element` | Add/update/remove/move/find elements, bulk operations, conditions |
| **GlobalClassHandler** | `global_class` | CSS class CRUD, batch ops, import CSS/JSON, categories, export |
| **DesignSystemHandler** | `global_variable`, `color_palette`, `typography_scale`, `theme_style` | CSS variables, color palettes, type scales, theme styles |
| **BricksToolHandler** | `bricks` | Enable/disable Bricks, settings, schemas, dynamic tags, queries, notes, knowledge fragments |
| **ProposalHandler** | `propose_design` | Two-phase design pipeline entry: DISCOVER (no plan) → PROPOSAL (with plan) |
| **BuildHandler** | `build_from_schema` | Declarative design pipeline orchestration with class/pattern resolution |
| **VerifyHandler** | `verify_build` | Post-build verification with human-readable section descriptions (v3.4.0) |
| **TemplateHandler** | `template`, `template_condition`, `template_taxonomy` | Template CRUD, display conditions, tags/bundles |
| **ComponentHandler** | `component` | Bricks components: create, instantiate, properties, slots |
| **SchemaHandler** | `get_site_info`, `confirm_destructive_action` | Site info/diagnostics, destructive action confirmation |
| **OnboardingHandler** | `get_onboarding_guide` | On-demand onboarding sections (workflows, examples, site_context, briefs) |
| **CodeHandler** | `code` | Page CSS and JavaScript (dangerous actions gated) |
| **MediaHandler** | `media` | Unsplash search, image sideload, featured images |
| **MenuHandler** | `menu` | WordPress menus and locations |
| **FontHandler** | `font` | Adobe Fonts, webfont loading settings |
| **WordPressHandler** | `wordpress` | Posts, users, plugins |
| **MetaBoxHandler** | `metabox` | Meta Box field groups, values, dynamic tags |
| **WooCommerceHandler** | `woocommerce` | WC status, elements, dynamic tags, template scaffolding |

---

## 5. The Design Build Pipeline

The design pipeline is a server-enforced 4-step flow — DISCOVER → DESIGN → BUILD → VERIFY — that translates an AI's design intent into Bricks elements with pattern-aware layout, class reuse, and auto-fixes.

See [DESIGN_PIPELINE.md](DESIGN_PIPELINE.md) for the full reference. Summary below.

### Step 1 — DISCOVER (`propose_design` without `design_plan`)

`ProposalService::create_discovery()` returns:

- Available Bricks elements with purpose + capabilities + rules (20 common types)
- Available layouts and section types
- Building rules (10 critical rules about structure, centering, child theme overrides)
- 2–3 matching reference patterns from the Design Pattern Library (pattern matching via tags)
- Site context: classes (available + suggested), scoped CSS variables, briefs
- **Existing page sections (v3.4.0)** — top 5 sections already on the target page with label, description, background, layout, classes_used — so the AI can match existing visual language
- **Site style hints (v3.4.0)** — aggregated common layouts, backgrounds, frequently used classes
- **Bootstrap recommendation (v3.4.0)** — when a site has fewer than 5 global classes, returns 13 starter class definitions from `StarterClassesService`
- Design plan format specification

No `proposal_id` is returned — the AI cannot skip to building. The discovery response uses hash-based caching: subsequent discoveries in the same session return a ~3KB slim response vs ~16KB full payload when site_context hasn't changed.

### Step 2 — DESIGN (`propose_design` with `design_plan`)

The AI provides a structured `design_plan` object containing:
- `section_type` (hero/features/pricing/cta/testimonials/split/generic)
- `layout` (centered/split-60-40/split-50-50/grid-2/3/4)
- `background` (dark/light), optional `background_image` (`unsplash:query`)
- `elements[]` — each with `type`, `role`, `content_hint`, optional `tag`, `class_intent`
- `patterns[]` — repeating structures (cards, badges) with `name`, `repeat`, `element_structure`

The server validates enum values, required fields, and structural coherence. On success it returns:
- `proposal_id` (10-minute TTL)
- `suggested_schema` — complete schema generated from the design_plan with column gaps, pattern padding, multi-row support
- `resolved.element_schemas` — real Bricks schemas for the chosen element types
- `resolved.classes_suggested` — matched existing classes

### Step 3 — BUILD (`build_from_schema`)

The AI replaces `[PLACEHOLDER]` text in the suggested schema and calls `build_from_schema(proposal_id, schema)`. The server-side pipeline runs:

```
suggested_schema (with real content)
    |
    v
1. Validate proposal (10-min TTL)
    |
    v
2. DesignSchemaValidator — structure, hierarchy, unknown keys rejected
    |
    v
3. Protected page check
    |
    v
4. Extract element types + class intents + class styles
    |
    v
5. ClassIntentResolver — match existing classes OR create new with styles
    |
    v
6. Prefetch Bricks element schemas for chosen types (dynamic)
    |
    v
7. SchemaExpander — expand patterns (repeat + data substitution), multi-row layouts
    |
    v
8. Re-validate expanded hierarchy
    |
    v
9. ElementSettingsGenerator — generate Bricks settings with auto-fixes:
   - Dark sections auto-color children text white
   - Button `icon` → native Bricks icon settings (no emoji)
   - Form without fields → FormTypeDetector classifies type, applies template
   - `_gap` → `_columnGap`/`_rowGap` based on `_direction`
   - `_maxWidth` → `_widthMax`, `_textAlign` → `_typography.text-align`
   - `unsplash:query` in `_background.image` → sideload and replace
   - `background: "dark"` merges with existing background (preserves images)
   - CSS variable resolution via SiteVariableResolver
    |
    v
10. Auto-snapshot the page for rollback safety
    |
    v
11. Write elements to Bricks via PageOperationsService
```

Returns: `elements_created` count, `classes_created`/`classes_reused` arrays, `tree_summary`, `snapshot_id`.

### Step 4 — VERIFY (`verify_build`)

`VerifyHandler` returns (v3.4.0 rich output):

- `page_description` from `describe_page()` — e.g., "Homepage — 3 sections"
- `sections[]` — per-section human-readable summaries ("Dark section with background image and overlay. Contains h1, text, 2 buttons")
- `type_counts` — element type frequency
- `classes_used` — resolved class names
- `labels` — all structural element labels
- `last_section` — hierarchy summary of the most recently added section

The AI compares this output against its design_plan to verify the build matches intent.

---

## 6. Design Pattern Library

Located in `data/design-patterns/` (v3.2.0+). 17 curated section compositions across 7 categories. Each pattern file defines:

- `layout`, `background`, `tags` — used for matching against AI descriptions
- `section_overrides`, `container_overrides` — applied by `SchemaSkeletonGenerator`
- `columns` — left/right column definitions with `alignment`, `padding`, `gap`, `max_width`, `fill`, `elements[]`
- `has_two_rows` + `rows.row_1`/`rows.row_2` — for multi-row patterns (hero with badges below)
- `patterns` — embedded repeating structures (cards, badges)

| Category | Pattern count | Examples |
|---|---|---|
| heroes | 5 | centered-dark, centered-dark-image, split-cards, split-image, split-form-badges |
| splits | 4 | login-form, text-image, text-pills, contact-form |
| features | 3 | icon-grid-3, dark-icon-grid-4, icon-box-grid |
| ctas | 3 | centered-dark, split-image, banner-accent |
| pricing | 1 | 3-tier |
| testimonials | 2 | card-grid, dark-single |
| content | 3 | faq-accordion, stats-counter, about-team |

Patterns capture **composition** (what elements, in what arrangement). The site's design system handles **appearance** (colors, sizes, radius). This separation lets the same patterns work across visually diverse sites.

`DesignPatternService` loads patterns lazily and caches them in a static property per request.

---

## 7. Site-Awareness (v3.4.0)

When connecting to a site with existing designed pages, the discovery phase analyzes those sections to extract the site's visual language:

- `existing_page_sections` — top 5 sections from the target page with `label`, `description`, `background`, `layout`, `classes_used`, `element_count`
- `site_style_hints` — aggregated across all existing sections: most common layouts, backgrounds, frequently used classes

The AI is explicitly instructed in the `next_step` text to match existing patterns (same classes, same layouts, same background treatments) unless the user explicitly requests a different style. This dramatically improves consistency on sites with established designs.

For sites with fewer than 5 global classes, `StarterClassesService` returns 13 curated starter class definitions (grid-2/3/4, eyebrow, tagline, hero-description, btn-primary, btn-outline, card, card-dark, card-glass, tag-pill, tag-grid) that use CSS variables for portability. The AI can bootstrap these upfront via `global_class:batch_create` or let them emerge lazily as `class_intent` values appear in design plans.

---

## 8. Knowledge System

The plugin serves domain-specific knowledge on demand via `bricks:get_knowledge(domain)`. Eight markdown files in `docs/knowledge/` cover:

| Domain | Content |
|---|---|
| `building` | General rules: hierarchy, classes, labels, semantic HTML, CSS variables, gap handling, background/overlay patterns, button icons, form auto-detection, invalid key auto-fixes |
| `forms` | Bricks form element configuration, form type detection (newsletter/login/contact), validation, actions |
| `dynamic-data` | Dynamic data tags, query loops, template context |
| `components` | Bricks components: properties, slots, instances |
| `popups` | Popup templates, triggers, settings |
| `woocommerce` | WC template types, product elements, dynamic tags |
| `animations` | CSS animations, interactions, scroll-based effects |
| `seo` | SEO plugin integration, meta tags, Open Graph |

This replaced the monolithic legacy `BUILDER_GUIDE.md` which was previously served in full to every AI session. The modular system reduces token usage by serving only the knowledge relevant to the current task.

---

## 9. Key Services

### Content Operations

- **PageOperationsService** — Page content reads/writes, snapshot management, content views (detail/summary/context/describe), section description generator
- **ElementNormalizer** — Normalizes element arrays (ID generation, parent/child linkage, settings cleanup)
- **ElementIdGenerator** — Generates unique 6-character alphanumeric element IDs
- **ValidationService** — Input validation, element count safety checks, content wipe prevention

### Design System

- **GlobalClassService** — Global class CRUD with style support, batch operations, CSS/JSON import (with static caching)
- **GlobalVariableService** — CSS variable and category management
- **ColorPaletteService** — Color palette and individual color management
- **ThemeStyleService** — Site-wide theme style management
- **TypographyScaleService** — Typography scale with CSS variable generation
- **StarterClassesService** (v3.4.0) — 13 curated starter class definitions for bootstrapping new sites

### Design Pipeline

- **ProposalService** — Two-phase propose_design orchestrator; discovery caching; plan validation
- **DesignPatternService** (v3.2.0) — Loads curated section compositions, matches by tags
- **SchemaSkeletonGenerator** — Generates suggested_schema from design_plan with pattern column overrides
- **DesignSchemaValidator** — Schema validation against expected format; unknown key rejection with suggestions
- **ClassIntentResolver** — Semantic class matching and creation with styles
- **SchemaExpander** — Pattern expansion, data substitution, multi-row layout expansion (`build_multi_row_layout`)
- **ElementSettingsGenerator** — Structure-to-settings conversion with defaults, responsive handling, dark-section text coloring
- **SiteVariableResolver** — Resolves palette color references to CSS variables (with static caching)
- **FormTypeDetector** (v3.3.1) — Single utility for detecting form type (newsletter/login/contact) from role/content_hint

### Infrastructure

- **PrerequisiteGateService** — Enforces per-workflow context prerequisites (5 flags, 30-min persistence)
- **PendingActionService** — Token-based destructive action confirmation (2-min TTL)
- **OnboardingService** — Site context, workflow guide, examples, briefs (with option caching)
- **RequestCounterService** — Request counting for admin dashboard
- **NotesService** — AI-writable notes for cross-session context
- **BricksCore** — Low-level Bricks API wrapper (element registry, global settings, option key constants)
- **SchemaGenerator** — Converts Bricks element controls to simplified JSON schemas
- **BricksService** — Facade over Bricks API for handlers

---

## 10. Static Caching Layer

To minimize database hits during multi-step operations, several services use in-memory static caches scoped to a single request:

| Service | Cached | Effect |
|---|---|---|
| `GlobalClassService` | All global classes | 11 class fetches per 3-section build → 1 DB read |
| `SiteVariableResolver` | Variables by category | Single variable read across ProposalService + ClassIntentResolver + ElementSettingsGenerator |
| `DesignPatternService` | Loaded pattern JSON files | Pattern files read once per request |
| `OnboardingService` | Briefs option | 3 `get_option` calls per session → 1 |
| `ProposalService` | Discovery response (hashed by site_context) | Subsequent discoveries return slim ~3KB response vs ~16KB full |

These caches are invalidated on mutation (e.g., creating a new class clears the `GlobalClassService` cache) and don't persist across requests.

---

## 11. Auto-Snapshot System

Before any content write operation (`page:update_content`, `page:append_content`, `build_from_schema`), the plugin automatically creates a snapshot of the existing page content. Snapshots are stored as post meta and can be listed and restored via the `page` tool (`snapshot`, `list_snapshots`, `restore` actions).

This provides a safety net: if an AI build produces unwanted results, the previous state can be restored without manual intervention. Each build result includes the `snapshot_id` for easy rollback.

---

## 12. Destructive Action Confirmation

Operations that delete or replace content require a two-step confirmation flow:

1. The tool returns a confirmation token (16-character hex string) with a description of the action
2. The AI must call `confirm_destructive_action` with the token to proceed
3. Tokens expire after 2 minutes and can only be used once

This prevents accidental mass deletions from AI agent loops. The `PendingActionService` manages token generation, storage, and validation. Gated operations include: cascade element removal, global class bulk delete, menu deletion, template deletion, page force-delete, page content replacement via append with replace flag.

---

## 13. Admin Interface

The plugin settings page (`Settings > Bricks MCP`) provides:

- **Connection status badge** — Shows whether the MCP endpoint is reachable
- **Getting started checklist** — Guides new users through setup
- **Request counter** — Displays tool call counts per session and total
- **Configuration options** — Authentication toggle, rate limit (10-1000 RPM), dangerous actions toggle, Unsplash API key
- **Diagnostics** — 10 automated checks (HTTPS, Application Passwords, REST API, Bricks active, permalinks, PHP timeout, hosting provider, security plugins, endpoint reachability, user app passwords)

---

## 14. Update System

The `UpdateChecker` polls the GitHub repository for new releases and integrates with the WordPress plugin update system. Users see available updates in the standard Plugins screen and can update with one click. Release metadata is sourced from GitHub release tags (e.g., `v3.4.0`) and the plugin header version declared in `bricks-mcp.php`.
