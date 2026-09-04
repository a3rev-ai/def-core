<?php
/**
 * Class DEF_Core_Partner_Attribution
 *
 * Partner attribution for the pitch site (S1–S3 of the partner-attribution-slug
 * runsheet, DEF repo): the `/p/<slug>` co-brand route, the 90-day first-touch
 * cookie, and the fail-open capture call into DEFHO's attribution bridge.
 *
 * - S1: `/p/<slug>` rewrites to the front page WITHOUT redirecting (AD-6: the slug
 *   must stay in the URL at conversion). The slug is validated against DEFHO's
 *   public validate-slug endpoint (transient-cached — S1 is REQUIRED to cache; all
 *   legitimate traffic to that endpoint comes from this server's IP). A valid slug
 *   server-sets the first-touch attribution cookie: HttpOnly, SameSite=Lax (AD-4),
 *   expiry from the endpoint's `window_days` (AD-2 single source — never a local
 *   constant), and NEVER overwritten once present (AD-1/AD-3). Unknown slugs serve
 *   the bare page — no cookie, no banner, never a 404.
 * - S2: a co-brand banner ("Widrow · Delivered by {partner}") on /p/ pageviews via
 *   wp_body_open. Page CTAs carrying the slug are content-side (AD-15); the
 *   Joe-prompt context is a DEF-side follow-up, deliberately not in this slice.
 * - S3: at escalation capture the attribution legs are the HttpOnly cookie
 *   (server-read — it rides the same-origin REST call) and the conversion page URL
 *   (JS-sent; covers the convert-on-first-pageview case). AD-13: the session leg is
 *   deliberately dropped; cross-device continuity is deal registration's job. The
 *   capture POST to DEFHO authenticates with this site's service secret (AD-11)
 *   and is FAIL-OPEN (AD-14): any error and the lead email still goes.
 *
 * @package def-core
 * @since 6.5.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_Partner_Attribution {

	/**
	 * Attribution cookie name (JSON: {"slug":..,"ts":..}).
	 */
	public const COOKIE_NAME = 'def_partner_attr';

	/**
	 * Query var carrying the /p/<slug> path segment.
	 */
	public const QUERY_VAR = 'def_partner_slug';

	/**
	 * Rewrite-rules schema version; bump to trigger a lazy flush.
	 */
	private const REWRITE_VERSION = '1';

	/**
	 * Transient prefix for validate-slug responses (10 min TTL).
	 */
	private const TRANSIENT_PREFIX = 'def_attr_slug_';

	/**
	 * Fallback attribution window if DEFHO's response omits window_days.
	 * Mirrors AD-2's configured default; the response value always wins.
	 */
	private const FALLBACK_WINDOW_DAYS = 90;

	/**
	 * The validated partner for the current /p/ pageview (banner render).
	 *
	 * @var array|null {slug, display_name}
	 */
	private static $current_partner = null;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'route_to_front_page' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'cancel_canonical_redirect' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_partner_visit' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'render_cobrand_banner' ) );
	}

	/**
	 * S1 — the /p/<slug> rewrite. Lazy-flushes once per REWRITE_VERSION so
	 * activation order can't strand the rule.
	 */
	public static function register_rewrite(): void {
		add_rewrite_rule( '^p/([A-Za-z0-9\-]{1,50})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		if ( get_option( 'def_core_attr_rewrite_version' ) !== self::REWRITE_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'def_core_attr_rewrite_version', self::REWRITE_VERSION );
		}
	}

	/**
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * S1/AD-12 — serve the front page at /p/<slug> with the URL intact.
	 *
	 * @param \WP_Query $query Main query.
	 */
	public static function route_to_front_page( $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$slug = $query->get( self::QUERY_VAR );
		if ( ! $slug ) {
			return;
		}
		$front_id = (int) get_option( 'page_on_front' );
		if ( $front_id > 0 ) {
			$query->set( 'page_id', $front_id );
			$query->is_home     = false;
			$query->is_page     = true;
			$query->is_singular = true;
		}
	}

	/**
	 * AD-12 — core's redirect_canonical 301s the front page's query-var render
	 * back to `/` on page-on-front sites (canonical.php's page_on_front branch),
	 * which would strip the slug BEFORE handle_partner_visit runs and kill the
	 * whole feature on the pitch site's actual config. Cancel it for /p/ views.
	 *
	 * @param string|false $redirect_url Proposed canonical redirect.
	 * @return string|false
	 */
	public static function cancel_canonical_redirect( $redirect_url ) {
		if ( get_query_var( self::QUERY_VAR ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * S1 — validate the slug, set the first-touch cookie, stage the banner.
	 */
	public static function handle_partner_visit(): void {
		$raw = get_query_var( self::QUERY_VAR );
		if ( ! $raw ) {
			return;
		}
		$slug = self::sanitize_slug( (string) $raw );
		if ( '' === $slug ) {
			return; // Serve the bare page (never 404 a partner link).
		}

		$info = self::validate_slug( $slug );
		if ( empty( $info['valid'] ) ) {
			return; // Unknown/inactive slug: bare page, no cookie, no banner.
		}

		self::$current_partner = array(
			'slug'         => $slug,
			'display_name' => (string) ( $info['display_name'] ?? '' ),
		);

		// First-touch (AD-1/AD-3): an existing VALID cookie is never overwritten.
		// A tampered/garbage cookie doesn't count — it must not block attribution
		// until its expiry.
		if ( isset( $_COOKIE[ self::COOKIE_NAME ] )
			&& '' !== self::slug_from_cookie_value( (string) wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) ) {
			return;
		}
		if ( headers_sent() ) {
			return;
		}
		$window_days = (int) ( $info['window_days'] ?? 0 );
		if ( $window_days < 1 ) {
			$window_days = self::FALLBACK_WINDOW_DAYS;
		}
		setcookie(
			self::COOKIE_NAME,
			self::build_cookie_value( $slug, time() ),
			array(
				'expires'  => time() + ( $window_days * DAY_IN_SECONDS ),
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true, // AD-4 — never a JS cookie.
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * S2/AD-15 — the co-brand banner on validated /p/ pageviews.
	 */
	public static function render_cobrand_banner(): void {
		if ( empty( self::$current_partner['display_name'] ) ) {
			return;
		}
		printf(
			'<div class="def-cobrand-banner" style="background:#0C302E;color:#F4F0E6;text-align:center;padding:8px 16px;font-size:14px;">%s <strong>%s</strong></div>',
			esc_html__( 'Widrow · Delivered by', 'digital-employees' ),
			esc_html( self::$current_partner['display_name'] )
		);
	}

	/**
	 * Joe partner-context (7.7.0) — the referring partner for THIS pageview, for the chat
	 * widget's page_context payload (DEF renders one line so Joe can acknowledge the
	 * referral). The /p/ visit knows it already; any later pageview resolves it from the
	 * first-touch cookie through the same transient-cached validate call.
	 *
	 * @return array|null {slug, name} or null when no partner is known.
	 */
	public static function current_partner_context(): ?array {
		if ( ! empty( self::$current_partner['slug'] ) ) {
			return self::partner_context_from( (string) self::$current_partner['slug'], (string) self::$current_partner['display_name'] );
		}
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}
		$slug = self::slug_from_cookie_value( (string) wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		if ( '' === $slug ) {
			return null;
		}
		$info = self::validate_slug( $slug );
		if ( empty( $info['valid'] ) ) {
			return null;
		}
		return self::partner_context_from( $slug, (string) ( $info['display_name'] ?? '' ) );
	}

	/**
	 * Shape {slug, name} for the widget. A partner without a display name is no
	 * context at all — Joe would have nothing to say.
	 */
	public static function partner_context_from( string $slug, string $display_name ): ?array {
		$name = trim( preg_replace( '/\s+/', ' ', $display_name ) );
		if ( '' === $slug || '' === $name ) {
			return null;
		}
		return array(
			'slug' => $slug,
			'name' => $name,
		);
	}

	/**
	 * S3 — capture attribution for an escalation lead. FAIL-OPEN (AD-14):
	 * returns null on any failure and the caller proceeds regardless.
	 *
	 * @param string $reply_to     Visitor email (its domain feeds the registration rung; S5c sends it whole).
	 * @param string $page_url     The conversion page URL as sent by the client.
	 * @param string $contact_name The visitor's name from the hand-off form (S5c, 7.6.0).
	 * @param string $message      The visitor's message from the hand-off form (S5c, 7.6.0).
	 * @param string $contact_phone The visitor's phone from the hand-off form (7.6.1).
	 * @param string $company_name  The visitor's business name from the hand-off form (7.6.1).
	 * @param string $website       The visitor's business website from the hand-off form (7.6.1).
	 * @return array|null {source, partner_name} or null.
	 */
	public static function capture_for_escalation( string $reply_to, string $page_url, string $contact_name = '', string $message = '', string $contact_phone = '', string $company_name = '', string $website = '' ): ?array {
		$secret = class_exists( 'DEF_Core_Encryption' )
			? (string) DEF_Core_Encryption::get_secret( 'def_service_auth_secret' )
			: '';
		if ( '' === $secret ) {
			return null; // Site not DEFHO-connected — nothing to attribute against.
		}

		$slug = '';
		if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$slug = self::slug_from_cookie_value( (string) wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		}
		if ( '' === $slug && '' !== $page_url ) {
			$slug = self::extract_slug_from_page_url( $page_url ); // AD-6 leg.
		}

		$lead_ref = 'esc-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 8, false ) );
		$payload  = self::build_capture_payload( $lead_ref, $slug, $reply_to, $page_url, $contact_name, $message, $contact_phone, $company_name, $website );

		$response = wp_remote_post(
			DEF_Core_OAuth::get_defho_api_url() . '/api/bridge/attribution/capture',
			array(
				'timeout' => 3,
				'headers' => array(
					'Content-Type'         => 'application/json',
					'X-DEF-Service-Secret' => $secret,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// AD-14 — the lead email must still go, and the MISS is logged for
			// reconcile against DEFHO's `[ATTRIBUTION] captured` lines.
			error_log(
				'[DEF Core] Partner attribution capture failed (fail-open): '
				. ( is_wp_error( $response ) ? $response->get_error_code() : 'HTTP ' . wp_remote_retrieve_response_code( $response ) )
			);
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['source'] ) ) {
			return null;
		}
		return array(
			'source'       => (string) $data['source'],
			'partner_name' => isset( $data['partner_name'] ) ? (string) $data['partner_name'] : '',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Pure helpers (covered by tests/test-partner-attribution.php)        */
	/* ------------------------------------------------------------------ */

	/**
	 * The DEFHO capture payload (S3 + S5c). Contact fields are bounded to the
	 * capture schema's limits because DEFHO REFUSES over-length with a 422 and
	 * the call is fail-open — an unbounded field would lose the WHOLE
	 * attribution, not a suffix. Resolution input (slug, domain) is never cut.
	 *
	 * @return array<string, string>
	 */
	public static function build_capture_payload( string $lead_ref, string $slug, string $reply_to, string $page_url, string $contact_name = '', string $message = '', string $contact_phone = '', string $company_name = '', string $website = '' ): array {
		$payload = array( 'lead_ref' => $lead_ref );
		if ( '' !== $slug ) {
			$payload['slug'] = $slug;
		}
		$domain = self::email_domain_from( $reply_to );
		if ( '' !== $domain ) {
			$payload['email_domain']  = $domain;
			$payload['contact_email'] = strtolower( trim( $reply_to ) );
		}
		if ( '' !== $page_url ) {
			$payload['page_url'] = substr( $page_url, 0, 500 );
		}
		$contact_name = trim( (string) preg_replace( '/\s+/', ' ', $contact_name ) );
		if ( '' !== $contact_name ) {
			$payload['contact_name'] = mb_substr( $contact_name, 0, 200 );
		}
		$message = trim( $message );
		if ( '' !== $message ) {
			$payload['message'] = mb_substr( $message, 0, 1000 );
		}
		$contact_phone = trim( $contact_phone );
		if ( '' !== $contact_phone ) {
			$payload['contact_phone'] = mb_substr( $contact_phone, 0, 50 );
		}
		$company_name = trim( (string) preg_replace( '/\s+/', ' ', $company_name ) );
		if ( '' !== $company_name ) {
			$payload['company_name'] = mb_substr( $company_name, 0, 200 );
		}
		$website = trim( $website );
		if ( '' !== $website ) {
			$payload['website'] = mb_substr( $website, 0, 255 );
		}
		return $payload;
	}

	/**
	 * Slugs are lowercase [a-z0-9-], 1–50 chars — DEFHO's Partner.slug shape.
	 *
	 * @param string $raw Raw path segment.
	 * @return string Sanitized slug or ''.
	 */
	public static function sanitize_slug( string $raw ): string {
		$slug = strtolower( trim( $raw ) );
		return preg_match( '/^[a-z0-9\-]{1,50}$/', $slug ) ? $slug : '';
	}

	/**
	 * @param string $slug Validated slug.
	 * @param int    $ts   First-touch unix time.
	 * @return string JSON cookie value.
	 */
	public static function build_cookie_value( string $slug, int $ts ): string {
		return (string) wp_json_encode( array( 'slug' => $slug, 'ts' => $ts ) );
	}

	/**
	 * @param string $value Cookie value.
	 * @return string Slug or ''.
	 */
	public static function slug_from_cookie_value( string $value ): string {
		$data = json_decode( $value, true );
		if ( ! is_array( $data ) || empty( $data['slug'] ) ) {
			return '';
		}
		return self::sanitize_slug( (string) $data['slug'] );
	}

	/**
	 * The AD-6 leg: a conversion on the /p/ pageview itself carries the slug
	 * in the URL even with no cookie.
	 *
	 * @param string $url Page URL.
	 * @return string Slug or ''.
	 */
	public static function extract_slug_from_page_url( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( ! preg_match( '#^/p/([^/]+)/?$#', $path, $m ) ) {
			return '';
		}
		return self::sanitize_slug( $m[1] );
	}

	/**
	 * @param string $email Visitor email.
	 * @return string Lowercased domain or ''.
	 */
	public static function email_domain_from( string $email ): string {
		if ( ! is_email( $email ) ) {
			return '';
		}
		$at = strrpos( $email, '@' );
		return false === $at ? '' : strtolower( substr( $email, $at + 1 ) );
	}

	/**
	 * The email-body line the escalation recipient sees.
	 *
	 * @param array $attribution {source, partner_name}.
	 * @return string
	 */
	public static function build_attributed_line( array $attribution ): string {
		$source = (string) ( $attribution['source'] ?? '' );
		// Collapse all whitespace: a multi-line partner name must never inject
		// extra plain-text lines into the escalation email.
		$name = trim( preg_replace( '/\s+/', ' ', (string) ( $attribution['partner_name'] ?? '' ) ) );
		if ( 'house' === $source || '' === $name ) {
			return 'Attributed: house lead';
		}
		return sprintf( 'Attributed: %s (via %s)', $name, 'registration' === $source ? 'registered deal' : 'partner link' );
	}

	/**
	 * Validate a slug against DEFHO, transient-cached (10 min).
	 *
	 * @param string $slug Sanitized slug.
	 * @return array {valid, display_name?, window_days?}
	 */
	private static function validate_slug( string $slug ): array {
		$key    = self::TRANSIENT_PREFIX . md5( $slug );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get(
			DEF_Core_OAuth::get_defho_api_url() . '/api/public/partners/validate-slug/' . rawurlencode( $slug ),
			array( 'timeout' => 3 )
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Don't cache transport failures — the next pageview retries.
			return array( 'valid' => false );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$info = array(
			'valid'        => is_array( $data ) && ! empty( $data['valid'] ),
			'display_name' => is_array( $data ) ? (string) ( $data['display_name'] ?? '' ) : '',
			'window_days'  => is_array( $data ) ? (int) ( $data['window_days'] ?? 0 ) : 0,
		);
		set_transient( $key, $info, 10 * MINUTE_IN_SECONDS );
		return $info;
	}
}
