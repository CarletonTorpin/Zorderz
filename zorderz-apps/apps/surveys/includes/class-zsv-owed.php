<?php
/**
 * Zorderz Surveys — CRM work this app owes, kept until it is done.
 *
 * Two kinds of debt survive a request that ran out of time or met a sick CRM:
 *
 * OWED NOTES. Three things this app writes come with an explanatory CRM note: an e-mail sent,
 * an auto-close, a review recorded. The WRITE takes the lead out of the set the next run looks
 * at (e-mailed, Won, review_left = 1), so a note not posted in the same run would never be
 * posted at all. So the note is DURABLE: stored before it is posted, removed only once it has
 * posted, and posted first thing by the next sweep.
 *
 *   owed     not posted, and definitely not (no attempt yet, or the CRM refused it);
 *   posting  claimed by a worker posting it right now (younger than IN_FLIGHT_TTL);
 *   maybe    a post was attempted and its outcome is unknown (the Core CRM client answers null
 *            on a transport or API error). It may have landed, so it is NEVER posted again
 *            before the lead's notes are read and its `Ref:` line is not among them.
 *
 * Every note carries `Ref: zsv-<12 hex>`. A claim older than IN_FLIGHT_TTL means its worker
 * died: it is treated as `maybe`. After BACKOFF_AFTER attempts an entry is retried once a day,
 * logged each time. The store is capped: beyond MAX_ENTRIES, or older than MAX_AGE_DAYS, an
 * entry moves to a small capped record of notes given up on, with a loud log line. Never silent.
 *
 * OWED WON MARKS. After a review e-mail the lead is marked Won. When that mark fails the e-mail
 * has still gone, so the close is remembered here and the daily sweep makes it — only while the
 * CRM still reads the lead OPEN (a lead a person has since closed or reopened by hand is never
 * touched again), and after the grace-window closes of that sweep. An owed mark older than
 * EMAILED_LOOKBACK_DAYS is dropped, loudly.
 *
 * Storage: three non-autoloaded options, every read-modify-write under one MySQL named lock
 * (released by the database if a worker dies), never held across a network call.
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_Owed {

	const OPTION          = 'zsv_owed_notes';
	const GAVE_UP_OPTION  = 'zsv_owed_notes_gave_up';
	const WON_OPTION      = 'zsv_won_owed';
	const BACKOFF_AFTER   = 3;
	const BACKOFF_SECONDS = 86400;
	const IN_FLIGHT_TTL   = 900;
	const MAYBE_SETTLE    = 120;
	const MAX_ENTRIES     = 500;
	const MAX_AGE_DAYS    = 60;
	const GAVE_UP_KEEP    = 50;
	const EMAILED_LOOKBACK_DAYS = 30;
	const LOCK_WAIT       = 3;

	/** Injected in tests: "now" as a unix time. */
	public static $now = null;

	private static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}

	/* ── lock + storage ─────────────────────────────────────────── */

	private static function lock_name(): string {
		global $wpdb;
		return 'zsv_owed_' . substr( md5( ( $wpdb ? $wpdb->prefix : '' ) . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) ), 0, 16 );
	}

	/**
	 * Run $fn under the store's lock. Returns [ got_lock, result ]; without the lock $fn is not
	 * run (a write whose note could not be stored is not made by the caller).
	 */
	private static function locked( callable $fn, int $wait = self::LOCK_WAIT ): array {
		global $wpdb;
		$got = $wpdb ? '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name(), $wait ) ) : true;
		if ( ! $got ) {
			return array( false, null );
		}
		try {
			return array( true, $fn() );
		} finally {
			if ( $wpdb ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
			}
		}
	}

	/** The option as stored now (the per-request option cache is dropped first). */
	private static function read( string $opt ): array {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $opt, 'options' );
		}
		$v = get_option( $opt, array() );
		return is_array( $v ) ? $v : array();
	}

	private static function write( string $opt, array $v ): void {
		update_option( $opt, $v, false );
	}

	/* ── notes ──────────────────────────────────────────────────── */

	/** A new reference for one note. */
	public static function new_ref(): string {
		return 'zsv-' . substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
	}

	/** The note body with its reference line. */
	public static function with_ref( string $body, string $ref ): string {
		return rtrim( $body ) . "\nRef: " . $ref;
	}

	/**
	 * Store a note as owed BEFORE it is posted. Returns false when the store could not be
	 * written (its lock was busy); the caller then makes one plain attempt.
	 */
	public static function owe( string $kind, int $crm_lead_id, int $wp_lead_id, string $body, string $ref ): bool {
		if ( $crm_lead_id < 1 || '' === $ref ) {
			return false;
		}
		list( $got ) = self::locked( function () use ( $kind, $crm_lead_id, $wp_lead_id, $body, $ref ) {
			$all          = self::read( self::OPTION );
			$all[ $ref ]  = array(
				'kind'  => sanitize_key( $kind ),
				'crm'   => $crm_lead_id,
				'wp'    => $wp_lead_id,
				'body'  => $body,
				'state' => 'owed',
				'tries' => 0,
				'at'    => self::now(),
				'next'  => 0,
				'claim' => 0,
			);
			self::write( self::OPTION, self::cap( $all ) );
		} );
		return $got;
	}

	/** Every owed note, oldest first (for the screen and the tests). */
	public static function notes(): array {
		$all = self::read( self::OPTION );
		uasort( $all, function ( $a, $b ) { return (int) $a['at'] <=> (int) $b['at']; } );
		return $all;
	}

	public static function count(): int {
		return count( self::read( self::OPTION ) );
	}

	/**
	 * Post one stored note. $poster( array $entry ) posts it and answers truthy on success; a
	 * falsy answer or an exception leaves the note `maybe` (it may have landed). Answers
	 * 'posted' | 'maybe' | 'busy' (claimed elsewhere, or the lock was busy) | 'gone'.
	 */
	public static function post( string $ref, callable $poster ): string {
		list( $got, $entry ) = self::locked( function () use ( $ref ) {
			$all = self::read( self::OPTION );
			if ( ! isset( $all[ $ref ] ) ) {
				return 'gone';
			}
			$e = $all[ $ref ];
			if ( 'posting' === $e['state'] && self::now() - (int) $e['claim'] < self::IN_FLIGHT_TTL ) {
				return 'busy';
			}
			$all[ $ref ]['state'] = 'posting';
			$all[ $ref ]['claim'] = self::now();
			self::write( self::OPTION, $all );
			return $all[ $ref ];
		} );
		if ( ! $got ) {
			return 'busy';
		}
		if ( ! is_array( $entry ) ) {
			return (string) $entry;
		}
		$ok = false;
		try {
			$ok = (bool) $poster( $entry );
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		self::locked( function () use ( $ref, $ok ) {
			$all = self::read( self::OPTION );
			if ( ! isset( $all[ $ref ] ) ) {
				return;
			}
			if ( $ok ) {
				unset( $all[ $ref ] );
			} else {
				$all[ $ref ]['state'] = 'maybe';
				$all[ $ref ]['tries'] = (int) $all[ $ref ]['tries'] + 1;
				$all[ $ref ]['claim'] = self::now();
				$all[ $ref ]['next']  = (int) $all[ $ref ]['tries'] >= self::BACKOFF_AFTER ? self::now() + self::BACKOFF_SECONDS : 0;
			}
			self::write( self::OPTION, $all );
		} );
		return $ok ? 'posted' : 'maybe';
	}

	/** The note is already on the lead (its Ref was found): forget it. */
	public static function forget( string $ref ): void {
		self::locked( function () use ( $ref ) {
			$all = self::read( self::OPTION );
			unset( $all[ $ref ] );
			self::write( self::OPTION, $all );
		} );
	}

	/**
	 * Post what is owed, oldest first, while the budget lets an item start.
	 *
	 * @param callable $poster  ( array $entry ): bool — posts one note.
	 * @param callable $is_on   ( array $entry ): ?bool — is a note with this Ref already on the lead?
	 *                          true / false, or null when the lead could not be read.
	 * @return array{posted:int,already:int,maybe:int,waiting:int,left:int}
	 */
	public static function drain( callable $poster, callable $is_on ): array {
		$out = array( 'posted' => 0, 'already' => 0, 'maybe' => 0, 'waiting' => 0, 'left' => 0 );
		$now = self::now();
		foreach ( self::notes() as $ref => $e ) {
			if ( ! ZSV_Budget::can_start( 2 ) ) {
				$out['left']++;
				continue;
			}
			if ( (int) ( $e['next'] ?? 0 ) > $now ) {
				$out['waiting']++;
				continue;
			}
			$state = (string) $e['state'];
			if ( 'posting' === $state && $now - (int) $e['claim'] < self::IN_FLIGHT_TTL ) {
				$out['waiting']++; // someone else's live post
				continue;
			}
			if ( 'maybe' === $state || 'posting' === $state ) {
				if ( $now - (int) $e['claim'] < self::MAYBE_SETTLE ) {
					$out['waiting']++;
					continue;
				}
				$on = $is_on( $e );
				if ( true === $on ) {
					self::forget( (string) $ref );
					$out['already']++;
					continue;
				}
				if ( null === $on ) {
					$out['waiting']++; // the lead could not be read: never a blind second post
					continue;
				}
			}
			$r = self::post( (string) $ref, $poster );
			if ( 'posted' === $r ) {
				$out['posted']++;
			} elseif ( 'maybe' === $r ) {
				$out['maybe']++;
				$tries = (int) ( $e['tries'] ?? 0 ) + 1;
				if ( $tries >= self::BACKOFF_AFTER ) {
					error_log( sprintf( 'Zorderz Surveys: a %s note for CRM lead #%d failed %d times — retried once a day, kept until it posts.', (string) $e['kind'], (int) $e['crm'], $tries ) );
				}
			} else {
				$out['waiting']++;
			}
		}
		return $out;
	}

	/** Keep the store bounded: too many or too old entries move to the given-up record, loudly. */
	private static function cap( array $all ): array {
		$now   = self::now();
		$gone  = array();
		foreach ( $all as $ref => $e ) {
			if ( $now - (int) $e['at'] > self::MAX_AGE_DAYS * 86400 ) {
				$gone[ $ref ] = $e;
				unset( $all[ $ref ] );
			}
		}
		if ( count( $all ) > self::MAX_ENTRIES ) {
			uasort( $all, function ( $a, $b ) { return (int) $a['at'] <=> (int) $b['at']; } );
			while ( count( $all ) > self::MAX_ENTRIES ) {
				$ref          = array_key_first( $all );
				$gone[ $ref ] = $all[ $ref ];
				unset( $all[ $ref ] );
			}
		}
		if ( $gone ) {
			$rec = self::read( self::GAVE_UP_OPTION );
			foreach ( $gone as $ref => $e ) {
				$rec[ $ref ] = array( 'kind' => $e['kind'], 'crm' => (int) $e['crm'], 'at' => (int) $e['at'], 'gave_up' => $now );
				error_log( sprintf( 'Zorderz Surveys: GAVE UP on a %s note for CRM lead #%d (kept since %s) — post it by hand.', (string) $e['kind'], (int) $e['crm'], gmdate( 'Y-m-d', (int) $e['at'] ) ) );
			}
			self::write( self::GAVE_UP_OPTION, array_slice( $rec, -self::GAVE_UP_KEEP, null, true ) );
		}
		return $all;
	}

	/* ── Won marks ──────────────────────────────────────────────── */

	public static function won_owed(): array {
		return self::read( self::WON_OPTION );
	}

	public static function won_owed_add( int $wp_lead_id, int $crm_lead_id ): void {
		if ( $wp_lead_id < 1 || $crm_lead_id < 1 ) {
			return;
		}
		self::locked( function () use ( $wp_lead_id, $crm_lead_id ) {
			$l                = self::read( self::WON_OPTION );
			$l[ $wp_lead_id ] = array( 'crm' => $crm_lead_id, 'since' => self::now() );
			self::write( self::WON_OPTION, $l );
		} );
	}

	public static function won_owed_forget( int $wp_lead_id ): void {
		self::locked( function () use ( $wp_lead_id ) {
			$l = self::read( self::WON_OPTION );
			if ( isset( $l[ $wp_lead_id ] ) ) {
				unset( $l[ $wp_lead_id ] );
				self::write( self::WON_OPTION, $l );
			}
		} );
	}

	/**
	 * What to do with one owed Won mark, given the CRM's status for the lead. Pure.
	 *
	 * @param array    $entry      {crm, since}
	 * @param int|null $crm_status The lead's CRM status (0 = open), null when it could not be read.
	 * @return string 'close' | 'settled' (closed by now, any outcome: forget) | 'keep' | 'expired'
	 */
	public static function won_decision( array $entry, ?int $crm_status ): string {
		if ( self::now() - (int) ( $entry['since'] ?? 0 ) > self::EMAILED_LOOKBACK_DAYS * 86400 ) {
			return 'expired';
		}
		if ( null === $crm_status ) {
			return 'keep';
		}
		return 0 === $crm_status ? 'close' : 'settled';
	}
}
