<?php
/**
 * ZSM_Policy — the Security Monitor's decisions, as pure functions.
 *
 * Everything here takes plain values and returns plain values: no database, no HTTP, no
 * WordPress state. The feed job, the scheduler, the console and the digest call these, and
 * the unit tests pin them directly.
 *
 *   budget()          how big a background step may be on THIS host (time box, download timeout)
 *   memory_stop()     when a feed slice must stop to stay inside the PHP memory limit
 *   verdict()         whether a finished download is complete and sane enough to replace the cache
 *   gate()            whether a download may start now (staging copy, no key, backoff, pacing)
 *   http_failure()    what an HTTP status means: message, backoff, and kind of failure
 *   is_stale()        whether the cached copy needs a person's attention
 *   feed_state()      not connected / never synced / fresh / stale
 *   schedule_action() whether the daily job can be scheduled yet, and with what
 *   retry_at()        when the one retry after a rate limit or a transient failure runs
 *   digest_*()        which recipients get a digest (nothing new = nothing sent)
 *
 * @package Zorderz\Security_Monitor
 */

defined( 'ABSPATH' ) || exit;

final class ZSM_Policy {

	/** The feed provider allows one download per key every 30 minutes by default. */
	const MIN_INTERVAL = 1800;
	/** Pause after the provider refuses the key (HTTP 401/403). */
	const AUTH_BACKOFF = 21600;
	/** Pause after the feed address stops serving data (HTTP 404/410). */
	const GONE_BACKOFF = 21600;
	/** The shortest pause after a rate limit (HTTP 429); a longer Retry-After is honoured up to a day. */
	const RATE_BACKOFF = 3600;
	/** A cached copy older than this, with no good sync since, is stale. */
	const STALE_AFTER = 172800;
	/** A real feed holds tens of thousands of records; fewer than this is refused. */
	const MIN_RECORDS = 1000;
	/** The request wall assumed when neither the site nor PHP says how long a request may run. */
	const DEFAULT_WALL = 60;
	/** The longest time box for one background step, whatever the host allows. */
	const MAX_SLICE = 20.0;
	/** The shortest useful time box. */
	const MIN_SLICE = 2.0;
	/** The longest download timeout, whatever the host allows. */
	const MAX_DOWNLOAD = 50;
	/** The shortest download timeout. */
	const MIN_DOWNLOAD = 10;
	/** How much a single feed slice may grow the process before it stops at a record boundary. */
	const MEMORY_HEADROOM = 134217728;
	/** A single feed record larger than this is treated as a damaged feed, not read into memory. */
	const MAX_RECORD_BYTES = 8388608;
	/** The daily code check runs again once the last finished one is this old. */
	const CODESCAN_EVERY = 72000;
	/** How long a confirmed daily schedule is trusted before it is checked again. */
	const SCHEDULE_RECHECK = 43200;
	/** The first daily run starts this long after the schedule is created. */
	const FIRST_RUN_DELAY = 300;
	/** The retry runs this long after the pause ends. */
	const RETRY_GRACE = 60;
	/** A feed job unfinished after this long is dropped and started again. */
	const JOB_MAX_AGE = 86400;

	/** Failure kinds: only 'attention' (or old age) makes the cached copy stale. */
	const KIND_RATE      = 'rate';
	const KIND_TRANSIENT = 'transient';
	const KIND_ATTENTION = 'attention';

	/** Severities, worst first. */
	const SEVERITIES = array( 'critical', 'high', 'medium', 'low', 'info' );

	// ── Sizing the work for the host ────────────────────────────────────────────

	/**
	 * Size one background step for the host's request wall (the longest a web request may run
	 * before the server cuts it off). A third of the wall goes to one step, so a step plus the
	 * page around it never meets the wall; the download gets the wall less ten seconds. Both are
	 * capped, so a generous host does not make a single step hog the database.
	 *
	 * @param int $wall_seconds 0 or less = unknown (DEFAULT_WALL is assumed).
	 * @return array{wall:int,slice:float,download_timeout:int}
	 */
	public static function budget( int $wall_seconds ): array {
		$wall  = $wall_seconds > 0 ? $wall_seconds : self::DEFAULT_WALL;
		$slice = (float) max( self::MIN_SLICE, min( self::MAX_SLICE, floor( $wall / 3 ) ) );
		$dl    = (int) max( self::MIN_DOWNLOAD, min( self::MAX_DOWNLOAD, $wall - 10 ) );
		return array( 'wall' => $wall, 'slice' => $slice, 'download_timeout' => $dl );
	}

	/**
	 * The memory level at which a feed slice stops (at the next record boundary): 85% of the PHP
	 * limit, or the memory in use when the slice began plus the headroom, whichever comes first.
	 *
	 * @param int $limit    PHP memory_limit in bytes; 0 or less = no limit.
	 * @param int $base     memory in use when the slice began.
	 * @param int $headroom how much one slice may add (at least 16 MB).
	 */
	public static function memory_stop( int $limit, int $base, int $headroom = self::MEMORY_HEADROOM ): int {
		$headroom = max( 16777216, $headroom );
		if ( $limit > 0 ) {
			return (int) min( (int) floor( $limit * 0.85 ), $base + $headroom );
		}
		return $base + $headroom;
	}

	/**
	 * Is a finished download good enough to replace the cached copy? '' = yes; otherwise the
	 * reason, and the cached copy stays exactly as it was.
	 *
	 * @param array $job records, matched, malformed, insert_failed counts from the job.
	 */
	public static function verdict( array $job, int $min_records = self::MIN_RECORDS ): string {
		$records   = (int) ( $job['records'] ?? 0 );
		$matched   = (int) ( $job['matched'] ?? 0 );
		$malformed = (int) ( $job['malformed'] ?? 0 );
		$failed    = (int) ( $job['insert_failed'] ?? 0 );
		if ( $records < max( 1, $min_records ) ) {
			return sprintf( __( 'the feed had only %1$d records (expected at least %2$d); kept the last good copy', 'zorderz' ), $records, $min_records );
		}
		if ( $failed > 0 ) {
			return sprintf( __( '%d cache rows could not be written; kept the last good copy', 'zorderz' ), $failed );
		}
		if ( $malformed > max( 10, (int) floor( $matched / 2 ) ) ) {
			return sprintf( __( '%d relevant records could not be decoded; kept the last good copy', 'zorderz' ), $malformed );
		}
		return '';
	}

	// ── Starting a download ─────────────────────────────────────────────────────

	/**
	 * May a download start now? The checks run in this order and the first one that applies
	 * answers. Nothing is requested unless the answer is 'go'.
	 *
	 * @param array $f staging (bool), key (bool), url_ok (bool), now, backoff_until, last_attempt (ints), last_error (string)
	 * @return array{status:string,message:string,kind?:string}
	 *   status: staging | needs_key | failed | waiting | go
	 */
	public static function gate( array $f ): array {
		$now = (int) ( $f['now'] ?? time() );
		if ( ! empty( $f['staging'] ) ) {
			return array(
				'status'  => 'staging',
				'message' => __( 'This is a staging copy. It carries the live site\'s feed key, and the provider paces downloads per key, so a staging copy does not download the feed; it keeps the copy it was made with.', 'zorderz' ),
			);
		}
		if ( empty( $f['key'] ) ) {
			return array(
				'status'  => 'needs_key',
				'message' => __( 'The vulnerability feed is not connected (no feed key is set). The local checks still run: installed versions, available updates, version drift and your own code. Set a feed key to also match against published vulnerabilities.', 'zorderz' ),
			);
		}
		if ( empty( $f['url_ok'] ) ) {
			return array(
				'status'  => 'failed',
				'message' => __( 'the feed address is not an allowed https address', 'zorderz' ),
				'kind'    => self::KIND_ATTENTION,
			);
		}
		$backoff = (int) ( $f['backoff_until'] ?? 0 );
		if ( $backoff > $now ) {
			return array(
				'status'  => 'waiting',
				'message' => sprintf( __( 'Paused until %1$s UTC after: %2$s', 'zorderz' ), gmdate( 'j M H:i', $backoff ), (string) ( $f['last_error'] ?? '' ) ),
			);
		}
		$last = (int) ( $f['last_attempt'] ?? 0 );
		if ( $now - $last < self::MIN_INTERVAL ) {
			return array(
				'status'  => 'waiting',
				'message' => sprintf( __( 'The feed may be downloaded once every 30 minutes; the next download is allowed at %s UTC.', 'zorderz' ), gmdate( 'H:i', $last + self::MIN_INTERVAL ) ),
			);
		}
		return array( 'status' => 'go', 'message' => '' );
	}

	/** The earliest moment another download may start. */
	public static function next_download_at( int $last_attempt, int $backoff_until ): int {
		return max( $backoff_until, $last_attempt + self::MIN_INTERVAL );
	}

	/** When the single retry after a rate limit or a transient failure runs. */
	public static function retry_at( int $backoff_until, int $last_attempt ): int {
		return self::next_download_at( $last_attempt, $backoff_until ) + self::RETRY_GRACE;
	}

	/**
	 * How long to pause after a rate limit: at least an hour, longer if the provider's
	 * Retry-After says so (seconds or an HTTP date), never more than a day.
	 */
	public static function rate_backoff( string $retry_after, int $now = 0 ): int {
		$now = $now > 0 ? $now : time();
		$ra  = trim( $retry_after );
		$sec = 0;
		if ( '' !== $ra ) {
			if ( ctype_digit( $ra ) ) {
				$sec = (int) $ra;
			} else {
				$ts  = strtotime( $ra );
				$sec = false === $ts ? 0 : max( 0, $ts - $now );
			}
		}
		return max( self::RATE_BACKOFF, min( 86400, $sec ) );
	}

	/**
	 * What a non-200 HTTP status means.
	 *
	 * @return array{message:string,backoff:int,kind:string}
	 */
	public static function http_failure( int $code, string $retry_after = '', int $now = 0 ): array {
		if ( 401 === $code || 403 === $code ) {
			return array(
				'message' => sprintf( __( 'The feed provider refused the key (HTTP %d). Check the feed key.', 'zorderz' ), $code ),
				'backoff' => self::AUTH_BACKOFF,
				'kind'    => self::KIND_ATTENTION,
			);
		}
		if ( 429 === $code ) {
			return array(
				'message' => __( 'The feed provider\'s rate limit was reached (HTTP 429).', 'zorderz' ),
				'backoff' => self::rate_backoff( $retry_after, $now ),
				'kind'    => self::KIND_RATE,
			);
		}
		if ( 404 === $code || 410 === $code ) {
			return array(
				'message' => sprintf( __( 'The feed address no longer serves data (HTTP %d); the provider may have moved it.', 'zorderz' ), $code ),
				'backoff' => self::GONE_BACKOFF,
				'kind'    => self::KIND_ATTENTION,
			);
		}
		return array(
			'message' => sprintf( __( 'The feed provider returned HTTP %d.', 'zorderz' ), $code ),
			'backoff' => 0,
			'kind'    => $code >= 500 ? self::KIND_TRANSIENT : self::KIND_ATTENTION,
		);
	}

	// ── Freshness ───────────────────────────────────────────────────────────────

	/** A stored failure kind, or 'attention' when it is missing or unknown. */
	public static function kind( string $stored ): string {
		return in_array( $stored, array( self::KIND_RATE, self::KIND_TRANSIENT, self::KIND_ATTENTION ), true ) ? $stored : self::KIND_ATTENTION;
	}

	/**
	 * Does the cached copy need a person? Never synced; or the last attempt failed in a way a
	 * person must fix (key refused, feed moved, a copy refused); or no good sync for 48 hours.
	 * A rate limit or a transient failure with a copy under 48 hours old is NOT stale: the copy
	 * is fine and the next run tries again.
	 */
	public static function is_stale( string $last_sync, string $last_error, string $kind, int $now ): bool {
		if ( '' === $last_sync ) {
			return true;
		}
		if ( '' !== $last_error && ! in_array( self::kind( $kind ), array( self::KIND_RATE, self::KIND_TRANSIENT ), true ) ) {
			return true;
		}
		$t = strtotime( $last_sync );
		return ( false === $t ) || ( $now - $t > self::STALE_AFTER );
	}

	/**
	 * The feed's state, for the console, the dashboard widget and the digest.
	 *   not_connected  no feed key is set (the feed is optional; the local checks still run)
	 *   never          a key is set but no download has finished yet
	 *   stale          see is_stale()
	 *   fresh          a good copy, recent enough
	 */
	public static function feed_state( bool $connected, string $last_sync, string $last_error, string $kind, int $now ): string {
		if ( ! $connected ) {
			return 'not_connected';
		}
		if ( '' === $last_sync ) {
			return 'never';
		}
		return self::is_stale( $last_sync, $last_error, $kind, $now ) ? 'stale' : 'fresh';
	}

	/** Should the digest and the widget raise the stale-feed alarm? Only for a connected feed. */
	public static function alarm( string $state ): bool {
		return in_array( $state, array( 'never', 'stale' ), true );
	}

	/** A feed job started this long ago is dropped (cron not running, file lost). */
	public static function job_expired( string $started, int $now ): bool {
		if ( '' === $started ) {
			return false;
		}
		$t = strtotime( $started );
		return false !== $t && ( $now - $t ) > self::JOB_MAX_AGE;
	}

	// ── Scheduling ──────────────────────────────────────────────────────────────

	/**
	 * How to make sure the daily job exists, given what this request knows:
	 *   cached            confirmed within the last 12 hours: nothing to do
	 *   wait              the job library is loaded but its data store has not started yet
	 *                     (asking it now schedules nothing and logs a notice): try on a later request
	 *   action_scheduler  ask the job library
	 *   wp_cron           no job library: use WP-Cron
	 */
	public static function schedule_action( bool $cached, bool $as_loaded, bool $as_ready, bool $force = false ): string {
		if ( $cached && ! $force ) {
			return 'cached';
		}
		if ( $as_loaded && ! $as_ready ) {
			return 'wait';
		}
		return $as_loaded ? 'action_scheduler' : 'wp_cron';
	}

	/** The daily code check is due once the last finished one is 20 hours old (or there never was one). */
	public static function codescan_due( int $last_at, int $now ): bool {
		return $last_at <= 0 || ( $now - $last_at ) > self::CODESCAN_EVERY;
	}

	// ── Severity ────────────────────────────────────────────────────────────────

	/** Is public registration open? An explicit console choice wins over the WordPress setting. */
	public static function registration_open( bool $wp_open, string $override ): bool {
		if ( 'yes' === $override ) {
			return true;
		}
		if ( 'no' === $override ) {
			return false;
		}
		return $wp_open;
	}

	// ── The digest ──────────────────────────────────────────────────────────────

	/**
	 * A fingerprint of what the digest would say: the open critical/high findings (as a set) and
	 * whether the feed alarm is up. Same fingerprint as last time = nothing new to tell.
	 *
	 * @param string[] $notable_keys finding keys of the open critical/high findings
	 */
	public static function digest_signature( array $notable_keys, bool $alarm ): string {
		$keys = array_values( array_unique( array_map( 'strval', $notable_keys ) ) );
		sort( $keys, SORT_STRING );
		return md5( implode( ';', $keys ) . '|alarm=' . ( $alarm ? '1' : '0' ) );
	}

	/**
	 * Who gets this digest: each recipient whose last delivered digest had a different
	 * fingerprint (so someone whose delivery failed gets it next time, and a newly added
	 * recipient gets the current state), or everyone when it is sent on demand.
	 *
	 * @param int[]                 $recipients
	 * @param array<int|string,string> $sig_by_user last delivered fingerprint per user id
	 * @return int[]
	 */
	public static function digest_due( array $recipients, array $sig_by_user, string $sig, bool $force = false ): array {
		$due = array();
		foreach ( $recipients as $uid ) {
			$uid = (int) $uid;
			if ( $uid <= 0 || in_array( $uid, $due, true ) ) {
				continue;
			}
			if ( $force || (string) ( $sig_by_user[ $uid ] ?? '' ) !== $sig ) {
				$due[] = $uid;
			}
		}
		return $due;
	}

	/**
	 * Remove a secret from text that may be shown, stored or logged. An HTTP library or a proxy
	 * can echo request headers into an error message; this keeps the key out of every message.
	 */
	public static function redact( string $text, string $secret ): string {
		$secret = trim( $secret );
		if ( strlen( $secret ) >= 4 ) {
			$text = str_replace( $secret, '[redacted]', $text );
		}
		return (string) preg_replace( '/(Bearer\s+)[^\s"\',;]+/i', '$1[redacted]', $text );
	}
}
