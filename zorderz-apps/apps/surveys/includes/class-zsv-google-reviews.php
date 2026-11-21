<?php
/**
 * Zorderz Surveys — the hourly Google review check.
 *
 * WHAT IT DOES
 * ------------
 * Once an administrator has connected the site to the business's own Google Business Profile
 * (Zorderz → Credentials; the kernel's ZDZ_Core_Google_Business holds the sign-in and reads the
 * reviews, read-only), this class, once an hour:
 *
 *   1. reads the location's reviews newest first (at most PAGES pages of 50), stopping at the
 *      first review older than the high-water mark (the newest review time seen, less a day of
 *      overlap);
 *   2. for each review not seen before and no older than the look-back window, matches the
 *      reviewer's PUBLIC GOOGLE NAME (Google never gives an address) against survey customers
 *      whose follow-up was opened in the candidate window before the review and who have no
 *      review recorded:
 *        sure      the full name fits exactly one customer; or first name + last initial
 *                  ("Jordan L.") fits exactly one;
 *        possible  several customers fit; or only the first name, or only the last name, fits;
 *        none      anonymous, or nobody fits;
 *   3. WATCH-ONLY (the default): a sure match is listed as "would record"; NOTHING IS WRITTEN.
 *      AUTOMATIC (an administrator turns it on in the widget): a sure match is recorded at once.
 *
 * A name is a weak key: a nickname, an initial, a partner. So "sure" is narrow on purpose (whole
 * words compared exactly after the same clean-up on both sides, never part of a word, never a
 * sound-alike), a first name alone is never sure however rare, and everything else waits for a
 * person, who presses Confirm on one of the customers the site offered for that review, or "Not
 * them". Turning automatic on does not record what was listed before: those stay for a person.
 *
 * RECORDING a review on a customer sets review_left, the source 'google', the review's date and
 * its first words, in ONE statement that only writes a customer with no review yet, so two
 * writers can never both record. Then the CRM lead:
 *   - a person confirmed: closed as Won with one note ("reviewed"), as any hand-made close;
 *   - the hourly check, automatically: closed as Won only where the SAFETY FLOOR lets a system
 *     close at all (ZSV_Survey_Manager::can_system_close_won: the business opted in AND the call
 *     outcome is genuinely satisfied). Otherwise the review is recorded, the lead gets a note and
 *     stays open for a person: a customer with an open issue is never closed behind anyone's back.
 *
 * WHAT IT KEEPS (options, not autoloaded): the newest MAX_KEPT reviews seen (review id => the
 * reviewer's public name, stars, time, the first 140 characters, status, the customers offered);
 * the high-water time; when the check last ran and what stopped it. No address, no phone.
 *
 * BOUNDED AND QUIET: a run is one time budget (ZSV_Budget, which clamps every outbound call);
 * the high-water mark moves only when paging reached old reviews or the end, so a page that failed
 * is read again next hour; one lock (the kernel's Zdz_Request_Guard::guarded) covers the run,
 * Confirm and "Not them", so a Confirm during a run is told to try again in a minute rather than
 * being undone. With the connector not connected the run stops at once, says so once, and the
 * panel stays hidden. On a staging copy the connector reads nothing.
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_Google_Reviews {

	const CRON_HOOK = 'zsv_google_reviews_check';
	const OPT_SEEN  = 'zsv_greviews';
	const OPT_HW    = 'zsv_greviews_hw';
	const OPT_LAST  = 'zsv_greviews_last';
	const OPT_AUTO  = 'zsv_greviews_auto';
	const PAGES     = 4;     // pages of 50 read in one run
	const MAX_KEPT  = 200;   // reviews kept in the list
	const LISTED    = 15;    // reviews the panel is handed
	const BUDGET_S  = 25;    // seconds one run may take
	const LOCK      = 'zsv_google_reviews';
	const LOCK_STALE = 180;  // seconds after which a lock left by a killed worker may be taken

	/** Name words that are not a surname: "Linden Jr." is Linden. */
	const SUFFIXES = array( 'jr', 'sr', 'ii', 'iii', 'iv', 'v' );

	/** Tests set "now" (unix). */
	public static $now = null;

	/** Tests hand in the manager (and so the CRM client) a recording uses. */
	public static $manager = null;

	private static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}

	private static function manager(): ZSV_Survey_Manager {
		return self::$manager instanceof ZSV_Survey_Manager ? self::$manager : new ZSV_Survey_Manager( null, ZSV_Settings::crm() );
	}

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_automated_check' ) );
		add_action( 'wp_ajax_zsv_greviews_list', array( __CLASS__, 'ajax_list' ) );
		add_action( 'wp_ajax_zsv_greviews_confirm', array( __CLASS__, 'ajax_confirm' ) );
		add_action( 'wp_ajax_zsv_greviews_dismiss', array( __CLASS__, 'ajax_dismiss' ) );
		add_action( 'wp_ajax_zsv_greviews_check_now', array( __CLASS__, 'ajax_check_now' ) );
		add_action( 'wp_ajax_zsv_greviews_mode', array( __CLASS__, 'ajax_mode' ) );
		self::schedule();
	}

	public static function schedule(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( self::CRON_HOOK ) : false;
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/** The kernel can read Google for this site (client, sign-in and location all set). */
	public static function ready(): bool {
		return class_exists( 'ZDZ_Core_Google_Business' ) && ZDZ_Core_Google_Business::is_ready();
	}

	/** Has an administrator turned automatic recording on? Off (watch-only) until then. */
	public static function automatic(): bool {
		return '1' === (string) get_option( self::OPT_AUTO, '0' );
	}

	/* ------------------------------------------------------------------ */
	/*  Names                                                              */
	/* ------------------------------------------------------------------ */

	/** Accents off, the same way on every host. */
	private static function fold( string $s ): string {
		if ( function_exists( 'remove_accents' ) ) {
			return (string) remove_accents( $s );
		}
		if ( class_exists( 'Normalizer' ) ) {
			$d = Normalizer::normalize( $s, Normalizer::FORM_D );
			if ( is_string( $d ) ) {
				return (string) preg_replace( '/\p{Mn}+/u', '', $d );
			}
		}
		if ( function_exists( 'iconv' ) ) {
			$t = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a name iconv cannot read is kept as typed, with no notice.
			if ( false !== $t && '' !== $t ) {
				return $t;
			}
		}
		return $s;
	}

	/** Lower case, accents off, letters and spaces only, single spaces: "Devín  O'Marsh-Vale" → "devin omarshvale". */
	public static function norm( string $s ): string {
		$s = self::fold( trim( $s ) );
		$s = strtolower( $s );
		$s = str_replace( array( "\xE2\x80\x99", "\xE2\x80\x98" ), "'", $s );
		$s = (string) preg_replace( "/['`\-]/", '', $s );
		$s = (string) preg_replace( '/[^a-z ]+/', ' ', $s );
		return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
	}

	/** A customer's first names: "Sam & Riley", "Sam and Riley" → [ sam, riley ]. */
	private static function firsts( string $first ): array {
		$out = array();
		foreach ( (array) preg_split( '/\s*(?:&|\band\b|\/|\+|,)\s*/i', $first ) as $part ) {
			$p = explode( ' ', self::norm( (string) $part ) );
			if ( '' !== $p[0] ) {
				$out[] = $p[0];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Words without a trailing suffix. */
	private static function unsuffixed( array $t ): array {
		while ( count( $t ) > 1 && in_array( (string) end( $t ), self::SUFFIXES, true ) ) {
			array_pop( $t );
		}
		return $t;
	}

	/**
	 * How the reviewer's public name fits one customer: 'full' | 'initial' | 'first' | 'last' | ''.
	 *   full     "Jordan Linden" (or "Jordan A. Linden") against Jordan / Linden
	 *   initial  "Jordan L." or "Jordan L": the first name and the last name's first letter
	 *   first    the first name fits and the rest does not ("Jordan", "Jordan Brook")
	 *   last     the last name fits and the first does not (a partner, a nickname)
	 * Whole words, compared exactly after norm(); a double surname fits with or without its
	 * hyphen. Never part of a word.
	 */
	public static function fit( string $reviewer, string $first, string $last ): string {
		$r = self::norm( $reviewer );
		if ( '' === $r ) {
			return '';
		}
		$rt      = self::unsuffixed( explode( ' ', $r ) );
		$fs      = self::firsts( $first );
		$ln_full = self::norm( $last );
		$lt      = '' === $ln_full ? array() : self::unsuffixed( explode( ' ', $ln_full ) );
		$ln      = implode( '', $lt );   // "Alder Birch" and "Alder-Birch" are the same surname
		if ( ! $fs ) {
			return '';
		}
		$rf       = $rt[0];
		$rest     = array_slice( $rt, 1 );
		$rl       = $rest ? (string) end( $rest ) : '';
		$first_ok = in_array( $rf, $fs, true );
		if ( $first_ok && $rest && '' !== $ln ) {
			// The surname is the reviewer's words after the first name (all of them, or only the last, past a middle name).
			if ( implode( '', $rest ) === $ln || $rl === $ln || ( count( $rest ) > 1 && implode( '', array_slice( $rest, 1 ) ) === $ln ) ) {
				return 'full';
			}
			if ( 1 === strlen( $rl ) && $rl === $ln[0] ) {
				return 'initial';
			}
			return 'first';
		}
		if ( $first_ok ) {
			return 'first';
		}
		if ( '' !== $rl && strlen( $ln ) > 1 && ( $rl === $ln || implode( '', $rest ) === $ln ) ) {
			return 'last';
		}
		return '';
	}

	/**
	 * The verdict for one review against the candidate customers.
	 *
	 * @param array $review    [ reviewer, anonymous ]
	 * @param array $customers rows [ id, first_name, last_name ]
	 * @return array [ verdict: sure|possible|none, ids: int[] ] (at most four customers offered)
	 */
	public static function match( array $review, array $customers ): array {
		if ( ! empty( $review['anonymous'] ) || '' === trim( (string) ( $review['reviewer'] ?? '' ) ) ) {
			return array( 'verdict' => 'none', 'ids' => array() );
		}
		$sure  = array();
		$maybe = array();
		foreach ( $customers as $c ) {
			$f = self::fit( (string) $review['reviewer'], (string) ( $c['first_name'] ?? '' ), (string) ( $c['last_name'] ?? '' ) );
			if ( 'full' === $f || 'initial' === $f ) {
				$sure[] = (int) $c['id'];
			} elseif ( '' !== $f ) {
				$maybe[] = (int) $c['id'];
			}
		}
		if ( 1 === count( $sure ) ) {
			return array( 'verdict' => 'sure', 'ids' => $sure );
		}
		if ( $sure ) {
			return array( 'verdict' => 'possible', 'ids' => array_slice( $sure, 0, 4 ) );
		}
		if ( $maybe ) {
			return array( 'verdict' => 'possible', 'ids' => array_slice( $maybe, 0, 4 ) );
		}
		return array( 'verdict' => 'none', 'ids' => array() );
	}

	/** Customers who could have written a review made at $when: follow-up opened in the candidate window before it, no review yet. */
	private static function candidates( int $when ): array {
		global $wpdb;
		$t    = ZSV_DB::leads_table();
		$from = wp_date( 'Y-m-d H:i:s', $when - ZSV_Settings::greviews_candidate_days() * DAY_IN_SECONDS );
		$to   = wp_date( 'Y-m-d H:i:s', $when + DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed table name; both dates are placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, first_name, last_name FROM {$t}
				 WHERE created_at >= %s AND created_at <= %s AND ( review_left = 0 OR review_left IS NULL )
				 ORDER BY created_at DESC LIMIT 1000",
				$from,
				$to
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/* ------------------------------------------------------------------ */
	/*  The lock                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Run $fn as the ONE writer of the review list. Answers [ true, what $fn returned ], or
	 * [ false, null ] when another run or a Confirm holds the lock, or the kernel's lock is not
	 * there (then nothing is written at all).
	 */
	private static function locked( callable $fn ): array {
		if ( ! class_exists( 'Zdz_Request_Guard' ) || ! method_exists( 'Zdz_Request_Guard', 'guarded' ) ) {
			return array( false, null );
		}
		$res = Zdz_Request_Guard::guarded(
			self::LOCK,
			static function () use ( $fn ) {
				return array( $fn() );
			},
			array( 'stale_seconds' => self::LOCK_STALE )
		);
		return is_array( $res ) ? array( true, $res[0] ) : array( false, null );
	}

	/* ------------------------------------------------------------------ */
	/*  The run                                                            */
	/* ------------------------------------------------------------------ */

	public static function run_automated_check(): void {
		self::run();
	}

	/**
	 * One check. Returns [ ran, why, read, new, sure, recorded, possible, none ].
	 * why: '' | not_ready | busy | more_next_run | what the connector said (staging, no_token,
	 * not_approved, denied, unreachable, …).
	 */
	public static function run(): array {
		$out = array( 'ran' => false, 'why' => '', 'read' => 0, 'new' => 0, 'sure' => 0, 'recorded' => 0, 'possible' => 0, 'none' => 0 );
		if ( ! self::ready() ) {
			$out['why'] = 'not_ready';
			$last       = get_option( self::OPT_LAST, array() );
			if ( ! is_array( $last ) || 'not_ready' !== ( $last['why'] ?? '' ) ) {
				self::remember_last( $out ); // said once, not every hour
			}
			return $out;
		}
		list( $got, $res ) = self::locked(
			static function () use ( $out ) {
				$opened = ZSV_Budget::open( 'google_reviews', (float) self::BUDGET_S );
				try {
					return self::run_locked( $out );
				} finally {
					if ( $opened ) {
						ZSV_Budget::close();
					}
				}
			}
		);
		if ( ! $got ) {
			$out['why'] = 'busy';   // another run, or a Confirm, is writing the list right now
			return $out;
		}
		$out = $res;
		self::remember_last( $out );
		// Counts only: no reviewer and no customer is ever named in the log.
		error_log( sprintf(
			'Zorderz Surveys: Google reviews %s — read %d, new %d, sure %d (%s), recorded %d, possible %d, no match %d%s',
			$out['ran'] ? 'checked' : 'not checked',
			$out['read'],
			$out['new'],
			$out['sure'],
			self::automatic() ? 'automatic' : 'watch-only',
			$out['recorded'],
			$out['possible'],
			$out['none'],
			'' !== $out['why'] ? ' — ' . $out['why'] : ''
		) );
		return $out;
	}

	/** The run itself, under the list's lock and the time budget. */
	private static function run_locked( array $out ): array {
		$seen     = self::seen();
		$added    = array();   // only these are written back; nothing already listed is touched
		$floor    = self::now() - ZSV_Settings::greviews_lookback_days() * DAY_IN_SECONDS;
		$hw       = (int) get_option( self::OPT_HW, 0 );
		$stop     = max( $floor, $hw - DAY_IN_SECONDS );
		$token    = '';
		$newest   = $hw;
		$fresh    = array();
		$complete = false;   // the mark moves only when paging reached the old reviews or the end
		for ( $page = 0; $page < self::PAGES; $page++ ) {
			// A later page is asked for only while a full call still fits in the run's budget.
			if ( $page > 0 && ZSV_Budget::remaining() < ZSV_Budget::full_call() ) {
				break;
			}
			$r = ZDZ_Core_Google_Business::reviews( $token, 50, 10 );
			if ( empty( $r['ok'] ) ) {
				$out['why'] = (string) ( $r['why'] ?? '' );
				break;
			}
			$out['ran'] = true;
			$older      = false;
			foreach ( (array) $r['reviews'] as $rv ) {
				$out['read']++;
				$t      = ZDZ_Core_Google_Business::to_unix( '' !== (string) $rv['update'] ? (string) $rv['update'] : (string) $rv['create'] );
				$c      = ZDZ_Core_Google_Business::to_unix( (string) $rv['create'] );
				$newest = max( $newest, $t );
				if ( $t < $stop ) {
					$older = true;
					continue;
				}
				if ( '' === (string) $rv['id'] || isset( $seen[ $rv['id'] ] ) || $c < $floor ) {
					continue;
				}
				$fresh[] = $rv + array( 'create_unix' => $c );
			}
			$token = (string) ( $r['next'] ?? '' );
			if ( $older || '' === $token ) {
				$complete = true;
				break;
			}
		}

		$auto = self::automatic();
		foreach ( array_reverse( $fresh ) as $rv ) {   // oldest first, so two reviews by one name go in order
			$out['new']++;
			$m   = self::match( array( 'reviewer' => $rv['reviewer'], 'anonymous' => $rv['anonymous'] ), self::candidates( (int) $rv['create_unix'] ) );
			$rec = array(
				'id'       => (string) $rv['id'],
				'reviewer' => self::snippet( (string) $rv['reviewer'], 80 ),
				'stars'    => (int) $rv['stars'],
				'create'   => (int) $rv['create_unix'],
				'snippet'  => self::snippet( (string) $rv['comment'], 140 ),
				'status'   => 'sure' === $m['verdict'] ? 'would' : $m['verdict'],
				'ids'      => $m['ids'],
				'at'       => self::now(),
				'lead'     => 0,
				'by'       => '',
			);
			if ( 'sure' === $m['verdict'] ) {
				$out['sure']++;
				// Recorded only while the run's budget lets the CRM's part start; otherwise it stays
				// listed as "would record", for a person.
				if ( $auto && ZSV_Budget::can_start( 3 ) && self::record( (int) $m['ids'][0], $rec, (string) $rv['comment'], 'the hourly Google check', false ) ) {
					$rec['status'] = 'recorded';
					$rec['lead']   = (int) $m['ids'][0];
					$rec['by']     = 'auto';
					$out['recorded']++;
				}
			} elseif ( 'possible' === $m['verdict'] ) {
				$out['possible']++;
			} else {
				$out['none']++;
			}
			$seen[ $rec['id'] ]  = $rec;
			$added[ $rec['id'] ] = $rec;
		}
		if ( $added ) {
			$now_list = self::seen();   // re-read under the lock, add only what this run found
			foreach ( $added as $id => $rec ) {
				if ( ! isset( $now_list[ $id ] ) ) {
					$now_list[ $id ] = $rec;
				}
			}
			self::save_seen( $now_list );
		}
		if ( $out['ran'] && $complete && $newest > $hw ) {
			update_option( self::OPT_HW, $newest, false );
		} elseif ( $out['ran'] && ! $complete && '' === $out['why'] ) {
			$out['why'] = 'more_next_run';   // stopped at the page cap or the budget: the rest is read next hour, the mark stays
		}
		return $out;
	}

	private static function snippet( string $s, int $n ): string {
		$s = trim( (string) preg_replace( '/\s+/', ' ', $s ) );
		return function_exists( 'mb_substr' ) ? (string) mb_substr( $s, 0, $n ) : substr( $s, 0, $n );
	}

	private static function remember_last( array $out ): void {
		update_option( self::OPT_LAST, $out + array( 'at' => self::now() ), false );
	}

	/** The list as stored now (the per-request option cache is dropped first, so a read under the lock is fresh). */
	public static function seen(): array {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPT_SEEN, 'options' );
		}
		$s = get_option( self::OPT_SEEN, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function save_seen( array $seen ): void {
		uasort( $seen, static function ( $a, $b ) { return (int) ( $b['create'] ?? 0 ) <=> (int) ( $a['create'] ?? 0 ); } );
		update_option( self::OPT_SEEN, array_slice( $seen, 0, self::MAX_KEPT, true ), false );
	}

	/**
	 * Record a Google review on one customer. False when the customer already has a review
	 * (nothing is changed then), the row is gone, or the row could not be written; in none of
	 * those cases is the CRM touched.
	 *
	 * @param int    $lead_id   The survey row.
	 * @param array  $rec       The listed review ( create, stars ).
	 * @param string $comment   The review's words (the first 300 characters are kept).
	 * @param string $found_by  For the CRM note and the log: who or what found it.
	 * @param bool   $by_person A person confirmed it (true), or the hourly check did (false).
	 */
	public static function record( int $lead_id, array $rec, string $comment, string $found_by, bool $by_person ): bool {
		global $wpdb;
		$t   = ZSV_DB::leads_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, crm_lead_id, review_left, operator_status FROM {$t} WHERE id = %d", $lead_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed table name; the id is a placeholder.
		if ( ! is_array( $row ) || ! empty( $row['review_left'] ) ) {
			return false;
		}
		$when = (int) ( $rec['create'] ?? 0 );
		// One statement that only writes a customer with no review yet: exactly one row, or nothing
		// else happens (no CRM close, no "recorded"). Two writers can never both record on one customer.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed table name; every value is a placeholder.
		$done = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET review_left = 1, review_source = 'google', review_date = %s, review_snippet = %s
				 WHERE id = %d AND ( review_left = 0 OR review_left IS NULL )",
				$when > 0 ? wp_date( 'Y-m-d H:i:s', $when ) : current_time( 'mysql' ),
				self::snippet( $comment, 300 ),
				$lead_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $done ) {
			return false;
		}
		$stars = (int) ( $rec['stars'] ?? 0 );
		error_log( sprintf( 'Zorderz Surveys: Google review recorded for lead #%d (%d stars; %s)', $lead_id, $stars, $by_person ? 'confirmed by a person' : 'the hourly check' ) );

		$crm_id = (int) ( $row['crm_lead_id'] ?? 0 );
		if ( $crm_id < 1 ) {
			return true;
		}
		$mgr     = self::manager();
		$status  = strtolower( trim( (string) ( $row['operator_status'] ?? '' ) ) );
		$context = array(
			'review_source' => 'Google',
			'stars'         => $stars > 0 ? $stars : '',
			'found_by'      => $found_by,
		);
		if ( $by_person || $mgr->can_system_close_won( $status ) ) {
			$mgr->mark_lead_won_with_reason( $lead_id, $crm_id, 'reviewed', $context );
			return true;
		}
		// SAFETY FLOOR: the system never closes a lead as Won where a person has not looked. The
		// review is recorded; the lead keeps its state, with a note, for a person.
		$note = '[Surveys] Review recorded: the lead is left open for a person' . "\n" . 'Recorded: ' . ZSV_Settings::stamp() . "\n";
		foreach ( $context as $k => $v ) {
			if ( '' !== $v ) {
				$note .= ucwords( str_replace( '_', ' ', (string) $k ) ) . ': ' . $v . "\n";
			}
		}
		$state = $mgr->post_note_durably( 'reviewed', $crm_id, $lead_id, $note );
		if ( 'posted' !== $state ) {
			ZSV_DB::disposition( 'review_note_owed', array( 'lead_id' => $lead_id, 'state' => $state ) );
		}
		ZSV_DB::disposition(
			'survey_escalated',
			array(
				'lead_id'     => $lead_id,
				'crm_lead_id' => $crm_id,
				'reason'      => 'review_recorded_needs_human',
				'status'      => '' !== $status ? $status : 'not_contacted',
			)
		);
		return true;
	}

	/* ------------------------------------------------------------------ */
	/*  The widget                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * May this person use the panel? '' when yes, else the refusal. The panel names other people's
	 * customers, so it asks what the survey list asks (ZSV_Dashboard::can_see: an administrator, or
	 * someone who may see other people's customers; never the shared device). Turning automatic
	 * recording on or off, and checking now, are an administrator's.
	 */
	public static function refusal( int $uid, bool $admin = false ): string {
		if ( $admin && ! user_can( $uid, 'manage_options' ) ) {
			return 'Unauthorized';
		}
		if ( ! class_exists( 'ZSV_Dashboard' ) || ! ZSV_Dashboard::can_see( $uid ) ) {
			return $admin ? 'Unauthorized' : 'Survey data access restricted.';
		}
		return '';
	}

	private static function verify( bool $admin = false ): void {
		check_ajax_referer( ZSV_NONCE, 'nonce' );
		$no = self::refusal( get_current_user_id(), $admin );
		if ( '' !== $no ) {
			wp_send_json_error( $no );
		}
	}

	/**
	 * The panel's data: recent reviews (newest first), each offered customer's name and the day
	 * their follow-up was opened (so two customers of one name can be told apart), the mode and
	 * the last run.
	 */
	public static function list_payload( int $uid = 0 ): array {
		global $wpdb;
		$t     = ZSV_DB::leads_table();
		$items = array_slice( array_values( self::seen() ), 0, self::LISTED );
		$ids   = array();
		foreach ( $items as $it ) {
			foreach ( (array) ( $it['ids'] ?? array() ) as $id ) {
				$ids[ (int) $id ] = true;
			}
			if ( ! empty( $it['lead'] ) ) {
				$ids[ (int) $it['lead'] ] = true;
			}
		}
		$names = array();
		if ( $ids ) {
			$in   = implode( ',', array_map( 'intval', array_keys( $ids ) ) );
			$rows = $wpdb->get_results( "SELECT id, first_name, last_name, review_left, created_at FROM {$t} WHERE id IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $t is $wpdb->prefix plus a fixed table name; $in is a list of integers made by intval().
			foreach ( (array) $rows as $r ) {
				$day                     = substr( (string) ( $r['created_at'] ?? '' ), 0, 10 );
				$names[ (int) $r['id'] ] = array(
					'name'     => trim( $r['first_name'] . ' ' . $r['last_name'] ),
					'reviewed' => ! empty( $r['review_left'] ),
					'added'    => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ? $day : '',
				);
			}
		}
		$out = array();
		foreach ( $items as $it ) {
			$cands = array();
			foreach ( (array) ( $it['ids'] ?? array() ) as $id ) {
				if ( isset( $names[ (int) $id ] ) ) {
					$cands[] = array( 'id' => (int) $id ) + $names[ (int) $id ];
				}
			}
			$lead  = (int) ( $it['lead'] ?? 0 );
			$out[] = array(
				'id'       => (string) ( $it['id'] ?? '' ),
				'reviewer' => (string) ( $it['reviewer'] ?? '' ),
				'stars'    => (int) ( $it['stars'] ?? 0 ),
				'date'     => (int) ( $it['create'] ?? 0 ) > 0 ? ZSV_Settings::short_day( (int) $it['create'] ) : '',
				'snippet'  => (string) ( $it['snippet'] ?? '' ),
				'status'   => (string) ( $it['status'] ?? '' ),
				'lead'     => $lead > 0 && isset( $names[ $lead ] ) ? array( 'id' => $lead ) + $names[ $lead ] : null,
				'cands'    => $cands,
			);
		}
		$last = get_option( self::OPT_LAST, array() );
		return array(
			'ready'     => self::ready(),
			'automatic' => self::automatic(),
			'can_admin' => $uid > 0 && user_can( $uid, 'manage_options' ),
			'lookback'  => ZSV_Settings::greviews_lookback_days(),
			'last'      => is_array( $last ) && $last ? array(
				'at'  => ! empty( $last['at'] ) ? ZSV_Settings::short_stamp( (int) $last['at'] ) : '',
				'ran' => ! empty( $last['ran'] ),
				'why' => (string) ( $last['why'] ?? '' ),
			) : null,
			'items'     => $out,
		);
	}

	public static function ajax_list(): void {
		self::verify();
		wp_send_json_success( self::list_payload( get_current_user_id() ) );
	}

	/**
	 * A person says which customer wrote a listed review: recorded at once, whatever the mode.
	 * Only a customer the site offered for that review is accepted, and only once.
	 *
	 * @param string $who The person's display name, for the CRM note.
	 * @return array [ ok, message ]
	 */
	public static function confirm( string $review_id, int $lead_id, string $who ): array {
		list( $got, $res ) = self::locked(
			static function () use ( $review_id, $lead_id, $who ) {
				$seen = self::seen();   // read under the lock
				if ( '' === $review_id || ! isset( $seen[ $review_id ] ) || $lead_id < 1 ) {
					return array( false, __( 'That review is no longer in the list. Reload.', 'zorderz' ) );
				}
				if ( ! in_array( $lead_id, array_map( 'intval', (array) ( $seen[ $review_id ]['ids'] ?? array() ) ), true ) ) {
					return array( false, __( 'That customer was not offered for this review.', 'zorderz' ) );
				}
				if ( in_array( (string) ( $seen[ $review_id ]['status'] ?? '' ), array( 'recorded', 'confirmed' ), true ) ) {
					return array( false, __( 'This review is already recorded.', 'zorderz' ) );
				}
				$by = 'confirmed by ' . $who;
				if ( ! self::record( $lead_id, $seen[ $review_id ], (string) ( $seen[ $review_id ]['snippet'] ?? '' ), $by, true ) ) {
					return array( false, __( 'That customer already has a review recorded.', 'zorderz' ) );
				}
				$seen[ $review_id ]['status'] = 'confirmed';
				$seen[ $review_id ]['lead']   = $lead_id;
				$seen[ $review_id ]['by']     = $by;
				self::save_seen( $seen );
				return array( true, '' );
			}
		);
		return $got ? $res : array( false, __( 'The Google check is running right now. Try again in a minute.', 'zorderz' ) );
	}

	/** A person says a listed review is none of the customers offered: nothing is written to any customer. */
	public static function dismiss( string $review_id ): array {
		list( $got, $res ) = self::locked(
			static function () use ( $review_id ) {
				$seen = self::seen();   // read under the lock
				if ( '' === $review_id || ! isset( $seen[ $review_id ] ) ) {
					return array( false, __( 'That review is no longer in the list. Reload.', 'zorderz' ) );
				}
				if ( ! in_array( (string) ( $seen[ $review_id ]['status'] ?? '' ), array( 'recorded', 'confirmed' ), true ) ) {
					$seen[ $review_id ]['status'] = 'dismissed';
					self::save_seen( $seen );
				}
				return array( true, '' );
			}
		);
		return $got ? $res : array( false, __( 'The Google check is running right now. Try again in a minute.', 'zorderz' ) );
	}

	/** An administrator turns automatic recording on or off. Logged with the person's number, never a name. */
	public static function set_mode( bool $on, int $uid ): void {
		update_option( self::OPT_AUTO, $on ? '1' : '0', false );
		error_log( 'Zorderz Surveys: Google reviews automatic recording turned ' . ( $on ? 'ON' : 'OFF' ) . ' by user #' . $uid );
	}

	/** The answer goes out only after the lock is let go (the work above returns; nothing here holds it). */
	private static function reply( array $res ): void {
		if ( $res[0] ) {
			wp_send_json_success( self::list_payload( get_current_user_id() ) );
		}
		wp_send_json_error( $res[1] );
	}

	public static function ajax_confirm(): void {
		self::verify();
		$rid  = isset( $_POST['review_id'] ) ? sanitize_text_field( wp_unslash( $_POST['review_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::verify().
		$lead = isset( $_POST['lead_id'] ) ? absint( $_POST['lead_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::verify(); the id is made a whole number.
		$u    = wp_get_current_user();
		$who  = ( $u && ! empty( $u->display_name ) ) ? (string) $u->display_name : 'user #' . get_current_user_id();
		self::reply( self::confirm( $rid, $lead, $who ) );
	}

	public static function ajax_dismiss(): void {
		self::verify();
		$rid = isset( $_POST['review_id'] ) ? sanitize_text_field( wp_unslash( $_POST['review_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::verify().
		self::reply( self::dismiss( $rid ) );
	}

	public static function ajax_check_now(): void {
		self::verify( true );
		$r = self::run();
		wp_send_json_success( array( 'run' => $r ) + self::list_payload( get_current_user_id() ) );
	}

	public static function ajax_mode(): void {
		self::verify( true );
		$on = isset( $_POST['automatic'] ) && '1' === (string) wp_unslash( $_POST['automatic'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in self::verify(); compared with one fixed value.
		self::set_mode( $on, get_current_user_id() );
		wp_send_json_success( self::list_payload( get_current_user_id() ) );
	}
}
