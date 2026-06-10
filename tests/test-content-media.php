<?php
/**
 * Content Agent Wave 2 (images) — media sideload/delete bridge tests.
 *
 * Verifies the def-core half of image handling:
 *  - POST /content/media (rest_sideload) decodes + validates hostile bytes
 *    (strict base64, finfo sniff vs the claimed mime, png/jpeg/webp allowlist —
 *    never SVG, 10MB cap, traversal-safe filename with a forced extension),
 *    sideloads UNATTACHED, sets the alt + the `_def_created` scope stamp, and
 *    returns {attachment_id, url}.
 *  - The upload_files capability is enforced (no write without it).
 *  - POST /content/media/{id}/delete (rest_delete) refuses with a uniform 404
 *    unless the attachment exists, is DEF-created AND unattached; delete_post
 *    is enforced; eligible items are force-deleted.
 *  - register_social_image_size registers the exact-crop 1200×630 `def-social`
 *    rendition.
 *
 * Drives the real handlers with WP function stubs (HMAC verify lives in the
 * permission_callback, not exercised here). The finfo sniffing is REAL — the
 * happy path uses genuine PNG bytes and the rejects use genuinely wrong bytes.
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── WP stubs ────────────────────────────────────────────────────────────

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, int $status = 200 ) { $this->data = $data; $this->status = $status; }
		public function get_data() { return $this->data; }
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $body   = array();
		private $params = array();
		public function __construct( string $method = 'GET', string $route = '' ) {}
		public function set_json( array $b ): void { $this->body = $b; }
		public function get_json_params() { return $this->body; }
		public function set_param( string $k, $v ): void { $this->params[ $k ] = $v; }
		public function get_param( string $k ) { return $this->params[ $k ] ?? null; }
	}
}

$GLOBALS['t_can_upload']     = true;
$GLOBALS['t_can_delete']     = true;
$GLOBALS['t_sideloads']      = array(); // captured media_handle_sideload calls
$GLOBALS['t_sideload_error'] = false;
$GLOBALS['t_meta_writes']    = array();
$GLOBALS['t_deletes']        = array(); // captured wp_delete_attachment calls
$GLOBALS['t_image_sizes']    = array(); // captured add_image_size calls
// Attachment fixtures: id => [post_type, post_parent, def_created].
$GLOBALS['t_attachments']    = array(
	50 => array( 'post_type' => 'attachment', 'post_parent' => 0, 'def_created' => true ),
	51 => array( 'post_type' => 'attachment', 'post_parent' => 0, 'def_created' => false ),
	52 => array( 'post_type' => 'attachment', 'post_parent' => 9, 'def_created' => true ),
	53 => array( 'post_type' => 'post',       'post_parent' => 0, 'def_created' => true ),
);

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, ...$a ) {
		if ( 'upload_files' === $cap ) { return (bool) $GLOBALS['t_can_upload']; }
		if ( 'delete_post' === $cap ) { return (bool) $GLOBALS['t_can_delete']; }
		return true; // def_staff_access etc.
	}
}
if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( $id ) {}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		$id = (int) $id;
		if ( ! isset( $GLOBALS['t_attachments'][ $id ] ) ) { return null; }
		$o              = new stdClass();
		$o->ID          = $id;
		$o->post_type   = $GLOBALS['t_attachments'][ $id ]['post_type'];
		$o->post_parent = $GLOBALS['t_attachments'][ $id ]['post_parent'];
		return $o;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) {
		if ( '_def_created' === $key ) {
			return ! empty( $GLOBALS['t_attachments'][ (int) $id ]['def_created'] ) ? 1 : '';
		}
		return '';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['t_meta_writes'][] = array( 'id' => (int) $id, 'key' => $key, 'value' => $value );
		return true;
	}
}
if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $filename = '' ) {
		if ( ! empty( $GLOBALS['t_tempnam_override'] ) ) { return $GLOBALS['t_tempnam_override']; }
		return tempnam( sys_get_temp_dir(), 'def' );
	}
}
$GLOBALS['t_tempnam_override'] = null;

/**
 * Stream wrapper simulating a disk-full temp file: accepts the first few bytes
 * then refuses the rest, so file_put_contents returns a SHORT count (not false).
 */
class DEF_Test_Partial_Stream {
	public $context;
	private $written = 0;
	public function stream_open( $path, $mode, $options, &$opened_path ) { return true; }
	public function stream_write( $data ) {
		if ( $this->written > 0 ) { return 0; } // refuse the remainder → partial write
		$chunk = min( strlen( $data ), 8 );
		$this->written += $chunk;
		return $chunk;
	}
	public function stream_flush() { return true; }
	public function stream_close() {}
	public function unlink( $path ) { return true; }
}
if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
	// Mirror WP: trust the real bytes (finfo on the temp FILE), reconcile vs ext.
	function wp_check_filetype_and_ext( $file, $filename ) {
		$finfo = new finfo( FILEINFO_MIME_TYPE );
		$real  = (string) $finfo->file( $file );
		$map   = array( 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp' );
		if ( ! isset( $map[ $real ] ) ) {
			return array( 'ext' => false, 'type' => false, 'proper_filename' => false );
		}
		return array( 'ext' => $map[ $real ], 'type' => $real, 'proper_filename' => false );
	}
}
if ( ! function_exists( 'media_handle_sideload' ) ) {
	// Predefined so the handler skips the wp-admin includes. Captures the call;
	// consumes (unlinks) the temp file like the real one.
	function media_handle_sideload( $file_array, $post_id = 0, $desc = null ) {
		$GLOBALS['t_sideloads'][] = array(
			'name'   => $file_array['name'],
			'parent' => $post_id,
			'bytes'  => filesize( $file_array['tmp_name'] ),
		);
		@unlink( $file_array['tmp_name'] );
		if ( ! empty( $GLOBALS['t_sideload_error'] ) ) {
			return new WP_Error( 'upload_error', 'nope' );
		}
		return 777;
	}
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $id ) { return 'https://site.test/wp-content/uploads/def-' . (int) $id . '.png'; }
}
if ( ! function_exists( 'wp_delete_attachment' ) ) {
	function wp_delete_attachment( $id, $force = false ) {
		$GLOBALS['t_deletes'][] = array( 'id' => (int) $id, 'force' => (bool) $force );
		return get_post( $id );
	}
}
if ( ! function_exists( 'add_image_size' ) ) {
	function add_image_size( $name, $width = 0, $height = 0, $crop = false ) {
		$GLOBALS['t_image_sizes'][ $name ] = array( 'w' => (int) $width, 'h' => (int) $height, 'crop' => $crop );
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-media.php';

$_SERVER['HTTP_X_DEF_USER'] = '5';

// ── Tiny assertion harness ──────────────────────────────────────────────

$pass = 0;
$fail = 0;

function assert_true( $value, string $label ): void {
	global $pass, $fail;
	if ( $value ) { $pass++; } else { $fail++; echo "  FAIL: $label\n"; }
}
function assert_same( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) { $pass++; } else {
		$fail++;
		echo "  FAIL: $label (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
	}
}

/** 1×1 transparent PNG — real bytes, sniffs as image/png. */
function tiny_png(): string {
	return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
}

function sideload_request( array $overrides = array() ): WP_REST_Request {
	$req = new WP_REST_Request( 'POST', '' );
	$req->set_json( array_merge( array(
		'image_b64' => base64_encode( tiny_png() ),
		'mime'      => 'image/png',
		'filename'  => 'hero image.png',
		'alt_text'  => 'A cat on a mat',
	), $overrides ) );
	return $req;
}

// ── 1. Happy path: PNG sideloads unattached, alt + scope stamp, id+url ──
echo "[1] sideload accepts a real PNG\n";
$GLOBALS['t_sideloads']   = array();
$GLOBALS['t_meta_writes'] = array();
$resp = DEF_Core_Media::rest_sideload( sideload_request() );
assert_true( $resp instanceof WP_REST_Response, 'returns WP_REST_Response' );
$data = $resp->get_data();
assert_same( 777, $data['attachment_id'] ?? null, 'returns attachment_id' );
assert_true( is_string( $data['url'] ?? null ) && '' !== $data['url'], 'returns url' );
assert_same( 1, count( $GLOBALS['t_sideloads'] ), 'media_handle_sideload called once' );
assert_same( 0, $GLOBALS['t_sideloads'][0]['parent'], 'sideloaded UNATTACHED (post_parent 0)' );
assert_same( strlen( tiny_png() ), $GLOBALS['t_sideloads'][0]['bytes'], 'temp file carried the exact decoded bytes' );
$writes = array();
foreach ( $GLOBALS['t_meta_writes'] as $w ) { $writes[ $w['key'] ] = $w; }
assert_same( 'A cat on a mat', $writes['_wp_attachment_image_alt']['value'] ?? null, 'alt text set' );
assert_same( 1, $writes['_def_created']['value'] ?? null, '_def_created scope stamp set' );
assert_same( 777, $writes['_def_created']['id'] ?? null, 'stamp on the new attachment' );

// ── 2. Filename is traversal-safe with a forced extension ───────────────
echo "[2] filename sanitized + extension forced\n";
$GLOBALS['t_sideloads'] = array();
DEF_Core_Media::rest_sideload( sideload_request( array( 'filename' => '../../evil dir/payload.php' ) ) );
$name = $GLOBALS['t_sideloads'][0]['name'] ?? '';
assert_true( false === strpos( $name, '/' ) && false === strpos( $name, '\\' ), 'no path separators survive' );
assert_true( (bool) preg_match( '/\.png$/', $name ), 'extension forced to the validated type (.png)' );
assert_true( '.png' !== $name && '' !== $name, 'non-empty basename' );

// Empty filename falls back to a default base.
$GLOBALS['t_sideloads'] = array();
DEF_Core_Media::rest_sideload( sideload_request( array( 'filename' => '' ) ) );
assert_same( 'def-image.png', $GLOBALS['t_sideloads'][0]['name'] ?? '', 'empty filename → def-image.png' );

// ── 3. SVG (and any non-allowlisted mime) rejected up front ─────────────
echo "[3] svg / unknown mime rejected\n";
$GLOBALS['t_sideloads'] = array();
$data = DEF_Core_Media::rest_sideload( sideload_request( array(
	'mime'      => 'image/svg+xml',
	'image_b64' => base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"></svg>' ),
) ) )->get_data();
assert_same( 'invalid', $data['status'] ?? null, 'svg → status=invalid' );
assert_same( array(), $GLOBALS['t_sideloads'], 'no sideload for svg' );

// ── 4. Bytes must match the declared mime (real finfo sniff) ────────────
echo "[4] byte/mime mismatch rejected\n";
$GLOBALS['t_sideloads'] = array();
$data = DEF_Core_Media::rest_sideload( sideload_request( array(
	'image_b64' => base64_encode( '<?php echo "owned"; ?>' ), // claims image/png
) ) )->get_data();
assert_same( 'invalid', $data['status'] ?? null, 'php-script bytes claiming png → invalid' );
$data = DEF_Core_Media::rest_sideload( sideload_request( array(
	'mime' => 'image/jpeg', // real PNG bytes claiming jpeg
) ) )->get_data();
assert_same( 'invalid', $data['status'] ?? null, 'png bytes claiming jpeg → invalid' );
assert_same( array(), $GLOBALS['t_sideloads'], 'no sideload on mismatch' );

// ── 5. Broken base64 and oversize payloads rejected ─────────────────────
echo "[5] bad base64 / oversize rejected\n";
$GLOBALS['t_sideloads'] = array();
$data = DEF_Core_Media::rest_sideload( sideload_request( array( 'image_b64' => '!!!not-base64!!!' ) ) )->get_data();
assert_same( 'invalid', $data['status'] ?? null, 'invalid base64 → invalid' );
$data = DEF_Core_Media::rest_sideload( sideload_request( array(
	'image_b64' => base64_encode( str_repeat( 'A', 10485761 ) ), // 10MB + 1
) ) )->get_data();
assert_same( 'invalid', $data['status'] ?? null, '>10MB → invalid' );
assert_same( array(), $GLOBALS['t_sideloads'], 'no sideload for rejected payloads' );

// ── 6. upload_files capability enforced ─────────────────────────────────
echo "[6] sideload requires upload_files\n";
$GLOBALS['t_can_upload'] = false;
$GLOBALS['t_sideloads']  = array();
$resp = DEF_Core_Media::rest_sideload( sideload_request() );
assert_true( is_wp_error( $resp ), 'returns WP_Error without upload_files' );
assert_same( 'rest_forbidden', $resp->get_error_code(), 'error code rest_forbidden' );
assert_same( 403, $resp->get_error_data()['status'], 'HTTP 403' );
assert_same( array(), $GLOBALS['t_sideloads'], 'no sideload without capability' );
$GLOBALS['t_can_upload'] = true;

// ── 7. Sideload failure surfaces as a soft error ────────────────────────
echo "[7] sideload failure surfaces\n";
$GLOBALS['t_sideload_error'] = true;
$data = DEF_Core_Media::rest_sideload( sideload_request() )->get_data();
assert_same( 'sideload_failed', $data['status'] ?? null, 'media_handle_sideload error → sideload_failed' );
$GLOBALS['t_sideload_error'] = false;

// ── 8. Delete: eligible DEF-created unattached attachment ───────────────
echo "[8] delete removes an eligible attachment\n";
$GLOBALS['t_deletes'] = array();
$req = new WP_REST_Request( 'POST', '' );
$req->set_param( 'id', '50' );
$data = DEF_Core_Media::rest_delete( $req )->get_data();
assert_same( 'deleted', $data['status'] ?? null, 'status=deleted' );
assert_same( 50, $data['attachment_id'] ?? null, 'echoes the attachment id' );
assert_same( array( array( 'id' => 50, 'force' => true ) ), $GLOBALS['t_deletes'], 'wp_delete_attachment(50, true) called' );

// ── 9. Delete: uniform 404 for every ineligible case (no info leak) ─────
echo "[9] delete refuses ineligible targets with a uniform 404\n";
foreach ( array(
	'99' => 'non-existent id',
	'51' => 'not DEF-created',
	'52' => 'already attached to a post',
	'53' => 'not an attachment',
) as $id => $why ) {
	$GLOBALS['t_deletes'] = array();
	$req = new WP_REST_Request( 'POST', '' );
	$req->set_param( 'id', $id );
	$resp = DEF_Core_Media::rest_delete( $req );
	assert_true( is_wp_error( $resp ), "WP_Error for: $why" );
	assert_same( 'not_found', $resp->get_error_code(), "uniform not_found for: $why" );
	assert_same( 404, $resp->get_error_data()['status'], "HTTP 404 for: $why" );
	assert_same( array(), $GLOBALS['t_deletes'], "nothing deleted for: $why" );
}

// ── 10. Delete: delete_post capability enforced on eligible items ───────
echo "[10] delete requires delete_post\n";
$GLOBALS['t_can_delete'] = false;
$GLOBALS['t_deletes']    = array();
$req = new WP_REST_Request( 'POST', '' );
$req->set_param( 'id', '50' );
$resp = DEF_Core_Media::rest_delete( $req );
assert_true( is_wp_error( $resp ), 'returns WP_Error without delete_post' );
assert_same( 'rest_forbidden', $resp->get_error_code(), 'error code rest_forbidden' );
assert_same( array(), $GLOBALS['t_deletes'], 'nothing deleted without capability' );
$GLOBALS['t_can_delete'] = true;

// ── 11. def-social exact-crop size registration ─────────────────────────
echo "[11] def-social 1200×630 crop registered\n";
DEF_Core_Media::register_social_image_size();
assert_same(
	array( 'w' => 1200, 'h' => 630, 'crop' => true ),
	$GLOBALS['t_image_sizes']['def-social'] ?? null,
	'add_image_size(def-social, 1200, 630, true)'
);

// ── 12. Oversize payload rejected BEFORE decoding (pre-decode guard) ────
echo "[12] oversize base64 rejected before decode\n";
$GLOBALS['t_sideloads'] = array();
$data = DEF_Core_Media::rest_sideload( sideload_request( array(
	// Over the pre-decode ceiling (MAX_BYTES*4/3 + 1024 ≈ 13.3MB of b64) AND
	// not valid base64 ('!') — if the length guard fires first, as it must,
	// the reason is the size cap, never the decoder.
	'image_b64' => str_repeat( 'A', 14000000 ) . '!',
) ) )->get_data();
assert_same( 'invalid', $data['status'] ?? null, 'oversize b64 → invalid' );
assert_same( 'image exceeds 10MB limit', $data['reason'] ?? null, 'rejected by the length guard, not the decoder' );
assert_same( array(), $GLOBALS['t_sideloads'], 'no sideload' );

// ── 13. Partial temp-file write fails closed ────────────────────────────
echo "[13] partial temp write rejected\n";
stream_wrapper_register( 'defpartial', 'DEF_Test_Partial_Stream' );
$GLOBALS['t_tempnam_override'] = 'defpartial://tmp-image';
$GLOBALS['t_sideloads']        = array();
set_error_handler( function () { return true; } ); // mute the short-write notice
$data = DEF_Core_Media::rest_sideload( sideload_request() )->get_data();
restore_error_handler();
$GLOBALS['t_tempnam_override'] = null;
assert_same( 'sideload_failed', $data['status'] ?? null, 'short write → sideload_failed' );
assert_same( 'could not write temp file', $data['reason'] ?? null, 'temp-write failure reason' );
assert_same( array(), $GLOBALS['t_sideloads'], 'truncated file never reaches sideload' );

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
