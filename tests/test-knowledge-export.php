<?php
/**
 * Knowledge Export endpoint tests.
 *
 * Verifies:
 * - Meta field denylist filtering
 * - Embedded document link extraction (same-domain, document types only)
 * - Exported post types exclude system types
 * - Attachment MIME whitelist
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── Minimal stubs for Knowledge Export ─────────────────────────────────

if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( array $args = [], string $output = 'names' ): array {
		// Simulate public post types.
		return array(
			'post'             => 'post',
			'page'             => 'page',
			'attachment'       => 'attachment',
			'nav_menu_item'    => 'nav_menu_item',
			'product'          => 'product',
			'topic'            => 'topic',
			'reply'            => 'reply',
			'forum'            => 'forum',
			'a3_timeline'      => 'a3_timeline',
			'wp_block'         => 'wp_block',
			'wp_template'      => 'wp_template',
			'wp_template_part' => 'wp_template_part',
			'wp_navigation'    => 'wp_navigation',
			'wp_global_styles' => 'wp_global_styles',
			'custom_css'       => 'custom_css',
			'shop_order'       => 'shop_order',
			'revision'         => 'revision',
		);
	}
}

// Minimal REST/Query stubs so content_deleted() can run without a WP bootstrap.
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params;
		public function __construct( array $params = array() ) { $this->params = $params; }
		public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, int $status = 200 ) { $this->data = $data; $this->status = $status; }
		public function get_data() { return $this->data; }
	}
}
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public $posts = array();
		public function __construct( array $args = array() ) { $this->posts = array(); }
	}
}

// Load the class under test.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
require_once __DIR__ . '/../includes/class-def-core-knowledge-export.php';

// ── Test Runner ────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;
$errors = [];

function assert_test( bool $condition, string $name ): void {
	global $passed, $failed, $errors;
	if ( $condition ) {
		$passed++;
		echo "  ✓ {$name}\n";
	} else {
		$failed++;
		$errors[] = $name;
		echo "  ✗ FAILED: {$name}\n";
	}
}

echo "=== Knowledge Export Tests ===\n\n";

// ── Test: Meta denylist filters internal prefixes ──────────────────────
echo "Meta Denylist Tests:\n";

// We can't test get_post_meta_filtered() directly without WP, but we can
// test the denylist prefix logic by reflection.
$reflection = new ReflectionClass( 'DEF_Core_Knowledge_Export' );
$prop = $reflection->getProperty( 'meta_denylist_prefixes' );
$prop->setAccessible( true );
$prefixes = $prop->getValue();

assert_test(
	in_array( '_wp_', $prefixes, true ),
	'Denylist includes _wp_ prefix'
);
assert_test(
	in_array( '_yoast_', $prefixes, true ),
	'Denylist includes _yoast_ prefix'
);
assert_test(
	in_array( '_rss_', $prefixes, true ),
	'Denylist includes _rss_ prefix'
);
assert_test(
	in_array( '_et_pb_', $prefixes, true ),
	'Denylist includes _et_pb_ prefix'
);
assert_test(
	in_array( '_aipkit_', $prefixes, true ),
	'Denylist includes _aipkit_ prefix'
);
assert_test(
	in_array( '_thumbnail_id', $prefixes, true ),
	'Denylist includes _thumbnail_id'
);
assert_test(
	! in_array( '_documentation_url', $prefixes, true ),
	'Denylist does NOT include _documentation_url (business-valuable meta)'
);
assert_test(
	! in_array( '_lic_product_version', $prefixes, true ),
	'Denylist does NOT include _lic_product_version (business-valuable meta)'
);

echo "\n";

// ── Test: Embedded document link extraction ────────────────────────────
echo "Embedded Document Link Tests:\n";

$html_with_pdf = '<p>Download the <a href="https://test.example.com/wp-content/uploads/brochure.pdf">brochure</a></p>';
$links = DEF_Core_Knowledge_Export::get_embedded_document_links( $html_with_pdf );
assert_test(
	count( $links ) === 1,
	'Finds PDF link in HTML'
);
assert_test(
	$links[0] === 'https://test.example.com/wp-content/uploads/brochure.pdf',
	'Extracts correct PDF URL'
);

$html_with_multiple = '<a href="https://test.example.com/spec.pdf">Spec</a> <a href="https://test.example.com/guide.docx">Guide</a>';
$links = DEF_Core_Knowledge_Export::get_embedded_document_links( $html_with_multiple );
assert_test(
	count( $links ) === 2,
	'Finds multiple document links'
);

$html_external = '<a href="https://evil.example.org/malware.pdf">Bad PDF</a>';
$links = DEF_Core_Knowledge_Export::get_embedded_document_links( $html_external );
assert_test(
	count( $links ) === 0,
	'Rejects external domain PDF links'
);

$html_with_images = '<img src="https://test.example.com/photo.jpg"> <a href="https://test.example.com/doc.pdf">Doc</a>';
$links = DEF_Core_Knowledge_Export::get_embedded_document_links( $html_with_images );
assert_test(
	count( $links ) === 1,
	'Ignores image URLs, finds only document links'
);

$links = DEF_Core_Knowledge_Export::get_embedded_document_links( '' );
assert_test(
	count( $links ) === 0,
	'Returns empty for empty content'
);

$html_subdomain = '<a href="https://docs.test.example.com/guide.pdf">Guide</a>';
$links = DEF_Core_Knowledge_Export::get_embedded_document_links( $html_subdomain );
assert_test(
	count( $links ) === 1,
	'Accepts subdomain document links'
);

$html_duplicate = '<a href="https://test.example.com/doc.pdf">Link 1</a> <a href="https://test.example.com/doc.pdf">Link 2</a>';
$links = DEF_Core_Knowledge_Export::get_embedded_document_links( $html_duplicate );
assert_test(
	count( $links ) === 1,
	'Deduplicates same URL'
);

echo "\n";

// ── Test: Exported post types exclude system types ─────────────────────
echo "Post Type Discovery Tests:\n";

$types = DEF_Core_Knowledge_Export::get_exported_post_types();
assert_test(
	in_array( 'post', $types, true ),
	'Includes post type'
);
assert_test(
	in_array( 'page', $types, true ),
	'Includes page type'
);
assert_test(
	in_array( 'product', $types, true ),
	'Includes product type'
);
assert_test(
	in_array( 'topic', $types, true ),
	'Includes topic type (bbPress)'
);
assert_test(
	in_array( 'a3_timeline', $types, true ),
	'Includes custom post type (a3_timeline)'
);
assert_test(
	! in_array( 'attachment', $types, true ),
	'Excludes attachment type'
);
assert_test(
	! in_array( 'nav_menu_item', $types, true ),
	'Excludes nav_menu_item type'
);
assert_test(
	! in_array( 'wp_block', $types, true ),
	'Excludes wp_block type'
);
assert_test(
	! in_array( 'wp_template', $types, true ),
	'Excludes wp_template type'
);
assert_test(
	! in_array( 'shop_order', $types, true ),
	'Excludes shop_order type'
);
assert_test(
	! in_array( 'revision', $types, true ),
	'Excludes revision type'
);
assert_test(
	! in_array( 'custom_css', $types, true ),
	'Excludes custom_css type'
);

echo "\n";

// ── Test: Exclusion-change tracking + /content/deleted excluded_ids ─────
echo "Exclusion Deindex Feed Tests:\n";

// track_exclusion_change appends and prunes entries older than 90 days.
_wp_test_reset_options();
DEF_Core_Knowledge_Export::track_exclusion_change( 500, 'product', true );
DEF_Core_Knowledge_Export::track_exclusion_change( 500, 'product', false );
$tracked = get_option( 'def_core_exclusion_changes', array() );
assert_test(
	count( $tracked ) === 2 && $tracked[0]['excluded'] === true && $tracked[1]['excluded'] === false,
	'track_exclusion_change records both directions in order'
);

// An entry older than 90 days is pruned on the next write.
update_option( 'def_core_exclusion_changes', array(
	array( 'id' => 999, 'type' => 'post', 'excluded' => true, 'time' => gmdate( 'c', strtotime( '-200 days' ) ) ),
) );
DEF_Core_Knowledge_Export::track_exclusion_change( 501, 'page', true );
$tracked = get_option( 'def_core_exclusion_changes', array() );
$ids     = array_map( static fn( $e ) => $e['id'], $tracked );
assert_test(
	! in_array( 999, $ids, true ) && in_array( 501, $ids, true ),
	'track_exclusion_change prunes entries older than 90 days'
);

// content_deleted reports NET-latest excluded items only (re-included drop out,
// pre-`since` entries excluded).
_wp_test_reset_options();
update_option( 'def_core_exclusion_changes', array(
	array( 'id' => 200, 'type' => 'product', 'excluded' => true,  'time' => '2026-05-01T00:00:00+00:00' ),
	array( 'id' => 100, 'type' => 'post',    'excluded' => true,  'time' => '2026-05-01T00:00:00+00:00' ),
	array( 'id' => 100, 'type' => 'post',    'excluded' => false, 'time' => '2026-05-02T00:00:00+00:00' ), // re-included → out
	array( 'id' => 300, 'type' => 'page',    'excluded' => false, 'time' => '2026-05-01T00:00:00+00:00' ),
	array( 'id' => 300, 'type' => 'page',    'excluded' => true,  'time' => '2026-05-03T00:00:00+00:00' ), // latest excluded → in
	array( 'id' => 400, 'type' => 'product', 'excluded' => true,  'time' => '2025-01-01T00:00:00+00:00' ), // before since → out
) );
$resp     = DEF_Core_Knowledge_Export::content_deleted( new WP_REST_Request( array( 'since' => '2026-01-01T00:00:00+00:00' ) ) );
$data     = $resp->get_data();
$excluded = $data['excluded_ids'] ?? array();
$ex_ids   = array_map( static fn( $e ) => $e['id'], $excluded );
sort( $ex_ids );
assert_test(
	array_key_exists( 'excluded_ids', $data ),
	'content_deleted response includes excluded_ids'
);
assert_test(
	$ex_ids === array( 200, 300 ),
	'excluded_ids = net-latest excluded only (re-included + pre-since dropped)'
);
$type_for_300 = '';
foreach ( $excluded as $e ) { if ( $e['id'] === 300 ) { $type_for_300 = $e['type']; } }
assert_test(
	$type_for_300 === 'page',
	'excluded_ids carries the post type (search index object_type)'
);

echo "\n";

// ── Summary ────────────────────────────────────────────────────────────
echo "───────────────────────────\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
if ( $failed > 0 ) {
	echo "\nFailed tests:\n";
	foreach ( $errors as $e ) {
		echo "  - {$e}\n";
	}
	exit( 1 );
}
echo "\nAll tests passed.\n";
exit( 0 );
