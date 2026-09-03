<?php
/**
 * Tests for DEF_Core_Partner_Attribution's pure helpers (6.5.0, S1–S3).
 *
 * The WP-glue (rewrite, cookie set, DEFHO HTTP) is exercised on the integration
 * stack; here we pin the security- and correctness-relevant invariants: slug
 * sanitization everywhere a slug enters (path, cookie, page URL), the cookie
 * round-trip, the AD-6 URL leg, the email-domain extraction that feeds the
 * registration rung, and the Attributed line the escalation recipient reads.
 */

require_once __DIR__ . '/wp-stubs.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

require_once __DIR__ . '/../includes/class-def-core-partner-attribution.php';

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

echo "sanitize_slug\n";
assert_test( 'acme-digital' === DEF_Core_Partner_Attribution::sanitize_slug( 'acme-digital' ), 'valid slug passes' );
assert_test( 'acme' === DEF_Core_Partner_Attribution::sanitize_slug( 'ACME' ), 'uppercase lowered' );
assert_test( 'acme' === DEF_Core_Partner_Attribution::sanitize_slug( '  acme  ' ), 'whitespace trimmed' );
assert_test( '' === DEF_Core_Partner_Attribution::sanitize_slug( 'acme_digital' ), 'underscore rejected' );
assert_test( '' === DEF_Core_Partner_Attribution::sanitize_slug( '../etc' ), 'traversal rejected' );
assert_test( '' === DEF_Core_Partner_Attribution::sanitize_slug( '<script>' ), 'markup rejected' );
assert_test( '' === DEF_Core_Partner_Attribution::sanitize_slug( str_repeat( 'a', 51 ) ), 'overlength rejected' );
assert_test( '' === DEF_Core_Partner_Attribution::sanitize_slug( '' ), 'empty rejected' );

echo "cookie round-trip\n";
$cookie = DEF_Core_Partner_Attribution::build_cookie_value( 'acme-digital', 1755500000 );
assert_test( 'acme-digital' === DEF_Core_Partner_Attribution::slug_from_cookie_value( $cookie ), 'round-trip returns slug' );
assert_test( '' === DEF_Core_Partner_Attribution::slug_from_cookie_value( 'not-json' ), 'malformed json -> empty' );
assert_test( '' === DEF_Core_Partner_Attribution::slug_from_cookie_value( '{"ts":1}' ), 'missing slug -> empty' );
assert_test( '' === DEF_Core_Partner_Attribution::slug_from_cookie_value( '{"slug":"<x>","ts":1}' ), 'tampered slug sanitized away' );

echo "extract_slug_from_page_url (AD-6 leg)\n";
assert_test( 'acme' === DEF_Core_Partner_Attribution::extract_slug_from_page_url( 'https://widrow.ai/p/acme' ), 'plain /p/ URL' );
assert_test( 'acme' === DEF_Core_Partner_Attribution::extract_slug_from_page_url( 'https://widrow.ai/p/acme/' ), 'trailing slash' );
assert_test( 'acme' === DEF_Core_Partner_Attribution::extract_slug_from_page_url( 'https://widrow.ai/p/ACME?utm=x' ), 'query string ignored, case lowered' );
assert_test( '' === DEF_Core_Partner_Attribution::extract_slug_from_page_url( 'https://widrow.ai/pricing' ), 'non-/p/ path -> empty' );
assert_test( '' === DEF_Core_Partner_Attribution::extract_slug_from_page_url( 'https://widrow.ai/p/bad_slug' ), 'invalid slug chars -> empty' );
assert_test( '' === DEF_Core_Partner_Attribution::extract_slug_from_page_url( '' ), 'empty URL -> empty' );
assert_test( '' === DEF_Core_Partner_Attribution::extract_slug_from_page_url( 'https://widrow.ai/blog/p/acme' ), 'non-root /p/ path -> empty (anchored)' );
assert_test( '' === DEF_Core_Partner_Attribution::extract_slug_from_page_url( 'https://widrow.ai/p/acme/deeper' ), 'deeper path -> empty' );

echo "email_domain_from (registration rung feed)\n";
assert_test( 'prospect.com' === DEF_Core_Partner_Attribution::email_domain_from( 'sue@prospect.com' ), 'plain email' );
assert_test( 'prospect.com' === DEF_Core_Partner_Attribution::email_domain_from( 'Sue@PROSPECT.COM' ), 'domain lowered' );
assert_test( '' === DEF_Core_Partner_Attribution::email_domain_from( 'not-an-email' ), 'invalid email -> empty' );
assert_test( '' === DEF_Core_Partner_Attribution::email_domain_from( '' ), 'empty -> empty' );

echo "build_attributed_line\n";
assert_test(
	'Attributed: house lead' === DEF_Core_Partner_Attribution::build_attributed_line( array( 'source' => 'house', 'partner_name' => '' ) ),
	'house lead line'
);
assert_test(
	'Attributed: Acme Digital (via partner link)' === DEF_Core_Partner_Attribution::build_attributed_line( array( 'source' => 'slug', 'partner_name' => 'Acme Digital' ) ),
	'slug source line'
);
assert_test(
	'Attributed: Acme Digital (via registered deal)' === DEF_Core_Partner_Attribution::build_attributed_line( array( 'source' => 'registration', 'partner_name' => 'Acme Digital' ) ),
	'registration source line'
);
assert_test(
	'Attributed: house lead' === DEF_Core_Partner_Attribution::build_attributed_line( array( 'source' => 'slug', 'partner_name' => '' ) ),
	'nameless partner falls back to house line'
);
assert_test(
	'Attributed: Acme Digital (via partner link)' === DEF_Core_Partner_Attribution::build_attributed_line( array( 'source' => 'slug', 'partner_name' => "Acme\nDigital" ) ),
	'multi-line partner name collapses to one line (no email-body line injection)'
);
assert_test(
	'Attributed: Acme Digital (via partner link)' === DEF_Core_Partner_Attribution::build_attributed_line( array( 'source' => 'weird-future-source', 'partner_name' => 'Acme Digital' ) ),
	'unknown source falls back to the partner-link wording'
);

echo "build_capture_payload (S5c contact fields)\n";
$p = DEF_Core_Partner_Attribution::build_capture_payload( 'esc-1', 'acme', 'Ada@Prospect.com', 'https://widrow.ai/p/acme', "  Ada \n Lovelace ", " Call me\nplease " );
assert_test( 'esc-1' === $p['lead_ref'] && 'acme' === $p['slug'], 'lead_ref + slug carried' );
assert_test( 'prospect.com' === $p['email_domain'] && 'ada@prospect.com' === $p['contact_email'], 'domain + the whole email (lowered)' );
assert_test( 'Ada Lovelace' === $p['contact_name'], 'name whitespace collapsed' );
assert_test( "Call me\nplease" === $p['message'], 'message trimmed, newlines kept' );
assert_test( 'https://widrow.ai/p/acme' === $p['page_url'], 'page_url carried' );
$q = DEF_Core_Partner_Attribution::build_capture_payload( 'esc-2', '', 'not-an-email', '', '', '' );
assert_test( array( 'lead_ref' => 'esc-2' ) === $q, 'empty/invalid inputs send nothing but lead_ref' );
$r = DEF_Core_Partner_Attribution::build_capture_payload( 'esc-3', '', '', '', str_repeat( 'n', 250 ), str_repeat( 'm', 1200 ) );
assert_test( 200 === strlen( $r['contact_name'] ) && 1000 === strlen( $r['message'] ), 'contact fields bounded to the capture schema (a 422 would lose the whole stamp)' );
$s = DEF_Core_Partner_Attribution::build_capture_payload( 'esc-4', '', 'ada@prospect.com', '', 'Ada', '', '  +61 400 000 000  ' );
assert_test( '+61 400 000 000' === $s['contact_phone'], 'phone trimmed and carried (7.6.1)' );
assert_test( ! isset( DEF_Core_Partner_Attribution::build_capture_payload( 'esc-5', '', '', '', '', '', '   ' )['contact_phone'] ), 'blank phone sends nothing' );
assert_test( 50 === strlen( DEF_Core_Partner_Attribution::build_capture_payload( 'esc-6', '', '', '', '', '', str_repeat( '9', 60 ) )['contact_phone'] ), 'phone bounded to the capture schema' );
$b = DEF_Core_Partner_Attribution::build_capture_payload( 'esc-7', '', 'ada@gmail.com', '', 'Ada', '', '', '  Harbour   Dental ', ' https://www.Harbour-Dental.com/ ' );
assert_test( 'Harbour Dental' === $b['company_name'] && 'https://www.Harbour-Dental.com/' === $b['website'], 'business name + website carried (7.6.1) — the registration key when the email is free-mail' );
assert_test( ! isset( DEF_Core_Partner_Attribution::build_capture_payload( 'esc-8', '', '', '', '', '', '', ' ', ' ' )['company_name'] ), 'blank business sends nothing' );
$b = DEF_Core_Partner_Attribution::build_capture_payload( 'esc-9', '', '', '', '', '', '', str_repeat( 'a', 250 ), str_repeat( 'b', 300 ) );
assert_test( 200 === strlen( $b['company_name'] ) && 255 === strlen( $b['website'] ), 'business + website bounded to the capture schema' );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) {
	echo 'FAILED: ' . implode( '; ', $errors ) . "\n";
	exit( 1 );
}
