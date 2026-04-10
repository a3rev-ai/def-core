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
			CURLOPT_TIMEOUT        => 90,
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
	 * Build trusted BFF proxy headers (API key + optional user ID + capabilities).
	 *
	 * @param bool $include_capabilities Whether to include X-DEF-User-Capabilities header.
	 * @return array HTTP header strings.
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
			}
		}

		return $headers;
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
	 * Parse WooCommerce session cookie from REST API request headers.
	 * This ensures REST API uses the same session as the browser for guest users.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return void
	 * @since 0.2.0
	 */
	private static function parse_wc_session_cookie_from_request( \WP_REST_Request $request ): void {
		// Get Cookie header from request.
		$cookie_header = $request->get_header( 'Cookie' );
		if ( empty( $cookie_header ) ) {
			// Fallback to $_SERVER if header not available.
			$cookie_header = isset( $_SERVER['HTTP_COOKIE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_COOKIE'] ) ) : '';
		}

		if ( empty( $cookie_header ) ) {
			return;
		}

		// Parse cookie header.
		$cookie_name = 'wp_woocommerce_session_' . COOKIEHASH;
		$cookies     = array();
		$pairs       = explode( '; ', $cookie_header );

		foreach ( $pairs as $pair ) {
			$parts = explode( '=', $pair, 2 );
			if ( count( $parts ) === 2 ) {
				$name             = trim( $parts[0] );
				$value            = urldecode( trim( $parts[1] ) );
				$cookies[ $name ] = $value;
			}
		}

		// If WooCommerce session cookie found, populate $_COOKIE so WooCommerce can use it.
		if ( isset( $cookies[ $cookie_name ] ) ) {
			$_COOKIE[ $cookie_name ] = $cookies[ $cookie_name ];
		}
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
	 * Permission callback for add-to-cart route.
	 * Allows guests if WooCommerce guest checkout is enabled.
	 *
	 * @return bool True if allowed (authenticated user OR guest checkout enabled).
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public static function permission_check_add_to_cart(): bool {
		// Check if user is authenticated.
		$user = self::verify_and_get_user();
		if ( $user instanceof \WP_User && $user->exists() ) {
			return true;
		}

		// Check if WooCommerce allows guest checkout.
		if ( function_exists( 'get_option' ) ) {
			$guest_checkout_enabled = get_option( 'woocommerce_enable_guest_checkout', 'no' );
			if ( 'yes' === $guest_checkout_enabled ) {
				return true;
			}
		}

		// Default: deny access.
		return false;
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

	/**
	 * Add product to cart.
	 * Accepts product_id (required) and optional variation_id.
	 * If product is variable and no variation_id provided, uses first available variation.
	 * Supports both authenticated users and guest checkout (if enabled in WooCommerce).
	 *
	 * @param \WP_REST_Request $req The request object.
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.2.0
	 */
	public static function wc_add_to_cart( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Cart' ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'WooCommerce not available',
				),
				400
			);
		}

		// Get current user (may be guest).
		$user     = wp_get_current_user();
		$is_guest = ( ! $user || 0 === $user->ID );

		// If guest, check if guest checkout is allowed.
		if ( $is_guest ) {
			$guest_checkout_enabled = get_option( 'woocommerce_enable_guest_checkout', 'no' );
			if ( 'yes' !== $guest_checkout_enabled ) {
				return new \WP_REST_Response(
					array(
						'error'   => true,
						'message' => 'Guest checkout is disabled. Please log in to add items to cart.',
						'code'    => 'guest_checkout_disabled',
					),
					403
				);
			}
		}

		$product_id   = intval( $req['product_id'] ?? 0 );
		$variation_id = intval( $req['variation_id'] ?? 0 );
		$quantity     = intval( $req['quantity'] ?? 1 );

		// Validate product_id.
		if ( $product_id <= 0 ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'product_id is required',
				),
				400
			);
		}

		// Verify product exists and is purchasable.
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Product not found',
				),
				404
			);
		}
		if ( ! $product->is_purchasable() ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Product is not purchasable',
				),
				400
			);
		}

		// Handle variable products.
		if ( $product->is_type( 'variable' ) ) {
			$variations = $product->get_available_variations();

			if ( empty( $variations ) ) {
				return new \WP_REST_Response(
					array(
						'error'   => true,
						'message' => 'No available variations for this product',
					),
					400
				);
			}

			// Validate variation_id if provided.
			if ( $variation_id > 0 ) {
				$valid_variation_ids = array_column( $variations, 'variation_id' );
				if ( ! in_array( $variation_id, $valid_variation_ids, true ) ) {
					return new \WP_REST_Response(
						array(
							'error'   => true,
							'message' => 'Invalid variation_id for this product',
						),
						400
					);
				}
			} else {
				// Use first available variation if not specified.
				$variation_id = (int) $variations[0]['variation_id'];
			}
		}

		// Add to cart.
		try {
			$current_user_id = get_current_user_id();

			// Initialize WooCommerce session if not already initialized (required for REST API context).
			if ( ! isset( WC()->session ) || '' === WC()->session || is_null( WC()->session ) ) {
				WC()->session = new \WC_Session_Handler();
				WC()->session->init();
			}

			// Set customer ID in session for logged-in user.
			if ( $current_user_id > 0 ) {
				WC()->session->set( 'customer_id', $current_user_id );
			} else {
				self::parse_wc_session_cookie_from_request( $req );
			}

			// Set customer session cookie if needed.
			if ( ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}

			// Load cart from session (this will use cookie if present, or create new session).
			if ( ! did_action( 'woocommerce_load_cart_from_session' ) ) {
				wc_load_cart();
			} else {
				// If cart was already loaded, ensure we reload it to get latest session data.
				WC()->cart->get_cart_from_session();
			}

			// Load user's existing cart from persistent storage (user meta) for logged-in users.
			// This ensures we ADD to existing cart instead of replacing it.
			if ( $current_user_id > 0 ) {
				$saved_cart = get_user_meta( $current_user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), true );
				if ( ! empty( $saved_cart['cart'] ) && is_array( $saved_cart['cart'] ) ) {
					// Merge saved cart into session.
					WC()->session->set( 'cart', $saved_cart['cart'] );
					// Reload cart from session.
					WC()->cart->get_cart_from_session();
				}
			}

			$cart_item_key = WC()->cart->add_to_cart(
				$product_id,
				$quantity,
				$variation_id
			);

			// Save cart back to persistent storage for logged-in user.
			if ( $current_user_id > 0 && $cart_item_key ) {
				$cart_to_save = array( 'cart' => WC()->session->get( 'cart', array() ) );
				update_user_meta( $current_user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), $cart_to_save );
			}

			// For guest users, ensure session data is saved to database.
			if ( 0 === $current_user_id && $cart_item_key && WC()->session ) {
				// Trigger session save to persist cart data.
				WC()->session->save_data();
			}

			if ( ! $cart_item_key ) {
				return new \WP_REST_Response(
					array(
						'error'   => true,
						'message' => 'Failed to add product to cart',
					),
					400
				);
			}

			// Invalidate user's cart cache.
			if ( $current_user_id > 0 ) {
				DEF_Core_Cache::invalidate_user( $current_user_id, 'cart_' );
			} else {
				// For guests, invalidate by session customer ID.
				$customer_id = WC()->session ? WC()->session->get_customer_id() : '';
				if ( $customer_id ) {
					DEF_Core_Cache::invalidate_user( (int) $customer_id, 'cart_' );
				}
			}

			// Get updated cart info.
			$cart         = WC()->cart;
			$product_info = array(
				'id'   => (int) $product->get_id(),
				'name' => $product->get_name(),
			);

			// Add variation info if applicable.
			if ( $variation_id > 0 ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation ) {
					$product_info['variation_id']   = (int) $variation_id;
					$product_info['variation_name'] = $variation->get_name();
					$attributes                     = $variation->get_variation_attributes();
					$product_info['attributes']     = $attributes;
				}
			}

			// Prepare response data.
			$response_data = array(
				'success'       => true,
				'message'       => 'Product added to cart',
				'cart_item_key' => $cart_item_key,
				'product'       => $product_info,
				'cart'          => array(
					'count'    => $cart->get_cart_contents_count(),
					'subtotal' => (string) $cart->get_subtotal(),
					'total'    => (string) $cart->get_total( 'edit' ),
					'currency' => get_woocommerce_currency(),
					'cart_url' => wc_get_cart_url(),
				),
			);

			// For guest users, return session cookie info so browser can sync.
			if ( 0 === $current_user_id && WC()->session ) {
				$cookie_name = 'wp_woocommerce_session_' . COOKIEHASH;

				// Try to get cookie value from $_COOKIE first (if it was sent in request).
				$cookie_value = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';

				// If not in $_COOKIE, build it from session handler (same way WooCommerce does).
				if ( empty( $cookie_value ) ) {
					$session_handler = WC()->session;
					$customer_id     = $session_handler->get_customer_id();

					if ( $customer_id ) {
						// Use reflection to access protected properties (session expiration/expiring).
						$reflection = new \ReflectionClass( $session_handler );

						$expiration_prop = $reflection->getProperty( '_session_expiration' );
						$expiration_prop->setAccessible( true );
						$session_expiration = $expiration_prop->getValue( $session_handler );

						$expiring_prop = $reflection->getProperty( '_session_expiring' );
						$expiring_prop->setAccessible( true );
						$session_expiring = $expiring_prop->getValue( $session_handler );

						// Build cookie hash (same method as WooCommerce).
						$hash_method = $reflection->getMethod( 'hash' );
						$hash_method->setAccessible( true );
						$cookie_hash = $hash_method->invoke( $session_handler, $customer_id . '|' . (string) $session_expiration );

						// Build cookie value (format: customer_id|expiration|expiring|hash).
						$cookie_value = $customer_id . '|' . (string) $session_expiration . '|' . (string) $session_expiring . '|' . $cookie_hash;
					}
				}

				// Return cookie info so browser can sync.
				if ( ! empty( $cookie_value ) ) {
					$response_data['session_cookie'] = array(
						'name'   => $cookie_name,
						'value'  => $cookie_value,
						'path'   => COOKIEPATH,
						'domain' => COOKIE_DOMAIN,
					);
				}
			}

			return new \WP_REST_Response( $response_data, 200 );
		} catch ( \Exception $e ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Error adding to cart: ' . $e->getMessage(),
				),
				500
			);
		}
	}
}
