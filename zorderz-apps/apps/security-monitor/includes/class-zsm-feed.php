<?php
/**
 * ZSM_Feed — download the vulnerability feed and cache the part of it that concerns the
 * software actually installed here.
 *
 * The feed is optional. With no feed key the monitor still runs every local check; this class
 * then requests nothing at all and says the feed is not connected.
 *
 *   start ──► download (streamed to a file, never into memory; the key only in a request header)
 *     │          │  401/403 · 404/410 · 429 · 5xx · timeout → readable error + backoff
 *     │          ▼
 *     │      read slices (ZSM_Feed_Stream; stop at a record boundary when the time box or the
 *     │          │        memory guard says so; resume on the next run)
 *     │          ▼
 *     │      rows go to {prefix}zsm_vulns_next — the live table is never touched
 *     │          ▼
 *     └──► complete and sane? ──► RENAME live→old, next→live (one statement) ──► drop old
 *                 │ no
 *                 └──► abort: the live table stays as it was (the last good copy)
 *
 * The work is sized for the host: each background step gets a third of the host's request
 * wall (at most 20 s), the download gets the wall less ten seconds (at most 50 s), and a slice
 * stops early when memory grows too close to PHP's limit (ZSM_Policy::budget, ::memory_stop).
 *
 * The provider allows one download per key every 30 minutes; every path that could start a
 * download checks that first, including the console button and WP-CLI. Only the feed address
 * is ever contacted: the address is pinned to the provider's host, and a redirect is followed
 * once, by hand, only to an allowed https host and without the key.
 *
 * @package Zorderz\Security_Monitor
 */

defined( 'ABSPATH' ) || exit;

class ZSM_Feed {

	/** Wordfence Intelligence v3 feed; the variant ('production' or 'scanner') is appended. */
	const BASE_URL     = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/';
	const ALLOWED_HOST = 'www.wordfence.com';
	const VARIANTS     = array( 'production', 'scanner' );
	const TMP_DIR      = 'zsm-tmp';

	// ── State ───────────────────────────────────────────────────────────────────

	public static function last_sync(): string {
		return (string) get_option( ZSM::OPT . 'feed_last_sync', '' );
	}

	public static function last_error(): string {
		return (string) get_option( ZSM::OPT . 'feed_last_error', '' );
	}

	/** 'rate' (429), 'transient' (5xx, network, a bad or cut-off body) or 'attention' (needs a person). */
	public static function last_error_kind(): string {
		return ZSM_Policy::kind( (string) get_option( ZSM::OPT . 'feed_last_error_kind', '' ) );
	}

	public static function metrics(): array {
		$m = get_option( ZSM::OPT . 'feed_last_metrics', array() );
		return is_array( $m ) ? $m : array();
	}

	public static function job(): array {
		$j = get_option( ZSM::OPT . 'feed_job', array() );
		return is_array( $j ) ? $j : array();
	}

	public static function backoff_until(): int {
		return (int) get_option( ZSM::OPT . 'feed_backoff_until', 0 );
	}

	public static function last_attempt(): int {
		return (int) get_option( ZSM::OPT . 'feed_last_attempt', 0 );
	}

	/** 'production' carries severity scores (recommended); 'scanner' is smaller but has none. */
	public static function variant(): string {
		$v = (string) get_option( ZSM::OPT . 'feed_variant', 'production' );
		return in_array( $v, self::VARIANTS, true ) ? $v : 'production';
	}

	/** The feed address for the chosen variant, pinned to the provider's host. Code may override it with `zsm_feed_url`. */
	public static function url(): string {
		$url = (string) apply_filters( 'zsm_feed_url', self::BASE_URL . self::variant() );
		return self::url_allowed( $url ) ? $url : '';
	}

	/** Hosts the monitor may contact for the feed. Code may widen the list with `zsm_feed_allowed_hosts`. */
	public static function allowed_hosts(): array {
		$hosts = (array) apply_filters( 'zsm_feed_allowed_hosts', array( self::ALLOWED_HOST ) );
		return array_values( array_filter( array_map( 'strtolower', array_map( 'strval', $hosts ) ) ) );
	}

	/** https and an allowed host only. */
	public static function url_allowed( string $url ): bool {
		$p = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $p ) || 'https' !== strtolower( (string) ( $p['scheme'] ?? '' ) ) || isset( $p['user'] ) || isset( $p['pass'] ) ) {
			return false;
		}
		return in_array( strtolower( (string) ( $p['host'] ?? '' ) ), self::allowed_hosts(), true );
	}

	/**
	 * Is this a staging copy that must not download? A staging copy carries the live site's
	 * feed key in its copied database, and the provider paces downloads per KEY, so a staging
	 * download can make the live site's next download fail with a rate limit. Staging keeps the
	 * copy it was made with. A staging copy given its own key can opt in with the
	 * `zsm_feed_on_staging` filter.
	 */
	public static function staging_copy(): bool {
		$staging = class_exists( 'ZDZ_Staging_Guard' ) && method_exists( 'ZDZ_Staging_Guard', 'is_active' ) && ZDZ_Staging_Guard::is_active();
		return $staging && ! apply_filters( 'zsm_feed_on_staging', false );
	}

	/** not_connected | never | stale | fresh (see ZSM_Policy::feed_state). */
	public static function state(): string {
		return ZSM_Policy::feed_state( ZSM::has_feed_key(), self::last_sync(), self::last_error(), self::last_error_kind(), time() );
	}

	/** Should the console, the widget and the digest raise the stale-feed alarm? */
	public static function alarm(): bool {
		return ZSM_Policy::alarm( self::state() );
	}

	/** One plain sentence about the feed, for the widget, the console and the digest. Never invents. */
	public static function freshness_line(): string {
		$state = self::state();
		$last  = self::last_sync();
		$ago   = '' === $last ? '' : ( function_exists( 'human_time_diff' ) ? human_time_diff( (int) strtotime( $last ) ) : $last );
		switch ( $state ) {
			case 'not_connected':
				$s = __( 'Vulnerability feed: not connected. Only the local checks ran (installed versions, available updates, version drift, your own code).', 'zorderz' );
				if ( '' !== $last ) {
					/* translators: %s: how long ago */
					$s .= ' ' . sprintf( __( 'An earlier copy of the feed, from %s ago, is still used for matching.', 'zorderz' ), $ago );
				}
				return $s;
			case 'never':
				$s = __( 'Vulnerability feed: connected, not synced yet; no vulnerability matching has run.', 'zorderz' );
				break;
			case 'stale':
				/* translators: %s: how long ago */
				$s = sprintf( __( 'STALE FEED. Vulnerability feed: last good sync %s ago. Findings use the last good copy.', 'zorderz' ), $ago );
				break;
			default:
				/* translators: %s: how long ago */
				$s = sprintf( __( 'Vulnerability feed: last good sync %s ago.', 'zorderz' ), $ago );
		}
		$err = self::last_error();
		if ( '' !== $err ) {
			/* translators: %s: the error */
			$s .= ' ' . sprintf( __( 'Last attempt failed: %s', 'zorderz' ), $err );
			if ( self::backoff_until() > time() ) {
				/* translators: %s: date and time */
				$s .= ' ' . sprintf( __( 'Next try after %s UTC.', 'zorderz' ), gmdate( 'j M H:i', self::backoff_until() ) );
			}
			if ( 'fresh' === $state && in_array( self::last_error_kind(), array( ZSM_Policy::KIND_RATE, ZSM_Policy::KIND_TRANSIENT ), true ) ) {
				$s .= ' ' . __( 'The copy above is still current.', 'zorderz' );
			}
		}
		if ( self::staging_copy() ) {
			$s .= ' ' . __( 'Staging copy: the feed is not downloaded here (it shares the live site\'s feed key), so this copy ages; that is expected.', 'zorderz' );
		}
		return $s;
	}

	// ── The job ─────────────────────────────────────────────────────────────────

	/**
	 * Advance the sync by at most $budget seconds of wall time. Safe to call from cron, the
	 * console button or WP-CLI; each call does whatever is next.
	 *
	 * @return array{status:string,message:string,progress:float,rows:int}
	 *   status: needs_key | staging | waiting | busy | downloading | parsing | done | failed
	 */
	public static function step( float $budget ): array {
		// One sync at a time across every entry point: overlapping runs race on the job record
		// and the table swap.
		if ( ! self::lock() ) {
			return self::result( 'busy', __( 'Another feed sync is running right now; this one stepped aside.', 'zorderz' ) );
		}
		try {
			self::forget_cached_state(); // another process may have moved the job on
			return self::step_locked( $budget );
		} finally {
			self::unlock();
		}
	}

	private static function lock_name(): string {
		global $wpdb;
		return substr( $wpdb->prefix . 'zsm_feed_sync', 0, 64 );
	}

	private static function lock(): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) );
	}

	private static function unlock(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
	}

	/** Drop this request's cached copies of the job's options, so the database is read. */
	private static function forget_cached_state(): void {
		foreach ( array( 'feed_job', 'feed_last_attempt', 'feed_backoff_until', 'feed_last_sync', 'feed_last_error', 'feed_last_error_kind' ) as $o ) {
			wp_cache_delete( ZSM::OPT . $o, 'options' );
		}
		wp_cache_delete( 'notoptions', 'options' );
	}

	private static function step_locked( float $budget ): array {
		$t0  = microtime( true );
		$job = self::job();

		// A job unfinished after a day (cron not running, file lost) is dropped; the next start
		// downloads again, subject to the 30-minute rule.
		if ( ! empty( $job ) && ZSM_Policy::job_expired( (string) ( $job['started'] ?? '' ), time() ) ) {
			self::abort_unlocked( 'unfinished for over a day' );
			$job = array();
		}

		if ( empty( $job ) ) {
			$start = self::start();
			if ( 'parsing' !== $start['status'] ) {
				return $start;
			}
			// The download may have used most of this run's time; read on only if a useful amount
			// is left. (Only here: a later run always reads at least one record, so every run makes
			// progress whatever its budget.)
			if ( $budget - ( microtime( true ) - $t0 ) < 1.0 ) {
				return $start;
			}
			$job = self::job();
		}

		$remaining = max( 0.0, $budget - ( microtime( true ) - $t0 ) );
		return self::parse_slice( $job, microtime( true ) + $remaining, $t0 );
	}

	/** Run to completion (WP-CLI only: there is no request wall there). Calls $on_step after each slice. */
	public static function run_all( float $slice_seconds, ?callable $on_step = null, int $max_slices = 10000 ): array {
		$r = array( 'status' => 'failed', 'message' => 'not started', 'progress' => 0.0, 'rows' => 0 );
		for ( $n = 0; $n < $max_slices; $n++ ) {
			$r = self::step( $slice_seconds );
			if ( $on_step ) {
				$on_step( $r );
			}
			if ( ! in_array( $r['status'], array( 'downloading', 'parsing' ), true ) ) {
				break;
			}
		}
		return $r;
	}

	/**
	 * Throw away an in-progress job (its file and shadow table); the live cache is untouched.
	 * Takes the sync lock, so it can never pull the shadow table out from under a running slice.
	 * Returns false when a sync is running right now.
	 */
	public static function abort( string $why = '' ): bool {
		if ( ! self::lock() ) {
			return false;
		}
		try {
			self::forget_cached_state();
			self::abort_unlocked( $why );
		} finally {
			self::unlock();
		}
		return true;
	}

	/** abort() for code that already holds the lock. */
	private static function abort_unlocked( string $why = '' ): void {
		$job = self::job();
		if ( ! empty( $job['file'] ) && is_string( $job['file'] ) && file_exists( $job['file'] ) && self::is_our_file( $job['file'] ) ) {
			wp_delete_file( $job['file'] );
		}
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . ZSM_DB::vulns_next_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed identifier
		delete_option( ZSM::OPT . 'feed_job' );
		if ( '' !== $why ) {
			ZSM::log( 'feed job aborted: ' . $why );
		}
	}

	// ── Start: checks, then the download ───────────────────────────────────────

	private static function start(): array {
		$key  = ZSM::feed_key();
		$url  = self::url();
		$gate = ZSM_Policy::gate(
			array(
				'staging'       => self::staging_copy(),
				'key'           => '' !== $key,
				'url_ok'        => '' !== $url,
				'now'           => time(),
				'backoff_until' => self::backoff_until(),
				'last_attempt'  => self::last_attempt(),
				'last_error'    => self::last_error(),
			)
		);
		if ( 'failed' === $gate['status'] ) {
			return self::fail( $gate['message'], 0, (string) ( $gate['kind'] ?? ZSM_Policy::KIND_ATTENTION ) );
		}
		if ( 'go' !== $gate['status'] ) {
			return self::result( $gate['status'], $gate['message'] );
		}

		self::sweep_temp_files();
		$file = self::new_temp_file();
		if ( '' === $file ) {
			return self::fail( __( 'no writable folder for the temporary feed file', 'zorderz' ), 0 );
		}

		update_option( ZSM::OPT . 'feed_last_attempt', time(), false );
		$budget = ZSM::budget();
		$t0     = microtime( true );
		$got    = self::fetch( $url, $key, $file, (int) $budget['download_timeout'] );
		$dl_s   = microtime( true ) - $t0;
		if ( ! $got['ok'] ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
			return self::fail( $got['message'], (int) $got['backoff'], (string) $got['kind'] );
		}

		$plain = self::ensure_plain_json( $file );
		if ( '' !== $plain['error'] ) {
			if ( file_exists( $file ) && self::is_our_file( $file ) ) {
				wp_delete_file( $file );
			}
			return self::fail( $plain['error'], 0, ZSM_Policy::KIND_TRANSIENT ); // a maintenance page or a cut-off body
		}

		global $wpdb;
		$live = ZSM_DB::vulns_table();
		$next = ZSM_DB::vulns_next_table();
		$wpdb->query( "DROP TABLE IF EXISTS {$next}" );      // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers
		$wpdb->query( "CREATE TABLE {$next} LIKE {$live}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers

		update_option(
			ZSM::OPT . 'feed_job',
			array(
				'file'      => $plain['file'],
				'bytes'     => (int) filesize( $plain['file'] ),
				'offset'    => 0,
				'records'   => 0,
				'matched'   => 0,
				'rows'      => 0,
				'malformed' => 0,
				'slices'    => 0,
				'parse_s'   => 0.0,
				'max_slice' => 0.0,
				'dl_s'      => round( $dl_s, 2 ),
				'gzip'      => $plain['gzip'],
				'started'   => gmdate( 'c' ),
				'variant'   => self::variant(),
				'wanted'    => self::wanted(),
			),
			false
		);

		/* translators: 1: file size, 2: seconds */
		return self::result( 'parsing', sprintf( __( 'Downloaded %1$s in %2$.1f s.', 'zorderz' ), size_format( (int) filesize( $plain['file'] ) ), $dl_s ), 0.0 );
	}

	/** The request arguments: streamed to a file, redirects not followed, the key in a header and nowhere else. */
	public static function request_args( string $key, string $file, int $timeout ): array {
		return array(
			'timeout'     => $timeout,
			'sslverify'   => true,
			'redirection' => 0, // never let the HTTP library carry the key to another address
			'stream'      => true,
			'filename'    => $file,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
				'User-Agent'    => 'Zorderz-Security-Monitor/' . ZSM::VERSION,
			),
		);
	}

	/**
	 * Download the feed to $file. The key goes only in the Authorization header of the request to
	 * the feed address. One redirect is followed by hand, only over https to an allowed host, and
	 * WITHOUT the key. Every message that comes back is scrubbed of the key.
	 *
	 * @param callable|null $http function( string $url, array $args ) returning a WordPress HTTP
	 *                            response array or an error object; wp_remote_get() by default.
	 * @return array{ok:bool,code:int,message:string,backoff:int,kind:string,hops:int}
	 */
	public static function fetch( string $url, string $key, string $file, int $timeout, ?callable $http = null ): array {
		$http = $http ?? 'wp_remote_get';
		$t0   = microtime( true );
		$args = self::request_args( $key, $file, $timeout );
		$resp = $http( $url, $args );
		$hops = 1;

		if ( ! self::is_error( $resp ) ) {
			$code0 = self::response_code( $resp );
			if ( $code0 >= 300 && $code0 < 400 ) {
				$loc = self::response_header( $resp, 'location' );
				if ( '' === $loc || ! self::url_allowed( $loc ) ) {
					$host = (string) parse_url( $loc, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
					return self::fetch_result( false, $code0, ZSM_Policy::redact(
						/* translators: %s: host name */
						sprintf( __( 'The feed address redirected to %s, which is not an allowed https feed host; nothing was requested from it.', 'zorderz' ), '' !== $host ? $host : __( 'an unknown address', 'zorderz' ) ),
						$key
					), 0, ZSM_Policy::KIND_ATTENTION, $hops );
				}
				unset( $args['headers']['Authorization'] );
				$args['timeout'] = max( 5, (int) ceil( $timeout - ( microtime( true ) - $t0 ) ) );
				$resp            = $http( $loc, $args );
				$hops            = 2;
			}
		}

		if ( self::is_error( $resp ) ) {
			/* translators: %s: the HTTP library's error */
			$msg = sprintf( __( 'download failed: %s', 'zorderz' ), (string) $resp->get_error_message() );
			return self::fetch_result( false, 0, ZSM_Policy::redact( $msg, $key ), 0, ZSM_Policy::KIND_TRANSIENT, $hops );
		}
		$code = self::response_code( $resp );
		if ( 200 !== $code ) {
			$f = ZSM_Policy::http_failure( $code, self::response_header( $resp, 'retry-after' ) );
			return self::fetch_result( false, $code, ZSM_Policy::redact( $f['message'], $key ), (int) $f['backoff'], (string) $f['kind'], $hops );
		}
		return self::fetch_result( true, 200, '', 0, '', $hops );
	}

	private static function fetch_result( bool $ok, int $code, string $message, int $backoff, string $kind, int $hops ): array {
		return array( 'ok' => $ok, 'code' => $code, 'message' => $message, 'backoff' => $backoff, 'kind' => $kind, 'hops' => $hops );
	}

	/** A WordPress HTTP error (or anything shaped like one). */
	private static function is_error( $r ): bool {
		return is_object( $r ) && method_exists( $r, 'get_error_message' );
	}

	private static function response_code( $r ): int {
		if ( function_exists( 'wp_remote_retrieve_response_code' ) ) {
			return (int) wp_remote_retrieve_response_code( $r );
		}
		return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0;
	}

	private static function response_header( $r, string $name ): string {
		if ( function_exists( 'wp_remote_retrieve_header' ) ) {
			$v = wp_remote_retrieve_header( $r, $name );
			return is_array( $v ) ? (string) end( $v ) : (string) $v;
		}
		$h = is_array( $r ) ? ( $r['headers'] ?? array() ) : array();
		foreach ( (array) $h as $k => $v ) {
			if ( strtolower( (string) $k ) === strtolower( $name ) ) {
				return is_array( $v ) ? (string) end( $v ) : (string) $v;
			}
		}
		return '';
	}

	// ── Read: one time-boxed slice ─────────────────────────────────────────────

	private static function parse_slice( array $job, float $deadline, float $t0 ): array {
		$file = (string) ( $job['file'] ?? '' );
		if ( '' === $file || ! is_readable( $file ) || ! self::is_our_file( $file ) ) {
			self::abort_unlocked();
			return self::fail( __( 'the temporary feed file disappeared between runs; it will be downloaded again later', 'zorderz' ), 0, ZSM_Policy::KIND_TRANSIENT );
		}
		$fh = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			self::abort_unlocked();
			return self::fail( __( 'cannot open the temporary feed file', 'zorderz' ), 0 );
		}

		global $wpdb;
		$next    = ZSM_DB::vulns_next_table();
		$wanted  = is_array( $job['wanted'] ?? null ) ? $job['wanted'] : array();
		$counts  = array( 'matched' => 0, 'rows' => 0, 'malformed' => 0, 'insert_failed' => 0 );
		$room    = (int) apply_filters( 'zsm_slice_memory_headroom', ZSM_Policy::MEMORY_HEADROOM );
		$stop_at = ZSM_Policy::memory_stop( self::memory_limit_bytes(), memory_get_usage( true ), $room );

		$on_record = function ( string $text ) use ( $wanted, $next, $wpdb, &$counts ) {
			$peek = ZSM_Feed_Stream::peek( $text );
			if ( ! $peek['core'] && ! array_intersect_key( $peek['slugs'], $wanted ) ) {
				return; // not about anything installed here: never decoded
			}
			$rec = json_decode( $text, true );
			if ( ! is_array( $rec ) ) {
				$counts['malformed']++;
				return;
			}
			$rows = self::rows_for_record( $rec, $wanted );
			if ( $rows ) {
				$counts['matched']++;
				foreach ( $rows as $row ) {
					if ( false === $wpdb->insert( $next, $row ) ) {
						$counts['insert_failed']++;
					} else {
						$counts['rows']++;
					}
				}
			}
		};
		$should_stop = function () use ( $stop_at ) {
			return memory_get_usage( true ) >= $stop_at;
		};

		$r = ZSM_Feed_Stream::scan( $fh, (int) $job['offset'], $on_record, $deadline, ZSM_Policy::MAX_RECORD_BYTES, $should_stop );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$slice_s              = microtime( true ) - $t0;
		$job['offset']        = $r['offset'];
		$job['records']      += $r['records'];
		$job['matched']      += $counts['matched'];
		$job['rows']         += $counts['rows'];
		$job['malformed']    += $counts['malformed'];
		$job['insert_failed'] = (int) ( $job['insert_failed'] ?? 0 ) + $counts['insert_failed'];
		$job['slices']       += 1;
		$job['parse_s']       = round( (float) $job['parse_s'] + $slice_s, 2 );
		$job['max_slice']     = round( max( (float) $job['max_slice'], $slice_s ), 2 );
		$job['peak_mb']       = round( max( (float) ( $job['peak_mb'] ?? 0 ), memory_get_peak_usage( true ) / 1048576 ), 1 );

		if ( '' !== $r['error'] ) {
			self::abort_unlocked();
			/* translators: %s: the reader's error */
			return self::fail( sprintf( __( 'the feed could not be read: %s', 'zorderz' ), $r['error'] ), 0, ZSM_Policy::KIND_TRANSIENT );
		}
		if ( ! $r['done'] ) {
			update_option( ZSM::OPT . 'feed_job', $job, false );
			/* translators: %d: number of records */
			return self::progress_result( $job, 'parsing', sprintf( __( 'Read %d records so far.', 'zorderz' ), (int) $job['records'] ) );
		}
		return self::finish( $job );
	}

	private static function finish( array $job ): array {
		$why = ZSM_Policy::verdict( $job, (int) apply_filters( 'zsm_feed_min_records', ZSM_Policy::MIN_RECORDS ) );
		if ( '' !== $why ) {
			self::abort_unlocked();
			return self::fail( $why, 0 );
		}

		global $wpdb;
		$live = ZSM_DB::vulns_table();
		$next = ZSM_DB::vulns_next_table();
		$old  = ZSM_DB::vulns_old_table();
		$wpdb->query( "DROP TABLE IF EXISTS {$old}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers
		$ok = $wpdb->query( "RENAME TABLE {$live} TO {$old}, {$next} TO {$live}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one atomic swap of fixed identifiers
		if ( false === $ok ) {
			$err = (string) $wpdb->last_error;
			self::abort_unlocked();
			ZSM_DB::install(); // make sure a live table exists whatever happened
			/* translators: %s: database error */
			return self::fail( sprintf( __( 'could not swap in the new feed copy: %s', 'zorderz' ), $err ), 0 );
		}
		$wpdb->query( "DROP TABLE IF EXISTS {$old}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier

		$file = (string) $job['file'];
		if ( self::is_our_file( $file ) && file_exists( $file ) ) {
			wp_delete_file( $file );
		}
		delete_option( ZSM::OPT . 'feed_job' );

		$metrics = array(
			'finished_at'  => gmdate( 'c' ),
			'variant'      => (string) $job['variant'],
			'bytes'        => (int) $job['bytes'],
			'gzip'         => ! empty( $job['gzip'] ),
			'download_s'   => (float) $job['dl_s'],
			'slices'       => (int) $job['slices'],
			'parse_s'      => (float) $job['parse_s'],
			'max_slice_s'  => (float) $job['max_slice'],
			'peak_mb'      => (float) ( $job['peak_mb'] ?? 0 ),
			'memory_limit' => (string) ini_get( 'memory_limit' ),
			'records'      => (int) $job['records'],
			'matched'      => (int) $job['matched'],
			'rows'         => (int) $job['rows'],
			'malformed'    => (int) $job['malformed'],
		);
		update_option( ZSM::OPT . 'feed_last_metrics', $metrics, false );
		update_option( ZSM::OPT . 'feed_last_sync', gmdate( 'c' ), false );
		update_option( ZSM::OPT . 'feed_last_error', '', false );
		delete_option( ZSM::OPT . 'feed_last_error_kind' );
		delete_option( ZSM::OPT . 'feed_backoff_until' );
		ZSM::log( 'feed sync ok: ' . wp_json_encode( $metrics ) );

		return self::result(
			'done',
			sprintf(
				/* translators: 1: records read, 2: records about installed software, 3: version ranges cached */
				__( 'Feed synced: %1$d records read, %2$d about software installed here, %3$d version ranges cached.', 'zorderz' ),
				$metrics['records'],
				$metrics['matched'],
				$metrics['rows']
			),
			1.0,
			$metrics['rows']
		);
	}

	// ── Record → rows ──────────────────────────────────────────────────────────

	/**
	 * Every (software, affected range) pair in one feed record that concerns software installed
	 * here. Core is stored under slug "wordpress". Pure.
	 *
	 * @param array<string,bool> $wanted installed feed-coverable slugs (lower case)
	 * @return array<int,array> rows for the vulns table
	 */
	public static function rows_for_record( array $rec, array $wanted ): array {
		$adv = isset( $rec['id'] ) ? (string) $rec['id'] : '';
		if ( '' === $adv || empty( $rec['software'] ) || ! is_array( $rec['software'] ) ) {
			return array();
		}
		$title                           = isset( $rec['title'] ) ? (string) $rec['title'] : '';
		$cve                             = ( isset( $rec['cve'] ) && is_string( $rec['cve'] ) ) ? $rec['cve'] : '';
		list( $score, $rating, $vector ) = self::cvss( $rec );
		$auth                            = self::auth_required( $title, $vector );
		$ref                             = self::first_ref( $rec );
		$info                            = ! empty( $rec['informational'] ) ? 1 : 0;
		$now                             = gmdate( 'Y-m-d H:i:s' );

		$out = array();
		foreach ( $rec['software'] as $sw ) {
			if ( ! is_array( $sw ) ) {
				continue;
			}
			$type = strtolower( (string) ( $sw['type'] ?? '' ) );
			if ( 'core' === $type ) {
				$slug = 'wordpress';
			} elseif ( 'plugin' === $type || 'theme' === $type ) {
				$slug = strtolower( (string) ( $sw['slug'] ?? '' ) );
				if ( '' === $slug || empty( $wanted[ $slug ] ) ) {
					continue;
				}
			} else {
				continue;
			}
			// No affected ranges = nothing to match (a missing list never means "every version").
			$ranges = ( isset( $sw['affected_versions'] ) && is_array( $sw['affected_versions'] ) ) ? $sw['affected_versions'] : array();
			// Every patched version (an advisory can span branches); the matcher picks the one that
			// fixes the installed version. Some advisories list hundreds, so the column is TEXT.
			$patched = '';
			if ( ! empty( $sw['patched_versions'] ) && is_array( $sw['patched_versions'] ) ) {
				$patched = implode( ',', array_filter( array_map( 'strval', $sw['patched_versions'] ), 'strlen' ) );
			}
			foreach ( $ranges as $rng ) { // an object keyed by range label; a list works too
				if ( ! is_array( $rng ) ) {
					continue;
				}
				$out[] = array(
					'advisory_id'     => substr( $adv, 0, 191 ),
					'comp_type'       => $type,
					'slug'            => substr( $slug, 0, 191 ),
					'title'           => $title,
					'cve'             => substr( $cve, 0, 64 ),
					'cvss_score'      => $score,
					'cvss_rating'     => substr( $rating, 0, 20 ),
					'from_version'    => substr( (string) ( $rng['from_version'] ?? '*' ), 0, 64 ),
					'from_inclusive'  => ! empty( $rng['from_inclusive'] ) ? 1 : 0,
					'to_version'      => substr( (string) ( $rng['to_version'] ?? '*' ), 0, 64 ),
					'to_inclusive'    => ! empty( $rng['to_inclusive'] ) ? 1 : 0,
					'patched_version' => $patched,
					'auth_required'   => $auth ? 1 : 0,
					'informational'   => $info,
					'ref_url'         => $ref,
					'updated_at'      => $now,
				);
			}
		}
		return $out;
	}

	/** @return array{0:?float,1:string,2:string} score, rating, vector */
	private static function cvss( array $rec ): array {
		$score  = null;
		$rating = '';
		$vector = '';
		if ( isset( $rec['cvss'] ) && is_array( $rec['cvss'] ) ) {
			if ( isset( $rec['cvss']['score'] ) && is_numeric( $rec['cvss']['score'] ) ) {
				$score = (float) $rec['cvss']['score'];
			}
			if ( ! empty( $rec['cvss']['rating'] ) ) {
				$rating = (string) $rec['cvss']['rating'];
			}
			if ( ! empty( $rec['cvss']['vector'] ) ) {
				$vector = (string) $rec['cvss']['vector'];
			}
		}
		return array( $score, $rating, $vector );
	}

	/**
	 * Does exploiting it need a logged-in account? The CVSS vector says so exactly (PR:N = no
	 * privileges). Without a vector, the feed's title convention decides ("Unauthenticated …" /
	 * "Authenticated (Subscriber+) …"); a plain word search would find "authenticated" inside
	 * "Unauthenticated" and downgrade the most dangerous bugs.
	 */
	public static function auth_required( string $title, string $vector ): bool {
		if ( preg_match( '#(?:^|/)PR:([NLH])(?:/|$)#', $vector, $m ) ) {
			return 'N' !== $m[1];
		}
		if ( preg_match( '/\bunauthenticated\b/i', $title ) ) {
			return false;
		}
		return (bool) preg_match( '/\bauthenticated\b/i', $title );
	}

	private static function first_ref( array $rec ): string {
		if ( ! empty( $rec['references'] ) && is_array( $rec['references'] ) ) {
			$r = reset( $rec['references'] );
			$u = is_string( $r ) ? $r : ( ( is_array( $r ) && isset( $r['url'] ) ) ? (string) $r['url'] : '' );
			return ( '' !== $u && 0 === stripos( $u, 'https://' ) ) ? $u : '';
		}
		return '';
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/** Installed, feed-coverable slugs (third-party plugins and themes, aliases included). */
	public static function wanted(): array {
		$aliases = ZSM_Matcher::alias_map();
		$want    = array();
		foreach ( ZSM_Inventory::collect() as $c ) {
			if ( ! empty( $c['first_party'] ) || 'core' === $c['comp_type'] ) {
				continue;
			}
			$local          = strtolower( (string) $c['slug'] );
			$want[ $local ] = true;
			if ( isset( $aliases[ $local ] ) ) {
				$want[ $aliases[ $local ] ] = true;
			}
		}
		return $want;
	}

	/** A fresh, unguessable file in the monitor's own folder. */
	private static function new_temp_file(): string {
		$dir = self::temp_dir();
		if ( '' === $dir ) {
			return '';
		}
		return trailingslashit( $dir ) . 'feed-' . wp_generate_password( 24, false, false ) . '.json';
	}

	/**
	 * The monitor's temporary folder: under uploads (shared by every web server of a site that
	 * runs on several), else the system temp folder. It refuses direct web access.
	 */
	public static function temp_dir(): string {
		$up         = wp_upload_dir( null, false );
		$candidates = array();
		if ( empty( $up['error'] ) && ! empty( $up['basedir'] ) ) {
			$candidates[] = trailingslashit( $up['basedir'] ) . self::TMP_DIR;
		}
		$candidates[] = trailingslashit( get_temp_dir() ) . self::TMP_DIR;
		foreach ( $candidates as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			if ( is_dir( $dir ) && wp_is_writable( $dir ) ) {
				if ( ! file_exists( $dir . '/index.php' ) ) {
					@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				}
				if ( ! file_exists( $dir . '/.htaccess' ) ) {
					@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				}
				return $dir;
			}
		}
		return '';
	}

	/**
	 * Remove leftover feed files (a request killed between the download and the job record leaves
	 * 100+ MB behind). Only the monitor's own name pattern, only files older than two hours, never
	 * the current job's file.
	 */
	public static function sweep_temp_files( int $older_than = 7200 ): int {
		$dir = self::temp_dir();
		if ( '' === $dir ) {
			return 0;
		}
		$keep = (string) ( self::job()['file'] ?? '' );
		$n    = 0;
		foreach ( (array) glob( $dir . '/feed-*.json' ) as $f ) {
			if ( ! is_string( $f ) || $f === $keep || ! self::is_our_file( $f ) ) {
				continue;
			}
			if ( time() - (int) @filemtime( $f ) > $older_than ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				wp_delete_file( $f );
				if ( ! file_exists( $f ) ) {
					$n++;
				}
			}
		}
		return $n;
	}

	/** Only ever delete files the monitor created: its folder and its name pattern. */
	public static function is_our_file( string $file ): bool {
		return (bool) preg_match( '#/' . self::TMP_DIR . '/feed-[A-Za-z0-9]{24}\.json$#', str_replace( '\\', '/', $file ) );
	}

	/**
	 * cURL usually decodes gzip while streaming; if the file is still gzip, decompress it once
	 * into a plain file so later slices can seek in it.
	 *
	 * @return array{file:string,gzip:bool,error:string}
	 */
	private static function ensure_plain_json( string $file ): array {
		$size = file_exists( $file ) ? (int) filesize( $file ) : 0;
		if ( $size <= 0 ) {
			return array( 'file' => $file, 'gzip' => false, 'error' => __( 'the download was empty', 'zorderz' ) );
		}
		$fh   = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$head = $fh ? (string) fread( $fh, 64 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$gz = false;
		if ( 0 === strncmp( $head, "\x1f\x8b", 2 ) ) {
			$plain = self::new_temp_file();
			$in    = gzopen( $file, 'rb' );
			$out   = '' !== $plain ? fopen( $plain, 'wb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $in || ! $out ) {
				return array( 'file' => $file, 'gzip' => true, 'error' => __( 'could not decompress the download', 'zorderz' ) );
			}
			while ( ! gzeof( $in ) ) {
				$chunk = gzread( $in, 1048576 );
				if ( false === $chunk ) {
					break;
				}
				fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			}
			gzclose( $in );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_delete_file( $file );
			$file = $plain;
			$fh   = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$head = $fh ? (string) fread( $fh, 64 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( $fh ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			$gz = true;
		}

		$first = ltrim( $head );
		if ( '' === $first || ( '{' !== $first[0] && '[' !== $first[0] ) ) {
			if ( self::is_our_file( $file ) && file_exists( $file ) ) {
				wp_delete_file( $file );
			}
			return array( 'file' => $file, 'gzip' => $gz, 'error' => __( 'the download is not JSON (an error page?)', 'zorderz' ) );
		}
		return array( 'file' => $file, 'gzip' => $gz, 'error' => '' );
	}

	private static function memory_limit_bytes(): int {
		$v = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : -1;
		return $v > 0 ? (int) $v : 0;
	}

	private static function progress_result( array $job, string $status, string $msg ): array {
		$bytes = max( 1, (int) ( $job['bytes'] ?? 1 ) );
		return self::result( $status, $msg, min( 0.999, (int) ( $job['offset'] ?? 0 ) / $bytes ), (int) ( $job['rows'] ?? 0 ) );
	}

	/** Record a failure. Only an 'attention' failure (or old age) makes the cached copy stale. */
	private static function fail( string $msg, int $backoff, string $kind = ZSM_Policy::KIND_ATTENTION ): array {
		$msg = ZSM_Policy::redact( $msg, ZSM::feed_key() );
		update_option( ZSM::OPT . 'feed_last_error', $msg, false );
		update_option( ZSM::OPT . 'feed_last_error_kind', ZSM_Policy::kind( $kind ), false );
		if ( $backoff > 0 ) {
			update_option( ZSM::OPT . 'feed_backoff_until', time() + $backoff, false );
		}
		ZSM::log( 'feed sync failed (the last good copy is kept): ' . $msg );
		return self::result( 'failed', $msg );
	}

	private static function result( string $status, string $msg, float $progress = 0.0, int $rows = 0 ): array {
		return array( 'status' => $status, 'message' => $msg, 'progress' => $progress, 'rows' => $rows );
	}
}
