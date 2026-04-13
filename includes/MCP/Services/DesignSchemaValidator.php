<?php
/**
 * Design schema validator.
 *
 * Validates incoming design schemas for the build_from_schema pipeline.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates design schema structure and references.
 */
final class DesignSchemaValidator {

	/**
	 * @var SchemaGenerator
	 */
	private SchemaGenerator $schema_generator;

	/**
	 * Cached element catalog (name => true map for O(1) lookups).
	 *
	 * @var array<string, true>|null
	 */
	private ?array $element_names = null;

	/**
	 * Cached hierarchy rules from data/element-hierarchy-rules.json.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $hierarchy_rules = null;

	/**
	 * Constructor.
	 *
	 * @param SchemaGenerator $schema_generator Schema generator for element type validation.
	 */
	public function __construct( SchemaGenerator $schema_generator ) {
		$this->schema_generator = $schema_generator;
	}

	/**
	 * Load hierarchy rules from the data file (cached in static property).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_hierarchy_rules(): array {
		if ( null === self::$hierarchy_rules ) {
			$path = dirname( __DIR__, 3 ) . '/data/element-hierarchy-rules.json';
			if ( file_exists( $path ) ) {
				$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$data = is_string( $json ) ? json_decode( $json, true ) : [];
				self::$hierarchy_rules = $data['rules'] ?? [];
			} else {
				self::$hierarchy_rules = [];
			}
		}
		return self::$hierarchy_rules;
	}

	/**
	 * Validate a design schema.
	 *
	 * @param array<string, mixed> $schema The design schema to validate.
	 * @return true|\WP_Error True if valid, WP_Error with all validation errors.
	 */
	public function validate( array $schema ): true|\WP_Error {
		$errors = [];

		// Validate target.
		if ( empty( $schema['target'] ) || ! is_array( $schema['target'] ) ) {
			$errors[] = 'target is required and must be an object.';
		} else {
			$target = $schema['target'];
			$page_id = $target['page_id'] ?? null;
			$template_id = $target['template_id'] ?? null;

			if ( null === $page_id && null === $template_id ) {
				$errors[] = 'target.page_id or target.template_id is required.';
			} else {
				$post_id = $page_id ?? $template_id;
				if ( ! is_numeric( $post_id ) ) {
					$errors[] = 'target.page_id/template_id must be a positive integer.';
				} else {
					$post = get_post( (int) $post_id );
					if ( ! $post ) {
						$errors[] = sprintf( 'target post %d does not exist.', (int) $post_id );
					}
				}
			}

			$valid_actions = [ 'append', 'replace' ];
			if ( empty( $target['action'] ) || ! in_array( $target['action'], $valid_actions, true ) ) {
				$errors[] = 'target.action is required and must be "append" or "replace".';
			}
		}

		// Validate design_context.
		if ( empty( $schema['design_context'] ) || ! is_array( $schema['design_context'] ) ) {
			$errors[] = 'design_context is required and must be an object.';
		} else {
			if ( empty( $schema['design_context']['summary'] ) ) {
				$errors[] = 'design_context.summary is required.';
			}
			$valid_spacing = [ 'compact', 'normal', 'spacious' ];
			if ( isset( $schema['design_context']['spacing'] ) && ! in_array( $schema['design_context']['spacing'], $valid_spacing, true ) ) {
				$errors[] = 'design_context.spacing must be "compact", "normal", or "spacious".';
			}
		}

		// Validate sections.
		if ( empty( $schema['sections'] ) || ! is_array( $schema['sections'] ) ) {
			$errors[] = 'sections is required and must be a non-empty array.';
		} else {
			foreach ( $schema['sections'] as $idx => $section ) {
				$path = "sections[{$idx}]";
				if ( empty( $section['intent'] ) ) {
					$errors[] = "{$path}.intent is required.";
				}
				if ( empty( $section['structure'] ) || ! is_array( $section['structure'] ) ) {
					$errors[] = "{$path}.structure is required and must be an object.";
				} else {
					$this->validate_structure_node( $section['structure'], "{$path}.structure", $schema['patterns'] ?? [], $errors );
				}
			}
		}

		// Validate patterns if provided.
		// Patterns are templates — skip parent-child hierarchy checks (they'll be validated in context when expanded).
		if ( isset( $schema['patterns'] ) ) {
			if ( ! is_array( $schema['patterns'] ) ) {
				$errors[] = 'patterns must be an object.';
			} else {
				foreach ( $schema['patterns'] as $name => $pattern ) {
					$this->validate_structure_node( $pattern, "patterns.{$name}", $schema['patterns'], $errors, '_pattern' );
				}
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'invalid_design_schema',
				sprintf( 'Design schema has %d validation error(s): %s', count( $errors ), implode( '; ', $errors ) ),
				[ 'errors' => $errors ]
			);
		}

		return true;
	}

	/**
	 * Valid keys allowed on structure nodes.
	 *
	 * Any key NOT in this list triggers a validation error, preventing
	 * AI clients from passing raw Bricks settings or invented keys.
	 */
	private const VALID_NODE_KEYS = [
		'type', 'tag', 'label', 'content',
		'class_intent', 'style_overrides', 'responsive_overrides',
		'layout', 'columns', 'responsive',
		'background',
		'children',
		'icon', 'iconPosition', 'src', 'form_type',
		// Pattern/repeat keys.
		'ref', 'repeat', 'data',
		// Element-specific settings escape hatch.
		'element_settings',
	];

	/**
	 * Per-element settings that can be passed via the element_settings escape hatch.
	 *
	 * Most elements get their settings from style_overrides + content + class_intent,
	 * but some Bricks elements have type-specific settings that don't fit those
	 * categories (pie-chart percent, counter count-to, video URL, etc.).
	 *
	 * The validator allows element_settings ONLY for the element types listed here,
	 * and only with the keys whitelisted per type. This keeps the attack surface narrow
	 * while letting AI clients fully use these elements.
	 */
	private const ELEMENT_SETTINGS_ALLOWED = [
		'pie-chart'       => [ 'percent', 'content', 'barColor', 'trackColor', 'size', 'lineWidth' ],
		'counter'         => [ 'countTo', 'countFrom', 'prefix', 'suffix', 'duration', 'separator' ],
		'video'           => [ 'videoType', 'ytId', 'vimeoId', 'mp4', 'fileUrl', 'iframeUrl', 'autoplay', 'loop', 'muted', 'controls', 'preload' ],
		'slider-nested'   => [ 'sliderOptions' ],
		'form'            => [ 'formFields', 'actions', 'submitButtonText', 'submitButtonStyle' ],
		'progress-bar'    => [ 'bars', 'showLabel', 'showPercentage', 'animation' ],
		'rating'          => [ 'rating', 'maxRating', 'icon', 'starColor', 'starColorEmpty' ],
		'animated-typing' => [ 'strings', 'typeSpeed', 'backSpeed', 'loop' ],
	];

	/**
	 * Common mistakes mapped to correct key names for helpful error messages.
	 */
	private const KEY_SUGGESTIONS = [
		'settings'     => 'style_overrides',
		'styles'       => 'style_overrides',
		'css'          => 'style_overrides',
		'_background'  => 'style_overrides (nest _background inside style_overrides)',
		'_padding'     => 'style_overrides (nest _padding inside style_overrides)',
		'_margin'      => 'style_overrides (nest _margin inside style_overrides)',
		'_display'     => 'style_overrides (nest _display inside style_overrides)',
		'_direction'   => 'style_overrides (nest _direction inside style_overrides)',
		'_gap'         => 'style_overrides (nest _gap inside style_overrides)',
		'_minHeight'   => 'style_overrides (nest _minHeight inside style_overrides)',
		'_gradient'    => 'style_overrides (nest _gradient inside style_overrides)',
		'_typography'  => 'style_overrides (nest _typography inside style_overrides)',
		'_color'       => 'style_overrides (nest _color inside style_overrides)',
		'_border'      => 'style_overrides (nest _border inside style_overrides)',
		'_width'       => 'style_overrides (nest _width inside style_overrides)',
		'_height'      => 'style_overrides (nest _height inside style_overrides)',
		'text'         => 'content',
		'classes'      => 'class_intent',
		'class'        => 'class_intent',
		'image'        => 'src',
	];

	/**
	 * Recursively validate a structure node.
	 *
	 * @param array<string, mixed>  $node        The structure node.
	 * @param string                $path        JSON path for error reporting.
	 * @param array<string, mixed>  $patterns    Available pattern definitions.
	 * @param array<int, string>    &$errors     Error collector.
	 * @param string                $parent_type Parent element type ('root' for top-level).
	 */
	private function validate_structure_node( array $node, string $path, array $patterns, array &$errors, string $parent_type = 'root' ): void {
		// If it's a ref, validate the reference exists.
		if ( ! empty( $node['ref'] ) ) {
			$ref_name = $node['ref'];
			if ( ! isset( $patterns[ $ref_name ] ) ) {
				$errors[] = "{$path}.ref \"{$ref_name}\" does not match any defined pattern.";
			}
			// Ref nodes don't need a type — the pattern provides it.
			return;
		}

		// Reject unknown keys — prevents AI clients from passing raw Bricks settings.
		foreach ( array_keys( $node ) as $key ) {
			if ( ! in_array( $key, self::VALID_NODE_KEYS, true ) ) {
				$suggestion = self::KEY_SUGGESTIONS[ $key ] ?? null;
				$hint       = $suggestion ? " Did you mean \"{$suggestion}\"?" : ' Check the schema format documentation.';
				$errors[]   = "{$path}: unknown key \"{$key}\".{$hint}";
			}
		}

		// Validate type.
		if ( empty( $node['type'] ) ) {
			$errors[] = "{$path}.type is required (Bricks element name).";
			return;
		}

		$type = $node['type'];

		if ( ! $this->is_valid_element_type( $type ) ) {
			$errors[] = "{$path}.type \"{$type}\" is not a known Bricks element.";
		}

		// Validate element_settings if provided.
		if ( isset( $node['element_settings'] ) ) {
			if ( ! is_array( $node['element_settings'] ) ) {
				$errors[] = "{$path}.element_settings must be an object.";
			} elseif ( ! isset( self::ELEMENT_SETTINGS_ALLOWED[ $type ] ) ) {
				$allowed_types = implode( ', ', array_keys( self::ELEMENT_SETTINGS_ALLOWED ) );
				$errors[] = "{$path}.element_settings is not allowed on element type \"{$type}\" — only on: {$allowed_types}.";
			} else {
				$allowed_keys = self::ELEMENT_SETTINGS_ALLOWED[ $type ];
				foreach ( array_keys( $node['element_settings'] ) as $es_key ) {
					if ( ! in_array( $es_key, $allowed_keys, true ) ) {
						$errors[] = "{$path}.element_settings.{$es_key} is not allowed on element type \"{$type}\". Allowed keys: " . implode( ', ', $allowed_keys ) . '.';
					}
				}
			}
		}

		// Validate parent-child hierarchy. Skip for pattern definitions (_pattern parent).
		$rules = self::get_hierarchy_rules();
		if ( '_pattern' !== $parent_type && isset( $rules[ $type ] ) ) {
			$valid_parents = $rules[ $type ]['valid_parents'] ?? [];
			if ( ! empty( $valid_parents ) && ! in_array( $parent_type, $valid_parents, true ) ) {
				$errors[] = "{$path}: \"{$type}\" is not typically placed inside \"{$parent_type}\". Expected parents: " . implode( ', ', $valid_parents ) . '.';
			}

			// Check if element accepts children but none provided, or doesn't accept but has children.
			$accepts = $rules[ $type ]['accepts_children'] ?? true;
			if ( ! $accepts && ! empty( $node['children'] ) ) {
				$errors[] = "{$path}: \"{$type}\" does not accept children, but children were provided.";
			}
		}

		// Validate children recursively.
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $child_idx => $child ) {
				if ( is_array( $child ) ) {
					$this->validate_structure_node( $child, "{$path}.children[{$child_idx}]", $patterns, $errors, $type );
				}
			}
		}

		// Validate responsive if provided.
		if ( isset( $node['responsive'] ) && ! is_array( $node['responsive'] ) ) {
			$errors[] = "{$path}.responsive must be an object.";
		}

		// Validate style_overrides if provided.
		if ( isset( $node['style_overrides'] ) && ! is_array( $node['style_overrides'] ) ) {
			$errors[] = "{$path}.style_overrides must be an object.";
		}
	}

	/**
	 * Check if an element type name is valid in Bricks.
	 *
	 * @param string $type Element type name.
	 * @return bool
	 */
	private function is_valid_element_type( string $type ): bool {
		if ( null === $this->element_names ) {
			$this->element_names = [];
			foreach ( $this->schema_generator->get_element_catalog() as $entry ) {
				$this->element_names[ $entry['name'] ] = true;
			}
		}

		return isset( $this->element_names[ $type ] );
	}

	/**
	 * Validate an expanded structure node (post-expansion).
	 *
	 * Similar to validate_structure_node() but does NOT check for refs
	 * (they are already resolved) and DOES check hierarchy and accepts_children.
	 *
	 * @param array<string, mixed> $node        The expanded structure node.
	 * @param string               $path        JSON path for error reporting.
	 * @param array<int, string>   &$errors     Error collector.
	 * @param string               $parent_type Parent element type ('root' for top-level).
	 */
	public function validate_expanded_node( array $node, string $path, array &$errors, string $parent_type = 'root' ): void {
		if ( empty( $node['type'] ) ) {
			$errors[] = "{$path}: missing type after expansion.";
			return;
		}

		$type = $node['type'];

		if ( ! $this->is_valid_element_type( $type ) ) {
			$errors[] = "{$path}: \"{$type}\" is not a known Bricks element.";
		}

		// Hierarchy check (skip for pattern context).
		$rules = self::get_hierarchy_rules();
		if ( '_pattern' !== $parent_type && isset( $rules[ $type ] ) ) {
			$valid_parents = $rules[ $type ]['valid_parents'] ?? [];
			if ( ! empty( $valid_parents ) && ! in_array( $parent_type, $valid_parents, true ) ) {
				$errors[] = "{$path}: \"{$type}\" not valid inside \"{$parent_type}\".";
			}
			$accepts = $rules[ $type ]['accepts_children'] ?? true;
			if ( ! $accepts && ! empty( $node['children'] ) ) {
				$errors[] = "{$path}: \"{$type}\" does not accept children.";
			}
		}

		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $child_idx => $child ) {
				if ( is_array( $child ) ) {
					$this->validate_expanded_node( $child, "{$path}.children[{$child_idx}]", $errors, $type );
				}
			}
		}
	}

	/**
	 * Extract all unique element types referenced in the schema.
	 *
	 * @param array<string, mixed> $schema The validated design schema.
	 * @return array<int, string> Unique element type names.
	 */
	public function extract_element_types( array $schema ): array {
		$types = [];

		foreach ( $schema['sections'] ?? [] as $section ) {
			if ( ! empty( $section['structure'] ) ) {
				$this->collect_types( $section['structure'], $types );
			}
		}

		foreach ( $schema['patterns'] ?? [] as $pattern ) {
			$this->collect_types( $pattern, $types );
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Extract all unique class_intent values from the schema.
	 *
	 * @param array<string, mixed> $schema The validated design schema.
	 * @return array<int, string> Unique class intent strings.
	 */
	public function extract_class_intents( array $schema ): array {
		$intents = [];

		foreach ( $schema['sections'] ?? [] as $section ) {
			if ( ! empty( $section['structure'] ) ) {
				$this->collect_intents( $section['structure'], $intents );
			}
		}

		foreach ( $schema['patterns'] ?? [] as $pattern ) {
			$this->collect_intents( $pattern, $intents );
		}

		return array_values( array_unique( $intents ) );
	}

	/**
	 * Recursively collect element types from a structure node.
	 *
	 * @param array<string, mixed>   $node   Structure node.
	 * @param array<int, string>     &$types Type collector.
	 */
	private function collect_types( array $node, array &$types ): void {
		if ( ! empty( $node['type'] ) ) {
			$types[] = $node['type'];
		}
		foreach ( $node['children'] ?? [] as $child ) {
			if ( is_array( $child ) ) {
				$this->collect_types( $child, $types );
			}
		}
	}

	/**
	 * Recursively collect class_intent values from a structure node.
	 *
	 * @param array<string, mixed>   $node     Structure node.
	 * @param array<int, string>     &$intents Intent collector.
	 */
	private function collect_intents( array $node, array &$intents ): void {
		if ( ! empty( $node['class_intent'] ) ) {
			$intents[] = $node['class_intent'];
		}
		foreach ( $node['children'] ?? [] as $child ) {
			if ( is_array( $child ) ) {
				$this->collect_intents( $child, $intents );
			}
		}
	}

	/**
	 * Extract a map of class_intent => style_overrides from the schema.
	 *
	 * For each node that has both class_intent and style_overrides,
	 * maps the intent to its styles. First occurrence wins if the
	 * same intent appears with different overrides.
	 *
	 * @param array<string, mixed> $schema The validated design schema.
	 * @return array<string, array<string, mixed>> class_intent => style_overrides map.
	 */
	public function extract_class_styles( array $schema ): array {
		$style_map = [];

		foreach ( $schema['sections'] ?? [] as $section ) {
			if ( ! empty( $section['structure'] ) ) {
				$this->collect_class_styles( $section['structure'], $style_map );
			}
		}

		foreach ( $schema['patterns'] ?? [] as $pattern ) {
			$this->collect_class_styles( $pattern, $style_map );
		}

		return $style_map;
	}

	/**
	 * Recursively collect class_intent => style_overrides pairs.
	 *
	 * @param array<string, mixed>              $node      Structure node.
	 * @param array<string, array<string, mixed>> &$style_map Style map collector.
	 */
	private function collect_class_styles( array $node, array &$style_map ): void {
		$intent = $node['class_intent'] ?? '';
		$styles = $node['style_overrides'] ?? [];

		// Only map if both class_intent and style_overrides exist, and first occurrence wins.
		if ( '' !== $intent && ! empty( $styles ) && ! isset( $style_map[ $intent ] ) ) {
			$style_map[ $intent ] = $styles;
		}

		foreach ( $node['children'] ?? [] as $child ) {
			if ( is_array( $child ) ) {
				$this->collect_class_styles( $child, $style_map );
			}
		}
	}
}
