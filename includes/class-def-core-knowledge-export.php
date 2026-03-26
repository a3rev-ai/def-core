<?php
/**
 * Class DEF_Core_Knowledge_Export
 *
 * Extended knowledge export endpoints for the WordPress Sync Pipeline.
 * Provides content counts, deletion/transition tracking, bbPress forum export,
 * authenticated attachment downloads, and sync status display.
 *
 * Endpoints:
 * - GET /wp-json/def-core/v1/content/counts   — content type counts + CPT discovery
 * - GET /wp-json/def-core/v1/content/deleted   — deleted/trashed IDs + status transitions
 * - GET /wp-json/def-core/v1/forums/export     — bbPress topics + replies with forum context
 * - GET /wp-json/def-core/v1/attachments/download/{id} — authenticated file download
 *
 * Authentication: Bearer token (def_core_api_key) — same as DEF_Core_Export.
 *
 * Architecture Spec: WordPress-Bulk-Ingestion-Spec-V1.2
 * Build Plan: WordPress-Bulk-Ingestion-Build-Plan-V1.1 §Sub-PR A
 *
 * @package digital-employees
 * @since   1.6.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_Knowledge_Export {

	/**
	 * Meta field denylist prefixes — these are internal/system fields that
	 * should NOT be exported as searchable knowledge content.
	 *
	 * @var array
	 */
	private static $meta_denylist_prefixes = array(
		'_wp_', '_edit_', '_oembed_',              // WordPress internals
		'_yoast_', '_rss_', '_aioseo_',            // SEO plugins
		'_et_pb_', '_actived_', '_wc_dgallery_',   // Theme/display plugins
		'_transient_', '_encloseme', '_pingme',     // System
		'_aipkit_',                                 // AIPKit (experimental)
		'_thumbnail_id',                            // Featured image ID
	);

	/**
	 * Post types to exclude from CPT discovery and status tracking.
	 *
	 * @var array
	 */
	private static $excluded_post_types = array(
		'attachment', 'nav_menu_item', 'wp_block', 'wp_template',
		'wp_template_part', 'wp_navigation', 'wp_global_styles',
		'custom_css', 'shop_order', 'shop_coupon', 'shop_order_refund',
		'product_variation', 'revision', 'oembed_cache',
		'customize_changeset', 'user_request', 'wp_font_family',
		'wp_font_face',
	);

	/**
	 * Initialize hooks and routes.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		// Status transition tracking hooks (priority 15 — after other plugins).
		add_action( 'before_delete_post', array( __CLASS__, 'track_permanent_delete' ), 15 );
		add_action( 'transition_post_status', array( __CLASS__, 'track_status_transition' ), 15, 3 );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			'def-core/v1',
			'/content/counts',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( 'DEF_Core_Export', 'permission_check' ),
				'callback'            => array( __CLASS__, 'content_counts' ),
			)
		);

		register_rest_route(
			'def-core/v1',
			'/content/deleted',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( 'DEF_Core_Export', 'permission_check' ),
				'callback'            => array( __CLASS__, 'content_deleted' ),
				'args'                => array(
					'since' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			'def-core/v1',
			'/forums/export',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( 'DEF_Core_Export', 'permission_check' ),
				'callback'            => array( __CLASS__, 'forums_export' ),
				'args'                => array(
					'page'           => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page'       => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 50 ),
					'modified_after' => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);

		register_rest_route(
			'def-core/v1',
			'/attachments/download/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( 'DEF_Core_Export', 'permission_check' ),
				'callback'            => array( __CLASS__, 'attachment_download' ),
				'args'                => array(
					'id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);

		register_rest_route(
			'def-core/v1',
			'/attachments/resolve',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( 'DEF_Core_Export', 'permission_check' ),
				'callback'            => array( __CLASS__, 'attachment_resolve' ),
				'args'                => array(
					'slug' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
	}

	// =========================================================================
	// Endpoint: /content/counts
	// =========================================================================

	/**
	 * Return content counts per post type including CPT discovery.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public static function content_counts( \WP_REST_Request $request ): \WP_REST_Response {
		$exported_types = self::get_exported_post_types();

		$counts = array();
		foreach ( $exported_types as $type ) {
			$count_obj = wp_count_posts( $type );
			$count     = 0;
			if ( $count_obj ) {
				$count = (int) ( $count_obj->publish ?? 0 );
				// bbPress topics: include closed (resolved).
				if ( 'topic' === $type && isset( $count_obj->closed ) ) {
					$count += (int) $count_obj->closed;
				}
			}
			if ( $count > 0 ) {
				$counts[ $type ] = $count;
			}
		}

		// Attachment counts by MIME type (documents only, not images).
		$attachment_counts = self::get_document_attachment_counts();

		// CPT discovery.
		$builtin = array( 'page', 'post', 'product', 'forum', 'topic', 'reply' );
		$cpts    = array_diff( $exported_types, $builtin );

		return new \WP_REST_Response( array(
			'counts'                => $counts,
			'attachment_counts'     => $attachment_counts,
			'estimated_size_mb'     => 0.0, // Placeholder — actual size requires content scan.
			'site_name'             => get_bloginfo( 'name' ),
			'site_url'              => home_url(),
			'woocommerce_active'    => class_exists( 'WooCommerce' ) || function_exists( 'WC' ),
			'bbpress_active'        => class_exists( 'bbPress' ) || function_exists( 'bbpress' ),
			'registered_public_cpts' => array_values( $cpts ),
		), 200 );
	}

	// =========================================================================
	// Endpoint: /content/deleted
	// =========================================================================

	/**
	 * Return IDs of deleted/trashed content and status transitions since a timestamp.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public static function content_deleted( \WP_REST_Request $request ): \WP_REST_Response {
		$since = sanitize_text_field( $request->get_param( 'since' ) );

		// Permanent deletes (tracked via before_delete_post hook).
		$deleted_tracked = get_option( 'def_core_deleted_posts', array() );
		$deleted_ids     = array();
		foreach ( $deleted_tracked as $entry ) {
			if ( strtotime( $entry['time'] ) >= strtotime( $since ) ) {
				$deleted_ids[] = array(
					'id'   => (int) $entry['id'],
					'type' => $entry['type'] ?? 'unknown',
				);
			}
		}

		// Trashed posts (still in DB with status = 'trash').
		$trashed_query = new \WP_Query( array(
			'post_type'      => self::get_exported_post_types(),
			'post_status'    => 'trash',
			'posts_per_page' => 500,
			'date_query'     => array(
				array(
					'after'  => $since,
					'column' => 'post_modified_gmt',
				),
			),
			'fields'         => 'ids',
		) );
		$trashed_ids = array_map( 'intval', $trashed_query->posts );

		// Status transitions (tracked via transition_post_status hook).
		$transitions_tracked = get_option( 'def_core_status_transitions', array() );
		$status_changes      = array();
		foreach ( $transitions_tracked as $entry ) {
			if ( strtotime( $entry['time'] ) >= strtotime( $since ) ) {
				$status_changes[] = array(
					'id'   => (int) $entry['id'],
					'type' => $entry['type'] ?? 'unknown',
					'from' => $entry['from'],
					'to'   => $entry['to'],
				);
			}
		}

		return new \WP_REST_Response( array(
			'deleted_ids'    => $deleted_ids,
			'trashed_ids'    => $trashed_ids,
			'status_changes' => $status_changes,
			'since'          => $since,
		), 200 );
	}

	// =========================================================================
	// Endpoint: /forums/export
	// =========================================================================

	/**
	 * Export bbPress forum topics with replies and forum context.
	 *
	 * 3-level hierarchy: forum → topic → replies.
	 * Topics with status 'publish' or 'closed' (resolved) are included.
	 * Spam, pending, and trash topics/replies are excluded.
	 * Private replies (_bbp_reply_is_private) are excluded.
	 * Private/hidden forums are excluded with all their content.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public static function forums_export( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! class_exists( 'bbPress' ) && ! function_exists( 'bbpress' ) ) {
			return new \WP_REST_Response( array(
				'items'       => array(),
				'page'        => 1,
				'per_page'    => 0,
				'total'       => 0,
				'total_pages' => 0,
				'message'     => 'bbPress is not active.',
			), 200 );
		}

		$page           = $request->get_param( 'page' );
		$per_page        = $request->get_param( 'per_page' );
		$modified_after = sanitize_text_field( $request->get_param( 'modified_after' ) );

		// Get IDs of public forums only.
		$public_forum_ids = self::get_public_forum_ids();

		if ( empty( $public_forum_ids ) ) {
			return new \WP_REST_Response( array(
				'items' => array(), 'page' => $page, 'per_page' => $per_page,
				'total' => 0, 'total_pages' => 0,
			), 200 );
		}

		// Query topics in public forums.
		$query_args = array(
			'post_type'      => 'topic',
			'post_status'    => array( 'publish', 'closed' ),
			'post_parent__in' => $public_forum_ids,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
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

		$items = array();
		foreach ( $query->posts as $topic ) {
			// Get forum name.
			$forum_id   = (int) $topic->post_parent;
			$forum_post = get_post( $forum_id );
			$forum_name = $forum_post ? $forum_post->post_title : '';

			// Get topic tags.
			$topic_tags = array();
			if ( taxonomy_exists( 'topic-tag' ) ) {
				$tags = wp_get_post_terms( $topic->ID, 'topic-tag', array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $tags ) ) {
					$topic_tags = $tags;
				}
			}

			// Get replies (exclude spam, pending, trash, and private replies).
			$replies      = self::get_topic_replies( $topic->ID );
			$topic_author = get_the_author_meta( 'display_name', $topic->post_author );

			$items[] = array(
				'topic_id'   => $topic->ID,
				'title'      => $topic->post_title,
				'forum_name' => $forum_name,
				'forum_id'   => $forum_id,
				'status'     => $topic->post_status,
				'author'     => $topic_author,
				'date'       => $topic->post_date_gmt,
				'tags'       => $topic_tags,
				'content'    => trim( wp_strip_all_tags( $topic->post_content ) ),
				'replies'    => $replies,
				'modified'   => $topic->post_modified_gmt,
			);
		}

		return new \WP_REST_Response( array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		), 200 );
	}

	// =========================================================================
	// Endpoint: /attachments/download/{id}
	// =========================================================================

	/**
	 * Download an attachment file via authenticated endpoint.
	 *
	 * DEF never fetches raw WordPress media URLs. All attachment downloads
	 * go through this endpoint for authentication and validation.
	 *
	 * Only document files are served (PDF, DOCX, DOC, XLSX, CSV, TXT).
	 * Image files are rejected.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function attachment_download( \WP_REST_Request $request ) {
		$attachment_id = (int) $request->get_param( 'id' );

		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'not_found', __( 'Attachment not found.', 'digital-employees' ), array( 'status' => 404 ) );
		}

		// Validate full parent export eligibility (ChatGPT R1+R2 blockers).
		$parent_id = (int) $post->post_parent;
		if ( $parent_id > 0 ) {
			$parent = get_post( $parent_id );
			if ( ! $parent ) {
				return new \WP_Error( 'parent_not_found', __( 'Attachment parent not found.', 'digital-employees' ), array( 'status' => 404 ) );
			}
			$public_statuses = array( 'publish', 'closed', 'inherit' );
			if ( ! in_array( $parent->post_status, $public_statuses, true ) ) {
				return new \WP_Error( 'parent_not_public', __( 'Attachment parent is not publicly visible.', 'digital-employees' ), array( 'status' => 403 ) );
			}
			// Check parent isn't password-protected.
			if ( ! empty( $parent->post_password ) ) {
				return new \WP_Error( 'parent_protected', __( 'Attachment parent is password-protected.', 'digital-employees' ), array( 'status' => 403 ) );
			}
			// bbPress: if parent is a topic, verify its forum is public.
			if ( 'topic' === $parent->post_type && (int) $parent->post_parent > 0 ) {
				if ( ! self::is_forum_public( (int) $parent->post_parent ) ) {
					return new \WP_Error( 'forum_not_public', __( 'Attachment belongs to a private forum.', 'digital-employees' ), array( 'status' => 403 ) );
				}
			}
			// bbPress: if parent is a reply, check it's not private.
			if ( 'reply' === $parent->post_type ) {
				$is_private = get_post_meta( $parent_id, '_bbp_reply_is_private', true );
				if ( '1' === $is_private || 'true' === $is_private ) {
					return new \WP_Error( 'reply_private', __( 'Attachment belongs to a private reply.', 'digital-employees' ), array( 'status' => 403 ) );
				}
				// Also check the reply's topic's forum is public.
				$topic_id = (int) $parent->post_parent;
				if ( $topic_id > 0 ) {
					$topic = get_post( $topic_id );
					if ( $topic && 'topic' === $topic->post_type && (int) $topic->post_parent > 0 ) {
						if ( ! self::is_forum_public( (int) $topic->post_parent ) ) {
							return new \WP_Error( 'forum_not_public', __( 'Attachment belongs to a private forum.', 'digital-employees' ), array( 'status' => 403 ) );
						}
					}
				}
			}
		}

		// Validate MIME type — documents only, no images.
		$allowed_mimes = array(
			'application/pdf',
			'application/msword',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'application/vnd.ms-excel',
			'text/csv',
			'text/plain',
		);

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, $allowed_mimes, true ) ) {
			return new \WP_Error(
				'invalid_type',
				__( 'Only document files (PDF, DOCX, DOC, XLSX, CSV, TXT) can be downloaded.', 'digital-employees' ),
				array( 'status' => 400 )
			);
		}

		// Get file path.
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new \WP_Error( 'file_missing', __( 'File not found on disk.', 'digital-employees' ), array( 'status' => 404 ) );
		}

		// Size check (10MB limit).
		$file_size = filesize( $file_path );
		if ( $file_size > 10 * 1024 * 1024 ) {
			return new \WP_Error( 'file_too_large', __( 'File exceeds 10MB limit.', 'digital-employees' ), array( 'status' => 400 ) );
		}

		// Serve the file.
		$filename = wp_basename( $file_path );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . $file_size );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file_path );
		exit;
	}

	// =========================================================================
	// Endpoint: /attachments/resolve
	// =========================================================================

	/**
	 * Resolve an attachment by slug (filename without extension).
	 *
	 * Proxies the WordPress core /wp/v2/media lookup through a def-core
	 * authenticated endpoint so DEF can discover attachment IDs for
	 * embedded document links without calling core WP REST directly.
	 *
	 * @param \WP_REST_Request $request Request with 'slug' parameter.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function attachment_resolve( \WP_REST_Request $request ) {
		$slug = sanitize_title( $request->get_param( 'slug' ) );

		if ( empty( $slug ) ) {
			return new \WP_Error(
				'missing_slug',
				__( 'Slug parameter is required.', 'digital-employees' ),
				array( 'status' => 400 )
			);
		}

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'name'           => $slug,
				'posts_per_page' => 1,
				'post_status'    => 'inherit',
			)
		);

		if ( empty( $attachments ) ) {
			return new \WP_REST_Response( array( 'id' => null, 'found' => false ), 200 );
		}

		$attachment = $attachments[0];

		// Validate MIME type — only document attachments, not images.
		$mime = get_post_mime_type( $attachment->ID );
		$allowed_mimes = array(
			'application/pdf',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/msword',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'text/csv',
			'text/plain',
		);
		if ( ! in_array( $mime, $allowed_mimes, true ) ) {
			return new \WP_REST_Response( array( 'id' => null, 'found' => false, 'reason' => 'not_document' ), 200 );
		}

		return new \WP_REST_Response(
			array(
				'id'        => $attachment->ID,
				'found'     => true,
				'mime_type' => $mime,
				'title'     => $attachment->post_title,
			),
			200
		);
	}

	// =========================================================================
	// Status Transition Tracking
	// =========================================================================

	/**
	 * Track permanent post deletions for delete sync.
	 *
	 * Fires on before_delete_post — records the post ID and type for
	 * later retrieval via /content/deleted endpoint.
	 *
	 * Skips revisions, autosaves, and non-exported post types.
	 *
	 * @param int $post_id The post ID being permanently deleted.
	 */
	public static function track_permanent_delete( int $post_id ): void {
		$post_type = get_post_type( $post_id );

		// Skip revisions, autosaves, and non-exported types.
		if ( ! $post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$exported_types = self::get_exported_post_types();
		if ( ! in_array( $post_type, $exported_types, true ) ) {
			return;
		}

		$tracked   = get_option( 'def_core_deleted_posts', array() );
		$tracked[] = array(
			'id'   => $post_id,
			'type' => $post_type,
			'time' => gmdate( 'c' ),
		);

		// Prune entries older than 90 days.
		$cutoff  = strtotime( '-90 days' );
		$tracked = array_filter( $tracked, function ( $e ) use ( $cutoff ) {
			return strtotime( $e['time'] ) > $cutoff;
		} );

		update_option( 'def_core_deleted_posts', array_values( $tracked ), false );
	}

	/**
	 * Track post status transitions for delete/transition sync.
	 *
	 * Fires on transition_post_status — records transitions involving
	 * public statuses (publish, closed) for later retrieval.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       The post object.
	 */
	public static function track_status_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $old_status === $new_status ) {
			return;
		}

		// Skip revisions, autosaves, and non-exported types.
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		$exported_types = self::get_exported_post_types();
		if ( ! in_array( $post->post_type, $exported_types, true ) ) {
			return;
		}

		// Only track transitions involving public statuses.
		$public_statuses = array( 'publish', 'closed' );
		if ( ! in_array( $old_status, $public_statuses, true ) && ! in_array( $new_status, $public_statuses, true ) ) {
			return;
		}

		$tracked   = get_option( 'def_core_status_transitions', array() );
		$tracked[] = array(
			'id'   => $post->ID,
			'type' => $post->post_type,
			'from' => $old_status,
			'to'   => $new_status,
			'time' => gmdate( 'c' ),
		);

		// Prune entries older than 90 days.
		$cutoff  = strtotime( '-90 days' );
		$tracked = array_filter( $tracked, function ( $e ) use ( $cutoff ) {
			return strtotime( $e['time'] ) > $cutoff;
		} );

		update_option( 'def_core_status_transitions', array_values( $tracked ), false );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Get all post types that should be exported.
	 *
	 * Includes built-in types (page, post, product, forum, topic, reply)
	 * plus any registered public CPTs, minus excluded system types.
	 *
	 * @return array List of post type slugs.
	 */
	public static function get_exported_post_types(): array {
		$all_public = get_post_types( array( 'public' => true ), 'names' );
		return array_values( array_diff( $all_public, self::$excluded_post_types ) );
	}

	/**
	 * Get IDs of public bbPress forums.
	 *
	 * Uses bbPress API helpers when available, falls back to meta inspection.
	 * Private and hidden forums are excluded — along with all their content.
	 *
	 * @return array List of public forum post IDs.
	 */
	private static function get_public_forum_ids(): array {
		$forum_query = new \WP_Query( array(
			'post_type'      => 'forum',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$public_ids = array();
		foreach ( $forum_query->posts as $forum_id ) {
			if ( self::is_forum_public( (int) $forum_id ) ) {
				$public_ids[] = (int) $forum_id;
			}
		}

		return $public_ids;
	}

	/**
	 * Check if a bbPress forum is publicly visible.
	 *
	 * Uses bbPress helper functions when available (handles internal edge cases),
	 * falls back to raw meta inspection.
	 *
	 * @param int $forum_id The forum post ID.
	 * @return bool True if the forum is public.
	 */
	private static function is_forum_public( int $forum_id ): bool {
		// Use bbPress helper if available.
		if ( function_exists( 'bbp_get_forum_visibility' ) ) {
			$visibility = bbp_get_forum_visibility( $forum_id );
			return 'publish' === $visibility;
		}

		// Fallback: raw meta inspection.
		$status = get_post_meta( $forum_id, '_bbp_status', true );
		return ! in_array( $status, array( 'private', 'hidden' ), true );
	}

	/**
	 * Get replies for a topic, excluding spam, pending, trash, and private replies.
	 *
	 * @param int $topic_id The topic post ID.
	 * @return array List of reply data arrays.
	 */
	private static function get_topic_replies( int $topic_id ): array {
		$reply_query = new \WP_Query( array(
			'post_type'   => 'reply',
			'post_parent' => $topic_id,
			'post_status' => 'publish',  // Excludes spam, pending, trash.
			'meta_query'  => array(      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => '_bbp_reply_is_private',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => '_bbp_reply_is_private',
					'value' => '0',
				),
			),
			'orderby'     => 'date',
			'order'       => 'ASC',
			'nopaging'    => true,
		) );

		$replies = array();
		foreach ( $reply_query->posts as $reply ) {
			$replies[] = array(
				'id'      => $reply->ID,
				'author'  => get_the_author_meta( 'display_name', $reply->post_author ),
				'date'    => $reply->post_date_gmt,
				'content' => trim( wp_strip_all_tags( $reply->post_content ) ),
			);
		}

		return $replies;
	}

	/**
	 * Get document attachment counts by file extension.
	 *
	 * Only counts document MIME types (PDF, DOCX, DOC, XLSX, CSV, TXT).
	 * Image attachments are excluded.
	 *
	 * @return array Counts keyed by extension: {"pdf": 122, "docx": 32, ...}
	 */
	private static function get_document_attachment_counts(): array {
		$mime_map = array(
			'pdf'  => 'application/pdf',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'doc'  => 'application/msword',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'csv'  => 'text/csv',
			'txt'  => 'text/plain',
		);

		$counts = array();
		foreach ( $mime_map as $ext => $mime ) {
			$count = (int) wp_count_attachments( $mime )->{'inherit'};
			if ( $count > 0 ) {
				$counts[ $ext ] = $count;
			}
		}

		return $counts;
	}

	/**
	 * Get filtered post meta for export — excludes internal/system fields.
	 *
	 * Applies denylist prefix filter, skips empty values, serialized blobs,
	 * ACF field references, and values over 500 characters.
	 *
	 * @param int $post_id The post ID.
	 * @return array Filtered meta key-value pairs.
	 */
	public static function get_post_meta_filtered( int $post_id ): array {
		$all_meta = get_post_meta( $post_id );
		$filtered = array();

		foreach ( $all_meta as $key => $values ) {
			// Skip denylist prefixes.
			$skip = false;
			foreach ( self::$meta_denylist_prefixes as $prefix ) {
				if ( strpos( $key, $prefix ) === 0 ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			$value = is_array( $values ) ? $values[0] : $values;

			// Skip empty values.
			if ( empty( $value ) ) {
				continue;
			}

			// Skip ACF field references (just contain field_xxxxx IDs).
			if ( is_string( $value ) && preg_match( '/^field_[a-f0-9]+$/', $value ) ) {
				continue;
			}

			// Skip serialized blobs.
			if ( is_serialized( $value ) ) {
				continue;
			}

			// Skip very long values (> 500 chars) — likely encoded data, not readable content.
			if ( is_string( $value ) && strlen( $value ) > 500 ) {
				continue;
			}

			// Clean up the key — remove leading underscore for readability.
			$clean_key = ltrim( $key, '_' );
			$filtered[ $clean_key ] = $value;
		}

		return $filtered;
	}

	/**
	 * Extract same-domain document links embedded in HTML content.
	 *
	 * Catches brochure/spec-sheet PDFs linked in product descriptions that
	 * are NOT in the media library (Fieldquip pattern — 62% of products).
	 *
	 * Only returns links matching the site domain and document MIME extensions.
	 *
	 * @param string $html_content Raw HTML content (before tag stripping).
	 * @return array List of embedded document link URLs.
	 */
	public static function get_embedded_document_links( string $html_content ): array {
		if ( empty( $html_content ) ) {
			return array();
		}

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( empty( $site_host ) ) {
			return array();
		}

		// Match URLs ending in document extensions.
		$pattern = '#https?://[^\s"\'<>]+\.(?:pdf|docx?|xlsx?|csv|txt)#i';
		if ( ! preg_match_all( $pattern, $html_content, $matches ) ) {
			return array();
		}

		$links = array();
		foreach ( $matches[0] as $url ) {
			// Only include same-domain links.
			$url_host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $url_host && ( $url_host === $site_host || str_ends_with( $url_host, '.' . $site_host ) ) ) {
				$links[] = $url;
			}
		}

		return array_values( array_unique( $links ) );
	}

	/**
	 * Get document attachments for a post (PDFs, DOCX, XLSX, etc. — NOT images).
	 *
	 * @param int $post_id The post ID.
	 * @return array List of attachment metadata arrays.
	 */
	public static function get_post_attachments( int $post_id ): array {
		$children = get_children( array(
			'post_parent' => $post_id,
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'numberposts' => 20, // Cap per parent (Build Plan V1.1).
		) );

		$document_mimes = array(
			'application/pdf',
			'application/msword',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'application/vnd.ms-excel',
			'text/csv',
			'text/plain',
		);

		$attachments = array();
		foreach ( $children as $child ) {
			$mime = get_post_mime_type( $child->ID );
			if ( ! in_array( $mime, $document_mimes, true ) ) {
				continue; // Skip images and other non-document types.
			}

			$file_path = get_attached_file( $child->ID );
			$filesize  = $file_path && file_exists( $file_path ) ? filesize( $file_path ) : 0;

			$attachments[] = array(
				'id'       => $child->ID,
				'type'     => $mime,
				'filename' => wp_basename( $file_path ?: $child->post_title ),
				'filesize' => $filesize,
				'title'    => $child->post_title,
				'modified' => $child->post_modified_gmt,
			);
		}

		return $attachments;
	}
}
