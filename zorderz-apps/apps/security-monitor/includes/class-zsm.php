<?php
/**
 * ZSM — the Security Monitor's settings, its feed key, its schedule and the daily run.
 *
 * Admin-only and server-authoritative: every surface requires manage_options, and the shared
 * device is refused even if someone gave it that capability. Off until an administrator arms
 * it on the console; until then nothing runs in the background.
 *
 * The feed key is read ONLY through the platform's credential layer,
 * ZDZ_Core_Settings::get_credential( 'wordfence_feed_key' ) (row zdz_core_wordfence_feed_key,
 * sealed at rest). When the platform's Credentials screen manages that row, the console points
 * there and never writes the row itself; otherwise the console offers a write-only key field
 * that stores the key sealed with ZDZ_Core_Settings::seal_secret(). The key is never printed,
 * logged or returned, and it travels only in the request header to the feed provider.
 *
 * @package Zorderz\Security_Monitor
 */

defined( 'ABSPATH' ) || exit;

class ZSM {

	const VERSION       = '1.0.0';
	const APP_ID        = 'security-monitor';
	const OPT           = 'zsm_';
	const CAP           = 'manage_options';
	const PAGE          = 'zsm';
	const CRON_HOOK     = 'zsm_daily_scan';
	const CONTINUE_HOOK = 'zsm_feed_continue';
	const RETRY_HOOK    = 'zsm_feed_retry';
	const AS_GROUP      = 'zsm';
	const ALERT_TYPE    = 'zsm_security_digest';
	const SCHEDULE_FLAG = 'zsm_schedule_ok';

	/** The credential name and its canonical row (ZDZ_Core_Settings::OPTION_PREFIX + name). */
	const KEY_NAME   = 'wordfence_feed_key';
	const KEY_OPTION = 'zdz_core_wordfence_feed_key';

	/** Set while the one retry runs, so a retry that fails again does not queue another. */
	private static $in_retry = false;

	// ── Settings ───────────────────────────────────────────────────────────────

	/** Is the monitor armed (daily feed sync, scan and digest)? Off by default. */
	public static function enabled(): bool {
		return 'on' === get_option( self::OPT . 'enabled', 'off' );
	}

	/** May this user see and drive the monitor? Administrators only; never the shared device. */
	public static function can_manage( int $uid = 0 ): bool {
		$uid = $uid > 0 ? $uid : (int) get_current_user_id();
		if ( $uid <= 0 || ! user_can( $uid, self::CAP ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Everyone the digest goes to: the administrators chosen on the console who still exist and
	 * may manage the site. With none chosen, the first administrator (lowest user id) gets it, so
	 * an armed monitor never reports to nobody.
	 *
	 * @return int[]
	 */
	public static function recipient_ids(): array {
		$out    = array();
		$chosen = get_option( self::OPT . 'recipient_ids', array() );
		foreach ( is_array( $chosen ) ? $chosen : array() as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $out, true ) && self::can_manage( $id ) ) {
				$out[] = $id;
			}
		}
		if ( ! $out ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 5, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ) );
			foreach ( (array) $admins as $id ) {
				if ( self::can_manage( (int) $id ) ) {
					$out[] = (int) $id;
					break;
				}
			}
		}
		return $out;
	}

	/** Does the version baseline follow deploys by itself ('follow', the default) or only by hand ('manual')? */
	public static function baseline_follows(): bool {
		return 'manual' !== get_option( self::OPT . 'baseline_mode', 'follow' );
	}

	/** Is public registration open? Drives the one-level downgrade of bugs that need a login. */
	public static function registration_open(): bool {
		return ZSM_Policy::registration_open( (bool) get_option( 'users_can_register', 0 ), (string) get_option( self::OPT . 'registration_open', '' ) );
	}

	/**
	 * The host's request wall in seconds: the console setting, else the `zsm_request_wall` filter,
	 * else PHP's max_execution_time for a web request. 0 = unknown (a minute is assumed).
	 */
	public static function request_wall(): int {
		$v = (int) get_option( self::OPT . 'request_wall', 0 );
		$v = (int) apply_filters( 'zsm_request_wall', $v );
		if ( $v <= 0 && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$v = (int) ini_get( 'max_execution_time' );
		}
		return max( 0, $v );
	}

	/** @return array{wall:int,slice:float,download_timeout:int} */
	public static function budget(): array {
		$b          = ZSM_Policy::budget( self::request_wall() );
		$b['slice'] = (float) apply_filters( 'zsm_slice_seconds', $b['slice'] );
		return $b;
	}

	// ── The feed key ───────────────────────────────────────────────────────────

	/** The usable key, through the platform's credential layer (which unseals it). '' when unset. */
	public static function feed_key(): string {
		if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_credential' ) ) {
			return trim( (string) ZDZ_Core_Settings::get_credential( self::KEY_NAME ) );
		}
		return '';
	}

	public static function has_feed_key(): bool {
		return '' !== self::feed_key();
	}

	/**
	 * Does the platform's Credentials screen manage the key row? Then that screen is its only
	 * writer and the console shows a link instead of a key field.
	 */
	public static function key_managed_elsewhere(): bool {
		if ( ! class_exists( 'ZDZ_Core_Credentials' ) || ! is_callable( array( 'ZDZ_Core_Credentials', 'services' ) ) ) {
			return false;
		}
		$services = (array) ZDZ_Core_Credentials::services();
		if ( isset( $services['wordfence'] ) ) {
			return true;
		}
		foreach ( $services as $svc ) {
			$canon = is_array( $svc ) ? (array) ( $svc['canonical'] ?? array() ) : array();
			if ( in_array( self::KEY_OPTION, $canon, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Where the Credentials screen lives. */
	public static function credentials_url(): string {
		if ( class_exists( 'ZDZ_Core_Credentials' ) && is_callable( array( 'ZDZ_Core_Credentials', 'page_url' ) ) ) {
			return (string) ZDZ_Core_Credentials::page_url();
		}
		return admin_url( 'admin.php?page=zdz-core-credentials' );
	}

	/** Light shape check: one token, no spaces, not absurdly long. */
	public static function key_shape_ok( string $plain ): bool {
		$plain = trim( $plain );
		return '' !== $plain && ! preg_match( '/\s/', $plain ) && strlen( $plain ) <= 512;
	}

	/**
	 * Store the key sealed, through the platform's sealing helper, after proving the credential
	 * layer reads it back. Refused while the Credentials screen manages the row.
	 *
	 * @return true|WP_Error
	 */
	public static function store_feed_key( string $plain ) {
		if ( self::key_managed_elsewhere() ) {
			return new WP_Error( 'zsm_key_managed', __( 'The feed key is managed on Zorderz → Credentials; change it there.', 'zorderz' ) );
		}
		$plain = trim( $plain );
		if ( ! self::key_shape_ok( $plain ) ) {
			return new WP_Error( 'zsm_key_shape', __( 'That does not look like a feed key (empty, spaces, or far too long).', 'zorderz' ) );
		}
		if ( ! class_exists( 'ZDZ_Core_Settings' ) || ! method_exists( 'ZDZ_Core_Settings', 'seal_secret' ) || ! class_exists( 'ZDZ_Core_Crypto' ) ) {
			return new WP_Error( 'zsm_no_vault', __( 'The platform\'s credential store is not available, so the key was not saved.', 'zorderz' ) );
		}
		$sealed = ZDZ_Core_Settings::seal_secret( $plain );
		if ( '' === $sealed || $sealed === $plain || ZDZ_Core_Crypto::FIXED !== ZDZ_Core_Crypto::decode( $sealed )[0] || ZDZ_Core_Crypto::plain( $sealed ) !== $plain ) {
			return new WP_Error( 'zsm_key_verify', __( 'The key could not be sealed in a form the credential store reads back (is OpenSSL available?); it was not saved.', 'zorderz' ) );
		}
		delete_option( self::KEY_OPTION );
		add_option( self::KEY_OPTION, $sealed, '', false ); // never autoloaded
		if ( self::feed_key() !== $plain ) {
			delete_option( self::KEY_OPTION );
			return new WP_Error( 'zsm_key_verify', __( 'The credential store did not read the key back; it was not saved.', 'zorderz' ) );
		}
		return true;
	}

	/** Remove the key. While the Credentials screen manages the row, this changes nothing. */
	public static function delete_feed_key(): bool {
		if ( self::key_managed_elsewhere() ) {
			return false;
		}
		delete_option( self::KEY_OPTION );
		return true;
	}

	/** A line for the salt watch: a sealed key cannot be read once the auth salt changes. */
	public static function salt_watch_lines( $lines, $changed ) {
		$lines = is_array( $lines ) ? $lines : array();
		if ( in_array( 'auth', (array) $changed, true ) && '' !== (string) get_option( self::KEY_OPTION, '' ) ) {
			$lines[] = __( 'Security Monitor: the vulnerability feed key can no longer be read; enter it again. The local checks keep running meanwhile.', 'zorderz' );
		}
		return $lines;
	}

	/** A structured log line, only with WP_DEBUG. Never carries the key. */
	public static function log( string $msg ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Zorderz Security Monitor ' . self::VERSION . '] ' . ZSM_Policy::redact( $msg, self::feed_key() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	// ── Lifecycle ──────────────────────────────────────────────────────────────

	/** Activation: create the tables. */
	public static function activate(): void {
		ZSM_DB::install();
		update_option( self::OPT . 'schema_ver', self::VERSION, false );
	}

	/** Deactivation: unschedule everything and drop an in-progress download (findings and settings stay). */
	public static function deactivate(): void {
		delete_transient( self::SCHEDULE_FLAG );
		foreach ( array( self::CRON_HOOK, self::CONTINUE_HOOK, self::RETRY_HOOK ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook );
			}
		}
		ZSM_Feed::abort( 'deactivated' );
		ZSM_Feed::sweep_temp_files( 0 );
		ZSM_Codescan::abort();
	}

	public static function boot(): void {
		// Self-heal the tables when the module's version changes (an upload over an older copy).
		if ( (string) get_option( self::OPT . 'schema_ver', '' ) !== self::VERSION ) {
			ZSM_DB::install();
			update_option( self::OPT . 'schema_ver', self::VERSION, false );
		}

		// A plugin or theme install or update is when a deploy happens, so the baseline follows it
		// right away (inventory only: no feed, no code walk, no outside call).
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 99, 0 );
		add_filter( 'zdz_salt_watch_breaks', array( __CLASS__, 'salt_watch_lines' ), 10, 2 );

		ZSM_Admin::init();  // the console (admin-only), always, so an administrator can arm the monitor
		ZSM_Report::init(); // the dashboard widget and the alert type

		if ( self::enabled() ) {
			// The schedule is set on init@20, once a job library's data store has started (it starts
			// on init@1). Asked earlier, it schedules nothing and logs a notice on every request.
			add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 20, 0 );
			add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_scan' ) );
			add_action( self::CONTINUE_HOOK, array( __CLASS__, 'continue_scheduled_sync' ) );
			add_action( self::RETRY_HOOK, array( __CLASS__, 'run_feed_retry' ) );
		}
	}

	/** Is a job library (Action Scheduler) loaded? */
	public static function action_scheduler_loaded(): bool {
		return function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' );
	}

	/** Is it loaded AND has its data store started, so it is safe to call? */
	public static function action_scheduler_ready(): bool {
		if ( ! self::action_scheduler_loaded() ) {
			return false;
		}
		if ( class_exists( 'ActionScheduler' ) && method_exists( 'ActionScheduler', 'is_initialized' ) ) {
			return (bool) ActionScheduler::is_initialized();
		}
		return did_action( 'init' ) > 0; // older versions have no is_initialized()
	}

	/**
	 * Make sure the daily job exists: through Action Scheduler when present (only once its data
	 * store has started), else WP-Cron. Checked at most every 12 hours per site, and remembered
	 * only once the job really exists.
	 */
	public static function ensure_schedule( bool $force = false ): void {
		$how = ZSM_Policy::schedule_action( (bool) get_transient( self::SCHEDULE_FLAG ), self::action_scheduler_loaded(), self::action_scheduler_ready(), $force );
		if ( 'cached' === $how || 'wait' === $how ) {
			return;
		}
		$first = time() + ZSM_Policy::FIRST_RUN_DELAY;
		if ( 'action_scheduler' === $how ) {
			$ok = (bool) as_has_scheduled_action( self::CRON_HOOK )
				|| (bool) as_schedule_recurring_action( $first, DAY_IN_SECONDS, self::CRON_HOOK, array(), self::AS_GROUP );
		} else {
			$ok = false !== wp_next_scheduled( self::CRON_HOOK ) || true === wp_schedule_event( $first, 'daily', self::CRON_HOOK );
		}
		if ( $ok ) {
			set_transient( self::SCHEDULE_FLAG, 1, ZSM_Policy::SCHEDULE_RECHECK );
		}
	}

	/**
	 * After a rate limit or a transient failure, queue ONE retry for when the pause ends, so a
	 * rate limit costs an hour, not the day. A retry that fails waits for the next daily run.
	 */
	private static function schedule_retry(): void {
		if ( self::$in_retry || ! self::enabled() ) {
			return;
		}
		$at = ZSM_Policy::retry_at( ZSM_Feed::backoff_until(), ZSM_Feed::last_attempt() );
		if ( self::action_scheduler_ready() ) {
			if ( empty( as_get_scheduled_actions( array( 'hook' => self::RETRY_HOOK, 'status' => 'pending', 'per_page' => 1 ), 'ids' ) ) ) {
				as_schedule_single_action( $at, self::RETRY_HOOK, array(), self::AS_GROUP );
			}
		} elseif ( ! self::action_scheduler_loaded() && ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_single_event( $at, self::RETRY_HOOK );
		}
	}

	public static function run_feed_retry(): void {
		self::log( 'feed retry: begin' );
		self::$in_retry = true;
		try {
			self::advance( true );
		} finally {
			self::$in_retry = false;
		}
	}

	/**
	 * Queue the next step a minute from now (one at a time). With Action Scheduler only PENDING
	 * actions count: the running one would otherwise see itself and queue nothing, and the
	 * chain would stop after one follow-up.
	 */
	public static function schedule_continue(): void {
		if ( self::action_scheduler_ready() && function_exists( 'as_get_scheduled_actions' ) && function_exists( 'as_schedule_single_action' ) ) {
			$pending = as_get_scheduled_actions( array( 'hook' => self::CONTINUE_HOOK, 'status' => 'pending', 'per_page' => 1 ), 'ids' );
			if ( empty( $pending ) ) {
				as_schedule_single_action( time() + 60, self::CONTINUE_HOOK, array(), self::AS_GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::CONTINUE_HOOK );
		}
	}

	/**
	 * The daily job: advance the feed one step. If the sync needs more steps, the rest run as
	 * follow-up jobs and the scan and digest happen when it finishes; otherwise (done, no key,
	 * waiting, failed) scan and digest now, on whatever copy of the feed is good. Runs in the
	 * background, never in a page load.
	 */
	public static function run_scheduled_scan(): void {
		self::log( 'scheduled scan: begin' );
		self::advance( true );
	}

	public static function continue_scheduled_sync(): void {
		if ( empty( ZSM_Feed::job() ) && ! ZSM_Codescan::in_progress() ) {
			return;
		}
		self::advance( false );
	}

	/**
	 * One background run: the feed first, then (once a day) your own code, each in its time box.
	 * The scan and digest run as soon as the feed is settled, before the code walk, so a walk
	 * never holds them back and a walk that dies cannot cost the day's digest.
	 */
	private static function advance( bool $daily ): void {
		$t0           = microtime( true );
		$slice        = (float) self::budget()['slice'];
		$feed_settled = false;
		if ( $daily || ! empty( ZSM_Feed::job() ) ) {
			$step = ZSM_Feed::step( $slice );
			self::log( 'feed step: ' . $step['status'] . ' - ' . $step['message'] );
			if ( in_array( $step['status'], array( 'downloading', 'parsing', 'busy' ), true ) ) {
				self::schedule_continue();
				return;
			}
			if ( 'failed' === $step['status'] && in_array( ZSM_Feed::last_error_kind(), array( ZSM_Policy::KIND_RATE, ZSM_Policy::KIND_TRANSIENT ), true ) ) {
				self::schedule_retry();
			}
			$feed_settled = true;
			self::scan_and_digest();
		}
		if ( ZSM_Codescan::in_progress() || ZSM_Codescan::due() ) {
			$cs = ZSM_Codescan::step( (float) apply_filters( 'zsm_codescan_slice_seconds', max( 2.0, $slice - ( microtime( true ) - $t0 ) ) ) );
			self::log( 'code check: ' . $cs['status'] . ' - ' . $cs['message'] );
			if ( 'scanning' === $cs['status'] ) {
				self::schedule_continue();
				return;
			}
			// The walk finished. In the run that already sent the digest, only refresh the findings
			// (one digest per run); a follow-up run that finishes the walk sends one.
			if ( $feed_settled ) {
				self::run_scan();
			} else {
				self::scan_and_digest();
			}
		}
	}

	private static function scan_and_digest(): void {
		$result = self::run_scan();
		ZSM_Report::maybe_send_digest( $result );
		self::log( 'scan + digest - ' . wp_json_encode( $result['counts'] ?? array() ) );
	}

	/** A plugin or theme was installed or updated: move the baseline forward (inventory only). */
	public static function after_upgrade(): void {
		if ( ! self::baseline_follows() ) {
			return;
		}
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}
		ZSM_Currency::follow_deploys( ZSM_Inventory::collect() );
	}

	/**
	 * Scan now (also the console's "Scan now" and WP-CLI). Reads the inventory and the cached
	 * feed; makes no outside call.
	 *
	 * @return array{counts:array,findings:array,scanned:int,ran_at:string}
	 */
	public static function run_scan(): array {
		$inventory = ZSM_Inventory::collect();
		if ( self::baseline_follows() ) {
			ZSM_Currency::follow_deploys( $inventory );
		}
		$all = array_merge( ZSM_Currency::evaluate( $inventory ), ZSM_Matcher::match( $inventory ) );
		ZSM_Findings::reconcile( $all );

		return array(
			'counts'   => ZSM_Findings::counts(),
			'findings' => $all,
			'scanned'  => count( $inventory ),
			'ran_at'   => gmdate( 'c' ),
		);
	}
}
