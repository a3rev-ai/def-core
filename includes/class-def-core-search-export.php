<?php
/**
 * Class DEF_Core_Search_Export
 *
 * Search-index export endpoint for the DEF Search Tool. Serves a metadata-shaped
 * feed — one object per product / post / page / CPT / taxonomy term — that the
 * DEFHO tenant platform pulls and DEF indexes into the deterministic `search`
 * index. Distinct from the content-heavy knowledge/chunk export (DEF_Core_Export).
 *
 * Endpoint:
 * - GET /wp-json/def-core/v1/search/export?type=<post_type|taxonomy>&page&per_page&modified_after
 *   `type` = a taxonomy (product_cat / post_tag / product_brand / category …)
 *   returns term rows; any other value (or empty) returns item rows for that post
 *   type (or all exported post types).
 *
 * Rides the EXISTING integration path — no new mechanism:
 * - incremental pull via `modified_after`,
 * - `_def_exclude_from_ingestion` exclusion (enforced HERE at the source),
 * - deletions via the existing `before_delete_post` tracking + /content/deleted
 *   (DEF_Core_Knowledge_Export) — the search index reuses that feed.
 *
 * `tenant_id` is NOT emitted — def-core is a single site and doesn't know its DEF
 * tenant id; the puller (DEFHO / DEF) stamps it from the authenticated connection.
 *
 * Auth: HMAC machine-to-machine (DEF_Core_HMAC_Auth) — same as the other exports.
 *
 * @package digital-employees
 * @since   3.5.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_Search_Export {

	/** Post meta flag: exclude from DEF ingestion (knowledge + search). Stored '1'. */
	const EXCLUDE_META = '_def_exclude_from_ingestion';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	public static function register_rest_routes(): void {
		register_rest_route(
			'def-core/v1',
			'/search/export',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'callback'            => array( __CLASS__, 'export_search' ),
				'args'                => array(
					'type'           => array( 'type' => 'string', 'default' => '' ),
					'page'           => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page'       => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ),
					'modified_after' => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
	}

	public static function permission_check( \WP_REST_Request $request ) {
		return \A3Rev\DefCore\DEF_Core_HMAC_Auth::permission_check_machine( $request );
	}

	/**
	 * Export one page of search objects (term rows if `type` is a taxonomy,
	 * otherwise item rows for that post type / all exported post types).
	 */
	public static function export_search( \WP_REST_Request $request ): \WP_REST_Response {
		$type           = sanitize_text_field( (string) $request->get_param( 'type' ) );
		$page           = (int) $request->get_param( 'page' );
		$per_page       = (int) $request->get_param( 'per_page' );
		$modified_after = sanitize_text_field( (string) $request->get_param( 'modified_after' ) );

		if ( '' !== $type && taxonomy_exists( $type ) ) {
			return self::export_terms( $type, $page, $per_page );
		}
		return self::export_items( $type, $page, $per_page, $modified_after );
	}

	// ── Item rows (products / posts / pages / CPTs) ──────────────────────

	private static function export_items( string $post_type, int $page, int $per_page, string $modified_after ): \WP_REST_Response {
		$post_types = class_exists( 'DEF_Core_Knowledge_Export' )
			? DEF_Core_Knowledge_Export::get_exported_post_types()
			: array_values( array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) ) );

		if ( '' !== $post_type && in_array( $post_type, $post_types, true ) ) {
			$post_types = array( $post_type );
		}

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'has_password'   => false,
			// Exclusion at the source: never emit items flagged out of DEF ingestion
			// ('1' = excluded; absent or '' = included).
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => self::EXCLUDE_META, 'compare' => 'NOT EXISTS' ),
				array( 'key' => self::EXCLUDE_META, 'value' => '1', 'compare' => '!=' ),
			),
		);
		if ( '' !== $modified_after ) {
			$query_args['date_query'] = array(
				array( 'after' => $modified_after, 'column' => 'post_modified_gmt' ),
			);
		}

		$query = new \WP_Query( $query_args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::build_item( $post );
		}

		return self::response( 'item', $items, $page, $per_page, (int) $query->found_posts, (int) $query->max_num_pages );
	}

	private static function build_item( \WP_Post $post ): array {
		$taxonomy_terms = DEF_Core_Export::collect_taxonomy_terms( $post->ID );
		$names          = array();
		foreach ( $taxonomy_terms as $t ) {
			$names[] = $t['name'];
		}

		$obj = array(
			'object_type'    => $post->post_type,
			'source_id'      => (string) $post->ID,
			'title'          => $post->post_title,
			'permalink'      => (string) get_permalink( $post->ID ),
			'taxonomy_names' => array_values( array_unique( $names ) ),
			'taxonomy_terms' => $taxonomy_terms,
			'focus_keywords' => self::focus_keywords( $post->ID ),
		);

		if ( 'product' === $post->post_type && ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				$obj = array_merge( $obj, self::product_fields( $product ) );
			}
		}

		// Drop null / empty values — parity with the contract's exclude_none ingest
		// (keeps false / 0 so on_sale=false and price=0 survive).
		return array_filter(
			$obj,
			static function ( $v ) {
				return null !== $v && '' !== $v && array() !== $v;
			}
		);
	}

	private static function product_fields( $product ): array {
		$skus = array();
		if ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) {
			foreach ( $product->get_children() as $var_id ) {
				$variation = wc_get_product( $var_id );
				if ( $variation && 'publish' === get_post_status( $var_id ) && '' !== (string) $variation->get_sku() ) {
					$skus[] = $variation->get_sku();
				}
			}
		}

		$attributes = array();
		$values     = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! is_a( $attr, 'WC_Product_Attribute' ) ) {
				continue;
			}
			$name = wc_attribute_label( $attr->get_name() );
			if ( $attr->is_taxonomy() ) {
				$opts = wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) );
				$opts = is_wp_error( $opts ) ? array() : $opts;
			} else {
				$opts = $attr->get_options();
			}
			foreach ( $opts as $opt ) {
				$opt          = (string) $opt;
				$attributes[] = array( 'name' => $name, 'value' => $opt, 'slug' => sanitize_title( $opt ) );
				$values[]     = $opt;
			}
		}

		return array(
			'sku'             => (string) $product->get_sku(),
			'variation_skus'  => array_values( array_unique( $skus ) ),
			'price'           => self::to_float( $product->get_price() ),
			'sale_price'      => self::to_float( $product->get_sale_price() ),
			'on_sale'         => (bool) $product->is_on_sale(),
			'stock_status'    => (string) $product->get_stock_status(),
			'attributes'      => $attributes,
			'attributes_text' => trim( implode( ' ', $values ) ),
		);
	}

	// ── Term rows (categories / tags / brands as their own objects) ──────

	private static function export_terms( string $taxonomy, int $page, int $per_page ): \WP_REST_Response {
		$total = (int) wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => $per_page,
				'offset'     => ( $page - 1 ) * $per_page,
				'orderby'    => 'id',
			)
		);

		$items = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$link    = get_term_link( $term );
				$items[] = array(
					'object_type'  => $taxonomy,
					'source_id'    => (string) $term->term_id,
					'title'        => $term->name,
					'permalink'    => is_wp_error( $link ) ? '' : (string) $link,
					'object_count' => (int) $term->count,
				);
			}
		}

		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;
		return self::response( 'term', $items, $page, $per_page, $total, $total_pages );
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Focus keywords from common SEO plugins (Yoast + legacy AIOSEO postmeta).
	 * AIOSEO v4 stores keywords in its own table — a future enhancement.
	 */
	private static function focus_keywords( int $post_id ): array {
		$keywords = array();
		$yoast    = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
		if ( is_string( $yoast ) && '' !== $yoast ) {
			$keywords[] = $yoast;
		}
		$aioseo = get_post_meta( $post_id, '_aioseop_keywords', true );
		if ( is_string( $aioseo ) && '' !== $aioseo ) {
			foreach ( explode( ',', $aioseo ) as $kw ) {
				$kw = trim( $kw );
				if ( '' !== $kw ) {
					$keywords[] = $kw;
				}
			}
		}
		return array_values( array_unique( $keywords ) );
	}

	private static function to_float( $value ): ?float {
		$text = is_string( $value ) ? trim( $value ) : $value;
		if ( null === $text || '' === $text || ! is_numeric( $text ) ) {
			return null;
		}
		return (float) $text;
	}

	private static function response( string $object_kind, array $items, int $page, int $per_page, int $total, int $total_pages ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'object_kind' => $object_kind,
				'items'       => $items,
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total,
				'total_pages' => $total_pages,
				'site_url'    => home_url(),
			),
			200
		);
	}
}
