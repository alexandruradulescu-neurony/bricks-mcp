# Prerequisite Gate for Content Write Operations

## Problem

The MCP server tells AI assistants to call prerequisite tools (get_site_info, global_class:list, etc.) before building pages, but never enforces it. The AI skips steps, uses wrong variable names, guesses element properties, and the server saves broken content. Advisory instructions do not work — enforcement is required.

## Solution

Server-side session state tracking with a hard gate on content write operations. The AI cannot write elements until it has loaded the required context. Three mandatory prerequisites, enforced by the server.

## Prerequisites (all 3 required before content writes)

1. **`get_site_info`** — design tokens, child theme CSS, color palette, AI notes, key gotchas, page summaries
2. **`global_class:list`** — all global classes with IDs and full settings
3. **`global_variable:list`** — all CSS variables with names and values

## Session State Tracking

### PrerequisiteGateService

New service: `includes/MCP/Services/PrerequisiteGateService.php`

**Storage:** WP transient `bricks_mcp_prereqs_{user_id}` with 30-minute TTL.

**Value:** Associative array of boolean flags:
```php
[
    'site_info' => true,
    'classes'   => true,
    'variables' => true,
]
```

**API:**

```php
PrerequisiteGateService::set_flag( string $flag ): void
```
Sets one flag in the transient. Refreshes the 30-minute TTL on every call. Valid flags: `site_info`, `classes`, `variables`.

```php
PrerequisiteGateService::check(): array|true
```
Returns `true` if all 3 flags are set. Otherwise returns an array of missing flag names.

```php
PrerequisiteGateService::reset(): void
```
Clears all flags (used on session termination / DELETE request).

### Where flags are set

| Tool call | Flag set |
|---|---|
| `get_site_info` (action: info) | `site_info` |
| `global_class:list` | `classes` |
| `global_variable:list` | `variables` |

Flags are set in `Router.php` immediately after the tool call succeeds, before returning the response.

## Gated Operations

### Require all 3 prerequisites

- `page:update_content`, `page:append_content`, `page:create` (when `elements` param is present), `page:import_clipboard`
- `element:add`, `element:bulk_add`, `element:update`, `element:bulk_update`
- `template:create` (when `elements` param is present)
- `component:create`, `component:update`, `component:instantiate`, `component:fill_slot`

### NOT gated

- All read operations (page:get, page:list, element:find, etc.)
- Structural operations without element settings (element:remove, element:move, element:duplicate)
- Meta operations (page:update_meta, page:update_settings, page:update_seo)
- Delete operations (page:delete, template:delete, etc.)
- Snapshots (page:snapshot, page:restore)
- All global_class:*, global_variable:*, color_palette:*, typography_scale:* operations
- get_site_info, get_builder_guide, bricks:* read actions

### Gate error response

```json
{
    "code": "bricks_mcp_prerequisites_not_met",
    "message": "You must call these tools before modifying content: get_site_info, global_variable:list. Call them now, then retry.",
    "data": {
        "missing": ["site_info", "variables"],
        "satisfied": ["classes"]
    }
}
```

The error names the exact missing tools so the AI can call them and retry.

### Gate check location

In `Router.php`, before dispatching to the handler for any gated operation. Single check point — not scattered across handlers.

## Instruction Changes

### Dynamic instructions (StreamableHttpHandler.php)

**Replace** the current 5-step mandatory block with:

```
MANDATORY FIRST STEP: Before ANY page/template/element creation or modification, you MUST:
1. Call get_site_info - Understand design tokens, child theme CSS, color palette, gotchas, and page summaries
2. Call global_class:list - Discover existing global classes with IDs and settings (if none exist, create them)
3. Call global_variable:list - Discover all CSS variables available for use

These are server-enforced — write operations will be rejected if you skip them.
```

**Remove:**
- Step 5: `Call page:get(view='describe') on a similar existing page - Study patterns before building`
- Critical reminder: `Reuse patterns: Check existing pages with page:get(view='describe') before building new sections. Match the site's existing style.`

### get_site_info response

Add a `gotchas` field containing the key gotchas as a compact array of strings. This ensures the AI gets critical rules with its first prerequisite call without needing to call get_builder_guide separately.

```json
{
    "name": "...",
    "child_theme_css": "...",
    "color_palette": {},
    "ai_notes": [],
    "gotchas": [
        "_textAlign does nothing — put text-align inside _typography instead",
        "Use _widthMax for max-width, not _maxWidth",
        "For multi-column layouts, use global grid classes with var(--grid-*) variables",
        "..."
    ]
}
```

### Builder guide (docs/BUILDER_GUIDE.md)

**Workflow section — remove:**
- Step 2: "Study existing patterns — Call page:get(view='describe') on 2-3 existing pages..."
- "When to Create vs Reuse" subsection reference to studying other pages
- "Add a Section Matching Existing Style" recipe (the one that tells the AI to copy from reference pages)

**Gotcha #3 — update:**
Remove wireframe-specific variable prefixes. Reference the site's actual variable names: `--grid-1` through `--grid-12`, `--grid-gap`, etc.

**Keep:**
- Pattern library recipe (analyze_patterns / use_pattern) — this is automated matching, not manual page browsing
- Builder guide as optional reference for forms, components, popups, etc.

## Files Changed

| File | Changes |
|---|---|
| `includes/MCP/Services/PrerequisiteGateService.php` | **New** — flag tracking + gate check |
| `includes/MCP/Router.php` | Set flags on prerequisite calls; check gate before gated operations |
| `includes/MCP/StreamableHttpHandler.php` | Update dynamic instructions (3 steps, enforced, remove step 5 + reuse reminder) |
| `includes/MCP/Services/BricksService.php` | Add `gotchas` array to get_site_info response |
| `docs/BUILDER_GUIDE.md` | Remove "study existing patterns" step, remove copy-from-page recipe, fix gotcha #3 |

## What This Does NOT Cover

- **Element validation** (checking class IDs exist, variable names are valid) — future phase
- **Schema enforcement** (checking element properties match schema) — future phase
- **Response size optimization** (compact class/variable lists) — not needed now that variables are 126 items and classes will be fresh

## Success Criteria

1. AI calls `page:append_content` without prerequisites → receives `bricks_mcp_prerequisites_not_met` error listing missing calls
2. AI calls all 3 prerequisites then `page:append_content` → succeeds normally
3. After 30 minutes of inactivity, prerequisites expire and must be called again
4. Dynamic instructions show 3 mandatory steps with "server-enforced" note
5. `get_site_info` response includes `gotchas` array
6. Builder guide no longer instructs AI to study existing pages before building
