<?php
/**
 * ZSCH_Health — a read-only answer to "is this thing actually running?"
 *
 * ── WHY THIS EXISTS ───────────────────────────────────────────────────────────
 *
 * Four handlers ride the five-minute `zsch_cron_sync` tick, and **every one of
 * them is silent in the normal case**: the Graph sweep when sync is unconfigured,
 * the backfill once it is done, the connected-calendar pull when its flag is
 * down. A perfectly healthy tick therefore looks exactly like a tick that never
 * fired.
 *
 * ── WHAT IT ANSWERS ───────────────────────────────────────────────────────────
 *
 * Which build is live and whether its schema agrees; whether the tick is really
 * firing; whether every column and guarantee-carrying index exists; what the
 * database's row format allows; whether the backfill finished (and the stored
 * durations still derive their ends — `ZSCH_Backfill::verify()`); and whether the
 * zone database still resolves time the way the recurrence engine relies on.
 * A page you can look at is one somebody actually looks at.
 *
 * ── DISCIPLINE ────────────────────────────────────────────────────────────────
 *
 * **Strictly read-only.** Nothing here writes, migrates, repairs or schedules. A
 * diagnostic that changes what it is measuring is worse than no diagnostic, and a
 * health page that "helpfully" re-runs a migration is how a look turns into an
 * outage. The one exception is the explicit verify button, and `verify()` is
 * itself read-only.
 *
 * The verdict functions are PURE — no database, no clock, no WordPress — so the
 * judgements a human will act on are covered by tests rather than by eyeballing
 * an admin screen once.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Health {

	/**
	 * How late the tick may be before it is called a fault.
	 *
	 * Three missed five-minute ticks. WP-Cron fires on page loads, so on a quiet
	 * site one or two skipped ticks is ordinary life, not a fault — and crying
	 * about it would make the panel noise people learn to ignore.
	 */
	const CRON_GRACE = 900;

	/** Columns added after the first schema, by table, so a half-applied migration is visible. */
	const EXPECTED = array(
		'zsch_appointments' => array( 'client_request_id', 'duration', 'uid', 'sequence', 'job_id', 'updated_by', 'last_modified', 'rrule', 'rdate', 'exdate', 'recurrence_id', 'series_uid', 'related_to', 'series_until_utc', 'override_key' ),
		'zsch_graph_map'    => array( 'pulled_body_hash' ),
	);

	/** Indexes that carry a guarantee rather than just speed. */
	const EXPECTED_INDEXES = array(
		/* EVERY index the schema declares, not a remembered subset. */
		'zsch_appointments' => array(
			'idx_zsch_uid', 'idx_zsch_idem', 'idx_zsch_override',
			'idx_zsch_series', 'idx_zsch_live_series', 'idx_zsch_job',
			'idx_owner_start', 'idx_scope_start', 'idx_deleted',
		),
	);

	/* ══ PURE VERDICTS ══════════════════════════════════════════════════ */

	/**
	 * The per-column index-prefix limit InnoDB enforces for a row format.
	 *
	 * That does not make `override_key char(32)` the wrong choice: 32 bytes
	 * beats 892 on index size, write cost and buffer-pool residency whatever
	 * the limit is. It makes the RECORDED REASON wrong, which is worse in a
	 * codebase that reasons from its own comments.
	 *
	 * @param  string $row_format DYNAMIC | COMPRESSED | COMPACT | REDUNDANT
	 * @return int    3072 or 767
	 */
	public static function index_limit_for( $row_format ) {
		$f = strtoupper( trim( (string) $row_format ) );
		return ( 'DYNAMIC' === $f || 'COMPRESSED' === $f ) ? 3072 : 767;
	}

	/**
	 * Turn the storage facts into a JUDGEMENT the operator can act on.
	 *
	 * PURE — every input is a parameter, so the whole thing is assertable as a
	 * property rather than as three examples.
	 *
	 * A surface that prints a row format and a number and stops leaves the reader
	 * to guess, so this says what the numbers MEAN.
	 *
	 * warn, not ok, when:
	 *   - the limit is the narrow 767 (a future composite key may be refused,
	 *     and it is worth knowing BEFORE writing the migration, not after);
	 *   - the table's row format could not be read at all — unknown is never ok;
	 *   - the table has drifted from the server default, which usually means it
	 *     predates a server upgrade and will behave unlike a freshly created one.
	 *
	 * @param  string $table_format  ROW_FORMAT of wp_zsch_appointments ('' = unknown)
	 * @param  string $server_default @@innodb_default_row_format
	 * @param  int    $limit          per-column index-prefix limit in bytes
	 * @return array{level:string,text:string}
	 */
	public static function storage_verdict( $table_format, $server_default, $limit ) {
		$tbl   = strtoupper( trim( (string) $table_format ) );
		$srv   = strtoupper( trim( (string) $server_default ) );
		$limit = (int) $limit;

		if ( '' === $tbl ) {
			return array(
				'level' => 'warn',
				'text'  => 'Cannot read the appointments table row format, so the '
					. $limit . '-byte index limit below is the server default, not this table\'s.',
			);
		}
		if ( 767 === $limit ) {
			return array(
				'level' => 'warn',
				'text'  => $tbl . ' row format: one indexed column may not exceed 767 bytes '
					. '(191 utf8mb4 characters). A whole index may still total 3072. '
					. ( $tbl !== $srv ? 'The server default is ' . $srv . ', so this table has drifted.' : '' ),
			);
		}
		if ( $tbl !== $srv ) {
			return array(
				'level' => 'warn',
				'text'  => $tbl . ' row format on a ' . $srv . ' server — this table has drifted from the '
					. 'default and will not behave like a freshly created one. Per-column index limit '
					. $limit . ' bytes.',
			);
		}
		/* At 3072 the per-column and whole-index limits coincide: say the
		 * number once, and say what it means. */
		return array(
			'level' => 'ok',
			'text'  => $tbl . ' row format: 3072 bytes for one indexed column and 3072 for a whole '
				. 'multi-column key — the two limits coincide here, so index width is not a constraint.',
		);
	}

	/**
	 * Render a signed second-count the way a person reads it.
	 *
	 * Positive is the future. PURE.
	 *
	 * @param int $seconds
	 * @return string
	 */
	public static function human_delta( $seconds ) {
		$seconds = (int) $seconds;
		if ( 0 === $seconds ) {
			return 'now';
		}
		$abs = abs( $seconds );
		if ( $abs < 60 )        { $n = $abs;                  $unit = 'second'; }
		elseif ( $abs < 3600 )  { $n = intdiv( $abs, 60 );    $unit = 'minute'; }
		elseif ( $abs < 86400 ) { $n = intdiv( $abs, 3600 );  $unit = 'hour';   }
		else                    { $n = intdiv( $abs, 86400 ); $unit = 'day';    }
		$label = $n . ' ' . $unit . ( 1 === $n ? '' : 's' );
		return ( $seconds > 0 ) ? ( 'in ' . $label ) : ( $label . ' ago' );
	}

	/**
	 * Is the five-minute tick alive? PURE.
	 *
	 * @param mixed $next           wp_next_scheduled() result: a timestamp or false.
	 * @param int   $now
	 * @param bool  $cron_disabled  DISABLE_WP_CRON — changes the ADVICE, not the verdict.
	 * @return array{level:string,text:string}
	 */
	public static function cron_verdict( $next, $now, $cron_disabled = false ) {
		if ( false === $next || null === $next || '' === $next ) {
			return array(
				'level' => 'bad',
				'text'  => 'NOT SCHEDULED. The five-minute tick is not registered at all, so nothing '
					. 'syncs, the write-back queue never drains and the backfill never runs. '
					. 'Deactivating and reactivating the plugin re-registers it.',
			);
		}
		$delta = (int) $next - (int) $now;

		if ( $delta < -self::CRON_GRACE ) {
			return array(
				'level' => 'bad',
				'text'  => sprintf(
					'OVERDUE. It was due %s and has not run. %s',
					self::human_delta( $delta ),
					$cron_disabled
						? 'WP-Cron is disabled here, so a real system cron must be calling wp-cron.php — check that it still is.'
						: 'WP-Cron only fires on a page load, so a site with no traffic simply stalls. A real system cron plus DISABLE_WP_CRON fixes that for good.'
				),
			);
		}
		if ( $delta < 0 ) {
			return array(
				'level' => 'warn',
				'text'  => sprintf( 'Due %s — inside the grace window, waiting on the next page load.', self::human_delta( $delta ) ),
			);
		}
		return array(
			'level' => 'ok',
			'text'  => sprintf( 'Scheduled. Next run %s.', self::human_delta( $delta ) ),
		);
	}

	/**
	 * Does the schema match the code? PURE.
	 *
	 * @param string $running ZSCH_VERSION
	 * @param string $db      the zsch_db_version option
	 * @return array{level:string,text:string}
	 */
	public static function version_verdict( $running, $db ) {
		$running = (string) $running;
		$db      = (string) $db;

		if ( '' === $db || '0' === $db ) {
			return array(
				'level' => 'bad',
				'text'  => 'No schema version has ever been recorded, which means the migration has not completed. Loading any admin page should run it.',
			);
		}
		if ( version_compare( $db, $running, '<' ) ) {
			return array(
				'level' => 'warn',
				'text'  => sprintf(
					'The code is %s but the schema is recorded at %s. The migration runs on the next page load — reload once and re-check.',
					$running, $db
				),
			);
		}
		if ( version_compare( $db, $running, '>' ) ) {
			/* Worth its own branch: this is the fingerprint of a rollback, and a
			 * rollback onto a newer schema is the case where "it looks fine" is
			 * least trustworthy — the columns a newer build added are still there. */
			return array(
				'level' => 'warn',
				'text'  => sprintf(
					'The schema is recorded at %s, AHEAD of the running code (%s). A newer build was installed and then rolled back; the extra columns are harmless but this build does not know about them.',
					$db, $running
				),
			);
		}
		return array(
			'level' => 'ok',
			'text'  => sprintf( 'Code and schema agree at %s.', $running ),
		);
	}

	/**
	 * Is the zone database still the one this engine was verified against? PURE.
	 *
	 * Three inputs, deliberately kept apart because they answer different questions:
	 *
	 *   $version   what PHP calls its zone database. On a managed host this is the
	 *              literal '0.system' forever, so it can confirm nothing — which is
	 *              exactly why the other two exist.
	 *   $recorded  the transition fingerprint last seen by the beacon.
	 *   $selftest  'pass' | 'fail' | 'n/a' from ZSCH_Tzdata::selftest().
	 *
	 * Note what is NOT a warning: running on OS tzdata. We cannot pin the extension
	 * here, so flagging it would leave this row amber permanently — and this panel's
	 * own header explains why a light that is always amber is a light nobody reads.
	 * Unpinned is reported in the detail line and watched by the fingerprint instead.
	 *
	 * @param string      $version
	 * @param string      $fingerprint  the fingerprint computed now.
	 * @param string      $recorded     the fingerprint previously recorded ('' = never).
	 * @param string      $selftest
	 * @param string|null $recorded_version the version previously recorded; null = do not compare.
	 * @return array{level:string,text:string}
	 */
	public static function tz_verdict( $version, $fingerprint, $recorded, $selftest, $recorded_version = null ) {
		$version     = (string) $version;
		$fingerprint = (string) $fingerprint;
		$recorded    = (string) $recorded;

		if ( 'fail' === $selftest ) {
			/* The loudest thing this panel can say, and it earns it: the engine
			 * resolves a DST gap by trusting PHP to apply the pre-gap offset. If that
			 * is no longer true, recurring appointments are landing an hour out and
			 * nothing else in the system would ever notice. */
			return array(
				'level' => 'bad',
				'text'  => 'The zone database no longer resolves DST the way the recurrence engine assumes. Recurring appointments across a transition cannot be trusted until this is understood — see the checks below.',
			);
		}

		if ( '' === $fingerprint ) {
			return array(
				'level' => 'bad',
				'text'  => 'No zone data could be read at all, so nothing here can be verified.',
			);
		}

		if ( '' === $recorded ) {
			return array(
				'level' => 'ok',
				'text'  => sprintf( 'First look. Zone rules fingerprinted at %s and recorded; a future change will be reported here and in the log.', $fingerprint ),
			);
		}

		if ( $recorded !== $fingerprint ) {
			return array(
				'level' => 'warn',
				'text'  => sprintf(
					'The zone rules changed underneath this install (%s → %s). The DST checks below still pass, so nothing is known to be wrong — but a recurring appointment booked before the change may now fall at a different instant, and recurring appointments that cross a clock change are worth checking.',
					$recorded, $fingerprint
				),
			);
		}

		/* the database moved and nothing we depend on did. Stays green on
		 * purpose; the sentence is the whole point of the branch.
		 *
		 * Three ways it can move, and calling them all "updated" would be wrong —
		 * found by rendering, where a container reporting '0.system' against a
		 * recorded '2026.3' produced "updated", which reads as
		 * a newer database when it means the pinned one is gone. */
		if ( null !== $recorded_version && '' !== (string) $recorded_version
			&& (string) $recorded_version !== $version && '' !== $version ) {
			$was = (string) $recorded_version;

			if ( '0.system' === $version ) {
				return array(
					'level' => 'ok',
					'text'  => sprintf(
						'The pinned zone database (%s) is no longer present — PHP is back on the operating system\'s copy, so there is no version left to compare. Nothing this install depends on moved: the fingerprint is unchanged at %s and DST still resolves the way the engine expects.',
						$was, $fingerprint
					),
				);
			}
			if ( '0.system' === $was ) {
				return array(
					'level' => 'ok',
					'text'  => sprintf(
						'The zone database is now pinned at %s (it was the operating system\'s copy before, which carried no version). Nothing this install depends on moved — the fingerprint is unchanged at %s.',
						$version, $fingerprint
					),
				);
			}
			return array(
				'level' => 'ok',
				'text'  => sprintf(
					'The zone database was updated (%s → %s) and none of the rules this install depends on moved — the fingerprint is unchanged at %s, and DST still resolves the way the engine expects.',
					$was, $version, $fingerprint
				),
			);
		}

		return array(
			'level' => 'ok',
			'text'  => sprintf(
				'Zone rules unchanged (%s) and DST resolves the way the engine expects.%s',
				$fingerprint,
				( '0.system' === $version )
					? ' The database is the operating system\'s, so it carries no version of its own — the fingerprint is what is being watched.'
					: ''
			),
		);
	}

	/**
	 * What is the backfill doing, and what did it refuse? PURE.
	 *
	 * @param bool $done
	 * @param int  $remaining  rows still missing a uid or a duration.
	 * @param int  $unfilled   rows carrying no duration at all.
	 * @return array{level:string,text:string}
	 */
	public static function backfill_verdict( $done, $remaining, $unfilled ) {
		$remaining = (int) $remaining;
		$unfilled  = (int) $unfilled;

		/* Still working: this is the only state where a pending count is real. */
		if ( ! $done && $remaining > 0 ) {
			return array(
				'level' => 'warn',
				'text'  => sprintf( 'Running. %d row(s) still to process, one batch per five-minute tick.', $remaining ),
			);
		}

		/* Nothing pending. Report refused rows HERE, before the done flag is
		 * consulted: checking `$done` first would report "nothing left to
		 * process" while rows carry no duration at all — hiding the exact number
		 * this panel exists to surface.
		 *
		 * Reachable: `remaining()` matches `duration = ''` while `unfilled()` also
		 * matches `duration IS NULL`. Today the column is NOT NULL DEFAULT '' so
		 * the two agree, but `unfilled()` was deliberately written not to depend on
		 * that, and this branch should not quietly re-introduce the dependency. */
		if ( $unfilled > 0 ) {
			return array(
				'level' => 'warn',
				'text'  => sprintf(
					'%s %d appointment(s) carry no duration. These were LEFT ALONE deliberately — '
					. 'an exact duration did not reproduce the end instant already stored on the row, and filling '
					. 'one anyway would have moved somebody\'s appointment. This is the safety gate working, not a fault.',
					$done ? 'Complete, but' : 'Nothing left to process, but',
					$unfilled
				),
			);
		}

		return array(
			'level' => 'ok',
			'text'  => $done
				? 'Complete. Every appointment carries a uid and a duration.'
				: 'Nothing left to process; it marks itself complete on the next tick.',
		);
	}

	/**
	 * The small print under the backfill verdict. PURE.
	 *
	 * Split out and tested so it can never contradict the verdict directly above
	 * it. `remaining()` counts rows still missing a uid or
	 * a duration — and a row the round-trip gate permanently refused keeps
	 * `duration = ''` forever, so it is counted by `remaining()` for the life of
	 * the install. Once the job is done those rows are not "still to process";
	 * they are the ones it decided never to touch, and the detail says so.
	 *
	 * @param bool   $done
	 * @param int    $remaining
	 * @param int    $unfilled
	 * @param string $at  timestamp of the last batch, or ''.
	 * @return string plain text; the caller escapes.
	 */
	public static function backfill_detail( $done, $remaining, $unfilled, $at = '' ) {
		$parts = array();

		if ( ! $done ) {
			$parts[] = sprintf( '%d row(s) still to process', (int) $remaining );
		}
		$parts[] = sprintf(
			'%d appointment(s) with no duration%s',
			(int) $unfilled,
			( $done && (int) $unfilled > 0 ) ? ' (permanently skipped)' : ''
		);
		if ( '' !== (string) $at ) {
			$parts[] = 'last batch ' . $at . ' UTC';
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Turn a missing-things list into a verdict. PURE.
	 *
	 * @param array $missing
	 * @param string $noun
	 * @return array{level:string,text:string}
	 */
	public static function presence_verdict( array $missing, $noun = 'column', $plural = '' ) {
		/* The plural is a parameter because `$noun . 's'` would render "indexs". */
		$plural = ( '' !== (string) $plural ) ? (string) $plural : ( $noun . 's' );

		if ( empty( $missing ) ) {
			return array( 'level' => 'ok', 'text' => sprintf( 'All expected %s present.', $plural ) );
		}
		return array(
			'level' => 'bad',
			'text'  => sprintf(
				'MISSING %s: %s. The migration did not finish; until it does, saving an appointment can fail outright.',
				( count( $missing ) > 1 ? $plural : $noun ),
				implode( ', ', array_map( 'strval', $missing ) )
			),
		);
	}

	/* ══ THE DATABASE SIDE (read-only) ══════════════════════════════════ */

	/** @return string */
	private static function t( $bare ) {
		global $wpdb;
		return $wpdb->prefix . $bare;
	}

	/** @return bool */
	public static function table_exists( $bare ) {
		global $wpdb;
		$t = self::t( $bare );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ); // phpcs:ignore
	}

	/** @return string[] lower-cased column names. */
	public static function columns( $bare ) {
		global $wpdb;
		$t    = self::t( $bare );
		$rows = $wpdb->get_col( "SHOW COLUMNS FROM {$t}" ); // phpcs:ignore
		return array_map( 'strtolower', (array) $rows );
	}

	/** @return string[] distinct index names. */
	public static function indexes( $bare ) {
		global $wpdb;
		$t    = self::t( $bare );
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$t}", ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $r ) {
			if ( ! empty( $r['Key_name'] ) ) {
				$out[ $r['Key_name'] ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Everything the panel shows, gathered once.
	 *
	 * @return array
	 */
	public static function gather() {
		global $wpdb;
		$now     = time();
		$running = defined( 'ZSCH_VERSION' ) ? ZSCH_VERSION : '';
		$db_ver  = (string) get_option( 'zsch_db_version', '' );

		$next_cron     = wp_next_scheduled( 'zsch_cron_sync' );
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		$out = array(
			'now'            => $now,
			'running'        => $running,
			'db_version'     => $db_ver,
			'version'        => self::version_verdict( $running, $db_ver ),
			'next_cron'      => $next_cron,
			'cron_disabled'  => $cron_disabled,
			'cron'           => self::cron_verdict( $next_cron, $now, $cron_disabled ),
			'tables'         => array(),
			'missing_cols'   => array(),
			'missing_idx'    => array(),
		);

		/* ── Zone database ──────────────────────────────────────
		 * All reads. The fingerprint is RECORDED by the beacon in the plugin
		 * bootstrap, never here — this panel is not allowed to write, and a
		 * diagnostic that quietly records what it just observed can never
		 * report that it changed. */
		if ( class_exists( 'ZSCH_Tzdata' ) ) {
			$tz_checks             = ZSCH_Tzdata::selftest();
			$out['tz_version']     = ZSCH_Tzdata::version();
			$out['tz_zones']       = ZSCH_Tzdata::zones();
			$out['tz_fingerprint'] = ZSCH_Tzdata::fingerprint( $out['tz_zones'] );
			$out['tz_recorded']     = (string) get_option( 'zsch_tzdata_fingerprint', '' );
			$out['tz_recorded_ver'] = (string) get_option( 'zsch_tzdata_version', '' );
			$out['tz_checks']       = $tz_checks;
			$out['tz_undeclared']   = ZSCH_Tzdata::undeclared_zones();
			$out['tz']              = self::tz_verdict(
				$out['tz_version'],
				$out['tz_fingerprint'],
				$out['tz_recorded'],
				ZSCH_Tzdata::selftest_status( $tz_checks ),
				$out['tz_recorded_ver']
			);
		} else {
			$out['tz'] = null;
		}

		foreach ( self::EXPECTED as $bare => $cols ) {
			$exists = self::table_exists( $bare );
			$out['tables'][ $bare ] = $exists;
			if ( ! $exists ) {
				$out['missing_cols'] = array_merge( $out['missing_cols'], array( $bare . ' (whole table)' ) );
				continue;
			}
			$have = self::columns( $bare );
			foreach ( $cols as $c ) {
				if ( ! in_array( strtolower( $c ), $have, true ) ) {
					$out['missing_cols'][] = $bare . '.' . $c;
				}
			}
		}
		foreach ( self::EXPECTED_INDEXES as $bare => $idx ) {
			if ( empty( $out['tables'][ $bare ] ) ) {
				continue;
			}
			$have = self::indexes( $bare );
			foreach ( $idx as $i ) {
				if ( ! in_array( $i, $have, true ) ) {
					$out['missing_idx'][] = $bare . '.' . $i;
				}
			}
		}
		$out['schema']  = self::presence_verdict( $out['missing_cols'], 'column' );
		$out['indexes'] = self::presence_verdict( $out['missing_idx'], 'index', 'indexes' );

		/* ── Storage engine facts ───────────────────────────────
		 * Reads only. The index limits a schema decision depends on are asked of
		 * this host on every load, rather than remembered. */
		$out['db_server']             = (string) $wpdb->db_version();
		$out['db_row_format_default'] = (string) $wpdb->get_var( 'SELECT @@innodb_default_row_format' );
		$out['db_row_format']         = '';
		if ( ! empty( $out['tables']['zsch_appointments'] ) ) {
			/* DATABASE(), not the DB_NAME constant: it asks the server which schema
			 * it is actually in, which is the same question with one fewer thing
			 * that can be undefined. A diagnostic must not die with the thing it
			 * is diagnosing — the same reason the backfill block below is guarded. */
			$out['db_row_format'] = (string) $wpdb->get_var( $wpdb->prepare(
				'SELECT ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$wpdb->prefix . 'zsch_appointments'
			) );
		}
		$out['db_index_limit'] = self::index_limit_for(
			'' !== $out['db_row_format'] ? $out['db_row_format'] : $out['db_row_format_default']
		);
		$out['storage'] = self::storage_verdict(
			$out['db_row_format'],
			$out['db_row_format_default'],
			$out['db_index_limit']
		);

		/* Backfill. Guarded because this panel must render even on an install where
		 * the class failed to load — a diagnostic that dies with the thing it is
		 * diagnosing is useless exactly when it is needed. */
		if ( class_exists( 'ZSCH_Backfill' ) && ! empty( $out['tables']['zsch_appointments'] ) ) {
			$done      = (bool) get_option( ZSCH_Backfill::DONE_OPTION );
			$remaining = ZSCH_Backfill::remaining();
			$unfilled  = ZSCH_Backfill::unfilled();
			$out['backfill'] = array(
				'done'      => $done,
				'remaining' => $remaining,
				'unfilled'  => $unfilled,
				'report'    => get_option( ZSCH_Backfill::REPORT_OPTION, array() ),
				'verdict'   => self::backfill_verdict( $done, $remaining, $unfilled ),
			);
		} else {
			$out['backfill'] = null;
		}

		return $out;
	}

	/* ══ RENDER ════════════════════════════════════════════════════════ */

	/** @return string a coloured chip. */
	private static function chip( $level ) {
		$map = array(
			'ok'   => array( '#ecfdf5', '#a7f3d0', '#065f46', '&#9989; OK' ),
			'warn' => array( '#fff7ed', '#fed7aa', '#9a3412', '&#9888;&#65039; CHECK' ),
			'bad'  => array( '#fef2f2', '#fecaca', '#991b1b', '&#9940; PROBLEM' ),
		);
		$c = isset( $map[ $level ] ) ? $map[ $level ] : $map['warn'];
		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:%s;border:1px solid %s;color:%s;font-weight:600;font-size:11px;white-space:nowrap">%s</span>',
			$c[0], $c[1], $c[2], $c[3]
		);
	}

	private static function row( $label, array $verdict, $detail = '' ) {
		printf(
			'<tr><th scope="row" style="width:190px;vertical-align:top;padding-top:12px">%s</th>'
			. '<td style="padding-top:12px">%s<div style="margin-top:4px">%s</div>%s</td></tr>',
			esc_html( $label ),
			self::chip( $verdict['level'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- chip() returns fixed markup chosen from its own map.
			esc_html( $verdict['text'] ),
			$detail ? '<div style="margin-top:4px;color:#666;font-size:12px">' . wp_kses_post( $detail ) . '</div>' : ''
		);
	}

	/**
	 * The panel. Called from the settings screen.
	 *
	 * @return void
	 */
	public static function render_panel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;   // belt and braces; the page is already gated
		}
		$h = self::gather();

		/* The header chip summarises the four INFRASTRUCTURE rows only — is the
		 * right build running, is the tick alive, is the schema whole. The backfill
		 * row is deliberately excluded: its steady state on a real install is
		 * "complete, with N rows permanently skipped", which is the safety gate
		 * working rather than a fault. Folding that into the header would pin it
		 * amber forever, and a status light that is always amber is a status light
		 * nobody reads. The backfill still reports its own state in its own row. */
		/* The zone row JOINS the rollup (the backfill does not).
		 * Its steady state is green — unpinned tzdata is reported in the detail
		 * line, not as a warning — so it cannot pin the header amber, and the one
		 * case it does fire on (DST no longer resolving the way the engine assumes)
		 * is precisely a thing somebody must see without opening the page. */
		$worst = 'ok';
		$rollup = array( $h['version'], $h['cron'], $h['schema'], $h['indexes'] );
		if ( ! empty( $h['tz'] ) ) {
			$rollup[] = $h['tz'];
		}
		foreach ( $rollup as $v ) {
			if ( 'bad' === $v['level'] ) { $worst = 'bad'; break; }
			if ( 'warn' === $v['level'] ) { $worst = 'warn'; }
		}
		?>
		<hr style="margin:28px 0 18px">
		<h2 style="margin-bottom:4px">Health <?php echo self::chip( $worst ); // phpcs:ignore ?></h2>
		<p class="description" style="max-width:740px;margin-top:0">
			Read-only. Nothing on this panel writes, repairs or schedules anything.
			It exists because every job on the five-minute tick is silent when it is idle,
			so a healthy system and a stopped one look identical in the log.
		</p>

		<table class="form-table" role="presentation" style="max-width:860px">
			<?php
			self::row( 'Running build', $h['version'],
				'Code <code>' . esc_html( $h['running'] ) . '</code> &middot; schema recorded at <code>'
				. esc_html( '' === $h['db_version'] ? '(none)' : $h['db_version'] ) . '</code>' );

			self::row( 'Five-minute tick', $h['cron'],
				$h['cron_disabled']
					? 'WP-Cron is <strong>disabled</strong> in wp-config &mdash; a system cron is expected to call <code>wp-cron.php</code>.'
					: 'WP-Cron is enabled, so ticks fire on page loads and inherit the host&rsquo;s ~60s request wall.' );

			self::row( 'Schema columns', $h['schema'] );
			self::row( 'Indexes', $h['indexes'],
				'Three of these carry a correctness guarantee rather than speed: '
				. '<code>idx_zsch_uid</code> stops one appointment acquiring two identities, '
				. '<code>idx_zsch_idem</code> makes a double-tapped save harmless, and '
				. '<code>idx_zsch_override</code> stops two people moving the same '
				. 'occurrence into two rows.' );

			/* the storage facts, read off THIS server.
			 * two releases because nobody asked the server. Now the page asks. */
			if ( ! empty( $h['storage'] ) ) {
				self::row( 'Storage engine', $h['storage'],
					'Server <code>' . esc_html( (string) $h['db_server'] ) . '</code>, default row format '
					. '<code>' . esc_html( (string) $h['db_row_format_default'] ) . '</code>. '
					. 'The 767-byte figure quoted in the migrations is a limit on ONE indexed column, '
					. 'not on a whole index &mdash; a multi-column key may total 3072 bytes whatever the '
					. 'row format.' );
			}

			if ( ! empty( $h['tz'] ) ) {
				/* Escape each zone, THEN join with markup: escaping the joined string
				 * would escape the separators too. */
				$tz_names = array();
				foreach ( $h['tz_zones'] as $tz_name ) {
					$tz_names[] = '<code>' . esc_html( $tz_name ) . '</code>';
				}
				/* Say WHICH zone database is loaded, not just what it calls itself. */
				$tz_detail = 'Watching ' . implode( ', ', $tz_names )
					. ' &middot; ' . ( ( '0.system' === $h['tz_version'] )
						? 'the operating system&rsquo;s zone database (no version of its own)'
						: 'PECL <code>timezonedb</code> pinned at <code>' . esc_html( $h['tz_version'] ) . '</code>' );
				if ( ! empty( $h['tz_recorded_ver'] ) && $h['tz_recorded_ver'] !== $h['tz_version'] ) {
					$tz_detail .= ' &middot; last recorded <code>' . esc_html( $h['tz_recorded_ver'] ) . '</code>';
				}
				foreach ( $h['tz_checks'] as $c ) {
					$mark = ( 'pass' === $c['status'] ) ? '&#10003;' : ( ( 'fail' === $c['status'] ) ? '&#10007;' : '&ndash;' );
					$tz_detail .= '<div style="margin-top:3px">' . $mark . ' ' . esc_html( $c['name'] )
						. '<br><span style="color:#888">' . esc_html( $c['detail'] ) . '</span></div>';
				}
				if ( ! empty( $h['tz_undeclared'] ) ) {
					/* Its own sentence on purpose. "An appointment uses a zone nobody
					 * declared" and "the zone database changed" are different problems,
					 * and a row that merges them answers neither. */
					$tz_detail .= '<div style="margin-top:6px;color:#9a3412">Appointments exist in '
						. esc_html( implode( ', ', $h['tz_undeclared'] ) )
						. ' &mdash; not in the declared list, so changes to those rules are not being watched. '
						. 'Add them via the <code>zsch_tzdata_zones</code> filter.</div>';
				}
				self::row( 'Zone database', $h['tz'], $tz_detail );
			}

			if ( $h['backfill'] ) {
				$b = $h['backfill'];
				self::row( 'Duration backfill', $b['verdict'], esc_html( self::backfill_detail(
					$b['done'], $b['remaining'], $b['unfilled'],
					isset( $b['report']['at'] ) ? (string) $b['report']['at'] : ''
				) ) );
			} else {
				self::row( 'Duration backfill',
					array( 'level' => 'warn', 'text' => 'Not available — the backfill class or the appointments table is not loaded.' ) );
			}
			?>
		</table>

		<?php
		// ── the on-demand audit ────────────────────────────────────────
		$verify_url = wp_nonce_url(
			admin_url( 'options-general.php?page=zsch-settings&zsch_verify=1' ),
			'zsch_verify'
		);
		$ran = isset( $_GET['zsch_verify'] ) && check_admin_referer( 'zsch_verify' ); // phpcs:ignore
		?>
		<p style="max-width:740px">
			<a href="<?php echo esc_url( $verify_url ); ?>" class="button">Audit stored durations</a>
			<span class="description" style="margin-left:8px">
				Re-derives every stored end instant from its duration and reports any that disagree.
				Read-only, and the number you want is <strong>0</strong>.
			</span>
		</p>
		<?php
		if ( $ran && class_exists( 'ZSCH_Backfill' ) ) {
			$v   = ZSCH_Backfill::verify();
			$bad = (int) $v['bad'];
			printf(
				'<div style="max-width:740px;padding:10px 14px;border-radius:8px;background:%s;border:1px solid %s">%s '
				. '<strong>%d checked, %d agree, %d disagree.</strong>%s</div>',
				$bad ? '#fef2f2' : '#ecfdf5',
				$bad ? '#fecaca' : '#a7f3d0',
				self::chip( $bad ? 'bad' : 'ok' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- chip() returns fixed markup chosen from its own map.
				(int) $v['checked'], (int) $v['ok'], (int) $bad,
				$bad
					? ' Appointment id(s): <code>' . esc_html( implode( ', ', array_map( 'intval', (array) $v['bad_ids'] ) ) ) . '</code>'
						. ' — each of these has a duration that no longer derives its stored end. Nothing has moved; the disagreement is the warning.'
					: ' Every stored duration reproduces the end instant on its row.'
			);
		}
	}
}
