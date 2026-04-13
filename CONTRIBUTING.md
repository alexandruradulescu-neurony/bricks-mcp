# Contributing to Bricks MCP

Thanks for considering a contribution. This document covers the most common contribution paths and the architectural conventions you'll need to follow.

## Quick reference

- **Architecture overview**: `docs/WordPress MCP Server for Bricks.md`
- **Design build pipeline**: `docs/DESIGN_PIPELINE.md`
- **Security model**: `docs/SECURITY.md`
- **Domain knowledge served to AI**: `docs/knowledge/*.md`

## Coding standards

- PHP 8.2+, `declare(strict_types=1)` at the top of every file
- PSR-4 autoloading via `includes/Autoloader.php` — namespaces match directory structure under `BricksMCP\`
- WordPress sanitization rules apply at all input boundaries (`sanitize_text_field`, `wp_kses`, `absint`, etc.)
- Database access only through WordPress APIs (`get_post_meta`, `update_option`, `WP_Query`) — no raw SQL
- File I/O in the media library only (`download_url`, `media_handle_sideload`)
- Never introduce `eval`, `assert`, `unserialize` on user-controlled data, or shell execution
- Use the existing static cache patterns (see `GlobalClassService::$cached_all`, `DesignPatternService::$all_patterns`) for hot data — avoid uncached `get_option` loops

## Adding a new MCP tool

The Router (`includes/MCP/Router.php`) wires handlers into a single `$handlers` array keyed by short name. To add a new tool:

1. **Create a handler** under `includes/MCP/Handlers/` (e.g. `MyToolHandler.php`)
2. **Implement a `register(ToolRegistry $registry)` method** that calls `$registry->register('tool_name', $description, $input_schema, [$this, 'handle'], $annotations)`. See `VerifyHandler::register()` (`includes/MCP/Handlers/VerifyHandler.php`) as the reference implementation.
3. **Add the handler to `Router::__construct()`** in the `$this->handlers` array
4. **If the tool is Bricks-only**, add its key to `$bricks_handler_keys` in `Router::register_bricks_tools()`. Otherwise it will register on plugin load.
5. **If the tool writes content**, register it in `Router::GATED_OPERATIONS` with the appropriate tier (`direct`, `instructed`, `full`, or `design`)
6. **If the tool is destructive**, return `new \WP_Error('bricks_mcp_confirm_required', $description)` from your handler — the Router intercepts this and issues a confirmation token

## Adding a design pattern

Design patterns live in `data/design-patterns/{category}/{name}.json`. Categories: `heroes`, `splits`, `features`, `ctas`, `pricing`, `testimonials`, `content`.

The pattern shape:

```json
{
  "name": "your-pattern-name",
  "tags": ["tag1", "tag2"],
  "layout": "split-60-40",
  "background": "dark",
  "section_overrides": {},
  "container_overrides": {},
  "columns": {
    "left":  { "alignment": "...", "padding": "...", "gap": "...", "max_width": "...", "elements": [] },
    "right": { "...": "..." }
  },
  "patterns": [ /* embedded repeating structures */ ]
}
```

For multi-row patterns (e.g. hero with badges below), use `has_two_rows: true` with `rows.row_1` and `rows.row_2` instead of `columns`.

`DesignPatternService::load_all()` discovers files automatically — no registration code needed. Add a brief description of your pattern to `docs/DESIGN_PIPELINE.md` under the pattern library table.

## Adding a diagnostic check

1. Create a class under `includes/Admin/Checks/` implementing `DiagnosticCheck`
2. Register it in `DiagnosticRunner::register_defaults()`
3. The check appears automatically in `Settings > Bricks MCP > Diagnostics`

`DesignPipelineCheck.php` is a good reference for what a check looks like.

## Adding starter classes

Edit `StarterClassesService::get_starter_classes()`. Use only CSS variables (`var(--name)`) for portability — never hardcode colors or sizes. The starter set should remain coherent (grids + typography + buttons + cards) and stay around 13 classes to match the bootstrap recommendation in discovery.

## Documentation updates

When you add a feature:

- Update `readme.txt` changelog (WordPress format, used by the plugin update system)
- Update `CHANGELOG.md` (Keep-a-Changelog format, used by GitHub release notes)
- If the feature changes the AI-facing surface area, update `docs/DESIGN_PIPELINE.md` or the relevant `docs/knowledge/*.md`
- If you add or rename a service/handler, update `docs/WordPress MCP Server for Bricks.md`

## Pull request checklist

Before opening a PR:

- [ ] No new files in the plugin root (everything goes under `includes/`, `data/`, `docs/`, `admin/`, or `languages/`)
- [ ] Plugin still activates cleanly on a fresh WordPress install
- [ ] `bricks-mcp.php` version constant + readme.txt `Stable tag` + CHANGELOG.md updated together for releases
- [ ] No untracked `*.zip` build artifacts committed
- [ ] If you touched the design pipeline, run a sample build (`propose_design` → `build_from_schema` → `verify_build`) on a test page

## Reporting bugs and security issues

- Bugs: open a GitHub issue with reproduction steps
- Security vulnerabilities: email **alex@tractarigub.ro** privately first; see `docs/SECURITY.md`
