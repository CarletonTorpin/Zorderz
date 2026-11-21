<?php
/**
 * ZDZ_Keys_Migrate -- the runner behind Zorderz -> App key that moves stored values onto the app's key.
 *
 * A component registers what it keeps under the WordPress salts as a JOB, on the filter zdz_keys_migrations:
 *
 *   $jobs['<id>'] = array(
 *     'label'   => 'Stored provider keys',                 // words for the page
 *     'purpose' => 'credentials',                          // a ZDZ_Keys purpose
 *     'items'   => callable(): string[],                   // every item's bind string, in a stable order
 *     'stored'  => callable( $item ): ?string,             // the raw stored value (null or '': nothing there)
 *     'legacy'  => callable( $item, $raw ): ?string,       // TODAY's reader (the salts): plaintext, or null
 *     'write'   => callable( $item, $new ): bool,          // store $new for $item
 *     'kids'    => callable(): string[],                   // OPTIONAL: the distinct zdzk1 key ids stored (one SQL
 *   );                                                     //   query); required for a job over 2000 items
 *
 * run( $id ) moves each legacy value to zdzk1, and each zdzk1 value under the previous key to the current one, in
 * batches. It reads every new value back before moving on and puts the old bytes back when the read-back fails; it
 * keeps a cursor, so it is RESUMABLE; a value already under the current key is skipped, so it is IDEMPOTENT; and it
 * NEVER WIPES: a value it can't read is listed by item (never by value) for re-entry or re-fetch and left as it was.
 * "Finished" only once nothing is left that it could still move (a list that grew between presses gets a new pass).
 * Refused while the key isn't ready (ZDZ_Keys::state()), and while another run holds the lock.
 *
 * Core registers no job: nothing is moved until a component registers what it keeps. A component that seals with
 * ZDZ_Keys outside the options table must register one (with a 'kids' reader over 2000 rows), or the scan that guards
 * recording a key cannot see its values.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Keys_Migrate {

	const OPT      = 'zdz_keys_migrate_';  // + job id: [ cursor, done, skipped, unreadable[], unreadable_n, damaged[], failed[], again, finished, at, by ]
	const LIST_MAX = 200;                  // item names kept for the page; the count is always whole
	const BUDGET_S = 20.0;                 // seconds per press (well inside a web request's time limit)

	/** The registered jobs that are well formed: id => job. */
	public static function jobs() {
		$j   = apply_filters( 'zdz_keys_migrations', array() );
		$out = array();
		foreach ( is_array( $j ) ? $j : array() as $id => $job ) {
			$id = (string) $id;
			if ( '' === $id || ! preg_match( '/^[a-z0-9_\-]{1,40}$/D', $id ) || ! is_array( $job ) ) {
				continue;
			}
			if ( ! isset( $job['purpose'] ) || ! is_string( $job['purpose'] ) || ! isset( ZDZ_Keys::PURPOSES[ $job['purpose'] ] ) ) {
				continue;
			}
			$ok = true;
			foreach ( array( 'items', 'stored', 'legacy', 'write' ) as $k ) {
				if ( ! isset( $job[ $k ] ) || ! is_callable( $job[ $k ] ) ) {
					$ok = false;
				}
			}
			if ( isset( $job['kids'] ) && ! is_callable( $job['kids'] ) ) {
				$ok = false;
			}
			if ( $ok ) {
				$job['label'] = isset( $job['label'] ) ? (string) $job['label'] : $id;
				$out[ $id ]   = $job;
			}
		}
		return $out;
	}

	private static function fresh() {
		return array(
			'cursor'       => -1,
			'done'         => 0,
			'skipped'      => 0,
			'unreadable'   => array(),
			'unreadable_n' => 0,
			'damaged'      => array(),
			'failed'       => array(),
			'again'        => false,
			'finished'     => false,
			'at'           => '',
			'by'           => 0,
		);
	}

	/** A job's last counts ( fresh() when it never ran ). */
	public static function status( $id ) {
		$s = get_option( self::OPT . (string) $id, array() );
		return is_array( $s ) ? $s + self::fresh() : self::fresh();
	}

	/**
	 * The distinct zdzk1 key ids a job holds: its 'kids' reader, else its items one by one up to $limit. null when the
	 * job is larger than $limit and has no reader (the caller can't tell, and fails closed).
	 */
	public static function kids_in( array $job, $limit ) {
		if ( isset( $job['kids'] ) && is_callable( $job['kids'] ) ) {
			return array_values( array_unique( array_map( 'strval', (array) call_user_func( $job['kids'] ) ) ) );
		}
		$items = array_values( (array) call_user_func( $job['items'] ) );
		if ( count( $items ) > (int) $limit ) {
			return null;
		}
		$found = array();
		foreach ( $items as $item ) {
			$raw = call_user_func( $job['stored'], (string) $item );
			$kid = ZDZ_Keys::header_key_id( $raw );
			if ( '' !== $kid ) {
				$found[ $kid ] = true;
			}
		}
		return array_map( 'strval', array_keys( $found ) );
	}

	private static function lock_name() {
		global $wpdb;
		$db = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		return 'zdz_keys_migrate_' . substr( md5( $db . '|' . ( is_object( $wpdb ) ? (string) $wpdb->prefix : '' ) ), 0, 12 );
	}

	private static function lock() {
		global $wpdb;
		return is_object( $wpdb ) && '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) );
	}

	private static function unlock() {
		global $wpdb;
		if ( is_object( $wpdb ) ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
		}
	}

	/**
	 * One press: up to $limit items of job $id (and at most BUDGET_S seconds). Returns the job's counts, or
	 * [ 'error' => no_such_job | busy | <the key's state> ].
	 */
	public static function run( $id, $limit = 200, $uid = 0 ) {
		$id   = (string) $id;
		$jobs = self::jobs();
		if ( ! isset( $jobs[ $id ] ) ) {
			return array( 'error' => 'no_such_job' );
		}
		if ( ! ZDZ_Keys::ready() ) {
			return array( 'error' => ZDZ_Keys::state() );
		}
		if ( ! self::lock() ) {
			return array( 'error' => 'busy' );
		}
		try {
			return self::run_locked( $id, $jobs[ $id ], max( 1, (int) $limit ), (int) $uid );
		} finally {
			self::unlock();
		}
	}

	private static function run_locked( $id, array $j, $limit, $uid ) {
		$t0 = microtime( true );
		$st = self::status( $id );
		if ( ! empty( $st['finished'] ) ) {
			$st = self::fresh();   // a finished job starts over: a new count, the same idempotent pass
		}
		$items = array_values( (array) call_user_func( $j['items'] ) );
		$total = count( $items );
		$cur   = ZDZ_Keys::key_id();
		$n     = 0;
		$bad   = array_values( (array) $st['unreadable'] );
		for ( $i = (int) $st['cursor'] + 1; $i < $total; $i++ ) {
			if ( $n >= $limit || ( microtime( true ) - $t0 ) > self::BUDGET_S ) {
				break;
			}
			++$n;
			$item         = (string) $items[ $i ];
			$st['cursor'] = $i;
			$raw          = call_user_func( $j['stored'], $item );
			if ( null === $raw || '' === $raw ) {
				++$st['skipped'];
				continue;
			}
			$raw = (string) $raw;
			if ( ZDZ_Keys::is_zdzk1( $raw ) && ZDZ_Keys::header_key_id( $raw ) === $cur ) {
				++$st['skipped'];
				continue;
			}
			$plain = ZDZ_Keys::is_zdzk1( $raw ) ? ZDZ_Keys::open( $j['purpose'], $raw, $item ) : call_user_func( $j['legacy'], $item, $raw );
			if ( null === $plain || '' === (string) $plain ) {
				self::mark_unreadable( $st, $bad, $item );
				continue;
			}
			$plain = (string) $plain;
			$new   = ZDZ_Keys::seal( $j['purpose'], $plain, $item );
			if ( ! is_string( $new ) || '' === $new ) {
				self::mark_unreadable( $st, $bad, $item );
				self::mark_failed( $st, $item );
				continue;
			}
			call_user_func( $j['write'], $item, $new );
			// Read back after EVERY write, even one that says it failed (a partial write), and keep the value only
			// if it opens to exactly what was read; otherwise put the old bytes back and check that too.
			$back = call_user_func( $j['stored'], $item );
			if ( is_string( $back ) && ZDZ_Keys::is_zdzk1( $back ) && ZDZ_Keys::open( $j['purpose'], $back, $item ) === $plain ) {
				++$st['done'];
				$plain = '';
				continue;
			}
			if ( $back !== $raw ) {
				call_user_func( $j['write'], $item, $raw );
				if ( call_user_func( $j['stored'], $item ) !== $raw && ! in_array( $item, (array) $st['damaged'], true ) ) {
					$st['damaged'][] = $item;   // the old bytes could not be put back: said loudly on the page
				}
			}
			self::mark_unreadable( $st, $bad, $item );
			self::mark_failed( $st, $item );
			$plain = '';
		}
		// The cursor counts positions, and a list can change between presses. At the end of the list, "finished" only
		// when no item is left that this job could still move (not under the current key, not already listed as
		// unreadable); otherwise the next press starts a new pass from the top.
		if ( ( (int) $st['cursor'] + 1 ) >= $total && self::pending( $j, (array) $st['failed'] ) > 0 ) {
			// a new pass from the top: it counts what it skips and can't read afresh (no double counting)
			$st['cursor']       = -1;
			$st['skipped']      = 0;
			$st['unreadable_n'] = 0;
			$st['failed']       = array();
			$st['again']        = true;
			$st['unreadable']   = array();
			$st['finished']     = false;
			$st['at']           = gmdate( 'Y-m-d H:i:s' );
			$st['by']           = (int) $uid;
			update_option( self::OPT . $id, $st, false );
			ZDZ_Keys::forget_scan();
			return $st;
		}
		$st['again']      = false;
		$st['unreadable'] = $bad;
		// a value that could not be put back holds the job open until someone has looked
		$st['finished'] = ( (int) $st['cursor'] + 1 ) >= $total && empty( $st['damaged'] );
		$st['at']       = gmdate( 'Y-m-d H:i:s' );
		$st['by']       = (int) $uid;
		update_option( self::OPT . $id, $st, false );
		ZDZ_Keys::forget_scan();
		return $st;
	}

	/**
	 * Values a job could still move: stored, not under the current key, readable now (legacy reader, or the previous
	 * key), and not a write that already failed in this pass. Decided by reading each value, not by the 200-name list
	 * kept for the page, so a job with many unreadable values still finishes.
	 */
	public static function pending( array $j, array $failed = array() ) {
		$cur    = ZDZ_Keys::key_id();
		$failed = array_flip( array_map( 'strval', $failed ) );
		$n      = 0;
		foreach ( (array) call_user_func( $j['items'] ) as $item ) {
			$item = (string) $item;
			if ( isset( $failed[ $item ] ) ) {
				continue;
			}
			$raw = call_user_func( $j['stored'], $item );
			if ( null === $raw || '' === $raw ) {
				continue;
			}
			$raw = (string) $raw;
			if ( ZDZ_Keys::is_zdzk1( $raw ) ) {
				if ( ZDZ_Keys::header_key_id( $raw ) === $cur || null === ZDZ_Keys::open( $j['purpose'], $raw, $item ) ) {
					continue;
				}
			} else {
				$p = call_user_func( $j['legacy'], $item, $raw );
				if ( null === $p || '' === (string) $p ) {
					continue;
				}
			}
			++$n;
		}
		return $n;
	}

	/** A value that read but could not be written back as zdzk1 this pass (rare: kept in full, never capped). */
	private static function mark_failed( array &$st, $item ) {
		$st['failed'] = (array) $st['failed'];
		if ( ! in_array( $item, $st['failed'], true ) ) {
			$st['failed'][] = $item;
		}
	}

	private static function mark_unreadable( array &$st, array &$bad, $item ) {
		if ( in_array( $item, $bad, true ) ) {
			return;
		}
		$st['unreadable_n'] = (int) $st['unreadable_n'] + 1;
		if ( count( $bad ) < self::LIST_MAX ) {
			$bad[] = $item;
		}
	}

	/**
	 * "Re-encrypt everything now": one press of every job (each up to $limit items). Returns id => counts. When a
	 * key change is under way and every job is done, records the new key (ZDZ_Keys::record_key), under '_recorded'.
	 */
	public static function run_all( $limit = 200, $uid = 0 ) {
		$out = array();
		foreach ( array_keys( self::jobs() ) as $id ) {
			$out[ $id ] = self::run( $id, $limit, $uid );
		}
		if ( ZDZ_Keys::rotating() && self::all_done() ) {
			$out['_recorded'] = ZDZ_Keys::record_key( (int) $uid );
		}
		return $out;
	}

	/**
	 * Is the move to the current key complete? Every job's last run finished with nothing damaged, and no zdzk1 value
	 * the scan can see (options, whole or inside a serialized array, and every job) is left under another key id:
	 * anything still under the previous key holds a key change open. (A legacy value it couldn't read does not: it
	 * depends on the salts, not on the key being retired; it stays listed for re-entry.)
	 */
	public static function all_done() {
		$cur = ZDZ_Keys::key_id();
		if ( '' === $cur ) {
			return false;
		}
		foreach ( self::jobs() as $id => $j ) {
			$st = self::status( $id );
			if ( empty( $st['finished'] ) || ! empty( $st['damaged'] ) ) {
				return false;
			}
		}
		$kids = ZDZ_Keys::sealed_kids();
		if ( null === $kids ) {
			return false;
		}
		foreach ( $kids as $k ) {
			if ( $k !== $cur ) {
				return false;
			}
		}
		return true;
	}
}
