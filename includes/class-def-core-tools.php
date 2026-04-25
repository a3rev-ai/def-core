<?php
/**
 * Class DEF_Core_Tools
 *
 * Tools for the Digital Employee Framework - Core plugin.
 *
 * @package def-core
 * @since 0.1.0
 * @version 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DEF_Core_Tools
 *
 * Tools for the Digital Employee Framework - Core plugin.
 *
 * @package def-core
 * @since 0.1.0
 * @version 0.1.0
 */
final class DEF_Core_Tools {

	/**
	 * Issue a context token.
	 *
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function rest_issue_context_token(): \WP_REST_Response {
		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			return new \WP_REST_Response( array( 'error' => 'unauthorized' ), 401 );
		}
		$claims = array(
			'sub'          => (string) $user->ID,
			'username'     => $user->user_login,
			'display_name' => $user->display_name,
			'first_name'   => $user->user_firstname,
			'email'        => $user->user_email,
			'roles'        => array_values( (array) $user->roles ),
			'capabilities' => self::get_user_def_capabilities( $user ),
			'iss'          => get_site_url(),
			'aud'          => DEF_CORE_AUDIENCE,
		);
		$jwt      = DEF_Core_JWT::issue_token( $claims, 300 ); // 5 minutes.
		$response = new \WP_REST_Response(
			array(
				'token' => $jwt,
				'exp'   => time() + 300,
			),
			200
		);
		$response->set_headers( array( 'Cache-Control' => 'no-store' ) );
		return $response;
	}

	/**
	 * BFF proxy for Customer Chat — proxies chat requests to DEF backend.
	 * WordPress resolves identity and forwards with trusted headers.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return void|\WP_Error
	 */
	public static function rest_proxy_chat_stream( $request ) {
		// No-silent-downgrade: logged-in user with bad nonce must be rejected
		if ( is_user_logged_in() ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new \WP_Error(
					'invalid_nonce',
					'Authentication required.',
					array( 'status' => 403 )
				);
			}
		}

		$headers = self::build_proxy_headers();
		$def_url = \DEF_Core::get_def_api_url_internal() . '/api/chat/stream';
		self::stream_proxy( $def_url, $headers, $request->get_body() );
	}

	/**
	 * SSE streaming proxy — forwards request to DEF and streams response back.
	 *
	 * @param string $url     DEF backend URL.
	 * @param array  $headers HTTP headers for the upstream request.
	 * @param string $body    Request body.
	 * @return void
	 */
	private static function stream_proxy( $url, $headers, $body ) {
		// Disable all output buffering for real-time streaming
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );

		$ch = curl_init( $url );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_POSTFIELDS     => $body,
			// No total timeout — stream as long as tokens are flowing.
			// Kill only if the connection stalls (no data for 30 seconds).
			// This prevents legitimate long responses (e.g. analysing a 34KB
			// spec document) from being cut off at a fixed timeout, while
			// still catching genuinely stalled connections.
			CURLOPT_TIMEOUT        => 0,
			CURLOPT_LOW_SPEED_LIMIT => 1,    // At least 1 byte/sec
			CURLOPT_LOW_SPEED_TIME  => 30,   // Kill after 30s of no data
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) {
				echo $data;
				if ( ob_get_level() ) {
					ob_flush();
				}
				flush();
				return strlen( $data );
			},
		) );

		$result = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( $result === false ) {
			$error = curl_error( $ch );
			curl_close( $ch );
			echo "data: {\"type\":\"error\",\"message\":\"Backend connection failed.\"}\n\n";
			flush();
			return;
		}

		curl_close( $ch );
	}

	/**
	 * JSON proxy — forwards a POST request to DEF and returns the JSON response.
	 *
	 * Used for upload init/commit endpoints that return JSON (not SSE).
	 *
	 * @param string $url     DEF backend URL.
	 * @param array  $headers HTTP headers for the upstream request.
	 * @param string $body    Request body.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function json_proxy( $url, $headers, $body ) {
		$ch = curl_init( $url );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_RETURNTRANSFER => true,
		) );

		$response  = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( $response === false ) {
			$error = curl_error( $ch );
			curl_close( $ch );
			return new \WP_Error( 'proxy_error', 'Backend connection failed: ' . $error, array( 'status' => 502 ) );
		}

		curl_close( $ch );

		$data = json_decode( $response, true );
		if ( null === $data && '' !== $response ) {
			$data = array( 'raw' => $response );
		}

		return new \WP_REST_Response( $data ?: array(), $http_code );
	}

	/**
	 * Build trusted BFF proxy headers for DEF backend requests.
	 *
	 * Always sends: Content-Type, X-DEF-API-Key, X-DEF-User (if logged in).
	 *
	 * When $include_user_context is true (staff_ai / setup_assistant), also sends:
	 *   - X-DEF-User-Capabilities (comma-separated DEF capabilities)
	 *   - X-DEF-User-Display-Name (URL-encoded)
	 *   - X-DEF-User-Email (URL-encoded)
	 *   - X-DEF-User-Roles (comma-separated WP roles)
	 *
	 * Customer Chat calls this with $include_user_context = false — identity
	 * headers are intentionally NOT sent (privacy boundary).
	 *
	 * @param bool $include_capabilities Whether to include capabilities + identity headers.
	 * @return array HTTP header strings (indexed, not associative).
	 */
	private static function build_proxy_headers( $include_capabilities = false ) {
		$headers = array(
			'Content-Type: application/json',
			'X-DEF-API-Key: ' . \DEF_Core_Encryption::get_secret( 'def_core_api_key' ),
		);

		if ( is_user_logged_in() ) {
			$headers[] = 'X-DEF-User: ' . get_current_user_id();

			if ( $include_capabilities ) {
				$user = wp_get_current_user();
				$caps = self::get_user_def_capabilities( $user );
				if ( ! empty( $caps ) ) {
					$headers[] = 'X-DEF-User-Capabilities: ' . implode( ',', $caps );
				}

				// Identity headers — let DEF restore the "## Current authenticated
				// user" prompt section that JWT auth previously provided. Sent for
				// staff_ai / setup_assistant only ($include_capabilities = true).
				// Customer Chat does NOT receive these (privacy boundary).
				//
				// Values are URL-encoded so Unicode names/emails survive HTTP
				// header transport. DEF decodes via urllib.parse.unquote().
				if ( ! empty( $user->display_name ) ) {
					$headers[] = 'X-DEF-User-Display-Name: ' . rawurlencode( $user->display_name );
				}
				if ( ! empty( $user->user_email ) ) {
					$headers[] = 'X-DEF-User-Email: ' . rawurlencode( $user->user_email );
				}
				if ( ! empty( $user->roles ) && is_array( $user->roles ) ) {
					// Filter to plain strings (defensive — WP guarantees this but
					// some plugins inject non-string entries)
					$roles = array_filter( $user->roles, 'is_string' );
					if ( ! empty( $roles ) ) {
						$headers[] = 'X-DEF-User-Roles: ' . implode( ',', $roles );
					}
				}

				// Site name — lets Staff AI reference the site by name in responses.
				$site_name = get_bloginfo( 'name' );
				if ( ! empty( $site_name ) ) {
					$headers[] = 'X-DEF-Site-Name: ' . rawurlencode( $site_name );
				}
			}
		}

		return $headers;
	}

	/**
	 * BFF proxy for async tool-result confirmation.
	 *
	 * Called by the browser after a wp_rest_call UI action completes, to
	 * close the agentic loop (Reason → Act → Observe) for async tools like
	 * add_to_cart_by_name. DEF records the confirmation against the
	 * originating tool_call_id so next-turn rehydration has the real
	 * server result, not just the LLM's pre-execution guess.
	 *
	 * Auth mirrors rest_proxy_chat_stream: nonce required for logged-in
	 * users, anonymous visitors fall through to the DEF-side signed-
	 * visitor-cookie thread-ownership check.
	 *
	 * Spec: DEF-AGENTIC-LOOP-CLOSURE-V1.2 §4.2.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_proxy_tool_result_confirm( $request ) {
		if ( is_user_logged_in() ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new \WP_Error(
					'invalid_nonce',
					'Authentication required.',
					array( 'status' => 403 )
				);
			}
		}

		$headers = self::build_proxy_headers();
		$def_url = \DEF_Core::get_def_api_url_internal() . '/api/chat/tool-result-confirm';
		return self::json_proxy( $def_url, $headers, $request->get_body() );
	}

	/**
	 * BFF proxy for Customer Chat upload init.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_proxy_upload_init( $request ) {
		$headers = self::build_proxy_headers();
		$def_url = \DEF_Core::get_def_api_url_internal() . '/api/customer/uploads/init';
		return self::json_proxy( $def_url, $headers, $request->get_body() );
	}

	/**
	 * BFF proxy for Customer Chat upload commit.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_proxy_upload_commit( $request ) {
		$headers = self::build_proxy_headers();
		$def_url = \DEF_Core::get_def_api_url_internal() . '/api/customer/uploads/commit';
		return self::json_proxy( $def_url, $headers, $request->get_body() );
	}

	/**
	 * BFF proxy for Staff AI chat stream.
	 * Auth: logged-in user with def_staff_access or def_management_access.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return void|\WP_Error
	 */
	public static function rest_proxy_staff_ai_stream( $request ) {
		$headers = self::build_proxy_headers( true );
		$def_url = \DEF_Core::get_def_api_url_internal() . '/api/staff-ai/chat/stream';
		self::stream_proxy( $def_url, $headers, $request->get_body() );
	}

	/**
	 * BFF proxy for Staff AI status check.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_proxy_staff_ai_status( $request ) {
		$headers = self::build_proxy_headers( true );
		$def_url = \DEF_Core::get_def_api_url_internal() . '/api/staff-ai/status';
		return self::json_proxy_get( $def_url, $headers );
	}

	/**
	 * BFF proxy for Setup Assistant chat stream.
	 * Auth: logged-in user with def_admin_access.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return void|\WP_Error
	 */
	public static function rest_proxy_setup_assistant_stream( $request ) {
		$headers = self::build_proxy_headers( true );
		$def_url = \DEF_Core::get_def_api_url_internal() . '/api/setup_assistant/chat/stream';
		self::stream_proxy( $def_url, $headers, $request->get_body() );
	}

	/**
	 * JSON proxy for GET requests — forwards to DEF and returns the JSON response.
	 *
	 * @param string $url     DEF backend URL.
	 * @param array  $headers HTTP headers for the upstream request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function json_proxy_get( $url, $headers ) {
		$ch = curl_init( $url );
		curl_setopt_array( $ch, array(
			CURLOPT_HTTPGET        => true,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_RETURNTRANSFER => true,
		) );

		$response  = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( $response === false ) {
			$error = curl_error( $ch );
			curl_close( $ch );
			return new \WP_Error( 'proxy_error', 'Backend connection failed: ' . $error, array( 'status' => 502 ) );
		}

		curl_close( $ch );

		$data = json_decode( $response, true );
		if ( null === $data && '' !== $response ) {
			$data = array( 'raw' => $response );
		}

		return new \WP_REST_Response( $data ?: array(), $http_code );
	}

	/**
	 * Get the DEF capabilities for a WordPress user.
	 *
	 * @param \WP_User $user The user to check.
	 * @return array List of DEF capability strings the user has.
	 */
	public static function get_user_def_capabilities( \WP_User $user ): array {
		$all  = array( 'def_admin_access', 'def_staff_access', 'def_management_access' );
		$caps = array();
		foreach ( $all as $cap ) {
			if ( $user->has_cap( $cap ) ) {
				$caps[] = $cap;
			}
		}
		return $caps;
	}

	/**
	 * Get the JWKS.
	 *
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function rest_get_jwks(): \WP_REST_Response {
		return new \WP_REST_Response( DEF_Core_JWT::get_jwks(), 200 );
	}

	/**
	 * Get the bearer token from the request.
	 *
	 * @return string|null The bearer token or null if not found.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	private static function get_bearer_token(): ?string {
		$auth = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( isset( $_SERVER['Authorization'] ) ) {
			$auth = sanitize_text_field( wp_unslash( $_SERVER['Authorization'] ) );
		}
		if ( $auth && stripos( $auth, 'bearer ' ) === 0 ) {
			return trim( substr( $auth, 7 ) );
		}
		return null;
	}

	/**
	 * Verify the bearer token and get the user.
	 *
	 * @return \WP_User|null The user object or null if not found.
	 * @since 0.1.0
	 * @version 0.2.0 - Made public for module use
	 */
	public static function verify_and_get_user( $request = null ): ?\WP_User {
		// Path 1: Bearer JWT (Staff AI, Setup Assistant, legacy)
		$jwt = self::get_bearer_token();
		if ( $jwt ) {
			$payload = DEF_Core_JWT::verify_token( $jwt );
			if ( is_array( $payload ) ) {
				$user_id = isset( $payload['sub'] ) ? absint( $payload['sub'] ) : 0;
				if ( $user_id ) {
					return self::set_and_return_user( $user_id );
				}
			}
			return null;
		}

		// Path 2: HMAC auth + X-DEF-User header (BFF proxy tool callbacks)
		if ( $request instanceof \WP_REST_Request ) {
			$hmac_result = \A3Rev\DefCore\DEF_Core_HMAC_Auth::verify_request( $request );
			if ( true === $hmac_result ) {
				$user_id_header = isset( $_SERVER['HTTP_X_DEF_USER'] )
					? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEF_USER'] ) )
					: '';
				$user_id = absint( $user_id_header );
				if ( $user_id ) {
					return self::set_and_return_user( $user_id );
				}
			}
		}

		return null;
	}

	/**
	 * Set WordPress current user and return the user object.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return \WP_User|null User object if valid, null otherwise.
	 */
	private static function set_and_return_user( int $user_id ): ?\WP_User {
		$user = get_user_by( 'id', $user_id );
		if ( ! ( $user instanceof \WP_User ) ) {
			return null;
		}
		wp_set_current_user( $user->ID );
		$current = wp_get_current_user();
		return ( $current instanceof \WP_User && $current->exists() ) ? $current : null;
	}

	/**
	 * Permission callback for tool routes.
	 * Verifies JWT or HMAC auth and sets current user context.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool True if user is authenticated, false otherwise.
	 * @since 0.1.0
	 */
	public static function permission_check( $request = null ): bool {
		$user = self::verify_and_get_user( $request );
		return ( $user instanceof \WP_User && $user->exists() );
	}

	/**
	 * Get the user's profile.
	 *
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function me(): \WP_REST_Response {
		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Unauthorized',
				),
				401
			);
		}

		$data = DEF_Core_Cache::get_or_set(
			'me',
			$user->ID,
			604800, // 7 days - user profile rarely changes (should be cached for a week).
			function () use ( $user ) {
				return array(
					'id'           => (int) $user->ID,
					'username'     => $user->user_login,
					'display_name' => $user->display_name,
					'first_name'   => $user->first_name,
					'last_name'    => $user->last_name,
					'email'        => $user->user_email,
					'roles'        => array_values( (array) $user->roles ),
				);
			}
		);

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Get the user's orders.
	 *
	 * @param \WP_REST_Request $req The request object.
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function wc_orders( \WP_REST_Request $req ): \WP_REST_Response {
		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Unauthorized',
				),
				401
			);
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'WooCommerce not active',
				),
				400
			);
		}

		$limit  = intval( $req['limit'] ?? -1 );
		$status = sanitize_text_field( $req['status'] ?? '' );

		// Build cache key with params.
		$cache_key = "orders_limit{$limit}";
		if ( $status ) {
			$cache_key .= "_status{$status}";
		}

		$data = DEF_Core_Cache::get_or_set(
			$cache_key,
			$user->ID,
			604800, // 7 days - orders are more dynamic (should be cached for a week).
			function () use ( $user, $limit, $status ) {
				$args = array(
					'customer_id' => (int) $user->ID,
					'limit'       => $limit,
					'orderby'     => 'date',
					'order'       => 'DESC',
					'return'      => 'ids',
				);
				if ( $status ) {
					$args['status'] = $status;
				}
				$order_ids = wc_get_orders( $args );
				$out       = array();
				foreach ( $order_ids as $oid ) {
					$o = wc_get_order( $oid );
					if ( ! $o ) {
						continue;
					}
					// Get product names from order items.
					$product_names = array();
					foreach ( $o->get_items() as $item ) {
						$product_names[] = $item->get_name();
					}
					$out[] = array(
						'id'       => (int) $o->get_id(),
						'date'     => $o->get_date_created() ? $o->get_date_created()->date( 'c' ) : null,
						'status'   => $o->get_status(),
						'total'    => (string) $o->get_total(),
						'currency' => $o->get_currency(),
						'products' => $product_names,
					);
				}
				return array(
					'total_orders' => count( $out ),
					'orders'       => $out,
				);
			}
		);

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Get the user's order detail.
	 *
	 * @param \WP_REST_Request $req The request object.
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function wc_order_detail( \WP_REST_Request $req ): \WP_REST_Response {
		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Unauthorized',
				),
				401
			);
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'WooCommerce not active',
				),
				400
			);
		}
		$order_id = intval( $req['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Invalid order_id',
				),
				400
			);
		}

		$data = DEF_Core_Cache::get_or_set(
			"order_detail_{$order_id}",
			$user->ID,
			604800, // 7 days - order details change less frequently (should be cached for a week).
			function () use ( $user, $order_id ) {
				$o = wc_get_order( $order_id );
				if ( ! $o ) {
					return array(
						'error'   => true,
						'message' => 'Order not found',
						'_status' => 404,
					);
				}
				if ( intval( $o->get_customer_id() ) !== intval( $user->ID ) ) {
					return array(
						'error'   => true,
						'message' => 'Forbidden',
						'_status' => 403,
					);
				}
				$items = array();
				foreach ( $o->get_items() as $item ) {
					$items[] = array(
						'id'       => (int) $item->get_id(),
						'name'     => $item->get_name(),
						'quantity' => (int) $item->get_quantity(),
						'total'    => (string) $item->get_total(),
					);
				}
				return array(
					'id'       => (int) $o->get_id(),
					'date'     => $o->get_date_created() ? $o->get_date_created()->date( 'c' ) : null,
					'status'   => $o->get_status(),
					'total'    => (string) $o->get_total(),
					'currency' => $o->get_currency(),
					'items'    => $items,
					'billing'  => array(
						'first_name' => $o->get_billing_first_name(),
						'last_name'  => $o->get_billing_last_name(),
						'email'      => $o->get_billing_email(),
						'country'    => $o->get_billing_country(),
					),
				);
			}
		);

		// Handle error responses from cache.
		if ( isset( $data['error'] ) && isset( $data['_status'] ) ) {
			$status = $data['_status'];
			unset( $data['_status'] );
			return new \WP_REST_Response( $data, $status );
		}

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Get all published products with their variations.
	 * This is a public endpoint (no user context needed) for LLM product selection.
	 * Results are cached for 1 hour.
	 *
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function wc_get_products_list(): \WP_REST_Response {
		if ( ! function_exists( 'WC' ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'WooCommerce not available',
				),
				400
			);
		}

		return DEF_Core_Cache::get_or_set(
			'products_list',
			0, // User ID 0 for public/shared cache.
			3600, // 1 hour - products change less frequently (should be cached for an hour).
			function () {
				// Get all published products.
				$args = array(
					'status'  => 'publish',
					'limit'   => -1,
					'orderby' => 'title',
					'order'   => 'ASC',
				);

				$products_data = array();
				$products      = wc_get_products( $args );

				foreach ( $products as $product ) {
					$product_info = array(
						'id'   => $product->get_id(),
						'name' => $product->get_name(),
						'type' => $product->get_type(),
					);

					// If variable product, get variations.
					if ( $product->is_type( 'variable' ) ) {
						$variations_data = array();
						$variations      = $product->get_available_variations();

						foreach ( $variations as $variation_data ) {
							$variation = wc_get_product( $variation_data['variation_id'] );
							if ( ! $variation ) {
								continue;
							}

							// Get variation attributes as readable string.
							$attributes      = $variation->get_variation_attributes();
							$attribute_names = array();
							foreach ( $attributes as $attr_name => $attr_value ) {
								$attribute_names[] = $attr_value;
							}

							$variations_data[] = array(
								'id'         => $variation_data['variation_id'],
								'name'       => $variation->get_name(),
								'attributes' => implode( ', ', $attribute_names ),
								'price'      => $variation->get_price(),
							);
						}

						$product_info['variations'] = $variations_data;
					} else {
						$product_info['price'] = $product->get_price();
					}

					$products_data[] = $product_info;
				}

				return new \WP_REST_Response(
					array(
						'success'        => true,
						'products'       => $products_data,
						'total_products' => count( $products_data ),
					),
					200
				);
			}
		);
	}

}
