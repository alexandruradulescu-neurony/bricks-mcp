# Forms in Bricks Builder

## Structure

A form element contains nested form fields. The form element itself holds submission settings, while each child field defines its type and validation.

```
section
  form (submitButtonText, formFields config, actions)
    form-field (type: text, label, placeholder, required)
    form-field (type: email, label, required)
    form-field (type: textarea)
```

## Key Settings

| Setting | Description |
|---------|-------------|
| `submitButtonText` | Button label (default: "Submit") |
| `formFields` | Array of field objects defining the form |
| `actions` | Array of post-submission actions |

## Field Types

Common field types: `text`, `email`, `textarea`, `tel`, `url`, `number`, `select`, `checkbox`, `radio`, `file`, `password`, `hidden`, `datepicker`.

Each field object supports:
- `type` — field type
- `label` — display label
- `placeholder` — placeholder text
- `required` — boolean, enables validation
- `id` — unique field identifier (used in action mappings)
- `width` — column width percentage (25, 33, 50, 67, 75, 100)

## Form Auto-Detection in the Design Pipeline

When `build_from_schema` encounters a `form` element with no explicit `formFields`, the pipeline auto-detects the form type from the element's `role`, `label`, or `content_hint` and applies a template. This keeps design_plan authoring terse — you can say "newsletter form" and get proper fields.

`FormTypeDetector` (shared utility) uses these patterns:

| Form type | Detected from | Template fields |
|---|---|---|
| **newsletter** | newsletter, subscribe, signup, opt-in, register, inregistr | email (67% width) + submit button (33%) + terms HTML field |
| **login** | login, sign-in, auth, conecta, autentific | email + password fields |
| **contact** (default) | anything else (or explicitly "contact") | name (50%) + email (50%) + textarea + submit |

### Explicit override

To force a specific type regardless of content_hint, set `form_type` directly on the schema node:

```json
{
  "type": "form",
  "form_type": "newsletter",
  "role": "inline_signup"
}
```

### Styling defaults

Form templates come with sensible Bricks defaults:
- Submit buttons use the primary button class (matches site)
- Newsletter form uses inline row layout with email + button side by side
- Contact form stacks fields vertically with 2-column name/email row at top

## Submission Actions

Actions fire sequentially after successful validation:
- **Email** — send form data to specified address
- **Redirect** — navigate to URL after submission
- **Custom** — webhook/external integration

## Validation

- Set `required: true` on individual fields
- Email fields auto-validate format
- File fields support `allowedTypes` and `maxSize`
- Custom validation patterns via `pattern` setting (regex)

## Common Pitfalls

1. Field `id` values must be unique within the form — duplicates break email mappings
2. The form element must exist as a parent; form fields outside a form element do nothing
3. Email action requires `emailTo` to be set or the submission silently fails
4. File uploads require server-side configuration (max upload size in PHP/WordPress)
5. When relying on form auto-detection, make sure the element's `role` or `content_hint` contains a trigger word — otherwise the pipeline defaults to a contact form

## Reference

Use `bricks:get_form_schema` for the complete form schema with all available settings and field types.
