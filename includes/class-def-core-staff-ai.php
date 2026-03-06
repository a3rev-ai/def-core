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
				__('Authentication required.', 'def-core'),
				array('status' => 401)
			);
		}

		// Capability gate.
		if (! self::user_has_staff_ai_access()) {
			return new \WP_Error(
				'rest_forbidden',
				__('You do not have permission to access Staff AI.', 'def-core'),
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
		$url = get_option('def_core_staff_ai_api_url', '');
		return ! empty($url) ? rtrim($url, '/') : null;
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
				__('Staff AI backend URL is not configured. Go to Settings > Digital Employees to set the Staff AI API URL.', 'def-core'),
				array('status' => 503)
			);
		}

		$url = $base_url . $endpoint;

		// Build JWT claims for backend auth.
		$user = wp_get_current_user();
		if (! $user || 0 === $user->ID) {
			return new \WP_Error(
				'staff_ai_not_authenticated',
				__('User not authenticated.', 'def-core'),
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
				__('Failed to generate authentication token.', 'def-core'),
				array('status' => 500)
			);
		}

		$args = array(
			'timeout'     => 60,
			'httpversion' => '1.1',
			'headers'     => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		if ('POST' === $method) {
			$args['body'] = wp_json_encode($body);
			$response     = wp_remote_post($url, $args);
		} else {
			$response = wp_remote_get($url, $args);
		}

		if (is_wp_error($response)) {
			return new \WP_Error(
				'staff_ai_request_failed',
				__('Failed to connect to Staff AI backend.', 'def-core'),
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
					__('Network error: Could not connect to backend at %1$s. Check if the URL is correct and the server is reachable.', 'def-core'),
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
					__('Backend auth failed (HTTP %1$d). The backend may need JWKS configuration. Detail: %2$s', 'def-core'),
					$status,
					$backend_detail ? $backend_detail : 'none'
				);
			} elseif (404 === $status) {
				$error_code    = 'staff_ai_not_found';
				$error_message = sprintf(
					/* translators: 1: API endpoint path, 2: full URL */
					__('Backend endpoint not found (HTTP 404): %1$s. Full URL: %2$s - Please verify the backend API supports this endpoint.', 'def-core'),
					$endpoint,
					$url
				);
			} elseif ($status >= 500) {
				$error_code    = 'staff_ai_service_error';
				$error_message = sprintf(
					/* translators: 1: HTTP status code */
					__('Backend service error (HTTP %1$d). The service may be temporarily unavailable.', 'def-core'),
					$status
				);
			} else {
				// Any other 4xx error (400, 405, 422, etc.)
				$error_code    = 'staff_ai_http_' . $status;
				$error_message = sprintf(
					/* translators: 1: HTTP status code, 2: backend error detail, 3: full URL */
					__('Backend error (HTTP %1$d) calling %3$s: %2$s', 'def-core'),
					$status,
					$backend_detail ? $backend_detail : __('Unknown error', 'def-core'),
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
					'title'      => $thread['title'] ?? __('New conversation', 'def-core'),
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
					'tool_outputs' => array(), // Will be parsed from content if needed.
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
			return new \WP_Error('invalid_filename', __('Filename is required.', 'def-core'), array('status' => 400));
		}

		// Validate MIME type against allowlist (UX filtering — backend validates authoritatively).
		if (! in_array($mime_type, self::UPLOAD_ALLOWED_MIME_TYPES, true)) {
			error_log('[DEF Upload] Rejected MIME type: ' . $mime_type . ' for file: ' . $filename . ' from user ' . get_current_user_id());
			return new \WP_Error(
				'unsupported_media_type',
				__('File type not supported.', 'def-core'),
				array('status' => 415)
			);
		}

		// Validate file size.
		if ($size <= 0 || $size > self::UPLOAD_MAX_SIZE_BYTES) {
			error_log('[DEF Upload] Rejected file size: ' . $size . ' bytes for file: ' . $filename . ' from user ' . get_current_user_id());
			return new \WP_Error(
				'payload_too_large',
				__('File exceeds maximum size of 10MB.', 'def-core'),
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
			return new \WP_Error('invalid_file_id', __('Invalid file ID.', 'def-core'), array('status' => 400));
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
				__('Message cannot be empty.', 'def-core'),
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
					'suggested_subject' => __('Staff AI Conversation', 'def-core'),
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
				array('allowed_recipients' => array()),
				200
			);
		}

		return $response;
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

		// Whitelist allowed fields — prevent staff users from injecting
		// bcc, sender_email, user_copy_email, or other escalation fields.
		$allowed_keys = array('channel', 'to', 'subject', 'body');
		$safe_body = array();
		foreach ($allowed_keys as $key) {
			if (isset($body[$key])) {
				$safe_body[$key] = $body[$key];
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
				__('Invalid file path.', 'def-core'),
				array('status' => 400)
			);
		}

		// Get backend URL.
		$base_url = self::get_api_base_url();
		if (! $base_url) {
			return new \WP_Error(
				'staff_ai_not_configured',
				__('Staff AI backend URL is not configured.', 'def-core'),
				array('status' => 503)
			);
		}

		$file_url = $base_url . '/api/files/' . urlencode($tenant) . '/' . rawurlencode($filename);

		// Build JWT claims for backend auth (same as backend_request).
		$user = wp_get_current_user();
		if (! $user || 0 === $user->ID) {
			return new \WP_Error(
				'staff_ai_not_authenticated',
				__('User not authenticated.', 'def-core'),
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
				__('Failed to generate authentication token.', 'def-core'),
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
				__('Failed to download file from backend.', 'def-core'),
				array('status' => 500)
			);
		}

		$status_code = wp_remote_retrieve_response_code($response);
		if ($status_code !== 200) {
			return new \WP_Error(
				'file_not_found',
				__('File not found or access denied.', 'def-core'),
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
			wp_die(__('Invalid file path.', 'def-core'), __('Error', 'def-core'), array('response' => 400));
		}

		// Authentication gate.
		if (! is_user_logged_in()) {
			wp_die(__('Authentication required.', 'def-core'), __('Unauthorized', 'def-core'), array('response' => 401));
		}

		// Capability gate.
		if (! self::user_has_staff_ai_access()) {
			wp_die(__('Access denied. You need Staff AI access.', 'def-core'), __('Forbidden', 'def-core'), array('response' => 403));
		}

		// Get backend URL.
		$base_url = self::get_api_base_url();
		if (! $base_url) {
			wp_die(__('Staff AI backend not configured.', 'def-core'), __('Error', 'def-core'), array('response' => 503));
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
			wp_die(__('Failed to generate token.', 'def-core'), __('Error', 'def-core'), array('response' => 500));
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
			wp_die(__('Failed to download file.', 'def-core'), __('Error', 'def-core'), array('response' => 500));
		}

		$status_code = wp_remote_retrieve_response_code($response);
		if ($status_code !== 200) {
			$error_body = wp_remote_retrieve_body($response);
			$error_msg  = __('File not found or access denied.', 'def-core');
			// Add debug info in development
			if (defined('WP_DEBUG') && WP_DEBUG) {
				$error_msg .= ' (HTTP ' . intval( $status_code ) . ': ' . esc_html( substr( $error_body, 0, 200 ) ) . ')';
			}
			wp_die($error_msg, __('Error', 'def-core'), array('response' => $status_code));
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
			<title><?php echo esc_html__('Access Denied', 'def-core'); ?> - <?php bloginfo('name'); ?></title>
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
				<h1><?php echo esc_html__('Access Denied', 'def-core'); ?></h1>
				<p><?php echo esc_html__('You do not have permission to access Staff AI.', 'def-core'); ?></p>
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
		$app_name = !empty($display_name)
			? $display_name . ' ' . __('Staff AI', 'def-core')
			: __('Staff AI', 'def-core');

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
			'short_name'       => __('Staff AI', 'def-core'),
			'description'      => sprintf(
				/* translators: %s: site display name */
				__('%s Staff AI Assistant', 'def-core'),
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
				esc_html__( 'Staff AI template not found. Please reinstall the def-core plugin.', 'def-core' ),
				esc_html__( 'Template Error', 'def-core' ),
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
