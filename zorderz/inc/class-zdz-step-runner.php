<?php
/**
 * ZDZ_Step_Runner — long work done as a chain of short steps, each in its own request.
 *
 * A web server ends a request after a minute or two, so work that takes many minutes cannot be one request. An app
 * registers a kind of job and a step handler; the runner keeps the job's row, runs one step per request, and starts
 * the next one itself. A job can wait for its person (a plan to approve), be stopped by them, and is picked up again
 * when a step's request died.
 *
 *   ZDZ_Step_Runner::register( 'kind', [ 'step' => fn( array $job ), 'stopped' => fn( $job, $why ),
 *                                        'failed' => fn( $job, $error ), 'abandoned' => fn( $job, $why ),
 *                                        'retrying' => fn( $job, Throwable $e, int $n ) ] )
 *   ZDZ_Step_Runner::start( 'kind', $owner_id, [ 'stage' => 'plan', 'state' => [...], 'deadline' => unix time ] ) -> id
 *
 * The step handler returns 'again' (run another step), 'wait' (until resume() is called for it, or the wait runs out)
 * or 'done'. It may throw ZDZ_Step_Yield ("not enough of this request is left: hand it to a fresh one", never a
 * failure), ZDZ_Step_Fatal ("this would fail the same way again": the job ends now), or anything else (a failure:
 * tried again, MAX_FAILS in a row end the job). It keeps its own place with set() (stage, step, progress, state,
 * deadline) and says it is alive during a long step with touch().
 *
 * HOW A STEP STARTS. No scheduler: a step ends by posting a small request to this site's own admin-ajax address
 * (non-blocking), carrying the job's id and a single-use random token whose hash is in the job's row. The request
 * that arrives uses the token up and runs one step. Nothing else can start a step: not a signed-in page, not a
 * guessed id.
 *
 * ONE WORKER A JOB. A step first takes the job's lease: one UPDATE that only one request can win, good for
 * LEASE_WEB_S (a request the server ended frees the job when it runs out). A request that finds the lease taken
 * stands down.
 *
 * STOP. request_stop() marks the job. The step that is running reads the mark when it ends; a job with no step
 * running (waiting for its person, its lease free, or quiet for QUIET_S) is closed by the Stop request itself. A
 * job that has ended never moves back: a step that comes back late changes nothing.
 *
 * THE WATCHDOG. poke() (called while the job's owner is watching) and sweep() (a single scheduled event that exists
 * only while a job is live) start a quiet job whose lease is free, at most once every KICK_EVERY_S. Each such start
 * with no progress since the last one is counted; MAX_REVIVALS of them, more runs than MAX_RUNS, or BACKSTOP_S past
 * the job's deadline, and the job is abandoned and its app told. A wait that passed its time is closed the same way.
 *
 * WHO. A step runs as the job's owner (wp_set_current_user), so every permission check inside it is asked of that
 * person. Who may start, stop or resume a job is the app's to check before it calls this class.
 *
 * Times in the table are UTC. Nothing here names an app: the handlers do the work.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZDZ_Step_Yield', false ) ) {
	/** Thrown by a step: not enough of this request is left for the work. The job goes back to be started fresh. */
	class ZDZ_Step_Yield extends \Exception {}
}

if ( ! class_exists( 'ZDZ_Step_Fatal', false ) ) {
	/** Thrown by a step: this would fail the same way on another try. The job ends now. */
	class ZDZ_Step_Fatal extends \Exception {}
}

final class ZDZ_Step_Runner {

	const DB_VERSION = '1';
	const DB_OPTION  = 'zdz_step_jobs_db';
	const ACTION     = 'zdz_step_run';
	const SWEEP_HOOK = 'zdz_step_sweep';
	const PRUNE_HOOK = 'zdz_step_prune';

	const WINDOW_S      = 75;     // a step plans its work to end inside this many seconds of its request
	const LEASE_WEB_S   = 150;    // one worker's hold on a job in a web request
	const LEASE_CLI_S   = 330;    // ... and on the command line, where nothing ends the process early
	const QUIET_S       = 90;     // no word for this long (and a free lease) and the job may be started again
	const KICK_EVERY_S  = 60;     // the watchdog starts one job at most this often
	const SWEEP_EVERY_S = 120;
	const MAX_FAILS     = 3;      // failed tries in a row before the job is failed
	const MAX_YIELDS    = 2;      // times one step may hand itself to a fresh request before it runs anyway
	const MAX_REVIVALS  = 8;      // watchdog starts with no progress before the job is abandoned
	const MAX_RUNS      = 600;    // steps one job may ever run
	const BACKSTOP_S    = 1800;   // seconds past its deadline at which a job is abandoned whatever else
	const WAIT_S        = 1800;   // how long a job waits for its person unless the step says otherwise
	const KEEP_DAYS     = 30;

	const LIVE = array( 'queued', 'running', 'waiting' );
	const DONE = array( 'done', 'failed', 'stopped' );

	/** @var array<string,array<string,callable>> kind => handlers */
	private static $kinds = array();

	/** When this request's step started (0: no step is running here). */
	private static $step_started = 0;

	/** Tests: callable(): int — the clock. */
	public static $now = null;

	/** Tests: callable( int $id, string $token ): void — stands in for the request that starts a step. */
	public static $loopback = null;

	/** Tests: callable(): int — when this request began. */
	public static $origin = null;

	/** Tests: callable(): void — stands in for scheduling the sweep. */
	public static $armer = null;

	/** Tests: 'cli' or anything else — stands in for how PHP is running. */
	public static $sapi = null;

	/* ================================================================== set-up */

	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		// The request that starts a step carries no session, so the handler answers with or without one; the single-use
		// token is what lets it in.
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax_run' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'ajax_run' ) );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
		add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * An app's kind of job. 'step' is required; the others are told when a job of this kind ends without its own
	 * step ending it. A kind registered twice keeps its first handlers.
	 */
	public static function register( string $kind, array $handlers ): bool {
		$kind = self::word( $kind, 40 );
		if ( '' === $kind || isset( self::$kinds[ $kind ] ) || ! isset( $handlers['step'] ) || ! is_callable( $handlers['step'] ) ) {
			return false;
		}
		$keep = array();
		foreach ( array( 'step', 'stopped', 'failed', 'abandoned', 'retrying' ) as $k ) {
			if ( isset( $handlers[ $k ] ) && is_callable( $handlers[ $k ] ) ) {
				$keep[ $k ] = $handlers[ $k ];
			}
		}
		self::$kinds[ $kind ] = $keep;
		return true;
	}

	/** Tests: forget every kind. */
	public static function reset(): void {
		self::$kinds        = array();
		self::$step_started = 0;
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zdz_step_jobs';
	}

	/** The jobs table, made once per schema version. False when it could not be made (then no job starts). */
	public static function maybe_install(): bool {
		if ( self::DB_VERSION === (string) get_option( self::DB_OPTION, '' ) ) {
			return true;
		}
		global $wpdb;
		$t   = self::table();
		$cs  = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$sql = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(40) NOT NULL DEFAULT '',
			owner bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL DEFAULT 'queued',
			stage varchar(32) NOT NULL DEFAULT '',
			step int(11) NOT NULL DEFAULT 0,
			progress varchar(64) NOT NULL DEFAULT '',
			runs int(11) NOT NULL DEFAULT 0,
			fails int(11) NOT NULL DEFAULT 0,
			yields int(11) NOT NULL DEFAULT 0,
			yield_mark varchar(120) NOT NULL DEFAULT '',
			revivals int(11) NOT NULL DEFAULT 0,
			revive_mark varchar(120) NOT NULL DEFAULT '',
			stop_asked tinyint(1) NOT NULL DEFAULT 0,
			state longtext NULL,
			error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			deadline_at datetime NULL DEFAULT NULL,
			wait_until datetime NULL DEFAULT NULL,
			lease_until datetime NULL DEFAULT NULL,
			lease_token varchar(32) NOT NULL DEFAULT '',
			kick_token varchar(64) NOT NULL DEFAULT '',
			kicked_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status_updated (status, updated_at),
			KEY owner (owner),
			KEY kind (kind)
		) {$cs};";
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql );
		} else {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- a fixed CREATE TABLE statement; its only variable part is this class's own table name
		}
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		update_option( self::DB_OPTION, self::DB_VERSION, false );
		return true;
	}

	/* ================================================================== the clock */

	public static function now(): int {
		return is_callable( self::$now ) ? (int) call_user_func( self::$now ) : time();
	}

	private static function at( int $ts ): string {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/** A stored time as unix seconds (0 when there is none). */
	public static function ts( $stored ): int {
		$stored = is_string( $stored ) ? trim( $stored ) : '';
		if ( '' === $stored || '0000-00-00 00:00:00' === $stored ) {
			return 0;
		}
		$t = strtotime( $stored . ' UTC' );
		return false === $t ? 0 : (int) $t;
	}

	/**
	 * When this web request began: what the server's own limit is counted from. On the command line, or when the
	 * server gives no sensible time, now.
	 */
	public static function request_origin(): int {
		if ( is_callable( self::$origin ) ) {
			return (int) call_user_func( self::$origin );
		}
		$now = self::now();
		if ( 'cli' === self::sapi() ) {
			return $now;
		}
		$rt = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : ( isset( $_SERVER['REQUEST_TIME'] ) ? (float) $_SERVER['REQUEST_TIME'] : 0.0 );
		if ( $rt <= 0 || $rt > $now + 1 || $rt < $now - 600 ) {
			return $now;
		}
		return (int) floor( $rt );
	}

	/** Seconds a step may plan for: WINDOW_S, or what the host's own limit calls for (zdz_step_window_seconds, 30-300). */
	public static function window(): int {
		$w = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_step_window_seconds', self::WINDOW_S ) : self::WINDOW_S;
		return is_numeric( $w ) ? max( 30, min( 300, (int) $w ) ) : self::WINDOW_S;
	}

	/** Seconds this request's step has been running (0 outside a step). */
	public static function elapsed(): int {
		return self::$step_started > 0 ? max( 0, self::now() - self::$step_started ) : 0;
	}

	/** Seconds left of this request's window; a large number outside a step. */
	public static function window_left(): int {
		return self::$step_started > 0 ? self::window() - self::elapsed() : 3600;
	}

	/** How long one worker holds a job: short in a web request, which the server ends anyway. */
	public static function lease_seconds(): int {
		return 'cli' === self::sapi() ? self::LEASE_CLI_S : self::LEASE_WEB_S;
	}

	private static function sapi(): string {
		return is_string( self::$sapi ) ? self::$sapi : PHP_SAPI;
	}

	/* ================================================================== a job */

	private static function word( $s, int $max ): string {
		return substr( (string) preg_replace( '/[^a-z0-9_:\-]/', '', strtolower( (string) $s ) ), 0, $max );
	}

	/**
	 * Start a job of a registered kind for a person. Returns its id, or 0 (unknown kind, nobody, or no table).
	 *
	 * @param array $args stage (string), state (array), deadline (unix time), kick (false: do not start its first
	 *                    step yet)
	 */
	public static function start( string $kind, int $owner, array $args = array() ): int {
		$kind = self::word( $kind, 40 );
		if ( ! isset( self::$kinds[ $kind ] ) || $owner <= 0 || ! self::maybe_install() ) {
			return 0;
		}
		global $wpdb;
		$now = self::now();
		$ok  = $wpdb->insert( self::table(), array(
			'kind'        => $kind,
			'owner'       => $owner,
			'status'      => 'queued',
			'stage'       => self::word( $args['stage'] ?? '', 32 ),
			'step'        => 0,
			'state'       => (string) wp_json_encode( is_array( $args['state'] ?? null ) ? $args['state'] : array() ),
			'created_at'  => self::at( $now ),
			'updated_at'  => self::at( $now ),
			'deadline_at' => isset( $args['deadline'] ) && (int) $args['deadline'] > 0 ? self::at( (int) $args['deadline'] ) : null,
		) );
		$id = $ok ? (int) $wpdb->insert_id : 0;
		if ( $id <= 0 ) {
			return 0;
		}
		self::arm_sweep();
		if ( ! array_key_exists( 'kick', $args ) || $args['kick'] ) {
			self::kick( $id );
		}
		return $id;
	}

	/** A job's row, its state read back as an array; null when there is none. */
	public static function get( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
		if ( ! is_array( $row ) ) {
			return null;
		}
		$state        = json_decode( (string) ( $row['state'] ?? '' ), true );
		$row['state'] = is_array( $state ) ? $state : array();
		foreach ( array( 'id', 'owner', 'step', 'runs', 'fails', 'yields', 'revivals', 'stop_asked' ) as $k ) {
			$row[ $k ] = (int) ( $row[ $k ] ?? 0 );
		}
		return $row;
	}

	public static function is_live( $job ): bool {
		return is_array( $job ) && in_array( (string) ( $job['status'] ?? '' ), self::LIVE, true );
	}

	/** Is a worker holding the job right now? */
	public static function leased( array $job ): bool {
		return self::ts( $job['lease_until'] ?? '' ) > self::now();
	}

	/**
	 * A step keeps its place: stage, step, progress (a word that changes when work was done inside one step), state
	 * and deadline. Never the status: how a job ends is decided by what its step returns. Always moves "last heard".
	 */
	public static function set( int $id, array $fields ): bool {
		$row = array();
		if ( array_key_exists( 'stage', $fields ) ) {
			$row['stage'] = self::word( $fields['stage'], 32 );
		}
		if ( array_key_exists( 'step', $fields ) ) {
			$row['step'] = max( 0, (int) $fields['step'] );
		}
		if ( array_key_exists( 'progress', $fields ) ) {
			$row['progress'] = self::word( $fields['progress'], 64 );
		}
		if ( array_key_exists( 'state', $fields ) && is_array( $fields['state'] ) ) {
			$row['state'] = (string) wp_json_encode( $fields['state'] );
		}
		if ( array_key_exists( 'deadline', $fields ) ) {
			$row['deadline_at'] = (int) $fields['deadline'] > 0 ? self::at( (int) $fields['deadline'] ) : null;
		}
		$row['updated_at'] = self::at( self::now() );
		global $wpdb;
		return false !== $wpdb->update( self::table(), $row, array( 'id' => $id ) );
	}

	/** A long step says it is alive. */
	public static function touch( int $id ): void {
		self::set( $id, array() );
	}

	/** Inside a long step: has its person pressed Stop (or has the job ended under it)? */
	public static function stop_asked( int $id ): bool {
		$job = self::get( $id );
		return ! $job || ! self::is_live( $job ) || $job['stop_asked'] > 0;
	}

	/**
	 * Inside a step: hand the job to a fresh request when fewer than $need_s seconds of this one are left. A step that
	 * has already done so MAX_YIELDS times at the same place runs anyway (it would never fit).
	 */
	public static function yield_if_short( array $job, int $need_s ): void {
		if ( self::$step_started <= 0 || self::window_left() >= $need_s ) {
			return;
		}
		$mark = self::mark( $job );
		$n    = (string) ( $job['yield_mark'] ?? '' ) === $mark ? (int) ( $job['yields'] ?? 0 ) : 0;
		if ( $n >= self::MAX_YIELDS ) {
			return;
		}
		global $wpdb;
		$wpdb->update( self::table(), array( 'yields' => $n + 1, 'yield_mark' => $mark ), array( 'id' => (int) $job['id'] ) );
		throw new ZDZ_Step_Yield( 'Not enough of this request is left.' );
	}

	/** Where a job is, as one word: it changes whenever the job gets anywhere. */
	private static function mark( array $job ): string {
		return substr( (string) ( $job['stage'] ?? '' ) . ':' . (int) ( $job['step'] ?? 0 ) . ':' . (string) ( $job['progress'] ?? '' ), 0, 120 );
	}

	/* ================================================================== starting a step */

	/**
	 * Start the job's next step in a fresh request: a single-use token (its hash kept in the row) posted to this
	 * site's own admin-ajax address, without waiting for the answer.
	 */
	public static function kick( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		global $wpdb;
		$token = bin2hex( random_bytes( 16 ) );
		$wpdb->update( self::table(), array( 'kick_token' => hash( 'sha256', $token ), 'kicked_at' => self::at( self::now() ) ), array( 'id' => $id ) );
		if ( is_callable( self::$loopback ) ) {
			call_user_func( self::$loopback, $id, $token );
			return true;
		}
		if ( ! function_exists( 'wp_remote_post' ) || ! function_exists( 'admin_url' ) ) {
			return false;
		}
		wp_remote_post( admin_url( 'admin-ajax.php' ), array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),   // this site calling itself: the switch WordPress uses for its own such requests
			'body'      => array( 'action' => self::ACTION, 'job' => $id, 'token' => $token ),
		) );
		return true;
	}

	/**
	 * Use a start token up. True for the one request that held the job's current token: the token is checked against
	 * the stored hash, then one UPDATE that only one request can win clears it.
	 */
	public static function consume_kick( int $id, string $token ): bool {
		if ( $id <= 0 || ! preg_match( '/^[a-f0-9]{32}$/', $token ) || self::DB_VERSION !== (string) get_option( self::DB_OPTION, '' ) ) {
			return false;
		}
		$job  = self::get( $id );
		$hash = hash( 'sha256', $token );
		if ( ! $job || '' === (string) ( $job['kick_token'] ?? '' ) || ! hash_equals( (string) $job['kick_token'], $hash ) ) {
			return false;
		}
		global $wpdb;
		$t = self::table();
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET kick_token = '' WHERE id = %d AND kick_token = %s", $id, $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
		return 1 === (int) $n;
	}

	/** The request that starts a step. */
	public static function ajax_run(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- a request from this server to itself, with no session to tie a nonce to: its single-use token is the check (consume_kick)
		$id    = isset( $_POST['job'] ) && is_scalar( $_POST['job'] ) ? absint( wp_unslash( $_POST['job'] ) ) : 0;
		$token = isset( $_POST['token'] ) && is_scalar( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! self::consume_kick( $id, $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );   // the request that asked did not wait; the step still runs to its end
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a host that forbids it warns; the step plans inside window() either way
		}
		self::run( $id );
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/* ================================================================== one step */

	/**
	 * Run one step of a job, if this request can have it. Returns what happened: missing | ended | waiting |
	 * unknown_kind | busy | stopped | abandoned | failed | done | again.
	 */
	public static function run( int $id ): string {
		$job = self::get( $id );
		if ( ! $job ) {
			return 'missing';
		}
		if ( ! self::is_live( $job ) ) {
			return 'ended';
		}
		if ( 'waiting' === $job['status'] ) {
			return 'waiting';
		}
		$h = self::$kinds[ $job['kind'] ] ?? null;
		if ( ! $h ) {
			return 'unknown_kind';   // its app is not loaded in this request; the watchdog closes it in time
		}
		$lease = self::acquire_lease( $id );
		if ( '' === $lease ) {
			return 'busy';
		}
		self::$step_started = self::request_origin();
		$next = '';
		try {
			// read again now that the job is this worker's: Stop may have closed it in between
			$job = self::get( $id );
			if ( ! $job || ! self::is_live( $job ) ) {
				return 'ended';
			}
			if ( 'waiting' === $job['status'] ) {
				return 'waiting';
			}
			if ( function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( $job['owner'] );
			}
			if ( $job['stop_asked'] ) {
				self::close( $job, 'stopped', 'person' );
				return 'stopped';
			}
			if ( $job['runs'] >= self::MAX_RUNS || self::past_backstop( $job ) ) {
				self::abandon( $job, $job['runs'] >= self::MAX_RUNS ? 'runs' : 'deadline' );
				return 'abandoned';
			}
			self::count_run( $id );
			try {
				$out = call_user_func( $h['step'], $job );
			} catch ( ZDZ_Step_Yield $y ) {
				$out = 'again';
			} catch ( ZDZ_Step_Fatal $f ) {
				self::fail( $id, $f->getMessage() );
				return 'failed';
			} catch ( \Throwable $e ) {
				$fails = $job['fails'] + 1;
				if ( $fails >= self::MAX_FAILS ) {
					self::fail( $id, $e->getMessage() );
					return 'failed';
				}
				global $wpdb;
				$wpdb->update( self::table(), array( 'fails' => $fails, 'updated_at' => self::at( self::now() ) ), array( 'id' => $id ) );
				if ( isset( $h['retrying'] ) ) {
					try {
						call_user_func( $h['retrying'], $job, $e, $fails );
					} catch ( \Throwable $ignored ) {
						unset( $ignored );   // saying so must never cost the job
					}
				}
				$next = 'again';
				self::move( $id, 'queued' );
				return 'again';
			}
			if ( is_array( $out ) ) {
				$wait = isset( $out['wait_s'] ) ? (int) $out['wait_s'] : 0;
				$out  = (string) ( $out['next'] ?? 'again' );
			} else {
				$wait = 0;
				$out  = (string) $out;
			}
			if ( $job['fails'] > 0 ) {
				global $wpdb;
				$wpdb->update( self::table(), array( 'fails' => 0 ), array( 'id' => $id ) );
			}
			if ( 'done' === $out ) {
				self::move( $id, 'done' );
				return 'done';
			}
			// Stop pressed while the step ran: closed here, not one request later
			$after = self::get( $id );
			if ( $after && self::is_live( $after ) && $after['stop_asked'] ) {
				self::close( $after, 'stopped', 'person' );
				return 'stopped';
			}
			if ( 'wait' === $out ) {
				self::move( $id, 'waiting', self::now() + ( $wait > 0 ? min( $wait, 86400 ) : self::WAIT_S ) );
				return 'waiting';
			}
			$next = 'again';
			self::move( $id, 'queued' );
			return 'again';
		} finally {
			self::release_lease( $id, $lease );
			self::$step_started = 0;
			if ( 'again' === $next ) {
				// only for a job that is still live: Stop may have closed it while the step ran
				$now = self::get( $id );
				if ( $now && self::is_live( $now ) && 'waiting' !== $now['status'] ) {
					self::kick( $id );
				}
			}
		}
	}

	private static function count_run( int $id ): void {
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET runs = runs + 1, status = 'running', updated_at = %s WHERE id = %d AND status IN ('queued','running')", self::at( self::now() ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
	}

	/** Take the job for this worker: one UPDATE only one request can win. '' when another worker holds it. */
	private static function acquire_lease( int $id ): string {
		global $wpdb;
		$t     = self::table();
		$now   = self::now();
		$token = bin2hex( random_bytes( 16 ) );
		$n     = $wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET lease_until = %s, lease_token = %s WHERE id = %d AND ( lease_until IS NULL OR lease_until < %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
			self::at( $now + self::lease_seconds() ),
			$token,
			$id,
			self::at( $now )
		) );
		// a query that failed is not a lease: standing down is safe, running twice is not
		return ( false !== $n && null !== $n && (int) $n > 0 ) ? $token : '';
	}

	/** Give the job back, if this worker still holds it. */
	private static function release_lease( int $id, string $token ): void {
		if ( '' === $token ) {
			return;
		}
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET lease_until = NULL, lease_token = '' WHERE id = %d AND lease_token = %s", $id, $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
	}

	/**
	 * Move a live job to another status. One UPDATE that only succeeds while the job is live, so a job that has ended
	 * stays ended and only one caller ends it. True for the caller whose UPDATE did it.
	 */
	private static function move( int $id, string $to, int $wait_until = 0 ): bool {
		global $wpdb;
		$t   = self::table();
		$now = self::at( self::now() );
		if ( 'waiting' === $to ) {
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'waiting', wait_until = %s, updated_at = %s WHERE id = %d AND status IN ('queued','running')", self::at( $wait_until ), $now, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
		} elseif ( 'queued' === $to ) {
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'queued', updated_at = %s WHERE id = %d AND status IN ('queued','running')", $now, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
		} else {
			$to = in_array( $to, self::DONE, true ) ? $to : 'failed';
			$n  = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = %s, updated_at = %s WHERE id = %d AND status IN ('queued','running','waiting')", $to, $now, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
		}
		return false !== $n && (int) $n > 0;
	}

	/** End a live job as stopped and tell its app why ('person', or 'expired' for a wait nobody answered). */
	private static function close( array $job, string $status, string $why ): bool {
		if ( ! self::move( (int) $job['id'], $status ) ) {
			return false;
		}
		self::tell( $job, 'stopped', $why );
		return true;
	}

	/** End a job as failed, keeping the reason, and tell its app. */
	private static function fail( int $id, string $error ): void {
		$job = self::get( $id );
		if ( ! $job || ! self::move( $id, 'failed' ) ) {
			return;
		}
		global $wpdb;
		$wpdb->update( self::table(), array( 'error' => substr( $error, 0, 1000 ) ), array( 'id' => $id ) );
		self::tell( $job, 'failed', $error );
	}

	/** End a job nothing is getting anywhere with ('revivals', 'deadline' or 'runs'), and tell its app. */
	private static function abandon( array $job, string $why ): void {
		if ( ! self::move( (int) $job['id'], 'failed' ) ) {
			return;
		}
		global $wpdb;
		$wpdb->update( self::table(), array( 'error' => 'abandoned: ' . $why ), array( 'id' => (int) $job['id'] ) );
		self::tell( $job, 'abandoned', $why );
	}

	private static function tell( array $job, string $what, string $why ): void {
		$h = self::$kinds[ $job['kind'] ?? '' ][ $what ] ?? null;
		if ( ! $h ) {
			return;
		}
		try {
			call_user_func( $h, $job, $why );
		} catch ( \Throwable $e ) {
			error_log( 'ZDZ_Step_Runner: a "' . $what . '" handler threw (' . get_class( $e ) . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the job has ended either way; the class name says where to look
		}
	}

	/* ================================================================== waiting, and Stop */

	/**
	 * The person answered: a waiting job goes on. One UPDATE decides which press counts (two tabs pressing at once);
	 * $fields are set() for the caller whose press it was. True for that caller.
	 */
	public static function resume( int $id, array $fields = array() ): bool {
		global $wpdb;
		$t = self::table();
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'queued', wait_until = NULL, updated_at = %s WHERE id = %d AND status = 'waiting'", self::at( self::now() ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
		if ( false === $n || 1 !== (int) $n ) {
			return false;
		}
		if ( $fields ) {
			self::set( $id, $fields );
		}
		self::arm_sweep();
		self::kick( $id );
		return true;
	}

	/**
	 * Stop a job. 'closed': it had no step running and was ended here. 'asked': its running step will read the mark
	 * when it ends. 'ended': it had already ended. 'missing': no such job.
	 */
	public static function request_stop( int $id ): string {
		$job = self::get( $id );
		if ( ! $job ) {
			return 'missing';
		}
		if ( ! self::is_live( $job ) ) {
			return 'ended';
		}
		global $wpdb;
		$wpdb->update( self::table(), array( 'stop_asked' => 1 ), array( 'id' => $id ) );
		$quiet = self::now() - self::ts( $job['updated_at'] ) > self::QUIET_S;
		if ( 'waiting' === $job['status'] || ! self::leased( $job ) || $quiet ) {
			return self::close( $job, 'stopped', 'person' ) ? 'closed' : 'ended';
		}
		return 'asked';
	}

	/* ================================================================== the watchdog */

	private static function past_backstop( array $job ): bool {
		$d = self::ts( $job['deadline_at'] ?? '' );
		return $d > 0 && self::now() > $d + self::BACKSTOP_S;
	}

	/**
	 * Its owner is watching: a wait that ran out is closed, a job far past its deadline is abandoned, and a quiet job
	 * whose lease is free is started again (counted). True when a step was started.
	 */
	public static function poke( int $id ): bool {
		$job = self::get( $id );
		return $job ? self::tend( $job ) : false;
	}

	private static function tend( array $job ): bool {
		if ( ! self::is_live( $job ) ) {
			return false;
		}
		$now = self::now();
		if ( 'waiting' === $job['status'] ) {
			$until = self::ts( $job['wait_until'] ?? '' );
			if ( $until > 0 && $now > $until ) {
				self::close( $job, 'stopped', 'expired' );
			}
			return false;
		}
		if ( self::past_backstop( $job ) ) {
			self::abandon( $job, 'deadline' );
			return false;
		}
		if ( $now - self::ts( $job['updated_at'] ) < self::QUIET_S || self::leased( $job ) ) {
			return false;   // working, or a worker still holds it
		}
		if ( $now - self::ts( $job['kicked_at'] ?? '' ) < self::KICK_EVERY_S ) {
			return false;
		}
		// a start with no progress since the last one is counted; progress starts the count again
		$mark = self::mark( $job );
		$n    = (string) ( $job['revive_mark'] ?? '' ) === $mark ? (int) $job['revivals'] : 0;
		if ( $n >= self::MAX_REVIVALS ) {
			self::abandon( $job, 'revivals' );
			return false;
		}
		global $wpdb;
		$wpdb->update( self::table(), array( 'revivals' => $n + 1, 'revive_mark' => $mark ), array( 'id' => (int) $job['id'] ) );
		return self::kick( (int) $job['id'] );
	}

	/** Every live job is tended; the event comes back while one is left. */
	public static function sweep(): void {
		if ( self::DB_VERSION !== (string) get_option( self::DB_OPTION, '' ) ) {
			return;
		}
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( "SELECT id FROM {$t} WHERE status IN ('queued','running','waiting') ORDER BY id ASC LIMIT 50", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- no outside value in it: $t is this class's own table name with the site's prefix
		$left = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$job = self::get( (int) ( $r['id'] ?? 0 ) );
			if ( ! $job ) {
				continue;
			}
			self::tend( $job );
			$after = self::get( (int) $job['id'] );
			if ( $after && self::is_live( $after ) ) {
				$left++;
			}
		}
		if ( $left > 0 ) {
			self::arm_sweep();
		}
	}

	/** One scheduled sweep while a job is live. A site may switch it off (zdz_step_runner_cron): poke() still works. */
	private static function arm_sweep(): void {
		if ( is_callable( self::$armer ) ) {
			call_user_func( self::$armer );
			return;
		}
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		if ( function_exists( 'apply_filters' ) && ! apply_filters( 'zdz_step_runner_cron', true ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_single_event( time() + self::SWEEP_EVERY_S, self::SWEEP_HOOK );
		}
	}

	/** Jobs that ended more than KEEP_DAYS ago are removed. */
	public static function prune(): void {
		if ( self::DB_VERSION !== (string) get_option( self::DB_OPTION, '' ) ) {
			return;
		}
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE status IN ('done','failed','stopped') AND updated_at < %s", self::at( self::now() - self::KEEP_DAYS * 86400 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
	}
}
