<?php
/**
 * ZL Nutshell Write-Back — durable "rep action → Nutshell" propagation.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS (and why it's its own file)
 * ─────────────────────────────────────────────────────────────────────────────
 * This is the concrete, reusable implementation of the platform-wide
 * "real-world propagation" thesis: when a rep does something on the app
 * (here: presses **Contacted** on a lead, with an optional note), that action is
 * mirrored onto the lead's Nutshell record — a posted activity/note plus a
 * status nudge — so the CRM stays truthful without the rep also hand-editing it.
 *
 * It is deliberately a SELF-CONTAINED CLASS so the same pattern can be lifted
 * into the other Zorderz plugins (Surveys, Prep, the camera
 * apps, …). The ONLY thing it needs from its host is a Nutshell client object
 * that can post a note and edit a lead. See "PORTING" at the bottom.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DESIGN DECISIONS (locked with the product owner)
 * ─────────────────────────────────────────────────────────────────────────────
 *   1. On "Contacted": post a note/activity to the lead AND mark the lead
 *      Open/active in Nutshell. (Advancing the lead toward "Create Estimate" is
 *      a SEPARATE, later ask — intentionally NOT done here.)
 *   2. The rep's LOCAL save must always succeed. The Nutshell post is
 *      best-effort: if it fails (API down, throttled, missing lead id, creds
 *      not configured), we DURABLY ENQUEUE the job and a cron drains it later
 *      with backoff. The rep never sees a hard failure for a transient CRM hiccup.
 *   3. Idempotency: each queued job records the local lead id + a short
 *      fingerprint so a retry can't double-post the same activity.
 *
 * This class performs NO permission checks and reads NO $_POST — the caller
 * (the AJAX handler) owns auth + sanitization and hands us clean values. That
 * keeps the class portable and unit-testable.
 *
 * @package Zorderz\Leads
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZL_Nutshell_Writeback {

	/** Cron hook that drains the retry queue. */
	const CRON_HOOK = 'zl_nutshell_writeback_drain';

	/** Queue table (without prefix). */
	const QUEUE_TABLE = 'zl_ns_writeback_queue';

	/** Max delivery attempts before a job is parked as 'failed'. */
	const MAX_ATTEMPTS = 6;

	/**
	 * Schema version for the queue table, so ensure_table() can evolve it.
	 * Stored in the option 'zl_ns_writeback_schema'.
	 */
	const SCHEMA = 1;

	/**
	 * Register the cron drain + its interval. Called once from the plugin's
	 * include bootstrap (see the require side-effect at the bottom of this file).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'drain_queue' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'register_interval' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'zl_five_minutes', self::CRON_HOOK );
		}
	}

	/**
	 * Add the 5-minute interval used by the drain cron (if not already present).
	 *
	 * @param array $schedules
	 * @return array
	 */
	public static function register_interval( $schedules ) {
		if ( ! isset( $schedules['zl_five_minutes'] ) ) {
			$schedules['zl_five_minutes'] = array(
				'interval' => 300,
				'display'  => 'Every 5 Minutes (ZL Nutshell write-back)',
			);
		}
		return $schedules;
	}

	/* ───────────────────────────────────────────────────────────────────────
	 * PUBLIC API — the one method callers use.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * Record a "contacted" action against a lead, propagating to Nutshell.
	 *
	 * Tries the live post first; on ANY failure, enqueues for retry and returns
	 * a structured result. NEVER throws — the caller's local save proceeds
	 * regardless.
	 *
	 * @param array $args {
	 *     @type int    $local_lead_id    Required. Row id in {prefix}zl_leads.
	 *     @type int    $nutshell_lead_id Nutshell lead id (0/empty ⇒ can't post
	 *                                    yet; job is parked for retry once the
	 *                                    lead has been pushed to Nutshell).
	 *     @type string $channel          'phone' | 'email' (how contact happened).
	 *     @type string $note             Optional freeform note (rep's words).
	 *     @type string $rep_name         Display name of the acting rep.
	 *     @type string $occurred_at      MySQL-UTC time of the action.
	 *                                    Defaults to now (UTC).
	 * }
	 * @param object|null $client Nutshell client (must expose new_note + edit_lead
	 *                            + get_lead). If null, we build the ZL one lazily.
	 * @return array { posted:bool, queued:bool, reason:string }
	 */
	public static function record_contact( array $args, $client = null ): array {
		$local_lead_id    = (int) ( $args['local_lead_id'] ?? 0 );
		$nutshell_lead_id = (int) ( $args['nutshell_lead_id'] ?? 0 );
		$channel          = in_array( ( $args['channel'] ?? 'phone' ), array( 'phone', 'email' ), true )
			? $args['channel'] : 'phone';
		$note             = (string) ( $args['note'] ?? '' );
		$rep_name         = (string) ( $args['rep_name'] ?? '' );
		$occurred_at      = (string) ( $args['occurred_at'] ?? gmdate( 'Y-m-d H:i:s' ) );

		if ( $local_lead_id <= 0 ) {
			return array( 'posted' => false, 'queued' => false, 'reason' => 'missing_local_lead_id' );
		}

		$job = array(
			'local_lead_id'    => $local_lead_id,
			'nutshell_lead_id' => $nutshell_lead_id,
			'channel'          => $channel,
			'note'             => $note,
			'rep_name'         => $rep_name,
			'occurred_at'      => $occurred_at,
		);

		// If the lead isn't in Nutshell yet, there's nothing to post against —
		// park it so a later "Send to Nutshell" + drain can complete it.
		if ( $nutshell_lead_id <= 0 ) {
			self::enqueue( $job, 'no_nutshell_lead_id_yet' );
			return array( 'posted' => false, 'queued' => true, 'reason' => 'no_nutshell_lead_id_yet' );
		}

		try {
			$client = $client ?: self::default_client();
			if ( ! $client ) {
				self::enqueue( $job, 'nutshell_not_configured' );
				return array( 'posted' => false, 'queued' => true, 'reason' => 'nutshell_not_configured' );
			}

			self::post_now( $client, $job );
			return array( 'posted' => true, 'queued' => false, 'reason' => 'ok' );

		} catch ( \Throwable $e ) {
			error_log( 'ZL writeback: live post failed (lead #' . $local_lead_id . '), queuing — ' . $e->getMessage() );
			self::enqueue( $job, 'live_post_failed: ' . $e->getMessage() );
			return array( 'posted' => false, 'queued' => true, 'reason' => 'live_post_failed' );
		}
	}

	/* ───────────────────────────────────────────────────────────────────────
	 * CORE — the actual Nutshell mutations. Single source of truth, used by
	 * both the live path and the cron drain.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * Perform the Nutshell side-effects for one job:
	 *   (a) post the contact note/activity onto the lead,
	 *   (b) mark the lead Open/active (status nudge).
	 *
	 * Throws on hard failure so the caller can decide to queue/retry.
	 *
	 * @param object $client
	 * @param array  $job
	 * @return void
	 * @throws Exception
	 */
	private static function post_now( $client, array $job ): void {
		$nutshell_lead_id = (int) $job['nutshell_lead_id'];
		if ( $nutshell_lead_id <= 0 ) {
			throw new Exception( 'post_now called without a nutshell_lead_id' );
		}

		// (a) Note/activity. Matches the existing ZL convention: plain-string
		// body + entity { entityType:'Leads', id }. (See create_nutshell_lead().)
		$body = self::compose_note_body( $job );
		if ( ! method_exists( $client, 'new_note' ) ) {
			throw new Exception( 'Nutshell client has no new_note()' );
		}
		$client->new_note(
			array( 'entityType' => 'Leads', 'id' => $nutshell_lead_id ),
			$body
		);

		// (b) Status nudge → Open/active. Best-effort and SAFE: we read the
		// current lead first, and only edit if it isn't already Open, so we
		// never clobber a Won/Lost lead a human deliberately closed.
		self::mark_lead_open( $client, $nutshell_lead_id );
	}

	/**
	 * Nudge a lead to Open/active, without disturbing a deliberately-closed one.
	 *
	 * Nutshell lead status ids: 0=Open, 1=Won, 2=Lost, 3=Cancelled. We only act
	 * when the lead is currently NOT Open (e.g. it had drifted to Cancelled) and
	 * never downgrade Won/Lost — those represent human decisions.
	 *
	 * @param object $client
	 * @param int    $nutshell_lead_id
	 * @return void
	 */
	private static function mark_lead_open( $client, int $nutshell_lead_id ): void {
		if ( ! method_exists( $client, 'get_lead' ) || ! method_exists( $client, 'edit_lead' ) ) {
			return; // status nudge is optional; note already posted.
		}

		try {
			$lead = $client->get_lead( $nutshell_lead_id );
		} catch ( \Throwable $e ) {
			return; // couldn't read; leave status as-is.
		}
		if ( ! is_array( $lead ) || ! isset( $lead['rev'] ) ) {
			return;
		}

		$status_id = null;
		if ( isset( $lead['status'] ) ) {
			if ( is_array( $lead['status'] ) && isset( $lead['status']['id'] ) ) {
				$status_id = (int) $lead['status']['id'];
			} elseif ( is_numeric( $lead['status'] ) ) {
				$status_id = (int) $lead['status'];
			}
		}

		// Already Open (0) → nothing to do. Won(1)/Lost(2) → never touch.
		if ( 0 === $status_id || 1 === $status_id || 2 === $status_id ) {
			return;
		}

		try {
			$client->edit_lead(
				$nutshell_lead_id,
				$lead['rev'],
				array( 'status' => 0 )
			);
		} catch ( \Throwable $e ) {
			// Non-fatal: the note (the important signal) is already posted.
			error_log( 'ZL writeback: status nudge failed for lead #' . $nutshell_lead_id . ' (non-fatal): ' . $e->getMessage() );
		}
	}

	/**
	 * Build the human-readable activity body for a contact action, e.g.:
	 *
	 *   Contacted by phone on May 31, 2026 at 1:17 PM PT by a salesperson
	 *
	 *   Left a voicemail; will try again Thursday.
	 *
	 * Mirrors Kathy's manual Nutshell note habit (a line of fact + her words).
	 *
	 * @param array $job
	 * @return string
	 */
	private static function compose_note_body( array $job ): string {
		$channel = ( 'email' === $job['channel'] ) ? 'email' : 'phone';
		$verb    = ( 'email' === $channel ) ? 'Contacted via email' : 'Contacted by phone';

		// occurred_at is stored UTC; present it in the site's timezone so the
		// note reads naturally to whoever opens Nutshell.
		$when = self::format_local( $job['occurred_at'] );

		$line = $verb . ' on ' . $when;
		$rep  = trim( (string) $job['rep_name'] );
		if ( '' !== $rep ) {
			$line .= ' by ' . $rep;
		}

		$note = trim( (string) $job['note'] );
		if ( '' !== $note ) {
			return $line . "\n\n" . $note;
		}
		return $line;
	}

	/**
	 * Format a UTC MySQL datetime in the site timezone as "M j, Y \a\t g:i A T".
	 *
	 * @param string $mysql_utc
	 * @return string
	 */
	private static function format_local( string $mysql_utc ): string {
		$ts = strtotime( $mysql_utc . ' UTC' );
		if ( ! $ts ) {
			$ts = time();
		}
		// wp_date() renders in the site's configured timezone (incl. abbrev T).
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( 'M j, Y \a\t g:i A T', $ts );
		}
		return gmdate( 'M j, Y \a\t g:i A', $ts ) . ' UTC';
	}

	/* ───────────────────────────────────────────────────────────────────────
	 * DURABLE QUEUE — survives a failed live post; cron drains it.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * Enqueue a job for later delivery. Idempotent on (local_lead_id, fingerprint):
	 * a duplicate pending job for the same action is not inserted twice.
	 *
	 * @param array  $job
	 * @param string $reason  Why it was queued (diagnostics).
	 * @return void
	 */
	private static function enqueue( array $job, string $reason ): void {
		global $wpdb;
		self::ensure_table();
		$table = $wpdb->prefix . self::QUEUE_TABLE;

		$fingerprint = self::fingerprint( $job );

		// Skip if an identical pending job already exists.
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE fingerprint = %s AND status = 'pending'",
			$fingerprint
		) );
		if ( $exists > 0 ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'local_lead_id'    => (int) $job['local_lead_id'],
				'nutshell_lead_id' => (int) $job['nutshell_lead_id'],
				'fingerprint'      => $fingerprint,
				'payload_json'     => wp_json_encode( $job ),
				'status'           => 'pending',
				'attempts'         => 0,
				'last_error'       => $reason,
				'next_attempt_at'  => gmdate( 'Y-m-d H:i:s' ),
				'created_at'       => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Cron callback: deliver due jobs. Re-resolves the Nutshell lead id from the
	 * local row each time (so a job parked before "Send to Nutshell" completes
	 * once the lead finally has an id), posts, and on success marks 'done'.
	 * On failure, backs off exponentially and parks as 'failed' past MAX_ATTEMPTS.
	 *
	 * @return void
	 */
	public static function drain_queue(): void {
		global $wpdb;
		self::ensure_table();
		$table = $wpdb->prefix . self::QUEUE_TABLE;
		$leads = $wpdb->prefix . 'zl_leads';

		$now = gmdate( 'Y-m-d H:i:s' );
		$due = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE status = 'pending' AND next_attempt_at <= %s
			 ORDER BY id ASC
			 LIMIT 25",
			$now
		), ARRAY_A );

		if ( empty( $due ) ) {
			return;
		}

		$client = self::default_client();

		foreach ( $due as $row ) {
			$id  = (int) $row['id'];
			$job = json_decode( (string) $row['payload_json'], true );
			if ( ! is_array( $job ) ) {
				self::park_failed( $id, 'corrupt_payload' );
				continue;
			}

			// Re-resolve the Nutshell lead id from the local row — it may have
			// been created since this job was queued.
			$ns_id = (int) $row['nutshell_lead_id'];
			if ( $ns_id <= 0 ) {
				$ns_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT nutshell_lead_id FROM {$leads} WHERE id = %d",
					(int) $job['local_lead_id']
				) );
				$job['nutshell_lead_id'] = $ns_id;
			}

			if ( $ns_id <= 0 ) {
				// Still not in Nutshell; defer with backoff (don't burn attempts
				// indefinitely — count it so a never-sent lead eventually parks).
				self::reschedule( $id, (int) $row['attempts'] + 1, 'still_no_nutshell_lead_id' );
				continue;
			}

			if ( ! $client ) {
				self::reschedule( $id, (int) $row['attempts'] + 1, 'nutshell_not_configured' );
				continue;
			}

			try {
				self::post_now( $client, $job );
				$wpdb->update(
					$table,
					array( 'status' => 'done', 'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'last_error' => '' ),
					array( 'id' => $id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			} catch ( \Throwable $e ) {
				self::reschedule( $id, (int) $row['attempts'] + 1, $e->getMessage() );
			}
		}
	}

	/**
	 * Reschedule a job with exponential backoff, or park it 'failed' once it has
	 * exhausted MAX_ATTEMPTS. Backoff: 5m, 15m, 45m, ~2h, ~6h, …
	 *
	 * @param int    $id
	 * @param int    $attempts
	 * @param string $error
	 * @return void
	 */
	private static function reschedule( int $id, int $attempts, string $error ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::QUEUE_TABLE;

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			self::park_failed( $id, $error );
			return;
		}

		$delay_min = 5 * (int) pow( 3, max( 0, $attempts - 1 ) ); // 5,15,45,135,405
		$next      = gmdate( 'Y-m-d H:i:s', time() + ( $delay_min * 60 ) );

		$wpdb->update(
			$table,
			array(
				'attempts'        => $attempts,
				'last_error'      => substr( $error, 0, 500 ),
				'next_attempt_at' => $next,
				'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	private static function park_failed( int $id, string $error ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::QUEUE_TABLE;
		$wpdb->update(
			$table,
			array(
				'status'     => 'failed',
				'last_error' => substr( $error, 0, 500 ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		error_log( "ZL writeback: job #{$id} parked as failed after retries — {$error}" );
	}

	/**
	 * Stable fingerprint for a contact action, so retries can't double-post and
	 * duplicate pending jobs collapse. Bucketed to the minute to tolerate the
	 * tiny clock differences between the live attempt and the queue insert.
	 *
	 * @param array $job
	 * @return string
	 */
	private static function fingerprint( array $job ): string {
		$minute = substr( (string) $job['occurred_at'], 0, 16 ); // YYYY-MM-DD HH:MM
		$raw    = implode( '|', array(
			(int) $job['local_lead_id'],
			$job['channel'],
			$minute,
			md5( (string) $job['note'] ),
		) );
		return substr( hash( 'sha256', $raw ), 0, 40 );
	}

	/**
	 * Create the queue table on demand (first enqueue/drain). Idempotent;
	 * mirrors the plugin's other self-bootstrapping schema code.
	 *
	 * @return void
	 */
	private static function ensure_table(): void {
		static $verified = false;
		if ( $verified ) {
			return;
		}
		if ( (int) get_option( 'zl_ns_writeback_schema', 0 ) >= self::SCHEMA ) {
			$verified = true;
			return;
		}

		global $wpdb;
		$table   = $wpdb->prefix . self::QUEUE_TABLE;
		$charset = $wpdb->get_charset_collate();

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$table}` (
				`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				`local_lead_id` bigint(20) unsigned NOT NULL,
				`nutshell_lead_id` bigint(20) unsigned NOT NULL DEFAULT 0,
				`fingerprint` varchar(40) NOT NULL DEFAULT '',
				`payload_json` longtext,
				`status` varchar(16) NOT NULL DEFAULT 'pending',
				`attempts` int(10) unsigned NOT NULL DEFAULT 0,
				`last_error` text,
				`next_attempt_at` datetime NULL,
				`created_at` datetime NOT NULL,
				`updated_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				KEY `idx_status_next` (`status`, `next_attempt_at`),
				KEY `idx_local_lead` (`local_lead_id`),
				KEY `idx_fingerprint` (`fingerprint`)
			) {$charset};"
		);

		if ( ! $wpdb->last_error ) {
			update_option( 'zl_ns_writeback_schema', self::SCHEMA, true );
			$verified = true;
		}
	}

	/* ───────────────────────────────────────────────────────────────────────
	 * DEFAULT CLIENT — ZL's own. The class works with ANY object exposing
	 * new_note / get_lead / edit_lead, which is the whole portability story.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * Build the ZL Nutshell client using the shared credential cascade
	 * (the same one init_nutshell_only() uses). Returns null if creds are
	 * missing — callers treat that as "queue and retry later".
	 *
	 * @return ZL_Nutshell|null
	 */
	private static function default_client(): ?ZL_Nutshell {
		if ( ! class_exists( 'ZL_Nutshell' ) || ! class_exists( 'ZL_Admin' ) ) {
			return null;
		}
		$email   = ZL_Admin::get_shared_option( 'zl_ns_email', 'ts_surveys_ns_email' );
		$api_key = ZL_Admin::decrypt_shared( 'zl_ns_api_key', 'ts_surveys_ns_api_key' );
		if ( empty( $email ) || empty( $api_key ) ) {
			return null;
		}
		return new ZL_Nutshell( $email, $api_key );
	}
}

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * PORTING THIS PATTERN TO ANOTHER PLUGIN
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. Copy this file into the other plugin's includes/ dir and rename the class
 *    + constants to that plugin's prefix (avoid two plugins sharing a class name
 *    or cron hook).
 * 2. Replace default_client() with that plugin's credential/client construction
 *    (or pass a client into record_contact() explicitly and delete it).
 * 3. The Nutshell client only needs three methods: new_note($entity, $body),
 *    get_lead($id), edit_lead($id, $rev, $fields). ZDZ_Core_Nutshell (theme) and
 *    ZL_Nutshell both already satisfy note posting; add_note vs new_note is the
 *    only name difference to bridge.
 * 4. Call ::init() once from the plugin's include bootstrap, and call
 *    ::record_contact() from wherever that plugin records a rep action.
 *
 * Everything else — the durable queue, backoff, idempotency, status-safety
 * (never clobbering Won/Lost) — is plugin-agnostic and comes along for free.
 */

// Register cron wiring as a side effect of being loaded by zl_load_includes().
ZL_Nutshell_Writeback::init();
