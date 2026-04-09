# Animations and Interactions in Bricks Builder

## CRITICAL: _animation is DEPRECATED

The `_animation` setting was removed in Bricks 1.6. Do NOT use it.

Always use `_interactions` array instead.

## Interactions Structure

Interactions are an array of objects on any element's settings:

```json
{
  "_interactions": [
    {
      "trigger": "scroll_into_view",
      "action": "toggle_class",
      "class": "fade-in",
      "target": "self"
    }
  ]
}
```

Each interaction object has:
- `trigger` - what initiates the interaction
- `action` - what happens
- `target` - which element is affected (default: self)

## Common Triggers

| Trigger | Description |
|---------|-------------|
| `click` | User clicks element |
| `scroll_into_view` | Element enters viewport |
| `mouse_enter` | Cursor enters element |
| `mouse_leave` | Cursor leaves element |
| `scroll` | Page scroll position |
| `timeout` | After delay (ms) |

## Common Actions

| Action | Description | Key Params |
|--------|-------------|------------|
| `show` | Show target element | - |
| `hide` | Hide target element | - |
| `toggle_class` | Add/remove CSS class | `class` |
| `set_attribute` | Set HTML attribute | `attribute`, `value` |
| `show_popup` | Open popup template | `popupId` |

## Target Options

- `self` - the element with the interaction
- `{element_id}` - specific element by ID
- CSS selector string - target by selector

## Scroll-Triggered Animations

The standard pattern for entrance animations:

1. Create a global CSS class with the animation (e.g., `fade-in` with CSS transition)
2. Set initial state via another class or inline styles (e.g., `opacity: 0`)
3. Add interaction: `trigger: scroll_into_view`, `action: toggle_class`, `class: "fade-in"`

## Multiple Interactions

Elements can have multiple interactions in the array:

```json
{
  "_interactions": [
    { "trigger": "mouse_enter", "action": "toggle_class", "class": "hovered" },
    { "trigger": "mouse_leave", "action": "toggle_class", "class": "hovered" }
  ]
}
```

## Common Pitfalls

1. Using `_animation` instead of `_interactions` -- deprecated, will not work
2. Missing the CSS class definition -- `toggle_class` only adds/removes the class, the actual animation must be defined in CSS
3. Forgetting initial hidden state for entrance animations
4. Targeting elements that don't exist on the page (wrong ID or selector)

## Reference

Use `bricks:get_interaction_schema` for the full interaction schema with all triggers, actions, and parameters.
