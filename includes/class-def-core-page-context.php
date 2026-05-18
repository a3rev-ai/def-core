<?php
/**
 * Class DEF_Core_Page_Context
 *
 * Page Context Build Plan V1.1 Sub-PR C. Server-side detection helpers
 * that produce the structured fields the chat-widget JS picks up via a
 * wp_localize_script-injected global (`window.DefCorePageContext`).
 *
 * Flow: PHP detects everything WP/WC functions can tell us at page-render
 * time (page type, IDs, queried taxonomy, terms attached to the queried
 * object, language). The JS half adds the parts WP can't reliably tell
 * us (the actual browser path + same-origin referrer derived from
 * `window.location.pathname` / `document.referrer` at submit time).
 *
 * The DEF backend (Sub-PR B, already deployed) validates the resulting
 * payload via Pydantic and persists into ThreadState.page_context_history.
 *
 * Spec: DEF-PAGE-CONTEXT-V1.2.md §3.1.
 *
 * @package digital-employees
 * @since   3.4.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_Page_Context {

	/**
	 * Closed enum of page types. Mirrors the Pydantic `_PAGE_TYPE_VALUES`
	 * literal in DEF's `app/chatbot/page_context_schemas.py`. `other_singular`
	 * is the custom-CPT bucket; `other` is everything else.
	 */
	private const PAGE_TYPES = array(
		'home',
		'product',
		'taxonomy_archive',
		'shop',
		'cart',
		'checkout',
		'account',
		'search',
		'post',
		'page',
		'other_singular',
		'other',
	);

	private const TERMS_CAP = 10;

	/**
	 * Build the page-context payload that gets localized as
	 * `window.DefCorePageContext`. JS overrides `canonical_path` from
	 * `window.location.pathname` at mount time and adds `referrer_path`
	 * at submit time, but every other field is PHP-derived.
	 *
	 * @return array Structured page context.
	 */
	public static function build_payload(): array {
		$post_id = (int) ( get_queried_object_id() ?: 0 );
		return array(
			'language_code'    => self::detect_language(),
			'page_type'        => self::detect_page_type(),
			'page_id'          => $post_id,
			'product_id'       => function_exists( 'is_product' ) && is_product() ? $post_id : null,
			'queried_taxonomy' => self::collect_queried_taxonomy(),
			'terms'            => self::collect_page_terms( $post_id ),
			'title'            => self::safe_title(),
		);
	}

	/**
	 * BCP-47 language code. WPML / Polylang detected built-in; other
	 * multilingual plugins integrate via the `def_core_detected_language`
	 * filter hook.
	 */
	public static function detect_language(): string {
		// WPML
		if ( defined( 'ICL_LANGUAGE_CODE' ) && ICL_LANGUAGE_CODE ) {
			$lang = (string) constant( 'ICL_LANGUAGE_CODE' );
			return self::sanitize_language_code( $lang );
		}
		// Polylang
		if ( function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language( 'slug' );
			if ( is_string( $lang ) && $lang !== '' ) {
				return self::sanitize_language_code( $lang );
			}
		}
		// WP core fallback (en_US -> en, de_DE -> de, zh_CN -> zh-Hans best-effort).
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$bcp47  = self::locale_to_bcp47( (string) $locale );

		// Filter hook for other multilingual plugins (TranslatePress, MultilingualPress).
		$bcp47 = apply_filters( 'def_core_detected_language', $bcp47 );
		return self::sanitize_language_code( (string) $bcp47 );
	}

	/**
	 * Maps the WP page query state to the closed page_type enum.
	 *
	 * @return string One of self::PAGE_TYPES.
	 */
	public static function detect_page_type(): string {
		if ( function_exists( 'is_front_page' ) && ( is_front_page() || is_home() ) ) {
			return 'home';
		}
		// WooCommerce singular surfaces — check is_product / is_shop / is_cart /
		// is_checkout / is_account_page BEFORE the generic is_singular() fallback.
		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return 'shop';
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'cart';
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'checkout';
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return 'account';
		}
		if ( function_exists( 'is_search' ) && is_search() ) {
			return 'search';
		}
		if ( function_exists( 'is_singular' ) && is_singular( 'post' ) ) {
			return 'post';
		}
		if ( function_exists( 'is_singular' ) && is_singular( 'page' ) ) {
			return 'page';
		}
		// Any taxonomy archive — built-in (category, tag, product_cat, product_tag)
		// OR custom taxonomies registered by themes/plugins.
		if ( ( function_exists( 'is_category' ) && is_category() ) ||
			 ( function_exists( 'is_tag' ) && is_tag() ) ||
			 ( function_exists( 'is_tax' ) && is_tax() ) ||
			 ( function_exists( 'is_product_category' ) && is_product_category() ) ||
			 ( function_exists( 'is_product_tag' ) && is_product_tag() ) ) {
			return 'taxonomy_archive';
		}
		if ( function_exists( 'is_singular' ) && is_singular() ) {
			// Custom CPT page — taxonomy terms collected via collect_page_terms.
			return 'other_singular';
		}
		return 'other';
	}

	/**
	 * Populated only when page_type == 'taxonomy_archive'. Returns the queried
	 * term + (for hierarchical taxonomies) the parent chain.
	 *
	 * @return array|null Queried taxonomy dict or null.
	 */
	public static function collect_queried_taxonomy(): ?array {
		if ( self::detect_page_type() !== 'taxonomy_archive' ) {
			return null;
		}
		if ( ! function_exists( 'get_queried_object' ) ) {
			return null;
		}
		$obj = get_queried_object();
		if ( ! is_object( $obj ) || ! isset( $obj->taxonomy, $obj->term_id ) ) {
			return null;
		}
		$hierarchy = array();
		if ( function_exists( 'is_taxonomy_hierarchical' ) && is_taxonomy_hierarchical( $obj->taxonomy ) ) {
			$ancestor_ids = get_ancestors( (int) $obj->term_id, (string) $obj->taxonomy );
			foreach ( array_reverse( (array) $ancestor_ids ) as $aid ) {
				$a = get_term( (int) $aid, (string) $obj->taxonomy );
				if ( $a && ! is_wp_error( $a ) && ! empty( $a->name ) ) {
					$hierarchy[] = self::safe_term_name( (string) $a->name );
				}
			}
			if ( ! empty( $obj->name ) ) {
				$hierarchy[] = self::safe_term_name( (string) $obj->name );
			}
		}
		return array(
			'taxonomy'  => self::safe_term_name( (string) $obj->taxonomy ),
			'term_id'   => (int) $obj->term_id,
			'slug'      => self::safe_term_name( (string) ( $obj->slug ?? '' ) ),
			'name'      => self::safe_term_name( (string) ( $obj->name ?? '' ) ),
			'hierarchy' => $hierarchy,
		);
	}

	/**
	 * Collect taxonomy terms attached to a single-object page (product /
	 * post / page / `other_singular`). Returns up to 10 terms total across
	 * all taxonomies, in WP's natural authoring order. WC attribute
	 * taxonomies (`pa_*`) are excluded by default; tenants can re-enable
	 * high-signal ones (e.g. `pa_brand`) via the
	 * `def_core_page_context_allowed_attribute_taxonomies` filter.
	 *
	 * @param int $post_id Post / product / page ID.
	 * @return array<int, array{taxonomy: string, term_id: int, slug: string, name: string}>
	 */
	public static function collect_page_terms( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return array();
		}
		// Only single-object pages have terms. Archive / cart / search etc.
		// return empty.
		$page_type = self::detect_page_type();
		if ( ! in_array( $page_type, array( 'product', 'post', 'page', 'other_singular' ), true ) ) {
			return array();
		}
		if ( ! function_exists( 'get_post_type' ) || ! function_exists( 'get_object_taxonomies' )
			|| ! function_exists( 'wp_get_object_terms' ) ) {
			return array();
		}
		$post_type = get_post_type( $post_id );
		if ( ! $post_type ) {
			return array();
		}
		$all_taxonomies = get_object_taxonomies( $post_type );
		if ( empty( $all_taxonomies ) ) {
			return array();
		}
		// pa_* allowlist filter — same shape as Sub-PR 0.1.a's
		// `def_core_page_context_allowed_attribute_taxonomies` hook in the
		// export endpoint.
		$allowed_pa = apply_filters( 'def_core_page_context_allowed_attribute_taxonomies', array() );
		$allowed_pa = is_array( $allowed_pa ) ? array_map( 'strval', $allowed_pa ) : array();

		$taxonomies = array();
		foreach ( $all_taxonomies as $tax_name ) {
			$tax_name = (string) $tax_name;
			if ( strpos( $tax_name, 'pa_' ) === 0 && ! in_array( $tax_name, $allowed_pa, true ) ) {
				continue;
			}
			// Defense: only public taxonomies (Sub-PR 0.1.a security review).
			$tax_obj = function_exists( 'get_taxonomy' ) ? get_taxonomy( $tax_name ) : null;
			if ( $tax_obj && empty( $tax_obj->public ) ) {
				continue;
			}
			$taxonomies[] = $tax_name;
		}
		if ( empty( $taxonomies ) ) {
			return array();
		}
		$terms = wp_get_object_terms( $post_id, $taxonomies );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) ) {
				continue;
			}
			$out[] = array(
				'taxonomy' => self::safe_term_name( (string) ( $term->taxonomy ?? '' ) ),
				'term_id'  => (int) ( $term->term_id ?? 0 ),
				'slug'     => self::safe_term_name( (string) ( $term->slug ?? '' ) ),
				'name'     => self::safe_term_name( (string) ( $term->name ?? '' ) ),
			);
			if ( count( $out ) >= self::TERMS_CAP ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Sanitised document title. HTML-stripped, length-capped at 200 chars.
	 * Falls back to bloginfo('name') if wp_get_document_title is unavailable.
	 */
	public static function safe_title(): string {
		$title = function_exists( 'wp_get_document_title' )
			? (string) wp_get_document_title()
			: (string) get_bloginfo( 'name' );
		$title = wp_strip_all_tags( $title );
		// Strip control characters (defensive).
		$title = preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $title );
		$title = trim( (string) $title );
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $title, 0, 200 );
		}
		return substr( $title, 0, 200 );
	}

	/**
	 * Best-effort WP-locale → BCP-47 mapping. Strips region for the common
	 * cases (en_US -> en, de_DE -> de) and special-cases zh_CN / zh_TW.
	 */
	public static function locale_to_bcp47( string $locale ): string {
		$locale = trim( $locale );
		if ( $locale === '' ) {
			return 'en';
		}
		// Special-case Chinese (script-tagged form).
		$lc = strtolower( $locale );
		if ( $lc === 'zh_cn' || $lc === 'zh-cn' ) {
			return 'zh-Hans';
		}
		if ( $lc === 'zh_tw' || $lc === 'zh-tw' ) {
			return 'zh-Hant';
		}
		// Take the first segment of language_REGION.
		$parts = preg_split( '/[_-]/', $locale );
		if ( ! empty( $parts[0] ) ) {
			return strtolower( $parts[0] );
		}
		return 'en';
	}

	/**
	 * BCP-47 sanitisation — alphanumerics + hyphens, length-capped at 16.
	 */
	public static function sanitize_language_code( string $value ): string {
		$value = preg_replace( '/[^A-Za-z0-9\-]/', '', $value );
		return substr( (string) $value, 0, 16 );
	}

	/**
	 * Term-string sanitisation — strip tags + control chars, length-cap.
	 */
	public static function safe_term_name( string $value ): string {
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', (string) $value );
		$value = trim( (string) $value );
		return substr( $value, 0, 100 );
	}

	/**
	 * Normalise a URL path — trim trailing slash (except root), strip
	 * control chars. NOT used at server-render time (PHP doesn't have the
	 * browser-actual path) — exposed for the JS module to use as a shared
	 * contract, AND for tests.
	 */
	public static function normalise_path( string $path ): string {
		if ( $path === '' ) {
			return '/';
		}
		$path = preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $path );
		if ( $path === '/' ) {
			return '/';
		}
		return rtrim( (string) $path, '/' ) ?: '/';
	}
}
