<?php
/**
 * Settings management sub-service.
 *
 * Handles Bricks global settings, page settings, popup settings,
 * font configuration, and page custom code.
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
 * SettingsService class.
 */
class SettingsService {

	/**
	 * Core infrastructure.
	 *
	 * @var BricksCore
	 */
	private BricksCore $core;

	/**
	 * Constructor.
	 *
	 * @param BricksCore $core Shared infrastructure.
	 */
	public function __construct( BricksCore $core ) {
		$this->core = $core;
	}

	/**
	 * Get Bricks global settings, optionally filtered by category.
	 *
	 * @param string $category Optional category filter.
	 * @return array<string, mixed>|\WP_Error Settings data or error.
	 */
	public function get_bricks_settings( string $category = '' ): array|\WP_Error {
		$category_map         = $this->get_settings_category_map();
		$available_categories = array_keys( $category_map );

		if ( '' !== $category && ! isset( $category_map[ $category ] ) ) {
			return new \WP_Error(
				'invalid_category',
				sprintf(
					/* translators: 1: provided category, 2: valid categories */
					__( 'Invalid category "%1$s". Valid categories: %2$s', 'bricks-mcp' ),
					$category,
					implode( ', ', $available_categories )
				)
			);
		}

		$raw_settings = get_option( BricksCore::OPTION_GLOBAL_SETTINGS, [] );
		if ( ! is_array( $raw_settings ) ) {
			$raw_settings = [];
		}

		// Build allowed keys list.
		if ( '' !== $category ) {
			$allowed_keys = array_flip( $category_map[ $category ] );
		} else {
			$all_keys = [];
			foreach ( $category_map as $keys ) {
				$all_keys = array_merge( $all_keys, $keys );
			}
			$allowed_keys = array_flip( $all_keys );
		}

		// Filter to only allowed keys that exist in the option.
		$settings = array_intersect_key( $raw_settings, $allowed_keys );

		// Mask sensitive settings.
		$this->mask_sensitive_settings( $settings );

		// Build restricted flags.
		$restricted      = [];
		$restricted_keys = [ 'executeCodeEnabled', 'svgUploadEnabled' ];
		foreach ( $restricted_keys as $restricted_key ) {
			$restricted[ $restricted_key ] = [
				'restricted' => true,
				'configured' => ! empty( $raw_settings[ $restricted_key ] ),
			];
		}

		return [
			'settings'             => $settings,
			'restricted'           => $restricted,
			'category'             => '' !== $category ? $category : 'all',
			'available_categories' => $available_categories,
		];
	}

	/**
	 * Get the settings category map.
	 *
	 * Maps category names to arrays of setting keys that belong to each category.
	 * Only keys in this map are exposed via get_bricks_settings.
	 *
	 * @return array<string, array<int, string>> Category-to-keys map.
	 */
	private function get_settings_category_map(): array {
		return [
			'general'      => [
				'postTypes',
				'wp_to_bricks',
				'bricks_to_wp',
				'deleteBricksData',
				'duplicateContent',
				'searchResultsQueryBricksData',
			],
			'performance'  => [
				'disableEmojis',
				'disableEmbed',
				'disableJqueryMigrate',
				'disableLazyLoad',
				'offsetLazyLoad',
				'cssLoading',
				'webfontLoading',
				'disableGoogleFonts',
				'customFontsPreload',
				'cacheQueryLoops',
				'disableBricksCascadeLayer',
				'disableClassChaining',
				'disableSkipLinks',
				'smoothScroll',
				'elementAttsAsNeeded',
				'themeStylesLoadingMethod',
			],
			'builder'      => [
				'builderMode',
				'builderAutosaveDisabled',
				'builderAutosaveInterval',
				'builderToolbarLogoLink',
				'builderDisableGlobalClassesInterface',
				'builderDisableRestApi',
				'builderInsertElement',
				'builderInsertLayout',
				'builderGlobalClassesImport',
				'builderHtmlCssConverter',
				'customBreakpoints',
				'enableDynamicDataPreview',
				'enableQueryFilters',
				'bricksComponentsInBlockEditor',
			],
			'templates'    => [
				'publicTemplates',
				'defaultTemplatesDisabled',
				'convertTemplates',
				'generateTemplateScreenshots',
				'myTemplatesAccess',
				'remoteTemplates',
				'remoteTemplatesUrl',
			],
			'integrations' => [
				'apiKeyGoogleMaps',
				'apiKeyGoogleRecaptcha',
				'apiSecretKeyGoogleRecaptcha',
				'apiKeyHCaptcha',
				'apiSecretKeyHCaptcha',
				'apiKeyTurnstile',
				'apiSecretKeyTurnstile',
				'apiKeyMailchimp',
				'apiKeySendgrid',
				'apiKeyUnsplash',
				'instagramAccessToken',
				'adobeFontsProjectId',
				'facebookAppId',
			],
			'woocommerce'  => [
				'woocommerceEnableAjaxAddToCart',
				'woocommerceDisableBuilder',
				'woocommerceAjaxAddedText',
				'woocommerceAjaxAddingText',
				'woocommerceAjaxHideViewCart',
				'woocommerceAjaxShowNotice',
				'woocommerceBadgeNew',
				'woocommerceBadgeSale',
				'woocommerceUseQtyInLoop',
				'woocommerceUseVariationSwatches',
				'woocommerceDisableProductGalleryLightbox',
				'woocommerceDisableProductGalleryZoom',
			],
		];
	}

	/**
	 * Mask sensitive settings in-place.
	 *
	 * Replaces non-empty API keys and secrets with ****configured**** to prevent
	 * exposure of credentials via the MCP interface.
	 *
	 * @param array<string, mixed> $settings Settings array to mask (modified in-place).
	 * @return void
	 */
	private function mask_sensitive_settings( array &$settings ): void {
		$masked_keys = [
			'apiKeyGoogleMaps',
			'apiKeyGoogleRecaptcha',
			'apiSecretKeyGoogleRecaptcha',
			'apiKeyHCaptcha',
			'apiSecretKeyHCaptcha',
			'apiKeyTurnstile',
			'apiSecretKeyTurnstile',
			'apiKeyMailchimp',
			'apiKeySendgrid',
			'apiKeyUnsplash',
			'instagramAccessToken',
			'adobeFontsProjectId',
			'facebookAppId',
			'myTemplatesPassword',
			'remoteTemplatesPassword',
		];

		foreach ( $masked_keys as $key ) {
			if ( isset( $settings[ $key ] ) && ! empty( $settings[ $key ] ) ) {
				$settings[ $key ] = '****configured****';
			}
		}
	}

	/**
	 * Get page-level Bricks settings for a specific post.
	 *
	 * Reads the _bricks_page_settings post meta and returns structured data
	 * with available setting groups.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|\WP_Error Page settings data or error.
	 */
	public function get_page_settings( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found. Use page:list or wordpress:get_posts to find valid post IDs.', 'bricks-mcp' )
			);
		}

		$meta_key = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
		$settings = get_post_meta( $post_id, $meta_key, true );

		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		return [
			'post_id'          => $post_id,
			'post_title'       => $post->post_title,
			'settings'         => $settings,
			'available_groups' => [ 'general', 'scroll-snap', 'seo', 'social-media', 'one-page', 'custom-code' ],
		];
	}

	/**
	 * Update page-level Bricks settings with allowlist validation.
	 *
	 * Validates each key against the page settings allowlist. Unknown keys are
	 * rejected. JS-related keys require dangerous actions mode. CSS writes include
	 * a Bricks-first principle warning. Null values delete individual settings.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $updates Key-value pairs to update.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	public function update_page_settings( int $post_id, array $updates ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found. Use page:list or wordpress:get_posts to find valid post IDs.', 'bricks-mcp' )
			);
		}

		$meta_key  = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
		$settings  = get_post_meta( $post_id, $meta_key, true );
		$allowlist = $this->get_page_settings_allowlist();

		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$dangerous_keys  = [ 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter', 'customCss' ];
		$js_gated_keys   = $dangerous_keys;
		$text_fields     = [ 'bodyClasses', 'postTitle', 'documentTitle', 'metaKeywords', 'sharingTitle' ];
		$textarea_fields = [ 'metaDescription', 'sharingDescription' ];

		$rejected      = [];
		$rejected_keys = [];
		$updated_keys  = [];
		$warnings      = [];
		$css_set       = false;
		$js_set        = false;

		foreach ( $updates as $key => $value ) {
			// Check key against allowlist.
			if ( ! in_array( $key, $allowlist, true ) ) {
				$rejected[]      = [
					'key'    => $key,
					'reason' => __( 'unknown key', 'bricks-mcp' ),
				];
				$rejected_keys[] = $key;
				continue;
			}

			// Check JS-gated keys.
			if ( in_array( $key, $js_gated_keys, true ) && ! $this->is_dangerous_actions_enabled() ) {
				$rejected[]      = [
					'key'    => $key,
					'reason' => __( 'requires dangerous actions mode (Settings > Bricks MCP > Enable Dangerous Actions)', 'bricks-mcp' ),
				];
				$rejected_keys[] = $key;
				continue;
			}

			// Null value = delete.
			if ( null === $value ) {
				unset( $settings[ $key ] );
				$updated_keys[] = $key;
				continue;
			}

			// Sanitize text fields.
			if ( in_array( $key, $text_fields, true ) && is_string( $value ) ) {
				$value = sanitize_text_field( $value );
			}

			// Sanitize textarea fields.
			if ( in_array( $key, $textarea_fields, true ) && is_string( $value ) ) {
				$value = sanitize_textarea_field( $value );
			}

			// Track CSS/JS writes for warnings.
			if ( 'customCss' === $key ) {
				$css_set = true;
			}
			if ( in_array( $key, $js_gated_keys, true ) ) {
				$js_set = true;
			}

			$settings[ $key ] = $value;
			$updated_keys[]   = $key;
		}

		$this->core->unhook_bricks_meta_filters( $meta_key );
		try {
			update_post_meta( $post_id, $meta_key, $settings );
		} finally {
			$this->core->rehook_bricks_meta_filters();
		}

		// Build warnings.
		if ( $css_set ) {
			$warnings[] = __( 'Bricks-first principle: prefer native Bricks elements and classes over custom CSS. Only use custom CSS when the desired result cannot be achieved with Bricks features.', 'bricks-mcp' );
		}
		if ( $js_set ) {
			$warnings[] = __( 'Custom scripts execute on the frontend. Ensure code is safe and necessary.', 'bricks-mcp' );
		}

		return [
			'post_id'      => $post_id,
			'settings'     => $settings,
			'updated_keys' => $updated_keys,
			'rejected'     => $rejected,
			'warnings'     => $warnings,
		];
	}

	/**
	 * Get the page settings allowlist.
	 *
	 * Returns a flat array of all valid page setting keys accepted by
	 * update_page_settings. Organized by group for clarity.
	 *
	 * @return array<int, string> Flat array of allowed setting keys.
	 */
	private function get_page_settings_allowlist(): array {
		return [
			// General.
			'bodyClasses',
			'headerDisabled',
			'footerDisabled',
			'disableLazyLoad',
			'popupDisabled',
			'siteLayout',
			'siteLayoutBoxedMaxWidth',
			'contentBoxShadow',
			'contentBackground',
			'siteBackground',
			'contentMargin',
			'siteBorder',
			'elementMargin',
			'sectionMargin',
			'sectionPadding',
			'containerMaxWidth',
			'lightboxBackground',
			'lightboxCloseColor',
			'lightboxCloseSize',
			'lightboxWidth',
			'lightboxHeight',

			// Scroll snap.
			'scrollSnapType',
			'scrollSnapSelector',
			'scrollSnapAlign',
			'scrollMargin',
			'scrollPadding',
			'scrollSnapStop',

			// SEO.
			'postName',
			'postTitle',
			'documentTitle',
			'metaDescription',
			'metaKeywords',
			'metaRobots',

			// Social media.
			'sharingTitle',
			'sharingDescription',
			'sharingImage',

			// One-page navigation.
			'onePageNavigation',
			'onePageNavigationItemSpacing',
			'onePageNavigationItemHeight',
			'onePageNavigationItemWidth',
			'onePageNavigationItemColor',
			'onePageNavigationItemBorder',
			'onePageNavigationItemBoxShadow',
			'onePageNavigationItemHeightActive',
			'onePageNavigationItemWidthActive',
			'onePageNavigationItemColorActive',
			'onePageNavigationItemBorderActive',
			'onePageNavigationItemBoxShadowActive',

			// Custom code.
			'customCss',
			'customScriptsHeader',
			'customScriptsBodyHeader',
			'customScriptsBodyFooter',
		];
	}

	/**
	 * Get popup display settings for a popup-type template.
	 *
	 * Reads only popup-prefixed keys and template_interactions from
	 * `_bricks_template_settings`. Validates the template is type `popup`.
	 *
	 * @param int $template_id Template post ID.
	 * @return array<string, mixed>|\WP_Error Popup settings data or error.
	 */
	public function get_popup_settings( int $template_id ): array|\WP_Error {
		$post = get_post( $template_id );

		if ( ! $post || 'bricks_template' !== $post->post_type ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %d: Template ID */
					__( 'Template %d not found. Verify the template_id is a valid bricks_template post.', 'bricks-mcp' ),
					$template_id
				)
			);
		}

		$type = get_post_meta( $template_id, '_bricks_template_type', true );
		if ( 'popup' !== $type ) {
			return new \WP_Error(
				'wrong_type',
				sprintf(
					/* translators: 1: Template ID, 2: Actual type */
					__( "Template %1\$d is type '%2\$s', not 'popup'.", 'bricks-mcp' ),
					$template_id,
					$type
				)
			);
		}

		$settings = get_post_meta( $template_id, '_bricks_template_settings', true );
		$settings = is_array( $settings ) ? $settings : [];

		// Extract only popup* keys.
		$popup_keys = array_filter(
			$settings,
			fn( $key ) => str_starts_with( $key, 'popup' ),
			ARRAY_FILTER_USE_KEY
		);

		// Extract template_interactions if present.
		$template_interactions = $settings['template_interactions'] ?? [];

		return array(
			'template_id'            => $template_id,
			'title'                  => $post->post_title,
			'is_infobox'             => ! empty( $popup_keys['popupIsInfoBox'] ),
			'popup_settings'         => $popup_keys,
			'template_interactions'  => $template_interactions,
		);
	}

	/**
	 * Set popup display settings on a popup-type template.
	 *
	 * Validates keys against the popup settings allowlist, then merges into
	 * existing `_bricks_template_settings` — preserving all other keys
	 * (conditions, headerPosition, etc.). Null value on a key deletes it.
	 *
	 * @param int                    $template_id    Template post ID.
	 * @param array<string, mixed>   $popup_settings Key-value pairs of popup settings.
	 * @return array<string, mixed>|\WP_Error Updated settings data or error.
	 */
	public function set_popup_settings( int $template_id, array $popup_settings ): array|\WP_Error {
		$post = get_post( $template_id );

		if ( ! $post || 'bricks_template' !== $post->post_type ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %d: Template ID */
					__( 'Template %d not found. Verify the template_id is a valid bricks_template post.', 'bricks-mcp' ),
					$template_id
				)
			);
		}

		$type = get_post_meta( $template_id, '_bricks_template_type', true );
		if ( 'popup' !== $type ) {
			return new \WP_Error(
				'wrong_type',
				sprintf(
					/* translators: 1: Template ID, 2: Actual type */
					__( "Template %1\$d is type '%2\$s', not 'popup'.", 'bricks-mcp' ),
					$template_id,
					$type
				)
			);
		}

		// Validate keys against allowlist.
		$allowed_keys = $this->get_popup_settings_allowlist();
		$unknown      = array_diff( array_keys( $popup_settings ), $allowed_keys );
		if ( ! empty( $unknown ) ) {
			return new \WP_Error(
				'unknown_keys',
				sprintf(
					/* translators: %s: Unknown key names */
					__( 'Unknown popup setting keys: %s', 'bricks-mcp' ),
					implode( ', ', $unknown )
				)
			);
		}

		// Gate template_interactions behind dangerous_actions (interactions can contain JS).
		if ( isset( $popup_settings['template_interactions'] ) && ! $this->is_dangerous_actions_enabled() ) {
			return new \WP_Error(
				'bricks_mcp_dangerous_action',
				__( 'template_interactions requires dangerous actions mode (Settings > Bricks MCP > Enable Dangerous Actions) because interactions can contain JavaScript.', 'bricks-mcp' )
			);
		}

		// Read-merge-write pattern — preserve all other settings keys.
		$this->core->unhook_bricks_meta_filters();
		try {
			$settings = get_post_meta( $template_id, '_bricks_template_settings', true );
			$settings = is_array( $settings ) ? $settings : [];

			foreach ( $popup_settings as $key => $value ) {
				if ( null === $value ) {
					unset( $settings[ $key ] );
				} elseif ( is_string( $value ) ) {
					$settings[ $key ] = sanitize_text_field( $value );
				} elseif ( is_bool( $value ) || is_int( $value ) ) {
					$settings[ $key ] = $value;
				} elseif ( is_array( $value ) ) {
					$settings[ $key ] = $value; // Arrays (e.g., template_interactions) validated by allowlist above.
				}
			}

			update_post_meta( $template_id, '_bricks_template_settings', $settings );
		} finally {
			$this->core->rehook_bricks_meta_filters();
		}

		// Re-read to return current state.
		$updated = $this->get_popup_settings( $template_id );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'template_id'           => $template_id,
			'updated_keys'          => array_keys( $popup_settings ),
			'popup_settings'        => $updated['popup_settings'],
			'template_interactions' => $updated['template_interactions'],
		);
	}

	/**
	 * Get the allowlist of valid popup settings keys.
	 *
	 * Source: Bricks `includes/popups.php` — `Popups::set_controls()`.
	 *
	 * @return string[] Array of valid popup setting key names.
	 */
	private function get_popup_settings_allowlist(): array {
		return array(
			// Outer popup settings.
			'popupPadding',
			'popupJustifyContent',
			'popupAlignItems',
			'popupCloseOn',
			'popupZindex',
			'popupBodyScroll',
			'popupScrollToTop',
			'popupDisableAutoFocus',
			// Info box.
			'popupIsInfoBox',
			'popupInfoBoxWidth',
			// AJAX content loading.
			'popupAjax',
			'popupIsWoo',
			'popupAjaxLoaderAnimation',
			'popupAjaxLoaderColor',
			'popupAjaxLoaderScale',
			'popupAjaxLoaderSelector',
			// Breakpoint visibility.
			'popupBreakpointMode',
			'popupShowAt',
			'popupShowOn',
			// Backdrop.
			'popupDisableBackdrop',
			'popupBackground',
			'popupBackdropTransition',
			// Content box sizing.
			'popupContentPadding',
			'popupContentWidth',
			'popupContentMinWidth',
			'popupContentMaxWidth',
			'popupContentHeight',
			'popupContentMinHeight',
			'popupContentMaxHeight',
			'popupContentBackground',
			'popupContentBorder',
			'popupContentBoxShadow',
			// Display limits.
			'popupLimitWindow',
			'popupLimitSessionStorage',
			'popupLimitLocalStorage',
			'popupLimitTimeStorage',
			// Template-level interactions.
			'template_interactions',
		);
	}

	/**
	 * Check if dangerous actions are enabled.
	 *
	 * @return bool True if dangerous actions mode is enabled.
	 */
	public function is_dangerous_actions_enabled(): bool {
		return $this->core->is_dangerous_actions_enabled();
	}

	/**
	 * Get all custom code (CSS and scripts) for a page.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|\WP_Error Code data or error.
	 */
	public function get_page_code( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		$meta_key = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
		$settings = get_post_meta( $post_id, $meta_key, true );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array(
			'post_id'                 => $post_id,
			'customCss'               => $settings['customCss'] ?? '',
			'customScriptsHeader'     => $settings['customScriptsHeader'] ?? '',
			'customScriptsBodyHeader' => $settings['customScriptsBodyHeader'] ?? '',
			'customScriptsBodyFooter' => $settings['customScriptsBodyFooter'] ?? '',
			'has_css'                 => ! empty( $settings['customCss'] ),
			'has_scripts'             => ! empty( $settings['customScriptsHeader'] )
				|| ! empty( $settings['customScriptsBodyHeader'] )
				|| ! empty( $settings['customScriptsBodyFooter'] ),
		);
	}

	/**
	 * Set page custom CSS.
	 *
	 * Requires dangerous_actions toggle to be enabled.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $css     Custom CSS code. Empty string removes CSS.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	public function update_page_css( int $post_id, string $css ): array|\WP_Error {
		if ( ! $this->is_dangerous_actions_enabled() ) {
			return new \WP_Error(
				'dangerous_actions_disabled',
				__( 'Custom CSS requires the Dangerous Actions toggle to be enabled in Bricks MCP settings. This is a security measure to prevent code injection.', 'bricks-mcp' )
			);
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		$meta_key = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
		$settings = get_post_meta( $post_id, $meta_key, true );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( '' === $css ) {
			unset( $settings['customCss'] );
		} else {
			// Strip any HTML/PHP tags from the CSS string as a safety measure.
			$settings['customCss'] = wp_strip_all_tags( $css );
		}

		$this->core->unhook_bricks_meta_filters();
		try {
			update_post_meta( $post_id, $meta_key, $settings );
		} finally {
			$this->core->rehook_bricks_meta_filters();
		}

		return array(
			'post_id'          => $post_id,
			'updated'          => true,
			'customCss_length' => strlen( $css ),
		);
	}

	/**
	 * Set page custom scripts.
	 *
	 * Requires dangerous_actions toggle to be enabled.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, string> $scripts Script keys: customScriptsHeader, customScriptsBodyHeader, customScriptsBodyFooter.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	public function update_page_scripts( int $post_id, array $scripts ): array|\WP_Error {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		if ( ! $this->is_dangerous_actions_enabled() ) {
			return new \WP_Error(
				'dangerous_actions_disabled',
				__( 'Custom scripts require the Dangerous Actions toggle to be enabled in Bricks MCP settings. This is a security measure to prevent accidental code injection.', 'bricks-mcp' )
			);
		}

		$allowed_keys = array( 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter' );
		$meta_key     = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
		$settings     = get_post_meta( $post_id, $meta_key, true );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$updated  = array();
		$rejected = array();

		foreach ( $scripts as $key => $value ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				$rejected[] = $key;
				continue;
			}

			if ( '' === (string) $value ) {
				unset( $settings[ $key ] );
			} else {
				$settings[ $key ] = (string) $value;
			}

			$updated[] = $key;
		}

		if ( ! empty( $updated ) ) {
			$this->core->unhook_bricks_meta_filters();
			try {
				update_post_meta( $post_id, $meta_key, $settings );
			} finally {
				$this->core->rehook_bricks_meta_filters();
			}
		}

		return array(
			'post_id'  => $post_id,
			'updated'  => $updated,
			'rejected' => $rejected,
			'warning'  => __( 'Scripts are executed on page load. Test carefully.', 'bricks-mcp' ),
		);
	}

	/**
	 * Get font configuration status overview.
	 *
	 * @return array<string, mixed> Font status data.
	 */
	public function get_font_status(): array {
		$settings    = get_option( BricksCore::OPTION_GLOBAL_SETTINGS, array() );
		$adobe_fonts = get_option( 'bricks_adobe_fonts', array() );

		return array(
			'google_fonts'         => array(
				'enabled' => empty( $settings['disableGoogleFonts'] ),
				'note'    => empty( $settings['disableGoogleFonts'] )
					? __( 'Google Fonts are loaded by default. Use font:update_settings with disable_google_fonts to disable.', 'bricks-mcp' )
					: __( 'Google Fonts are disabled. Use font:update_settings with disable_google_fonts to re-enable.', 'bricks-mcp' ),
			),
			'adobe_fonts'          => array(
				'configured'   => ! empty( $settings['adobeFontsProjectId'] ),
				'fonts_cached' => is_array( $adobe_fonts ) ? count( $adobe_fonts ) : 0,
				'note'         => __( 'Set Adobe Fonts project ID via bricks:update_settings (integrations category, adobeFontsProjectId key). Use font:get_adobe_fonts to list cached fonts.', 'bricks-mcp' ),
			),
			'webfont_loading'      => $settings['webfontLoading'] ?? 'swap',
			'custom_fonts_preload' => ! empty( $settings['customFontsPreload'] ),
			'usage_tip'            => __( 'Apply fonts via _typography["font-family"] in element settings or theme style typography group.', 'bricks-mcp' ),
		);
	}

	/**
	 * Get cached Adobe Fonts from Bricks option storage.
	 *
	 * @return array<string, mixed> Adobe Fonts data.
	 */
	public function get_adobe_fonts(): array {
		$settings    = get_option( BricksCore::OPTION_GLOBAL_SETTINGS, array() );
		$adobe_fonts = get_option( 'bricks_adobe_fonts', array() );

		if ( empty( $settings['adobeFontsProjectId'] ) ) {
			return array(
				'fonts' => array(),
				'count' => 0,
				'note'  => __( 'Adobe Fonts project ID is not configured. Set it via bricks:update_settings (integrations category, adobeFontsProjectId key).', 'bricks-mcp' ),
			);
		}

		if ( ! is_array( $adobe_fonts ) || empty( $adobe_fonts ) ) {
			return array(
				'fonts' => array(),
				'count' => 0,
				'note'  => __( 'Adobe Fonts project ID is configured but no fonts are cached. Open Bricks settings in the WordPress admin to trigger a refresh.', 'bricks-mcp' ),
			);
		}

		return array(
			'fonts' => $adobe_fonts,
			'count' => count( $adobe_fonts ),
			'note'  => __( 'These fonts are cached from your Adobe Fonts project. Refresh by re-saving the project ID in Bricks settings.', 'bricks-mcp' ),
		);
	}

	/**
	 * Update font-related Bricks settings.
	 *
	 * @param array<string, mixed> $fields Settings to update. Allowed: disableGoogleFonts, webfontLoading, customFontsPreload.
	 * @return array<string, mixed>|\WP_Error Update result.
	 */
	public function update_font_settings( array $fields ): array|\WP_Error {
		$allowed_keys = array( 'disableGoogleFonts', 'webfontLoading', 'customFontsPreload' );
		$valid_loading = array( 'swap', 'block', 'fallback', 'optional', 'auto', '' );

		$settings     = get_option( BricksCore::OPTION_GLOBAL_SETTINGS, array() );
		$updated      = array();
		$rejected     = array();

		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				$rejected[ $key ] = __( 'Not a font setting. Use bricks:update_settings for other Bricks settings.', 'bricks-mcp' );
				continue;
			}

			if ( 'webfontLoading' === $key ) {
				if ( ! in_array( (string) $value, $valid_loading, true ) ) {
					$rejected[ $key ] = sprintf(
						/* translators: %s: Valid values */
						__( 'Invalid value. Must be one of: %s', 'bricks-mcp' ),
						implode( ', ', array_map( fn( $v ) => $v === '' ? '""' : $v, $valid_loading ) )
					);
					continue;
				}
				$settings[ $key ] = (string) $value;
				$updated[]        = $key;
			} else {
				// Boolean settings.
				$settings[ $key ] = ! empty( $value );
				$updated[]        = $key;
			}
		}

		if ( ! empty( $updated ) ) {
			update_option( BricksCore::OPTION_GLOBAL_SETTINGS, $settings );
		}

		return array(
			'updated'        => $updated,
			'rejected'       => $rejected,
			'current_values' => array(
				'disableGoogleFonts' => ! empty( $settings['disableGoogleFonts'] ),
				'webfontLoading'     => $settings['webfontLoading'] ?? 'swap',
				'customFontsPreload' => ! empty( $settings['customFontsPreload'] ),
			),
		);
	}
}
