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
				),
			)
		);
	}

	/**
	 * Permission check — Bearer API key authentication.
	 *
	 * @return bool|\WP_Error True if authenticated, WP_Error otherwise.
	 */
	public static function permission_check() {
		$auth = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( isset( $_SERVER['Authorization'] ) ) {
			$auth = sanitize_text_field( wp_unslash( $_SERVER['Authorization'] ) );
		}

		if ( empty( $auth ) || stripos( $auth, 'bearer ' ) !== 0 ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Bearer token required.', 'digital-employees' ),
				array( 'status' => 401 )
			);
		}

		$token      = trim( substr( $auth, 7 ) );
		$stored_key = DEF_Core_Encryption::get_secret( 'def_core_api_key' );

		if ( empty( $stored_key ) || ! hash_equals( $stored_key, $token ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Invalid API key.', 'digital-employees' ),
				array( 'status' => 401 )
			);
		}

		return true;
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
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$filter_type = sanitize_text_field( $request->get_param( 'post_type' ) );

		// Get public post types.
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		unset( $post_types['attachment'] );

		if ( ! empty( $filter_type ) && isset( $post_types[ $filter_type ] ) ) {
			$post_types = array( $filter_type );
		} else {
			$post_types = array_values( $post_types );
		}

		$query = new \WP_Query( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		$items = array();
		foreach ( $query->posts as $post ) {
			$content = wp_strip_all_tags( $post->post_content );
			$content = preg_replace( '/\s+/', ' ', $content );

			$items[] = array(
				'id'            => $post->ID,
				'type'          => $post->post_type,
				'title'         => $post->post_title,
				'url'           => get_permalink( $post->ID ),
				'content'       => trim( $content ),
				'excerpt'       => wp_strip_all_tags( $post->post_excerpt ),
				'categories'    => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
				'tags'          => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
				'modified'      => $post->post_modified_gmt,
			);
		}

		return new \WP_REST_Response( array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'site_name'   => get_bloginfo( 'name' ),
			'site_url'    => home_url(),
		), 200 );
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

		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );

		$query = new \WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
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
				'image_url'       => wp_get_attachment_url( $product->get_image_id() ) ?: '',
				'modified'        => $post->post_modified_gmt,
			);

			// Add attributes.
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

			$items[] = $item;
		}

		return new \WP_REST_Response( array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
		), 200 );
	}
}
