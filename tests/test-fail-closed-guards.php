<?php
/**
 * Fail-closed guard tests that need an ABSENT WordPress function.
 *
 * validate_logo_id()'s guard must REFUSE when wp_attachment_is_image() is
 * unavailable (fail closed). That branch cannot be exercised from
 * test-admin-api.php, which defines the stub — PHP functions cannot be
 * undefined — so this file runs the check in its own process where the
 * function was never defined. The old guard
 * (`function_exists( ... ) && ! wp_attachment_is_image( $id )`) skipped
 * validation entirely when the function was absent, accepting ANY integer
 * as a logo attachment ID.
 *
 * @package def-core/tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

require_once __DIR__ . '/../includes/class-def-core-admin-api.php';

$pass = 0;
$fail = 0;

function assert_test( $cond, string $label ): void {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "[PASS] $label\n";
	} else {
		$fail++;
		echo "[FAIL] $label\n";
	}
}

// Precondition — the whole point of this separate process.
assert_test(
	! function_exists( 'wp_attachment_is_image' ),
	'precondition: wp_attachment_is_image() is NOT defined in this process'
);

$api    = ( new ReflectionClass( 'DEF_Core_Admin_API' ) )->newInstanceWithoutConstructor();
$method = new ReflectionMethod( 'DEF_Core_Admin_API', 'validate_logo_id' );
$method->setAccessible( true );

// The inverted branch: cannot verify ⇒ refuse, not accept.
$result = $method->invoke( $api, 123 );
assert_test(
	'Logo could not be verified as an image.' === $result,
	'a nonzero ID is REFUSED when the image check is unavailable — unverifiable is not a pass'
);

// The branches that need no WP function still behave.
assert_test( true === $method->invoke( $api, 0 ), '0 still clears the logo (allowed without verification)' );
assert_test( is_string( $method->invoke( $api, -5 ) ), 'a negative ID is still refused' );

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
