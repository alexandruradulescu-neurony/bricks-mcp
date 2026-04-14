# Popups in Bricks Builder

## Two Separate Systems

Popups involve two independent configurations:

1. **Popup template** - the content and display settings (size, backdrop, close button)
2. **Triggers** - interactions on page elements that open/close the popup

These are configured separately and linked by the popup template ID.

## Creating a Popup

### Step 1: Create the popup template

```
template:create with type: "popup"
```

This creates a template of type `popup`. Add content elements to it like any page.

### Step 2: Configure popup settings

```
template:set_popup_settings with template_id and settings object
```

Key popup settings:
- `width`, `height` - popup dimensions
- `overlay` - show backdrop overlay (boolean)
- `overlayClickClose` - close on backdrop click
- `showCloseButton` - render X button
- `closeButtonPosition` - inside/outside
- `animation` - entry animation type
- `position` - center, top-left, bottom-right, etc.
- `zIndex` - stacking order

Use `bricks:get_popup_schema` for the full list of available settings.

### Step 3: Set display conditions

```
template_condition:set with template_id and conditions array
```

The popup template must have conditions to define WHERE it can appear (e.g., "entire website", "specific page").

## Triggering a Popup

Triggers are NOT set on the popup itself. They are `_interactions` on page elements.

### Click trigger (most common)

Add to any element's settings:
```json
{
  "_interactions": [
    {
      "trigger": "click",
      "action": "show_popup",
      "popupId": "template_123"
    }
  ]
}
```

### Other trigger types
- `scroll` - open popup on scroll position
- `exit_intent` - open when cursor moves to leave viewport
- `timeout` - open after delay

## Common Pitfalls

1. Forgetting display conditions -- popup templates without conditions never render on the frontend
2. Setting triggers on the popup template instead of page elements
3. Missing `popupId` in the interaction -- must reference the popup template post ID
4. Popup content is a regular template -- build it with sections/containers like any page
5. `overlay: true` without `overlayClickClose: true` traps users if no close button exists

## Reference

Use `bricks:get_popup_schema` for popup display settings.
Use `bricks:get_interaction_schema` for trigger/interaction configuration.
