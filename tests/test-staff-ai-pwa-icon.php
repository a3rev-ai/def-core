<?php
/**
 * The Staff AI app's icon (8.2.7).
 *
 * Steve's iPhone installed the console as a small purple tile inside a white
 * ring: the shell emitted no apple-touch-icon at all, so iOS fell back to the
 * manifest PNG and masked the uploaded artwork's transparent, pre-rounded
 * corners onto white. Three things are pinned here:
 *
 *  1. app_icon() — ONE chain, Branding → Web App Icon, Branding → logo, the
 *     WordPress site icon, the bundled default — shared by the manifest and
 *     the shell so they cannot disagree about which icon this site wears. A
 *     link that cannot serve the size asked for, or is not a raster every
 *     browser reads, moves the chain along: a wordmark logo declared as both
 *     manifest entries is two identical icons and none 192 square, which is
 *     what makes Chrome and Edge withdraw Install.
 *  2. The size it declares is the size the file REALLY is. The old code asked
 *     wp_get_attachment_image_url() for array(192, 192), which returns the
 *     NEAREST registered size, and then declared "192x192" whatever came back
 *     — on a3rev a 250×250 file announced as 192.
 *  3. The manifest's icons carry `purpose => 'any maskable'`, without which
 *     Android drops the icon into a white plate of its own.
 *
 * The bundled PNGs themselves are pinned too: present, square, and with no
 * alpha channel to mask onto white. The shell's head tags are the browser
 * harness's half — tests/browser/harness-pwa-icon.js.
 *
 * Runs standalone (no WordPress bootstrap), the test-staff-ai-chat-images.php
 * harness pattern.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'DEF_CORE_PLUGIN_DIR' ) ) {
	define( 'DEF_CORE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'DEF_CORE_PLUGIN_URL' ) ) {
	define( 'DEF_CORE_PLUGIN_URL', 'https://example.test/wp-content/plugins/def-core/' );
}
if ( ! defined( 'DEF_CORE_VERSION' ) ) {
	define( 'DEF_CORE_VERSION', '8.2.7' );
}

require_once __DIR__ . '/wp-stubs.php';

// ── Controllable state ───────────────────────────────────────────────────

global $_wp_test_attachments;
$_wp_test_attachments = array();

// ── Stubs ────────────────────────────────────────────────────────────────

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $t, string $d = 'default' ): string { return $t; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $t ): string { return $t; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $t, string $d = 'default' ): string { return $t; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string { return 'name' === $show ? 'Test Site' : ''; }
}
if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(): void {}
}
foreach ( array( 'add_action', 'add_filter', 'add_rewrite_rule', 'register_rest_route' ) as $noop ) {
	if ( ! function_exists( $noop ) ) {
		eval( "function $noop( ...\$a ): void {}" );
	}
}

/**
 * The media library: id => array( mime, width, height, sizes ).
 * wp_get_attachment_image_url() models WordPress faithfully — it returns the
 * URL of a NAMED cut, and that is all the resolver may ask it for.
 */
if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	function wp_get_attachment_metadata( int $id ) {
		global $_wp_test_attachments;
		return $_wp_test_attachments[ $id ]['meta'] ?? false;
	}
}
if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( int $id, $size = 'thumbnail' ) {
		global $_wp_test_attachments;
		if ( ! isset( $_wp_test_attachments[ $id ] ) || ! is_string( $size ) ) {
			return false;
		}
		return "https://example.test/uploads/att{$id}-{$size}.png";
	}
}
if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( int $id ) {
		global $_wp_test_attachments;
		return $_wp_test_attachments[ $id ]['mime'] ?? false;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-def-core-staff-ai.php';

// ── Helpers ──────────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function check( bool $cond, string $label ): void {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  ✓ $label\n";
	} else {
		$fail++;
		echo "  ✗ $label\n";
	}
}

function call_private( string $method, ...$args ) {
	$m = new ReflectionMethod( 'DEF_Core_Staff_AI', $method );
	$m->setAccessible( true );
	return $m->invoke( null, ...$args );
}

/**
 * Put one attachment in the library.
 *
 * @param array $sizes Registered cuts, name => array( width, height ).
 */
function attach( int $id, int $w, int $h, array $sizes = array(), string $mime = 'image/png' ): void {
	global $_wp_test_attachments;
	$meta = array( 'width' => $w, 'height' => $h, 'sizes' => array() );
	foreach ( $sizes as $name => $wh ) {
		$meta['sizes'][ $name ] = array( 'width' => $wh[0], 'height' => $wh[1], 'file' => "$name.png" );
	}
	$_wp_test_attachments[ $id ] = array( 'meta' => $meta, 'mime' => $mime );
}

function reset_options(): void {
	foreach ( array( 'def_core_app_icon_id', 'def_core_logo_id', 'site_icon' ) as $opt ) {
		update_option( $opt, 0 );
	}
}

echo "=== Staff AI app icon (8.2.7) ===\n";

// ── 1. The chain ─────────────────────────────────────────────────────────

echo "\n[1] The chain: Web App Icon → logo → site icon → bundled\n";

attach( 11, 512, 512 );          // Branding → Web App Icon
attach( 22, 800, 800 );          // Branding → logo
attach( 33, 512, 512 );          // the WordPress site icon

reset_options();
update_option( 'def_core_app_icon_id', 11 );
update_option( 'def_core_logo_id', 22 );
update_option( 'site_icon', 33 );
check( call_private( 'app_icon', 512 )['src'] === 'https://example.test/uploads/att11-full.png', 'the Web App Icon wins when it is set' );

update_option( 'def_core_app_icon_id', 0 );
check( call_private( 'app_icon', 512 )['src'] === 'https://example.test/uploads/att22-full.png', 'no Web App Icon → the Branding logo' );

update_option( 'def_core_logo_id', 0 );
check( call_private( 'app_icon', 512 )['src'] === 'https://example.test/uploads/att33-full.png', 'no logo → the WordPress site icon' );

update_option( 'site_icon', 0 );
$icon = call_private( 'app_icon', 512 );
check(
	$icon['src'] === DEF_CORE_PLUGIN_URL . 'assets/images/staff-ai-icon-512.png' && $icon['sizes'] === '512x512',
	'nothing set → the icon bundled with the plugin'
);

reset_options();
update_option( 'def_core_app_icon_id', 99 );
update_option( 'def_core_logo_id', 22 );
check( call_private( 'app_icon', 512 )['src'] === 'https://example.test/uploads/att22-full.png', 'an id with no image behind it does not end the chain' );

reset_options();
$_wp_test_attachments[ 44 ] = array( 'meta' => array(), 'mime' => 'image/png' ); // no pixel size
update_option( 'def_core_app_icon_id', 44 );
update_option( 'def_core_logo_id', 22 );
check( call_private( 'app_icon', 180 )['src'] === 'https://example.test/uploads/att22-full.png', 'an attachment WordPress has no pixel size for moves the chain along' );

// The media picker admits SVGs and wp_attachment_is_image() passes them, so an
// SVG can arrive here carrying a width and a height — and iOS ignores one.
reset_options();
attach( 45, 1024, 1024, array(), 'image/svg+xml' );
update_option( 'def_core_app_icon_id', 45 );
update_option( 'def_core_logo_id', 22 );
check( call_private( 'app_icon', 180 )['src'] === 'https://example.test/uploads/att22-full.png', 'an SVG WITH pixel size still moves the chain along — never the apple-touch-icon' );
update_option( 'def_core_logo_id', 0 );
$svg_manifest = call_private( 'pwa_manifest' );
check( 0 === count( preg_grep( '/svg/', array_merge( array_column( $svg_manifest['icons'], 'src' ), array_column( $svg_manifest['icons'], 'type' ) ) ) ), 'and never reaches the manifest either' );

reset_options();
attach( 46, 1024, 1024, array(), 'image/gif' );
update_option( 'def_core_app_icon_id', 46 );
update_option( 'def_core_logo_id', 22 );
check( call_private( 'app_icon', 512 )['src'] === 'https://example.test/uploads/att22-full.png', 'only PNG, JPEG and WebP resolve — anything else moves the chain along' );

// ── 2. The size it declares is the size it is ────────────────────────────

echo "\n[2] The declared size is read from the attachment, never assumed\n";

reset_options();
attach( 55, 1024, 1024, array( 'thumbnail' => array( 250, 250 ), 'medium' => array( 300, 300 ) ) );
update_option( 'def_core_app_icon_id', 55 );

$icon = call_private( 'app_icon', 192 );
check( $icon['sizes'] === '250x250' && $icon['src'] === 'https://example.test/uploads/att55-thumbnail.png', "a3rev's 250×250 thumbnail is declared 250x250, not 192x192" );

$icon = call_private( 'app_icon', 512 );
check( $icon['sizes'] === '1024x1024' && $icon['src'] === 'https://example.test/uploads/att55-full.png', 'at 512 no registered cut is big enough, so a3rev gets its original, declared at ITS size' );

reset_options();
attach( 66, 600, 600, array( 'thumbnail' => array( 150, 150 ), 'large' => array( 512, 512 ) ) );
update_option( 'def_core_app_icon_id', 66 );
$icon = call_private( 'app_icon', 192 );
check( $icon['sizes'] === '512x512' && $icon['src'] === 'https://example.test/uploads/att66-large.png', 'the SMALLEST cut that is genuinely big enough wins — never one below the size asked for' );

// An attachment too small on EITHER axis is not an icon. def_core_logo_id is a
// wordmark option, so this is its ordinary shape — and offered as both manifest
// entries it would be two identical icons, none of them 192 square, which is
// what makes Chrome and Edge withdraw Install.
reset_options();
attach( 77, 300, 120, array( 'thumbnail' => array( 150, 60 ) ) );
update_option( 'def_core_logo_id', 77 );
check( call_private( 'app_icon', 192 )['src'] === DEF_CORE_PLUGIN_URL . 'assets/images/staff-ai-icon-192.png', 'a 300×120 wordmark cannot serve 192 square, so the chain falls through to the bundled icon' );

$oblong = call_private( 'pwa_manifest' )['icons'];
check(
	$oblong[0]['sizes'] === '192x192' && $oblong[1]['sizes'] === '512x512'
	&& $oblong[0]['src'] !== $oblong[1]['src'],
	'and the manifest keeps two DISTINCT entries rather than declaring one file twice'
);

reset_options();
attach( 78, 150, 150 );
update_option( 'site_icon', 78 );
check( call_private( 'app_icon', 192 )['src'] === DEF_CORE_PLUGIN_URL . 'assets/images/staff-ai-icon-192.png', 'a 150×150 site icon falls through at 192 for the same reason' );

reset_options();
attach( 88, 900, 900, array(), 'image/jpeg' );
update_option( 'def_core_app_icon_id', 88 );
check( call_private( 'app_icon', 512 )['type'] === 'image/jpeg', 'the type is the attachment\'s own, not a hard-coded image/png' );

// ── 3. The manifest ──────────────────────────────────────────────────────

echo "\n[3] The manifest\n";

reset_options();
$manifest = call_private( 'pwa_manifest' );
$sizes    = array_column( $manifest['icons'], 'sizes' );
check( count( $manifest['icons'] ) === 2 && $sizes === array( '192x192', '512x512' ), 'two icons, 192 and 512' );
check( 0 === count( array_filter( $manifest['icons'], static fn( $i ) => ( $i['purpose'] ?? '' ) !== 'any maskable' ) ), 'every icon is `any maskable` — Android crops it rather than plating it on white' );
check( 0 === count( preg_grep( '/\.svg$/', array_column( $manifest['icons'], 'src' ) ) ), 'no SVG icon: the generated-initials fallback is gone' );
check(
	$manifest['theme_color'] === '#6366f1' && $manifest['background_color'] === '#ffffff'
	&& $manifest['display'] === 'standalone' && $manifest['start_url'] === home_url( '/staff-ai/' )
	&& $manifest['scope'] === home_url( '/staff-ai/' ) && $manifest['version'] === DEF_CORE_VERSION,
	'the rest of the manifest is untouched'
);

// ── 4. The bundled PNGs ──────────────────────────────────────────────────

echo "\n[4] The bundled PNGs\n";

reset_options();
check( call_private( 'app_icon', 180 )['src'] === DEF_CORE_PLUGIN_URL . 'assets/images/staff-ai-icon-180.png', 'the size asked for picks the bundled file that fits it — 180 for the apple-touch-icon' );


foreach ( array( 180, 192, 512 ) as $px ) {
	$file = dirname( __DIR__ ) . "/assets/images/staff-ai-icon-$px.png";
	$info = file_exists( $file ) ? getimagesize( $file ) : false;
	check( $info && $info[0] === $px && $info[1] === $px, "staff-ai-icon-$px.png ships, and is {$px}×{$px}" );
	// Colour type 6 (RGBA) / 4 (grey+alpha) / a tRNS chunk are the ways a PNG can
	// carry transparency for iOS to mask onto white. None of them may be here.
	$bytes = $info ? file_get_contents( $file ) : '';
	$type  = $info ? ord( $bytes[25] ) : 99;
	check( in_array( $type, array( 0, 2, 3 ), true ) && strpos( $bytes, 'tRNS' ) === false, "staff-ai-icon-$px.png is fully opaque — no alpha channel, no tRNS" );
}

// ── Summary ──────────────────────────────────────────────────────────────

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
