<?php
/**
 * Smoke runner for HtmlToElements.
 *
 * Standalone — no PHPUnit, no WordPress bootstrap. Stubs the handful of
 * WP functions the converter touches so you can run it from the CLI:
 *
 *     php tests/smoke/html-to-elements.php
 *
 * Exits with status 0 when all assertions pass, 1 on any failure.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// ─── WordPress stubs ───────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string { return $text; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return preg_replace( '/[\r\n\t\0\x0B]+/', ' ', trim( strip_tags( $str ) ) ) ?? '';
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: $url;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public mixed $data;
		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_message(): string { return $this->message; }
		public function get_error_code(): string { return $this->code; }
	}
}

// ─── Load the converter ────────────────────────────────────────────
require __DIR__ . '/../../includes/MCP/Services/HtmlToElements.php';

use BricksMCP\MCP\Services\HtmlToElements;

// ─── Mini assertion harness ────────────────────────────────────────
$failures = [];
$passes   = 0;

function assert_eq( string $name, mixed $expected, mixed $actual ): void {
	global $failures, $passes;
	if ( $expected === $actual ) {
		$passes++;
		return;
	}
	$failures[] = sprintf(
		"FAIL: %s\n  expected: %s\n  actual:   %s",
		$name,
		var_export( $expected, true ),
		var_export( $actual, true )
	);
}

function assert_true( string $name, bool $cond, string $detail = '' ): void {
	global $failures, $passes;
	if ( $cond ) {
		$passes++;
		return;
	}
	$failures[] = "FAIL: {$name}" . ( $detail ? "\n  {$detail}" : '' );
}

function assert_not_wp_error( string $name, mixed $val ): bool {
	global $failures, $passes;
	if ( $val instanceof WP_Error ) {
		$failures[] = sprintf( "FAIL: %s — got WP_Error %s: %s", $name, $val->get_error_code(), $val->get_error_message() );
		return false;
	}
	$passes++;
	return true;
}

// ─── Test 1: rejects empty input ───────────────────────────────────
$err = HtmlToElements::convert( '' );
assert_true( 'empty input → WP_Error', $err instanceof WP_Error );
assert_eq( 'empty input error code', 'html_to_elements_empty', $err instanceof WP_Error ? $err->get_error_code() : '' );

// ─── Test 2: simple heading roundtrip ──────────────────────────────
$res = HtmlToElements::convert( '<h1 class="hero__heading">Hello</h1>' );
if ( assert_not_wp_error( 'heading converts', $res ) ) {
	assert_eq( 'heading wraps in synthetic section', 1, count( $res['sections'] ) );
	$root = $res['sections'][0]['structure'];
	assert_eq( 'synthetic root is section', 'section', $root['type'] );
	$h1 = $root['children'][0]['children'][0] ?? [];
	assert_eq( 'child is heading', 'heading', $h1['type'] ?? '' );
	assert_eq( 'heading tag preserved', 'h1', $h1['tag'] ?? '' );
	assert_eq( 'heading content', 'Hello', $h1['content'] ?? '' );
	assert_eq( 'heading class_intent', 'hero__heading', $h1['class_intent'] ?? '' );
	assert_eq( 'class seen', [ 'hero__heading' ], $res['class_names_seen'] );
}

// ─── Test 3: section with nested layout, classes, gradient bg ──────
$html = <<<HTML
<section class="hero hero--dark" style="padding: 1rem 2rem; background: linear-gradient(135deg, var(--primary-ultra-dark) 0%, var(--base-ultra-dark) 100%);">
  <div class="hero__content" style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-l);">
    <div class="hero__left">
      <p class="hero__tagline" style="text-transform: uppercase; letter-spacing: 0.12em; color: var(--accent);">ASISTENȚĂ 24/7</p>
      <h1 class="hero__heading" style="color: var(--white); text-align: left;">Tractări auto rapide</h1>
      <p class="hero__description" style="color: var(--white-trans-80);">Echipa noastră ajunge rapid.</p>
      <div class="hero__cta-group" style="display: flex; gap: var(--space-s);">
        <a class="hero__cta-primary" href="tel:+40744555666" style="background: var(--primary); color: var(--white);">Sună acum</a>
        <a class="hero__cta-secondary" href="#contact" style="border-style: solid; border-width: 1px; border-color: var(--white-trans-30); border-radius: 8px;">Cere ofertă</a>
      </div>
    </div>
    <div class="hero__right">
      <img class="hero__gallery-image" src="unsplash:tow truck night" alt="Asistență rutieră" style="border-radius: 16px; aspect-ratio: 4/3; object-fit: cover; width: 100%;" />
    </div>
  </div>
</section>
HTML;

$res = HtmlToElements::convert( $html );
if ( assert_not_wp_error( 'hero converts', $res ) ) {
	assert_eq( 'one section produced', 1, count( $res['sections'] ) );
	$sec = $res['sections'][0]['structure'];
	assert_eq( 'root is section', 'section', $sec['type'] );

	// Section-level styles.
	$so = $sec['style_overrides'] ?? [];
	assert_eq( 'section padding top',    '1rem',   $so['_padding']['top']    ?? null );
	assert_eq( 'section padding right',  '2rem',   $so['_padding']['right']  ?? null );
	assert_eq( 'section padding bottom', '1rem',   $so['_padding']['bottom'] ?? null );
	assert_eq( 'section padding left',   '2rem',   $so['_padding']['left']   ?? null );
	assert_true(
		'section has gradient background',
		isset( $so['_background']['useGradient'] ) && $so['_background']['useGradient'] === true,
		'expected useGradient=true; got ' . var_export( $so['_background'] ?? null, true )
	);
	assert_true(
		'gradient value carried to _background.image.gradient',
		str_contains( $so['_background']['image']['gradient'] ?? '', 'linear-gradient' )
	);
	assert_eq( 'section first class is intent', 'hero', $sec['class_intent'] ?? null );
	assert_eq( 'section extra class in _cssClasses', 'hero--dark', $sec['element_settings']['_cssClasses'] ?? null );

	// Auto-inserted container (Bricks hierarchy: section's only valid child is container).
	$container = $sec['children'][0] ?? [];
	assert_eq( 'auto-inserted container under section', 'container', $container['type'] ?? null );

	// Grid block.
	$grid = $container['children'][0] ?? [];
	assert_eq( 'grid block class_intent',          'hero__content',   $grid['class_intent']                         ?? null );
	assert_eq( 'grid display=grid',                'grid',            $grid['style_overrides']['_display']          ?? null );
	assert_eq( 'grid template columns',            '1fr 1fr',         $grid['style_overrides']['_gridTemplateColumns'] ?? null );
	assert_eq( 'grid gap',                         'var(--space-l)',  $grid['style_overrides']['_gap']              ?? null );

	// Tagline typography.
	$tagline = $grid['children'][0]['children'][0] ?? [];
	assert_eq( 'tagline class_intent',          'hero__tagline', $tagline['class_intent']                                       ?? null );
	assert_eq( 'tagline text-transform',        'uppercase',     $tagline['style_overrides']['_typography']['text-transform']   ?? null );
	assert_eq( 'tagline letter-spacing',        '0.12em',        $tagline['style_overrides']['_typography']['letter-spacing']   ?? null );
	assert_eq( 'tagline color is wrapped raw',   'var(--accent)', $tagline['style_overrides']['_typography']['color']['raw']    ?? null );
	assert_eq( 'tagline content',               'ASISTENȚĂ 24/7', $tagline['content'] ?? null );

	// Heading.
	$heading = $grid['children'][0]['children'][1] ?? [];
	assert_eq( 'heading tag',     'h1',                       $heading['tag']                                       ?? null );
	assert_eq( 'heading content', 'Tractări auto rapide',     $heading['content']                                   ?? null );
	assert_eq( 'heading color',   'var(--white)',             $heading['style_overrides']['_typography']['color']['raw'] ?? null );
	assert_eq( 'heading align',   'left',                     $heading['style_overrides']['_typography']['text-align']    ?? null );

	// CTA primary — solid background color.
	$cta_primary = $grid['children'][0]['children'][3]['children'][0] ?? [];
	assert_eq( 'cta primary type',          'text-link',                  $cta_primary['type']                       ?? null );
	assert_eq( 'cta primary class_intent',  'hero__cta-primary',          $cta_primary['class_intent']               ?? null );
	assert_eq( 'cta primary content',       'Sună acum',                  $cta_primary['content']                    ?? null );
	assert_eq( 'cta primary link url',      'tel:+40744555666',           $cta_primary['element_settings']['link']['url']  ?? null );
	assert_eq( 'cta primary bg color',      'var(--primary)',             $cta_primary['style_overrides']['_background']['color']['raw'] ?? null );
	assert_true(
		'cta primary background is NOT gradient',
		empty( $cta_primary['style_overrides']['_background']['useGradient'] )
	);

	// CTA secondary — border setup.
	$cta_secondary = $grid['children'][0]['children'][3]['children'][1] ?? [];
	assert_eq( 'cta secondary border style',     'solid',                  $cta_secondary['style_overrides']['_border']['style']         ?? null );
	assert_eq( 'cta secondary border color',     'var(--white-trans-30)',  $cta_secondary['style_overrides']['_border']['color']['raw']  ?? null );
	assert_eq( 'cta secondary border width top', '1px',                    $cta_secondary['style_overrides']['_border']['width']['top']  ?? null );
	assert_eq( 'cta secondary radius top',       '8px',                    $cta_secondary['style_overrides']['_border']['radius']['top'] ?? null );
	assert_eq( 'cta secondary link url',         '#contact',               $cta_secondary['element_settings']['link']['url']             ?? null );

	// Image.
	$img = $grid['children'][1]['children'][0] ?? [];
	assert_eq( 'image type',        'image',                  $img['type']                                ?? null );
	assert_eq( 'image src',         'unsplash:tow truck night', $img['src']                              ?? null );
	assert_eq( 'image alt',         'Asistență rutieră',      $img['element_settings']['altText']         ?? null );
	assert_eq( 'image aspect',      '4/3',                    $img['style_overrides']['_aspectRatio']     ?? null );
	assert_eq( 'image object-fit',  'cover',                  $img['style_overrides']['_objectFit']       ?? null );
	assert_eq( 'image radius top',  '16px',                   $img['style_overrides']['_border']['radius']['top'] ?? null );
}

// ─── Test 4: data-* preserved ──────────────────────────────────────
$res = HtmlToElements::convert( '<div data-track="cta-click" data-id="42">Hi</div>' );
if ( assert_not_wp_error( 'data attrs convert', $res ) ) {
	$div   = $res['sections'][0]['structure']['children'][0]['children'][0] ?? [];
	$attrs = $div['element_settings']['_attributes'] ?? [];
	assert_eq( 'two data attrs preserved', 2, count( $attrs ) );
	assert_eq( 'data-track preserved',     'data-track', $attrs[0]['name']  ?? '' );
	assert_eq( 'data-track value',         'cta-click',  $attrs[0]['value'] ?? '' );
}

// ─── Test 5: skipped tags surface as warnings ──────────────────────
$res = HtmlToElements::convert( '<div><details><summary>x</summary></details></div>' );
if ( assert_not_wp_error( 'unknown tags convert', $res ) ) {
	$warns = $res['warnings'] ?? [];
	assert_true(
		'warning emitted for <details>',
		count( array_filter( $warns, fn( $w ) => str_contains( $w, '<details>' ) ) ) > 0
	);
}

// ─── Test 6: unknown CSS rules go to css_rules_dropped ─────────────
$res = HtmlToElements::convert( '<div style="transition: all 0.3s; will-change: transform; padding: 1rem;">Hi</div>' );
if ( assert_not_wp_error( 'css drops surface', $res ) ) {
	$drops = $res['css_rules_dropped'] ?? [];
	$properties = array_column( $drops, 'property' );
	assert_true( 'will-change dropped', in_array( 'will-change', $properties, true ) );
	// transition is a known top-level prop in our map, should NOT be dropped.
	$div = $res['sections'][0]['structure']['children'][0]['children'][0] ?? [];
	assert_eq( 'transition mapped',    'all 0.3s', $div['style_overrides']['_transition'] ?? null );
	assert_eq( 'padding still there',  '1rem',     $div['style_overrides']['_padding']['top'] ?? null );
}

// ─── Test 7 (v5.2.0): mixed text inside heading ────────────────────
$res = HtmlToElements::convert( '<h1>The Future of Manufacturing with <span style="color: var(--primary)">Latest Technology</span></h1>' );
if ( assert_not_wp_error( 'mixed-text heading converts', $res ) ) {
	$h1 = $res['sections'][0]['structure']['children'][0]['children'][0] ?? [];
	assert_eq( 'h1 type', 'heading', $h1['type'] ?? null );
	assert_eq( 'h1 content includes span text', 'The Future of Manufacturing with Latest Technology', $h1['content'] ?? null );
}

// ─── Test 8 (v5.2.0): block with text-only content gets synthetic child ──
$res = HtmlToElements::convert( '<div class="badge">99+</div>' );
if ( assert_not_wp_error( 'block text fallback', $res ) ) {
	$block = $res['sections'][0]['structure']['children'][0]['children'][0] ?? [];
	assert_eq( 'block class_intent', 'badge', $block['class_intent'] ?? null );
	$child = $block['children'][0] ?? [];
	assert_eq( 'synthetic child is text-basic', 'text-basic', $child['type'] ?? null );
	assert_eq( 'synthetic child carries the text', '99+', $child['content'] ?? null );
}

// ─── Test 9 (v5.2.0): data-bricks-element="icon" ───────────────────
$res = HtmlToElements::convert( '<i data-bricks-element="icon" data-bricks-icon-library="themify" data-bricks-icon-name="ti-truck" data-bricks-icon-size="24px" data-bricks-icon-color="var(--primary)"></i>' );
if ( assert_not_wp_error( 'icon rich element converts', $res ) ) {
	$icon = $res['sections'][0]['structure']['children'][0]['children'][0] ?? [];
	assert_eq( 'icon type', 'icon', $icon['type'] ?? null );
	assert_eq( 'icon library', 'themify', $icon['element_settings']['icon']['library'] ?? null );
	assert_eq( 'icon name', 'ti-truck', $icon['element_settings']['icon']['icon'] ?? null );
	assert_eq( 'icon size', '24px', $icon['element_settings']['iconSize'] ?? null );
	assert_eq( 'icon color', 'var(--primary)', $icon['style_overrides']['_color']['raw'] ?? null );
}

// ─── Test 10 (v5.2.0): data-bricks-element="counter" ───────────────
$res = HtmlToElements::convert( '<span data-bricks-element="counter" data-bricks-count-to="1951" data-bricks-count-suffix="+" data-bricks-count-duration="2000"></span>' );
if ( assert_not_wp_error( 'counter rich element converts', $res ) ) {
	$counter = $res['sections'][0]['structure']['children'][0]['children'][0] ?? [];
	assert_eq( 'counter type', 'counter', $counter['type'] ?? null );
	assert_eq( 'counter target', '1951', $counter['element_settings']['countTo'] ?? null );
	assert_eq( 'counter suffix', '+', $counter['element_settings']['suffix'] ?? null );
	assert_eq( 'counter duration', '2000', $counter['element_settings']['duration'] ?? null );
}

// ─── Test 11 (v5.2.0): data-bricks-element="accordion-nested" ─────
$res = HtmlToElements::convert(
	'<div data-bricks-element="accordion-nested">' .
	'  <div data-bricks-accordion-title="Q1">A1 answer text.</div>' .
	'  <div data-bricks-accordion-title="Q2">A2 answer text.</div>' .
	'</div>'
);
if ( assert_not_wp_error( 'accordion-nested converts', $res ) ) {
	$acc = $res['sections'][0]['structure']['children'][0]['children'][0] ?? [];
	assert_eq( 'accordion type', 'accordion-nested', $acc['type'] ?? null );
	assert_eq( 'two accordion items', 2, count( $acc['children'] ?? [] ) );
	$first_item = $acc['children'][0] ?? [];
	$first_title_block = $first_item['children'][0] ?? [];
	$first_title_heading = $first_title_block['children'][0] ?? [];
	assert_eq( 'first item title heading content', 'Q1', $first_title_heading['content'] ?? null );
	assert_eq( 'title block has accordion-title-wrapper', 'accordion-title-wrapper', $first_title_block['element_settings']['_hidden']['_cssClasses'] ?? null );
	$first_content_block = $first_item['children'][1] ?? [];
	$first_content_text = $first_content_block['children'][0] ?? [];
	assert_eq( 'first item answer content', 'A1 answer text.', $first_content_text['content'] ?? null );
}

// ─── Test 12 (v5.2.0): data-bricks-element="tabs-nested" ──────────
$res = HtmlToElements::convert(
	'<div data-bricks-element="tabs-nested">' .
	'  <div data-bricks-tab-label="Day 1"><p>Day 1 content here.</p></div>' .
	'  <div data-bricks-tab-label="Day 2"><p>Day 2 content here.</p></div>' .
	'</div>'
);
if ( assert_not_wp_error( 'tabs-nested converts', $res ) ) {
	$tabs = $res['sections'][0]['structure']['children'][0]['children'][0] ?? [];
	assert_eq( 'tabs type', 'tabs-nested', $tabs['type'] ?? null );
	$tab_menu = $tabs['children'][0] ?? [];
	$tab_content = $tabs['children'][1] ?? [];
	assert_eq( 'tab menu cssClass', 'tab-menu', $tab_menu['element_settings']['_hidden']['_cssClasses'] ?? null );
	assert_eq( 'tab content cssClass', 'tab-content', $tab_content['element_settings']['_hidden']['_cssClasses'] ?? null );
	assert_eq( 'two tab labels', 2, count( $tab_menu['children'] ?? [] ) );
	$first_label_div = $tab_menu['children'][0] ?? [];
	$first_label_text = $first_label_div['children'][0] ?? [];
	assert_eq( 'first tab label text', 'Day 1', $first_label_text['content'] ?? null );
}

// ─── Test 13 (v5.2.0): data-bricks-element="slider-nested" ────────
$res = HtmlToElements::convert(
	'<div data-bricks-element="slider-nested" data-bricks-slider-autoplay="true" data-bricks-slider-arrows="true">' .
	'  <div data-bricks-slide><h2>Slide 1</h2></div>' .
	'  <div data-bricks-slide><h2>Slide 2</h2></div>' .
	'</div>'
);
if ( assert_not_wp_error( 'slider-nested converts', $res ) ) {
	$slider = $res['sections'][0]['structure']['children'][0]['children'][0] ?? [];
	assert_eq( 'slider type', 'slider-nested', $slider['type'] ?? null );
	assert_true( 'slider autoplay coerced to bool', ( $slider['element_settings']['autoplay'] ?? null ) === true );
	assert_eq( 'two slides', 2, count( $slider['children'] ?? [] ) );
	$first_slide = $slider['children'][0] ?? [];
	$first_slide_h2 = $first_slide['children'][0] ?? [];
	assert_eq( 'first slide heading content', 'Slide 1', $first_slide_h2['content'] ?? null );
}

// ─── Report ────────────────────────────────────────────────────────
echo "\n";
echo str_repeat( '─', 60 ) . "\n";
if ( empty( $failures ) ) {
	echo "✓ All {$passes} assertions passed\n";
	exit( 0 );
}
echo "✗ {$passes} passed, " . count( $failures ) . " failed\n\n";
foreach ( $failures as $f ) {
	echo $f . "\n\n";
}
exit( 1 );
