<?php
/**
 * The Setup Assistant is on the Connection Logs page (S1).
 *
 * Ask Sam IS the help layer — the admin pages carry no on-screen help text, so a
 * page without the drawer is a page with no help at all. Connection Logs was the
 * one Digital Employees page that had neither: no trigger beside the h1, no
 * drawer markup, and neither of the drawer's two asset handles.
 *
 * This RUNS the shipped renderer rather than reading it. DEF_Core_Logs_Page::
 * render_page() is called under stubs and its output captured, so what is asserted
 * is the HTML a DEF Admin actually receives and the handles WordPress is actually
 * asked for — including the ones DEF_Core_Admin::enqueue_setup_assistant_drawer()
 * adds, which is the real helper here and not a stand-in.
 *
 * The bite: delete the include at the foot of render_page() and checks 4 and 5 go
 * red; delete the enqueue and 1-3 go red; delete the trigger button and 6 goes red.
 *
 * The settings page is held to the same thing from the other side. Rendering it
 * would mean stubbing the whole options surface, so its include is read off the
 * METHOD's own source through Reflection — narrower than grepping the file, and it
 * still goes red if the line leaves render_settings_page().
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── Recorders ────────────────────────────────────────────────────────────
// What the page asked WordPress for, and who the current user is.
$GLOBALS['_def_styles']    = array();
$GLOBALS['_def_scripts']   = array();
$GLOBALS['_def_localized'] = array();
$GLOBALS['_def_caps']      = array();

function _def_reset_recorders( array $caps ): void {
	$GLOBALS['_def_styles']    = array();
	$GLOBALS['_def_scripts']   = array();
	$GLOBALS['_def_localized'] = array();
	$GLOBALS['_def_caps']      = $caps;
}

// ── $wpdb: enough for the table-exists probe, the count and one page of rows ──
class WPDB_Logs_Page_Stub {
	public $prefix = 'wp_';

	public function prepare( $query, ...$args ) {
		foreach ( $args as $a ) {
			$query = preg_replace( '/%[dsf]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $query, 1 );
		}
		return $query;
	}
	public function esc_like( $text ) {
		return $text;
	}
	public function get_var( $query ) {
		// SHOW TABLES LIKE — the page bails early on a falsy answer, so the
		// table has to look present or nothing below this renders at all.
		if ( false !== stripos( $query, 'SHOW TABLES' ) ) {
			return 'wp_def_core_logs';
		}
		return 1; // COUNT(*).
	}
	public function get_results( $query ) {
		return array(
			(object) array(
				'timestamp'  => '2026-09-14 15:15:00',
				'level'      => 'error',
				'source'     => 'connection',
				'message'    => 'Handshake refused by the platform',
				'context'    => '{"status":401}',
				'request_id' => 'abcd1234efgh',
			),
		);
	}
}

global $wpdb;
$wpdb = new WPDB_Logs_Page_Stub();

// ── WordPress stubs this page and the drawer template reach for ──────────
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, ...$args ): bool {
		return in_array( $cap, $GLOBALS['_def_caps'], true );
	}
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '' ) {
		throw new RuntimeException( 'wp_die: ' . (string) $message );
	}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, ...$rest ): void {
		$GLOBALS['_def_styles'][] = $handle;
	}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, ...$rest ): void {
		$GLOBALS['_def_scripts'][] = $handle;
	}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $name, $data ): bool {
		$GLOBALS['_def_localized'][ $name ] = $data;
		return true;
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return (object) array( 'first_name' => 'Sorin', 'display_name' => 'Sorin' );
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, string $url = '' ): string {
		return $url . '?' . http_build_query( (array) $args );
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action = '' ): string {
		return 'nonce-' . md5( $action );
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ): string {
		return number_format( (float) $number, (int) $decimals );
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $a, $b, $echo = true ) {
		$r = ( (string) $a === (string) $b ) ? ' selected="selected"' : '';
		if ( $echo ) {
			echo $r;
		}
		return $r;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ): string {
		return htmlspecialchars( (string) $t, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $t ): string {
		return htmlspecialchars( (string) $t, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ): string {
		return htmlspecialchars( (string) $url, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $t ): string {
		return addslashes( (string) $t );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $t, string $d = '' ): string {
		return esc_html( $t );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $t, string $d = '' ): string {
		return esc_attr( $t );
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $t, string $d = '' ): void {
		echo esc_html( $t );
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( string $t, string $d = '' ): void {
		echo esc_attr( $t );
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-logger.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-admin.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-logs-page.php';

$pass = 0;
$fail = 0;

function check( bool $cond, string $label, string $detail = '' ): void {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label" . ( $detail ? " ($detail)" : '' ) . "\n";
	}
}

/** Render the Connection Logs page as a user holding exactly $caps. */
function render_logs_as( array $caps ): string {
	_def_reset_recorders( $caps );
	ob_start();
	try {
		DEF_Core_Logs_Page::render_page();
	} catch ( Throwable $e ) {
		ob_end_clean();
		return 'THREW: ' . $e->getMessage();
	}
	return (string) ob_get_clean();
}

echo "=== Setup Assistant on the Connection Logs page ===\n";

// ── 1-6. A DEF Admin gets the drawer ─────────────────────────────────────
echo "\n[1] A DEF Admin opening Connection Logs gets the Setup Assistant\n";
$html = render_logs_as( array( 'manage_options', 'def_admin_access' ) );

check( 0 !== strpos( $html, 'THREW:' ), 'the page renders', $html );

check( in_array( 'def-core-setup-assistant', $GLOBALS['_def_styles'], true ),
	'the drawer stylesheet is enqueued',
	'styles: ' . implode( ', ', $GLOBALS['_def_styles'] ) );

check( in_array( 'def-core-setup-assistant', $GLOBALS['_def_scripts'], true ),
	'the drawer script is enqueued',
	'scripts: ' . implode( ', ', $GLOBALS['_def_scripts'] ) );

check( isset( $GLOBALS['_def_localized']['defSetupAssistant']['chatStreamUrl'] ),
	'the drawer is handed its REST config (defSetupAssistant.chatStreamUrl)' );

check( false !== strpos( $html, 'id="def-setup-assistant-drawer"' ),
	'the drawer panel is in the page markup' );

check( false !== strpos( $html, 'class="def-sa-close"' ),
	'the drawer carries its close button' );

check( false !== strpos( $html, 'id="def-setup-assistant-trigger"' ),
	'the trigger button sits beside the page heading' );

// The trigger belongs to the h1, not to the filter bar — that is where every
// other Digital Employees page puts it.
$h1 = strstr( strstr( $html, '<h1>' ) ?: '', '</h1>', true ) ?: '';
check( false !== strpos( $h1, 'id="def-setup-assistant-trigger"' ),
	'the trigger is inside the h1, as on the other pages' );

// ── 7-9. A manage_options user without def_admin_access gets none of it ──
echo "\n[2] A user without def_admin_access is shipped none of it\n";
$html_plain = render_logs_as( array( 'manage_options' ) );

check( 0 !== strpos( $html_plain, 'THREW:' ), 'the page still renders', $html_plain );

check( ! in_array( 'def-core-setup-assistant', $GLOBALS['_def_styles'], true )
	&& ! in_array( 'def-core-setup-assistant', $GLOBALS['_def_scripts'], true ),
	'no drawer assets are enqueued for a non-admin',
	'styles: ' . implode( ', ', $GLOBALS['_def_styles'] )
		. ' scripts: ' . implode( ', ', $GLOBALS['_def_scripts'] ) );

check( false === strpos( $html_plain, 'def-setup-assistant-drawer' )
	&& false === strpos( $html_plain, 'def-setup-assistant-trigger' ),
	'neither the drawer nor its trigger is in the markup for a non-admin' );

// ── 10-12. The table the page renders scrolls inside its own box ─────────
//
// Asserted on the OUTPUT, not on the source: what matters is that the wrapper
// actually encloses the table a reader receives.
echo "\n[3] The log table is rendered inside the scroll container\n";

check( false !== strpos( $html, 'class="def-core-table-scroll"' ),
	'the scroll container is rendered' );

// Containment, not mere order. "The table appears after the wrapper opens" is
// satisfied by an empty wrapper standing next to the table, so assert the wrapper
// is still OPEN where the table begins and CLOSES once it ends.
$from_open    = strstr( $html, '<div class="def-core-table-scroll">' ) ?: '';
$before_table = strstr( $from_open, '<table class="widefat striped def-core-logs-table"', true );
check( is_string( $before_table ) && false === strpos( $before_table, '</div>' ),
	'the wrapper is still open where the log table begins — the table is INSIDE it, not beside it' );

check( 1 === preg_match( '#</table>\s*</div>#', $from_open ),
	'the wrapper closes around the log table, not before it' );

check( false === strpos( $html, 'table-layout: fixed' ),
	'the table no longer carries table-layout: fixed — that is what squeezed Message to one letter per line' );

// ── 13-14. The settings page still carries the drawer ────────────────────
//
// Read off the method's own source. The point of this pair is that the Logs page
// borrowed the settings page's helper: a change that quietly moved the include
// out of render_settings_page() would leave the Logs checks above perfectly green.
echo "\n[4] The settings page still carries the drawer\n";

$m      = new ReflectionMethod( 'DEF_Core_Admin', 'render_settings_page' );
$lines  = file( (string) $m->getFileName() );
$source = implode( '', array_slice( $lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1 ) );

check( false !== strpos( $source, "templates/setup-assistant-drawer.php" ),
	'render_settings_page() still includes the drawer template' );

check( false !== strpos( $source, 'enqueue_setup_assistant_drawer()' ),
	'render_settings_page() still enqueues the drawer assets' );

// The helper both pages share does what both pages depend on it doing.
_def_reset_recorders( array( 'manage_options', 'def_admin_access' ) );
DEF_Core_Admin::enqueue_setup_assistant_drawer();
check( in_array( 'def-core-setup-assistant', $GLOBALS['_def_styles'], true )
	&& in_array( 'def-core-setup-assistant', $GLOBALS['_def_scripts'], true ),
	'the shared helper enqueues both drawer handles' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
