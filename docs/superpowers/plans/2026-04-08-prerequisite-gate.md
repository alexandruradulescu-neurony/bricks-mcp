# Prerequisite Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enforce that AI assistants call get_site_info, global_class:list, and global_variable:list before any content write operation.

**Architecture:** A lightweight PrerequisiteGateService stores per-user flags in a WP transient with 30-min TTL. The Router checks the gate before dispatching gated operations. Dynamic instructions and builder guide are updated to reflect the enforced 3-step flow.

**Tech Stack:** PHP 8.2, WordPress transients API, existing MCP Router pattern.

---

### Task 1: Create PrerequisiteGateService

**Files:**
- Create: `includes/MCP/Services/PrerequisiteGateService.php`

- [ ] **Step 1: Create the service file**

```php
<?php
/**
 * Prerequisite gate service.
 *
 * Tracks which mandatory tool calls have been made per user session
 * and gates content write operations behind them.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PrerequisiteGateService class.
 *
 * Uses a WP transient keyed by user ID to track which prerequisite
 * calls have been made. Flags expire after 30 minutes of inactivity.
 */
final class PrerequisiteGateService {

	/**
	 * Transient TTL in seconds (30 minutes).
	 */
	private const TTL = 1800;

	/**
	 * Valid flag names.
	 */
	private const VALID_FLAGS = [ 'site_info', 'classes', 'variables' ];

	/**
	 * Human-readable tool names for each flag (used in error messages).
	 */
	private const FLAG_TOOL_NAMES = [
		'site_info' => 'get_site_info',
		'classes'   => 'global_class:list',
		'variables' => 'global_variable:list',
	];

	/**
	 * Get the transient key for the current user.
	 *
	 * @return string
	 */
	private static function transient_key(): string {
		return 'bricks_mcp_prereqs_' . get_current_user_id();
	}

	/**
	 * Set a prerequisite flag.
	 *
	 * @param string $flag One of: site_info, classes, variables.
	 */
	public static function set_flag( string $flag ): void {
		if ( ! in_array( $flag, self::VALID_FLAGS, true ) ) {
			return;
		}

		$flags = get_transient( self::transient_key() );
		if ( ! is_array( $flags ) ) {
			$flags = [];
		}

		$flags[ $flag ] = true;
		set_transient( self::transient_key(), $flags, self::TTL );
	}

	/**
	 * Check if all prerequisites are met.
	 *
	 * @return true|array{missing: string[], satisfied: string[], missing_tools: string[]}
	 *               True if all flags set, or array with missing/satisfied details.
	 */
	public static function check(): true|array {
		$flags = get_transient( self::transient_key() );
		if ( ! is_array( $flags ) ) {
			$flags = [];
		}

		$missing   = [];
		$satisfied = [];
		$missing_tools = [];

		foreach ( self::VALID_FLAGS as $flag ) {
			if ( ! empty( $flags[ $flag ] ) ) {
				$satisfied[] = $flag;
			} else {
				$missing[]       = $flag;
				$missing_tools[] = self::FLAG_TOOL_NAMES[ $flag ];
			}
		}

		if ( empty( $missing ) ) {
			return true;
		}

		return [
			'missing'       => $missing,
			'satisfied'     => $satisfied,
			'missing_tools' => $missing_tools,
		];
	}

	/**
	 * Reset all flags (e.g. on session termination).
	 */
	public static function reset(): void {
		delete_transient( self::transient_key() );
	}
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/MCP/Services/PrerequisiteGateService.php
git commit -m "feat: add PrerequisiteGateService for tracking mandatory prerequisites"
```

---

### Task 2: Set flags in Router when prerequisites are called

**Files:**
- Modify: `includes/MCP/Router.php:599` (tool_get_site_info)
- Modify: `includes/MCP/Router.php:418` (execute_tool — for global_class and global_variable list detection)

- [ ] **Step 1: Add the import for PrerequisiteGateService**

At the top of `Router.php`, after line 18 (`use BricksMCP\MCP\Services\ValidationService;`), add:

```php
use BricksMCP\MCP\Services\PrerequisiteGateService;
```

- [ ] **Step 2: Set the site_info flag in tool_get_site_info**

In `Router.php`, inside `tool_get_site_info()`, right after line 600 (`$action = $args['action'] ?? 'info';`), add the flag for the `info` action:

```php
		if ( 'info' === $action ) {
			PrerequisiteGateService::set_flag( 'site_info' );
		}
```

Note: Only set flag on `info` action, not `diagnose`.

- [ ] **Step 3: Set class and variable flags in execute_tool**

In `Router.php`, inside `execute_tool()`, after the successful `call_user_func` on line 455 (inside the try block, after the `is_wp_error` check on line 457-463), add flag detection before the return. Replace the block starting at line 465:

```php
			// Set prerequisite flags for gate tracking.
			if ( 'global_class' === $name && ( $arguments['action'] ?? '' ) === 'list' ) {
				PrerequisiteGateService::set_flag( 'classes' );
			} elseif ( 'global_variable' === $name && ( $arguments['action'] ?? '' ) === 'list' ) {
				PrerequisiteGateService::set_flag( 'variables' );
			}

			return Response::success(
				array(
					'content' => array(
```

This goes right before the existing `return Response::success(...)` block.

- [ ] **Step 4: Commit**

```bash
git add includes/MCP/Router.php
git commit -m "feat: set prerequisite flags when get_site_info, global_class:list, global_variable:list are called"
```

---

### Task 3: Add gate check before gated operations

**Files:**
- Modify: `includes/MCP/Router.php:418` (execute_tool)

- [ ] **Step 1: Define the list of gated operations**

In the `Router` class, add a class constant after the existing property declarations (around line 39):

```php
	/**
	 * Operations that require all prerequisites to be met.
	 *
	 * Format: tool_name => list of actions that are gated.
	 * An empty array means ALL actions of that tool are gated.
	 *
	 * @var array<string, string[]>
	 */
	private const GATED_OPERATIONS = [
		'page'      => [ 'update_content', 'append_content', 'create', 'import_clipboard' ],
		'element'   => [ 'add', 'bulk_add', 'update', 'bulk_update' ],
		'template'  => [ 'create' ],
		'component' => [ 'create', 'update', 'instantiate', 'fill_slot' ],
	];
```

- [ ] **Step 2: Add the gate check in execute_tool**

In `execute_tool()`, after the capability check (after line 442, before the argument validation on line 444), add:

```php
		// Prerequisite gate: block content writes unless mandatory calls have been made.
		if ( $this->is_gated_operation( $name, $arguments ) ) {
			$gate_result = PrerequisiteGateService::check();
			if ( true !== $gate_result ) {
				$missing_tools = $gate_result['missing_tools'];
				return Response::error(
					'bricks_mcp_prerequisites_not_met',
					sprintf(
						'You must call these tools before modifying content: %s. Call them now, then retry.',
						implode( ', ', $missing_tools )
					),
					422,
					[
						'missing'   => $gate_result['missing'],
						'satisfied' => $gate_result['satisfied'],
					]
				);
			}
		}
```

- [ ] **Step 3: Add the is_gated_operation helper method**

Add this private method to the Router class (after `execute_tool`, around line 480):

```php
	/**
	 * Check if a tool call is a gated content write operation.
	 *
	 * @param string               $name      Tool name.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return bool
	 */
	private function is_gated_operation( string $name, array $arguments ): bool {
		if ( ! isset( self::GATED_OPERATIONS[ $name ] ) ) {
			return false;
		}

		$gated_actions = self::GATED_OPERATIONS[ $name ];

		// Empty array means all actions are gated.
		if ( empty( $gated_actions ) ) {
			return true;
		}

		$action = $arguments['action'] ?? '';

		// Special case: page:create and template:create are only gated when elements are provided.
		if ( in_array( $name, [ 'page', 'template' ], true ) && 'create' === $action ) {
			return ! empty( $arguments['elements'] );
		}

		return in_array( $action, $gated_actions, true );
	}
```

- [ ] **Step 4: Check that Response::error supports the data parameter**

Read `includes/MCP/Response.php` to confirm `Response::error()` accepts a 4th parameter for additional data. If it doesn't, pass only 3 arguments and drop the `$data` array.

- [ ] **Step 5: Commit**

```bash
git add includes/MCP/Router.php
git commit -m "feat: gate content write operations behind prerequisite calls"
```

---

### Task 4: Add gotchas to get_site_info response

**Files:**
- Modify: `includes/MCP/Router.php:599-845` (tool_get_site_info)

- [ ] **Step 1: Add gotchas array before the return statement**

In `tool_get_site_info()`, before line 845 (`return $info;`), add:

```php
		// Key gotchas embedded so the AI gets critical rules on the first mandatory call.
		$info['gotchas'] = [
			'_textAlign does nothing — put text-align inside _typography instead.',
			'%root% shorthand works in _cssCustom — auto-replaces with the element selector.',
			'Use _widthMax for max-width, not _maxWidth. For multi-column layouts, use global grid classes with var(--grid-*) variables.',
			'Templates must have publish status to be active.',
			'Icon libraries: Ionicons, Themify, FontAwesome — call bricks:get_element_schemas(element=name) to check available options.',
			'Text supports inline HTML: "<strong>Bold</strong> and normal".',
			'update_element calls are independent — fire them in parallel for speed.',
			'Gradients use _gradient key (separate from _background). Each color entry: {color: {raw: value}, stop: "percentage"}.',
			'Dynamic tags ({post_title}, {post_url}) only work inside query loops or single post templates.',
			'Image dynamic data: {useDynamicData: "{tag}"}. Link dynamic data: {type: "dynamic", dynamicData: "{tag}"}.',
			'_animation is deprecated since Bricks 1.6 — use _interactions array instead.',
			'Component instance name = component ID (6-char string), not a human-readable type.',
			'Component properties without connections do nothing — set the connections map.',
			'Slot content lives in the page element array (parent = instance_id), not the component definition.',
			'Popup triggers use _interactions on elements. Popup settings (close, backdrop) use template_settings — separate systems.',
			'Always use nestable element variants: tabs-nested, accordion-nested, nav-nested. Basic versions only support plain text.',
			'div needs explicit _display: flex for flex layouts — block and container default to flex, div does not.',
			'Before using ANY unfamiliar element type, ALWAYS call bricks:get_element_schemas(element=name) first.',
			'Do NOT duplicate child theme CSS inline — sections get padding, containers get gap, headings get sizes automatically.',
		];
```

- [ ] **Step 2: Commit**

```bash
git add includes/MCP/Router.php
git commit -m "feat: embed key gotchas in get_site_info response"
```

---

### Task 5: Update dynamic instructions

**Files:**
- Modify: `includes/MCP/StreamableHttpHandler.php:571-592`

- [ ] **Step 1: Replace the mandatory steps and critical reminders block**

Replace lines 573-590 with:

```php
			. "⚠️ MANDATORY FIRST STEP: Before ANY page/template/element creation or modification, you MUST:\n"
			. "1. Call get_site_info - Understand design tokens, child theme CSS, color palette, gotchas, and page summaries\n"
			. "2. Call global_class:list - Discover existing global classes with IDs and settings (if none exist, create them)\n"
			. "3. Call global_variable:list - Discover all CSS variables available for use\n\n"
			. "These are server-enforced — write operations will be REJECTED if you skip them.\n\n"
			. "CRITICAL REMINDERS:\n"
			. "- Global classes: Use _cssGlobalClasses on EVERY element. Create classes with global_class:batch_create if none exist.\n"
			. "- Child theme CSS: Handles section padding, container gaps, heading sizes, text styles. Do NOT duplicate these inline.\n"
			. "- Labels: Add label to every structural element (sections, containers, blocks, divs)\n"
			. "- Semantic HTML: Use tag: 'ul', 'li', 'figure', 'address' on block/div elements\n"
			. "- Element hierarchy: section > container > block/div > content. Use block and div for grouping, not nested containers.\n"
			. "- Inline styles: ONLY for instance-specific overrides (_padding.top: '0', _order: '-1', unique background color)\n"
			. "- Style properties: Use _widthMax NOT _maxWidth, _typography['text-align'] NOT _textAlign\n"
			. "- Unfamiliar elements: Before using ANY element type you haven't used before (accordion, tabs, slider, etc.), ALWAYS call bricks:get_element_schemas(element='element_name') first. Do NOT guess repeater keys or item structure.\n"
			. "- Nestable elements: ALWAYS use tabs-nested, accordion-nested, nav-nested instead of basic tabs, accordion, nav. Basic versions only support plain text.\n"
			. "- Destructive actions: delete operations require confirm: true. Protected pages block all write operations."
```

The key changes:
- Steps reduced from 5 to 3
- Step 5 (page:get describe) removed entirely
- "Reuse patterns" critical reminder removed
- Added "server-enforced" note
- Added global_variable:list as step 3

- [ ] **Step 2: Commit**

```bash
git add includes/MCP/StreamableHttpHandler.php
git commit -m "feat: update dynamic instructions — 3 enforced prerequisites, remove study-existing-pages step"
```

---

### Task 6: Update builder guide

**Files:**
- Modify: `docs/BUILDER_GUIDE.md:2233-2305`

- [ ] **Step 1: Update the "How to Build Any Page" workflow**

Replace lines 2237-2249 with:

```markdown
Follow this sequence for every page you build:

1. **Understand the site** — Call `get_site_info` to learn the design system, color palette, child theme CSS, gotchas, existing pages, and class groups.

2. **Get global classes** — Call `global_class:list` to see all available classes with their IDs and settings. Map class names to component types: heroes, grids, cards, typography, navigation.

3. **Get CSS variables** — Call `global_variable:list` to see all available variables. Use `var(--name)` to reference them. Never hardcode values when a variable exists.

4. **Check the pattern library** — Call `bricks:analyze_patterns` to discover reusable section patterns. If a pattern matches what you need, use `bricks:use_pattern` instead of building from scratch.

5. **Build with classes** — Use `_cssGlobalClasses` on EVERY element. Never inline what a class already handles. The child theme CSS handles section padding, container gaps, heading sizes — do NOT duplicate these.

6. **Verify your work** — After building, call `page:get(view='describe')` on the page you just built. Check that sections have the right background, layout, and content structure.

> **Note:** Steps 1-3 are server-enforced. Write operations will be rejected if you skip them.
```

- [ ] **Step 2: Update "When to Create vs Reuse" section**

Replace lines 2251-2255 with:

```markdown
### When to Create vs Reuse

- **Reuse**: If `analyze_patterns` shows a matching pattern, use `bricks:use_pattern` with text overrides
- **Create**: When no existing pattern matches, build from scratch using existing global classes for all styling. If no classes exist, create them with `global_class:batch_create`.
```

- [ ] **Step 3: Remove "Add a Section Matching Existing Style" recipe**

Delete lines 2297-2305 (the entire recipe that tells the AI to copy from a reference page).

- [ ] **Step 4: Update gotcha #3 variable references**

On line 2210, replace the gotcha text. Change:

```
Grid tokens: `--grid-1` through `--grid-12`, plus ratio variants `--grid-1-2`, `--grid-2-1`, etc.
```

This should already match the site's actual variable names. Verify no `--brxw-` prefixed references remain in the gotchas section.

- [ ] **Step 5: Commit**

```bash
git add docs/BUILDER_GUIDE.md
git commit -m "docs: update builder guide — remove study-existing-pages, add enforced prerequisites note"
```

---

### Task 7: Version bump and release

**Files:**
- Modify: `bricks-mcp.php:14,34`
- Modify: `readme.txt:6`

- [ ] **Step 1: Bump version to 1.9.6**

In `bricks-mcp.php`, change:
- Line 14: `Version: 1.9.5.2` → `Version: 1.9.6`
- Line 34: `define( 'BRICKS_MCP_VERSION', '1.9.5.2' );` → `define( 'BRICKS_MCP_VERSION', '1.9.6' );`

In `readme.txt`, change:
- Line 6: `Stable tag: 1.9.5.2` → `Stable tag: 1.9.6`

- [ ] **Step 2: Commit and tag**

```bash
git add bricks-mcp.php readme.txt
git commit -m "chore: bump version to 1.9.6"
git tag -a v1.9.6 -m "v1.9.6 — prerequisite gate for content write operations"
```

- [ ] **Step 3: Push and release**

```bash
git push origin main --tags
gh release create v1.9.6 --title "v1.9.6" --notes "Server-enforced prerequisite gate: AI must call get_site_info, global_class:list, and global_variable:list before modifying page content. Removed study-existing-pages instruction. Gotchas embedded in get_site_info response."
```
