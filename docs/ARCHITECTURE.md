# Bricks MCP — Architecture & Main Flows

> Last updated: 2026-04-15 (v3.16.0)

## Overview

The plugin exposes a single REST endpoint that speaks the Model Context Protocol (MCP) over Server-Sent Events. AI assistants connect via HTTP, authenticate with WordPress Application Passwords, and call tools to read/write Bricks Builder content.

```
AI Client (Claude, Cursor, etc.)
  |
  | HTTPS + Application Password
  v
WordPress REST API
  /wp-json/bricks-wp-mcp/v1/mcp
  |
  v
Server (auth, rate limit)
  |
  v
StreamableHttpHandler (SSE transport)
  |
  v
Router (dispatch, gates, confirmation)
  |
  v
Handlers (20+) → Services (34+) → WordPress/Bricks APIs
```

---

## 1. Request Lifecycle

### Entry Point
**File**: `MCP/Server.php`

Single endpoint: `POST|GET|DELETE /wp-json/bricks-wp-mcp/v1/mcp`

1. **Auth check** — WordPress login required for writes, `manage_options` capability enforced
2. **Rate limit** — per user/IP, configurable RPM (default 120)
3. **Request counter** — daily totals for diagnostics
4. **Hand off** to StreamableHttpHandler

### Transport
**File**: `MCP/StreamableHttpHandler.php`

| Method | Purpose |
|--------|---------|
| POST | JSON-RPC dispatch (single or batch up to 20 messages) |
| GET | SSE keepalive stream (configurable interval, default 25s) |
| DELETE | Session termination (no-op, returns 200) |

POST flow:
1. Validate `Content-Type: application/json`
2. Check body size (soft limit 1MB filterable, hard limit 10MB)
3. Parse JSON-RPC message(s)
4. Route each message: `initialize`, `tools/list`, `tools/call`, `ping`
5. Emit response as SSE: `event: message\ndata: <json>\n\n`

### Routing
**File**: `MCP/Router.php`

Tool execution pipeline:
1. **Lookup** tool in ToolRegistry
2. **Capability check** — read vs write tools
3. **Argument validation** — JSON Schema per tool
4. **Prerequisite gate** — block writes until site context loaded
5. **Design build gate** — redirect complex builds to 4-step pipeline
6. **Execute** handler
7. **Confirmation intercept** — destructive ops get token-based confirmation
8. **Response wrap** — success content or error

---

## 2. Prerequisite Gate

**File**: `MCP/Services/PrerequisiteGateService.php`

Two tiers of prerequisites, tracked per-user via transients (2-hour TTL):

| Tier | Required Before | Must Call First |
|------|----------------|-----------------|
| `direct` | Content writes (page:create, element:add, etc.) | `get_site_info` + `global_class:list` |
| `design` | `build_from_schema` | `propose_design` Phase 2 (with design_plan) |

Missing prerequisites return HTTP 422 with list of required calls.

---

## 3. Design Build Pipeline

**Files**: `Services/ProposalService.php`, `Handlers/BuildHandler.php`, `Services/SchemaSkeletonGenerator.php`

Server-enforced 4-step flow for complex sections:

### Step 1 — DISCOVER
`propose_design(page_id, description)` without `design_plan`

Returns: element capabilities, building rules (adapted to site state), available layouts, reference patterns, site classes/variables, design briefs. No `proposal_id` — cannot skip to build.

### Step 2 — DESIGN
`propose_design(page_id, description, design_plan)` with structured plan

AI provides: section_type, layout, background, elements list, patterns. Returns: `proposal_id` + `suggested_schema`. Proposal expires after 10 minutes.

### Step 3 — BUILD
`build_from_schema(proposal_id, schema)`

Pipeline: validate schema > resolve class intents > expand patterns > generate Bricks elements > auto-snapshot > write to page. Returns element counts, classes created/reused, tree summary.

### Step 4 — VERIFY
`verify_build(page_id)`

Read-back: element counts, type distribution, classes used, labels. Compare against design intent.

### Adaptive Building Rules

The discovery response adapts based on site state:
- **Site has design system** (>5 classes or variables configured): "DO NOT set section padding, container gap, heading typography — design system handles it"
- **Fresh site**: "Set explicit values or use starter classes"

Element capabilities (section, container, heading, text-basic rules) follow the same pattern.

---

## 4. Confirmation System

**Files**: `MCP/Router.php`, `MCP/Services/PendingActionService.php`

Destructive operations (delete, bulk delete) require two calls:

1. Handler returns `bricks_mcp_confirm_required` error with action description
2. Router intercepts, generates one-time token (16-char hex, 120s TTL via PendingActionService)
3. AI client calls `confirm_destructive_action(token)`
4. Router validates token, re-dispatches handler with `confirm: true` injected

---

## 5. Page Operations

**Files**: `Handlers/PageHandler.php`, `Handlers/Page/*SubHandler.php`

| Action | Sub-handler | Key behavior |
|--------|-------------|-------------|
| list, search, get, describe_section | PageReadSubHandler | 4 view modes: detail, summary, context, describe |
| create, update_meta, delete, duplicate | PageCrudSubHandler | Delete requires confirmation token |
| update_content, append_content, import_clipboard | PageContentSubHandler | Clipboard import flattens global class styles |
| get_seo, update_seo | PageSeoSubHandler | Supports Yoast, Rank Math, native |
| get_settings, update_settings | PageSettingsSubHandler | Bricks page-level settings |
| snapshot, restore, list_snapshots | PageSnapshotSubHandler | Auto-snapshot before builds |

---

## 6. Global Classes

**File**: `Handlers/GlobalClassHandler.php`, `Services/GlobalClassService.php`

CRUD + batch operations + CSS import/export. Classes stored in `bricks_global_classes` WordPress option.

Key flows:
- **batch_create** — validate names, generate IDs, sanitize styles, write all at once
- **import_css** — parse CSS selectors, map media queries to Bricks breakpoints, create classes
- **semantic_search** — keyword scoring heuristic for finding classes by description
- **apply/remove** — add/remove class IDs from elements' `_cssGlobalClasses` array

---

## 7. Handler Map

All handlers register tools via `ToolRegistry`. Each tool has a JSON Schema for input validation.

| Handler | Tools | Domain |
|---------|-------|--------|
| BricksToolHandler | get_site_info, bricks (settings/breakpoints/schemas/notes) | Site metadata |
| PageHandler | page (list/search/get/create/update/delete/seo/settings/snapshots) | Page CRUD |
| ElementHandler | element (add/update/remove/move/bulk_add/bulk_update/find/duplicate) | Element CRUD |
| BuildHandler | build_from_schema | Design pipeline build step |
| ProposalHandler | propose_design | Design pipeline discovery/proposal |
| VerifyHandler | verify_build | Design pipeline verification |
| GlobalClassHandler | global_class (CRUD/batch/import/export/search/apply/remove) | CSS classes |
| DesignSystemHandler | theme_style, typography_scale, color_palette, global_variable | Design tokens |
| TemplateHandler | template, template_condition, template_taxonomy | Templates |
| ComponentHandler | component (CRUD/instantiate/properties/slots) | Reusable components |
| MediaHandler | media (unsplash/sideload/list/featured) | Images |
| MenuHandler | menu (CRUD/items/assign) | Navigation |
| CodeHandler | code (page CSS/JS) | Custom code |
| FontHandler | font (status/settings) | Typography |
| WooCommerceHandler | woocommerce (status/elements/tags/scaffold) | WooCommerce |
| WordPressHandler | wordpress (posts/users/plugins) | WordPress data |
| MetaBoxHandler | metabox (fields/values/tags) | Meta Box integration |
| SchemaHandler | Element schemas, dynamic tags, query types, popups, forms | Reference data |
| DesignPatternHandler | design_pattern (CRUD/export/import) | Section patterns |
| OnboardingHandler | get_onboarding_guide | Session onboarding |
| PageLayoutHandler | propose_page_layout | Full-page section sequencing |

---

## 8. Key Constants & Configuration

### Plugin Constants (bricks-mcp.php)
| Constant | Value | Purpose |
|----------|-------|---------|
| `BRICKS_MCP_VERSION` | `3.16.0` | Plugin version |
| `BRICKS_MCP_MIN_PHP_VERSION` | `8.2` | PHP requirement |
| `BRICKS_MCP_GITHUB_REPO` | `alexandruradulescu-neurony/bricks-mcp` | GitHub repo slug |

### Optional Constants (wp-config.php)
| Constant | Purpose |
|----------|---------|
| `BRICKS_MCP_GITHUB_TOKEN` | GitHub API token for authenticated update checks |

### Filter Hooks
| Filter | Default | Purpose |
|--------|---------|---------|
| `bricks_mcp_keepalive_interval` | 25 | SSE keepalive seconds (clamped 5-55) |
| `bricks_mcp_prerequisite_ttl` | 7200 | Prerequisite flag TTL seconds |
| `bricks_mcp_max_body_size` | 1MB | Max request body |
| `bricks_mcp_sse_timeout` | 1800 | SSE stream timeout |
| `bricks_mcp_tools` | all tools | Filter registered tools |
| `bricks_mcp_diagnostic_checks` | all checks | Filter diagnostic checks |
| `bricks_mcp_known_hosting_providers` | 7 providers | Hosting detection list |
| `bricks_mcp_known_security_plugins` | 9 plugins | Security plugin detection list |
| `bricks_mcp_intent_map` | 9 intents | Page layout intent map |
| `bricks_mcp_condition_schema` | Bricks conditions | Condition reference catalog |
| `bricks_mcp_form_schema` | Form fields | Form reference catalog |
| `bricks_mcp_interaction_schema` | Interactions | Interaction reference catalog |
| `bricks_mcp_filter_schema` | Filters | Filter reference catalog |

### Named Constants (internal)
| Constant | Value | Location |
|----------|-------|----------|
| `BricksCore::META_KEY` | `_bricks_page_content_2` | Bricks content meta key |
| `Router::DESIGN_GATE_THRESHOLD` | 8 | Max elements before design pipeline required |
| `RateLimiter::DEFAULT_RPM` | 120 | Default requests per minute |
| `StreamableHttpHandler::MAX_BATCH_SIZE` | 20 | Max JSON-RPC messages per batch |
| `StreamableHttpHandler::MAX_BODY_HARD_LIMIT` | 10MB | Absolute max request body |

---

## 9. File Structure

```
bricks-mcp/
  bricks-mcp.php              Entry point, constants, version checks
  uninstall.php                Cleanup on plugin deletion
  includes/
    Plugin.php                 Singleton, init, migrations
    Activator.php              Activation checks
    Deactivator.php            Deactivation cleanup
    Autoloader.php             PSR-4 autoloader
    I18n.php                   Internationalization
    Admin/
      Settings.php             Admin UI (1900+ lines)
      DesignSystemAdmin.php    Design system generator UI
      PatternsAdmin.php        Pattern manager UI
      DiagnosticRunner.php     Health check runner
      SiteHealth.php           WP Site Health integration
      Checks/                  11 diagnostic check classes
    MCP/
      Server.php               REST endpoint, auth, rate limit
      StreamableHttpHandler.php SSE transport
      Router.php               Tool dispatch, gates, confirmation
      ToolRegistry.php         Tool storage and lookup
      RateLimiter.php          Per-user/IP rate limiting
      Response.php             JSON-RPC response formatting
      Handlers/                20+ tool handler classes
      Services/                34+ service classes
      Reference/               4 static reference catalogs
    Updates/
      UpdateChecker.php        GitHub Releases update checker
  data/
    element-defaults.json      Default settings per element type
    element-hierarchy-rules.json  Parent/child nesting rules
    class-context-rules.json   Class intent resolution rules
    css-property-map.json      Bricks key → CSS property mapping
```
