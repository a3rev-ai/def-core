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
		// Don't redirect if this is a file download request
		if (get_query_var('staff_ai_download')) {
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

		// Export conversation.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/conversations/(?P<id>[a-zA-Z0-9_-]+)/export',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_export_conversation'),
			)
		);

		// Escalate.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/escalate',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_escalate'),
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
		$thread_id = $request->get_param('id');
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
	public static function rest_send_message(\WP_REST_Request $request)
	{
		$body = $request->get_json_params();

		$message   = isset($body['message']) ? sanitize_textarea_field($body['message']) : '';
		$thread_id = isset($body['thread_id']) ? sanitize_text_field($body['thread_id']) : null;

		if (empty($message)) {
			return new \WP_Error(
				'invalid_message',
				__('Message cannot be empty.', 'def-core'),
				array('status' => 400)
			);
		}

		// Build chat request for backend.
		$chat_body = array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => $message,
				),
			),
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

		// Delegate to the escalation send-email handler
		$inner_request = new \WP_REST_Request('POST', '/' . DEF_CORE_API_NAME_SPACE . '/escalation/send-email');
		$inner_request->set_header('Content-Type', 'application/json');
		$inner_request->set_body(wp_json_encode($body));
		// Also set JSON params directly so get_json_params() works reliably
		foreach ($body as $key => $value) {
			$inner_request->set_param($key, $value);
		}

		return \DEF_Core_Escalation::send_escalation_email($inner_request);
	}

	/**
	 * REST handler: Export conversation.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response.
	 * @since 1.1.0
	 */
	public static function rest_export_conversation(\WP_REST_Request $request)
	{
		// Export endpoint not yet implemented in backend.
		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __('Export not yet available.', 'def-core'),
			),
			200
		);
	}

	/**
	 * REST handler: Escalate.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response.
	 * @since 1.1.0
	 */
	public static function rest_escalate(\WP_REST_Request $request)
	{
		// Escalate endpoint not yet implemented in backend.
		// Return success stub - conversation remains active (non-terminal).
		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __('Escalated for review — you can continue working while this is reviewed.', 'def-core'),
			),
			200
		);
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
		return $vars;
	}

	/**
	 * Handle the /staff-ai endpoint request.
	 */
	public static function handle_endpoint(): void
	{
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

	/**
	 * Render the Staff AI shell.
	 */
	private static function render_shell(): void
	{
		$user    = wp_get_current_user();
		$channel = 'staff_ai';

		// REST API data for JS - Staff AI adapter endpoints.
		$api_base = rest_url(DEF_CORE_API_NAME_SPACE . '/staff-ai');
		$nonce    = wp_create_nonce('wp_rest');

		// Header logo - use custom_logo or fall back to site name.
		$custom_logo_id = get_theme_mod('custom_logo');
		$logo_html      = '';
		if ($custom_logo_id) {
			$logo_html = wp_get_attachment_image($custom_logo_id, 'full', false, array(
				'class' => 'header-logo-img',
				'style' => 'max-height: 32px; width: auto;',
			));
		}
		if (empty($logo_html)) {
			$logo_html = '<span class="header-logo-text">' . esc_html(get_bloginfo('name')) . '</span>';
		}
	?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>

		<head>
			<meta charset="<?php bloginfo('charset'); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html__('Staff AI', 'def-core'); ?> - <?php bloginfo('name'); ?></title>
			<style>
				* {
					margin: 0;
					padding: 0;
					box-sizing: border-box;
				}

				html,
				body {
					height: 100%;
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
					background: #343541;
					color: #ececf1;
				}

				#staff-ai-app {
					display: flex;
					height: 100vh;
					overflow: hidden;
				}

				/* Sidebar */
				.sidebar {
					width: 260px;
					background: #202123;
					display: flex;
					flex-direction: column;
					flex-shrink: 0;
					transition: transform 0.2s ease;
				}

				.sidebar-header {
					padding: 12px;
					border-bottom: 1px solid rgba(255, 255, 255, 0.1);
				}

				.new-chat-btn {
					width: 100%;
					padding: 12px 16px;
					background: transparent;
					border: 1px solid rgba(255, 255, 255, 0.2);
					border-radius: 6px;
					color: #fff;
					font-size: 14px;
					cursor: pointer;
					display: flex;
					align-items: center;
					gap: 8px;
					transition: background 0.15s;
				}

				.new-chat-btn:hover {
					background: rgba(255, 255, 255, 0.1);
				}

				.new-chat-btn svg {
					width: 16px;
					height: 16px;
				}

				.conversation-list {
					flex: 1;
					overflow-y: auto;
					padding: 8px;
				}

				.conversation-list-placeholder {
					padding: 16px;
					color: rgba(255, 255, 255, 0.5);
					font-size: 13px;
					text-align: center;
				}

				.conversation-item {
					display: block;
					width: 100%;
					padding: 10px 12px;
					background: transparent;
					border: none;
					border-radius: 6px;
					color: rgba(255, 255, 255, 0.8);
					font-size: 13px;
					text-align: left;
					cursor: pointer;
					margin-bottom: 2px;
					transition: background 0.15s;
				}

				.conversation-item:hover {
					background: rgba(255, 255, 255, 0.1);
				}

				.conversation-item.active {
					background: rgba(255, 255, 255, 0.15);
				}

				.conversation-item-title {
					display: block;
					white-space: nowrap;
					overflow: hidden;
					text-overflow: ellipsis;
					margin-bottom: 2px;
				}

				.conversation-item-time {
					font-size: 11px;
					color: rgba(255, 255, 255, 0.4);
				}

				.sidebar-footer {
					padding: 12px;
					border-top: 1px solid rgba(255, 255, 255, 0.1);
					font-size: 11px;
					color: rgba(255, 255, 255, 0.5);
					text-align: center;
				}

				/* Main chat area */
				.chat-container {
					flex: 1;
					display: flex;
					flex-direction: column;
					min-width: 0;
				}

				.chat-header {
					padding: 12px 20px;
					background: #343541;
					border-bottom: 1px solid rgba(255, 255, 255, 0.1);
					display: flex;
					align-items: center;
					gap: 12px;
				}

				.menu-toggle {
					display: none;
					background: none;
					border: none;
					color: #fff;
					cursor: pointer;
					padding: 4px;
				}

				.menu-toggle svg {
					width: 20px;
					height: 20px;
				}

				.header-actions {
					display: flex;
					gap: 8px;
				}

				.header-btn {
					background: transparent;
					border: 1px solid rgba(255, 255, 255, 0.2);
					border-radius: 6px;
					color: rgba(255, 255, 255, 0.7);
					padding: 6px 12px;
					font-size: 12px;
					cursor: pointer;
					transition: background 0.15s, color 0.15s;
				}

				.header-btn:hover {
					background: rgba(255, 255, 255, 0.1);
					color: #fff;
				}

				.header-btn:disabled {
					opacity: 0.5;
					cursor: not-allowed;
				}

				/* Read-only indicator */
				.readonly-indicator {
					display: none;
					background: rgba(251, 191, 36, 0.15);
					color: #fbbf24;
					padding: 4px 10px;
					border-radius: 4px;
					font-size: 11px;
				}

				.readonly-indicator.visible {
					display: inline-block;
				}

				/* Messages area */
				.messages-container {
					flex: 1;
					overflow-y: auto;
					padding: 0;
				}

				.messages-list {
					max-width: 768px;
					margin: 0 auto;
					padding: 20px;
				}

				.message {
					padding: 20px 0;
					display: flex;
					gap: 16px;
				}

				.message+.message {
					border-top: 1px solid rgba(255, 255, 255, 0.1);
				}

				.message-avatar {
					width: 30px;
					height: 30px;
					border-radius: 4px;
					display: flex;
					align-items: center;
					justify-content: center;
					font-size: 12px;
					font-weight: 600;
					flex-shrink: 0;
				}

				.message-user .message-avatar {
					background: #5436da;
					color: #fff;
				}

				.message-assistant .message-avatar {
					background: #19c37d;
					color: #fff;
				}

				.message-content {
					flex: 1;
					line-height: 1.6;
					white-space: pre-wrap;
					word-break: break-word;
				}

				/* Tool output card */
				.tool-output-card {
					background: #40414f;
					border: 1px solid rgba(255, 255, 255, 0.1);
					border-radius: 8px;
					padding: 12px 16px;
					margin-top: 8px;
					display: flex;
					align-items: center;
					gap: 12px;
				}

				.tool-output-icon {
					width: 36px;
					height: 36px;
					background: rgba(255, 255, 255, 0.1);
					border-radius: 6px;
					display: flex;
					align-items: center;
					justify-content: center;
					flex-shrink: 0;
				}

				.tool-output-icon svg {
					width: 18px;
					height: 18px;
					color: rgba(255, 255, 255, 0.7);
				}

				.tool-output-info {
					flex: 1;
					min-width: 0;
				}

				.tool-output-name {
					font-size: 13px;
					font-weight: 500;
					color: #fff;
					white-space: nowrap;
					overflow: hidden;
					text-overflow: ellipsis;
				}

				.tool-output-type {
					font-size: 11px;
					color: rgba(255, 255, 255, 0.5);
					text-transform: uppercase;
				}

				.tool-output-download {
					background: #19c37d;
					color: #fff;
					padding: 6px 12px;
					border-radius: 4px;
					font-size: 12px;
					text-decoration: none;
					flex-shrink: 0;
					transition: background 0.15s;
				}

				.tool-output-download:hover {
					background: #1a9d6a;
				}

				.welcome-message {
					text-align: center;
					padding: 60px 20px;
					color: rgba(255, 255, 255, 0.6);
				}

				.welcome-message h2 {
					font-size: 28px;
					font-weight: 600;
					color: #fff;
					margin-bottom: 8px;
				}

				.typing-indicator {
					display: flex;
					gap: 4px;
					padding: 8px 0;
				}

				.typing-indicator span {
					width: 8px;
					height: 8px;
					background: rgba(255, 255, 255, 0.4);
					border-radius: 50%;
					animation: typing 1.4s infinite ease-in-out;
				}

				.typing-indicator span:nth-child(2) {
					animation-delay: 0.2s;
				}

				.typing-indicator span:nth-child(3) {
					animation-delay: 0.4s;
				}

				@keyframes typing {

					0%,
					60%,
					100% {
						transform: translateY(0);
					}

					30% {
						transform: translateY(-4px);
					}
				}

				/* Banners */
				.info-banner {
					background: rgba(34, 197, 94, 0.1);
					border: 1px solid rgba(34, 197, 94, 0.3);
					color: #86efac;
					padding: 12px 16px;
					max-width: 768px;
					margin: 0 auto 16px;
					border-radius: 8px;
					font-size: 13px;
					display: none;
				}

				.info-banner.visible {
					display: block;
				}

				.error-banner {
					background: rgba(239, 68, 68, 0.1);
					border: 1px solid rgba(239, 68, 68, 0.3);
					color: #fca5a5;
					padding: 12px 16px;
					max-width: 768px;
					margin: 0 auto 16px;
					border-radius: 8px;
					font-size: 13px;
					display: none;
				}

				.error-banner.visible {
					display: block;
				}

				/* Composer */
				.composer-container {
					padding: 16px 20px 24px;
					background: #343541;
				}

				.composer-container.disabled .composer {
					opacity: 0.6;
					pointer-events: none;
				}

				.composer-wrapper {
					max-width: 768px;
					margin: 0 auto;
				}

				.composer-row {
					display: flex;
					align-items: flex-end;
					gap: 8px;
				}

				.composer {
					flex: 1;
					display: flex;
					align-items: flex-end;
					background: #40414f;
					border: 1px solid rgba(255, 255, 255, 0.1);
					border-radius: 12px;
					padding: 12px 16px;
					gap: 12px;
				}

				.composer:focus-within {
					border-color: rgba(255, 255, 255, 0.3);
				}

				.composer-input {
					flex: 1;
					background: transparent;
					border: none;
					color: #fff;
					font-size: 15px;
					line-height: 1.5;
					resize: none;
					min-height: 24px;
					max-height: 200px;
					outline: none;
					font-family: inherit;
				}

				.composer-input::placeholder {
					color: rgba(255, 255, 255, 0.4);
				}

				.send-btn {
					background: #19c37d;
					border: none;
					border-radius: 6px;
					color: #fff;
					width: 32px;
					height: 32px;
					cursor: pointer;
					display: flex;
					align-items: center;
					justify-content: center;
					transition: background 0.15s, opacity 0.15s;
					flex-shrink: 0;
				}

				.send-btn:hover {
					background: #1a9d6a;
				}

				.send-btn:disabled {
					opacity: 0.5;
					cursor: not-allowed;
				}

				.send-btn svg {
					width: 16px;
					height: 16px;
				}

				.escalation-suggestion {
					background: rgba(251, 191, 36, 0.08);
					border: 1px solid rgba(251, 191, 36, 0.3);
					border-radius: 10px;
					padding: 12px 16px;
					margin-top: 8px;
				}

				.escalation-suggestion-header {
					display: flex;
					align-items: center;
					gap: 8px;
					margin-bottom: 8px;
					color: #fbbf24;
					font-size: 13px;
					font-weight: 600;
				}

				.escalation-suggestion-header svg {
					width: 16px;
					height: 16px;
					flex-shrink: 0;
				}

				.escalation-suggestion-reason {
					font-size: 13px;
					color: rgba(255, 255, 255, 0.7);
					line-height: 1.5;
					margin-bottom: 8px;
				}

				.escalation-suggestion-hint {
					font-size: 12px;
					color: rgba(255, 255, 255, 0.4);
					font-style: italic;
				}

				.create-btn {
					background: transparent;
					border: 1px solid rgba(99, 102, 241, 0.4);
					border-radius: 8px;
					color: #818cf8;
					padding: 8px 12px;
					font-size: 12px;
					cursor: pointer;
					white-space: nowrap;
					transition: background 0.15s;
					display: flex;
					align-items: center;
					gap: 6px;
				}

				.create-btn:hover {
					background: rgba(99, 102, 241, 0.1);
				}

				.create-btn:disabled {
					opacity: 0.5;
					cursor: not-allowed;
				}

				.create-btn svg {
					width: 14px;
					height: 14px;
				}

				.composer-hint {
					text-align: center;
					font-size: 11px;
					color: rgba(255, 255, 255, 0.4);
					margin-top: 8px;
				}

				/* Modal overlay */
				.modal-overlay {
					display: none;
					position: fixed;
					inset: 0;
					background: rgba(0, 0, 0, 0.7);
					z-index: 200;
					align-items: center;
					justify-content: center;
				}

				.modal-overlay.visible {
					display: flex;
				}

				.modal {
					background: #2d2d3a;
					border-radius: 12px;
					width: 90%;
					max-width: 400px;
					padding: 24px;
					box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
				}

				.modal-header {
					display: flex;
					align-items: center;
					justify-content: space-between;
					margin-bottom: 16px;
				}

				.modal-title {
					font-size: 16px;
					font-weight: 600;
					color: #fff;
				}

				.modal-close {
					background: none;
					border: none;
					color: rgba(255, 255, 255, 0.5);
					cursor: pointer;
					padding: 4px;
					font-size: 20px;
					line-height: 1;
				}

				.modal-close:hover {
					color: #fff;
				}

				.modal-body {
					margin-bottom: 20px;
				}

				.form-group {
					margin-bottom: 16px;
				}

				.form-group:last-child {
					margin-bottom: 0;
				}

				.form-label {
					display: block;
					font-size: 13px;
					color: rgba(255, 255, 255, 0.7);
					margin-bottom: 6px;
				}

				.form-input {
					width: 100%;
					background: #40414f;
					border: 1px solid rgba(255, 255, 255, 0.1);
					border-radius: 6px;
					padding: 10px 12px;
					color: #fff;
					font-size: 14px;
					font-family: inherit;
				}

				.form-input:focus {
					outline: none;
					border-color: rgba(255, 255, 255, 0.3);
				}

				.form-input:disabled {
					opacity: 0.7;
					cursor: not-allowed;
				}

				.form-input::placeholder {
					color: rgba(255, 255, 255, 0.4);
				}

				.modal-footer {
					display: flex;
					gap: 12px;
					justify-content: flex-end;
				}

				.modal-btn {
					padding: 10px 20px;
					border-radius: 6px;
					font-size: 14px;
					cursor: pointer;
					transition: background 0.15s;
				}

				.modal-btn-secondary {
					background: transparent;
					border: 1px solid rgba(255, 255, 255, 0.2);
					color: #fff;
				}

				.modal-btn-secondary:hover {
					background: rgba(255, 255, 255, 0.1);
				}

				.modal-btn-primary {
					background: #19c37d;
					border: none;
					color: #fff;
				}

				.modal-btn-primary:hover {
					background: #1a9d6a;
				}

				.modal-btn-primary:disabled {
					opacity: 0.5;
					cursor: not-allowed;
				}

				/* Share modal states */
				.share-loading {
					display: flex;
					flex-direction: column;
					align-items: center;
					justify-content: center;
					padding: 40px 20px;
					gap: 12px;
					color: rgba(255,255,255,0.6);
				}

				.share-loading-spinner {
					width: 32px;
					height: 32px;
					border: 3px solid rgba(255,255,255,0.15);
					border-top-color: #19c37d;
					border-radius: 50%;
					animation: share-spin 0.8s linear infinite;
				}

				@keyframes share-spin {
					to { transform: rotate(360deg); }
				}

				.share-recipient-select {
					min-height: 80px;
				}

				.share-recipient-select option {
					padding: 6px 8px;
				}

				.share-recipient-select option:checked {
					background: rgba(99, 102, 241, 0.3);
				}

				.share-message-input {
					min-height: 80px;
					resize: vertical;
				}

				.share-transcript-toggle {
					padding: 0 20px 12px;
				}

				.share-toggle-label {
					display: flex;
					align-items: center;
					gap: 8px;
					font-size: 13px;
					color: rgba(255,255,255,0.6);
					cursor: pointer;
				}

				.share-paperclip-icon {
					width: 16px;
					height: 16px;
					color: rgba(255,255,255,0.4);
					flex-shrink: 0;
				}

				.share-error {
					display: flex;
					flex-direction: column;
					align-items: center;
					padding: 40px 20px;
					gap: 16px;
					color: #f87171;
					text-align: center;
				}

				/* System message (ephemeral share confirmation) */
				.message-system {
					display: flex;
					justify-content: center;
					padding: 8px 16px;
					margin: 4px 0;
				}

				.message-system-content {
					font-size: 12px;
					color: rgba(255,255,255,0.4);
					font-style: italic;
				}

				/* Responsive */
				@media (max-width: 768px) {
					.sidebar {
						position: fixed;
						left: 0;
						top: 0;
						bottom: 0;
						z-index: 100;
						transform: translateX(-100%);
					}

					.sidebar.open {
						transform: translateX(0);
					}

					.sidebar-overlay {
						display: none;
						position: fixed;
						inset: 0;
						background: rgba(0, 0, 0, 0.5);
						z-index: 99;
					}

					.sidebar-overlay.visible {
						display: block;
					}

					.menu-toggle {
						display: flex;
					}

					.header-actions {
						display: none;
					}
				}
			</style>
		</head>

		<body>
			<div id="staff-ai-app"
				data-channel="<?php echo esc_attr($channel); ?>"
				data-user-id="<?php echo esc_attr((string) $user->ID); ?>"
				data-user-email="<?php echo esc_attr($user->user_email); ?>"
				data-api-base="<?php echo esc_url($api_base); ?>"
				data-nonce="<?php echo esc_attr($nonce); ?>">

				<!-- Sidebar overlay for mobile -->
				<div class="sidebar-overlay" id="sidebarOverlay"></div>

				<!-- Sidebar -->
				<aside class="sidebar" id="sidebar">
					<div class="sidebar-header">
						<button type="button" class="new-chat-btn" id="newChatBtn">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<line x1="12" y1="5" x2="12" y2="19"></line>
								<line x1="5" y1="12" x2="19" y2="12"></line>
							</svg>
							<?php echo esc_html__('New chat', 'def-core'); ?>
						</button>
					</div>
					<nav class="conversation-list" id="conversationList">
						<div class="conversation-list-placeholder" id="conversationPlaceholder">
							<?php echo esc_html__('No conversations yet', 'def-core'); ?>
						</div>
					</nav>
					<div class="sidebar-footer">
						<?php echo esc_html__('Powered by DEF', 'def-core'); ?>
					</div>
				</aside>

				<!-- Main chat -->
				<main class="chat-container">
					<header class="chat-header">
						<button type="button" class="menu-toggle" id="menuToggle" aria-label="<?php echo esc_attr__('Toggle menu', 'def-core'); ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<line x1="3" y1="6" x2="21" y2="6"></line>
								<line x1="3" y1="12" x2="21" y2="12"></line>
								<line x1="3" y1="18" x2="21" y2="18"></line>
							</svg>
						</button>
						<div class="header-logo"><?php echo $logo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Logo is escaped in wp_get_attachment_image or esc_html 
													?></div>
						<span class="readonly-indicator" id="readonlyIndicator"><?php echo esc_html__('Read-only (shared)', 'def-core'); ?></span>
						<div class="header-actions">
							<button type="button" class="header-btn" id="exportBtn" disabled><?php echo esc_html__('Export', 'def-core'); ?></button>
							<button type="button" class="header-btn" id="shareBtn" disabled><?php echo esc_html__('Share', 'def-core'); ?></button>
						</div>
					</header>

					<div class="messages-container" id="messagesContainer">
						<div class="messages-list" id="messagesList">
							<div class="welcome-message" id="welcomeMessage">
								<p><?php echo esc_html__('How can I help you today?', 'def-core'); ?></p>
							</div>
						</div>
					</div>

					<div class="info-banner" id="infoBanner"></div>
					<div class="error-banner" id="errorBanner"></div>

					<div class="composer-container" id="composerContainer">
						<div class="composer-wrapper">
							<div class="composer-row">
								<div class="composer">
									<textarea
										class="composer-input"
										id="composerInput"
										placeholder="<?php echo esc_attr__('Send a message...', 'def-core'); ?>"
										rows="1"></textarea>
									<button type="button" class="send-btn" id="sendBtn" disabled aria-label="<?php echo esc_attr__('Send message', 'def-core'); ?>">
										<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
											<line x1="22" y1="2" x2="11" y2="13"></line>
											<polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
										</svg>
									</button>
								</div>
								<button type="button" class="create-btn" id="createBtn">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
										<polyline points="14 2 14 8 20 8"></polyline>
										<line x1="12" y1="11" x2="12" y2="17"></line>
										<line x1="9" y1="14" x2="15" y2="14"></line>
									</svg>
									<?php echo esc_html__('Create', 'def-core'); ?>
								</button>
								</div>
							<div class="composer-hint">
								<?php echo esc_html__('Press Enter to send, Shift+Enter for new line', 'def-core'); ?>
							</div>
						</div>
					</div>
				</main>

				<!-- Share Modal -->
				<div class="modal-overlay" id="shareModal">
					<div class="modal" style="max-width: 520px;">
						<div class="modal-header">
							<span class="modal-title"><?php echo esc_html__('Share Conversation', 'def-core'); ?></span>
							<button type="button" class="modal-close" id="shareModalClose">&times;</button>
						</div>
						<!-- Loading state -->
						<div class="share-loading" id="shareLoading">
							<div class="share-loading-spinner"></div>
							<p><?php echo esc_html__('Preparing share form...', 'def-core'); ?></p>
						</div>
						<!-- Error state -->
						<div class="share-error" id="shareError" style="display:none;">
							<p id="shareErrorText"></p>
							<button type="button" class="modal-btn modal-btn-secondary" id="shareErrorClose"><?php echo esc_html__('Close', 'def-core'); ?></button>
						</div>
						<!-- Form state -->
						<div id="shareFormContent" style="display:none;">
							<div class="modal-body">
								<div class="form-group">
									<label class="form-label"><?php echo esc_html__('Share with', 'def-core'); ?></label>
									<select class="form-input share-recipient-select" id="shareRecipient" multiple></select>
								</div>
								<div class="form-group">
									<label class="form-label"><?php echo esc_html__('Subject', 'def-core'); ?></label>
									<input type="text" class="form-input" id="shareSubject" placeholder="<?php echo esc_attr__('Brief summary...', 'def-core'); ?>">
								</div>
								<div class="form-group">
									<label class="form-label"><?php echo esc_html__('Message', 'def-core'); ?></label>
									<textarea class="form-input share-message-input" id="shareMessage" rows="4" placeholder="<?php echo esc_attr__('Summary and context for the recipient...', 'def-core'); ?>"></textarea>
								</div>
								<div class="share-transcript-toggle">
									<label class="share-toggle-label">
										<input type="checkbox" id="shareTranscript" checked>
										<svg class="share-paperclip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
										<?php echo esc_html__('Include conversation transcript', 'def-core'); ?>
									</label>
								</div>
							</div>
							<div class="modal-footer">
								<button type="button" class="modal-btn modal-btn-secondary" id="shareCancel"><?php echo esc_html__('Cancel', 'def-core'); ?></button>
								<button type="button" class="modal-btn modal-btn-primary" id="shareSend" disabled><?php echo esc_html__('Send', 'def-core'); ?></button>
							</div>
						</div>
					</div>
				</div>

				<!-- Create Tool Modal -->
				<div class="modal-overlay" id="createModal">
					<div class="modal" style="max-width: 480px;">
						<div class="modal-header">
							<span class="modal-title"><?php echo esc_html__('Create', 'def-core'); ?></span>
							<button type="button" class="modal-close" id="createModalClose">&times;</button>
						</div>
						<div class="modal-body">
							<div class="form-group">
								<label class="form-label"><?php echo esc_html__('Type', 'def-core'); ?></label>
								<select class="form-input" id="createToolType">
									<option value="document_creation"><?php echo esc_html__('Document', 'def-core'); ?></option>
									<option value="spreadsheet_creation"><?php echo esc_html__('Spreadsheet', 'def-core'); ?></option>
									<option value="image_generation"><?php echo esc_html__('Image', 'def-core'); ?></option>
								</select>
							</div>
							<div class="form-group" id="createFormatGroup">
								<label class="form-label"><?php echo esc_html__('Format', 'def-core'); ?></label>
								<select class="form-input" id="createFormat">
									<option value="docx">DOCX</option>
									<option value="pdf">PDF</option>
									<option value="md">Markdown</option>
								</select>
							</div>
							<div class="form-group">
								<label class="form-label"><?php echo esc_html__('Title (optional)', 'def-core'); ?></label>
								<input type="text" class="form-input" id="createTitle" placeholder="<?php echo esc_attr__('My Document', 'def-core'); ?>">
							</div>
							<div class="form-group">
								<label class="form-label"><?php echo esc_html__('Instructions', 'def-core'); ?> <span style="color: #f87171;">*</span></label>
								<textarea class="form-input" id="createPrompt" rows="4" placeholder="<?php echo esc_attr__('Describe what you want to create...', 'def-core'); ?>"></textarea>
							</div>
							<div class="error-banner" id="createError" style="margin: 0;"></div>
						</div>
						<div class="modal-footer">
							<button type="button" class="modal-btn modal-btn-secondary" id="createCancel"><?php echo esc_html__('Cancel', 'def-core'); ?></button>
							<button type="button" class="modal-btn modal-btn-primary" id="createSubmit"><?php echo esc_html__('Create', 'def-core'); ?></button>
						</div>
					</div>
				</div>
			</div>

			<script>
				(function() {
					'use strict';

					const app = document.getElementById('staff-ai-app');
					const channel = app.dataset.channel;
					const userId = app.dataset.userId;
					const userEmail = app.dataset.userEmail;
					const apiBase = app.dataset.apiBase;
					const nonce = app.dataset.nonce;

					// HTML escape helper
					function escapeHtml(str) {
						const div = document.createElement('div');
						div.appendChild(document.createTextNode(str));
						return div.innerHTML;
					}

					// API helper function
					async function apiRequest(endpoint, options = {}) {
						const url = apiBase + endpoint;
						const headers = {
							'X-WP-Nonce': nonce,
							'Content-Type': 'application/json',
							...options.headers
						};
						const response = await fetch(url, {
							...options,
							headers,
							credentials: 'same-origin'
						});
						const data = await response.json();
						if (!response.ok) {
							const errorCode = data.code || data.error || '';
							const errorMsg = data.message || data.error || 'Request failed';
							console.error('Staff AI API error:', {
								code: errorCode,
								message: errorMsg,
								data: data
							});
							throw new Error(errorCode ? '[' + errorCode + '] ' + errorMsg : errorMsg);
						}
						return data;
					}

					// Elements
					const sidebar = document.getElementById('sidebar');
					const sidebarOverlay = document.getElementById('sidebarOverlay');
					const menuToggle = document.getElementById('menuToggle');
					const newChatBtn = document.getElementById('newChatBtn');
					const conversationList = document.getElementById('conversationList');
					const conversationPlaceholder = document.getElementById('conversationPlaceholder');
					const messagesContainer = document.getElementById('messagesContainer');
					const messagesList = document.getElementById('messagesList');
					const welcomeMessage = document.getElementById('welcomeMessage');
					const readonlyIndicator = document.getElementById('readonlyIndicator');
					const shareBtn = document.getElementById('shareBtn');
					const infoBanner = document.getElementById('infoBanner');
					const errorBanner = document.getElementById('errorBanner');
					const composerContainer = document.getElementById('composerContainer');
					const composerInput = document.getElementById('composerInput');
					const sendBtn = document.getElementById('sendBtn');
					// Share modal elements
					const shareModal = document.getElementById('shareModal');
					const shareModalClose = document.getElementById('shareModalClose');
					const shareLoading = document.getElementById('shareLoading');
					const shareError = document.getElementById('shareError');
					const shareErrorText = document.getElementById('shareErrorText');
					const shareErrorClose = document.getElementById('shareErrorClose');
					const shareFormContent = document.getElementById('shareFormContent');
					const shareRecipient = document.getElementById('shareRecipient');
					const shareSubject = document.getElementById('shareSubject');
					const shareMessage = document.getElementById('shareMessage');
					const shareTranscript = document.getElementById('shareTranscript');
					const shareCancel = document.getElementById('shareCancel');
					const shareSend = document.getElementById('shareSend');

					// State
					let conversations = [];
					let currentConversationId = null;
					let messages = [];
					let isLoading = false;
					let isReadOnly = false;

					// Initialize
					loadConversations();

					// Sidebar toggle (mobile)
					function toggleSidebar() {
						sidebar.classList.toggle('open');
						sidebarOverlay.classList.toggle('visible');
					}

					menuToggle.addEventListener('click', toggleSidebar);
					sidebarOverlay.addEventListener('click', toggleSidebar);

					// Load conversations from backend
					async function loadConversations() {
						try {
							const result = await apiRequest('/conversations');
							conversations = result.conversations || [];
							renderConversationList();
							hideError();
						} catch (err) {
							console.error('Failed to load conversations:', err);
							conversations = [];
							renderConversationList();
							// Show detailed error with troubleshooting hint
							let errorMsg = err.message || '<?php echo esc_js(__('Failed to connect to backend service.', 'def-core')); ?>';
							errorMsg += ' <?php echo esc_js(__('Check /wp-json/a3-ai/v1/staff-ai/status for diagnostics.', 'def-core')); ?>';
							showError(errorMsg);
						}
					}

					// Render conversation list
					function renderConversationList() {
						// Remove existing items
						const items = conversationList.querySelectorAll('.conversation-item');
						items.forEach(el => el.remove());

						if (conversations.length === 0) {
							conversationPlaceholder.style.display = 'block';
							return;
						}

						conversationPlaceholder.style.display = 'none';

						conversations.forEach(function(conv) {
							const btn = document.createElement('button');
							btn.type = 'button';
							btn.className = 'conversation-item';
							if (conv.id === currentConversationId) {
								btn.classList.add('active');
							}
							btn.dataset.id = conv.id;

							const title = document.createElement('span');
							title.className = 'conversation-item-title';
							title.textContent = conv.title || '<?php echo esc_js(__('New conversation', 'def-core')); ?>';

							const time = document.createElement('span');
							time.className = 'conversation-item-time';
							time.textContent = formatTime(conv.updated_at);

							btn.appendChild(title);
							btn.appendChild(time);
							btn.addEventListener('click', function() {
								loadConversation(conv.id, conv.is_shared);
							});

							conversationList.insertBefore(btn, conversationPlaceholder);
						});
					}

					// Format time for display
					function formatTime(timestamp) {
						if (!timestamp) return '';
						const date = new Date(timestamp);
						const now = new Date();
						const diff = now - date;
						if (diff < 86400000) {
							return date.toLocaleTimeString([], {
								hour: '2-digit',
								minute: '2-digit'
							});
						}
						return date.toLocaleDateString();
					}

					// Load a specific conversation
					async function loadConversation(id, shared) {
						currentConversationId = id;
						isReadOnly = !!shared;

						// Update UI state
						updateReadOnlyState();
						renderConversationList();

						try {
							const result = await apiRequest('/conversations/' + encodeURIComponent(id));
							messages = result.messages || [];
							renderMessages();
						} catch (err) {
							console.error('Failed to load conversation:', err);
							showError('<?php echo esc_js(__('Failed to load conversation.', 'def-core')); ?>');
						}

						if (window.innerWidth <= 768) {
							toggleSidebar();
						}
					}

					// Update read-only state
					function updateReadOnlyState() {
						if (isReadOnly) {
							readonlyIndicator.classList.add('visible');
							composerContainer.classList.add('disabled');
						} else {
							readonlyIndicator.classList.remove('visible');
							composerContainer.classList.remove('disabled');
						}
						shareBtn.disabled = !currentConversationId;
					}

					// New chat
					newChatBtn.addEventListener('click', function() {
						currentConversationId = null;
						isReadOnly = false;
						messages = [];
						updateReadOnlyState();
						renderConversationList();
						renderMessages();
						composerInput.value = '';
						updateSendButton();
						hideError();
						hideInfo();
						if (window.innerWidth <= 768) {
							toggleSidebar();
						}
					});

					// Auto-resize textarea
					function autoResize() {
						composerInput.style.height = 'auto';
						composerInput.style.height = Math.min(composerInput.scrollHeight, 200) + 'px';
					}

					composerInput.addEventListener('input', function() {
						autoResize();
						updateSendButton();
					});

					// Update send button state
					function updateSendButton() {
						sendBtn.disabled = !composerInput.value.trim() || isLoading || isReadOnly;
					}

					// Keyboard handler: Enter to send, Shift+Enter for newline
					composerInput.addEventListener('keydown', function(e) {
						if (e.key === 'Enter' && !e.shiftKey) {
							e.preventDefault();
							if (!sendBtn.disabled) {
								sendMessage();
							}
						}
					});

					sendBtn.addEventListener('click', sendMessage);

					// Show info
					function showInfo(msg) {
						infoBanner.textContent = msg;
						infoBanner.classList.add('visible');
					}

					// Hide info
					function hideInfo() {
						infoBanner.classList.remove('visible');
					}

					// Show error
					function showError(msg) {
						errorBanner.textContent = msg;
						errorBanner.classList.add('visible');
					}

					// Hide error
					function hideError() {
						errorBanner.classList.remove('visible');
					}

					// Render messages
					function renderMessages() {
						if (messages.length === 0) {
							welcomeMessage.style.display = 'block';
							const msgElements = messagesList.querySelectorAll('.message');
							msgElements.forEach(el => el.remove());
							return;
						}

						welcomeMessage.style.display = 'none';

						const msgElements = messagesList.querySelectorAll('.message');
						msgElements.forEach(el => el.remove());

						messages.forEach(function(msg) {
							const div = document.createElement('div');
							div.className = 'message message-' + msg.role;

							const avatar = document.createElement('div');
							avatar.className = 'message-avatar';
							avatar.textContent = msg.role === 'user' ? 'U' : 'AI';

							const content = document.createElement('div');
							content.className = 'message-content';

							if (msg.isTyping) {
								const indicator = document.createElement('div');
								indicator.className = 'typing-indicator';
								indicator.innerHTML = '<span></span><span></span><span></span>';
								content.appendChild(indicator);
							} else {
								content.textContent = msg.content;

								// Render tool outputs if present
								if (msg.tool_outputs && msg.tool_outputs.length > 0) {
									msg.tool_outputs.forEach(function(tool) {
										const card = createToolOutputCard(tool);
										content.appendChild(card);
									});
								}
							}

							div.appendChild(avatar);
							div.appendChild(content);
							messagesList.appendChild(div);
						});

						messagesContainer.scrollTop = messagesContainer.scrollHeight;
					}

					// Helper function to rewrite download URLs to use WordPress endpoint
					function rewriteDownloadUrl(url) {
						if (!url || url === '#') return url;
						// Convert /api/files/{tenant}/{filename} to /staff-ai-download/{tenant}/{filename}
						if (url.startsWith('/api/files/')) {
							return '<?php echo esc_js(home_url('/staff-ai-download/')); ?>' + url.replace('/api/files/', '');
						}
						return url;
					}

					// Create tool output card
					function createToolOutputCard(tool) {
						// Escalation offer — inline suggestion card
						if (tool.type === 'escalation_offer') {
							const card = document.createElement('div');
							card.className = 'escalation-suggestion';

							const header = document.createElement('div');
							header.className = 'escalation-suggestion-header';
							header.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> <?php echo esc_js(__('Internal Handoff Suggested', 'def-core')); ?>';

							const reason = document.createElement('div');
							reason.className = 'escalation-suggestion-reason';
							reason.textContent = tool.reason || '';

							const hint = document.createElement('div');
							hint.className = 'escalation-suggestion-hint';
							hint.textContent = '<?php echo esc_js(__('Use the Share button to hand off this conversation to another team member.', 'def-core')); ?>';

							card.appendChild(header);
							card.appendChild(reason);
							card.appendChild(hint);

							return card;
						}

						// File output — download card
						const card = document.createElement('div');
						card.className = 'tool-output-card';

						const icon = document.createElement('div');
						icon.className = 'tool-output-icon';
						icon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>';

						const info = document.createElement('div');
						info.className = 'tool-output-info';

						const name = document.createElement('div');
						name.className = 'tool-output-name';
						name.textContent = tool.file_name || '<?php echo esc_js(__('Download', 'def-core')); ?>';

						const type = document.createElement('div');
						type.className = 'tool-output-type';
						type.textContent = tool.file_type || '<?php echo esc_js(__('File', 'def-core')); ?>';

						info.appendChild(name);
						info.appendChild(type);

						const download = document.createElement('a');
						download.className = 'tool-output-download';
						download.href = rewriteDownloadUrl(tool.download_url) || '#';
						download.target = '_blank';
						download.rel = 'noopener';
						download.textContent = '<?php echo esc_js(__('Download', 'def-core')); ?>';

						card.appendChild(icon);
						card.appendChild(info);
						card.appendChild(download);

						return card;
					}

					// Send message
					async function sendMessage() {
						const text = composerInput.value.trim();
						if (!text || isLoading || isReadOnly) return;

						hideError();
						hideInfo();

						messages.push({
							role: 'user',
							content: text
						});
						renderMessages();

						composerInput.value = '';
						autoResize();
						updateSendButton();

						messages.push({
							role: 'assistant',
							content: '',
							isTyping: true
						});
						renderMessages();

						isLoading = true;
						updateSendButton();

						try {
							const result = await apiRequest('/chat', {
								method: 'POST',
								body: JSON.stringify({
									message: text,
									thread_id: currentConversationId
								})
							});

							messages.pop();
							messages.push({
								role: 'assistant',
								content: result.message?.content || '',
								tool_outputs: result.message?.tool_outputs || []
							});
							renderMessages();

							// Update conversation ID from response
							if (result.thread_id) {
								currentConversationId = result.thread_id;
							}

							// Refresh conversation list
							loadConversations();

							updateReadOnlyState();
						} catch (err) {
							messages.pop();
							renderMessages();
							console.error('Failed to send message:', err);
							showError(err.message || '<?php echo esc_js(__('Failed to send message. Please try again.', 'def-core')); ?>');
						} finally {
							isLoading = false;
							updateSendButton();
						}
					}

					// =============================================
					// SHARE MODAL — AI-generated subject + summary
					// =============================================

					function showShareLoading() {
						shareLoading.style.display = '';
						shareError.style.display = 'none';
						shareFormContent.style.display = 'none';
					}

					function showShareError(msg) {
						shareLoading.style.display = 'none';
						shareError.style.display = '';
						shareFormContent.style.display = 'none';
						shareErrorText.textContent = msg;
					}

					function showShareForm() {
						shareLoading.style.display = 'none';
						shareError.style.display = 'none';
						shareFormContent.style.display = '';
					}

					function updateShareSendButton() {
						const hasRecipient = shareRecipient.selectedOptions.length > 0;
						const hasSubject = shareSubject.value.trim() !== '';
						const hasMessage = shareMessage.value.trim() !== '';
						shareSend.disabled = !(hasRecipient && hasSubject && hasMessage);
					}

					// Enable/disable Send when fields change
					shareRecipient.addEventListener('change', updateShareSendButton);
					shareSubject.addEventListener('input', updateShareSendButton);
					shareMessage.addEventListener('input', updateShareSendButton);

					// Open share modal
					shareBtn.addEventListener('click', async function() {
						if (!currentConversationId) return;
						shareModal.classList.add('visible');
						showShareLoading();

						try {
							// Fetch recipients + AI summary in parallel
							const [settingsResp, summaryResp] = await Promise.all([
								apiRequest('/share-settings', {
									method: 'GET'
								}).catch(function() { throw new Error('Failed to load recipients'); }),
								apiRequest('/conversations/' + encodeURIComponent(currentConversationId) + '/summarize', {
									method: 'POST'
								})
							]);

							// Populate recipients (multi-select)
							const recipients = (settingsResp && settingsResp.allowed_recipients) || [];
							shareRecipient.innerHTML = '';
							recipients.forEach(function(email) {
								const opt = document.createElement('option');
								opt.value = email;
								opt.textContent = email;
								shareRecipient.appendChild(opt);
							});

							// Populate subject + message from AI summary
							shareSubject.value = (summaryResp && summaryResp.suggested_subject) || '';
							shareMessage.value = (summaryResp && summaryResp.summary) || '';
							shareTranscript.checked = true;

							updateShareSendButton();
							showShareForm();
						} catch (err) {
							showShareError(err.message || '<?php echo esc_js(__('Failed to prepare share form.', 'def-core')); ?>');
						}
					});

					// Send share email
					shareSend.addEventListener('click', async function() {
						if (shareSend.disabled || !currentConversationId) return;
						shareSend.disabled = true;

						try {
							const selectedRecipients = Array.from(shareRecipient.selectedOptions).map(function(o) { return o.value; });
							if (selectedRecipients.length === 0) return;

							// Build body — message + optional transcript
							let bodyText = shareMessage.value;

							if (shareTranscript.checked && messages.length > 0) {
								bodyText += '\n\n---\nConversation Transcript:\n\n';
								messages.forEach(function(msg) {
									const role = msg.role === 'user' ? 'User' : 'Assistant';
									bodyText += role + ': ' + msg.content + '\n\n';
								});
							}

							await apiRequest('/share-send', {
								method: 'POST',
								body: JSON.stringify({
									channel: 'staff_ai',
									to: selectedRecipients,
									subject: shareSubject.value,
									body: bodyText
								})
							});

							shareModal.classList.remove('visible');
							addSystemMessage(selectedRecipients.join(', '));
						} catch (err) {
							showShareError(err.message || '<?php echo esc_js(__('Failed to send share email.', 'def-core')); ?>');
						} finally {
							shareSend.disabled = false;
							updateShareSendButton();
						}
					});

					// Add ephemeral system message to thread (DN-2: not persisted)
					function addSystemMessage(email) {
						const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
						const el = document.createElement('div');
						el.className = 'message-system';
						el.innerHTML = '<span class="message-system-content">' +
							'<?php echo esc_js(__('Shared with', 'def-core')); ?> ' +
							escapeHtml(email) + ' · ' + escapeHtml(time) +
							'</span>';
						messagesList.appendChild(el);
						messagesList.scrollTop = messagesList.scrollHeight;
					}

					// Close share modal handlers
					shareModalClose.addEventListener('click', function() {
						shareModal.classList.remove('visible');
					});

					shareCancel.addEventListener('click', function() {
						shareModal.classList.remove('visible');
					});

					shareErrorClose.addEventListener('click', function() {
						shareModal.classList.remove('visible');
					});

					shareModal.addEventListener('click', function(e) {
						if (e.target === shareModal) shareModal.classList.remove('visible');
					});

					// =============================================
					// CREATE TOOL MODAL
					// =============================================
					const createBtn = document.getElementById('createBtn');
					const createModal = document.getElementById('createModal');
					const createModalClose = document.getElementById('createModalClose');
					const createToolType = document.getElementById('createToolType');
					const createFormatGroup = document.getElementById('createFormatGroup');
					const createFormat = document.getElementById('createFormat');
					const createTitle = document.getElementById('createTitle');
					const createPrompt = document.getElementById('createPrompt');
					const createError = document.getElementById('createError');
					const createCancel = document.getElementById('createCancel');
					const createSubmit = document.getElementById('createSubmit');

					// Format options by tool type
					const formatOptions = {
						document_creation: [{
								value: 'docx',
								label: 'DOCX'
							},
							{
								value: 'pdf',
								label: 'PDF'
							},
							{
								value: 'md',
								label: 'Markdown'
							}
						],
						spreadsheet_creation: [{
								value: 'xlsx',
								label: 'XLSX'
							},
							{
								value: 'csv',
								label: 'CSV'
							}
						],
						image_generation: [{
							value: 'png',
							label: 'PNG'
						}]
					};

					// Update format options when tool type changes
					function updateFormatOptions() {
						const toolType = createToolType.value;
						const options = formatOptions[toolType] || [];
						createFormat.innerHTML = '';
						options.forEach(function(opt) {
							const option = document.createElement('option');
							option.value = opt.value;
							option.textContent = opt.label;
							createFormat.appendChild(option);
						});
						// Hide format group for image (only one option)
						createFormatGroup.style.display = toolType === 'image_generation' ? 'none' : 'block';
					}

					createToolType.addEventListener('change', updateFormatOptions);

					// Open create modal
					createBtn.addEventListener('click', function() {
						createToolType.value = 'document_creation';
						updateFormatOptions();
						createTitle.value = '';
						createPrompt.value = '';
						createError.classList.remove('visible');
						createError.textContent = '';
						createModal.classList.add('visible');
						createPrompt.focus();
					});

					// Close create modal
					createModalClose.addEventListener('click', function() {
						createModal.classList.remove('visible');
					});

					createCancel.addEventListener('click', function() {
						createModal.classList.remove('visible');
					});

					createModal.addEventListener('click', function(e) {
						if (e.target === createModal) createModal.classList.remove('visible');
					});

					// Submit tool creation via chat
					createSubmit.addEventListener('click', function() {
						const toolName = createToolType.value;
						const format = createFormat.value;
						const title = createTitle.value.trim();
						const prompt = createPrompt.value.trim();

						// Validate
						if (!prompt) {
							createError.textContent = '<?php echo esc_js(__('Instructions are required.', 'def-core')); ?>';
							createError.classList.add('visible');
							return;
						}

						createError.classList.remove('visible');

						// Build a chat message from the form fields
						const toolLabel = toolName === 'create_document' ? 'document' : toolName === 'create_spreadsheet' ? 'spreadsheet' : 'image';
						let chatMessage = 'Create a ' + format.toUpperCase() + ' ' + toolLabel;
						if (title) {
							chatMessage += ' titled "' + title + '"';
						}
						chatMessage += ' with these instructions:\n\n' + prompt;

						// Close modal and send through normal chat flow
						createModal.classList.remove('visible');
						createToolType.value = 'create_document';
						createFormat.value = 'pdf';
						createTitle.value = '';
						createPrompt.value = '';
						createError.classList.remove('visible');
						createFormatGroup.style.display = '';

						// Inject into composer and send
						composerInput.value = chatMessage;
						autoResize();
						sendMessage();
					});

					// Focus input on load
					composerInput.focus();
				})();
			</script>
		</body>

		</html>
<?php
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
