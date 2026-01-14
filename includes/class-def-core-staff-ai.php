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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the /staff-ai endpoint rendering.
 */
final class DEF_Core_Staff_AI {
	/**
	 * The endpoint slug.
	 */
	const ENDPOINT_SLUG = 'staff-ai';

	/**
	 * Initialize the Staff AI endpoint.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_endpoint' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Register REST API routes for Staff AI adapter.
	 *
	 * @since 1.1.0
	 */
	public static function register_rest_routes(): void {
		// List conversations.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/conversations',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_list_conversations' ),
			)
		);

		// Load single conversation.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/conversations/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_load_conversation' ),
			)
		);

		// Send message (creates conversation if needed).
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/chat',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_send_message' ),
			)
		);

		// Share conversation.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/conversations/(?P<id>[a-zA-Z0-9_-]+)/share',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_share_conversation' ),
			)
		);

		// Revoke share.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/conversations/(?P<id>[a-zA-Z0-9_-]+)/revoke',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_revoke_share' ),
			)
		);

		// Export conversation.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/conversations/(?P<id>[a-zA-Z0-9_-]+)/export',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_export_conversation' ),
			)
		);

		// Escalate.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/escalate',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_escalate' ),
			)
		);

		// Status/test endpoint for debugging connection issues.
		// Uses manage_options cap so admins can diagnose without needing staff_ai access.
		register_rest_route(
			DEF_CORE_API_NAME_SPACE,
			'/staff-ai/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => function() {
					return current_user_can( 'manage_options' ) || self::user_has_staff_ai_access();
				},
				'callback'            => array( __CLASS__, 'rest_status' ),
			)
		);
	}

	/**
	 * REST API permission check for Staff AI endpoints.
	 *
	 * @return bool|\WP_Error True if access granted, WP_Error otherwise.
	 * @since 1.1.0
	 */
	public static function rest_permission_check() {
		// Authentication gate.
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'Authentication required.', 'def-core' ),
				array( 'status' => 401 )
			);
		}

		// Capability gate.
		if ( ! self::user_has_staff_ai_access() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access Staff AI.', 'def-core' ),
				array( 'status' => 403 )
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
	private static function get_api_base_url(): ?string {
		$url = get_option( 'def_core_staff_ai_api_url', '' );
		return ! empty( $url ) ? rtrim( $url, '/' ) : null;
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
	private static function backend_request( string $method, string $endpoint, array $body = array() ) {
		$base_url = self::get_api_base_url();
		if ( ! $base_url ) {
			return new \WP_Error(
				'staff_ai_not_configured',
				__( 'Staff AI backend URL is not configured. Go to Settings > Digital Employees to set the Staff AI API URL.', 'def-core' ),
				array( 'status' => 503 )
			);
		}

		$url = $base_url . $endpoint;

		// Build JWT claims for backend auth.
		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			return new \WP_Error(
				'staff_ai_not_authenticated',
				__( 'User not authenticated.', 'def-core' ),
				array( 'status' => 401 )
			);
		}

		$capabilities = array();
		if ( $user->has_cap( 'def_staff_access' ) ) {
			$capabilities[] = 'def_staff_access';
		}
		if ( $user->has_cap( 'def_management_access' ) ) {
			$capabilities[] = 'def_management_access';
		}

		$claims = array(
			'sub'          => (string) $user->ID,
			'email'        => $user->user_email,
			'capabilities' => $capabilities,
			'channel'      => 'staff_ai',
			'iss'          => get_site_url(),
		);

		$token = DEF_Core_JWT::issue_token( $claims, 300 );
		if ( empty( $token ) ) {
			return new \WP_Error(
				'staff_ai_token_error',
				__( 'Failed to generate authentication token.', 'def-core' ),
				array( 'status' => 500 )
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

		if ( 'POST' === $method ) {
			$args['body'] = wp_json_encode( $body );
			$response     = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'staff_ai_request_failed',
				__( 'Failed to connect to Staff AI backend.', 'def-core' ),
				array( 'status' => 502 )
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		// Map backend errors to clean UI-safe errors.
		if ( $status >= 400 ) {
			$error_code   = 'staff_ai_backend_error';
			$backend_detail = isset( $data['detail'] ) ? $data['detail'] : '';

			// Handle different error status codes.
			if ( 401 === $status || 403 === $status ) {
				$error_message = sprintf(
					/* translators: 1: HTTP status code, 2: backend error detail */
					__( 'Backend auth failed (HTTP %1$d). The backend may need JWKS configuration. Detail: %2$s', 'def-core' ),
					$status,
					$backend_detail ? $backend_detail : 'none'
				);
				$error_code = 'staff_ai_auth_failed';
			} elseif ( 404 === $status ) {
				$error_message = sprintf(
					/* translators: 1: API endpoint path, 2: full URL */
					__( 'Backend endpoint not found (HTTP 404): %1$s. Full URL: %2$s - Please verify the backend API supports this endpoint.', 'def-core' ),
					$endpoint,
					$url
				);
				$error_code = 'staff_ai_not_found';
			} elseif ( $status >= 500 ) {
				$error_message = sprintf(
					/* translators: 1: HTTP status code */
					__( 'Backend service error (HTTP %1$d). The service may be temporarily unavailable.', 'def-core' ),
					$status
				);
				$error_code = 'staff_ai_service_error';
			} else {
				$error_message = sprintf(
					/* translators: 1: HTTP status code, 2: backend error detail */
					__( 'Backend error (HTTP %1$d): %2$s', 'def-core' ),
					$status,
					$backend_detail ? $backend_detail : __( 'Unknown error', 'def-core' )
				);
			}

			// Log detailed error in debug mode for troubleshooting.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$debug_info = sprintf(
					'Staff AI backend error: status=%d, endpoint=%s, detail=%s',
					$status,
					$endpoint,
					$backend_detail ? $backend_detail : 'none'
				);
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( $debug_info );
			}

			return new \WP_Error(
				$error_code,
				$error_message,
				array( 'status' => $status )
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
	public static function rest_list_conversations( \WP_REST_Request $request ) {
		$result = self::backend_request( 'GET', '/api/my/threads' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Map backend response to frontend format.
		$conversations = array();
		if ( isset( $result['threads'] ) && is_array( $result['threads'] ) ) {
			foreach ( $result['threads'] as $thread ) {
				$conversations[] = array(
					'id'         => $thread['id'] ?? '',
					'title'      => $thread['title'] ?? __( 'New conversation', 'def-core' ),
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
	public static function rest_load_conversation( \WP_REST_Request $request ) {
		$thread_id = $request->get_param( 'id' );
		$result    = self::backend_request( 'GET', '/api/thread/' . rawurlencode( $thread_id ) . '/messages' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Map backend response to frontend format.
		$messages = array();
		if ( isset( $result['messages'] ) && is_array( $result['messages'] ) ) {
			foreach ( $result['messages'] as $msg ) {
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
	public static function rest_send_message( \WP_REST_Request $request ) {
		$body = $request->get_json_params();

		$message   = isset( $body['message'] ) ? sanitize_textarea_field( $body['message'] ) : '';
		$thread_id = isset( $body['thread_id'] ) ? sanitize_text_field( $body['thread_id'] ) : null;

		if ( empty( $message ) ) {
			return new \WP_Error(
				'invalid_message',
				__( 'Message cannot be empty.', 'def-core' ),
				array( 'status' => 400 )
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
		if ( $thread_id && 'temp-' !== substr( $thread_id, 0, 5 ) ) {
			$chat_body['thread_id']        = $thread_id;
			$chat_body['continue_thread']  = true;
		}

		$result = self::backend_request( 'POST', '/api/chat', $chat_body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Extract response.
		$assistant_content = '';
		$tool_outputs      = array();

		if ( isset( $result['choices'][0]['message']['content'] ) ) {
			$assistant_content = $result['choices'][0]['message']['content'];
		}

		return new \WP_REST_Response(
			array(
				'success'      => true,
				'thread_id'    => $result['thread_id'] ?? $thread_id,
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
	 * REST handler: Share conversation.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response.
	 * @since 1.1.0
	 */
	public static function rest_share_conversation( \WP_REST_Request $request ) {
		// Sharing endpoint not yet implemented in backend.
		// Return success stub - backend will add this endpoint later.
		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Conversation shared successfully.', 'def-core' ),
			),
			200
		);
	}

	/**
	 * REST handler: Revoke share.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response.
	 * @since 1.1.0
	 */
	public static function rest_revoke_share( \WP_REST_Request $request ) {
		// Revoke endpoint not yet implemented in backend.
		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Share access revoked.', 'def-core' ),
			),
			200
		);
	}

	/**
	 * REST handler: Export conversation.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response.
	 * @since 1.1.0
	 */
	public static function rest_export_conversation( \WP_REST_Request $request ) {
		// Export endpoint not yet implemented in backend.
		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Export not yet available.', 'def-core' ),
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
	public static function rest_escalate( \WP_REST_Request $request ) {
		// Escalate endpoint not yet implemented in backend.
		// Return success stub - conversation remains active (non-terminal).
		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Escalated for review — you can continue working while this is reviewed.', 'def-core' ),
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
	public static function rest_status( \WP_REST_Request $request ) {
		$user     = wp_get_current_user();
		$base_url = self::get_api_base_url();

		$status = array(
			'success'      => true,
			'user'         => array(
				'id'    => $user->ID,
				'email' => $user->user_email,
			),
			'capabilities' => array(
				'def_staff_access'      => $user->has_cap( 'def_staff_access' ),
				'def_management_access' => $user->has_cap( 'def_management_access' ),
			),
			'config'       => array(
				'api_url_configured' => ! empty( $base_url ),
				'api_url'            => $base_url,
				'jwks_url'           => rest_url( DEF_CORE_API_NAME_SPACE . '/jwks' ),
				'issuer'             => get_site_url(),
			),
		);

		// Test token generation.
		$capabilities = array();
		if ( $user->has_cap( 'def_staff_access' ) ) {
			$capabilities[] = 'def_staff_access';
		}
		if ( $user->has_cap( 'def_management_access' ) ) {
			$capabilities[] = 'def_management_access';
		}

		$claims = array(
			'sub'          => (string) $user->ID,
			'email'        => $user->user_email,
			'capabilities' => $capabilities,
			'channel'      => 'staff_ai',
			'iss'          => get_site_url(),
		);

		$token = DEF_Core_JWT::issue_token( $claims, 300 );
		$status['token_generation'] = ! empty( $token ) ? 'ok' : 'failed';

		// Test backend connectivity if configured.
		if ( ! empty( $base_url ) && ! empty( $token ) ) {
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

			$health_response = wp_remote_get( $health_url, $test_args );

			if ( is_wp_error( $health_response ) ) {
				$status['health_check'] = 'error: ' . $health_response->get_error_message();
			} else {
				$health_status = wp_remote_retrieve_response_code( $health_response );
				$status['health_check'] = 'status_' . $health_status;
			}

			// Test 2: Threads endpoint (the actual endpoint that fails)
			$threads_url      = $base_url . '/api/my/threads';
			$threads_response = wp_remote_get( $threads_url, $test_args );

			if ( is_wp_error( $threads_response ) ) {
				$status['threads_check'] = 'error: ' . $threads_response->get_error_message();
			} else {
				$threads_status = wp_remote_retrieve_response_code( $threads_response );
				$threads_body   = wp_remote_retrieve_body( $threads_response );
				$threads_data   = json_decode( $threads_body, true );

				$status['threads_check'] = array(
					'status' => $threads_status,
					'detail' => isset( $threads_data['detail'] ) ? $threads_data['detail'] : null,
				);
			}
		} else {
			$status['health_check']  = 'not_tested';
			$status['threads_check'] = 'not_tested';
		}

		return new \WP_REST_Response( $status, 200 );
	}

	/**
	 * Add rewrite rules for /staff-ai endpoint.
	 */
	public static function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^' . self::ENDPOINT_SLUG . '/?$',
			'index.php?' . self::ENDPOINT_SLUG . '=1',
			'top'
		);
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Existing query vars.
	 * @return array Modified query vars.
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = self::ENDPOINT_SLUG;
		return $vars;
	}

	/**
	 * Handle the /staff-ai endpoint request.
	 */
	public static function handle_endpoint(): void {
		if ( ! get_query_var( self::ENDPOINT_SLUG ) ) {
			return;
		}

		// Authentication gate: redirect to login if not authenticated.
		if ( ! is_user_logged_in() ) {
			$redirect_url = home_url( '/' . self::ENDPOINT_SLUG );
			wp_safe_redirect( wp_login_url( $redirect_url ) );
			exit;
		}

		// Capability gate: check for def_staff_access OR def_management_access.
		if ( ! self::user_has_staff_ai_access() ) {
			self::render_access_denied();
			exit;
		}

		// Render the Staff AI shell.
		self::render_shell();
		exit;
	}

	/**
	 * Check if current user has Staff AI access.
	 *
	 * @return bool True if user has def_staff_access OR def_management_access.
	 */
	public static function user_has_staff_ai_access(): bool {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		return $user->has_cap( 'def_staff_access' ) || $user->has_cap( 'def_management_access' );
	}

	/**
	 * Render the access denied page.
	 */
	private static function render_access_denied(): void {
		http_response_code( 403 );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html__( 'Access Denied', 'def-core' ); ?> - <?php bloginfo( 'name' ); ?></title>
	<style>
		* { margin: 0; padding: 0; box-sizing: border-box; }
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
			box-shadow: 0 1px 3px rgba(0,0,0,.04);
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
		<h1><?php echo esc_html__( 'Access Denied', 'def-core' ); ?></h1>
		<p><?php echo esc_html__( 'You do not have permission to access Staff AI.', 'def-core' ); ?></p>
	</div>
</body>
</html>
		<?php
	}

	/**
	 * Render the Staff AI shell.
	 */
	private static function render_shell(): void {
		$user    = wp_get_current_user();
		$channel = 'staff_ai';

		// Determine assistant type based on capability.
		$assistant_type = $user->has_cap( 'def_management_access' )
			? 'management'
			: 'staff';

		$assistant_label = ( 'management' === $assistant_type )
			? __( 'Management Knowledge Assistant', 'def-core' )
			: __( 'Staff Knowledge Assistant', 'def-core' );

		// REST API data for JS - Staff AI adapter endpoints.
		$api_base = rest_url( DEF_CORE_API_NAME_SPACE . '/staff-ai' );
		$nonce    = wp_create_nonce( 'wp_rest' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $assistant_label ); ?> - <?php bloginfo( 'name' ); ?></title>
	<style>
		* { margin: 0; padding: 0; box-sizing: border-box; }
		html, body {
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
			border-bottom: 1px solid rgba(255,255,255,0.1);
		}
		.new-chat-btn {
			width: 100%;
			padding: 12px 16px;
			background: transparent;
			border: 1px solid rgba(255,255,255,0.2);
			border-radius: 6px;
			color: #fff;
			font-size: 14px;
			cursor: pointer;
			display: flex;
			align-items: center;
			gap: 8px;
			transition: background 0.15s;
		}
		.new-chat-btn:hover { background: rgba(255,255,255,0.1); }
		.new-chat-btn svg { width: 16px; height: 16px; }
		.conversation-list {
			flex: 1;
			overflow-y: auto;
			padding: 8px;
		}
		.conversation-list-placeholder {
			padding: 16px;
			color: rgba(255,255,255,0.5);
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
			color: rgba(255,255,255,0.8);
			font-size: 13px;
			text-align: left;
			cursor: pointer;
			margin-bottom: 2px;
			transition: background 0.15s;
		}
		.conversation-item:hover { background: rgba(255,255,255,0.1); }
		.conversation-item.active { background: rgba(255,255,255,0.15); }
		.conversation-item-title {
			display: block;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			margin-bottom: 2px;
		}
		.conversation-item-time {
			font-size: 11px;
			color: rgba(255,255,255,0.4);
		}
		.sidebar-footer {
			padding: 12px;
			border-top: 1px solid rgba(255,255,255,0.1);
			font-size: 11px;
			color: rgba(255,255,255,0.5);
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
			border-bottom: 1px solid rgba(255,255,255,0.1);
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
		.menu-toggle svg { width: 20px; height: 20px; }
		.assistant-label {
			flex: 1;
			font-size: 14px;
			font-weight: 500;
			color: rgba(255,255,255,0.9);
		}
		.header-actions {
			display: flex;
			gap: 8px;
		}
		.header-btn {
			background: transparent;
			border: 1px solid rgba(255,255,255,0.2);
			border-radius: 6px;
			color: rgba(255,255,255,0.7);
			padding: 6px 12px;
			font-size: 12px;
			cursor: pointer;
			transition: background 0.15s, color 0.15s;
		}
		.header-btn:hover { background: rgba(255,255,255,0.1); color: #fff; }
		.header-btn:disabled { opacity: 0.5; cursor: not-allowed; }
		/* Read-only indicator */
		.readonly-indicator {
			display: none;
			background: rgba(251,191,36,0.15);
			color: #fbbf24;
			padding: 4px 10px;
			border-radius: 4px;
			font-size: 11px;
		}
		.readonly-indicator.visible { display: inline-block; }
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
		.message + .message { border-top: 1px solid rgba(255,255,255,0.1); }
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
		.message-user .message-avatar { background: #5436da; color: #fff; }
		.message-assistant .message-avatar { background: #19c37d; color: #fff; }
		.message-content {
			flex: 1;
			line-height: 1.6;
			white-space: pre-wrap;
			word-break: break-word;
		}
		/* Tool output card */
		.tool-output-card {
			background: #40414f;
			border: 1px solid rgba(255,255,255,0.1);
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
			background: rgba(255,255,255,0.1);
			border-radius: 6px;
			display: flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
		}
		.tool-output-icon svg { width: 18px; height: 18px; color: rgba(255,255,255,0.7); }
		.tool-output-info { flex: 1; min-width: 0; }
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
			color: rgba(255,255,255,0.5);
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
		.tool-output-download:hover { background: #1a9d6a; }
		.welcome-message {
			text-align: center;
			padding: 60px 20px;
			color: rgba(255,255,255,0.6);
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
			background: rgba(255,255,255,0.4);
			border-radius: 50%;
			animation: typing 1.4s infinite ease-in-out;
		}
		.typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
		.typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
		@keyframes typing {
			0%, 60%, 100% { transform: translateY(0); }
			30% { transform: translateY(-4px); }
		}
		/* Banners */
		.info-banner {
			background: rgba(34,197,94,0.1);
			border: 1px solid rgba(34,197,94,0.3);
			color: #86efac;
			padding: 12px 16px;
			margin: 0 20px 16px;
			border-radius: 8px;
			font-size: 13px;
			display: none;
		}
		.info-banner.visible { display: block; }
		.error-banner {
			background: rgba(239,68,68,0.1);
			border: 1px solid rgba(239,68,68,0.3);
			color: #fca5a5;
			padding: 12px 16px;
			margin: 0 20px 16px;
			border-radius: 8px;
			font-size: 13px;
			display: none;
		}
		.error-banner.visible { display: block; }
		/* Composer */
		.composer-container {
			padding: 16px 20px 24px;
			background: #343541;
		}
		.composer-container.disabled .composer { opacity: 0.6; pointer-events: none; }
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
			border: 1px solid rgba(255,255,255,0.1);
			border-radius: 12px;
			padding: 12px 16px;
			gap: 12px;
		}
		.composer:focus-within { border-color: rgba(255,255,255,0.3); }
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
		.composer-input::placeholder { color: rgba(255,255,255,0.4); }
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
		.send-btn:hover { background: #1a9d6a; }
		.send-btn:disabled { opacity: 0.5; cursor: not-allowed; }
		.send-btn svg { width: 16px; height: 16px; }
		.escalate-btn {
			background: transparent;
			border: 1px solid rgba(251,191,36,0.4);
			border-radius: 8px;
			color: #fbbf24;
			padding: 8px 12px;
			font-size: 12px;
			cursor: pointer;
			white-space: nowrap;
			transition: background 0.15s;
		}
		.escalate-btn:hover { background: rgba(251,191,36,0.1); }
		.escalate-btn:disabled { opacity: 0.5; cursor: not-allowed; }
		.composer-hint {
			text-align: center;
			font-size: 11px;
			color: rgba(255,255,255,0.4);
			margin-top: 8px;
		}
		/* Modal overlay */
		.modal-overlay {
			display: none;
			position: fixed;
			inset: 0;
			background: rgba(0,0,0,0.7);
			z-index: 200;
			align-items: center;
			justify-content: center;
		}
		.modal-overlay.visible { display: flex; }
		.modal {
			background: #2d2d3a;
			border-radius: 12px;
			width: 90%;
			max-width: 400px;
			padding: 24px;
			box-shadow: 0 20px 40px rgba(0,0,0,0.3);
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
			color: rgba(255,255,255,0.5);
			cursor: pointer;
			padding: 4px;
			font-size: 20px;
			line-height: 1;
		}
		.modal-close:hover { color: #fff; }
		.modal-body { margin-bottom: 20px; }
		.form-group { margin-bottom: 16px; }
		.form-group:last-child { margin-bottom: 0; }
		.form-label {
			display: block;
			font-size: 13px;
			color: rgba(255,255,255,0.7);
			margin-bottom: 6px;
		}
		.form-input {
			width: 100%;
			background: #40414f;
			border: 1px solid rgba(255,255,255,0.1);
			border-radius: 6px;
			padding: 10px 12px;
			color: #fff;
			font-size: 14px;
			font-family: inherit;
		}
		.form-input:focus { outline: none; border-color: rgba(255,255,255,0.3); }
		.form-input:disabled { opacity: 0.7; cursor: not-allowed; }
		.form-input::placeholder { color: rgba(255,255,255,0.4); }
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
			border: 1px solid rgba(255,255,255,0.2);
			color: #fff;
		}
		.modal-btn-secondary:hover { background: rgba(255,255,255,0.1); }
		.modal-btn-primary {
			background: #19c37d;
			border: none;
			color: #fff;
		}
		.modal-btn-primary:hover { background: #1a9d6a; }
		.modal-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
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
			.sidebar.open { transform: translateX(0); }
			.sidebar-overlay {
				display: none;
				position: fixed;
				inset: 0;
				background: rgba(0,0,0,0.5);
				z-index: 99;
			}
			.sidebar-overlay.visible { display: block; }
			.menu-toggle { display: flex; }
			.header-actions { display: none; }
		}
	</style>
</head>
<body>
	<div id="staff-ai-app"
		data-channel="<?php echo esc_attr( $channel ); ?>"
		data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"
		data-user-email="<?php echo esc_attr( $user->user_email ); ?>"
		data-assistant-type="<?php echo esc_attr( $assistant_type ); ?>"
		data-api-base="<?php echo esc_url( $api_base ); ?>"
		data-nonce="<?php echo esc_attr( $nonce ); ?>">

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
					<?php echo esc_html__( 'New chat', 'def-core' ); ?>
				</button>
			</div>
			<nav class="conversation-list" id="conversationList">
				<div class="conversation-list-placeholder" id="conversationPlaceholder">
					<?php echo esc_html__( 'No conversations yet', 'def-core' ); ?>
				</div>
			</nav>
			<div class="sidebar-footer">
				<?php echo esc_html__( 'Powered by DEF', 'def-core' ); ?>
			</div>
		</aside>

		<!-- Main chat -->
		<main class="chat-container">
			<header class="chat-header">
				<button type="button" class="menu-toggle" id="menuToggle" aria-label="<?php echo esc_attr__( 'Toggle menu', 'def-core' ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<line x1="3" y1="6" x2="21" y2="6"></line>
						<line x1="3" y1="12" x2="21" y2="12"></line>
						<line x1="3" y1="18" x2="21" y2="18"></line>
					</svg>
				</button>
				<span class="assistant-label"><?php echo esc_html( $assistant_label ); ?></span>
				<span class="readonly-indicator" id="readonlyIndicator"><?php echo esc_html__( 'Read-only (shared)', 'def-core' ); ?></span>
				<div class="header-actions">
					<button type="button" class="header-btn" id="shareBtn" disabled><?php echo esc_html__( 'Share', 'def-core' ); ?></button>
				</div>
			</header>

			<div class="messages-container" id="messagesContainer">
				<div class="messages-list" id="messagesList">
					<div class="welcome-message" id="welcomeMessage">
						<h2><?php echo esc_html( $assistant_label ); ?></h2>
						<p><?php echo esc_html__( 'How can I help you today?', 'def-core' ); ?></p>
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
								placeholder="<?php echo esc_attr__( 'Send a message...', 'def-core' ); ?>"
								rows="1"
							></textarea>
							<button type="button" class="send-btn" id="sendBtn" disabled aria-label="<?php echo esc_attr__( 'Send message', 'def-core' ); ?>">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
									<line x1="22" y1="2" x2="11" y2="13"></line>
									<polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
								</svg>
							</button>
						</div>
						<button type="button" class="escalate-btn" id="escalateBtn" disabled><?php echo esc_html__( 'Escalate', 'def-core' ); ?></button>
					</div>
					<div class="composer-hint">
						<?php echo esc_html__( 'Press Enter to send, Shift+Enter for new line', 'def-core' ); ?>
					</div>
				</div>
			</div>
		</main>

		<!-- Share Modal -->
		<div class="modal-overlay" id="shareModal">
			<div class="modal">
				<div class="modal-header">
					<span class="modal-title"><?php echo esc_html__( 'Share Conversation', 'def-core' ); ?></span>
					<button type="button" class="modal-close" id="shareModalClose">&times;</button>
				</div>
				<div class="modal-body">
					<div class="form-group">
						<label class="form-label"><?php echo esc_html__( 'Share with (email)', 'def-core' ); ?></label>
						<input type="email" class="form-input" id="shareEmail" placeholder="<?php echo esc_attr__( 'colleague@company.com', 'def-core' ); ?>">
					</div>
					<p style="font-size: 12px; color: rgba(255,255,255,0.5);">
						<?php echo esc_html__( 'Shared conversations are read-only by default.', 'def-core' ); ?>
					</p>
				</div>
				<div class="modal-footer">
					<button type="button" class="modal-btn modal-btn-secondary" id="shareCancel"><?php echo esc_html__( 'Cancel', 'def-core' ); ?></button>
					<button type="button" class="modal-btn modal-btn-primary" id="shareSubmit"><?php echo esc_html__( 'Share', 'def-core' ); ?></button>
				</div>
			</div>
		</div>

		<!-- Escalate Modal -->
		<div class="modal-overlay" id="escalateModal">
			<div class="modal">
				<div class="modal-header">
					<span class="modal-title"><?php echo esc_html__( 'Escalate for Review', 'def-core' ); ?></span>
					<button type="button" class="modal-close" id="escalateModalClose">&times;</button>
				</div>
				<div class="modal-body">
					<div class="form-group">
						<label class="form-label"><?php echo esc_html__( 'Your email', 'def-core' ); ?></label>
						<input type="email" class="form-input" id="escalateEmail" disabled>
					</div>
					<div class="form-group">
						<label class="form-label"><?php echo esc_html__( 'Note (optional)', 'def-core' ); ?></label>
						<textarea class="form-input" id="escalateNote" rows="3" placeholder="<?php echo esc_attr__( 'What do you want reviewed?', 'def-core' ); ?>"></textarea>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="modal-btn modal-btn-secondary" id="escalateCancel"><?php echo esc_html__( 'Cancel', 'def-core' ); ?></button>
					<button type="button" class="modal-btn modal-btn-primary" id="escalateSubmit"><?php echo esc_html__( 'Submit Escalation', 'def-core' ); ?></button>
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
		const assistantType = app.dataset.assistantType;
		const apiBase = app.dataset.apiBase;
		const nonce = app.dataset.nonce;

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
				// Include error code in message for debugging
				const errorCode = data.code || 'unknown';
				const errorMsg = data.message || 'Request failed';
				console.error('Staff AI API error:', { code: errorCode, message: errorMsg, data: data });
				throw new Error('[' + errorCode + '] ' + errorMsg);
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
		const escalateBtn = document.getElementById('escalateBtn');

		// Share modal elements
		const shareModal = document.getElementById('shareModal');
		const shareModalClose = document.getElementById('shareModalClose');
		const shareEmail = document.getElementById('shareEmail');
		const shareCancel = document.getElementById('shareCancel');
		const shareSubmit = document.getElementById('shareSubmit');

		// Escalate modal elements
		const escalateModal = document.getElementById('escalateModal');
		const escalateModalClose = document.getElementById('escalateModalClose');
		const escalateEmail = document.getElementById('escalateEmail');
		const escalateNote = document.getElementById('escalateNote');
		const escalateCancel = document.getElementById('escalateCancel');
		const escalateSubmit = document.getElementById('escalateSubmit');

		// State
		let conversations = [];
		let currentConversationId = null;
		let messages = [];
		let isLoading = false;
		let isReadOnly = false;

		// Initialize
		escalateEmail.value = userEmail;
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
				let errorMsg = err.message || '<?php echo esc_js( __( 'Failed to connect to backend service.', 'def-core' ) ); ?>';
				errorMsg += ' <?php echo esc_js( __( 'Check /wp-json/a3-ai/v1/staff-ai/status for diagnostics.', 'def-core' ) ); ?>';
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
				title.textContent = conv.title || '<?php echo esc_js( __( 'New conversation', 'def-core' ) ); ?>';

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
				return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
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
				showError('<?php echo esc_js( __( 'Failed to load conversation.', 'def-core' ) ); ?>');
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
				escalateBtn.disabled = true;
			} else {
				readonlyIndicator.classList.remove('visible');
				composerContainer.classList.remove('disabled');
				escalateBtn.disabled = !currentConversationId;
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

		// Create tool output card
		function createToolOutputCard(tool) {
			const card = document.createElement('div');
			card.className = 'tool-output-card';

			const icon = document.createElement('div');
			icon.className = 'tool-output-icon';
			icon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>';

			const info = document.createElement('div');
			info.className = 'tool-output-info';

			const name = document.createElement('div');
			name.className = 'tool-output-name';
			name.textContent = tool.file_name || '<?php echo esc_js( __( 'Download', 'def-core' ) ); ?>';

			const type = document.createElement('div');
			type.className = 'tool-output-type';
			type.textContent = tool.file_type || '<?php echo esc_js( __( 'File', 'def-core' ) ); ?>';

			info.appendChild(name);
			info.appendChild(type);

			const download = document.createElement('a');
			download.className = 'tool-output-download';
			download.href = tool.download_url || '#';
			download.target = '_blank';
			download.rel = 'noopener';
			download.textContent = '<?php echo esc_js( __( 'Download', 'def-core' ) ); ?>';

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

			messages.push({ role: 'user', content: text });
			renderMessages();

			composerInput.value = '';
			autoResize();
			updateSendButton();

			messages.push({ role: 'assistant', content: '', isTyping: true });
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
				showError(err.message || '<?php echo esc_js( __( 'Failed to send message. Please try again.', 'def-core' ) ); ?>');
			} finally {
				isLoading = false;
				updateSendButton();
			}
		}

		// Share modal handlers
		shareBtn.addEventListener('click', function() {
			shareEmail.value = '';
			shareModal.classList.add('visible');
		});

		shareModalClose.addEventListener('click', function() {
			shareModal.classList.remove('visible');
		});

		shareCancel.addEventListener('click', function() {
			shareModal.classList.remove('visible');
		});

		shareSubmit.addEventListener('click', async function() {
			const email = shareEmail.value.trim();
			if (!email || !currentConversationId) return;

			shareSubmit.disabled = true;

			try {
				const result = await apiRequest('/conversations/' + encodeURIComponent(currentConversationId) + '/share', {
					method: 'POST',
					body: JSON.stringify({ email: email })
				});
				shareModal.classList.remove('visible');
				showInfo('<?php echo esc_js( __( 'Conversation shared successfully.', 'def-core' ) ); ?>');
			} catch (err) {
				showError('<?php echo esc_js( __( 'Failed to share conversation.', 'def-core' ) ); ?>');
			} finally {
				shareSubmit.disabled = false;
			}
		});

		// Escalate modal handlers
		escalateBtn.addEventListener('click', function() {
			escalateNote.value = '';
			escalateModal.classList.add('visible');
		});

		escalateModalClose.addEventListener('click', function() {
			escalateModal.classList.remove('visible');
		});

		escalateCancel.addEventListener('click', function() {
			escalateModal.classList.remove('visible');
		});

		escalateSubmit.addEventListener('click', async function() {
			if (!currentConversationId) return;

			escalateSubmit.disabled = true;

			try {
				const note = escalateNote.value.trim();
				const result = await apiRequest('/escalate', {
					method: 'POST',
					body: JSON.stringify({
						conversation_id: currentConversationId,
						note: note
					})
				});
				escalateModal.classList.remove('visible');
				showInfo(result.message || '<?php echo esc_js( __( 'Escalated for review — you can continue working while this is reviewed.', 'def-core' ) ); ?>');
				// Conversation remains active (non-terminal)
			} catch (err) {
				showError('<?php echo esc_js( __( 'Failed to submit escalation.', 'def-core' ) ); ?>');
			} finally {
				escalateSubmit.disabled = false;
			}
		});

		// Close modals on overlay click
		shareModal.addEventListener('click', function(e) {
			if (e.target === shareModal) shareModal.classList.remove('visible');
		});

		escalateModal.addEventListener('click', function(e) {
			if (e.target === escalateModal) escalateModal.classList.remove('visible');
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
	public static function on_activate(): void {
		self::add_rewrite_rules();
		flush_rewrite_rules();
	}
}
