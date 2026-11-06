<?php
/**
 * ZSCH_Identity — stable event identity, revision counting, and the change log.
 *
 * Three jobs that belong together because they answer one question: **is this
 * inbound event the same event I already hold, and is it newer?**
 *
 * That is what makes out-of-order delivery safe — a webhook that arrives late, a
 * mail client replaying an invitation, two dispatchers saving within a second of
 * each other. Without it, a delayed old update silently reverts a newer one and
 * nothing anywhere records that it happened.
 *
 * ── WHY THIS LANDS BEFORE THE RECURRENCE ENGINE ───────────────────────────────
 *
 * Recurrence without stable identity produces duplicates on every sync. A series
 * edit means writing an override that keys on the master's UID; a "this and
 * following" split means minting a NEW UID and relating it back. Neither is
 * expressible until a UID exists and is guaranteed not to move.
 *
 * ── THE CHANGE LOG, AND WHY `source` IS THE POINT ─────────────────────────────
 *
 * Append-only, one row per FIELD that changed rather than one per save, so "when
 * did the start time move, and who moved it" is an indexed lookup instead of a
 * diff of two blobs.
 *
 * The column that earns it is `source`: it answers "did a person here move
 * this, or did a sync?". It is also the precondition for ever letting an
 * automated caller write to the calendar: a machine write that is attributed,
 * reviewable and reversible is defensible, and one that is not, is not.
 *
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Identity {

	/** Where a change came from. Anything not in this list is recorded as 'unknown'. */
	const SOURCES = array( 'ui', 'api', 'graph-sync', 'google-sync', 'orchestrator', 'backfill', 'system', 'unknown' );

	/** Who made it. 'system' covers cron and migrations; 'sync' is a provider pull. */
	const ACTOR_TYPES = array( 'user', 'system', 'sync', 'orchestrator' );

	/**
	 * The spec's own enumeration: DTSTART, DTEND, DURATION, DUE, RRULE, RDATE,
	 * EXDATE, STATUS. Mapped onto this schema's column names.
	 *
	 * Getting this list wrong fails in both directions and both are silent.
	 * Too broad — bumping for a typo in the notes — resets participation status
	 * in some clients. Too narrow — never bumping on a time change — makes Google
	 * and Outlook drop the update as a stale duplicate, so the appointment moves
	 * here and nowhere else.
	 */
	const SIGNIFICANT = array( 'start_utc', 'end_utc', 'duration', 'rrule', 'rdate', 'exdate', 'status' );

	/**
	 * Fields worth recording in the log even though they do NOT bump SEQUENCE.
	 *
	 * Kept separate from SIGNIFICANT on purpose: "what changed" and "is this a new
	 * revision for scheduling purposes" are different questions, and collapsing
	 * them is how the sequence list quietly grows until every edit is significant.
	 */
	const TRACKED = array( 'title', 'body', 'location', 'color', 'busy_status', 'calendar_scope', 'owner_user_id', 'is_all_day', 'time_zone', 'job_id', 'deleted_at' );

	/**
	 * Mint a UID that will never change again.
	 *
	 * PURE — the host is passed in rather than read from WordPress, so this is
	 * testable and so a CLI or cron context cannot silently mint a UID against a
	 * different host than the web context does.
	 */
	public static function mint_uid( $host, $entropy = '' ) {
		$host = preg_replace( '/[^a-z0-9.\-]/i', '', (string) $host );
		if ( '' === $host ) {
			$host = 'zorderz.invalid';
		}
		if ( '' === $entropy ) {
			try {
				$entropy = bin2hex( random_bytes( 16 ) );
			} catch ( Exception $e ) {
				// Never fall back to something guessable; fail loudly instead.
				$entropy = bin2hex( hash( 'sha256', uniqid( '', true ) . microtime( true ), true ) );
				$entropy = substr( $entropy, 0, 32 );
			}
		}
		return $entropy . '@' . $host;
	}

	/**
	 * The host to mint against. Thin WordPress wrapper over mint_uid().
	 *
	 * @return string
	 */
	public static function uid_host() {
		$host = function_exists( 'home_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : '';
		return ( '' !== $host ) ? $host : 'zorderz.invalid';
	}

	/**
	 * Is this a usable UID — ours OR somebody else's?
	 *
	 * So: any non-empty printable value that fits the column, with no whitespace
	 * or control characters. The things actually worth refusing.
	 *
	 * @param mixed $uid
	 * @return bool
	 */
	public static function is_valid_uid( $uid ) {
		if ( ! is_string( $uid ) || '' === $uid || strlen( $uid ) > 191 ) {
			return false;
		}
		// No whitespace, no control characters; printable ASCII otherwise.
		return 1 === preg_match( '/^[\x21-\x7E]+$/', $uid );
	}

	/**
	 * Which fields actually differ between two versions of a row?
	 *
	 * PURE. Compares as strings because that is how they arrive from wpdb and how
	 * they will be written back — comparing an int 0 against a string '0' and
	 * calling it a change is how a change log fills with noise nobody reads.
	 *
	 * @param array $before
	 * @param array $after   may be partial; only keys present here are compared.
	 * @param array $fields  which keys to consider.
	 * @return array<string,array{0:string,1:string}> field => [old, new]
	 */
	public static function diff( array $before, array $after, array $fields ) {
		$out = array();
		foreach ( $fields as $f ) {
			if ( ! array_key_exists( $f, $after ) ) {
				continue;   // not part of this write at all
			}
			$old = isset( $before[ $f ] ) ? (string) $before[ $f ] : '';
			$new = (string) $after[ $f ];
			if ( $old !== $new ) {
				$out[ $f ] = array( $old, $new );
			}
		}
		return $out;
	}

	/**
	 * Does this change warrant a SEQUENCE bump?
	 *
	 * PURE, and deliberately a separate decision from "did anything change" —
	 * see the note on SIGNIFICANT.
	 *
	 * @param array $before
	 * @param array $after
	 * @return bool
	 */
	public static function needs_sequence_bump( array $before, array $after ) {
		return ! empty( self::diff( $before, $after, self::SIGNIFICANT ) );
	}

	/**
	 * Does an inbound revision obsolete the one we hold?
	 *
	 * This one predicate is what makes out-of-order delivery safe — a webhook that
	 * arrives late, a mail client replaying an invitation, two dispatchers saving
	 * within a second of each other. Without it a delayed OLD update silently
	 * reverts a NEW one, and nothing anywhere records that it happened.
	 *
	 * An exact tie on both is NOT applied. There is nothing to gain from rewriting
	 * a row with a revision that claims no precedence over it, and something to
	 * lose: a duplicated delivery would churn `updated_at`, which the write-back
	 * engine watches, and re-push the event to everybody's calendar.
	 *
	 * PURE.
	 *
	 * @param int    $held_sequence
	 * @param string $held_dtstamp     'Y-m-d H:i:s' UTC, or ''.
	 * @param int    $inbound_sequence
	 * @param string $inbound_dtstamp  'Y-m-d H:i:s' UTC, or ''.
	 * @return array{apply:bool,reason:string}
	 */
	public static function wins( $held_sequence, $held_dtstamp, $inbound_sequence, $inbound_dtstamp ) {
		$held_seq = (int) $held_sequence;
		$in_seq   = (int) $inbound_sequence;

		if ( $in_seq > $held_seq ) {
			return array( 'apply' => true, 'reason' => 'higher_sequence' );
		}
		if ( $in_seq < $held_seq ) {
			/* The case the rule exists for: a late delivery of an older revision. */
			return array( 'apply' => false, 'reason' => 'stale_sequence' );
		}

		$held_ts = trim( (string) $held_dtstamp );
		$in_ts   = trim( (string) $inbound_dtstamp );

		/* Equal SEQUENCE, so DTSTAMP decides. A missing DTSTAMP cannot win a tie —
		 * treating "unknown" as "newer" is how a revision with no provenance
		 * overwrites one that has it. */
		if ( '' === $in_ts ) {
			return array( 'apply' => false, 'reason' => 'no_inbound_dtstamp' );
		}
		if ( '' === $held_ts ) {
			return array( 'apply' => true, 'reason' => 'held_has_no_dtstamp' );
		}
		$cmp = strcmp( $in_ts, $held_ts );
		if ( $cmp > 0 ) {
			return array( 'apply' => true, 'reason' => 'newer_dtstamp' );
		}
		if ( $cmp < 0 ) {
			return array( 'apply' => false, 'reason' => 'older_dtstamp' );
		}
		return array( 'apply' => false, 'reason' => 'identical_revision' );
	}

	/**
	 * Normalise a source label. Unknown values are recorded as 'unknown' rather
	 * than stored raw — a free-text source column stops being groupable within a
	 * month, and a log you cannot group is a log nobody queries.
	 *
	 * @param mixed $source
	 * @return string
	 */
	public static function normalize_source( $source ) {
		$source = is_string( $source ) ? strtolower( trim( $source ) ) : '';
		return in_array( $source, self::SOURCES, true ) ? $source : 'unknown';
	}

	/**
	 * Normalise an actor type.
	 *
	 * @param mixed $type
	 * @return string
	 */
	public static function normalize_actor_type( $type ) {
		$type = is_string( $type ) ? strtolower( trim( $type ) ) : '';
		return in_array( $type, self::ACTOR_TYPES, true ) ? $type : 'system';
	}

	/** @return string the change-log table name. */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'zsch_change_log';
	}

	/**
	 * Write one row per changed field.
	 *
	 * Never throws and never blocks the write it is describing: a logging failure
	 * must not cost somebody their appointment. Values are truncated rather than
	 * refused, because a log row that exists and is clipped is worth more than one
	 * that silently did not happen.
	 *
	 * @param int    $appointment_id
	 * @param string $uid
	 * @param array  $changes        field => [old, new], from diff()
	 * @param array  $meta           { actor_id, actor_type, source, sequence_before, sequence_after }
	 * @return int rows written.
	 */
	public static function log( $appointment_id, $uid, array $changes, array $meta = array() ) {
		if ( empty( $changes ) ) {
			return 0;
		}
		global $wpdb;
		$table = self::log_table();

		$row_base = array(
			'appointment_id'  => (int) $appointment_id,
			'uid'             => substr( (string) $uid, 0, 191 ),
			'actor_id'        => (int) ( $meta['actor_id'] ?? 0 ),
			'actor_type'      => self::normalize_actor_type( $meta['actor_type'] ?? '' ),
			'source'          => self::normalize_source( $meta['source'] ?? '' ),
			'sequence_before' => (int) ( $meta['sequence_before'] ?? 0 ),
			'sequence_after'  => (int) ( $meta['sequence_after'] ?? 0 ),
			'created_at'      => function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ),
		);

		$written = 0;
		foreach ( $changes as $field => $pair ) {
			$ok = $wpdb->insert( $table, array_merge( $row_base, array( // phpcs:ignore
				'field'     => substr( (string) $field, 0, 64 ),
				'old_value' => self::clip( $pair[0] ),
				'new_value' => self::clip( $pair[1] ),
			) ) );
			if ( $ok ) {
				$written++;
			}
		}
		return $written;
	}

	/**
	 * Keep a logged value to a sane length.
	 *
	 * A body can be thousands of characters and the log is not a backup. The head
	 * is kept because that is where a human recognises what changed.
	 *
	 * @param mixed $v
	 * @return string
	 */
	public static function clip( $v ) {
		$v = (string) $v;
		return ( strlen( $v ) > 500 ) ? ( substr( $v, 0, 497 ) . '...' ) : $v;
	}
}
