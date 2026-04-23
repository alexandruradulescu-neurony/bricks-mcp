# Global Classes in Bricks Builder

## Related Knowledge

- `building` — class-first workflow, `class_intent` in design schemas, `_cssGlobalClasses` on elements
- `animations` — interactions can target elements by class
- **Live class list:** `global_class:list` — all classes on the site with names, IDs, styles, categories

## What Global Classes Are

Global classes are reusable CSS rule sets stored in `bricks_global_classes` WP option. Apply a class to any element via `_cssGlobalClasses` (array of class **IDs**, not names).

Classes generate CSS at render time — they're NOT WordPress CSS classes in the traditional sense. They use Bricks' composite-key style system (`_padding`, `_typography:hover`, etc.).

## IDs vs Names (critical footgun)

| Concept | Format | Example | Where used |
|---|---|---|---|
| **Class ID** | 6-char alphanumeric | `fj8k2l` | `_cssGlobalClasses: ["fj8k2l"]`, `apply`/`remove` actions |
| **Class name** | Human-readable | `btn-primary` | `create`, `update`, `list` display, `class_intent` in schemas |

**`_cssGlobalClasses` takes IDs, not names.** Always call `global_class:list` to get the name→ID mapping before applying.

In the `build_from_schema` pipeline, `class_intent` uses human names — the pipeline resolves them to IDs automatically. Direct element operations (`element:add`, `element:update`) require IDs.

## Style Shape

Class styles use the same Bricks composite-key format as inline element settings:

```json
{
  "name": "btn-primary",
  "styles": {
    "_padding": { "top": "12px", "right": "24px", "bottom": "12px", "left": "24px" },
    "_background": { "color": { "raw": "var(--primary)" } },
    "_typography": { "color": { "raw": "var(--white)" }, "font-weight": "700" },
    "_border": { "radius": { "top": "var(--radius-btn)", "right": "var(--radius-btn)", "bottom": "var(--radius-btn)", "left": "var(--radius-btn)" } },
    "_background:hover": { "color": { "raw": "var(--primary-dark)" } }
  }
}
```

Key rules:
- Color values must be `{raw}` or `{hex}` objects (NOT plain strings)
- Border `style` is a string (NOT per-side object)
- Responsive: `_padding:tablet_portrait`, `_padding:mobile_portrait`
- Pseudo: `_background:hover`, `_typography:focus`
- Combined: `_padding:mobile_portrait:hover`

See `bricks:get_knowledge('building')` → "Composite Key Format" + "Color Object Format" for full reference.

## Setting Key Conventions (CRITICAL — silent failure)

Bricks uses **two case conventions in the same object** — if you get this wrong the CSS compiler silently drops the rule and only some styles render. No error, no warning. The v3.33.1 knowledge gate now blocks class writes with styles until you've read this file, but you still have to apply the rules correctly.

### camelCase (top-level underscore-prefixed keys)

```json
{
  "_display": "flex",
  "_direction": "row",
  "_alignItems": "center",
  "_justifyContent": "center",
  "_columnGap": "var(--space-s)",
  "_rowGap": "var(--space-s)",
  "_flexWrap": "wrap",
  "_flexGrow": "1",
  "_flexBasis": "0",
  "_aspectRatio": "3/4",
  "_objectFit": "cover",
  "_textAlign": "center",
  "_width": "100%",
  "_widthMax": "var(--max-width)",
  "_widthMin": "var(--container-width)",
  "_height": "auto",
  "_heightMax": "80vh",
  "_heightMin": "var(--min-height)"
}
```

### kebab-case (INSIDE `_typography`, `_border.*`, etc.)

```json
{
  "_typography": {
    "font-size": "var(--h2)",
    "font-weight": "700",
    "line-height": "1.2",
    "letter-spacing": "-0.02em",
    "text-transform": "uppercase",
    "text-align": "center",
    "color": { "raw": "var(--base-ultra-dark)" }
  }
}
```

**Wrong:** `"fontSize": "var(--h2)"` inside `_typography` — Bricks silently drops it. Only the `color` rule survives.

### Object shape (NOT scalar) for these keys

| Key | Shape |
|---|---|
| `_padding` | `{ top, right, bottom, left }` object |
| `_margin` | `{ top, right, bottom, left }` object |
| `_border.radius` | `{ top, right, bottom, left }` object |
| `_border.width` | `{ top, right, bottom, left }` object |
| `*.color` | `{ raw: "var(...)" }` OR `{ hex: "#..." }` object |

Scalar for: `_width`, `_widthMax`, `_widthMin`, `_height`, `_heightMax`, `_heightMin`, `_display`, `_aspectRatio`, everything listed in "camelCase (top-level)".

Example — fully working button class:
```json
{
  "_typography": {
    "font-size": "var(--text-m)",
    "font-weight": "600",
    "color": { "raw": "var(--white)" }
  },
  "_background": { "color": { "raw": "var(--base-ultra-dark)" } },
  "_padding": {
    "top": "var(--space-s)", "right": "var(--space-l)",
    "bottom": "var(--space-s)", "left": "var(--space-l)"
  },
  "_border": {
    "radius": {
      "top": "var(--radius-pill)", "right": "var(--radius-pill)",
      "bottom": "var(--radius-pill)", "left": "var(--radius-pill)"
    }
  },
  "_display": "inline-flex",
  "_alignItems": "center",
  "_columnGap": "var(--space-s)"
}
```

### Heading font-size specificity trap

Child theme CSS has tag selectors: `h1 { font-size: var(--h1) }` through `h6`. Setting `_typography.font-size` on a class applied to an `<h1>` element LOSES the specificity war — your font-size doesn't win. Options:

- Use `var(--h1)..var(--h6)` variables which already produce the right size per tag
- Override heading font-size via a more specific selector — not possible through classes alone
- Use `type: text-basic` with `tag: h1` instead of `type: heading` (text-basic has no tag-level theme rule)
- Use inline `_cssCustom` via element settings (blocked by DANGEROUS_SETTINGS_BLOCKED in v3.33.0 — you'd have to allow it per-element)

For standard hero sizes just use the `var(--h1)` tokens. For giant custom sizes (e.g. `clamp(3rem, 12vw, 11rem)` landing hero) use `text-basic` with `tag: div` or `p` to bypass the heading theme rules.

### Verification before committing a build

After creating/updating a class with styles, ALWAYS call `global_class:render_sample(class_name)` and inspect the `css_rules` field. If any setting you passed isn't showing up as a CSS rule, the key shape is wrong. Fix before building elements that use the class.

## Available Actions (16)

### Core CRUD

| Action | Use |
|---|---|
| `list(search?, category?, limit?, offset?)` | List classes. Filter by name substring or category. |
| `create(name, styles?, category?, color?)` | Create class. `color` = visual indicator hex in editor. |
| `update(class_name, styles?, name?, category?, color?, replace_styles?)` | Update. Default: deep-merge styles. `replace_styles: true` to overwrite entirely. |
| `delete(class_name)` | Delete class (destructive — requires confirmation token) |

### Applying / Removing from Elements

| Action | Use |
|---|---|
| `apply(class_name, element_ids, post_id)` | Add class to elements. Resolves name→ID automatically. |
| `remove(class_name, element_ids, post_id)` | Remove class from elements. |

### Batch Operations

| Action | Use |
|---|---|
| `batch_create(classes)` | Array of `{name, styles, category?}` objects. Up to 50. |
| `batch_delete(classes)` | Array of class name strings. Destructive. |

### Import / Export

| Action | Use |
|---|---|
| `import_css(css)` | Parse raw CSS string → create classes. Each CSS rule becomes a class. |
| `import_json(classes_data)` | Import from JSON (array of class objects or `{classes, categories}` bundle). |
| `export` | Export all classes as JSON (portable). |

### Categories

| Action | Use |
|---|---|
| `list_categories` | List class categories |
| `create_category(category_name)` | Create category |
| `delete_category(category_id)` | Delete category |

### Discovery

| Action | Use |
|---|---|
| `semantic_search(query)` | Natural language search — `"card with white bg and shadow"`. Scored by name match (10pts), settings match (6pts), word stems (4pts). Returns ranked results with `match_reasons` + `settings_summary`. |
| `render_sample(class_name or class_id)` | Returns: structured description, equivalent CSS rules, sample HTML snippet. Use to verify a class produces what you expect before applying. |

## Class-First Workflow

### In `build_from_schema` (design pipeline)

Use `class_intent` on schema elements — the pipeline handles name→ID resolution + class creation:

```json
{
  "type": "button",
  "content": "Get Started",
  "class_intent": "btn-primary"
}
```

If `btn-primary` exists → reused. If not → created with styles from `style_overrides`.

### In direct element operations

Use `_cssGlobalClasses` with IDs:

```json
{
  "name": "button",
  "settings": {
    "text": "Get Started",
    "_cssGlobalClasses": ["fj8k2l"]
  }
}
```

Or use `global_class:apply` which resolves names:
```json
{ "action": "apply", "class_name": "btn-primary", "element_ids": ["abc123"], "post_id": 42 }
```

## Multiple Classes Compose

Elements can have multiple classes. Order doesn't matter — Bricks merges styles:

```json
"_cssGlobalClasses": ["card-id", "shadow-m-id", "rounded-id"]
```

Later classes override earlier ones on conflict (same key).

## Import from CSS

Convert existing CSS into global classes:

```json
{
  "action": "import_css",
  "css": ".card { padding: 24px; border-radius: 12px; background: #fff; }\n.card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }"
}
```

Each rule → one class. Hover/focus pseudo-states → composite keys on the same class.

## Deep Merge vs Replace

`update` default = **deep merge**. New styles merge INTO existing:

```json
// Existing: _padding + _background
// Update with: _typography
// Result: _padding + _background + _typography (all preserved)
```

With `replace_styles: true` — existing styles WIPED, replaced entirely with new.

## Style Normalization

The plugin auto-fixes common style shape mistakes on create + update:
- String colors → `{raw|hex}` object
- Per-side `_border.style` object → string
- `_border.width` number → per-side object
- `_cssCustom` array → joined string

Warnings returned when auto-fixes are applied.

## Common Pitfalls

1. **Name in `_cssGlobalClasses`** — wrong. Must be IDs. Use `global_class:list` to get mapping, or use `apply` action which resolves names.
2. **`styles` as flat CSS** — Bricks expects composite-key objects, not `{ "padding": "12px" }`. Use `{ "_padding": { "top": "12px", ... } }`.
3. **Color as string** — `"color": "#fff"` → rejected/auto-fixed. Use `"color": { "hex": "#fff" }` or `{ "raw": "var(--white)" }`.
4. **`replace_styles: true` on update accidentally** — wipes all existing styles. Default merge is usually what you want.
5. **Duplicate names** — `create` errors if name already exists. Use `update` for existing classes.
6. **`batch_delete` without confirmation** — destructive action, requires confirmation token (see `confirm_destructive_action` tool).
7. **`import_css` selector naming** — `.my-class` becomes class named `my-class`. Nested selectors (`.parent .child`) get flattened.
8. **Responsive styles on wrong breakpoint** — `_padding:mobile` is NOT valid. Use `_padding:mobile_portrait` or `_padding:mobile_landscape`.
9. **camelCase inside `_typography`** — silent failure. `"_typography": {"fontSize": ...}` emits NO CSS for that property; only `color` survives. Use kebab: `"font-size"`, `"font-weight"`, `"line-height"`, `"letter-spacing"`, `"text-transform"`, `"text-align"`. See "Setting Key Conventions" above.
10. **`font-size` on heading classes** — loses specificity war against child-theme `h1..h6` tag selectors. Your size doesn't render. Use `var(--h1)..var(--h6)` tokens OR switch the element to `type: text-basic` with `tag: h1` if you need a custom giant size.
11. **Not verifying via `render_sample`** — classes can store wrong-shape settings and return success. The silent-drop happens in the CSS compiler, not the save. Always call `global_class:render_sample(class_name)` after any class write that includes styles; inspect `css_rules` for all properties you passed.

## Reference

- `global_class:list` — all classes with IDs, names, styles
- `global_class:semantic_search(query)` — find classes by description
- `global_class:render_sample(class_name)` — preview CSS output + HTML snippet
- `global_class:batch_create(classes)` — bulk creation (up to 50)
- `global_class:import_css(css)` — parse CSS → classes
- `bricks:get_knowledge('building')` — composite key format, color objects, `class_intent` usage
