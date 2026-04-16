# Popups in Bricks Builder

## Related Knowledge

- `building` — schema rules, element settings shape
- `animations` — `_interactions` array, all triggers + actions including `showPopup`/`hidePopup` events
- `dynamic-data` — pre-fill popup form fields, dynamic popup content
- **Live popup schema (authoritative):** `bricks:get_popup_schema` — every setting, all categories, defaults, infobox/AJAX rules. Always current.

## Three Independent Systems

A working popup needs THREE separate things configured:

| System | Storage | Controls |
|---|---|---|
| **Popup template** | `bricks_template` post (type=popup) | What's inside the popup |
| **Display settings** | `_bricks_template_settings` post meta | How the popup looks/behaves (size, backdrop, close, limits) |
| **Triggers** | `_interactions` on any element | What opens the popup |

Plus optionally:

| **Page conditions** | template conditions | Which pages can render this popup |

These are configured separately. The popup template ID links them all.

## Workflow

```
1. template:create(type='popup', title='My Popup')
2. page:update_content(post_id=<popup_template_id>, elements=[...])
3. template:set_popup_settings(template_id=<popup_id>, settings={...})
4. element:update(post_id=<page>, element_id=<button>, settings={_interactions: [...]})
5. template_condition:set(template_id=<popup_id>, conditions=[{main:'any'}])
```

## Trigger Pattern (Universal)

Set `_interactions` on the element that should open the popup:

```json
{
  "_interactions": [
    {
      "id": "ab1cd2",
      "trigger": "click",
      "action": "show",
      "target": "popup",
      "templateId": 123
    }
  ]
}
```

Key shape rules:
- `action: "show"` (NOT `"show_popup"` — that doesn't exist)
- `target: "popup"`
- `templateId: <popup template post ID>`
- `id` is required (6-char alphanumeric, same as element IDs)

To close: `action: "hide"`, same target/templateId.

### Common trigger variants

| Trigger | Use | Extra keys |
|---|---|---|
| `click` | Open on button click | — |
| `contentLoaded` | Auto-open on page load | `delay: "2s"` |
| `scroll` | Open at scroll position | `scrollOffset: "50%"` (px or %) |
| `mouseleaveWindow` | Exit-intent | `runOnce: true` recommended |
| `enterView` | Open when an element scrolls into view | `rootMargin: "0px 0px -80px 0px"` |
| `formSuccess` | Open after form submit | `formId: <form element id>` |

See `bricks:get_interaction_schema` for the full trigger list.

## Popup Settings — 30+ keys across 7 categories

All settings live in `_bricks_template_settings` (template-level meta), NOT in element settings. Manage via `template:set_popup_settings`. Pass `null` to delete a key (revert to default).

### Outer (popup wrapper)

| Key | Default | Use |
|---|---|---|
| `popupPadding` | none | Spacing object — outer padding around content |
| `popupJustifyContent` | none | Main-axis alignment |
| `popupAlignItems` | none | Cross-axis alignment (controls vertical position) |
| `popupCloseOn` | both (unset) | `"backdrop"` = click only, `"esc"` = ESC only, `"none"` = disabled. **DO NOT pass `"both"`** — leave unset. |
| `popupZindex` | `10000` | CSS z-index |
| `popupBodyScroll` | `false` | Allow body scroll while open |
| `popupScrollToTop` | `false` | Scroll popup to top on open |
| `popupDisableAutoFocus` | `false` | Skip auto-focus first focusable element |

### Backdrop

| Key | Default | Use |
|---|---|---|
| `popupDisableBackdrop` | `false` | Remove backdrop entirely (page interactive while popup open) |
| `popupBackground` | none | Backdrop color/image (background object) |
| `popupBackdropTransition` | none | CSS transition value |

### Content sizing (`.brx-popup-content` box)

| Key | Default | Use |
|---|---|---|
| `popupContentPadding` | `30px` all | Inner padding |
| `popupContentWidth` | container | Width (number+unit) |
| `popupContentMinWidth` | none | Min-width |
| `popupContentMaxWidth` | none | Max-width |
| `popupContentHeight` | none | Height |
| `popupContentMinHeight` | none | Min-height |
| `popupContentMaxHeight` | none | Max-height |
| `popupContentBackground` | none | Background object |
| `popupContentBorder` | none | Border object |
| `popupContentBoxShadow` | none | Box-shadow object |

### Display limits (4 tiers — pick the storage that matches lifetime)

| Key | Storage | Use |
|---|---|---|
| `popupLimitWindow` | window var | Max times per page load |
| `popupLimitSessionStorage` | sessionStorage | Max times per browser session |
| `popupLimitLocalStorage` | localStorage | Max times across sessions (persistent) |
| `popupLimitTimeStorage` | localStorage + ts | Show again only after N hours |

### Breakpoint visibility

| Key | Use |
|---|---|
| `popupBreakpointMode` | `"at"` = show starting at breakpoint, `"on"` = show on specific breakpoints only |
| `popupShowAt` | Single breakpoint key (e.g. `"tablet_portrait"`) — used with mode `"at"` |
| `popupShowOn` | Array of breakpoint keys — used with mode `"on"` |

### AJAX mode (load content on demand)

| Key | Use |
|---|---|
| `popupAjax` | `true` to fetch via AJAX. **Only supports Post / Term / User context types.** |
| `popupIsWoo` | `true` for WooCommerce Quick View (requires `popupAjax: true`) |
| `popupAjaxLoaderAnimation` | Loader animation type |
| `popupAjaxLoaderColor` | Color object |
| `popupAjaxLoaderScale` | Scale factor (default 1) |
| `popupAjaxLoaderSelector` | CSS selector (default `.brx-popup-content`) |

### Template interactions (popup-level events)

`template_interactions` = repeater stored in `_bricks_template_settings`. Same shape as element `_interactions` but supports two extra triggers:

- `showPopup` — fires when this popup is shown (chain another animation, run JS)
- `hidePopup` — fires after this popup is hidden

These are NOT for opening the popup — they REACT to its open/close events.

## Infobox Mode (Google Maps)

A popup with `popupIsInfoBox: true` is a lightweight variant designed for Google Maps info windows. Many display settings are skipped because the map controls positioning and lifecycle.

```json
{
  "popupIsInfoBox": true,
  "popupInfoBoxWidth": 300,
  "popupContentPadding": { "top": "12px", "right": "16px", "bottom": "12px", "left": "16px" },
  "popupContentBackground": { "color": { "raw": "var(--white)" } }
}
```

| Skipped in infobox mode | Active in infobox mode |
|---|---|
| `popupPadding`, `popupJustifyContent`, `popupAlignItems`, `popupCloseOn`, `popupZindex`, `popupBodyScroll` | `popupInfoBoxWidth`, `popupContentPadding`, `popupContentWidth`, `popupContentBackground`, `popupContentBorder`, `popupContentBoxShadow`, `popupAjax`, `template_interactions` |

`template:list` and `template:get` return `is_infobox: true` for quick identification.

Workflow: `template:create(type=popup)` → `template:set_popup_settings({popupIsInfoBox: true, ...})` → reference template ID in a Bricks Map element.

## Page Conditions (where the popup can appear)

`template_condition:set` controls which page contexts include the popup template. Without conditions the popup never renders.

```json
[ { "main": "any" } ]                         ← entire site
[ { "main": "ids", "ids": [42] } ]            ← specific page only
[ { "main": "postType", "postType": "post" } ] ← all blog posts
```

See `bricks:get_form_schema` (similar condition format) and `template_condition:get_types` for the full list.

## Common Pitfalls

1. **`action: "show_popup"`** — wrong. Use `action: "show"` + `target: "popup"` + `templateId`.
2. **`popupId` setting key** — doesn't exist. The reference key is `templateId`.
3. **Settings without `popup` prefix** — `width`, `height`, `zIndex` are ignored. ALL popup settings are prefixed `popup*`.
4. **`popupCloseOn: "both"`** — explicitly do NOT pass `"both"`. Leave the key unset for both backdrop+ESC.
5. **No display conditions** — popup template never renders on the frontend without conditions. Default-active needs `[{main:'any'}]`.
6. **Triggers on the popup template** — wrong layer. Triggers go on OTHER elements that should open the popup (or `template_interactions` for showPopup/hidePopup reactions).
7. **`popupDisableBackdrop: true` without close button** — traps users with no way out. Always pair with a visible close mechanism.
8. **Display limits double-up** — picking `popupLimitWindow` + `popupLimitLocalStorage` means BOTH apply. Choose one storage tier.
9. **AJAX with non-supported context** — `popupAjax: true` only works with Post/Term/User. Other contexts silently fail.
10. **Infobox setting bleed** — setting `popupZindex` or `popupAlignItems` on `popupIsInfoBox: true` does nothing; map controls those.
11. **Trigger `exit_intent`** — wrong name. Use `mouseleaveWindow` (often with `runOnce: true`).
12. **Trigger `timeout`** — wrong name. Use `contentLoaded` + `delay: "2s"`.

## Reference

- `bricks:get_popup_schema` — full popup settings with categories, defaults, infobox rules
- `bricks:get_interaction_schema` — trigger/action reference
- `template:set_popup_settings(template_id, settings)` — write settings (null deletes a key)
- `template:get_popup_settings(template_id)` — read current settings
- `template_condition:set` / `:get_types` — page-level visibility
