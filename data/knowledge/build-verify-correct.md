# Build → verify → correct loop

A reliable build is rarely a single tool call. Today's pipeline (resolve, normalize, write) catches most issues, but silent CSS drops still happen — a `style_overrides` value that the normalizer can't fit into a known Bricks shape disappears without a warning. The remedy: verify after every build, and correct in place when verify finds drift.

## The loop

```
build_from_html OR build_structure
        │
        ▼
   verify_build  ── always — element-data signal
        │
        ▼
  verification_plan in response  ── if AI client has Playwright MCP
        │
        ▼
  navigate + browser_evaluate(snippet)
        │
        ▼
  compare to expected_features + design intent
        │
        ▼
  if drift detected ─→ element:bulk_update / element:update (Phase D doc)
        │                or, if structural fix needed:
        │                element:remove + build_from_html replace
        ▼
  re-verify (max 2 iterations total — stop guessing past that)
```

## Step 1 — Build

Either tool works:

- `build_from_html` for content sections (heroes, features, CTAs)
- `build_structure` for layouts that need patterns, components, query loops, or popups

Both write through the same downstream pipeline (resolve → normalize → validate → write).

## Step 2 — Element-data verify (always)

Call `verify_build(page_id, section_id)`. This runs without external tooling and surfaces:

- `element_count`, `type_counts` — did the right number of elements land?
- `classes_used` — did your class_intents resolve to actual global class names?
- `quality_checks` — duplicate role labels, missing class IDs, empty classes, missing variables
- `content_sample` — actual text rendered (catches placeholder-text drift)
- `content_contract` — required roles + whether they have content
- `verification_plan` — see step 3

Check `quality_checks.warnings`. Anything non-empty deserves a follow-up.

## Step 3 — Live render verify (when Playwright is available)

If you have access to the Playwright MCP (or any headless browser tool), use `verification_plan` from step 2:

```
verification_plan: {
  page_url: "https://site.tld/page/",
  section_selector: "#brxe-abc123",
  expected_features: [
    "page renders 200 OK at https://site.tld/page/",
    "section element #brxe-abc123 exists in the rendered DOM",
    "section computed padding-top > 0",
    "section computed background-color or background-image is non-default",
    "6 global class(es) appear in element class chains: hero__heading, ...",
    "1 heading element(s) render with non-empty text",
    "1 image element(s) have a resolved src (not literal \"unsplash:*\")",
    "2 clickable element(s) carry a usable href"
  ],
  evaluate_snippet: "() => { ... }",
  evaluate_usage: "Pass evaluate_snippet to the Playwright MCP browser_evaluate tool ..."
}
```

Workflow:

1. `playwright__browser_navigate(url=verification_plan.page_url)`
2. `playwright__browser_evaluate(function=verification_plan.evaluate_snippet)`
3. Inspect the returned object: `root` (the section), `descendants` (children up to 60), each carrying tag, classes, computed `styles`, text snippet, image src
4. Score against `expected_features` and your design intent

Common drifts the snippet catches that element-data doesn't:

- `padding: 0` on a section that was supposed to have `padding-section` — typically caused by per-side variables that resolve to multi-value shorthands
- `background-image: none` when a gradient was intended — Bricks gradient field shape mismatch
- Class ID applied but `style_overrides` color/text-align dropped silently
- `aspect-ratio: auto` when `4/3` was intended
- Image present but with literal `src="unsplash:..."` (sideload didn't run)
- Layout collapsed because grid template columns dropped

## Step 4 — Correct

When you find drift, prefer the smallest-blast-radius tool:

| Drift | Fix tool |
|---|---|
| Wrong color, font-size, padding, alignment | `element:update` with the corrected `_typography` / `_padding` / etc. block |
| Multiple elements share the same fix | `element:bulk_update` (deep-merges per element) |
| Class missing styles entirely | `global_class:update` to fix the class itself |
| Wrong gradient field shape | `element:update` with `_background.image.gradient` (Bricks reads gradients there) |
| Wrong padding-section variable use | `element:update` switching to `var(--space-section)` for vertical, `var(--space-l)` for horizontal |
| Image src never resolved | `media:smart_search` + `media:sideload` + `element:update` with the bricks_image_object |
| Structural drift (wrong children count, wrong nesting) | `element:remove` + rebuild |

After applying corrections, return to step 2 (verify) and run the loop again.

## Iteration cap

Stop after 2 correction passes per section. Beyond that the remaining issue is usually:

- A site convention you don't know about (ask the user)
- A Bricks-shape edge case (file a note via `bricks:add_note` and document the workaround)
- A global class that doesn't have the styles you expect (`global_class:update` to fix the source, not the leaf)

Don't grind through a third loop hoping one more tweak lands. Surface what's still wrong and ask.

## When Playwright isn't available

If the AI client has no Playwright/headless browser, skip step 3. The element-data verify in step 2 is the only signal. Drifts that need rendered output to detect (silent CSS drops, layout breaks) will go unnoticed until a human looks at the page. In that environment:

- Trust `verify_build`'s `quality_checks` and `content_sample`
- Be conservative on first build (read existing classes, prefer existing styles, avoid inventing class names)
- Tell the user explicitly that visual verification was skipped — they should preview the page before approving

## When verify says "ok" but the page is wrong

This is the failure mode `verify_build` was extended for in v5/C. If `quality_checks.status` is `ok` but the rendered page is broken:

- `style_overrides` was dropped silently by the normalizer. Read the section with `page:get(view: detail)` and see what survived.
- A class you used has empty `settings` (check `quality_checks.empty_classes_used`).
- A variable you referenced doesn't exist (check `quality_checks.warnings` for "missing Bricks variable").

The visual-verify step would have caught it. If you're working without Playwright, this risk is real — slow down on novel sections.
