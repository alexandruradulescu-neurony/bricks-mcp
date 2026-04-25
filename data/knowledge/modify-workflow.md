# Modify workflow — picking the right tool

When the user asks you to *change* something on a page (rather than build a new section from scratch), pick the smallest-blast-radius tool. Rebuilding a section through `build_structure` or `build_from_html` re-creates elements with new IDs and can drop in-place tweaks the user already made — avoid that path for modifications.

## Decision tree

| User says… | Right tool | Why |
|---|---|---|
| "Change the heading text to X" | `element:update` | Single setting on a single element. Preserves IDs, classes, surrounding structure. |
| "Make the buttons bigger / change their color" | `element:update` or `element:bulk_update` | Style overrides on existing elements. No structural change. |
| "Move this card above that one" | `element:move` | Re-parents in place. Element identity stays. |
| "Duplicate this testimonial" | `element:duplicate` | Clones the element + its descendants under the same parent. |
| "Make this CTA look like the other one" | `element:copy_styling` | Copies `_cssGlobalClasses` and/or inline settings between two elements. |
| "Translate the section to English" | `element:bulk_update` | Patch `text`/`content` on each element; structure + styling untouched. |
| "Replace the placeholder image" | `element:update` | Change the `image` setting. Use `media:smart_search` + `media:sideload` first if you need a new asset. |
| "Swap one of the icons" | `element:update` | Update the `icon` setting. |
| "Add a new feature card" | `element:duplicate` an existing card, then `element:update` to change its content | Keeps the card's class chain + layout consistent. |
| "Remove this section" | `element:remove` with `cascade: true` | Deletes the section and all descendants. Requires destructive confirmation token. |
| "Rebuild this section completely / change layout" | `element:remove` + `build_from_html` (or `build_structure`) | Only when structure must change. Snapshot is taken automatically before remove. |

## Read first, then patch

Before `element:update` or `element:bulk_update`, read the current state with `page:get(view: detail, root_element_id: SECTION_ID, compact: true)`. The compact view returns just the elements you need, with their settings. Diff your intended change against that, then patch only the keys you actually want to alter — `bulk_update` deep-merges, so omitting a key leaves it untouched.

## Style overrides on existing elements

To restyle an existing element without losing its class associations, send the override under `style_overrides`-equivalent shapes inside `settings`. Example: re-color a heading without touching its class:

```json
element:update(post_id: 94, element_id: "q3es5o", settings: {
  "_typography": {
    "color": { "raw": "var(--accent)" }
  }
})
```

This deep-merges with the existing `_typography` block. If you replace `_typography` wholesale you'll lose siblings (font-size, line-height) — let `bulk_update`'s merge do the work.

## When you do need to rebuild

If the layout itself must change (e.g. "switch from split to centered", "add a new column"), you cannot patch your way there. Workflow:

1. `page:snapshot` — explicit save (auto-snapshots also exist before any rebuild).
2. `element:remove` with `cascade: true` for the section — get the destructive confirmation token, present to the user, then `confirm_destructive_action`.
3. `build_from_html` (or `build_structure`) with `action: append` to write the new section.
4. `verify_build` — confirm the rewrite applied.

Snapshot lets the user `page:restore` if the rebuild went wrong.

## What rebuilding loses (and the modify tools preserve)

- Element IDs (downstream Bricks integrations may reference them)
- Inline content tweaks the user made in the editor since the last build
- Per-instance settings not part of the design system (custom popup conditions, conditional visibility, custom IDs/anchors)
- Any element's history in template revisions

For these reasons, default to in-place modification. Reach for rebuild only when the structure genuinely must change.
