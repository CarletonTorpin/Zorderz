<?php
/**
 * ZSCH_Backfill — give rows that predate identity and durations a `uid` and a
 * `duration`.
 *
 * ── THE ONE PROMISE ───────────────────────────────────────────────────────────
 *
 * **This never changes a start or an end instant.** It only ever writes columns
 * that were NULL or empty. A backfill that "improves" a stored value is a
 * backfill that changed data, and on a calendar that means somebody's appointment
 * moved without anybody asking.
 *
 * The duration it records is therefore EXACT (`PT…S`, via
 * ZSCH_Duration::between_exact), not nominal, even for all-day rows: exact is
 * the only form that reproduces an already-stored `end_utc` byte for byte,
 * whatever convention that row was written under. Every row is verified with
 * `round_trips()` BEFORE the write, and a row that does not round-trip is LEFT
 * ALONE and counted, never guessed at.
 *
 * What that costs: an existing multi-day or all-day row keeps an exact duration,
 * so it does not get the nominal-day behaviour (a three-day job keeping its wall
 * clock across a DST change). Rows created or edited from now on do.
 *
 * ── SHAPE ─────────────────────────────────────────────────────────────────────
 *
 * Bounded and resumable: a fixed batch per call, oldest rows first, driven from
 * the cron tick rather than a page load, so it cannot exceed a request time
 * limit however many rows exist. Idempotent — re-running processes only what is
 * still missing, so a half-finished run is simply resumed.
 *
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Backfill {

	/** Rows per call. Small enough that a batch is never the slow part of a tick. */
	const BATCH = 200;

	/** Option holding the last run's report, for the admin screen and for tests. */
	const REPORT_OPTION = 'zsch_backfill_report';

	/** Marks the backfill complete so the cron stops doing pointless work. */
	const DONE_OPTION = 'zsch_backfill_done';

	/** @return string appointments table. */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'zsch_appointments';
	}

	/**
	 * How many rows still need either column?
	 *
	 * Soft-deleted rows are included deliberately: they can still be restored, and
	 * a restored appointment with no UID is an appointment that will duplicate on
	 * the next sync.
	 *
	 * @return int
	 */
	public static function remaining() {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( // phpcs:ignore
			"SELECT COUNT(*) FROM {$t} WHERE uid IS NULL OR uid = '' OR duration = ''" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed table name; no values in the query.
		);
	}

	/**
	 * Process one batch.
	 *
	 * @param int $limit
	 * @return array { processed, uid_set, duration_set, skipped_no_roundtrip, remaining, done }
	 */
	public static function run( $limit = self::BATCH ) {
		global $wpdb;
		$t     = self::table();
		$limit = max( 1, min( 1000, (int) $limit ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed table name; the limit is a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT id, start_utc, end_utc, time_zone, uid, duration
			   FROM {$t}
			  WHERE uid IS NULL OR uid = '' OR duration = ''
			  ORDER BY id ASC
			  LIMIT %d",
			$limit
		), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$host      = ZSCH_Identity::uid_host();
		$processed = 0;
		$uid_set   = 0;
		$dur_set   = 0;
		$skipped   = 0;

		foreach ( (array) $rows as $r ) {
			$processed++;
			$update = array();

			// ── uid ──────────────────────────────────────────────────
			if ( empty( $r['uid'] ) ) {
				$update['uid'] = ZSCH_Identity::mint_uid( $host );
			}

			// ── duration ─────────────────────────────────────────────
			if ( '' === (string) $r['duration'] ) {
				$iso = ZSCH_Duration::between_exact( $r['start_utc'], $r['end_utc'] );
				$tz  = (string) $r['time_zone'];

				/* THE GATE. Only record a duration that derives back to the end
				 * instant already on this row. If it does not — a zero-length or
				 * inverted span, an unparseable zone — leave the row completely
				 * alone and count it. Guessing here is how a maintenance job moves
				 * somebody's appointment. */
				if ( '' !== $iso && ZSCH_Duration::round_trips( $r['start_utc'], $r['end_utc'], $iso, $tz ) ) {
					$update['duration'] = $iso;
				} else {
					$skipped++;
				}
			}

			if ( empty( $update ) ) {
				continue;
			}

			/* Note what is NOT in $update: start_utc, end_utc, updated_at. The row's
			 * timestamps are left untouched so a backfill does not look like an edit
			 * to anything watching updated_at — including the write-back engine,
			 * which would otherwise re-push every appointment in the database to
			 * everybody's real calendar. */
			$ok = $wpdb->update( $t, $update, array( 'id' => (int) $r['id'] ) ); // phpcs:ignore
			if ( false !== $ok ) {
				if ( isset( $update['uid'] ) )      { $uid_set++; }
				if ( isset( $update['duration'] ) ) { $dur_set++; }
			}
		}

		$remaining = self::remaining();

		/* "Done" means nothing is left that we are ABLE to fill. Rows that failed
		 * the round-trip gate keep `duration = ''` forever and would otherwise make
		 * the cron loop on them every five minutes for the life of the install. */
		$done = ( 0 === $remaining ) || ( 0 === $processed ) || ( $processed === $skipped && 0 === $uid_set );

		$report = array(
			'processed'            => $processed,
			'uid_set'              => $uid_set,
			'duration_set'         => $dur_set,
			'skipped_no_roundtrip' => $skipped,
			'remaining'            => $remaining,
			'done'                 => $done,
			'at'                   => gmdate( 'Y-m-d H:i:s' ),
		);
		update_option( self::REPORT_OPTION, $report, false );
		if ( $done ) {
			update_option( self::DONE_OPTION, 1, false );
		}

		/* SAY SO IN THE LOG, not only in an option: a maintenance job that
		 * eventually touches every appointment must be observable. The number
		 * that matters is
		 * `skipped_no_roundtrip`: rows this REFUSED to fill because the duration
		 * did not derive back to their stored end. That refusal is the safety
		 * feature — but a safety feature nobody can see is indistinguishable from
		 * one that never fired.
		 *
		 * Deliberately NOT gated on WP_DEBUG, unlike the fatal handler below. The
		 * output is bounded — a few lines once per install, then the DONE option
		 * stops the job forever — and the record of a data migration should not
		 * depend on a debug flag that was off the day somebody needs to ask what
		 * it did. */
		self::report_to_log( $report );

		return $report;
	}

	/**
	 * One line per batch that did work, plus a closing line that states the
	 * ground truth rather than a running total.
	 *
	 * The completion count comes from `unfilled()` — a fresh COUNT(*) — instead
	 * of accumulating `skipped` across batches. A number that is re-derived from
	 * the table cannot drift from it, and drift is exactly what would make this
	 * line worse than useless.
	 *
	 * @param array $r report from run().
	 * @return void
	 */
	private static function report_to_log( array $r ) {
		$unfilled = ! empty( $r['done'] ) ? self::unfilled() : 0;
		foreach ( self::report_lines( $r, $unfilled ) as $line ) {
			error_log( $line );
		}
	}

	/**
	 * The lines themselves. PURE — the unfilled count is passed in rather than
	 * queried here.
	 *
	 * Split out from the logging so the wording, the branching and the COMPLETE
	 * marker are covered by a test.
	 *
	 * @param array $r         report from run().
	 * @param int   $unfilled  rows still carrying no duration; only read when done.
	 * @return string[]
	 */
	public static function report_lines( array $r, $unfilled = 0 ) {
		if ( 0 === (int) ( $r['processed'] ?? 0 ) ) {
			return array();   // nothing happened; nothing to say
		}

		$lines = array( sprintf(
			'Zorderz Scheduler backfill: batch %d row(s) — uid+%d, duration+%d, skipped %d (no round-trip), %d remaining%s',
			(int) $r['processed'],
			(int) ( $r['uid_set'] ?? 0 ),
			(int) ( $r['duration_set'] ?? 0 ),
			(int) ( $r['skipped_no_roundtrip'] ?? 0 ),
			(int) ( $r['remaining'] ?? 0 ),
			! empty( $r['done'] ) ? ' — COMPLETE' : ''
		) );

		if ( empty( $r['done'] ) ) {
			return $lines;
		}

		$unfilled = (int) $unfilled;
		$lines[]  = ( $unfilled > 0 )
			? sprintf(
				'Zorderz Scheduler backfill: COMPLETE with %d appointment(s) still carrying no duration. '
				. 'These were LEFT ALONE on purpose — an exact duration did not reproduce the '
				. 'end instant already stored on the row, so filling one would have moved an '
				. 'appointment. Run ZSCH_Backfill::verify() for the rows that DID fill.',
				$unfilled
			)
			: 'Zorderz Scheduler backfill: COMPLETE — every appointment now has a uid and a duration.';

		return $lines;
	}

	/**
	 * How many rows still carry no duration at all?
	 *
	 * The complement of verify(), which only inspects rows that HAVE one. Between
	 * them every row in the table is accounted for.
	 *
	 * @return int
	 */
	public static function unfilled() {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( // phpcs:ignore
			"SELECT COUNT(*) FROM {$t} WHERE duration = '' OR duration IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed table name; no values in the query.
		);
	}

	/**
	 * Cron tick. Does nothing once complete.
	 */
	public static function cron_tick() {
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}
		if ( get_transient( 'zsch_backfill_running' ) ) {
			return;
		}
		set_transient( 'zsch_backfill_running', 1, 3 * MINUTE_IN_SECONDS );
		try {
			self::run();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'ZSCH_Backfill fatal: ' . $e->getMessage() );
			}
		} finally {
			delete_transient( 'zsch_backfill_running' );
		}
	}

	/**
	 * Read-only audit: does every backfilled row still derive its stored end?
	 *
	 * @param int $limit
	 * @return array { checked, ok, bad, bad_ids }
	 */
	public static function verify( $limit = 5000 ) {
		global $wpdb;
		$t    = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed table name; the limit is a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT id, start_utc, end_utc, time_zone, duration
			   FROM {$t}
			  WHERE duration <> ''
			  ORDER BY id ASC
			  LIMIT %d",
			max( 1, (int) $limit )
		), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$ok = 0; $bad = 0; $bad_ids = array();
		foreach ( (array) $rows as $r ) {
			if ( ZSCH_Duration::round_trips( $r['start_utc'], $r['end_utc'], $r['duration'], (string) $r['time_zone'] ) ) {
				$ok++;
			} else {
				$bad++;
				if ( count( $bad_ids ) < 50 ) {
					$bad_ids[] = (int) $r['id'];
				}
			}
		}
		return array(
			'checked' => count( (array) $rows ),
			'ok'      => $ok,
			'bad'     => $bad,
			'bad_ids' => $bad_ids,
		);
	}
}
