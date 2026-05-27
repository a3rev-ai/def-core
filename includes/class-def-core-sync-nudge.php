<?php
/**
 * DEF Core — WordPress change → DEF sync "nudge" (event-driven push).
 *
 * Phase B of WP Auto-Sync. When a tenant adds / edits / deletes syncable
 * content, def-core pings the DEF backend so an incremental sync runs *now*
 * instead of waiting for the scheduled sweep (Phase A). The ping carries no
 * content — just the tenant signal (resolved server-side from the API key).
 *
 * Debounced + deduped: a burst of changes (e.g. a bulk edit of 50 products)
 * collapses to a SINGLE delayed ping via wp_schedule_single_event, not 50.
 * Best-effort by design — a lost ping is caught by the Phase A schedule and
 * the full-sync reconcile, so failures are logged and dropped (no retry).
 *
 * Reuses the existing outbound channel: server-side DEF URL
 * (DEF_Core::get_def_api_url_internal) + the per-tenant X-DEF-API-Key
 * (def_core_api_key) that Customer Chat / Staff-AI already use.
 *
 * @package DEF_Core
 * @since 3.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules and sends debounced incremental-sync nudges to DEF.
 */
class DEF_Core_Sync_Nudge {

	/**
	 * WP-Cron hook for the debounced ping.
	 */
	const CRON_HOOK = 'def_core_sync_nudge';

	/**
	 * Debounce window (seconds). A change schedules the ping this far out; any
	 * further change inside the window folds into the already-pending ping, so
	 * a bulk edit becomes one sync. ~30–60s keeps it near-instant while still
	 * coalescing a save storm.
	 */
	const DEBOUNCE_SECONDS = 45;

	/**
	 * Register change hooks + the debounced cron handler.
	 */
	public static function init(): void {
		// Content-change signals. Priority 20 runs after the export/exclusion
		// trackers (10–15) that record the change; we only read state here.
		add_action( 'save_post', array( __CLASS__, 'on_post_change' ), 20, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_change' ), 20, 1 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status_transition' ), 20, 3 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_post_change' ), 20, 1 );

		// The debounced worker that actually pings DEF.
		add_action( self::CRON_HOOK, array( __CLASS__, 'send_nudge' ) );
	}

	/**
	 * save_post / before_delete_post / woocommerce_update_product handler.
	 *
	 * @param int $post_id The post (or product) ID that changed.
	 */
	public static function on_post_change( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! self::is_syncable( (string) get_post_type( $post_id ) ) ) {
			return;
		}
		self::schedule_nudge();
	}

	/**
	 * transition_post_status handler — only public-status changes are relevant
	 * (publish/closed in, or out). Mirrors the export delete-tracker's filter.
	 *
	 * @param string $new_status New post status.
	 * @param string $old_status Old post status.
	 * @param mixed  $post       The WP_Post being transitioned.
	 */
	public static function on_status_transition( $new_status, $old_status, $post ): void {
		if ( ! ( $post instanceof WP_Post ) || $old_status === $new_status ) {
			return;
		}
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		if ( ! self::is_syncable( (string) $post->post_type ) ) {
			return;
		}
		$public = array( 'publish', 'closed' );
		if ( ! in_array( $old_status, $public, true ) && ! in_array( $new_status, $public, true ) ) {
			return;
		}
		self::schedule_nudge();
	}

	/**
	 * Is this post type one the knowledge sync exports? Reuses the export
	 * class's canonical list so the nudge fires for exactly the content a
	 * sync would touch (no duplicated post-type vocabulary).
	 *
	 * @param string $post_type Post type slug.
	 */
	private static function is_syncable( string $post_type ): bool {
		if ( '' === $post_type ) {
			return false;
		}
		if ( ! class_exists( 'DEF_Core_Knowledge_Export' ) ) {
			return false;
		}
		return in_array( $post_type, DEF_Core_Knowledge_Export::get_exported_post_types(), true );
	}

	/**
	 * Debounce: schedule a single ping DEBOUNCE_SECONDS out, unless one is
	 * already pending — that is the coalesce that turns a save storm into one
	 * sync. Pure (only WP-Cron state) so it is cheap and unit-testable; the
	 * connection check lives in send_nudge.
	 */
	public static function schedule_nudge(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return; // A ping is already queued — fold into it.
		}
		wp_schedule_single_event( time() + self::DEBOUNCE_SECONDS, self::CRON_HOOK );
	}

	/**
	 * Cron worker: ping DEF so it runs an incremental sync now. Best-effort —
	 * DEF coalesces nudges and the Phase A schedule is the backstop, so a
	 * failure is logged and dropped (no retry). No-ops when not connected.
	 */
	public static function send_nudge(): void {
		$api_key = DEF_Core_Encryption::get_secret( 'def_core_api_key' );
		if ( empty( $api_key ) ) {
			return; // Not connected — nothing to ping.
		}

		$url = DEF_Core::get_def_api_url_internal() . '/api/wp-sync/nudge';

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 15,
				'httpversion' => '1.1',
				'blocking'    => true,
				'headers'     => array(
					'X-DEF-API-Key' => $api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'        => wp_json_encode( array( 'source' => 'def-core' ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			DEF_Core_Logger::warning(
				DEF_Core_Logger::SOURCE_SYNC,
				'Sync nudge failed (transport)',
				array( 'error' => $response->get_error_message() )
			);
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			DEF_Core_Logger::warning(
				DEF_Core_Logger::SOURCE_SYNC,
				'Sync nudge rejected by DEF',
				array( 'status' => $code )
			);
			return;
		}

		DEF_Core_Logger::info(
			DEF_Core_Logger::SOURCE_SYNC,
			'Sync nudge sent',
			array( 'status' => $code )
		);
	}
}
