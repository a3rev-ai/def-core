<?php
/**
 * Theme Color Detection utility.
 *
 * Detects button colors from the active WordPress theme for use as
 * Customer Chat button defaults. Supports block themes (FSE) via
 * wp_get_global_styles() and classic themes via CSS parsing.
 *
 * @package def-core
 * @since 2.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DEF_Core_Theme_Colors {

	/**
	 * Set button colors from the active theme on first activation.
	 * Only sets colors if no custom values have been saved yet.
	 */
	public static function maybe_set_defaults(): void {
		// Only set if not already configured (i.e., still using defaults).
		if ( get_option( 'def_core_chat_button_color' ) !== false ) {
			return;
		}

		$colors = self::detect();
		if ( ! empty( $colors['button_color'] ) ) {
			update_option( 'def_core_chat_button_color', $colors['button_color'] );
		}
		if ( ! empty( $colors['button_hover_color'] ) ) {
			update_option( 'def_core_chat_button_hover_color', $colors['button_hover_color'] );
		}
	}

	/**
	 * Detect button colors from the active WordPress theme.
	 *
	 * Strategy:
	 * 1. Block themes (FSE): Use wp_get_global_styles() for button element colors.
	 * 2. Classic themes: Parse theme's style.css for button/submit CSS rules.
	 *
	 * @return array{button_color: string, button_hover_color: string, source: string, theme_name: string}
	 */
	public static function detect(): array {
		$result = array(
			'button_color'       => '',
			'button_hover_color' => '',
			'source'             => 'none',
			'theme_name'         => wp_get_theme()->get( 'Name' ),
		);

		// Strategy 1: Block theme (FSE) — wp_get_global_styles().
		if ( function_exists( 'wp_get_global_styles' ) ) {
			$styles = wp_get_global_styles();

			// Primary button background from elements.button.color.background.
			$button_bg = $styles['elements']['button']['color']['background'] ?? '';
			if ( $button_bg ) {
				$resolved = self::resolve_color( $button_bg );
				if ( $resolved ) {
					$result['button_color'] = $resolved;
					$result['source']       = 'block_theme';
				}
			}

			// Hover state from elements.button.:hover.color.background.
			$hover_bg = $styles['elements']['button'][':hover']['color']['background'] ?? '';
			if ( $hover_bg ) {
				$resolved = self::resolve_color( $hover_bg );
				if ( $resolved ) {
					$result['button_hover_color'] = $resolved;
				}
			}

			if ( $result['source'] === 'block_theme' ) {
				return $result;
			}
		}

		// Strategy 2: Classic theme — parse style.css for button colors.
		$stylesheet_path = get_stylesheet_directory() . '/style.css';
		if ( ! file_exists( $stylesheet_path ) ) {
			return $result;
		}

		$css = file_get_contents( $stylesheet_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( empty( $css ) ) {
			return $result;
		}

		$color = self::extract_button_color_from_css( $css );
		if ( $color ) {
			$result['button_color'] = $color;
			$result['source']       = 'classic_theme_css';
		}

		return $result;
	}

	/**
	 * Resolve a theme.json color reference to a hex value.
	 *
	 * Handles: hex colors, CSS variable references (var(--wp--preset--color--slug)),
	 * rgb() values, and named CSS colors.
	 *
	 * @param string $color The color value.
	 * @return string Resolved hex color or empty string.
	 */
	public static function resolve_color( string $color ): string {
		// Already a hex color.
		if ( preg_match( '/^#([0-9a-fA-F]{3,8})$/', $color ) ) {
			return $color;
		}

		// CSS variable reference from theme.json: var(--wp--preset--color--slug).
		if ( preg_match( '/var\(\s*--wp--preset--color--([a-zA-Z0-9-]+)\s*\)/', $color, $matches ) ) {
			$slug = $matches[1];
			if ( function_exists( 'wp_get_global_settings' ) ) {
				$settings = wp_get_global_settings();
				$palette  = $settings['color']['palette']['theme'] ?? array();
				foreach ( $palette as $entry ) {
					if ( ( $entry['slug'] ?? '' ) === $slug ) {
						return $entry['color'] ?? '';
					}
				}
			}
		}

		// RGB/RGBA — convert simple rgb() to hex.
		if ( preg_match( '/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/', $color, $matches ) ) {
			return sprintf( '#%02x%02x%02x', (int) $matches[1], (int) $matches[2], (int) $matches[3] );
		}

		// Named CSS colors — return as-is if it looks like a word.
		if ( preg_match( '/^[a-zA-Z]+$/', $color ) ) {
			return $color;
		}

		return '';
	}

	/**
	 * Extract the first button background-color from a CSS string.
	 *
	 * @param string $css Raw CSS content.
	 * @return string Hex color or empty string.
	 */
	public static function extract_button_color_from_css( string $css ): string {
		// Remove CSS comments.
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css );

		// Patterns ordered by specificity (WordPress block elements first).
		$selectors = array(
			'/\.wp-element-button\s*\{([^}]+)\}/i',
			'/\.wp-block-button__link\s*\{([^}]+)\}/i',
			'/button\s*,?\s*input\[type=["\']submit["\']\]\s*\{([^}]+)\}/i',
			'/\.btn-primary\s*\{([^}]+)\}/i',
			'/\.button\s*\{([^}]+)\}/i',
			'/button\s*\{([^}]+)\}/i',
			'/input\[type=["\']submit["\']\]\s*\{([^}]+)\}/i',
			'/\.btn\s*\{([^}]+)\}/i',
		);

		foreach ( $selectors as $pattern ) {
			if ( preg_match( $pattern, $css, $block_match ) ) {
				$block = $block_match[1];
				if ( preg_match( '/background-color\s*:\s*([^;]+)/i', $block, $color_match ) ) {
					$value    = trim( $color_match[1] );
					$resolved = self::resolve_color( $value );
					if ( $resolved ) {
						return $resolved;
					}
				}
				if ( preg_match( '/background\s*:\s*(#[0-9a-fA-F]{3,8})\b/i', $block, $color_match ) ) {
					return trim( $color_match[1] );
				}
			}
		}

		return '';
	}
}
