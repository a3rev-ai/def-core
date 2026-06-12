<?php
/**
 * Connection-log noise-reduction tests (DEF_Core_Export::log_sync_query).
 *
 * Verifies:
 * - A zero-change export emits exactly ONE summary INFO row (not the trio)
 *   and returns false so the caller skips its response row.
 * - A non-empty export emits the "WP_Query executed" DEBUG row but the raw
 *   SQL is NOT in the log context when WP_DEBUG is off, and returns true.
 *
 * Runs standalone with WP stubs (no WordPress bootstrap). WP_DEBUG is left
 * undefined here so the SQL-strip path is exercised deterministically.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// Capture-only $wpdb: every logged row lands in ->rows for inspection.
class WPDB_Log_Capture {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $rows       = array();

	public function insert( $table, $data, $formats ) {
		$this->rows[] = $data;
		return 1;
	}
	public function get_var( $query ) {
		return 0;
	}
	public function prepare( $query, ...$args ) {
		return $query;
	}
	public function query( $query ) {
		return 0;
	}
}

global $wpdb;
$wpdb = new WPDB_Log_Capture();

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value, ...$args ) {
		return $value;
	}
}

// Minimal WP_Query stand-in — only the fields log_sync_query reads.
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public $found_posts   = 0;
		public $post_count    = 0;
		public $max_num_pages = 0;
		public $query_vars    = array( 'posts_per_page' => 50 );
		public $request       = '';
		public $posts         = array();
	}
}

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-logger.php';
require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-export.php';

// Log at debug level so the DEBUG row actually writes and can be inspected.
update_option( 'def_core_log_level', 'debug' );

$pass = 0;
$fail = 0;

function assert_true( $value, string $label ): void {
	global $pass, $fail;
	if ( $value ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label\n";
	}
}

function assert_equals( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
	}
}

echo "=== Connection-log noise tests ===\n";

// ── 1. Zero-change export → one compact summary row ─────────────────────
echo "\n[1] Zero-change export logs exactly one summary row\n";
$wpdb->rows = array();
$query = new WP_Query();
$query->found_posts = 0;

$result = DEF_Core_Export::log_sync_query( 'page', 50, $query, 'req-zero' );

assert_equals( false, $result, 'returns false when nothing changed' );
assert_equals( 1, count( $wpdb->rows ), 'exactly one row logged (not the request/query/response trio)' );
assert_equals( 'info', $wpdb->rows[0]['level'], 'summary row is INFO level' );
assert_equals( 'Incremental sync: 0 changes', $wpdb->rows[0]['message'], 'summary message' );
assert_equals( 'sync', $wpdb->rows[0]['source'], 'source is sync' );

// ── 2. Non-empty export → debug row WITHOUT raw SQL (WP_DEBUG off) ───────
echo "\n[2] Non-empty export omits raw SQL when WP_DEBUG is off\n";
assert_true( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ), 'precondition: WP_DEBUG is off' );

$wpdb->rows = array();
$query = new WP_Query();
$query->found_posts   = 5;
$query->post_count    = 5;
$query->max_num_pages = 1;
$query->request       = 'SELECT * FROM wp_posts WHERE post_status = "publish"';

$result = DEF_Core_Export::log_sync_query( 'page', 50, $query, 'req-changes' );

assert_equals( true, $result, 'returns true when rows found' );
assert_equals( 1, count( $wpdb->rows ), 'one debug row logged' );
assert_equals( 'debug', $wpdb->rows[0]['level'], 'row is DEBUG level' );
assert_equals( 'WP_Query executed', $wpdb->rows[0]['message'], 'debug message' );

$context = json_decode( (string) $wpdb->rows[0]['context'], true );
assert_true( is_array( $context ), 'context decodes to array' );
assert_true( ! isset( $context['sql'] ), 'raw SQL is NOT in context when WP_DEBUG off' );
assert_true( isset( $context['found_posts'] ), 'query metadata (found_posts) retained' );
assert_equals( 5, $context['found_posts'], 'found_posts value correct' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
