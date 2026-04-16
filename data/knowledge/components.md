# Components in Bricks Builder

## Related Knowledge

- `building` — general schema rules, CSS variables, page structure
- `dynamic-data` — `{post_title}` tags inside component property values
- Live element schemas: `bricks:get_element_schemas(element='NAME')`
- **Live component schema (authoritative):** `bricks:get_component_schema` — property types, connections format, slot mechanics, instantiation pattern. Always current with installed Bricks version.

## What is a Component

A reusable element tree with:
- **Properties** — typed inputs that drive element settings via connections
- **Slots** — placeholder elements where per-instance content lives
- **Connections** — map binding property values to specific element settings

Once defined, a component can be instantiated on any page. Changes to the definition propagate to all instances at render time.

## Component IDs

- 6-char alphanumeric (e.g. `abc123`) — same format as element IDs
- The component ID IS the instance element's `name` (no human-readable lookup; always reference by ID)
- **Root element ID inside the component's `elements` array MUST equal the component ID**

## Property Types (9)

| Type | Value format |
|---|---|
| `text` | string (covers text, textarea, rich-text controls) |
| `icon` | object `{ library, icon }` |
| `image` | object `{ id, url }` |
| `gallery` | array of image objects |
| `link` | object `{ url, type, newTab }` |
| `select` | string (selected option value; pair with an `options` array) |
| `toggle` | `"on"` or `"off"` |
| `query` | object with query loop parameters |
| `class` | array of global class IDs |

Property definition shape:
```json
{
  "id": "titleText",
  "name": "Title",
  "type": "text",
  "default": "Card Title",
  "connections": { "title1": ["text"] }
}
```

## Connections — array, not boolean

`connections` is an object mapping **element ID → array of setting keys**. When an instance sets a property value, Bricks copies that value into each listed setting on the connected element.

```json
"connections": {
  "title1": ["text"],
  "btn001": ["text", "link"]
}
```

**Without connections, property values have NO visible effect.** This is the #1 component bug.

To target a nested setting (e.g. typography color), use the dotted key Bricks expects (verify via `bricks:get_element_schemas` for the target element):
```json
"connections": { "title1": ["_typography.color"] }
```

## Slots — element name MUST be "slot"

A slot is an element placed inside the component definition with `name: "slot"` literally — NOT a regular container.

Slot element shape inside the component:
```json
{
  "id": "slot01",
  "name": "slot",
  "parent": "root01",
  "children": [],
  "settings": []
}
```

When the component is instantiated, slot content is filled per-instance and stored in the page's element array (parent = instance element ID), referenced via the instance's `slotChildren`:
```json
"slotChildren": {
  "slot01": ["pageElement1", "pageElement2"]
}
```

Use `component:fill_slot(instance_id, slot_id, slot_elements)` to manage this atomically — the action handles both the page-array insertion and the slotChildren update.

## Instance Element Shape

When you call `component:instantiate`, the resulting element on the page looks like:
```json
{
  "id": "uniq01",
  "name": "abc123",
  "cid": "abc123",
  "parent": "<parent_id>",
  "children": [],
  "properties": [
    { "id": "titleText", "value": "My Custom Title" }
  ],
  "slotChildren": {}
}
```

- `name === cid === component_id`
- `properties` = override values for this instance
- `slotChildren` = filled slots (manage via `component:fill_slot`, not directly)
- Nested components allowed up to **10 levels deep**

## Available Actions

| Action | Purpose |
|---|---|
| `list` | List all components (id, label, category, description, element_count, property_count) |
| `get(component_id)` | Full definition (elements, properties, slots) |
| `create(label, elements, properties, ...)` | New component. Root element ID auto-set to component ID. |
| `update(component_id, ...)` | Update label, description, category, elements, properties |
| `delete(component_id)` | Remove from registry |
| `instantiate(component_id, post_id, parent_id, properties?)` | Place on a page with optional initial property values |
| `update_properties(post_id, instance_id, properties)` | Change instance property values without rebuilding |
| `fill_slot(post_id, instance_id, slot_id, slot_elements)` | Insert content into a named slot on an instance |

## Minimal Example

Define a card component with a title property + slot for body content:

```json
{
  "action": "create",
  "label": "Card",
  "category": "content",
  "elements": [
    { "id": "abc123", "name": "div", "parent": 0, "children": ["title1", "slot01"] },
    { "id": "title1", "name": "heading", "parent": "abc123", "settings": { "tag": "h3" } },
    { "id": "slot01", "name": "slot",    "parent": "abc123", "children": [], "settings": [] }
  ],
  "properties": [
    { "id": "titleText", "name": "Title", "type": "text", "default": "Card Title",
      "connections": { "title1": ["text"] } }
  ]
}
```

(Root element ID `abc123` will become the component's ID — auto-set by `create`.)

Instantiate on a page + fill the slot:
```json
// Step 1
{ "action": "instantiate", "component_id": "abc123", "post_id": 42, "parent_id": "section1",
  "properties": { "titleText": "About Us" } }

// Step 2 — returns instance_id, then:
{ "action": "fill_slot", "post_id": 42, "instance_id": "<from step 1>", "slot_id": "slot01",
  "slot_elements": [
    { "id": "p001", "name": "text-basic", "parent": "<instance_id>", "settings": { "text": "Body content here." } }
  ] }
```

## Common Pitfalls

1. **Connections as `{ "key": true }` instead of `["key"]`** — silently no-op. Always array of setting key strings.
2. **Slot element with `name: "div"` or `"block"`** — not recognized as a slot. Must be literal `name: "slot"`.
3. **Root element ID ≠ component ID** — `create` auto-fixes this; `update` does not. Manual element edits can break instantiation.
4. **Editing slot content via `component:update`** — slot content is per-instance, lives on the page. Use `fill_slot` instead.
5. **Forgetting to set `default`** on a property — instances without an override render an empty value.
6. **Hardcoding values inside element settings** instead of routing through a property — defeats the reusability point.
7. **>10 nested levels** — Bricks rejects.

## Reference

- `bricks:get_component_schema` — live schema (property types, connection format, slot mechanics, instance keys, nesting limit)
- `component:get(component_id)` — full definition of a specific component
- `component:list` — summary of all components on the site
