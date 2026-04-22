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
        $registry_path = BRICKS_MCP_PLUGIN_DIR . 'data/elements.json';
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
                'text' => "[REFERENCE] User has supplied this known-good pattern for similar images. Use as stylistic guide — prefer its class names and structural conventions.\n" . wp_json_encode( $reference_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
            ];
        }

        $task = $mode === 'pattern'
            ? "[TASK] Analyze the image. Call emit_pattern with the structure. Prefer existing classes and variables listed above when visual function matches. Use BEM-style class names (block--modifier__element) for any new classes."
            : <<<'EOT'
[TASK] Analyze the image. Call emit_design_plan producing a design_plan exactly as a skilled human would type into propose_design — this is fed DIRECTLY into the existing build pipeline.

CRITICAL RULES:
1. elements[] is a flat list of content/leaf elements. Do NOT emit section/container/block wrappers — SchemaSkeletonGenerator adds the frame. Elements inside are: heading, text-basic, button, image, icon, list, slider, form, divider, etc.
2. Every styled element needs a class_intent LABEL (string, BEM-style like "hero__heading", "hero--dark__cta-primary"). The pipeline creates the class with styles derived from site design tokens. Do NOT put style values, CSS properties, or style objects anywhere — only LABELS.
3. Reuse existing site class names (listed above) when a class matches the element's visual function semantically. Only invent new class_intent labels when no existing class fits.
4. For repeating content (card grids, feature lists, testimonial sliders): use the patterns[] array — one pattern entry per repeat-template with name, repeat count, element_structure, content_hint.
5. content_hint per element is a short plain-text description of the intended content (e.g. "Main CTA button linking to contact", "Section tagline above the heading"). The pipeline uses these for content_plan and Unsplash queries.
6. section_type must match image intent (hero, features, pricing, cta, testimonials, split, generic).
7. background: pick dark or light based on dominant backdrop in image.
EOT;
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

    private function emit_design_plan_schema(): array {
        return [
            'name'        => 'emit_design_plan',
            'description' => 'Emit a design_plan compatible with propose_design — drives one-shot build.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'section_type' => [ 'type' => 'string', 'enum' => [ 'hero', 'features', 'pricing', 'cta', 'testimonials', 'split', 'generic' ] ],
                    'layout'       => [ 'type' => 'string', 'enum' => [ 'centered', 'split-60-40', 'split-50-50', 'grid-2', 'grid-3', 'grid-4' ] ],
                    'background'   => [ 'type' => 'string', 'enum' => [ 'dark', 'light' ] ],
                    'elements'     => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'type'         => [ 'type' => 'string', 'enum' => $this->get_valid_element_types(), 'description' => 'Bricks element type. MUST be one of the listed enum values — do not invent types.' ],
                                'role'         => [ 'type' => 'string' ],
                                'content_hint' => [ 'type' => 'string' ],
                                'tag'          => [ 'type' => 'string' ],
                                'class_intent' => [ 'type' => 'string', 'description' => 'BEM-style class label for this element (e.g. "hero__heading", "hero--dark__cta-primary"). Prefer existing site class names when the visual function matches; otherwise invent a new BEM label following site conventions. NEVER emit style values as class_intent — use labels only. The pipeline creates the class with appropriate styles drawn from site design tokens.' ],
                            ],
                            'required' => [ 'type', 'role' ],
                        ],
                    ],
                    'patterns'     => [
                        'type'        => 'array',
                        'description' => 'Optional repeat-templates (e.g. card grid where one card shape clones N times). Omit entirely if no repeating content. Never emit empty or incomplete entries.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'name'              => [ 'type' => 'string', 'description' => 'Template name (e.g. feature_card, testimonial).' ],
                                'repeat'            => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'How many clones to produce.' ],
                                'element_structure' => [
                                    'type'  => 'array',
                                    'items' => [
                                        'type'       => 'object',
                                        'properties' => [
                                            'type' => [ 'type' => 'string', 'enum' => $this->get_valid_element_types() ],
                                            'role' => [ 'type' => 'string' ],
                                        ],
                                        'required'   => [ 'type', 'role' ],
                                    ],
                                ],
                                'content_hint'      => [ 'type' => 'string' ],
                            ],
                            'required'   => [ 'name', 'repeat', 'element_structure' ],
                        ],
                    ],
                ],
                'required'   => [ 'section_type', 'layout', 'background', 'elements' ],
            ],
        ];
    }
}
