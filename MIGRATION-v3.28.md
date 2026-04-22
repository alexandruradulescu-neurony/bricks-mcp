# Migration guide — Pattern + Class System v3 (v3.28.0)

## Tool changes

### `build_from_schema` → two tools

Before:
```
build_from_schema({ proposal_id, schema: { ...elements with content... } })
```

After:
```
build_structure({ proposal_id, schema: { ...elements, NO content fields... } })
  → returns { section_id, role_map, classes_created, classes_reused }

populate_content({ section_id, content_map: { role: value } })
  → returns { injected_count, unmatched_roles }
```

`build_from_schema` still registered but returns `{ error: "tool_deprecated", next_step: "..." }`.

## Schema changes

### Content fields forbidden in build_structure schema

Rejected fields anywhere in schema tree: `content, content_example, label, text, title, description, link, href, icon, image, src, url, placeholder`.

### design_plan element-level content_hint moved to content_plan

Before:
```json
{ "type": "heading", "role": "heading_main", "content_hint": "5-8 word headline" }
```

After:
```json
{ "type": "heading", "role": "heading_main" }

// And in design_plan:
{ "content_plan": { "heading_main": "5-8 word headline" } }
```

## class_intent shape

### Structured (preferred)
```json
"class_intent": { "block": "hero", "modifier": "b2b", "element": "title" }
→ normalized to: "hero--b2b__title"
```

### Loose string (accepted)
```json
"class_intent": "hero b2b title"
→ normalized to: "hero--b2b__title"

"class_intent": "HeroTitle"
→ normalized to: "hero-title"
```

### Null or omitted
```json
"class_intent": null
// Element remains classless. Only valid when element has no style_overrides.
```

## Dedup policy

- New classes: always BEM.
- Style-signature dedup applies across in-section + pattern pool.
- Legacy (non-BEM) classes on site remain but are NEVER auto-reused — only matched by explicit class_intent.
- AI proposes `hero--b2b__title`, site has `btn-b2b-primary` with identical style → new class created, legacy untouched.

## content_map keys

Role-keyed (default):
```json
{ "heading_main": "...", "cta_primary": { "label": "...", "link": "..." } }
```

ID-prefixed (direct target, takes precedence):
```json
{ "#elem_abc": "Overrides specific element" }
```

## Pattern system additions

Every pattern now carries:
- `bem_purity`: float 0.0-1.0
- `non_bem_classes`: list of legacy class names in pattern
- `bem_migration_hints`: map of legacy → suggested BEM equivalent

Surfaced in:
- Pattern catalog entries (discovery response)
- Admin pattern detail panel (BEM compliance tile)

## One-time migration

On first admin page load after v3.28.0 activation:
1. All stored patterns get bem_purity + non_bem_classes + bem_migration_hints augmentation.
2. Guarded by flag `bricks_mcp_patterns_v3_28_metadata_applied`.
3. Idempotent — safe to re-run.
4. Admin notice appears once (dismissible).

No existing classes or patterns are renamed or deleted.

## Deferred

- Admin "Apply BEM migration" button that actually renames classes across the site — shipped in v3.28.1 or later.
- `from_image` capture action — already deferred from v2.
- Required-role marking — already deferred from v2.
