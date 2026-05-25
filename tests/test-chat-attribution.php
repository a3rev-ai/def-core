<?php
/**
 * Tests for DEF_Core_Chat_Attribution::sanitize_chat_id().
 *
 * The session/order hooks need a live WooCommerce + session and are verified
 * end-to-end on the integration stack; here we cover the input-sanitization
 * invariant (the security-relevant bit): the stamped id is always a short token
 * limited to [A-Za-z0-9_-], so it can't carry injection payloads into the
 * session / order meta / URL.
 */

require_once __DIR__ . '/wp-stubs.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $s ) );
	}
}

require_once __DIR__ . '/../includes/class-def-core-chat-attribution.php';

$passed = 0;
$failed = 0;
$errors = array();

function assert_test( bool $condition, string $name ): void {
	global $passed, $failed, $errors;
	if ( $condition ) {
		$passed++;
		echo "  \xE2\x9C\x93 {$name}\n";
	} else {
		$failed++;
		$errors[] = $name;
		echo "  \xE2\x9C\x97 FAILED: {$name}\n";
	}
}

echo "=== DEF_Core_Chat_Attribution::sanitize_chat_id ===\n\n";

$valid = DEF_Core_Chat_Attribution::sanitize_chat_id( 'orch-abc123_DEF' );
assert_test( 'orch-abc123_DEF' === $valid, 'preserves a valid thread id' );

// Safety invariant: output is always limited to the token charset.
$dirty_inputs = array(
	"orch <script>alert(1)</script>",
	"a'b\"c;d/e?f=g&h",
	"../../etc/passwd",
	"id with spaces",
	"emoji \xF0\x9F\x98\x80 here",
);
$all_clean = true;
foreach ( $dirty_inputs as $in ) {
	$out = DEF_Core_Chat_Attribution::sanitize_chat_id( $in );
	if ( ! preg_match( '/^[A-Za-z0-9_-]*$/', $out ) ) {
		$all_clean = false;
	}
}
assert_test( $all_clean, 'output is always limited to [A-Za-z0-9_-]' );

assert_test(
	100 === strlen( DEF_Core_Chat_Attribution::sanitize_chat_id( str_repeat( 'a', 250 ) ) ),
	'caps length at 100'
);
assert_test( '12345' === DEF_Core_Chat_Attribution::sanitize_chat_id( 12345 ), 'coerces a non-string' );
assert_test( '' === DEF_Core_Chat_Attribution::sanitize_chat_id( '' ), 'empty stays empty' );
assert_test( '' === DEF_Core_Chat_Attribution::sanitize_chat_id( null ), 'null becomes empty' );

echo "\n$passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
