<?php
/**
 * InteractionSchemaCatalog reference data.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Reference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * InteractionSchemaCatalog class.
 *
 * Provides the static reference array for interaction schema.
 */
final class InteractionSchemaCatalog {

	/**
	 * Return the reference data array.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$data = array(
			'description'       => 'Bricks element interaction/animation settings reference. Interactions are stored in settings._interactions as a repeater array on any element. Use element:update or page:update_content to add interactions.',
			'important'         => 'NEVER use deprecated _animation/_animationDuration/_animationDelay keys. Always use _interactions array. Each interaction needs a unique 6-char lowercase alphanumeric id field.',
			'triggers'          => array(
				'click'            => 'Element clicked',
				'mouseover'        => 'Mouse over element',
				'mouseenter'       => 'Mouse enters element',
				'mouseleave'       => 'Mouse leaves element',
				'focus'            => 'Element receives focus',
				'blur'             => 'Element loses focus',
				'enterView'        => 'Element enters viewport (IntersectionObserver)',
				'leaveView'        => 'Element leaves viewport',
				'animationEnd'     => 'Another interaction\'s animation ends (chain via animationId)',
				'contentLoaded'    => 'DOM content loaded (optional delay field)',
				'scroll'           => 'Window scroll reaches scrollOffset value',
				'mouseleaveWindow' => 'Mouse leaves browser window',
				'ajaxStart'        => 'Query loop AJAX starts (requires ajaxQueryId)',
				'ajaxEnd'          => 'Query loop AJAX ends (requires ajaxQueryId)',
				'formSubmit'       => 'Form submitted (requires formId)',
				'formSuccess'      => 'Form submission succeeded (requires formId)',
				'formError'        => 'Form submission failed (requires formId)',
			),
			'actions'           => array(
				'startAnimation'   => 'Run Animate.css animation (requires animationType)',
				'show'             => 'Show target element (remove display:none)',
				'hide'             => 'Hide target element (set display:none)',
				'click'            => 'Programmatically click target element',
				'setAttribute'     => 'Set HTML attribute on target',
				'removeAttribute'  => 'Remove HTML attribute from target',
				'toggleAttribute'  => 'Toggle HTML attribute on target',
				'toggleOffCanvas'  => 'Toggle Bricks off-canvas element',
				'loadMore'         => 'Load more results in query loop (requires loadMoreQuery)',
				'loadMoreGallery'  => 'Load more images in Image Gallery element (Bricks 2.3+). Configure loadMoreInitial, loadMoreStep, loadMoreInfiniteScroll, loadMoreInfiniteScrollDelay, loadMoreInfiniteScrollOffset on the Image Gallery element settings, then add this interaction action on the trigger element (e.g. a button)',
				'scrollTo'         => 'Smooth scroll to target element',
				'javascript'       => 'Call a global JS function (GSAP bridge, requires jsFunction)',
				'openAddress'      => 'Open map info box',
				'closeAddress'     => 'Close map info box',
				'clearForm'        => 'Clear form fields',
				'storageAdd'       => 'Add to browser storage',
				'storageRemove'    => 'Remove from browser storage',
				'storageCount'     => 'Count browser storage items',
			),
			'target_options'    => array(
				'self'   => 'The element the interaction is on (default)',
				'custom' => 'CSS selector in targetSelector field (e.g. "#brxe-abc123", ".my-class")',
				'popup'  => 'Popup template by templateId',
			),
			'interaction_fields' => array(
				'id'                    => 'Required. 6-char lowercase alphanumeric, unique per interaction',
				'trigger'               => 'Required. See triggers list',
				'action'                => 'Required. See actions list',
				'target'                => 'Optional. "self" (default), "custom", or "popup"',
				'targetSelector'        => 'Required when target="custom". Full CSS selector',
				'animationType'         => 'Required when action="startAnimation". See animation_types',
				'animationDuration'     => 'Optional. CSS time value, e.g. "0.8s" or "800ms" (default "1s")',
				'animationDelay'        => 'Optional. CSS time value, e.g. "0.3s" (default "0s")',
				'rootMargin'            => 'Optional for enterView. IntersectionObserver rootMargin, e.g. "0px 0px -80px 0px"',
				'runOnce'               => 'Optional boolean. Animate only on first trigger occurrence',
				'delay'                 => 'Optional for contentLoaded. Delay before execution, e.g. "0.5s"',
				'scrollOffset'          => 'Optional for scroll trigger. Offset value in px/vh/%',
				'animationId'           => 'Required for animationEnd trigger. ID of the interaction to wait for',
				'jsFunction'            => 'Required for javascript action. Global function name, e.g. "myAnimations.parallax"',
				'jsFunctionArgs'        => 'Optional for javascript action. Array of {id, jsFunctionArg} objects. Use "%brx%" for Bricks params object',
				'disablePreventDefault' => 'Optional boolean for click trigger. Allow link default behavior',
				'ajaxQueryId'           => 'Required for ajaxStart/ajaxEnd triggers',
				'formId'                => 'Required for formSubmit/formSuccess/formError triggers',
				'templateId'            => 'Required for target="popup"',
				'loadMoreQuery'         => 'Required for loadMore action. Typically "main"',
				'loadMoreTargetSelector' => 'Required for loadMoreGallery action. CSS selector of the Image Gallery element, e.g. "#brxe-abc123"',
				'interactionConditions' => 'Optional. Array of condition objects for conditional execution',
			),
			'animation_types'   => array(
				'attention'  => array( 'bounce', 'flash', 'pulse', 'rubberBand', 'shakeX', 'shakeY', 'headShake', 'swing', 'tada', 'wobble', 'jello', 'heartBeat' ),
				'back'       => array( 'backInDown', 'backInLeft', 'backInRight', 'backInUp', 'backOutDown', 'backOutLeft', 'backOutRight', 'backOutUp' ),
				'bounce'     => array( 'bounceIn', 'bounceInDown', 'bounceInLeft', 'bounceInRight', 'bounceInUp', 'bounceOut', 'bounceOutDown', 'bounceOutLeft', 'bounceOutRight', 'bounceOutUp' ),
				'fade'       => array( 'fadeIn', 'fadeInDown', 'fadeInDownBig', 'fadeInLeft', 'fadeInLeftBig', 'fadeInRight', 'fadeInRightBig', 'fadeInUp', 'fadeInUpBig', 'fadeInTopLeft', 'fadeInTopRight', 'fadeInBottomLeft', 'fadeInBottomRight', 'fadeOut', 'fadeOutDown', 'fadeOutDownBig', 'fadeOutLeft', 'fadeOutLeftBig', 'fadeOutRight', 'fadeOutRightBig', 'fadeOutUp', 'fadeOutUpBig', 'fadeOutTopLeft', 'fadeOutTopRight', 'fadeOutBottomRight', 'fadeOutBottomLeft' ),
				'flip'       => array( 'flip', 'flipInX', 'flipInY', 'flipOutX', 'flipOutY' ),
				'lightspeed' => array( 'lightSpeedInRight', 'lightSpeedInLeft', 'lightSpeedOutRight', 'lightSpeedOutLeft' ),
				'rotate'     => array( 'rotateIn', 'rotateInDownLeft', 'rotateInDownRight', 'rotateInUpLeft', 'rotateInUpRight', 'rotateOut', 'rotateOutDownLeft', 'rotateOutDownRight', 'rotateOutUpLeft', 'rotateOutUpRight' ),
				'special'    => array( 'hinge', 'jackInTheBox', 'rollIn', 'rollOut' ),
				'zoom'       => array( 'zoomIn', 'zoomInDown', 'zoomInLeft', 'zoomInRight', 'zoomInUp', 'zoomOut', 'zoomOutDown', 'zoomOutLeft', 'zoomOutRight', 'zoomOutUp' ),
				'slide'      => array( 'slideInUp', 'slideInDown', 'slideInLeft', 'slideInRight', 'slideOutUp', 'slideOutDown', 'slideOutLeft', 'slideOutRight' ),
			),
			'examples'          => array(
				'scroll_reveal'  => array(
					'description'   => 'Fade in element when scrolled into view',
					'_interactions' => array(
						array(
							'id'                => 'aa1bb2',
							'trigger'           => 'enterView',
							'rootMargin'        => '0px 0px -80px 0px',
							'action'            => 'startAnimation',
							'animationType'     => 'fadeInUp',
							'animationDuration' => '0.8s',
							'animationDelay'    => '0s',
							'target'            => 'self',
							'runOnce'           => true,
						),
					),
				),
				'stagger_cards'  => array(
					'description'          => 'Three cards fade in with incremental delays (apply to each card element)',
					'card_1_interactions'  => array(
						array( 'id' => 'cc3dd4', 'trigger' => 'enterView', 'action' => 'startAnimation', 'animationType' => 'fadeInUp', 'animationDuration' => '0.8s', 'animationDelay' => '0s', 'target' => 'self', 'runOnce' => true ),
					),
					'card_2_interactions'  => array(
						array( 'id' => 'ee5ff6', 'trigger' => 'enterView', 'action' => 'startAnimation', 'animationType' => 'fadeInUp', 'animationDuration' => '0.8s', 'animationDelay' => '0.15s', 'target' => 'self', 'runOnce' => true ),
					),
					'card_3_interactions'  => array(
						array( 'id' => 'gg7hh8', 'trigger' => 'enterView', 'action' => 'startAnimation', 'animationType' => 'fadeInUp', 'animationDuration' => '0.8s', 'animationDelay' => '0.3s', 'target' => 'self', 'runOnce' => true ),
					),
				),
				'chained_hero'   => array(
					'description'            => 'Hero title animates on load, then subtitle fades in after title finishes',
					'title_interactions'     => array(
						array( 'id' => 'ii9jj0', 'trigger' => 'contentLoaded', 'action' => 'startAnimation', 'animationType' => 'fadeInDown', 'animationDuration' => '0.8s', 'animationDelay' => '0s', 'target' => 'self' ),
					),
					'subtitle_interactions'  => array(
						array( 'id' => 'kk1ll2', 'trigger' => 'animationEnd', 'animationId' => 'ii9jj0', 'action' => 'startAnimation', 'animationType' => 'fadeIn', 'animationDuration' => '0.6s', 'animationDelay' => '0s', 'target' => 'self' ),
					),
				),
				'native_parallax' => array(
					'description'          => 'Native parallax (Bricks 2.3+). Style properties under Transform group — no GSAP or interactions needed. Prefer this over GSAP for simple parallax.',
					'element_parallax'     => array(
						'_motionElementParallax'       => true,
						'_motionElementParallaxSpeedX'  => 0,
						'_motionElementParallaxSpeedY'  => -20,
						'_motionStartVisiblePercent'    => 0,
					),
					'background_parallax'  => array(
						'_motionBackgroundParallax'      => true,
						'_motionBackgroundParallaxSpeed' => -15,
						'_motionStartVisiblePercent'     => 0,
					),
					'notes'                => array(
						'Speed values are percentages. Negative = opposite scroll direction.',
						'_motionStartVisiblePercent: 0 = element entering viewport, 50 = near center.',
						'Not visible in builder preview — only on live frontend.',
						'These are style properties, NOT interactions. Set directly on element settings.',
					),
				),
				'gsap_parallax'  => array(
					'description'                => 'GSAP ScrollTrigger parallax via javascript action. Requires GSAP loaded on page. For simple parallax, prefer native_parallax example above. Use GSAP only for advanced control (custom easing, scrub values, timeline sequencing).',
					'step_1_page_script'         => 'Use page:update_settings with customScriptsBodyFooter to add: <script>document.addEventListener("DOMContentLoaded",function(){if(typeof gsap==="undefined")return;gsap.registerPlugin(ScrollTrigger);window.brxGsap={parallax:function(b){gsap.to(b.source,{yPercent:-20,ease:"none",scrollTrigger:{trigger:b.source,scrub:1}})}}});</script>',
					'step_2_element_interaction'  => array(
						array( 'id' => 'mm3nn4', 'trigger' => 'contentLoaded', 'action' => 'javascript', 'jsFunction' => 'brxGsap.parallax', 'jsFunctionArgs' => array( array( 'id' => 'oo5pp6', 'jsFunctionArg' => '%brx%' ) ), 'target' => 'self' ),
					),
				),
				// image_gallery_load_more was previously misplaced OUTSIDE the 'examples'
				// array key, making it a sibling of 'notes' at the top level of $data.
				// Moved inside 'examples' where it belongs alongside the other examples.
				'image_gallery_load_more' => array(
					'description'               => 'Image Gallery with load more + infinite scroll (Bricks 2.3+). Step 1: Set load more settings on the Image Gallery element. Step 2: Add a button with loadMoreGallery interaction targeting the gallery.',
					'step_1_gallery_settings'   => array(
						'note'                          => 'Set these on the Image Gallery element settings (not in _interactions)',
						'loadMoreInitial'               => 6,
						'loadMoreStep'                  => 3,
						'loadMoreInfiniteScroll'        => true,
						'loadMoreInfiniteScrollDelay'   => '600ms',
						'loadMoreInfiniteScrollOffset'  => '200px',
					),
					'step_2_button_interactions' => array(
						'note'           => 'Add this interaction on the button or trigger element. loadMoreGallery does not use target/targetSelector — it uses loadMoreTargetSelector instead.',
						'_interactions'  => array(
							array(
								'id'                     => 'pp7qq8',
								'trigger'                => 'click',
								'action'                 => 'loadMoreGallery',
								'loadMoreTargetSelector' => '#brxe-{galleryElementId}',
							),
						),
					),
				),
			),
			'notes'             => array(
				'Each interaction id must be unique — 6-char lowercase alphanumeric (same format as element IDs).',
				'Animation types containing "In" (case-sensitive) automatically hide the element on page load and reveal on animation.',
				'Use "In" types for enterView/contentLoaded triggers. "Out" types are for exit animations or click-triggered hiding.',
				'Bricks auto-enqueues Animate.css when startAnimation action is detected — no manual enqueue needed.',
				'For GSAP: the plugin does NOT enqueue GSAP. The site owner must load it (CDN or local). AI should inject via page:update_settings customScriptsBodyFooter.',
				'The deprecated _animation, _animationDuration, _animationDelay keys still work but show converter warnings. Never generate them.',
			),
		);

		/**
		 * Filter: bricks_mcp_interaction_schema
		 * Validate filtered result — third-party filters may return non-array.
		 */
		$filtered = apply_filters( 'bricks_mcp_interaction_schema', $data );
		return is_array( $filtered ) ? $filtered : $data;
	}
}
