<?php
/**
 * The Content AI page opens already showing the Creator's name (8.2.8).
 *
 * 8.2.6 gave the page her name, but the page draws before any list response
 * lands: PHP rendered the platform default and the JS repainted it a moment
 * later, so a tenant who had renamed her watched "Carol - Creator" turn into
 * her own name on every single visit. The BFF now remembers the name DEF last
 * sent, and the page renders THAT at first paint.
 *
 * This RUNS the shipped renderer rather than reading it: render_page() under
 * stubs, its output captured, and DefDraftCards.creator read off the
 * wp_localize_script call the page actually made — the JS repaint is a no-op
 * only if PHP hands the JS the same name it rendered into the markup.
 *
 * The stored value is read back onto an admin page, so it goes back through the
 * BFF's own sanitiser: a name is printable, bounded, and never nothing.
 *
 * Where the option is REMOVED is read off the two removal paths themselves —
 * uninstall.php's option list, and ajax_disconnect()'s own source through
 * Reflection, the name belonging to the tenant the site was connected to.
 *
 * The BFF half — both list handlers storing it, and neither writing when the
 * name has not changed — is test-content-targets.php [12].
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

global $_wp_test_current_user, $_wp_test_user_caps;
$_wp_test_user_caps = array( 'def_staff_access' );

// What the page asked WordPress for.
$GLOBALS['_def_localized'] = array();

// ── WP stubs the page reaches for ───────────────────────────────────────
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID = 0;
		public function __construct( int $id = 0 ) { $this->ID = $id; }
		public function exists(): bool { return $this->ID > 0; }
		public function has_cap( string $cap ): bool {
			global $_wp_test_user_caps;
			return in_array( $cap, $_wp_test_user_caps, true );
		}
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): WP_User {
		global $_wp_test_current_user;
		return $_wp_test_current_user ?? new WP_User( 0 );
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, ...$args ): bool {
		global $_wp_test_user_caps;
		return in_array( $cap, $_wp_test_user_caps, true );
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '' ) { throw new RuntimeException( 'wp_die: ' . (string) $message ); }
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, ...$rest ): void {}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, ...$rest ): void {}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $name, $data ): bool {
		$GLOBALS['_def_localized'][ $name ] = $data;
		return true;
	}
}
if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $type ) { return null; }
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string { return 'https://site.test/wp-json/' . ltrim( $path, '/' ); }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action = '' ): string { return 'nonce'; }
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string { return $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ): string { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $t ): string { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $t, string $d = '' ): string { return esc_html( $t ); }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $t, string $d = '' ): void { echo esc_html( $t ); }
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( string $t, string $d = '' ): void { echo esc_attr( $t ); }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool { return true; }
}
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( string $var, $default = '' ) { return $default; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string { return 'Test Site'; }
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-staff-ai.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-content-drafts-page.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-oauth.php';

$_wp_test_current_user = new WP_User( 5 );

$pass = 0;
$fail = 0;

function check( bool $cond, string $label, string $detail = '' ): void {
	global $pass, $fail;
	if ( $cond ) { $pass++; } else { $fail++; echo "  FAIL: $label" . ( $detail ? " ($detail)" : '' ) . "\n"; }
}

/** Render the Content AI page with $stored remembered (null = never stored). */
function render_page_with( $stored ): string {
	global $_wp_test_options;
	unset( $_wp_test_options['def_core_creator_name'] );
	if ( null !== $stored ) {
		$_wp_test_options['def_core_creator_name'] = $stored;
	}
	$GLOBALS['_def_localized'] = array();
	ob_start();
	try {
		DEF_Core_Content_Drafts_Page::render_page();
	} catch ( Throwable $e ) {
		ob_end_clean();
		return 'THREW: ' . $e->getMessage();
	}
	return (string) ob_get_clean();
}

/** The h1's title span, as rendered. */
function rendered_title( string $html ): string {
	return preg_match( '/<span id="def-creator-title">(.*?)<\/span>/s', $html, $m ) ? $m[1] : '';
}

/** The three tab descriptions, as rendered. */
function rendered_copy( string $html ): array {
	preg_match_all( '/id="def-creator-copy-([a-z]+)">\s*(.*?)\s*<\/p>/s', $html, $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $hit ) { $out[ $hit[1] ] = $hit[2]; }
	return $out;
}

echo "=== The Content AI page opens showing the remembered name ===\n";

// ── [1] The tenant renamed her: the page paints HER name, once ──────────
echo "\n[1] a remembered name is what the page renders\n";
$html = render_page_with( 'Caz' );
$copy = rendered_copy( $html );

check( 0 !== strpos( $html, 'THREW:' ), 'the page renders', $html );
check( 'Caz - Creator' === rendered_title( $html ), 'the h1 says her name at first paint', rendered_title( $html ) );
check( 3 === count( $copy ), 'all three tab descriptions render', implode( ',', array_keys( $copy ) ) );
check(
	count( array_filter( $copy, function ( $p ) { return false !== strpos( $p, 'Caz' ) && false === strpos( $p, 'Carol' ); } ) ) === 3,
	'every tab description names her, and none still says Carol',
	json_encode( $copy )
);
// The Clusters sentence says her name twice (%1$s).
check( 2 === substr_count( $copy['clusters'] ?? '', 'Caz' ), 'the Clusters sentence names her in both places' );

// The point of the fix: the JS is handed the name PHP rendered, so the repaint
// off the first list response changes nothing.
check( 'Caz' === ( $GLOBALS['_def_localized']['DefDraftCards']['creator']['name'] ?? null ),
	'the same name is localized for the JS, so the repaint is a no-op',
	json_encode( $GLOBALS['_def_localized']['DefDraftCards']['creator']['name'] ?? null ) );

// ── [2] Nothing remembered yet ──────────────────────────────────────────
echo "\n[2] before DEF has ever answered, the platform default stands\n";
$html = render_page_with( null );
check( 'Carol - Creator' === rendered_title( $html ), 'a site that has never listed content renders the default', rendered_title( $html ) );
check( 'Carol' === ( $GLOBALS['_def_localized']['DefDraftCards']['creator']['name'] ?? null ),
	'and localizes the default' );

// ── [3] The stored value is read back through the BFF's sanitiser ───────
echo "\n[3] a stored value is re-validated on the way out\n";
check( 'Ca z - Creator' === rendered_title( render_page_with( "Ca\nz" ) ),
	'a newline cannot break the title out of its line' );
check( 'Carol - Creator' === rendered_title( render_page_with( '   ' ) ),
	'a blank stored name falls back to the default' );
check( 'Carol - Creator' === rendered_title( render_page_with( array( 'Caz' ) ) ),
	'a stored value that is not a string falls back to the default' );
check( str_repeat( 'a', 100 ) . ' - Creator' === rendered_title( render_page_with( str_repeat( 'a', 250 ) ) ),
	'an over-long stored name is capped' );
// It is tenant data on an admin screen either way — the page escapes it.
check( false !== strpos( render_page_with( '<b>Caz</b>' ), '&lt;b&gt;Caz&lt;/b&gt; - Creator' ),
	'a stored name carrying markup is escaped, not rendered' );

// ── [4] It goes when the tenant does ────────────────────────────────────
echo "\n[4] uninstall and disconnect remove it\n";
check( false !== strpos( (string) file_get_contents( DEF_CORE_PLUGIN_DIR . 'uninstall.php' ), "'def_core_creator_name'" ),
	'uninstall.php deletes the option with its neighbours' );

$m      = new ReflectionMethod( 'DEF_Core_OAuth', 'ajax_disconnect' );
$lines  = file( (string) $m->getFileName() );
$source = implode( '', array_slice( $lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1 ) );
check( false !== strpos( $source, "delete_option( 'def_core_creator_name' )" ),
	'disconnecting forgets the name — it belonged to that tenant' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
