# WordPress MCP Server for Bricks — Architecture Overview

Technical architecture of the Bricks MCP plugin (v2.10.0). This document describes how the plugin is structured, how requests flow through it, and how the major subsystems work together.

For security details, see [SECURITY.md](SECURITY.md). For the Bricks data model internals, see [BRICKS_DATA_MODEL_DEEP_DIVE.md](BRICKS_DATA_MODEL_DEEP_DIVE.md).

---

## 1. High-Level Architecture

The plugin implements a Model Context Protocol (MCP) server as a WordPress plugin. It exposes 23 tools that allow AI assistants to read and write Bricks Builder content, manage WordPress resources, and build pages from declarative design schemas.

```
AI Client (Claude Desktop / Claude Code)
    |
    | Streamable HTTP (JSON-RPC 2.0)
    v
WordPress REST API
    |
    v
StreamableHttpHandler — session management, SSE transport
    |
    v
Server — authentication, rate limiting, JSON-RPC dispatch
    |
    v
Router (1052 lines) — tool resolution, prerequisite gating, design build gate
    |
    v
ToolRegistry — tool definitions and input schemas
    |
    v
Handlers (16 files) — tool execution logic
    |
    v
Services (26 files) — business logic, Bricks/WordPress APIs
```

### Transport

The plugin uses **Streamable HTTP** as its MCP transport. The `StreamableHttpHandler` (593 lines) manages:

- REST API route registration (`/wp-json/bricks-mcp/v1/mcp`)
- Session creation and resumption via `Mcp-Session-Id` headers
- Server-Sent Events (SSE) streaming for responses
- JSON-RPC 2.0 message parsing and dispatch
- Server capability advertisement (`tools/list`, `initialize`)
- Output buffering control and keep-alive for long-running operations

There is no stdio transport. All communication is HTTP-based through WordPress's REST infrastructure.

### Authentication

All requests are authenticated using WordPress Application Passwords (HTTP Basic Auth). The `Server` class (337 lines) handles:

- Permission checks (`manage_options` capability required)
- Rate limiting via `RateLimiter` (configurable 10-1000 RPM per user)
- JSON-RPC method dispatch to the Router

---

## 2. Request Flow

### Tool Call Lifecycle

1. **Client sends** a JSON-RPC `tools/call` request over HTTP POST
2. **StreamableHttpHandler** validates the session, opens an SSE stream
3. **Server** authenticates the user, checks rate limits, dispatches to Router
4. **Router** resolves the tool name, checks prerequisite gates, invokes the handler
5. **Handler** validates input, calls service methods, returns result
6. **Router** wraps the result in MCP response format
7. **StreamableHttpHandler** sends the response as an SSE event, closes the stream

### Intent Router and Prerequisite Gating

The Router classifies every request and enforces tiered prerequisites via `PrerequisiteGateService`:

| Workflow | Example Operations | Required Context |
|---|---|---|
| **Direct** | Edit text, move elements, swap images | Site info |
| **Instructed** | bulk_add, append_content | Site info + global classes |
| **Design** | build_from_schema | Site info + global classes + CSS variables |

The server instructions (embedded in `StreamableHttpHandler`) tell the AI client which workflow applies and what prerequisites to gather before attempting writes. This prevents the AI from building pages without understanding the site's design system.

### Design Build Gate

The Router enforces a **design build gate**: when an AI attempts to use `page:append_content`, `page:update_content`, `element:bulk_add`, or `page:create` with section-level elements or more than 8 elements, the request is rejected with a message redirecting to `build_from_schema`. This ensures large layouts always go through the design pipeline for consistent, high-quality output.

The gate can be bypassed with `bypass_design_gate: true` for legitimate direct operations.

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
│   │   ├── StreamableHttpHandler.php  # MCP transport (593 lines)
│   │   ├── Server.php                 # Auth, rate limiting, dispatch (337 lines)
│   │   ├── Router.php                 # Tool routing, gates (1052 lines)
│   │   ├── ToolRegistry.php           # Tool definitions (118 lines)
│   │   ├── RateLimiter.php            # Per-user rate limiting
│   │   ├── Response.php               # MCP response formatting
│   │   ├── Handlers/                  # 16 tool handler files
│   │   └── Services/                  # 26 service files
│   └── Updates/
│       └── UpdateChecker.php          # GitHub-based update checks
├── data/
│   ├── element-defaults.json          # Default settings per element type
│   ├── element-hierarchy-rules.json   # Valid parent/child relationships
│   └── class-context-rules.json       # Contextual class suggestions
├── docs/
│   ├── knowledge/                     # 8 domain-specific knowledge fragments
│   │   ├── building.md
│   │   ├── forms.md
│   │   ├── dynamic-data.md
│   │   ├── components.md
│   │   ├── popups.md
│   │   ├── woocommerce.md
│   │   ├── animations.md
│   │   └── seo.md
│   └── *.md                           # Architecture and reference docs
├── admin/                             # Admin CSS and JS assets
└── languages/                         # Translation files
```

---

## 4. Handlers

Each handler file manages one tool (or a group of related actions). The Router dispatches to handlers based on tool name.

| Handler | Tool Name | Responsibility |
|---|---|---|
| **PageHandler** | `page` | CRUD for pages/posts, content views, snapshots, SEO |
| **ElementHandler** | `element` | Add/update/remove/move/find elements, bulk operations |
| **GlobalClassHandler** | `global_class` | CSS class CRUD, batch ops, import CSS/JSON, categories |
| **DesignSystemHandler** | `global_variable`, `color_palette`, `typography_scale`, `theme_style` | CSS variables, color palettes, type scales, theme styles |
| **BricksToolHandler** | `bricks` | Enable/disable Bricks, settings, schemas, dynamic tags, queries, notes, knowledge |
| **BuildHandler** | `build_from_schema` | Declarative design pipeline orchestration |
| **TemplateHandler** | `template`, `template_condition`, `template_taxonomy` | Template CRUD, display conditions, tags/bundles |
| **ComponentHandler** | `component` | Bricks components: create, instantiate, properties, slots |
| **SchemaHandler** | `get_site_info`, `confirm_destructive_action` | Site info/diagnostics, destructive action confirmation |
| **CodeHandler** | `code` | Page CSS and JavaScript (dangerous actions gated) |
| **MediaHandler** | `media` | Unsplash search, image sideload, featured images |
| **MenuHandler** | `menu` | WordPress menus and locations |
| **FontHandler** | `font` | Adobe Fonts, webfont loading settings |
| **WordPressHandler** | `wordpress` | Posts, users, plugins |
| **MetaBoxHandler** | `metabox` | Meta Box field groups, values, dynamic tags |
| **WooCommerceHandler** | `woocommerce` | WC status, elements, dynamic tags, template scaffolding |

---

## 5. The `build_from_schema` Pipeline

The design pipeline is the primary mechanism for building page layouts. It translates a declarative design schema into fully realized Bricks elements.

### Pipeline Stages

```
Design Schema (JSON)
    |
    v
1. DesignSchemaValidator — validates target, sections, structure nodes
    |
    v
2. ClassIntentResolver — matches class_intent strings to existing global classes,
                          creates new classes with styles when no match found,
                          applies contextual class suggestions from class-context-rules.json
    |
    v
3. SchemaExpander — expands ref/repeat/data patterns into concrete element trees,
                     performs data substitution ("data.title" -> actual values)
    |
    v
4. ElementSettingsGenerator — converts structure nodes into Bricks element settings,
                               applies element-defaults.json,
                               generates element IDs (ElementIdGenerator),
                               sets parent/child linkage,
                               handles responsive_overrides per breakpoint,
                               dark section auto-colors (light text in dark sections),
                               resolves CSS variables via SiteVariableResolver
    |
    v
5. BuildHandler — validates hierarchy against element-hierarchy-rules.json,
                   creates auto-snapshot of existing content,
                   writes final elements to the page via PageOperationsService,
                   returns element tree summary
```

### Schema Format

The schema is documented in the `build_from_schema` tool description (served to AI clients). Key concepts:

- **target**: page_id, action (append/replace), optional parent_id
- **design_context**: summary, mood, spacing hints
- **sections**: array of section intents with nested structure trees
- **patterns**: reusable node templates referenced by `ref`/`repeat`/`data`
- **structure nodes**: type, content, tag, class_intent, layout, columns, responsive, style_overrides, children

### Data Files

Three JSON files in `data/` drive pipeline behavior:

- **element-defaults.json** — Default settings applied to each element type (e.g., heading gets tag "h2", image gets caption "none")
- **element-hierarchy-rules.json** — Valid parent/child relationships (e.g., section can only be at root, container must be inside section)
- **class-context-rules.json** — Maps element types and section contexts to suggested global classes

---

## 6. Knowledge System

The plugin serves domain-specific knowledge on demand via `bricks:get_knowledge(domain)`. Eight markdown files in `docs/knowledge/` cover:

| Domain | Content |
|---|---|
| `building` | General rules: hierarchy, classes, labels, semantic HTML, CSS variables |
| `forms` | Bricks form element configuration, validation, actions |
| `dynamic-data` | Dynamic data tags, query loops, template context |
| `components` | Bricks components: properties, slots, instances |
| `popups` | Popup templates, triggers, settings |
| `woocommerce` | WC template types, product elements, dynamic tags |
| `animations` | CSS animations, interactions, scroll-based effects |
| `seo` | SEO plugin integration, meta tags, Open Graph |

This replaced the monolithic `BUILDER_GUIDE.md` (2400 lines) which was previously served in full to every AI session. The modular system reduces token usage by serving only the knowledge relevant to the current task.

---

## 7. Key Services

### Content Operations

- **PageOperationsService** — Page content reads/writes, snapshot management, content views (detail/summary/context/describe)
- **ElementNormalizer** — Normalizes element arrays (ID generation, parent/child linkage, settings cleanup)
- **ElementIdGenerator** — Generates unique 6-character alphanumeric element IDs
- **ValidationService** — Input validation, element count safety checks, content wipe prevention

### Design System

- **GlobalClassService** — Global class CRUD with style support, batch operations, CSS/JSON import
- **GlobalVariableService** — CSS variable and category management
- **ColorPaletteService** — Color palette and individual color management
- **ThemeStyleService** — Site-wide theme style management
- **TypographyScaleService** — Typography scale with CSS variable generation

### Build Pipeline

- **DesignSchemaValidator** — Schema validation against expected format
- **ClassIntentResolver** — Semantic class matching and creation with styles
- **SchemaExpander** — Pattern expansion and data substitution
- **ElementSettingsGenerator** — Structure-to-settings conversion with defaults and responsive handling
- **SiteVariableResolver** — Resolves palette color references to CSS variables

### Infrastructure

- **PrerequisiteGateService** — Enforces per-workflow context prerequisites
- **PendingActionService** — Token-based destructive action confirmation
- **RequestCounterService** — Request counting for admin dashboard
- **NotesService** — AI-writable notes for cross-session context
- **BricksCore** — Low-level Bricks API wrapper (element registry, global settings)
- **SchemaGenerator** — Converts Bricks element controls to simplified JSON schemas

---

## 8. Auto-Snapshot System

Before any content write operation (`page:update_content`, `page:append_content`, `build_from_schema`), the plugin automatically creates a snapshot of the existing page content. Snapshots are stored as post meta and can be listed and restored via the `page` tool.

This provides a safety net: if an AI build produces unwanted results, the previous state can be restored without manual intervention.

---

## 9. Destructive Action Confirmation

Operations that delete or replace content require a two-step confirmation flow:

1. The tool returns a confirmation token (16-character hex string) with a description of the action
2. The AI must call `confirm_destructive_action` with the token to proceed
3. Tokens expire after 2 minutes and can only be used once

This prevents accidental mass deletions from AI agent loops. The `PendingActionService` manages token generation, storage, and validation.

---

## 10. Admin Interface

The plugin settings page (`Settings > Bricks MCP`) provides:

- **Connection status badge** — Shows whether the MCP endpoint is reachable
- **Getting started checklist** — Guides new users through setup
- **Request counter** — Displays tool call counts
- **Configuration options** — Authentication toggle, rate limit, dangerous actions toggle, Unsplash API key
- **Diagnostics** — 10 automated checks (HTTPS, Application Passwords, REST API, Bricks active, permalinks, PHP timeout, hosting provider, security plugins, endpoint reachability, user app passwords)

---

## 11. Update System

The `UpdateChecker` polls the GitHub repository for new releases and integrates with the WordPress plugin update system. Users see available updates in the standard Plugins screen and can update with one click.
