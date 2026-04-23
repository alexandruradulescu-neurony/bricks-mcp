<?php
/**
 * Vision prompt builder.
 *
 * Assembles system message, site-context text block, reference_json block,
 * task instruction, and the tool schema for either pattern or schema output.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class VisionPromptBuilder {

    private const CLASS_PREVIEW_CAP = 40;

    /**
     * Cache of valid Bricks element types loaded from data/elements.json.
     * @var array<int, string>|null
     */
    private static ?array $element_types_cache = null;

    /**
     * Load the canonical list of Bricks element types from the registry
     * (data/elements.json). Used to enum-constrain vision tool schemas so the
     * model cannot invent element types that don't exist in Bricks.
     *
     * @return array<int, string> Sorted list of element type keys.
     */
    private function get_valid_element_types(): array {
        if ( self::$element_types_cache !== null ) {
            return self::$element_types_cache;
        }
        // Primary: constant-based path (correct in a live WordPress install).
        // Fallback: resolve relative to this file for test environments where
        // BRICKS_MCP_PLUGIN_DIR may not point at the plugin root.
        $registry_path = BRICKS_MCP_PLUGIN_DIR . 'data/elements.json';
        if ( ! is_readable( $registry_path ) ) {
            // Fallback for test bootstrap where BRICKS_MCP_PLUGIN_DIR points at dev/.
            // Depth 4: Services → MCP → includes → plugin root.
            $registry_path = dirname( __FILE__, 4 ) . '/data/elements.json';
        }
        if ( ! is_readable( $registry_path ) ) {
            self::$element_types_cache = [];
            return [];
        }
        $raw  = file_get_contents( $registry_path );
        $data = is_string( $raw ) ? json_decode( $raw, true ) : null;
        $keys = is_array( $data ) && isset( $data['elements'] ) && is_array( $data['elements'] )
            ? array_keys( $data['elements'] )
            : [];
        sort( $keys );
        self::$element_types_cache = $keys;
        return $keys;
    }

    /**
     * Build prompt + schema for the pattern-save flow (emit_pattern).
     *
     * @param array{classes: array, variables: array, theme: string} $site_context
     * @param array<string, mixed>|null $reference_json
     * @return array{tool_schema: array, messages: array}
     */
    public function build_for_pattern( array $site_context, ?array $reference_json ): array {
        return [
            'tool_schema' => $this->emit_pattern_schema(),
            'messages'    => $this->build_messages( $site_context, $reference_json, 'pattern' ),
        ];
    }

    /**
     * Build prompt + schema for the one-shot build flow (emit_design_plan).
     *
     * @param array{classes: array, variables: array, theme: string} $site_context
     * @param array<string, mixed>|null $reference_json
     * @return array{tool_schema: array, messages: array}
     */
    public function build_for_schema( array $site_context, ?array $reference_json ): array {
        return [
            'tool_schema' => $this->emit_design_plan_schema(),
            'messages'    => $this->build_messages( $site_context, $reference_json, 'schema' ),
        ];
    }

    private function build_messages( array $site_context, ?array $reference_json, string $mode ): array {
        $messages = [];

        $messages[] = [ 'type' => 'text', 'text' => $this->render_site_context( $site_context ) ];

        if ( is_array( $reference_json ) && $reference_json !== [] ) {
            $messages[] = [
                'type' => 'text',
                'text' => "[REFERENCE] Authoritative template. TRANSLATE its globalClasses and variable references to site equivalents:\n"
                    . "- For each class in globalClasses[], rename to BEM using site conventions (drop foreign prefixes like 'hero-69__', replace with role-appropriate site prefix or existing class name when visual function matches).\n"
                    . "- For each var(--brxw-*) reference in class settings, pick the closest matching site variable from [SITE CONTEXT] variables above based on actual style value intent.\n"
                    . "- Structure (element tree) preserved verbatim from reference.content[]; do NOT reshape.\n"
                    . "- Emit translations via global_classes_to_create[] in your tool call.\n\n"
                    . wp_json_encode( $reference_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
            ];
        }

        if ( $mode === 'pattern' ) {
            $task = "[TASK] Analyze the image. Call emit_pattern with the structure. Prefer existing classes and variables listed above when visual function matches. Use BEM-style class names (block--modifier__element) for any new classes.";
        } else {
            // schema mode = design_plan output for propose_design
            $task = <<<'EOT'
[TASK] Analyze the image. Call emit_design_plan. Output MUST be the exact shape a human types into propose_design(description, design_plan) — this feeds the existing build pipeline directly. No parallel schema.

CRITICAL RULES (deviation = wrong):

1. class_intent values are BEM string LABELS ONLY. Never style values, never var(--*), never objects, never arrays. Labels like "hero__heading", "hero--dark__cta-primary".

2. Prefer existing site class names (listed above) when visual function matches. Only invent new BEM labels when no existing class fits.

3. elements[] is FLAT — content/leaf elements only (heading, text-basic, button, image, icon, list, slider, form, divider, image-gallery, etc.). NEVER emit type: section / container / block / div — these wrappers are added by the pipeline. The enum on the elements[].type field already excludes them; do not try.

4. For REPEATING content (card grids, feature lists, testimonial sliders, image galleries of >2 identical tiles), use patterns[] — one pattern per template with name, repeat count, element_structure, content_hint. Do NOT clone elements inline. For a small fixed image row (2–5 visible images as a single gallery unit), emit ONE element with type: image-gallery and content_hint describing the set.

5. content_hint per element = short plain-text description of intended content (e.g. "Main CTA button linking to contact", "24/7 towing service tagline"). The pipeline uses these for content_plan and for image elements to run media:smart_search against site's business_brief.

6. section_type matches image intent (hero|features|pricing|cta|testimonials|split|generic).
   layout matches visual arrangement (centered|split-60-40|split-50-50|grid-2..4|stacked).
   background = dark or light per dominant backdrop.

6b. section_type is only a coarse bucket, not the full family taxonomy. There can be many composition families inside the same bucket. Express the real family through layout choice, element ordering, media presence, CTA grouping, and repeat templates instead of flattening everything into one generic stack.

6a. Prefer specific semantic roles instead of generic labels:
   - direct elements: main_heading, section_heading, eyebrow, subtitle, primary_cta, secondary_cta, hero_image, section_image_1
   - repeated items: feature_card_title, feature_card_text, tier_title, tier_cta, testimonial_text, testimonial_author
   Avoid bare roles like heading, text, button, image, content, card, item.

7. description field = ≤200 chars human-readable summary of what the section is.

8. global_classes_to_create is REQUIRED whenever you introduce a NEW class_intent label (one that does not already exist in [SITE CONTEXT] classes). For each new class_intent, emit a matching global_classes_to_create[] entry with site-design-token style values INFERRED FROM THE IMAGE — approximate font sizes, colors, paddings, alignments you see in the image, expressed in Bricks underscore-prefix keys. Values MUST reference site variables (var(--text-2xl), var(--space-m), var(--primary-ultra-dark), var(--radius-btn), etc.) — NEVER hardcode pixel/rem values, NEVER use var(--brxw-*). Empty-shell classes produce unstyled output; fill them. You may omit a class entry ONLY if its class_intent label is already defined in [SITE CONTEXT] (reuse).

8a. Bricks setting key shapes (use EXACT shape — wrong shape renders as [object Object]):
- SCALAR string keys (emit as `"_key": "value"`, NOT as `"_key": {...}`):
  `_width`, `_widthMin`, `_widthMax`, `_height`, `_heightMin`, `_heightMax`,
  `_display`, `_direction`, `_alignItems`, `_justifyContent`, `_flexWrap`, `_flexGrow`, `_flexBasis`,
  `_aspectRatio`, `_objectFit`, `_textAlign`, `_columnGap`, `_rowGap`.
  Example CORRECT: `"_widthMax": "var(--max-width)"`, `"_textAlign": "center"`.
  Example WRONG: `"_width": {"maxWidth": "var(--max-width)"}` — produces [object Object].
- NESTED object keys (emit as `"_key": { top|right|bottom|left: "value" }`):
  `_padding`, `_margin`, `_border.radius`, `_border.width`.
  Example: `"_padding": {"top": "var(--space-s)", "bottom": "var(--space-s)"}`.
- TYPOGRAPHY object (`_typography`): keys are KEBAB-CASE (`font-size`, `font-weight`, `line-height`, `letter-spacing`, `text-transform`, `text-align`, `color`). NOT camelCase — Bricks CSS compiler silently drops `fontSize` etc and only `color` renders. `color` is `{ "raw": "var(--token)" }`. For headings (h1-h6) do NOT set `font-size` — child theme tag selectors win on specificity; rely on `var(--h1)..var(--h6)` or use `text-basic` if you need custom size.
- BACKGROUND object (`_background`): `{ "backgroundColor": "var(--token)" }` or `{ "color": { "raw": "var(--token)" } }`.

9. content_map is OPTIONAL — include when image text is legible and intent-specific (e.g. Romanian text reading "Tractări 24/7"). Map role → literal content string. Omit for generic image intent.

10. The plan must work even if the site has NO saved patterns. Use direct elements for one-off content. Use patterns[] only when the image clearly shows repeated cards/tiers/testimonials that share the same structure.
EOT;
        }
        $messages[] = [ 'type' => 'text', 'text' => $task ];

        return $messages;
    }

    private function render_site_context( array $site_context ): string {
        $classes   = $site_context['classes'] ?? [];
        $variables = $site_context['variables'] ?? [];
        $theme     = (string) ( $site_context['theme'] ?? '' );

        $lines   = [ '[SITE CONTEXT]' ];
        if ( $theme !== '' ) {
            $lines[] = 'theme: ' . $theme;
        }

        if ( $variables !== [] ) {
            $lines[] = '';
            $lines[] = 'variables:';
            foreach ( $variables as $name => $value ) {
                $lines[] = '  ' . $name . ': ' . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
            }
        }

        $design_system = is_array( $site_context['design_system'] ?? null ) ? $site_context['design_system'] : [];
        if ( $design_system !== [] ) {
            $lines[] = '';
            $lines[] = 'design_system:';

            $operating_mode = (string) ( $design_system['operating_mode'] ?? '' );
            if ( '' !== $operating_mode ) {
                $lines[] = '  operating_mode: ' . $operating_mode;
            }

            $readiness = is_array( $design_system['readiness'] ?? null ) ? $design_system['readiness'] : [];
            foreach ( [ 'foundation_design_system', 'component_style_layer', 'pattern_library' ] as $key ) {
                if ( ! is_array( $readiness[ $key ] ?? null ) ) {
                    continue;
                }
                $ready = ! empty( $readiness[ $key ]['ready'] ) ? 'ready' : 'not_ready';
                $score = isset( $readiness[ $key ]['score'] ) ? (string) (int) $readiness[ $key ]['score'] : '0';
                $lines[] = '  ' . $key . ': ' . $ready . ' (' . $score . ')';
            }

            $style_roles = is_array( $design_system['style_roles'] ?? null ) ? $design_system['style_roles'] : [];
            if ( $style_roles !== [] ) {
                $lines[] = '  style_roles:';
                foreach ( $this->compact_style_role_preview( $style_roles ) as $preview ) {
                    $lines[] = '    ' . $preview;
                }
            }

            $component_classes = is_array( $design_system['component_classes'] ?? null ) ? $design_system['component_classes'] : [];
            if ( $component_classes !== [] ) {
                $lines[] = '  generated_components:';
                foreach ( $component_classes as $semantic_role => $definition ) {
                    if ( ! is_array( $definition ) || empty( $definition['name'] ) ) {
                        continue;
                    }
                    $lines[] = '    ' . $semantic_role . ' -> ' . (string) $definition['name'];
                }
            }
        }

        if ( $classes !== [] ) {
            $lines[] = '';
            $lines[] = 'classes (preview, top ' . self::CLASS_PREVIEW_CAP . '; remaining names-only):';
            $detailed = array_slice( $classes, 0, self::CLASS_PREVIEW_CAP, true );
            foreach ( $detailed as $name => $def ) {
                $preview = $this->compact_class_preview( $def );
                $lines[] = '  ' . $name . ': {' . $preview . '}';
            }
            $remaining = array_keys( array_slice( $classes, self::CLASS_PREVIEW_CAP, null, true ) );
            if ( $remaining !== [] ) {
                $lines[] = '';
                $lines[] = 'classes (names only):';
                foreach ( $remaining as $name ) {
                    $lines[] = '  ' . $name;
                }
            }
        }

        return implode( "\n", $lines );
    }

    private function compact_class_preview( $class_def ): string {
        if ( ! is_array( $class_def ) ) {
            return '';
        }
        $tokens = $class_def['style_tokens'] ?? [];
        if ( ! is_array( $tokens ) ) {
            return '';
        }
        $pick = [];
        foreach ( [ '_background', '_color', '_font-size', '_padding', '_margin' ] as $key ) {
            if ( isset( $tokens[ $key ] ) ) {
                $pick[] = ltrim( $key, '_' ) . ': ' . ( is_scalar( $tokens[ $key ] ) ? (string) $tokens[ $key ] : wp_json_encode( $tokens[ $key ] ) );
            }
        }
        return implode( ', ', $pick );
    }

    /**
     * @param array<string, array<string, mixed>> $style_roles
     * @return array<int, string>
     */
    private function compact_style_role_preview( array $style_roles ): array {
        $preview = [];
        foreach ( $style_roles as $role => $resolution ) {
            if ( ! is_array( $resolution ) ) {
                continue;
            }
            $status = (string) ( $resolution['status'] ?? 'unresolved' );
            $kind   = (string) ( $resolution['kind'] ?? '' );
            if ( 'resolved' === $status && 'class' === $kind && ! empty( $resolution['class_name'] ) ) {
                $preview[] = $role . ' -> class:' . (string) $resolution['class_name'];
                continue;
            }
            if ( 'resolved' === $status && 'token' === $kind && ! empty( $resolution['token_name'] ) ) {
                $preview[] = $role . ' -> token:' . (string) $resolution['token_name'];
                continue;
            }
            if ( count( $preview ) < 8 ) {
                $preview[] = $role . ' -> unresolved';
            }
        }
        return array_slice( $preview, 0, 12 );
    }

    private function emit_pattern_schema(): array {
        $node_schema = [
            'type'       => 'object',
            'properties' => [
                'type'         => [ 'type' => 'string', 'enum' => $this->get_valid_element_types(), 'description' => 'Bricks element type. MUST be one of the listed enum values — do not invent types. Common picks: section, container, block, div, heading, text-basic, button, image, icon, slider, carousel, form, divider.' ],
                'role'         => [ 'type' => 'string', 'description' => 'Semantic role (e.g. heading_main, eyebrow, subtitle, cta_primary, feature_card)' ],
                'tag'          => [ 'type' => 'string', 'description' => 'HTML tag override (e.g. h1, h2)' ],
                'class_refs'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Existing or new class names for this node' ],
                'style_tokens' => [ 'type' => 'object', 'description' => 'Style properties as key-value (use var(--name) to reference variables)' ],
                'children'     => [ 'type' => 'array', 'description' => 'Nested child nodes (same shape)' ],
                'repeat'       => [ 'type' => 'boolean', 'description' => 'True if this node is a template that will be cloned for each item' ],
                'required'     => [ 'type' => 'boolean', 'description' => 'True if this role must be supplied during populate_content' ],
            ],
            'required' => [ 'type' ],
        ];
        return [
            'name'        => 'emit_pattern',
            'description' => 'Emit a Bricks pattern structure captured from the image.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'structure'  => $node_schema,
                    'layout'     => [ 'type' => 'string', 'enum' => [ 'centered', 'split-60-40', 'split-50-50', 'grid-2', 'grid-3', 'grid-4', 'stacked' ] ],
                    'background' => [ 'type' => 'string', 'enum' => [ 'dark', 'light' ] ],
                    'notes'      => [ 'type' => 'string' ],
                ],
                'required'   => [ 'structure' ],
            ],
        ];
    }

    /**
     * Leaf-only element types for design_plan.elements[] and patterns[].element_structure[].
     *
     * SchemaSkeletonGenerator adds the section/container/block wrapper frame. Vision
     * emitting a wrapper inline here produces container-in-container schema errors
     * (validated by DesignSchemaValidator). This filter is the first line of defense;
     * VisionResponseMapper::extract_tool_output coerces any that slip through.
     *
     * @return array<int, string>
     */
    private function get_leaf_element_types(): array {
        $wrappers = [ 'section', 'container', 'block', 'div' ];
        $all      = $this->get_valid_element_types();
        return array_values( array_filter( $all, static fn( $t ) => ! in_array( $t, $wrappers, true ) ) );
    }

    private function emit_design_plan_schema(): array {
        $leaf_types = $this->get_leaf_element_types();

        $element_item = [
            'type'       => 'object',
            'properties' => [
                'type'         => [
                    'type' => 'string',
                    'enum' => $leaf_types,
                    'description' => 'Bricks LEAF element type (content/visual leaf — NOT wrappers). section/container/block/div are emitted by the pipeline, not you. For repeating image galleries use type: image-gallery or type: slider-nested; for simple image rows use multiple type: image entries inside patterns[].',
                ],
                'role'         => [ 'type' => 'string', 'description' => 'Semantic role snake_case (e.g. heading_main, cta_primary, feature_card_heading).' ],
                'content_hint' => [ 'type' => 'string', 'description' => 'Short plain-text description of intended content. Drives content_plan + Unsplash smart_search for images.' ],
                'tag'          => [ 'type' => 'string', 'description' => 'Optional HTML tag override (e.g. h1, h2).' ],
                'class_intent' => [
                    'type' => 'string',
                    'description' => 'BEM-style class label ONLY (e.g. "hero__heading", "hero--dark__cta-primary"). NEVER emit style values, CSS properties, var(--*) references, or objects here. Pipeline creates the class with styles from site design tokens. Prefer existing site class names when visual function matches.',
                ],
            ],
            'required'   => [ 'type', 'role' ],
        ];

        $pattern_item = [
            'type'       => 'object',
            'properties' => [
                'name'              => [ 'type' => 'string', 'description' => 'Template name (e.g. feature_card).' ],
                'repeat'            => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'How many clones to produce.' ],
                'element_structure' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'type'         => [ 'type' => 'string', 'enum' => $leaf_types ],
                            'role'         => [ 'type' => 'string' ],
                            'tag'          => [ 'type' => 'string' ],
                            'class_intent' => [ 'type' => 'string', 'description' => 'Optional BEM-style class label for this repeated child.' ],
                            'content_hint' => [ 'type' => 'string', 'description' => 'Optional short hint for the repeated child content.' ],
                        ],
                        'required'   => [ 'type', 'role' ],
                    ],
                ],
                'content_hint'      => [ 'type' => 'string' ],
            ],
            'required'   => [ 'name', 'repeat', 'element_structure' ],
        ];

        $design_plan = [
            'type'       => 'object',
            'properties' => [
                'section_type' => [ 'type' => 'string', 'enum' => [ 'hero', 'features', 'pricing', 'cta', 'testimonials', 'split', 'generic' ] ],
                'layout'       => [ 'type' => 'string', 'enum' => [ 'centered', 'split-60-40', 'split-50-50', 'grid-2', 'grid-3', 'grid-4', 'stacked' ] ],
                'background'   => [ 'type' => 'string', 'enum' => [ 'dark', 'light' ] ],
                'elements'     => [ 'type' => 'array', 'items' => $element_item ],
                'patterns'     => [
                    'type'        => 'array',
                    'description' => 'Optional repeat-templates (card grids, testimonial sliders). Omit entirely if no repeats.',
                    'items'       => $pattern_item,
                ],
            ],
            'required'   => [ 'section_type', 'layout', 'background', 'elements' ],
        ];

        $gc_item = [
            'type'       => 'object',
            'properties' => [
                'name'     => [ 'type' => 'string', 'description' => 'BEM-normalized class name (e.g. hero__heading).' ],
                'settings' => [
                    'type'        => 'object',
                    'description' => 'Style settings keyed by Bricks underscore-prefix (_typography, _padding, _background, etc.). Values MAY reference var(--site-token). NEVER reference var(--brxw-*) or foreign tokens — translate to site equivalents first.',
                ],
            ],
            'required'   => [ 'name', 'settings' ],
        ];

        return [
            'name'         => 'emit_design_plan',
            'description'  => 'Emit description + design_plan (exact shape for propose_design Phase 2) + optional global_classes_to_create + optional content_map. class_intent fields MUST be BEM string labels only.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'description'              => [ 'type' => 'string', 'description' => 'Human-readable intent summary (≤200 chars).' ],
                    'design_plan'              => $design_plan,
                    'global_classes_to_create' => [
                        'type'        => 'array',
                        'description' => 'Optional. Include when reference JSON supplies explicit style values that must be preserved (translation case). Each class settings uses Bricks underscore-prefix keys and MAY reference var(--site-token).',
                        'items'       => $gc_item,
                    ],
                    'content_map'              => [
                        'type'        => 'object',
                        'description' => 'Optional. Maps role → literal content string. When omitted, pipeline uses content_hint + business_brief.',
                    ],
                ],
                'required'   => [ 'description', 'design_plan' ],
            ],
        ];
    }
}
