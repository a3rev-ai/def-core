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

		// Upstream status + Retry-After, captured from the response headers so the body
		// writer knows whether it is holding SSE or an error document. HTTP delivers
		// headers before the body, so this is always populated first.
		$state = array(
			'status'      => 0,
			'retry_after' => 0,
			'error_sent'  => false,
		);

		$ch = curl_init( $url );
		curl_setopt_array( $ch, array(
			CURLOPT_POST            => true,
			CURLOPT_HTTPHEADER      => $headers,
			CURLOPT_POSTFIELDS      => $body,
			// No total timeout — stream as long as tokens are flowing.
			// Kill only if the connection stalls (no data for 30 seconds).
			// This prevents legitimate long responses (e.g. analysing a 34KB
			// spec document) from being cut off at a fixed timeout, while
			// still catching genuinely stalled connections.
			CURLOPT_TIMEOUT         => 0,
			CURLOPT_LOW_SPEED_LIMIT => 1,    // At least 1 byte/sec
			CURLOPT_LOW_SPEED_TIME  => 30,   // Kill after 30s of no data
			CURLOPT_RETURNTRANSFER  => false,
			CURLOPT_HEADERFUNCTION  => function ( $ch, $header ) use ( &$state ) {
				self::note_upstream_header( $header, $state );
				return strlen( $header );
			},
			CURLOPT_WRITEFUNCTION   => function ( $ch, $data ) use ( &$state ) {
				$out = self::stream_chunk( $data, $state );
				if ( '' !== $out ) {
					// Not escaped on purpose: this is an SSE frame consumed by JSON.parse,
					// not HTML. Its only interpolated values are wp_json_encode'd ints and
					// translator-supplied copy; esc_* here would corrupt the JSON.
					echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					if ( ob_get_level() ) {
						ob_flush();
					}
					flush();
				}
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

		// A non-200 with an EMPTY body never reaches the write callback, so the visitor
		// would still see nothing. Belt for that case; stream_chunk() covers the rest.
		if ( $state['status'] >= 400 && ! $state['error_sent'] ) {
			$state['error_sent'] = true;
			// See the write callback: an SSE frame, wp_json_encode'd, never HTML.
			echo self::sse_error_payload( $state['status'], $state['retry_after'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			flush();
		}
	}

	/**
	 * Record the upstream status line and Retry-After from one response header.
	 *
	 * @param string $header One raw header line as curl delivers it.
	 * @param array  $state  Mutated: 'status' and 'retry_after'.
	 * @return void
	 */
	private static function note_upstream_header( string $header, array &$state ): void {
		if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $header, $m ) ) {
			// Last status line wins, so a 100-continue or a redirect hop cannot mask the
			// real one.
			$state['status'] = (int) $m[1];
		} elseif ( preg_match( '#^Retry-After:\s*(\d{1,5})\s*$#i', $header, $m ) ) {
			// Seconds form only. The HTTP-date form is legal but DEF never sends it, and a
			// date we failed to parse would be worse than saying nothing.
			$state['retry_after'] = (int) $m[1];
		}
	}

	/**
	 * What to write to the client for one chunk of upstream body.
	 *
	 * On a 2xx this returns the chunk **verbatim** — the streaming path is unchanged.
	 *
	 * On a non-200 it returns an SSE error event ONCE and swallows the body. That is the
	 * whole point of this function: DEF answers a refused request with a JSON document, and
	 * a JSON document echoed into an SSE stream parses as nothing, so a rate-limited
	 * visitor saw NO message at all — the ceiling worked and looked like a dead widget.
	 *
	 * @param string $data  Chunk of upstream body.
	 * @param array  $state Mutated: 'error_sent'.
	 * @return string Bytes to send to the client ('' to send nothing).
	 */
	private static function stream_chunk( string $data, array &$state ): string {
		if ( $state['status'] < 400 ) {
			return $data;
		}
		if ( $state['error_sent'] ) {
			return '';
		}
		$state['error_sent'] = true;
		return self::sse_error_payload( $state['status'], $state['retry_after'] );
	}

	/**
	 * One SSE `error` event, in the shape both widgets already render.
	 *
	 * Customer Chat renders `evt.message` (def-core-customer-chat.js, case 'error') and
	 * Staff AI renders it via showError(), each falling back to its own generic copy when
	 * `message` is absent — which is why the message is built HERE rather than left to the
	 * client: a 429 rendered as "Unable to connect" would be actively misleading. Note the
	 * widgets carry their own `rateLimited` string for the non-streaming paths; this is the
	 * streaming twin of it.
	 *
	 * `status` and `retry_after` ride along so a widget can act on them (backing the
	 * composer off, for instance) without re-deriving anything.
	 *
	 * @param int $status      Upstream HTTP status.
	 * @param int $retry_after Seconds from Retry-After, 0 when absent.
	 * @return string A complete SSE frame.
	 */
	private static function sse_error_payload( int $status, int $retry_after ): string {
		if ( 429 === $status ) {
			$message = $retry_after > 0
				? sprintf(
					/* translators: %d: number of seconds to wait. */
					_n(
						'Please wait %d second before sending another message.',
						'Please wait %d seconds before sending another message.',
						$retry_after,
						'digital-employees'
					),
					$retry_after
				)
				: __( 'Please wait a moment before sending another message.', 'digital-employees' );
		} else {
			$message = __( 'The assistant is unavailable right now. Please try again.', 'digital-employees' );
		}

		$payload = array(
			'type'    => 'error',
			'message' => $message,
			'status'  => $status,
		);
		if ( $retry_after > 0 ) {
			$payload['retry_after'] = $retry_after;
		}

		return 'data: ' . wp_json_encode( $payload ) . "\n\n";
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
	 * Effective Customer-Chat employee name from DEF (the per-tenant override set
	 * in the Tenant Portal, or the a3rev default) for the chat header + greeting.
	 *
	 * Server-side GET to DEF's /api/customer/identity over the BFF (API-key only —
	 * same customer privacy boundary as the chat proxy). Cached in a transient
	 * because the name only changes when the tenant renames + republishes. Fails
	 * SAFE to '' (a short negative cache avoids hammering DEF during an outage), so
	 * the caller falls back to the branding display name and the page never blocks.
	 * The launcher/button label is a separate admin setting, intentionally NOT here.
	 *
	 * @return string Effective employee name, or '' to use the branding fallback.
	 */
	public static function get_customer_assistant_name(): string {
		$cached = get_transient( 'def_core_customer_assistant_name' );
		if ( false !== $cached ) {
			// '' is the negative-cache sentinel (recent fetch failed/unset) — use
			// the fallback without re-hitting DEF until it expires.
			return is_string( $cached ) ? $cached : '';
		}

		$api_key = \DEF_Core_Encryption::get_secret( 'def_core_api_key' );
		if ( empty( $api_key ) ) {
			return ''; // Not connected to DEF — branding fallback, don't cache.
		}

		$ch = curl_init( \DEF_Core::get_def_api_url_internal() . '/api/customer/identity' );
		curl_setopt_array( $ch, array(
			CURLOPT_HTTPGET        => true,
			// No visitor IP: this fires on page render to warm a per-SITE config value,
			// for visitors who may never open the chat, and nothing rate-limits it.
			CURLOPT_HTTPHEADER     => self::build_proxy_headers( false, false ),
			CURLOPT_TIMEOUT        => 4, // short — must not block page render on a DEF hiccup
			CURLOPT_RETURNTRANSFER => true,
		) );
		$response  = curl_exec( $ch );
		$http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		$name = '';
		if ( false !== $response && 200 === $http_code ) {
			$data = json_decode( $response, true );
			if ( is_array( $data ) && isset( $data['display_name'] ) && is_string( $data['display_name'] ) ) {
				$name = trim( $data['display_name'] );
			}
		}

		if ( '' === $name ) {
			set_transient( 'def_core_customer_assistant_name', '', MINUTE_IN_SECONDS );
			return '';
		}
		set_transient( 'def_core_customer_assistant_name', $name, 15 * MINUTE_IN_SECONDS );
		return $name;
	}

	/**
	 * The visitor's own IP, for DEF's per-visitor rate limits.
	 *
	 * DEF sees this WP server's egress IP on every proxied request, so without this
	 * header its "per-IP" limits bound a whole SITE rather than one visitor. Customer
	 * Chat is anonymous, so the IP is the only visitor key that exists.
	 *
	 * REMOTE_ADDR only — the TCP peer as PHP sees it. A forwarding header is NOT read
	 * here even when one is present: on a site that is not actually behind a proxy,
	 * anyone can send X-Forwarded-For / CF-Connecting-IP, and def-core would then hand
	 * DEF an attacker-chosen "visitor" per request — a limit keyed on that is no limit.
	 *
	 * Sites behind a CDN or reverse proxy see the edge/proxy address in REMOTE_ADDR. The
	 * fix is at the web-server layer — mod_remoteip, nginx set_real_ip_from, or a
	 * REMOTE_ADDR-rewriting plugin — so the real visitor lands in REMOTE_ADDR before this
	 * runs, which is the same guidance DEF_Core_Escalation::get_client_ip() already gives
	 * for its own rate limit. The filter below exists for setups that cannot do that.
	 *
	 * Only PUBLIC addresses are forwarded. A private or reserved REMOTE_ADDR (10.0.0.5,
	 * 172.17.0.1, ::1) means an un-rewritten proxy sits in front, so the address is that
	 * proxy — one constant for every visitor. Sending it would give DEF something that
	 * LOOKS like a visitor key but is really the site again, hiding the fact that the
	 * site is not per-visitor keyed; DEF counts the omission instead
	 * (chat_limiter_site_keyed_total), so the gap is visible centrally.
	 *
	 * Anything not a public IP yields '' and the caller omits the header; DEF then falls
	 * back to the connection peer, which is today's behaviour. That covers WP-CLI (no
	 * REMOTE_ADDR at all) and WP-Cron (a loopback HTTP request, so REMOTE_ADDR is present
	 * but is 127.0.0.1 — caught as non-public, not as absent).
	 *
	 * @return string A public IP, or '' when there is none to send.
	 */
	private static function get_visitor_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		/**
		 * Filters the visitor IP def-core forwards to DEF.
		 *
		 * Must return ONE public IP — not an X-Forwarded-For chain ("1.2.3.4, 5.6.7.8"
		 * fails validation and the header is silently omitted).
		 *
		 * Read a client-supplied header here ONLY after checking that the request
		 * actually came through your edge (i.e. REMOTE_ADDR is one of its ranges). A
		 * CDN-fronted origin usually stays directly reachable, and an unconditional
		 * `$_SERVER['HTTP_CF_CONNECTING_IP']` lets anyone who finds the origin choose
		 * their own rate-limit bucket on every request — no limit at all. Rewriting
		 * REMOTE_ADDR at the server layer avoids this question entirely.
		 *
		 * @param string $ip The IP from REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'def_core_visitor_ip', $ip );

		$public = filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);

		return $public ? $ip : '';
	}

	/**
	 * Build trusted BFF proxy headers for DEF backend requests.
	 *
	 * Always sends (when logged in): X-DEF-User (id) + X-DEF-User-Display-Name.
	 * The display name crosses on EVERY channel — including Customer Chat — so a
	 * logged-in visitor is greeted by name from the first turn (their own name,
	 * their own session). Plus Content-Type + X-DEF-API-Key.
	 *
	 * When $include_capabilities is true (staff_ai / setup_assistant), also sends:
	 *   - X-DEF-User-Capabilities (comma-separated DEF capabilities)
	 *   - X-DEF-User-Email (URL-encoded)
	 *   - X-DEF-User-Roles (comma-separated WP roles)
	 *   - X-DEF-Site-Name (URL-encoded)
	 *
	 * Customer Chat calls this with $include_capabilities = false — email / roles /
	 * capabilities are intentionally NOT sent for it (privacy boundary); only the
	 * display name above crosses.
	 *
	 * Sends X-DEF-Client-IP (the visitor, see get_visitor_ip) so DEF can rate-limit per
	 * VISITOR. It reaches DEF over the API-key channel, which is what makes the IP
	 * trustworthy at the other end. $include_visitor_ip is false for the one caller that
	 * runs on a plain page view rather than on something the visitor did — an IP is
	 * personal data, and "nothing is sent unless a visitor uses the chat" is a published
	 * claim (readme.txt, External Services) that a config GET must not quietly break.
	 *
	 * @param bool $include_capabilities Whether to include capabilities + email/roles.
	 * @param bool $include_visitor_ip   Whether to send the visitor's IP.
	 * @return array HTTP header strings (indexed, not associative).
	 */
	private static function build_proxy_headers( $include_capabilities = false, $include_visitor_ip = true ) {
		$headers = array(
			'Content-Type: application/json',
			'X-DEF-API-Key: ' . \DEF_Core_Encryption::get_secret( 'def_core_api_key' ),
		);

		$visitor_ip = $include_visitor_ip ? self::get_visitor_ip() : '';
		if ( '' !== $visitor_ip ) {
			$headers[] = 'X-DEF-Client-IP: ' . $visitor_ip;
		}

		if ( is_user_logged_in() ) {
			$headers[] = 'X-DEF-User: ' . get_current_user_id();
			$user      = wp_get_current_user();

			// Display name is sent on EVERY channel (incl. Customer Chat) so a
			// logged-in visitor is greeted by name from the first turn — it's the
			// user's OWN name, used only in their own session. DEF renders it into
			// the "## Current authenticated user" prompt section. URL-encoded so
			// Unicode survives header transport (DEF decodes via urllib unquote).
			// Email / roles / capabilities remain staff-only below (privacy boundary).
			if ( ! empty( $user->display_name ) ) {
				$headers[] = 'X-DEF-User-Display-Name: ' . rawurlencode( $user->display_name );
			}

			if ( $include_capabilities ) {
				$caps = self::get_user_def_capabilities( $user );
				if ( ! empty( $caps ) ) {
					$headers[] = 'X-DEF-User-Capabilities: ' . implode( ',', $caps );
				}

				// Email / roles — staff_ai / setup_assistant only. Customer Chat does
				// NOT receive these (only the display name above crosses for it).
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

		// Forward WC Cart-Token (browser-supplied) as DEF-namespaced header.
		// JWT-shape + length-capped to block header-splitting payloads.
		if ( isset( $_SERVER['HTTP_CART_TOKEN'] ) ) {
			$cart_token = trim( wp_unslash( $_SERVER['HTTP_CART_TOKEN'] ) );
			if (
				'' !== $cart_token
				&& strlen( $cart_token ) <= 4096
				&& preg_match( '/^[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $cart_token )
			) {
				$headers[] = 'X-DEF-WC-Cart-Token: ' . $cart_token;
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
	 * BFF proxy for the Setup Assistant active-thread resume.
	 * Returns the admin's active thread + its messages so the drawer can
	 * rehydrate the conversation on mount (persists across wp-admin page
	 * navigations). Auth: logged-in user with def_admin_access.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_proxy_setup_assistant_active_thread( $request ) {
		$headers = self::build_proxy_headers( true );
		$def_url = \DEF_Core::get_def_api_url_internal() . '/api/setup_assistant/active-thread';
		return self::json_proxy_get( $def_url, $headers );
	}

	/**
	 * BFF proxy for clearing the Setup Assistant active thread ("new chat").
	 * Auth: logged-in user with def_admin_access.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_proxy_setup_assistant_clear( $request ) {
		$headers = self::build_proxy_headers( true );
		$def_url = \DEF_Core::get_def_api_url_internal() . '/api/setup_assistant/clear';
		return self::json_proxy( $def_url, $headers, $request->get_body() );
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
	 * The fixed trio plus the user's custom-role caps (`def_role_<slug>`, custom-roles R4) —
	 * catalog-validated so a cap for a role DEFHO has deleted stops being asserted once the
	 * cached catalog refreshes. This is the single funnel every assertion path reads (proxy
	 * headers, context token, admin bridge).
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
		foreach ( self::get_role_caps() as $cap ) {
			if ( $user->has_cap( $cap ) ) {
				$caps[] = $cap;
			}
		}
		return $caps;
	}

	/**
	 * The tenant's custom-role capability names (`def_role_<slug>`) from the cached catalog.
	 *
	 * @return array
	 */
	public static function get_role_caps(): array {
		$caps = array();
		foreach ( self::get_roles_catalog() as $role ) {
			$caps[] = 'def_role_' . $role['slug'];
		}
		return $caps;
	}

	/**
	 * The tenant's role catalog `[{slug, name}]` (custom-roles R4).
	 *
	 * Cached in the `def_core_roles_catalog` option; `$refresh` re-fetches from DEF's bridge
	 * (`GET /api/staff-ai/roles-catalog`, admin-gated — call with refresh only from a def_admin
	 * context, e.g. the User Access page load). Fetch failure keeps the cached copy (assignment
	 * must not break on a DEF blip). Entries are sanitized on store: slugs to the locked wire
	 * format, names to plain text.
	 *
	 * @param bool $refresh Re-fetch from DEF before returning.
	 * @return array
	 */
	public static function get_roles_catalog( bool $refresh = false ): array {
		$cached = self::sanitize_roles_catalog( get_option( 'def_core_roles_catalog', array() ) );
		if ( ! $refresh ) {
			return $cached;
		}

		$api_url = get_option( 'def_core_staff_ai_api_url', '' );
		$api_key = \DEF_Core_Encryption::get_secret( 'def_core_api_key' );
		$user    = wp_get_current_user();
		if ( empty( $api_url ) || empty( $api_key ) || ! $user || 0 === $user->ID ) {
			return $cached;
		}

		$response = wp_remote_get( rtrim( $api_url, '/' ) . '/api/staff-ai/roles-catalog', array(
			'timeout' => 5,
			'headers' => array(
				'X-DEF-API-Key'           => $api_key,
				'X-DEF-User'              => (string) $user->ID,
				'X-DEF-User-Capabilities' => implode( ',', self::get_user_def_capabilities( $user ) ),
				'Accept'                  => 'application/json',
			),
		) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $cached;
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$roles = isset( $body['roles'] ) && is_array( $body['roles'] ) ? $body['roles'] : null;
		if ( null === $roles ) {
			return $cached;
		}

		$clean = self::sanitize_roles_catalog( $roles );
		update_option( 'def_core_roles_catalog', $clean, false );
		return $clean;
	}

	/**
	 * Sanitize a role-catalog value — applied to BOTH the fetched payload and the cached option
	 * (self-sanitizing store: a poisoned option can't smuggle slugs into caps headers or markup).
	 * Drops: malformed entries, slugs outside the locked wire format, and RESERVED slugs — the
	 * seeded staff/management rows ride the DEFHO catalog but must NEVER become def_role_* columns
	 * (they'd duplicate the fixed columns and bypass the Staff/Mgmt exclusivity guard).
	 *
	 * @param mixed $roles Raw catalog value.
	 * @return array
	 */
	private static function sanitize_roles_catalog( $roles ): array {
		if ( ! is_array( $roles ) ) {
			return array();
		}
		$reserved = array( 'staff', 'management', 'public', 'admin', 'def_admin' );
		$clean    = array();
		foreach ( $roles as $role ) {
			if ( ! is_array( $role ) || empty( $role['slug'] ) || ! is_string( $role['slug'] ) ) {
				continue;
			}
			if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,39}$/', $role['slug'] ) ) {
				continue;
			}
			if ( in_array( $role['slug'], $reserved, true ) || 0 === strpos( $role['slug'], 'def_' ) ) {
				continue;
			}
			$name    = isset( $role['name'] ) && is_string( $role['name'] ) ? sanitize_text_field( $role['name'] ) : $role['slug'];
			$clean[] = array( 'slug' => $role['slug'], 'name' => $name );
		}
		return $clean;
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
				// Get all published products, EXCLUDING any flagged out of DEF
				// ingestion (`_def_exclude_from_ingestion`). This is the live path
				// the chatbot uses to resolve a name → product ID for add-to-cart,
				// so an excluded product must not be resolvable here either — not
				// just hidden from the Azure search index. Mirrors the
				// search-export filter; the in-loop guard below is the guarantee
				// in case wc_get_products() doesn't honour meta_query.
				$exclude_meta = '_def_exclude_from_ingestion'; // DEF_Core_Knowledge_Exclusion::META_KEY.
				$args         = array(
					'status'     => 'publish',
					'limit'      => -1,
					'orderby'    => 'title',
					'order'      => 'ASC',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'OR',
						array( 'key' => $exclude_meta, 'compare' => 'NOT EXISTS' ),
						array( 'key' => $exclude_meta, 'value' => '1', 'compare' => '!=' ),
					),
				);

				$products_data = array();
				$products      = wc_get_products( $args );

				foreach ( $products as $product ) {
					// Defense in depth: never expose an excluded product to the
					// tool/LLM layer, regardless of meta_query support.
					if ( get_post_meta( $product->get_id(), $exclude_meta, true ) ) {
						continue;
					}

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
	 * Server-side fallback for DEF's `get_cart` tool — hydrates the
	 * logged-in user's persistent_cart user meta into WC()->cart.
	 *
	 * @param \WP_REST_Request $req
	 * @return \WP_REST_Response
	 * @since 3.1.6
	 */
	public static function wc_get_cart( \WP_REST_Request $req ): \WP_REST_Response {
		$current_user_id = get_current_user_id();
		if ( $current_user_id <= 0 ) {
			// Defensive: permission_check should have rejected anonymous callers.
			return new \WP_REST_Response(
				array(
					'items'         => array(),
					'cart_count'    => 0,
					'cart_total'    => null,
					'cart_subtotal' => null,
					'currency'      => null,
				),
				200
			);
		}

		// WC's session bootstrap runs on `init`, but REST callers skip that.
		if ( ! isset( WC()->session ) || is_null( WC()->session ) ) {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
		}
		WC()->session->set( 'customer_id', $current_user_id );

		if ( ! did_action( 'woocommerce_load_cart_from_session' ) ) {
			wc_load_cart();
		}

		// Same persistent_cart row /cart/ reads on revisit.
		$saved_cart = get_user_meta(
			$current_user_id,
			'_woocommerce_persistent_cart_' . get_current_blog_id(),
			true
		);
		if ( ! empty( $saved_cart['cart'] ) && is_array( $saved_cart['cart'] ) ) {
			WC()->session->set( 'cart', $saved_cart['cart'] );
			WC()->cart->get_cart_from_session();
		}

		WC()->cart->calculate_totals();

		$decimals = wc_get_price_decimals();
		$items    = array();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( ! $product ) {
				continue;
			}
			$variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
			$product_id   = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			$items[]      = array(
				'product_id'   => $variation_id > 0 ? $variation_id : $product_id,
				'product_name' => $product->get_name(),
				'quantity'     => isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0,
				'line_total'   => wc_format_decimal( isset( $cart_item['line_total'] ) ? $cart_item['line_total'] : 0, $decimals ),
			);
		}

		return new \WP_REST_Response(
			array(
				'items'         => $items,
				'cart_count'    => WC()->cart->get_cart_contents_count(),
				'cart_total'    => wc_format_decimal( WC()->cart->get_total( 'edit' ), $decimals ),
				'cart_subtotal' => wc_format_decimal( WC()->cart->get_subtotal(), $decimals ),
				'currency'      => get_woocommerce_currency(),
			),
			200
		);
	}

}
