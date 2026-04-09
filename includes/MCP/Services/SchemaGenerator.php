<?php
/**
 * Bricks element schema generator service.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SchemaGenerator class.
 *
 * Converts Bricks element registry controls into JSON Schema format.
 * Caches results via WordPress transients with Bricks version in cache key.
 */
class SchemaGenerator {

	/**
	 * Cache option name prefix for schema storage (non-autoloaded wp_options).
	 * Survives full object cache flushes by WP Rocket and similar plugins.
	 * @var string
	 */
	private const CACHE_OPTION_PREFIX = 'bricks_mcp_schema_cache';

	/**
	 * Cache expiry option name.
	 * @var string
	 */
	private const CACHE_EXPIRY_OPTION = 'bricks_mcp_schema_cache_expires';

	/**
	 * Cache duration in seconds (24 hours).
	 * @var int
	 */
	private const CACHE_DURATION = DAY_IN_SECONDS;

	/**
	 * CSS-to-Bricks property mapping for AI reference.
	 * @var array<string, string>
	 */
	private const CSS_PROPERTY_MAP = [
		'_padding'             => 'padding (top/right/bottom/left object)',
		'_margin'              => 'margin (top/right/bottom/left object)',
		'_background'          => 'background-color via color.raw/hex',
		'_gradient'            => 'background gradient (colors array with color+stop, angle)',
		'_typography'          => 'font-size, font-weight, color, line-height, text-align, letter-spacing, text-transform, font-family',
		'_direction'           => 'flex-direction',
		'_display'             => 'display (flex, grid, block, none)',
		'_alignItems'          => 'align-items',
		'_justifyContent'      => 'justify-content',
		'_flexWrap'            => 'flex-wrap',
		'_flexGrow'            => 'flex-grow',
		'_flexShrink'          => 'flex-shrink',
		'_columnGap'           => 'column-gap',
		'_rowGap'              => 'row-gap',
		'_gap'                 => 'gap (shorthand, for grid)',
		'_gridTemplateColumns' => 'grid-template-columns',
		'_gridTemplateRows'    => 'grid-template-rows',
		'_alignItemsGrid'      => 'align-items (grid context)',
		'_border'              => 'border (width/style/color/radius object)',
		'_width'               => 'width',
		'_widthMax'            => 'max-width',
		'_widthMin'            => 'min-width',
		'_height'              => 'height',
		'_overflow'            => 'overflow',
		'_position'            => 'position (relative, absolute, fixed, sticky)',
		'_top'                 => 'top',
		'_right'               => 'right',
		'_bottom'              => 'bottom',
		'_left'                => 'left',
		'_zIndex'              => 'z-index',
		'_opacity'             => 'opacity',
		'_transform'           => 'transform',
		'_transition'          => 'transition',
		'_boxShadow'           => 'box-shadow',
		'_cssCustom'           => 'Custom CSS block (any CSS, use #brxe-{id} selector)',
		'_cssGlobalClasses'    => 'Array of global class IDs to apply',
	];

	/**
	 * Element nesting rules for AI reference.
	 * @var array<string, array<string, mixed>>
	 */
	private const NESTING_RULES = [
		'section'          => [ 'accepts_children' => true,  'nestable' => false, 'typical_children' => [ 'container' ], 'typical_parents' => [ 'root' ] ],
		'container'        => [ 'accepts_children' => true,  'nestable' => false, 'typical_children' => [ 'heading', 'text-basic', 'button', 'image', 'container', 'block' ], 'typical_parents' => [ 'section', 'container', 'block', 'div' ] ],
		'block'            => [ 'accepts_children' => true,  'nestable' => false, 'typical_children' => [ 'heading', 'text-basic', 'button', 'image', 'container', 'block' ], 'typical_parents' => [ 'section', 'container', 'block', 'div' ] ],
		'div'              => [ 'accepts_children' => true,  'nestable' => false, 'typical_children' => [ 'heading', 'text-basic', 'button', 'image' ], 'typical_parents' => [ 'section', 'container', 'block', 'div' ] ],
		'heading'          => [ 'accepts_children' => false, 'nestable' => false ],
		'text-basic'       => [ 'accepts_children' => false, 'nestable' => false ],
		'text'             => [ 'accepts_children' => false, 'nestable' => false ],
		'text-link'        => [ 'accepts_children' => false, 'nestable' => false ],
		'button'           => [ 'accepts_children' => false, 'nestable' => false ],
		'image'            => [ 'accepts_children' => false, 'nestable' => false ],
		'icon'             => [ 'accepts_children' => false, 'nestable' => false ],
		'video'            => [ 'accepts_children' => false, 'nestable' => false ],
		'code'             => [ 'accepts_children' => false, 'nestable' => false ],
		'divider'          => [ 'accepts_children' => false, 'nestable' => false ],
		'list'             => [ 'accepts_children' => false, 'nestable' => false ],
		'icon-box'         => [ 'accepts_children' => false, 'nestable' => false ],
		'map'              => [ 'accepts_children' => false, 'nestable' => false ],
		'form'             => [ 'accepts_children' => false, 'nestable' => false ],
		'rating'           => [ 'accepts_children' => false, 'nestable' => false ],
		'counter'          => [ 'accepts_children' => false, 'nestable' => false ],
		'countdown'        => [ 'accepts_children' => false, 'nestable' => false ],
		'progress-bar'     => [ 'accepts_children' => false, 'nestable' => false ],
		'pie-chart'        => [ 'accepts_children' => false, 'nestable' => false ],
		'alert'            => [ 'accepts_children' => false, 'nestable' => false ],
		'accordion'        => [ 'accepts_children' => false, 'nestable' => false ],
		'tabs'             => [ 'accepts_children' => false, 'nestable' => false ],
		'slider'           => [ 'accepts_children' => false, 'nestable' => false ],
		'accordion-nested' => [ 'accepts_children' => true,  'nestable' => true, 'typical_children' => [ 'block' ], 'typical_parents' => [ 'section', 'container' ] ],
		'tabs-nested'      => [ 'accepts_children' => true,  'nestable' => true, 'typical_children' => [ 'block' ], 'typical_parents' => [ 'section', 'container' ] ],
		'slider-nested'    => [ 'accepts_children' => true,  'nestable' => true, 'typical_children' => [ 'block' ], 'typical_parents' => [ 'section', 'container' ] ],
		'nav-nested'       => [ 'accepts_children' => true,  'nestable' => true, 'typical_children' => [ 'block' ], 'typical_parents' => [ 'section', 'container' ] ],
		'offcanvas'        => [ 'accepts_children' => true,  'nestable' => true, 'typical_children' => [ 'block', 'container' ], 'typical_parents' => [ 'section', 'container' ] ],
		'dropdown'         => [ 'accepts_children' => true,  'nestable' => true, 'typical_children' => [ 'block' ], 'typical_parents' => [ 'container', 'block' ] ],
		'toggle'           => [ 'accepts_children' => true,  'nestable' => true, 'typical_children' => [ 'block' ], 'typical_parents' => [ 'container', 'block' ] ],
		'popup'            => [ 'accepts_children' => true,  'nestable' => true, 'typical_children' => [ 'block', 'container' ], 'typical_parents' => [ 'section', 'container' ] ],
		'template'         => [ 'accepts_children' => false, 'nestable' => true ],
	];

	/**
	 * Cached CSS property map loaded from data file.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $css_property_map_data = null;

	/**
	 * Get CSS-to-Bricks property mapping.
	 *
	 * Loads from data/css-property-map.json for the full structured map,
	 * falls back to the hardcoded constant for backward compatibility.
	 *
	 * @return array<string, string> Key → description map (legacy format for schema responses).
	 */
	public function get_css_property_map(): array {
		return self::CSS_PROPERTY_MAP;
	}

	/**
	 * Get the full structured CSS property map from the data file.
	 *
	 * Returns the complete map with type, format, and css info for each property.
	 *
	 * @return array<string, array<string, string>> Key → {css, type, format} map.
	 */
	public static function get_css_property_map_full(): array {
		if ( null === self::$css_property_map_data ) {
			$path = dirname( __DIR__, 3 ) . '/data/css-property-map.json';
			if ( file_exists( $path ) ) {
				$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$data = is_string( $json ) ? json_decode( $json, true ) : [];
				self::$css_property_map_data = $data['properties'] ?? [];
			} else {
				self::$css_property_map_data = [];
			}
		}
		return self::$css_property_map_data;
	}

	/**
	 * Check if a settings key is a valid Bricks CSS property.
	 *
	 * Accepts both base keys (_padding) and composite keys (_padding:mobile:hover).
	 *
	 * @param string $key The settings key to validate.
	 * @return bool True if valid.
	 */
	public static function is_valid_settings_key( string $key ): bool {
		// Extract base key from composite key (e.g., _padding:mobile → _padding).
		$base_key = str_contains( $key, ':' ) ? substr( $key, 0, strpos( $key, ':' ) ) : $key;

		// Non-underscore keys are element-specific settings (text, tag, label, etc.) — always valid.
		if ( ! str_starts_with( $base_key, '_' ) ) {
			return true;
		}

		$map = self::get_css_property_map_full();
		return isset( $map[ $base_key ] );
	}

	/**
	 * Get nesting rules for a specific element or all elements.
	 *
	 * @param string|null $element_name Element name, or null for all rules.
	 * @return array<string, mixed>
	 */
	public function get_nesting_rules( ?string $element_name = null ): array {
		if ( null !== $element_name ) {
			return self::NESTING_RULES[ $element_name ] ?? [ 'accepts_children' => false, 'nestable' => false ];
		}
		return self::NESTING_RULES;
	}

	/**
	 * Get all element schemas from Bricks registry.
	 *
	 * Returns the full catalog of all registered element types with their
	 * JSON Schema definitions and minimal working examples.
	 * Results are cached via transients using the Bricks version as cache key.
	 *
	 * @return array<string, array<string, mixed>> Map of element name => schema data.
	 */
	public function get_all_schemas(): array {
		if ( ! class_exists( '\Bricks\Elements' ) ) {
			return [];
		}

		// Flush stale caches when Bricks version changes.
		$current_version = $this->get_bricks_version();
		$last_version    = get_option( 'bricks_mcp_schema_version', '' );
		if ( $last_version !== $current_version ) {
			$this->flush_cache();
			update_option( 'bricks_mcp_schema_version', $current_version, true );
		}

		$cache_key = self::CACHE_OPTION_PREFIX . '_' . str_replace( '.', '_', $current_version );
		$cached    = $this->read_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		$schemas = [];

		// Bricks\Elements::$elements is the registered element registry.
		if ( ! isset( \Bricks\Elements::$elements ) || ! is_array( \Bricks\Elements::$elements ) ) {
			return [];
		}

		foreach ( \Bricks\Elements::$elements as $element_name => $element_entry ) {
			// Bricks stores elements as arrays with 'class' key or as class strings/objects.
			$element_class = is_array( $element_entry ) ? ( $element_entry['class'] ?? null ) : $element_entry;

			if ( null === $element_class ) {
				continue;
			}

			$element_obj = $this->get_element_object( $element_class );

			if ( null === $element_obj ) {
				continue;
			}

			$schemas[ $element_name ] = [
				'name'            => $element_name,
				'label'           => $this->get_element_label( $element_obj ),
				'category'        => $this->get_element_category( $element_obj ),
				'nesting'         => $this->get_nesting_rules( $element_name ),
				'settings_schema' => $this->convert_to_json_schema( $element_obj ),
				'working_example' => $this->generate_working_example( $element_name, $element_obj ),
			];
		}

		$this->write_cache( $cache_key, $schemas );

		return $schemas;
	}

	/**
	 * Get schema for a single element type.
	 *
	 * @param string $element_name The element type name (e.g., 'heading', 'section').
	 * @return array<string, mixed>|\WP_Error Schema data or WP_Error if not found.
	 */
	public function get_element_schema( string $element_name ): array|\WP_Error {
		$all_schemas = $this->get_all_schemas();

		if ( empty( $all_schemas ) && ! class_exists( '\Bricks\Elements' ) ) {
			return new \WP_Error(
				'bricks_not_active',
				__( 'Bricks Builder must be installed and active to retrieve element schemas.', 'bricks-mcp' )
			);
		}

		if ( ! isset( $all_schemas[ $element_name ] ) ) {
			// Suggest similar element names.
			$similar = $this->find_similar_element_names( $element_name, array_keys( $all_schemas ) );

			return new \WP_Error(
				'element_not_found',
				sprintf(
					/* translators: %s: Element type name */
					__( 'Element type "%s" not found in Bricks element registry.', 'bricks-mcp' ),
					$element_name
				),
				[
					'element_type' => $element_name,
					'suggestions'  => $similar,
				]
			);
		}

		return $all_schemas[ $element_name ];
	}

	/**
	 * Get simplified element catalog without full schemas.
	 *
	 * Returns only element name, label, and category for quick reference.
	 *
	 * @return array<int, array<string, string>> List of elements with name, label, category.
	 */
	public function get_element_catalog(): array {
		$all_schemas = $this->get_all_schemas();
		$catalog     = [];

		foreach ( $all_schemas as $element_name => $schema ) {
			$catalog[] = [
				'name'     => $element_name,
				'label'    => $schema['label'] ?? $element_name,
				'category' => $schema['category'] ?? 'general',
			];
		}

		// Sort by category then name.
		usort(
			$catalog,
			static function ( array $a, array $b ): int {
				$cat_cmp = strcmp( $a['category'], $b['category'] );
				if ( 0 !== $cat_cmp ) {
					return $cat_cmp;
				}
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return $catalog;
	}

	/**
	 * Convert a Bricks element's controls to JSON Schema Draft 2020-12.
	 *
	 * Maps Bricks control types to JSON Schema types.
	 * Controls with responsive variants note the key format {key}:{breakpoint}:{pseudo}.
	 *
	 * @param object $element The Bricks element object.
	 * @return array<string, mixed> JSON Schema object for element settings.
	 */
	public function convert_to_json_schema( object $element ): array {
		$controls = [];

		if ( method_exists( $element, 'get_controls' ) ) {
			$controls = $element->get_controls();
		} elseif ( isset( $element->controls ) && is_array( $element->controls ) ) {
			$controls = $element->controls;
		}

		if ( empty( $controls ) || ! is_array( $controls ) ) {
			return [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => true,
			];
		}

		$properties    = [];
		$required      = [];
		$control_count = 0;

		foreach ( $controls as $control_key => $control ) {
			if ( ! is_array( $control ) || ! isset( $control['type'] ) ) {
				continue;
			}

			// Skip group/section/tab separators.
			if ( in_array( $control['type'], [ 'group', 'section', 'tab', 'separator', 'data' ], true ) ) {
				continue;
			}

			++$control_count;
			if ( $control_count > 200 ) {
				// Prevent excessive iteration on complex elements.
				break;
			}

			$control_schema = $this->map_control_type_to_schema( $control );

			// Add description from control definition.
			if ( ! empty( $control['label'] ) ) {
				$control_schema['description'] = $control['label'];
			} elseif ( ! empty( $control['placeholder'] ) ) {
				$control_schema['description'] = $control['placeholder'];
			}

			// Note responsive/state support.
			if ( ! empty( $control['css'] ) ) {
				$existing_desc                 = $control_schema['description'] ?? '';
				$control_schema['description'] = trim( $existing_desc . ' Supports responsive variants: {key}:{breakpoint}:{pseudo} (e.g., ' . $control_key . ':tablet_portrait, ' . $control_key . ':mobile:hover). Use get_breakpoints tool for valid breakpoint names.' );
			}

			$properties[ $control_key ] = $control_schema;

			// Mark as required if explicitly set.
			if ( ! empty( $control['required'] ) && true === $control['required'] ) {
				$required[] = $control_key;
			}
		}

		$schema = [
			'type'                 => 'object',
			'properties'           => empty( $properties ) ? new \stdClass() : $properties,
			'additionalProperties' => true,
		];

		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Generate a minimal working example for an element.
	 *
	 * Returns an example element array with minimal valid settings.
	 *
	 * @param string $element_name The element type name.
	 * @param object $element      The Bricks element object.
	 * @return array<string, mixed> Minimal working example element.
	 */
	public function generate_working_example( string $element_name, object $element ): array {
		// Known element examples with sensible defaults.
		$known_examples = [
			// === Layout elements ===
			'section'    => [],
			'container'  => [],
			'block'      => [],
			'div'        => [],

			// === Basic elements ===
			'heading'    => [
				'text' => 'Heading Text',
				'tag'  => 'h2',
			],
			'text-basic' => [ 'text' => 'Paragraph text' ],
			'text'       => [ 'text' => '<p>Paragraph text</p>' ],
			'text-link'  => [ 'text' => 'Link text', 'link' => [ 'type' => 'external', 'url' => '#' ] ],
			'button'     => [
				'text'  => 'Click Me',
				'style' => 'primary',
				'link'  => [ 'type' => 'external', 'url' => '#' ],
			],
			'image'      => [
				'image' => [
					'id'       => 0,
					'filename' => 'placeholder.jpg',
					'size'     => 'full',
					'full'     => 'https://placeholder.example/800x400',
					'url'      => 'https://placeholder.example/800x400',
				],
			],
			'video'      => [
				'videoType'                       => 'youtube',
				'youTubeId'                       => 'dQw4w9WgXcQ',
				'youtubeControls'                 => true,
				'youtubeDisableFullscreenButton'  => false,
				'youtubeHideAnnotationsByDefault' => false,
			],
			'icon'       => [
				'icon' => [ 'library' => 'themify', 'icon' => 'ti-star' ],
			],

			// === General elements ===
			'divider'    => [],
			'icon-box'   => [
				'icon'    => [ 'library' => 'themify', 'icon' => 'ti-wordpress' ],
				'content' => '<h4>Icon box heading</h4><p>Icon box description text.</p>',
			],
			'list'       => [
				'items' => [
					[ 'id' => 'lst001', 'title' => 'List item #1', 'meta' => '$10.00', 'link' => [ 'type' => 'external', 'url' => '#' ] ],
					[ 'id' => 'lst002', 'title' => 'List item #2', 'meta' => '$25.00' ],
				],
			],
			'accordion'  => [
				'accordions' => [
					[ 'id' => 'acc001', 'title' => 'Accordion Item 1', 'subtitle' => 'More details', 'content' => 'Content of accordion item 1.' ],
					[ 'id' => 'acc002', 'title' => 'Accordion Item 2', 'content' => 'Content of accordion item 2.' ],
				],
				'titleTag'     => 'h4',
				'icon'         => [ 'library' => 'ionicons', 'icon' => 'ion-ios-arrow-forward' ],
				'iconExpanded' => [ 'library' => 'ionicons', 'icon' => 'ion-ios-arrow-down' ],
			],
			'alert'      => [
				'content' => '<p>This is an alert message.</p>',
				'type'    => 'info',
			],
			'animated-typing' => [
				'prefix'    => 'We ',
				'suffix'    => ' for you!',
				'strings'   => [
					[ 'id' => 'aty001', 'text' => 'design' ],
					[ 'id' => 'aty002', 'text' => 'code' ],
					[ 'id' => 'aty003', 'text' => 'launch' ],
				],
				'typeSpeed' => 55,
				'backSpeed' => 30,
				'loop'      => true,
			],
			'countdown'  => [
				'date'   => '2026-12-31 00:00',
				'fields' => [
					[ 'id' => 'cdf001', 'format' => '%D days' ],
					[ 'id' => 'cdf002', 'format' => '%H hours' ],
					[ 'id' => 'cdf003', 'format' => '%M minutes' ],
					[ 'id' => 'cdf004', 'format' => '%S seconds' ],
				],
			],
			'counter'    => [
				'countTo' => '1000',
			],
			'tabs'       => [
				'tabs' => [
					[ 'id' => 'tab001', 'title' => 'Tab 1', 'content' => 'Tab 1 content goes here.' ],
					[ 'id' => 'tab002', 'title' => 'Tab 2', 'content' => 'Tab 2 content goes here.' ],
				],
				'titlePadding'   => [ 'top' => 15, 'right' => 20, 'bottom' => 15, 'left' => 20 ],
				'contentPadding' => [ 'top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20 ],
			],
			'pricing-tables' => [
				'pricingTables' => [
					[
						'id'           => 'prc001',
						'title'        => 'Basic',
						'subtitle'     => 'For individuals',
						'pricePrefix'  => '$',
						'price'        => '9',
						'priceSuffix'  => '90',
						'priceMeta'    => 'per month',
						'features'     => "Feature 1\nFeature 2\nFeature 3",
						'featuresIcon' => [ 'library' => 'ionicons', 'icon' => 'ion-ios-checkmark-circle-outline' ],
						'buttonText'   => 'Choose Plan',
						'buttonStyle'  => 'primary',
					],
					[
						'id'           => 'prc002',
						'title'        => 'Pro',
						'subtitle'     => 'For teams',
						'pricePrefix'  => '$',
						'price'        => '29',
						'priceSuffix'  => '90',
						'priceMeta'    => 'per month',
						'features'     => "All Basic features\nFeature 4\nFeature 5",
						'featuresIcon' => [ 'library' => 'ionicons', 'icon' => 'ion-ios-checkmark-circle-outline' ],
						'buttonText'   => 'Choose Plan',
						'buttonStyle'  => 'primary',
					],
				],
			],
			'progress-bar' => [
				'bars' => [
					[ 'id' => 'prb001', 'title' => 'Web Design', 'percentage' => 80 ],
					[ 'id' => 'prb002', 'title' => 'SEO', 'percentage' => 90 ],
				],
				'showPercentage' => true,
			],
			'pie-chart'  => [
				'percent'    => 60,
				'content'    => 'percent',
				'barColor'   => [ 'hex' => '#3b82f6' ],
				'trackColor' => [ 'hex' => '#e5e7eb' ],
			],
			'rating'     => [
				'rating' => 3.5,
			],
			'social-icons' => [
				'icons' => [
					[
						'id'         => 'soc001',
						'label'      => 'Facebook',
						'icon'       => [ 'library' => 'fontawesomeBrands', 'icon' => 'fab fa-facebook-f' ],
						'background' => [ 'hex' => '#1877f2' ],
						'link'       => [ 'type' => 'external', 'url' => '#' ],
					],
					[
						'id'         => 'soc002',
						'label'      => 'Instagram',
						'icon'       => [ 'library' => 'fontawesomeBrands', 'icon' => 'fab fa-instagram' ],
						'background' => [ 'hex' => '#e4405f' ],
						'link'       => [ 'type' => 'external', 'url' => '#' ],
					],
				],
				'_padding'    => [ 'top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10 ],
				'_typography' => [ 'font-size' => '16px' ],
			],
			'team-members' => [
				'items' => [
					[ 'id' => 'tmm001', 'title' => 'John Doe', 'subtitle' => 'CEO', 'description' => 'Team member description.' ],
					[ 'id' => 'tmm002', 'title' => 'Jane Smith', 'subtitle' => 'Designer', 'description' => 'Another team member.' ],
				],
			],
			'testimonials' => [
				'items' => [
					[ 'id' => 'tes001', 'content' => 'Great service, highly recommended!', 'name' => 'Client A', 'job' => 'CEO' ],
					[ 'id' => 'tes002', 'content' => 'Excellent work and fast delivery.', 'name' => 'Client B', 'job' => 'Manager' ],
				],
			],
			'slider'     => [
				'items' => [
					[
						'id'         => 'sld001',
						'title'      => 'Slide 1',
						'content'    => 'Slide content goes here.',
						'buttonText' => 'Click Me',
						'buttonLink' => [ 'type' => 'external', 'url' => '#' ],
						'background' => [ 'color' => [ 'hex' => '#1e293b' ] ],
					],
					[
						'id'         => 'sld002',
						'title'      => 'Slide 2',
						'content'    => 'Another slide.',
						'buttonText' => 'Learn More',
						'buttonLink' => [ 'type' => 'external', 'url' => '#' ],
						'background' => [ 'color' => [ 'hex' => '#334155' ] ],
					],
				],
				'arrows' => true,
			],
			'carousel'   => [
				'fields' => [
					[
						'id'            => 'crf001',
						'dynamicData'   => '{post_title:link}',
						'tag'           => 'h3',
						'dynamicMargin' => [ 'top' => 20, 'right' => 0, 'bottom' => 20, 'left' => 0 ],
					],
					[ 'id' => 'crf002', 'dynamicData' => '{post_excerpt:20}' ],
				],
				'arrows'    => true,
				'infinite'  => true,
				'prevArrow' => [ 'library' => 'ionicons', 'icon' => 'ion-ios-arrow-back' ],
				'nextArrow' => [ 'library' => 'ionicons', 'icon' => 'ion-ios-arrow-forward' ],
			],
			'code'       => [
				'code'        => '<h1>Custom HTML/PHP</h1><?php echo date("Y"); ?>',
				'executeCode' => true,
			],
			'map'        => [
				'addresses' => [
					[ 'id' => 'map001', 'latitude' => '40.7128', 'longitude' => '-74.0060' ],
				],
				'scrollwheel'       => true,
				'draggable'         => true,
				'zoomControl'       => true,
				'streetViewControl' => true,
			],
			'map-leaflet' => [
				'layers' => [
					[ 'id' => 'mpl001', 'name' => 'OpenStreetMap', 'url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' ],
				],
				'zoomControl'        => true,
				'dragging'           => true,
				'attributionControl' => true,
			],
			'map-connector' => [],
			'facebook-page' => [
				'href' => 'https://facebook.com/facebook',
			],
			'logo'        => [
				'logoText' => 'Site Name',
			],
			'form'       => [
				'fields'           => [
					[
						'id'          => 'abc123',
						'type'        => 'text',
						'label'       => 'Name',
						'placeholder' => 'Your Name',
						'required'    => false,
						'width'       => 100,
					],
					[
						'id'          => 'def456',
						'type'        => 'email',
						'label'       => 'Email',
						'placeholder' => 'Your Email',
						'required'    => true,
						'width'       => 100,
					],
					[
						'id'          => 'ghi789',
						'type'        => 'textarea',
						'label'       => 'Message',
						'placeholder' => 'Your Message',
						'required'    => true,
						'width'       => 100,
					],
				],
				'actions'           => [ 'email' ],
				'submitButtonStyle' => 'primary',
				'successMessage'    => 'Message successfully sent. We will get back to you as soon as possible.',
				'emailSubject'      => 'Contact form request',
				'emailTo'           => 'admin_email',
				'fromName'          => '{site_name}',
				'htmlEmail'         => true,
				'submitButtonText'  => 'Send',
			],
			'custom-title' => [
				'title'    => 'Custom Title',
				'subtitle' => 'Subtitle text here',
			],

			// === Media elements ===
			'audio'          => [],
			'image-gallery'  => [
				'items' => [
					'images' => [
						[ 'id' => 0, 'filename' => 'gallery-1.jpg', 'size' => 'full', 'full' => 'https://placeholder.example/800x600', 'url' => 'https://placeholder.example/800x600' ],
						[ 'id' => 0, 'filename' => 'gallery-2.jpg', 'size' => 'full', 'full' => 'https://placeholder.example/800x600', 'url' => 'https://placeholder.example/800x600' ],
					],
				],
			],
			'instagram-feed' => [
				'followText' => 'Follow us @yourhandle',
				'followIcon' => [ 'library' => 'ionicons', 'icon' => 'ion-logo-instagram' ],
			],
			'svg'            => [],

			// === Query elements ===
			'pagination'  => [],
			'query-results-summary' => [],

			// === Single post elements ===
			'post-title'   => [ 'tag' => 'h1' ],
			'post-excerpt' => [],
			'post-content' => [],
			'post-meta'    => [
				'meta' => [
					[ 'id' => 'mtd001', 'dynamicData' => '{author_name}' ],
					[ 'id' => 'mtd002', 'dynamicData' => '{post_date}' ],
					[ 'id' => 'mtd003', 'dynamicData' => '{post_comments}' ],
				],
			],
			'post-author'  => [
				'avatar'     => true,
				'name'       => true,
				'website'    => true,
				'bio'        => true,
				'postsLink'  => true,
				'postsStyle' => 'primary',
			],
			'post-taxonomy' => [
				'taxonomy' => 'category',
				'style'    => 'dark',
			],
			'post-comments' => [
				'title'             => true,
				'avatar'            => true,
				'formTitle'         => true,
				'label'             => true,
				'submitButtonStyle' => 'primary',
			],
			'post-navigation' => [
				'label'     => true,
				'title'     => true,
				'image'     => true,
				'prevArrow' => [ 'library' => 'ionicons', 'icon' => 'ion-ios-arrow-back' ],
				'nextArrow' => [ 'library' => 'ionicons', 'icon' => 'ion-ios-arrow-forward' ],
			],
			'post-reading-time' => [
				'prefix' => 'Reading time: ',
				'suffix' => ' minutes',
			],
			'post-reading-progress-bar' => [],
			'post-sharing' => [
				'items' => [
					[ 'id' => 'psh001', 'service' => 'facebook' ],
					[ 'id' => 'psh002', 'service' => 'twitter' ],
					[ 'id' => 'psh003', 'service' => 'linkedin' ],
					[ 'id' => 'psh004', 'service' => 'whatsapp' ],
					[ 'id' => 'psh005', 'service' => 'pinterest' ],
					[ 'id' => 'psh006', 'service' => 'telegram' ],
					[ 'id' => 'psh007', 'service' => 'email' ],
				],
				'brandColors' => true,
			],
			'post-toc'     => [],
			'related-posts' => [
				'taxonomies' => [ 'category', 'post_tag' ],
				'content'    => [
					[ 'id' => 'rpc001', 'dynamicData' => '{post_title:link}', 'tag' => 'h3', 'dynamicMargin' => [ 'top' => 10 ] ],
					[ 'id' => 'rpc002', 'dynamicData' => '{post_excerpt:20}' ],
				],
			],

			// === WordPress elements ===
			'nav-menu'    => [],
			'posts'       => [
				'gutter' => '30px',
				'fields' => [
					[
						'id'            => 'psf001',
						'dynamicData'   => '{post_title:link}',
						'tag'           => 'h3',
						'dynamicMargin' => [ 'top' => 20, 'right' => 0, 'bottom' => 20, 'left' => 0 ],
					],
					[ 'id' => 'psf002', 'dynamicData' => '{post_excerpt:20}' ],
				],
			],
			'search'      => [
				'searchOverlayTitle' => 'Search',
			],
			'shortcode'   => [],
			'sidebar'     => [],
			'wordpress'   => [
				'type' => 'posts',
			],

			// === Misc elements ===
			'breadcrumbs'  => [],
			'back-to-top'  => [
				'_nestable_children' => [
					[
						'name'     => 'icon',
						'settings' => [
							'icon' => [ 'library' => 'ionicons', 'icon' => 'ion-ios-arrow-up' ],
						],
					],
					[
						'name'     => 'text-basic',
						'settings' => [ 'text' => 'Back to top' ],
					],
				],
			],
			'toggle-mode'  => [
				'ariaLabel' => 'Toggle mode',
			],
			'offcanvas'    => [],
			'toggle'       => [],
			'template'     => [],
			'slot'         => [],
			'dropdown'     => [
				'text' => 'Dropdown',
			],
			'accordion-nested' => [
				'_nestable_children' => [
					[
						'name'     => 'block',
						'label'    => 'Accordion Item',
						'children' => [
							[
								'name'     => 'block',
								'label'    => 'Title',
								'settings' => [
									'_direction'      => 'row',
									'_justifyContent' => 'space-between',
									'_alignItems'     => 'center',
									'_hidden'         => [ '_cssClasses' => 'accordion-title-wrapper' ],
								],
								'children' => [
									[
										'name'     => 'heading',
										'settings' => [ 'text' => 'Accordion Title', 'tag' => 'h3' ],
									],
									[
										'name'     => 'icon',
										'settings' => [
											'icon'            => [ 'icon' => 'ion-ios-arrow-forward', 'library' => 'ionicons' ],
											'iconSize'        => '1em',
											'isAccordionIcon' => true,
										],
									],
								],
							],
							[
								'name'     => 'block',
								'label'    => 'Content',
								'settings' => [
									'_hidden' => [ '_cssClasses' => 'accordion-content-wrapper' ],
								],
								'children' => [
									[
										'name'     => 'text',
										'settings' => [ 'text' => 'Accordion content goes here.' ],
									],
								],
							],
						],
					],
				],
			],
			'tabs-nested' => [
				'_nestable_children' => [
					[
						'name'     => 'block',
						'label'    => 'Tab Menu',
						'settings' => [
							'_direction' => 'row',
							'_hidden'    => [ '_cssClasses' => 'tab-menu' ],
						],
						'children' => [
							[
								'name'     => 'div',
								'settings' => [ '_hidden' => [ '_cssClasses' => 'tab-title' ] ],
								'children' => [
									[
										'name'     => 'text-basic',
										'settings' => [ 'text' => 'Tab 1' ],
									],
								],
							],
							[
								'name'     => 'div',
								'settings' => [ '_hidden' => [ '_cssClasses' => 'tab-title' ] ],
								'children' => [
									[
										'name'     => 'text-basic',
										'settings' => [ 'text' => 'Tab 2' ],
									],
								],
							],
						],
					],
					[
						'name'     => 'block',
						'label'    => 'Tab Content',
						'settings' => [
							'_hidden' => [ '_cssClasses' => 'tab-content' ],
						],
						'children' => [
							[
								'name'     => 'block',
								'settings' => [ '_hidden' => [ '_cssClasses' => 'tab-pane' ] ],
								'children' => [
									[
										'name'     => 'text',
										'settings' => [ 'text' => 'Tab pane 1 content goes here.' ],
									],
								],
							],
							[
								'name'     => 'block',
								'settings' => [ '_hidden' => [ '_cssClasses' => 'tab-pane' ] ],
								'children' => [
									[
										'name'     => 'text',
										'settings' => [ 'text' => 'Tab pane 2 content goes here.' ],
									],
								],
							],
						],
					],
				],
			],
			'slider-nested' => [
				'_nestable_children' => [
					[
						'name'     => 'block',
						'label'    => 'Slide',
						'children' => [
							[
								'name'     => 'heading',
								'settings' => [ 'text' => 'Slide Title', 'tag' => 'h2' ],
							],
							[
								'name'     => 'button',
								'settings' => [ 'text' => 'Button', 'style' => 'primary' ],
							],
						],
					],
				],
			],
			'nav-nested' => [
				'_nestable_children' => [
					[
						'name'     => 'text-link',
						'settings' => [ 'text' => 'Home', 'link' => [ 'url' => '/', 'type' => 'external' ] ],
					],
					[
						'name'     => 'text-link',
						'settings' => [ 'text' => 'About', 'link' => [ 'url' => '/about/', 'type' => 'external' ] ],
					],
					[
						'name'     => 'dropdown',
						'settings' => [ 'text' => 'Services', 'trigger' => 'hover' ],
						'children' => [
							[
								'name'     => 'text-link',
								'settings' => [ 'text' => 'Service 1', 'link' => [ 'url' => '/service-1/', 'type' => 'external' ] ],
							],
						],
					],
				],
			],
		];

		if ( isset( $known_examples[ $element_name ] ) ) {
			return $known_examples[ $element_name ];
		}

		// For unknown elements: use defaults from controls where available.
		$settings = [];

		$controls = [];
		if ( method_exists( $element, 'get_controls' ) ) {
			$controls = $element->get_controls();
		} elseif ( isset( $element->controls ) && is_array( $element->controls ) ) {
			$controls = $element->controls;
		}

		if ( ! is_array( $controls ) ) {
			return $settings;
		}

		$added = 0;
		foreach ( $controls as $control_key => $control ) {
			if ( ! is_array( $control ) || ! isset( $control['type'] ) ) {
				continue;
			}

			// Skip non-setting controls.
			if ( in_array( $control['type'], [ 'group', 'section', 'tab', 'separator', 'data' ], true ) ) {
				continue;
			}

			// Only include controls with explicit defaults or required fields.
			if ( isset( $control['default'] ) ) {
				$settings[ $control_key ] = $control['default'];
				++$added;
			} elseif ( ! empty( $control['required'] ) ) {
				// Use type-appropriate empty value for required fields.
				$settings[ $control_key ] = $this->get_empty_value_for_type( $control['type'] );
				++$added;
			}

			if ( $added >= 5 ) {
				// Keep examples minimal.
				break;
			}
		}

		return $settings;
	}

	/**
	 * Get the Bricks version string for cache key generation.
	 *
	 * @return string Bricks version or 'unknown' if not available.
	 */
	public function get_bricks_version(): string {
		if ( defined( 'BRICKS_VERSION' ) ) {
			return (string) BRICKS_VERSION;
		}
		return 'unknown';
	}

	/**
	 * Flush all schema transient caches.
	 *
	 * Called when Bricks version changes or plugins are updated.
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		global $wpdb;

		// Delete all transients matching our prefix pattern.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::CACHE_OPTION_PREFIX ) . '%'
			)
		);
		delete_option( self::CACHE_EXPIRY_OPTION );
	}

	/**
	 * Read cached schema data from wp_options.
	 *
	 * Returns null if the cache is expired or missing.
	 *
	 * @param string $key Option name for the cached data.
	 * @return array<string, mixed>|null Cached schemas or null if expired/missing.
	 */
	private function read_cache( string $key ): ?array {
		$expires = get_option( self::CACHE_EXPIRY_OPTION, 0 );
		if ( time() > (int) $expires ) {
			return null;
		}
		$data = get_option( $key, null );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Write schema data to wp_options cache (non-autoloaded).
	 *
	 * @param string              $key  Option name for the cached data.
	 * @param array<string, mixed> $data Schema data to cache.
	 * @return void
	 */
	private function write_cache( string $key, array $data ): void {
		update_option( $key, $data, false );
		update_option( self::CACHE_EXPIRY_OPTION, time() + self::CACHE_DURATION, false );
	}

	/**
	 * Map a Bricks control definition to a JSON Schema type.
	 *
	 * @param array<string, mixed> $control Bricks control definition.
	 * @return array<string, mixed> JSON Schema type definition.
	 */
	private function map_control_type_to_schema( array $control ): array {
		$type = $control['type'] ?? 'text';

		switch ( $type ) {
			case 'text':
			case 'textarea':
			case 'code':
			case 'editor':
			case 'richtextEditor':
				return [ 'type' => 'string' ];

			case 'number':
				$schema = [ 'type' => 'number' ];
				if ( isset( $control['min'] ) ) {
					$schema['minimum'] = $control['min'];
				}
				if ( isset( $control['max'] ) ) {
					$schema['maximum'] = $control['max'];
				}
				return $schema;

			case 'checkbox':
			case 'toggle':
				return [ 'type' => 'boolean' ];

			case 'select':
				$schema = [ 'type' => 'string' ];
				if ( ! empty( $control['options'] ) && is_array( $control['options'] ) ) {
					$schema['enum'] = array_keys( $control['options'] );
				}
				return $schema;

			case 'color':
				// Bricks color values are ALWAYS objects, never plain strings.
				// Valid formats: {"hex":"#1E293B"}, {"raw":"var(--primary)"}, {"rgb":"rgba(30,41,59,0.8)"}
				return [
					'type'        => 'object',
					'description' => 'Bricks color object. Use ONE key: {"hex":"#value"} for hex colors, {"raw":"var(--var)"} for CSS vars/keywords, or {"rgb":"rgba(...)"} for rgba.',
					'properties'  => [
						'hex' => [ 'type' => 'string', 'pattern' => '^#[0-9a-fA-F]{3,8}$' ],
						'raw' => [ 'type' => 'string', 'description' => 'CSS variable or raw CSS color (e.g. "var(--primary)", "transparent")' ],
						'rgb' => [ 'type' => 'string', 'description' => 'RGBA string (e.g. "rgba(30, 41, 59, 0.8)")' ],
					],
				];

			case 'dimensions':
				// All directional values are CSS unit strings (e.g. "20px", "var(--spacing)", "50%").
				// NOT integers. Bricks does NOT auto-append "px" when values are set programmatically.
				return [
					'type'        => 'object',
					'description' => 'All values are CSS unit strings (e.g. "20px", "var(--s)", "50%"). NOT integers.',
					'properties' => [
						'top'    => [ 'type' => 'string' ],
						'right'  => [ 'type' => 'string' ],
						'bottom' => [ 'type' => 'string' ],
						'left'   => [ 'type' => 'string' ],
					],
				];

			case 'typography':
				return [
					'type'        => 'object',
					'properties'  => [
						'font-family'     => [ 'type' => 'string' ],
						'font-size'       => [ 'type' => 'string', 'description' => 'CSS unit (e.g. 16px, 1.5rem)' ],
						'font-weight'     => [ 'type' => [ 'string', 'number' ], 'description' => '700 or bold' ],
						'line-height'     => [ 'type' => 'string' ],
						'letter-spacing'  => [ 'type' => 'string' ],
						'text-transform'  => [ 'type' => 'string', 'enum' => [ 'none', 'uppercase', 'lowercase', 'capitalize' ] ],
						'text-decoration' => [ 'type' => 'string' ],
						'text-align'      => [ 'type' => 'string', 'enum' => [ 'left', 'center', 'right', 'justify' ] ],
						'font-style'      => [ 'type' => 'string', 'enum' => [ 'normal', 'italic', 'oblique' ] ],
						'color'           => [
							'type'        => 'object',
							'properties'  => [ 'hex' => ['type'=>'string'], 'raw' => ['type'=>'string'], 'rgb' => ['type'=>'string'] ],
							'description' => 'Text color object. Use {"hex":"#value"} or {"raw":"var(--color)"}',
						],
					],
					'description' => 'Typography. color must be color object, not plain string.',
				];

			case 'image':
				return [
					'type'       => 'object',
					'properties' => [
						'id'  => [ 'type' => 'integer' ],
						'url' => [ 'type' => 'string' ],
					],
				];

			case 'gallery':
				return [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'  => [ 'type' => 'integer' ],
							'url' => [ 'type' => 'string' ],
						],
					],
				];

			case 'link':
				return [
					'type'       => 'object',
					'properties' => [
						'url'      => [ 'type' => 'string' ],
						'type'     => [ 'type' => 'string' ],
						'newTab'   => [ 'type' => 'boolean' ],
						'nofollow' => [ 'type' => 'boolean' ],
					],
				];

			case 'icon':
				return [
					'type'       => 'object',
					'properties' => [
						'library' => [ 'type' => 'string' ],
						'icon'    => [ 'type' => 'string' ],
						'svg'     => [
							'type'       => 'object',
							'properties' => [
								'id'  => [ 'type' => 'integer' ],
								'url' => [ 'type' => 'string' ],
							],
						],
					],
				];

			case 'repeater':
				// Recurse into repeater fields.
				$items_props = [];
				if ( ! empty( $control['fields'] ) && is_array( $control['fields'] ) ) {
					foreach ( $control['fields'] as $field_key => $field ) {
						if ( is_array( $field ) ) {
							$items_props[ $field_key ] = $this->map_control_type_to_schema( $field );
						}
					}
				}

				return [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'properties'           => empty( $items_props ) ? new \stdClass() : $items_props,
						'additionalProperties' => true,
					],
				];

			case 'background':
				// color MUST be a color object: {"hex":"#value"} or {"raw":"var(--var)"} — never a plain string.
				return [
					'type'        => 'object',
					'description' => 'Background settings. color must be color object {hex/raw/rgb}, not a plain string.',
					'properties' => [
						'color'      => [
							'type'        => 'object',
							'description' => 'Color object. Use {"hex":"#value"} or {"raw":"var(--var)"}. Never a plain string.',
							'properties'  => [
								'hex' => [ 'type' => 'string' ],
								'raw' => [ 'type' => 'string' ],
								'rgb' => [ 'type' => 'string' ],
							],
						],
						'image'      => [
							'type'       => 'object',
							'properties' => [
								'id'   => [ 'type' => 'integer' ],
								'url'  => [ 'type' => 'string' ],
								'size' => [ 'type' => 'string', 'description' => 'Image size slug (e.g. "full", "large")' ],
							],
						],
						'size'       => [ 'type' => 'string', 'description' => 'CSS background-size (e.g. "cover", "contain")' ],
						'position'   => [ 'type' => 'string', 'description' => 'CSS background-position (e.g. "center center")' ],
						'repeat'     => [ 'type' => 'string', 'description' => 'CSS background-repeat (e.g. "no-repeat")' ],
						'attachment' => [ 'type' => 'string', 'description' => '"scroll" or "fixed"' ],
					],
				];

			case 'border':
				// width/radius: CSS unit STRINGS ("4px", "50%", "var(--r)") or per-side objects.
				// color: color OBJECT {hex/raw/rgb}, never a plain string.
				return [
					'type'        => 'object',
					'description' => 'Border settings. width/radius are CSS strings or per-side objects. color is a color object.',
					'properties' => [
						'width'  => [
							'description' => 'CSS string ("1px", "var(--border)") or per-side {top,right,bottom,left} object. NOT an integer.',
							'oneOf' => [
								[ 'type' => 'string' ],
								[ 'type' => 'object', 'properties' => [ 'top' => ['type'=>'string'], 'right' => ['type'=>'string'], 'bottom' => ['type'=>'string'], 'left' => ['type'=>'string'] ] ],
							],
						],
						'style'  => [ 'type' => 'string', 'enum' => ['none','solid','dashed','dotted','double','groove','ridge','inset','outset'] ],
						'color'  => [
							'type'        => 'object',
							'description' => 'Color object. {"hex":"#value"} or {"raw":"var(--var)"}. Never a plain string.',
							'properties'  => [ 'hex' => ['type'=>'string'], 'raw' => ['type'=>'string'], 'rgb' => ['type'=>'string'] ],
						],
						'radius' => [
							'description' => 'CSS string ("12px", "50%", "var(--r)") or per-corner {top,right,bottom,left} object. NOT an integer.',
							'oneOf' => [
								[ 'type' => 'string' ],
								[ 'type' => 'object', 'properties' => [ 'top' => ['type'=>'string'], 'right' => ['type'=>'string'], 'bottom' => ['type'=>'string'], 'left' => ['type'=>'string'] ] ],
							],
						],
					],
				];

			case 'box-shadow':
				return [
					'type'       => 'object',
					'properties' => [
						'offsetX' => [ 'type' => 'string' ],
						'offsetY' => [ 'type' => 'string' ],
						'blur'    => [ 'type' => 'string' ],
						'spread'  => [ 'type' => 'string' ],
						'color'   => [
							'type'        => 'object',
							'description' => 'Color object {hex/raw/rgb}. Never a plain string.',
							'properties'  => [ 'hex' => ['type'=>'string'], 'raw' => ['type'=>'string'], 'rgb' => ['type'=>'string'] ],
						],
					],
				];

			default:
				// Fallback: accept any string or object.
				return [
					'oneOf' => [
						[ 'type' => 'string' ],
						[ 'type' => 'number' ],
						[ 'type' => 'boolean' ],
						[ 'type' => 'object' ],
						[ 'type' => 'array' ],
					],
				];
		}
	}

	/**
	 * Get an element label from the element object.
	 *
	 * @param object $element The Bricks element object.
	 * @return string Element label.
	 */
	private function get_element_label( object $element ): string {
		if ( isset( $element->label ) && is_string( $element->label ) ) {
			return $element->label;
		}
		if ( method_exists( $element, 'get_label' ) ) {
			return (string) $element->get_label();
		}
		return '';
	}

	/**
	 * Get an element category from the element object.
	 *
	 * @param object $element The Bricks element object.
	 * @return string Element category slug.
	 */
	private function get_element_category( object $element ): string {
		if ( isset( $element->category ) && is_string( $element->category ) ) {
			return $element->category;
		}
		return 'general';
	}

	/**
	 * Instantiate a Bricks element object from a class name or object.
	 *
	 * @param string|object $element_class Class name or existing object.
	 * @return object|null Instantiated element or null on failure.
	 */
	private function get_element_object( string|object $element_class ): ?object {
		if ( is_object( $element_class ) ) {
			return $element_class;
		}

		if ( ! is_string( $element_class ) || ! class_exists( $element_class ) ) {
			return null;
		}

		try {
			return new $element_class( [] );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Find element names similar to the requested name.
	 *
	 * Uses similar_text() to find closest matches.
	 *
	 * @param string        $requested     The requested element name.
	 * @param array<string> $element_names All available element names.
	 * @return array<string> List of similar element names (max 5).
	 */
	private function find_similar_element_names( string $requested, array $element_names ): array {
		$similar = [];

		foreach ( $element_names as $name ) {
			similar_text( $requested, $name, $percent );
			if ( $percent > 40 ) {
				$similar[ $name ] = $percent;
			}
		}

		// Sort by similarity descending.
		arsort( $similar );

		return array_slice( array_keys( $similar ), 0, 5 );
	}

	/**
	 * Get a type-appropriate empty value for a control type.
	 *
	 * Used when generating minimal examples for required fields.
	 *
	 * @param string $type The control type.
	 * @return mixed Type-appropriate empty value.
	 */
	private function get_empty_value_for_type( string $type ): mixed {
		return match ( $type ) {
			'number'                     => 0,
			'checkbox', 'toggle'         => false,
			'color'                      => [ 'hex' => '#000000' ],
			'repeater', 'gallery'        => [],
			'image', 'link', 'icon',
			'dimensions', 'typography',
			'background', 'border',
			'box-shadow'                 => [],
			default                      => '',
		};
	}
}
