<?php
/**
 * SEO data management sub-service.
 *
 * Multi-plugin abstraction layer supporting Yoast, Rank Math, SEOPress,
 * Slim SEO, and Bricks native SEO settings.
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
 * SeoService class.
 */
class SeoService {

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
	 * Detect the active SEO plugin.
	 *
	 * Priority order: Yoast > Rank Math > SEOPress > Slim SEO > Bricks native.
	 * Detection is centralized here so adding a new plugin requires one change.
	 *
	 * @return string Plugin identifier: 'yoast', 'rankmath', 'seopress', 'slimseo', or 'bricks'.
	 */
	private function detect_seo_plugin(): string {
		if ( class_exists( 'WPSEO_Options' ) ) {
			return 'yoast';
		}
		if ( class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		if ( class_exists( 'SeoPress_Seo_Metabox' ) || function_exists( 'seopress_init' ) ) {
			return 'seopress';
		}
		if ( class_exists( 'SlimSEO\MetaTags\Title' ) ) {
			return 'slimseo';
		}
		return 'bricks';
	}

	/**
	 * Get unified SEO data from the active SEO plugin.
	 *
	 * Reads normalized SEO fields from whichever SEO plugin is active (Yoast, Rank Math,
	 * SEOPress, Slim SEO) or falls back to Bricks native page settings. Includes an inline
	 * SEO audit with title/description length checks and OG image detection.
	 *
	 * @param int $post_id Post ID to read SEO data for.
	 * @return array<string, mixed>|\WP_Error Normalized SEO data with audit, or error.
	 */
	public function get_seo_data( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found. Use page tool (action: list) to find valid post IDs.', 'bricks-mcp' )
			);
		}

		$plugin = $this->detect_seo_plugin();

		$data = array(
			'post_id'       => $post_id,
			'seo_plugin'    => $plugin,
			'plugin_active' => 'bricks' !== $plugin,
			'fields'        => array(),
			'audit'         => array(),
		);

		switch ( $plugin ) {
			case 'yoast':
				$noindex  = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
				$nofollow = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );

				$data['fields'] = array(
					'title'               => get_post_meta( $post_id, '_yoast_wpseo_title', true ) ?: '',
					'description'         => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) ?: '',
					'robots_noindex'      => '1' === $noindex,
					'robots_nofollow'     => '1' === $nofollow,
					'canonical'           => get_post_meta( $post_id, '_yoast_wpseo_canonical', true ) ?: '',
					'og_title'            => get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true ) ?: '',
					'og_description'      => get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true ) ?: '',
					'og_image'            => get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true ) ?: '',
					'twitter_title'       => get_post_meta( $post_id, '_yoast_wpseo_twitter-title', true ) ?: '',
					'twitter_description' => get_post_meta( $post_id, '_yoast_wpseo_twitter-description', true ) ?: '',
					'twitter_image'       => get_post_meta( $post_id, '_yoast_wpseo_twitter-image', true ) ?: '',
					'focus_keyword'       => get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) ?: '',
				);
				break;

			case 'rankmath':
				$robots = get_post_meta( $post_id, 'rank_math_robots', true );
				$robots = is_array( $robots ) ? $robots : array();

				$data['fields'] = array(
					'title'           => get_post_meta( $post_id, 'rank_math_title', true ) ?: '',
					'description'     => get_post_meta( $post_id, 'rank_math_description', true ) ?: '',
					'robots_noindex'  => in_array( 'noindex', $robots, true ),
					'robots_nofollow' => in_array( 'nofollow', $robots, true ),
					'canonical'       => get_post_meta( $post_id, 'rank_math_canonical_url', true ) ?: '',
					'focus_keyword'   => get_post_meta( $post_id, 'rank_math_focus_keyword', true ) ?: '',
					'og_image'        => get_post_meta( $post_id, 'rank_math_facebook_image', true ) ?: '',
				);
				break;

			case 'seopress':
				$noindex  = get_post_meta( $post_id, '_seopress_robots_index', true );
				$nofollow = get_post_meta( $post_id, '_seopress_robots_follow', true );

				$data['fields'] = array(
					'title'               => get_post_meta( $post_id, '_seopress_titles_title', true ) ?: '',
					'description'         => get_post_meta( $post_id, '_seopress_titles_desc', true ) ?: '',
					'robots_noindex'      => 'yes' === $noindex,
					'robots_nofollow'     => 'yes' === $nofollow,
					'canonical'           => get_post_meta( $post_id, '_seopress_robots_canonical', true ) ?: '',
					'og_title'            => get_post_meta( $post_id, '_seopress_social_fb_title', true ) ?: '',
					'og_description'      => get_post_meta( $post_id, '_seopress_social_fb_desc', true ) ?: '',
					'og_image'            => get_post_meta( $post_id, '_seopress_social_fb_img', true ) ?: '',
					'twitter_title'       => get_post_meta( $post_id, '_seopress_social_twitter_title', true ) ?: '',
					'twitter_image'       => get_post_meta( $post_id, '_seopress_social_twitter_img', true ) ?: '',
				);
				break;

			case 'slimseo':
				$slim = get_post_meta( $post_id, 'slim_seo', true );
				$slim = is_array( $slim ) ? $slim : array();

				$data['fields'] = array(
					'title'       => $slim['title'] ?? '',
					'description' => $slim['description'] ?? '',
					'canonical'   => $slim['canonical'] ?? '',
				);
				break;

			case 'bricks':
			default:
				$meta_key = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
				$settings = get_post_meta( $post_id, $meta_key, true );
				$settings = is_array( $settings ) ? $settings : array();

				$data['fields'] = array(
					'title'          => $settings['documentTitle'] ?? '',
					'description'    => $settings['metaDescription'] ?? '',
					'keywords'       => $settings['metaKeywords'] ?? '',
					'robots'         => $settings['metaRobots'] ?? '',
					'og_title'       => $settings['sharingTitle'] ?? '',
					'og_description' => $settings['sharingDescription'] ?? '',
					'og_image'       => $settings['sharingImage'] ?? '',
				);
				break;
		}

		// SEO audit: simple quality checks.
		$title       = $data['fields']['title'] ?? '';
		$description = $data['fields']['description'] ?? '';
		$title_len   = mb_strlen( $title, 'UTF-8' );
		$desc_len    = mb_strlen( $description, 'UTF-8' );

		$data['audit'] = array(
			'title_length'       => $title_len,
			'title_ok'           => $title_len >= 30 && $title_len <= 60,
			'title_issue'        => 0 === $title_len ? 'missing' : ( $title_len < 30 ? 'too_short' : ( $title_len > 60 ? 'too_long' : null ) ),
			'description_length' => $desc_len,
			'description_ok'     => $desc_len >= 120 && $desc_len <= 160,
			'description_issue'  => 0 === $desc_len ? 'missing' : ( $desc_len < 120 ? 'too_short' : ( $desc_len > 160 ? 'too_long' : null ) ),
			'has_og_image'       => ! empty( $data['fields']['og_image'] ),
		);

		return $data;
	}

	/**
	 * Update SEO data via the active SEO plugin.
	 *
	 * Writes normalized field names to the correct plugin meta keys. Sanitizes text
	 * fields via sanitize_text_field() and URL fields via esc_url_raw(). Tracks which
	 * fields were updated, unsupported, or skipped.
	 *
	 * @param int                  $post_id Post ID to update SEO data for.
	 * @param array<string, mixed> $fields  Normalized SEO field values to write.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	public function update_seo_data( int $post_id, array $fields ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found. Use page tool (action: list) to find valid post IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $fields ) ) {
			return new \WP_Error(
				'empty_fields',
				__( 'At least one SEO field must be provided. Accepted: title, description, robots_noindex, robots_nofollow, canonical, og_title, og_description, og_image, twitter_title, twitter_description, twitter_image, focus_keyword.', 'bricks-mcp' )
			);
		}

		$plugin = $this->detect_seo_plugin();

		// Accepted normalized field names.
		$text_fields = array( 'title', 'description', 'og_title', 'og_description', 'twitter_title', 'twitter_description', 'focus_keyword' );
		$url_fields  = array( 'canonical', 'og_image', 'twitter_image' );
		$bool_fields = array( 'robots_noindex', 'robots_nofollow' );
		$all_fields  = array_merge( $text_fields, $url_fields, $bool_fields );

		// Sanitize inputs.
		$sanitized = array();
		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, $all_fields, true ) ) {
				continue;
			}
			if ( in_array( $key, $text_fields, true ) ) {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
			} elseif ( in_array( $key, $url_fields, true ) ) {
				$sanitized[ $key ] = esc_url_raw( (string) $value );
			} elseif ( in_array( $key, $bool_fields, true ) ) {
				$sanitized[ $key ] = (bool) $value;
			}
		}

		$updated     = array();
		$unsupported = array();

		$plugin_notes = array(
			'yoast'    => __( 'SEO fields written to Yoast SEO meta keys. Changes will appear in the Yoast metabox.', 'bricks-mcp' ),
			'rankmath' => __( 'SEO fields written to Rank Math meta keys. Changes will appear in the Rank Math metabox.', 'bricks-mcp' ),
			'seopress' => __( 'SEO fields written to SEOPress meta keys. Changes will appear in the SEOPress metabox.', 'bricks-mcp' ),
			'slimseo'  => __( 'SEO fields written to Slim SEO meta key. Only title, description, and canonical are supported.', 'bricks-mcp' ),
			'bricks'   => __( 'SEO fields written to Bricks native page settings. Only effective when no SEO plugin is active.', 'bricks-mcp' ),
		);

		switch ( $plugin ) {
			case 'yoast':
				$yoast_map = array(
					'title'               => '_yoast_wpseo_title',
					'description'         => '_yoast_wpseo_metadesc',
					'canonical'           => '_yoast_wpseo_canonical',
					'og_title'            => '_yoast_wpseo_opengraph-title',
					'og_description'      => '_yoast_wpseo_opengraph-description',
					'og_image'            => '_yoast_wpseo_opengraph-image',
					'twitter_title'       => '_yoast_wpseo_twitter-title',
					'twitter_description' => '_yoast_wpseo_twitter-description',
					'twitter_image'       => '_yoast_wpseo_twitter-image',
					'focus_keyword'       => '_yoast_wpseo_focuskw',
				);

				foreach ( $yoast_map as $field => $meta_key ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						update_post_meta( $post_id, $meta_key, $sanitized[ $field ] );
						$updated[] = $field;
					}
				}

				// Robots booleans.
				if ( array_key_exists( 'robots_noindex', $sanitized ) ) {
					update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $sanitized['robots_noindex'] ? '1' : '' );
					$updated[] = 'robots_noindex';
				}
				if ( array_key_exists( 'robots_nofollow', $sanitized ) ) {
					update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', $sanitized['robots_nofollow'] ? '1' : '' );
					$updated[] = 'robots_nofollow';
				}
				break;

			case 'rankmath':
				$rm_map = array(
					'title'         => 'rank_math_title',
					'description'   => 'rank_math_description',
					'canonical'     => 'rank_math_canonical_url',
					'focus_keyword' => 'rank_math_focus_keyword',
					'og_image'      => 'rank_math_facebook_image',
				);

				foreach ( $rm_map as $field => $meta_key ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						update_post_meta( $post_id, $meta_key, $sanitized[ $field ] );
						$updated[] = $field;
					}
				}

				// Rank Math robots: read-modify-write array.
				$robots_changed = false;
				$robots         = get_post_meta( $post_id, 'rank_math_robots', true );
				$robots         = is_array( $robots ) ? $robots : array();

				if ( array_key_exists( 'robots_noindex', $sanitized ) ) {
					if ( $sanitized['robots_noindex'] ) {
						$robots[] = 'noindex';
					} else {
						$robots = array_diff( $robots, array( 'noindex' ) );
					}
					$robots_changed = true;
					$updated[]      = 'robots_noindex';
				}
				if ( array_key_exists( 'robots_nofollow', $sanitized ) ) {
					if ( $sanitized['robots_nofollow'] ) {
						$robots[] = 'nofollow';
					} else {
						$robots = array_diff( $robots, array( 'nofollow' ) );
					}
					$robots_changed = true;
					$updated[]      = 'robots_nofollow';
				}

				if ( $robots_changed ) {
					update_post_meta( $post_id, 'rank_math_robots', array_values( array_unique( $robots ) ) );
				}

				// Rank Math unsupported fields.
				$rm_unsupported = array( 'og_title', 'og_description', 'twitter_title', 'twitter_description', 'twitter_image' );
				foreach ( $rm_unsupported as $field ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						$unsupported[ $field ] = __( 'Rank Math uses the main title/description for OG/Twitter.', 'bricks-mcp' );
					}
				}
				break;

			case 'seopress':
				$sp_map = array(
					'title'               => '_seopress_titles_title',
					'description'         => '_seopress_titles_desc',
					'canonical'           => '_seopress_robots_canonical',
					'og_title'            => '_seopress_social_fb_title',
					'og_description'      => '_seopress_social_fb_desc',
					'og_image'            => '_seopress_social_fb_img',
					'twitter_title'       => '_seopress_social_twitter_title',
					'twitter_description' => '_seopress_social_twitter_desc',
					'twitter_image'       => '_seopress_social_twitter_img',
				);

				foreach ( $sp_map as $field => $meta_key ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						update_post_meta( $post_id, $meta_key, $sanitized[ $field ] );
						$updated[] = $field;
					}
				}

				// SEOPress robots booleans.
				if ( array_key_exists( 'robots_noindex', $sanitized ) ) {
					update_post_meta( $post_id, '_seopress_robots_index', $sanitized['robots_noindex'] ? 'yes' : '' );
					$updated[] = 'robots_noindex';
				}
				if ( array_key_exists( 'robots_nofollow', $sanitized ) ) {
					update_post_meta( $post_id, '_seopress_robots_follow', $sanitized['robots_nofollow'] ? 'yes' : '' );
					$updated[] = 'robots_nofollow';
				}

				// SEOPress unsupported.
				if ( array_key_exists( 'focus_keyword', $sanitized ) ) {
					$unsupported['focus_keyword'] = __( 'SEOPress does not support focus keyword per post.', 'bricks-mcp' );
				}
				break;

			case 'slimseo':
				// Slim SEO: read-modify-write single serialized array.
				$slim              = get_post_meta( $post_id, 'slim_seo', true );
				$slim              = is_array( $slim ) ? $slim : array();
				$slim_allowed_keys = array( 'title', 'description', 'canonical' );

				foreach ( $slim_allowed_keys as $field ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						$slim[ $field ] = $sanitized[ $field ];
						$updated[]      = $field;
					}
				}

				if ( ! empty( $updated ) ) {
					update_post_meta( $post_id, 'slim_seo', $slim );
				}

				// Slim SEO unsupported fields.
				$slim_unsupported = array( 'robots_noindex', 'robots_nofollow', 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description', 'twitter_image', 'focus_keyword' );
				foreach ( $slim_unsupported as $field ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						$unsupported[ $field ] = __( 'Slim SEO only supports title, description, and canonical per post.', 'bricks-mcp' );
					}
				}
				break;

			case 'bricks':
			default:
				$meta_key = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
				$settings = get_post_meta( $post_id, $meta_key, true );
				$settings = is_array( $settings ) ? $settings : array();

				$bricks_map = array(
					'title'          => 'documentTitle',
					'description'    => 'metaDescription',
					'og_title'       => 'sharingTitle',
					'og_description' => 'sharingDescription',
					'og_image'       => 'sharingImage',
				);

				foreach ( $bricks_map as $field => $settings_key ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						$settings[ $settings_key ] = $sanitized[ $field ];
						$updated[]                 = $field;
					}
				}

				// Bricks robots: map booleans to metaRobots string.
				if ( array_key_exists( 'robots_noindex', $sanitized ) || array_key_exists( 'robots_nofollow', $sanitized ) ) {
					$robots_parts = array();
					$noindex      = $sanitized['robots_noindex'] ?? false;
					$nofollow     = $sanitized['robots_nofollow'] ?? false;

					if ( $noindex ) {
						$robots_parts[] = 'noindex';
					}
					if ( $nofollow ) {
						$robots_parts[] = 'nofollow';
					}

					$settings['metaRobots'] = implode( ', ', $robots_parts );

					if ( array_key_exists( 'robots_noindex', $sanitized ) ) {
						$updated[] = 'robots_noindex';
					}
					if ( array_key_exists( 'robots_nofollow', $sanitized ) ) {
						$updated[] = 'robots_nofollow';
					}
				}

				if ( ! empty( $updated ) ) {
					update_post_meta( $post_id, $meta_key, $settings );
				}

				// Bricks unsupported fields.
				$bricks_unsupported = array( 'canonical', 'twitter_title', 'twitter_description', 'twitter_image', 'focus_keyword' );
				foreach ( $bricks_unsupported as $field ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						$unsupported[ $field ] = __( 'Bricks native SEO does not support this field.', 'bricks-mcp' );
					}
				}
				break;
		}

		return array(
			'post_id'             => $post_id,
			'seo_plugin'          => $plugin,
			'updated_fields'      => array_unique( $updated ),
			'unsupported_fields'  => $unsupported,
			'note'                => $plugin_notes[ $plugin ] ?? '',
		);
	}
}
