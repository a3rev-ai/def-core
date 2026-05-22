<?php
/**
 * Class DEF_Core_Export
 *
 * Knowledge export endpoints for the Tenant Knowledge Ingestion Pipeline.
 * These endpoints allow the DEF backend to pull site content and product
 * catalog data for indexing into the tenant's AI knowledge base.
 *
 * Endpoints:
 * - GET /wp-json/def-core/v1/content/export — all publishable content
 * - GET /wp-json/def-core/v1/products/export — WooCommerce product catalog
 *
 * Authentication: Bearer token (def_core_api_key).
 *
 * @package digital-employees
 * @since   2.3.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_Export {

	/**
	 * Initialize export routes.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			'def-core/v1',
			'/content/export',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'callback'            => array( __CLASS__, 'export_content' ),
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 100,
					),
					'post_type' => array(
						'type'    => 'string',
						'default' => '',
					),
					'modified_after' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			'def-core/v1',
			'/products/export',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'callback'            => array( __CLASS__, 'export_products' ),
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 100,
					),
					'modified_after' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Permission check — HMAC machine-to-machine authentication.
	 *
	 * Uses the shared DEF_Core_HMAC_Auth verifier. Same auth contract
	 * as all other DEF → WordPress machine calls (a3-ai/v1 routes).
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool|\WP_Error True if authenticated, WP_Error otherwise.
	 * @since 1.6.1
	 */
	public static function permission_check( \WP_REST_Request $request ) {
		return \A3Rev\DefCore\DEF_Core_HMAC_Auth::permission_check_machine( $request );
	}

	/**
	 * Export publishable site content.
	 *
	 * Returns pages, posts, and public custom post types in a structured
	 * format suitable for indexing into Azure AI Search.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response The response.
	 */
	public static function export_content( \WP_REST_Request $request ): \WP_REST_Response {
		$page           = $request->get_param( 'page' );
		$per_page       = $request->get_param( 'per_page' );
		$filter_type    = sanitize_text_field( $request->get_param( 'post_type' ) );
		$modified_after = sanitize_text_field( $request->get_param( 'modified_after' ) );
		$request_id     = $request->get_header( 'X-DEF-Request-ID' ) ?: '';

		$content_type = ! empty( $filter_type ) ? $filter_type : 'mixed';
		DEF_Core_Logger::info( DEF_Core_Logger::SOURCE_SYNC, 'Export request received', array(
			'content_type'   => $content_type,
			'page'           => $page,
			'per_page'       => $per_page,
			'modified_after' => $modified_after,
			'request_id'     => $request_id,
		) );

		// Get exportable post types (excludes system types).
		$post_types = class_exists( 'DEF_Core_Knowledge_Export' )
			? DEF_Core_Knowledge_Export::get_exported_post_types()
			: array_values( array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) ) );

		if ( ! empty( $filter_type ) && in_array( $filter_type, $post_types, true ) ) {
			$post_types = array( $filter_type );
		}

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'has_password'   => false, // Exclude password-protected posts.
		);

		// Incremental sync: only fetch content modified after timestamp.
		if ( ! empty( $modified_after ) ) {
			$query_args['date_query'] = array(
				array(
					'after'  => $modified_after,
					'column' => 'post_modified_gmt',
				),
			);
		}

		$query = new \WP_Query( $query_args );

		DEF_Core_Logger::debug( DEF_Core_Logger::SOURCE_SYNC, 'WP_Query executed', array(
			'content_type'           => $content_type,
			'requested_per_page'     => $per_page,
			'actual_posts_per_page'  => $query->query_vars['posts_per_page'],
			'found_posts'            => (int) $query->found_posts,
			'post_count'             => $query->post_count,
			'max_num_pages'          => (int) $query->max_num_pages,
			'sql'                    => substr( $query->request, 0, 2000 ),
			'per_page_modified'      => (int) $per_page !== (int) $query->query_vars['posts_per_page'],
			'request_id'             => $request_id,
		) );

		$items = array();
		foreach ( $query->posts as $post ) {
			$content = wp_strip_all_tags( $post->post_content );
			$content = preg_replace( '/\s+/', ' ', $content );

			// Parent page title for hierarchical context.
			$parent_title = '';
			if ( $post->post_parent > 0 ) {
				$parent = get_post( $post->post_parent );
				if ( $parent ) {
					$parent_title = $parent->post_title;
				}
			}

			$item = array(
				'id'            => $post->ID,
				'type'          => $post->post_type,
				'title'         => $post->post_title,
				'url'           => get_permalink( $post->ID ),
				'content'       => trim( $content ),
				'excerpt'       => wp_strip_all_tags( $post->post_excerpt ),
				'author'        => get_the_author_meta( 'display_name', $post->post_author ),
				'date'          => $post->post_date_gmt,
				'categories'    => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
				'tags'          => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
				// V1.1 platform-agnostic taxonomy emission (Page Context Build Plan
				// Sub-PR 0.1.a). DEF-side mapper (Sub-PR 0.1.b) consumes this in
				// preference to the WP-named `categories`/`tags` fields above; old
				// fields retained for one release for backward compatibility.
				'taxonomies'    => self::collect_taxonomy_terms( $post->ID ),
				'modified'      => $post->post_modified_gmt,
				'parent_title'  => $parent_title,
			);

			// Attachments, meta, and embedded document links.
			if ( class_exists( 'DEF_Core_Knowledge_Export' ) ) {
				$item['attachments']             = DEF_Core_Knowledge_Export::get_post_attachments( $post->ID );
				$item['meta']                    = DEF_Core_Knowledge_Export::get_post_meta_filtered( $post->ID );
				$item['embedded_document_links'] = DEF_Core_Knowledge_Export::get_embedded_document_links( $post->post_content );
			}

			$items[] = $item;
		}

		$total_items = (int) $query->found_posts;
		$total_pages = (int) $query->max_num_pages;

		DEF_Core_Logger::info( DEF_Core_Logger::SOURCE_SYNC, 'Export response', array(
			'content_type'   => $content_type,
			'items_returned' => count( $items ),
			'total_items'    => $total_items,
			'total_pages'    => $total_pages,
			'page'           => $page,
			'request_id'     => $request_id,
		) );

		$response = new \WP_REST_Response( array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total_items,
			'total_pages' => $total_pages,
			'site_name'   => get_bloginfo( 'name' ),
			'site_url'    => home_url(),
		), 200 );

		if ( $request_id ) {
			$response->header( 'X-DEF-Request-ID', $request_id );
		}

		return $response;
	}

	/**
	 * Export WooCommerce product catalog.
	 *
	 * Returns products with pricing, stock, categories, and attributes
	 * in a structured format suitable for indexing.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response The response.
	 */
	public static function export_products( \WP_REST_Request $request ): \WP_REST_Response {
		$request_id = $request->get_header( 'X-DEF-Request-ID' ) ?: '';

		if ( ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) ) {
			return new \WP_REST_Response( array(
				'items'       => array(),
				'page'        => 1,
				'per_page'    => 0,
				'total'       => 0,
				'total_pages' => 0,
				'message'     => 'WooCommerce is not active.',
			), 200 );
		}

		$page           = $request->get_param( 'page' );
		$per_page       = $request->get_param( 'per_page' );
		$modified_after = sanitize_text_field( $request->get_param( 'modified_after' ) );

		DEF_Core_Logger::info( DEF_Core_Logger::SOURCE_SYNC, 'Export request received', array(
			'content_type'   => 'product',
			'page'           => $page,
			'per_page'       => $per_page,
			'modified_after' => $modified_after,
			'request_id'     => $request_id,
		) );

		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'has_password'   => false,
		);

		if ( ! empty( $modified_after ) ) {
			$query_args['date_query'] = array(
				array(
					'after'  => $modified_after,
					'column' => 'post_modified_gmt',
				),
			);
		}

		$query = new \WP_Query( $query_args );

		DEF_Core_Logger::debug( DEF_Core_Logger::SOURCE_SYNC, 'WP_Query executed', array(
			'content_type'           => 'product',
			'requested_per_page'     => $per_page,
			'actual_posts_per_page'  => $query->query_vars['posts_per_page'],
			'found_posts'            => (int) $query->found_posts,
			'post_count'             => $query->post_count,
			'max_num_pages'          => (int) $query->max_num_pages,
			'sql'                    => substr( $query->request, 0, 2000 ),
			'per_page_modified'      => (int) $per_page !== (int) $query->query_vars['posts_per_page'],
			'request_id'             => $request_id,
		) );

		$items = array();
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}

			$item = array(
				'id'              => $product->get_id(),
				'name'            => $product->get_name(),
				'type'            => $product->get_type(),
				'url'             => $product->get_permalink(),
				'sku'             => $product->get_sku(),
				'price'           => $product->get_price(),
				'regular_price'   => $product->get_regular_price(),
				'sale_price'      => $product->get_sale_price(),
				'stock_status'    => $product->get_stock_status(),
				'short_description' => wp_strip_all_tags( $product->get_short_description() ),
				'description'     => wp_strip_all_tags( $product->get_description() ),
				'categories'      => wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'names' ) ),
				'tags'            => wp_get_post_terms( $post->ID, 'product_tag', array( 'fields' => 'names' ) ),
				// V1.1 platform-agnostic taxonomy emission (Page Context Build Plan
				// Sub-PR 0.1.a). Carries product_cat + product_tag + any custom
				// taxonomies (brand plugins, attributes, etc.) as full
				// {taxonomy, term_id, slug, name} tuples for the DEF-side mapper.
				'taxonomies'      => self::collect_taxonomy_terms( $post->ID ),
				'modified'        => $post->post_modified_gmt,
			);

			// Brand taxonomy (common plugin: Perfect Brands for WooCommerce).
			$brands = wp_get_post_terms( $post->ID, 'pwb-brand', array( 'fields' => 'names' ) );
			if ( is_wp_error( $brands ) ) {
				// Try alternate taxonomy name.
				$brands = wp_get_post_terms( $post->ID, 'product_brand', array( 'fields' => 'names' ) );
			}
			$item['brand'] = ( ! is_wp_error( $brands ) && ! empty( $brands ) ) ? $brands[0] : '';

			// Attributes.
			$attributes = array();
			foreach ( $product->get_attributes() as $attr ) {
				if ( is_a( $attr, 'WC_Product_Attribute' ) ) {
					$attributes[] = array(
						'name'    => wc_attribute_label( $attr->get_name() ),
						'values'  => $attr->get_options(),
					);
				}
			}
			$item['attributes'] = $attributes;

			// Variations (for variable products).
			$variations = array();
			if ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) {
				$variation_ids = $product->get_children();
				foreach ( $variation_ids as $var_id ) {
					$variation = wc_get_product( $var_id );
					if ( ! $variation || 'publish' !== get_post_status( $var_id ) ) {
						continue;
					}
					$var_attrs = array();
					foreach ( $variation->get_variation_attributes() as $attr_key => $attr_val ) {
						$var_attrs[] = array(
							'name'  => wc_attribute_label( str_replace( 'attribute_', '', $attr_key ) ),
							'value' => $attr_val,
						);
					}
					$variations[] = array(
						'id'           => $var_id,
						'sku'          => $variation->get_sku(),
						'price'        => $variation->get_price(),
						'regular_price' => $variation->get_regular_price(),
						'sale_price'   => $variation->get_sale_price(),
						'stock_status' => $variation->get_stock_status(),
						'attributes'   => $var_attrs,
					);
				}
			}
			$item['variations'] = $variations;

			// Attachments, meta, and embedded document links.
			if ( class_exists( 'DEF_Core_Knowledge_Export' ) ) {
				$raw_description = $product->get_description();
				$item['attachments']             = DEF_Core_Knowledge_Export::get_post_attachments( $post->ID );
				$item['meta']                    = DEF_Core_Knowledge_Export::get_post_meta_filtered( $post->ID );
				$item['embedded_document_links'] = DEF_Core_Knowledge_Export::get_embedded_document_links( $raw_description );
			}

			$items[] = $item;
		}

		$total_items = (int) $query->found_posts;
		$total_pages = (int) $query->max_num_pages;

		DEF_Core_Logger::info( DEF_Core_Logger::SOURCE_SYNC, 'Export response', array(
			'content_type'   => 'product',
			'items_returned' => count( $items ),
			'total_items'    => $total_items,
			'total_pages'    => $total_pages,
			'page'           => $page,
			'request_id'     => $request_id,
		) );

		$response = new \WP_REST_Response( array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total_items,
			'total_pages' => $total_pages,
			'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
		), 200 );

		if ( $request_id ) {
			$response->header( 'X-DEF-Request-ID', $request_id );
		}

		return $response;
	}

	/**
	 * Collect taxonomy terms attached to a post in a platform-agnostic shape.
	 *
	 * Enumerates ALL taxonomies attached to the post's type (via
	 * get_object_taxonomies()) and returns full {taxonomy, term_id, slug, name}
	 * tuples for each term. Used by the DEF-side content mapper to populate the
	 * platform-agnostic Azure Search taxonomy_terms field (Page Context Build
	 * Plan V1.1, Sub-PR 0.1.b).
	 *
	 * Emits all taxonomies including WC attribute taxonomies (pa_*) — the search
	 * index benefits from attribute terms (a visitor filtering for "blue
	 * products" can match pa_color: blue). Joe's prompt-context layer
	 * (Sub-PR C, def_core_collect_page_terms()) does its own pa_* filtering for
	 * token-noise reasons; that concern is orthogonal to indexing.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array{taxonomy: string, term_id: int, slug: string, name: string}>
	 */
	public static function collect_taxonomy_terms( int $post_id ): array {
		$post_type = get_post_type( $post_id );
		if ( ! $post_type ) {
			return array();
		}
		// Public taxonomies only. Filtering by public=true at the registry level
		// drops admin-internal taxonomies (e.g. `nav_menu`, `link_category`,
		// `wp_pattern_category`, and any tenant-registered private categorisation
		// such as `internal-tag`, `do-not-quote`, etc.). These would otherwise
		// flow into DEF's search index where Joe could surface them in
		// customer-facing conversation — addressed pre-merge per converged code
		// + security review on PR #177.
		$all_taxonomies = get_object_taxonomies( $post_type );
		$taxonomies     = array_filter(
			$all_taxonomies,
			static function ( $tax_name ) {
				$tax = get_taxonomy( $tax_name );
				return $tax && ! empty( $tax->public );
			}
		);
		if ( empty( $taxonomies ) ) {
			return array();
		}
		$terms = wp_get_object_terms( $post_id, $taxonomies );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			$out[] = array(
				'taxonomy' => $term->taxonomy,
				'term_id'  => (int) $term->term_id,
				'slug'     => $term->slug,
				'name'     => $term->name,
			);
		}
		return $out;
	}
}
