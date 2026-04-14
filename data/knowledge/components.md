# Components in Bricks Builder

## What is a Component

A component is a reusable element tree with configurable properties. Once defined, it can be instantiated on any page. Instances inherit the component structure but allow property overrides and slot content.

## Key Concepts

- **Component ID**: 6-character alphanumeric string (e.g., `abc123`). This IS the instance name in Bricks -- there is no separate human-readable reference name.
- **Properties**: typed inputs (text, number, color, image, etc.) that drive element settings via connections.
- **Slots**: placeholder elements inside the component where page-specific content can be inserted.
- **Connections**: the map that binds a property value to specific element settings.

## Creating a Component

Use `component:create` with:
- `label` - display name
- `elements` - flat element array (same format as page content)
- `properties` - array of property definitions

```json
{
  "action": "create",
  "label": "Card",
  "elements": [
    { "id": "root01", "name": "div", "parent": 0, "settings": {} },
    { "id": "title1", "name": "heading", "parent": "root01", "settings": { "tag": "h3" } }
  ],
  "properties": [
    { "id": "titleText", "name": "Title", "type": "text", "default": "Card Title" }
  ]
}
```

## Property Connections

Properties do nothing until connected to element settings. The `connections` map on each property tells Bricks which element setting to update:

```json
{
  "id": "titleText",
  "name": "Title",
  "type": "text",
  "connections": {
    "title1": { "text": true }
  }
}
```

This maps `titleText` property to the `text` setting of element `title1`.

## Instantiating a Component

Use `component:instantiate` with:
- `component_id` - the 6-char component ID
- `post_id` - target page
- `parent_id` - where to place it
- `properties` - override values (optional)

## Slots

Slots are defined as empty container elements in the component. When instantiated, slot content lives in the PAGE element array with `parent` set to the instance element ID path -- NOT in the component definition.

Use `component:fill_slot` to add content to a slot after instantiation.

## Common Pitfalls

1. Forgetting `connections` -- properties without connections have no visible effect
2. Using human-readable names to reference components -- always use the 6-char ID
3. Editing slot content via `component:update` -- slot content is per-instance, stored on the page
4. Component elements use a flat array with parent references, same as page content

## Reference

Use `bricks:get_component_schema` for the full component property and slot schema.
