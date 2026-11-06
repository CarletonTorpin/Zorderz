<?php
/**
 * ZPREP_Queue_Counts — the `prep.queue_counts` verb: how many jobs are in Prep's cut queue
 * for the asker (ready to cut, installing within seven days, possible), with when the queue
 * was read. Counts only — no customer, address or amount leaves this verb.
 *
 * The queue is Prep's own (ZPREP_Dashboard::build_queue()), read in the background with the
 * asker as the current user and without the billing self-heal, and kept per person. An
 * answer older than ten minutes asks for a new read; an answer older than an hour says so
 * (`stale`). Refused: signed out, the shared device, a person who may not open Prep, and a
 * person who only holds job assignments (their Prep is their own jobs, not a queue).
 *
 * counts_from() is pure and unit-tested.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Queue_Counts {

	const VERB          = 'prep.queue_counts';
	const OPTION        = 'zprep_queue_counts';
	const CRON_HOOK     = 'zprep_queue_counts_refresh';
	const REFRESH_AFTER = 600;
	const STALE_SECONDS = 3600;
	const MAX_PEOPLE    = 25;
	const WEEK          = 604800;

	/** Injected in tests: now (unix), access (callable uid => bool), mode (callable uid => string), build (callable uid => payload). */
	public static $now    = null;
	public static $access = null;
	public static $mode   = null;
	public static $build  = null;

	public static function init(): void {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh_for' ), 10, 1 );
	}

	public static function descriptor(): array {
		return array(
			'verb'          => self::VERB,
			'provider'      => 'zprep',
			'app'           => defined( 'ZPREP_APP_ID' ) ? ZPREP_APP_ID : 'prep',
			'tier'          => 'staff',
			'needs_app'     => true,
			'callback'      => array( __CLASS__, 'from_capability' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'summary'       => 'How many jobs are in Prep\'s cut queue for you: ready to cut, installing this week, possible. Counts only, with when the queue was read.',
		);
	}

	public static function register( $caps ) {
		$caps                = is_array( $caps ) ? $caps : array();
		$caps[ self::VERB ]  = self::descriptor();
		return $caps;
	}

	public static function from_capability( array $args ): array {
		$uid   = (int) ( $args['user_id'] ?? 0 );
		$kiosk = ! empty( $args['is_kiosk'] ) || 'kiosk' === (string) ( $args['tier'] ?? '' )
			|| ( $uid > 0 && class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) );
		if ( $uid <= 0 ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'signed_out', 'verb' => self::VERB );
		}
		if ( $kiosk ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'kiosk', 'verb' => self::VERB );
		}
		if ( ! self::may_open_prep( $uid ) ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'no_prep', 'message' => 'Prep is not open to your account.', 'verb' => self::VERB );
		}
		if ( 'assignee' === self::mode_of( $uid ) ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'assignee', 'message' => 'You see only the jobs assigned to you in Prep.', 'verb' => self::VERB );
		}
		$kept   = self::kept_for( $uid );
		$age    = $kept ? max( 0, self::now() - (int) $kept['read_at'] ) : null;
		$failed = self::failed_at( $uid );
		$recent = null !== $failed && self::now() - $failed < self::REFRESH_AFTER;
		if ( ( ! $kept || $age > self::REFRESH_AFTER ) && ! $recent ) {
			self::ask_for_count( $uid );
		}
		if ( ! $kept ) {
			return null !== $failed
				? array( 'success' => false, 'error' => 'Prep\'s queue could not be read just now.', 'verb' => self::VERB )
				: array( 'success' => false, 'counting' => true, 'error' => 'Prep\'s queue is being read.', 'verb' => self::VERB );
		}
		return array(
			'success'     => true,
			'verb'        => self::VERB,
			'counts'      => array(
				'to_cut'    => (int) $kept['counts']['to_cut'],
				'this_week' => (int) $kept['counts']['this_week'],
				'possible'  => (int) $kept['counts']['possible'],
			),
			'read_at'     => (int) $kept['read_at'],
			'age_s'       => $age,
			'stale'       => $age > self::STALE_SECONDS,
			'stage'       => (string) ( $kept['stage'] ?? '' ),
			'definitions' => self::definitions(),
		);
	}

	public static function definitions(): array {
		return array(
			'to_cut'    => 'Jobs in Prep\'s Approved-to-Cut queue for you, exactly as Prep lists them, that are not cut yet.',
			'this_week' => 'Of those, installing within the next 7 days, or already past their install day (a known date, or a date read from the job\'s paperwork).',
			'possible'  => 'Cards Prep shows as "Possible — not yet approved"; not counted above.',
		);
	}

	/**
	 * Pure: the three counts of a queue payload, or null when the queue could not be read.
	 * A card's install time is its server-side `install_ts` (absent: no known date).
	 */
	public static function counts_from( array $payload, int $now ): ?array {
		if ( empty( $payload['configured'] ) || ! isset( $payload['jobs'] ) || ! is_array( $payload['jobs'] ) ) {
			return null;
		}
		$c = array( 'to_cut' => 0, 'this_week' => 0, 'possible' => 0 );
		foreach ( $payload['jobs'] as $job ) {
			if ( ! is_array( $job ) || 'remake' === (string) ( $job['kind'] ?? '' ) ) {
				continue;
			}
			if ( ! empty( $job['fb_possible'] ) ) {
				$c['possible']++;
				continue;
			}
			$c['to_cut']++;
			$ts = (int) ( $job['install_ts'] ?? 0 );
			if ( $ts > 0 && $ts < $now + self::WEEK ) {
				$c['this_week']++;
			}
		}
		return $c;
	}

	public static function remember( int $uid, array $payload ): void {
		if ( $uid <= 0 ) {
			return;
		}
		$c = self::counts_from( $payload, self::now() );
		if ( null === $c ) {
			self::note_failure( $uid );
			return;
		}
		$all         = get_option( self::OPTION, array() );
		$all         = is_array( $all ) ? $all : array();
		$all[ $uid ] = array( 'counts' => $c, 'read_at' => self::now(), 'stage' => (string) ( $payload['stage'] ?? '' ) );
		if ( count( $all ) > self::MAX_PEOPLE ) {
			uasort( $all, static function ( $a, $b ) {
				return max( (int) ( $b['read_at'] ?? 0 ), (int) ( $b['failed_at'] ?? 0 ) ) <=> max( (int) ( $a['read_at'] ?? 0 ), (int) ( $a['failed_at'] ?? 0 ) );
			} );
			$all = array_slice( $all, 0, self::MAX_PEOPLE, true );
		}
		update_option( self::OPTION, $all, false );
	}

	/** The background read: the queue as $uid sees it, without the billing self-heal. */
	public static function refresh_for( $uid ): void {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			return;
		}
		$prev = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( $uid );
		}
		try {
			if ( ! self::may_open_prep( $uid ) || 'assignee' === self::mode_of( $uid ) ) {
				return;
			}
			$payload = is_callable( self::$build )
				? call_user_func( self::$build, $uid )
				: ( class_exists( 'ZPREP_Dashboard' ) ? ZPREP_Dashboard::build_queue( $uid, false, true ) : null );
			if ( is_array( $payload ) ) {
				self::remember( $uid, $payload );
			} else {
				self::note_failure( $uid );
			}
		} catch ( \Throwable $e ) {
			self::note_failure( $uid );
			error_log( 'ZPREP prep.queue_counts could not read the queue.' );
		} finally {
			if ( function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( $prev );
			}
		}
	}

	private static function ask_for_count( int $uid ): void {
		if ( function_exists( 'wp_next_scheduled' ) && false !== wp_next_scheduled( self::CRON_HOOK, array( $uid ) ) ) {
			return;
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, array( $uid ) );
		}
	}

	private static function note_failure( int $uid ): void {
		$all              = get_option( self::OPTION, array() );
		$all              = is_array( $all ) ? $all : array();
		$cur              = is_array( $all[ $uid ] ?? null ) ? $all[ $uid ] : array();
		$cur['failed_at'] = self::now();
		$all[ $uid ]      = $cur;
		update_option( self::OPTION, $all, false );
	}

	private static function failed_at( int $uid ): ?int {
		$all = get_option( self::OPTION, array() );
		$k   = is_array( $all ) ? ( $all[ $uid ] ?? null ) : null;
		if ( ! is_array( $k ) || ! isset( $k['failed_at'] ) ) {
			return null;
		}
		return ( isset( $k['read_at'] ) && (int) $k['read_at'] >= (int) $k['failed_at'] ) ? null : (int) $k['failed_at'];
	}

	private static function kept_for( int $uid ): ?array {
		$all = get_option( self::OPTION, array() );
		$k   = is_array( $all ) ? ( $all[ $uid ] ?? null ) : null;
		return ( is_array( $k ) && isset( $k['counts'], $k['read_at'] ) ) ? $k : null;
	}

	private static function may_open_prep( int $uid ): bool {
		if ( is_callable( self::$access ) ) {
			return (bool) call_user_func( self::$access, $uid );
		}
		return class_exists( 'ZPREP_Dashboard' ) && ZPREP_Dashboard::user_can_access( $uid );
	}

	private static function mode_of( int $uid ): string {
		if ( is_callable( self::$mode ) ) {
			return (string) call_user_func( self::$mode, $uid );
		}
		return class_exists( 'ZPREP_Dashboard' ) ? ZPREP_Dashboard::queue_mode( $uid ) : 'team';
	}

	private static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}
}
