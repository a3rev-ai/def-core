<?php

/**
 * Class DEF_Core_Staff_AI
 *
 * Staff AI frontend endpoint handler.
 *
 * @package def-core
 * @since 1.1.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Handles the /staff-ai endpoint rendering.
 */
final class DEF_Core_Staff_AI
{
	/**
	 * The endpoint slug.
	 */
	const ENDPOINT_SLUG = 'staff-ai';

	/**
	 * Allowed MIME types for file uploads.
	 * UX-layer filtering only — DEF backend performs authoritative content validation.
	 */
	const UPLOAD_ALLOWED_MIME_TYPES = array(
		'image/png',
		'image/jpeg',
		'image/gif',
		'image/webp',
		'application/pdf',
		'text/plain',
		'text/markdown',
		'text/csv',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	);

	/**
	 * Maximum file size in bytes (10MB).
	 */
	const UPLOAD_MAX_SIZE_BYTES = 10485760;

	/**
	 * "Create with reference sources" caps (Engine 2.5). These mirror the DEF
	 * contract exactly — DEF re-validates authoritatively; these are BFF sanity
	 * caps that reject obviously over-budget payloads with a clear 400.
	 */
	const CREATE_MAX_REFERENCE_URLS       = 5;
	const CREATE_MAX_REFERENCE_FILES      = 2;
	const CREATE_MAX_REFERENCE_FILE_BYTES = 10485760; // 10MB total decoded.
	const CREATE_MAX_REFERENCE_TEXT       = 20000;
	const CREATE_ALLOWED_FILE_EXT         = array( 'pdf', 'docx', 'txt', 'csv', 'xlsx' );

	/**
	 * Initialize the Staff AI endpoint.
	 */
	public static function init(): void
	{
		add_action('init', array(__CLASS__, 'add_rewrite_rules'));
		add_action('template_redirect', array(__CLASS__, 'handle_endpoint'));
		add_filter('query_vars', array(__CLASS__, 'add_query_vars'));
		add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));

		// Prevent trailing slash redirect for file downloads
		add_filter('redirect_canonical', array(__CLASS__, 'prevent_download_redirect'), 10, 2);
	}

	/**
	 * Prevent trailing slash redirects for file download URLs.
	 *
	 * @param string $redirect_url The redirect URL.
	 * @param string $requested_url The requested URL.
	 * @return string|false The redirect URL or false to prevent redirect.
	 */
	public static function prevent_download_redirect($redirect_url, $requested_url)
	{
		// Don't redirect if this is a file download or PWA asset request.
		if (get_query_var('staff_ai_download') || get_query_var('staff_ai_pwa')) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Register REST API routes for Staff AI adapter.
	 *
	 * @since 1.1.0
	 */
	public static function register_rest_routes(): void
	{
		// List conversations.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/conversations',
			array(
				'methods'             => 'GET',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_list_conversations'),
			)
		);

		// Load single conversation.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/conversations/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_load_conversation'),
			)
		);

		// Send message (creates conversation if needed).
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/chat',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_send_message'),
			)
		);

		// Share settings (proxy to escalation settings for Staff AI auth context).
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/share-settings',
			array(
				'methods'             => 'GET',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_share_settings'),
			)
		);

		// Share send (proxy to escalation send-email for Staff AI auth context).
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/share-send',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_share_send'),
			)
		);

		// Store share event (persists share confirmation in conversation).
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/conversations/(?P<id>[a-zA-Z0-9_-]+)/share-event',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_store_share_event'),
			)
		);

		// Upload init — proxy to DEF backend presigned URL generation.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/uploads/init',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_upload_init'),
			)
		);

		// Upload commit — proxy to DEF backend upload finalization.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/uploads/commit',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_upload_commit'),
			)
		);

		// Summarize conversation (AI-generated subject + summary for Share form).
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/conversations/(?P<id>[a-zA-Z0-9_-]+)/summarize',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_summarize_conversation'),
			)
		);

		// Status/test endpoint for debugging connection issues.
		// Uses manage_options cap so admins can diagnose without needing staff_ai access.
		// NOTE: this shares the path GET /staff-ai/status with the BFF passthrough in
		// DEF_Core_Routes (which registers first and therefore wins dispatch — see the
		// note there). This diagnostic handler is effectively shadowed at that path; it
		// returns a richer debug payload (api_url, token_generation) and is intentionally
		// NOT the one the Staff-AI UI consumes for the model switcher's available_models.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can('manage_options') || self::user_has_staff_ai_access();
				},
				'callback'            => array(__CLASS__, 'rest_status'),
			)
		);

		// List available tools.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/tools',
			array(
				'methods'             => 'GET',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_list_tools'),
			)
		);

		// Tool invocation now goes through /staff-ai/chat (Orchestrator).
		// The /staff-ai/tools/invoke endpoint was removed in PR #19.

		// File download proxy.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/files/(?P<tenant>[^/]+)/(?P<filename>.+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_download_file'),
			)
		);

		// Content Agent review queue (PR-6): list pending staged drafts, apply, dismiss.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/drafts',
			array(
				'methods'             => 'GET',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_list_content_drafts'),
			)
		);
		// Content Agent "Create New" (Engine 2): on-demand create request. The agent
		// generates a draft asynchronously; it surfaces in the review queue above.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/create',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_content_create'),
			)
		);
		// Last audit-run timestamp for the Content Drafts freshness line (read-only).
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/last-run',
			array(
				'methods'             => 'GET',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_content_last_run'),
			)
		);
		// Current-state coverage breakdown (per content type) for the status strip.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/summary',
			array(
				'methods'             => 'GET',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_content_summary'),
			)
		);
		// Items skipped for lack of a focus keyphrase (the human must set one).
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/needs-keyphrase',
			array(
				'methods'             => 'GET',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_list_needs_keyphrase'),
			)
		);
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/drafts/(?P<id>[a-zA-Z0-9_-]+)/apply',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_apply_content_draft'),
			)
		);
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/drafts/(?P<id>[a-zA-Z0-9_-]+)/dismiss',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_dismiss_content_draft'),
			)
		);

		// Content Agent DEF #522 — Optimize tab: item-level dismiss + dismissed list + restore.
		// GET /list?bucket=<bucket> — item list by state bucket (e.g. dismissed).
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/list',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_list_content_items' ),
			)
		);
		// POST /items/{item_id}/dismiss — lightweight dismiss (item stays in knowledge).
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/items/(?P<item_id>\d+)/dismiss',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_dismiss_content_item' ),
			)
		);
		// POST /items/{item_id}/restore — restore a dismissed item back to its prior bucket.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/items/(?P<item_id>\d+)/restore',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_restore_content_item' ),
			)
		);

		// Content Agent Engine 2.5 — Clusters curation (targets + keyphrase queues).
		// Thin proxies to DEF /api/staff-ai/content/* with field-faithful bodies;
		// gated by def_staff_access (rest_permission_check) like the review queue.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/targets',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array(__CLASS__, 'rest_permission_check'),
					'callback'            => array(__CLASS__, 'rest_list_content_targets'),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array(__CLASS__, 'rest_permission_check'),
					'callback'            => array(__CLASS__, 'rest_create_content_target'),
				),
			)
		);
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/targets/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => 'PATCH',
					'permission_callback' => array(__CLASS__, 'rest_permission_check'),
					'callback'            => array(__CLASS__, 'rest_update_content_target'),
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => array(__CLASS__, 'rest_permission_check'),
					'callback'            => array(__CLASS__, 'rest_delete_content_target'),
				),
			)
		);
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/targets/(?P<id>[a-zA-Z0-9_-]+)/keyphrases',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array(__CLASS__, 'rest_permission_check'),
					'callback'            => array(__CLASS__, 'rest_list_target_keyphrases'),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array(__CLASS__, 'rest_permission_check'),
					'callback'            => array(__CLASS__, 'rest_add_target_keyphrase'),
				),
			)
		);
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/targets/(?P<id>[a-zA-Z0-9_-]+)/derive',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_derive_content_target'),
			)
		);
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/keyphrases/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'PATCH',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_update_keyphrase'),
			)
		);
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/keyphrases/(?P<id>[a-zA-Z0-9_-]+)/approve',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_approve_keyphrase'),
			)
		);
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/keyphrases/(?P<id>[a-zA-Z0-9_-]+)/dismiss',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_dismiss_keyphrase'),
			)
		);
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/targets/(?P<id>[a-zA-Z0-9_-]+)/keyphrases/dismiss-remaining',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_dismiss_remaining_keyphrases'),
			)
		);
		// Local WP item search backing the Clusters target picker — no DEF call.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/content/target-search',
			array(
				'methods'             => 'GET',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_target_search'),
			)
		);
	}

	/**
	 * REST API permission check for Staff AI endpoints.
	 *
	 * @return bool|\WP_Error True if access granted, WP_Error otherwise.
	 * @since 1.1.0
	 */
	public static function rest_permission_check()
	{
		// Authentication gate.
		if (! is_user_logged_in()) {
			return new \WP_Error(
				'rest_not_logged_in',
				__('Authentication required.', 'digital-employees'),
				array('status' => 401)
			);
		}

		// Capability gate.
		if (! self::user_has_staff_ai_access()) {
			return new \WP_Error(
				'rest_forbidden',
				__('You do not have permission to access Staff AI.', 'digital-employees'),
				array('status' => 403)
			);
		}

		return true;
	}

	/**
	 * Get the backend API base URL.
	 *
	 * @return string|null API base URL or null if not configured.
	 * @since 1.1.0
	 */
	private static function get_api_base_url(): ?string
	{
		$url = \DEF_Core::get_def_api_url_internal();
		return ! empty($url) ? $url : null;
	}

	/**
	 * Make a request to the backend API.
	 *
	 * @param string $method HTTP method (GET, POST).
	 * @param string $endpoint API endpoint path.
	 * @param array  $body Request body for POST requests.
	 * @return array|\WP_Error Response data or WP_Error.
	 * @since 1.1.0
	 */
	private static function backend_request(string $method, string $endpoint, array $body = array())
	{
		$base_url = self::get_api_base_url();
		if (! $base_url) {
			return new \WP_Error(
				'staff_ai_not_configured',
				__('Staff AI backend URL is not configured. Go to Settings > Digital Employees to set the Staff AI API URL.', 'digital-employees'),
				array('status' => 503)
			);
		}

		$url = $base_url . $endpoint;

		// BFF proxy auth: API key + user ID + capabilities (no JWT).
		$user = wp_get_current_user();
		if (! $user || 0 === $user->ID) {
			return new \WP_Error(
				'staff_ai_not_authenticated',
				__('User not authenticated.', 'digital-employees'),
				array('status' => 401)
			);
		}

		$api_key = \DEF_Core_Encryption::get_secret( 'def_core_api_key' );
		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'staff_ai_not_configured',
				__( 'API key not configured. Go to Settings > Digital Employees to set up the connection.', 'digital-employees' ),
				array( 'status' => 503 )
			);
		}

		$capabilities = \DEF_Core_Tools::get_user_def_capabilities( $user );

		$headers = array(
			'X-DEF-API-Key'            => $api_key,
			'X-DEF-User'               => (string) $user->ID,
			'X-DEF-User-Capabilities'  => implode( ',', $capabilities ),
			'Content-Type'             => 'application/json',
			'Accept'                   => 'application/json',
		);

		// Identity headers — let DEF build the "## Current authenticated user"
		// prompt section. URL-encoded for Unicode safety; DEF decodes via
		// urllib.parse.unquote(). See DEF auth.py verify_internal_request().
		if ( ! empty( $user->display_name ) ) {
			$headers['X-DEF-User-Display-Name'] = rawurlencode( $user->display_name );
		}
		if ( ! empty( $user->user_email ) ) {
			$headers['X-DEF-User-Email'] = rawurlencode( $user->user_email );
		}
		if ( ! empty( $user->roles ) && is_array( $user->roles ) ) {
			$roles = array_filter( $user->roles, 'is_string' );
			if ( ! empty( $roles ) ) {
				$headers['X-DEF-User-Roles'] = implode( ',', $roles );
			}
		}
		$site_name = get_bloginfo( 'name' );
		if ( ! empty( $site_name ) ) {
			$headers['X-DEF-Site-Name'] = rawurlencode( $site_name );
		}

		$args = array(
			'timeout'     => 60,
			'httpversion' => '1.1',
			'headers'     => $headers,
		);

		if ('GET' === $method) {
			$response = wp_remote_get($url, $args);
		} else {
			// POST / PATCH / DELETE — wp_remote_post() only speaks POST, so all
			// write verbs go through wp_remote_request() with an explicit method.
			$args['method'] = $method;
			$args['body']   = wp_json_encode($body);
			$response       = wp_remote_request($url, $args);
		}

		if (is_wp_error($response)) {
			return new \WP_Error(
				'staff_ai_request_failed',
				__('Failed to connect to Staff AI backend.', 'digital-employees'),
				array('status' => 502)
			);
		}

		$status = wp_remote_retrieve_response_code($response);
		$body   = wp_remote_retrieve_body($response);
		$data   = json_decode($body, true);

		// Handle unexpected status codes (0 = network error, empty = no response).
		if (empty($status) || 0 === $status) {
			return new \WP_Error(
				'staff_ai_network_error',
				sprintf(
					/* translators: 1: URL being called */
					__('Network error: Could not connect to backend at %1$s. Check if the URL is correct and the server is reachable.', 'digital-employees'),
					$url
				),
				array('status' => 502)
			);
		}

		// Map backend errors to clean UI-safe errors.
		if ($status >= 400) {
			$backend_detail = isset($data['detail']) ? $data['detail'] : '';

			// Handle different error status codes - each branch MUST set both $error_code and $error_message.
			if (401 === $status || 403 === $status) {
				$error_code    = 'staff_ai_auth_failed';
				$error_message = sprintf(
					/* translators: 1: HTTP status code, 2: backend error detail */
					__('Backend auth failed (HTTP %1$d). The backend may need JWKS configuration. Detail: %2$s', 'digital-employees'),
					$status,
					$backend_detail ? $backend_detail : 'none'
				);
			} elseif (404 === $status) {
				$error_code    = 'staff_ai_not_found';
				$error_message = sprintf(
					/* translators: 1: API endpoint path, 2: full URL */
					__('Backend endpoint not found (HTTP 404): %1$s. Full URL: %2$s - Please verify the backend API supports this endpoint.', 'digital-employees'),
					$endpoint,
					$url
				);
			} elseif ($status >= 500) {
				$error_code    = 'staff_ai_service_error';
				$error_message = sprintf(
					/* translators: 1: HTTP status code */
					__('Backend service error (HTTP %1$d). The service may be temporarily unavailable.', 'digital-employees'),
					$status
				);
			} else {
				// Any other 4xx error (400, 405, 422, etc.)
				$error_code    = 'staff_ai_http_' . $status;
				$error_message = sprintf(
					/* translators: 1: HTTP status code, 2: backend error detail, 3: full URL */
					__('Backend error (HTTP %1$d) calling %3$s: %2$s', 'digital-employees'),
					$status,
					$backend_detail ? $backend_detail : __('Unknown error', 'digital-employees'),
					$url
				);
			}

			// Log detailed error in debug mode for troubleshooting.
			if (defined('WP_DEBUG') && WP_DEBUG) {
				$debug_info = sprintf(
					'Staff AI backend error: status=%d, endpoint=%s, detail=%s',
					$status,
					$endpoint,
					$backend_detail ? $backend_detail : 'none'
				);
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log($debug_info);
			}

			return new \WP_Error(
				$error_code,
				$error_message,
				array('status' => $status)
			);
		}

		return $data;
	}

	/**
	 * REST handler: List conversations.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response.
	 * @since 1.1.0
	 */
	public static function rest_list_conversations(\WP_REST_Request $request)
	{
		// Staff AI uses dedicated endpoints - NOT the customer chatbot
		// Reference: STAFF_AI_CHANNEL_OVERVIEW.md
		// "The Staff AI Channel is NOT a customer chatbot"
		$result = self::backend_request('GET', '/api/staff-ai/threads');

		if (is_wp_error($result)) {
			return $result;
		}

		// Map backend response to frontend format.
		$conversations = array();
		if (isset($result['threads']) && is_array($result['threads'])) {
			foreach ($result['threads'] as $thread) {
				$conversations[] = array(
					'id'         => $thread['id'] ?? '',
					'title'      => $thread['title'] ?? __('New conversation', 'digital-employees'),
					'updated_at' => $thread['updatedAt'] ?? $thread['createdAt'] ?? '',
					'is_shared'  => false, // Backend doesn't support sharing yet.
				);
			}
		}

		return new \WP_REST_Response(
			array(
				'success'       => true,
				'conversations' => $conversations,
			),
			200
		);
	}

	/**
	 * REST handler: list pending Content Agent drafts (PR-6 review queue).
	 *
	 * Proxies to DEF GET /api/staff-ai/content/drafts and passes the draft fields
	 * through unchanged (proposed/source carry the diff the UI renders).
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	/**
	 * True when $url is a syntactically valid http(s) URL. Used to validate
	 * reference-source URLs WITHOUT reshaping them — esc_url_raw would re-encode
	 * the string, but these are opaque payload data for DEF, so we only check the
	 * scheme/host shape and pass the original through.
	 *
	 * @param string $url Candidate URL.
	 * @return bool Whether it is an http(s) URL.
	 */
	private static function is_http_url( string $url ): bool
	{
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		return ( 'http' === $scheme || 'https' === $scheme )
			&& '' !== (string) wp_parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * Validate a client-supplied reference_sources object (Engine 2.5).
	 *
	 * Shape mirrors the DEF contract: { urls: string[≤5], text: string[≤20k],
	 * files: [{filename, content_b64}][≤2, ≤10MB decoded total; pdf/docx/txt/csv/
	 * xlsx] }. Everything optional. Returns the CLEANED object passed through
	 * unchanged (no esc_url_raw / sanitize that could corrupt payload data —
	 * length/type/scheme checks only), an empty array when no usable source is
	 * present, or a WP_Error naming the first cap/shape violation. DEF owns the
	 * authoritative validation; this is a BFF sanity gate with clear 400s.
	 *
	 * @param mixed $raw Raw reference_sources from the request body.
	 * @return array|\WP_Error Cleaned reference_sources (possibly empty) or error.
	 */
	private static function validate_reference_sources( $raw )
	{
		if ( null === $raw || ( is_array( $raw ) && empty( $raw ) ) ) {
			return array();
		}
		if ( ! is_array( $raw ) ) {
			return new \WP_Error(
				'invalid_reference_sources',
				__( 'reference_sources must be an object.', 'digital-employees' ),
				array( 'status' => 400 )
			);
		}

		$err   = static function ( $message ) {
			return new \WP_Error( 'invalid_reference_sources', $message, array( 'status' => 400 ) );
		};
		$clean = array();

		// urls: ≤5 http(s), passed through unchanged.
		if ( isset( $raw['urls'] ) ) {
			if ( ! is_array( $raw['urls'] ) ) {
				return $err( __( 'reference_sources.urls must be a list of URLs.', 'digital-employees' ) );
			}
			if ( count( $raw['urls'] ) > self::CREATE_MAX_REFERENCE_URLS ) {
				return $err( __( 'At most 5 reference URLs are allowed.', 'digital-employees' ) );
			}
			$urls = array();
			foreach ( $raw['urls'] as $u ) {
				$u = is_string( $u ) ? trim( $u ) : '';
				if ( '' === $u ) {
					continue;
				}
				if ( ! self::is_http_url( $u ) ) {
					return $err( __( 'Each reference URL must be a valid http(s) URL.', 'digital-employees' ) );
				}
				$urls[] = $u;
			}
			if ( ! empty( $urls ) ) {
				$clean['urls'] = $urls;
			}
		}

		// text: ≤20,000 chars, passed through verbatim (sanitizing would corrupt
		// source material — this is payload data for DEF, not display text).
		if ( isset( $raw['text'] ) ) {
			if ( ! is_string( $raw['text'] ) ) {
				return $err( __( 'reference_sources.text must be a string.', 'digital-employees' ) );
			}
			if ( mb_strlen( $raw['text'] ) > self::CREATE_MAX_REFERENCE_TEXT ) {
				return $err( __( 'Source text is limited to 20,000 characters.', 'digital-employees' ) );
			}
			if ( '' !== trim( $raw['text'] ) ) {
				$clean['text'] = $raw['text'];
			}
		}

		// files: ≤2 items, allowed types, ≤10MB total decoded; passed through unchanged.
		if ( isset( $raw['files'] ) ) {
			if ( ! is_array( $raw['files'] ) ) {
				return $err( __( 'reference_sources.files must be a list.', 'digital-employees' ) );
			}
			if ( count( $raw['files'] ) > self::CREATE_MAX_REFERENCE_FILES ) {
				return $err( __( 'At most 2 reference files are allowed.', 'digital-employees' ) );
			}
			$files = array();
			$bytes = 0;
			foreach ( $raw['files'] as $f ) {
				if ( ! is_array( $f ) || ! isset( $f['filename'], $f['content_b64'] )
					|| ! is_string( $f['filename'] ) || ! is_string( $f['content_b64'] ) ) {
					return $err( __( 'Each reference file needs a filename and base64 content.', 'digital-employees' ) );
				}
				$ext = strtolower( pathinfo( $f['filename'], PATHINFO_EXTENSION ) );
				if ( ! in_array( $ext, self::CREATE_ALLOWED_FILE_EXT, true ) ) {
					return $err( __( 'Reference files must be PDF, DOCX, TXT, CSV or XLSX.', 'digital-employees' ) );
				}
				$decoded = base64_decode( $f['content_b64'], true );
				if ( false === $decoded ) {
					return $err( __( 'A reference file is not valid base64.', 'digital-employees' ) );
				}
				$bytes  += strlen( $decoded );
				$files[] = array( 'filename' => $f['filename'], 'content_b64' => $f['content_b64'] );
			}
			if ( $bytes > self::CREATE_MAX_REFERENCE_FILE_BYTES ) {
				return $err( __( 'Reference files exceed the 10MB total limit.', 'digital-employees' ) );
			}
			if ( ! empty( $files ) ) {
				$clean['files'] = $files;
			}
		}

		return $clean;
	}

	/**
	 * REST handler: on-demand "Create New" request (Content Agent Engine 2).
	 *
	 * Proxies DEF POST /api/staff-ai/content/create with the user's keyphrase. DEF
	 * generates a fully-optimized draft asynchronously; it then appears in the
	 * Content Drafts review queue (kind = 'create') for approval. No synchronous
	 * wait — this just acknowledges the request.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_content_create( \WP_REST_Request $request )
	{
		$params    = $request->get_json_params();
		$params    = is_array( $params ) ? $params : array();
		$keyphrase = ( isset( $params['keyphrase'] ) && is_string( $params['keyphrase'] ) )
			? trim( $params['keyphrase'] )
			: '';

		// Reference sources (Engine 2.5 "Create with sources") — optional. Validated
		// for shape/caps here and passed through UNCHANGED (no lossy reshape); DEF
		// owns authoritative validation. When ≥1 source is supplied the keyphrase is
		// optional (DEF derives it, the human reviews it on the draft card).
		$reference_sources = self::validate_reference_sources(
			isset( $params['reference_sources'] ) ? $params['reference_sources'] : null
		);
		if ( is_wp_error( $reference_sources ) ) {
			return $reference_sources;
		}
		$has_sources = ! empty( $reference_sources );

		if ( '' === $keyphrase && ! $has_sources ) {
			return new \WP_Error(
				'invalid_keyphrase',
				__( 'Provide a keyphrase or at least one reference source to generate a post.', 'digital-employees' ),
				array( 'status' => 400 )
			);
		}

		// Optional writer notes (Engine 2.5) — forwarded verbatim to DEF; the
		// writer consumes them when retrieval-grounded writing lands (PR 2.5-3).
		$notes = ( isset( $params['notes'] ) && is_string( $params['notes'] ) )
			? trim( sanitize_textarea_field( $params['notes'] ) )
			: '';
		if ( mb_strlen( $notes ) > 2000 ) {
			return new \WP_Error(
				'invalid_notes',
				__( 'Notes are limited to 2000 characters.', 'digital-employees' ),
				array( 'status' => 400 )
			);
		}

		$body = array();
		if ( '' !== $keyphrase ) {
			$body['keyphrase'] = $keyphrase;
		}
		if ( '' !== $notes ) {
			$body['notes'] = $notes;
		}
		if ( $has_sources ) {
			$body['reference_sources'] = $reference_sources;
		}

		$result = self::backend_request( 'POST', '/api/staff-ai/content/create', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload = array( 'success' => true );
		if ( is_array( $result ) ) {
			$payload = array_merge( $payload, $result );
		}
		return new \WP_REST_Response( $payload, 200 );
	}

	public static function rest_list_content_drafts( \WP_REST_Request $request )
	{
		$result = self::backend_request( 'GET', '/api/staff-ai/content/drafts' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$drafts = ( isset( $result['drafts'] ) && is_array( $result['drafts'] ) ) ? $result['drafts'] : array();

		// Enrich each draft with the local product title + links. The draft's
		// item_id IS the WordPress product post ID, so we resolve title/links here
		// (in WP) rather than round-tripping the backend — always accurate, no extra
		// call. get_edit_post_link returns '' when the current user can't edit, so
		// the title simply falls back to plain text client-side.
		foreach ( $drafts as &$draft ) {
			$item_id = isset( $draft['item_id'] ) ? (int) $draft['item_id'] : 0;
			if ( $item_id > 0 && get_post_status( $item_id ) ) {
				$title             = get_the_title( $item_id );
				$draft['title']    = is_string( $title ) ? $title : '';
				$edit_url          = get_edit_post_link( $item_id, 'raw' );
				$draft['edit_url'] = $edit_url ? $edit_url : '';
				$view_url          = get_permalink( $item_id );
				$draft['view_url'] = $view_url ? $view_url : '';
			}
		}
		unset( $draft );

		return new \WP_REST_Response( array( 'success' => true, 'drafts' => $drafts ), 200 );
	}

	/**
	 * REST handler: the most recent Content Agent audit-run summary, for the
	 * Content Drafts status strip.
	 *
	 * Proxies DEF GET /api/staff-ai/content/last-run and normalizes the payload
	 * (see normalize_last_run_payload) so the client receives integer counts and
	 * a predictable shape. Returns last_run = null when no run has happened.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_content_last_run( \WP_REST_Request $request )
	{
		$result = self::backend_request( 'GET', '/api/staff-ai/content/last-run' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'last_run' => self::normalize_last_run_payload( $result ),
			),
			200
		);
	}

	/**
	 * Normalize the DEF /content/last-run payload into a safe, predictable shape.
	 *
	 * The backend returns either {"last_run": null} (never run) or
	 * {"last_run": {status, counts{...}, started_at, finished_at}}. This coerces
	 * every count to a non-negative integer (the strip renders them as numbers
	 * and must never be handed a string/float), keeps status/timestamps as
	 * strings (or null), and drops anything malformed. A missing or non-array
	 * last_run collapses to null so the UI shows "No runs yet".
	 *
	 * Note: counts are surfaced as a raw activity breakdown by design — we never
	 * derive a ratio like "N/total optimized", because most audited items
	 * legitimately pass and need no work, so a fraction would imply false pending
	 * work (Steve's hard requirement).
	 *
	 * @param mixed $payload Decoded backend response.
	 * @return array|null Normalized last_run, or null when there is no run.
	 */
	public static function normalize_last_run_payload( $payload ): ?array
	{
		if ( ! is_array( $payload ) || ! array_key_exists( 'last_run', $payload ) ) {
			return null;
		}
		$last_run = $payload['last_run'];
		if ( ! is_array( $last_run ) ) {
			return null; // null (never run) or malformed → "No runs yet".
		}

		$counts_in = ( isset( $last_run['counts'] ) && is_array( $last_run['counts'] ) )
			? $last_run['counts']
			: array();

		$counts = array();
		foreach ( array( 'audited', 'flagged', 'staged', 'needs_keyphrase', 'skipped', 'errored' ) as $key ) {
			$counts[ $key ] = isset( $counts_in[ $key ] ) ? max( 0, (int) $counts_in[ $key ] ) : 0;
		}

		// Optional: content types whose audit hit a transport error. Strings only.
		if ( isset( $counts_in['audit_failed_types'] ) && is_array( $counts_in['audit_failed_types'] ) ) {
			$failed_types = array();
			foreach ( $counts_in['audit_failed_types'] as $type ) {
				if ( is_string( $type ) && '' !== $type ) {
					$failed_types[] = $type;
				}
			}
			if ( ! empty( $failed_types ) ) {
				$counts['audit_failed_types'] = array_values( $failed_types );
			}
		}

		$string_or_null = static function ( $value ) {
			return ( is_string( $value ) && '' !== $value ) ? $value : null;
		};

		return array(
			'status'      => is_string( $last_run['status'] ?? null ) ? $last_run['status'] : '',
			'counts'      => $counts,
			'started_at'  => $string_or_null( $last_run['started_at'] ?? null ),
			'finished_at' => $string_or_null( $last_run['finished_at'] ?? null ),
		);
	}

	/**
	 * Bucket keys for the current-state coverage breakdown. Whitelisted so a
	 * hostile/garbled backend payload can only ever produce these integer fields.
	 */
	const COVERAGE_BUCKET_KEYS = array(
		'good',
		'optimized',
		'awaiting_review',
		'needs_work',
		'needs_keyphrase',
		'dismissed',
		'errored',
		'other',
		'total',
	);

	/**
	 * REST handler: current-state content coverage breakdown, per content type,
	 * for the Content Drafts status strip headline.
	 *
	 * Proxies DEF GET /api/staff-ai/content/summary and normalizes the payload
	 * (see normalize_summary_payload). Returns summary = null when the backend has
	 * no coverage data yet.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_content_summary( \WP_REST_Request $request )
	{
		$result = self::backend_request( 'GET', '/api/staff-ai/content/summary' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'summary' => self::normalize_summary_payload( $result ),
			),
			200
		);
	}

	/**
	 * Normalize the DEF /content/summary payload into a safe, predictable shape.
	 *
	 * Backend shape: {"summary": {"by_type": {"<type>": {<buckets>}, ...},
	 * "totals": {<buckets>, "total_reviewed": N}}}. This whitelists the bucket
	 * keys, coerces every bucket to a non-negative integer, keeps only string
	 * type keys, and tolerates missing types/buckets. A missing or non-array
	 * summary collapses to null (the strip then simply omits the coverage line).
	 *
	 * The buckets are surfaced as discrete counts; we never derive a ratio over
	 * "all content" — the only legitimate denominator is items reviewed
	 * (Steve's hard requirement), carried as totals.total_reviewed.
	 *
	 * @param mixed $payload Decoded backend response.
	 * @return array|null Normalized summary, or null when there is none.
	 */
	public static function normalize_summary_payload( $payload ): ?array
	{
		if ( ! is_array( $payload ) || ! isset( $payload['summary'] ) || ! is_array( $payload['summary'] ) ) {
			return null;
		}
		$summary = $payload['summary'];

		$by_type_in = ( isset( $summary['by_type'] ) && is_array( $summary['by_type'] ) ) ? $summary['by_type'] : array();
		$by_type    = array();
		foreach ( $by_type_in as $type => $buckets ) {
			if ( ! is_string( $type ) || '' === $type ) {
				continue; // Drop malformed type keys.
			}
			$by_type[ $type ] = self::normalize_coverage_buckets( $buckets );
		}

		$totals = self::normalize_coverage_buckets( isset( $summary['totals'] ) ? $summary['totals'] : array() );
		// total_reviewed is the only sanctioned denominator (never "all content").
		$totals_in                = ( isset( $summary['totals'] ) && is_array( $summary['totals'] ) ) ? $summary['totals'] : array();
		$totals['total_reviewed'] = ( isset( $totals_in['total_reviewed'] ) && is_scalar( $totals_in['total_reviewed'] ) )
			? max( 0, (int) $totals_in['total_reviewed'] )
			: 0;

		return array(
			'by_type' => $by_type,
			'totals'  => $totals,
		);
	}

	/**
	 * Coerce one set of coverage buckets to a whitelisted map of non-negative
	 * integers. Unknown keys are dropped; missing keys default to 0.
	 *
	 * @param mixed $buckets Raw per-type or totals bucket map.
	 * @return array<string,int> Normalized buckets.
	 */
	private static function normalize_coverage_buckets( $buckets ): array
	{
		$buckets = is_array( $buckets ) ? $buckets : array();
		$out     = array();
		foreach ( self::COVERAGE_BUCKET_KEYS as $key ) {
			// Guard is_scalar before the int-cast so an array value (garbled
			// payload) can't raise an "Array to int conversion" warning or coerce
			// to a misleading 1 — it cleanly becomes 0.
			$out[ $key ] = ( isset( $buckets[ $key ] ) && is_scalar( $buckets[ $key ] ) )
				? max( 0, (int) $buckets[ $key ] )
				: 0;
		}
		return $out;
	}

	/**
	 * REST handler: list items the Content Agent skipped for lack of a focus
	 * keyphrase. Enriched with the local title + edit link so the human can jump
	 * to the editor and set one.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_list_needs_keyphrase( \WP_REST_Request $request )
	{
		$result = self::backend_request( 'GET', '/api/staff-ai/content/needs-keyphrase' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$items = ( isset( $result['items'] ) && is_array( $result['items'] ) ) ? $result['items'] : array();
		foreach ( $items as &$item ) {
			$item_id = isset( $item['item_id'] ) ? (int) $item['item_id'] : 0;
			if ( $item_id > 0 && get_post_status( $item_id ) ) {
				$title            = get_the_title( $item_id );
				$item['title']    = is_string( $title ) ? $title : '';
				$edit_url         = get_edit_post_link( $item_id, 'raw' );
				$item['edit_url'] = $edit_url ? $edit_url : '';
			}
		}
		unset( $item );

		return new \WP_REST_Response( array( 'success' => true, 'items' => $items ), 200 );
	}

	/**
	 * REST handler: apply a draft live (PR-6). The DEF write runs as THIS user, so
	 * WordPress's own current_user_can gates the product edit.
	 *
	 * Optionally forwards reviewer edits {proposed:{field:value}} (PR-C). Only the
	 * content allowlist is forwarded; DEF re-validates (edits may change the value
	 * of an already-touched field, not introduce new ones).
	 *
	 * @return \WP_REST_Response|\WP_Error Response ({status: applied|stale|apply_failed}).
	 */
	public static function rest_apply_content_draft( \WP_REST_Request $request )
	{
		$id   = sanitize_text_field( $request->get_param( 'id' ) );
		$body = array();
		$params = $request->get_json_params();
		if ( is_array( $params ) && isset( $params['proposed'] ) && is_array( $params['proposed'] ) ) {
			$allowed  = array( 'description', 'short_description', 'name' );
			$proposed = array();
			foreach ( $params['proposed'] as $field => $value ) {
				if ( in_array( $field, $allowed, true ) && is_string( $value ) ) {
					$proposed[ $field ] = $value;
				}
			}
			if ( ! empty( $proposed ) ) {
				$body['proposed'] = $proposed;
			}
		}
		$result = self::backend_request( 'POST', '/api/staff-ai/content/drafts/' . rawurlencode( $id ) . '/apply', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: dismiss a draft (PR-6).
	 *
	 * @return \WP_REST_Response|\WP_Error Response ({status: dismissed}).
	 */
	public static function rest_dismiss_content_draft( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$result = self::backend_request( 'POST', '/api/staff-ai/content/drafts/' . rawurlencode( $id ) . '/dismiss' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	// ── Content Agent DEF #522: item-level dismiss / dismissed list / restore ──────

	/**
	 * REST handler: list content items by bucket (e.g. bucket=dismissed).
	 *
	 * Proxies DEF GET /api/staff-ai/content/list?bucket=<bucket> and enriches each
	 * item with the local WP title and edit URL (same pattern as needs-keyphrase).
	 *
	 * @return \WP_REST_Response|\WP_Error Response ({success, items}).
	 */
	public static function rest_list_content_items( \WP_REST_Request $request )
	{
		$bucket = sanitize_key( (string) $request->get_param( 'bucket' ) );
		if ( '' === $bucket ) {
			return new \WP_Error(
				'invalid_bucket',
				__( 'bucket parameter is required.', 'digital-employees' ),
				array( 'status' => 400 )
			);
		}
		$result = self::backend_request( 'GET', '/api/staff-ai/content/list?bucket=' . rawurlencode( $bucket ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$items = ( isset( $result['items'] ) && is_array( $result['items'] ) ) ? $result['items'] : array();
		foreach ( $items as &$item ) {
			$item_id = isset( $item['item_id'] ) ? (int) $item['item_id'] : 0;
			if ( $item_id > 0 && get_post_status( $item_id ) ) {
				$title             = get_the_title( $item_id );
				$item['title']     = is_string( $title ) ? $title : '';
				$edit_url          = get_edit_post_link( $item_id, 'raw' );
				$item['edit_url']  = $edit_url ? $edit_url : '';
				$view_url          = get_permalink( $item_id );
				$item['view_url']  = $view_url ? $view_url : '';
			}
		}
		unset( $item );

		// Allowlist the fields the JS UI reads. Prevents future backend schema
		// additions from silently surfacing sensitive fields to the client.
		$allowed = array_flip( array( 'item_id', 'item_type', 'draft_id', 'restorable', 'last_audited', 'title', 'edit_url', 'view_url' ) );
		$items   = array_map( static function ( $item ) use ( $allowed ) {
			return is_array( $item ) ? array_intersect_key( $item, $allowed ) : array();
		}, $items );

		return new \WP_REST_Response( array( 'success' => true, 'items' => $items ), 200 );
	}

	/**
	 * REST handler: lightweight dismiss of a content item (DEF #522).
	 *
	 * The item stays in knowledge / Customer Chat — only the optimization queue
	 * entry moves to the dismissed bucket. Proxies DEF POST
	 * /api/staff-ai/content/items/{item_id}/dismiss.
	 *
	 * @return \WP_REST_Response|\WP_Error Response ({status: dismissed}).
	 */
	public static function rest_dismiss_content_item( \WP_REST_Request $request )
	{
		$item_id = absint( $request->get_param( 'item_id' ) );
		if ( $item_id <= 0 ) {
			return new \WP_Error(
				'invalid_item_id',
				__( 'A valid item_id is required.', 'digital-employees' ),
				array( 'status' => 400 )
			);
		}
		$result = self::backend_request( 'POST', '/api/staff-ai/content/items/' . $item_id . '/dismiss' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: restore a dismissed content item (DEF #522).
	 *
	 * Returns the item to its prior optimization bucket (needs_keyphrase /
	 * needs_work / pass). Proxies DEF POST
	 * /api/staff-ai/content/items/{item_id}/restore.
	 *
	 * @return \WP_REST_Response|\WP_Error Response ({status: needs_keyphrase|needs_work|pass}).
	 */
	public static function rest_restore_content_item( \WP_REST_Request $request )
	{
		$item_id = absint( $request->get_param( 'item_id' ) );
		if ( $item_id <= 0 ) {
			return new \WP_Error(
				'invalid_item_id',
				__( 'A valid item_id is required.', 'digital-employees' ),
				array( 'status' => 400 )
			);
		}
		$result = self::backend_request( 'POST', '/api/staff-ai/content/items/' . $item_id . '/restore' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	// ── Content Agent Engine 2.5: Clusters curation (targets + keyphrase queues) ──

	/**
	 * Maximum reference URLs per target (mirrors the DEF contract — every stored
	 * URL is fetched at derive, so the cap is real cost control, not cosmetics).
	 */
	const TARGET_MAX_REFERENCE_URLS = 5;

	/**
	 * Validate a client-supplied reference_urls list: an array of at most 5
	 * http(s) URLs. Returns the cleaned list, or a WP_Error naming the problem —
	 * an invalid entry is rejected explicitly, never silently dropped.
	 *
	 * @param mixed $raw Raw reference_urls from the request body.
	 * @return array|\WP_Error Cleaned URL list or error.
	 */
	private static function validate_reference_urls( $raw )
	{
		if ( ! is_array( $raw ) ) {
			return new \WP_Error(
				'invalid_reference_urls',
				__( 'reference_urls must be a list of URLs.', 'digital-employees' ),
				array( 'status' => 400 )
			);
		}
		if ( count( $raw ) > self::TARGET_MAX_REFERENCE_URLS ) {
			return new \WP_Error(
				'invalid_reference_urls',
				__( 'A target can have at most 5 reference URLs.', 'digital-employees' ),
				array( 'status' => 400 )
			);
		}
		$urls = array();
		foreach ( $raw as $u ) {
			$clean = is_string( $u ) ? esc_url_raw( trim( $u ), array( 'http', 'https' ) ) : '';
			if ( '' === $clean ) {
				return new \WP_Error(
					'invalid_reference_urls',
					__( 'Each reference URL must be a valid http(s) URL.', 'digital-employees' ),
					array( 'status' => 400 )
				);
			}
			$urls[] = $clean;
		}
		return $urls;
	}

	/**
	 * REST handler: list cluster targets. Proxies DEF GET /content/targets and
	 * passes the target objects through unchanged (id, item_type, item_id,
	 * source_route, title, url, reference_urls, focus_keyphrase, status,
	 * keyphrase_counts, created_at).
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_list_content_targets( \WP_REST_Request $request )
	{
		$result = self::backend_request( 'GET', '/api/staff-ai/content/targets' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$targets = ( isset( $result['targets'] ) && is_array( $result['targets'] ) ) ? $result['targets'] : array();
		return new \WP_REST_Response( array( 'success' => true, 'targets' => $targets ), 200 );
	}

	/**
	 * REST handler: nominate a cluster target. Forwards item_type, item_id,
	 * source_route (CPTs), title, url and reference_urls to DEF — every accepted
	 * field is forwarded; DEF re-validates authoritatively (422/409).
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_create_content_target( \WP_REST_Request $request )
	{
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$item_type = ( isset( $params['item_type'] ) && is_string( $params['item_type'] ) ) ? sanitize_key( $params['item_type'] ) : '';
		$item_id   = isset( $params['item_id'] ) ? (string) $params['item_id'] : '';
		$title     = ( isset( $params['title'] ) && is_string( $params['title'] ) ) ? sanitize_text_field( $params['title'] ) : '';
		$url       = ( isset( $params['url'] ) && is_string( $params['url'] ) ) ? esc_url_raw( trim( $params['url'] ), array( 'http', 'https' ) ) : '';

		if ( '' === $item_type || ! preg_match( '/^\d+$/', $item_id ) || '' === $title || '' === $url ) {
			return new \WP_Error(
				'invalid_target',
				__( 'item_type, a numeric item_id, title and a valid http(s) url are required.', 'digital-employees' ),
				array( 'status' => 400 )
			);
		}

		$body = array(
			'item_type' => $item_type,
			'item_id'   => $item_id,
			'title'     => $title,
			'url'       => $url,
		);
		if ( isset( $params['source_route'] ) && is_string( $params['source_route'] ) && '' !== $params['source_route'] ) {
			$body['source_route'] = sanitize_key( $params['source_route'] );
		}
		if ( isset( $params['reference_urls'] ) ) {
			$urls = self::validate_reference_urls( $params['reference_urls'] );
			if ( is_wp_error( $urls ) ) {
				return $urls;
			}
			$body['reference_urls'] = $urls;
		}

		$result = self::backend_request( 'POST', '/api/staff-ai/content/targets', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$payload = array( 'success' => true );
		if ( is_array( $result ) ) {
			$payload = array_merge( $payload, $result );
		}
		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * REST handler: update a target. PATCH semantics — only the keys present in
	 * the request are forwarded (title, url, reference_urls, status).
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_update_content_target( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$body   = array();

		if ( array_key_exists( 'title', $params ) ) {
			$title = is_string( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';
			if ( '' === $title ) {
				return new \WP_Error( 'invalid_target', __( 'title cannot be empty.', 'digital-employees' ), array( 'status' => 400 ) );
			}
			$body['title'] = $title;
		}
		if ( array_key_exists( 'url', $params ) ) {
			$url = is_string( $params['url'] ) ? esc_url_raw( trim( $params['url'] ), array( 'http', 'https' ) ) : '';
			if ( '' === $url ) {
				return new \WP_Error( 'invalid_target', __( 'url must be a valid http(s) URL.', 'digital-employees' ), array( 'status' => 400 ) );
			}
			$body['url'] = $url;
		}
		if ( array_key_exists( 'reference_urls', $params ) ) {
			$urls = self::validate_reference_urls( $params['reference_urls'] );
			if ( is_wp_error( $urls ) ) {
				return $urls;
			}
			$body['reference_urls'] = $urls;
		}
		if ( array_key_exists( 'status', $params ) ) {
			if ( ! in_array( $params['status'], array( 'active', 'paused' ), true ) ) {
				return new \WP_Error( 'invalid_target', __( 'status must be active or paused.', 'digital-employees' ), array( 'status' => 400 ) );
			}
			$body['status'] = $params['status'];
		}
		if ( empty( $body ) ) {
			return new \WP_Error( 'invalid_target', __( 'Nothing to update.', 'digital-employees' ), array( 'status' => 400 ) );
		}

		$result = self::backend_request( 'PATCH', '/api/staff-ai/content/targets/' . rawurlencode( $id ), $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: remove a target (and its queue rows, on the DEF side).
	 * Written posts and staged drafts are untouched.
	 *
	 * @return \WP_REST_Response|\WP_Error Response ({status: deleted}).
	 */
	public static function rest_delete_content_target( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$result = self::backend_request( 'DELETE', '/api/staff-ai/content/targets/' . rawurlencode( $id ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: list a target's keyphrase queue. Rows pass through unchanged
	 * (phrase, intent_type, status, rationale, staged_change_id, post_id, …);
	 * written rows whose post_id resolves locally are enriched with edit_url /
	 * view_url so the UI can link the cluster post.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_list_target_keyphrases( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$result = self::backend_request( 'GET', '/api/staff-ai/content/targets/' . rawurlencode( $id ) . '/keyphrases' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$rows = ( isset( $result['keyphrases'] ) && is_array( $result['keyphrases'] ) ) ? $result['keyphrases'] : array();

		foreach ( $rows as &$row ) {
			$post_id = ( is_array( $row ) && isset( $row['post_id'] ) ) ? (int) $row['post_id'] : 0;
			if ( $post_id > 0 && get_post_status( $post_id ) ) {
				$edit_url        = get_edit_post_link( $post_id, 'raw' );
				$row['edit_url'] = $edit_url ? $edit_url : '';
				$view_url        = get_permalink( $post_id );
				$row['view_url'] = $view_url ? $view_url : '';
			}
		}
		unset( $row );

		return new \WP_REST_Response( array( 'success' => true, 'keyphrases' => $rows ), 200 );
	}

	/**
	 * REST handler: manually add a keyphrase to a target's queue (born approved
	 * on the DEF side — human-added IS curation).
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_add_target_keyphrase( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$params = $request->get_json_params();
		$phrase = ( is_array( $params ) && isset( $params['phrase'] ) && is_string( $params['phrase'] ) )
			? sanitize_text_field( $params['phrase'] )
			: '';
		$intent = ( is_array( $params ) && isset( $params['intent_type'] ) && is_string( $params['intent_type'] ) )
			? sanitize_key( $params['intent_type'] )
			: '';
		if ( '' === $phrase || '' === $intent ) {
			return new \WP_Error(
				'invalid_keyphrase',
				__( 'A phrase and an intent type are required.', 'digital-employees' ),
				array( 'status' => 400 )
			);
		}
		$result = self::backend_request(
			'POST',
			'/api/staff-ai/content/targets/' . rawurlencode( $id ) . '/keyphrases',
			array( 'phrase' => $phrase, 'intent_type' => $intent )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: edit a queued keyphrase (phrase and/or intent_type, only
	 * while proposed/approved — DEF enforces the state machine with 409).
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_update_keyphrase( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$body   = array();

		if ( array_key_exists( 'phrase', $params ) ) {
			$phrase = is_string( $params['phrase'] ) ? sanitize_text_field( $params['phrase'] ) : '';
			if ( '' === $phrase ) {
				return new \WP_Error( 'invalid_keyphrase', __( 'phrase cannot be empty.', 'digital-employees' ), array( 'status' => 400 ) );
			}
			$body['phrase'] = $phrase;
		}
		if ( array_key_exists( 'intent_type', $params ) ) {
			$intent = is_string( $params['intent_type'] ) ? sanitize_key( $params['intent_type'] ) : '';
			if ( '' === $intent ) {
				return new \WP_Error( 'invalid_keyphrase', __( 'intent_type cannot be empty.', 'digital-employees' ), array( 'status' => 400 ) );
			}
			$body['intent_type'] = $intent;
		}
		if ( empty( $body ) ) {
			return new \WP_Error( 'invalid_keyphrase', __( 'Nothing to update.', 'digital-employees' ), array( 'status' => 400 ) );
		}

		$result = self::backend_request( 'PATCH', '/api/staff-ai/content/keyphrases/' . rawurlencode( $id ), $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: approve a proposed keyphrase.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_approve_keyphrase( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$result = self::backend_request( 'POST', '/api/staff-ai/content/keyphrases/' . rawurlencode( $id ) . '/approve' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: dismiss a keyphrase (keeps its slot claimed — re-derive
	 * won't re-propose it).
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_dismiss_keyphrase( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$result = self::backend_request( 'POST', '/api/staff-ai/content/keyphrases/' . rawurlencode( $id ) . '/dismiss' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: bulk-dismiss every still-proposed keyphrase on a target
	 * (the post-curation "Dismiss remaining" click). Proxies DEF's same-named
	 * route; dismissed phrases keep their slot, so re-derive won't re-propose
	 * them. Pure passthrough — keyphrase_counts is sparse (zero-count keys
	 * omitted) and the JS coerces missing keys to 0.
	 *
	 * @return \WP_REST_Response|\WP_Error Response ({dismissed, keyphrase_counts}).
	 */
	public static function rest_dismiss_remaining_keyphrases( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$result = self::backend_request(
			'POST',
			'/api/staff-ai/content/targets/' . rawurlencode( $id ) . '/keyphrases/dismiss-remaining'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: run derive for a target. Enqueue-and-ack on the DEF side
	 * ({status: accepted}, ~15-60s) — the UI polls the keyphrase list for new
	 * proposed rows.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_derive_content_target( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$result = self::backend_request( 'POST', '/api/staff-ai/content/targets/' . rawurlencode( $id ) . '/derive' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: local WP item search backing the Clusters target picker.
	 * Searches published items across all public, REST-exposed post types
	 * (posts and pages first-class alongside products and CPTs) and returns the
	 * fields a nomination needs — source_route is the type's rest_base, which
	 * DEF requires for non-built-in types.
	 *
	 * @return \WP_REST_Response Response ({items: [...]}).
	 */
	public static function rest_target_search( \WP_REST_Request $request )
	{
		$q = sanitize_text_field( (string) $request->get_param( 'q' ) );
		if ( strlen( $q ) < 2 ) {
			return new \WP_REST_Response( array( 'success' => true, 'items' => array() ), 200 );
		}

		$types = get_post_types( array( 'public' => true, 'show_in_rest' => true ), 'names' );
		unset( $types['attachment'] );

		$posts = get_posts(
			array(
				's'              => $q,
				'post_type'      => array_values( $types ),
				'post_status'    => 'publish',
				'posts_per_page' => 20,
			)
		);

		$items = array();
		foreach ( $posts as $p ) {
			$pto     = get_post_type_object( $p->post_type );
			$items[] = array(
				'item_type'    => $p->post_type,
				'item_id'      => (string) $p->ID,
				'title'        => get_the_title( $p ),
				'url'          => get_permalink( $p ),
				'source_route' => ( $pto && ! empty( $pto->rest_base ) ) ? $pto->rest_base : $p->post_type,
			);
		}

		return new \WP_REST_Response( array( 'success' => true, 'items' => $items ), 200 );
	}

	/**
	 * REST handler: Load single conversation.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response.
	 * @since 1.1.0
	 */
	public static function rest_load_conversation(\WP_REST_Request $request)
	{
		$thread_id = sanitize_text_field($request->get_param('id'));
		// Staff AI uses dedicated endpoints - NOT the customer chatbot
		$result    = self::backend_request('GET', '/api/staff-ai/threads/' . rawurlencode($thread_id) . '/messages');

		if (is_wp_error($result)) {
			return $result;
		}

		// Map backend response to frontend format.
		$messages = array();
		if (isset($result['messages']) && is_array($result['messages'])) {
			foreach ($result['messages'] as $msg) {
				$messages[] = array(
					'role'         => $msg['role'] ?? 'user',
					'content'      => $msg['content'] ?? '',
					'timestamp'    => $msg['timestamp'] ?? '',
					// Pass through replay-safe tool_outputs from the backend (web citation
					// sources, result cards) so the widget rebuilds inline pills / cards
					// on history reload.
					'tool_outputs' => ( isset($msg['tool_outputs']) && is_array($msg['tool_outputs']) ) ? $msg['tool_outputs'] : array(),
				);
			}
		}

		// Merge persisted thread events (share confirmations, errors) into the message list.
		$option_key = 'def_core_share_events_' . $thread_id;
		$thread_events = get_option($option_key, array());
		if (!empty($thread_events) && is_array($thread_events)) {
			foreach ($thread_events as $event) {
				$type = $event['type'] ?? 'share';
				if ('error' === $type) {
					$messages[] = array(
						'role'      => 'error_event',
						'content'   => $event['message'] ?? 'Unknown error',
						'timestamp' => $event['timestamp'] ?? '',
					);
				} else {
					$messages[] = array(
						'role'      => 'share_event',
						'content'   => implode(', ', $event['recipients'] ?? array()),
						'timestamp' => $event['timestamp'] ?? '',
					);
				}
			}

			// Re-sort by timestamp so events appear in correct position.
			usort($messages, function ($a, $b) {
				return strcmp($a['timestamp'] ?? '', $b['timestamp'] ?? '');
			});
		}

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'id'       => $thread_id,
				'messages' => $messages,
			),
			200
		);
	}

	/**
	 * REST handler: Send message.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response.
	 * @since 1.1.0
	 */
	/**
	 * REST handler: Upload init — proxy to DEF backend presigned URL generation.
	 *
	 * PHP validation is UX-layer filtering only (extension-based, not content inspection).
	 * The DEF backend performs authoritative content-type validation.
	 *
	 * @since 1.2.0
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_upload_init(\WP_REST_Request $request)
	{
		$body = $request->get_json_params();

		$filename  = isset($body['filename']) ? sanitize_file_name($body['filename']) : '';
		$mime_type = isset($body['mime_type']) ? sanitize_text_field($body['mime_type']) : '';
		$size      = isset($body['size_bytes']) ? absint($body['size_bytes']) : 0;
		$conv_id   = isset($body['conversation_id']) ? sanitize_text_field($body['conversation_id']) : '';

		// Validate filename.
		if (empty($filename)) {
			error_log('[DEF Upload] Rejected: empty filename from user ' . get_current_user_id());
			return new \WP_Error('invalid_filename', __('Filename is required.', 'digital-employees'), array('status' => 400));
		}

		// Validate MIME type against allowlist (UX filtering — backend validates authoritatively).
		if (! in_array($mime_type, self::UPLOAD_ALLOWED_MIME_TYPES, true)) {
			error_log('[DEF Upload] Rejected MIME type: ' . $mime_type . ' for file: ' . $filename . ' from user ' . get_current_user_id());
			return new \WP_Error(
				'unsupported_media_type',
				__('File type not supported.', 'digital-employees'),
				array('status' => 415)
			);
		}

		// Validate file size.
		if ($size <= 0 || $size > self::UPLOAD_MAX_SIZE_BYTES) {
			error_log('[DEF Upload] Rejected file size: ' . $size . ' bytes for file: ' . $filename . ' from user ' . get_current_user_id());
			return new \WP_Error(
				'payload_too_large',
				__('File exceeds maximum size of 10MB.', 'digital-employees'),
				array('status' => 413)
			);
		}

		// Proxy to backend.
		return self::backend_request('POST', '/api/staff_ai/uploads/init', array(
			'filename'        => $filename,
			'mime_type'       => $mime_type,
			'size_bytes'      => $size,
			'conversation_id' => $conv_id,
		));
	}

	/**
	 * REST handler: Upload commit — proxy to DEF backend upload finalization.
	 *
	 * @since 1.2.0
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_upload_commit(\WP_REST_Request $request)
	{
		$body    = $request->get_json_params();
		$file_id = isset($body['file_id']) ? sanitize_text_field($body['file_id']) : '';

		if (empty($file_id) || ! preg_match('/^upload_[a-f0-9]+$/', $file_id)) {
			error_log('[DEF Upload] Rejected invalid file_id: ' . $file_id . ' from user ' . get_current_user_id());
			return new \WP_Error('invalid_file_id', __('Invalid file ID.', 'digital-employees'), array('status' => 400));
		}

		return self::backend_request('POST', '/api/staff_ai/uploads/commit', array(
			'file_id' => $file_id,
		));
	}

	public static function rest_send_message(\WP_REST_Request $request)
	{
		$body = $request->get_json_params();

		$message   = isset($body['message']) ? sanitize_textarea_field($body['message']) : '';
		$thread_id = isset($body['thread_id']) ? sanitize_text_field($body['thread_id']) : null;
		$file_ids  = isset($body['file_ids']) && is_array($body['file_ids']) ? $body['file_ids'] : array();

		// Validate file_ids format.
		$validated_file_ids = array();
		foreach ($file_ids as $fid) {
			$fid = sanitize_text_field($fid);
			if (preg_match('/^upload_[a-f0-9]+$/', $fid)) {
				$validated_file_ids[] = $fid;
			}
		}

		// Message or files required.
		if (empty($message) && empty($validated_file_ids)) {
			return new \WP_Error(
				'invalid_message',
				__('Message cannot be empty.', 'digital-employees'),
				array('status' => 400)
			);
		}

		// Build message object.
		$message_obj = array(
			'role'    => 'user',
			'content' => ! empty($message) ? $message : 'Please analyze the attached file(s).',
		);

		// Attach file references if present.
		if (! empty($validated_file_ids)) {
			$message_obj['attachments'] = array_map(
				function ($fid) {
					return array('file_id' => $fid);
				},
				$validated_file_ids
			);
		}

		// Build chat request for backend.
		$chat_body = array(
			'messages' => array($message_obj),
		);

		// If continuing existing thread.
		if ($thread_id && 'temp-' !== substr($thread_id, 0, 5)) {
			$chat_body['thread_id']        = $thread_id;
			$chat_body['continue_thread']  = true;
		}

		// Staff AI uses dedicated endpoint - NOT the customer chatbot
		// Reference: STAFF_AI_CHANNEL_OVERVIEW.md
		// "Staff AI is NOT a customer chatbot and is NOT used for platform configuration"
		$result = self::backend_request('POST', '/api/staff-ai/chat', $chat_body);

		if (is_wp_error($result)) {
			return $result;
		}

		// Extract response.
		$assistant_content = '';
		$tool_outputs      = array();

		if (isset($result['choices'][0]['message']['content'])) {
			$assistant_content = $result['choices'][0]['message']['content'];
		}

		// Extract tool_outputs from Python response (for chat-invoked tools)
		if (isset($result['choices'][0]['message']['tool_outputs']) && is_array($result['choices'][0]['message']['tool_outputs'])) {
			foreach ($result['choices'][0]['message']['tool_outputs'] as $tool_output) {
				$output_type = $tool_output['type'] ?? 'file';

				if ($output_type === 'escalation_offer') {
					// Escalation offer — pass through type, reason, reason_code
					$tool_outputs[] = array(
						'type'        => 'escalation_offer',
						'reason'      => $tool_output['reason'] ?? '',
						'reason_code' => $tool_output['reason_code'] ?? 'general',
					);
				} else {
					// File output — rewrite download URL to use WordPress proxy endpoint
					$download_url = $tool_output['download_url'] ?? '';
					if (!empty($download_url) && strpos($download_url, '/api/files/') === 0) {
						// Extract tenant and filename from /api/files/{tenant}/{filename}
						$path_part = str_replace('/api/files/', '', $download_url);
						$path_parts = explode('/', $path_part, 2);
						if (count($path_parts) === 2) {
							$tenant = $path_parts[0];
							$filename = $path_parts[1];
							// Properly URL-encode the filename for the URL
							$download_url = home_url('/staff-ai-download/' . rawurlencode($tenant) . '/' . rawurlencode($filename));
						}
					}

					$tool_outputs[] = array(
						'file_name'    => $tool_output['file_name'] ?? '',
						'file_type'    => $tool_output['file_type'] ?? '',
						'download_url' => $download_url,
						'expires_at'   => $tool_output['expires_at'] ?? null,
					);
				}
			}
		}

		$channel = isset($result['channel']) ? $result['channel'] : 'staff_ai';

		return new \WP_REST_Response(
			array(
				'success'      => true,
				'thread_id'    => $result['thread_id'] ?? $thread_id,
				'channel'      => $channel,
				'tool_invoked' => $result['tool_invoked'] ?? null,
				'message'      => array(
					'role'         => 'assistant',
					'content'      => $assistant_content,
					'tool_outputs' => $tool_outputs,
				),
			),
			200
		);
	}

	/**
	 * REST handler: Summarize conversation for Share form.
	 *
	 * Proxies to Python backend /api/staff-ai/threads/{id}/summarize
	 * to get an AI-generated subject + summary.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response.
	 * @since 1.2.0
	 */
	public static function rest_summarize_conversation(\WP_REST_Request $request)
	{
		$id = $request->get_param('id');
		$response = self::backend_request(
			'POST',
			'/api/staff-ai/threads/' . rawurlencode($id) . '/summarize',
			array()
		);

		if (is_wp_error($response)) {
			return new \WP_REST_Response(
				array(
					'success'          => true,
					'suggested_subject' => __('Staff AI Conversation', 'digital-employees'),
					'summary'          => '',
					'summary_fallback' => true,
				),
				200
			);
		}

		return new \WP_REST_Response($response, 200);
	}

	/**
	 * REST handler: Share settings (proxy to escalation settings).
	 *
	 * Returns escalation settings for the staff_ai channel, using the
	 * Staff AI permission check (cookie/nonce auth) instead of the
	 * escalation endpoint's JWT Bearer auth.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 * @since 1.2.0
	 */
	public static function rest_share_settings(\WP_REST_Request $request)
	{
		// Delegate to the escalation settings handler with channel=staff_ai
		$inner_request = new \WP_REST_Request('GET', '/' . DEF_CORE_API_NAME_SPACE . '/settings/escalation');
		$inner_request->set_param('channel', 'staff_ai');
		$response = \DEF_Core_Escalation::get_escalation_settings($inner_request);

		if ($response instanceof \WP_Error) {
			return new \WP_REST_Response(
				array(
					'allowed_recipients' => array(),
					'recipient_options'  => array(),
				),
				200
			);
		}

		// Add recipient_options (with display names) for the token picker UI.
		// allowed_recipients stays as string[] for policy/validation.
		$data = $response->get_data();
		$current_user_id = get_current_user_id();

		$option_key = 'def_core_escalation_staff_ai';
		$stored     = get_option( $option_key, array() );

		if ( empty( $stored['allowed_recipients'] ) ) {
			// Auto-discovery path: query staff/management users, exclude self.
			// Suppress admin fallback — empty list is preferable to reintroducing
			// the excluded user when they're the only staff/management user.
			$result = \DEF_Core_Escalation::get_staff_management_recipients_public(
				$current_user_id,
				false
			);
			$data['recipient_options'] = $result['recipients'];
		} else {
			// Stored override path: build recipient_options from the email list.
			$current_email = strtolower( wp_get_current_user()->user_email ?? '' );
			$data['recipient_options'] = array();
			foreach ( (array) $stored['allowed_recipients'] as $email ) {
				$lower = strtolower( sanitize_email( $email ) );
				if ( ! is_email( $lower ) || $lower === $current_email ) {
					continue;
				}
				$user = get_user_by( 'email', $email );
				$data['recipient_options'][] = array(
					'email' => $lower,
					'name'  => $user ? ( $user->display_name ?: $user->user_login ) : $lower,
				);
			}
		}

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * REST handler: Share send (proxy to escalation send-email).
	 *
	 * Forwards the share email request to the escalation email bridge,
	 * using the Staff AI permission check (cookie/nonce auth).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 * @since 1.2.0
	 */
	public static function rest_share_send(\WP_REST_Request $request)
	{
		$body = $request->get_json_params();

		// Whitelist allowed client fields — prevent staff users from injecting
		// bcc, sender_email, user_copy_email, or other escalation fields.
		// NOTE: 'channel' is NOT in the whitelist — forced server-side below.
		$allowed_keys = array('to', 'subject', 'body');
		$safe_body = array();
		foreach ($allowed_keys as $key) {
			if (isset($body[$key])) {
				$safe_body[$key] = $body[$key];
			}
		}

		// Force channel=staff_ai server-side. This endpoint is exclusively
		// for Staff AI sharing — never allow the client to drive a different
		// escalation channel through this route (confused-deputy prevention).
		$safe_body['channel'] = 'staff_ai';

		// Inject current user's email as Reply-To so recipients can reply
		// to the person who shared. Server-side injection (not from client
		// input) — safe because auth is already verified via cookie/nonce.
		$current_user = wp_get_current_user();
		if ( $current_user && ! empty( $current_user->user_email ) ) {
			$reply_to = sanitize_email( $current_user->user_email );
			if ( is_email( $reply_to ) ) {
				$safe_body['reply_to'] = $reply_to;
			}
		}

		// Delegate to the escalation send-email handler
		$inner_request = new \WP_REST_Request('POST', '/' . DEF_CORE_API_NAME_SPACE . '/escalation/send-email');
		$inner_request->set_header('Content-Type', 'application/json');
		$inner_request->set_body(wp_json_encode($safe_body));
		foreach ($safe_body as $key => $value) {
			$inner_request->set_param($key, $value);
		}

		return \DEF_Core_Escalation::send_escalation_email($inner_request);
	}

	/**
	 * REST handler: Store a thread event (share confirmation or error).
	 *
	 * Persists events as banners in the conversation thread across page loads.
	 * Supported types: "share" (green) and "error" (red).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 * @since 1.2.0
	 */
	public static function rest_store_share_event(\WP_REST_Request $request)
	{
		$thread_id = sanitize_text_field($request->get_param('id'));
		$body = $request->get_json_params();

		$type = sanitize_text_field($body['type'] ?? 'share');
		$timestamp = isset($body['timestamp']) ? sanitize_text_field($body['timestamp']) : gmdate('c');

		if ('error' === $type) {
			$message = sanitize_text_field($body['message'] ?? 'Unknown error');
			$event = array(
				'type'      => 'error',
				'message'   => $message,
				'timestamp' => $timestamp,
				'user_id'   => get_current_user_id(),
			);
		} else {
			$recipients = isset($body['recipients']) ? array_map('sanitize_email', (array) $body['recipients']) : array();
			if (empty($recipients)) {
				return new \WP_REST_Response(
					array('success' => false, 'message' => 'No recipients provided.'),
					400
				);
			}
			$event = array(
				'type'       => 'share',
				'recipients' => $recipients,
				'timestamp'  => $timestamp,
				'user_id'    => get_current_user_id(),
			);
		}

		$option_key = 'def_core_share_events_' . $thread_id;
		$events = get_option($option_key, array());

		// Cap at 100 events per thread to prevent unbounded storage growth.
		if (count($events) >= 100) {
			$events = array_slice($events, -99);
		}

		$events[] = $event;
		update_option($option_key, $events, false); // no autoload

		return new \WP_REST_Response(array('success' => true), 200);
	}

	/**
	 * REST handler: Status check for debugging.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response with status info.
	 * @since 1.1.0
	 */
	public static function rest_status(\WP_REST_Request $request)
	{
		$user     = wp_get_current_user();
		$base_url = self::get_api_base_url();

		$status = array(
			'success'      => true,
			'user'         => array(
				'id'    => $user->ID,
				'email' => $user->user_email,
			),
			'capabilities' => array(
				'def_staff_access'      => $user->has_cap('def_staff_access'),
				'def_management_access' => $user->has_cap('def_management_access'),
			),
			'config'       => array(
				'api_url_configured' => ! empty($base_url),
				'api_url'            => $base_url,
				'jwks_url'           => rest_url(DEF_CORE_API_NAME_SPACE . '/jwks'),
				'issuer'             => get_site_url(),
			),
		);

		// Test token generation.
		$capabilities = array();
		if ($user->has_cap('def_staff_access')) {
			$capabilities[] = 'def_staff_access';
		}
		if ($user->has_cap('def_management_access')) {
			$capabilities[] = 'def_management_access';
		}

		$claims = array(
			'sub'          => (string) $user->ID,
			'email'        => $user->user_email,
			'capabilities' => $capabilities,
			'channel'      => 'staff_ai',
			'iss'          => get_site_url(),
			'aud'          => 'digital-employee-framework',
		);

		$token = DEF_Core_JWT::issue_token($claims, 300);
		$status['token_generation'] = ! empty($token) ? 'ok' : 'failed';

		// Test backend connectivity if configured.
		if (! empty($base_url) && ! empty($token)) {
			// Test 1: Health endpoint
			$health_url  = $base_url . '/api/health';
			$test_args   = array(
				'timeout'     => 10,
				'httpversion' => '1.1',
				'headers'     => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			);

			$health_response = wp_remote_get($health_url, $test_args);

			if (is_wp_error($health_response)) {
				$status['health_check'] = 'error: ' . $health_response->get_error_message();
			} else {
				$health_status = wp_remote_retrieve_response_code($health_response);
				$status['health_check'] = 'status_' . $health_status;
			}

			// Test 2: Staff AI Status endpoint (validates Staff AI routing)
			$threads_url      = $base_url . '/api/staff-ai/status';
			$threads_response = wp_remote_get($threads_url, $test_args);

			if (is_wp_error($threads_response)) {
				$status['threads_check'] = 'error: ' . $threads_response->get_error_message();
			} else {
				$threads_status = wp_remote_retrieve_response_code($threads_response);
				$threads_body   = wp_remote_retrieve_body($threads_response);
				$threads_data   = json_decode($threads_body, true);

				$status['threads_check'] = array(
					'status' => $threads_status,
					'detail' => isset($threads_data['detail']) ? $threads_data['detail'] : null,
				);
			}
		} else {
			$status['health_check']  = 'not_tested';
			$status['threads_check'] = 'not_tested';
		}

		return new \WP_REST_Response($status, 200);
	}

	/**
	 * REST handler: List available tools.
	 *
	 * Proxies to Python backend /api/staff-ai/tools
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response with tools list.
	 * @since 1.2.0
	 */
	public static function rest_list_tools(\WP_REST_Request $request)
	{
		$result = self::backend_request('GET', '/api/staff-ai/tools');

		if (is_wp_error($result)) {
			$code    = $result->get_error_code();
			$message = $result->get_error_message();
			$data    = $result->get_error_data();
			$status  = isset($data['status']) ? (int) $data['status'] : 500;

			return new \WP_REST_Response(
				array(
					'code'    => $code,
					'message' => $message,
					'data'    => array('status' => $status),
				),
				$status
			);
		}

		return new \WP_REST_Response($result, 200);
	}

	/**
	 * REST handler: Download a generated file.
	 *
	 * Proxies file download from Python backend /api/files/{tenant}/{filename}
	 * Uses the same authentication pattern as backend_request().
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error File response or error.
	 * @since 1.2.0
	 */
	public static function rest_download_file(\WP_REST_Request $request)
	{
		$tenant   = $request->get_param('tenant');
		$filename = $request->get_param('filename');

		if (empty($tenant) || empty($filename)) {
			return new \WP_Error(
				'invalid_params',
				__('Invalid file path.', 'digital-employees'),
				array('status' => 400)
			);
		}

		// Get backend URL.
		$base_url = self::get_api_base_url();
		if (! $base_url) {
			return new \WP_Error(
				'staff_ai_not_configured',
				__('Staff AI backend URL is not configured.', 'digital-employees'),
				array('status' => 503)
			);
		}

		$file_url = $base_url . '/api/files/' . urlencode($tenant) . '/' . rawurlencode($filename);

		// Build JWT claims for backend auth (same as backend_request).
		$user = wp_get_current_user();
		if (! $user || 0 === $user->ID) {
			return new \WP_Error(
				'staff_ai_not_authenticated',
				__('User not authenticated.', 'digital-employees'),
				array('status' => 401)
			);
		}

		$capabilities = array();
		if ($user->has_cap('def_staff_access')) {
			$capabilities[] = 'def_staff_access';
		}
		if ($user->has_cap('def_management_access')) {
			$capabilities[] = 'def_management_access';
		}

		$claims = array(
			'sub'          => (string) $user->ID,
			'email'        => $user->user_email,
			'capabilities' => $capabilities,
			'channel'      => 'staff_ai',
			'iss'          => get_site_url(),
			'aud'          => 'digital-employee-framework',
		);

		$token = DEF_Core_JWT::issue_token($claims, 300);
		if (empty($token)) {
			return new \WP_Error(
				'staff_ai_token_error',
				__('Failed to generate authentication token.', 'digital-employees'),
				array('status' => 500)
			);
		}

		// Fetch file from backend.
		$response = wp_remote_get(
			$file_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if (is_wp_error($response)) {
			return new \WP_Error(
				'download_failed',
				__('Failed to download file from backend.', 'digital-employees'),
				array('status' => 500)
			);
		}

		$status_code = wp_remote_retrieve_response_code($response);
		if ($status_code !== 200) {
			return new \WP_Error(
				'file_not_found',
				__('File not found or access denied.', 'digital-employees'),
				array('status' => $status_code)
			);
		}

		$body         = wp_remote_retrieve_body($response);
		$content_type = wp_remote_retrieve_header($response, 'content-type');

		// Extract clean filename (remove timestamp prefix).
		$clean_filename = $filename;
		if (preg_match('/^\d{8}_\d{6}_[a-f0-9]+_(.+)$/', $filename, $matches)) {
			$clean_filename = $matches[1];
		}

		// SECURITY: Sanitize Content-Type — only allow safe MIME types, block text/html.
		$safe_content_type = self::sanitize_proxy_content_type( $content_type );

		// SECURITY: Sanitize filename — strip anything that could inject headers.
		$safe_filename = self::sanitize_proxy_filename( $clean_filename );

		// Send file response with security headers.
		header( 'Content-Type: ' . $safe_content_type );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-cache, must-revalidate' );
		echo $body;
		exit;
	}

	/**
	 * Add rewrite rules for /staff-ai endpoint.
	 */
	public static function add_rewrite_rules(): void
	{
		add_rewrite_rule(
			'^' . self::ENDPOINT_SLUG . '/?$',
			'index.php?' . self::ENDPOINT_SLUG . '=1',
			'top'
		);

		// PWA manifest and service worker.
		add_rewrite_rule(
			'^' . self::ENDPOINT_SLUG . '/manifest\.json$',
			'index.php?staff_ai_pwa=manifest',
			'top'
		);
		add_rewrite_rule(
			'^' . self::ENDPOINT_SLUG . '/sw\.js$',
			'index.php?staff_ai_pwa=sw',
			'top'
		);
		add_rewrite_rule(
			'^' . self::ENDPOINT_SLUG . '/icon\.svg$',
			'index.php?staff_ai_pwa=icon',
			'top'
		);

		// File download endpoint (uses cookie auth, not REST nonce).
		add_rewrite_rule(
			'^staff-ai-download/([^/]+)/(.+)$',
			'index.php?staff_ai_download=1&staff_ai_tenant=$matches[1]&staff_ai_filename=$matches[2]',
			'top'
		);
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Existing query vars.
	 * @return array Modified query vars.
	 */
	public static function add_query_vars(array $vars): array
	{
		$vars[] = self::ENDPOINT_SLUG;
		$vars[] = 'staff_ai_download';
		$vars[] = 'staff_ai_tenant';
		$vars[] = 'staff_ai_filename';
		$vars[] = 'staff_ai_pwa';
		return $vars;
	}

	/**
	 * Handle the /staff-ai endpoint request.
	 */
	public static function handle_endpoint(): void
	{
		// Handle PWA assets (manifest, service worker, icon) — no auth required.
		$pwa_asset = get_query_var('staff_ai_pwa');
		if ($pwa_asset) {
			self::handle_pwa_asset($pwa_asset);
			return;
		}

		// Handle file download endpoint.
		if (get_query_var('staff_ai_download')) {
			self::handle_file_download();
			return;
		}

		if (! get_query_var(self::ENDPOINT_SLUG)) {
			return;
		}

		// Authentication gate: redirect to login if not authenticated.
		if (! is_user_logged_in()) {
			$redirect_url = home_url('/' . self::ENDPOINT_SLUG);
			wp_safe_redirect(wp_login_url($redirect_url));
			exit;
		}

		// Capability gate: check for def_staff_access OR def_management_access.
		if (! self::user_has_staff_ai_access()) {
			self::render_access_denied();
			exit;
		}

		// Render the Staff AI shell.
		self::render_shell();
		exit;
	}

	/**
	 * Handle file download from Python backend.
	 *
	 * Uses WordPress cookie authentication (not REST nonce) so direct links work.
	 *
	 * @since 1.2.0
	 */
	private static function handle_file_download(): void
	{
		$tenant   = get_query_var('staff_ai_tenant');
		$filename = get_query_var('staff_ai_filename');

		// Strip trailing slash that WordPress might add
		$filename = rtrim($filename, '/');

		// URL decode in case it's still encoded
		$filename = urldecode($filename);
		$tenant   = urldecode($tenant);

		if (empty($tenant) || empty($filename)) {
			wp_die(__('Invalid file path.', 'digital-employees'), __('Error', 'digital-employees'), array('response' => 400));
		}

		// Authentication gate.
		if (! is_user_logged_in()) {
			wp_die(__('Authentication required.', 'digital-employees'), __('Unauthorized', 'digital-employees'), array('response' => 401));
		}

		// Capability gate.
		if (! self::user_has_staff_ai_access()) {
			wp_die(__('Access denied. You need Staff AI access.', 'digital-employees'), __('Forbidden', 'digital-employees'), array('response' => 403));
		}

		// Get backend URL.
		$base_url = self::get_api_base_url();
		if (! $base_url) {
			wp_die(__('Staff AI backend not configured.', 'digital-employees'), __('Error', 'digital-employees'), array('response' => 503));
		}

		// Build the file URL - rawurlencode to handle spaces and special chars
		$file_url = $base_url . '/api/files/' . rawurlencode($tenant) . '/' . rawurlencode($filename);

		// Debug log for troubleshooting
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[DEF Staff AI Download] Tenant: ' . $tenant);
			error_log('[DEF Staff AI Download] Filename: ' . $filename);
			error_log('[DEF Staff AI Download] Backend URL: ' . $file_url);
		}

		// Build JWT for backend auth.
		$user         = wp_get_current_user();
		$capabilities = array();
		if ($user->has_cap('def_staff_access')) {
			$capabilities[] = 'def_staff_access';
		}
		if ($user->has_cap('def_management_access')) {
			$capabilities[] = 'def_management_access';
		}

		$claims = array(
			'sub'          => (string) $user->ID,
			'email'        => $user->user_email,
			'capabilities' => $capabilities,
			'channel'      => 'staff_ai',
			'iss'          => get_site_url(),
			'aud'          => 'digital-employee-framework',
		);

		$token = DEF_Core_JWT::issue_token($claims, 300);
		if (empty($token)) {
			wp_die(__('Failed to generate token.', 'digital-employees'), __('Error', 'digital-employees'), array('response' => 500));
		}

		// Fetch file from backend.
		$response = wp_remote_get(
			$file_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if (is_wp_error($response)) {
			wp_die(__('Failed to download file.', 'digital-employees'), __('Error', 'digital-employees'), array('response' => 500));
		}

		$status_code = wp_remote_retrieve_response_code($response);
		if ($status_code !== 200) {
			$error_body = wp_remote_retrieve_body($response);
			$error_msg  = __('File not found or access denied.', 'digital-employees');
			// Add debug info in development
			if (defined('WP_DEBUG') && WP_DEBUG) {
				$error_msg .= ' (HTTP ' . intval( $status_code ) . ': ' . esc_html( substr( $error_body, 0, 200 ) ) . ')';
			}
			wp_die($error_msg, __('Error', 'digital-employees'), array('response' => $status_code));
		}

		$body         = wp_remote_retrieve_body($response);
		$content_type = wp_remote_retrieve_header($response, 'content-type');

		// Extract clean filename (remove timestamp prefix).
		$clean_filename = $filename;
		if (preg_match('/^\d{8}_\d{6}_[a-f0-9]+_(.+)$/', $filename, $matches)) {
			$clean_filename = $matches[1];
		}

		// SECURITY: Sanitize Content-Type — only allow safe MIME types, block text/html.
		$safe_content_type = self::sanitize_proxy_content_type( $content_type );

		// SECURITY: Sanitize filename — strip anything that could inject headers.
		$safe_filename = self::sanitize_proxy_filename( $clean_filename );

		// Send file response with security headers.
		nocache_headers();
		header( 'Content-Type: ' . $safe_content_type );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $body;
		exit;
	}

	/**
	 * Sanitize Content-Type for proxied file downloads.
	 *
	 * Prevents Content-Type reflection XSS by rejecting types that could
	 * execute scripts in the browser (text/html, application/xhtml+xml, etc.).
	 * Forces application/octet-stream for any unrecognized or dangerous type.
	 *
	 * @param string $content_type The Content-Type from the backend response.
	 * @return string A safe Content-Type string.
	 * @since 1.2.0
	 */
	private static function sanitize_proxy_content_type( string $content_type ): string {
		if ( empty( $content_type ) ) {
			return 'application/octet-stream';
		}

		// Extract the base MIME type (strip charset, boundary, etc.).
		$base = strtolower( trim( explode( ';', $content_type )[0] ) );

		// Blocklist: types that can execute scripts in the browser.
		$dangerous_types = array(
			'text/html',
			'application/xhtml+xml',
			'application/xml',
			'text/xml',
			'image/svg+xml',
			'application/javascript',
			'text/javascript',
			'application/x-javascript',
		);

		if ( in_array( $base, $dangerous_types, true ) ) {
			return 'application/octet-stream';
		}

		// Reject anything that does not look like a valid MIME type.
		if ( ! preg_match( '~^[a-z0-9][a-z0-9!#$&\-^_.+]*/[a-z0-9][a-z0-9!#$&\-^_.+]*$~', $base ) ) {
			return 'application/octet-stream';
		}

		return $base;
	}

	/**
	 * Sanitize filename for Content-Disposition header.
	 *
	 * Prevents HTTP header injection by stripping control characters,
	 * newlines, quotes, and any non-printable characters from the filename.
	 * Falls back to 'download' if nothing remains.
	 *
	 * @param string $filename The raw filename.
	 * @return string A safe filename for use in Content-Disposition.
	 * @since 1.2.0
	 */
	private static function sanitize_proxy_filename( string $filename ): string {
		// Strip any characters that could inject headers or break the Content-Disposition value.
		// Remove: control chars (0x00-0x1F, 0x7F), double quotes, backslashes, newlines.
		$safe = preg_replace( '/[\x00-\x1F\x7F"\\\\]/', '', $filename );

		// Also strip path separators to prevent path traversal in save dialogs.
		$safe = str_replace( array( '/', '\\' ), '', $safe );

		// Trim whitespace and dots (Windows disallows trailing dots).
		$safe = trim( $safe, " \t\n\r\0\x0B." );

		// Fallback if nothing remains.
		if ( empty( $safe ) ) {
			$safe = 'download';
		}

		return $safe;
	}

	/**
	 * Check if current user has Staff AI access.
	 *
	 * @return bool True if user has def_staff_access OR def_management_access.
	 */
	public static function user_has_staff_ai_access(): bool
	{
		$user = wp_get_current_user();
		if (! $user || ! $user->exists()) {
			return false;
		}

		return $user->has_cap('def_staff_access') || $user->has_cap('def_management_access');
	}

	/**
	 * Render the access denied page.
	 */
	private static function render_access_denied(): void
	{
		http_response_code(403);
?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>

		<head>
			<meta charset="<?php bloginfo('charset'); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html__('Access Denied', 'digital-employees'); ?> - <?php bloginfo('name'); ?></title>
			<style>
				* {
					margin: 0;
					padding: 0;
					box-sizing: border-box;
				}

				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
					background: #f0f0f1;
					color: #3c434a;
					display: flex;
					align-items: center;
					justify-content: center;
					min-height: 100vh;
					padding: 20px;
				}

				.access-denied {
					background: #fff;
					border: 1px solid #c3c4c7;
					border-radius: 4px;
					padding: 40px;
					max-width: 400px;
					text-align: center;
					box-shadow: 0 1px 3px rgba(0, 0, 0, .04);
				}

				.access-denied h1 {
					font-size: 1.5em;
					margin-bottom: 16px;
					color: #1d2327;
				}

				.access-denied p {
					color: #50575e;
					line-height: 1.6;
				}
			</style>
		</head>

		<body>
			<div class="access-denied">
				<h1><?php echo esc_html__('Access Denied', 'digital-employees'); ?></h1>
				<p><?php echo esc_html__('You do not have permission to access Staff AI.', 'digital-employees'); ?></p>
			</div>
		</body>

		</html>
	<?php
	}


	// ─── PWA Support ────────────────────────────────────────────────

	/**
	 * Handle PWA asset requests (manifest.json, sw.js, icon.svg).
	 *
	 * @param string $asset The asset type to serve.
	 */
	private static function handle_pwa_asset(string $asset): void
	{
		switch ($asset) {
			case 'manifest':
				self::serve_pwa_manifest();
				break;
			case 'sw':
				self::serve_pwa_service_worker();
				break;
			case 'icon':
				self::serve_pwa_icon();
				break;
			default:
				status_header(404);
				exit;
		}
	}

	/**
	 * Serve the PWA web app manifest.
	 */
	private static function serve_pwa_manifest(): void
	{
		$display_name = get_option('def_core_display_name', '');
		if (empty($display_name)) {
			$display_name = get_bloginfo('name');
		}
		$app_name = __('Staff AI', 'digital-employees');

		// Icon priority: 1. Uploaded app icon, 2. WordPress site icon, 3. Generated SVG.
		$icons = array();

		// 1. Uploaded app icon (from Branding > Web App Icon).
		$app_icon_id = (int) get_option('def_core_app_icon_id', 0);
		if ($app_icon_id) {
			$icon_192 = wp_get_attachment_image_url($app_icon_id, array(192, 192));
			$icon_512 = wp_get_attachment_image_url($app_icon_id, array(512, 512));
			if ($icon_192) {
				$icons[] = array('src' => $icon_192, 'sizes' => '192x192', 'type' => 'image/png');
			}
			if ($icon_512) {
				$icons[] = array('src' => $icon_512, 'sizes' => '512x512', 'type' => 'image/png');
			}
		}

		// 2. WordPress site icon.
		if (empty($icons)) {
			$site_icon_id = get_option('site_icon');
			if ($site_icon_id) {
				$icon_192 = wp_get_attachment_image_url((int) $site_icon_id, array(192, 192));
				$icon_512 = wp_get_attachment_image_url((int) $site_icon_id, array(512, 512));
				if ($icon_192) {
					$icons[] = array('src' => $icon_192, 'sizes' => '192x192', 'type' => 'image/png');
				}
				if ($icon_512) {
					$icons[] = array('src' => $icon_512, 'sizes' => '512x512', 'type' => 'image/png');
				}
			}
		}

		// 3. Fallback: generated SVG icon with site initials.
		if (empty($icons)) {
			$icon_url = home_url('/staff-ai/icon.svg');
			$icons[] = array('src' => $icon_url, 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any');
		}

		$manifest = array(
			'name'             => $app_name,
			'short_name'       => __('Staff AI', 'digital-employees'),
			'description'      => sprintf(
				/* translators: %s: site display name */
				__('%s Staff AI Assistant', 'digital-employees'),
				$display_name
			),
			'start_url'        => home_url('/staff-ai/'),
			'scope'            => home_url('/staff-ai/'),
			'display'          => 'standalone',
			'background_color' => '#ffffff',
			'theme_color'      => '#6366f1',
			'icons'            => $icons,
		);

		nocache_headers();
		header('Content-Type: application/manifest+json');
		echo wp_json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		exit;
	}

	/**
	 * Serve the PWA service worker.
	 * Minimal worker — just enough for PWA installability.
	 */
	private static function serve_pwa_service_worker(): void
	{
		header('Content-Type: application/javascript');
		header('Service-Worker-Allowed: /staff-ai/');
		header('Cache-Control: no-cache');
		echo <<<'JS'
// Staff AI Service Worker — enables PWA install.
const CACHE_NAME = 'staff-ai-v1';

self.addEventListener('install', function(event) {
	self.skipWaiting();
});

self.addEventListener('activate', function(event) {
	event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function(event) {
	// Network-first for all requests — Staff AI is a live app, not offline-capable.
	event.respondWith(
		fetch(event.request).catch(function() {
			return caches.match(event.request);
		})
	);
});
JS;
		exit;
	}

	/**
	 * Serve a generated SVG icon with site initials.
	 * Used as fallback when no site icon is configured.
	 */
	private static function serve_pwa_icon(): void
	{
		$display_name = get_option('def_core_display_name', get_bloginfo('name'));
		// Get first 2 initials from display name.
		$words    = preg_split('/\s+/', trim($display_name));
		$initials = '';
		foreach (array_slice($words, 0, 2) as $word) {
			$initials .= mb_strtoupper(mb_substr($word, 0, 1));
		}
		if (empty($initials)) {
			$initials = 'AI';
		}

		header('Content-Type: image/svg+xml');
		header('Cache-Control: public, max-age=86400');
		echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">';
		echo '<rect width="512" height="512" rx="96" fill="#6366f1"/>';
		echo '<text x="256" y="280" text-anchor="middle" font-family="system-ui,-apple-system,sans-serif" font-size="200" font-weight="700" fill="#fff">' . esc_html($initials) . '</text>';
		echo '</svg>';
		exit;
	}

	/**
	 * Render the Staff AI application shell.
	 *
	 * This outputs a standalone HTML document (not embedded in wp-admin).
	 * It does NOT call wp_head() / wp_footer() by design — assets are
	 * loaded via direct <link> and <script> tags in the template.
	 * Do not attempt to use wp_enqueue_style/script for this page.
	 */
	private static function render_shell(): void
	{
		// Prevent caching — page contains user-specific nonce and identifiers.
		nocache_headers();

		$user    = wp_get_current_user();
		$channel = 'staff_ai';

		// REST API data for JS.
		$api_base = rest_url( DEF_CORE_API_NAME_SPACE . '/staff-ai' );
		$nonce    = wp_create_nonce( 'wp_rest' );

		// Header logo — D-II fallback chain: def_core_logo_id → custom_logo → site name.
		$show_logo = '0' !== get_option( 'def_core_logo_show_staff_ai', '1' );
		if ( $show_logo ) {
			$logo_html = DEF_Core_Admin::get_logo_html( 32 );
		} else {
			$display_name = get_option( 'def_core_display_name', get_bloginfo( 'name' ) );
			$logo_html    = '<span class="header-logo-text">' . esc_html( $display_name ) . '</span>';
		}

		// Template expects: $channel, $user, $api_base, $nonce, $logo_html.
		$template = DEF_CORE_PLUGIN_DIR . 'templates/staff-ai-shell.php';
		if ( ! file_exists( $template ) ) {
			wp_die(
				esc_html__( 'Staff AI template not found. Please reinstall the def-core plugin.', 'digital-employees' ),
				esc_html__( 'Template Error', 'digital-employees' ),
				array( 'response' => 500 )
			);
		}
		require $template;
	}

	/**
	 * Flush rewrite rules on activation.
	 */
	public static function on_activate(): void
	{
		self::add_rewrite_rules();
		flush_rewrite_rules();
	}
}
