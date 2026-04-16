# Animations and Interactions in Bricks Builder

## CRITICAL: `_animation` is DEPRECATED

The `_animation`, `_animationDuration`, and `_animationDelay` settings were removed in Bricks 1.6. Do NOT use them — they are silently ignored.

Always use the `_interactions` array instead. For full programmatic reference, call `bricks:get_interaction_schema`.

## Interactions Structure

Every element can have an `_interactions` array in its settings. Each interaction object requires:

- `id` — unique 6-char lowercase alphanumeric (same format as element IDs, e.g. `ab1cd2`)
- `trigger` — what event starts the interaction
- `action` — what happens when triggered
- `target` — which element is affected: `"self"` (default), `"custom"` (CSS selector via `targetSelector`), or `"popup"` (template ID via `templateId`)

```json
{
  "_interactions": [
    {
      "id": "ab1cd2",
      "trigger": "enterView",
      "action": "startAnimation",
      "animationType": "fadeInUp",
      "animationDuration": "0.8s",
      "target": "self",
      "runOnce": true
    }
  ]
}
```

## Triggers (17)

| Trigger | When It Fires | Required / Optional Params |
|---|---|---|
| `click` | Element is clicked | — |
| `mouseover` | Mouse moves over element | — |
| `mouseenter` | Mouse enters element bounds | — |
| `mouseleave` | Mouse leaves element bounds | — |
| `mouseleaveWindow` | Mouse leaves the browser window | — |
| `focus` | Element receives focus | — |
| `blur` | Element loses focus | — |
| `enterView` | Element enters viewport (IntersectionObserver) | optional `rootMargin` (e.g. `"0px 0px -80px 0px"`) |
| `leaveView` | Element leaves viewport | optional `rootMargin` |
| `animationEnd` | Another interaction's animation ends | requires `animationId` (id of source interaction) |
| `contentLoaded` | DOM content loaded | optional `delay` (e.g. `"0.5s"`) |
| `scroll` | Window scroll reaches `scrollOffset` | requires `scrollOffset` (px / vh / %) |
| `ajaxStart` | Query loop AJAX starts | requires `ajaxQueryId` |
| `ajaxEnd` | Query loop AJAX ends | requires `ajaxQueryId` |
| `formSubmit` | Form submitted | requires `formId` |
| `formSuccess` | Form submission succeeded | requires `formId` |
| `formError` | Form submission failed | requires `formId` |

## Actions

| Action | What It Does | Required Params |
|---|---|---|
| `startAnimation` | Run Animate.css animation on target | `animationType`, optional `animationDuration`, `animationDelay` |
| `show` | Show target (removes `display:none`) | — |
| `hide` | Hide target (sets `display:none`) | — |
| `click` | Programmatically click target | — |
| `toggleClass` | Add/remove CSS class | `class` |
| `setAttribute` | Set HTML attribute | `attribute`, `value` |
| `removeAttribute` | Remove HTML attribute | `attribute` |
| `toggleAttribute` | Toggle HTML attribute | `attribute` |
| `showPopup` | Open a popup template | `templateId` |
| `toggleOffCanvas` | Toggle Bricks off-canvas element | — |
| `loadMore` | Load more results in query loop | `loadMoreQuery` |
| `scrollTo` | Smooth scroll to target | — |
| `javascript` | Call a global JS function | `jsFunction`, optional `jsFunctionArgs` |
| `openAddress` | Open map info box | — |
| `closeAddress` | Close map info box | — |
| `clearForm` | Clear form fields | — |
| `storageAdd` | Add to browser storage | — |
| `storageRemove` | Remove from browser storage | — |
| `storageCount` | Count browser storage items | — |

## Animation Types (Animate.css)

Bricks auto-enqueues Animate.css when `startAnimation` action is detected — no manual enqueue needed.

- **Attention:** `bounce`, `flash`, `pulse`, `rubberBand`, `shakeX`, `shakeY`, `headShake`, `swing`, `tada`, `wobble`, `jello`, `heartBeat`
- **Back:** `backIn{Down|Left|Right|Up}`, `backOut{Down|Left|Right|Up}`
- **Bounce:** `bounceIn`, `bounceIn{Down|Left|Right|Up}`, `bounceOut`, `bounceOut{Down|Left|Right|Up}`
- **Fade:** `fadeIn`, `fadeIn{Down|DownBig|Left|LeftBig|Right|RightBig|Up|UpBig|TopLeft|TopRight|BottomLeft|BottomRight}`, `fadeOut` (+ all directional variants)
- **Flip:** `flip`, `flipInX`, `flipInY`, `flipOutX`, `flipOutY`
- **Light Speed:** `lightSpeedIn{Right|Left}`, `lightSpeedOut{Right|Left}`
- **Rotate:** `rotateIn`, `rotateIn{DownLeft|DownRight|UpLeft|UpRight}`, `rotateOut` (+ directional variants)
- **Slide:** `slideIn{Up|Down|Left|Right}`, `slideOut{Up|Down|Left|Right}`
- **Zoom:** `zoomIn`, `zoomIn{Down|Left|Right|Up}`, `zoomOut` (+ directional variants)
- **Special:** `hinge`, `jackInTheBox`, `rollIn`, `rollOut`

## Target Options

- `"self"` — the element with the interaction (default)
- `"custom"` — targets a CSS selector via `targetSelector`, e.g. `"#brxe-abc123"` or `".card-wrapper"`
- `"popup"` — targets a popup template via `templateId`

## Common Patterns

### Scroll-Reveal (enterView)

```json
{
  "_interactions": [
    {
      "id": "aa1bb2",
      "trigger": "enterView",
      "rootMargin": "0px 0px -80px 0px",
      "action": "startAnimation",
      "animationType": "fadeInUp",
      "animationDuration": "0.8s",
      "animationDelay": "0s",
      "target": "self",
      "runOnce": true
    }
  ]
}
```

Negative bottom `rootMargin` fires the trigger when the element is that many pixels inside the viewport — prevents animation firing before the element is actually visible.

### Stagger (Cascading siblings)

Same animation on sibling elements with incrementing `animationDelay`:

```json
// Card 1
{"_interactions": [{"id": "cc3dd4", "trigger": "enterView", "action": "startAnimation", "animationType": "fadeInUp", "animationDuration": "0.8s", "animationDelay": "0s",    "target": "self", "runOnce": true}]}

// Card 2
{"_interactions": [{"id": "ee5ff6", "trigger": "enterView", "action": "startAnimation", "animationType": "fadeInUp", "animationDuration": "0.8s", "animationDelay": "0.15s", "target": "self", "runOnce": true}]}

// Card 3
{"_interactions": [{"id": "gg7hh8", "trigger": "enterView", "action": "startAnimation", "animationType": "fadeInUp", "animationDuration": "0.8s", "animationDelay": "0.3s",  "target": "self", "runOnce": true}]}
```

### Chained Animations (animationEnd)

Sequence animations across elements using `animationEnd` + `animationId`:

```json
// Title
{"_interactions": [{"id": "ii9jj0", "trigger": "contentLoaded", "action": "startAnimation", "animationType": "fadeInDown", "animationDuration": "0.8s", "target": "self"}]}

// Subtitle — waits for title
{"_interactions": [{"id": "kk1ll2", "trigger": "animationEnd", "animationId": "ii9jj0", "action": "startAnimation", "animationType": "fadeIn", "animationDuration": "0.6s", "target": "self"}]}
```

`animationId` can reference any interaction on the page, not just on the same element.

### Click-Triggered

```json
{
  "_interactions": [
    {"id": "pp1qq2", "trigger": "click", "action": "startAnimation", "animationType": "pulse", "animationDuration": "0.5s", "target": "self"}
  ]
}
```

For show/hide on click, use `action: "show"` or `"hide"` with `target: "custom"` + `targetSelector: "#brxe-elementId"`.

## Native Parallax (Bricks 2.3+)

Bricks 2.3 ships built-in parallax as style properties under the Transform control group — no GSAP, no custom JS, no `_interactions` needed. Set these directly in element settings, same as `_padding` or `_typography`.

### Element parallax — moves the element itself

```json
{
  "_motionElementParallax": true,
  "_motionElementParallaxSpeedX": 0,
  "_motionElementParallaxSpeedY": -20,
  "_motionStartVisiblePercent": 0
}
```

### Background parallax — moves the background image

```json
{
  "_motionBackgroundParallax": true,
  "_motionBackgroundParallaxSpeed": -15,
  "_motionStartVisiblePercent": 0
}
```

### Rules

- Speed values are **percentages**. Negative = opposite scroll direction (classic "slower than page" parallax). Positive = same direction.
- `_motionStartVisiblePercent` (0–100) controls when the effect begins: `0` = element entering viewport, `50` = near center.
- Parallax is **not visible in the Bricks builder preview** — only on the live frontend.
- These are NOT `_interactions` — they are style properties. Combining them with `_interactions` is fine.

## 3D Transforms (Bricks 2.3+)

Perspective and scale3d are now supported inside `_transform`:

```json
{
  "_perspective": "800px",
  "_perspectiveOrigin": "center",
  "_transform": {
    "perspective": "800px",
    "rotateX": 15,
    "rotateY": 10,
    "scale3dX": 1.1,
    "scale3dY": 1.1,
    "scale3dZ": 1
  },
  "_transformOrigin": "center center"
}
```

- `_perspective` on a parent enables 3D for children.
- `perspective` inside `_transform` is always emitted first in CSS output (required by spec).
- `scale3d*` — omitted axes default to `1`. Requires at least one axis set.
- `rotateX/Y/Z`, `skewX/Y` — auto-append `deg`.

## GSAP (advanced)

GSAP is NOT bundled. The site owner must load it. Use for advanced control (scrub, timelines, custom easing) when native parallax + Animate.css isn't enough.

## Common Pitfalls

1. **Using `_animation`** instead of `_interactions` — deprecated, silently ignored.
2. **Missing `id` on interaction objects** — Bricks skips the interaction. Always include a 6-char alphanumeric id.
3. **Wrong trigger name** — common typos: `scroll_into_view` → use `enterView`; `mouse_enter` → use `mouseenter`.
4. **Missing required params** — `scroll` needs `scrollOffset`; `animationEnd` needs `animationId`; form/ajax triggers need the matching id.
5. **Parallax with `_interactions`** — the `_motion*` properties are style properties, not interactions. Don't wrap them in an `_interactions` array.
6. **Expecting parallax in builder** — only visible on frontend.
7. **`runOnce: true`** omitted on scroll-reveal — animation replays every time the element re-enters the viewport.

## Reference

- `bricks:get_interaction_schema` — full interaction schema (all triggers, actions, parameters) read live from the installed Bricks version.
- Bricks Builder documentation — Interactions panel.
