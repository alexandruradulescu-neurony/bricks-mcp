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
- `type` - field type
- `label` - display label
- `placeholder` - placeholder text
- `required` - boolean, enables validation
- `id` - unique field identifier (used in action mappings)

## Submission Actions

Actions fire sequentially after successful validation:
- **Email** - send form data to specified address
- **Redirect** - navigate to URL after submission
- **Custom** - webhook/external integration

## Validation

- Set `required: true` on individual fields
- Email fields auto-validate format
- File fields support `allowedTypes` and `maxSize`
- Custom validation patterns via `pattern` setting (regex)

## Common Pitfalls

1. Field `id` values must be unique within the form -- duplicates break email mappings
2. The form element must exist as a parent; form fields outside a form element do nothing
3. Email action requires `emailTo` to be set or the submission silently fails
4. File uploads require server-side configuration (max upload size in PHP/WordPress)

## Reference

Use `bricks:get_form_schema` for the complete form schema with all available settings and field types.
