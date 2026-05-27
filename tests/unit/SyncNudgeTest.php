<?php
/**
 * Unit tests for DEF_Core_Sync_Nudge — the Phase B event-driven push.
 *
 * Focus: debounce/dedup (a save storm collapses to one ping) and the relevance
 * gates (revisions/autosaves/non-syncable types/non-public transitions do not
 * schedule). send_nudge() (HTTP) is exercised by the DEF-side endpoint tests.
 *
 * @package def-core/tests/unit
 * @covers DEF_Core_Sync_Nudge
 */

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers DEF_Core_Sync_Nudge
 */
final class SyncNudgeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Reset the stateful WP-Cron + post stubs (defined in bootstrap.php).
		$GLOBALS['_wp_test_cron']           = array();
		$GLOBALS['_wp_test_cron_scheduled'] = array();
		$GLOBALS['_wp_test_post_types']     = array();
		$GLOBALS['_wp_test_revisions']      = array();
		$GLOBALS['_wp_test_autosaves']      = array();
		$GLOBALS['_wp_test_exported_types'] = array( 'post', 'page', 'product' );
	}

	private function scheduled_count(): int {
		return count( $GLOBALS['_wp_test_cron_scheduled'] );
	}

	// ── Debounce / dedup ────────────────────────────────────────────────

	public function test_schedule_nudge_schedules_one_event(): void {
		DEF_Core_Sync_Nudge::schedule_nudge();
		$this->assertSame( 1, $this->scheduled_count() );
		$this->assertSame( DEF_Core_Sync_Nudge::CRON_HOOK, $GLOBALS['_wp_test_cron_scheduled'][0]['hook'] );
	}

	public function test_schedule_nudge_is_deduped_within_window(): void {
		// A burst of changes must collapse to a SINGLE pending ping.
		DEF_Core_Sync_Nudge::schedule_nudge();
		DEF_Core_Sync_Nudge::schedule_nudge();
		DEF_Core_Sync_Nudge::schedule_nudge();
		$this->assertSame( 1, $this->scheduled_count(), 'repeat schedule_nudge calls must dedup to one event' );
	}

	public function test_schedule_nudge_uses_debounce_delay(): void {
		$before = time();
		DEF_Core_Sync_Nudge::schedule_nudge();
		$ts = $GLOBALS['_wp_test_cron_scheduled'][0]['timestamp'];
		$this->assertGreaterThanOrEqual( $before + DEF_Core_Sync_Nudge::DEBOUNCE_SECONDS, $ts );
	}

	// ── on_post_change relevance gate ───────────────────────────────────

	public function test_on_post_change_schedules_for_syncable_type(): void {
		$GLOBALS['_wp_test_post_types'][5] = 'product';
		DEF_Core_Sync_Nudge::on_post_change( 5 );
		$this->assertSame( 1, $this->scheduled_count() );
	}

	public function test_on_post_change_skips_non_syncable_type(): void {
		$GLOBALS['_wp_test_post_types'][6] = 'nav_menu_item';
		DEF_Core_Sync_Nudge::on_post_change( 6 );
		$this->assertSame( 0, $this->scheduled_count() );
	}

	public function test_on_post_change_skips_revision(): void {
		$GLOBALS['_wp_test_post_types'][7] = 'product';
		$GLOBALS['_wp_test_revisions'][7]  = true;
		DEF_Core_Sync_Nudge::on_post_change( 7 );
		$this->assertSame( 0, $this->scheduled_count() );
	}

	public function test_on_post_change_skips_autosave(): void {
		$GLOBALS['_wp_test_post_types'][8] = 'product';
		$GLOBALS['_wp_test_autosaves'][8]  = true;
		DEF_Core_Sync_Nudge::on_post_change( 8 );
		$this->assertSame( 0, $this->scheduled_count() );
	}

	public function test_on_post_change_skips_invalid_id(): void {
		DEF_Core_Sync_Nudge::on_post_change( 0 );
		$this->assertSame( 0, $this->scheduled_count() );
	}

	// ── on_status_transition relevance gate ─────────────────────────────

	public function test_status_transition_schedules_on_publish(): void {
		$post = new WP_Post( array( 'ID' => 9, 'post_type' => 'post' ) );
		DEF_Core_Sync_Nudge::on_status_transition( 'publish', 'draft', $post );
		$this->assertSame( 1, $this->scheduled_count() );
	}

	public function test_status_transition_schedules_on_unpublish(): void {
		// publish → draft (leaving public) must still nudge so the delete lands.
		$post = new WP_Post( array( 'ID' => 10, 'post_type' => 'page' ) );
		DEF_Core_Sync_Nudge::on_status_transition( 'draft', 'publish', $post );
		$this->assertSame( 1, $this->scheduled_count() );
	}

	public function test_status_transition_skips_non_public(): void {
		// draft → pending: neither side is public — irrelevant to the index.
		$post = new WP_Post( array( 'ID' => 11, 'post_type' => 'post' ) );
		DEF_Core_Sync_Nudge::on_status_transition( 'pending', 'draft', $post );
		$this->assertSame( 0, $this->scheduled_count() );
	}

	public function test_status_transition_skips_no_change(): void {
		$post = new WP_Post( array( 'ID' => 12, 'post_type' => 'post' ) );
		DEF_Core_Sync_Nudge::on_status_transition( 'publish', 'publish', $post );
		$this->assertSame( 0, $this->scheduled_count() );
	}

	public function test_status_transition_skips_non_syncable_type(): void {
		$post = new WP_Post( array( 'ID' => 13, 'post_type' => 'nav_menu_item' ) );
		DEF_Core_Sync_Nudge::on_status_transition( 'publish', 'draft', $post );
		$this->assertSame( 0, $this->scheduled_count() );
	}
}
