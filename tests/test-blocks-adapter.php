<?php
/**
 * Content Agent — Adapter G (block-safe edit) pure-logic tests.
 *
 * Covers the security-critical, WordPress-independent helpers of DEF_Core_Blocks:
 * - path validation (rejects traversal / injection in node paths)
 * - innerHTML-surgical splice (wrapper + attributes preserved byte-for-byte)
 * - editable-inner extraction (single-wrapper only; custom markup → locked)
 * - link preservation (every source href must survive a patch)
 * - structural fingerprint (ignores editable text + image alt; catches structure drift)
 * - tree addressing (node_at / set_node) and named-block counting
 * - image alt read/splice
 *
 * The parse_blocks/serialize_blocks round-trip is validated by live smoke (it needs
 * a real WordPress with the site's block versions). Runs standalone with WP stubs.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

// ── Stubs used by the tested helpers ───────────────────────────────────

if ( ! function_exists( 'wp_kses' ) ) {
	// Test stub: keep only the inline whitelist (real wp_kses also filters attrs;
	// the helpers under test don't depend on that nuance).
	function wp_kses( $string, $allowed ) {
		return strip_tags( (string) $string, '<a><strong><em><b><i><br>' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES );
	}
}

require_once __DIR__ . '/../includes/class-def-core-blocks.php';

$passed = 0;
$failed = 0;
$errors = array();

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

$ref = new ReflectionClass( 'DEF_Core_Blocks' );
function call_priv( ReflectionClass $ref, string $method, array $args ) {
	$m = $ref->getMethod( $method );
	$m->setAccessible( true );
	return $m->invokeArgs( null, $args );
}

// ── path_indices: traversal / injection rejection ──────────────────────
assert_test( call_priv( $ref, 'path_indices', array( '5' ) ) === array( 5 ), 'path "5" → [5]' );
assert_test( call_priv( $ref, 'path_indices', array( '5.1' ) ) === array( 5, 1 ), 'path "5.1" → [5,1]' );
assert_test( null === call_priv( $ref, 'path_indices', array( '' ) ), 'empty path → null' );
assert_test( null === call_priv( $ref, 'path_indices', array( '5..1' ) ), 'path "5..1" → null' );
assert_test( null === call_priv( $ref, 'path_indices', array( '../1' ) ), 'path "../1" → null (traversal)' );
assert_test( null === call_priv( $ref, 'path_indices', array( '5/1' ) ), 'path "5/1" → null' );
assert_test( null === call_priv( $ref, 'path_indices', array( '5.x' ) ), 'path "5.x" → null' );

// ── splice_inner: wrapper + attributes preserved verbatim ──────────────
$s = call_priv( $ref, 'splice_inner', array( '<p class="x">old text</p>', 'new text' ) );
assert_test( '<p class="x">new text</p>' === $s, 'splice preserves <p> wrapper + class' );
$s = call_priv( $ref, 'splice_inner', array( '<h2 class="wp-block-heading has-text-align-center">A</h2>', '<strong>B</strong>' ) );
assert_test( '<h2 class="wp-block-heading has-text-align-center"><strong>B</strong></h2>' === $s, 'splice preserves <h2> attrs, allows inline' );
$s = call_priv( $ref, 'splice_inner', array( '<li class="a">x</li>', 'y' ) );
assert_test( '<li class="a">y</li>' === $s, 'splice preserves <li> wrapper' );
assert_test( null === call_priv( $ref, 'splice_inner', array( '<div class="custom">x</div>', 'y' ) ), 'splice refuses non-wrapper block (→ null)' );

// ── extract_inner: keeps inline links; locks custom markup ─────────────
$inner = call_priv( $ref, 'extract_inner', array( '<p class="c">Hi <a href="http://a3rev.com/shop/">shop</a> now</p>' ) );
assert_test( false !== strpos( (string) $inner, '<a href="http://a3rev.com/shop/">shop</a>' ), 'extract keeps inline <a href>' );
assert_test( null === call_priv( $ref, 'extract_inner', array( '<section class="wp-block-a3-blockpress-iconlist">...</section>' ) ), 'extract returns null for custom block (locks it)' );

// ── links_preserved ────────────────────────────────────────────────────
$src = '<p>a <a href="http://x/1">1</a> b <a href="http://x/2">2</a></p>';
assert_test( true === call_priv( $ref, 'links_preserved', array( $src, 'a <a href="http://x/1">1</a> b <a href="http://x/2">two</a>' ) ), 'links preserved when both hrefs survive (text may change)' );
assert_test( false === call_priv( $ref, 'links_preserved', array( $src, 'a 1 b <a href="http://x/2">2</a>' ) ), 'link loss detected (gate #5)' );
assert_test( true === call_priv( $ref, 'links_preserved', array( '<p>no links</p>', '<p>still none</p>' ) ), 'no-link node passes' );

// ── fingerprint: ignores editable text + image alt; catches structure ──
$para = function ( $html, $attrs = array() ) {
	return array( 'blockName' => 'core/paragraph', 'attrs' => $attrs, 'innerHTML' => $html, 'innerContent' => array( $html ), 'innerBlocks' => array() );
};
$tree_a = array( $para( '<p>first</p>' ), $para( '<p>second</p>' ) );
$tree_b = array( $para( '<p>FIRST CHANGED</p>' ), $para( '<p>second</p>' ) );
$tree_c = array( $para( '<p>first</p>', array( 'align' => 'center' ) ), $para( '<p>second</p>' ) );
$fp = function ( $t ) use ( $ref ) { return call_priv( $ref, 'fingerprint', array( $t ) ); };
assert_test( $fp( $tree_a ) === $fp( $tree_b ), 'fingerprint stable across editable text change' );
assert_test( $fp( $tree_a ) !== $fp( $tree_c ), 'fingerprint changes when a wrapper attr changes (structure)' );
$img = function ( $alt ) {
	return array( 'blockName' => 'core/image', 'attrs' => array( 'id' => 9, 'alt' => $alt ), 'innerHTML' => '<figure><img alt="' . $alt . '"/></figure>', 'innerContent' => array(), 'innerBlocks' => array() );
};
assert_test( $fp( array( $img( 'old alt' ) ) ) === $fp( array( $img( 'new alt' ) ) ), 'fingerprint ignores image alt (editable)' );

// ── node_at / set_node (nested addressing) ─────────────────────────────
$list = array(
	$para( '<p>intro</p>' ),
	array( 'blockName' => 'core/list', 'attrs' => array(), 'innerHTML' => '', 'innerContent' => array(), 'innerBlocks' => array(
		array( 'blockName' => 'core/list-item', 'attrs' => array(), 'innerHTML' => '<li>one</li>', 'innerContent' => array( '<li>one</li>' ), 'innerBlocks' => array() ),
		array( 'blockName' => 'core/list-item', 'attrs' => array(), 'innerHTML' => '<li>two</li>', 'innerContent' => array( '<li>two</li>' ), 'innerBlocks' => array() ),
	) ),
);
$got = call_priv( $ref, 'node_at', array( $list, '1.1' ) );
assert_test( is_array( $got ) && '<li>two</li>' === ( $got['innerHTML'] ?? '' ), 'node_at resolves nested path 1.1' );
assert_test( null === call_priv( $ref, 'node_at', array( $list, '9' ) ), 'node_at out-of-range → null' );
// set_node writes back through nesting
$m = $ref->getMethod( 'set_node' );
$m->setAccessible( true );
$copy = $list;
$newnode = array( 'blockName' => 'core/list-item', 'attrs' => array(), 'innerHTML' => '<li>TWO!</li>', 'innerContent' => array( '<li>TWO!</li>' ), 'innerBlocks' => array() );
$ok = $m->invokeArgs( null, array( &$copy, '1.1', $newnode ) );
assert_test( true === $ok && '<li>TWO!</li>' === $copy[1]['innerBlocks'][1]['innerHTML'], 'set_node replaces nested node' );

// ── count_named ignores whitespace (nameless) blocks ───────────────────
$mixed = array(
	$para( '<p>a</p>' ),
	array( 'blockName' => null, 'attrs' => array(), 'innerHTML' => "\n\n", 'innerContent' => array( "\n\n" ), 'innerBlocks' => array() ),
	$para( '<p>b</p>' ),
);
assert_test( 2 === call_priv( $ref, 'count_named', array( $mixed ) ), 'count_named ignores nameless whitespace blocks' );

// ── image_alt read + splice ────────────────────────────────────────────
$ib = array( 'blockName' => 'core/image', 'attrs' => array( 'id' => 9, 'alt' => 'old' ), 'innerHTML' => '<figure class="wp-block-image"><img src="x.jpg" alt="old" class="wp-image-9"/></figure>', 'innerContent' => array(), 'innerBlocks' => array() );
assert_test( 'old' === call_priv( $ref, 'image_alt', array( $ib ) ), 'image_alt reads attr' );
$spliced = call_priv( $ref, 'splice_image_alt', array( $ib, 'a better alt' ) );
assert_test( 'a better alt' === $spliced['attrs']['alt'] && false !== strpos( $spliced['innerHTML'], 'alt="a better alt"' ), 'splice_image_alt updates attr + <img> tag' );

// ── walk(): paths agree with node_at (raw indices), nesting, locking ────
function run_walk( ReflectionClass $ref, array $blocks ): array {
	$m = $ref->getMethod( 'walk' );
	$m->setAccessible( true );
	$nodes  = array();
	$locked = array();
	$args   = array( $blocks, '', &$nodes, &$locked ); // by-ref out params
	$m->invokeArgs( null, $args );
	return array( $nodes, $locked );
}
$mk_p  = function ( $t ) { return array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerHTML' => "<p>$t</p>", 'innerContent' => array( "<p>$t</p>" ), 'innerBlocks' => array() ); };
$mk_li = function ( $t ) { return array( 'blockName' => 'core/list-item', 'attrs' => array(), 'innerHTML' => "<li>$t</li>", 'innerContent' => array( "<li>$t</li>" ), 'innerBlocks' => array() ); };
$mk_ws = array( 'blockName' => null, 'attrs' => array(), 'innerHTML' => "\n\n", 'innerContent' => array( "\n\n" ), 'innerBlocks' => array() );
$paths_of = function ( array $nodes ) { return array_map( static function ( $n ) { return $n['path']; }, $nodes ); };

// Nameless whitespace block between two paragraphs: walk MUST emit raw indices
// (0 and 2), because node_at/set_node resolve by raw array position. This is the
// contract the whole addressing scheme rests on.
list( $nodes, $locked ) = run_walk( $ref, array( $mk_p( 'a' ), $mk_ws, $mk_p( 'b' ) ) );
assert_test( $paths_of( $nodes ) === array( '0', '2' ), 'walk emits raw indices across a nameless gap (0,2 — matches node_at)' );

// core/list → recurse to list-item leaves at 1.0 / 1.1.
$list = array( 'blockName' => 'core/list', 'attrs' => array(), 'innerHTML' => '', 'innerContent' => array(), 'innerBlocks' => array( $mk_li( 'one' ), $mk_li( 'two' ) ) );
list( $nodes, $locked ) = run_walk( $ref, array( $mk_p( 'intro' ), $list ) );
assert_test( $paths_of( $nodes ) === array( '0', '1.0', '1.1' ), 'walk recurses core/list → list-item paths 1.0, 1.1' );

// A list-item that itself has innerBlocks (nested sub-list) must be LOCKED, not
// exposed — editing it as one string would drop the sub-list.
$li_nested = array( 'blockName' => 'core/list-item', 'attrs' => array(), 'innerHTML' => '<li>parent</li>', 'innerContent' => array( '<li>parent', null, '</li>' ), 'innerBlocks' => array( $list ) );
$outer     = array( 'blockName' => 'core/list', 'attrs' => array(), 'innerHTML' => '', 'innerContent' => array(), 'innerBlocks' => array( $li_nested ) );
list( $nodes, $locked ) = run_walk( $ref, array( $outer ) );
assert_test( ! in_array( '0.0', $paths_of( $nodes ), true ), 'nested-list-item (has innerBlocks) is NOT exposed as editable' );
assert_test( 1 === count( array_filter( $locked, static function ( $l ) { return 0 === strpos( $l, '0.0 ' ); } ) ), 'nested-list-item is locked' );

// Custom block: locked, not exposed, NOT recursed into.
$cpt = array( 'blockName' => 'a3-blockpress/iconlist', 'attrs' => array( 'blockID' => 'x' ), 'innerHTML' => '<section>...</section>', 'innerContent' => array( '<section>...</section>' ), 'innerBlocks' => array() );
list( $nodes, $locked ) = run_walk( $ref, array( $mk_p( 'a' ), $cpt ) );
assert_test( $paths_of( $nodes ) === array( '0' ) && 1 === count( $locked ) && false !== strpos( $locked[0], 'a3-blockpress/iconlist' ), 'custom block locked, not exposed' );

// Image block exposes an alt node.
$imgb = array( 'blockName' => 'core/image', 'attrs' => array( 'id' => 9, 'alt' => 'cap' ), 'innerHTML' => '<figure><img alt="cap"/></figure>', 'innerContent' => array(), 'innerBlocks' => array() );
list( $nodes, $locked ) = run_walk( $ref, array( $imgb ) );
assert_test( 1 === count( $nodes ) && 'alt' === $nodes[0]['field'] && 'cap' === $nodes[0]['alt'], 'walk exposes image alt node' );

// Link-set equality (post-review): a NEW href must be rejected, not just loss.
assert_test( false === call_priv( $ref, 'links_preserved', array( '<p>plain text</p>', 'plain <a href="https://evil.com">x</a> text' ) ), 'links_preserved rejects an ADDED href (injection guard)' );

// ── Summary ────────────────────────────────────────────────────────────
echo "\n=== Summary ===\n";
echo "{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) {
	echo "\nFailures:\n";
	foreach ( $errors as $name ) {
		echo "  - {$name}\n";
	}
	exit( 1 );
}
exit( 0 );
