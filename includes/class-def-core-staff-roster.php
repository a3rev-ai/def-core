<?php
/**
 * DEF Core — Staff-AI roster → DEF (D-U10 of the Usage & Budgets track).
 *
 * The source of truth for "who is a Staff-AI user" is the User Access grid: a
 * Staff or Management tick grants Staff-AI login. DEF's usage dashboard needs
 * that list so a tenant admin picks a person from a roster instead of typing a
 * numeric WordPress user id, and so users who have never sent a message still
 * appear (adoption visibility) — spend rows alone only ever show people who did.
 *
 * The push is FULL DESIRED STATE: DEF replaces the roster with what arrives, so
 * a revoked tick propagates without def-core having to send a removal event
 * nobody would retry. That is also why a lost push is harmless — the next save
 * or plugin upgrade re-sends the whole picture.
 *
 * Debounced through WP-Cron exactly like the sync nudge, for the same two
 * reasons: a save storm collapses to ONE push, and nothing on the admin's save
 * request waits on an outbound HTTP call. A failed push must never break the
 * User Access save.
 *
 * Data minimization (D-U10): id, name and access level cross — never emails.
 *
 * @package DEF_Core
 * @since 6.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and pushes the Staff-AI roster to DEF.
 */
class DEF_Core_Staff_Roster {

	/**
	 * WP-Cron hook for the debounced push.
	 */
	const CRON_HOOK = 'def_core_staff_roster_push';

	/**
	 * Debounce window (seconds). Saving the grid schedules the push this far
	 * out; further saves inside the window fold into the pending one. Shorter
	 * than the sync nudge's 45s — a roster change is a deliberate admin action
	 * whose result someone is waiting to see in the DEFHO dashboard.
	 */
	const DEBOUNCE_SECONDS = 30;

	/**
	 * Register the debounced cron handler.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'send_roster' ) );
	}

	/**
	 * Queue a push, folding into one already pending.
	 *
	 * Pure (only WP-Cron state), so the User Access save can call it without
	 * any chance of a network error reaching the admin's response. The
	 * connection check lives in send_roster().
	 */
	public static function schedule_push(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return; // A push is already queued — fold into it.
		}
		wp_schedule_single_event( time() + self::DEBOUNCE_SECONDS, self::CRON_HOOK );
	}

	/**
	 * Every user holding a Staff or Management tick, as DEF's wire shape.
	 *
	 * Reads the STORED capabilities via WP_User_Query, never has_cap(): the
	 * map_meta_cap filter in DEF_Core_Admin::map_def_capabilities() answers
	 * true for def_staff_access on any Management or DEF-Admin user, so
	 * has_cap() cannot tell a real Staff tick from an implied one. The
	 * capability query hits the stored wp_capabilities meta and is unaffected.
	 *
	 * Management wins when a user somehow holds both (the grid enforces
	 * exclusivity on save, but older data or a direct add_cap() need not have).
	 * DEF-Admin alone is NOT a roster row — it is an access grant, not a
	 * Staff-AI seat.
	 *
	 * @return array List of {sub, display_name, access_level} rows.
	 */
	public static function build_roster(): array {
		$levels = array(
			'management' => self::ids_with_cap( 'def_management_access' ),
			'staff'      => self::ids_with_cap( 'def_staff_access' ),
		);

		// Management first so a user in both sets keeps the higher level.
		$by_id = array();
		foreach ( $levels as $level => $ids ) {
			foreach ( $ids as $id ) {
				if ( ! isset( $by_id[ $id ] ) ) {
					$by_id[ $id ] = $level;
				}
			}
		}

		$users = array();
		foreach ( $by_id as $id => $level ) {
			$user = get_userdata( (int) $id );
			if ( ! $user ) {
				continue;
			}
			$row = array(
				// DEF's `sub` is a string; the WordPress id is numeric. Sending
				// the int would fail DEF's string check and refuse the whole roster.
				'sub'          => (string) (int) $id,
				'access_level' => $level,
			);
			// Omitted rather than sent empty — DEF stores null for "no name",
			// and "" would render as a blank row in the dashboard.
			$display_name = trim( (string) $user->display_name );
			if ( '' !== $display_name ) {
				// Truncated to DEF's 200-character bound, which it enforces
				// ALL-OR-NOTHING: one over-long name 422s the WHOLE roster and
				// DEF writes nothing. wp_users.display_name is varchar(250) and
				// an ordinary profile nickname reaches past 200, so without this
				// a single user with a long nickname would permanently kill
				// every push for the tenant. Of DEF's four row constraints this
				// is the only one def-core can violate.
				//
				// REPORTS rather than dropping silently (caps doctrine): the cut
				// is named, with the user it happened to, so an admin seeing a
				// clipped name in the dashboard can find and fix the source.
				if ( mb_strlen( $display_name ) > 200 ) {
					DEF_Core_Logger::warning(
						DEF_Core_Logger::SOURCE_SYNC,
						'Staff roster display name truncated to DEF\'s 200-character limit',
						array( 'user_id' => (int) $id, 'length' => mb_strlen( $display_name ) )
					);
					$display_name = mb_substr( $display_name, 0, 200 );
				}
				$row['display_name'] = $display_name;
			}
			$users[] = $row;
		}

		return $users;
	}

	/**
	 * Stored holders of one capability.
	 *
	 * @param string $cap Capability slug.
	 * @return array List of user ids.
	 */
	private static function ids_with_cap( string $cap ): array {
		return get_users( array(
			'capability' => $cap,
			'fields'     => 'ids',
		) );
	}

	/**
	 * Cron worker: POST the whole roster to DEF. Best-effort — a failure is
	 * logged and dropped (no retry), because the next User Access save or
	 * plugin upgrade re-pushes the full state. No-ops when not connected.
	 */
	public static function send_roster(): void {
		$api_key = DEF_Core_Encryption::get_secret( 'def_core_api_key' );
		if ( empty( $api_key ) ) {
			return; // Not connected — nothing to push.
		}

		$users = self::build_roster();
		$url   = DEF_Core::get_def_api_url_internal() . '/api/staff-ai/roster';

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
				'body'        => wp_json_encode( array( 'users' => $users ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			DEF_Core_Logger::warning(
				DEF_Core_Logger::SOURCE_SYNC,
				'Staff roster push failed (transport)',
				array( 'error' => $response->get_error_message() )
			);
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			// The status is the actionable half: 413 means the roster is over
			// DEF's 2000-user bound, 422 that a row was malformed. Both mean
			// DEF wrote NOTHING, so the count is logged alongside it.
			DEF_Core_Logger::warning(
				DEF_Core_Logger::SOURCE_SYNC,
				'Staff roster push rejected by DEF',
				array(
					'status' => $code,
					'users'  => count( $users ),
				)
			);
			return;
		}

		DEF_Core_Logger::info(
			DEF_Core_Logger::SOURCE_SYNC,
			'Staff roster pushed',
			array(
				'status' => $code,
				'users'  => count( $users ),
			)
		);
	}
}
