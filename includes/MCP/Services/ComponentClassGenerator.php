<?php
/**
 * Token-driven fallback component class generator.
 *
 * Creates class definitions for missing semantic component roles without
 * hardcoding site-specific values. Values are resolved from the current site's
 * variables when available, with conservative raw fallbacks only for fresh sites.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ComponentClassGenerator {

	/**
	 * Class category name for generated fallback components.
	 */
	public const CATEGORY_NAME = 'Bricks MCP Components';

	/**
	 * Generated semantic role => class name map.
	 */
	private const ROLE_CLASS_NAMES = [
		'button.primary'   => 'mcp-button-primary',
		'button.secondary' => 'mcp-button-secondary',
		'card.default'     => 'mcp-card',
		'card.featured'    => 'mcp-card-featured',
		'text.eyebrow'     => 'mcp-eyebrow',
		'text.subtitle'    => 'mcp-subtitle',
	];

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $style_roles;

	/**
	 * @param array<string, array<string, mixed>> $style_roles StyleRoleResolver output.
	 */
	public function __construct( array $style_roles ) {
		$this->style_roles = $style_roles;
	}

	/**
	 * Return generated class definitions for currently unresolved component roles.
	 *
	 * @return array<string, array{name: string, styles: array<string, mixed>, semantic_role: string, source: string}>
	 */
	public function missing_component_definitions(): array {
		$definitions = [];

		foreach ( self::ROLE_CLASS_NAMES as $role => $name ) {
			$resolution = $this->style_roles[ $role ] ?? [];
			if ( is_array( $resolution ) && ( $resolution['status'] ?? '' ) === 'resolved' ) {
				continue;
			}

			$styles = $this->styles_for_role( $role );
			if ( empty( $styles ) ) {
				continue;
			}

			$definitions[ $role ] = [
				'name'          => $name,
				'styles'        => $styles,
				'semantic_role' => $role,
				'source'        => 'generated_token_driven_fallback',
			];
		}

		return $definitions;
	}

	/**
	 * Return class definition for a semantic role if it must be generated.
	 *
	 * @return array{name: string, styles: array<string, mixed>, semantic_role: string, source: string}|null
	 */
	public function definition_for_role( string $role ): ?array {
		$definitions = $this->missing_component_definitions();
		return $definitions[ $role ] ?? null;
	}

	/**
	 * Get generated class name for a role.
	 */
	public static function class_name_for_role( string $role ): ?string {
		return self::ROLE_CLASS_NAMES[ $role ] ?? null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function styles_for_role( string $role ): array {
		return match ( $role ) {
			'button.primary'   => $this->primary_button_styles(),
			'button.secondary' => $this->secondary_button_styles(),
			'card.default'     => $this->card_styles( false ),
			'card.featured'    => $this->card_styles( true ),
			'text.eyebrow'     => $this->eyebrow_styles(),
			'text.subtitle'    => $this->subtitle_styles(),
			default            => [],
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function primary_button_styles(): array {
		return [
			'_display'        => 'inline-flex',
			'_alignItems'     => 'center',
			'_justifyContent' => 'center',
			'_columnGap'      => $this->token( 'space.content_gap', 'space-s', '0.75rem' ),
			'_padding'        => $this->box( $this->token( 'space.content_gap', 'space-s', '0.75rem' ), $this->token( 'space.section_padding', 'space-l', '1.5rem' ) ),
			'_background'     => [ 'color' => $this->color( $this->token( 'color.primary', 'primary', '#1f4fff' ) ) ],
			'_typography'     => [
				'font-size'   => $this->token( 'text.body', 'text-m', '1rem' ),
				'font-weight' => '700',
				'line-height' => '1',
				'color'       => $this->color( $this->token( 'color.on_primary', 'white', '#ffffff' ) ),
			],
			'_border'         => [
				'style'  => 'solid',
				'width'  => $this->box( '1px' ),
				'color'  => $this->color( $this->token( 'color.primary', 'primary', '#1f4fff' ) ),
				'radius' => $this->box( $this->token( 'radius.button', 'radius-btn', '999px' ) ),
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function secondary_button_styles(): array {
		return [
			'_display'        => 'inline-flex',
			'_alignItems'     => 'center',
			'_justifyContent' => 'center',
			'_columnGap'      => $this->token( 'space.content_gap', 'space-s', '0.75rem' ),
			'_padding'        => $this->box( $this->token( 'space.content_gap', 'space-s', '0.75rem' ), $this->token( 'space.section_padding', 'space-l', '1.5rem' ) ),
			'_background'     => [ 'color' => $this->color( 'transparent' ) ],
			'_typography'     => [
				'font-size'   => $this->token( 'text.body', 'text-m', '1rem' ),
				'font-weight' => '700',
				'line-height' => '1',
				'color'       => $this->color( $this->token( 'color.primary', 'primary', '#1f4fff' ) ),
			],
			'_border'         => [
				'style'  => 'solid',
				'width'  => $this->box( '1px' ),
				'color'  => $this->color( $this->token( 'color.primary', 'primary', '#1f4fff' ) ),
				'radius' => $this->box( $this->token( 'radius.button', 'radius-btn', '999px' ) ),
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function card_styles( bool $featured ): array {
		$border_color = $featured
			? $this->token( 'color.primary', 'primary', '#1f4fff' )
			: $this->token( 'color.border', 'border-color', '#e5e7eb' );

		return [
			'_display'    => 'flex',
			'_direction'  => 'column',
			'_rowGap'     => $this->token( 'space.content_gap', 'content-gap', '1rem' ),
			'_padding'    => $this->box( $this->token( 'space.card_padding', 'space-l', '1.5rem' ) ),
			'_background' => [ 'color' => $this->color( $this->token( 'color.surface.light', 'white', '#ffffff' ) ) ],
			'_border'     => [
				'style'  => 'solid',
				'width'  => $this->box( $featured ? '2px' : '1px' ),
				'color'  => $this->color( $border_color ),
				'radius' => $this->box( $this->token( 'radius.card', 'radius', '8px' ) ),
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function eyebrow_styles(): array {
		return [
			'_typography' => [
				'font-size'      => $this->token( 'text.small', 'text-s', '0.875rem' ),
				'font-weight'    => '700',
				'line-height'    => '1.2',
				'letter-spacing' => '0.08em',
				'text-transform' => 'uppercase',
				'color'          => $this->color( $this->token( 'color.primary', 'primary', '#1f4fff' ) ),
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function subtitle_styles(): array {
		return [
			'_typography' => [
				'font-size'   => $this->token( 'text.large', 'text-l', '1.125rem' ),
				'font-weight' => '400',
				'line-height' => $this->token( 'text.line_height', 'text-line-height', '1.6' ),
				'color'       => $this->color( $this->token( 'color.text', 'text-color', '#374151' ) ),
			],
		];
	}

	/**
	 * Resolve a token role or fallback variable name into a CSS value.
	 */
	private function token( string $role, string $fallback_variable, string $raw_fallback ): string {
		$resolution = $this->style_roles[ $role ] ?? null;
		if ( is_array( $resolution ) && ( $resolution['status'] ?? '' ) === 'resolved' && ! empty( $resolution['reference'] ) ) {
			return (string) $resolution['reference'];
		}

		if ( SiteVariableResolver::exists( $fallback_variable ) ) {
			return 'var(--' . ltrim( $fallback_variable, '-' ) . ')';
		}

		return $raw_fallback;
	}

	/**
	 * @return array<string, string>
	 */
	private function color( string $value ): array {
		if ( str_starts_with( $value, '#' ) ) {
			return [ 'hex' => $value ];
		}
		return [ 'raw' => $value ];
	}

	/**
	 * @return array{top: string, right: string, bottom: string, left: string}
	 */
	private function box( string $vertical, ?string $horizontal = null ): array {
		$horizontal = $horizontal ?? $vertical;
		return [
			'top'    => $vertical,
			'right'  => $horizontal,
			'bottom' => $vertical,
			'left'   => $horizontal,
		];
	}
}
