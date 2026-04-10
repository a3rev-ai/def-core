<?php
/**
 * Class DEF_Core_Site_Tools
 *
 * REST passthrough endpoint for Site Intelligence Tools.
 *
 * Allows Staff AI to query/modify the WordPress + WooCommerce site via the
 * REST API, executing requests as the authenticated user. WordPress capability
 * checks fire on the dispatched endpoint — DEF does not duplicate them.
 *
 * Architecture: DEF sends HMAC-signed POST to this endpoint with
 * {namespace, route, method, params, body}. We validate, set user context,
 * dispatch via rest_do_request(), and return the result.
 *
 * Wire contract:
 * - This endpoint always returns HTTP 200 to DEF (the HMAC transport layer).
 * - The inner ['status'] field reflects the REAL WordPress REST status (200, 403, 404, etc.).
 * - When status >= 400, ['data'] contains the WordPress error object ({code, message}).
 * - DEF Python side checks result['status'] >= 400 to detect errors.
 * - Pagination metadata (['pagination']['total'], ['pagination']['total_pages']) is extracted
 *   from WP response headers and included when present.
 *
 * @package def-core
 * @since 2.1.0
 * @see DEF-SITE-INTELLIGENCE-TOOLS Spec V1.1
 */

declare(strict_types=1);

class DEF_Core_Site_Tools {

	/**
	 * Namespace allowlist — only these REST API namespaces can be accessed.
	 * Add entries here to enable additional plugin namespaces (e.g., 'wc-analytics').
	 */
	private const ALLOWED_NAMESPACES = [
		'wp/v2',
		'wc/v3',
	];

	/**
	 * Allowed HTTP methods for the passthrough.
	 */
	private const ALLOWED_METHODS = [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ];

	/**
	 * Maximum response body size to return (bytes).
	 * Prevents oversized payloads from blowing up the LLM context window.
	 */
	private const MAX_RESPONSE_BYTES = 100000; // 100KB

	/**
	 * Route character allowlist regex. Only alphanumeric, underscore, hyphen, and
	 * forward slash are valid in WordPress REST routes.
	 */
	private const ROUTE_PATTERN = '/[^a-zA-Z0-9_\/-]/';

	/**
	 * Initialize: register REST routes on rest_api_init.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Register the REST passthrough route.
	 */
	public static function register_rest_routes(): void {
		register_rest_route( 'def-core/v1', '/site/rest-passthrough', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_passthrough' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );
	}

	/**
	 * Permission callback — HMAC signature + user existence only.
	 *
	 * Capability checks and wp_set_current_user() are in the main handler
	 * to avoid side-effects surviving failed validation.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return true|\WP_Error
	 */
	public static function permission_check( \WP_REST_Request $request ) {
		$hmac_result = \A3Rev\DefCore\DEF_Core_HMAC_Auth::verify_request( $request );
		if ( is_wp_error( $hmac_result ) ) {
			return $hmac_result;
		}

		$user_id = isset( $_SERVER['HTTP_X_DEF_USER'] )
			? intval( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_USER'] ) ) )
			: 0;

		if ( $user_id < 1 ) {
			return new \WP_Error( 'rest_not_logged_in', 'User ID required.', array( 'status' => 401 ) );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! $user->exists() ) {
			return new \WP_Error( 'rest_user_not_found', 'User not found.', array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * REST passthrough handler.
	 *
	 * Sets user context, validates input, dispatches via rest_do_request(),
	 * and returns the result with pagination metadata.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_passthrough( \WP_REST_Request $request ) {
		// Set user context in handler (not permission_callback) to avoid
		// side-effects surviving failed validation. Restore after dispatch.
		$user_id = isset( $_SERVER['HTTP_X_DEF_USER'] )
			? intval( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_USER'] ) ) )
			: 0;
		$original_user_id = get_current_user_id();
		wp_set_current_user( $user_id );

		// DEF capability check — must have at least staff access.
		if ( ! current_user_can( 'def_staff_access' ) && ! current_user_can( 'def_management_access' ) ) {
			wp_set_current_user( $original_user_id );
			return new \WP_Error( 'rest_forbidden', 'Insufficient DEF capabilities.', array( 'status' => 403 ) );
		}

		$body      = $request->get_json_params();
		$namespace = sanitize_text_field( $body['namespace'] ?? '' );
		$route     = ltrim( $body['route'] ?? '', '/' );
		$method    = strtoupper( sanitize_text_field( $body['method'] ?? 'GET' ) );
		$params    = $body['params'] ?? array();
		$req_body  = $body['body'] ?? array();

		// Validate namespace against allowlist.
		if ( ! in_array( $namespace, self::ALLOWED_NAMESPACES, true ) ) {
			wp_set_current_user( $original_user_id );
			return new \WP_Error(
				'invalid_namespace',
				'Namespace not allowed: ' . $namespace,
				array( 'status' => 400 )
			);
		}

		// Validate HTTP method.
		if ( ! in_array( $method, self::ALLOWED_METHODS, true ) ) {
			wp_set_current_user( $original_user_id );
			return new \WP_Error(
				'invalid_method',
				'HTTP method not allowed: ' . $method,
				array( 'status' => 400 )
			);
		}

		// Route character allowlist + path traversal rejection.
		if ( empty( $route ) || preg_match( self::ROUTE_PATTERN, $route ) || preg_match( '/\.\./', $route ) ) {
			wp_set_current_user( $original_user_id );
			return new \WP_Error(
				'invalid_route',
				'Route is empty or contains invalid characters.',
				array( 'status' => 400 )
			);
		}

		// Build the internal REST request.
		$rest_route = '/' . $namespace . '/' . $route;
		$internal   = new \WP_REST_Request( $method, $rest_route );

		// Set query parameters.
		if ( is_array( $params ) ) {
			foreach ( $params as $key => $value ) {
				$internal->set_param( $key, $value );
			}
		}

		// Set body for write methods.
		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) && ! empty( $req_body ) ) {
			$internal->set_body( wp_json_encode( $req_body ) );
			$internal->set_header( 'Content-Type', 'application/json' );
			// Also set body params for handlers that read from get_json_params().
			if ( is_array( $req_body ) ) {
				foreach ( $req_body as $key => $value ) {
					$internal->set_param( $key, $value );
				}
			}
		}

		// Dispatch internally — WordPress REST permission callbacks fire here.
		$server   = rest_get_server();
		$response = $server->dispatch( $internal );
		$data     = $server->response_to_data( $response, false );
		$status   = $response->get_status();

		// Restore original user context.
		wp_set_current_user( $original_user_id );

		// Audit log — includes params for meaningful query context.
		$params_summary = ! empty( $params ) ? wp_json_encode( $params ) : '{}';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'[DEF_SITE_TOOLS] user=%d method=%s route=%s params=%s status=%d',
			$user_id, $method, $rest_route, $params_summary, $status
		) );

		// Truncate oversized responses.
		$json_data    = wp_json_encode( $data );
		$json_data_len = strlen( $json_data );

		if ( $json_data_len > self::MAX_RESPONSE_BYTES ) {
			if ( is_array( $data ) && wp_is_numeric_array( $data ) ) {
				// Indexed array (list) — truncate to first 10 items.
				$truncated = array_slice( $data, 0, 10 );
				return new \WP_REST_Response( array(
					'data'        => $truncated,
					'truncated'   => true,
					'total_items' => count( $data ),
					'status'      => $status,
					'_note'       => 'Response truncated. Use per_page and page params to paginate.',
				), 200 );
			}

			// Object response — return a byte-limited preview.
			return new \WP_REST_Response( array(
				'data_preview' => substr( $json_data, 0, self::MAX_RESPONSE_BYTES ),
				'truncated'    => true,
				'status'       => $status,
				'_note'        => 'Response object was too large. Try ?_fields= to limit returned fields.',
			), 200 );
		}

		// Include pagination headers in the response.
		$headers = $response->get_headers();
		$meta    = array();
		if ( isset( $headers['X-WP-Total'] ) ) {
			$meta['total'] = intval( $headers['X-WP-Total'] );
		}
		if ( isset( $headers['X-WP-TotalPages'] ) ) {
			$meta['total_pages'] = intval( $headers['X-WP-TotalPages'] );
		}

		$result = array( 'data' => $data, 'status' => $status );
		if ( $meta ) {
			$result['pagination'] = $meta;
		}

		return new \WP_REST_Response( $result, 200 );
	}
}
