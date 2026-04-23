# Bricks MCP Implementation Status

This file summarizes the implementation work completed in the plugin so far and the remaining work.

It is written as a technical status document, not a marketing document.

## Goal

Make the plugin produce structurally valid, design-system-aware, reusable Bricks output that works:

- on fresh sites
- on sites with an existing custom design system
- with optional patterns
- from direct plans and image/reference-driven plans

The browser-based visual feedback layer is intentionally not included in the completed work below.

## What Has Been Implemented

### 1. Design system readiness and introspection

Implemented:

- `DesignSystemIntrospector`
- readiness separation between:
  - foundation design system
  - component style layer
  - pattern library
  - ready-for-design-build
- adaptive operating modes such as:
  - fresh site needs foundation
  - foundation only needs component layer
  - adaptive existing system
  - adaptive existing system with patterns

Why it matters:

- the plugin no longer assumes one fixed site state
- it can distinguish token readiness from component readiness from pattern readiness

### 2. Semantic style-role layer

Implemented:

- `StyleRoleResolver`
- semantic roles such as:
  - `button.primary`
  - `button.secondary`
  - `card.default`
  - `card.featured`
  - `text.eyebrow`
  - `text.subtitle`
  - token roles like primary/text/surface/etc.

Why it matters:

- the system can reason in semantic roles instead of guessing hardcoded class names

### 3. Existing-system mapping without hardcoding

Implemented:

- persistent `style_role` mapping tool
- mapping semantic roles to existing site classes/variables
- support for user-owned naming systems without forcing renames

Examples:

- `button.primary -> .cta-main`
- `color.primary -> --brand`

Why it matters:

- the system can build on top of an existing custom design system without hardcoded values

### 4. Token-driven fallback component layer

Implemented:

- `ComponentClassGenerator`
- generated fallback component classes when semantic roles are unresolved
- current fallback roles include:
  - primary button
  - secondary button
  - default card
  - featured card
  - eyebrow text
  - subtitle text

Why it matters:

- fresh/foundation-only sites no longer produce empty class shells for common components

### 5. Build contract

Implemented:

- `BuildContractService`
- rejection of new `class_intent` values when they have:
  - no existing styled class
  - no valid `style_overrides`
- rejection of empty visual classes
- warnings for unresolved/foreign variable usage

Why it matters:

- structurally valid but visually useless output is blocked earlier

### 6. Verification upgrades

Implemented:

- stronger `verify_build` quality reporting
- detection/reporting for:
  - missing class IDs
  - empty classes in use
  - unresolved variable references
  - foreign variable references
  - duplicate role labels
  - content contract state

Why it matters:

- verification now checks build quality, not just whether something got written

### 7. Content contract

Implemented:

- `ContentContractService`
- `build_structure` now returns `content_contract`
- `populate_content` enforces required content roles
- `allow_partial=true` support for intentional partial updates

Why it matters:

- content population is no longer loose and ambiguous

### 8. Plan normalization

Implemented:

- `DesignPlanNormalizationService`
- normalization of:
  - role keys
  - content-map keys
  - duplicate/empty global classes to create
  - invalid `class_intent` guesses
- rewrite of weak/invalid class guesses to semantic fallback classes where possible

Applied to:

- direct proposal flow
- image flow
- reference JSON flow

Why it matters:

- upstream model output is corrected before it damages the pipeline

### 9. Plan enrichment

Implemented:

- `DesignPlanEnrichmentService`
- rewrite of weak generic roles into stronger semantic roles
- synthesis of missing `content_hint` values
- propagation of rewritten keys into:
  - `content_plan`
  - `content_map`

Examples:

- `heading -> main_heading`
- `text -> subtitle` or `description_*`
- `button -> primary_cta`
- `image -> hero_image`
- repeat child roles like:
  - `feature_card_title`
  - `feature_card_text`
  - `tier_cta`
  - `testimonial_author`

Why it matters:

- the system now improves weak plans instead of only rejecting them

### 10. Structural repair pass

Implemented:

- `DesignPlanStructureRepairService`
- inserts missing singleton anchors when a plan is still under-specified after enrichment

Current repairs include:

- missing hero heading
- missing hero subtitle
- missing section heading
- missing CTA button
- missing split-layout media anchor

Response trace:

- `repair_log`

Why it matters:

- weak plans can still be made buildable without silently failing

### 11. Non-blocking quality analysis

Implemented:

- `DesignPlanQualityService`
- `design_plan_warnings`

Current warnings include:

- split layout with no media anchor
- CTA with no button
- hero with no heading
- generic role names
- missing media hints
- missing CTA hints
- repeated cards modeled inline
- repeated child media/button hints missing

Why it matters:

- the system now exposes why a plan is weak before build

### 12. Direct plan role normalization and collision handling

Implemented:

- duplicate direct roles rejected early
- duplicate roles inside repeat templates rejected
- built role collisions returned as `role_collisions`
- role keys normalized consistently across:
  - direct proposals
  - build structure
  - content population
  - verify build

Why it matters:

- `populate_content` can target elements reliably

### 13. Pattern/image/reference path alignment

Implemented:

- image/reference flows now go through:
  - normalization
  - enrichment
  - repeat extraction
  - composition
  - repair
  - quality analysis
- `from_image` responses now expose:
  - `normalization_log`
  - `enrichment_log`
  - `repeat_extraction_log`
  - `composition_family`
  - `composition_log`
  - `repair_log`
  - `design_plan_warnings`

Why it matters:

- image/reference path is no longer a weak parallel pipeline

### 14. Vision prompt hardening

Implemented:

- `VisionPromptBuilder` strengthened substantially
- prompt now includes:
  - design-system readiness context
  - resolved semantic style roles
  - generated fallback component classes
- prompt now explicitly enforces:
  - semantic role specificity
  - no wrapper elements in `design_plan.elements`
  - patterns as optional, not required
  - richer repeated-item guidance
  - clearer distinction between section bucket and real composition family

Why it matters:

- upstream model output is guided toward the pipeline’s actual contract

### 15. Repeated-item role expansion

Implemented:

- shared `RepeatRoleNamingService`
- repeated `patterns[]` children now expand into unique per-item roles

Examples:

- `feature_card_1_title`
- `feature_card_2_text`
- `tier_3_cta`
- `testimonial_3_author`

Why it matters:

- repeated builds no longer collide on one shared role label

### 16. Repeated-item content planning

Implemented:

- repeated child hints synthesized at the child level
- proposal `content_plan` now expands repeated child hints into indexed keys

Examples:

- `feature_card_1_title`
- `feature_card_2_text`
- `tier_3_cta`

Why it matters:

- repeated sections now have explicit content guidance before populate phase

### 17. Automatic inline repeat extraction

Implemented:

- `DesignPlanRepeatExtractionService`
- detects indexed flat repeated direct roles and converts them into `patterns[]`

Examples:

- `feature_card_1_title`, `feature_card_2_text`, `feature_card_3_cta`
- `tier_title_1`, `tier_price_1`, `tier_title_2`, `tier_price_2`

Response trace:

- `repeat_extraction_log`

Why it matters:

- the system can repair flat repeated plans into proper repeat templates automatically

### 18. Extensible composition layer

Implemented:

- `DesignPlanCompositionService`
- family/trait-based composition pass, not a fixed hardcoded taxonomy
- broad built-in composition families currently cover:
  - hero/banner style openings
  - media/text split sections
  - repeat-grid sections
  - action-prompt sections
  - general content stacks

What it does:

- infers a composition family from plan traits
- reorders direct elements into more coherent flow
- adjusts obviously weak layouts

Current layout corrections include:

- repeat-heavy plans -> `grid-*`
- media + text stacks -> `split-50-50`
- CTA/simpler prompt flows -> centered when split is weak

Response trace:

- `composition_family`
- `composition_log`

Why it matters:

- the plugin is no longer relying only on cleanup passes
- it now has a real composition grammar layer

### 19. Page-layout skeleton alignment

Implemented:

- `PageLayoutService` now uses stronger semantic roles/hints
- page-layout skeletons now pass through the same composition layer

Why it matters:

- layout recommendations and proposal/build behavior no longer drift apart

### 20. Documentation updates

Implemented:

- root/plugin docs updated to reflect actual behavior
- stale claims removed
- current pipeline behavior documented for:
  - adaptive design systems
  - semantic roles
  - build/content contracts
  - repeat extraction
  - repeated-item keys
  - composition layer

Primary updated files include:

- `readme.txt`
- `data/knowledge/building.md`
- `CHANGELOG.md`

## Implementation Characteristics

These are important architectural decisions already reflected in the code:

### A. No hardcoded site values

The implementation is built around:

- semantic roles
- mapping to existing systems
- token-driven fallbacks
- adaptive class/variable reuse

It is specifically designed so people can build on existing systems without fixed class names or fixed CSS values.

### B. Patterns are optional, not required

The system is designed so that:

- direct plan builds work without any pattern library
- patterns are acceleration and reuse, not a hard dependency

### C. Repair layers are visible

When the server has to reshape the plan, it exposes logs instead of hiding the intervention:

- `normalization_log`
- `enrichment_log`
- `repeat_extraction_log`
- `composition_log`
- `repair_log`
- `design_plan_warnings`

### D. Browser-based feedback was intentionally excluded

No browser feedback verification layer has been implemented yet in this status file’s completed section.

That work remains separate.

## What Still Remains To Be Done

This section is intentionally strict. It lists what is not yet complete.

### 1. Browser/visual feedback verification layer

Not implemented yet.

This includes any external visual audit layer such as:

- Playwright
- browser automation feedback
- screenshot comparison
- rendered DOM/visual assertions

This should remain outside the WordPress plugin runtime and be implemented as an external companion layer.

### 2. Live integrated end-to-end verification on a real Bricks site

The code has been linted and smoke-tested, but full live integration validation against a running WordPress + Bricks 2.0+ site still remains.

That includes validating:

- direct plan path
- image/reference path
- existing custom design system path
- fresh-site fallback path
- repeated content population path
- real-world class/variable creation behavior

### 3. More composition families over time

The composition layer is now extensible, but the registry is still young.

What remains:

- expand the composition family registry over time
- add more family trait definitions without breaking the generic architecture
- tune ordering/layout recommendations from real usage

Important:

This is not a missing architecture piece anymore.
It is ongoing expansion/tuning work on top of an implemented extensible system.

### 4. Real-world tuning of image/reference fidelity

The non-browser implementation is in place, but real-world tuning will still be needed after live usage:

- prompt tuning from actual failures
- composition trait tuning
- repeat extraction thresholds
- quality warning thresholds
- better family-specific ordering heuristics where justified by evidence

This is tuning work, not missing foundational implementation.

## Practical Summary

Core plugin-side implementation is effectively complete except for the external browser feedback/visual verification layer and live real-site validation/tuning.

The main architecture now exists for:

- adaptive design-system reuse
- fresh-site fallback generation
- plan normalization/enrichment/repair
- repeat extraction and repeated-item handling
- composition-family shaping
- direct/image/reference alignment

The remaining work is operational validation, tuning, and the separate browser-feedback track.
