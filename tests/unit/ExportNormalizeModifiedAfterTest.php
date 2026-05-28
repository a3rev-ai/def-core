<?php
/**
 * Unit tests for DEF_Core_Export::normalize_modified_after_for_date_query().
 *
 * This is the workaround for WordPress's WP_Date_Query::build_mysql_datetime(),
 * which TZ-converts a string-with-offset into wp_timezone() before formatting.
 * For our use against `post_modified_gmt` (UTC), that conversion silently
 * misaligns the comparison by the site's UTC offset and the export endpoints
 * return 0 rows on any non-UTC site (observed live on a3rev, +07:00).
 *
 * The helper strips the explicit offset and emits canonical `Y-m-d H:i:s` in
 * UTC so WP's setTimezone() pass becomes a no-op on the literal string.
 *
 * @package def-core/tests/unit
 * @covers DEF_Core_Export::normalize_modified_after_for_date_query
 */

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

require_once DEF_CORE_PLUGIN_DIR . 'includes/class-def-core-export.php';

/**
 * @covers DEF_Core_Export
 */
final class ExportNormalizeModifiedAfterTest extends TestCase {

	private function call( string $raw ): string {
		return DEF_Core_Export::normalize_modified_after_for_date_query( $raw );
	}

	// ── Empty / falsy ────────────────────────────────────────────────────

	public function test_empty_string_returns_empty(): void {
		$this->assertSame( '', $this->call( '' ) );
	}

	// ── ISO 8601 with explicit timezone offset ──────────────────────────

	public function test_iso_utc_offset_stays_utc(): void {
		// Live a3rev case — DEFHO emits this exact shape.
		$this->assertSame(
			'2026-05-28 10:00:13',
			$this->call( '2026-05-28T10:00:13.592528+00:00' )
		);
	}

	public function test_iso_zulu_stays_utc(): void {
		$this->assertSame(
			'2026-05-28 10:00:13',
			$this->call( '2026-05-28T10:00:13Z' )
		);
	}

	public function test_iso_positive_offset_converts_to_utc(): void {
		// 10:00 Vietnam (+07:00) = 03:00 UTC.
		$this->assertSame(
			'2026-05-28 03:00:13',
			$this->call( '2026-05-28T10:00:13+07:00' )
		);
	}

	public function test_iso_negative_offset_converts_to_utc(): void {
		// 22:00 NY (-05:00) = 03:00 next-day UTC.
		$this->assertSame(
			'2026-05-29 03:00:13',
			$this->call( '2026-05-28T22:00:13-05:00' )
		);
	}

	// ── Bare MySQL DATETIME (no TZ): we explicitly default to UTC ────────

	public function test_bare_mysql_datetime_treated_as_utc(): void {
		// Caller's contract: a watermark with no TZ is already in UTC, so
		// passing it through unchanged is correct.
		$this->assertSame(
			'2026-05-28 10:00:13',
			$this->call( '2026-05-28 10:00:13' )
		);
	}

	// ── Microseconds preserved-then-truncated to seconds (MySQL DATETIME) ─

	public function test_microseconds_truncated_to_seconds(): void {
		// post_modified_gmt is a DATETIME (seconds precision); microseconds
		// in the input should not leak into the SQL literal.
		$this->assertSame(
			'2026-05-28 10:00:13',
			$this->call( '2026-05-28T10:00:13.999999+00:00' )
		);
	}

	// ── Malformed input: passthrough, don't crash ───────────────────────

	public function test_unparseable_string_passes_through(): void {
		$raw = 'not-a-real-datetime';
		$this->assertSame( $raw, $this->call( $raw ) );
	}

	// ── Idempotency: normalising an already-normalised value is stable ──

	public function test_idempotent(): void {
		$once  = $this->call( '2026-05-28T10:00:13.592528+00:00' );
		$twice = $this->call( $once );
		$this->assertSame( $once, $twice );
	}
}
