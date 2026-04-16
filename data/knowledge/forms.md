# Forms in Bricks Builder

## Related Knowledge

- `building` — schema rules, where to put `_attributes` and `_conditions` on form fields
- `dynamic-data` — pre-fill field values via dynamic tags (`value: "{wp_user_email}"`)
- **Live form schema (authoritative):** `bricks:get_form_schema` — all 18 field types, action keys, examples. Always current.

## Structure

Form is a single element with all fields inside its settings — NOT a parent containing `form-field` children.

```json
{
  "name": "form",
  "settings": {
    "fields": [...],
    "actions": ["email"],
    "submitButtonText": "Send",
    "successMessage": "Thanks!"
  }
}
```

## Field Object

Every field needs `id` + `type`:

```json
{
  "id": "abc123",
  "type": "text",
  "label": "Name",
  "placeholder": "Your name",
  "required": true,
  "width": 50
}
```

### Required props
- `id` — **6-char lowercase alphanumeric** (same format as element IDs). Bricks uses `form-field-{id}` as the HTTP submission key.
- `type` — one of the 18 types below.

### Common optional props (any field type)
- `label` — text above the field
- `placeholder` — hint inside the field
- `value` — default value (supports dynamic tags)
- `required` — boolean
- `width` — column width 0–100 (percent; 100 = full width)
- `name` — custom HTML name attr (defaults to `form-field-{id}`)
- `errorMessage` — custom validation error text
- `isHoneypot` — invisible spam trap (works without any API key — use this first)

## Field Types (18)

| Type | Notes / extra props |
|---|---|
| `text` | + `minLength`, `maxLength`, `pattern` |
| `email` | auto-validates email format |
| `tel` | + `pattern` for format constraint |
| `url` | URL input |
| `number` | + `min`, `max`, `step` |
| `password` | + optional reveal toggle |
| `textarea` | + `height` |
| `richtext` | TinyMCE editor (Bricks 2.1+) + `height` |
| `select` | + `options` (newline string), `valueLabelOptions` (bool) |
| `checkbox` | + `options` (newline string), `valueLabelOptions` |
| `radio` | + `options` (newline string), `valueLabelOptions` |
| `file` | + `fileUploadLimit`, `fileUploadSize` (KB), `fileUploadAllowedTypes`, `fileUploadStorage` |
| `image` | image picker (Bricks 2.1+) |
| `gallery` | gallery picker |
| `datepicker` | Flatpickr; + `time` (bool), `l10n` (lang code) |
| `hidden` | + `value` (often a dynamic tag) |
| `html` | static HTML output, NOT an input |
| `rememberme` | "remember me" checkbox for login forms |

### Options format (footgun)

For `select` / `checkbox` / `radio`, `options` is a **newline-separated string**, NOT an array:

```json
{ "type": "select", "id": "country", "label": "Country",
  "options": "United States\nUnited Kingdom\nGermany\nFrance" }
```

For separate value/label pairs (value::label):

```json
{ "type": "select", "id": "size", "label": "Size",
  "valueLabelOptions": true,
  "options": "sm::Small\nmd::Medium\nlg::Large" }
```

## General Form Settings

| Setting | Default | Use |
|---|---|---|
| `submitButtonText` | `"Send"` | Button label |
| `successMessage` | — | Shown after successful submit |
| `requiredAsterisk` | `false` | Show `*` on required fields |
| `showLabels` | `true` | Show field labels |
| `disableBrowserValidation` | `false` | Adds HTML `novalidate` |
| `validateAllFieldsOnSubmit` | `false` | Show all errors at once vs first only |

## Spam Protection

| Method | Setup | Use |
|---|---|---|
| **Honeypot** | `isHoneypot: true` on a hidden field | Zero-config — use first, always |
| **reCAPTCHA v3** | `enableRecaptcha: true` + API keys in Bricks > Settings > API Keys | Google |
| **hCaptcha** | `enableHCaptcha: true` + API keys | Privacy-friendly alternative |
| **Cloudflare Turnstile** | `enableTurnstile: true` + API keys | Cloudflare |

## Actions (7)

`actions` is an array — runs sequentially after successful validation. **`redirect` always runs LAST regardless of array position.**

### `email` — send notification

```json
"actions": ["email"],
"emailSubject": "New contact submission",
"emailTo": "admin_email",        ← or specific address
"emailContent": "Name: {{name_id}}\n\nMessage:\n{{message_id}}",
"htmlEmail": true
```

Required: `emailSubject`, `emailTo` (use `"admin_email"` for the WP admin, or a literal email; `"custom"` then `emailToCustom` for a specific address).

Optional: `emailBcc`, `fromEmail`, `fromName`, `replyToEmail`, `emailContent`, `htmlEmail`, `emailErrorMessage`.

**Templating in `emailContent`:** `{{field_id}}` substitutes the value of the field with that id. `{{all_fields}}` dumps every field.

**Confirmation email** (separate from notification — sent to the submitter): `confirmationEmailSubject`, `confirmationEmailContent`, `confirmationEmailTo` (a field id whose value is the recipient email).

### `redirect` — navigate after submit

```json
"actions": ["redirect"],
"redirect": "/thank-you",
"redirectTimeout": 1500            ← optional ms delay
```

Always runs LAST — chain with `email` to notify then redirect.

### `webhook` — POST to external URL (Bricks 2.0+)

```json
"actions": ["webhook"],
"webhooks": [
  {
    "name": "Slack notification",
    "url": "https://hooks.slack.com/services/...",
    "contentType": "json",
    "dataTemplate": "{\"text\":\"New lead: {{name_id}} <{{email_id}}>\"}",
    "headers": "{\"Authorization\":\"Bearer xxx\"}"
  }
]
```

`dataTemplate` empty = sends all fields. Optional: `webhookMaxSize` (KB, default 1024), `webhookErrorIgnore`.

### `login` — authenticate user

```json
"actions": ["login", "redirect"],
"loginName": "lgn001",             ← field id holding email/username
"loginPassword": "lgn002",         ← field id holding password
"loginRemember": "lgn003",         ← optional: rememberme field id
"loginErrorMessage": "Invalid credentials",
"redirect": "/account"
```

### `registration` — create WP user

```json
"actions": ["registration", "redirect"],
"registrationEmail": "reg002",
"registrationPassword": "reg003",
"registrationUserName": "reg001",
"registrationFirstName": "reg004",
"registrationLastName": "reg005",
"registrationRole": "subscriber",   ← NEVER "administrator" — Bricks blocks
"registrationAutoLogin": true,
"registrationPasswordMinLength": 8,
"registrationWPNotification": false,
"redirect": "/welcome"
```

### `create-post` — create WP post (Bricks 2.1+)

```json
"actions": ["create-post"],
"createPostType": "feedback",
"createPostTitle": "fid001",        ← field id whose value becomes post title
"createPostContent": "fid002",
"createPostStatus": "draft",
"createPostMeta": [
  { "metaKey": "rating", "metaValue": "fid003", "sanitizationMethod": "absint" }
],
"createPostTaxonomies": [
  { "taxonomy": "category", "fieldId": "fid004" }
]
```

### `custom` — fire WP action

Implement via the `bricks/form/custom_action` filter hook in PHP.

## Field Validation

- `required: true` — mandatory
- Email type — auto-validates format
- `minLength` / `maxLength` — text/textarea
- `min` / `max` / `step` — number
- `pattern` — regex (text/tel)
- `fileUploadAllowedTypes` — comma-separated extensions (e.g. `"jpg,png,pdf"`)
- `fileUploadSize` — max bytes per file (KB)
- `errorMessage` — custom message per field

## Pre-fill Field Values

Set `value` to a string, dynamic tag, or both:

```json
{ "type": "email", "id": "em0001", "value": "{wp_user_email}" }     ← logged-in user's email
{ "type": "hidden", "id": "src001", "value": "{url_parameter:utm_source}" }
{ "type": "hidden", "id": "ref001", "value": "{post_id}" }
```

## Common Pitfalls

1. **Field IDs must be 6-char lowercase alphanumeric** — `"name"` or `"my_field"` is rejected. Use `wp_rand`-style 6-char ids like `abc123`.
2. **Options as array** — wrong. Bricks expects newline-separated string `"A\nB\nC"` (or `"value::label\n..."` with `valueLabelOptions: true`).
3. **`fields` not `formFields`** — the settings key is `fields`.
4. **`emailTo` missing** — silent failure on email action. Use `"admin_email"` for default recipient.
5. **`redirect` ordering** — runs LAST regardless of position in actions array. Don't fight it.
6. **`registrationRole: "administrator"`** — Bricks blocks for security. Use `subscriber`, `author`, `editor`, etc.
7. **CAPTCHA without API keys** — `enableRecaptcha: true` does nothing if API keys aren't configured in Bricks Settings. Use `isHoneypot` first (zero-config).
8. **File upload limits** — frontend `fileUploadSize` doesn't override PHP's `upload_max_filesize`. Configure server-side limits separately.
9. **Templating syntax** — `{{field_id}}` in `emailContent` / `dataTemplate` (double braces). Single-brace `{post_title}` is dynamic data, NOT a form-field substitution.
10. **`html` field type is output, not input** — submits no value. Use it for static instructions/disclaimers.

## Reference

- `bricks:get_form_schema` — full schema (18 field types, all action keys, working examples)
- `dynamic-data` knowledge — pre-fill values from current user / URL / post context
